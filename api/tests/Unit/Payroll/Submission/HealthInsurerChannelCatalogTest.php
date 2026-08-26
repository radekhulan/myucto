<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerIsdsAttachmentFormat;
use PHPUnit\Framework\TestCase;

final class HealthInsurerChannelCatalogTest extends TestCase
{
    public function testCurrentAttachmentMatrixCoversAllSevenInsurers(): void
    {
        $catalog = new HealthInsurerChannelCatalog();
        $formats = [];
        foreach ($catalog->channels() as $code => $channel) {
            $formats[$code] = $channel
                ->isdsAttachmentFormatOn('2026-08-25')
                ->value;
        }

        self::assertSame([
            '111' => 'text_pdf',
            '201' => 'text_pdf',
            '205' => 'xml',
            '207' => 'xml',
            '209' => 'text_pdf',
            '211' => 'text_pdf',
            '213' => 'xml',
        ], $formats);
    }

    public function testZpMvKeepsTextPdfForIsdsAfterXmlGatewayLaunch(): void
    {
        $channel = (new HealthInsurerChannelCatalog())->forInsurer('211');

        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::TextPdf,
            $channel->isdsAttachmentFormatOn('2026-09-30'),
        );
        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::TextPdf,
            $channel->isdsAttachmentFormatOn('2026-10-01'),
        );
        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::TextPdf,
            $channel->isdsAttachmentFormatOn('2027-01-01'),
        );
    }

    public function testChannelCodesStayInSyncWithTheSharedInsurerCodebook(): void
    {
        self::assertSame(
            HealthInsurers::codes(),
            array_map(
                strval(...),
                array_keys((new HealthInsurerChannelCatalog())->channels()),
            ),
        );
    }
}
