<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier as Lines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sdílená tabulka „řádek přiznání DPH → kód zařazení MyÚčta" pro převody.
 */
final class VatReturnLineClassifierTest extends TestCase
{
    public function testDomesticReverseSaleCodes(): void
    {
        self::assertTrue(Lines::isDomesticReverseSale(['25s']));
        self::assertTrue(Lines::isDomesticReverseSale([null, '1', '25s5']));
        self::assertTrue(Lines::isDomesticReverseSale(['25s3']));
        self::assertFalse(Lines::isDomesticReverseSale([null, '1', '26s', '22', '5']));
        self::assertFalse(Lines::isDomesticReverseSale([]));
    }

    /** @return iterable<string,array{int,?string,?string}> */
    public static function saleLines(): iterable
    {
        yield 'dodání zboží do EU' => [20, null, '20'];
        yield 'služba do EU' => [21, null, '22'];
        yield 'vývoz' => [22, null, '26'];
        yield 'nový dopravní prostředek' => [23, null, '23n'];
        yield 'zasílání zboží' => [24, null, '24z'];
        yield 'tuzemský přenos bez předmětu = stavební práce' => [25, null, '25s'];
        yield 'tuzemský přenos prázdný předmět' => [25, '', '25s'];
        yield 'tuzemský přenos stavební práce' => [25, '4', '25s'];
        yield 'tuzemský přenos odpad' => [25, '5', '25s5'];
        yield 'tuzemský přenos nemovitost' => [25, '3', '25s3'];
        yield 'tuzemský přenos jiný předmět' => [25, '1', null];
        yield 'služba mimo tuzemsko' => [26, null, '26s'];
        yield 'třístranný obchod' => [31, null, '31'];
        yield 'osvobozené' => [50, null, '3'];
        yield 'tuzemsko se kóduje sazbou' => [1, null, null];
        yield 'mimo koeficient samostatně' => [51, null, null];
        yield 'odpočet není plnění na výstupu' => [40, null, null];
    }

    #[DataProvider('saleLines')]
    public function testSaleCode(int $line, ?string $subject, ?string $expected): void
    {
        self::assertSame($expected, Lines::saleCode($line, $subject));
    }

    /** @return iterable<string,array{int,?string,?string}> */
    public static function selfAssessmentLines(): iterable
    {
        yield 'pořízení zboží z EU' => [3, null, '23'];
        yield 'pořízení zboží z EU snížená' => [4, null, '23'];
        yield 'služba z EU' => [5, null, '24e'];
        yield 'dovoz' => [7, null, '25'];
        yield 'tuzemský přenos stavební práce' => [10, null, '5'];
        yield 'tuzemský přenos snížená, prázdný předmět' => [11, '', '5'];
        yield 'tuzemský přenos odpad' => [10, '5', '5c'];
        yield 'tuzemský přenos nemovitost' => [10, '3', '5d'];
        yield 'tuzemský přenos neznámý předmět' => [10, '9', null];
        yield 'služba ze třetí země' => [12, null, '24'];
        yield 'předmět se u jiných řádků nečte' => [3, '9', '23'];
        yield 'ř. 9 není samovyměření' => [9, null, null];
    }

    #[DataProvider('selfAssessmentLines')]
    public function testSelfAssessmentCode(int $line, ?string $subject, ?string $expected): void
    {
        self::assertSame($expected, Lines::selfAssessmentCode($line, $subject));
        self::assertSame(in_array($line, [3, 4, 5, 6, 7, 8, 10, 11, 12, 13], true), Lines::isSelfAssessmentOutput($line));
    }

    public function testSelfAssessmentPairOnlyAcceptsWholePairs(): void
    {
        self::assertSame('24e', Lines::selfAssessmentCodeForPair([5, 6]));
        self::assertSame('5c', Lines::selfAssessmentCodeForPair([10, 11], '5'));
        self::assertNull(Lines::selfAssessmentCodeForPair([5]));
        self::assertNull(Lines::selfAssessmentCodeForPair([5, 6, 43]));
        self::assertNull(Lines::selfAssessmentCodeForPair(null));
    }

    public function testAssetSaleCodeKnowsOnlyCurrentRates(): void
    {
        self::assertSame('1m', Lines::assetSaleCode(21.0));
        self::assertSame('2m', Lines::assetSaleCode(12.0));
        self::assertNull(Lines::assetSaleCode(15.0));
    }

    public function testRateClassFromLines(): void
    {
        self::assertSame(Lines::RATE_BASE, Lines::rateClass([43, 47]));
        self::assertSame(Lines::RATE_REDUCED, Lines::rateClass([4, 44]));
        self::assertNull(Lines::rateClass([20]));
        self::assertSame(15.0, Lines::domesticRate(Lines::RATE_REDUCED, '2023-12-31'));
        self::assertSame(12.0, Lines::domesticRate(Lines::RATE_REDUCED, '2024-01-01'));
        self::assertSame(20.0, Lines::domesticRate(Lines::RATE_BASE, '2012-06-30'));
    }

    public function testSaleFromLineSet(): void
    {
        self::assertSame(['in_return' => false, 'code' => null, 'domestic' => false, 'asset_sale' => false], Lines::saleFromLineSet([]));
        self::assertSame(['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => false], Lines::saleFromLineSet([2]));
        self::assertSame(['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => true], Lines::saleFromLineSet([1, 51]));
        self::assertSame(['in_return' => true, 'code' => '3m', 'domestic' => false, 'asset_sale' => false], Lines::saleFromLineSet([50, 51]));
        self::assertSame(['in_return' => true, 'code' => '25s', 'domestic' => false, 'asset_sale' => false], Lines::saleFromLineSet([25]));
        self::assertNull(Lines::saleFromLineSet([1, 20]));
        self::assertNull(Lines::saleFromLineSet([51]));
    }

    /** Ř. 26: tuzemský odběratel (CZ nebo bez země) = § 71i, zahraniční = 26s; jiné kódy beze změny. */
    public function testLine26CodeForCustomer(): void
    {
        self::assertSame('26s', Lines::saleCode(26));
        self::assertSame('71i', Lines::saleCodeForCustomer(Lines::saleCode(26), 'CZ'));
        self::assertSame('71i', Lines::saleCodeForCustomer('26s', ' cz '));
        self::assertSame('71i', Lines::saleCodeForCustomer('26s', null));
        self::assertSame('71i', Lines::saleCodeForCustomer('26s', ''));
        self::assertSame('26s', Lines::saleCodeForCustomer('26s', 'US'));
        self::assertSame('26s', Lines::saleCodeForCustomer('26s', 'SK'));
        foreach (['3', '22', '26', '26c', '25s', null] as $code) {
            self::assertSame($code, Lines::saleCodeForCustomer($code, 'CZ'));
        }
    }

    public function testPurchaseFromLineSet(): void
    {
        self::assertSame(['in_return' => true, 'deduction' => 'reduced', 'reverse' => false, 'code' => null, 'fixed_asset' => true], Lines::purchaseFromLineSet([40, 47], true));
        self::assertSame(['in_return' => true, 'deduction' => 'full', 'reverse' => true, 'code' => '24e', 'fixed_asset' => false], Lines::purchaseFromLineSet([5, 43], false));
        self::assertSame(['in_return' => true, 'deduction' => 'none', 'reverse' => true, 'code' => '5', 'fixed_asset' => false], Lines::purchaseFromLineSet([10, 11], false));
        self::assertNull(Lines::purchaseFromLineSet([43, 44], false), 'Odpočet ze samovyměření bez výstupu');
        self::assertNull(Lines::purchaseFromLineSet([3, 5, 43], false), 'Dva různé druhy samovyměření');
        self::assertNull(Lines::purchaseFromLineSet([10, 40], false), 'Samovyměření s tuzemským odpočtem');
        self::assertNull(Lines::purchaseFromLineSet([42], false), 'Dovoz celním úřadem');
    }
}
