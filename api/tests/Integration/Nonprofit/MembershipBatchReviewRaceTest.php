<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use PDO;
use PDOStatement;
use Psr\Log\AbstractLogger;

final class MembershipBatchReviewRaceTest extends MembershipBatchReviewFixture
{
    public function testCreateReplaySurvivesRateEditAndRetirementButChangedBodyConflicts(): void
    {
        $input = $this->input([$this->member('REPLAY-RATE')]);
        $created = $this->batches->create($this->supplier, $input, $this->actor);
        $rates = new MembershipRateRepository($this->db);
        $rate = $rates->find($this->supplier, $this->template['rate_id']);
        $rate['amount'] = '750.00';
        $rate = $rates->save($this->supplier, $rate['id'], $rate, $rate['row_version']);
        self::assertSame($created, $this->batches->create($this->supplier, $input, $this->actor));
        $rate['is_active'] = 0;
        $rates->save($this->supplier, $rate['id'], $rate, $rate['row_version']);
        self::assertSame($created, $this->batches->create($this->supplier, $input, $this->actor));
        $changed = $input; $changed['template']['reason'] = 'Synthetic different decision';
        try { $this->batches->create($this->supplier, $changed, $this->actor); self::fail('Stejný klíč přijal jiné tělo.'); }
        catch (MembershipException $e) { self::assertSame('nonprofit_membership_idempotency_conflict', $e->errorCode); self::assertSame(409, $e->httpStatus); }
        try { $this->batches->create($this->supplier, $input, $this->actor + 1); self::fail('Stejný klíč přijal jiného aktéra.'); }
        catch (MembershipException $e) { self::assertSame('nonprofit_membership_idempotency_conflict', $e->errorCode); }
        $input['idempotency_key'] = 'new-retired-rate';
        try { $this->batches->create($this->supplier, $input, $this->actor); self::fail('Nová dávka přijala neaktivní sazbu.'); }
        catch (MembershipException $e) { self::assertSame('nonprofit_membership_rate_unavailable', $e->errorCode); self::assertSame(422, $e->httpStatus); }
        self::assertSame(0, $this->operationCount());
    }

    public function testOldFailureCannotRejectOrIncrementAttemptsInRenewedPostingGeneration(): void
    {
        $member = $this->member('GENERATION'); $ready = $this->ready([$member]); $id = $ready['batch']['id'];
        $oldToken = $ready['batch']['approval_token']; $newToken = null;
        $logger = new BatchRaceCallbackLogger(function () use ($id, &$newToken): void {
            // Hook se volá po návratu z transakčního guardu: původní práce je vrácena zpět.
            self::assertSame(0, $this->operationCount());
            BatchRaceFailureStatement::$enabled = false;
            $this->batches->renewPreview($this->supplier, $id, $this->actor);
            $renewed = $this->batches->prepare($this->supplier, $id);
            $newToken = $renewed['batch']['approval_token'];
            // B právě přijal T2 a před zpracováním prvního člena přenechal běh A.
            (new MembershipBatchRepository($this->db))->beginPosting($this->supplier, $id, $newToken, $this->actor);
        });
        $service = new MembershipBatchService($this->db, $this->charges, logger: $logger);
        $this->withFailure(fn () => $service->confirm($this->supplier, $id, $oldToken, $this->actor));
        self::assertTrue($logger->called); self::assertNotSame($oldToken, $newToken);
        $repo = new MembershipBatchRepository($this->db); $row = $repo->workPage($this->supplier, $id, 'ready', 1)[0];
        self::assertSame(0, (int) $row['attempts']); self::assertNull($row['error_code']);
        self::assertSame(1, $repo->summary($this->supplier, $id)['ready']);
        self::assertSame(0, $repo->summary($this->supplier, $id)['rejected']);
        $done = $this->batches->confirm($this->supplier, $id, $newToken, $this->actor);
        self::assertSame(1, $done['summary']['created']); self::assertSame(1, $this->operationCount());
        $this->assertCounters($id);
    }

    public function testFailureRequiresMatchingItemPreviewAndVersionEvenWithSameToken(): void
    {
        $member = $this->member('PREVIEW-RACE'); $ready = $this->ready([$member]); $id = $ready['batch']['id'];
        $logger = new BatchRaceCallbackLogger(function () use ($id, $member): void {
            BatchRaceFailureStatement::$enabled = false;
            $this->pdo->prepare('UPDATE nonprofit_membership_batch_members SET preview_hash=?,expected_case_version=expected_case_version+1 WHERE supplier_id=? AND batch_id=? AND member_id=?')->execute([str_repeat('a', 64), $this->supplier, $id, $member]);
        });
        $service = new MembershipBatchService($this->db, $this->charges, logger: $logger);
        $this->withFailure(fn () => $service->confirm($this->supplier, $id, $ready['batch']['approval_token'], $this->actor));
        self::assertTrue($logger->called);
        $row = (new MembershipBatchRepository($this->db))->workPage($this->supplier, $id, 'ready', 1)[0];
        self::assertSame(str_repeat('a', 64), $row['preview_hash']); self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['error_code']); $this->assertCounters($id);
    }

    private function withFailure(\Closure $run): void
    {
        $previous = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        BatchRaceFailureStatement::$enabled = true;
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BatchRaceFailureStatement::class]);
        try { $run(); }
        finally { BatchRaceFailureStatement::$enabled = false; $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous); }
    }
}

/** Proložení po rollbacku nevyžaduje časování dvou vláken ani měnící se produkční data. */
final class BatchRaceCallbackLogger extends AbstractLogger
{
    public bool $called = false;

    public function __construct(private readonly \Closure $callback) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->called) return;
        $this->called = true;
        ($this->callback)();
    }
}

final class BatchRaceFailureStatement extends PDOStatement
{
    public static bool $enabled = false;

    public function execute(?array $params = null): bool
    {
        if (self::$enabled && str_contains($this->queryString, 'INSERT INTO nonprofit_membership_charge_operations')) throw new \RuntimeException('Synthetic old confirmation failure');
        return parent::execute($params);
    }
}
