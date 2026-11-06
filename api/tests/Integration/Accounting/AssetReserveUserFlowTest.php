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

final class AssetReserveUserFlowTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $sourcePeriod;
    private int $period;
    private int $kind;
    private array $dimensions;
    private int $assetNumber = 0;
    private ?string $trigger = null;
    private bool $committed = false;
    private ?Connection $other = null;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($this->name() === 'testLateFailureRollsBackTransferRevisionAndClaimInsideCallerTransaction') {
            $this->trigger = 'asset_closed_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->trigger . " BEFORE UPDATE ON accounting_periods FOR EACH ROW BEGIN IF @asset_closed_fail=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic correction CAS failure'; END IF; END");
        }
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
        if ($this->other !== null) {
            if ($this->other->pdo()->inTransaction()) $this->other->pdo()->rollBack();
            $this->other->close();
        }
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'document_dimensions', 'document_dimension_splits', 'assets'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        $this->db->pdo()->exec('SET @asset_closed_fail=NULL');
        if ($this->trigger !== null) $this->db->pdo()->exec('DROP TRIGGER ' . $this->trigger);
    }












    public static function snapshots(): array { return ['without snapshot' => [false], 'old RR snapshot' => [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshots')]


    public static function assetChanges(): array { return ['asset account' => ['account'], 'backdated improvement' => ['improvement']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('assetChanges')]







    public static function frameworks(): array { return ['500 custom' => ['cz_500'], '504 joint' => ['cz_504']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworks')]

    public static function directMutations(): array { return ['partial' => ['partial'], 'detach' => ['detach'], 'reversal marker' => ['marker']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('directMutations')]

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshots')]


    public static function pinnedReversalTargets(): array
    {
        return array_combine(['creation', 'used', 'close', 'opening', 'transfer', 'old_source', 'current_source'],
            array_map(static fn (string $target): array => [$target], ['creation', 'used', 'close', 'opening', 'transfer', 'old_source', 'current_source']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pinnedReversalTargets')]

    private function closedFixture(bool $anotherReserve = false, bool $unusedCard = false, bool $reversedUse = false, bool $jointSource = false): array
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
        $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $anotherReserve ? '270000.00' : ($reversedUse ? '150000.00' : ($jointSource ? '120000.01' : '120000.00')), 'dimensions' => $oldDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $oldDimensions)],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $anotherReserve ? '270000.00' : ($reversedUse ? '150000.00' : ($jointSource ? '120000.01' : '120000.00')), 'dimensions' => $oldDimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-test'], $oldDimensions)],
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
        $service->runFxRevaluation($this->supplier, $this->period, [], $this->sourceBody([])['row_version'], [], ['snapshot_hash' => $fx['snapshot_hash'], 'account_decisions' => [], 'case_decisions' => []]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([$this->kind]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($this->container, $this->supplier, $this->period, (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closed = $service->closeBooks($this->supplier, $this->period, $this->sourceBody([])['row_version']);
        self::assertGreaterThan(0, $closed['entry_id']);
        $service->openNext($this->supplier, $this->period, $this->sourceBody([])['row_version']);
        $nextRow = $this->container->get(AccountingPeriodRepository::class)->findForDate($this->supplier, '2098-01-01');
        $next = (int) $nextRow['id'];
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

    private function action(string $method, int $period, array $body = [], array $permissions = ['accounting' => 1, 'accounting.periods.close' => 2, 'assets' => 1], ?int $supplier = null): array
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method === 'assetReserveWorkspace' ? 'GET' : 'POST', '/api/accounting/periods/' . $period . '/closing/asset-reserve')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier ?? $this->supplier)
            ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(2, 'Synthetic', 'staff', true, $permissions))
            ->withParsedBody($body)->withQueryParams($body);
        $response = $this->container->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class)->$method($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['id' => $period, 'entryId' => $body['entry_id'] ?? 0]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testWorkspaceAndPreviewReadOnlyThenCreatesThroughExistingWriter(): void
    {
        [$asset] = $this->asset(true);
        $this->closeSource();
        $source = ['asset_id' => $asset, 'mode' => 'capitalization', 'allocation_method' => 'capitalization_joint_proportional', 'continued_use_evidence' => 'Synthetic continued use'];
        $before = $this->history();
        [$status, $workspace] = $this->action('assetReserveWorkspace', $this->period, ['asset_id' => $asset], ['accounting' => 1, 'assets' => 1]);
        self::assertSame(200, $status, json_encode($workspace));
        self::assertSame($asset, (int) $workspace['assets'][0]['id']);
        self::assertNotEmpty($workspace['lines']);
        [$status, $preview] = $this->action('assetReservePreview', $this->period, ['reserve_source' => $source, 'amount' => '150000.00'], ['accounting' => 1, 'assets' => 1]);
        self::assertSame(200, $status, json_encode($preview));
        self::assertSame($before, $this->history());
        $source['source_hash'] = $preview['source']['source_hash'];
        [$status, $written] = $this->action('createEntry', $this->period, ['step' => 'provisions'] + $this->sourceBody($source));
        self::assertSame(201, $status, json_encode($written));
        self::assertGreaterThan(0, $written['entry_id']);
        $release = $this->sourceBody([], '30000.00');
        $release['step'] = 'provisions';
        $release['rule_key'] = 'reserve.repairs.release';
        unset($release['legal_decision']['reserve_source']);
        $release['legal_decision']['source_entry_id'] = $written['entry_id'];
        $release['legal_decision']['allocations'] = [['amount' => '30000.00', 'dimensions' => $this->dimensions]];
        [$status, $used] = $this->action('createEntry', $this->period, $release);
        self::assertSame(201, $status, json_encode($used));
        $repo = new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class));
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $written['entry_id'], '2097-12-31')));
        [$status, $workspace] = $this->action('assetReserveWorkspace', $this->period, ['asset_id' => $asset]);
        self::assertSame(200, $status);
        self::assertSame([$written['entry_id'], $used['entry_id']], array_column($workspace['entries'], 'entry_id'));
    }

    public function testClosedCorrectionUserFlowCarriesOnlyRemainingAndPreservesHistory(): void
    {
        extract($this->closedFixture());
        [$status, $preview] = $this->action('assetReserveCorrectionPreview', $next, $request, ['accounting' => 1, 'assets' => 1]);
        self::assertSame(200, $status, json_encode($preview));
        self::assertSame(12000000, $preview['remaining_cents']);
        self::assertSame(['941'], array_values(array_unique(array_column($preview['lines'], 'account_code'))));
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        [$status, $written] = $this->action('assetReserveCorrect', $next, $request);
        self::assertSame(200, $status, json_encode($written));
        self::assertGreaterThan(0, $written['entry_id']);
        self::assertSame([$status, $written], $this->action('assetReserveCorrect', $next, $request));
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        $stmt = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE id IN (?,?) ORDER BY id');
        $stmt->execute([$created['entry_id'], $used['entry_id']]);
        self::assertSame([null, null], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testReadPermissionCannotConfirmCorrectionAndNoPermissionCannotPreview(): void
    {
        extract($this->closedFixture());
        $before = $this->history();
        self::assertSame(403, $this->action('assetReserveCorrect', $next, $request, ['accounting' => 1, 'assets' => 1])[0]);
        self::assertSame(403, $this->action('assetReserveCorrectionPreview', $next, $request, [])[0]);
        self::assertSame(403, $this->action('assetReserveWorkspace', $next, ['asset_id' => $asset], [])[0]);
        self::assertSame($before, $this->history());
    }

    public function testChangedSourceAndForeignTenantAreRejectedWithoutMutation(): void
    {
        extract($this->closedFixture());
        [$status, $preview] = $this->action('assetReserveCorrectionPreview', $next, $request);
        self::assertSame(200, $status);
        $request['reserve_source']['source_hash'] = str_repeat('0', 64);
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $before = $this->history();
        self::assertSame(409, $this->action('assetReserveCorrect', $next, $request)[0]);
        self::assertSame($before, $this->history());
        $this->db->pdo()->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) SELECT 'Synthetic foreign tenant','Test','Test','10000','foreign@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $foreign = (int) $this->db->pdo()->lastInsertId();
        self::assertNotSame(200, $this->action('assetReserveWorkspace', $next, ['asset_id' => $asset], ['accounting' => 1, 'assets' => 1], $foreign)[0]);
        self::assertNotSame(200, $this->action('assetReserveCorrectionPreview', $next, $request, ['accounting' => 1, 'assets' => 1], $foreign)[0]);
        self::assertSame($before, $this->history());
    }

    public function testSamePeriodCorrectionRequiresReversedFullMirror(): void
    {
        [$asset] = $this->asset(false);
        $oldLine = $this->openingLine('100000.00');
        $newLine = $this->openingLine('100000.00');
        $this->closeSource();
        $source = $this->association($asset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $created = $this->postSource($source);
        $state = $this->container->get(ClosingService::class)->assetReserveSourceState($this->supplier, $this->period, $asset);
        $request = ['mode' => 'same_period', 'previous_hash' => $state['current']['source_hash'], 'reason' => 'Synthetic wrong association', 'reserve_source' => $this->association($asset, [['line_id' => $newLine, 'contribution_cents' => 10000000]])];
        $before = $this->history();
        self::assertSame(409, $this->action('assetReserveCorrectionPreview', $this->period, $request)[0]);
        self::assertSame($before, $this->history());
        self::assertSame(200, $this->action('reverseEntry', $this->period, ['entry_id' => $created['entry_id'], 'row_version' => $this->sourceBody([])['row_version']])[0]);
        [$status, $preview] = $this->action('assetReserveCorrectionPreview', $this->period, $request);
        self::assertSame(200, $status, json_encode($preview));
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->sourceBody([])['row_version'];
        [$status, $written] = $this->action('assetReserveCorrect', $this->period, $request);
        self::assertSame(200, $status, json_encode($written));
        self::assertSame(1, $written['revision_number']);
    }

    public function testUnknownCorrectionModeAndMissingVersionAreRejected(): void
    {
        $before = $this->history();
        self::assertSame(422, $this->action('assetReserveCorrect', $this->period, ['mode' => 'arbitrary'])[0]);
        self::assertSame(422, $this->action('assetReserveCorrect', $this->period, ['mode' => 'same_period'])[0]);
        self::assertSame($before, $this->history());
        $map = new \MyInvoice\Security\RoutePermissionMap();
        self::assertSame('accounting', $map->match('POST', '/api/accounting/periods/1/closing/asset-reserve/correction-preview')->key);
        self::assertSame('accounting.periods.close', $map->match('POST', '/api/accounting/periods/1/closing/asset-reserve/correct')->key);
    }
    public function testAssetWorkspaceRejects500AndStaleCorrectionVersion(): void
    {
        extract($this->closedFixture());
        [$status, $preview] = $this->action('assetReserveCorrectionPreview', $next, $request);
        self::assertSame(200, $status);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = 999999;
        $before = $this->history();
        self::assertSame(409, $this->action('assetReserveCorrect', $next, $request)[0]);
        self::assertSame($before, $this->history());
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        self::assertSame(422, $this->action('assetReserveWorkspace', $next, ['asset_id' => $asset])[0]);
        self::assertSame($before, $this->history());
    }
    public function testAssetReadPermissionIsRequiredButLegalWorkspaceDoesNotExposeAssets(): void
    {
        [$asset] = $this->asset(true);
        $this->closeSource();
        $before = $this->history();
        $rights = ['accounting' => 1, 'accounting.periods.close' => 2];
        self::assertSame(403, $this->action('assetReserveWorkspace', $this->period, ['asset_id' => $asset], $rights)[0]);
        self::assertSame(403, $this->action('assetReservePreview', $this->period, [], $rights)[0]);
        self::assertSame(403, $this->action('assetReserveCorrect', $this->period, ['mode' => 'same_period'], $rights)[0]);
        [$status, $workspace] = $this->action('legalProvisionsWorkspace', $this->period, ['asset_id' => $asset], $rights);
        self::assertSame(200, $status, json_encode($workspace));
        self::assertSame([], $workspace['assets']);
        self::assertSame([], $workspace['lines']);
        self::assertNull($workspace['state']['current']);
        self::assertSame($before, $this->history());
    }
    public function testNativeImprovementRequiresCompleteDocumentedAssociation(): void
    {
        [$asset, $capitalizationEntry] = $this->asset(true);
        $this->container->get(AssetService::class)->addImprovement($this->supplier, $asset, ['completed_on' => '2096-07-01', 'amount' => '90000.00', 'description' => 'Synthetic technical improvement']);
        $tzEntry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '022', 'side' => 'debit', 'amount' => '90000.00', 'dimensions' => $this->dimensions, 'dimension_origins' => array_map(static fn (): array => ['origin' => 'explicit', 'ref' => 'synthetic-improvement'], $this->dimensions)],
            ['account_code' => '042', 'side' => 'credit', 'amount' => '90000.00'],
        ], ['entry_date' => '2096-07-01', 'posted' => true, 'description' => 'Synthetic technical improvement capitalization']);
        $this->closeSource();
        $source = ['asset_id' => $asset, 'mode' => 'capitalization', 'allocation_method' => 'capitalization_joint_proportional', 'continued_use_evidence' => 'Synthetic continued use after improvement'];
        $before = $this->history();
        [$status, $error] = $this->action('assetReservePreview', $this->period, ['reserve_source' => $source, 'amount' => '150000.00']);
        self::assertSame(409, $status);
        self::assertSame('asset_reserve_source_changed', $error['error']['code']);
        self::assertSame($before, $this->history());
        [$status, $workspace] = $this->action('assetReserveWorkspace', $this->period, ['asset_id' => $asset]);
        self::assertSame(200, $status);
        $contributions = [];
        foreach ($workspace['lines'] as $line) {
            if (!in_array((int) $line['entry_id'], [$capitalizationEntry, $tzEntry], true)) continue;
            $contributions[] = ['line_id' => (int) $line['line_id'], 'contribution_cents' => \MyInvoice\Service\Accounting\JournalLineAmount::cents($line['amount'])];
        }
        self::assertCount(2, $contributions);
        self::assertSame(19000000, array_sum(array_column($contributions, 'contribution_cents')));
        $source = $this->association($asset, $contributions);
        [$status, $preview] = $this->action('assetReservePreview', $this->period, ['reserve_source' => $source, 'amount' => '150000.00']);
        self::assertSame(200, $status, json_encode($preview));
        self::assertSame(19000000, $preview['source']['gross_cents']);
        self::assertCount(1, $preview['source']['improvements']);
        self::assertSame('150000.00', $preview['allocations'][0]['amount']);
        $source['source_hash'] = $preview['source']['source_hash'];
        [$status, $written] = $this->action('createEntry', $this->period, ['step' => 'provisions'] + $this->sourceBody($source));
        self::assertSame(201, $status, json_encode($written));
        self::assertGreaterThan(0, $written['entry_id']);
    }
}
