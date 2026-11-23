<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionDenied;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Security\PayrollJmhzIdentifierRevealService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Syntetická čísla: OIČ `1000000001`, ID PPV `200000000000000000002`.
 * Reálné identifikátory od ČSSZ do veřejného repa nepatří.
 */
#[Group('integration')]
final class PayrollJmhzIdentifierRevealServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const OIC = '1000000001';
    private const ID_PPV = '200000000000000000002';
    private const AUDIT_ACTION = 'payroll.jmhz_identity.revealed';

    private Connection $db;
    private PayrollRegistrationIdentityService $identities;
    private PayrollJmhzIdentifierRevealService $service;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $activity = $container->get(ActivityLogger::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(ActivityLogger::class, $activity);
        $this->db = $db;
        if (!$db->hasTable('payroll_person_external_ids')) {
            $this->markTestSkipped('Evidence identifikátorů ČSSZ chybí.');
        }
        $this->identities = new PayrollRegistrationIdentityService(
            new PayrollRegistrationIdentityRepository($db),
            $sensitive,
            \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry::defaults(),
            $activity,
        );
        $this->service = new PayrollJmhzIdentifierRevealService(
            $this->identities,
            new PermissionChecker(new PermissionCatalog()),
            $activity,
        );

        $pdo = $db->pdo();
        $sourceSupplier = $this->queryPositiveInt(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        );
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        [$this->employeeId, $this->employmentId] = $this->createPerson($pdo);
        $this->actorId = $this->createActor($pdo);
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

    public function testRevealsBothIdentifiersAndKeepsPlaintextOutOfAudit(): void
    {
        $this->assignIdentifiers();

        $result = $this->service->reveal(
            $this->supplierId,
            $this->employmentId,
            $this->actorId,
            $this->sensitiveReader(),
            'test',
            '2026-08-04',
            'Porovnání s protokolem o přijetí registrace.',
            '192.0.2.10',
            'synthetic-reveal-test',
        );

        self::assertNotNull($result['person_external_identifier']);
        self::assertNotNull($result['employment_external_identifier']);
        self::assertSame(
            self::OIC,
            $result['person_external_identifier']['value'],
        );
        self::assertSame(
            self::ID_PPV,
            $result['employment_external_identifier']['value'],
        );

        $audit = $this->lastAudit();
        self::assertSame($this->supplierId, $this->integer($audit, 'supplier_id'));
        self::assertSame($this->actorId, $this->integer($audit, 'user_id'));
        self::assertSame($this->employmentId, $this->integer($audit, 'entity_id'));
        $payload = $this->auditPayload($audit);
        self::assertSame(
            'Porovnání s protokolem o přijetí registrace.',
            $payload['reason'],
        );
        self::assertSame('test', $payload['environment']);
        self::assertSame('2026-08-04', $payload['on_date']);
        self::assertSame(['ik_mpsv', 'id_ppv'], $payload['identifiers']);
        $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach ([self::OIC, self::ID_PPV, 'enc:v2:'] as $secret) {
            self::assertStringNotContainsString($secret, $auditJson);
        }
    }

    /**
     * Prostředí a den se do odpovědi promítají, ne jen do auditu: ostré číslo
     * se od testovacího liší a odkrytí nesmí sáhnout do cizí evidence.
     */
    public function testEmptyEvidenceRevealsNothingInsteadOfFailing(): void
    {
        $this->assignIdentifiers();

        $result = $this->service->reveal(
            $this->supplierId,
            $this->employmentId,
            $this->actorId,
            $this->sensitiveReader(),
            'production',
            '2026-08-04',
            'Kontrola ostrého prostředí před prvním hlášením.',
        );

        self::assertNull($result['person_external_identifier']);
        self::assertNull($result['employment_external_identifier']);
        self::assertSame([], $this->auditPayload($this->lastAudit())['identifiers']);
    }

    public function testBroadPayrollWriteDoesNotReplaceSensitivePermission(): void
    {
        $this->assignIdentifiers();
        $role = new EffectiveRole(
            101,
            'Syntetická účetní',
            'staff',
            true,
            [
                'payroll' => AccessLevel::WRITE->value,
                'payroll.employment.write' => AccessLevel::WRITE->value,
            ],
        );

        $this->expectException(PermissionDenied::class);
        try {
            $this->service->reveal(
                $this->supplierId,
                $this->employmentId,
                $this->actorId,
                $role,
                'test',
                '2026-08-04',
                'Porovnání s protokolem o přijetí registrace.',
            );
        } finally {
            self::assertSame(0, $this->auditCount());
        }
    }

    public function testReasonIsMandatoryAndNotAuditedOnFailure(): void
    {
        $this->assignIdentifiers();

        foreach (['', '   ', 'krátké'] as $reason) {
            try {
                $this->service->reveal(
                    $this->supplierId,
                    $this->employmentId,
                    $this->actorId,
                    $this->sensitiveReader(),
                    'test',
                    '2026-08-04',
                    $reason,
                );
                self::fail('Odkrytí bez konkrétního důvodu musí selhat.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, $this->auditCount());
    }

    public function testTamperedHashStopsRevealBeforeAudit(): void
    {
        $this->assignIdentifiers();
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_external_ids
                SET value_hash = ?
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([
            random_bytes(32),
            $this->supplierId,
            $this->employeeId,
        ]);

        try {
            $this->service->reveal(
                $this->supplierId,
                $this->employmentId,
                $this->actorId,
                $this->sensitiveReader(),
                'test',
                '2026-08-04',
                'Kontrola integrity uložené hodnoty.',
            );
            self::fail('Podvržený otisk musí odkrytí zastavit.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'neodpovídá ciphertextu',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $this->auditCount());
    }

    private function assignIdentifiers(): void
    {
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $this->employmentId,
            'test',
            self::OIC,
            self::ID_PPV,
            '2026-08-01',
            null,
            true,
            $this->actorId,
        );
    }

    private function sensitiveReader(): EffectiveRole
    {
        return new EffectiveRole(
            100,
            'Citlivý mzdový čtenář',
            'staff',
            true,
            ['payroll.person.read_sensitive' => AccessLevel::READ->value],
        );
    }

    /** @return array{int,int} */
    private function createPerson(PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba pro odkrytí", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Syntetická osoba pro odkrytí",
                     "Jana", "Novotná", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "jmhz-reveal-synthetic", "employment", "active",
                     "2026-08-01", 0)'
        )->execute([$this->supplierId, $employeeId]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický uživatel odkrytí",
                     "accountant", "cs", 1)'
        )->execute([
            'payroll-jmhz-reveal-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function lastAudit(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier_id, user_id, entity_id, payload
               FROM activity_log
              WHERE supplier_id = ? AND action = ?
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$this->supplierId, self::AUDIT_ACTION]);

        return $this->object($statement->fetch(PDO::FETCH_ASSOC));
    }

    private function auditCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([$this->supplierId, self::AUDIT_ACTION]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function auditPayload(array $audit): array
    {
        $payload = $audit['payload'] ?? null;
        if (!is_string($payload)) {
            throw new \UnexpectedValueException('Auditní payload není text.');
        }

        return $this->object(json_decode(
            $payload,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Testovací hodnota není objekt.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací objekt nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není platné číslo.",
            );
        }

        return $integer;
    }

    private function queryPositiveInt(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Dotaz se nepodařilo spustit.');
        }
        $value = (int) $statement->fetchColumn();
        if ($value <= 0) {
            throw new \RuntimeException('Dotaz nevrátil kladné číslo.');
        }

        return $value;
    }
}
