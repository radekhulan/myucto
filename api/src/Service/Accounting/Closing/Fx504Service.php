<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\Fx504ReadRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use PDO;

final class Fx504Service
{
    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly DocumentSeriesService $series,
        private readonly ClosingRepository $closing,
        private readonly PostingService $posting,
        private readonly ActivityLogger $activity,
    ) {}

    public function handles(int $supplierId, int $periodId, bool $currentRead = false): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $currentRead): bool {
            if ($currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $stmt = $this->db->pdo()->prepare('SELECT ends_on FROM accounting_periods WHERE supplier_id = ? AND id = ?' . ($currentRead ? ' FOR UPDATE' : ''));
            $stmt->execute([$supplierId, $periodId]);
            $date = $stmt->fetchColumn();
            if (!$date) throw new ClosingException('not_found', 'Účetní období neexistuje.', 404);
            $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
            return ($currentRead ? $framework->forDateForUpdate($supplierId, $date) : $framework->forDate($supplierId, $date)) === AccountingFrameworkService::CZ_504;
        });
    }

    public function preview(int $supplierId, int $periodId, ?array $accountDecisions = null, bool $currentRead = false, ?array $caseDecisions = null, ?array $securitiesEvidence = null): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $accountDecisions, $currentRead, $caseDecisions, $securitiesEvidence): array {
            $snapshot = (new Fx504ReadRepository($this->db))->snapshot($supplierId, $periodId, $currentRead);
            $this->assertFramework($supplierId, $snapshot['period'], $currentRead);
            $stmt = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation'" . ($currentRead ? ' FOR UPDATE' : ''));
            $stmt->execute([$supplierId, $periodId]);
            $stored = $stmt->fetchColumn();
            $saved = !is_string($stored) || $stored === '' ? null : (json_decode($stored, true, 512, JSON_THROW_ON_ERROR)['fx504'] ?? null);
            if (!is_array($saved) || ($saved['snapshot_hash'] ?? null) !== $snapshot['snapshot_hash']) $saved = null;
            $accountDecisions ??= $saved['account_decisions'] ?? [];
            $caseDecisions ??= $saved['case_decisions'] ?? null;
            $securitiesEvidence ??= $saved['securities_evidence'] ?? null;
            $preview = SecuritiesValuation504Evidence::preview($snapshot, $accountDecisions, $caseDecisions, $securitiesEvidence);
            return $preview + ['account_decisions' => $accountDecisions, 'saved_decisions' => $saved['decisions'] ?? []]
                + array_intersect_key($snapshot, array_flip(['snapshot_hash', 'required_types', 'dimension_types', 'dimension_values', 'rate_info']));
        });
    }

    public function run(int $supplierId, int $periodId, array $body, int $rowVersion, array $meta = []): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $body, $rowVersion, $meta): array {
            $snapshot = (new Fx504ReadRepository($this->db))->snapshot($supplierId, $periodId, true);
            $period = $snapshot['period'];
            $this->assertFramework($supplierId, $period);
            if ($period['status'] !== 'closing') throw new ClosingException('invalid_status', 'Přecenění vyžaduje uzavírané účetní období.', 422);
            if ((int) $period['row_version'] !== $rowVersion) throw new ClosingException('version_conflict', 'Účetní období se změnilo. Načtěte stav znovu.', 409);
            if (!is_string($body['snapshot_hash'] ?? null) || !hash_equals($snapshot['snapshot_hash'], $body['snapshot_hash'])) throw Fx504JournalProof::changed();
            if (!is_array($body['account_decisions'] ?? []) || !is_array($body['decisions'] ?? []) || !is_array($body['case_decisions'] ?? [])) throw Fx504JournalProof::changed();
            if (isset($body['securities_evidence']) && !is_array($body['securities_evidence'])) throw Fx504JournalProof::changed();
            $preview = SecuritiesValuation504Evidence::preview($snapshot, $body['account_decisions'] ?? [], $body['case_decisions'] ?? null, $body['securities_evidence'] ?? null);
            $built = Fx504Builder::build($preview, $body['decisions'] ?? [], $snapshot['required_types']);
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->beginRefresh($supplierId, $periodId);
            $record = ['expected_version' => $rowVersion, 'snapshot_hash' => $snapshot['snapshot_hash'],
                'account_decisions' => $body['account_decisions'] ?? [], 'decisions' => $body['decisions'] ?? [],
                'securities_evidence' => $body['securities_evidence'] ?? null, 'securities_inventory_hash' => $snapshot['securities_snapshot']['hash'],
                'securities_assessment' => $preview['securities'],
                'case_projection_version' => 1, 'case_decisions' => $preview['case_decisions'], 'case_source_hash' => $snapshot['case_snapshot']['hash'],
                'case_materialization' => $built['case_materialization'], 'case_materialization_hash' => hash('sha256', json_encode($built['case_materialization'], JSON_THROW_ON_ERROR)),
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $meta['posted_by'] ?? $meta['user_id'] ?? null, 'slots' => []];
            foreach (['saldo' => ClosingSourceId::fxSaldo($periodId), 'bank' => ClosingSourceId::fxBank($periodId)] as $slot => $sourceId) {
                $existing = $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', $sourceId);
                if ($built[$slot] === [] && $existing !== null) {
                    $this->posting->reverse($supplierId, (int) $existing['id'], ['entry_date' => $period['ends_on'], 'allow_closing_period' => true] + $meta);
                    $existing = null;
                }
                $record['slots'][$slot] = ['status' => 'pending', 'previous_entry_id' => $existing === null ? null : (int) $existing['id'],
                    'previous_entry_version' => $existing === null ? null : (int) $existing['row_version'],
                    'document_no' => $existing['document_no'] ?? $this->series->next($supplierId, 'fx', (int) $period['fiscal_year'])];
            }
            $this->closing->upsertStep($supplierId, $periodId, 'fx_revaluation', 'pending', ['fx504' => $record, 'preview' => $preview], null, $meta['user_id'] ?? null);
            $step = $this->db->pdo()->prepare("SELECT id FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'fx_revaluation' FOR UPDATE");
            $step->execute([$supplierId, $periodId]);
            $stepId = (int) $step->fetchColumn();
            $ids = [];
            foreach (['saldo', 'bank'] as $slot) $ids[$slot] = $this->journal->upsertCurrentFx504($supplierId, $stepId, $slot, $rowVersion);
            $done = $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET status = 'done', done_at = CURRENT_TIMESTAMP, done_by = ? WHERE supplier_id = ? AND id = ? AND status = 'pending'");
            $done->execute([$meta['user_id'] ?? null, $supplierId, $stepId]);
            if ($done->rowCount() !== 1) throw Fx504JournalProof::changed();
            $bump = $this->db->pdo()->prepare('UPDATE accounting_periods SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $bump->execute([$supplierId, $periodId, $rowVersion]);
            if ($bump->rowCount() !== 1) throw Fx504JournalProof::changed();
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($supplierId, $periodId);
            $result = ['entry_ids' => $ids, 'totals' => $preview['totals'], 'snapshot_hash' => $snapshot['snapshot_hash']];
            $this->audit($supplierId, 'accounting.fx_revalued', $periodId, $result + ['fx504' => $record], $meta);
            return $result;
        });
    }

    public function reverseAtOpen(int $supplierId, int $periodId, int $targetId, int $expectedVersion, array $meta = []): ?int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $targetId, $expectedVersion, $meta): ?int {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');
            $stmt->execute([$supplierId, $targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target || $target['status'] !== 'open') throw Fx504JournalProof::changed();
            $settings = $this->db->pdo()->prepare('SELECT fx_reversal_at_open FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');
            $settings->execute([$supplierId]);
            $enabled = $settings->fetchColumn();
            $original = $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($periodId));
            $existing = $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($periodId));
            if ($enabled === 0 || $enabled === '0' || $original === null) {
                if ($existing !== null) {
                    (new SecuritiesDebt504FxCarryRegistrar($this->db))->beginRefresh($supplierId, $periodId);
                    $this->posting->reverse($supplierId, (int) $existing['id'], ['entry_date' => $target['starts_on']] + $meta);
                    (new SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($supplierId, $periodId);
                }
                return null;
            }
            $stmt = $this->db->pdo()->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation' FOR UPDATE");
            $stmt->execute([$supplierId, $periodId]);
            $step = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$step || $step['status'] !== 'done') throw Fx504JournalProof::changed();
            $payload = json_decode($step['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload['fx504'] ?? null)) throw Fx504JournalProof::changed();
            $sourceLines = Fx504JournalProof::sourceLines($this->db, $this->journal, $supplierId, (int) $original['id']);
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->beginRefresh($supplierId, $periodId);
            $payload['fx504']['slots']['reversal'] = ['status' => 'pending', 'expected_version' => $expectedVersion, 'target_period_id' => $targetId,
                'source_entry_id' => (int) $original['id'], 'source_entry_version' => (int) $original['row_version'], 'source_hash' => Fx504JournalProof::sourceHash($sourceLines),
                'previous_entry_id' => $existing === null ? null : (int) $existing['id'], 'previous_entry_version' => $existing === null ? null : (int) $existing['row_version'],
                'document_no' => $existing['document_no'] ?? $this->series->next($supplierId, 'fx', (int) $target['fiscal_year']),
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $meta['posted_by'] ?? $meta['user_id'] ?? null];
            $stmt = $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET payload=? WHERE supplier_id=? AND id=? AND status='done' AND payload <=> ?");
            $stmt->execute([json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $supplierId, $step['id'], $step['payload']]);
            if ($stmt->rowCount() !== 1) throw Fx504JournalProof::changed();
            $entryId = $this->journal->upsertCurrentFx504($supplierId, (int) $step['id'], 'reversal', $expectedVersion);
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($supplierId, $periodId);
            $this->audit($supplierId, 'accounting.fx_opening_reversed', $periodId, ['entry_id' => $entryId, 'reversal' => $payload['fx504']['slots']['reversal']], $meta);
            return $entryId;
        });
    }

    public function cancelOpeningReversal(int $supplierId, int $periodId, array $meta = []): ?int
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $meta): ?int {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $entry = $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($periodId));
            if ($entry === null) return null;
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->beginRefresh($supplierId, $periodId);
            $result = $this->posting->reverse($supplierId, (int) $entry['id'], ['entry_date' => $entry['entry_date']] + $meta);
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($supplierId, $periodId);
            return $result;
        });
    }

    public function revert(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');
            $stmt->execute([$supplierId, $periodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period || $period['status'] !== 'closing' || (int) $period['row_version'] !== $rowVersion) throw Fx504JournalProof::changed();
            $this->assertFramework($supplierId, $period);
            $stmt = $this->db->pdo()->prepare("SELECT status FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='close_books' FOR UPDATE");
            $stmt->execute([$supplierId, $periodId]);
            if ($stmt->fetchColumn() === 'done' || $this->journal->findActiveBySourceForUpdate($supplierId, 'closing', $periodId) !== null
                || $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($periodId)) !== null) {
                throw new ClosingException('revert_order_violation', 'Nejprve zrušte otevření následujícího období a uzavření knih.', 422);
            }
            (new SecuritiesDebt504FxCarryRegistrar($this->db))->beginRefresh($supplierId, $periodId);
            $ids = [];
            foreach (['saldo' => ClosingSourceId::fxSaldo($periodId), 'bank' => ClosingSourceId::fxBank($periodId)] as $slot => $sourceId) {
                $entry = $this->journal->findActiveBySourceForUpdate($supplierId, 'fx_revaluation', $sourceId);
                if ($entry !== null) $ids[$slot] = $this->posting->reverse($supplierId, (int) $entry['id'], ['entry_date' => $period['ends_on'], 'allow_closing_period' => true] + $meta);
            }
            $this->closing->resetStep($supplierId, $periodId, 'fx_revaluation');
            $stmt = $this->db->pdo()->prepare('UPDATE accounting_periods SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?');
            $stmt->execute([$supplierId, $periodId, $rowVersion]);
            if ($stmt->rowCount() !== 1) throw Fx504JournalProof::changed();
            $result = ['step' => 'fx_revaluation', 'reversal_entry_ids' => $ids];
            $this->audit($supplierId, 'accounting.closing_step_reverted', $periodId, $result, $meta);
            return $result;
        });
    }

    private function audit(int $supplierId, string $action, int $periodId, array $payload, array $meta): void
    {
        $this->activity->log($action, $meta['user_id'] ?? null, 'accounting_period', $periodId, $payload,
            $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
    }

    private function assertFramework(int $supplierId, array $period, bool $currentRead = true): void
    {
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        foreach ($framework->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead) as $effective) {
            if ($effective !== AccountingFrameworkService::CZ_504) throw Fx504JournalProof::changed();
        }
    }
}
