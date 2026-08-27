<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollOfficeRegistrationRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollOfficeRegistrationRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testVersionsAreTenantScopedAppendOnlyAndOrderedByEffectiveDate(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $registrations = $container->get(PayrollOfficeRegistrationRepository::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollOfficeRegistrationRepository::class, $registrations);
        if (!$db->hasTable('payroll_office_registration_versions')) {
            self::markTestSkipped('Migrace 1595 neproběhla.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $actorId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $actorId <= 0) {
            self::markTestSkipped('Chybí syntetický zdroj firmy nebo uživatel.');
        }
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $pdo->prepare("INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, 'REG', 'Registrace', 1)")
                ->execute([$supplierId]);
            $officeId = (int) $pdo->lastInsertId();

            $first = $registrations->add($supplierId, $officeId, '2026-09-01', '0012345678', 'synthetic-approved-1', $actorId);
            $second = $registrations->add($supplierId, $officeId, '2026-10-01', '0012345679', 'synthetic-approved-2', $actorId);
            self::assertSame('0012345679', $registrations->effective($supplierId, $officeId, '2026-10-15')['social_security_variable_symbol']);
            self::assertSame('0012345678', $registrations->effective($supplierId, $officeId, '2026-09-15')['social_security_variable_symbol']);
            self::assertSame([], $registrations->list($otherSupplierId, $officeId));

            try {
                $registrations->add($supplierId, $officeId, '2026-09-15', '0012345680', 'synthetic-overlap', $actorId);
                self::fail('Backdated effective interval must be rejected.');
            } catch (\PDOException) {
                self::assertNotSame($first['id'], $second['id']);
            }
            $this->expectException(\PDOException::class);
            $pdo->prepare('UPDATE payroll_office_registration_versions SET source_reference = ? WHERE id = ?')
                ->execute(['rewrite', $first['id']]);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $db->close();
        }
    }
}
