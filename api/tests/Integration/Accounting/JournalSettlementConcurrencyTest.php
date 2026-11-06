<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dva souběžné rozpady různých řádků na tutéž fakturu. Druhý čeká na zámek dokladu, který
 * drží první; po jeho potvrzení musí počítat s jeho rozpadem, jinak fakturu přealokuje
 * (saldokonto to schová ořezem poměru, kontrola K3 ukáže zápornou odchylku).
 *
 * Souběžný požadavek běží v samostatném procesu ({@see SaldoAllocationLockWorker}) a commituje,
 * proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class JournalSettlementConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'saldo_alloc_lock';
    private const YEAR = 2099;

    public function testWaitingAllocationSeesAllocationCommittedWhileItWaited(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        // Bez snapshot isolation by REPEATABLE READ starý stav tiše přečetl (výchozí stav
        // instalací, které ho mají vypnutý); test tak ověřuje čtení, ne chybu snapshotu.
        $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
        // Výchozí úroveň produkce; lokální server může mít globálně jinou.
        $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
        $container->set(Connection::class, $db);
        $fixture = $this->fixture($db, $container);
        // Služba se sestaví předem: souběžný rozpad drží zámek jen pár sekund.
        $service = $container->get(JournalSettlementService::class);
        $service->candidates($fixture['supplier'], 'purchase_invoice', $fixture['doc'], '321', self::YEAR . '-12-31');

        $ready = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saldo-alloc-' . bin2hex(random_bytes(4));
        $config = self::sandboxConfig(self::SANDBOX);
        $env = getenv() + [
            'SALDO_WORKER_HOST' => (string) $config->get('db.host'),
            'SALDO_WORKER_PORT' => (string) $config->get('db.port', 3306),
            'SALDO_WORKER_DB' => (string) $config->get('db.name'),
            'SALDO_WORKER_USER' => (string) $config->get('db.user'),
            'SALDO_WORKER_PASS' => (string) $config->get('db.pass', ''),
            'SALDO_WORKER_SUPPLIER' => (string) $fixture['supplier'],
            'SALDO_WORKER_DOC' => (string) $fixture['doc'],
            'SALDO_WORKER_ENTRY' => (string) $fixture['entry_b'],
            'SALDO_WORKER_LINE_NO' => (string) $fixture['line_no_b'],
            'SALDO_WORKER_ACCOUNT' => (string) $fixture['account'],
            'SALDO_WORKER_AMOUNT' => '600.00',
            'SALDO_WORKER_READY' => $ready,
        ];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/SaldoAllocationLockWorker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 20;
            while (!is_file($ready) && microtime(true) < $deadline) {
                usleep(50000);
                clearstatcache(true, $ready);
            }
            // Chybový výstup workeru jen při selhání: čtení roury čeká až na jeho konec.
            if (!is_file($ready)) {
                self::fail('Souběžný rozpad nezamkl doklad: ' . stream_get_contents($pipes[2]));
            }

            try {
                $service->allocate($fixture['supplier'], [
                    ['line_id' => $fixture['line_a'], 'doc_type' => 'purchase_invoice', 'doc_id' => $fixture['doc'], 'amount' => 600.0],
                ], null);
                self::fail('Rozpad přealokoval fakturu, protože nepočítal se souběžně potvrzeným rozpadem.');
            } catch (JournalSettlementException $e) {
                self::assertSame('document_overallocated', $e->errorCode, $e->getMessage());
            }
        } finally {
            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe);
            $exit = proc_close($process);
            @unlink($ready);
        }
        self::assertSame(0, $exit, $stderr);
        $sum = $db->pdo()->prepare("SELECT SUM(amount) FROM journal_settlement_allocations WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id = ?");
        $sum->execute([$fixture['supplier'], $fixture['doc']]);
        self::assertEqualsWithDelta(600.0, (float) $sum->fetchColumn(), 0.001);
        $db->close();
    }

    /** @return array{supplier:int, doc:int, account:int, line_a:int, entry_b:int, line_no_b:int} */
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
             VALUES (?, "Syntetický dodavatel souběhu", "Test 1", "Praha", "11000", ?, "CZ12345678", "t@example.com", "cs", ?, 1, 1)'
        )->execute([$supplier, $country, $currency]);
        $vendor = (int) $pdo->lastInsertId();
        $issue = self::YEAR . '-06-10';
        $pdo->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, varsymbol, vendor_snapshot, document_kind, vat_deduction,
                issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "SOUBEH-1", "SOUBEH1", ?, "invoice", "full", ?, ?, ?, ?, ?, 0, 0, 1000, 0, 1000, "received", ?)'
        )->execute([$supplier, $vendor, json_encode(['company_name' => 'Syntetický dodavatel souběhu']), $issue, $issue, $issue, $issue, $currency, $user]);
        $doc = (int) $pdo->lastInsertId();
        $map = $container->get(ChartOfAccountsRepository::class)->codeToIdMap($supplier);
        $container->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $supplier, 'period_id' => $periodId, 'entry_date' => $issue, 'document_no' => 'PREDPIS-SOUBEH',
            'description' => 'Předpis', 'source_type' => 'purchase_invoice', 'source_id' => $doc,
            'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $user,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => 1000.0],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => 1000.0],
        ]);
        $posting = $container->get(PostingService::class);
        $manual = fn (string $description): int => $posting->postDocument($supplier, 'manual', null, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 600.0],
            ['account_code' => '395', 'side' => 'credit', 'amount' => 600.0],
        ], ['entry_date' => self::YEAR . '-07-01', 'description' => $description, 'posted_by' => $user]);
        $entryA = $manual('Zápočet souběh A');
        $entryB = $manual('Zápočet souběh B');
        $pdo->commit();
        $line = $pdo->prepare('SELECT id, line_no FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? AND account_id = ?');
        $line->execute([$supplier, $entryA, $map['321']['id']]);
        $a = $line->fetch(PDO::FETCH_ASSOC);
        $line->execute([$supplier, $entryB, $map['321']['id']]);
        $b = $line->fetch(PDO::FETCH_ASSOC);
        return [
            'supplier' => $supplier, 'doc' => $doc, 'account' => (int) $map['321']['id'],
            'line_a' => (int) $a['id'], 'entry_b' => $entryB, 'line_no_b' => (int) $b['line_no'],
        ];
    }
}
