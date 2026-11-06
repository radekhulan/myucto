<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentExporter;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxFullJointCompatibilityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private NonprofitTaxAssessmentService $service;
    private NonprofitTaxAssessmentGuard $guard;
    private NonprofitTaxAssessmentRepository $repository;
    private NonprofitTaxAssessmentExporter $exporter;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $kind;
    private array $catalog;
    private array $accounts;
    private ?Connection $otherDb = null;
    private bool $committed = false;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed, 'Synthetic seed required');
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01');
        $this->pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
            ->execute([$this->catalog['activity_kind']['id'], $this->supplier, 'RENT', 'SyntetickĂ˝ nĂˇjem']);
        $this->kind = (int) $this->pdo->lastInsertId();
        foreach (['221' => 'asset', '518' => 'expense', '591' => 'expense', '602' => 'revenue', '682' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
        $this->service = $container->get(NonprofitTaxAssessmentService::class);
        $this->guard = $container->get(NonprofitTaxAssessmentGuard::class);
        $this->repository = $container->get(NonprofitTaxAssessmentRepository::class);
        $this->exporter = $container->get(NonprofitTaxAssessmentExporter::class);
    }

    protected function tearDown(): void
    {
        if ($this->otherDb !== null) {
            if ($this->otherDb->pdo()->inTransaction()) $this->otherDb->pdo()->rollBack();
            $this->otherDb->close();
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->snapshotIsolation !== null) $this->pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
        if ($this->committed) {
            $this->pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $this->pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
    }

    private function post(string $code, string $amount, bool $dimensions = true, string $origin = 'explicit', bool $red = false, string $source = 'manual', ?int $sourceId = null, string $date = '2026-06-01'): int
    {
        $expense = str_starts_with($code, '5');
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id) VALUES (?,?,?,?,?)')
            ->execute([$this->supplier, $this->period, $date, $source, $sourceId]);
        $entry = (int) $this->pdo->lastInsertId();
        $line = 0;
        foreach ([[$code, $expense ? 'debit' : 'credit'], ['221', $expense ? 'credit' : 'debit']] as $no => [$account, $side]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,line_no,account_id,side,amount,is_red_storno) VALUES (?,?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $no, $this->accounts[$account], $side, $amount, (int) $red]);
            if ($no === 0) {
                $line = (int) $this->pdo->lastInsertId();
                if ($dimensions) $this->assign($line, $origin);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $line;
    }

    private function assign(int $line, string $origin = 'explicit'): void
    {
        foreach (['activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $this->kind,
            'tax_regime' => $this->catalog['tax_regime']['values']['exempt']] as $axis => $value) {
            $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $line, $this->catalog[$axis]['id'], $value, $origin]);
        }
    }

    private function assessment(array $snapshot): array
    {
        $out = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'SyntetickĂ© stanovy a posouzenĂ­ Â§17a'],
            'activities' => [$this->kind => ['evidence' => 'DoloĹľenĂˇ nĂˇjemnĂ­ ÄŤinnost']], 'decisions' => [], 'funding' => []];
        foreach ($snapshot['sources'] as $source) {
            $out['decisions'][$source['id']] = ['title' => $source['kind'] === 'revenue' ? 'rent' : 'cost',
                'evidence' => 'SyntetickĂ˝ podklad Ĺ™Ăˇdku ' . $source['id'], 'legal_basis' => 'zdp_24_1', 'deductible_cents' => $source['amount_cents']];
        }
        return $out;
    }

    private function jointEntry(array $lines, string $date): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type) VALUES (?,?,?,'manual')")->execute([$this->supplier, $this->period, $date]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach ($lines as $no => $line) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,line_no,account_id,side,amount,is_red_storno) VALUES (?,?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $no, $line['account_id'], $line['side'], $line['amount'], (int) !empty($line['is_red_storno'])]);
            $id = (int) $this->pdo->lastInsertId();
            foreach ($line['dimensions'] ?? [] as $type => $value) {
                $origin = $line['dimension_origins'][$type] ?? ['origin' => 'unknown', 'ref' => null];
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin,assignment_origin_ref) VALUES (?,?,?,?,?,?)')
                    ->execute([$this->supplier, $id, $type, $value, $origin['origin'], $origin['ref']]);
            }
            foreach ($line['dimension_splits'] ?? [] as $type => $shares) foreach ($shares as $value => $share) {
                $this->pdo->prepare('INSERT INTO journal_entry_line_dimension_splits (supplier_id,line_id,dimension_type_id,dimension_value_id,share,assignment_origin) VALUES (?,?,?,?,?,?)')
                    ->execute([$this->supplier, $id, $type, $value, $share, 'explicit']);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
        return $entry;
    }


    public function testExplicitFullJointCellsPreserveTaxSemanticsAndCustomMarginals(): void
    {
        $sourceId = $this->post('518', '100.00', false);
        $custom = [];
        foreach (['REGION', 'PROJECT'] as $code) {
            $this->pdo->prepare('INSERT INTO dimension_types (supplier_id,code,name) VALUES (?,?,?)')->execute([$this->supplier, $code, 'Synthetic ' . $code]);
            $type = (int) $this->pdo->lastInsertId();
            foreach (['A', 'B'] as $value) {
                $this->pdo->prepare('INSERT INTO dimension_values (supplier_id,type_id,code,name) VALUES (?,?,?,?)')->execute([$this->supplier, $type, $value, 'Synthetic ' . $value]);
                $custom[$type][] = (int) $this->pdo->lastInsertId();
            }
        }
        [$x, $y] = array_keys($custom);
        $splits = [$x => [$custom[$x][0] => '0.6', $custom[$x][1] => '0.4'], $y => [$custom[$y][0] => '0.2', $custom[$y][1] => '0.8']];
        foreach ($splits as $type => $shares) foreach ($shares as $value => $share) {
            $this->pdo->prepare("INSERT INTO journal_entry_line_dimension_splits (supplier_id,line_id,dimension_type_id,dimension_value_id,share,assignment_origin) VALUES (?,?,?,?,?,'explicit')")
                ->execute([$this->supplier, $sourceId, $type, $value, $share]);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM journal_entry_lines WHERE id = ?');
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        $source['id'] = (int) $source['id'];
        $source['dimensions'] = $source['dimension_origins'] = [];
        $source['dimension_splits'] = $splits;
        $types = array_map(static fn (array $type): int => $type['id'], $this->catalog);
        $cells = [];
        foreach ([[0, 0], [0, 1], [1, 1]] as [$a, $b]) $cells[] = [
            'activity' => $this->catalog['activity']['values']['main'], 'activity_kind' => $this->kind,
            'tax_regime' => $this->catalog['tax_regime']['values']['subject'], 'weight' => '1',
            'additional_dimensions' => [$x => $custom[$x][$a], $y => $custom[$y][$b]],
        ];
        $version = \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::normalize([
            'valid_from' => '2026-01-01', 'valid_to' => '2027-01-01', 'basis' => 'fixed', 'explanation' => 'Exact synthetic joint facts',
            'allocation_mode' => 'explicit_amounts', 'cells' => $cells]);
        $exact = ['source_line_id' => $sourceId, 'evidence' => 'Documented joint combinations, not independent products', 'cells' => []];
        foreach ($version['cells'] as $index => $cell) $exact['cells'][] = [
            'cell_id' => \MyInvoice\Service\Accounting\Allocation\JointAllocationCalculator::cellId($cell),
            'amount' => ['20.00', '40.00', '40.00'][$index], 'amount_foreign' => null,
        ];
        $plan = \MyInvoice\Service\Accounting\Allocation\JointAllocationPlanBuilder::build([$source], $version['cells'], $types, [$exact], 'explicit_amounts');
        self::assertSame(['20.00', '40.00', '40.00'], array_column($plan['sources'][0]['targets'], 'amount'));
        $stmt = $this->pdo->prepare('SELECT id,semantic_key,code,name FROM dimension_values WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $snapshot = ['period_id' => $this->period, 'key' => $version, 'source_lines' => [$source], 'plan' => $plan,
            'type_ids' => $types, 'semantic_values' => array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id'), 'exact_cells' => [$exact]];
        $this->pdo->prepare("INSERT INTO joint_allocation_keys (supplier_id,code,name) VALUES (?,'FULL','Synthetic full joint')")->execute([$this->supplier]);
        $key = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO joint_allocation_key_versions (supplier_id,key_id,version_no,valid_from,valid_to,basis,explanation,rounding_policy,cells_json,content_hash)
            VALUES (?,?,1,'2026-01-01','2027-01-01','fixed','Synthetic exact evidence','half_up_largest_weight_v1',?,?)")
            ->execute([$this->supplier, $key, \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeCells($version), $version['content_hash']]);
        $versionId = (int) $this->pdo->lastInsertId();
        $snapshot['supplier_id'] = $this->supplier;
        $snapshot['target_date'] = '2026-06-30';
        $snapshot['key']['id'] = $snapshot['key_version_id'] = $versionId;
        $snapshot['preview_hash'] = \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::hash($snapshot);
        $this->pdo->prepare("INSERT INTO joint_allocation_batches (supplier_id,key_version_id,target_date,reason,idempotency_key,request_hash,preview_hash,snapshot_json)
            VALUES (?,?,'2026-06-30','Synthetic full joint','full',?,?,?)")
            ->execute([$this->supplier, $versionId, $version['content_hash'], $snapshot['preview_hash'],
                \MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeJson($snapshot)]);
        $batch = (int) $this->pdo->lastInsertId();
        $lines = \MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof::materializedLines($plan, $batch, $types);
        $entry = $this->jointEntry($lines, '2026-06-30');
        $this->pdo->prepare('UPDATE joint_allocation_batches SET journal_entry_id = ? WHERE id = ?')->execute([$entry, $batch]);
        $read = new \MyInvoice\Repository\JointAllocationReadRepository($this->db);
        $joint = $read->jointCells($this->supplier, $batch, true);
        self::assertCount(3, $joint);
        self::assertSame(['2000', '4000', '4000'], array_column($joint, 'signed_amount_cents'));
        foreach ($joint as $cell) {
            self::assertSame(['activity', 'activity_kind', 'tax_regime'], array_keys($cell['axes']));
            self::assertSame('main', $cell['axes']['activity']['semantic_key']);
            self::assertSame('subject', $cell['axes']['tax_regime']['semantic_key']);
            self::assertCount(2, $cell['additional_axes']);
        }
        $data = $this->service->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $data['findings']);
        self::assertCount(3, $data['sources']);
        self::assertSame([2000, 4000, 4000], array_column($data['sources'], 'amount_cents'));
        self::assertSame([$sourceId, $sourceId, $sourceId], array_column(array_column($data['sources'], 'origin'), 'line_id'));
        foreach ($data['sources'] as $line) self::assertCount(2, $line['provenance']['additional_axes']);
        $assessment = $this->assessment($data);
        $assessment['profile'] = ['kind' => 'non_vpp', 'evidence' => 'Documented synthetic non-VPP status'];
        $result = $this->service->preview($this->supplier, $this->period, $assessment)['result'];
        self::assertSame(-10000, $result['accounting_result_cents']);
        self::assertSame(-10000, $result['assessed_result_cents']);
        self::assertSame(0, $result['cost_addbacks_cents']);
    }
}
