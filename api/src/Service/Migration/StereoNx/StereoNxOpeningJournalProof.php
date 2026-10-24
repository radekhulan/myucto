<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use PDO;

/** Živý účetní důkaz počátečních stavů banky i pokladny; nevytváří ani nemění deník. */
final class StereoNxOpeningJournalProof
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zdrojový důkaz sdílí oba importní plánovače. Tady se navíc pod zámkem ověří
     * přesné mapované zápisy a jejich skutečné řádky, nikoli jen importní identita.
     * @return array<string,array{entries:list<array<string,mixed>>,entry_ids:list<int>}>
     */
    public function validate(array $context, array $openings, array $journalPlan, string $errorCode): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new StereoNxException('transaction_required', 'Ověření počátečních zápisů vyžaduje transakci převodu.');
        $fail = static fn () => throw new StereoNxException($errorCode, 'Počáteční stav nemá nezměněnou živou počáteční kontaci.');
        $sourceKeys = [];
        foreach ($journalPlan['accounting_plan']['entries'] ?? [] as $entry) {
            $key = (string) ($entry['source_key'] ?? '');
            if ($key === '' || isset($sourceKeys[$key])) $fail();
            $sourceKeys[$key] = true;
        }
        $index = StereoNxOpeningMovementProof::index($journalPlan);
        $sources = []; $entryKeys = []; $usedKeys = [];
        foreach ($openings as $opening) {
            $movement = (array) ($opening['movement'] ?? []);
            $key = (string) ($movement['source_key'] ?? '');
            $account = ImportedAccountCode::normalize((string) ($opening['account_code'] ?? ''));
            $prefix = substr($account, 0, 3);
            if ($key === '' || isset($sources[$key]) || !in_array($prefix, ['211', '221'], true)) $fail();
            $entries = $index[$prefix === '211' ? 'cash' : 'bank'][$key] ?? [];
            $proof = StereoNxOpeningMovementProof::prove($movement, $entries, $prefix, $account);
            $expectedKeys = $opening['entry_keys'] ?? [];
            if (!is_array($expectedKeys)) $fail();
            $actualKeys = $proof['entry_keys'] ?? [];
            sort($expectedKeys, SORT_STRING); sort($actualKeys, SORT_STRING);
            if ($proof === null || $expectedKeys !== $actualKeys || $expectedKeys === []
                || ($opening['opening_cents'] ?? null) !== $proof['opening_cents']
                || ($opening['date'] ?? null) !== $proof['date']
                || $account !== $proof['account_code']) $fail();
            foreach ($entries as $entry) {
                $entryKey = $entry['source_key'];
                if (isset($usedKeys[$entryKey])) $fail();
                $usedKeys[$entryKey] = true; $entryKeys[] = $entryKey;
            }
            $sources[$key] = $entries;
        }
        $maps = [];
        sort($entryKeys, SORT_STRING);
        foreach (array_chunk($entryKeys, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT source_key,source_hash,target_id FROM stereo_nx_import_map
                WHERE supplier_id=? AND source_ico=? AND source_company_index=? AND kind='accounting_journal'
                  AND source_key IN ({$marks}) ORDER BY source_key FOR UPDATE");
            $q->execute([$context['supplier_id'], $context['ico'], $context['company_index'], ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $maps[$row['source_key']] = $row;
        }
        $ids = []; $headers = []; $lines = [];
        foreach ($maps as $map) {
            $id = (int) $map['target_id'];
            if (isset($ids[$id])) $fail();
            $ids[$id] = true;
        }
        $entryIds = array_keys($ids);
        sort($entryIds, SORT_NUMERIC);
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT id,entry_date,source_type,source_id,posted_at,reversed_by FROM journal_entries
                WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
            $q->execute([$context['supplier_id'], ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $headers[(int) $row['id']] = $row;
            $q = $this->db->pdo()->prepare("SELECT l.entry_id,l.side,l.amount,l.is_red_storno,l.currency_code,l.amount_foreign,a.account_code
                FROM journal_entry_lines l FORCE INDEX (idx_jel_supplier_entry)
                STRAIGHT_JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
                WHERE l.supplier_id=? AND l.entry_id IN ({$marks}) ORDER BY l.entry_id,l.line_no,l.id FOR UPDATE");
            $q->execute([$context['supplier_id'], ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $lines[(int) $row['entry_id']][] = $row;
        }
        $result = [];
        foreach ($sources as $key => $entries) {
            $ids = [];
            foreach ($entries as $entry) {
                $map = $maps[$entry['source_key']] ?? null;
                $id = (int) ($map['target_id'] ?? 0); $header = $headers[$id] ?? null;
                if ($map === null || $header === null || !is_string($entry['source_hash'] ?? null)
                    || !hash_equals($map['source_hash'], $entry['source_hash'])
                    || $header['entry_date'] !== $entry['date'] || $header['source_type'] !== 'opening'
                    || $header['source_id'] !== null || $header['posted_at'] === null || $header['reversed_by'] !== null) $fail();
                $expected = [[self::account($entry, 'debit'), 'debit', $entry['amount_cents']],
                    [self::account($entry, 'credit'), 'credit', $entry['amount_cents']]];
                $actual = [];
                foreach ($lines[$id] ?? [] as $line) {
                    if ((bool) $line['is_red_storno'] || $line['amount_foreign'] !== null
                        || ($line['currency_code'] !== null && $line['currency_code'] !== 'CZK')) $fail();
                    $actual[] = [ImportedAccountCode::normalize($line['account_code']), $line['side'], StatementBalanceService::cents($line['amount'])];
                }
                sort($expected); sort($actual);
                if ($expected !== $actual) $fail();
                $ids[] = $id;
            }
            $result[$key] = ['entries' => $entries, 'entry_ids' => $ids];
        }
        return $result;
    }

    public static function account(array $entry, string $side): string
    {
        return ImportedAccountCode::normalize((string) ($entry['target_' . $side] ?? $entry[$side] ?? ''));
    }
}
