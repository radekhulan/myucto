<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Assets\ImportedPlanRevisionService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** Dvě skutečné DB sessions: uzávěrka a účtování se nesmějí minout s revizí plánu. */
#[Group('integration')]
final class ImportedAssetPlanRevisionConcurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $dbA;
    private Connection $dbB;
    private AssetService $assetsA;
    private AssetService $assetsB;
    private ImportedPlanRevisionService $revisionsA;
    private ImportedPlanRevisionService $revisionsB;
    private DepreciationPostingService $postingA;
    private DepreciationPostingService $postingB;
    private int $supplier = 0;
    private int $user;
    private int $asset;
    private int $period;
    private int $oldTimeout;
    private array $input;
    private string $originalJson;

    protected function setUp(): void
    {
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $pdo = $this->dbA->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->oldTimeout = (int) $this->dbB->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $this->dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed, 'Testovací DB musí obsahovat syntetickou firmu.');
        self::assertGreaterThan(0, $this->user, 'Testovací DB musí obsahovat syntetického uživatele.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', fiscal_year_start_month = 1 WHERE id = ?")
            ->execute([$this->supplier]);
        $a->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $periods = $a->get(AccountingPeriodRepository::class);
        $this->period = $periods->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $periods->create($this->supplier, 2099, '2099-01-01', '2099-12-31');
        $this->assetsA = $a->get(AssetService::class);
        $this->assetsB = $b->get(AssetService::class);
        $this->postingA = $a->get(DepreciationPostingService::class);
        $this->postingB = $b->get(DepreciationPostingService::class);
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2098-01-15T12:00:00+01:00'); }
        };
        $this->revisionsA = new ImportedPlanRevisionService($this->dbA, $a->get(AssetRepository::class),
            $this->assetsA, $a->get(AccountingPeriodRepository::class), $clock, $a->get(ActivityLogger::class));
        $this->revisionsB = new ImportedPlanRevisionService($this->dbB, $b->get(AssetRepository::class),
            $this->assetsB, $b->get(AccountingPeriodRepository::class), $clock, $b->get(ActivityLogger::class));
        $created = $this->assetsA->createImported($this->supplier, [
            'inventory_number' => 'SYN-REVISION-CONCURRENT', 'name' => 'Syntetická licence pro souběh',
            'kind' => 'intangible', 'asset_account_code' => '013', 'accumulated_account_code' => '073',
            'acquisition_account_code' => '041', 'input_price' => 96000.0, 'acquisition_date' => '2096-08-03',
            'put_into_use_date' => '2096-08-05', 'status' => 'in_use', 'tax_method' => 'by_accounting',
            'acc_method' => 'straight_line', 'acc_useful_life_months' => 20, 'opening_tax_years' => 2,
            'opening_tax_amount' => 60000.0, 'opening_acc_months' => 16, 'opening_acc_amount' => 60000.0,
        ], ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'synthetic-tax',
            'source_accounting_method' => 'synthetic-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 899000], ['month' => '2098-02', 'amount_cents' => 899000],
                ['month' => '2098-03', 'amount_cents' => 899000], ['month' => '2098-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 3600000]]]);
        $this->asset = (int) $created['asset']['id'];
        $this->originalJson = $this->rawPlan($pdo);
        $this->input = ['effective_from' => '2098-01', 'remaining_months' => 16,
            'reason' => 'Syntetická změna zbývající doby užívání', 'expected_revision' => 0];
        $preview = $this->revisionsB->preview($this->supplier, $this->asset, $this->input, $this->user);
        $this->input += ['expected_calendar_start_month' => $preview['calendar_start_month'],
            'expected_calendar_fingerprint' => $preview['calendar_fingerprint']];
    }

    protected function tearDown(): void
    {
        foreach (['dbB', 'dbA'] as $property) {
            if (isset($this->{$property}) && $this->{$property}->pdo()->inTransaction()) $this->{$property}->pdo()->rollBack();
        }
        if (isset($this->oldTimeout)) $this->dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->oldTimeout);
        if ($this->supplier !== 0) {
            $pdo = $this->dbA->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        if (isset($this->dbB)) $this->dbB->close();
        if (isset($this->dbA)) $this->dbA->close();
    }

    public function testClosingBlocksRevisionAndCommittedStateIsRevalidated(): void
    {
        $pdo = $this->dbA->pdo();
        $pdo->beginTransaction();
        $periods = new AccountingPeriodRepository($this->dbA);
        self::assertNotNull($periods->findForUpdate($this->period, $this->supplier));
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplier, $this->period]);
        $this->assertRevisionWaitsForOtherSession();
        $pdo->commit();
        $this->assertRevisionRejectedAfterCommit();
        self::assertSame([], (new DepreciationEntryRepository($this->dbB))->forAsset($this->asset));
    }

    public function testUncommittedBookingBlocksRevisionAndConfirmedHistoryWins(): void
    {
        $pdo = $this->dbA->pdo();
        $pdo->beginTransaction();
        $booked = $this->postingA->bookYear($this->supplier, 2098, ['user_id' => $this->user, 'posted_by' => $this->user]);
        self::assertSame([], $booked['errors']);
        self::assertSame(1, $booked['booked']);
        $this->assertRevisionWaitsForOtherSession();
        $pdo->commit();
        $this->assertRevisionRejectedAfterCommit();
        $entries = (new DepreciationEntryRepository($this->dbB))->forAsset($this->asset);
        self::assertCount(2, $entries);
        self::assertSame([36000.0, 36000.0], array_column($entries, 'amount'));
    }

    public function testUncommittedRevisionBlocksBookingThenBookingUsesEntireNewPlan(): void
    {
        $pdo = $this->dbA->pdo();
        $pdo->beginTransaction();
        $saved = $this->revisionsA->revise($this->supplier, $this->asset, $this->input, $this->user);
        self::assertCount(1, $saved['asset']['imported_depreciation_plan']['revisions']);
        $blocked = $this->postingB->bookYear($this->supplier, 2098, ['user_id' => $this->user, 'posted_by' => $this->user]);
        self::assertSame(0, $blocked['booked']);
        self::assertSame([['asset_id' => $this->asset, 'code' => 'operation_failed']], $blocked['errors']);
        self::assertFalse($this->dbB->pdo()->inTransaction());
        self::assertSame([], (new DepreciationEntryRepository($this->dbB))->forAsset($this->asset));
        $pdo->commit();
        $booked = $this->postingB->bookYear($this->supplier, 2098, ['user_id' => $this->user, 'posted_by' => $this->user]);
        self::assertSame([], $booked['errors']);
        self::assertSame(1, $booked['booked']);
        $entries = new DepreciationEntryRepository($this->dbB);
        self::assertSame(27000.0, $entries->findYear($this->asset, 'accounting', 2098)['amount']);
        self::assertSame(27000.0, $entries->findYear($this->asset, 'tax', 2098)['amount']);
        $plan = $this->assetsB->plan($this->supplier, $this->asset);
        self::assertSame([27000.0, 9000.0], array_column($plan['accounting'], 'amount'));
        self::assertSame([2250.0, 2250.0, 2250.0, 2250.0], array_column($plan['accounting'][1]['months'], 'amount'));
        $original = json_decode($this->originalJson, true, 512, JSON_THROW_ON_ERROR);
        $current = json_decode($this->rawPlan($this->dbB->pdo()), true, 512, JSON_THROW_ON_ERROR);
        unset($current['revisions']);
        self::assertSame($original, $current);
    }

    private function assertRevisionWaitsForOtherSession(): void
    {
        try {
            $this->revisionsB->revise($this->supplier, $this->asset, $this->input, $this->user);
            self::fail('Revize musí čekat na zámek jiné DB session.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), 'Ověřujeme skutečný lock timeout, ne libovolnou výjimku.');
        }
        self::assertFalse($this->dbB->pdo()->inTransaction());
        self::assertSame($this->originalJson, $this->rawPlan($this->dbB->pdo()));
    }

    private function assertRevisionRejectedAfterCommit(): void
    {
        try {
            $this->revisionsB->revise($this->supplier, $this->asset, $this->input, $this->user);
            self::fail('Revize nesmí ignorovat nově potvrzenou historii nebo stav období.');
        } catch (AssetException $e) {
            self::assertSame('imported_plan_revision_locked', $e->errorCode);
        }
        self::assertFalse($this->dbB->pdo()->inTransaction());
        self::assertSame($this->originalJson, $this->rawPlan($this->dbB->pdo()));
    }

    private function rawPlan(PDO $pdo): string
    {
        $stmt = $pdo->prepare('SELECT imported_depreciation_plan FROM assets WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplier, $this->asset]);
        return (string) $stmt->fetchColumn();
    }
}
