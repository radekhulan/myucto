<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

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

        // ── Skupina 2: opraví se, ale po zamknutí přes opravnou revizi ───────
        // Všechno, co vstupuje do zmrazeného snímku vstupů.
        'draft_inputs_present' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'time_month_not_approved' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'time_month_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'employment_without_inputs' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'missing_effective_employment_term' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'part_time_discount_intent_missing' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],
        'part_time_discount_transitional_window' => [self::IMPACT_REVISION, self::SCOPE_MONTHLY],

        // ── Skupina 1: doplní se kdykoli, na výpočet to nemá vliv ────────────
        'run_without_employments' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'readiness_check_failed' => [self::IMPACT_ANYTIME, self::SCOPE_MONTHLY],
        'person_data_gap' => [self::IMPACT_ANYTIME, self::SCOPE_SETUP],
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
    ];

    /** Nálezy identity pro ČSSZ mají společné zařazení — všechny do skupiny 1. */
    private const IDENTITY_PREFIX = 'jmhz_identity_';

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
                self::IMPACT_BLOCKING => 'blocker',
                self::IMPACT_REVISION => 'warning',
                default => 'info',
            },
        ];
    }
}
