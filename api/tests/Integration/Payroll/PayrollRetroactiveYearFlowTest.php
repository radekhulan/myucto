<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use Psr\Clock\ClockInterface;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Brána G7: celý rok 2026 zadaný zpětně v říjnu, mzdy vedené jen v MyÚčtu.
 *
 * Účetní nejdřív založí lidi a vztahy s datem v minulosti (nástupy, odchod,
 * změnu úvazku, nepřítomnosti, nároky na slevy) a pak postupně spočítá leden
 * až září. Na začátku čtvrtletí založí průměry hromadně z uzavřených běhů,
 * pro 1. čtvrtletí ručně z podkladů předchozího zpracování.
 *
 * Každý měsíc musí projít během bez blokace, přípravou a testovacím sestavením
 * JMHZ (XSD a katalog kontrol {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator}).
 * U lidí bez nepřítomnosti se hodnoty běhu i hlášení porovnávají s ručně
 * spočtenými očekáváními (hrubá, pojistné, záloha, srážka, čistá, fond,
 * odpracované hodiny a dny, dny pojištění a vyměřovací základ ELDP); u lidí
 * s nepřítomností se ověřují invarianty (čistá = hrubá − srážky, 10268 + 10275
 * = 10260, pojistné v celých korunách).
 *
 * Syntetická data, transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
#[Group('g7')]
final class PayrollRetroactiveYearFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const MONTHS = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'];
    /** Sleva na poplatníka 2026 měsíčně. */
    private const TAXPAYER_CREDIT = 2_570;
    /** Sleva na držitele průkazu ZTP/P 2026 měsíčně. */
    private const ZTPP_CREDIT = 1_345;
    /** Sleva na první dítě 2026 měsíčně. */
    private const FIRST_CHILD_CREDIT = 1_267;
    /** Minimální mzda 2026 = minimální vyměřovací základ zdravotního pojištění. */
    private const MINIMUM_WAGE = 22_400;

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    /** @var array<string,array<string,mixed>> */
    private array $people = [];
    /** @var array<int,true> */
    private array $calendars = [];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('G7', 'Syntetická účtárna G7', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        // Kód OSSZ podle číselníku pracovišť; bez něj případ NEMPRI nevznikne.
        $this->db->pdo()->prepare('UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->baseComponentId = $this->createComponent('MZDA_G7', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testWholeYearEnteredRetroactivelyProducesCorrectRunsAndReports(): void
    {
        $this->enterCompany();

        $failures = $this->quarterAverages(1);
        foreach (self::MONTHS as $period) {
            $month = (int) substr($period, 5, 2);
            if ($month === 4 || $month === 7) {
                $failures = [...$failures, ...$this->quarterAverages(intdiv($month - 1, 3) + 1)];
            }
            $failures = [...$failures, ...$this->processMonth($period)];
        }
        self::assertSame([], $failures, implode("\n", $failures));

        $this->assertSicknessReports();
        $this->assertHealthOverview();
        $this->assertAnnualEldp();
    }

    /**
     * NEMPRI a HZUPN z nemoci 25. 3. – 10. 4. (17 dnů, případ vzniká).
     * Rozhodné období 1. 3. 2025 – 28. 2. 2026: leden a únor 2026 z běhů
     * MyÚčta (40 000 Kč), březen až prosinec 2025 ručně z výplatních listin.
     */
    private function assertSicknessReports(): void
    {
        $employmentId = $this->people['nemoc']['person']['employment_id'];
        $caseId = (int) $this->scalar(
            'SELECT id FROM payroll_sickness_cases WHERE supplier_id = ? AND employment_id = ? AND benefit_kind = "NEM" ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );
        self::assertGreaterThan(0, $caseId, 'Nemoc delší než 14 dnů musí založit případ NEMPRI: ' . CanonicalJson::encode($this->people['nemoc']['absences'][0]['sickness_case'] ?? null));
        $environment = (string) $this->scalar('SELECT environment FROM payroll_sickness_cases WHERE supplier_id = ? AND id = ?', [$this->supplierId, $caseId]);
        $cases = $this->container->get(SicknessCaseService::class);
        self::assertInstanceOf(SicknessCaseService::class, $cases);
        $case = $cases->requireCase($this->supplierId, $environment, $caseId);
        self::assertSame('2026-03-25', $case['incapacity_from']);
        self::assertSame('2026-04-10', $case['incapacity_to']);
        $months = [];
        for ($cursor = new \DateTimeImmutable('2025-03-01'); $cursor->format('Y-m') <= '2025-12'; $cursor = $cursor->modify('+1 month')) {
            $months[] = ['period' => $cursor->format('Y-m'), 'income_minor' => 40_000_00, 'excluded_days' => 0];
        }
        $this->db->pdo()->prepare('UPDATE payroll_employee_profiles SET payout_method = "cash" WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $this->people['nemoc']['person']['employee_id']]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Zkušební 12", "Testov", "11000", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $this->people['nemoc']['person']['employee_id']]);
        $cases->update($this->supplierId, $environment, $caseId, (int) $case['row_version'], [
            'decision_number' => 'E7654321',
            'daily_working_hours' => '8',
            'issued_on' => '2026-04-13',
            'returned_to_work' => '1',
            'returned_on' => '2026-04-13',
            'hours_worked_last_day' => '8',
            'shift_hours_last_day' => '8',
            'decisive_months' => $months,
        ]);
        $submissions = $this->container->get(SicknessSubmissionService::class);
        self::assertInstanceOf(SicknessSubmissionService::class, $submissions);
        $nempri = (string) preg_replace('/>\s+</', '><', (string) $submissions->preview($this->supplierId, $environment, $caseId, SicknessDocumentKind::Nempri)['xml']);
        self::assertStringContainsString('<rozhodneObdobiOd>2025-03-01</rozhodneObdobiOd>', $nempri);
        self::assertStringContainsString('<rozhodneObdobiDo>2026-02-28</rozhodneObdobiDo>', $nempri);
        self::assertSame(12, substr_count($nempri, '<zapocitatelnyPrijem>'), $nempri);
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>480000</zapocitatelnyPrijemCelkem>', $nempri);
        $hzupn = (string) $submissions->preview($this->supplierId, $environment, $caseId, SicknessDocumentKind::Hzupn)['xml'];
        self::assertStringContainsString('<datumNavratDoPrace>2026-04-13</datumNavratDoPrace>', $hzupn);
    }

    /** Přehled o platbě pojistného ZP za září: jedna pojišťovna, souhrn ze schválené revize. */
    private function assertHealthOverview(): void
    {
        $action = $this->container->get(PayrollHealthInsuranceOverviewAction::class);
        self::assertInstanceOf(PayrollHealthInsuranceOverviewAction::class, $action);
        $revisionId = (int) $this->scalar(
            'SELECT revision.id FROM payroll_run_revisions revision JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE run.supplier_id = ? AND run.period_start = "2026-09-01" ORDER BY revision.id DESC LIMIT 1',
            [$this->supplierId],
        );
        $response = $action->index(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}"),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $overview = $this->json($response);
        self::assertSame('111', $overview['items'][0]['insurer']['code'] ?? null, CanonicalJson::encode($overview));
        $download = $action->download(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$revisionId}/111/download"),
            new Response(),
            ['revisionId' => (string) $revisionId, 'insurerCode' => '111'],
        );
        self::assertSame(200, $download->getStatusCode(), (string) $download->getBody());
    }

    /** Evidenční list stálé zaměstnankyně za leden až září na výzvu: 273 dnů, základ 360 000 Kč. */
    private function assertAnnualEldp(): void
    {
        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        $employmentId = $this->people['stala']['person']['employment_id'];
        try {
            $service->prepare($this->supplierId, $employmentId, 2026, 'test', [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'requested_by_authority' => true,
                'authority_request_received_on' => '2026-10-05',
                'note' => 'Syntetická výzva G7.',
            ], 'g7-eldp-stala', $this->actors[0]);
        } catch (EldpValidationException $exception) {
            self::fail("ELDP odmítnut ({$exception->validationCode}): " . CanonicalJson::encode($exception->blockers));
        }
        $sections = $service->statement($this->supplierId, 'test', $employmentId, 2026)['payload']['eldp_sections'] ?? [];
        self::assertSame(273, array_sum(array_map(static fn (array $s): int => (int) ($s['days'] ?? $s['insurance_days'] ?? 0), $sections)));
        self::assertSame(360_000, array_sum(array_map(static fn (array $s): int => (int) ($s['assessment_base_czk'] ?? 0), $sections)));
    }

    /**
     * REGZEC zpětně: 9. 10. účetní zakládá nástup z 2. 3. a odchod k 30. 6.
     * Přihláška i odhláška se dají připravit (pozdě, ale neblokují), nesou
     * historická data a lhůta se počítá od skutečné události, ne od dneška.
     */
    /**
     * Varianta: leden až červenec zpracováno a hlášení přijatá, pak účetní
     * zjistí, že v březnu chyběla odměna 2 000 Kč. Oprava březnového běhu
     * přepočte jen březen, opravné hlášení JMHZ nese březen (typ O) s novými
     * částkami, pozdější běhy zůstanou a průměr 2. čtvrtletí (rozhodné období
     * 1. čtvrtletí) se ukáže jako neaktuální.
     */
    public function testMarchCorrectionAfterJulyRecalculatesMarchOnly(): void
    {
        $this->fileJmhz = true;
        $this->hire('stala', 'Syntetická Stálá', '1985-05-12', 'female', 'hpp', 40, true, annual: 'requested',
            gross: static fn (string $p): int => 40_000);
        $this->hire('soubeh', 'Syntetický Souběžný', '1983-07-07', 'male', 'hpp', 40, true,
            gross: static fn (string $p): int => 30_000);

        $failures = $this->quarterAverages(1);
        foreach (array_slice(self::MONTHS, 0, 7) as $period) {
            $month = (int) substr($period, 5, 2);
            if ($month === 4 || $month === 7) {
                $failures = [...$failures, ...$this->quarterAverages(intdiv($month - 1, 3) + 1)];
            }
            $failures = [...$failures, ...$this->processMonth($period)];
        }
        self::assertSame([], $failures, implode("\n", $failures));
        $later = [];
        foreach (['2026-04', '2026-05', '2026-06', '2026-07'] as $period) {
            $later[$period] = $this->scalar('SELECT CONCAT(status, ":", current_revision_no, ":", row_version) FROM payroll_runs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, (int) $this->approvedRuns[$period]['id']]);
        }

        // Zapomenutá březnová odměna stálé zaměstnankyně.
        $stala = $this->people['stala']['person'];
        $this->createApprovedInput($stala, $this->baseComponentId, 2_000_00, 'g7-stala-2026-03-odmena', '2026-03-01');
        $corrected = $this->correctPayrollRun($this->approvedRuns['2026-03'], 'g7-march-fix', 'Doplněna březnová odměna.');
        self::assertSame('approved', $corrected->run['status']);
        $revisionId = (int) $corrected->revision['id'];
        $result = $this->personResult($revisionId, $stala['employee_id']);
        self::assertNotNull($result);
        self::assertSame(42_000_00, $result['cash_income']);
        self::assertSame(2_982_00, $result['employee_social'], '7,1 % z 42 000');
        self::assertSame(1_890_00, $result['employee_health'], 'třetina z 13,5 % z 42 000');
        self::assertSame(3_730_00, $result['advance_tax'], '6 300 − 2 570');
        $other = $this->personResult($revisionId, $this->people['soubeh']['person']['employee_id']);
        self::assertSame(30_000_00, $other['cash_income'] ?? null, 'Oprava nesmí měnit ostatní lidi.');
        foreach ($later as $period => $state) {
            self::assertSame($state, $this->scalar('SELECT CONCAT(status, ":", current_revision_no, ":", row_version) FROM payroll_runs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, (int) $this->approvedRuns[$period]['id']]), "Běh {$period} se opravou března nemá měnit.");
        }

        // Opravné hlášení JMHZ za březen.
        $preparation = $this->prepareJmhz($revisionId, 'g7-march-fix');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $corrections = $this->container->get(JmhzContentCorrectionSubmissionService::class);
        self::assertInstanceOf(JmhzContentCorrectionSubmissionService::class, $corrections);
        $candidates = $corrections->candidates($this->supplierId, 'test', $this->submissions['2026-03'], (int) $preparation['body']['id']);
        // G7-D3: kandidáti označí, který přijatý formulář se proti podání změnil.
        $flags = array_column($candidates['forms'], 'changed', 'employee_name');
        self::assertSame(['Syntetická Stálá' => true, 'Syntetický Souběžný' => false], $flags, CanonicalJson::encode($candidates));
        $changed = array_values(array_filter($candidates['forms'], static fn (array $f): bool => $f['changed'] === true));
        $frozen = $corrections->freeze(
            $this->supplierId,
            'test',
            $this->submissions['2026-03'],
            (int) $preparation['body']['id'],
            [(string) $changed[0]['employment_external_identifier']],
            $this->actors[0],
        );
        self::assertSame('correction', $frozen['submission_kind']);
        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);
        $xml = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', (int) $frozen['submission_id']));
        self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
        self::assertStringContainsString('<mesic>3</mesic>', $xml, 'Opravné hlášení nese období opravovaného měsíce.');
        self::assertStringContainsString('<form:zuctovanoCelkem>42000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:danZalohaPoSleve>3730</form:danZalohaPoSleve>', $xml);
        // Pojistná část a souhrn opravy nahrazují úhrn zaměstnavatele za celý měsíc.
        self::assertStringContainsString('<pvpoj:zakladZamestnavateleA>72000</pvpoj:zakladZamestnavateleA>', $xml);
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>5112</pvpoj:pojistneZamestnance>', $xml, '2 982 + 2 130');
        self::assertStringContainsString('<so:danZalohaPoSleve>5660</so:danZalohaPoSleve>', $xml, '3 730 + 1 930');
        self::assertSame(1, substr_count($xml, '</formularOsoby>'));

        // Průměr 2. čtvrtletí stojí na březnu: po opravě musí být vidět, že neplatí.
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        $items = array_column($batch->page($this->supplierId, 2026, 2, 100)['items'], null, 'employment_id');
        $item = $items[$stala['employment_id']];
        self::assertTrue($item['existing_outdated'] ?? null, 'Schválený průměr Q2 je po opravě března neaktuální: ' . CanonicalJson::encode($item));
        self::assertFalse($items[$this->people['soubeh']['person']['employment_id']]['existing_outdated'] ?? null);
    }

    /**
     * G7-D2: mateřská přes očekávaný den porodu bez skutečného dne porodu.
     * Příprava JMHZ musí říct konkrétně „doplňte den porodu", ne obecné
     * „nepřítomnost nelze bezpečně odvodit, zpracujte individuálně".
     */
    public function testMaternityWithoutChildbirthDateNamesTheFix(): void
    {
        $this->hire('matka', 'Syntetická Matka', '1993-03-03', 'female', 'hpp', 40, true,
            gross: static fn (string $p): int => $p < '2026-07' ? 30_000 : 0);
        $this->absenceFrom('matka', 'ppm', '2026-07-01', '2026-12-31');

        $failures = $this->quarterAverages(1);
        foreach (array_slice(self::MONTHS, 0, 7) as $period) {
            $month = (int) substr($period, 5, 2);
            if ($month === 4 || $month === 7) {
                $failures = [...$failures, ...$this->quarterAverages(intdiv($month - 1, 3) + 1)];
            }
            $failures = [...$failures, ...$this->processMonth($period)];
        }
        self::assertSame([], $failures, implode("\n", $failures));

        $august = implode("\n", $this->processMonth('2026-08'));
        self::assertStringContainsString('jmhz_eldp_ppm_childbirth_missing', $august);
        self::assertStringNotContainsString('jmhz_eldp_absences_unsupported', $august);
        self::assertStringContainsString('den porodu', JmhzBlockerExplainer::action('jmhz_eldp_ppm_childbirth_missing'));

        // Účetní doplní den porodu a příprava projde.
        $absenceId = (int) $this->people['matka']['absences'][0]['id'];
        $recorded = $this->absences->childbirth(
            $this->request('POST', "/api/payroll/absences/{$absenceId}/childbirth")->withParsedBody([
                'row_version' => (int) $this->scalar('SELECT row_version FROM payroll_absences WHERE supplier_id = ? AND id = ?', [$this->supplierId, $absenceId]),
                'childbirth_date' => '2026-08-18',
            ]),
            new Response(),
            ['id' => (string) $absenceId],
        );
        self::assertSame(200, $recorded->getStatusCode(), (string) $recorded->getBody());
        // Revize běhu nepřítomnosti zmrazila, den porodu se do ní dostane opravou běhu.
        $corrected = $this->correctPayrollRun($this->approvedRuns['2026-08'], 'g7-august-birth', 'Doplněn den porodu.');
        $preparation = $this->prepareJmhz((int) $corrected->revision['id'], 'g7-august-after-birth');
        self::assertSame('source_ready', $preparation['body']['readiness_status'] ?? null, CanonicalJson::encode($preparation['body']));
    }

    public function testRetroactiveRegistrationsKeepHistoricalDates(): void
    {
        $calendar = new class () {
            public string $today = '2026-10-09';
        };
        $clock = new class ($calendar) implements ClockInterface {
            /** @param object{today:string} $calendar */
            public function __construct(private readonly object $calendar) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->calendar->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
            }
        };
        self::assertInstanceOf(\DI\Container::class, $this->container);
        $this->container->set(ClockInterface::class, $clock);
        $registration = $this->container->get(PayrollRegistrationAction::class);
        self::assertInstanceOf(PayrollRegistrationAction::class, $registration);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110") ON DUPLICATE KEY UPDATE social_security_office_code = "110"',
        )->execute([$this->supplierId, $this->officeId]);

        $people = $this->container->get(PayrollPersonCreateService::class);
        self::assertInstanceOf(PayrollPersonCreateService::class, $people);
        $created = $people->create($this->supplierId, [
            'full_name' => 'Syntetická Zpětná',
            'first_name' => 'Syntetická',
            'last_name' => 'Zpětná',
            'birth_date' => '1994-04-15',
            'birth_number' => self::syntheticBirthNumber('1994-04-15', 'female', 41),
            'relation_type' => 'employment',
            'planned_start_on' => '2026-03-02',
            'office_id' => $this->officeId,
            'health_insurer_code' => '111',
        ], $this->actors[0], null, null);
        $employeeId = (int) $created['id'];
        $employmentId = (int) $this->scalar(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->completeRegistrationCard($employeeId, $employmentId);
        $this->saveA1Profile($registration, $employmentId, self::a1Profile('2026-03-02'));

        // Přihláška A1 sedm měsíců po nástupu.
        $a1 = $this->prepareRegistration($registration, $employmentId, ['registration_mode' => 'full']);
        self::assertSame('REGZEC25', $a1['agenda_code'], CanonicalJson::encode($a1));
        self::assertSame('2026-03-02', $a1['deadline']['due_on'], 'Lhůta A1 je den nástupu, ne den zadání.');
        $a1Xml = $this->artifactXml((int) $a1['submission_id']);
        self::assertStringContainsString('act="1"', $a1Xml);
        self::assertStringContainsString(' fro="2026-03-02"', $a1Xml);
        $this->acceptRegistration((int) $a1['submission_id'], (int) $a1['row_version'], (int) $a1['part_id'], true);
        $this->transitionEmployment($employmentId, 'active', '2026-03-02');

        // Odchod k 30. 6. zadaný v říjnu.
        $this->transitionEmployment($employmentId, 'ended', '2026-06-30');
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $termination->save($this->supplierId, $employmentId, ['termination_method' => 'employee_notice'], $this->actors[0]);
        $event = $registration->approveEvent(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}/events")->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-06-30',
                'ended_by_death' => false,
                'unemployment' => ['mode' => 'not_provided_2'],
            ]),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $event->getStatusCode(), 'Zaseknutí: zpětná odhláška A2. ' . (string) $event->getBody());
        $a2 = $this->prepareRegistration($registration, $employmentId, ['event_id' => $this->json($event)['id']]);
        self::assertSame('termination', $a2['interaction']);
        self::assertSame('2026-07-08', $a2['deadline']['due_on'], 'Lhůta A2 je 8 dní od skončení, ne od zadání.');
        $a2Xml = $this->artifactXml((int) $a2['submission_id']);
        self::assertStringContainsString('act="2"', $a2Xml);
        self::assertStringContainsString(' to="2026-06-30"', $a2Xml);
    }

    /**
     * Lidé a vztahy, jak je účetní zadá v říjnu: všechno s datem v minulosti.
     */
    private function enterCompany(): void
    {
        $this->hire('stala', 'Syntetická Stálá', '1985-05-12', 'female', 'hpp', 40, true, annual: 'requested',
            gross: static fn (string $p): int => 40_000);

        $this->hire('zmena', 'Syntetický Změnový', '1979-11-03', 'male', 'hpp', 40, true,
            gross: static fn (string $p): int => $p < '2026-05' ? 36_000 : 30_000,
            daily: static fn (string $p): int => $p < '2026-05' ? 480 : 360);
        $this->changeTermsFrom('zmena', '2026-05-01', 30, 7_500);

        $this->hire('nastup', 'Syntetická Nástupní', '1996-08-21', 'female', 'hpp', 40, true, start: '2026-03-02',
            gross: static fn (string $p): ?int => $p < '2026-03' ? null : 32_000);

        $this->hire('odchod', 'Syntetický Odchodový', '1990-01-15', 'male', 'hpp', 40, true, end: '2026-06-30',
            gross: static fn (string $p): ?int => $p > '2026-06' ? null : 35_000);

        // DPP bez prohlášení: leden pod limitem (srážka), březen nad limitem
        // (pojištění, záloha bez slevy), červen pod limitem; jinak nepracuje.
        $this->hire('dpp', 'Syntetický Dohodář', '2001-04-30', 'male', 'dpp', 10, false,
            gross: static fn (string $p): ?int => ['2026-01' => 8_000, '2026-03' => 15_000, '2026-06' => 10_000][$p] ?? 0,
            daily: static fn (string $p): int => 240,
            worked: static fn (string $p): array => array_slice(self::workdays($p), 0, 3));

        $this->hire('dpc', 'Syntetická Činnostní', '1988-02-14', 'female', 'dpc', 10, true,
            gross: static fn (string $p): int => 5_000,
            daily: static fn (string $p): int => 120);

        // Souběh: HPP s prohlášením a DPP téže osoby v únoru a dubnu.
        $this->hire('soubeh', 'Syntetický Souběžný', '1983-07-07', 'male', 'hpp', 40, true,
            gross: static fn (string $p): int => 30_000);
        $this->hire('soubeh_dpp', 'Syntetický Souběžný', '1983-07-07', 'male', 'dpp', 10, true, sameAs: 'soubeh',
            gross: static fn (string $p): ?int => in_array($p, ['2026-02', '2026-04'], true) ? 6_000 : 0,
            daily: static fn (string $p): int => 240,
            worked: static fn (string $p): array => array_slice(self::workdays($p), -2));

        // Nemoc přes přelom měsíce (25. 3. – 10. 4.) a OČR 11.–13. 5.
        $this->hire('nemoc', 'Syntetická Nemocná', '1987-09-09', 'female', 'hpp', 40, true,
            gross: static fn (string $p): int => 40_000);
        $this->absenceFrom('nemoc', 'dpn', '2026-03-25', '2026-04-10');
        $this->absenceFrom('nemoc', 'ocr', '2026-05-11', '2026-05-13');

        // Mateřská od 1. 7. (porod očekáván 20. 8.), bez mzdy.
        $this->hire('matka', 'Syntetická Matka', '1993-03-03', 'female', 'hpp', 40, true,
            gross: static fn (string $p): int => $p < '2026-07' ? 30_000 : 0);
        $this->absenceFrom('matka', 'ppm', '2026-07-01', '2026-12-31', childbirth: '2026-08-18');

        // Sleva na dítě od července a neplacené volno 10.–14. 8.
        $this->hire('dite', 'Syntetický Rodič', '1989-12-12', 'male', 'hpp', 40, true,
            gross: static fn (string $p): int => $p === '2026-08' ? 28_952 : 38_000);
        $this->childFrom('dite', '2026-07-01');
        $this->absenceFrom('dite', 'unpaid_leave', '2026-08-10', '2026-08-14');

        $this->hire('ztpp', 'Syntetická Průkazová', '1975-06-06', 'female', 'hpp', 40, true,
            gross: static fn (string $p): int => 28_000);
        $this->creditFrom('ztpp', 'ztp-p', '2026-01-01');

        $this->hire('duchodce', 'Syntetický Důchodce', '1958-06-21', 'male', 'hpp', 40, true, socialDiscount: 'verified',
            gross: static fn (string $p): int => 25_000);
    }

    /** @return list<string> */
    private function processMonth(string $period): array
    {
        $periodStart = "{$period}-01";
        foreach ($this->people as $key => &$p) {
            if (!self::activeIn($p, $period)) {
                continue;
            }
            foreach ($p['absences'] as $index => $absence) {
                if (substr($absence['from'], 0, 7) === $period && !isset($absence['id'])) {
                    $average = $absence['type'] === 'dpn' ? $this->latestAverageId($p['person']['employment_id']) : null;
                    $created = $this->requestAbsence(
                        $p['person']['employment_id'],
                        $absence['type'],
                        $absence['from'],
                        $absence['to'],
                        $average,
                        $absence['extra'],
                    );
                    if ($created->getStatusCode() !== 201) {
                        return ["{$period} {$key}: nepřítomnost {$absence['type']} (průměr {$average}) odmítnuta: " . (string) $created->getBody()];
                    }
                    $row = $this->json($created)['absence'];
                    $decision = $this->absences->decision(
                        $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($absence['decision'] + [
                            'row_version' => $row['row_version'],
                            'decision' => 'approved',
                        ]),
                        new Response(),
                        ['id' => (string) $row['id']],
                    );
                    if ($decision->getStatusCode() !== 200) {
                        return ["{$period} {$key}: schválení nepřítomnosti {$absence['type']}: " . (string) $decision->getBody()];
                    }
                    $p['absences'][$index]['id'] = (int) $row['id'];
                    $p['absences'][$index]['sickness_case'] = $this->json($decision)['sickness_case'] ?? null;
                }
                $birth = $absence['childbirth'] ?? null;
                if ($birth !== null && substr($birth, 0, 7) === $period) {
                    // Den porodu doplní účetní u mateřské, jakmile ho zná.
                    $absenceId = $p['absences'][$index]['id'];
                    $recorded = $this->absences->childbirth(
                        $this->request('POST', "/api/payroll/absences/{$absenceId}/childbirth")->withParsedBody([
                            'row_version' => (int) $this->scalar('SELECT row_version FROM payroll_absences WHERE supplier_id = ? AND id = ?', [$this->supplierId, $absenceId]),
                            'childbirth_date' => $birth,
                        ]),
                        new Response(),
                        ['id' => (string) $absenceId],
                    );
                    if ($recorded->getStatusCode() !== 200) {
                        return ["{$period} {$key}: den porodu: " . (string) $recorded->getBody()];
                    }
                }
            }
            $this->db->pdo()->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $p['person']['employee_id'], $periodStart, $this->actors[0]]);
            $gross = ($p['gross'])($period);
            if ($gross === null) {
                continue;
            }
            $this->ensureCalendar($p, $period);
            $time = $this->approveTimeMonth(
                $p['person']['employment_id'],
                $period,
                $this->workedDates($p, $period),
                dailyMinutes: ($p['daily'])($period),
            );
            if ($time->getStatusCode() !== 200) {
                return ["{$period} {$key}: docházka neprošla: " . (string) $time->getBody()];
            }
            // Vztah trvá i v měsíci bez příjmu: účetní zadá základní složku 0 Kč.
            $this->createApprovedInput($p['person'], $this->baseComponentId, $gross * 100, "g7-{$key}-{$period}", $periodStart);
        }
        unset($p);

        $payday = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-10');
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "g7-{$period}");
        if ($run['blockers'] !== [] || $run['warnings'] !== []) {
            return ["{$period}: běh zablokován: " . CanonicalJson::encode([...$run['blockers'], ...$run['warnings']])];
        }
        $revisionId = (int) $run['approved']->revision['id'];
        $this->approvedRuns[$period] = $run['approved']->run;
        $preparation = $this->prepareJmhz($revisionId, "g7-{$period}");
        if ($preparation['status'] !== 201 || ($preparation['body']['readiness_status'] ?? null) !== 'source_ready') {
            return ["{$period}: příprava JMHZ: " . mb_substr(CanonicalJson::encode($preparation['body']['issues'] ?? $preparation['body']), 0, 3000)];
        }
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        if ($tested['status'] !== 200 || ($tested['body']['status'] ?? null) !== 'dry_run_valid') {
            return ["{$period}: sestavení JMHZ: " . mb_substr(CanonicalJson::encode($tested['body']['blockers'] ?? $tested['body']['controls'] ?? $tested['body']), 0, 3000)];
        }
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        $this->xmls[$period] = $xml;
        if ($this->fileJmhz) {
            // Pozdní podání minulého měsíce: zmrazit a přijmout bez přepsání období.
            $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
            if ($frozen['status'] !== 201) {
                return ["{$period}: zmrazení pozdního hlášení: " . CanonicalJson::encode($frozen['body'])];
            }
            $this->submissions[$period] = (int) $frozen['body']['submission_id'];
            $this->acceptJmhzSubmission($this->submissions[$period]);
        }

        return $this->checkMonth($period, $revisionId, $xml);
    }

    /** @var array<string,string> */
    private array $xmls = [];
    /** @var array<string,array<string,mixed>> */
    private array $approvedRuns = [];
    private bool $fileJmhz = false;
    /** @var array<string,int> */
    private array $submissions = [];

    /**
     * Ručně spočtená očekávání proti běhu a hlášení.
     *
     * @return list<string>
     */
    private function checkMonth(string $period, int $revisionId, string $xml): array
    {
        $failures = [];
        $forms = self::forms($xml);
        foreach ($this->people as $key => $p) {
            $gross = ($p['gross'])($period);
            if ($gross === null) {
                if (self::activeIn($p, $period) && $p['relation'] !== 'dpp') {
                    $failures[] = "{$period} {$key}: aktivní vztah bez zadání";
                }
                continue;
            }
            $form = $forms[$p['ppv']] ?? null;
            if ($form === null) {
                $failures[] = "{$period} {$key}: v hlášení chybí formulář vztahu";
                continue;
            }
            $prefix = "{$period} {$key}";
            $workdays = self::workdays($period);
            $first = max("{$period}-01", $p['start']);
            $last = min((new \DateTimeImmutable("{$period}-01"))->format('Y-m-t'), $p['end'] ?? '9999-12-31');
            $evidenceDays = count(self::dateRange($first, $last));
            // Dohody ani mateřská (PPM) se do evidenčního stavu nepočítají (metodika ČSÚ).
            $maternity = count(array_filter(
                $this->absentIn($p, $period, ['ppm', 'parental']),
                static fn (string $d): bool => $d >= $first && $d <= $last,
            ));
            $this->expectXml($failures, $prefix, $form, 'dnyEvidencniStav', $p['relation'] === 'hpp' ? (string) ($evidenceDays - $maternity) : '0');

            $absent = $this->absentIn($p, $period);
            if ($p['relation'] === 'hpp' && $absent === []) {
                $hours = count($this->workedDates($p, $period)) * ($p['daily'])($period) / 60;
                // Sjednaný fond 10260 zahrnuje i svátky připadající na pracovní den.
                $weekdays = array_filter(
                    self::dateRange($first, $last),
                    static fn (string $d): bool => (int) (new \DateTimeImmutable($d))->format('N') <= 5,
                );
                $fund = count($weekdays) * ($p['daily'])($period) / 60;
                $this->expectXml($failures, $prefix, $form, 'sjednanyFond', sprintf('%.3f', $fund));
                $this->expectXml($failures, $prefix, $form, 'odpracovaneHodiny', sprintf('%.3f', $hours));
                $this->expectXml($failures, $prefix, $form, 'dnyOdpracovanePocet', (string) count($this->workedDates($p, $period)));
                // Poživatel starobního důchodu: třída ELDP se nehlásí, 10356 = 0
                // (metodika MPSV/ČSSZ, JmhzEldpEvidenceBuilder).
                $this->expectXml($failures, $prefix, $form, 'pocetDnu', $p['social_discount'] === 'verified' ? '0' : (string) $evidenceDays);
            }
            if ($p['same_as'] !== null) {
                continue;
            }
            $expected = $absent === [] ? $this->expectedMoney($p, $period) : null;
            $result = $this->personResult($revisionId, $p['person']['employee_id']);
            if ($result === null) {
                $failures[] = "{$prefix}: běh nemá výsledek čisté mzdy";
                continue;
            }
            $net = $result['cash_income'] - $result['employee_social'] - $result['employee_health'] - $result['advance_tax']
                - $result['withholding_tax'] + $result['tax_bonus'] - $result['deducted'];
            if ($net !== $result['net_payable']) {
                $failures[] = "{$prefix}: k výplatě {$result['net_payable']} ≠ hrubá − srážky {$net}";
            }
            foreach (['employee_social', 'employee_health', 'advance_tax', 'withholding_tax'] as $field) {
                if ($result[$field] % 100 !== 0) {
                    $failures[] = "{$prefix}: {$field} {$result[$field]} není v celých korunách";
                }
            }
            if ($expected === null) {
                continue;
            }
            foreach ($expected as $field => $value) {
                if ($field === 'eldp_base') {
                    $this->expectXml($failures, $prefix, $form, 'eldp_vymerovaciZaklad', $value === null ? null : (string) $value);
                    continue;
                }
                if ($value === null) {
                    continue;
                }
                if (intdiv($result[$field], 100) !== $value) {
                    $failures[] = sprintf('%s: %s %s ≠ očekáváno %s', $prefix, $field, $result[$field] / 100, $value);
                }
            }
        }

        return $failures;
    }

    /**
     * Ručně spočtená očekávání peněz osoby v měsíci bez nepřítomnosti.
     *
     * Pojistné zaměstnance: sociální 7,1 % (pracující důchodce se slevou 0,6 %),
     * zdravotní třetina z 13,5 %; záloha 15 % ze základu zaokrouhleného na
     * koruny nahoru, mínus slevy; u DPP bez prohlášení pod 12 000 Kč srážka 15 %.
     *
     * @param array<string,mixed> $p
     * @return array<string,?int>
     */
    private function expectedMoney(array $p, string $period): array
    {
        $gross = 0;
        $insuredBase = 0;
        $withheldBase = 0;
        foreach ($this->people as $other) {
            if ($other['person']['employee_id'] !== $p['person']['employee_id']) {
                continue;
            }
            $amount = ($other['gross'])($period) ?? 0;
            $gross += $amount;
            $insured = match ($other['relation']) {
                'hpp' => true,
                'dpp' => $amount >= 12_000,
                'dpc' => $amount >= 4_500,
            };
            if ($insured) {
                $insuredBase += $amount;
            }
            if ($other['relation'] === 'dpp' && !$other['declaration'] && $amount < 12_000) {
                $withheldBase += $amount;
            }
        }
        $social = (int) ceil($insuredBase * ($p['social_discount'] === 'verified' ? 0.006 : 0.071) - 1e-9);
        $health = intdiv((int) ceil($insuredBase * 0.135 - 1e-9), 3);
        // § 3 odst. 4, 6 a 10 zákona 592/1992: minimum je minimální mzda (22 400 Kč)
        // i u dohod; rozdíl doplácí zaměstnanec. Výjimka § 3 odst. 8 písm. a) ZTP/P.
        if ($insuredBase > 0 && $insuredBase < self::MINIMUM_WAGE && $p['credits'] === []) {
            $health += (int) ceil((self::MINIMUM_WAGE - $insuredBase) * 0.135 - 1e-9);
        }
        $advanceBase = $gross - $withheldBase;
        $tax = (int) ceil($advanceBase * 0.15 - 1e-9);
        if ($p['declaration']) {
            $credits = self::TAXPAYER_CREDIT + array_sum(array_map(
                static fn (array $c): int => $period >= substr($c['from'], 0, 7) ? $c['monthly'] : 0,
                $p['credits'],
            ));
            $tax = max(0, $tax - $credits);
        }
        $withholding = intdiv($withheldBase * 15, 100);

        return [
            'cash_income' => $gross,
            'employee_social' => $social,
            'employee_health' => $health,
            // Sleva důchodce na dani se řídí zvláštními pravidly; hlídáme jen pojistné.
            'advance_tax' => $p['social_discount'] === 'verified' ? null : $tax,
            'withholding_tax' => $withholding,
            'net_payable' => $p['social_discount'] === 'verified'
                ? null
                : $gross - $social - $health - $tax - $withholding,
            'eldp_base' => $insuredBase > 0 && $p['social_discount'] !== 'verified' ? $insuredBase : null,
        ];
    }

    /** @param list<string> $failures */
    private function expectXml(array &$failures, string $prefix, string $form, string $element, ?string $expected): void
    {
        $actual = match ($element) {
            'odpracovaneHodiny' => preg_match('#<form:odpracovaneHodiny><form:pocet>([\d.]+)</form:pocet>#', $form, $m) === 1 ? $m[1] : null,
            'eldp_vymerovaciZaklad' => preg_match('#<form:eldp>.*?<form:vymerovaciZaklad>(\d+)</form:vymerovaciZaklad>#', $form, $m) === 1 ? $m[1] : null,
            default => preg_match('#<form:' . $element . '>([^<]*)</form:' . $element . '>#', $form, $m) === 1 ? $m[1] : null,
        };
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: %s v hlášení %s ≠ očekáváno %s', $prefix, $element, $actual ?? '(chybí)', $expected ?? '(nemá být)');
        }
    }

    /** @return ?array<string,int> */
    private function personResult(int $revisionId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT person.result_snapshot_json
               FROM payroll_statutory_person_results person
               JOIN payroll_statutory_results result
                 ON result.supplier_id = person.supplier_id AND result.id = person.statutory_result_id
              WHERE result.supplier_id = ? AND result.revision_id = ? AND result.calculation_kind = "net_pay"',
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string) $json, true);
            if (is_array($row) && in_array("employee:{$employeeId}", [$row['person_reference'] ?? null, $row['person_id'] ?? null], true)) {
                $out = [];
                foreach (['cash_income', 'employee_social', 'employee_health', 'advance_tax', 'withholding_tax', 'tax_bonus', 'deducted', 'net_payable'] as $field) {
                    $out[$field] = (int) ($row["{$field}_minor_units"] ?? 0);
                }

                return $out;
            }
        }

        return null;
    }

    /** @return array<string,string> identifikátor PPV => formulář */
    private static function forms(string $xml): array
    {
        $forms = [];
        foreach (explode('</formularOsoby>', $xml) as $chunk) {
            if (preg_match('#<form:idPpv>(\d+)</form:idPpv>#', $chunk, $m) === 1) {
                $forms[$m[1]] = $chunk;
            }
        }

        return $forms;
    }

    /**
     * @param array<string,mixed> $p
     * @return list<string>
     */
    private function workedDates(array $p, string $period): array
    {
        $absent = $this->absentIn($p, $period);
        $dates = ($p['gross'])($period) === 0 ? [] : ($p['worked'])($period);

        return array_values(array_filter(
            $dates,
            static fn (string $d): bool => $d >= $p['start'] && ($p['end'] === null || $d <= $p['end']) && !in_array($d, $absent, true),
        ));
    }

    /**
     * @param array<string,mixed> $p
     * @return list<string>
     */
    private function absentIn(array $p, string $period, ?array $types = null): array
    {
        $days = [];
        foreach ($p['absences'] as $absence) {
            if ($types !== null && !in_array($absence['type'], $types, true)) {
                continue;
            }
            foreach (self::dateRange($absence['from'], $absence['to']) as $day) {
                if (substr($day, 0, 7) === $period) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }

    /** @param array<string,mixed> $p */
    private static function activeIn(array $p, string $period): bool
    {
        return substr($p['start'], 0, 7) <= $period && ($p['end'] === null || substr($p['end'], 0, 7) >= $period);
    }

    /**
     * @param \Closure(string):?int $gross
     * @param (\Closure(string):int)|null $daily
     * @param (\Closure(string):list<string>)|null $worked
     */
    private function hire(
        string $key,
        string $name,
        string $birthDate,
        string $sex,
        string $relation,
        int $weekly,
        bool $declaration,
        \Closure $gross,
        string $start = '2024-01-01',
        ?string $end = null,
        string $annual = 'not_requested',
        ?\Closure $daily = null,
        ?\Closure $worked = null,
        ?string $sameAs = null,
        string $socialDiscount = 'not_claimed',
    ): void {
        $sequence = ++$this->sequence;
        [$employmentType, $relationType] = match ($relation) {
            'hpp' => ['hpp', 'employment'],
            'dpp' => ['dpp', 'dpp'],
            'dpc' => ['dpc', 'dpc'],
        };
        $workload = intdiv($weekly * 10_000, 40);
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weekly,
            $workload,
            $declaration,
            '2026-01-01',
            socialDiscountStatus: $socialDiscount,
            healthTopUpResponsibility: 'employee',
            existingEmployeeId: $sameAs === null ? null : $this->people[$sameAs]['person']['employee_id'],
        );
        [$first, $last] = explode(' ', $name, 2);
        if ($sameAs === null) {
            $this->completeJmhzEmployment($person, null, [
                'first_name' => $first,
                'last_name' => $last,
                'birth_date' => $birthDate,
                'sex' => $sex,
                'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
            ]);
            $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        } else {
            $this->completeJmhzEmployment($person, withIdentity: false);
        }
        $ppv = sprintf('4%020d', $sequence);
        $this->assignJmhzIdentity($person, $sameAs === null ? self::syntheticOic($sequence) : null, $ppv);
        $this->startOn($person['employment_id'], $start);
        $daily ??= static fn (string $p): int => intdiv($weekly * 60, 5);
        $worked ??= static fn (string $p): array => self::workdays($p);
        foreach (self::MONTHS as $period) {
            $this->publishShiftMinutes($person['employment_id'], self::workdays($period), $daily($period));
        }
        if ($end !== null) {
            $this->endOn($person['employment_id'], $end);
        }
        if ($start < '2026-01-01' && $sameAs === null) {
            // Leden a únor nesou 10319: žádost o roční zúčtování 2025 eviduje účetní výslovně.
            $repository = $this->container->get(PayrollAnnualSettlementRepository::class);
            self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $repository);
            $values = $annual === 'requested' ? [
                'request_status' => 'requested',
                'requested_on' => '2026-02-05',
                'request_evidence_reference' => 'document:synthetic-annual-request-2025',
                'prior_employers' => 'none',
                'filing_obligation' => 'none',
                'annual_claims' => 'none',
            ] : ['request_status' => 'not_requested', 'requested_on' => null, 'request_evidence_reference' => null,
                'prior_employers' => 'unknown', 'filing_obligation' => 'unknown', 'annual_claims' => 'unknown'];
            $values += ['prior_documents_received_on' => null, 'filing_obligation_reason' => null, 'annual_claims_note' => null, 'note' => null];
            $repository->saveRequest($this->supplierId, $person['employee_id'], 2025, $values, null, $this->actors[0]);
        }
        $this->people[$key] = [
            'person' => $person,
            'relation' => $relation,
            'declaration' => $declaration,
            'social_discount' => $socialDiscount,
            'ppv' => $ppv,
            'start' => $start < '2026-01-01' ? '2026-01-01' : $start,
            'end' => $end,
            'same_as' => $sameAs,
            'gross' => $gross,
            'daily' => $daily,
            'worked' => $worked,
            'absences' => [],
            'credits' => [],
        ];
    }

    /** Změna úvazku od data: nová verze podmínek vztahu. */
    private function changeTermsFrom(string $key, string $from, int $weekly, int $workload): void
    {
        $employmentId = $this->people[$key]['person']['employment_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_to = ? WHERE supplier_id = ? AND employment_id = ? AND effective_to IS NULL',
        )->execute([(new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'), $this->supplierId, $employmentId]);
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation, health_insurance_participation,
                 tax_regime, tax_declaration_signed, is_primary, activity_code, jmhz_relationship_detail_code,
                 work_place, jmhz_workplace_municipality_code, jmhz_workplace_country_code,
                 jmhz_external_codebook_overlay_key, jmhz_external_codebook_manifest_sha256,
                 jmhz_apz_contribution_status, jmhz_functional_benefits_status, jmhz_temporary_assignment_status,
                 risky_work, change_reason)
             SELECT supplier_id, employment_id, office_id, ?, planned_start_on, actual_start_on,
                    ?, ?, social_insurance_participation, health_insurance_participation,
                    tax_regime, tax_declaration_signed, is_primary, activity_code, jmhz_relationship_detail_code,
                    work_place, jmhz_workplace_municipality_code, jmhz_workplace_country_code,
                    jmhz_external_codebook_overlay_key, jmhz_external_codebook_manifest_sha256,
                    jmhz_apz_contribution_status, jmhz_functional_benefits_status, jmhz_temporary_assignment_status,
                    risky_work, "Změna úvazku dodatkem"
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? AND effective_to IS NOT NULL
              ORDER BY effective_from DESC LIMIT 1',
        )->execute([$from, $weekly, $workload, $this->supplierId, $employmentId]);
    }

    /** @param array<string,mixed> $extra */
    private function absenceFrom(string $key, string $type, string $from, string $to, ?string $childbirth = null): void
    {
        [$extra, $decision] = match ($type) {
            'dpn' => [[], [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]],
            'ppm' => [['expected_childbirth_date' => '2026-08-20'], []],
            default => [[], []],
        };
        $this->people[$key]['absences'][] = ['type' => $type, 'from' => $from, 'to' => $to, 'extra' => $extra, 'decision' => $decision, 'childbirth' => $childbirth];
    }

    private function creditFrom(string $key, string $kind, string $from): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status, effective_from, evidence_reference)
             VALUES (?, ?, ?, "verified", ?, "document:synthetic-credit")',
        )->execute([$this->supplierId, $this->people[$key]['person']['employee_id'], $kind, $from]);
        $this->people[$key]['credits'][] = ['from' => $from, 'monthly' => self::ZTPP_CREDIT];
    }

    private function childFrom(string $key, string $from): void
    {
        $employeeId = $this->people[$key]['person']['employee_id'];
        $dependants = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $dependants);
        $birthNumber = self::syntheticBirthNumber('2026-06-20', 'male', 300);
        $created = $dependants->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => 'Syntetické Dítě',
                'given_name' => 'Syntetické',
                'family_name' => 'Dítě',
                'birth_date' => '2026-06-20',
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => '2026-06-20',
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $created->getStatusCode(), 'Vyživovaná osoba: ' . (string) $created->getBody());
        $dependantId = (int) $this->json($created)['dependants'][0]['id'];
        $claim = $dependants->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")->withParsedBody([
                'child_order' => 1,
                'claim_reason' => 'own_household',
                'evidence_status' => 'verified',
                'evidence_reference' => 'document:child-claim',
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
                'other_household_caregiver_status' => 'none',
                'ztp_p' => false,
                'effective_from' => $from,
                'effective_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $claim->getStatusCode(), 'Nárok na dítě: ' . (string) $claim->getBody());
        $this->people[$key]['credits'][] = ['from' => $from, 'monthly' => self::FIRST_CHILD_CREDIT];
    }

    /** @param array<string,mixed> $p */
    private function ensureCalendar(array $p, string $period): void
    {
        $employmentId = $p['person']['employment_id'];
        if (!isset($this->calendars[$employmentId])) {
            $this->ensureRegularCalendar($employmentId, ($p['daily'])($period));
            $this->calendars[$employmentId] = true;
            return;
        }
        $previous = (new \DateTimeImmutable("{$period}-01"))->modify('-1 month')->format('Y-m');
        $minutes = ($p['daily'])($period);
        if ($minutes === ($p['daily'])($previous)) {
            return;
        }
        $version = (int) $this->scalar(
            'SELECT row_version FROM payroll_work_calendars WHERE supplier_id = ? AND employment_id = ? ORDER BY valid_from DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );
        $calendar = $this->time->calendar(
            $this->request('PUT', "/api/payroll/time/calendars/{$employmentId}")->withParsedBody([
                'name' => 'Syntetický týden po změně úvazku',
                'timezone' => 'Europe/Prague',
                'schedule_type' => 'regular',
                'week_pattern' => ['1' => $minutes, '2' => $minutes, '3' => $minutes, '4' => $minutes, '5' => $minutes, '6' => 0, '7' => 0],
                'valid_from' => "{$period}-01",
                'valid_to' => null,
                'row_version' => $version,
                'month_row_version' => $this->timeMonthRowVersion($employmentId, $period),
                'days' => [],
            ]),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode(), (string) $calendar->getBody());
    }

    /** @param list<string> $dates */
    private function publishShiftMinutes(int $employmentId, array $dates, int $minutes): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", ?, NOW())',
        );
        foreach ($dates as $date) {
            $start = new \DateTimeImmutable("{$date} 06:00:00");
            $statement->execute([$this->supplierId, $employmentId, "g7-{$employmentId}-{$date}",
                $start->format('Y-m-d H:i:s'), $start->modify("+{$minutes} minutes")->format('Y-m-d H:i:s'), $this->actors[0]]);
        }
    }

    private function latestAverageId(int $employmentId): ?int
    {
        $id = $this->scalar(
            'SELECT id FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = "approved"
              ORDER BY applicable_year DESC, applicable_quarter DESC, id DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * Průměry čtvrtletí hromadně (Nepřítomnosti → Průměrné výdělky): návrh
     * z uzavřených běhů, u vztahu bez historie v MyÚčtu ruční zadání z podkladů
     * předchozího roku. Vrací položky, které nešly založit.
     *
     * @return list<string>
     */
    private function quarterAverages(int $quarter): array
    {
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        $page = $batch->page($this->supplierId, 2026, $quarter, 100);
        $ready = [];
        $failures = [];
        foreach ($page['items'] as $item) {
            if ($item['existing'] !== null) {
                continue;
            }
            if ($item['ready'] === true) {
                $ready[] = ['employment_id' => (int) $item['employment_id'], 'input_version' => (string) $item['input_version']];
                continue;
            }
            if ($quarter === 1) {
                $this->manualAverage((int) $item['employment_id']);
                continue;
            }
            $failures[] = "Q{$quarter}: průměr vztahu {$item['employment_code']} nejde navrhnout: " . CanonicalJson::encode($item['blockers']);
        }
        if ($ready !== []) {
            $batch->createBatch($this->supplierId, 2026, $quarter, $ready, $this->actors[0]);
        }

        return $failures;
    }

    /** Průměr pro 1. čtvrtletí z výplatních listin 4. čtvrtletí 2025 předchozího zpracování. */
    private function manualAverage(int $employmentId): void
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 1,
                'decisive_from' => '2025-10-01',
                'decisive_to' => '2025-12-31',
                'gross_earnings_minor' => 120_000_00,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 480 * 60,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    private function startOn(int $employmentId, string $start): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$start, $start, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$start, $start, $start, $this->supplierId, $employmentId]);
        // Produkční založení vztahu zapisuje událost životního cyklu; bez ní by
        // pozdější skončení schovalo vztah i z měsíců před ním.
        $pdo->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status, effective_on, created_by)
             VALUES (?, ?, "created", NULL, "active", ?, ?)',
        )->execute([$this->supplierId, $employmentId, $start, $this->actors[0]]);
    }

    /** Skončení vztahu výpovědí zaměstnance, zadané zpětně. */
    private function endOn(int $employmentId, string $end): void
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]);
        $employments->transition($this->supplierId, $employmentId, 'ended', $version, $end, null, $this->actors[0], null, null);
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $termination->save($this->supplierId, $employmentId, ['termination_method' => 'employee_notice', 'legal_ground' => 'none'], $this->actors[0]);
    }

    private function transitionEmployment(int $employmentId, string $target, string $effectiveOn): void
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]);
        $employments->transition($this->supplierId, $employmentId, $target, $version, $effectiveOn, null, $this->actors[0], null, null);
    }

    /** Karta osoby pro registraci: identita, trvalá adresa, pojišťovna a místo výkonu. */
    private function completeRegistrationCard(int $employeeId, int $employmentId): void
    {
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $identityId = (int) $this->scalar(
            'SELECT id FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $rowVersion = (int) $this->scalar(
            'SELECT row_version FROM payroll_person_identity_history WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $identityId],
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET birth_surname = "Zpětná", effective_from = "2026-01-01"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $identityId]);
        $identities->saveIdentityFacts($this->supplierId, $employeeId, $identityId, $rowVersion, [
            'title_prefix' => null,
            'title_suffix' => null,
            'birth_date' => '1994-04-15',
            'birth_place' => 'Testov',
            'birth_country_code' => 'CZ',
            'citizenship_country_code' => 'CZ',
            'sex' => 'female',
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Dlouhá 12", "Praha", "11000", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET insurer_status = "verified", effective_from = "2026-01-01", insurer_evidence_reference = "karta-pojistence"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET work_place = "Praha 1, Dlouhá 1", jmhz_workplace_municipality_code = "554782",
                    jmhz_workplace_country_code = "CZ", cz_isco_code = "2411"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
    }

    /** @return array<string,mixed> */
    private static function a1Profile(string $startOn): array
    {
        return [
            'effective_on' => $startOn,
            'row_version' => 0,
            'permanent_address' => [
                'street' => 'Dlouhá', 'house_number' => '12', 'orientation_number' => null, 'city' => 'Praha',
                'postal_code' => '11000', 'country_code' => 'CZ', 'ruian_point' => null,
            ],
            'tax_residency' => ['country_code' => 'CZ', 'identifier_type' => null, 'identifier' => null, 'residence_address' => null],
            'employment' => [
                'activity_code' => '1', 'relationship_detail_code' => '1', 'actual_start_on' => $startOn,
                'contract_start_on' => $startOn, 'small_scale' => false, 'employment_status_code' => '1111',
                'work_mode_code' => '1', 'continuous_operation' => false, 'prevailing_workplace_code' => null,
                'expected_workplaces' => null, 'contract_workplace' => 'Praha 1, Dlouhá 1', 'workplace_city' => 'Praha',
                'workplace_municipality_code' => '554782', 'profession_code' => '24111', 'required_education_code' => null,
                'position_name' => 'Účetní', 'leadership' => false,
            ],
            'pension' => ['type_code' => null, 'received_from' => null, 'early_retirement' => false, 'reduced_retirement_age' => false],
            'health_insurance_code' => '111',
            'facts' => ['highest_education_code' => 'T', 'disability_card' => false, 'health_restrictions' => []],
            'foreign_legislation' => ['applies' => false, 'country_code' => null],
            'proof_identity' => null,
            'foreign_worker' => null,
            'czech_residence_address' => null,
            'contact_address' => null,
            'attachments' => [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function saveA1Profile(PayrollRegistrationAction $registration, int $employmentId, array $payload): void
    {
        $current = $this->json($registration->a1Profile(
            $this->request('GET', "/api/payroll/submissions/registration/{$employmentId}/a1-profile"),
            new Response(),
            ['employmentId' => (string) $employmentId],
        ));
        $payload['row_version'] = (int) $current['draft']['row_version'];
        $response = $registration->saveA1Profile(
            $this->request('PUT', "/api/payroll/submissions/registration/{$employmentId}/a1-profile")->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertContains($response->getStatusCode(), [200, 201], (string) $response->getBody());
        $profile = $this->json($response)['profile'];
        self::assertSame('verified', $profile['status'], 'Zaseknutí: profil A1. ' . CanonicalJson::encode($profile['problems'] ?? []));
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function prepareRegistration(PayrollRegistrationAction $registration, int $employmentId, array $body): array
    {
        $response = $registration->prepare(
            $this->request('POST', "/api/payroll/submissions/registration/{$employmentId}")
                ->withParsedBody(['environment' => 'test'] + $body),
            new Response(),
            ['employmentId' => (string) $employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), 'Zaseknutí: příprava podání. ' . (string) $response->getBody());

        return $this->json($response);
    }

    /** Syntetický protokol ČSSZ: přijetí, u přihlášky s OIČ a ID PPV. */
    private function acceptRegistration(int $submissionId, int $rowVersion, int $partId, bool $withIdentifiers): void
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $correlation = "synthetic-g7-registration:{$submissionId}";
        $submitted = $submissions->transition($this->supplierId, $submissionId, $rowVersion, 'submitted', $correlation);
        $outcomes = $withIdentifiers ? [new PayrollVerifiedReceiptFormOutcome(
            '11111111-2222-4333-8444-555555555577', $partId, 1, 'Accepted', 'accepted', '1000000023', '200000000000000000077', [],
        )] : [];
        $verifier = new class ($partId, $outcomes) implements PayrollReceiptVerifierInterface {
            /** @param list<PayrollVerifiedReceiptFormOutcome> $outcomes */
            public function __construct(private readonly int $partId, private readonly array $outcomes) {}

            public function verify(string $bytes, string $channel, string $environment, ?string $expectedCorrelationReference): PayrollVerifiedReceipt
            {
                return new PayrollVerifiedReceipt('accepted', $expectedCorrelationReference, [$this->partId => 'accepted'], $this->outcomes);
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId, $submissionId, (int) $submitted['row_version'], $partId, '<synthetic-registration-receipt/>',
            "synthetic-g7-receipt:{$submissionId}", $correlation, 'CSSZ_REGZEC', 'accepted', 'vrep_apep',
            "synthetic-g7-key:{$submissionId}", $this->actors[0], $verifier,
        );
        self::assertTrue($receipt['trusted']);
    }

    private function artifactXml(int $submissionId): string
    {
        $id = (int) $this->scalar(
            'SELECT id FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ? AND artifact_kind = "outbound_xml" ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $submissionId],
        );
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return $submissions->artifactBytes($this->supplierId, $id);
    }
}
