<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\{DimensionService, SystemDimensionCatalog};
use PHPUnit\Framework\TestCase;

final class AssetReserveCarrierLifecycleTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $sourcePeriod;
    private int $period;
    private int $kind;
    private array $dimensions;
    private int $assetNumber = 0;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic asset reserve','Test 1','Test','10000','asset-reserve@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $periods = $this->container->get(AccountingPeriodRepository::class);
        $this->sourcePeriod = $periods->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $this->period = $periods->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-01-01','cz_504')")->execute([$this->supplier]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier, '2096-01-01');
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-01-01');
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
            ->execute([$catalog['activity_kind']['id'], $this->supplier, 'asset_repair', 'Synthetic asset activity']);
        $this->kind = (int) $pdo->lastInsertId();
        $this->dimensions = [$catalog['activity']['id'] => $catalog['activity']['values']['economic'],
            $catalog['activity_kind']['id'] => $this->kind,
            $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testBackdatedDisposeRejectsBeforeAnyMutation(): void
    {
        $this->assertBackdatedDisposalRejected(false);
    }

    public function testBackdatedJournalDisposalRejectsBeforeAnyMutation(): void
    {
        $this->assertBackdatedDisposalRejected(true);
    }

    private function assertBackdatedDisposalRejected(bool $fromJournal): void
    {
        [$asset] = $this->lifecycleFixture();
        $before = $this->lifecycleSnapshot();
        $service = $this->container->get(AssetService::class);
        $data = ['date' => '2096-12-01', 'type' => 'liquidated'];
        $code = null;
        try {
            if ($fromJournal) $service->disposeFromJournal($this->supplier, $asset, $data, []);
            else $service->dispose($this->supplier, $asset, $data, []);
        } catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Assets\AssetException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->lifecycleSnapshot());
    }

    public function testCorruptBackdatedDisposalCannotBeRevertedSilently(): void
    {
        [$asset] = $this->lifecycleFixture();
        $this->db->pdo()->prepare("UPDATE assets SET status='disposed',disposal_date='2096-12-01',disposal_type='liquidated' WHERE supplier_id=? AND id=?")
            ->execute([$this->supplier, $asset]);
        $before = $this->lifecycleSnapshot();
        $code = null;
        try { $this->container->get(AssetService::class)->revertDisposal($this->supplier, $asset); }
        catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Assets\AssetException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->lifecycleSnapshot());
    }

    public function testFutureDisposalAndRevertPreserveHistoricalReceipt(): void
    {
        [$asset, $receipt] = $this->lifecycleFixture();
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $service = $this->container->get(AssetService::class);
        $service->pauseYear($this->supplier, $asset, 2096);
        $service->pauseYear($this->supplier, $asset, 2097);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->sourcePeriod]);
        $result = $service->disposeFromJournal($this->supplier, $asset, ['date' => '2098-06-01', 'type' => 'liquidated'], []);
        self::assertSame('disposed', $result['asset']['status']);
        $source = new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository($this->db);
        $source->assertReceiptCurrent($this->supplier, '2097-12-31', $receipt);
        self::assertSame($receipt, $source->receipts($this->supplier)[0]['receipt']);
        $result = $service->revertDisposal($this->supplier, $asset);
        self::assertSame('in_use', $result['asset']['status']);
        self::assertNull($result['asset']['disposal_date']);
        $source->assertReceiptCurrent($this->supplier, '2097-12-31', $receipt);
        self::assertSame($receipt, $source->receipts($this->supplier)[0]['receipt']);
    }

    private function lifecycleFixture(): array
    {
        [$asset] = $this->asset(false);
        $line = $this->openingLine('100000.00');
        $this->closeSource();
        $this->postSource($this->association($asset, [['line_id' => $line, 'contribution_cents' => 10000000]]));
        $receipt = (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository($this->db))->receipts($this->supplier)[0]['receipt'];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='open' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->sourcePeriod]);
        return [$asset, $receipt];
    }

    private function lifecycleSnapshot(): array
    {
        $out = [];
        foreach (['assets', 'asset_improvements', 'depreciation_entries', 'journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'accounting_periods', 'accounting_closing_steps'] as $table) {
            $statement = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $statement->execute([$this->supplier]);
            $out[$table] = $statement->fetchAll(\PDO::FETCH_ASSOC);
            sort($out[$table]);
        }
        return $out;
    }

    private function association(int $asset, array $contributions): array
    {
        return ['asset_id' => $asset, 'mode' => 'documented_association', 'allocation_method' => 'capitalization_joint_proportional',
            'continued_use_evidence' => 'Synthetic continued use', 'association' => ['reference' => 'SYN-ASSOCIATION-' . $asset,
                'evidence' => 'Synthetic itemized historical capitalization title', 'contributions' => $contributions]];
    }

    private function openingLine(string $amount): int
    {
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '022', 'side' => 'debit', 'amount' => $amount, 'dimensions' => $this->dimensions],
            ['account_code' => '901', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2096-06-01', 'posted' => true]);
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='debit'");
        $stmt->execute([$entry]);
        return (int) $stmt->fetchColumn();
    }

    private function postSource(array $source): array
    {
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $source, '150000.00');
        $source['source_hash'] = $preview['source']['source_hash'];
        return $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->sourceBody($source));
    }

    private function sourceBody(array $source, string $amount = '150000.00'): array
    {
        return ['row_version' => $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'],
            'rule_key' => 'reserve.repairs.create', 'amount' => $amount, 'description' => 'Synthetic source repair reserve',
            'legal_decision' => ['reference' => 'SYN-REPAIR', 'legal_basis' => '593/1992 §7', 'taxable_activity' => true,
                'conditions_confirmed' => true, 'evidence' => 'Synthetic repair plan and statutory deposit assessment', 'reserve_source' => $source]];
    }

    private function asset(bool $bookEntry): array
    {
        $assets = $this->container->get(AssetService::class);
        $created = $assets->create($this->supplier, ['inventory_number' => 'SYN-ASSET-' . ++$this->assetNumber, 'name' => 'Synthetic machine',
            'input_price' => '100000.00', 'acquisition_date' => '2096-06-01', 'tax_method' => 'straight',
            'tax_group' => 2, 'acc_useful_life_months' => 60]);
        $asset = (int) $created['asset']['id'];
        $this->container->get(DimensionService::class)->saveDocument($this->supplier, 'asset', $asset, $this->dimensions, null);
        $result = $assets->putIntoUse($this->supplier, $asset, '2096-06-01', $bookEntry, []);
        self::assertSame('in_use', $result['asset']['status']);
        $stmt = $this->db->pdo()->prepare("SELECT e.id,l.id AS line_id,l.amount,a.account_code FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id=e.id JOIN chart_of_accounts a ON a.id=l.account_id
            WHERE e.supplier_id=? AND e.source_type='asset' AND e.source_id=? AND l.side='debit'");
        $stmt->execute([$this->supplier, $asset]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$bookEntry) { self::assertFalse($source); return [$asset, 0, []]; }
        self::assertIsArray($source);
        self::assertSame('100000.00', $source['amount']);
        self::assertSame($result['asset']['asset_account_code'], $source['account_code']);
        $stmt = $this->db->pdo()->prepare('SELECT dimension_type_id,dimension_value_id FROM journal_entry_line_dimensions WHERE line_id=? ORDER BY dimension_type_id');
        $stmt->execute([$source['line_id']]);
        $dimensions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $dimensions[(int) $row['dimension_type_id']] = (int) $row['dimension_value_id'];
        self::assertEquals($this->dimensions, $dimensions);
        return [$asset, (int) $source['id'], $dimensions];
    }

    private function closeSource(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=?")->execute([$this->sourcePeriod]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
    }

}
