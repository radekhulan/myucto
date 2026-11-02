<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Connector\KbPlusKmStatementParser;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConnectedStatementImporterTest extends TestCase
{
    private PDO $pdo;
    private StatementImporter $importer;
    private StatementMatcher $matcher;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INTEGER, account_number TEXT, iban TEXT, bank_code TEXT, code TEXT, is_active INTEGER)');
        $this->pdo->exec("INSERT INTO currencies VALUES (1, 10, '1000000005', NULL, '2010', 'EUR', 1)");
        $this->pdo->exec('CREATE TABLE supplier_bank_accounts (supplier_id INTEGER, account_number TEXT, iban TEXT, bank_code TEXT, is_active INTEGER)');
        $this->pdo->exec('CREATE TABLE bank_statements (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, period_kind TEXT DEFAULT \'period\',
            file_name TEXT, file_hash TEXT, file_content BLOB,
            pdf_content BLOB, pdf_name TEXT, pdf_hash TEXT, pdf_size_bytes INTEGER, pdf_uploaded_at TEXT,
            supplier_id INTEGER, account_number TEXT, bank_code TEXT, currency TEXT, statement_number TEXT,
            statement_date TEXT, prev_balance NUMERIC, curr_balance NUMERIC, credit_total NUMERIC,
            debit_total NUMERIC, transaction_count INTEGER, imported_by INTEGER, matched_count INTEGER DEFAULT 0,
            UNIQUE (supplier_id, file_hash)
        )');
        $this->pdo->exec("CREATE TABLE bank_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, statement_id INTEGER, posted_at TEXT, amount NUMERIC, currency TEXT, original_amount NUMERIC, original_currency TEXT,
            variable_symbol TEXT, constant_symbol TEXT, specific_symbol TEXT, counterparty_account TEXT,
            counterparty_bank TEXT, counterparty_name TEXT, card_last4 TEXT, description TEXT, bank_ref TEXT,
            import_fingerprint TEXT UNIQUE, portable_fingerprint TEXT, match_status TEXT DEFAULT 'unmatched',
            processing_pending_at TEXT, processing_error TEXT
        )");
        $db = $this->createStub(Connection::class);
        $this->pdo->exec('CREATE TABLE bank_transaction_imports (statement_id INTEGER, bank_transaction_id INTEGER, import_fingerprint TEXT, supplier_id INTEGER, original_statement_id INTEGER, PRIMARY KEY (statement_id, bank_transaction_id))');
        $db->method('pdo')->willReturn($this->pdo);
        $this->pdo->exec('CREATE TABLE bank_api_months (supplier_id INTEGER, account_key TEXT, currency TEXT, month_start TEXT, statement_id INTEGER UNIQUE, PRIMARY KEY (supplier_id, account_key, currency, month_start))');
        $this->pdo->exec('CREATE TABLE bank_api_evidence_months (supplier_id INTEGER, evidence_statement_id INTEGER, monthly_statement_id INTEGER, PRIMARY KEY (evidence_statement_id, monthly_statement_id))');
        $this->pdo->exec('CREATE TABLE bank_connections (supplier_id INTEGER, currency_id INTEGER, verified_account_number TEXT, verified_bank_code TEXT)');
        $this->matcher = $this->createMock(StatementMatcher::class);
        $reconciler = $this->createStub(EmailNoticeReconciler::class);
        $reconciler->method('takeOverFromEmailNotice')->willReturn(null);
        $this->importer = new StatementImporter($db, new GpcParser(), $this->matcher, $reconciler);
    }

    public function testApiImportKeepsJsonSourceAndOverlappingTransactionsAreNotDuplicated(): void
    {
        $parsed = new GpcParser()->parse($this->gpc());
        $parsed['header']['curr_balance'] = null;
        $parsed['header']['prev_balance'] = null;
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $first = $this->importer->importConnectedParsed($parsed, '{"synthetic":1}', 'synthetic.json', null, 1, 10);
        $second = $this->importer->importConnectedParsed($parsed, '{"synthetic":2}', 'synthetic-overlap.json', null, 1, 10);
        self::assertSame(2, $first['transactions']);
        self::assertSame(0, $second['transactions']);
        self::assertSame(2, $second['skipped_duplicates']);
        self::assertSame('bank_api', $this->pdo->query('SELECT source FROM bank_statements LIMIT 1')->fetchColumn());
        self::assertSame('{"synthetic":1}', $this->pdo->query('SELECT file_content FROM bank_statements LIMIT 1')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT curr_balance FROM bank_statements LIMIT 1')->fetchColumn());
    }

    public function testApiOverlapsAndEmptyDownloadsReuseMonthlyStatementWithoutMovingTransactions(): void
    {
        $parsed = new GpcParser()->parse($this->gpc());
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $first = $this->importer->importConnectedParsed($parsed, 'synthetic-month-first', 'first.json', null, 1, 10);
        $before = $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $second = $this->importer->importConnectedParsed($parsed, 'synthetic-month-overlap', 'second.json', null, 1, 10);
        self::assertSame($first['statement_id'], $second['statement_id']);
        $parsed['transactions'] = [];
        $empty = $this->importer->importConnectedParsed($parsed, 'synthetic-month-empty', 'empty.json', null, 1, 10);
        self::assertSame($first['statement_id'], $empty['statement_id']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements bs WHERE ' . \MyInvoice\Service\Bank\BankApiMonthlyStatements::visibleSql())->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE ' . \MyInvoice\Service\Bank\StatementTransactionScope::sql($first['statement_id']))->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements WHERE file_content IS NOT NULL')->fetchColumn());
    }

    public function testApiIbanIsStoredAsTheLinkedCurrencyAccountNumber(): void
    {
        $this->pdo->exec("UPDATE currencies SET bank_code = '0100' WHERE id = 1");
        $parsed = new GpcParser()->parse($this->gpc());
        $parsed['header']['account_number'] = 'CZ6501000000001000000005';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-kb-iban', 'kb.json', null, 1, 10);
        self::assertGreaterThan(0, $result['transactions']);
        $accounts = $this->pdo->query('SELECT DISTINCT account_number FROM bank_statements')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['1000000005'], $accounts);
    }

    public function testApiDownloadSpanningMonthsSeparatesTransactionsByBookingDate(): void
    {
        $parsed = new GpcParser()->parse($this->gpc());
        $parsed['transactions'][1]['posted_at'] = '2026-02-02';
        $parsed['header']['statement_date'] = '2026-02-05';
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-two-months', 'months.json', null, 1, 10);
        self::assertCount(2, $result['statement_ids'] ?? []);
        $months = $this->pdo->query('SELECT month_start, statement_id FROM bank_api_months ORDER BY month_start')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['2026-01-01', '2026-02-01'], array_column($months, 'month_start'));
        foreach ($months as $month) {
            $dates = $this->pdo->query('SELECT posted_at FROM bank_transactions bt WHERE ' . \MyInvoice\Service\Bank\StatementTransactionScope::sql((int) $month['statement_id']))->fetchAll(PDO::FETCH_COLUMN);
            self::assertCount(1, $dates);
            self::assertSame(substr($month['month_start'], 0, 7), substr($dates[0], 0, 7));
        }
        self::assertSame($months[1]['statement_id'], $result['statement_id']);
        $retry = $this->importer->importConnectedParsed($parsed, 'synthetic-two-months', 'months.json', null, 1, 10);
        self::assertSame($result['statement_ids'], $retry['statement_ids']);
    }

    public function testMonthlyAccountNormalizationAndOlderEmptyDownloadKeepCoverage(): void
    {
        $parsed = new GpcParser()->parse($this->gpc());
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $first = $this->importer->importConnectedParsed($parsed, 'synthetic-covered', 'covered.json', null, 1, 10);
        $parsed['transactions'] = [];
        $parsed['header']['account_number'] = '1000000005';
        $parsed['header']['statement_date'] = '2026-01-10';
        $older = $this->importer->importConnectedParsed($parsed, 'synthetic-older', 'older.json', null, 1, 10);
        self::assertSame($first['statement_id'], $older['statement_id']);
        self::assertSame('2026-01-31', $this->pdo->query('SELECT statement_date FROM bank_statements WHERE id = ' . $first['statement_id'])->fetchColumn());
        $this->pdo->exec("INSERT INTO currencies VALUES (2, 20, '1000000005', NULL, '2010', 'EUR', 1)");
        $foreign = $this->importer->importConnectedParsed($parsed, 'synthetic-other-tenant', 'foreign.json', null, 2, 20);
        self::assertNotSame($first['statement_id'], $foreign['statement_id']);
        $this->pdo->exec("INSERT INTO currencies VALUES (3, 10, '1000000005', NULL, '2010', 'CZK', 1)");
        $currency = $this->importer->importConnectedParsed($parsed, 'synthetic-other-currency', 'currency.json', null, 3, 10);
        self::assertNotSame($first['statement_id'], $currency['statement_id']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_api_months')->fetchColumn());
    }

    public function testConnectedGpcFeedFoldsNightlyWindowsIntoOneMonthlyStatement(): void
    {
        // Fio: každé noční stažení je GPC za okno od posledního watermarku, i když
        // v něm nic nového není. Samostatně by každé bylo vidět jako další výpis
        // a pohyby z překryvu oken by se opakovaly ve výpisech po sobě (#109).
        $this->pdo->exec("INSERT INTO bank_connections VALUES (10, 1, '1000000005', '2010')");
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $header = explode("\r\n", $this->gpc())[0];

        $first = $this->importer->importConnected($this->gpc(), 'fio-1-2026-01-10-2026-01-31.gpc', null, 1, 10);
        $overlap = $this->importer->importConnected(str_replace('+001310126', '+001300126', $this->gpc()), 'fio-1-2026-01-12-2026-01-30.gpc', null, 1, 10);
        $empty = $this->importer->importConnected(str_replace('+001310126', '+001290126', $header) . "\r\n", 'fio-1-2026-01-27-2026-01-29.gpc', null, 1, 10);

        self::assertSame($first['statement_id'], $overlap['statement_id']);
        self::assertSame($first['statement_id'], $empty['statement_id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements bs WHERE ' . \MyInvoice\Service\Bank\BankApiMonthlyStatements::visibleSql())->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE ' . \MyInvoice\Service\Bank\StatementTransactionScope::sql($first['statement_id']))->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_api_evidence_months')->fetchColumn());
    }

    public function testUnconnectedGpcUploadStaysStandaloneStatement(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $result = $this->importer->importConnected($this->gpc(), 'manual.gpc', null, 1, 10);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_api_months')->fetchColumn());
        self::assertSame('gpc', $this->pdo->query('SELECT source FROM bank_statements WHERE id = ' . (int) $result['statement_id'])->fetchColumn());
    }

    public function testReconstructedExportCannotBecomeConfirmedBankEvidence(): void
    {
        $this->matcher->expects(self::never())->method('matchBatch');
        $parsed = (new GpcParser())->parse($this->gpc());
        $parsed['header']['reconstructed'] = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->importConnectedParsed($parsed, 'synthetic-export', 'synthetic.gpc', null, 1, 10, 'gpc');
    }

    public function testSlovakIbanOnlyAccountPreservesBankCodeAndEuroCurrency(): void
    {
        $this->pdo->exec("UPDATE currencies SET account_number = NULL, iban = 'SK0383300000001000000005', bank_code = '8330'");
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $result = $this->importer->importConnected($this->gpc(), 'synthetic-sk.gpc', null, 1, 10);
        self::assertSame(2, $result['transactions']);
        self::assertSame(['bank_code' => '8330', 'currency' => 'EUR'], $this->pdo->query('SELECT bank_code, currency FROM bank_statements')->fetch(PDO::FETCH_ASSOC));
        self::assertSame(['EUR'], $this->pdo->query('SELECT DISTINCT currency FROM bank_transactions')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testStorageIsAtomicBeforeMatchingAndRetryImportsAllRows(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_second BEFORE INSERT ON bank_transactions WHEN NEW.bank_ref = '1002' BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END");
        try {
            $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
            self::fail('Storage failure must propagate.');
        } catch (\PDOException) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
            self::assertFalse($this->pdo->inTransaction());
        }
        $this->pdo->exec('DROP TRIGGER fail_second');
        $this->matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids): array {
            self::assertCount(2, $ids);
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
            return [];
        });
        $result = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertSame(2, $result['transactions']);
        self::assertSame(['EUR'], $this->pdo->query('SELECT DISTINCT currency FROM bank_transactions')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRetryOfIdenticalFileAfterMatchingFailureOnlyLinksStoredRows(): void
    {
        // Uložené pohyby se znovu nahraným výpisem jen propojí; dorovnání po chybě
        // párování je „Znovu spárovat" u výpisu (StatementImportTransactions).
        $this->matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids): array {
            self::assertCount(2, $ids);
            throw new \RuntimeException('synthetic matching failure');
        });
        $failed = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertTrue($failed['processing_failed'], 'Selhání párování import nezahodí, výpis ho nese dál.');
        self::assertSame(['processing_failed'], array_column($failed['warnings'], 'code'));
        self::assertSame(
            ['synthetic matching failure', 'synthetic matching failure'],
            $this->pdo->query('SELECT processing_error FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
        $result = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertTrue($result['duplicate']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE processing_error IS NOT NULL')->fetchColumn(), 'Opakované nahrání upozornění neodstraní.');
    }

    public function testSuccessfulProcessingClearsPendingMark(): void
    {
        $this->matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids): array {
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE processing_pending_at IS NOT NULL')->fetchColumn());
            return [];
        });
        $result = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertFalse($result['processing_failed']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE processing_pending_at IS NOT NULL OR processing_error IS NOT NULL')->fetchColumn());
    }

    public function testForeignTenantIsRejectedBeforeStorage(): void
    {
        $this->matcher->expects(self::never())->method('matchBatch');
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 20);
    }

    public function testChangedFileAfterMatchingFailureOnlyLinksStoredMovements(): void
    {
        $this->matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids): array {
            self::assertCount(2, $ids);
            throw new \RuntimeException('synthetic matching failure');
        });
        self::assertTrue($this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10)['processing_failed']);
        $result = $this->importer->importConnected(str_replace('+001310126', '+002310126', $this->gpc()), 'synthetic-2.gpc', null, 1, 10);
        self::assertSame(0, $result['transactions']);
        self::assertSame(2, $result['skipped_duplicates']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testMismatchedAccountIsRejectedBeforeStorage(): void
    {
        $this->pdo->exec("UPDATE currencies SET account_number = '19-1000000005'");
        $this->matcher->expects(self::never())->method('matchBatch');
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
    }

    public function testForeignStatementHashCreatesOwnStatement(): void
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_hash) VALUES (20, 'gpc', ?)")
            ->execute([hash('sha256', $this->gpc())]);
        $foreignId = (int) $this->pdo->lastInsertId();
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $result = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertFalse($result['duplicate']);
        self::assertNotSame($foreignId, (int) $result['statement_id']);
        self::assertSame(10, (int) $this->pdo->query('SELECT supplier_id FROM bank_statements WHERE id = ' . (int) $result['statement_id'])->fetchColumn());
    }

    public function testMonthlyGpcReusesApiMovementWithPaddedReferenceAndPreservesEvidence(): void
    {
        $lines = explode("\r\n", $this->gpc());
        $lines[1] = substr_replace($lines[1], '0000001000000005', 19, 16);
        $lines[1] = substr_replace($lines[1], '0100', 73, 4);
        $content = $lines[0] . "\r\n" . $lines[1] . "\r\n";
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = '0001001';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $api = $this->importer->importConnectedParsed($parsed, '{"synthetic":3}', 'synthetic.json', null, 1, 10);
        $this->pdo->exec("UPDATE bank_transactions SET match_status = 'manual'");
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE UNIQUE INDEX evidence_parent ON bank_transactions(statement_id, id)');
        $this->pdo->exec('CREATE TABLE synthetic_payroll_evidence (statement_id INTEGER, transaction_id INTEGER, frozen_hash TEXT, FOREIGN KEY (statement_id, transaction_id) REFERENCES bank_transactions(statement_id, id) ON DELETE RESTRICT)');
        $this->pdo->prepare('INSERT INTO synthetic_payroll_evidence VALUES (?, 1, ?)')->execute([$api['evidence_statement_id'], hash('sha256', 'synthetic-frozen-evidence')]);
        $this->pdo->exec('CREATE TABLE synthetic_journal (id INTEGER PRIMARY KEY, transaction_id INTEGER UNIQUE REFERENCES bank_transactions(id), debit TEXT, credit TEXT)');
        $this->pdo->exec("INSERT INTO synthetic_journal VALUES (1, 1, '221', '311')");
        $journal = $this->pdo->query('SELECT * FROM synthetic_journal')->fetchAll(PDO::FETCH_ASSOC);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);

        $gpc = $this->importer->import($content, 'synthetic-month.gpc', null, 1);

        self::assertSame(0, $gpc['transactions']);
        self::assertSame(1, $gpc['skipped_duplicates']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(hash('sha256', 'synthetic-frozen-evidence'), $this->pdo->query('SELECT frozen_hash FROM synthetic_payroll_evidence')->fetchColumn());
        self::assertSame($api['statement_id'], $gpc['statement_id']);
        self::assertSame(1, $gpc['matched']);
        self::assertSame('gpc', $this->pdo->query('SELECT source FROM bank_statements WHERE id = ' . $gpc['statement_id'])->fetchColumn());
        self::assertSame($content, $this->pdo->query('SELECT file_content FROM bank_statements WHERE id = ' . $gpc['statement_id'])->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements bs WHERE ' . \MyInvoice\Service\Bank\BankApiMonthlyStatements::visibleSql())->fetchColumn());
        self::assertSame($content, $this->pdo->query('SELECT file_content FROM bank_statements WHERE id = ' . $gpc['evidence_statement_id'])->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transaction_imports')->fetchColumn());
        $scope = \MyInvoice\Service\Bank\StatementTransactionScope::sql($gpc['statement_id']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM bank_transactions bt WHERE $scope")->fetchColumn());
        self::assertTrue($this->importer->import($content, 'synthetic-month.gpc', null, 1)['duplicate']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($journal, $this->pdo->query('SELECT * FROM synthetic_journal')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testPartialGpcCannotHideApiMovementMissingFromBankDocument(): void
    {
        $parsed = new GpcParser()->parse($this->gpc());
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $api = $this->importer->importConnectedParsed($parsed, 'synthetic-full-api', 'api.json', null, 1, 10);
        $before = $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $gpc = $this->importer->import($this->transferGpc(), 'partial.gpc', null, 1);
        self::assertSame($api['statement_id'], $gpc['statement_id']);
        self::assertSame('bank_api', $this->pdo->query('SELECT source FROM bank_statements WHERE id = ' . $api['statement_id'])->fetchColumn());
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE ' . \MyInvoice\Service\Bank\StatementTransactionScope::sql($api['statement_id']))->fetchColumn());
    }

    public function testApiAfterGpcPreservesTransactionAndDoesNotRematchIt(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['header']['account_number'] = 'CZ2920100000001000000005';
        $parsed['transactions'][0]['bank_ref'] = '0001001';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturnCallback(function (array $ids): array {
            self::assertSame([1], $ids);
            return [];
        });
        $this->importer->import($content, 'synthetic-month.gpc', null, 1);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);
        $result = $this->importer->importConnectedParsed($parsed, '{"synthetic":4}', 'synthetic.json', null, 1, 10);
        self::assertSame(0, $result['transactions']);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":4}', 'synthetic.json', null, 1, 10);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testConfirmedCrossSourceIdentityPreservesEvidenceAndSubsequentOverlap(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = 'SYNTHETIC-API-REFERENCE';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->import($content, 'synthetic.gpc', null, 1);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->importer->importConnectedParsed($parsed, 'synthetic-confirmed-api', 'synthetic.json', null, 1, 10);
            self::fail('Different references require confirmation.');
        } catch (\InvalidArgumentException $e) {
            self::assertObjectHasProperty('candidates', $e);
            $confirmations = array_column($e->candidates, 'confirmation_key');
            self::assertCount(1, $confirmations);
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-confirmed-api', 'synthetic.json', null, 1, 10, 'bank_api', $confirmations);
        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        $identities = (new \ReflectionMethod(StatementImporter::class, 'transactionIdentities'))->invoke(
            $this->importer, $parsed['transactions'], (string) $parsed['header']['account_number'], '2010', 'EUR', 'EUR', 10,
        );
        $this->pdo->prepare('UPDATE bank_transaction_imports SET import_fingerprint = ? WHERE import_fingerprint = ?')
            ->execute([$identities[0]['candidates'][1], $identities[0]['fingerprint']]);
        $retry = $this->importer->importConnectedParsed($parsed, 'synthetic-overlap-after-confirmation', 'synthetic-overlap.json', null, 1, 10);
        self::assertSame(0, $retry['transactions']);
        self::assertSame(1, $retry['skipped_duplicates']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transaction_imports')->fetchColumn());
    }

    public function testPdfImportFindsLegacyUnscopedAlias(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'] = [$parsed['transactions'][0]];
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $original = $this->importer->import($content, 'synthetic.gpc', null, 1);
        $transactionId = (int) $this->pdo->query('SELECT id FROM bank_transactions LIMIT 1')->fetchColumn();
        $portable = (string) $this->pdo->query('SELECT portable_fingerprint FROM bank_transactions WHERE id = ' . $transactionId)->fetchColumn();
        $this->pdo->prepare('UPDATE bank_transactions SET import_fingerprint = ? WHERE id = ?')
            ->execute([hash('sha256', 'synthetic-older-reference'), $transactionId]);
        $this->pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id) VALUES (?, ?, ?, ?)')
            ->execute([$original['statement_id'], $transactionId, $portable, 10]);

        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-pdf-overlap', 'synthetic.pdf', null, 1, 10, 'pdf');

        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testChangedIncomingIdentityCannotReuseConfirmation(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = 'SYNTHETIC-API-REFERENCE';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->import($content, 'synthetic.gpc', null, 1);
        try {
            $this->importer->importConnectedParsed($parsed, 'synthetic-first', 'synthetic.json', null, 1, 10);
            self::fail('Confirmation required.');
        } catch (\InvalidArgumentException $e) {
            self::assertObjectHasProperty('candidates', $e);
            $confirmations = array_column($e->candidates, 'confirmation_key');
        }
        $parsed['transactions'][0]['bank_ref'] = 'SYNTHETIC-CHANGED-REFERENCE';
        try {
            $this->importer->importConnectedParsed($parsed, 'synthetic-changed', 'synthetic.json', null, 1, 10, 'bank_api', $confirmations);
            self::fail('Stale confirmation must not merge changed movement.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
        }
    }

    /**
     * Stejný den, částka a protiúčet, v GPC i ve feedu jediný takový pohyb: jde o tutéž platbu
     * i s jinou referencí a popisem (převod mezi vlastními účty bez VS), potvrzení netřeba.
     */
    public function testMonthlyGpcWithDifferentReferenceReusesUniqueApiMovement(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = 'SYNTHETIC-API-REFERENCE';
        $parsed['transactions'][0]['description'] = 'Odchozi uhrada';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($parsed, 'synthetic-first-api', 'synthetic.json', null, 1, 10);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);
        $result = $this->importer->import($content, 'synthetic.gpc', null, 1);
        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        $retry = $this->importer->import($content . "\r\n", 'synthetic-overlap.gpc', null, 1);
        self::assertSame(0, $retry['transactions']);
        self::assertSame(1, $retry['skipped_duplicates']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testConfirmedAliasCannotAbsorbAnotherPaymentInNextBatch(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = 'SYNTHETIC-API-REFERENCE';
        $this->matcher->expects(self::exactly(2))->method('matchBatch')->willReturn([]);
        $this->importer->import($content, 'synthetic.gpc', null, 1);
        try {
            $this->importer->importConnectedParsed($parsed, 'synthetic-first', 'synthetic.json', null, 1, 10);
            self::fail('Confirmation required.');
        } catch (\MyInvoice\Service\Bank\StatementReconciliationException $e) {
            $keys = array_column($e->candidates, 'confirmation_key');
        }
        $this->importer->importConnectedParsed($parsed, 'synthetic-first', 'synthetic.json', null, 1, 10, 'bank_api', $keys);
        $parsed['transactions'][] = $parsed['transactions'][0];
        $parsed['transactions'][1]['bank_ref'] = 'SYNTHETIC-ANOTHER-PAYMENT';

        // Potvrzený alias smí převzít právě jeden načtený pohyb. Druhá platba téhož
        // dne a částky se za něj proto nesmí schovat — založí se jako samostatný
        // pohyb. Dřív se místo toho shodilo celé načtení a platba se ztratila.
        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-next', 'synthetic.json', null, 1, 10, 'bank_api', $keys);

        self::assertSame(1, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testAmbiguousBatchRollsBackRatherThanMergingRepeatedPayments(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['transactions'][0]['bank_ref'] = 'synthetic-api';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":5}', 'synthetic.json', null, 1, 10);
        $lines = explode("\r\n", $content);
        $content .= substr_replace($lines[1], '0000000002002', 35, 13) . "\r\n";
        try {
            $this->importer->import($content, 'synthetic-ambiguous.gpc', null, 1);
            self::fail('Ambiguous cross-source match must stop the import.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nejednoznačnou', $e->getMessage());
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
            self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testCrossSourceNeverMatchesAnotherTenantCurrencyOrBank(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $this->matcher->expects(self::exactly(2))->method('matchBatch')->willReturn([]);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 1);
        $parsed['transactions'][0]['bank_ref'] = 'synthetic-distinct';
        $this->pdo->exec("UPDATE bank_statements SET supplier_id = 20, currency = 'CZK', bank_code = '0100'");
        $result = $this->importer->importConnectedParsed($parsed, '{"synthetic":6}', 'synthetic.json', null, 1, 10);
        self::assertSame(1, $result['transactions']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testMissingCounterpartyAndDifferentReferencesRequireReview(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $parsed['transactions'][0]['bank_ref'] = 'synthetic-no-counterparty';
        $parsed['transactions'][0]['counterparty_account'] = null;
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":7}', 'synthetic.json', null, 1, 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 1);
    }

    /**
     * Feed je jen okno pohybů, ne úplný seznam účtu: proti uloženému GPC jediný pohyb téhož dne
     * nic nedokazuje a shodu dál potvrzuje člověk. Automaticky ji bere jen GPC proti feedu.
     */
    public function testFeedWithDifferentReferenceAgainstGpcStillRequiresConfirmation(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $parsed['transactions'][0]['bank_ref'] = 'synthetic-distinct-payment';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 1);
        $this->expectException(\MyInvoice\Service\Bank\StatementReconciliationException::class);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":8}', 'synthetic.json', null, 1, 10);
    }

    public function testApiDownloadReusesMovementTakenOverFromAnotherProgram(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $parsed['transactions'] = [array_replace($parsed['transactions'][0], ['bank_ref' => 'synthetic-api-ref', 'variable_symbol' => '2026001'])];
        $tx = $parsed['transactions'][0];
        $this->pdo->exec("INSERT INTO bank_statements (source, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_date, transaction_count)
            VALUES ('import', 'money-s3-synthetic.import', 'synthetic-takeover', 10, '1000000005', '2010', 'EUR', '{$tx['posted_at']}', 1)");
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank, bank_ref, import_fingerprint)
            VALUES (?, ?, ?, 'EUR', ?, ?, ?, 'BV260001', 'synthetic-takeover-fingerprint')")
            ->execute([(int) $this->pdo->lastInsertId(), $tx['posted_at'], $tx['amount'], $tx['variable_symbol'], $tx['counterparty_account'], $tx['counterparty_bank']]);
        $this->matcher->expects(self::never())->method('matchBatch');

        $result = $this->importer->importConnectedParsed($parsed, '{"synthetic":"takeover"}', 'synthetic.json', null, 1, 10);

        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testTakenOverMovementWithoutCounterpartyRequiresReview(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $parsed['transactions'] = [array_replace($parsed['transactions'][0], ['bank_ref' => 'synthetic-api-ref'])];
        $tx = $parsed['transactions'][0];
        $this->pdo->exec("INSERT INTO bank_statements (source, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_date, transaction_count)
            VALUES ('import', 'money-s3-synthetic.import', 'synthetic-takeover', 10, '1000000005', '2010', 'EUR', '{$tx['posted_at']}', 1)");
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, bank_ref, import_fingerprint)
            VALUES (?, ?, ?, 'EUR', 'BV260001', 'synthetic-takeover-fingerprint')")
            ->execute([(int) $this->pdo->lastInsertId(), $tx['posted_at'], $tx['amount']]);
        $this->matcher->expects(self::never())->method('matchBatch');

        $this->expectException(\MyInvoice\Service\Bank\StatementReconciliationException::class);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":"takeover-weak"}', 'synthetic.json', null, 1, 10);
    }

    public function testIdenticalFileCannotBeReusedForAnotherCurrency(): void
    {
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 1);
        $this->pdo->exec("INSERT INTO currencies VALUES (2, 10, '1000000005', NULL, '2010', 'CZK', 1)");
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 2);
    }

    private function transferGpc(): string
    {
        $lines = explode("\r\n", $this->gpc());
        $lines[1] = substr_replace($lines[1], '0000001000000005', 19, 16);
        $lines[1] = substr_replace($lines[1], '0100', 73, 4);
        return $lines[0] . "\r\n" . $lines[1] . "\r\n";
    }

    public static function automaticCrossSourceCases(): array
    {
        $cases = [];
        foreach (['gpc', 'bank_api'] as $source) {
            $cases[] = [$source, 'Invoice transfer', 'Bank transfer', '00012345', '12345', '1000000005', '0000001000000005'];
            $cases[] = [$source, "TEST SHOP s.r.o.\nPraha 123\nKarta: ****1234", 'TEST SHOP s.r.o. Praha 123 Karta: ****1234', null, null, null, null];
            $cases[] = [$source, 'Banka Test | Úrok z kladného zůstatku na účtu', 'Úrok z kladného zůstatku na účtu', null, null, null, null];
        }
        return $cases;
    }

    /**
     * Dvě opakované platby se shodným popisem proti JEDNOMU dříve uloženému pohybu.
     * Sdílet ho nesmí — jedna ho převezme, druhá se založí jako samostatný pohyb.
     */
    public function testRepeatedPaymentsPairOneMovementAndStoreTheSurplus(): void
    {
        $stored = new GpcParser()->parse($this->transferGpc());
        $stored['transactions'] = [$stored['transactions'][0]];
        $stored['transactions'][0]['description'] = 'TEST SHOP Praha 123 karta 1234';
        $this->matcher->expects(self::exactly(2))->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($stored, 'synthetic-original', 'synthetic.txt', null, 1, 10, 'gpc');
        $incoming = $stored;
        $incoming['transactions'][0]['bank_ref'] = 'SYNTHETIC-FIRST';
        $incoming['transactions'][] = array_replace($incoming['transactions'][0], ['bank_ref' => 'SYNTHETIC-SECOND']);

        $result = $this->importer->importConnectedParsed($incoming, 'synthetic-ambiguous', 'synthetic.txt', null, 1, 10);

        self::assertSame(1, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    /**
     * Dva samostatné převody téhož dne, téže částky a se shodným variabilním symbolem
     * (produkční hlášení: 2× 10 000 Kč, VS 1). Nejde o duplicitu — načíst se musí oba
     * a opakované načtení překrývajícího se výpisu je nesmí zdvojit.
     */
    public function testTwoIdenticalSameDayPaymentsAreStoredOnceEachAcrossOverlappingStatements(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);

        $first = $this->importer->importConnectedParsed($this->repeatedPayments(), 'synthetic-repeated-1', 'repeated-1.gpc', null, 1, 10, 'gpc');

        self::assertSame(2, $first['transactions']);
        self::assertSame(0, $first['skipped_duplicates']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
        self::assertSame(20000.0, (float) $this->pdo->query('SELECT SUM(amount) FROM bank_transactions')->fetchColumn());

        $before = $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $overlap = $this->importer->importConnectedParsed($this->repeatedPayments(), 'synthetic-repeated-2', 'repeated-2.gpc', null, 1, 10, 'gpc');

        self::assertSame(0, $overlap['transactions']);
        self::assertSame(2, $overlap['skipped_duplicates']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * ČSOB: první z dvojice shodných plateb dorazí avízem (bank_api), denní výpis
     * banky (gpc) pak nese obě. Dřív skončil celý import chybou „nejednoznačná
     * duplicita" a druhá platba se nenačetla nikdy — v evidenci zůstal jeden pohyb
     * proti bankovnímu zůstatku za dva.
     */
    public function testBankStatementAddsTheSecondIdenticalPaymentMissingFromTheAdvice(): void
    {
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $advice = $this->repeatedPayments();
        $advice['transactions'] = [array_replace($advice['transactions'][0], ['bank_ref' => 'CSOB-ADVICE-1'])];
        $this->importer->importConnectedParsed($advice, 'synthetic-advice', 'advice.json', null, 1, 10, 'bank_api');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());

        $statement = $this->importer->importConnectedParsed($this->repeatedPayments(), 'synthetic-csob-gpc', 'csob.gpc', null, 1, 10, 'gpc');

        self::assertSame(1, $statement['transactions']);
        self::assertSame(1, $statement['skipped_duplicates']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
        self::assertSame(20000.0, (float) $this->pdo->query('SELECT SUM(amount) FROM bank_transactions')->fetchColumn());
    }

    /** Dva shodné příchozí převody 10 000 se stejným VS téhož dne, bez bankovní reference. */
    private function repeatedPayments(): array
    {
        $movement = [
            'posted_at' => '2026-09-14', 'amount' => 10000.0, 'currency' => 'EUR',
            'variable_symbol' => '1', 'constant_symbol' => null, 'specific_symbol' => null,
            'counterparty_account' => '0000001000000005', 'counterparty_bank' => '0100',
            'counterparty_name' => 'SYNTHETIC', 'description' => 'Vlastni prevod',
            'bank_ref' => null,
        ];
        return [
            'header' => [
                'account_number' => '1000000005', 'statement_date' => '2026-09-14', 'statement_number' => '001',
                'prev_balance' => null, 'curr_balance' => null, 'debit_total' => null, 'credit_total' => null,
            ],
            'transactions' => [$movement, $movement],
        ];
    }

    public static function conflictingDescriptions(): array
    {
        return [
            ['TEST SHOP Praha 123 karta 1234', 'TEST SHOP Praha 123 karta 9999'],
            ['SYNTHETIC BANK NAME | Shop Alpha Praha', 'SYNTHETIC BANK NAME | Shop Beta Praha'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingDescriptions')]
    public function testDifferentCardDetailsStillRequireReview(string $originalDescription, string $incomingDescription): void
    {
        $stored = new GpcParser()->parse($this->transferGpc());
        $stored['transactions'] = [$stored['transactions'][0]];
        $stored['transactions'][0]['description'] = $originalDescription;
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($stored, 'synthetic-original', 'synthetic.txt', null, 1, 10, 'gpc');
        $incoming = $stored;
        $incoming['transactions'][0]['bank_ref'] = 'SYNTHETIC-OTHER';
        $incoming['transactions'][0]['description'] = $incomingDescription;
        $this->expectException(\MyInvoice\Service\Bank\StatementReconciliationException::class);
        $this->importer->importConnectedParsed($incoming, 'synthetic-other', 'synthetic.txt', null, 1, 10);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('automaticCrossSourceCases')]
    public function testUniqueCrossSourceEvidenceReconcilesAutomatically(string $source, string $oldDescription, string $newDescription, ?string $oldVs, ?string $newVs, ?string $oldAccount, ?string $newAccount): void
    {
        $stored = new GpcParser()->parse($this->transferGpc());
        $stored['transactions'] = [array_replace($stored['transactions'][0], [
            'description' => $oldDescription, 'variable_symbol' => $oldVs,
            'counterparty_account' => $oldAccount, 'counterparty_bank' => $oldAccount === null ? null : '0100',
        ])];
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($stored, 'synthetic-original', 'synthetic-original.txt', null, 1, 10, $source);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);
        $incoming = $stored;
        $incoming['transactions'][0] = array_replace($incoming['transactions'][0], [
            'bank_ref' => 'SYNTHETIC-OTHER-REFERENCE', 'description' => $newDescription,
            'variable_symbol' => $newVs, 'counterparty_account' => $newAccount,
        ]);
        $otherSource = $source === 'gpc' ? 'bank_api' : 'gpc';
        foreach (['first', 'overlap'] as $batch) {
            $result = $this->importer->importConnectedParsed($incoming, 'synthetic-' . $batch, 'synthetic.txt', null, 1, 10, $otherSource);
            self::assertSame(0, $result['transactions']);
            self::assertSame(1, $result['skipped_duplicates']);
            self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    /**
     * KB: tentýž pohyb načtený dřív přes ADAA (varianta Plus) a později z výpisu KM
     * (varianta Basic). Liší se referencí, tvarem protiúčtu (IBAN × národní číslo)
     * i popisem; výpis KM jako originál banky ho musí spárovat, ne založit znovu.
     */
    public function testKbStatementReconcilesMovementImportedEarlierFromAdaa(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES (2, 10, '1000000005', 'CZ6501000000001000000005', '0100', 'CZK', 1)");
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $adaa = [
            'header' => [
                'account_number' => 'CZ6501000000001000000005', 'statement_date' => '2026-09-14', 'statement_number' => null,
                'prev_balance' => null, 'curr_balance' => null, 'debit_total' => null, 'credit_total' => null,
            ],
            'transactions' => [[
                'posted_at' => '2026-09-10', 'amount' => 10000.0, 'currency' => 'CZK',
                'variable_symbol' => '1', 'constant_symbol' => null, 'specific_symbol' => null,
                'counterparty_account' => 'CZ0622500000001000000005', 'counterparty_bank' => '2250',
                'counterparty_name' => 'Synteticka s.r.o.', 'description' => 'vlastni ucet | CZ0622500000001000000005',
                'bank_ref' => 'kbplus:SYNTHETIC-0001',
            ]],
        ];
        $this->importer->importConnectedParsed($adaa, '{"synthetic":"adaa"}', 'kb_plus.json', null, 2, 10, 'bank_api');
        $km = '074' . '5000100000000000' . str_repeat(' ', 20) . '090926' . str_repeat('0', 14) . '+'
            . sprintf('%014d', 1000000) . '+' . str_repeat('0', 14) . '0' . sprintf('%014d', 1000000) . '0'
            . '001' . '100926' . 'CZ650100' . 'MB' . str_repeat(' ', 4) . "\r\n"
            . '075' . '5000100000000000' . '5000100000000000' . sprintf('%013d', 1) . sprintf('%012d', 1000000) . '2'
            . sprintf('%010d', 1) . '00' . '2250' . '0000' . str_repeat('0', 10) . '000000'
            . str_pad('SYNTETICKA S.R.O.', 20) . '0' . str_repeat(' ', 4) . '100926' . "\r\n";
        $statement = (new KbPlusKmStatementParser())->statements([$km], 'CZ6501000000001000000005', 'CZK')[0];

        $result = $this->importer->importConnectedParsed($statement['parsed'], $statement['content'], 'kb-km.gpc', null, 2, 10, 'gpc');

        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    /**
     * MONETA: pohyb načtený průběžně z MONETA API a později z ručně nahraného
     * měsíčního výpisu GPC. API nese protiúčet jako „předčíslí mezera číslo/kód“
     * a vlastní referenci, výpis protiúčet vycpaný nulami a jinou referenci.
     * Výpis ho musí spárovat, ne založit znovu. Pohyb bez VS a protiúčtu
     * (poplatek) se tiše nesloučí ani nezdvojí, jde k potvrzení uživateli.
     */
    public function testMonetaMonthlyStatementReconcilesMovementsImportedFromApi(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES (3, 10, '1000000005', 'CZ3106000000001000000005', '0600', 'CZK', 1)");
        $this->matcher->expects(self::atLeastOnce())->method('matchBatch')->willReturn([]);
        $api = (new \MyInvoice\Service\Bank\Connector\MonetaTransactionParser())->parse([[
            'amount' => ['currency' => 'CZK', 'value' => 10000],
            'bookingDate' => ['date' => '2026-09-18'],
            'valueDate' => ['date' => '2026-09-18'],
            'creditDebitIndicator' => 'CRDT',
            'status' => 'BOOK',
            'entryReference' => '0001000000005:20260918:00001:0000000000000000001',
            'entryDetails' => ['transactionDetails' => [
                'relatedParties' => [
                    'debtor' => ['name' => 'Synteticka s.r.o.'],
                    'debtorAccount' => ['identification' => ['other' => ['identification' => '0 0000000019/2250']]],
                ],
                'remittanceInformation' => [
                    'structured' => ['creditorReferenceInformation' => ['reference' => 'VS:1']],
                    'unstructured' => 'vlastni ucet',
                ],
            ]],
        ]], 'CZ3106000000001000000005', 'CZK', '2026-09-22');
        $this->importer->importConnectedParsed($api, '{"synthetic":"moneta"}', 'moneta.json', null, 3, 10, 'bank_api');

        $account = str_pad('1000000005', 16, '0', STR_PAD_LEFT);
        $gpc = '074' . $account . str_pad('SYNTETICKA', 20) . '310826' . str_repeat('0', 14) . '+'
            . sprintf('%014d', 1000000) . '+' . str_repeat('0', 14) . '0' . sprintf('%014d', 1000000) . '0'
            . '001' . '300926' . str_repeat(' ', 14) . "\r\n"
            . '075' . $account . str_pad('19', 16, '0', STR_PAD_LEFT) . sprintf('%013d', 777) . sprintf('%012d', 1000000) . '2'
            . sprintf('%010d', 1) . '00' . '2250' . '0000' . str_repeat('0', 10) . '180926'
            . str_pad('SYNTETICKA S.R.O.', 20) . '0' . str_repeat(' ', 4) . '180926' . "\r\n";

        $result = $this->importer->import($gpc, 'moneta-2026-09.gpc', null, 3);

        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());

        $fee = $api;
        $fee['transactions'] = [[
            'posted_at' => '2026-09-20', 'amount' => -50.0, 'currency' => 'CZK',
            'variable_symbol' => null, 'constant_symbol' => null, 'specific_symbol' => null,
            'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_name' => null,
            'description' => 'Poplatek', 'bank_ref' => 'moneta:synthetic-fee',
        ]];
        $this->importer->importConnectedParsed($fee, '{"synthetic":"moneta-fee"}', 'moneta-fee.json', null, 3, 10, 'bank_api');
        $feeGpc = substr($gpc, 0, 130)
            . '075' . $account . str_repeat('0', 16) . sprintf('%013d', 778) . sprintf('%012d', 5000) . '1'
            . str_repeat('0', 10) . '00' . '0000' . '0000' . str_repeat('0', 10) . '200926'
            . str_pad('POPLATEK', 20) . '0' . str_repeat(' ', 4) . '200926' . "\r\n";
        try {
            $this->importer->import($feeGpc, 'moneta-2026-09-fee.gpc', null, 3);
            self::fail('Pohyb bez VS a protiúčtu se nesmí sloučit bez potvrzení.');
        } catch (\MyInvoice\Service\Bank\StatementReconciliationException $e) {
            self::assertCount(1, $e->candidates);
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    private function gpc(): string
    {
        $account = str_pad('1000000005', 16, '0', STR_PAD_LEFT);
        $header = '074' . $account . str_pad('SYNTHETIC', 20) . '010126'
            . str_repeat('0', 14) . '+' . str_pad('20000', 14, '0', STR_PAD_LEFT) . '+'
            . str_repeat('0', 14) . '+' . str_pad('20000', 14, '0', STR_PAD_LEFT) . '+001310126';
        $lines = [$header];
        foreach (['1001', '1002'] as $reference) {
            $lines[] = '075' . $account . str_repeat('0', 16) . str_pad($reference, 13, '0', STR_PAD_LEFT)
                . str_pad('10000', 12, '0', STR_PAD_LEFT) . '2' . str_repeat('0', 30)
                . '150126' . str_pad('SYNTHETIC', 20) . '00203150126';
        }
        return implode("\r\n", $lines) . "\r\n";
    }
}
