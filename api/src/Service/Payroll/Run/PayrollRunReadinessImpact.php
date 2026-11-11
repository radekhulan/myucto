<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;

/**
 * JEDINÉ místo, které říká, CO nález pro účetní znamená.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Nálezy se dělily na „blocker" a „warning" podle toho, jak vážně zněly. Účetní
 * z toho nepoznala to jediné, co potřebuje vědět: jestli může pokračovat, nebo
 * ne. Chybějící identifikátor od ČSSZ vypadal stejně naléhavě jako chybějící
 * mzdová politika — přitom první se doplní kdykoli před podáním a s výpočtem
 * mzdy nemá nic společného, kdežto bez druhé se nedá spočítat vůbec nic.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * O zařazení rozhoduje JEDINÁ otázka: **dá se to opravit potom?**
 *
 * - {@see self::IMPACT_ANYTIME}  — dá, a nic to nestojí. Ukázat, nezastavit.
 * - {@see self::IMPACT_REVISION} — dá, ale až přes OPRAVNOU REVIZI, protože
 *   údaj vstupuje do zmrazeného snímku. Taky nezastavit, ale říct následek.
 * - {@see self::IMPACT_APPROVAL} — běh se zahájí i spočítá, ale SCHVÁLIT ho
 *   nepůjde, dokud se to neopraví. Kontrola nad zmrazeným snímkem z toho
 *   udělá blokující validaci. Patří sem červeně, ne žlutě: dřív se tu
 *   neschválená docházka ukazovala jako varování a po zahájení se tatáž věc
 *   objevila jako blokace schválení.
 * - {@see self::IMPACT_BLOCKING} — nedá; výpočet nemá z čeho vyjít.
 *
 * Když si u nálezu nejsme jistí, patří do `anytime`. Falešná závora je horší
 * než falešné varování: koho aplikace pustí dál, ten si problém opraví; koho
 * zastaví, ten nemá co dělat.
 *
 * ── Druhá osa: kdy se to řeší ───────────────────────────────────────────────
 * {@see self::SCOPE_SETUP} je nastavení firmy — dořeší se při rozjezdu a pak
 * je pokoj. {@see self::SCOPE_MONTHLY} je práce, která se opakuje každý měsíc.
 * Účetní tak pozná, jestli stojí nad jednorázovou konfigurací, nebo nad běžným
 * měsícem, a nebere „už zase to samé" jako chybu aplikace.
 *
 * POZOR na dvojí okamžik: tady se rozhoduje o ZAHÁJENÍ BĚHU. Zmrazení JMHZ je
 * jiná brána — tam pole, které v hlášení opravdu být musí, blokovat smí,
 * protože neúplné hlášení podat nejde. Blokace z podání se sem nepropisuje.
 */
final class PayrollRunReadinessImpact
{
    /** Opraví se kdykoli a nic to nestojí. */
    public const IMPACT_ANYTIME = 'anytime';

    /** Opraví se, ale po zamknutí vstupů to stojí opravnou revizi. */
    public const IMPACT_REVISION = 'revision';

    /** Spočítat jde, schválit ne — v běhu z toho je blokující validace. */
    public const IMPACT_APPROVAL = 'approval';

    /** Bez tohohle se nedá počítat. */
    public const IMPACT_BLOCKING = 'blocking';

    /** Nastavení firmy — jednorázové, patří do rozjezdu. */
    public const SCOPE_SETUP = 'setup';

    /** Práce, která se opakuje každý měsíc. */
    public const SCOPE_MONTHLY = 'monthly';

    /**
     * Zařazení podle kódu nálezu.
     *
     * @var array<string,array{0:string,1:string}> kód => [dopad, okamžik]
     */
    private const CLASSIFICATION = [
        // ── Skupina 3: bez tohohle výpočet nemá z čeho vyjít ─────────────────
        'employer_policy_missing' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        /*
         * Období PŘED prvním mzdovým obdobím firmy. Běh za něj nejde ani
         * založit ({@see \MyInvoice\Service\Payroll\Run\PayrollRunCommandService::assertModuleAvailable()}),
         * takže je to skutečná závora, ne informace. Zároveň je to JEDINÝ nález,
         * který se za takové období vypisuje: měsíce zpracované v předchozím
         * programu patří do počátečních stavů, a vypsat u nich dvě stě chybějících
         * podkladů by účetní poslalo opravovat data, která se nikdy počítat nebudou.
         */
        'period_before_module_start' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        // Rok bez legislativní sady: běh nejde ani založit (PayrollRunSnapshotBuilder::assertRulesetCoverage).
        'ruleset_year_unavailable' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        // Snímek běhu bez nich nevznikne
        // ({@see PayrollRunSnapshotFixableException}), běh tedy nejde zahájit.
        'employer_policy_invalid' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        'employer_identity_incomplete' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        'employer_accounts_incomplete' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        'employer_accounts_invalid' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        'employment_dimension_split_invalid' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],
        'employment_dimension_share_invalid' => [self::IMPACT_BLOCKING, self::SCOPE_SETUP],

        // ── Spočítat jde, schválit ne: v běhu jsou to blokující validace ─────
        'draft_inputs_present' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],
        'time_month_not_approved' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],
        // V běhu je to varování bez povinné výjimky: schválit jde, ale
        // doplnění po zamknutí vstupů stojí opravnou revizi.
        'time_month_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Vztah bez schválené složky výpočet neprojde (zákonný výpočet skončí
        // ruční kontrolou „chybí schválená mzdová složka"), i když snímek sám
        // to hlásí jen jako varování. Cesta ven: zadat složku, případně 0 Kč.
        'employment_without_inputs' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],
        // Vstupy vztahu, který do běhu nevstoupí (nepotvrzený nástup, podmínky
        // účinné až po období). Bez opravy se tiše nevyplatí.
        'inputs_on_unstarted_employment' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],
        // Výpočet daně osobu bez ověřeného stavu prohlášení zastaví (ruční
        // kontrola), takže běh se spočítá jen zčásti a schválit nepůjde.
        'tax_declaration_evidence_missing' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],
        // Chybějící daňová rezidence, zdravotní pojištění a jiné zákonné údaje:
        // výpočet osobu zastaví ruční kontrolou a běh nepůjde schválit.
        'statutory_calculation_manual_review' => [self::IMPACT_APPROVAL, self::SCOPE_MONTHLY],

        // ── Skupina 2: opraví se, ale po zamknutí přes opravnou revizi ───────
        // Rozhodnutí nepřítomnosti mění snímek vstupů (schválené nepřítomnosti).
        'absence_pending_decision' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // § 75 ZP — roční limit DPP 300 h včetně převzatých hodin. Výpočet běží,
        // ale smluvní vztah se musí upravit.
        'dpp_annual_hours_exceeded' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Ruční podklad DPP bez schválených hodin; roční limit § 75 ZP nelze ověřit.
        'dpp_annual_hours_manual_review_required' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // § 76 odst. 2 ZP — průměr DPČ nad polovinu stanovené týdenní doby.
        'dpc_weekly_average_exceeded' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'dpc_weekly_average_manual_review_required' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'recurring_component_without_input' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'payout_rule_missing' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'missing_effective_employment_term' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'part_time_discount_intent_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'part_time_discount_transitional_window' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Věková hranice důvodu slevy (§ 7a odst. 1 a, d, g) vstupuje do zmrazeného
        // snímku jako odvozený výrok, takže oprava po zamknutí vstupů je revize.
        'part_time_discount_age_condition' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Poučení zaměstnance (§ 23d odst. 2) se zapisuje u záměru, do zmrazeného
        // snímku nevstupuje, takže se doplní kdykoli a nic to nestojí.
        'part_time_discount_employee_not_informed' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // § 75 ZP — DPP dosáhla prahu upozornění
        // ({@see \MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours::WARNING_PERCENT}). Nic se
        // neopravuje, jde o výhled, aby se smluvní vztah stihl upravit předem.
        'dpp_annual_hours_approaching' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // Stav prohlášení vstupuje do zmrazeného snímku — podpis po zamknutí
        // znamená opravnou revizi. Je to ale rozhodnutý stav, ne chyba.
        'tax_declaration_not_signed_summary' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'tax_residence_missing_unsigned_summary' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'health_coverage_missing_summary' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'social_jurisdiction_missing_summary' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        // Schválený průměr vstupuje do zmrazeného snímku vstupů běhu. Schválený
        // po výpočtu revize ho hlášení nevidí a oprava stojí opravnou revizi.
        'jmhz_average_hourly_earning_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // § 38ch odst. 5 ZDP: březen je poslední běh, který přeplatek ze
        // zúčtování vyplatí. Zúčtování provedené po schválení vyplatí jen
        // opravná revize běhu.
        'annual_settlement_pending_last_payout' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // § 111 odst. 2 ZP — sjednaná měsíční mzda pod minimální mzdou období.
        // Mzda vstupuje do zmrazeného snímku, oprava po zamknutí je revize.
        'monthly_wage_below_minimum' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Podmínky vztahu (pracoviště 10229 až 10231, tristavy, APZ, dočasné
        // přidělení, kategorizace rizika, druh činnosti a upřesnění vztahu
        // pro výběr formuláře) vstupují do zmrazeného snímku běhu.
        // Doplněné po výpočtu je příprava hlášení nevidí a oprava stojí
        // opravnou revizi ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder::termReadinessIssues()}).
        'jmhz_workplace_codebooks_unverified' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_verified_boolean_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_apz_instrument_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_temporary_assignment_user_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_risk_categorization_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario_activity_code_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario_activity_code_invalid' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario_relationship_detail_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario_relationship_detail_invalid' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario_relationship_detail_not_applicable' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Variabilní symbol registrace u ČSSZ (10221) se do snímku běhu zmrazí
        // s účtárnou vztahu ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder::officeRegistrations()}).
        // Opravený po schválení ho hlášení nevidí, dokud běh neprojde opravou.
        'jmhz_office_variable_symbol_invalid' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'jmhz_office_variable_symbol_missing' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'jmhz_office_variable_symbol_mismatch' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'social_security_variable_symbol_missing' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        'jmhz_employment_without_office' => [self::IMPACT_REVISION, self::SCOPE_SETUP],
        // Odpověď, zda tytéž děti vyživuje i jiná osoba (10453), a její jméno
        // jsou součástí nároku na dítě v zákonné evidenci zmrazené ve snímku.
        'jmhz_scenario1_child_credit_caregiver_unknown' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario1_child_credit_caregiver_inconsistent' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'jmhz_scenario1_child_credit_caregiver_identity_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        // Jméno a datum narození dítěte se čtou z evidence vyživovaných osob
        // až při přípravě hlášení, ve snímku běhu nejsou.
        'jmhz_scenario1_child_identity_incomplete' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // Roční údaje zaměstnavatele pro JMHZ (prosinec, od 2027 každý měsíc)
        // nejsou ve snímku běhu: doplní se kdykoli před přípravou hlášení.
        'jmhz_employer_annual_collective_agreement_missing' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'jmhz_employer_annual_ownership_missing' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'jmhz_employer_annual_ozp_summary_missing' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // Stav žádosti o roční zúčtování (10319, hlášení za leden a únor) se
        // čte až při přípravě hlášení a ve snímku běhu není: rozhodne se
        // kdykoli před přípravou, mzdový běh na něj nečeká.
        'jmhz_annual_request_status_unresolved' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],

        // Měsíc, který ani nezačal: běh se počítá celý z plánu a každá pozdější
        // změna podkladů po schválení je opravná revize.
        'period_not_started' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],

        // ── Skupina 1: doplní se kdykoli, na výpočet to nemá vliv ────────────
        // Měsíc běží: výplata před koncem měsíce je legitimní, jen informace
        // o tom, co se ještě může změnit a odkdy půjde měsíční hlášení.
        'period_not_finished' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'run_without_employments' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'readiness_check_failed' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // Hodnoty roku, které ještě nevyšly ve Sbírce: informace, přijdou aktualizací.
        'ruleset_year_values_pending' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'person_data_gap' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        // Aktivní vztah po sjednaném konci: běh ho vezme jako trvající, ale
        // ukončit se dá kdykoli a hromadně.
        'employments_past_agreed_end' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        // Žádost o roční zúčtování čeká na provedení; v lednu a únoru se
        // přeplatek ještě vyplatí v některém z dalších běhů.
        'annual_settlement_pending' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'institution_account_unverified' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'employment_without_office' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'employment_social_registration_missing' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'employment_health_registration_missing' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'office_registration_history_missing' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        // Zařazení mzdových složek do JMHZ je konfigurace firmy, ne měsíční
        // práce — a hlášení se podává až po běhu, takže běh nezdrží.
        'component_jmhz_mapping_missing' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'component_jmhz_manual_review' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        'component_jmhz_treatment_invalid' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
        // Zařazení odměny z dřívějšího výchozího pravidla k ověření: platí dál,
        // nic neblokuje, jen se má potvrdit pravidelnost (10330/10331).
        'component_jmhz_bonus_regularity_unverified' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
    ];

    /** Nálezy identity pro ČSSZ mají společné zařazení — všechny do skupiny 1. */
    private const IDENTITY_PREFIX = 'jmhz_identity_';

    /**
     * Druhy nápravy ({@see JmhzBlockerCatalog}), jejichž údaj hlášení čte ze
     * ZMRAZENÉHO snímku vstupů schválené revize: podmínky vztahu, zákonná
     * evidence osoby včetně nároků na děti, průměrný výdělek, nepřítomnosti
     * a docházka. Platí pro nálezy hlášení, které nemají vlastní řádek
     * v {@see self::CLASSIFICATION}; řádek má vždy přednost.
     *
     * Údaje čtené až při přípravě hlášení (identifikátory ČSSZ, zařazení
     * složek, roční údaje zaměstnavatele, žádost o roční zúčtování, běžná
     * evidence JMHZ) sem nepatří: opravnou revizi nestojí.
     */
    private const FROZEN_SOURCE_KINDS = [
        'employment_terms',
        'workplace',
        'statutory_evidence',
        'dependants',
        'averages',
        'absences',
        'time',
    ];

    /**
     * Stojí oprava nálezu MĚSÍČNÍHO HLÁŠENÍ opravnou revizi schváleného běhu?
     *
     * Hlášení se sestavuje ze schválené revize. Údaj, který do jejího snímku
     * vstoupil, oprava v nastavení ani na kartě nezmění: příprava hlášení dál
     * čte starou hodnotu, dokud běh neprojde opravou, přepočtem a novým
     * schválením. Blokace to musí říct, jinak účetní údaj opraví a hlášení
     * se zastaví na tomtéž.
     *
     * Jedno místo pravdy pro přípravu hlášení i kontrolu před během: zařazení
     * kódu ({@see self::CLASSIFICATION}) platí pro obojí stejně.
     *
     * @param list<string> $attributeIds dotčené atributy (nález s rozpadem podle atributu)
     */
    public static function jmhzBlockerNeedsRevision(string $code, string $entityType = '', array $attributeIds = []): bool
    {
        if (isset(self::CLASSIFICATION[$code])) {
            return self::CLASSIFICATION[$code][0] === self::IMPACT_REVISION;
        }
        // Kód, který katalog nezná, nikdo nezařadil: o opravné revizi se u něj
        // nic netvrdí (stejně jako neznámý nález nikdy neblokuje běh).
        if (str_starts_with($code, self::IDENTITY_PREFIX) || !JmhzBlockerCatalog::knows($code)) {
            return false;
        }

        return in_array(
            JmhzBlockerCatalog::remediationFor($code, $entityType, $attributeIds)['kind'],
            self::FROZEN_SOURCE_KINDS,
            true,
        );
    }

    /**
     * Zařazení nálezu z kontroly snímku. Validace, kterou běh nad zmrazenými
     * vstupy hlásí jako blokující, nesmí být v předběžné kontrole slabší než
     * „neschválí se" — jinak účetní uvidí žlutou a po zahájení červenou.
     *
     * @return array{impact:string,scope:string,severity:string}
     */
    public static function describeValidation(string $code, string $validationSeverity, bool $requiresOverride = false): array
    {
        $classification = self::describe($code);
        if ($requiresOverride
            && $validationSeverity === 'warning'
            && in_array($classification['impact'], [self::IMPACT_ANYTIME, self::IMPACT_REVISION], true)
        ) {
            return [
                'impact' => self::IMPACT_APPROVAL,
                'scope' => $classification['scope'],
                'severity' => 'warning',
            ];
        }
        if ($validationSeverity === 'blocker'
            && in_array($classification['impact'], [self::IMPACT_ANYTIME, self::IMPACT_REVISION], true)
        ) {
            return [
                'impact' => self::IMPACT_APPROVAL,
                'scope' => $classification['scope'],
                'severity' => 'blocker',
            ];
        }

        return $classification;
    }

    /** @return array{impact:string,scope:string,severity:string} */
    public static function describe(string $code): array
    {
        [$impact, $scope] = self::CLASSIFICATION[$code]
            ?? (str_starts_with($code, self::IDENTITY_PREFIX)
                ? [self::IMPACT_ANYTIME, self::SCOPE_SETUP]
                // Neznámý kód je vždycky jen informace. Zastavit práci kvůli
                // nálezu, který nikdo nezařadil, by byl přesně ten druh závory,
                // kterou tahle třída odstraňuje.
                : [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY]);

        return [
            'impact' => $impact,
            'scope' => $scope,
            'severity' => match ($impact) {
                self::IMPACT_BLOCKING, self::IMPACT_APPROVAL => 'blocker',
                self::IMPACT_REVISION => 'warning',
                default => 'info',
            },
        ];
    }
}
