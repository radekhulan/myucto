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

final class AssetReserveSourceProofTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $sourcePeriod;
    private int $period;
    private int $kind;
    private array $dimensions;
    private int $assetNumber = 0;
    private bool $committed = false;
    private ?\Psr\Container\ContainerInterface $other = null;
    private ?string $trigger = null;
    private int $originalIsolation;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        if ($this->name() === 'testLateWriterFailureRollsBackPendingJournalAndVersionInsideCallerTransaction') {
            $this->trigger = 'asset_reserve_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->trigger . " BEFORE UPDATE ON accounting_closing_steps FOR EACH ROW BEGIN IF @asset_reserve_fail=1 AND JSON_UNQUOTE(JSON_EXTRACT(NEW.payload,'$.legal_pending.status'))='written' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic source writer failure'; END IF; END");
        }
        if ($this->name() === 'testCorrectionFailureRollsBackRevisionAndVersionInsideCallerTransaction') {
            $this->trigger = 'asset_revision_' . bin2hex(random_bytes(6));
            $pdo->exec('CREATE TRIGGER ' . $this->trigger . " BEFORE UPDATE ON accounting_periods FOR EACH ROW BEGIN IF @asset_reserve_fail=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic revision failure'; END IF; END");
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
        if ($this->other !== null) $this->other->get(Connection::class)->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'document_dimensions', 'document_dimension_splits', 'assets'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        $this->db->pdo()->exec('SET @asset_reserve_fail=NULL');
        $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
        if ($this->trigger !== null) $this->db->pdo()->exec('DROP TRIGGER ' . $this->trigger);
    }

    public function testActiveAssetTupleSupportsRepairBudgetAboveAcquisitionValue(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $this->assertReserve($this->body($asset, $entry, $dimensions));
    }

    public function testRetiredCapitalizationTupleCanSupportDocumentedRepairReserve(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=? AND supplier_id=?')->execute([$this->kind, $this->supplier]);
        $this->assertReserve($this->body($asset, $entry, $dimensions));
    }

    public function testHistoricalCardWithoutBookingHasNoCapitalizationAuthority(): void
    {
        [$asset, $entry] = $this->asset(false);
        self::assertGreaterThan(0, $asset);
        self::assertSame(0, $entry);
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='asset' AND source_id=?");
        $statement->execute([$this->supplier, $asset]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testHistoricalCardExplicitAssociationKeepsJointCorrelationAndExactBudget(): void
    {
        [$asset] = $this->asset(false);
        $kindType = array_keys($this->dimensions)[1];
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$kindType, $this->supplier, 'second', 'Second synthetic activity']);
        $secondKind = (int) $pdo->lastInsertId();
        $dimensions = $this->dimensions;
        $dimensions[$kindType] = $secondKind;
        $entry = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '022', 'side' => 'debit', 'amount' => '60000.00', 'dimensions' => $this->dimensions, 'cost_center' => 'A'],
            ['account_code' => '022', 'side' => 'debit', 'amount' => '40000.00', 'dimensions' => $dimensions, 'cost_center' => 'B'],
            ['account_code' => '901', 'side' => 'credit', 'amount' => '100000.00'],
        ], ['entry_date' => '2096-06-01', 'posted' => true]);
        $stmt = $pdo->prepare("SELECT id,amount FROM journal_entry_lines WHERE entry_id=? AND side='debit' ORDER BY id");
        $stmt->execute([$entry]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $source = $this->association($asset, array_map(static fn (array $r): array => ['line_id' => (int) $r['id'], 'contribution_cents' => \MyInvoice\Service\Accounting\JournalLineAmount::cents($r['amount'])], $rows));
        $this->closeSource();
        $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE id IN (?,?)')->execute([$this->kind, $secondKind]);
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $source, '150000.01');
        self::assertCount(2, $preview['allocations']);
        $byKind = [];
        foreach ($preview['allocations'] as $cell) $byKind[$cell['dimensions'][$kindType]] = [$cell['cost_center'], $cell['amount']];
        self::assertSame(['A', '90000.01'], $byKind[$this->kind]);
        self::assertSame(['B', '60000.00'], $byKind[$secondKind]);
        $source['source_hash'] = $preview['source']['source_hash'];
        $result = $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->sourceBody($source, '150000.01'));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id=?');
        $stmt->execute([$result['entry_id']]);
        self::assertSame(4, (int) $stmt->fetchColumn());
    }

    public function testTwoAssetsCannotClaimSameGrossContribution(): void
    {
        [$first] = $this->asset(false);
        [$second] = $this->asset(false);
        $line = $this->openingLine('100000.00');
        $this->closeSource();
        $source = $this->association($first, [['line_id' => $line, 'contribution_cents' => 10000000]]);
        $this->postSource($source);
        try {
            $this->postSource($this->association($second, [['line_id' => $line, 'contribution_cents' => 10000000]]));
            self::fail('The same gross contribution cannot establish two different assets.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('asset_reserve_source_claimed', $e->errorCode);
        }
    }

    public function testClaimCommittedAfterPreviewIsSeenUnderOldSnapshot(): void
    {
        [$first] = $this->asset(false);
        [$second] = $this->asset(false);
        $line = $this->openingLine('100000.00');
        $this->closeSource();
        $firstSource = $this->association($first, [['line_id' => $line, 'contribution_cents' => 10000000]]);
        $secondSource = $this->association($second, [['line_id' => $line, 'contribution_cents' => 10000000]]);
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $secondSource, '150000.00');
        $secondSource['source_hash'] = $preview['source']['source_hash'];
        $this->db->pdo()->commit();
        $this->committed = true;
        $this->other = Connection::withoutSharedTestConnection(static function (): \Psr\Container\ContainerInterface {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        $otherDb = $this->other->get(Connection::class);
        self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $otherDb->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $otherDb->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
        $otherDb->pdo()->beginTransaction();
        $otherDb->pdo()->query('SELECT COUNT(*) FROM accounting_closing_steps')->fetchColumn();
        $this->db->pdo()->beginTransaction();
        $this->postSource($firstSource);
        try {
            $otherDb->pdo()->query('SELECT id FROM accounting_periods WHERE supplier_id=' . $this->supplier . ' ORDER BY starts_on,id FOR UPDATE NOWAIT')->fetchAll();
            self::fail('A competing claim must wait for the same period-first lock.');
        } catch (\PDOException $e) { self::assertSame(1205, (int) $e->errorInfo[1]); self::assertTrue($otherDb->pdo()->inTransaction()); }
        $this->db->pdo()->commit();
        $body = $this->sourceBody($secondSource);
        try {
            $this->other->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('Current claim must be read after the competing transaction committed.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('asset_reserve_source_claimed', $e->errorCode);
        } finally { if ($otherDb->pdo()->inTransaction()) $otherDb->pdo()->rollBack(); }
    }

    public function testRetiredSourceReserveReleaseAndRealReversalPreserveReceipt(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([$this->kind]);
        $body = $this->body($asset, $entry, $dimensions);
        $service = $this->container->get(ClosingService::class);
        $created = $service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        $body = $this->sourceBody($body['legal_decision']['reserve_source'], '50000.00');
        $body['rule_key'] = 'reserve.repairs.release';
        unset($body['legal_decision']['reserve_source']);
        $body['legal_decision']['source_entry_id'] = $created['entry_id'];
        $body['legal_decision']['allocations'] = [['amount' => '50000.00', 'dimensions' => $dimensions]];
        $released = $service->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        $version = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->reverseAssistedEntry($this->supplier, $this->period, $released['entry_id'], $version);
        $preview = $service->provisionsPreview($this->supplier, $this->period);
        self::assertSame(15000000, array_sum(array_column($preview['reserves'][0]['cells'], 'available_cents')));
    }

    public function testUseGuardAllowsExactRetryAndRejectsSourceAmountOrDateChange(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body($asset, $entry, $dimensions));
        $guard = $this->container->get(\MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard::class);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->findForUpdate($entry, $this->supplier);
        foreach ($journal['lines'] as &$line) { $line['dimensions'] = $dimensions; $line['dimension_splits'] = []; }
        unset($line);
        $guard->assertEntryChange($this->supplier, $entry, $journal['lines'], '2096-06-01');
        $guard->assertAssetChange($this->supplier, $asset, ['name' => 'Harmless description']);
        foreach (['amount', 'date'] as $case) {
            $lines = $journal['lines'];
            if ($case === 'amount') $lines[0]['amount'] = '99999.00';
            try { $guard->assertEntryChange($this->supplier, $entry, $lines, $case === 'date' ? '2096-06-02' : '2096-06-01'); self::fail('Used source must remain financially unchanged.'); }
            catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
        }
    }

    public function testPhysicalLineRewriteAndCounterpartAssignmentsCannotInvalidateReceiptIds(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body($asset, $entry, $dimensions));
        $guard = $this->container->get(\MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard::class);
        try { $guard->assertEntriesMutable($this->supplier, [$entry]); self::fail('Physical rewrite would destroy source line identities even with identical amounts.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
        $statement = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='credit'"); $statement->execute([$entry]);
        try { $guard->assertLinesMutable($this->supplier, [(int) $statement->fetchColumn()]); self::fail('Full source snapshot also protects counterpart dimensions.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
    }

    public function testLateWriterFailureRollsBackPendingJournalAndVersionInsideCallerTransaction(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $body = $this->body($asset, $entry, $dimensions);
        $pdo = $this->db->pdo();
        $pdo->exec('SET @asset_reserve_fail=1');
        try {
            $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('The synthetic late failure must interrupt the writer.');
        } catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn());
        self::assertSame($body['row_version'], $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version']);
        $pdo->exec('SET @asset_reserve_fail=0');
        $this->assertReserve($body);
    }

    public function testCurrentMoneyChangeAfterOldSnapshotInvalidatesReceipt(): void { $this->concurrentMoney(false); }
    public function testSnapshotIsolationConflictAbortsWholeCallerTransaction(): void { $this->concurrentMoney(true); }

    private function concurrentMoney(bool $isolation): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $body = $this->body($asset, $entry, $dimensions);
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($isolation ? '1' : '0'));
        $pdo->beginTransaction();
        $pdo->query('SELECT amount FROM journal_entry_lines WHERE supplier_id=' . $this->supplier)->fetchAll();
        $this->other = Connection::withoutSharedTestConnection(static function (): \Psr\Container\ContainerInterface {
            $container = Bootstrap::buildContainer(); $container->get(Connection::class)->pdo(); return $container;
        });
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->get(Connection::class)->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->other->get(Connection::class)->pdo()->prepare("UPDATE journal_entry_lines SET amount='99999.00' WHERE entry_id=? AND supplier_id=?")->execute([$entry, $this->supplier]);
        try {
            $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
            self::fail('A stale preview must not become a source receipt.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertFalse($isolation); self::assertSame('asset_reserve_source_changed', $e->errorCode); self::assertTrue($pdo->inTransaction());
        } catch (\PDOException $e) {
            self::assertTrue($isolation); self::assertSame(1020, (int) $e->errorInfo[1]); self::assertFalse($pdo->inTransaction());
        }
        self::assertSame(1, (int) $this->other->get(Connection::class)->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testRealSourceReversalAndItsExactReversalAreVerified(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $posting = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class);
        $mirror = $posting->reverse($this->supplier, $entry, ['entry_date' => '2096-06-02']);
        try { $this->body($asset, $entry, $dimensions); self::fail('Reversed capitalization is not a source.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_source_changed', $e->errorCode); }
        $posting->reverse($this->supplier, $mirror, ['entry_date' => '2096-06-03']);
        $this->closeSource();
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE id=?')->execute([$this->kind]);
        $this->assertReserve($this->body($asset, $entry, $dimensions));
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET amount='99998.00' WHERE entry_id=?")->execute([$mirror]);
        try { $this->body($asset, $entry, $dimensions); self::fail('Rehashed or balanced corrupt mirror is not evidence.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_source_changed', $e->errorCode); }
    }

    public function testSourcePreviewWorksInActualReadOnlyTransaction(): void
    {
        [$asset] = $this->asset(true);
        $this->closeSource();
        $this->db->pdo()->commit();
        $this->committed = true;
        $this->db->pdo()->exec('START TRANSACTION READ ONLY');
        $source = ['asset_id' => $asset, 'mode' => 'capitalization', 'allocation_method' => 'capitalization_joint_proportional', 'continued_use_evidence' => 'Synthetic current preview'];
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $source, '150000.01');
        self::assertSame('150000.01', $preview['allocations'][0]['amount']);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM accounting_closing_steps WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testStepRevertCannotErasePostedDecisionOrHistoricalAssetClaims(): void
    {
        [$asset, $entry, $dimensions] = $this->asset(true);
        $this->closeSource();
        $service = $this->container->get(ClosingService::class);
        $posted = $service->createAssistedEntry($this->supplier, $this->period, 'provisions', $this->body($asset, $entry, $dimensions));
        $version = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        try { $service->revertStep($this->supplier, $this->period, 'provisions', $version); self::fail('Reverting a checklist step must not erase an active journal decision.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('legal_closing_revert_required', $e->errorCode); }
        $service->reverseAssistedEntry($this->supplier, $this->period, $posted['entry_id'], $version);
        $version = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->revertStep($this->supplier, $this->period, 'provisions', $version);
        $claims = (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository($this->db))->receipts($this->supplier);
        self::assertCount(1, $claims);
        self::assertSame($asset, $claims[0]['receipt']['asset_id']);
    }

    public function testAssociationCorrectionRequiresReversalAndPreservesOriginalReceipt(): void
    {
        [$asset] = $this->asset(false);
        [$otherAsset] = $this->asset(false);
        $oldLine = $this->openingLine('100000.00');
        $newLine = $this->openingLine('100000.00');
        $this->closeSource();
        $source = $this->association($asset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $posted = $this->postSource($source);
        $service = $this->container->get(ClosingService::class);
        $state = $service->assetReserveSourceState($this->supplier, $this->period, $asset);
        self::assertSame([$posted['entry_id']], array_column($state['blocking_entries'], 'entry_id'));
        $before = $state['current'];
        $replacement = $this->association($asset, [['line_id' => $newLine, 'contribution_cents' => 10000000]]);
        $request = ['reserve_source' => $replacement, 'previous_hash' => $before['source_hash'], 'reason' => 'Synthetic correction of mistaken title'];
        try { $service->assetReserveCorrectionPreview($this->supplier, $this->period, $request); self::fail('Live reserve must prevent correction.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_correction_used', $e->errorCode); }
        $version = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->reverseAssistedEntry($this->supplier, $this->period, $posted['entry_id'], $version);
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $this->period, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $result = $service->correctAssetReserveSource($this->supplier, $this->period, $request);
        self::assertSame(1, $result['revision_number']);
        $state = $service->assetReserveSourceState($this->supplier, $this->period, $asset);
        self::assertSame($preview['source'], $state['current']);
        self::assertSame($before, $state['revisions'][0]['previous_source_evidence']);
        $records = (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository($this->db))->receipts($this->supplier);
        self::assertSame($before, $records[0]['receipt']);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->findForUpdate($posted['entry_id'], $this->supplier);
        try { $this->container->get(\MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard::class)->assertReversal($this->supplier, (int) $journal['reversed_by']); self::fail('A superseded association cannot be resurrected by reversing its reversal.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }

        $this->postSource($replacement);
        $this->postSource($this->association($otherAsset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]));
        self::assertCount(3, (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository($this->db))->receipts($this->supplier));
        $request['row_version'] = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        try { $service->correctAssetReserveSource($this->supplier, $this->period, $request); self::fail('Stale predecessor must not append a revision.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_source_changed', $e->errorCode); }
    }

    public function testCorrectionFailureRollsBackRevisionAndVersionInsideCallerTransaction(): void
    {
        [$asset, $request] = $this->correctionFixture();
        $service = $this->container->get(ClosingService::class);
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $this->period, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $this->db->pdo()->exec('SET @asset_reserve_fail=1');
        try { $service->correctAssetReserveSource($this->supplier, $this->period, $request); self::fail('Late CAS failure must rollback the new association.'); }
        catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        self::assertTrue($this->db->pdo()->inTransaction());
        $state = $service->assetReserveSourceState($this->supplier, $this->period, $asset);
        self::assertSame([], $state['revisions']);
        self::assertSame($request['previous_hash'], $state['current']['source_hash']);
        self::assertSame($request['row_version'], $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version']);
        $this->db->pdo()->exec('SET @asset_reserve_fail=0');
        self::assertSame(1, $service->correctAssetReserveSource($this->supplier, $this->period, $request)['revision_number']);
    }

    public function testCorrectionPreviewIsReadOnlyAndOldSnapshotCannotReplaceNewRevision(): void
    {
        [$asset, $request] = $this->correctionFixture();
        $this->db->pdo()->commit(); $this->committed = true;
        $service = $this->container->get(ClosingService::class);
        $this->db->pdo()->exec('START TRANSACTION READ ONLY');
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $this->period, $request);
        self::assertSame([], $service->assetReserveSourceState($this->supplier, $this->period, $asset)['revisions']);
        $this->db->pdo()->rollBack();
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $this->other = Connection::withoutSharedTestConnection(static function (): \Psr\Container\ContainerInterface { $c = Bootstrap::buildContainer(); $c->get(Connection::class)->pdo(); return $c; });
        $otherPdo = $this->other->get(Connection::class)->pdo();
        $otherPdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $otherPdo->beginTransaction();
        self::assertSame([], $this->other->get(ClosingService::class)->assetReserveSourceState($this->supplier, $this->period, $asset)['revisions']);
        $request['row_version'] = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $this->period, $request);
        $request['row_version']++;
        try { $this->other->get(ClosingService::class)->correctAssetReserveSource($this->supplier, $this->period, $request); self::fail('Current predecessor must supersede old consistent snapshot.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_source_changed', $e->errorCode); }
        self::assertTrue($otherPdo->inTransaction());
    }

    public static function settledV1Mutations(): array { return ['amount' => ['amount'], 'dimensions' => ['dimensions'], 'usage resurrection' => ['resurrection'], 'usage rewrite' => ['rewrite'], 'usage axis insert' => ['assignment']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('settledV1Mutations')]
    public function testReleasedV1ClaimRequiresExactCreationAndUsageMirrors(string $mutation): void
    {
        [$asset] = $this->asset(false);
        [$otherAsset] = $this->asset(false);
        $old = $this->openingLine('100000.00'); $next = $this->openingLine('100000.00');
        $this->closeSource();
        $source = $this->association($asset, [['line_id' => $old, 'contribution_cents' => 10000000]]);
        $created = $this->postSource($source);
        $service = $this->container->get(ClosingService::class);
        $release = $this->sourceBody([], '30000.00');
        $release['rule_key'] = 'reserve.repairs.release';
        unset($release['legal_decision']['reserve_source']);
        $release['legal_decision']['source_entry_id'] = $created['entry_id'];
        $release['legal_decision']['allocations'] = [['amount' => '30000.00', 'dimensions' => $this->dimensions]];
        $used = $service->createAssistedEntry($this->supplier, $this->period, 'provisions', $release);
        foreach ([$used['entry_id'], $created['entry_id']] as $id) {
            $version = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
            $service->reverseAssistedEntry($this->supplier, $this->period, $id, $version);
        }
        $state = $service->assetReserveSourceState($this->supplier, $this->period, $asset);
        $request = ['reserve_source' => $this->association($asset, [['line_id' => $next, 'contribution_cents' => 10000000]]),
            'previous_hash' => $state['current']['source_hash'], 'reason' => 'Synthetic settled open-period correction'];
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $this->period, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $this->period, $request);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $mirror = (int) $journal->findForUpdate($used['entry_id'], $this->supplier)['reversed_by'];
        $candidate = $this->association($otherAsset, [['line_id' => $old, 'contribution_cents' => 10000000]]);
        self::assertSame(10000000, $service->assetReservePreview($this->supplier, $this->period, $candidate, '150000.00')['source']['gross_cents']);
        if (in_array($mutation, ['resurrection', 'rewrite', 'assignment'], true)) {
            $this->postSource($candidate);
            $code = null;
            try {
                if ($mutation === 'resurrection') $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $mirror, ['entry_date' => '2097-12-31', 'allow_closing_period' => true]);
                elseif ($mutation === 'rewrite') {
                    $entry = $journal->findForUpdate($mirror, $this->supplier);
                    $lines = (new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, $journal))->entryEvidence($this->supplier, $mirror)['lines'];
                    foreach ($lines as &$line) $line['amount'] = '29999.99';
                    unset($line);
                    $journal->replace($mirror, $entry, $lines);
                } else {
                    $this->db->pdo()->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES (?,'extra_axis','Synthetic extra axis','custom')")->execute([$this->supplier]);
                    $type = (int) $this->db->pdo()->lastInsertId();
                    $this->db->pdo()->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,'one','Synthetic value')")->execute([$type, $this->supplier]);
                    $value = (int) $this->db->pdo()->lastInsertId();
                    $line = $journal->findForUpdate($mirror, $this->supplier)['lines'][0]['id'];
                    $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->insertLineDimensions($this->supplier, $line, [$type => $value]);
                }
            } catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
            self::assertSame('asset_reserve_source_used', $code);
            self::assertNull($journal->findForUpdate($mirror, $this->supplier)['reversed_by']);
        } else {
            if ($mutation === 'amount') $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+0.01 WHERE entry_id=?')->execute([$mirror]);
            else $this->db->pdo()->prepare('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=?')->execute([$mirror]);
            $code = null;
            try { $service->assetReservePreview($this->supplier, $this->period, $candidate, '150000.00'); }
            catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
            self::assertSame('legal_closing_source_changed', $code);
        }
    }

    public static function reserveAssetHttpMutations(): array
    {
        $cases = [];
        foreach (['cs', 'en'] as $locale) {
            foreach (['update', 'delete', 'addImprovement', 'deleteImprovement'] as $mutation) $cases[$locale . ' ' . $mutation] = [$locale, $mutation];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reserveAssetHttpMutations')]
    public function testUsedAssetMutationReturnsHttpConflictWithoutChangingEvidence(string $locale, string $mutation): void
    {
        [$asset] = $this->asset(false);
        $improvement = $this->container->get(AssetService::class)->addImprovement($this->supplier, $asset, ['completed_on' => '2096-07-01', 'amount' => '10000.00']);
        $line = $this->openingLine('110000.00');
        $this->closeSource();
        $this->postSource($this->association($asset, [['line_id' => $line, 'contribution_cents' => 11000000]]));
        $snapshot = function (): array {
            $out = [];
            foreach (['assets', 'asset_improvements', 'journal_entries', 'journal_entry_lines', 'accounting_closing_steps', 'activity_log'] as $table) {
                $query = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=? ORDER BY id');
                $query->execute([$this->supplier]);
                $out[$table] = $query->fetchAll(\PDO::FETCH_ASSOC);
            }
            return $out;
        };
        $before = $snapshot();
        $body = match ($mutation) {
            'update' => ['asset_account_code' => '021'],
            'addImprovement' => ['completed_on' => '2097-06-01', 'amount' => '10000.00'],
            default => [],
        };
        $method = match ($mutation) { 'update' => 'PUT', 'addImprovement' => 'POST', default => 'DELETE' };
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, '/api/accounting/assets/' . $asset)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(2, 'Synthetic', 'staff', true, ['accounting' => 2, 'assets' => 2]))
            ->withParsedBody($body);
        $action = in_array($mutation, ['update', 'delete'], true)
            ? \MyInvoice\Action\Accounting\Assets\AssetAction::class
            : \MyInvoice\Action\Accounting\Assets\AssetLifecycleAction::class;
        $previousLocale = \MyInvoice\I18n\Locale::current();
        \MyInvoice\I18n\Locale::set($locale);
        try {
            $response = $this->container->get($action)->$mutation($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(),
                ['id' => $asset, 'impId' => $improvement['improvement']['id']]);
        } finally {
            \MyInvoice\I18n\Locale::set($previousLocale);
        }
        $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($before, $snapshot());
        self::assertSame(409, $response->getStatusCode(), json_encode($json, JSON_UNESCAPED_UNICODE));
        self::assertSame('asset_reserve_source_used', $json['error']['code']);
        self::assertSame($locale === 'cs'
            ? 'Zdroj majetku je navázán na doložené rozhodnutí o rezervě. Jeho finanční údaje a společné dimenze nelze přepsat.'
            : 'The asset source is linked to a documented reserve decision. Its financial data and joint dimensions cannot be changed.', $json['error']['message']);
    }

    private function correctionFixture(): array
    {
        [$asset] = $this->asset(false);
        $old = $this->openingLine('100000.00'); $next = $this->openingLine('100000.00');
        $this->closeSource();
        $posted = $this->postSource($this->association($asset, [['line_id' => $old, 'contribution_cents' => 10000000]]));
        $service = $this->container->get(ClosingService::class);
        $state = $service->assetReserveSourceState($this->supplier, $this->period, $asset);
        $version = (int) $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $service->reverseAssistedEntry($this->supplier, $this->period, $posted['entry_id'], $version);
        return [$asset, ['reserve_source' => $this->association($asset, [['line_id' => $next, 'contribution_cents' => 10000000]]),
            'previous_hash' => $state['current']['source_hash'], 'reason' => 'Synthetic corrected capitalization title']];
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

    private function body(int $asset, int $entry, array $dimensions): array
    {
        $version = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
        $source = ['asset_id' => $asset, 'mode' => 'capitalization', 'allocation_method' => 'capitalization_joint_proportional',
            'continued_use_evidence' => 'Synthetic repair retains the proven asset joint use'];
        $preview = $this->container->get(ClosingService::class)->assetReservePreview($this->supplier, $this->period, $source, '150000.00');
        $source['source_hash'] = $preview['source']['source_hash'];
        return ['row_version' => $version, 'rule_key' => 'reserve.repairs.create', 'amount' => '150000.00',
            'description' => 'Synthetic repair reserve', 'legal_decision' => ['reference' => 'SYN-REPAIR-BUDGET',
                'legal_basis' => '593/1992 §7', 'taxable_activity' => true, 'conditions_confirmed' => true,
                'evidence' => 'Synthetic repair budget, schedule, deposit assessment and explicit continued use of capitalization joint allocation',
                'reserve_source' => $source,
                'allocations' => [['amount' => '150000.00', 'dimensions' => $dimensions]]]];
    }

    private function assertReserve(array $body): void
    {
        $entry = $this->container->get(ClosingService::class)->createAssistedEntry($this->supplier, $this->period, 'provisions', $body);
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id');
        $stmt->execute([$entry['entry_id']]);
        self::assertSame([['account_code' => '556', 'side' => 'debit', 'amount' => '150000.00'],
            ['account_code' => '941', 'side' => 'credit', 'amount' => '150000.00']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=? AND d.dimension_value_id=?');
        $stmt->execute([$entry['entry_id'], $this->kind]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }
}
