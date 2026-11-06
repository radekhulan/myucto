<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinition;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentKind;
use MyInvoice\Service\Payroll\Component\PayrollFoodServiceTipStatute;
use MyInvoice\Tests\Fixtures\Payroll\Payroll2027IncomeTaxRulesetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * § 6 odst. 9 písm. g) ZDP od 2027: osvobozené spropitné za měsíc do podílu
 * 7 % měsíčních příjmů ze stravovacích služeb a počtu zaměstnanců, kteří
 * spropitné obdrželi. Částky v haléřích.
 */
final class PayrollFoodServiceTipStatuteTest extends TestCase
{
    #[DataProvider('perCapita')]
    public function testPerCapitaCap(int $revenue, int $recipients, int $expected): void
    {
        self::assertSame($expected, PayrollFoodServiceTipStatute::perCapitaCapMinor($revenue, $recipients));
    }

    /** @return iterable<string,array{int,int,int}> */
    public static function perCapita(): iterable
    {
        yield 'jeden číšník' => [10_000_000, 1, 700_000];
        yield 'dva číšníci' => [10_000_000, 2, 350_000];
        // 7 % z 100 000,01 Kč / 3 = 2 333,33356… Kč → haléře dolů.
        yield 'zbytek dolů' => [10_000_001, 3, 233_333];
        yield 'bez tržeb' => [0, 4, 0];
    }

    /** Úhrn podílů nikdy nepřekročí 7 % tržeb. */
    public function testSharesNeverExceedEmployerCap(): void
    {
        foreach ([1, 2, 3, 7, 11] as $recipients) {
            $revenue = 12_345_679;
            self::assertLessThanOrEqual(
                PayrollFoodServiceTipStatute::employerCapMinor($revenue),
                PayrollFoodServiceTipStatute::perCapitaCapMinor($revenue, $recipients) * $recipients,
            );
        }
    }

    public function testEffectiveFrom2027(): void
    {
        self::assertFalse(PayrollFoodServiceTipStatute::isEffective(2026));
        self::assertTrue(PayrollFoodServiceTipStatute::isEffective(2027));
        self::assertFalse(PayrollBenefitExemptionBasket::FoodServiceTips->existsIn(2026));
        self::assertTrue(PayrollBenefitExemptionBasket::FoodServiceTips->accumulatesPerMonth());
        self::assertNull(PayrollBenefitExemptionBasket::FoodServiceTips->rulesetKey());
    }

    /** Spropitné není benefit: koš nese složka druhu Ostatní, benefitní koš ji odmítne. */
    public function testTipBasketBelongsToOtherComponentKind(): void
    {
        self::assertTrue(
            PayrollBenefitExemptionBasket::FoodServiceTips->allowsComponentKind(PayrollComponentKind::OTHER),
        );
        self::assertFalse(
            PayrollBenefitExemptionBasket::FoodServiceTips->allowsComponentKind(PayrollComponentKind::BENEFIT_MEAL),
        );
        self::assertFalse(
            PayrollBenefitExemptionBasket::Recreation->allowsComponentKind(PayrollComponentKind::OTHER),
        );
    }

    /** Výchozí složka 2027: osvobozená, mimo pojistné, nadlimitní část přes zmrazený rozpad. */
    public function testDefaultTipComponent2027(): void
    {
        $rows = [];
        foreach ((new PayrollComponentDefaults(Payroll2027IncomeTaxRulesetFixture::provider()))->versions() as $version) {
            if ($version['valid_from'] === '2027-01-01') {
                foreach ($version['rows'] as $row) {
                    $rows[$row['code']] = $row;
                }
            }
        }
        $tip = $rows['SPROPITNE'];

        self::assertSame('other', $tip['component_kind']);
        self::assertSame('exempt', $tip['tax_treatment']);
        self::assertSame('excluded', $tip['social_treatment']);
        self::assertSame('excluded', $tip['health_treatment']);
        self::assertSame('food_service_tips', $tip['exemption_basket']);
        self::assertSame('periodic_benefit_limit', $tip['exemption_basis']);

        $definition = (new PayrollComponentDefinitionFactory())->fromArray([
            ...$tip,
            'social_participation_treatment' => $tip['social_treatment'],
            'health_participation_treatment' => $tip['health_treatment'],
            'accounting_debit_code' => null,
            'accounting_credit_code' => null,
            'annual_limit_minor' => null,
        ]);
        self::assertInstanceOf(PayrollComponentDefinition::class, $definition);
    }
}
