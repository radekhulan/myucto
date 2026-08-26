<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollRegistrationIdentityServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSensitiveData $sensitive;
    private PayrollRegistrationIdentityService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $identityId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $this->db = $db;
        $this->sensitive = $sensitive;
        if (!$db->hasTable('payroll_identity_resolution_tasks')) {
            $this->markTestSkipped('Migrace 1285 neproběhla.');
        }
        $this->service = new PayrollRegistrationIdentityService(
            new PayrollRegistrationIdentityRepository($db),
            $sensitive,
        );

        $pdo = $db->pdo();
        $sourceSupplier = $this->queryPositiveInt(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        );
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplier,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplier,
        );
        [$this->employeeId, $this->employmentId, $this->identityId] =
            $this->createPerson($pdo, $this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testBuildsExplicitHistoricalIdentityWithOptionalIdentifiers(): void
    {
        $this->service->saveIdentityFacts(
            $this->supplierId,
            $this->employeeId,
            $this->identityId,
            1,
            [
                'title_prefix' => 'Ing.',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'cz',
                'citizenship_country_code' => 'CZ',
                'sex' => 'female',
            ],
        );
        $this->insertIdentifier('ecp', 'ECP-SYNTHETIC-001');
        $this->insertIdentifier('vcp', 'VCP-SYNTHETIC-002');
        $this->insertIdentifier(
            'foreign_tax_identifier',
            'FOREIGN-SYNTHETIC-003',
        );

        $snapshot = $this->service->sensitiveIdentityAt(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
        );

        self::assertSame('Jana', $snapshot['identity']['first_name']);
        self::assertSame('Novotná', $snapshot['identity']['last_name']);
        self::assertSame('CZ', $snapshot['identity']['birth_country_code']);
        self::assertNull($snapshot['identifiers']['birth_number']);
        self::assertSame(
            'ECP-SYNTHETIC-001',
            $snapshot['identifiers']['ecp'],
        );
        self::assertSame(
            'VCP-SYNTHETIC-002',
            $snapshot['identifiers']['vcp'],
        );
        self::assertSame(
            'FOREIGN-SYNTHETIC-003',
            $snapshot['identifiers']['foreign_tax_identifier'],
        );
    }

    public function testNeverDerivesStructuredNameFromDisplayName(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET first_name = NULL, last_name = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->identityId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('explicitní jméno a příjmení');
        $this->service->sensitiveIdentityAt(
            $this->supplierId,
            $this->employeeId,
            '2026-08-04',
        );
    }

    public function testExternalIdIsEncryptedIdempotentAndEnvironmentScoped(): void
    {
        $production = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '100000000000000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:production',
            null,
            null,
        );
        $replay = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '100000000000000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:production',
            null,
            null,
        );
        $test = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'test',
            '100000000000000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:test',
            null,
            null,
        );
        [, $otherEmploymentId] = $this->createPerson(
            $this->db->pdo(),
            $this->otherSupplierId,
        );
        $otherTenant = $this->service->assignEmploymentExternalId(
            $this->otherSupplierId,
            $otherEmploymentId,
            'production',
            '100000000000000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:other-tenant',
            null,
            null,
        );

        self::assertTrue($production['created']);
        self::assertFalse($replay['created']);
        self::assertSame($production['id'], $replay['id']);
        self::assertNotSame($production['id'], $test['id']);
        self::assertTrue($otherTenant['created']);

        $statement = $this->db->pdo()->prepare(
            'SELECT value_ciphertext, value_hash, value_masked,
                    source_reference_hash
               FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $production['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith(
            'enc:v2:',
            $this->databaseString($stored, 'value_ciphertext'),
        );
        self::assertStringNotContainsString(
            '100000000000000000001',
            $this->scalarRow($stored),
        );
        self::assertSame(
            32,
            strlen($this->databaseString($stored, 'value_hash')),
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $this->databaseString($stored, 'source_reference_hash'),
        );
    }

    public function testJmhzPersonIdentityIsEncryptedEnvironmentScopedAndSnapshotReady(): void
    {
        $production = $this->service->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'production',
            '1000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-oic-evidence:production',
            null,
            null,
        );
        $replay = $this->service->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'production',
            '100 000 000 1',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-oic-evidence:production',
            null,
            null,
        );
        $test = $this->service->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'test',
            '1000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-oic-evidence:test',
            null,
            null,
        );
        $idPpv = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '200000000000000000002',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-id-ppv-evidence:jmhz',
            null,
            null,
        );

        self::assertTrue($production['created']);
        self::assertFalse($replay['created']);
        self::assertSame($production['id'], $replay['id']);
        self::assertNotSame($production['id'], $test['id']);

        $snapshot = $this->service->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'production',
            '2026-08-04',
        );
        self::assertSame('1000000001', $snapshot['person_external_identifier']['value']);
        self::assertSame($production['id'], $snapshot['person_external_identifier']['id']);
        self::assertSame('200000000000000000002', $snapshot['employment_external_identifier']['value']);
        self::assertSame($idPpv['id'], $snapshot['employment_external_identifier']['id']);
        self::assertSame('production', $snapshot['environment']);

        $statement = $this->db->pdo()->prepare(
            'SELECT value_ciphertext, value_hash, value_masked, source_reference_hash
               FROM payroll_person_external_ids
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $production['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith(
            'enc:v2:',
            $this->databaseString($stored, 'value_ciphertext'),
        );
        self::assertStringNotContainsString('1000000001', $this->scalarRow($stored));
        self::assertSame(32, strlen($this->databaseString($stored, 'value_hash')));
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $this->databaseString($stored, 'source_reference_hash'),
        );
    }

    public function testJmhzPersonIdentityRejectsInvalidOicAndMissingPair(): void
    {
        try {
            $this->service->assignPersonExternalId(
                $this->supplierId,
                $this->employeeId,
                'production',
                '1000000002',
                '2026-08-01',
                'verified_manual_import',
                'synthetic-invalid-oic',
                null,
                null,
            );
            self::fail('Neplatná kontrolní číslice OIČ musí být odmítnuta.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('OIČ', $exception->getMessage());
        }

        $this->service->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'production',
            '1000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-valid-oic-without-id-ppv',
            null,
            null,
        );

        $this->expectException(PayrollRegistrationIdentitySnapshotException::class);
        $this->expectExceptionMessage('ID PPV');
        $this->service->sensitiveJmhzIdentityAt(
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            'production',
            '2026-08-04',
        );
    }

    public function testManualJmhzIdentityIsAtomicAndUiStatusStaysMasked(): void
    {
        $assigned = $this->service->assignManualJmhzIdentity(
            $this->supplierId,
            $this->employmentId,
            'test',
            '100 000 000 1',
            '200000000000000000002',
            '2026-08-01',
            null,
            true,
            null,
        );

        self::assertTrue($assigned['person_external_identifier']['created']);
        self::assertTrue($assigned['employment_external_identifier']['created']);

        $status = $this->service->jmhzIdentityStatusAt(
            $this->supplierId,
            $this->employmentId,
            'test',
            '2026-08-04',
        );
        self::assertSame($this->employeeId, $status['employee_id']);
        self::assertSame($this->employmentId, $status['employment_id']);
        self::assertSame('test', $status['environment']);
        self::assertSame('2026-08-04', $status['on_date']);
        self::assertNotNull($status['person_external_identifier']);
        self::assertNotNull($status['employment_external_identifier']);
        self::assertArrayNotHasKey('value', $status['person_external_identifier']);
        self::assertArrayNotHasKey('value', $status['employment_external_identifier']);
        self::assertStringNotContainsString(
            '1000000001',
            json_encode($status, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            '200000000000000000002',
            json_encode($status, JSON_THROW_ON_ERROR),
        );
    }

    public function testManualJmhzIdentityValidatesPairBeforeWriteAndChecksTenant(): void
    {
        try {
            $this->service->assignManualJmhzIdentity(
                $this->supplierId,
                $this->employmentId,
                'test',
                '1000000001',
                'ID-PPV-NENI-CISLO',
                '2026-08-01',
                null,
                true,
                null,
            );
            self::fail('Neplatné ID PPV musí vrátit celou dvojici identifikátorů.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('ID PPV', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM payroll_person_external_ids
              WHERE supplier_id = ' . $this->supplierId,
        )->fetchColumn());
        self::assertSame(0, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM payroll_employment_external_ids
              WHERE supplier_id = ' . $this->supplierId,
        )->fetchColumn());

        $this->expectException(\OutOfBoundsException::class);
        $this->service->assignManualJmhzIdentity(
            $this->otherSupplierId,
            $this->employmentId,
            'test',
            '1000000001',
            '200000000000000000002',
            '2026-08-01',
            null,
            true,
            null,
        );
    }

    public function testExternalIdentifierReplayAndEnvironmentAreImmutable(): void
    {
        $person = $this->service->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'production',
            '1000000001',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-immutable-oic-evidence',
            null,
            null,
        );
        $employment = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '600000000000000000006',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-immutable-id-ppv-evidence',
            null,
            null,
        );

        try {
            $this->service->assignPersonExternalId(
                $this->supplierId,
                $this->employeeId,
                'production',
                '1000000001',
                '2026-08-02',
                'verified_manual_import',
                'synthetic-different-oic-evidence',
                null,
                null,
            );
            self::fail('Replay OIČ s jinou provenance musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('datu nebo důkazu', $exception->getMessage());
        }

        try {
            $this->service->assignEmploymentExternalId(
                $this->supplierId,
                $this->employmentId,
                'production',
                '600000000000000000006',
                '2026-08-01',
                'verified_manual_import',
                'synthetic-different-id-ppv-evidence',
                null,
                null,
            );
            self::fail('Replay ID PPV s jinou provenance musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('datu nebo důkazu', $exception->getMessage());
        }

        foreach ([
            ['payroll_person_external_ids', $person['id']],
            ['payroll_employment_external_ids', $employment['id']],
        ] as [$table, $id]) {
            try {
                $this->db->pdo()->prepare(
                    "UPDATE {$table} SET environment = 'test'
                      WHERE supplier_id = ? AND id = ?"
                )->execute([$this->supplierId, $id]);
                self::fail("{$table}: prostředí musí být databázově neměnné.");
            } catch (\PDOException $exception) {
                self::assertStringContainsString('environment is immutable', $exception->getMessage());
            }
        }
    }

    public function testEmploymentExternalIdRejectsNonNumericJmhzValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ID PPV');
        $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            'ID-PPV-NOT-XSD-VALID',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-invalid-id-ppv',
            null,
            null,
        );
    }

    public function testResolutionStoresOnlyKeyedEvidenceHash(): void
    {
        $external = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '300000000000000000003',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:resolved-external',
            null,
            null,
        );
        $task = $this->service->openResolutionTask(
            $this->supplierId,
            $this->employmentId,
            'production',
            'employment_external_id',
            'waiting_for_assignment',
            1,
            null,
            null,
            null,
        );
        $userId = $this->queryPositiveInt(
            $this->db->pdo(),
            'SELECT MIN(id) FROM users',
        );

        $version = $this->service->resolveTask(
            $this->supplierId,
            $task['id'],
            1,
            'production',
            $external['id'],
            'synthetic-resolution-evidence',
            $userId,
        );

        self::assertSame(2, $version);
        $statement = $this->db->pdo()->prepare(
            'SELECT status, resolution_evidence_hash, resolved_external_id_id
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $task['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertSame('resolved', $stored['status']);
        self::assertSame(
            $external['id'],
            $this->databasePositiveInt($stored, 'resolved_external_id_id'),
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $this->databaseString($stored, 'resolution_evidence_hash'),
        );
        self::assertStringNotContainsString(
            'synthetic-resolution-evidence',
            $this->scalarRow($stored),
        );
    }

    public function testResolutionTaskIsIdempotentAndCannotCrossTenant(): void
    {
        $external = $this->service->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            '400000000000000000004',
            '2026-08-01',
            'verified_manual_import',
            'synthetic-evidence:resolution',
            null,
            null,
        );
        $task = $this->service->openResolutionTask(
            $this->supplierId,
            $this->employmentId,
            'production',
            'employment_external_id',
            'waiting_for_assignment',
            1,
            null,
            null,
            null,
        );
        $replay = $this->service->openResolutionTask(
            $this->supplierId,
            $this->employmentId,
            'production',
            'employment_external_id',
            'waiting_for_assignment',
            1,
            null,
            null,
            null,
        );
        self::assertTrue($task['created']);
        self::assertFalse($replay['created']);
        self::assertSame($task['id'], $replay['id']);

        $this->expectException(\DomainException::class);
        $this->service->resolveTask(
            $this->otherSupplierId,
            $task['id'],
            1,
            'production',
            $external['id'],
            'synthetic-resolution-evidence',
            1,
        );
    }

    /**
     * @return array{int,int,int}
     */
    private function createPerson(PDO $pdo, int $supplierId): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Zobrazené jméno bez parsování", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Zobrazené jméno bez parsování",
                     "Jana", "Novotná", "2026-01-01")'
        )->execute([$supplierId, $employeeId]);
        $identityId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "regzec-synthetic", "employment", "active",
                     "2026-08-01", 0)'
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
    private function databasePositiveInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací databáze nevrátila číselné pole {$key}.",
            );
        }
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($validated)) {
            throw new \UnexpectedValueException(
                "Testovací databáze nevrátila kladné pole {$key}.",
            );
        }

        return $validated;
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
}
