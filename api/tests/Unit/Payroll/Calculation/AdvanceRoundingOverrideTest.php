<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * W28 / V-22 — `advance.rounding.*` v rulesetu i v admin katalogu, ale výpočet
 * hardcoded.
 *
 * Parametry se administrátorovi nabízely k úpravě, uložily se, promítly se do
 * hashe rulesetu a do stopy výpočtu — a na vypočtenou zálohu neměly ŽÁDNÝ vliv.
 * To je horší než parametr nenabízet: administrátor vidí, že změnu uložil,
 * a věří jí.
 *
 * Zákonná opora té výchozí hodnoty je § 38h odst. 1 (základ do 100 Kč na celé
 * koruny nahoru, nad 100 Kč na celé stokoruny nahoru) a § 146 odst. 1 daňového
 * řádu (daň na celé koruny nahoru).
 */
final class AdvanceRoundingOverrideTest extends TestCase
{
    private const RULESET_ID = 'cz-payroll-2026.income-tax.v1';

    /** Základ 12 345,67 Kč — nad 100 Kč, tedy podle § 38h odst. 1 na stokoruny nahoru. */
    private const TAXABLE_MINOR = 1_234_567;

    public function testDefaultRulesetKeepsTheStatutoryRounding(): void
    {
        $result = $this->calculate([]);

        // 1 234 567 h → celé stokoruny nahoru → 1 240 000 h = 12 400 Kč.
        self::assertSame(1_240_000, $result->roundedTaxBaseMinorUnits);
        // 15 % z 12 400 Kč = 1 860 Kč.
        self::assertSame(186_000, $result->taxBeforeCreditsMinorUnits);
    }

    /**
     * Administrátorův override na „celé koruny nahoru“ i nad 100 Kč se musí
     * projevit — dřív se tiše ignoroval a základ zůstal na stokorunách.
     */
    public function testOverrideOfTheBaseRoundingChangesTheResult(): void
    {
        $result = $this->calculate([
            'advance.rounding.base_above_100_czk' => [
                'type' => 'text',
                'value' => 'ceil-to-1-czk',
            ],
        ]);

        // 1 234 567 h → celé koruny nahoru → 1 234 600 h = 12 346 Kč.
        self::assertSame(1_234_600, $result->roundedTaxBaseMinorUnits);
        // 15 % z 12 346 Kč = 1 851,90 Kč → na celé koruny nahoru → 1 852 Kč.
        self::assertSame(185_200, $result->taxBeforeCreditsMinorUnits);
    }

    public function testOverrideOfTheResultRoundingChangesTheResult(): void
    {
        $result = $this->calculate([
            'advance.rounding.result' => [
                'type' => 'text',
                'value' => 'ceil-to-100-czk',
            ],
        ]);

        self::assertSame(1_240_000, $result->roundedTaxBaseMinorUnits);
        // 1 860 Kč zaokrouhlených na celé stokoruny nahoru = 1 900 Kč.
        self::assertSame(190_000, $result->taxBeforeCreditsMinorUnits);
    }

    /**
     * Neznámá strategie výpočet ZASTAVÍ. Spočítat zálohu jinak, než jak je
     * pravidlo evidované, znamená vyrobit snapshot, který nesedí na vlastní
     * stopu — a to je horší než odmítnout.
     */
    public function testUnsupportedStrategyFailsClosed(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->calculate([
            'advance.rounding.result' => [
                'type' => 'text',
                'value' => 'round-half-to-even',
            ],
        ]);
    }

    /** @param array<string, array<string, mixed>> $parameters */
    private function calculate(array $parameters): \MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult
    {
        $calculator = new MonthlyAdvanceTaxCalculator(
            new PayrollRulesetProvider([$this->overridden($parameters)]),
        );

        return $calculator->calculate('2026-08-31', new MonthlyAdvanceTaxInput(
            taxableIncomeMinorUnits: self::TAXABLE_MINOR,
            signedDeclaration: false,
            claimTaxpayerCredit: false,
            otherNonRefundableCreditsMinorUnits: 0,
            childCreditMinorUnits: 0,
        ));
    }

    /** @param array<string, array<string, mixed>> $parameters */
    private function overridden(array $parameters): PayrollRulesetVersion
    {
        $default = null;
        foreach (PayrollRulesetRegistry::defaults()->versions() as $version) {
            if ($version->id === self::RULESET_ID) {
                $default = $version;
            }
        }
        self::assertInstanceOf(PayrollRulesetVersion::class, $default);

        return PayrollRulesetRegistry::merge($default, [
            'ruleset_id' => self::RULESET_ID,
            'lifecycle' => 'active',
            'reason' => 'Syntetická administrátorská změna pro deterministický test.',
            'created_by' => 900_001,
            'updated_by' => 900_001,
            'reviewed_by' => 900_001,
            'reviewed_at' => '2026-08-04 00:00:00',
            'approved_by' => 900_002,
            'approved_at' => '2026-08-05 00:00:00',
            'data' => json_encode(['parameters' => $parameters], JSON_THROW_ON_ERROR),
        ]);
    }
}
