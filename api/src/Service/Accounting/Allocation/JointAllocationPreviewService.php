<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JointAllocationKeyRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use PDO;

final class JointAllocationPreviewService
{
    public function __construct(private readonly Connection $db) {}

    public function preview(int $supplierId, int $versionId, string $targetDate, array $sourceLineIds, array $exactCells = []): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(fn (): array => $this->previewLocked($supplierId, $versionId, $targetDate, $sourceLineIds, $exactCells));
    }

    public function previewLocked(int $supplierId, int $versionId, string $targetDate, array $sourceLineIds, array $exactCells = []): array
    {
        return $this->buildLocked($supplierId, $versionId, $targetDate, $sourceLineIds, null, $exactCells);
    }

    public function forPendingBatchLocked(int $supplierId, int $batchId): array
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM joint_allocation_batches WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch || $batch['journal_entry_id'] !== null) {
            throw new JointAllocationException('allocation_changed', 'Alokace již není připravena k prvnímu zaúčtování.', 409);
        }
        $stored = JointAllocationKeyVersion::decodeSnapshot($batch);
        $ids = array_map('intval', array_column($stored['source_lines'], 'id'));
        $current = $this->buildLocked($supplierId, (int) $batch['key_version_id'], $batch['target_date'], $ids, $batchId, $stored['exact_cells'] ?? []);
        if (!hash_equals($batch['preview_hash'], $current['preview_hash'])) {
            throw new JointAllocationException('allocation_preview_stale', 'Aktuální podklady již neodpovídají uloženému náhledu.', 409);
        }
        return ['batch' => $batch, 'preview' => $current];
    }

    private function buildLocked(int $supplierId, int $versionId, string $targetDate, array $sourceLineIds, ?int $ownedBatchId, array $exactCells): array
    {
        JointAllocationKeyVersion::date($targetDate);
        $sourceLineIds = JointAllocationRequest::sourceIds($sourceLineIds);
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertAllocationSourcesMutable($supplierId, $sourceLineIds);
        $period = $this->targetPeriod($supplierId, $targetDate);
        $keys = new JointAllocationKeyRepository($this->db);
        $version = $keys->findForUpdate($supplierId, $versionId);
        JointAllocationKeyVersion::assertDate($version, $targetDate);
        $typeIds = $keys->validateCurrent($supplierId, $version);
        $sources = $this->sources($supplierId, $sourceLineIds, $targetDate);
        $uses = new JointAllocationUseGuard($this->db);
        if ($ownedBatchId === null) {
            $uses->assertLinesMutable($supplierId, $sourceLineIds);
        } else {
            $uses->assertOwnedSources($supplierId, $ownedBatchId, $sourceLineIds);
        }
        $plan = JointAllocationPlanBuilder::build($sources, $version['cells'], $typeIds, $exactCells, $version['allocation_mode']);
        $header = ['supplier_id' => $supplierId, 'period_id' => (int) $period['id'], 'entry_date' => $targetDate, 'source_type' => 'manual'];
        $targets = [];
        foreach ($plan['sources'] as $source) {
            array_push($targets, ...$source['targets']);
        }
        (new SystemDimensionWritePolicy($this->db))->assertJournal($header, $targets);
        $snapshot = [
            'supplier_id' => $supplierId, 'key_version_id' => $versionId, 'target_date' => $targetDate,
            'period_id' => (int) $period['id'], 'key' => $version, 'source_lines' => $sources,
            'exact_cells' => JointAllocationExactCells::normalize($exactCells), 'type_ids' => $typeIds, 'semantic_values' => $this->semanticValues($supplierId, $version['cells']), 'plan' => $plan,
        ];
        return $snapshot + ['preview_hash' => JointAllocationKeyVersion::hash($snapshot)];
    }

    private function semanticValues(int $supplierId, array $cells): array
    {
        $ids = [];
        foreach ($cells as $cell) {
            foreach (JointAllocationCalculator::AXES as $axis) $ids[$cell[$axis]] = $cell[$axis];
            foreach ($cell['additional_dimensions'] ?? [] as $valueId) $ids[$valueId] = $valueId;
        }
        sort($ids, SORT_NUMERIC);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT v.id, v.type_id, t.system_key, v.semantic_key, v.code, v.name
            FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id
            JOIN supplier s ON s.id = ?
            WHERE (v.supplier_id = s.id OR v.supplier_group_id = s.supplier_group_id)
              AND (t.supplier_id = s.id OR t.supplier_group_id = s.supplier_group_id)
              AND v.id IN ({$marks}) ORDER BY v.id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }

    public function targetPeriod(int $supplierId, string $date): array
    {
        JointAllocationKeyVersion::date($date);
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        if ((new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504) {
            throw new JointAllocationException('allocation_framework_unsupported', 'Datum přeúčtování musí patřit do účetního rámce 504.');
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on, id FOR UPDATE');
        $stmt->execute([$supplierId, $date, $date]);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($periods) !== 1 || $periods[0]['status'] !== 'open') {
            throw new JointAllocationException('period_not_open', 'Datum přeúčtování musí patřit do jediného otevřeného a nezamčeného období.', 409);
        }
        $period = $periods[0];
        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        if (is_string($lockedUntil) && $date <= $lockedUntil) {
            throw new JointAllocationException('date_locked', 'Datum přeúčtování je zamčené.', 409);
        }
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'closing' AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1 FOR UPDATE");
        $stmt->execute([$supplierId, $period['id']]);
        if ($stmt->fetchColumn() !== false) {
            throw new JointAllocationException('period_not_open', 'Období již obsahuje účinný závěrkový zápis.', 409);
        }
        return $period;
    }

    private function sources(int $supplierId, array $ids, string $targetDate): array
    {
        $pdo = $this->db->pdo();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT l.*, e.entry_date AS source_date, e.period_id AS source_period_id, e.source_type,
                e.source_id, e.document_no, e.description AS entry_description, a.account_code, a.name AS account_name,
                e.posted_at, e.reversed_by, e.row_version AS source_version, a.account_type
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.id IN ({$marks}) ORDER BY l.id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lines) !== count($ids)) {
            throw new JointAllocationException('allocation_source_not_found', 'Některý zdrojový řádek nepatří firmě nebo již neexistuje.', 404);
        }
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        foreach ($lines as $index => $line) {
            JointAllocationSourceEligibility::assert($line, $targetDate, $framework->forDateForUpdate($supplierId, $line['source_date']));
            $lines[$index]['id'] = (int) $line['id'];
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $maps = [
            'dimensions' => $assignments->lineDimensions($supplierId, $ids, true),
            'dimension_splits' => $assignments->lineSplits($supplierId, $ids, true),
            'dimension_origins' => $assignments->lineOrigins($supplierId, $ids, true),
            'dimension_split_origins' => $assignments->lineSplitOrigins($supplierId, $ids, true),
        ];
        foreach ($lines as &$line) {
            foreach ($maps as $field => $map) {
                $line[$field] = $map[$line['id']] ?? [];
            }
        }
        unset($line);
        return $lines;
    }
}
