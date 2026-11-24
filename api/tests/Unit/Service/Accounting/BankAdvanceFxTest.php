<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class BankAdvanceFxTest extends TestCase
{
    #[TestWith(['incomingFxSaldo', '311', '324.123'])]
    #[TestWith(['outgoingFxSaldo', '321', '314.123'])]
    public function testUsesAdvancePostingRuleAndPaymentRateInsteadOfDocumentRate(string $method, string $saldo, string $advance): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE currencies (id INT, code TEXT);
            INSERT INTO currencies VALUES (1, 'EUR');
            CREATE TABLE invoices (id INT, supplier_id INT, invoice_type TEXT, status TEXT, exchange_rate DECIMAL,
                amount_to_pay DECIMAL, paid_total DECIMAL, currency_id INT);
            INSERT INTO invoices VALUES (10, 1, 'proforma', 'paid', 24, 100, 100, 1);
            CREATE TABLE purchase_invoices (id INT, supplier_id INT, document_kind TEXT, status TEXT, exchange_rate DECIMAL,
                amount_to_pay DECIMAL, parent_purchase_invoice_id INT, currency_id INT);
            INSERT INTO purchase_invoices VALUES (10, 1, 'advance', 'paid', 24, 100, NULL, 1);
            CREATE TABLE posting_rules (id INT, supplier_id INT, rule_key TEXT, description TEXT,
                debit_account_code TEXT, credit_account_code TEXT, priority INT, is_active INT);
            INSERT INTO posting_rules VALUES
                (1, 1, 'advance.received.collection', 'Přijatá záloha', '221', '324.123', 100, 1),
                (2, 1, 'advance.paid.payment', 'Poskytnutá záloha', '314.123', '221', 100, 1)");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $bank = (new \ReflectionClass(BankPostingService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($bank, 'db'))->setValue($bank, $db);
        (new \ReflectionProperty($bank, 'postingRules'))->setValue($bank, new PostingRuleRepository($db));

        self::assertSame([$advance, 26.0, true], (new \ReflectionMethod($bank, $method))->invoke($bank, 1, 10, $saldo, 'EUR', 26.0));
    }

    public function testReadsHistoricalCzkOnlyForTheRequestedPostedAllocation(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE invoice_payments (id INT, supplier_id INT, invoice_id INT, bank_transaction_id INT, amount DECIMAL, currency TEXT);
            INSERT INTO invoice_payments VALUES (1, 1, 10, 100, 50, 'EUR'), (2, 1, 11, 100, 100, 'EUR'), (3, 1, 10, 200, 50, 'EUR');
            CREATE TABLE journal_entries (id INT, supplier_id INT, source_type TEXT, source_id INT, reversed_by INT, posted_at TEXT);
            INSERT INTO journal_entries VALUES (8, 1, 'bank', 100, NULL, '2099-06-15'), (9, 1, 'bank', 200, 10, '2099-06-16');
            CREATE TABLE chart_of_accounts (id INT, supplier_id INT, account_code TEXT);
            INSERT INTO chart_of_accounts VALUES (22, 1, '221.003'), (32, 1, '324');
            CREATE TABLE journal_entry_lines
                (entry_id INT, supplier_id INT, account_id INT, side TEXT, amount DECIMAL, amount_foreign DECIMAL, currency_code TEXT, is_red_storno INT);
            INSERT INTO journal_entry_lines VALUES
                (8, 1, 22, 'debit', 3900, 150, 'EUR', 0),
                (8, 1, 32, 'credit', 1300, 50, 'EUR', 0),
                (8, 1, 32, 'credit', 2600, 100, 'EUR', 0),
                (9, 1, 32, 'credit', 1200, 50, 'EUR', 0)");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $evidence = new AdvanceSettlementEvidenceReader($db);

        self::assertSame(['foreign' => 50.0, 'czk' => 1300.0], $evidence->tracedBankAdvance(1, 'sale', 10, 'EUR', '324'));
        self::assertSame(['foreign' => 0.0, 'czk' => 0.0], $evidence->tracedBankAdvance(2, 'sale', 10, 'EUR', '324'));
    }
}
