<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\BankSqlitePdo;
use MyInvoice\Security\EffectiveRole;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankIgnoreNoteTest extends TestCase
{
    public static function notes(): array
    {
        return [
            'trimmed note' => ['  Testovací poplatek  ', 'Testovací poplatek', 200],
            'empty note' => [" \n ", null, 200],
            'without note' => [null, null, 200],
            'unicode limit' => [str_repeat('ž', 1000), str_repeat('ž', 1000), 200],
            'too long' => [str_repeat('ž', 1001), null, 422],
            'array' => [['note'], null, 422],
            'number' => [123, null, 422],
            'posted transaction' => ['Test note', null, 409],
        ];
    }

    #[DataProvider('notes')]
    public function testIgnorePersistsAndReturnsValidatedNote(mixed $note, ?string $expected, int $status): void
    {
        $sqlite = new BankSqlitePdo();
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, match_status TEXT, matched_invoice_id INTEGER, ignore_note TEXT, ignore_origin TEXT)');
        $sqlite->exec("INSERT INTO bank_transactions VALUES (1, 2, 'unmatched', NULL, NULL, NULL)");
        $sqlite->exec("ALTER TABLE bank_transactions ADD COLUMN source TEXT DEFAULT 'statement'");
        $sqlite->exec('CREATE TABLE bank_notice_confirmations (supplier_id INTEGER, notice_transaction_id INTEGER, state TEXT, reason TEXT, authoritative_transaction_id INTEGER)');
        $sqlite->exec('CREATE TABLE bank_statements (id INTEGER, matched_count INTEGER)');
        $sqlite->exec('INSERT INTO bank_statements VALUES (2, 0)');
        $sqlite->exec('CREATE TABLE activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)');
        $this->createAllocationTables($sqlite);

        // MariaDB ownership SQL is covered separately; all writes execute against SQLite.
        $pdo = $this->scopedPdo($sqlite);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $reflection = new \ReflectionClass(BankStatementAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => new ActivityLogger($db), 'ipMatcher' => new IpMatcher()] as $name => $value) {
            $reflection->getProperty($name)->setValue($action, $value);
        }
        $reflection->getProperty('ownership')->setValue($action, new \MyInvoice\Repository\BankStatementOwnershipResolver($db));
        $posting = $this->postingStub($sqlite);
        if ($status === 409) {
            $posting->method('onIgnore')->willThrowException(new \MyInvoice\Service\Accounting\PostingException('posted_transaction_cannot_be_ignored', 'Test rejection', 409));
        }
        $reflection->getProperty('bankPosting')->setValue($action, $posting);
        $matchReflection = new \ReflectionClass(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class);
        $reflection->getProperty('matchV2')->setValue($action, $matchReflection->newInstanceWithoutConstructor());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank-transactions/1/ignore')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic bank staff', 'staff', true, ['bank' => 1, 'bank.ignore' => 2]))
            ->withParsedBody($note === null ? [] : ['note' => $note]);
        $response = $action->ignore($request, new Response(), ['id' => 1]);
        self::assertSame($status, $response->getStatusCode());
        $row = $sqlite->query('SELECT * FROM bank_transactions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($expected, $row['ignore_note']);
        self::assertSame($status === 200 ? 'ignored' : 'unmatched', $row['match_status']);
        if ($status === 200) {
            $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($result['ignored']);
            self::assertSame($expected, $result['ignore_note']);
            $audit = json_decode($sqlite->query('SELECT payload FROM activity_log')->fetchColumn(), true);
            self::assertSame($expected, $audit['note']);
        } else {
            self::assertSame(0, (int) $sqlite->query('SELECT COUNT(*) FROM activity_log')->fetchColumn());
        }
    }

    public static function reignoreBodies(): array
    {
        return [
            'omitted keeps stored note' => [[], 'Uložená poznámka'],
            'explicit null clears' => [['note' => null], null],
            'empty string clears' => [['note' => ''], null],
            'value replaces' => [['note' => 'Nová'], 'Nová'],
        ];
    }

    #[DataProvider('reignoreBodies')]
    public function testReignoreWithoutNoteKeyKeepsStoredNote(array $body, ?string $expected): void
    {
        $sqlite = new BankSqlitePdo();
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, match_status TEXT, matched_invoice_id INTEGER, ignore_note TEXT, ignore_origin TEXT)');
        $sqlite->exec("INSERT INTO bank_transactions VALUES (1, 2, 'ignored', NULL, 'Uložená poznámka', 'manual')");
        $sqlite->exec("ALTER TABLE bank_transactions ADD COLUMN source TEXT DEFAULT 'statement'");
        $sqlite->exec('CREATE TABLE bank_notice_confirmations (supplier_id INTEGER, notice_transaction_id INTEGER, state TEXT, reason TEXT, authoritative_transaction_id INTEGER)');
        $sqlite->exec('CREATE TABLE bank_statements (id INTEGER, matched_count INTEGER)');
        $sqlite->exec('INSERT INTO bank_statements VALUES (2, 0)');
        $sqlite->exec('CREATE TABLE activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)');
        $this->createAllocationTables($sqlite);

        $pdo = $this->scopedPdo($sqlite);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $reflection = new \ReflectionClass(BankStatementAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => new ActivityLogger($db), 'ipMatcher' => new IpMatcher()] as $name => $value) {
            $reflection->getProperty($name)->setValue($action, $value);
        }
        $reflection->getProperty('ownership')->setValue($action, new \MyInvoice\Repository\BankStatementOwnershipResolver($db));
        $reflection->getProperty('bankPosting')->setValue($action, $this->postingStub($sqlite));
        $matchReflection = new \ReflectionClass(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class);
        $reflection->getProperty('matchV2')->setValue($action, $matchReflection->newInstanceWithoutConstructor());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank-transactions/1/ignore')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic bank staff', 'staff', true, ['bank' => 1, 'bank.ignore' => 2]))
            ->withParsedBody($body);
        $response = $action->ignore($request, new Response(), ['id' => 1]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $sqlite->query('SELECT * FROM bank_transactions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($expected, $row['ignore_note']);
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result['ignore_note']);
    }

    public static function unmatchCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('unmatchCases')]
    public function testUnmatchClearsPersistedIgnoreNote(bool $closed): void
    {
        $sqlite = new BankSqlitePdo();
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE bank_transactions (id INTEGER, statement_id INTEGER, match_status TEXT, matched_invoice_id INTEGER, ignore_note TEXT, posted_at TEXT, matched_at TEXT, matched_by INTEGER, ignore_origin TEXT)');
        $sqlite->exec("INSERT INTO bank_transactions VALUES (1, 2, 'ignored', NULL, 'Test note', '2099-01-01', NULL, NULL, 'manual')");
        $sqlite->exec("ALTER TABLE bank_transactions ADD COLUMN source TEXT DEFAULT 'statement'");
        $sqlite->exec('CREATE TABLE bank_notice_confirmations (supplier_id INTEGER, notice_transaction_id INTEGER, state TEXT, reason TEXT, authoritative_transaction_id INTEGER)');
        $sqlite->exec('CREATE TABLE bank_statements (id INTEGER, matched_count INTEGER)');
        $sqlite->exec('INSERT INTO bank_statements VALUES (2, 0)');
        $sqlite->exec('CREATE TABLE activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)');

        $sqlite->exec('CREATE TABLE invoice_payments (id INTEGER, source TEXT, bank_transaction_id INTEGER, tax_document_invoice_id INTEGER)');
        $sqlite->exec('CREATE TABLE invoices (id INTEGER, status TEXT)');
        $sqlite->exec('CREATE TABLE payment_matches (bank_transaction_id INTEGER, supplier_id INTEGER, purchase_invoice_id INTEGER, invoice_id INTEGER)');

        // MariaDB ownership SQL is covered separately; all writes execute against SQLite.
        $pdo = $this->scopedPdo($sqlite);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $reflection = new \ReflectionClass(BankStatementAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'logger' => new ActivityLogger($db), 'ipMatcher' => new IpMatcher()] as $name => $value) {
            $reflection->getProperty($name)->setValue($action, $value);
        }
        $paymentsReflection = new \ReflectionClass(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
        $payments = $paymentsReflection->newInstanceWithoutConstructor();
        $paymentsReflection->getProperty('db')->setValue($payments, $db);
        $reflection->getProperty('payments')->setValue($action, $payments);
        $reflection->getProperty('ownership')->setValue($action, new \MyInvoice\Repository\BankStatementOwnershipResolver($db));
        $posting = $this->postingStub($sqlite);
        if ($closed) {
            $posting->method('releaseMatch')->willThrowException(new \MyInvoice\Service\Accounting\PostingException('period_closed', 'Test closed period', 409));
        }
        $reflection->getProperty('bankPosting')->setValue($action, $posting);
        $reflection->getProperty('release')->setValue($action, new \MyInvoice\Service\Bank\BankTransactionReleaseService(
            $db,
            $payments,
            $posting,
            // Zrušení párování se politiky automatiky neptá (jen předběžná kontrola smazání výpisu).
            (new \ReflectionClass(\MyInvoice\Service\Accounting\AutoPostingPolicyService::class))->newInstanceWithoutConstructor(),
        ));
        $matchReflection = new \ReflectionClass(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class);
        $reflection->getProperty('matchV2')->setValue($action, $matchReflection->newInstanceWithoutConstructor());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank-transactions/1/unmatch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic bank staff', 'staff', true, ['bank' => 1, 'bank.match' => 2]));
        $response = $action->unmatch($request, new Response(), ['id' => 1]);
        self::assertSame($closed ? 409 : 200, $response->getStatusCode(), (string) $response->getBody());
        $row = $sqlite->query('SELECT match_status, ignore_note FROM bank_transactions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($closed ? 'ignored' : 'unmatched', $row['match_status']);
        self::assertSame($closed ? 'Test note' : null, $row['ignore_note']);
    }

    /** Ownership resolver SQL is covered on MariaDB; mutation SQL runs on real fixture rows. */
    private function scopedPdo(BankSqlitePdo $sqlite): PDO
    {
        $sqlite->exec('CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, status TEXT, starts_on TEXT)');
        $scope = $this->createStub(\PDOStatement::class);
        $scope->method('execute')->willReturn(true);
        $scope->method('fetchColumn')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($scope, $sqlite) {
            if (str_contains($sql, 'CASE WHEN bs.supplier_id')) {
                if (str_contains($sql, 'SELECT bt.*')) {
                    return $sqlite->prepare('SELECT * FROM bank_transactions WHERE id=? AND ?=?');
                }
                if (str_contains($sql, 'SELECT bt.id, bt.statement_id, bt.matched_invoice_id')) {
                    return $sqlite->prepare('SELECT id, statement_id, matched_invoice_id, posted_at, match_status FROM bank_transactions WHERE id=? AND ?=?');
                }
                return $scope;
            }
            return $sqlite->prepare($sql);
        });
        $pdo->method('query')->willReturnCallback(fn (string $sql) => $sqlite->query($sql));
        $pdo->method('exec')->willReturnCallback(fn (string $sql) => $sqlite->exec($sql));
        $pdo->method('beginTransaction')->willReturnCallback(fn () => $sqlite->beginTransaction());
        $pdo->method('commit')->willReturnCallback(fn () => $sqlite->commit());
        $pdo->method('rollBack')->willReturnCallback(fn () => $sqlite->rollBack());
        $pdo->method('inTransaction')->willReturnCallback(fn () => $sqlite->inTransaction());
        return $pdo;
    }

    public function testMovementListSerializesIgnoreNote(): void
    {
        $row = array_fill_keys([
            'variable_symbol', 'constant_symbol', 'specific_symbol', 'counterparty_account',
            'counterparty_bank', 'counterparty_name', 'description', 'bank_ref', 'matched_invoice_id',
            'matched_varsymbol', 'matched_invoice_amount', 'matched_client_name', 'matched_purchase_invoice_id',
            'matched_purchase_ref', 'matched_vendor_name', 'matched_at', 'bank_code', 'account_label',
        ], null);
        $row += ['id' => 1, 'transaction_source' => 'statement', 'statement_id' => 2,
            'posted_at' => '2099-01-01', 'amount' => -25, 'currency' => 'CZK', 'account_number' => '1000000005',
            'match_status' => 'unmatched', 'ignore_note' => 'Test note', 'ignore_origin' => null, 'period_closed' => false];
        $count = $this->createStub(\PDOStatement::class);
        $count->method('fetchColumn')->willReturn(1);
        $list = $this->createStub(\PDOStatement::class);
        $list->method('fetchAll')->willReturn([$row]);
        $payments = $this->createStub(\PDOStatement::class);
        $payments->method('fetchAll')->willReturn([]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($count, $list, $payments) {
            if (str_contains($sql, 'SELECT COUNT(*)')) return $count;
            if (str_contains($sql, 'bt.source AS transaction_source')) {
                self::assertStringContainsString('bt.ignore_note', $sql);
                return $list;
            }
            return $payments;
        });
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $result = (new \MyInvoice\Repository\BankPostingSuggestionRepository($db))->paginateUnposted(7, 50, 0);
        self::assertSame('Test note', $result['items'][0]['ignore_note']);
    }

    private function postingStub(PDO $sqlite): \MyInvoice\Service\Accounting\Bank\BankPostingService
    {
        $posting = $this->createStub(\MyInvoice\Service\Accounting\Bank\BankPostingService::class);
        $posting->method('lockTransaction')->willReturnCallback(static function (int $supplierId, int $transactionId) use ($sqlite): ?array {
            self::assertSame(7, $supplierId);
            self::assertSame(1, $transactionId);
            self::assertTrue($sqlite->inTransaction());
            $row = $sqlite->query('SELECT * FROM bank_transactions WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        });
        return $posting;
    }

    private function createAllocationTables(PDO $sqlite): void
    {
        foreach (['invoice_payments', 'payment_matches', 'payroll_payment_matches', 'other_item_allocations'] as $table) {
            $sqlite->exec('CREATE TABLE ' . $table . ' (bank_transaction_id INTEGER)');
        }
        $sqlite->exec('CREATE TABLE tax_advance_schedules (matched_transaction_id INTEGER)');
        $sqlite->exec('CREATE TABLE bank_transfer_matches (in_transaction_id INTEGER, out_transaction_id INTEGER)');
        $sqlite->exec('CREATE TABLE gopay_clearings (bank_transaction_id INTEGER, payout_match_transaction_id INTEGER)');
    }
}
