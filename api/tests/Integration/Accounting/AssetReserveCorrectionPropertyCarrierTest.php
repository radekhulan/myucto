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

final class AssetReserveCorrectionPropertyCarrierTest extends TestCase
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

    public function testActualClosedCorrectionRetainsRetiredPropertyTuples(): void
    {
        $propertyFixture = $this->propertyFixture();
        $fixture = $this->closedFixture();
        $pdo = $this->db->pdo();
        foreach ($propertyFixture['property'] as $row) {
            $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $row['dimension_value_id']]);
        }
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->supplier,
            ['expected_revision' => 1, 'effective_from' => '2098-01-01T00:00:00+01:00', 'enabled' => false,
                'reason' => 'Synthetic retired property activity', 'title_ref' => 'Synthetic decision', 'idempotency_key' => 'asset-correction-property-retired'], $propertyFixture['actor']);
        $service = $fixture['service'];
        $next = $fixture['next'];
        $request = $fixture['request'];
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        self::assertSame(12000000, $preview['remaining_cents']);
        self::assertSame(['941'], array_values(array_unique(array_column($preview['lines'], 'account_code'))));
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $history = $pdo->prepare('SELECT e.id,e.reversed_by,l.id AS line_id,l.account_id,l.side,l.amount FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.period_id=? ORDER BY e.id,l.id');
        $history->execute([$this->supplier, $this->period]);
        $before = $history->fetchAll(\PDO::FETCH_ASSOC);
        $corrected = $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertGreaterThan(0, $corrected['entry_id']);
        $audit = $pdo->prepare("SELECT action FROM activity_log WHERE supplier_id=? AND entity_type='journal_entry' AND entity_id=?");
        $audit->execute([$this->supplier, $corrected['entry_id']]);
        self::assertSame(['accounting.posted'], $audit->fetchAll(\PDO::FETCH_COLUMN));
        $history->execute([$this->supplier, $this->period]);
        self::assertSame($before, $history->fetchAll(\PDO::FETCH_ASSOC));
        $statement = $pdo->prepare('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id');
        $statement->execute([$this->supplier, $corrected['entry_id']]);
        $lines = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $lines);
        self::assertSame(['941'], array_values(array_unique(array_column($lines, 'account_code'))));
        $dimensions = (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplier, $corrected['entry_id'], true);
        $expected = [];
        foreach ($preview['lines'] as $line) {
            $tuple = $line['dimensions'];
            ksort($tuple);
            $expected[$line['side']] = $tuple;
        }
        self::assertCount(2, $expected);
        foreach ($lines as $line) {
            self::assertSame(12000000, \MyInvoice\Service\Accounting\JournalLineAmount::cents($line['amount']));
            $tuple = $dimensions[$line['id']] ?? [];
            ksort($tuple);
            self::assertSame($expected[$line['side']], $tuple);
            foreach ($propertyFixture['property'] as $row) self::assertSame((int) $row['dimension_value_id'], $tuple[(int) $row['type_id']]);
        }
        self::assertSame(12000000, array_sum($fixture['repo']->reserveRemaining($this->supplier, $fixture['created']['entry_id'], '2098-12-31')));
        self::assertSame('approved', $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['status']);
        self::assertSame($corrected, $service->correctAssetReserveSource($this->supplier, $next, $request));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['created'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['used'])]
    public function testServiceReconciliationRejectsChangedNativeReserveBeforeSkippingIt(string $record): void
    {
        $this->propertyFixture();
        $fixture = $this->closedFixture(prepareCorrection: false);
        $reconciliation = new \MyInvoice\Service\Property\Services\Reconciliation\ServiceReconciliation($this->db);
        $before = $reconciliation->at($this->supplier, '2097-12-31');
        self::assertSame('complete', $before['coverage']);
        self::assertTrue($before['matches']);
        $entry = (int) $fixture[$record]['entry_id'];
        $line = $this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_type='expense' ORDER BY l.id");
        $line->execute([$this->supplier, $entry]);
        $lineId = (int) $line->fetchColumn();
        self::assertGreaterThan(0, $lineId);
        $change = $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+0.01 WHERE supplier_id=? AND id=?');
        $change->execute([$this->supplier, $lineId]);
        self::assertSame(1, $change->rowCount());
        $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
        $reconciliation->at($this->supplier, '2097-12-31');
    }

    public function testPendingCorrectionPropertyConsumerRejectsChangedAmountAndTenant(): void
    {
        $fixture = $this->pendingProofFixture();
        $proof = $this->pendingProof($fixture);
        self::assertCount(2, $proof->indices($this->db, $proof->header, $proof->lines));
        $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
        $lines = $proof->lines;
        $lines[0]['amount'] = '120000.01';
        $this->assertPendingProofRejected(fn () => $policy->assertAssetReserveCorrectionJournal($proof->header, $lines, $proof));
        $header = $proof->header;
        $header['supplier_id']++;
        $this->assertPendingProofRejected(fn () => $policy->assertAssetReserveCorrectionJournal($header, $proof->lines, $proof));
    }

    public function testPendingCorrectionPropertyConsumerRereadsCurrentAsset(): void
    {
        $fixture = $this->pendingProofFixture();
        $proof = $this->pendingProof($fixture);
        self::assertCount(2, $proof->indices($this->db, $proof->header, $proof->lines));
        $this->db->pdo()->prepare("UPDATE assets SET input_price='100001.00' WHERE supplier_id=? AND id=?")
            ->execute([$this->supplier, $fixture['asset']]);
        $this->assertPendingProofRejected(fn () => (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertAssetReserveCorrectionJournal($proof->header, $proof->lines, $proof));
    }

    public function testPendingCorrectionProofCannotSurviveEarlierSavepointRollback(): void
    {
        $fixture = $this->pendingProofFixture();
        $this->db->pdo()->exec('SAVEPOINT before_correction_property_proof');
        $proof = $this->pendingProof($fixture);
        self::assertCount(2, $proof->indices($this->db, $proof->header, $proof->lines));
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_correction_property_proof');
        $this->assertPendingProofRejected(fn () => (new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db))->assertAssetReserveCorrectionJournal($proof->header, $proof->lines, $proof));
    }

    private function assertPendingProofRejected(callable $call): void
    {
        $code = null;
        try { $call(); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_changed', $code);
    }

    private function pendingProof(array $fixture): \MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof
    {
        return \MyInvoice\Service\Accounting\Closing\AssetReserveCorrection504Proof::forPending($this->db,
            $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class), $this->supplier, $fixture['next'], $fixture['expected_version']);
    }

    private function pendingProofFixture(): array
    {
        $propertyFixture = $this->propertyFixture();
        $fixture = $this->closedFixture();
        foreach ($propertyFixture['property'] as $row) {
            $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $row['dimension_value_id']]);
        }
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->supplier,
            ['expected_revision' => 1, 'effective_from' => '2098-01-01T00:00:00+01:00', 'enabled' => false,
                'reason' => 'Synthetic retired property activity', 'title_ref' => 'Synthetic decision', 'idempotency_key' => 'asset-correction-property-retired'], $propertyFixture['actor']);
        $request = $fixture['request'];
        $preview = $fixture['service']->assetReserveCorrectionPreview($this->supplier, $fixture['next'], $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $period = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $fixture['next']);
        $request['row_version'] = (int) $period['row_version'];
        $step = (new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db,
            $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)))->step($this->supplier, $fixture['next'], 'provisions');
        $payload = $step['payload'];
        $payload['asset_correction_pending'] = ['status' => 'pending', 'expected_version' => $request['row_version'],
            'body' => $request, 'document_no' => 'SYN-CORRECTION-PROPERTY', 'posted_at' => '2098-12-31 12:00:00', 'meta' => []];
        $this->container->get(\MyInvoice\Repository\ClosingRepository::class)->upsertStep($this->supplier, $fixture['next'],
            'provisions', $step['status'], $payload, $step['note'], null);
        return $fixture + ['expected_version' => $request['row_version']];
    }

    private function propertyFixture(): array
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
        return ['property' => $property, 'actor' => $actor];
    }

    private function closedFixture(bool $anotherReserve = false, bool $unusedCard = false, bool $reversedUse = false, bool $jointSource = false, bool $prepareCorrection = true): array
    {
        [$asset] = $this->asset(false);
        $oldDimensions = $this->dimensions;
        $oldLine = $this->openingLine('100000.00');
        $stmt = $this->db->pdo()->prepare('SELECT type_id FROM dimension_values WHERE id=?');
        $stmt->execute([$this->kind]);
        $kindType = (int) $stmt->fetchColumn();
        $this->db->pdo()->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
            ->execute([$kindType, $this->supplier, 'correct_asset_repair', 'Synthetic documented original asset activity']);
        $this->dimensions[$kindType] = (int) $this->db->pdo()->lastInsertId();
        $newLine = $this->openingLine($jointSource ? '33333.33' : '100000.00');
        $newContributions = [['line_id' => $newLine, 'contribution_cents' => $jointSource ? 3333333 : 10000000]];
        $jointTuples = [$this->dimensions];
        if ($jointSource) {
            $this->db->pdo()->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$kindType, $this->supplier, 'second_asset_repair', 'Synthetic second joint purpose']);
            $this->dimensions[$kindType] = (int) $this->db->pdo()->lastInsertId();
            $activity = $this->db->pdo()->prepare("SELECT t.id AS type_id,v.id FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id WHERE t.supplier_id=? AND t.system_key='activity' AND v.semantic_key='main'");
            $activity->execute([$this->supplier]);
            $main = $activity->fetch(\PDO::FETCH_ASSOC);
            $this->dimensions[(int) $main['type_id']] = (int) $main['id'];
            $jointTuples[] = $this->dimensions;
            $newContributions[] = ['line_id' => $this->openingLine('66666.67'), 'contribution_cents' => 6666667];
        }
        $this->dimensions = $oldDimensions;
        $otherCreated = null;
        $otherSource = null;
        $unusedAsset = null;
        if ($unusedCard) [$unusedAsset] = $this->asset(false);
        if ($anotherReserve) {
            [$otherAsset] = $this->asset(false);
            $otherLine = $this->openingLine('100000.00');
            $otherSource = $this->association($otherAsset, [['line_id' => $otherLine, 'contribution_cents' => 10000000]]);
        }
        $this->closeSource();
        $source = $this->association($asset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $created = $this->postSource($source, $jointSource ? '150000.01' : '150000.00');
        if ($otherSource !== null) $otherCreated = $this->postSource($otherSource);
        $service = $this->container->get(ClosingService::class);
        $release = $this->sourceBody($source, '30000.00');
        $release['rule_key'] = 'reserve.repairs.release';
        unset($release['legal_decision']['reserve_source']);
        $release['legal_decision']['source_entry_id'] = $created['entry_id'];
        $release['legal_decision']['allocations'] = [['amount' => '30000.00', 'dimensions' => $oldDimensions]];
        $used = $service->createAssistedEntry($this->supplier, $this->period, 'provisions', $release);
        $usageReversal = null;
        if ($reversedUse) $usageReversal = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse(
            $this->supplier, $used['entry_id'], ['entry_date' => '2097-12-31', 'allow_closing_period' => true]);
        if (!$prepareCorrection) return compact('created', 'used');
        $incomeTypes = $this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key IN ('activity','activity_kind','tax_regime') ORDER BY id");
        $incomeTypes->execute([$this->supplier]);
        $incomeDimensions = array_intersect_key($oldDimensions, array_fill_keys(array_map('intval', $incomeTypes->fetchAll(\PDO::FETCH_COLUMN)), true));
        self::assertCount(3, $incomeDimensions);
        $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $anotherReserve ? '270000.00' : ($reversedUse ? '150000.00' : ($jointSource ? '120000.01' : '120000.00')), 'dimensions' => $incomeDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $incomeDimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $anotherReserve ? '270000.00' : ($reversedUse ? '150000.00' : ($jointSource ? '120000.01' : '120000.00')), 'dimensions' => $incomeDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $incomeDimensions)],
        ], ['entry_date' => '2097-12-31', 'posted' => true, 'allow_closing_period' => true]);
        $inventory = [];
        foreach ($service->inventoryPreview($this->supplier, $this->period)['rows'] as $row) {
            $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $service->saveInventory($this->supplier, $this->period, $this->sourceBody([])['row_version'], ['complete' => true], $inventory);
        $service->runPrecheck($this->supplier, $this->period, $this->sourceBody([])['row_version']);
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $service->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $this->sourceBody([])['row_version']);
        }
        $fx = $service->fxPreview($this->supplier, $this->period);
        $service->runFxRevaluation($this->supplier, $this->period, [], $this->sourceBody([])['row_version'], [], ['snapshot_hash' => $fx['snapshot_hash']]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($this->container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closed = $service->closeBooks($this->supplier, $this->period, $this->sourceBody([])['row_version']);
        self::assertGreaterThan(0, $closed['entry_id']);
        $actor = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $approval = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/periods/' . $this->period . '/status')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin'])
            ->withParsedBody(['status' => 'approved', 'confirm' => true, 'row_version' => $this->sourceBody([])['row_version'],
                'approval_body' => 'Synthetic authorised body', 'approval_decision_ref' => 'SYN-ASSET-CARRIER-APPROVAL']);
        $approved = $this->container->get(\MyInvoice\Action\Accounting\AccountingPeriodAction::class)->status(
            $approval, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $service->openNext($this->supplier, $this->period, $this->sourceBody([])['row_version']);
        $nextRow = $this->container->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2098-01-01');
        $next = (int) $nextRow['id'];
        $decision = $service->profitDistributionPreview($this->supplier, $this->period, '2098-01-01');
        self::assertNotEmpty($decision['cells']);
        self::assertSame(0, array_sum(array_column($decision['cells'], 'available_cents')));
        $service->runProfitDistribution($this->supplier, $this->period, [
            'decision_date' => '2098-01-01', 'decision_reference' => 'SYN-ASSET-CARRIER-RESULT',
            'decision_authority' => 'Synthetic authorised body', 'target_row_version' => $decision['target_period']['row_version'],
            'snapshot_hash' => $decision['snapshot_hash'], 'allocations' => array_map(static fn (array $cell): array => [
                'cell_key' => $cell['key'], 'role' => 'result.retained_profit', 'amount' => abs($cell['available'])], $decision['cells']),
        ], ['user_id' => $actor]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([$this->kind]);
        $nextRow = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next);
        $service->start($this->supplier, $next, $nextRow['row_version']);
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db,
            $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame($reversedUse ? 15000000 : ($jointSource ? 12000001 : 12000000), array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        $state = $service->assetReserveSourceState($this->supplier, $next, $asset);
        $request = ['mode' => 'prospective_closed_history', 'previous_hash' => $state['current']['source_hash'],
            'reason' => 'Synthetic wrong ledger contribution selected for the same original machine and repair purpose',
            'original_purpose' => ['same_original_asset_and_purpose' => true, 'document_date' => '2096-12-01', 'reference' => 'SYN-ORIGINAL-PLAN', 'evidence' => 'Synthetic contemporaneous repair plan identifies this machine before reserve creation'],
            'reserve_source' => $this->association($asset, $newContributions)];
        return compact('usageReversal', 'jointTuples', 'unusedAsset', 'otherCreated', 'asset', 'oldLine', 'newLine', 'oldDimensions', 'created', 'used', 'closed', 'next', 'repo', 'service', 'state', 'request');
    }

    private function history(): string
    {
        $snapshot = [];
        foreach (['journal_entries', 'journal_entry_lines', 'journal_entry_line_dimensions', 'accounting_closing_steps'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $stmt->execute([$this->supplier]);
            $snapshot[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            sort($snapshot[$table]);
        }
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
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
            ['account_code' => '022', 'side' => 'debit', 'amount' => $amount, 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $this->dimensions)],
            ['account_code' => '901', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2096-06-01', 'posted' => true]);
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='debit'");
        $stmt->execute([$entry]);
        return (int) $stmt->fetchColumn();
    }

    private function postSource(array $source, string $amount = '150000.00'): array
    {
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $source, $amount);
        $source['source_hash'] = $preview['source']['source_hash'];
        return $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->sourceBody($source, $amount));
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
