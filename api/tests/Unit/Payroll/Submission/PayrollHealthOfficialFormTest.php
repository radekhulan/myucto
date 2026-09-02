<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationAddress;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialFormCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use MyInvoice\Service\Pdf\PayrollHealthBulkNotificationPdfRenderer;
use MyInvoice\Service\Pdf\PayrollHealthPaymentOverviewPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Vyplňování úředních tiskopisů zdravotních pojišťoven.
 *
 * Nejdůležitější test celé sady je {@see testOfficialFormKeepsCzechDiacritics()}.
 * Předchozí řešení zapisovalo hodnoty do AcroForm polí a ověřovalo je zpětným
 * čtením `/V` — jenže vložené písmo tiskopisu je WinAnsi, takže na papíře
 * z „Řepařská" vzniklo „?epa?ská" a kontrola to nepoznala. Test proto vytěžuje
 * text z HOTOVÉHO PDF, ne hodnoty polí.
 */
final class PayrollHealthOfficialFormTest extends TestCase
{
    private const SYNTHETIC_PAYER = '1234567800';

    public function testBulkNotificationUsesTheOfficialFormForVozp(): void
    {
        $bytes = (new PayrollHealthBulkNotificationPdfRenderer())->renderPayload(
            $this->bulkPayload('201', 2),
            'Vojenská zdravotní pojišťovna ČR',
            '2026-08-31',
            '2026-08',
        );

        $text = $this->text($bytes);
        self::assertStringContainsString('73.51/2026', $text);
        self::assertStringContainsString('Hromadné oznámení', $text);
        self::assertStringContainsString('08/2026', $text);
        self::assertStringContainsString('31.08.2026', $text);
        // Datum vydání tiskopisu (01.07.2026) je hodnotou pole šablony; do
        // výstupu se nesmí propsat, protože se přebírá jen obsah stránky.
        self::assertStringNotContainsString('01.07.2026', $text);
    }

    /**
     * Bez vykreslování vlastním písmem tenhle test padá: generátor vzhledu
     * AcroForm buď skončí výjimkou (vložené WinAnsi písmo tiskopisu), nebo
     * znak nahradí otazníkem (náhradní Helvetica).
     */
    public function testOfficialFormKeepsCzechDiacritics(): void
    {
        $bytes = (new PayrollHealthBulkNotificationPdfRenderer())->renderPayload(
            $this->bulkPayload('201', 1),
            'Vojenská zdravotní pojišťovna ČR',
            '2026-08-31',
            '2026-08',
        );

        $text = $this->text($bytes);
        self::assertStringContainsString('Řepařská Ďůra s.r.o.', $text);
        self::assertStringContainsString('Křížová-Šťastná', $text);
        self::assertStringContainsString('Žďár nad Sázavou', $text);
        self::assertStringNotContainsString('?epa', $text);
        self::assertStringNotContainsString('K?', $text);
    }

    /**
     * Celé koruny se tisknou bez desetinných míst, stejně jako je tiskne
     * portál pojišťovny. Účetní porovnávala náš tiskopis s tím, který jí
     * VZP přijala, a rozdíl byl právě tady: `22400,00` proti `22 400`.
     */
    public function testWholeCrownsPrintWithoutDecimals(): void
    {
        $bytes = (new PayrollHealthPaymentOverviewPdfRenderer())->renderPayload(
            new HealthPaymentOverviewPayload(
                insurerCode: '111',
                overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
                employer: $this->employer(),
                month: 8,
                year: 2026,
                employeeCount: 1,
                assessmentBaseMinorUnits: 2240000,
                contributionCzk: 3024,
            ),
            'Všeobecná zdravotní pojišťovna',
            '2026-09-02',
        );

        $text = $this->text($bytes);
        self::assertStringContainsString('22 400', $text);
        self::assertStringNotContainsString('22 400,00', $text);
        self::assertStringContainsString('3 024', $text);
    }

    public function testPaymentOverviewUsesTheOfficialFormAndMarksCorrectiveKind(): void
    {
        $bytes = (new PayrollHealthPaymentOverviewPdfRenderer())->renderPayload(
            new HealthPaymentOverviewPayload(
                insurerCode: '111',
                overviewKind: HealthPaymentOverviewPayload::KIND_CORRECTIVE,
                employer: $this->employer(),
                month: 8,
                year: 2026,
                employeeCount: 3,
                assessmentBaseMinorUnits: 12345678,
                contributionCzk: 16667,
            ),
            'Všeobecná zdravotní pojišťovna',
            '2026-08-31',
        );

        $text = $this->text($bytes);
        self::assertStringContainsString('76.51/2026', $text);
        self::assertStringContainsString('Řepařská Ďůra s.r.o.', $text);
        self::assertStringContainsString('08/2026', $text);
        // Částky se tisknou v podobě, jakou na tentýž tiskopis dává portál
        // pojišťovny — tisíce oddělené mezerou. Halíře se nezaokrouhlují.
        self::assertStringContainsString('123 456,78', $text);
        self::assertStringContainsString('16 667', $text);
        self::assertStringNotContainsString('123456,78', $text);
        // PSČ taky s mezerou; slepené `11000` by čtečka pojišťovny vidět
        // neměla.
        self::assertStringContainsString('110 00', $text);
        // Křížek se kreslí do políčka daného typu přehledu — u řádného
        // přehledu vyjde jinam, takže se dokumenty musí lišit.
        self::assertStringContainsString('X', $text);
    }

    /**
     * Typ přehledu je na tiskopisu přepínač se dvěma políčky pod jedním
     * jménem. Kdyby se křížek kreslil vždy do téhož, vypadaly by oba přehledy
     * stejně — proto se porovnává, že se dokumenty liší.
     */
    public function testRegularAndCorrectiveOverviewsMarkDifferentBoxes(): void
    {
        $renderer = new PayrollHealthPaymentOverviewPdfRenderer();
        $render = fn (string $kind): string => $renderer->renderPayload(
            new HealthPaymentOverviewPayload(
                insurerCode: '111',
                overviewKind: $kind,
                employer: $this->employer(),
                month: 8,
                year: 2026,
                employeeCount: 3,
                assessmentBaseMinorUnits: 12345600,
                contributionCzk: 16667,
            ),
            'Všeobecná zdravotní pojišťovna',
            '2026-08-31',
        );

        self::assertNotSame(
            $render(HealthPaymentOverviewPayload::KIND_REGULAR),
            $render(HealthPaymentOverviewPayload::KIND_CORRECTIVE),
        );
    }

    /**
     * Pátá věta se na tiskopis nevejde — a aplikace to musí ŘÍCT, ne mlčet.
     * Důvod jde jak do rozhodnutí, tak do patky vytištěné vlastní sestavy.
     */
    public function testFifthChangeFallsBackWithAStatedReason(): void
    {
        $renderer = new PayrollHealthBulkNotificationPdfRenderer();
        $payload = $this->bulkPayload('201', 5);

        $decision = $renderer->decide($payload);
        self::assertFalse($decision->usesOfficialForm());
        self::assertSame(
            HealthOfficialFormCatalog::REASON_CAPACITY_EXCEEDED,
            $decision->reasonCode,
        );
        self::assertStringContainsString('5 vět', (string) $decision->reason);

        $text = $this->text($renderer->renderPayload(
            $payload,
            'Vojenská zdravotní pojišťovna ČR',
            '2026-08-31',
            '2026-08',
        ));
        self::assertStringContainsString('Proč to není úřední tiskopis', $text);
        self::assertStringContainsString('73.51/2026', (string) $decision->reason);
        self::assertStringNotContainsString('Číslo listu', $text);
    }

    /** Každá nepodporovaná pojišťovna má konkrétní důvod, ne obecnou hlášku. */
    public function testEveryUnsupportedInsurerNamesItsOwnReason(): void
    {
        $renderer = new PayrollHealthBulkNotificationPdfRenderer();
        $expected = [
            '205' => 'ČPZP',
            '207' => 'OZP',
            '209' => 'ZPŠ',
            '211' => 'ZP MV ČR',
            '213' => 'RBP',
        ];

        foreach ($expected as $code => $needle) {
            $insurerCode = (string) $code;
            $decision = $renderer->decide($this->bulkPayload($insurerCode, 1));
            self::assertFalse($decision->usesOfficialForm(), $insurerCode);
            self::assertSame(
                HealthOfficialFormCatalog::REASON_INSURER_FORM_NOT_SHARED,
                $decision->reasonCode,
                $insurerCode,
            );
            self::assertStringContainsString(
                $needle,
                (string) $decision->reason,
                $insurerCode,
            );
        }
    }

    public function testInsurersWithSharedFormAreTheDocumentedTwo(): void
    {
        self::assertSame(
            ['111', '201'],
            array_map(
                strval(...),
                array_keys(HealthOfficialFormCatalog::insurersWithSharedForm()),
            ),
        );
    }

    public function testUnknownInsurerIsARejectedSubmissionNotAFallback(): void
    {
        $this->expectException(HealthNotificationException::class);
        (new PayrollHealthBulkNotificationPdfRenderer())
            ->decide($this->bulkPayload('999', 1));
    }

    /**
     * Údaj, který se do políčka nevejde, by se na tiskopisu překryl se
     * sousedním — proto je to tvrdá chyba s pojmenovaným polem.
     */
    public function testValueThatCannotFitIsRefusedWithTheFieldName(): void
    {
        $employer = new HealthEmployerIdentification(
            payerNumber: self::SYNTHETIC_PAYER,
            name: 'Zkušební',
            street: 'Křížová',
            houseNumber: str_repeat('9', 60),
            postalCode: '11000',
            city: 'Kroměříž',
            phone: '',
        );

        try {
            (new PayrollHealthBulkNotificationPdfRenderer())->renderPayload(
                new HealthBulkNotificationPayload('201', $employer, [$this->change('P')]),
                'Vojenská zdravotní pojišťovna ČR',
                '2026-08-31',
                '2026-08',
            );
            self::fail('Přetečený údaj musí podání zastavit.');
        } catch (HealthNotificationException $exception) {
            self::assertSame('zp_official_form_value_too_long', $exception->errorCode);
            self::assertStringContainsString('ZamCpCo', $exception->getMessage());
        }
    }

    /** Otisk šablony musí rozlišit úřední tiskopis od vlastní sestavy. */
    public function testTemplateReferenceDistinguishesBothOutputs(): void
    {
        $renderer = new PayrollHealthBulkNotificationPdfRenderer();

        self::assertStringStartsWith(
            'hoz-uni-2026:',
            $renderer->templateReference($this->bulkPayload('201', 1)),
        );
        self::assertSame(
            'payroll-health-bulk-notification.v2',
            $renderer->templateReference($this->bulkPayload('205', 1)),
        );
    }

    private function bulkPayload(string $insurerCode, int $changes): HealthBulkNotificationPayload
    {
        return new HealthBulkNotificationPayload(
            $insurerCode,
            $this->employer(),
            array_map(fn (): HealthNotificationChange => $this->change('P'), range(1, $changes)),
        );
    }

    private function employer(): HealthEmployerIdentification
    {
        return new HealthEmployerIdentification(
            payerNumber: self::SYNTHETIC_PAYER,
            name: 'Řepařská Ďůra s.r.o.',
            street: 'Křížová',
            houseNumber: '12/3',
            postalCode: '11000',
            city: 'Kroměříž',
            phone: '+420 800 123 456',
        );
    }

    private function change(string $code): HealthNotificationChange
    {
        return new HealthNotificationChange(
            changeCode: $code,
            changedOn: '2026-02-01',
            insuranceNumber: '9001011234',
            firstName: 'Ludmila',
            lastName: 'Křížová-Šťastná',
            address: new HealthNotificationAddress(
                'Na Můstku',
                '7',
                '60200',
                'Žďár nad Sázavou',
            ),
        );
    }

    private function text(string $pdfBytes): string
    {
        self::assertStringStartsWith('%PDF-', $pdfBytes);

        return (new Parser())->parseContent($pdfBytes)->getText();
    }
}
