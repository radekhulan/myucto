<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\CrossCheckSuite;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit VAT klasifikací 2026-08, nález M-2 — prodej dlouhodobého majetku bez vazby
 * na kartu majetku.
 *
 * Kód `1m`/`2m` (vyloučení z koeficientu § 76 odst. 4) se dosadí jen tehdy, má-li řádek
 * faktury `asset_id`. Faktura „prodej vozidla" bez té vazby dostane běžný kód `1`
 * a plnění zůstane v čitateli i jmenovateli vypořádacího koeficientu — bez jediného
 * upozornění. Audit odmítá heuristiku nad popisem řádku; smysluplná je roční křížová
 * kontrola dvou nezávislých evidencí, a ta až doteď neexistovala.
 */
#[Group('integration')]
final class AssetSaleCoefficientCrossCheckTest extends TestCase
{
    /** Rok bez jakýchkoli reálných dat — kontrola tak měří jen to, co si sama založí. */
    private const YEAR = 2098;

    private Connection $db;
    private CrossCheckSuite $suite;
    private int $supplierId = 0;

    /** @var int[] */
    private array $assetIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->suite = $container->get(CrossCheckSuite::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('assets', 'sale_invoice_id')) {
            $this->markTestSkipped('Evidence majetku (migrace 1013/1097) na téhle DB chybí.');
        }
        $this->supplierId = (int) ($this->db->pdo()
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->assetIds as $id) {
            $this->db->pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** Rok bez vyřazení prodejem nesmí nic hlásit — kontrola nesmí křičet do prázdna. */
    public function testYearWithoutAssetSalesIsClean(): void
    {
        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertSame('asset_sale_coefficient', $result['check']);
        self::assertTrue($result['ok'], 'Bez vyřazených karet nemá co hlásit.');
        self::assertSame(0.0, $result['a']);
    }

    /**
     * Karta vyřazená prodejem, ke které žádný doklad nenese `1m`/`2m` → nález.
     * Právě tenhle případ propadal beze stopy do koeficientu § 76.
     */
    public function testSoldAssetWithoutClassifiedDocumentIsReported(): void
    {
        $this->soldAsset('T-2098-01', 'Testovací stroj', self::YEAR . '-05-20');

        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertFalse($result['ok'], 'Prodaná karta bez dokladu s 1m/2m musí být nález.');
        self::assertSame(1.0, $result['a']);
        self::assertSame(0.0, $result['b']);
        self::assertNotNull($result['note'], 'Nález musí říct, o kterou kartu jde.');
        self::assertStringContainsString('T-2098-01', (string) $result['note']);
    }

    /** Vyřazení JINÝM způsobem než prodejem se § 76 odst. 4 netýká. */
    public function testLiquidatedAssetIsNotReported(): void
    {
        $this->soldAsset('T-2098-02', 'Sešrotovaný stroj', self::YEAR . '-05-20', 'liquidated');

        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertTrue($result['ok'], 'Likvidace není prodej — do koeficientu nevstupuje.');
    }

    private function soldAsset(string $inventoryNumber, string $name, string $disposalDate, string $type = 'sold'): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO assets
                (supplier_id, inventory_number, name, kind, input_price, acquisition_date,
                 put_into_use_date, disposal_date, disposal_type, disposal_price, status, tax_group)
             VALUES (?, ?, ?, "tangible", 500000, ?, ?, ?, ?, 300000, "disposed", 2)'
        );
        $stmt->execute([
            $this->supplierId,
            $inventoryNumber,
            $name,
            (self::YEAR - 5) . '-01-10',
            (self::YEAR - 5) . '-01-10',
            $disposalDate,
            $type,
        ]);
        $this->assetIds[] = (int) $this->db->pdo()->lastInsertId();
    }
}
