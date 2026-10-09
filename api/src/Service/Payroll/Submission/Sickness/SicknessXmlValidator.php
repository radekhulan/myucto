<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use MyInvoice\Service\Bank\CzechBankCodeRegistry;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;

/**
 * Validace datových vět NEMPRI25 a HZUPN20 proti PŘIPNUTÉMU XSD a proti těm
 * pravidlům, která XSD vyjádřit neumí.
 *
 * Tři vrstvy, každá chytá jinou třídu chyby:
 *
 * 1. **Obchodní hranice** — co XSD dovolí, ale ČSSZ odmítne až protokolem
 *    (opravné podání bez čísla rozhodnutí, pracovní volno bez období,
 *    interval práce mimo dobu neschopnosti).
 * 2. **Otisk snapshotu** — XML se přeserializuje z payloadu a porovná bajt po
 *    bajtu. Bez toho by šlo uložit artefakt, který neodpovídá datům, ze
 *    kterých vznikl, a nikdo by to nepoznal.
 * 3. **XSD** — proti souboru z {@see CsszSchemaCatalog}. Katalog ověřuje otisk
 *    SHA-256 vstupního schématu i jeho `baseTypes`; nesouhlasí-li, podání
 *    spadne. To je záměr: validovat proti jinému než ověřenému schématu
 *    znamená tvrdit shodu, kterou nikdo neprokázal.
 *
 * ## Past, kterou odhalilo až XSD
 *
 * `VSZamestnavatel` (NEMPRI) i `variabilniSymbol` (HZUPN) jsou
 * `tns:simpleNType_string` s `length=10`, tedy vzor `[1-9][0-9]*` na deseti
 * znacích. Variabilní symbol tudíž NESMÍ začínat nulou a NELZE ho doplnit
 * zleva nulami do desítky, jak to dělá OZUSPOJ u své vlastní datové věty.
 * Krátký nebo nulou začínající symbol je proto tvrdá chyba s vlastním
 * důvodovým kódem, ne tiché doplnění.
 */
final readonly class SicknessXmlValidator
{
    /** Kontrola 3 LK: den vystavení HZUPN musí být po tomto dni. */
    private const HZUPN_ISSUED_AFTER = '2019-12-31';

    /** Hodiny posledního dne: interval 0 až 24 (DV HZUPN20, NEMPRI25 StDoublePracDoba). */
    private const MAX_DAY_HOURS = 24.0;

    public function __construct(
        private CsszSchemaCatalog $schemas,
        private NempriXmlSerializer $nempri,
        private HzupnXmlSerializer $hzupn,
    ) {}

    public function validateNempri(NempriXmlPayload $payload, string $xml): void
    {
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1) {
            $this->invalid(
                'nempri_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic. '
                . 'NEMPRI ho vyžaduje vždy — bez něj ČSSZ případ nespáruje.',
            );
        }
        $this->vendor('nempri_vendor_invalid', $payload->productName, $payload->productVersion);
        foreach ([
            'nempri_insured_first_name_missing' => $payload->insuredFirstName,
            'nempri_insured_last_name_missing' => $payload->insuredLastName,
            'nempri_employer_name_missing' => $payload->employerName,
        ] as $code => $value) {
            if (trim($value) === '') {
                $this->invalid(
                    $code,
                    'Oznámení nemá vyplněné povinné identifikační údaje.',
                );
            }
        }
        if (preg_match('/^[0-9A-Z]{1,3}$/D', $payload->activityCode) !== 1) {
            $this->invalid(
                'nempri_activity_code_invalid',
                'Druh činnosti musí být kód z číselníku ČSSZ (1 až 3 znaky 0-9 a A-Z). '
                . 'Doplňte ho v podmínkách pracovního vztahu.',
            );
        }
        // Opravné podání se páruje číslem rozhodnutí jen tam, kde ho druh dávky
        // nese povinně. VPM, PPM s převzetím dítěte do péče a otcovská bez
        // potvrzení o hospitalizaci číslo nemají, a opravu podat musí jít i tak:
        // Všeobecné zásady chtějí opravu odeslaných údajů vždy jako opravné
        // podání. Zahraniční případ číslo z českého systému mít nemusí.
        if ($payload->correction
            && $payload->decisionNumber === null
            && !$payload->foreignCase
            && $payload->benefitKind->decisionNumberRequirement(
                $payload->application?->maternityCareReason !== null,
            ) === SicknessBenefitKind::DECISION_REQUIRED
        ) {
            $this->invalid(
                'nempri_correction_without_decision_number',
                'Opravné podání se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové podání.',
            );
        }
        $this->decisionNumber($payload);
        if ($payload->benefitKind->hasUnpaidLeaveSection()) {
            $this->unpaidLeave($payload);
        } elseif ($payload->unpaidLeave
            || $payload->unpaidLeaveFrom !== null
            || $payload->unpaidLeaveTo !== null
        ) {
            $this->invalid(
                'nempri_unpaid_leave_not_in_benefit_kind',
                'Potvrzení zaměstnavatele u tohoto druhu dávky prvek pracovního volna '
                . 'bez náhrady příjmu nemá; vyplněné volno by datovou větu shodilo.',
            );
        }
        $this->employerConfirmation($payload);
        $this->application($payload);
        $this->decisivePeriodRequirement($payload);
        if ($payload->decisivePeriod !== null) {
            $this->decisivePeriod($payload->decisivePeriod);
        }
        $this->paymentConnectionRequirement($payload);
        if ($payload->paymentConnection !== null) {
            $this->paymentConnection($payload->paymentConnection);
        }
        if ($payload->transferredOtherWork !== ($payload->transferredOn !== null)) {
            $this->invalid(
                'nempri_transfer_date_mismatch',
                'Převedení na jinou práci musí mít datum a datum nesmí být bez převedení.',
            );
        }
        $this->exactDate($payload->employmentFrom, 'nempri_date_invalid');
        if ($payload->employmentTo !== null) {
            $this->exactDate($payload->employmentTo, 'nempri_date_invalid');
            if ($payload->employmentTo < $payload->employmentFrom) {
                $this->invalid(
                    'nempri_employment_period_invalid',
                    'Den skončení zaměstnání nesmí předcházet dni jeho vzniku.',
                );
            }
        }
        foreach ([
            $payload->unpaidLeaveFrom,
            $payload->unpaidLeaveTo,
            $payload->childBirthDate,
            $payload->transferredOn,
        ] as $date) {
            if ($date !== null) {
                $this->exactDate($date, 'nempri_date_invalid');
            }
        }
        // LK 13 (chyba DIS 08/13): narození dítěte ani převedení na jinou práci
        // nemůže být v budoucnu.
        $this->notInFuture($payload->childBirthDate);
        $this->notInFuture($payload->transferredOn);
        $this->assertSnapshot(
            $this->nempri->serialize($payload),
            $xml,
            'nempri_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu NEMPRI.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::NEMPRI25,
            'nempri_xsd_validation_failed',
            'XML NEMPRI neprošlo připnutým XSD: ',
        );
    }

    /**
     * @param string $incapacityFrom První den dočasné pracovní neschopnosti;
     *        intervaly práce ani návrat do práce nesmí být dřív.
     */
    public function validateHzupn(
        HzupnXmlPayload $payload,
        string $xml,
        string $incapacityFrom,
    ): void {
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (!$payload->employerReport) {
            $this->invalid(
                'hzupn_employer_report_required',
                'Hlášení, které podává zaměstnavatel, musí mít příznak hlášení zaměstnavatele. '
                . 'Hlášení osoby dobrovolně nemocensky pojištěné podává pojištěnec sám.',
            );
        }
        if ($payload->personReport) {
            $this->invalid(
                'hzupn_person_report_not_supported',
                'Hlášení osoby dobrovolně nemocensky pojištěné aplikace nesestavuje — '
                . 'není to podání zaměstnavatele.',
            );
        }
        if ($payload->insuredBirthNumber === null
            && $payload->insuredBirthDate === null
        ) {
            $this->invalid(
                'hzupn_insured_identifier_missing',
                'Hlášení musí nést rodné číslo pojištěnce nebo alespoň datum narození, '
                . 'jinak ho ČSSZ nespáruje s neschopenkou.',
            );
        }
        if ($payload->insuredBirthNumber !== null
            && preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1
        ) {
            $this->invalid(
                'hzupn_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic.',
            );
        }
        // DV HZUPN20, kontrola 2: rodné číslo musí být platné (modulo 11, datum).
        $birthNumberProblem = self::birthNumberProblem($payload->insuredBirthNumber);
        if ($birthNumberProblem !== null) {
            $this->invalid(
                'hzupn_birth_number_invalid',
                'Rodné číslo pojištěnce není platné: ' . $birthNumberProblem,
            );
        }
        $this->vendor('hzupn_vendor_invalid', $payload->productName, $payload->productVersion);
        if ($payload->correction && $payload->confirmationNumber === null) {
            $this->invalid(
                'hzupn_correction_without_confirmation_number',
                'Opravné hlášení se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové hlášení.',
            );
        }
        // Hlášení se vždy váže k jedné neschopence a ČSSZ ho s ní páruje číslem
        // rozhodnutí. Bez čísla lze podat jen zahraniční případ, jehož
        // rozhodnutí nevydal český lékař; to platí i pro slovenskou neschopenku.
        if ($payload->confirmationNumber === null
            && !$payload->foreignCase
            && !$payload->slovakCase
        ) {
            $this->invalid(
                'hzupn_confirmation_number_missing',
                'Chybí číslo rozhodnutí o dočasné pracovní neschopnosti. ČSSZ podle něj '
                . 'hlášení páruje s neschopenkou; bez něj ho nezpracuje. Výjimkou je jen '
                . 'zahraniční (i slovenský) případ.',
            );
        }
        // Kontrola 33 LK: HZUPN nese jen číslo ve formátu od r. 2020. Rozhodnutí
        // mimo český systém (zahraniční, slovenská neschopenka) tvar českého
        // čísla mít nemusí, stejně jako u NEMPRI.
        if ($payload->confirmationNumber !== null
            && !$payload->foreignCase
            && !$payload->slovakCase
        ) {
            $problem = SicknessDecisionNumber::hzupnProblem($payload->confirmationNumber);
            if ($problem !== null) {
                $this->invalid($problem['code'], $problem['message']);
            }
        }
        $this->hzupnEmployerConfirmation($payload);
        if ($payload->osszName !== null
            && mb_strlen($payload->osszName, 'UTF-8') > CsszWorkplaceCatalog::MAX_NAME_LENGTH
        ) {
            $this->invalid(
                'hzupn_ossz_name_too_long',
                'Název pracoviště ČSSZ smí mít nejvýš ' . CsszWorkplaceCatalog::MAX_NAME_LENGTH . ' znaků.',
            );
        }
        $this->exactDate($payload->issuedOn, 'hzupn_date_invalid');
        if ($payload->issuedOn <= self::HZUPN_ISSUED_AFTER) {
            $this->invalid(
                'hzupn_issue_date_too_early',
                'Den vystavení hlášení musí být po 31. 12. 2019 (kontrola 3 ČSSZ). Opravte ho u případu.',
            );
        }
        $this->exactDate($incapacityFrom, 'hzupn_date_invalid');
        if ($payload->returnedOn !== null) {
            $this->exactDate($payload->returnedOn, 'hzupn_date_invalid');
            if ($payload->returnedOn < $incapacityFrom) {
                $this->invalid(
                    'hzupn_return_before_incapacity',
                    'Návrat do práce nemůže předcházet vzniku pracovní neschopnosti.',
                );
            }
        }
        $previousTo = null;
        foreach ($payload->workIntervals as $interval) {
            $this->exactDate($interval['from'], 'hzupn_date_invalid');
            $this->exactDate($interval['to'], 'hzupn_date_invalid');
            // Kontrola 30 LK (2019) zní „DO musí být > OD", jenže interval nese
            // celé dny práce: jednodenní práce je od = do a ostře větší by ji
            // nešlo nahlásit vůbec. DV HZUPN20 v1.12 žádnou kontrolu intervalu
            // neuvádí a NEMPRI u téhož seznamu dní práce chce od <= do. Rovnost
            // je proto povolená záměrně; ověření v testovacím prostředí ČSSZ
            // zůstává otevřené.
            if ($interval['to'] < $interval['from']) {
                $this->invalid(
                    'hzupn_work_interval_invalid',
                    'Interval práce v době neschopnosti musí končit nejdřív dnem, kterým začíná.',
                );
            }
            if ($interval['from'] < $incapacityFrom) {
                $this->invalid(
                    'hzupn_work_interval_before_incapacity',
                    'Práce v době neschopnosti nemůže spadat před její vznik.',
                );
            }
            if ($previousTo !== null && $interval['from'] <= $previousTo) {
                $this->invalid(
                    'hzupn_work_intervals_overlap',
                    'Intervaly práce v době neschopnosti se nesmí překrývat ani navazovat '
                    . 've stejný den; ČSSZ z nich počítá vyloučené dny.',
                );
            }
            $previousTo = $interval['to'];
        }
        $this->assertSnapshot(
            $this->hzupn->serialize($payload),
            $xml,
            'hzupn_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu HZUPN.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::HZUPN20,
            'hzupn_xsd_validation_failed',
            'XML HZUPN neprošlo připnutým XSD: ',
        );
    }

    /**
     * Úplnost žádosti o dávku u OSE, DLO, OPP a PPM.
     *
     * Hlídá se jen to, bez čeho ČSSZ větu odmítne nebo nespáruje: akce,
     * den, od kterého se žádá, dítě nebo ošetřovaná osoba a u otcovské důvod.
     * Prohlášení zaměstnance (společná domácnost, osamělost …) podání
     * neblokují — nevyplněné se podle zásad NEMPRI uvádí jako „NE“
     * ({@see SicknessPayloadFactory::application()}); ve větě ale být musí.
     */
    private function application(NempriXmlPayload $payload): void
    {
        $kind = $payload->benefitKind;
        $application = $payload->application;
        if (!$kind->hasApplication()) {
            return;
        }
        if ($application === null) {
            $this->invalid(
                'nempri_application_missing',
                'U tohoto druhu dávky věta nese žádost zaměstnance o dávku. '
                . 'Vyplňte údaje z žádosti, kterou vám zaměstnanec předal.',
            );
        }
        if ($kind->hasActions()
            && !$application->actionStart
            && !$application->actionContinuation
            && !$application->actionEnd
        ) {
            $this->invalid(
                'nempri_care_action_missing',
                'Ošetřovné musí nést alespoň jednu akci: vznik, trvání nebo ukončení. '
                . 'Větu bez akce ČSSZ odmítne.',
            );
        }
        // Doporučené kombinace akcí (FAQ ČSSZ k dávkám NP, dotaz 6; Elektronizace
        // dávek NP, str. 5-6): je-li k dispozici ukončení, trvání je nadbytečné.
        if ($kind->hasActions() && $application->actionContinuation && $application->actionEnd) {
            if ($application->actionStart) {
                $this->invalid(
                    'nempri_care_actions_all_three',
                    'Vznik, trvání a ukončení současně ČSSZ nepoužívá. Je-li potřeba péče už '
                    . 'ukončená, zvolte u případu jen Vznik a Ukončení (trvání je nadbytečné).',
                );
            }
            $this->invalid(
                'nempri_care_continuation_with_end',
                'Trvání spolu s ukončením je podle ČSSZ nadbytečné. Je-li potřeba péče ukončená, '
                . 'zvolte u případu jen Ukončení.',
            );
        }
        $starts = !$kind->hasActions() || $application->actionStart;
        if ($starts && $application->fromDate === null) {
            $this->invalid(
                'nempri_application_from_missing',
                'Žádost musí uvést den, od kterého zaměstnanec o dávku žádá.',
            );
        }
        if ($kind->hasActions() && $application->carriesDuration()) {
            if ($application->toDate === null) {
                $this->invalid(
                    'nempri_application_to_missing',
                    'Při trvání nebo ukončení péče musí žádost uvést den, do kterého '
                    . 'zaměstnanec o dávku žádá (doDne).',
                );
            }
            $this->careDuration($kind, $application);
        }
        if ($kind->hasActions() && $application->carriesStart()) {
            $this->careStart($kind, $application);
        }
        if ($kind === SicknessBenefitKind::Opp) {
            $this->paternitySupport($application);
        }
        foreach ([$application->fromDate, $application->toDate, $application->returnedOn] as $date) {
            if ($date !== null) {
                $this->exactDate($date, 'nempri_date_invalid');
            }
        }
        if ($application->fromDate !== null
            && $application->toDate !== null
            && $application->toDate < $application->fromDate
        ) {
            $this->invalid(
                'nempri_application_period_invalid',
                'Den, do kterého se o dávku žádá, nesmí předcházet dni, od kterého se žádá.',
            );
        }
        $needsPerson = $kind === SicknessBenefitKind::Opp
            || ($kind->hasActions() && $application->actionStart);
        if ($needsPerson && $application->person === null) {
            $this->invalid(
                $kind === SicknessBenefitKind::Opp
                    ? 'nempri_child_missing'
                    : 'nempri_cared_person_missing',
                $kind === SicknessBenefitKind::Opp
                    ? 'Otcovská musí uvést dítě, o které zaměstnanec pečuje.'
                    : 'Žádost musí uvést ošetřovanou osobu.',
            );
        }
        if ($application->person !== null) {
            $this->person($application->person);
        }
        if ($kind === SicknessBenefitKind::Opp && $application->paternityReason === null) {
            $this->invalid(
                'nempri_paternity_reason_missing',
                'Otcovská musí uvést důvod podle žádosti (kód z číselníku ČSSZ).',
            );
        }
        if ($kind === SicknessBenefitKind::Ose && $application->actionStart
            && $application->careReason === null
        ) {
            $this->invalid(
                'nempri_care_reason_missing',
                'Žádost o ošetřovné musí uvést důvod péče: onemocnění, karanténa, '
                . 'nemožnost péče o dítě, nebo uzavření školy či zařízení.',
            );
        }
        if ($application->careReason !== null
            && !in_array($application->careReason, NempriBenefitApplication::CARE_REASONS, true)
        ) {
            $this->invalid(
                'nempri_care_reason_invalid',
                'Důvod péče není z nabízeného seznamu.',
            );
        }
        if ($application->carriesStart()
            && $application->careReason === NempriBenefitApplication::CARE_REASON_SCHOOL_CLOSED
        ) {
            if ($application->schoolName === null || trim($application->schoolName) === '') {
                $this->invalid(
                    'nempri_school_name_missing',
                    'U uzavřené školy nebo zařízení musí žádost uvést jeho název.',
                );
            }
            if ($application->schoolBusinessId === null || trim($application->schoolBusinessId) === '') {
                $this->invalid(
                    'nempri_school_business_id_missing',
                    'U uzavřené školy nebo zařízení musí žádost uvést jeho IČ '
                    . '(ICZarizeniSkoly je podle DV NEMPRI25 povinné).',
                );
            }
        }
        // XSD má u všech tří prvků jen `StCiselnik`, takže kód mimo číselník
        // projde schématem a odmítne ho až územní správa.
        NempriCodebook::assertValid(
            $kind,
            $application->relationshipCode,
            $application->paternityReason,
            $application->maternityCareReason,
        );
        // DV NEMPRI25 u `duvodPece`: „Pokud je vyplněno, nesmí být uvedeno
        // cisloRozhodnuti" a seznam dětí je pak povinný.
        if ($application->maternityCareReason !== null) {
            if ($payload->decisionNumber !== null) {
                $this->invalid(
                    'nempri_maternity_care_reason_with_decision_number',
                    'Peněžitá pomoc v mateřství s důvodem převzetí dítěte do péče nesmí nést '
                    . 'číslo rozhodnutí. Smažte ho v případu dávky, nebo důvod převzetí.',
                );
            }
            if ($application->person === null) {
                $this->invalid(
                    'nempri_maternity_care_child_missing',
                    'U převzetí dítěte do péče musí žádost uvést převzaté dítě.',
                );
            }
        } elseif ($kind === SicknessBenefitKind::Ppm && $application->person !== null) {
            // Postupy zaměstnavatelů (Elektronizace dávek NP, bod 2) a Všeobecné
            // zásady NEMPRI: při běžném nástupu na PPM se vyplňuje jen den
            // nástupu, identifikace dítěte patří jen k převzetí dítěte do péče.
            $this->invalid(
                'nempri_maternity_child_without_care_reason',
                'Při běžném nástupu na peněžitou pomoc v mateřství se dítě neuvádí. Údaje '
                . 'o dítěti patří jen k převzetí dítěte do péče: vyplňte důvod převzetí, '
                . 'nebo dítě u případu smažte.',
            );
        }
        if ($application->childOrder !== null
            && ($application->childOrder < 1 || $application->childOrder > 10)
        ) {
            $this->invalid(
                'nempri_child_order_invalid',
                'Pořadí dítěte musí být 1 až 10.',
            );
        }
        foreach ([
            $application->careDays,
            $application->workDays,
            $application->leavePeriods,
            $application->shiftSchedule,
        ] as $periods) {
            foreach ($periods as $period) {
                $this->exactDate($period['from'], 'nempri_date_invalid');
                $this->exactDate($period['to'], 'nempri_date_invalid');
                if ($period['to'] < $period['from']) {
                    $this->invalid(
                        'nempri_period_invalid',
                        'Období péče nebo práce musí končit nejdřív dnem, kterým začíná.',
                    );
                }
            }
        }
    }

    /**
     * Číslo rozhodnutí podle druhu dávky (logické kontroly NEMPRI25 č. 2 a 3):
     * povinnost, zákaz i tvar. Zahraniční případ číslo z českého systému mít
     * nemusí, a tak se pro něj tvar nehlídá.
     */
    private function decisionNumber(NempriXmlPayload $payload): void
    {
        $problem = $payload->benefitKind->decisionNumberProblem(
            $payload->decisionNumber,
            $payload->application?->maternityCareReason !== null,
            $payload->foreignCase,
        );
        if ($problem !== null) {
            $this->invalid($problem['code'], $problem['message']);
        }
    }

    /**
     * Podmíněné prvky potvrzení zaměstnavatele (DV NEMPRI25, kontroly 12 až 14):
     * každý nese vlastní nadřazený příznak a bez něj nesmí být.
     */
    private function employerConfirmation(NempriXmlPayload $payload): void
    {
        $kind = $payload->benefitKind;
        $hours = self::number($payload->hoursWorked);
        $workTime = self::number($payload->dailyWorkingHours);
        if ($payload->workedOnDecisiveDay) {
            if ($hours === null || $workTime === null) {
                $this->invalid(
                    'nempri_worked_hours_missing',
                    'Zaměstnanec v den sociální události pracoval, takže potvrzení musí nést '
                    . 'počet odpracovaných hodin i pracovní dobu. Doplňte je v případu dávky.',
                );
            }
            if ($hours > $workTime) {
                $this->invalid(
                    'nempri_worked_hours_exceed_working_time',
                    'Počet odpracovaných hodin nesmí být vyšší než pracovní doba.',
                );
            }
        } elseif ($hours !== null || $workTime !== null) {
            $this->invalid(
                'nempri_hours_without_worked',
                'Odpracované hodiny a pracovní doba se uvádějí jen tehdy, když zaměstnanec '
                . 'v den sociální události pracoval. Zaškrtněte „pracoval“, nebo hodiny smažte.',
            );
        }
        if ($kind->hasStudentSection()) {
            if ($payload->isStudent && $payload->withinSchoolHolidays === null) {
                $this->invalid(
                    'nempri_school_holidays_missing',
                    'Zaměstnanec je student, takže potvrzení musí říct, zda událost spadá do prázdnin.',
                );
            }
            if (!$payload->isStudent && $payload->withinSchoolHolidays !== null) {
                $this->invalid(
                    'nempri_school_holidays_without_student',
                    'Prázdniny se uvádějí jen u studenta.',
                );
            }
        }
        if ($kind === SicknessBenefitKind::Nem
            || $kind === SicknessBenefitKind::Vpm
            || $kind === SicknessBenefitKind::Ppm
        ) {
            if ($payload->receivesPension && $payload->pensionKind === null) {
                $this->invalid(
                    'nempri_pension_kind_missing',
                    'Zaměstnanec pobírá důchod, takže potvrzení musí nést druh důchodu.',
                );
            }
            if (!$payload->receivesPension && $payload->pensionKind !== null) {
                $this->invalid(
                    'nempri_pension_kind_without_pension',
                    'Druh důchodu se uvádí jen u zaměstnance, který důchod pobírá.',
                );
            }
            NempriCodebook::assertPensionKind($payload->pensionKind);
            if ($payload->startsMaternity === true && $payload->childBirthDate === null) {
                $this->invalid(
                    'nempri_child_birth_missing',
                    'Zaměstnankyně nastupuje na peněžitou pomoc v mateřství, takže potvrzení '
                    . 'musí nést datum narození dítěte.',
                );
            }
            if ($payload->startsMaternity !== true && $payload->childBirthDate !== null) {
                $this->invalid(
                    'nempri_child_birth_without_maternity',
                    'Datum narození dítěte se uvádí jen při nástupu na peněžitou pomoc v mateřství.',
                );
            }
        }
    }

    /**
     * Prvky žádosti o ošetřovné, které patří jen k akci vznik. Prohlášení
     * vzniku jsou u OSE i DLO při vzniku povinná (DV NEMPRI25, chyba 02).
     */
    private function careStart(SicknessBenefitKind $kind, NempriBenefitApplication $application): void
    {
        $declarations = $kind === SicknessBenefitKind::Ose
            ? [
                $application->sharedHousehold,
                $application->loneCaregiver,
                $application->childUnder16,
                $application->otherMaternityClaim,
            ]
            : [
                $application->alternation,
                $application->otherMaternityClaim,
                $application->sharedHousehold,
            ];
        if (in_array(null, $declarations, true)) {
            $this->invalid(
                'nempri_care_declaration_missing',
                'Žádost s akcí vznik musí nést všechna prohlášení zaměstnance (u ošetřovného společnou '
                . 'domácnost, osamělost, dítě do 16 let a nárok jiné osoby; u dlouhodobého ošetřovného '
                . 'střídání, nárok jiné osoby a společnou domácnost). Nevyplněné se uvádí jako NE.',
            );
        }
        if ($application->otherMaternityClaim === true) {
            $missing = $kind === SicknessBenefitKind::Ose
                ? ($application->otherParentalClaim === null || $application->otherPersonS57 === null)
                : $application->otherPersonS57 === null;
            if ($missing) {
                $this->invalid(
                    'nempri_other_claim_details_missing',
                    'Je-li uvedeno, že na dávku má nárok jiná osoba, musí žádost nést i nárok '
                    . 'na rodičovský příspěvek a údaj o jiné fyzické osobě podle § 57.',
                );
            }
        }
    }

    /**
     * Prvky trvání a ukončení péče: u samotného vzniku zakázané, jinak povinné
     * (`pecovalOsobne`, `planovaneSmeny`, podklady pro výplatu).
     */
    private function careDuration(SicknessBenefitKind $kind, NempriBenefitApplication $application): void
    {
        if ($application->caredPersonally === null) {
            $this->invalid(
                'nempri_cared_personally_missing',
                'Při trvání nebo ukončení péče musí žádost říct, zda zaměstnanec pečoval osobně.',
            );
        }
        if ($application->careDays === []) {
            $this->invalid(
                'nempri_care_days_missing',
                'Při trvání nebo ukončení péče musí žádost nést dny, kdy zaměstnanec pečoval '
                . '(pecovalVeDnech je podle DV NEMPRI25 povinné). Doplňte je u případu.',
            );
        }
        if ($application->plannedShifts === null) {
            $this->invalid(
                'nempri_planned_shifts_missing',
                'Při trvání nebo ukončení péče musí podklady říct, zda měl zaměstnanec plánované směny.',
            );
        }
        if ($application->plannedShifts === true) {
            if ($kind === SicknessBenefitKind::Ose && $application->plannedShiftsWorked === null) {
                $this->invalid(
                    'nempri_planned_shifts_worked_missing',
                    'Při plánovaných směnách musí podklady říct, zda je zaměstnanec odpracoval.',
                );
            }
            if ($kind === SicknessBenefitKind::Dlo && $application->shiftSchedule === []) {
                $this->invalid(
                    'nempri_shift_schedule_missing',
                    'Při plánovaných směnách musí podklady nést rozvrh směn.',
                );
            }
        }
        if ($kind === SicknessBenefitKind::Dlo) {
            if ($application->hasLeave === null) {
                $this->invalid(
                    'nempri_leave_flag_missing',
                    'U dlouhodobého ošetřovného musí podklady říct, zda měl zaměstnanec pracovní volno.',
                );
            }
            if ($application->hasLeave === true && $application->leavePeriods === []) {
                $this->invalid(
                    'nempri_leave_periods_missing',
                    'Měl-li zaměstnanec pracovní volno, musí podklady nést jeho období.',
                );
            }
            // Hodiny posledního dne a návrat do práce nese věta jen při ukončení.
            if ($application->actionEnd) {
                $this->returnAndLastDayHours($application);
            }
        }
        if ($kind === SicknessBenefitKind::Ose && $application->actionEnd) {
            if ($application->workedLastDay === null) {
                $this->invalid(
                    'nempri_worked_last_day_missing',
                    'Při ukončení péče musí podklady říct, zda zaměstnanec pracoval poslední den péče.',
                );
            }
            if ($application->workedLastDay === true) {
                $this->lastDayHours($application);
            } elseif ($application->shiftHoursLastDay !== null || $application->hoursWorkedLastDay !== null) {
                $this->invalid(
                    'nempri_last_day_hours_without_worked',
                    'Pracovní doba a odpracované hodiny posledního dne se u ošetřovného uvádějí jen tehdy, '
                    . 'když zaměstnanec poslední den péče pracoval.',
                );
            }
        }
    }

    /** Otcovská: hodiny posledního dne, datum návratu a plánované směny jsou svázané. */
    private function paternitySupport(NempriBenefitApplication $application): void
    {
        $this->returnAndLastDayHours($application);
        if ($application->plannedShifts === true && $application->plannedShiftsWorked === null) {
            $this->invalid(
                'nempri_planned_shifts_worked_missing',
                'Při plánovaných směnách musí podklady říct, zda je zaměstnanec odpracoval.',
            );
        }
    }

    /**
     * Otcovská a dlouhodobé ošetřovné (DV NEMPRI25): datum návratu do práce
     * a hodiny posledního dne podpůrčí doby patří k sobě — jedno bez druhého
     * nejde a odpracováno nesmí převýšit pracovní dobu.
     */
    private function returnAndLastDayHours(NempriBenefitApplication $application): void
    {
        $anyHours = $application->shiftHoursLastDay !== null
            || $application->hoursWorkedLastDay !== null;
        if ($application->returnedOn !== null || $anyHours) {
            if ($application->returnedOn === null) {
                $this->invalid(
                    'nempri_return_date_missing',
                    'Jsou-li vyplněny hodiny posledního dne, musí podklady nést i datum návratu do práce.',
                );
            }
            $this->lastDayHours($application);
        }
    }

    private function lastDayHours(NempriBenefitApplication $application): void
    {
        $shift = self::number($application->shiftHoursLastDay);
        $worked = self::number($application->hoursWorkedLastDay);
        if ($shift === null || $worked === null) {
            $this->invalid(
                'nempri_last_day_hours_missing',
                'Podklady musí nést pracovní dobu i počet odpracovaných hodin posledního dne.',
            );
        }
        if ($worked > $shift) {
            $this->invalid(
                'nempri_last_day_hours_exceed',
                'Počet odpracovaných hodin posledního dne nesmí být vyšší než pracovní doba.',
            );
        }
    }

    /**
     * Rozhodné období je u NEM, VPM, OPP, PPM a u OSE/DLO s akcí vznik
     * „povinné vždy“ (DV NEMPRI25). Buď nese úplný seznam měsíců se součty,
     * nebo jen pravděpodobnou výši; obojí najednou ne (kontroly 7, 8, 16).
     */
    private function decisivePeriodRequirement(NempriXmlPayload $payload): void
    {
        if (!NempriXmlSerializer::carriesStartSection($payload)) {
            return;
        }
        $period = $payload->decisivePeriod;
        if ($period === null) {
            $this->invalid(
                'nempri_decisive_period_missing',
                'Věta musí nést rozhodné období (DV NEMPRI25 ho vyžaduje vždy). '
                . 'Doplňte měsíce rozhodného období, nebo pravděpodobnou výši příjmu.',
            );
        }
        $hasProbable = $period->probableIncomeCzk !== null;
        $hasMonths = $period->months !== [];
        if ($hasProbable && $hasMonths) {
            $this->invalid(
                'nempri_decisive_period_probable_with_months',
                'Rozhodné období nese buď měsíce, nebo pravděpodobnou výši příjmu, nikdy obojí.',
            );
        }
        if (!$hasProbable && !$hasMonths) {
            $this->invalid(
                'nempri_decisive_period_empty',
                'Rozhodné období nenese ani měsíce, ani pravděpodobnou výši příjmu.',
            );
        }
        if ($hasProbable) {
            return;
        }
        $expected = [];
        $cursor = new \DateTimeImmutable(substr($period->from, 0, 7) . '-01');
        $last = substr($period->to, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $expected[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        $actual = array_map(
            static fn (NempriDecisiveMonth $month): string => $month->period(),
            $period->months,
        );
        sort($actual, SORT_STRING);
        if (!$period->complete || $actual !== $expected) {
            $this->invalid(
                'nempri_decisive_period_incomplete',
                'Seznam měsíců rozhodného období musí pokrýt celé období ' . $period->from . ' až '
                . $period->to . ' (každý kalendářní měsíc právě jednou), jinak ÚSSZ nespočítá '
                . 'denní vyměřovací základ.',
            );
        }
        $total = 0;
        foreach ($period->months as $month) {
            $total += $month->countableIncomeMinor;
        }
        if ($total <= 0) {
            $this->invalid(
                'nempri_decisive_income_zero',
                'Započitatelný příjem za rozhodné období je nulový. Uveďte místo měsíců '
                . 'pravděpodobnou výši příjmu.',
            );
        }
    }

    /**
     * Platební spojení: u OSE/DLO povinné při vzniku a bez vzniku zakázané,
     * u ostatních druhů povinné (NEM jen pro elektronické číslo rozhodnutí).
     */
    private function paymentConnectionRequirement(NempriXmlPayload $payload): void
    {
        $startsClaim = NempriXmlSerializer::carriesStartSection($payload);
        $requirement = $payload->benefitKind
            ->paymentConnectionRequirement($startsClaim, $payload->decisionNumber);
        if ($requirement === SicknessBenefitKind::DECISION_FORBIDDEN
            && $payload->paymentConnection !== null
        ) {
            $this->invalid(
                'nempri_payment_connection_forbidden',
                'Platební spojení patří jen k akci vznik. U trvání a ukončení ošetřovného '
                . 'ho věta nesmí nést.',
            );
        }
        if ($requirement === SicknessBenefitKind::DECISION_REQUIRED
            && $payload->paymentConnection === null
        ) {
            $this->invalid(
                'nempri_payment_connection_required',
                'Věta musí nést platební spojení (způsob výplaty mzdy). Zaměstnanec nemá ve výplatním '
                . 'profilu účet ani adresu, na kterou by šlo dávku vyplatit; mzda vyplácená přes '
                . 'partnera spojení nenahrazuje. Doplňte účet nebo adresu na kartě osoby.',
            );
        }
    }

    /**
     * Potvrzení zaměstnavatele v HZUPN (DV HZUPN20 v1.12): návrat „ano“ nese
     * datum a hodiny, „ne“ nese důvod, a naopak nic z toho nesmí být navíc.
     *
     * Odpověď na návrat do práce je u hlášení zaměstnavatele (`hlasZamest=A`)
     * povinná: bez ní by `potvrzeniZamestnavatele` vůbec nevzniklo a hlášení by
     * ČSSZ nic nesdělilo. Nevyplněná odpověď proto není „ne“.
     */
    private function hzupnEmployerConfirmation(HzupnXmlPayload $payload): void
    {
        $hours = self::number($payload->hoursWorkedLastDay);
        $shift = self::number($payload->shiftHoursLastDay);
        if ($payload->returnedToWork === null) {
            $this->invalid(
                'hzupn_return_decision_missing',
                'Hlášení zaměstnavatele musí říct, zda se zaměstnanec po neschopnosti vrátil do práce '
                . '(Ano s datem a hodinami posledního dne, nebo Ne s důvodem). Vyberte odpověď u případu.',
            );
        }
        foreach ([$hours, $shift] as $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_DAY_HOURS)) {
                $this->invalid(
                    'hzupn_last_day_hours_out_of_range',
                    'Pracovní doba i odpracované hodiny posledního dne neschopnosti musí být '
                    . 'v rozmezí 0 až 24 hodin.',
                );
            }
        }
        if ($payload->returnedToWork === true) {
            if ($payload->returnedOn === null) {
                $this->invalid(
                    'hzupn_return_date_missing',
                    'Návrat do práce musí mít datum; z něj ČSSZ počítá poslední dávku.',
                );
            }
            if ($hours === null || $shift === null) {
                $this->invalid(
                    'hzupn_hours_missing',
                    'Návrat do práce musí nést počet odpracovaných hodin i pracovní dobu '
                    . 'posledního dne neschopnosti (u zaměstnance, který nepracoval, 0).',
                );
            }
            if ($shift > 0 && $hours <= 0) {
                $this->invalid(
                    'hzupn_worked_hours_zero_with_shift',
                    'Je-li pracovní doba posledního dne větší než 0, nesmí být počet odpracovaných '
                    . 'hodin 0 ani prázdný, ČSSZ takové hlášení zamítne.',
                );
            }
            if ($payload->returnReason !== null) {
                $this->invalid(
                    'hzupn_return_reason_with_return',
                    'Důvod nenávratu se uvádí jen tehdy, když se zaměstnanec do práce nevrátil.',
                );
            }

            return;
        }
        if ($payload->returnedOn !== null) {
            $this->invalid(
                'hzupn_return_date_with_no_return',
                'Datum návratu do práce se uvádí jen tehdy, když se zaměstnanec do práce vrátil '
                . '(DV HZUPN20).',
            );
        }
        if ($hours !== null || $shift !== null) {
            $this->invalid(
                'hzupn_hours_without_return',
                'Hodiny posledního dne se uvádějí jen tehdy, když se zaměstnanec do práce vrátil.',
            );
        }
        if ($payload->returnedToWork === false && $payload->returnReason === null) {
            $this->invalid(
                'hzupn_return_reason_missing',
                'Když se zaměstnanec do práce nevrátil, hlášení musí uvést důvod '
                . '(například nástup na peněžitou pomoc v mateřství nebo skončení zaměstnání).',
            );
        }
    }

    private static function number(?string $value): ?float
    {
        return $value === null || !is_numeric($value) ? null : (float) $value;
    }

    private function person(NempriPerson $person): void
    {
        if (trim($person->firstName) === '' || trim($person->lastName) === '') {
            $this->invalid(
                'nempri_person_name_missing',
                'Dítě nebo ošetřovaná osoba musí mít jméno i příjmení.',
            );
        }
        if ($person->birthNumber !== null
            && preg_match('/^\d{9,10}$/D', $person->birthNumber) !== 1
        ) {
            $this->invalid(
                'nempri_person_birth_number_invalid',
                'Rodné číslo dítěte nebo ošetřované osoby musí mít 9 nebo 10 číslic.',
            );
        }
        // DV NEMPRI25, LK 4: rodné číslo musí projít modulo 11 a nést platné
        // datum. EČP (den zvýšený o 40) se tu neověřuje, prvek ho připouští.
        $birthNumberProblem = self::birthNumberProblem($person->birthNumber);
        if ($birthNumberProblem !== null) {
            $this->invalid(
                'nempri_person_birth_number_invalid',
                'Rodné číslo dítěte nebo ošetřované osoby není platné: '
                    . $birthNumberProblem,
            );
        }
        if ($person->birthNumber === null && $person->birthDate === null) {
            $this->invalid(
                'nempri_person_identifier_missing',
                'Dítě nebo ošetřovaná osoba musí mít rodné číslo nebo alespoň datum narození, '
                . 'jinak ji ČSSZ neztotožní.',
            );
        }
        if ($person->birthDate !== null) {
            $this->exactDate($person->birthDate, 'nempri_date_invalid');
            $this->notInFuture($person->birthDate);
        }
    }

    /**
     * Proč rodné číslo neprojde kontrolou validity (modulo 11 a datum), nebo
     * `null`. EČP (den zvýšený o 40) se neověřuje a hodnota, která nemá 9 až
     * 10 číslic, už má vlastní hlášku.
     */
    private static function birthNumberProblem(?string $value): ?string
    {
        if ($value === null
            || preg_match('/^\d{9,10}$/D', $value) !== 1
            || (int) substr($value, 4, 2) > 40
        ) {
            return null;
        }
        try {
            CzechBirthNumber::normalize($value);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /** VENDOR: název programu 0 až 64 znaků, verze 0 až 16 (DV NEMPRI25, HZUPN20). */
    private function vendor(string $code, string $productName, string $productVersion): void
    {
        if (mb_strlen($productName) > 64 || mb_strlen($productVersion) > 16) {
            $this->invalid(
                $code,
                'Název programu smí mít nejvýš 64 znaků a jeho verze 16 znaků.',
            );
        }
    }

    private function notInFuture(?string $date): void
    {
        $today = PayrollSubmissionCalendar::today();
        if ($date !== null && $date > $today) {
            $this->invalid(
                'nempri_date_in_future',
                'Datum narození dítěte nebo ošetřované osoby ani den převedení na jinou práci nesmí '
                . 'být pozdější než dnešek (' . $date . '). Opravte ho u případu.',
            );
        }
    }

    private function decisivePeriod(NempriDecisivePeriod $period): void
    {
        $this->exactDate($period->from, 'nempri_date_invalid');
        $this->exactDate($period->to, 'nempri_date_invalid');
        if ($period->to < $period->from) {
            $this->invalid(
                'nempri_decisive_period_invalid',
                'Rozhodné období musí končit nejdřív dnem, kterým začíná.',
            );
        }
        if (count($period->months) > 12) {
            $this->invalid(
                'nempri_decisive_period_too_long',
                'Rozhodné období nese nejvýš 12 kalendářních měsíců.',
            );
        }
        foreach ($period->months as $month) {
            if ($month->countableIncomeMinor % 100 !== 0) {
                $this->invalid(
                    'nempri_decisive_amount_not_whole_czk',
                    'Měsíc rozhodného období ' . $month->period() . ' nese příjem v haléřích. '
                    . 'NEMPRI25 přijímá jen celé koruny; zaokrouhlete příjem na celé Kč.',
                );
            }
            $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $month->year, $month->month)))
                ->format('t');
            if ($month->excludedDays < 0 || $month->excludedDays > $daysInMonth
                || $month->countableIncomeMinor < 0
            ) {
                $this->invalid(
                    'nempri_decisive_month_invalid',
                    'Měsíc rozhodného období ' . $month->period()
                    . ' má neplatný příjem nebo počet vyloučených dnů.',
                );
            }
        }
    }

    private function paymentConnection(NempriPaymentConnection $connection): void
    {
        $valid = match ($connection->kind) {
            NempriPaymentConnection::KIND_ACCOUNT_CZ =>
                preg_match('/^\d{2,10}$/D', (string) $connection->accountNumber) === 1
                && preg_match('/^\d{4}$/D', (string) $connection->bankCode) === 1
                && ($connection->accountPrefix === null
                    || preg_match('/^\d{1,6}$/D', $connection->accountPrefix) === 1),
            // Zahraniční účet nesmí mít stát CZ (DV NEMPRI25, ucetZahranicni/stat).
            NempriPaymentConnection::KIND_ACCOUNT_FOREIGN =>
                preg_match('/^[A-Z]{2}[0-9A-Z]{2,32}$/D', (string) $connection->iban) === 1
                && preg_match('/^[0-9A-Z]{1,3}$/D', (string) $connection->countryCode) === 1
                && $connection->countryCode !== 'CZ'
                && !str_starts_with((string) $connection->iban, 'CZ'),
            NempriPaymentConnection::KIND_ADDRESS =>
                trim((string) $connection->city) !== ''
                && preg_match('/^[0-9A-Za-z]{1,4}$/D', (string) $connection->houseNumber) === 1
                && preg_match('/^[0-9A-Za-z]{1,5}$/D', (string) $connection->postalCode) === 1,
            NempriPaymentConnection::KIND_CASH => true,
            default => false,
        };
        if (!$valid) {
            $this->invalid(
                'nempri_payment_connection_invalid',
                'Způsob výplaty mzdy se nedá zapsat do věty: účet musí být platný český '
                . 'účet nebo IBAN, adresa musí mít obec, číslo popisné a PSČ. '
                . 'Opravte ho ve výplatním profilu zaměstnance.',
            );
        }
        // C_KODBANKY (DV NEMPRI25, chyba DIS 06): XSD hlídá jen čtyři číslice,
        // kód mimo registr ČNB odmítne až územní správa. Registr je aktuální
        // kopie, ze které ČSSZ číselník přebírá ({@see CzechBankCodeRegistry}).
        if ($connection->kind === NempriPaymentConnection::KIND_ACCOUNT_CZ
            && !CzechBankCodeRegistry::isValid((string) $connection->bankCode)
        ) {
            $this->invalid(
                'nempri_bank_code_unknown',
                'Kód banky ' . (string) $connection->bankCode . ' výplatního účtu není v aktuálním '
                . 'číselníku kódů platebního styku ČNB (C_KODBANKY), ČSSZ by oznámení odmítla. '
                . 'Opravte účet ve výplatním profilu zaměstnance.',
            );
        }
    }

    private function unpaidLeave(NempriXmlPayload $payload): void
    {
        if ($payload->unpaidLeave && $payload->unpaidLeaveFrom === null) {
            $this->invalid(
                'nempri_unpaid_leave_period_missing',
                'Pracovní volno bez náhrady příjmu musí mít den, od kterého trvalo — '
                . 'z něj se posuzují vyloučené dny.',
            );
        }
        if ($payload->unpaidLeave && $payload->unpaidLeaveTo === null) {
            $this->invalid(
                'nempri_unpaid_leave_end_missing',
                'Pracovní volno bez náhrady příjmu musí mít i den, do kterého trvalo.',
            );
        }
        if (!$payload->unpaidLeave
            && ($payload->unpaidLeaveFrom !== null || $payload->unpaidLeaveTo !== null)
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_without_flag',
                'Období pracovního volna bez náhrady příjmu nesmí být vyplněné, '
                . 'když volno nebylo čerpáno.',
            );
        }
        if ($payload->unpaidLeaveFrom !== null
            && $payload->unpaidLeaveTo !== null
            && $payload->unpaidLeaveTo < $payload->unpaidLeaveFrom
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_invalid',
                'Konec pracovního volna bez náhrady příjmu nesmí předcházet jeho začátku.',
            );
        }
    }

    private function osszCode(int $code): void
    {
        if (!CsszWorkplaceCatalog::acceptsSubmission($code)) {
            $this->invalid(
                'sickness_ossz_code_invalid',
                'Kód OSSZ ' . $code . ' není v číselníku pracovišť ČSSZ C_COKR, nebo ho ČSSZ pro '
                . 'e-podání nepoužívá (101 ústředí). Opravte ho v Nastavení mezd → Zaměstnavatel, '
                . 'případně přímo u případu.',
            );
        }
    }

    private function variableSymbol(string $symbol): void
    {
        if (preg_match('/^[1-9][0-9]{9}$/D', $symbol) !== 1) {
            $this->invalid(
                'sickness_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít deset číslic a nesmí začínat nulou — '
                . 'obě XSD ho mají jako typ N s pevnou délkou 10. Doplnit ho zleva nulami nelze; '
                . 'opravte ho v Nastavení mezd → Účtárny.',
            );
        }
    }

    private function assertSnapshot(
        string $expected,
        string $actual,
        string $code,
        string $message,
    ): void {
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
            $this->invalid($code, $message);
        }
    }

    private function assertSchema(
        string $xml,
        string $documentType,
        string $code,
        string $messagePrefix,
    ): void {
        try {
            $schema = $this->schemas->schemaFor($documentType);
        } catch (\RuntimeException $exception) {
            $this->invalid(
                'sickness_schema_integrity_failed',
                'Připnutý XSD balíček ČSSZ chybí nebo má jiný otisk, než jaký byl ověřen: '
                . $exception->getMessage(),
            );
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            );
            $this->invalid(
                $code,
                $messagePrefix . implode('; ', array_unique($messages)),
            );
        }
    }

    private function exactDate(string $value, string $code): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                $code,
                'Datum v podání musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new SicknessException($code, $message);
    }
}
