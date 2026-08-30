<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Táž sazba na dvou místech se dřív nebo později rozejde.
 *
 * Mzda se do 8/2026 počítala ze DVOU nezávislých sad: modul Mzdy z rulesetu,
 * starší mzdová rekapitulace ({@see \MyInvoice\Service\Accounting\Payroll\PayrollCalculator})
 * z literálů v `TaxConstants::TABLE`. Duplicitní byly sazby pojistného i zálohové
 * daně, minimální mzda, měsíční hranice § 38h odst. 2 a rozhodný příjem — a tenhle
 * test hlídal jen dvě sazby zaměstnavatele.
 *
 * Dnes je to rozdělené na dvě skupiny a test hlídá obě:
 *
 *  1. MZDOVÉ hodnoty se z `TABLE` odstranily a pro roky s rulesetem je zrcadlí
 *     {@see TaxConstants::withDerived()}. Test ověřuje, že zrcadlení opravdu
 *     běží a že mapuje SPRÁVNÉ parametry — překlep v názvu klíče by jinak
 *     protekl jako tichá změna sazby.
 *  2. DAŇOVÉ hodnoty (`social_max_base`, slevy, limity) v `TABLE` zůstávají:
 *     čte je i DPFO a per-klíč override v tabulce `tax_constants` je u nich
 *     živá, testovaná funkce. Tam je jediná obrana právě tenhle guard.
 *
 * Guard běží nad KAŽDÝM rokem, pro který ruleset existuje. Rok 2024 ruleset
 * nemá a hodnoty si drží sám — ověřuje se, že mu je zrcadlení nepřepsalo.
 */
final class TaxConstantsPayrollRatesMatchRulesetTest extends TestCase
{
    /** @var non-empty-list<int> $YEARS */
    private const YEARS = [2025, 2026];

    /**
     * Sazby, které starší modul počítá — všechny, ne jen ty dvě zaměstnavatelské.
     *
     * @var non-empty-array<string, array{0:PayrollRulesetDomain, 1:string}>
     */
    private const RATES = [
        'employee_social'  => [PayrollRulesetDomain::SocialInsurance, 'employee.rate.ordinary'],
        'employer_social'  => [PayrollRulesetDomain::SocialInsurance, 'employer.rate.ordinary'],
        'employee_health'  => [PayrollRulesetDomain::HealthInsurance, 'employee.rate'],
        'employer_health'  => [PayrollRulesetDomain::HealthInsurance, 'employer.rate'],
        'health_total'     => [PayrollRulesetDomain::HealthInsurance, 'total.rate'],
        'advance_tax'      => [PayrollRulesetDomain::IncomeTax, 'advance.low_rate'],
        'advance_tax_high' => [PayrollRulesetDomain::IncomeTax, 'advance.high_rate'],
    ];

    /**
     * Měsíční / roční částky, které starší modul čte z kořene sady.
     *
     * @var non-empty-array<string, array{0:PayrollRulesetDomain, 1:string}>
     */
    private const AMOUNTS = [
        'minimum_wage' => [PayrollRulesetDomain::EmploymentThresholds, 'minimum_wage.monthly_40h_week'],
        'advance_tax_high_threshold' => [PayrollRulesetDomain::IncomeTax, 'advance.high_threshold.monthly'],
        'sickness_participation_threshold' =>
            [PayrollRulesetDomain::SocialInsurance, 'participation.small_scale.minimum'],
    ];

    public function testPayrollRatesComeFromTheOrdinaryPayrollRulesetRates(): void
    {
        foreach (self::YEARS as $year) {
            $payroll = TaxConstants::forYear($year)['payroll'];
            self::assertIsArray($payroll);

            foreach (self::RATES as $key => [$domain, $parameter]) {
                self::assertSame(
                    $this->rate($domain, $parameter, $year),
                    (float) $payroll[$key],
                    "Sazba `{$key}` se v roce {$year} rozešla s parametrem {$parameter}.",
                );
            }
        }
    }

    /**
     * Částky jsou v rulesetu v setinách, v `TaxConstants` v celých korunách —
     * převod musí být bezeztrátový a musí vracet `int`, protože na tom visí
     * `assertSame` napříč daňovými testy i přetypování v kalkulátoru.
     */
    public function testPayrollAmountsComeFromTheRulesetInWholeCrowns(): void
    {
        foreach (self::YEARS as $year) {
            $constants = TaxConstants::forYear($year);
            foreach (self::AMOUNTS as $key => [$domain, $parameter]) {
                $minor = $this->moneyMinor($domain, $parameter, $year);
                self::assertSame(
                    0,
                    $minor % 100,
                    "Parametr {$parameter} roku {$year} není v celých korunách — "
                        . 'převod na `TaxConstants` by ztratil haléře.',
                );
                self::assertSame(
                    intdiv($minor, 100),
                    $constants[$key],
                    "Hodnota `{$key}` se v roce {$year} rozešla s parametrem {$parameter}.",
                );
            }
        }
    }

    /**
     * Konstanty, které čte i daňová část (DPFO, přehledy OSVČ) a které proto
     * v `TaxConstants` ZŮSTÁVAJÍ. Přesměrovat je na mzdový ruleset by zabilo
     * jejich per-klíč override v tabulce `tax_constants`, takže se místo toho
     * hlídá, že nesou tutéž zákonnou hodnotu.
     *
     * Roční slevy ruleset nevede — § 35d odst. 2 ZDP z nich dělá měsíční
     * dvanáctinu, takže se porovnává dvanáctinásobek měsíčního parametru.
     * Ten vztah zakládá zákon; u PRAHŮ výplaty (§ 35c odst. 3, § 35d odst. 4)
     * neplatí a ty se tu proto neporovnávají.
     */
    public function testSharedTaxConstantsStillMatchTheRuleset(): void
    {
        foreach (self::YEARS as $year) {
            $c = TaxConstants::forYear($year);

            self::assertSame(
                intdiv(
                    $this->moneyMinor(PayrollRulesetDomain::SocialInsurance, 'maximum_assessment_base.yearly', $year),
                    100,
                ),
                $c['social_max_base'],
                "Maximální vyměřovací základ § 15a se v roce {$year} rozešel s rulesetem.",
            );

            $czk = fn (string $parameter): int => intdiv(
                $this->moneyMinor(PayrollRulesetDomain::IncomeTax, $parameter, $year),
                100,
            );

            self::assertSame(
                12 * $czk('credit.taxpayer.monthly'),
                $c['credit_taxpayer'],
                "Roční sleva na poplatníka se v roce {$year} rozešla s rulesetem.",
            );
            self::assertSame(
                [
                    12 * $czk('credit.child.first.monthly'),
                    12 * $czk('credit.child.second.monthly'),
                    12 * $czk('credit.child.third_and_next.monthly'),
                ],
                $c['child_credits'],
                "Roční daňové zvýhodnění na děti se v roce {$year} rozešlo s rulesetem.",
            );
            self::assertSame(
                $czk('credit.spouse.yearly'),
                $c['credit_spouse'],
                "Sleva na manžela se v roce {$year} rozešla s rulesetem.",
            );
            self::assertSame(
                $czk('spouse.income_limit'),
                $c['spouse_income_limit'],
                "Limit vlastního příjmu manžela se v roce {$year} rozešel s rulesetem.",
            );
            self::assertSame(
                $czk('bonus.minimum_income.yearly'),
                $c['child_bonus_min_income'],
                "Minimální roční příjem pro daňový bonus se v roce {$year} rozešel s rulesetem.",
            );
        }
    }

    /**
     * Rok 2024 ruleset nemá a mít nebude — je uzavřený a jeho výsledky jsou
     * porovnané s reálným deníkem účetní. Zrcadlení se ho proto nesmí dotknout.
     */
    public function testTheYearWithoutARulesetKeepsItsOwnValues(): void
    {
        $c = TaxConstants::forYear(2024);

        self::assertSame(18900, $c['minimum_wage']);
        self::assertSame(131901, $c['advance_tax_high_threshold']);
        self::assertSame(4000, $c['sickness_participation_threshold']);
        self::assertSame(0.248, (float) $c['payroll']['employer_social']);
    }

    /**
     * Kategorie b) a c) v `TaxConstants` být NESMÍ. Kdyby tam někdo některou
     * doplnil, znamenalo by to, že starší modul předstírá rozlišení, které
     * nemá jak doložit — a účtoval by 29,8 % komukoli.
     */
    public function testTaxConstantsDoNotClaimTheOtherEmployerRateCategories(): void
    {
        $forbidden = [
            $this->rate(
                PayrollRulesetDomain::SocialInsurance,
                'employer.rate.rescue_and_company_fire_service',
            ),
            $this->rate(PayrollRulesetDomain::SocialInsurance, 'employer.rate.risk_employment'),
        ];

        foreach (TaxConstants::availableYears() as $year) {
            $payroll = TaxConstants::forYear($year)['payroll'];
            self::assertIsArray($payroll);
            foreach ($payroll as $key => $value) {
                if (!str_starts_with((string) $key, 'employer_social')) {
                    continue;
                }
                self::assertNotContains(
                    (float) $value,
                    $forbidden,
                    "Sada {$year} nese sazbu kategorie § 5a odst. 1 písm. b) nebo c), "
                        . 'kterou starší mzdový modul neumí doložit.',
                );
            }
        }
    }

    private function rate(PayrollRulesetDomain $domain, string $parameter, int $year = 2026): float
    {
        $value = $this->parameter($domain, $parameter, $year);
        self::assertSame('decimal_rate', $value->type);

        return (float) $value->value;
    }

    private function moneyMinor(PayrollRulesetDomain $domain, string $parameter, int $year): int
    {
        $value = $this->parameter($domain, $parameter, $year);
        self::assertSame('money_minor', $value->type);
        self::assertIsInt($value->value);

        return $value->value;
    }

    private function parameter(PayrollRulesetDomain $domain, string $parameter, int $year): PayrollRuleValue
    {
        $provider = $year === 2026
            ? CzechPayrollRulesets2026::provider()
            : CzechPayrollRulesets::provider();
        $ruleset = $provider->forDate($domain, sprintf('%04d-01-01', $year));
        $value = $ruleset->parameters[$parameter] ?? null;
        self::assertInstanceOf(
            PayrollRuleValue::class,
            $value,
            "Mzdový ruleset {$domain->value} nenese parametr {$parameter}.",
        );

        return $value;
    }
}
