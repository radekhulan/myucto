<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use DI\Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\TaxEvidence\AnnualClosingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Otevření uzávěrky daňové evidence (GET) zakládá řádek roku. Na veřejné ukázce
 * se tím čtení nesmí stát zápisem; zobrazí se prázdný rozpracovaný stav.
 */
#[Group('integration')]
final class AnnualClosingDemoReadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ?Connection $db = null;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDemoReadCreatesNoClosingRow(): void
    {
        [$service, $supplierId] = $this->setUpWith(true);

        $row = $service->get($supplierId, 2025);

        self::assertSame('draft', $row['status']);
        self::assertSame(2025, $row['year']);
        self::assertSame(1, $row['row_version']);
        self::assertSame(0, $this->closingRows($supplierId));
    }

    public function testReadOutsideDemoStillCreatesClosingRow(): void
    {
        [$service, $supplierId] = $this->setUpWith(false);

        $row = $service->get($supplierId, 2025);

        self::assertSame('draft', $row['status']);
        self::assertSame(1, $this->closingRows($supplierId));
    }

    /** @return array{0:AnnualClosingService,1:int} */
    private function setUpWith(bool $demo): array
    {
        $container = Bootstrap::buildContainer();
        self::assertInstanceOf(Container::class, $container);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$db->hasTable('tax_evidence_closings')) {
            self::markTestSkipped('Chybí tabulka tax_evidence_closings.');
        }
        $service = $container->make(AnnualClosingService::class, [
            'demo' => new DemoMode(new Config(['demo' => ['enabled' => $demo]])),
        ]);
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();

        return [$service, $this->createIsolatedSupplier($pdo, $sourceSupplierId)];
    }

    private function closingRows(int $supplierId): int
    {
        $stmt = $this->db?->pdo()->prepare('SELECT COUNT(*) FROM tax_evidence_closings WHERE supplier_id = ?');
        self::assertNotNull($stmt);
        self::assertNotFalse($stmt);
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
