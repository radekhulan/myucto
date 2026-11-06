<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointRevenueProposalService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JointRevenueProposalTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JournalEntryRepository $journal;
    private int $supplier;
    private int $period;
    private array $accounts;
    private array $types;
    private array $cells;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$this->supplier]);
        $stmt = $pdo->prepare('SELECT account_code,id FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $this->accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->types = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-01-01');
        $dimensions = new DimensionRepository($this->db);
        $type = ['id' => $this->types['activity_kind']['id'], 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        $this->cells = [];
        foreach (['SPORT', 'EDUCATION'] as $code) {
            $this->cells[] = ['activity' => $this->types['activity']['values']['main'],
                'activity_kind' => $dimensions->createValue($type, ['code' => $code, 'name' => 'Synthetic ' . $code]),
                'tax_regime' => $this->types['tax_regime']['values']['subject']];
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testExactJointCellsUseSignedPostedRevenueAndInclusiveSourceDatesWithoutWrites(): void
    {
        $this->revenue('2096-01-01', '100.01', 0);
        $this->revenue('2096-01-31', '20.02', 0, red: true);
        $this->revenue('2096-01-31', '33.33', 1);
        $this->revenue('2096-02-01', '900.00', 1);
        $this->revenue('2096-01-15', '800.00', 1, posted: false);
        $this->revenue('2096-01-15', '700.00', 1, source: 'closing', sourceId: $this->period);
        $before = $this->writeCounters();
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertTrue($result['can_propose']);
        self::assertSame('11332', $result['net_revenue_cents']);
        self::assertSame(3, $result['source_lines']);
        self::assertSame(['79.990000', '33.330000'], array_column($result['cells'], 'weight'));
        self::assertSame(array_column($this->cells, 'activity_kind'), array_column($result['cells'], 'activity_kind'));
        self::assertSame($result, $this->service()->build($this->supplier, '2096-01-01', '2096-01-31'));
        self::assertSame($before, $this->writeCounters());
        self::assertFalse($result['mutations_performed']);
        self::assertTrue($result['requires_manual_confirmation']);
    }

    public function testMissingClassificationAndNegativeCellsCannotBecomePlausibleWeights(): void
    {
        $this->revenue('2096-01-01', '100.00', 0);
        $this->revenue('2096-01-02', '5.00', null);
        $this->revenue('2096-01-03', '10.00', 1, red: true);
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertFalse($result['can_propose']);
        self::assertSame([], $result['cells']);
        self::assertContains('unclassified_revenue', $result['reason_codes']);
        self::assertContains('negative_cell', $result['reason_codes']);
        self::assertSame(1, $result['unclassified_lines']);
        self::assertSame('500', $result['unclassified_amount_cents']);
        self::assertSame('9500', $result['net_revenue_cents']);
    }

    public function testIndependentDimensionSplitsAreNeverMultipliedIntoJointCells(): void
    {
        $line = $this->revenue('2096-01-01', '100.00', 0);
        $stmt = $this->db->pdo()->prepare('INSERT INTO journal_entry_line_dimension_splits (line_id,dimension_type_id,dimension_value_id,supplier_id,share) VALUES (?,?,?,?,0.5)');
        foreach ($this->cells as $cell) $stmt->execute([$line, $this->types['activity_kind']['id'], $cell['activity_kind'], $this->supplier]);
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertSame('10000', $result['net_revenue_cents']);
        self::assertContains('split_dimensions', $result['reason_codes']);
        self::assertFalse($result['can_propose']);
        self::assertSame([], $result['cells']);
    }

    public function testEmptyPeriodAndInvalidDatesAreExplicit(): void
    {
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertFalse($result['can_propose']);
        self::assertContains('no_revenue', $result['reason_codes']);
        $this->expectException(JointAllocationException::class);
        $this->service()->build($this->supplier, '2096-02-31', '2096-03-01');
    }

    public function testClosingReversalKeepsTechnicalOriginWhileFutureReversalDoesNotEraseRevenue(): void
    {
        $technical = $this->revenue('2096-01-01', '80.00', 0, source: 'closing', sourceId: $this->period);
        $technicalReversal = $this->revenue('2096-01-02', '80.00', 0, red: true);
        $ordinary = $this->revenue('2096-01-03', '123.45', 1);
        $futureReversal = $this->revenue('2096-02-01', '123.45', 1, red: true);
        $stmt = $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by = (SELECT entry_id FROM journal_entry_lines WHERE id = ?) WHERE id = (SELECT entry_id FROM journal_entry_lines WHERE id = ?)');
        $stmt->execute([$technicalReversal, $technical]);
        $stmt->execute([$futureReversal, $ordinary]);
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertTrue($result['can_propose']);
        self::assertSame('12345', $result['net_revenue_cents']);
        self::assertSame(1, $result['source_lines']);
        self::assertSame($this->cells[1]['activity_kind'], $result['cells'][0]['activity_kind']);
    }

    public function testTenantIsolationAndUnsupportedFramework(): void
    {
        $this->revenue('2096-01-01', '123.45', 0);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$other]);
        (new SystemDimensionCatalog($this->db))->ensureForFramework($other, '2096-01-01');
        $result = $this->service()->build($other, '2096-01-01', '2096-01-31');
        self::assertSame('0', $result['net_revenue_cents']);
        self::assertSame(0, $result['source_lines']);
        self::assertFalse($result['can_propose']);
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$other]);
        $this->expectException(JointAllocationException::class);
        $this->service()->build($other, '2096-01-01', '2096-01-31');
    }

    public function testTooManyPositiveCellsNeverReturnsPartialProposal(): void
    {
        $dimensions = new DimensionRepository($this->db);
        $type = ['id' => $this->types['activity_kind']['id'], 'supplier_id' => $this->supplier, 'supplier_group_id' => null];
        for ($i = 0; $i < 201; $i++) {
            $value = $dimensions->createValue($type, ['code' => 'SYNTH' . $i, 'name' => 'Synthetic activity ' . $i]);
            $this->cells[] = array_replace($this->cells[0], ['activity_kind' => $value]);
            $this->revenue('2096-01-01', '0.01', count($this->cells) - 1);
        }
        $result = $this->service()->build($this->supplier, '2096-01-01', '2096-01-31');
        self::assertSame('201', $result['net_revenue_cents']);
        self::assertSame(201, $result['source_lines']);
        self::assertFalse($result['can_propose']);
        self::assertContains('too_many_cells', $result['reason_codes']);
        self::assertSame([], $result['cells']);
    }

    private function service(): JointRevenueProposalService { return new JointRevenueProposalService($this->db); }

    private function revenue(string $date, string $amount, ?int $cell, bool $red = false, bool $posted = true, string $source = 'manual', ?int $sourceId = null): int
    {
        $dimensions = [$this->types['activity']['id'] => $this->types['activity']['values']['main']];
        if ($cell !== null) foreach ($this->cells[$cell] as $axis => $value) $dimensions[$this->types[$axis]['id']] = $value;
        $entry = $this->journal->insert(['supplier_id' => $this->supplier, 'period_id' => $this->period,
            'entry_date' => $date, 'source_type' => $source, 'source_id' => $sourceId,
            'posted_at' => $posted ? $date . ' 12:00:00' : null, 'description' => 'Synthetic revenue proposal'], [
                ['account_id' => $this->accounts['311'], 'side' => 'debit', 'amount' => $amount, 'is_red_storno' => $red],
                ['account_id' => $this->accounts['602'], 'side' => 'credit', 'amount' => $amount, 'is_red_storno' => $red, 'dimensions' => $dimensions],
            ]);
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id = ? AND side = 'credit'");
        $stmt->execute([$entry]);
        $line = (int) $stmt->fetchColumn();
        return $line;
    }

    private function writeCounters(): array
    {
        return $this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete')")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
