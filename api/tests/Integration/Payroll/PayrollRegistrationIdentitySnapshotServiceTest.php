<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentitySnapshotRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRegistrationIdentitySnapshotServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSensitiveData $sensitive;
    private PayrollRegistrationIdentityService $identities;
    private PayrollRegistrationIdentitySnapshotRepository $snapshotRepository;
    private PayrollRegistrationIdentitySnapshotService $snapshots;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $identityId;
    private int $revisionId;
    private int $runId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $db;
        $this->sensitive = $sensitive;
        if (!$db->hasTable('payroll_registration_identity_snapshots')) {
            $this->markTestSkipped('Migrace 1288 neproběhla.');
        }
        $identityRepository = new PayrollRegistrationIdentityRepository($db);
        $this->identities = new PayrollRegistrationIdentityService(
            $identityRepository,
            $sensitive,
            \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry::defaults(),
        );
        $this->snapshotRepository =
            new PayrollRegistrationIdentitySnapshotRepository($db);
        $this->snapshots = new PayrollRegistrationIdentitySnapshotService(
            $this->snapshotRepository,
            $this->identities,
            new PayrollRegistrationIdentitySnapshotBuilder(),
            $sensitive,
            $encryption,
        );

        $pdo = $db->pdo();
        $sourceSupplierId = $this->queryPositiveInt(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        );
        $this->userId = $this->queryPositiveInt(
            $pdo,
            'SELECT MIN(id) FROM users',
        );
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        [$this->employeeId, $this->employmentId, $this->identityId] =
            $this->createPerson($pdo, $this->supplierId);
        $this->insertIdentifier(
            'foreign_tax_identifier',
            'FOREIGN-SYNTHETIC-SNAPSHOT-001',
        );
        $this->identities->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '500000000000000000005',
            '2026-08-01',
            'verified_manual_import',
            'synthetic:snapshot-source',
            null,
            $this->userId,
        );
        [$this->runId, $this->revisionId] = $this->createRevision(
            $pdo,
            $this->supplierId,
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

    public function testFreezesEncryptedImmutableSnapshotIdempotently(): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );
        $created = $this->freeze(
            $submissionId,
            'production',
            'snapshot-regzec-idempotent',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET first_name = "Změněno", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->identityId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->runId]);
        $replay = $this->freeze(
            $submissionId,
            'production',
            'snapshot-regzec-idempotent',
        );

        self::assertTrue($created['created']);
        self::assertFalse($replay['created']);
        self::assertSame($created['id'], $replay['id']);
        self::assertSame(
            $created['request_fingerprint'],
            $replay['request_fingerprint'],
        );

        $sensitive = $this->snapshots->sensitiveSnapshot(
            $this->supplierId,
            $created['id'],
            'production',
        );
        $identifiers = $sensitive['identifiers'] ?? null;
        $identity = $sensitive['identity'] ?? null;
        $external = $sensitive['employment_external_identifier'] ?? null;
        self::assertIsArray($identifiers);
        self::assertIsArray($identity);
        self::assertIsArray($external);
        self::assertNull($identifiers['birth_number']);
        self::assertSame(
            'Jana',
            $identity['first_name'],
        );
        self::assertSame(
            '500000000000000000005',
            $external['value'],
        );

        $stored = $this->snapshotRow($created['id']);
        self::assertStringStartsWith(
            'enc:v2:',
            $this->databaseString($stored, 'snapshot_ciphertext'),
        );
        $databaseRow = $this->scalarRow($stored);
        self::assertStringNotContainsString('Jana', $databaseRow);
        self::assertStringNotContainsString(
            'FOREIGN-SYNTHETIC-SNAPSHOT-001',
            $databaseRow,
        );
        self::assertStringNotContainsString(
            '500000000000000000005',
            $databaseRow,
        );

        $frozen = $this->snapshots->sensitiveSnapshot(
            $this->supplierId,
            $created['id'],
            'production',
        );
        $frozenIdentity = $frozen['identity'] ?? null;
        self::assertIsArray($frozenIdentity);
        self::assertSame('Jana', $frozenIdentity['first_name']);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_registration_identity_snapshots
                SET effective_on = effective_on
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $created['id']]);
    }

    public function testFailsClosedForOpenResolutionTask(): void
    {
        $this->identities->openResolutionTask(
            $this->supplierId,
            $this->employmentId,
            'production',
            'person_identity',
            'synthetic_identity_unresolved',
            null,
            null,
            null,
            $this->userId,
        );
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );

        $this->expectCode(
            'registration_identity_unresolved',
            fn () => $this->freeze(
                $submissionId,
                'production',
                'snapshot-regzec-unresolved',
            ),
        );
    }

    public function testPrezecFailsWithoutBirthNumberOrEcp(): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'PREZEC26',
            'production',
        );

        $this->expectCode(
            'registration_identity_prezec_bno_missing',
            fn () => $this->freeze(
                $submissionId,
                'production',
                'snapshot-prezec-bno-missing',
            ),
        );
    }

    public function testScopesIdPpvByEnvironmentAndRejectsForeignTenant(): void
    {
        $testSubmissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'test',
        );
        $testSnapshot = $this->freeze(
            $testSubmissionId,
            'test',
            'snapshot-regzec-test-environment',
        );
        $testSensitive = $this->snapshots->sensitiveSnapshot(
            $this->supplierId,
            $testSnapshot['id'],
            'test',
        );
        self::assertNull(
            $testSensitive['employment_external_identifier'],
        );

        $this->expectCode(
            'registration_identity_submission_scope_mismatch',
            fn () => $this->snapshots->freeze(
                $this->otherSupplierId,
                $testSubmissionId,
                $this->revisionId,
                $this->employeeId,
                $this->employmentId,
                'test',
                '2026-08-04',
                'snapshot-regzec-cross-tenant',
                $this->userId,
            ),
        );
    }

    public function testRejectsEmployeeOutsideSourceRevision(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
        ]);
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );

        $this->expectCode(
            'registration_identity_submission_scope_mismatch',
            fn () => $this->freeze(
                $submissionId,
                'production',
                'snapshot-person-outside-revision',
            ),
        );
    }

    public function testRejectsEmploymentOutsideSourceRevision(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_employments
              WHERE supplier_id = ? AND revision_id = ? AND employment_id = ?'
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
        ]);
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );

        $this->expectCode(
            'registration_identity_submission_scope_mismatch',
            fn () => $this->freeze(
                $submissionId,
                'production',
                'snapshot-employment-outside-revision',
            ),
        );
    }

    public function testRejectsObligationIntervalDifferentFromEffectiveDate(): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_obligations obligation
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id
                AND submission.environment = obligation.environment
                AND submission.obligation_id = obligation.id
                SET obligation.period_end = "2026-08-05"
              WHERE submission.supplier_id = ? AND submission.id = ?'
        )->execute([$this->supplierId, $submissionId]);

        $this->expectCode(
            'registration_identity_effective_date_mismatch',
            fn () => $this->freeze(
                $submissionId,
                'production',
                'snapshot-obligation-date-mismatch',
            ),
        );
    }

    public function testIdempotentReplayRejectsDifferentEmployee(): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );
        $this->freeze(
            $submissionId,
            'production',
            'snapshot-replay-employee-scope',
        );

        $this->expectCode(
            'registration_identity_idempotent_replay_scope_mismatch',
            fn () => $this->snapshots->freeze(
                $this->supplierId,
                $submissionId,
                $this->revisionId,
                $this->employeeId + 1000000,
                $this->employmentId,
                'production',
                '2026-08-04',
                'snapshot-replay-employee-scope',
                $this->userId,
            ),
        );
    }

    public function testIdempotentReplayRejectsDifferentEffectiveDate(): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );
        $this->freeze(
            $submissionId,
            'production',
            'snapshot-replay-effective-date',
        );

        $this->expectCode(
            'registration_identity_idempotent_replay_scope_mismatch',
            fn () => $this->snapshots->freeze(
                $this->supplierId,
                $submissionId,
                $this->revisionId,
                $this->employeeId,
                $this->employmentId,
                'production',
                '2026-08-05',
                'snapshot-replay-effective-date',
                $this->userId,
            ),
        );
    }

    public function testRejectsRecomputedManifestWithChangedSourceVersions(): void
    {
        $this->assertManifestTamperingRejected(
            static function (array $manifest): array {
                $sourceVersions = $manifest['source_versions'] ?? null;
                self::assertIsArray($sourceVersions);
                $identity = $sourceVersions['identity'] ?? null;
                self::assertIsArray($identity);
                $identity['row_version'] = 999;
                $sourceVersions['identity'] = $identity;
                $manifest['source_versions'] = $sourceVersions;

                return $manifest;
            },
        );
    }

    public function testRejectsRecomputedManifestWithChangedRevisionHash(): void
    {
        $this->assertManifestTamperingRejected(
            static function (array $manifest): array {
                $manifest['source_revision_input_hash'] = str_repeat('b', 64);

                return $manifest;
            },
        );
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_hash:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,workflow_status:string,
     *   official_submission_supported:false,created:bool
     * }
     */
    private function freeze(
        int $submissionId,
        string $environment,
        string $idempotencyKey,
    ): array {
        return $this->snapshots->freeze(
            $this->supplierId,
            $submissionId,
            $this->revisionId,
            $this->employeeId,
            $this->employmentId,
            $environment,
            '2026-08-04',
            $idempotencyKey,
            $this->userId,
        );
    }

    /** @return array{int,int,int} */
    private function createPerson(PDO $pdo, int $supplierId): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Zobrazené jméno se nesmí parsovat", "employee",
                     "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, birth_date, birth_place,
                 birth_country_code, citizenship_country_code, sex,
                 effective_from)
             VALUES (?, ?, "Zobrazené jméno se nesmí parsovat",
                     "Jana", "Novotná", "Nováková", "1991-02-03",
                     "Testov", "CZ", "CZ", "female", "2026-01-01")'
        )->execute([$supplierId, $employeeId]);
        $identityId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, "registration-snapshot-synthetic",
                     "employment", "active", "2026-08-01", 1000000)'
        )->execute([$supplierId, $employeeId]);

        return [$employeeId, (int) $pdo->lastInsertId(), $identityId];
    }

    private function insertIdentifier(string $type, string $value): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, ?, 'enc:v2:pending', ?, '')"
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $type,
            random_bytes(32),
        ]);
        $id = (int) $pdo->lastInsertId();
        $field = $type === 'foreign_tax_identifier'
            ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
            : PayrollSensitiveField::PERSONAL_IDENTIFIER;
        $sealed = $this->sensitive->seal(
            $value,
            $field,
            $this->supplierId,
            $id,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $id,
        ]);
    }

    /** @return array{int,int} */
    private function createRevision(PDO $pdo, int $supplierId): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-08-01", "2026-09-10", "approved", 1)'
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $inputJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $supplierId,
            'period_start' => '2026-08-01',
        ]);
        $resultJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash(
                'sha256',
                "synthetic-registration-snapshot:{$supplierId}:{$runId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        )->execute([$supplierId, $revisionId, $this->employeeId]);
        $employmentInput = CanonicalJson::encode([
            'schema_version' => 'synthetic-registration-membership.v1',
            'employment_id' => $this->employmentId,
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, "calculated")'
        )->execute([
            $supplierId,
            $revisionId,
            $this->employeeId,
            $this->employmentId,
            $employmentInput,
            hash('sha256', $employmentInput),
        ]);

        return [$runId, $revisionId];
    }

    private function createSubmission(
        PDO $pdo,
        string $agendaCode,
        string $environment,
    ): int {
        $nonce = bin2hex(random_bytes(8));
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end,
                 obligation_kind, preferred_channel, status,
                 source_event_type, source_event_reference,
                 source_event_hash, request_fingerprint,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, "employment", ?, "2026-08-04",
                     "2026-08-04", "regular", "manual_upload", "open",
                     "registration_snapshot_test", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $environment,
            $agendaCode,
            "employment:{$this->employmentId}",
            "registration-snapshot:{$nonce}",
            hash('sha256', "source:{$nonce}"),
            hash('sha256', "request:{$nonce}"),
            hash('sha256', "obligation:{$nonce}", true),
            $this->userId,
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_revision_id, source_snapshot_hash,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, "regular", "manual_upload", "draft", ?,
                     ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $environment,
            $obligationId,
            $this->revisionId,
            hash('sha256', "submission-source:{$nonce}"),
            hash('sha256', "submission-request:{$nonce}"),
            hash('sha256', "submission:{$nonce}", true),
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<mixed> */
    private function snapshotRow(int $snapshotId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_registration_identity_snapshots
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $snapshotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function queryPositiveInt(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Testovací databázový dotaz selhal.');
        }
        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                'Testovací databáze nevrátila číselnou hodnotu.',
            );
        }
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($validated)) {
            throw new \UnexpectedValueException(
                'Testovací databáze nevrátila kladné ID.',
            );
        }

        return $validated;
    }

    /** @param array<mixed> $row */
    private function databaseString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací databáze nevrátila řetězec {$key}.",
            );
        }

        return $value;
    }

    /** @param array<mixed> $row */
    private function scalarRow(array $row): string
    {
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = '';
                continue;
            }
            if (!is_scalar($value)) {
                throw new \UnexpectedValueException(
                    'Testovací databáze vrátila neslučitelnou hodnotu.',
                );
            }
            $values[] = (string) $value;
        }

        return implode('|', $values);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    private function assertManifestTamperingRejected(callable $mutator): void
    {
        $submissionId = $this->createSubmission(
            $this->db->pdo(),
            'REGZEC25',
            'production',
        );
        $created = $this->freeze(
            $submissionId,
            'production',
            'snapshot-manifest-tampering-' . bin2hex(random_bytes(4)),
        );
        $stored = $this->snapshotRepository->find(
            $this->supplierId,
            $created['id'],
            'production',
        );
        self::assertNotNull($stored);
        $manifest = $this->stringObject(
            json_decode(
                $stored['source_manifest_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        $tampered = $mutator($manifest);
        $stored['source_manifest_json'] = CanonicalJson::encode($tampered);
        $stored['source_manifest_hash'] = hash(
            'sha256',
            $stored['source_manifest_json'],
        );
        $stored['request_fingerprint'] = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-registration-identity-freeze-request.v1',
                'scope' => [
                    'supplier_id' => $stored['supplier_id'],
                    'submission_id' => $stored['submission_id'],
                    'source_revision_id' =>
                        $stored['source_revision_id'],
                    'employee_id' => $stored['employee_id'],
                    'employment_id' => $stored['employment_id'],
                    'environment' => $stored['environment'],
                    'agenda_code' => $stored['agenda_code'],
                    'effective_on' => $stored['effective_on'],
                ],
                'source_manifest_hash' => $stored['source_manifest_hash'],
                'snapshot_fingerprint' =>
                    $stored['snapshot_fingerprint'],
            ]),
        );
        $method = new \ReflectionMethod(
            PayrollRegistrationIdentitySnapshotService::class,
            'verifyStoredSnapshot',
        );
        $rejected = false;
        try {
            $method->invoke($this->snapshots, $stored);
        } catch (\RuntimeException) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'Přepočítaný veřejný SHA nesmí autentizovat změněný manifest.',
        );
    }

    /** @return array<string,mixed> */
    private function stringObject(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Testovací JSON neobsahuje objekt.',
            );
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací JSON obsahuje neplatný klíč.',
                );
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @param callable():mixed $callback */
    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }
}
