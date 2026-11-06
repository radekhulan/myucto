<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JointAllocationKeyRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationJournalAdapter;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationRequest;
use MyInvoice\Service\Accounting\Allocation\JointAllocationService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\JointAllocationCloneScrubber;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JointAllocationCloneScrubberTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const EMAIL = 'synthetic-allocation@example.test';
    private const TABLES = ['joint_allocation_keys', 'joint_allocation_key_versions', 'joint_allocation_batches',
        'joint_allocation_source_claims', 'joint_allocation_cancellations', 'joint_allocation_audit'];
    private Config $config;
    private PDO $server;
    private array $schemas = [];
    private array $connections = [];
    private int $supplier;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->config = $container->get(Config::class);
        self::assertContains($this->config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $base = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $base);
        $this->server = new PDO('mysql:host=' . $this->config->get('db.host') . ';port=' . $this->config->get('db.port', 3306) . ';charset=utf8mb4',
            $this->config->get('db.user'), $this->config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->server->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->schemas = [CloneSandbox::acquire($this->server, $base, true)];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        foreach ($this->schemas as $schema) {
            if (CloneSandbox::isLeased($schema)) {
                CloneSandbox::release($this->server, $schema);
                continue;
            }
            self::assertMatchesRegularExpression('/^myucto_joint_scrub_(src|dst)_[a-f0-9]{10}_test$/D', $schema);
            \MyInvoice\Tests\Support\OwnedTestDatabaseCleanup::drop($this->server,$schema,$this->schemas);
        }
    }

    public static function splitCases(): array
    {
        return ['fractional' => [false], 'whole_share' => [true]];
    }

    public function testFullAnonymizationKeepsAllocationProofAndAllowsCancellation(): void
    {
        $source = $this->connection($this->schemas[0]);
        [$lineId, $key, $exact] = $this->seed($source, true);
        $request = $this->request($source, $lineId, $key, $exact, 'full-clone');
        $batch = $this->service($source)->apply($this->supplier, $request);
        $before = $this->state($source->pdo());
        $this->schemas[] = CloneSandbox::anonymized($this->server, $this->config, $this->schemas[0], 'synthetic-joint-clone');
        $target = $this->connection($this->schemas[1]);
        $after = $this->state($target->pdo());
        foreach (self::TABLES as $table) {
            self::assertCount(count($before[$table]), $after[$table], $table);
        }
        $stored = $after['joint_allocation_batches'][0];
        self::assertSame($request['idempotency_key'], $stored['idempotency_key']);
        self::assertStringNotContainsString(self::EMAIL, $stored['snapshot_json']);
        $snapshot = json_decode($stored['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $hash = $snapshot['preview_hash'];
        unset($snapshot['preview_hash']);
        self::assertSame($stored['preview_hash'], $hash);
        self::assertSame($hash, JointAllocationKeyVersion::hash($snapshot));
        foreach (['joint_allocation_keys', 'joint_allocation_key_versions', 'joint_allocation_audit'] as $table) {
            self::assertStringNotContainsString(self::EMAIL, json_encode($after[$table], JSON_THROW_ON_ERROR));
        }
        $result = $this->service($target)->cancel($this->supplier, ['batch_id' => $batch['id'], 'target_date' => '2096-07-01',
            'reason' => 'Synthetic full clone cancellation', 'idempotency_key' => 'full-clone-cancel']);
        self::assertSame('cancelled', $result['status']);
        self::assertSame(0, (int) $target->pdo()->query('SELECT COUNT(*) FROM joint_allocation_source_claims WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        self::assertSame($before, $this->state($source->pdo()));
    }

    #[DataProvider('splitCases')]
    public function testCloneRetainsOwnershipCancellationHistoryAndCanonicalIdempotence(bool $wholeShare): void
    {
        $source = $this->connection($this->schemas[0]);
        [$lineId, $key, $exact] = $this->seed($source, $wholeShare);
        $service = $this->service($source);
        $request = $this->request($source, $lineId, $key, $exact, 'first');
        $first = $service->apply($this->supplier, $request);
        $stored = $source->pdo()->query('SELECT snapshot_json FROM joint_allocation_batches WHERE id = ' . (int) $first['id'])->fetchColumn();
        $snapshot = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $hash = $snapshot['preview_hash'];
        unset($snapshot['preview_hash']);
        self::assertSame($request['preview_hash'], $hash);
        self::assertSame($hash, JointAllocationKeyVersion::hash($snapshot), 'Persisted preview must retain the canonical numeric types.');
        $cancel = ['batch_id' => $first['id'], 'target_date' => '2096-07-01', 'reason' => self::EMAIL, 'idempotency_key' => 'original-cancel'];
        $service->cancel($this->supplier, $cancel);
        $cancelledRetry = $service->apply($this->supplier, $request);
        self::assertSame($first['id'], $cancelledRetry['id']);
        self::assertSame('cancelled', $cancelledRetry['status']);
        $source->pdo()->prepare('UPDATE journal_entry_lines SET cost_center = ? WHERE id = ?')->execute(['POOL-CHANGED', $lineId]);
        $secondRequest = $this->request($source, $lineId, $key, $exact, 'second');
        $second = $service->apply($this->supplier, $secondRequest);
        $sourceState = $this->state($source->pdo());
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($this->schemas[0]);
        $this->schemas[] = CloneSandbox::acquire($this->server, $this->schemas[0]);
        $target = $this->connection($this->schemas[1]);
        $pdo = $target->pdo();
        $scrubber = new TextScrubber(new Pseudonymizer(str_repeat('synthetic-clone-', 3)));
        foreach (['journal_entries' => 'description', 'dimension_values' => 'name'] as $table => $column) {
            $find = $pdo->prepare('SELECT id, ' . $column . ' FROM ' . $table . ' WHERE supplier_id = ?');
            $find->execute([$this->supplier]);
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pdo->prepare('UPDATE ' . $table . ' SET ' . $column . ' = ? WHERE id = ?')->execute([$scrubber->scrub($row[$column]), $row['id']]);
            }
        }
        foreach (['journal_entry_line_dimensions', 'journal_entry_line_dimension_splits'] as $table) {
            $pdo->prepare('UPDATE ' . $table . ' SET assignment_origin_ref = ? WHERE supplier_id = ? AND assignment_origin_ref = ?')
                ->execute([$scrubber->scrub(self::EMAIL), $this->supplier, self::EMAIL]);
            self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . " WHERE supplier_id = {$this->supplier} AND assignment_origin = 'explicit'")->fetchColumn());
        }
        \MyInvoice\Service\Anonymization\LegacyCostCenterCloneScrubber::run($pdo,$scrubber);
        $before = $this->state($pdo);
        $helper = new JointAllocationCloneScrubber($pdo, $scrubber);
        $original = $before['joint_allocation_batches'][0];
        $corrupt = json_decode($original['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $corrupt['plan']['sources'][0]['targets'][0]['amount'] = '5030.87';
        $pdo->prepare('UPDATE joint_allocation_batches SET snapshot_json = ? WHERE id = ?')
            ->execute([json_encode($corrupt, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $original['id']]);
        $rawCorruptState = $this->state($pdo);
        try {
            $helper->run();
            self::fail('A raw snapshot mismatch must fail before transforming provenance.');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(JointAllocationException::class, $e);
            self::assertSame('allocation_changed', $e->errorCode);
        }
        self::assertSame($rawCorruptState, $this->state($pdo));
        unset($corrupt['preview_hash']);
        $corrupt['preview_hash'] = JointAllocationKeyVersion::hash($corrupt);
        $pdo->prepare('UPDATE joint_allocation_batches SET snapshot_json = ?, preview_hash = ? WHERE id = ?')
            ->execute([json_encode($corrupt, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $corrupt['preview_hash'], $original['id']]);
        $corruptState = $this->state($pdo);
        try {
            $helper->run();
            self::fail('A corrupt financial snapshot must not be resealed.');
        } catch (\RuntimeException $e) {
            self::assertSame('Allocation clone ledger differs from snapshot.', $e->getMessage());
        }
        self::assertSame($corruptState, $this->state($pdo), 'Failure rolls back all prior key rewrites.');
        $pdo->prepare('UPDATE joint_allocation_batches SET snapshot_json = ?, preview_hash = ? WHERE id = ?')
            ->execute([$original['snapshot_json'], $original['preview_hash'], $original['id']]);
        foreach (['journal_entry_line_dimensions', 'journal_entry_line_dimension_splits'] as $table) {
            $pdo->prepare('UPDATE ' . $table . " SET assignment_origin = 'unknown' WHERE supplier_id = ? AND assignment_origin_ref = ?")
                ->execute([$this->supplier, $scrubber->scrub(self::EMAIL)]);
            $corruptOrigins = $this->state($pdo);
            try {
                $helper->run();
                self::fail('An unrelated origin mismatch must not be accepted as anonymization.');
            } catch (\RuntimeException $e) {
                self::assertSame('Allocation clone ledger differs from snapshot.', $e->getMessage());
            }
            self::assertSame($corruptOrigins, $this->state($pdo));
            $pdo->prepare('UPDATE ' . $table . " SET assignment_origin = 'explicit' WHERE supplier_id = ? AND assignment_origin_ref = ?")
                ->execute([$this->supplier, $scrubber->scrub(self::EMAIL)]);
        }
        $helper->run();
        $after = $this->state($pdo);
        self::assertStringNotContainsString(self::EMAIL, json_encode(array_intersect_key($after, array_flip(self::TABLES)), JSON_THROW_ON_ERROR));
        foreach (self::TABLES as $table) self::assertSame(array_column($before[$table], 'id'), array_column($after[$table], 'id'), $table);
        self::assertSame($before['joint_allocation_source_claims'], $after['joint_allocation_source_claims']);
        self::assertSame($before['journal_entry_lines'], $after['journal_entry_lines']);
        self::assertSame($before['journal_entry_line_dimensions'], $after['journal_entry_line_dimensions']);
        self::assertSame($before['journal_entry_line_dimension_splits'], $after['journal_entry_line_dimension_splits']);
        self::assertSame($sourceState, $this->state($source->pdo()), 'Source database is read-only during anonymization.');
        $helper->run();
        self::assertSame($after, $this->state($pdo), 'Second pass is idempotent.');
        foreach ($after['joint_allocation_key_versions'] as $version) {
            self::assertSame(JointAllocationKeyVersion::normalize(JointAllocationKeyVersion::decodeCells($version))['content_hash'], $version['content_hash']);
        }
        $anonymousRequests = [];
        foreach ($after['joint_allocation_batches'] as $batch) {
            $snapshot = json_decode($batch['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            $hash = $snapshot['preview_hash'];
            unset($snapshot['preview_hash']);
            self::assertSame(JointAllocationKeyVersion::hash($snapshot), $hash);
            self::assertSame($hash, $batch['preview_hash']);
            self::assertSame(JointAllocationKeyVersion::normalize($snapshot['key'])['content_hash'], $snapshot['key']['content_hash']);
            self::assertSame('EUR', $snapshot['source_lines'][0]['currency_code']);
            self::assertSame('800.00', $snapshot['source_lines'][0]['amount_foreign']);
            self::assertSame(1, $snapshot['source_lines'][0]['is_red_storno']);
            $originalBatch = array_column($before['joint_allocation_batches'], null, 'id')[$batch['id']];
            $expectedPlan = json_decode(str_replace([self::EMAIL,'POOL-ORIGINAL','POOL-CHANGED'], [$scrubber->scrub(self::EMAIL),$scrubber->byStrategy('cost_center','POOL-ORIGINAL'),$scrubber->byStrategy('cost_center','POOL-CHANGED')], $originalBatch['snapshot_json']), true, flags: JSON_THROW_ON_ERROR)['plan'];
            self::assertSame($expectedPlan, $snapshot['plan']);
            self::assertSame(['origin' => 'explicit', 'ref' => $scrubber->scrub(self::EMAIL)], array_values($snapshot['source_lines'][0]['dimension_origins'])[0]);
            self::assertSame(['origin' => 'explicit', 'ref' => $scrubber->scrub(self::EMAIL)], array_values(array_values($snapshot['source_lines'][0]['dimension_split_origins'])[0])[0]);
            self::assertSame($originalBatch['idempotency_key'], $batch['idempotency_key']);
            self::assertSame($scrubber->byStrategy('cost_center',$batch['id'] === $first['id'] ? 'POOL-ORIGINAL' : 'POOL-CHANGED'), $snapshot['source_lines'][0]['cost_center']);
        }
        $versions = array_column($after['joint_allocation_key_versions'], null, 'id');
        $batches = array_column($after['joint_allocation_batches'], null, 'id');
        foreach ($after['joint_allocation_audit'] as $audit) {
            $payload = json_decode($audit['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            if ($audit['entity_type'] === 'version') self::assertSame($versions[$audit['entity_id']]['content_hash'], $payload['content_hash']);
            if ($audit['entity_type'] === 'batch') self::assertSame($batches[$audit['entity_id']]['preview_hash'], $payload['preview_hash']);
        }
        // Pojistce stačí jediný trigger; zakládají se jen triggery tabulek, se kterými test dál pracuje.
        foreach ($plan['triggers'] as $trigger) {
            if (preg_match('/^(joint_allocation_|journal_entr)/', $trigger['table']) === 1) $pdo->exec($trigger['ddl']);
        }
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        try {
            $helper->run();
            self::fail('The clone hook must refuse a database with installed triggers.');
        } catch (\LogicException $e) {
            self::assertSame('Allocation clone postprocess must run before installing clone triggers.', $e->getMessage());
        }
        $pdo->beginTransaction();
        $targetService = $this->service($target);
        $this->refused('allocation_source_in_use', fn () => (new JointAllocationUseGuard($target))->assertLinesMutable($this->supplier, [$lineId]));
        $this->refused('allocation_idempotency_conflict', fn () => $targetService->apply($this->supplier, $secondRequest));
        $this->refused('allocation_idempotency_conflict', fn () => $targetService->cancel($this->supplier, $cancel));
        foreach ($after['joint_allocation_batches'] as $batch) {
            $snapshot = json_decode($batch['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            $retry = ['target_date' => $batch['target_date'], 'reason' => $batch['reason'], 'idempotency_key' => $batch['idempotency_key'],
                'key_version_id' => (int) $batch['key_version_id'], 'source_line_ids' => [$lineId], 'preview_hash' => $batch['preview_hash'], 'exact_cells' => $snapshot['exact_cells']];
            self::assertSame($batch['request_hash'], JointAllocationRequest::apply($retry)['request_hash']);
            $anonymousRequests[$batch['id']] = $retry;
            $replayed = $targetService->apply($this->supplier, $retry);
            self::assertSame($batch['id'], $replayed['id']);
            self::assertSame($batch['id'] === $first['id'] ? 'cancelled' : 'applied', $replayed['status']);
            $retry['reason'] .= ' changed';
            $this->refused('allocation_idempotency_conflict', fn () => $targetService->apply($this->supplier, $retry));
        }
        $storedCancel = $after['joint_allocation_cancellations'][0];
        $cancelRetry = ['batch_id' => $first['id'], 'target_date' => $storedCancel['target_date'], 'reason' => $storedCancel['reason'], 'idempotency_key' => $storedCancel['idempotency_key']];
        self::assertSame($storedCancel['request_hash'], JointAllocationRequest::cancel($cancelRetry)['request_hash']);
        self::assertSame('cancelled', $targetService->cancel($this->supplier, $cancelRetry)['status']);
        $result = $targetService->cancel($this->supplier, ['batch_id' => $second['id'], 'target_date' => '2096-07-01', 'reason' => 'Synthetic anonymous correction', 'idempotency_key' => 'clone-cancel']);
        self::assertSame('cancelled', $result['status']);
        foreach ($anonymousRequests as $id => $retry) {
            $replayed = $targetService->apply($this->supplier, $retry);
            self::assertSame($id, $replayed['id']);
            self::assertSame('cancelled', $replayed['status']);
        }
        $this->refused('allocation_idempotency_conflict', fn () => $targetService->apply($this->supplier, $request));
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM joint_allocation_batches WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        (new JointAllocationUseGuard($target))->assertLinesMutable($this->supplier, [$lineId]);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM joint_allocation_source_claims WHERE supplier_id = ' . $this->supplier)->fetchColumn());
        self::assertSame($sourceState, $this->state($source->pdo()));
    }

    private function connection(string $schema): Connection
    {
        $data = $this->config->all();
        $data['db']['name'] = $schema;
        $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $this->config->dataDir())));
        $this->connections[] = $db;
        $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $db;
    }

    private function seed(Connection $db, bool $wholeShare): array
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($db))->seedForSupplier($this->supplier);
        $period = (new AccountingPeriodRepository($db))->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $stmt = $pdo->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $entry = (new JournalEntryRepository($db, new ActivityLogger($db)))->insert([
            'supplier_id' => $this->supplier, 'period_id' => $period, 'entry_date' => '2096-06-01', 'source_type' => 'manual',
            'posted_at' => '2096-06-01 12:00:00', 'description' => self::EMAIL,
        ], [
            ['account_id' => $accounts['518'], 'side' => 'debit', 'amount' => '20123.45', 'is_red_storno' => true, 'amount_foreign' => '800.00', 'currency_code' => 'EUR', 'fx_rate' => '25.1543125', 'cost_center' => 'POOL-ORIGINAL'],
            ['account_id' => $accounts['221'], 'side' => 'credit', 'amount' => '20123.45', 'is_red_storno' => true, 'amount_foreign' => '800.00', 'currency_code' => 'EUR', 'fx_rate' => '25.1543125'],
        ]);
        $stmt = $pdo->prepare("SELECT id FROM journal_entry_lines WHERE entry_id = ? AND side = 'debit'");
        $stmt->execute([$entry]);
        $lineId = (int) $stmt->fetchColumn();
        $dimensions = new DimensionRepository($db);
        $assignments = new DimensionAssignmentRepository($db);
        foreach (['SCALAR', 'SPLIT'] as $code) {
            $typeId = $dimensions->createType($this->supplier, false, ['code' => $code, 'name' => $code, 'kind' => 'custom']);
            $valueId = $dimensions->createValue($dimensions->findType($this->supplier, $typeId), ['code' => 'VALUE', 'name' => 'Synthetic']);
            $origin = ['origin' => 'explicit', 'ref' => self::EMAIL];
            if ($code === 'SCALAR') $assignments->insertLineDimensions($this->supplier, $lineId, [$typeId => $valueId], [$typeId => $origin]);
            else {
                $secondValueId = $dimensions->createValue($dimensions->findType($this->supplier, $typeId), ['code' => 'SECOND', 'name' => 'Synthetic second']);
                $splitType = $typeId;
                $splitValues = $wholeShare ? [$valueId] : [$valueId, $secondValueId];
                $shares = $wholeShare ? [$valueId => '1'] : [$valueId => '0.25', $secondValueId => '0.75'];
                $assignments->insertLineSplits($this->supplier, $lineId, [$typeId => $shares], [$typeId => [$valueId => $origin, $secondValueId => $origin]]);
            }
        }
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplier]);
        $catalog = (new SystemDimensionCatalog($db))->ensureForFramework($this->supplier, '2096-01-01');
        $repo = new DimensionRepository($db);
        $type = ['id' => $catalog['activity_kind']['id'], 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $kind = $repo->createValue($type, ['code' => 'SYNTHETIC', 'name' => self::EMAIL]);
        $cell = ['activity' => $catalog['activity']['values']['main'], 'activity_kind' => $kind, 'tax_regime' => $catalog['tax_regime']['values']['subject'], 'weight' => '1'];
        $cells = array_map(static fn (int $value): array => $cell + ['additional_dimensions' => [$splitType => $value]], $splitValues);
        $key = (new JointAllocationKeyRepository($db))->create($this->supplier, 'SYNTHETIC', self::EMAIL, [
            'valid_from' => '2096-01-01', 'valid_to' => '2097-01-01', 'basis' => 'hours', 'explanation' => self::EMAIL,
            'allocation_mode' => 'explicit_amounts', 'cells' => $cells,
        ]);
        $amountCells = $wholeShare ? [
            ['cell_id' => JointAllocationCalculator::cellId($cells[0]), 'amount' => '20123.45', 'amount_foreign' => '800.00'],
        ] : [
            ['cell_id' => JointAllocationCalculator::cellId($cells[0]), 'amount' => '5030.86', 'amount_foreign' => '200.00'],
            ['cell_id' => JointAllocationCalculator::cellId($cells[1]), 'amount' => '15092.59', 'amount_foreign' => '600.00'],
        ];
        $exact = [['source_line_id' => $lineId, 'evidence' => self::EMAIL, 'cells' => $amountCells]];
        $pdo->commit();
        return [$lineId, $key, $exact];
    }

    private function service(Connection $db): JointAllocationService
    {
        return new JointAllocationService($db, new JointAllocationJournalAdapter(new JournalEntryRepository($db, new ActivityLogger($db))));
    }

    private function request(Connection $db, int $line, array $key, array $exact, string $token): array
    {
        $preview = (new JointAllocationPreviewService($db))->preview($this->supplier, $key['id'], '2096-06-30', [$line], $exact);
        return ['key_version_id' => $key['id'], 'source_line_ids' => [$line], 'target_date' => '2096-06-30', 'reason' => self::EMAIL,
            'idempotency_key' => $token, 'preview_hash' => $preview['preview_hash'], 'exact_cells' => $exact];
    }

    private function state(PDO $pdo): array
    {
        $out = [];
        foreach ([...self::TABLES, 'journal_entry_lines', 'journal_entry_line_dimensions', 'journal_entry_line_dimension_splits'] as $table) {
            $order = match ($table) {
                'joint_allocation_source_claims' => 'line_id',
                'journal_entry_line_dimensions' => 'line_id, dimension_type_id',
                'journal_entry_line_dimension_splits' => 'line_id, dimension_type_id, dimension_value_id',
                default => 'id',
            };
            $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id = ? ORDER BY ' . $order);
            $stmt->execute([$this->supplier]);
            $out[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function refused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected refusal: ' . $code);
        } catch (JointAllocationException|PostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
