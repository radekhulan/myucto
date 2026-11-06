<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\Closing\ClosingCurrency504Builder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\OpeningEntryExpectation;
use MyInvoice\Service\Accounting\Closing\ProfitDistribution504Builder;
use PDO;

final class ClosingCarryReadRepository
{
    public function __construct(private readonly Connection $db, private readonly bool $currentRead = true) {}

    public function periods(int $supplierId): array
    {
        if ($this->currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        elseif (!$this->db->pdo()->inTransaction()) throw new \LogicException('Carry preview requires a consistent read-only transaction.');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on, id' . $this->lock());
        $stmt->execute([$supplierId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }

    public function step(int $supplierId, int $periodId, string $step): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = ?' . $this->lock());
        $stmt->execute([$supplierId, $periodId, $step]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row['decoded_payload'] = json_decode((string) ($row['payload'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR) ?? [];
        return $row;
    }

    public function snapshot(int $supplierId, array $source, array $target, string $step, string $date, AccountingRoleProfile $profile, array $decision = []): array
    {
        $this->periods($supplierId);
        if ($step === 'close_books') {
            $rows = $this->rows($supplierId, 'e.entry_date <= ? AND NOT(e.source_type = ? AND e.period_id = ? AND e.source_id <=> ?)',
                [$source['ends_on'], 'closing', $source['id'], $source['id']]);
            $periods = $this->periods($supplierId);
            // LedgerReportRepository::openingAnchor semantics, derived from current locked rows rather than an RR snapshot.
            $anchor = null;
            foreach ($rows as $row) {
                if ($row['source_type'] === 'opening') {
                    $start = $periods[$row['period_id']]['starts_on'];
                    $anchor = $anchor === null || $start > $anchor ? $start : $anchor;
                }
            }
            $pl = array_values(array_filter($rows, static fn (array $row): bool => $row['entry_date'] >= $source['starts_on'] && in_array($row['account_type'], ['revenue', 'expense'], true)));
            $bs = array_values(array_filter($rows, static fn (array $row): bool => ($anchor === null || $row['entry_date'] >= $anchor)
                && in_array($row['account_type'], ['asset', 'liability', 'equity'], true) && !$profile->isTransientAccount($row['account_code'])));
            $built = (new ClosingEntryBuilder())->closingLines(self::balances($pl), ClosingCurrency504Builder::balances($bs), $profile);
        } elseif ($step === 'open_next') {
            $closed = $this->step($supplierId, (int) $source['id'], 'close_books');
            if (($closed['status'] ?? null) !== 'done') throw self::changed();
            $rows = $this->rows($supplierId, 'e.id = ? AND e.period_id = ? AND e.source_type = ? AND e.source_id = ? AND e.reversed_by IS NULL',
                [(int) ($closed['decoded_payload']['entry_id'] ?? 0), $source['id'], 'closing', $source['id']]);
            if ($rows === [] && (($closed['decoded_payload']['entry_id'] ?? null) !== null
                || ($closed['decoded_payload']['lines_count'] ?? null) !== 0
                || (float) ($closed['decoded_payload']['profit'] ?? 1) !== 0.0)) throw self::changed();
            $profit = 0;
            foreach ($rows as $row) {
                if (in_array($row['account_type'], ['revenue', 'expense'], true)) $profit += (int) round((float) $row['signed_amount'] * 100) * ($row['side'] === 'debit' ? 1 : -1);
            }
            if ($profit !== (int) round((float) ($closed['decoded_payload']['profit'] ?? 0) * 100)) throw self::changed();
            $built = ['lines' => OpeningEntryExpectation::fromClosingLines($rows, $profit / 100, $profile), 'profit' => $profit / 100];
        } else {
            throw self::changed();
        }
        return $built + ['source_hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'source_line_ids' => array_map('intval', array_column($rows, 'id'))];
    }

    public function entryLines(int $supplierId, int $entryId): array
    {
        return $this->rows($supplierId, 'e.id = ?', [$entryId]);
    }

    private static function balances(array $rows): array
    {
        $accounts = array_column($rows, null, 'account_code');
        return array_map(static fn (array $cell): array => ['account_id' => (int) $accounts[$cell['account_code']]['account_id'],
            'account_code' => $cell['account_code'], 'name' => $accounts[$cell['account_code']]['account_name'], 'bal' => -$cell['available_cents'] / 100]
            + array_intersect_key($cell, ['dimensions' => true, 'cost_center' => true, 'project_id' => true]), ProfitDistribution504Builder::cells($rows));
    }

    private function rows(int $supplierId, string $predicate, array $params): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.*, e.period_id, e.entry_date, e.source_type, e.source_id, e.row_version AS entry_version,
            e.document_date, e.posted_at, e.posted_by, e.reversed_by, e.document_no AS entry_document_no, e.description AS entry_description,
            a.account_code, a.account_type, a.name AS account_name
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE e.supplier_id=? AND e.posted_at IS NOT NULL AND {$predicate} ORDER BY e.id,l.id" . $this->lock());
        $stmt->execute([$supplierId, ...$params]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($rows, 'id');
        $assignments = new DimensionAssignmentRepository($this->db);
        $dims = $assignments->lineDimensions($supplierId, $ids, $this->currentRead);
        $splits = $assignments->lineSplits($supplierId, $ids, $this->currentRead);
        $origins = $assignments->lineOrigins($supplierId, $ids, $this->currentRead);
        $splitOrigins = $assignments->lineSplitOrigins($supplierId, $ids, $this->currentRead);
        foreach ($rows as &$row) {
            $row['dimensions'] = $dims[$row['id']] ?? [];
            $row['dimension_splits'] = $splits[$row['id']] ?? [];
            $row['dimension_origins'] = $origins[$row['id']] ?? [];
            $row['dimension_split_origins'] = $splitOrigins[$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    private function lock(): string
    {
        return $this->currentRead ? ' FOR UPDATE' : '';
    }

    public static function changed(): ClosingException
    {
        return new ClosingException('closing_carry_changed', 'Podklady přenosu historických dimenzí se změnily. Načtěte uzávěrku znovu.', 409);
    }
}
