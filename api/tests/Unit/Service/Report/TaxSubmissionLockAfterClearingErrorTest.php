<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\Accounting\Vat\VatClearingTrigger;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use PHPUnit\Framework\TestCase;

/**
 * Podání přiznání k DPH zaúčtuje zúčtování DPH a pak zamkne konec období. Když zúčtování
 * selže nečekaně (odvolaná transakce 1020/1213), zámek by jeho opakování znemožnil.
 */
final class TaxSubmissionLockAfterClearingErrorTest extends TestCase
{
    use AbortedTransactionTrait;

    public function testFailedClearingDoesNotAdvanceLock(): void
    {
        $clearing = $this->createStub(VatClearingService::class);
        $clearing->method('isCandidate')->willReturn(true);
        $clearing->method('postForPeriod')->willThrowException(self::abortedFailure(1213));
        $settings = $this->createMock(AccountingSupplierSettingsRepository::class);
        $settings->expects(self::never())->method('advanceLockedUntil');

        $row = $this->archiver($clearing, $settings)->markSubmitted(41, 3, '2099-02-20 10:00:00', 'EPO-1', 1);

        self::assertSame('error', $row['vat_clearing']['status'] ?? null);
    }

    public function testSkippedClearingStillAdvancesLock(): void
    {
        $clearing = $this->createStub(VatClearingService::class);
        $clearing->method('isCandidate')->willReturn(true);
        $clearing->method('postForPeriod')->willThrowException(new PostingException('date_locked', 'Zamčeno.'));
        $settings = $this->createMock(AccountingSupplierSettingsRepository::class);
        $settings->expects(self::once())->method('advanceLockedUntil')->with(3, '2020-01-31');

        $row = $this->archiver($clearing, $settings)->markSubmitted(41, 3, '2020-02-20 10:00:00', 'EPO-1', 1);

        self::assertSame('skipped', $row['vat_clearing']['status'] ?? null);
    }

    private function archiver(VatClearingService $clearing, AccountingSupplierSettingsRepository $settings): TaxSubmissionArchiver
    {
        $repo = $this->createStub(TaxSubmissionRepository::class);
        $repo->method('markSubmitted')->willReturn([
            'id' => 41, 'supplier_id' => 3, 'form_code' => 'dphdp3',
            'period_year' => 2020, 'period_month' => 1, 'period_quarter' => null,
            'validation_status' => 'passed', 'status' => 'submitted',
        ]);

        return new TaxSubmissionArchiver(
            $repo,
            $this->createStub(XmlSchemaValidator::class),
            $settings,
            new VatClearingTrigger($clearing, $this->createStub(ActivityLogger::class)),
            new DemoMode(new Config([])),
        );
    }
}
