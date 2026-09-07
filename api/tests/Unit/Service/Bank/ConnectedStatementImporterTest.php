<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
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
            id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, file_name TEXT, file_hash TEXT UNIQUE, file_content BLOB,
            pdf_content BLOB, pdf_name TEXT, pdf_hash TEXT, pdf_size_bytes INTEGER, pdf_uploaded_at TEXT,
            supplier_id INTEGER, account_number TEXT, bank_code TEXT, currency TEXT, statement_number TEXT,
            statement_date TEXT, prev_balance NUMERIC, curr_balance NUMERIC, credit_total NUMERIC,
            debit_total NUMERIC, transaction_count INTEGER, imported_by INTEGER, matched_count INTEGER DEFAULT 0
        )');
        $this->pdo->exec("CREATE TABLE bank_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, statement_id INTEGER, posted_at TEXT, amount NUMERIC, currency TEXT,
            variable_symbol TEXT, constant_symbol TEXT, specific_symbol TEXT, counterparty_account TEXT,
            counterparty_bank TEXT, counterparty_name TEXT, description TEXT, bank_ref TEXT,
            import_fingerprint TEXT UNIQUE, match_status TEXT DEFAULT 'unmatched'
        )");
        $db = $this->createStub(Connection::class);
        $this->pdo->exec('CREATE TABLE bank_transaction_imports (statement_id INTEGER, bank_transaction_id INTEGER, import_fingerprint TEXT, supplier_id INTEGER, original_statement_id INTEGER, PRIMARY KEY (statement_id, bank_transaction_id))');
        $db->method('pdo')->willReturn($this->pdo);
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

    public function testMatchingFailureCanResumeIdenticalFileWithoutDuplicatingRows(): void
    {
        $attempt = 0;
        $this->matcher->expects(self::exactly(2))->method('matchBatch')->willReturnCallback(function (array $ids) use (&$attempt): array {
            self::assertCount(2, $ids);
            if (++$attempt === 1) throw new \RuntimeException('synthetic matching failure');
            return [];
        });
        try {
            $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
            self::fail('Matching failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic matching failure', $e->getMessage());
        }
        $result = $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
        self::assertTrue($result['duplicate']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn());
    }

    public function testForeignTenantIsRejectedBeforeStorage(): void
    {
        $this->matcher->expects(self::never())->method('matchBatch');
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 20);
    }

    public function testChangedFileAfterMatchingFailureResumesPreviouslyStoredMovements(): void
    {
        $attempt = 0;
        $this->matcher->expects(self::exactly(2))->method('matchBatch')->willReturnCallback(function (array $ids) use (&$attempt): array {
            self::assertCount(2, $ids);
            if (++$attempt === 1) throw new \RuntimeException('synthetic matching failure');
            return [];
        });
        try {
            $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
            self::fail('Matching failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic matching failure', $e->getMessage());
        }
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

    public function testForeignStatementHashNeverResumesForeignTransactions(): void
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_hash) VALUES (20, 'gpc', ?)")
            ->execute([hash('sha256', $this->gpc())]);
        $this->matcher->expects(self::never())->method('matchBatch');
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->importConnected($this->gpc(), 'synthetic.gpc', null, 1, 10);
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
        $this->pdo->prepare('INSERT INTO synthetic_payroll_evidence VALUES (?, 1, ?)')->execute([$api['statement_id'], hash('sha256', 'synthetic-frozen-evidence')]);
        $before = $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC);

        $gpc = $this->importer->import($content, 'synthetic-month.gpc', null, 1);

        self::assertSame(0, $gpc['transactions']);
        self::assertSame(1, $gpc['skipped_duplicates']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(hash('sha256', 'synthetic-frozen-evidence'), $this->pdo->query('SELECT frozen_hash FROM synthetic_payroll_evidence')->fetchColumn());
        self::assertNotSame($api['statement_id'], $gpc['statement_id']);
        self::assertSame(1, $gpc['matched']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transaction_imports')->fetchColumn());
        $scope = \MyInvoice\Service\Bank\StatementTransactionScope::sql($gpc['statement_id']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM bank_transactions bt WHERE $scope")->fetchColumn());
        self::assertTrue($this->importer->import($content, 'synthetic-month.gpc', null, 1)['duplicate']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM bank_transactions')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testApiAfterGpcPreservesTransactionAndResumesUnmatchedMovement(): void
    {
        $content = $this->transferGpc();
        $parsed = new GpcParser()->parse($content);
        $parsed['header']['account_number'] = 'CZ2920100000001000000005';
        $parsed['transactions'][0]['bank_ref'] = '0001001';
        $this->matcher->expects(self::exactly(3))->method('matchBatch')->willReturnCallback(function (array $ids): array {
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
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bank_statements')->fetchColumn());
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

    public function testDifferentReferencesDoNotHideAnotherPaymentToSameAccount(): void
    {
        $parsed = new GpcParser()->parse($this->transferGpc());
        $parsed['transactions'][0]['bank_ref'] = 'synthetic-distinct-payment';
        $this->matcher->expects(self::once())->method('matchBatch')->willReturn([]);
        $this->importer->importConnectedParsed($parsed, '{"synthetic":8}', 'synthetic.json', null, 1, 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import($this->transferGpc(), 'synthetic.gpc', null, 1);
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
