<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CreditRefundConcurrencyTest extends TestCase
{
    private const FILE_MARKER = '__credit_refund_concurrency__';
    private const CLIENT_MARKER = '__credit_refund_concurrency_client__';
    private const ISSUED_VS = '209991001';
    private const PURCHASE_VS = '209991002';
    private const AMOUNT = 608.0;

    private Connection $db;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $countryId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open není dostupný.');
        }
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currency = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($currency === false) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->currencyId = (int) $currency['id'];
        $this->supplierId = (int) $currency['supplier_id'];
        $this->account = (string) $currency['account_number'];
        $this->bankCode = $currency['bank_code'] !== null ? (string) $currency['bank_code'] : null;
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí country/user pro integrační test.');
        }

        $this->cleanup();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, self::CLIENT_MARKER, $this->countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->cleanup();
        }
    }

    public function testIssuedRefundUsesCurrentReadsWithSnapshotIsolationOff(): void
    {
        $this->assertIssuedRace(false);
    }

    public function testIssuedRefundRetriesAbortedTransactionWithSnapshotIsolationOn(): void
    {
        $this->assertIssuedRace(true);
    }

    public function testPurchaseRefundUsesCurrentReadsWithSnapshotIsolationOff(): void
    {
        $this->assertPurchaseRace(false);
    }

    public function testPurchaseRefundRetriesAbortedTransactionWithSnapshotIsolationOn(): void
    {
        $this->assertPurchaseRace(true);
    }

    private function assertIssuedRace(bool $snapshotIsolation): void
    {
        [$documentId, $transactionIds] = $this->seedIssued();

        $this->runRace('invoices', 'issued', $documentId, $transactionIds, $snapshotIsolation);

        $pdo = $this->db->pdo();
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE invoice_id = {$documentId}"
        )->fetchColumn());
        self::assertEqualsWithDelta(self::AMOUNT, (float) $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE invoice_id = {$documentId}"
        )->fetchColumn(), 0.001);
    }

    private function assertPurchaseRace(bool $snapshotIsolation): void
    {
        [$documentId, $transactionIds] = $this->seedPurchase();

        $this->runRace('purchase_invoices', 'purchase', $documentId, $transactionIds, $snapshotIsolation);

        $pdo = $this->db->pdo();
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = {$documentId}"
        )->fetchColumn());
        self::assertEqualsWithDelta(self::AMOUNT, (float) $pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE purchase_invoice_id = {$documentId}"
        )->fetchColumn(), 0.001);
    }

    /** @return array{int,list<int>} */
    private function seedIssued(): array
    {
        $pdo = $this->db->pdo();
        $date = '2099-08-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, status,
                 total_without_vat, total_vat, total_with_vat, paid_total, created_by)
             VALUES ('credit_note', ?, ?, ?, ?, ?, ?, ?, 1, ?, 'issued', -608, 0, -608, 0, ?)"
        )->execute([
            self::ISSUED_VS, $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $date, $this->userId,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $statementId = $this->statement(self::ISSUED_VS, $date);

        return [$documentId, [
            $this->transaction($statementId, self::ISSUED_VS, -self::AMOUNT, $date),
            $this->transaction($statementId, self::ISSUED_VS, -self::AMOUNT, $date),
        ]];
    }

    /** @return array{int,list<int>} */
    private function seedPurchase(): array
    {
        $pdo = $this->db->pdo();
        $date = '2099-08-16';
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, 'credit_note', ?, ?, ?, ?, ?, '{}', -608, -608, 'received', ?)"
        )->execute([
            $this->supplierId, $this->clientId, self::PURCHASE_VS, self::PURCHASE_VS,
            $date, $date, $date, $date, $this->currencyId, $this->userId,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $statementId = $this->statement(self::PURCHASE_VS, $date);

        return [$documentId, [
            $this->transaction($statementId, self::PURCHASE_VS, self::AMOUNT, $date),
            $this->transaction($statementId, self::PURCHASE_VS, self::AMOUNT, $date),
        ]];
    }

    private function statement(string $suffix, string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, "CZK", ?)'
        )->execute([
            self::FILE_MARKER . $suffix . '.gpc',
            hash('sha256', self::FILE_MARKER . $suffix),
            $this->account,
            $this->bankCode,
            $date,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function transaction(int $statementId, string $varsymbol, float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, "CZK", ?)'
        )->execute([$statementId, $date, $amount, $varsymbol]);

        return (int) $pdo->lastInsertId();
    }

    /** @param list<int> $transactionIds */
    private function runRace(
        string $table,
        string $documentKind,
        int $documentId,
        array $transactionIds,
        bool $snapshotIsolation,
    ): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
        $lock->execute([$documentId]);
        self::assertSame($documentId, (int) $lock->fetchColumn());

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'credit-refund-race-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $barrier = $directory . DIRECTORY_SEPARATOR . 'go';
        $workers = [];
        try {
            foreach ($transactionIds as $index => $transactionId) {
                $workers[] = $this->startWorker(
                    $transactionId,
                    $documentKind,
                    $documentId,
                    $barrier,
                    $directory,
                    $index,
                    $snapshotIsolation,
                );
            }
            foreach ($workers as &$worker) {
                $worker['connection_id'] = $this->waitForReady($worker, $snapshotIsolation);
            }
            unset($worker);
            touch($barrier);
            foreach ($workers as $worker) {
                $this->waitForLock($pdo, $worker);
            }
            $pdo->commit();

            $results = [];
            foreach ($workers as &$worker) {
                $results[] = $this->collectWorker($worker);
            }
            unset($worker);
            $workers = [];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
            }
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        self::assertSame(1, count(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'auto_exact',
        )), json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame(1, count(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'unmatched',
        )), json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([], array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['status'] ?? null) === 'worker_error',
        )), json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $abortedAttempts = array_sum(array_map(
            static fn (array $result): int => (int) ($result['aborted_attempts'] ?? 0),
            $results,
        ));
        if ($snapshotIsolation) {
            self::assertGreaterThanOrEqual(1, $abortedAttempts, json_encode($results, JSON_THROW_ON_ERROR));
            self::assertLessThanOrEqual(3, max(array_map(
                static fn (array $result): int => (int) ($result['attempts'] ?? 0),
                $results,
            )));
        } else {
            self::assertSame(0, $abortedAttempts, json_encode($results, JSON_THROW_ON_ERROR));
            self::assertSame([1, 1], array_map(
                static fn (array $result): int => (int) ($result['attempts'] ?? 0),
                $results,
            ));
        }
    }

    /** @return array{process:resource,pipes:array<int,resource>,ready:string,result:string,connection_id:int} */
    private function startWorker(
        int $transactionId,
        string $documentKind,
        int $documentId,
        string $barrier,
        string $directory,
        int $index,
        bool $snapshotIsolation,
    ): array
    {
        $ready = $directory . DIRECTORY_SEPARATOR . 'ready-' . $index . '.json';
        $result = $directory . DIRECTORY_SEPARATOR . 'result-' . $index . '.json';
        $worker = dirname(__DIR__, 2) . '/Support/CreditRefundMatchWorker.php';
        $database = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertNotSame('', $database);
        self::assertStringEndsWith('_test', $database);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['MYINVOICE_DB_NAME'] = $database;
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $worker,
                (string) $transactionId,
                $documentKind,
                (string) $documentId,
                $barrier,
                $ready,
                $result,
                $snapshotIsolation ? 'on' : 'off',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'ready' => $ready,
            'result' => $result,
            'connection_id' => 0,
        ];
    }

    /** @param array{process:resource,pipes:array<int,resource>,ready:string,result:string,connection_id:int} $worker */
    private function waitForReady(array $worker, bool $snapshotIsolation): int
    {
        $deadline = microtime(true) + 20.0;
        do {
            clearstatcache(true, $worker['ready']);
            if (is_file($worker['ready'])) {
                $data = json_decode((string) file_get_contents($worker['ready']), true);
                if (is_array($data) && (int) ($data['connection_id'] ?? 0) > 0) {
                    self::assertSame('REPEATABLE-READ', $data['isolation'] ?? null);
                    self::assertSame($snapshotIsolation, $data['snapshot_isolation'] ?? null);
                    self::assertTrue($data['snapshot_established'] ?? false);
                    return (int) $data['connection_id'];
                }
            }
            if (!proc_get_status($worker['process'])['running']) {
                self::fail('Worker skončil před zveřejněním databázové session.');
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Worker nezveřejnil databázovou session do 20 sekund.');
    }

    /** @param array{process:resource,pipes:array<int,resource>,ready:string,result:string,connection_id:int} $worker */
    private function waitForLock(PDO $pdo, array $worker): void
    {
        $probe = $pdo->prepare(
            'SELECT
                EXISTS(
                    SELECT 1 FROM information_schema.INNODB_LOCK_WAITS w
                    JOIN information_schema.INNODB_TRX waiter ON waiter.trx_id = w.requesting_trx_id
                    WHERE waiter.trx_mysql_thread_id = ?
                ) AS has_lock_wait,
                EXISTS(
                    SELECT 1 FROM information_schema.INNODB_TRX waiter
                    WHERE waiter.trx_mysql_thread_id = ? AND waiter.trx_state = "LOCK WAIT"
                ) AS trx_in_lock_wait,
                COALESCE((SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?), "") AS current_statement'
        );
        $deadline = microtime(true) + 15.0;
        $statementSince = null;
        do {
            $probe->execute([$worker['connection_id'], $worker['connection_id'], $worker['connection_id']]);
            $state = $probe->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int) ($state['has_lock_wait'] ?? 0) === 1 || (int) ($state['trx_in_lock_wait'] ?? 0) === 1) {
                return;
            }
            $currentStatement = trim((string) preg_replace('/\s+/', ' ', (string) ($state['current_statement'] ?? '')));
            $periodWait = preg_match('/^SELECT id, ?status FROM accounting_periods WHERE supplier_id = (?:\?|\'?[0-9]+\'?) ORDER BY starts_on, ?id FOR UPDATE$/iD', $currentStatement) === 1;
            if ($periodWait || preg_match('/(?:FROM|UPDATE)\s+(?:purchase_)?invoices\b/i', $currentStatement) === 1) {
                $statementSince ??= microtime(true);
                if (microtime(true) - $statementSince >= 0.25) {
                    return;
                }
            } else {
                $statementSince = null;
            }
            clearstatcache(true, $worker['result']);
            if (is_file($worker['result'])) {
                self::fail('Worker dokončil párování před čekáním na zámek: ' . file_get_contents($worker['result']));
            }
            if (!proc_get_status($worker['process'])['running']) {
                self::fail('Worker skončil dřív, než začal čekat na zámek dobropisu.');
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('Worker nezačal do 15 sekund čekat na zámek dobropisu.');
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>,ready:string,result:string,connection_id:int} $worker
     * @return array<string,mixed>
     */
    private function collectWorker(array &$worker): array
    {
        $deadline = microtime(true) + 20.0;
        do {
            clearstatcache(true, $worker['result']);
            if (is_file($worker['result'])) {
                $result = json_decode((string) file_get_contents($worker['result']), true, flags: JSON_THROW_ON_ERROR);
                $this->terminateWorker($worker, false);
                return $result;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->terminateWorker($worker);
        self::fail('Worker nedokončil párování do 20 sekund.');
    }

    /** @param array{process:resource,pipes:array<int,resource>,ready:string,result:string,connection_id:int} $worker */
    private function terminateWorker(array &$worker, bool $terminate = true): void
    {
        if (is_resource($worker['process'])) {
            if ($terminate && proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'invoice'
                AND entity_id IN (SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?)"
        )->execute([$this->supplierId, self::ISSUED_VS]);
        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'purchase_invoice'
                AND entity_id IN (
                    SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?
                )"
        )->execute([$this->supplierId, self::PURCHASE_VS]);
        $pdo->prepare(
            'DELETE pm FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE ?'
        )->execute([self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')
            ->execute([self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')
            ->execute([$this->supplierId, self::ISSUED_VS]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?')
            ->execute([$this->supplierId, self::PURCHASE_VS]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::CLIENT_MARKER]);
    }
}
