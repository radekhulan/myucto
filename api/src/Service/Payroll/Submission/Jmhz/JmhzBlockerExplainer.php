<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzBlockerExplainer
{
    /** @var array<string,string> */
    private const REASONS = [
        'effective_term_missing' => 'Chybí účinné podmínky pracovního vztahu pro vykazovaný měsíc.',
        'component_jmhz_mapping_missing' => 'Mzdové složky nemají určené zařazení do JMHZ.',
        'component_jmhz_manual_review' => 'Zařazení mzdových složek do JMHZ vyžaduje kontrolu.',
        'component_jmhz_treatment_invalid' => 'Mzdové složky mají neplatné nastavení pro JMHZ.',
        'jmhz_average_hourly_earning_missing' => 'Chybí ověřený průměrný hodinový výdělek.',
        'jmhz_identity_incomplete' => 'Chybí povinné identifikační údaje zaměstnance.',
        'jmhz_identity_oic_missing' => 'Chybí ověřený identifikátor pojištěnce pro ČSSZ.',
        'jmhz_identity_id_ppv_missing' => 'Chybí identifikátor pracovního vztahu pro ČSSZ.',
        'jmhz_scenario_activity_code_missing' => 'Chybí kód druhu činnosti pro JMHZ.',
        'jmhz_scenario_relationship_detail_missing' => 'Chybí upřesnění druhu pracovního vztahu pro JMHZ.',
        'jmhz_verified_boolean_missing' => 'Chybí potvrzení povinných voleb ano/ne.',
        'jmhz_work_month_not_approved' => 'Pracovní doba za vykazovaný měsíc není schválená.',
        'jmhz_workplace_codebooks_unverified' => 'Chybí ověřené číselníkové údaje pracoviště.',
        'jmhz_preparation_not_ready' => 'Zdroje měsíčního hlášení nejsou úplné.',
        'jmhz_ordinary_evidence_missing' => 'Chybí potvrzení běžných právních skutečností.',
        'jmhz_attribute_10116_unresolved' => 'Není potvrzeno, zda se ze mzdy vykazují srážky.',
        'jmhz_attribute_10546_unresolved' => 'Není potvrzeno uplatnění sezónní slevy na pojistném.',
        'jmhz_interaction_in13_unresolved' => 'Není potvrzen výskyt zvláštní právní skutečnosti zaměstnání.',
        'jmhz_interaction_in28_unresolved' => 'Není potvrzeno uplatnění podpory zaměstnávání osob se zdravotním postižením.',
        'jmhz_interaction_in30_unresolved' => 'Není potvrzeno, zda zaměstnanec vykonával práci v hlubinném hornictví.',
        'jmhz_primary_employment_unresolved' => 'Není určen hlavní pracovněprávní vztah.',
        'jmhz_taxpayer_declaration_unresolved' => 'Není doloženo prohlášení poplatníka.',
        'jmhz_scenario1_advance_tax_missing' => 'Chybí výsledek zálohy na daň.',
        'jmhz_scenario1_advance_tax_incomplete' => 'Výsledek zálohy na daň není úplný.',
        'jmhz_scenario1_tax_credit_breakdown_unavailable' => 'Chybí rozpad uplatněných slev na dani.',
        'jmhz_scenario1_deductions_unsupported' => 'Srážky ze mzdy nejsou pro tento profil JMHZ připravené.',
        'jmhz_scenario1_withholding_tax_unsupported' => 'Srážková daň není pro tento profil JMHZ připravená.',
        'jmhz_scenario1_multiple_employments_unsupported' => 'Více pracovních vztahů není pro tento profil JMHZ připraveno.',
        'jmhz_scenario1_annual_fields_unsupported' => 'Chybí povinné roční údaje JMHZ.',
        'jmhz_scenario1_pvpoj_unavailable' => 'Chybí pojistná část měsíčního hlášení.',
        'jmhz_scenario1_pvpoj_source_mismatch' => 'Pojistná část neodpovídá vybrané registraci u OSSZ.',
        'jmhz_scenario1_earnings_vector_incomplete' => 'Mzdové složky nejsou úplně zařazené do polí měsíčního hlášení.',
        'jmhz_eldp_evidence_missing' => 'Chybí připravená evidence důchodového pojištění.',
        'jmhz_work_summary_v2_missing' => 'Chybí schválený pracovní souhrn měsíce.',
        'jmhz_employer_part_time_discount_unverified' => 'Nárok na slevu za kratší úvazek není doložený.',
        'jmhz_employer_part_time_discount_outcome_missing' => 'Chybí posouzení nároku na slevu za kratší úvazek.',
        'jmhz_employer_part_time_discount_reason_missing' => 'Chybí důvod uplatnění slevy za kratší úvazek.',
        'jmhz_employer_part_time_discount_working_time_missing' => 'Chybí sjednaná kratší týdenní pracovní doba.',
        'jmhz_employer_part_time_discount_working_time_unresolved' => 'Sjednanou kratší týdenní pracovní dobu nelze vykázat.',
        'jmhz_employer_part_time_discount_activity_unsupported' => 'Sleva za kratší úvazek neodpovídá druhu pracovního vztahu.',
    ];

    /** @var array<string,string> */
    private const ACTIONS = [
        'component_jmhz_mapping_missing' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení.',
        'component_jmhz_manual_review' => 'Otevřete Mzdy → Mzdové složky a potvrďte zařazení.',
        'component_jmhz_treatment_invalid' => 'Otevřete Mzdy → Mzdové složky a opravte nastavení.',
        'jmhz_average_hourly_earning_missing' => 'Otevřete Mzdy → Absence a průměry a doplňte výdělek.',
        'jmhz_work_month_not_approved' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_work_summary_v2_missing' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_scenario1_earnings_vector_incomplete' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení.',
        'jmhz_eldp_evidence_missing' => 'Otevřete Mzdová podání → Evidenční list DP a připravte evidenci.',
        'jmhz_ordinary_evidence_missing' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_attribute_10116_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_attribute_10546_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in13_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in28_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in30_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_scenario1_pvpoj_unavailable' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte výpočet pojistného.',
        'jmhz_scenario1_pvpoj_source_mismatch' => 'Obnovte přehled a zkontrolujte mzdovou účtárnu pracovních vztahů.',
        'jmhz_preparation_not_ready' => 'Otevřete test JMHZ a postupně doplňte zvýrazněné skupiny údajů.',
    ];

    /** @param list<JmhzScenario1Blocker> $blockers */
    public static function describe(array $blockers): string
    {
        if ($blockers === []) {
            return 'Důvod blokace nebyl uveden. Obnovte test JMHZ a zkuste jej znovu.';
        }

        /** @var array<string,array{blocker:JmhzScenario1Blocker,count:int}> $groups */
        $groups = [];
        foreach ($blockers as $blocker) {
            if (isset($groups[$blocker->code])) {
                ++$groups[$blocker->code]['count'];
                continue;
            }
            $groups[$blocker->code] = ['blocker' => $blocker, 'count' => 1];
        }

        $descriptions = [];
        foreach ($groups as $group) {
            $blocker = $group['blocker'];
            $reason = self::REASONS[$blocker->code]
                ?? 'Chybí zákonný údaj potřebný pro měsíční hlášení.';
            $action = self::ACTIONS[$blocker->code]
                ?? self::fallbackAction($blocker->entityType);
            $descriptions[] = $reason . ' '
                . self::affected($blocker->entityType, $group['count'])
                . ' ' . $action;
        }

        return implode(' ', $descriptions);
    }

    private static function fallbackAction(string $entityType): string
    {
        return match ($entityType) {
            'component' => 'Otevřete Mzdy → Mzdové složky a zkontrolujte zvýrazněná pole.',
            'employment', 'person', 'employee' => 'Otevřete Mzdy → Zaměstnanci a doplňte zvýrazněná pole.',
            'office' => 'Otevřete Nastavení mezd → Mzdové účtárny a doplňte zvýrazněná pole.',
            'run', 'revision', 'preparation' => 'Otevřete Mzdy → Mzdové běhy a dokončete zvýrazněný krok.',
            default => 'Otevřete test JMHZ a pokračujte od zvýrazněné skupiny údajů.',
        };
    }

    private static function affected(string $entityType, int $count): string
    {
        $forms = match ($entityType) {
            'component' => ['mzdová složka', 'mzdové složky', 'mzdových složek'],
            'employment' => ['pracovní vztah', 'pracovní vztahy', 'pracovních vztahů'],
            'person', 'employee' => ['zaměstnanec', 'zaměstnanci', 'zaměstnanců'],
            'office' => ['mzdová účtárna', 'mzdové účtárny', 'mzdových účtáren'],
            'run' => ['mzdový běh', 'mzdové běhy', 'mzdových běhů'],
            default => ['záznam', 'záznamy', 'záznamů'],
        };
        $form = $count === 1 ? $forms[0] : ($count < 5 ? $forms[1] : $forms[2]);

        return "Dotčeno: {$count} {$form}.";
    }
}
