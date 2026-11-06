<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Neúspěšný claim pohybu v `doMatchCurrent` (pohyb mezitím spároval někdo jiný)
 * smí vrátit jen savepoint párování, ne transakci volajícího (import výpisu).
 *
 * Snímek řádku předaný do `doMatchCurrent` hlásí volný pohyb, v DB je už
 * spárovaný — tak se claim rozejde se snímkem. Přímé `$pdo->rollBack()` dřív
 * odvolalo celou vnější transakci včetně dat volajícího.
 */
#[Group('integration')]
final class StatementMatcherClaimRollbackTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->matcher = $c->get(StatementMatcher::class);
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** @return iterable<string, array{float}> */
    public static function amounts(): iterable
    {
        yield 'exact' => [1210.00];
        yield 'partial' => [500.00];
    }

    #[DataProvider('amounts')]
    public function testFailedClaimKeepsCallerTransaction(float $txAmount): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $vs = '8880000731';
        $account = '8880000731';
        $pdo->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, 'CZK', 'TEST CZK claim', 'Kč', 'koruna', 'koruna', 2, 0, 0, ?, '0100')"
        )->execute([$this->supplierId, $account]);
        $currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             SELECT ?, 'TEST Klient claim', 'Ulice 1', 'Praha', '11000', country_id, '', ? FROM supplier WHERE id = ?"
        )->execute([$this->supplierId, $currencyId, $this->supplierId]);
        $clientId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat)
             VALUES ('invoice', ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, 'issued', 1000, 1210)"
        )->execute([$vs, $clientId, $this->supplierId, $currencyId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, 'TEST-claim.gpc', ?, ?, '0100', 'CZK', CURDATE())"
        )->execute([$this->supplierId, hash('sha256', 'test-claim:' . $txAmount), $account]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, CURDATE(), ?, 'CZK', ?, 'manual')"
        )->execute([$statementId, $txAmount, $vs]);
        $txId = (int) $pdo->lastInsertId();

        $row = $pdo->query(
            "SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency, bs.supplier_id AS statement_supplier_id
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = {$txId}"
        )->fetch(PDO::FETCH_ASSOC);
        $row['match_status'] = 'unmatched';

        $doMatchCurrent = new \ReflectionMethod(StatementMatcher::class, 'doMatchCurrent');
        $result = $doMatchCurrent->invoke($this->matcher, $txId, false, $row, $this->supplierId);

        self::assertSame('transaction_not_free', $result['reason'] ?? null);
        self::assertTrue($pdo->inTransaction(), 'transakce volajícího musí zůstat otevřená');
        self::assertSame(
            $invoiceId,
            (int) $pdo->query("SELECT id FROM invoices WHERE id = {$invoiceId}")->fetchColumn(),
            'data volajícího z vnější transakce nesmí zmizet',
        );
    }

    /** Totéž v druhém průchodu odchozí platby: pohyb mezitím spotřeboval převod mezi vlastními účty. */
    public function testPurchaseAmountDateTakenElsewhereKeepsCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, 'TEST-claim-out.gpc', ?, '8880000732', '0100', 'CZK', CURDATE())"
        )->execute([$this->supplierId, hash('sha256', 'test-claim-out')]);
        $statementId = (int) $pdo->lastInsertId();
        $insertTx = $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status)
             VALUES (?, CURDATE(), ?, 'CZK', 'unmatched')"
        );
        $insertTx->execute([$statementId, -100.00]);
        $outId = (int) $pdo->lastInsertId();
        $insertTx->execute([$statementId, 100.00]);
        $inId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transfer_matches (supplier_id, out_transaction_id, in_transaction_id, amount, currency)
             VALUES (?, ?, ?, 100.00, 'CZK')"
        )->execute([$this->supplierId, $outId, $inId]);

        $fallback = new \ReflectionMethod(StatementMatcher::class, 'matchPurchaseByAmountDate');
        $result = $fallback->invoke($this->matcher, $pdo, $this->supplierId, 100.00, date('Y-m-d'), $outId, 'CZK', $statementId, '');

        self::assertSame('own_transfer_matched', $result['reason'] ?? null);
        self::assertTrue($pdo->inTransaction(), 'transakce volajícího musí zůstat otevřená');
        self::assertSame(
            $statementId,
            (int) $pdo->query("SELECT id FROM bank_statements WHERE id = {$statementId}")->fetchColumn(),
        );
    }
}
