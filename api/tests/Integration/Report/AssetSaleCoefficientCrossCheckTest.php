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

    /** @var int[] */
    private array $invoiceIds = [];

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
        foreach ($this->invoiceIds as $id) {
            $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
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

    /**
     * Zelený vzorek: prodaná karta s dokladem, který kód nese, hlásit NESMÍ.
     * Bez tohohle testu by kontrola mohla hlásit úplně všechno a pořád svítit zeleně
     * v ostatních případech.
     */
    public function testSoldAssetWithClassifiedDocumentIsClean(): void
    {
        $invoiceId = $this->classifiedInvoice('1m');
        $this->soldAsset('T-2098-03', 'Prodaný stroj', self::YEAR . '-05-20', 'sold', $invoiceId);

        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertTrue($result['ok'], 'Správně označený prodej nesmí být nález.');
        self::assertSame(1.0, $result['a']);
        self::assertSame(1.0, $result['b']);
        self::assertNull($result['note']);
    }

    /**
     * Tři karty prodané JEDNOU fakturou. Kontrola, která porovnává počet karet proti
     * počtu dokladů, tu spočítá 3 − 1 = 2 a nahlásí nesoulad nad bezvadnými daty —
     * a účetní pak dostane výčet inventárních čísel „ke kontrole", na kterých nic není.
     */
    public function testMultipleAssetsOnOneInvoiceAreClean(): void
    {
        $invoiceId = $this->classifiedInvoice('1m');
        $this->soldAsset('T-2098-04', 'Stroj A', self::YEAR . '-06-10', 'sold', $invoiceId);
        $this->soldAsset('T-2098-05', 'Stroj B', self::YEAR . '-06-10', 'sold', $invoiceId);
        $this->soldAsset('T-2098-06', 'Stroj C', self::YEAR . '-06-10', 'sold', $invoiceId);

        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertTrue($result['ok'], 'Jedna faktura smí prodat víc karet, aniž by to byl nález.');
        self::assertSame(3.0, $result['a']);
        self::assertSame(3.0, $result['b']);
    }

    /** Vyřazení JINÝM způsobem než prodejem se § 76 odst. 4 netýká. */
    public function testLiquidatedAssetIsNotReported(): void
    {
        $this->soldAsset('T-2098-02', 'Sešrotovaný stroj', self::YEAR . '-05-20', 'liquidated');

        $result = $this->suite->assetSalesVsCoefficientExclusion($this->supplierId, self::YEAR);

        self::assertTrue($result['ok'], 'Likvidace není prodej — do koeficientu nevstupuje.');
    }

    private function soldAsset(
        string $inventoryNumber,
        string $name,
        string $disposalDate,
        string $type = 'sold',
        ?int $saleInvoiceId = null,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO assets
                (supplier_id, inventory_number, name, kind, input_price, acquisition_date,
                 put_into_use_date, disposal_date, disposal_type, disposal_price, status, tax_group,
                 sale_invoice_id)
             VALUES (?, ?, ?, "tangible", 500000, ?, ?, ?, ?, 300000, "disposed", 2, ?)'
        );
        $stmt->execute([
            $this->supplierId,
            $inventoryNumber,
            $name,
            (self::YEAR - 5) . '-01-10',
            (self::YEAR - 5) . '-01-10',
            $disposalDate,
            $type,
            $saleInvoiceId,
        ]);
        $this->assetIds[] = (int) $this->db->pdo()->lastInsertId();
    }

    /** Vydaná faktura nesoucí klasifikaci na hlavičce (kontrola čte řádek i hlavičku). */
    private function classifiedInvoice(string $code): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId]);
        $clientId = (int) ($stmt->fetchColumn() ?: 0);
        if ($clientId === 0) {
            self::markTestSkipped('Supplier nemá žádného klienta pro testovací fakturu.');
        }
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        $stmt = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, client_id, issue_date, tax_date, due_date, currency_id,
                 created_by, total_with_vat, status, vat_classification_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 300000, "issued", ?)'
        );
        $stmt->execute([
            $this->supplierId,
            (string) random_int(1000000000, 1999999999),
            $clientId,
            self::YEAR . '-06-10',
            self::YEAR . '-06-10',
            self::YEAR . '-07-10',
            $currencyId,
            $userId,
            $code,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $invoiceId;

        return $invoiceId;
    }
}
