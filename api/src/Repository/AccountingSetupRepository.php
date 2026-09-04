<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AccountingSetupRepository
{
    public function __construct(private readonly Connection $db) {}

    public function createRun(int $supplierId, int $jobId, array $scope, int $catalogVersion, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO accounting_setup_runs (supplier_id, job_id, scope_json, catalog_version, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $jobId, self::json($scope), $catalogVersion, $userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function runByJob(int $jobId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_setup_runs WHERE job_id = ?');
        $stmt->execute([$jobId]);
        return $this->decode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findRun(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.*, b.id bundle_id, b.bundle_hash, j.status job_status, j.total_items, j.processed, j.created_count,
                    j.skipped_count, j.failed_count, j.current_step, j.last_error, j.log_text
               FROM accounting_setup_runs r JOIN import_jobs j ON j.id = r.job_id AND j.supplier_id = r.supplier_id
               LEFT JOIN accounting_setup_rule_bundles b ON b.run_id = r.id AND b.supplier_id = r.supplier_id
              WHERE r.id = ? AND r.supplier_id = ?'
        );
        $stmt->execute([$runId, $supplierId]);
        return $this->decode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    /** @return list<array<string,mixed>> */
    public function listRuns(int $supplierId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.*, b.id bundle_id, b.bundle_hash, j.status job_status, j.total_items, j.processed, j.created_count,
                    j.skipped_count, j.failed_count, j.current_step, j.last_error
               FROM accounting_setup_runs r JOIN import_jobs j ON j.id = r.job_id AND j.supplier_id = r.supplier_id
               LEFT JOIN accounting_setup_rule_bundles b ON b.run_id = r.id AND b.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? ORDER BY r.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $row): array => $this->decode($row) ?? [], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function completeRun(int $runId, string $inputHash, string $chartHash, string $rulesHash, array $summary): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounting_setup_runs
                SET input_hash = ?, chart_hash = ?, rules_hash = ?, summary_json = ?, completed_at = NOW()
              WHERE id = ?'
        )->execute([$inputHash, $chartHash, $rulesHash, self::json($summary), $runId]);
    }

    public function addProposal(
        int $runId,
        int $supplierId,
        string $type,
        string $signature,
        string $title,
        float $confidence,
        int $count,
        float $amount,
        array $proposal,
        array $evidence,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_setup_proposals
                (run_id, supplier_id, proposal_type, signature, title, confidence, occurrence_count,
                 affected_amount, proposal_json, evidence_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), confidence = VALUES(confidence),
                 occurrence_count = VALUES(occurrence_count), affected_amount = VALUES(affected_amount),
                 proposal_json = VALUES(proposal_json), evidence_json = VALUES(evidence_json)'
        )->execute([
            $runId, $supplierId, $type, $signature, mb_substr($title, 0, 190), $confidence, $count,
            round($amount, 2), self::json($proposal), self::json($evidence),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function proposals(int $supplierId, int $runId, ?string $type = null): array
    {
        $sql = 'SELECT * FROM accounting_setup_proposals WHERE supplier_id = ? AND run_id = ?';
        $params = [$supplierId, $runId];
        if ($type !== null) {
            $sql .= ' AND proposal_type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY proposal_type, confidence DESC, occurrence_count DESC, id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->decode($row) ?? [], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findProposal(int $supplierId, int $runId, int $proposalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_setup_proposals WHERE id = ? AND run_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$proposalId, $runId, $supplierId]);
        return $this->decode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function updatePendingProposal(
        int $supplierId,
        int $runId,
        int $proposalId,
        string $title,
        array $proposal,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE accounting_setup_proposals p
                SET p.title = ?, p.proposal_json = ?
              WHERE p.id = ? AND p.run_id = ? AND p.supplier_id = ? AND p.decision = 'pending'
                AND NOT EXISTS (
                    SELECT 1 FROM accounting_setup_rule_bundles b
                     WHERE b.run_id = p.run_id AND b.supplier_id = p.supplier_id
                )"
        );
        $stmt->execute([
            mb_substr($title, 0, 190),
            self::json($proposal),
            $proposalId,
            $runId,
            $supplierId,
        ]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $check = $this->db->pdo()->prepare(
            "SELECT 1 FROM accounting_setup_proposals p
              WHERE p.id = ? AND p.run_id = ? AND p.supplier_id = ? AND p.decision = 'pending'
                AND NOT EXISTS (
                    SELECT 1 FROM accounting_setup_rule_bundles b
                     WHERE b.run_id = p.run_id AND b.supplier_id = p.supplier_id
                )"
        );
        $check->execute([$proposalId, $runId, $supplierId]);
        return $check->fetchColumn() !== false;
    }

    public function updatePendingChartProposal(
        int $supplierId,
        int $runId,
        int $proposalId,
        string $title,
        array $proposal,
    ): bool {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT p.* FROM accounting_setup_proposals p
                  WHERE p.id = ? AND p.run_id = ? AND p.supplier_id = ?
                    AND p.proposal_type = 'chart_account' AND p.decision = 'pending'
                    AND NOT EXISTS (
                        SELECT 1 FROM accounting_setup_rule_bundles b
                         WHERE b.run_id = p.run_id AND b.supplier_id = p.supplier_id
                    )
                  FOR UPDATE"
            );
            $stmt->execute([$proposalId, $runId, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return false;
            }

            $existing = self::decodeJson($row['proposal_json']) ?? [];
            $originalCode = trim((string) ($existing['account_code'] ?? ''));
            $currentCode = trim((string) ($existing['replacement_account_code'] ?? '')) ?: $originalCode;
            $targetCode = trim((string) ($proposal['replacement_account_code'] ?? '')) ?: $originalCode;
            $dependentIds = array_values(array_unique(array_filter(
                array_map('intval', (array) ($existing['dependent_proposal_ids'] ?? [])),
                static fn (int $id): bool => $id > 0,
            )));
            $discoverDependencies = $dependentIds === [];

            $dependents = $pdo->prepare(
                "SELECT id, proposal_json FROM accounting_setup_proposals
                  WHERE run_id = ? AND supplier_id = ? AND decision = 'pending'
                    AND proposal_type IN ('expense_rule', 'posting_rule', 'bank_rule')
                  ORDER BY id FOR UPDATE"
            );
            $dependents->execute([$runId, $supplierId]);
            $update = $pdo->prepare(
                "UPDATE accounting_setup_proposals
                    SET proposal_json = ?
                  WHERE id = ? AND run_id = ? AND supplier_id = ? AND decision = 'pending'"
            );
            foreach ($dependents->fetchAll(PDO::FETCH_ASSOC) as $dependent) {
                $dependentId = (int) $dependent['id'];
                $payload = self::decodeJson($dependent['proposal_json']) ?? [];
                $tracked = in_array($dependentId, $dependentIds, true);
                $referencesCurrent = false;
                $changed = false;
                foreach (['target_account_code', 'debit_account_code', 'credit_account_code'] as $field) {
                    if (($tracked || $discoverDependencies) && trim((string) ($payload[$field] ?? '')) === $currentCode) {
                        $referencesCurrent = true;
                        if ($currentCode !== $targetCode) {
                            $payload[$field] = $targetCode;
                            $changed = true;
                        }
                    }
                }
                if (!$referencesCurrent) {
                    continue;
                }
                if (!$tracked) {
                    $dependentIds[] = $dependentId;
                }
                if (!$changed) {
                    continue;
                }
                $update->execute([self::json($payload), $dependentId, $runId, $supplierId]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('proposal_dependency_changed');
                }
            }

            $proposal['dependent_proposal_ids'] = array_values(array_unique($dependentIds));
            $save = $pdo->prepare(
                "UPDATE accounting_setup_proposals
                    SET title = ?, proposal_json = ?
                  WHERE id = ? AND run_id = ? AND supplier_id = ? AND decision = 'pending'"
            );
            $save->execute([
                mb_substr($title, 0, 190),
                self::json($proposal),
                $proposalId,
                $runId,
                $supplierId,
            ]);
            if ($save->rowCount() !== 1 && self::json($existing) !== self::json($proposal)) {
                throw new \RuntimeException('proposal_changed');
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function approve(
        int $supplierId,
        int $runId,
        array $proposalIds,
        int $userId,
        array $existingExpenseRules = [],
    ): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $run = $this->findRun($supplierId, $runId);
            if ($run === null || $run['completed_at'] === null || $run['input_hash'] === null) {
                throw new \RuntimeException('analysis_not_completed');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $proposalIds), static fn (int $id): bool => $id > 0)));
            if ($ids === [] && $existingExpenseRules === []) {
                throw new \InvalidArgumentException('proposal_selection_empty');
            }
            $rows = [];
            $placeholders = '';
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT * FROM accounting_setup_proposals
                      WHERE supplier_id = ? AND run_id = ? AND decision = 'pending' AND id IN ({$placeholders})
                      ORDER BY id FOR UPDATE"
                );
                $stmt->execute([$supplierId, $runId, ...$ids]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) !== count($ids)) {
                    throw new \RuntimeException('proposal_selection_invalid');
                }
            }
            $payload = array_map(static fn (array $rule): array => [
                'proposal_id' => null,
                'type' => 'expense_rule',
                'source' => 'existing',
                'proposal' => $rule,
            ], $existingExpenseRules);
            $payload = array_merge($payload, array_map(fn (array $row): array => [
                'proposal_id' => (int) $row['id'],
                'type' => (string) $row['proposal_type'],
                'proposal' => self::decodeJson($row['proposal_json']),
            ], $rows));
            $json = self::json($payload);
            $hash = hash('sha256', $json);
            $pdo->prepare(
                'INSERT INTO accounting_setup_rule_bundles
                    (run_id, supplier_id, bundle_hash, input_hash, payload_json, approved_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$runId, $supplierId, $hash, $run['input_hash'], $json, $userId]);
            $bundleId = (int) $pdo->lastInsertId();
            if ($ids !== []) {
                $pdo->prepare(
                    "UPDATE accounting_setup_proposals SET decision = 'approved', decided_by = ?, decided_at = NOW()
                      WHERE supplier_id = ? AND run_id = ? AND id IN ({$placeholders})"
                )->execute([$userId, $supplierId, $runId, ...$ids]);
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
            return ['id' => $bundleId, 'bundle_hash' => $hash, 'input_hash' => $run['input_hash'], 'payload' => $payload];
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findBundle(int $supplierId, int $bundleId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_setup_rule_bundles WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$bundleId, $supplierId]);
        return $this->decode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function addReclassificationItem(int $jobId, int $bundleId, int $supplierId, int $invoiceId, string $status, ?array $before, ?array $after, ?int $entryId = null, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_reclassification_items
                (job_id, bundle_id, supplier_id, purchase_invoice_id, status, before_json, after_json,
                 correction_entry_id, error_code, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), before_json = VALUES(before_json),
                 after_json = VALUES(after_json), correction_entry_id = VALUES(correction_entry_id),
                 error_code = VALUES(error_code), error_message = VALUES(error_message)'
        )->execute([
            $jobId, $bundleId, $supplierId, $invoiceId, $status,
            $before === null ? null : self::json($before), $after === null ? null : self::json($after),
            $entryId, $errorCode, $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function reclassificationItems(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_reclassification_items WHERE supplier_id = ? AND job_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $jobId]);
        return array_map(fn (array $row): array => $this->decode($row) ?? [], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function hasRollbackSnapshot(int $supplierId, int $jobId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) applied_count, COALESCE(SUM(before_json IS NOT NULL), 0) snapshot_count
               FROM accounting_reclassification_items
              WHERE supplier_id = ? AND job_id = ? AND status = 'applied'"
        );
        $stmt->execute([$supplierId, $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $applied = (int) ($row['applied_count'] ?? 0);
        return $applied > 0 && $applied === (int) ($row['snapshot_count'] ?? 0);
    }

    public function deleteRollbackSnapshot(int $supplierId, int $jobId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE accounting_reclassification_items
                SET before_json = NULL
              WHERE supplier_id = ? AND job_id = ? AND status = 'applied' AND before_json IS NOT NULL"
        );
        $stmt->execute([$supplierId, $jobId]);
        return $stmt->rowCount();
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['scope_json', 'summary_json', 'proposal_json', 'evidence_json', 'payload_json', 'before_json', 'after_json'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::decodeJson($row[$key]);
            }
        }
        foreach (['id', 'supplier_id', 'job_id', 'run_id', 'bundle_id', 'purchase_invoice_id', 'correction_entry_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        return $row;
    }

    private static function decodeJson(mixed $value): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
