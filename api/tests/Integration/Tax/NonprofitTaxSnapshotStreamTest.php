<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JointAllocationReadRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\CurrentJ6SourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6SourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotBatchReader;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotMaterializer;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\NonprofitTaxSourceFactProvider;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\NonprofitTaxSourceFactRegistry;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxSnapshotStreamTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $period;
    private NonprofitTaxSnapshotBatchReader $reader;
    private NonprofitTaxSnapshotMaterializer $materializer;
    private NonprofitTaxSnapshotManifestRepository $manifests;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $configured = (string) getenv('MYINVOICE_DB_NAME');
        self::assertStringEndsWith('_test', $configured);
        self::assertSame($configured, (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")
            ->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $this->insertThousandAndTwoTaxLines($container->get(JournalEntryRepository::class));

        $facts = new NonprofitTaxSourceFactRegistry([new class implements NonprofitTaxSourceFactProvider {
            public function schema(): string { return 'synthetic-j6-source.v1'; }
            public function capture(int $supplierId, array $pageRows, bool $currentRead): array
            {
                $out = [];
                foreach ($pageRows as $row) $out[$row['line_id']] = ['line_id' => $row['line_id'], 'tenant' => $supplierId, 'current' => $currentRead];
                return $out;
            }
        }]);
        $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $this->reader = new NonprofitTaxSnapshotBatchReader($this->db, $frameworks, new JointAllocationReadRepository($this->db), $facts);
        $hasher = new NonprofitTaxSnapshotHasher();
        $this->manifests = new NonprofitTaxSnapshotManifestRepository($this->db, $hasher);
        $this->materializer = new NonprofitTaxSnapshotMaterializer($this->db, $this->reader, $hasher, $this->manifests);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReaderPagesByStableTupleWithBoundedQueries(): void
    {
        $oneQueries = $this->selectQueries(fn () => $this->reader->page($this->supplier, $this->period, null, 1, true));
        $fiveHundredQueries = $this->selectQueries(fn () => $this->reader->page($this->supplier, $this->period, null, 500, true));
        self::assertSame($oneQueries, $fiveHundredQueries);
        self::assertLessThanOrEqual(16, $fiveHundredQueries);

        $cursor = null;
        $ids = [];
        $counts = [];
        do {
            $page = $this->reader->page($this->supplier, $this->period, $cursor, 500, true);
            $counts[] = count($page->rows);
            foreach ($page->rows as $row) $ids[] = $row['line_id'];
            if ($page->rows !== []) self::assertArrayHasKey('synthetic-j6-source.v1', $page->rows[0]['module_facts']);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertSame([500, 500, 2], $counts);
        self::assertCount(1002, $ids);
        self::assertCount(1002, array_unique($ids));
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testReaderAdvancesAcrossBalanceLinesWithoutPersistentStagingWrites(): void
    {
        $accounts = [];
        $stmt = $this->pdo->prepare("SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('221','321') ORDER BY account_code");
        $stmt->execute([$this->supplier]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $accounts[$row['account_code']] = (int) $row['id'];
        self::assertSame([221, 321], array_keys($accounts));
        $actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        Bootstrap::buildContainer()->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2026-05-31',
            'source_type' => 'manual', 'description' => 'Synthetic balance-only page prefix',
            'posted_at' => '2026-05-31 12:00:00', 'posted_by' => $actor,
        ], [
            ['account_id' => $accounts['221'], 'side' => 'debit', 'amount' => '1.00'],
            ['account_id' => $accounts['321'], 'side' => 'credit', 'amount' => '1.00'],
        ]);
        $before = $this->persistentCounts();
        $cursor = null;
        $counts = [];
        $rows = 0;
        do {
            $page = $this->reader->page($this->supplier, $this->period, $cursor, 500, true);
            $counts[] = count($page->rows);
            $rows += count($page->rows);
            if ($cursor === null) self::assertSame(500, $page->scannedCount);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        self::assertSame([498, 500, 4], $counts);
        self::assertSame(1002, $rows);
        self::assertSame($before, $this->persistentCounts());
    }

    public function testMaterializerPublishesImmutablePagedManifestAndCleansSpool(): void
    {
        $spool = RuntimePaths::storage('tmp/j6-snapshot-spool');
        $before = is_dir($spool) ? glob($spool . DIRECTORY_SEPARATOR . 'j6-*.ndjson') : [];
        $first = $this->materializer->materializeCurrent($this->supplier, $this->period);
        $after = is_dir($spool) ? glob($spool . DIRECTORY_SEPARATOR . 'j6-*.ndjson') : [];
        self::assertSame($before, $after);
        self::assertSame(1002, $first->rowCount);
        self::assertSame(3, $first->chunkCount);
        self::assertEquals($first, $this->manifests->verify($this->supplier, $first->id, true));
        self::assertEquals($first, $this->materializer->materializeCurrent($this->supplier, $this->period));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_manifests')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_manifest_chunks')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_chunks')->fetchColumn());

        $other = $this->createIsolatedSupplier($this->pdo, $this->supplier);
        $this->expectException(\OutOfBoundsException::class);
        $this->manifests->manifest($other, $first->id, true);
    }

    public function testCurrentAndHistoricAuthoritativeProvidersYieldTheSameBoundedRowsWithoutHistoricWrites(): void
    {
        $current = new CurrentJ6SourceProvider($this->reader,
            new J6ReadContext($this->supplier, $this->period, J6ReadMode::CURRENT, currentRead: true));
        self::assertSame($this->supplier, $current->header()->data['supplier_id']);
        $currentHashes = [];
        foreach ($current->pages(limit: 500) as $page) {
            self::assertLessThanOrEqual(500, $page->scannedCount);
            foreach ($page->rows as $row) $currentHashes[] = (new NonprofitTaxSnapshotHasher())->row($row);
        }
        $manifest = $this->materializer->materializeCurrent($this->supplier, $this->period);
        $historic = new HistoricJ6SourceProvider($this->manifests, new NonprofitTaxSnapshotHasher(),
            new J6ReadContext($this->supplier, $this->period, J6ReadMode::HISTORIC, 9001, $manifest->id));
        $before = $this->persistentCounts();
        self::assertSame($manifest->id, $historic->context()->sourceManifestId);
        self::assertSame($this->period, $historic->header()->data['period_id']);
        $historicHashes = [];
        foreach ($historic->pages(limit: 500) as $page) {
            self::assertLessThanOrEqual(500, count($page->rows));
            foreach ($page->rows as $row) $historicHashes[] = (new NonprofitTaxSnapshotHasher())->row($row);
        }
        self::assertSame($currentHashes, $historicHashes);
        self::assertSame($before, $this->persistentCounts());
    }

    public function testPublishedManifestIsAppendOnly(): void
    {
        $manifest = $this->materializer->materializeCurrent($this->supplier, $this->period);
        try {
            $this->pdo->prepare('UPDATE nonprofit_tax_snapshot_manifests SET row_count=0 WHERE supplier_id=? AND id=?')
                ->execute([$this->supplier, $manifest->id]);
            self::fail('Published snapshot manifest must be immutable.');
        } catch (\PDOException $failure) {
            self::assertStringContainsString('nonprofit_tax_snapshot_immutable', $failure->getMessage());
        }
        self::assertEquals($manifest, $this->manifests->verify($this->supplier, $manifest->id, true));
    }

    public function testFailureAfterFirstChunkRollsBackPublicationAndCleansSpool(): void
    {
        $failingFacts = new NonprofitTaxSourceFactRegistry([new class implements NonprofitTaxSourceFactProvider {
            private int $pages = 0;
            public function schema(): string { return 'synthetic-failing-j6.v1'; }
            public function capture(int $supplierId, array $pageRows, bool $currentRead): array
            {
                if (++$this->pages === 2) throw new \RuntimeException('Synthetic second page failure.');
                return [];
            }
        }]);
        $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $reader = new NonprofitTaxSnapshotBatchReader($this->db, $frameworks, new JointAllocationReadRepository($this->db), $failingFacts);
        $hasher = new NonprofitTaxSnapshotHasher();
        $manifests = new NonprofitTaxSnapshotManifestRepository($this->db, $hasher);
        $materializer = new NonprofitTaxSnapshotMaterializer($this->db, $reader, $hasher, $manifests);
        $spool = RuntimePaths::storage('tmp/j6-snapshot-spool');
        $before = is_dir($spool) ? glob($spool . DIRECTORY_SEPARATOR . 'j6-*.ndjson') : [];
        try {
            $materializer->materializeCurrent($this->supplier, $this->period);
            self::fail('Synthetic second page failure must abort snapshot publication.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Synthetic second page failure.', $failure->getMessage());
        }
        $after = is_dir($spool) ? glob($spool . DIRECTORY_SEPARATOR . 'j6-*.ndjson') : [];
        self::assertSame($before, $after);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_manifests')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_manifest_chunks')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_chunks')->fetchColumn());
    }

    private function insertThousandAndTwoTaxLines(JournalEntryRepository $journal): void
    {
        $ids = [];
        $stmt = $this->pdo->prepare("SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('501','602') ORDER BY account_code");
        $stmt->execute([$this->supplier]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $ids[$row['account_code']] = (int) $row['id'];
        self::assertSame([501, 602], array_keys($ids));
        $lines = [];
        for ($index = 0; $index < 501; ++$index) {
            $lines[] = ['account_id' => $ids['501'], 'side' => 'debit', 'amount' => '0.01'];
            $lines[] = ['account_id' => $ids['602'], 'side' => 'credit', 'amount' => '0.01'];
        }
        $actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $journal->insert(['supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2026-06-01',
            'source_type' => 'manual', 'description' => 'Synthetic paged J6 journal', 'posted_at' => '2026-06-01 12:00:00', 'posted_by' => $actor], $lines);
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }

    private function persistentCounts(): array
    {
        $out = [];
        foreach (['nonprofit_tax_snapshot_chunks', 'nonprofit_tax_snapshot_manifests',
            'nonprofit_tax_snapshot_manifest_chunks'] as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id=?");
            $stmt->execute([$this->supplier]);
            $out[$table] = (int) $stmt->fetchColumn();
        }
        return $out;
    }
}
