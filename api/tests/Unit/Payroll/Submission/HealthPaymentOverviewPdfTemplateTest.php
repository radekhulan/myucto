<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthPaymentOverviewPdfTemplateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oficiální tiskopis VZP je připnutý v repozitáři, ne stahovaný.
 *
 * Dokud se tahal z `vzp.cz`, závisela na cizím webu i testovací sada — na CI
 * kvůli tomu padaly čtyři testy podání na `zp_vzp_pdf_template_changed`,
 * přestože ověřují stavový automat, ne bajty formuláře. Test drží obojí:
 * že soubor v instalaci je, a že sedí s otiskem, na který se aplikace odkazuje.
 */
final class HealthPaymentOverviewPdfTemplateTest extends TestCase
{
    public function testPinnedFormIsShippedAndMatchesItsHash(): void
    {
        $template = (new CachedHealthPaymentOverviewPdfTemplateProvider())->vzpPaymentOverview();

        self::assertStringStartsWith('%PDF-', $template->bytes);
        self::assertSame(
            CachedHealthPaymentOverviewPdfTemplateProvider::VZP_SHA256,
            hash('sha256', $template->bytes),
        );
        self::assertSame(
            CachedHealthPaymentOverviewPdfTemplateProvider::VZP_SHA256,
            $template->sha256,
        );
    }

    /**
     * Otisk se smí měnit JEN spolu se souborem. Kdyby se rozešly, aplikace by
     * tiskla nepotvrzenou verzi úředního formuláře — proto je nesoulad brzda,
     * ne varování.
     */
    public function testShippedFileIsTheOneTheCodePinsTo(): void
    {
        $path = Bootstrap::rootDir()
            . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'xsd'
            . DIRECTORY_SEPARATOR . 'vzp'
            . DIRECTORY_SEPARATOR . 'prehled-o-platbe-pojistneho-zamestnavatele.pdf';

        self::assertFileExists($path, 'Tiskopis VZP musí být součástí instalace.');
        self::assertSame(
            CachedHealthPaymentOverviewPdfTemplateProvider::VZP_SHA256,
            hash_file('sha256', $path),
        );
    }

    /** Nesmí zůstat cesta, která by se pokusila formulář stáhnout. */
    public function testProviderDoesNotReachOutToTheNetwork(): void
    {
        $source = (string) file_get_contents(
            Bootstrap::rootDir()
            . '/api/src/Service/Payroll/Submission/HealthInsurance/CachedHealthPaymentOverviewPdfTemplateProvider.php',
        );

        self::assertStringNotContainsString('GuzzleHttp', $source);
        self::assertStringNotContainsString('->request(', $source);
    }
}
