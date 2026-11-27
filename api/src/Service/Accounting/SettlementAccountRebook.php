<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Přeúčtování saldokontního účtu dokladu: závazek přijaté faktury nebo účtenky (321 →
 * 321.200), pohledávka vydané faktury (311 → 311.200). Jediné místo, které rozhoduje,
 * jestli to jde, a které nový účet uloží do dokladu, aby ho další zaúčtování nevrátilo.
 *
 * Saldokontní účet zápisu se staví z dokladu ({@see PostingService::documentSettlementAccount},
 * pořadí priorit v {@see PartnerSettlementAccount}). Nový účet se proto ukládá tam, kde ho
 * zaúčtování čte s nejvyšší prioritou po uhrazeném dokladu: do protiúčtu řádků
 * ({@see ItemPostingAccounts}). Když nový účet dává doklad už bez protiúčtu řádků (karta
 * partnera, předkontace), protiúčet řádků se vyprázdní, ať se doklad dál řídí nastavením.
 *
 * Přeúčtovat jde jen neuhrazený doklad. Úhrada leží na původním účtu a uhrazený doklad si
 * ho při každém dalším zaúčtování ponechá ({@see PartnerSettlementAccount::keptForPaidDocument}),
 * takže by přeúčtování rozdělilo saldo na dva účty. Doklad bez saldokonta (DDKP, penále)
 * a doklad zúčtovávající zálohu protiúčet řádků nést nemůže
 * ({@see PostingService::documentCounterAccountBlocker}). Cílový účet se ověřuje stejně
 * jako účet na kartě partnera ({@see PartnerSettlementAccount::validatePayable},
 * {@see PartnerSettlementAccount::validateReceivable}).
 *
 * Volá ho hromadné přeúčtování ({@see JournalRebookService}: rozhodnutí) i přeúčtování
 * jednoho dokladu ({@see DocumentRepostService}: uložení do řádků), takže stránka
 * Přeúčtovat doklady, Hromadné akce i dialog Přeúčtovat jdou stejnou cestou.
 */
final class SettlementAccountRebook
{
    public const PAID_DOCUMENT = 'paid_document';
    public const INVALID_ACCOUNT = 'invalid_settlement_account';
    public const NOT_APPLICABLE = 'settlement_not_applicable';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
    ) {}

    /**
     * Je `$code` saldokontním účtem dokladu? Buď účtem, na kterém závazek či pohledávka leží
     * v živém zápisu ({@see PartnerSettlementAccount::postedSettlementAccount}), nebo účtem,
     * na který by doklad šel při novém zaúčtování z dokladu (zápis může zaostávat za kartou
     * partnera, která se změnila po zaúčtování).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    public function isSettlementAccount(int $supplierId, string $sourceType, int $docId, string $code, bool $currentRead = false): bool
    {
        $code = $this->posting->redirectedAccountCode($supplierId, $code, null, $currentRead);
        $posted = $this->partners()->postedSettlementAccount($supplierId, $sourceType, $docId);
        if ($posted !== null && $this->posting->redirectedAccountCode($supplierId, $posted, null, $currentRead) === $code) {
            return true;
        }
        return $this->posting->documentSettlementAccount($supplierId, $sourceType, $docId, $currentRead) === $code;
    }

    /**
     * Byl `$code` saldokontním účtem zápisu s řádky `$lines` (přeúčtování, které zápis už
     * přepsalo, se ptá na původní řádky)?
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param list<array<string,mixed>> $lines
     */
    public function wasSettlementAccount(int $supplierId, string $sourceType, array $lines, string $code): bool
    {
        $codes = array_values(array_filter(array_map(
            fn (array $line): string => $this->posting->redirectedAccountCode($supplierId, (string) ($line['account_code'] ?? ''), null, true),
            $lines,
        ), static fn (string $c): bool => $c !== ''));
        return $this->partners()->settlementAccountOf($supplierId, $sourceType, $codes) === $code;
    }

    /**
     * Proč saldokontní účet dokladu nejde přeúčtovat na `$to` (null = jde).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @return array{0:string, 1:string}|null kód důvodu a zpráva
     */
    public function blocker(int $supplierId, string $sourceType, int $docId, string $to): ?array
    {
        if ($this->partners()->hasPayment($supplierId, $sourceType, $docId)) {
            return [self::PAID_DOCUMENT, 'Doklad je uhrazený, úhrada leží na původním účtu. Uhrazený doklad zůstává na účtu, který úhrada vyrovnala.'];
        }
        $counter = $this->posting->documentCounterAccountBlocker($supplierId, $sourceType, $docId);
        if ($counter !== null) {
            return [self::NOT_APPLICABLE, $counter->getMessage()];
        }
        if ($this->itemIds($supplierId, $sourceType, $docId, false) === []) {
            return [self::NOT_APPLICABLE, 'Doklad nemá položky, do kterých by se nový účet uložil.'];
        }
        try {
            $sourceType === 'purchase_invoice'
                ? $this->partners()->validatePayable($supplierId, $to, 'to_account')
                : $this->partners()->validateReceivable($supplierId, $to, 'to_account');
        } catch (PostingException $e) {
            return [self::INVALID_ACCOUNT, $e->getMessage()];
        }
        return null;
    }

    /**
     * Uloží nový saldokontní účet do protiúčtu řádků dokladu (v transakci volajícího, po
     * zápisu do deníku). Nejdřív zkusí řádky bez protiúčtu, pak s `$to`; použije první
     * podobu, ve které zaúčtování z dokladu dá `$to`. Jinak řádky vrátí, jak byly,
     * a vrátí 0 (deník nese přeúčtování jako dosud).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @return int počet řádků se zapsaným protiúčtem (0 = nic)
     */
    public function persist(int $supplierId, string $sourceType, int $docId, string $to): int
    {
        $ids = $this->itemIds($supplierId, $sourceType, $docId, true);
        if ($ids === []) {
            return 0;
        }
        $target = $this->posting->redirectedAccountCode($supplierId, $to, null, true);
        $table = $sourceType === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $read = $this->db->pdo()->prepare("SELECT id, counter_account_code FROM {$table} WHERE id IN ({$in})");
        $read->execute(array_keys($ids));
        $previous = $read->fetchAll(PDO::FETCH_KEY_PAIR);
        $write = $this->db->pdo()->prepare("UPDATE {$table} SET counter_account_code = ? WHERE id IN ({$in})");
        foreach ([null, $to] as $counter) {
            $write->execute([$counter, ...array_keys($ids)]);
            if ($this->posting->documentSettlementAccount($supplierId, $sourceType, $docId, true) === $target) {
                return count($ids);
            }
        }
        $restore = $this->db->pdo()->prepare("UPDATE {$table} SET counter_account_code = ? WHERE id = ?");
        foreach ($previous as $id => $code) {
            $restore->execute([$code, (int) $id]);
        }
        return 0;
    }

    /**
     * Řádky dokladu (id => true), zamčené při zápisu.
     *
     * @return array<int,true>
     */
    private function itemIds(int $supplierId, string $sourceType, int $docId, bool $lock): array
    {
        $sql = $sourceType === 'invoice'
            ? 'SELECT it.id FROM invoice_items it JOIN invoices d ON d.id = it.invoice_id WHERE d.id = ? AND d.supplier_id = ?'
            : 'SELECT it.id FROM purchase_invoice_items it JOIN purchase_invoices d ON d.id = it.purchase_invoice_id WHERE d.id = ? AND d.supplier_id = ?';
        $stmt = $this->db->pdo()->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$docId, $supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function partners(): PartnerSettlementAccount
    {
        return new PartnerSettlementAccount($this->db, new PostingRuleRepository($this->db));
    }
}
