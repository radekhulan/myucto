<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;

/**
 * Roční daňové konstanty (CZ) — referenční DEFAULTY / fallback.
 *
 * V produkci se čtou přes {@see \MyInvoice\Repository\TaxConstantsRepository},
 * který vrací admin override z tabulky `tax_constants` (migrace 0079), a teprve
 * když override pro daný rok není, spadne na tyhle hodnoty. Tahle třída je tedy
 * jediný zdroj výchozích čísel v kódu (a fallback pro testy/CLI bez DB).
 *
 * Hodnoty ověřeny k 2026-07 dle Finanční správy / ČSSZ / VZP:
 *  - paušální daň 2024 7 498/16 745/27 139 Kč/měs, 2025 8 716/16 745/27 139 Kč/měs;
 *    2026 9 984/16 745/27 139 Kč/měs do června, od 1. 7. 2026 klesá 1. pásmo na
 *    9 162 Kč/měs (2. a 3. beze změny — na minimum OSVČ je navázaná jen důchodová
 *    složka 1. pásma)
 *  - průměrná mzda 2024 43 967 Kč, 2025 46 557 Kč, 2026 48 967 Kč → hranice 23 % = 36×
 *  - vyměřovací základ: sociální 55 % zisku, zdravotní 50 % zisku (§7)
 *  - min. roční vyměřovací základ: soc. hlavní 30 % (2024) / 35 % (2025) / 40 % (2026) prům. mzdy,
 *    zdravotní 50 % prům. mzdy × 12
 */
final class TaxConstants
{
    /**
     * Konstanty pro daný rok. Neznámý rok se nesmí tiše počítat hodnotami jiného
     * období; chybějící sadu musí doplnit release nebo explicitní DB override.
     *
     * `pausal_annual` se dopočítá z rozvrhu měsíčních záloh pro daný rok
     * ({@see PausalSchedule}).
     * @return array<string, mixed>
     */
    public static function forYear(int $year): array
    {
        if (isset(self::TABLE[$year])) {
            return self::withDerived(self::TABLE[$year], $year);
        }
        throw new \OutOfRangeException('Pro rok ' . $year . ' nejsou ověřené daňové konstanty.');
    }

    /**
     * Doplní odvozené klíče (`pausal_annual` z `pausal_monthly` a hranici srážkové
     * daně z DPP z mzdového rulesetu). Voláno i repository po sloučení s DB
     * override, aby uložená roční částka nemohla přebít měsíční rozvrh.
     *
     * @param array<string, mixed> $constants
     * @return array<string, mixed>
     */
    public static function withDerived(array $constants, int $year): array
    {
        $constants = self::withDppWithholdingThreshold($constants, $year);
        $segments = PausalSchedule::normalize($constants['pausal_monthly'] ?? []);
        if ($segments === []) {
            // Legacy override bez rozvrhu: roční částku ber doslova, měsíční
            // hodnoty jen dopočti pro zobrazení.
            $constants['pausal_monthly'] = PausalSchedule::fromAnnual($year, $constants['pausal_annual'] ?? []);
            return $constants;
        }
        // Rozvrh se ukotví k požadovanému roku: segmenty z jiného roku (fallback na
        // poslední známý rok) se srazí na jedinou sazbu platnou k 1. 1. — pro budoucí
        // rok platí poslední známá záloha celý rok, ne zopakovaný schod uprostřed roku.
        $anchored = [['from' => sprintf('%04d-01-01', $year)] + PausalSchedule::monthlyAt($year, 1, $segments)];
        foreach ($segments as $seg) {
            if (substr((string) $seg['from'], 0, 4) === (string) $year) {
                $anchored[] = $seg;
            }
        }
        $segments = PausalSchedule::normalize($anchored);

        $constants['pausal_monthly'] = $segments;
        $constants['pausal_annual']  = PausalSchedule::annual($year, $segments);
        return $constants;
    }

    /**
     * Hranice srážkové daně z DPP (§ 6 odst. 4 ZDP) pro roky, které pokrývá mzdový
     * ruleset — JEDINÝ zdroj pravdy pro tuhle hodnotu.
     *
     * ── Proč to tady vůbec je ──────────────────────────────────────────────────
     * Hranice žila v systému dvakrát: `dpp_withholding_limit` v téhle tabulce
     * (12 000 Kč, porovnávané `<=`) a `dpp.withholding.maximum` v rulesetu
     * (11 999 Kč, také `<=`). Odměna PŘESNĚ 12 000 Kč tak dostala jiný daňový
     * režim podle toho, kterou cestou firma jela — moderní mzdový běh ji zdanil
     * zálohou, legacy zaúčtování srážkou. Nešlo o kosmetiku: jsou to jiné peníze,
     * jiné odvody a jiný řádek v podání.
     *
     * Ruleset je administrovatelný (MZ-02-W08), takže vyhrává on. Tahle metoda
     * jeho hodnotu ZRCADLÍ, nekopíruje — v `self::TABLE` pro pokryté roky žádné
     * číslo není, takže se nemá co rozejít.
     *
     * Vědomé omezení: čte se VÝCHOZÍ sada z kódu, ne override z tabulky
     * `payroll_rulesets`. Legacy účetní cesta ({@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService})
     * běží bez DI na ruleset registry; admin override se proto projeví ve mzdovém
     * běhu, ne tady. Rozdíl mezi 12 000 a 11 999 to neřeší jen zdánlivě — ten je
     * pryč nadobro; zbývá jen scénář „admin si hranici přepsal ručně".
     *
     * Případný override z tabulky `tax_constants` se pro pokryté roky ignoruje
     * ZÁMĚRNĚ: dvě administrátorské cesty k téže hranici by vrátily přesně ten
     * rozpor, který tahle změna odstraňuje.
     *
     * @param array<string, mixed> $constants
     * @return array<string, mixed>
     */
    private static function withDppWithholdingThreshold(array $constants, int $year): array
    {
        $thresholdMinor = self::rulesetDppWithholdingThresholdMinor($year);
        if ($thresholdMinor === null) {
            return $constants;
        }
        $constants['dpp_withholding_limit'] = $thresholdMinor / 100;
        // Ruleset existuje až od roku 2026, tedy výhradně pro znění § 6 odst. 4
        // písm. a) ZDP po novele č. 470/2024 Sb. („nedosáhne") — hranice je ostrá.
        $constants['dpp_withholding_limit_inclusive'] = false;

        return $constants;
    }

    /**
     * Rozhodná částka v haléřích, nebo `null` pro roky bez mzdového rulesetu.
     *
     * Ročníky, které registry drží, se čtou z něj — jinak by táž hranice žila
     * na dvou místech a rozešla by se. Rok 2024 ruleset nemá (a mít nebude:
     * do 31. 12. 2024 zněl § 6 odst. 4 ZDP „nepřesáhne 10 000 Kč“, tedy pevná
     * částka s NEOSTRÝM operátorem, kterou dnešní klíč `*.threshold` neumí
     * vyjádřit) a hodnotu si drží sám v {@see self::TABLE}.
     */
    private static function rulesetDppWithholdingThresholdMinor(int $year): ?int
    {
        /** @var array<int, int|null> $cache */
        static $cache = [];
        if (array_key_exists($year, $cache)) {
            return $cache[$year];
        }

        $cache[$year] = null;
        if (in_array($year, [2025, 2026], true)) {
            $ruleset = CzechPayrollRulesets::provider()->forDate(
                PayrollRulesetDomain::IncomeTax,
                sprintf('%04d-01-01', $year),
            );
            $value = $ruleset->parameters['dpp.withholding.threshold'] ?? null;
            if ($value === null || $value->type !== 'money_minor' || !is_int($value->value)) {
                throw new \DomainException(
                    'Mzdový ruleset pro rok ' . $year . ' nenese peněžní parametr '
                    . '`dpp.withholding.threshold`. Bez něj nelze určit hranici srážkové '
                    . 'daně z DPP — doplňte parametr v Mzdy → Legislativní pravidla.',
                );
            }
            $cache[$year] = $value->value;
        }

        return $cache[$year];
    }

    public static function availableYears(): array
    {
        return array_keys(self::TABLE);
    }

    /**
     * Sazby pojistného a zálohové daně ze závislé činnosti (§6 ZDP). Od 1. 1. 2024
     * beze změny pro 2024–2026, proto jedna sdílená sada — jakmile se některý rok
     * rozejde, rozkopíruj ji do dotčeného roku v {@see self::TABLE}.
     *
     *  - `employee_social` 7,1 % = 6,5 % důchodové (§7 z. 589/1992) + 0,6 % nemocenské,
     *    které zaměstnanci platí nově od 1. 1. 2024 (novela z. 349/2023 Sb.)
     *  - `employee_health` 4,5 % / `employer_health` 9,0 %, dohromady `health_total`
     *    13,5 % z vyměřovacího základu (§2 z. 592/1992)
     *  - `employer_social` 24,8 % (§7 odst. 1 písm. a) z. 589/1992)
     *  - `advance_tax` 15 % / `advance_tax_high` 23 % — progresivní zálohová daň
     *    (§38h odst. 2 ZDP). Vyšší sazbou se daní jen ČÁST základu nad měsíční hranicí
     *    `advance_tax_high_threshold`, ne celý základ. Hranice je měsíční (3× průměrná
     *    mzda), proto sedí v {@see self::TABLE} u konkrétního roku, ne tady.
     *
     * ── Proč tu `employer_social` zůstává, když je totéž v mzdovém rulesetu ─────
     * Konzumentem téhle sady je JEDINĚ {@see \MyInvoice\Service\Accounting\Payroll\PayrollCalculator},
     * tedy starší modul mzdové rekapitulace (§ 6 ZDP) mimo modul Mzdy. Ten počítá
     * jednu mzdu jednou sazbou a víc kategorií zaměstnavatele podle § 5a odst. 1
     * z. č. 589/1992 Sb. neumí ani zadat: nemá zaměstnance, nemá vztahy, nemá
     * evidenci zařazení. `employer_social` je proto sazba PÍSMENE a) — ostatní
     * zaměstnanci — a nic jiného.
     *
     * Přesměrovat ji na mzdový ruleset by nebyla konsolidace, ale změna výpočtu
     * v modulu, který účtuje a jehož výsledky jsou uzavřené proti reálnému deníku
     * účetní. Sazba 29,8 % (písm. b) ani 27,8 % (písm. c) se sem stejně dostat
     * nemůže — modul nemá čím kategorii doložit a hádat ji je horší než ji neznat
     * ({@see \MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory::Unverified}).
     * Kdo potřebuje kategorie, slevu § 7a nebo strop § 15a per zaměstnanec, musí
     * použít modul Mzdy; tahle sazba na to není a nikdy nebyla.
     *
     * Co se hlídat MUSÍ, je rozejití obou zdrojů u té jedné společné sazby —
     * to dělá `TaxConstantsPayrollRatesMatchRulesetTest`.
     */
    private const PAYROLL_2024_PLUS = [
        'employee_social' => 0.071,
        'employee_health' => 0.045,
        'employer_social' => 0.248,
        'employer_health' => 0.090,
        'health_total'    => 0.135,
        'advance_tax'     => 0.15,
        'advance_tax_high' => 0.23,
    ];

    private const TABLE = [
        2024 => [
            'year' => 2024,
            // Sazba se v roce 2024 neměnila — jediný segment od 1. 1.
            // (7 498 / 16 745 / 27 139 Kč/měs → 89 976 / 200 940 / 325 668 Kč ročně).
            'pausal_monthly' => [
                ['from' => '2024-01-01', 'band1' => 7498, 'band2' => 16745, 'band3' => 27139],
            ],
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'credit_disability_12' => 2520,
            'credit_disability_3'  => 5040,
            'credit_ztpp'          => 16140,
            'child_credits'   => [15204, 22320, 27840],
            'child_bonus_min' => 100,
            'minimum_wage' => 18900,
            'payroll' => self::PAYROLL_2024_PLUS,
            // §38h odst. 2: 3× průměrná mzda měsíčně = social_max_base / 16 (48× ročně).
            'advance_tax_high_threshold' => 131901, // 3 × 43 967

            'child_bonus_min_income' => 113400,
            'spouse_income_limit' => 68000,
            'spouse_child_max_age' => 3,
            'fixed_asset_limit' => 80000,
            // § 38g odst. 1 a 2 ZDP — hranice povinnosti podat přiznání. Od zdaňovacího
            // období 2023 zvýšené novelou 366/2022 Sb. z 15 000 / 6 000 Kč.
            'filing_duty_income_limit' => 50000,
            'filing_duty_other_income_limit' => 20000,
            // § 16a odst. 2 — sazba daně ze samostatného základu daně (zahraniční podíly
            // na zisku a obdobné příjmy § 8, které poplatník do samostatného základu zvolí).
            'separate_base_rate' => 0.15,
            'transition_receivables_max_years' => 9,
            'tax_loss_carry_years' => 5,
            // § 34 odst. 1 ZDP ve znění novely 299/2020 — ztrátu lze uplatnit i ZPĚTNĚ
            // ve 2 obdobích bezprostředně předcházejících, a to nejvýše v souhrnné výši
            // 30 000 000 Kč (limit se počítá na jednotlivou ztrátu, ne na rok uplatnění).
            'tax_loss_carryback_years' => 2,
            'tax_loss_carryback_limit' => 30000000,
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1582812, // 36× průměrné mzdy 2024 (43 967)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55,
            'health_assessment_pct' => 0.50,
            'social_min_base_main'      => 158292, // 30 % × 43 967 × 12
            'social_min_base_secondary' => 58044,
            'social_max_base'           => 2110416, // 48 × průměrná mzda
            'social_secondary_participation_threshold' => 105520,
            'health_min_base'           => 263802, // 50 % × 43 967 × 12
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            'mortgage_cap' => 150000,
            'mortgage_cap_pre2021' => 300000,
            'mortgage_pre2021_cutoff' => '2020-12-31',
            'pension_cap'  => 48000,
            // Do konce 2024 existoval jediný registrační limit DPH; oba klíče proto nesou tutéž hodnotu.
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2000000,
            'vat_rate_standard' => 21.0,
            // § 99a odst. 1 ZDPH — obrat za předcházející kalendářní rok, do kterého
            // si plátce může zvolit čtvrtletní zdaňovací období.
            'vat_quarterly_turnover_limit' => 15000000,
            'vat_rate_reduced'  => 12.0,
            'kh_item_threshold' => 10000,
            // § 4 z. č. 254/2004 Sb., o omezení plateb v hotovosti — jedna platba téhož dne
            // mezi týmiž osobami nad tento limit se MUSÍ provést bezhotovostně. Není to
            // účetní chyba, ale povinnost plátce → v pokladně jen varování, ne blokace.
            'cash_payment_limit' => 270000,
            'vat_coefficient_full_threshold_pct' => 95,
            // § 8 odst. 3 / § 10i ZDPH — celounijní práh pro zasílání zboží a digitální
            // služby B2C. Je v EUR (ne v Kč) a je společný pro všechny členské státy;
            // po jeho překročení se místo plnění přesouvá do státu spotřeby.
            'oss_threshold_eur' => 10000,
            'corporate_tax_rate' => 0.21,
            'withholding_rate'   => 0.15,
            // § 6 odst. 4 ZDP — dohoda o provedení práce do tohoto měsíčního limitu
            // u JEDNOHO zaměstnavatele a BEZ podepsaného prohlášení k dani tvoří
            // samostatný základ daně zdaněný srážkou. Od 1. 1. 2024 je to současně
            // hranice, do které se z DPP neodvádí sociální ani zdravotní pojištění.
            'dpp_withholding_limit' => 10000,
            // Do 31. 12. 2024 zněl § 6 odst. 4 ZDP „NEPŘESÁHNE … 10 000 Kč“, tedy
            // hranice VČETNĚ: odměna přesně 10 000 Kč se ještě daní srážkou. Od
            // 1. 1. 2025 je znění „nedosáhne rozhodné částky“ a hranice je ostrá.
            // Ten rozdíl je tady jako data, ne jako podmínka na rok v kalkulátoru —
            // historické měsíce se přepočtem nesmí překlopit do jiného režimu.
            'dpp_withholding_limit_inclusive' => true,
            // § 7 odst. 6 ZDP — autorský honorář do tohoto měsíčního limitu od jednoho
            // plátce se rovněž zdaňuje srážkou a do přiznání se neuvádí.
            'author_fee_withholding_limit' => 10000,
            'sickness_rate'             => 0.027,
            'sickness_min_monthly_base' => 8000,
            // § 6 odst. 1 písm. a) z. 187/2006 — ROZHODNÝ PŘÍJEM. Účast na nemocenském
            // (a tím i důchodovém) pojištění vzniká až při jeho DOSAŽENÍ; pod ním jde
            // o zaměstnání malého rozsahu (§ 7) a sociální pojistné se neodvádí vůbec.
            // Odvozeno jako 1/10 průměrné mzdy zaokrouhlená dolů na celých 500 Kč;
            // `sickness_min_monthly_base` výš je přesně jeho dvojnásobek.
            'sickness_participation_threshold' => 4000,
            'donation_cap_po_pct' => 0.30,
            'donation_cap_fo_pct' => 0.30,
            'donation_min_fo'     => 1000,
            'donation_min_fo_pct' => 0.02,
            'donation_min_po' => 2000,
            'disabled_employee_credit'        => 18000,
            'disabled_employee_credit_severe' => 60000,
            'advance_threshold_low'  => 30000,
            'advance_threshold_high' => 150000,
            'advance_semiannual_rate' => 0.40,
            'advance_quarterly_rate' => 0.25,
            'advance_rounding_step' => 100,
            'advance_semiannual_months' => [6, 12],
            'advance_quarterly_months' => [3, 6, 9, 12],
            'm1_depreciation_limit' => 2000000,
            'extraordinary_depreciation' => ['eligible_from' => '2024-01-01', 'eligible_to' => '2028-12-31', 'total_months' => 24, 'phase1_months' => 12, 'phase1_share' => 0.60],
            'depreciation_straight_rates' => [
                'basic' => [1 => [20.0,40.0,33.3], 2 => [11.0,22.25,20.0], 3 => [5.5,10.5,10.0], 4 => [2.15,5.15,5.0], 5 => [1.4,3.4,3.4], 6 => [1.02,2.02,2.0]],
                'p20' => [1 => [40.0,30.0,33.3], 2 => [31.0,17.25,20.0], 3 => [24.4,8.4,10.0]],
                'p15' => [1 => [35.0,32.5,33.3], 2 => [26.0,18.5,20.0], 3 => [19.0,9.0,10.0]],
                'p10' => [1 => [30.0,35.0,33.3], 2 => [21.0,19.75,20.0], 3 => [15.4,9.4,10.0]],
            ],
            'depreciation_accelerated_coefficients' => [1 => [3,4,3], 2 => [5,6,5], 3 => [10,11,10], 4 => [20,21,20], 5 => [30,31,30], 6 => [50,51,50]],
            'entity_category_thresholds' => [
                'micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10],
                'small' => ['assets_net' => 120000000, 'net_turnover' => 240000000, 'employees' => 50],
                'medium' => ['assets_net' => 600000000, 'net_turnover' => 1200000000, 'employees' => 250],
            ],
            'filing_deadlines' => ['dpfo_paper' => '04-01', 'dpfo_electronic' => '05-02', 'advisor' => '07-01', 'insurance_electronic' => '06-02', 'insurance_advisor' => '08-01', 'health_advance_day' => 8, 'tax_advance_day' => 15],
            'rounding_base_po' => 1000,
            'rounding_base_fo' => 100,
        ],
        2025 => [
            'year' => 2025,
            // Paušální daň — měsíční záloha dle pásma; segment platí od `from`,
            // dokud ho nevystřídá další. Roční částka (`pausal_annual`) je odvozená.
            'pausal_monthly' => [
                ['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139],
            ],
            // Stropy pásem dle příjmu × výdajového paušálu (§7a ZDP).
            // Klíč = sazba výdajového paušálu; hodnota = strop pro [band1, band2, band3].
            // Pozn.: SummaryAction::FLAT_TAX_BANDS drží zjednodušenou (činnost-neutrální)
            // variantu týchž stropů; sjednotit dashboard na tento zdroj je follow-up.
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            // Slevy a zvýhodnění
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'credit_disability_12' => 2520,
            'credit_disability_3'  => 5040,
            'credit_ztpp'          => 16140,
            'child_credits'   => [15204, 22320, 27840], // 1., 2., 3.+ dítě (3.+ se opakuje)
            'child_bonus_min' => 100,
            'minimum_wage' => 20800,
            'payroll' => self::PAYROLL_2024_PLUS,
            'advance_tax_high_threshold' => 139671, // 3 × 46 557 (= social_max_base / 16)

            'child_bonus_min_income' => 124800,
            'spouse_income_limit' => 68000,
            'spouse_child_max_age' => 3,
            'fixed_asset_limit' => 80000,
            // § 38g odst. 1 a 2 ZDP — hranice povinnosti podat přiznání. Od zdaňovacího
            // období 2023 zvýšené novelou 366/2022 Sb. z 15 000 / 6 000 Kč.
            'filing_duty_income_limit' => 50000,
            'filing_duty_other_income_limit' => 20000,
            // § 16a odst. 2 — sazba daně ze samostatného základu daně (zahraniční podíly
            // na zisku a obdobné příjmy § 8, které poplatník do samostatného základu zvolí).
            'separate_base_rate' => 0.15,
            'transition_receivables_max_years' => 9,
            'tax_loss_carry_years' => 5,
            // § 34 odst. 1 ZDP ve znění novely 299/2020 — ztrátu lze uplatnit i ZPĚTNĚ
            // ve 2 obdobích bezprostředně předcházejících, a to nejvýše v souhrnné výši
            // 30 000 000 Kč (limit se počítá na jednotlivou ztrátu, ne na rok uplatnění).
            'tax_loss_carryback_years' => 2,
            'tax_loss_carryback_limit' => 30000000,
            // Daň z příjmu
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1676052, // 36× průměrné mzdy 2025 (46 557)
            // Pojistné — sazby a vyměřovací základy (% ze zisku §7)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55, // sociální: 55 % zisku
            'health_assessment_pct' => 0.50, // zdravotní: 50 % zisku
            'social_min_base_main'      => 195540, // 35 % × 46 557 × 12
            'social_min_base_secondary' => 61476,  // min. roční zákl. vedlejší činnost
            'social_max_base'           => 2234736, // 48 × průměrná mzda (§15a z. 589/1992 Sb.)
            // Rozhodná částka (daňový základ / zisk) pro povinnou účast na důchodovém
            // pojištění u vedlejší SVČ — pod ní se sociální pojištění neplatí (ČSSZ).
            'social_secondary_participation_threshold' => 111736, // 2025
            'health_min_base'           => 279342, // 50 % × 46 557 × 12
            // Výdajové paušály — strop uplatnitelných výdajů dle sazby
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            // Odpočty — stropy
            // §15 odst. 3/4 ZDP: úroky z úvěru na bytovou potřebu. Obstarání od 1. 1. 2021
            // (zák. 386/2020 Sb.) → strop 150 000 Kč; obstarání do 31. 12. 2020 → 300 000 Kč.
            'mortgage_cap' => 150000,
            'mortgage_cap_pre2021' => 300000,
            'mortgage_pre2021_cutoff' => '2020-12-31',
            'pension_cap'  => 48000,
            // DPH — platí pro VŠECHNY plátce (nejen OSVČ)
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
            'vat_rate_standard' => 21.0,
            // § 99a odst. 1 ZDPH — obrat za předcházející kalendářní rok, do kterého
            // si plátce může zvolit čtvrtletní zdaňovací období.
            'vat_quarterly_turnover_limit' => 15000000,  // základní sazba § 47 ZDPH
            'vat_rate_reduced'  => 12.0,  // snížená sazba (od 2024 jednotná 12 %)
            'kh_item_threshold' => 10000, // limit KH: nad → A.4/B.2 jednotlivě, do → A.5/B.3 sumace
            // § 4 z. č. 254/2004 Sb., o omezení plateb v hotovosti — jedna platba téhož dne
            // mezi týmiž osobami nad tento limit se MUSÍ provést bezhotovostně. Není to
            // účetní chyba, ale povinnost plátce → v pokladně jen varování, ne blokace.
            'cash_payment_limit' => 270000,
            'vat_coefficient_full_threshold_pct' => 95,
            // § 8 odst. 3 / § 10i ZDPH — celounijní práh pro zasílání zboží a digitální
            // služby B2C. Je v EUR (ne v Kč) a je společný pro všechny členské státy;
            // po jeho překročení se místo plnění přesouvá do státu spotřeby.
            'oss_threshold_eur' => 10000,
            // ── Daň z příjmů PO (DPPO) + odvody OSVČ — Epic DP (issue #18) ──────
            'corporate_tax_rate' => 0.21,   // §21 ZDP sazba DPPO od 2024
            'withholding_rate'   => 0.15,
            // § 6 odst. 4 ZDP — dohoda o provedení práce do tohoto měsíčního limitu
            // u JEDNOHO zaměstnavatele a BEZ podepsaného prohlášení k dani tvoří
            // samostatný základ daně zdaněný srážkou. Od 1. 1. 2024 je to současně
            // hranice, do které se z DPP neodvádí sociální ani zdravotní pojištění.
            // Od 1. 1. 2025 NENÍ pevných 10 000: § 6/4 odkazuje na rozhodnou částku pro
            // účast na nemocenském pojištění, a ta je podle § 7a z. 187/2006 (novela
            // 163/2024 Sb.) 25 % průměrné mzdy zaokrouhlených DOLŮ na celých 500 Kč.
            // 2025: 46 557 × 0,25 = 11 639,25 → 11 500.
            // Mzdový ruleset pro rok 2025 neexistuje (registry začíná 2026), takže
            // tenhle rok si hodnotu drží sám; pro 2026 ji dodá ruleset.
            'dpp_withholding_limit' => 11500,
            // Zák. č. 470/2024 Sb. změnil od 1. 1. 2025 znění § 6 odst. 4 písm. a) ZDP
            // z „nepřesáhne" na „NEDOSÁHNE" — odměna přesně na rozhodné částce už
            // srážkou nejde. Viz komentář u roku 2024.
            'dpp_withholding_limit_inclusive' => false,
            // § 7 odst. 6 ZDP — autorský honorář do tohoto měsíčního limitu od jednoho
            // plátce se rovněž zdaňuje srážkou a do přiznání se neuvádí. Na rozhodnou
            // částku NAVÁZANÝ NENÍ — zůstává 10 000 Kč.
            'author_fee_withholding_limit' => 10000,   // §36 srážková daň z podílu na zisku
            // Nemocenské pojištění OSVČ (dobrovolné) — sazba a min. měsíční VZ
            'sickness_rate'             => 0.027, // 2,7 % z měsíčního VZ
            // min. měsíční VZ nemocenského = 2× rozhodný příjem (§5b/3 z. 589/1992 Sb.);
            // rozhodný příjem 2025 = 4 500 (1/10 prům. mzdy 46 557 zaokr. dolů na 500) → 9 000
            'sickness_min_monthly_base' => 9000,  // min. pojistné 9 000 × 2,7 % = 243 Kč/měs
            // § 6 odst. 1 písm. a) z. 187/2006 — ROZHODNÝ PŘÍJEM. Účast na nemocenském
            // (a tím i důchodovém) pojištění vzniká až při jeho DOSAŽENÍ; pod ním jde
            // o zaměstnání malého rozsahu (§ 7) a sociální pojistné se neodvádí vůbec.
            // Odvozeno jako 1/10 průměrné mzdy zaokrouhlená dolů na celých 500 Kč;
            // `sickness_min_monthly_base` výš je přesně jeho dvojnásobek.
            'sickness_participation_threshold' => 4500,
            // Dary — stropy odpočtu (§20/8 PO, §15/1 FO); 2020–2026 zvýšeno na 30 %
            'donation_cap_po_pct' => 0.30,  // §20/8 — dočasné zvýšení do 2026
            'donation_cap_fo_pct' => 0.30,  // §15/1 — dtto
            'donation_min_fo'     => 1000,  // §15/1 spodní limit (nebo 2 % ZD)
            'donation_min_fo_pct' => 0.02,
            'donation_min_po' => 2000,
            // Sleva na zaměstnance se zdravotním postižením (§35/1 ZDP)
            'disabled_employee_credit'        => 18000, // na zaměstnance se ZP (§35/1/a)
            'disabled_employee_credit_severe' => 60000, // s těžším ZP (§35/1/b)
            // §38a zálohy na daň: do 30 000 nic; 30 000–150 000 → 40 % pololetně;
            // nad 150 000 → 25 % čtvrtletně (poslední daňová povinnost)
            'advance_threshold_low'  => 30000,
            'advance_threshold_high' => 150000,
            'advance_semiannual_rate' => 0.40,
            'advance_quarterly_rate' => 0.25,
            'advance_rounding_step' => 100,
            'advance_semiannual_months' => [6, 12],
            'advance_quarterly_months' => [3, 6, 9, 12],
            'm1_depreciation_limit' => 2000000,
            'extraordinary_depreciation' => ['eligible_from' => '2024-01-01', 'eligible_to' => '2028-12-31', 'total_months' => 24, 'phase1_months' => 12, 'phase1_share' => 0.60],
            'depreciation_straight_rates' => [
                'basic' => [1 => [20.0,40.0,33.3], 2 => [11.0,22.25,20.0], 3 => [5.5,10.5,10.0], 4 => [2.15,5.15,5.0], 5 => [1.4,3.4,3.4], 6 => [1.02,2.02,2.0]],
                'p20' => [1 => [40.0,30.0,33.3], 2 => [31.0,17.25,20.0], 3 => [24.4,8.4,10.0]],
                'p15' => [1 => [35.0,32.5,33.3], 2 => [26.0,18.5,20.0], 3 => [19.0,9.0,10.0]],
                'p10' => [1 => [30.0,35.0,33.3], 2 => [21.0,19.75,20.0], 3 => [15.4,9.4,10.0]],
            ],
            'depreciation_accelerated_coefficients' => [1 => [3,4,3], 2 => [5,6,5], 3 => [10,11,10], 4 => [20,21,20], 5 => [30,31,30], 6 => [50,51,50]],
            'entity_category_thresholds' => [
                'micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10],
                'small' => ['assets_net' => 120000000, 'net_turnover' => 240000000, 'employees' => 50],
                'medium' => ['assets_net' => 600000000, 'net_turnover' => 1200000000, 'employees' => 250],
            ],
            'filing_deadlines' => ['dpfo_paper' => '04-01', 'dpfo_electronic' => '05-02', 'advisor' => '07-01', 'insurance_electronic' => '06-02', 'insurance_advisor' => '08-01', 'health_advance_day' => 8, 'tax_advance_day' => 15],
            // Zaokrouhlení základu daně: PO dolů na celé tisíce, FO dolů na sta Kč
            'rounding_base_po' => 1000,
            'rounding_base_fo' => 100,
        ],
        2026 => [
            'year' => 2026,
            // Od 1. 7. 2026 klesá záloha 1. pásma z 9 984 na 9 162 Kč (novela zákona
            // o minimálním pojistném OSVČ). Roční částka 1. pásma tak vychází
            // 6× 9 984 + 6× 9 162 = 114 876 Kč, ne 12× 9 984.
            'pausal_monthly' => [
                ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
                ['from' => '2026-07-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139],
            ],
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'credit_disability_12' => 2520,
            'credit_disability_3'  => 5040,
            'credit_ztpp'          => 16140,
            'child_credits'   => [15204, 22320, 27840],
            'child_bonus_min' => 100,
            'minimum_wage' => 22400,
            'payroll' => self::PAYROLL_2024_PLUS,
            'advance_tax_high_threshold' => 146901, // 3 × 48 967 (= social_max_base / 16)

            'child_bonus_min_income' => 134400,
            'spouse_income_limit' => 68000,
            'spouse_child_max_age' => 3,
            'fixed_asset_limit' => 80000,
            // § 38g odst. 1 a 2 ZDP — hranice povinnosti podat přiznání. Od zdaňovacího
            // období 2023 zvýšené novelou 366/2022 Sb. z 15 000 / 6 000 Kč.
            'filing_duty_income_limit' => 50000,
            'filing_duty_other_income_limit' => 20000,
            // § 16a odst. 2 — sazba daně ze samostatného základu daně (zahraniční podíly
            // na zisku a obdobné příjmy § 8, které poplatník do samostatného základu zvolí).
            'separate_base_rate' => 0.15,
            'transition_receivables_max_years' => 9,
            'tax_loss_carry_years' => 5,
            // § 34 odst. 1 ZDP ve znění novely 299/2020 — ztrátu lze uplatnit i ZPĚTNĚ
            // ve 2 obdobích bezprostředně předcházejících, a to nejvýše v souhrnné výši
            // 30 000 000 Kč (limit se počítá na jednotlivou ztrátu, ne na rok uplatnění).
            'tax_loss_carryback_years' => 2,
            'tax_loss_carryback_limit' => 30000000,
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1762812, // 36× průměrné mzdy 2026 (48 967)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55,
            'health_assessment_pct' => 0.50,
            'social_min_base_main'      => 235044, // 40 % × 48 967 × 12
            'social_min_base_secondary' => 64644,  // min. roční zákl. vedlejší činnost
            'social_max_base'           => 2350416, // 48 × průměrná mzda (§15a z. 589/1992 Sb.)
            'social_secondary_participation_threshold' => 117521, // 2026 (ČSSZ)
            'health_min_base'           => 293802, // 50 % × 48 967 × 12
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            // §15/3-4 ZDP: 150k od 2021, 300k pro bytové potřeby obstarané do 31. 12. 2020.
            'mortgage_cap' => 150000,
            'mortgage_cap_pre2021' => 300000,
            'mortgage_pre2021_cutoff' => '2020-12-31',
            'pension_cap'  => 48000,
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
            'vat_rate_standard' => 21.0,
            // § 99a odst. 1 ZDPH — obrat za předcházející kalendářní rok, do kterého
            // si plátce může zvolit čtvrtletní zdaňovací období.
            'vat_quarterly_turnover_limit' => 15000000,
            'vat_rate_reduced'  => 12.0,
            'kh_item_threshold' => 10000,
            // § 4 z. č. 254/2004 Sb., o omezení plateb v hotovosti — jedna platba téhož dne
            // mezi týmiž osobami nad tento limit se MUSÍ provést bezhotovostně. Není to
            // účetní chyba, ale povinnost plátce → v pokladně jen varování, ne blokace.
            'cash_payment_limit' => 270000,
            'vat_coefficient_full_threshold_pct' => 95,
            // § 8 odst. 3 / § 10i ZDPH — celounijní práh pro zasílání zboží a digitální
            // služby B2C. Je v EUR (ne v Kč) a je společný pro všechny členské státy;
            // po jeho překročení se místo plnění přesouvá do státu spotřeby.
            'oss_threshold_eur' => 10000,
            // ── Daň z příjmů PO (DPPO) + odvody OSVČ — Epic DP (issue #18) ──────
            'corporate_tax_rate' => 0.21,
            'withholding_rate'   => 0.15,
            // `dpp_withholding_limit` tu ZÁMĚRNĚ NENÍ. Pro rok 2026 už legislativní
            // hodnotu drží mzdový ruleset (`dpp.withholding.threshold`) a doplní ji
            // {@see self::withDerived()}. Druhá kopie tady vedla k tomu, že tatáž
            // odměna dostala podle použité cesty jiný daňový režim.
            // Hranice je od 1. 1. 2025 OSTRÁ — viz `dpp_withholding_limit_inclusive`.
            // § 7 odst. 6 ZDP — autorský honorář; na rozhodnou částku navázaný není.
            'author_fee_withholding_limit' => 10000,
            'sickness_rate'             => 0.027,
            // 2× rozhodný příjem: 2026 prům. mzda 48 967 → 1/10 = 4 896,7 → zaokr. dolů 4 500 → 9 000
            'sickness_min_monthly_base' => 9000,  // min. pojistné 9 000 × 2,7 % = 243 Kč/měs
            // § 6 odst. 1 písm. a) z. 187/2006 — ROZHODNÝ PŘÍJEM. Účast na nemocenském
            // (a tím i důchodovém) pojištění vzniká až při jeho DOSAŽENÍ; pod ním jde
            // o zaměstnání malého rozsahu (§ 7) a sociální pojistné se neodvádí vůbec.
            // Odvozeno jako 1/10 průměrné mzdy zaokrouhlená dolů na celých 500 Kč;
            // `sickness_min_monthly_base` výš je přesně jeho dvojnásobek.
            'sickness_participation_threshold' => 4500,
            'donation_cap_po_pct' => 0.30,
            'donation_cap_fo_pct' => 0.30,
            'donation_min_fo'     => 1000,
            'donation_min_fo_pct' => 0.02,
            'donation_min_po' => 2000,
            'disabled_employee_credit'        => 18000,
            'disabled_employee_credit_severe' => 60000,
            'advance_threshold_low'  => 30000,
            'advance_threshold_high' => 150000,
            'advance_semiannual_rate' => 0.40,
            'advance_quarterly_rate' => 0.25,
            'advance_rounding_step' => 100,
            'advance_semiannual_months' => [6, 12],
            'advance_quarterly_months' => [3, 6, 9, 12],
            'm1_depreciation_limit' => 2000000,
            'extraordinary_depreciation' => ['eligible_from' => '2024-01-01', 'eligible_to' => '2028-12-31', 'total_months' => 24, 'phase1_months' => 12, 'phase1_share' => 0.60],
            'depreciation_straight_rates' => [
                'basic' => [1 => [20.0,40.0,33.3], 2 => [11.0,22.25,20.0], 3 => [5.5,10.5,10.0], 4 => [2.15,5.15,5.0], 5 => [1.4,3.4,3.4], 6 => [1.02,2.02,2.0]],
                'p20' => [1 => [40.0,30.0,33.3], 2 => [31.0,17.25,20.0], 3 => [24.4,8.4,10.0]],
                'p15' => [1 => [35.0,32.5,33.3], 2 => [26.0,18.5,20.0], 3 => [19.0,9.0,10.0]],
                'p10' => [1 => [30.0,35.0,33.3], 2 => [21.0,19.75,20.0], 3 => [15.4,9.4,10.0]],
            ],
            'depreciation_accelerated_coefficients' => [1 => [3,4,3], 2 => [5,6,5], 3 => [10,11,10], 4 => [20,21,20], 5 => [30,31,30], 6 => [50,51,50]],
            'entity_category_thresholds' => [
                'micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10],
                'small' => ['assets_net' => 120000000, 'net_turnover' => 240000000, 'employees' => 50],
                'medium' => ['assets_net' => 600000000, 'net_turnover' => 1200000000, 'employees' => 250],
            ],
            'filing_deadlines' => ['dpfo_paper' => '04-01', 'dpfo_electronic' => '05-02', 'advisor' => '07-01', 'insurance_electronic' => '06-02', 'insurance_advisor' => '08-01', 'health_advance_day' => 8, 'tax_advance_day' => 15],
            'rounding_base_po' => 1000,
            'rounding_base_fo' => 100,
        ],
    ];
}
