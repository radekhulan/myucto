<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Převod syntetické agendy Money S3 do firmy v MyÚčtu — celý řetěz nad skutečnou DB:
 * záloha → deník, doklady, banka, pokladna → vazby a úhrady → uzávěrka 2024 →
 * rekonciliace. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
abstract class MoneyS3ImportTestCase extends TestCase
{
    use OwnedSandboxDatabase;
    protected \Psr\Container\ContainerInterface $container;
    protected Connection $db;
    protected MoneyS3Importer $importer;
    protected MoneyS3ImportRepository $map;
    protected AutoPostingPolicyService $policy;
    protected string $tmp = '';
    protected int $userId = 0;
    protected int $anyCurrencyId = 0;
    protected int $vatRateId = 0;
    protected int $czId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $ownsTransactions = in_array('fulltext-transactions', $this->groups(), true);
        try {
            $container = Bootstrap::buildContainer();
            if ($ownsTransactions) {
                $container->set(Config::class, self::sandboxConfig('money_preview'));
                $container->set(Connection::class, self::sandboxConnection('money_preview'));
            }
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(MoneyS3Importer::class);
            $this->map = $container->get(MoneyS3ImportRepository::class);
            $this->policy = $container->get(AutoPostingPolicyService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');
        file_put_contents($this->tmp . '/predvaha-2024.csv', SyntheticAgenda::trialBalanceCsv2024());

        if (!$ownsTransactions) {
            $pdo->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /** @return array<string,array<string,mixed>> */
    protected function assetsByInventory(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM assets WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'inventory_number');
    }

    /** @return array<string,array<int,float>> druh => rok => odpis */
    protected function entries(int $assetId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT kind, fiscal_year, amount FROM depreciation_entries WHERE asset_id = ? ORDER BY kind, fiscal_year');
        $stmt->execute([$assetId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['kind']][(int) $r['fiscal_year']] = (float) $r['amount'];
        }
        ksort($out);
        return $out;
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    protected function supplier(string $ico = SyntheticAgenda::ICO, string $mode = 'tax_evidence'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, ?)'
        )->execute([SyntheticAgenda::NAME, $this->czId, $ico, $this->anyCurrencyId, $this->vatRateId, $mode]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @param array<int,string> $reports */
    protected function import(int $supplierId, string $mode = ImportOptions::MODE_IMPORT, array $reports = []): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions($mode, true, null, [], $reports));
    }

    protected function backup(): Ms3Backup
    {
        return Ms3Backup::extract($this->tmp . '/agenda.lz', $this->tmp . '/agenda-' . bin2hex(random_bytes(3)));
    }

    protected function rowCount(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    protected function snapshotCounts(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'cash_documents',
            'cash_registers', 'bank_statements', 'clients', 'payment_matches', 'posting_rules', 'journal_entry_document_links'] as $t) {
            $out[$t] = $this->rowCount($t, $supplierId);
        }
        $tx = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?');
        $tx->execute([$supplierId]);
        $out['bank_transactions'] = (int) $tx->fetchColumn();
        return $out;
    }

    protected function periodId(int $supplierId, int $year): int
    {
        return (int) $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, $year)['id'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function container(string $class): object
    {
        return $this->container->get($class);
    }

    protected function explain(ImportProtocol $protocol): string
    {
        return json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
