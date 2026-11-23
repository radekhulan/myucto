<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSicknessCaseAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Dávky nemocenského pojištění celým tokem účetní (ověření podání 11/2026):
 * zaměstnanec HPP → nepřítomnost → mzdové běhy → případ dávky → věta NEMPRI
 * nebo HZUPN proti XSD → zmrazení podání bez odeslání.
 *
 * Doplňuje {@see PayrollSicknessFullFlowTest} o scénáře, které tam chybí:
 * neschopnost přes hranici měsíce s náhradou mzdy ve dvou bězích, rozhodné
 * období a vyloučené dny ze schválených běhů (ručně přepočtené podle § 18
 * zák. č. 187/2006 Sb.), peněžitá pomoc v mateřství a krátké ošetřovné
 * celým tokem a blokace s místem nápravy.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniSicknessFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private const DPN = [
        'first_day_fully_worked' => false,
        'insurance_eligibility_confirmed' => true,
        'conflicting_benefit_excluded' => true,
    ];

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('NEMX', 'Syntetická účtárna dávek', '9990007777');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $this->baseComponentId = $this->createComponent('MZDA_NEMX', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testSicknessAcrossMonthBoundary(): void
    {
        $person = $this->hire(1, 'Marie Přelomová', 'female', '1990-03-15');
        $average = $this->probableAverage($person['employment_id'], 1, 30_000);
        $this->publishShifts($person['employment_id'], array_merge(
            self::workdays('2026-01'),
            self::workdays('2026-02'),
            self::workdays('2026-03'),
        ));
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-01-12', '2026-01-12');
        $this->approveMonth($person['employment_id'], '2026-01', self::workdays('2026-01', ['2026-01-12']));
        $this->pay($person, 4_000_000, '2026-01-01', 'jan');

        $dpn = $this->approveAbsence($person['employment_id'], 'dpn', '2026-02-20', '2026-03-15', (int) $average['id'], self::DPN);
        self::assertSame('created', $dpn['sickness_case']['outcome'], CanonicalJson::encode($dpn['sickness_case']));
        self::assertSame('NEM', $dpn['sickness_case']['benefit_kind']);
        self::assertSame('2026-03-06', $dpn['sickness_case']['nempri_due_on']);
        self::assertSame('2026-02-20', $dpn['calculation']['compensation_window_from']);
        self::assertSame('2026-03-05', $dpn['calculation']['compensation_window_to']);
        self::assertSame(26574, $dpn['calculation']['reduced_hourly_minor']);
        self::assertSame(1_275_700, $dpn['calculation']['compensation_minor']);
        $caseId = (int) $dpn['sickness_case']['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        $case = $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'E7654321',
            'daily_working_hours' => '8',
        ]);
        $submissions = $this->service(SicknessSubmissionService::class);

        // Rozhodné období je leden (§ 18 odst. 4: pojištění od 1. 1. 2026),
        // mzdu za něj počítá MyÚčto: bez schváleného běhu se podání zastaví
        // a řekne, který měsíc a co s ním.
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
            self::fail('Bez schváleného lednového běhu nemá rozhodné období zdroj.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_month_missing', $exception->validationCode);
            self::assertStringContainsString('Mzdu za 2026-01 počítá MyÚčto', $exception->getMessage());
            self::assertStringContainsString('spočítejte a schvalte', $exception->getMessage());
        }

        $january = $this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'nemx-jan');
        self::assertSame([], $january['blockers'], CanonicalJson::encode($january['blockers']));
        self::assertNotNull($january['approved']);

        $sick = self::dateRange('2026-02-20', '2026-03-15');
        $this->approveMonth($person['employment_id'], '2026-02', self::workdays('2026-02', $sick));
        $this->pay($person, 3_150_000, '2026-02-01', 'feb');
        $february = $this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'nemx-feb');
        self::assertSame([], $february['blockers'], CanonicalJson::encode($february['blockers']));
        self::assertSame([], $february['warnings'], CanonicalJson::encode($february['warnings']));
        self::assertSame(765_400, $this->compensation($february, $person['employment_id']));

        $this->approveMonth($person['employment_id'], '2026-03', self::workdays('2026-03', $sick));
        $this->pay($person, 2_454_600, '2026-03-01', 'mar');
        $march = $this->runPayrollMonth('2026-03-01', '2026-04-14', $this->officeId, 'nemx-mar');
        self::assertSame([], $march['blockers'], CanonicalJson::encode($march['blockers']));
        self::assertSame([], $march['warnings'], CanonicalJson::encode($march['warnings']));
        self::assertSame(510_300, $this->compensation($march, $person['employment_id']));

        $preview = $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
        $xml = self::compact((string) $preview['xml']);
        // § 97 odst. 2 věta druhá: neprodleně po uplynutí 14 dnů = 15. den (6. 3.).
        self::assertSame('2026-03-06', $preview['window']['due_on']);
        self::assertStringContainsString('<druhDavky>NEM</druhDavky><cisloRozhodnuti>E7654321</cisloRozhodnuti>', $xml);
        self::assertStringContainsString('<zamestnanOd>2026-01-01</zamestnanOd>', $xml);
        self::assertStringNotContainsString('<zamestnanDo>', $xml);
        // Rozhodné období: 12 měsíců před únorem (2/2025–1/2026) zkrácené
        // na dobu pojištění od 1. 1. 2026 (§ 18 odst. 3 a 4). Leden má 31 dnů,
        // 1 vyloučený (neplacené volno 12. 1.) → 30 nevyloučených dnů, právě
        // tolik, kolik § 18 odst. 6 žádá. Příjem 40 000 Kč ze schváleného běhu.
        self::assertStringContainsString(
            '<rozhodneObdobi><rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd><rozhodneObdobiDo>2026-01-31</rozhodneObdobiDo>'
                . '<seznamObdobi><obdobi><kalendarniMesic>1</kalendarniMesic><kalendarniRok>2026</kalendarniRok>'
                . '<zapocitatelnyPrijem>40000</zapocitatelnyPrijem><vylouceneDny>1</vylouceneDny></obdobi></seznamObdobi>'
                . '<zapocitatelnyPrijemCelkem>40000</zapocitatelnyPrijemCelkem><vylouceneDnyCelkem>1</vylouceneDnyCelkem></rozhodneObdobi>',
            $xml,
        );
        self::assertStringNotContainsString('pravdepodobnaVysePrijmu', $xml);
        self::assertStringContainsString('<pracoval>false</pracoval>', $xml);
        self::assertStringContainsString(
            '<platebniSpojeni><vyplatitUcetCR>true</vyplatitUcetCR><vyplatitUcetCizina>false</vyplatitUcetCizina>'
                . '<vyplatitAdresa>false</vyplatitAdresa><vyplatitHotovost>false</vyplatitHotovost>'
                . '<ucetCZ><ucetCislo>1000000005</ucetCislo><bankaKod>0100</bankaKod></ucetCZ></platebniSpojeni>',
            $xml,
        );

        // Nástup do práce 16. 3. → HZUPN (§ 97 odst. 3), lhůta od dne nástupu.
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        self::assertSame('2026-03-15', $case['incapacity_to']);
        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'issued_on' => '2026-03-16',
            'returned_to_work' => '1',
            'returned_on' => '2026-03-16',
            'hours_worked_last_day' => '8',
            'shift_hours_last_day' => '8',
        ]);
        $hzupn = $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn);
        self::assertSame('2026-03-16', $hzupn['window']['due_on']);
        $hzupnXml = self::compact((string) $hzupn['xml']);
        self::assertStringContainsString('<navratDoPrace>A</navratDoPrace>', $hzupnXml);
        self::assertStringContainsString('<datumNavratDoPrace>2026-03-16</datumNavratDoPrace>', $hzupnXml);

        // Zmrazení obou podání do fronty bez odeslání: zmrazené XML je to,
        // které ukázal náhled.
        $previewed = [
            SicknessDocumentKind::Nempri->value => $preview['xml_sha256'],
            SicknessDocumentKind::Hzupn->value => $hzupn['xml_sha256'],
        ];
        foreach ([SicknessDocumentKind::Nempri, SicknessDocumentKind::Hzupn] as $document) {
            $prepared = $submissions->prepare($this->supplierId, self::ENVIRONMENT, $caseId, $document, $this->actors[0]);
            self::assertTrue($prepared['created']);
            self::assertSame($previewed[$document->value], $prepared['artifact_sha256']);
            self::assertSame('ready', $prepared['status']);
            self::assertSame($document->agendaCode(), $prepared['agenda_code']);
            self::assertSame(
                0,
                (int) $this->scalar(
                    'SELECT COUNT(*) FROM payroll_submission_transport_attempts WHERE supplier_id = ? AND submission_id = ?',
                    [$this->supplierId, $prepared['submission_id']],
                ),
            );
        }
    }

    /**
     * Dva dny neplaceného volna v jediném měsíci rozhodného období: 31 − 2 =
     * 29 nevyloučených dnů, méně než 30. Rozhodné období podle § 18 odst. 4
     * (pojištění od 1. 1. 2026) se proto nahradí pravděpodobným příjmem
     * (§ 19 odst. 7); předchozí kalendářní rok podle § 18 odst. 6 pojištění
     * nemá. Podání se zastaví a řekne, co doplnit; s doplněným příjmem věta
     * nese období bez seznamu měsíců.
     */
    public function testShortDecisivePeriodWithTooFewDaysNeedsProbableIncome(): void
    {
        $person = $this->hire(2, 'Pavla Krátká', 'female', '1991-06-01');
        $this->publishShifts($person['employment_id'], self::workdays('2026-01'));
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-01-12', '2026-01-13');
        $this->approveMonth($person['employment_id'], '2026-01', self::workdays('2026-01', ['2026-01-12', '2026-01-13']));
        $this->pay($person, 4_000_000, '2026-01-01', 'jan');
        $january = $this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'nemx-short');
        self::assertNotNull($january['approved'], CanonicalJson::encode($january['blockers']));

        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-02-09',
            'incapacity_to' => '2026-03-06',
            'decision_number' => 'E1112223',
            'daily_working_hours' => '8',
        ], $this->actors[0]);
        $caseId = (int) $case['id'];
        $submissions = $this->service(SicknessSubmissionService::class);
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
            self::fail('Rozhodné období s 29 nevyloučenými dny nestačí.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_probable_income_missing', $exception->validationCode);
            self::assertStringContainsString('§ 19 odst. 7', $exception->getMessage());
            self::assertStringContainsString('Zadejte ji u případu', $exception->getMessage());
        }

        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'probable_income_czk' => '45000',
        ]);
        $xml = self::compact((string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml']);
        self::assertStringContainsString(
            '<rozhodneObdobi><rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd><rozhodneObdobiDo>2026-01-31</rozhodneObdobiDo>'
                . '<pravdepodobnaVysePrijmu>45000</pravdepodobnaVysePrijmu></rozhodneObdobi>',
            $xml,
        );
        self::assertStringNotContainsString('<seznamObdobi>', $xml);
    }

    /**
     * Peněžitá pomoc v mateřství: nástup 2. 3. 2026 (7 týdnů před očekávaným
     * porodem 20. 4.). Schválená nepřítomnost `ppm` založí případ PPM s lhůtou
     * od dne nástupu (§ 97 odst. 1 věta první), rozhodné období nese leden
     * a únor ze schválených běhů a březnový běh projde bez mzdy.
     */
    public function testMaternityBenefitFromApprovedAbsence(): void
    {
        $person = $this->hire(3, 'Lucie Mateřská', 'female', '1993-08-08');
        $this->publishShifts($person['employment_id'], array_merge(self::workdays('2026-01'), self::workdays('2026-02')));
        $this->approveMonth($person['employment_id'], '2026-01', self::workdays('2026-01'));
        $this->pay($person, 3_800_000, '2026-01-01', 'jan');
        self::assertNotNull($this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'nemx-ppm-jan')['approved']);
        $this->approveMonth($person['employment_id'], '2026-02', self::workdays('2026-02'));
        $this->pay($person, 4_200_000, '2026-02-01', 'feb');
        self::assertNotNull($this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'nemx-ppm-feb')['approved']);

        $approved = $this->approveAbsence(
            $person['employment_id'],
            'ppm',
            '2026-03-02',
            '2026-08-30',
            extra: ['expected_childbirth_date' => '2026-04-20'],
        );
        self::assertSame('created', $approved['sickness_case']['outcome'], CanonicalJson::encode($approved['sickness_case']));
        self::assertSame('PPM', $approved['sickness_case']['benefit_kind']);
        // § 97 odst. 1 věta první: neprodleně po přijetí žádosti, nejdřív v den nástupu.
        self::assertSame('2026-03-02', $approved['sickness_case']['nempri_due_on']);
        $caseId = (int) $approved['sickness_case']['case_id'];

        // Březen celý na PPM: docházka bez odpracovaných dnů, běh bez mzdy projde.
        $this->approveMonth($person['employment_id'], '2026-03', []);
        $march = $this->runPayrollMonth('2026-03-01', '2026-04-14', $this->officeId, 'nemx-ppm-mar');
        self::assertSame([], $march['blockers'], CanonicalJson::encode($march['blockers']));
        self::assertSame([], $march['warnings'], CanonicalJson::encode($march['warnings']));
        self::assertNotNull($march['approved']);

        $cases = $this->service(SicknessCaseService::class);
        $submissions = $this->service(SicknessSubmissionService::class);
        // PPM nese číslo rozhodnutí (kontrola NEMPRI25 č. 2: sedm číslic a M).
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
            self::fail('PPM bez čísla rozhodnutí ČSSZ nespáruje.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decision_number_missing', $exception->validationCode);
            // Hláška mluví o dokladu PPM, ne o eNeschopence a eOČR.
            self::assertStringContainsString('písmenem M', $exception->getMessage());
            self::assertStringNotContainsString('eNeschopenky', $exception->getMessage());
        }
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], ['decision_number' => '1234567M']);

        $preview = $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
        $xml = self::compact((string) $preview['xml']);
        self::assertStringContainsString('<druhDavky>PPM</druhDavky><cisloRozhodnuti>1234567M</cisloRozhodnuti>', $xml);
        // Rozhodné období 3/2025–2/2026 zkrácené na pojištění od 1. 1. 2026:
        // leden 38 000 Kč a únor 42 000 Kč, 59 dnů bez vyloučených.
        self::assertStringContainsString(
            '<rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd><rozhodneObdobiDo>2026-02-28</rozhodneObdobiDo><seznamObdobi>'
                . '<obdobi><kalendarniMesic>1</kalendarniMesic><kalendarniRok>2026</kalendarniRok><zapocitatelnyPrijem>38000</zapocitatelnyPrijem><vylouceneDny>0</vylouceneDny></obdobi>'
                . '<obdobi><kalendarniMesic>2</kalendarniMesic><kalendarniRok>2026</kalendarniRok><zapocitatelnyPrijem>42000</zapocitatelnyPrijem><vylouceneDny>0</vylouceneDny></obdobi>'
                . '</seznamObdobi><zapocitatelnyPrijemCelkem>80000</zapocitatelnyPrijemCelkem><vylouceneDnyCelkem>0</vylouceneDnyCelkem>',
            $xml,
        );
        // Při běžném nástupu na PPM žádost nese jen den, od kterého se dávka
        // žádá; dítě se uvádí jen při převzetí do péče.
        self::assertStringContainsString('<zadostODavku><odeDne>2026-03-02</odeDne></zadostODavku></ppm>', $xml);
        self::assertStringNotContainsString('volnoBezNahrady', $xml);
        // Bez převedení na jinou práci den převedení v potvrzení PPM být nesmí.
        self::assertStringContainsString('<prevedenaNaJinouPraci>false</prevedenaNaJinouPraci>', $xml);
        self::assertStringNotContainsString('datumNaJinouPraci', $xml);
        self::assertStringContainsString('<ucetCZ><ucetCislo>1000000005</ucetCislo><bankaKod>0100</bankaKod></ucetCZ>', $xml);

        // HZUPN u mateřské nevzniká, hlídač ho nesmí tvrdit.
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn);
            self::fail('HZUPN patří jen k nemocenskému.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_not_for_benefit_kind', $exception->validationCode);
        }
        self::assertSame(['NEMPRI'], $this->watchedDocuments($caseId));

        $prepared = $submissions->prepare($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, $this->actors[0]);
        self::assertSame('ready', $prepared['status']);
        self::assertSame($preview['xml_sha256'], $prepared['artifact_sha256']);
    }

    /**
     * Krátké ošetřovné 9. až 13. 2. (pět dnů, kratší než podpůrčí doba
     * 9 dnů podle § 40 odst. 1): lhůta NEMPRI běží od skončení péče
     * (14. 2., sobota → pondělí 16. 2.), únorový běh nezná náhradu mzdy
     * (ošetřovné platí ČSSZ) a věta nese leden ze schváleného běhu.
     */
    public function testShortCareBenefitInMonth(): void
    {
        $person = $this->hire(4, 'Eva Pečující', 'female', '1988-04-12');
        $this->publishShifts($person['employment_id'], array_merge(self::workdays('2026-01'), self::workdays('2026-02')));
        $this->approveMonth($person['employment_id'], '2026-01', self::workdays('2026-01'));
        $this->pay($person, 4_500_000, '2026-01-01', 'jan');
        self::assertNotNull($this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'nemx-ocr-jan')['approved']);

        $approved = $this->approveAbsence($person['employment_id'], 'ocr', '2026-02-09', '2026-02-13');
        self::assertSame('created', $approved['sickness_case']['outcome'], CanonicalJson::encode($approved['sickness_case']));
        self::assertSame('OSE', $approved['sickness_case']['benefit_kind']);
        self::assertSame('2026-02-16', $approved['sickness_case']['nempri_due_on']);
        $caseId = (int) $approved['sickness_case']['case_id'];

        $this->approveMonth($person['employment_id'], '2026-02', self::workdays('2026-02', self::dateRange('2026-02-09', '2026-02-13')));
        $this->pay($person, 3_375_000, '2026-02-01', 'feb');
        $february = $this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'nemx-ocr-feb');
        self::assertSame([], $february['blockers'], CanonicalJson::encode($february['blockers']));
        self::assertSame([], $february['warnings'], CanonicalJson::encode($february['warnings']));
        self::assertSame(0, $this->compensation($february, $person['employment_id']));

        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => '1234567N',
            'daily_working_hours' => '8',
            'action_start' => true,
            'action_end' => true,
            'worked_last_day' => false,
            'planned_shifts_worked' => false,
            'cared_first_name' => 'Dítě',
            'cared_last_name' => 'Syntetické',
            'cared_birth_date' => '2019-05-05',
            'care_reason' => 'ill',
            'relationship_code' => 'PL',
            'shared_household' => true,
            'child_under_16' => true,
            'cared_personally' => true,
            'care_days' => [['from' => '2026-02-09', 'to' => '2026-02-13']],
            'planned_shifts' => true,
        ]);
        $submissions = $this->service(SicknessSubmissionService::class);
        $preview = $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
        $xml = self::compact((string) $preview['xml']);
        self::assertSame('2026-02-14', $preview['window']['earliest_notification_on']);
        self::assertSame('2026-02-16', $preview['window']['due_on']);
        self::assertStringContainsString('<druhDavky>OSE</druhDavky><cisloRozhodnuti>1234567N</cisloRozhodnuti>', $xml);
        self::assertStringContainsString(
            '<seznamObdobi><obdobi><kalendarniMesic>1</kalendarniMesic><kalendarniRok>2026</kalendarniRok>'
                . '<zapocitatelnyPrijem>45000</zapocitatelnyPrijem><vylouceneDny>0</vylouceneDny></obdobi></seznamObdobi>',
            $xml,
        );
        self::assertStringContainsString('<oseVznik>true</oseVznik><oseTrvani>false</oseTrvani><oseUkonceni>true</oseUkonceni>', $xml);
        self::assertStringContainsString('<zadostODavku><odeDne>2026-02-09</odeDne><doDne>2026-02-13</doDne>', $xml);
        self::assertStringContainsString('<pecovalVeDnech><obdobi><od>2026-02-09</od><do>2026-02-13</do></obdobi></pecovalVeDnech><kodRodVztah>PL</kodRodVztah>', $xml);
        self::assertStringContainsString('<vyplatitUcetCR>true</vyplatitUcetCR>', $xml);
        self::assertSame(['NEMPRI'], $this->watchedDocuments($caseId));
        $prepared = $submissions->prepare($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, $this->actors[0]);
        self::assertSame('ready', $prepared['status']);
    }

    /**
     * Zaměstnání skončí 20. 3. uprostřed neschopnosti (2. až 31. 3.). Okno
     * náhrady mzdy končí s vztahem nejpozději 15. 3. (14. den), NEMPRI nese
     * konec zaměstnání a HZUPN se nepodává: zaměstnanec do práce nenastoupí
     * (§ 97 odst. 3). Hlídač vede jen NEMPRI.
     */
    public function testEmploymentEndedDuringSicknessHasNempriWithoutHzupn(): void
    {
        $person = $this->hire(5, 'Ota Odcházející', 'male', '1985-02-02');
        $average = $this->probableAverage($person['employment_id'], 1, 30_000);
        $this->publishShifts($person['employment_id'], array_merge(self::workdays('2026-01'), self::workdays('2026-02'), self::workdays('2026-03')));
        foreach (['2026-01' => 'nemx-end-jan', '2026-02' => 'nemx-end-feb'] as $period => $key) {
            $this->approveMonth($person['employment_id'], $period, self::workdays($period));
            $this->pay($person, 4_000_000, $period . '-01', $key);
            $payday = (new \DateTimeImmutable($period . '-13'))->modify('+1 month')->format('Y-m-d');
            self::assertNotNull($this->runPayrollMonth($period . '-01', $payday, $this->officeId, $key)['approved']);
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-03-20" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);

        $dpn = $this->approveAbsence($person['employment_id'], 'dpn', '2026-03-02', '2026-03-31', (int) $average['id'], self::DPN);
        self::assertSame('created', $dpn['sickness_case']['outcome'], CanonicalJson::encode($dpn['sickness_case']));
        self::assertSame('2026-03-15', $dpn['calculation']['compensation_window_to']);
        $caseId = (int) $dpn['sickness_case']['case_id'];
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
        $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $case['row_version'], [
            'decision_number' => 'E5556667',
            'daily_working_hours' => '8',
        ]);
        $submissions = $this->service(SicknessSubmissionService::class);
        $xml = self::compact((string) $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri)['xml']);
        // § 97 odst. 2 a DV NEMPRI25: konec zaměstnání je ve větě, rozhodné
        // období 1. 1. – 28. 2. (§ 18 odst. 3 a 4) se nemění.
        self::assertStringContainsString('<zamestnanOd>2026-01-01</zamestnanOd><zamestnanDo>2026-03-20</zamestnanDo>', $xml);
        self::assertStringContainsString('<rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd><rozhodneObdobiDo>2026-02-28</rozhodneObdobiDo>', $xml);
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>80000</zapocitatelnyPrijemCelkem><vylouceneDnyCelkem>0</vylouceneDnyCelkem>', $xml);
        try {
            $submissions->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn);
            self::fail('HZUPN se po skončení zaměstnání během neschopnosti nepodává.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_employment_ended_during_incapacity', $exception->validationCode);
        }
        self::assertSame(['NEMPRI'], $this->watchedDocuments($caseId));
    }

    /**
     * Průchod bez slepé uličky: případ, kterému chybí údaje na více místech.
     * Každá blokace přijde přes API jako 422 s kódem a větou, která říká co
     * a kde doplnit; po opravě se ukáže další, až věta projde. Nic nekončí 500.
     */
    public function testEveryNempriBlockerNamesWhatAndWhereToFix(): void
    {
        $person = $this->createEmployment($this->officeId, 'Ivo Neúplný', 6, 'hpp', 'employment', 40, 10_000, true, '2026-01-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Ivo',
            'last_name' => 'Neúplný',
            'birth_date' => '1986-09-09',
            'sex' => 'male',
            'birth_number' => self::syntheticBirthNumber('1986-09-09', 'male', 6),
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employee_profiles SET payout_method = "bank" WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $person['employee_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET activity_code = NULL WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-02-09',
            'incapacity_to' => '2026-03-06',
        ], $this->actors[0]);
        $caseId = (int) $case['id'];

        $pdo = $this->db->pdo();
        $fixes = [
            'nempri_decisive_month_missing' => function () use ($cases, $caseId): void {
                $row = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
                $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $row['row_version'], [
                    'decisive_months' => [['period' => '2026-01', 'income_minor' => 4_000_000, 'excluded_days' => 0]],
                ]);
            },
            'nempri_decision_number_missing' => function () use ($cases, $caseId): void {
                $row = $cases->requireCase($this->supplierId, self::ENVIRONMENT, $caseId);
                $cases->update($this->supplierId, self::ENVIRONMENT, $caseId, (int) $row['row_version'], [
                    'decision_number' => 'E2468024',
                    'daily_working_hours' => '8',
                ]);
            },
            'nempri_activity_code_missing' => fn () => $pdo->prepare(
                'UPDATE payroll_employment_terms SET activity_code = "1" WHERE supplier_id = ? AND employment_id = ?',
            )->execute([$this->supplierId, $person['employment_id']]),
            'nempri_payment_connection_missing' => fn () => $this->payoutToAccount($person['employee_id']),
        ];
        $rounds = 0;
        for ($attempt = 0; $attempt <= count($fixes); $attempt++) {
            $response = $this->service(PayrollSicknessCaseAction::class)->preview(
                $this->request('GET', "/api/payroll/submissions/sickness-cases/{$caseId}/preview")
                    ->withQueryParams(['document' => 'nempri', 'environment' => self::ENVIRONMENT]),
                new Response(),
                ['caseId' => (string) $caseId],
            );
            if ($response->getStatusCode() === 200) {
                break;
            }
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            $error = $this->json($response)['error'];
            $rounds++;
            foreach (array_column($error['problems'] ?? [['code' => $error['code']]], 'code') as $code) {
                self::assertArrayHasKey($code, $fixes, 'Blokace bez známé nápravy: ' . CanonicalJson::encode($error));
                $fixes[$code]();
            }
        }
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        // Všechny čtyři chybějící údaje přišly v první odpovědi: jedno kolo oprav.
        self::assertSame(1, $rounds);
    }

    /**
     * Náhled hlásí chybějící údaje případu NAJEDNOU (komentář v
     * {@see SicknessSubmissionService::resolve()}) a každý s místem nápravy.
     * Dřív se rozhodné období, platební spojení a údaje vztahu zastavovaly
     * jedno po druhém (tři kola oprav) a odpověď nesla jen kód prvního:
     * obrazovka případů pak k měsíci, který počítá MyÚčto, nabídla Kontrolu
     * převodu mezd, ačkoli opravou je schválit mzdový běh.
     */
    public function testNempriPreviewListsAllProblemsWithTheirPlace(): void
    {
        $person = $this->createEmployment($this->officeId, 'Ilja Bezúčtový', 7, 'hpp', 'employment', 40, 10_000, true, '2026-01-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Ilja',
            'last_name' => 'Bezúčtový',
            'birth_date' => '1984-10-10',
            'sex' => 'male',
            'birth_number' => self::syntheticBirthNumber('1984-10-10', 'male', 7),
        ]);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employee_profiles SET payout_method = "bank" WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $person['employee_id']]);
        $pdo->prepare('UPDATE payroll_employment_terms SET activity_code = NULL WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $person['employment_id']]);
        $case = $this->service(SicknessCaseService::class)->create($this->supplierId, self::ENVIRONMENT, $person['employment_id'], 'NEM', [
            'incapacity_from' => '2026-02-09',
            'incapacity_to' => '2026-03-06',
        ], $this->actors[0]);

        $response = $this->service(PayrollSicknessCaseAction::class)->preview(
            $this->request('GET', '/api/payroll/submissions/sickness-cases/' . $case['id'] . '/preview')
                ->withQueryParams(['document' => 'nempri', 'environment' => self::ENVIRONMENT]),
            new Response(),
            ['caseId' => (string) $case['id']],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('nempri_decisive_month_missing', $error['code']);
        self::assertSame([
            'nempri_decisive_month_missing',
            'nempri_payment_connection_missing',
            'nempri_activity_code_missing',
            'nempri_decision_number_missing',
        ], array_column($error['problems'] ?? [], 'code'), CanonicalJson::encode($error));
        // Měsíc, který počítá MyÚčto, se opravuje schválením běhu za to období.
        self::assertSame(['2026-01'], $error['problems'][0]['payroll_periods']);
        self::assertSame([], $error['problems'][0]['takeover_periods']);
        self::assertStringContainsString('Doplňte účet na kartě osoby', $error['message']);
        self::assertStringContainsString('doplňte ho v podmínkách vztahu', $error['message']);
    }

    /** @return list<string> tiskopisy, které hlídač termínů u případu vede */
    private function watchedDocuments(int $caseId): array
    {
        $titles = [];
        foreach ($this->service(PayrollDeadlineOverviewService::class)->overview($this->supplierId, self::ENVIRONMENT, 400)['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $titles[] = (string) $item['title'];
            }
        }
        sort($titles);

        return $titles;
    }

    private static function compact(string $xml): string
    {
        return (string) preg_replace('/>\s+</', '><', $xml);
    }

    /** Náhrada mzdy při DPN zúčtovaná vztahu ve schváleném běhu (haléře). */
    private function compensation(array $run, int $employmentId): int
    {
        self::assertNotNull($run['approved']);
        $total = 0;
        foreach ($run['approved']->revision['result_snapshot']['people'] ?? [] as $person) {
            foreach ($person['employments'] ?? [] as $employment) {
                if ((int) $employment['employment_id'] !== $employmentId) {
                    continue;
                }
                foreach ($employment['inputs'] ?? [] as $input) {
                    if (($input['component_code'] ?? null) === 'NAHRADA_MZDY_DPN') {
                        $total += (int) $input['totals']['source_amount_minor'];
                    }
                }
            }
        }

        return $total;
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(int $sequence, string $name, string $sex, string $birthDate): array
    {
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, '2026-01-01');
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->payoutToAccount($person['employee_id']);

        return $person;
    }

    /**
     * Pravděpodobný výdělek (§ 355 odst. 2 ZP) pro čtvrtletí, ve kterém
     * zaměstnanec nemá odpracovaných 21 dnů v předchozím čtvrtletí.
     *
     * @return array<string,mixed>
     */
    private function probableAverage(int $employmentId, int $quarter, int $hourlyMinor): array
    {
        $start = new \DateTimeImmutable(sprintf('2026-%02d-01', (($quarter - 1) * 3) + 1));
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => $quarter,
                'decisive_from' => $start->modify('-3 months')->format('Y-m-d'),
                'decisive_to' => $start->modify('-1 day')->format('Y-m-d'),
                'gross_earnings_minor' => 0,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 0,
                'worked_days' => 0,
                'probable_hourly_minor' => $hourlyMinor,
                'rationale' => 'Syntetický nástup 1. 1. 2026, pravděpodobný výdělek podle smlouvy.',
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody([
                'row_version' => $average['row_version'],
            ]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return $average;
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, string $period, array $workedDates): void
    {
        $response = $this->approveTimeMonth($employmentId, $period, $workedDates);
        self::assertSame(200, $response->getStatusCode(), "Zaseknutí: docházka {$period}. " . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart, string $key): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, "nemx-{$key}-{$person['employment_id']}", $periodStart);
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
        array $extra = [],
    ): array {
        $created = $this->requestAbsence($employmentId, $type, $from, $to, $averageId, $extra);
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

    /** Mzda na účet 1000000005/0100 — NEMPRI z něj vezme platební spojení. */
    private function payoutToAccount(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employee_profiles SET payout_method = "bank" WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, effective_from)
             VALUES (?, ?, "Mzda", "enc:v2:pending", ?, "", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId, random_bytes(32)]);
        $accountId = (int) $pdo->lastInsertId();
        $sensitive = $this->service(PayrollSensitiveData::class);
        $sealed = $sensitive->seal('1000000005/0100', PayrollSensitiveField::BANK_ACCOUNT, $this->supplierId, $accountId);
        $pdo->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_ciphertext = ?, bank_account_hash = ?, bank_account_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $accountId]);
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
