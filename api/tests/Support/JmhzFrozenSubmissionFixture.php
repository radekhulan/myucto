<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

/**
 * Podání JMHZ zmrazené z přípravy — tak, jak ho po „Zmrazit" zakládá
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService}:
 * povinnost měsíční agendy a podání ve stavu `ready`, jehož otisk zdroje je
 * otisk přípravy. Samotná příprava bez podání za zmrazenou neplatí.
 */
final class JmhzFrozenSubmissionFixture
{
    public static function freeze(
        PDO $pdo,
        int $supplierId,
        string $periodStart,
        string $preparationFingerprint,
        ?int $userId = null,
    ): int {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $pdo->prepare(
            "INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference,
                 period_start, period_end, obligation_kind, preferred_channel,
                 source_event_type, source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, 'production', 'JMHZ25', 'payroll_run', ?, ?, ?, 'regular', 'vrep_apep',
                     'jmhz_preparation_snapshot', ?, ?, ?, ?, ?)",
        )->execute([
            $supplierId,
            'synthetic:' . $preparationFingerprint,
            $periodStart,
            $periodEnd,
            'synthetic:' . substr($preparationFingerprint, 0, 16),
            $preparationFingerprint,
            hash('sha256', 'obligation' . $preparationFingerprint),
            hash('sha256', 'obligation' . $preparationFingerprint, true),
            $userId,
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_snapshot_hash, request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, 'production', ?, 'regular', 'vrep_apep', 'ready', ?, ?, ?, ?)",
        )->execute([
            $supplierId,
            $obligationId,
            $preparationFingerprint,
            hash('sha256', 'submission' . $preparationFingerprint),
            hash('sha256', 'submission' . $preparationFingerprint, true),
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
