<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JmhzPreparationSnapshotRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzPreparationSnapshotRepository $repository;
    private JmhzPreparationSnapshotService $service;
    private JmhzScenario1DocumentService $scenario1;
    private int $supplierId;
    private int $runId;
    private int $revisionId;
    private PayrollSensitiveData $sensitiveData;
    private SecretEncryption $encryption;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        if (!$db->hasTable('payroll_jmhz_preparation_snapshots')) {
            $this->markTestSkipped('Migrace 1360 neproběhla.');
        }
        $this->db = $db;
        $this->repository = new JmhzPreparationSnapshotRepository($db);
        $service = $container->get(JmhzPreparationSnapshotService::class);
        self::assertInstanceOf(JmhzPreparationSnapshotService::class, $service);
        $this->service = $service;
        $scenario1 = $container->get(JmhzScenario1DocumentService::class);
        self::assertInstanceOf(JmhzScenario1DocumentService::class, $scenario1);
        $this->scenario1 = $scenario1;
        $this->sensitiveData = $container->get(PayrollSensitiveData::class);
        $this->encryption = $container->get(SecretEncryption::class);
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        [$this->runId, $this->revisionId] = $this->createRevision($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCurrentApprovedPreparationIsInsertedAndImmutable(): void
    {
        $id = $this->repository->insert($this->record());
        $stored = $this->repository->find($this->supplierId, 'test', $id);
        self::assertIsArray($stored);
        self::assertSame('blocked', $stored['readiness_status']);
        self::assertSame(1, $stored['issue_count']);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_preparation_snapshots
                SET issue_count = 2 WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $id]);
    }

    public function testCorrectionPreparationListIsTenantEnvironmentPeriodRunAndReadinessScoped(): void
    {
        $blockedId = $this->repository->insert($this->record());
        $ready = $this->record();
        $ready['readiness_status'] = 'source_ready';
        $ready['issue_count'] = 0;
        $ready['readiness_json'] = CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => 'source_ready',
            'issue_count' => 0,
            'issues' => [],
            'official_submission_supported' => false,
        ]);
        $ready['readiness_sha256'] = hash('sha256', $ready['readiness_json']);
        $ready['source_manifest_sha256'] = str_repeat('c', 64);
        $ready['snapshot_fingerprint'] = str_repeat('d', 64);
        $ready['request_fingerprint'] = str_repeat('e', 64);
        $ready['idempotency_key_hash'] = hash('sha256', 'ready-correction-preparation', true);
        $readyId = $this->repository->insert($ready);

        $listed = $this->repository->listSourceReadyForCorrection(
            $this->supplierId,
            'test',
            $this->runId,
            '2026-07-01',
        );
        self::assertCount(1, $listed);
        self::assertSame($readyId, $listed[0]['id']);
        self::assertSame($this->revisionId, $listed[0]['source_revision_id']);
        self::assertSame(1, $listed[0]['revision_no']);
        self::assertSame('2026-07-01', $listed[0]['period_start']);
        self::assertIsString($listed[0]['created_at']);
        self::assertNotSame($blockedId, $readyId);
        self::assertSame([], $this->repository->listSourceReadyForCorrection(
            $this->supplierId,
            'production',
            $this->runId,
            '2026-07-01',
        ));
        self::assertSame([], $this->repository->listSourceReadyForCorrection(
            $this->supplierId,
            'test',
            $this->runId + 1,
            '2026-07-01',
        ));
        self::assertSame([], $this->repository->listSourceReadyForCorrection(
            $this->supplierId + 999999,
            'test',
            $this->runId,
            '2026-07-01',
        ));
    }

    public function testBlockedPreparationIsEncryptedAndIdempotent(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-preparation',
            null,
        );
        $second = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-preparation',
            null,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame('blocked', $first['readiness_status']);
        self::assertFalse($first['official_submission_supported']);
        self::assertNotEmpty($first['issues']);
        $statement = $this->db->pdo()->prepare(
            'SELECT snapshot_ciphertext, readiness_json
               FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $first['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith('enc:v2:', (string) $stored['snapshot_ciphertext']);
        self::assertStringNotContainsString('entity_id', (string) $stored['readiness_json']);
        self::assertStringNotContainsString('snapshot_ciphertext', CanonicalJson::encode($first));
    }

    public function testVerifiedLoaderReturnsTypedPayloadWithoutExposingCiphertext(): void
    {
        $created = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-verified-loader',
            null,
        );

        $verified = $this->service->loadVerified(
            $this->supplierId,
            'test',
            (int) $created['id'],
        );

        self::assertSame($created['id'], $verified->id);
        self::assertSame($this->revisionId, $verified->sourceRevisionId);
        self::assertSame('mixed', $verified->scenarioKey);
        self::assertSame([], $verified->payload['scope']['scenario_set'] ?? null);
        self::assertSame(
            JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            $verified->payload['schema_reference'],
        );
        self::assertObjectNotHasProperty('snapshotCiphertext', $verified);
    }

    public function testVerifiedLoaderKeepsCurrentApprovedCorrectionRevisionUsable(): void
    {
        $created = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-correction-source-loader',
            null,
        );
        $verified = $this->service->loadVerified(
            $this->supplierId,
            'test',
            (int) $created['id'],
        );

        self::assertSame($this->revisionId, $verified->sourceRevisionId);
    }

    public function testVerifiedLoaderHidesCrossEnvironmentAndUnknownScope(): void
    {
        $created = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-loader-scope',
            null,
        );

        foreach ([
            [$this->supplierId, 'production', (int) $created['id']],
            [$this->supplierId, 'test', PHP_INT_MAX],
        ] as [$supplierId, $environment, $preparationId]) {
            try {
                $this->service->loadVerified(
                    $supplierId,
                    $environment,
                    $preparationId,
                );
                self::fail('Cizí scope přípravy JMHZ musí zůstat skrytý.');
            } catch (JmhzPreparationSnapshotException $exception) {
                self::assertSame('jmhz_preparation_not_found', $exception->validationCode);
            }
        }
    }

    public function testVerifiedLoaderRejectsSupersededSourceRevision(): void
    {
        $created = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-loader-superseded',
            null,
        );
        $this->createSecondRevision($this->db->pdo(), $this->runId);

        try {
            $this->service->loadVerified(
                $this->supplierId,
                'test',
                (int) $created['id'],
            );
            self::fail('Zastaralá příprava JMHZ nesmí být aktuálním zdrojem dokumentu.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_preparation_source_not_current',
                $exception->validationCode,
            );
        }
    }

    public function testScenarioResolverIsReadOnlyAndKeepsUnfrozenInteractionsBlocked(): void
    {
        $created = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-read-only-resolver',
            null,
        );
        $before = $this->tableCounts([
            'payroll_jmhz_preparation_snapshots',
            'payroll_jmhz_preparation_idempotency_claims',
            'payroll_submissions',
            'payroll_submission_artifacts',
        ]);

        $resolution = $this->scenario1->resolve(
            $this->supplierId,
            'test',
            (int) $created['id'],
        );

        self::assertSame('blocked', $resolution->status());
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );
        self::assertSame(['jmhz_scenario1_scope_unsupported'], $codes);
        self::assertSame($before, $this->tableCounts(array_keys($before)));
    }

    /**
     * @param list<string> $tables
     * @return array<string,int>
     */
    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->db->pdo()
                ->query("SELECT COUNT(*) FROM {$table}")
                ->fetchColumn();
        }
        return $counts;
    }

    public function testDifferentIdempotencyKeysAreBothBoundToRequestDeduplication(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-a',
            null,
        );
        $second = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-b',
            null,
        );
        $replay = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-request-b',
            null,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertFalse($replay['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['id'], $replay['id']);
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_preparation_idempotency_claims
              WHERE supplier_id = ? AND environment = "test"
                AND preparation_snapshot_id = ?'
        );
        $statement->execute([$this->supplierId, $first['id']]);
        self::assertSame(2, (int) $statement->fetchColumn());
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function historicalPreparationContracts(): iterable
    {
        yield 'v1' => [
            JmhzPreparationSnapshotBuilder::LEGACY_BUILDER_VERSION,
            JmhzPreparationSnapshot::LEGACY_SCHEMA_REFERENCE,
            'payroll-jmhz-preparation-source-manifest.v1',
            'payroll-jmhz-preparation-request.v1',
        ];
        yield 'v2' => [
            JmhzPreparationSnapshotBuilder::PREVIOUS_V2_BUILDER_VERSION,
            JmhzPreparationSnapshot::PREVIOUS_V2_SCHEMA_REFERENCE,
            'payroll-jmhz-preparation-source-manifest.v2',
            'payroll-jmhz-preparation-request.v2',
        ];
        yield 'v3' => [
            JmhzPreparationSnapshotBuilder::PREVIOUS_BUILDER_VERSION,
            JmhzPreparationSnapshot::PREVIOUS_SCHEMA_REFERENCE,
            'payroll-jmhz-preparation-source-manifest.v3',
            'payroll-jmhz-preparation-request.v3',
        ];
        yield 'v4' => [
            JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION,
            JmhzPreparationSnapshot::PREVIOUS_V4_SCHEMA_REFERENCE,
            'payroll-jmhz-preparation-source-manifest.v4',
            'payroll-jmhz-preparation-request.v4',
        ];
    }

    #[DataProvider('historicalPreparationContracts')]
    public function testHistoricalPreparationRemainsVerifiableAfterV4Upgrade(
        string $builderVersion,
        string $snapshotSchema,
        string $manifestSchema,
        string $requestSchema,
    ): void
    {
        $key = 'synthetic-jmhz-historical-' . $builderVersion;
        $idempotencyHash = hash('sha256', $key, true);
        $revision = $this->repository->lockSource($this->supplierId, $this->revisionId);
        self::assertIsArray($revision);
        $row = $revision['revision'];
        self::assertIsArray($row);
        $issues = [[
            'code' => 'scenario_selector_not_frozen',
            'entity_type' => 'revision',
            'entity_id' => $this->revisionId,
            'attribute_ids' => ['10239'],
        ]];
        $scope = [
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'run_id' => $this->runId,
            'source_revision_id' => $this->revisionId,
            'revision_no' => 1,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'scenario_key' => 'scenario_1',
        ];
        $specification = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
        $sourceRevision = [
            'input_snapshot_hash' => $row['input_snapshot_hash'],
            'result_snapshot_hash' => $row['result_snapshot_hash'],
            'ruleset_manifest_hash' => $row['ruleset_manifest_hash'],
        ];
        $sourceVersions = ['office_id' => null, 'employments' => []];
        $payload = [
            'schema_reference' => $snapshotSchema,
            'builder_version' => $builderVersion,
            'scope' => $scope,
            'specification' => $specification,
            'source_revision' => $sourceRevision,
            'header' => [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'environment' => 'test',
            ],
            'employer_summary' => ['employer' => null, 'office' => null],
            'people' => [],
            'source_versions' => $sourceVersions,
            'readiness_issue_codes' => ['scenario_selector_not_frozen'],
            'readiness_issues' => $issues,
        ];
        $snapshot = new JmhzPreparationSnapshot($payload, $issues);
        $plaintext = $snapshot->canonicalJson();
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $plaintext,
            'jmhz-preparation-snapshot',
            $this->supplierId,
        );
        $readinessJson = CanonicalJson::encode($snapshot->readiness());
        $readinessHash = hash('sha256', $readinessJson);
        $manifestJson = CanonicalJson::encode([
            'schema_reference' => $manifestSchema,
            'builder_version' => $builderVersion,
            'scope' => $scope,
            'specification' => $specification,
            'source_revision' => $sourceRevision,
            'source_versions' => $sourceVersions,
            'snapshot_fingerprint' => $fingerprint,
            'readiness_sha256' => $readinessHash,
        ]);
        $manifestHash = hash('sha256', $manifestJson);
        $requestFingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => $requestSchema,
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'source_revision_id' => $this->revisionId,
            'source_manifest_sha256' => $manifestHash,
        ]));
        $ciphertext = $this->encryption->encryptFor(
            $plaintext,
            "payroll:jmhz-preparation:{$this->supplierId}:test:{$this->revisionId}:{$fingerprint}:{$manifestHash}:{$readinessHash}",
        );
        $id = $this->repository->insert([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'run_id' => $this->runId,
            'source_revision_id' => $this->revisionId,
            'period_start' => '2026-07-01',
            'scenario_key' => 'scenario_1',
            'builder_version' => $builderVersion,
            'readiness_status' => 'blocked',
            'issue_count' => 1,
            'source_manifest_json' => $manifestJson,
            'source_manifest_sha256' => $manifestHash,
            'readiness_json' => $readinessJson,
            'readiness_sha256' => $readinessHash,
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_fingerprint' => $fingerprint,
            'request_fingerprint' => $requestFingerprint,
            'idempotency_key_hash' => $idempotencyHash,
            'created_by' => null,
        ]);
        self::assertTrue($this->repository->insertIdempotencyClaim(
            $this->supplierId,
            'test',
            $idempotencyHash,
            $this->revisionId,
            null,
        ));
        $this->repository->bindIdempotencyClaim(
            $this->supplierId,
            'test',
            $idempotencyHash,
            $id,
        );

        $replay = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            $key,
            null,
        );

        self::assertFalse($replay['created']);
        self::assertSame($id, $replay['id']);
        self::assertSame(
            $builderVersion,
            $replay['builder_version'],
        );
    }

    public function testIdempotencyClaimRejectsDifferentSourceRevision(): void
    {
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-jmhz-stable-scope',
            null,
        );
        $otherRevisionId = $this->createSecondRevision(
            $this->db->pdo(),
            $this->runId,
        );

        try {
            $this->service->freeze(
                $this->supplierId,
                $otherRevisionId,
                'test',
                'synthetic-jmhz-stable-scope',
                null,
            );
            self::fail('Stejný idempotency klíč nesmí změnit zdrojovou revizi.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_preparation_idempotency_scope_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testUnknownRevisionReturnsDomainErrorWithoutClaim(): void
    {
        $key = 'synthetic-jmhz-unknown-revision';
        try {
            $this->service->freeze(
                $this->supplierId,
                PHP_INT_MAX,
                'test',
                $key,
                null,
            );
            self::fail('Neexistující revize musí být odmítnuta doménově.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame('jmhz_revision_not_found', $exception->validationCode);
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_preparation_idempotency_claims
              WHERE supplier_id = ? AND environment = "test"
                AND idempotency_key_hash = ?',
        );
        $statement->execute([
            $this->supplierId,
            hash('sha256', $key, true),
        ]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testInsertGuardRejectsMismatchedPeriod(): void
    {
        $record = $this->record();
        $record['period_start'] = '2026-08-01';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('current approved revision');
        $this->repository->insert($record);
    }

    /** @return array<string,mixed> */
    private function record(): array
    {
        $manifest = CanonicalJson::encode([
            'schema_reference' => 'synthetic-jmhz-preparation-manifest.v1',
        ]);
        $readiness = CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => 'blocked',
            'issue_count' => 1,
            'issues' => [[
                'code' => 'jmhz_synthetic_blocker',
                'entity_type' => 'revision',
                'count' => 1,
                'attribute_ids' => [],
            ]],
            'official_submission_supported' => false,
        ]);
        return [
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'run_id' => $this->runId,
            'source_revision_id' => $this->revisionId,
            'period_start' => '2026-07-01',
            'scenario_key' => 'scenario_1',
            'builder_version' => 'jmhz-preparation-source.v1',
            'readiness_status' => 'blocked',
            'issue_count' => 1,
            'source_manifest_json' => $manifest,
            'source_manifest_sha256' => hash('sha256', $manifest),
            'readiness_json' => $readiness,
            'readiness_sha256' => hash('sha256', $readiness),
            'snapshot_ciphertext' => 'enc:v2:synthetic',
            'snapshot_fingerprint' => str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
            'idempotency_key_hash' => hash('sha256', 'synthetic-idempotency', true),
            'created_by' => null,
        ];
    }

    /** @return array{int,int} */
    private function createRevision(PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-07-01", "2026-08-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-07-01',
            'people' => [],
        ]);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $input),
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "correction", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('c', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "jmhz-preparation:{$this->supplierId}", true),
        ]);
        return [$runId, (int) $pdo->lastInsertId()];
    }

    private function createSecondRevision(PDO $pdo, int $runId): int
    {
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-07-01',
            'people' => [],
        ]);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $input),
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('d', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "jmhz-preparation-second:{$this->supplierId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $runId]);
        return $revisionId;
    }
}
