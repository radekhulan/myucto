<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\CompanyIdNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * BUG 2 + FR 2 (vendor bugreport 2026-08-06) — jediný zdroj pravdy pro
 * normalizaci IČO/DIČ použitý cross-tenant guardem (»Faktura adresovaná jinému
 * plátci«) i zakládáním/dedup karty dodavatele.
 */
final class CompanyIdNormalizerTest extends TestCase
{
    // ── ic() ─────────────────────────────────────────────────────────────

    public function testIcPadsSevenDigitsToEight(): void
    {
        // AI extrakce u IČO začínajícího nulou vrátí číslo bez úvodní nuly.
        self::assertSame('01234567', CompanyIdNormalizer::ic('1234567'));
    }

    public function testIcAlreadyEightDigitsUnchanged(): void
    {
        self::assertSame('45274649', CompanyIdNormalizer::ic('45274649'));
    }

    public function testIcStripsNonDigits(): void
    {
        self::assertSame('45274649', CompanyIdNormalizer::ic('452 74 649'));
        self::assertSame('01234567', CompanyIdNormalizer::ic('CZ1234567')); // 'CZ' odfiltrováno, 7 číslic dopadováno
    }

    public function testIcNullOrEmptyReturnsNull(): void
    {
        self::assertNull(CompanyIdNormalizer::ic(null));
        self::assertNull(CompanyIdNormalizer::ic(''));
        self::assertNull(CompanyIdNormalizer::ic('   '));
    }

    public function testIcLongerThanEightDigitsLeftUnchanged(): void
    {
        // Skupinové/zahraniční ID delší než 8 číslic — mimo rozsah zero-padu.
        self::assertSame('123456789', CompanyIdNormalizer::ic('123456789'));
    }

    public function testIcSingleDigitPadsToEight(): void
    {
        self::assertSame('00000001', CompanyIdNormalizer::ic('1'));
    }

    // ── dic() ────────────────────────────────────────────────────────────

    public function testDicStripsSpaces(): void
    {
        self::assertSame('CZ45274649', CompanyIdNormalizer::dic('CZ 45274649'));
        self::assertSame('CZ45274649', CompanyIdNormalizer::dic('CZ45274649'));
    }

    public function testDicUppercases(): void
    {
        self::assertSame('CZ45274649', CompanyIdNormalizer::dic('cz45274649'));
    }

    public function testDicStripsDashesAndOtherPunctuation(): void
    {
        self::assertSame('CZ45274649', CompanyIdNormalizer::dic('CZ-45274649'));
    }

    public function testDicNullOrEmptyReturnsNull(): void
    {
        self::assertNull(CompanyIdNormalizer::dic(null));
        self::assertNull(CompanyIdNormalizer::dic(''));
        self::assertNull(CompanyIdNormalizer::dic('   '));
    }

    public function testDicGroupRegistrationShapeUnaffected(): void
    {
        // Tvar skupinové registrace (CZ699xxxxxx) prochází beze změny obsahu.
        self::assertSame('CZ699000123', CompanyIdNormalizer::dic('CZ699000123'));
        self::assertSame('CZ699000123', CompanyIdNormalizer::dic('CZ 699 000 123'));
    }

    // ── nameKey() ────────────────────────────────────────────────────────

    public function testNameKeyIgnoresLegalFormCaseDiacriticsAndPunctuation(): void
    {
        $key = CompanyIdNormalizer::nameKey('Syntetická Dodávka, spol. s r.o.');
        self::assertSame('synteticka dodavka', $key);
        self::assertSame($key, CompanyIdNormalizer::nameKey('SYNTETICKA DODAVKA s.r.o.'));
        self::assertSame($key, CompanyIdNormalizer::nameKey('Syntetická dodávka sro'));
        self::assertSame('vzorova', CompanyIdNormalizer::nameKey('Vzorová a.s.'));
    }

    public function testNameKeyKeepsLegalFormTokensInsideName(): void
    {
        self::assertSame('as servis', CompanyIdNormalizer::nameKey('AS Servis s.r.o.'));
        self::assertNotSame(CompanyIdNormalizer::nameKey('Alfa Beta s.r.o.'), CompanyIdNormalizer::nameKey('Alfa s.r.o.'));
    }

    public function testNameKeyEmptyReturnsNull(): void
    {
        self::assertNull(CompanyIdNormalizer::nameKey(null));
        self::assertNull(CompanyIdNormalizer::nameKey(' , . '));
    }
}
