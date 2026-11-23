<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Dodaná legislativní sada pro rok 2027 — immutable ročník vedle
 * {@see CzechPayrollRulesets2026}, skládaný v {@see CzechPayrollRulesets}.
 *
 * ── Co ročník pokrývá ───────────────────────────────────────────────────────
 * Jen pět domén, bez kterých se mzda nespočítá
 * ({@see PayrollRulesetYearCoverage::CALCULATION_CRITICAL_DOMAINS}). Hodnoty
 * jsou vyhlášené ve Sbírce a doložené rešerší k 28. 10. 2026:
 *
 *  • minimální mzda 24 900 Kč a 148,30 Kč za hodinu — nař. vlády č. 164/2026 Sb.
 *    (koeficient 0,446) a sdělení MPSV č. 171/2026 Sb.,
 *  • průměrná mzda 51 663 Kč — § 23b odst. 4 z. č. 589/1992 Sb. ze vstupů nař.
 *    vlády č. 177/2026 Sb. (48 900 Kč × 1,0565 = 51 662,85 → nahoru na koruny);
 *    samo číslo nařízení neobsahuje, je to výpočet, který potvrzují všechna
 *    z něj odvozená vyhlášená čísla (12 500 / 5 000 Kč),
 *  • rozhodný příjem 5 000 Kč — sdělení MPSV č. 186/2026 Sb.,
 *  • rozhodná částka u DPP 12 500 Kč — sdělení MPSV č. 184/2026 Sb.,
 *  • redukční hranice 1 723 / 2 584 / 5 167 Kč — sdělení MPSV č. 185/2026 Sb.,
 *  • sleva na studenta 4 020 Kč ročně — § 35bc ZDP ve znění zák. č. 180/2026 Sb.,
 *  • sazba zaměstnavatele u rizikového zaměstnání 28,8 % — § 7 odst. 1 písm. c)
 *    bod 3 z. č. 589/1992 Sb.
 *
 * Ostatní parametry jsou zákonná čísla, která se proti roku 2026 nemění (sazby
 * pojistného a daně, slevy podle § 35ba, zvýhodnění na děti, lhůty a podíly
 * podle zákoníku práce). Nesou se proto se stejnou hodnotou — jejich nezměněné
 * znění k 1. 1. 2027 ověřila tatáž rešerše.
 *
 * ── Co ročník ZÁMĚRNĚ nemá ──────────────────────────────────────────────────
 * Cestovní náhrady: vyhláška podle § 189 zákoníku práce pro rok 2027 k 28. 10.
 * 2026 nevyšla. Sada proto doménu NEMÁ — cestovní náhrada za rok 2027 spadne
 * fail-closed na chybějícím rulesetu, nikdy se nespočítá sazbami roku 2026.
 * Totéž platí pro nezabavitelné částky (nařízení pro rok 2027 není doložené)
 * a pro číselníky a podání JMHZ (číselníky obcí a států s účinností pro rok
 * 2027 ČSSZ nevydala). Lhůty JMHZ sada nese: § 7 odst. 1 z. č. 323/2025 Sb.
 * je trvalé pravidlo zákona.
 *
 * Uvnitř daně z příjmů čeká na vyhlášení jediná hodnota:
 *
 *  • `benefit_exemption.meal.per_shift` — limit stravenkového paušálu je 70 %
 *    horní hranice stravného, a ta pro rok 2027 vyhlášená není.
 *
 * `benefit_exemption.non_cash_leisure.yearly` nese od 1. 1. 2027 hodnotu
 * `unlimited`: zák. č. 180/2026 Sb. zrušil u § 6 odst. 9 písm. d) bodu 2 ZDP
 * společný limit poloviny průměrné mzdy, rekreace a zájezd mají vlastní koš
 * 20 000 Kč podle bodu 3.
 *
 * ── Co zůstává podle roku 2026 a mění se samostatně ─────────────────────────
 * Zrušení srážkové daně podle § 6 odst. 4 ZDP (zák. č. 360/2025 Sb.) je změna
 * ALGORITMU, ne hodnot: od 1. 1. 2027 jde o samostatný základ zálohy se
 * zaokrouhlením nahoru. Tahle sada nese aktuální rozhodné částky (12 500 / 5 000
 * Kč), sazbu 15 % a algoritmus nemění.
 */
final class CzechPayrollRulesets2027
{
    public const RETRIEVED_ON = '2026-10-28';

    public static function provider(): PayrollRulesetProvider
    {
        $technicalReview = new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            self::RETRIEVED_ON,
            'Manifest oficiálních zdrojů ve Sbírce zákonů, kontrola přesných hodnot a testy bajtové '
            . 'stability — technická kontrola, ne odborné ani právní schválení.',
        );

        return new PayrollRulesetProvider([
            self::incomeTax($technicalReview),
            self::socialInsurance($technicalReview),
            self::healthInsurance($technicalReview),
            self::employmentThresholds($technicalReview),
            self::compensationAverages($technicalReview),
            self::jmhzDeadlines($technicalReview),
        ]);
    }

    /**
     * Lhůta měsíčního hlášení za vykazovaná období roku 2027: § 7 odst. 1
     * zákona č. 323/2025 Sb. („ve lhůtě od prvního do dvacátého dne
     * kalendářního měsíce bezprostředně následujícího"). Je to trvalé
     * pravidlo zákona; přechodné okno § 34 odst. 1 platilo jen pro leden až
     * březen 2026 a jiné přechodné ustanovení pro rok 2027 zákon nemá.
     * Tvar parametrů je stejný jako u ročníku 2026 (JmhzDeadlinePolicy).
     */
    private static function jmhzDeadlines(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-jmhz-deadlines-2027.regular.v1',
            PayrollRulesetDomain::Deadlines,
            PayrollRulesetCapability::Supported,
            [self::jmhzAct2027()],
            [
                'jmhz.deadline.calendar_basis' => PayrollRuleValue::text('business_days'),
                'jmhz.deadline.cancellation_allowed' => PayrollRuleValue::boolean(true),
                'jmhz.deadline.due_day' => PayrollRuleValue::integer(20),
                'jmhz.deadline.due_shift' => PayrollRuleValue::text('next_czech_working_day'),
                'jmhz.deadline.earliest_day' => PayrollRuleValue::integer(1),
                'jmhz.deadline.month_offset' => PayrollRuleValue::integer(1),
                'jmhz.deadline.rule' => PayrollRuleValue::text('following_month_day_window'),
            ],
            $technicalReview,
        );
    }

    private static function jmhzAct2027(): RulesetSource
    {
        return new RulesetSource(
            'zakonyprolidi-jmhz-act-2027',
            'Zákon č. 323/2025 Sb., o jednotném měsíčním hlášení zaměstnavatele, § 7 odst. 1',
            'https://www.zakonyprolidi.cz/cs/2025-323',
            self::RETRIEVED_ON,
        );
    }

    private static function incomeTax(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2027.income-tax.v1',
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetCapability::Supported,
            [self::incomeTaxAct2027(), self::incomeTaxAmendment2027(), self::averageWage2027(), self::minimumWage2027()],
            [
                'advance.high_rate' => PayrollRuleValue::rate('0.23'),
                // § 38h odst. 2 ZDP: trojnásobek průměrné mzdy, 3 × 51 663 Kč.
                'advance.high_threshold.monthly' => PayrollRuleValue::moneyMinor(15_498_900),
                'advance.low_rate' => PayrollRuleValue::rate('0.15'),
                'advance.rounding.base_above_100_czk' => PayrollRuleValue::text('ceil-to-100-czk'),
                'advance.rounding.base_up_to_100_czk' => PayrollRuleValue::text('ceil-to-1-czk'),
                'advance.rounding.result' => PayrollRuleValue::text('ceil-to-1-czk'),
                'benefit_exemption.meal.minimum_work_minutes' => PayrollRuleValue::integer(180),
                // 70 % horní hranice stravného 5 až 12 hodin; vyhláška MPSV podle
                // § 189 zákoníku práce pro rok 2027 k datu stažení nevyšla. Hodnota
                // roku 2026 by tu byla tichý odhad. V administraci se parametr
                // ukazuje jako „čeká na vyhlášení" (PayrollRuleParameterCatalog).
                'benefit_exemption.meal.per_shift' => PayrollRuleValue::manualReview(
                    'ČEKÁ NA VYHLÁŠENÍ: horní hranici stravného pro rok 2027 stanoví vyhláška MPSV '
                    . 'podle § 189 zákoníku práce, obvykle v prosinci. Do té doby nelze odvodit '
                    . 'osvobozený příspěvek na stravování za směnu.',
                ),
                'benefit_exemption.meal.second_contribution_day_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.second_contribution_shift_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.shift_rate' => PayrollRuleValue::rate('0.70'),
                // § 6 odst. 9 písm. d) bod 1 ZDP: průměrná mzda 51 663 Kč.
                'benefit_exemption.non_cash_health.yearly' => PayrollRuleValue::moneyMinor(5_166_300),
                // § 6 odst. 9 písm. d) bod 2 ZDP ve znění zák. č. 180/2026 Sb.: věta
                // „v úhrnu do výše poloviny průměrné mzdy" vypadla, plnění jsou
                // osvobozena bez číselného limitu. Rekreace a zájezd mají vlastní
                // koš podle bodu 3 (benefit_exemption.recreation.yearly). Hodnota
                // musí souhlasit s PayrollBenefitExemptionBasket::isUnlimited(),
                // hlídá to CzechPayrollRulesets2027Test.
                'benefit_exemption.non_cash_leisure.yearly' => PayrollRuleValue::text('unlimited'),
                'benefit_exemption.old_age_savings.yearly' => PayrollRuleValue::moneyMinor(5_000_000),
                // § 6 odst. 9 písm. d) bod 3 ZDP ve znění zák. č. 180/2026 Sb.:
                // rekreace a zájezd samostatně nejvýš 20 000 Kč za zdaňovací období.
                'benefit_exemption.recreation.yearly' => PayrollRuleValue::moneyMinor(2_000_000),
                'benefit_exemption.temporary_accommodation.monthly' =>
                    PayrollRuleValue::moneyMinor(350_000),
                // § 21g odst. 1 a § 35bd ZDP: minimální mzda k 1. 1. roku (NV č. 164/2026 Sb.)
                // – strop slevy za umístění dítěte; hlídaná proti 6 × bonus.minimum_income.yearly.
                'minimum_wage.monthly' => PayrollRuleValue::moneyMinor(2_490_000),
                'bonus.minimum_amount.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'bonus.minimum_amount.yearly' => PayrollRuleValue::moneyMinor(10_000),
                // § 35d odst. 4: polovina minimální mzdy 24 900 Kč zaokrouhlená
                // dolů = 12 450 Kč; § 35c odst. 4: šestinásobek = 149 400 Kč.
                // Minimální mzda je ta účinná k 1. dni zdaňovacího období (§ 21g).
                'bonus.minimum_income.monthly' => PayrollRuleValue::moneyMinor(1_245_000),
                'bonus.minimum_income.yearly' => PayrollRuleValue::moneyMinor(14_940_000),
                'credit.child.first.monthly' => PayrollRuleValue::moneyMinor(126_700),
                'credit.child.second.monthly' => PayrollRuleValue::moneyMinor(186_000),
                'credit.child.third_and_next.monthly' => PayrollRuleValue::moneyMinor(232_000),
                'credit.disability.basic.monthly' => PayrollRuleValue::moneyMinor(21_000),
                'credit.disability.extended.monthly' => PayrollRuleValue::moneyMinor(42_000),
                'credit.spouse.eligibility' => PayrollRuleValue::manualReview(
                    'Nárok na slevu na manžela závisí na společně hospodařící domácnosti, '
                    . 'na vyživovaném dítěti do 3 let věku, na vlastním příjmu manžela '
                    . 'a na doložení podle § 38l — musí ho posoudit mzdová účetní.',
                ),
                'credit.spouse.yearly' => PayrollRuleValue::moneyMinor(2_484_000),
                'credit.spouse.ztp_p_multiplier' => PayrollRuleValue::integer(2),
                // § 35bc odst. 1 ZDP ve znění zák. č. 180/2026 Sb.: „Výše slevy na
                // studenta činí 4020 Kč." Měsíčně dvanáctina podle § 35d odst. 2
                // (od 1. 1. 2027 odkazuje na § 35ba odst. 1 písm. a) až f)), tj.
                // 335 Kč. Ve výčtu pro nerezidenta (§ 35ba odst. 2) sleva NENÍ.
                'credit.student.monthly' => PayrollRuleValue::moneyMinor(33_500),
                'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
                'credit.ztp_p.monthly' => PayrollRuleValue::moneyMinor(134_500),
                // Rozhodná částka u DPP — § 7a odst. 1 z. č. 187/2006 Sb., 25 %
                // průměrné mzdy dolů na celých 500 Kč, sdělení MPSV č. 184/2026 Sb.
                // Test zůstává OSTRÝ („nedosáhne").
                'dpp.withholding.threshold' => PayrollRuleValue::moneyMinor(1_250_000),
                // Rozhodný příjem pro nemocenské pojištění — sdělení MPSV
                // č. 186/2026 Sb.
                'other.withholding.threshold' => PayrollRuleValue::moneyMinor(500_000),
                'settlement.payout_threshold' => PayrollRuleValue::moneyMinor(5_000),
                'spouse.income_limit' => PayrollRuleValue::moneyMinor(6_800_000),
                'withholding.rate' => PayrollRuleValue::rate('0.15'),
            ],
            $technicalReview,
        );
    }

    private static function socialInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2027.social-insurance.v1',
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetCapability::Supported,
            [self::socialInsuranceAct2027(), self::averageWage2027(), self::agreementParticipation2027(), self::sicknessParticipation2027()],
            [
                'employee.discount.agriculture_dpp' => PayrollRuleValue::manualReview(
                    'Nárok na slevu závisí na zákonných podmínkách sezónní zemědělské činnosti '
                    . 'a musí ho posoudit člověk.',
                ),
                'employee.discount.working_pensioner' => PayrollRuleValue::rate('0.065'),
                'employee.rate.ordinary' => PayrollRuleValue::rate('0.071'),
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
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(5_166_300),
                'employer.rate.ordinary' => PayrollRuleValue::rate('0.248'),
                'employer.rate.rescue_and_company_fire_service' => PayrollRuleValue::rate('0.298'),
                // § 7 odst. 1 písm. c) bod 3: „v roce 2027 28,8 %" (25,5 % důchodové
                // + 2,1 % nemocenské + 1,2 % SPZ). V roce 2028 to bude 29,8 %.
                'employer.rate.risk_employment' => PayrollRuleValue::rate('0.288'),
                // § 15a odst. 1: 48 × 51 663 Kč.
                'maximum_assessment_base.yearly' => PayrollRuleValue::moneyMinor(247_982_400),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_250_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(500_000),
                'risky_savings.effective_from' => PayrollRuleValue::text('2026-01-01'),
                'risky_savings.minimum_shift_eighths' => PayrollRuleValue::integer(24),
                'risky_savings.payment_due.months_after_period' => PayrollRuleValue::integer(1),
                'risky_savings.payment_due.rule' => PayrollRuleValue::text('last_day_of_month'),
                'risky_savings.rate' => PayrollRuleValue::rate('0.04'),
            ],
            $technicalReview,
        );
    }

    private static function healthInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2027.health-insurance.v1',
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetCapability::Supported,
            [self::healthInsuranceAct2027(), self::minimumWage2027(), self::agreementParticipation2027(), self::sicknessParticipation2027()],
            [
                'employee.rate' => PayrollRuleValue::rate('0.045'),
                'employer.rate' => PayrollRuleValue::rate('0.09'),
                // § 3 odst. 6 z. č. 592/1992 Sb.: minimální mzda 24 900 Kč;
                // 13,5 % = 3 361,50 Kč → nahoru na koruny 3 362 Kč.
                'minimum_assessment_base.monthly' => PayrollRuleValue::moneyMinor(2_490_000),
                'minimum_contribution.monthly' => PayrollRuleValue::moneyMinor(336_200),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(500_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_250_000),
                'rounding.total' => PayrollRuleValue::text('ceil-to-1-czk'),
                'total.rate' => PayrollRuleValue::rate('0.135'),
            ],
            $technicalReview,
        );
    }

    private static function employmentThresholds(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2027.employment-thresholds.v1',
            PayrollRulesetDomain::EmploymentThresholds,
            PayrollRulesetCapability::Supported,
            [self::minimumWage2027(), self::minimumWageAnnouncement2027(), self::averageWage2027(), self::agreementParticipation2027(), self::sicknessParticipation2027()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(5_166_300),
                'minimum_wage.hourly_40h_week' => PayrollRuleValue::moneyMinor(14_830),
                'minimum_wage.monthly_40h_week' => PayrollRuleValue::moneyMinor(2_490_000),
                'minimum_wage.standard_weekly_minutes' => PayrollRuleValue::integer(2_400),
                'overtime.ordered.weekly_max_minutes' => PayrollRuleValue::integer(480),
                'overtime.ordered.yearly_max_minutes' => PayrollRuleValue::integer(9_000),
                'overtime.averaging.max_weeks' => PayrollRuleValue::integer(26),
                'overtime.averaging.weekly_average_max_minutes' => PayrollRuleValue::integer(480),
                'overtime.annual.early_warning_basis_points' => PayrollRuleValue::integer(8_000),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(500_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_250_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(500_000),
            ],
            $technicalReview,
        );
    }

    private static function compensationAverages(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2027.compensation-averages.v1',
            PayrollRulesetDomain::CompensationAverages,
            PayrollRulesetCapability::ManualReview,
            [self::averageWage2027(), self::reductionLimits2027(), self::minimumWage2027()],
            [
                'average_earning.minimum_worked_days' => PayrollRuleValue::integer(21),
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(5_166_300),
                'leave.agreement_weekly_minutes' => PayrollRuleValue::integer(1_200),
                'leave.entitlement_weeks.statutory_minimum' => PayrollRuleValue::integer(4),
                'leave.minimum_continuous_calendar_days' => PayrollRuleValue::integer(28),
                'leave.minimum_worked_week_multiples' => PayrollRuleValue::integer(4),
                'leave.weeks_per_year' => PayrollRuleValue::integer(52),
                'sickness_benefit.paternity_support_days' => PayrollRuleValue::integer(14),
                'sickness_benefit.care_support_days' => PayrollRuleValue::integer(9),
                'sickness_benefit.care_support_days_lone_carer' => PayrollRuleValue::integer(16),
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
                'surcharge.standby.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.standby.rate' => PayrollRuleValue::rate('0.10'),
                'surcharge.weekend.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.weekend.rate' => PayrollRuleValue::rate('0.10'),
                'wage_compensation.compensation_rate' => PayrollRuleValue::rate('0.60'),
                // § 192 odst. 2 zákoníku práce: denní redukční hranice ze sdělení
                // MPSV č. 185/2026 Sb. (1 723 / 2 584 / 5 167 Kč) × 0,175,
                // zaokrouhleno nahoru na haléře.
                'wage_compensation.hourly_boundary_1_minor' => PayrollRuleValue::moneyMinor(30_153),
                'wage_compensation.hourly_boundary_2_minor' => PayrollRuleValue::moneyMinor(45_220),
                'wage_compensation.hourly_boundary_3_minor' => PayrollRuleValue::moneyMinor(90_423),
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
            '2027.1.0',
            $domain,
            '2027-01-01',
            '2027-12-31',
            PayrollRulesetLifecycle::Active,
            $capability,
            $sources,
            $parameters,
            VendorRulesetApprover::approval($technicalReview, VendorRulesetApprover::APPROVED_ON_2027),
            $technicalReview,
        );
    }

    private static function incomeTaxAct2027(): RulesetSource
    {
        return new RulesetSource(
            'zakonyprolidi-income-tax-act-2027',
            'Zákon č. 586/1992 Sb., o daních z příjmů, ve znění účinném od 1. 1. 2027',
            'https://www.zakonyprolidi.cz/cs/1992-586/zneni-20270101',
            self::RETRIEVED_ON,
        );
    }

    private static function incomeTaxAmendment2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-180',
            'Zákon č. 180/2026 Sb., část druhá — změna zákona o daních z příjmů (sleva na studenta, '
            . 'benefity, hranice přiznání), účinnost 1. 1. 2027',
            'https://www.zakonyprolidi.cz/cs/2026-180',
            self::RETRIEVED_ON,
        );
    }

    private static function averageWage2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-177',
            'Nařízení vlády č. 177/2026 Sb. — všeobecný vyměřovací základ za rok 2025 a přepočítací '
            . 'koeficient; průměrná mzda 2027 podle § 23b odst. 4 z. č. 589/1992 Sb. = 51 663 Kč',
            'https://www.zakonyprolidi.cz/cs/2026-177',
            self::RETRIEVED_ON,
        );
    }

    private static function minimumWage2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-164',
            'Nařízení vlády č. 164/2026 Sb. — koeficient minimální mzdy pro rok 2027 (0,446)',
            'https://www.zakonyprolidi.cz/cs/2026-164',
            self::RETRIEVED_ON,
        );
    }

    private static function minimumWageAnnouncement2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-171',
            'Sdělení MPSV č. 171/2026 Sb. — minimální mzda 24 900 Kč a 148,30 Kč za hodinu pro rok 2027',
            'https://www.zakonyprolidi.cz/cs/2026-171',
            self::RETRIEVED_ON,
        );
    }

    private static function agreementParticipation2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-184',
            'Sdělení MPSV č. 184/2026 Sb. — částka rozhodná pro účast na pojištění u dohody '
            . 'o provedení práce 12 500 Kč',
            'https://www.zakonyprolidi.cz/cs/2026-184',
            self::RETRIEVED_ON,
        );
    }

    private static function sicknessParticipation2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-186',
            'Sdělení MPSV č. 186/2026 Sb. — rozhodný příjem pro účast na nemocenském pojištění 5 000 Kč',
            'https://www.zakonyprolidi.cz/cs/2026-186',
            self::RETRIEVED_ON,
        );
    }

    private static function reductionLimits2027(): RulesetSource
    {
        return new RulesetSource(
            'sb-2026-185',
            'Sdělení MPSV č. 185/2026 Sb. — redukční hranice denního vyměřovacího základu pro rok 2027',
            'https://www.zakonyprolidi.cz/cs/2026-185',
            self::RETRIEVED_ON,
        );
    }

    private static function socialInsuranceAct2027(): RulesetSource
    {
        return new RulesetSource(
            'zakonyprolidi-social-insurance-act-2027',
            'Zákon č. 589/1992 Sb., o pojistném na sociální zabezpečení, § 7, § 15a a § 23b',
            'https://www.zakonyprolidi.cz/cs/1992-589',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsuranceAct2027(): RulesetSource
    {
        return new RulesetSource(
            'zakonyprolidi-health-insurance-act',
            'Zákon č. 592/1992 Sb., o pojistném na veřejné zdravotní pojištění',
            'https://www.zakonyprolidi.cz/cs/1992-592',
            self::RETRIEVED_ON,
        );
    }
}
