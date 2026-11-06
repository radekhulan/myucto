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
 * ostatní položky stornem položky). Chybový kód začíná `allocation_`, takže ho akce deníku
 * mapují jako ostatní zámky řádků.
 */
final class JournalSettlementUseGuard
{
    public function __construct(private readonly Connection $db) {}

    /** Zápisy se smí smazat jen bez rozpadu (ani zrušeného předpisu, ten nese historii). */
    public function assertEntriesFree(int $supplierId, array $entryIds): void
    {
        if ((new JournalSettlementRepository($this->db))->forEntries($supplierId, $entryIds) !== []) {
            throw self::inUse();
        }
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
                throw self::inUse();
            }
            $line = $candidates[0];
            if ((int) $line['account_id'] !== (int) $account || (string) $line['side'] !== $side || !empty($line['is_red_storno'])
                || (int) round((float) JournalLineAmount::decimal($line['amount']) * 100) < $cents) {
                throw self::inUse();
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
                throw self::inUse();
            }
        }
    }

    private static function inUse(): PostingException
    {
        return new PostingException(
            'allocation_saldo_in_use',
            'Řádek zápisu je v saldokontu přiřazený dokladům nebo ostatní položce. Nejdřív přiřazení zrušte v saldokontu (předpis ostatní položky stornem položky).',
            409,
        );
    }
}
