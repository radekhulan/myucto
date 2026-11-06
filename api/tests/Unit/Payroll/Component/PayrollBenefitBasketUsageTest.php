<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use PHPUnit\Framework\TestCase;

/**
 * Souhrnné čerpání koše za rok — doménová pravidla přehledu.
 *
 * Testuje se právě to, co odlišuje SOUHRN od rozpadu jednoho plnění: souhrn
 * nesmí nic dopočítávat. Nadlimitní část je zmrazený fakt ze schválení, chybějící
 * podklad se přiznává a chybějící limit se nenahrazuje odhadem.
 */
final class PayrollBenefitBasketUsageTest extends TestCase
{
    /** § 6 odst. 9 písm. d) bod 2 ZDP pro rok 2026 — polovina průměrné mzdy. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    /**
     * Nerovnost je neostrá: úhrn přesně na limitu je celý osvobozený, zbývá nula
     * a stav není „překročeno".
     */
    public function testExactlyOnTheLimitIsNotExceeded(): void
    {
        $usage = $this->usage(
            usedMinor: self::LEISURE_LIMIT_MINOR,
            exemptMinor: self::LEISURE_LIMIT_MINOR,
            taxableMinor: 0,
        );

        self::assertSame(0, $usage->remainingMinor());
        self::assertSame('approaching', $usage->status());
        self::assertFalse($usage->splitDrift());
    }

    /** Překročení se pozná ze zmrazené nadlimitní části, ne z dnešního limitu. */
    public function testFrozenTaxablePartMakesTheRowExceeded(): void
    {
        $usage = $this->usage(
            usedMinor: self::LEISURE_LIMIT_MINOR + 100,
            exemptMinor: self::LEISURE_LIMIT_MINOR,
            taxableMinor: 100,
        );

        self::assertSame('exceeded', $usage->status());
        self::assertSame(0, $usage->remainingMinor());
    }

    /**
     * Nadlimitní část je fakt ze schválení, takže platí i bez dnešního limitu.
     * Kdyby ji chybějící ruleset přebil, přehled by zamlčel jediný jistý údaj.
     */
    public function testExceededOutranksMissingLimit(): void
    {
        $usage = $this->usage(
            usedMinor: 3_000_000,
            exemptMinor: 2_448_350,
            taxableMinor: 551_650,
            limitMinor: null,
        );

        self::assertSame('exceeded', $usage->status());
        self::assertNull($usage->remainingMinor());
    }

    /** Bez limitu se netvrdí ani „zbývá", ani „blíží se" — fail-closed. */
    public function testMissingLimitIsReportedInsteadOfGuessed(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 1_000_000,
            taxableMinor: 0,
            limitMinor: null,
        );

        self::assertSame('limit_unavailable', $usage->status());
        self::assertNull($usage->remainingMinor());
        self::assertFalse($usage->splitDrift());
    }

    /**
     * Vstup schválený dřív, než koše existovaly, rozpad nemá. Nedopočítává se —
     * řádek se označí jako neúplný.
     */
    public function testInputWithoutFrozenSplitMakesTheRowIncomplete(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 500_000,
            taxableMinor: 0,
            unfrozenCount: 1,
        );

        self::assertSame('incomplete', $usage->status());
        self::assertFalse(
            $usage->splitDrift(),
            'Chybějící podklad není důkaz změny limitu.',
        );
    }

    /** Práh upozornění je 80 % koše a je neostrý. */
    public function testApproachingStartsAtEightyPercent(): void
    {
        $justBelow = $this->usage(
            usedMinor: 1_958_679,
            exemptMinor: 1_958_679,
            taxableMinor: 0,
        );
        $atThreshold = $this->usage(
            usedMinor: 1_958_680,
            exemptMinor: 1_958_680,
            taxableMinor: 0,
        );

        self::assertSame('ok', $justBelow->status());
        self::assertSame('approaching', $atThreshold->status());
    }

    /**
     * Zmrazený součet se rozešel s dnešním limitem — limit se v rulesetu po
     * schválení změnil. Přehled to oznámí a dál ukazuje zmrazená čísla.
     */
    public function testDriftIsReportedWhenFrozenExemptDoesNotMatchTodaysLimit(): void
    {
        $usage = $this->usage(
            usedMinor: 2_500_000,
            exemptMinor: 2_000_000,
            taxableMinor: 0,
        );

        self::assertTrue($usage->splitDrift());
        self::assertSame(
            2_000_000,
            $usage->jsonSerialize()['exempt_minor'],
            'Zmrazená částka se dnešním limitem nepřepisuje.',
        );
    }

    /**
     * Řádek musí ŘÍCT, za jaké období limit platí — jinak se prázdný sloupec
     * limitu čte jako „v pořádku".
     *
     * Rozhodné období součtu a období limitu nejsou totéž. U příspěvku na
     * stravování se sčítá měsíc (mzdový vstup je měsíční), ale limit je podle
     * § 6 odst. 9 písm. b) ZDP za JEDNU SMĚNU. Bez tohohle údaje by měsíční
     * přehled ukázal součet vedle prázdného limitu a nic by nevysvětlil.
     */
    public function testRowNamesThePeriodItsLimitAppliesTo(): void
    {
        $bases = [];
        foreach (PayrollBenefitExemptionBasket::cases() as $basket) {
            $bases[$basket->value] = $basket->limitBasis();
            self::assertContains(
                $basket->limitBasis(),
                PayrollBenefitBasketUsage::LIMIT_BASES,
                'Základ limitu musí být z domény, kterou zná klient i slovník.',
            );
        }

        self::assertSame([
            'non_cash_health' => 'tax_year',
            'non_cash_leisure' => 'tax_year',
            'old_age_savings' => 'tax_year',
            'meal_per_shift' => 'per_shift',
            'temporary_accommodation' => 'calendar_month',
            'recreation' => 'tax_year',
            'food_service_tips' => 'calendar_month',
        ], $bases);
    }

    /**
     * MĚSÍČNÍ ŘÁDEK STRAVNÉHO NESMÍ TVRDIT „v limitu".
     *
     * Součet za měsíc se proti limitu za směnu poměřit nedá, takže se limit
     * netvrdí a řádek jde ven jako `limit_unavailable` se základem `per_shift`.
     * Kdyby se stav spočítal z měsíčního součtu, přehled by u překročeného
     * stravného svítil zeleně.
     */
    public function testMonthlyMealRowNeverClaimsItIsWithinTheLimit(): void
    {
        $usage = new PayrollBenefitBasketUsage(
            employeeId: 1,
            employeeName: 'Syntetická osoba',
            basket: PayrollBenefitExemptionBasket::MealPerShift,
            limitMinor: null,
            usedMinor: 250_000,
            exemptMinor: 250_000,
            taxableMinor: 0,
            inputCount: 1,
            unfrozenCount: 0,
            negativeCount: 0,
        );

        self::assertSame('limit_unavailable', $usage->status());
        self::assertNull($usage->remainingMinor());
        self::assertFalse($usage->splitDrift());

        $json = $usage->jsonSerialize();
        self::assertSame('per_shift', $json['limit_basis']);
        self::assertNull($json['limit_minor']);
        self::assertNull($json['remaining_minor']);
        self::assertSame(250_000, $json['used_minor']);
    }

    /** Přechodné ubytování měsíční limit MÁ, takže se u něj „zbývá" tvrdit smí. */
    public function testMonthlyAccommodationRowStillReportsARealLimit(): void
    {
        $usage = new PayrollBenefitBasketUsage(
            employeeId: 1,
            employeeName: 'Syntetická osoba',
            basket: PayrollBenefitExemptionBasket::TemporaryAccommodation,
            limitMinor: 350_000,
            usedMinor: 200_000,
            exemptMinor: 200_000,
            taxableMinor: 0,
            inputCount: 1,
            unfrozenCount: 0,
            negativeCount: 0,
        );

        self::assertSame('ok', $usage->status());
        self::assertSame(150_000, $usage->remainingMinor());
        self::assertSame('calendar_month', $usage->jsonSerialize()['limit_basis']);
    }

    /** Záporná oprava v koši rovnost rozbije očekávaně — drift by byl planý poplach. */
    public function testNegativeCorrectionSuppressesDrift(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 1_500_000,
            taxableMinor: 0,
            negativeCount: 1,
        );

        self::assertFalse($usage->splitDrift());
    }

    private function usage(
        int $usedMinor,
        int $exemptMinor,
        int $taxableMinor,
        ?int $limitMinor = self::LEISURE_LIMIT_MINOR,
        int $unfrozenCount = 0,
        int $negativeCount = 0,
    ): PayrollBenefitBasketUsage {
        return new PayrollBenefitBasketUsage(
            employeeId: 1,
            employeeName: 'Syntetická osoba',
            basket: PayrollBenefitExemptionBasket::NonCashLeisure,
            limitMinor: $limitMinor,
            usedMinor: $usedMinor,
            exemptMinor: $exemptMinor,
            taxableMinor: $taxableMinor,
            inputCount: 1,
            unfrozenCount: $unfrozenCount,
            negativeCount: $negativeCount,
        );
    }
}
