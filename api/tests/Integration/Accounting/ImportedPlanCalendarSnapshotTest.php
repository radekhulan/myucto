<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\ImportedPlanRevisionService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class ImportedPlanCalendarSnapshotTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $dbA;
    private Connection $dbB;
    private AccountingPeriodRepository $periodsA;
    private AccountingPeriodRepository $periodsB;
    private AssetService $assets;
    private ImportedPlanRevisionService $revisions;
    private int $supplier = 0;
    private int $user;
    private int $timeout;
    private int $snapshotIsolation;

    protected function setUp(): void
    {
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b;
        $pdo = $this->dbA->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        self::assertGreaterThan(0, $this->user);
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', fiscal_year_start_month = 1 WHERE id = ?")->execute([$this->supplier]);
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        $this->timeout = (int) $this->dbB->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $this->dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->periodsA = new AccountingPeriodRepository($this->dbA);
        $this->periodsB = new AccountingPeriodRepository($this->dbB);
        $a->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->assets = $a->get(AssetService::class);
        $this->revisions = new ImportedPlanRevisionService($this->dbA, $a->get(AssetRepository::class), $this->assets,
            $this->periodsA, new MockClock('2098-10-15'), $a->get(ActivityLogger::class));
    }

    protected function tearDown(): void
    {
        foreach (['dbB', 'dbA'] as $property) {
            if (isset($this->{$property}) && $this->{$property}->pdo()->inTransaction()) $this->{$property}->pdo()->rollBack();
        }
        if (isset($this->timeout)) $this->dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->timeout);
        if (isset($this->snapshotIsolation)) $this->dbA->pdo()->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
        if ($this->supplier !== 0) $this->dbA->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        if (isset($this->dbB)) $this->dbB->close();
        if (isset($this->dbA)) $this->dbA->close();
    }

    public function testRevisionUsesSameLatestFiscalRegimeAsNativeCalendar(): void
    {
        $this->periodsA->create($this->supplier, 2095, '2095-08-01', '2096-07-31');
        $this->periodsA->create($this->supplier, 2098, '2098-10-01', '2099-09-30');
        self::assertSame(10, $this->periodsA->fiscalCalendar($this->supplier)->startMonth());
        $card = $this->assets->createImported($this->supplier, [
            'inventory_number' => 'SYN-CALENDAR-REVISION', 'name' => 'Syntetický software s hospodářským rokem',
            'kind' => 'intangible', 'asset_account_code' => '013', 'accumulated_account_code' => '073',
            'acquisition_account_code' => '041', 'input_price' => 60000.0, 'acquisition_date' => '2097-05-03',
            'put_into_use_date' => '2097-05-05', 'status' => 'in_use', 'tax_method' => 'by_accounting',
            'acc_method' => 'straight_line', 'acc_useful_life_months' => 20, 'opening_acc_months' => 16,
            'opening_acc_amount' => 48000.0, 'opening_tax_years' => 2, 'opening_tax_amount' => 48000.0,
        ], ['version' => 1, 'source_program' => 'Synthetic', 'source_tax_method' => 'tax', 'source_accounting_method' => 'acc',
            'accounting' => [['month' => '2098-10', 'amount_cents' => 300000], ['month' => '2098-11', 'amount_cents' => 300000],
                ['month' => '2098-12', 'amount_cents' => 300000], ['month' => '2099-01', 'amount_cents' => 300000]],
            'tax' => [['fiscal_year' => 2098, 'amount_cents' => 1200000]]]);
        $preview = $this->revisions->preview($this->supplier, (int) $card['asset']['id'], [
            'effective_from' => '2098-11', 'remaining_months' => 5, 'reason' => 'Nový odhad využití', 'expected_revision' => 0,
        ], $this->user);
        self::assertSame(10, $preview['calendar_start_month']);
        self::assertSame([12000.0], array_column($preview['after']['tax'], 'amount'));
        self::assertSame(['2098-10', '2098-11', '2098-12', '2099-01', '2099-02', '2099-03'],
            array_column($preview['after']['accounting'][0]['months'], 'month'));
    }

    public function testLockedSnapshotRequiresTransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->periodsA->fiscalCalendarSnapshot($this->supplier, true);
    }

    #[DataProvider('snapshotIsolationModes')]
    public function testLockedSnapshotReadsCommittedCalendarInsteadOfEarlierTransactionSnapshot(int $snapshotIsolation): void
    {
        $snapshot = $this->snapshotAfterConcurrentChange($snapshotIsolation, true);
        self::assertSame(8, $snapshot['calendar']->startMonth());
        self::assertNull($snapshot['supplier_start_month'], 'Doložený hospodářský rok nepoužívá nastavení firmy.');
        self::assertCount(1, $snapshot['periods']);
        self::assertSame('2098-08-01', $snapshot['periods'][0]['starts_on']);
        self::assertSame('2099-07-31', $snapshot['periods'][0]['ends_on']);
        self::assertSame($snapshot['fingerprint'], $this->periodsB->fiscalCalendarSnapshot($this->supplier)['fingerprint']);
    }

    #[DataProvider('snapshotIsolationModes')]
    public function testFallbackReadsCurrentSupplierSettingDespiteEarlierSnapshot(int $snapshotIsolation): void
    {
        $snapshot = $this->snapshotAfterConcurrentChange($snapshotIsolation, false);
        self::assertSame([], $snapshot['periods']);
        self::assertSame(8, $snapshot['supplier_start_month']);
        self::assertSame(8, $snapshot['calendar']->startMonth());
    }

    public static function snapshotIsolationModes(): iterable
    {
        yield 'current read bez snapshot izolace' => [0];
        yield 'konflikt snapshotu vyžaduje novou transakci' => [1];
    }

    private function snapshotAfterConcurrentChange(int $snapshotIsolation, bool $createPeriod): array
    {
        $pdo = $this->dbA->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $snapshotIsolation);
        $attempts = 0;
        $conflicts = 0;
        // Repository nevlastní transakci. Při 1020 musí celý pokus zopakovat její vlastník,
        // stejně jako ImportedPlanRevisionService::revise(), nikoli jen zamykací SELECT.
        $snapshot = TransactionFailure::retrying($pdo, function () use ($pdo, $createPeriod, &$attempts, &$conflicts): array {
            $pdo->beginTransaction();
            $attempts++;
            if ($attempts === 1) {
                self::assertSame(1, $this->periodsA->fiscalCalendar($this->supplier)->startMonth());
                $this->dbB->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplier]);
                if ($createPeriod) $this->periodsB->create($this->supplier, 2098, '2098-08-01', '2099-07-31');
            }
            try {
                $current = $this->periodsA->fiscalCalendarSnapshot($this->supplier, true);
            } catch (\PDOException $e) {
                self::assertSame(1020, (int) ($e->errorInfo[1] ?? 0), 'Ověřujeme konflikt snapshotu, ne libovolnou chybu DB.');
                $conflicts++;
                throw $e;
            }
            $pdo->commit();
            return $current;
        });
        self::assertSame($snapshotIsolation === 1 ? 2 : 1, $attempts);
        self::assertSame($snapshotIsolation, $conflicts);
        self::assertFalse($pdo->inTransaction());
        return $snapshot;
    }

    public function testEstablishedFiscalRegimeDoesNotLockUnusedSupplierSetting(): void
    {
        $this->periodsA->create($this->supplier, 2098, '2098-08-01', '2099-07-31');
        $this->dbA->pdo()->beginTransaction();
        $before = $this->periodsA->fiscalCalendarSnapshot($this->supplier, true);
        $this->dbB->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 10 WHERE id = ?')->execute([$this->supplier]);
        $after = $this->periodsB->fiscalCalendarSnapshot($this->supplier);
        self::assertSame(8, $after['calendar']->startMonth());
        self::assertSame($before['fingerprint'], $after['fingerprint'], 'Nepoužité nastavení nemění účinný kalendář.');
    }

    public function testSupplierSettingCannotChangeWhileSnapshotIsLockedEvenWithoutPeriods(): void
    {
        $this->dbA->pdo()->beginTransaction();
        $snapshot = $this->periodsA->fiscalCalendarSnapshot($this->supplier, true);
        self::assertSame([], $snapshot['periods']);
        try {
            $this->dbB->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplier]);
            self::fail('Nastavení fiskálního roku musí čekat na zámek snapshotu.');
        } catch (\PDOException $e) { self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0)); }
        $this->dbA->pdo()->commit();
        $this->dbB->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplier]);
        $current = $this->periodsB->fiscalCalendarSnapshot($this->supplier);
        self::assertSame(8, $current['calendar']->startMonth());
        self::assertNotSame($snapshot['fingerprint'], $current['fingerprint']);
    }
}
