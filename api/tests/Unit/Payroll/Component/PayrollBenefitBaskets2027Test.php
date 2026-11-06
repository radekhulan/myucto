<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Tests\Fixtures\Payroll\Payroll2027IncomeTaxRulesetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Koše osvobození benefitů podle § 6 odst. 9 písm. d) ZDP ve znění zák.
 * č. 180/2026 Sb. (od 1. 1. 2027) a regrese roku 2026. Částky v haléřích.
 */
final class PayrollBenefitBaskets2027Test extends TestCase
{
    /** Bod 2 od 2027 bez limitu: i plnění nad dřívější polovinu průměrné mzdy je celé osvobozené. */
    public function testLeisureIsUnlimitedFrom2027(): void
    {
        $split = $this->service()->split(
            PayrollBenefitExemptionBasket::NonCashLeisure,
            '2027-05-01',
            5_000_000,
            10_000_000,
        );

        self::assertNull($split->limitMinor);
        self::assertSame(10_000_000, $split->exemptMinor);
        self::assertSame(0, $split->taxableMinor);
        self::assertNull($split->remainingMinor());
        self::assertTrue($split->jsonSerialize()['unlimited']);
    }

    /** Regrese 2026: bod 2 dál do poloviny průměrné mzdy (24 483,50 Kč), stejný rozpad i JSON. */
    public function testLeisure2026KeepsHalfAverageWageLimit(): void
    {
        $split = $this->service()->split(
            PayrollBenefitExemptionBasket::NonCashLeisure,
            '2026-05-01',
            0,
            2_500_000,
        );

        self::assertSame(2_448_350, $split->limitMinor);
        self::assertSame(2_448_350, $split->exemptMinor);
        self::assertSame(51_650, $split->taxableMinor);
        self::assertArrayNotHasKey('unlimited', $split->jsonSerialize());
    }

    /** Bod 3: rekreace a zájezd „v úhrnu do výše 20000 Kč" — hranice je neostrá. */
    #[DataProvider('recreationBoundaries')]
    public function testRecreationLimitIs20000(int $usedBefore, int $amount, int $exempt, int $taxable): void
    {
        $split = $this->service()->split(
            PayrollBenefitExemptionBasket::Recreation,
            '2027-07-01',
            $usedBefore,
            $amount,
        );

        self::assertSame(Payroll2027IncomeTaxRulesetFixture::RECREATION_YEARLY_MINOR, $split->limitMinor);
        self::assertSame($exempt, $split->exemptMinor);
        self::assertSame($taxable, $split->taxableMinor);
    }

    /** @return iterable<string,array{int,int,int,int}> */
    public static function recreationBoundaries(): iterable
    {
        yield 'přesně 20 000' => [0, 2_000_000, 2_000_000, 0];
        yield 'o haléř nad' => [0, 2_000_001, 2_000_000, 1];
        yield 'doplnění do limitu' => [1_999_999, 2, 1, 1];
        yield 'vyčerpáno' => [2_000_000, 50_000, 0, 50_000];
    }

    /** Koš rekreace před rokem 2027 neexistuje — schválení spadne fail-closed. */
    public function testRecreationBasketDoesNotExistIn2026(): void
    {
        $this->expectException(PayrollRulesetException::class);

        $this->service()->split(PayrollBenefitExemptionBasket::Recreation, '2026-07-01', 0, 100);
    }

    /** Bod 1 beze změny: průměrná mzda roku z rulesetu (2027: 51 663 Kč). */
    public function testHealthLimitFollowsTheYearRuleset(): void
    {
        $service = $this->service();

        self::assertSame(
            Payroll2027IncomeTaxRulesetFixture::AVERAGE_WAGE_MINOR,
            $service->limitMinor(PayrollBenefitExemptionBasket::NonCashHealth, '2027-03-01'),
        );
        self::assertSame(
            4_896_700,
            $service->limitMinor(PayrollBenefitExemptionBasket::NonCashHealth, '2026-03-01'),
        );
    }

    public function testUnlimitedUsageHasOwnStatus(): void
    {
        $usage = new PayrollBenefitBasketUsage(
            employeeId: 1,
            employeeName: 'Syntetická osoba',
            basket: PayrollBenefitExemptionBasket::NonCashLeisure,
            limitMinor: null,
            usedMinor: 9_000_000,
            exemptMinor: 9_000_000,
            taxableMinor: 0,
            inputCount: 3,
            unfrozenCount: 0,
            negativeCount: 0,
            unlimited: true,
        );

        self::assertSame('unlimited', $usage->status());
        self::assertNull($usage->jsonSerialize()['remaining_minor']);
    }

    /** Výchozí číselník 2027 se založí až s rulesetem, který zná limit rekreace. */
    public function testDefaults2027VersionRequiresTheRecreationLimit(): void
    {
        $without = new PayrollComponentDefaults(CzechPayrollRulesets2026::provider());
        self::assertNotContains('2027-01-01', array_column($without->versions(), 'valid_from'));

        $with = new PayrollComponentDefaults(Payroll2027IncomeTaxRulesetFixture::provider());
        $rows = [];
        foreach ($with->versions() as $version) {
            if ($version['valid_from'] === '2027-01-01') {
                foreach ($version['rows'] as $row) {
                    $rows[$row['code']] = $row;
                }
            }
        }

        self::assertSame('recreation', $rows['REKREACE_ZAJEZD']['exemption_basket']);
        self::assertSame('non_cash_leisure', $rows['REKREACE_VOLNY_CAS']['exemption_basket']);
        self::assertNull($rows['KOMUNITNI_SOCIALNI_SLUZBY']['exemption_basket']);
        // § 6 odst. 7 písm. f): není příjem — mimo daň, pojistné i JMHZ.
        $annex = $rows['PECE_O_ZDRAVI_PRILOHA4'];
        self::assertSame('exempt', $annex['tax_treatment']);
        self::assertSame('not_subject_to_tax', $annex['exemption_basis']);
        self::assertSame('excluded', $annex['social_treatment']);
        self::assertSame('excluded', $annex['health_treatment']);
        self::assertSame('excluded', $annex['jmhz_treatment']);
    }

    private function service(): PayrollBenefitBasketService
    {
        return new PayrollBenefitBasketService(Payroll2027IncomeTaxRulesetFixture::provider());
    }
}
