<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Skutečné SQL nad izolovanými daty; MariaDB regex funkce emuluje SQLite. */
final class StatementMatcherPurchasePaymentTest extends TestCase
{
    private \Pdo\Sqlite $pdo;
    private StatementMatcher $matcher;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->createFunction('regexp', static fn ($pattern, $value) => preg_match('~' . $pattern . '~', (string) $value));
        $this->pdo->createFunction('REGEXP_REPLACE', static fn ($value, $pattern, $replacement) => preg_replace('~' . $pattern . '~', $replacement, (string) $value));
        $this->pdo->createFunction('NOW', static fn () => '2099-06-15 12:00:00');
        $this->pdo->exec("CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT);
            INSERT INTO currencies VALUES (1, 'CZK');
            CREATE TABLE purchase_invoices (
                id INTEGER PRIMARY KEY, supplier_id INTEGER DEFAULT 1,
                varsymbol TEXT DEFAULT 'PF-2099-001', vendor_invoice_number TEXT DEFAULT 'FV-2099-002',
                payment_variable_symbol TEXT, currency_id INTEGER DEFAULT 1,
                total_with_vat REAL DEFAULT 2500, advance_paid_amount REAL DEFAULT 0,
                amount_to_pay REAL GENERATED ALWAYS AS (total_with_vat - advance_paid_amount) STORED,
                rounding REAL DEFAULT 0, exchange_rate REAL, status TEXT DEFAULT 'received',
                document_kind TEXT DEFAULT 'invoice', paid_at TEXT);
            CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, match_status TEXT DEFAULT 'unmatched', matched_at TEXT);
            INSERT INTO bank_transactions (id) VALUES (1);
            CREATE TABLE payment_matches (id INTEGER PRIMARY KEY, invoice_id INTEGER, supplier_id INTEGER, bank_transaction_id INTEGER,
                purchase_invoice_id INTEGER, amount REAL, match_type TEXT, match_confidence INTEGER, matched_by_user_id INTEGER);
            CREATE TABLE offset_agreement_items (supplier_id INTEGER, agreement_id INTEGER, doc_type TEXT, doc_id INTEGER, amount REAL);
            CREATE TABLE offset_agreements (id INTEGER, status TEXT);
            CREATE TABLE invoice_settlements (id INTEGER, supplier_id INTEGER, doc_type TEXT, doc_id INTEGER, status TEXT, amount REAL);");
        $this->matcher = new StatementMatcher(
            $this->createStub(Connection::class),
            $this->createStub(FinalFromProformaCreator::class),
        );
    }

    public static function payments(): array
    {
        return [
            'samostatný platební VS' => ['2099000261', '2099000261', 0.0, 0.0, 0.0],
            'úvodní nuly' => ['0020990261', '20990261', 0.0, 0.0, 0.0],
            'zaokrouhlení dolů' => ['2099000261', '2099000261', -0.24, 0.0, 0.0],
            'zaokrouhlení nahoru' => ['2099000261', '2099000261', 0.36, 0.0, 0.0],
            'záloha a dřívější úhrada' => ['2099000261', '2099000261', -0.24, 500.0, 300.0],
            'původní interní číslo' => [null, 'PF-2099-001', -0.24, 0.0, 0.0],
            'původní dodavatelské číslo' => [null, 'FV-2099-002', 0.36, 0.0, 0.0],
        ];
    }

    #[DataProvider('payments')]
    public function testExactPayment(?string $paymentVs, string $bankVs, float $rounding, float $advance, float $settled): void
    {
        $this->pdo->prepare('INSERT INTO purchase_invoices (id, payment_variable_symbol, rounding, advance_paid_amount) VALUES (1, ?, ?, ?)')
            ->execute([$paymentVs, $rounding, $advance]);
        if ($settled > 0) {
            $this->pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount) VALUES (1, 2, 1, ?)')
                ->execute([$settled]);
        }
        $amount = round(2500 + $rounding - $advance - $settled, 2);
        $result = $this->match($bankVs, $amount);
        self::assertSame('auto_exact', $result['status']);
        self::assertSame(1, $result['purchase_invoice_id']);
        self::assertSame('paid', $this->pdo->query('SELECT status FROM purchase_invoices WHERE id = 1')->fetchColumn());
        self::assertEqualsWithDelta($amount, (float) $this->pdo->query('SELECT amount FROM payment_matches WHERE bank_transaction_id = 1')->fetchColumn(), 0.001);
    }

    public function testLiteralPaymentVsWinsOverNormalizedDocumentNumber(): void
    {
        $this->pdo->exec("INSERT INTO purchase_invoices (id, varsymbol) VALUES (1, 'PF-2099-0261');
            INSERT INTO purchase_invoices (id, payment_variable_symbol) VALUES (2, '20990261');");
        $result = $this->match('20990261', 2500);
        self::assertSame('auto_exact', $result['status']);
        self::assertSame(2, $result['purchase_invoice_id']);
        self::assertSame('received', $this->pdo->query('SELECT status FROM purchase_invoices WHERE id = 1')->fetchColumn());
    }

    public function testRepeatedPaymentVsRemainsAmbiguous(): void
    {
        $this->pdo->exec("INSERT INTO purchase_invoices (id, payment_variable_symbol) VALUES (1, '20990261'), (2, '20990261');");
        $result = $this->match('20990261', 2500);
        self::assertSame('ambiguous_vs_purchase', $result['reason']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_matches')->fetchColumn());
    }

    public function testAlreadyPaidInvoiceRequiresReview(): void
    {
        $this->pdo->exec("INSERT INTO purchase_invoices (id, payment_variable_symbol, status, rounding) VALUES (1, '20990261', 'paid', -0.24);");
        $result = $this->match('20990261', 2499.76);
        self::assertSame('already_paid_verify', $result['reason']);
        self::assertTrue($result['requires_review']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_matches')->fetchColumn());
    }

    public function testUnroundedPaymentRemainsPartial(): void
    {
        $this->pdo->exec("INSERT INTO purchase_invoices (id, payment_variable_symbol, rounding) VALUES (1, '20990261', 0.36);");
        $result = $this->match('20990261', 2500);
        self::assertSame('auto_partial', $result['status']);
        self::assertSame('received', $this->pdo->query('SELECT status FROM purchase_invoices WHERE id = 1')->fetchColumn());
    }

    public function testCreditRefundUsesPaymentVsAndRounding(): void
    {
        $this->pdo->exec("INSERT INTO purchase_invoices (id, payment_variable_symbol, total_with_vat, rounding, document_kind)
            VALUES (1, '20990261', -2500.24, 0.24, 'credit_note');");
        $result = $this->match('20990261', 2500, 'matchPurchaseCreditRefund');
        self::assertSame('auto_exact', $result['status']);
        self::assertSame(1, $result['purchase_invoice_id']);
        self::assertSame('paid', $this->pdo->query('SELECT status FROM purchase_invoices WHERE id = 1')->fetchColumn());
    }

    private function match(string $vs, float $amount, string $method = 'matchPurchase'): array
    {
        return (new \ReflectionMethod($this->matcher, $method))->invoke(
            $this->matcher, $this->pdo, 1, $vs, $amount, '2099-06-15', 1, 'CZK',
        );
    }
}
