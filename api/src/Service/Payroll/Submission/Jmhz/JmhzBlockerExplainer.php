<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzBlockerExplainer
{
    /** Nálezy, u kterých účetní potřebuje vědět, KTERÁ pole hlášení se jich týkají. */
    private const FIELD_LISTING_CODES = ['jmhz_scenario1_whole_czk_required'];

    /** @var array<string,string>|null */
    private static ?array $attributeNames = null;

    /** @var array<string,string> */
    private const REASONS = [
        'effective_term_missing' => 'Chybí účinné podmínky pracovního vztahu pro vykazovaný měsíc.',
        'component_jmhz_mapping_missing' => 'Mzdové složky nemají určené zařazení do JMHZ.',
        'component_jmhz_manual_review' => 'Zařazení mzdových složek do JMHZ vyžaduje kontrolu.',
        'component_jmhz_treatment_invalid' => 'Mzdové složky mají neplatné nastavení pro JMHZ.',
        'jmhz_average_hourly_earning_missing' => 'Chybí ověřený průměrný hodinový výdělek.',
        /*
         * Atribut 10345 je v `formBezPriznaku.xsd` povinný bez výjimky, takže
         * ho nelze vynechat ani u dohody v prvním měsíci. Skutečný průměr se
         * tam ale spočítat nedá — není z čeho — a § 355 zákoníku práce na to
         * má pravděpodobný výdělek. Generická hláška „atribut není doložený"
         * účetní neřekne, že má co dělat, natož kde.
         */
        'jmhz_average_hourly_earning_probable_missing' => 'Chybí průměrný hodinový '
            . 'výdělek a zaměstnanec v rozhodném období neodpracoval zákonné minimum '
            . 'dnů, takže se skutečný průměr spočítat nedá. Podle § 355 zákoníku práce '
            . 'se v takovém případě použije pravděpodobný výdělek, který stanoví '
            . 'zaměstnavatel. Bez jiného podkladu ho aplikace navrhne ve výši spodní '
            . 'meze, minimální mzdy; návrh je potřeba založit a schválit.',
        /*
         * Názvy polí jsou DOSLOVA ty z formuláře (`payroll.people.jmhz_identity`
         * v `web/src/i18n/cs.json`) a ze slovníku
         * {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationFieldVocabulary}.
         * Účetní ten popisek hledá očima na obrazovce; když se liší byť slovem,
         * nenajde ho. „Chybí 10051" jí neřekne vůbec nic.
         */
        'jmhz_identity_incomplete' => 'Pracovní vztah nemá uložené „OIČ / IK MPSV '
            . 'osoby" ani „ID PPV pracovního vztahu". Obě čísla přiděluje ČSSZ '
            . 'při registraci zaměstnance, aplikace je vymyslet nemůže.',
        'jmhz_identity_oic_missing' => 'Chybí „OIČ / IK MPSV osoby" — osobní '
            . 'identifikační číslo, které zaměstnanci přiděluje ČSSZ při registraci.',
        'jmhz_identity_id_ppv_missing' => 'Chybí „ID PPV pracovního vztahu" — '
            . 'identifikátor zaměstnání, který přiděluje ČSSZ při registraci.',
        'jmhz_scenario_activity_code_missing' => 'Chybí kód druhu činnosti pro JMHZ.',
        'jmhz_scenario_relationship_detail_missing' => 'Chybí upřesnění druhu pracovního vztahu pro JMHZ.',
        'jmhz_scenario1_scope_unsupported' => 'Příprava obsahuje smíšené nebo zvláštní scénáře JMHZ, které nelze vydat za běžné hlášení.',
        'jmhz_scenario2_scope_unsupported' => 'Příprava neobsahuje zmrazený scénář odměny pěstouna.',
        'jmhz_scenario2_source_version_unsupported' => 'Příprava nemá verzi potřebnou pro bezpečné rozpoznání odměny pěstouna.',
        'jmhz_scenario2_frozen_resolution_invalid' => 'Zmrazené zařazení odměny pěstouna neodpovídá připnutému katalogu nebo XSD.',
        'jmhz_scenario2_frozen_resolution_missing' => 'Příprava označuje odměnu pěstouna, ale neobsahuje její zmrazený pracovní vztah.',
        'jmhz_scenario2_evidence_gap' => 'Zmrazená příprava nenese ověřený zdroj všech povinných údajů odměny pěstouna.',
        // Nevyplněno (`unverified`) sem UŽ NESPADNE — vykládá se jako „ne",
        // viz JmhzPreparationSnapshotBuilder::DEFAULTED_TRISTATES. Zůstává
        // jen pro poškozený nebo úplně chybějící údaj v evidenci.
        'jmhz_verified_boolean_missing' => 'Údaj o příspěvku APZ, funkčních požitcích nebo dočasném přidělení má v evidenci neplatnou hodnotu.',
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
        'jmhz_scenario1_child_credit_source_inconsistent' => 'Zmrazený nárok na daňové zvýhodnění na děti neodpovídá vypočtené částce.',
        'jmhz_scenario1_child_credit_without_declaration' => 'Měsíční zvýhodnění na děti je uplatněné bez podepsaného prohlášení poplatníka.',
        'jmhz_scenario1_child_credit_caregiver_unknown' => 'Není zodpovězeno, zda tytéž děti vyživuje i jiná osoba v téže domácnosti.',
        'jmhz_scenario1_child_credit_caregiver_inconsistent' => 'Nároky na děti si u téže domácnosti odporují v údaji o jiné vyživující osobě.',
        'jmhz_scenario1_child_credit_caregiver_identity_missing' => 'Jiná osoba vyživující tytéž děti není v podání pojmenovaná.',
        'jmhz_scenario1_child_identity_incomplete' => 'U vyživovaného dítěte chybí jméno, příjmení nebo platné datum narození.',
        'jmhz_scenario1_child_order_unsupported' => 'Pořadí vyživovaného dítěte je mimo číselník měsíčního hlášení.',
        'jmhz_scenario1_withholding_tax_unsupported' => 'Srážková daň není pro tento profil JMHZ připravená.',
        'jmhz_scenario1_withholding_above_thresholds' => 'Srážková daň nesmí nastat: dohody o provedení práce i ostatní vztahy jsou na rozhodných hranicích nebo nad nimi (kontrola 325 ČSSZ).',
        /*
         * Souběh účastných vztahů výpočet počítá po vztazích a hlášení ho
         * vykáže. Nález zbývá jen u revize spočítané dřív, jejíž výsledek
         * nese pojistné jen za osobu, a ten se odhadem dělit nesmí.
         */
        'jmhz_scenario1_concurrent_participation_unsupported' => 'Zaměstnanec má v měsíci víc souběžných pracovních '
            . 'vztahů účastných na sociálním pojištění (nebo pojistné bez účastného vztahu) a výsledek mzdového '
            . 'běhu byl spočítaný dřív, než se pojistné počítalo po vztazích. Nese pojistné jen za osobu, a to se '
            . 'na formuláře vztahů odhadem dělit nesmí.',
        'jmhz_temporary_assignment_user_missing' => 'Zaměstnanec je dočasně přidělen k uživateli '
            . '(agentura práce), ale u pracovního vztahu chybí identifikace uživatele. Hlášení ji '
            . 'u přidělení vyžaduje (kontrola 103 ČSSZ).',
        'jmhz_risk_categorization_missing' => 'Vztah je zařazený jako zdravotnický záchranář nebo člen '
            . 'jednotky HZS podniku (§ 5a odst. 1 písm. b) ZPSZ), ale chybí, o který z obou případů '
            . 'jde. Hlášení ho vykazuje jako kategorizaci rizika (6 nebo 7).',
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
        /*
         * Záporná částka mzdové složky hlášení NEBLOKUJE — sečte se normálně
         * a výsledný záporný součet se do JMHZ vykáže jako 0 (viz
         * JmhzScenario1DocumentResolver::NEGATIVE_INCOME_REPORTED_AS_ZERO).
         * Tenhle nález proto vždycky znamená chybějící nebo neurčenou částku
         * mzdové složky, ne zápornou hodnotu.
         */
        'jmhz_negative_or_deferred_income_unsupported' => 'Mzdová složka nemá určenou částku za vykazovaný měsíc.',
        'jmhz_eldp_evidence_missing' => 'Chybí připravená evidence důchodového pojištění.',
        'jmhz_eldp_absences_unsupported' => 'Měsíc obsahuje nepřítomnost, jejíž zápis do evidence důchodového pojištění nelze bezpečně odvodit; automaticky se zpracuje dovolená, nemoc, karanténa a ošetřovné.',
        'jmhz_eldp_ppm_childbirth_missing' => 'Peněžitá pomoc v mateřství sahá na očekávaný den porodu nebo za něj a nemá doplněný skutečný den porodu; vyloučenou dobou je jen část do dne před porodem.',
        'jmhz_eldp_excluded_days_unsupported' => 'Vyloučené doby neodpovídají neodpracovaným hodinám v pracovním souhrnu.',
        'jmhz_eldp_import_absence_dates_missing' => 'Docházka měsíce je převzatá z importu a obsahuje nemoc, ošetřovné, otcovskou, neplacené či náhradní volno nebo neomluvenou absenci jen jako součet hodin; evidenci důchodového pojištění z nich bez dat od–do nelze odvodit.',
        'jmhz_eldp_excluded_days_sum_mismatch' => 'Úhrn vyloučených dob neodpovídá jejich rozpadu podle druhů.',
        'jmhz_eldp_excluded_days_exceed_period' => 'Vyloučené doby přesahují dobu důchodového pojištění vykázanou v měsíci.',
        'jmhz_eldp_work_summary_missing' => 'Chybí schválený pracovní souhrn potřebný pro evidenci důchodového pojištění.',
        'jmhz_eldp_work_summary_mismatch' => 'Pracovní souhrn obsahuje výjimku, kterou nelze do běžné evidence důchodového pojištění bezpečně převzít.',
        'jmhz_eldp_relationship_kind_unsupported' => 'Druh pracovního vztahu není podporován automatickou evidencí důchodového pojištění.',
        'jmhz_eldp_ordinary_activity_unsupported' => 'Druh činnosti není podporován automatickou evidencí důchodového pojištění.',
        'jmhz_eldp_relation_activity_mismatch' => 'Druh činnosti neodpovídá druhu pracovního vztahu.',
        'jmhz_eldp_scenario_unsupported' => 'Pracovní vztah nepatří do podporovaného běžného scénáře evidence důchodového pojištění.',
        'jmhz_eldp_social_relationship_unsupported' => 'Pracovní vztah nemá běžnou účast na sociálním pojištění.',
        'jmhz_eldp_capped_base_unsupported' => 'Vyměřovací základ byl krácen ročním maximem a vyžaduje individuální kontrolu.',
        'jmhz_eldp_assessment_base_not_whole_czk' => 'Vyměřovací základ nelze bezpečně převést na celé koruny pro evidenci důchodového pojištění.',
        'jmhz_eldp_section18_days_unresolved' => 'Vyloučené dny nemocenského pojištění (§ 18 odst. 7) nejde u tohoto vztahu rozdělit: nemoc je ve mzdovém běhu zmrazená bez okna náhrady mzdy. V měsíci bez vyloučených dob (důchodce, měsíc bez dnů pojištění) je hlášení přesto musí nést.',
        'jmhz_work_summary_v2_missing' => 'Chybí schválený pracovní souhrn měsíce.',
        'jmhz_employer_part_time_discount_unverified' => 'Nárok na slevu za kratší úvazek není doložený.',
        'jmhz_employer_part_time_discount_outcome_missing' => 'Chybí posouzení nároku na slevu za kratší úvazek.',
        'jmhz_employer_part_time_discount_reason_missing' => 'Chybí důvod uplatnění slevy za kratší úvazek.',
        'jmhz_employer_part_time_discount_working_time_missing' => 'Chybí sjednaná kratší týdenní pracovní doba.',
        'jmhz_employer_part_time_discount_working_time_unresolved' => 'Sjednanou kratší týdenní pracovní dobu nelze vykázat.',
        'jmhz_employer_part_time_discount_activity_unsupported' => 'Sleva za kratší úvazek neodpovídá druhu pracovního vztahu.',
        'jmhz_employee_social_discount_relationship_unresolved' => 'Slevu na pojistném pracujícího důchodce nelze '
            . 'přiřadit pracovnímu vztahu: vztah, který nese pojistné osoby, není účastný na pojištění nebo má '
            . 'jiný vyměřovací základ, než ze kterého je sleva vypočtená.',
        'jmhz_employee_social_discount_exclusive' => 'U jednoho pracovního vztahu se sbíhá sleva pracujícího '
            . 'důchodce se sezónní slevou na pojistném; obě současně uplatnit nelze.',
        'jmhz_xml_identity_name_incomplete' => 'Zaměstnanec se hlásí jménem, protože mu ČSSZ zatím nepřidělila OIČ ani ID PPV, a k tomu chybí příjmení, jméno, datum narození, datum nástupu nebo druh činnosti.',
        /*
         * Proč nález a ne zaokrouhlení, viz
         * JmhzScenario1DocumentResolver::wholeCzk(): XSD i Pokyny k vyplnění
         * chtějí celé číslo, ale způsob zaokrouhlení haléřů nedávají. Pojistné
         * zaměstnavatele (10481) se od kontroly 315 počítá samo a nález
         * nevyvolá; zbývá výsledek běhu, typicky mzdová složka s haléři.
         */
        'jmhz_deferral_concurrent_incomplete' => 'Z hlášení je odložený jen jeden ze souběžných '
            . 'pracovních vztahů osoby. Pojistné osoby a souhrnná data zaměstnance nese jediný '
            . 'formulář, takže vynechání jednoho vztahu by změnilo, co vykazují ostatní.',
        'jmhz_deferral_no_form_left' => 'Odložením by v hlášení za registraci nezůstal žádný formulář.',
        'jmhz_excluded_summary_unavailable' => 'Souhrn daní opravného hlášení nelze sestavit, protože '
            . 'u osoby bez opravovaného formuláře chybí spočtená záloha na daň.',
        'jmhz_scenario1_whole_czk_required' => 'Částka, která se do měsíčního hlášení '
            . 'vykazuje v celých korunách, vyšla ve výsledku mzdového běhu s haléři. '
            . 'Hlášení přijímá jen celá čísla a oficiální podklady ČSSZ a MPSV způsob '
            . 'zaokrouhlení haléřů nestanoví, proto ho aplikace sama nezaokrouhlí.',
    ];

    /** @var array<string,string> */
    private const ACTIONS = [
        'jmhz_scenario1_scope_unsupported' => 'Zkontrolujte druhy činnosti v přípravě a nepodporované vztahy zpracujte individuálně.',
        'jmhz_scenario2_evidence_gap' => 'Tento scénář zatím zpracujte individuálně podle podkladů ČSSZ; hodnoty nelze bezpečně doplnit odhadem.',
        'component_jmhz_mapping_missing' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení. '
            . 'Týká se jen složek, které se v období použily — nezařazená složka bez pohybu '
            . 'hlášení nebrání.',
        /*
         * Dvě různé nápravy podle toho, jestli zaměstnanec u ČSSZ registrovaný
         * je, nebo není. Rada „doplňte údaj" je u obou k ničemu: to číslo se
         * nevyplňuje, ono se OPISUJE odjinud, a když ho ještě nikdo nepřidělil,
         * musí se nejdřív podat přihláška.
         */
        'jmhz_identity_incomplete' => 'Když zaměstnanec u ČSSZ registrovaný ještě není, '
            . 'podejte nejdřív přihlášku (PREZEC/REGZEC A1) — čísla přijdou v protokolu '
            . 'a doplní se sama. Když registrovaný je (typicky u firmy, která běží roky), '
            . 'opište je z protokolu ČSSZ nebo z ePortálu na kartě pracovního vztahu '
            . 'v části „Identifikátory přidělené ČSSZ pro JMHZ".',
        'jmhz_identity_oic_missing' => 'Opište ho z protokolu o přijetí přihlášky nebo '
            . 'z ePortálu ČSSZ na kartě pracovního vztahu v části „Identifikátory přidělené '
            . 'ČSSZ pro JMHZ". Když zaměstnanec registrovaný ještě není, podejte nejdřív '
            . 'přihlášku (PREZEC/REGZEC A1) a číslo se doplní z protokolu samo.',
        'jmhz_identity_id_ppv_missing' => 'Opište ho z protokolu o přijetí přihlášky nebo '
            . 'z ePortálu ČSSZ na kartě pracovního vztahu v části „Identifikátory přidělené '
            . 'ČSSZ pro JMHZ". Když zaměstnanec registrovaný ještě není, podejte nejdřív '
            . 'přihlášku (PREZEC/REGZEC A1) a číslo se doplní z protokolu samo.',
        'component_jmhz_manual_review' => 'Otevřete Mzdy → Mzdové složky a potvrďte zařazení.',
        'component_jmhz_treatment_invalid' => 'Otevřete Mzdy → Mzdové složky a opravte nastavení.',
        'jmhz_average_hourly_earning_missing' => 'Otevřete Mzdy → Absence a průměry a doplňte výdělek.',
        'jmhz_average_hourly_earning_probable_missing' => 'Otevřete Mzdy → Absence a průměry '
            . 'a založte průměr za čtvrtletí. Když nejde odvodit z dosažené ani sjednané '
            . 'mzdy, aplikace navrhne spodní mez, minimální hodinovou mzdu; tak postupují '
            . 'i jiné mzdové systémy. Vyšší pravděpodobný výdělek zadejte na kartě '
            . 'pracovního vztahu v části „Průměrný výdělek" (pole „Pravděpodobný hodinový '
            . 'výdělek" s odůvodněním) a má přednost.',
        'jmhz_verified_boolean_missing' => 'Otevřete Mzdy → Zaměstnanci, na kartě pracovního vztahu v části Evidence pro ČSSZ zvolte u všech tří otázek Ano nebo Ne a uložte.',
        'jmhz_work_month_not_approved' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_work_summary_v2_missing' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_scenario1_earnings_vector_incomplete' => 'Otevřete Mzdy → Mzdové složky a doplňte zařazení.',
        'jmhz_negative_or_deferred_income_unsupported' => 'Otevřete Mzdy → Mzdové běhy a doplňte částku dotčené mzdové složky za měsíc.',
        'jmhz_eldp_evidence_missing' => 'Obnovte test JMHZ; pokud blokace zůstane, postupujte podle konkrétního upozornění u pracovního vztahu.',
        'jmhz_eldp_absences_unsupported' => 'Zkontrolujte Mzdy → Pracovní doba; nestandardní měsíc ponechte blokovaný a zpracujte jej individuálně podle podkladů ČSSZ.',
        'jmhz_eldp_ppm_childbirth_missing' => 'Otevřete Mzdy → Nepřítomnosti, u mateřské doplňte den porodu, opravte mzdový běh měsíce a hlášení připravte znovu.',
        'jmhz_eldp_excluded_days_unsupported' => 'Otevřete Mzdy → Pracovní doba a slaďte evidované nepřítomnosti s neodpracovanými hodinami měsíce.',
        'jmhz_eldp_import_absence_dates_missing' => 'Otevřete Mzdy → Absence a průměry a zapište nepřítomnost s daty od–do; potom měsíc v Mzdy → Pracovní doba znovu otevřete a schvalte.',
        'jmhz_eldp_excluded_days_sum_mismatch' => 'Zkontrolujte Mzdy → Absence a průměry; rozpad nepřítomností v měsíci není konzistentní.',
        'jmhz_eldp_excluded_days_exceed_period' => 'Otevřete Mzdy → Absence a průměry a zkontrolujte rozsah nepřítomností proti trvání pracovního vztahu.',
        'jmhz_eldp_work_summary_missing' => 'Otevřete Mzdy → Pracovní doba a měsíc schvalte.',
        'jmhz_eldp_work_summary_mismatch' => 'Otevřete Mzdy → Pracovní doba a zkontrolujte absence a neodpracované doby.',
        'jmhz_eldp_relationship_kind_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte druh pracovního vztahu.',
        'jmhz_eldp_ordinary_activity_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte druh činnosti pro JMHZ.',
        'jmhz_eldp_relation_activity_mismatch' => 'Otevřete Mzdy → Zaměstnanci a slaďte druh vztahu s druhem činnosti pro JMHZ.',
        'jmhz_eldp_scenario_unsupported' => 'Otevřete Mzdy → Zaměstnanci a zkontrolujte nastavení pracovního vztahu pro JMHZ.',
        'jmhz_eldp_social_relationship_unsupported' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte účast na sociálním pojištění.',
        'jmhz_eldp_capped_base_unsupported' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte roční maximum pojistného.',
        'jmhz_eldp_assessment_base_not_whole_czk' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte výsledek sociálního pojištění.',
        'jmhz_eldp_section18_days_unresolved' => 'Otevřete Mzdy → Mzdové běhy, u měsíce založte opravnou revizi a znovu ji schvalte; nové zmrazení převezme okno náhrady mzdy ze schválené nemoci. Pokud nemoc schválená není, schvalte ji nejdřív v Mzdy → Absence a průměry.',
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
        'jmhz_scenario1_child_credit_caregiver_unknown' => 'Otevřete Mzdy → Zaměstnanci → Vyživované osoby a u nároku rozhodněte, zda tytéž děti vyživuje i jiná osoba.',
        'jmhz_scenario1_child_credit_caregiver_inconsistent' => 'Otevřete Mzdy → Zaměstnanci → Vyživované osoby a sjednoťte odpověď u všech nároků téže domácnosti.',
        'jmhz_scenario1_child_credit_caregiver_identity_missing' => 'Otevřete Mzdy → Zaměstnanci → Vyživované osoby a doplňte jméno, příjmení a datum narození jiné vyživující osoby.',
        'jmhz_scenario1_child_identity_incomplete' => 'Otevřete Mzdy → Zaměstnanci → Vyživované osoby, zkontrolujte jméno, příjmení a datum narození dítěte a připravte hlášení znovu.',
        'jmhz_preparation_not_ready' => 'Otevřete test JMHZ a postupně doplňte zvýrazněné skupiny údajů.',
        'jmhz_primary_employment_unresolved' => 'Otevřete Mzdy → Zaměstnanci a na kartě pracovního vztahu '
            . 'označte právě jeden vztah osoby jako hlavní; ostatní souběžné vztahy nechte jako vedlejší.',
        'jmhz_scenario1_concurrent_participation_unsupported' => 'Otevřete Mzdy → Mzdové běhy, u běhu '
            . 'tohoto měsíce zvolte „Otevřít opravu" a mzdy spočítejte znovu, výpočet pojistné rozdělí '
            . 'po vztazích. Novou revizi schvalte a hlášení připravte znovu.',
        'jmhz_temporary_assignment_user_missing' => 'Otevřete Mzdy → Zaměstnanci, na kartě pracovního '
            . 'vztahu v části Evidence pro ČSSZ u dočasného přidělení vyplňte IČO uživatele, nebo u '
            . 'zahraniční osoby stát, registrační číslo a název; uložte a přepočítejte mzdový běh.',
        'jmhz_risk_categorization_missing' => 'Otevřete Mzdy → Zaměstnanci, na kartě pracovního vztahu '
            . 'v části Výjimečné situace vyberte u „Kategorizace rizika pro JMHZ" práci zdravotnického '
            . 'záchranáře, nebo člena jednotky HZS podniku; uložte a přepočítejte mzdový běh.',
        'jmhz_employee_social_discount_relationship_unresolved' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte '
            . 'účast pracovních vztahů na sociálním pojištění; nesedí-li, hlášení za tento měsíc podejte ručně '
            . 'přes ePortál ČSSZ.',
        'jmhz_employee_social_discount_exclusive' => 'Opravte buď potvrzení sezónní slevy v Mzdová podání → JMHZ, '
            . 'nebo slevu pracujícího důchodce v zákonné evidenci osoby (Mzdy → Zaměstnanci).',
        'jmhz_xml_identity_name_incomplete' => 'Otevřete Mzdy → Zaměstnanci a na kartě zaměstnance a jeho pracovního vztahu doplňte jméno, příjmení, datum narození, den nástupu a druh činnosti; OIČ ani ID PPV shánět nemusíte, ta přidělí ČSSZ až v protokolu o přijetí.',
        'jmhz_deferral_concurrent_incomplete' => 'Odložte z hlášení všechny vztahy osoby v této '
            . 'registraci, nebo odložení zrušte.',
        'jmhz_deferral_no_form_left' => 'Zrušte odložení alespoň u jednoho vztahu, nebo hlášení '
            . 'podejte až po doplnění dat.',
        'jmhz_excluded_summary_unavailable' => 'Otevřete Mzdy → Mzdové běhy a zkontrolujte výpočet '
            . 'zálohy na daň u dotčené osoby, nebo ji zahrňte do opravy.',
        'jmhz_scenario1_whole_czk_required' => 'Mzda, plat, odměna z dohody i náhrada mzdy se podle '
            . '§ 142 odst. 2 a § 144 zákoníku práce zaokrouhlují na celé koruny směrem nahoru. '
            . 'Otevřete Mzdy → Mzdové běhy, u dotčených osob upravte haléřovou částku mzdové složky '
            . 'na celé koruny nahoru, aby zaměstnanec nedostal méně, než mu náleží; běh přepočítejte, '
            . 'schvalte a hlášení připravte znovu.',
    ];

    /** @param list<JmhzScenario1Blocker> $blockers */
    public static function describe(array $blockers): string
    {
        if ($blockers === []) {
            return 'Důvod blokace nebyl uveden. Obnovte test JMHZ a zkuste jej znovu.';
        }

        /*
         * Jedna osoba nese nález za každé pole zvlášť (18 vztahů s haléřovým
         * přesčasem = 144 nálezů), takže se počítají DOTČENÉ entity, ne řádky
         * nálezů. Nález bez entity nejde s jiným ztotožnit a počítá se sám.
         *
         * @var array<string,array{blocker:JmhzScenario1Blocker,entities:array<string,array<string,true>>,attributes:array<string,true>}> $groups
         */
        $groups = [];
        foreach ($blockers as $index => $blocker) {
            $groups[$blocker->code] ??= ['blocker' => $blocker, 'entities' => [], 'attributes' => []];
            $entityKey = $blocker->entityId === null ? "#{$index}" : (string) $blocker->entityId;
            $groups[$blocker->code]['entities'][$blocker->entityType][$entityKey] = true;
            foreach ($blocker->attributeIds as $attributeId) {
                $groups[$blocker->code]['attributes'][(string) $attributeId] = true;
            }
        }

        $descriptions = [];
        foreach ($groups as $group) {
            $blocker = $group['blocker'];
            $reason = self::reason($blocker->code, $blocker->message);
            $action = self::action($blocker->code, $blocker->entityType);
            $fields = in_array($blocker->code, self::FIELD_LISTING_CODES, true)
                ? self::fields(array_keys($group['attributes']))
                : '';
            $descriptions[] = $reason . ' '
                . $fields
                . self::affected($group['entities'])
                . ' ' . $action;
        }

        return implode(' ', $descriptions);
    }

    /**
     * Důvod a náprava jednoho kódu, bez vazby na seznam blokátorů.
     *
     * Veřejná proto, že tytéž věty potřebuje i serializér XML, který blokátory
     * nemá — vyhazuje {@see JmhzXmlException}. Kdyby si je opsal, měl by ten
     * kód dvě znění a jedno by se přestalo udržovat.
     */
    public static function guidance(string $code): string
    {
        return self::reason($code) . ' ' . self::action($code);
    }

    /**
     * Důvod nálezu. Konkrétní věta zdroje (např. serializéru formuláře) má
     * přednost před obecnou „chybí zákonný údaj" - obecná věta nic neřekne.
     */
    public static function reason(string $code, ?string $message = null): string
    {
        $reason = self::REASONS[$code] ?? null;
        if ($reason !== null) {
            return $reason;
        }
        if ($message !== null && trim($message) !== '') {
            return trim($message);
        }

        return 'Chybí zákonný údaj potřebný pro měsíční hlášení.';
    }

    /** Krok nápravy; bez konkrétního se odvodí z druhu dotčeného záznamu. */
    public static function action(string $code, string $entityType = ''): string
    {
        return self::ACTIONS[$code] ?? self::fallbackAction($entityType);
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

    /** @param array<string,array<string,true>> $entitiesByType */
    private static function affected(array $entitiesByType): string
    {
        $parts = [];
        foreach ($entitiesByType as $entityType => $entities) {
            $parts[] = self::counted((string) $entityType, count($entities));
        }

        return 'Dotčeno: ' . implode(', ', $parts) . '.';
    }

    private static function counted(string $entityType, int $count): string
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

        return "{$count} {$form}";
    }

    /**
     * Názvy polí doslova z datového slovníku JMHZ (připnutý balík), aby je
     * účetní poznala ve formuláři ČSSZ; číslo pole jí samo nic neřekne.
     *
     * @param list<int|string> $attributeIds
     */
    private static function fields(array $attributeIds): string
    {
        if ($attributeIds === []) {
            return '';
        }
        $ids = array_map(static fn (int|string $id): string => (string) $id, $attributeIds);
        sort($ids, SORT_NATURAL);
        $names = array_values(array_unique(array_map(
            static fn (string $id): string => self::attributeName($id) ?? "pole {$id}",
            $ids,
        )));

        return (count($names) === 1 ? 'Týká se pole: ' : 'Týká se polí: ')
            . implode(', ', $names) . '. ';
    }

    private static function attributeName(string $attributeId): ?string
    {
        if (self::$attributeNames === null) {
            self::$attributeNames = [];
            try {
                $manifest = (new JmhzSpecPackageCatalog())->load(JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY);
                $rows = $manifest['payload']['dictionary_attributes'] ?? [];
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = is_array($row) ? ($row['attribute_id'] ?? null) : null;
                    $name = is_array($row) ? ($row['name'] ?? null) : null;
                    if (is_string($id) && is_string($name) && trim($name) !== '') {
                        self::$attributeNames[$id] = trim($name);
                    }
                }
            } catch (\Throwable) {
                // Vysvětlení nesmí spadnout kvůli slovníku; pole se pak jmenuje číslem.
            }
        }

        return self::$attributeNames[$attributeId] ?? null;
    }
}
