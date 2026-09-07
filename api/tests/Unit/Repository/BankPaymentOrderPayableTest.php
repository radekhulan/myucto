<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class BankPaymentOrderPayableTest extends TestCase
{
    public function testSnapshotCannotPaySettledAmountOrTaxDocumentOrForeignSupplier(): void
    {
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->createFunction('GREATEST', static fn (...$values) => max($values));
        $pdo->exec('CREATE TABLE currencies (id INTEGER, supplier_id INTEGER, code TEXT)');
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC)');
        $pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE offset_agreements (id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)');
        $pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK')");
        $pdo->exec("INSERT INTO purchase_invoices VALUES (101, 1, 11, 'received', 'invoice', 'bank_transfer', 100, 0)");
        $pdo->exec('INSERT INTO payment_order_items VALUES (501, 101, 100)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $repository = new PaymentOrderRepository($db);

        self::assertTrue($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec('UPDATE purchase_invoices SET amount_to_pay = 100.60, rounding = 0.40');
        $pdo->exec('UPDATE payment_order_items SET amount = 101');
        self::assertTrue($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec('UPDATE purchase_invoices SET amount_to_pay = 100, rounding = 0');
        $pdo->exec('UPDATE payment_order_items SET amount = 100');
        self::assertFalse($repository->allItemsStillPayable(501, 2, 'CZK'));
        self::assertFalse($repository->allItemsStillPayable(501, 1, 'EUR'));
        $pdo->exec('INSERT INTO payment_matches VALUES (1, 101, 20)');
        self::assertFalse($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec('UPDATE payment_order_items SET amount = 80');
        self::assertTrue($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec("INSERT INTO invoice_settlements VALUES (1, 1, 'purchase_invoice', 101, 'confirmed', 10)");
        self::assertFalse($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec('UPDATE payment_order_items SET amount = 70');
        self::assertTrue($repository->allItemsStillPayable(501, 1, 'CZK'));
        $pdo->exec("UPDATE purchase_invoices SET document_kind = 'tax_document'");
        self::assertFalse($repository->allItemsStillPayable(501, 1, 'CZK'));
    }
}
