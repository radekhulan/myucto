<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Regzel\EmployerRegistrationService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelPayloadSnapshot;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelPayloadSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionPayloadAssembler;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelValidationException;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlGenerator;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelXmlValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRegzelPayloadServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRegzelRepository $repository;
    private RegzelPayloadSnapshotBuilder $builder;
    private EmployerRegistrationService $service;
    private SecretEncryption $encryption;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->repository = new PayrollRegzelRepository($this->db);
        $this->builder = new RegzelPayloadSnapshotBuilder($this->repository);
        $this->encryption = new SecretEncryption($container->get(Config::class));
        $this->service = new EmployerRegistrationService(
            $this->repository,
            $this->builder,
            new RegzelXmlGenerator(),
            new RegzelXmlValidator(new RegzelSchemaCatalog()),
            $this->encryption,
        );

        $pdo = $this->db->pdo();
        $this->foreignSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn();
        if ($this->foreignSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $this->foreignSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetický REGZEL zaměstnavatel",
                    financial_office_code = "451",
                    workplace_code = "3001",
                    data_box_id = "abc1234"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol)
             VALUES (?, "REGZEL", "Syntetická účtárna", "1234567890")',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id,
                 employer_registration_number,
                 social_security_office_code)
             VALUES (?, ?, "1234567890", "110")',
        )->execute([$this->supplierId, $this->officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_regzel_employer_profiles
                (supplier_id, social_enterprise, employment_agency,
                 protected_labor_market, tax_office_code,
                 tax_office_workplace_code, payer_reference_number,
                 evidence_confirmed_by)
             VALUES (?, 1, 0, 1, "3000", "3002", "612345678", ?)',
        )->execute([$this->supplierId, $this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPersistsEncryptedExactSnapshotAndReplaysIdempotently(): void
    {
        $prepared = $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
            'synthetic-regzel-production',
            $this->userId,
        );
        $replayed = $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
            'synthetic-regzel-production',
            $this->userId,
        );

        self::assertTrue($prepared['created']);
        self::assertFalse($replayed['created']);
        self::assertSame($prepared['id'], $replayed['id']);
        self::assertSame($prepared['xml'], $replayed['xml']);
        self::assertStringContainsString('<kodFU>3000</kodFU>', $prepared['xml']);
        self::assertStringContainsString(
            '<kodPracovisteFU>3002</kodPracovisteFU>',
            $prepared['xml'],
        );
        self::assertStringNotContainsString('<kodFU>451</kodFU>', $prepared['xml']);
        self::assertStringNotContainsString(
            '<kodPracovisteFU>3001</kodPracovisteFU>',
            $prepared['xml'],
        );
        self::assertStringContainsString('<vcp>612345678</vcp>', $prepared['xml']);
        self::assertStringNotContainsString('<vcp>123456789</vcp>', $prepared['xml']);
        self::assertSame(hash('sha256', $prepared['xml']), $prepared['xml_sha256']);
        self::assertSame(
            $this->builder->buildSupplementalInformation(
                $this->supplierId,
                $this->officeId,
                'production',
            )->hash(),
            $prepared['source_snapshot_hash'],
        );

        $row = $this->db->pdo()->query(
            'SELECT environment, snapshot_ciphertext, source_snapshot_hash,
                    xml_sha256, xml_byte_size
               FROM payroll_regzel_payload_snapshots
              WHERE id = ' . (int) $prepared['id'],
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('production', $row['environment']);
        self::assertStringStartsWith('enc:v2:', (string) $row['snapshot_ciphertext']);
        self::assertStringNotContainsString('1234567890', (string) $row['snapshot_ciphertext']);
        self::assertSame($prepared['source_snapshot_hash'], $row['source_snapshot_hash']);
        self::assertSame($prepared['xml_sha256'], $row['xml_sha256']);
        self::assertSame(strlen($prepared['xml']), (int) $row['xml_byte_size']);
        self::assertNull(
            $this->repository->findSnapshot(
                $this->foreignSupplierId,
                (int) $prepared['id'],
                'production',
            ),
        );
    }

    public function testIdempotencyKeyCannotBeReusedAfterSourceChange(): void
    {
        $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
            'synthetic-regzel-conflict',
            $this->userId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_regzel_employer_profiles
                SET protected_labor_market = 0,
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        try {
            $this->service->prepareSupplementalInformation(
                $this->supplierId,
                $this->officeId,
                'production',
                'synthetic-regzel-conflict',
                $this->userId,
            );
            self::fail('Stejný idempotency klíč nesmí přijmout jiný snapshot.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Idempotency klíč REGZEL už patří jinému přesnému obsahu.',
                $exception->getMessage(),
            );
        }
    }

    public function testEncryptedSnapshotMustMatchStoredMappingMetadata(): void
    {
        $snapshot = $this->builder->buildSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
        );
        $json = $snapshot->canonicalJson();
        $sourceHash = hash('sha256', $json);
        $xml = (new RegzelXmlGenerator())->generate($snapshot);
        $id = $this->repository->insertSnapshot([
            'supplier_id' => $this->supplierId,
            'environment' => 'production',
            'office_id' => $this->officeId,
            'document_type' => 'REGZELDOPL25',
            'interaction_code' => 'supplemental_information',
            'mapping_version' => 'regzeldopl25-map-1',
            'xsd_version' => '1.2',
            'source_manifest_json' => '{}',
            'snapshot_ciphertext' => $this->encryption->encryptFor(
                $json,
                implode('|', [
                    'payroll-regzel-snapshot.v1',
                    (string) $this->supplierId,
                    'production',
                    'REGZELDOPL25',
                    $sourceHash,
                ]),
            ),
            'source_snapshot_hash' => $sourceHash,
            'xml_sha256' => hash('sha256', $xml),
            'xml_byte_size' => strlen($xml),
            'request_fingerprint' => hash('sha256', 'metadata-mismatch'),
            'idempotency_key_hash' => hash('sha256', 'metadata-mismatch'),
            'created_by' => $this->userId,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neodpovídá archivním metadatům');
        $this->service->snapshotXml($this->supplierId, $id, 'production');
    }

    public function testEncryptedLegacySnapshotReplaysThroughDownloadAndAssembler(): void
    {
        $current = $this->builder->buildSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
        );
        $snapshot = new RegzelPayloadSnapshot(
            supplierId: $current->supplierId,
            officeId: $current->officeId,
            environment: $current->environment,
            interaction: $current->interaction,
            csszWorkplaceCode: $current->csszWorkplaceCode,
            taxOfficeCode: '2001',
            taxOfficeWorkplaceCode: null,
            socialSecurityVariableSymbol: $current->socialSecurityVariableSymbol,
            payerReferenceNumber: '123456789',
            notificationDataBoxId: $current->notificationDataBoxId,
            socialEnterprise: $current->socialEnterprise,
            employmentAgency: $current->employmentAgency,
            protectedLaborMarket: $current->protectedLaborMarket,
            employerSettingsRowVersion: $current->employerSettingsRowVersion,
            officeRowVersion: $current->officeRowVersion,
            profileRowVersion: $current->profileRowVersion,
            supplierUpdatedAt: $current->supplierUpdatedAt,
            schemaReference: RegzelPayloadSnapshot::LEGACY_SCHEMA_REFERENCE,
            mappingVersion: RegzelPayloadSnapshot::LEGACY_MAPPING_VERSION,
        );
        $json = $snapshot->canonicalJson();
        $sourceHash = hash('sha256', $json);
        $xml = (new RegzelXmlGenerator())->generate($snapshot);
        $id = $this->repository->insertSnapshot([
            'supplier_id' => $this->supplierId,
            'environment' => 'production',
            'office_id' => $this->officeId,
            'document_type' => 'REGZELDOPL25',
            'interaction_code' => 'supplemental_information',
            'mapping_version' => RegzelPayloadSnapshot::LEGACY_MAPPING_VERSION,
            'xsd_version' => RegzelPayloadSnapshot::XSD_VERSION,
            'source_manifest_json' => '{}',
            'snapshot_ciphertext' => $this->encryption->encryptFor(
                $json,
                implode('|', [
                    'payroll-regzel-snapshot.v1',
                    (string) $this->supplierId,
                    'production',
                    'REGZELDOPL25',
                    $sourceHash,
                ]),
            ),
            'source_snapshot_hash' => $sourceHash,
            'xml_sha256' => hash('sha256', $xml),
            'xml_byte_size' => strlen($xml),
            'request_fingerprint' => hash('sha256', 'legacy-replay'),
            'idempotency_key_hash' => hash('sha256', 'legacy-replay'),
            'created_by' => $this->userId,
        ]);

        $download = $this->service->snapshotXml(
            $this->supplierId,
            $id,
            'production',
        );
        self::assertSame($xml, $download['xml']);
        self::assertStringContainsString('<kodFU>2001</kodFU>', $download['xml']);
        self::assertStringNotContainsString('<kodPracovisteFU>', $download['xml']);
        self::assertStringContainsString('<vcp>123456789</vcp>', $download['xml']);

        $payload = (new RegzelSubmissionPayloadAssembler(
            $this->repository,
            $this->service,
        ))->assemble($this->supplierId, $id, 'production');
        self::assertSame(RegzelPayloadSnapshot::LEGACY_MAPPING_VERSION, $payload->mappingVersion);
        self::assertSame($xml, $payload->xml);
    }

    public function testProductionAndTestVariableSymbolsAreFailClosedAndSeparated(): void
    {
        $production = $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
            'same-environment-local-key',
            $this->userId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices
                SET social_security_variable_symbol = "9994567890",
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->officeId]);

        $test = $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'test',
            'same-environment-local-key',
            $this->userId,
        );
        self::assertSame('test', $test['environment']);
        self::assertSame('production', $production['environment']);
        self::assertNotSame($production['id'], $test['id']);
        self::assertNotSame(
            $production['source_snapshot_hash'],
            $test['source_snapshot_hash'],
        );

        try {
            $this->service->prepareSupplementalInformation(
                $this->supplierId,
                $this->officeId,
                'production',
                'same-environment-local-key',
                $this->userId,
            );
            self::fail('Testovací VS nesmí projít do produkčního payloadu.');
        } catch (RegzelValidationException $exception) {
            self::assertSame(
                'regzel_test_variable_symbol_forbidden',
                $exception->validationCode,
            );
        }
    }

    public function testPersistedSnapshotCannotBeUpdatedOrDeleted(): void
    {
        $prepared = $this->service->prepareSupplementalInformation(
            $this->supplierId,
            $this->officeId,
            'production',
            'synthetic-regzel-immutable',
            $this->userId,
        );
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_regzel_payload_snapshots
                SET xml_byte_size = xml_byte_size + 1
              WHERE supplier_id = ? AND id = ?',
        );
        try {
            $update->execute([$this->supplierId, $prepared['id']]);
            self::fail('REGZEL snapshot nesmí být změnitelný.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'Payroll REGZEL payload snapshots are immutable',
                $exception->getMessage(),
            );
        }

        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_regzel_payload_snapshots
              WHERE supplier_id = ? AND id = ?',
        );
        try {
            $delete->execute([$this->supplierId, $prepared['id']]);
            self::fail('REGZEL snapshot nesmí být smazatelný.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                'Payroll REGZEL payload snapshots are immutable',
                $exception->getMessage(),
            );
        }
    }
}
