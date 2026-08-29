<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\Payroll\PayrollEmploymentAgendaSummaryRepository;
use MyInvoice\Service\Payroll\Settings\PayrollEmployerPolicyService;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontraktová brána mezi mzdovou doménou a klientem.
 *
 * ## Co hlídá
 *
 * Mzdový číselník žije na třech místech současně: v PHP (enum nebo ENUM sloupec
 * v migraci), v TypeScriptu (`web/src/api/payroll*.ts` — ručně přepsaný union)
 * a ve slovníku (`web/src/i18n/{cs,en}.json`). Žádné z těch tří míst se
 * neodvozuje z ostatních, takže se rozejít MUSÍ — je to jen otázka času.
 * Projev je pokaždé tichý: klient buď hodnotu nezná (a striktní typ lže),
 * nebo pro ni nemá popisek (a uživatel vidí syrový klíč).
 *
 * Doložené případy, ze kterých test vznikl:
 *
 *  - union `PayrollRunCommand` neznal `post`, `prepare_payments` ani `mark_paid`,
 *    přestože je PHP enum měl;
 *  - filtr docházky porovnával `employment.status` s hodnotou `cancelled`, kterou
 *    migrace 1195 z enumu odstranila (hlídá {@see PayrollEmploymentStatusLiteralTest});
 *  - popisky vztahů se vypisovaly jako holý kód, protože komponenta hledala
 *    překlad pod klíčem, který ve slovníku nebyl.
 *
 * ## Proč PHP a ne vitest
 *
 * 1. CI spouští `--testsuite Architecture` jako samostatný krok. Frontendový job
 *    pouští jen `vue-tsc`, `pnpm test:pwa` a build — plný vitest v CI NEBĚŽÍ,
 *    takže kontrakt psaný ve vitestu by bránu nikdy netvořil.
 * 2. Opačné zapojení (vygenerovat seznam z PHP a porovnat ve vitestu) zavádí
 *    generovaný artefakt, jehož zastarání je PŘESNĚ ta chyba, kterou máme chytat:
 *    kdo zapomene regenerovat, dostane zelenou.
 * 3. Tady se každá strana čte z vlastního zdroje pravdy a žádná z kopie:
 *    PHP enum přes `::cases()` (reflexe, ne parsování), doména sloupce z textu
 *    migrace (posouvá se se schématem), `.ts` jako text, `.json` jako JSON.
 *
 * ## Proč to nejde obejít zapomenutím
 *
 * Registr není jen seznam dvojic. `testEveryPayrollApiUnionIsPairedOrDocumented`
 * projde VŠECHNY uniony řetězcových literálů v `web/src/api/payroll*.ts` a
 * `testEveryPayrollEnumIsPairedOrDocumented` všechny řetězcové enumy pod
 * `src/Service/Payroll`. Kdo přidá nový, musí ho buď spárovat, nebo mu napsat
 * důvod, proč párovat nejde. Mlčky přidat nový číselník nelze.
 *
 * Rozdíl, který je v pořádku (klientský stav navíc), se řeší DOLOŽENOU výjimkou
 * v {@see self::CLIENT_ONLY_UNIONS} — ne uvolněním pravidla na „stačí podmnožina".
 */
#[Group('architecture')]
final class PayrollEnumContractTest extends TestCase
{
    /**
     * Union v klientovi → doména na backendu. Porovnává se na PŘESNOU shodu
     * množin: méně hodnot znamená, že klient odpověď serveru neuzná, více
     * znamená, že klient nabízí něco, co server odmítne.
     *
     * Zápis domény:
     *  - `enum:FQCN`        — hodnoty řetězcového enumu (`::cases()`)
     *  - `enum-names:FQCN`  — JMÉNA případů (číselný enum, jehož jména jdou po drátě)
     *  - `const:FQCN::NAME` — pole v konstantě třídy
     *  - `consts:FQCN`      — všechny veřejné řetězcové konstanty třídy
     *  - `policy:klíč`      — položka katalogu {@see PayrollEmployerPolicyService}
     *  - `db:tabulka.sloupec` — finální podoba ENUM sloupce podle migrací
     *
     * @var array<string,string>
     */
    private const UNION_DOMAIN = [
        // Zákonné příplatky § 114 až § 118 ZP
        'payroll.ts::PayrollSurchargeKind'
            => 'enum:MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind',
        'payroll.ts::PayrollSurchargeCompensationMode'
            => 'enum:MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode',
        // Mzdový běh
        'payroll.ts::PayrollPeriodExportScope'
            => 'enum:MyInvoice\Service\Payroll\Export\PayrollPeriodExportScope',
        'payroll.ts::PayrollPeriodExportJobStatus'
            => 'const:MyInvoice\Repository\Payroll\PayrollPeriodExportJobRepository::STATUSES',
        'payroll.ts::PayrollDeadlinePhase'
            => 'const:MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService::PHASES',
        'payroll.ts::PayrollRegistrationChangeDuty'
            => 'const:MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService::DUTY_KINDS',
        'payroll.ts::PayrollDeadlineSource'
            => 'const:MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService::SOURCES',
        'payrollRulesets.ts::PayrollRulesetOutlookSeverity'
            => 'const:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearOutlook::SEVERITIES',
        'payroll.ts::PayrollForeignPermitKind'
            => 'db:payroll_person_foreign_permits.permit_kind',
        'payroll.ts::PayrollForeignPermitStatus'
            => 'const:MyInvoice\Repository\Payroll\PayrollForeignPermitRepository::STATUSES',
        'payroll.ts::PayrollYearCloseStatus'
            => 'db:payroll_year_closures.status',
        'payroll.ts::PayrollYearCloseBlockerCode'
            => 'const:MyInvoice\Service\Payroll\PayrollYearCloseService::BLOCKER_CODES',
        'payroll.ts::PayrollBenefitExemptionBasket'
            => 'enum:MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket',
        // Čím je nezdanění složky podložené. Klient hodnotu vybírá ve formuláři
        // složky a `not_subject_to_tax` musí umět odlišit od osvobození — na
        // mzdovém listu totiž znamená pravý opak: plnění mimo předmět daně se
        // mezi osvobozené částky nevykazuje.
        'payroll.ts::PayrollExemptionBasis'
            => 'enum:MyInvoice\Service\Payroll\Component\PayrollExemptionBasis',
        // Stav řádku v přehledu čerpání košů. `incomplete` a `limit_unavailable`
        // jsou přiznání chybějícího podkladu — klient, který je nezná, by je
        // vykreslil jako prázdný stav, tedy jako „nic se neděje".
        'payrollBenefitBaskets.ts::BenefitBasketStatus'
            => 'const:MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage::STATUSES',
        // Čeho se limit řádku týká. `per_shift` je jediný důvod, proč měsíční
        // součet NELZE poměřit proti limitu — klient, který tu hodnotu nezná,
        // by prázdný limit vykreslil jako „v pořádku".
        'payrollBenefitBaskets.ts::BenefitBasketLimitBasis'
            => 'const:MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage::LIMIT_BASES',
        // Nálezy porovnání dvou evidencí náhradního volna. Klient, který
        // některý nezná, by rozpor vykreslil jako prázdno — tedy jako „sedí to".
        'payroll.ts::PayrollCompensatoryTimeOffFinding'
            => 'const:MyInvoice\Service\Payroll\Time\Overtime\CompensatoryTimeOffReconciliation::FINDINGS',
        // Druh nabídky pro serverové hledání v pickeru párování plateb.
        'payrollPayments.ts::PayrollPaymentOptionKind'
            => 'const:MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService::PICKER_KINDS',
        'payroll.ts::PayrollRunStatus'      => 'enum:MyInvoice\Service\Payroll\Run\PayrollRunStatus',
        'payroll.ts::PayrollRunCommand'     => 'enum:MyInvoice\Service\Payroll\Run\PayrollRunCommand',
        'payroll.ts::PayrollRunOutcomeCode' => 'consts:MyInvoice\Service\Payroll\Run\PayrollRunCommandOutcome',
        'payroll.ts::PayrollRiskySavingsRiskFactor'
            => 'enum:MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRiskFactor',
        'payroll.ts::PayrollEmployeeCardStatusFilter'
            => 'const:MyInvoice\Repository\Payroll\PayrollQuickInputRepository::CARD_STATUS_FILTERS',
        // Proč rozklad pojistného není k dispozici. Každý důvod má na obrazovce
        // vlastní větu — nová hodnota bez věty by se projevila prázdnou kartou.
        'payrollInsurance.ts::PayrollInsuranceUnavailableReason' =>
            'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::UNAVAILABLE_REASONS',
        // Odkud pochází sazba. `reconstructed` je DOLOŽENÝ dopočet, ne uložený
        // záznam — klient, který tu hodnotu nezná, by ho vydal za uložený.
        'payrollInsurance.ts::PayrollInsuranceRateSource' =>
            'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::RATE_SOURCES',
        // Rozdělení pojistného zaměstnavatele na osobu není zákonná částka.
        // Metoda i důvod, proč rozdělit nejde, musí mít na obrazovce vlastní větu.
        'payrollInsurance.ts::PayrollEmployerAllocationMethod' =>
            'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::EMPLOYER_ALLOCATION_METHODS',
        'payrollInsurance.ts::PayrollEmployerAllocationBlocker' =>
            'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::EMPLOYER_ALLOCATION_BLOCKERS',

        // Pracovní vztah a jeho podmínky
        'payroll.ts::PayrollEmploymentStatus'        => 'db:payroll_employments.status',
        'payroll.ts::PayrollRelationType'
            => 'enum:MyInvoice\Service\Payroll\Employment\PayrollRelationType',
        'payroll.ts::PayrollMealEntitlementBasis'    => 'db:payroll_employments.meal_entitlement_basis',
        'payroll.ts::PayrollTaxRegime'               => 'db:payroll_employment_terms.tax_regime',
        // Doména sloupce je ÚZKO tři hodnoty; PHP enum OtherWithholdingEligibility
        // má navíc `automatic`, protože to není volba uživatele, ale zařazení,
        // které si výpočet odvodí z druhu vztahu. Klient tu čtvrtou hodnotu
        // nesmí nabízet — proto se páruje sloupec, ne enum.
        'payroll.ts::PayrollOtherWithholdingEligibility'
            => 'db:payroll_employment_terms.other_withholding_eligibility',
        // Stejný důvod jako výše: PHP enum SocialEmployerRateCategory má navíc
        // `unverified`, což není čtvrtá zákonná kategorie § 5a odst. 1, ale stav
        // evidence — vzniká až ve vstupu výpočtu, když k zařazení chybí podklad.
        // Nabídnout ho v klientovi by znamenalo nabídnout „nevím" jako volbu.
        'payroll.ts::PayrollSocialEmployerRateCategory'
            => 'db:payroll_employment_terms.social_employer_rate_category',
        'payroll.ts::PayrollSocialPartTimeDiscountReason'
            => 'db:payroll_employment_terms.social_part_time_discount_reason',
        'payroll.ts::PayrollInsuranceParticipation'  => 'db:payroll_employment_terms.social_insurance_participation',
        'payroll.ts::PayrollChecklistStatus'         => 'db:payroll_employment_checklist_items.status',
        'payroll.ts::PayrollVerifiedTriState'        => 'db:payroll_employment_terms.jmhz_apz_contribution_status',
        'payroll.ts::PayrollRegistrationAgenda'      => 'db:payroll_registration_identity_snapshots.agenda_code',

        // Osoba
        'payroll.ts::PayrollPersonAddressType'    => 'db:payroll_person_addresses.address_type',
        'payroll.ts::PayrollPersonContactType'    => 'db:payroll_person_contacts.contact_type',
        'payroll.ts::PayrollPersonIdentifierType' => 'db:payroll_person_identifiers.identifier_type',
        'payroll.ts::PayrollPersonSex'            => 'db:payroll_person_identity_history.sex',
        'payroll.ts::PayrollPayoutMethod'         => 'db:payroll_employee_profiles.payout_method',
        'payroll.ts::PayrollSecureDeliveryChannel' => 'db:payroll_employee_profiles.secure_delivery_channel',
        'payroll.ts::PayrollDependantRelation'    => 'db:payroll_dependants.relation',
        'payroll.ts::PayrollStatutoryEvidenceSection'
            => 'const:MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository::EDITABLE_SECTIONS',
        'payroll.ts::PayrollPersonAccountVerificationSource'
            => 'enum:MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationSource',

        // Složky mzdy a vstupy
        'payroll.ts::PayrollComponentKind'         => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentKind',
        'payroll.ts::PayrollComponentFrequency'    => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentFrequency',
        'payroll.ts::PayrollComponentValueKind'    => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentValueKind',
        'payroll.ts::PayrollComponentTaxTreatment' => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentTaxTreatment',
        'payroll.ts::PayrollComponentInclusion'    => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentInclusion',
        'payroll.ts::PayrollInputStatus'           => 'db:payroll_inputs.status',
        'payroll.ts::PayrollInputSourceKind'       => 'db:payroll_inputs.source_kind',
        'payroll.ts::PayrollRecurringCalculationKind' => 'db:payroll_recurring_components.calculation_kind',
        'payroll.ts::PayrollRecurringAllocationRule'  => 'db:payroll_recurring_components.allocation_rule',
        'payroll.ts::PayrollTimeCategory'          => 'db:payroll_time_entries.category',
        // Zákazy a vyrovnávací období u přesčasu (§ 93 odst. 4, § 240 odst. 3).
        // Sloupce jsou VARCHAR s CHECK, ne ENUM, protože doména je právní výčet
        // vázaný na ustanovení — páruje se proto konstanta domény, ne sloupec.
        'payroll.ts::PayrollOvertimeProtectionKind'
            => 'const:MyInvoice\Service\Payroll\Time\Overtime\OvertimeProtectionWindow::KINDS',
        'payroll.ts::PayrollOvertimeAveragingBasis'
            => 'const:MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimits::BASES',

        // Výplata, instituce, dokumenty
        'payroll.ts::PayrollPayoutDestinationKind'    => 'db:payroll_payout_rules.destination_kind',
        'payroll.ts::PayrollPayoutAllocationKind'     => 'db:payroll_payout_rules.allocation_kind',
        'payroll.ts::PayrollInstitutionType'          => 'enum:MyInvoice\Service\Payroll\InstitutionAccountType',
        'payroll.ts::PayrollInstitutionAccountSource' => 'enum:MyInvoice\Service\Payroll\InstitutionAccountSourceKind',
        'payroll.ts::PayrollDocumentKind'             => 'enum:MyInvoice\Service\Payroll\Document\PayrollDocumentKind',
        'payroll.ts::PayrollDocumentBatchStatus'      => 'db:payroll_document_batches.status',
        'payroll.ts::PayrollDocumentBatchItemStatus'  => 'db:payroll_document_batch_items.status',
        // Způsob skončení vztahu na odděleném potvrzení podle § 313 odst. 2
        // zákoníku práce. Doménu drží doklad, protože právě on ji tiskne.
        'payroll.ts::PayrollTerminationReasonKind'
            => 'const:MyInvoice\Service\Payroll\Document\AverageEarningsCertificateDocumentData::TERMINATION_REASONS',

        // Roční zúčtování (§ 38ch ZDP). Všech šest hodnot chodí po drátě —
        // stav evidence i důvod odmítnutí musí obrazovka umět vypsat větou.
        'payroll.ts::PayrollAnnualSettlementRequestStatus'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequestStatus',
        'payroll.ts::PayrollAnnualSettlementPriorEmployers'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPriorEmployers',
        'payroll.ts::PayrollAnnualSettlementFilingObligation'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementFilingObligation',
        'payroll.ts::PayrollAnnualSettlementAnnualClaims'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementAnnualClaims',
        'payroll.ts::PayrollAnnualSettlementCaregiverStatus'
            => 'db:payroll_annual_settlement_requests.other_household_caregiver_status',
        'payroll.ts::PayrollAnnualSettlementOutcome'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome',
        'payroll.ts::PayrollAnnualSettlementBlocker'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker',
        'payroll.ts::PayrollDimensionType'            => 'db:payroll_dimensions.dimension_type',
        'payroll.ts::PayrollModuleStatus'             => 'db:payroll_module_state.status',

        // Retence. Katalog lhůt žije v kódu, ne v tabulce (lhůta je tvrzení
        // o právu a musí projít revizí v diffu), takže se páruje s jeho
        // konstantami. Původ lhůty je z celé sady nejcitlivější: rozejít se
        // o hodnotu `house_policy` by znamenalo, že se dodaná politika na
        // obrazovce ukáže jako paragraf.
        'payrollRetention.ts::RetentionOrigin'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::ORIGINS',
        'payrollRetention.ts::RetentionSourceStatus'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::SOURCE_STATUSES',
        'payrollRetention.ts::RetentionBasis'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::BASES',
        'payrollRetention.ts::PayrollRetentionBlock'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment::BLOCKS',
        // Zadržení a výmaz uložené JSOU, takže se párují se sloupcem. Důvod
        // zadržení sdílí tabulku s účetní stranou (migrace 1396 ho rozšířila
        // o exekuci a insolvenci) — kdyby se klient rozešel, mzdová obrazovka
        // by nabídla důvod, který sloupec nepřijme.
        'payrollRetention.ts::PayrollRetentionHoldReason' => 'db:retention_holds.reason',
        'payrollRetention.ts::PayrollErasureStatus'       => 'db:payroll_erasure_proposals.status',
        'payrollRetention.ts::PayrollErasureOutcome'      => 'db:payroll_erasure_proposal_items.outcome',

        // Podání
        'payroll.ts::PayrollStatutoryAgendaCapability'
            => 'const:MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog::CAPABILITIES',
        'payroll.ts::PayrollEldpAuthorityStatus'
            => 'db:payroll_eldp_manual_completions.authority_status',
        'payroll.ts::PayrollSubmissionObligationStatus'     => 'db:payroll_obligations.status',
        'payroll.ts::PayrollSubmissionInboxStatus'          => 'db:payroll_submission_inbox_items.status',
        'payroll.ts::PayrollSubmissionInboxProblemKind'     => 'db:payroll_submission_inbox_items.problem_kind',
        'payroll.ts::PayrollSubmissionInboxEscalationLevel' => 'db:payroll_submission_inbox_items.escalation_level',
        'payroll.ts::PayrollJmhzTransportStatus'      => 'db:payroll_submission_transport_attempts.status',
        'payroll.ts::PayrollJmhzImportedProtocolKind' => 'db:payroll_imported_jmhz_protocols.protocol_kind',
        'payroll.ts::PayrollJmhzControlOutcome'
            => 'enum:MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome',
        'payroll.ts::PayrollJmhzProtocolStatus'
            => 'enum-names:MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus',
        // Skupina agend přehledu podání: klasifikaci dělá SQL v repozitáři,
        // protože se podle ní filtruje stránka i souhrny. Frontend ji jen čte.
        'payroll.ts::PayrollSubmissionAgendaGroup'
            => 'const:MyInvoice\Repository\Payroll\PayrollSubmissionRepository::AGENDA_GROUPS',
        // Výběr stavů inboxu: taky filtr, který drží SERVER, aby `total`
        // popisoval právě to, co stránka ukáže.
        'payroll.ts::PayrollSubmissionInboxStatusFilter'
            => 'const:MyInvoice\Repository\Payroll\PayrollSubmissionInboxRepository::STATUS_FILTERS',
        // Zdravotní pojišťovny. Druh oznamovací povinnosti odchází v odpovědi
        // od chvíle, kdy vznikl přehled za období — obrazovka podle něj filtruje
        // i popisuje řádek, takže nová hodnota bez překladu by se projevila
        // holým klíčem v tabulce.
        'payrollHealthNotifications.ts::HealthDutyKind'
            => 'enum:MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyKind',
        'payrollHealthNotifications.ts::HealthIsdsAttachmentFormat'
            => 'enum:MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerIsdsAttachmentFormat',
        // Důvod, proč aplikace nesmí odeslat sama. Každý má na obrazovce vlastní
        // větu; nový kód bez věty by se vykreslil jako prázdné místo přesně tam,
        // kde má stát přiznání, co modul neumí.
        'payrollHealthNotifications.ts::HealthDispatchReasonCode'
            => 'consts:MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog',
        // Jak je pravidlo doložené. Rozdíl mezi textem zákona a publikací
        // pojišťovny se v tabulce ukazuje, takže se nesmí rozejít.
        'payrollHealthNotifications.ts::HealthSourceStatus'
            => 'consts:MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyRule',
        // Záměr uplatňovat slevu na pojistném (OZUSPOJ). Stav rozhoduje o tom,
        // jestli se sleva vůbec uplatní, a obrazovka podle něj nabízí akce —
        // nová hodnota bez překladu by nechala řádek bez toho, co s ním dělat.
        'payrollDiscountIntents.ts::PayrollDiscountIntentStatus'
            => 'enum:MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentStatus',
        // Druh oznámení (`zamer/typPodani`). Hodnotou enumu je slovo, ne číslo
        // z XSD — `2` v požadavku by nikdo nepřečetl jako „ukončit záměr".
        'payrollDiscountIntents.ts::PayrollDiscountIntentSubmissionKind'
            => 'enum:MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind',

        // Politiky zaměstnavatele
        'payroll.ts::PayrollBusinessDayRule'     => 'policy:payday_business_day_rule',
        'payroll.ts::PayrollBalanceRoundingMode' => 'policy:balance_rounding_mode',
        'payroll.ts::PayrollOptionalPolicyState' => 'policy:home_office_policy',
        'payroll.ts::PayrollDeliveryChannel'     => 'policy:delivery_channel',
        'payroll.ts::PayrollPolicySourceKind'    => 'policy:source_kind',

        // Ostatní moduly
        'payrollAbsences.ts::AbsenceType' => 'db:payroll_absences.absence_type',

        'payrollDeductions.ts::DeductionAgreementStatus'
            => 'enum:MyInvoice\Service\Payroll\Net\DeductionAgreementStatus',
        'payrollDeductions.ts::DeductionAgreementCommand'
            => 'enum:MyInvoice\Service\Payroll\Net\DeductionAgreementCommand',
        'payrollDeductions.ts::DeductionAgreementKind' => 'db:payroll_deduction_agreements.deduction_kind',

        'payrollEnforcement.ts::EnforcementCaseStatus'
            => 'enum:MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus',
        'payrollEnforcement.ts::EnforcementCaseCommand'
            => 'enum:MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand',
        'payrollEnforcement.ts::EnforcementClaimCategory'
            => 'enum:MyInvoice\Service\Payroll\Garnishment\ClaimCategory',
        'payrollEnforcement.ts::EnforcementCaseKind' => 'db:payroll_enforcement_cases.case_kind',
        // Doložení důchodu, které od 1. 1. 2025 podmiňuje čtvrtinu na
        // manžela/partnera (nař. vlády č. 441/2024 Sb.). Hodnota chodí po drátě
        // OBĚMA směry: klient ji u manžela posílá při zakládání vyživované osoby
        // a čte ji zpátky u existujících záznamů. `unknown` se nenabízí k výběru,
        // ale klient ho znát MUSÍ — starší záznamy ho nesou a je to jediný stav,
        // ze kterého vzniká blokátor `spouse_quarter_pension_evidence_unknown`.
        'payrollEnforcement.ts::SpousePensionEvidence'
            => 'enum:MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence',
        // Držitel a druh důchodu enum nemají — doménu drží sloupec, který je
        // zavedl (migrace 1612), a validace v repozitáři se páruje s ním.
        'payrollEnforcement.ts::SpousePensionHolder'
            => 'db:payroll_enforcement_dependants.quarter_pension_holder',
        'payrollEnforcement.ts::SpousePensionKind'
            => 'db:payroll_enforcement_dependants.quarter_pension_kind',

        'payrollRulesets.ts::PayrollRulesetLifecycle'
            => 'enum:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle',
        'payrollRulesets.ts::PayrollRulesetCapability'
            => 'enum:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability',
        'payrollRulesets.ts::PayrollRulesetCommand'
            => 'const:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetAdminService::COMMANDS',
        'payrollRulesets.ts::PayrollRulesetOrigin'
            => 'enum:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin',
        'payroll.ts::PayrollPeopleFilter'
            => 'const:MyInvoice\Repository\Payroll\PayrollPeopleRepository::LIST_FILTERS',
        // Navazující agendy karty zaměstnance. Klíč navíc na klientovi = řádek
        // souhrnu bez popisku, klíč navíc na serveru = agenda, na kterou karta
        // neumí odkázat.
        'payroll.ts::PayrollAgendaKey'
            => 'const:MyInvoice\Repository\Payroll\PayrollEmploymentAgendaSummaryRepository::AGENDA_KEYS',
        // Údaje, které § 38ch odst. 3 žádá po potvrzení od jiného plátce. Klíč
        // navíc na klientovi = popisek pole, které se nikdy nevypíše; klíč navíc
        // na serveru = chybějící údaj, ke kterému účetní neuvidí větu, PROČ se
        // zúčtování neprovedlo.
        'payroll.ts::PayrollAnnualSettlementCertificateField'
            => 'const:MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate::REQUIRED_STATUTORY_FIELDS',

        'payrollTravel.ts::TravelTransportMode' => 'enum:MyInvoice\Service\Payroll\Travel\TravelTransportMode',
        'payrollTravel.ts::TravelVehicleKind'   => 'enum:MyInvoice\Service\Payroll\Travel\TravelVehicleKind',
        'payrollTravel.ts::TravelFuelKind'      => 'enum:MyInvoice\Service\Payroll\Travel\TravelFuelKind',
        'payrollTravel.ts::TravelItemKind'      => 'enum:MyInvoice\Service\Payroll\Travel\TravelExpenseItemKind',
        'payrollTravel.ts::TravelTripStatus'    => 'db:payroll_business_trips.status',
    ];

    /**
     * Unionu, který se s ničím na backendu párovat NEMÁ, se musí napsat proč.
     *
     * Zdaleka ne každý řetězcový union je číselník domény: část z nich je stav,
     * který backend nikde neskladuje ani nedeklaruje, ale POČÍTÁ ho v místě
     * odpovědi. Takový union nemá s čím porovnávat — jediný zdroj pravdy je ta
     * metoda a nic jiného. Kdyby pro něj někdo enum založil, patří do registru výš.
     *
     * @var array<string,string>
     */
    private const CLIENT_ONLY_UNIONS = [
        'payroll.ts::PayrollQuickSurchargeKind' =>
            'Vědomá PODMNOŽINA PayrollSurchargeKind: druhy, které jde zadat ručně '
            . 'v rychlém měsíčním vstupu. Přesčas (§ 114) v ní chybí schválně — má '
            . 'tam vlastní pole s rozpadem na dosaženou mzdu a příplatek, takže druhé '
            . 'pole na týž nárok by byl další způsob, jak ho vyplatit dvakrát. '
            . 'Zdrojem pravdy zůstává PayrollSurchargeKind::quickManualEntry(); shodu '
            . 'hlídá testQuickManualSurchargeKindsMatchTheClientUnion().',
        'payroll.ts::PayrollRunHistoryTotalKey' =>
            'Bezpečný read model historie zpřístupňuje jen tři výslovně vybrané součty '
            . 'z JSON výsledku; nejde o uložený stav ani samostatný backendový číselník.',
        'payroll.ts::PayrollPersonProfileStatus' =>
            'Sloupec `payroll_employee_profiles.profile_status` zná legacy/setup/ready; '
            . '`missing` je čistě klientský stav „profil ještě nevznikl" a v DB být nemůže.',
        'payroll.ts::PayrollPersonSetupGap' =>
            'Co osobě chybí, aby na ni šlo spustit mzdy. Klíče skládá '
            . 'PayrollPeopleRepository::setupGapExpressions() z existenčních dotazů '
            . 'nad čtyřmi tabulkami; uložená hodnota to není a sloupec pro ni neexistuje.',
        'payroll.ts::PayrollComponentJmhzMappingStatus' =>
            'Odvozený stav vazby složky na JMHZ — klient ho skládá z `jmhz_treatment` '
            . 'a existence mapování, backend takovou hodnotu neposílá.',
        'payroll.ts::PayrollSupportStatus' =>
            'Obecný stav podpory, který vrací víc nezávislých služeb; přivázat ho '
            . 'k `support_status` jedné tabulky by test rozbila změna kterékoli jiné.',
        'payroll.ts::PayrollSubmissionDeadlinePhase' =>
            'Fázi lhůty počítá PayrollDeadlineAssessmentService z termínu a dneška; '
            . 'není to uložená hodnota.',
        'payroll.ts::PayrollDependantBlocker' =>
            'Kódy překážek nároku skládá PayrollDependantRepository v místě dotazu.',
        'payroll.ts::PayrollDependantClaimReason' =>
            'Důvod nároku je volný číselník, sloupec je textový — enum pro něj neexistuje.',
        'payroll.ts::PayrollRegzelEnvironment' =>
            'Prostředí podání (test/produkce); backend ho drží po sloupcích několika '
            . 'tabulek, sám o sobě to číselník domény není.',
        'payroll.ts::PayrollSigningEnvironment' => 'Totéž co PayrollRegzelEnvironment, pro podepisování.',
        'payroll.ts::PayrollJmhzTransportEnvironment' => 'Totéž co PayrollRegzelEnvironment, pro cJMHZ.',
        'payroll.ts::PayrollRegistrationEventInteraction' =>
            'Editor následných událostí nabízí jen A2 až A8; backendový katalog '
            . 'PayrollRegistrationInteraction::SUPPORTED navíc obsahuje vstupní P1 a A1, '
            . 'které vznikají jinými průvodci a v tomto unionu být nesmějí.',
        'payrollPayments.ts::PayrollPaymentLiabilityState' =>
            'Stav závazku se neukládá — PayrollPaymentQueryService ho dopočítává '
            . 'z pokrytí dávkami a spárováním.',
        'payrollPosting.ts::PayrollPostingReconciliationCategoryKey' =>
            'Kategorie rekonciliace jsou struktura sestavy v PayrollPostingReconciliationService, '
            . 'ne uložený číselník.',
        'payrollPosting.ts::PayrollPostingReconciliationCategoryStatus' =>
            'Výsledek porovnání kategorie, počítá se při sestavení sestavy.',
        'payrollRulesets.ts::PayrollRuleValueType' =>
            'Typ hodnoty parametru nese JSON obsahu rulesetu, ne sloupec ani enum.',
        'payrollRulesets.ts::PayrollRulesetDomainStatus' =>
            'Stav domény dopočítává PayrollRulesetAdminService z pokrytí a lifecyclu.',
        'payroll.ts::PayrollAnnualSettlementListState' =>
            'Pojmenované zúžení přehledu ročního zúčtování (vše / požádali a nemají '
            . 'výsledek / bez zúčtování / se zúčtováním). Skládá ho '
            . 'PayrollAnnualSettlementRepository::LIST_STATES z existence žádosti '
            . 'a výsledku — uložená hodnota to není a sloupec pro ni neexistuje.',
    ];

    /**
     * Prefix ve slovníku → doména, kterou musí popsat CELOU.
     *
     * Klíč se v komponentách skládá dynamicky (`t(\`payroll.runs.status.${status}\`)`),
     * takže statická i18n brána (`web/scripts/check-i18n.mjs`) na něj nedosáhne —
     * vidí jen proměnnou. Chybějící překlad se pak neprojeví chybou, ale tím, že
     * uživatel uvidí místo popisku syrový kód.
     *
     * Doména se schválně bere z BACKENDU, ne z TS unionu: kdyby se chytala na
     * union, zdědila by jeho zastarání a rozejití by zamlčela dvakrát.
     *
     * @var array<string,string>
     */
    private const I18N_DOMAIN = [
        'payroll.runs.status'   => 'enum:MyInvoice\Service\Payroll\Run\PayrollRunStatus',
        'payroll.runs.commands' => 'enum:MyInvoice\Service\Payroll\Run\PayrollRunCommand',
        'payroll.runs.outcome'  => 'consts:MyInvoice\Service\Payroll\Run\PayrollRunCommandOutcome',

        'payroll.people.employment_status'    => 'db:payroll_employments.status',
        'payroll.people.relations'            => 'db:payroll_employments.relation_type',
        'payroll.people.tax_regime'           => 'db:payroll_employment_terms.tax_regime',
        'payroll.people.other_withholding_eligibility'
            => 'db:payroll_employment_terms.other_withholding_eligibility',
        'payroll.people.insurance_mode'       => 'db:payroll_employment_terms.social_insurance_participation',
        'payroll.people.checklist_status'     => 'db:payroll_employment_checklist_items.status',
        'payroll.people.event'                => 'db:payroll_employment_events.event_type',
        'payroll.people.jmhz_evidence.state'  => 'db:payroll_employment_terms.jmhz_apz_contribution_status',
        'payroll.people.registration.agenda'  => 'db:payroll_registration_identity_snapshots.agenda_code',

        'payroll.components.kind'         => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentKind',
        // Koš se v šabloně skládá dynamicky
        // (`t(\`payroll.components.exemption_basket.${basket}\`)`), takže bez
        // věty by účetní u benefitu viděla `non_cash_leisure` místo paragrafu.
        'payroll.time.overtime.compensatory_check'
            => 'const:MyInvoice\Service\Payroll\Time\Overtime\CompensatoryTimeOffReconciliation::FINDINGS',

        'payroll.components.exemption_basket'
            => 'enum:MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket',
        'payroll.components.frequency'    => 'enum:MyInvoice\Service\Payroll\Component\PayrollComponentFrequency',
        'payroll.components.source'       => 'db:payroll_inputs.source_kind',
        'payroll.components.input_status' => 'db:payroll_inputs.status',
        'payroll.components.calculation'  => 'db:payroll_recurring_components.calculation_kind',

        // Přehled čerpání košů skládá oba klíče dynamicky. Bez věty by se místo
        // stavu vypsalo `limit_unavailable` — právě tam, kde má být řečeno, že
        // se limit netvrdí, ne že je nula.
        'payroll.benefit_baskets.basket'
            => 'enum:MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket',
        'payroll.benefit_baskets.status'
            => 'const:MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage::STATUSES',
        // Věta o tom, za jaké období limit platí. Bez ní by u příspěvku na
        // stravování zůstal prázdný sloupec limitu bez vysvětlení — a měsíční
        // součet by se četl jako součet proti měsíčnímu limitu, který neexistuje.
        'payroll.benefit_baskets.limit_basis'
            => 'const:MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage::LIMIT_BASES',

        'payroll.documents.kind' => 'enum:MyInvoice\Service\Payroll\Document\PayrollDocumentKind',

        'payroll.submissions.statutory.capability'
            => 'const:MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog::CAPABILITIES',

        // Rozklad pojistného skládá klíče dynamicky (`t(\`…allocation_blocker.${reason}\`)`).
        // Chybějící věta by u rozdělení, které nevzniklo, vypsala syrový kód —
        // právě tam, kde má být řečeno, PROČ osobní podíl nedostal.
        'payroll.runs.insurance.allocation_method'
            => 'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::EMPLOYER_ALLOCATION_METHODS',
        'payroll.runs.insurance.allocation_blocker'
            => 'const:MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService::EMPLOYER_ALLOCATION_BLOCKERS',

        // Retence skládá klíče dynamicky (`t(\`payroll.retention.origin.${origin}\`)`).
        // Chybějící popisek by na obrazovce vypsal `house_policy` — přesně
        // u sloupce, který má odlišit zákon od rozhodnutí aplikace.
        'payroll.retention.origin'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::ORIGINS',
        'payroll.retention.origin_hint'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::ORIGINS',
        'payroll.retention.source_status'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::SOURCE_STATUSES',
        'payroll.retention.basis'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog::BASES',
        'payroll.retention.block'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment::BLOCKS',
        'payroll.retention.block_hint'
            => 'const:MyInvoice\Service\Payroll\Retention\PayrollRetentionAssessment::BLOCKS',

        // Roční zúčtování skládá klíče dynamicky z kódu překážky i stavu
        // evidence, takže statická i18n brána na ně nedosáhne. Chybějící věta
        // by se projevila tím, že uživateli místo důvodu svítí `not_requested`.
        'payroll.annual_settlement.blocker'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker',
        'payroll.annual_settlement.outcome'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome',
        'payroll.annual_settlement.request_status_options'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequestStatus',
        'payroll.annual_settlement.prior_employers_options'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPriorEmployers',
        'payroll.annual_settlement.filing_obligation_options'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementFilingObligation',
        'payroll.annual_settlement.annual_claims_options'
            => 'enum:MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementAnnualClaims',
        'payroll.annual_settlement.other_caregiver_options'
            => 'db:payroll_annual_settlement_requests.other_household_caregiver_status',

        'payroll.deductions.status'      => 'enum:MyInvoice\Service\Payroll\Net\DeductionAgreementStatus',
        'payroll.deductions.commands'    => 'enum:MyInvoice\Service\Payroll\Net\DeductionAgreementCommand',
        'payroll.deductions.kinds'       => 'db:payroll_deduction_agreements.deduction_kind',
        'payroll.deductions.change_kind' => 'db:payroll_deduction_agreement_versions.change_kind',
        'payroll.deductions.ledger_kind' => 'db:payroll_deduction_ledger.event_kind',

        'payroll.enforcement.status'         => 'enum:MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus',
        'payroll.enforcement.commands'       => 'enum:MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand',
        'payroll.enforcement.commands.confirm' => 'enum:MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand',
        'payroll.enforcement.categories'     => 'enum:MyInvoice\Service\Payroll\Garnishment\ClaimCategory',
        'payroll.enforcement.kinds'          => 'db:payroll_enforcement_cases.case_kind',
        'payroll.enforcement.ledger_kind'    => 'db:payroll_enforcement_ledger.entry_kind',
        'payroll.enforcement.dependant_kind' => 'db:payroll_enforcement_dependants.dependant_kind',
        // Editor vyživované osoby skládá popisky dynamicky
        // (`t(\`payroll.enforcement.spouse_pension.kind.${value}\`)`). Bez věty
        // by u manžela svítilo `invalidity_second_degree` právě tam, kde má být
        // řečeno, který důchod čtvrtinu zakládá — a u `unknown` by místo
        // vysvětlení, proč se čtvrtina nezapočítala, zůstal holý kód.
        'payroll.enforcement.spouse_pension.evidence'
            => 'enum:MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence',
        'payroll.enforcement.spouse_pension.holder'
            => 'db:payroll_enforcement_dependants.quarter_pension_holder',
        'payroll.enforcement.spouse_pension.kind'
            => 'db:payroll_enforcement_dependants.quarter_pension_kind',

        'payroll.employer.dimensions.type_options' => 'db:payroll_dimensions.dimension_type',

        'payroll.rulesets.lifecycle'    => 'enum:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle',
        'payroll.rulesets.domain'       => 'enum:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain',
        'payroll.rulesets.command'      => 'const:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetAdminService::COMMANDS',
        'payroll.rulesets.command_done' => 'const:MyInvoice\Service\Payroll\Ruleset\PayrollRulesetAdminService::COMMANDS',
    ];

    /**
     * Mzdové enumy, které HTTP hranici nepřekračují.
     *
     * Jsou to vstupy a mezivýsledky výpočtu (režim zaokrouhlení, evidenční
     * příznaky pojistného, vnitřní členění katalogu JMHZ). Klient je nevidí,
     * takže pro ně union ani překlad neexistuje a existovat nemusí. Jakmile
     * některý z nich začne odcházet v odpovědi, patří do {@see self::UNION_DOMAIN} —
     * tenhle seznam je tu proto, aby se na to muselo aktivně sáhnout.
     *
     * @var list<class-string>
     */
    private const BACKEND_ONLY_ENUMS = [
        \MyInvoice\Service\Payroll\Calculation\HealthMinimumTopUpPayer::class,
        \MyInvoice\Service\Payroll\Calculation\RoundingMode::class,
        \MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis::class,
        \MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceSource::class,
        \MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind::class,
        \MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus::class,
        \MyInvoice\Service\Payroll\Garnishment\InsolvencyMode::class,
        \MyInvoice\Service\Payroll\Garnishment\PensionEvidence::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibilitySource::class,
        \MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus::class,
        \MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind::class,
        \MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment::class,
        \MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxRegime::class,
        \MyInvoice\Service\Payroll\IncomeTax\TaxResidence::class,
        \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain::class,
        \MyInvoice\Service\Payroll\Security\PayrollRevealPurpose::class,
        \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeBasis::class,
        \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode::class,
        \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind::class,
        \MyInvoice\Service\Payroll\Security\PayrollSensitiveField::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence::class,
        // Důvod § 7a odst. 1 se s klientským unionem PÁRUJE přes sloupec, ne
        // přes tenhle enum: sloupec má navíc `none` (sleva se neuplatňuje),
        // což není zákonný důvod, ale výchozí stav evidence. Výsledek
        // posouzení § 7a odst. 3 hranici HTTP nepřekračuje jako volba
        // uživatele — je to zjištění výpočtu v uloženém výsledku.
        \MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountOutcome::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationAggregationGroup::class,
        \MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus::class,
        \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelKind::class,
        \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeGroup::class,
        // Výsledek posouzení záměru (kontrola 291) je zjištění výpočtu, ne
        // volba uživatele — do klienta odchází jen jako `evidences_discount`
        // a věta, ne jako kód, který by šlo poslat zpátky.
        \MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojEligibilityOutcome::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlScope::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSystem::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFieldEffect::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFieldRequirementKind::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzInteractionTriggerKind::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzMatrixKind::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleClassification::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleValidationResult::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectionKind::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolErrorOrigin::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolKind::class,
        \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPartKind::class,
    ];

    // ------------------------------------------------------------------ testy

    /**
     * Registr sám musí být živý: každá doména se musí dát rozřešit a nesmí být
     * prázdná. Bez téhle kontroly by překlep v názvu sloupce nebo přejmenovaná
     * třída udělaly z porovnání „prázdné vs. prázdné" — tedy tichou zelenou.
     */
    public function testEveryRegisteredDomainResolves(): void
    {
        $broken = [];
        foreach ([self::UNION_DOMAIN, self::I18N_DOMAIN] as $registry) {
            foreach ($registry as $key => $spec) {
                $values = $this->domain($spec);
                if ($values === []) {
                    $broken[] = sprintf('%s → %s (doména je prázdná)', $key, $spec);
                }
            }
        }

        self::assertSame([], array_values(array_unique($broken)), sprintf(
            "Záznam registru se nedá rozřešit:\n  %s\n\n"
                . 'Prázdná doména neporovná nic a test by prošel, i kdyby se kontrakt rozpadl.',
            implode("\n  ", $broken),
        ));
    }

    /** Union v klientovi musí mít PŘESNĚ tytéž hodnoty jako doména na backendu. */
    public function testTypeScriptUnionsMirrorBackendDomain(): void
    {
        $unions = $this->typeScriptUnions();
        $offences = [];

        foreach (self::UNION_DOMAIN as $unionKey => $spec) {
            if (!isset($unions[$unionKey])) {
                $offences[] = sprintf('%s — union v klientovi neexistuje (přejmenovaný?)', $unionKey);
                continue;
            }
            $expected = $this->domain($spec);
            $actual = $unions[$unionKey];

            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));
            if ($missing !== []) {
                $offences[] = sprintf(
                    '%s (%s) — klient NEZNÁ hodnoty, které backend posílá: %s',
                    $unionKey,
                    $spec,
                    implode(', ', $missing),
                );
            }
            if ($extra !== []) {
                $offences[] = sprintf(
                    '%s (%s) — klient nabízí hodnoty, které backend nezná: %s',
                    $unionKey,
                    $spec,
                    implode(', ', $extra),
                );
            }
        }

        self::assertSame([], $offences, sprintf(
            "Union v `web/src/api` se rozešel s doménou na backendu:\n  %s\n\n"
                . "Chybějící hodnota znamená, že klientský typ lže o tom, co může přijít.\n"
                . 'Legitimní rozdíl (čistě klientský stav) patří do CLIENT_ONLY_UNIONS i s důvodem.',
            implode("\n  ", $offences),
        ));
    }

    /**
     * Každá hodnota, kterou uživatel vidí jako stav nebo druh, musí mít popisek
     * v OBOU slovnících. Chybějící klíč se neprojeví chybou, ale holým kódem v UI.
     */
    public function testUserFacingValuesHaveCzechAndEnglishLabels(): void
    {
        $offences = [];
        foreach (['cs', 'en'] as $locale) {
            $messages = $this->messages($locale);
            foreach (self::I18N_DOMAIN as $prefix => $spec) {
                $node = $this->dig($messages, $prefix);
                if (!is_array($node)) {
                    $offences[] = sprintf('%s.json: chybí celá větev %s', $locale, $prefix);
                    continue;
                }
                foreach ($this->domain($spec) as $value) {
                    $label = $node[$value] ?? null;
                    if (!is_string($label) || trim($label) === '') {
                        $offences[] = sprintf('%s.json: chybí %s.%s (%s)', $locale, $prefix, $value, $spec);
                    }
                }
            }
        }

        self::assertSame([], $offences, sprintf(
            "Hodnota domény nemá popisek — uživatel na tom místě uvidí syrový klíč:\n  %s",
            implode("\n  ", $offences),
        ));
    }

    /**
     * Stavový graf pracovního vztahu musí znát přesně ty stavy, které připouští
     * sloupec. Je to zobecnění nálezu z migrace 1195: kód, který se za změnou
     * enumu neposunul, se navenek tváří, že funguje.
     */
    public function testEmploymentLifecycleGraphMatchesDatabaseEnum(): void
    {
        $reflection = new \ReflectionClass(\MyInvoice\Service\Payroll\PayrollEmploymentLifecycle::class);
        $transitions = $reflection->getConstant('TRANSITIONS');
        self::assertIsArray($transitions);
        self::assertNotSame([], $transitions, 'Stavový graf vztahu je prázdný — test by nic neporovnal.');

        $column = $this->domain('db:payroll_employments.status');
        $states = [];
        $unknownTargets = [];

        foreach ($transitions as $from => $targets) {
            $states[] = (string) $from;
            foreach ((array) $targets as $to) {
                if (!in_array($to, $column, true)) {
                    $unknownTargets[] = sprintf('%s → %s', (string) $from, (string) $to);
                }
            }
        }

        sort($states);
        $sortedColumn = $column;
        sort($sortedColumn);
        self::assertSame($sortedColumn, $states, 'PayrollEmploymentLifecycle zná jiné stavy než sloupec.');
        self::assertSame([], $unknownTargets, 'Přechod míří na stav, který sloupec nepřipouští.');
    }

    public function testPayrollRelationTypeEnumMatchesDatabaseColumn(): void
    {
        $enum = $this->domain(
            'enum:MyInvoice\Service\Payroll\Employment\PayrollRelationType',
        );
        $column = $this->domain('db:payroll_employments.relation_type');
        sort($enum, SORT_STRING);
        sort($column, SORT_STRING);

        self::assertNotEmpty($column, 'Doména payroll_employments.relation_type je prázdná.');
        self::assertSame(
            $column,
            $enum,
            'Společný enum pracovních vztahů se rozešel s databázovým sloupcem.',
        );
    }

    /**
     * `PayrollQuickSurchargeKind` je vědomá podmnožina, takže z registru párování
     * vypadl. Nehlídaný ale zůstat nesmí: kdyby se rozešel s
     * {@see PayrollSurchargeKind::quickManualEntry()}, formulář by buď nabízel
     * druh, který server odmítne, nebo naopak zamlčel zákonný nárok, který zadat
     * jde. Obojí se pozná až na výplatní pásce.
     */
    public function testQuickManualSurchargeKindsMatchTheClientUnion(): void
    {
        $union = $this->typeScriptUnions()['payroll.ts::PayrollQuickSurchargeKind'] ?? null;
        self::assertIsArray($union, 'Union PayrollQuickSurchargeKind v payroll.ts chybí.');

        $backend = array_map(
            static fn (PayrollSurchargeKind $kind): string => $kind->value,
            PayrollSurchargeKind::quickManualEntry(),
        );
        sort($union, SORT_STRING);
        sort($backend, SORT_STRING);

        self::assertSame($backend, $union);
        self::assertNotContains(
            PayrollSurchargeKind::Overtime->value,
            $backend,
            'Přesčas má v rychlém zadání vlastní pole; druhé pole na týž nárok '
            . 'by byl další způsob, jak ho vyplatit dvakrát.',
        );
    }

    /**
     * Rozcestník agend na kartě zaměstnance se řadí podle toho, jak často
     * k agendě účetní chodí — a to POŘADÍ je kontrakt, ne kosmetika.
     *
     * Klientský katalog (`payrollAgendaLinks.ts`) rozhoduje, v jakém pořadí se
     * dlaždice vykreslí; backend (`AGENDA_KEYS`) v jakém pořadí přijdou počty.
     * Union `PayrollAgendaKey` se porovnává jen jako množina (viz sweep výše),
     * takže přeskládat jednu stranu a na druhou zapomenout by dnes nic nechytlo:
     * mřížka by se u každého člověka vykreslila jinak, než jak je souhrn řazený,
     * a nikdo by nepoznal proč. Proto se čte skutečné pořadí `key:` z katalogu.
     */
    public function testAgendaCatalogOrderMatchesTheClientCatalog(): void
    {
        $path = dirname(__DIR__, 3) . '/web/src/pages/payroll/payrollAgendaLinks.ts';
        self::assertFileExists($path, 'Katalog agend na klientovi chybí.');

        $source = (string) file_get_contents($path);
        $catalog = substr($source, (int) strpos($source, 'export const payrollAgendas'));
        // `\r?` schválně: katalog má na Windows CRLF a bez toho by `$` nesedlo.
        preg_match_all("/^    key: '(\w+)',\r?$/m", $catalog, $matches);
        $clientOrder = $matches[1];

        // Bez tohohle by rozbitý parser (přejmenovaná konstanta, jiné odsazení)
        // udělal z testu prázdné porovnání, které projde vždycky.
        self::assertNotEmpty($clientOrder, 'V katalogu agend se nenašel jediný `key:` — čtečka je rozbitá.');

        self::assertSame(
            PayrollEmploymentAgendaSummaryRepository::agendaKeys(),
            $clientOrder,
            'Pořadí agend v `payrollAgendaLinks.ts` se rozešlo s `AGENDA_KEYS`. '
            . 'Přeskládat se musí OBĚ strany naráz.',
        );
    }

    /**
     * Nový union nesmí vzniknout mlčky. Buď se páruje s doménou, nebo mu někdo
     * musel napsat, proč párovat nejde — třetí možnost tenhle test nedovolí.
     */
    public function testEveryPayrollApiUnionIsPairedOrDocumented(): void
    {
        $unions = $this->typeScriptUnions();
        // Bez téhle pojistky by se z rozbitého čtenáře `.ts` stal test, který
        // prochází vždy — projít nad prázdnou množinou umí každé pravidlo.
        self::assertNotEmpty($unions, 'V `web/src/api/payroll*.ts` se nenašel žádný union — sweep nic nehlídá.');

        $unclassified = [];
        foreach (array_keys($unions) as $unionKey) {
            if (isset(self::UNION_DOMAIN[$unionKey]) || isset(self::CLIENT_ONLY_UNIONS[$unionKey])) {
                continue;
            }
            $unclassified[] = $unionKey;
        }

        // Výjimka, jejíž union už neexistuje, je jen nepravdivá poznámka v registru.
        $stale = array_values(array_diff(array_keys(self::CLIENT_ONLY_UNIONS), array_keys($unions)));
        self::assertSame([], $stale, sprintf(
            "CLIENT_ONLY_UNIONS zmiňuje union, který v klientovi není:\n  %s",
            implode("\n  ", $stale),
        ));

        self::assertSame([], $unclassified, sprintf(
            "Union v `web/src/api/payroll*.ts` není zařazený:\n  %s\n\n"
                . "Přidej ho do UNION_DOMAIN (páruje se s doménou na backendu),\n"
                . 'nebo do CLIENT_ONLY_UNIONS i s důvodem, proč párovat nejde.',
            implode("\n  ", $unclassified),
        ));
    }

    /** Totéž z druhé strany: nový mzdový enum musí být zařazený. */
    public function testEveryPayrollEnumIsPairedOrDocumented(): void
    {
        $paired = [];
        foreach (self::UNION_DOMAIN + self::I18N_DOMAIN as $spec) {
            if (preg_match('/^(?:enum|enum-names):(.+)$/', $spec, $m) === 1) {
                $paired[] = $m[1];
            }
        }

        $unclassified = [];
        foreach ($this->payrollBackedEnums() as $fqcn) {
            if (in_array($fqcn, $paired, true) || in_array($fqcn, self::BACKEND_ONLY_ENUMS, true)) {
                continue;
            }
            $unclassified[] = $fqcn;
        }

        self::assertSame([], $unclassified, sprintf(
            "Mzdový enum není zařazený:\n  %s\n\n"
                . "Buď ho spáruj s unionem v UNION_DOMAIN, nebo — pokud HTTP hranici\n"
                . 'nepřekračuje — zapiš ho do BACKEND_ONLY_ENUMS.',
            implode("\n  ", $unclassified),
        ));
    }

    // ------------------------------------------------------- rozřešení domény

    /** @return list<string> */
    private function domain(string $spec): array
    {
        [$kind, $argument] = explode(':', $spec, 2);

        return match ($kind) {
            'enum' => $this->enumValues($argument),
            'enum-names' => $this->enumNames($argument),
            'const' => $this->classConstant($argument),
            'consts' => $this->stringConstants($argument),
            'policy' => $this->policyDomain($argument),
            'db' => $this->columnDomain($argument),
            default => throw new \LogicException('Neznámý druh domény: ' . $kind),
        };
    }

    /** @return list<string> */
    private function enumValues(string $fqcn): array
    {
        if (!enum_exists($fqcn)) {
            return [];
        }

        return array_map(
            static fn (\UnitEnum $case): string => (string) ($case instanceof \BackedEnum ? $case->value : $case->name),
            $fqcn::cases(),
        );
    }

    /** @return list<string> */
    private function enumNames(string $fqcn): array
    {
        if (!enum_exists($fqcn)) {
            return [];
        }

        return array_map(static fn (\UnitEnum $case): string => $case->name, $fqcn::cases());
    }

    /** @return list<string> */
    private function classConstant(string $reference): array
    {
        [$fqcn, $name] = explode('::', $reference, 2);
        if (!class_exists($fqcn)) {
            return [];
        }
        $value = (new \ReflectionClass($fqcn))->getConstant($name);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return list<string> */
    private function stringConstants(string $fqcn): array
    {
        if (!class_exists($fqcn)) {
            return [];
        }
        $values = [];
        foreach ((new \ReflectionClass($fqcn))->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }
            $value = $constant->getValue();
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function policyDomain(string $key): array
    {
        $enums = (new \ReflectionClass(PayrollEmployerPolicyService::class))->getConstant('ENUMS');

        return is_array($enums) && isset($enums[$key]) && is_array($enums[$key])
            ? array_values(array_filter($enums[$key], 'is_string'))
            : [];
    }

    /**
     * Finální podoba ENUM sloupce podle migrací.
     *
     * Čte se z migrací, ne z konstanty v testu ani z živé databáze: konstanta by
     * zastarala se schématem a živá databáze v CI kroku `Architecture` není.
     * Poslední migrace, která se sloupce dotkne, vyhrává — stejná úvaha jako
     * v {@see PayrollEmploymentStatusLiteralTest}.
     *
     * @return list<string>
     */
    private function columnDomain(string $reference): array
    {
        [$table, $column] = explode('.', $reference, 2);

        return $this->columnDomains()[$table . '.' . $column] ?? [];
    }

    /** @return array<string,list<string>> */
    private function columnDomains(): array
    {
        /** @var null|array<string,list<string>> $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $files = glob(dirname(__DIR__, 3) . '/db/migrations/*.sql') ?: [];
        natsort($files);
        $columns = [];

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);

            if (preg_match_all(
                '/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE/is',
                $sql,
                $tables,
                PREG_SET_ORDER,
            ) > 0) {
                foreach ($tables as $table) {
                    foreach ($this->enumColumns($table[2], '/^\s*`?(\w+)`?\s+ENUM\s*\(((?:[^()]|\n)*?)\)/im') as $name => $values) {
                        $columns[$table[1] . '.' . $name] = $values;
                    }
                }
            }

            if (preg_match_all('/ALTER TABLE\s+`?(\w+)`?(.*?);/is', $sql, $alters, PREG_SET_ORDER) > 0) {
                foreach ($alters as $alter) {
                    $pattern = '/(?:MODIFY|ADD|CHANGE)\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?'
                        . '`?(\w+)`?(?:\s+`?\w+`?)?\s+ENUM\s*\(((?:[^()]|\n)*?)\)/is';
                    foreach ($this->enumColumns($alter[2], $pattern) as $name => $values) {
                        $columns[$alter[1] . '.' . $name] = $values;
                    }
                }
            }
        }

        return $cache = $columns;
    }

    /** @return array<string,list<string>> */
    private function enumColumns(string $body, string $pattern): array
    {
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        $out = [];
        foreach ($matches as $match) {
            preg_match_all("/'([^']*)'/", $match[2], $values);
            $out[$match[1]] = $values[1];
        }

        return $out;
    }

    // ------------------------------------------------------------ čtení frontu

    /**
     * Unionty řetězcových literálů z `web/src/api/payroll*.ts`.
     *
     * Bere se jen union složený VÝHRADNĚ z literálů — `type X = Y | 'other'`
     * nebo odkaz na jiný typ se vědomě přeskakuje, protože jeho doménu nelze
     * z jednoho souboru poznat a test by na něm hádal.
     *
     * @return array<string,list<string>>
     */
    private function typeScriptUnions(): array
    {
        /** @var null|array<string,list<string>> $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $files = glob(dirname(__DIR__, 3) . '/web/src/api/payroll*.ts') ?: [];
        sort($files);
        $unions = [];

        foreach ($files as $file) {
            // \R kvůli CRLF v pracovním stromu (git autocrlf na Windows).
            $source = preg_replace('/\R/', "\n", (string) file_get_contents($file)) ?? '';
            preg_match_all(
                '/export type (\w+) =([^\n]*(?:\n\s*\|[^\n]*)*)/',
                $source,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $body = $match[2];
                // Zbude-li po odstranění literálů, svislítek a bílých znaků cokoli,
                // není to čistý union literálů.
                if (preg_replace("/'[^']*'|\||\s/", '', $body) !== '') {
                    continue;
                }
                preg_match_all("/'([^']*)'/", $body, $values);
                if ($values[1] === []) {
                    continue;
                }
                $unions[basename($file) . '::' . $match[1]] = $values[1];
            }
        }

        return $cache = $unions;
    }

    /** @return array<string,mixed> */
    private function messages(string $locale): array
    {
        /** @var array<string,array<string,mixed>> $cache */
        static $cache = [];
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }
        $path = dirname(__DIR__, 3) . "/web/src/i18n/{$locale}.json";
        self::assertFileExists($path);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $cache[$locale] = $decoded;
    }

    /** @param array<string,mixed> $tree */
    private function dig(array $tree, string $path): mixed
    {
        $node = $tree;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Řetězcové enumy pod `src/Service/Payroll`. Hledají se v textu (deklarace
     * `enum X: string`) a teprve pak se ověří, že je autoloader zná — tím se
     * pozná i enum, který se do jmenného prostoru nedostal.
     *
     * @return list<class-string<\UnitEnum>>
     */
    private function payrollBackedEnums(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Service/Payroll';
        $found = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $code, $namespace) !== 1) {
                continue;
            }
            if (preg_match('/^enum\s+(\w+)\s*:\s*string/m', $code, $name) !== 1) {
                continue;
            }
            $fqcn = trim($namespace[1]) . '\\' . $name[1];
            if (enum_exists($fqcn)) {
                $found[] = $fqcn;
            }
        }
        sort($found);
        self::assertNotEmpty($found, 'Pod src/Service/Payroll se nenašel žádný enum — sweep by nic nehlídal.');

        return $found;
    }
}
