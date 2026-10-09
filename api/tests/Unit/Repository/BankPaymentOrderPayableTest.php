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
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC, exchange_rate NUMERIC)');
        $pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, invoice_type TEXT, status TEXT, payment_method TEXT, amount_to_pay NUMERIC, parent_invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, currency TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER, currency TEXT)');
        $pdo->exec('CREATE TABLE cash_documents (id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, doc_type TEXT, status TEXT, total_amount NUMERIC, currency_code TEXT, amount_foreign NUMERIC)');
        $pdo->exec('CREATE TABLE offset_agreements (id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE credit_note_offsets (id INTEGER, supplier_id INTEGER, doc_type TEXT, invoice_id INTEGER, credit_note_id INTEGER, amount NUMERIC, offset_on TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK')");
        $pdo->exec("INSERT INTO purchase_invoices VALUES (101, 1, 11, 'received', 'invoice', 'bank_transfer', 100, 0, NULL)");
        $pdo->exec('INSERT INTO payment_order_items VALUES (501, 101, 100, NULL)');
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
        $pdo->exec('INSERT INTO payment_matches VALUES (1, 101, 20, 901)');
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

    public function testRefundItemIsPayableOnlyWhileDocumentStaysOpenForRefund(): void
    {
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->createFunction('GREATEST', static fn (...$values) => max($values));
        $pdo->exec('CREATE TABLE currencies (id INTEGER, supplier_id INTEGER, code TEXT)');
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC, exchange_rate NUMERIC)');
        $pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, invoice_type TEXT, status TEXT, payment_method TEXT, amount_to_pay NUMERIC, parent_invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, currency TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER, currency TEXT)');
        $pdo->exec('CREATE TABLE cash_documents (id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, doc_type TEXT, status TEXT, total_amount NUMERIC, currency_code TEXT, amount_foreign NUMERIC)');
        $pdo->exec('CREATE TABLE offset_agreements (id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE credit_note_offsets (id INTEGER, supplier_id INTEGER, doc_type TEXT, invoice_id INTEGER, credit_note_id INTEGER, amount NUMERIC, offset_on TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK')");
        $pdo->exec("INSERT INTO invoices VALUES (301, 1, 11, 'invoice', 'issued', 'bank_transfer', -608, NULL)");
        $pdo->exec('INSERT INTO payment_order_items VALUES (601, NULL, 608, 301)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $repository = new PaymentOrderRepository($db);

        self::assertTrue($repository->allItemsStillPayable(601, 1, 'CZK'));
        self::assertFalse($repository->allItemsStillPayable(601, 2, 'CZK'));
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'EUR'));
        $pdo->exec('UPDATE payment_order_items SET amount = 609');
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'CZK'));
        $pdo->exec('UPDATE payment_order_items SET amount = 608');
        $pdo->exec("UPDATE invoices SET status = 'paid'");
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'CZK'), 'Vyplacený doklad už do banky nesmí.');
        $pdo->exec("UPDATE invoices SET status = 'sent', payment_method = 'cash'");
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'CZK'), 'Hotovostní vratka nejde převodem.');
        $pdo->exec("UPDATE invoices SET payment_method = 'bank_transfer', amount_to_pay = 608");
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'CZK'), 'Kladná částka je pohledávka, ne vratka.');
        $pdo->exec("UPDATE invoices SET amount_to_pay = -608, invoice_type = 'proforma'");
        self::assertFalse($repository->allItemsStillPayable(601, 1, 'CZK'));
        $pdo->exec("UPDATE invoices SET invoice_type = 'credit_note'");
        self::assertTrue($repository->allItemsStillPayable(601, 1, 'CZK'));
    }
}
