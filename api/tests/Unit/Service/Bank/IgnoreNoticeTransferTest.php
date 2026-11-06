<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\IgnoreNoticeTransfer;
use MyInvoice\Service\Bank\IgnoreNoticeConfirmationRequired;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class IgnoreNoticeTransferTest extends TestCase
{
    private PDO $pdo;
    private StatementImporter $importer;
    private StatementMatcher $matcher;
    private array $parsed;

    protected function setUp(): void
    {
        // SQLite executes production SQL except MariaDB's row-lock suffix.
        $this->pdo = new class extends PDO {
            public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
            public function prepare(string $query, array $options = []): PDOStatement|false {
                return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
            }
        };
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INTEGER, account_number TEXT, iban TEXT, bank_code TEXT, code TEXT)');
        $this->pdo->exec("INSERT INTO currencies VALUES (1, 1, '1000000005', NULL, '0100', 'CZK')");
        $this->pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER DEFAULT 1, period_kind TEXT DEFAULT \'period\', source TEXT, file_name TEXT, file_hash TEXT UNIQUE,
            file_content TEXT, pdf_content TEXT, pdf_name TEXT, pdf_hash TEXT, pdf_size_bytes INTEGER, pdf_uploaded_at TEXT,
            account_number TEXT, bank_code TEXT, currency TEXT, statement_number TEXT, statement_date TEXT,
            prev_balance REAL, curr_balance REAL, credit_total REAL, debit_total REAL, transaction_count INTEGER, matched_count INTEGER DEFAULT 0, imported_by INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, statement_id INTEGER, source TEXT DEFAULT \'statement\', posted_at TEXT,
            amount REAL, currency TEXT, original_amount REAL, original_currency TEXT, variable_symbol TEXT, constant_symbol TEXT, specific_symbol TEXT, counterparty_account TEXT,
            counterparty_bank TEXT, counterparty_name TEXT, description TEXT, bank_ref TEXT, match_status TEXT DEFAULT \'unmatched\',
            matched_invoice_id INTEGER, ignore_note TEXT, ignore_origin TEXT, card_last4 TEXT, import_fingerprint TEXT UNIQUE, portable_fingerprint TEXT,
            processing_pending_at TEXT, processing_error TEXT)');
        $this->pdo->exec('CREATE TABLE bank_email_processed_messages (bank_transaction_id INTEGER, supplier_id INTEGER)');
        $this->pdo->exec('CREATE TABLE invoice_payments (bank_transaction_id INTEGER)');
        $this->pdo->exec('CREATE TABLE payment_matches (bank_transaction_id INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_notice_ignore_transfers (notice_transaction_id INTEGER PRIMARY KEY, statement_transaction_id INTEGER UNIQUE, supplier_id INTEGER, user_id INTEGER, ignore_note TEXT)');
        $this->pdo->exec("INSERT INTO bank_statements (id, source, account_number, bank_code, currency) VALUES (1, 'email_notice', '1000000005', '0100', 'CZK')");
        $this->pdo->exec("INSERT INTO bank_transactions (id, statement_id, source, posted_at, amount, currency, variable_symbol, match_status, ignore_origin, ignore_note)
            VALUES (1, 1, 'email_notice', '2099-06-15', -100, 'CZK', '123', 'ignored', 'manual', 'Synthetic test note')");
        $this->pdo->exec('INSERT INTO bank_email_processed_messages VALUES (1, 1)');
        $this->pdo->exec('CREATE TABLE supplier_bank_accounts (supplier_id INTEGER, account_number TEXT, iban TEXT, bank_code TEXT, is_active INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_transaction_imports (statement_id INTEGER, bank_transaction_id INTEGER, import_fingerprint TEXT, supplier_id INTEGER, original_statement_id INTEGER, PRIMARY KEY (statement_id, bank_transaction_id))');
        $this->pdo->exec('CREATE TABLE bank_api_months (supplier_id INTEGER, account_key TEXT, currency TEXT, month_start TEXT, statement_id INTEGER UNIQUE, PRIMARY KEY (supplier_id, account_key, currency, month_start))');
        $this->pdo->exec('CREATE TABLE bank_api_evidence_months (supplier_id INTEGER, evidence_statement_id INTEGER, monthly_statement_id INTEGER, PRIMARY KEY (evidence_statement_id, monthly_statement_id))');
        $this->pdo->exec('CREATE TABLE bank_connections (supplier_id INTEGER, currency_id INTEGER, verified_account_number TEXT, verified_bank_code TEXT)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $this->matcher = $this->createMock(StatementMatcher::class);
        $reconciler = $this->createStub(EmailNoticeReconciler::class);
        $reconciler->method('takeOverFromEmailNotice')->willReturn(null);
        $this->importer = new StatementImporter($db, new GpcParser(), $this->matcher, $reconciler);
        $this->parsed = [
            'header' => ['account_number' => '1000000005', 'statement_number' => '1', 'statement_date' => '2099-06-16', 'prev_balance' => 1000, 'curr_balance' => 900, 'credit_total' => 0, 'debit_total' => 100],
            'transactions' => [['posted_at' => '2099-06-16', 'amount' => -100, 'currency' => 'CZK', 'variable_symbol' => '123', 'constant_symbol' => null, 'specific_symbol' => null,
                'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_name' => 'Synthetic party', 'description' => 'Test', 'bank_ref' => null]],
        ];
    }

    private function upload(?array $decision = [], string $bytes = 'synthetic-pdf'): array
    {
        return $this->importer->importParsedPdf($this->parsed, $bytes, 'synthetic.pdf', 1, 1, 1, $decision);
    }

    private function preview(): array
    {
        try { $this->upload(); self::fail('Expected preview without persisting the statement'); }
        catch (IgnoreNoticeConfirmationRequired $e) { return $e->preview; }
    }

    public function testPreviewConfirmAndDuplicate(): void
    {
        $this->matcher->expects(self::never())->method('match');
        $preview = $this->preview();
        self::assertCount(1, $preview['candidates']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
        $result = $this->upload(['fingerprint' => $preview['fingerprint'], 'selected' => [0]]);
        self::assertSame(1, $result['ignored_transferred']);
        self::assertSame(0, $result['matched']);
        $target = $this->pdo->query('SELECT * FROM bank_transactions WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ignored', $target['match_status']);
        self::assertSame('notice_transfer', $target['ignore_origin']);
        self::assertSame('Synthetic test note', $target['ignore_note']);
        self::assertSame('ignored', $this->pdo->query('SELECT match_status FROM bank_transactions WHERE id = 1')->fetchColumn());
        self::assertTrue($this->upload()['duplicate']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_notice_ignore_transfers')->fetchColumn());
    }

    public function testSkipAndNoninteractiveImportDoNotTransfer(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        self::assertSame(0, $this->upload(['skip' => true])['ignored_transferred']);
        self::assertSame(0, $this->upload(null, 'scan-import')['ignored_transferred']);
    }

    public function testChangedNoteRequiresFreshConfirmationWithoutWrites(): void
    {
        $this->matcher->expects(self::never())->method('match');
        $preview = $this->preview();
        $this->pdo->exec("UPDATE bank_transactions SET ignore_note = 'Changed note' WHERE id = 1");
        try { $this->upload(['fingerprint' => $preview['fingerprint'], 'selected' => [0]]); self::fail('Stale confirmation'); }
        catch (IgnoreNoticeConfirmationRequired $e) { self::assertNotSame($preview['fingerprint'], $e->preview['fingerprint']); }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
    }

    public function testConsumedNoticeCannotBeReusedAfterUndoOrDeletion(): void
    {
        $preview = $this->preview();
        $this->upload(['fingerprint' => $preview['fingerprint'], 'selected' => [0]]);
        $this->pdo->exec("UPDATE bank_transactions SET match_status = 'unmatched' WHERE id = 2");
        $this->pdo->exec('DELETE FROM bank_transactions WHERE id = 2');
        $this->pdo->exec('DELETE FROM bank_statements WHERE id = 2');
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        self::assertSame(0, $this->upload()['ignored_transferred']);
    }

    public function testRollbackOnInvalidSelection(): void
    {
        $this->matcher->expects(self::never())->method('match');
        $preview = $this->preview();
        try { $this->upload(['fingerprint' => $preview['fingerprint'], 'selected' => [99]]); self::fail('Invalid selection'); }
        catch (\InvalidArgumentException) {}
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
    }

    public function testForeignSupplierSystemIgnoreAndPaymentLinksAreExcluded(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $this->pdo->exec('UPDATE bank_email_processed_messages SET supplier_id = 2');
        self::assertSame(0, $this->upload([], 'foreign')['ignored_transferred']);
        $this->pdo->exec('UPDATE bank_email_processed_messages SET supplier_id = 1');
        $this->pdo->exec("UPDATE bank_transactions SET ignore_origin = 'system' WHERE id = 1");
        self::assertSame(0, $this->upload([], 'system')['ignored_transferred']);
        $this->pdo->exec("UPDATE bank_transactions SET ignore_origin = 'manual' WHERE id = 1");
        $this->pdo->exec('INSERT INTO invoice_payments VALUES (1)');
        self::assertSame(0, $this->upload([], 'linked')['ignored_transferred']);
    }

    public function testAmbiguousRowsNotOfferedAndCardWithoutIdentityExcluded(): void
    {
        $this->parsed['transactions'][] = $this->parsed['transactions'][0];
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        self::assertSame(0, $this->upload([], 'ambiguous')['ignored_transferred']);
        array_pop($this->parsed['transactions']);
        $this->parsed['transactions'][0]['variable_symbol'] = null;
        $this->pdo->exec('UPDATE bank_transactions SET variable_symbol = NULL WHERE id = 1');
        self::assertSame(0, $this->upload([], 'card')['ignored_transferred']);
    }

    public function testOverlappingStatementDoesNotOfferIgnoreForExistingTransaction(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $first = $this->upload(['skip' => true]);
        $second = $this->upload([], 'overlapping-statement');
        self::assertSame(0, $second['ignored_transferred']);
        self::assertSame(0, $second['transactions']);
        self::assertSame(1, $second['skipped_duplicates']);
        self::assertSame('unmatched', $this->pdo->query('SELECT match_status FROM bank_transactions WHERE statement_id = ' . $first['statement_id'])->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_notice_ignore_transfers')->fetchColumn());
    }

    public function testDailyPdfKeepsIgnoredStateInMonthlyProjection(): void
    {
        $this->matcher->expects(self::never())->method('matchBatch');
        $this->parsed['header']['period_kind'] = 'day';
        $preview = $this->preview();
        $result = $this->upload(['fingerprint' => $preview['fingerprint'], 'selected' => [0]]);
        self::assertSame(1, $result['ignored_transferred']);
        self::assertArrayHasKey('evidence_statement_id', $result);
        self::assertNotSame($result['statement_id'], $result['evidence_statement_id']);
        self::assertSame('ignored', $this->pdo->query('SELECT match_status FROM bank_transactions WHERE statement_id = ' . $result['evidence_statement_id'])->fetchColumn());
    }

    /**
     * Ruční upload bez rozhodnutí posílá prázdné pole, takže každé PDF jde scoped
     * cestou. Výpis za období se do měsíčního výpisu neskládá; na účtu, který už
     * skládá (feed nebo denní PDF), musí zůstat samostatným dokladem s vlastním id.
     */
    public function testPeriodPdfOnAggregatedAccountKeepsOwnStatement(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $this->pdo->exec("INSERT INTO bank_statements (source, period_kind, file_hash, account_number, bank_code, currency, statement_date)
            VALUES ('pdf', 'day', 'daily-evidence', '1000000005', '0100', 'CZK', '2099-05-31')");
        $this->pdo->exec("UPDATE bank_transactions SET match_status = 'unmatched', ignore_origin = NULL WHERE id = 1");

        $result = $this->upload([], 'period-pdf');

        self::assertIsInt($result['statement_id']);
        self::assertArrayNotHasKey('evidence_statement_id', $result);
        $statement = $this->pdo->query('SELECT source, period_kind FROM bank_statements WHERE id = ' . $result['statement_id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['source' => 'pdf', 'period_kind' => 'period'], $statement);
    }

    public function testWrongBankCurrencySignDateOrSymbolDoNotMatch(): void
    {
        $this->matcher->expects(self::never())->method('match');
        $account = $this->pdo->query('SELECT * FROM currencies')->fetch(PDO::FETCH_ASSOC);
        $notice = $this->pdo->query("SELECT bt.*, bs.account_number AS own_account, bs.bank_code AS own_bank, bs.currency AS statement_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id")->fetch(PDO::FETCH_ASSOC);
        foreach (['own_bank' => '0800', 'currency' => 'EUR', 'amount' => 100, 'posted_at' => '2099-06-01', 'variable_symbol' => '456'] as $key => $value) {
            self::assertSame([], IgnoreNoticeTransfer::candidates($this->parsed['transactions'], [array_replace($notice, [$key => $value])], $account), $key);
        }
        self::assertSame([], IgnoreNoticeTransfer::candidates($this->parsed['transactions'], [$notice, array_replace($notice, ['id' => 9])], $account));
    }
}
