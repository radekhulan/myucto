<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalSettlementRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Řádek zápisu rozepsaný na doklady saldokonta (`journal_settlement_allocations`) se nesmí
 * tiše změnit pod rozpadem. Rozpad drží řádek přes pořadí, účet a stranu; přeúčtování,
 * které by na tom pořadí nechalo jiný účet, stranu nebo menší částku, než je rozepsáno,
 * by vyrovnání přiřadilo jinému řádku nebo ho nechalo v plné výši. Smazání zápisu by
 * rozpad odneslo kaskádou a ostatní položka založená ze zůstatku by osiřela.
 *
 * Proto se taková změna odmítne: nejdřív je potřeba přiřazení zrušit v saldokontu (předpis
 * ostatní položky stornem položky, zápočet ostatní položky v jejím detailu). Chybový kód
 * začíná `allocation_`, takže ho akce deníku mapují jako ostatní zámky řádků.
 *
 * Totéž platí pro storno a přeúčtování jednotlivých dvojic opravným zápisem
 * ({@see assertLinesUnallocated()}): původní řádek by v hlavní knize zmizel, rozpad by
 * vyrovnání dál počítal.
 */
final class JournalSettlementUseGuard
{
    public const CODE = 'allocation_saldo_in_use';

    public function __construct(private readonly Connection $db) {}

    /** Zápisy se smí smazat jen bez rozpadu (ani zrušeného předpisu, ten nese historii). */
    public function assertEntriesFree(int $supplierId, array $entryIds): void
    {
        $allocations = (new JournalSettlementRepository($this->db))->forEntries($supplierId, $entryIds);
        if ($allocations !== []) {
            throw self::inUse(self::offsetsOtherItem($allocations));
        }
    }

    /**
     * Odmítnutí pro volající, kteří ho vracejí jako výsledek: hláška podle toho, zda
     * zápis nese zápočet ostatní položky (ten se ruší v detailu položky, ne v saldokontu).
     *
     * @param list<int> $entryIds
     * @return array{code:string, message:string}
     */
    public function blockFor(int $supplierId, array $entryIds): array
    {
        return self::block(self::offsetsOtherItem((new JournalSettlementRepository($this->db))->forEntries($supplierId, $entryIds)));
    }

    /**
     * Storno nebo přeúčtování vybraných řádků zápisu opravným zápisem (dvojice): žádný z nich
     * nesmí nést rozpad. Platí pro rozpady na faktury i pro zápočty ostatních položek;
     * zrušený předpis ostatní položky ze zůstatku už řádek nedrží.
     *
     * @param list<array<string,mixed>> $lines řádky s `line_no`, `account_id`, `side`
     */
    public function assertLinesUnallocated(int $supplierId, int $entryId, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $allocations = (new JournalSettlementRepository($this->db))->forEntries($supplierId, [$entryId]);
        if ($allocations === []) {
            return;
        }
        $selected = [];
        foreach ($lines as $line) {
            $selected[(int) $line['line_no'] . ':' . (int) $line['account_id'] . ':' . (string) $line['side']] = true;
        }
        $hit = [];
        foreach ($allocations as $a) {
            if ($a['kind'] === 'origin' && $a['released_on'] !== null) {
                continue;
            }
            if (isset($selected[$a['line_no'] . ':' . $a['account_id'] . ':' . $a['side']])) {
                $hit[] = $a;
            }
        }
        if ($hit !== []) {
            throw self::inUse(self::offsetsOtherItem($hit));
        }
    }

    /**
     * První zápis sady s rozpadem na doklady saldokonta, pro volající, kteří odmítnutí
     * vracejí jako výsledek ({@see DocumentJournalPurge::purge()}).
     *
     * @param list<int> $entryIds
     */
    public function allocatedEntry(int $supplierId, array $entryIds): ?int
    {
        $allocations = (new JournalSettlementRepository($this->db))->forEntries($supplierId, $entryIds);
        return $allocations === [] ? null : $allocations[0]['entry_id'];
    }

    /**
     * Celá nová sada řádků zápisu (přeúčtování): každý rozepsaný řádek musí na svém pořadí
     * zůstat se stejným účtem a stranou, bez červeného storna a s částkou aspoň ve výši
     * rozpadu. Pořadí řádku bez `line_no` je jeho pozice (shodně s vkládáním řádků).
     *
     * @param list<array<string,mixed>> $lines
     */
    public function assertEntryChange(int $supplierId, int $entryId, array $lines): void
    {
        $allocations = (new JournalSettlementRepository($this->db))->forEntries($supplierId, [$entryId]);
        if ($allocations === []) {
            return;
        }
        $inUse = self::inUse(self::offsetsOtherItem($allocations));
        $byNo = [];
        foreach (array_values($lines) as $i => $line) {
            $byNo[(int) ($line['line_no'] ?? $i)][] = $line;
        }
        $needed = [];
        foreach ($allocations as $a) {
            if ($a['kind'] === 'origin' && $a['released_on'] !== null) {
                continue;
            }
            $key = $a['line_no'] . ':' . $a['account_id'] . ':' . $a['side'];
            $needed[$key] = ($needed[$key] ?? 0) + (int) round($a['amount'] * 100);
        }
        foreach ($needed as $key => $cents) {
            [$no, $account, $side] = explode(':', $key);
            $candidates = $byNo[(int) $no] ?? [];
            if (count($candidates) !== 1) {
                throw $inUse;
            }
            $line = $candidates[0];
            if ((int) $line['account_id'] !== (int) $account || (string) $line['side'] !== $side || !empty($line['is_red_storno'])
                || (int) round((float) JournalLineAmount::decimal($line['amount']) * 100) < $cents) {
                throw $inUse;
            }
        }
    }

    /** Odebrání vybraných řádků zápisu: žádný z nich nesmí nést rozpad. */
    public function assertLinesRemovable(int $supplierId, int $entryId, array $removeLineIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $removeLineIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $allocations = (new JournalSettlementRepository($this->db))->forEntries($supplierId, [$entryId]);
        if ($allocations === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT line_no, account_id, side FROM journal_entry_lines
              WHERE supplier_id = ? AND entry_id = ? AND id IN (' . implode(',', $ids) . ')'
        );
        $stmt->execute([$supplierId, $entryId]);
        $removed = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $removed[$r['line_no'] . ':' . $r['account_id'] . ':' . $r['side']] = true;
        }
        foreach ($allocations as $a) {
            if (isset($removed[$a['line_no'] . ':' . $a['account_id'] . ':' . $a['side']])) {
                throw self::inUse(self::offsetsOtherItem([$a]));
            }
        }
    }

    /**
     * @param bool $otherItemOffset řádek vyrovnává ostatní položku zápočtem (migrace 2351):
     *                              ruší se v detailu položky, saldokonto ho zrušit neumí
     * @return array{code:string, message:string}
     */
    public static function block(bool $otherItemOffset = false): array
    {
        return [
            'code'    => self::CODE,
            'message' => $otherItemOffset
                ? 'Řádek zápisu vyrovnává zápočtem ostatní pohledávku nebo závazek. Nejdřív zápočet zrušte v detailu položky (Ostatní pohledávky a závazky): odeberte přiřazení, nebo stornujte interní doklad zápočtu.'
                : 'Řádek zápisu je v saldokontu přiřazený dokladům nebo ostatní položce. Nejdřív přiřazení zrušte v saldokontu (předpis ostatní položky stornem položky).',
        ];
    }

    /** @param list<array{kind:string, doc_type?:string}> $allocations */
    private static function offsetsOtherItem(array $allocations): bool
    {
        foreach ($allocations as $a) {
            if ($a['kind'] === 'settlement' && ($a['doc_type'] ?? null) === 'other_item') {
                return true;
            }
        }
        return false;
    }

    private static function inUse(bool $otherItemOffset = false): PostingException
    {
        $block = self::block($otherItemOffset);
        return new PostingException($block['code'], $block['message'], 409);
    }
}
