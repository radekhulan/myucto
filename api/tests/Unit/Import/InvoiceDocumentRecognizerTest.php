<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Import;

use MyInvoice\Service\Import\InvoiceDocumentRecognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vstupní filtr příloh z e-mailu: pustit dál jen doklad adresovaný NAŠÍ firmě.
 *
 * Testy hlídají obě podmínky zvlášť (typ dokladu × identita) — pravidlo, které
 * propustí leták jen proto, že v patičce nese naše IČO, by stálo AI call a šum
 * ve frontě příchozích dokladů.
 */
final class InvoiceDocumentRecognizerTest extends TestCase
{
    private const IDENTITY = [
        'ic' => '01234567',
        'dic' => 'CZ01234567',
        'name' => 'Zkušební Firma s.r.o.',
    ];

    private InvoiceDocumentRecognizer $recognizer;

    protected function setUp(): void
    {
        $this->recognizer = new InvoiceDocumentRecognizer();
    }

    public function testAcceptsInvoiceWithOurCompanyId(): void
    {
        $text = "FAKTURA - DAŇOVÝ DOKLAD č. 2026001\nOdběratel: Někdo Jiný a.s., IČO: 01234567\nCelkem 1 210,00 Kč";
        $result = $this->recognizer->recognize($text, self::IDENTITY);

        self::assertTrue($result['is_invoice']);
        self::assertSame('ic', $result['matched_by']);
        self::assertSame(100.0, $result['match_score']);
    }

    /** IČO bývá v PDF vysázené s mezerami mezi trojicemi číslic. */
    public function testCompanyIdMatchesDespiteSpaces(): void
    {
        $result = $this->recognizer->recognize('Faktura č. 5 — IČ: 012 345 67', self::IDENTITY);

        self::assertTrue($result['is_invoice']);
        self::assertSame('ic', $result['matched_by']);
    }

    /**
     * IČO bez vedoucí nuly (7 číslic) je týž subjekt — normalizace na 8 míst je
     * důvod, proč vůbec {@see \MyInvoice\Support\CompanyIdNormalizer} existuje.
     */
    public function testCompanyIdMatchesWithoutLeadingZero(): void
    {
        $result = $this->recognizer->recognize('Faktura, IČO 1234567', self::IDENTITY);

        self::assertTrue($result['is_invoice']);
        self::assertSame('ic', $result['matched_by']);
    }

    /** Delší číslo, které naše IČO jen OBSAHUJE, shoda není. */
    public function testCompanyIdDoesNotMatchInsideLongerNumber(): void
    {
        $result = $this->recognizer->recognize('Faktura, číslo účtu 9012345678/0100', [
            'ic' => '01234567', 'dic' => null, 'name' => null,
        ]);

        self::assertFalse($result['is_invoice']);
        self::assertNull($result['matched_by']);
    }

    public function testMatchesVatIdWithSpaces(): void
    {
        $result = $this->recognizer->recognize('Daňový doklad, DIČ: CZ 01234567', [
            'ic' => null, 'dic' => 'CZ01234567', 'name' => null,
        ]);

        self::assertTrue($result['is_invoice']);
        self::assertSame('dic', $result['matched_by']);
    }

    /** Bez IČO i DIČ musí stačit název firmy — a to i bez právní formy a diakritiky. */
    public function testMatchesCompanyNameWithoutLegalFormAndDiacritics(): void
    {
        $result = $this->recognizer->recognize(
            'FAKTURA c. 7 Odberatel: Zkusebni Firma, Praha 1',
            ['ic' => null, 'dic' => null, 'name' => 'Zkušební Firma s.r.o.'],
        );

        self::assertTrue($result['is_invoice']);
        self::assertSame('name', $result['matched_by']);
        self::assertGreaterThanOrEqual(InvoiceDocumentRecognizer::NAME_MATCH_THRESHOLD, (float) $result['match_score']);
    }

    /** Jiná firma stejného oboru nesmí projít na název. */
    public function testRejectsDifferentCompanyName(): void
    {
        $result = $this->recognizer->recognize(
            'Faktura c. 7 Odberatel: Uplne Jina Spolecnost, Brno',
            ['ic' => null, 'dic' => null, 'name' => 'Zkušební Firma s.r.o.'],
        );

        self::assertFalse($result['is_invoice']);
    }

    /** Naše IČO v patičce newsletteru bez slova „faktura" projít nesmí. */
    public function testRejectsDocumentWithoutDocumentKeyword(): void
    {
        $result = $this->recognizer->recognize(
            'Novinky z našeho e-shopu. Adresát: IČO 01234567. Odhlásit odběr.',
            self::IDENTITY,
        );

        self::assertFalse($result['is_invoice']);
        self::assertFalse($result['has_document_keyword']);
        self::assertSame('ic', $result['matched_by']);
    }

    /** Faktura adresovaná někomu jinému projít nesmí, i když je to faktura. */
    public function testRejectsInvoiceAddressedToSomeoneElse(): void
    {
        $result = $this->recognizer->recognize(
            'FAKTURA - DAŇOVÝ DOKLAD, odběratel IČO: 99887766, DIČ CZ99887766, Cizí Firma a.s.',
            self::IDENTITY,
        );

        self::assertFalse($result['is_invoice']);
        self::assertTrue($result['has_document_keyword']);
        self::assertNull($result['matched_by']);
    }

    /** Sken bez textové vrstvy — musí to říct, ne tiše propadnout. */
    public function testRejectsEmptyTextWithScanReason(): void
    {
        $result = $this->recognizer->recognize('   ', self::IDENTITY);

        self::assertFalse($result['is_invoice']);
        self::assertStringContainsString('textovou vrstvu', $result['reason']);
    }

    #[DataProvider('documentKeywords')]
    public function testAcceptsDocumentKeywordVariants(string $keyword): void
    {
        $result = $this->recognizer->recognize($keyword . ' — IČO 01234567', self::IDENTITY);

        self::assertTrue($result['is_invoice'], $keyword);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function documentKeywords(): iterable
    {
        yield 'faktura' => ['Faktura č. 1'];
        yield 'danovy doklad' => ['Daňový doklad'];
        yield 'dobropis' => ['Opravný daňový doklad — dobropis'];
        yield 'uctenka' => ['Účtenka za nákup'];
        yield 'invoice' => ['Invoice No. 42'];
        yield 'zalohova faktura' => ['Zálohová faktura'];
    }
}
