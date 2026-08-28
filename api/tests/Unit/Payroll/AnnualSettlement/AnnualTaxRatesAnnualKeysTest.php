<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementUnavailableException;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use PHPUnit\Framework\TestCase;

/**
 * Roční klíče v rulesetu daně z příjmů se čtou PŘÍMO, ne dvanáctinásobkem.
 *
 * Dvanáctina podle § 35d odst. 2 platí pro slevy a daňové zvýhodnění. Na prahy
 * výplaty nedopadá vůbec a odvození by tam tiše lhalo — právě tomu mají roční
 * klíče zabránit, takže to musí být vidět jako spustitelné tvrzení.
 */
final class AnnualTaxRatesAnnualKeysTest extends TestCase
{
    public function testAnnualValuesComeFromTheRulesetAndNotFromTwelveTimesMonthly(): void
    {
        $ruleset = self::incomeTax();
        $rates = AnnualTaxRates::forRuleset($ruleset);

        // § 35c odst. 3 — „alespoň 100 Kč". Dvanáctinásobek měsíčního prahu
        // podle § 35d odst. 4 by dal 600 Kč a poplatník by o bonus mezi tím přišel.
        self::assertSame(10_000, $rates->bonusMinimumAmountMinorUnits);
        self::assertNotSame(
            ((int) $ruleset->parameter('bonus.minimum_amount.monthly')->value)
                * AnnualTaxRates::MONTHS_IN_YEAR,
            $rates->bonusMinimumAmountMinorUnits,
        );

        // § 38ch odst. 5 / § 35d odst. 8 — stejné číslo jako měsíční bonus,
        // ale opačný operátor. Proto je to vlastní klíč, ne sdílená hodnota.
        self::assertSame(5_000, $rates->payoutThresholdMinorUnits);
        self::assertSame(
            (int) $ruleset->parameter('bonus.minimum_amount.monthly')->value,
            $rates->payoutThresholdMinorUnits,
        );
        self::assertTrue(AnnualSettlementStatute::isPayable(5_001));
        self::assertFalse(AnnualSettlementStatute::isPayable(5_000));
        self::assertTrue(AnnualSettlementStatute::isAnnualBonusAmountEligible(10_000));
        self::assertFalse(AnnualSettlementStatute::isAnnualBonusAmountEligible(9_999));

        // § 35bb — roční sleva na manžela, zdvojnásobení u přiznaného nároku na
        // průkaz ZTP/P a limit vlastního příjmu manžela.
        self::assertSame(2_484_000, $rates->spouseCreditMinorUnits);
        self::assertSame(2, $rates->spouseCreditZtpPMultiplier);
        self::assertSame(4_968_000, $rates->spouseCreditMinorUnits * $rates->spouseCreditZtpPMultiplier);
        self::assertSame(6_800_000, $rates->spouseIncomeLimitMinorUnits);

        $snapshot = $rates->toArray();
        self::assertSame('ruleset', $snapshot['annual_keys_source']);
        self::assertSame(5_000, $snapshot['payout_threshold_minor_units']);
        self::assertSame(10_000, $snapshot['bonus_minimum_amount_minor_units']);
    }

    /**
     * Práh výplaty je zároveň zákonným číslem v kódu a administrovatelnou
     * hodnotou v rulesetu. Rozejdou-li se, není jasné, které platí — a zúčtování
     * se proto zastaví, místo aby jedno z nich tiše vyhrálo.
     */
    public function testDivergedPayoutThresholdStopsTheSettlement(): void
    {
        $this->expectException(AnnualSettlementUnavailableException::class);
        $this->expectExceptionMessage('settlement.payout_threshold');

        AnnualTaxRates::forRuleset(self::withParameter(
            'settlement.payout_threshold',
            PayrollRuleValue::moneyMinor(10_000),
        ));
    }

    public function testDivergedAnnualBonusMinimumStopsTheSettlement(): void
    {
        $this->expectException(AnnualSettlementUnavailableException::class);
        $this->expectExceptionMessage('bonus.minimum_amount.yearly');

        AnnualTaxRates::forRuleset(self::withParameter(
            'bonus.minimum_amount.yearly',
            PayrollRuleValue::moneyMinor(60_000),
        ));
    }

    /** Brána vztahu 12× nad `bonus.minimum_income` platí dál. */
    public function testBrokenTwelveTimesRelationStillStopsTheSettlement(): void
    {
        $this->expectException(AnnualSettlementUnavailableException::class);
        $this->expectExceptionMessage('dvanáctinásobek');

        AnnualTaxRates::forRuleset(self::withParameter(
            'bonus.minimum_income.yearly',
            PayrollRuleValue::moneyMinor(13_000_000),
        ));
    }

    private static function incomeTax(): PayrollRulesetVersion
    {
        return CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');
    }

    /**
     * Přepis jedné hodnoty vyrobí zákaznický override, ne dodanou sadu — účinný
     * proto musí být se schválením, jinak by konstruktor odmítl `active`.
     */
    private static function withParameter(string $key, PayrollRuleValue $value): PayrollRulesetVersion
    {
        $delivered = self::incomeTax();
        $parameters = $delivered->parameters;
        $parameters[$key] = $value;
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $delivered->id,
            $delivered->version,
            $delivered->domain,
            $delivered->effectiveFrom,
            $delivered->effectiveTo,
            PayrollRulesetLifecycle::Active,
            $delivered->capability,
            $delivered->sources,
            $parameters,
            $delivered->approval,
            $delivered->technicalReview,
        );
    }
}
