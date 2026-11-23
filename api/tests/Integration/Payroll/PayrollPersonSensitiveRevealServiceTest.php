<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonSensitiveRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionDenied;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Security\PayrollPersonSensitiveReveal;
use MyInvoice\Service\Payroll\Security\PayrollPersonSensitiveRevealService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPersonSensitiveRevealServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSensitiveData $sensitiveData;
    private PayrollPersonSensitiveRevealService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $profiles = $container->get(PayrollPersonProfileRepository::class);
        $validator = $container->get(PayrollPersonProfileValidator::class);
        $activity = $container->get(ActivityLogger::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(PayrollPersonProfileRepository::class, $profiles);
        self::assertInstanceOf(PayrollPersonProfileValidator::class, $validator);
        self::assertInstanceOf(ActivityLogger::class, $activity);
        $this->db = $connection;
        $this->sensitiveData = $sensitive;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->createActor($pdo);
        $this->employeeId = $this->createEmployee(
            $pdo,
            $this->supplierId,
            'Syntetická citlivá osoba',
        );
        $this->otherEmployeeId = $this->createEmployee(
            $pdo,
            $this->otherSupplierId,
            'Syntetická cizí osoba',
        );
        $profiles->save(
            $this->supplierId,
            $this->employeeId,
            $validator->validate($this->profilePayload()),
            0,
            $this->actorId,
            '192.0.2.1',
            'synthetic-test-agent',
        );
        $this->service = new PayrollPersonSensitiveRevealService(
            new PayrollPersonSensitiveRepository($connection),
            $sensitive,
            new PermissionChecker(new PermissionCatalog()),
            $activity,
        );
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

    public function testRevealsOnlyWithFinePermissionAndWritesSafeAudit(): void
    {
        $result = $this->service->reveal(
            $this->supplierId,
            $this->employeeId,
            $this->actorId,
            $this->sensitiveReader(),
            'Kontrola podkladů pro přihlášení zaměstnance.',
            '192.0.2.10',
            'synthetic-reveal-test',
        );

        self::assertInstanceOf(PayrollPersonSensitiveReveal::class, $result);
        self::assertSame('private, no-store', $result->cacheControl());
        self::assertSame([
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
        ], $result->responseHeaders());
        $payload = $result->jsonSerialize();
        self::assertSame($this->employeeId, $payload['employee_id']);
        self::assertSame(
            '900000001',
            $payload['identifiers'][0]['value'],
        );
        self::assertSame(
            'sensitive.person@example.invalid',
            $payload['contacts'][0]['value'],
        );
        self::assertSame(
            '1000000005/0100',
            $payload['accounts'][0]['bank_account'],
        );
        $audit = $this->revealAudit();
        self::assertSame($this->supplierId, $this->integer($audit, 'supplier_id'));
        self::assertSame($this->actorId, $this->integer($audit, 'user_id'));
        self::assertSame(
            'Kontrola podkladů pro přihlášení zaměstnance.',
            $this->auditPayload($audit)['reason'],
        );
        $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach ([
            '900000001',
            'sensitive.person@example.invalid',
            '1000000005/0100',
            'enc:v2:',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $auditJson);
        }
    }

    public function testBroadPayrollWriteDoesNotReplaceSensitivePermission(): void
    {
        $role = new EffectiveRole(
            101,
            'Syntetická účetní',
            'staff',
            true,
            ['payroll' => AccessLevel::WRITE->value],
        );

        $this->expectException(PermissionDenied::class);
        try {
            $this->service->reveal(
                $this->supplierId,
                $this->employeeId,
                $this->actorId,
                $role,
                'Kontrola podkladů pro přihlášení zaměstnance.',
            );
        } finally {
            self::assertSame(0, $this->revealAuditCount());
        }
    }

    public function testReasonIsMandatoryAndNotAuditedOnFailure(): void
    {
        foreach (['', '   ', 'krátké'] as $reason) {
            try {
                $this->service->reveal(
                    $this->supplierId,
                    $this->employeeId,
                    $this->actorId,
                    $this->sensitiveReader(),
                    $reason,
                );
                self::fail('Reveal bez konkrétního důvodu musí selhat.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, $this->revealAuditCount());
    }

    public function testTenantIsolationAndHashTamperingFailBeforeAudit(): void
    {
        try {
            $this->service->reveal(
                $this->supplierId,
                $this->otherEmployeeId,
                $this->actorId,
                $this->sensitiveReader(),
                'Kontrola podkladů zaměstnance jiné firmy.',
            );
            self::fail('Cizí osoba nesmí být odkryta.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->revealAuditCount());

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_identifiers
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
                $this->employeeId,
                $this->actorId,
                $this->sensitiveReader(),
                'Kontrola podkladů po ověření integrity.',
            );
            self::fail('Podvržený keyed hash musí reveal zastavit.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->revealAuditCount());
    }

    public function testAuditFailurePreventsRevealResult(): void
    {
        $failingActivity = new class($this->db) extends ActivityLogger {
            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                throw new \RuntimeException('Syntetické selhání auditu.');
            }
        };
        $service = new PayrollPersonSensitiveRevealService(
            new PayrollPersonSensitiveRepository($this->db),
            $this->sensitiveData,
            new PermissionChecker(new PermissionCatalog()),
            $failingActivity,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntetické selhání auditu.');
        try {
            $service->reveal(
                $this->supplierId,
                $this->employeeId,
                $this->actorId,
                $this->sensitiveReader(),
                'Kontrola fail-closed auditní cesty.',
            );
        } finally {
            self::assertSame(0, $this->revealAuditCount());
        }
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

    /** @return array<string,mixed> */
    private function profilePayload(): array
    {
        return [
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => 'Syntetická citlivá osoba',
                'first_name' => 'Syntetická',
                'last_name' => 'Osoba',
                'birth_surname' => null,
                'effective_from' => '2026-01-01',
            ]],
            'addresses' => [],
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'sensitive.person@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => '900000001',
            ]],
            'accounts' => [[
                'label' => 'Syntetický účet',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 10000,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ]],
        ];
    }

    private function createEmployee(
        PDO $pdo,
        int $supplierId,
        string $fullName,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický reveal uživatel",
                     "accountant", "cs", 1)'
        )->execute([
            'payroll-sensitive-reveal-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function revealAudit(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier_id, user_id, entity_id, payload
               FROM activity_log
              WHERE supplier_id = ? AND action = ?
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            $this->supplierId,
            'payroll.person_sensitive.revealed',
        ]);

        return $this->object($statement->fetch(PDO::FETCH_ASSOC));
    }

    private function revealAuditCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([
            $this->supplierId,
            'payroll.person_sensitive.revealed',
        ]);

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
            throw new \UnexpectedValueException(
                'Auditní payload není text.',
            );
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
}
