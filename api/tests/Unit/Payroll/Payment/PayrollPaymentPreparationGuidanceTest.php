<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentPreparationException;
use PHPUnit\Framework\TestCase;

final class PayrollPaymentPreparationGuidanceTest extends TestCase
{
    public function testUnapprovedRevisionKeepsReasonAndLeadsToRuns(): void
    {
        $exception = $this->failure('assertRevisionContext', [['revision_status' => 'draft']]);
        $issue = PayrollPaymentPreparationException::issue('net_wage', $exception);
        self::assertSame('net_wage', $issue['liability_kind']);
        self::assertSame('revision_not_approved', $issue['reason']);
        self::assertSame('/payroll/runs', $issue['remediation_path']);
    }

    public function testFrozenAccountValidityFailureKeepsEmployeeAndExplainsNewRevision(): void
    {
        $exception = $this->failure('verifiedAccounts', [[[
            'id' => 8,
            'bank_account_hash' => str_repeat('a', 64),
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ]], '2026-08-15', 42]);
        $issue = PayrollPaymentPreparationException::issue('net_wage', $exception);
        self::assertSame('person_account_not_effective', $issue['reason']);
        self::assertSame('/payroll/people?person=42&panel=accounts', $issue['remediation_path']);
        self::assertStringContainsString('opravnou revizi', $issue['message']);
    }

    public function testInstitutionAccountFailureDoesNotLoseItsDetailedCause(): void
    {
        $exception = new PayrollPaymentPreparationException('institution_account_ambiguous', 'Byly nalezeny dva ověřené účty. Ukončete platnost nepoužívaného účtu.');
        $issue = PayrollPaymentPreparationException::issue('health_insurance', $exception);
        self::assertSame('institution_account_ambiguous', $issue['reason']);
        self::assertSame($exception->getMessage(), $issue['message']);
        self::assertSame('/payroll/settings?tab=institutions', $issue['remediation_path']);
    }

    public function testIntegrityFailureIsNotMisdiagnosedAsMissingInstitutionAccount(): void
    {
        $exception = new \DomainException('snapshot_hash_mismatch');
        $issue = PayrollPaymentPreparationException::issue('health_insurance', $exception);
        self::assertSame('support_required', $issue['reason']);
        self::assertSame('/admin/support', $issue['remediation_path']);
        self::assertStringNotContainsString('snapshot_hash_mismatch', $issue['message']);
        self::assertSame('snapshot_hash_mismatch', $issue['technical_detail']);
    }

    private function failure(string $method, array $arguments): \DomainException
    {
        $class = new \ReflectionClass(PayrollNetWageLiabilityMaterializer::class);
        try {
            $class->getMethod($method)->invokeArgs($class->newInstanceWithoutConstructor(), $arguments);
            self::fail('Invalid payment source was accepted.');
        } catch (\DomainException $exception) {
            return $exception;
        }
    }
}
