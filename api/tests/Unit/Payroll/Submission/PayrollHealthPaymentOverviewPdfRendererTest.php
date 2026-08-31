<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use MyInvoice\Service\Pdf\PayrollHealthPaymentOverviewPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class PayrollHealthPaymentOverviewPdfRendererTest extends TestCase
{
    public function testPdfHasAnExtractableTextLayerAndFrozenValues(): void
    {
        $payload = new HealthPaymentOverviewPayload(
            insurerCode: '209',
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: new HealthEmployerIdentification(
                payerNumber: '1234567800',
                name: 'Syntetický zaměstnavatel s.r.o.',
                street: 'Testovací',
                houseNumber: '12',
                postalCode: '11000',
                city: 'Praha',
                phone: '+420 111 222 333',
            ),
            month: 8,
            year: 2026,
            employeeCount: 3,
            assessmentBaseMinorUnits: 12345678,
            contributionCzk: 16667,
            internalReference: 'PPZ-2026-08-209',
        );

        $bytes = (new PayrollHealthPaymentOverviewPdfRenderer())
            ->renderPayload(
                $payload,
                'Zaměstnanecká pojišťovna Škoda',
                '2026-08-25',
            );

        self::assertStringStartsWith('%PDF-', $bytes);
        $text = (new Parser())->parseContent($bytes)->getText();
        self::assertStringContainsString(
            'Přehled o platbě pojistného na zdravotní pojištění zaměstnavatele',
            $text,
        );
        self::assertStringContainsString('ZPŠ — kód 209', $text);
        self::assertStringContainsString('Počet zaměstnanců pojištěných u ZPŠ', $text);
        self::assertStringContainsString('Syntetický zaměstnavatel', $text);
        self::assertStringContainsString('123 456,78 Kč', $text);
        self::assertStringContainsString('16 667 Kč', $text);
        self::assertStringContainsString('25.08.2026', $text);
    }

    public function testZpmvPdfUsesItsOwnInsurerIdentificationWithTheSameFrozenData(): void
    {
        $payload = new HealthPaymentOverviewPayload(
            insurerCode: '211',
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: new HealthEmployerIdentification(
                payerNumber: '1234567800',
                name: 'Syntetický zaměstnavatel s.r.o.',
                street: 'Testovací',
                houseNumber: '12',
                postalCode: '11000',
                city: 'Praha',
                phone: '+420 111 222 333',
            ),
            month: 8,
            year: 2026,
            employeeCount: 2,
            assessmentBaseMinorUnits: 10000000,
            contributionCzk: 13500,
            internalReference: 'PPZ-2026-08-211',
        );

        $bytes = (new PayrollHealthPaymentOverviewPdfRenderer())
            ->renderPayload(
                $payload,
                'Zdravotní pojišťovna ministerstva vnitra České republiky',
                '2026-08-25',
            );

        $text = (new Parser())->parseContent($bytes)->getText();
        self::assertStringContainsString('ZP MV ČR — kód 211', $text);
        self::assertStringContainsString(
            'Počet zaměstnanců pojištěných u ZP MV ČR',
            $text,
        );
        self::assertStringContainsString('100 000,00 Kč', $text);
        self::assertStringContainsString('13 500 Kč', $text);
    }

}
