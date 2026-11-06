<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;

/**
 * Zástupná sada daně z příjmů pro rok 2027, dokud v repu není dodaná
 * `CzechPayrollRulesets2027`.
 *
 * Bere ověřenou sadu 2026 a mění jen to, co testy slevy za umístění dítěte
 * a košů benefitů 2027 potřebují, s hodnotami z vyhlášených předpisů:
 *
 *  - minimální mzda 24 900 Kč (NV č. 164/2026 Sb., sdělení MPSV č. 171/2026 Sb.),
 *    § 35c odst. 4 a § 35d odst. 4 z ní dělají práh bonusu 6× ročně
 *    (149 400 Kč) a polovinu měsíčně (12 450 Kč),
 *  - průměrná mzda 51 663 Kč (§ 23b odst. 4 ZPSZ z NV č. 177/2026 Sb.) jako
 *    roční strop zdravotních plnění (§ 6 odst. 9 písm. d) bod 1 ZDP),
 *  - rekreace a zájezdy 20 000 Kč za zdaňovací období (§ 6 odst. 9 písm. d)
 *    bod 3 ZDP ve znění zák. č. 180/2026 Sb.).
 *
 * Není to dodaná sada (jiný otisk obsahu), účinnost si proto platí
 * syntetickým schválením jako každý zákaznický přepis.
 */
final class Payroll2027IncomeTaxRulesetFixture
{
    public const MINIMUM_WAGE_MINOR = 2_490_000;
    public const AVERAGE_WAGE_MINOR = 5_166_300;
    public const RECREATION_YEARLY_MINOR = 2_000_000;

    /**
     * @param array<string,PayrollRuleValue|null> $overrides `null` klíč odebere
     */
    public static function incomeTax(array $overrides = []): PayrollRulesetVersion
    {
        $base = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-12-01');
        $parameters = $base->parameters;
        foreach ([
            'minimum_wage.monthly' => PayrollRuleValue::moneyMinor(self::MINIMUM_WAGE_MINOR),
            'bonus.minimum_income.monthly' => PayrollRuleValue::moneyMinor(1_245_000),
            'bonus.minimum_income.yearly' => PayrollRuleValue::moneyMinor(14_940_000),
            'benefit_exemption.non_cash_health.yearly' =>
                PayrollRuleValue::moneyMinor(self::AVERAGE_WAGE_MINOR),
            'benefit_exemption.recreation.yearly' =>
                PayrollRuleValue::moneyMinor(self::RECREATION_YEARLY_MINOR),
            ...$overrides,
        ] as $key => $value) {
            if ($value === null) {
                unset($parameters[$key]);
                continue;
            }
            $parameters[$key] = $value;
        }
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            'fixture.cz.income-tax.2027',
            '2027.1',
            PayrollRulesetDomain::IncomeTax,
            '2027-01-01',
            '2027-12-31',
            PayrollRulesetLifecycle::Active,
            $base->capability,
            $base->sources,
            $parameters,
            new RulesetApproval(
                'synthetic-independent-reviewer',
                '2026-10-28',
                'synthetic-independent-approver',
                '2026-10-28',
                'Synthetic approval used only by deterministic tests of the 2027 rules.',
            ),
            $base->technicalReview,
        );
    }

    /** Registry s ověřenými sadami 2026 a zástupnou sadou daně z příjmů 2027. */
    public static function provider(): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            ...CzechPayrollRulesets2026::provider()->versions(),
            self::incomeTax(),
        ]);
    }
}
