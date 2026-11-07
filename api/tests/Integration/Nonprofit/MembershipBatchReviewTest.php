<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Regrese review dávky; samostatná syntetická fixture nezasahuje do paralelních testů. */
final class MembershipBatchReviewTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;
    private array $classification;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private MembershipBatchService $batches;
    private array $template;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic batch review association','Synthetic street','Synthetic city','10000','batch-review@example.test',203,1,?,'double_entry')")->execute([$vat]);
            $this->supplier = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $this->pdo->lastInsertId(), $this->supplier]);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic batch actor','admin','cs',1)")
            ->execute(['batch-review-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'BATCH-REVIEW','Synthetic batch activity')")->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'], 'activity_kind_value_id' => (int) $this->pdo->lastInsertId(), 'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, ['expected_revision' => 0, 'idempotency_key' => 'batch-review-legal', 'reason' => 'Synthetic reviewed statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed statutes']], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $container->get(PostingService::class));
        $this->batches = new MembershipBatchService($this->db, $this->charges);
        $rate = (new MembershipRateRepository($this->db))->save($this->supplier, null, ['name' => 'Synthetic annual rate', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_key' => 'synthetic-review',
            'period_months' => 12, 'title_reference' => 'Synthetic decision', 'evidence' => 'Synthetic signed decision', 'classification' => $this->classification, 'is_active' => 1], 0);
        $this->template = ['rate_id' => $rate['id'], 'title_key' => 'synthetic-review', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'classification' => $this->classification,
            'title_reference' => 'Synthetic decision', 'evidence' => 'Synthetic signed decision', 'reason' => 'Synthetic batch charge', 'nonreciprocal' => true];
    }

    protected function tearDown(): void
    {
        BatchReviewFailureStatement::$fail = null;
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testFinancialOverridesAndWrongCalendarLengthAre422(): void
    {
        $member = $this->member('FINANCE');
        foreach (['amount' => '501.00', 'receivable_account' => '315.999', 'revenue_account' => '684.999', 'period_to' => '2026-11-30', 'period_from' => '2026-01-02'] as $field => $value) {
            $input = $this->input([$member], 'invalid-' . $field);
            $input['template'][$field] = $value;
            try { $this->batches->create($this->supplier, $input, $this->actor); self::fail('Dávka přijala odchylku: ' . $field); }
            catch (MembershipException $e) { self::assertSame(422, $e->httpStatus, $field); }
        }
        self::assertSame(0, $this->operationCount());
    }

    public function testMissingFinancialFieldsComeFromServerRate(): void
    {
        $input = $this->input([$this->member('SERVER')]);
        foreach (['amount', 'receivable_account', 'revenue_account', 'period_to'] as $field) unset($input['template'][$field]);
        $created = $this->batches->create($this->supplier, $input, $this->actor);
        foreach (['amount', 'receivable_account', 'revenue_account', 'period_to'] as $field) self::assertSame($this->template[$field], $created['batch']['template'][$field]);
        $internal = (new MembershipBatchRepository($this->db))->find($this->supplier, $created['batch']['id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $internal['batch_uid']);
        self::assertArrayNotHasKey('batch_uid', $created['batch']);
    }

    public function testBatchRejectsRateWithoutFixedCalendarPeriodBeforeCreatingAnything(): void
    {
        $repo=new MembershipRateRepository($this->db);
        $rate=$repo->find($this->supplier,$this->template['rate_id']);
        $rate['period_months']=0;
        $repo->save($this->supplier,$rate['id'],$rate,$rate['row_version']);
        try {
            $this->batches->create($this->supplier,$this->input([$this->member('MANUAL-PERIOD')]),$this->actor);
            self::fail('Ruční délka sazby nesmí vytvořit dávku.');
        } catch (MembershipException $e) {
            self::assertSame(422,$e->httpStatus);
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_membership_batches WHERE supplier_id=?');$q->execute([$this->supplier]);
            self::assertSame(0,(int)$q->fetchColumn());
        }
    }

    public function testExpiryRequiresNewPreviewAndCancelRevokesToken(): void
    {
        $batch = $this->ready([$this->member('EXPIRE')]);
        $id = $batch['batch']['id']; $old = $batch['batch']['approval_token'];
        $this->pdo->prepare('UPDATE nonprofit_membership_batches SET approval_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE supplier_id=? AND id=?')->execute([$this->supplier, $id]);
        $this->assertConfirmRejected($id, $old);
        $renewed = $this->batches->renewPreview($this->supplier, $id, $this->actor);
        self::assertSame('preparing', $renewed['batch']['phase']);
        self::assertNull($renewed['batch']['approval_token']);
        $ready = $this->batches->prepare($this->supplier, $id);
        self::assertNotSame($old, $ready['batch']['approval_token']);
        $this->assertConfirmRejected($id, $old);
        self::assertSame('cancelled', $this->batches->cancel($this->supplier, $id, $this->actor)['batch']['phase']);
        $this->assertConfirmRejected($id, $ready['batch']['approval_token']);
        self::assertSame(0, $this->operationCount());
        $this->assertCounters($id);
    }

    public function testCancellationBeforePreparationAndDuringPostingPreservesCompletedMembers(): void
    {
        $first = $this->batches->create($this->supplier, $this->input([$this->member('CANCEL-PREP')]), $this->actor);
        $id = $first['batch']['id'];
        $this->batches->cancel($this->supplier, $id, $this->actor);
        self::assertSame('cancelled', $this->batches->prepare($this->supplier, $id)['batch']['phase']);
        $ids = [];
        for ($i = 0; $i < 11; ++$i) $ids[] = $this->member('CANCEL-' . $i);
        $ready = $this->ready($ids, 'partial'); $id = $ready['batch']['id'];
        $partial = $this->batches->confirm($this->supplier, $id, $ready['batch']['approval_token'], $this->actor);
        self::assertSame('posting', $partial['batch']['phase']);
        self::assertSame(10, $partial['summary']['created']);
        $cancelled = $this->batches->cancel($this->supplier, $id, $this->actor);
        self::assertSame(10, $cancelled['summary']['created']);
        $this->assertConfirmRejected($id, $ready['batch']['approval_token']);
        self::assertSame(10, $this->operationCount());
        $this->assertCounters($id);
    }

    public function testEveryConfirmIsAuditedAndFirstActorStaysStable(): void
    {
        $ids = [];
        for ($i = 0; $i < 11; ++$i) $ids[] = $this->member('ACTOR-' . $i);
        $ready = $this->ready($ids); $id = $ready['batch']['id']; $token = $ready['batch']['approval_token'];
        $first = $this->batches->confirm($this->supplier, $id, $token, $this->actor);
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES('batch-review-next@example.test','synthetic-unused','Synthetic next actor','admin','cs',1)")->execute();
        $nextActor = (int) $this->pdo->lastInsertId();
        $done = $this->batches->confirm($this->supplier, $id, $token, $nextActor);
        self::assertSame('finished', $done['batch']['phase']);
        self::assertSame($this->actor, $done['batch']['confirmed_by']);
        self::assertNotNull($done['batch']['confirmed_at']);
        self::assertSame($first['batch']['confirmed_at'], $done['batch']['confirmed_at']);
        $q = $this->pdo->prepare("SELECT user_id,payload FROM activity_log WHERE supplier_id=? AND entity_id=? AND action='nonprofit_membership_batch_confirm' ORDER BY id");
        $q->execute([$this->supplier, $id]); $audit = $q->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([$this->actor, $nextActor], array_map('intval', array_column($audit, 'user_id')));
        $q = $this->pdo->prepare('SELECT DISTINCT created_by FROM nonprofit_membership_charge_operations WHERE supplier_id=?');
        $q->execute([$this->supplier]); self::assertSame([$this->actor], array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertCounters($id);
    }

    public function testCommittedFinancialOperationReplaysAfterOutcomeGapAndRenewal(): void
    {
        $member = $this->member('GAP'); $ready = $this->ready([$member]); $id = $ready['batch']['id'];
        $repo = new MembershipBatchRepository($this->db);
        $repo->beginPosting($this->supplier, $id, $ready['batch']['approval_token'], $this->actor);
        $item = $repo->workPage($this->supplier, $id, 'ready', 1)[0];
        $issued = $this->charges->confirmIssue($this->supplier, ['member_id' => $member] + $ready['batch']['template'], (int) $item['expected_case_version'], $item['preview_hash'], MembershipBatchService::memberKey($repo->find($this->supplier, $id), $member), $this->actor);
        $renewed = $this->batches->renewPreview($this->supplier, $id, $this->actor);
        self::assertSame(1, $renewed['summary']['created']);
        self::assertSame($issued['operation']['id'], $renewed['items'][0]['operation_id']);
        $next = $this->batches->prepare($this->supplier, $id);
        $done = $this->batches->confirm($this->supplier, $id, $next['batch']['approval_token'], $this->actor);
        self::assertSame('finished', $done['batch']['phase']);
        self::assertSame(1, $this->operationCount());
        $this->assertCounters($id);
    }

    public function testCommittedFinancialOperationReplaysDirectlyWithoutAnotherPosting(): void
    {
        $member = $this->member('DIRECT-GAP'); $ready = $this->ready([$member]); $id = $ready['batch']['id'];
        $repo = new MembershipBatchRepository($this->db);
        $repo->beginPosting($this->supplier, $id, $ready['batch']['approval_token'], $this->actor);
        $item = $repo->workPage($this->supplier, $id, 'ready', 1)[0];
        $issued = $this->charges->confirmIssue($this->supplier, ['member_id' => $member] + $ready['batch']['template'], (int) $item['expected_case_version'], $item['preview_hash'], MembershipBatchService::memberKey($repo->find($this->supplier, $id), $member), $this->actor);
        $done = $this->batches->confirm($this->supplier, $id, $ready['batch']['approval_token'], $this->actor);
        self::assertSame('finished', $done['batch']['phase']); self::assertSame(1, $done['summary']['created']);
        self::assertSame($issued['operation']['id'], $done['items'][0]['operation_id']);
        self::assertSame(1, $this->operationCount());
        self::assertSame($done['summary'], $this->batches->confirm($this->supplier, $id, $ready['batch']['approval_token'], $this->actor)['summary']);
        $this->assertCounters($id);
    }

    public function testAbortedOuterTransactionPropagatesToItsOwner(): void
    {
        $ready = $this->ready([$this->member('ABORT')]);
        $previous = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        BatchReviewFailureStatement::$fail = static function (string $sql): void {
            if (!str_contains($sql, 'INSERT INTO nonprofit_membership_charge_operations')) return;
            $failure = new \PDOException('Synthetic deadlock');
            $failure->errorInfo = ['40001', 1213, 'Synthetic deadlock'];
            throw $failure;
        };
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BatchReviewFailureStatement::class]);
        try {
            $this->batches->confirm($this->supplier, $ready['batch']['id'], $ready['batch']['approval_token'], $this->actor);
            self::fail('Odvolaná cizí transakce se nesmí převést na rejected.');
        } catch (\PDOException $e) {
            self::assertSame(1213, (int) $e->errorInfo[1]);
            self::assertFalse($this->pdo->inTransaction());
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous); BatchReviewFailureStatement::$fail = null;
        }
    }

    public function testUnknownThrowableRejectsOneMemberAndContinuesAfterRollback(): void
    {
        $ids = [$this->member('FAULT-FIRST'), $this->member('FAULT-MIDDLE'), $this->member('FAULT-LAST')];
        $ready = $this->ready($ids); $id = $ready['batch']['id'];
        $previous = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        BatchReviewFailureStatement::$fail = static function (string $sql, ?array $params) use ($ids): void {
            if (str_contains($sql, 'INSERT INTO nonprofit_membership_charge_operations') && (int) (json_decode($params[18], true, flags: JSON_THROW_ON_ERROR)['member']['id'] ?? 0) === $ids[1]) throw new \RuntimeException('Synthetic permanent middle failure');
        };
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BatchReviewFailureStatement::class]);
        try { $done = $this->batches->confirm($this->supplier, $id, $ready['batch']['approval_token'], $this->actor); }
        finally { $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous); BatchReviewFailureStatement::$fail = null; }
        self::assertSame('finished', $done['batch']['phase']);
        self::assertSame(2, $done['summary']['created']); self::assertSame(1, $done['summary']['rejected']);
        self::assertSame('nonprofit_membership_batch_technical', $done['items'][1]['error_code']);
        self::assertSame(2, $this->operationCount());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_cases WHERE supplier_id=' . $this->supplier)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=" . $this->supplier . " AND source_type='nonprofit_membership'")->fetchColumn());
        $this->assertCounters($id);
    }

    public function testReadDetailNeverExposesWriteTokenOrInternalSelectionAndKeys(): void
    {
        $ready = $this->ready([$this->member('DTO')]); $id = $ready['batch']['id'];
        $read = $this->batches->detail($this->supplier, $id);
        $write = $this->batches->detail($this->supplier, $id, write: true);
        self::assertSame('selected', $read['batch']['selection_mode']);
        self::assertSame('selected', $write['batch']['selection_mode']);
        self::assertArrayNotHasKey('approval_token', $read['batch']);
        self::assertSame($ready['batch']['approval_token'], $write['batch']['approval_token']);
        self::assertSame($this->supplier, $read['batch']['supplier_id']);
        self::assertSame($this->supplier, $write['batch']['supplier_id']);
        foreach (['idempotency_key', 'request_hash', 'batch_uid', 'member_ids', 'selection_json', 'seed_cursor', 'seed_until'] as $key) {
            self::assertArrayNotHasKey($key, $read['batch']); self::assertArrayNotHasKey($key, $write['batch']);
        }
    }

    public function testSeedPersistsOne500MemberPageAndBoundsSelectionByInitialHighWaterMark(): void
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]); $currency = (int) $q->fetchColumn();
        $rows = [];
        for ($i = 0; $i < 501; ++$i) $rows[] = [$this->supplier, 'Synthetic seed member ' . $i, 'Synthetic street', 'Synthetic city', '10000', 203, 'seed-' . $i . '@example.test', 'cs', $currency, 1, 0];
        $this->pdo->prepare('INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES' . implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?)')))->execute(array_merge(...$rows));
        $this->pdo->prepare("INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by,created_at,recorded_at_utc) SELECT supplier_id,id,CONCAT('SYN-SEED-',id),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM clients WHERE supplier_id=?")->execute([$this->actor, $this->supplier]);
        $input = ['template' => $this->template, 'selection_mode' => 'active', 'member_ids' => [], 'idempotency_key' => 'seed-cursor'];
        $first = $this->batches->create($this->supplier, $input, $this->actor); $id = $first['batch']['id'];
        self::assertSame('seeding', $first['batch']['phase']); self::assertSame(500, $first['summary']['total']);
        self::assertSame(500, $first['summary']['rejected'], 'Neprůkazná historie nesmí tiše zmizet z výběru.');
        $repo = new MembershipBatchRepository($this->db); $cursor = $repo->find($this->supplier, $id)['seed_cursor'];
        $newMember = $this->member('AFTER-HIGH-WATER');
        $resumed = (new MembershipBatchService($this->db, $this->charges))->prepare($this->supplier, $id);
        self::assertSame('preparing', $resumed['batch']['phase']); self::assertSame(501, $resumed['summary']['total']);
        self::assertGreaterThan($cursor, $repo->find($this->supplier, $id)['seed_cursor']);
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?'); $q->execute([$this->supplier, $id, $newMember]);
        self::assertSame(0, (int) $q->fetchColumn());
        $this->assertCounters($id);
    }

    public function testRejectedMemberKeepsItsOwnAmountAndPreparedReplayDoesNotDoubleCounters(): void
    {
        $ids = [$this->member('AMOUNT-FIRST'), $this->member('AMOUNT-SECOND')];
        $created = $this->batches->create($this->supplier, $this->input($ids), $this->actor); $id = $created['batch']['id'];
        $this->pdo->prepare('UPDATE nonprofit_membership_batch_members SET amount_cents=76543 WHERE supplier_id=? AND batch_id=? AND member_id=?')->execute([$this->supplier, $id, $ids[1]]);
        $this->members->appendRevision($this->supplier, $ids[1], ['status' => 'suspended', 'effective_from' => '2026-07-01', 'expected_revision' => 1, 'idempotency_key' => 'review-suspend', 'legal_basis' => $this->basis()], $this->actor);
        $prepared = $this->batches->prepare($this->supplier, $id);
        self::assertSame(76543, $prepared['items'][1]['amount_cents']);
        self::assertSame('rejected', $prepared['items'][1]['status']);
        self::assertSame($prepared['summary'], $this->batches->prepare($this->supplier, $id)['summary']);
        $this->assertCounters($id);
    }

    public function testSeedLimitAndForeignSelectionAre422(): void
    {
        $ids = [$this->member('LIMIT-FIRST'), $this->member('LIMIT-SECOND')];
        $limited = new MembershipBatchService($this->db, $this->charges, maximumMembers: 1);
        try { $limited->create($this->supplier, ['template' => $this->template, 'selection_mode' => 'active', 'member_ids' => [], 'idempotency_key' => 'limit-active'], $this->actor); self::fail('Seed překročil limit.'); }
        catch (MembershipException $e) { self::assertSame(422, $e->httpStatus); self::assertSame('nonprofit_membership_batch_limit', $e->errorCode); }
        try { $this->batches->create($this->supplier, $this->input([$ids[0], PHP_INT_MAX], 'foreign'), $this->actor); self::fail('Cizí člen byl přijat.'); }
        catch (MembershipException $e) { self::assertSame(422, $e->httpStatus); self::assertSame('nonprofit_membership_batch_selection_invalid', $e->errorCode); }
    }

    private function member(string $number): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?'); $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic batch member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")->execute([$this->supplier, strtolower($number) . '@example.test', (int) $q->fetchColumn()]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), 'SYN-' . $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0, 'idempotency_key' => 'review-' . $number, 'legal_basis' => $this->basis()], $this->actor);
        return $member['id'];
    }

    private function basis(): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership', 'reference' => 'Synthetic statutes', 'evidence' => 'Synthetic signed membership evidence'];
    }

    private function input(array $ids, string $key = 'review-batch'): array
    {
        return ['template' => $this->template, 'selection_mode' => 'selected', 'member_ids' => $ids, 'idempotency_key' => $key];
    }

    private function ready(array $ids, string $key = 'review-batch'): array
    {
        $created = $this->batches->create($this->supplier, $this->input($ids, $key), $this->actor);
        return $this->batches->prepare($this->supplier, $created['batch']['id']);
    }

    private function operationCount(): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=?'); $q->execute([$this->supplier]);
        return (int) $q->fetchColumn();
    }

    private function assertConfirmRejected(int $id, string $token): void
    {
        try { $this->batches->confirm($this->supplier, $id, $token, $this->actor); self::fail('Neplatný souhlas byl přijat.'); }
        catch (MembershipException $e) { self::assertSame(409, $e->httpStatus); }
    }

    private function assertCounters(int $id): void
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='ready'),0) ready,COALESCE(SUM(status='created'),0) created,COALESCE(SUM(status='existing'),0) existing,COALESCE(SUM(status='rejected'),0) rejected,
            CAST(COALESCE(SUM(IF(status IN ('ready','created'),amount_cents,0)),0) AS CHAR) planned_cents,CAST(COALESCE(SUM(IF(status='created',amount_cents,0)),0) AS CHAR) created_cents,
            CAST(COALESCE(SUM(IF(status IN ('ready','created'),deferred_cents,0)),0) AS CHAR) deferred_cents,MIN(IF(status IN ('ready','created') AND deferred_cents>0,deferral_date,NULL)) deferral_date
            FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=?");
        $q->execute([$this->supplier, $id]); $expected = $q->fetch(PDO::FETCH_ASSOC);
        foreach (['total', 'pending', 'ready', 'created', 'existing', 'rejected'] as $field) $expected[$field] = (int) $expected[$field];
        self::assertSame($expected, (new MembershipBatchRepository($this->db))->summary($this->supplier, $id));
    }
}

/** Výjimka v reálném SQL toku; rollback a pokračování zajišťuje produkční služba. */
final class BatchReviewFailureStatement extends PDOStatement
{
    public static ?\Closure $fail = null;

    public function execute(?array $params = null): bool
    {
        if (self::$fail !== null) (self::$fail)($this->queryString, $params);
        return parent::execute($params);
    }
}
