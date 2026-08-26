<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Regzel\RegzelTaxOfficeCode;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegzelTaxOfficeCodeTest extends TestCase
{
    #[DataProvider('validCodes')]
    public function testAcceptsOfficialTaxOfficeCodes(string $code): void
    {
        self::assertSame($code, RegzelTaxOfficeCode::required($code));
    }

    /** @return iterable<string,array{string}> */
    public static function validCodes(): iterable
    {
        yield 'lower bound' => ['2000'];
        yield 'ordinary office' => ['3000'];
        yield 'specialized office' => ['4000'];
    }

    #[DataProvider('invalidCodes')]
    public function testRejectsEpoAndOutOfRangeCodes(string $code): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::required($code);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'EPO code' => ['451'];
        yield 'workplace instead of office' => ['3001'];
        yield 'below range' => ['1999'];
        yield 'above range' => ['7001'];
        yield 'general directorate is not tax office' => ['7000'];
        yield 'letters' => ['FU01'];
    }

    public function testOptionalWorkplaceAndSuggestionNeverInventValues(): void
    {
        self::assertNull(RegzelTaxOfficeCode::optional(null));
        self::assertNull(RegzelTaxOfficeCode::suggestion('451'));
        self::assertSame('3001', RegzelTaxOfficeCode::suggestion(' 3001 '));
        self::assertNull(RegzelTaxOfficeCode::suggestion([]));
    }

    public function testWorkplaceIsRequiredExceptForSpecializedTaxOffice(): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::validatePair('3000', null);
    }

    public function testSpecializedTaxOfficeMayOmitWorkplace(): void
    {
        RegzelTaxOfficeCode::validatePair('4000', null);
        $this->addToAssertionCount(1);
    }

    public function testRejectsWorkplaceBelongingToDifferentOffice(): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::validatePair('2300', '3001');
    }

    public function testRejectsUnknownWorkplaceFromOtherwiseValidRange(): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::optional('2304');
    }

    public function testSpecializedTaxOfficeRejectsWorkplace(): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::validatePair('4000', '4001');
    }

    public function testRejectsNonScalarInputWithoutPhpWarning(): void
    {
        $this->expectException(RegzelValidationException::class);
        RegzelTaxOfficeCode::required([]);
    }
}
