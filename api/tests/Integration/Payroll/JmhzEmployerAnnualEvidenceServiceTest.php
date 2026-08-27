<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzEmployerAnnualEvidenceConflictException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JmhzEmployerAnnualEvidenceServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testRevisionsArePinnedIdempotentAndTenantScoped(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $service = $container->get(JmhzEmployerAnnualEvidenceService::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(JmhzEmployerAnnualEvidenceService::class, $service);

        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $pdo->prepare(
                'INSERT INTO payroll_offices
                    (supplier_id, code, name, is_active, row_version)
                 VALUES (?, "SYN", "Syntetická účtárna", 1, 1)',
            )->execute([$supplierId]);
            $officeId = (int) $pdo->lastInsertId();
            $input = [
                'expected_revision_id' => null,
                'collective_agreement_types' => ['1', '3'],
                'ownership_form' => '2',
                'average_headcount' => '25.50',
                'average_disabled_headcount' => '1.25',
                'ozp_reporting_office_id' => $officeId,
                'evidence_reference' => 'synthetic-approved-source',
            ];

            $first = $service->save($supplierId, 2026, $input, null);
            self::assertSame(1, $first['revision_no']);
            self::assertSame(2_550, $first['average_headcount_hundredths']);
            self::assertSame(125, $first['average_disabled_headcount_hundredths']);
            self::assertSame(490, $first['disabled_share_hundredths']);
            self::assertSame(
                JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                $first['spec_manifest_sha256'],
            );

            $replay = $service->save($supplierId, 2026, $input, null);
            self::assertSame($first['id'], $replay['id']);

            $changed = $service->save($supplierId, 2026, [
                ...$input,
                'expected_revision_id' => $first['id'],
                'average_headcount' => '26.00',
            ], null);
            self::assertSame(2, $changed['revision_no']);
            self::assertSame($first['id'], $changed['previous_revision_id']);

            self::assertNull($service->snapshotForPreparation($supplierId, '2026-11-01'));
            self::assertSame(
                $changed['id'],
                $service->snapshotForPreparation($supplierId, '2026-12-01')['id'] ?? null,
            );

            $this->expectException(JmhzEmployerAnnualEvidenceConflictException::class);
            $service->save($supplierId, 2026, [
                ...$input,
                'expected_revision_id' => $first['id'],
                'average_headcount' => '27.00',
            ], null);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
