<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Repository\Payroll\PayrollPensionRequestRepository;
use MyInvoice\Repository\Payroll\PayrollTaxableIncomeConfirmationRequestRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentitySnapshotRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Deadline\PayrollTaxStatementDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDeltaPlanner;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetector;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableProfileBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollEmployeeRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Přehled mzdových termínů — modul lhůty počítal, ale nikomu je neřekl.
 *
 * Test drží tři věci, na kterých hlídač stojí: že zmeškaný termín je vidět,
 * že se doložená povinnost do seznamu nepřipomíná, a že termín za horizontem
 * seznam nezaplevelí.
 */
#[Group('integration')]
final class PayrollDeadlineOverviewTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDeadlineOverviewService $service;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_employments',
            'payroll_employees',
            'payroll_employment_checklist_items',
            'payroll_obligations',
            'payroll_submission_deadlines',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('Europe/Prague')),
        );
        $changeProposals = new PayrollRegistrationChangeProposalRepository($this->db);
        $this->service = new PayrollDeadlineOverviewService(
            new PayrollDeadlineOverviewRepository($this->db),
            new PayrollDeadlineAssessmentService($clock),
            $changeProposals,
            new PayrollRegistrationChangeDetectionService(
                $changeProposals,
                new PayrollRegistrationIdentitySnapshotRepository($this->db),
                $container->get(PayrollRegistrationIdentitySnapshotService::class),
                $container->get(PayrollRegistrationIdentityService::class),
                $container->get(PayrollRegistrationEventService::class),
                new PayrollRegistrationChangeDetector(),
                new PayrollRegistrationChangeDeltaPlanner(),
                new PayrollRegistrationReportableProfileBuilder(),
                new PayrollEmployeeRegistrationDeadlinePolicy(),
                new HealthNotificationDeadlinePolicy(),
                $clock,
            ),
            new PayrollTaxStatementDeadlinePolicy(),
            new PayrollSicknessCaseRepository($this->db),
            new SicknessDeadlinePolicy($container->get(PayrollRulesetProvider::class)),
            $clock,
        );

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba termíny", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, "TRM-1", "employment", "active", 1, "2026-08-03")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Endpoint musí jít sestavit kontejnerem, ne jen ručně v testu. */
    public function testContainerBuildsTheAction(): void
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(
            \MyInvoice\Action\Payroll\PayrollDeadlineOverviewAction::class,
        );

        self::assertInstanceOf(
            \MyInvoice\Action\Payroll\PayrollDeadlineOverviewAction::class,
            $action,
        );
    }

    /**
     * HZUPN hlásí NÁSTUP po skončení neschopnosti, takže lhůta běží od dne
     * nástupu, a vzniká jen u nemocenského. Dřív hlídač počítal HZUPN od
     * posledního dne neschopnosti a vypisoval ho i u ošetřovného.
     */
    public function testSicknessCaseDeadlinesFollowReturnToWorkAndOnlySicknessHasHzupn(): void
    {
        // Neschopnost 1. až 18. 8. přesáhla 14 dnů — do 14 dnů by dávka
        // nevznikla a hlídač by k ní lhůty neukázal (§ 26 odst. 1 zák. č. 187/2006 Sb.).
        $this->sicknessCase('NEM', '2026-08-01', '2026-08-18', '2026-08-19');
        $this->sicknessCase('OSE', '2026-08-03', '2026-08-05', null);

        $overview = $this->service->overview($this->supplierId, 'production');
        $sickness = array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['source'] === 'sickness_case',
        ));
        $byKey = [];
        foreach ($sickness as $item) {
            $byKey[$item['benefit_kind'] . ':' . $item['title']] = $item;
        }
        ksort($byKey);

        self::assertSame(['NEM:HZUPN', 'NEM:NEMPRI', 'OSE:NEMPRI'], array_keys($byKey));
        // Nástup ve středu 19. 8. — lhůta HZUPN tentýž den, ne 18. 8.
        self::assertSame('2026-08-19', $byKey['NEM:HZUPN']['due_on']);
        self::assertSame('/payroll/submissions/sickness', $byKey['NEM:HZUPN']['path']);
    }

    /**
     * PRE-01: NEMPRI a HZUPN jsou dvě podání (§ 97 odst. 1–3). Zapsané přijetí
     * NEMPRI dřív případ uzavřelo celý a lhůta HZUPN z hlídače zmizela.
     */
    public function testPendingHzupnStaysWatchedAfterNempriAccepted(): void
    {
        $this->sicknessCase('NEM', '2026-08-01', '2026-08-18', '2026-08-19');
        $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET nempri_status = "accepted", nempri_accepted_on = "2026-08-17"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $overview = $this->service->overview($this->supplierId, 'production');
        $titles = array_map(
            static fn (array $item): string => $item['title'] . ':' . $item['document_status'],
            array_values(array_filter(
                $overview['items'],
                static fn (array $item): bool => $item['source'] === 'sickness_case',
            )),
        );

        self::assertSame(['HZUPN:pending'], $titles);
    }

    /**
     * HZUPN20-APPLIC-2: skončilo-li zaměstnání v průběhu neschopnosti, ČSSZ
     * HZUPN nepožaduje a hlídač ho nehlídá; NEMPRI zůstává.
     */
    public function testHzupnIsNotWatchedWhenEmploymentEndedDuringIncapacity(): void
    {
        $this->sicknessCase('NEM', '2026-08-01', '2026-08-18', null);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-08-10" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $overview = $this->service->overview($this->supplierId, 'production');
        $titles = array_map(
            static fn (array $item): string => $item['title'],
            array_values(array_filter(
                $overview['items'],
                static fn (array $item): bool => $item['source'] === 'sickness_case',
            )),
        );

        self::assertSame(['NEMPRI'], $titles);
    }

    /**
     * NEMPRI25-*.potv.prevedenaNaJinouPraci-2: druhé oznámení ke dni převedení
     * se podává současně s prvním, takže ho hlídač drží se stejnou lhůtou
     * a zvlášť; přijaté první oznámení ho neschová.
     */
    public function testTransferNoticeIsWatchedWithTheNempriDeadline(): void
    {
        $this->sicknessCase('OSE', '2026-08-03', '2026-08-05', null);
        $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET transferred_other_work = 1, transferred_on = "2026-06-15",
                    transfer_reason = "breastfeeding", nempri_transfer_status = "pending"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $items = static fn (array $overview): array => array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['source'] === 'sickness_case',
        ));
        $watched = $items($this->service->overview($this->supplierId, 'production'));
        self::assertSame(['nempri', 'nempri_transfer'], array_column($watched, 'document_kind'));
        self::assertSame($watched[0]['due_on'], $watched[1]['due_on']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET nempri_status = "accepted", nempri_accepted_on = "2026-08-17"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $watched = $items($this->service->overview($this->supplierId, 'production'));
        self::assertSame(['nempri_transfer'], array_column($watched, 'document_kind'));
        self::assertSame('NEMPRI ke dni převedení', $watched[0]['title']);
    }

    /** Odmítnuté podání hlídač ukazuje dál a říká, že je odmítnuté. */
    public function testRejectedNempriStaysWatchedWithItsStatus(): void
    {
        $this->sicknessCase('OSE', '2026-08-03', '2026-08-05', null);
        $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET nempri_status = "rejected", nempri_rejection_reason = "Syntetický důvod"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $overview = $this->service->overview($this->supplierId, 'production');
        $items = array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['source'] === 'sickness_case',
        ));

        self::assertCount(1, $items);
        self::assertSame('rejected', $items[0]['document_status']);
        self::assertSame('rejected', $items[0]['status']);
    }

    private function sicknessCase(string $kind, string $from, string $to, ?string $returnedOn): void
    {
        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_sickness_cases
                (supplier_id, environment, employee_id, employment_id, benefit_kind,
                 ossz_code, incapacity_from, incapacity_to, returned_to_work,
                 returned_on, created_by)
             VALUES (?, "production", ?, ?, ?, 115, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $kind,
            $from,
            $to,
            $returnedOn === null ? null : 1,
            $returnedOn,
            $userId,
        ]);
    }

    public function testOverdueChecklistItemIsReported(): void
    {
        $this->checklistItem('social_jmhz_registration', '2026-08-03');

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame('2026-08-20', $overview['as_of']);
        $items = $this->itemsOfSource($overview, 'checklist');
        self::assertCount(1, $items);
        self::assertSame('overdue', $items[0]['phase']);
        self::assertSame(-17, $items[0]['days_to_due']);
        self::assertTrue($items[0]['is_overdue']);
        self::assertSame(1, $overview['summary']['overdue']);
    }

    public function testChecklistKeepsEmploymentEndDateWithAndWithoutDeadline(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2025-01-01", end_date = "2025-12-31", status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $this->checklistItem('eldp_submission', '2026-08-03');
        $this->checklistItem('termination_document', null);

        $overview = $this->service->overview($this->supplierId, 'production');
        $dated = $this->itemsOfSource($overview, 'checklist');
        $undated = $this->service->groupItems(
            $this->supplierId,
            'production',
            90,
            PayrollDeadlineOverviewService::UNDATED_PHASE,
            'checklist',
            'termination_document',
        );

        self::assertCount(1, $dated);
        self::assertSame(1, $undated['total']);
        foreach ([...$dated, ...$undated['items']] as $item) {
            self::assertSame('2025-12-31', $item['employment_end_date']);
            self::assertSame($this->employmentId, $item['employment_id']);
            self::assertSame($this->employeeId, $item['employee_id']);
        }
    }

    /**
     * Katalog termínů dosud jen PŘIPOMÍNAL a neměl vazbu na službu, která
     * povinnost splní: změnu údaje nikdo nesledoval, takže osmidenní lhůta
     * (§ 19 odst. 5 zákona č. 323/2025 Sb.) neměla kde vzniknout. Návrh
     * z detekce musí být v přehledu vidět i s proklikem na jeho splnění.
     */
    public function testDetectedRegistrationChangeIsReportedAsADeadline(): void
    {
        if (!$this->db->hasTable('payroll_registration_change_proposals')) {
            $this->markTestSkipped('Migrace detekce registračních změn neproběhla.');
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_change_proposals
                (supplier_id, employee_id, employment_id, environment,
                 duty_kind, action_code, baseline_fingerprint,
                 current_fingerprint, detected_on, due_on,
                 deadline_ruleset_id, deadline_source, findings_json)
             VALUES (?, ?, ?, "production", "regzec_change", 3, ?, ?,
                     "2026-08-10", "2026-08-18",
                     "cz-regzec-follow-up-2026-04.v1",
                     "§ 19 odst. 5 zákona č. 323/2025 Sb.", "{}")',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            str_repeat('a', 64),
            str_repeat('f', 64),
        ]);

        $overview = $this->service->overview($this->supplierId, 'production');
        $items = $this->itemsOfSource($overview, 'registration_change');

        self::assertCount(1, $items);
        self::assertSame('overdue', $items[0]['phase']);
        self::assertSame('2026-08-18', $items[0]['due_on']);
        self::assertSame('regzec_change', $items[0]['title']);
        self::assertArrayHasKey('proposal_id', $items[0]);
        self::assertSame(
            '/payroll/people/' . $this->employeeId,
            $items[0]['path'],
        );
    }

    public function testItemWithoutDerivedDeadlineIsNotReported(): void
    {
        $this->checklistItem('taxable_income_confirmation', null);

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    /**
     * Podání zakládá povinnost pod referencí
     * `payroll_employment_registration:{vztah}` (viz
     * `PayrollRegistrationSubmissionService::sourceEventReference`). Přehled
     * dřív hledal `payroll_employment:{vztah}` a odeslanou registraci
     * připomínal dál.
     */
    public function testSubmittedRegistrationSilencesTheReminder(): void
    {
        $this->checklistItem('social_jmhz_registration', '2026-08-03');
        $this->obligation(
            'PREZEC26',
            'payroll_employment_registration',
            'payroll_employment_registration:' . $this->employmentId,
            '2026-08-03',
            'submitted',
        );

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    /**
     * Připravená, ale neodeslaná odhláška u pojišťovny povinnost nesplnila —
     * lhůta § 10 zákona č. 48/1997 Sb. dál běží a přehled ji musí hlásit.
     */
    public function testPreparedHealthDeregistrationStillReminds(): void
    {
        $this->checklistItem('health_insurance_deregistration', '2026-08-10');
        $this->obligation(
            'ZPOZNAM',
            'payroll_health_notification',
            'payroll_health_notification:' . $this->employmentId . ':employment_end:2026-08-02',
            '2026-08-02',
            'prepared',
        );

        $items = $this->itemsOfSource(
            $this->service->overview($this->supplierId, 'production'),
            'checklist',
        );

        self::assertCount(1, $items);
        self::assertSame('overdue', $items[0]['phase']);
    }

    public function testDeadlineBeyondTheHorizonIsNotReported(): void
    {
        $this->checklistItem('social_jmhz_registration', '2027-01-15');

        $overview = $this->service->overview($this->supplierId, 'production', 30);

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    /**
     * § 38j odst. 3 ZDP: potvrzení na žádost do 10 dnů i u trvajícího
     * vztahu. Dřív šel den žádosti zapsat jen k výstupnímu checklistu, takže
     * žádost běžícího zaměstnance termín neměla nikde.
     */
    public function testTaxableIncomeRequestOfOngoingEmploymentIsADeadline(): void
    {
        $requests = new PayrollTaxableIncomeConfirmationRequestRepository($this->db);
        $actorId = $this->actorId();
        $list = $requests->create($this->supplierId, $this->employeeId, [
            'requested_on' => '2026-08-12',
            'income_year' => 2025,
            'employment_id' => $this->employmentId,
        ], $actorId);
        self::assertCount(1, $list);
        self::assertSame('2026-08-22', $list[0]['due_on']);
        self::assertSame('open', $list[0]['status']);
        self::assertStringContainsString('§ 38j odst. 3', $list[0]['deadline_source']);

        $items = $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'taxable_income_request');
        self::assertCount(1, $items);
        self::assertSame('2026-08-22', $items[0]['due_on']);
        self::assertSame('due_soon', $items[0]['phase']);
        self::assertSame('taxable_income_request', $items[0]['title']);
        self::assertSame('2025', $items[0]['period']);
        self::assertStringContainsString('panel=taxable_income_requests', $items[0]['path']);
        self::assertStringContainsString("employment={$this->employmentId}", $items[0]['path']);

        $requests->complete($this->supplierId, $this->employeeId, $list[0]['id'], '2026-08-19', $actorId);
        self::assertSame(
            [],
            $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'taxable_income_request'),
        );
    }

    /**
     * § 38a odst. 1 zákona č. 582/1991 Sb.: výzva ČSSZ/ÚSSZ ke sdělení nebo
     * opravě údajů měsíčním hlášením má lhůtu 8 dnů od doručení. Dřív ji
     * aplikace neevidovala vůbec; teď je v přehledu termínů, dokud ji účetní
     * nevyřídí.
     */
    public function testAuthorityRequestToCorrectTheMonthlyReportIsADeadline(): void
    {
        $requests = new PayrollPensionRequestRepository($this->db);
        $actorId = $this->actorId();
        $list = $requests->create($this->supplierId, $this->employeeId, [
            'request_kind' => 'jmh_correction',
            'requester' => 'ossz',
            'requester_reference' => 'SYN-38a-1',
            'received_on' => '2026-08-12',
            'period_from' => '2026-05-01',
            'employment_id' => $this->employmentId,
        ], $actorId);
        self::assertSame('2026-08-20', $list[0]['due_on']);
        self::assertStringContainsString('§ 38a odst. 1', $list[0]['deadline_source']);

        $items = $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'pension_request');
        self::assertCount(1, $items);
        self::assertSame('2026-08-20', $items[0]['due_on']);
        self::assertSame('pension_request_jmh_correction', $items[0]['title']);
        self::assertSame('2026-05', $items[0]['period']);
        self::assertStringContainsString('panel=pension_requests', $items[0]['path']);

        $requests->complete($this->supplierId, $this->employeeId, $list[0]['id'], '2026-08-18', 'SYN-JMH-opravne', $actorId);
        self::assertSame(
            [],
            $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'pension_request'),
        );
        self::assertSame('completed', $requests->list($this->supplierId, $this->employeeId)[0]['status']);
    }

    /**
     * § 37 odst. 2 a čl. V bod 4 zák. č. 360/2025 Sb.: potvrzení o náhradách
     * za ztrátu na výdělku a o směnách v rizikovém zaměstnání do 30 dnů od
     * žádosti; potvrzení podle starého znění jen za období před rokem 2026.
     */
    public function testCertificateRequestsOfTheOldAndCurrentWordingAreDeadlines(): void
    {
        $requests = new PayrollPensionRequestRepository($this->db);
        $actorId = $this->actorId();
        $requests->create($this->supplierId, $this->employeeId, [
            'request_kind' => 'compensation_confirmation',
            'requester' => 'former_employee',
            'received_on' => '2026-08-03',
        ], $actorId);
        $list = $requests->create($this->supplierId, $this->employeeId, [
            'request_kind' => 'legacy_confirmation',
            'legacy_kind' => 'risky_work',
            'requester' => 'employee',
            'received_on' => '2026-08-04',
            'period_year' => 2024,
        ], $actorId);
        self::assertSame(['2026-09-03', '2026-09-02'], array_column($list, 'due_on'));

        $titles = array_column(
            $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'pension_request'),
            'title',
        );
        sort($titles);
        self::assertSame(['pension_request_compensation_confirmation', 'pension_request_risky_work'], $titles);

        try {
            $requests->create($this->supplierId, $this->employeeId, [
                'request_kind' => 'legacy_confirmation',
                'legacy_kind' => 'deep_mining',
                'requester' => 'employee',
                'received_on' => '2026-08-04',
                'period_year' => 2026,
            ], $actorId);
            self::fail('Potvrzení podle znění do 31. 12. 2025 za rok 2026 nevzniká.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('před 1. 1. 2026', $exception->getMessage());
        }
    }

    /** Karta osoby zapisuje a vyřizuje žádost přes endpoint z kontejneru. */
    public function testTaxableIncomeRequestEndpointRoundTrip(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(
            \MyInvoice\Action\Payroll\PayrollTaxableIncomeConfirmationRequestAction::class,
        );
        self::assertInstanceOf(\MyInvoice\Action\Payroll\PayrollTaxableIncomeConfirmationRequestAction::class, $action);
        $request = fn (array $body) => (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', "/api/payroll/people/{$this->employeeId}/taxable-income-requests")
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actorId(), 'role' => 'accountant'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        $created = $action->save(
            $request(['requested_on' => '2026-08-12', 'income_year' => 2025]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $created->getBody()->rewind();
        $list = json_decode((string) $created->getBody(), true)['requests'] ?? [];
        self::assertCount(1, $list);

        $invalid = $action->save(
            $request(['requested_on' => 'včera', 'income_year' => 2025]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(422, $invalid->getStatusCode());

        $completed = $action->save(
            $request(['id' => $list[0]['id'], 'complete' => true, 'completed_on' => '2026-08-19']),
            new \Slim\Psr7\Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(200, $completed->getStatusCode(), (string) $completed->getBody());
        $completed->getBody()->rewind();
        self::assertSame('completed', json_decode((string) $completed->getBody(), true)['requests'][0]['status']);
    }

    public function testTaxableIncomeRequestRejectsImpossibleDates(): void
    {
        $requests = new PayrollTaxableIncomeConfirmationRequestRepository($this->db);
        $this->expectException(\InvalidArgumentException::class);
        $requests->create($this->supplierId, $this->employeeId, [
            'requested_on' => '2026-08-12',
            'income_year' => 2027,
        ], $this->actorId());
    }

    /**
     * § 183 odst. 1 ZP: doklady do 10 pracovních dnů po skončení cesty,
     * vyúčtování do 10 pracovních dnů od jejich předložení. Cesta končící
     * v pátek 14. 8. má doklady do 28. 8.; předložené 19. 8. se vyúčtují do
     * 2. 9. Schválená (vyúčtovaná) cesta termín nemá.
     */
    public function testBusinessTripSettlementDeadlinesFollowTheLabourCode(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, country_code, timezone_name,
                 departure_at_utc, arrival_at_utc, origin_place, destination_place,
                 purpose, settlement_period_start)
             VALUES (?, ?, ?, "CZ", "Europe/Prague", "2026-08-14 05:00:00",
                     "2026-08-14 14:00:00", "Praha", "Syntetické Brno",
                     "Syntetické jednání", "2026-08-01")',
        )->execute([$this->supplierId, $this->employeeId, $this->employmentId]);
        $tripId = (int) $this->db->pdo()->lastInsertId();

        $items = $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'business_trip');
        self::assertCount(1, $items);
        self::assertSame('business_trip_documents', $items[0]['title']);
        self::assertSame('2026-08-28', $items[0]['due_on']);
        self::assertSame('Syntetické Brno', $items[0]['trip_label']);
        self::assertStringContainsString("trip={$tripId}", $items[0]['path']);
        self::assertStringContainsString('period=2026-08', $items[0]['path']);
        self::assertStringContainsString('§ 183 odst. 1', (string) $items[0]['deadline_source']);

        $this->db->pdo()->prepare('UPDATE payroll_business_trips SET documents_submitted_on = "2026-08-19" WHERE id = ?')
            ->execute([$tripId]);
        $items = $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'business_trip');
        self::assertCount(1, $items);
        self::assertSame('business_trip_settlement', $items[0]['title']);
        self::assertSame('2026-09-02', $items[0]['due_on']);

        $this->db->pdo()->prepare('UPDATE payroll_business_trips SET status = "cancelled" WHERE id = ?')
            ->execute([$tripId]);
        self::assertSame(
            [],
            $this->itemsOfSource($this->service->overview($this->supplierId, 'production'), 'business_trip'),
        );
    }

    private function actorId(): int
    {
        $statement = $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1');
        $id = $statement === false ? 0 : (int) $statement->fetchColumn();
        if ($id <= 0) {
            self::markTestSkipped('Chybí uživatel.');
        }

        return $id;
    }

    /**
     * @param array{items:list<array<string,mixed>>} $overview
     * @return list<array<string,mixed>>
     */
    private function itemsOfSource(array $overview, string $source): array
    {
        return array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['source'] === $source,
        ));
    }

    private function checklistItem(string $itemKey, ?string $dueDate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status, due_date)
             VALUES (?, ?, "onboarding", ?, "pending", ?)',
        )->execute([$this->supplierId, $this->employmentId, $itemKey, $dueDate]);
    }

    private function obligation(
        string $agendaCode,
        string $sourceEventType,
        string $sourceEventReference,
        string $periodStart,
        string $status,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, "production", ?, "employment", ?, ?, ?, "regular",
                     "vrep_apep", ?, ?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $agendaCode,
            'employment:' . $this->employmentId,
            $periodStart,
            $periodStart,
            $status,
            $sourceEventType,
            $sourceEventReference,
            str_repeat('a', 64),
            str_repeat('b', 64),
            bin2hex(random_bytes(32)),
        ]);
    }
}
