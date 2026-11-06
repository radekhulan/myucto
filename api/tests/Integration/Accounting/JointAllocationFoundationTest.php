<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JointAllocationKeyRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalAdapter;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalWriter;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JointAllocationFoundationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JointAllocationKeyRepository $keys;
    private JointAllocationPreviewService $preview;
    private JointAllocationService $allocations;
    private int $supplier;
    private int $period;
    private int $sourceLine;
    private array $cells;
    private ?Connection $otherDb = null;
    private bool $committed = false;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed, 'Synthetic CI seed is required.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier);
        $periods = new AccountingPeriodRepository($this->db);
        $this->period = $periods->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $periods->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $stmt = $pdo->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $entry = $container->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2096-06-01',
            'source_type' => 'manual', 'posted_at' => '2096-06-01 12:00:00', 'description' => 'Synthetic common pool',
        ], [
            ['account_id' => $accounts['518'], 'side' => 'debit', 'amount' => '20123.45'],
            ['account_id' => $accounts['221'], 'side' => 'credit', 'amount' => '20123.45'],
        ]);
        $stmt = $pdo->prepare("SELECT id FROM journal_entry_lines WHERE entry_id = ? AND side = 'debit'");
        $stmt->execute([$entry]);
        $this->sourceLine = (int) $stmt->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplier]);
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-01-01');
        $dimensions = new DimensionRepository($this->db);
        $kindType = ['id' => $catalog['activity_kind']['id'], 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $sport = $dimensions->createValue($kindType, ['code' => 'SPORT', 'name' => 'Synthetic sport']);
        $education = $dimensions->createValue($kindType, ['code' => 'EDUCATION', 'name' => 'Synthetic education']);
        $rental = $dimensions->createValue($kindType, ['code' => 'RENTAL', 'name' => 'Synthetic rental']);
        $main = $catalog['activity']['values']['main'];
        $economic = $catalog['activity']['values']['economic'];
        $subject = $catalog['tax_regime']['values']['subject'];
        $this->cells = [
            ['activity' => $main, 'activity_kind' => $sport, 'tax_regime' => $subject, 'weight' => '60'],
            ['activity' => $main, 'activity_kind' => $education, 'tax_regime' => $subject, 'weight' => '25'],
            ['activity' => $economic, 'activity_kind' => $rental, 'tax_regime' => $subject, 'weight' => '15'],
        ];
        $this->keys = new JointAllocationKeyRepository($this->db);
        $this->preview = new JointAllocationPreviewService($this->db);
        $this->allocations = new JointAllocationService($this->db, new JointAllocationJournalAdapter($container->get(JournalEntryRepository::class)));
    }

    protected function tearDown(): void
    {
        if ($this->otherDb !== null) {
            if ($this->otherDb->pdo()->inTransaction()) $this->otherDb->pdo()->rollBack();
            $this->otherDb->close();
        }
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if ($this->snapshotIsolation !== null) $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
        if ($this->committed) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM joint_allocation_keys WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
    }

    public function testApplyAndCancellationPreserveSourceAndExactTotals(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $preview = $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]);
        $before = $this->state();
        $request = ['key_version_id' => $key['id'], 'target_date' => '2096-06-30', 'source_line_ids' => [$this->sourceLine],
            'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'synthetic-apply', 'reason' => 'Synthetic attendance allocation'];
        $batch = $this->allocations->apply($this->supplier, $request);
        self::assertSame('applied', $batch['status']);
        self::assertSame($batch, $this->allocations->apply($this->supplier, $request));
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT side, amount FROM journal_entry_lines WHERE entry_id = ? ORDER BY line_no');
        $stmt->execute([$batch['journal_entry_id']]);
        self::assertSame([
            ['side' => 'credit', 'amount' => '20123.45'], ['side' => 'debit', 'amount' => '12074.07'],
            ['side' => 'debit', 'amount' => '5030.86'], ['side' => 'debit', 'amount' => '3018.52'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->refused('allocation_source_in_use', fn () => $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]));
        $cancel = ['batch_id' => $batch['id'], 'target_date' => '2096-07-01', 'reason' => 'Synthetic correction', 'idempotency_key' => 'synthetic-cancel'];
        $cancelled = $this->allocations->cancel($this->supplier, $cancel);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame($cancelled, $this->allocations->cancel($this->supplier, $cancel));
        $reader = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $detail = $reader->find($this->supplier, $batch['id']);
        self::assertSame('cancelled', $detail['status']);
        self::assertSame($preview, $detail['snapshot']);
        self::assertSame(['apply', 'cancel'], array_column($detail['audit'], 'action'));
        self::assertSame(['system', 'system'], array_column($detail['audit'], 'actor_source'));
        self::assertSame([null, null], array_column($detail['audit'], 'actor_id'));
        self::assertCount(1, $reader->list($this->supplier));
        self::assertSame([], $reader->list($this->supplier + 1000000));
        $this->refused('allocation_not_found', fn () => $reader->find($this->supplier + 1000000, $batch['id']));
        $stmt = $pdo->prepare("SELECT SUM(IF(side = 'debit', amount, -amount)) FROM journal_entry_lines WHERE entry_id IN (?, ?)");
        $stmt->execute([$batch['journal_entry_id'], $cancelled['cancellation_entry_id']]);
        self::assertSame('0.00', $stmt->fetchColumn());
        $stmt = $pdo->prepare('SELECT * FROM journal_entry_lines WHERE id = ?');
        $stmt->execute([$this->sourceLine]);
        $original = array_values(array_filter($before['journal_entry_lines'], fn (array $line): bool => (int) $line['id'] === $this->sourceLine))[0];
        self::assertSame($original, $stmt->fetch(PDO::FETCH_ASSOC));
        self::assertSame($preview, $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]));
        $request['idempotency_key'] = 'synthetic-corrected';
        self::assertNotSame($batch['id'], $this->allocations->apply($this->supplier, $request)['id']);
    }

    public function testReadProjectionPreservesHistoricalSemanticsAndExactLedgerRoles(): void
    {
        [$request] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $reader = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $cells = $reader->jointCells($this->supplier, $batch['id']);
        self::assertSame(['1207407', '503086', '301852'], array_column($cells, 'amount_cents'));
        self::assertSame(['main', 'main', 'economic'], array_map(static fn (array $cell): string => $cell['axes']['activity']['semantic_key'], $cells));
        self::assertSame('Synthetic sport', $cells[0]['axes']['activity_kind']['name']);
        $this->db->pdo()->prepare('UPDATE dimension_values SET name = ? WHERE id = ? AND supplier_id = ?')
            ->execute(['Synthetic renamed sport', $this->cells[0]['activity_kind'], $this->supplier]);
        self::assertSame($cells, $reader->jointCells($this->supplier, $batch['id']));
        $roles = $reader->ledgerProjection($this->supplier, $batch['id']);
        self::assertCount(5, $roles);
        self::assertSame('source', $roles[$this->sourceLine]['role']);
        self::assertSame('compensation', $roles[$cells[0]['compensation_line_id']]['role']);
        self::assertSame('target', $roles[$cells[0]['line_id']]['role']);
        self::assertSame('2012345', $roles[$this->sourceLine]['amount_cents']);
        self::assertSame('allocation:' . $batch['id'], $roles[$cells[0]['line_id']]['dimension_origins'][$cells[0]['axes']['activity']['type_id']]['ref']);
        $this->allocations->cancel($this->supplier, ['batch_id' => $batch['id'], 'target_date' => '2096-07-01',
            'reason' => 'Synthetic correction', 'idempotency_key' => 'projection-cancel']);
        $cancelled = $reader->jointCells($this->supplier, $batch['id']);
        $roles = $reader->ledgerProjection($this->supplier, $batch['id']);
        self::assertCount(9, $roles);
        self::assertSame('cancellation_target', $roles[$cancelled[0]['cancellation_line_id']]['role']);
        self::assertSame($cells[0]['line_id'], $roles[$cancelled[0]['cancellation_line_id']]['mirrors_line_id']);
        $net = '0';
        foreach ($roles as $row) {
            if ($row['role'] === 'source') continue;
            $net = bcadd($net, $row['side'] === 'debit' ? $row['signed_amount_cents'] : bcsub('0', $row['signed_amount_cents'], 0), 0);
        }
        self::assertSame('0', $net);
        $this->refused('allocation_not_found', fn () => $reader->ledgerProjection($this->supplier + 1000000, $batch['id']));
    }

    public function testBatchLedgerProjectionMatchesSingleProjectionAndHasBoundedQueries(): void
    {
        [$request] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $reader = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $single = $reader->ledgerProjection($this->supplier, $batch['id']);
        $before = (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
        $batched = $reader->ledgerProjectionsForBatches($this->supplier, [$batch['id']]);
        $queries = (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'] - $before;
        self::assertSame([$batch['id'] => $single], $batched);
        self::assertLessThanOrEqual(4, $queries);
        self::assertSame([], $reader->ledgerProjectionsForBatches($this->supplier, []));
    }
    public function testCurrentReadProjectionSeesBatchAndCancellationAfterCallerSnapshot(): void
    {
        [$request] = $this->applyRequest();
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        $pdo->query('SELECT COUNT(*) FROM joint_allocation_batches')->fetchColumn();
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $journal = new JournalEntryRepository($other, $container->get(\MyInvoice\Service\ActivityLogger::class));
        $service = new JointAllocationService($other, new JointAllocationJournalAdapter($journal));
        $batch = $service->apply($this->supplier, $request);
        $reader = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $this->refused('allocation_not_found', fn () => $reader->find($this->supplier, $batch['id']));
        self::assertCount(5, $reader->ledgerProjection($this->supplier, $batch['id'], true));
        $pdo->commit();
        $pdo->beginTransaction();
        self::assertSame('applied', $reader->find($this->supplier, $batch['id'])['status']);
        $service->cancel($this->supplier, ['batch_id' => $batch['id'], 'target_date' => '2096-07-01',
            'reason' => 'Synthetic current read correction', 'idempotency_key' => 'current-read-cancel']);
        self::assertSame('applied', $reader->find($this->supplier, $batch['id'])['status']);
        $rows = $reader->ledgerProjection($this->supplier, $batch['id'], true);
        self::assertCount(9, $rows);
        self::assertSame(['cancelled'], array_values(array_unique(array_column($rows, 'status'))));
    }

    public function testLarge500JournalChecksAssignmentOwnershipAsOneBatch(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')->execute([$this->supplier]);
        $repo = new DimensionRepository($this->db);
        $type = $repo->createType($this->supplier, false, ['code' => 'BATCH', 'name' => 'Synthetic batch', 'kind' => 'custom']);
        $value = $repo->createValue(['id' => $type, 'supplier_id' => $this->supplier, 'supplier_group_id' => null], ['code' => 'A', 'name' => 'Synthetic A']);
        $stmt = $pdo->prepare('SELECT account_id FROM journal_entry_lines WHERE id = ?');
        $stmt->execute([$this->sourceLine]);
        $account = (int) $stmt->fetchColumn();
        $lines = [];
        for ($i = 0; $i < 1000; $i++) {
            $lines[] = ['account_id' => $account, 'side' => $i % 2 === 0 ? 'debit' : 'credit', 'amount' => '0.01',
                'dimensions' => [$type => $value], 'dimension_origins' => [$type => ['origin' => 'explicit', 'ref' => null]]];
        }
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
        $journal->insert(['supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2096-05-31', 'source_type' => 'manual'], array_slice($lines, 0, 2));
        $pairQueries = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'] - $before;
        $before = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
        $entry = $journal->insert(['supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2096-06-01', 'source_type' => 'manual'], $lines);
        $queries = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'] - $before;
        self::assertSame($pairQueries + 1, $queries, 'The membership owner guard must add only the second 500-line batch, not a query per line.');
        // +2 pevné dotazy: strážci vložení dimenzí čtou zápisy řádků a jejich odkazy jednou za zápis.
        self::assertLessThanOrEqual(21, $queries, 'Current conversion context, issued membership ownership and line-to-entry proof must stay bounded for all 1000 rows.');
        $stmt = $pdo->prepare("SELECT COUNT(*), SUM(IF(l.side = 'debit', l.amount, -l.amount)) FROM journal_entry_lines l
            JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
            WHERE l.entry_id = ? AND d.dimension_type_id = ? AND d.dimension_value_id = ? AND d.assignment_origin = 'explicit'");
        $stmt->execute([$entry, $type, $value]);
        self::assertSame([1000, '0.00'], $stmt->fetch(PDO::FETCH_NUM));
    }

    public function testActiveClaimBlocksDirectAssignmentsButAllowsNoOp(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $preview = $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]);
        $this->allocations->apply($this->supplier, ['key_version_id' => $key['id'], 'target_date' => '2096-06-30',
            'source_line_ids' => [$this->sourceLine], 'preview_hash' => $preview['preview_hash'],
            'idempotency_key' => 'synthetic-guard', 'reason' => 'Synthetic allocation']);
        $repo = new DimensionAssignmentRepository($this->db);
        self::assertFalse($repo->replaceLineDimensions($this->supplier, $this->sourceLine, []));
        $before = $this->state();
        $this->refused('allocation_source_in_use', fn () => $repo->replaceLineDimensions($this->supplier, $this->sourceLine,
            [$preview['type_ids']['activity'] => $this->cells[0]['activity']]));
        self::assertSame($before, $this->state());
        $this->refused('allocation_source_in_use', fn () => $repo->insertLineDimensions($this->supplier, $this->sourceLine,
            [$preview['type_ids']['activity'] => $this->cells[0]['activity']]));
        $shares = [$preview['type_ids']['activity_kind'] => [$this->cells[0]['activity_kind'] => 0.5, $this->cells[1]['activity_kind'] => 0.5]];
        $this->refused('allocation_source_in_use', fn () => $repo->replaceLineSplits($this->supplier, $this->sourceLine, $shares));
        $this->refused('allocation_source_in_use', fn () => $repo->insertLineSplits($this->supplier, $this->sourceLine, $shares));
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id = ? AND side = 'credit'");
        $stmt->execute([$preview['source_lines'][0]['entry_id']]);
        $unclaimed = (int) $stmt->fetchColumn();
        $data = ['dimensions' => [$preview['type_ids']['activity'] => $this->cells[0]['activity']]];
        $this->refused('allocation_source_in_use', fn () => $repo->insertLineAssignments($this->supplier, [$unclaimed => $data, $this->sourceLine => $data]));
        self::assertSame($before, $this->state());
    }

    public function testActiveClaimAndAllocationEntryRejectFinancialMutators(): void
    {
        [$request, $preview] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $sourceEntryId = (int) $preview['source_lines'][0]['entry_id'];
        $source = $journal->findForUpdate($sourceEntryId, $this->supplier);
        $before = $this->state();
        $traceService = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass::class);
        $this->refused('allocation_source_in_use', fn () => (new \ReflectionMethod($traceService, 'fillForeignTrace'))->invoke(
            $traceService, $this->supplier, $sourceEntryId,
            [['account_code' => '518', 'side' => 'debit', 'amount' => '20123.45', 'currency_code' => 'EUR', 'fx_rate' => '201.2345', 'amount_foreign' => '100.00']], true));
        self::assertSame($before, $this->state());
        foreach ([$sourceEntryId => 'allocation_source_in_use', $batch['journal_entry_id'] => 'allocation_entry_immutable'] as $id => $code) {
            $entry = $journal->findForUpdate($id, $this->supplier);
            $this->refused($code, fn () => $journal->replace($id, $entry, $entry['lines']));
            $this->refused($code, fn () => $journal->replaceSomeLines($id, $this->supplier, (int) $entry['row_version'], [], []));
            $this->refused($code, fn () => $journal->markPosted($id, $this->supplier, null));
            $this->refused($code, fn () => $journal->detachSource($id, $this->supplier));
            $this->refused($code, fn () => $journal->setReversedBy($id, $this->supplier, $batch['journal_entry_id']));
            $this->refused($code, fn () => $journal->insertReversal($entry, $entry['lines'], $id));
        }
        self::assertSame($before, $this->state());
        try {
            $this->db->pdo()->prepare('DELETE FROM journal_entry_lines WHERE id = ?')->execute([$this->sourceLine]);
            self::fail('Live source claim must protect deletion at the database boundary.');
        } catch (\PDOException $e) {
            self::assertSame(1451, (int) $e->errorInfo[1]);
        }
        self::assertSame($source['lines'], $journal->findForUpdate($sourceEntryId, $this->supplier)['lines']);
    }

    public function testStalePreviewAndWriterFailureLeaveNoBatchClaimsOrJournal(): void
    {
        [$request] = $this->applyRequest();
        $before = $this->state();
        $stale = $request;
        $stale['preview_hash'] = str_repeat('0', 64);
        $this->refused('allocation_preview_stale', fn () => $this->allocations->apply($this->supplier, $stale));
        self::assertSame($before, $this->state());
        $adapter = new JointAllocationJournalAdapter(Bootstrap::buildContainer()->get(JournalEntryRepository::class));
        $broken = new class($adapter) implements JointAllocationJournalWriter {
            public function __construct(private JointAllocationJournalWriter $writer) {}
            public function insertCurrentAllocation(int $supplierId, int $batchId): int {
                $this->writer->insertCurrentAllocation($supplierId, $batchId);
                throw new JointAllocationException('synthetic_failure', 'Synthetic failure after journal persistence.');
            }
            public function reverseCurrentAllocation(int $supplierId, int $cancellationId): int {
                $this->writer->reverseCurrentAllocation($supplierId, $cancellationId);
                throw new JointAllocationException('synthetic_failure', 'Synthetic failure after cancellation persistence.');
            }
        };
        $service = new JointAllocationService($this->db, $broken);
        $this->refused('synthetic_failure', fn () => $service->apply($this->supplier, $request));
        self::assertSame($before, $this->state());
        $batch = $this->allocations->apply($this->supplier, $request);
        $before = $this->state();
        $this->refused('synthetic_failure', fn () => $service->cancel($this->supplier, ['batch_id' => $batch['id'],
            'target_date' => '2096-07-01', 'idempotency_key' => 'synthetic-failed-cancel', 'reason' => 'Synthetic cancellation']));
        self::assertSame($before, $this->state());
    }

    private function applyRequest(): array
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $preview = $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]);
        return [['key_version_id' => $key['id'], 'target_date' => '2096-06-30', 'source_line_ids' => [$this->sourceLine],
            'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'synthetic-apply', 'reason' => 'Synthetic allocation'], $preview];
    }

    public function testDocumentOwnerRefusesBeforeChangingHeaderItemsOrSplits(): void
    {
        $repo = new DimensionRepository($this->db);
        $repo->setEnabled($this->supplier, true);
        $typeId = $repo->createType($this->supplier, false, ['code' => 'LOCATION', 'name' => 'Synthetic location', 'kind' => 'custom']);
        $type = ['id' => $typeId, 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $a = $repo->createValue($type, ['code' => 'A', 'name' => 'Synthetic A']);
        $b = $repo->createValue($type, ['code' => 'B', 'name' => 'Synthetic B']);
        $items = new \MyInvoice\Repository\OtherItemRepository($this->db);
        $doc = $items->insert($this->supplier, ['side' => 'payable', 'kind' => 'liability', 'title' => 'Synthetic liability',
            'partner_id' => null, 'partner_name' => 'Synthetic partner', 'issued_on' => '2096-06-01', 'accounting_on' => '2096-06-01',
            'due_on' => '2096-06-30', 'currency' => 'CZK', 'amount' => '20123.45', 'exchange_rate' => '1', 'amount_czk' => '20123.45',
            'variable_symbol' => null, 'account_code' => '379', 'counter_account_code' => '518', 'note' => null], null);
        $stmt = $this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE id = ?');
        $stmt->execute([$this->sourceLine]);
        $entryId = (int) $stmt->fetchColumn();
        $this->db->pdo()->prepare("UPDATE journal_entries SET source_type = 'other_item', source_id = ? WHERE id = ?")->execute([$doc, $entryId]);
        $items->setPosted($this->supplier, $doc, 'posted', 'SYNTHETIC-2096', $entryId);
        $assignments = new DimensionAssignmentRepository($this->db);
        $assignments->replaceDocumentDimensions($this->supplier, 'other_item', $doc, [$typeId => $a], [1 => [$typeId => $a]]);
        $assignments->replaceLineDimensions($this->supplier, $this->sourceLine, [$typeId => $a]);
        [$request] = $this->applyRequest();
        $this->allocations->apply($this->supplier, $request);
        $before = $this->state();
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        try {
            $dimensions->saveDocument($this->supplier, 'other_item', $doc, [$typeId => $b], [1 => [$typeId => $b]], false,
                [0 => [$typeId => [['value_id' => $a, 'share' => 0.5], ['value_id' => $b, 'share' => 0.5]]]]);
            self::fail('Document owner changed metadata of an active allocation source.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            self::assertSame('allocation_source_in_use', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame($before, $this->state());
    }

    public function testExistingDeleteEndpointReturns409AndKeepsAllocationSource(): void
    {
        [$request, $preview] = $this->applyRequest();
        $this->allocations->apply($this->supplier, $request);
        $before = $this->state();
        $http = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/accounting/journal')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        $response = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\JournalAction::class)->delete($http, new \Slim\Psr7\Response(),
            ['id' => (int) $preview['source_lines'][0]['entry_id']]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('allocation_source_in_use', (string) $response->getBody());
        self::assertSame($before, $this->state());
    }

    public function testRedStornoAndForeignAmountsAreAllocatedAndCancelledIndependently(): void
    {
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET is_red_storno = 1, currency_code = 'EUR', amount_foreign = 101.01, fx_rate = 199.123456 WHERE supplier_id = ?")
            ->execute([$this->supplier]);
        [$request] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $stmt = $this->db->pdo()->prepare('SELECT side, amount, is_red_storno, amount_foreign, fx_rate FROM journal_entry_lines WHERE entry_id = ? ORDER BY line_no');
        $stmt->execute([$batch['journal_entry_id']]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['101.01', '60.61', '25.25', '15.15'], array_column($lines, 'amount_foreign'));
        self::assertSame(['debit', 'debit', 'debit', 'debit'], array_column($lines, 'side'));
        self::assertSame([0, 1, 1, 1], array_column($lines, 'is_red_storno'));
        foreach ($lines as $line) self::assertSame(0, bccomp('199.123456', $line['fx_rate'], 6));
        $cancelled = $this->allocations->cancel($this->supplier, ['batch_id' => $batch['id'], 'target_date' => '2096-07-01',
            'reason' => 'Synthetic red correction', 'idempotency_key' => 'synthetic-red-cancel']);
        $stmt->execute([$cancelled['cancellation_entry_id']]);
        $mirror = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['credit', 'debit', 'debit', 'debit'], array_column($mirror, 'side'));
        self::assertSame([0, 0, 0, 0], array_column($mirror, 'is_red_storno'));
        self::assertSame(array_column($lines, 'amount'), array_column($mirror, 'amount'));
        self::assertSame(array_column($lines, 'amount_foreign'), array_column($mirror, 'amount_foreign'));
    }

    public function testConcurrentAllocationCannotClaimAlreadyOwnedSource(): void
    {
        [$request] = $this->applyRequest();
        $other = $this->commitAndConnect();
        $this->db->pdo()->beginTransaction();
        $batch = $this->allocations->apply($this->supplier, $request);
        $other->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $otherJournal = new JournalEntryRepository($other, $container->get(\MyInvoice\Service\ActivityLogger::class));
        $otherService = new JointAllocationService($other, new JointAllocationJournalAdapter($otherJournal));
        $request['idempotency_key'] = 'synthetic-concurrent';
        try {
            $otherService->apply($this->supplier, $request);
            self::fail('Concurrent allocation bypassed the period lock.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) $e->errorInfo[1]);
        }
        $this->db->pdo()->commit();
        $this->refused('allocation_source_in_use', fn () => $otherService->apply($this->supplier, $request));
        self::assertGreaterThan(0, $batch['journal_entry_id']);
    }

    public function testNewEntryAssignmentDoesNotExpandTargetPeriodLocksToOlderYears(): void
    {
        $other = $this->commitAndConnect();
        $other->pdo()->beginTransaction();
        $stmt = $other->pdo()->prepare('SELECT id FROM accounting_periods WHERE id = ? FOR UPDATE');
        $stmt->execute([$this->period]);
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = 2097 FOR UPDATE');
        $stmt->execute([$this->supplier]);
        $period = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '518'");
        $stmt->execute([$this->supplier]);
        $account = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT system_key, id FROM dimension_types WHERE supplier_id = ? AND system_key IS NOT NULL');
        $stmt->execute([$this->supplier]);
        $types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $dims = [];
        foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) $dims[(int) $types[$axis]] = $this->cells[0][$axis];
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $id = $journal->insert(['supplier_id' => $this->supplier, 'period_id' => $period, 'entry_date' => '2097-03-01',
            'source_type' => 'manual', 'posted_at' => '2097-03-01 12:00:00'], [
            ['account_id' => $account, 'side' => 'debit', 'amount' => '1.00', 'dimensions' => $dims],
            ['account_id' => $account, 'side' => 'credit', 'amount' => '1.00', 'dimensions' => $dims],
        ]);
        self::assertGreaterThan(0, $id);
    }

    public function testExactCalculatorMatchesActualDimensionReportSqlResiduals(): void
    {
        $repo = new DimensionRepository($this->db);
        $typeId = $repo->createType($this->supplier, false, ['code' => 'SHARES', 'name' => 'Synthetic shares', 'kind' => 'custom']);
        $type = ['id' => $typeId, 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $values = [];
        foreach (['A', 'B', 'C'] as $code) $values[] = $repo->createValue($type, ['code' => $code, 'name' => 'Synthetic ' . $code]);
        $assignments = new DimensionAssignmentRepository($this->db);
        foreach ([['0.01', ['0.5', '0.5']], ['0.02', ['0.333333', '0.333333', '0.333333']],
            ['20123.45', ['0.6', '0.25', '0.15']], ['0.01', ['0.2', '0.3', '0.2']]] as [$amount, $weights]) {
            $shares = array_combine(array_slice($values, 0, count($weights)), $weights);
            $assignments->replaceLineSplits($this->supplier, $this->sourceLine, [$typeId => $shares]);
            $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount = ? WHERE id = ?')->execute([$amount, $this->sourceLine]);
            [$sql, $params] = \MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation::partsSql($typeId, null, $this->supplier);
            $stmt = $this->db->pdo()->prepare('SELECT amount FROM (' . $sql . ') allocation ORDER BY value_id');
            $stmt->execute($params);
            $actual = array_map(static fn (string $part): string => bcadd($part, '0', 2), $stmt->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(array_column(\MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator::apportion($amount, $weights)['parts'], 'amount'), $actual);
        }
    }

    public function testNewActionEnforcesWritePermissionAndReturnsActionablePreview(): void
    {
        [$input] = $this->applyRequest();
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\JointAllocationAction::class);
        $http = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/allocations')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withParsedBody($input);
        $before = $this->state();
        self::assertSame(403, $action->apply($http, new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame($before, $this->state());
        $http = $http->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session');
        $response = $action->preview($http, new \Slim\Psr7\Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('12074.07', (string) $response->getBody());
        self::assertSame(422, $action->createKey($http->withParsedBody(['code' => [], 'name' => 'Synthetic', 'version' => []]), new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame($before, $this->state());
    }

    public function testMutationsRequireSessionEvenWithWritePermission(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\JointAllocationAction::class);
        $http = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/joint-allocations')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody([]);
        $before = $this->state();
        foreach (['bearer', null] as $method) {
            $request = $http->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $method);
            foreach (['createKey', 'createVersion', 'apply', 'cancel'] as $operation) {
                $response = $action->$operation($request, new \Slim\Psr7\Response(), ['id' => 1]);
                self::assertSame(403, $response->getStatusCode(), $operation . ':' . ($method ?? 'missing'));
                self::assertStringContainsString('allocation_session_only', (string) $response->getBody());
                self::assertSame($before, $this->state());
            }
            self::assertSame(200, $action->keys($request, new \Slim\Psr7\Response())->getStatusCode());
        }
    }

    public function testAssignmentInsertFindsClaimedLineCreatedAfterCallerSnapshot(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        $pdo->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn();
        $other->pdo()->beginTransaction();
        $other->pdo()->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at) VALUES (?, ?, '2096-06-01', 'manual', '2096-06-01 12:00:00')")
            ->execute([$this->supplier, $this->period]);
        $entryId = (int) $other->pdo()->lastInsertId();
        $other->pdo()->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) SELECT ?, supplier_id, account_id, side, amount, 0 FROM journal_entry_lines WHERE id = ?')
            ->execute([$entryId, $this->sourceLine]);
        $newLine = (int) $other->pdo()->lastInsertId();
        $other->pdo()->prepare("INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) SELECT ?, supplier_id, account_id, 'credit', amount, 1 FROM journal_entry_lines WHERE id = ?")
            ->execute([$entryId, $this->sourceLine]);
        $preview = (new JointAllocationPreviewService($other))->preview($this->supplier, $key['id'], '2096-06-30', [$newLine]);
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $journal = new JournalEntryRepository($other, $container->get(\MyInvoice\Service\ActivityLogger::class));
        (new JointAllocationService($other, new JointAllocationJournalAdapter($journal)))->apply($this->supplier,
            ['key_version_id' => $key['id'], 'target_date' => '2096-06-30', 'source_line_ids' => [$newLine],
                'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'synthetic-new-line', 'reason' => 'Synthetic allocation']);
        $other->pdo()->commit();
        $this->refused('allocation_source_in_use', fn () => (new DimensionAssignmentRepository($this->db))->insertLineDimensions(
            $this->supplier, $newLine, [$preview['type_ids']['activity'] => $this->cells[0]['activity']]));
        $data = ['dimensions' => [$preview['type_ids']['activity'] => $this->cells[0]['activity']]];
        $this->refused('allocation_source_in_use', fn () => (new DimensionAssignmentRepository($this->db))->insertLineAssignments(
            $this->supplier, [$this->sourceLine => $data, $newLine => $data]));
        self::assertSame([], (new DimensionAssignmentRepository($this->db))->lineDimensions($this->supplier, [$newLine], true));
    }

    public function testVersionIntervalsAndRejectedOverlapAreAtomic(): void
    {
        $first = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $next = $this->keys->addVersion($this->supplier, $first['key_id'], $this->version(2097));
        self::assertSame(1, $first['version_no']);
        self::assertSame(2, $next['version_no']);
        $before = $this->state();
        $this->refused('allocation_key_overlap', fn () => $this->keys->addVersion($this->supplier, $first['key_id'], $this->version()));
        self::assertSame($before, $this->state());
        self::assertSame($first['content_hash'], $this->keys->findForUpdate($this->supplier, $first['id'])['content_hash']);
    }

    public function testReferencePreviewHasExactCellsAndDoesNotWriteAnything(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $before = $this->state();
        $preview = $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]);
        self::assertSame(['12074.07', '5030.86', '3018.52'], array_column($preview['plan']['sources'][0]['targets'], 'amount'));
        self::assertSame('20123.45', $preview['plan']['sources'][0]['compensation']['amount']);
        self::assertSame('credit', $preview['plan']['sources'][0]['compensation']['side']);
        self::assertSame($preview, $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]));
        self::assertSame($before, $this->state());
    }

    public function testClosedSourceCanOnlyBeCorrectedInOpenLaterTarget(): void
    {
        $current = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $next = $this->keys->addVersion($this->supplier, $current['key_id'], $this->version(2097));
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $before = $this->state();
        $this->refused('period_not_open', fn () => $this->preview->preview($this->supplier, $current['id'], '2096-06-30', [$this->sourceLine]));
        $result = $this->preview->preview($this->supplier, $next['id'], '2097-01-01', [$this->sourceLine]);
        self::assertSame('2096-06-01', $result['source_lines'][0]['source_date']);
        self::assertSame('2097-01-01', $result['target_date']);
        self::assertSame($before, $this->state());
    }

    public function testForeignTenantCannotReadKeyOrSource(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $this->refused('allocation_key_not_found', fn () => $this->keys->findForUpdate($this->supplier + 1000000, $key['id']));
        $this->refused('allocation_source_not_found', fn () => $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine + 1000000]));
    }

    public function testFramework500KeyRefusalHasNoWrites(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $before = $this->state();
        $this->refused('allocation_framework_unsupported', fn () => $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version()));
        self::assertSame($before, $this->state());
    }

    public function testOldCallerSnapshotUsesCurrentAmountAfterOtherConnectionClosesSource(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version(2097));
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        $old = $pdo->prepare('SELECT amount FROM journal_entry_lines WHERE id = ?');
        $old->execute([$this->sourceLine]);
        self::assertSame('20123.45', $old->fetchColumn());
        $other->pdo()->beginTransaction();
        $other->pdo()->prepare('UPDATE journal_entry_lines SET amount = 200.00 WHERE supplier_id = ?')->execute([$this->supplier]);
        $other->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);
        $other->pdo()->commit();
        $result = $this->preview->preview($this->supplier, $key['id'], '2097-01-01', [$this->sourceLine]);
        self::assertSame('200.00', $result['source_lines'][0]['amount']);
        self::assertSame(['120.00', '50.00', '30.00'], array_column($result['plan']['sources'][0]['targets'], 'amount'));
    }

    public function testPreviewPeriodLockSerializesConcurrentVersionWriter(): void
    {
        $key = $this->keys->create($this->supplier, 'HOURS', 'Synthetic hours', $this->version());
        $other = $this->commitAndConnect();
        $this->db->pdo()->beginTransaction();
        $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]);
        $other->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            (new JointAllocationKeyRepository($other))->addVersion($this->supplier, $key['key_id'], $this->version(2097));
            self::fail('Concurrent writer acquired a period held by the preview transaction.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) $e->errorInfo[1]);
        }
        $this->db->pdo()->commit();
        $next = (new JointAllocationKeyRepository($other))->addVersion($this->supplier, $key['key_id'], $this->version(2097));
        self::assertSame(2, $next['version_no']);
    }

    private function commitAndConnect(): Connection
    {
        $this->db->pdo()->commit();
        $this->committed = true;
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $this->otherDb = Connection::withoutSharedTestConnection(fn () => $container->get(Connection::class));
        $this->otherDb->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $this->otherDb;
    }

    public function testFullTupleExactCurrenciesApplyCurrentProofProjectionCancelAndReuse(): void
    {
        $repo = new DimensionRepository($this->db);
        $type = $repo->createType($this->supplier, false, ['code' => 'ROOM', 'name' => 'Synthetic rooms', 'kind' => 'custom']);
        $definition = ['id' => $type, 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $a = $repo->createValue($definition, ['code' => 'A', 'name' => 'Synthetic room A']);
        $b = $repo->createValue($definition, ['code' => 'B', 'name' => 'Synthetic room B']);
        (new DimensionAssignmentRepository($this->db))->insertLineSplits($this->supplier, $this->sourceLine, [$type => [$a => '0.5', $b => '0.5']]);
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET amount_foreign = '100.01', currency_code = 'EUR', fx_rate = '201.21437856' WHERE supplier_id = ?")
            ->execute([$this->supplier]);
        $this->cells = array_slice($this->cells, 0, 2);
        $this->cells[0]['additional_dimensions'] = [$type => $a];
        $this->cells[1]['additional_dimensions'] = [$type => $b];
        $version = $this->version() + ['allocation_mode' => 'explicit_amounts'];
        $key = $this->keys->create($this->supplier, 'EXACT', 'Synthetic explicit tuple', $version);
        $exact = [['source_line_id' => $this->sourceLine, 'evidence' => 'Synthetic measured rooms. Preserve booked CZK and EUR margins independently.', 'cells' => [
            ['cell_id' => \MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator::cellId($this->cells[0]), 'amount' => '10061.72', 'amount_foreign' => '50.00'],
            ['cell_id' => \MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator::cellId($this->cells[1]), 'amount' => '10061.73', 'amount_foreign' => '50.01'],
        ]]];
        $candidates = new \MyInvoice\Service\Accounting\Allocation\JointAllocationCandidateService($this->db);
        self::assertContains($this->sourceLine, array_column($candidates->list($this->supplier, ['target_date' => '2096-06-30'])['items'], 'id'));
        self::assertSame([], $candidates->list($this->supplier + 1000000, ['target_date' => '2096-06-30'])['items']);
        $preview = $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine], $exact);
        $request = ['key_version_id' => $key['id'], 'target_date' => '2096-06-30', 'source_line_ids' => [$this->sourceLine],
            'preview_hash' => $preview['preview_hash'], 'exact_cells' => $exact, 'idempotency_key' => 'explicit-apply', 'reason' => 'Synthetic measured allocation'];
        $before = $this->state();
        $bad = $request;
        $bad['exact_cells'][0]['cells'][0]['amount_foreign'] = '50.01';
        $this->refused('allocation_exact_total_mismatch', fn () => $this->allocations->apply($this->supplier, $bad));
        self::assertSame($before, $this->state());
        $bad = $request;
        $bad['exact_cells'][0]['evidence'] = 'Different synthetic evidence after preview';
        $this->refused('allocation_preview_stale', fn () => $this->allocations->apply($this->supplier, $bad));
        self::assertSame($before, $this->state());
        $batch = $this->allocations->apply($this->supplier, $request);
        self::assertSame($batch, $this->allocations->apply($this->supplier, $request));
        self::assertSame([], $candidates->list($this->supplier, ['target_date' => '2096-06-30'])['items']);
        $reader = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $cells = $reader->jointCells($this->supplier, $batch['id'], true);
        self::assertSame(['1006172', '1006173'], array_column($cells, 'amount_cents'));
        self::assertSame(['5000', '5001'], array_column($cells, 'foreign_amount_cents'));
        self::assertSame([$a, $b], array_map(static fn (array $cell): int => $cell['additional_axes'][$type]['value_id'], $cells));
        $projection = $reader->ledgerProjection($this->supplier, $batch['id'], true);
        foreach ($cells as $cell) {
            self::assertSame([], $projection[$cell['line_id']]['dimension_splits']);
            self::assertSame(['origin' => 'module', 'ref' => 'allocation:' . $batch['id']], $projection[$cell['line_id']]['dimension_origins'][$type]);
        }
        $this->allocations->cancel($this->supplier, ['batch_id' => $batch['id'], 'target_date' => '2096-07-01', 'reason' => 'Synthetic correction', 'idempotency_key' => 'explicit-cancel']);
        self::assertContains($this->sourceLine, array_column($candidates->list($this->supplier, ['target_date' => '2096-07-01'])['items'], 'id'));
        self::assertSame($preview['plan'], $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine], $exact)['plan']);
    }

    public function testAdditionalAxisCannotUseAnotherTenantAndLegacyListVersionsRemainReadable(): void
    {
        $key = $this->keys->create($this->supplier, 'LEGACY', 'Synthetic legacy list', $this->version());
        $this->db->pdo()->prepare('UPDATE joint_allocation_key_versions SET cells_json = ? WHERE id = ?')
            ->execute([json_encode($key['cells'], JSON_THROW_ON_ERROR), $key['id']]);
        self::assertSame('weighted', $this->keys->findForUpdate($this->supplier, $key['id'])['allocation_mode']);
        self::assertSame($key['cells'], $this->keys->list($this->supplier)[0]['cells']);
        $legacy = \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::normalize($key);
        unset($legacy['content_hash'], $legacy['allocation_mode']);
        $this->db->pdo()->prepare('UPDATE joint_allocation_key_versions SET content_hash = ? WHERE id = ?')
            ->execute([\MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::hash($legacy), $key['id']]);
        self::assertSame('weighted', $this->keys->findForUpdate($this->supplier, $key['id'])['allocation_mode']);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $repo = new DimensionRepository($this->db);
        $type = $repo->createType($other, false, ['code' => 'FOREIGN', 'name' => 'Synthetic foreign room', 'kind' => 'custom']);
        $value = $repo->createValue(['id' => $type, 'supplier_id' => $other, 'supplier_group_id' => null], ['code' => 'A', 'name' => 'Synthetic room']);
        foreach ($this->cells as &$cell) $cell['additional_dimensions'] = [$type => $value];
        unset($cell);
        $before = $this->state();
        $this->refused('allocation_invalid_types', fn () => $this->keys->create($this->supplier, 'BAD', 'Synthetic rejected tuple', $this->version()));
        self::assertSame($before, $this->state());
    }

    public function testCorruptAllocationSnapshotCannotBeReadAsEvidence(): void
    {
        [$request] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $this->db->pdo()->prepare("UPDATE joint_allocation_batches SET snapshot_json = JSON_SET(snapshot_json, '$.key.explanation', 'Synthetic altered evidence') WHERE id = ?")
            ->execute([$batch['id']]);
        $before = $this->state();
        $this->refused('allocation_changed', fn () => (new \MyInvoice\Repository\JointAllocationReadRepository($this->db))->find($this->supplier, $batch['id']));
        self::assertSame($before, $this->state());
    }

    public function testCorruptAllocationSnapshotCannotReleaseItsClaim(): void
    {
        [$request] = $this->applyRequest();
        $batch = $this->allocations->apply($this->supplier, $request);
        $this->db->pdo()->prepare("UPDATE joint_allocation_batches SET snapshot_json = JSON_SET(snapshot_json, '$.key.explanation', 'Synthetic altered evidence') WHERE id = ?")
            ->execute([$batch['id']]);
        $before = $this->state();
        $this->refused('allocation_changed', fn () => $this->allocations->cancel($this->supplier, ['batch_id' => $batch['id'],
            'target_date' => '2096-07-01', 'reason' => 'Synthetic cancellation', 'idempotency_key' => 'corrupt-cancel']));
        self::assertSame($before, $this->state());
    }

    public function testCorruptKeyHashCannotProduceNewAllocationPreview(): void
    {
        $key = $this->keys->create($this->supplier, 'CORRUPT', 'Synthetic key', $this->version());
        $this->db->pdo()->prepare('UPDATE joint_allocation_key_versions SET content_hash = ? WHERE id = ?')
            ->execute([str_repeat('0', 64), $key['id']]);
        $before = $this->state();
        $this->refused('allocation_changed', fn () => $this->preview->preview($this->supplier, $key['id'], '2096-06-30', [$this->sourceLine]));
        self::assertSame($before, $this->state());
    }

    public function testPropertyKeyIsStructuralButDatedTargetsRequireProfileAndExactCancellationRemainsPossible(): void
    {
        $actor=(int)$this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $profiles=new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db);
        $profile=['expected_revision'=>0,'effective_from'=>'2096-01-01T00:00:00+01:00','enabled'=>true,'reason'=>'Synthetic','title_ref'=>'SYN','idempotency_key'=>'first'];
        $profiles->save($this->supplier,$profile,$actor);
        $object=(new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db))->create($this->supplier,'service',[
            'code'=>'WATER','expected_revision'=>0,'status'=>'confirmed','effective_from'=>'2096-01-01T00:00:00+01:00',
            'state'=>['name'=>'Synthetic water','active'=>true,'measure'=>'unit','measure_label'=>''],'reason'=>'Synthetic','title_ref'=>'SYN','idempotency_key'=>'service'],$actor);
        $value=(new \MyInvoice\Repository\Property\PropertyReadRepository($this->db))->find($this->supplier,$object['id'],'2096-06-01T00:00:00+02:00')['dimension_value_id'];
        $q=$this->db->pdo()->prepare('SELECT type_id FROM dimension_values WHERE id=?');$q->execute([$value]);$type=(int)$q->fetchColumn();
        $assignments=new DimensionAssignmentRepository($this->db);
        $existing=$assignments->lineDimensions($this->supplier,[$this->sourceLine],true)[$this->sourceLine] ?? [];
        $assignments->replaceLineDimensions($this->supplier,$this->sourceLine,$existing+[$type=>(int)$value]);
        $version=$this->version();foreach ($version['cells'] as &$cell) $cell['additional_dimensions']=[$type=>(int)$value];unset($cell);
        $key=$this->keys->create($this->supplier,'PROPERTY','Synthetic property key',$version);
        $preview=$this->preview->preview($this->supplier,$key['id'],'2096-06-30',[$this->sourceLine]);
        $batch=$this->allocations->apply($this->supplier,['key_version_id'=>$key['id'],'target_date'=>'2096-06-30','source_line_ids'=>[$this->sourceLine],
            'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'property-apply','reason'=>'Synthetic']);
        self::assertSame('applied',$batch['status']);
        $profile['expected_revision']=1;$profile['enabled']=false;$profile['effective_from']='2096-07-01T00:00:00+02:00';$profile['idempotency_key']='disabled';$profiles->save($this->supplier,$profile,$actor);
        $structural=$this->keys->create($this->supplier,'STRUCTURAL','Synthetic undated key',$version);
        self::assertGreaterThan(0,$structural['id']);
        $cancelled=$this->allocations->cancel($this->supplier,['batch_id'=>$batch['id'],'target_date'=>'2096-08-01','reason'=>'Synthetic correction','idempotency_key'=>'property-cancel']);
        self::assertSame('cancelled',$cancelled['status']);
        $before=$this->state();
        $this->refused('property_profile_required',fn()=>$this->preview->preview($this->supplier,$structural['id'],'2096-08-01',[$this->sourceLine]));
        self::assertSame($before,$this->state());
    }

    private function version(int $year = 2096): array
    {
        return ['valid_from' => $year . '-01-01', 'valid_to' => ($year + 1) . '-01-01', 'basis' => 'hours',
            'explanation' => 'Synthetic attendance allocation', 'cells' => $this->cells];
    }

    private function state(): array
    {
        $out = [];
        foreach (['joint_allocation_keys', 'joint_allocation_key_versions', 'joint_allocation_batches', 'joint_allocation_source_claims', 'joint_allocation_cancellations', 'joint_allocation_audit', 'journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'journal_entry_line_dimension_splits', 'document_dimensions', 'document_dimension_splits'] as $table) {
            $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id = ?");
            $stmt->execute([$this->supplier]);
            $out[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function refused(string $code, callable $work): void
    {
        try {
            $work();
            self::fail('Expected refusal: ' . $code);
        } catch (JointAllocationException|\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
