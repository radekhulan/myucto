<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Bank\CzechBankCodeRegistry;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\CsszWorkplaceCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriBenefitApplication;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriCodebook;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriod;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnectionResolver;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPerson;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDecisionNumber;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessInsuredContactReader;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessPayloadFactory;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Maticová pravidla DV NEMPRI25 a HZUPN20, která XSD neumí vyjádřit
 * (všechno je v něm `minOccurs=0`): akce ošetřovného, podmíněné prvky
 * potvrzení, číslo rozhodnutí, rozhodné období, platební spojení a HZUPN.
 *
 * Zelené XSD nic nedokazuje, proto testy kontrolují přítomnost a pořadí
 * prvků v XML a kódy odmítnutí. Všechna data jsou syntetická.
 */
final class NempriMatrixAndValidationTest extends TestCase
{
    private SicknessXmlValidator $validator;
    private NempriXmlSerializer $serializer;
    private HzupnXmlSerializer $hzupn;

    protected function setUp(): void
    {
        $this->serializer = new NempriXmlSerializer();
        $this->hzupn = new HzupnXmlSerializer();
        $this->validator = new SicknessXmlValidator(
            new CsszSchemaCatalog(),
            $this->serializer,
            $this->hzupn,
        );
    }

    // ---- NX-01: matice akcí OSE a DLO -------------------------------------

    public function testCareStartOnlyOmitsDurationElementsEvenWhenCaseHasEndDate(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Ose, $this->care(
            actionStart: true,
            actionEnd: false,
            toDate: '2026-09-11',
        ));
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        foreach (['doDne', 'pecovalOsobne', 'pecovalVeDnech', 'podkladyProVyplatDavky', 'pracovalPoslDenPD', 'planovaneSmeny', 'seznamPraceVeDnech'] as $element) {
            self::assertStringNotContainsString('<' . $element, $xml, $element);
        }
        self::assertStringContainsString('<odeDne>2026-09-07</odeDne>', $xml);
        self::assertStringContainsString('<osetrovanaOsoba>', $xml);
        self::assertStringContainsString('<platebniSpojeni>', $xml);
    }

    public function testCareEndOnlyCarriesDurationElementsAndNoStartElements(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Ose, $this->care(actionStart: false, actionEnd: true));
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        foreach (['odeDne', 'osetrovanaOsoba', 'onemocnela', 'spolecnaDomacnost', 'jeOsamely', 'vPeciDiteDo16Let', 'narokNaPPMjinouOsobou', 'kodRodVztah', 'potvrzeniZamestnavatele', 'rozhodneObdobi', 'platebniSpojeni'] as $element) {
            self::assertStringNotContainsString('<' . $element, $xml, $element);
        }
        foreach (['doDne', 'pecovalOsobne', 'pecovalVeDnech', 'pracovalPoslDenPD', 'planovaneSmeny', 'planovaneSmenyOdpracoval', 'seznamPraceVeDnech'] as $element) {
            self::assertStringContainsString('<' . $element, $xml, $element);
        }
        self::assertStringContainsString('<oseUkonceni>true</oseUkonceni>', $xml);
        self::assertStringContainsString('<oseVznik>false</oseVznik>', $xml);
    }

    public function testCareContinuationDoesNotCarryLastDayElements(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Ose, $this->care(
            actionStart: false,
            actionContinuation: true,
            actionEnd: false,
        ));
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<doDne>', $xml);
        self::assertStringNotContainsString('pracovalPoslDenPD', $xml);
        self::assertStringNotContainsString('pracovniDobaPoslDenPD', $xml);
    }

    public function testOtherClaimDetailsAreSentOnlyWhenOtherPersonClaims(): void
    {
        $without = $this->payload(SicknessBenefitKind::Ose, $this->care(otherMaternityClaim: false));
        $xml = $this->serializer->serialize($without);
        $this->validator->validateNempri($without, $xml);
        self::assertStringNotContainsString('narokNaRPjinaOsobaNecerpaVolnoNeboOSVC', $xml);
        self::assertStringNotContainsString('jinaFOParagraf57', $xml);

        $with = $this->payload(SicknessBenefitKind::Ose, $this->care(
            otherMaternityClaim: true,
            otherParentalClaim: false,
            otherPersonS57: false,
        ));
        $xml = $this->serializer->serialize($with);
        $this->validator->validateNempri($with, $xml);
        self::assertStringContainsString('<narokNaRPjinaOsobaNecerpaVolnoNeboOSVC>false<', $xml);
        self::assertStringContainsString('<jinaFOParagraf57>false<', $xml);

        $this->expectRejected('nempri_other_claim_details_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(otherMaternityClaim: true),
        ));
    }

    public function testCareDurationRequiresEndDateLastDayAndShiftFlags(): void
    {
        $this->expectRejected('nempri_application_to_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionContinuation: true, actionEnd: false, toDate: null),
        ));
        $this->expectRejected('nempri_worked_last_day_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, workedLastDay: null),
        ));
        $this->expectRejected('nempri_planned_shifts_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, plannedShifts: null),
        ));
        $this->expectRejected('nempri_planned_shifts_worked_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, plannedShifts: true, plannedShiftsWorked: null),
        ));
        $this->expectRejected('nempri_cared_personally_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, caredPersonally: null),
        ));
        // Pracoval-li poslední den, musí nést hodiny; odpracováno nesmí přesáhnout pracovní dobu.
        $this->expectRejected('nempri_last_day_hours_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, workedLastDay: true, shiftHoursLastDay: null, hoursWorkedLastDay: null),
        ));
        $this->expectRejected('nempri_last_day_hours_exceed', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, workedLastDay: true, shiftHoursLastDay: '4', hoursWorkedLastDay: '8'),
        ));
    }

    public function testSchoolClosureNeedsBusinessId(): void
    {
        $this->expectRejected('nempri_school_business_id_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(careReason: NempriBenefitApplication::CARE_REASON_SCHOOL_CLOSED, schoolName: 'Základní škola Testov'),
        ));
    }

    // ---- NX-02: platební spojení ------------------------------------------

    public function testPaymentConnectionCarriesAllFourFlags(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Nem, null);
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<vyplatitUcetCR>true</vyplatitUcetCR>', $xml);
        self::assertStringContainsString('<vyplatitUcetCizina>false</vyplatitUcetCizina>', $xml);
        self::assertStringContainsString('<vyplatitAdresa>false</vyplatitAdresa>', $xml);
        self::assertStringContainsString('<vyplatitHotovost>false</vyplatitHotovost>', $xml);
        self::assertLessThan(strpos($xml, '<ucetCZ>'), strpos($xml, '<vyplatitHotovost>'));
    }

    public function testPaymentConnectionIsForbiddenWithoutCareStartAndRequiredWithIt(): void
    {
        $withConnection = $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true),
            ['paymentConnection' => $this->account()],
        );
        $this->expectRejected('nempri_payment_connection_forbidden', $withConnection);

        $startWithout = $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(),
            ['paymentConnection' => null],
        );
        $this->expectRejected('nempri_payment_connection_required', $startWithout);
    }

    public function testPaymentConnectionIsRequiredForElectronicSicknessNumber(): void
    {
        $this->expectRejected('nempri_payment_connection_required', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisionNumber' => '2601011234', 'paymentConnection' => null],
        ));
        // Papírové číslo (písmeno a číslice) spojení nevyžaduje.
        $legacy = $this->payload(SicknessBenefitKind::Nem, null, ['paymentConnection' => null]);
        $this->validator->validateNempri($legacy, $this->serializer->serialize($legacy));
        self::assertTrue(true);
    }

    public function testPaymentConnectionIsRequiredForOtherBenefitKinds(): void
    {
        $this->expectRejected('nempri_payment_connection_required', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(
                fromDate: '2026-09-14',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-09-10'),
                paternityReason: 'OTC',
                plannedShifts: false,
            ),
            ['paymentConnection' => null],
        ));
    }

    // ---- NX-03: DLO podklady ----------------------------------------------

    public function testDloEndCarriesShiftScheduleAndLeaveInSchemaOrder(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Dlo, $this->dlo(actionStart: false, actionEnd: true));
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        $order = ['<pracovniDobaPoslDenPD>', '<pocetOdpracHodinPoslDenPD>', '<datumNavratDoPrace>', '<planovaneSmeny>', '<seznamRozvrhuSmen>', '<seznamPraceVeDnech>', '<maVolno>', '<pracovniVolno>'];
        $previous = -1;
        foreach ($order as $element) {
            $position = strpos($xml, $element);
            self::assertNotFalse($position, $element);
            self::assertGreaterThan($previous, $position, $element);
            $previous = $position;
        }
        self::assertStringContainsString('<maVolno>true</maVolno>', $xml);
    }

    public function testDloStartHasNoPaymentFreeSectionsAndNoSupportData(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Dlo, $this->dlo());
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        foreach (['podkladyProVyplatDavky', 'maVolno', 'seznamRozvrhuSmen', 'pracovniVolno', 'doDne'] as $element) {
            self::assertStringNotContainsString('<' . $element, $xml, $element);
        }
    }

    public function testDloEndRequiresLeaveFlagAndSchedules(): void
    {
        $this->expectRejected('nempri_leave_flag_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, hasLeave: null),
        ));
        $this->expectRejected('nempri_leave_periods_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, leavePeriods: []),
        ));
        $this->expectRejected('nempri_shift_schedule_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, shiftSchedule: []),
        ));
    }

    /**
     * NEMPRI25-dlo.zadost.jeStridani-2 (a narokNaPPMjinouOsobou-2,
     * spolecnaDomacnost-2): u DLO s akcí vznik jsou prohlášení povinná,
     * nevyplněná se uvádí jako „NE“ stejně jako u ošetřovného.
     */
    public function testDloStartDeclarationsDefaultToNoAndMustBePresent(): void
    {
        $application = (new SicknessPayloadFactory())->application(
            ['action_start' => 1, 'action_end' => 0],
            new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
            SicknessBenefitKind::Dlo,
        );
        self::assertFalse($application->alternation);
        self::assertFalse($application->otherMaternityClaim);
        self::assertFalse($application->sharedHousehold);
        self::assertNull($application->loneCaregiver, 'Osamělost DLO nenese.');
        self::assertNull($application->caredPersonally);
        $claim = (new SicknessPayloadFactory())->application(
            ['action_start' => 1, 'other_maternity_claim' => 1],
            null,
            SicknessBenefitKind::Dlo,
        );
        self::assertFalse($claim->otherPersonS57);
        $end = (new SicknessPayloadFactory())->application(['action_start' => 0, 'action_end' => 1], null, SicknessBenefitKind::Dlo);
        self::assertNull($end->alternation);
        self::assertFalse($end->caredPersonally);

        $payload = $this->payload(SicknessBenefitKind::Dlo, $this->dlo());
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);
        foreach (['<jeStridani>false<', '<narokNaPPMjinouOsobou>false<', '<spolecnaDomacnost>true<'] as $element) {
            self::assertStringContainsString($element, $xml);
        }
        $this->expectRejected('nempri_care_declaration_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(alternation: null),
        ));
        $this->expectRejected('nempri_care_declaration_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(sharedHousehold: null),
        ));
    }

    /**
     * NEMPRI25-ose.zadost.pecovalVeDnech-2 (a dlo.zadost.pecovalVeDnech-2):
     * při trvání nebo ukončení jsou dny péče povinné.
     */
    public function testCareDaysAreRequiredForContinuationAndEnd(): void
    {
        $this->expectRejected('nempri_care_days_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionEnd: true, careDays: []),
        ));
        $this->expectRejected('nempri_care_days_missing', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionContinuation: true, careDays: []),
        ));
        $this->expectRejected('nempri_care_days_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, careDays: []),
        ));
        $start = $this->payload(SicknessBenefitKind::Ose, $this->care(careDays: []));
        $this->validator->validateNempri($start, $this->serializer->serialize($start));
        self::assertTrue(true);
    }

    /**
     * NEMPRI25-ose.podklady.pracovniDobaPoslDenPD-4: u ošetřovného jsou hodiny
     * posledního dne bez `pracovalPoslDenPD = true` zakázané — ve validátoru,
     * v serializaci i v továrně.
     */
    public function testCareLastDayHoursOnlyWhenEmployeeWorkedLastDay(): void
    {
        $stale = $this->payload(SicknessBenefitKind::Ose, $this->care(
            actionStart: false,
            actionEnd: true,
            workedLastDay: false,
            shiftHoursLastDay: '8',
        ));
        self::assertStringNotContainsString('pracovniDobaPoslDenPD', $this->serializer->serialize($stale));
        $this->expectRejected('nempri_last_day_hours_without_worked', $stale);

        $factory = new SicknessPayloadFactory();
        $notWorked = $factory->application([
            'action_start' => 0,
            'action_end' => 1,
            'worked_last_day' => 0,
            'shift_hours_last_day' => '8.00',
            'hours_worked_last_day' => '2.00',
        ], null, SicknessBenefitKind::Ose);
        self::assertNull($notWorked->shiftHoursLastDay);
        self::assertNull($notWorked->hoursWorkedLastDay);
        $worked = $factory->application([
            'action_start' => 0,
            'action_end' => 1,
            'worked_last_day' => 1,
            'shift_hours_last_day' => '8.00',
            'hours_worked_last_day' => '2.00',
        ], null, SicknessBenefitKind::Ose);
        self::assertSame('8.00', $worked->shiftHoursLastDay);
        // Otcovská a DLO váží hodiny na návrat do práce, ne na `pracovalPoslDenPD`.
        $dlo = $factory->application(['action_end' => 1, 'shift_hours_last_day' => '8.00'], null, SicknessBenefitKind::Dlo);
        self::assertSame('8.00', $dlo->shiftHoursLastDay);
    }

    /**
     * NEMPRI25-dlo.podklady.pracovniDobaPoslDenPD-3: u DLO jsou hodiny
     * posledního dne povinné s datem návratu do práce a naopak, odpracováno
     * nesmí převýšit pracovní dobu.
     */
    public function testDloLastDayHoursGoWithReturnDate(): void
    {
        $this->expectRejected('nempri_last_day_hours_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, shiftHoursLastDay: null, hoursWorkedLastDay: null),
        ));
        $this->expectRejected('nempri_return_date_missing', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, returnedOn: null),
        ));
        $this->expectRejected('nempri_last_day_hours_exceed', $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, shiftHoursLastDay: '4', hoursWorkedLastDay: '6'),
        ));
        $none = $this->payload(
            SicknessBenefitKind::Dlo,
            $this->dlo(actionStart: false, actionEnd: true, returnedOn: null, shiftHoursLastDay: null, hoursWorkedLastDay: null),
        );
        $this->validator->validateNempri($none, $this->serializer->serialize($none));
        self::assertTrue(true);
    }

    /**
     * NEMPRI25-LK-13: datum narození dítěte nebo ošetřované osoby a den
     * převedení na jinou práci nesmí být pozdější než dnešek.
     */
    public function testBirthAndTransferDatesMustNotBeInTheFuture(): void
    {
        $future = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->expectRejected('nempri_date_in_future', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['startsMaternity' => true, 'childBirthDate' => $future],
        ));
        $this->expectRejected('nempri_date_in_future', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['transferredOtherWork' => true, 'transferredOn' => $future],
        ));
        $this->expectRejected('nempri_date_in_future', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(
                fromDate: '2026-09-14',
                person: new NempriPerson('Dítě', 'Testovací', null, $future),
                paternityReason: 'OTC',
                plannedShifts: false,
            ),
        ));
        $today = $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['startsMaternity' => true, 'childBirthDate' => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d')],
        );
        $this->validator->validateNempri($today, $this->serializer->serialize($today));
        self::assertTrue(true);
    }

    /**
     * NEMPRI25-dokument.opravnePodani-2: opravné podání jde podat u každého
     * druhu dávky; číslo rozhodnutí chce jen tam, kde je ho druh povinně nese.
     */
    public function testCorrectionWithoutDecisionNumberIsAllowedWhereKindHasNone(): void
    {
        $child = new NempriPerson('Dítě', 'Testovací', null, '2026-08-20');
        foreach ([
            [SicknessBenefitKind::Vpm, null],
            [SicknessBenefitKind::Ppm, new NempriBenefitApplication(fromDate: '2026-09-01', person: $child, maternityCareReason: 'DOH')],
            [SicknessBenefitKind::Opp, new NempriBenefitApplication(fromDate: '2026-09-14', person: $child, paternityReason: 'OTC', plannedShifts: false)],
        ] as [$kind, $application]) {
            $payload = $this->payload($kind, $application, ['correction' => true, 'decisionNumber' => null]);
            $xml = $this->serializer->serialize($payload);
            $this->validator->validateNempri($payload, $xml);
            self::assertStringContainsString('<opravnePodani>true</opravnePodani>', $xml, $kind->value);
        }
        $this->expectRejected('nempri_correction_without_decision_number', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['correction' => true, 'decisionNumber' => null],
        ));
        $foreign = $this->payload(SicknessBenefitKind::Nem, null, ['correction' => true, 'decisionNumber' => null, 'foreignCase' => true]);
        $this->validator->validateNempri($foreign, $this->serializer->serialize($foreign));
    }

    /** NEMPRI25-platebniSpojeni.ucetZahranicni.stat-3: zahraniční účet nesmí mít stát CZ. */
    public function testForeignAccountMustNotBeCzech(): void
    {
        $this->expectRejected('nempri_payment_connection_invalid', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['paymentConnection' => new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_FOREIGN,
                iban: 'CZ6508000000192000145399',
                countryCode: 'CZ',
            )],
        ));
        try {
            (new NempriPaymentConnectionResolver())->fromAccount('CZ65 0800 0000 1920 0014');
            self::fail('Český IBAN v neplatném tvaru není zahraniční účet.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_invalid', $exception->validationCode);
        }
        $slovak = (new NempriPaymentConnectionResolver())->fromAccount('SK31 1200 0000 1987 4263 7541');
        self::assertSame('SK', $slovak->countryCode);
        $payload = $this->payload(SicknessBenefitKind::Nem, null, ['paymentConnection' => $slovak]);
        $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
    }

    /**
     * NEMPRI25-platebniSpojeni.ucetCZ.bankaKod-3: kód banky z číselníku
     * C_KODBANKY (registr ČNB). Čtyři číslice projdou XSD, kód zaniklé banky
     * (eBanka 2400, Equa bank 6100) ani neexistující kód ČSSZ nepřijme.
     */
    public function testCzechAccountBankCodeMustComeFromCnbRegistry(): void
    {
        foreach (['2400', '6100', '9999'] as $code) {
            $this->expectRejected('nempri_bank_code_unknown', $this->payload(
                SicknessBenefitKind::Nem,
                null,
                ['paymentConnection' => new NempriPaymentConnection(
                    NempriPaymentConnection::KIND_ACCOUNT_CZ,
                    accountNumber: '1000000005',
                    bankCode: $code,
                )],
            ));
        }
        foreach (['0100', '0800', '6363', '3030'] as $code) {
            $payload = $this->payload(SicknessBenefitKind::Nem, null, ['paymentConnection' => new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_CZ,
                accountNumber: '1000000005',
                bankCode: $code,
            )]);
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        self::assertSame('Partners Banka, a.s.', CzechBankCodeRegistry::name('6363'));
    }

    /** Registr kódů bank je beze změny převzatý soubor ČNB a parser ho čte celý. */
    public function testBankCodeRegistryParsesCnbFile(): void
    {
        $codes = CzechBankCodeRegistry::codes();
        self::assertGreaterThanOrEqual(40, count($codes));
        self::assertSame('KOMBCZPP', $codes['0100']['bic']);
        self::assertNull($codes['2100']['bic']);
        self::assertFalse(CzechBankCodeRegistry::isValid('100'));
        $this->expectException(\RuntimeException::class);
        CzechBankCodeRegistry::parse("<html>chyba</html>\r\n");
    }

    // ---- NX-04: potvrzení zaměstnavatele ----------------------------------

    public function testEmployerConfirmationConditionalElements(): void
    {
        $cases = [
            'nempri_worked_hours_missing' => ['workedOnDecisiveDay' => true, 'hoursWorked' => null, 'dailyWorkingHours' => '8'],
            'nempri_hours_without_worked' => ['workedOnDecisiveDay' => false, 'hoursWorked' => '4', 'dailyWorkingHours' => null],
            'nempri_worked_hours_exceed_working_time' => ['workedOnDecisiveDay' => true, 'hoursWorked' => '9', 'dailyWorkingHours' => '8'],
            'nempri_school_holidays_missing' => ['isStudent' => true, 'withinSchoolHolidays' => null],
            'nempri_school_holidays_without_student' => ['isStudent' => false, 'withinSchoolHolidays' => true],
            'nempri_pension_kind_missing' => ['receivesPension' => true, 'pensionKind' => null],
            'nempri_pension_kind_without_pension' => ['receivesPension' => false, 'pensionKind' => 'SD'],
            'nempri_child_birth_missing' => ['startsMaternity' => true, 'childBirthDate' => null],
            'nempri_child_birth_without_maternity' => ['startsMaternity' => false, 'childBirthDate' => '2026-08-01'],
            'nempri_unpaid_leave_end_missing' => ['unpaidLeave' => true, 'unpaidLeaveFrom' => '2026-08-01', 'unpaidLeaveTo' => null],
        ];
        foreach ($cases as $code => $overrides) {
            $this->expectRejected($code, $this->payload(SicknessBenefitKind::Nem, null, $overrides));
        }
        $this->expectRejected('nempri_child_birth_missing', $this->payload(
            SicknessBenefitKind::Ppm,
            new NempriBenefitApplication(fromDate: '2026-09-01', person: new NempriPerson('Dítě', 'Testovací', null, '2026-08-20')),
            ['decisionNumber' => '1234567M', 'startsMaternity' => true, 'childBirthDate' => null],
        ));
    }

    public function testEmployerConfirmationOmitsChildElementsWithoutParentFlag(): void
    {
        // Zbylé hodnoty bez nadřazeného příznaku (validátor je odmítne) se do věty nedostanou.
        $stale = $this->payload(SicknessBenefitKind::Nem, null, [
            'workedOnDecisiveDay' => false,
            'hoursWorked' => '4',
            'dailyWorkingHours' => '8',
            'receivesPension' => false,
            'pensionKind' => 'SD',
            'isStudent' => false,
            'withinSchoolHolidays' => true,
            'unpaidLeave' => false,
            'unpaidLeaveFrom' => '2026-08-01',
            'unpaidLeaveTo' => '2026-08-05',
            'startsMaternity' => false,
            'childBirthDate' => '2026-08-01',
        ]);
        $staleXml = $this->serializer->serialize($stale);
        foreach (['pocetOdpracovanychHodin', '<pracovniDoba>', 'druhDuchodu', 'spadaDoPrazdnin', 'volnoBezNahradyOd', 'volnoBezNahradyDo', 'narozeniDitete'] as $element) {
            self::assertStringNotContainsString($element, $staleXml, $element);
        }
        $payload = $this->payload(SicknessBenefitKind::Nem, null, [
            'workedOnDecisiveDay' => false,
            'hoursWorked' => null,
            'dailyWorkingHours' => null,
        ]);
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        $student = $this->payload(SicknessBenefitKind::Nem, null, [
            'isStudent' => true,
            'withinSchoolHolidays' => true,
            'unpaidLeave' => true,
            'unpaidLeaveFrom' => '2026-08-01',
            'unpaidLeaveTo' => '2026-08-05',
        ]);
        $xml = $this->serializer->serialize($student);
        $this->validator->validateNempri($student, $xml);
        self::assertStringContainsString('<spadaDoPrazdnin>true</spadaDoPrazdnin>', $xml);
        self::assertStringContainsString('<volnoBezNahradyDo>2026-08-05</volnoBezNahradyDo>', $xml);
    }

    /**
     * NEMPRI25-nem.potv.druhDuchodu-3: druh důchodu je kód CIS_DRUHDUCH_NEM.
     * XSD pustí cokoli z `[0-9A-Z]{1,3}`, takže kód z číselníku přihlášky
     * (1, 2, 8) by odmítla až územní správa.
     */
    public function testPensionKindMustComeFromSicknessCodebook(): void
    {
        foreach (['1', '2', '8', 'SD'] as $foreign) {
            $this->expectRejected('nempri_pension_kind_invalid', $this->payload(
                SicknessBenefitKind::Nem,
                null,
                ['receivesPension' => true, 'pensionKind' => $foreign],
            ));
        }
        $this->expectRejected('nempri_pension_kind_invalid', $this->payload(
            SicknessBenefitKind::Vpm,
            null,
            ['receivesPension' => true, 'pensionKind' => '1'],
        ));
        foreach (NempriCodebook::PENSION_KINDS as $code) {
            $payload = $this->payload(SicknessBenefitKind::Nem, null, ['receivesPension' => true, 'pensionKind' => $code]);
            $xml = $this->serializer->serialize($payload);
            $this->validator->validateNempri($payload, $xml);
            self::assertStringContainsString('<druhDuchodu>' . $code . '</druhDuchodu>', $xml);
        }
    }

    public function testFactoryDropsDailyWorkingHoursWhenEmployeeDidNotWork(): void
    {
        $payload = (new SicknessPayloadFactory())->nempri(
            $this->caseRow(['worked_on_decisive_day' => 0, 'daily_working_hours' => '8.00']),
            SicknessBenefitKind::Nem,
            $this->context(),
            $this->identity(),
            '1.0',
            'MyUcto',
            '1.0',
        );
        self::assertNull($payload->dailyWorkingHours);

        $worked = (new SicknessPayloadFactory())->nempri(
            $this->caseRow(['worked_on_decisive_day' => 1, 'daily_working_hours' => '8.00', 'hours_worked' => '4.00']),
            SicknessBenefitKind::Nem,
            $this->context(),
            $this->identity(),
            '1.0',
            'MyUcto',
            '1.0',
        );
        self::assertSame('8.00', $worked->dailyWorkingHours);
    }

    // ---- NX-05: HZUPN ------------------------------------------------------

    public function testHzupnReturnRules(): void
    {
        $cases = [
            'hzupn_hours_missing' => ['returnedToWork' => true, 'hoursWorkedLastDay' => null, 'shiftHoursLastDay' => null],
            'hzupn_worked_hours_zero_with_shift' => ['returnedToWork' => true, 'hoursWorkedLastDay' => '0', 'shiftHoursLastDay' => '8'],
            'hzupn_return_reason_with_return' => ['returnedToWork' => true, 'returnReason' => 'jiný důvod'],
            'hzupn_return_date_with_no_return' => ['returnedToWork' => false, 'returnReason' => 'konec', 'returnedOn' => '2026-08-24', 'hoursWorkedLastDay' => null, 'shiftHoursLastDay' => null],
            'hzupn_hours_without_return' => ['returnedToWork' => false, 'returnReason' => 'konec', 'returnedOn' => null],
        ];
        foreach ($cases as $code => $overrides) {
            $payload = $this->hzupnPayload($overrides);
            try {
                $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
                self::fail($code);
            } catch (SicknessException $exception) {
                self::assertSame($code, $exception->validationCode);
            }
        }
    }

    public function testHzupnAcceptsReturnWithZeroHoursAndNoShift(): void
    {
        // Referenční hlášení jiných programů nesou „A“ s 0/0.
        $payload = $this->hzupnPayload(['hoursWorkedLastDay' => '0', 'shiftHoursLastDay' => '0']);
        $xml = $this->hzupn->serialize($payload);
        $this->validator->validateHzupn($payload, $xml, '2026-08-03');
        self::assertStringContainsString('<pracovniDobaPoslDenPD>0</pracovniDobaPoslDenPD>', $xml);
    }

    public function testHzupnSerializerDropsElementsThatDoNotBelongToTheAnswer(): void
    {
        $payload = $this->hzupnPayload([
            'returnedToWork' => false,
            'returnReason' => 'konec',
            'returnedOn' => '2026-08-24',
        ]);
        $xml = $this->hzupn->serialize($payload);
        self::assertStringNotContainsString('datumNavratDoPrace', $xml);
        self::assertStringNotContainsString('pocetOdpracHodinPoslDenPD', $xml);
        self::assertStringContainsString('<duvodNavratDoPrace>konec</duvodNavratDoPrace>', $xml);
    }

    public function testHzupnFactoryKeepsDateAndHoursOnlyForReturn(): void
    {
        $row = $this->caseRow([
            'issued_on' => '2026-08-24',
            'returned_to_work' => 0,
            'return_reason' => 'konec',
            'returned_on' => '2026-08-24',
            'hours_worked_last_day' => '4.00',
            'shift_hours_last_day' => '8.00',
        ]);
        $no = (new SicknessPayloadFactory())->hzupn($row, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0');
        self::assertNull($no->returnedOn);
        self::assertNull($no->hoursWorkedLastDay);
        self::assertSame('konec', $no->returnReason);

        $yes = (new SicknessPayloadFactory())->hzupn(
            [...$row, 'returned_to_work' => 1],
            $this->context(),
            $this->identity(),
            '1.0',
            'MyUcto',
            '1.0',
        );
        self::assertSame('2026-08-24', $yes->returnedOn);
        self::assertNull($yes->returnReason);
    }

    /**
     * HZUPN20-potvrzeniZamestnavatele-1 a duvodNavratDoPrace-2: hlášení
     * zaměstnavatele musí odpovědět na návrat do práce a důvod nenávratu
     * patří jen k odpovědi „ne“, nikdy k nevyplněné.
     */
    public function testHzupnRequiresReturnAnswerAndReasonOnlyForNo(): void
    {
        $this->expectHzupnRejected('hzupn_return_decision_missing', [
            'returnedToWork' => null,
            'returnedOn' => null,
            'hoursWorkedLastDay' => null,
            'shiftHoursLastDay' => null,
        ]);

        $unanswered = $this->hzupnPayload([
            'returnedToWork' => null,
            'returnReason' => 'konec',
            'returnedOn' => null,
            'hoursWorkedLastDay' => null,
            'shiftHoursLastDay' => null,
        ]);
        self::assertStringNotContainsString('duvodNavratDoPrace', $this->hzupn->serialize($unanswered));

        $row = $this->caseRow([
            'issued_on' => '2026-08-24',
            'returned_to_work' => null,
            'return_reason' => 'konec',
        ]);
        $payload = (new SicknessPayloadFactory())->hzupn($row, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0');
        self::assertNull($payload->returnReason);
    }

    /** HZUPN20-pracovniDobaPoslDenPD-3, pocetOdpracHodinPoslDenPD-3: hodiny 0 až 24. */
    public function testHzupnLastDayHoursStayWithinOneDay(): void
    {
        $this->expectHzupnRejected('hzupn_last_day_hours_out_of_range', ['shiftHoursLastDay' => '25', 'hoursWorkedLastDay' => '8']);
        $this->expectHzupnRejected('hzupn_last_day_hours_out_of_range', ['shiftHoursLastDay' => '30', 'hoursWorkedLastDay' => '25']);
        $full = $this->hzupnPayload(['shiftHoursLastDay' => '24', 'hoursWorkedLastDay' => '24']);
        $this->validator->validateHzupn($full, $this->hzupn->serialize($full), '2026-08-03');
        self::assertTrue(true);
    }

    /** HZUPN20-LK3-1: den vystavení po 31. 12. 2019. */
    public function testHzupnIssueDateMustFollowYear2019(): void
    {
        $this->expectHzupnRejected('hzupn_issue_date_too_early', ['issuedOn' => '2019-12-31']);
        $first = $this->hzupnPayload(['issuedOn' => '2020-01-01']);
        $this->validator->validateHzupn($first, $this->hzupn->serialize($first), '2019-12-20');
        self::assertTrue(true);
    }

    /**
     * HZUPN20-LK30-1: kontrola 30 zní „DO > OD“, ale jednodenní práce má od = do.
     * Rovnost je povolená záměrně (komentář u validátoru); obrácený interval ne.
     */
    public function testHzupnSingleDayWorkIntervalIsAllowedButReversedIsNot(): void
    {
        $single = $this->hzupnPayload(['workIntervals' => [['from' => '2026-08-10', 'to' => '2026-08-10']]]);
        $xml = $this->hzupn->serialize($single);
        $this->validator->validateHzupn($single, $xml, '2026-08-03');
        self::assertStringContainsString('<pracovalOd>2026-08-10</pracovalOd>', $xml);
        $this->expectHzupnRejected('hzupn_work_interval_invalid', [
            'workIntervals' => [['from' => '2026-08-11', 'to' => '2026-08-10']],
        ]);
    }

    /**
     * HZUPN20-LK33-1 a cisloPotvrzeni-3: HZUPN nese číslo rozhodnutí ve formátu
     * od r. 2020 (ČPN s písmenem E až Z kromě K, PČ od 2001010000, i s IČPE).
     */
    public function testHzupnConfirmationNumberUsesPost2020Format(): void
    {
        foreach (['A1234567', 'D123456', 'K1234567', '1912310001', '12345', 'E12345678'] as $number) {
            $this->expectHzupnRejected('hzupn_confirmation_number_format_invalid', ['confirmationNumber' => $number]);
        }
        $this->expectHzupnRejected('hzupn_confirmation_number_icpe_invalid', ['confirmationNumber' => '12345675' . '2601010001']);
        foreach (['E1234567', 'Z123456', 'L7654321', '2001010000', '6000000001', '12345674' . '2601010001'] as $number) {
            $payload = $this->hzupnPayload(['confirmationNumber' => $number]);
            $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
        }
        // Rozhodnutí mimo český systém tvar českého čísla mít nemusí.
        foreach (['foreignCase', 'slovakCase'] as $flag) {
            $payload = $this->hzupnPayload(['confirmationNumber' => 'SK-77/2026', $flag => true]);
            $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
        }
        $lower = (new SicknessPayloadFactory())->hzupn(
            $this->caseRow(['issued_on' => '2026-08-24', 'returned_to_work' => 1, 'returned_on' => '2026-08-24']),
            $this->context(),
            $this->identity(),
            '1.0',
            'MyUcto',
            '1.0',
        );
        self::assertSame('E1234567', $lower->confirmationNumber);
    }

    /** NEMPRI25-dokument.kodOSSZ-3 a HZUPN20-kodOSSZ-3: kód z C_COKR, 101 ne. */
    public function testOsszCodeMustComeFromWorkplaceCodebook(): void
    {
        foreach ([101, 100, 999, 120] as $code) {
            $this->expectRejected('sickness_ossz_code_invalid', $this->payload(SicknessBenefitKind::Nem, null, ['osszCode' => $code]));
            $this->expectHzupnRejected('sickness_ossz_code_invalid', ['osszCode' => $code]);
        }
        self::assertTrue(CsszWorkplaceCatalog::acceptsSubmission(115));
        self::assertFalse(CsszWorkplaceCatalog::acceptsSubmission(101));
        self::assertSame('Praha 5', CsszWorkplaceCatalog::nameFor(115));
        $payload = $this->payload(SicknessBenefitKind::Nem, null, ['osszCode' => 772]);
        $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
    }

    /**
     * NEMPRI25-ERR-22: IČPE předsazené číslu rozhodnutí musí projít kontrolou
     * 8. číslice (Luhn). Platí pro všechny druhy dávky, i pro NEM, jehož
     * elektronické číslo s IČPE (18 číslic) je zároveň „elektronické“ pro
     * povinné platební spojení.
     */
    public function testDecisionNumberIcpeMustPassLuhnCheck(): void
    {
        $this->expectRejected('nempri_decision_number_icpe_invalid', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(),
            ['decisionNumber' => '12345675' . '260101001N'],
        ));
        $this->expectRejected('nempri_decision_number_icpe_invalid', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisionNumber' => '12345675' . '2601011234'],
        ));
        $this->expectRejected('nempri_payment_connection_required', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisionNumber' => '12345674' . '2601011234', 'paymentConnection' => null],
        ));
        foreach ([
            [SicknessBenefitKind::Ose, $this->care(), '12345674' . '260101001N'],
            [SicknessBenefitKind::Nem, null, '12345674' . '2601011234'],
        ] as [$kind, $application, $number]) {
            $payload = $this->payload($kind, $application, ['decisionNumber' => $number]);
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        self::assertTrue(SicknessDecisionNumber::icpeChecksumValid('12345674'));
        self::assertFalse(SicknessDecisionNumber::icpeChecksumValid('12345675'));
    }

    // ---- NX-06: číslo rozhodnutí ------------------------------------------

    public function testDecisionNumberPerBenefitKind(): void
    {
        $partial = new NempriBenefitApplication(fromDate: '2026-09-01');
        $this->expectRejected('nempri_decision_number_missing', $this->payload(SicknessBenefitKind::Ppm, $partial, ['decisionNumber' => null]));
        $this->expectRejected('nempri_decision_number_forbidden', $this->payload(SicknessBenefitKind::Vpm, null, ['decisionNumber' => 'A1234567']));
        $this->expectRejected('nempri_decision_number_format_invalid', $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(),
            ['decisionNumber' => 'A1234567'],
        ));
        $this->expectRejected('nempri_decision_number_format_invalid', $this->payload(SicknessBenefitKind::Nem, null, ['decisionNumber' => '12345']));

        foreach (['1234567N', '1234567Z'] as $number) {
            $payload = $this->payload(SicknessBenefitKind::Ose, $this->care(), ['decisionNumber' => $number]);
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        $icpe = $this->payload(SicknessBenefitKind::Ose, $this->care(), ['decisionNumber' => '123456789012345N']);
        $this->validator->validateNempri($icpe, $this->serializer->serialize($icpe));
        $ppm = $this->payload(SicknessBenefitKind::Ppm, $partial, ['decisionNumber' => '1234567M']);
        $this->validator->validateNempri($ppm, $this->serializer->serialize($ppm));
        $electronic = $this->payload(SicknessBenefitKind::Nem, null, ['decisionNumber' => '2601011234']);
        $this->validator->validateNempri($electronic, $this->serializer->serialize($electronic));
        self::assertTrue(true);
    }

    public function testForeignCaseSkipsDecisionNumberFormat(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Nem, null, ['decisionNumber' => 'SK-77/2026', 'foreignCase' => true]);
        $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        self::assertTrue(true);
    }

    /**
     * NX-06 (W2): jediné pravidlo čísla rozhodnutí, které volá validátor věty
     * i předkontrola služby před sestavením věty.
     */
    public function testDecisionNumberProblemIsOneRuleForValidatorAndPrecheck(): void
    {
        $problem = static fn (SicknessBenefitKind $kind, ?string $number, bool $care = false, bool $foreign = false): ?string
            => $kind->decisionNumberProblem($number, $care, $foreign)['code'] ?? null;

        self::assertSame('nempri_decision_number_missing', $problem(SicknessBenefitKind::Ppm, null));
        self::assertNull($problem(SicknessBenefitKind::Ppm, null, true), 'PPM s důvodem převzetí číslo nemá.');
        self::assertNull($problem(SicknessBenefitKind::Ppm, '1234567M', true), 'Zákaz u PPM s důvodem hlídá žádost o dávku.');
        self::assertSame('nempri_decision_number_forbidden', $problem(SicknessBenefitKind::Vpm, 'A1234567'));
        self::assertSame('nempri_decision_number_format_invalid', $problem(SicknessBenefitKind::Ose, 'A1234567'));
        self::assertNull($problem(SicknessBenefitKind::Ose, '1234567N'));
        self::assertNull($problem(SicknessBenefitKind::Ose, '1234567490' . '1234567N'), 'ICPE + číslo + písmeno.');
        self::assertSame('nempri_decision_number_missing', $problem(SicknessBenefitKind::Nem, null));
        self::assertNull($problem(SicknessBenefitKind::Nem, null, false, true), 'Zahraniční případ číslo mít nemusí.');
        self::assertNull($problem(SicknessBenefitKind::Opp, null));
    }

    /**
     * NX-08 (W2): slovenská DPN. NEMPRI hlásí `zahranicni` pro případ mimo
     * Česko (i Slovensko), HZUPN „A" jen mimo Česko a Slovensko.
     */
    public function testSlovakCaseIsForeignForNempriButNotForHzupn(): void
    {
        $row = $this->caseRow([
            'slovak_case' => 1,
            'issued_on' => '2026-08-24',
            'returned_to_work' => 1,
            'returned_on' => '2026-08-24',
            'hours_worked_last_day' => '4.00',
            'shift_hours_last_day' => '8.00',
        ]);
        $factory = new SicknessPayloadFactory();

        $nempri = $factory->nempri($row, SicknessBenefitKind::Nem, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0');
        $hzupn = $factory->hzupn($row, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0');

        self::assertTrue($nempri->foreignCase);
        self::assertFalse($hzupn->foreignCase);
        self::assertTrue($hzupn->slovakCase);
        self::assertStringContainsString('<zahranicni>true</zahranicni>', $this->serializer->serialize($nempri));
        self::assertStringContainsString('<zahranicni>N</zahranicni>', $this->hzupn->serialize($hzupn));

        $abroad = [...$row, 'slovak_case' => 0, 'foreign_case' => 1];
        self::assertTrue($factory->nempri($abroad, SicknessBenefitKind::Nem, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0')->foreignCase);
        self::assertTrue($factory->hzupn($abroad, $this->context(), $this->identity(), '1.0', 'MyUcto', '1.0')->foreignCase);
    }

    public function testSlovakCaseMayOmitTheHzupnConfirmationNumber(): void
    {
        $slovak = $this->hzupnPayload(['confirmationNumber' => null, 'slovakCase' => true]);
        $this->validator->validateHzupn($slovak, $this->hzupn->serialize($slovak), '2026-08-03');

        $czech = $this->hzupnPayload(['confirmationNumber' => null]);
        try {
            $this->validator->validateHzupn($czech, $this->hzupn->serialize($czech), '2026-08-03');
            self::fail('Český případ bez čísla rozhodnutí musí selhat.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_confirmation_number_missing', $exception->validationCode);
        }
    }

    // ---- NX-07: OPP podklady ----------------------------------------------

    public function testPaternityLastDayHoursAndReturnDateGoTogether(): void
    {
        $base = ['fromDate' => '2026-09-14', 'paternityReason' => 'OTC', 'plannedShifts' => false];
        $child = new NempriPerson('Dítě', 'Testovací', null, '2026-09-10');

        $this->expectRejected('nempri_last_day_hours_missing', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(...[...$base, 'person' => $child, 'returnedOn' => '2026-09-18']),
        ));
        $this->expectRejected('nempri_return_date_missing', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(...[...$base, 'person' => $child, 'shiftHoursLastDay' => '8', 'hoursWorkedLastDay' => '4']),
        ));
        $this->expectRejected('nempri_last_day_hours_exceed', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(...[...$base, 'person' => $child, 'shiftHoursLastDay' => '4', 'hoursWorkedLastDay' => '8', 'returnedOn' => '2026-09-18']),
        ));
        $this->expectRejected('nempri_planned_shifts_worked_missing', $this->payload(
            SicknessBenefitKind::Opp,
            new NempriBenefitApplication(...[...$base, 'person' => $child, 'plannedShifts' => true]),
        ));

        $valid = $this->payload(SicknessBenefitKind::Opp, new NempriBenefitApplication(
            ...[...$base, 'person' => $child, 'plannedShifts' => true, 'plannedShiftsWorked' => false, 'shiftHoursLastDay' => '8', 'hoursWorkedLastDay' => '4', 'returnedOn' => '2026-09-18'],
        ));
        $xml = $this->serializer->serialize($valid);
        $this->validator->validateNempri($valid, $xml);
        self::assertStringContainsString('<planovaneSmenyOdpracoval>false<', $xml);
    }

    // ---- NRO-01 / NRO-05: rozhodné období ---------------------------------

    public function testDecisivePeriodIsMandatoryAndCoherent(): void
    {
        $this->expectRejected('nempri_decisive_period_missing', $this->payload(SicknessBenefitKind::Nem, null, ['decisivePeriod' => null]));
        $this->expectRejected('nempri_decisive_period_empty', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod('2026-05-01', '2026-05-31', [], false)],
        ));

        $months = self::months('2025-09', 12);
        $this->expectRejected('nempri_decisive_period_probable_with_months', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod('2025-09-01', '2026-08-31', $months, true, 42_000)],
        ));
        $this->expectRejected('nempri_decisive_period_incomplete', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod('2025-09-01', '2026-08-31', array_slice($months, 0, 3), false)],
        ));
        $this->expectRejected('nempri_decisive_period_incomplete', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod('2025-09-01', '2026-08-31', array_slice($months, 0, 3), true)],
        ));
        $this->expectRejected('nempri_decisive_income_zero', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod(
                '2025-09-01',
                '2025-09-30',
                [new NempriDecisiveMonth(2025, 9, 0, 0, NempriDecisiveMonth::SOURCE_MANUAL)],
                true,
            )],
        ));
    }

    public function testDecisivePeriodWithProbableIncomeOnlyIsValid(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Nem, null, [
            'decisivePeriod' => new NempriDecisivePeriod('2026-05-20', '2026-05-31', [], false, 42_000),
        ]);
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);
        self::assertStringContainsString('<pravdepodobnaVysePrijmu>42000<', $xml);
        self::assertStringNotContainsString('Celkem', $xml);
    }

    public function testDecisivePeriodRefusesCentsAndExcessExcludedDays(): void
    {
        $this->expectRejected('nempri_decisive_amount_not_whole_czk', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod(
                '2025-09-01',
                '2025-09-30',
                [new NempriDecisiveMonth(2025, 9, 2_560_645, 0, NempriDecisiveMonth::SOURCE_MANUAL)],
                true,
            )],
        ));
        $this->expectRejected('nempri_decisive_month_invalid', $this->payload(
            SicknessBenefitKind::Nem,
            null,
            ['decisivePeriod' => new NempriDecisivePeriod(
                '2026-02-01',
                '2026-02-28',
                [new NempriDecisiveMonth(2026, 2, 3_000_000, 29, NempriDecisiveMonth::SOURCE_MANUAL)],
                true,
            )],
        ));
    }

    public function testCareContinuationDoesNotNeedDecisivePeriod(): void
    {
        $payload = $this->payload(
            SicknessBenefitKind::Ose,
            $this->care(actionStart: false, actionContinuation: true, actionEnd: false),
            ['decisivePeriod' => null],
        );
        $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        self::assertTrue(true);
    }

    // ---- K2: kontakt pojištěnce a název OSSZ ------------------------------

    public function testInsuredContactComesFromPersonCardAndFollowsSchemaOrder(): void
    {
        $payload = (new SicknessPayloadFactory())->nempri(
            $this->caseRow(['worked_on_decisive_day' => 0]),
            SicknessBenefitKind::Nem,
            $this->context(),
            $this->identity(),
            '1.0',
            'MyUcto',
            '1.0',
            null,
            self::fullPeriod(),
            $this->account(),
            ['phone' => '+420 600 000 000', 'email' => 'jan@example.test'],
        );
        self::assertSame('+420 600 000 000', $payload->insuredPhone);
        $xml = $this->serializer->serialize($payload);
        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<kontakt>', $xml);
        self::assertLessThan(strpos($xml, '<email>'), strpos($xml, '<telefon>'));
        self::assertLessThan(strpos($xml, '<zamestnani>'), strpos($xml, '<kontakt>'));
    }

    public function testContactNormalisationFollowsSchemaPatterns(): void
    {
        self::assertSame('+420 600 000 000', SicknessInsuredContactReader::phone('+420 (600) 000  000'));
        self::assertNull(SicknessInsuredContactReader::phone('žádný telefon!'));
        self::assertNull(SicknessInsuredContactReader::phone(str_repeat('1', 34)));
        self::assertSame('jan@example.test', SicknessInsuredContactReader::email(' jan@example.test '));
        self::assertNull(SicknessInsuredContactReader::email('neplatny@bezteckyvdomene'));
        self::assertNull(SicknessInsuredContactReader::email(null));
    }

    public function testHzupnCarriesOsszNameWhenKnownAndNeverInventsOne(): void
    {
        $payload = $this->hzupnPayload(['osszName' => 'Testovací pracoviště']);
        $xml = $this->hzupn->serialize($payload);
        $this->validator->validateHzupn($payload, $xml, '2026-08-03');
        self::assertStringContainsString('<nazevOSSZ>Testovací pracoviště</nazevOSSZ>', $xml);
        self::assertLessThan(strpos($xml, '<datumVystaveni>'), strpos($xml, '<nazevOSSZ>'));

        // Kód mimo číselník v repozitáři název nedostane.
        self::assertNull(CsszWorkplaceCatalog::nameFor(999));
        $tooLong = $this->hzupnPayload(['osszName' => str_repeat('x', 31)]);
        try {
            $this->validator->validateHzupn($tooLong, $this->hzupn->serialize($tooLong), '2026-08-03');
            self::fail('Název delší než 30 znaků.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_ossz_name_too_long', $exception->validationCode);
        }
    }

    // ---- factory: DLO pole, výchozí „ne“ podle akce -------------------------

    public function testFactoryMapsDloSupportColumnsAndDefaultsByAction(): void
    {
        $factory = new SicknessPayloadFactory();
        $dlo = $factory->application([
            'action_start' => 0,
            'action_end' => 1,
            'dlo_has_leave' => 1,
            'dlo_leave_periods' => '[{"from":"2026-09-02","to":"2026-09-04"}]',
            'dlo_shift_schedule' => [['from' => '2026-09-07', 'to' => '2026-09-11']],
        ], null, SicknessBenefitKind::Dlo);
        self::assertTrue($dlo->hasLeave);
        self::assertSame([['from' => '2026-09-02', 'to' => '2026-09-04']], $dlo->leavePeriods);
        self::assertSame([['from' => '2026-09-07', 'to' => '2026-09-11']], $dlo->shiftSchedule);

        // Sloupce před migrací: bez nich se nic nerozbije.
        $legacy = $factory->application(['action_start' => 1], null, SicknessBenefitKind::Dlo);
        self::assertNull($legacy->hasLeave);
        self::assertSame([], $legacy->leavePeriods);

        // Ošetřovné: u vzniku „ne“ jen pro prohlášení vzniku, u ukončení jen pro osobní péči.
        $start = $factory->application(['action_start' => 1, 'action_end' => 0], null, SicknessBenefitKind::Ose);
        self::assertFalse($start->sharedHousehold);
        self::assertNull($start->caredPersonally);
        $end = $factory->application(['action_start' => 0, 'action_end' => 1], null, SicknessBenefitKind::Ose);
        self::assertNull($end->sharedHousehold);
        self::assertFalse($end->caredPersonally);
        $claim = $factory->application(['action_start' => 1, 'other_maternity_claim' => 1], null, SicknessBenefitKind::Ose);
        self::assertTrue($claim->otherMaternityClaim);
        self::assertFalse($claim->otherParentalClaim);
        self::assertFalse($claim->otherPersonS57);
    }

    // ---- helpery ------------------------------------------------------------

    private function expectRejected(string $code, NempriXmlPayload $payload): void
    {
        try {
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
            self::fail('Validace měla odmítnout větu s kódem ' . $code . '.');
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function expectHzupnRejected(string $code, array $overrides): void
    {
        $payload = $this->hzupnPayload($overrides);
        try {
            $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
            self::fail('Validace měla odmítnout hlášení s kódem ' . $code . '.');
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    private function account(): NempriPaymentConnection
    {
        return new NempriPaymentConnection(
            NempriPaymentConnection::KIND_ACCOUNT_CZ,
            accountPrefix: '19',
            accountNumber: '1000000005',
            bankCode: '0100',
        );
    }

    /** @return list<NempriDecisiveMonth> */
    private static function months(string $from, int $count): array
    {
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $date = (new \DateTimeImmutable($from . '-01'))->modify('+' . $i . ' months');
            $months[] = new NempriDecisiveMonth(
                (int) $date->format('Y'),
                (int) $date->format('n'),
                3_000_000,
                0,
                NempriDecisiveMonth::SOURCE_TAKEOVER,
            );
        }

        return $months;
    }

    private static function fullPeriod(): NempriDecisivePeriod
    {
        return new NempriDecisivePeriod('2025-09-01', '2026-08-31', self::months('2025-09', 12), true);
    }

    private function care(
        bool $actionStart = true,
        bool $actionContinuation = false,
        bool $actionEnd = false,
        ?string $toDate = '2026-09-11',
        ?string $careReason = NempriBenefitApplication::CARE_REASON_ILL,
        ?string $schoolName = null,
        ?bool $otherMaternityClaim = false,
        ?bool $otherParentalClaim = null,
        ?bool $otherPersonS57 = null,
        ?bool $caredPersonally = true,
        ?bool $workedLastDay = false,
        ?bool $plannedShifts = true,
        ?bool $plannedShiftsWorked = false,
        ?string $shiftHoursLastDay = null,
        ?string $hoursWorkedLastDay = null,
        array $careDays = [['from' => '2026-09-07', 'to' => '2026-09-11']],
        ?bool $sharedHousehold = true,
    ): NempriBenefitApplication {
        return new NempriBenefitApplication(
            actionStart: $actionStart,
            actionContinuation: $actionContinuation,
            actionEnd: $actionEnd,
            fromDate: '2026-09-07',
            toDate: $toDate,
            person: new NempriPerson('Dítě', 'Testovací', '1501010005', null),
            careReason: $careReason,
            schoolName: $schoolName,
            sharedHousehold: $sharedHousehold,
            loneCaregiver: false,
            childUnder16: true,
            otherMaternityClaim: $otherMaternityClaim,
            otherParentalClaim: $otherParentalClaim,
            otherPersonS57: $otherPersonS57,
            caredPersonally: $caredPersonally,
            careDays: $careDays,
            relationshipCode: 'PL',
            workedLastDay: $workedLastDay,
            shiftHoursLastDay: $shiftHoursLastDay,
            hoursWorkedLastDay: $hoursWorkedLastDay,
            plannedShifts: $plannedShifts,
            plannedShiftsWorked: $plannedShiftsWorked,
            workDays: [['from' => '2026-09-08', 'to' => '2026-09-08']],
        );
    }

    /**
     * @param list<array{from:string,to:string}> $leavePeriods
     * @param list<array{from:string,to:string}> $shiftSchedule
     */
    private function dlo(
        bool $actionStart = true,
        bool $actionEnd = false,
        ?bool $hasLeave = true,
        array $leavePeriods = [['from' => '2026-09-02', 'to' => '2026-09-04']],
        array $shiftSchedule = [['from' => '2026-09-07', 'to' => '2026-09-11']],
        ?bool $alternation = false,
        ?string $returnedOn = '2026-10-01',
        ?string $shiftHoursLastDay = '8',
        ?string $hoursWorkedLastDay = '0',
        array $careDays = [['from' => '2026-09-01', 'to' => '2026-09-30']],
    ): NempriBenefitApplication {
        return new NempriBenefitApplication(
            actionStart: $actionStart,
            actionEnd: $actionEnd,
            fromDate: '2026-09-01',
            toDate: '2026-09-30',
            person: new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
            sharedHousehold: true,
            otherMaternityClaim: false,
            caredPersonally: true,
            careDays: $careDays,
            relationshipCode: '3',
            alternation: $alternation,
            shiftHoursLastDay: $shiftHoursLastDay,
            hoursWorkedLastDay: $hoursWorkedLastDay,
            plannedShifts: true,
            returnedOn: $returnedOn,
            workDays: [['from' => '2026-09-14', 'to' => '2026-09-14']],
            hasLeave: $hasLeave,
            leavePeriods: $leavePeriods,
            shiftSchedule: $shiftSchedule,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function payload(
        SicknessBenefitKind $kind,
        ?NempriBenefitApplication $application,
        array $overrides = [],
    ): NempriXmlPayload {
        $starts = !$kind->hasActions() || ($application?->actionStart ?? true);
        $values = [
            'benefitKind' => $kind,
            'osszCode' => 115,
            'correction' => false,
            'decisionNumber' => match ($kind) {
                SicknessBenefitKind::Nem => 'A1234567',
                SicknessBenefitKind::Ose => '1234567N',
                SicknessBenefitKind::Dlo => '1234567L',
                default => null,
            },
            'foreignCase' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Testovací',
            'insuredBirthNumber' => '8001010008',
            'insuredPhone' => null,
            'insuredEmail' => null,
            'employerVariableSymbol' => '1234567890',
            'employerIdentificationNumber' => '12345678',
            'employerName' => 'Testovací zaměstnavatel s.r.o.',
            'employmentFrom' => '2020-01-01',
            'employmentTo' => null,
            'activityCode' => '1',
            'workedOnDecisiveDay' => false,
            'hoursWorked' => null,
            'dailyWorkingHours' => null,
            'smallScopeIncomeMinor' => null,
            'receivesPension' => false,
            'pensionKind' => null,
            'isStudent' => false,
            'withinSchoolHolidays' => null,
            'firstEmploymentFreeTime' => false,
            'unpaidLeave' => false,
            'unpaidLeaveFrom' => null,
            'unpaidLeaveTo' => null,
            'startsMaternity' => null,
            'childBirthDate' => null,
            'transferredOtherWork' => false,
            'transferredOn' => null,
            'enforcement' => false,
            'insolvency' => false,
            'additionalNote' => null,
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '1.0',
            'application' => $application,
            'decisivePeriod' => self::fullPeriod(),
            'paymentConnection' => $starts ? $this->account() : null,
        ];

        return new NempriXmlPayload(...[...$values, ...$overrides]);
    }

    /** @param array<string,mixed> $overrides */
    private function hzupnPayload(array $overrides = []): HzupnXmlPayload
    {
        $values = [
            'employerReport' => true,
            'personReport' => false,
            'foreignCase' => false,
            'confirmationNumber' => 'E1234567',
            'osszCode' => 115,
            'osszName' => null,
            'issuedOn' => '2026-08-24',
            'correction' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Testovací',
            'insuredTitle' => null,
            'insuredBirthNumber' => '8001010008',
            'insuredBirthDate' => '1980-01-01',
            'employerName' => 'Testovací zaměstnavatel s.r.o.',
            'employerIdentificationNumber' => '12345678',
            'employerVariableSymbol' => '1234567890',
            'returnedToWork' => true,
            'returnReason' => null,
            'returnedOn' => '2026-08-24',
            'hoursWorkedLastDay' => '4',
            'shiftHoursLastDay' => '8',
            'workIntervals' => [],
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '20201.01',
        ];

        return new HzupnXmlPayload(...[...$values, ...$overrides]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function caseRow(array $overrides = []): array
    {
        return [...[
            'ossz_code' => 115,
            'correction' => 0,
            'decision_number' => 'e1234567',
            'foreign_case' => 0,
            'worked_on_decisive_day' => 0,
            'hours_worked' => null,
            'daily_working_hours' => null,
            'small_scope_income_minor' => null,
            'receives_pension' => 0,
            'is_student' => 0,
            'first_employment_free_time' => 0,
            'unpaid_leave' => 0,
            'transferred_other_work' => 0,
            'enforcement' => 0,
            'insolvency' => 0,
            'work_days' => [],
        ], ...$overrides];
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return [
            'start_date' => '2020-01-01',
            'end_date' => null,
            'employer_business_id' => '12345678',
            'employer_name' => 'Testovací zaměstnavatel s.r.o.',
            'employer_variable_symbol' => '1234567890',
            'activity_code' => '1',
        ];
    }

    /** @return array<string,mixed> */
    private function identity(): array
    {
        return [
            'identity' => ['first_name' => 'Jan', 'last_name' => 'Testovací', 'birth_date' => '1980-01-01'],
            'identifiers' => ['birth_number' => '8001010008', 'ecp' => null],
        ];
    }
}
