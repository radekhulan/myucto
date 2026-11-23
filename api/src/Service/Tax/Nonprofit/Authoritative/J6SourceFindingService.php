<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use PDO;

/**
 * Zjištění podkladů daňového posouzení za období mimo koncept: souhrn po účtu a kódu
 * a stránkovaný seznam řádků se zjištěním.
 *
 * Zjištění vznikají jen ve stránkovaném čtení podkladů (NonprofitTaxSnapshotBatchReader),
 * proto souhrn běží v témže průchodu jako otisk podkladů (J6CurrentProviderFactory::fingerprint)
 * a vrací jeho hlavičku beze změny. Nic nezapisuje a podklady nezamyká.
 */
final readonly class J6SourceFindingService
{
    /** Kolik řádků deníku jedno volání seznamu nejvýše prohledá, než vrátí kurzor pro pokračování. */
    public const SCAN_BUDGET = 5000;

    public function __construct(private Connection $db, private J6CurrentProviderFactory $current) {}

    /**
     * Souhrn zjištění po účtu × kódu, počítaný v řádcích. Zjištění patří vždy stránce
     * svého řádku, takže počet řádků se zjištěním jde sečíst po stránkách bez seznamu
     * všech řádků v paměti.
     *
     * @return array{source: array<string,mixed>, line_count: int, groups: list<array{account_id:int, account_code:string, account_name:?string, code:string, line_count:int}>}
     */
    public function summary(int $supplierId, int $periodId): array
    {
        $groups = [];
        $lineCount = 0;
        $fingerprint = $this->current->fingerprint($supplierId, $periodId, false,
            static function (array $rows, array $findings) use (&$groups, &$lineCount): void {
                if ($findings === []) return;
                $accounts = array_column($rows, null, 'line_id');
                $lines = [];
                foreach ($findings as $finding) {
                    $row = $accounts[$finding['line_id']];
                    $key = $row['account_code'] . "\0" . $finding['code'];
                    $groups[$key] ??= ['account_id' => $row['account_id'], 'account_code' => $row['account_code'],
                        'account_name' => null, 'code' => $finding['code'], 'line_count' => 0];
                    ++$groups[$key]['line_count'];
                    $lines[$finding['line_id']] = true;
                }
                $lineCount += count($lines);
            });
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [$a['account_code'], $a['code']] <=> [$b['account_code'], $b['code']]);
        $names = $this->accountNames($supplierId, array_values(array_unique(array_column($groups, 'account_id'))));
        foreach ($groups as &$group) $group['account_name'] = $names[$group['account_id']] ?? null;
        unset($group);
        return ['source' => $fingerprint->toArray(), 'line_count' => $lineCount, 'groups' => $groups];
    }

    /**
     * Řádky se zjištěním v pořadí podkladů. Filtr podle kódu nejde do SQL (zjištění vznikají
     * až v PHP), proto volání prohledá nejvýše SCAN_BUDGET řádků deníku a vrátí kurzor,
     * od kterého se pokračuje; `next_cursor` null znamená konec období.
     *
     * @return array{rows: list<array<string,mixed>>, next_cursor: ?array{entry_date:string, entry_id:int, line_id:int}}
     */
    public function lines(int $supplierId, int $periodId, ?string $code, ?string $account, ?SnapshotCursor $after, int $limit): array
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Finding page limit must be between 1 and 500.');
        $out = [];
        $scanned = 0;
        $cursor = $after;
        $next = null;
        do {
            $page = $this->current->page($supplierId, $periodId, $cursor, 500);
            $scanned += $page->scannedCount;
            $codes = [];
            foreach ($page->findings as $finding) $codes[$finding['line_id']][] = $finding['code'];
            foreach ($page->rows as $row) {
                $lineCodes = $codes[$row['line_id']] ?? [];
                if ($lineCodes === [] || ($code !== null && !in_array($code, $lineCodes, true))
                    || ($account !== null && $row['account_code'] !== $account)) continue;
                $out[] = ['line_id' => $row['line_id'], 'entry_id' => $row['entry_id'], 'line_no' => $row['line_no'],
                    'date' => $row['date'], 'account_code' => $row['account_code'], 'kind' => $row['kind'],
                    'amount_cents' => $row['amount_cents'], 'codes' => $lineCodes,
                    'source_type' => $row['source_type'], 'source_id' => $row['source_id']];
                if (count($out) === $limit) {
                    $next = new SnapshotCursor($row['date'], $row['entry_id'], $row['line_id']);
                    break 2;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $scanned < self::SCAN_BUDGET);
        $next ??= $cursor;
        return ['rows' => $this->withEntries($supplierId, $out), 'next_cursor' => $next?->toArray()];
    }

    /** @param list<int> $ids @return array<int,string> */
    private function accountNames(int $supplierId, array $ids): array
    {
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT id, name FROM chart_of_accounts WHERE supplier_id=? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $names[(int) $row['id']] = (string) $row['name'];
        return $names;
    }

    /** Číslo dokladu a popis zápisu jedním dotazem pro celou stránku. */
    private function withEntries(int $supplierId, array $rows): array
    {
        $ids = array_values(array_unique(array_column($rows, 'entry_id')));
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT id, document_no, description FROM journal_entries WHERE supplier_id=? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) $entries[(int) $entry['id']] = $entry;
        foreach ($rows as &$row) {
            $row['document_no'] = $entries[$row['entry_id']]['document_no'] ?? null;
            $row['description'] = $entries[$row['entry_id']]['description'] ?? null;
        }
        unset($row);
        return $rows;
    }
}
