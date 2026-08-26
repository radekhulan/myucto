<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\RegzelSubmissionBridgeRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\Regzel\EmployerRegistrationService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelPayloadSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionPayloadAssembler;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelValidationException;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlGenerator;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollRegzelSubmissionBridgeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private EmployerRegistrationService $registration;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private RegzelSubmissionBridgeService $bridge;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $config = $container->get(Config::class);
        if (!$db instanceof Connection || !$config instanceof Config) {
            throw new \RuntimeException(
                'Databáze nebo konfigurace REGZEL testu není dostupná.',
            );
        }
        $this->db = $db;
        foreach ([
            'payroll_regzel_payload_snapshots',
            'payroll_obligations',
            'payroll_submissions',
            'payroll_submission_parts',
            'payroll_submission_artifacts',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $supplierStatement = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $userStatement = $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        if ($supplierStatement === false || $userStatement === false) {
            $this->markTestSkipped('Výchozí testovací data nelze načíst.');
        }
        $sourceSupplierId = (int) $supplierStatement->fetchColumn();
        $this->userId = (int) $userStatement->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->foreignSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetický REGZEL bridge",
                    data_box_id = "abc1234"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol)
             VALUES (?, "BRIDGE", "Syntetická bridge účtárna", "1234567890")',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id,
                 employer_registration_number, social_security_office_code)
             VALUES (?, ?, "123456789", "110")',
        )->execute([$this->supplierId, $this->officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_regzel_employer_profiles
                (supplier_id, social_enterprise, employment_agency,
                 protected_labor_market, tax_office_code,
                 tax_office_workplace_code, evidence_confirmed_by)
             VALUES (?, 1, 0, 1, "2000", "2002", ?)',
        )->execute([$this->supplierId, $this->userId]);

        $regzelRepository = new PayrollRegzelRepository($db);
        $this->registration = new EmployerRegistrationService(
            $regzelRepository,
            new RegzelPayloadSnapshotBuilder($regzelRepository),
            new RegzelXmlGenerator(),
            new RegzelXmlValidator(new RegzelSchemaCatalog()),
            new SecretEncryption($config),
        );
        $submissionRepository = new PayrollSubmissionRepository($db);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService(
            $submissionRepository,
            $clock,
        );
        $this->submissions = new PayrollSubmissionService(
            $submissionRepository,
            new PayrollSubmissionStateMachine(),
            new SecretEncryption($config),
            $clock,
        );
        $this->bridge = new RegzelSubmissionBridgeService(
            new RegzelSubmissionPayloadAssembler(
                $regzelRepository,
                $this->registration,
            ),
            new RegzelSubmissionBridgeRepository($db),
            $submissionRepository,
            $this->submissions,
        );
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

    public function testArchivesExactXmlAndReplaysReadySubmission(): void
    {
        $snapshot = $this->snapshot('production', 'regzel-bridge-production');
        $obligation = $this->obligation($snapshot, 'production');

        $created = $this->bridge->bridge(
            $this->supplierId,
            $snapshot['id'],
            $obligation['id'],
            'production',
            $this->userId,
        );
        $replayed = $this->bridge->bridge(
            $this->supplierId,
            $snapshot['id'],
            $obligation['id'],
            'production',
            $this->userId,
        );

        self::assertTrue($created['created']);
        self::assertFalse($replayed['created']);
        self::assertSame($created['submission_id'], $replayed['submission_id']);
        self::assertSame($created['part_id'], $replayed['part_id']);
        self::assertSame($created['artifact_id'], $replayed['artifact_id']);
        self::assertSame('ready', $created['status']);
        self::assertSame('ready', $replayed['status']);
        self::assertSame(
            $snapshot['source_snapshot_hash'],
            $created['source_snapshot_hash'],
        );
        self::assertSame($snapshot['xml_sha256'], $created['artifact_sha256']);
        self::assertSame(
            $snapshot['xml'],
            $this->submissions->artifactBytes(
                $this->supplierId,
                $created['artifact_id'],
            ),
        );

        $stored = $this->submissionRow($created['submission_id']);
        self::assertSame('ready', $stored['status']);
        self::assertSame('manual_upload', $stored['channel']);
        self::assertSame('production', $stored['environment']);
        self::assertNull($stored['submitted_at']);
        self::assertNull($stored['decided_at']);
        self::assertSame(
            $snapshot['source_snapshot_hash'],
            $stored['source_snapshot_hash'],
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_submissions',
                'supplier_id = ? AND environment = ?',
                [$this->supplierId, 'production'],
            ),
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_submission_artifacts',
                'supplier_id = ? AND environment = ?',
                [$this->supplierId, 'production'],
            ),
        );
    }

    public function testRequiresVerifiedMatchingObligationAndTenant(): void
    {
        $snapshot = $this->snapshot('production', 'regzel-bridge-required');

        try {
            $this->bridge->bridge(
                $this->supplierId,
                $snapshot['id'],
                9_999_999,
                'production',
                $this->userId,
            );
            self::fail('Bridge bez ověřené povinnosti musí selhat.');
        } catch (RegzelValidationException $exception) {
            self::assertSame(
                'regzel_verified_obligation_required',
                $exception->validationCode,
            );
        }

        $mismatched = $this->obligations->register(
            $this->supplierId,
            RegzelSubmissionBridgeService::AGENDA_CODE,
            'office',
            RegzelSubmissionBridgeService::officeReference($this->officeId),
            '2026-08-04',
            '2026-08-04',
            'regular',
            'manual_upload',
            RegzelSubmissionBridgeService::SOURCE_EVENT_TYPE,
            RegzelSubmissionBridgeService::sourceEventReference(
                $snapshot['id'],
            ),
            str_repeat('f', 64),
            '2026-08-04',
            '2026-08-12',
            'calendar_days',
            'regzeldopl25-deadline-verified-test',
            str_repeat('d', 64),
            'regzel-bridge-obligation-mismatch',
            null,
            $this->userId,
            null,
            'production',
        );
        try {
            $this->bridge->bridge(
                $this->supplierId,
                $snapshot['id'],
                $mismatched['id'],
                'production',
                $this->userId,
            );
            self::fail('Povinnost jiného zdroje musí selhat.');
        } catch (RegzelValidationException $exception) {
            self::assertSame(
                'regzel_obligation_scope_mismatch',
                $exception->validationCode,
            );
        }

        $this->expectException(\OutOfBoundsException::class);
        $this->bridge->bridge(
            $this->foreignSupplierId,
            $snapshot['id'],
            $mismatched['id'],
            'production',
            $this->userId,
        );
    }

    public function testProductionAndTestIdempotenceStaySeparated(): void
    {
        $production = $this->snapshot(
            'production',
            'regzel-bridge-environment-production',
        );
        $productionObligation = $this->obligation(
            $production,
            'production',
        );
        $productionBridge = $this->bridge->bridge(
            $this->supplierId,
            $production['id'],
            $productionObligation['id'],
            'production',
            $this->userId,
        );

        $this->db->pdo()->prepare(
            'UPDATE payroll_offices
                SET social_security_variable_symbol = "9994567890",
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);
        $test = $this->snapshot(
            'test',
            'regzel-bridge-environment-test',
        );
        $testObligation = $this->obligation($test, 'test');
        $testBridge = $this->bridge->bridge(
            $this->supplierId,
            $test['id'],
            $testObligation['id'],
            'test',
            $this->userId,
        );

        self::assertNotSame(
            $productionBridge['submission_id'],
            $testBridge['submission_id'],
        );
        self::assertNotSame(
            $productionBridge['artifact_id'],
            $testBridge['artifact_id'],
        );
        self::assertSame('production', $productionBridge['environment']);
        self::assertSame('test', $testBridge['environment']);
        self::assertSame(
            2,
            $this->countRows(
                'payroll_submissions',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_submissions',
                'supplier_id = ? AND environment = ?',
                [$this->supplierId, 'production'],
            ),
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_submissions',
                'supplier_id = ? AND environment = ?',
                [$this->supplierId, 'test'],
            ),
        );
    }

    /**
     * @return array{
     *   id:int,environment:string,office_id:int,document_type:string,
     *   interaction_code:string,mapping_version:string,xsd_version:string,
     *   source_snapshot_hash:string,xml_sha256:string,xml_byte_size:int,
     *   request_fingerprint:string,xml:string,created:bool
     * }
     */
    private function snapshot(
        string $environment,
        string $idempotencyKey,
    ): array {
        return $this->registration->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            $environment,
            $idempotencyKey,
            $this->userId,
        );
    }

    /**
     * @param array{
     *   id:int,source_snapshot_hash:string
     * } $snapshot
     * @return array{id:int,due_on:string,status:string,row_version:int,created:bool}
     */
    private function obligation(array $snapshot, string $environment): array
    {
        return $this->obligations->register(
            $this->supplierId,
            RegzelSubmissionBridgeService::AGENDA_CODE,
            'office',
            RegzelSubmissionBridgeService::officeReference($this->officeId),
            '2026-08-04',
            '2026-08-04',
            'regular',
            'manual_upload',
            RegzelSubmissionBridgeService::SOURCE_EVENT_TYPE,
            RegzelSubmissionBridgeService::sourceEventReference(
                $snapshot['id'],
            ),
            $snapshot['source_snapshot_hash'],
            '2026-08-04',
            '2026-08-12',
            'calendar_days',
            'regzeldopl25-deadline-verified-test',
            str_repeat('d', 64),
            "regzel-bridge-obligation:{$environment}:{$snapshot['id']}",
            null,
            $this->userId,
            null,
            $environment,
        );
    }

    /** @return array<string,mixed> */
    private function submissionRow(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status, channel, environment, submitted_at, decided_at,
                    source_snapshot_hash
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $normalized = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Řádek podání nemá pojmenované sloupce.',
                );
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param list<int|string> $parameters
     */
    private function countRows(
        string $table,
        string $where,
        array $parameters,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
