<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSicknessCaseAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Nemocenské dávky cestou účetní, od absence po datovou větu:
 *
 * 1. schválená neschopnost založí případ NEMPRI s lhůtou, navazující absence
 *    ho prodlouží, hlídač termínů ukáže NEMPRI i HZUPN (od dne nástupu),
 * 2. neschopnost po skončení zaměstnání projde jen v ochranné lhůtě § 15,
 * 3. ošetřovné ze schváleného OČR nese vztah z číselníku CIS_RODVZTAH,
 * 4. dlouhodobé ošetřovné odmítnuté zaměstnavatelem (§ 191a ZP) se nepředá,
 * 5. změna zdravotní pojišťovny na kartě osoby vydá HOZ oběma pojišťovnám
 *    (odhláška „O", přihláška „P").
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollSicknessFullFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('NEM', 'Syntetická účtárna dávek', '9990007777');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        // Identifikátory zaměstnavatele pro ČSSZ žijí v Mzdách (VS u účtárny,
        // kód OSSZ v nastavení zaměstnavatele), ne na firmě.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testApprovedIncapacityCreatesCaseWithDeadlinesAndSubmissions(): void
    {
        $person = $this->sicknessPerson(1, 'Jana Nemocná');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $dpn = ['first_day_fully_worked' => false, 'insurance_eligibility_confirmed' => true, 'conflicting_benefit_excluded' => true];

        // 8. až 19. 6. je 12 dnů: celé je kryje náhrada mzdy (§ 192 ZP),
        // nemocenské by náleželo až od 15. dne (§ 26 odst. 1), případ nevzniká.
        $first = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-19', (int) $average['id'], $dpn);
        self::assertNull($first['sickness_case'], json_encode($first['sickness_case']) ?: '');

        // Neschopnost zapsaná po částech je jedna událost: prodloužení ji
        // dotáhne přes 14. den a teprve teď vznikne případ — od prvního dne.
        $second = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-20', '2026-06-22', (int) $average['id'], $dpn);
        self::assertSame('created', $second['sickness_case']['outcome'], json_encode($second['sickness_case']) ?: '');
        self::assertSame('NEM', $second['sickness_case']['benefit_kind']);
        // § 97 odst. 2: neprodleně po uplynutí prvních 14 dnů, tedy od 22. 6.
        self::assertSame('2026-06-22', $second['sickness_case']['nempri_due_on']);
        $caseId = (int) $second['sickness_case']['case_id'];

        $third = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-23', '2026-06-26', (int) $average['id'], $dpn);
        self::assertSame('extended', $third['sickness_case']['outcome']);
        self::assertSame($caseId, (int) $third['sickness_case']['case_id']);

        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('2026-06-08', $case['incapacity_from']);
        self::assertSame('2026-06-26', $case['incapacity_to']);
        self::assertSame((int) $first['absence']['id'], (int) $case['absence_id']);

        $this->cashPayout($person['employee_id']);
        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'E1234567',
            'daily_working_hours' => '8',
            'issued_on' => '2026-06-29',
            'returned_to_work' => '1',
            'returned_on' => '2026-06-29',
            'hours_worked_last_day' => '4',
            'shift_hours_last_day' => '8',
            // Tok nemá mzdové běhy za leden až květen; věta rozhodné období
            // nese vždy celé, takže se měsíce doplní u případu.
            'decisive_months' => self::manualMonths('2026-01', '2026-05'),
        ]);

        $overview = $this->service(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        $due = [];
        foreach ($overview['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $due[$item['title']] = $item['due_on'];
            }
        }
        self::assertSame(['HZUPN' => '2026-06-29', 'NEMPRI' => '2026-06-22'], $this->sorted($due));

        $submissions = $this->service(SicknessSubmissionService::class);
        $nempri = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<druhDavky>NEM</druhDavky>', $nempri);
        self::assertStringContainsString('<cisloRozhodnuti>E1234567</cisloRozhodnuti>', $nempri);
        self::assertStringContainsString('<rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd>', $nempri);
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>200000</zapocitatelnyPrijemCelkem>', $nempri);
        $hzupn = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn)['xml'];
        self::assertStringContainsString('<datumNavratDoPrace>2026-06-29</datumNavratDoPrace>', $hzupn);
    }

    /**
     * C-24: potvrzení „první směna celá odpracována" u schválené DPN se musí
     * propsat do případu jako „pracoval v den vzniku"; dřív NEMPRI hlásilo false.
     */
    public function testWorkedFirstDayFromApprovalReachesCase(): void
    {
        $person = $this->sicknessPerson(8, 'Petr Odpracovaný');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $approved = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-26', (int) $average['id'], [
            'first_day_fully_worked' => true,
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
        ]);
        self::assertSame('created', $approved['sickness_case']['outcome'], json_encode($approved['sickness_case']) ?: '');

        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, (int) $approved['sickness_case']['case_id']);
        self::assertTrue((bool) $case['worked_on_decisive_day']);
    }

    /**
     * NRO-01: měsíce, za které MyÚčto spočítalo a schválilo mzdu, věta nese
     * se započitatelným příjmem z mzdového běhu a se součty. Dřív je
     * vynechala jako „pokryté měsíčním hlášením" a rozhodné období bez
     * jediného měsíce ze sítě vypadlo úplně.
     */
    public function testDecisivePeriodMonthsComeFromApprovedPayrollRun(): void
    {
        $baseComponentId = $this->createComponent('MZDA_NEMPRI', 'base_wage', 'regular');
        $mappings = $this->service(PayrollComponentJmhzMappingRepository::class);
        $mappings->put($this->supplierId, $baseComponentId, '10329', null, $this->actors[0]);
        $person = $this->createEmployment($this->officeId, 'Hana Zaměstnaná', 9, 'hpp', 'employment', 40, 10_000, true, '2026-07-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Hana',
            'last_name' => 'Zaměstnaná',
            'birth_date' => '1987-02-03',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1987-02-03', 'female', 9),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(9), sprintf('2%020d', 9));
        $this->publishShifts($person['employment_id'], self::workdays('2026-07'));
        $this->createApprovedAverage($person['employment_id'], 3);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET start_date = "2026-07-01", actual_start_date = "2026-07-01"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = "2026-07-01", planned_start_on = "2026-07-01", actual_start_on = "2026-07-01"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $response = $this->approveTimeMonth($person['employment_id'], '2026-07', self::workdays('2026-07'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($person, $baseComponentId, 4_200_000, 'nempri-base', '2026-07-01');
        $run = $this->runPayrollMonth('2026-07-01', '2026-08-14', $this->officeId, 'nempri-decisive');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);

        $this->cashPayout($person['employee_id']);
        $case = $this->service(SicknessCaseService::class)->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-08-10',
            'incapacity_to' => '2026-08-31',
            'decision_number' => 'E2223334',
            'daily_working_hours' => '8',
        ], $this->actors[0]);

        $xml = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri)['xml'];

        self::assertStringContainsString('<rozhodneObdobiOd>2026-07-01</rozhodneObdobiOd>', $xml);
        self::assertStringContainsString('<rozhodneObdobiDo>2026-07-31</rozhodneObdobiDo>', $xml);
        self::assertSame(1, substr_count($xml, '<zapocitatelnyPrijem>'));
        self::assertStringContainsString('<zapocitatelnyPrijem>42000</zapocitatelnyPrijem>', $xml);
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>42000</zapocitatelnyPrijemCelkem>', $xml);
        self::assertStringContainsString('<vylouceneDnyCelkem>0</vylouceneDnyCelkem>', $xml);
        self::assertStringNotContainsString('pravdepodobnaVysePrijmu', $xml);
    }

    /**
     * Případ zapsaný ručně k neschopnosti do 14 dnů: dávka z ní neplyne
     * (§ 26 odst. 1 zák. č. 187/2006 Sb.), hlídač termínů k ní NEMPRI ani HZUPN
     * neukáže a NEMPRI se připravit nedá.
     */
    public function testIncapacityWithinWageCompensationWindowHasNoNempri(): void
    {
        $person = $this->sicknessPerson(7, 'Olga Krátká');
        $this->cashPayout($person['employee_id']);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-06-08',
            'incapacity_to' => '2026-06-21',
            'decision_number' => 'E1112223',
            'daily_working_hours' => '8',
        ], $this->actors[0]);
        $caseId = (int) $case['id'];

        $overview = $this->service(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        foreach ($overview['items'] as $item) {
            self::assertNotSame($caseId, $item['case_id'] ?? null, json_encode($item) ?: '');
        }

        try {
            $this->service(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
            self::fail('NEMPRI k neschopnosti do 14 dnů nevzniká.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_within_wage_compensation_window', $exception->validationCode);
        }
        // HZUPN20-CRIT-WEB-1: HZUPN zasílá zaměstnavatel jen u DPN delší než
        // 14 dnů, stejně jako NEMPRI.
        try {
            $this->service(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn);
            self::fail('HZUPN k neschopnosti do 14 dnů nevzniká.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_within_wage_compensation_window', $exception->validationCode);
        }
    }

    /**
     * Zrušená absence zruší i případ, ze kterého se ještě nic nepodalo —
     * jinak by hlídač termínů dál připomínal lhůtu k události, která nenastala.
     */
    public function testCancelledAbsenceCancelsDraftCase(): void
    {
        $person = $this->sicknessPerson(6, 'Ivo Zrušený');
        $approved = $this->approveAbsence($person['employment_id'], 'ocr', '2026-06-08', '2026-06-10');
        $caseId = (int) $approved['sickness_case']['case_id'];

        $cancelled = $this->absences->cancel(
            $this->request('POST', '/api/payroll/absences/cancel')->withParsedBody([
                'row_version' => $approved['absence']['row_version'],
            ]),
            new Response(),
            ['id' => (string) $approved['absence']['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertSame('cancelled', $this->json($cancelled)['sickness_case']['outcome']);
        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('cancelled', $case['status']);
    }

    /**
     * Zaměstnání skončilo 30. 6. Neschopnost od 3. 7. je v sedmidenní
     * ochranné lhůtě a NEMPRI ji předá s koncem zaměstnání; od 8. 7. už nárok
     * z tohoto vztahu nevzniká a případ nejde založit.
     */
    public function testIncapacityAfterEmploymentEndIsSubmittedOnlyWithinProtectionPeriod(): void
    {
        $person = $this->sicknessPerson(2, 'Petr Odcházející');
        $this->cashPayout($person['employee_id']);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-06-30", status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $cases = $this->service(SicknessCaseService::class);

        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-07-03',
            'decision_number' => 'E7654321',
            'daily_working_hours' => '8',
            'decisive_months' => self::manualMonths('2026-01', '2026-06'),
        ], $this->actors[0]);
        $listed = array_values(array_filter(
            $cases->list($this->supplierId, self::ENVIRONMENT, $person['employment_id']),
            static fn (array $row): bool => (int) $row['id'] === (int) $case['id'],
        ));
        self::assertSame('protection_period', $listed[0]['protection_period']['status']);
        self::assertSame('2026-07-07', $listed[0]['protection_period']['protection_until']);

        $xml = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<zamestnanDo>2026-06-30</zamestnanDo>', $xml);
        // § 19 odst. 11: rozhodným dnem je 1. 7. (den po skončení), období končí červnem.
        self::assertStringContainsString('<rozhodneObdobiDo>2026-06-30</rozhodneObdobiDo>', $xml);

        try {
            $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
                'incapacity_from' => '2026-07-08',
            ], $this->actors[0]);
            self::fail('Neschopnost osmý den po skončení zaměstnání nárok nezakládá.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_event_outside_protection_period', $exception->validationCode);
        }
    }

    public function testCareBenefitFromApprovedAbsenceCarriesCodebookRelationship(): void
    {
        $person = $this->sicknessPerson(3, 'Eva Pečující');
        $this->cashPayout($person['employee_id']);
        $approved = $this->approveAbsence($person['employment_id'], 'ocr', '2026-06-08', '2026-06-12');
        self::assertSame('created', $approved['sickness_case']['outcome']);
        self::assertSame('OSE', $approved['sickness_case']['benefit_kind']);
        $caseId = (int) $approved['sickness_case']['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);

        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
                'relationship_code' => '1',
            ]);
            self::fail('Kód z CIS_VZTAH (DLO) u ošetřovného neplatí.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_relationship_code_invalid', $exception->validationCode);
        }

        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => '1234567N',
            'daily_working_hours' => '8',
            'action_start' => true,
            'action_end' => true,
            'worked_last_day' => false,
            'planned_shifts_worked' => false,
            'cared_first_name' => 'Dítě',
            'cared_last_name' => 'Syntetické',
            'cared_birth_date' => '2018-05-05',
            'care_reason' => 'ill',
            'relationship_code' => 'PL',
            'care_days' => [['from' => '2026-06-08', 'to' => '2026-06-12']],
            'planned_shifts' => true,
            'decisive_months' => self::manualMonths('2026-01', '2026-05'),
        ]);

        $xml = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<druhDavky>OSE</druhDavky>', $xml);
        self::assertStringContainsString('<kodRodVztah>PL</kodRodVztah>', $xml);
    }

    /**
     * § 191a zákoníku práce: odmítnutí dlouhodobé péče nese den a důvod
     * a z případu se pak NEMPRI nepředá. Souhlas podání uvolní.
     */
    public function testLongTermCareRefusedByEmployerIsNotSubmitted(): void
    {
        $person = $this->sicknessPerson(4, 'Karel Ošetřující');
        $this->cashPayout($person['employee_id']);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'DLO', [
            'incapacity_from' => '2026-06-08',
            'decision_number' => '1234567L',
            'daily_working_hours' => '8',
            'action_start' => true,
            'cared_first_name' => 'Rodič',
            'cared_last_name' => 'Syntetický',
            'cared_birth_date' => '1950-01-01',
            'relationship_code' => '2',
            'alternation' => false,
            'decisive_months' => self::manualMonths('2026-01', '2026-05'),
        ], $this->actors[0]);

        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
                'long_term_care_consent' => 'refused',
                'long_term_care_consent_on' => '2026-06-05',
            ]);
            self::fail('Odmítnutí bez důvodu nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('dlo_employer_refusal_reason_missing', $exception->validationCode);
        }

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
            'long_term_care_consent' => 'refused',
            'long_term_care_consent_on' => '2026-06-05',
            'long_term_care_refusal_reason' => 'Syntetický vážný provozní důvod.',
        ]);
        $submissions = $this->service(SicknessSubmissionService::class);
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri);
            self::fail('Odmítnutá dlouhodobá péče se nepředává.');
        } catch (SicknessException $exception) {
            self::assertSame('dlo_employer_refused', $exception->validationCode);
        }

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, (int) $case['id'], (int) $case['row_version'], [
            'long_term_care_consent' => 'granted',
            'long_term_care_consent_on' => '2026-06-06',
            'long_term_care_refusal_reason' => null,
        ]);
        self::assertSame('granted', $case['long_term_care_consent']);
        $xml = (string) $submissions->preview($this->supplierId, self::ENVIRONMENT, (int) $case['id'], SicknessDocumentKind::Nempri)['xml'];
        self::assertStringContainsString('<kodVztah>2</kodVztah>', $xml);
    }

    /**
     * Přestup k jiné pojišťovně od 1. 7.: dosavadní 111 dostane odhlášku „O"
     * k 30. 6., nová 205 přihlášku „P" k 1. 7. Dřív z přestupu nevznikla věta
     * vůbec (kód byl fail-closed).
     */
    public function testInsurerChangeProducesBulkNotificationForBothInsurers(): void
    {
        $person = $this->sicknessPerson(5, 'Zuzana Přestupující');
        $accounts = $this->service(PayrollInstitutionAccountRepository::class);
        $accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '205',
            'institution_name' => 'Syntetická druhá pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '0000002050',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:sickness-flow-health-205',
            'verified_on' => '2026-06-15',
        ], $this->actors[0]);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_person_health_coverage_history SET effective_to = "2026-06-30"
              WHERE supplier_id = ? AND employee_id = ? AND effective_to IS NULL',
        )->execute([$this->supplierId, $person['employee_id']]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status, insurer_code,
                 insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "205", "document:synthetic-card-205", "2026-07-01")',
        )->execute([$this->supplierId, $person['employee_id']]);

        $health = $this->service(HealthInsuranceSubmissionService::class);
        $outgoing = (string) $health->bulkNotificationDownload($this->supplierId, '2026-07', '111')['bytes'];
        $incoming = (string) $health->bulkNotificationDownload($this->supplierId, '2026-07', '205')['bytes'];

        self::assertStringContainsString('<kodZdravotniPojistovny>111</kodZdravotniPojistovny>', $outgoing);
        self::assertStringContainsString('<kodzmeny>O</kodzmeny>', $outgoing);
        self::assertStringContainsString('<datumZmeny>2026-06-30</datumZmeny>', $outgoing);
        self::assertStringContainsString('<kodZdravotniPojistovny>205</kodZdravotniPojistovny>', $incoming);
        self::assertStringContainsString('<kodzmeny>P</kodzmeny>', $incoming);
        self::assertStringContainsString('<datumZmeny>2026-07-01</datumZmeny>', $incoming);
    }

    /**
     * PRE-01: NEMPRI a HZUPN jsou dvě podání (§ 97 odst. 1–3 zák.
     * č. 187/2006 Sb.). Dřív zapsané přijetí NEMPRI zamklo celý případ: údaje
     * pro HZUPN nešly uložit (`sickness_case_not_editable`), prodloužení
     * neschopnosti založilo druhý případ a lhůta HZUPN zmizela z hlídače.
     */
    public function testHzupnCanBePreparedAfterNempriReceiptWasRecorded(): void
    {
        $person = $this->sicknessPerson(9, 'Hana Návratová');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $dpn = ['first_day_fully_worked' => false, 'insurance_eligibility_confirmed' => true, 'conflicting_benefit_excluded' => true];
        $first = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-22', (int) $average['id'], $dpn);
        self::assertSame('created', $first['sickness_case']['outcome'], json_encode($first['sickness_case']) ?: '');
        $caseId = (int) $first['sickness_case']['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        $this->cashPayout($person['employee_id']);
        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'E1234567',
            'daily_working_hours' => '8',
        ]);

        $receipt = $this->recordReceiptViaApi($caseId, [
            'outcome' => 'accepted',
            'document' => 'nempri',
            'accepted_on' => '2026-06-24',
        ]);
        self::assertSame(200, $receipt->getStatusCode(), (string) $receipt->getBody());
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('accepted', $case['nempri_status']);
        self::assertSame('2026-06-24', $case['nempri_accepted_on']);
        self::assertSame('pending', $case['hzupn_status']);
        self::assertSame('submitted', $case['status']);

        // Prodloužení po přijetí NEMPRI patří k téže neschopnosti.
        $extended = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-23', '2026-06-26', (int) $average['id'], $dpn);
        self::assertSame('extended', $extended['sickness_case']['outcome'], json_encode($extended['sickness_case']) ?: '');
        self::assertSame($caseId, (int) $extended['sickness_case']['case_id']);

        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('2026-06-26', $case['incapacity_to']);
        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'issued_on' => '2026-06-29',
            'returned_to_work' => '1',
            'returned_on' => '2026-06-29',
            'hours_worked_last_day' => '8',
            'shift_hours_last_day' => '8',
            // Editor posílá celý formulář: nezměněné údaje přijatého NEMPRI
            // uložení neshodí.
            'daily_working_hours' => '8.00',
            'decision_number' => 'E1234567',
        ]);
        self::assertSame('2026-06-29', $case['returned_on']);

        $hzupn = (string) $this->service(SicknessSubmissionService::class)
            ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn)['xml'];
        self::assertStringContainsString('<datumNavratDoPrace>2026-06-29</datumNavratDoPrace>', $hzupn);

        $watched = [];
        foreach ($this->service(PayrollDeadlineOverviewService::class)->overview($this->supplierId, self::ENVIRONMENT, 400)['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $watched[] = $item['title'];
            }
        }
        self::assertSame(['HZUPN'], $watched);
    }

    /**
     * PRE-01: vyřízené podání zamkne jen SVOJE údaje. Změna údaje přijatého
     * NEMPRI bez opravného podání neprojde, s opravným ano; druhé přijetí
     * téhož podání jde zapsat jen u opravy.
     */
    public function testSettledNempriLocksOnlyItsOwnFieldsUntilCorrection(): void
    {
        $person = $this->sicknessPerson(10, 'Rita Opravná');
        $this->cashPayout($person['employee_id']);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-06-08',
            'incapacity_to' => '2026-06-30',
            'decision_number' => 'E2223334',
            'daily_working_hours' => '8',
        ], $this->actors[0]);
        $caseId = (int) $case['id'];
        $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'accepted', '2026-06-23', null);

        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
                'daily_working_hours' => '6',
            ]);
            self::fail('Údaj přijatého NEMPRI se bez opravného podání nemění.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_case_document_settled', $exception->validationCode);
        }
        try {
            $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'accepted', '2026-06-25', null);
            self::fail('Druhé přijetí téhož NEMPRI patří jen k opravnému podání.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_receipt_already_recorded', $exception->validationCode);
        }
        try {
            $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn, 'predecessor', null, null);
            self::fail('Vyřízení předchozím programem jde zapsat jen u převzatého případu.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_receipt_predecessor_not_takeover', $exception->validationCode);
        }

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'correction' => '1',
            'daily_working_hours' => '6',
        ]);
        self::assertSame('6.00', $case['daily_working_hours']);
        $case = $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'accepted', '2026-06-25', null);
        self::assertSame('2026-06-25', $case['nempri_accepted_on']);
        self::assertSame('pending', $case['hzupn_status']);

        $case = $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn, 'accepted', '2026-07-02', null);
        self::assertSame('accepted', $case['status']);
    }

    /**
     * Schválení zpětně zapsané neschopnosti z doby před prvním měsícem vedení
     * mezd v MyÚčtu nedokládá, že ji předchozí program podal. Dřív ji případ
     * rovnou vedl jako podanou předchozím programem, povinnost se přestala
     * hlídat a NEMPRI nešlo připravit. Teď je podání hlídané a vyřízení
     * předchozím programem se jen nabídne; zapsané jde i vrátit.
     */
    public function testApprovedAbsenceBeforeStartPeriodOnlyOffersPredecessor(): void
    {
        $person = $this->sicknessPerson(16, 'Věra Zpětná');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2026-07-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $dpn = ['first_day_fully_worked' => false, 'insurance_eligibility_confirmed' => true, 'conflicting_benefit_excluded' => true];

        $approved = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-26', (int) $average['id'], $dpn);
        $outcome = $approved['sickness_case'];
        self::assertSame('created', $outcome['outcome'], json_encode($outcome) ?: '');
        self::assertSame('sickness_case_predecessor_period', $outcome['reason_code']);
        self::assertSame('2026-06-22', $outcome['nempri_due_on']);
        $caseId = (int) $outcome['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('predecessor', $case['source']);
        self::assertSame('pending', $case['nempri_status']);
        self::assertSame('pending', $case['hzupn_status']);

        $case = $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'predecessor', null, null);
        self::assertSame('predecessor', $case['nempri_status']);

        $reopened = $this->recordReceiptViaApi($caseId, ['outcome' => 'pending', 'document' => 'nempri']);
        self::assertSame(200, $reopened->getStatusCode(), (string) $reopened->getBody());
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('pending', $case['nempri_status']);
        $watched = [];
        foreach ($this->service(PayrollDeadlineOverviewService::class)->overview($this->supplierId, self::ENVIRONMENT, 400)['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $watched[] = $item['title'];
            }
        }
        self::assertContains('NEMPRI', $watched);
    }

    /**
     * DPN-05: převzatá neschopnost s 10 dny okna u předchozího plátce. Den
     * vzniku je 1. 6., ne první den v MyÚčtu, a lhůta NEMPRI běží od 15. 6.
     */
    public function testCarriedWindowDaysMoveCaseStartAndDeadline(): void
    {
        $person = $this->sicknessPerson(11, 'Lenka Převzatá');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $created = $this->requestAbsence($person['employment_id'], 'dpn', '2026-06-11', '2026-06-30', (int) $average['id']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $carried = $this->absences->sicknessWindowCarried(
            $this->request('POST', '/api/payroll/absences/sickness-window')->withParsedBody([
                'row_version' => $absence['row_version'],
                'sickness_window_carried_days' => 10,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $carried->getStatusCode(), (string) $carried->getBody());
        $absence = $this->json($carried)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());
        $outcome = $this->json($decision)['sickness_case'];

        self::assertSame('created', $outcome['outcome'], json_encode($outcome) ?: '');
        self::assertSame('2026-06-15', $outcome['nempri_due_on']);
        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, (int) $outcome['case_id']);
        self::assertSame('2026-06-01', $case['incapacity_from']);
    }

    /**
     * DPN-06, § 26 odst. 3: odpracoval-li zaměstnanec 8. 6. celou směnu, je
     * prvním dnem neschopnosti 9. 6. DPN do 22. 6. pak trvá 14 dnů, celou ji
     * kryje náhrada mzdy a případ NEMPRI nevzniká.
     */
    public function testWorkedFirstDayDelaysNempriDuty(): void
    {
        $person = $this->sicknessPerson(12, 'Otto Směnový');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $approved = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-22', (int) $average['id'], [
            'first_day_fully_worked' => true,
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
        ]);

        self::assertNull($approved['sickness_case'], json_encode($approved['sickness_case']) ?: '');
    }

    /**
     * NX-04: „pracoval v den vzniku" vyžaduje pracovní dobu a odpracované
     * hodiny. Celá odpracovaná směna = obojí stejné, délka ze zveřejněné směny
     * (8:00–16:30 s půlhodinovou přestávkou = 8 hodin).
     */
    public function testWorkedFirstDayFillsShiftHoursIntoCase(): void
    {
        $person = $this->sicknessPerson(13, 'Iva Celosměnná');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $approved = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-26', (int) $average['id'], [
            'first_day_fully_worked' => true,
            'insurance_eligibility_confirmed' => true,
            'conflicting_benefit_excluded' => true,
        ]);
        self::assertSame('created', $approved['sickness_case']['outcome'], json_encode($approved['sickness_case']) ?: '');
        self::assertNull($approved['sickness_case']['reason_code']);
        // Lhůta NEMPRI od 15. dne neschopnosti počítané od 9. 6. (§ 26 odst. 3).
        self::assertSame('2026-06-23', $approved['sickness_case']['nempri_due_on']);

        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, (int) $approved['sickness_case']['case_id']);
        self::assertTrue((bool) $case['worked_on_decisive_day']);
        self::assertSame('8.00', $case['daily_working_hours']);
        self::assertSame('8.00', $case['hours_worked']);
    }

    /**
     * DPN-07: zrušení navazující nepřítomnosti vrátí konec případu na den před
     * ní. Dřív zůstal případ až do 26. 6. a HZUPN by hlásilo pozdější návrat.
     */
    public function testCancelledContinuationShortensCase(): void
    {
        $person = $this->sicknessPerson(14, 'Marek Zkrácený');
        $average = $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays('2026-06'));
        $dpn = ['first_day_fully_worked' => false, 'insurance_eligibility_confirmed' => true, 'conflicting_benefit_excluded' => true];
        $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-08', '2026-06-19', (int) $average['id'], $dpn);
        $second = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-20', '2026-06-22', (int) $average['id'], $dpn);
        $caseId = (int) $second['sickness_case']['case_id'];
        $third = $this->approveAbsence($person['employment_id'], 'dpn', '2026-06-23', '2026-06-26', (int) $average['id'], $dpn);
        self::assertSame('extended', $third['sickness_case']['outcome']);

        $cancelled = $this->absences->cancel(
            $this->request('POST', '/api/payroll/absences/cancel')->withParsedBody([
                'row_version' => $third['absence']['row_version'],
            ]),
            new Response(),
            ['id' => (string) $third['absence']['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertSame('shortened', $this->json($cancelled)['sickness_case']['outcome'] ?? null);

        $case = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('2026-06-22', $case['incapacity_to']);
        self::assertSame('draft', $case['status']);
    }

    /**
     * NX-03 a NRO-06: podklady pro výplatu DLO (`maVolno`, `pracovniVolno`,
     * `seznamRozvrhuSmen`) a důvod převedení na jinou práci (§ 19 odst. 6)
     * se ukládají s případem a drží vazbu na nadřazený údaj.
     */
    public function testDloPayoutBasisAndTransferReasonAreStored(): void
    {
        $person = $this->sicknessPerson(15, 'Dana Pečovatelka');
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'DLO', [
            'incapacity_from' => '2026-06-08',
            'action_end' => true,
        ], $this->actors[0]);
        $caseId = (int) $case['id'];

        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'dlo_has_leave' => '1',
            'dlo_leave_periods' => [['from' => '2026-06-15', 'to' => '2026-06-16']],
            'planned_shifts' => '1',
            'dlo_shift_schedule' => [['from' => '2026-06-08', 'to' => '2026-06-12']],
            'transferred_other_work' => '1',
            'transferred_on' => '2026-03-10',
            'transfer_reason' => 'pregnancy',
        ]);
        self::assertSame(1, (int) $case['dlo_has_leave']);
        self::assertSame([['from' => '2026-06-15', 'to' => '2026-06-16']], $case['dlo_leave_periods']);
        self::assertSame([['from' => '2026-06-08', 'to' => '2026-06-12']], $case['dlo_shift_schedule']);
        self::assertSame('pregnancy', $case['transfer_reason']);

        foreach ([
            'dlo_leave_periods_without_leave' => ['dlo_has_leave' => '0', 'dlo_leave_periods' => [['from' => '2026-06-15', 'to' => '2026-06-16']]],
            'dlo_shift_schedule_without_planned_shifts' => ['planned_shifts' => '0'],
            'sickness_transfer_reason_invalid' => ['transfer_reason' => 'holiday'],
        ] as $code => $input) {
            try {
                $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], $input);
                self::fail('Nekonzistentní podklad nesmí projít: ' . $code);
            } catch (SicknessException $exception) {
                self::assertSame($code, $exception->validationCode);
            }
        }

        // Zrušené převedení s sebou smaže den i důvod.
        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'transferred_other_work' => '0',
        ]);
        self::assertNull($case['transferred_on']);
        self::assertNull($case['transfer_reason']);

        $nem = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-06-08',
        ], $this->actors[0]);
        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, (int) $nem['id'], (int) $nem['row_version'], [
                'dlo_has_leave' => '1',
            ]);
            self::fail('Podklady DLO u nemocenského nejsou.');
        } catch (SicknessException $exception) {
            self::assertSame('dlo_basis_not_in_kind', $exception->validationCode);
        }
        try {
            $cases->update($this->supplierId, self::ENVIRONMENT, (int) $nem['id'], (int) $nem['row_version'], [
                'transfer_reason' => 'pregnancy',
            ]);
            self::fail('Důvod převedení bez převedení nedává smysl.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_transfer_reason_without_transfer', $exception->validationCode);
        }
    }

    /** @param array<string,mixed> $body */
    private function recordReceiptViaApi(int $caseId, array $body): ResponseInterface
    {
        return $this->service(PayrollSicknessCaseAction::class)->receipt(
            $this->request('POST', "/api/payroll/submissions/sickness-cases/{$caseId}/receipt")
                ->withParsedBody($body + ['environment' => self::ENVIRONMENT]),
            new Response(),
            ['caseId' => (string) $caseId],
        );
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function sicknessPerson(int $sequence, string $name): array
    {
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000);
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '1988-04-12',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1988-04-12', 'female', $sequence),
        ]);

        return $person;
    }

    /** Výplata mzdy v hotovosti — NEMPRI nese `vyplatitHotovost`, adresa se nepředává. */
    private function cashPayout(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employee_profiles SET payout_method = "cash" WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code,
                 country_code, effective_from)
             VALUES (?, ?, "residence", "Zkušební 12", "Testov", "11000", "CZ", "2026-05-01")',
        )->execute([$this->supplierId, $employeeId]);
    }

    /**
     * @param array<string,mixed> $decisionExtra
     * @return array<string,mixed>
     */
    private function approveAbsence(
        int $employmentId,
        string $type,
        string $from,
        string $to,
        ?int $averageId = null,
        array $decisionExtra = [],
    ): array {
        $created = $this->requestAbsence($employmentId, $type, $from, $to, $averageId);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($decisionExtra + [
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), (string) $decision->getBody());

        return $this->json($decision);
    }

    /**
     * Ruční měsíce rozhodného období po 40 000 Kč bez vyloučených dnů.
     *
     * @return list<array{period:string,income_minor:int,excluded_days:int}>
     */
    private static function manualMonths(string $from, string $to): array
    {
        $months = [];
        for ($cursor = new \DateTimeImmutable($from . '-01'); $cursor->format('Y-m') <= $to; $cursor = $cursor->modify('+1 month')) {
            $months[] = ['period' => $cursor->format('Y-m'), 'income_minor' => 4_000_000, 'excluded_days' => 0];
        }

        return $months;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
