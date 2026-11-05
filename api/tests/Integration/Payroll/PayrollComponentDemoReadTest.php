<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DI\Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výpis číselníku mzdových složek si chybějící výchozí složky (a jejich zařazení
 * do JMHZ) doplňuje sám. Na veřejné ukázce tím GET návštěvníka zakládal firmě
 * nové složky mimo přípravu dat; tam se výpis zápisem stát nesmí.
 */
#[Group('integration')]
final class PayrollComponentDemoReadTest extends TestCase
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

    public function testDemoReadDoesNotSeedDefaults(): void
    {
        [$components, $supplierId] = $this->setUpWith(true);

        self::assertSame([], $components->list($supplierId));
        self::assertSame(0, $this->rowCount('payroll_component_definitions', $supplierId));
        self::assertSame(0, $this->rowCount('payroll_component_jmhz_mappings', $supplierId));
    }

    public function testReadOutsideDemoStillSeedsDefaults(): void
    {
        [$components, $supplierId] = $this->setUpWith(false);

        self::assertNotSame([], $components->list($supplierId));
        self::assertGreaterThan(0, $this->rowCount('payroll_component_definitions', $supplierId));
    }

    /** @return array{0:PayrollComponentRepository,1:int} */
    private function setUpWith(bool $demo): array
    {
        $container = Bootstrap::buildContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(DemoMode::class, new DemoMode(new Config(['demo' => ['enabled' => $demo]])));
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(Connection::class, $db);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $this->db = $db;
        if (!$db->hasTable('payroll_component_definitions') || !$db->hasTable('payroll_component_jmhz_mappings')) {
            self::markTestSkipped('Chybí tabulky mzdových složek.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);

        return [$components, $supplierId];
    }

    private function rowCount(string $table, int $supplierId): int
    {
        $stmt = $this->db?->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        self::assertNotNull($stmt);
        self::assertNotFalse($stmt);
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
