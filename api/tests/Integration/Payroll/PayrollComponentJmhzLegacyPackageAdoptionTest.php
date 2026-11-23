<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nález z E2E nad kopií provozu: firma měla zařazení složek jen ve starším
 * balíku specifikace JMHZ. Obrazovka je ukazovala jako zařazené, snímek
 * hlášení (čte jen aktuální balík) hlásil „složka nemá zařazení" a výchozí
 * zařazení se nedoplnilo, protože složka nějaký záznam zařazení měla.
 *
 * Rozhodnutí účetní ze staršího balíku se převezme do aktuálního, pokud tam
 * cílový atribut existuje. Migrace 1840 (balík 1.4.2.9), 1903 (balík 1.4.2.10)
 * a 2210 (balík 1.4.2.11) dělají u existujících instalací totéž
 * co aplikace — shodu hlídá {@see self::testMigrationDoesExactlyWhatTheApplicationDoes()}.
 */
#[Group('integration')]
final class PayrollComponentJmhzLegacyPackageAdoptionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MIGRATION = '2210_payroll_component_jmhz_catalog_1_4_2_11_package.sql';

    /** Cíle, které aplikace umí; synthetický starší balík je musí znát, jinak FK zařazení neprojde. */
    private const TARGETS = [
        '10328', '10329', '10330', '10331', '10332', '10333', '10334', '10335', '10336',
        '10337', '10338', '10339', '10340', '10341', '10342', '10343', '10417',
        '10418', '10292', '10293', '10294', '10295', '10296',
    ];

    private Connection $db;
    private PayrollComponentRepository $components;
    private PayrollComponentJmhzMappingRepository $mappings;
    private PayrollComponentJmhzMappingDefaults $defaults;
    private int $supplierId;
    private int $userId;
    private int $currentPackageId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentRepository::class);
        $mappings = $container->get(PayrollComponentJmhzMappingRepository::class);
        $defaults = $container->get(PayrollComponentJmhzMappingDefaults::class);
        if (!$db instanceof Connection || !$components instanceof PayrollComponentRepository
            || !$mappings instanceof PayrollComponentJmhzMappingRepository
            || !$defaults instanceof PayrollComponentJmhzMappingDefaults
        ) {
            throw new \RuntimeException('Služby zařazení mzdových složek nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->mappings = $mappings;
        $this->defaults = $defaults;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::fail('Testovací DB nemá výchozí firmu ani uživatele.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->components->ensureDefaults($this->supplierId);
        $this->currentPackageId = $this->mappings->currentPackageId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Přesně stav firmy z provozu: výchozí zařazení i volba účetní žijí jen
     * ve starším balíku. Čtení číselníku (kudy jde každá mzdová stránka)
     * je musí převést do balíku, který čte snímek hlášení.
     */
    public function testReadingTheCatalogAdoptsDecisionsFromTheOlderPackage(): void
    {
        $this->assertAdoptedAfter(fn () => $this->components->ensureDefaults($this->supplierId));
    }

    /** Příprava hlášení a obrazovka zařazení jdou přes apply(); převzetí musí proběhnout i tam. */
    public function testApplyingDefaultsAdoptsDecisionsFromTheOlderPackage(): void
    {
        $this->assertAdoptedAfter(fn () => $this->defaults->apply($this->supplierId));
    }

    /** Zařazení, které už v aktuálním balíku je, se nemění; starší záznam přestane blokovat zápis. */
    public function testExistingCurrentMappingIsKeptAndTheLegacyOneStopsBlocking(): void
    {
        $componentId = $this->componentId('MZDA_MESICNI');
        $before = $this->row($componentId, $this->currentPackageId);
        self::assertIsArray($before);
        $oldPackage = $this->installSyntheticPackage(self::TARGETS);
        $this->insertMapping($componentId, $oldPackage, '10330', $this->userId);

        $this->mappings->adoptLegacy($this->supplierId);

        self::assertSame($before, $this->row($componentId, $this->currentPackageId), 'Aktuální zařazení se nesmí změnit.');
        $legacy = $this->row($componentId, $oldPackage);
        self::assertIsArray($legacy);
        self::assertSame(0, $legacy['is_active']);
        // Zápis zařazení už nenarazí na „nejprve deaktivujte mapování ze staršího balíku".
        $changed = $this->mappings->put($this->supplierId, $componentId, '10330', $before['row_version'], $this->userId);
        self::assertSame('10330', $changed['target_attribute_id']);
    }

    /**
     * Cíl, který aktuální balík nezná, se nepřevede: starší zařazení zůstane
     * aktivní a nález „nemá zařazení" s ním. Tiché zahození volby by bylo horší.
     */
    public function testDecisionWhoseTargetIsMissingInTheNewPackageStaysAsAFinding(): void
    {
        $componentId = $this->componentId('PROVIZE');
        $oldPackage = $this->installSyntheticPackage(self::TARGETS);
        $newPackage = $this->installSyntheticPackage(array_values(array_diff(self::TARGETS, ['10330'])));
        $this->insertMapping($componentId, $oldPackage, '10330', $this->userId);

        $this->mappings->adoptLegacy($this->supplierId, $newPackage);

        self::assertNull($this->row($componentId, $newPackage));
        $legacy = $this->row($componentId, $oldPackage);
        self::assertIsArray($legacy);
        self::assertSame(1, $legacy['is_active']);
        // Snímek hlášení (aktuální balík aplikace) zařazení dál nevidí.
        $this->expectException(\DomainException::class);
        $this->mappings->snapshot($this->supplierId, $componentId);
    }

    public function testMigrationDoesExactlyWhatTheApplicationDoes(): void
    {
        $packageId = $this->migrationPackageId();
        self::assertSame($this->currentPackageId, $packageId, 'Migrace převádí do jiného balíku, než čte aplikace.');
        $this->moveToOlderPackage();
        $pdo = $this->db->pdo();

        $pdo->exec('SAVEPOINT adoption');
        $this->mappings->adoptLegacy($this->supplierId, $packageId);
        $application = $this->mappingRows();
        $pdo->exec('ROLLBACK TO SAVEPOINT adoption');

        $this->runMigration();
        $migration = $this->mappingRows();
        self::assertSame($application, $migration, 'Migrace a aplikace převzaly zařazení různě.');
        $this->runMigration();
        self::assertSame($migration, $this->mappingRows(), 'Opakovaný běh migrace nesmí nic změnit.');
        self::assertSame('10329', $this->mappings->snapshot($this->supplierId, $this->componentId('MZDA_MESICNI'))['target_attribute_id']);
    }

    private function assertAdoptedAfter(callable $trigger): void
    {
        $wage = $this->componentId('MZDA_MESICNI');
        $task = $this->componentId('MZDA_UKOLOVA');
        $bonus = $this->componentId('ODMENA');
        $manual = $this->componentId('PROVIZE');
        $disabled = $this->componentId('NAHRADA_MZDY');
        $oldPackage = $this->moveToOlderPackage();
        // Volba účetní mimo výchozí zařazení a vědomě zrušené zařazení.
        $this->insertMapping($manual, $oldPackage, '10330', $this->userId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_component_jmhz_mappings
                SET is_active = 0, disabled_at = CURRENT_TIMESTAMP, row_version = row_version + 1
              WHERE supplier_id = ? AND component_definition_id = ?',
        )->execute([$this->supplierId, $disabled]);

        foreach ([$wage, $task, $bonus] as $componentId) {
            try {
                $this->mappings->snapshot($this->supplierId, $componentId);
                self::fail('Výchozí stav testu: snímek hlášení zařazení ze staršího balíku nevidí.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $trigger();

        foreach ([$wage => '10329', $task => '10329', $bonus => '10331', $manual => '10330'] as $componentId => $target) {
            $snapshot = $this->mappings->snapshot($this->supplierId, $componentId);
            self::assertSame($target, $snapshot['target_attribute_id']);
            $legacy = $this->row($componentId, $oldPackage);
            self::assertIsArray($legacy);
            self::assertSame(0, $legacy['is_active'], 'Převzaté starší zařazení nesmí dál blokovat zápis.');
        }
        $adopted = $this->row($manual, $this->currentPackageId);
        self::assertIsArray($adopted);
        self::assertSame($this->userId, $adopted['created_by'], 'Volba účetní zůstává volbou účetní.');

        $kept = $this->mappings->find($this->supplierId, $disabled);
        self::assertIsArray($kept);
        self::assertFalse($kept['is_active'], 'Vědomě zrušené zařazení se nesmí obnovit ani nahradit výchozím.');
        self::assertTrue($kept['is_current_package']);

        // Zařazení jde běžně změnit — bez „nejprve deaktivujte mapování ze staršího balíku".
        $current = $this->mappings->find($this->supplierId, $wage);
        self::assertIsArray($current);
        $changed = $this->mappings->put($this->supplierId, $wage, '10330', $current['row_version'], $this->userId);
        self::assertSame('10330', $changed['target_attribute_id']);
    }

    /** Přesune všechna zařazení firmy do nového (synthetického) staršího balíku. */
    private function moveToOlderPackage(): int
    {
        $oldPackage = $this->installSyntheticPackage(self::TARGETS);
        $this->db->pdo()->prepare(
            'UPDATE payroll_component_jmhz_mappings SET spec_package_id = ? WHERE supplier_id = ?',
        )->execute([$oldPackage, $this->supplierId]);

        return $oldPackage;
    }

    /**
     * Balík specifikace jen s peněžními cíli zařazení. Balíky jsou append-only,
     * test je zakládá v transakci, kterou na konci vrací.
     *
     * @param list<string> $attributeIds
     */
    private function installSyntheticPackage(array $attributeIds): int
    {
        $pdo = $this->db->pdo();
        $key = 'synthetic-jmhz-adoption-' . bin2hex(random_bytes(8));
        $manifest = json_encode(['payload' => ['package_key' => $key, 'counts' => ['attributes' => count($attributeIds)]]], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO payroll_jmhz_spec_packages
                (package_key, schema_version, xsd_version, dictionary_version,
                 control_catalog_version, process_version, instructions_version,
                 manifest_json, manifest_sha256)
             VALUES (?, 'test', 'test', 'test', 'test', 'test', 'test', ?, ?)",
        )->execute([$key, $manifest, hash('sha256', $manifest)]);
        $packageId = (int) $pdo->lastInsertId();
        $copy = $pdo->prepare(
            'INSERT INTO payroll_jmhz_dictionary_attributes
                (package_id, attribute_id, name, data_type, xsd_mapping, monthly_marker, row_hash)
             SELECT ?, attribute_id, name, data_type, xsd_mapping, monthly_marker, row_hash
               FROM payroll_jmhz_dictionary_attributes
              WHERE package_id = ? AND attribute_id = ?',
        );
        foreach ($attributeIds as $attributeId) {
            $copy->execute([$packageId, $this->currentPackageId, $attributeId]);
            self::assertSame(1, $copy->rowCount(), "Aktuální balík nezná atribut {$attributeId}.");
        }

        return $packageId;
    }

    private function insertMapping(int $componentId, int $packageId, string $target, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_jmhz_mappings
                (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$this->supplierId, $componentId, $packageId, $target, $userId, $userId]);
    }

    private function migrationPackageId(): int
    {
        $sql = $this->migrationSql();
        self::assertSame(1, preg_match("/package_key = '([^']+)'/", $sql, $key));
        self::assertSame(1, preg_match("/manifest_sha256 = '([0-9a-f]{64})'/", $sql, $sha));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_jmhz_spec_packages WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$key[1], $sha[1]]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, 'Balík, do kterého migrace převádí, v testovací DB není.');

        return (int) $id;
    }

    private function runMigration(): void
    {
        foreach (explode(';', $this->migrationSql()) as $statement) {
            if (trim($statement) !== '') {
                $this->db->pdo()->exec($statement);
            }
        }
    }

    private function migrationSql(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . self::MIGRATION);
        self::assertIsString($sql, 'Migrace ' . self::MIGRATION . ' chybí.');

        return (string) preg_replace('/^\s*--.*$/m', '', $sql);
    }

    /** @return list<list<int|string|null>> */
    private function mappingRows(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component_definition_id, spec_package_id, target_attribute_id, is_active,
                    disabled_at IS NULL AS enabled, row_version, created_by, updated_by
               FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ?
              ORDER BY component_definition_id, spec_package_id',
        );
        $stmt->execute([$this->supplierId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = array_map(static fn (mixed $value): int|string|null => $value === null ? null : (string) $value, $row);
        }

        return $rows;
    }

    /** @return array{id:int,target_attribute_id:string,is_active:int,row_version:int,created_by:?int}|null */
    private function row(int $componentId, int $packageId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, target_attribute_id, is_active, row_version, created_by
               FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ? AND component_definition_id = ? AND spec_package_id = ?',
        );
        $stmt->execute([$this->supplierId, $componentId, $packageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'target_attribute_id' => (string) $row['target_attribute_id'],
            'is_active' => (int) $row['is_active'],
            'row_version' => (int) $row['row_version'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
        ];
    }

    private function componentId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? ORDER BY valid_from LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            self::fail("Výchozí číselník neobsahuje složku {$code}.");
        }

        return (int) $id;
    }
}
