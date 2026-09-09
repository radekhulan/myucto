<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\VatClassificationMapper;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy pro VatClassificationMapper::aggregateForDphPriznani.
 *
 * Pokrývá hlavně regression scenarios z issue #29:
 *   1. Reverse charge / EU pořízení zboží v EUR — kurz se musí aplikovat na
 *      základ + samovyměřená daň na ř. 3 + mirror odpočet na ř. 43.
 *   2. Pořízení dlouhodobého majetku — hodnota navíc na ř. 47 (doplňující údaj).
 *
 * Test používá in-memory SQLite (žádná závislost na MariaDB) — Connection
 * mockujeme s nadřazením PDO instance přes reflection.
 */
final class VatClassificationMapperTest extends TestCase
{
    private PDO $pdo;
    private VatClassificationMapper $mapper;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seedClassifications();

        // Bypass-finals enables overriding Connection. Config se nikdy nečte,
        // protože jí "skipneme" tím, že nastavíme pdo property přímo.
        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $ref = new \ReflectionClass($conn);
        $prop = $ref->getProperty('pdo');
        $prop->setValue($conn, $this->pdo);

        $this->mapper = new VatClassificationMapper($conn, new VatLedgerService($conn, new TaxConstantsRepository($conn)));
    }

    public function testEuAcquisitionInEurAppliesExchangeRate(): void
    {
        // Scenario: 1000 EUR @ kurz 25 = 25 000 CZK base, RC 21 % → 5250 CZK daň.
        // Code 23 → primary ř. 3 (output), secondary ř. 43 (mirror odpočet).
        $this->insertPurchaseInvoice(
            id: 1, supplierId: 1, vendorId: 100,
            issueDate: '2026-05-15', taxDate: '2026-05-15',
            currencyCode: 'EUR', exchangeRate: 25.0, reverseCharge: 1,
            classCode: '23', isFixedAsset: 0,
        );
        $this->insertPurchaseInvoiceItem(
            id: 1, invoiceId: 1, base: 1000.0, vat: 0.0, vatRate: 21.0,
            classCode: null, isFixedAsset: 0,
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');

        // ř. 3 — výstup samovyměřená daň
        $this->assertArrayHasKey('3', $lines, 'EU pořízení zboží musí dorazit na ř. 3');
        $this->assertSame(25000.0, $lines['3']['base'], 'Základ v CZK = EUR × kurz');
        $this->assertSame(5250.0,  $lines['3']['vat'],  'Samovyměřená daň = base × 21 %');

        // ř. 43 — mirror odpočet (secondary line z migrace 0044)
        $this->assertArrayHasKey('43', $lines, 'RC musí mirror na ř. 43 (nárok na odpočet)');
        $this->assertSame(25000.0, $lines['43']['base']);
        $this->assertSame(5250.0,  $lines['43']['vat']);
    }

    public function testFixedAssetPopulatesRow47(): void
    {
        // Scenario: tuzemský nákup vozidla, 1 000 000 Kč + 210 000 DPH (21 %),
        // is_fixed_asset=1. Musí být na ř. 40 (běžný odpočet) ZÁROVEŇ na ř. 47
        // (doplňující údaj). Klasifikační kód 40 = tuzemsko 21 % nárok.
        $this->insertPurchaseInvoice(
            id: 2, supplierId: 1, vendorId: 200,
            issueDate: '2026-05-20', taxDate: '2026-05-20',
            currencyCode: 'CZK', exchangeRate: 1.0, reverseCharge: 0,
            classCode: '40', isFixedAsset: 1,
        );
        $this->insertPurchaseInvoiceItem(
            id: 2, invoiceId: 2, base: 1000000.0, vat: 210000.0, vatRate: 21.0,
            classCode: null, isFixedAsset: 0, // header flag, ne per-item
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');

        $this->assertArrayHasKey('40', $lines);
        $this->assertSame(1000000.0, $lines['40']['base']);
        $this->assertSame(210000.0,  $lines['40']['vat']);

        $this->assertArrayHasKey('47', $lines, 'is_fixed_asset musí naplnit ř. 47');
        $this->assertSame(1000000.0, $lines['47']['base'], 'Hodnota pořízeného majetku');
    }

    public function testFixedAssetOnRcEuAcquisitionAppearsOn47(): void
    {
        // Edge case: pořízení vozidla z DE v RC režimu (vzácné). Primary ř. 3,
        // secondary ř. 43 odpočet, A ř. 47 (přes secondary v 40-45 range).
        $this->insertPurchaseInvoice(
            id: 3, supplierId: 1, vendorId: 100,
            issueDate: '2026-05-10', taxDate: '2026-05-10',
            currencyCode: 'EUR', exchangeRate: 25.0, reverseCharge: 1,
            classCode: '23', isFixedAsset: 1,
        );
        $this->insertPurchaseInvoiceItem(
            id: 3, invoiceId: 3, base: 40000.0, vat: 0.0, vatRate: 21.0,
            classCode: null, isFixedAsset: 0,
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');

        $this->assertArrayHasKey('3',  $lines);
        $this->assertArrayHasKey('43', $lines);
        $this->assertArrayHasKey('47', $lines);
        // Base v CZK = 40000 × 25 = 1 000 000
        $this->assertSame(1000000.0, $lines['47']['base']);
    }

    public function testDraftInvoiceExcluded(): void
    {
        $this->insertPurchaseInvoice(
            id: 4, supplierId: 1, vendorId: 200,
            issueDate: '2026-05-15', taxDate: '2026-05-15',
            currencyCode: 'CZK', exchangeRate: 1.0, reverseCharge: 0,
            classCode: '40', isFixedAsset: 0, status: 'draft',
        );
        $this->insertPurchaseInvoiceItem(
            id: 4, invoiceId: 4, base: 5000.0, vat: 1050.0, vatRate: 21.0,
            classCode: null, isFixedAsset: 0,
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');
        $this->assertSame([], $lines, 'Drafty se do DPHDP3 nezapočítávají');
    }

    public function testOssSaleIsExcludedFromDomesticVatLedger(): void
    {
        $this->pdo->exec(
            "INSERT INTO invoices
                (id, supplier_id, client_id, varsymbol, issue_date, tax_date, currency_id,
                 exchange_rate, reverse_charge, status, invoice_type, total_with_vat)
             VALUES (10, 1, 200, 'OSS-TEST', '2026-05-20', '2026-05-20', 1,
                     1, 0, 'issued', 'invoice', 119)"
        );
        $this->pdo->exec(
            "INSERT INTO invoice_items
                (id, invoice_id, vat_rate_snapshot, description, total_without_vat,
                 total_vat, vat_classification_code, oss_applicable)
             VALUES (10, 10, 19, 'OSS sale', 100, 19, '1', 1)"
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');

        $this->assertSame([], $lines, 'OSS plneni nesmi vstoupit do tuzemskeho DPH priznani');
    }

    public function testPerTenantOverrideWinsOverGlobal(): void
    {
        // Globální kód 40 → ř.40. Per-tenant override (supplier 1) ho přemapuje na ř.41.
        // Override MUSÍ vyhrát (ORDER BY supplier_id IS NULL DESC → tenant zapsán poslední).
        $this->pdo->exec("INSERT INTO vat_classifications
            (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
             kh_section, vat_rate, is_reverse_charge, display_order, archived)
            VALUES (1, '40', 'Override 40→41', 'purchase', '41', NULL, 'B.2', 21.0, 0, 0, 0)");
        $this->insertPurchaseInvoice(
            id: 5, supplierId: 1, vendorId: 200, issueDate: '2026-05-15', taxDate: '2026-05-15',
            currencyCode: 'CZK', exchangeRate: 1.0, reverseCharge: 0, classCode: '40', isFixedAsset: 0,
        );
        $this->insertPurchaseInvoiceItem(
            id: 5, invoiceId: 5, base: 1000.0, vat: 210.0, vatRate: 21.0, classCode: null, isFixedAsset: 0,
        );

        $lines = $this->mapper->aggregateForDphPriznani(1, 2026, 5, 'monthly');
        $this->assertArrayHasKey('41', $lines, 'Per-tenant override (40→ř.41) musí vyhrát nad globálním seedem');
        $this->assertArrayNotHasKey('40', $lines, 'Globální mapování 40→ř.40 nesmí vyhrát');
        $this->assertSame(1000.0, $lines['41']['base']);
    }

    // ───── helpers ─────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        // Minimální schema pro VatClassificationMapper queries. Žádné FK, žádné
        // indexy — jen tabulky a sloupce čtené v aggregateForDphPriznani.
        $this->pdo->exec("CREATE TABLE currencies (
            id INTEGER PRIMARY KEY, code TEXT NOT NULL
        )");
        $this->pdo->exec("INSERT INTO currencies (id, code) VALUES (1, 'CZK'), (2, 'EUR')");

        // VatLedgerService JOINuje clients + countries (kvůli protistraně/zemi pro KH).
        $this->pdo->exec("CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, is_eu INTEGER NOT NULL DEFAULT 0)");

        // Daňové konstanty — prázdná tabulka = žádný override → defaulty z TaxConstants
        $this->pdo->exec("CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)");
        $this->pdo->exec("INSERT INTO countries (id, iso2, is_eu) VALUES (1, 'CZ', 1), (4, 'DE', 1)");
        $this->pdo->exec("CREATE TABLE clients (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '',
            dic TEXT NULL, country_id INTEGER NULL
        )");
        // Dodavatelé použití v testech: 100 = DE (EU pořízení), 200 = CZ.
        $this->pdo->exec("INSERT INTO clients (id, company_name, dic, country_id) VALUES
            (100, 'Vendor DE', 'DE123456789', 4), (200, 'Vendor CZ', 'CZ22222220', 1)");

        $this->pdo->exec("CREATE TABLE vat_classifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NULL,
            code TEXT NOT NULL,
            label TEXT NOT NULL,
            direction TEXT NOT NULL,
            dphdp3_line TEXT NULL,
            dphdp3_line_secondary TEXT NULL,
            kh_section TEXT NULL,
            vat_rate REAL NULL,
            is_reverse_charge INTEGER NOT NULL DEFAULT 0,
            kod_pred_pl TEXT NULL,
            kh_regime_code TEXT NULL,
            kh_bad_debt TEXT NULL,
            display_order INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        )");

        $this->pdo->exec("CREATE TABLE purchase_invoices (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER NOT NULL,
            vendor_id INTEGER NOT NULL,
            varsymbol TEXT NULL,
            vendor_invoice_number TEXT NULL,
            document_kind TEXT NULL,
            parent_purchase_invoice_id INTEGER NULL,
            issue_date TEXT NOT NULL,
            tax_date TEXT NULL,
            received_at TEXT NULL,
            received_at_source TEXT NOT NULL DEFAULT 'import',
            currency_id INTEGER NOT NULL,
            exchange_rate REAL NULL,
            reverse_charge INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'received',
            vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full',
            vat_deduction_percent REAL NOT NULL DEFAULT 100,
            tax_deductible INTEGER NOT NULL DEFAULT 1,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0,
            total_with_vat REAL NOT NULL DEFAULT 0
        )");

        $this->pdo->exec("CREATE TABLE purchase_invoice_items (
            id INTEGER PRIMARY KEY,
            purchase_invoice_id INTEGER NOT NULL,
            vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL,
            total_without_vat REAL NOT NULL,
            total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_vat_allocations (
            id INTEGER PRIMARY KEY,
            purchase_invoice_id INTEGER NOT NULL,
            description TEXT NULL,
            vat_rate REAL NOT NULL,
            base_amount REAL NOT NULL,
            vat_amount REAL NOT NULL,
            vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full',
            vat_deduction_percent REAL NOT NULL DEFAULT 100
        )");

        $this->pdo->exec("CREATE TABLE invoices (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER NOT NULL,
            client_id INTEGER NULL,
            varsymbol TEXT NULL,
            issue_date TEXT NOT NULL,
            tax_date TEXT NULL,
            currency_id INTEGER NOT NULL,
            exchange_rate REAL NULL,
            reverse_charge INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'issued',
            invoice_type TEXT NOT NULL DEFAULT 'invoice',
            vat_classification_code TEXT NULL,
            total_with_vat REAL NOT NULL DEFAULT 0,
            effective_tax_date TEXT GENERATED ALWAYS AS (COALESCE(tax_date, issue_date)) STORED
        )");

        $this->pdo->exec("CREATE TABLE invoice_items (
            id INTEGER PRIMARY KEY,
            invoice_id INTEGER NOT NULL,
            vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL,
            total_without_vat REAL NOT NULL,
            total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL,
            oss_applicable INTEGER NOT NULL DEFAULT 0
        )");

        // Pokladna (mini-epic #14) — VatLedgerService::fetchCash() JOINuje tyto
        // tabulky. Prázdné → žádné cash řádky (chování neutrální k faktury-only testům).
        $this->pdo->exec("CREATE TABLE cash_documents (
            id INTEGER PRIMARY KEY,
            supplier_id INTEGER NOT NULL,
            doc_number TEXT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            doc_type TEXT NOT NULL,
            vat_mode TEXT NOT NULL DEFAULT 'none',
            tax_date TEXT NULL,
            issue_date TEXT NOT NULL,
            total_amount REAL NOT NULL DEFAULT 0,
            partner_name TEXT NULL,
            partner_dic TEXT NULL,
            description TEXT NULL,
            invoice_id INTEGER NULL,
            purchase_invoice_id INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE cash_document_vat_lines (
            id INTEGER PRIMARY KEY,
            cash_document_id INTEGER NOT NULL,
            vat_rate REAL NOT NULL,
            base_amount REAL NOT NULL,
            vat_amount REAL NOT NULL,
            vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full',
            vat_deduction_percent REAL NOT NULL DEFAULT 100,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0
        )");
    }

    private function seedClassifications(): void
    {
        // Z migrace 0037 + 0044 (relevantní kódy pro tyto testy)
        $rows = [
            // code, label, dir, primary, secondary, kh, rate, rc
            ['1',  'Sale 21 %', 'sale', '1', null, 'A.4', 21.0, 0],
            ['40', 'Purchase tuzemsko 21 %', 'purchase', '40', null, 'B.2', 21.0, 0],
            ['41', 'Purchase tuzemsko 12 %', 'purchase', '41', null, 'B.2', 12.0, 0],
            ['5',  'Tuzemský RC', 'purchase', '10', '43', 'B.1', 21.0, 1],
            ['23', 'EU pořízení zboží', 'purchase', '3', '43', 'A.2', 21.0, 1],
        ];
        $stmt = $this->pdo->prepare(
            "INSERT INTO vat_classifications
                (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
                 kh_section, vat_rate, is_reverse_charge, display_order, archived)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)"
        );
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
    }

    private function insertPurchaseInvoice(
        int $id, int $supplierId, int $vendorId,
        string $issueDate, string $taxDate,
        string $currencyCode, float $exchangeRate,
        int $reverseCharge, string $classCode, int $isFixedAsset,
        string $status = 'received',
    ): void {
        $currencyId = $currencyCode === 'CZK' ? 1 : 2;
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (id, supplier_id, vendor_id, issue_date, tax_date,
                 currency_id, exchange_rate, reverse_charge, status,
                 vat_classification_code, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $id, $supplierId, $vendorId, $issueDate, $taxDate,
            $currencyId, $exchangeRate, $reverseCharge, $status,
            $classCode, $isFixedAsset,
        ]);
    }

    private function insertPurchaseInvoiceItem(
        int $id, int $invoiceId, float $base, float $vat, float $vatRate,
        ?string $classCode, int $isFixedAsset,
    ): void {
        $this->pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (id, purchase_invoice_id, vat_rate_snapshot,
                 total_without_vat, total_vat, vat_classification_code, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $invoiceId, $vatRate, $base, $vat, $classCode, $isFixedAsset]);
    }
}
