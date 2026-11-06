<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\Fx504Service;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;

final class Fx504RevaluationTest extends TestCase
{
    use \MyInvoice\Tests\Support\SharedClassFixture;

    /** Testy, které výchozí stav commitují (druhá session, READ ONLY transakce): staví si vlastní. U ověření důkazu jen varianta s commitem. */
    private const OWN_FIXTURE = ['testCurrentPreviewSeesCommittedPostingAndCancellationAfterCallerSnapshot', 'testPublicPreviewRunsInsideNativeReadOnlyTransaction',
        'testNativeCaseOriginLocksNewMembershipBeyondCallerSnapshot', 'testCasePostPreviewRunsInsideReadOnlyTransaction', 'testFxProofRejectsChangedAuthority'];

    private Connection $db;
    private int $supplier;
    private int $period;
    private array $dims;
    private Fx504Service $service;
    private bool $committed = false;
    private ?Connection $other = null;
    private int $originalIsolation;
    private mixed $previousRate;


    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $this->previousRate = $pdo->query("SELECT rate FROM exchange_rates WHERE rate_date='2098-12-31' AND currency_code='EUR'")->fetchColumn();
        if (in_array($this->name(), self::OWN_FIXTURE, true) && ($this->name() !== 'testFxProofRejectsChangedAuthority' || $this->providedData() === ['transaction'])) {
            $pdo->beginTransaction();
            $this->buildFixture($container);
        } else {
            $data = $this->sharedFixture($pdo, 'base', function () use ($container): array {
                $this->buildFixture($container);
                return [$this->supplier, ['supplier' => $this->supplier, 'period' => $this->period, 'dims' => $this->dims]];
            });
            ['supplier' => $this->supplier, 'period' => $this->period, 'dims' => $this->dims] = $data;
        }
        // Kurz je globální číselník: vždy jen v transakci testu (commitující testy ho v tearDown vrací).
        $pdo->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=25")->execute();
        $this->service = $container->get(Fx504Service::class);
    }

    private function buildFixture(\Psr\Container\ContainerInterface $container): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic joint FX','Test 1','Test','10000','fx504@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        foreach (['545' => 'expense', '645' => 'revenue'] as $code => $type) {
            $pdo->prepare('INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,?,1)')->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2098-01-01','cz_504')")->execute([$this->supplier]);
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2098-01-01');
        $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$catalog['activity_kind']['id'], $this->supplier, 'synthetic_kind', 'Synthetic kind']);
        $this->dims = [$catalog['activity']['id'] => $catalog['activity']['values']['main'], $catalog['activity_kind']['id'] => (int) $pdo->lastInsertId(), $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
        $container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24.000000', 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ['account_code' => '645', 'side' => 'credit', 'amount' => '24000.00', 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
        ], ['entry_date' => '2098-06-01', 'posted' => true]);
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $this->other?->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'invoices', 'clients', 'currencies'] as $table) $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
            if ($this->previousRate === false) $pdo->exec("DELETE FROM exchange_rates WHERE rate_date='2098-12-31' AND currency_code='EUR'");
            else $pdo->prepare("UPDATE exchange_rates SET rate=? WHERE rate_date='2098-12-31' AND currency_code='EUR'")->execute([$this->previousRate]);
        }
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['manual'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['bank'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['closing'])]
    public function testUnlinkedForeignNullNominalBlocksPreviewAndPosting(string $source): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE journal_entries SET source_type=? WHERE supplier_id=?')->execute([$source, $this->supplier]);
        $pdo->prepare("UPDATE journal_entry_lines SET amount_foreign=NULL WHERE supplier_id=? AND currency_code='EUR'")->execute([$this->supplier]);
        $preview = $this->service->preview($this->supplier, $this->period);
        self::assertFalse($preview['complete'], 'Unproven EUR 24000 CZK balance was silently omitted.');
        self::assertSame(['case_nominal_unproven'], array_column($preview['case_findings'], 'code'));
        self::assertGreaterThan(0, $preview['case_findings'][0]['line_id']);
        $before = $this->state();
        try {
            $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => []], 1);
            self::fail('Unproven foreign nominal accepted.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('fx_case_source_changed', $e->errorCode);
        }
        self::assertSame($before, $this->state());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['CZK', null, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([null, null, false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['EUR', '0.00', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['EUR', '0.00', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['EUR', '1000.00', true])]
    public function testDomesticAndProvenZeroOrCreditNominalsRemainValid(?string $currency, ?string $nominal, bool $credit): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entry_lines SET currency_code=?,amount_foreign=? WHERE supplier_id=? AND currency_code='EUR'")->execute([$currency, $nominal, $this->supplier]);
        if ($credit) $pdo->prepare("UPDATE journal_entry_lines SET side=IF(side='credit','debit','credit') WHERE supplier_id=?")->execute([$this->supplier]);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        self::assertSame([], $preview['case_findings']);
        self::assertTrue($preview['complete']);
        if ($currency !== 'EUR') {
            self::assertSame([], $preview['positions']);
        } else {
            self::assertSame((int) bcmul($nominal, $credit ? '-100' : '100', 0), array_sum(array_column($preview['positions'], 'foreign_cents')));
            self::assertSame($credit ? -2400000 : 2400000, array_sum(array_column($preview['positions'], 'czk_cents')));
        }
    }

    public function testPublicPreviewDoesNotSilentlyOmitNativeManualMonetaryBalance(): void
    {
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->supplier, $this->period, [], null, $this->caseEvidence());
        self::assertSame(1000.0, (float) $preview['totals']['gain']);
        self::assertSame($this->dims, $preview['positions'][0]['dimensions']);
    }

    public function testTypedWriterPreservesRetiredCurrentSourceTupleAndIsIdempotent(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, array_values($this->dims)[1]]);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
        $audit = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND action='accounting.fx_revalued' ORDER BY id DESC LIMIT 1");
        $audit->execute([$this->supplier]);
        $auditPayload = $audit->fetchColumn();
        self::assertNotFalse($auditPayload);
        self::assertSame($preview['snapshot_hash'], json_decode($auditPayload, true, 512, JSON_THROW_ON_ERROR)['snapshot_hash']);
        self::assertTrue($this->db->pdo()->inTransaction());
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $lines = $journal->linesForEntry($result['entry_ids']['saldo'], $this->supplier, true);
        self::assertCount(2, $lines);
        self::assertSame(1000.0, $lines[0]['amount']);
        $assignments = new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);
        $dims = $assignments->lineDimensions($this->supplier, array_column($lines, 'id'), true);
        self::assertSame([$this->dims, $this->dims], array_values($dims));
        $again = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        self::assertSame('1000.00', $again['totals']['gain']);
        self::assertSame($preview['snapshot_hash'], $again['snapshot_hash']);
        $rerun = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $again['snapshot_hash'], 'case_decisions' => $again['case_decisions']], 2);
        self::assertSame($result['entry_ids']['saldo'], $rerun['entry_ids']['saldo']);
        self::assertSame(['accounting.posted', 'accounting.reposted'], $this->entryAudit($result['entry_ids']['saldo']));
    }

    /** § 33a: auditní stopa patří i k samotnému zápisu, ne jen k období (invariant I28). */
    private function entryAudit(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT action FROM activity_log WHERE supplier_id=? AND entity_type='journal_entry' AND entity_id=? ORDER BY id");
        $stmt->execute([$this->supplier, $entryId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testCancellationPairDoesNotCreatePhantomRevaluation(): void
    {
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
        $reverted = $this->service->revert($this->supplier, $this->period, 2);
        self::assertGreaterThan(0, $reverted['reversal_entry_ids']['saldo']);
        $after = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        self::assertSame($preview['snapshot_hash'], $after['snapshot_hash']);
        self::assertSame('1000.00', $after['totals']['gain']);
        $again = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $after['snapshot_hash'], 'case_decisions' => $after['case_decisions']], 3);
        self::assertNotSame($result['entry_ids']['saldo'], $again['entry_ids']['saldo']);
        self::assertSame('1000.00', $again['totals']['gain']);
    }

    public function testNextYearMirrorKeepsRetiredTupleAndCanBeCancelledAndRepeated(): void
    {
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
        $container = Bootstrap::buildContainer();
        $target = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2099, '2099-01-01', '2099-12-31');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, array_values($this->dims)[1]]);
        $pdo->prepare("UPDATE accounting_closing_steps SET done_at='2098-12-31 18:30:00' WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation'")->execute([$this->supplier, $this->period]);
        $reversal = $this->service->reverseAtOpen($this->supplier, $this->period, $target, 2);
        $done = $pdo->prepare("SELECT done_at FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation'");
        $done->execute([$this->supplier, $this->period]);
        self::assertSame('2098-12-31 18:30:00', $done->fetchColumn());
        self::assertGreaterThan(0, $reversal);
        self::assertSame(['accounting.posted'], $this->entryAudit($reversal));
        $journal = $container->get(JournalEntryRepository::class);
        $original = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::sourceLines($this->db, $journal, $this->supplier, $result['entry_ids']['saldo']);
        $mirror = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::sourceLines($this->db, $journal, $this->supplier, $reversal);
        foreach ($original as $index => $line) {
            self::assertSame($line['dimensions'], $mirror[$index]['dimensions']);
            self::assertSame($line['amount'], $mirror[$index]['amount']);
            self::assertSame($line['amount_foreign'], $mirror[$index]['amount_foreign']);
            self::assertNotSame($line['side'], $mirror[$index]['side']);
        }
        self::assertGreaterThan(0, $this->service->cancelOpeningReversal($this->supplier, $this->period));
        $again = $this->service->reverseAtOpen($this->supplier, $this->period, $target, 2);
        self::assertGreaterThan(0, $again);
        self::assertNotSame($reversal, $again);
    }

    public function testLateBankFailureRollsBackBothSlotsAndPendingStepInCallerTransaction(): void
    {
        $container = Bootstrap::buildContainer();
        $container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 2400, 'amount_foreign' => 100, 'currency_code' => 'EUR', 'fx_rate' => 24, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ['account_code' => '645', 'side' => 'credit', 'amount' => 2400, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
        ], ['entry_date' => '2098-06-01', 'posted' => true, 'allow_closing_period' => true]);
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='221'")->execute([$this->supplier]);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $before = $this->state();
        try {
            $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
            self::fail('Inactive bank account must fail after saldo slot processing.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
            self::assertSame('account_inactive', $failure->errorCode);
        }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
    }

    /**
     * Náhled příštího roku je jen čtení a na stavu zdroje nezávisí, proto ho ověřuje uzavřená
     * varianta před revertem. Reviewed/approved ověřují otevření i zablokovaný revert.
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['closed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['reviewed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['approved'])]
    public function testPublicCloseOpenAndRevertCarriesFxTuple(string $sourceStatus): void
    {
        $container = Bootstrap::buildContainer();
        $closing = $container->get(ClosingService::class);
        $pdo = $this->db->pdo();
        foreach (['901', '911', '931', '932', '933', '961', '962'] as $code) {
            $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        }
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) {
            $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id=' . $this->period)->fetchColumn();
        $closing->saveInventory($this->supplier, $this->period, $version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $version());
        }
        $preview = $closing->fxPreview($this->supplier, $this->period, [], null, $this->caseEvidence());
        $fx = $closing->runFxRevaluation($this->supplier, $this->period, [], $version(), [], ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']]);
        $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, array_values($this->dims)[1]]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closed = $closing->closeBooks($this->supplier, $this->period, $version());
        self::assertSame(25000.0, $closed['profit']);
        if ($sourceStatus !== 'closed') {
            $pdo->prepare('UPDATE accounting_periods SET status=?,row_version=row_version+1 WHERE supplier_id=? AND id=?')
                ->execute([$sourceStatus, $this->supplier, $this->period]);
        }
        $beforeOpen = $closing->fxPreview($this->supplier, $this->period);
        $opened = $closing->openNext($this->supplier, $this->period, $version());
        $afterOpen = $closing->fxPreview($this->supplier, $this->period);
        self::assertSame($beforeOpen['case_source_hash'], $afterOpen['case_source_hash']);
        self::assertSame($beforeOpen['case_findings'], $afterOpen['case_findings']);
        self::assertGreaterThan(0, $opened['entry_id']);
        $journal = $container->get(JournalEntryRepository::class);
        $mirror = $journal->findActiveBySourceForUpdate($this->supplier, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxReversal($this->period));
        self::assertNotNull($mirror);
        $lines = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::sourceLines($this->db, $journal, $this->supplier, (int) $mirror['id']);
        self::assertSame($this->dims, $lines[0]['dimensions']);
        self::assertSame(1000.0, $lines[0]['amount']);
        if ($sourceStatus === 'closed') {
            $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2099-12-31','EUR',26) ON DUPLICATE KEY UPDATE rate=26");
            $nextPreview = $closing->fxPreview($this->supplier, $opened['next_period_id']);
            self::assertSame('2000.00', $nextPreview['totals']['gain']);
            self::assertSame('1000.00', $nextPreview['positions'][0]['foreign_balance']);
            self::assertSame('24000.00', $nextPreview['positions'][0]['booked_czk']);
        }
        if ($sourceStatus !== 'closed') {
            try {
                $closing->revertStep($this->supplier, $this->period, 'open_next', $version());
                self::fail('Reviewed or approved closing must retain its opening and FX reversal.');
            } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
                self::assertSame('invalid_status_transition', $failure->errorCode);
            }
            self::assertSame($mirror['id'], $journal->findActiveBySourceForUpdate($this->supplier, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxReversal($this->period))['id']);
            return;
        }
        $closing->revertStep($this->supplier, $this->period, 'open_next', $version());
        self::assertNull($journal->findActiveBySourceForUpdate($this->supplier, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxReversal($this->period)));
        self::assertNotNull($journal->findActiveBySourceForUpdate($this->supplier, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxSaldo($this->period)));
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testCurrentPreviewSeesCommittedPostingAndCancellationAfterCallerSnapshot(bool $cancel): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $old = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period);
        self::assertSame(100000, $old['positions'][0]['foreign_cents']);
        $otherContainer = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        $this->other = $otherContainer->get(Connection::class);
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $posting = $otherContainer->get(PostingService::class);
        if ($cancel) {
            $posting->reverse($this->supplier, $old['source_rows'][0]['entry_id'], ['entry_date' => '2098-06-02', 'allow_closing_period' => true]);
        } else {
            $posting->postDocument($this->supplier, 'manual', null, [
                ['account_code' => '311', 'side' => 'debit', 'amount' => 24000, 'amount_foreign' => 1000, 'currency_code' => 'EUR', 'fx_rate' => 24, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
                ['account_code' => '645', 'side' => 'credit', 'amount' => 24000, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ], ['entry_date' => '2098-06-02', 'posted' => true, 'allow_closing_period' => true]);
        }
        $preview = $this->service->preview($this->supplier, $this->period, [], true, $this->caseEvidence(true));
        self::assertSame($cancel ? '0.00' : '2000.00', $preview['totals']['gain']);
        self::assertCount($cancel ? 0 : 2, $preview['positions']);
        self::assertNotSame($old['snapshot_hash'], $preview['snapshot_hash']);
        try {
            $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $old['snapshot_hash']], 1);
            self::fail('Old source snapshot cannot authorize posting.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
            self::assertSame('fx504_changed', $failure->errorCode);
        }
        self::assertTrue($pdo->inTransaction());
    }

    public function testPublicPreviewRunsInsideNativeReadOnlyTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        $before = $this->state();
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->supplier, $this->period, [], null, $this->caseEvidence());
        self::assertSame('1000.00', $preview['totals']['gain']);
        self::assertTrue($pdo->inTransaction());
        self::assertSame($before, $this->state());
    }

    public function testLateAuditFailureRollsBackPostingAndKeepsCallerWork(): void
    {
        $container = Bootstrap::buildContainer();
        $logger = new class($this->db) extends \MyInvoice\Service\ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null,
                ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                if ($action === 'accounting.fx_revalued') throw new \DomainException('Synthetic audit failure.');
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        };
        $service = new Fx504Service($this->db, $container->get(JournalEntryRepository::class),
            $container->get(\MyInvoice\Service\Accounting\Closing\DocumentSeriesService::class),
            $container->get(\MyInvoice\Repository\ClosingRepository::class), $container->get(PostingService::class), $logger);
        $preview = $service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $before = $this->state();
        try {
            $service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
            self::fail('Audit failure must propagate.');
        } catch (\DomainException $failure) {
            self::assertSame('Synthetic audit failure.', $failure->getMessage());
        }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
    }

    public function testMissingProfitAxesRequireExplicitCellsAndPreserveSourceCustomAxes(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name,is_active) VALUES (?,'fx_custom','Synthetic custom',1)")->execute([$this->supplier]);
        $type = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name,is_active) VALUES (?,?,'fx_custom_value','Synthetic custom value',1)")->execute([$type, $this->supplier]);
        $value = (int) $pdo->lastInsertId();
        $source = $pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='311' ORDER BY l.id LIMIT 1");
        $source->execute([$this->supplier]);
        $lineId = (int) $source->fetchColumn();
        $pdo->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id=? AND line_id=?')->execute([$this->supplier, $lineId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->insertLineAssignments($this->supplier, [$lineId => ['dimensions' => [$type => $value]]]);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        self::assertFalse($preview['complete']);
        $key = $preview['positions'][0]['key'];
        $body = ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions'], 'decisions' => [$key => ['reason' => 'Synthetic allocation evidence', 'cells' => [
            ['amount' => '600.00', 'dimensions' => $this->dims + [$type => $value]],
            ['amount' => '400.00', 'dimensions' => $this->dims + [$type => $value]],
        ]]]];
        $result = $this->service->run($this->supplier, $this->period, $body, 1);
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $lines = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::sourceLines($this->db, $journal, $this->supplier, $result['entry_ids']['saldo']);
        self::assertCount(4, $lines);
        self::assertSame([$type => $value], $lines[0]['dimensions']);
        self::assertSame($this->dims + [$type => $value], $lines[1]['dimensions']);
        self::assertSame(600.0, $lines[0]['amount']);
        self::assertSame(400.0, $lines[2]['amount']);
        $again = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        self::assertSame($body['decisions'], $again['saved_decisions']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['{'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['null'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['42'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['[1]'])]
    public function testPreviewRejectsMalformedAccountDecisionJson(string $query): void
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/test')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withQueryParams(['account_decisions' => $query]);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class);
        $response = $action->fxPreview($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testNativeCaseOriginLocksNewMembershipBeyondCallerSnapshot(): void
    {
        $invoiceId = $this->caseInvoice();
        $pdo = $this->db->pdo();
        $rows = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'];
        $manual = array_values(array_filter($rows, static fn (array $row): bool => $row['source_type'] === 'manual'))[0];
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $reader = new \MyInvoice\Repository\FxCaseOriginReadRepository($this->db);
        $old = $reader->snapshot($this->supplier, $rows);
        self::assertSame([], $old['origins'][$manual['id']]);
        $other = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        $this->other = $other->get(Connection::class);
        $this->other->pdo()->prepare("INSERT INTO journal_entry_document_links(supplier_id,entry_id,doc_type,doc_id) VALUES (?,?,'invoice',?)")->execute([$this->supplier, $manual['entry_id'], $invoiceId]);
        $current = $reader->snapshot($this->supplier, $rows, true);
        self::assertSame([['kind' => 'document', 'doc_type' => 'invoice', 'doc_id' => $invoiceId]], $current['origins'][$manual['id']]);
        self::assertNotSame($old['facts_hash'], $current['facts_hash']);
        self::assertTrue($pdo->inTransaction());
    }

    public function testNonmonetaryEvidenceDoesNotRequireMonetaryCaseAllocation(): void
    {
        $accounts = ['311' => ['classification' => 'nonmonetary', 'reason' => 'Synthetic title is settled solely by delivery, without a right to cash.']];
        $preview = $this->service->preview($this->supplier, $this->period, $accounts);
        self::assertTrue($preview['complete']);
        self::assertSame([], $preview['case_unresolved']);
        self::assertSame(['loss' => '0.00', 'gain' => '0.00'], $preview['totals']);
        $posted = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'account_decisions' => $accounts, 'case_decisions' => []], 1);
        self::assertSame(['saldo' => null, 'bank' => null], $posted['entry_ids']);
    }

    public function testOppositeIndependentCasesStillLoadRateWhenWholeAccountNetsToZero(): void
    {
        $source = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'][0];
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->reverse($this->supplier, (int) $source['entry_id'], ['entry_date' => '2098-06-02', 'allow_closing_period' => true]);
        $this->caseInvoice(24, '2098000601');
        $credit = $this->caseInvoice(24, '2098000602');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET invoice_type='credit_note',total_without_vat=-1000,total_with_vat=-1000 WHERE supplier_id=? AND id=?")->execute([$this->supplier, $credit]);
        $pdo->prepare('UPDATE invoice_items SET quantity=-1,total_without_vat=-1000,total_with_vat=-1000 WHERE invoice_id=?')->execute([$credit]);
        $posting->postDocument($this->supplier, 'invoice', $credit, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24', 'is_red_storno' => true, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ['account_code' => '645', 'side' => 'credit', 'amount' => '24000.00', 'is_red_storno' => true, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
        ], ['entry_date' => '2098-06-15', 'posted' => true, 'allow_closing_period' => true]);
        $preview = $this->service->preview($this->supplier, $this->period);
        self::assertTrue($preview['complete']);
        self::assertCount(2, $preview['positions']);
        self::assertSame(['loss' => '1000.00', 'gain' => '1000.00'], $preview['totals']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testLinkedCreditNoteSharesTheProvenOriginalCase(bool $samePartner): void
    {
        $source = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'][0];
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier, (int) $source['entry_id'], ['entry_date' => '2098-06-02', 'allow_closing_period' => true]);
        $invoice = $this->caseInvoice(24, '2098000501');
        $credit = $this->caseInvoice(23, '2098000502');
        if ($samePartner) $this->db->pdo()->prepare('UPDATE invoices c JOIN invoices p ON p.id=? AND p.supplier_id=c.supplier_id SET c.client_id=p.client_id WHERE c.id=? AND c.supplier_id=?')->execute([$invoice, $credit, $this->supplier]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET invoice_type='credit_note',parent_invoice_id=?,total_without_vat=-500,total_with_vat=-500 WHERE supplier_id=? AND id=?")->execute([$invoice, $this->supplier, $credit]);
        $pdo->prepare('UPDATE invoice_items SET quantity=-0.5,total_without_vat=-500,total_with_vat=-500 WHERE invoice_id=?')->execute([$credit]);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'invoice', $credit, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => '11500.00', 'amount_foreign' => '500.00', 'currency_code' => 'EUR', 'fx_rate' => '23', 'is_red_storno' => true, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ['account_code' => '645', 'side' => 'credit', 'amount' => '11500.00', 'is_red_storno' => true, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
        ], ['entry_date' => '2098-06-15', 'posted' => true, 'allow_closing_period' => true]);
        $preview = $this->service->preview($this->supplier, $this->period);
        if (!$samePartner) { self::assertFalse($preview['complete']); self::assertNotEmpty($preview['case_unresolved']); return; }
        self::assertTrue($preview['complete']);
        self::assertCount(1, $preview['positions']);
        self::assertSame(['loss' => '0.00', 'gain' => '0.00'], $preview['totals']);
        self::assertSame('500.00', $preview['positions'][0]['foreign_balance']);
        self::assertSame('12500.00', $preview['positions'][0]['booked_czk']);
    }

    public function testSeveralLinkedDocumentsCannotBecomeAutomaticThroughAccountFiltering(): void
    {
        $first = $this->caseInvoice(24, '2098000401');
        $second = $this->caseInvoice(26, '2098000402');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id SET l.account_id=(SELECT a2.id FROM chart_of_accounts a2 WHERE a2.supplier_id=? AND a2.account_code='378') WHERE e.supplier_id=? AND e.source_type='invoice' AND e.source_id=? AND a.account_code='311'")->execute([$this->supplier, $this->supplier, $second]);
        $rows = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'];
        $manual = array_values(array_filter($rows, static fn (array $row): bool => $row['source_type'] === 'manual'))[0];
        foreach ([$first, $second] as $id) $pdo->prepare("INSERT INTO journal_entry_document_links(supplier_id,entry_id,doc_type,doc_id) VALUES (?,?,'invoice',?)")->execute([$this->supplier, $manual['entry_id'], $id]);
        $origins = (new \MyInvoice\Repository\FxCaseOriginReadRepository($this->db))->snapshot($this->supplier, $rows);
        self::assertSame([], $origins['origins'][$manual['id']], 'An account match does not prove which of several linked documents owns a manual payment.');
        self::assertFalse($this->service->preview($this->supplier, $this->period)['complete']);
    }

    public function testPublicCaseProjectionRequiresManualEvidenceAndPreservesGrossNativeCases(): void
    {
        $pending = $this->service->preview($this->supplier, $this->period);
        self::assertFalse($pending['complete']);
        self::assertSame('case_evidence_required', $pending['positions'][0]['finding']);
        $source = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'][0];
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier, (int) $source['entry_id'], ['entry_date' => '2098-06-02', 'allow_closing_period' => true]);
        $this->caseInvoice(24, '2098000201');
        $this->caseInvoice(26, '2098000202');
        $preview = $this->service->preview($this->supplier, $this->period);
        self::assertTrue($preview['complete']);
        self::assertSame(['loss' => '1000.00', 'gain' => '1000.00'], $preview['totals']);
        $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash']], 1);
        $lines = Bootstrap::buildContainer()->get(JournalEntryRepository::class)->linesForEntry($result['entry_ids']['saldo'], $this->supplier);
        self::assertCount(4, $lines);
        $codes = $this->db->pdo()->prepare('SELECT a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY a.account_code');
        $codes->execute([$this->supplier, $result['entry_ids']['saldo']]);
        self::assertSame(['311', '311', '545', '645'], $codes->fetchAll(\PDO::FETCH_COLUMN));
        $again = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash']], 2);
        self::assertSame($result['entry_ids'], $again['entry_ids']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([null])]
    #[\PHPUnit\Framework\Attributes\TestWith(['bearer'])]
    public function testCasePreviewRequiresExplicitSession(?string $method): void
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $method)
            ->withParsedBody(['account_decisions' => [], 'case_decisions' => []]);
        $response = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class)
            ->fxCasePreview($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('fx_case_session_only', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testCasePostPreviewRunsInsideReadOnlyTransaction(): void
    {
        $body = ['account_decisions' => [], 'case_decisions' => $this->caseEvidence()];
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        $before = $this->state();
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'accountant'])
            ->withParsedBody($body);
        $response = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\ClosingAction::class)
            ->fxCasePreview($request, new \Slim\Psr7\Response(), ['id' => (string) $this->period]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('1000.00', json_decode((string) $response->getBody(), true)['totals']['gain']);
        self::assertSame($before, $this->state());
        self::assertTrue($pdo->inTransaction());
    }

    public function testCasePayloadIsRejectedBy500Preview(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        try {
            Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->supplier, $this->period, [], [], []);
            self::fail('504-only evidence cannot be ignored by500 preview.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
            self::assertSame('fx_case_framework_unsupported', $failure->errorCode);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testDistinctCasesReconcileOpeningAndKeepNextYearGross(bool $reverse): void
    {
        $container = Bootstrap::buildContainer();
        $closing = $container->get(ClosingService::class);
        $pdo = $this->db->pdo();
        $source = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period)['source_rows'][0];
        $container->get(PostingService::class)->reverse($this->supplier, (int) $source['entry_id'], ['entry_date' => '2098-06-02', 'allow_closing_period' => true]);
        $this->caseInvoice(24, '2098000301');
        $this->caseInvoice(26, '2098000302');
        $pdo->prepare('INSERT INTO accounting_supplier_settings(supplier_id,fx_reversal_at_open) VALUES (?,?) ON DUPLICATE KEY UPDATE fx_reversal_at_open=VALUES(fx_reversal_at_open)')->execute([$this->supplier, (int) $reverse]);
        foreach (['901', '911', '931', '932', '933', '961', '962'] as $code) {
            $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier, $code, 'Synthetic ' . $code]);
        }
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplier, $this->period)['rows'] as $row) $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id=' . $this->period)->fetchColumn();
        $closing->saveInventory($this->supplier, $this->period, $version(), ['complete' => true], $inventory);
        $closing->runPrecheck($this->supplier, $this->period, $version());
        foreach (['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'] as $step) $closing->confirmStep($this->supplier, $this->period, $step, 'skipped', null, $version());
        $preview = $closing->fxPreview($this->supplier, $this->period);
        $closing->runFxRevaluation($this->supplier, $this->period, [], $version(), [], ['snapshot_hash' => $preview['snapshot_hash']]);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($container, $this->supplier, $this->period, (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
        $closing->closeBooks($this->supplier, $this->period, $version());
        $opened = $closing->openNext($this->supplier, $this->period, $version());
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2099-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=25");
        $next = $closing->fxPreview($this->supplier, $opened['next_period_id']);
        self::assertTrue($next['complete'], json_encode($next['case_findings']));
        self::assertCount(2, $next['positions']);
        self::assertSame(['loss' => $reverse ? '1000.00' : '0.00', 'gain' => $reverse ? '1000.00' : '0.00'], $next['totals']);
        self::assertSame(['1000.00', '1000.00'], array_column($next['positions'], 'foreign_balance'));
        $balances = array_column($next['positions'], 'booked_czk');
        sort($balances);
        self::assertSame($reverse ? ['24000.00', '26000.00'] : ['25000.00', '25000.00'], $balances);
    }

    public function testCasePayloadIsRejectedBy500Run(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->supplier]);
        $before = $this->state();
        try {
            Bootstrap::buildContainer()->get(ClosingService::class)->runFxRevaluation($this->supplier, $this->period, [], 1, [], ['case_decisions' => []]);
            self::fail('504-only evidence cannot be ignored by500 posting.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $failure) {
            self::assertSame('fx_case_framework_unsupported', $failure->errorCode);
        }
        self::assertSame($before, $this->state());
    }


    #[\PHPUnit\Framework\Attributes\TestWith(['tenant'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['amount'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['source'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['source_membership'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['rollback'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['transaction'])]
    public function testFxProofRejectsChangedAuthority(string $mutation): void
    {
        $pdo = $this->db->pdo();
        $proof = $this->pendingProof();
        $pdo->exec('SAVEPOINT before_fx_proof');
        $proof = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::forPending($this->db, $this->supplier, (int) $proof->stepRow['id'], 'saldo', 2);
        $header = $proof->header;
        $lines = $proof->lines;
        if ($mutation === 'tenant') ++$header['supplier_id'];
        if ($mutation === 'amount') $lines[0]['amount'] = '999.99';
        if ($mutation === 'source') $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id SET l.amount=l.amount+1 WHERE e.supplier_id=? AND e.source_type='manual'")->execute([$this->supplier]);
        if ($mutation === 'source_membership') $this->caseInvoice(24, '2098000991');
        if ($mutation === 'rollback') $pdo->exec('ROLLBACK TO SAVEPOINT before_fx_proof');
        if ($mutation === 'transaction') { $pdo->commit(); $this->committed = true; $pdo->beginTransaction(); }
        try { $proof->assignments($this->db, $header, $lines); self::fail('Changed FX authority accepted'); }
        catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('fx504_changed', $e->errorCode); }
    }

    public function testFxProofCannotBeSerializedAndRequiresItsConnection(): void
    {
        $proof = $this->pendingProof();
        try { serialize($proof); self::fail('Serializable proof'); }
        catch (\LogicException $e) { self::assertStringContainsString('cannot be serialized', $e->getMessage()); }
        $other = Connection::withoutSharedTestConnection(static function (): Connection {
            $connection = Bootstrap::buildContainer()->get(Connection::class);
            $connection->pdo();
            return $connection;
        });
        try {
            $other->pdo()->beginTransaction();
            try { $proof->assignments($other, $proof->header, $proof->lines); self::fail('Foreign connection accepted'); }
            catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('fx504_changed', $e->errorCode); }
        } finally { if ($other->pdo()->inTransaction()) $other->pdo()->rollBack(); $other->close(); }
    }

    private function pendingProof(): \MyInvoice\Service\Accounting\Closing\Fx504JournalProof
    {
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='fx_revaluation'");
        $stmt->execute([$this->supplier, $this->period]);
        $step = $stmt->fetch(\PDO::FETCH_ASSOC);
        $payload = json_decode($step['payload'], true, flags: JSON_THROW_ON_ERROR);
        $payload['fx504']['expected_version'] = 2;
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        foreach (['saldo', 'bank'] as $slot) {
            $entryId = $payload['fx504']['slots'][$slot]['entry_id'];
            $entry = $entryId === null ? null : $journal->findForUpdate($entryId, $this->supplier);
            $payload['fx504']['slots'][$slot]['status'] = 'pending';
            $payload['fx504']['slots'][$slot]['previous_entry_id'] = $entryId;
            $payload['fx504']['slots'][$slot]['previous_entry_version'] = $entry === null ? null : (int) $entry['row_version'];
        }
        $pdo->prepare("UPDATE accounting_closing_steps SET status='pending',payload=? WHERE id=? AND supplier_id=?")->execute([json_encode($payload, JSON_THROW_ON_ERROR), $step['id'], $this->supplier]);
        return \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::forPending($this->db, $this->supplier, (int) $step['id'], 'saldo', 2);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testRetiredPropertyFxKeepsStructuralGuard(bool $orphan): void
    {
        $pdo = $this->db->pdo();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $profile = new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db);
        $profile->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2098-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic property activity', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'fx-property-active'], $actor);
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT ?,'Synthetic owner','Synthetic 1','Synthetic','10000',country_id,'fx-owner@example.test',default_currency_id FROM supplier WHERE id=?")
            ->execute([$this->supplier, $this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $registry = new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db);
        $request = static fn (string $code, array $state): array => ['code' => $code, 'expected_revision' => 0,
            'effective_from' => '2098-01-01T00:00:00+01:00', 'status' => 'confirmed', 'state' => $state,
            'reason' => 'Synthetic registry', 'title_ref' => 'Synthetic deed', 'idempotency_key' => $code];
        $house = $registry->create($this->supplier, 'house', $request('FX-HOUSE', ['name' => 'Synthetic house', 'active' => true,
            'address' => ['street' => 'Synthetic 1', 'city' => 'Synthetic', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic deed', 'share_scope' => 'FX-HOUSE', 'registry_complete' => true]), $actor);
        $service = $registry->create($this->supplier, 'service', $request('FX-SERVICE', ['name' => 'Synthetic water', 'active' => true, 'measure' => 'm3', 'measure_label' => '']), $actor);
        $unit = $registry->create($this->supplier, 'unit', $request('FX-UNIT', ['unit_no' => 'FX-UNIT', 'kind' => 'apartment', 'active' => true,
            'attributes' => ['share' => ['num' => '1', 'den' => '1']],
            'ownerships' => [['group_key' => 'OWNER', 'kind' => 'single', 'share' => ['num' => '1', 'den' => '1'], 'members' => [$client], 'title_ref' => 'Synthetic deed']],
            'roles' => [], 'identifiers' => [], 'services' => [$service['id']]]) + ['parent_id' => $house['id']], $actor);
        $statement = $pdo->prepare('SELECT o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id WHERE o.supplier_id=? AND o.id IN (?,?)');
        $statement->execute([$this->supplier, $unit['id'], $service['id']]);
        $property = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $property);
        foreach ($property as $row) $this->dims[(int) $row['type_id']] = (int) $row['dimension_value_id'];
        ksort($this->dims);

        $sourceEntry = Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => '24000.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => '24', 'dimensions' => $this->dims],
            ['account_code' => '645', 'side' => 'credit', 'amount' => '24000.00', 'dimensions' => $this->dims],
        ], ['entry_date' => '2098-06-10', 'posted' => true, 'allow_closing_period' => true]);
        if ($orphan) {
            $type = $pdo->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='unit'");
            $type->execute([$this->supplier]);
            $typeId = (int) $type->fetchColumn();
            $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$typeId, $this->supplier, 'FX-ORPHAN', 'Synthetic orphan']);
            $orphanId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id AND l.supplier_id=d.supplier_id SET d.dimension_value_id=? WHERE d.supplier_id=? AND l.entry_id=? AND d.dimension_type_id=?')->execute([$orphanId, $this->supplier, $sourceEntry, $typeId]);
        }
        foreach ($property as $row) $pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $row['dimension_value_id']]);
        $profile->save($this->supplier, ['expected_revision' => 1, 'effective_from' => '2098-07-01T00:00:00+02:00',
            'enabled' => false, 'reason' => 'Synthetic retired property activity', 'title_ref' => 'Synthetic decision', 'idempotency_key' => 'fx-property-retired'], $actor);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $before = $this->state();
        try {
            $result = $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
            if ($orphan) self::fail('Orphan property tuple accepted');
            self::assertSame('2000.00', $result['totals']['gain']);
            $lines = \MyInvoice\Service\Accounting\Closing\Fx504JournalProof::sourceLines($this->db, Bootstrap::buildContainer()->get(JournalEntryRepository::class), $this->supplier, $result['entry_ids']['saldo']);
            self::assertContains($this->dims, array_column($lines, 'dimensions'));
            $this->service->revert($this->supplier, $this->period, 2);
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            if (!$orphan) throw $e;
            self::assertSame('property_dimension_unavailable', $e->errorCode);
            self::assertSame($before, $this->state());
        }
    }


    #[\PHPUnit\Framework\Attributes\TestWith(['bank', 500, '2098-09-15'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['bank', 500, '2098-05-15'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['bank', 1000, '2098-09-15'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['cash', 500, '2098-09-15'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['cash_unproven', 500, '2098-09-15'])]
    public function testNativePaymentWithoutNominalTraceIsIncluded(string $channel, int $paid, string $date): void
    {
        $pdo = $this->db->pdo();
        $container = Bootstrap::buildContainer();
        $id = $this->caseInvoice();
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE accounting_periods SET status='open' WHERE id=? AND supplier_id=?")->execute([$this->period, $this->supplier]);
        $pdo->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,'EUR',24) ON DUPLICATE KEY UPDATE rate=24")->execute([$date]);
        if ($channel === 'bank') {
            $pdo->prepare("UPDATE currencies SET account_number='1000000005',bank_code='0100' WHERE supplier_id=? AND code='EUR'")->execute([$this->supplier]);
            $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?)")->execute([$this->supplier, $actor]);
            $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-fx504.csv',?,'1000000005','0100','EUR',?,?)")->execute([$this->supplier, hash('sha256', 'synthetic-fx504-' . $this->supplier), $date, $actor]);
            $statement = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,matched_invoice_id) VALUES (?,'statement',? ,?,'EUR','Synthetic payment','manual',?)")->execute([$statement, $date, $paid, $id]);
            $tx = (int) $pdo->lastInsertId();
            $container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id, $paid, $date, ['source' => 'bank', 'bank_transaction_id' => $tx]);
            $posted = $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($tx, $actor);
            self::assertSame('posted', $posted['action'], json_encode($posted));
        } else {
            $register = $container->get(\MyInvoice\Repository\CashRegisterRepository::class)->create($this->supplier, ['name' => 'Synthetic CZK', 'currency_code' => 'CZK', 'account_code' => '211']);
            $cash = $container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)->create($this->supplier, [
                'register_id' => $register, 'purpose' => 'other', 'doc_type' => 'in', 'issue_date' => $date,
                'description' => 'Synthetic historical cash', 'vat_mode' => 'none', 'total_amount' => $paid * 24, 'counter_account_code' => '311',
            ], $actor);
            $payment = $container->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id, $paid, $date, ['source' => 'cash']);
            $pdo->prepare('UPDATE cash_documents SET invoice_id=?,invoice_payment_id=? WHERE supplier_id=? AND id=?')->execute([$id, $payment['payment_id'], $this->supplier, $cash['id']]);
            if ($channel === 'cash_unproven') $pdo->prepare('UPDATE cash_documents SET invoice_payment_id=NULL WHERE supplier_id=? AND id=?')->execute([$this->supplier, $cash['id']]);
        }
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=? AND supplier_id=?")->execute([$this->period, $this->supplier]);
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        if ($channel === 'cash_unproven') {
            self::assertFalse($preview['complete']);
            self::assertContains('case_nominal_unproven', array_column($preview['case_findings'], 'code'));
            $before = $this->state();
            try { $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1); self::fail('Unproven nominal accepted'); }
            catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) { self::assertSame('fx_case_source_changed', $e->errorCode); }
            self::assertSame($before, $this->state());
            return;
        }
        $positions = array_values(array_filter($preview['positions'], static fn (array $p): bool => ($p['case_origin']['doc_id'] ?? null) === $id));
        self::assertSame((1000 - $paid) * 100, array_sum(array_column($positions, 'foreign_cents')));
        self::assertSame((1000 - $paid) * 2400, array_sum(array_column($positions, 'czk_cents')));
        self::assertSame([], $preview['case_findings']);
    }


    public function testPostedFxFeedsCurrent504StatementAndCategoryTurnover(): void
    {
        $preview = $this->service->preview($this->supplier, $this->period, null, false, $this->caseEvidence());
        $this->service->run($this->supplier, $this->period, ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions']], 1);
        $container = Bootstrap::buildContainer();
        $period = $container->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period);
        $criteria = $container->get(\MyInvoice\Service\Accounting\Reports\Statement504CategoryCriteria::class)->calculate($this->supplier, $period, '2098-12-31');
        self::assertSame(25000.0, $criteria['net_turnover']);
        $rows = $container->get(\MyInvoice\Repository\Statement504DataRepository::class)->profitLines($this->supplier, '2098-01-01', '2098-12-31');
        $statement = (new \MyInvoice\Service\Accounting\Reports\Statement504Builder())->incomeStatement($rows, 'full');
        $gain = array_values(array_filter($statement['rows'], static fn (array $row): bool => $row['row_code'] === 'UT.52'));
        self::assertCount(1, $gain);
        self::assertSame(25000.0, $gain[0]['main']);
        self::assertSame(0.0, $gain[0]['economic']);
    }

    private function caseInvoice(int $rate = 24, string $symbol = '2098000201'): int
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='EUR'");
        $find->execute([$this->supplier]);
        $eur = (int) $find->fetchColumn();
        if (!$eur) {
            $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$this->supplier]);
            $eur = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic FX case','Test','Test','10000',country_id,'case@example.com',? FROM supplier WHERE id=?")->execute([$eur, $this->supplier]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
            VALUES (?,?,'invoice',?,'2098-06-15','2098-06-15','2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$this->supplier, $symbol, $client, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => (string) ($rate * 1000) . '.00', 'amount_foreign' => '1000.00', 'currency_code' => 'EUR', 'fx_rate' => (string) $rate, 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
            ['account_code' => '645', 'side' => 'credit', 'amount' => (string) ($rate * 1000) . '.00', 'dimensions' => $this->dims, 'dimension_origins' => array_fill_keys(array_keys($this->dims), ['origin' => 'explicit', 'ref' => 'synthetic-fx-source'])],
        ], ['entry_date' => '2098-06-15', 'posted' => true, 'allow_closing_period' => true]);
        return $id;
    }

    private function caseEvidence(bool $currentRead = false): array
    {
        $snapshot = (new \MyInvoice\Repository\Fx504ReadRepository($this->db))->snapshot($this->supplier, $this->period, $currentRead);
        $source = $snapshot['case_snapshot'];
        $decisions = $source['prior_decisions'];
        $projection = \MyInvoice\Service\Accounting\Closing\Fx504CaseProjection::project($source['rows'], $source['origins'], $decisions);
        foreach ($projection['unresolved'] as $cell) {
            $decisions[] = ['origin' => ['kind' => 'declared_title', 'origin_line_id' => $cell['line_id'], 'reference' => 'Synthetic claim ' . $cell['line_id']],
                'reason' => 'Synthetic documented inventory of a separate monetary claim', 'contributions' => [
                    ['cell_key' => $cell['cell_key'], 'czk_signed' => $cell['czk_signed'], 'foreign_signed' => $cell['foreign_signed']],
                ]];
        }
        return $decisions;
    }

    private function state(): array
    {
        $result = [];
        foreach (['accounting_periods', 'journal_entries', 'journal_entry_lines', 'accounting_closing_steps', 'accounting_document_series', 'activity_log'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=? ORDER BY id');
            $stmt->execute([$this->supplier]);
            $result[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
