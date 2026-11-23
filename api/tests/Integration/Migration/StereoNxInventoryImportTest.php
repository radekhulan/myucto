<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxInventory;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxInventoryImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxInventory $inventory;
    private int $sourceSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->inventory = $container->get(StereoNxInventory::class);
        foreach (['stereo_nx_import_map', 'warehouses', 'stock_items', 'stock_documents', 'cars'] as $table) {
            if (!$this->db->hasTable($table)) self::markTestSkipped("Chybí tabulka {$table}.");
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId <= 0) self::markTestSkipped('Chybí základní testovací firma.');
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testWritesCatalogsWithoutStockOrJournalMovementsAndRepeatsIdempotently(): void
    {
        $supplierId = $this->supplier(true);
        $plan = StereoNxInventory::fromTables($this->tables(), ['ico' => '12345679'], 1);
        $beforeJournal = $this->rows('journal_entries', $supplierId);

        $first = $this->inventory->write($plan, $supplierId, $this->userId ?: null);

        self::assertSame(1, $first['counts']['warehouses_created']);
        self::assertSame(1, $first['counts']['stock_items_created']);
        self::assertSame(1, $first['counts']['cars_created']);
        self::assertSame([], $first['warnings']);
        self::assertSame(1, $this->rows('warehouses', $supplierId));
        self::assertSame(1, $this->rows('stock_items', $supplierId));
        self::assertSame(1, $this->rows('cars', $supplierId));
        self::assertSame(3, $this->rows('stereo_nx_import_map', $supplierId));
        self::assertSame(0, $this->rows('stock_documents', $supplierId));
        self::assertSame($beforeJournal, $this->rows('journal_entries', $supplierId));

        $item = $this->db->pdo()->prepare(
            'SELECT sku, item_type, unit, tracking_mode, ean, sale_price_without_vat, min_qty,
                    weight_g, intrastat_cn8_code, intrastat_net_mass_kg,
                    intrastat_supplementary_unit, intrastat_supplementary_unit_coefficient, is_active
               FROM stock_items WHERE supplier_id = ?'
        );
        $item->execute([$supplierId]);
        $itemRow = $item->fetch(\PDO::FETCH_ASSOC);
        $itemRow['weight_g'] = (int) $itemRow['weight_g'];
        $itemRow['is_active'] = (int) $itemRow['is_active'];
        self::assertSame([
            'sku' => 'SYN-001', 'item_type' => 'goods', 'unit' => 'ks', 'tracking_mode' => 'serial',
            'ean' => '4006381333931', 'sale_price_without_vat' => '125.500000', 'min_qty' => '2.500',
            'weight_g' => 750, 'intrastat_cn8_code' => '12345678', 'intrastat_net_mass_kg' => '0.750',
            'intrastat_supplementary_unit' => 'KGM', 'intrastat_supplementary_unit_coefficient' => '1.000000',
            'is_active' => 1,
        ], $itemRow);

        $car = $this->db->pdo()->prepare(
            'SELECT registration, name, fuel_type, odometer_start, is_archived FROM cars WHERE supplier_id = ?'
        );
        $car->execute([$supplierId]);
        $carRow = $car->fetch(\PDO::FETCH_ASSOC);
        $carRow['odometer_start'] = (int) $carRow['odometer_start'];
        $carRow['is_archived'] = (int) $carRow['is_archived'];
        self::assertSame([
            'registration' => 'TEST-01', 'name' => 'Syntetické vozidlo', 'fuel_type' => 'diesel',
            'odometer_start' => 5000, 'is_archived' => 0,
        ], $carRow);

        $repeat = $this->inventory->write($plan, $supplierId, $this->userId ?: null);
        self::assertSame(1, $repeat['counts']['warehouses_existing']);
        self::assertSame(1, $repeat['counts']['stock_items_existing']);
        self::assertSame(1, $repeat['counts']['cars_existing']);
        self::assertSame(1, $this->rows('stock_items', $supplierId));

        $changed = $this->tables();
        $changed['SCenik'][0]['ProdejniCena'] = 130.0;
        $changedPlan = StereoNxInventory::fromTables($changed, ['ico' => '12345679'], 1);
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Zdrojový záznam se od předchozího převodu změnil.');
        $this->inventory->write($changedPlan, $supplierId, $this->userId ?: null);
    }

    public function testDisabledStockModuleSkipsStockButStillImportsVehicle(): void
    {
        $supplierId = $this->supplier(false);
        $plan = StereoNxInventory::fromTables($this->tables(), ['ico' => '12345679'], 1);

        $result = $this->inventory->write($plan, $supplierId, null);

        self::assertSame(1, $result['counts']['warehouses_skipped']);
        self::assertSame(1, $result['counts']['stock_items_skipped']);
        self::assertSame(1, $result['counts']['cars_created']);
        self::assertSame(['stock_module_missing'], array_column($result['warnings'], 'code'));
        self::assertSame(0, $this->rows('warehouses', $supplierId));
        self::assertSame(0, $this->rows('stock_items', $supplierId));
        self::assertSame(1, $this->rows('cars', $supplierId));
        self::assertSame(1, $this->rows('stereo_nx_import_map', $supplierId));
    }

    public function testCanonicalVehicleRepositoryJoinsCallerTransaction(): void
    {
        $supplierId = $this->supplier(false);
        $carId = (new CarRepository($this->db))->create($supplierId, [
            'registration' => 'SYN-02', 'name' => 'Syntetické vozidlo',
            'is_default' => false, 'is_archived' => false,
        ], $this->userId ?: null);

        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame('SYN-02', (new CarRepository($this->db))->find($carId, $supplierId)['registration']);
    }

    private function supplier(bool $stockEnabled): int
    {
        $supplierId = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = ? WHERE id = ?')
            ->execute([(int) $stockEnabled, $supplierId]);
        return $supplierId;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        return [
            'SSklady' => [['Sklad' => 'HLAVNI', 'Nazev' => 'Syntetický sklad']],
            'SParamSkl' => [['Sklad' => '', 'CenyBezDPH' => true, 'MetodaSkladu' => 'B']],
            'SCenik' => [[
                'Sklad' => 'HLAVNI', 'PolozkaSkladu' => 'SYN-001', 'TypPolozky' => 'Z',
                'CarovyKod' => '4006381333931', 'Nazev' => 'Syntetické zboží', 'Jednotka' => 'ks',
                'SazbaDPHn' => '', 'SazbaDPHp' => '', 'ProdejniCena' => 125.5,
                'Mnozstvi' => 0.0, 'MnozstviPocSt' => 0.0, 'HmotnostKg' => 0.75,
                'MinMnozstvi' => 2.5, 'VyrobniCisla' => true, 'Neaktivni' => false,
                'Poznamka' => 'Pouze syntetický test', 'IntraKod' => '12345678',
                'IntraVlHmMJ' => 0.75, 'IntraDoplMJ' => 'KGM', 'IntraKoefMJ' => 1.0,
            ]],
            'Kauta' => [[
                'Vozidlo' => 'SYN-CAR', 'Nazev' => 'Syntetické vozidlo', 'SPZ' => 'TEST-01',
                'VIN' => '', 'Palivo' => 'nafta', 'PocStavKm' => 5000, 'Poznamka' => '',
                'DatumVyrazeni' => '',
            ]],
            'Kcesty' => [],
        ];
    }

    private function rows(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
