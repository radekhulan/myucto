<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\HealthInsuranceOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewBuilder;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewService;
use PHPUnit\Framework\TestCase;

/**
 * Kód pojišťovny se v `overview()` kontroluje ještě před sáhnutím do databáze,
 * takže tenhle test nepotřebuje data — jen ověřuje, že se brána zúžila
 * z tvaru `\d{3}` na skutečný číselník.
 */
final class HealthPaymentOverviewServiceInsurerCodebookTest extends TestCase
{
    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kód zdravotní pojišťovny 999 neexistuje.');
        $this->service()->overview(1, 1, '999');
    }

    public function testMessageListsTheAvailableInsurers(): void
    {
        try {
            $this->service()->overview(1, 1, '000');
            self::fail('Neexistující pojišťovna musí být odmítnuta.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('111 VZP', $exception->getMessage());
            self::assertStringContainsString('213 RBP', $exception->getMessage());
        }
    }

    private function service(): HealthPaymentOverviewService
    {
        return new HealthPaymentOverviewService(
            new HealthInsuranceOverviewRepository(
                $this->createStub(Connection::class),
                $this->createStub(PayrollStatutoryResultRepository::class),
            ),
            new HealthPaymentOverviewBuilder(),
        );
    }
}
