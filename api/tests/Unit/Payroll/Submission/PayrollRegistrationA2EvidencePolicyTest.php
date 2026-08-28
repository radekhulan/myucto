<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA2EvidencePolicy;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA2EvidencePolicyTest extends TestCase
{
    public function testAcceptedRequiresTransportAttemptAndTrustedAcceptedFormOutcome(): void
    {
        self::assertSame(
            ['decision' => 'accepted', 'reason' => null],
            PayrollRegistrationA2EvidencePolicy::decide($this->acceptedFacts()),
        );
    }

    public function testMissingTransportCorrelationStaysPending(): void
    {
        $facts = $this->acceptedFacts();
        $facts['correlation_reference'] = null;

        self::assertSame(
            'pending',
            PayrollRegistrationA2EvidencePolicy::decide($facts)['decision'],
        );
    }

    public function testUntrustedReceiptStaysPending(): void
    {
        $facts = $this->acceptedFacts();
        $facts['verification_status'] = 'untrusted';

        self::assertSame(
            'pending',
            PayrollRegistrationA2EvidencePolicy::decide($facts)['decision'],
        );
    }

    public function testReceiptMustMatchTheCompletedTransportAttempt(): void
    {
        $facts = $this->acceptedFacts();
        $facts['receipt_correlation_reference'] = 'SYNTHETIC-A2-OTHER';

        self::assertSame(
            'pending',
            PayrollRegistrationA2EvidencePolicy::decide($facts)['decision'],
        );
    }

    public function testAnyExplicitRejectionWinsOverOtherAcceptedEvidence(): void
    {
        $facts = $this->acceptedFacts();
        $facts['form_status'] = 'rejected';

        self::assertSame(
            'rejected',
            PayrollRegistrationA2EvidencePolicy::decide($facts)['decision'],
        );
    }

    /** @return array<string,mixed> */
    private function acceptedFacts(): array
    {
        return [
            'submission_status' => 'accepted',
            'transport_status' => 'completed',
            'sent_at' => '2026-08-20 10:00:00',
            'correlation_reference' => 'SYNTHETIC-A2-1',
            'receipt_correlation_reference' => 'SYNTHETIC-A2-1',
            'verification_status' => 'trusted',
            'receipt_status' => 'accepted',
            'form_status' => 'accepted',
        ];
    }
}

