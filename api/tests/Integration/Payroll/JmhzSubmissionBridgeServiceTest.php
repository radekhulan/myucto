<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DOMDocument;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEffectiveFormLedgerResolver;
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
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
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
        $container = Bootstrap::buildContainer();
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
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
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
        self::assertSame('1234567890', $result['variable_symbol']);
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

    public function testDecemberCorrectionObligationUsesFollowingJanuaryDueYear(): void
    {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturn($this->resolution());
        $frozen = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $service = new JmhzContentCorrectionSubmissionService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(),
            new JmhzSubmissionGuidFactory(),
            new JmhzEffectiveFormLedgerResolver($this->submissionRepository, $frozen),
            $frozen,
            $this->preparations,
            $this->people,
            $this->submissionRepository,
            $this->submissions,
            $this->obligations,
            new MockClock('2037-12-31 11:30:00 Europe/Prague'),
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
        self::assertSame('1234567890', $first['variable_symbol']);
        self::assertSame('9990001234', $second['variable_symbol']);

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
        $first = $this->resolutionForOffice(4, '1234567890');
        $second = $this->resolutionForOffice(5, '9990001234');

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
        self::assertSame('9990001234', $frozen['variable_symbol']);
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
                ? $this->resolutionForOffice(5, '9990001234')
                : $this->resolutionForOffice(4, '1234567890'),
        );

        return new JmhzSubmissionBridgeService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(),
            new JmhzSubmissionGuidFactory(),
            $this->submissionRepository,
            $this->submissions,
            new MockClock('2026-08-05 11:30:00 Europe/Prague'),
            $this->obligations,
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

    private function contentCorrections(JmhzScenario1Resolution $resolution): JmhzContentCorrectionSubmissionService
    {
        $documents = $this->createMock(JmhzScenario1DocumentService::class);
        $documents->expects(self::exactly(2))->method('resolve')->willReturn($resolution);
        $frozen = new JmhzFrozenPayloadReader($this->submissionRepository, $this->submissions);
        $clock = new MockClock('2026-08-05 11:30:00 Europe/Prague');

        return new JmhzContentCorrectionSubmissionService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(),
            new JmhzSubmissionGuidFactory(),
            new JmhzEffectiveFormLedgerResolver($this->submissionRepository, $frozen),
            $frozen,
            $this->preparations,
            $this->people,
            $this->submissionRepository,
            $this->submissions,
            $this->obligations,
            $clock,
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
    ): JmhzSubmissionBridgeService {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturn(
            $resolution ?? $this->resolution(),
        );

        return new JmhzSubmissionBridgeService(
            $documents,
            new JmhzScenario1XmlValidator(),
            JmhzScenario1ControlValidator::create(),
            new JmhzSubmissionGuidFactory(),
            $this->submissionRepository,
            $this->submissions,
            new MockClock($now),
            $this->obligations,
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
                'social_security_variable_symbol' => '1234567890',
            ],
            [
                'id' => 5,
                'code' => 'UC5',
                'name' => 'Mzdová účtárna 5',
                'social_security_variable_symbol' => '9990001234',
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

        return (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $pvpoj,
            null,
            $officeId,
        );
    }

    private function pvpoj(
        int $employerTotal = 248,
        int $officeId = 4,
        string $variableSymbol = '1234567890',
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
                'office' => ['social_security_variable_symbol' => '1234567890'],
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
