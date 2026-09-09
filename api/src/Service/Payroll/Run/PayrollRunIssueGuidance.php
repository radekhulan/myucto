<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollRunIssueGuidance
{
    private const RULES = [
        'duplicate_id' => ['Pohledávka je v podkladu srážek uvedena vícekrát. Zkontrolujte evidenci pohledávek; pokud duplicita není v agendě vidět, předejte uloženou revizi správci.', 'enforcement'],
        'enforcement_ruleset_incomplete' => ['Pravidla pro výpočet exekučních srážek jsou neúplná. Správce musí ověřit a doplnit platnou sadu pravidel pro rok výplaty.', 'rulesets'],
        'concurrent_priority_enforcement_with_insolvency_requires_manual_review' => ['Současně je evidována insolvence a přednostní exekuce. V agendě Insolvence ověřte podle doručených rozhodnutí, zda pokračovat v exekuci a komu srážku odvádět.', 'insolvency'],
        'participation_component_manual_review|assessment_component_manual_review' => ['Mzdová složka nemá ověřené zahrnutí do účasti na pojištění nebo vyměřovacího základu. V číselníku Mzdové složky ověřte pojistné zacházení této složky.', 'components'],
        'prior_period_correction_requires_period_revision|dpp_group_negative_income_requires_period_revision|dpc_group_negative_income_requires_period_revision' => ['Oprava dřívějšího období nebo záporný příjem souběžných dohod vyžaduje opravnou revizi původní mzdy. Otevřete odpovídající mzdový běh a opravte jej tam.', 'runs'],
        'correction_period_unverified' => ['Není ověřeno období, ke kterému se oprava příjmu vztahuje. V měsíčních vstupech doplňte původní období opravy.', 'inputs'],
        'dpp_group_contains_unresolved_relationship|dpc_group_contains_unresolved_relationship' => ['U souběžných dohod není dořešena účast jednoho z pracovních vztahů na pojištění. Opravte konkrétní kontrolu příslušné dohody; teprve potom lze posoudit jejich součet.', 'employment'],
        'other_employer_base_outside_calculation_month' => ['Potvrzení vyměřovacího základu od jiného zaměstnavatele patří jinému měsíci. V zákonné evidenci doplňte potvrzení za právě počítaný měsíc.', 'statutory'],
        'minimum_reduction_requires_whole_month' => ['Výjimka z minima zdravotního pojištění není doložena po celý požadovaný měsíc. V zákonné evidenci ověřte důvod výjimky a celé období její platnosti.', 'statutory'],
        'part_time_discount_unverified' => ['Nárok na slevu zaměstnavatele za zkrácený úvazek není ověřen. Na kartě pracovního vztahu doložte důvod a platnost nároku.', 'employment'],
        'part_time_discount_relationship_kind_unsupported' => ['Sleva zaměstnavatele za zkrácený úvazek je požadována u nezpůsobilého druhu vztahu. Na kartě vztahu ověřte jeho druh a nárok na slevu.', 'employment'],
        'part_time_discount_worked_hours_missing' => ['Pro slevu zaměstnavatele chybí skutečně odpracované hodiny. Doplňte a schvalte docházku vztahu za měsíc mzdy.', 'time'],
        'part_time_discount_weekly_working_time_missing' => ['Pro slevu zaměstnavatele chybí sjednaná týdenní pracovní doba. Doplňte ji v účinných podmínkách pracovního vztahu.', 'employment'],
        'part_time_discount_employment_length_missing' => ['Pro slevu zaměstnavatele chybí délka pracovního poměru v měsíci. Na kartě vztahu ověřte počátek a konec pracovního poměru.', 'employment'],
        'employer_rate_category_unverified' => ['Není ověřena kategorie sazby sociálního pojistného zaměstnavatele. Na kartě vztahu doložte zařazení práce do odpovídající kategorie.', 'employment'],
        'agriculture_dpp_discount_requires_manual_review' => ['Je požadována sleva sociálního pojistného u zemědělské DPP, kterou automatický výpočet neumí ověřit. Ověřte nárok na kartě vztahu a předejte jej správci k odbornému posouzení.', 'employment'],
        'risky_savings_pension_company_missing|risky_savings_product_reference_missing' => ['Pro příspěvek na spoření chybí penzijní společnost nebo číslo produktu zaměstnance. Doplňte je v evidenci příspěvku za rizikovou práci.', 'risky_savings'],
        'statutory_input_incomplete' => ['Zákonný výpočet nebyl dokončen a uložený výsledek neobsahuje konkrétní příčinu. Předejte správci číslo tohoto běhu a revize, aby ověřil podklady výpočtu. Částky nedoplňujte odhadem.', 'runs'],
        'enforcement_input_incomplete' => ['Výpočet srážek nebyl dokončen a neobsahuje konkrétní příčinu. V agendě Exekuce ověřte podklady uvedené osoby; pokud jsou úplné, předejte běh a revizi správci.', 'enforcement'],
        'duplicate_employee_reference|employee_reference_invalid|person_missing|person_shape_invalid' => ['Uložený podklad mzdy obsahuje chybějící, neplatnou nebo duplicitní osobu. Jde o problém uložené revize. Předejte správci číslo běhu a revize; osobní údaje neměňte odhadem.', 'runs'],
        'duplicate_employment_reference|duplicate_employment_relationship_reference|employment_person_mismatch|employment_reference_invalid|employment_snapshot_invalid' => ['Uložený podklad mzdy obsahuje neplatný nebo duplicitní pracovní vztah, případně vztah jiné osoby. Předejte správci číslo běhu a revize k ověření vazeb.', 'runs'],
        'statutory_evidence_snapshot_missing_or_mismatched|health_evidence_mapping_failed|social_evidence_mapping_failed|tax_evidence_mapping_failed' => ['Zákonná evidence v uložené revizi chybí nebo neodpovídá zaměstnanci a období. Zkontrolujte aktuální zákonnou evidenci; po opravě založte novou revizi. Pokud evidence sedí, předejte běh správci.', 'statutory'],
        'tax_evidence_invalid' => ['Daňové podklady obsahují neplatnou hodnotu. V zákonné evidenci zkontrolujte platnost prohlášení, rezidenci a uplatněné slevy; pokud údaje odpovídají dokladům, předejte revizi správci.', 'statutory'],
        'ruleset_not_active|enforcement_ruleset_not_effective_for_whole_year|unsupported_tax_year|risky_savings_ruleset_invalid' => ['Pro datum výpočtu není dostupná platná schválená sada mzdových pravidel. Správce musí v agendě Pravidla mezd ověřit podporu roku a platnost sady. Datum mzdy neměňte jen kvůli obejití kontroly.', 'rulesets'],
        'invalid_payroll_period|payment_date_outside_ruleset_2026' => ['Období mzdy nebo datum výplaty není platné pro výpočet srážek. Ověřte období a datum výplaty ve mzdovém běhu; správné datum mimo podporované období předejte správci.', 'runs'],
        'negative_assessment_base_requires_period_revision|negative_participation_income_requires_period_revision|negative_relationship_tax_base|prior_period_tax_correction_requires_revision' => ['Záporný základ nebo oprava příjmu z minulého období vyžaduje opravnou revizi původního mzdového běhu. Opravu nezadávejte jako běžný příjem aktuálního měsíce.', 'runs'],
        'foreign_regime_requires_non_applicable_czech_insurer_snapshot' => ['Zaměstnanec je evidován v zahraničním pojištění, ale současně má českou zdravotní pojišťovnu pro odvod. V zákonné evidenci opravte tento rozpor podle doloženého státu pojištění.', 'statutory'],
        'foster_reward_only_exception_relationships_mismatch' => ['Výjimka zdravotního pojištění pro samotnou odměnu pěstouna neodpovídá evidovaným vztahům. Zkontrolujte souběžná zaměstnání a podklad pro výjimku v zákonné evidenci.', 'statutory'],
        'part_time_discount_may_select_only_one_relationship_per_person' => ['Sleva zaměstnavatele na sociálním pojištění je zvolena u více vztahů jedné osoby. V podmínkách vztahů ponechte uplatnění pouze u jednoho způsobilého vztahu.', 'employment'],
        'duplicate_tax_child_claim|tax_child_concurrent_claim_unresolved|tax_child_order_gap' => ['Zvýhodnění na děti obsahuje duplicitní nárok, nevyřešený souběh nebo mezeru v pořadí. U vyživovaných osob opravte měsíční nároky a pořadí dětí podle podkladů.', 'dependants'],
        'duplicate_tax_credit_claim' => ['Stejná osobní sleva na dani je uplatněna vícekrát. V zákonné evidenci odstraňte duplicitní nárok, aby za měsíc zůstal pouze jeden platný podklad.', 'statutory'],
        'tax_child_requires_signed_declaration|tax_credit_requires_signed_declaration' => ['Sleva nebo zvýhodnění na dítě je uplatněno bez podepsaného daňového prohlášení. V zákonné evidenci doložte skutečný podpis prohlášení, nebo neuplatňujte nárok bez podpisu.', 'statutory'],
        'nonresident_monthly_child_credit_not_supported|nonresident_monthly_credit_not_supported' => ['U daňového nerezidenta je uplatněna sleva nebo zvýhodnění nepodporované v měsíčním výpočtu. V zákonné evidenci ověřte rezidenci a způsob uplatnění nároku podle jeho podkladů.', 'statutory'],
        'tax_residence_evidence_not_effective' => ['Doklad o daňové rezidenci není platný pro měsíc mzdy. V zákonné evidenci doplňte aktuální doklad a jeho platnost.', 'statutory'],
        'concurrent_priority_enforcement_with_insolvency' => ['Současně je evidována insolvence a přednostní exekuce, jejichž souběh vyžaduje posouzení. V agendě Insolvence ověřte rozhodnutí a určení příjemce srážky.', 'insolvency'],
        'risky_savings_evidence_invalid|risky_savings_evidence_not_approved' => ['Podklady příspěvku na spoření u rizikové práce nejsou platně schválené. V Mzdových složkách na záložce Rizikové spoření opravte a schvalte evidenci rizikové práce.', 'risky_savings'],
        'risky_savings_employee_not_informed' => ['Není doloženo informování zaměstnance o příspěvku za rizikovou práci. V Mzdových složkách na záložce Rizikové spoření doplňte datum a podklad informování.', 'risky_savings'],
        'risky_savings_risk_factor_invalid|risky_savings_work_category_invalid' => ['U příspěvku za rizikovou práci je neplatný rizikový faktor nebo kategorie práce. V Mzdových složkách na záložce Rizikové spoření opravte zařazení podle rozhodnutí o kategorizaci.', 'risky_savings'],
        'risky_savings_shift_eighths_invalid' => ['Počet směn rizikové práce v osminách není platný. Opravte měsíční podklad rizikových směn podle odpracované doby.', 'risky_savings'],
        'risky_savings_assessment_base_missing' => ['Pro příspěvek za rizikovou práci chybí ověřený vyměřovací základ nebo platný uložený podklad. Nejprve dokončete kontrolu pojistného v tomto mzdovém běhu. Pokud je výpočet úplný, předejte revizi správci.', 'runs'],
        'risky_savings_claim_date_invalid' => ['Datum uplatnění nároku na příspěvek za rizikovou práci není platné. V Mzdových složkách na záložce Rizikové spoření doplňte skutečné datum uplatnění.', 'risky_savings'],
        'risky_savings_payment_target_invalid|risky_savings_payment_target_changed' => ['Údaje pro platbu příspěvku na spoření chybí nebo se změnily proti schválenému podkladu. V Mzdových složkách na záložce Rizikové spoření ověřte poskytovatele a platební údaje a připravte novou revizi.', 'risky_savings'],
        'payroll_component_missing' => ['Chybí schválená mzdová složka. V měsíčních vstupech doplňte a schvalte složku pro uvedený pracovní vztah.', 'inputs'],
        'payroll_component_invalid|component_mapping_failed|component_treatment_unverified|income_component_tax_treatment_unverified' => ['Mzdová složka nemá platné nebo ověřené zařazení do daně a pojistného. V číselníku Mzdové složky opravte její daňové a pojistné zacházení.', 'components'],
        'component_amount_invalid' => ['Částka mzdové složky není platná. V měsíčních vstupech opravte částku a znovu ji schvalte.', 'inputs'],
        'negative_component_requires_revision|prior_period_component_requires_revision' => ['Vstup obsahuje zápornou částku nebo částku za jiné období. Opravte příslušný původní mzdový běh opravnou revizí; částku nepřidávejte jako běžný příjem tohoto měsíce.', 'runs'],
        'annual_accumulator_missing' => ['Chybí počáteční nebo průběžné roční součty zaměstnance. V zákonné evidenci doplňte návazné roční základy a daňové součty podle předchozích mezd.', 'statutory'],
        'annual_accumulator_invalid' => ['Roční součty zaměstnance nejsou platné nebo neodpovídají návazným mzdám. Zkontrolujte počáteční stavy a předchozí schválené měsíce.', 'statutory'],
        'employment_term_missing|employment_relationship_missing' => ['Pro období mzdy chybí účinné podmínky pracovního vztahu. Na kartě vztahu doplňte podmínky s odpovídající platností.', 'employment'],
        'employment_dates_invalid|relationship_kind_or_dates_invalid' => ['Datum začátku nebo konce pracovního vztahu není platné. Na kartě vztahu opravte jeho časovou platnost.', 'employment'],
        'relationship_kind_missing|relationship_kind_unsupported|relationship_mapping_failed' => ['Druh pracovního vztahu není zadaný nebo jej výpočet neumí zpracovat. Zkontrolujte druh vztahu; je-li správný, požádejte správce o doplnění podpory tohoto druhu.', 'employment'],
        'participation_override_unsupported|tax_regime_override_unsupported' => ['Ruční přepsání účasti na pojištění nebo daňového režimu není podpořené. Na kartě vztahu zkontrolujte podmínky a použijte doloženou zákonnou evidenci místo ručního přepsání.', 'employment'],
        'post_termination_income_attribution_unverified|income_month_attribution_unverified' => ['U příjmu po skončení vztahu není ověřeno období, do kterého patří. V měsíčních vstupech doplňte původní období příjmu a ověřte jeho přiřazení.', 'inputs'],
        'health_coverage_evidence_missing|health_coverage_evidence_invalid|health_coverage_evidence_conflict' => ['Chybí platné zdravotní pojištění nebo se jeho období překrývají. V zákonné evidenci opravte intervaly pojištění tak, aby jednoznačně pokrývaly měsíc mzdy.', 'statutory'],
        'health_insurer_evidence_unverified|health_insurer_snapshot_unverified' => ['Není ověřena zdravotní pojišťovna zaměstnance. V zákonné evidenci potvrďte pojišťovnu a její platnost pro měsíc mzdy.', 'statutory'],
        'health_jurisdiction_evidence_unverified|health_insurance_jurisdiction_unverified' => ['Není ověřeno, ve kterém státě je zaměstnanec zdravotně pojištěn. V zákonné evidenci doplňte příslušnost k pojištění a doložte ji.', 'statutory'],
        'health_minimum_reduction_invalid|health_minimum_reduction_unverified|health_minimum_reductions_invalid|minimum_reduction_unverified' => ['Snížení minima zdravotního pojištění není platně doložené. V zákonné evidenci opravte důvod a počet dnů snížení minima pro daný měsíc.', 'statutory'],
        'health_minimum_responsibility_invalid|health_minimum_responsibility_unverified|minimum_top_up_responsibility_unverified' => ['Není určeno nebo ověřeno, kdo hradí doplatek zdravotního pojištění do minima. V zákonné evidenci potvrďte odpovědnost zaměstnance nebo zaměstnavatele.', 'statutory'],
        'health_other_employer_evidence_invalid|selected_top_up_employer_unverified' => ['Podklady od dalšího zaměstnavatele pro minimum zdravotního pojištění nejsou ověřené. V zákonné evidenci doplňte potvrzení o příjmu a zaměstnavateli, který provede doplatek.', 'statutory'],
        'social_a1_evidence_conflict|social_a1_evidence_unverified' => ['Doklad A1 není ověřený nebo odporuje evidované příslušnosti k sociálnímu pojištění. V zákonné evidenci opravte stát a platnost dokladu A1.', 'statutory'],
        'social_jurisdiction_evidence_invalid|social_jurisdiction_evidence_missing|social_jurisdiction_evidence_unverified|social_security_jurisdiction_unverified' => ['Chybí platná a ověřená příslušnost k sociálnímu pojištění. V zákonné evidenci doplňte stát pojištění, období platnosti a jeho doložení.', 'statutory'],
        'working_pensioner_discount_evidence_invalid|working_pensioner_discount_evidence_missing|working_pensioner_discount_evidence_unverified|working_pensioner_discount_unverified' => ['Sleva pracujícího důchodce není platně doložená. V zákonné evidenci doplňte a ověřte nárok a datum, od kterého zaměstnanec slevu uplatňuje.', 'statutory'],
        'tax_declaration_term_conflict|tax_declaration_conflict' => ['Údaje o daňovém prohlášení si odporují. V zákonné evidenci opravte platnost a podpis prohlášení, aby pro měsíc mzdy platil jediný doložený stav.', 'statutory'],
        'tax_declaration_evidence_missing|tax_declaration_evidence_unverified|tax_declaration_unverified' => ['Chybí ověřený stav daňového prohlášení. V zákonné evidenci zaznamenejte, zda zaměstnanec prohlášení podepsal, a ověřte platnost pro měsíc mzdy.', 'statutory'],
        'tax_residence_evidence_missing|tax_residence_evidence_unverified|tax_residence_unverified' => ['Není doložena daňová rezidence zaměstnance. V zákonné evidenci doplňte stát rezidence a ověřte podklady.', 'statutory'],
        'tax_credit_evidence_invalid|tax_credit_evidence_unverified|disability_credit_conflict' => ['Podklady pro osobní slevu na dani nejsou platné nebo si odporují. V zákonné evidenci opravte druh slevy, dobu nároku a příslušný doklad.', 'statutory'],
        'tax_child_evidence_invalid|tax_child_evidence_unverified|tax_child_order_conflict|tax_child_shared_household_unverified' => ['Daňové zvýhodnění na dítě není jednoznačně doložené. U vyživovaných osob ověřte nárok, společnou domácnost, pořadí dítěte a období uplatnění.', 'dependants'],
        'tax_component_exemption_evidence_missing|income_component_exemption_evidence_unverified' => ['Osvobození mzdového příjmu od daně není doložené. V měsíčních vstupech doplňte podklad osvobození; bez něj příjem nelze považovat za osvobozený.', 'inputs'],
        'other_withholding_eligibility_unverified' => ['Není ověřena účast na nemocenském pojištění z odměny. V podmínkách pracovního vztahu ji potvrďte podle skutečného nároku; ovlivňuje použití srážkové daně.', 'employment'],
        'relationship_tax_classification_conflict' => ['Daňové zařazení pracovního vztahu si odporuje s jeho druhem nebo účastí na pojištění. Na kartě vztahu opravte tyto údaje.', 'employment'],
        'net_pay_result_missing_or_unverified|insurance_or_tax_result_requires_manual_review' => ['Čistou mzdu zatím nelze použít, protože výpočet daně nebo pojistného nebyl dokončen. Nejprve opravte konkrétní kontroly zákonného výpočtu v tomto mzdovém běhu.', 'runs'],
        'claim_register_evidence_incomplete' => ['Evidence pohledávek není úplná. V agendě Exekuce doplňte všechny doručené pohledávky a potvrďte úplnost jejich evidence.', 'enforcement'],
        'dependants_evidence_incomplete' => ['Evidence vyživovaných osob není úplná. Na kartě zaměstnance doplňte osoby započítávané do nezabavitelné částky a ověřte jejich nárok.', 'dependants'],
        'spouse_allowance_evidence_incomplete|spouse_quarter_pension_evidence_unknown' => ['Není doložen nárok na započtení manžela či partnera do nezabavitelné částky. U vyživovaných osob ověřte příslušný důchod a jeho platnost v rozhodném období.', 'dependants'],
        'income_register_evidence_incomplete' => ['Evidence příjmů pro srážky není úplná. V agendě Exekuce doplňte všechny příjmy zaměstnance a potvrďte úplnost podkladů.', 'enforcement'],
        'severance_period_split_required' => ['Odstupné není rozděleno na měsíce, za které náleží. V podkladech příjmů pro exekuci doplňte rozdělení odstupného před výpočtem srážky.', 'enforcement'],
        'kind_requires_manual_review' => ['Druh příjmu vyžaduje ruční posouzení srážek. V agendě Exekuce ověřte, zda a v jaké výši tento příjem podléhá srážkám.', 'enforcement'],
        'multiple_income_payers_require_separate_calculation|multiple_payers_protected_amount_decision_missing|multiple_payers_protected_amount_decision_not_verified' => ['Zaměstnanec má více plátců příjmu a chybí ověřené rozdělení nezabavitelné částky. V agendě Exekuce doplňte rozhodnutí určující částku pro tohoto zaměstnavatele.', 'enforcement'],
        'protected_amount_override_without_multiple_payers|protected_amount_decision_verified_without_multiple_payers' => ['Je zadáno rozdělení nezabavitelné částky, ale není evidováno více plátců příjmu. V agendě Exekuce opravte tento rozpor podle rozhodnutí.', 'enforcement'],
        'delivery_date_missing' => ['Chybí datum doručení pohledávky určující pořadí srážek. V detailu pohledávky doplňte doložené datum doručení.', 'enforcement'],
        'priority_classification_not_verified' => ['Není ověřeno, zda je pohledávka přednostní. V detailu pohledávky potvrďte její zařazení podle doručeného rozhodnutí.', 'enforcement'],
        'legal_title_not_verified' => ['Právní titul pohledávky není ověřen. V detailu pohledávky zkontrolujte a potvrďte podklad, který ukládá provádět srážky.', 'enforcement'],
        'order_or_notice_not_delivered' => ['U pohledávky není doloženo doručení příkazu nebo vyrozumění. V detailu pohledávky zaznamenejte jeho skutečné doručení.', 'enforcement'],
        'order_issue_date_missing' => ['Chybí datum vydání příkazu. Doplňte jej v detailu pohledávky podle doručeného dokumentu.', 'enforcement'],
        'due_monetary_claim_not_verified' => ['Není ověřeno, že jde o splatnou peněžitou pohledávku. V detailu pohledávky ověřte splatnost podle právního titulu.', 'enforcement'],
        'enforcement_order_id_missing' => ['Chybí označení exekučního příkazu. V detailu pohledávky doplňte číslo příkazu nebo spisovou značku.', 'enforcement'],
        'deduction_agreement_not_verified' => ['Dohoda o srážkách není ověřena. V agendě Dohody o srážkách doložte a potvrďte platnou dohodu.', 'agreements'],
        'voluntary_agreement_cannot_be_priority' => ['Dobrovolná dohoda je chybně označena jako přednostní pohledávka. Opravte její pořadí a druh v evidenci srážek.', 'agreements'],
        'maintenance_weight_missing' => ['Chybí částka výživného pro poměrné rozdělení srážky. V detailu pohledávky doplňte běžné výživné podle rozhodnutí.', 'enforcement'],
        'four_enforcement_pension_exception_evidence_unknown' => ['U souběhu nejméně čtyř exekucí není ověřena důchodová výjimka. V agendě Exekuce doložte pobírání příslušného důchodu pro výpočet srážek.', 'enforcement'],
        'insolvency_decision_not_verified' => ['Rozhodnutí o insolvenci není ověřeno. V agendě Insolvence doložte aktuální rozhodnutí a potvrďte použitelný režim srážek.', 'insolvency'],
        'insolvency_recipient_not_verified|insolvency_payment_instruction_missing' => ['Chybí ověřený příjemce nebo platební pokyn insolvenčního správce. V agendě Insolvence doplňte a ověřte komu a kam srážku odvádět.', 'insolvency'],
        'court_determined_insolvency_amount_missing' => ['Chybí soudem určená výše insolvenční srážky. V agendě Insolvence doplňte částku podle platného rozhodnutí.', 'insolvency'],
        'insolvency_alert_cannot_redirect_payment' => ['Insolvence je vedena pouze jako upozornění, které neopravňuje přesměrovat platbu. V agendě Insolvence ověřte rozhodnutí a nastavte odpovídající režim.', 'insolvency'],
        'court_determined_amount_without_insolvency' => ['Je zadána soudem určená insolvenční srážka bez aktivní insolvence. V agendě Insolvence opravte režim nebo chybnou částku.', 'insolvency'],
    ];

    public static function describe(string $issue, ?int $employeeId = null, ?int $employmentId = null): array
    {
        $employeeId = self::reference($issue, 'employee') ?? $employeeId;
        $employmentId = self::reference($issue, 'employment') ?? $employmentId;
        $employeeId = $employeeId !== null && $employeeId > 0 ? $employeeId : null;
        $employmentId = $employmentId !== null && $employmentId > 0 ? $employmentId : null;
        $parts = explode(':', str_replace('-', '_', $issue));
        $message = null;
        $target = 'statutory';
        foreach (self::RULES as $codes => [$text, $destination]) {
            if (array_intersect(explode('|', $codes), $parts) !== []) {
                $message = $text;
                $target = $destination;
                break;
            }
        }
        if ($message === null) {
            $message = 'Výpočet narazil na kontrolu, kterou nelze automaticky vyřešit. Předejte správci aplikace tento mzdový běh a jeho revizi; podklady neměňte odhadem.';
            $target = 'support';
        }
        $claimId = self::reference($issue, 'claim');
        if ($claimId !== null) {
            $message = "Pohledávka č. {$claimId}: " . $message;
        }
        $path = match ($target) {
            'support' => '/admin/support',
            'inputs' => '/payroll/quick-inputs',
            'time' => '/payroll/time',
            'components' => '/payroll/components',
            'risky_savings' => '/payroll/components?tab=risky_savings',
            'runs' => '/payroll/runs',
            'rulesets' => '/payroll/rulesets',
            'enforcement' => '/payroll/enforcement',
            'insolvency' => '/payroll/insolvency',
            'agreements' => '/payroll/deduction-agreements',
            default => '/payroll/people',
        };
        if ($path === '/payroll/people') {
            $query = [];
            if ($employeeId !== null) $query['person'] = $employeeId;
            if ($employmentId !== null) $query['employment'] = $employmentId;
            if ($target !== 'employment') $query['panel'] = $target === 'dependants' ? 'dependants' : 'statutory_evidence';
            if ($target === 'employment') {
                $query['panel'] = 'employment_terms';
                if (in_array('part_time_discount_weekly_working_time_missing', $parts, true)) $query['field'] = 'weekly_hours';
                elseif (in_array('other_withholding_eligibility_unverified', $parts, true)) $query['field'] = 'other_withholding_eligibility';
                elseif (in_array('employer_rate_category_unverified', $parts, true)) $query['field'] = 'social_employer_rate_category';
                elseif (array_intersect(['part_time_discount_unverified', 'part_time_discount_relationship_kind_unsupported', 'part_time_discount_may_select_only_one_relationship_per_person'], $parts) !== []) $query['field'] = 'social_part_time_discount_reason';
            }
            if ($query !== []) $path .= '?' . http_build_query($query);
        } elseif ($target === 'risky_savings') {
            if ($employeeId !== null) $path .= '&person=' . $employeeId;
            if ($employmentId !== null) $path .= '&employment=' . $employmentId;
        } elseif (in_array($target, ['enforcement', 'insolvency', 'agreements'], true) && $employeeId !== null) {
            $path .= '?person=' . $employeeId;
        } elseif (in_array($target, ['inputs', 'time'], true) && $employmentId !== null) {
            $path .= '?employment=' . $employmentId;
        }

        return [
            'issue_code' => $issue,
            'entity_type' => $employeeId !== null ? 'employee' : ($employmentId !== null ? 'employment' : 'run'),
            'entity_id' => $employeeId ?? $employmentId,
            'message' => $message,
            'remediation_path' => $path,
        ];
    }

    public static function reference(string $value, string $kind): ?int
    {
        return preg_match('/(?:^|:)' . preg_quote($kind, '/') . ':([1-9][0-9]*)(?:$|:)/', $value, $match) === 1
            ? (int) $match[1] : null;
    }
}
