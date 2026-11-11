<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Action\Payroll\PayrollHealthNotificationAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\PayrollHealthInsurerWriter;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Přehled o platbě pojistného zaměstnavatele (PPZ) celou cestou účetní:
 * zaměstnanci, mzdové běhy, přehled podle pojišťoven, příprava (zmrazení
 * a XSD `api/xsd/zp/2025-v8`) a opravný přehled po opravě zmrazeného měsíce.
 * Nic se neodesílá.
 *
 * Ruční přepočty (zákon č. 592/1992 Sb.):
 * - sazba 13,5 % z vyměřovacího základu, zaměstnanec třetinu (4,5 %),
 *   zaměstnavatel zbytek (§ 2 odst. 1, § 9 odst. 1),
 * - minimální vyměřovací základ 2026 = minimální mzda 22 400 Kč (§ 3 odst. 7
 *   a 8): pojistné z rozdílu hradí zaměstnanec, ledaže jde o překážku na
 *   straně zaměstnavatele; pak ho hradí zaměstnavatel.
 *
 * Syntetická data, transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniHealthOverviewFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('E2EZ', 'Syntetická účtárna ZP', '1100007654');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_E2E_ZP', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Dvě pojišťovny a změna pojišťovny od 1. 7. 2026 (§ 1 odst. 2 zákona
     * č. 48/1997 Sb. dovoluje změnu jen k prvnímu dni kalendářního období;
     * 1. 7. tomu vyhovuje, aplikace ji vede od 1. dne měsíce).
     *
     * A: HPP 40 000 Kč, VZP (111) po celou dobu.
     * B: HPP 30 000 Kč, červen VZP (111), od července ČPZP (205).
     *
     * Červen, přehled 111: A 40 000 × 13,5 % = 5 400 (zaměstnanec 1 800,
     * zaměstnavatel 3 600), B 30 000 × 13,5 % = 4 050 (1 350 + 2 700); úhrn
     * 9 450 Kč, základ 70 000 Kč. Přehled 205 za červen nevznikne.
     * Červenec: přehled 111 jen A (5 400), přehled 205 jen B (4 050). Měsíc patří
     * přehledu té pojišťovny, u které je zaměstnanec pojištěn k 1. dni měsíce.
     */
    public function testInsurerChangeFromJulyMovesThePersonToTheNewInsurersOverview(): void
    {
        $this->healthAccount('205', 'Syntetická ČPZP');
        $a = $this->employ('Syntetická Vzpová', '1984-02-11', 'female', 40, 10_000, '2026-06-01');
        $b = $this->employ('Syntetický Přecházející', '1981-07-23', 'male', 40, 10_000, '2026-06-01');
        $writer = $this->container->get(PayrollHealthInsurerWriter::class);
        self::assertInstanceOf(PayrollHealthInsurerWriter::class, $writer);
        $writer->assign($this->supplierId, $b['employee_id'], '205', '2026-07-01', $this->actors[0], null, null);

        $june = $this->month('2026-06', [[$a, 40_000], [$b, 30_000]]);
        $july = $this->month('2026-07', [[$a, 40_000], [$b, 30_000]]);

        $juneOverview = $this->overview($june);
        self::assertSame(['111'], array_map('strval', array_keys($juneOverview)), CanonicalJson::encode($juneOverview));
        self::assertSame(945_000, $juneOverview['111']['totals']['total_contribution_minor_units']);
        self::assertSame(7_000_000, $juneOverview['111']['totals']['assessment_base_minor_units']);
        self::assertSame([180_000, 360_000], $this->shares($juneOverview['111'], $a['employee_id']));
        self::assertSame([135_000, 270_000], $this->shares($juneOverview['111'], $b['employee_id']));

        $julyOverview = $this->overview($july);
        self::assertSame(['111', '205'], array_map('strval', array_keys($julyOverview)), CanonicalJson::encode($julyOverview));
        self::assertSame(540_000, $julyOverview['111']['totals']['total_contribution_minor_units']);
        self::assertSame([$a['employee_id']], $this->peopleIn($julyOverview['111']));
        self::assertSame(405_000, $julyOverview['205']['totals']['total_contribution_minor_units']);
        self::assertSame([$b['employee_id']], $this->peopleIn($julyOverview['205']));

        // ČPZP přijímá XML; příprava ho zmrazí a ověří proti XSD.
        $prepared = $this->prepareOverview($july, '205');
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertTrue($prepared['body']['schema_validated'], CanonicalJson::encode($prepared['body']));
        self::assertSame('ready', $prepared['body']['status']);
        self::assertSame('2026-07', $prepared['body']['period']);
        self::assertSame('2026-08-20', $prepared['body']['deadline']['due_on']);
        $xml = $this->artifact((int) $prepared['body']['artifact_id']);
        self::assertStringContainsString('<kodZdravotniPojistovny>205</kodZdravotniPojistovny>', $xml);
        self::assertStringContainsString('<soucetPojistneho>4050</soucetPojistneho>', $xml);
        self::assertStringContainsString('<typPrehledu>radny</typPrehledu>', $xml);

        // Hromadné oznámení zaměstnavatele: změna pojišťovny je povinnost vůči
        // odcházející (111) i přicházející (205) pojišťovně za červenec.
        $service = $this->container->get(HealthInsuranceSubmissionService::class);
        self::assertInstanceOf(HealthInsuranceSubmissionService::class, $service);
        $duties = $service->dutiesForPeriod($this->supplierId, 'test', '2026-07', ['kind' => 'insurer_change']);
        self::assertEqualsCanonicalizing(
            ['111:outgoing', '205:incoming'],
            array_map(static fn (array $item): string => $item['insurer_code'] . ':' . $item['insurer_direction'], $duties['items']),
            CanonicalJson::encode($duties),
        );
        $notification = $this->container->get(PayrollHealthNotificationAction::class);
        self::assertInstanceOf(PayrollHealthNotificationAction::class, $notification);
        $hoz = $notification->prepareBulkNotification(
            $this->request('POST', '/api/payroll/submissions/health-notifications/bulk/2026-07/205/prepare')
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['period' => '2026-07', 'insurerCode' => '205'],
        );
        self::assertSame(200, $hoz->getStatusCode(), (string) $hoz->getBody());
        $hozBody = $this->json($hoz);
        self::assertTrue($hozBody['schema_validated'] ?? null, CanonicalJson::encode($hozBody));
    }

    /**
     * Minimum zdravotního pojištění: dva zaměstnanci na poloviční úvazek se
     * mzdou 15 000 Kč, březen 2026.
     *
     * Ručně: pojistné ze skutečného příjmu 15 000 × 13,5 % = 2 025 Kč
     * (zaměstnanec 675, zaměstnavatel 1 350). Rozdíl do minima
     * (22 400 − 15 000) × 13,5 % = 999 Kč. Celkem 3 024 Kč = 13,5 % z 22 400.
     * - C (rozdíl hradí zaměstnanec): zaměstnanec 675 + 999 = 1 674,
     *   zaměstnavatel 1 350.
     * - D (překážka na straně zaměstnavatele): zaměstnanec 675,
     *   zaměstnavatel 1 350 + 999 = 2 349.
     * Přehled 111: základ 2 × 22 400 = 44 800 Kč, pojistné 6 048 Kč.
     */
    public function testMinimumAssessmentBaseTopUpIsChargedToTheRightPartyAndReported(): void
    {
        $c = $this->employ('Syntetická Poloviční', '1990-10-10', 'female', 20, 5_000, '2026-03-01', 'employee');
        $d = $this->employ('Syntetický Odstávkový', '1991-11-11', 'male', 20, 5_000, '2026-03-01', 'employer_obstacle_verified');
        $march = $this->month('2026-03', [[$c, 15_000], [$d, 15_000]]);

        $overview = $this->overview($march);
        self::assertSame(['111'], array_map('strval', array_keys($overview)));
        self::assertSame([167_400, 135_000], $this->shares($overview['111'], $c['employee_id']));
        self::assertSame([67_500, 234_900], $this->shares($overview['111'], $d['employee_id']));
        foreach ($overview['111']['people'] as $person) {
            self::assertSame(2_240_000, $person['assessment_base_minor_units'], CanonicalJson::encode($person));
            self::assertSame(302_400, $person['total_contribution_minor_units']);
        }
        self::assertSame(604_800, $overview['111']['totals']['total_contribution_minor_units']);
        self::assertSame(4_480_000, $overview['111']['totals']['assessment_base_minor_units']);

        $prepared = $this->prepareOverview($march, '111');
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertTrue($prepared['body']['schema_validated']);
        $xml = $this->artifact((int) $prepared['body']['artifact_id']);
        self::assertStringContainsString('<soucetPojistneho>6048</soucetPojistneho>', $xml);
    }

    /**
     * Opravný přehled: březnový přehled 111 se zmrazí a zapíše jako přijatý
     * (simulace doručení, nic se neodesílá). Pak účetní doplní zapomenutou
     * odměnu 2 000 Kč a opraví březnový běh. Nový přehled je opravný
     * (`typPrehledu` opravny), odkazuje na přijatý řádný a nese pojistné
     * z 42 000 Kč: 42 000 × 13,5 % = 5 670 Kč. Lhůta opravného přehledu je
     * 8 dnů ode dne zjištění (žádosti o opravu), § 25 odst. 4 zákona
     * č. 592/1992 Sb., posunutá na pracovní den.
     */
    public function testCorrectionAfterFiledOverviewProducesCorrectiveOverview(): void
    {
        $a = $this->employ('Syntetická Opravná', '1986-06-06', 'female', 40, 10_000, '2026-03-01');
        $march = $this->month('2026-03', [[$a, 40_000]]);

        $regular = $this->prepareOverview($march, '111');
        self::assertSame(200, $regular['status'], CanonicalJson::encode($regular['body']));
        self::assertTrue($regular['body']['created']);
        $this->markAccepted((int) $regular['body']['submission_id']);

        $this->createApprovedInput($a, $this->baseComponentId, 2_000_00, 'e2e-zp-oprava-odmena', '2026-03-01');
        $run = $this->db->pdo()->prepare('SELECT * FROM payroll_runs WHERE supplier_id = ? AND id = ?');
        $run->execute([$this->supplierId, $march['run_id']]);
        $corrected = $this->correctPayrollRun($run->fetch(\PDO::FETCH_ASSOC), 'e2e-zp-oprava', 'Doplněna březnová odměna.');
        self::assertSame('approved', $corrected->run['status']);
        $correctionRevisionId = (int) $corrected->revision['id'];

        $overview = $this->overview(['revision_id' => $correctionRevisionId]);
        self::assertSame(567_000, $overview['111']['totals']['total_contribution_minor_units']);

        $correction = $this->prepareOverview(['revision_id' => $correctionRevisionId], '111');
        self::assertSame(200, $correction['status'], CanonicalJson::encode($correction['body']));
        $repository = $this->container->get(PayrollSubmissionRepository::class);
        self::assertInstanceOf(PayrollSubmissionRepository::class, $repository);
        $submission = $repository->findSubmission($this->supplierId, (int) $correction['body']['submission_id']);
        self::assertIsArray($submission);
        self::assertSame('correction', $submission['submission_kind']);
        self::assertSame((int) $regular['body']['submission_id'], (int) $submission['corrects_submission_id']);
        $xml = $this->artifact((int) $correction['body']['artifact_id']);
        self::assertStringContainsString('<typPrehledu>opravny</typPrehledu>', $xml);
        self::assertStringContainsString('<soucetPojistneho>5670</soucetPojistneho>', $xml);

        $requestedOn = (string) $this->scalar(
            'SELECT DATE(created_at) FROM payroll_run_events WHERE supplier_id = ? AND run_id = ? AND event_type = "request_correction" ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $march['run_id']],
        );
        $expectedDue = CzechWorkingDays::shiftToWorkingDay((new \DateTimeImmutable($requestedOn))->modify('+8 days'))->format('Y-m-d');
        self::assertSame($expectedDue, $correction['body']['deadline']['due_on']);
    }

    /**
     * Chybí platební účet pojišťovny (číslo plátce): příprava přehledu se
     * zastaví srozumitelnou chybou 422, ne 500, a hláška jmenuje, kde se účet
     * zakládá.
     */
    public function testMissingInsurerAccountStopsPreparationWithActionableReason(): void
    {
        $b = $this->employ('Syntetický Bezúčtový', '1982-03-03', 'male', 40, 10_000, '2026-03-01');
        $writer = $this->container->get(PayrollHealthInsurerWriter::class);
        self::assertInstanceOf(PayrollHealthInsurerWriter::class, $writer);
        $writer->assign($this->supplierId, $b['employee_id'], '207', '2026-03-01', $this->actors[0], null, null);
        $march = $this->month('2026-03', [[$b, 30_000]]);

        $prepared = $this->prepareOverview($march, '207');
        self::assertSame(422, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertSame('zp_payer_account_missing', $prepared['body']['error']['code'] ?? null, CanonicalJson::encode($prepared['body']));
        self::assertStringContainsString('Platební účty', (string) ($prepared['body']['error']['message'] ?? ''));
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function employ(
        string $name,
        string $birthDate,
        string $sex,
        int $weekly,
        int $workload,
        string $start,
        string $topUp = 'employee',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            $weekly,
            $workload,
            true,
            $start,
            healthTopUpResponsibility: $topUp,
        );
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('6%020d', $sequence));
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$start, $start, $this->supplierId, $person['employment_id']]);
        $pdo->prepare('UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$start, $start, $start, $this->supplierId, $person['employment_id']]);
        $daily = intdiv($weekly * 60, 5);
        $this->ensureRegularCalendar($person['employment_id'], $daily);
        $person['daily'] = $daily;

        return $person;
    }

    /**
     * @param list<array{0:array<string,mixed>,1:int}> $pay
     * @return array{revision_id:int,run_id:int}
     */
    private function month(string $period, array $pay): array
    {
        $periodStart = "{$period}-01";
        foreach ($pay as [$person, $gross]) {
            $this->publishShifts($person['employment_id'], self::workdays($period));
            $this->db->pdo()->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start, claim_register_evidence_complete,
                     dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], $periodStart, $this->actors[0]]);
            $time = $this->approveTimeMonth($person['employment_id'], $period, self::workdays($period), dailyMinutes: $person['daily']);
            self::assertSame(200, $time->getStatusCode(), "Zaseknutí {$period}: docházka " . (string) $time->getBody());
            $this->createApprovedInput($person, $this->baseComponentId, $gross * 100, "e2e-zp-{$person['employment_id']}-{$period}", $periodStart);
        }
        $payday = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-10');
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "e2e-zp-{$period}");
        self::assertSame([], $run['blockers'], "Zaseknutí {$period}: výpočet " . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], "Zaseknutí {$period}: varování " . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);

        return ['revision_id' => (int) $run['approved']->revision['id'], 'run_id' => (int) $run['approved']->run['id']];
    }

    /**
     * @param array{revision_id:int} $month
     * @return array<string,array<string,mixed>> přehledy podle kódu pojišťovny
     */
    private function overview(array $month): array
    {
        $action = $this->container->get(PayrollHealthInsuranceOverviewAction::class);
        self::assertInstanceOf(PayrollHealthInsuranceOverviewAction::class, $action);
        $response = $action->index(
            $this->request('GET', "/api/payroll/submissions/health-overviews/{$month['revision_id']}"),
            new Response(),
            ['revisionId' => (string) $month['revision_id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame([], $body['failures'], CanonicalJson::encode($body['failures']));
        $byCode = [];
        foreach ($body['items'] as $item) {
            $byCode[(string) $item['insurer']['code']] = $item;
        }
        ksort($byCode, SORT_STRING);

        return $byCode;
    }

    /**
     * @param array{revision_id:int} $month
     * @return array{status:int,body:array<string,mixed>}
     */
    private function prepareOverview(array $month, string $insurerCode): array
    {
        $action = $this->container->get(PayrollHealthNotificationAction::class);
        self::assertInstanceOf(PayrollHealthNotificationAction::class, $action);
        $response = $action->preparePaymentOverview(
            $this->request('POST', "/api/payroll/submissions/health-notifications/payment-overview/{$month['revision_id']}/{$insurerCode}/prepare")
                ->withParsedBody(['environment' => 'test']),
            new Response(),
            ['revisionId' => (string) $month['revision_id'], 'insurerCode' => $insurerCode],
        );
        self::assertNotSame(500, $response->getStatusCode(), (string) $response->getBody());

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /** @param array<string,mixed> $item @return array{0:int,1:int} [zaměstnanec, zaměstnavatel] */
    private function shares(array $item, int $employeeId): array
    {
        foreach ($item['people'] as $person) {
            if ($person['employee_reference'] === "employee:{$employeeId}") {
                return [$person['employee_contribution_minor_units'], $person['employer_contribution_minor_units']];
            }
        }
        self::fail("Osoba employee:{$employeeId} v přehledu chybí: " . CanonicalJson::encode($item['people']));
    }

    /** @param array<string,mixed> $item @return list<int> */
    private function peopleIn(array $item): array
    {
        return array_map(static fn (array $p): int => (int) substr((string) $p['employee_reference'], 9), $item['people']);
    }

    private function artifact(int $artifactId): string
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return $submissions->artifactBytes($this->supplierId, $artifactId);
    }

    /** Simulace doručeného a přijatého přehledu (nic se neodesílá). */
    private function markAccepted(int $submissionId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "accepted", submitted_at = UTC_TIMESTAMP(), decided_at = UTC_TIMESTAMP(), row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $submissionId]);
    }

    private function healthAccount(string $code, string $name): void
    {
        $accounts = $this->container->get(PayrollInstitutionAccountRepository::class);
        self::assertInstanceOf(PayrollInstitutionAccountRepository::class, $accounts);
        $accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => $code,
            'institution_name' => $name,
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '0000001900',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:e2e-health-account-' . $code,
            'verified_on' => '2026-02-15',
        ], $this->actors[0]);
    }
}
