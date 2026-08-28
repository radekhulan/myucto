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
 * `TaxConstants::PAYROLL_2024_PLUS` drží sazby pojistného pro STARŠÍ modul
 * mzdové rekapitulace ({@see \MyInvoice\Service\Accounting\Payroll\PayrollCalculator}),
 * mzdový ruleset je drží pro modul Mzdy. Sloučit je nejde: ruleset zná tři
 * kategorie zaměstnavatele podle § 5a odst. 1 z. č. 589/1992 Sb. a starší modul
 * nemá čím kategorii doložit, takže umí a smí jen písm. a). Sazba písmene a) je
 * ale v obou zdrojích ta samá zákonná hodnota — a právě to se musí hlídat.
 *
 * Guard běží nad KAŽDÝM rokem, pro který ruleset existuje. Rok 2025 přibyl
 * zpětně (opravné revize a roční zúčtování), a protože `PAYROLL_2024_PLUS` je
 * pro 2024–2026 sdílená sada, projevilo by se rozejití v obou ročnících
 * najednou — kontrolují se proto oba.
 */
final class TaxConstantsPayrollRatesMatchRulesetTest extends TestCase
{
    /** @var non-empty-list<int> */
    private const YEARS = [2025, 2026];

    public function testEmployerAndEmployeeRatesMatchTheOrdinaryPayrollRulesetRates(): void
    {
        foreach (self::YEARS as $year) {
            $payroll = TaxConstants::forYear($year)['payroll'];
            self::assertIsArray($payroll);

            self::assertSame(
                $this->rate(PayrollRulesetDomain::SocialInsurance, 'employer.rate.ordinary', $year),
                (float) $payroll['employer_social'],
                "Sazba § 7 odst. 1 písm. a) se v roce {$year} v obou zdrojích rozešla.",
            );
            self::assertSame(
                $this->rate(PayrollRulesetDomain::HealthInsurance, 'employer.rate', $year),
                (float) $payroll['employer_health'],
                "Sazba zdravotního pojistného zaměstnavatele se v roce {$year} v obou zdrojích rozešla.",
            );
        }
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
        self::assertSame('decimal_rate', $value->type);

        return (float) $value->value;
    }
}
