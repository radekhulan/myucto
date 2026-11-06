<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Dorovná zúčtování zálohy v zápisu KONEČNÉ faktury, když se úhrada zálohy zaúčtuje,
 * zruší nebo přepáruje až PO zaúčtování konečné faktury.
 *
 * Zápis konečné faktury nese pár zúčtování zálohy (přijatá strana 321 MD / 314 D,
 * vydaná 324 MD / 311 D) ve výši SKUTEČNĚ zaúčtované úhrady zálohy
 * ({@see PostingService::appendAdvanceSettlementPurchase()} a Sale). Ta se ale počítá jen
 * v okamžiku zaúčtování faktury. Typický průběh z praxe — konečná faktura zaúčtovaná
 * dřív, než se platba zálohy (kartou) spárovala, nebo platba zálohy odpárovaná a znovu
 * spárovaná — pak nechal pohledávku ze zálohy na 314 a závazek na 321 rozjeté. Účetní
 * to viděla jako „nezaúčtované" a platbu přesunula na konečnou fakturu.
 *
 * Mění se VÝHRADNĚ pár zúčtování; ostatní řádky zápisu zůstávají, jak jsou (i ruční
 * přeúčtování nákladového účtu). Očekávaný pár se bere z téhož builderu jako při
 * zaúčtování, takže pravidlo (strop, DDKP, cizí měna) žije na jednom místě. V zápisu se
 * vymění jen řádky páru ({@see PostingService::replaceEntryLines()}) s optimistickým
 * zámkem row_version — dimenze a rozpady ostatních řádků i posted_at zůstávají. Uzavřené
 * nebo zamčené období, souběžná změna zápisu a úhrada z jiného roku než konečná faktura
 * se nepřepisují — jen se zaloguje `accounting.advance_settlement_stale`.
 *
 * Volá se best-effort z cest, které mění zaúčtovanou úhradu zálohy:
 * bankovní párování a jeho zrušení, pokladní úhrada a její storno.
 * Chyba rušící celou transakci se propaguje, protože zrušila i samotnou úhradu.
 */
final class AdvanceSettlementSync
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Zálohy, jejichž úhradu nese bankovní pohyb: zálohové přijaté faktury
     * (payment_matches) a proformy (invoice_payments).
     *
     * @return array{purchase:list<int>, sale:list<int>}
     */
    public function advancesOfBankTransaction(int $supplierId, int $txId): array
    {
        $purchase = $this->db->pdo()->prepare(
            "SELECT DISTINCT pm.purchase_invoice_id
               FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
              WHERE pm.supplier_id = ? AND pm.bank_transaction_id = ? AND pi.document_kind = 'advance'"
        );
        $purchase->execute([$supplierId, $txId]);
        $sale = $this->db->pdo()->prepare(
            "SELECT DISTINCT ip.invoice_id
               FROM invoice_payments ip
               JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ip.supplier_id
              WHERE ip.supplier_id = ? AND ip.bank_transaction_id = ? AND i.invoice_type = 'proforma'"
        );
        $sale->execute([$supplierId, $txId]);

        return [
            'purchase' => array_map('intval', $purchase->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'sale'     => array_map('intval', $sale->fetchAll(PDO::FETCH_COLUMN) ?: []),
        ];
    }

    /**
     * Best-effort dorovnání konečných faktur daných záloh. Běžná chyba v zápisu
     * jiného dokladu nesmí shodit úhradu; zrušení celé transakce musí volající vědět.
     *
     * @param array{purchase?:list<int>, sale?:list<int>} $advances
     */
    public function syncAdvances(int $supplierId, array $advances, ?int $userId = null): void
    {
        foreach ($advances['purchase'] ?? [] as $advanceId) {
            $this->safely($supplierId, 'purchase', (int) $advanceId, $userId);
        }
        foreach ($advances['sale'] ?? [] as $proformaId) {
            $this->safely($supplierId, 'sale', (int) $proformaId, $userId);
        }
    }

    /**
     * Pokladní doklad úhrady zálohy se zaúčtoval nebo stornoval.
     *
     * @param array<string,mixed> $cashDocument
     */
    public function afterCashDocument(int $supplierId, array $cashDocument, ?int $userId = null): void
    {
        $purpose = (string) ($cashDocument['purpose'] ?? '');
        if ($purpose === 'purchase_payment' && ($cashDocument['purchase_invoice_id'] ?? null) !== null) {
            $id = (int) $cashDocument['purchase_invoice_id'];
            if ($this->documentKind('purchase_invoices', 'document_kind', $supplierId, $id) === 'advance') {
                $this->syncAdvances($supplierId, ['purchase' => [$id]], $userId);
            }
        } elseif ($purpose === 'invoice_payment' && ($cashDocument['invoice_id'] ?? null) !== null) {
            $id = (int) $cashDocument['invoice_id'];
            if ($this->documentKind('invoices', 'invoice_type', $supplierId, $id) === 'proforma') {
                $this->syncAdvances($supplierId, ['sale' => [$id]], $userId);
            }
        }
    }

    /**
     * Dorovná zápis konečné faktury zálohy. Vrací, co se stalo (`synced`, `in_sync`,
     * `none`, `skipped` s důvodem) — CLI oprava dat z toho staví report.
     *
     * @param 'purchase'|'sale' $side
     * @return array{action:string, reason?:string, final_id?:int, entry_id?:int, before?:float, after?:float}
     */
    public function syncAdvance(int $supplierId, string $side, int $advanceId, ?int $userId = null): array
    {
        $finalId = $this->finalOf($supplierId, $side, $advanceId);
        if ($finalId === null) {
            return ['action' => 'none', 'reason' => 'no_final_invoice'];
        }
        return ['final_id' => $finalId] + $this->syncFinal($supplierId, $side, $finalId, $advanceId, $userId);
    }

    /**
     * Má záloha živou úhradu (banka, pokladna) zaúčtovanou v POZDĚJŠÍM účetním období
     * (hospodářském roce) než zápis konečné faktury? Porovnává se účetní období zápisů
     * (accounting_periods.starts_on), ne kalendářní rok — firma s hospodářským rokem má
     * hranici jinde. Úhrada v DŘÍVĚJŠÍM období je běžný případ (záloha zaplacená v prosinci,
     * faktura v lednu) a zúčtování se k datu faktury doplní normálně.
     *
     * @param 'purchase'|'sale' $side
     */
    private function paymentInLaterPeriod(int $supplierId, string $side, int $advanceId, int $finalPeriodId): bool
    {
        $start = $this->db->pdo()->prepare('SELECT starts_on FROM accounting_periods WHERE id = ? AND supplier_id = ?');
        $start->execute([$finalPeriodId, $supplierId]);
        $finalStart = $start->fetchColumn();
        if ($finalStart === false) {
            return false;
        }
        [$link, $docColumn] = $side === 'sale'
            ? ['invoice_payments x', 'x.invoice_id']
            : ['payment_matches x', 'x.purchase_invoice_id'];
        $cashColumn = $side === 'sale' ? 'cd.invoice_id' : 'cd.purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT ap.starts_on FROM {$link}
               JOIN journal_entries je ON je.supplier_id = x.supplier_id AND je.source_type = 'bank'
                AND je.source_id = x.bank_transaction_id AND je.reversed_by IS NULL
               JOIN accounting_periods ap ON ap.id = je.period_id
              WHERE x.supplier_id = :sid AND {$docColumn} = :aid
             UNION ALL
             SELECT ap.starts_on FROM cash_documents cd
               JOIN journal_entries je ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                AND je.source_id = cd.id AND je.reversed_by IS NULL
               JOIN accounting_periods ap ON ap.id = je.period_id
              WHERE cd.supplier_id = :sid2 AND {$cashColumn} = :aid2"
        );
        $stmt->execute([':sid' => $supplierId, ':aid' => $advanceId, ':sid2' => $supplierId, ':aid2' => $advanceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $paymentStart) {
            if ((string) $paymentStart > (string) $finalStart) {
                return true;
            }
        }
        return false;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }

    /**
     * @param 'purchase'|'sale' $side
     * @return array{action:string, reason?:string, entry_id?:int, before?:float, after?:float}
     */
    private function syncFinal(int $supplierId, string $side, int $finalId, int $advanceId, ?int $userId): array
    {
        $sourceType = $side === 'sale' ? 'invoice' : 'purchase_invoice';
        $active = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $finalId, $this->db->pdo()->inTransaction());
        if ($active === []) {
            // Nezaúčtovaná konečná faktura si zúčtování spočítá sama při zaúčtování.
            return ['action' => 'none', 'reason' => 'final_not_posted'];
        }
        $entry = $active[0];
        $entryId = (int) $entry['id'];
        if ((new TakenOverRecord($this->db))->isDocumentOrItsEntry($supplierId, $sourceType, $finalId)) {
            // Převzatý zápis musí souhlasit s deníkem zdroje — automatika ho nepřepisuje.
            return ['action' => 'skipped', 'reason' => 'taken_over', 'entry_id' => $entryId];
        }
        // Zúčtování mění řádky jediného předpisu. Rozdělenou skupinu ani jejího
        // osamoceného ručního člena nesmíme vydávat za nezaúčtovanou fakturu nebo
        // do prvního člena připsat pár, který už může nést jiný člen skupiny.
        if (!DocumentPostingGroup::isSingleDirectEntry($active, $sourceType, $finalId)) {
            return $this->stale($supplierId, $sourceType, $finalId, $entryId, 'posting_group_requires_repost', $userId);
        }

        ['debit' => $debitCode, 'credit' => $creditCode] = $this->posting->advanceSettlementAccounts($supplierId, $side);
        $debitCode = $this->posting->redirectedAccountCode($supplierId, $debitCode);
        $creditCode = $this->posting->redirectedAccountCode($supplierId, $creditCode);
        if ($debitCode === $creditCode) {
            // Záloha vedená přímo na saldokontu: pár se nezapisuje, není co dorovnávat.
            return ['action' => 'none', 'reason' => 'settlement_on_same_account', 'entry_id' => $entryId];
        }
        $isPair = static fn (string $code, string $lineSide): bool =>
            ($code === $debitCode && $lineSide === 'debit') || ($code === $creditCode && $lineSide === 'credit');

        // Záporná konečná faktura (vyúčtování k vyplacení) má saldokonto na opačné straně,
        // takže by se předpis nedal od páru zúčtování odlišit. Taková se dorovná ručně.
        if ($this->documentTotal($supplierId, $side, $finalId) < 0.0) {
            return $this->stale($supplierId, $sourceType, $finalId, $entryId, 'negative_final', $userId);
        }

        try {
            $rebuilt = $side === 'sale'
                ? $this->posting->buildFromInvoice($supplierId, $finalId, ['entry_date' => (string) $entry['entry_date']])
                : $this->posting->buildFromPurchaseInvoice($supplierId, $finalId, ['entry_date' => (string) $entry['entry_date']]);
        } catch (PostingException $e) {
            return $this->stale($supplierId, $sourceType, $finalId, $entryId, $e->errorCode, $userId);
        }
        $expected = [];
        foreach ($rebuilt as $line) {
            $code = $this->posting->redirectedAccountCode($supplierId, (string) $line['account_code'], (string) $entry['entry_date']);
            if ($isPair($code, (string) $line['side'])) {
                $expected[] = ['account_code' => $code] + $line;
            }
        }

        $accountMap = $this->accounts->idToAccountMap($supplierId, (string) $entry['entry_date']);
        $current = [];
        $currentIds = [];
        foreach ($this->journal->linesForEntry($entryId, $supplierId) as $line) {
            $code = (string) ($accountMap[(int) $line['account_id']]['code'] ?? '');
            if ($isPair($code, (string) $line['side'])) {
                $current[] = ['account_code' => $code, 'side' => (string) $line['side'], 'amount' => (float) $line['amount']];
                $currentIds[] = (int) $line['id'];
            }
        }

        $before = self::pairAmount($current, $creditCode);
        $after = self::pairAmount($expected, $creditCode);
        if (self::signature($current) === self::signature($expected)) {
            return ['action' => 'in_sync', 'entry_id' => $entryId, 'before' => $before, 'after' => $after];
        }

        // Zúčtování se zapisuje k datu KONEČNÉ faktury. Úhrada zálohy z POZDĚJŠÍHO účetního
        // období (faktura 12/2026, záloha zaplacená 1/2027) by tak zpětně čerpala 314 v roce,
        // kdy záloha ještě zaplacená nebyla — rozvaha uzavíraného roku by lhala. Opačný směr
        // (záloha 12/2026, faktura 1/2027) je běžný a dorovná se normálně. Samostatný
        // zápis zúčtování k datu úhrady by potřeboval nový zdroj zápisu a builder konečné
        // faktury, který by ho při přeúčtování odečítal; to je mimo rozsah téhle opravy.
        // Proto se takový případ jen zaloguje a vyřeší ručním zápisem zúčtování k datu úhrady.
        // Uvnitř téhož roku se zúčtování k datu faktury nechává (321 i 314 jsou saldokonta
        // bez vlivu na DPH; podané DPH chrání zámek k datu v replaceEntryLines).
        if (self::cents($after) > self::cents($before)
            && $this->paymentInLaterPeriod($supplierId, $side, $advanceId, (int) $entry['period_id'])) {
            return $this->stale($supplierId, $sourceType, $finalId, $entryId, 'payment_in_later_period', $userId);
        }

        try {
            // Mění se JEN řádky páru zúčtování. Ostatní řádky zápisu (dimenze, rozpad podle
            // položek, ruční přeúčtování) i posted_at/posted_by zůstávají beze změny.
            $this->posting->replaceEntryLines(
                $supplierId,
                $sourceType,
                $finalId,
                (int) $entry['row_version'],
                $currentIds,
                $expected,
                ['user_id' => $userId],
            );
        } catch (PostingException $e) {
            return $this->stale($supplierId, $sourceType, $finalId, $entryId, $e->errorCode, $userId);
        }

        $this->activity->log(
            'accounting.advance_settlement_synced',
            $userId,
            $sourceType,
            $finalId,
            ['journal_entry_id' => $entryId, 'before' => $before, 'after' => $after],
            supplierId: $supplierId,
        );
        return ['action' => 'synced', 'entry_id' => $entryId, 'before' => $before, 'after' => $after];
    }

    /** @param 'purchase'|'sale' $side */
    private function safely(int $supplierId, string $side, int $advanceId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $inTx = $pdo->inTransaction();
        $savepoint = 'advance_settlement_' . $side . '_' . max(0, $advanceId);
        if ($inTx) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $this->syncAdvance($supplierId, $side, $advanceId, $userId);
            if ($inTx) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($inTx && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            try {
                $this->activity->log(
                    'accounting.advance_settlement_stale',
                    $userId,
                    $side === 'sale' ? 'invoice' : 'purchase_invoice',
                    $advanceId,
                    ['reason' => 'error', 'message' => $e->getMessage()],
                    supplierId: $supplierId,
                );
            } catch (\Throwable $auditFailure) {
                if (TransactionFailure::rollbackIfAborted($pdo, $auditFailure)) {
                    throw $auditFailure;
                }
            }
        }
    }

    /**
     * Zápis zůstal se starým zúčtováním — účetní to musí vidět v historii dokladu,
     * ne zjistit až z rozjetého 314/324.
     *
     * @return array{action:string, reason:string, entry_id:int}
     */
    private function stale(int $supplierId, string $sourceType, int $finalId, int $entryId, string $reason, ?int $userId): array
    {
        $this->activity->log(
            'accounting.advance_settlement_stale',
            $userId,
            $sourceType,
            $finalId,
            ['journal_entry_id' => $entryId, 'reason' => $reason],
            supplierId: $supplierId,
        );
        return ['action' => 'skipped', 'reason' => $reason, 'entry_id' => $entryId];
    }

    /** @param 'purchase'|'sale' $side */
    private function finalOf(int $supplierId, string $side, int $advanceId): ?int
    {
        $sql = $side === 'sale'
            ? "SELECT id FROM invoices
                WHERE supplier_id = ? AND parent_invoice_id = ? AND invoice_type = 'invoice'
                  AND status NOT IN ('draft', 'cancelled')
                ORDER BY id"
            : "SELECT id FROM purchase_invoices
                WHERE supplier_id = ? AND advance_purchase_invoice_id = ? AND document_kind = 'invoice'
                  AND status NOT IN ('draft', 'cancelled')
                ORDER BY id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $advanceId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        // Víc konečných faktur k jedné proformě builder odmítne (advance_settlement_ambiguous);
        // první z nich to zaloguje přes stale().
        return $ids[0] ?? null;
    }

    /** @param 'purchase'|'sale' $side */
    private function documentTotal(int $supplierId, string $side, int $id): float
    {
        $table = $side === 'sale' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT total_with_vat FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $supplierId]);
        return (float) $stmt->fetchColumn();
    }

    private function documentKind(string $table, string $column, int $supplierId, int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $supplierId]);
        $kind = $stmt->fetchColumn();
        return $kind === false || $kind === null ? null : (string) $kind;
    }

    /** @param list<array<string,mixed>> $lines */
    private static function pairAmount(array $lines, string $creditCode): float
    {
        $sum = 0;
        foreach ($lines as $l) {
            if ((string) $l['account_code'] === $creditCode && (string) $l['side'] === 'credit') {
                $sum += (int) round((float) $l['amount'] * 100.0);
            }
        }
        return $sum / 100;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    private static function signature(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = $l['side'] . '|' . $l['account_code'] . '|' . (int) round((float) $l['amount'] * 100.0);
        }
        sort($out);
        return $out;
    }
}
