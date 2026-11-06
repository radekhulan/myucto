<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\Accounting\Vat\VatClearingTrigger;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Stažení XML přiznání je GET. Na veřejné ukázce tím návštěvník zakládal řádky
 * `tax_submissions` (stav `downloaded` i s celým XML). Stažení má dál fungovat,
 * archiv se ale nesmí rozrůstat.
 */
final class TaxSubmissionArchiverDemoTest extends TestCase
{
    public function testDemoValidatesButDoesNotArchive(): void
    {
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::never())->method('archive');
        $clearing = $this->createMock(VatClearingService::class);
        $clearing->expects(self::never())->method('isCandidate');

        $result = $this->archiver($repo, $clearing, true)
            ->archive(3, 'dphdp3', 2026, 9, null, '<Pisemnost/>', [], 1);

        self::assertNull($result['submission_id']);
        self::assertSame('skipped', $result['validation_status']);
        self::assertSame('not_archived', $result['status']);
    }

    public function testOutsideDemoArchivesAsBefore(): void
    {
        $repo = $this->createMock(TaxSubmissionRepository::class);
        $repo->expects(self::once())->method('archive')
            ->with(3, 'dphdp3', 2026, 9, null, '<Pisemnost/>')
            ->willReturn(77);

        $result = $this->archiver($repo, $this->createStub(VatClearingService::class), false)
            ->archive(3, 'dphdp3', 2026, 9, null, '<Pisemnost/>', [], 1, false);

        self::assertSame(77, $result['submission_id']);
        self::assertSame('downloaded', $result['status']);
    }

    private function archiver(TaxSubmissionRepository $repo, VatClearingService $clearing, bool $demo): TaxSubmissionArchiver
    {
        $validator = $this->createStub(XmlSchemaValidator::class);
        $validator->method('validate')->willReturn(['status' => 'skipped', 'errors' => []]);

        return new TaxSubmissionArchiver(
            $repo,
            $validator,
            $this->createStub(AccountingSupplierSettingsRepository::class),
            new VatClearingTrigger($clearing, $this->createStub(ActivityLogger::class)),
            new DemoMode(new Config(['demo' => ['enabled' => $demo]])),
        );
    }
}
