<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2027;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleParameterCatalog;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearOutlook;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetApprover;
use MyInvoice\Service\Payroll\Ruleset\VendorRulesetManifest;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ročník 2027 proti rešerši `28a-DANE-2027` a předpisům ve Sbírce.
 */
final class CzechPayrollRulesets2027Test extends TestCase
{
    /** Průměrná mzda 2027: 48 900 × 1,0565 = 51 662,85 → nahoru (§ 23b odst. 4 ZPSZ). */
    private const AVERAGE_WAGE_MINOR = 5_166_300;

    /** Minimální mzda 2027 (NV č. 164/2026 Sb., sdělení č. 171/2026 Sb.). */
    private const MINIMUM_WAGE_MINOR = 2_490_000;

    protected function setUp(): void
    {
        VendorRulesetApprover::configure(VendorRulesetApprover::DEFAULT_NAME);
    }

    protected function tearDown(): void
    {
        VendorRulesetApprover::reset();
    }

    public function testCanonicalManifestIsByteStable(): void
    {
        self::assertSame(
            CzechPayrollRulesets2027::provider()->canonicalManifestJson(),
            CzechPayrollRulesets2027::provider()->canonicalManifestJson(),
        );
    }

    public function testAverageWageIsDerivedFromGovernmentRegulation177(): void
    {
        // § 23b odst. 4 ZPSZ: součin VVZ a přepočítacího koeficientu, nahoru
        // na celé koruny. Počítá se v celých číslech, ne v plovoucí čárce.
        self::assertSame(51_663, intdiv(48_900 * 10_565 + 9_999, 10_000));
        self::assertSame(self::AVERAGE_WAGE_MINOR, 51_663 * 100);
    }

    /** @return iterable<string, array{0:string, 1:string, 2:int}> */
    public static function moneyParameters(): iterable
    {
        yield 'hranice 23 % zálohy = 3 × 51 663' =>
            ['income_tax', 'advance.high_threshold.monthly', 15_498_900];
        yield 'měsíční příjem pro bonus = polovina min. mzdy dolů' =>
            ['income_tax', 'bonus.minimum_income.monthly', 1_245_000];
        yield 'roční příjem pro bonus = 6 × min. mzda' =>
            ['income_tax', 'bonus.minimum_income.yearly', 14_940_000];
        yield 'rozhodná částka DPP 12 500 Kč (sdělení 184/2026)' =>
            ['income_tax', 'dpp.withholding.threshold', 1_250_000];
        yield 'rozhodný příjem 5 000 Kč (sdělení 186/2026)' =>
            ['income_tax', 'other.withholding.threshold', 500_000];
        yield 'limit zdravotních benefitů = průměrná mzda' =>
            ['income_tax', 'benefit_exemption.non_cash_health.yearly', 5_166_300];
        yield 'sleva na studenta 4 020 / 12 = 335 Kč' =>
            ['income_tax', 'credit.student.monthly', 33_500];
        yield 'sleva na poplatníka beze změny 2 570 Kč' =>
            ['income_tax', 'credit.taxpayer.monthly', 257_000];
        yield 'zvýhodnění na první dítě beze změny' =>
            ['income_tax', 'credit.child.first.monthly', 126_700];
        yield 'práh vyplacení bonusu beze změny 50 Kč' =>
            ['income_tax', 'bonus.minimum_amount.monthly', 5_000];
        yield 'průměrná mzda SP' =>
            ['social_insurance', 'average_wage.monthly', 5_166_300];
        yield 'maximální vyměřovací základ = 48 × 51 663' =>
            ['social_insurance', 'maximum_assessment_base.yearly', 247_982_400];
        yield 'účast DPP 12 500 Kč (SP)' =>
            ['social_insurance', 'participation.dpp.minimum', 1_250_000];
        yield 'rozhodný příjem 5 000 Kč (SP)' =>
            ['social_insurance', 'participation.small_scale.minimum', 500_000];
        yield 'minimální VZ ZP = minimální mzda' =>
            ['health_insurance', 'minimum_assessment_base.monthly', 2_490_000];
        yield 'minimální pojistné ZP 3 362 Kč' =>
            ['health_insurance', 'minimum_contribution.monthly', 336_200];
        yield 'účast DPP 12 500 Kč (ZP)' =>
            ['health_insurance', 'participation.dpp.minimum', 1_250_000];
        yield 'účast DPČ 5 000 Kč (ZP)' =>
            ['health_insurance', 'participation.dpc.minimum', 500_000];
        yield 'minimální mzda 24 900 Kč' =>
            ['employment_thresholds', 'minimum_wage.monthly_40h_week', 2_490_000];
        yield 'minimální hodinová mzda 148,30 Kč' =>
            ['employment_thresholds', 'minimum_wage.hourly_40h_week', 14_830];
        yield 'průměrná mzda (prahy)' =>
            ['employment_thresholds', 'average_wage.monthly', 5_166_300];
        yield 'účast DPČ 5 000 Kč (prahy)' =>
            ['employment_thresholds', 'participation.dpc.minimum', 500_000];
        yield 'účast DPP 12 500 Kč (prahy)' =>
            ['employment_thresholds', 'participation.dpp.minimum', 1_250_000];
        yield 'malý rozsah 5 000 Kč (prahy)' =>
            ['employment_thresholds', 'participation.small_scale.minimum', 500_000];
        yield 'průměrná mzda (náhrady)' =>
            ['compensation_averages', 'average_wage.monthly', 5_166_300];
        yield 'první hodinová redukční hranice 301,53 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_1_minor', 30_153];
        yield 'druhá hodinová redukční hranice 452,20 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_2_minor', 45_220];
        yield 'třetí hodinová redukční hranice 904,23 Kč' =>
            ['compensation_averages', 'wage_compensation.hourly_boundary_3_minor', 90_423];
    }

    #[DataProvider('moneyParameters')]
    public function testDeliveredAmountsMatchTheirLegalSource(
        string $domain,
        string $key,
        int $expectedMinorUnits,
    ): void {
        $version = CzechPayrollRulesets::provider()->forDate(
            PayrollRulesetDomain::from($domain),
            '2027-06-15',
        );

        self::assertSame($expectedMinorUnits, $version->parameter($key)->value, "{$domain}|{$key}");
    }

    /** @return iterable<string, array{0:string, 1:string, 2:string}> */
    public static function rateParameters(): iterable
    {
        yield 'riziková práce 28,8 % (§ 7 odst. 1 písm. c) bod 3)' =>
            ['social_insurance', 'employer.rate.risk_employment', '0.288'];
        yield 'zaměstnavatel běžně 24,8 %' => ['social_insurance', 'employer.rate.ordinary', '0.248'];
        yield 'záchranáři a HZS podniku 29,8 %' =>
            ['social_insurance', 'employer.rate.rescue_and_company_fire_service', '0.298'];
        yield 'zaměstnanec 7,1 %' => ['social_insurance', 'employee.rate.ordinary', '0.071'];
        yield 'pracující důchodce 6,5 %' => ['social_insurance', 'employee.discount.working_pensioner', '0.065'];
        yield 'ZP zaměstnanec 4,5 %' => ['health_insurance', 'employee.rate', '0.045'];
        yield 'ZP zaměstnavatel 9 %' => ['health_insurance', 'employer.rate', '0.09'];
        yield 'ZP celkem 13,5 %' => ['health_insurance', 'total.rate', '0.135'];
        yield 'daň 15 %' => ['income_tax', 'advance.low_rate', '0.15'];
        yield 'daň 23 %' => ['income_tax', 'advance.high_rate', '0.23'];
    }

    #[DataProvider('rateParameters')]
    public function testDeliveredRatesMatchTheirLegalSource(string $domain, string $key, string $expected): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(PayrollRulesetDomain::from($domain), '2027-06-15');

        self::assertSame($expected, $version->parameter($key)->value, "{$domain}|{$key}");
    }

    /**
     * Odvozená čísla se nesmí rozejít se vstupem, ze kterého zákon počítá.
     */
    public function testDerivedAmountsFollowFromAverageAndMinimumWage(): void
    {
        $provider = CzechPayrollRulesets::provider();
        $money = static fn (PayrollRulesetDomain $domain, string $key): int
            => (int) $provider->forDate($domain, '2027-06-15')->parameter($key)->value;

        self::assertSame(3 * self::AVERAGE_WAGE_MINOR, $money(PayrollRulesetDomain::IncomeTax, 'advance.high_threshold.monthly'));
        self::assertSame(48 * self::AVERAGE_WAGE_MINOR, $money(PayrollRulesetDomain::SocialInsurance, 'maximum_assessment_base.yearly'));
        self::assertSame(self::AVERAGE_WAGE_MINOR, $money(PayrollRulesetDomain::IncomeTax, 'benefit_exemption.non_cash_health.yearly'));
        // § 7a odst. 1 NemP: 25 % průměrné mzdy dolů na celých 500 Kč.
        self::assertSame(
            intdiv(intdiv(self::AVERAGE_WAGE_MINOR, 4), 50_000) * 50_000,
            $money(PayrollRulesetDomain::IncomeTax, 'dpp.withholding.threshold'),
        );
        // § 6 odst. 2 NemP: desetina průměrné mzdy dolů na celých 500 Kč.
        self::assertSame(
            intdiv(intdiv(self::AVERAGE_WAGE_MINOR, 10), 50_000) * 50_000,
            $money(PayrollRulesetDomain::IncomeTax, 'other.withholding.threshold'),
        );
        self::assertSame(
            self::MINIMUM_WAGE_MINOR,
            $money(PayrollRulesetDomain::EmploymentThresholds, 'minimum_wage.monthly_40h_week'),
        );
        self::assertSame(
            self::MINIMUM_WAGE_MINOR,
            $money(PayrollRulesetDomain::HealthInsurance, 'minimum_assessment_base.monthly'),
        );
        // 13,5 % minimální mzdy nahoru na koruny.
        self::assertSame(
            (int) ceil(self::MINIMUM_WAGE_MINOR * 135 / 1000 / 100) * 100,
            $money(PayrollRulesetDomain::HealthInsurance, 'minimum_contribution.monthly'),
        );
        self::assertSame(
            intdiv(self::MINIMUM_WAGE_MINOR, 2 * 100) * 100,
            $money(PayrollRulesetDomain::IncomeTax, 'bonus.minimum_income.monthly'),
        );
        self::assertSame(6 * self::MINIMUM_WAGE_MINOR, $money(PayrollRulesetDomain::IncomeTax, 'bonus.minimum_income.yearly'));
        // § 35bc odst. 1: 4 020 Kč ročně, měsíčně přesně dvanáctina.
        self::assertSame(402_000, 12 * $money(PayrollRulesetDomain::IncomeTax, 'credit.student.monthly'));

        // Hodinové redukční hranice: denní hranice ze sdělení č. 185/2026 Sb.
        // × 0,175, nahoru na haléře (§ 192 odst. 2 ZP).
        foreach ([1 => 1_723, 2 => 2_584, 3 => 5_167] as $band => $dailyCzk) {
            self::assertSame(
                intdiv($dailyCzk * 100 * 175 + 999, 1000),
                $money(PayrollRulesetDomain::CompensationAverages, "wage_compensation.hourly_boundary_{$band}_minor"),
                "hranice {$band}",
            );
        }
    }

    /**
     * Doplnění roku 2027 se roku 2026 nesmí dotknout ani o bajt — visí na něm
     * zmrazené snapshoty výplat i integritní piny.
     */
    public function testAddingYear2027LeavesTheYear2026Untouched(): void
    {
        $standalone = CzechPayrollRulesets2026::provider()->versions();
        $composed = array_values(array_filter(
            CzechPayrollRulesets::provider()->versions(),
            static fn (PayrollRulesetVersion $version): bool => str_contains($version->id, '2026'),
        ));

        self::assertSame(
            array_map(static fn (PayrollRulesetVersion $v): string => $v->id, $standalone),
            array_map(static fn (PayrollRulesetVersion $v): string => $v->id, $composed),
        );
        foreach ($standalone as $index => $version) {
            self::assertSame($version->canonicalSnapshot, $composed[$index]->canonicalSnapshot, $version->id);
        }
        foreach (PayrollRulesetDomain::cases() as $domain) {
            foreach (['2026-01-01', '2026-12-31'] as $date) {
                self::assertSame(
                    CzechPayrollRulesets2026::provider()->forDate($domain, $date)->canonicalSnapshot,
                    CzechPayrollRulesets::provider()->forDate($domain, $date)->canonicalSnapshot,
                    "{$domain->value} {$date}",
                );
            }
        }
    }

    public function testVendorManifestPinsEveryDeliveredVersionOf2027(): void
    {
        foreach (CzechPayrollRulesets2027::provider()->versions() as $version) {
            self::assertTrue(
                VendorRulesetManifest::contains($version->contentHash),
                sprintf("Otisk dodané verze %s není v manifestu: '%s'", $version->id, $version->contentHash),
            );
            self::assertSame(PayrollRulesetOrigin::Vendor, $version->origin, $version->id);
            self::assertSame(PayrollRulesetLifecycle::Active, $version->lifecycle, $version->id);
            self::assertSame('2027-01-01', $version->effectiveFrom, $version->id);
            self::assertSame('2027-12-31', $version->effectiveTo, $version->id);
        }
    }

    /**
     * Rok 2027 je spočitatelný: všechny domény mzdového výpočtu jsou pokryté
     * a výhled ho od podzimu 2026 nehlásí jako chybějící.
     */
    public function testYear2027IsCoveredForEveryCalculationCriticalDomain(): void
    {
        $provider = CzechPayrollRulesets::provider();
        foreach (PayrollRulesetYearCoverage::CALCULATION_CRITICAL_DOMAINS as $domain) {
            self::assertTrue(PayrollRulesetYearCoverage::coversYear($provider, $domain, 2027), $domain->value);
        }

        $outlook = PayrollRulesetYearOutlook::forProvider($provider, new \DateTimeImmutable('2026-10-28'));
        self::assertSame(2027, $outlook[0]['year']);
        self::assertTrue($outlook[0]['covered']);
        self::assertSame([], $outlook[0]['missing_domains']);
        self::assertSame(2028, $outlook[1]['year']);
        self::assertFalse($outlook[1]['covered']);
    }

    /**
     * „Rok 2027 pokryto" neplatilo pro všechno: exekuční srážky a cestovní
     * náhrady sadu na rok 2027 nemají (nezabavitelné částky a stravné ještě
     * nevyšly) a stravenkový paušál čeká na vyhlášku. Výhled to u pokrytého
     * roku říká jmenovitě, jako informaci, ne jako závoru.
     */
    public function testCoveredYear2027NamesAgendasAndValuesThatAreNotPublishedYet(): void
    {
        $provider = CzechPayrollRulesets::provider();
        $outlook = PayrollRulesetYearOutlook::forProvider($provider, new \DateTimeImmutable('2026-11-11'));

        self::assertTrue($outlook[0]['covered']);
        self::assertSame('info', $outlook[0]['severity']);
        self::assertSame('year_covered_values_pending', $outlook[0]['code']);
        self::assertContains('enforcement_deductions', $outlook[0]['missing_agenda_domains']);
        self::assertContains('travel_allowances', $outlook[0]['missing_agenda_domains']);
        // Jádro výpočtu mezi chybějícími agendami není.
        foreach (PayrollRulesetYearCoverage::CALCULATION_CRITICAL_DOMAINS as $domain) {
            self::assertNotContains($domain->value, $outlook[0]['missing_agenda_domains']);
        }
        $pendingKeys = array_map(
            static fn (array $item): string => $item['domain'] . '.' . $item['key'],
            $outlook[0]['pending_parameters'],
        );
        self::assertContains('income_tax.benefit_exemption.meal.per_shift', $pendingKeys);
        // Posuzované jinde ani trvale ruční parametry na vyhlášení nečekají.
        self::assertNotContains('income_tax.credit.spouse.eligibility', $pendingKeys);
        self::assertNotContains('social_insurance.employee.discount.agriculture_dpp', $pendingKeys);
        self::assertStringContainsString('neodhadují', $outlook[0]['message']);

        // Rok 2026 má všechny agendy i hodnoty: žádná mezera.
        self::assertSame([], PayrollRulesetYearOutlook::agendaGaps($provider, 2026));
        self::assertSame([], PayrollRulesetYearOutlook::pendingParameters($provider, 2026));
        // Nepokrytý rok 2028 mezery agend nehlásí, chybí mu celá sada.
        self::assertSame([], $outlook[1]['missing_agenda_domains']);
        self::assertSame([], $outlook[1]['pending_parameters']);
    }

    /**
     * Nedoložené domény 2027 se NEDOPLŇUJÍ hodnotami roku 2026: cestovní
     * náhrady (vyhláška pro rok 2027 nevyšla), nezabavitelné částky a lhůty,
     * číselníky a podání JMHZ selžou na chybějícím rulesetu.
     */
    /**
     * Lhůta JMHZ za období 2027 (§ 7 odst. 1 z. č. 323/2025 Sb.): od 1. do 20.
     * dne následujícího měsíce, konec posunutý na nejbližší pracovní den.
     * Hlášení za 01/2027 se podává 1. až 22. 2. 2027 (20. 2. je sobota).
     */
    public function testJmhzDeadlineOf2027FollowsTheAct(): void
    {
        $window = (new JmhzDeadlinePolicy(CzechPayrollRulesets::provider()))->forPeriod('2027-01-01');

        self::assertSame('2027-02-01', $window->earliestSubmissionOn);
        self::assertSame('2027-02-22', $window->dueOn);
        self::assertSame('cz-jmhz-deadlines-2027.regular.v1', $window->rulesetId);
        self::assertTrue(PayrollRulesetYearCoverage::coversYear(
            CzechPayrollRulesets::provider(),
            PayrollRulesetDomain::Deadlines,
            2027,
        ));
    }

    public function testUndocumentedDomainsOf2027FailClosed(): void
    {
        $provider = CzechPayrollRulesets::provider();
        foreach ([
            PayrollRulesetDomain::TravelAllowances,
            PayrollRulesetDomain::EnforcementDeductions,
            PayrollRulesetDomain::Codebooks,
            PayrollRulesetDomain::Submissions,
        ] as $domain) {
            try {
                $provider->forCalculation($domain, '2027-01-31');
                self::fail("Doména {$domain->value} nemá mít pro rok 2027 ruleset.");
            } catch (PayrollRulesetException) {
                self::assertFalse(PayrollRulesetYearCoverage::coversYear($provider, $domain, 2027));
            }
        }
    }

    /**
     * Stravenkový paušál (70 % stravného 2027) čeká na vyhlášení: výpočet
     * osvobození fail-closed selže, místo aby použil číslo roku 2026.
     * Administrace ho ale nesmí ukazovat jako „ruční posouzení" bez termínu.
     */
    public function testUndocumentedBenefitLimitsFailClosed(): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(PayrollRulesetDomain::IncomeTax, '2027-06-15');
        $meal = $version->parameters['benefit_exemption.meal.per_shift'];
        self::assertSame(PayrollRulesetCapability::ManualReview, $meal->capability);
        self::assertStringContainsString('ČEKÁ NA VYHLÁŠENÍ', (string) $meal->value);
        $explanation = PayrollRuleParameterCatalog::manualReview('income_tax', 'benefit_exemption.meal.per_shift');
        self::assertNotNull($explanation);
        self::assertSame(PayrollRuleParameterCatalog::REVIEW_PENDING_PUBLICATION, $explanation['category']);
        self::assertSame('2026-12', $explanation['expected'] ?? null);
        self::assertStringStartsWith('https://', $explanation['source_url'] ?? '');

        $baskets = new PayrollBenefitBasketService(CzechPayrollRulesets::provider());
        try {
            $baskets->limitMinor(PayrollBenefitExemptionBasket::MealPerShift, '2027-03-01', 10);
            self::fail('Stravenkový paušál nesmí mít pro rok 2027 limit, dokud nevyjde vyhláška.');
        } catch (PayrollRulesetException) {
            self::addToAssertionCount(1);
        }
        // Volnočasový koš je od 2027 ze zákona bez limitu (§ 6 odst. 9 písm. d) bod 2
        // ve znění zák. č. 180/2026 Sb.) – starý parametr se už nečte.
        self::assertNull($baskets->limitMinor(PayrollBenefitExemptionBasket::NonCashLeisure, '2027-03-01', 10));
        // Doložený koš se počítá normálně.
        self::assertSame(
            self::AVERAGE_WAGE_MINOR,
            $baskets->limitMinor(PayrollBenefitExemptionBasket::NonCashHealth, '2027-03-01'),
        );
    }

    /**
     * § 6 odst. 9 písm. d) ZDP ve znění zák. č. 180/2026 Sb.: bod 2 (sport,
     * kultura, vzdělávací a předškolní zařízení) od 1. 1. 2027 bez číselného
     * limitu, bod 3 (rekreace a zájezd) do 20 000 Kč za zdaňovací období.
     * Sada to musí říct hodnotou, ne ručním posouzením, a výpočet se podle
     * toho musí chovat.
     */
    public function testLeisureBasketOf2027IsUnlimitedAndRecreationCapped(): void
    {
        $version = CzechPayrollRulesets::provider()->forDate(PayrollRulesetDomain::IncomeTax, '2027-06-15');
        $leisure = $version->parameters['benefit_exemption.non_cash_leisure.yearly'];
        self::assertSame(PayrollRulesetCapability::Supported, $leisure->capability);
        self::assertSame(PayrollBenefitExemptionBasket::UNLIMITED_RULESET_VALUE, $leisure->value);
        self::assertSame('bez číselného limitu', PayrollRuleParameterCatalog::valueLabel($leisure->value));

        // Hodnota v sadě a zákonné pravidlo v kódu se nesmí rozejít v žádném ročníku.
        $provider = CzechPayrollRulesets::provider();
        foreach ([2025, 2026, 2027] as $year) {
            $value = $provider->forDate(PayrollRulesetDomain::IncomeTax, "{$year}-12-31")
                ->parameters['benefit_exemption.non_cash_leisure.yearly']->value;
            self::assertSame(
                PayrollBenefitExemptionBasket::NonCashLeisure->isUnlimited($year),
                $value === PayrollBenefitExemptionBasket::UNLIMITED_RULESET_VALUE,
                (string) $year,
            );
        }

        $baskets = new PayrollBenefitBasketService($provider);
        $leisureSplit = $baskets->split(PayrollBenefitExemptionBasket::NonCashLeisure, '2027-03-01', 0, 5_000_000);
        self::assertNull($leisureSplit->limitMinor);
        self::assertSame(5_000_000, $leisureSplit->exemptMinor);
        self::assertSame(0, $leisureSplit->taxableMinor);

        $recreationSplit = $baskets->split(PayrollBenefitExemptionBasket::Recreation, '2027-03-01', 0, 2_500_000);
        self::assertSame(2_000_000, $recreationSplit->limitMinor);
        self::assertSame(2_000_000, $recreationSplit->exemptMinor);
        self::assertSame(500_000, $recreationSplit->taxableMinor);
    }

    /**
     * Ročník 2027 nese tytéž klíče jako 2026 — kromě jmenovaně nových.
     * Chybějící klíč by spadl až uprostřed lednové mzdy.
     */
    public function testEveryDomainOf2027CarriesTheKeysOf2026(): void
    {
        $addedIn2027 = ['income_tax' => [
            'benefit_exemption.recreation.yearly',
            'credit.student.monthly',
            'minimum_wage.monthly',
        ]];

        foreach (CzechPayrollRulesets2027::provider()->versions() as $version2027) {
            $keys2026 = array_keys(
                CzechPayrollRulesets2026::provider()->forDate($version2027->domain, '2026-08-03')->parameters,
            );
            $keys2027 = array_keys($version2027->parameters);
            self::assertSame([], array_values(array_diff($keys2026, $keys2027)), $version2027->domain->value);
            self::assertSame(
                $addedIn2027[$version2027->domain->value] ?? [],
                array_values(array_diff($keys2027, $keys2026)),
                $version2027->domain->value,
            );
        }
    }

    public function testEveryParameterHasCzechLabelAndExplanation(): void
    {
        $provider = CzechPayrollRulesets::provider();

        self::assertSame([], PayrollRuleParameterCatalog::missingLabels($provider));
        self::assertSame([], PayrollRuleParameterCatalog::missingManualReviewExplanations($provider));
    }

    public function testEverySourceCitesTheCollectionOfLaws(): void
    {
        foreach (CzechPayrollRulesets2027::provider()->versions() as $version) {
            foreach ($version->sources as $source) {
                self::assertStringStartsWith('https://www.zakonyprolidi.cz/cs/', $source->url, $source->id);
                self::assertSame(CzechPayrollRulesets2027::RETRIEVED_ON, $source->retrievedOn, $source->id);
            }
        }
    }
}
