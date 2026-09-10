<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockLevelRepository;
use MyInvoice\Repository\StockTakeRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockIssueService;
use MyInvoice\Service\Stock\StockLevelService;
use MyInvoice\Service\Stock\StockReceiptService;
use MyInvoice\Service\Stock\StockTakeService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Sdílený základ integračních testů Epicu SKLAD (vzor BankPostingTestCase).
 *
 * IZOLACE: KAŽDÝ test si zakládá VLASTNÍHO throwaway supplier (createSupplier())
 * — NIKDY se nesahá na supplier id 1 (demo data). tearDown maže supplier(y)
 * vytvořené testem; `invoices`/`purchase_invoices`/`clients` mají supplier_id
 * FK s DELETE_RULE=RESTRICT (musí se smazat explicitně PŘED supplierem),
 * zatímco `warehouses`/`stock_items`/`stock_levels`/`stock_documents`/
 * `stock_takes`/`stock_landed_costs`/`accounting_periods`/`chart_of_accounts`/
 * `journal_entries` mají CASCADE (smaže je až finální DELETE FROM supplier).
 *
 * Reálné (commitnuté) INSERTy/DELETy — ŽÁDNÁ obalující transakce s rollbackem
 * (na rozdíl od ClosingWorkflowTest/BankPostingTestCase) — protože souběhové
 * scénáře (B3) potřebují druhé reálné DB spojení, které commitnutá data vidí.
 * Soft-skip bez cfg.php / DI.
 */
abstract class StockTestCase extends TestCase
{
    protected Connection $db;
    protected ContainerInterface $container;

    protected StockDocumentService $documents;
    protected StockIssueService $issue;
    protected StockLevelService $levels;
    protected StockTakeService $takes;
    protected StockReceiptService $receipts;
    protected StockDocumentRepository $docsRepo;
    protected StockItemRepository $itemsRepo;
    protected StockLevelRepository $levelsRepo;
    protected StockTakeRepository $takesRepo;
    protected WarehouseRepository $warehousesRepo;

    protected int $userId = 0;
    protected int $currencyId = 0;
    protected int $czId = 0;
    protected int $vatRateId = 0;
    protected string $vatRatePercent = '21.00';

    /** @var list<int> */
    protected array $supplierIds = [];
    /** @var array<int,int> supplierId => vlastní tenant-scoped CZK currencies.id */
    private array $currencyIdBySupplier = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->container      = $container;
            $this->db              = $container->get(Connection::class);
            $this->documents       = $container->get(StockDocumentService::class);
            $this->issue           = $container->get(StockIssueService::class);
            $this->levels          = $container->get(StockLevelService::class);
            $this->takes           = $container->get(StockTakeService::class);
            $this->receipts        = $container->get(StockReceiptService::class);
            $this->docsRepo        = $container->get(StockDocumentRepository::class);
            $this->itemsRepo       = $container->get(StockItemRepository::class);
            $this->levelsRepo      = $container->get(StockLevelRepository::class);
            $this->takesRepo       = $container->get(StockTakeRepository::class);
            $this->warehousesRepo  = $container->get(WarehouseRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRow = $pdo->query(
            "SELECT id, rate_percent FROM vat_rates WHERE is_reverse_charge = 0
              AND (valid_to IS NULL OR valid_to >= CURDATE()) ORDER BY is_default DESC, rate_percent DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->vatRateId = $vatRow !== false ? (int) $vatRow['id'] : 0;
        $this->vatRatePercent = $vatRow !== false ? (string) $vatRow['rate_percent'] : '21.00';

        if ($this->userId === 0 || $this->currencyId === 0 || $this->czId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/country/vat_rate) v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($this->supplierIds as $sid) {
            // RESTRICT děti nejdřív (invoice_items/purchase_invoice_items jdou
            // kaskádou s invoices/purchase_invoices). journal_entries má sice
            // CASCADE na supplier, ale MariaDB nezaručuje pořadí VÍCE souběžných
            // cascade řetězů v jednom DELETE — journal_entry_lines.account_id →
            // chart_of_accounts (bez CASCADE) může blokovat, pokud FK engine smaže
            // chart_of_accounts dřív než journal_entries/journal_entry_lines. Smaž
            // je explicitně v bezpečném pořadí (vzor EnableDoubleEntryHookTest).
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$sid]);
            // Objednávky (1330) drží RESTRICT FK na clients (vendor_id) i currencies,
            // takže musí zmizet PŘED nimi. Jejich řádky kaskádují z hlavičky a vazby
            // ze skladových dokladů / řádků PF jsou ON DELETE SET NULL.
            $pdo->prepare('DELETE FROM purchase_orders WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
            // Vlastní tenant-scoped currencies řádek (createSupplier()) — RESTRICT,
            // nikdy nesahej na sdílenou globální currencyId (patří supplieru 1).
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$sid]);
            // stock_documents/stock_takes CASCADE do svých *_lines (fk_sdl_document,
            // fk_stl_take), ale stock_document_lines/stock_take_lines TAKÉ FK na
            // stock_items BEZ CASCADE (fk_sdl_item, fk_stl_item = RESTRICT) — MariaDB
            // negarantuje pořadí dvou souběžných cascade řetězů z jednoho DELETE FROM
            // supplier, takže je smaž explicitně PŘED finálním DELETE (které by jinak
            // mohlo zkusit cascade smazat stock_items dřív, než zmizí řádky, co na ně
            // odkazují).
            $pdo->prepare('DELETE FROM stock_tracking_allocations WHERE supplier_id = ? ORDER BY id DESC')->execute([$sid]);
            $pdo->prepare('DELETE FROM stock_documents WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM stock_takes WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        }
        $this->db->close();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Throwaway supplier se skladem zapnutým (dle parametrů). Cascade smaže vše skladové. */
    protected function createSupplier(
        string $mode = 'tax_evidence',
        bool $stockEnabled = true,
        bool $autoIssue = true,
    ): int {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                 accounting_mode, is_vat_payer, stock_enabled, stock_auto_issue)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            'STOCK TEST ' . uniqid('', true),
            $this->czId,
            uniqid('stocktest', true) . '@example.test',
            $this->currencyId,
            $this->vatRateId,
            $mode,
            $stockEnabled ? 1 : 0,
            $autoIssue ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->supplierIds[] = $id;

        // Vlastní tenant-scoped CZK měna (currencies má supplier_id + RESTRICT
        // delete) — sdílená globální currencyId patří jinému supplieru; některé
        // dotazy (např. StockReceiptService::findPurchaseInvoice) JOINují
        // currencies s c.supplier_id = pi.supplier_id, takže cizí měna by se
        // "ztratila" (not_found).
        $ownCurrency = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "Koruna česká", "Kč", "Koruna česká", "Czech koruna", 2, 1, 1)'
        );
        $ownCurrency->execute([$id]);
        $this->currencyIdBySupplier[$id] = (int) $pdo->lastInsertId();

        return $id;
    }

    protected function currencyIdFor(int $supplierId): int
    {
        return $this->currencyIdBySupplier[$supplierId] ?? $this->currencyId;
    }

    protected function warehouse(int $supplierId, string $code = 'HLAVNI', bool $isDefault = true, bool $isActive = true): int
    {
        return $this->warehousesRepo->insert($supplierId, [
            'code'       => $code,
            'name'       => 'Sklad ' . $code,
            'is_default' => $isDefault,
            'is_active'  => $isActive,
        ]);
    }

    protected function item(int $supplierId, string $sku, string $type = 'goods', bool $active = true): int
    {
        return $this->itemsRepo->insert($supplierId, [
            'sku'       => $sku,
            'name'      => 'Karta ' . $sku,
            'item_type' => $type,
            'unit'      => 'ks',
            'is_active' => $active,
        ]);
    }

    protected function client(int $supplierId, string $name = 'Testovací klient'): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                   language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "cs", ?, 1, 1)'
        );
        $stmt->execute([$supplierId, $name, $this->czId, uniqid('cl', true) . '@example.test', $this->currencyIdFor($supplierId)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $over */
    protected function invoiceDraft(int $supplierId, int $clientId, string $type = 'invoice', array $over = []): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, client_id, invoice_type, issue_date, due_date, tax_date,
                                    currency_id, created_by, status, varsymbol, parent_invoice_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)'
        );
        $issueDate = (string) ($over['issue_date'] ?? '2099-06-10');
        $stmt->execute([
            $supplierId,
            $clientId,
            $type,
            $issueDate,
            (string) ($over['due_date'] ?? '2099-06-24'),
            (string) ($over['tax_date'] ?? $issueDate),
            $this->currencyIdFor($supplierId),
            $this->userId,
            $over['varsymbol'] ?? null,
            isset($over['parent_invoice_id']) ? (int) $over['parent_invoice_id'] : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function invoiceItem(
        int $invoiceId,
        ?int $stockItemId,
        ?int $warehouseId,
        string $qty = '1.000',
        float $unitPrice = 100.0,
        int $orderIndex = 0,
    ): int {
        $totalWithoutVat = round((float) $qty * $unitPrice, 2);
        $totalVat        = round($totalWithoutVat * ((float) $this->vatRatePercent) / 100, 2);
        $totalWithVat    = round($totalWithoutVat + $totalVat, 2);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, stock_item_id, warehouse_id, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $invoiceId,
            'Testovací položka',
            $qty,
            $unitPrice,
            $this->vatRateId,
            $this->vatRatePercent,
            $totalWithoutVat,
            $totalVat,
            $totalWithVat,
            $stockItemId,
            $warehouseId,
            $orderIndex,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $over */
    protected function purchaseInvoice(int $supplierId, int $vendorId, array $over = []): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, due_date, received_at,
                 currency_id, vendor_snapshot, created_by, varsymbol, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $issueDate = (string) ($over['issue_date'] ?? '2099-06-01');
        $stmt->execute([
            $supplierId,
            $vendorId,
            (string) ($over['vendor_invoice_number'] ?? ('DOD-' . uniqid())),
            $issueDate,
            (string) ($over['due_date'] ?? '2099-06-15'),
            (string) ($over['received_at'] ?? $issueDate),
            $this->currencyIdFor($supplierId),
            json_encode(['company_name' => 'Dodavatel test'], JSON_UNESCAPED_UNICODE),
            $this->userId,
            $over['varsymbol'] ?? null,
            (string) ($over['status'] ?? 'received'),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    protected function purchaseInvoiceItem(
        int $piId,
        ?int $stockItemId,
        string $qty = '1.000',
        float $unitPrice = 100.0,
        int $orderIndex = 0,
    ): int {
        $totalWithoutVat = round((float) $qty * $unitPrice, 2);
        $totalVat        = round($totalWithoutVat * ((float) $this->vatRatePercent) / 100, 2);
        $totalWithVat    = round($totalWithoutVat + $totalVat, 2);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, stock_item_id, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $piId,
            'Testovací položka PF',
            $qty,
            $unitPrice,
            $this->vatRateId,
            $this->vatRatePercent,
            $totalWithoutVat,
            $totalVat,
            $totalWithVat,
            $stockItemId,
            $orderIndex,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{qtyT:int,valueC:int} */
    protected function level(int $supplierId, int $warehouseId, int $stockItemId): array
    {
        return $this->levels->current($supplierId, $warehouseId, $stockItemId);
    }

    /** Vytvoří a rovnou zaúčtuje ruční příjemku (pomocník pro seed stavů). */
    protected function receiveStock(int $supplierId, int $warehouseId, int $stockItemId, string $qty, float $unitCost, string $docDate = '2099-01-10'): array
    {
        $draft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $warehouseId,
            'doc_date'     => $docDate,
            'description'  => 'Test příjem',
            'lines'        => [[
                'stock_item_id' => $stockItemId,
                'qty'           => $qty,
                'unit_cost'     => number_format($unitCost, 6, '.', ''),
            ]],
        ], $this->userId);
        return $this->documents->post($supplierId, (int) $draft['id'], $this->userId);
    }
}
