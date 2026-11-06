<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionAssignmentOrigin;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipJournalProof
{
    public const MAX_BATCH = 500;

    public function __construct(private readonly Connection $db) {}

    public function capture(int $supplier, int $entry, int $case, array $plan, string $operation, ?int $originalEntry = null, bool $current = true): array
    {
        return $this->captureMany($supplier, [[
            'key' => 0, 'entry_id' => $entry, 'case_id' => $case, 'plan' => $plan,
            'operation' => $operation, 'original_entry_id' => $originalEntry,
        ]], $current)[0];
    }

    public function captureMany(int $supplier, array $requests, bool $current = true): array
    {
        if ($current && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership journal proof current reads require a transaction.');
        if ($supplier <= 0 || $requests === [] || count($requests) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Membership journal proof batch must contain one tenant and at most 500 operations.');
        }
        $entries = [];
        $originalEntries = [];
        $byKey = [];
        foreach ($requests as $request) {
            if (!is_array($request) || !array_key_exists('key', $request) || !is_int($request['entry_id'] ?? null)
                || $request['entry_id'] <= 0 || !is_int($request['case_id'] ?? null) || $request['case_id'] <= 0
                || !in_array($request['operation'] ?? null, ['issue', 'reverse'], true) || !is_array($request['plan'] ?? null)
                || count($request['plan']) !== 2 || ($request['operation'] === 'reverse' && (!is_int($request['original_entry_id'] ?? null) || $request['original_entry_id'] <= 0))) {
                throw new \InvalidArgumentException('Membership journal proof batch contains an invalid operation reference.');
            }
            $key = is_int($request['key']) || is_string($request['key']) ? $request['key'] : throw new \InvalidArgumentException('Membership journal proof key is invalid.');
            if (array_key_exists($key, $byKey)) throw new \InvalidArgumentException('Membership journal proof key is duplicated.');
            $byKey[$key] = $request;
            $entries[$request['entry_id']] = $request['entry_id'];
            if ($request['operation'] === 'reverse') $originalEntries[$request['original_entry_id']] = $request['original_entry_id'];
        }

        $pdo = $this->db->pdo();
        $lock = $current ? ' FOR UPDATE' : '';
        $headerIds = array_values($entries + $originalEntries);
        $marks = implode(',', array_fill(0, count($headerIds), '?'));
        $q = $pdo->prepare("SELECT * FROM journal_entries WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id{$lock}");
        $q->execute([$supplier, ...$headerIds]);
        $headers = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $headers[(int) $row['id']] = $row;

        $entryIds = array_values($entries);
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $q = $pdo->prepare("SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id IN ({$marks}) ORDER BY l.entry_id,l.line_no,l.id{$lock}");
        $q->execute([$supplier, ...$entryIds]);
        $lines = [];
        $lineIds = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[(int) $row['entry_id']][] = $row;
            $lineIds[(int) $row['id']] = (int) $row['id'];
        }
        $dimensions = [];
        $splitLines = [];
        if ($lineIds !== []) {
            $marks = implode(',', array_fill(0, count($lineIds), '?'));
            $q = $pdo->prepare("SELECT line_id,dimension_type_id,dimension_value_id,assignment_origin,assignment_origin_ref FROM journal_entry_line_dimensions WHERE supplier_id=? AND line_id IN ({$marks}) ORDER BY line_id,dimension_type_id{$lock}");
            $q->execute([$supplier, ...array_values($lineIds)]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $line = (int) $row['line_id'];
                $type = (int) $row['dimension_type_id'];
                $dimensions[$line]['dimensions'][$type] = (int) $row['dimension_value_id'];
                $dimensions[$line]['dimension_origins'][$type] = DimensionAssignmentOrigin::normalize([
                    'origin' => $row['assignment_origin'], 'ref' => $row['assignment_origin_ref'],
                ]);
            }
            $q = $pdo->prepare("SELECT DISTINCT line_id FROM journal_entry_line_dimension_splits WHERE supplier_id=? AND line_id IN ({$marks}) ORDER BY line_id{$lock}");
            $q->execute([$supplier, ...array_values($lineIds)]);
            while (($line = $q->fetchColumn()) !== false) $splitLines[(int) $line] = true;
        }

        $out = [];
        foreach ($byKey as $key => $request) {
            $header = $headers[$request['entry_id']] ?? null;
            if ($header === null || $header['source_type'] !== 'nonprofit_membership' || $header['posted_at'] === null
                || ($request['operation'] === 'issue' && (int) $header['source_id'] !== $request['case_id'])
                || ($request['operation'] === 'reverse' && $header['source_id'] !== null)) self::mismatch();
            if ($request['operation'] === 'reverse' && (int) ($headers[$request['original_entry_id']]['reversed_by'] ?? 0) !== $request['entry_id']) self::mismatch();
            $entryLines = $lines[$request['entry_id']] ?? [];
            if (count($entryLines) !== 2) self::mismatch();
            $captured = [];
            foreach ($entryLines as $index => $line) {
                $want = $request['plan'][$index];
                $actualClassification = $dimensions[(int) $line['id']] ?? ['dimensions' => [], 'dimension_origins' => []];
                $wantClassification = self::lineClassification($want);
                if ($line['account_code'] !== $want['account_code'] || $line['side'] !== $want['side']
                    || JournalLineAmount::decimal($line['amount']) !== $want['amount'] || !empty($line['is_red_storno'])
                    || $line['currency_code'] !== null || $line['amount_foreign'] !== null || $line['fx_rate'] !== null
                    || $line['cost_center'] !== null || $line['project_id'] !== null || isset($splitLines[(int) $line['id']])
                    || $actualClassification !== $wantClassification) self::mismatch();
                $captured[] = ['id' => (int) $line['id'], 'line_no' => (int) $line['line_no'], 'account_id' => (int) $line['account_id'],
                    'account_code' => $line['account_code'], 'side' => $line['side'], 'amount' => JournalLineAmount::decimal($line['amount'])] + $actualClassification;
            }
            $out[$key] = [
                'entry' => ['id' => (int) $header['id'], 'period_id' => (int) $header['period_id'], 'entry_date' => $header['entry_date'],
                    'source_type' => $header['source_type'], 'source_id' => $header['source_id'] === null ? null : (int) $header['source_id'],
                    'posted_by' => $header['posted_by'] === null ? null : (int) $header['posted_by']],
                'lines' => $captured,
                'hash' => NonprofitTaxRules::hash(['entry' => [(int) $header['id'], (int) $header['period_id'], $header['entry_date'], $header['source_type'], $header['source_id']], 'lines' => $captured]),
            ];
        }
        return $out;
    }

    public function assertOperation(array $operation, bool $current = true): void
    {
        $this->assertOperations((int) $operation['supplier_id'], [$operation], $current);
    }

    public function assertOperations(int $supplier, array $operations, bool $current = true): void
    {
        $requests = [];
        foreach ($operations as $operation) {
            if ((int) ($operation['supplier_id'] ?? 0) !== $supplier || !is_int($operation['id'] ?? null)) {
                throw new \InvalidArgumentException('Membership journal proof operations must belong to one tenant.');
            }
            $plan = MembershipJournalPlan::issue($operation['proof']['accounts']['receivable']['account_code'], $operation['proof']['accounts']['revenue']['account_code'],
                bcdiv((string) $operation['amount_cents'], '100', 2), $operation['proof']['classification']);
            if ($operation['operation'] === 'reverse') $plan = MembershipJournalPlan::reverse($plan);
            $requests[] = ['key' => $operation['id'], 'entry_id' => $operation['journal_entry_id'], 'case_id' => $operation['case_id'], 'plan' => $plan,
                'operation' => $operation['operation'], 'original_entry_id' => $operation['operation'] === 'reverse' ? (int) $operation['proof']['original_journal_entry_id'] : null];
        }
        $receipts = $this->captureMany($supplier, $requests, $current);
        foreach ($operations as $operation) {
            if (!hash_equals(NonprofitTaxRules::hash($operation['receipt']), NonprofitTaxRules::hash($receipts[$operation['id']]))) self::mismatch();
        }
    }

    private static function lineClassification(array $line): array
    {
        if (!is_array($line['dimensions'] ?? null) || !is_array($line['dimension_origins'] ?? null)) self::mismatch();
        $dimensions = [];
        $origins = [];
        foreach ($line['dimensions'] as $type => $value) {
            if ((!is_int($type) && !ctype_digit((string) $type)) || !is_int($value) || (int) $type <= 0 || $value <= 0) self::mismatch();
            $dimensions[(int) $type] = $value;
            $origins[(int) $type] = DimensionAssignmentOrigin::normalize($line['dimension_origins'][$type] ?? []);
        }
        if (count($dimensions) !== 3 || count($line['dimension_origins']) !== 3) self::mismatch();
        ksort($dimensions, SORT_NUMERIC);
        ksort($origins, SORT_NUMERIC);
        return ['dimensions' => $dimensions, 'dimension_origins' => $origins];
    }

    private static function mismatch(): never
    {
        throw new MembershipException('nonprofit_membership_ledger_mismatch', 'Deník neodpovídá přesnému členskému předpisu.', 409);
    }
}
