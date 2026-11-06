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

final class AssetReservePropertyCarrierTest extends TestCase
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

    public function testRetiredUnitAndServiceAreRetainedByActualReserveWriter(): void
    {
        [$asset, $posted] = $this->propertyReserve();
        self::assertGreaterThan(0, $asset);
        self::assertGreaterThan(0, $posted['entry_id']);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->find($posted['entry_id'], $this->supplier);
        self::assertNotNull($journal);
        $dimensions = (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplier, $posted['entry_id'], true);
        self::assertCount(2, $journal['lines']);
        foreach ($journal['lines'] as $line) {
            $actual = $dimensions[$line['id']] ?? [];
            ksort($actual);
            self::assertSame($this->dimensions, $actual);
            self::assertSame(15000000, \MyInvoice\Service\Accounting\JournalLineAmount::cents($line['amount']));
        }
    }

    public function testPropertyConsumerRejectsChangedProofAmountAndTenant(): void
    {
        $this->propertyReserve();
        $proof = $this->proof();
        $lines = $proof->lines;
        $lines[0]['amount'] = '150000.01';
        $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
        $this->assertProofRejected(fn () => $policy->assertLegalClosingJournal($proof->header, $lines, $proof), 'legal_closing_source_changed');
        $header = $proof->header;
        $header['supplier_id']++;
        $this->assertProofRejected(fn () => $policy->assertLegalClosingJournal($header, $proof->lines, $proof), 'legal_closing_source_changed');
    }

    public function testPropertyConsumerRereadsCurrentAssetSource(): void
    {
        [$asset] = $this->propertyReserve();
        $proof = $this->proof();
        $this->db->pdo()->prepare("UPDATE assets SET input_price='100001.00' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $asset]);
        $this->assertProofRejected(fn () => (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertLegalClosingJournal($proof->header, $proof->lines, $proof), 'asset_reserve_source_changed');
    }

    public function testPropertyConsumerRejectsProofAfterEarlierSavepointRollback(): void
    {
        $this->propertyReserve();
        $this->db->pdo()->exec('SAVEPOINT before_asset_property_proof');
        $proof = $this->proof();
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_asset_property_proof');
        $this->assertProofRejected(fn () => (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertLegalClosingJournal($proof->header, $proof->lines, $proof), 'legal_closing_source_changed');
    }

    public function testHistoricalProofDoesNotAuthorizeOrphanPropertyValue(): void
    {
        $code = null;
        try { $this->propertyReserve(true); }
        catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
        self::assertSame('property_dimension_unavailable', $code);
    }

    private function assertProofRejected(callable $call, string $expected): void
    {
        $code = null;
        try { $call(); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
        self::assertSame($expected, $code);
    }

    private function proof(): \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof
    {
        $period = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period);
        return \MyInvoice\Service\Accounting\Closing\ClosingLegal504Proof::forPending($this->db,
            $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class), $this->supplier, $this->period, (int) $period['row_version'] - 1);
    }

    private function propertyReserve(bool $orphan = false): array
    {
        $pdo = $this->db->pdo();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $profile = new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db);
        $profile->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2096-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic property activity', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'asset-property-active'], $actor);
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT ?,'Synthetic owner','Synthetic 1','Synthetic','10000',country_id,'asset-owner@example.test',default_currency_id FROM supplier WHERE id=?")
            ->execute([$this->supplier, $this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $registry = new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db);
        $request = static fn (string $code, array $state): array => ['code' => $code, 'expected_revision' => 0,
            'effective_from' => '2096-01-01T00:00:00+01:00', 'status' => 'confirmed', 'state' => $state,
            'reason' => 'Synthetic registry', 'title_ref' => 'Synthetic deed', 'idempotency_key' => $code];
        $house = $registry->create($this->supplier, 'house', $request('ASSET-HOUSE', ['name' => 'Synthetic house', 'active' => true,
            'address' => ['street' => 'Synthetic 1', 'city' => 'Synthetic', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic deed', 'share_scope' => 'ASSET-HOUSE', 'registry_complete' => true]), $actor);
        $service = $registry->create($this->supplier, 'service', $request('ASSET-SERVICE', ['name' => 'Synthetic water', 'active' => true, 'measure' => 'm3', 'measure_label' => '']), $actor);
        $unit = $registry->create($this->supplier, 'unit', $request('ASSET-UNIT', ['unit_no' => 'ASSET-UNIT', 'kind' => 'apartment', 'active' => true,
            'attributes' => ['share' => ['num' => '1', 'den' => '1']],
            'ownerships' => [['group_key' => 'OWNER', 'kind' => 'single', 'share' => ['num' => '1', 'den' => '1'], 'members' => [$client], 'title_ref' => 'Synthetic deed']],
            'roles' => [], 'identifiers' => [], 'services' => [$service['id']]]) + ['parent_id' => $house['id']], $actor);
        $statement = $pdo->prepare('SELECT o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id IN (?,?)');
        $statement->execute([$this->supplier, $unit['id'], $service['id']]);
        $property = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $property);
        foreach ($property as $row) $this->dimensions[(int) $row['type_id']] = (int) $row['dimension_value_id'];
        ksort($this->dimensions);
        [$asset, $sourceEntry] = $this->asset(true);
        if ($orphan) {
            $type = $pdo->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='unit'");
            $type->execute([$this->supplier]);
            $typeId = (int) $type->fetchColumn();
            $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$typeId, $this->supplier, 'SYN-ORPHAN', 'Synthetic unbound property value']);
            $orphanId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id AND l.supplier_id=d.supplier_id SET d.dimension_value_id=? WHERE d.supplier_id=? AND l.entry_id=? AND d.dimension_type_id=?')
                ->execute([$orphanId, $this->supplier, $sourceEntry, $typeId]);
        }
        $this->closeSource();
        foreach ($property as $row) $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $row['dimension_value_id']]);
        $profile->save($this->supplier, ['expected_revision' => 1, 'effective_from' => '2097-01-01T00:00:00+01:00',
            'enabled' => false, 'reason' => 'Synthetic retired property activity', 'title_ref' => 'Synthetic decision', 'idempotency_key' => 'asset-property-retired'], $actor);
        $posted = $this->postSource(['asset_id' => $asset, 'mode' => 'capitalization', 'allocation_method' => 'capitalization_joint_proportional',
            'continued_use_evidence' => 'Synthetic continuing repair purpose with historical property tuple']);
        return [$asset, $posted];
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
