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

final class AssetReserveClosedCorrectionTest extends TestCase
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
    private bool $shared = false;
    private ?Connection $other = null;

    /**
     * Výchozí uzavřený stav (celá uzávěrka 2097 a otevření 2098), případně i po první
     * opravě zdroje, se staví jednou za třídu ({@see SharedClassFixture}). Varianty s jinými
     * parametry (mezi nimi i test, který změny commituje) si fixture staví samy.
     */
    use \MyInvoice\Tests\Support\SharedClassFixture;

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
    }

    private static function deleteSupplier(\PDO $pdo, int $supplier): void
    {
        foreach (['activity_log', 'journal_entries', 'document_dimensions', 'document_dimension_splits', 'assets'] as $table) $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$supplier]);
        $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$supplier]);
    }

    private function ownSupplier(): void
    {
        $pdo = $this->db->pdo();
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
        if ($this->committed && !$this->shared) self::deleteSupplier($this->db->pdo(), $this->supplier);
        $this->db->pdo()->exec('SET @asset_closed_fail=NULL');
        if ($this->trigger !== null) $this->db->pdo()->exec('DROP TRIGGER ' . $this->trigger);
    }

    public function testProspectiveAssociationCorrectionPreservesClosedCreationAndUsedAmount(): void
    {
        extract($this->closedFixture());
        $before = $this->history();
        $error = null;
        $preview = null;
        try { $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $error = $e->errorCode; }
        self::assertSame($before, $this->history());
        $stmt = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE id IN (?,?) ORDER BY id');
        $stmt->execute([$created['entry_id'], $used['entry_id']]);
        self::assertSame([null, null], $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('closed', $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['status']);
        self::assertNull($error, 'A documented association-only correction needs a current-period path without reversing closed creation or used amount.');
        self::assertSame(12000000, $preview['remaining_cents']);
        self::assertSame(['941'], array_values(array_unique(array_column($preview['lines'], 'account_code'))));
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $corrected = $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertGreaterThan(0, $corrected['entry_id']);
        self::assertSame($corrected, $service->correctAssetReserveSource($this->supplier, $next, $request));
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        $this->period = $next;
        $release = $this->sourceBody([], '20000.00');
        $release['rule_key'] = 'reserve.repairs.release';
        unset($release['legal_decision']['reserve_source']);
        $release['legal_decision']['source_entry_id'] = $created['entry_id'];
        $newDimensions = $preview['source']['cells'][0]['dimensions'];
        $release['legal_decision']['allocations'] = [['amount' => '20000.00', 'dimensions' => $newDimensions]];
        $service->createAssistedEntry($this->supplier, $next, 'provisions', $release);
        self::assertSame(10000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
    }

    public function testPinnedPriorUsageAndCarryCannotBeReversedAfterCorrection(): void
    {
        extract($this->correctedFixture());
        $result = $corrected;
        $guard = $this->container->get(\MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard::class);
        foreach ([$used['entry_id'], $closed['entry_id'], $result['entry_id']] as $id) {
            try { $guard->assertReversal($this->supplier, $id); self::fail('Pinned use/carry/transfer reversal must not resurrect consumed capacity.'); }
            catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
        }
    }

    public function testReleasePreviewKeepsOriginalOriginAndCurrentHeadCells(): void
    {
        extract($this->correctedFixture());
        $sources = $repo->reserveSources($this->supplier, '2098-12-31');
        self::assertCount(1, $sources);
        self::assertSame($created['entry_id'], $sources[0]['entry_id']);
        self::assertSame(12000000, array_sum(array_column($sources[0]['cells'], 'available_cents')));
        self::assertSame($preview['source']['cells'][0]['dimensions'], $sources[0]['cells'][0]['dimensions']);
    }

    public function testSecondCorrectionAfterFullUsageHasNoZeroJournalAndKeepsOriginalOrigin(): void
    {
        extract($this->correctedFixture());
        $first = $corrected;
        $this->period = $next;
        $release = $this->sourceBody([], '120000.00');
        $release['rule_key'] = 'reserve.repairs.release';
        unset($release['legal_decision']['reserve_source']);
        $release['legal_decision']['source_entry_id'] = $created['entry_id'];
        $release['legal_decision']['allocations'] = [['amount' => '120000.00', 'dimensions' => $preview['source']['cells'][0]['dimensions']]];
        $lastUse = $service->createAssistedEntry($this->supplier, $next, 'provisions', $release);
        $request['previous_hash'] = $first['source_hash'];
        $request['reserve_source'] = $this->association($asset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $request['reason'] = 'Synthetic further documented association correction, no restoration of used reserve';
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        self::assertSame(0, $preview['remaining_cents']);
        self::assertSame([], $preview['lines']);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->sourceBody([])['row_version'];
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');
        $stmt->execute([$this->supplier]); $beforeCount = $stmt->fetchColumn();
        $second = $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertNull($second['entry_id']);
        self::assertSame(2, $second['revision_number']);
        self::assertSame($second, $service->correctAssetReserveSource($this->supplier, $next, $request));
        $stmt->execute([$this->supplier]); self::assertSame($beforeCount, $stmt->fetchColumn());
        self::assertSame(0, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        try { $this->container->get(\MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard::class)->assertReversal($this->supplier, $lastUse['entry_id']); self::fail('A zero balance alone does not release consumed source history.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
    }

    public function testLateFailureRollsBackTransferRevisionAndClaimInsideCallerTransaction(): void
    {
        extract($this->closedFixture());
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $before = $this->history();
        $this->db->pdo()->exec('SET @asset_closed_fail=1');
        try { $service->correctAssetReserveSource($this->supplier, $next, $request); self::fail('Late revision CAS failure must abort transfer and pending atomically.'); }
        catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
        finally { $this->db->pdo()->exec('SET @asset_closed_fail=0'); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->history());
        self::assertSame($request['previous_hash'], $service->assetReserveSourceState($this->supplier, $next, $asset)['current']['source_hash']);
        self::assertGreaterThan(0, $service->correctAssetReserveSource($this->supplier, $next, $request)['entry_id']);
    }

    public function testLatePurposeEvidenceRefusesBeforeWriting(): void
    {
        extract($this->closedFixture());
        $request['original_purpose']['document_date'] = '2098-01-01';
        $before = $this->history();
        try { $service->assetReserveCorrectionPreview($this->supplier, $next, $request); self::fail('A new repair purpose cannot sanitize past reserve formation.'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('asset_reserve_source_required', $e->errorCode); }
        self::assertSame($before, $this->history());
    }

    public function testCorrectionOfAnotherReserveSharingJointCellDoesNotHideItsRemainingLiability(): void
    {
        extract($this->closedFixture(true));
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertSame(15000000, array_sum($repo->reserveRemaining($this->supplier, $otherCreated['entry_id'], '2098-12-31')));
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
    }

    public function testExactPinnedRewriteKeepsPhysicalIdentityAndRejectsFinancialChange(): void
    {
        extract($this->correctedFixture());
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $entry = $journal->findForUpdate($corrected['entry_id'], $this->supplier);
        $lines = $repo->entryEvidence($this->supplier, $corrected['entry_id'])['lines'];
        $before = $this->history();
        $journal->replace($corrected['entry_id'], $entry, $lines);
        self::assertSame($before, $this->history(), 'An exact retry must retain pinned physical line identities and posted timestamp.');
        foreach ($lines as &$line) $line['amount'] = '119999.99';
        unset($line);
        try { $journal->replace($corrected['entry_id'], $entry, $lines); self::fail('Pinned transfer cannot change its financial facts.'); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('asset_reserve_source_used', $e->errorCode); }
        self::assertSame($before, $this->history());
    }

    public function testActualAssignmentWriterCannotChangePinnedSourceTupleButExactNoopPasses(): void
    {
        extract($this->correctedFixture());
        $assignments = $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class);
        self::assertFalse($assignments->replaceLineDimensions($this->supplier, $oldLine, $oldDimensions));
        $before = $this->history();
        $code = null;
        try { $assignments->replaceLineDimensions($this->supplier, $oldLine, $preview['source']['cells'][0]['dimensions']); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->history());
    }

    public function testActualAssetDeleteCannotRemoveAssociatedCardAfterClosedCorrection(): void
    {
        extract($this->correctedFixture());
        $code = null;
        try { $this->container->get(AssetService::class)->delete($this->supplier, $asset); }
        catch (\MyInvoice\Service\Accounting\Assets\AssetException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertNotNull($this->container->get(AssetService::class)->get($this->supplier, $asset));
    }

    public function testHistoricalAssociationClaimCannotBeReusedByAnotherCardAfterCorrection(): void
    {
        extract($this->closedFixture(false, true));
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $next, $request);
        $candidate = $this->association($unusedAsset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $before = $this->history();
        $code = null;
        try { $service->assetReservePreview($this->supplier, $next, $candidate, '500.00'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_claimed', $code, 'A prospective correction must not silently release immutable historical association capacity to another asset.');
        self::assertSame($before, $this->history());
    }

    public static function snapshots(): array { return ['without snapshot' => [false], 'old RR snapshot' => [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshots')]
    public function testAccountingContextIsLockedBeforeBlockedSourceDiscovery(bool $snapshot): void
    {
        extract($this->closedFixture());
        $a = $this->db->pdo();
        $a->commit();
        $this->committed = true;
        $this->other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $b = $this->other->pdo();
        self::assertNotSame($a->query('SELECT CONNECTION_ID()')->fetchColumn(), $b->query('SELECT CONNECTION_ID()')->fetchColumn());
        $a->exec('SET SESSION innodb_lock_wait_timeout=1');
        $a->beginTransaction();
        if ($snapshot) $a->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        $b->beginTransaction();
        $b->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE')->execute([$this->supplier, $created['entry_id']]);
        try {
            try { $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $created['entry_id'], ['entry_date' => '2098-12-31', 'allow_closing_period' => true]); self::fail('Source row is held by the other session.'); }
            catch (\PDOException $e) { self::assertSame(1205, (int) $e->errorInfo[1]); }
            self::assertTrue($a->inTransaction());
            foreach (['supplier_accounting_frameworks' => 'supplier_id', 'supplier' => 'id'] as $table => $key) {
                $error = null;
                try { $b->query('SELECT id FROM ' . $table . ' WHERE ' . $key . '=' . $this->supplier . ' FOR UPDATE NOWAIT')->fetchAll(); }
                catch (\PDOException $e) { $error = (int) $e->errorInfo[1]; }
                self::assertSame(1205, $error, 'Current history and supplier must already be locked before the writer waits for its source.');
            }
        } finally {
            if ($a->inTransaction()) $a->rollBack();
            if ($b->inTransaction()) $b->rollBack();
            $a->exec('SET SESSION innodb_lock_wait_timeout=50');
        }
    }

    public function testCurrentClaimCreatedAfterCallerSnapshotPreventsCompetingCorrection(): void
    {
        extract($this->closedFixture(false, true));
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $future = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2099, '2099-01-01', '2099-12-31');
        $this->container->get(AccountingPeriodRepository::class)->setStatus($future, $this->supplier, 'closing');
        $a = $this->db->pdo();
        $a->commit(); $this->committed = true;
        $originalIsolation = (int) $a->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $a->exec('SET SESSION innodb_snapshot_isolation=0');
        $a->beginTransaction();
        $a->query('SELECT COUNT(*) FROM accounting_closing_steps')->fetchColumn();
        $other = Connection::withoutSharedTestConnection(static function () {
            $c = Bootstrap::buildContainer(); $c->get(Connection::class); return $c;
        });
        $this->other = $other->get(Connection::class);
        try {
            $candidate = $this->association($unusedAsset, [['line_id' => $newLine, 'contribution_cents' => 10000000]]);
            $otherService = $other->get(ClosingService::class);
            $otherPreview = $otherService->assetReservePreview($this->supplier, $future, $candidate, '500.00');
            $candidate['source_hash'] = $otherPreview['source']['source_hash'];
            $body = $this->sourceBody($candidate, '500.00');
            $body['row_version'] = $other->get(AccountingPeriodRepository::class)->findById($this->supplier, $future)['row_version'];
            $otherService->createAssistedEntry($this->supplier, $future, 'provisions', $body);
            $request['row_version'] = $other->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
            $query = $this->other->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions'");
            $query->execute([$this->supplier, $next]); $before = $query->fetchColumn();
            $code = null;
            try { $service->correctAssetReserveSource($this->supplier, $next, $request); }
            catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
            self::assertSame('asset_reserve_source_claimed', $code);
            self::assertTrue($a->inTransaction());
            $a->rollBack();
            $query->execute([$this->supplier, $next]); self::assertSame($before, $query->fetchColumn());
            self::assertSame($state['current']['source_hash'], $otherService->assetReserveSourceState($this->supplier, $next, $asset)['current']['source_hash']);
        } finally {
            if ($a->inTransaction()) $a->rollBack();
            $a->exec('SET SESSION innodb_snapshot_isolation=' . $originalIsolation);
        }
    }

    public static function assetChanges(): array { return ['asset account' => ['account'], 'backdated improvement' => ['improvement']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('assetChanges')]
    public function testActualAssetFinancialChangeCannotInvalidateHistoricalSource(string $change): void
    {
        extract($this->correctedFixture());
        $assets = $this->container->get(AssetService::class);
        $before = $assets->get($this->supplier, $asset);
        $code = null;
        try {
            if ($change === 'account') $assets->update($this->supplier, $asset, ['asset_account_code' => '029']);
            else $assets->addImprovement($this->supplier, $asset, ['completed_on' => '2096-08-01', 'amount' => '1000.00', 'description' => 'Synthetic late source change']);
        } catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Assets\AssetException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $assets->get($this->supplier, $asset));
    }

    public function testPhysicalCarryDeletionCannotErasePinnedOpening(): void
    {
        extract($this->correctedFixture());
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND period_id=? AND source_type='opening'");
        $stmt->execute([$this->supplier, $next]); $opening = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $opening);
        $before = $this->history();
        $code = null;
        try { $this->container->get(\MyInvoice\Repository\ClosingRepository::class)->deleteOpeningEntry($this->supplier, $opening); }
        catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Closing\ClosingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->history());
    }

    public function testReadOnlyPreviewAndSecondNonzeroCorrectionKeepOriginAndAllReceipts(): void
    {
        extract($this->closedFixture());
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        $before = $this->history();
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        self::assertSame(12000000, $preview['remaining_cents']);
        self::assertSame($before, $this->history());
        $pdo->rollBack();
        $pdo->beginTransaction();
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $first = $service->correctAssetReserveSource($this->supplier, $next, $request);
        $request['previous_hash'] = $first['source_hash'];
        $request['reserve_source'] = $this->association($asset, [['line_id' => $oldLine, 'contribution_cents' => 10000000]]);
        $request['reason'] = 'Synthetic further evidenced association correction';
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $second = $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertGreaterThan(0, $second['entry_id']);
        self::assertSame(2, $second['revision_number']);
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        $state = $service->assetReserveSourceState($this->supplier, $next, $asset);
        self::assertCount(2, $state['revisions']);
        self::assertSame($first['revision_hash'], $state['revisions'][0]['revision_hash']);
        $sources = $repo->reserveSources($this->supplier, '2098-12-31');
        self::assertSame($created['entry_id'], $sources[0]['entry_id']);
        self::assertSame($oldDimensions, $sources[0]['cells'][0]['dimensions']);
    }

    public function testReversalOfHistoricalUsageReversalCannotChangePinnedRemaining(): void
    {
        extract($this->closedFixture(false, false, true));
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $next, $request);
        self::assertSame(15000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        $before = $this->history();
        $code = null;
        try { $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $usageReversal, ['entry_date' => '2098-12-31', 'allow_closing_period' => true]); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->history());
    }

    public function testCorrectionPreservesTwoCorrelatedJointTuplesAndExactWeightedCents(): void
    {
        extract($this->closedFixture(false, false, false, true));
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $result = $service->correctAssetReserveSource($this->supplier, $next, $request);
        $lines = $repo->entryEvidence($this->supplier, $result['entry_id'])['lines'];
        $credits = array_values(array_filter($lines, static fn (array $line): bool => $line['side'] === 'credit'));
        self::assertCount(2, $credits);
        $actual = [];
        foreach ($credits as $line) { $dims = $line['dimensions']; ksort($dims); $actual[json_encode($dims, JSON_THROW_ON_ERROR)] = $line['amount']; }
        foreach ($jointTuples as &$tuple) ksort($tuple);
        unset($tuple);
        $expected = [json_encode($jointTuples[0], JSON_THROW_ON_ERROR) => '40000.00', json_encode($jointTuples[1], JSON_THROW_ON_ERROR) => '80000.01'];
        ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        self::assertSame(12000001, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
        self::assertSame(10000000, $preview['source']['gross_cents'], 'Capitalization is a weight basis, not the repair reserve budget.');
    }

    public function testActualOpeningRerunAfterAbortKeepsPinnedPhysicalCarry(): void
    {
        extract($this->closedFixture());
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $periods = $this->container->get(AccountingPeriodRepository::class);
        $request['row_version'] = $periods->findById($this->supplier, $next)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $next, $request);
        $service->abort($this->supplier, $next, $periods->findById($this->supplier, $next)['row_version']);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $opening = $journal->findActiveBySourceForUpdate($this->supplier, 'opening', $next);
        $before = $repo->entryEvidence($this->supplier, $opening['id']);
        $service->openNext($this->supplier, $this->period, $periods->findById($this->supplier, $this->period)['row_version']);
        self::assertSame($before, $repo->entryEvidence($this->supplier, $opening['id']));
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
    }

    public function testMarkPostedRetryPreservesPinnedPostingTimestamp(): void
    {
        extract($this->closedFixture());
        $this->db->pdo()->prepare("UPDATE journal_entries SET posted_at='2097-12-31 12:00:00' WHERE id=? AND supplier_id=?")->execute([$created['entry_id'], $this->supplier]);
        $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
        $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
        $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
        $service->correctAssetReserveSource($this->supplier, $next, $request);
        $before = $this->history();
        self::assertFalse($this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->markPosted($created['entry_id'], $this->supplier, null));
        self::assertSame($before, $this->history());
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
    }

    public static function frameworks(): array { return ['500 custom' => ['cz_500'], '504 joint' => ['cz_504']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworks')]
    public function testNewJournalAssignmentsDoNotLockOlderPeriodWithoutAssetClaims(string $framework): void
    {
        $this->ownSupplier();
        $a = $this->db->pdo();
        $dims = $this->dimensions;
        if ($framework === 'cz_500') {
            $a->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2097-01-01','cz_500')")->execute([$this->supplier]);
            $a->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES (?,'plain','Synthetic plain','custom')")->execute([$this->supplier]);
            $type = (int) $a->lastInsertId();
            $a->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,'one','Synthetic value')")->execute([$type, $this->supplier]);
            $dims = [$type => (int) $a->lastInsertId()];
        }
        $accounts = $a->prepare("SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('022','901')");
        $accounts->execute([$this->supplier]);
        $accounts = array_column($accounts->fetchAll(\PDO::FETCH_ASSOC), 'id', 'account_code');
        $a->commit();
        $this->committed = true;
        $this->other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $b = $this->other->pdo();
        $b->beginTransaction();
        $b->prepare('SELECT id FROM accounting_periods WHERE id=? FOR UPDATE')->execute([$this->sourcePeriod]);
        $a->exec('SET SESSION innodb_lock_wait_timeout=1');
        $a->beginTransaction();
        try {
            $id = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->insert(
                ['supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2097-05-01', 'source_type' => 'manual', 'posted_at' => '2097-05-01 12:00:00'],
                [['account_id' => $accounts['022'], 'side' => 'debit', 'amount' => '49.99', 'dimensions' => $dims],
                 ['account_id' => $accounts['901'], 'side' => 'credit', 'amount' => '49.99', 'dimensions' => $dims]]);
            self::assertGreaterThan(0, $id);
            $lines = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->linesForEntry($id, $this->supplier);
            self::assertSame([49.99, 49.99], array_column($lines, 'amount'));
            $actual = $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->lineDimensions($this->supplier, array_column($lines, 'id'), true);
            foreach ($actual as $row) self::assertEquals($dims, $row);
            self::assertCount(2, $actual);
            $failure = null;
            try { $b->query('SELECT id FROM accounting_periods WHERE id=' . $this->period . ' FOR UPDATE NOWAIT')->fetchAll(); }
            catch (\PDOException $e) { $failure = (int) $e->errorInfo[1]; }
            self::assertSame(1205, $failure);
        } finally {
            if ($a->inTransaction()) $a->rollBack();
            if ($b->inTransaction()) $b->rollBack();
            $a->exec('SET SESSION innodb_lock_wait_timeout=50');
        }
    }

    public static function directMutations(): array { return ['partial' => ['partial'], 'detach' => ['detach'], 'reversal marker' => ['marker']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('directMutations')]
    public function testDirectJournalMutationCannotInvalidatePinnedEntry(string $mutation): void
    {
        extract($this->correctedFixture());
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $entry = $journal->findForUpdate($corrected['entry_id'], $this->supplier);
        $lines = $repo->entryEvidence($this->supplier, $corrected['entry_id'])['lines'];
        foreach ($lines as &$line) { $line['amount'] = '119999.99'; $line['dimensions'] = $preview['source']['cells'][0]['dimensions']; }
        unset($line);
        $before = $this->history();
        $code = null;
        try {
            match ($mutation) {
                'partial' => $journal->replaceSomeLines($entry['id'], $this->supplier, $entry['row_version'], array_column($entry['lines'], 'id'), $lines),
                'detach' => $journal->detachSource($entry['id'], $this->supplier),
                'marker' => $journal->setReversedBy($entry['id'], $this->supplier, $used['entry_id']),
            };
        } catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->history());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshots')]
    public function testAssignmentContextPrecedesCurrentSourceLock(bool $snapshot): void
    {
        extract($this->closedFixture());
        $a = $this->db->pdo();
        $a->commit();
        $this->committed = true;
        $a->beginTransaction();
        $a->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE')->execute([$this->supplier, $oldLine]);
        $root = dirname(__DIR__, 4);
        $resultFile = tempnam(sys_get_temp_dir(), 'asset-lock-');
        $process = proc_open([PHP_BINARY, $root . '/api/tests/Fixtures/asset-assignment-lock-worker.php', $root,
            (string) $this->supplier, (string) $oldLine, json_encode($oldDimensions, JSON_THROW_ON_ERROR), $snapshot ? '1' : '0', $resultFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $blocked = false;
            $deadline = microtime(true) + 12;
            while (microtime(true) < $deadline) {
                $state = json_decode((string) file_get_contents($resultFile), true);
                if (isset($state['connection_id'])) {
                    $query = $a->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');
                    $query->execute([$state['connection_id']]);
                    $sql = (string) $query->fetchColumn();
                    if (str_starts_with($sql, \MyInvoice\Infrastructure\Database\SubqueryCache::OFF)) $sql = substr($sql, strlen(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF));
                    if (str_starts_with($sql, 'SELECT l.id, e.id AS entry_id, e.period_id,')
                        || str_starts_with($sql, 'SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id=')
                        || str_starts_with($sql, 'SELECT DISTINCT e.id,(' . \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::entryReferenceSql() . ') AS settlement_used FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=')
                        // Strážci vyhodnocují odkazy jednou za zápis: nejdřív zápisy řádků, potom odkazy zápisů.
                        || str_starts_with($sql, 'SELECT DISTINCT e.id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=')
                        || str_starts_with($sql, 'SELECT e.id,(' . \MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::entryReferenceSql() . ') AS settlement_used FROM journal_entries e WHERE e.supplier_id=')
                        || str_starts_with($sql, 'SELECT e.id, (')) { $blocked = true; break; }
                }
                usleep(20000);
            }
            self::assertTrue($blocked, 'Worker must be waiting in the exact current source query.');
            foreach (['supplier_accounting_frameworks' => 'supplier_id', 'supplier' => 'id'] as $table => $key) {
                $error = null;
                try { $a->query('SELECT id FROM ' . $table . ' WHERE ' . $key . '=' . $this->supplier . ' FOR UPDATE NOWAIT')->fetchAll(); }
                catch (\PDOException $e) { $error = (int) $e->errorInfo[1]; }
                self::assertSame(1205, $error);
            }
        } finally {
            if ($a->inTransaction()) $a->rollBack();
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            $state = json_decode((string) file_get_contents($resultFile), true);
            unlink($resultFile);
        }
        self::assertSame(0, $exit, $output . $errors);
        self::assertSame('asset_reserve_source_used', $state['result'] ?? null);
    }

    public function testAssignmentInsertCannotAddAnAxisToPinnedPhysicalLine(): void
    {
        extract($this->correctedFixture());
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES (?,'extra_axis','Synthetic extra axis','custom')")->execute([$this->supplier]);
        $type = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,'one','Synthetic value')")->execute([$type, $this->supplier]);
        $value = (int) $pdo->lastInsertId();
        $before = $this->history(); $code = null;
        try { $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->insertLineDimensions($this->supplier, $oldLine, [$type => $value]); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code);
        self::assertSame($before, $this->history());
    }

    public static function pinnedReversalTargets(): array
    {
        return array_combine(['creation', 'used', 'close', 'opening', 'transfer', 'old_source', 'current_source'],
            array_map(static fn (string $target): array => [$target], ['creation', 'used', 'close', 'opening', 'transfer', 'old_source', 'current_source']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pinnedReversalTargets')]
    public function testActualPostingCannotReversePinnedLineage(string $target): void
    {
        extract($this->correctedFixture());
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND period_id=? AND source_type='opening'");
        $stmt->execute([$this->supplier, $next]); $opening = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $opening);
        $stmt = $pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE id=? AND supplier_id=?');
        $stmt->execute([$oldLine, $this->supplier]); $oldEntry = (int) $stmt->fetchColumn();
        $stmt->execute([$newLine, $this->supplier]); $newEntry = (int) $stmt->fetchColumn();
        $ids = ['creation' => $created['entry_id'], 'used' => $used['entry_id'], 'close' => $closed['entry_id'],
            'opening' => $opening, 'transfer' => $corrected['entry_id'], 'old_source' => $oldEntry, 'current_source' => $newEntry];
        $before = $this->history();
        $code = null;
        try { $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplier, $ids[$target], ['entry_date' => '2098-12-31', 'allow_closing_period' => true]); }
        catch (\MyInvoice\Service\Accounting\PostingException $e) { $code = $e->errorCode; }
        self::assertSame('asset_reserve_source_used', $code, 'Pinned source/use/carry may not be cancelled through the actual financial writer.');
        self::assertSame($before, $this->history());
        self::assertSame(12000000, array_sum($repo->reserveRemaining($this->supplier, $created['entry_id'], '2098-12-31')));
    }

    private function closedFixture(bool $anotherReserve = false, bool $unusedCard = false, bool $reversedUse = false, bool $jointSource = false): array
    {
        if ($anotherReserve || $unusedCard || $reversedUse || $jointSource) {
            $this->ownSupplier();
            return $this->buildClosedFixture($anotherReserve, $unusedCard, $reversedUse, $jointSource);
        }
        return $this->sharedState('closed', fn (): array => $this->buildClosedFixture());
    }

    /** Výchozí uzavřený stav po první (úspěšné) opravě zdroje, z něhož vychází většina testů. */
    private function correctedFixture(): array
    {
        return $this->sharedState('corrected', function (): array {
            extract($this->buildClosedFixture());
            $preview = $service->assetReserveCorrectionPreview($this->supplier, $next, $request);
            $request['reserve_source']['source_hash'] = $preview['source']['source_hash'];
            $request['row_version'] = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplier, $next)['row_version'];
            $corrected = $service->correctAssetReserveSource($this->supplier, $next, $request);
            return get_defined_vars();
        });
    }

    /** @param callable():array<string,mixed> $build */
    private function sharedState(string $key, callable $build): array
    {
        $shared = $this->sharedFixture($this->db->pdo(), $key, function () use ($build): array {
            $this->ownSupplier();
            $data = $build();
            unset($data['service'], $data['repo'], $data['this']);
            return [$this->supplier, ['data' => $data, 'props' => ['supplier' => $this->supplier, 'sourcePeriod' => $this->sourcePeriod,
                'period' => $this->period, 'kind' => $this->kind, 'dimensions' => $this->dimensions, 'assetNumber' => $this->assetNumber]]];
        });
        $this->shared = true;
        foreach ($shared['props'] as $property => $value) $this->{$property} = $value;
        return $shared['data'] + ['service' => $this->container->get(ClosingService::class),
            'repo' => new \MyInvoice\Service\Accounting\Closing\LegalClosing504Repository($this->db, $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class))];
    }

    private function buildClosedFixture(bool $anotherReserve = false, bool $unusedCard = false, bool $reversedUse = false, bool $jointSource = false): array
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
        $service->runFxRevaluation($this->supplier, $this->period, [], $this->sourceBody([])['row_version'], [], ['snapshot_hash' => $fx['snapshot_hash']]);
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

}
