<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentitySnapshotRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDeltaPlanner;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetector;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableProfileBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\EmployerRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollEmployeeRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteractionResolver;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Registrace pracovního vztahu PRODUKČNÍ CESTOU: přes Action, ne přímým
 * voláním serializéru. Přesně tahle mezera způsobila, že jádro PREZEC/REGZEC
 * mělo zelené testy a přitom se nikdy nespustilo.
 *
 * Žádný test tu nesahá na síť — připravuje se jen XML a záznam podání.
 */
#[Group('integration')]
final class PayrollRegistrationActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Nástup pět dnů po „dnešku" testu — uvnitř okna PREZEC P1 (0–8 dnů). */
    private const TODAY = '2026-08-17';
    private const START_ON = '2026-08-22';

    private Connection $db;
    private PayrollSensitiveData $sensitive;
    private PayrollRegistrationIdentityService $identities;
    private PayrollRegistrationAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;
    private int $identityId;
    private int $officeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$db->hasTable('payroll_obligations')
            || !$db->hasTable('payroll_identity_resolution_tasks')
            || !$db->hasTable('payroll_registration_event_snapshots')
            || !$db->hasTable('payroll_registration_a1_profiles')
        ) {
            $this->markTestSkipped('Migrace podání/identit neproběhly.');
        }
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $this->sensitive = $sensitive;
        $identities = $container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(
            PayrollRegistrationIdentityService::class,
            $identities,
        );
        $this->identities = $identities;

        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1, company_name = ?
              WHERE id IN (?, ?)',
        )->execute([
            'Syntetický zaměstnavatel s.r.o.',
            $this->supplierId,
            $this->otherSupplierId,
        ]);

        $this->seedEmployer($pdo);
        $this->seedPerson($pdo);
        $this->action = $this->buildAction($container);
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

    /**
     * Nástup: český občan před zahájením práce se přihlašuje částečně
     * (PREZEC P1). Serializér i validátor se volají uvnitř Action.
     */
    public function testHireBeforeStartPreparesPrezecP1ThroughTheAction(): void
    {
        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
        $body = $this->json($response);
        self::assertSame('PREZEC26', $body['agenda_code']);
        self::assertSame('limited_pre_registration', $body['interaction']);
        // Podání smí skončit nejvýš ve stavu `ready`. Cokoli dál by tvrdilo,
        // že ČSSZ přihlášku převzala.
        self::assertSame('ready', $body['status']);
        self::assertTrue($body['created']);
        self::assertSame(
            self::START_ON,
            $body['deadline']['due_on'],
        );
        self::assertSame(
            '2026-08-14',
            $body['deadline']['earliest_registration_on'],
        );

        $stored = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('<PREZEC', $stored);
        self::assertStringContainsString('act="9"', $stored);
        self::assertSame(
            $body['artifact_sha256'],
            hash('sha256', $stored),
        );

        // Registrační povinnost musí být v registru MZ-19, jinak by lhůtu
        // nikdo nehlídal.
        self::assertSame(1, $this->countObligations('PREZEC26'));
        // A protože jde o první pracovní vztah, musí vzniknout i povinnost
        // přihlásit zaměstnavatele do evidence.
        self::assertSame(1, $this->countObligations('REGZEL26'));
        self::assertSame(
            self::START_ON,
            $this->checklistDueDate(),
        );
    }

    public function testRegistrationAfterActualStartKeepsIncompleteRegzecA1Closed(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);

        $response = $this->post();
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame(
            'registration_regzec_a1_activity_missing',
            $body['error']['code'],
        );
        self::assertSame(0, $this->countSubmissions());
    }

    /** Skončený vztah bez schváleného A2 zdroje nesmí znovu vytvořit A1. */
    public function testTerminatedEmploymentCannotFallBackToA1(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-31",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);

        $response = $this->post();
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_regzec_a2_source_missing',
            $body['error']['code'],
        );
        self::assertSame(0, $this->countSubmissions());
    }

    public function testApprovedTerminationEventPreparesRegzecA2WithTheFrozenOid(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            null,
            null,
            true,
        );

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $eventResponse->getStatusCode(), (string) $eventResponse->getBody());
        $event = $this->json($eventResponse);
        $events = Bootstrap::buildContainer()->get(PayrollRegistrationEventService::class);
        self::assertInstanceOf(PayrollRegistrationEventService::class, $events);
        $snapshot = $events->load(
            $this->supplierId,
            'test',
            $this->employmentId,
            (int) $event['id'],
        );
        self::assertSame(
            'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1',
            $snapshot['jmhz_codebook']['package_key'] ?? null,
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            (string) ($snapshot['jmhz_codebook']['manifest_sha256'] ?? ''),
        );
        self::assertSame(
            'accepted',
            $snapshot['data']['jmhz_correction_evidence']['decision'] ?? null,
        );
        self::assertSame([], $snapshot['data']['jmhz_correction_evidence']['months'] ?? null);
        $ledger = $this->db->pdo()->prepare(
            'SELECT plan_sha256 FROM payroll_registration_a2_evidence_ledger
              WHERE supplier_id = ? AND environment = "test"
                AND event_snapshot_id = ?',
        );
        $ledger->execute([$this->supplierId, (int) $event['id']]);
        self::assertSame(
            $snapshot['data']['jmhz_correction_evidence']['fingerprint'] ?? null,
            $ledger->fetchColumn(),
        );

        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $body = $this->json($prepared);
        self::assertSame('termination', $body['interaction']);
        self::assertSame('2026-09-02', $body['deadline']['due_on']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="2"', $xml);
        self::assertStringContainsString('oid="200000000000000000002"', $xml);
        self::assertStringContainsString('to="2026-08-25"', $xml);
        self::assertStringNotContainsString(' fro=', $xml);
        self::assertStringNotContainsString('endbydeath=', $xml);
        self::assertStringNotContainsString('<unemplcomp', $xml);
    }

    public function testA2PrepareRechecksCorrectionEvidenceUnderSupplierLock(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25", status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            null,
            null,
            true,
        );
        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $event = $this->json($approved);

        $this->seedCorrectiveMonth('2026-07-01');

        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertSame(
            'registration_a2_jmhz_evidence_changed',
            $this->json($prepared)['error']['code'],
        );
    }

    public function testA2BlocksEveryCorrectiveMonthWithoutAcceptedJmhzEvidence(): void
    {
        if (!$this->db->hasTable('payroll_registration_a2_evidence_ledger')) {
            $this->markTestSkipped('Migrace důkazního ledgeru REGZEC A2 neproběhla.');
        }
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            null,
            null,
            true,
        );
        $this->seedCorrectiveMonth('2026-06-01');
        $this->seedCorrectiveMonth('2026-07-01');

        $candidateResponse = ($this->action)->a2EvidenceCandidates(
            $this->request('GET')->withQueryParams([
                'environment' => 'test',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $candidateResponse->getStatusCode(), (string) $candidateResponse->getBody());
        $candidate = $this->json($candidateResponse);
        self::assertSame('blocked', $candidate['decision']);
        self::assertSame(
            ['2026-06-01', '2026-07-01'],
            array_column($candidate['months'], 'period_start'),
        );

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a2_jmhz_corrections_incomplete',
            $this->json($response)['error']['code'],
        );
        $ledger = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_registration_a2_evidence_ledger
              WHERE supplier_id = ?',
        );
        $ledger->execute([$this->supplierId]);
        self::assertSame(0, (int) $ledger->fetchColumn());
    }

    public function testA2RejectsManualOicProvenance(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, self::START_ON);

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_a2_oic_provenance_invalid',
            $this->json($response)['error']['code'],
        );
    }

    public function testA2RejectsManualIdPpvProvenance(): void
    {
        $this->seedTrustedReceipt();
        $this->employmentId = $this->insertAdditionalEmployment(
            'reg-manual-id-ppv',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            null,
            null,
            true,
        );
        $this->identities->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'test',
            '300000000000000000003',
            self::START_ON,
            'verified_manual_import',
            'synthetic-manual-id-ppv',
            null,
            $this->userId,
        );

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_a2_id_ppv_provenance_invalid',
            $this->json($response)['error']['code'],
        );
    }

    public function testA2RejectsTrustedReceiptWithoutMatchingOutcome(): void
    {
        $receiptId = $this->seedTrustedReceipt(false);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            $receiptId,
            null,
        );

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_a2_oic_provenance_invalid',
            $this->json($response)['error']['code'],
        );
    }

    public function testTrustedRegistrationReceiptCommitsWithoutReplacingManualIdentities(): void
    {
        $this->seedRegistrationEventPrerequisites('10', null, self::START_ON);

        $receiptId = $this->seedTrustedReceipt(true, false);
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::START_ON,
        );
        $storedReceipt = $this->db->pdo()->prepare(
            'SELECT verification_status, remote_status
               FROM payroll_submission_receipts
              WHERE supplier_id = ? AND id = ?',
        );
        $storedReceipt->execute([$this->supplierId, $receiptId]);
        $receipt = $storedReceipt->fetch(PDO::FETCH_ASSOC);

        self::assertGreaterThan(0, $receiptId);
        self::assertSame('trusted', $receipt['verification_status'] ?? null);
        self::assertSame('accepted', $receipt['remote_status'] ?? null);
        self::assertSame(
            'verified_manual_import',
            $identity['person_external_identifier']['source_kind'],
        );
        self::assertSame(
            'verified_manual_import',
            $identity['employment_external_identifier']['source_kind'],
        );
        self::assertNull($identity['person_external_identifier']['source_receipt_id']);
        self::assertNull($identity['employment_external_identifier']['source_receipt_id']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_a2_oic_provenance_invalid',
            $this->json($response)['error']['code'],
        );
    }

    public function testTrustedRegistrationReceiptAssignsIdPpvForAnotherEmploymentWithExistingTrustedOic(): void
    {
        $firstReceiptId = $this->seedTrustedReceipt();
        $this->employmentId = $this->insertAdditionalEmployment(
            'reg-second-employment',
        );

        $secondReceiptId = $this->seedTrustedReceipt(
            expectedPersonReceiptId: $firstReceiptId,
            externalEmploymentReference: '300000000000000000003',
        );
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::START_ON,
            true,
        );

        self::assertNotSame($firstReceiptId, $secondReceiptId);
        self::assertSame(
            $firstReceiptId,
            $identity['person_external_identifier']['source_receipt_id'],
        );
        self::assertSame(
            $secondReceiptId,
            $identity['employment_external_identifier']['source_receipt_id'],
        );
    }

    public function testTrustedRegistrationReceiptUsesFrozenEffectiveDate(): void
    {
        $receiptId = $this->seedTrustedReceipt(
            expectTrustedIdentities: false,
            beforeReceipt: function (): void {
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employments
                        SET actual_start_date = "2026-08-23"
                      WHERE supplier_id = ? AND id = ?',
                )->execute([$this->supplierId, $this->employmentId]);
            },
        );
        $person = $this->db->pdo()->prepare(
            'SELECT valid_from FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ?
                AND environment = "test" AND source_receipt_id = ?',
        );
        $person->execute([
            $this->supplierId,
            $this->employeeId,
            $receiptId,
        ]);
        $employment = $this->db->pdo()->prepare(
            'SELECT valid_from FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ?
                AND environment = "test" AND source_receipt_id = ?',
        );
        $employment->execute([
            $this->supplierId,
            $this->employmentId,
            $receiptId,
        ]);

        self::assertSame(self::START_ON, $person->fetchColumn());
        self::assertSame(self::START_ON, $employment->fetchColumn());
    }

    public function testPreRegistrationNoShowReceiptDoesNotAssignIdentities(): void
    {
        $this->seedAcceptedPreRegistration();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $receiptId = $this->seedTrustedReceipt(
            expectTrustedIdentities: false,
        );
        $person = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ?
                AND environment = "test" AND source_receipt_id = ?',
        );
        $person->execute([
            $this->supplierId,
            $this->employeeId,
            $receiptId,
        ]);
        $employment = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ?
                AND environment = "test" AND source_receipt_id = ?',
        );
        $employment->execute([
            $this->supplierId,
            $this->employmentId,
            $receiptId,
        ]);

        self::assertSame(0, (int) $person->fetchColumn());
        self::assertSame(0, (int) $employment->fetchColumn());
    }

    public function testTrustedBatchRegistrationReceiptAssignsEveryOutcome(): void
    {
        $secondEmploymentId = $this->insertAdditionalEmployment(
            'reg-batch-employment',
        );
        $prepared = $this->json($this->post());
        self::assertSame('ready', $prepared['status']);
        $secondPart = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_parts
                (supplier_id, environment, submission_id, part_reference,
                 agenda_code, subject_reference, status, source_entity_type,
                 source_entity_reference, source_snapshot_hash)
             VALUES (?, "test", ?, ?, ?, ?, "ready", "payroll_employment",
                     ?, ?)'
        );
        $secondPart->execute([
            $this->supplierId,
            (int) $prepared['submission_id'],
            'registration:batch:' . $secondEmploymentId,
            (string) $prepared['agenda_code'],
            'payroll_employment:' . $secondEmploymentId,
            'payroll_employment_registration:' . $secondEmploymentId,
            str_repeat('f', 64),
        ]);
        $secondPartId = (int) $this->db->pdo()->lastInsertId();
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            'synthetic-batch-registration-correlation',
        );
        $firstPartId = (int) $prepared['part_id'];
        $verifier = new class ($firstPartId, $secondPartId) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly int $firstPartId,
                private readonly int $secondPartId,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    'accepted',
                    $expectedCorrelationReference,
                    [
                        $this->firstPartId => 'accepted',
                        $this->secondPartId => 'accepted',
                    ],
                    [
                        new PayrollVerifiedReceiptFormOutcome(
                            '11111111-2222-4333-8444-555555555550',
                            null,
                            1,
                            'Accepted',
                            'accepted',
                            '1000000001',
                            '400000000000000000004',
                            [],
                        ),
                        new PayrollVerifiedReceiptFormOutcome(
                            '11111111-2222-4333-8444-555555555551',
                            $this->firstPartId,
                            1,
                            'Accepted',
                            'accepted',
                            '1000000001',
                            '200000000000000000002',
                            [],
                        ),
                        new PayrollVerifiedReceiptFormOutcome(
                            '11111111-2222-4333-8444-555555555552',
                            $this->secondPartId,
                            1,
                            'Accepted',
                            'accepted',
                            '1000000001',
                            '300000000000000000003',
                            [],
                        ),
                    ],
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            null,
            '<synthetic-batch-receipt/>',
            'synthetic-batch-registration-receipt',
            'synthetic-batch-registration-correlation',
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            'synthetic-batch-registration-key',
            $this->userId,
            $verifier,
        );

        foreach ([$this->employmentId, $secondEmploymentId] as $employmentId) {
            $identity = $this->identities->sensitiveJmhzIdentityAt(
                $this->supplierId,
                $this->employeeId,
                $employmentId,
                'test',
                self::START_ON,
                true,
            );
            self::assertSame(
                $receipt['id'],
                $identity['employment_external_identifier']['source_receipt_id'],
            );
        }
    }

    public function testPartiallyAcceptedRegistrationReceiptAssignsOnlyAcceptedOutcome(): void
    {
        $secondEmploymentId = $this->insertAdditionalEmployment(
            'reg-partial-batch-employment',
        );
        $prepared = $this->json($this->post());
        $secondPart = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_parts
                (supplier_id, environment, submission_id, part_reference,
                 agenda_code, subject_reference, status, source_entity_type,
                 source_entity_reference, source_snapshot_hash)
             VALUES (?, "test", ?, ?, ?, ?, "ready", "payroll_employment",
                     ?, ?)'
        );
        $secondPart->execute([
            $this->supplierId,
            (int) $prepared['submission_id'],
            'registration:partial-batch:' . $secondEmploymentId,
            (string) $prepared['agenda_code'],
            'payroll_employment:' . $secondEmploymentId,
            'payroll_employment_registration:' . $secondEmploymentId,
            str_repeat('e', 64),
        ]);
        $secondPartId = (int) $this->db->pdo()->lastInsertId();
        $firstPartId = (int) $prepared['part_id'];
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            'synthetic-partial-batch-correlation',
        );
        $verifier = new class ($firstPartId, $secondPartId) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly int $firstPartId,
                private readonly int $secondPartId,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    'partially_accepted',
                    $expectedCorrelationReference,
                    [
                        $this->firstPartId => 'accepted',
                        $this->secondPartId => 'rejected',
                    ],
                    [
                        new PayrollVerifiedReceiptFormOutcome(
                            '11111111-2222-4333-8444-555555555561',
                            $this->firstPartId,
                            1,
                            'Accepted',
                            'accepted',
                            '1000000001',
                            '200000000000000000002',
                            [],
                        ),
                        new PayrollVerifiedReceiptFormOutcome(
                            '11111111-2222-4333-8444-555555555562',
                            $this->secondPartId,
                            2,
                            'Rejected',
                            'rejected',
                            null,
                            null,
                            [],
                        ),
                    ],
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            null,
            '<synthetic-partial-batch-receipt/>',
            'synthetic-partial-batch-receipt',
            'synthetic-partial-batch-correlation',
            'CSSZ_REGZEC',
            'partially_accepted',
            'vrep_apep',
            'synthetic-partial-batch-key',
            $this->userId,
            $verifier,
        );
        $first = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::START_ON,
            true,
        );
        $second = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ?
                AND environment = "test" AND source_receipt_id = ?',
        );
        $second->execute([
            $this->supplierId,
            $secondEmploymentId,
            (int) $receipt['id'],
        ]);

        self::assertSame(
            $receipt['id'],
            $first['employment_external_identifier']['source_receipt_id'],
        );
        self::assertSame(0, (int) $second->fetchColumn());
    }

    public function testA2ReplayKeepsTheOriginalSubmissionAfterLiveIdentityChanges(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '10',
            null,
            self::START_ON,
            null,
            null,
            true,
        );

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $eventResponse->getStatusCode());
        $event = $this->json($eventResponse);

        $first = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $firstBody = $this->json($first);
        $firstXml = $this->storedArtifactXml((int) $firstBody['submission_id']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET title_prefix = "Mgr.", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->identityId]);

        $replayed = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $replayed->getStatusCode(), (string) $replayed->getBody());
        $replayedBody = $this->json($replayed);
        self::assertFalse($replayedBody['created']);
        self::assertSame($firstBody['submission_id'], $replayedBody['submission_id']);
        self::assertSame($firstBody['artifact_sha256'], $replayedBody['artifact_sha256']);
        self::assertSame($firstXml, $this->storedArtifactXml((int) $replayedBody['submission_id']));
        self::assertSame(2, $this->countSubmissions());
    }

    /**
     * Tvar 1–3 číslice není důkaz, že důvod ukončení existuje. ČSSZ ho má
     * přímo v připnutém datovém slovníku JMHZ; neznámý kód proto nesmí projít
     * až do XML a teprve tam selhat u protistrany.
     */
    public function testTerminationEventRejectsUnknownEmploymentTerminationReason(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites(
            '1',
            '1',
            self::START_ON,
            null,
            null,
            true,
        );

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
                'ended_by_death' => false,
                'unemployment' => [
                    'mode' => 'provided',
                    'average_net_earnings' => 25_000,
                    'pension_periods' => [[
                        'from' => self::START_ON,
                        'to' => '2026-08-25',
                    ]],
                    'employment_type' => '1',
                    'termination_reason' => '99',
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $eventResponse->getStatusCode());
        self::assertStringContainsString(
            'Důvod ukončení pracovního vztahu',
            $this->json($eventResponse)['error']['message'],
        );
    }

    public function testA3ChangeReplaysTheSameFrozenBusinessEvent(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');
        $request = [
            'environment' => 'test',
            'interaction' => 'change',
            'effective_on' => '2026-03-30',
            'source_reference' => 'synthetic-change-business-replay',
            'changes' => ['title_prefix' => 'Mgr.'],
        ];

        $created = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $first = $this->json($created);

        $replayed = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $replayed->getStatusCode(), (string) $replayed->getBody());
        $second = $this->json($replayed);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
    }

    public function testA3ChangeRejectsBusinessDuplicateAfterLiveSnapshotChanges(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');
        $request = [
            'environment' => 'test',
            'interaction' => 'change',
            'effective_on' => '2026-03-30',
            'source_reference' => 'synthetic-change-business-conflict',
            'changes' => ['title_prefix' => 'Mgr.'],
        ];

        $created = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        $this->db->pdo()->prepare(
            'UPDATE supplier SET company_name = ? WHERE id = ?',
        )->execute(['Změněný syntetický zaměstnavatel s.r.o.', $this->supplierId]);

        $conflict = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame('conflict', $this->json($conflict)['error']['code']);

        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_registration_event_snapshots
              WHERE supplier_id = ? AND environment = "test"
                AND employment_id = ? AND interaction_code = "change"
                AND effective_on = "2026-03-30"
                AND source_reference = "synthetic-change-business-conflict"',
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /**
     * A3 musí projít celou produkční cestou: schválená událost je neměnná,
     * XML je validované proti připnutému XSD a zmrazený artefakt zůstává
     * připravený pro samostatně potvrzený transport.
     */
    public function testA3ChangeIsFrozenValidatedAndReadyForTransport(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-change-q1',
                'changes' => ['title_prefix' => 'Mgr.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $eventResponse->getStatusCode(), (string) $eventResponse->getBody());
        $event = $this->json($eventResponse);

        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $body = $this->json($prepared);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertSame('change', $body['interaction']);
        self::assertSame('ready', $body['status']);
        self::assertSame('2026-03-30', $body['deadline']['earliest_registration_on']);
        self::assertSame('2026-04-07', $body['deadline']['due_on']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="3"', $xml);
        self::assertStringContainsString('fro="2026-03-30"', $xml);
        self::assertStringContainsString('ikmpsv="1000000001"', $xml);
        self::assertStringContainsString('oid="200000000000000000002"', $xml);
        self::assertStringContainsString('<name tit="Mgr."/>', $xml);
        self::assertSame($body['artifact_sha256'], hash('sha256', $xml));

        // Opakování smí vrátit jen tytéž zmrazené bajty, nikoli nový dokument.
        $replayed = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $replayed->getStatusCode());
        self::assertFalse($this->json($replayed)['created']);
        self::assertSame($body['artifact_sha256'], $this->json($replayed)['artifact_sha256']);
    }

    public function testA3ChangeRejectsUnknownHealthInsuranceCode(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-invalid-health-insurer',
                'changes' => ['health_insurance_code' => '999'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'Kód zdravotní pojišťovny',
            $this->json($response)['error']['message'],
        );
    }

    public function testA3ChangeRejectsTaxResidenceWithAnotherEffectiveDate(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-tax-residence-wrong-date',
                'changes' => [
                    'tax_residency' => [
                        'country_code' => 'SK',
                        'changed_on' => '2026-03-29',
                    ],
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a3_effective_date_mismatch',
            $this->json($response)['error']['code'],
        );
    }

    public function testA3RelationshipChangeFailsClosedWithoutExplanationAttachment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-activity-change-without-attachment',
                'changes' => ['relationship_detail_code' => '3'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a3_activity_explanation_attachment_required',
            $this->json($response)['error']['code'],
        );
    }

    public function testA5ToA8RejectActivity10BeforeEventFreeze(): void
    {
        $this->assertA5ToA8RejectVariant('10', null);
    }

    public function testA5ToA8RejectSpecVariantBeforeEventFreeze(): void
    {
        $this->assertA5ToA8RejectVariant('11', '1');
    }

    /**
     * A6: přechod na české právní předpisy nese jen zmrazený podklad o
     * zahraničním nositeli; po přípravě nesmí tvrdit, že jej ČSSZ přijala.
     */
    public function testA6CzechLegislationStartIsValidatedAndFrozenForTransport(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            self::TODAY,
            self::TODAY,
            $this->supplierId,
            $this->employmentId,
        ]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);

        $event = $this->json(($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'czech_legislation_start',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a6-jurisdiction',
                'foreign_insurance' => [
                    'current' => 'P',
                    'name' => 'Syntetická zahraniční instituce',
                    'country_code' => 'SK',
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));

        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $body = $this->json($prepared);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertSame('czech_legislation_start', $body['interaction']);
        self::assertSame('ready', $body['status']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="6"', $xml);
        self::assertStringContainsString('fro="' . self::TODAY . '"', $xml);
        self::assertStringContainsString('oid="200000000000000000002"', $xml);
        self::assertStringContainsString('<forin cur="P" nam="Syntetická zahraniční instituce" cnt="SK"/>', $xml);
        self::assertSame($body['artifact_sha256'], hash('sha256', $xml));
    }

    /** A7: ukončení českých právních předpisů vyžaduje identifikátor nositele. */
    public function testA7CzechLegislationEndIsValidatedAndFrozenForTransport(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            self::TODAY,
            self::TODAY,
            $this->supplierId,
            $this->employmentId,
        ]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);

        $event = $this->json(($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'czech_legislation_end',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a7-jurisdiction',
                'foreign_insurance' => [
                    'current' => 'S',
                    'name' => 'Syntetická zahraniční instituce',
                    'country_code' => 'SK',
                    'identifier' => 'SYN-INS-123',
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));

        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $body = $this->json($prepared);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertSame('czech_legislation_end', $body['interaction']);
        self::assertSame('ready', $body['status']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="7"', $xml);
        self::assertStringContainsString('fro="' . self::TODAY . '"', $xml);
        self::assertStringContainsString('oid="200000000000000000002"', $xml);
        self::assertStringContainsString('<forin cur="S" nam="Syntetická zahraniční instituce" cnt="SK" id="SYN-INS-123"/>', $xml);
        self::assertSame($body['artifact_sha256'], hash('sha256', $xml));
    }

    public function testA4MustUseTheExactDateAndIdentityOfTheAcceptedSourceArtifact(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-08-16", actual_start_date = ?,
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-08-16');

        $sourceEvent = $this->json(($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a4-source-change',
                'changes' => ['title_prefix' => 'Bc.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $source = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $sourceEvent['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $this->markRegistrationAccepted((int) $source['submission_id']);

        $wrongDate = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'correction',
                'effective_on' => '2026-08-18',
                'discovered_on' => '2026-08-18',
                'source_reference' => 'synthetic-a4-wrong-date',
                'source_submission_id' => $source['submission_id'],
                'corrections' => ['title_prefix' => 'Mgr.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $wrongDate->getStatusCode());
        self::assertSame(
            'registration_a4_original_filing_date_mismatch',
            $this->json($wrongDate)['error']['code'],
        );

        $accepted = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'correction',
                'effective_on' => self::TODAY,
                'discovered_on' => self::TODAY,
                'source_reference' => 'synthetic-a4-exact-source',
                'source_submission_id' => $source['submission_id'],
                'corrections' => ['title_prefix' => 'Mgr.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
    }

    public function testA4RelationshipCorrectionFailsClosedWithoutExplanationAttachment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-08-16", actual_start_date = ?,
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-08-16');

        $sourceEvent = $this->json(($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a4-activity-source',
                'changes' => ['title_prefix' => 'Bc.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $source = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $sourceEvent['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $this->markRegistrationAccepted((int) $source['submission_id']);

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'correction',
                'effective_on' => self::TODAY,
                'discovered_on' => self::TODAY,
                'source_reference' => 'synthetic-a4-activity-correction',
                'source_submission_id' => $source['submission_id'],
                'corrections' => ['relationship_detail_code' => '3'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a4_activity_explanation_attachment_required',
            $this->json($response)['error']['code'],
        );
    }

    public function testAcceptedA5ReceiptRotatesIdPpvAndKeepsFrozenReplay(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-08-01", actual_start_date = "2026-08-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-08-01');

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a5-transfer',
                'new_variable_symbol' => '9990005678',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $eventResponse->getStatusCode(), (string) $eventResponse->getBody());
        $event = $this->json($eventResponse);

        $preparedResponse = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $preparedResponse->getStatusCode(), (string) $preparedResponse->getBody());
        $prepared = $this->json($preparedResponse);
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            'synthetic-a5-correlation',
        );
        $newIdPpv = '300000000000000000003';
        $verifier = new class ($prepared['part_id'], $newIdPpv) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly int $partId,
                private readonly string $newIdPpv,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    'accepted',
                    $expectedCorrelationReference,
                    [$this->partId => 'accepted'],
                    [new PayrollVerifiedReceiptFormOutcome(
                        '11111111-2222-4333-8444-555555555555',
                        $this->partId,
                        1,
                        'Accepted',
                        'accepted',
                        '1000000001',
                        $this->newIdPpv,
                        [],
                    )],
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            (int) $prepared['part_id'],
            '<synthetic-receipt/>',
            'synthetic-a5-receipt',
            'synthetic-a5-correlation',
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            'synthetic-a5-receipt-key',
            $this->userId,
            $verifier,
        );
        self::assertTrue($receipt['trusted']);

        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::TODAY,
        );
        self::assertSame(
            $newIdPpv,
            $identity['employment_external_identifier']['value'],
        );
        $oldValidTo = $this->db->pdo()->prepare(
            'SELECT valid_to FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ?
                AND environment = "test" AND identifier_type = "id_ppv"
                AND source_kind = "verified_manual_import"',
        );
        $oldValidTo->execute([$this->supplierId, $this->employmentId]);
        self::assertSame('2026-08-16', $oldValidTo->fetchColumn());

        $replayedResponse = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $replayedResponse->getStatusCode(), (string) $replayedResponse->getBody());
        $replayed = $this->json($replayedResponse);
        self::assertFalse($replayed['created']);
        self::assertSame($prepared['artifact_sha256'], $replayed['artifact_sha256']);
    }

    public function testAcceptedPreRegistrationDoesNotBypassIncompleteA1Guard(): void
    {
        $this->seedAcceptedPreRegistration();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);

        $response = $this->post();
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_regzec_a1_activity_missing',
            $body['error']['code'],
        );
    }

    /**
     * Přijatou předregistraci nelze zopakovat. Duplicitní podání u ČSSZ nejde
     * vzít zpět, takže se raději nepodá nic.
     */
    public function testAcceptedPreRegistrationCannotBeRepeated(): void
    {
        $this->seedAcceptedPreRegistration();

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_interaction_duplicate_p1',
            $this->json($response)['error']['code'],
        );
    }

    /**
     * Opakované zmrazení nesmí vyrobit druhý dokument: nové GUIDy pod stejným
     * podáním by u ČSSZ znamenaly duplicitu.
     */
    public function testRepeatedPrepareReplaysTheFrozenFilingInstead(): void
    {
        $first = $this->json($this->post());
        $second = $this->json($this->post());

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(
            $first['submission_id'],
            $second['submission_id'],
        );
        self::assertSame(
            $first['artifact_sha256'],
            $second['artifact_sha256'],
        );
        self::assertSame(1, $this->countSubmissions());
    }

    public function testLateRegistrationDoesNotBypassIncompleteA1Guard(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-07-06", actual_start_date = "2026-07-06",
                    status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_regzec_a1_activity_missing',
            $this->json($response)['error']['code'],
        );
    }

    /**
     * Nenastoupení (PREZEC P2) bez PROKÁZANÉ přijaté předregistrace se
     * nepodává. Prázdný ledger neznamená „P1 neexistuje", ale „o jejím
     * přijetí nic nevíme" — a na tom se stavět nedá.
     */
    public function testNoShowWithoutAcceptedPreRegistrationStaysClosed(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'registration_interaction_no_show_without_p1',
            $this->json($response)['error']['code'],
        );
        self::assertSame(0, $this->countObligations('PREZEC26'));
    }

    /**
     * Chybějící povinný údaj musí podání zablokovat VĚTOU, PODLE KTERÉ SE DÁ
     * JEDNAT — ne technickou hláškou o neplatném payloadu.
     */
    public function testMissingVariableSymbolBlocksWithAnActionableMessage(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET social_security_variable_symbol = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame(
            'registration_employer_variable_symbol_missing',
            $error['code'],
        );
        self::assertStringContainsString('variabilní symbol', $error['message']);
        self::assertStringContainsString('Nastavení mezd', $error['message']);
        self::assertSame(0, $this->countSubmissions());
    }

    /** Bez data nástupu nelze určit lhůtu ani podat přihlášku. */
    public function testMissingStartDateBlocksWithAnActionableMessage(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('registration_start_date_missing', $error['code']);
        self::assertStringContainsString(
            'datum nástupu',
            $error['message'],
        );
    }

    /** Nácvik ukáže XML, ale nesmí po sobě nechat podání ani povinnost. */
    public function testPreviewShowsTheDocumentWithoutCreatingAnything(): void
    {
        $response = ($this->action)->preview(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('PREZEC26', $body['agenda_code']);
        self::assertStringContainsString('<PREZEC', $body['xml']);
        self::assertFalse($body['official_submission']['supported']);
        self::assertSame(0, $this->countSubmissions());
        self::assertSame(0, $this->countObligations('PREZEC26'));
    }

    /** Úřední podání se z tokenu neposílá. */
    public function testBearerTokenIsRejected(): void
    {
        foreach ([
            ['prepare', 'POST'],
            ['a1Profile', 'GET'],
            ['saveA1Profile', 'PUT'],
        ] as [$method, $httpMethod]) {
            $response = ($this->action)->{$method}(
                $this->request($httpMethod, 'bearer'),
                new Response(),
                ['employmentId' => (string) $this->employmentId],
            );

            self::assertSame(403, $response->getStatusCode(), $method);
            self::assertSame(
                'session_required',
                $this->json($response)['error']['code'],
                $method,
            );
        }
    }

    /** Cizí firma nesmí vidět ani připravit registraci. */
    public function testOtherTenantCannotReachTheEmployment(): void
    {
        $response = ($this->action)->preview(
            $this->request('GET', 'session', $this->otherSupplierId),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'not_found',
            $this->json($response)['error']['code'],
        );

        $profile = ($this->action)->a1Profile(
            $this->request('GET', 'session', $this->otherSupplierId),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(404, $profile->getStatusCode());
        self::assertSame(
            'not_found',
            $this->json($profile)['error']['code'],
        );
    }

    public function testA1ProfileRejectsStaleOptimisticVersion(): void
    {
        $sealed = $this->sensitive->seal(
            '{}',
            PayrollSensitiveField::REGISTRATION_A1_PROFILE,
            $this->supplierId,
            $this->employmentId,
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_a1_profiles
                (supplier_id, employee_id, employment_id, effective_on,
                 profile_ciphertext, profile_hash, reference_hash, row_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            self::START_ON,
            $sealed->ciphertext,
            $sealed->lookupHash,
            str_repeat('a', 64),
        ]);

        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody([
                'effective_on' => self::START_ON,
                'row_version' => 0,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'registration_regzec_a1_profile_conflict',
            $this->json($response)['error']['code'],
        );
    }

    private function post(): \Psr\Http\Message\ResponseInterface
    {
        return ($this->action)->prepare(
            $this->request('POST'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
    }

    private function buildAction(
        \Psr\Container\ContainerInterface $container,
    ): PayrollRegistrationAction {
        // Hodiny se zmrazí, aby okno PREZEC P1 neputovalo s reálným datem
        // běhu testu. Všechno ostatní je produkční instance.
        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    PayrollRegistrationActionTest::todayAtNoon(),
                    new \DateTimeZone('Europe/Prague'),
                );
            }
        };
        $submissions = $container->get(PayrollSubmissionService::class);
        $submissionRepository = $container->get(
            PayrollSubmissionRepository::class,
        );
        $obligations = $container->get(PayrollObligationService::class);
        $events = $container->get(PayrollRegistrationEventService::class);
        $access = $container->get(PayrollModuleAccess::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        self::assertInstanceOf(
            PayrollSubmissionRepository::class,
            $submissionRepository,
        );
        self::assertInstanceOf(PayrollObligationService::class, $obligations);
        self::assertInstanceOf(PayrollRegistrationEventService::class, $events);
        self::assertInstanceOf(PayrollModuleAccess::class, $access);

        $service = new PayrollRegistrationSubmissionService(
            new PayrollRegistrationSubmissionRepository($this->db),
            $this->identities,
            $events,
            new PayrollRegistrationIdentitySnapshotBuilder(),
            new PayrollRegistrationInteractionResolver(),
            new PayrollRegistrationXmlSerializer(),
            new PayrollRegistrationXmlValidator(
                new PayrollRegistrationSchemaCatalog(),
            ),
            new PayrollEmployeeRegistrationDeadlinePolicy(),
            new EmployerRegistrationDeadlinePolicy(),
            $obligations,
            $submissions,
            $submissionRepository,
            new JmhzSubmissionGuidFactory(),
            $clock,
        );

        // Detekce změn běží na týchž zmrazených hodinách jako zbytek Action,
        // jinak by osmidenní lhůta putovala s reálným datem běhu testu.
        $changes = new PayrollRegistrationChangeDetectionService(
            new PayrollRegistrationChangeProposalRepository($this->db),
            new PayrollRegistrationIdentitySnapshotRepository($this->db),
            $container->get(PayrollRegistrationIdentitySnapshotService::class),
            $this->identities,
            $events,
            new PayrollRegistrationChangeDetector(),
            new PayrollRegistrationChangeDeltaPlanner(),
            new PayrollRegistrationReportableProfileBuilder(),
            new PayrollEmployeeRegistrationDeadlinePolicy(),
            new HealthNotificationDeadlinePolicy(),
            $clock,
        );

        return new PayrollRegistrationAction(
            $service,
            $this->identities,
            $changes,
            $access,
        );
    }

    public static function todayAtNoon(): string
    {
        return self::TODAY . ' 12:00:00';
    }

    private function seedEmployer(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name,
                 social_security_variable_symbol, is_active)
             VALUES (?, "REG", "Synteticka uctarna", "9990001234", 1)',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110")
             ON DUPLICATE KEY UPDATE
                default_office_id = VALUES(default_office_id),
                social_security_office_code =
                    VALUES(social_security_office_code)',
        )->execute([$this->supplierId, $this->officeId]);
    }

    private function seedPerson(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Zobrazene jmeno bez parsovani", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, effective_from)
             VALUES (?, ?, "Zobrazene jmeno bez parsovani",
                     "Jana", "Novotná", "Nováková", "2026-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->identityId = (int) $pdo->lastInsertId();
        $this->identities->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            1,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => 'CZ',
                'sex' => 'female',
            ],
        );
        $this->insertBirthNumber('9152031234');
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date, is_legacy_projection)
             VALUES (?, ?, ?, "reg-synthetic", "employment", "planned", ?, 0)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->officeId,
            self::START_ON,
        ]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, due_date)
             VALUES (?, ?, "onboarding", "social_jmhz_registration", ?)',
        )->execute([$this->supplierId, $this->employmentId, '2026-01-01']);
    }

    private function insertAdditionalEmployment(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date, is_legacy_projection)
             VALUES (?, ?, ?, ?, "employment", "planned", ?, 0)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->officeId,
            $code,
            self::START_ON,
        ]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, due_date)
             VALUES (?, ?, "onboarding", "social_jmhz_registration", ?)',
        )->execute([$this->supplierId, $employmentId, '2026-01-01']);

        return $employmentId;
    }

    private function insertBirthNumber(string $value): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, 'birth_number', 'enc:v2:pending', ?, '')",
        )->execute([$this->supplierId, $this->employeeId, random_bytes(32)]);
        $id = (int) $pdo->lastInsertId();
        $sealed = $this->sensitive->seal(
            $value,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $this->supplierId,
            $id,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $id,
        ]);
    }

    private function seedRegistrationEventPrerequisites(
        string $activityCode,
        ?string $relationshipDetail,
        string $validFrom,
        ?int $oicReceiptId = null,
        ?int $idPpvReceiptId = null,
        bool $skipIdentityAssignment = false,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, activity_code,
                 jmhz_relationship_detail_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->officeId,
            $validFrom,
            $validFrom,
            $validFrom,
            $activityCode,
            $relationshipDetail,
        ]);
        if ($skipIdentityAssignment) {
            return;
        }
        if ($oicReceiptId === null && $idPpvReceiptId === null) {
            $this->identities->assignManualJmhzIdentity(
                $this->supplierId,
                $this->employmentId,
                'test',
                '1000000001',
                '200000000000000000002',
                $validFrom,
                'synthetic-regzec-identity',
                true,
                $this->userId,
            );

            return;
        }
        $this->identities->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'test',
            '1000000001',
            $validFrom,
            $oicReceiptId === null ? 'verified_manual_import' : 'trusted_receipt',
            'synthetic-regzec-oic',
            $oicReceiptId,
            $this->userId,
        );
        $this->identities->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'test',
            '200000000000000000002',
            $validFrom,
            $idPpvReceiptId === null ? 'verified_manual_import' : 'trusted_receipt',
            'synthetic-regzec-id-ppv',
            $idPpvReceiptId,
            $this->userId,
        );
    }

    private function seedCorrectiveMonth(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 status, current_revision_no)
             VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 40 DAY), "approved", 1)',
        )->execute([$this->supplierId, $this->officeId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $empty = '{}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, approved_by, approved_at)
             VALUES (?, ?, 1, "correction", "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $empty,
            hash('sha256', $empty),
            $empty,
            hash('sha256', $empty),
            hash('sha256', "regzec-a2-correction:{$this->supplierId}:{$periodStart}", true),
            $this->userId,
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $this->employmentId,
            $empty,
            hash('sha256', $empty),
            $empty,
            hash('sha256', $empty),
        ]);
    }

    private function assertA5ToA8RejectVariant(
        string $activityCode,
        ?string $relationshipDetail,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            self::TODAY,
            self::TODAY,
            $this->supplierId,
            $this->employmentId,
        ]);
        $this->seedRegistrationEventPrerequisites(
            $activityCode,
            $relationshipDetail,
            self::TODAY,
        );
        $cases = [
            'variable_symbol_transfer' => [
                'new_variable_symbol' => '9990005678',
            ],
            'czech_legislation_start' => [
                'foreign_insurance' => [
                    'current' => 'P',
                    'name' => 'Syntetická zahraniční instituce',
                    'country_code' => 'SK',
                ],
            ],
            'czech_legislation_end' => [
                'foreign_insurance' => [
                    'current' => 'S',
                    'name' => 'Syntetická zahraniční instituce',
                    'country_code' => 'SK',
                    'identifier' => 'SYN-INS-123',
                ],
            ],
            'cancellation' => [
                'source_submission_id' => 1,
                'not_started' => true,
            ],
        ];

        foreach ($cases as $interaction => $specificInput) {
            $response = ($this->action)->approveEvent(
                $this->request('POST')->withParsedBody([
                    'environment' => 'test',
                    'interaction' => $interaction,
                    'effective_on' => self::TODAY,
                    'source_reference' => "synthetic-{$interaction}-{$activityCode}",
                    ...$specificInput,
                ]),
                new Response(),
                ['employmentId' => (string) $this->employmentId],
            );

            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(
                'registration_regzec_action_variant_unsupported',
                $this->json($response)['error']['code'],
            );
        }
    }

    private function seedTrustedReceipt(
        bool $withExternalReferences = true,
        bool $expectTrustedIdentities = true,
        ?int $expectedPersonReceiptId = null,
        string $externalEmploymentReference = '200000000000000000002',
        ?callable $beforeReceipt = null,
    ): int
    {
        $prepared = $this->json($this->post());
        self::assertSame('ready', $prepared['status']);
        $correlationReference =
            'synthetic-a2-provenance-correlation:' . $this->employmentId;
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            $correlationReference,
        );
        $partId = (int) $prepared['part_id'];
        $verifier = new class (
            $partId,
            $withExternalReferences,
            $externalEmploymentReference,
        ) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly int $partId,
                private readonly bool $withExternalReferences,
                private readonly string $externalEmploymentReference,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                $outcomes = $this->withExternalReferences ? [new PayrollVerifiedReceiptFormOutcome(
                    '11111111-2222-4333-8444-555555555555',
                    $this->partId,
                    1,
                    'Accepted',
                    'accepted',
                    '1000000001',
                    $this->externalEmploymentReference,
                    [],
                )] : [];

                return new PayrollVerifiedReceipt(
                    'accepted',
                    $expectedCorrelationReference,
                    [$this->partId => 'accepted'],
                    $outcomes,
                );
            }
        };
        $beforeReceipt?->__invoke();
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            $partId,
            '<synthetic-receipt/>',
            'synthetic-a2-provenance-receipt:' . $this->employmentId,
            $correlationReference,
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            'synthetic-a2-provenance-key:' . $this->employmentId,
            $this->userId,
            $verifier,
        );
        self::assertTrue($receipt['trusted']);

        if (!$withExternalReferences || !$expectTrustedIdentities) {
            return (int) $receipt['id'];
        }
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::START_ON,
            true,
        );
        self::assertSame(
            'trusted_receipt',
            $identity['person_external_identifier']['source_kind'],
        );
        self::assertSame(
            'trusted_receipt',
            $identity['employment_external_identifier']['source_kind'],
        );
        self::assertSame(
            $expectedPersonReceiptId ?? $receipt['id'],
            $identity['person_external_identifier']['source_receipt_id'],
        );
        self::assertSame(
            $receipt['id'],
            $identity['employment_external_identifier']['source_receipt_id'],
        );

        return (int) $receipt['id'];
    }

    private function storedArtifactXml(int $submissionId): string
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'SELECT id FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ?
                AND artifact_kind = "outbound_xml"
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $artifactId = (int) $statement->fetchColumn();
        self::assertGreaterThan(0, $artifactId);
        $submissions = Bootstrap::buildContainer()
            ->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return $submissions->artifactBytes($this->supplierId, $artifactId);
    }

    private function markRegistrationAccepted(int $submissionId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "accepted", submitted_at = UTC_TIMESTAMP(),
                    decided_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $submissionId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_submission_parts
                SET status = "accepted"
              WHERE supplier_id = ? AND submission_id = ?'
        )->execute([$this->supplierId, $submissionId]);
    }

    /**
     * Přijatá PREZEC P1 v ledgeru. Zapisuje se přímo SQL: stav `accepted`
     * smí v běhu nastavit jedině protokol od ČSSZ, a ten tenhle test
     * simulovat nemá — jde o vstupní podmínku, ne o testovanou cestu.
     */
    private function seedAcceptedPreRegistration(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, "test", "PREZEC26", "employment", ?, ?, ?, "regular",
                     "vrep_apep", "submitted", "seed", "seed:1", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            'payroll_employment:' . $this->employmentId,
            '2026-08-14',
            self::START_ON,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash, submitted_at, decided_at)
             VALUES (?, "test", ?, "regular", "vrep_apep", "accepted", ?, ?, ?,
                     "2026-08-15 08:00:00", "2026-08-15 09:00:00")',
        )->execute([
            $this->supplierId,
            $obligationId,
            str_repeat('c', 64),
            str_repeat('d', 64),
            random_bytes(32),
        ]);
        $submissionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submission_parts
                (supplier_id, environment, submission_id, part_reference,
                 agenda_code, subject_reference, status, source_entity_type,
                 source_entity_reference, source_snapshot_hash)
             VALUES (?, "test", ?, ?, "PREZEC26", ?, "accepted",
                     "payroll_employment", ?, ?)',
        )->execute([
            $this->supplierId,
            $submissionId,
            'prezec26:seed:' . $this->employmentId,
            'payroll_employment:' . $this->employmentId,
            'payroll_employment_registration:' . $this->employmentId,
            str_repeat('e', 64),
        ]);
    }

    private function obligationEarliest(string $agendaCode): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT deadline.earliest_submission_on
               FROM payroll_submission_deadlines deadline
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = deadline.supplier_id
                AND obligation.id = deadline.obligation_id
              WHERE obligation.supplier_id = ?
                AND obligation.agenda_code = ?
              ORDER BY deadline.id DESC LIMIT 1',
        );
        $statement->execute([$this->supplierId, $agendaCode]);

        return (string) $statement->fetchColumn();
    }

    private function countObligations(string $agendaCode): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_obligations
              WHERE supplier_id = ? AND agenda_code = ?',
        );
        $statement->execute([$this->supplierId, $agendaCode]);

        return (int) $statement->fetchColumn();
    }

    private function countSubmissions(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?',
        );
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function checklistDueDate(): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT due_date FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ?
                AND phase = "onboarding"
                AND item_key = "social_jmhz_registration"',
        );
        $statement->execute([$this->supplierId, $this->employmentId]);

        return (string) $statement->fetchColumn();
    }

    private function request(
        string $method,
        string $authMethod = 'session',
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/submissions/registration/'
                    . $this->employmentId,
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody(['environment' => 'test']);
    }

    /** @return array<string,mixed> */
    private function json(
        \Psr\Http\Message\ResponseInterface $response,
    ): array {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
