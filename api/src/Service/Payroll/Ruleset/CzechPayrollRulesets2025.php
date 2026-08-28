<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Dodaná legislativní sada pro rok 2025.
 *
 * ── Proč zpětný ročník vůbec vzniká ──────────────────────────────────────────
 * Registry začínal rokem 2026 a to mělo tři provozní důsledky, z nichž ani jeden
 * není teoretický: opravná revize staršího období neměla podle čeho počítat,
 * roční zúčtování daně za rok 2025 (podle § 38ch odst. 4 ZDP se provádí do
 * 31. 3. 2026, tedy PRÁVĚ TEĎ) nešlo provést vůbec, a mzdová účetní neměla jak
 * dopočítat prosincovou mzdu 2025 vyplácenou v lednu 2026.
 *
 * Ročník je proto samostatná třída vedle {@see CzechPayrollRulesets2026}, ne
 * editace téhle. Rok 2026 je immutable: visí na něm zmrazené snapshoty výplat
 * a integritní piny ({@see VendorRulesetManifest}), takže jeho otisky se nesmí
 * pohnout ani o bajt. Skládá je dohromady {@see CzechPayrollRulesets}.
 *
 * ── Které domény tu ZÁMĚRNĚ nejsou ───────────────────────────────────────────
 * `deadlines`, `codebooks` a `submissions` jsou navázané na jednotné měsíční
 * hlášení zaměstnavatele, které zavedl až zákon č. 323/2025 Sb. s účinností od
 * 1. 1. 2026. V roce 2025 se místo něj podávaly přehledy ČSSZ, ELDP a přílohy
 * k žádosti o dávku podle starých pravidel — agenda, kterou tenhle modul neumí.
 * Sadu proto NEMAJÍ a pokus o JMHZ za období roku 2025 skončí fail-closed na
 * chybějícím rulesetu. To je správná odpověď: horší než „neumím" je „umím",
 * které pak vyrobí podání podle špatných pravidel.
 *
 * ── Co je vedené jako K OVĚŘENÍ ──────────────────────────────────────────────
 * Tři parametry sociálního pojištění (zvláštní sazby zaměstnavatele podle
 * § 7 odst. 1 a sleva pracujícího starobního důchodce) se pro rok 2025 nepodařilo
 * doložit z primárního zdroje se stejnou jistotou jako zbytek sady. Jsou proto
 * vedené jako ruční posouzení, ne jako číslo: výpočet, který na ně sáhne,
 * fail-closed selže a účetní hodnotu doplní v administraci mzdových rulesetů.
 * Tiše špatná sazba by se propsala do každé výplaty — nedoložená hodnota se
 * nedosazuje.
 */
final class CzechPayrollRulesets2025
{
    public const RETRIEVED_ON = '2026-08-28';

    /**
     * Průměrná mzda pro rok 2025 podle nařízení vlády č. 282/2024 Sb.
     * (všeobecný vyměřovací základ za rok 2023 = 43 682 Kč × přepočítací
     * koeficient 1,0658 = 46 557 Kč). Z ní se odvozuje maximální vyměřovací
     * základ, hranice progrese, rozhodné částky i limity benefitů — proto je
     * v kódu jednou a odvozeniny se od ní počítají ručně jen v komentářích.
     */
    private const AVERAGE_WAGE_MINOR = 4_655_700;

    /** Minimální mzda 2025: 20 800 Kč měsíčně (sdělení MPSV č. 286/2024 Sb.). */
    private const MINIMUM_WAGE_MONTHLY_MINOR = 2_080_000;

    public static function provider(): PayrollRulesetProvider
    {
        $technicalReview = new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            self::RETRIEVED_ON,
            'Manifest oficiálních zdrojů, kontrola přesných hodnot a testy bajtové stability — '
            . 'technická kontrola, ne odborné ani právní schválení.',
        );

        return new PayrollRulesetProvider([
            self::incomeTax($technicalReview),
            self::socialInsurance($technicalReview),
            self::healthInsurance($technicalReview),
            self::employmentThresholds($technicalReview),
            self::compensationAverages($technicalReview),
            self::travelAllowances($technicalReview),
            self::enforcementDeductions($technicalReview),
        ]);
    }

    private static function incomeTax(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.income-tax.v1',
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetCapability::Supported,
            [self::financialAdministration(), self::benefitExemptionInformation()],
            [
                'advance.high_rate' => PayrollRuleValue::rate('0.23'),
                // § 38h odst. 2 ZDP — 3× průměrná mzda 46 557 = 139 671 Kč měsíčně.
                'advance.high_threshold.monthly' => PayrollRuleValue::moneyMinor(13_967_100),
                'advance.low_rate' => PayrollRuleValue::rate('0.15'),
                // Zaokrouhlovací pravidla § 38h odst. 1 a 3 ZDP se mezi 2025 a 2026
                // nezměnila; jsou to pravidla, ne roční částky.
                'advance.rounding.base_above_100_czk' => PayrollRuleValue::text('ceil-to-100-czk'),
                'advance.rounding.base_up_to_100_czk' => PayrollRuleValue::text('ceil-to-1-czk'),
                'advance.rounding.result' => PayrollRuleValue::text('ceil-to-1-czk'),
                // § 6 odst. 9 písm. b) ZDP — 70 % horní hranice stravného pásma
                // 5 až 12 hodin u zaměstnance odměňovaného platem. Pro 2025 je ta
                // hranice 177 Kč (vyhláška č. 475/2024 Sb.), takže 70 % = 123,90 Kč.
                // Odvození z domény cestovních náhrad hlídá test, ať částka nemůže
                // utéct od sazby stravného, ze které plyne.
                'benefit_exemption.meal.minimum_work_minutes' => PayrollRuleValue::integer(180),
                'benefit_exemption.meal.per_shift' => PayrollRuleValue::moneyMinor(12_390),
                'benefit_exemption.meal.second_contribution_day_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.second_contribution_shift_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.shift_rate' => PayrollRuleValue::rate('0.70'),
                // § 6 odst. 9 písm. d) ZDP ve znění účinném od 1. 1. 2025 — DVA
                // samostatné roční úhrnné limity odvozené z průměrné mzdy 46 557 Kč:
                //   bod 1 — zdravotnické služby a zdravotnické prostředky … průměrná mzda
                //   bod 2 — rekreace, sport, kultura, tisk, vzdělávací a předškolní
                //           zařízení … polovina průměrné mzdy = 23 278,50 Kč
                // Rok 2025 je první, kdy jsou limity dva; do 2024 byl jeden společný
                // ve výši poloviny průměrné mzdy. Nevyčerpaný zdravotní limit se do
                // volnočasového NEPŘELÉVÁ.
                'benefit_exemption.non_cash_health.yearly' => PayrollRuleValue::moneyMinor(4_655_700),
                'benefit_exemption.non_cash_leisure.yearly' => PayrollRuleValue::moneyMinor(2_327_850),
                // § 6 odst. 9 písm. m) ZDP — 50 000 Kč ročně. Zákon píše částku číslem,
                // z průměrné mzdy se neodvozuje, a od 1. 1. 2024 se nezměnila.
                'benefit_exemption.old_age_savings.yearly' => PayrollRuleValue::moneyMinor(5_000_000),
                // § 6 odst. 9 písm. i) ZDP — 3 500 Kč měsíčně, rovněž pevná zákonná
                // částka nezávislá na roce.
                'benefit_exemption.temporary_accommodation.monthly' =>
                    PayrollRuleValue::moneyMinor(350_000),
                // § 35d odst. 4 ZDP, neostře; § 35c odst. 3 ZDP, rovněž neostře.
                // Obě částky píše zákon číslem a mezi 2025 a 2026 se nezměnily.
                'bonus.minimum_amount.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'bonus.minimum_amount.yearly' => PayrollRuleValue::moneyMinor(10_000),
                // § 35d odst. 4 / § 35c odst. 4 ZDP — příjem alespoň poloviny,
                // resp. šestinásobku minimální mzdy. Minimální mzda 2025 = 20 800 Kč,
                // tedy 10 400 Kč měsíčně a 124 800 Kč ročně.
                'bonus.minimum_income.monthly' => PayrollRuleValue::moneyMinor(1_040_000),
                'bonus.minimum_income.yearly' => PayrollRuleValue::moneyMinor(12_480_000),
                // § 35c odst. 1 ZDP — daňové zvýhodnění se mezi 2025 a 2026 nezměnilo
                // (15 204 / 22 320 / 27 840 Kč ročně).
                'credit.child.first.monthly' => PayrollRuleValue::moneyMinor(126_700),
                'credit.child.second.monthly' => PayrollRuleValue::moneyMinor(186_000),
                'credit.child.third_and_next.monthly' => PayrollRuleValue::moneyMinor(232_000),
                // § 35ba odst. 1 písm. c) a d) ZDP — 2 520 / 5 040 Kč ročně.
                'credit.disability.basic.monthly' => PayrollRuleValue::moneyMinor(21_000),
                'credit.disability.extended.monthly' => PayrollRuleValue::moneyMinor(42_000),
                // § 35bb odst. 1 ZDP — 24 840 Kč, uplatnitelné až v ročním zúčtování.
                'credit.spouse.yearly' => PayrollRuleValue::moneyMinor(2_484_000),
                'credit.spouse.ztp_p_multiplier' => PayrollRuleValue::integer(2),
                // § 35ba odst. 1 písm. a) ZDP — 30 840 Kč ročně, 2 570 Kč měsíčně.
                'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
                // § 35ba odst. 1 písm. e) ZDP — 16 140 Kč ročně, 1 345 Kč měsíčně.
                'credit.ztp_p.monthly' => PayrollRuleValue::moneyMinor(134_500),
                // ROZHODNÁ ČÁSTKA, poměřovaná OSTŘE. Rok 2025 je první, ve kterém
                // § 6 odst. 4 písm. a) ZDP (ve znění zák. č. 470/2024 Sb.) zní
                // „NEDOSÁHNE částky rozhodné pro účast na nemocenském pojištění“ —
                // do 31. 12. 2024 tam stálo „nepřesáhne 10 000 Kč“, tedy hranice
                // včetně, a to je JINÝ operátor, ne jen jiné číslo.
                //
                // § 7a odst. 2 z. č. 187/2006 Sb.: 25 % průměrné mzdy zaokrouhlených
                // DOLŮ na celých 500 Kč → 46 557 × 0,25 = 11 639,25 → 11 500 Kč.
                //
                // Režim „oznámené dohody“, který zavedl zák. č. 163/2024 Sb., byl
                // zrušen zák. č. 470/2024 Sb. JEŠTĚ PŘED nabytím účinnosti. V roce
                // 2025 tedy NEEXISTUJE dvoukolejnost oznámená/neoznámená DPP a platí
                // jediná hranice pro všechny dohody — proto tu je jen jeden klíč.
                'dpp.withholding.threshold' => PayrollRuleValue::moneyMinor(1_150_000),
                // § 6 odst. 4 písm. b) ZDP — rozhodná částka pro účast na nemocenském
                // pojištění podle § 6 odst. 1 písm. a) z. č. 187/2006 Sb. je 1/10
                // průměrné mzdy zaokrouhlená dolů na celých 500 Kč: 46 557 / 10 =
                // 4 655,7 → 4 500 Kč (2024: 4 000 Kč).
                'other.withholding.threshold' => PayrollRuleValue::moneyMinor(450_000),
                // § 38ch odst. 5 a § 35d odst. 8 ZDP — přeplatek se vrací, činí-li
                // VÍCE NEŽ 50 Kč. Nerovnost je OSTRÁ a je to jiné pravidlo než
                // `bonus.minimum_amount.monthly`, i když je tam stejné číslo.
                'settlement.payout_threshold' => PayrollRuleValue::moneyMinor(5_000),
                // § 35bb odst. 2 písm. b) ZDP — vlastní příjem manžela nepřesahující
                // 68 000 Kč. Příjem přesně 68 000 Kč nárok neruší.
                'spouse.income_limit' => PayrollRuleValue::moneyMinor(6_800_000),
                'credit.spouse.eligibility' => PayrollRuleValue::manualReview(
                    'Nárok na slevu na manžela závisí na společně hospodařící domácnosti, '
                    . 'na vyživovaném dítěti do 3 let věku, na vlastním příjmu manžela '
                    . 'a na doložení podle § 38l — musí ho posoudit mzdová účetní.',
                ),
                'withholding.rate' => PayrollRuleValue::rate('0.15'),
            ],
            $technicalReview,
        );
    }

    private static function socialInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.social-insurance.v1',
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetCapability::Supported,
            [self::socialSecurity(), self::agreementParticipation()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(self::AVERAGE_WAGE_MINOR),
                'employee.discount.agriculture_dpp' => PayrollRuleValue::manualReview(
                    'Nárok na slevu závisí na zákonných podmínkách sezónní zemědělské činnosti '
                    . 'a musí ho posoudit člověk.',
                ),
                // K OVĚŘENÍ. Sleva pracujícího starobního důchodce na důchodovém
                // pojištění se pro rok 2025 nepodařilo doložit z primárního zdroje
                // (ČSSZ / z. č. 589/1992 Sb. ve znění pro rok 2025) se stejnou
                // jistotou jako zbytek sady. Sazba, která by se lišila jen o desetinu
                // procenta, se propíše do každé výplaty důchodce — proto se tu
                // nedosazuje žádné číslo a výpočet, který na ni sáhne, selže.
                'employee.discount.working_pensioner' => PayrollRuleValue::manualReview(
                    'K OVĚŘENÍ: sazba slevy pracujícího starobního důchodce pro rok 2025 není '
                    . 'v dodané sadě doložená z primárního zdroje. Doplňte ji v administraci '
                    . 'mzdových rulesetů podle znění § 7 z. č. 589/1992 Sb. účinného pro rok 2025.',
                ),
                'employee.rate.ordinary' => PayrollRuleValue::rate('0.071'),
                // § 7a z. č. 589/1992 Sb. — sleva na pojistném za zaměstnance
                // v kratším pracovním poměru. Platí beze změny od 1. 2. 2023, meze
                // se ale vážou na průměrnou mzdu, která je roční.
                'employer.discount.part_time' => PayrollRuleValue::rate('0.05'),
                'employer.discount.part_time.assessment_base_limit_multiple' =>
                    PayrollRuleValue::rate('1.5'),
                'employer.discount.part_time.hourly_assessment_base_limit' =>
                    PayrollRuleValue::rate('0.0115'),
                'employer.discount.part_time.maximum_monthly_millihours' =>
                    PayrollRuleValue::integer(138_000),
                'employer.discount.part_time.maximum_weekly_millihours' =>
                    PayrollRuleValue::integer(30_000),
                'employer.discount.part_time.minimum_weekly_millihours' =>
                    PayrollRuleValue::integer(8_000),
                'employer.rate.ordinary' => PayrollRuleValue::rate('0.248'),
                // K OVĚŘENÍ, obě. Trojice sazeb § 7 odst. 1 písm. a) až c) je znění
                // účinné pro rok 2026 a obě zvláštní sazby v něm rostou po letech
                // („počínaje rokem 2026 29,8 %“, „v roce 2026 27,8 %“). Jaké číslo
                // platilo v roce 2025 a zda kategorie riziková zaměstnání tehdy vůbec
                // existovala, se z primárního zdroje doložit nepodařilo — sekundární
                // zdroje si navíc protiřečí v tom, která sazba patří které kategorii.
                // Dosadit kteroukoli z nich naslepo by znamenalo účtovat zaměstnavateli
                // o procentní body jiné pojistné, proto se tu nedosazuje nic.
                'employer.rate.rescue_and_company_fire_service' => PayrollRuleValue::manualReview(
                    'K OVĚŘENÍ: zvláštní sazba zaměstnavatele za zdravotnické záchranáře a členy '
                    . 'HZS podniku pro rok 2025 není v dodané sadě doložená z primárního zdroje. '
                    . 'Doplňte ji podle znění § 7 odst. 1 z. č. 589/1992 Sb. účinného pro rok 2025.',
                ),
                'employer.rate.risk_employment' => PayrollRuleValue::manualReview(
                    'K OVĚŘENÍ: zvláštní sazba zaměstnavatele za rizikové zaměstnání pro rok 2025 '
                    . 'není v dodané sadě doložená z primárního zdroje — není doloženo ani to, zda '
                    . 'tahle kategorie v roce 2025 existovala. Doplňte ji podle znění § 7 odst. 1 '
                    . 'z. č. 589/1992 Sb. účinného pro rok 2025.',
                ),
                // § 15a z. č. 589/1992 Sb. — 48 × průměrná mzda 46 557 = 2 234 736 Kč.
                'maximum_assessment_base.yearly' => PayrollRuleValue::moneyMinor(223_473_600),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_150_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
                // Pojistné na rizikové zaměstnání (§ 7 odst. 1 písm. c) a spoření
                // na stáří z něj odvozené) nabylo účinnosti až 1. 1. 2026 — v roce
                // 2025 klíče `risky_savings.*` neexistují a záměrně tu nejsou.
            ],
            $technicalReview,
        );
    }

    private static function healthInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.health-insurance.v1',
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetCapability::Supported,
            [self::healthInsuranceMethod(), self::healthAgreements2025()],
            [
                'employee.rate' => PayrollRuleValue::rate('0.045'),
                'employer.rate' => PayrollRuleValue::rate('0.09'),
                // § 3 odst. 6 z. č. 592/1992 Sb. — minimálním vyměřovacím základem
                // zaměstnance je minimální mzda, tedy 20 800 Kč; 13,5 % z ní je
                // 2 808 Kč měsíčně.
                'minimum_assessment_base.monthly' =>
                    PayrollRuleValue::moneyMinor(self::MINIMUM_WAGE_MONTHLY_MINOR),
                'minimum_contribution.monthly' => PayrollRuleValue::moneyMinor(280_800),
                // Zdravotní pojištění používá tytéž rozhodné částky jako nemocenské:
                // DPČ 4 500 Kč, DPP 11 500 Kč.
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_150_000),
                'rounding.total' => PayrollRuleValue::text('ceil-to-1-czk'),
                'total.rate' => PayrollRuleValue::rate('0.135'),
            ],
            $technicalReview,
        );
    }

    private static function employmentThresholds(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.employment-thresholds.v1',
            PayrollRulesetDomain::EmploymentThresholds,
            PayrollRulesetCapability::Supported,
            [self::minimumWage2025(), self::socialSecurity()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(self::AVERAGE_WAGE_MINOR),
                // Sdělení MPSV č. 286/2024 Sb. — 20 800 Kč měsíčně, 124,40 Kč za
                // hodinu při 40hodinové týdenní pracovní době.
                //
                // Zaručená mzda tu ZÁMĚRNĚ není: od 1. 1. 2025 byla v soukromém
                // sektoru zrušena úplně a nejnižší úrovně zaručeného PLATU se týkají
                // jen zaměstnanců odměňovaných platem, tedy veřejné sféry, kterou
                // tenhle modul neobsluhuje.
                'minimum_wage.hourly_40h_week' => PayrollRuleValue::moneyMinor(12_440),
                'minimum_wage.monthly_40h_week' =>
                    PayrollRuleValue::moneyMinor(self::MINIMUM_WAGE_MONTHLY_MINOR),
                // § 93 zákoníku práce — limity přesčasové práce se mezi 2025 a 2026
                // nezměnily; jsou to pravidla, ne roční částky.
                'overtime.annual.early_warning_basis_points' => PayrollRuleValue::integer(8_000),
                'overtime.averaging.max_weeks' => PayrollRuleValue::integer(26),
                'overtime.averaging.weekly_average_max_minutes' => PayrollRuleValue::integer(480),
                'overtime.ordered.weekly_max_minutes' => PayrollRuleValue::integer(480),
                'overtime.ordered.yearly_max_minutes' => PayrollRuleValue::integer(9_000),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_150_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
            ],
            $technicalReview,
        );
    }

    private static function compensationAverages(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.compensation-averages.v1',
            PayrollRulesetDomain::CompensationAverages,
            PayrollRulesetCapability::ManualReview,
            [self::socialSecurity(), self::labourCode(), self::reductionLimits2025()],
            [
                'average_earning.minimum_worked_days' => PayrollRuleValue::integer(21),
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(self::AVERAGE_WAGE_MINOR),
                'leave.agreement_weekly_minutes' => PayrollRuleValue::integer(1_200),
                'leave.entitlement_weeks.statutory_minimum' => PayrollRuleValue::integer(4),
                'leave.minimum_continuous_calendar_days' => PayrollRuleValue::integer(28),
                'leave.minimum_worked_week_multiples' => PayrollRuleValue::integer(4),
                'leave.weeks_per_year' => PayrollRuleValue::integer(52),
                // ── Zákonné příplatky ke mzdě, § 114 až § 118 zákoníku práce ──────
                //
                // Sazby i časové meze jsou pro rok 2025 STEJNÉ jako pro rok 2026:
                // § 114 až § 118 se naposledy měnily novelou účinnou od 1. 1. 2024
                // a tzv. flexinovela účinná od 1. 1. 2025 se dotkla zaručené mzdy,
                // ne příplatků. Kopie tu tedy není nedbalost — je to týž zákonný
                // text, jen pro jiný rok.
                //
                // ROČNĚ PROMĚNNÝ je jediný vstup: § 117 odst. 2 počítá „nejméně
                // 10 % základní sazby minimální mzdy". Ta se ale nebere odsud —
                // základ je text `minimum_wage_hourly` a hodnotu k němu dohledá
                // {@see \MyInvoice\Service\Payroll\Absence\MinimumWageFloor} podle
                // DATA v doméně hranic zaměstnání. Pro rok 2025 tam stojí 124,40 Kč,
                // takže příplatek za ztížené prostředí vyjde z minimální mzdy roku
                // 2025 sám od sebe a v téhle sadě žádná částka být nesmí.
                //
                // Všechny sazby jsou ZÁKONNÉ MINIMUM. Že § 116 a § 118 jako jediné
                // dovolují sjednat i nižší sazbu, kdežto § 114, 115 a 117 mají
                // „nejméně" kogentně, rozhoduje
                // {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy} —
                // ruleset dodává čísla, ne tohle pravidlo.
                'surcharge.difficult_environment.basis' => PayrollRuleValue::text('minimum_wage_hourly'),
                'surcharge.difficult_environment.rate' => PayrollRuleValue::rate('0.10'),
                'surcharge.holiday.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.holiday.rate' => PayrollRuleValue::rate('1.00'),
                'surcharge.holiday.time_off_months' => PayrollRuleValue::integer(3),
                'surcharge.night.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.night.rate' => PayrollRuleValue::rate('0.10'),
                'surcharge.night.window_end_hour' => PayrollRuleValue::integer(6),
                'surcharge.night.window_start_hour' => PayrollRuleValue::integer(22),
                'surcharge.overtime.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.overtime.rate' => PayrollRuleValue::rate('0.25'),
                'surcharge.overtime.time_off_months' => PayrollRuleValue::integer(3),
                'surcharge.weekend.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.weekend.rate' => PayrollRuleValue::rate('0.10'),
                'wage_compensation.compensation_rate' => PayrollRuleValue::rate('0.60'),
                // § 192 odst. 2 zákoníku práce — hodinové redukční hranice jsou denní
                // redukční hranice nemocenského pojištění (§ 21 z. č. 187/2006 Sb.)
                // vynásobené koeficientem 0,175 a zaokrouhlené na haléře nahoru.
                // Pro rok 2025 jsou denní hranice 1 552 / 2 328 / 4 656 Kč, tedy
                // 271,60 / 407,40 / 814,80 Kč za hodinu.
                'wage_compensation.hourly_boundary_1_minor' => PayrollRuleValue::moneyMinor(27_160),
                'wage_compensation.hourly_boundary_2_minor' => PayrollRuleValue::moneyMinor(40_740),
                'wage_compensation.hourly_boundary_3_minor' => PayrollRuleValue::moneyMinor(81_480),
                'wage_compensation.manual_review' => PayrollRuleValue::manualReview(
                    'Nárok na náhradu, úplnost rozvrhu směn, souběh s dávkami a přerušené směny '
                    . 'musí posoudit mzdová účetní.',
                ),
                'wage_compensation.reduction_band_1_rate' => PayrollRuleValue::rate('0.90'),
                'wage_compensation.reduction_band_2_rate' => PayrollRuleValue::rate('0.60'),
                'wage_compensation.reduction_band_3_rate' => PayrollRuleValue::rate('0.30'),
                'wage_compensation.window_calendar_days' => PayrollRuleValue::integer(14),
            ],
            $technicalReview,
        );
    }

    /**
     * Tuzemské cestovní náhrady 2025. Časová pásma, krácení za bezplatné jídlo
     * a zaokrouhlení plynou přímo ze zákoníku práce, peněžní sazby z vyhlášky
     * č. 475/2024 Sb. Na rozdíl od roku 2026 nemá rok 2025 mimořádnou novelu cen
     * pohonných hmot, takže má jedinou verzi platnou celý rok.
     */
    private static function travelAllowances(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.travel-allowances.v1',
            PayrollRulesetDomain::TravelAllowances,
            PayrollRulesetCapability::Supported,
            [self::labourCodeTravel(), self::travelAllowanceDecree2025()],
            [
                'foreign_travel' => PayrollRuleValue::manualReview(
                    'Zahraniční pracovní cesty (zahraniční stravné, kapesné, přepočet měn) tenhle '
                    . 'ruleset neřeší a vyúčtují se ručně podle vyhlášky pro daný stát.',
                ),
                'fuel.average_price.diesel_per_litre' => PayrollRuleValue::moneyMinor(3_470),
                'fuel.average_price.electricity_per_kwh' => PayrollRuleValue::moneyMinor(770),
                'fuel.average_price.petrol_95_per_litre' => PayrollRuleValue::moneyMinor(3_580),
                'fuel.average_price.petrol_98_per_litre' => PayrollRuleValue::moneyMinor(4_050),
                'meal_allowance.band_1.free_meal_reduction_rate' => PayrollRuleValue::rate('0.70'),
                'meal_allowance.band_1.minimum' => PayrollRuleValue::moneyMinor(14_800),
                'meal_allowance.band_1.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(17_700),
                'meal_allowance.band_1.to_minutes' => PayrollRuleValue::integer(720),
                'meal_allowance.band_2.free_meal_reduction_rate' => PayrollRuleValue::rate('0.35'),
                'meal_allowance.band_2.minimum' => PayrollRuleValue::moneyMinor(22_500),
                'meal_allowance.band_2.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(27_100),
                'meal_allowance.band_2.to_minutes' => PayrollRuleValue::integer(1_080),
                'meal_allowance.band_3.free_meal_reduction_rate' => PayrollRuleValue::rate('0.25'),
                'meal_allowance.band_3.minimum' => PayrollRuleValue::moneyMinor(35_300),
                'meal_allowance.band_3.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(42_200),
                'meal_allowance.from_minutes' => PayrollRuleValue::integer(300),
                'meal_allowance.two_day_merge_rule' => PayrollRuleValue::text(
                    'merge-two-calendar-days-when-more-favourable',
                ),
                'rounding.entitlement' => PayrollRuleValue::text('ceil-to-1-czk'),
                'vehicle.basic_compensation.car_per_km' => PayrollRuleValue::moneyMinor(580),
                'vehicle.basic_compensation.single_track_per_km' => PayrollRuleValue::moneyMinor(160),
            ],
            $technicalReview,
        );
    }

    /**
     * Nezabavitelné částky roku 2025 podle nařízení vlády č. 441/2024 Sb.
     *
     * ── Proč se podíly liší od roku 2026 ────────────────────────────────────
     * Rok 2025 počítá nezabavitelnou částku na povinného jako DVĚ TŘETINY ze
     * součtu životního minima jednotlivce a částky na náklady na bydlení, a
     * hranici plně zabavitelného zbytku jako 1,5násobek téhož součtu. Rok 2026
     * má místo toho 85/100 a 19/10 nad základem rozšířeným o paušál na energie.
     * Právě proto se podíly vezou jako čitatel a jmenovatel v datech a ne jako
     * konstanta v kódu — kdyby byly v kódu, historická oprava roku 2025 by se
     * počítala pravidly roku 2026.
     *
     * Paušál na energie se v roce 2025 do základu nezapočítával vůbec; je proto
     * nula, ne chybějící klíč — algoritmus základ sčítá a nula je jeho správný
     * příspěvek, kdežto chybějící klíč by celou doménu fail-closed zablokoval.
     *
     * ── Čtvrtina na manžela ─────────────────────────────────────────────────
     * Tatáž novela od 1. 1. 2025 zrušila automatické započtení manžela jako
     * vyživované osoby; čtvrtina náleží jen při doloženém přiznaném starobním,
     * invalidním (II. nebo III. stupně) nebo sirotčím důchodu. Rulesetem se to
     * neřídí — je to vlastnost konkrétního povinného a nese ji
     * {@see \MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence}, kde
     * je pravidlo implementované od roku 2025 dál. Sada s tím tedy sedí a žádný
     * parametr pro to nepotřebuje.
     */
    private static function enforcementDeductions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2025.enforcement-deductions.v1',
            PayrollRulesetDomain::EnforcementDeductions,
            PayrollRulesetCapability::Supported,
            [
                self::civilProcedure(),
                self::enforcementDecree2025(),
                self::enforcementIncome(),
                self::insolvencyDebtRelief(),
                self::labourCodeDeductions(),
            ],
            [
                // Dvě třetiny základu (nař. vlády č. 441/2024 Sb. ve znění pro 2025).
                'debtor_share.denominator' => PayrollRuleValue::integer(3),
                'debtor_share.numerator' => PayrollRuleValue::integer(2),
                'dependant_share.denominator' => PayrollRuleValue::integer(4),
                'dependant_share.numerator' => PayrollRuleValue::integer(1),
                // Vyhláška č. 485/2000 Sb. — 50 Kč měsíčně; přednostní pořadí podle
                // novely účinné od 1. 1. 2022.
                'employer_flat_fee.maximum.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'employer_flat_fee.order_effective_from' => PayrollRuleValue::text('2022-01-01'),
                // V roce 2025 se paušál na energie do základu nezapočítával.
                'energy_flat.monthly' => PayrollRuleValue::moneyMinor(0),
                // § 279 odst. 5 o. s. ř. — pevná zákonná částka vložená zák. č. 286/2021 Sb.
                // s účinností od 1. 1. 2022, na průměrnou mzdu ani na životní minimum
                // navázaná není a do roku 2026 se nezměnila. Proto je tu totéž číslo
                // jako v sadě 2026, a ne přepočet.
                'four_enforcement_rule.pension_exception_limit' =>
                    PayrollRuleValue::moneyMinor(108_900),
                // Hranice plně zabavitelného zbytku = 1,5 × 19 540 = 29 310 Kč.
                'fully_attachable.factor_denominator' => PayrollRuleValue::integer(2),
                'fully_attachable.factor_numerator' => PayrollRuleValue::integer(3),
                'fully_attachable.threshold.monthly' => PayrollRuleValue::moneyMinor(2_931_000),
                // Životní minimum jednotlivce 4 860 Kč platí beze změny od 1. 1. 2023.
                'life_minimum.monthly' => PayrollRuleValue::moneyMinor(486_000),
                // Částka na náklady na bydlení pro rok 2025: 14 680 Kč (2024: 14 197 Kč).
                'normative_rent.monthly' => PayrollRuleValue::moneyMinor(1_468_000),
                // Základ 4 860 + 14 680 = 19 540 Kč; dvě třetiny = 13 026,67 Kč.
                'protected_amount.calculation_base.monthly' =>
                    PayrollRuleValue::moneyMinor(1_954_000),
                'protected_amount.debtor_base.monthly' => PayrollRuleValue::moneyMinor(1_302_667),
                'rounding.proportional_allocation' =>
                    PayrollRuleValue::text('floor_minor_units_then_largest_remainder'),
                'rounding.protected_total' => PayrollRuleValue::text('ceil_to_whole_czk_after_sum'),
                'rounding.thirds_base' =>
                    PayrollRuleValue::text('floor_to_whole_czk_divisible_by_three'),
            ],
            $technicalReview,
        );
    }

    /**
     * @param non-empty-list<RulesetSource> $sources
     * @param non-empty-array<string, PayrollRuleValue> $parameters
     */
    private static function version(
        string $id,
        PayrollRulesetDomain $domain,
        PayrollRulesetCapability $capability,
        array $sources,
        array $parameters,
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            '2025.1.0',
            $domain,
            '2025-01-01',
            '2025-12-31',
            PayrollRulesetLifecycle::Active,
            $capability,
            $sources,
            $parameters,
            VendorRulesetApprover::approval(
                $technicalReview,
                VendorRulesetApprover::APPROVED_ON_2025,
            ),
            $technicalReview,
        );
    }

    private static function financialAdministration(): RulesetSource
    {
        return new RulesetSource(
            'fs-dependent-activity-2025',
            'Finanční správa: aktuální dotazy a odpovědi k dani z příjmů za období 2024 a 2025',
            'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/dotazy-a-odpovedi/2025/aktualni-dotazy-a-odpovedi-dzp-2024-a-2025',
            self::RETRIEVED_ON,
        );
    }

    private static function benefitExemptionInformation(): RulesetSource
    {
        return new RulesetSource(
            'fs-benefit-exemption-2025',
            'Finanční správa: informace k upravenému znění § 6 odst. 9 písm. d) ZDP od 1. 1. 2025',
            'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/informace-stanoviska-sdeleni/2025/informace-k-upravenemu-zneni-6-odst-9',
            self::RETRIEVED_ON,
        );
    }

    private static function socialSecurity(): RulesetSource
    {
        return new RulesetSource(
            'cssz-key-data-2025',
            'ČSSZ: Přehled nejdůležitějších údajů pro sociální zabezpečení v roce 2025',
            'https://www.cssz.gov.cz/-/prehled-nejdulezitejsich-udaju-pro-socialni-zabezpeceni-v-roce-2025',
            self::RETRIEVED_ON,
        );
    }

    private static function agreementParticipation(): RulesetSource
    {
        return new RulesetSource(
            'cssz-dpp-participation-2025',
            'ČSSZ: dohody o provedení práce — pravidla pro účast na pojištění od 1. 1. 2025',
            'https://www.cssz.gov.cz/-/dohody-o-provedeni-prace-pravidla-pro-ucast-na-pojisteni-od-1-1-2025',
            self::RETRIEVED_ON,
        );
    }

    private static function minimumWage2025(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-minimum-wage-2025',
            'MPSV: sdělení č. 286/2024 Sb. o výši minimální mzdy pro rok 2025 a příručka XVIII.1',
            'https://ppropo.mpsv.cz/xviii1minimalnimzdaanejnizsiurov',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsuranceMethod(): RulesetSource
    {
        return new RulesetSource(
            'vzp-employer-method',
            'VZP: Plátce pojistného – zaměstnavatel',
            'https://www.vzp.cz/platci/informace/povinnosti-platcu-metodika/2-4-platce-pojistneho-zamestnavatel',
            self::RETRIEVED_ON,
        );
    }

    private static function healthAgreements2025(): RulesetSource
    {
        return new RulesetSource(
            'vzp-dpp-dpc-2025',
            'VZP: změny ve zdravotním pojištění pro DPP a DPČ od 1. 1. 2025',
            'https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/zmeny-ve-zdravotnim-pojisteni-pro-dpp-a-dpc',
            self::RETRIEVED_ON,
        );
    }

    private static function reductionLimits2025(): RulesetSource
    {
        return new RulesetSource(
            'cssz-reduction-limits-2025',
            'ČSSZ: redukční hranice pro úpravu denního vyměřovacího základu platné v roce 2025',
            'https://www.cssz.gov.cz/web/cz/vyse-a-vyplata-davek',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCode(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-current',
            'MPSV: zákoník práce č. 262/2006 Sb., § 192, § 213 a § 351 až 362',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeTravel(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-travel',
            'MPSV: zákoník práce č. 262/2006 Sb., § 156 až 189 (cestovní náhrady, časová pásma stravného a krácení za bezplatné jídlo)',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function travelAllowanceDecree2025(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-travel-allowance-decree-2025',
            'MPSV: vyhláška č. 475/2024 Sb., o sazbě základní náhrady, stravném a průměrné ceně pohonných hmot pro rok 2025',
            'https://ppropo.mpsv.cz/vyhlaska_475_2024',
            self::RETRIEVED_ON,
        );
    }

    private static function civilProcedure(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-civil-procedure',
            'e-Sbírka: občanský soudní řád č. 99/1963 Sb.',
            'https://www.e-sbirka.cz/sb/1963/99',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementDecree2025(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-enforcement-regulation-2025',
            'e-Sbírka: nařízení vlády č. 441/2024 Sb., kterým se mění nařízení vlády č. 595/2006 Sb., o nezabavitelných částkách (účinné od 1. 1. 2025)',
            'https://www.e-sbirka.cz/sb/2024/441',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementIncome(): RulesetSource
    {
        return new RulesetSource(
            'justice-enforcement-income',
            'Justice.cz: srážky ze mzdy a jiných příjmů',
            'https://exekuce.justice.cz/srazky-ze-mzdy-a-jinych-prijmu/',
            self::RETRIEVED_ON,
        );
    }

    private static function insolvencyDebtRelief(): RulesetSource
    {
        return new RulesetSource(
            'justice-insolvency-debt-relief',
            'Justice.cz: oddlužení — jak ven z dluhové pasti',
            'https://insolvence.justice.cz/jak-ven-z-dluhove-pasti/oddluzeni/',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeDeductions(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-deductions',
            'MPSV: srážky z příjmu z pracovněprávního vztahu',
            'https://ppropo.mpsv.cz/pdf/XXI4Srazkyzprijmuzpracovnepravni.pdf',
            self::RETRIEVED_ON,
        );
    }
}
