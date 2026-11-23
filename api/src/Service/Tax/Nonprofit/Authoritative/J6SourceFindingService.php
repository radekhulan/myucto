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
            $last = null;
            foreach ($page->rows as $row) {
                $lineCodes = $codes[$row['line_id']] ?? [];
                if ($lineCodes === [] || ($code !== null && !in_array($code, $lineCodes, true))
                    || ($account !== null && $row['account_code'] !== $account)) continue;
                if ($last !== null) {
                    // Stránka je plná a za ní je ještě shodný řádek: pokračuje se za posledním vráceným.
                    $next = new SnapshotCursor($last['date'], $last['entry_id'], $last['line_id']);
                    break 2;
                }
                $out[] = ['line_id' => $row['line_id'], 'entry_id' => $row['entry_id'], 'line_no' => $row['line_no'],
                    'date' => $row['date'], 'account_code' => $row['account_code'], 'kind' => $row['kind'],
                    'amount_cents' => $row['amount_cents'], 'codes' => $lineCodes];
                if (count($out) === $limit) $last = $row;
            }
            if ($last !== null) {
                // Plná stránka bez dalšího shodného řádku v téže stránce podkladů: konec období
                // znamená konec seznamu, jinak se pokračuje od dalšího čtení podkladů.
                $next = $page->nextCursor === null ? null : new SnapshotCursor($last['date'], $last['entry_id'], $last['line_id']);
                break;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $scanned < self::SCAN_BUDGET);
        if (count($out) < $limit) $next = $cursor;
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

    /**
     * Číslo dokladu, popis a zdroj zápisu jedním dotazem pro celou stránku. Zdroj nese stejné
     * obohacení jako deník ({@see \MyInvoice\Repository\JournalEntryRepository::paginate()}),
     * aby proklik vedl na tentýž prvotní doklad (výpis banky, pokladní doklad, karta majetku,
     * vyrovnaný doklad zápočtu, u protizápisu zdroj stornovaného zápisu).
     */
    private function withEntries(int $supplierId, array $rows): array
    {
        $ids = array_values(array_unique(array_column($rows, 'entry_id')));
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare("SELECT je.id,
                   COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                   je.description, je.source_type, je.source_id,
                   COALESCE(je.source_id, rev_src.source_id) AS source_link_id,
                   COALESCE(bt.statement_id, rev_bt.statement_id) AS source_statement_id,
                   COALESCE(cd.doc_number, rev_cd.doc_number) AS source_doc_number,
                   COALESCE(cd.register_id, rev_cd.register_id) AS source_register_id,
                   ast.id AS source_asset_id,
                   stl.doc_type AS source_settlement_doc_type,
                   stl.doc_id AS source_settlement_doc_id
              FROM journal_entries je
         LEFT JOIN journal_entries rev_src FORCE INDEX (idx_je_supplier_reversed_by)
                ON rev_src.supplier_id = je.supplier_id AND rev_src.reversed_by = je.id
         LEFT JOIN bank_transactions rev_bt ON rev_src.source_type = 'bank' AND rev_bt.id = rev_src.source_id
         LEFT JOIN cash_documents rev_cd ON rev_src.source_type = 'cash' AND rev_cd.id = rev_src.source_id
         LEFT JOIN bank_transactions bt ON je.source_type = 'bank' AND bt.id = je.source_id
         LEFT JOIN cash_documents cd ON je.source_type = 'cash' AND cd.id = je.source_id
         LEFT JOIN invoice_settlements stl ON je.source_type = 'settlement' AND stl.id = je.source_id AND stl.supplier_id = je.supplier_id
         LEFT JOIN invoices src_i ON je.source_type = 'invoice' AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
         LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice' AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
         LEFT JOIN depreciation_entries dep ON je.source_type = 'depreciation' AND dep.id = je.source_id AND dep.supplier_id = je.supplier_id
         LEFT JOIN assets ast ON ast.supplier_id = je.supplier_id
                AND ast.id = CASE WHEN je.source_type IN ('asset', 'asset_disposal') THEN je.source_id
                                  WHEN je.source_type = 'depreciation' THEN dep.asset_id ELSE NULL END
             WHERE je.supplier_id = ? AND je.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) $entries[(int) $entry['id']] = $entry;
        $ints = ['source_id', 'source_link_id', 'source_statement_id', 'source_register_id', 'source_asset_id', 'source_settlement_doc_id'];
        foreach ($rows as &$row) {
            $entry = $entries[$row['entry_id']];
            foreach (['document_no', 'description', 'source_type', 'source_doc_number', 'source_settlement_doc_type'] as $key) $row[$key] = $entry[$key];
            foreach ($ints as $key) $row[$key] = $entry[$key] === null ? null : (int) $entry[$key];
        }
        unset($row);
        return $rows;
    }
}
