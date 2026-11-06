<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate504;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\WorkerLockWaitTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class AccountingFrameworkServiceTest extends TestCase
{
    use IsolatedSupplierTrait;
    use WorkerLockWaitTrait;

    private Connection $db;
    private Config $config;
    private AccountingFrameworkRepository $repository;
    private AccountingFrameworkService $service;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $otherSupplierId;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }

        $container = Bootstrap::buildContainer();
        $this->config = $container->get(Config::class);
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma pro izolovaný test.');
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->insertFramework($this->supplierId, '1900-01-01', AccountingFrameworkService::CZ_500);
        $this->insertFramework($this->otherSupplierId, '1900-01-01', AccountingFrameworkService::CZ_500);

        $this->repository = new AccountingFrameworkRepository($this->db);
        $this->service = new AccountingFrameworkService($this->db, $this->repository);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($this->inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testExistingFixtureSupplierHasInitializedCz500Baseline(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT accounting_framework FROM supplier_accounting_frameworks
              WHERE supplier_id = ? AND effective_from = '1900-01-01'"
        );
        $stmt->execute([$this->sourceSupplierId]);

        self::assertSame(AccountingFrameworkService::CZ_500, $stmt->fetchColumn());
    }

    public function testHistoryUsesExactBoundaryAndDoesNotLeakAcrossTenants(): void
    {
        $this->insertFramework($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504);

        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2097-12-31'));
        self::assertSame(AccountingFrameworkService::CZ_504, $this->service->forDate($this->supplierId, '2098-01-01'));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->otherSupplierId, '2098-01-01'));
    }

    public function testMissingHistoryUsesSafeCz500Baseline(): void
    {
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-06-30'));
    }

    public function testChangeOutsidePeriodStartIsRejected(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');

        $this->expectFrameworkError('accounting_framework_period_start_required', function (): void {
            $this->service->changeFromPeriodStart(
                $this->supplierId,
                '2098-02-01',
                AccountingFrameworkService::CZ_500,
            );
        });
    }

    public function testCz504CanBeActivatedAtValidPeriodBoundaryAndChangedBack(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        self::assertTrue($this->service->changeFromPeriodStart($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2097-12-31'));
        self::assertSame(AccountingFrameworkService::CZ_504, $this->service->forDate($this->supplierId, '2098-01-01'));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->otherSupplierId, '2098-01-01'));
        self::assertCount(2, $this->repository->listForTenant($this->supplierId));
        self::assertFalse($this->service->changeFromPeriodStart($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertTrue($this->service->changeFromPeriodStart($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_500));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'));
    }

    public function testCz504ActivationKeepsPeriodAndPostedJournalGuards(): void
    {
        $period = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->expectFrameworkError('accounting_framework_period_start_required', fn () =>
            $this->service->changeFromPeriodStart($this->supplierId, '2098-02-01', AccountingFrameworkService::CZ_504));
        $this->insertJournalEntry($this->supplierId, $period, '2098-03-01', true);
        $this->expectFrameworkError('accounting_framework_period_has_posted_entries', fn () =>
            $this->service->changeFromPeriodStart($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'));
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testSettingsSwitchTo504IsAllowedOnlyWithoutAccountingHistory(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');

        self::assertTrue($this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertSame(AccountingFrameworkService::CZ_504, $this->service->forDate($this->supplierId, '2098-01-01'));
    }

    public function testSettingsSwitchTo504RejectsJournalEntryOutsideAffectedInterval(): void
    {
        $earlier = $this->createPeriod($this->supplierId, 2097, '2097-01-01', '2097-12-31');
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $earlier, '2097-06-30', false);

        $this->expectFrameworkError(AccountingFrameworkService::CONVERSION_REQUIRED, fn () =>
            $this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'));
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testSettingsSwitchTo504RejectsExistingChartOfAccounts(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '221', 'Syntetický bankovní účet', 'asset', 'debit', 1, 1)"
        )->execute([$this->supplierId]);

        $this->expectFrameworkError(AccountingFrameworkService::CONVERSION_REQUIRED, fn () =>
            $this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'));
    }

    public function testSettingsSwitchTo504ReplacesUntouchedSeededChart500(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplierId);
        $other = (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->otherSupplierId);

        self::assertTrue($this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));

        self::assertSame(AccountingFrameworkService::CZ_504, $this->service->forDate($this->supplierId, '2098-01-01'));
        self::assertSame(
            $this->templateCodes(ChartOfAccountsTemplate504::ACCOUNTS),
            $this->chartCodes($this->supplierId),
            'nedotčená šablona 500 se nahradí šablonou 504',
        );
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM posting_rules WHERE supplier_id = ?', [$this->supplierId]));
        self::assertCount($other, $this->chartCodes($this->otherSupplierId), 'osnova jiné firmy zůstává');
    }

    public function testSettingsSwitchTo504RejectsChangedOrUsedSeededChart(): void
    {
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $seeder = new ChartOfAccountsSeeder($this->db);
        $seeder->seedForSupplier($this->supplierId);
        $pdo = $this->db->pdo();
        $mutations = [
            'přejmenovaný účet' => ["UPDATE chart_of_accounts SET name = 'Syntetická banka' WHERE supplier_id = ? AND account_code = '221'"],
            'vlastní účet' => ["INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active) VALUES (?, '999', 'Syntetický vlastní', 'offbalance', NULL, 1, 1)"],
            'vlastní předkontace' => ["INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active) VALUES (?, 'invoice.service.issued', 'Syntetická předkontace', '311', '602', 100, 1)"],
            'použitý účet v dokladu' => ["INSERT INTO cash_registers (supplier_id, name, account_code) VALUES (?, 'Syntetická pokladna', '211')"],
        ];
        foreach ($mutations as $label => [$sql]) {
            $pdo->exec('SAVEPOINT pristine_case');
            $pdo->prepare($sql)->execute([$this->supplierId]);
            $this->expectFrameworkError(AccountingFrameworkService::CONVERSION_REQUIRED, fn () =>
                $this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
            self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'), $label);
            self::assertSame($this->templateCodes(ChartOfAccountsTemplate::ACCOUNTS), array_values(array_diff($this->chartCodes($this->supplierId), ['999'])), $label);
            $pdo->exec('ROLLBACK TO SAVEPOINT pristine_case');
        }
    }

    public function testSettingsHistoryGuardDoesNotBlockReturnTo500OrOtherTenant(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $earlier = $this->createPeriod($this->supplierId, 2097, '2097-01-01', '2097-12-31');
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $earlier, '2097-06-30', false);
        $this->createPeriod($this->otherSupplierId, 2098, '2098-01-01', '2098-12-31');

        self::assertTrue($this->service->changeFromSettings($this->supplierId, '2098-01-01', AccountingFrameworkService::CZ_500));
        self::assertTrue($this->service->changeFromSettings($this->otherSupplierId, '2098-01-01', AccountingFrameworkService::CZ_504));
    }

    public function testPostedEntryBlocksFrameworkChange(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $periodId, '2098-03-01', true);

        $this->expectFrameworkError('accounting_framework_period_has_posted_entries', function (): void {
            $this->service->changeFromPeriodStart(
                $this->supplierId,
                '2098-01-01',
                AccountingFrameworkService::CZ_500,
            );
        });
        self::assertSame(AccountingFrameworkService::CZ_504, $this->service->forDate($this->supplierId, '2098-01-01'));
    }

    public function testDraftEntryDoesNotBlockFrameworkChange(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $periodId, '2098-03-01', false);

        self::assertTrue($this->service->changeFromPeriodStart(
            $this->supplierId,
            '2098-01-01',
            AccountingFrameworkService::CZ_500,
        ));
        self::assertSame(AccountingFrameworkService::CZ_500, $this->service->forDate($this->supplierId, '2098-01-01'));
    }

    public function testPostedEntryOfAnotherTenantDoesNotBlockChange(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $otherPeriodId = $this->createPeriod($this->otherSupplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->otherSupplierId, $otherPeriodId, '2098-03-01', true);

        self::assertTrue($this->service->changeFromPeriodStart(
            $this->supplierId,
            '2098-01-01',
            AccountingFrameworkService::CZ_500,
        ));
    }

    public function testPostedEntryInLaterPeriodOfAffectedIntervalBlocksChange(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $laterPeriodId = $this->createPeriod($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->insertJournalEntry($this->supplierId, $laterPeriodId, '2099-03-01', true);

        $this->expectFrameworkError('accounting_framework_period_has_posted_entries', function (): void {
            $this->service->changeFromPeriodStart(
                $this->supplierId,
                '2098-01-01',
                AccountingFrameworkService::CZ_500,
            );
        });
    }

    public function testPostedEntryAtNextFrameworkChangeDoesNotBlockEarlierInterval(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $this->insertFramework($this->supplierId, '2099-01-01', AccountingFrameworkService::CZ_504);
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $laterPeriodId = $this->createPeriod($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->insertJournalEntry($this->supplierId, $laterPeriodId, '2099-01-01', true);

        self::assertTrue($this->service->changeFromPeriodStart(
            $this->supplierId,
            '2098-01-01',
            AccountingFrameworkService::CZ_500,
        ));
    }

    public function testLegacyEntryWithWrongPeriodIdStillBlocksByEntryDate(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $legacyPeriodId = $this->createPeriod($this->supplierId, 2097, '2097-01-01', '2097-12-31');
        $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $legacyPeriodId, '2098-03-01', true);

        $this->expectFrameworkError('accounting_framework_period_has_posted_entries', function (): void {
            $this->service->changeFromPeriodStart(
                $this->supplierId,
                '2098-01-01',
                AccountingFrameworkService::CZ_500,
            );
        });
    }

    public function testPostedGuardWithSnapshotIsolationOffUsesCurrentRead(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->inTransaction = false;

        $originalSnapshotIsolation = (int) $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        $writer = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->config));
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = OFF');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $snapshot = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
            $snapshot->execute([$this->supplierId]);
            self::assertSame(0, (int) $snapshot->fetchColumn());

            $this->insertJournalEntryWithPdo(
                $writer->pdo(),
                $this->supplierId,
                $periodId,
                '2098-03-01',
                true,
            );

            $this->expectFrameworkError('accounting_framework_period_has_posted_entries', function (): void {
                $this->service->changeFromPeriodStart(
                    $this->supplierId,
                    '2098-01-01',
                    AccountingFrameworkService::CZ_500,
                );
            });
            self::assertTrue($pdo->inTransaction(), 'Při snapshot isolation OFF zůstává vnější transakce aktivní.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $writer->close();
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $originalSnapshotIsolation);
            $pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')
                ->execute([$this->supplierId, $this->otherSupplierId]);
        }
    }

    public function testSnapshotIsolationConflictPreservesMariaDb1020AndAbortsTransaction(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->inTransaction = false;

        $originalSnapshotIsolation = (int) $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        $writer = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->config));
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $snapshot = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
            $snapshot->execute([$this->supplierId]);
            self::assertSame(0, (int) $snapshot->fetchColumn());

            $this->insertJournalEntryWithPdo(
                $writer->pdo(),
                $this->supplierId,
                $periodId,
                '2098-03-01',
                true,
            );

            try {
                $this->service->changeFromPeriodStart(
                    $this->supplierId,
                    '2098-01-01',
                    AccountingFrameworkService::CZ_500,
                );
                self::fail('Konfliktní locking read měl skončit chybou MariaDB 1020.');
            } catch (\PDOException $e) {
                self::assertSame(1020, (int) ($e->errorInfo[1] ?? 0));
            }

            self::assertFalse($pdo->inTransaction(), 'MariaDB 1020 musí ukončit celou transakci.');
            self::assertSame(
                AccountingFrameworkService::CZ_504,
                $this->service->forDate($this->supplierId, '2098-01-01'),
                'Konflikt nesmí změnit účetní rámec.',
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $writer->close();
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $originalSnapshotIsolation);
            $pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')
                ->execute([$this->supplierId, $this->otherSupplierId]);
        }
    }

    public function testFrameworkChangeWaitsForPostingPeriodBeforeJournalForeignKeyLock(): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->inTransaction = false;

        $root = dirname(__DIR__, 4);
        $expectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertMatchesRegularExpression('/_test(?:_worker[A-Za-z0-9_]*)?$/D', $expectedDatabase);
        $controlFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'accounting-framework-lock-' . bin2hex(random_bytes(8)) . '.json';
        $worker = <<<'PHP'
$root = $argv[1];
$supplierId = (int) $argv[2];
$controlFile = $argv[3];
$expectedDatabase = $argv[4];
require $root . '/api/vendor/autoload.php';
$config = \MyInvoice\Infrastructure\Config\Config::load($root);
if ((string) $config->get('db.name') !== $expectedDatabase) { exit(10); }
$container = \MyInvoice\Bootstrap::buildContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) { exit(11); }
file_put_contents($controlFile, json_encode([
    'ready' => true,
    'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
], JSON_THROW_ON_ERROR));
try {
    $container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)
        ->changeFromPeriodStart($supplierId, '2098-01-01', 'cz_500');
    exit(12);
} catch (\MyInvoice\Service\Accounting\AccountingFrameworkException $e) {
    if ($e->errorCode !== 'accounting_framework_period_has_posted_entries') { exit(13); }
}
echo 'posted_guard';
PHP;
        $process = new Process(
            [PHP_BINARY, '-r', $worker, $root, (string) $this->supplierId, $controlFile, $expectedDatabase],
            $root,
        );
        $process->setEnv(['MYINVOICE_DB_NAME' => $expectedDatabase]);

        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM accounting_periods WHERE id = ? FOR UPDATE');
            $lock->execute([$periodId]);
            self::assertSame($periodId, (int) $lock->fetchColumn());

            $process->start();
            $control = $this->awaitWorkerReady($process, $controlFile);
            $this->assertWorkerWaitsOnLock(
                $pdo,
                $process,
                (int) $control['connection_id'],
                '/FROM\s+accounting_periods\b.*FOR\s+UPDATE/is',
                'Změna rámce nečekala jako první na zámek účetního období.',
            );

            // INSERT musí získat FK zámek supplier. Kdyby worker držel supplier FOR UPDATE
            // a čekal na období, vznikl by zde opačný lock order a deadlock.
            $this->insertJournalEntryWithPdo(
                $pdo,
                $this->supplierId,
                $periodId,
                '2098-03-01',
                true,
            );
            $pdo->commit();

            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('posted_guard', trim($process->getOutput()));
            self::assertSame(
                AccountingFrameworkService::CZ_504,
                $this->service->forDate($this->supplierId, '2098-01-01'),
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($controlFile)) {
                unlink($controlFile);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')
                ->execute([$this->supplierId, $this->otherSupplierId]);
        }
    }

    public function testNowaitBusyRollsBackOwnedTransaction(): void
    {
        $this->assertNowaitBusyWhilePostedRowIsLocked(false);
    }

    public function testNowaitBusyRollsBackOnlyFrameworkSavepoint(): void
    {
        $this->assertNowaitBusyWhilePostedRowIsLocked(true);
    }

    private function assertNowaitBusyWhilePostedRowIsLocked(bool $outerTransaction): void
    {
        $this->replaceBaselineWithCz504($this->supplierId);
        $periodId = $this->createPeriod($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->insertJournalEntry($this->supplierId, $periodId, '2098-03-01', true);
        $entryId = (int) $this->db->pdo()->lastInsertId();
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->inTransaction = false;

        $contender = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->config));
        $contenderService = new AccountingFrameworkService(
            $contender,
            new AccountingFrameworkRepository($contender),
        );
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM journal_entries WHERE id = ? FOR UPDATE');
            $lock->execute([$entryId]);
            self::assertSame($entryId, (int) $lock->fetchColumn());

            if ($outerTransaction) {
                $contender->pdo()->beginTransaction();
            }
            $startedAt = microtime(true);
            try {
                $contenderService->changeFromPeriodStart(
                    $this->supplierId,
                    '2098-01-01',
                    AccountingFrameworkService::CZ_500,
                );
                self::fail('Zamčený posted řádek měl změnu rámce ihned odmítnout.');
            } catch (AccountingFrameworkException $e) {
                self::assertSame('accounting_framework_busy', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
                $previous = $e->getPrevious();
                self::assertInstanceOf(\PDOException::class, $previous);
                self::assertSame(1205, (int) ($previous->errorInfo[1] ?? 0));
            }
            self::assertLessThan(5.0, microtime(true) - $startedAt, 'NOWAIT nesmí čekat na lock timeout session.');
            self::assertSame(
                $outerTransaction,
                $contender->pdo()->inTransaction(),
                'Vlastní transakce se má rollbacknout, vnější má zůstat aktivní po návratu na savepoint.',
            );
            self::assertTrue($pdo->inTransaction(), 'Transakce držící journal row nesmí být změnou rámce ukončena.');
            self::assertSame(
                AccountingFrameworkService::CZ_504,
                $this->service->forDate($this->supplierId, '2098-01-01'),
            );
        } finally {
            if ($contender->pdo()->inTransaction()) {
                $contender->pdo()->rollBack();
            }
            $contender->close();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')
                ->execute([$this->supplierId, $this->otherSupplierId]);
        }
    }

    /** @param list<array{code:string}> $accounts @return list<string> */
    private function templateCodes(array $accounts): array
    {
        $codes = array_map(static fn (array $account): string => $account['code'], $accounts);
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @return list<string> */
    private function chartCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $codes = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function replaceBaselineWithCz504(int $supplierId): void
    {
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')
            ->execute([$supplierId]);
        $this->insertFramework($supplierId, '1900-01-01', AccountingFrameworkService::CZ_504);
    }

    private function insertFramework(int $supplierId, string $effectiveFrom, string $framework): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework)
             VALUES (?, ?, ?)'
        )->execute([$supplierId, $effectiveFrom, $framework]);
    }

    private function createPeriod(int $supplierId, int $year, string $startsOn, string $endsOn): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, \'open\')'
        )->execute([$supplierId, $year, $startsOn, $endsOn]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertJournalEntry(int $supplierId, int $periodId, string $entryDate, bool $posted): void
    {
        $this->insertJournalEntryWithPdo($this->db->pdo(), $supplierId, $periodId, $entryDate, $posted);
    }

    private function insertJournalEntryWithPdo(
        \PDO $pdo,
        int $supplierId,
        int $periodId,
        string $entryDate,
        bool $posted,
    ): void
    {
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, posted_at)
             VALUES (?, ?, ?, \'Syntetický test rámce\', \'manual\', ?)'
        )->execute([$supplierId, $periodId, $entryDate, $posted ? $entryDate . ' 10:00:00' : null]);
    }

    private function expectFrameworkError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Očekávána chyba účetního rámce ' . $errorCode . '.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }
}
