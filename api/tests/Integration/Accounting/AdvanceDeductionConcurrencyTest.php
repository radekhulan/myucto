<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AdvanceDeductionService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dvě souběžná čerpání téže zálohy různými konečnými fakturami. Druhé čeká na zámek zálohy,
 * který drží první; po jeho potvrzení musí počítat s jeho čerpáním, jinak zálohu vyčerpá
 * nad zaplacenou částku (saldokonto by to schovalo ořezem poměru).
 *
 * Souběžné čerpání běží v samostatném procesu ({@see AdvanceDeductionLockWorker}) a commituje,
 * proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class AdvanceDeductionConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'adv_deduct_lock';
    private const YEAR = 2099;

    public function testWaitingDeductionSeesDeductionCommittedWhileItWaited(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
        $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
        $container->set(Connection::class, $db);
        try {
            $fixture = $this->fixture($db, $container);
        } catch (\Throwable $e) {
            // Otevřená transakce by držela zámek metadat a úklid sandboxu by na něj čekal.
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
            throw $e;
        }
        $service = $container->get(AdvanceDeductionService::class);
        $before = $service->candidates($fixture['supplier'], 'purchase_invoice', $fixture['advance'], '314', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(1000.0, $before['document']['remaining'], 0.001);

        $ready = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adv-deduct-' . bin2hex(random_bytes(4));
        $config = self::sandboxConfig(self::SANDBOX);
        $env = getenv() + [
            'ADV_WORKER_HOST' => (string) $config->get('db.host'),
            'ADV_WORKER_PORT' => (string) $config->get('db.port', 3306),
            'ADV_WORKER_DB' => (string) $config->get('db.name'),
            'ADV_WORKER_USER' => (string) $config->get('db.user'),
            'ADV_WORKER_PASS' => (string) $config->get('db.pass', ''),
            'ADV_WORKER_SUPPLIER' => (string) $fixture['supplier'],
            'ADV_WORKER_ADVANCE' => (string) $fixture['advance'],
            'ADV_WORKER_FINAL' => (string) $fixture['final_a'],
            'ADV_WORKER_AMOUNT' => '600.00',
            'ADV_WORKER_READY' => $ready,
        ];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/AdvanceDeductionLockWorker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);
        try {
            $deadline = microtime(true) + 20;
            while (!is_file($ready) && microtime(true) < $deadline) {
                usleep(50000);
                clearstatcache(true, $ready);
            }
            if (!is_file($ready)) {
                self::fail('Souběžné čerpání nezamklo zálohu: ' . stream_get_contents($pipes[2]));
            }

            try {
                $service->deduct($fixture['supplier'], [
                    ['doc_type' => 'purchase_invoice', 'advance_id' => $fixture['advance'], 'final_id' => $fixture['final_b'], 'amount' => 600.0],
                ], null);
                self::fail('Čerpání přečerpalo zálohu, protože nepočítalo se souběžně potvrzeným čerpáním.');
            } catch (JournalSettlementException $e) {
                self::assertSame('advance_overdeducted', $e->errorCode, $e->getMessage());
            }
        } finally {
            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe);
            $exit = proc_close($process);
            @unlink($ready);
        }
        self::assertSame(0, $exit, $stderr);
        $sum = $db->pdo()->prepare('SELECT SUM(amount) FROM purchase_advance_deductions WHERE supplier_id = ? AND advance_invoice_id = ?');
        $sum->execute([$fixture['supplier'], $fixture['advance']]);
        self::assertEqualsWithDelta(600.0, (float) $sum->fetchColumn(), 0.001);
        $db->close();
    }

    /** @return array{supplier:int, advance:int, final_a:int, final_b:int} */
    private function fixture(Connection $db, \Psr\Container\ContainerInterface $container): array
    {
        $pdo = $db->pdo();
        $supplier = (int) $pdo->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplier === 0 || $currency === 0 || $country === 0 || $user === 0) {
            self::markTestSkipped('Chybí základní data (firma v podvojném účetnictví, měna, země, uživatel).');
        }
        $pdo->beginTransaction();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplier);
        $periods = $container->get(AccountingPeriodRepository::class);
        $period = $periods->findByYear($supplier, self::YEAR);
        $periodId = $period !== null ? (int) $period['id'] : $periods->create($supplier, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel souběhu záloh", "Test 1", "Praha", "11000", ?, "CZ12345678", "t@example.com", "cs", ?, 1, 1)'
        )->execute([$supplier, $country, $currency]);
        $vendor = (int) $pdo->lastInsertId();
        $document = function (string $number, string $kind, float $total) use ($pdo, $supplier, $vendor, $currency, $user): int {
            $issue = self::YEAR . '-06-10';
            $pdo->prepare(
                'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, varsymbol, vendor_snapshot, document_kind, vat_deduction,
                    issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "received", ?)'
            )->execute([$supplier, $vendor, $number, $number, json_encode(['company_name' => 'Syntetický dodavatel']), $kind, $issue, $issue, $issue, $issue, $currency, $total, $total, $user]);
            return (int) $pdo->lastInsertId();
        };
        $map = $container->get(ChartOfAccountsRepository::class)->codeToIdMap($supplier);
        $journal = $container->get(JournalEntryRepository::class);
        $entry = function (string $type, int $source, array $lines) use ($journal, $map, $supplier, $periodId, $user): void {
            $journal->insert([
                'supplier_id' => $supplier, 'period_id' => $periodId, 'entry_date' => self::YEAR . '-07-01', 'document_no' => 'SOUBEH-' . $source,
                'description' => 'Syntetický zápis', 'source_type' => $type, 'source_id' => $source,
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $user,
            ], array_map(static fn (array $l): array => ['account_id' => $map[$l[0]]['id'], 'side' => $l[1], 'amount' => $l[2]], $lines));
        };
        $advance = $document('ZF-SOUBEH', 'advance', 1000.0);
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, "soubeh.gpc", ?, "1000000005", "0100", "CZK", ?, ?)'
        )->execute([$supplier, hash('sha256', uniqid('adv', true)), self::YEAR . '-06-15', $user]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, 'statement', ?, -1000, 'CZK', 'Protistrana', 'Záloha', 'unmatched')"
        )->execute([$statement, self::YEAR . '-06-15']);
        $tx = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type) VALUES (?, ?, ?, 1000, 'manual')")
            ->execute([$supplier, $tx, $advance]);
        $entry('bank', $tx, [['314', 'debit', 1000.0], ['221', 'credit', 1000.0]]);
        $finalA = $document('FA-SOUBEH-A', 'invoice', 1000.0);
        $entry('purchase_invoice', $finalA, [['518', 'debit', 1000.0], ['321', 'credit', 1000.0], ['321', 'debit', 600.0], ['314', 'credit', 600.0]]);
        $finalB = $document('FA-SOUBEH-B', 'invoice', 1000.0);
        $entry('purchase_invoice', $finalB, [['518', 'debit', 1000.0], ['321', 'credit', 1000.0], ['321', 'debit', 600.0], ['314', 'credit', 600.0]]);
        $pdo->commit();
        return ['supplier' => $supplier, 'advance' => $advance, 'final_a' => $finalA, 'final_b' => $finalB];
    }
}
