<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\JmhzControlCatalogRepository;
use MyInvoice\Repository\Payroll\JmhzSpecPackageRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzControlCatalogRepositoryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        if (!$db->hasTable('payroll_jmhz_control_catalogs')) {
            $this->markTestSkipped('Migrace 1336 neproběhla.');
        }
        $this->db = $db;
    }

    public function testOfficialCatalogIsInstalledIdempotentlyAndRemainsImmutable(): void
    {
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        (new JmhzSpecPackageRepository($this->db))->install($spec);
        $manifest = JmhzControlSourceCatalog::load()->manifest();
        $repository = new JmhzControlCatalogRepository($this->db);
        $catalogId = $repository->install($manifest, $spec);

        self::assertSame($catalogId, $repository->install($manifest, $spec));
        self::assertEquals(
            $manifest,
            $repository->find(JmhzControlSourceCatalog::CATALOG_KEY, $manifest['manifest_sha256']),
        );
        self::assertNull($repository->find(
            JmhzControlSourceCatalog::CATALOG_KEY,
            str_repeat('0', 64),
        ));
        self::assertSame(199, $this->countRows('payroll_jmhz_control_definitions', $catalogId));
        self::assertSame(825, $this->countRows('payroll_jmhz_control_attribute_refs', $catalogId));
        self::assertSame(22, $this->countRows('payroll_jmhz_control_parameters', $catalogId));
        self::assertSame(30, $this->countRows('payroll_jmhz_control_parameter_refs', $catalogId));
        self::assertSame(50, $this->countRows('payroll_jmhz_control_parameter_values', $catalogId));
        $missing = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_jmhz_control_parameter_refs
              WHERE catalog_id = ? AND resolution = 'missing'",
        );
        $missing->execute([$catalogId]);
        self::assertSame(10, (int) $missing->fetchColumn());

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_jmhz_control_definitions SET name = ?
                  WHERE catalog_id = ? AND control_id = 74',
            )->execute(['Pozměněná kontrola', $catalogId]);
            self::fail('Zdrojová kontrola JMHZ se nesmí dát změnit.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_jmhz_control_definitions
                    (catalog_id, package_id, control_id, source_row, name, symbolic_refs_json,
                     rejection_scope, owner_name, portal_system, portal_passability,
                     remote_system, remote_passability, detail_text, error_message,
                     source_label, row_hash)
                 SELECT id, package_id, 999, 999, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                   FROM payroll_jmhz_control_catalogs WHERE id = ?',
            )->execute([
                'Nepovolená kontrola', '[]', 'global', 'test', 'eportal', 'blocking',
                'dis', 'blocking', 'detail', 'chyba', 'test', str_repeat('0', 64), $catalogId,
            ]);
            self::fail('Do kompletního katalogu JMHZ se nesmí přidat další kontrola.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('already contains all definitions', $e->getMessage());
        }
    }

    private function countRows(string $table, int $catalogId): int
    {
        $allowed = [
            'payroll_jmhz_control_definitions',
            'payroll_jmhz_control_attribute_refs',
            'payroll_jmhz_control_parameters',
            'payroll_jmhz_control_parameter_refs',
            'payroll_jmhz_control_parameter_values',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Neplatná tabulka testu katalogu JMHZ.');
        }
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE catalog_id = ?");
        $stmt->execute([$catalogId]);

        return (int) $stmt->fetchColumn();
    }
}
