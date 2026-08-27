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
        'jmhz_scenario1_scope_unsupported' => 'Příprava obsahuje smíšené nebo zvláštní scénáře JMHZ, které nelze vydat za běžné hlášení.',
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
        'jmhz_annual_evidence_source_missing' => 'Chybí zmrazená roční evidence zaměstnance pro předchozí zdaňovací období.',
        'jmhz_annual_request_source_missing' => 'Není doloženo, zda zaměstnanec požádal o roční zúčtování.',
        'jmhz_annual_request_status_unresolved' => 'Stav žádosti o roční zúčtování není rozhodnutý.',
        'jmhz_annual_settlement_performance_source_missing' => 'Není doloženo, zda bylo roční zúčtování provedeno.',
        'jmhz_annual_settlement_source_inconsistent' => 'Žádost a výsledek ročního zúčtování si odporují.',
        'jmhz_annual_settlement_request_source_inconsistent' => 'Provedené roční zúčtování nemá souhlasnou uzamčenou žádost a evidenci ročních nároků.',
        'jmhz_annual_settlement_result_incomplete' => 'Výsledek ročního zúčtování nelze převést do celých korun JMHZ.',
        'jmhz_annual_settlement_child_details_unsupported' => 'Roční zúčtování obsahuje zvýhodnění na děti, ale snapshot zatím nenese povinné identifikační údaje JMHZ.',
        'jmhz_december_collective_agreement_source_missing' => 'Chybí roční údaj o typu kolektivní smlouvy pro prosincové hlášení.',
        'jmhz_december_ownership_form_source_missing' => 'Chybí roční údaj o formě vlastnictví a kontroly pro prosincové hlášení.',
        'jmhz_december_ozp_annual_source_missing' => 'Chybí roční evidence zaměstnávání osob se zdravotním postižením pro prosincové hlášení.',
        'jmhz_scenario1_pvpoj_unavailable' => 'Chybí pojistná část měsíčního hlášení.',
        'jmhz_scenario1_pvpoj_source_mismatch' => 'Pojistná část neodpovídá vybrané registraci u OSSZ.',
        'jmhz_scenario1_earnings_vector_incomplete' => 'Mzdové složky nejsou úplně zařazené do polí měsíčního hlášení.',
        'jmhz_eldp_evidence_missing' => 'Chybí připravená evidence důchodového pojištění.',
        'jmhz_eldp_absences_unsupported' => 'Měsíc obsahuje absenci, proto nelze běžnou evidenci důchodového pojištění bezpečně odvodit.',
        'jmhz_eldp_work_summary_missing' => 'Chybí schválený pracovní souhrn potřebný pro evidenci důchodového pojištění.',
        'jmhz_eldp_work_summary_mismatch' => 'Pracovní souhrn obsahuje výjimku, kterou nelze do běžné evidence důchodového pojištění bezpečně převzít.',
        'jmhz_eldp_relationship_kind_unsupported' => 'Druh pracovního vztahu není podporován automatickou evidencí důchodového pojištění.',
        'jmhz_eldp_ordinary_activity_unsupported' => 'Druh činnosti není podporován automatickou evidencí důchodového pojištění.',
        'jmhz_eldp_relation_activity_mismatch' => 'Druh činnosti neodpovídá druhu pracovního vztahu.',
        'jmhz_eldp_scenario_unsupported' => 'Pracovní vztah nepatří do podporovaného běžného scénáře evidence důchodového pojištění.',
        'jmhz_eldp_social_relationship_unsupported' => 'Pracovní vztah nemá běžnou účast na sociálním pojištění.',
        'jmhz_eldp_capped_base_unsupported' => 'Vyměřovací základ byl krácen ročním maximem a vyžaduje individuální kontrolu.',
        'jmhz_eldp_assessment_base_not_whole_czk' => 'Vyměřovací základ nelze bezpečně převést na celé koruny pro evidenci důchodového pojištění.',
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
        'jmhz_scenario1_scope_unsupported' => 'Zkontrolujte druhy činnosti v přípravě a nepodporované vztahy zpracujte individuálně.',
        'component_jmhz_mapping_missing' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení.',
        'component_jmhz_manual_review' => 'Otevřete Mzdy → Mzdové složky a potvrďte zařazení.',
        'component_jmhz_treatment_invalid' => 'Otevřete Mzdy → Mzdové složky a opravte nastavení.',
        'jmhz_average_hourly_earning_missing' => 'Otevřete Mzdy → Absence a průměry a doplňte výdělek.',
        'jmhz_work_month_not_approved' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_work_summary_v2_missing' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_scenario1_earnings_vector_incomplete' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení.',
        'jmhz_eldp_evidence_missing' => 'Obnovte test JMHZ; pokud blokace zůstane, postupujte podle konkrétního upozornění u pracovního vztahu.',
        'jmhz_eldp_absences_unsupported' => 'Zkontrolujte Mzdy → Pracovní doba; nestandardní měsíc ponechte blokovaný a zpracujte jej individuálně podle podkladů ČSSZ.',
        'jmhz_eldp_work_summary_missing' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_eldp_work_summary_mismatch' => 'Otevřete Mzdy → Pracovní doba a zkontrolujte absence a neodpracované doby.',
        'jmhz_eldp_relationship_kind_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte druh pracovního vztahu.',
        'jmhz_eldp_ordinary_activity_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte druh činnosti pro JMHZ.',
        'jmhz_eldp_relation_activity_mismatch' => 'Otevřete Mzdy → Zaměstnanci a slaďte druh vztahu s druhem činnosti pro JMHZ.',
        'jmhz_eldp_scenario_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte nastavení pracovního vztahu pro JMHZ.',
        'jmhz_eldp_social_relationship_unsupported' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte účast na sociálním pojištění.',
        'jmhz_eldp_capped_base_unsupported' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte roční maximum pojistného.',
        'jmhz_eldp_assessment_base_not_whole_czk' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte výsledek sociálního pojištění.',
        'jmhz_ordinary_evidence_missing' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_attribute_10116_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_attribute_10546_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in13_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in28_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_interaction_in30_unresolved' => 'Otevřete Mzdová podání → JMHZ a potvrďte právní skutečnosti.',
        'jmhz_scenario1_pvpoj_unavailable' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte výpočet pojistného.',
        'jmhz_scenario1_pvpoj_source_mismatch' => 'Obnovte přehled a zkontrolujte mzdovou účtárnu pracovních vztahů.',
        'jmhz_annual_request_source_missing' => 'Otevřete Mzdy → Roční zúčtování a evidujte výslovně požádáno nebo nepožádáno.',
        'jmhz_annual_request_status_unresolved' => 'Otevřete Mzdy → Roční zúčtování a rozhodněte stav žádosti.',
        'jmhz_annual_settlement_performance_source_missing' => 'Obnovte přípravu JMHZ nad uzamčenou evidencí výsledků ročního zúčtování.',
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
