<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1MasterDataWriter;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
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
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmployerName;
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
    private PayrollRegistrationSubmissionService $registrationService;
    private ClockInterface $clock;
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
            'registration_regzec_a1_profile_missing',
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
            'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.10_manifest-v1',
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
        // Věta začíná lidským názvem podání, ne kódem „REGZEC A2", a jmenuje
        // konkrétní blokující období. Zkratka JMHZ zůstává rozepsaná.
        $message = (string) $this->json($response)['error']['message'];
        self::assertStringStartsWith(
            'Oznámení o skončení pracovního vztahu (REGZEC A2)',
            $message,
        );
        self::assertStringContainsString(
            'jednotná měsíční hlášení zaměstnavatele (JMHZ)',
            $message,
        );
        self::assertStringContainsString('2026-06-01, 2026-07-01', $message);
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

    /**
     * Rozhodnutí 26. 9. 2026 (bod 8): zaměstnanec převzatý z ONZ bez
     * dohlášení A3 smí dostat odhlášku A2 s ručně zapsaným OIČ a ID PPV,
     * pokud je účetní výslovně ověří v Seznamu zaměstnanců ČSSZ. Dřív A2
     * padala, dokud ČSSZ nepřijala A3.
     */
    public function testA2ForOnzEmployeeWithoutA3PassesAfterExplicitVerification(): void
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
                'identifiers_verified_in_cssz_list' => true,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $event = $this->registrationService->listEvents($this->supplierId, 'test', $this->employmentId);
        self::assertSame('termination', $event[0]['interaction']);
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($response)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString('act="2"', $xml);
        self::assertStringContainsString('ikmpsv="1000000001"', $xml);
    }

    /**
     * Pojistka rozhodnutí bodu 8: ručně potvrzené ID PPV nesmí nést jiný
     * vztah — odhláška by ukončila cizí vztah téže osoby. Hlídá to unikátní
     * index nad otiskem hodnoty; druhý zápis téhož čísla neprojde.
     */
    public function testSameIdPpvCannotBeRecordedForAnotherEmployment(): void
    {
        $this->seedRegistrationEventPrerequisites('10', null, self::START_ON);
        $other = $this->insertAdditionalEmployment('reg-duplicate-id-ppv');

        try {
            $this->identities->assignManualJmhzIdentity(
                $this->supplierId,
                $other,
                'test',
                null,
                '200000000000000000002',
                self::START_ON,
                'synthetic-duplicate-id-ppv',
                true,
                $this->userId,
            );
            self::fail('Stejné ID PPV se zapsalo k druhému vztahu.');
        } catch (\PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }

    /**
     * Čísla z importu exportu zaměstnanců ČSSZ (Seznam zaměstnanců) jsou
     * podklad sama o sobě — A2 projde bez dalšího potvrzení.
     */
    public function testA2ForOnzEmployeeTrustsIdentifiersFromCsszEmployeeExport(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, self::START_ON, null, null, true);
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $this->employmentId,
            'test',
            '1000000001',
            '200000000000000000002',
            self::START_ON,
            'jmhz-registration-import:synthetic-export:1',
            true,
            $this->userId,
            false,
            'cssz_employee_export',
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

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
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

    /**
     * REG-02: ukončení předregistrace (P2) musí nést GUID formuláře přijaté P1
     * (atribut 10012). Čerstvě vygenerovaný GUID ČSSZ nespáruje a předregistrace
     * zůstane otevřená.
     */
    public function testPrezecP2ReferencesTheGuidOfTheAcceptedP1(): void
    {
        $p1Guid = $this->seedAcceptedPreRegistrationReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('PREZEC26', $body['agenda_code']);
        self::assertSame('pre_registration_no_show', $body['interaction']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="10"', $xml);
        self::assertStringContainsString('idform="' . $p1Guid . '"', $xml);
    }

    /**
     * Přijatá P1 bez doloženého GUID (ledger bez protokolu) ukončení
     * nepustí: P2 s nesprávnou referencí nelze zpracovat.
     */
    public function testPrezecP2IsBlockedWhenTheGuidOfTheP1IsNotProven(): void
    {
        $this->seedAcceptedPreRegistration();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_prezec_p1_guid_missing',
            $this->json($response)['error']['code'],
        );
        // Zůstává jen nasazená přijatá P1; žádné nové podání nevzniklo.
        self::assertSame(1, $this->countSubmissions());
    }

    public function testPreRegistrationNoShowReceiptDoesNotAssignIdentities(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
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

    /**
     * Důvod skončení má jediný zdroj — záznam na kartě vztahu. Odhláška A2
     * ho nesmí tvrdit jinak; dřív se oba údaje zadávaly nezávisle a mohly
     * se rozejít (A2 „výpověď zaměstnance", potvrzení pro ÚP „dohoda").
     */
    public function testTerminationEventMustMatchTheTerminationRecord(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terminations
                (supplier_id, employment_id, termination_method, legal_ground)
             VALUES (?, ?, "agreement", "none")',
        )->execute([$this->supplierId, $this->employmentId]);

        $payload = fn (string $reason): array => [
            'environment' => 'test',
            'interaction' => 'termination',
            'effective_on' => '2026-08-25',
            'ended_by_death' => false,
            'unemployment' => [
                'mode' => 'provided',
                'average_net_earnings' => 25_000,
                'pension_periods' => [['from' => self::START_ON, 'to' => '2026-08-25']],
                'employment_type' => '1',
                'termination_reason' => $reason,
            ],
        ];
        $mismatch = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($payload('3')),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $mismatch->getStatusCode(), (string) $mismatch->getBody());
        self::assertStringContainsString('Skončení vztahu', $this->json($mismatch)['error']['message']);

        $match = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($payload('2')),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $match->getStatusCode(), (string) $match->getBody());
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
        // Účetní musí z hlášky vidět OBĚ data, jinak neví, které opravit.
        $message = (string) $this->json($response)['error']['message'];
        self::assertStringStartsWith('Datum změny daňové rezidence', $message);
        self::assertStringContainsString('2026-03-29', $message);
        self::assertStringContainsString('2026-03-30', $message);
        self::assertStringEndsWith('(tax_residency.changed_on)', $message);
    }

    /**
     * REG-06: rezidence v jiném státě než ČR nese adresu bydliště (`rdr`),
     * jinak ji ČSSZ odmítne. Pravidlo je totéž jako u přihlášky A1.
     */
    public function testA3TaxResidencyOutsideCzechiaRequiresResidenceAddress(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $request = [
            'environment' => 'test',
            'interaction' => 'change',
            'effective_on' => '2026-03-30',
            'source_reference' => 'synthetic-tax-residence-us',
            'changes' => [
                'tax_residency' => [
                    'country_code' => 'US',
                    'changed_on' => '2026-03-30',
                ],
            ],
        ];

        $rejected = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(
            'registration_tax_residence_address_missing',
            $this->json($rejected)['error']['code'],
        );

        $request['changes']['tax_residency']['identifier_type'] = 'T';
        $request['changes']['tax_residency']['identifier'] = 'SYN-TIN-7';
        $request['changes']['tax_residency']['residence_address'] = [
            'street' => 'Main',
            'house_number' => '1',
            'postal_code' => '123 45',
            'city' => 'Springfield',
            'country_code' => 'US',
        ];
        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString('<rdr ', $xml);
        self::assertStringContainsString('pnu="12345"', $xml);
        self::assertStringContainsString('stat="US"', $xml);
        self::assertStringContainsString('statchang="2026-03-30"', $xml);
    }

    /**
     * REG-09: PSČ s mezerou („110 00") je pro schéma neplatné. V delta adrese
     * se mezery odstraní; chybný tvar vrací srozumitelnou větu, ne hlášku XSD.
     */
    public function testA3AddressPostalCodeIsNormalizedAndValidated(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');
        $address = [
            'street' => 'Krátká',
            'house_number' => '3',
            'postal_code' => '110 00',
            'city' => 'Praha',
            'country_code' => 'CZ',
        ];

        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-postal-code-space',
                'changes' => ['permanent_address' => $address],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertStringContainsString(
            'pnu="11000"',
            $this->storedArtifactXml((int) $this->json($prepared)['submission_id']),
        );

        $address['postal_code'] = '1100';
        $bad = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-postal-code-short',
                'changes' => ['permanent_address' => $address],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $bad->getStatusCode(), (string) $bad->getBody());
        self::assertStringContainsString(
            'pět číslic',
            $this->json($bad)['error']['message'],
        );
    }

    /**
     * REG-04: A3 nese změnu příjmení, důchodu, ZTP, pobytu v ČR, dokladu
     * totožnosti a státu cizí legislativy; dřív šla jen ručně mimo aplikaci.
     */
    public function testA3ChangeCarriesIdentityPensionFactsAndForeignData(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => 'synthetic-a3-wide-delta',
                'changes' => [
                    'identity' => [
                        'last_name' => 'Nováková',
                        'first_name' => 'Jana',
                        'citizenship_country_code' => 'SK',
                        'previous_surnames' => 'Novotná',
                    ],
                    'pension' => [
                        'type_code' => 'S',
                        'received_from' => '2026-03-15',
                        'early_retirement' => false,
                    ],
                    'facts' => [
                        'disability_card' => true,
                        'health_restrictions' => [
                            ['type_code' => '2', 'from' => '2026-03-20'],
                        ],
                    ],
                    'permanent_address' => [
                        'street' => 'Testovacia',
                        'house_number' => '7',
                        'postal_code' => '81101',
                        'city' => 'Bratislava',
                        'country_code' => 'SK',
                    ],
                    'czech_residence_address' => [
                        'street' => 'Krátká',
                        'house_number' => '3',
                        'postal_code' => '602 00',
                        'city' => 'Brno',
                    ],
                    'proof_identity' => [
                        'type_code' => 'P',
                        'number' => 'SYN-54321',
                        'country_code' => 'SK',
                    ],
                    'foreign_legislation' => [
                        'applies' => true,
                        'country_code' => 'DE',
                    ],
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertMatchesRegularExpression('/<name [^>]*sur="Nováková"[^>]*fir="Jana"/', $xml);
        self::assertStringContainsString('<stat cnt="SK"/>', $xml);
        self::assertStringContainsString('<pens ', $xml);
        self::assertStringContainsString('ztp="A"', $xml);
        self::assertStringContainsString('<healtrest ', $xml);
        self::assertStringContainsString('<fdr ', $xml);
        self::assertStringContainsString('pnu="60200"', $xml);
        self::assertStringContainsString('<proofid ', $xml);
        self::assertStringContainsString('state="DE"', $xml);
    }

    /**
     * REG-05: platnost změny a začátek lhůty jsou dvě data. Změna s platností
     * od 1. 8., o které se zaměstnavatel dozvěděl 12. 8., se hlásí s `fro`
     * 1. 8., ale osm dnů běží od 12. 8.
     */
    public function testA3ChangeKeepsValidityAndStartsTheDeadlineWhenLearned(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, '2026-03-01');

        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-08-01',
                'learned_on' => '2026-08-12',
                'source_reference' => 'synthetic-a3-backdated',
                'changes' => ['title_prefix' => 'Mgr.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $event = $this->json($approved);
        self::assertSame('2026-08-01', $event['effective_on']);

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
        self::assertSame('2026-08-12', $body['deadline']['earliest_registration_on']);
        self::assertSame('2026-08-20', $body['deadline']['due_on']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('fro="2026-08-01"', $xml);
    }

    public function testA3ChangeCannotBeApprovedBeforeTheChangeTakesEffect(): void
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
                'effective_on' => '2099-01-01',
                'learned_on' => '2026-08-12',
                'source_reference' => 'synthetic-a3-future-validity',
                'changes' => ['title_prefix' => 'Mgr.'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_event_in_future',
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
        // Hláška musí říct, CO nejde ohlásit, PROČ a co s tím — ne jen že
        // „podání zůstává uzavřené".
        $message = (string) $this->json($response)['error']['message'];
        self::assertStringStartsWith(
            'Bližší určení pracovněprávního vztahu',
            $message,
        );
        self::assertStringContainsString(
            'Oznámení o změně údajů zaměstnance (REGZEC A3)',
            $message,
        );
        self::assertStringContainsString('mimo aplikaci', $message);
        self::assertStringEndsWith('(relationship_detail_code)', $message);
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
        )->execute(['2026-08-16', $this->supplierId, $this->employmentId]);
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
        // Místo „atributu dat původního přijatého podání" musí být vidět obě
        // konkrétní data.
        $wrongDateMessage = (string) $this->json($wrongDate)['error']['message'];
        self::assertStringStartsWith(
            'Datum původního opravovaného podání',
            $wrongDateMessage,
        );
        self::assertStringContainsString('2026-08-18', $wrongDateMessage);
        self::assertStringContainsString(self::TODAY, $wrongDateMessage);
        self::assertStringEndsWith('(effective_on)', $wrongDateMessage);

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
        // REGZEC25-client.bno-02: i oprava A4 nese u občana ČR rodné číslo.
        $correction = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($accepted)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $xml = $this->storedArtifactXml((int) $correction['submission_id']);
        self::assertStringContainsString(' act="4"', $xml);
        self::assertStringContainsString(' bno="9152031234"', $xml);
    }

    public function testA4RelationshipCorrectionFailsClosedWithoutExplanationAttachment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-08-16", actual_start_date = ?,
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute(['2026-08-16', $this->supplierId, $this->employmentId]);
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

    /**
     * REGZEC25-PROC.A1.prereg-combos-01 (d): přihláška A1 s předpokládaným
     * nástupem se při jiném skutečném nástupu opraví A4 na 10223, vždy
     * s průvodním dopisem v příloze.
     */
    public function testA4CorrectsTheActualStartWithExplanationAttachment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-08-16", actual_start_date = "2026-08-16",
                    status = "active"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-08-16');
        $sourceEvent = $this->json(($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a4-start-source',
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
        $correction = [
            'environment' => 'test',
            'interaction' => 'correction',
            'effective_on' => self::TODAY,
            'discovered_on' => self::TODAY,
            'source_reference' => 'synthetic-a4-actual-start',
            'source_submission_id' => $source['submission_id'],
            'corrections' => ['employment' => ['actual_start_on' => '2026-08-17']],
        ];

        $withoutLetter = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($correction),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $withoutLetter->getStatusCode(), (string) $withoutLetter->getBody());
        self::assertSame(
            'registration_a4_start_attachment_required',
            $this->json($withoutLetter)['error']['code'],
        );

        $otherField = $correction;
        $otherField['corrections'] = ['employment' => ['work_mode_code' => '1']];
        $otherField['source_reference'] = 'synthetic-a4-other-employment';
        $forbidden = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($otherField),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $forbidden->getStatusCode(), (string) $forbidden->getBody());

        $correction['explanation_attachment'] = [
            'name' => 'vysvetleni.pdf',
            'description' => 'Skutečný nástup',
            'data_base64' => base64_encode('%PDF-synthetic-explanation'),
        ];
        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($correction),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString(' act="4"', $xml);
        self::assertMatchesRegularExpression('/<job [^>]*fro="2026-08-17"/', $xml);
        self::assertStringContainsString('<attach name="vysvetleni.pdf"', $xml);
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
                'new_variable_symbol' => '1110000005',
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
            'registration_regzec_a1_profile_missing',
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

    /**
     * UI-23: karta po obnovení stránky nevěděla, že přihláška už čeká ve
     * frontě, a nabízela ji připravit znovu. Endpoint `current` vrací stav
     * existujícího podání; bez živé přihlášky vrací `null`.
     */
    public function testCurrentRegistrationReportsThePreparedFiling(): void
    {
        $empty = $this->json($this->current());
        self::assertArrayHasKey('submission', $empty);
        self::assertNull($empty['submission']);

        $prepared = $this->json($this->post());
        $current = $this->json($this->current())['submission'];

        self::assertIsArray($current);
        self::assertSame($prepared['submission_id'], $current['submission_id']);
        self::assertSame('PREZEC26', $current['agenda_code']);
        self::assertSame('ready', $current['status']);
        self::assertFalse($current['sent']);
    }

    /**
     * Změněná data po přípravě měla jiný otisk zdroje, takže idempotence
     * nezabrala a vedle připravené přihlášky vznikla druhá. U ČSSZ by šly
     * dvě přihlášky téhož vztahu.
     */
    public function testChangedDataDoNotCreateSecondRegistrationBesideThePreparedOne(): void
    {
        $first = $this->json($this->post());
        self::assertTrue($first['created']);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET last_name = ? WHERE id = ?',
        )->execute(['Novotná-Testová', $this->identityId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_already_prepared',
            $this->json($response)['error']['code'],
        );
        self::assertSame(1, $this->countSubmissions());
    }

    /**
     * Odeslaná přihláška blokuje druhou stejně, jen s jinou radou: změny se
     * hlásí změnovým, chyby opravným hlášením.
     */
    public function testSentRegistrationBlocksSecondRegistrationForTheSameEmployment(): void
    {
        $first = $this->json($this->post());
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = \'submitted\', submitted_at = NOW()
              WHERE id = ?',
        )->execute([$first['submission_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET last_name = ? WHERE id = ?',
        )->execute(['Novotná-Testová', $this->identityId]);

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_already_filed',
            $this->json($response)['error']['code'],
        );
        self::assertTrue($this->json($this->current())['submission']['sent']);
        self::assertSame(1, $this->countSubmissions());
    }

    /**
     * § 19 odst. 1 písm. a) zákona č. 323/2025 Sb.: přihlásit PŘED nástupem,
     * nejdřív osm dnů předem — a to i plnou registrací A1. Dřív šla A1 až po
     * nástupu (`full_registration_data = metadata && work_started`), takže
     * zaměstnavatel, který chtěl registraci vyřídit najednou, musel čekat
     * na den, kdy už byl v prodlení.
     */
    public function testCzechEmployeeBeforeStartCanFileFullRegistrationA1(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertSame('direct_full_registration', $body['interaction']);
        self::assertTrue($body['before_start_choice']);
        self::assertSame(self::START_ON, $body['deadline']['due_on']);
        self::assertSame('2026-08-14', $body['deadline']['earliest_registration_on']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="1"', $xml);
        self::assertStringContainsString(' fro="' . self::START_ON . '"', $xml);
    }

    /**
     * Název zaměstnavatele (10120) je podle zásad REGZEC „celý název dle
     * rejstříku a obec sídla"; Premier ho tak poslal ve všech 67 přijatých
     * větách, aplikace dřív jen název.
     */
    public function testFullRegistrationCarriesEmployerNameWithSeatMunicipality(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET company_name = ?, city = ? WHERE id = ?',
        )->execute(['Syntetická firma s.r.o.', 'Testov', $this->supplierId]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($response)['submission_id']);
        self::assertStringContainsString(' nam="Syntetická firma s.r.o., Testov"', $xml);
    }

    /**
     * Vztah s ID PPV od ČSSZ (převzatý z jiného programu, bez přihlášky
     * z aplikace) už je u ČSSZ přihlášený. Plnou A1 k němu ČSSZ odmítla
     * v zpětném převodu šestkrát (603/604 a duplicita); aplikace ji dřív
     * připravila a zařadila do fronty bez varování.
     */
    public function testFullRegistrationIsRefusedForEmploymentWithIdPpvFromCssz(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('registration_already_registered', $error['code']);
        self::assertStringContainsString('A3', (string) $error['message']);
        self::assertStringContainsString('A4', (string) $error['message']);
        self::assertSame(0, $this->countSubmissions());
    }

    /**
     * Protokol ČSSZ k přijaté A1 v tvaru, jaký vrací VREP (Item/@subtype
     * REGZEC25, sqnr = pořadí věty, identifier „RČ;OIČ;IDPPV"), projde
     * skutečným parserem i verifierem protokolů a z něj se převezme OIČ
     * a ID PPV vztahu jako `trusted_receipt`. Dřív parser takový protokol
     * odmítl a podání zůstalo navždy „odesláno".
     */
    public function testRegzecA1ProtocolFromVrepAssignsOicAndIdPpvAsTrustedReceipt(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');
        $prepared = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        self::assertSame('REGZEC25', $prepared['agenda_code']);
        $correlation = 'B0000000000000000000000000000001';
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            $correlation,
        );
        $protocol = '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails><Class>CSSZ_REGZEC</Class><Qualifier>response</Qualifier>'
            . '<Function>submit</Function><CorrelationID>' . $correlation . '</CorrelationID>'
            . '</MessageDetails></Header><GovTalkDetails><Keys /></GovTalkDetails><Body>'
            . '<Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="response"><Header /><Body>'
            . '<ProcessingResult type="CSSZ_REGZEC" version="1,0" result="OK" errMsg="" errNumber="0"'
            . ' count="1" countErr="0" countWar="0"><Details>'
            . '<Item sqnr="" identifier="" subtype="REGZEC25" period="" result="OK" errMsg="" errNum="" />'
            . '<Item sqnr="1" identifier="7001010001;1000000001;200000000000000000002" subtype="REGZEC25"'
            . ' period="" result="OK" errMsg="" errNum="" />'
            . '</Details></ProcessingResult></Body></Message></Body></GovTalkMessage>';
        $signatures = new class () implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };

        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            null,
            $protocol,
            $correlation,
            $correlation,
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            'synthetic-regzec-a1-protocol:' . $this->employmentId,
            $this->userId,
            new JmhzReceiptVerifier($signatures),
        );

        self::assertTrue($receipt['trusted']);
        self::assertSame('accepted', $receipt['submission_status']);
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::START_ON,
            true,
        );
        self::assertSame('trusted_receipt', $identity['person_external_identifier']['source_kind']);
        self::assertSame((int) $receipt['id'], $identity['person_external_identifier']['source_receipt_id']);
        self::assertSame('trusted_receipt', $identity['employment_external_identifier']['source_kind']);
        self::assertSame((int) $receipt['id'], $identity['employment_external_identifier']['source_receipt_id']);
    }

    /**
     * EDV 1.4.0.6, ID 10092 a 10099: u druhu činnosti "N" musí A1 nést
     * cizozemského nositele pojištění. Profil bez něj zůstane rozpracovaný,
     * s ním se uloží, vrátí a podání ho zapíše mezi `job` a `pens`.
     */
    public function testActivityNProfileKeepsForeignInsurerAndFilesItInTheRegistration(): void
    {
        $this->seedRegistrationEventPrerequisites('N', '1', self::START_ON, null, null, true);
        $payload = $this->completeA1Payload();
        $payload['employment']['activity_code'] = 'N';
        $payload['employment']['relationship_detail_code'] = '1';

        $draft = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $draft->getStatusCode(), (string) $draft->getBody());
        $draftProfile = $this->json($draft)['profile'];
        self::assertSame('draft', $draftProfile['status']);
        self::assertContains(
            'foreign_insurance.current',
            array_column($draftProfile['problems'], 'field'),
        );

        $payload['row_version'] = $draftProfile['row_version'];
        $payload['foreign_insurance'] = [
            'current' => 'S',
            'name' => 'Syntetická pojišťovna',
            'street' => null,
            'house_number' => null,
            'orientation_number' => null,
            'postal_code' => null,
            'city' => null,
            'country_code' => 'DE',
            'identifier' => 'SYN-1',
            'sector' => null,
        ];
        $saved = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $profile = $this->json($saved)['profile'];
        self::assertSame(
            'verified',
            $profile['status'],
            json_encode($profile['problems'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        );
        self::assertSame('S', $profile['foreign_insurance']['current']);
        self::assertSame('DE', $profile['foreign_insurance']['country_code']);

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($response)['submission_id']);
        self::assertStringContainsString('<forin cur="S" nam="Syntetická pojišťovna" cnt="DE" id="SYN-1"/>', $xml);
        self::assertLessThan(strpos($xml, '<pens'), strpos($xml, '<forin'));
    }

    /**
     * Cizinec se přihlašuje VŽDY plnou registrací a VŽDY před nástupem.
     * Hláška validátoru mu to radila, přitom podání A1 před nástupem padalo
     * na „nemá vyplněné všechny povinné údaje".
     */
    public function testForeignEmployeeBeforeStartFilesRegzecA1WithoutChoosing(): void
    {
        $this->identities->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            2,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'SK',
                'citizenship_country_code' => 'SK',
                'sex' => 'female',
            ],
        );
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $payload = $this->completeA1Payload();
        // EDV 1.4.0.6 (ID 10071, 10526, 10248): u cizince povinné.
        $payload['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $payload['employment']['required_education_code'] = 'T';
        $payload['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => 'SK',
        ];
        $payload['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];
        $saved = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(
            'verified',
            $this->json($saved)['profile']['status'],
            json_encode($this->json($saved)['profile']['problems'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        );

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertFalse($body['before_start_choice']);
        self::assertSame(self::START_ON, $body['deadline']['due_on']);
    }

    /**
     * Storno A8 z jiného důvodu než nenastoupení (tady chybný variabilní
     * symbol): příloha se zdůvodněním jde do podání a lhůta NENÍ zákonná.
     * Dřív aplikace takové storno odmítla a každé A8 vedla s osmi dny.
     */
    public function testA8ForOtherReasonCarriesExplanationAndHasNoStatutoryDeadline(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');
        $a1 = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $this->markRegistrationAccepted((int) $a1['submission_id']);
        $this->assignIdentityFromAcceptedRegistration();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::TODAY, self::TODAY, $this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([self::TODAY, $this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_external_ids SET valid_from = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([self::TODAY, $this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_external_ids SET valid_from = ?
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([self::TODAY, $this->supplierId, $this->employeeId]);

        $missing = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'cancellation',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a8-wrong-vs',
                'source_submission_id' => $a1['submission_id'],
                'not_started' => false,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertSame(
            'registration_a8_explanation_attachment_required',
            $this->json($missing)['error']['code'],
        );

        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'cancellation',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a8-wrong-vs',
                'source_submission_id' => $a1['submission_id'],
                'not_started' => false,
                'explanation_attachment' => [
                    'name' => 'zduvodneni-storna.pdf',
                    'description' => 'Přihlášeno pod chybným variabilním symbolem',
                    'data_base64' => base64_encode('%PDF-synthetic-explanation'),
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $body = $this->json($prepared);
        self::assertFalse($body['deadline']['statutory']);
        self::assertSame('2026-09-20', $body['deadline']['due_on']);
        self::assertSame('cz-regzec-cancellation-other-2026-07.v1', $body['deadline']['ruleset_id']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="8"', $xml);
        self::assertStringContainsString('notstart="N"', $xml);
        self::assertStringContainsString('name="zduvodneni-storna.pdf"', $xml);
    }

    /** Dřív než osm dnů před nástupem A1 nejde — stejně jako P1. */
    public function testFullRegistrationA1IsRefusedMoreThanEightDaysBeforeStart(): void
    {
        $startOn = '2026-09-01';
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$startOn, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', $startOn, null, null, true);
        $this->saveA1ProfileFor($startOn, '1', '1');

        $response = ($this->action)->preview(
            $this->request('GET')->withQueryParams([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('registration_regzec_a1_start_window_invalid', $error['code']);
        self::assertStringContainsString('24.08.2026', $error['message']);
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
            'registration_regzec_a1_profile_missing',
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
        self::assertSame('registration_data_incomplete', $error['code']);
        // Věta jmenuje údaj lidsky a technický název do ní nepatří; kam jít,
        // nese položka seznamu (`target`), podle které klient nabídne proklik.
        self::assertStringContainsString(
            'variabilní symbol zaměstnavatele u ČSSZ',
            $error['message'],
        );
        self::assertStringNotContainsString('(employer_variable_symbol)', $error['message']);
        self::assertSame([[
            'field' => 'employer_variable_symbol',
            'label' => 'Variabilní symbol zaměstnavatele u ČSSZ',
            'message' => 'Variabilní symbol zaměstnavatele u ČSSZ chybí u mzdové '
                . 'účtárny, pod kterou pracovní vztah patří, a bez něj ČSSZ neví, '
                . 'komu zaměstnance přihlásit. Doplňte v Mzdy → Nastavení mezd '
                . '→ Zaměstnavatel a účtárny.',
            'panel' => null,
            'target' => 'employer_settings',
        ]], $error['problems']);
        self::assertSame(0, $this->countSubmissions());
    }

    /**
     * Chybějící údaje se hlásí VŠECHNY NAJEDNOU a každý s adresou pole.
     *
     * Dřív příprava spadla na prvním z nich (občanství), po doplnění na dalším
     * (rodné příjmení s technickým `(birth_surname)` v textu) a tak dál —
     * účetní chodila na kartu osoby pro každý údaj zvlášť.
     */
    public function testIncompleteRegistrationListsEveryMissingItemAtOnce(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET citizenship_country_code = NULL, birth_place = NULL,
                    birth_surname = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->identityId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET social_security_variable_symbol = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);

        $response = ($this->action)->preview(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('registration_data_incomplete', $error['code']);
        self::assertSame(
            [
                'identity.birth_surname',
                'identity.birth_place',
                'identity.citizenship_country_code',
                'employer_variable_symbol',
            ],
            array_column($error['problems'], 'field'),
        );
        self::assertSame(
            ['registration_identity', 'registration_identity', 'registration_identity', null],
            array_column($error['problems'], 'panel'),
        );
        foreach ($error['problems'] as $problem) {
            self::assertDoesNotMatchRegularExpression('/\([a-z_.]+\)/', $problem['message']);
        }
        self::assertDoesNotMatchRegularExpression('/\([a-z_.]+\)/', $error['message']);
        self::assertStringContainsString('rodné příjmení', $error['message']);
        self::assertStringContainsString('státní občanství', $error['message']);
    }

    /**
     * Ukázkový variabilní symbol projde formální kontrolou, ale podání by
     * odešlo pod cizím zaměstnavatelem. Náhled na to upozorní, neblokuje.
     */
    public function testPlaceholderVariableSymbolIsWarnedInPreview(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET social_security_variable_symbol = "12345678"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);

        $response = ($this->action)->preview(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertStringContainsString('vs="12345678"', $body['xml']);
        self::assertSame('employer_variable_symbol_placeholder', $body['warnings'][0]['code']);
        self::assertSame('employer_settings', $body['warnings'][0]['target']);

        $prepared = $this->json($this->post());
        self::assertContains(
            'employer_variable_symbol_placeholder',
            array_column($prepared['problems'], 'code'),
        );
    }

    /**
     * Zkušební podání do TEST ČSSZ (8. 10. 2026): registrace odcházela pod
     * ostrým VS firmy, přestože účtárna má pro test přidělený vlastní. Výběr
     * podle prostředí je teď stejný jako u JMHZ ({@see \MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol}).
     */
    public function testTestEnvironmentRegistrationUsesTheOfficeTestVariableSymbol(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET test_social_security_variable_symbol = "2210000002"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);

        $test = $this->registrationService->preview(
            $this->supplierId,
            'test',
            $this->employmentId,
        );
        $production = $this->registrationService->preview(
            $this->supplierId,
            'production',
            $this->employmentId,
        );
        $prepared = $this->json($this->post());

        self::assertStringContainsString('vs="2210000002"', $test['xml']);
        self::assertStringNotContainsString('vs="1100000007"', $test['xml']);
        self::assertStringContainsString('vs="1100000007"', $production['xml']);
        self::assertStringContainsString(
            'vs="2210000002"',
            $this->storedArtifactXml((int) $prepared['submission_id']),
        );
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
        self::assertSame(
            'Datum nástupu u pracovního vztahu chybí, takže nejde spočítat '
            . 'lhůta pro přihlášku ani přihlášku podat. Údaj doplňte na '
            . 'kartě pracovního vztahu — v tomhle formuláři se nezadává. '
            . 'Registraci potom připravte znovu (start_date).',
            $error['message'],
        );
    }

    /**
     * Povinnost přihlásit ZAMĚSTNAVATELE je povinnost někoho jiného, vedená
     * jen při té příležitosti. Když ji registr povinností odmítne, nesmí tím
     * spadnout přihláška ZAMĚSTNANCE, u které běží zákonná lhůta — nedostatek
     * se vypíše v `problems` a podání se dokončí.
     */
    public function testRejectedEmployerObligationDoesNotBlockTheEmployeeRegistration(): void
    {
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-employer-registration-obligation.v1',
            'first_employment_id' => $this->employmentId,
            'expected_start_on' => self::START_ON,
        ]));
        // Tentýž idempotentní klíč, ale jiný otisk vstupů: registr povinností
        // to odmítne s `\DomainException`. Lhůta k povinnosti patří, protože
        // bez ní registr existující záznam vůbec nenajde.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, "test", "REGZEL26", "employer", ?, ?, ?, "regular",
                     "other", "open", "seed", "seed:1", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            'payroll_employer:' . $this->supplierId,
            self::START_ON,
            self::START_ON,
            str_repeat('a', 64),
            str_repeat('f', 64),
            hash('sha256', 'employer-registration:test:' . $sourceHash, true),
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis, ruleset_id,
                 ruleset_hash, trigger_event_hash)
             VALUES (?, "test", ?, "regular", ?, ?, "business_days", "seed",
                     ?, ?)',
        )->execute([
            $this->supplierId,
            (int) $pdo->lastInsertId(),
            self::START_ON,
            self::START_ON,
            str_repeat('a', 64),
            str_repeat('a', 64),
        ]);

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('ready', $body['status']);
        self::assertTrue($body['created']);
        self::assertSame(1, $this->countSubmissions());
        self::assertCount(1, $body['problems']);
        self::assertSame(
            'registration_employer_obligation_not_recorded',
            $body['problems'][0]['code'],
        );
        self::assertSame('employer_registration', $body['problems'][0]['field']);
        self::assertStringContainsString(
            'Přihlášku zaměstnance to nezastavilo',
            $body['problems'][0]['message'],
        );
    }

    /** Když všechno sedne, seznam nedostatků je prázdný, ne chybějící. */
    public function testSuccessfulRegistrationReportsNoProblems(): void
    {
        $body = $this->json($this->post());

        self::assertSame([], $body['problems']);
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
        self::assertSame([], $body['warnings']);
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

    /**
     * Profil je snímek k datu registrace, ne editor karty osoby. Uložení proto
     * smí sáhnout jedině do payroll_registration_a1_profiles — kdyby zapsalo
     * zpátky do kmenových dat, ztratila by se doložitelnost stavu, ke kterému
     * se registrace hlásila.
     */
    public function testSavingA1ProfileNeverWritesIntoPersonMasterData(): void
    {
        $this->seedA1MasterData();
        $before = $this->masterDataFingerprint();

        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode());
        $profile = $this->json($response)['profile'];
        self::assertSame(1, $profile['row_version']);
        self::assertTrue($profile['created']);
        self::assertSame($before, $this->masterDataFingerprint());

        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(1, (int) $statement->fetchColumn());

        /*
         * Druhé uložení PŘEPÍŠE pracovní řádek, nezakládá verzi: dokud
         * registrace neodešla, není profil evidence, ale rozepsaná práce.
         * `row_version` běží dál jen jako optimistický zámek. Kmenová data
         * zůstávají netknutá i tak — zapisuje se do nich výhradně samostatnou
         * akcí `writeA1MasterData`.
         */
        $second = $this->completeA1Payload();
        $second['row_version'] = 1;
        $second['employment']['position_name'] = 'Vedoucí účtárny';
        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($second),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->json($response)['profile']['row_version']);
        self::assertSame($before, $this->masterDataFingerprint());
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testA1DraftPrefillsFromMasterDataAndNamesTheGaps(): void
    {
        $this->seedA1MasterData();

        $response = ($this->action)->a1Profile(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertNull($body['profile']);
        $draft = $body['draft'];
        self::assertSame('OST', $draft['variant']);
        self::assertSame(self::START_ON, $draft['effective_on']);
        self::assertSame('Praha', $draft['suggested']['permanent_address']['city']);
        self::assertSame('111', $draft['suggested']['health_insurance_code']);
        self::assertSame('CZ', $draft['suggested']['tax_residency']['country_code']);
        self::assertSame('1', $draft['suggested']['employment']['activity_code']);
        self::assertArrayHasKey('permanent_address.city', $draft['sources']);

        $missing = array_column($draft['missing'], 'field');
        self::assertContains('permanent_address.house_number', $missing);
        self::assertContains('employment.position_name', $missing);
        foreach ($draft['missing'] as $gap) {
            self::assertNotSame('', trim((string) $gap['message']));
        }
    }

    /**
     * Uložení musí projít i s prázdným povinným polem. Účetní jinak nechá
     * hodinu práce v prohlížeči, než se vrátí chybějící údaj doplnit.
     */
    public function testIncompleteA1ProfileIsSavedAsDraftAndNamesTheGaps(): void
    {
        $this->seedA1MasterData();
        $payload = $this->completeA1Payload();
        $payload['facts']['highest_education_code'] = null;
        $payload['employment']['work_mode_code'] = null;

        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $profile = $this->json($response)['profile'];
        self::assertSame('draft', $profile['status']);
        self::assertSame(1, $profile['row_version']);
        $fields = array_column($profile['problems'], 'field');
        self::assertContains('facts.highest_education_code', $fields);
        self::assertContains('employment.work_mode_code', $fields);
        foreach ($profile['problems'] as $problem) {
            self::assertNotSame('', trim((string) $problem['message']));
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(['draft'], $statement->fetchAll(PDO::FETCH_COLUMN));

        // Vyplněná část se opravdu uložila, ne jen „přijala".
        $stored = $this->json(($this->action)->a1Profile(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ))['profile'];
        self::assertSame('draft', $stored['status']);
        self::assertSame('Účetní', $stored['employment']['position_name']);
        self::assertSame('Praha', $stored['permanent_address']['city']);
        self::assertNull($stored['facts']['highest_education_code']);
    }

    /**
     * Scénář V1 + K1: nástup 15. 2. 2026, přihláška REGZEC A1 z aplikace.
     *
     * Pravidla pro REGZEC: událost do 31. 3. 2026 neohlášená do té doby se od
     * 1. 4. 2026 hlásí jen přes REGZEC. Podání se nesmí blokovat, lhůta se jen
     * neodvozuje. Postavení v zaměstnání jde jako čtyřmístný kód NKPZ.
     */
    public function testFebruaryStartA1IsPreparedWithFourDigitStatusAndLateNotice(): void
    {
        $this->startExistingEmployment('2026-02-15', '1', '1', null, false);
        $this->saveA1ProfileFor('2026-02-15', '1', '1');

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('REGZEC25', $body['agenda_code']);
        self::assertSame('direct_full_registration', $body['interaction']);
        self::assertFalse($body['deadline']['derived']);
        self::assertSame('2026-02-15', $body['deadline']['due_on']);
        self::assertStringContainsString('1. 7. 2026', (string) $body['deadline']['notice']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        self::assertStringContainsString('act="1"', $xml);
        self::assertStringContainsString(' fro="2026-02-15"', $xml);
        self::assertStringContainsString(' relat="1111"', $xml);
        self::assertStringContainsString(' relDetail="1"', $xml);
        self::assertStringNotContainsString(' place=', $xml);
    }

    /**
     * Scénář V2: zaměstnanec přihlášený dřív přes ONZ (OIČ a ID PPV zapsané
     * ručně) → dohlášení celého profilu akcí A3 → přijetí ČSSZ číslo potvrdí
     * a odhláška A2 ho pak přijme.
     */
    public function testOnzEmployeeFullCompletionIsPreparedAndConfirmsIdentifiersForA2(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET city = ? WHERE id = ?')
            ->execute(['Testov', $this->supplierId]);
        $this->startExistingEmployment('2026-02-15', '1', '1', null, true);
        $this->saveA1ProfileFor('2026-02-15', '1', '1');
        try {
            $this->identities->sensitiveJmhzIdentityAt(
                $this->supplierId,
                $this->employeeId,
                $this->employmentId,
                'test',
                self::TODAY,
                true,
            );
            self::fail('Ručně zapsané OIČ nesmí A2 bez potvrzení od ČSSZ projít.');
        } catch (\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException $exception) {
            self::assertSame('registration_a2_oic_provenance_invalid', $exception->validationCode);
            self::assertStringContainsString('dohlášení údajů', $exception->getMessage());
        }

        $event = $this->approveCompletion('full');
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
        self::assertSame('change', $body['interaction']);
        $xml = $this->storedArtifactXml((int) $body['submission_id']);
        foreach ([
            'act="3"',
            ' fro="' . self::TODAY . '"',
            ' bno="9152031234"',
            ' ikmpsv="1000000001"',
            ' oid="200000000000000000002"',
            ' relat="1111"',
            ' workmode="1"',
            ' cont="N"',
            ' highedu="T"',
            '<adr ',
            '<taxidrezid stat="CZ" statchang="2026-02-15"/>',
            '<insh cnr="111"/>',
        ] as $expected) {
            self::assertStringContainsString($expected, $xml, $expected);
        }
        // Událost zmrazí název zaměstnavatele stejným pravidlem jako A1 (10120).
        $supplier = $this->db->pdo()->prepare('SELECT company_name, city FROM supplier WHERE id = ?');
        $supplier->execute([$this->supplierId]);
        $employer = $supplier->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($employer);
        self::assertStringEndsWith(', Testov', PayrollRegistrationEmployerName::forSubmission(
            (string) $employer['company_name'],
            'Testov',
        ));
        self::assertStringContainsString(
            ' nam="' . htmlspecialchars(
                PayrollRegistrationEmployerName::forSubmission(
                    (string) $employer['company_name'],
                    $employer['city'] === null ? null : (string) $employer['city'],
                ),
                ENT_XML1 | ENT_QUOTES,
            ) . '"',
            $xml,
        );

        $this->acceptWithTrustedReceipt($body);
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'test',
            self::TODAY,
            true,
        );
        self::assertSame('1000000001', $identity['person_external_identifier']['value']);
    }

    /**
     * Dohlášení za už skončený vztah (MPSV, 28. 4. 2026): údaje ke dni
     * skončení, v podání i datum skončení; 10009 = den odeslání.
     */
    public function testEndedOnzEmployeeMinimalCompletionCarriesEndDate(): void
    {
        $this->startExistingEmployment('2026-02-15', '1', '1', '2026-06-30', true);
        $this->saveA1ProfileFor('2026-02-15', '1', '1');

        $event = $this->approveCompletion('minimal');
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString(' fro="' . self::TODAY . '"', $xml);
        self::assertStringContainsString(' to="2026-06-30"', $xml);
        self::assertStringContainsString(' relat="1111"', $xml);
        self::assertStringNotContainsString('<adr ', $xml);
    }

    /**
     * Po převodu z cizího programu evidence často nemá rodné příjmení, místo
     * ani stát narození. A3 je do věty nepřenáší, takže dohlášení nesmí
     * stát na údajích, které nikdy neodejdou.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('completionModes')]
    public function testCompletionIsNotBlockedByBirthDataItDoesNotCarry(string $mode): void
    {
        $this->startExistingEmployment('2026-02-15', '1', '1', null, true);
        $this->saveA1ProfileFor('2026-02-15', '1', '1');
        $this->clearBirthData();

        $event = $this->approveCompletion($mode);
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $event['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), $mode . ': ' . (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString('act="3"', $xml, $mode);
        self::assertDoesNotMatchRegularExpression('/<birth [^>]*(nam|cit|stat)=/', $xml, $mode);
    }

    /** @return iterable<string,array{0:string}> */
    public static function completionModes(): iterable
    {
        yield 'celý profil' => ['full'];
        yield 'jen údaje, které ONZ nevedla' => ['minimal'];
    }

    /** Odhláška A2 nese jen IK MPSV, takže rodné příjmení a místo narození nechce. */
    public function testTerminationIsNotBlockedByBirthDataItDoesNotCarry(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('10', null, self::START_ON, null, null, true);
        $this->clearBirthData();

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
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($eventResponse)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString('act="2"', $xml);
        self::assertStringNotContainsString('<birth ', $xml);
    }

    private function clearBirthData(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET birth_surname = NULL, birth_place = NULL,
                    birth_country_code = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->identityId]);
    }

    /** Hromadné dohlášení: kandidáti, připravené podání a vada u koho a proč. */
    public function testBatchCompletionPreparesReadyProfilesAndNamesTheRest(): void
    {
        $this->startExistingEmployment('2026-02-15', '1', '1', null, true);
        $this->saveA1ProfileFor('2026-02-15', '1', '1');
        $second = $this->insertAdditionalEmployment('reg-second');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-03-01", actual_start_date = "2026-03-01",
                    status = "active", relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $second]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, activity_code)
             VALUES (?, ?, ?, "2026-03-01", "2026-03-01", "2026-03-01", "T")',
        )->execute([$this->supplierId, $second, $this->officeId]);
        $this->identities->assignEmploymentExternalId(
            $this->supplierId,
            $second,
            'test',
            '200000000000000000003',
            '2026-03-01',
            'verified_manual_import',
            'synthetic-second-id-ppv',
            null,
            $this->userId,
        );
        $service = new \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCompletionService(
            new \MyInvoice\Repository\Payroll\PayrollRegistrationCompletionRepository($this->db),
            $this->registrationService,
            $this->clock,
        );

        $candidates = $service->candidates($this->supplierId, 'test');
        $byEmployment = array_column($candidates['items'], null, 'employment_id');
        self::assertSame('verified', $byEmployment[$this->employmentId]['profile_status'] ?? null);
        self::assertArrayHasKey($second, $byEmployment);
        self::assertNull($byEmployment[$second]['profile_status']);
        self::assertSame(self::TODAY, $candidates['today']);

        $result = $service->complete(
            $this->supplierId,
            'test',
            [$this->employmentId, $second],
            'minimal',
            null,
            $this->userId,
        );
        $results = array_column($result['results'], null, 'employment_id');
        self::assertSame('prepared', $results[$this->employmentId]['status']);
        self::assertSame('failed', $results[$second]['status']);
        self::assertSame(
            'registration_a3_completion_profile_missing',
            $results[$second]['code'],
        );
        self::assertStringContainsString('profil A1', (string) $results[$second]['message']);

        $after = array_column(
            $service->candidates($this->supplierId, 'test')['items'],
            null,
            'employment_id',
        );
        self::assertSame('ready', $after[$this->employmentId]['completion_submission_status']);
    }

    /**
     * S2: odhláška DPP nese `relDetail="1"` (Premier i PAMICA, přijato),
     * i když evidence u dohod bližší určení nevede. Dřív schválení padlo.
     */
    public function testDppTerminationIsApprovedWithRelationshipDetailOne(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended", relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('T', null, self::START_ON, null, null, true);

        $eventResponse = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
                'ended_by_death' => false,
                'unemployment' => ['mode' => 'not_provided_2'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $eventResponse->getStatusCode(), (string) $eventResponse->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($eventResponse)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString(' rel="T"', $xml);
        self::assertStringContainsString(' relDetail="1"', $xml);
    }

    /**
     * MPSV 14. 8. 2026: DIS od 13. 8. nepřijímá REGZEC s „odstupné náleží"
     * (10378), je-li důvod skončení (10380) jiný než 4 nebo 5. Aplikace to
     * odmítne už při schválení odhlášky; u důvodu 4 nárok projde.
     */
    public function testA2SettlementIsAcceptedOnlyForTerminationReasonFourOrFive(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25", status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $unemployment = static fn (string $reason): array => [
            'mode' => 'provided',
            'average_net_earnings' => '25000',
            'pension_periods' => [['from' => self::START_ON, 'to' => '2026-08-25']],
            'employment_type' => '1',
            'termination_reason' => $reason,
            'entitlement' => true,
            'paid_in_full' => true,
            'golden_handshake' => '50000',
        ];
        $approve = fn (string $reason) => ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
                'ended_by_death' => false,
                'unemployment' => $unemployment($reason),
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        $rejected = $approve('3');
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(
            'registration_a2_settlement_forbidden',
            $this->json($rejected)['error']['code'],
        );
        self::assertStringContainsString('4 nebo 5', $this->json($rejected)['error']['message']);

        $accepted = $approve('4');
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
    }

    /**
     * A2 nese 10378 (odstupné náleží) a 10531 (odstupné § 67 odst. 1) podle
     * záznamu Skončení vztahu. Skončení z organizačních důvodů odstupné
     * zakládá — odhláška, která ho zamlčí, se neschválí.
     */
    public function testA2SettlementMustMatchTheTerminationRecord(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25", status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $termination = Bootstrap::buildContainer()->get(
            \MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService::class,
        );
        $termination->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'organizational',
        ], $this->userId);
        $approve = fn (array $settlement) => ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
                'ended_by_death' => false,
                'unemployment' => [
                    'mode' => 'provided',
                    'average_net_earnings' => '25000',
                    'pension_periods' => [['from' => self::START_ON, 'to' => '2026-08-25']],
                    'employment_type' => '1',
                    'termination_reason' => '4',
                ] + $settlement,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        $silenced = $approve(['entitlement' => false]);
        self::assertSame(422, $silenced->getStatusCode(), (string) $silenced->getBody());
        self::assertSame(
            'registration_a2_settlement_record_mismatch',
            $this->json($silenced)['error']['code'],
        );
        $wrongKind = $approve(['entitlement' => true, 'paid_in_full' => true, 'replacement' => '50000']);
        self::assertSame(
            'registration_a2_settlement_record_mismatch',
            $this->json($wrongKind)['error']['code'],
        );

        $accepted = $approve(['entitlement' => true, 'paid_in_full' => true, 'golden_handshake' => '50000']);
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($accepted)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString('belong="A"', $xml);
        self::assertStringContainsString('goldenhandshake="50000"', $xml);
    }

    /**
     * Předkontrola chyb ČSSZ 603/604: další vztah téže osoby se stejným
     * druhem činnosti a ZMR, který se časově překrývá. Varování, ne zákaz.
     */
    public function testOverlappingSameActivityEmploymentIsWarnedBeforeFiling(): void
    {
        $this->startExistingEmployment('2026-02-15', '1', '1', null, false);
        $other = $this->insertAdditionalEmployment('reg-overlap');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-01-01", actual_start_date = "2026-01-01",
                    status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $other]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, activity_code,
                 jmhz_relationship_detail_code)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01", "1", "1")',
        )->execute([$this->supplierId, $other, $this->officeId]);

        $view = $this->json(($this->action)->a1Profile(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));

        self::assertCount(1, $view['warnings'] ?? []);
        self::assertSame('registration_overlap_same_activity', $view['warnings'][0]['code']);
        self::assertSame($other, $view['warnings'][0]['employment_id']);
        self::assertStringContainsString('603', $view['warnings'][0]['message']);

        // Jiný druh činnosti u druhého vztahu = navazující PPV bez kolize.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET activity_code = "2"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $other]);
        $clean = $this->json(($this->action)->a1Profile(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        self::assertSame([], $clean['warnings']);
    }

    private function startExistingEmployment(
        string $startOn,
        string $activity,
        ?string $detail,
        ?string $endOn,
        bool $withManualIdentity,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, end_date = ?,
                    status = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $startOn,
            $startOn,
            $endOn,
            $endOn === null ? 'active' : 'ended',
            $this->supplierId,
            $this->employmentId,
        ]);
        $this->seedRegistrationEventPrerequisites(
            $activity,
            $detail,
            $startOn,
            null,
            null,
            !$withManualIdentity,
        );
    }

    private function saveA1ProfileFor(string $startOn, string $activity, ?string $detail): void
    {
        $payload = $this->completeA1Payload();
        $payload['effective_on'] = $startOn;
        $payload['employment']['actual_start_on'] = $startOn;
        $payload['employment']['contract_start_on'] = $startOn;
        $payload['employment']['activity_code'] = $activity;
        $payload['employment']['relationship_detail_code'] = $detail;
        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'verified',
            $this->json($response)['profile']['status'],
            json_encode($this->json($response)['profile']['problems'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /** @return array<string,mixed> */
    private function approveCompletion(string $mode): array
    {
        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => self::TODAY,
                'source_reference' => 'dohlaseni:' . $mode . ':' . self::TODAY,
                'completion' => $mode,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @param array<string,mixed> $prepared */
    private function acceptWithTrustedReceipt(array $prepared): void
    {
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $correlation = 'synthetic-a3-correlation:' . $this->employmentId;
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            $correlation,
        );
        $partId = (int) $prepared['part_id'];
        $verifier = new class ($partId) implements PayrollReceiptVerifierInterface {
            public function __construct(private readonly int $partId) {}

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
                    [],
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            $partId,
            '<synthetic-a3-receipt/>',
            'synthetic-a3-receipt:' . $this->employmentId,
            $correlation,
            'CSSZ_REGZEC',
            'accepted',
            'vrep_apep',
            'synthetic-a3-key:' . $this->employmentId,
            $this->userId,
            $verifier,
        );
        self::assertTrue($receipt['trusted']);
    }

    /** Kontrola pojmenuje vady, ale nic nezaloží. */
    public function testA1ProfileCheckNamesGapsWithoutSaving(): void
    {
        $this->seedA1MasterData();
        $payload = $this->completeA1Payload();
        $payload['facts']['highest_education_code'] = null;
        $payload['permanent_address']['house_number'] = null;

        $response = ($this->action)->checkA1Profile(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertFalse($body['complete']);
        $fields = array_column($body['problems'], 'field');
        self::assertContains('facts.highest_education_code', $fields);
        self::assertContains('permanent_address.house_number', $fields);

        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(0, (int) $statement->fetchColumn());

        $complete = ($this->action)->checkA1Profile(
            $this->request('POST')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertTrue($this->json($complete)['complete']);
        self::assertSame([], $this->json($complete)['problems']);
    }

    /**
     * Chybějící evidence identity nesmí shodit uložení. Dokud tahle výjimka
     * létala ven, přišla účetní o všechno, co ve formuláři napsala, protože
     * identitu dopisuje jinde na kartě osoby.
     */
    public function testMissingIdentityHistorySavesA1ProfileAsDraftInstead(): void
    {
        $this->seedA1MasterData();
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([$this->supplierId, $this->employeeId]);

        $response = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $profile = $this->json($response)['profile'];
        self::assertSame('draft', $profile['status']);
        self::assertSame([[
            'field' => 'identity',
            'code' => 'registration_regzec_a1_identity_missing',
            'message' => 'Evidence identity osoby k rozhodnému dni chybí, '
                . 'takže registrace nemá odkud vzít jméno ani údaje '
                . 'o narození. Údaj doplňte na kartě osoby → Identita '
                . 'a adresy → Historie jména — v tomhle formuláři '
                . 'se nezadává.',
        ]], $profile['problems']);

        // Rozepsaná práce se opravdu uložila, ne jen „přijala".
        $stored = $this->json(($this->action)->a1Profile(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ))['profile'];
        self::assertSame('draft', $stored['status']);
        self::assertSame('Účetní', $stored['employment']['position_name']);
    }

    /**
     * Kontrola musí vypsat i chybějící datum nástupu jako vadu. Výjimka
     * shodila celé tlačítko a účetní neuviděla ani jednu z ostatních vad.
     */
    public function testMissingStartDateIsListedByA1CheckInsteadOfFailing(): void
    {
        $this->seedA1MasterData();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = NULL, actual_start_date = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = ($this->action)->checkA1Profile(
            $this->request('POST')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertFalse($body['complete']);
        self::assertSame([[
            'field' => 'employment.actual_start_on',
            'code' => 'registration_regzec_a1_start_date_missing',
            'message' => 'Skutečné datum nástupu chybí. Registrace se '
                . 'zmrazuje právě k tomuhle dni, takže bez data nástupu '
                . 'kontrolu dokončit nejde. Údaj doplňte na kartě pracovního '
                . 'vztahu — v tomhle formuláři se nezadává.',
        ]], $body['problems']);
    }

    /** Úplnost se vynucuje až tam, kde z profilu vzniká podání. */
    public function testPreparingSubmissionRefusesADraftProfile(): void
    {
        $this->seedA1MasterData();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $payload = $this->completeA1Payload();
        $payload['facts']['highest_education_code'] = null;
        ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame(
            'registration_regzec_a1_required_field_missing',
            $error['code'],
        );
        self::assertStringContainsString(
            'facts.highest_education_code',
            $error['message'],
        );
        self::assertSame(0, $this->countSubmissions());
    }

    /**
     * Dokud registrace NEODEŠLA, je profil rozepsaná práce, ne evidence —
     * uložení proto pracovní řádek PŘEPÍŠE. `status = 'verified'` na tom nic
     * nemění: znamená jen „prošlo přísnou kontrolou", ne „odesláno".
     */
    public function testUnsubmittedProfileIsReplacedInsteadOfVersioned(): void
    {
        $this->seedA1MasterData();
        $verified = $this->json(($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ))['profile'];
        self::assertSame('verified', $verified['status']);

        $incomplete = $this->completeA1Payload();
        $incomplete['row_version'] = 1;
        $incomplete['employment']['position_name'] = null;
        $draft = $this->json(($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($incomplete),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ))['profile'];
        self::assertSame('draft', $draft['status']);
        // Číslo běží dál jen jako optimistický zámek, ne jako verze historie.
        self::assertSame(2, $draft['row_version']);

        $rows = $this->storedA1Rows();
        self::assertCount(1, $rows);
        self::assertSame('draft', $rows[0]['status']);
        self::assertNotSame($verified['reference_hash'], $rows[0]['reference_hash']);
    }

    /**
     * Jakmile registrace odešla, podklad se drží: řádek, ze kterého mohl
     * vzniknout odeslaný snímek, se nesmaže a nová práce vzniká nad ním.
     */
    public function testSubmittedRegistrationKeepsItsProfileRow(): void
    {
        $this->seedA1MasterData();
        self::assertSame(201, $this->post()->getStatusCode());
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "submitted", submitted_at = ?
              WHERE supplier_id = ?',
        )->execute([self::todayAtNoon(), $this->supplierId]);

        $first = $this->json(($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($this->completeA1Payload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ))['profile'];
        self::assertSame(1, $first['row_version']);

        $second = $this->completeA1Payload();
        $second['row_version'] = 1;
        $second['employment']['position_name'] = 'Vedoucí účtárny';
        ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($second),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        $rows = $this->storedA1Rows();
        self::assertCount(2, $rows);
        self::assertSame($first['reference_hash'], $rows[0]['reference_hash']);
    }

    /**
     * Opačný směr než „Vrátit návrh z kmenových dat": hodnota z formuláře jde
     * zpátky do evidence osoby a vztahu. Historizovaná evidence se přitom
     * nepřepisuje — vzniká nová verze účinná od rozhodného dne registrace.
     */
    public function testMasterDataWriteBackVersionsTheAddressAndCorrectsTheTerms(): void
    {
        $this->seedA1MasterData();
        $payload = $this->completeA1Payload();
        $payload['permanent_address']['city'] = 'Brno';
        $payload['employment']['contract_workplace'] = 'Hlavní město Praha';
        ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        $response = ($this->action)->writeA1MasterData(
            $this->request('POST')->withParsedBody(['fields' => [
                'permanent_address.city',
                'employment.contract_workplace',
                // Kmen tenhle údaj nevede jako samostatný sloupec.
                'employment.small_scale',
                // Shodná hodnota — idempotence, nic se nezapisuje.
                'employment.activity_code',
            ]]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame(
            ['employment.contract_workplace', 'permanent_address.city'],
            array_column($body['written'], 'field'),
        );
        self::assertSame(
            ['employment.activity_code', 'employment.small_scale'],
            array_column($body['skipped'], 'field'),
        );
        foreach ($body['skipped'] as $item) {
            self::assertNotSame('', trim((string) $item['reason']));
        }
        self::assertSame(
            'Obec trvalého pobytu',
            $body['written'][1]['label'],
        );

        // Adresa: stará verze uzavřená dnem před nástupem, nová od nástupu.
        $statement = $this->db->pdo()->prepare(
            'SELECT city, street_line, effective_from, effective_to
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
              ORDER BY effective_from'
        );
        $statement->execute([$this->supplierId, $this->employeeId]);
        $addresses = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $addresses);
        self::assertSame('Praha', $addresses[0]['city']);
        self::assertSame('2026-08-21', $addresses[0]['effective_to']);
        self::assertSame('Brno', $addresses[1]['city']);
        self::assertSame(self::START_ON, $addresses[1]['effective_from']);
        // Nezvolená část adresy zůstává tak, jak ji vedou kmenová data.
        self::assertSame('Dlouhá 12', $addresses[1]['street_line']);

        // Podmínky: OPRAVA platné verze, ne nová verze podmínek.
        $statement = $this->db->pdo()->prepare(
            'SELECT work_place FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(
            ['Hlavní město Praha'],
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );

        // Přepočítaný seznam už zapsané údaje nenabízí.
        $remaining = array_column($body['view']['draft']['writeback'], 'field');
        self::assertNotContains('permanent_address.city', $remaining);
        self::assertNotContains('employment.contract_workplace', $remaining);
    }

    /** @return list<array<string,mixed>> */
    private function storedA1Rows(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT row_version, status, reference_hash
               FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY row_version'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Kmenové tabulky osoby a pracovního vztahu, do kterých se nesmí zapsat. */
    private const MASTER_DATA_TABLES = [
        'payroll_employees',
        'payroll_employments',
        'payroll_employment_terms',
        'payroll_person_identity_history',
        'payroll_person_identifiers',
        'payroll_person_addresses',
        'payroll_person_contacts',
        'payroll_person_tax_residences',
        'payroll_person_health_coverage_history',
        'payroll_person_foreign_permits',
    ];

    private function masterDataFingerprint(): string
    {
        $pdo = $this->db->pdo();
        $dump = [];
        foreach (self::MASTER_DATA_TABLES as $table) {
            // Tabulka je v pevném výčtu konstanty, ne z requestu.
            $statement = $pdo->prepare(
                "SELECT * FROM {$table} WHERE supplier_id = ? ORDER BY id"
            );
            $statement->execute([$this->supplierId]);
            $dump[$table] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return hash('sha256', (string) json_encode($dump));
    }

    private function seedA1MasterData(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Dlouhá 12", "Praha", "11000",
                     "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "prohlaseni-2026")',
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "karta-pojistence", "2026-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, activity_code,
                 jmhz_relationship_detail_code, work_place,
                 jmhz_workplace_municipality_code, jmhz_workplace_country_code,
                 cz_isco_code)
             VALUES (?, ?, ?, ?, ?, ?, "1", "1", "Praha 1, Dlouhá 1",
                     "554782", "CZ", "2411")',
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->officeId,
            self::START_ON,
            self::START_ON,
            self::START_ON,
        ]);
    }

    /**
     * REGZEC25-client.cdr-02 a další zákazy: A3-SPEC nenese kontaktní adresu
     * a z pracovních údajů jen část, A3-10 nenese zdravotní pojišťovnu ani
     * rezidenci. Zakázaný element ČSSZ zamítne, takže se událost neschválí.
     */
    public function testA3RejectsPartsForbiddenForTheVariant(): void
    {
        $cases = [
            'SPEC contact address' => [
                '11', '1',
                ['contact_address' => [
                    'street' => 'Testovací',
                    'house_number' => '5',
                    'postal_code' => '11000',
                    'city' => 'Praha',
                    'country_code' => 'CZ',
                ]],
            ],
            'SPEC work mode' => ['11', '1', ['employment' => ['work_mode_code' => '1']]],
            '10 health insurer' => ['10', null, ['health_insurance_code' => '111']],
            '10 tax residency' => ['10', null, ['tax_residency' => [
                'country_code' => 'CZ',
                'changed_on' => '2026-03-30',
            ]]],
        ];
        foreach ($cases as $label => [$activity, $detail, $changes]) {
            $this->employmentActiveSince('2026-03-01');
            $this->seedRegistrationEventPrerequisites($activity, $detail, '2026-03-01');
            $response = $this->approveA3($changes, 'synthetic-forbidden-' . md5($label));
            self::assertSame(422, $response->getStatusCode(), $label . (string) $response->getBody());
            self::assertSame(
                'registration_event_delta_variant_forbidden',
                $this->json($response)['error']['code'],
                $label,
            );
            $this->db->pdo()->prepare(
                'DELETE FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            )->execute([$this->supplierId, $this->employmentId]);
        }
    }

    public function testA3AllowsWhatTheSpecVariantMayCarry(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('11', '1', '2026-03-01');

        $response = $this->approveA3(
            ['employment' => ['contract_workplace' => 'Praha 1, Dlouhá 1']],
            'synthetic-spec-allowed',
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /** REGZEC25-client.adr.pnu-09 a adr.num-09 v oznámení A3. */
    public function testA3CzechAddressNeedsNumericHouseNumberAndValidPostalCode(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $address = [
            'street' => 'Krátká',
            'house_number' => '3a',
            'postal_code' => '11000',
            'city' => 'Praha',
            'country_code' => 'CZ',
        ];
        $letter = $this->approveA3(['permanent_address' => $address], 'synthetic-house-letter');
        self::assertSame(422, $letter->getStatusCode(), (string) $letter->getBody());
        self::assertStringContainsString('číslo o nejvýš čtyřech číslicích', $this->json($letter)['error']['message']);

        $address['house_number'] = '3';
        $address['postal_code'] = '81101';
        $zip = $this->approveA3(['permanent_address' => $address], 'synthetic-zip-eight');
        self::assertSame(422, $zip->getStatusCode(), (string) $zip->getBody());
        self::assertStringContainsString('nesmí začínat 0, 8 ani 9', $this->json($zip)['error']['message']);

        $address['country_code'] = 'SK';
        $abroad = $this->approveA3(['permanent_address' => $address], 'synthetic-zip-abroad');
        self::assertSame(201, $abroad->getStatusCode(), (string) $abroad->getBody());
    }

    /** REGZEC25-client.adr.onum-04 a cdr.onum-04 v oznámení A3. */
    public function testA3CzechAddressAllowsFourCharacterOrientationNumberOnly(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $address = [
            'street' => 'Krátká',
            'house_number' => '3',
            'orientation_number' => '12345',
            'postal_code' => '11000',
            'city' => 'Praha',
            'country_code' => 'CZ',
        ];
        foreach (['permanent_address', 'contact_address'] as $key) {
            $long = $this->approveA3([$key => $address], 'synthetic-onum-' . $key);
            self::assertSame(422, $long->getStatusCode(), (string) $long->getBody());
            self::assertStringContainsString(
                'u české adresy nejvýš 4 znaky',
                $this->json($long)['error']['message'],
            );
        }

        $address['country_code'] = 'SK';
        $address['postal_code'] = '81101';
        $abroad = $this->approveA3(['permanent_address' => $address], 'synthetic-onum-abroad');
        self::assertSame(201, $abroad->getStatusCode(), (string) $abroad->getBody());
    }

    /** REGZEC25-client.rdr.num-04 a rdr.cnt-08 v oznámení A3. */
    public function testA3ResidenceAddressMatchesTheTaxResidencyCountry(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $residence = [
            'street' => 'Main',
            'house_number' => '1',
            'postal_code' => '12345',
            'city' => 'Springfield',
            'country_code' => 'US',
        ];
        $residency = [
            'country_code' => 'CZ',
            'changed_on' => '2026-03-30',
            'residence_address' => $residence,
        ];
        $czech = $this->approveA3(['tax_residency' => $residency], 'synthetic-rdr-cz');
        self::assertSame(422, $czech->getStatusCode(), (string) $czech->getBody());
        self::assertStringContainsString('neposílá', $this->json($czech)['error']['message']);

        $residency['country_code'] = 'DE';
        $residency['identifier_type'] = 'T';
        $residency['identifier'] = 'SYN-TIN-1';
        $mismatch = $this->approveA3(['tax_residency' => $residency], 'synthetic-rdr-mismatch');
        self::assertSame(422, $mismatch->getStatusCode(), (string) $mismatch->getBody());
        self::assertStringContainsString('shodný se státem daňové rezidence', $this->json($mismatch)['error']['message']);

        $residency['country_code'] = 'US';
        $ok = $this->approveA3(['tax_residency' => $residency], 'synthetic-rdr-ok');
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
    }

    /** REGZEC25-client.fdr.num-09 v oznámení A3. */
    public function testA3CzechResidenceAddressKeepsTheCzechNumberRules(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $address = [
            'street' => 'Krátká',
            'house_number' => '12a',
            'postal_code' => '60200',
            'city' => 'Brno',
        ];
        $abroad = [
            'street' => 'Testovacia',
            'house_number' => '7',
            'postal_code' => '81101',
            'city' => 'Bratislava',
            'country_code' => 'SK',
        ];
        $bad = $this->approveA3(
            ['czech_residence_address' => $address, 'permanent_address' => $abroad],
            'synthetic-fdr-letter',
        );
        self::assertSame(422, $bad->getStatusCode(), (string) $bad->getBody());

        $address['house_number'] = '12';
        $address['orientation_number'] = '12345';
        $long = $this->approveA3(
            ['czech_residence_address' => $address, 'permanent_address' => $abroad],
            'synthetic-fdr-onum',
        );
        self::assertSame(422, $long->getStatusCode(), (string) $long->getBody());

        $address['orientation_number'] = '4';
        $ok = $this->approveA3(
            ['czech_residence_address' => $address, 'permanent_address' => $abroad],
            'synthetic-fdr-ok',
        );
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
    }

    /**
     * REGZEC25-client.fdr.num-05, fdr.pnu-05 a fdr.cit-05: pobyt v ČR je
     * v A3 při trvalém pobytu v ČR zakázaný (stát z téže změny i z karty osoby).
     */
    public function testA3CzechResidenceIsForbiddenForCzechPermanentResidence(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $residence = [
            'street' => 'Krátká',
            'house_number' => '12',
            'postal_code' => '60200',
            'city' => 'Brno',
        ];

        $unknown = $this->approveA3(['czech_residence_address' => $residence], 'synthetic-fdr-unknown');
        self::assertSame(422, $unknown->getStatusCode(), (string) $unknown->getBody());
        self::assertSame('registration_event_czech_residence_unverifiable', $this->json($unknown)['error']['code']);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Dlouhá 12", "Praha", "11000", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $card = $this->approveA3(['czech_residence_address' => $residence], 'synthetic-fdr-cz-card');
        self::assertSame(422, $card->getStatusCode(), (string) $card->getBody());
        self::assertSame('registration_event_czech_residence_forbidden', $this->json($card)['error']['code']);

        $czech = $this->approveA3([
            'czech_residence_address' => $residence,
            'permanent_address' => [
                'street' => 'Dlouhá',
                'house_number' => '12',
                'postal_code' => '11000',
                'city' => 'Praha',
                'country_code' => 'CZ',
            ],
        ], 'synthetic-fdr-cz-change');
        self::assertSame(422, $czech->getStatusCode(), (string) $czech->getBody());
        self::assertSame('registration_event_czech_residence_forbidden', $this->json($czech)['error']['code']);
    }

    /** REGZEC25-PROC.A3.scope-01: posun nástupu se hlásí A3 na 10223. */
    public function testA3CarriesAShiftedStartDate(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $approved = $this->approveA3(
            ['employment' => ['actual_start_on' => '2026-03-05']],
            'synthetic-shifted-start',
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertStringContainsString(
            ' fro="2026-03-05"',
            $this->storedArtifactXml((int) $this->json($prepared)['submission_id']),
        );
    }

    /**
     * REGZEC25-employee.fro-05: datum nástupu (10223) musí být menší nebo
     * rovno dni, ke kterému se A3, A5, A6 a A7 hlásí (10009).
     */
    public function testEventIsRejectedWhenItPrecedesTheStartOfEmployment(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $shifted = $this->approveA3(
            ['employment' => ['actual_start_on' => '2026-04-02']],
            'synthetic-start-after-change',
        );
        self::assertSame(422, $shifted->getStatusCode(), (string) $shifted->getBody());
        self::assertSame('registration_event_before_start', $this->json($shifted)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET actual_start_date = "2026-08-20"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $transfer = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a5-before-start',
                'new_variable_symbol' => '1110000005',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $transfer->getStatusCode(), (string) $transfer->getBody());
        self::assertSame('registration_event_before_start', $this->json($transfer)['error']['code']);
    }

    /**
     * REGZEC25-client.bno-02: u českého občanství nese i změna A3 rodné
     * číslo v `client/@bno`; bez něj se oznámení neschválí.
     */
    public function testA3ForCzechCitizenCarriesTheBirthNumber(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $approved = $this->approveA3(['title_prefix' => 'Bc.'], 'synthetic-a3-bno');
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertMatchesRegularExpression(
            '/<client [^>]*bno="9152031234"[^>]*ikmpsv="1000000001"|<client [^>]*ikmpsv="1000000001"[^>]*bno="9152031234"/',
            $this->storedArtifactXml((int) $this->json($prepared)['submission_id']),
        );

        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_identifiers WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $this->employeeId]);
        $missing = $this->approveA3(['title_prefix' => 'MUDr.'], 'synthetic-a3-no-bno');
        self::assertSame(422, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertSame('registration_event_birth_number_missing', $this->json($missing)['error']['code']);
    }

    /**
     * REGZEC25-forin.cur-03 a forin.cur-07: u druhu činnosti „N" nese i změna
     * A3 cizozemského nositele (`forin/@cur`) z profilu A1.
     */
    public function testA3ForActivityNCarriesTheForeignInsurer(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('N', '1', '2026-03-01');

        $withoutProfile = $this->approveA3(['title_prefix' => 'Bc.'], 'synthetic-a3-n-missing');
        self::assertSame(422, $withoutProfile->getStatusCode(), (string) $withoutProfile->getBody());
        self::assertSame(
            'registration_event_foreign_insurance_missing',
            $this->json($withoutProfile)['error']['code'],
        );

        $payload = $this->completeA1Payload();
        $payload['effective_on'] = '2026-03-01';
        $payload['employment']['actual_start_on'] = '2026-03-01';
        $payload['employment']['contract_start_on'] = '2026-03-01';
        $payload['employment']['activity_code'] = 'N';
        $payload['employment']['relationship_detail_code'] = '1';
        $payload['foreign_insurance'] = [
            'current' => 'S',
            'name' => 'Syntetická pojišťovna',
            'street' => null,
            'house_number' => null,
            'orientation_number' => null,
            'postal_code' => null,
            'city' => null,
            'country_code' => 'DE',
            'identifier' => 'SYN-1',
            'sector' => '06',
        ];
        $saved = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());

        $approved = $this->approveA3(['title_prefix' => 'Bc.'], 'synthetic-a3-n-forin');
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($prepared)['submission_id']);
        self::assertStringContainsString(
            '<forin cur="S" nam="Syntetická pojišťovna" cnt="DE" id="SYN-1" sec="06"/>',
            $xml,
        );
        self::assertLessThan(strpos($xml, '<forin'), strpos($xml, '<job'));
    }

    /** REGZEC25-client.birth.dat-06 u nového nástupu v A3. */
    public function testA3RejectsAShiftedStartThatMakesTheEmployeeUnderage(): void
    {
        $this->identities->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            2,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '2012-03-10',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => 'CZ',
                'sex' => 'female',
            ],
        );
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');

        $response = $this->approveA3(
            ['employment' => ['actual_start_on' => '2026-03-05']],
            'synthetic-underage-start',
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('registration_a3_underage', $this->json($response)['error']['code']);
    }

    /** REGZEC25-comp.nvs-06: nový VS se nesmí shodovat s původním. */
    public function testA5RejectsTheSameVariableSymbol(): void
    {
        $this->employmentActiveSince(self::TODAY);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);

        $same = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a5-same',
                'new_variable_symbol' => '1100000007',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $same->getStatusCode(), (string) $same->getBody());
        self::assertSame('registration_a5_variable_symbol_unchanged', $this->json($same)['error']['code']);

        // REGZEC25-comp.nvs-06: C_COKR + Luhn pro VS10 platí i pro nový VS.
        $typo = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a5-typo',
                'new_variable_symbol' => '1110000006',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $typo->getStatusCode(), (string) $typo->getBody());
        self::assertSame('registration_a5_variable_symbol_invalid', $this->json($typo)['error']['code']);

        $other = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a5-other',
                'new_variable_symbol' => '1110000005',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $other->getStatusCode(), (string) $other->getBody());
    }

    /** REGZEC25-employee.dep-04: kód správy musí být z číselníku C_COKR. */
    public function testEventRejectsAWorkplaceCodeOutsideTheDistrictCodebook(): void
    {
        $this->employmentActiveSince(self::TODAY);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "999"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'variable_symbol_transfer',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-dep-invalid',
                'new_variable_symbol' => '1110000005',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('registration_cssz_workplace_code_invalid', $this->json($response)['error']['code']);
    }

    /** REGZEC25-forin.num-02: A6 a A7 hlídají adresu nositele jako A1. */
    public function testA6ForeignInsurerAddressIsAllOrNothing(): void
    {
        $this->employmentActiveSince(self::TODAY);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);
        $insurer = [
            'current' => 'P',
            'name' => 'Syntetická zahraniční instituce',
            'country_code' => 'SK',
            'street' => 'Testovacia',
        ];
        $partial = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'czech_legislation_start',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a6-partial',
                'foreign_insurance' => $insurer,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $partial->getStatusCode(), (string) $partial->getBody());

        $insurer += ['house_number' => '7', 'postal_code' => '81101', 'city' => 'Bratislava'];
        $complete = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'czech_legislation_start',
                'effective_on' => self::TODAY,
                'source_reference' => 'synthetic-a6-complete',
                'foreign_insurance' => $insurer,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $complete->getStatusCode(), (string) $complete->getBody());
    }

    /** REGZEC25-forin.sec-04: sektor nositele v A6 a A7 je kód z číselníku 01 až 08. */
    public function testA6AndA7ForeignInsurerSectorMustBeFromTheCodebook(): void
    {
        $this->employmentActiveSince(self::TODAY);
        $this->seedRegistrationEventPrerequisites('1', '1', self::TODAY);
        foreach ([['czech_legislation_start', 'P'], ['czech_legislation_end', 'S']] as [$interaction, $current]) {
            $insurer = [
                'current' => $current,
                'name' => 'Syntetická zahraniční instituce',
                'country_code' => 'SK',
                'identifier' => 'SYN-INSTITUTION-1',
                'sector' => 'Nemoc',
            ];
            $text = ($this->action)->approveEvent(
                $this->request('POST')->withParsedBody([
                    'environment' => 'test',
                    'interaction' => $interaction,
                    'effective_on' => self::TODAY,
                    'source_reference' => 'synthetic-sector-text-' . $current,
                    'foreign_insurance' => $insurer,
                ]),
                new Response(),
                ['employmentId' => (string) $this->employmentId],
            );
            self::assertSame(422, $text->getStatusCode(), (string) $text->getBody());
            self::assertStringContainsString('číselníku Sektor', $this->json($text)['error']['message']);

            $insurer['sector'] = '06';
            $code = ($this->action)->approveEvent(
                $this->request('POST')->withParsedBody([
                    'environment' => 'test',
                    'interaction' => $interaction,
                    'effective_on' => self::TODAY,
                    'source_reference' => 'synthetic-sector-code-' . $current,
                    'foreign_insurance' => $insurer,
                ]),
                new Response(),
                ['employmentId' => (string) $this->employmentId],
            );
            self::assertSame(201, $code->getStatusCode(), (string) $code->getBody());
        }
    }

    /** REGZEC25-attachs.attach-02 u zdůvodnění storna A8. */
    public function testA8ExplanationAttachmentIsCheckedForExtensionAndSize(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');
        $a1 = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $this->markRegistrationAccepted((int) $a1['submission_id']);
        $this->assignIdentityFromAcceptedRegistration();
        $this->employmentActiveSince(self::TODAY);
        foreach ([
            'UPDATE payroll_employment_terms SET effective_from = ?
              WHERE supplier_id = ? AND employment_id = ?',
            'UPDATE payroll_employment_external_ids SET valid_from = ?
              WHERE supplier_id = ? AND employment_id = ?',
        ] as $sql) {
            $this->db->pdo()->prepare($sql)->execute([
                self::TODAY, $this->supplierId, $this->employmentId,
            ]);
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_external_ids SET valid_from = ?
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([self::TODAY, $this->supplierId, $this->employeeId]);
        foreach ([
            'zduvodneni.exe' => 'syntetický text',
            'zduvodneni.pdf' => str_repeat('x', 2 * 1024 * 1024 + 1),
        ] as $name => $content) {
            $response = ($this->action)->approveEvent(
                $this->request('POST')->withParsedBody([
                    'environment' => 'test',
                    'interaction' => 'cancellation',
                    'effective_on' => self::TODAY,
                    'source_reference' => 'synthetic-a8-attachment-' . md5($name),
                    'source_submission_id' => $a1['submission_id'],
                    'not_started' => false,
                    'explanation_attachment' => [
                        'name' => $name,
                        'description' => null,
                        'data_base64' => base64_encode($content),
                    ],
                ]),
                new Response(),
                ['employmentId' => (string) $this->employmentId],
            );
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(
                'registration_a8_explanation_attachment_invalid',
                $this->json($response)['error']['code'],
                $name,
            );
        }
    }

    /**
     * REGZEC25-unemplcomp.earlyterm-02: cizinec s povolením k zaměstnání, jehož
     * zaměstnání končí před koncem oprávnění, musí v odhlášce nést důvod
     * předčasného ukončení; bez té podmínky je důvod zakázaný.
     */
    public function testA2RequiresEarlyTerminationReasonOnlyForAnEarlyEndingPermit(): void
    {
        $this->identities->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            2,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'UA',
                'citizenship_country_code' => 'UA',
                'sex' => 'female',
            ],
        );
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $payload = $this->completeA1Payload();
        $payload['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $payload['employment']['required_education_code'] = 'T';
        $payload['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => 'UA',
        ];
        $payload['tax_residency'] = [
            'country_code' => 'UA',
            'identifier_type' => 'T',
            'identifier' => 'SYN-TIN-9',
            'residence_address' => [
                'street' => 'Holovna',
                'house_number' => '1',
                'orientation_number' => null,
                'city' => 'Kyiv',
                'postal_code' => '01001',
                'country_code' => 'UA',
                'ruian_point' => null,
            ],
        ];
        $payload['permanent_address']['country_code'] = 'UA';
        $payload['permanent_address']['postal_code'] = '01001';
        $payload['permanent_address']['city'] = 'Kyiv';
        $payload['czech_residence_address'] = [
            'street' => 'Dlouhá',
            'house_number' => '12',
            'orientation_number' => null,
            'city' => 'Praha',
            'postal_code' => '11000',
            'country_code' => 'CZ',
            'ruian_point' => null,
        ];
        $payload['foreign_worker'] = [
            'free_access' => false,
            'free_access_reason_code' => null,
            'permit_type_code' => '2',
            'issuing_labour_office_code' => null,
            'permit_identifier' => 'SYN-PERMIT-1',
            'permit_from' => '2026-01-01',
            'permit_to' => '2027-12-31',
        ];
        $saved = ($this->action)->saveA1Profile(
            $this->request('PUT')->withParsedBody($payload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(
            'verified',
            $this->json($saved)['profile']['status'],
            json_encode($this->json($saved)['profile']['problems'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25", status = "ended"
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);

        $request = [
            'environment' => 'test',
            'interaction' => 'termination',
            'effective_on' => '2026-08-25',
            'ended_by_death' => false,
            'unemployment' => ['mode' => 'not_provided_2'],
        ];
        $missing = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertSame(
            'registration_a2_early_termination_required',
            $this->json($missing)['error']['code'],
        );

        $request['unemployment']['early_termination_reason'] = '1';
        $approved = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody($request),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertStringContainsString(
            ' earlyterm="1"',
            $this->storedArtifactXml((int) $this->json($prepared)['submission_id']),
        );
        // REGZEC25-DEADLINE.FOREIGN.nonappearance-01: lhůta zohlední oznámení
        // úřadu práce (§ 88 ZoZ, 10 dnů); platí dřívější osmidenní lhůta ČSSZ.
        $deadline = $this->json($prepared)['deadline'];
        self::assertSame('cz-regzec-foreign-permit-labour-office-2026-07.v1', $deadline['ruleset_id']);
        self::assertSame('2026-09-02', $deadline['due_on']);
    }

    public function testA2EarlyTerminationReasonIsForbiddenWithoutAnEarlyEndingPermit(): void
    {
        $this->seedTrustedReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET actual_start_date = ?, end_date = "2026-08-25",
                    status = "ended", relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?'
        )->execute([self::START_ON, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);

        $response = ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'termination',
                'effective_on' => '2026-08-25',
                'ended_by_death' => false,
                'unemployment' => [
                    'mode' => 'not_provided_2',
                    'early_termination_reason' => '1',
                ],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a2_early_termination_forbidden',
            $this->json($response)['error']['code'],
        );
    }

    /** PREZEC26-vs-5: P2 nese variabilní symbol z přijaté P1. */
    public function testPrezecP2CarriesTheVariableSymbolOfTheAcceptedP1(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET social_security_variable_symbol = "1110000005"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $xml = $this->storedArtifactXml((int) $this->json($response)['submission_id']);
        self::assertStringContainsString('act="10"', $xml);
        self::assertStringContainsString(' vs="1100000007"', $xml);
        self::assertStringNotContainsString('1110000005', $xml);
    }

    /** PREZEC26-predat-5: zaměstnanec mladší 14 let k nástupu se do P1 nedostane. */
    public function testPrezecP1RejectsAnEmployeeYoungerThanFourteenAtStart(): void
    {
        $this->identities->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            2,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '2015-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => 'CZ',
                'sex' => 'female',
            ],
        );

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('registration_prezec_underage', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countSubmissions());
    }

    /** PREZEC26-DEADLINE-10: A1 po P1 se skutečným nástupem pozdě po předpokládaném dni nejde. */
    public function testFullRegistrationAfterP1IsRefusedWhenTheStartIsLateByMoreThanEightDays(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
        $lateStart = '2026-09-05';
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$lateStart, $lateStart, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', $lateStart, null, null, true);
        $this->saveA1ProfileFor($lateStart, '1', '1');

        $response = $this->post();

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'registration_a1_after_p1_start_too_late',
            $this->json($response)['error']['code'],
        );
    }

    public function testFullRegistrationAfterP1IsAcceptedWithinEightDaysOfTheExpectedStart(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
        $start = '2026-08-25';
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$start, $start, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', $start, null, null, true);
        $this->saveA1ProfileFor($start, '1', '1');

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('full_registration_after_p1', $this->json($response)['interaction']);
    }

    /** PREZEC26-DEADLINE-6: po P1 i A1 musí nenastoupení nést P2 i A8. */
    public function testNoShowAfterP1AndA1RemindsTheCancellation(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
        $start = '2026-08-25';
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$start, $start, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', $start, null, null, true);
        $this->saveA1ProfileFor($start, '1', '1');
        $a1 = $this->json($this->post());
        $this->markRegistrationAccepted((int) $a1['submission_id']);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show", actual_start_date = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->post();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('pre_registration_no_show', $body['interaction']);
        self::assertContains(
            'registration_no_show_needs_cancellation',
            array_column($body['problems'], 'code'),
        );
    }

    public function testNoShowOfAP1AloneDoesNotRemindTheCancellation(): void
    {
        $this->seedAcceptedPreRegistrationReceipt();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $body = $this->json($this->post());

        self::assertNotContains(
            'registration_no_show_needs_cancellation',
            array_column($body['problems'], 'code'),
        );
    }

    /** REGZEC25-DEADLINE.A1.fallback-01: nástup nebyl předem znám, lhůta je osm dnů od prvního plnění. */
    public function testA1WithUnknownStartHasEightDaysFromTheFirstPerformance(): void
    {
        $started = '2026-08-12';
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$started, $started, $this->supplierId, $this->employmentId]);
        $this->seedRegistrationEventPrerequisites('1', '1', $started, null, null, true);
        $this->saveA1ProfileFor($started, '1', '1');

        $default = $this->json(($this->action)->preview(
            $this->request('GET'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        self::assertSame($started, $default['deadline']['due_on']);

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'start_not_known_in_advance' => true,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('2026-08-20', $body['deadline']['due_on']);
        self::assertSame(
            'cz-employee-registration-unknown-start-2026-07.v1',
            $body['deadline']['ruleset_id'],
        );
    }

    public function testUnknownStartIsRefusedForAFutureStart(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
                'start_not_known_in_advance' => true,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('registration_unknown_start_in_future', $this->json($response)['error']['code']);
    }

    /** REGZEC25-employee.dep-04: kód správy mimo číselník C_COKR blokuje přihlášku. */
    public function testA1IsBlockedByAWorkplaceCodeOutsideTheDistrictCodebook(): void
    {
        $this->seedRegistrationEventPrerequisites('1', '1', self::START_ON, null, null, true);
        $this->saveA1ProfileFor(self::START_ON, '1', '1');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "999"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        $response = ($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'registration_mode' => 'full',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('registration_data_incomplete', $error['code']);
        self::assertSame(['cssz_workplace_code'], array_column($error['problems'], 'field'));
        self::assertStringContainsString('okresních správ ČSSZ', $error['problems'][0]['message']);
    }

    /** REGZEC25-DEADLINE.A3.wait48-01: 48 hodin po přijaté A3 čeká jen dotčený vztah. */
    public function testAcceptedA3HoldsTheMonthlyReportOfThatEmploymentForFortyEightHours(): void
    {
        $this->employmentActiveSince('2026-03-01');
        $this->seedRegistrationEventPrerequisites('1', '1', '2026-03-01');
        $approved = $this->approveA3(
            ['permanent_address' => [
                'street' => 'Krátká',
                'house_number' => '3',
                'postal_code' => '11000',
                'city' => 'Praha',
                'country_code' => 'CZ',
            ]],
            'synthetic-wait48',
        );
        self::assertSame(201, $approved->getStatusCode(), (string) $approved->getBody());
        $prepared = $this->json(($this->action)->prepare(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'event_id' => $this->json($approved)['id'],
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        ));
        $settlement = new \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationChangeSettlement(
            new \MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository($this->db),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertNull(
            $settlement->pendingUntil($this->supplierId, 'test', [$this->employmentId], $now),
            'Podání A3, které ČSSZ ještě nepřijala, nic nezdržuje.',
        );

        $this->markRegistrationAccepted((int) $prepared['submission_id']);

        $pending = $settlement->pendingUntil($this->supplierId, 'test', [$this->employmentId], $now);
        self::assertNotNull($pending);
        self::assertSame([$this->employmentId], $pending['employment_ids']);
        self::assertGreaterThan($now->modify('+47 hours'), $pending['until']);
        self::assertNull($settlement->pendingUntil($this->supplierId, 'test', [$this->employmentId + 1000], $now));
        self::assertNull($settlement->pendingUntil(
            $this->supplierId,
            'test',
            [$this->employmentId],
            $now->modify('+49 hours'),
        ));
        self::assertNull($settlement->pendingUntil($this->supplierId, 'production', [$this->employmentId], $now));
        self::assertNull($settlement->pendingUntil($this->otherSupplierId, 'test', [$this->employmentId], $now));
    }

    private function employmentActiveSince(string $date): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = ?, actual_start_date = ?, status = "active"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$date, $date, $this->supplierId, $this->employmentId]);
    }

    /** @param array<string,mixed> $changes */
    private function approveA3(array $changes, string $reference): \Psr\Http\Message\ResponseInterface
    {
        return ($this->action)->approveEvent(
            $this->request('POST')->withParsedBody([
                'environment' => 'test',
                'interaction' => 'change',
                'effective_on' => '2026-03-30',
                'source_reference' => $reference,
                'changes' => $changes,
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
    }

    /** @return array<string,mixed> */
    private function completeA1Payload(): array
    {
        return [
            'effective_on' => self::START_ON,
            'row_version' => 0,
            'permanent_address' => [
                'street' => 'Dlouhá',
                'house_number' => '12',
                'orientation_number' => null,
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
                'ruian_point' => null,
            ],
            'tax_residency' => [
                'country_code' => 'CZ',
                'identifier_type' => null,
                'identifier' => null,
                'residence_address' => null,
            ],
            'employment' => [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'actual_start_on' => self::START_ON,
                'contract_start_on' => self::START_ON,
                'small_scale' => false,
                'employment_status_code' => '1111',
                'work_mode_code' => '1',
                'continuous_operation' => false,
                'prevailing_workplace_code' => '1',
                'expected_workplaces' => null,
                'contract_workplace' => 'Praha 1, Dlouhá 1',
                'workplace_city' => 'Praha',
                'workplace_municipality_code' => '554782',
                'profession_code' => '24111',
                'required_education_code' => null,
                'position_name' => 'Účetní',
                'leadership' => false,
            ],
            'pension' => [
                'type_code' => null,
                'received_from' => null,
                'early_retirement' => false,
                'reduced_retirement_age' => false,
            ],
            'health_insurance_code' => '111',
            'facts' => [
                'highest_education_code' => 'T',
                'disability_card' => false,
                'health_restrictions' => [],
            ],
            'foreign_legislation' => [
                'applies' => false,
                'country_code' => null,
            ],
            'proof_identity' => null,
            'foreign_worker' => null,
            'czech_residence_address' => null,
            'contact_address' => null,
            'attachments' => [],
        ];
    }

    private function post(): \Psr\Http\Message\ResponseInterface
    {
        return ($this->action)->prepare(
            $this->request('POST'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
    }

    private function current(): \Psr\Http\Message\ResponseInterface
    {
        return ($this->action)->current(
            $this->request('GET'),
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
            new JmhzSoftwareIdentification('MyÚčto.cz', '5.6.0'),
            $clock,
        );
        $this->registrationService = $service;
        $this->clock = $clock;

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
            new PayrollRegistrationA1MasterDataWriter(
                $this->identities,
                new PayrollRegistrationIdentityRepository($this->db),
                $container->get(PayrollPersonProfileRepository::class),
                $container->get(PayrollPersonProfileValidator::class),
                $container->get(PayrollPersonStatutoryEvidenceRepository::class),
                $container->get(PayrollEmploymentRepository::class),
                $container->get(PayrollEmploymentValidator::class),
            ),
            $access,
            new IpMatcher(),
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
             VALUES (?, "REG", "Synteticka uctarna", "1100000007", 1)',
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

    /**
     * OIČ a ID PPV přidělí ČSSZ až přijatou přihláškou; dřív přidělené ID PPV
     * by plnou A1 zablokovalo jako duplicitní registraci.
     */
    private function assignIdentityFromAcceptedRegistration(): void
    {
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $this->employmentId,
            'test',
            '1000000001',
            '200000000000000000002',
            self::START_ON,
            'synthetic-regzec-identity',
            true,
            $this->userId,
        );
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
                'new_variable_symbol' => '1110000005',
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
     * Přijatá PREZEC P1 PRODUKČNÍ CESTOU: příprava, odeslání a důvěryhodný
     * protokol s doloženým výsledkem formuláře. Vrací GUID, který P1 nesla
     * v `idform` a který protokol potvrdil.
     */
    private function seedAcceptedPreRegistrationReceipt(): string
    {
        $prepared = $this->json($this->post());
        self::assertSame('ready', $prepared['status']);
        self::assertSame('PREZEC26', $prepared['agenda_code']);
        $xml = $this->storedArtifactXml((int) $prepared['submission_id']);
        self::assertSame(
            1,
            preg_match('/idform="([0-9A-F-]{36})"/D', $xml, $match),
        );
        $guid = $match[1];
        $correlation = 'synthetic-p1-correlation:' . $this->employmentId;
        $submissions = Bootstrap::buildContainer()->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submitted = $submissions->transition(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $prepared['row_version'],
            'submitted',
            $correlation,
        );
        $partId = (int) $prepared['part_id'];
        $verifier = new class ($partId, $guid) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly int $partId,
                private readonly string $guid,
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
                        $this->guid,
                        $this->partId,
                        1,
                        'Accepted',
                        'accepted',
                        null,
                        null,
                        [],
                    )],
                );
            }
        };
        $receipt = $submissions->importReceipt(
            $this->supplierId,
            (int) $prepared['submission_id'],
            (int) $submitted['row_version'],
            $partId,
            '<synthetic-p1-receipt/>',
            'synthetic-p1-receipt:' . $this->employmentId,
            $correlation,
            'CSSZ_PREZEC',
            'accepted',
            'vrep_apep',
            'synthetic-p1-key:' . $this->employmentId,
            $this->userId,
            $verifier,
        );
        self::assertTrue($receipt['trusted']);

        return $guid;
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
