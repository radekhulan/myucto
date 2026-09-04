<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use PDO;

final class AccountingHistoryReclassificationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AccountingSetupRepository $setup,
        private readonly PostingService $posting,
        private readonly ExpenseClassificationService $classification,
        private readonly SmallAssetService $smallAssets,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== 'accounting_history_reclassification' || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        $params = (array) ($job['params'] ?? []);
        try {
            if (!empty($params['rollback_of_job_id'])) {
                $this->rollback($jobId, $supplierId, (int) $params['rollback_of_job_id'], (int) $job['created_by']);
                return;
            }
            $bundle = $this->setup->findBundle($supplierId, (int) ($params['bundle_id'] ?? 0));
            if ($bundle === null) {
                throw new \RuntimeException('bundle_not_found');
            }
            $dryRun = !empty($params['dry_run']);
            $dateFrom = self::scopeDate($params['date_from'] ?? null);
            $dateTo = self::scopeDate($params['date_to'] ?? null);
            $scopeMode = self::scopeMode($params['scope_mode'] ?? 'matched');
            if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
                throw new \RuntimeException('invalid_date_range');
            }
            $dryItems = [];
            if (!$dryRun) {
                $dryJobId = (int) ($params['dry_run_job_id'] ?? 0);
                $dryJob = $this->jobs->find($dryJobId, $supplierId);
                if ($dryJob === null || $dryJob['source'] !== 'accounting_history_reclassification'
                    || !in_array($dryJob['status'], ['completed', 'completed_with_warnings'], true)
                    || empty(($dryJob['params'] ?? [])['dry_run'])
                    || (int) (($dryJob['params'] ?? [])['bundle_id'] ?? 0) !== (int) $bundle['id']
                    || (string) (($dryJob['params'] ?? [])['bundle_hash'] ?? '') !== (string) $bundle['bundle_hash']
                    || (string) (($dryJob['params'] ?? [])['input_hash'] ?? '') !== (string) $bundle['input_hash']
                    || self::scopeDate(($dryJob['params'] ?? [])['date_from'] ?? null) !== $dateFrom
                    || self::scopeDate(($dryJob['params'] ?? [])['date_to'] ?? null) !== $dateTo
                    || self::scopeMode(($dryJob['params'] ?? [])['scope_mode'] ?? 'matched') !== $scopeMode) {
                    throw new \RuntimeException('matching_dry_run_required');
                }
                foreach ($this->setup->reclassificationItems($supplierId, $dryJobId) as $item) {
                    $dryItems[(int) $item['purchase_invoice_id']] = $item;
                }
            }

            $overrides = $this->affectedInvoiceOverrides(
                $supplierId,
                (array) ($bundle['payload_json'] ?? []),
                $dateFrom,
                $dateTo,
                $scopeMode,
            );
            if (!$dryRun) {
                $expectedInvoiceIds = array_map('intval', array_keys(array_filter(
                    $dryItems,
                    static fn (array $item): bool => $item['status'] === 'would_change',
                )));
                if (array_diff($expectedInvoiceIds, array_map('intval', array_keys($overrides)))) {
                    throw new \RuntimeException('result_changed_after_dry_run');
                }
            }
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($overrides),
                'current_step' => $dryRun ? 'reclassification_dry_run' : 'reclassification_apply',
            ]);
            $changed = 0;
            $skipped = 0;
            $failed = 0;
            $processed = 0;
            foreach ($overrides as $invoiceId => $itemOverrides) {
                if ($this->jobs->isCancelRequested($jobId)) {
                    $this->jobs->markCancelled($jobId);
                    return;
                }
                try {
                    if ($this->hasVatAllocations($supplierId, $invoiceId)) {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'skipped', null, null, null, 'vat_allocations_present', 'Doklad má autoritativní rozdělení DPH a běžná pravidla položek jej nemění.');
                        $skipped++;
                        continue;
                    }
                    $before = $this->snapshot($supplierId, $invoiceId);
                    if ($before === null) {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'skipped', null, null, null, 'entry_not_found', 'Doklad nemá aktivní účetní zápis.');
                        $skipped++;
                        continue;
                    }
                    if ($before['period_status'] !== 'open' || $before['date_locked']) {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'skipped', $before, null, null, 'period_locked', 'Uzavřené nebo uzamčené období se nemění.');
                        $skipped++;
                        continue;
                    }
                    $calculatedLines = $this->posting->buildFromPurchaseInvoice($supplierId, $invoiceId, [
                        'item_classification_overrides' => $itemOverrides,
                    ]);
                    $desiredLines = self::mergeReclassifiedLines((array) $before['lines'], $calculatedLines);
                    $after = [
                        'entry_date' => $before['entry_date'],
                        'document_date' => $before['document_date'],
                        'document_no' => $before['document_no'],
                        'description' => $before['description'],
                        'lines' => self::normalizeLines($desiredLines),
                        'item_classifications' => self::applyClassificationOverrides(
                            (array) $before['item_classifications'],
                            $itemOverrides,
                        ),
                    ];
                    if (self::linesHash($before['lines']) === self::linesHash($after['lines'])
                        && self::classificationHash((array) $before['item_classifications'])
                            === self::classificationHash((array) $after['item_classifications'])) {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'unchanged', $before, $after, (int) $before['entry_id']);
                        $skipped++;
                        continue;
                    }
                    if ($dryRun) {
                        $before['journal_fingerprint'] = self::snapshotHash($before);
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'would_change', $before, $after, (int) $before['entry_id']);
                        $changed++;
                        continue;
                    }

                    $dryItem = $dryItems[$invoiceId] ?? null;
                    if ($dryItem === null || $dryItem['status'] !== 'would_change') {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'skipped', $before, $after, null, 'dry_run_item_missing', 'Doklad nebyl ve shodném dry-runu označen ke změně.');
                        $skipped++;
                        continue;
                    }
                    $dryBefore = (array) ($dryItem['before_json'] ?? []);
                    if (($dryBefore['journal_fingerprint'] ?? '') !== self::snapshotHash($before)) {
                        $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'skipped', $before, $after, null, 'document_changed_after_dry_run', 'Zápis se po dry-runu změnil. Spusťte novou kontrolu.');
                        $skipped++;
                        continue;
                    }
                    $entryId = $this->applyReplacement(
                        $jobId,
                        (int) $bundle['id'],
                        $supplierId,
                        $invoiceId,
                        $itemOverrides,
                        (string) $dryBefore['journal_fingerprint'],
                        (array) ($dryItem['after_json']['lines'] ?? []),
                        (array) ($dryItem['after_json']['item_classifications'] ?? []),
                        (int) $job['created_by'],
                    );
                    $changed++;
                } catch (\Throwable $e) {
                    $this->setup->addReclassificationItem($jobId, (int) $bundle['id'], $supplierId, $invoiceId, 'failed', null, null, null, 'reclassification_failed', $e->getMessage());
                    $failed++;
                } finally {
                    $processed++;
                    if ($processed % 25 === 0 || $processed === count($overrides)) {
                        $this->jobs->updateProgress($jobId, [
                            'processed' => $processed,
                            'created_count' => $changed,
                            'skipped_count' => $skipped,
                            'failed_count' => $failed,
                        ]);
                    }
                }
            }
            $this->jobs->updateProgress($jobId, ['current_step' => $dryRun ? 'dry_run_completed' : 'reclassification_completed']);
            if ($failed > 0 || $skipped > 0) {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function applyReplacement(
        int $jobId,
        int $bundleId,
        int $supplierId,
        int $invoiceId,
        array $itemOverrides,
        string $expectedBeforeHash,
        array $expectedAfterLines,
        array $expectedAfterClassifications,
        int $userId,
    ): int
    {
        $pdo = $this->db->pdo();
        $ownTransaction = self::beginDocumentTransaction($pdo);
        try {
            $before = $this->snapshot($supplierId, $invoiceId);
            if ($before === null || $before['period_status'] !== 'open' || $before['date_locked']) {
                throw new \RuntimeException('period_locked');
            }
            if (self::snapshotHash($before) !== $expectedBeforeHash) {
                throw new \RuntimeException('document_changed_after_dry_run');
            }
            $desiredClassifications = self::applyClassificationOverrides(
                (array) $before['item_classifications'],
                $itemOverrides,
            );
            if (self::classificationHash($desiredClassifications)
                !== self::classificationHash($expectedAfterClassifications)) {
                throw new \RuntimeException('result_changed_after_dry_run');
            }
            $calculatedLines = $this->posting->buildFromPurchaseInvoice($supplierId, $invoiceId, [
                'item_classification_overrides' => $itemOverrides,
            ]);
            $lines = self::mergeReclassifiedLines((array) $before['lines'], $calculatedLines);
            if (self::linesHash($lines) !== self::linesHash($expectedAfterLines)) {
                throw new \RuntimeException('result_changed_after_dry_run');
            }
            $entryId = $this->posting->postDocument($supplierId, 'purchase_invoice', $invoiceId, $lines, [
                'entry_date' => $before['entry_date'],
                'document_date' => $before['document_date'],
                'document_no' => $before['document_no'],
                'description' => $before['description'],
                'posted' => true,
                'posted_at' => $before['posted_at'],
                'posted_by' => $before['posted_by'],
                'user_id' => $userId,
                'expected_row_version' => $before['row_version'],
            ]);
            $lockedClassifications = $this->itemClassifications($supplierId, $invoiceId, true);
            if (self::classificationHash($lockedClassifications) !== self::classificationHash((array) $before['item_classifications'])) {
                throw new \RuntimeException('document_changed_after_dry_run');
            }
            $stmt = $pdo->prepare(
                'UPDATE purchase_invoice_items pii
                    JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
                    SET pii.expense_kind = ?, pii.expense_account_code = ?,
                        pii.is_fixed_asset = CASE WHEN ? = "fixed_asset" THEN 1 ELSE 0 END
                  WHERE pii.id = ? AND pii.purchase_invoice_id = ?'
            );
            foreach ($itemOverrides as $itemId => $override) {
                if (!isset($lockedClassifications[(int) $itemId])) {
                    throw new \RuntimeException('item_not_found');
                }
                $stmt->execute([
                    $supplierId,
                    $override['expense_kind'],
                    $override['expense_account_code'] ?: null,
                    $override['expense_kind'],
                    $itemId,
                    $invoiceId,
                ]);
            }
            $this->smallAssets->syncFromPurchaseInvoice($supplierId, $invoiceId, $userId);
            $actualAfter = $this->snapshot($supplierId, $invoiceId);
            if ($actualAfter === null) {
                throw new \RuntimeException('entry_not_found_after_apply');
            }
            $actualAfter['journal_fingerprint'] = self::snapshotHash($actualAfter);
            $this->setup->addReclassificationItem(
                $jobId,
                $bundleId,
                $supplierId,
                $invoiceId,
                'applied',
                $before,
                $actualAfter,
                $entryId,
            );
            self::commitDocumentTransaction($pdo, $ownTransaction);
            return $entryId;
        } catch (\Throwable $e) {
            self::rollBackDocumentTransaction($pdo, $ownTransaction);
            throw $e;
        }
    }

    private function rollback(int $jobId, int $supplierId, int $appliedJobId, int $userId): void
    {
        $appliedJob = $this->jobs->find($appliedJobId, $supplierId);
        if ($appliedJob === null || $appliedJob['source'] !== 'accounting_history_reclassification'
            || !in_array($appliedJob['status'], ['completed', 'completed_with_warnings'], true)
            || !array_key_exists('dry_run', (array) ($appliedJob['params'] ?? []))
            || ($appliedJob['params'] ?? [])['dry_run'] !== false
            || !empty(($appliedJob['params'] ?? [])['rollback_of_job_id'])) {
            throw new \RuntimeException('completed_apply_job_required');
        }
        $items = array_values(array_filter(
            $this->setup->reclassificationItems($supplierId, $appliedJobId),
            static fn (array $item): bool => $item['status'] === 'applied',
        ));
        if ($items === [] || array_filter($items, static fn (array $item): bool => $item['before_json'] === null)) {
            throw new \RuntimeException('rollback_snapshot_missing');
        }
        $bundleId = (int) ($items[0]['bundle_id'] ?? 0);
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'rollback']);
        $done = 0;
        $failed = 0;
        foreach ($items as $item) {
            $invoiceId = (int) $item['purchase_invoice_id'];
            $before = (array) ($item['before_json'] ?? []);
            try {
                $pdo = $this->db->pdo();
                $ownTransaction = self::beginDocumentTransaction($pdo);
                try {
                    $current = $this->snapshot($supplierId, $invoiceId);
                    if ($current === null || $current['period_status'] !== 'open' || $current['date_locked']) {
                        throw new \RuntimeException('period_locked');
                    }
                    $appliedSnapshot = (array) ($item['after_json'] ?? []);
                    if ((int) ($current['entry_id'] ?? 0) !== (int) ($item['correction_entry_id'] ?? 0)
                        || ($appliedSnapshot['journal_fingerprint'] ?? '') !== self::snapshotHash($current)) {
                        throw new \RuntimeException('document_changed_after_apply');
                    }
                    $entryId = $this->posting->postDocument($supplierId, 'purchase_invoice', $invoiceId, (array) ($before['lines'] ?? []), [
                        'entry_date' => $before['entry_date'],
                        'document_date' => $before['document_date'],
                        'document_no' => $before['document_no'],
                        'description' => $before['description'],
                        'posted' => true,
                        'posted_at' => $before['posted_at'],
                        'posted_by' => $before['posted_by'],
                        'user_id' => $userId,
                        'expected_row_version' => $current['row_version'],
                    ]);
                    $lockedClassifications = $this->itemClassifications($supplierId, $invoiceId, true);
                    if (self::classificationHash($lockedClassifications)
                        !== self::classificationHash((array) ($appliedSnapshot['item_classifications'] ?? []))) {
                        throw new \RuntimeException('document_changed_after_apply');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
                            SET pii.expense_kind = ?, pii.expense_account_code = ?, pii.is_fixed_asset = ?
                          WHERE pii.id = ? AND pii.purchase_invoice_id = ?'
                    );
                    foreach ((array) ($before['item_classifications'] ?? []) as $itemId => $classification) {
                        if (!isset($lockedClassifications[(int) $itemId])) {
                            throw new \RuntimeException('item_not_found');
                        }
                        $stmt->execute([
                            $supplierId,
                            $classification['expense_kind'] ?? null,
                            $classification['expense_account_code'] ?? null,
                            !empty($classification['is_fixed_asset']) ? 1 : 0,
                            $itemId,
                            $invoiceId,
                        ]);
                    }
                    $this->smallAssets->syncFromPurchaseInvoice($supplierId, $invoiceId, $userId);
                    $actualAfter = $this->snapshot($supplierId, $invoiceId);
                    if ($actualAfter === null) {
                        throw new \RuntimeException('entry_not_found_after_rollback');
                    }
                    $actualAfter['journal_fingerprint'] = self::snapshotHash($actualAfter);
                    $this->setup->addReclassificationItem($jobId, $bundleId, $supplierId, $invoiceId, 'applied', $current, $actualAfter, $entryId);
                    self::commitDocumentTransaction($pdo, $ownTransaction);
                    $done++;
                } catch (\Throwable $e) {
                    self::rollBackDocumentTransaction($pdo, $ownTransaction);
                    throw $e;
                }
            } catch (\Throwable $e) {
                $this->setup->addReclassificationItem($jobId, $bundleId, $supplierId, $invoiceId, 'failed', null, null, null, 'rollback_failed', $e->getMessage());
                $failed++;
            }
            $this->jobs->updateProgress($jobId, ['processed' => $done + $failed, 'created_count' => $done, 'failed_count' => $failed]);
        }
        $this->jobs->updateProgress($jobId, ['current_step' => 'rollback_completed']);
        $failed > 0 ? $this->jobs->markCompletedWithWarnings($jobId) : $this->jobs->markCompleted($jobId);
    }

    /** @return array<int,array<int,array{expense_kind:string,expense_account_code:?string}>> */
    private function affectedInvoiceOverrides(
        int $supplierId,
        array $payload,
        ?string $dateFrom,
        ?string $dateTo,
        string $scopeMode,
    ): array
    {
        $rules = [];
        $assetItems = [];
        foreach ($payload as $item) {
            $proposal = (array) ($item['proposal'] ?? []);
            if (($item['type'] ?? '') === 'expense_rule') {
                $rules[] = $proposal;
            } elseif (($item['type'] ?? '') === 'asset_candidate' && !empty($proposal['item_id'])) {
                $assetItems[(int) $proposal['item_id']] = $proposal;
            }
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.unit_price_without_vat,
                    pi.vendor_id, pi.exchange_rate, YEAR(COALESCE(pi.tax_date, pi.issue_date)) acq_year,
                    c.company_name vendor_name
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
               WHERE pi.supplier_id = ?
                 AND pi.status NOT IN (\'draft\', \'cancelled\')
                 AND pi.document_kind NOT IN (\'advance\', \'tax_document\')
                 AND (? IS NULL OR COALESCE(pi.tax_date, pi.issue_date) >= ?)
                 AND (? IS NULL OR COALESCE(pi.tax_date, pi.issue_date) <= ?)
               ORDER BY pii.id'
        );
        $stmt->execute([$supplierId, $dateFrom, $dateFrom, $dateTo, $dateTo]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoiceId = (int) $row['purchase_invoice_id'];
            if ($scopeMode === 'all') {
                $out[$invoiceId] ??= [];
            }
            $proposal = $assetItems[(int) $row['id']] ?? null;
            if ($proposal === null) {
                $rate = (float) ($row['exchange_rate'] ?? 0);
                $suggestion = $this->classification->suggestFromRules(
                    $supplierId,
                    (string) $row['description'],
                    $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
                    $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
                    abs((float) $row['unit_price_without_vat']) * ($rate > 0 ? $rate : 1.0),
                    (int) $row['acq_year'],
                    $rules,
                );
                if ($suggestion !== null) {
                    $proposal = [
                        'expense_kind' => $suggestion->kind->value,
                        'target_account_code' => $suggestion->accountCode,
                    ];
                }
            }
            if ($proposal === null || empty($proposal['expense_kind'])) {
                continue;
            }
            $out[$invoiceId][(int) $row['id']] = [
                'expense_kind' => (string) $proposal['expense_kind'],
                'expense_account_code' => ($proposal['target_account_code'] ?? null) ?: null,
            ];
        }
        return $out;
    }

    private static function scopeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException('invalid_date_range');
        }
        return $date->format('Y-m-d');
    }

    private static function scopeMode(mixed $value): string
    {
        $mode = (string) $value;
        if (!in_array($mode, ['matched', 'all'], true)) {
            throw new \RuntimeException('invalid_scope_mode');
        }
        return $mode;
    }

    private function snapshot(int $supplierId, int $invoiceId, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id entry_id, je.entry_date, je.document_date, je.document_no, je.description,
                    je.posted_at, je.posted_by, je.row_version,
                    ap.status period_status,
                    CASE WHEN aset.locked_until IS NOT NULL AND je.entry_date <= aset.locked_until THEN 1 ELSE 0 END date_locked
               FROM journal_entries je
               JOIN accounting_periods ap ON ap.id = je.period_id AND ap.supplier_id = je.supplier_id
               LEFT JOIN accounting_supplier_settings aset ON aset.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = "purchase_invoice" AND je.source_id = ?
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
              ORDER BY je.id DESC LIMIT 1' . $lock
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return null;
        }
        $lineStmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code, jel.side, jel.amount, jel.currency_code, jel.fx_rate,
                    jel.amount_foreign, jel.cost_center, jel.project_id
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ? ORDER BY jel.line_no, jel.id'
        );
        $lineStmt->execute([(int) $header['entry_id'], $supplierId]);
        return [
            'entry_id' => (int) $header['entry_id'],
            'entry_date' => (string) $header['entry_date'],
            'document_date' => $header['document_date'],
            'document_no' => $header['document_no'],
            'description' => $header['description'],
            'posted_at' => $header['posted_at'],
            'posted_by' => $header['posted_by'] === null ? null : (int) $header['posted_by'],
            'row_version' => (int) $header['row_version'],
            'period_status' => (string) $header['period_status'],
            'date_locked' => (bool) $header['date_locked'],
            'lines' => self::normalizeLines($lineStmt->fetchAll(PDO::FETCH_ASSOC)),
            'item_classifications' => $this->itemClassifications($supplierId, $invoiceId),
        ];
    }

    /** @return array<int,array{expense_kind:?string,expense_account_code:?string,is_fixed_asset:bool}> */
    private function itemClassifications(int $supplierId, int $invoiceId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.expense_kind, pii.expense_account_code, pii.is_fixed_asset
               FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ? ORDER BY pii.id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $classifications = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $classifications[(int) $item['id']] = [
                'expense_kind' => $item['expense_kind'] === null ? null : (string) $item['expense_kind'],
                'expense_account_code' => $item['expense_account_code'] === null ? null : (string) $item['expense_account_code'],
                'is_fixed_asset' => (bool) $item['is_fixed_asset'],
            ];
        }
        return $classifications;
    }

    private function hasVatAllocations(int $supplierId, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoice_vat_allocations piva
              JOIN purchase_invoices pi ON pi.id = piva.purchase_invoice_id AND pi.supplier_id = ?
             WHERE piva.purchase_invoice_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function normalizeLines(array $lines): array
    {
        return array_map(static function (array $line): array {
            $normalized = [
                'account_code' => (string) $line['account_code'],
                'side' => (string) $line['side'],
                'amount' => round((float) $line['amount'], 2),
                'cost_center' => ($line['cost_center'] ?? null) ?: null,
                'project_id' => isset($line['project_id']) ? (int) $line['project_id'] : null,
            ];
            if (($line['currency_code'] ?? null) !== null) {
                $normalized['currency_code'] = (string) $line['currency_code'];
                $normalized['fx_rate'] = $line['fx_rate'] === null ? null : (float) $line['fx_rate'];
                $normalized['amount_foreign'] = $line['amount_foreign'] === null ? null : (float) $line['amount_foreign'];
            }
            return $normalized;
        }, $lines);
    }

    private static function mergeReclassifiedLines(array $beforeLines, array $calculatedLines): array
    {
        $preserved = array_values(array_filter(
            self::normalizeLines($beforeLines),
            static fn (array $line): bool => !self::isReclassifiableAccount((string) $line['account_code']),
        ));
        $replacement = array_values(array_filter(
            self::normalizeLines($calculatedLines),
            static fn (array $line): bool => self::isReclassifiableAccount((string) $line['account_code']),
        ));
        $merged = [...$preserved, ...$replacement];
        $debit = 0;
        $credit = 0;
        foreach ($merged as $line) {
            $cents = (int) round((float) $line['amount'] * 100);
            $line['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        if ($debit !== $credit) {
            throw new \RuntimeException('unsafe_total_mismatch');
        }
        return $merged;
    }

    private static function isReclassifiableAccount(string $accountCode): bool
    {
        $normalized = str_replace('.', '', $accountCode);
        return str_starts_with($normalized, '5') || str_starts_with($normalized, '04');
    }

    private static function linesHash(array $lines): string
    {
        $lines = self::normalizeLines($lines);
        usort($lines, static fn (array $a, array $b): int => implode('|', $a) <=> implode('|', $b));
        return hash('sha256', json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function snapshotHash(array $snapshot): string
    {
        return hash('sha256', implode('|', [
            $snapshot['entry_id'] ?? '', $snapshot['row_version'] ?? '', $snapshot['entry_date'] ?? '',
            $snapshot['posted_at'] ?? '', $snapshot['posted_by'] ?? '', self::linesHash((array) ($snapshot['lines'] ?? [])),
            hash('sha256', json_encode($snapshot['item_classifications'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]));
    }

    private static function classificationHash(array $classifications): string
    {
        ksort($classifications, SORT_NUMERIC);
        return hash('sha256', json_encode($classifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function applyClassificationOverrides(array $current, array $overrides): array
    {
        foreach ($overrides as $itemId => $override) {
            if (!isset($current[(int) $itemId])) {
                continue;
            }
            $current[(int) $itemId] = [
                'expense_kind' => (string) $override['expense_kind'],
                'expense_account_code' => ($override['expense_account_code'] ?? null) ?: null,
                'is_fixed_asset' => (string) $override['expense_kind'] === 'fixed_asset',
            ];
        }
        return $current;
    }

    private static function beginDocumentTransaction(PDO $pdo): bool
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            return true;
        }
        $pdo->exec('SAVEPOINT accounting_setup_reclassification');
        return false;
    }

    private static function commitDocumentTransaction(PDO $pdo, bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT accounting_setup_reclassification');
    }

    private static function rollBackDocumentTransaction(PDO $pdo, bool $ownTransaction): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($ownTransaction) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT accounting_setup_reclassification');
        $pdo->exec('RELEASE SAVEPOINT accounting_setup_reclassification');
    }
}
