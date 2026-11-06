<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\{TaxLossPeriodProof, TaxLossService, TaxReturnService, TaxReturnException};
use MyInvoice\Tests\Integration\Tax\DppoFinalSnapshotAuditTest;
use PHPUnit\Framework\TestCase;

final class TaxReturnLossAllocationTest extends TestCase
{
    private DppoFinalSnapshotAuditTest $fixture;
    private Connection $db;
    private TaxReturnService $returns;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->fixture = new DppoFinalSnapshotAuditTest('testFinalDppoDetailAndXmlStayFrozenAfterLedgerAndSupplierChanges');
        (new \ReflectionMethod($this->fixture, 'setUp'))->invoke($this->fixture);
        foreach (['db', 'returns', 'supplierId', 'userId'] as $key) {
            $this->$key = (new \ReflectionProperty($this->fixture, $key))->getValue($this->fixture);
        }
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture, 'tearDown'))->invoke($this->fixture);
    }

    public function testNative500FinalizationRejectsUnallocatedLossBeforeSnapshotOrLedgerWrites(): void
    {
        $losses = new TaxLossService($this->db, new TaxConstantsRepository($this->db));
        $losses->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $draft = $this->returns->getReturn($this->supplierId, 2049, 'po', $this->userId);
        $draft = $this->returns->saveInputs($this->supplierId, 2049, 'po', ['loss_carryforward' => 200000], $draft['return']['row_version'], $this->userId, partial: true);
        self::assertSame(200000, (int) $draft['computed']['summary']['loss_applied']);
        self::assertSame(105000, (int) $draft['computed']['tax']);
        try {
            $this->returns->finalize($this->supplierId, 2049, 'po', $draft['return']['row_version'], $this->userId);
            self::fail('Native return deducted 200000 while only 100000 could be allocated.');
        } catch (TaxReturnException $e) {
            self::assertSame('loss_allocation_insufficient', $e->errorCode);
        }
        self::assertSame('draft', (new TaxReturnRepository($this->db))->find($this->supplierId, 2049, 'po')['status']);
        foreach (['income_tax_return_snapshots', 'tax_loss_applications'] as $table) {
            $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id=?');
            $query->execute([$this->supplierId]);
            self::assertSame(0, (int) $query->fetchColumn());
        }
        self::assertSame(100000.0, $losses->card($this->supplierId, 2049, 'po')['available_total']);
        $corrected = $this->draft(100000);
        self::assertSame(126000, (int) $corrected['computed']['tax']);
    }

    public function testNativeFifoMatchesComputedAndPersistedAcrossReplacementAndReopen(): void
    {
        $losses = $this->losses();
        $losses->registerOpeningLoss($this->supplierId, 'po', 2047, 60000);
        $losses->registerOpeningLoss($this->supplierId, 'po', 2048, 50000);
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($this->supplierId, 2048, '2048-01-01', '2048-12-31');
        $draft = $this->draft(100000); $first = $this->finish($draft);
        self::assertSame([2047 => '60000.00', 2048 => '40000.00'], $this->amounts());
        self::assertSame((int) $first['computed']['summary']['loss_applied'], (int) array_sum($this->amounts()));
        $corrective = $this->finish($this->draft(60000, 'opravne'), 'opravne');
        self::assertSame([2047 => '60000.00'], $this->amounts());
        self::assertSame($this->owner('opravne'), (int) $this->applications()[0]['applied_return_id']);
        $this->returns->reopen($this->supplierId, 2049, 'po', $corrective['return']['row_version'], $this->userId, 'opravne');
        self::assertSame([2047 => '60000.00', 2048 => '40000.00'], $this->amounts());
        self::assertSame($this->owner(), (int) $this->applications()[0]['applied_return_id']);
    }

    public function testLowerVariantNeverRewritesEffectiveOwnersApplications(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $first = $this->finish($this->draft(80000));
        $higher = $this->finish($this->draft(60000, 'opravne'), 'opravne');
        $before = $this->applications();
        $this->returns->reopen($this->supplierId, 2049, 'po', $first['return']['row_version'], $this->userId);
        self::assertSame($before, $this->applications());
        $this->finish($this->draft(90000));
        self::assertSame($before, $this->applications());
        self::assertSame($this->owner('opravne'), (int) $this->applications()[0]['applied_return_id']);
    }

    public function testFinalizeCasFailureRollsBackSnapshotsAndLossWritesInsideCallerTransaction(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $draft = $this->draft(80000); $before = $this->state();
        try {
            $this->returns->finalize($this->supplierId, 2049, 'po', $draft['return']['row_version'] + 10, $this->userId);
            self::fail('Expected real CAS conflict.');
        } catch (TaxReturnException $e) { self::assertSame('version_conflict', $e->errorCode); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
    }

    public function testReopenCasFailureRestoresOldOwnerAllocationInsideCallerTransaction(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $final = $this->finish($this->draft(80000)); $before = $this->state();
        try {
            $this->returns->reopen($this->supplierId, 2049, 'po', $final['return']['row_version'] + 10, $this->userId);
            self::fail('Expected real CAS conflict.');
        } catch (TaxReturnException $e) { self::assertSame('version_conflict', $e->errorCode); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->state());
    }

    public function testUnverifiedSameYearClaimIsNeverReleasedForNativeFinalization(): void
    {
        $losses = $this->losses(); $losses->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $losses->reconcileFinalize($this->supplierId, 'po', 2049, 0, 60000, null);
        $draft = $this->draft(100000); $before = $this->state();
        try { $this->finish($draft); self::fail('Unowned same-year application must remain protected.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_owner_unverified', $e->errorCode); }
        self::assertSame($before, $this->state());
    }

    public function testReplacementExcludesOldEffectiveOwnerAndKeepsOtherYearClaims(): void
    {
        $losses = $this->losses(); $losses->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $losses->reconcileFinalize($this->supplierId, 'po', 2050, 0, 40000, null);
        $first = $this->finish($this->draft(60000));
        $draft = $this->draft(80000, 'opravne'); $before = $this->state();
        try { $this->finish($draft, 'opravne'); self::fail('Another years consumption cannot be released.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_insufficient', $e->errorCode); }
        self::assertSame($before, $this->state());
        try {
            $losses->allocationPlan($this->supplierId, 'po', 2049, 60000, $this->owner('opravne'), $this->owner('opravne'));
            self::fail('New draft cannot impersonate the previous effective owner.');
        } catch (TaxReturnException $e) { self::assertSame('loss_allocation_owner_unverified', $e->errorCode); }
        self::assertSame($this->owner(), (int) $this->applications()[0]['applied_return_id']);
    }

    public function testNativeLossApplicationRejectsShortPeriodWithoutInferringCalendarYears(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($this->supplierId, 2048, '2048-01-01', '2048-06-30');
        $draft = $this->draft(80000); $before = $this->state();
        try { $this->finish($draft); self::fail('Shortened source period has no supported period-count proof.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_period_unsupported', $e->errorCode); }
        self::assertSame($before, $this->state());
        self::assertSame('final', $this->finish($this->draft(0))['return']['status']);
    }

    public function testMissingIntermediatePeriodProofCannotBecomeAStatutoryYearWindow(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2046, 100000);
        $draft = $this->draft(80000);
        try { $this->finish($draft); self::fail('Missing intermediate period evidence is unsupported.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_period_unsupported', $e->errorCode); }
        self::assertSame([], $this->applications());
    }

    public function testFiscalReturnWithoutLossDeductionDoesNotEnterUnsupportedAllocationPath(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET starts_on='2048-07-01',ends_on='2049-06-30' WHERE supplier_id=? AND fiscal_year=2049")->execute([$this->supplierId]);
        $final = $this->finish($this->draft(0));
        self::assertSame('final', $final['return']['status']);
        self::assertSame([], $this->applications());
    }

    public function testNative500FiscalYearLossReplacementAndReopenKeepLegacyUnknownPeriodProof(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET starts_on='2048-07-01',ends_on='2049-06-30' WHERE supplier_id=? AND fiscal_year=2049")->execute([$this->supplierId]);
        (new \ReflectionMethod($this->fixture,'postEntry'))->invoke($this->fixture,'2049-06-01',[['518','debit',2000000],['321','credit',2000000]]);
        $draft=$this->returns->getReturn($this->supplierId,2049,'po',$this->userId);self::assertLessThan(0,$draft['computed']['summary']['base']);
        $this->returns->finalize($this->supplierId,2049,'po',$draft['return']['row_version'],$this->userId);
        $corrective=$this->returns->getReturn($this->supplierId,2049,'po',$this->userId,'opravne');$corrective=$this->returns->finalize($this->supplierId,2049,'po',$corrective['return']['row_version'],$this->userId,'opravne');
        $reopened=$this->returns->reopen($this->supplierId,2049,'po',$corrective['return']['row_version'],$this->userId,'opravne');self::assertSame('draft',$reopened['return']['status']);
        $q=$this->db->pdo()->prepare('SELECT period_proof_kind,period_proof_hash,source_return_id FROM tax_losses WHERE supplier_id=? AND taxpayer_type="po" AND origin_year=2049');$q->execute([$this->supplierId]);self::assertSame(['period_proof_kind'=>null,'period_proof_hash'=>null,'source_return_id'=>$this->owner()],$q->fetch(\PDO::FETCH_ASSOC));
    }

    public function testNativeOriginLossCannotOverwriteAnotherYearsSourceOwner(): void
    {
        $foreignYear = (new TaxReturnRepository($this->db))->create($this->supplierId, 2048, 'po', [], $this->userId);
        $this->losses()->reconcileFinalize($this->supplierId, 'po', 2049, 100000, 0, (int) $foreignYear['id']);
        $draft = $this->draft(0); $before = $this->state();
        try { $this->finish($draft); self::fail('Origin loss owned by another year cannot be silently deleted.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_owner_unverified', $e->errorCode); }
        self::assertSame($before, $this->state());
    }

    public function testImportedOriginWithoutHistoricalPeriodProofReturnsTypedUnsupported(): void
    {
        $source = (new TaxReturnRepository($this->db))->create($this->supplierId, 2048, 'po', ['filed_source' => ['forma' => 'B', 'period_to' => '2048-12-31', 'file_sha1' => str_repeat('a', 40)]], $this->userId);
        $this->losses()->reconcileFinalize($this->supplierId, 'po', 2048, 100000, 0, (int) $source['id']);
        $draft = $this->draft(80000); $before = $this->state();
        try { $this->finish($draft); self::fail('Imported year-only loss does not prove its original taxable period.'); }
        catch (TaxReturnException $e) { self::assertSame('loss_allocation_period_unsupported', $e->errorCode); }
        self::assertSame($before, $this->state());
    }

    public function testFutureApplicationDoesNotRewriteHistoricalDppoAppendixCard(): void
    {
        $lossId = $this->nativeLoss(2024, 100000);
        $period = ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'];
        $before = $this->losses()->dppoAppendixCard($this->supplierId, 2025, $period, 20000, 0);
        $this->db->pdo()->prepare('INSERT INTO tax_loss_applications(supplier_id,taxpayer_type,loss_id,applied_year,applied_return_id,amount) VALUES(?,?,?,?,?,?)')
            ->execute([$this->supplierId, 'po', $lossId, 2026, null, 10000]);
        $after = $this->losses()->dppoAppendixCard($this->supplierId, 2025, $period, 20000, 0);

        self::assertSame($before, $after);
        self::assertSame('0', $after['rows'][0]['applied_before_cents']);
        self::assertSame('8000000', $after['rows'][0]['remaining_cents']);
    }

    public function testDppoAppendixUsesFutureNativeLossForCarrybackWithinSharedLimit(): void
    {
        $lossId = $this->nativeLoss(2026, 40000000);
        $this->db->pdo()->prepare('INSERT INTO tax_loss_applications(supplier_id,taxpayer_type,loss_id,applied_year,applied_return_id,amount) VALUES(?,?,?,?,?,?)')
            ->execute([$this->supplierId, 'po', $lossId, 2024, null, 5000000]);
        $card = $this->losses()->dppoAppendixCard(
            $this->supplierId,
            2025,
            ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
            25000000,
            0,
        );

        self::assertSame($lossId, $card['rows'][0]['loss_id']);
        self::assertSame('500000000', $card['rows'][0]['applied_before_cents']);
        self::assertSame('2500000000', $card['rows'][0]['applied_cents']);
        self::assertSame('1000000000', $card['rows'][0]['remaining_cents']);
        try {
            $this->losses()->dppoAppendixCard(
                $this->supplierId,
                2025,
                ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
                25000001,
                0,
            );
            self::fail('Carryback above the remaining statutory cap entered appendix E.');
        } catch (TaxReturnException $e) {
            self::assertSame('loss_allocation_insufficient', $e->errorCode);
        }
    }

    public function testFutureOpeningLossCannotFakeExactCarrybackPeriod(): void
    {
        $this->losses()->registerOpeningLoss($this->supplierId, 'po', 2026, 100000);
        try {
            $this->losses()->dppoAppendixCard(
                $this->supplierId,
                2025,
                ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
                50000,
                0,
            );
            self::fail('Carryback appendix accepted a year-only opening loss.');
        } catch (TaxReturnException $e) {
            self::assertSame('loss_appendix_period_proof_required', $e->errorCode);
        }
    }
    private function owner(string $variant = 'radne'): int
    { return (int) (new TaxReturnRepository($this->db))->find($this->supplierId, 2049, 'po', $variant)['id']; }

    private function losses(): TaxLossService
    { return new TaxLossService($this->db, new TaxConstantsRepository($this->db)); }

    private function nativeLoss(int $year, float $amount): int
    {
        $source = (new TaxReturnRepository($this->db))->create($this->supplierId, $year, 'po', [], $this->userId);
        $proof = TaxLossPeriodProof::native(
            $this->supplierId,
            'po',
            $year,
            ['starts_on' => sprintf('%04d-01-01', $year), 'ends_on' => sprintf('%04d-12-31', $year)],
            $amount,
            (int) $source['id'],
        );
        $this->db->pdo()->prepare('INSERT INTO tax_losses(supplier_id,taxpayer_type,origin_year,origin_starts_on,origin_ends_on,period_proof_kind,period_proof_hash,amount,source_return_id) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$this->supplierId, 'po', $year, $proof['starts_on'], $proof['ends_on'], $proof['kind'], $proof['proof_hash'], $amount, $source['id']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function draft(float $amount, string $variant = 'radne'): array
    {
        $draft = $this->returns->getReturn($this->supplierId, 2049, 'po', $this->userId, $variant);
        return $this->returns->saveInputs($this->supplierId, 2049, 'po', ['loss_carryforward' => $amount], $draft['return']['row_version'], $this->userId, $variant, partial: true);
    }

    private function finish(array $draft, string $variant = 'radne'): array
    { return $this->returns->finalize($this->supplierId, 2049, 'po', $draft['return']['row_version'], $this->userId, $variant); }

    private function applications(): array
    {
        $q = $this->db->pdo()->prepare('SELECT a.* FROM tax_loss_applications a WHERE supplier_id=? AND applied_year=2049 ORDER BY id');
        $q->execute([$this->supplierId]); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function amounts(): array
    {
        $q = $this->db->pdo()->prepare('SELECT l.origin_year,a.amount FROM tax_loss_applications a JOIN tax_losses l ON l.id=a.loss_id WHERE a.supplier_id=? AND applied_year=2049 ORDER BY l.origin_year');
        $q->execute([$this->supplierId]); return $q->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function state(): array
    {
        $out = [];
        foreach (['income_tax_returns', 'income_tax_return_snapshots', 'tax_losses', 'tax_loss_applications'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=? ORDER BY id');
            $q->execute([$this->supplierId]); $out[$table] = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
