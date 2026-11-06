<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Assets\ImportedPlanRevisionService;
use Symfony\Component\Clock\MockClock;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ImportedAssetRevisionTest extends TestCase
{
    private ImportedPlanRevisionService $revisions;
    private int $actor;
    private Connection $db;
    private AssetService $service;
    private DepreciationPostingService $posting;
    private DepreciationEntryRepository $entries;
    private JournalEntryRepository $journal;
    private int $supplier;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->actor = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->actor);
        $this->service = $container->get(AssetService::class);
        $this->posting = $container->get(DepreciationPostingService::class);
        $this->entries = $container->get(DepreciationEntryRepository::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->revisions = new ImportedPlanRevisionService($this->db, $container->get(AssetRepository::class), $this->service, $container->get(AccountingPeriodRepository::class), new MockClock('2098-01-15T10:00:00+00:00'), $container->get(ActivityLogger::class));
        $this->supplier = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->supplier, 'Test requires a seeded test tenant.');
        $this->db->pdo()->beginTransaction();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testPreviewAndRevisionKeepOriginAndCreateNoAccountingEntries(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $input = $this->input();
        $preview = $this->revisions->preview($this->supplier, $id, $input, $this->actor);
        self::assertSame($this->source(), $this->service->get($this->supplier, $id)['imported_depreciation_plan']);
        self::assertSame(27010.0, $preview['remaining_amount']);
        self::assertSame([8990.0, 9003.33, 9003.33, 9003.34], array_column($preview['after']['accounting'][0]['months'], 'amount'));
        $saved = $this->revisions->revise($this->supplier, $id, $input + $this->snapshot($preview), $this->actor)['asset'];
        $history = $saved['imported_depreciation_plan']['revisions'];
        $origin = $saved['imported_depreciation_plan'];
        unset($origin['revisions']);
        self::assertSame($this->source(), $origin);
        self::assertSame($this->actor, $history[0]['user_id']);
        self::assertSame('2098-01-15T10:00:00+00:00', $history[0]['created_at']);
        self::assertSame(20, $saved['acc_useful_life_months']);
        self::assertSame([], $this->entries->forAsset($id));
        self::assertNull($this->journal->findBySource($this->supplier, 'asset', $id));
        self::assertSame($preview['after'], $this->service->plan($this->supplier, $id));
        $this->reject(fn () => $this->revisions->revise($this->supplier, $id, $input + $this->snapshot($preview), $this->actor), 'imported_plan_revision_stale');
    }

    public function testChangedCalendarAndLockedFutureYearBlockRevision(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $input = array_replace($this->input(), ['remaining_months' => 14]);
        $preview = $this->revisions->preview($this->supplier, $id, $input, $this->actor);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET ends_on = '2098-12-30' WHERE supplier_id = ? AND fiscal_year = 2098")->execute([$this->supplier]);
        $this->reject(fn () => $this->revisions->revise($this->supplier, $id, $input + $this->snapshot($preview), $this->actor), 'imported_plan_revision_stale');
        $pdo->prepare("UPDATE accounting_periods SET ends_on = '2098-12-31', status = 'closing' WHERE supplier_id = ? AND fiscal_year = 2098")->execute([$this->supplier]);
        $this->reject(fn () => $this->revisions->preview($this->supplier, $id, $input, $this->actor), 'imported_plan_revision_locked');
        self::assertSame($this->source(), $this->service->get($this->supplier, $id)['imported_depreciation_plan']);
    }

    public function testExistingShortFiscalPeriodCannotReceiveFullYearRevision(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET ends_on = '2098-06-30' WHERE supplier_id = ? AND fiscal_year = 2098")->execute([$this->supplier]);
        $this->reject(fn () => $this->revisions->preview($this->supplier, $id,
            array_replace($this->input(), ['remaining_months' => 14]), $this->actor), 'imported_plan_revision_not_allowed');
    }

    public function testConfirmedYearBlocksEvenWhenEffectiveMonthIsLater(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $this->posting->bookYear($this->supplier, 2098);
        $before = $this->entries->forAsset($id);
        $this->reject(fn () => $this->revisions->preview($this->supplier, $id, $this->input(), $this->actor), 'imported_plan_revision_locked');
        self::assertSame($before, $this->entries->forAsset($id));
        $plan = $this->service->plan($this->supplier, $id);
        self::assertGreaterThan(0, $plan['accounting'][0]['depreciation_entry_id']);
        self::assertGreaterThan(0, $plan['accounting'][0]['journal_entry_id']);
    }

    public function testLaterRevisionPreservesAlreadyPostedYearAndOriginalLife(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $first = array_replace($this->input(), ['remaining_months' => 14]);
        $preview = $this->revisions->preview($this->supplier, $id, $first, $this->actor);
        $this->revisions->revise($this->supplier, $id, $first + $this->snapshot($preview), $this->actor);
        $this->posting->bookYear($this->supplier, 2098);
        $entries = $this->entries->forAsset($id);
        $plan = $this->service->plan($this->supplier, $id);
        $second = array_replace($this->input(), ['effective_from' => '2099-02', 'expected_revision' => 1]);
        $preview = $this->revisions->preview($this->supplier, $id, $second, $this->actor);
        $saved = $this->revisions->revise($this->supplier, $id, $second + $this->snapshot($preview), $this->actor)['asset'];
        self::assertCount(2, $saved['imported_depreciation_plan']['revisions']);
        self::assertSame(20, $saved['acc_useful_life_months']);
        self::assertSame(32, $preview['after']['asset_summary']['effective_acc_useful_life_months']);
        self::assertSame($entries, $this->entries->forAsset($id));
        self::assertSame($plan['accounting'][0], $preview['after']['accounting'][0]);
        self::assertSame($plan['tax'][0], $preview['after']['tax'][0]);
        self::assertSame($preview['after'], $this->service->plan($this->supplier, $id));
    }

    public function testAuditFailureRollsBackRevision(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $logger = new class($this->db) extends ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            { throw new \RuntimeException('Synthetic audit failure'); }
        };
        $service = new ImportedPlanRevisionService($this->db, new AssetRepository($this->db), $this->service, new AccountingPeriodRepository($this->db), new MockClock('2098-01-15'), $logger);
        $preview = $service->preview($this->supplier, $id, $this->input(), $this->actor);
        try {
            $service->revise($this->supplier, $id, $this->input() + $this->snapshot($preview), $this->actor);
            self::fail('Audit failure must abort revision.');
        } catch (\RuntimeException $e) { self::assertSame('Synthetic audit failure', $e->getMessage()); }
        self::assertSame($this->source(), $this->service->get($this->supplier, $id)['imported_depreciation_plan']);
    }

    public function testImportCannotForgeHistoryAndPastRevisionCannotRewriteMonths(): void
    {
        $this->reject(fn () => $this->service->createImported($this->supplier, $this->card(), $this->source() + ['revisions' => []]), 'imported_plan_invalid');
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $this->reject(fn () => $this->revisions->preview($this->supplier, $id, array_replace($this->input(), ['effective_from' => '2097-12']), $this->actor), 'imported_plan_revision_not_allowed');
        $this->reject(fn () => $this->revisions->preview($this->supplier + 1000000, $id, $this->input(), $this->actor), 'not_found');
    }

    public function testTaxEvidenceSupplierCannotReviseImportedPlan(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $preview = $this->revisions->preview($this->supplier, $id, $this->input(), $this->actor);
        // Změna režimu po importu: revize se nesmí provést ani v náhledu, ani při uložení.
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplier]);
        $revisions = new ImportedPlanRevisionService($this->db, new AssetRepository($this->db), $this->service,
            new AccountingPeriodRepository($this->db), new MockClock('2098-01-15T10:00:00+00:00'), new ActivityLogger($this->db));
        $this->reject(fn () => $revisions->preview($this->supplier, $id, $this->input(), $this->actor), 'imported_plan_revision_not_allowed');
        $this->reject(fn () => $revisions->revise($this->supplier, $id, $this->input() + $this->snapshot($preview), $this->actor), 'imported_plan_revision_not_allowed');
        self::assertSame($this->source(), $this->service->get($this->supplier, $id)['imported_depreciation_plan']);
    }

    public function testMetadataEditCannotOverwriteCompletedConcurrentRevisionOrLifecycle(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $input = $this->input();
        $preview = $this->revisions->preview($this->supplier, $id, $input, $this->actor);
        $pdo = $this->db->pdo();
        $originalClass = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RevisionInterleavingStatement::class]);
        RevisionInterleavingStatement::$beforeAssetUpdate = function () use ($id, $input, $preview): void {
            $this->revisions->revise($this->supplier, $id, $input + $this->snapshot($preview), $this->actor);
            (new AssetRepository($this->db))->update($this->supplier, $id, ['status' => 'disposed', 'disposal_date' => '2098-04-30']);
        };
        try {
            $updated = $this->service->update($this->supplier, $id, ['name' => 'Metadata updated after concurrent revision'])['asset'];
        } finally {
            RevisionInterleavingStatement::$beforeAssetUpdate = null;
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $originalClass);
        }
        self::assertSame('disposed', $updated['status']);
        self::assertCount(1, $updated['imported_depreciation_plan']['revisions'] ?? []);
        self::assertSame('Metadata updated after concurrent revision', $updated['name']);
    }

    private function input(): array
    {
        return ['effective_from' => '2098-02', 'remaining_months' => 3, 'reason' => 'Nový odhad zbývající doby využití', 'expected_revision' => 0];
    }

    private function snapshot(array $preview): array
    {
        return ['expected_calendar_start_month' => $preview['calendar_start_month'], 'expected_calendar_fingerprint' => $preview['calendar_fingerprint']];
    }

    private function assertExactPlan(int $id): void
    {
        $plan = $this->service->plan($this->supplier, $id);
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
    }

    private function reject(callable $call, string $code): void
    {
        try { $call(); self::fail('Expected ' . $code); }
        catch (AssetException $e) { self::assertSame($code, $e->errorCode); }
    }

    private function card(): array
    {
        return ['inventory_number' => 'SYN-IMPORTED-1', 'name' => 'Synthetic imported software', 'kind' => 'intangible',
            'asset_account_code' => '013', 'accumulated_account_code' => '073', 'acquisition_account_code' => '041',
            'input_price' => 96000.0, 'acquisition_date' => '2096-08-03', 'put_into_use_date' => '2096-08-05',
            'status' => 'in_use', 'tax_method' => 'by_accounting', 'acc_method' => 'straight_line',
            'acc_useful_life_months' => 20, 'opening_tax_years' => 2, 'opening_tax_amount' => 60000.0,
            'opening_acc_months' => 16, 'opening_acc_amount' => 60000.0];
    }

    private function source(): array
    {
        return ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'source-tax',
            'source_accounting_method' => 'source-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 899000], ['month' => '2098-02', 'amount_cents' => 899000],
                ['month' => '2098-03', 'amount_cents' => 899000], ['month' => '2098-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 3600000]]];
    }
}

/** Deterministické proložení dokončené druhé mutace mezi čtení a zápis metadat. */
final class RevisionInterleavingStatement extends \PDOStatement
{
    public static ?\Closure $beforeAssetUpdate = null;

    public function execute(?array $params = null): bool
    {
        if (self::$beforeAssetUpdate !== null && preg_match('/^\s*UPDATE\s+assets\s/i', $this->queryString)) {
            $interleave = self::$beforeAssetUpdate;
            self::$beforeAssetUpdate = null;
            $interleave();
        }
        return parent::execute($params);
    }
}
