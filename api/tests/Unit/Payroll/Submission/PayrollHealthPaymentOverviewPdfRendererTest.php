<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPdfTemplate;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPdfTemplateProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use MyInvoice\Service\Pdf\PayrollHealthPaymentOverviewPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class PayrollHealthPaymentOverviewPdfRendererTest extends TestCase
{
    public function testVzpUsesAndVerifiesOfficialFormFields(): void
    {
        $templateBytes = $this->syntheticVzpTemplate();
        $provider = new class($templateBytes) implements HealthPaymentOverviewPdfTemplateProvider {
            public function __construct(private readonly string $bytes) {}

            public function vzpPaymentOverview(): HealthPaymentOverviewPdfTemplate
            {
                return new HealthPaymentOverviewPdfTemplate(
                    $this->bytes,
                    'synthetic-vzp-template',
                    hash('sha256', $this->bytes),
                );
            }
        };
        $payload = new HealthPaymentOverviewPayload(
            insurerCode: '111',
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: new HealthEmployerIdentification(
                payerNumber: '1234567890',
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
            assessmentBaseMinorUnits: 12345600,
            contributionCzk: 16667,
        );

        $bytes = (new PayrollHealthPaymentOverviewPdfRenderer($provider))
            ->renderPayload($payload, 'Všeobecná zdravotní pojišťovna', '2026-08-25');
        $fields = PdfEditor::fromBytes($bytes)->formFields();

        self::assertSame([
            false,
            'Praha',
            '420111222333',
            'Syntetický zaměstnavatel s.r.o.',
            'Testovací',
            '12',
            '1234567890',
            '11000',
            '25.8.2026',
            '3',
            '123456',
            '16667',
            '08/2026',
            true,
        ], array_map(static fn ($field): string|bool|array|null => $field->value, $fields));
    }

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

    private function syntheticVzpTemplate(): string
    {
        $document = new Document();
        $page = $document->addPage();
        $page->field(new Checkbox(10, 10, 5, 5, 'corrective'));
        foreach ([
            'city',
            'phone',
            'name',
            'street',
            'house_number',
            'payer_number',
            'postal_code',
            'filled_on',
            'employee_count',
            'assessment_base',
            'insurance_amount',
            'period',
        ] as $index => $name) {
            $page->field(new TextField(10, 20 + ($index * 8), 80, 6, $name));
        }
        $page->field(new Checkbox(10, 125, 5, 5, 'regular'));

        return $document->output();
    }
}
