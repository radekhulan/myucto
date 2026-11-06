<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\Fx504Builder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class SecuritiesValuation504ReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public static function candidate(string $code): bool
    {
        return in_array(substr($code, 0, 3), ['061', '062', '063', '069', '251', '253', '255', '256'], true);
    }

    public function snapshot(int $supplierId, array $period, bool $currentRead = false, ?array $profileSnapshot = null): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Securities inventory requires a caller transaction.');
        if ($currentRead) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $profileSnapshot ??= $this->profileSnapshot($supplierId, $period, $currentRead);
        $priorPeriods = array_column(array_values(array_filter($profileSnapshot['periods'], static fn (array $p): bool => $p['ends_on'] < $period['starts_on'])), null, 'id');
        $profileSnapshot['history'] = array_values(array_filter($profileSnapshot['history'], static fn (array $row): bool => isset($priorPeriods[(int) $row['period_id']])));
        $headers = $this->rows('SELECT id,period_id,entry_date,source_type,source_id,posted_at,reversed_by,row_version,document_no FROM journal_entries WHERE supplier_id=? ORDER BY id', [$supplierId], $currentRead);
        $excluded = Fx504ReadRepository::excludedEntries(array_values(array_filter($headers, static fn (array $header): bool => $header['posted_at'] !== null && $header['entry_date'] <= $period['ends_on'])), (int) $period['id']);
        $rows = $this->rows('SELECT l.*,a.account_code,a.account_type,e.entry_date,e.source_type,e.source_id,e.posted_at,e.reversed_by,e.row_version AS entry_version
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE e.supplier_id=? ORDER BY e.id,l.line_no,l.id', [$supplierId], $currentRead);
        $ids = array_map('intval', array_column($rows, 'id'));
        $assignments = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignments->lineDimensions($supplierId, $ids, $currentRead);
        $splits = $assignments->lineSplits($supplierId, $ids, $currentRead);
        $origins = $assignments->lineOrigins($supplierId, $ids, $currentRead);
        $splitOrigins = $assignments->lineSplitOrigins($supplierId, $ids, $currentRead);
        $headers = array_values(array_filter($headers, static fn (array $header): bool => !isset($excluded[(int) $header['id']])));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => !isset($excluded[(int) $row['entry_id']])));
        $entries = [];
        foreach ($headers as $header) $entries[(int) $header['id']] = $header + ['lines' => []];
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['dimensions'] = $dimensions[$id] ?? [];
            $row['dimension_splits'] = $splits[$id] ?? [];
            $row['dimension_origins'] = $origins[$id] ?? [];
            $row['dimension_split_origins'] = $splitOrigins[$id] ?? [];
            $entries[(int) $row['entry_id']]['lines'][] = $row;
        }
        unset($row);
        $steps = $this->rows("SELECT s.id,s.period_id,s.status,s.step_key,s.payload FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND p.ends_on<? AND s.step_key IN ('open_next','close_books') ORDER BY s.period_id,s.id", [$supplierId, $period['starts_on']], $currentRead);
        $removed = [];
        $findings = [];
        foreach (array_reverse($entries, true) as $id => $entry) {
            $reverseId = (int) ($entry['reversed_by'] ?? 0);
            $reverse = $entries[$reverseId] ?? null;
            if (!$reverse || isset($removed[$id]) || isset($removed[$reverseId]) || $entry['posted_at'] === null || $reverse['posted_at'] === null || $reverse['entry_date'] > $period['ends_on']) continue;
            $same = count($entry['lines']) === count($reverse['lines']);
            foreach ($entry['lines'] as $index => $line) {
                if (!isset($reverse['lines'][$index]) || !Fx504CaseReadRepository::same($reverse['lines'][$index], JournalLineAmount::reversal($line))) $same = false;
            }
            if (!$same) {
                if (array_any($entry['lines'], static fn (array $line): bool => self::candidate($line['account_code']))) $findings[] = ['code' => 'securities_reversal_unproven', 'entry_id' => $id];
                continue;
            }
            $removed[$id] = $removed[$reverseId] = true;
        }
        $openings = [];
        $carryRead = new ClosingCarryReadRepository($this->db, $currentRead);
        $periods = $carryRead->periods($supplierId);
        foreach ($steps as $step) {
            if ($step['step_key'] !== 'open_next' || $step['status'] !== 'done') continue;
            $payload = json_decode((string) $step['payload'], true, 512, JSON_THROW_ON_ERROR);
            $carry = $payload['carry']['open_next'] ?? null;
            if (!is_array($carry) || ($carry['status'] ?? null) !== 'written') continue;
            $id = (int) ($carry['entry_id'] ?? 0);
            $entry = $entries[$id] ?? null;
            if (isset($removed[$id])) continue;
            if (!$entry || $entry['source_type'] !== 'opening' || $entry['posted_at'] === null || $entry['reversed_by'] !== null || (int) $entry['row_version'] !== (int) ($carry['entry_version'] ?? 0) || $entry['entry_date'] !== ($carry['entry_date'] ?? null) || (int) $entry['period_id'] !== (int) ($carry['target_period_id'] ?? 0)) {
                $findings[] = ['code' => 'securities_opening_unproven', 'entry_id' => $id];
                continue;
            }
            if (!$this->openingProof($supplierId, $step, $carry, $entry, $entries, $periods, $carryRead)) {
                $findings[] = ['code' => 'securities_opening_unproven', 'entry_id' => $id];
                continue;
            }
            $openings[$id] = true;
        }
        $anchor = null;
        foreach ($entries as $id => $entry) if ($entry['source_type'] === 'opening' && $entry['posted_at'] !== null && !isset($removed[$id]) && $entry['reversed_by'] === null && $entry['entry_date'] <= $period['ends_on']) $anchor = max($anchor ?? '', $entry['entry_date']);
        $history = $current = [];
        foreach ($rows as $row) {
            if (!in_array($row['account_type'], ['asset', 'liability', 'equity'], true) || $row['posted_at'] === null || $row['entry_date'] > $period['ends_on'] || isset($removed[(int) $row['entry_id']]) || $row['source_type'] === 'closing') continue;
            if (!isset($openings[(int) $row['entry_id']])) $history[] = $row;
            if ($anchor === null || $row['entry_date'] >= $anchor) $current[] = $row;
        }
        $cells = self::cells(array_values(array_filter($history, static fn (array $row): bool => self::candidate($row['account_code']))));
        $rates = ['CZK' => ['rate' => '1.000000', 'rate_date' => $period['ends_on']]];
        $from = (new \DateTimeImmutable($period['ends_on']))->modify('-7 days')->format('Y-m-d');
        foreach (array_unique(array_map(static fn (array $row): string => $row['currency_code'] ?? 'CZK', $history)) as $currency) {
            if ($currency === 'CZK') continue;
            $rate = $this->rateAt($currency, $period['ends_on'], $currentRead);
            if ($rate !== null) $rates[$currency] = $rate;
        }
        $proofEntries = [];
        foreach ($entries as $id => $entry) if (array_any($entry['lines'], static fn (array $line): bool => in_array($line['account_type'], ['asset', 'liability', 'equity'], true))) $proofEntries[$id] = $entry;
        $originRows = array_map(static fn (array $row): array => array_replace($row, ['currency_code' => $row['currency_code'] ?? 'CZK']), $history);
        $originSnapshot = (new FxCaseOriginReadRepository($this->db))->snapshot($supplierId, $originRows, $currentRead);
        $snapshot = ['origins' => $originSnapshot['origins'], 'origin_facts_hash' => $originSnapshot['facts_hash'], 'all_source_rows' => $history, 'current_source_rows' => $current, 'cells' => array_values($cells), 'entries' => $proofEntries, 'rates' => $rates, 'findings' => $findings,
            'opening_entry_ids' => array_keys($openings), 'cancelled_entry_ids' => array_keys($removed), 'as_of' => $period['ends_on'], 'profile_snapshot' => $profileSnapshot];
        $snapshot['debt_lifecycle'] = (new SecuritiesDebt504Repository($this->db,$currentRead))->closingProof($supplierId,$period['ends_on']);
        $hashSnapshot = $snapshot;
        $hashSnapshot['category_source_rows'] = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['account_type'], ['expense', 'revenue'], true)
            && $row['posted_at'] !== null && $row['entry_date'] <= $period['ends_on'] && $row['source_type'] !== 'closing'));
        $hashSnapshot['entries'] = array_filter($proofEntries, static fn (array $entry): bool => $entry['entry_date'] <= $period['ends_on']
            && !($entry['source_type'] === 'closing' && (int) $entry['period_id'] === (int) $period['id']));
        $snapshot['hash'] = hash('sha256', json_encode([$hashSnapshot, $steps], JSON_THROW_ON_ERROR));
        return $snapshot;
    }

    public static function select(array $snapshot, mixed $accountIds): array
    {
        if (!is_array($accountIds) || !array_is_list($accountIds) || array_any($accountIds, static fn ($id): bool => !is_int($id) || $id <= 0) || count(array_unique($accountIds)) !== count($accountIds)) throw new \DomainException('securities_inventory_selection_invalid');
        $available = [];
        foreach ($snapshot['all_source_rows'] as $row) $available[(int) $row['account_id']] = ['id' => (int) $row['account_id'], 'account_code' => $row['account_code'], 'account_type' => $row['account_type']];
        if (array_diff($accountIds, array_keys($available)) !== []) throw new \DomainException('securities_inventory_selection_invalid');
        $selected = static fn (array $row): bool => self::candidate($row['account_code']) || in_array((int) $row['account_id'], $accountIds, true);
        $snapshot['cells'] = array_values(self::cells(array_values(array_filter($snapshot['all_source_rows'], $selected))));
        $current = self::cells(array_values(array_filter($snapshot['current_source_rows'], $selected)));
        if (self::balances($snapshot['cells']) !== self::balances($current)) $snapshot['findings'][] = ['code' => 'securities_opening_membership'];
        foreach ($snapshot['cells'] as $cell) if (str_starts_with($cell['account_code'], '255')) { $snapshot['findings'][] = ['code' => 'securities_own_instrument_unsupported']; break; }
        $snapshot['available_accounts'] = array_values($available);
        return $snapshot;
    }

    public function rateAt(string $currency, string $date, bool $currentRead = false): ?array
    {
        $from = (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d');
        $rate = $this->rows('SELECT rate,rate_date FROM exchange_rates WHERE currency_code=? AND rate_date>=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1', [$currency, $from, $date], $currentRead);
        return $rate[0] ?? null;
    }

    public function profileSnapshot(int $supplierId, array $period, bool $currentRead = false): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Securities profile requires a caller transaction.');
        if ($currentRead) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $periods = $this->rows('SELECT id,starts_on,ends_on,fiscal_year FROM accounting_periods WHERE supplier_id=? AND starts_on<=? ORDER BY starts_on,id', [$supplierId, $period['ends_on']], $currentRead);
        $history = $this->rows('SELECT period_id,assets_net,net_turnover,avg_employees,raw_category FROM entity_category_history WHERE supplier_id=? ORDER BY period_id', [$supplierId], $currentRead);
        $constants = new TaxConstantsRepository($this->db);
        $thresholds = [];
        $years = array_unique(array_map(static fn (array $p): int => (int) substr($p['ends_on'], 0, 4), $periods));
        sort($years);
        foreach ($years as $year) {
            $effective = $constants->forYear($year, $currentRead);
            $thresholds[$year] = ['year' => $effective['year'], 'limits' => $effective['entity_category_thresholds'] ?? null];
        }
        return ['periods' => $periods, 'history' => $history, 'thresholds' => $thresholds];
    }

    private function openingProof(int $supplierId, array $step, array $carry, array $opening, array $entries, array $periods, ClosingCarryReadRepository $read): bool
    {
        $source = $periods[(int) $step['period_id']] ?? null;
        $target = $periods[(int) $opening['period_id']] ?? null;
        if (!$source || !$target || $target['starts_on'] !== (new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d')
            || $opening['entry_date'] !== $target['starts_on'] || !in_array($source['status'], ['closed', 'approved'], true)) return false;
        $closed = $read->step($supplierId, (int) $source['id'], 'close_books');
        $record = $closed['decoded_payload']['carry']['close_books'] ?? null;
        $closing = $entries[(int) ($record['entry_id'] ?? 0)] ?? null;
        if (($closed['status'] ?? null) !== 'done' || !is_array($record) || ($record['status'] ?? null) !== 'written'
            || !$closing || $closing['source_type'] !== 'closing' || (int) $closing['source_id'] !== (int) $source['id']
            || (int) $closing['period_id'] !== (int) $source['id'] || $closing['entry_date'] !== $source['ends_on']
            || $closing['posted_at'] === null || $closing['reversed_by'] !== null || (int) $closing['row_version'] !== (int) ($record['entry_version'] ?? 0)) return false;
        try {
            $profile = new AccountingRoleProfile('cz_504');
            $beforeClose = $read->snapshot($supplierId, $source, $source, 'close_books', $source['ends_on'], $profile);
            $beforeOpen = $read->snapshot($supplierId, $source, $target, 'open_next', $target['starts_on'], $profile);
        } catch (ClosingException) {
            return false;
        }
        // Dodatečný důkaz porovnává řádky jako AnnualOpeningReadProof a LegalClosing504Repository.
        // Otisk zdrojových řádků z okamžiku zápisu se po pozdějším stornu do otevřeného roku
        // (rozpuštění dohadu) legitimně mění, ačkoli uzavírací ani zahajovací řádky ne.
        return self::entryMatches($closing['lines'], $beforeClose['lines'])
            && self::entryMatches($opening['lines'], $beforeOpen['lines']);
    }

    private static function entryMatches(array $actual, array $expected): bool
    {
        if (count($actual) !== count($expected)) return false;
        foreach ($actual as $index => $line) if (!Fx504CaseReadRepository::same($line, $expected[$index])) return false;
        return true;
    }

    public static function cells(array $rows): array
    {
        $cells = [];
        foreach ($rows as $row) foreach (Fx504Builder::sourceCells($row) as $cell) {
            $key = hash('sha256', json_encode([(int) $row['id'], $cell['account_id'], $cell['currency_code'], $cell['dimensions'], $cell['cost_center'], $cell['project_id']], JSON_THROW_ON_ERROR));
            if (isset($cells[$key])) throw new \DomainException('Duplicate securities source cell.');
            $cells[$key] = $cell + ['cell_key' => $key, 'line_id' => (int) $row['id'], 'entry_id' => (int) $row['entry_id'],
                'line_no' => (int) $row['line_no'], 'entry_version' => (int) $row['entry_version'], 'entry_date' => $row['entry_date'],
                'book_czk' => Fx504Builder::decimal($cell['czk_cents']), 'book_foreign' => Fx504Builder::decimal($cell['foreign_cents']),
                'source_hash' => hash('sha256', json_encode([$row, $cell], JSON_THROW_ON_ERROR))];
        }
        ksort($cells);
        return $cells;
    }

    private static function balances(array $cells): array
    {
        $out = [];
        foreach ($cells as $cell) {
            $key = json_encode([$cell['account_id'], $cell['currency_code'], $cell['dimensions'], $cell['cost_center'], $cell['project_id']], JSON_THROW_ON_ERROR);
            $out[$key] ??= [0, 0];
            $out[$key][0] += $cell['czk_cents'];
            $out[$key][1] += $cell['foreign_cents'];
        }
        $out = array_filter($out, static fn (array $amount): bool => $amount !== [0, 0]);
        ksort($out);
        return $out;
    }

    private function rows(string $sql, array $params, bool $currentRead): array
    {
        $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
