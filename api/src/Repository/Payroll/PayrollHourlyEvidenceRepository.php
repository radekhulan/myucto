<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceBody;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use PDO;

/** Neměnné doklady příplatkových vrstev nad jedním zvoleným zdrojem hodin. */
final class PayrollHourlyEvidenceRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return array<int,array<string,mixed>> */
    public function latestMany(int $supplierId, array $employmentIds, string $periodStart): array
    {
        if ($employmentIds === []) return [];
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare("WITH ranked AS (
            SELECT evidence.*, ROW_NUMBER() OVER (PARTITION BY employment_id ORDER BY revision_no DESC) AS position_no
            FROM payroll_hourly_evidence_revisions evidence FORCE INDEX (uq_hourly_evidence_revision)
            WHERE supplier_id = ? AND employment_id IN ($in) AND period_start = ?
        ) SELECT * FROM ranked WHERE position_no = 1");
        $stmt->execute([$supplierId, ...$employmentIds, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['employment_id']] = self::decode($row);
        return $result;
    }

    /** @return array{history:list<array<string,mixed>>,history_offset:int,history_has_more:bool} */
    public function history(int $supplierId, int $employmentId, string $periodStart, int $offset): array
    {
        if ($offset < 0 || $offset > 100_000) throw new \InvalidArgumentException('Neplatná stránka historie.');
        $stmt = $this->db->pdo()->prepare('SELECT evidence.id, evidence.revision_no, evidence.reason,
                evidence.created_at, author.name AS created_by_name
            FROM payroll_hourly_evidence_revisions evidence
            LEFT JOIN users author ON author.id = evidence.created_by
            WHERE evidence.supplier_id = ? AND evidence.employment_id = ? AND evidence.period_start = ?
            ORDER BY evidence.revision_no DESC LIMIT 21 OFFSET ' . $offset);
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['revision_no'] = (int) $row['revision_no'];
        }
        return ['history' => $rows, 'history_offset' => $offset, 'history_has_more' => $more];
    }

    public function assertWritable(int $supplierId, int $employmentId, string $periodStart): void
    {
        (new PayrollYearCloseGuard($this->db))->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
        (new PayrollClosedRunGuard($this->db))->assertOpen($supplierId, $periodStart, $employmentId);
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM payroll_inputs
            WHERE supplier_id = ? AND employment_id = ? AND period_start = ? AND status = 'locked' LIMIT 1");
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        if ($stmt->fetchColumn() !== false) throw new PayrollTimeLockedException('Mzdové vstupy jsou zamčené. Pro změnu podkladů nejprve znovu otevřete mzdový běh.');
    }

    /**
     * Validace rozvrhu a čerpání běží pod stejným zámkem vztahu jako zápis.
     * @param array<string,mixed> $input
     * @param callable(array<string,mixed>,array<string,mixed>):void $validateSelections
     * @return array<string,mixed>
     */
    public function save(int $supplierId, int $employmentId, string $periodStart, array $input, ?int $userId, callable $validateSelections): array
    {
        $version = PayrollHourlyEvidenceBody::integer($input['row_version'] ?? null, 0, PHP_INT_MAX);
        $reason = PayrollHourlyEvidenceBody::text($input['reason'] ?? null);
        $payload = PayrollHourlyEvidenceBody::normalize($input, $periodStart);
        $sourceHash = $input['work_source_hash'] ?? null;
        if (!is_string($sourceHash) || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) throw new \InvalidArgumentException('Chybí platný otisk zdroje hodin.');
        $pdo = $this->db->pdo();
        $savepoint = $pdo->inTransaction() ? 'hourly_evidence_' . bin2hex(random_bytes(6)) : null;
        if ($savepoint === null) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $stmt = $pdo->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $employmentId]);
            if ($stmt->fetchColumn() === false) throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
            $this->assertWritable($supplierId, $employmentId, $periodStart);
            $current = $this->latestMany($supplierId, [$employmentId], $periodStart)[$employmentId] ?? null;
            $currentVersion = (int) ($current['revision_no'] ?? 0);
            if ($version !== $currentVersion) throw new PayrollTimeConflictException($currentVersion);
            $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($supplierId, [$employmentId], $periodStart, true)[$employmentId] ?? null;
            $source = (new \MyInvoice\Service\Payroll\PayrollHourlyEntitlementContextLoader($this->db))->sourceForEvidence($supplierId, $employmentId, $periodStart, $source);
            if ($source === null || !in_array($source['work_source'], ['manual_summary', 'entries', 'settlement_only'], true)) throw new \InvalidArgumentException('Nejprve doložte ruční hodiny nebo docházku za zvolený měsíc.');
            if (!hash_equals(self::bindingHash($source), $sourceHash)) throw new PayrollTimeConflictException($currentVersion);
            self::validatePremiumLayers($payload, $source);
            $validateSelections($payload, $source);
            $json = CanonicalJson::encode($payload);
            $hash = hash('sha256', CanonicalJson::encode(['work_source_hash' => $sourceHash, 'payload' => $payload]));
            $stmt = $pdo->prepare('INSERT INTO payroll_hourly_evidence_revisions
                (supplier_id, employment_id, period_start, revision_no, work_source_hash, payload_json, content_sha256, reason, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $employmentId, $periodStart, $currentVersion + 1, $sourceHash, $json, $hash, $reason, $userId]);
            if ($savepoint === null) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                if ($savepoint === null) $pdo->rollBack();
                else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
        return $this->latestMany($supplierId, [$employmentId], $periodStart)[$employmentId];
    }

    public static function bindingHash(array $source): string
    {
        return (string) (($source['work_source'] ?? null) === 'manual_summary'
            ? ($source['manual_summary']['content_sha256'] ?? '') : ($source['source_sha256'] ?? ''));
    }

    private static function validatePremiumLayers(array $payload, array $source): void
    {
        if ($source['work_source'] === 'settlement_only') {
            if ($payload['premium_segments'] !== []) throw new \InvalidArgumentException('Vypořádání starších nároků neobsahuje příplatkové vrstvy aktuální práce.');
            return;
        }
        if ($source['work_source'] === 'entries') {
            if ($payload['premium_segments'] !== []) throw new \InvalidArgumentException('Příplatkové vrstvy docházky se odvozují ze záznamů, ručně je nepřepisujte.');
            return;
        }
        $segments = $source['manual_summary']['segments'] ?? [];
        if (count($segments) !== count($payload['premium_segments'])) throw new \InvalidArgumentException('Doložte příplatkové vrstvy ke každému úseku ručních hodin.');
        foreach ($segments as $index => $segment) {
            $row = $payload['premium_segments'][$index];
            if ($segment['date_from'] !== $row['date_from'] || $segment['date_to'] !== $row['date_to']) throw new \InvalidArgumentException('Příplatkové úseky musí odpovídat úsekům doložených hodin.');
            foreach (['night_duration_units', 'weekend_duration_units', 'holiday_work_duration_units'] as $key) {
                if ($row[$key] !== null && $row[$key] > $segment['worked_duration_units']) throw new \InvalidArgumentException('Příplatkové hodiny překračují doloženou práci.');
            }
            if (array_sum(array_column($row['difficult_exposures'] ?? [], 'duration_units')) > $segment['worked_duration_units']) throw new \InvalidArgumentException('Doba ztíženého prostředí překračuje doloženou práci.');
            foreach (['holiday_work_days' => 'holiday_work_duration_units', 'overtime_work_days' => 'overtime_duration_units'] as $days => $key) {
                $units = $key === 'overtime_duration_units' ? $segment[$key] : $row[$key];
                if ($row[$days] !== [] && array_sum(array_column($row[$days], 'duration_units')) !== $units) throw new \InvalidArgumentException('Součet práce po dnech neodpovídá doloženým hodinám.');
            }
        }
    }

    private static function decode(array $row): array
    {
        $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'supplier_id', 'employment_id', 'revision_no', 'created_by'] as $key) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        unset($row['payload_json'], $row['position_no']);
        return [...$payload, ...$row];
    }
}
