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
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC, exchange_rate NUMERIC, settled_outside_amount NUMERIC DEFAULT 0)');
        $pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, invoice_id INTEGER, tax_submission_id INTEGER, tax_advance_schedule_id INTEGER, other_item_id INTEGER, other_item_installment_id INTEGER)');
        $pdo->exec('CREATE TABLE other_items (id INTEGER, supplier_id INTEGER, side TEXT, status TEXT, currency TEXT, amount NUMERIC, settled_outside_amount NUMERIC DEFAULT 0, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE other_item_allocations (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, installment_id INTEGER, amount NUMERIC, payment_on TEXT, reversed_on TEXT)');
        $pdo->exec('CREATE TABLE other_item_installments (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, position INTEGER, due_on TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, invoice_type TEXT, status TEXT, payment_method TEXT, amount_to_pay NUMERIC, parent_invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, currency TEXT, amount NUMERIC, card_last4 TEXT)');
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER, currency TEXT)');
        $pdo->exec('CREATE TABLE cash_documents (id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, doc_type TEXT, status TEXT, total_amount NUMERIC, currency_code TEXT, amount_foreign NUMERIC)');
        $pdo->exec('CREATE TABLE offset_agreements (id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE credit_note_offsets (id INTEGER, supplier_id INTEGER, doc_type TEXT, invoice_id INTEGER, credit_note_id INTEGER, amount NUMERIC, offset_on TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK')");
        $pdo->exec("INSERT INTO purchase_invoices (id, supplier_id, currency_id, status, document_kind, payment_method, amount_to_pay, rounding, exchange_rate) VALUES (101, 1, 11, 'received', 'invoice', 'bank_transfer', 100, 0, NULL)");
        $pdo->exec('INSERT INTO payment_order_items (payment_order_id, purchase_invoice_id, amount, invoice_id) VALUES (501, 101, 100, NULL)');
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
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC, exchange_rate NUMERIC, settled_outside_amount NUMERIC DEFAULT 0)');
        $pdo->exec('CREATE TABLE payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, invoice_id INTEGER, tax_submission_id INTEGER, tax_advance_schedule_id INTEGER, other_item_id INTEGER, other_item_installment_id INTEGER)');
        $pdo->exec('CREATE TABLE other_items (id INTEGER, supplier_id INTEGER, side TEXT, status TEXT, currency TEXT, amount NUMERIC, settled_outside_amount NUMERIC DEFAULT 0, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE other_item_allocations (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, installment_id INTEGER, amount NUMERIC, payment_on TEXT, reversed_on TEXT)');
        $pdo->exec('CREATE TABLE other_item_installments (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, position INTEGER, due_on TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, invoice_type TEXT, status TEXT, payment_method TEXT, amount_to_pay NUMERIC, parent_invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, bank_transaction_id INTEGER)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, currency TEXT, amount NUMERIC, card_last4 TEXT)');
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER, currency TEXT)');
        $pdo->exec('CREATE TABLE cash_documents (id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, doc_type TEXT, status TEXT, total_amount NUMERIC, currency_code TEXT, amount_foreign NUMERIC)');
        $pdo->exec('CREATE TABLE offset_agreements (id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)');
        $pdo->exec('CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)');
        $pdo->exec('CREATE TABLE credit_note_offsets (id INTEGER, supplier_id INTEGER, doc_type TEXT, invoice_id INTEGER, credit_note_id INTEGER, amount NUMERIC, offset_on TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK')");
        $pdo->exec("INSERT INTO invoices VALUES (301, 1, 11, 'invoice', 'issued', 'bank_transfer', -608, NULL)");
        $pdo->exec('INSERT INTO payment_order_items (payment_order_id, purchase_invoice_id, amount, invoice_id) VALUES (601, NULL, 608, 301)');
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

    public function testOtherPayableItemIsPayableOnlyWhileItsInstallmentStaysOpen(): void
    {
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->createFunction('GREATEST', static fn (...$values) => max($values));
        foreach ([
            'currencies (id INTEGER, supplier_id INTEGER, code TEXT)',
            'purchase_invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, status TEXT, document_kind TEXT, payment_method TEXT, amount_to_pay NUMERIC, rounding NUMERIC, exchange_rate NUMERIC, settled_outside_amount NUMERIC DEFAULT 0)',
            'payment_order_items (payment_order_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, invoice_id INTEGER, tax_submission_id INTEGER, tax_advance_schedule_id INTEGER, other_item_id INTEGER, other_item_installment_id INTEGER)',
            'other_items (id INTEGER, supplier_id INTEGER, side TEXT, status TEXT, currency TEXT, amount NUMERIC, settled_outside_amount NUMERIC DEFAULT 0, deleted_at TEXT)',
            'other_item_allocations (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, installment_id INTEGER, amount NUMERIC, payment_on TEXT, reversed_on TEXT)',
            'other_item_installments (id INTEGER, supplier_id INTEGER, other_item_id INTEGER, position INTEGER, due_on TEXT, amount NUMERIC)',
            'invoices (id INTEGER, supplier_id INTEGER, currency_id INTEGER, invoice_type TEXT, status TEXT, payment_method TEXT, amount_to_pay NUMERIC, parent_invoice_id INTEGER)',
            'payment_matches (supplier_id INTEGER, purchase_invoice_id INTEGER, amount NUMERIC, bank_transaction_id INTEGER)',
            'bank_transactions (id INTEGER, statement_id INTEGER, currency TEXT, amount NUMERIC, card_last4 TEXT)',
            'bank_statements (id INTEGER, currency TEXT)',
            'cash_documents (id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, doc_type TEXT, status TEXT, total_amount NUMERIC, currency_code TEXT, amount_foreign NUMERIC)',
            'offset_agreements (id INTEGER, status TEXT)',
            'offset_agreement_items (agreement_id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, amount NUMERIC)',
            'invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount NUMERIC)',
            'credit_note_offsets (id INTEGER, supplier_id INTEGER, doc_type TEXT, invoice_id INTEGER, credit_note_id INTEGER, amount NUMERIC, offset_on TEXT)',
        ] as $table) {
            $pdo->exec('CREATE TABLE ' . $table);
        }
        $pdo->exec("INSERT INTO other_items VALUES (701, 1, 'payable', 'posted', 'CZK', 3000, 0, NULL)");
        $pdo->exec("INSERT INTO other_item_installments VALUES (81, 1, 701, 1, '2099-01-31', 1000), (82, 1, 701, 2, '2099-02-28', 1000), (83, 1, 701, 3, '2099-03-31', 1000)");
        $pdo->exec('INSERT INTO payment_order_items (payment_order_id, amount, other_item_id, other_item_installment_id) VALUES (801, 1000, 701, 81)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $repository = new PaymentOrderRepository($db);

        self::assertTrue($repository->allItemsStillPayable(801, 1, 'CZK'));
        self::assertFalse($repository->allItemsStillPayable(801, 2, 'CZK'), 'Závazek jiné firmy do banky nesmí.');
        self::assertFalse($repository->allItemsStillPayable(801, 1, 'EUR'));

        // Splátku 1 uhradila jiná platba: zbytek položky (2000) by platbu pokryl, splátka už ne.
        $pdo->exec("INSERT INTO other_item_allocations VALUES (1, 1, 701, 81, 1000, '2099-01-30', NULL)");
        self::assertFalse($repository->allItemsStillPayable(801, 1, 'CZK'), 'Uhrazená splátka se podruhé neplatí.');
        $pdo->exec("UPDATE other_item_allocations SET reversed_on = '2099-01-31'");
        self::assertTrue($repository->allItemsStillPayable(801, 1, 'CZK'), 'Stornovaná úhrada splátku zase otevře.');

        $pdo->exec("UPDATE other_items SET status = 'draft'");
        self::assertFalse($repository->allItemsStillPayable(801, 1, 'CZK'), 'Koncept se neplatí.');
        $pdo->exec("UPDATE other_items SET status = 'confirmed', side = 'receivable'");
        self::assertFalse($repository->allItemsStillPayable(801, 1, 'CZK'), 'Pohledávka se neplatí.');

        // Bez splátky hradí položka celý zbytek.
        $pdo->exec("UPDATE other_items SET side = 'payable', settled_outside_amount = 2500");
        $pdo->exec('UPDATE payment_order_items SET other_item_installment_id = NULL, amount = 600');
        self::assertFalse($repository->allItemsStillPayable(801, 1, 'CZK'));
        $pdo->exec('UPDATE payment_order_items SET amount = 500');
        self::assertTrue($repository->allItemsStillPayable(801, 1, 'CZK'));
    }
}
