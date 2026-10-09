<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriBenefitApplication;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriCodebook;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriod;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPerson;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessPayloadFactory;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * NEMPRI s žádostí o dávku (OSE, OPP, PPM, DLO), rozhodným obdobím a platebním
 * spojením — proti PŘIPNUTÉMU XSD a proti pravidlům, kterými ČSSZ přijatá
 * podání jiných mzdových programů odlišuje od odmítnutých.
 *
 * Všechny osoby, čísla a účty jsou syntetické.
 */
final class NempriBenefitApplicationXmlTest extends TestCase
{
    private SicknessXmlValidator $validator;
    private NempriXmlSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new NempriXmlSerializer();
        $this->validator = new SicknessXmlValidator(
            new CsszSchemaCatalog(),
            $this->serializer,
            new HzupnXmlSerializer(),
        );
    }

    /**
     * Ošetřovné bylo blokované jako „údaje, které zaměstnavatel nedrží“.
     * Zaměstnavatel žádost přijímá a předává (§ 97 odst. 1), takže věta se
     * sestaví — s akcí vznik i ukončení, jak ji ČSSZ od jiných programů přijala.
     */
    public function testCareBenefitWithStartAndEndValidatesAgainstPinnedSchema(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Ose, $this->careApplication());
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<oseVznik>true</oseVznik>', $xml);
        self::assertStringContainsString('<oseTrvani>false</oseTrvani>', $xml);
        self::assertStringContainsString('<oseUkonceni>true</oseUkonceni>', $xml);
        self::assertStringContainsString('<onemocnela>true</onemocnela>', $xml);
        self::assertStringContainsString('<kodRodVztah>PL</kodRodVztah>', $xml);
        self::assertStringContainsString('<odeDne>2026-09-07</odeDne>', $xml);
        self::assertStringContainsString('<rodneCislo>1501010007</rodneCislo>', $xml);
        self::assertStringContainsString('<pecovalVeDnech>', $xml);
        self::assertStringContainsString('<planovaneSmeny>true</planovaneSmeny>', $xml);
        // Potvrzení zaměstnavatele u OSE: převedení PŘED pracovním volnem.
        self::assertLessThan(
            strpos($xml, '<volnoBezNahrady>'),
            strpos($xml, '<prevedenaNaJinouPraci>'),
        );
        self::assertStringContainsString('<rozhodneObdobi>', $xml);
    }

    /**
     * ČSSZ odmítla ošetřovné, které u akce bez vzniku neslo rozhodné období,
     * potvrzení zaměstnavatele a den, od kterého se žádá. U samotného trvání
     * se proto nic z toho nevypisuje.
     */
    public function testCareContinuationOmitsSectionsThatBelongOnlyToStart(): void
    {
        $application = $this->careApplication(
            actionStart: false,
            actionContinuation: true,
            actionEnd: false,
        );
        $payload = $this->payload(SicknessBenefitKind::Ose, $application);
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringNotContainsString('<rozhodneObdobi>', $xml);
        self::assertStringNotContainsString('<potvrzeniZamestnavatele>', $xml);
        self::assertStringNotContainsString('<odeDne>', $xml);
        self::assertStringContainsString('<oseTrvani>true</oseTrvani>', $xml);
    }

    /** Věta bez jediné akce — ČSSZ ji odmítla („Alespoň jedno … musí být true“). */
    public function testCareWithoutAnyActionIsRefused(): void
    {
        $application = $this->careApplication(
            actionStart: false,
            actionContinuation: false,
            actionEnd: false,
        );
        $payload = $this->payload(SicknessBenefitKind::Ose, $application);

        $this->expectRejected('nempri_care_action_missing', $payload);
    }

    public function testCareStartNeedsCaredPersonAndReason(): void
    {
        $withoutPerson = $this->payload(
            SicknessBenefitKind::Ose,
            $this->careApplication(person: false),
        );
        $this->expectRejected('nempri_cared_person_missing', $withoutPerson);

        $withoutReason = $this->payload(
            SicknessBenefitKind::Ose,
            $this->careApplication(careReason: null),
        );
        $this->expectRejected('nempri_care_reason_missing', $withoutReason);
    }

    public function testCareForClosedSchoolCarriesItsName(): void
    {
        $payload = $this->payload(
            SicknessBenefitKind::Ose,
            $this->careApplication(careReason: NempriBenefitApplication::CARE_REASON_SCHOOL_CLOSED, schoolName: 'Základní škola Testov', schoolBusinessId: '12345678'),
        );
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<nazevZarizeniSkoly>Základní škola Testov</nazevZarizeniSkoly>', $xml);
    }

    /**
     * Otcovská: dítě, důvod a plánované směny jsou v XSD povinné. Číslo
     * rozhodnutí otcovská nemá — přijatá podání ho nenesou.
     */
    public function testPaternityBenefitValidatesWithoutDecisionNumber(): void
    {
        $application = new NempriBenefitApplication(
            fromDate: '2026-09-14',
            person: new NempriPerson('Dítě', 'Testovací', null, '2026-09-10'),
            paternityReason: 'OTC',
            plannedShifts: false,
            shiftHoursLastDay: '8',
            hoursWorkedLastDay: '4',
            returnedOn: '2026-09-18',
        );
        $payload = $this->payload(SicknessBenefitKind::Opp, $application, [
            'decisionNumber' => null,
        ]);
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<druhDavky>OPP</druhDavky>', $xml);
        self::assertStringContainsString('<duvodOtcovske>OTC</duvodOtcovske>', $xml);
        self::assertStringContainsString('<datumNarozeni>2026-09-10</datumNarozeni>', $xml);
        self::assertStringNotContainsString('cisloRozhodnuti', $xml);
    }

    public function testPaternityNeedsReason(): void
    {
        $application = new NempriBenefitApplication(
            fromDate: '2026-09-14',
            person: new NempriPerson('Dítě', 'Testovací', null, '2026-09-10'),
            plannedShifts: false,
        );
        $payload = $this->payload(SicknessBenefitKind::Opp, $application, ['decisionNumber' => null]);

        $this->expectRejected('nempri_paternity_reason_missing', $payload);
    }

    public function testMaternityAndLongTermCareValidate(): void
    {
        $ppm = $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
            fromDate: '2026-09-01',
        ), ['decisionNumber' => '1234567M', 'unpaidLeave' => false, 'unpaidLeaveFrom' => null, 'unpaidLeaveTo' => null]);
        $this->validator->validateNempri($ppm, $this->serializer->serialize($ppm));

        $dlo = $this->payload(SicknessBenefitKind::Dlo, new NempriBenefitApplication(
            actionStart: true,
            fromDate: '2026-09-01',
            person: new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
            relationshipCode: '3',
            alternation: false,
            otherMaternityClaim: false,
            sharedHousehold: false,
        ));
        $xml = $this->serializer->serialize($dlo);
        $this->validator->validateNempri($dlo, $xml);

        self::assertStringContainsString('<dloVznik>true</dloVznik>', $xml);
        self::assertStringContainsString('<kodVztah>3</kodVztah>', $xml);
    }

    /**
     * NEMPRI25-ppm.zadost.duvodPece-4: při běžném nástupu na PPM se vyplňuje
     * jen den nástupu; dítě a důvod péče patří jen k převzetí dítěte do péče
     * (Postupy zaměstnavatelů, bod 2; Všeobecné zásady NEMPRI). Dítě bez
     * důvodu převzetí validátor odmítne a továrna payloadu ho do věty nedá.
     */
    public function testStandardMaternityStartCarriesNoChild(): void
    {
        $this->expectRejected(
            'nempri_maternity_child_without_care_reason',
            $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
                fromDate: '2026-09-01',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-08-20'),
                childOrder: 1,
            ), ['decisionNumber' => '1234567M', 'unpaidLeave' => false, 'unpaidLeaveFrom' => null, 'unpaidLeaveTo' => null]),
        );

        $factory = new SicknessPayloadFactory();
        $child = new NempriPerson('Dítě', 'Testovací', null, '2026-08-20');
        $standard = $factory->application(['application_from' => '2026-09-01'], $child, SicknessBenefitKind::Ppm);
        self::assertNull($standard->person);
        $taken = $factory->application(
            ['application_from' => '2026-09-01', 'maternity_care_reason' => '1'],
            $child,
            SicknessBenefitKind::Ppm,
        );
        self::assertSame($child, $taken->person);

        $ppm = $this->payload(SicknessBenefitKind::Ppm, $standard, ['decisionNumber' => '1234567M', 'unpaidLeave' => false, 'unpaidLeaveFrom' => null, 'unpaidLeaveTo' => null]);
        $xml = $this->serializer->serialize($ppm);
        $this->validator->validateNempri($ppm, $xml);
        self::assertStringNotContainsString('<deti>', $xml);
        self::assertStringNotContainsString('<duvodPece>', $xml);
    }

    /**
     * Kódy mimo číselníky ČSSZ. XSD je má jako `StCiselnik`, takže je připnuté
     * schéma propustí — odmítla by je až územní správa. Každý druh dávky má
     * vlastní číselník: „PL“ je platný vztah u ošetřovného, u DLO ne.
     */
    public function testCodesOutsideCsszCodebooksAreRefused(): void
    {
        $this->expectRejected(
            'nempri_relationship_code_invalid',
            $this->payload(SicknessBenefitKind::Ose, $this->careApplication(relationshipCode: 'AB')),
        );
        $this->expectRejected(
            'nempri_relationship_code_invalid',
            $this->payload(SicknessBenefitKind::Dlo, new NempriBenefitApplication(
                actionStart: true,
                fromDate: '2026-09-01',
                person: new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
                relationshipCode: 'PL',
                alternation: false,
                otherMaternityClaim: false,
                sharedHousehold: false,
            )),
        );
        $this->expectRejected(
            'nempri_paternity_reason_invalid',
            $this->payload(SicknessBenefitKind::Opp, new NempriBenefitApplication(
                fromDate: '2026-09-14',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-09-10'),
                paternityReason: '1',
                plannedShifts: false,
            ), ['decisionNumber' => null]),
        );
        $this->expectRejected(
            'nempri_maternity_care_reason_invalid',
            $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
                fromDate: '2026-09-01',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-08-20'),
                maternityCareReason: 'XYZ',
            ), ['decisionNumber' => null, 'unpaidLeave' => false]),
        );
    }

    /**
     * DV NEMPRI25 u `duvodPece`: s důvodem převzetí nesmí věta nést číslo
     * rozhodnutí a musí nést převzaté dítě.
     */
    public function testMaternityCareReasonExcludesDecisionNumberAndNeedsChild(): void
    {
        $valid = $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
            fromDate: '2026-09-01',
            person: new NempriPerson('Dítě', 'Testovací', null, '2026-08-20'),
            maternityCareReason: 'ROZ',
        ), ['decisionNumber' => null, 'unpaidLeave' => false]);
        $xml = $this->serializer->serialize($valid);
        $this->validator->validateNempri($valid, $xml);
        self::assertStringContainsString('<duvodPece>ROZ</duvodPece>', $xml);

        $this->expectRejected(
            'nempri_maternity_care_reason_with_decision_number',
            $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
                fromDate: '2026-09-01',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-08-20'),
                maternityCareReason: 'ROZ',
            ), ['decisionNumber' => 'R123', 'unpaidLeave' => false]),
        );
        $this->expectRejected(
            'nempri_maternity_care_child_missing',
            $this->payload(SicknessBenefitKind::Ppm, new NempriBenefitApplication(
                fromDate: '2026-09-01',
                maternityCareReason: 'ROZ',
            ), ['decisionNumber' => null, 'unpaidLeave' => false]),
        );
    }

    public function testEveryCodebookValueValidatesAgainstPinnedSchema(): void
    {
        foreach (NempriCodebook::FAMILY_RELATIONSHIPS as $code) {
            $payload = $this->payload(SicknessBenefitKind::Ose, $this->careApplication(relationshipCode: $code));
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        foreach (NempriCodebook::CARE_RELATIONSHIPS as $code) {
            $payload = $this->payload(SicknessBenefitKind::Dlo, new NempriBenefitApplication(
                actionStart: true,
                fromDate: '2026-09-01',
                person: new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
                relationshipCode: $code,
                alternation: false,
                otherMaternityClaim: false,
                sharedHousehold: false,
            ));
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        foreach (NempriCodebook::PATERNITY_REASONS as $code) {
            $payload = $this->payload(SicknessBenefitKind::Opp, new NempriBenefitApplication(
                fromDate: '2026-09-14',
                person: new NempriPerson('Dítě', 'Testovací', null, '2026-09-10'),
                paternityReason: $code,
                plannedShifts: false,
            ), ['decisionNumber' => null]);
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
        }
        self::assertCount(29, NempriCodebook::CARE_RELATIONSHIPS);
    }

    /**
     * Rozhodné období s měsíci mimo měsíční hlášení. Úplný seznam nese
     * i součty, částečný ne — součet části by ÚSSZ přečetla jako součet celku.
     */
    public function testDecisivePeriodCarriesMonthsAndTotalsOnlyWhenComplete(): void
    {
        $months = [
            new NempriDecisiveMonth(2025, 11, 3_000_000, 0, NempriDecisiveMonth::SOURCE_TAKEOVER),
            new NempriDecisiveMonth(2025, 12, 3_100_000, 4, NempriDecisiveMonth::SOURCE_MANUAL),
        ];
        $complete = $this->payload(SicknessBenefitKind::Nem, null, [
            'decisivePeriod' => new NempriDecisivePeriod('2025-11-01', '2025-12-31', $months, true),
        ]);
        $xml = $this->serializer->serialize($complete);
        $this->validator->validateNempri($complete, $xml);

        self::assertStringContainsString('<zapocitatelnyPrijem>30000</zapocitatelnyPrijem>', $xml);
        self::assertStringContainsString('<zapocitatelnyPrijem>31000</zapocitatelnyPrijem>', $xml);
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>61000</zapocitatelnyPrijemCelkem>', $xml);
        self::assertStringContainsString('<vylouceneDnyCelkem>4</vylouceneDnyCelkem>', $xml);
        // Rozhodné období leží mezi zaměstnáním a dávkou.
        self::assertLessThan(strpos($xml, '<davka>'), strpos($xml, '<rozhodneObdobi>'));
        self::assertGreaterThan(strpos($xml, '</zamestnani>'), strpos($xml, '<rozhodneObdobi>'));

        // Částečný seznam ČSSZ nepřijme (kontrola 8): bez součtů a bez
        // pravděpodobné výše musí měsíce pokrýt celé období.
        $partial = $this->payload(SicknessBenefitKind::Nem, null, [
            'decisivePeriod' => new NempriDecisivePeriod('2025-11-01', '2026-10-31', $months, false),
        ]);
        $this->expectRejected('nempri_decisive_period_incomplete', $partial);
    }

    public function testProbableIncomeIsSentForShortDecisivePeriod(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Nem, null, [
            'decisivePeriod' => new NempriDecisivePeriod('2026-05-20', '2026-05-31', [], false, 42_000),
        ]);
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<pravdepodobnaVysePrijmu>42000</pravdepodobnaVysePrijmu>', $xml);
        self::assertStringNotContainsString('<seznamObdobi>', $xml);
    }

    /**
     * Způsob výplaty mzdy (§ 97 odst. 2) — bez něj ČSSZ posílá výzvu a dávka
     * se zdrží. Všechny tři tvary musí projít XSD a stát za kontaktním
     * pracovníkem.
     */
    public function testPaymentConnectionShapesValidate(): void
    {
        foreach ([
            new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_CZ,
                accountPrefix: '19',
                accountNumber: '1000000005',
                bankCode: '0100',
            ),
            new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_FOREIGN,
                iban: 'DE89370400440532013000',
                countryCode: 'DE',
            ),
            new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ADDRESS,
                city: 'Testov',
                street: 'Zkušební',
                houseNumber: '123',
                orientationNumber: '4a',
                postalCode: '11000',
            ),
        ] as $connection) {
            $payload = $this->payload(SicknessBenefitKind::Nem, null, [
                'paymentConnection' => $connection,
                'contactWorkerName' => 'Mzdová Účetní',
                'contactWorkerPhone' => '+420 600 000 000',
                'contactWorkerEmail' => 'mzdy@example.test',
            ]);
            $xml = $this->serializer->serialize($payload);

            $this->validator->validateNempri($payload, $xml);

            self::assertGreaterThan(
                strpos($xml, '</kontaktPracovnik>'),
                strpos($xml, '<platebniSpojeni>'),
            );
        }
    }

    /**
     * Mzda v hotovosti: `vyplatitHotovost=true` a ostatní volby `false`, bez
     * adresy (DV NEMPRI25 ji váže jen na `vyplatitAdresa`). ÚSSZ si způsob
     * výplaty dávky vyžádá od pojištěnce (Všeobecné zásady NEMPRI 2025).
     */
    public function testCashWageSendsCashFlagWithoutAddress(): void
    {
        $payload = $this->payload(SicknessBenefitKind::Nem, null, [
            'paymentConnection' => new NempriPaymentConnection(NempriPaymentConnection::KIND_CASH),
        ]);
        $xml = $this->serializer->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<vyplatitHotovost>true</vyplatitHotovost>', $xml);
        self::assertStringContainsString('<vyplatitAdresa>false</vyplatitAdresa>', $xml);
        self::assertStringContainsString('<vyplatitUcetCR>false</vyplatitUcetCR>', $xml);
        self::assertStringNotContainsString('<adresa>', $xml);
    }

    private function expectRejected(string $code, NempriXmlPayload $payload): void
    {
        try {
            $this->validator->validateNempri($payload, $this->serializer->serialize($payload));
            self::fail('Validace měla odmítnout větu s kódem ' . $code . '.');
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    private function careApplication(
        bool $actionStart = true,
        bool $actionContinuation = false,
        bool $actionEnd = true,
        bool $person = true,
        ?string $careReason = NempriBenefitApplication::CARE_REASON_ILL,
        ?string $schoolName = null,
        string $relationshipCode = 'PL',
        ?string $schoolBusinessId = null,
    ): NempriBenefitApplication {
        return new NempriBenefitApplication(
            actionStart: $actionStart,
            actionContinuation: $actionContinuation,
            actionEnd: $actionEnd,
            fromDate: '2026-09-07',
            toDate: '2026-09-11',
            person: $person ? new NempriPerson('Dítě', 'Testovací', '1501010007', null) : null,
            careReason: $careReason,
            schoolName: $schoolName,
            schoolBusinessId: $schoolBusinessId,
            sharedHousehold: true,
            loneCaregiver: false,
            childUnder16: true,
            otherMaternityClaim: false,
            caredPersonally: true,
            careDays: [['from' => '2026-09-07', 'to' => '2026-09-11']],
            relationshipCode: $relationshipCode,
            workedLastDay: false,
            plannedShifts: true,
            plannedShiftsWorked: false,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function payload(
        SicknessBenefitKind $kind,
        ?NempriBenefitApplication $application,
        array $overrides = [],
    ): NempriXmlPayload {
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
            'paymentConnection' => $kind->hasActions() && !($application?->actionStart ?? true)
                ? null
                : new NempriPaymentConnection(
                    NempriPaymentConnection::KIND_ACCOUNT_CZ,
                    accountPrefix: '19',
                    accountNumber: '1000000005',
                    bankCode: '0100',
                ),
        ];

        return new NempriXmlPayload(...[...$values, ...$overrides]);
    }

    /** Úplné rozhodné období: 12 kalendářních měsíců se součty. */
    private static function fullPeriod(): NempriDecisivePeriod
    {
        $months = [];
        foreach (['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'] as $period) {
            $months[] = new NempriDecisiveMonth(
                (int) substr($period, 0, 4),
                (int) substr($period, 5, 2),
                3_000_000,
                0,
                NempriDecisiveMonth::SOURCE_TAKEOVER,
            );
        }

        return new NempriDecisivePeriod('2025-09-01', '2026-08-31', $months, true);
    }
}
