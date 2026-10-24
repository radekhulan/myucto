<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Zapisovač převzatých dokladů zapisuje to, co mu importér předá, a nic nedoplňuje:
 * režim cen, přenesená povinnost, měna a kurz jdou z DTO, nepovinné sloupce dostanou
 * hodnotu shodnou s DEFAULT tabulky.
 */
final class MigratedDocumentWriterTest extends TestCase
{
    private PDO $pdo;
    private MigratedDocumentWriter $writer;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INT, invoice_type TEXT, client_id INT, varsymbol TEXT, payment_variable_symbol TEXT,
            issue_date TEXT, tax_date TEXT, due_date TEXT, currency_id INT, exchange_rate NUMERIC,
            note_above_items TEXT, note_below_items TEXT, client_snapshot TEXT,
            total_without_vat NUMERIC, total_vat NUMERIC, total_with_vat NUMERIC, rounding NUMERIC,
            advance_paid_amount NUMERIC, paid_total NUMERIC, paid_at TEXT, status TEXT, booked_at TEXT,
            booked_by INT, vat_classification_code TEXT, reverse_charge INT, payment_method TEXT,
            prices_include_vat INT, created_by INT, supplier_snapshot TEXT, bank_snapshot TEXT,
            parent_invoice_id INT, corrective_delivered_on TEXT)');
        $this->pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INT, description TEXT, quantity NUMERIC, unit TEXT, unit_price_without_vat NUMERIC,
            vat_rate_id INT, vat_rate_snapshot NUMERIC, total_without_vat NUMERIC, total_vat NUMERIC,
            total_with_vat NUMERIC, order_index INT, vat_classification_code TEXT,
            oss_applicable INT, oss_consumer_country TEXT, oss_rate_type TEXT, oss_supply_type TEXT,
            oss_needs_manual_review INT, oss_taxable_amount_return NUMERIC, oss_vat_amount_return NUMERIC,
            revenue_account_code TEXT)');
        $this->pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INT, vendor_id INT, vendor_is_vat_payer INT, varsymbol TEXT, vendor_invoice_number TEXT,
            document_kind TEXT, issue_date TEXT, tax_date TEXT, due_date TEXT, received_at TEXT,
            received_at_source TEXT, currency_id INT, exchange_rate NUMERIC, vendor_snapshot TEXT,
            total_without_vat NUMERIC, total_vat NUMERIC, total_with_vat NUMERIC, rounding NUMERIC,
            advance_paid_amount NUMERIC, payment_variable_symbol TEXT, payment_constant_symbol TEXT,
            payment_account_number TEXT, payment_bank_code TEXT, payment_method TEXT, status TEXT,
            paid_at TEXT, booked_at TEXT, booked_by INT, note_above_items TEXT, note_below_items TEXT,
            external_barcode TEXT, vat_deduction TEXT, vat_classification_code TEXT, reverse_charge INT,
            is_fixed_asset INT, prices_include_vat INT, created_by INT)');
        $this->pdo->exec('CREATE TABLE purchase_invoice_items (id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_invoice_id INT, description TEXT, quantity NUMERIC, unit TEXT, unit_price_without_vat NUMERIC,
            vat_rate_id INT, vat_rate_snapshot NUMERIC, total_without_vat NUMERIC, total_vat NUMERIC,
            total_with_vat NUMERIC, order_index INT, vat_classification_code TEXT, is_fixed_asset INT,
            expense_kind TEXT, expense_account_code TEXT)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->writer = new MigratedDocumentWriter($db);
    }

    public function testIssuedDocumentKeepsExplicitVatModeAndFillsColumnDefaults(): void
    {
        $id = $this->writer->insertIssued(new MigratedIssuedDocument(
            supplierId: 7,
            invoiceType: 'invoice',
            clientId: 3,
            varsymbol: 'FV2024001',
            issueDate: '2024-03-01',
            taxDate: '2024-02-29',
            dueDate: '2024-03-15',
            currencyId: 5,
            exchangeRate: null,
            pricesIncludeVat: true,
            reverseCharge: true,
            noteAboveItems: null,
            noteBelowItems: 'Převzato',
            clientSnapshot: '{"company_name":"Odběratel"}',
            totalWithoutVat: 100.0,
            totalVat: 21.0,
            totalWithVat: 121.0,
            rounding: 0.0,
            status: 'sent',
            createdBy: 9,
        ));

        $row = $this->pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch();
        self::assertSame(1, (int) $row['prices_include_vat']);
        self::assertSame(1, (int) $row['reverse_charge']);
        self::assertNull($row['exchange_rate']);
        self::assertSame(5, (int) $row['currency_id']);
        self::assertSame('2024-02-29', $row['tax_date']);
        // Nepovinné sloupce = DEFAULT tabulky (advance_paid_amount 0, paid_total 0, bank_transfer, NULL).
        self::assertSame(0.0, (float) $row['advance_paid_amount']);
        self::assertSame(0.0, (float) $row['paid_total']);
        self::assertSame('bank_transfer', $row['payment_method']);
        self::assertNull($row['payment_variable_symbol']);
        self::assertNull($row['paid_at']);
        self::assertNull($row['booked_at']);
        self::assertNull($row['booked_by']);
        self::assertNull($row['vat_classification_code']);
        // Sloupce, které nese jen import přes API — převod je nezná, takže zůstávají NULL.
        self::assertNull($row['supplier_snapshot']);
        self::assertNull($row['bank_snapshot']);
        self::assertNull($row['parent_invoice_id']);
        self::assertNull($row['corrective_delivered_on']);
    }

    public function testIssuedDocumentWritesApiImportColumnsWhenGiven(): void
    {
        $id = $this->writer->insertIssued(new MigratedIssuedDocument(
            supplierId: 7,
            invoiceType: 'credit_note',
            clientId: 3,
            varsymbol: 'D2024001',
            issueDate: '2024-03-01',
            taxDate: '2024-03-04',
            dueDate: '2024-03-15',
            currencyId: 5,
            exchangeRate: null,
            pricesIncludeVat: false,
            reverseCharge: false,
            noteAboveItems: null,
            noteBelowItems: null,
            clientSnapshot: '{}',
            totalWithoutVat: -100.0,
            totalVat: -21.0,
            totalWithVat: -121.0,
            rounding: 0.0,
            status: 'issued',
            createdBy: 9,
            supplierSnapshot: '{"company_name":"Dodavatel"}',
            bankSnapshot: '{"account_number":"1000000005"}',
            parentInvoiceId: 42,
            correctiveDeliveredOn: '2024-03-04',
        ));

        $row = $this->pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch();
        self::assertSame('{"company_name":"Dodavatel"}', $row['supplier_snapshot']);
        self::assertSame('{"account_number":"1000000005"}', $row['bank_snapshot']);
        self::assertSame(42, (int) $row['parent_invoice_id']);
        self::assertSame('2024-03-04', $row['corrective_delivered_on']);
    }

    public function testIssuedItemsCarryOrderIndexFromKeysAndOssColumns(): void
    {
        $oss = ['oss_applicable' => 1, 'oss_consumer_country' => 'DE', 'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods', 'oss_needs_manual_review' => 0,
            'oss_taxable_amount_return' => 4.0, 'oss_vat_amount_return' => 0.76];
        $this->writer->insertIssuedItems(11, [
            0 => MigratedDocumentItem::issued('Zboží', 2.0, 'ks', 50.0, 4, 19.0, 100.0, 19.0, 119.0, null, $oss),
            1 => MigratedDocumentItem::issued('Doprava', 1.0, 'ks', 10.0, 1, 21.0, 10.0, 2.1, 12.1, '1', OssMigrationPolicy::DOMESTIC_COLUMNS),
        ]);

        $rows = $this->pdo->query('SELECT * FROM invoice_items ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame([0, 1], array_map(static fn (array $r): int => (int) $r['order_index'], $rows));
        self::assertSame(1, (int) $rows[0]['oss_applicable']);
        self::assertSame('DE', $rows[0]['oss_consumer_country']);
        // Částky pro OSS přiznání v měně podání, když je zdroj zná; jinak NULL = dopočte náhled.
        self::assertSame(4.0, (float) $rows[0]['oss_taxable_amount_return']);
        self::assertSame(0.76, (float) $rows[0]['oss_vat_amount_return']);
        self::assertSame(0, (int) $rows[1]['oss_applicable']);
        self::assertNull($rows[1]['oss_consumer_country']);
        self::assertNull($rows[1]['oss_taxable_amount_return']);
        self::assertNull($rows[1]['oss_vat_amount_return']);
        self::assertSame('1', $rows[1]['vat_classification_code']);
    }

    public function testPurchaseDocumentAndItems(): void
    {
        $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
            supplierId: 7,
            vendorId: 4,
            vendorIsVatPayer: true,
            varsymbol: 'FP24001',
            vendorInvoiceNumber: 'DF-1',
            documentKind: 'invoice',
            issueDate: '2024-01-10',
            taxDate: '2024-01-09',
            dueDate: '2024-01-24',
            receivedAt: '2024-02-05',
            receivedAtSource: 'manual',
            currencyId: 5,
            exchangeRate: null,
            pricesIncludeVat: false,
            reverseCharge: true,
            vendorSnapshot: '{}',
            totalWithoutVat: 1000.0,
            totalVat: 0.0,
            totalWithVat: 1000.0,
            rounding: 0.0,
            status: 'booked',
            vatDeduction: 'full',
            noteAboveItems: null,
            noteBelowItems: 'Převzato',
            createdBy: 9,
            externalBarcode: '90000101',
            isFixedAsset: true,
        ));
        $this->writer->insertPurchaseItems($id, [
            MigratedDocumentItem::purchase('Služba z EU', 1.0, 'ks', 1000.0, 1, 21.0, 1000.0, 0.0, 1000.0, '43', true, 'fixed_asset'),
        ]);

        $row = $this->pdo->query("SELECT * FROM purchase_invoices WHERE id = {$id}")->fetch();
        self::assertSame(0, (int) $row['prices_include_vat']);
        self::assertSame(1, (int) $row['reverse_charge']);
        self::assertSame(1, (int) $row['vendor_is_vat_payer']);
        self::assertSame(1, (int) $row['is_fixed_asset']);
        self::assertSame('manual', $row['received_at_source']);
        self::assertSame('90000101', $row['external_barcode']);
        self::assertSame('bank_transfer', $row['payment_method']);
        self::assertNull($row['payment_constant_symbol']);
        self::assertSame(0.0, (float) $row['advance_paid_amount']);

        $item = $this->pdo->query('SELECT * FROM purchase_invoice_items')->fetch();
        self::assertSame($id, (int) $item['purchase_invoice_id']);
        self::assertSame('43', $item['vat_classification_code']);
        self::assertSame(1, (int) $item['is_fixed_asset']);
        self::assertSame('fixed_asset', $item['expense_kind']);
        self::assertSame(0, (int) $item['order_index']);
    }

    public function testIssuedItemWithoutOssDecisionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MigratedDocumentItem::issued('Zboží', 1.0, 'ks', 10.0, 1, 21.0, 10.0, 2.1, 12.1, null, ['oss_applicable' => 0]);
    }

    public function testPurchaseItemIsDomestic(): void
    {
        $item = MigratedDocumentItem::purchase('Materiál', 1.0, 'ks', 10.0, 1, 21.0, 10.0, 2.1, 12.1, null);
        self::assertSame(OssMigrationPolicy::DOMESTIC_COLUMNS, $item->oss);
        self::assertFalse($item->isFixedAsset);
        self::assertNull($item->expenseKind);
    }

    /**
     * Aplikace drží expense_kind='fixed_asset' ⇔ is_fixed_asset=1 (PurchaseInvoiceRepository,
     * migrace 1092). Převzatá položka s příznakem majetku bez druhu by se prvním uložením
     * nebo automatickou klasifikací rozešla a příznak pro ř. 47 by zmizel.
     */
    public function testFixedAssetItemGetsFixedAssetExpenseKind(): void
    {
        $item = MigratedDocumentItem::purchase('Stroj', 1.0, 'ks', 100000.0, 1, 21.0, 100000.0, 21000.0, 121000.0, '40', true);
        self::assertTrue($item->isFixedAsset);
        self::assertSame('fixed_asset', $item->expenseKind);

        $explicit = MigratedDocumentItem::purchase('Monitor', 1.0, 'ks', 5000.0, 1, 21.0, 5000.0, 1050.0, 6050.0, '40', true, 'small_asset');
        self::assertSame('small_asset', $explicit->expenseKind, 'Druh daný zdrojem (účet položky) se nepřepisuje.');
    }
}
