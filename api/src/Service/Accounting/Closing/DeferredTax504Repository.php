<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{DimensionAssignmentRepository, JournalEntryRepository};
use PDO;

final class DeferredTax504Repository
{
    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal, private readonly bool $currentRead = true) {}

    public static function sourceHash(Connection $db, int $supplierId, int $periodId, bool $currentRead = false): string
    {
        if (\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAssessmentSource::pagedHistory($db, $supplierId, $periodId, $currentRead)) {
            // Nad stránkovanou historií J6 doloží podklady období otisk stránkovaného zdroje.
            $container = \MyInvoice\Bootstrap::buildContainer();
            $container->set(Connection::class, $db);
            return $container->get(\MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CurrentProviderFactory::class)
                ->fingerprint($supplierId, $periodId, $currentRead)->inputHash;
        }
        $frameworks = new \MyInvoice\Service\Accounting\AccountingFrameworkService($db, new \MyInvoice\Repository\AccountingFrameworkRepository($db));
        $sources = new \MyInvoice\Repository\NonprofitTaxSourceRepository($db, $frameworks,
            new \MyInvoice\Repository\JointAllocationReadRepository($db), new \MyInvoice\Repository\NonprofitTaxAssessmentRepository($db));
        return $sources->snapshot($supplierId, $periodId, $currentRead)['input_hash'];
    }

    public function hasApplicableDecision(int $supplierId, string $asOf): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.starts_on,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='deferred_tax' AND p.starts_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['deferred504_decisions'] ?? [] as $record) {
                if ($step['ends_on'] <= $asOf || ($record['body']['method']['first_adoption'] ?? false) === true) return true;
            }
        }
        return false;
    }

    public function state(int $supplierId, string $asOf): array
    {
        $rows = $this->rows($supplierId, $asOf);
        $read = new LegalClosing504Repository($this->db, $this->journal, $this->currentRead);
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.starts_on,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='deferred_tax' AND p.starts_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        $method = null;
        $decisions = [];
        $effectiveBody = null;
        $effectivePeriodId = null;
        $effectiveSourceHash = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['deferred504_decisions'] ?? [] as $record) {
                $assessment = DeferredTax504Builder::assessment($record['body']);
                if ($assessment !== ($record['assessment'] ?? null)) throw self::changed();
                $effectivePhase = $record['batches'] === [] && $step['ends_on'] <= $asOf ? 'closing' : null;
                foreach ($record['batches'] as $batch) {
                    $phase = $batch['phase'];
                    $date = $phase === 'opening' ? $step['starts_on'] : $step['ends_on'];
                    if ($date > $asOf) continue;
                    $id = (int) $batch['entry_id'];
                    $before = self::balances($rows, $date, $id);
                    $lines = DeferredTax504Builder::movements($assessment, $before, $phase);
                    if ($lines === []) throw self::changed();
                    if ($read->verifyRecordedJournal($supplierId, $id, (int) $step['period_id'], $date, $lines, $asOf)) $effectivePhase = $phase;
                }
                if ($step['ends_on'] <= $asOf && !in_array('closing', array_column($record['batches'], 'phase'), true)) $effectivePhase = 'closing';
                if ($step['ends_on'] <= $asOf || ($assessment['method']['first_adoption'] && $step['starts_on'] <= $asOf)) {
                    $method = $assessment['method'];
                    $decisions[] = $record + ['period_id' => (int) $step['period_id']];
                    if ($effectivePhase !== null) {
                        $effectivePeriodId = (int) $step['period_id'];
                        $effectiveSourceHash = $record['body']['source_hash'] ?? null;
                        $effectiveBody = ['method' => array_replace($assessment['method'], ['first_adoption' => false]),
                            'titles' => $record['body'][$effectivePhase === 'opening' ? 'opening_titles' : 'titles'] ?? []];
                        unset($effectiveBody['method']['first_adoption_reason']);
                    }
                }
            }
        }
        $known = array_fill_keys($read->verifiedSourceLineIds($supplierId), true);
        foreach ($rows as $row) {
            if (isset($known[(int) $row['id']])) continue;
            if (!$read->verifiedCarryEntry($supplierId, (int) $row['entry_id'])) throw self::changed();
            $known = array_fill_keys($read->verifiedSourceLineIds($supplierId), true);
        }
        if ($effectiveBody === null && $method !== null) {
            $effectiveBody = ['method' => array_replace($method, ['first_adoption' => false]), 'titles' => []];
            unset($effectiveBody['method']['first_adoption_reason']);
        }
        $effectiveAssessment = DeferredTax504Builder::assessment($effectiveBody ?? []);
        $balances = self::balances($rows, $asOf);
        $actual = $expected = [];
        foreach ($balances as $cell) $actual[$cell['kind'] . ':' . LegalClosing504Builder::cellKey($cell)] = $cell['balance_cents'];
        foreach ($effectiveAssessment['closing']['cells'] ?? [] as $key => $cell) $expected[$key] = $cell['balance_cents'];
        ksort($actual); ksort($expected);
        return ['method' => $method, 'balances' => $balances, 'decisions' => $decisions,
            'effective_period_id' => $effectivePeriodId, 'effective_body' => $effectiveBody, 'effective_assessment' => $effectiveAssessment, 'assessment_current' => $actual === $expected && ($effectivePeriodId === null || (is_string($effectiveSourceHash) && hash_equals($effectiveSourceHash, self::sourceHash($this->db, $supplierId, $effectivePeriodId, $this->currentRead)))),
            'source_line_ids' => array_map('intval', array_keys($known)), 'rows' => $rows];
    }

    public function rows(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT l.*,e.entry_date,e.period_id,a.account_code FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE e.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (a.account_code LIKE '349%' OR a.account_code='959.100' OR a.account_code LIKE '592%')
            ORDER BY e.entry_date,e.id,l.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $assignments = new DimensionAssignmentRepository($this->db);
        $ids = array_column($rows, 'id');
        $dimensions = $assignments->lineDimensions($supplierId, $ids, $this->currentRead);
        $splits = $assignments->lineSplits($supplierId, $ids, $this->currentRead);
        foreach ($rows as &$row) {
            $row['dimensions'] = $dimensions[$row['id']] ?? [];
            $row['dimension_splits'] = $splits[$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public static function balances(array $rows, string $asOf, ?int $beforeEntryId = null): array
    {
        $out = [];
        foreach (['asset' => '349', 'liability' => '959.100'] as $kind => $code) {
            $selected = array_values(array_filter($rows, static fn (array $row): bool => $row['account_code'] === $code && $row['entry_date'] <= $asOf
                && ($beforeEntryId === null || $row['entry_date'] < $asOf || (int) $row['entry_id'] < $beforeEntryId)));
            foreach (ProfitDistribution504Builder::cells($selected) as $cell) {
                $cents = ($kind === 'asset' ? -1 : 1) * $cell['available_cents'];
                if ($cents < 0) throw self::changed();
                if ($cents === 0) continue;
                $out[] = ['kind' => $kind, 'balance_cents' => $cents] + array_intersect_key($cell, array_flip(['dimensions', 'cost_center', 'project_id']));
            }
        }
        return $out;
    }

    private function lock(): string
    {
        if ($this->currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Deferred tax current reads require caller period-first locks.');
        return $this->currentRead ? ' FOR UPDATE' : '';
    }

    public static function changed(): ClosingException
    {
        return new ClosingException('deferred_tax_source_changed', 'Zůstatky a historie odložené daně neodpovídají doložené metodě. Obnovte podklady a zkontrolujte navazující zápisy.', 409);
    }
}
