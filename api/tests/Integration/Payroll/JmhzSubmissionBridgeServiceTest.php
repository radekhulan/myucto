<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzDeferralRepository;
use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlDryRunService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEffectiveFormLedgerResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFormExclusion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenSubmissionIdentity;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationChangeSettlement;
use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionRetryConfirmationService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzRejectedSubmissionRefreezeService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionAbandonService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceiptFormOutcome;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Most z nácviku do ostrého podání. Testuje se přesně to, co nácvik neumí:
 * že GUIDy vzniknou právě jednou, že opakované volání vrátí TYTÉŽ bajty
 * (ne jen tentýž záznam) a že podání, které by ČSSZ zamítla, nevznikne vůbec.
 */
#[Group('integration')]
final class JmhzSubmissionBridgeServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PREPARATION_ID = 501;
    private const RUN_ID = 401;
    private const PERIOD_START = '2026-07-01';
    private const PERIOD_END = '2026-07-31';
    private const ENVIRONMENT = 'test';
    private const SNAPSHOT_HASH = '3333333333333333333333333333333333333333333333333333333333333333';

    private Connection $db;
    private Config $config;
    private JmhzPreparationSnapshotRepository $preparations;
    private PayrollPeopleRepository $people;
    private PayrollSubmissionRepository $submissionRepository;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $config = $container->get(Config::class);
        if (!$db instanceof Connection || !$config instanceof Config) {
            self::markTestSkipped(
                'Databáze nebo konfigurace JMHZ bridge testu není dostupná.',
            );
        }
        $this->db = $db;
        $this->config = $config;
        $this->preparations = $container->get(JmhzPreparationSnapshotRepository::class);
        $this->people = $container->get(PayrollPeopleRepository::class);
        foreach ([
            'payroll_obligations',
            'payroll_submission_deadlines',
            'payroll_submissions',
            'payroll_submission_parts',
            'payroll_submission_artifacts',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )?->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )?->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );

        $clock = new MockClock('2026-08-05 11:30:00 Europe/Prague');
        $this->submissionRepository = new PayrollSubmissionRepository($db);
        $this->obligations = new PayrollObligationService(
            $this->submissionRepository,
            $clock,
        );
        $this->submissions = new PayrollSubmissionService(
            $this->submissionRepository,
            new PayrollSubmissionStateMachine(),
            new SecretEncryption($config),
            $clock,
        );
    }

    protected function tearDown(): void
    {
        $this->protocolFactory?->cleanUp();
        $this->protocolFactory = null;
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * REGZEC25-DEADLINE.A3.wait48-01: JMHZ odeslané dřív než 48 hodin po přijaté
     * změně údajů zaměstnance (A3) ČSSZ může zamítnout (chyba 243), takže se
     * hlášení nezmrazí a hláška řekne, od kdy to jde.
     */
    public function testRegistrationChangeAcceptedWithinFortyEightHoursBlocksFreezing(): void
    {
        $obligationId = $this->registerObligation();
        $settlement = new class (new PayrollRegistrationSubmissionRepository($this->db)) extends PayrollRegistrationChangeSettlement {
            public function pendingUntil(
                int $supplierId,
                string $environment,
                array $employmentIds,
                \DateTimeImmutable $now,
            ): ?array {
                return [
                    'until' => new \DateTimeImmutable('2026-08-06 12:00:00 UTC'),
                    'employment_ids' => [1, 2],
                ];
            }
        };

        try {
            $this->bridge(registrationSettlement: $settlement)->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                $obligationId,
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Hlášení nemělo jít zmrazit.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_registration_change_settling', $exception->validationCode);
            self::assertStringContainsString('6. 8. 2026 14:00', $exception->getMessage());
            self::assertSame('jmhz_registration_change_settling', JmhzSubmissionBridgeService::clientErrorCode($exception));
            $details = $exception->clientDetails()['details'];
            self::assertSame([1, 2], $details['deferral']['employment_ids']);
            self::assertSame(self::PREPARATION_ID, $details['deferral']['preparation_id']);
        }
        $count = $this->row(
            'SELECT COUNT(*) AS c FROM payroll_submissions WHERE supplier_id = ?',
            [$this->supplierId],
        );
        self::assertSame(0, (int) $count['c']);

        $result = $this->bridge(registrationSettlement: $settlement)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
            registrationSettlingConfirmed: true,
        );
        self::assertSame('ready', $result['status'], 'Účetní hlášení vědomě odešle i tak.');
    }

    public function testFreezesWhenNoRegistrationChangeIsSettling(): void
    {
        $obligationId = $this->registerObligation();
        $settlement = new class (new PayrollRegistrationSubmissionRepository($this->db)) extends PayrollRegistrationChangeSettlement {
            public function pendingUntil(
                int $supplierId,
                string $environment,
                array $employmentIds,
                \DateTimeImmutable $now,
            ): ?array {
                return null;
            }
        };

        $result = $this->bridge(registrationSettlement: $settlement)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );

        self::assertSame('ready', $result['status']);
    }

    /**
     * Čas vyplnění nese český den: půl hodiny po půlnoci (letní čas) je v UTC
     * ještě předchozí den, ale `datumVyplneni` musí začínat dnem českým.
     */
    public function testFilledAtCarriesCzechDayShortlyAfterMidnight(): void
    {
        $obligationId = $this->registerObligation();
        $result = $this->bridge(now: '2026-08-06 00:30:00 Europe/Prague')->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );

        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $result['artifact_id'],
        );

        self::assertStringContainsString(
            '<datumVyplneni>2026-08-06T00:30:00+02:00</datumVyplneni>',
            $xml,
        );
    }

    public function testFreezesReadySubmissionOnVrepChannel(): void
    {
        $obligationId = $this->registerObligation();
        $result = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );

        self::assertTrue($result['created']);
        self::assertSame('ready', $result['status']);
        self::assertSame(self::ENVIRONMENT, $result['environment']);
        self::assertSame(
            self::SNAPSHOT_HASH,
            $result['source_snapshot_hash'],
        );
        self::assertSame('1101234563', $result['variable_symbol']);
        self::assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-7[0-9A-F]{3}-[0-9A-F]{4}-[0-9A-F]{12}$/D',
            $result['submission_guid'],
        );

        $submission = $this->submissionRow($result['submission_id']);
        // Kanál není štítek: trigger ledgeru pokusů vyžaduje shodu kanálu
        // pokusu s kanálem podání, takže `manual_upload` by udělal
        // z podání něco neodeslatelného.
        self::assertSame('vrep_apep', $submission['channel']);
        self::assertSame('ready', $submission['status']);
        self::assertSame(self::ENVIRONMENT, $submission['environment']);
        self::assertNull($submission['submitted_at']);

        $part = $this->row(
            'SELECT agenda_code, subject_reference, source_entity_type,
                    source_entity_reference, source_snapshot_hash
               FROM payroll_submission_parts
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $result['part_id']],
        );
        self::assertSame(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            $part['agenda_code'],
        );
        self::assertSame('payroll_run:' . self::RUN_ID, $part['subject_reference']);
        self::assertSame('jmhz_preparation', $part['source_entity_type']);
        self::assertSame(
            'jmhz_preparation:' . self::PREPARATION_ID,
            $part['source_entity_reference'],
        );
        self::assertSame(self::SNAPSHOT_HASH, $part['source_snapshot_hash']);

        $artifact = $this->row(
            'SELECT artifact_kind, direction, mime_type, channel,
                    xsd_version, catalog_version, artifact_sha256
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $result['artifact_id']],
        );
        self::assertSame('outbound_xml', $artifact['artifact_kind']);
        self::assertSame('outbound', $artifact['direction']);
        self::assertSame('application/xml', $artifact['mime_type']);
        self::assertSame('vrep_apep', $artifact['channel']);
        self::assertSame(
            JmhzSchemaCatalog::PACKAGE_KEY,
            $artifact['xsd_version'],
        );
        self::assertSame(
            JmhzControlSourceCatalog::CATALOG_KEY,
            $artifact['catalog_version'],
        );
        self::assertSame(
            $result['artifact_sha256'],
            $artifact['artifact_sha256'],
        );

        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $result['artifact_id'],
        );
        // GUID ve vráceném tvaru musí být týž, jaký je zapsaný v datové větě —
        // transportní vrstva jinou pravdu než tuhle nemá.
        self::assertStringContainsString(
            "<idPodani>{$result['submission_guid']}</idPodani>",
            $xml,
        );
        self::assertSame(hash('sha256', $xml), $result['artifact_sha256']);
    }

    #[DataProvider('annualSettlementPeriods')]
    public function testFrozenAnnualSettlementEvidenceProducesReadyImmutableSubmission(
        string $periodStart,
        string $periodEnd,
        string $requestStatus,
        ?array $settlement,
        array $expectedFragments,
        array $unexpectedFragments,
    ): void {
        $payload = $this->payloadForPeriod($periodStart, $periodEnd);
        $payload['people'][0]['annual_evidence'] = [
            'tax_year' => 2025,
            'request' => [
                'id' => 701,
                'row_version' => 1,
                'status' => $requestStatus,
                'requested_on' => $requestStatus === 'requested' ? '2026-02-10' : null,
                'annual_claims' => 'none',
                'evidence_sha256' => str_repeat('8', 64),
            ],
            'request_evidence' => [
                'present' => true,
                'proof' => 'verified_request_row_under_unique_key_lock',
                'supplier_id' => 7,
                'employee_id' => 11,
                'tax_year' => 2025,
            ],
            'settlement' => $settlement,
            'settlement_evidence' => [
                'performed' => $settlement !== null,
                'proof' => $settlement === null
                    ? 'outcome_absent_under_unique_key_lock'
                    : 'verified_annual_outcome_and_document_revision',
                'supplier_id' => 7,
                'employee_id' => 11,
                'tax_year' => 2025,
            ],
            'withholding_certificate' => substr($periodStart, 5, 2) === '01'
                ? [
                    'revision_id' => 801,
                    'snapshot_hash' => str_repeat('9', 64),
                    'paid_income_minor_units' => 125_000,
                    'withholding_tax_minor_units' => 18_000,
                ]
                : null,
        ];

        $resolution = $this->resolutionFor(
            $this->pvpoj(period: substr($periodStart, 0, 7)),
            $payload,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
        self::assertNotContains(
            'jmhz_scenario1_annual_fields_unsupported',
            array_map(
                static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
        self::assertSame('resolved', $resolution->status());

        $bridge = $this->bridge(
            $resolution,
            '2027-01-05 11:30:00 Europe/Prague',
        );
        $created = $bridge->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );
        self::assertTrue($created['created']);
        self::assertSame('ready', $created['status']);
        $frozenBytes = $this->submissions->artifactBytes(
            $this->supplierId,
            $created['artifact_id'],
        );
        foreach ($expectedFragments as $fragment) {
            self::assertStringContainsString($fragment, $frozenBytes);
        }
        foreach ($unexpectedFragments as $fragment) {
            self::assertStringNotContainsString($fragment, $frozenBytes);
        }

        $replayed = $bridge->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );
        self::assertFalse($replayed['created']);
        self::assertSame('ready', $replayed['status']);
        self::assertSame($created['submission_id'], $replayed['submission_id']);
        self::assertSame($created['artifact_sha256'], $replayed['artifact_sha256']);
        self::assertSame(
            $frozenBytes,
            $this->submissions->artifactBytes(
                $this->supplierId,
                $replayed['artifact_id'],
            ),
        );
    }

    /** @return iterable<string,array{string,string,string,?array<string,mixed>,list<string>,list<string>}> */
    public static function annualSettlementPeriods(): iterable
    {
        yield 'leden: nepožádáno a neprovedeno' => [
            '2026-01-01',
            '2026-01-31',
            'not_requested',
            null,
            [
                '<form:prijemSrazkDanZvlSazba>1250</form:prijemSrazkDanZvlSazba>',
                '<form:danSrazenaZvlSazba>180</form:danSrazenaZvlSazba>',
                '<form:rocniZuctovaniZadost>false</form:rocniZuctovaniZadost>',
                '<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno>',
            ],
            ['<form:vysledekRocnihoZuctovani>'],
        ];
        yield 'únor: požádáno a dosud neprovedeno' => [
            '2026-02-01',
            '2026-02-28',
            'requested',
            null,
            [
                '<form:rocniZuctovaniZadost>true</form:rocniZuctovaniZadost>',
                '<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno>',
            ],
            [
                '<form:prijemSrazkDanZvlSazba>',
                '<form:vysledekRocnihoZuctovani>',
            ],
        ];
        yield 'březen: požádáno, dosud neprovedeno' => [
            '2026-03-01',
            '2026-03-31',
            'requested',
            null,
            ['<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno>'],
            [
                '<form:rocniZuctovaniZadost>',
                '<form:vysledekRocnihoZuctovani>',
            ],
        ];
    }

    public function testPerformedSettlementUsesCompleteAnnualSourcesAndSignedBonus(): void
    {
        $payload = $this->payloadForPeriod('2026-02-01', '2026-02-28');
        $payload['people'][0]['annual_evidence'] = [
            'tax_year' => 2025,
            'request' => [
                'id' => 701,
                'row_version' => 1,
                'status' => 'requested',
                'requested_on' => '2026-02-10',
                'annual_claims' => 'none',
                'evidence_sha256' => str_repeat('8', 64),
            ],
            'request_evidence' => [
                'present' => true,
                'proof' => 'verified_request_row_under_unique_key_lock',
                'supplier_id' => 7,
                'employee_id' => 11,
                'tax_year' => 2025,
            ],
            'settlement' => [
                'revision_id' => 802,
                'snapshot_hash' => str_repeat('a', 64),
                'settled_on' => '2026-02-16',
                'performed' => true,
                'tax_difference_minor_units' => 12_300,
                'bonus_difference_minor_units' => -2_300,
                'settlement_difference_minor_units' => 10_000,
                'credit_rows' => [],
                'child_rows' => [],
            ],
            'settlement_evidence' => [
                'performed' => true,
                'proof' => 'verified_annual_outcome_and_document_revision',
                'supplier_id' => 7,
                'employee_id' => 11,
                'tax_year' => 2025,
            ],
            'withholding_certificate' => null,
        ];

        $resolution = $this->resolutionFor(
            $this->pvpoj(period: '2026-02'),
            $payload,
            periodStart: '2026-02-01',
            periodEnd: '2026-02-28',
        );
        self::assertSame('resolved', $resolution->status());
        $codes = array_map(
            static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
            $resolution->blockers,
        );
        self::assertNotContains('jmhz_annual_settlement_request_source_inconsistent', $codes);
        self::assertNotContains('jmhz_annual_settlement_child_details_unsupported', $codes);
        self::assertNotContains('jmhz_scenario1_annual_fields_unsupported', $codes);

        $created = $this->bridge($resolution, '2026-03-05 Europe/Prague')->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );
        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $created['artifact_id'],
        );
        self::assertStringContainsString('<form:preplatekRok>100</form:preplatekRok>', $xml);
        self::assertStringContainsString('<form:danPreplatekRok>123</form:danPreplatekRok>', $xml);
        self::assertStringContainsString(
            '<form:danBonusPreplatekRok>-23</form:danBonusPreplatekRok>',
            $xml,
        );

        $payload['people'][0]['annual_evidence']['settlement']['child_rows'] = [[
            'label' => '1. dítě',
            'child_reference' => 'dependant-91',
            'given_name' => 'Anna',
            'family_name' => 'Syntetická',
            'birth_date' => '2018-04-12',
            'birth_number' => null,
            'months' => 12,
            'ztp_p_months' => 0,
            'ztp_p_months_mask' => 'NNNNNNNNNNNN',
            'order_months_mask' => '111111111111',
            'other_household_caregiver' => false,
            'amount_minor_units' => 152_040_00,
        ]];
        $childResolution = $this->resolutionFor(
            $this->pvpoj(period: '2026-02'),
            $payload,
            periodStart: '2026-02-01',
            periodEnd: '2026-02-28',
        );
        self::assertSame('resolved', $childResolution->status());
        self::assertNotContains(
            'jmhz_annual_settlement_child_details_unsupported',
            array_map(
                static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
                $childResolution->blockers,
            ),
        );
        self::assertInstanceOf(
            JmhzScenario1NormalizedDocument::class,
            $childResolution->candidate,
        );
        $childXml = (new JmhzScenario1XmlSerializer())->serialize(
            $childResolution->candidate,
            JmhzSubmissionEnvelope::create(
                '019A0000-0000-7000-8000-000000000101',
                [101 => '019A0000-0000-7000-8000-000000000102'],
                '2026-03-06T09:00:00Z',
                'MyÚčto.cz',
                'test',
            ),
        );
        self::assertStringContainsString(
            '<form:uplatnenoZvyhodneniNaDeti>true</form:uplatnenoZvyhodneniNaDeti>',
            $childXml,
        );
        self::assertStringContainsString(
            '<form:vyzivujeJinaOsoba>false</form:vyzivujeJinaOsoba>',
            $childXml,
        );
        self::assertStringContainsString('<form:jmeno>Anna</form:jmeno>', $childXml);
        self::assertStringContainsString(
            '<form:prijmeni>Syntetická</form:prijmeni>',
            $childXml,
        );
        self::assertStringContainsString(
            '<form:datumNarozeni>2018-04-12</form:datumNarozeni>',
            $childXml,
        );
        self::assertStringContainsString(
            '<form:poradi>111111111111</form:poradi>',
            $childXml,
        );
    }

    public function testDecemberUsesSpecificSourceBlockersInsteadOfBlanketAnnualBlocker(): void
    {
        $payload = $this->payloadForPeriod('2026-12-01', '2026-12-31');
        $resolution = $this->resolutionFor(
            $this->pvpoj(period: '2026-12'),
            $payload,
            periodStart: '2026-12-01',
            periodEnd: '2026-12-31',
        );

        self::assertSame('blocked', $resolution->status());
        $codes = array_map(
            static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
            $resolution->blockers,
        );
        self::assertNotContains('jmhz_scenario1_annual_fields_unsupported', $codes);
        self::assertContains('jmhz_december_collective_agreement_source_missing', $codes);
        self::assertContains('jmhz_december_ownership_form_source_missing', $codes);
        self::assertContains('jmhz_december_ozp_annual_source_missing', $codes);
    }

    public function testDecemberSerializesFrozenEmployerAnnualEvidence(): void
    {
        $payload = $this->payloadForPeriod('2026-12-01', '2026-12-31');
        $payload['employer_annual_evidence'] = [
            'schema_reference' => 'payroll-jmhz-employer-annual-evidence.v1',
            'id' => 901,
            'revision_no' => 2,
            'report_year' => 2026,
            'collective_agreement_types' => ['1', '3'],
            'ownership_form' => '2',
            'average_headcount_hundredths' => 2_675,
            'average_disabled_headcount_hundredths' => 134,
            'disabled_share_hundredths' => 501,
            'ozp_reporting_office_id' => null,
            'source_reference_sha256' => str_repeat('9', 64),
        ];

        $resolution = $this->resolutionFor(
            $this->pvpoj(period: '2026-12'),
            $payload,
            periodStart: '2026-12-01',
            periodEnd: '2026-12-31',
        );

        self::assertSame('resolved', $resolution->status());
        $created = $this->bridge($resolution, '2027-01-05 Europe/Prague')->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );
        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $created['artifact_id'],
        );
        self::assertStringContainsString(
            '<so:formaVlastnictvi>2</so:formaVlastnictvi>',
            $xml,
        );
        self::assertStringContainsString(
            '<so:zecPocetPrepRok>26.75</so:zecPocetPrepRok>',
            $xml,
        );
        self::assertStringContainsString(
            '<so:zecPocetPrepOzpRok>1.34</so:zecPocetPrepOzpRok>',
            $xml,
        );
        self::assertStringContainsString(
            '<so:podilZamZtp>5.01</so:podilZamZtp>',
            $xml,
        );
        self::assertSame(2, substr_count($xml, '<so:kolektivniSmlouva>'));
        self::assertStringContainsString(
            '<so:typKolektSmlouvy>1</so:typKolektSmlouvy>',
            $xml,
        );
        self::assertStringContainsString(
            '<so:typKolektSmlouvy>3</so:typKolektSmlouvy>',
            $xml,
        );
    }

    public function testContentCorrectionFreezesFullAcceptedFormWithSameGuidAndReplaysImmutableArtifact(): void
    {
        $resolution = $this->resolutionWithEmployeeName('Jana Syntetická');
        $original = $this->bridge($resolution)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalXml = $this->submissions->artifactBytes(
            $this->supplierId,
            $original['artifact_id'],
        );
        $formGuid = $this->firstFormGuid($originalXml);
        $this->acceptWithFormOutcome($original, $formGuid, 'accepted');

        $service = $this->contentCorrections($resolution);
        $candidates = $service->candidates(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
        );
        self::assertCount(1, $candidates['forms']);
        self::assertSame('correct_values', $candidates['forms'][0]['action']);
        self::assertSame('Jana Syntetická', $candidates['forms'][0]['employee_name']);
        $employment = (string) $candidates['forms'][0]['employment_external_identifier'];

        $correction = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            [$employment],
            $this->userId,
        );
        self::assertTrue($correction['created']);
        self::assertSame('ready', $correction['status']);
        self::assertSame('correction', $correction['submission_kind']);
        self::assertSame($original['submission_id'], $correction['corrects_submission_id']);
        $correctionXml = $this->submissions->artifactBytes(
            $this->supplierId,
            $correction['artifact_id'],
        );
        self::assertStringContainsString('<typPodani>O</typPodani>', $correctionXml);
        self::assertStringContainsString('<typFormulare>O</typFormulare>', $correctionXml);
        self::assertSame($formGuid, $this->firstFormGuid($correctionXml));
        self::assertStringContainsString('<form:bezPriznaku', $correctionXml);
        self::assertStringContainsString('<so:souhrn>', $correctionXml);
        self::assertStringContainsString('<pvpoj:PVPOJ>', $correctionXml);

        $replayed = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            [$employment],
            $this->userId,
        );
        self::assertFalse($replayed['created']);
        self::assertSame($correction['submission_id'], $replayed['submission_id']);
        self::assertSame($correction['artifact_id'], $replayed['artifact_id']);
        self::assertSame(
            $correctionXml,
            $this->submissions->artifactBytes($this->supplierId, $replayed['artifact_id']),
        );
    }

    public function testContentCorrectionAddsMissingFormWithNewGuidAndWholeCompanyPvpoj(): void
    {
        $original = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalGuid = $this->firstFormGuid($this->submissions->artifactBytes(
            $this->supplierId,
            $original['artifact_id'],
        ));
        $this->acceptWithFormOutcome($original, $originalGuid, 'accepted');

        $service = $this->contentCorrections($this->resolutionWithSecondPerson());
        $candidates = $service->candidates(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
        );
        self::assertCount(2, $candidates['forms']);
        $missing = array_values(array_filter(
            $candidates['forms'],
            static fn (array $form): bool => $form['action'] === 'complete_form',
        ));
        self::assertCount(1, $missing);

        $correction = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            [(string) $missing[0]['employment_external_identifier']],
            $this->userId,
        );
        $xml = $this->submissions->artifactBytes($this->supplierId, $correction['artifact_id']);
        self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
        self::assertStringContainsString('<typFormulare>R</typFormulare>', $xml);
        self::assertNotSame($originalGuid, $this->firstFormGuid($xml));
        self::assertMatchesRegularExpression(
            '/<idFormulare>[0-9A-F]{8}-[0-9A-F]{4}-7[0-9A-F]{3}-[0-9A-F]{4}-[0-9A-F]{12}<\/idFormulare>/',
            $xml,
        );
        self::assertStringContainsString('<pvpoj:pojistneZamestnavateleCelkem>496</pvpoj:pojistneZamestnavateleCelkem>', $xml);
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>142</pvpoj:pojistneZamestnance>', $xml);
        self::assertStringContainsString('<pvpoj:pojistneCelkem>638</pvpoj:pojistneCelkem>', $xml);
        self::assertStringContainsString('<form:idPpv>2000000000000000000002</form:idPpv>', $xml);
        self::assertStringNotContainsString('<form:idPpv>2000000000000000000001</form:idPpv>', $xml);
    }

    /**
     * Pravidla podání JMHZ 1.4.5, kap. 3: obsahová oprava víc součástí, než
     * pojme balík, se zmrazí jako dílčí balíky jednoho podání se souhrnem
     * a pojistnou částí jen v prvním. Limit je snížený na 1, aby šly dva
     * balíky postavit ze dvou vztahů.
     */
    public function testContentCorrectionAboveThePackageLimitIsFrozenAsPackages(): void
    {
        $original = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalGuid = $this->firstFormGuid($this->submissions->artifactBytes(
            $this->supplierId,
            $original['artifact_id'],
        ));
        $this->acceptWithFormOutcome($original, $originalGuid, 'accepted');

        $service = $this->contentCorrections($this->resolutionWithSecondPerson(), 1);
        $candidates = $service->candidates(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
        );
        self::assertCount(2, $candidates['forms']);

        $correction = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            array_map(
                static fn (array $form): string => (string) $form['employment_external_identifier'],
                $candidates['forms'],
            ),
            $this->userId,
        );

        $packages = $this->submissionRepository->listPackageOutboundXmlArtifacts(
            $this->supplierId,
            self::ENVIRONMENT,
            $correction['submission_id'],
        );
        self::assertSame([1, 2], array_column($packages, 'ordinal'));
        self::assertSame($packages[0]['artifact_id'], $correction['artifact_id']);
        $first = $this->submissions->artifactBytes($this->supplierId, $packages[0]['artifact_id']);
        $second = $this->submissions->artifactBytes($this->supplierId, $packages[1]['artifact_id']);
        foreach ([$first, $second] as $xml) {
            self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
            self::assertStringContainsString('<balikyPocet>2</balikyPocet>', $xml);
            self::assertStringContainsString('<formularePocetCelkem>4</formularePocetCelkem>', $xml);
        }
        self::assertStringContainsString('<formularePocetVBaliku>3</formularePocetVBaliku>', $first);
        self::assertStringContainsString('<pvpoj:PVPOJ>', $first);
        self::assertStringContainsString('<formularePocetVBaliku>1</formularePocetVBaliku>', $second);
        self::assertStringNotContainsString('<pvpoj:PVPOJ>', $second);
    }

    /**
     * Hlášení za 01/2027 se řídí specifikací 2027. Dokud její balík není
     * připnutý, nezmrazí se nic a nevznikne ani povinnost; účetní dostane
     * kód blokace, ne chybu serveru.
     */
    public function testJanuary2027ReportIsBlockedUntilSpecification2027IsPinned(): void
    {
        $resolution = $this->resolutionFor(
            $this->pvpoj(period: '2027-01'),
            $this->payloadForPeriod('2027-01-01', '2027-01-31'),
            periodStart: '2027-01-01',
            periodEnd: '2027-01-31',
        );

        try {
            $this->bridge($resolution, '2027-02-05 10:00:00 Europe/Prague')->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                null,
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Hlášení za 01/2027 se nesmí sestavit verzí 2026.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $exception->validationCode);
            self::assertSame('conflict', JmhzSubmissionBridgeService::clientErrorCode($exception));
        }
        self::assertSame(0, $this->countRows('payroll_obligations'));
        self::assertSame(0, $this->countRows('payroll_submissions'));
    }

    /**
     * Opravné hlášení za 11/2026 podané v březnu 2027 jde dál verzí 2026:
     * rozhoduje vykazované období, ne den odeslání.
     */
    public function testNovember2026CorrectionFrozenInMarch2027StaysOnVersion2026(): void
    {
        $resolution = $this->resolutionFor(
            $this->pvpoj(period: '2026-11'),
            $this->payloadForPeriod('2026-11-01', '2026-11-30'),
            periodStart: '2026-11-01',
            periodEnd: '2026-11-30',
        );
        // Platforma podání běží v březnu 2027; řádné hlášení vzniklo v prosinci.
        $this->submissions = new PayrollSubmissionService(
            $this->submissionRepository,
            new PayrollSubmissionStateMachine(),
            new SecretEncryption($this->config),
            new MockClock('2027-03-10 10:00:00 Europe/Prague'),
        );
        $original = $this->bridge($resolution, '2026-12-05 10:00:00 Europe/Prague')->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalXml = $this->submissions->artifactBytes($this->supplierId, $original['artifact_id']);
        $this->acceptWithFormOutcome($original, $this->firstFormGuid($originalXml), 'accepted');

        $service = $this->contentCorrections($resolution, now: '2027-03-10 10:00:00 Europe/Prague');
        $candidates = $service->candidates(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
        );
        $correction = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            [(string) $candidates['forms'][0]['employment_external_identifier']],
            $this->userId,
        );

        $xml = $this->submissions->artifactBytes($this->supplierId, $correction['artifact_id']);
        $identity = JmhzFrozenSubmissionIdentity::read($xml);
        self::assertSame('O', $identity->submissionType);
        self::assertSame([2026, 11], [$identity->year, $identity->month]);
        self::assertSame(JmhzSpecVersion::V2026, $identity->specVersion);
        self::assertStringContainsString(JmhzSchemaCatalog::NS_PODANI, $xml);
        self::assertSame(
            'JMHZ25',
            JmhzGovTalkRequestShape::forPayload('CSSZ_JMHZ', $xml)->bodyEnvelopeType,
        );
    }

    public function testDecemberCorrectionObligationUsesFollowingJanuaryDueYear(): void
    {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturn($this->resolution());
        $frozen = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $service = new JmhzContentCorrectionSubmissionService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(
                CzechPayrollRulesets2026::provider(),
            ),
            new JmhzSubmissionGuidFactory(),
            new JmhzEffectiveFormLedgerResolver($this->submissionRepository, $frozen),
            $frozen,
            $this->preparations,
            $this->people,
            $this->submissionRepository,
            $this->submissions,
            $this->obligations,
            new MockClock('2037-12-31 11:30:00 Europe/Prague'),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new JmhzDeferralRepository($this->db),
        );
        $method = new \ReflectionMethod($service, 'correctionObligation');
        $method->invoke(
            $service,
            $this->supplierId,
            self::ENVIRONMENT,
            [
                'agenda_code' => JmhzSubmissionBridgeService::AGENDA_CODE,
                'subject_type' => 'payroll_run',
                'subject_reference' => 'payroll_run:' . self::RUN_ID,
                'period_start' => '2026-12-01',
                'period_end' => '2026-12-31',
            ],
            9001,
            self::PREPARATION_ID,
            str_repeat('a', 64),
            $this->userId,
        );

        $deadline = $this->row(
            'SELECT deadline.due_on
               FROM payroll_submission_deadlines deadline
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = deadline.supplier_id
                AND obligation.environment = deadline.environment
                AND obligation.id = deadline.obligation_id
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.obligation_kind = ?',
            [$this->supplierId, self::ENVIRONMENT, 'correction'],
        );
        self::assertSame('2037-12-31', $deadline['due_on']);
    }

    public function testRegistersMissingRegularObligationDuringFreeze(): void
    {
        $result = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            null,
            self::ENVIRONMENT,
            $this->userId,
        );

        self::assertTrue($result['created']);
        self::assertSame('ready', $result['status']);
        self::assertSame(1, $this->countRows('payroll_obligations'));
        $obligation = $this->row(
            'SELECT agenda_code, subject_type, subject_reference,
                    period_start, period_end, obligation_kind,
                    preferred_channel, status
               FROM payroll_obligations
              WHERE supplier_id = ?',
            [$this->supplierId],
        );
        self::assertSame(JmhzSubmissionBridgeService::AGENDA_CODE, $obligation['agenda_code']);
        self::assertSame('payroll_run', $obligation['subject_type']);
        self::assertSame('payroll_run:' . self::RUN_ID, $obligation['subject_reference']);
        self::assertSame(self::PERIOD_START, $obligation['period_start']);
        self::assertSame(self::PERIOD_END, $obligation['period_end']);
        self::assertSame('regular', $obligation['obligation_kind']);
        self::assertSame('vrep_apep', $obligation['preferred_channel']);
        self::assertSame('prepared', $obligation['status']);
    }

    /**
     * Jádro celé vrstvy. Opakované volání nesmí XML postavit znovu — nové GUIDy
     * by pod tímtéž podáním vyrobily jiný dokument a duplicitu přijatého podání
     * u ČSSZ vzít zpět nelze. Proto se porovnávají BAJTY, ne jen identifikátory.
     */
    public function testReplayReturnsTheIdenticalFrozenBytesAndGuids(): void
    {
        $obligationId = $this->registerObligation();
        $bridge = $this->bridge();

        $created = $bridge->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );
        $frozenBytes = $this->submissions->artifactBytes(
            $this->supplierId,
            $created['artifact_id'],
        );
        $replayed = $bridge->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );

        self::assertTrue($created['created']);
        self::assertFalse($replayed['created']);
        self::assertSame($created['submission_id'], $replayed['submission_id']);
        self::assertSame($created['part_id'], $replayed['part_id']);
        self::assertSame($created['artifact_id'], $replayed['artifact_id']);
        self::assertSame('ready', $replayed['status']);
        self::assertSame(
            $created['submission_guid'],
            $replayed['submission_guid'],
        );
        self::assertSame(
            $created['variable_symbol'],
            $replayed['variable_symbol'],
        );
        self::assertSame(
            $created['artifact_sha256'],
            $replayed['artifact_sha256'],
        );

        // Bajtová shoda, ne jen shoda otisků: kdyby opakování XML postavilo
        // znovu, mělo by jiné GUIDy a tady by se to projevilo.
        $replayedBytes = $this->submissions->artifactBytes(
            $this->supplierId,
            $replayed['artifact_id'],
        );
        self::assertSame($frozenBytes, $replayedBytes);
        self::assertSame(
            hash('sha256', $replayedBytes),
            $replayed['artifact_sha256'],
        );
        self::assertStringContainsString(
            "<idPodani>{$replayed['submission_guid']}</idPodani>",
            $replayedBytes,
        );
        self::assertSame(
            1,
            $this->countRows('payroll_submissions'),
            'Idempotentní opakování nesmí založit druhé podání.',
        );
        self::assertSame(1, $this->countRows('payroll_submission_parts'));
        self::assertSame(1, $this->countRows('payroll_submission_artifacts'));
    }

    /**
     * NÁLEZ: zahození odeslání po zamítnutí zpracováním vracelo podání na
     * `ready` se stejným GUID podání. Řádné podání přitom dostává nový GUID
     * i po zamítnutí; stejné R se stejným GUID odmítne kontrola 22 (20022).
     *
     * Celý tok: zmrazení → odeslání → protokol „zamítnuto" → zahození → nové
     * zmrazení s novým GUID podání i součástí, stejným VS a obdobím. Návazné
     * čtení (odeslání, opravné i stornovací podání) bere NOVÝ dokument.
     */
    public function testAbandonAfterRejectionRefreezesTheRegularSubmissionWithNewGuids(): void
    {
        $created = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $reader = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $originalForms = $reader->formGuids($this->supplierId, self::ENVIRONMENT, $created['submission_id']);
        $rejected = $this->rejectAfterSending($created['submission_id'], $created['row_version']);

        $result = $this->abandonService()->abandon(
            $this->supplierId,
            self::ENVIRONMENT,
            $created['submission_id'],
            $rejected,
            'ČSSZ podání zamítla, příčina je vyřízená.',
            $this->userId,
        );

        self::assertSame('ready', $result['submission']['status']);
        self::assertIsArray($result['refreeze']);
        self::assertTrue($result['refreeze']['refrozen']);
        self::assertSame($created['submission_guid'], $result['refreeze']['previous_submission_guid']);
        self::assertNotSame($created['submission_guid'], $result['refreeze']['submission_guid']);

        $identity = $reader->identity($this->supplierId, self::ENVIRONMENT, $created['submission_id']);
        self::assertSame($result['refreeze']['submission_guid'], $identity->submissionGuid);
        self::assertSame($created['variable_symbol'], $identity->variableSymbol);
        self::assertSame(7, $identity->month);
        self::assertSame(2026, $identity->year);
        $renewedForms = $reader->formGuids($this->supplierId, self::ENVIRONMENT, $created['submission_id']);
        self::assertCount(count($originalForms), $renewedForms);
        self::assertSame([], array_intersect($originalForms, $renewedForms));

        // Původní dokument zůstává v archivu jako doklad prvního odeslání.
        self::assertStringContainsString(
            "<idPodani>{$created['submission_guid']}</idPodani>",
            $this->submissions->artifactBytes($this->supplierId, $created['artifact_id']),
        );
        self::assertSame('2', (string) $this->row(
            'SELECT COUNT(*) AS c FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ? AND artifact_kind = "outbound_xml"',
            [$this->supplierId, $created['submission_id']],
        )['c']);
    }

    /**
     * Zahození BEZ výsledku zpracování (odesláno, protokol nepřišel) GUID
     * nemění: kdyby originál u ČSSZ přece jen byl, opakování se stejným GUID
     * ohlásí kontrola 22, místo aby s novým GUID vznikla duplicita.
     */
    public function testAbandonWithoutProcessingResultKeepsTheGuid(): void
    {
        $created = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $created['submission_id'],
            $created['row_version'],
            'submitted',
            'synthetic-correlation-kept',
        );

        $result = $this->abandonService()->abandon(
            $this->supplierId,
            self::ENVIRONMENT,
            $created['submission_id'],
            $submitted['row_version'],
            'Protokol nepřišel, posíláme znovu.',
            $this->userId,
        );

        self::assertNull($result['refreeze']);
        $reader = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        self::assertSame(
            $created['submission_guid'],
            $reader->identity($this->supplierId, self::ENVIRONMENT, $created['submission_id'])->submissionGuid,
        );
        self::assertSame(1, $this->countRows('payroll_submission_artifacts'));
    }

    /**
     * NÁLEZ: vypršený čas po odeslání požadavku se zapisoval jako `failed`
     * bez `sent_at`, a brána tak pustila opakování, jako by nic neodešlo.
     *
     * Tok nad skutečnou databází: pokus „možná doručeno" podání z nabídky
     * k odeslání vyřadí; potvrzení opakování je odmítnuté, dokud je načtený
     * protokol se stejným GUID (originál je u ČSSZ); bez něj se opakování
     * potvrdí a podání se vrátí do nabídky se STEJNÝM zmrazeným dokumentem.
     */
    public function testPossiblyDeliveredAttemptBlocksRetryUntilExplicitlyConfirmed(): void
    {
        $created = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $attempts = new PayrollSubmissionTransportAttemptRepository($this->db);
        $attempt = $attempts->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $created['submission_id'],
            'vrep_apep',
            1,
            'synthetic-possibly-delivered-' . $created['submission_id'],
            $created['artifact_sha256'],
            $this->userId,
        );
        $attempts->markPossiblyDelivered(
            (int) $attempt['id'],
            'jmhz_vrep_response_lost',
            'Požadavek odešel, odpověď nedorazila.',
            null,
            null,
            (int) $attempt['row_version'],
        );
        $ready = fn (): array => array_column(
            $attempts->listReadySubmissions(
                $this->supplierId,
                self::ENVIRONMENT,
                [JmhzSubmissionBridgeService::AGENDA_CODE],
            ),
            'submission_id',
        );
        self::assertNotContains($created['submission_id'], $ready(), 'Možná doručené podání nesmí jít odeslat.');

        $protocols = new PayrollImportedJmhzProtocolRepository($this->db);
        $confirmation = new PayrollSubmissionRetryConfirmationService(
            $attempts,
            $protocols,
            new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions),
        );
        $savepoint = 'possibly_delivered_protocol';
        $this->db->pdo()->exec('SAVEPOINT ' . $savepoint);
        $protocols->store($this->supplierId, self::ENVIRONMENT, [
            'protocol_kind' => 'processing',
            'variable_symbol' => $created['variable_symbol'],
            'period_month' => 7,
            'period_year' => 2026,
            'submission_guid' => $created['submission_guid'],
            'correlation_reference' => null,
            'status_code' => 1,
            'status_name' => 'ProcessedAndComplete',
            'error_count' => 0,
            'protocol_dated_at' => null,
            'submitted_at' => null,
            'source_filename' => 'synthetic-protocol.xml',
            'payload_sha256' => str_repeat('e', 64),
            'payload_xml' => '<protokol/>',
            'dedupe_key' => str_repeat('e', 64),
        ], $this->userId);
        try {
            $confirmation->confirm($this->supplierId, self::ENVIRONMENT, $created['submission_id'], 'Protokol nenalezen.');
            self::fail('Načtený protokol se stejným GUID dokládá originál u ČSSZ; opakování se musí odmítnout.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('originál je tedy u ČSSZ', $exception->getMessage());
        }
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        self::assertNotContains($created['submission_id'], $ready());

        $confirmed = $confirmation->confirm(
            $this->supplierId,
            self::ENVIRONMENT,
            $created['submission_id'],
            'V datové schránce ani na portálu ČSSZ protokol není.',
        );

        self::assertSame([(int) $attempt['id']], $confirmed['confirmed_attempts']);
        self::assertSame($created['submission_guid'], $confirmed['submission_guid']);
        self::assertContains($created['submission_id'], $ready());
        $reader = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        self::assertSame(
            $created['submission_guid'],
            $reader->identity($this->supplierId, self::ENVIRONMENT, $created['submission_id'])->submissionGuid,
            'Opakuje se tentýž dokument se stejným GUID.',
        );
    }

    private function rejectAfterSending(int $submissionId, int $rowVersion): int
    {
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submissionId,
            $rowVersion,
            'submitted',
            'synthetic-correlation-rejected',
        );
        $verifier = new class implements PayrollReceiptVerifierInterface {
            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt('rejected', $expectedCorrelationReference);
            }
        };
        $rejected = $this->submissions->importReceipt(
            $this->supplierId,
            $submissionId,
            $submitted['row_version'],
            null,
            '<receipt status="rejected"/>',
            'synthetic-receipt-rejected',
            'synthetic-correlation-rejected',
            'CSSZ_JMHZ',
            'rejected',
            'vrep_apep',
            'synthetic-idempotency-rejected',
            null,
            $verifier,
        );
        self::assertSame('rejected', $rejected['submission_status']);

        return (int) $rejected['submission_row_version'];
    }

    private function abandonService(): PayrollSubmissionAbandonService
    {
        return new PayrollSubmissionAbandonService(
            $this->submissions,
            new PayrollSubmissionTransportAttemptRepository($this->db),
            $this->submissionRepository,
            new JmhzRejectedSubmissionRefreezeService(
                new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions),
                $this->submissionRepository,
                $this->submissions,
                new JmhzSubmissionGuidFactory(),
                new JmhzScenario1XmlValidator(),
                new MockClock('2026-08-25 09:00:00 Europe/Prague'),
            ),
        );
    }

    public function testStoredXmlValidatesAgainstPinnedSchema(): void
    {
        $result = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );

        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $result['artifact_id'],
        );
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $dom->schemaValidate(
            (new JmhzSchemaCatalog())->entryPoint()['path'],
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode('; ', array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            $errors,
        )));
    }

    public function testBlockedPreparationFreezesNothing(): void
    {
        $obligationId = $this->registerObligation();
        $bridge = $this->bridge(new JmhzScenario1Resolution(null, [
            new JmhzScenario1Blocker(
                'jmhz_taxpayer_declaration_unresolved',
                'person',
                11,
                ['10419'],
            ),
        ]));

        try {
            $bridge->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                $obligationId,
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Blokovaná příprava nesmí založit podání.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_submission_preparation_blocked',
                $exception->validationCode,
            );
            self::assertStringContainsString(
                'Není doloženo prohlášení poplatníka.',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'Mzdy → Zaměstnanci',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'jmhz_taxpayer_declaration_unresolved',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'person 11',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $this->countRows('payroll_submissions'));
        self::assertSame(0, $this->countRows('payroll_submission_artifacts'));
    }

    public function testBlockedPreparationCarriesStructuredBlockersWithPath(): void
    {
        $bridge = $this->bridge(new JmhzScenario1Resolution(null, [
            new JmhzScenario1Blocker('jmhz_annual_request_source_missing', 'person', 11, ['10319']),
            new JmhzScenario1Blocker('jmhz_average_hourly_earning_missing', 'employment', 22),
        ]));

        try {
            $bridge->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                $this->registerObligation(),
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Blokovaná příprava nesmí založit podání.');
        } catch (JmhzXmlException $exception) {
            self::assertCount(2, $exception->blockers);
            [$annual, $average] = $exception->blockers;
            self::assertSame('jmhz_annual_request_source_missing', $annual['code']);
            self::assertSame('/payroll/annual-settlement?state=request_undecided&person=11', $annual['remediation_path']);
            self::assertArrayHasKey('label', $annual);
            self::assertSame(
                '/payroll/absences?employment=22&tab=averages',
                $average['remediation_path'],
            );
            self::assertSame('Chybí ověřený průměrný hodinový výdělek.', $average['reason']);
        }
    }

    /**
     * XSD by tohle podání pustilo — rozvaha PVPOJ nesedí až na úrovni katalogu
     * kontrol. Zmrazit ho by znamenalo jen odsunout zamítnutí blíž ke lhůtě.
     */
    public function testSubmissionFailingControlsFreezesNothing(): void
    {
        $obligationId = $this->registerObligation();
        $bridge = $this->bridge($this->resolutionWithBrokenPvpoj());

        try {
            $bridge->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                $obligationId,
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Podání neprošlé kontrolami nesmí vzniknout.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_submission_controls_failed',
                $exception->validationCode,
            );
        }

        self::assertSame(0, $this->countRows('payroll_submissions'));
        self::assertSame(0, $this->countRows('payroll_submission_parts'));
        self::assertSame(0, $this->countRows('payroll_submission_artifacts'));
    }

    public function testObligationOfAnotherScopeIsRefused(): void
    {
        $foreign = $this->obligations->register(
            $this->supplierId,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run',
            'payroll_run:999',
            self::PERIOD_START,
            self::PERIOD_END,
            'regular',
            'vrep_apep',
            JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
            JmhzSubmissionBridgeService::sourceEventReference(
                self::PREPARATION_ID,
            ),
            self::SNAPSHOT_HASH,
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz25-deadline-test',
            str_repeat('d', 64),
            'jmhz-bridge-obligation-foreign',
            null,
            $this->userId,
            null,
            self::ENVIRONMENT,
        );

        try {
            $this->bridge()->bridge(
                $this->supplierId,
                self::PREPARATION_ID,
                (int) $foreign['id'],
                self::ENVIRONMENT,
                $this->userId,
            );
            self::fail('Povinnost jiného mzdového běhu musí selhat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_submission_obligation_scope_mismatch',
                $exception->validationCode,
            );
        }

        self::assertSame(0, $this->countRows('payroll_submissions'));
    }

    /**
     * Dvě registrace u OSSZ = dvě podání.
     *
     * Zmrazený GUID je jednorázový: duplicitu přijatého podání nelze u ČSSZ
     * vzít zpět. Idempotence proto musí být PER REGISTRACI — dokud byla per
     * revizi, druhá účtárna dostala idempotentní odpověď první, tedy cizí GUID
     * i cizí variabilní symbol, místo vlastního podání.
     */
    public function testEachRegistrationFreezesItsOwnSubmissionAndGuid(): void
    {
        $first = $this->officeBridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(4),
            self::ENVIRONMENT,
            $this->userId,
            4,
        );
        $second = $this->officeBridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(5),
            self::ENVIRONMENT,
            $this->userId,
            5,
        );

        self::assertTrue($first['created']);
        self::assertTrue($second['created']);
        self::assertNotSame($first['submission_id'], $second['submission_id']);
        self::assertNotSame($first['submission_guid'], $second['submission_guid']);
        self::assertSame('1101234563', $first['variable_symbol']);
        self::assertSame('1100001237', $second['variable_symbol']);

        // Opakování TÉŽE registrace naopak musí vrátit původní podání i GUID.
        $replay = $this->officeBridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(4),
            self::ENVIRONMENT,
            $this->userId,
            4,
        );
        self::assertFalse($replay['created']);
        self::assertSame($first['submission_id'], $replay['submission_id']);
        self::assertSame($first['submission_guid'], $replay['submission_guid']);
        self::assertSame($first['artifact_sha256'], $replay['artifact_sha256']);
    }

    /**
     * Víceúčtárenský běh dojde až ke zmrazení podání i s ordinary evidencí.
     *
     * Tohle je smysl celé změny: revize přes dvě účtárny má vždy ≥2 pracovní
     * vztahy, takže dokud se ordinary evidence zmrazovala jen jedna na revizi
     * (a příprava navíc trvala na jediné osobě s jediným vztahem), nebyla
     * taková příprava z reálných dat dosažitelná. Každá registrace teď dostane
     * vlastní osobu s vlastní evidencí a vlastní podání.
     */
    public function testMultiOfficeRunWithPerEmploymentEvidenceReachesFrozenSubmission(): void
    {
        $first = $this->resolutionForOffice(4, '1101234563');
        $second = $this->resolutionForOffice(5, '1100001237');

        self::assertSame([], $first->blockers);
        self::assertSame([], $second->blockers);
        self::assertSame(
            [11],
            array_column($first->candidate?->payload['people'] ?? [], 'employee_id'),
        );
        self::assertSame(
            [12],
            array_column($second->candidate?->payload['people'] ?? [], 'employee_id'),
        );
        // Evidence každé osoby se promítla do jejího řádku, ne z cizí evidence.
        self::assertFalse(
            $first->candidate?->payload['people'][0]['summary']['deductions_recorded'],
        );
        self::assertFalse(
            $second->candidate?->payload['people'][0]['summary']['deductions_recorded'],
        );
        self::assertSame(
            ['IN13' => false, 'IN28' => false, 'IN30' => false, 'IN36' => false],
            $second->candidate?->payload['interactions'],
        );

        $frozen = $this->officeBridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(5),
            self::ENVIRONMENT,
            $this->userId,
            5,
        );
        self::assertTrue($frozen['created']);
        self::assertSame('1100001237', $frozen['variable_symbol']);
    }

    /**
     * Jeden blokovaný zaměstnanec nesmí zastavit hlášení za ostatní.
     *
     * Odložený vztah nemá v řádném hlášení formulář, ale pojistná část je
     * dál za všechny tři zaměstnance: pojistné i sleva se musí uplatnit do
     * splatnosti. Nesoulad pojistné části se součtem podaných formulářů hlásí
     * jen propustné kontroly (12, 7), takže podání vznikne.
     */
    public function testDeferredEmploymentLeavesRegularSubmissionWithRemainingFormsAndWholePvpoj(): void
    {
        $payload = $this->payloadWithPeople(3);
        unset($payload['people'][2]['employments'][0]['earnings_by_attribute_minor']['10330']);
        $pvpoj = $this->pvpoj(employerTotal: 744, people: 3);

        $full = $this->resolutionFor($pvpoj, $payload);
        self::assertSame('blocked', $full->status());
        self::assertSame(
            [['jmhz_scenario1_earnings_vector_incomplete', 'employment', 103]],
            array_map(
                static fn (JmhzScenario1Blocker $blocker): array
                    => [$blocker->code, $blocker->entityType, $blocker->entityId],
                $full->blockers,
            ),
        );
        self::assertTrue($full->blockers[0]->deferrable());

        $partial = $this->resolutionFor(
            $pvpoj,
            $payload,
            exclusion: JmhzFormExclusion::deferral([103], []),
        );
        self::assertSame('resolved', $partial->status());
        $document = $partial->requireResolvedDocument();
        self::assertSame([11, 12], array_column($document->payload['people'], 'employee_id'));
        self::assertSame(2, $document->payload['header']['individual_form_count']);
        self::assertSame(
            ['jmhz_scenario1_earnings_vector_incomplete'],
            array_map(
                static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
                $partial->excludedBlockers,
            ),
        );
        // Souhrn daní zahrne i odloženou osobu - její záloha je spočtená.
        self::assertSame(
            ['advance_tax_after_credits' => 450, 'tax_bonus' => 0],
            $document->payload['employer']['summary_totals'],
        );
        self::assertSame(
            [
                'purpose' => 'deferral',
                'employment_ids' => [103],
                'employee_ids' => [13],
                'deferral_ids' => [],
                'summary_excluded_employee_ids' => [],
            ],
            $document->payload['provenance']['form_exclusion'],
        );

        $frozen = $this->bridge($partial)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        self::assertTrue($frozen['created']);
        $xml = $this->submissions->artifactBytes($this->supplierId, $frozen['artifact_id']);
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertStringNotContainsString('2000000000000000000003', $xml);
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>213</pvpoj:pojistneZamestnance>', $xml);
        self::assertStringContainsString('<pvpoj:zakladZamestnavateleA>3000</pvpoj:zakladZamestnavateleA>', $xml);
        self::assertStringContainsString('<so:danZalohaPoSleve>450</so:danZalohaPoSleve>', $xml);

        $report = JmhzScenario1ControlValidator::create(CzechPayrollRulesets2026::provider())
            ->validate($xml, new JmhzControlContext('2026-08-05', schemaValidated: true));
        self::assertTrue($report->submittable());
        $warnings = array_map(
            static fn ($finding): int => $finding->controlId,
            $report->warnings(),
        );
        self::assertContains(12, $warnings);
        self::assertSame(
            [],
            array_diff($warnings, JmhzScenario1XmlDryRunService::DEFERRAL_EXPECTED_CONTROL_IDS),
            'Odložení smí vyvolat jen propustné kontroly součtu formulářů.',
        );
    }

    /**
     * Orchestrace rozděleného hlášení běží nad dvěma skutečnými balíky. Ostrou
     * hranici 1500/1501 ověřuje JmhzPackageSplitterTest bez výroby 1501 plných
     * mezd; tady malý limit zachová celý databázový, transportní a protokolový
     * tok včetně retry a agregace stavů.
     */
    public function testSplitSubmissionFreezesRetriesAndAggregatesProtocols(): void
    {
        $people = 2;
        $resolution = $this->resolutionFor(
            $this->pvpoj(employerTotal: 248 * $people, people: $people),
            $this->payloadWithPeople($people),
        );
        self::assertSame('resolved', $resolution->status(), CanonicalJson::encode($resolution->blockers));
        $obligationId = $this->registerObligation();

        $frozen = $this->bridge($resolution, packageFormLimit: 1)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );

        self::assertTrue($frozen['created']);
        self::assertSame('ready', $frozen['status']);
        self::assertCount(2, $frozen['packages']);
        self::assertSame([1, 2], array_column($frozen['packages'], 'ordinal'));
        $first = $this->submissions->artifactBytes($this->supplierId, $frozen['packages'][0]['artifact_id']);
        $second = $this->submissions->artifactBytes($this->supplierId, $frozen['packages'][1]['artifact_id']);
        $guid = '<idPodani>' . strtoupper($frozen['submission_guid']) . '</idPodani>';
        self::assertStringContainsString($guid, $first);
        self::assertStringContainsString($guid, $second);
        self::assertStringContainsString('<balikPoradi>1</balikPoradi>', $first);
        self::assertStringContainsString('<balikPoradi>2</balikPoradi>', $second);
        foreach ([$first, $second] as $xml) {
            self::assertStringContainsString('<balikyPocet>2</balikyPocet>', $xml);
            self::assertStringContainsString('<formularePocetCelkem>4</formularePocetCelkem>', $xml);
        }
        self::assertStringContainsString('<formularePocetVBaliku>3</formularePocetVBaliku>', $first);
        self::assertStringContainsString('<formularePocetVBaliku>1</formularePocetVBaliku>', $second);
        self::assertSame(1, substr_count($first, '</formularOsoby>'));
        self::assertSame(1, substr_count($second, '</formularOsoby>'));
        self::assertStringContainsString('<so:souhrn>', $first);
        self::assertStringNotContainsString('<so:souhrn>', $second);
        self::assertStringContainsString('<pvpoj:PVPOJ>', $first);
        self::assertStringNotContainsString('<pvpoj:PVPOJ>', $second);
        self::assertSame(1, preg_match('/<datumVyplneni>([^<]+)<\/datumVyplneni>/', $first, $firstDate));
        self::assertStringContainsString('<datumVyplneni>' . $firstDate[1] . '</datumVyplneni>', $second);

        $reader = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        self::assertCount(2, $reader->formGuids($this->supplierId, self::ENVIRONMENT, $frozen['submission_id']));
        self::assertSame(
            $frozen['submission_guid'],
            $reader->identity($this->supplierId, self::ENVIRONMENT, $frozen['submission_id'])->submissionGuid,
        );
        try {
            $reader->bytes($this->supplierId, self::ENVIRONMENT, $frozen['submission_id']);
            self::fail('Rozdělené hlášení nesmí vydat jen jednu datovou větu.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_submission_split_payload', $exception->validationCode);
        }

        $replayed = $this->bridge($resolution, packageFormLimit: 1)->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $obligationId,
            self::ENVIRONMENT,
            $this->userId,
        );
        self::assertFalse($replayed['created']);
        self::assertSame($frozen['packages'], $replayed['packages']);
        self::assertSame(1, $this->countRows('payroll_submissions'));
        self::assertSame(1, preg_match('/<VENDOR productName="([^"]+)" productVersion="([^"]+)"/', $first, $vendor));
        $this->splitSoftware = new JmhzSoftwareIdentification($vendor[1], $vendor[2]);

        $submissionId = $frozen['submission_id'];
        $ready = $this->readyEntry($submissionId);
        self::assertNotNull($ready);
        self::assertSame(2, $ready['package_count']);
        self::assertSame(0, $ready['packages_sent']);

        $history = [];
        $dispatch = $this->splitDispatch([
            new Response(200, ['Content-Type' => 'text/xml'], self::splitAcknowledgement(self::SPLIT_CORRELATION_1)),
            new ConnectException(
                'cURL error 7: Failed to connect',
                new Request('POST', 'https://t-epodani.cssz.cz/VREP/submission'),
            ),
        ], $history);
        try {
            $this->sendSplit($dispatch, $submissionId, 'split-send-1');
            self::fail('Neodeslaný druhý balík musí odeslání shodit.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_vrep_unavailable', $exception->errorCode);
            self::assertFalse($exception->possiblyDelivered);
        }
        self::assertCount(2, $history);
        self::assertSame('submitted', $this->submissions->get($this->supplierId, $submissionId)['status']);
        $ready = $this->readyEntry($submissionId);
        self::assertNotNull($ready, 'Hlášení s neodeslaným balíkem musí zůstat v nabídce k odeslání.');
        self::assertSame(2, $ready['package_count']);
        self::assertSame(1, $ready['packages_sent']);

        $history = [];
        $dispatch = $this->splitDispatch([
            new Response(500, ['Content-Type' => 'text/html'], 'chyba brány'),
        ], $history);
        try {
            $this->sendSplit($dispatch, $submissionId, 'split-send-2');
            self::fail('Odpověď 5xx musí odeslání shodit.');
        } catch (JmhzTransportException $exception) {
            self::assertTrue($exception->possiblyDelivered);
        }
        self::assertCount(1, $history, 'Odeslaný první balík se nesmí poslat znovu.');
        self::assertNull($this->readyEntry($submissionId), 'Možná doručený balík nesmí jít odeslat znovu.');

        $history = [];
        $dispatch = $this->splitDispatch([], $history);
        try {
            $this->sendSplit($dispatch, $submissionId, 'split-send-3');
            self::fail('Možná doručený balík musí další odeslání zastavit.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Dílčí balík 2 z 2', $exception->getMessage());
        }
        self::assertCount(0, $history);

        $attempts = new PayrollSubmissionTransportAttemptRepository($this->db);
        $confirmation = new PayrollSubmissionRetryConfirmationService(
            $attempts,
            new PayrollImportedJmhzProtocolRepository($this->db),
            new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions),
        );
        $confirmation->confirm(
            $this->supplierId,
            self::ENVIRONMENT,
            $submissionId,
            'V datové schránce ani na portálu ČSSZ protokol k balíku není.',
        );
        $ready = $this->readyEntry($submissionId);
        self::assertNotNull($ready);
        self::assertSame(1, $ready['packages_sent']);

        $history = [];
        $dispatch = $this->splitDispatch([
            new Response(200, ['Content-Type' => 'text/xml'], self::splitAcknowledgement(self::SPLIT_CORRELATION_2)),
        ], $history);
        $outcome = $this->sendSplit($dispatch, $submissionId, 'split-send-4');
        self::assertCount(1, $history);
        self::assertSame(self::SPLIT_CORRELATION_2, $outcome->attempt['correlation_reference']);
        self::assertSame($frozen['packages'][1]['artifact_sha256'], $outcome->attempt['request_sha256']);
        self::assertNull($this->readyEntry($submissionId));
        $byPackage = $this->attemptsByPackage($submissionId, $frozen['packages']);
        self::assertSame(['awaiting_protocol'], $byPackage[1]);
        self::assertSame(['failed', 'expired', 'awaiting_protocol'], $byPackage[2]);

        try {
            $this->sendSplit($dispatch, $submissionId, 'split-send-5');
            self::fail('Po odeslání všech balíků se nesmí odesílat nic dalšího.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Všechny dílčí balíky', $exception->getMessage());
        }
        self::assertCount(1, $history);

        $sent = array_values(array_filter(
            $attempts->listForSubmission($this->supplierId, self::ENVIRONMENT, $submissionId),
            static fn (array $attempt): bool => $attempt['status'] === 'awaiting_protocol',
        ));
        self::assertCount(2, $sent);
        $protocols = $this->protocolFactory();
        $history = [];
        $dispatch = $this->splitDispatch([
            new Response(200, ['Content-Type' => 'text/xml'], $protocols->sign(
                JmhzTransportSample::partialProtocol(correlationId: self::SPLIT_CORRELATION_1),
            )),
            new Response(200, ['Content-Type' => 'text/xml'], $protocols->sign(
                JmhzTransportSample::partialProtocol(correlationId: self::SPLIT_CORRELATION_2),
            )),
        ], $history, $protocols);
        $first = $dispatch->poll($this->supplierId, self::ENVIRONMENT, (int) $sent[0]['id'], '1101234563');
        self::assertTrue($first->isSettled());
        self::assertSame(
            'processing',
            $this->submissions->get($this->supplierId, $submissionId)['status'],
            'Po protokolu prvního balíku čeká hlášení na zbytek.',
        );
        $dispatch->poll($this->supplierId, self::ENVIRONMENT, (int) $sent[1]['id'], '1101234563');
        self::assertSame(
            'accepted',
            $this->submissions->get($this->supplierId, $submissionId)['status'],
            'Přijaté oba balíky = přijaté hlášení.',
        );
        self::assertNull($this->readyEntry($submissionId));
        $timeline = [];
        foreach ($attempts->listRecentPage($this->supplierId, self::ENVIRONMENT)['items'] as $item) {
            $timeline[] = [$item['package_ordinal'], $item['package_count'], $item['status']];
        }
        self::assertSame(
            [[2, 2, 'completed'], [2, 2, 'expired'], [2, 2, 'failed'], [1, 2, 'completed']],
            $timeline,
            'Přehled odeslání ukazuje u každého pokusu jeho balík.',
        );
    }

    private const SPLIT_CORRELATION_1 = 'CCCC9999DDDD0000EEEE1111FFFF0001';
    private const SPLIT_CORRELATION_2 = 'CCCC9999DDDD0000EEEE1111FFFF0002';

    private ?JmhzSignedProtocolFactory $protocolFactory = null;
    private ?JmhzSoftwareIdentification $splitSoftware = null;

    /** @return array<string,mixed>|null */
    private function readyEntry(int $submissionId): ?array
    {
        $ready = (new PayrollSubmissionTransportAttemptRepository($this->db))->listReadySubmissions(
            $this->supplierId,
            self::ENVIRONMENT,
            [JmhzSubmissionBridgeService::AGENDA_CODE],
        );
        foreach ($ready as $row) {
            if ($row['submission_id'] === $submissionId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Stavy pokusů po balících, v pořadí vzniku.
     *
     * @param list<array{ordinal:int,artifact_sha256:string}> $packages
     * @return array<int,list<string>>
     */
    private function attemptsByPackage(int $submissionId, array $packages): array
    {
        $ordinals = array_column($packages, 'ordinal', 'artifact_sha256');
        $result = [];
        foreach ((new PayrollSubmissionTransportAttemptRepository($this->db))
            ->listForSubmission($this->supplierId, self::ENVIRONMENT, $submissionId) as $attempt) {
            $result[$ordinals[$attempt['request_sha256']]][] = (string) $attempt['status'];
        }
        ksort($result);

        return $result;
    }

    private function sendSplit(
        JmhzDispatchService $dispatch,
        int $submissionId,
        string $idempotencyKey,
    ): JmhzDispatchOutcome {
        return $dispatch->send(
            $this->supplierId,
            self::ENVIRONMENT,
            $submissionId,
            null,
            '1101234563',
            $idempotencyKey,
            $this->userId,
        );
    }

    /**
     * Odesílání nad skutečnou databází a zmrazeným archivem; falešný je jen
     * VREP (Guzzle MockHandler) a podpisový certifikát vyrobený v testu.
     *
     * @param list<mixed> $queue
     * @param list<array<string,mixed>> $history
     */
    private function splitDispatch(
        array $queue,
        array &$history,
        ?JmhzSignedProtocolFactory $protocols = null,
    ): JmhzDispatchService {
        $material = self::dispatchCertificate();
        $profiles = $this->createStub(PayrollSigningProfileRepository::class);
        $profiles->method('find')->willReturn([
            'supplier_id' => $this->supplierId,
            'environment' => self::ENVIRONMENT,
            'credential_id' => 5,
            'owner_user_id' => $this->userId,
            'cssz_registered_serial' => null,
            'row_version' => 1,
        ]);
        $vault = $this->createStub(PersonalCertificateVaultService::class);
        $vault->method('resolve')->willReturn([
            'pfx' => $material['pfx'],
            'password_enc' => 'enc:jmhz',
            'certificate_valid_from' => null,
            'certificate_valid_to' => null,
            'credential' => ['id' => 5, 'serial_hex' => '01'],
        ]);
        $secrets = $this->createStub(SecretEncryption::class);
        $secrets->method('decrypt')->willReturn($material['password']);
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new JmhzDispatchService(
            new PayrollSubmissionTransportAttemptRepository($this->db),
            $profiles,
            $vault,
            $secrets,
            $this->splitSoftware ?? throw new \LogicException('Nejdřív zmrazte rozdělené hlášení.'),
            new JmhzVrepClient(new Client(['handler' => $stack, 'http_errors' => false]), self::ENVIRONMENT),
            frozen: new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions),
            submissions: $this->submissions,
            signatures: $protocols === null
                ? null
                : new JmhzProtocolSignatureVerifier(trustAnchorPem: $protocols->anchorPem()),
        );
    }

    private function protocolFactory(): JmhzSignedProtocolFactory
    {
        return $this->protocolFactory ??= new JmhzSignedProtocolFactory();
    }

    private static function splitAcknowledgement(string $correlation): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails>'
            . '<Class>CSSZ_JMHZ</Class>'
            . '<Qualifier>acknowledgement</Qualifier>'
            . '<Function>submit</Function>'
            . '<TransactionID />'
            . '<CorrelationID>' . $correlation . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="60">https://t-epodani.cssz.cz/VREP/poll</ResponseEndPoint>'
            . '<GatewayTimestamp>2026-08-15T02:24:15.182</GatewayTimestamp>'
            . '</MessageDetails><SenderDetails /></Header>'
            . '<GovTalkDetails><Keys /></GovTalkDetails>'
            . '<Body />'
            . '</GovTalkMessage>';
    }

    /** @return array{pfx:string,password:string} */
    private static function dispatchCertificate(): array
    {
        static $material = null;
        if (is_array($material)) {
            return $material;
        }
        if (!function_exists('openssl_cms_sign') || !function_exists('openssl_cms_encrypt')) {
            self::markTestSkipped('Server nepodporuje CMS.');
        }
        $config = tempnam(sys_get_temp_dir(), 'jmhz-openssl-');
        self::assertIsString($config);
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\n");
        $options = ['config' => $config];
        try {
            $key = openssl_pkey_new($options + [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            self::assertNotFalse($key);
            $csr = openssl_csr_new(
                ['commonName' => 'JMHZ Split Dispatch Test', 'countryName' => 'CZ'],
                $key,
                $options + ['digest_alg' => 'sha256'],
            );
            self::assertNotFalse($csr);
            $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['digest_alg' => 'sha256']);
            self::assertNotFalse($certificate);
            $password = 'jmhz-test';
            self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));
        } finally {
            @unlink($config);
        }

        return $material = ['pfx' => (string) $pfx, 'password' => $password];
    }

    /**
     * Souběh: pojistné osoby a souhrnná data nese jediný formulář, takže
     * odložit jen jeden ze souběžných vztahů by změnilo, co vykazuje druhý.
     */
    public function testDeferringOnlyOneOfConcurrentEmploymentsIsRefused(): void
    {
        $payload = $this->payloadWithPeople(2);
        $secondary = $payload['people'][0]['employments'][0];
        $secondary['employment_id'] = 104;
        $secondary['employment']['is_primary'] = false;
        $secondary['identity']['jmhz_employment_external_identifier']['value'] = '2000000000000000000004';
        $secondary['insurance']['relationship_id'] = 'employment:104';
        $payload['people'][0]['employments'][] = $secondary;

        $partial = $this->resolutionFor(
            $this->pvpoj(employerTotal: 496, people: 2),
            $payload,
            exclusion: JmhzFormExclusion::deferral([104], []),
        );

        self::assertContains(
            ['jmhz_deferral_concurrent_incomplete', 'person', 11],
            array_map(
                static fn (JmhzScenario1Blocker $blocker): array
                    => [$blocker->code, $blocker->entityType, $blocker->entityId],
                $partial->blockers,
            ),
        );
    }

    public function testDeferringEveryFormOfTheRegistrationIsRefused(): void
    {
        $partial = $this->resolutionFor(
            $this->pvpoj(),
            $this->payload(),
            exclusion: JmhzFormExclusion::deferral([101], []),
        );

        self::assertContains(
            'jmhz_deferral_no_form_left',
            array_map(
                static fn (JmhzScenario1Blocker $blocker): string => $blocker->code,
                $partial->blockers,
            ),
        );
    }

    /**
     * Po změně variabilního symbolu účtárny se oprava musí dál spárovat
     * s řádným hlášením - hlavička opravy nese VS ze ZMRAZENÉHO řádného
     * hlášení (kontrola 22 ČSSZ). Dřív oprava spadla na „jiná registrace".
     */
    public function testContentCorrectionKeepsFrozenVariableSymbolAfterOfficeSymbolChange(): void
    {
        $original = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalXml = $this->submissions->artifactBytes($this->supplierId, $original['artifact_id']);
        $this->acceptWithFormOutcome($original, $this->firstFormGuid($originalXml), 'accepted');

        $payload = $this->payload();
        $payload['employer_summary']['office']['social_security_variable_symbol'] = '1100001237';
        $changed = $this->resolutionFor($this->pvpoj(variableSymbol: '1100001237'), $payload);
        self::assertSame('1100001237', $changed->requireResolvedDocument()->payload['header']['variable_symbol']);

        $service = $this->contentCorrections($changed);
        $candidates = $service->candidates(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
        );
        self::assertSame(['2000000000000000000001'], array_column($candidates['forms'], 'employment_external_identifier'));
        $correction = $service->freeze(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['submission_id'],
            self::PREPARATION_ID,
            ['2000000000000000000001'],
            $this->userId,
        );

        $xml = $this->submissions->artifactBytes($this->supplierId, $correction['artifact_id']);
        self::assertStringContainsString('<variabilniSymbol>1101234563</variabilniSymbol>', $xml);
        self::assertSame('1101234563', $correction['variable_symbol']);
    }

    public function testContentCorrectionForAnotherPeriodNamesBothPeriods(): void
    {
        $original = $this->bridge()->bridge(
            $this->supplierId,
            self::PREPARATION_ID,
            $this->registerObligation(),
            self::ENVIRONMENT,
            $this->userId,
        );
        $originalXml = $this->submissions->artifactBytes($this->supplierId, $original['artifact_id']);
        $this->acceptWithFormOutcome($original, $this->firstFormGuid($originalXml), 'accepted');
        $august = $this->resolutionFor(
            $this->pvpoj(period: '2026-08'),
            $this->payloadForPeriod('2026-08-01', '2026-08-31'),
            periodStart: '2026-08-01',
            periodEnd: '2026-08-31',
        );
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolveForCorrection')->willReturn($august);
        $frozen = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $service = new JmhzContentCorrectionSubmissionService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(CzechPayrollRulesets2026::provider()),
            new JmhzSubmissionGuidFactory(),
            new JmhzEffectiveFormLedgerResolver($this->submissionRepository, $frozen),
            $frozen,
            $this->preparations,
            $this->people,
            $this->submissionRepository,
            $this->submissions,
            $this->obligations,
            new MockClock('2026-09-05 11:30:00 Europe/Prague'),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new JmhzDeferralRepository($this->db),
        );

        try {
            $service->candidates(
                $this->supplierId,
                self::ENVIRONMENT,
                $original['submission_id'],
                self::PREPARATION_ID,
            );
            self::fail('Příprava za jiné období nesmí vést k opravě.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_content_correction_scope_mismatch', $exception->validationCode);
            self::assertStringContainsString('08/2026', $exception->getMessage());
            self::assertStringContainsString('07/2026', $exception->getMessage());
        }
    }

    /**
     * Příprava s `$count` osobami, každá s jedním vztahem (101, 102, …) a
     * vlastní ordinary evidencí.
     *
     * @return array<string,mixed>
     */
    private function payloadWithPeople(int $count): array
    {
        $payload = $this->payload();
        $template = $payload['people'][0];
        for ($index = 1; $index < $count; $index++) {
            $employeeId = 11 + $index;
            $employmentId = 101 + $index;
            $person = $template;
            $person['employee_id'] = $employeeId;
            $person['employments'][0]['employment_id'] = $employmentId;
            $oic = 1000000001 + 20 * $index;
            while (!PayrollRegistrationIdentityService::oicChecksumValid((string) $oic)) {
                ++$oic;
            }
            $person['employments'][0]['identity']['person_external_identifier']['value']
                = (string) $oic;
            $person['employments'][0]['identity']['jmhz_employment_external_identifier']['value']
                = sprintf('2%021d', 1 + $index);
            $person['employments'][0]['insurance']['relationship_id'] = "employment:{$employmentId}";
            $person['person_summary']['statutory']['net_pay']['relationships']
                = [['relationship_id' => "employment:{$employmentId}"]];
            $payload['people'][] = $person;
            $payload['ordinary_evidence'][] = [
                'scope' => ['employee_id' => $employeeId, 'employment_id' => $employmentId],
                'attribute_values' => ['10116' => false, '10546' => false],
            ];
            $payload['source_versions']['ordinary_evidence'][] = [
                'employment_id' => $employmentId,
                'id' => 600 + $employmentId,
                'source_manifest_sha256' => str_repeat('6', 64),
                'snapshot_fingerprint' => str_repeat('7', 64),
            ];
        }

        return $payload;
    }

    /**
     * Most, který dokument řeší podle zvolené mzdové účtárny — stejně jako
     * skutečný `JmhzScenario1DocumentService`.
     */
    private function officeBridge(): JmhzSubmissionBridgeService
    {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturnCallback(
            fn (
                int $supplierId,
                string $environment,
                int $preparationId,
                ?int $officeId = null,
            ): JmhzScenario1Resolution => $officeId === 5
                ? $this->resolutionForOffice(5, '1100001237')
                : $this->resolutionForOffice(4, '1101234563'),
        );

        return new JmhzSubmissionBridgeService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(
                CzechPayrollRulesets2026::provider(),
            ),
            new JmhzSubmissionGuidFactory(),
            $this->submissionRepository,
            $this->submissions,
            new MockClock('2026-08-05 11:30:00 Europe/Prague'),
            $this->obligations,
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new JmhzDeferralRepository($this->db),
        );
    }

    /** @param array<string,mixed> $submission */
    private function acceptWithFormOutcome(array $submission, string $formGuid, string $status): void
    {
        $submitted = $this->submissions->transition(
            $this->supplierId,
            (int) $submission['submission_id'],
            (int) $submission['row_version'],
            'submitted',
            'VREP-CONTENT-CORRECTION-ROOT',
        );
        $verifier = new class ($formGuid, $status) implements PayrollReceiptVerifierInterface {
            public function __construct(
                private readonly string $formGuid,
                private readonly string $status,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    $this->status,
                    $expectedCorrelationReference,
                    [],
                    [new PayrollVerifiedReceiptFormOutcome(
                        $this->formGuid,
                        null,
                        $this->status === 'accepted' ? 1 : 3,
                        $this->status === 'accepted' ? 'ProcessedAndComplete' : 'Rejected',
                        $this->status,
                        '1000000001',
                        '2000000000000000000001',
                        [],
                    )],
                );
            }
        };
        $result = $this->submissions->importReceipt(
            $this->supplierId,
            (int) $submission['submission_id'],
            $submitted['row_version'],
            null,
            '<signed-jmhz-protocol/>',
            'receipt:content-correction-root',
            'VREP-CONTENT-CORRECTION-ROOT',
            'CSSZ_JMHZ',
            $status,
            'vrep_apep',
            'receipt-content-correction-root',
            $this->userId,
            $verifier,
        );
        self::assertSame($status, $result['submission_status']);
    }

    private function contentCorrections(
        JmhzScenario1Resolution $resolution,
        ?int $packageFormLimit = null,
        string $now = '2026-08-05 11:30:00 Europe/Prague',
    ): JmhzContentCorrectionSubmissionService {
        $documents = $this->createMock(JmhzScenario1DocumentService::class);
        $documents->expects(self::exactly(2))->method('resolveForCorrection')->willReturn($resolution);
        $frozen = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $clock = new MockClock($now);

        return new JmhzContentCorrectionSubmissionService(
            $documents,
            new JmhzScenario1XmlValidator(
                serializer: new JmhzScenario1XmlSerializer(
                    $packageFormLimit === null
                        ? new JmhzPackageSplitter()
                        : new JmhzPackageSplitter($packageFormLimit),
                ),
            ),
            JmhzScenario1ControlValidator::create(
                CzechPayrollRulesets2026::provider(),
            ),
            new JmhzSubmissionGuidFactory(),
            new JmhzEffectiveFormLedgerResolver($this->submissionRepository, $frozen),
            $frozen,
            $this->preparations,
            $this->people,
            $this->submissionRepository,
            $this->submissions,
            $this->obligations,
            $clock,
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new JmhzDeferralRepository($this->db),
        );
    }

    private function firstFormGuid(string $xml): string
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $node = $xpath->query('/p:jmhz/p:formulareOsob/p:formularOsoby[1]/p:hlavicka/p:idFormulare')->item(0);
        self::assertNotNull($node);

        return trim($node->textContent);
    }

    private function bridge(
        ?JmhzScenario1Resolution $resolution = null,
        string $now = '2026-08-05 11:30:00 Europe/Prague',
        ?int $packageFormLimit = null,
        ?PayrollRegistrationChangeSettlement $registrationSettlement = null,
    ): JmhzSubmissionBridgeService {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturn(
            $resolution ?? $this->resolution(),
        );

        return new JmhzSubmissionBridgeService(
            $documents,
            new JmhzScenario1XmlValidator(
                serializer: new JmhzScenario1XmlSerializer(
                    $packageFormLimit === null
                        ? new JmhzPackageSplitter()
                        : new JmhzPackageSplitter($packageFormLimit),
                ),
            ),
            JmhzScenario1ControlValidator::create(
                CzechPayrollRulesets2026::provider(),
            ),
            new JmhzSubmissionGuidFactory(),
            $this->submissionRepository,
            $this->submissions,
            new MockClock($now),
            $this->obligations,
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            new JmhzDeferralRepository($this->db),
            registrationSettlement: $registrationSettlement,
        );
    }

    private function registerObligation(?int $officeId = null): int
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run',
            JmhzSubmissionBridgeService::runReference(self::RUN_ID, $officeId),
            self::PERIOD_START,
            self::PERIOD_END,
            'regular',
            'vrep_apep',
            JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
            JmhzSubmissionBridgeService::sourceEventReference(
                self::PREPARATION_ID,
            ),
            self::SNAPSHOT_HASH,
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz25-deadline-test',
            str_repeat('d', 64),
            'jmhz-bridge-obligation:' . self::PREPARATION_ID
                . ($officeId === null ? '' : ":office:{$officeId}"),
            null,
            $this->userId,
            null,
            self::ENVIRONMENT,
        );

        return (int) $obligation['id'];
    }

    private function resolution(): JmhzScenario1Resolution
    {
        return $this->resolutionFor($this->pvpoj());
    }

    private function resolutionWithEmployeeName(string $employeeName): JmhzScenario1Resolution
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)',
        );
        $statement->execute([$this->supplierId, $employeeName]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $payload = $this->resolution()->requireResolvedDocument()->payload;
        $payload['people'][0]['employee_id'] = $employeeId;

        return new JmhzScenario1Resolution(
            new JmhzScenario1NormalizedDocument($payload),
            [],
        );
    }

    /**
     * Zaměstnavatelské pojistné v PVPOJ o korunu nižší, než kolik vychází ze
     * součástí. Tvar zůstává platný, rozvaha ne.
     */
    private function resolutionWithBrokenPvpoj(): JmhzScenario1Resolution
    {
        return $this->resolutionFor($this->pvpoj(employerTotal: 247));
    }

    private function resolutionWithSecondPerson(): JmhzScenario1Resolution
    {
        $payload = $this->payload();
        $second = $payload['people'][0];
        $second['employee_id'] = 12;
        $second['employments'][0]['employment_id'] = 102;
        $second['employments'][0]['identity']['person_external_identifier']['value'] = '1000000012';
        $second['employments'][0]['identity']['jmhz_employment_external_identifier']['value']
            = '2000000000000000000002';
        $second['employments'][0]['insurance']['relationship_id'] = 'employment:102';
        $second['person_summary']['statutory']['net_pay']['relationships'] = [
            ['relationship_id' => 'employment:102'],
        ];
        $payload['people'][] = $second;
        $payload['ordinary_evidence'][] = [
            'scope' => ['employee_id' => 12, 'employment_id' => 102],
            'attribute_values' => ['10116' => false, '10546' => false],
        ];
        $payload['source_versions']['ordinary_evidence'][] = [
            'employment_id' => 102,
            'id' => 602,
            'source_manifest_sha256' => str_repeat('6', 64),
            'snapshot_fingerprint' => str_repeat('7', 64),
        ];

        return $this->resolutionFor($this->pvpoj(employerTotal: 496, people: 2), $payload);
    }

    /**
     * Řádné podání za JEDNU registraci u OSSZ.
     *
     * @param array<string,mixed>|null $payload
     */
    private function resolutionForOffice(
        int $officeId,
        string $variableSymbol,
    ): JmhzScenario1Resolution {
        $payload = $this->payload();
        $payload['schema_reference'] = JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE;
        $payload['employer_summary']['office'] = null;
        $payload['employer_summary']['offices'] = [
            [
                'id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'social_security_variable_symbol' => '1101234563',
            ],
            [
                'id' => 5,
                'code' => 'UC5',
                'name' => 'Mzdová účtárna 5',
                'social_security_variable_symbol' => '1100001237',
            ],
        ];
        // Revize přes DVĚ účtárny: každá osoba má vlastní vztah, vlastní
        // registraci a vlastní ordinary evidenci. Dokud šla evidence zmrazit
        // jen za revizi s jedinou osobou, takový běh se k podání nedostal.
        $payload['people'][0]['employments'][0]['employment']['office_id'] = 4;
        $second = $payload['people'][0];
        $second['employee_id'] = 12;
        $second['employments'][0]['employment_id'] = 102;
        $second['employments'][0]['employment']['office_id'] = 5;
        $second['employments'][0]['insurance']['relationship_id'] = 'employment:102';
        $second['person_summary']['statutory']['net_pay']['relationships']
            = [['relationship_id' => 'employment:102']];
        $payload['people'][] = $second;
        $payload['ordinary_evidence'][] = [
            'scope' => ['employee_id' => 12, 'employment_id' => 102],
            'attribute_values' => ['10116' => false, '10546' => false],
        ];
        $payload['source_versions']['ordinary_evidence'][] = [
            'employment_id' => 102,
            'id' => 602,
            'source_manifest_sha256' => str_repeat('6', 64),
            'snapshot_fingerprint' => str_repeat('7', 64),
        ];

        return $this->resolutionFor(
            $this->pvpoj(officeId: $officeId, variableSymbol: $variableSymbol),
            $payload,
            $officeId,
        );
    }

    /** @param array<string,mixed>|null $payload */
    private function resolutionFor(
        JmhzPvpojPreview $pvpoj,
        ?array $payload = null,
        ?int $officeId = null,
        string $periodStart = self::PERIOD_START,
        string $periodEnd = self::PERIOD_END,
        ?JmhzFormExclusion $exclusion = null,
    ): JmhzScenario1Resolution {
        $preparation = new JmhzVerifiedPreparationSnapshot(
            self::PREPARATION_ID,
            7,
            self::ENVIRONMENT,
            self::RUN_ID,
            301,
            1,
            $periodStart,
            $periodEnd,
            'scenario_1',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            self::SNAPSHOT_HASH,
            [],
            [
                'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
                'status' => 'source_ready',
                'issue_count' => 0,
                'issues' => [],
                'official_submission_supported' => false,
            ],
            $payload ?? $this->payload(),
        );
        $resolver = new JmhzScenario1DocumentResolver();
        if ($exclusion !== null) {
            return $resolver->resolveExcluding($preparation, $pvpoj, null, $officeId, [], $exclusion);
        }

        return $resolver->resolve(
            $preparation,
            $pvpoj,
            null,
            $officeId,
        );
    }

    private function pvpoj(
        int $employerTotal = 248,
        int $officeId = 4,
        string $variableSymbol = '1101234563',
        int $people = 1,
        ?string $period = null,
    ): JmhzPvpojPreview {
        return new JmhzPvpojPreview(
            7,
            self::RUN_ID,
            301,
            1,
            $period ?? '2026-07',
            [
                'office_id' => $officeId,
                'code' => 'UC' . $officeId,
                'name' => 'Mzdová účtárna ' . $officeId,
                'variable_symbol' => $variableSymbol,
            ],
            [[
                'office_id' => $officeId,
                'employee_contribution_minor_units' => 7_100 * $people,
                'employer_contribution_minor_units' => 24_800 * $people,
                'amount_minor_units' => 31_900 * $people,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000 * $people,
                    'pojistneZamestnavateleA' => $employerTotal,
                    'pojistneZamestnavateleCelkem' => $employerTotal,
                    'pojistneZamestnance' => 71 * $people,
                    'pojistneCelkem' => $employerTotal + (71 * $people),
                ],
                'pojistneUhrada' => $employerTotal + (71 * $people),
            ],
            array_map(
                static fn (int $offset): array => ['employee_id' => 11 + $offset],
                range(0, $people - 1),
            ),
        );
    }

    /**
     * Ověřená příprava, ze které vzniká právě jedna platná součást. Hodnoty jsou
     * shodné s fixture serializéru, takže dokument projde XSD i katalogem kontrol.
     *
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'schema_reference' => 'payroll-jmhz-preparation-source.v5',
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => self::ENVIRONMENT,
                'run_id' => self::RUN_ID,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => self::PERIOD_START,
                'period_end' => self::PERIOD_END,
                'scenario_set' => ['scenario_1'],
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1101234563'],
            ],
            'ordinary_evidence' => [[
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ]],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [
                                ['relationship_id' => 'employment:101'],
                            ],
                            'net_before_deductions_minor_units' => 73_400,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 73_400,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => [
                            'value' => '2000000000000000000001',
                        ],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                        'work_place' => 'Brno',
                        'jmhz_workplace_municipality_code' => '582786',
                        'jmhz_workplace_country_code' => 'CZ',
                        'jmhz_apz_contribution_status' => 'no',
                        'jmhz_functional_benefits_status' => 'no',
                        'jmhz_temporary_assignment_status' => 'no',
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => [
                        'confirmation' => [
                            'in03_active' => false,
                            'in04_active' => false,
                        ],
                        'insurance_interval' => [
                            'insurance_from' => self::PERIOD_START,
                            'insurance_to' => self::PERIOD_END,
                        ],
                        'eldp_sections' => [[
                            'ordinal' => 1,
                            'code' => '1++',
                            'valid_from' => self::PERIOD_START,
                            'valid_to' => self::PERIOD_END,
                            'insurance_days' => 31,
                            'assessment_base_czk' => 1_000,
                            'excluded_days' => null,
                            'deducted_days' => null,
                        ]],
                    ],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'derivation_version' => 'jmhz-work-month.v2',
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'standard_fund_millihours' => 184_000,
                                'agreed_fund_millihours' => 184_000,
                                'weekly_work_centihours' => 4_000,
                                'evidence_days' => 31,
                                'worked_millihours' => 184_000,
                                'unworked_total_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => [
                        'relationship_id' => 'employment:101',
                        'capped_assessment_base_minor_units' => 100_000,
                        'employer_rate_category' => 'ordinary',
                    ],
                ]],
            ]],
            'source_versions' => [
                'office_id' => 9,
                'employments' => [],
                'ordinary_evidence' => [[
                    'employment_id' => 101,
                    'id' => 601,
                    'source_manifest_sha256' => str_repeat('4', 64),
                    'snapshot_fingerprint' => str_repeat('5', 64),
                ]],
            ],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function payloadForPeriod(string $periodStart, string $periodEnd): array
    {
        $payload = $this->payload();
        $payload['scope']['period_start'] = $periodStart;
        $payload['scope']['period_end'] = $periodEnd;
        $employment = &$payload['people'][0]['employments'][0];
        $employment['eldp']['insurance_interval']['insurance_from'] = $periodStart;
        $employment['eldp']['insurance_interval']['insurance_to'] = $periodEnd;
        $employment['eldp']['eldp_sections'][0]['valid_from'] = $periodStart;
        $employment['eldp']['eldp_sections'][0]['valid_to'] = $periodEnd;
        $days = (int) (new \DateTimeImmutable($periodEnd))->format('d');
        $employment['eldp']['eldp_sections'][0]['insurance_days'] = $days;
        $employment['work_month']['jmhz_work_summary']['values']['evidence_days'] = $days;
        unset($employment);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function submissionRow(int $submissionId): array
    {
        return $this->row(
            'SELECT status, channel, environment, submitted_at, decided_at,
                    source_snapshot_hash
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $submissionId],
        );
    }

    /**
     * @param list<int|string> $parameters
     * @return array<string,mixed>
     */
    private function row(string $sql, array $parameters): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $normalized = [];
        foreach ($row as $key => $value) {
            self::assertIsString($key);
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function countRows(string $table): int
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE supplier_id = ? AND environment = ?",
        );
        $statement->execute([$this->supplierId, self::ENVIRONMENT]);

        return (int) $statement->fetchColumn();
    }
}
