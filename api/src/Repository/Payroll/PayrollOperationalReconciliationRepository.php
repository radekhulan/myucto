<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollOperationalReconciliationRepository
{
    private const OPEN_STATES = ['diff', 'blocked', 'not_materialized'];

    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   run_id:int,run_status:string,revision_id:?int,revision_no:int,
     *   revision_status:?string,revision_kind:?string,result_snapshot_hash:?string
     * }|null
     */
    public function runContext(int $supplierId, string $period): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id, run.status AS run_status,
                    COUNT(*) OVER () AS run_count,
                    run.current_revision_no, revision.id AS revision_id,
                    revision.status AS revision_status,
                    revision.revision_kind, revision.result_snapshot_hash
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.period_start = ?
              ORDER BY run.office_scope_id
              LIMIT 1',
        );
        $statement->execute([$supplierId, $period . '-01']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((int) $row['run_count'] !== 1) {
            throw new \DomainException(
                'Provozní reconciliation zatím vyžaduje právě jeden firemní běh za období; více účtáren se vyhodnotí fail-closed.',
            );
        }

        return [
            'run_id' => (int) $row['run_id'],
            'run_status' => (string) $row['run_status'],
            'revision_id' => $row['revision_id'] === null
                ? null
                : (int) $row['revision_id'],
            'revision_no' => (int) $row['current_revision_no'],
            'revision_status' => $row['revision_status'] === null
                ? null
                : (string) $row['revision_status'],
            'revision_kind' => $row['revision_kind'] === null
                ? null
                : (string) $row['revision_kind'],
            'result_snapshot_hash' => $row['result_snapshot_hash'] === null
                ? null
                : (string) $row['result_snapshot_hash'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function jmhzStates(
        int $supplierId,
        string $period,
        string $agendaCode,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT environment, obligation_id, obligation_status,
                    submission_id, submission_status, submission_kind,
                    source_revision_id, source_snapshot_hash
               FROM (
                    SELECT obligation.environment,
                           obligation.id AS obligation_id,
                           obligation.status AS obligation_status,
                           submission.id AS submission_id,
                           submission.status AS submission_status,
                           submission.submission_kind,
                           submission.source_revision_id,
                           submission.source_snapshot_hash,
                           ROW_NUMBER() OVER (
                               PARTITION BY obligation.environment
                               ORDER BY submission.created_at DESC,
                                        submission.id DESC,
                                        obligation.created_at DESC,
                                        obligation.id DESC
                           ) AS row_rank
                      FROM payroll_obligations obligation
                 LEFT JOIN payroll_submissions submission
                        ON submission.supplier_id = obligation.supplier_id
                       AND submission.environment = obligation.environment
                       AND submission.obligation_id = obligation.id
                     WHERE obligation.supplier_id = ?
                       AND obligation.agenda_code = ?
                       AND obligation.period_start <= ?
                       AND obligation.period_end >= ?
               ) ranked
              WHERE row_rank = 1
              ORDER BY environment',
        );
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $statement->execute([
            $supplierId,
            $agendaCode,
            $periodEnd,
            $periodStart,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'environment' => (string) $row['environment'],
                'obligation_id' => (int) $row['obligation_id'],
                'obligation_status' => (string) $row['obligation_status'],
                'submission_id' => $row['submission_id'] === null
                    ? null
                    : (int) $row['submission_id'],
                'submission_status' => $row['submission_status'] === null
                    ? null
                    : (string) $row['submission_status'],
                'submission_kind' => $row['submission_kind'] === null
                    ? null
                    : (string) $row['submission_kind'],
                'source_revision_id' => $row['source_revision_id'] === null
                    ? null
                    : (int) $row['source_revision_id'],
                'source_snapshot_hash' => $row['source_snapshot_hash'] === null
                    ? null
                    : (string) $row['source_snapshot_hash'],
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $findings
     */
    public function synchronize(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $periodStart,
        array $findings,
    ): void {
        $normalized = [];
        foreach ($findings as $finding) {
            $item = $this->normalizeFinding($finding);
            if (isset($normalized[$item['key']])) {
                throw new \InvalidArgumentException(
                    'Sweep obsahuje duplicitní klíč reconciliation.',
                );
            }
            $normalized[$item['key']] = $item;
        }

        $this->transaction(function () use (
            $supplierId,
            $runId,
            $revisionId,
            $periodStart,
            $normalized,
        ): void {
            $this->lockCurrentRevision(
                $supplierId,
                $runId,
                $revisionId,
                $periodStart,
            );
            $existing = $this->lockIssues($supplierId, $runId);

            foreach ($normalized as $key => $finding) {
                if (!in_array($finding['status'], self::OPEN_STATES, true)) {
                    continue;
                }
                $issue = $existing[$key] ?? null;
                if ($issue === null) {
                    $issueId = $this->insertIssue(
                        $supplierId,
                        $runId,
                        $revisionId,
                        $periodStart,
                        $finding,
                    );
                    $this->insertEvent(
                        $supplierId,
                        $issueId,
                        'detected',
                        null,
                        'open',
                        $finding['status'],
                        $finding,
                    );
                    continue;
                }

                $transition = null;
                if ($issue['status'] === 'resolved') {
                    $transition = 'reopened';
                } elseif (!hash_equals($issue['source_hash'], $finding['source_hash'])
                    || $issue['finding_state'] !== $finding['status']
                ) {
                    $transition = 'observed';
                }
                $this->updateOpenIssue(
                    $supplierId,
                    $issue['id'],
                    $revisionId,
                    $finding,
                );
                if ($transition !== null) {
                    $this->insertEvent(
                        $supplierId,
                        $issue['id'],
                        $transition,
                        $transition === 'reopened' ? 'resolved' : 'open',
                        'open',
                        $finding['status'],
                        $finding,
                    );
                }
            }

            foreach ($existing as $key => $issue) {
                if ($issue['status'] !== 'open') {
                    continue;
                }
                $finding = $normalized[$key] ?? $this->absentFinding($issue);
                if (in_array($finding['status'], self::OPEN_STATES, true)) {
                    continue;
                }
                $this->resolveIssue(
                    $supplierId,
                    $issue['id'],
                    $revisionId,
                    $finding,
                );
                $this->insertEvent(
                    $supplierId,
                    $issue['id'],
                    'resolved',
                    'open',
                    'resolved',
                    $issue['finding_state'],
                    $finding,
                );
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function forPeriod(int $supplierId, string $period): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, run_id, current_revision_id, period_start, issue_key,
                    scope, category, status, finding_state, expected_minor,
                    actual_minor, difference_minor, source_hash, first_seen_at,
                    last_seen_at, resolved_at, row_version
               FROM payroll_operational_reconciliation_issues
              WHERE supplier_id = ? AND period_start = ?
              ORDER BY status = "resolved", scope, category, issue_key',
        );
        $statement->execute([$supplierId, $period . '-01']);

        return array_map($this->issueRow(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $issueId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, run_id, current_revision_id, period_start, issue_key,
                    scope, category, status, finding_state, expected_minor,
                    actual_minor, difference_minor, source_snapshot_json,
                    source_hash, first_seen_at, last_seen_at, resolved_at,
                    row_version
               FROM payroll_operational_reconciliation_issues
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $issueId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $events = $this->db->pdo()->prepare(
            'SELECT id, transition_kind, from_status, to_status, finding_state,
                    expected_minor, actual_minor, difference_minor,
                    source_snapshot_json, source_hash, occurred_at
               FROM payroll_operational_reconciliation_issue_events
              WHERE supplier_id = ? AND issue_id = ?
              ORDER BY occurred_at, id',
        );
        $events->execute([$supplierId, $issueId]);

        return [
            ...$this->issueRow($row),
            'source_snapshot' => $this->decodeSnapshot(
                (string) $row['source_snapshot_json'],
            ),
            'events' => array_map(
                $this->eventRow(...),
                $events->fetchAll(PDO::FETCH_ASSOC),
            ),
        ];
    }

    /**
     * @return array{
     *   open:int,diff:int,blocked:int,not_materialized:int,periods:int,
     *   oldest_first_seen_at:?string
     * }
     */
    public function summary(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS open_count,
                    SUM(finding_state = "diff") AS diff_count,
                    SUM(finding_state = "blocked") AS blocked_count,
                    SUM(finding_state = "not_materialized") AS not_materialized_count,
                    COUNT(DISTINCT period_start) AS period_count,
                    DATE_FORMAT(MIN(first_seen_at), "%Y-%m-%d %H:%i:%s.%f") AS oldest
               FROM payroll_operational_reconciliation_issues
              WHERE supplier_id = ? AND status = "open"',
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'open' => (int) ($row['open_count'] ?? 0),
            'diff' => (int) ($row['diff_count'] ?? 0),
            'blocked' => (int) ($row['blocked_count'] ?? 0),
            'not_materialized' => (int) ($row['not_materialized_count'] ?? 0),
            'periods' => (int) ($row['period_count'] ?? 0),
            'oldest_first_seen_at' => $row['oldest'] === null
                ? null
                : (string) $row['oldest'],
        ];
    }

    private function lockCurrentRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $periodStart,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?
                AND run.period_start = ? AND revision.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $runId, $periodStart, $revisionId]);
        if ((int) ($statement->fetchColumn() ?: 0) !== $revisionId) {
            throw new \DomainException(
                'Mzdová revize se během reconciliation změnila; sweep zopakujte.',
            );
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function lockIssues(int $supplierId, int $runId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, issue_key, status, finding_state, source_hash,
                    scope, category
               FROM payroll_operational_reconciliation_issues
              WHERE supplier_id = ? AND run_id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $runId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['issue_key']] = [
                'id' => (int) $row['id'],
                'key' => (string) $row['issue_key'],
                'status' => (string) $row['status'],
                'finding_state' => (string) $row['finding_state'],
                'source_hash' => (string) $row['source_hash'],
                'scope' => (string) $row['scope'],
                'category' => (string) $row['category'],
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $finding */
    private function insertIssue(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $periodStart,
        array $finding,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_operational_reconciliation_issues
                (supplier_id, run_id, current_revision_id, period_start,
                 issue_key, scope, category, status, finding_state,
                 expected_minor, actual_minor, difference_minor,
                 source_snapshot_json, source_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, "open", ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $runId,
            $revisionId,
            $periodStart,
            $finding['key'],
            $finding['scope'],
            $finding['category'],
            $finding['status'],
            $finding['expected_minor'],
            $finding['actual_minor'],
            $finding['difference_minor'],
            $finding['source_snapshot_json'],
            $finding['source_hash'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $finding */
    private function updateOpenIssue(
        int $supplierId,
        int $issueId,
        int $revisionId,
        array $finding,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_operational_reconciliation_issues
                SET current_revision_id = ?, status = "open",
                    finding_state = ?, expected_minor = ?, actual_minor = ?,
                    difference_minor = ?, source_snapshot_json = ?,
                    source_hash = ?, last_seen_at = CURRENT_TIMESTAMP(6),
                    resolved_at = NULL, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([
            $revisionId,
            $finding['status'],
            $finding['expected_minor'],
            $finding['actual_minor'],
            $finding['difference_minor'],
            $finding['source_snapshot_json'],
            $finding['source_hash'],
            $supplierId,
            $issueId,
        ]);
    }

    /** @param array<string,mixed> $finding */
    private function resolveIssue(
        int $supplierId,
        int $issueId,
        int $revisionId,
        array $finding,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_operational_reconciliation_issues
                SET current_revision_id = ?, status = "resolved",
                    expected_minor = ?, actual_minor = ?, difference_minor = ?,
                    source_snapshot_json = ?, source_hash = ?,
                    last_seen_at = CURRENT_TIMESTAMP(6),
                    resolved_at = CURRENT_TIMESTAMP(6),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND status = "open"',
        );
        $statement->execute([
            $revisionId,
            $finding['expected_minor'],
            $finding['actual_minor'],
            $finding['difference_minor'],
            $finding['source_snapshot_json'],
            $finding['source_hash'],
            $supplierId,
            $issueId,
        ]);
    }

    /**
     * @param array<string,mixed> $finding
     */
    private function insertEvent(
        int $supplierId,
        int $issueId,
        string $transition,
        ?string $from,
        string $to,
        string $findingState,
        array $finding,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_operational_reconciliation_issue_events
                (supplier_id, issue_id, transition_kind, from_status, to_status,
                 finding_state, expected_minor, actual_minor, difference_minor,
                 source_snapshot_json, source_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $issueId,
            $transition,
            $from,
            $to,
            $findingState,
            $finding['expected_minor'],
            $finding['actual_minor'],
            $finding['difference_minor'],
            $finding['source_snapshot_json'],
            $finding['source_hash'],
        ]);
    }

    /** @param array<string,mixed> $finding @return array<string,mixed> */
    private function normalizeFinding(array $finding): array
    {
        $key = $finding['key'] ?? null;
        $scope = $finding['scope'] ?? null;
        $category = $finding['category'] ?? null;
        $status = $finding['status'] ?? null;
        $json = $finding['source_snapshot_json'] ?? null;
        $hash = $finding['source_hash'] ?? null;
        if (!is_string($key)
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $key) !== 1
            || !in_array($scope, ['posting', 'payment', 'health', 'jmhz'], true)
            || !is_string($category)
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $category) !== 1
            || !in_array($status, [
                'match', 'diff', 'not_applicable', 'not_materialized', 'blocked',
            ], true)
            || !is_string($json)
            || !is_array(json_decode($json, true, flags: JSON_THROW_ON_ERROR))
            || !is_string($hash)
            || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            throw new \InvalidArgumentException('Reconciliation finding není platný.');
        }
        $expected = $this->nullableInt($finding['expected_minor'] ?? null);
        $actual = $this->nullableInt($finding['actual_minor'] ?? null);
        $difference = $this->nullableInt($finding['difference_minor'] ?? null);
        if (($expected === null) !== ($actual === null)
            || ($expected === null) !== ($difference === null)
            || ($expected !== null && $difference !== $expected - $actual)
        ) {
            throw new \InvalidArgumentException(
                'Reconciliation částky nejsou úplné nebo nesouhlasí.',
            );
        }

        return [
            ...$finding,
            'key' => $key,
            'scope' => $scope,
            'category' => $category,
            'status' => $status,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'difference_minor' => $difference,
            'source_snapshot_json' => $json,
            'source_hash' => $hash,
        ];
    }

    /** @param array<string,mixed> $issue @return array<string,mixed> */
    private function absentFinding(array $issue): array
    {
        $snapshot = [
            'key' => $issue['key'],
            'status' => 'not_applicable',
            'reason' => 'axis_absent_after_sweep',
        ];
        $json = CanonicalJson::encode($snapshot);

        return [
            'key' => 'resolved:axis_absent',
            'scope' => $issue['scope'],
            'category' => $issue['category'],
            'status' => 'not_applicable',
            'expected_minor' => null,
            'actual_minor' => null,
            'difference_minor' => null,
            'source_snapshot_json' => $json,
            'source_hash' => hash('sha256', $json),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Reconciliation částka musí být integer.');
        }

        return $value;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function issueRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'run_id' => (int) $row['run_id'],
            'current_revision_id' => (int) $row['current_revision_id'],
            'period' => substr((string) $row['period_start'], 0, 7),
            'key' => (string) $row['issue_key'],
            'scope' => (string) $row['scope'],
            'category' => (string) $row['category'],
            'status' => (string) $row['status'],
            'finding_state' => (string) $row['finding_state'],
            'expected_minor' => $row['expected_minor'] === null
                ? null
                : (int) $row['expected_minor'],
            'actual_minor' => $row['actual_minor'] === null
                ? null
                : (int) $row['actual_minor'],
            'difference_minor' => $row['difference_minor'] === null
                ? null
                : (int) $row['difference_minor'],
            'source_hash' => (string) $row['source_hash'],
            'first_seen_at' => (string) $row['first_seen_at'],
            'last_seen_at' => (string) $row['last_seen_at'],
            'resolved_at' => $row['resolved_at'] === null
                ? null
                : (string) $row['resolved_at'],
            'row_version' => (int) $row['row_version'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function eventRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'transition_kind' => (string) $row['transition_kind'],
            'from_status' => $row['from_status'] === null
                ? null
                : (string) $row['from_status'],
            'to_status' => (string) $row['to_status'],
            'finding_state' => (string) $row['finding_state'],
            'expected_minor' => $row['expected_minor'] === null
                ? null
                : (int) $row['expected_minor'],
            'actual_minor' => $row['actual_minor'] === null
                ? null
                : (int) $row['actual_minor'],
            'difference_minor' => $row['difference_minor'] === null
                ? null
                : (int) $row['difference_minor'],
            'source_snapshot' => $this->decodeSnapshot(
                (string) $row['source_snapshot_json'],
            ),
            'source_hash' => (string) $row['source_hash'],
            'occurred_at' => (string) $row['occurred_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeSnapshot(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException(
                'Uložený reconciliation snapshot není objekt.',
            );
        }

        return $decoded;
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $savepoint = null;
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_reconciliation_' . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }
}
