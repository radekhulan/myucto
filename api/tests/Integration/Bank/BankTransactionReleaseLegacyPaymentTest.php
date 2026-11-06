<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uvolnění pohybu smí smazat backfill 'legacy' platbu (migrace 0108) jen tehdy,
 * když ji založilo historické párování tohoto pohybu. Pohyb spárovaný až po
 * backfillu fakturu zaplacenou dřív (import, ruční úhrada) jen navázal — smazání
 * výpisu jí dřív úhradu zrušilo a po opětovném nahrání se „zaplatila" znovu.
 */
#[Group('integration')]
final class BankTransactionReleaseLegacyPaymentTest extends TestCase
{
    private Connection $db;
    private BankTransactionReleaseService $release;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->release = $c->get(BankTransactionReleaseService::class);
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testDeletingStatementKeepsPaymentThatPredatesTheMatch(): void
    {
        [$invoiceId, $txId] = $this->paidInvoiceLinkedToTransaction(legacyAgo: '2 DAY', matchedAgo: '1 DAY');

        $this->release->release($this->supplierId, $txId, BankTransactionReleaseService::MODE_DELETE);

        self::assertSame('paid', $this->invoiceStatus($invoiceId), 'faktura zaplacená před spárováním zůstává zaplacená');
        self::assertSame(1, $this->legacyPayments($invoiceId));
    }

    public function testReleasingHistoricMatchStillRemovesItsBackfillPayment(): void
    {
        [$invoiceId, $txId] = $this->paidInvoiceLinkedToTransaction(legacyAgo: '1 DAY', matchedAgo: '2 DAY');

        $this->release->release($this->supplierId, $txId, BankTransactionReleaseService::MODE_UNMATCH);

        self::assertNotSame('paid', $this->invoiceStatus($invoiceId));
        self::assertSame(0, $this->legacyPayments($invoiceId));
    }

    /** @return array{int, int} */
    private function paidInvoiceLinkedToTransaction(string $legacyAgo, string $matchedAgo): array
    {
        $pdo = $this->db->pdo();
        $vs = '8880000733';
        $pdo->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, 'CZK', 'TEST CZK legacy', 'Kč', 'koruna', 'koruna', 2, 0, 0, '8880000733', '0100')"
        )->execute([$this->supplierId]);
        $currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             SELECT ?, 'TEST Klient legacy', 'Ulice 1', 'Praha', '11000', country_id, '', ? FROM supplier WHERE id = ?"
        )->execute([$this->supplierId, $currencyId, $this->supplierId]);
        $clientId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, paid_at, total_without_vat, total_with_vat)
             VALUES ('invoice', ?, ?, ?, CURDATE() - INTERVAL 10 DAY, CURDATE() - INTERVAL 10 DAY, CURDATE() - INTERVAL 5 DAY,
                     ?, 'paid', CURDATE() - INTERVAL 5 DAY, 1000, 1210)"
        )->execute([$vs, $clientId, $this->supplierId, $currencyId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, created_at)
             VALUES (?, ?, CURDATE() - INTERVAL 5 DAY, 1210, 'CZK', 'legacy', NOW() - INTERVAL {$legacyAgo})"
        )->execute([$this->supplierId, $invoiceId]);
        $pdo->prepare('UPDATE invoices SET paid_total = 1210 WHERE id = ?')->execute([$invoiceId]);

        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, 'TEST-legacy.gpc', ?, '8880000733', '0100', 'CZK', CURDATE())"
        )->execute([$this->supplierId, hash('sha256', 'test-legacy:' . $legacyAgo)]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, match_status, matched_invoice_id, matched_at)
             VALUES (?, CURDATE() - INTERVAL 5 DAY, 1210, 'CZK', ?, 'auto_exact', ?, NOW() - INTERVAL {$matchedAgo})"
        )->execute([$statementId, $vs, $invoiceId]);
        return [$invoiceId, (int) $pdo->lastInsertId()];
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$invoiceId}")->fetchColumn();
    }

    private function legacyPayments(int $invoiceId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$invoiceId} AND source = 'legacy'"
        )->fetchColumn();
    }
}
