<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Převzetí měsíce od ruční mzdové rekapitulace modulem Mzdy.
 *
 * Do migrace 1719 nešlo řádek mzdového listu odstranit odnikud než ruční
 * editací databáze, takže `releaseLegacy()` období nikdy neuvolnila a rok, který
 * začal ruční rekapitulací, zůstal navždy rozpůlený mezi dvě agendy.
 */
#[Group('integration')]
final class PayrollLegacyRecapitulationHandoverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollMonthlyRecordRepository $records;
    private PayrollPeriodOwnershipService $ownership;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $records = $container->get(PayrollMonthlyRecordRepository::class);
        $ownership = $container->get(PayrollPeriodOwnershipService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollMonthlyRecordRepository::class, $records);
        self::assertInstanceOf(PayrollPeriodOwnershipService::class, $ownership);
        $this->db = $connection;
        $this->records = $records;
        $this->ownership = $ownership;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí zdrojový tenant nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 0, 0, 0, NULL, 0, 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testRetiredMonthStopsBlockingTheLegacyPeriodRelease(): void
    {
        $this->seedRecord(3);
        $this->claimLegacy(3);

        $blockers = array_column(
            $this->ownership->legacyClaimStatus($this->supplierId, 2026, 3)['blockers'],
            null,
            'code',
        );
        self::assertArrayHasKey('legacy_monthly_record_exists', $blockers);

        $retired = $this->records->retireForPeriod(
            $this->supplierId,
            2026,
            3,
            $this->userId,
            'Období přebírá modul Mzdy.',
        );
        self::assertSame(1, $retired);

        $status = $this->ownership->legacyClaimStatus($this->supplierId, 2026, 3);
        self::assertSame([], $status['blockers']);
        self::assertTrue($status['releasable']);

        // Uvolnění projde bez ručního zásahu do databáze.
        $this->ownership->releaseLegacy(
            $this->supplierId,
            2026,
            3,
            $this->userId,
            'Období přebírá modul Mzdy.',
        );
        self::assertFalse(
            $this->ownership->legacyClaimStatus($this->supplierId, 2026, 3)['claimed'],
        );
    }

    /**
     * Odložený měsíc se nesmí počítat do ročního mzdového listu ani do
     * kumulovaného vyměřovacího základu — jinak by ho modul i rekapitulace
     * vykázaly dvakrát.
     */
    public function testRetiredMonthDisappearsFromSheetAndCumulativeBase(): void
    {
        $this->seedRecord(1);
        $this->seedRecord(2);

        self::assertCount(
            2,
            $this->records->listForYear($this->supplierId, $this->employeeId, 2026),
        );
        self::assertSame(
            9000.0,
            $this->records->socialBaseYearToDate($this->supplierId, $this->employeeId, 2026, 3),
        );
        self::assertSame(
            4500.0,
            $this->records->grossForMonth($this->supplierId, $this->employeeId, 2026, 1),
        );

        $this->records->retireForPeriod(
            $this->supplierId,
            2026,
            1,
            $this->userId,
            'Období přebírá modul Mzdy.',
        );

        self::assertSame(
            [2],
            array_keys($this->records->listForYear($this->supplierId, $this->employeeId, 2026)),
        );
        self::assertSame(
            4500.0,
            $this->records->socialBaseYearToDate($this->supplierId, $this->employeeId, 2026, 3),
        );
        self::assertNull(
            $this->records->grossForMonth($this->supplierId, $this->employeeId, 2026, 1),
        );
    }

    /** Odložení bez důvodu by z evidence § 38j udělalo tichou díru. */
    public function testRetirementRequiresAReason(): void
    {
        $this->seedRecord(4);
        $this->expectException(\InvalidArgumentException::class);
        $this->records->retireForPeriod($this->supplierId, 2026, 4, $this->userId, '   ');
    }

    /** Nové zaúčtování rekapitulace za týž měsíc odložení ruší. */
    public function testNewPostingRevivesTheRetiredMonth(): void
    {
        $this->seedRecord(5);
        $this->records->retireForPeriod(
            $this->supplierId,
            2026,
            5,
            $this->userId,
            'Období přebírá modul Mzdy.',
        );
        self::assertSame([], $this->records->listForYear($this->supplierId, $this->employeeId, 2026));

        $this->seedRecord(5);
        self::assertSame(
            [5],
            array_keys($this->records->listForYear($this->supplierId, $this->employeeId, 2026)),
        );
    }

    private function seedRecord(int $month): void
    {
        $this->records->upsert(
            $this->supplierId,
            $this->employeeId,
            2026,
            $month,
            ['gross' => 4500, 'social_base' => 4500, 'net' => 886],
            ['taxpayer' => 0, 'children' => 0, 'total' => 0],
            675,
            886,
            null,
        );
    }

    private function claimLegacy(int $month): void
    {
        $this->ownership->claimLegacy(
            $this->supplierId,
            2026,
            $month,
            2026 * 100 + $month,
            $this->userId,
        );
    }
}
