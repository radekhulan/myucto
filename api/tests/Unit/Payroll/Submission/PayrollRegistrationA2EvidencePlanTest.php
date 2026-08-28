<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA2EvidencePlan;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA2EvidencePlanTest extends TestCase
{
    public function testEveryCorrectiveMonthMustCarryAcceptedTransportEvidence(): void
    {
        $plan = PayrollRegistrationA2EvidencePlan::create(
            7,
            'test',
            11,
            '2026-08-25',
            [
                $this->month('2026-06-01', 'accepted'),
                $this->month('2026-07-01', 'rejected'),
            ],
        );

        self::assertSame('blocked', $plan->decision());
        self::assertSame(['2026-07-01'], $plan->blockedPeriods());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $plan->fingerprint());
    }

    public function testCanonicalFingerprintDoesNotDependOnDatabaseRowOrder(): void
    {
        $june = $this->month('2026-06-01', 'accepted');
        $july = $this->month('2026-07-01', 'accepted');

        $first = PayrollRegistrationA2EvidencePlan::create(
            7,
            'production',
            11,
            '2026-08-25',
            [$july, $june],
        );
        $second = PayrollRegistrationA2EvidencePlan::create(
            7,
            'production',
            11,
            '2026-08-25',
            [$june, $july],
        );

        self::assertSame('accepted', $first->decision());
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame($first->toArray(), $second->toArray());
    }

    /** @return array<string,mixed> */
    private function month(string $periodStart, string $decision): array
    {
        return [
            'period_start' => $periodStart,
            'run_id' => 20 + (int) substr($periodStart, 5, 2),
            'revision_id' => 30 + (int) substr($periodStart, 5, 2),
            'preparation_id' => 40 + (int) substr($periodStart, 5, 2),
            'submission_id' => 50 + (int) substr($periodStart, 5, 2),
            'transport_attempt_id' => 60 + (int) substr($periodStart, 5, 2),
            'receipt_id' => 70 + (int) substr($periodStart, 5, 2),
            'submission_status' => $decision === 'accepted' ? 'accepted' : 'rejected',
            'transport_status' => 'completed',
            'transport_sent_at' => '2026-08-20 10:00:00',
            'transport_correlation_reference' => 'SYNTHETIC-A2-' . substr($periodStart, 5, 2),
            'receipt_status' => $decision,
            'receipt_verification_status' => 'trusted',
            'receipt_correlation_reference' => 'SYNTHETIC-A2-' . substr($periodStart, 5, 2),
            'form_status' => $decision,
            'decision' => $decision,
            'reason' => $decision === 'accepted'
                ? null
                : 'Opravný formulář byl ČSSZ odmítnut.',
        ];
    }
}

