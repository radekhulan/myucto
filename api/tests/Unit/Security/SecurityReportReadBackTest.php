<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FuelingRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecurityReportReadBackTest extends TestCase
{
    public function testHistoricalForeignFuelingLinksDoNotExposeData(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $columns = ['fueled_date', 'fueled_time', 'fuel_type', 'quantity', 'unit', 'unit_price', 'amount_without_vat', 'amount_vat', 'amount_with_vat', 'currency', 'odometer', 'station', 'source', 'receipt_number', 'raw_text', 'note', 'created_at'];
        $columns = [...$columns, 'car_assigned_by', 'card_last4'];
        $pdo->exec('CREATE TABLE fuelings (id INTEGER PRIMARY KEY, supplier_id INTEGER, vendor_id INTEGER, car_id INTEGER, source_purchase_invoice_id INTEGER, source_cash_document_id INTEGER, source_bank_transaction_id INTEGER, source_journal_entry_id INTEGER, ' . implode(', ', array_map(static fn (string $c): string => $c . ' TEXT', $columns)) . ')');
        $pdo->exec('INSERT INTO fuelings (id, supplier_id, vendor_id, car_id, source_purchase_invoice_id, source_cash_document_id, source_bank_transaction_id, source_journal_entry_id) VALUES (1, 1, 2, 2, 2, 2, 2, 2)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, company_name TEXT)');
        $pdo->exec("INSERT INTO clients VALUES (2, 2, 'Synthetic private client')");
        $pdo->exec('CREATE TABLE cars (id INTEGER PRIMARY KEY, supplier_id INTEGER, registration TEXT, name TEXT)');
        $pdo->exec("INSERT INTO cars VALUES (2, 2, 'SECRET-2', 'Synthetic private car')");
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, vendor_invoice_number TEXT)');
        $pdo->exec("INSERT INTO purchase_invoices VALUES (2, 2, 'SECRET-PI-2')");
        $pdo->exec('CREATE TABLE cash_documents (id INTEGER PRIMARY KEY, supplier_id INTEGER, doc_number TEXT)');
        $pdo->exec("INSERT INTO cash_documents VALUES (2, 2, 'SECRET-CD-2')");
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('INSERT INTO bank_statements VALUES (2, 2)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER, posted_at TEXT, amount TEXT)');
        $pdo->exec("INSERT INTO bank_transactions VALUES (2, 2, '2026-01-02', '-999.00')");
        $pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, document_no TEXT)');
        $pdo->exec("INSERT INTO journal_entries VALUES (2, 2, 'SECRET-JE-2')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $repo = new FuelingRepository($db);
        foreach ([$repo->find(1, 1), ...$repo->listForTenant(1), ...$repo->listPaged(1, [], 10, 0)[0]] as $row) {
            self::assertSame(1, $row['id']);
            self::assertNull($row['vendor_name']);
            self::assertNull($row['car_name']);
            self::assertNull($row['car_registration']);
            self::assertNull($row['source_invoice_number']);
            self::assertNull($row['source_cash_document_number']);
            self::assertNull($row['source_bank_statement_id']);
            self::assertNull($row['source_bank_posted_at']);
            self::assertNull($row['source_bank_amount']);
            self::assertNull($row['source_journal_entry_number']);
        }
        $pdo->exec('UPDATE clients SET supplier_id = 1');
        self::assertSame('Synthetic private client', $repo->find(1, 1)['vendor_name']);
    }

    public function testValidReferencesStillCheckActualOwnership(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('INSERT INTO clients VALUES (1, 1), (2, 2)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $guard = new TenantReferenceGuard($db);
        foreach ([1, '1', '01', ' 1 ', '+1', '1e0', 1.0] as $id) {
            self::assertSame([], $guard->violations(1, ['vendor_id' => $id], ['vendor_id']));
        }
        foreach ([2, '2', '02', ' 2 ', '+2', '2e0', 2.0] as $id) {
            self::assertSame(['vendor_id'], $guard->violations(1, ['vendor_id' => $id], ['vendor_id']));
        }
    }
}
