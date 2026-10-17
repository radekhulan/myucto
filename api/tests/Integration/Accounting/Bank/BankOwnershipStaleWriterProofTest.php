<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class BankOwnershipStaleWriterProofTest extends TestCase
{
    use IsolatedSupplierTrait;
    private string $ownedSchema;
    private static ?string $classSchema = null;
    private static ?PDO $classServer = null;
    private PDO $server;
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->ownedSchema = self::$classSchema ?? ('myucto_bank_owner_proof_' . bin2hex(random_bytes(5)) . '_test');
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (self::$classSchema === null) {
            $cloner = new DatabaseCloner($this->server);
            $plan = $cloner->plan($source);
            $cloner->copyTables($plan, $this->ownedSchema, static function (): void {});
            $cloner->finalize($plan, $this->ownedSchema);
            self::$classSchema = $this->ownedSchema;
            self::$classServer = $this->server;
        }
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$classSchema !== null) {
            self::assertMatchesRegularExpression('/^myucto_bank_owner_proof_[a-f0-9]{10}_test$/D', self::$classSchema);
            self::$classServer->exec('DROP DATABASE ' . DatabaseCloner::quote(self::$classSchema));
            self::$classSchema = null;
            self::$classServer = null;
        }
    }

    private function fixture(): array
    {
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->beginTransaction();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $other = $this->createIsolatedSupplier($pdo, $supplier);
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$supplier]);
        (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($a))->seedForSupplier($supplier);
        (new \MyInvoice\Repository\AccountingPeriodRepository($a))->create($supplier, 2096, '2096-01-01', '2096-12-31');
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,transaction_count,matched_count)
            VALUES (?,'synthetic.gpc',?,'19-1000000005','0100','CZK','2096-06-01',1,0)")->execute([$supplier, hash('sha256', (string) $supplier)]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description)
            VALUES (?,'2096-06-01',49.99,'CZK','Synthetic')")->execute([$statement]);
        $transaction = (int) $pdo->lastInsertId();
        $rule = (new BankPostingRuleRepository($a))->insert($supplier, [
            'name' => 'Synthetic', 'direction' => 'incoming', 'debit_account_code' => '221',
            'credit_account_code' => '648', 'mode' => 'suggest', 'message_contains' => 'Synthetic',
        ], $actor);
        $suggestion = (new BankPostingSuggestionRepository($a))->createIfNoPending([
            'supplier_id' => $supplier, 'bank_transaction_id' => $transaction, 'rule_id' => $rule,
            'source' => 'rule', 'debit_account_code' => '221', 'credit_account_code' => '648', 'amount' => 49.99,
        ])['id'];
        $pdo->commit();
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $container->set(Connection::class, $a);
        return [$supplier, $other, $actor, $statement, $transaction, $rule, $suggestion, $container];
    }

    #[DataProvider('writers')]
    public function testActualWriterRejectsChangedAuthoritativeOwner(string $writer): void
    {
        [$supplier, $other, $actor, $statement, $transaction, $rule, $suggestion, $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $service = $container->get(BankPostingService::class);
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        $b->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 49.99], ['account_code' => '648', 'side' => 'credit', 'amount' => 49.99]];
        try {
            $result = match ($writer) {
                'manual' => $service->postManual($supplier, $transaction, ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]),
                'rule' => $service->applyRuleToTransaction($supplier, $transaction, $rule, $actor),
                'approve' => $service->approveSuggestion($supplier, $suggestion, ['user_id' => $actor]),
                'matched' => $service->postMatched($supplier, $transaction, $actor),
                'repost' => $service->repostMatchedInPlace($supplier, $transaction, $actor),
                'document' => $container->get(\MyInvoice\Service\Accounting\DocumentRepostService::class)->repost($supplier, 'bank', $transaction, $lines, ['user_id' => $actor]),
            };
            if ($writer === 'rule') {
                self::assertSame('transaction_not_found', $result['reason']);
            } elseif ($writer === 'matched') {
                self::assertNull($result);
            } else {
                self::fail('The old bank owner must be refused before any financial mutation.');
            }
        } catch (PostingException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertTrue($pdo->inTransaction(), 'An ordinary refusal preserves the caller transaction.');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $transaction)->fetchColumn());
        $pdo->rollBack();
    }

    public static function writers(): array
    {
        return array_combine(['manual', 'rule', 'approve', 'matched', 'repost', 'document'], array_map(static fn ($v) => [$v], ['manual', 'rule', 'approve', 'matched', 'repost', 'document']));
    }

    #[DataProvider('legacyChanges')]
    public function testLegacyNestedAuthorityUsesCurrentRows(string $change): void
    {
        [$supplier, $other, $actor, $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id=NULL WHERE id=?')->execute([$statement]);
        $insert = "INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code)
            VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic','19-1000000005','0100')";
        $pdo->prepare($insert)->execute([$supplier]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE currencies SET account_number=NULL WHERE id<>? AND account_number='19-1000000005'")->execute([$currency]);
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        match ($change) {
            'ambiguous' => $b->pdo()->prepare($insert)->execute([$other]),
            'moved' => $b->pdo()->prepare('UPDATE currencies SET supplier_id=? WHERE id=?')->execute([$other, $currency]),
            'deleted' => $b->pdo()->prepare('DELETE FROM currencies WHERE id=?')->execute([$currency]),
        };
        try {
            $container->get(BankPostingService::class)->postManual($supplier, $transaction, ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
            self::fail('Nested legacy ownership must not use the old RR snapshot.');
        } catch (PostingException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $transaction)->fetchColumn());
        $pdo->rollBack();
    }

    public static function legacyChanges(): array
    {
        return ['ambiguity' => ['ambiguous'], 'reassignment' => ['moved'], 'deletion' => ['deleted']];
    }

    public function testManualWriterUsesCurrentAmountAndDate(): void
    {
        [$supplier, , $actor, , $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        $b->pdo()->prepare("UPDATE bank_transactions SET amount=79.98,posted_at='2096-07-02' WHERE id=?")->execute([$transaction]);
        $result = $container->get(BankPostingService::class)->postManual($supplier, $transaction, ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
        $entry = (int) $result['entry_id'];
        self::assertSame('2096-07-02', $pdo->query('SELECT entry_date FROM journal_entries WHERE id=' . $entry)->fetchColumn());
        self::assertSame('79.98', $pdo->query("SELECT amount FROM journal_entry_lines WHERE entry_id=$entry AND side='debit'")->fetchColumn());
        $pdo->rollBack();
        self::assertSame(0, (int) $b->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $transaction)->fetchColumn());
    }

    #[DataProvider('abortWriters')]
    public function testSnapshotIsolationConflictAbortsWholeCallerTransaction(string $writer): void
    {
        [$supplier, $other, $actor, $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=1');
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        $pdo->prepare("UPDATE supplier SET city='Uncommitted' WHERE id=?")->execute([$supplier]);
        $b->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
        try {
            if ($writer === 'document') {
                $container->get(\MyInvoice\Service\Accounting\DocumentRepostService::class)->repost($supplier, 'bank', $transaction,
                    [['account_code' => '221', 'side' => 'debit', 'amount' => 49.99], ['account_code' => '648', 'side' => 'credit', 'amount' => 49.99]], ['user_id' => $actor]);
            } else {
                $container->get(BankPostingService::class)->postManual($supplier, $transaction, ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
            }
            self::fail('A current read must propagate the actual MariaDB snapshot conflict.');
        } catch (\PDOException $e) {
            self::assertSame(1020, (int) ($e->errorInfo[1] ?? 0));
        }
        self::assertFalse($pdo->inTransaction());
        self::assertNotSame('Uncommitted', $b->pdo()->query('SELECT city FROM supplier WHERE id=' . $supplier)->fetchColumn());
    }

    public static function abortWriters(): array
    {
        return [['manual'], ['document']];
    }

    #[DataProvider('actionMutations')]
    public function testActionRejectsChangedOwner(string $method): void
    {
        [$supplier, $other, $actor, $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $invoice = $this->invoice($pdo, $supplier, $actor);
        if ($method === 'unmatch') {
            $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id=?,match_status='manual' WHERE id=?")->execute([$invoice, $transaction]);
        }
        if ($method === 'unignore') {
            $pdo->prepare("UPDATE bank_transactions SET match_status='ignored',ignore_origin='manual' WHERE id=?")->execute([$transaction]);
        }
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/test')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withParsedBody(['invoice_id' => $invoice]);
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        $b->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
        $action = $container->get(\MyInvoice\Action\Bank\BankStatementAction::class);
        $response = $action->$method($request, new \Slim\Psr7\Response(), ['id' => $method === 'delete' ? $statement : $transaction]);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
    }

    private function invoice(PDO $pdo, int $supplier, int $actor): int
    {
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,dic,main_email,language,currency_default_id,is_customer,is_vendor) VALUES (?,'Synthetic','Test 1','Praha','11000',?,'','synthetic@example.test','cs',?,1,1)")->execute([$supplier, $country, $currency]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,reverse_charge,total_without_vat,total_vat,total_with_vat,paid_total,status,vat_classification_code,created_by)
            VALUES (?,'20960001','invoice',?,'2096-06-01','2096-06-01','2096-06-01',?,0,49.99,0,49.99,0,'issued','1',?)")->execute([$supplier, $client, $currency, $actor]);
        $invoice = (int) $pdo->lastInsertId();
        return $invoice;
    }

    public function testOldSnapshotCannotBypassNoticeGuardForNewConfirmedMovement(): void
    {
        [$supplier, , $actor, , $target, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->beginTransaction();
        $pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn();
        $other = $b->pdo();
        $other->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source)
            VALUES (?,'synthetic-late.eml',?,'19-1000000005','0100','CZK','2096-06-01','email_notice')")
            ->execute([$supplier, hash('sha256', 'late-notice-' . $supplier)]);
        $statement = (int) $other->lastInsertId();
        $reference = 'synthetic-late-confirmation-' . $supplier;
        $other->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,source,source_ref)
            VALUES (?,'2096-06-01',49.99,'CZK','email_notice',?)")->execute([$statement, $reference]);
        $notice = (int) $other->lastInsertId();
        $other->prepare("INSERT INTO bank_notice_confirmations(supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state,authoritative_transaction_id)
            VALUES (?,?,?,?,'confirmed',?)")->execute([$supplier, $notice, $reference, hash('sha256', $reference), $target]);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/test')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier);
        $response = $container->get(\MyInvoice\Action\Bank\BankStatementAction::class)
            ->ignore($request, new \Slim\Psr7\Response(), ['id' => $notice]);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($pdo->inTransaction());
        $current = $pdo->prepare('SELECT match_status, ignore_origin FROM bank_transactions WHERE id=? FOR UPDATE');
        $current->execute([$notice]);
        self::assertSame(['match_status' => 'unmatched', 'ignore_origin' => null], $current->fetch(PDO::FETCH_ASSOC));
        $pdo->rollBack();
    }

    public static function actionMutations(): array
    {
        return ['match' => ['manualMatch'], 'ignore' => ['ignore'], 'unmatch' => ['unmatch'], 'unignore' => ['unignore'], 'delete' => ['delete']];
    }

    public function testAcceptedMatchKeepsCurrentOwnerLockedThroughPayment(): void
    {
        [$supplier, $other, $actor, $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $invoice = $this->invoice($pdo, $supplier, $actor);
        $pdo->prepare("INSERT INTO bank_match_suggestions(supplier_id,bank_transaction_id,kind,reason,candidates_json,top_score)
            VALUES (?,?,'single','no_vs',?,0.9)")->execute([$supplier, $transaction, json_encode([
                ['type' => 'invoice', 'invoice_id' => $invoice, 'score' => 0.9],
            ], JSON_THROW_ON_ERROR)]);
        $suggestion = (int) $pdo->lastInsertId();
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        $blocked = false;
        $result = $container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)->accept(
            $suggestion, $supplier, $actor, 0,
            function () use ($b, $statement, $other, &$blocked): void {
                try {
                    $b->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
                } catch (\PDOException $e) {
                    self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
                    $blocked = true;
                }
            },
        );
        self::assertTrue($result['matched']);
        self::assertTrue($blocked, 'The bank owner changed between authorization and a real 49.99 invoice payment.');
        self::assertSame('49.99', $pdo->query('SELECT paid_total FROM invoices WHERE id=' . $invoice)->fetchColumn());
    }

    #[DataProvider('sourceChanges')]
    public function testManualWriterUsesCurrentSourceState(string $change, string $expected): void
    {
        [$supplier, , $actor, , $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $a->pdo()->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        match ($change) {
            'delete' => $b->pdo()->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$transaction]),
            'ignored' => $b->pdo()->prepare("UPDATE bank_transactions SET match_status='ignored' WHERE id=?")->execute([$transaction]),
            'notice' => $b->pdo()->prepare("UPDATE bank_transactions SET source='email_notice' WHERE id=?")->execute([$transaction]),
        };
        try {
            $container->get(BankPostingService::class)->postManual($supplier, $transaction, ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
            self::fail('Current source state must be checked before posting.');
        } catch (PostingException $e) {
            self::assertSame($expected, $e->errorCode);
        }
        self::assertSame(0, (int) $a->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $transaction)->fetchColumn());
        $a->pdo()->rollBack();
    }

    public static function sourceChanges(): array
    {
        return [['delete', 'not_found'], ['ignored', 'transaction_ignored'], ['notice', 'email_notice_provisional']];
    }

    public function testReadonlyPreviewDoesNotAcquireWriteLocks(): void
    {
        [$supplier, , , , $transaction, , , $container] = $this->fixture();
        [$a] = $this->connections;
        $service = $container->get(BankPostingService::class);
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 49.99], ['account_code' => '648', 'side' => 'credit', 'amount' => 49.99]];
        $expected = $service->prepareRepostLines($supplier, $transaction, $lines);
        $a->pdo()->exec('START TRANSACTION READ ONLY');
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        self::assertSame($expected, $service->prepareRepostLines($supplier, $transaction, $lines));
        self::assertIsArray($service->previewTransaction($supplier, $transaction));
        $a->pdo()->rollBack();
    }

    public function testAutomaticMatcherRejectsPreviousOwnerBeforePayment(): void
    {
        [$supplier, $other, , $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $invoice = $this->invoice($pdo, $supplier, $actor);
        $pdo->exec("UPDATE currencies SET account_number=NULL WHERE account_number='19-1000000005'");
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code)
            VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic','19-1000000005','0100')")->execute([$supplier]);
        $pdo->prepare("UPDATE bank_transactions SET variable_symbol='20960001' WHERE id=?")->execute([$transaction]);
        $pdo->beginTransaction();
        self::assertTrue((new BankStatementOwnershipResolver($a))->transactionOwned($transaction, $supplier));
        $b->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
        $result = $container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($transaction);
        self::assertSame('unmatched', $result['status']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $transaction)->fetchColumn());
        $pdo->rollBack();
    }

    #[DataProvider('recordedStatuses')]
    public function testRecordedMatcherStateDoesNotRequireCurrentAccountDiscovery(string $status, bool $legacy): void
    {
        [$supplier, , , $statement, $transaction, , , $container] = $this->fixture();
        [$a] = $this->connections;
        $a->pdo()->exec("UPDATE currencies SET account_number=NULL WHERE account_number='19-1000000005'");
        if ($legacy) $a->pdo()->prepare('UPDATE bank_statements SET supplier_id=NULL WHERE id=?')->execute([$statement]);
        $a->pdo()->prepare('UPDATE bank_transactions SET match_status=? WHERE id=?')->execute([$status, $transaction]);
        $result = $container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($transaction);
        self::assertSame($status, $result['status']);
        self::assertTrue($result['already_recorded']);
        self::assertSame(0, (int) $a->pdo()->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $transaction)->fetchColumn());
    }

    public static function recordedStatuses(): array
    {
        return [['ignored', false], ['manual', false], ['auto_exact', false], ['ignored', true], ['manual', true], ['auto_exact', true]];
    }

    public function testNativeDeadlockFromCurrentContextAbortsCallerTransaction(): void
    {
        [$supplier, , $actor, , $transaction, , , $container] = $this->fixture();
        [$a] = $this->connections;
        $pdo = $a->pdo();
        $table = 'test_bank_abort_' . bin2hex(random_bytes(6));
        $pdo->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $insert = $pdo->prepare("INSERT INTO {$table} VALUES (?,0)");
        for ($id = 1; $id <= 5002; ++$id) $insert->execute([$id]);
        $ready = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $table . '.ready';
        $root = dirname(__DIR__, 5);
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->ownedSchema;
        $worker = null;
        $property = new \ReflectionProperty(Connection::class, 'pdo');
        try {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE {$table} SET value=7 WHERE id=2");
            $worker = proc_open([PHP_BINARY, $root . '/api/tests/Fixtures/bank-abort-deadlock-worker.php', $root, $table, $ready],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            self::assertIsResource($worker);
            fclose($pipes[0]);
            $deadline = microtime(true) + 12;
            do {
                clearstatcache(true, $ready);
                $stage = is_file($ready) ? file_get_contents($ready) : '';
                if ($stage === 'ready') break;
                usleep(10000);
            } while (microtime(true) < $deadline && proc_get_status($worker)['running']);
            self::assertSame('ready', $stage);
            $original = null;
            $property->setValue($a, new BankOwnershipObservedPdo($pdo, static function (string $sql) use ($pdo, $table, &$original): void {
                if (str_contains($sql, 'FROM accounting_periods') && str_contains($sql, 'FOR UPDATE')) {
                    try {
                        $pdo->query("SELECT value FROM {$table} WHERE id=1 FOR UPDATE");
                    } catch (\PDOException $e) {
                        $original = $e;
                        throw $e;
                    }
                }
            }));
            try {
                $container->get(BankPostingService::class)->postManual($supplier, $transaction,
                    ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
                self::fail('The actual deadlock must reach the caller.');
            } catch (\PDOException $e) {
                self::assertSame(1213, (int) ($e->errorInfo[1] ?? 0));
                self::assertSame($original, $e);
            }
            self::assertFalse($pdo->inTransaction());
            // Protějšek commituje až po rollbacku volajícího, hodnotu lze číst teprve po jeho skončení.
            $deadline = microtime(true) + 12;
            while (proc_get_status($worker)['running'] && microtime(true) < $deadline) usleep(10000);
            self::assertSame(1, (int) $pdo->query("SELECT value FROM {$table} WHERE id=2")->fetchColumn());
        } finally {
            $property->setValue($a, $pdo);
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_resource($worker)) {
                $deadline = microtime(true) + 12;
                do {
                    $status = proc_get_status($worker);
                    if (!$status['running']) break;
                    usleep(10000);
                } while (microtime(true) < $deadline);
                if ($status['running']) proc_terminate($worker);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($worker);
                self::assertSame('', $error);
                self::assertSame("DEADLOCK_PEER_COMMITTED\n", $output);
            }
            if (is_file($ready)) unlink($ready);
            $pdo->exec("DROP TABLE {$table}");
        }
    }

    public static function periodLockWriters(): array
    {
        return ['posting' => ['posting'], 'notice mutation' => ['mutation'], 'manual confirmation' => ['confirmation']];
    }

    #[DataProvider('periodLockWriters')]
    public function testPeriodConflictPrecedesSourceLockAndPreservesOrdinaryCallerTransaction(string $writer): void
    {
        [$supplier, , $actor, $statement, $transaction, , , $container] = $this->fixture();
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        $notice = 0;
        if ($writer === 'confirmation') {
            $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source)
                VALUES (?,'synthetic.eml',?,'19-1000000005','0100','CZK','2096-06-01','email_notice')")
                ->execute([$supplier, hash('sha256', 'notice-' . $supplier)]);
            $noticeStatement = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,source,source_ref)
                VALUES (?,'2096-06-01',49.99,'CZK','email_notice',?)")
                ->execute([$noticeStatement, 'synthetic-period-order-' . $supplier]);
            $notice = (int) $pdo->lastInsertId();
        }
        $b->pdo()->beginTransaction();
        $b->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? FOR UPDATE')->execute([$supplier]);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE supplier SET city='Caller marker' WHERE id=?")->execute([$supplier]);
        try {
            if ($writer === 'mutation') {
                (new \MyInvoice\Service\Bank\BankNoticeMutationGuard($a))->mutate($supplier, $transaction, static fn () => null);
            } elseif ($writer === 'confirmation') {
                $container->get(\MyInvoice\Service\Bank\BankNoticeConfirmationService::class)
                    ->confirmManually($supplier, $notice, $transaction, $actor, 'Synthetic period lock test');
            } else {
                $container->get(BankPostingService::class)->postManual($supplier, $transaction,
                    ['debit_account_code' => '221', 'credit_account_code' => '648'], ['user_id' => $actor]);
            }
            self::fail('The held period must block before the bank source.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
        } catch (\MyInvoice\Service\Bank\BankNoticeConfirmationException $e) {
            self::assertSame('confirmation', $writer);
            self::assertSame('bank_busy', $e->errorCode);
        }
        self::assertTrue($pdo->inTransaction());
        $source = $b->pdo()->prepare('SELECT id FROM bank_transactions WHERE id=? FOR UPDATE NOWAIT');
        $source->execute([$transaction]);
        self::assertSame($transaction, (int) $source->fetchColumn());
        $owner = $b->pdo()->prepare('SELECT id FROM bank_statements WHERE id=? FOR UPDATE NOWAIT');
        $owner->execute([$statement]);
        self::assertSame($statement, (int) $owner->fetchColumn());
        self::assertSame('Caller marker', $pdo->query('SELECT city FROM supplier WHERE id=' . $supplier)->fetchColumn());
        $pdo->rollBack();
        $b->pdo()->rollBack();
    }

}


final class BankOwnershipObservedPdo extends PDO
{
    public function __construct(private PDO $actual, private \Closure $observe) {}
    public function prepare(string $query, array $options = []): \PDOStatement|false { ($this->observe)($query); return $this->actual->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false { return $fetchMode === null ? $this->actual->query($query) : $this->actual->query($query, $fetchMode, ...$fetchModeArgs); }
    public function exec(string $statement): int|false { return $this->actual->exec($statement); }
    public function beginTransaction(): bool { return $this->actual->beginTransaction(); }
    public function commit(): bool { return $this->actual->commit(); }
    public function rollBack(): bool { return $this->actual->rollBack(); }
    public function inTransaction(): bool { return $this->actual->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->actual->lastInsertId($name); }
    public function getAttribute(int $attribute): mixed { return $this->actual->getAttribute($attribute); }
    public function setAttribute(int $attribute, mixed $value): bool { return $this->actual->setAttribute($attribute, $value); }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return $this->actual->quote($string, $type); }
}
