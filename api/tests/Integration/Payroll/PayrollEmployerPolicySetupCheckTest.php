<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyConflictException;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Settings\PayrollEmployerPolicyService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeatures;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollEmployerPolicySetupCheckTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmployerPolicyRepository $repository;
    private PayrollEmployerPolicyService $policies;
    private PayrollSetupCheckService $setupCheck;
    private int $supplierId;
    private int $otherSupplierId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
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
        $this->repository = new PayrollEmployerPolicyRepository(
            $connection,
            new PayrollEmployerPolicyDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        );
        $this->policies = new PayrollEmployerPolicyService($this->repository);
        $this->setupCheck = new PayrollSetupCheckService(
            $connection,
            $this->repository,
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

    public function testEffectiveHistoryIsTenantScopedAuditedAndOptimisticallyLocked(): void
    {
        $created = $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(),
            0,
            $this->actorId,
        );
        self::assertSame(1, $created['row_version']);
        $createdId = $this->intValue($created, 'id');
        self::assertSame(
            $createdId,
            $this->repository->findEffective(
                $this->supplierId,
                '2026-06-30',
            )['id'] ?? null,
        );
        self::assertNull($this->repository->find(
            $this->otherSupplierId,
            $createdId,
        ));

        $updated = $this->policies->save(
            $this->supplierId,
            $createdId,
            $this->policyInput([
                'payday_day' => 12,
                'source_reference' => 'synthetic:policy-change',
            ]),
            1,
            $this->actorId,
        );
        self::assertSame(2, $updated['row_version']);
        self::assertSame(12, $updated['payday_day']);

        try {
            $this->policies->save(
                $this->supplierId,
                $createdId,
                $this->policyInput(['payday_day' => 14]),
                1,
                $this->actorId,
            );
            self::fail('Stale row_version musí skončit konfliktem.');
        } catch (PayrollEmployerPolicyConflictException $e) {
            self::assertSame(2, $e->currentVersion);
        }

        $audit = $this->repository->auditTrail(
            $this->supplierId,
            $createdId,
        );
        self::assertCount(2, $audit);
        self::assertSame(['created', 'updated'], array_column($audit, 'action'));
        foreach ($audit as $entry) {
            self::assertSame(
                hash('sha256', $entry['snapshot_json']),
                $entry['snapshot_hash'],
            );
            self::assertSame($this->actorId, $entry['actor_user_id']);
        }
    }

    public function testOverlappingPolicyIntervalsAreRejected(): void
    {
        $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(['valid_to' => '2026-06-30']),
            0,
            $this->actorId,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('překrývá');
        $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput([
                'valid_from' => '2026-06-01',
                'valid_to' => null,
            ]),
            0,
            $this->actorId,
        );
    }

    public function testSetupCheckDoesNotRequireDisabledFeatures(): void
    {
        $this->createEmployerSettings($this->db->pdo(), $this->supplierId);
        $policy = $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput([
                'home_office_policy' => 'not_used',
                'travel_expense_policy' => 'not_used',
                'automatic_posting_enabled' => false,
                'delivery_channel' => 'disabled',
                'delivery_verified_on' => null,
            ]),
            0,
            $this->actorId,
        );

        $result = $this->setupCheck->check(
            $this->supplierId,
            '2026-06-01',
            new PayrollSetupFeatures(),
        );
        self::assertTrue($result['ready']);
        self::assertSame(
            $this->intValue($policy, 'id'),
            $result['policy_id'],
        );
        self::assertSame([], $result['blockers']);
        $codes = array_column($result['checks'], 'code');
        self::assertNotContains('jmhz_registry', $codes);
        self::assertNotContains('jmhz_certificate', $codes);
        self::assertNotContains('secure_delivery', $codes);
    }

    public function testSetupCheckFailsClosedForEveryEnabledFeature(): void
    {
        $this->createEmployerSettings($this->db->pdo(), $this->supplierId);
        $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput([
                'home_office_policy' => 'manual_review',
                'travel_expense_policy' => 'not_used',
                'automatic_posting_enabled' => false,
                'delivery_channel' => 'disabled',
                'delivery_verified_on' => null,
            ]),
            0,
            $this->actorId,
        );

        $result = $this->setupCheck->check(
            $this->supplierId,
            '2026-06-01',
            new PayrollSetupFeatures(
                homeOffice: true,
                travelExpenses: true,
                automaticPosting: true,
                secureDelivery: true,
                jmhz: true,
                activeApproverCount: 1,
                jmhzRegistryReady: false,
                jmhzCertificateReady: false,
            ),
        );

        self::assertFalse($result['ready']);
        self::assertEqualsCanonicalizing([
            'home_office_policy',
            'travel_expense_policy',
            'automatic_posting',
            'secure_delivery',
            // `jmhz_certificate` mezi blokátory NENÍ schválně: produkční
            // endpoint VREP není doložený, takže se z aplikace ostře stejně
            // podat nedá. Kontrola je vidět, ale nastavení nezastaví.
            'jmhz_registry',
        ], $result['blockers']);
    }

    public function testConfiguredPolicyPassesEnabledFeatureChecklist(): void
    {
        $this->createEmployerSettings($this->db->pdo(), $this->supplierId);
        $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(),
            0,
            $this->actorId,
        );

        $result = $this->setupCheck->check(
            $this->supplierId,
            '2026-06-01',
            new PayrollSetupFeatures(
                homeOffice: true,
                travelExpenses: true,
                automaticPosting: true,
                secureDelivery: true,
                jmhz: true,
                activeApproverCount: 2,
                jmhzRegistryReady: true,
                jmhzCertificateReady: true,
            ),
        );

        self::assertTrue($result['ready']);
        self::assertSame([], $result['blockers']);
        foreach ($result['checks'] as $check) {
            self::assertSame('ok', $check['status']);
        }
    }

    public function testAuditRowsAreAppendOnly(): void
    {
        $created = $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(),
            0,
            $this->actorId,
        );

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_policy_audit
                SET snapshot_hash = REPEAT("0", 64)
              WHERE supplier_id = ? AND policy_id = ?',
        )->execute([
            $this->supplierId,
            $this->intValue($created, 'id'),
        ]);
    }

    /**
     * Migrace 1388 změnila bezvýhradný zákaz mazání na PODMÍNĚNOU obranu: blokovat
     * smí jen důkaz pohybu, a tím je u politiky mzdový běh v její platnosti.
     * Verze, podle které se ještě nic nespočítalo, zmizí i s vlastní auditní stopou
     * (FK ON DELETE CASCADE); fakt, že existovala, zůstává v `activity_log`.
     */
    public function testPolicyWithoutPayrollRunIsDeletableTogetherWithItsAuditTrail(): void
    {
        $created = $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(),
            0,
            $this->actorId,
        );
        $policyId = $this->intValue($created, 'id');
        self::assertNotSame([], $this->repository->auditTrail($this->supplierId, $policyId));

        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_policies
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $policyId]);

        self::assertNull($this->repository->find($this->supplierId, $policyId));
        self::assertSame([], $this->repository->auditTrail($this->supplierId, $policyId));
    }

    public function testPolicyUsedByPayrollRunCannotBeDeleted(): void
    {
        $created = $this->policies->save(
            $this->supplierId,
            null,
            $this->policyInput(),
            0,
            $this->actorId,
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-03-01", "2026-04-10")',
        )->execute([$this->supplierId]);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_policies
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            $this->intValue($created, 'id'),
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function policyInput(array $overrides = []): array
    {
        return array_replace([
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'configured',
            'travel_expense_policy' => 'configured',
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'employee_portal',
            'delivery_verified_on' => '2025-12-15',
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:board-policy',
        ], $overrides);
    }

    private function createEmployerSettings(PDO $pdo, int $supplierId): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "SYN", "Syntetická účtárna", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id)
             VALUES (?, ?)',
        )->execute([$supplierId, $officeId]);
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický správce politik",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-policy-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $row */
    private function intValue(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není celé číslo.",
            );
        }

        return $value;
    }
}
