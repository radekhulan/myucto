<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\{ClosingException, ClosingService};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;

final class ProfitDistribution504Test extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $source;
    private int $target;
    private array $catalog;
    private array $kinds;
    private ClosingService $service;
    private bool $committed = false;

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $pdo=$this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic result decision','Test 1','Test','10000','decision@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        foreach(['901','911','931','932','933','961','962'] as $code) {
            $pdo->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier,$code,'Synthetic '.$code]);
        }
        $periods=$c->get(AccountingPeriodRepository::class);
        $this->source=$periods->create($this->supplier,2024,'2024-07-01','2025-06-30');
        $this->target=$periods->create($this->supplier,2025,'2025-07-01','2026-06-30');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2024-07-01','cz_504')")->execute([$this->supplier]);
        $this->catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2025-07-01');
        foreach(['main','economic'] as $kind) {
            $pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$this->catalog['activity_kind']['id'],$this->supplier,'synthetic_'.$kind,'Synthetic '.$kind]);
            $this->kinds[$kind]=(int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE accounting_periods SET status='approved' WHERE id=?")->execute([$this->source]);
        $this->service=$c->get(ClosingService::class);
        $this->opening('main',100.01,'credit');
        $this->opening('economic',50.02,'debit');
    }

    protected function tearDown(): void
    {
        if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if($this->committed) {
            $pdo=$this->db->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
    }

    public function testOldSnapshotUsesCurrentSourceAmounts(): void { $this->oldSnapshot(false); }
    public function testSnapshotConflictAbortsWholeOuterTransaction(): void { $this->oldSnapshot(true); }

    private function oldSnapshot(bool $snapshotIsolation): void
    {
        $pdo=$this->db->pdo();
        $pdo->commit();
        $this->committed=true;
        $previous=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $other=Connection::withoutSharedTestConnection(static fn()=>Bootstrap::buildContainer()->get(Connection::class));
        try {
            self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),$other->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
            $pdo->exec('SET SESSION innodb_snapshot_isolation='.($snapshotIsolation?'ON':'OFF'));
            $pdo->beginTransaction();
            $preview=$this->service->profitDistributionPreview($this->supplier,$this->source);
            $body=$this->body($preview);
            $id=$preview['cells'][0]['source_line_ids'][0];
            $stmt=$other->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');
            $stmt->execute([$this->supplier,$id]);
            $entry=(int)$stmt->fetchColumn();
            $other->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND entry_id=?')->execute([$this->supplier,$entry]);
            try {
                $this->service->runProfitDistribution($this->supplier,$this->source,$body);
                self::fail('Old RR snapshot must not approve stale amounts.');
            } catch(ClosingException $e) {
                self::assertFalse($snapshotIsolation);
                self::assertSame('result_decision_stale',$e->errorCode);
                self::assertTrue($pdo->inTransaction());
            } catch(\PDOException $e) {
                self::assertTrue($snapshotIsolation);
                self::assertSame(1020,(int)$e->errorInfo[1]);
                self::assertFalse($pdo->inTransaction());
            }
            if($pdo->inTransaction()) $pdo->rollBack();
            self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
        } finally {
            if($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_snapshot_isolation='.$previous);
            $other->close();
        }
    }

    public function testRevertReadsFollowUpPostingAfterOldSnapshot(): void
    {
        $this->service->runProfitDistribution($this->supplier,$this->source,$this->body($this->service->profitDistributionPreview($this->supplier,$this->source)));
        $pdo=$this->db->pdo();
        $pdo->commit();
        $this->committed=true;
        $previous=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
            $pdo->beginTransaction();
            $preview=$this->service->profitDistributionPreview($this->supplier,$this->source);
            Connection::withoutSharedTestConnection(function (): void {
                Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier,'manual',null,[
                    ['account_code'=>'932','side'=>'debit','amount'=>1,'dimensions'=>$this->dims('main')],
                    ['account_code'=>'221','side'=>'credit','amount'=>1,'dimensions'=>$this->dims('main')],
                ],['entry_date'=>'2025-09-01','posted'=>true]);
            });
            try {
                $this->service->revertProfitDistribution($this->supplier,$this->source,$preview['target_period']['row_version']);
                self::fail('A newly posted use of the result must prevent reversal.');
            } catch(ClosingException $e) {
                self::assertSame('distribution_settled',$e->errorCode);
                self::assertTrue($pdo->inTransaction());
            }
            self::assertNotNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
        } finally {
            if($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_snapshot_isolation='.$previous);
        }
    }

    public function testActual504DecisionPreservesOppositeActivityBalancesAndReverts(): void
    {
        $preview=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $body=$this->body($preview);
        $posted=$this->service->runProfitDistribution($this->supplier,$this->source,$body);
        $stmt=$this->db->pdo()->prepare("SELECT a.account_code,l.side,l.amount,d.dimension_value_id FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id=l.account_id JOIN journal_entry_line_dimensions d ON d.line_id=l.id
            WHERE l.entry_id=? AND d.dimension_type_id=? ORDER BY l.id");
        $stmt->execute([$posted['entry_id'],$this->catalog['activity']['id']]);
        $rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(4,$rows);
        self::assertEqualsCanonicalizing(['931','931','932','932'],array_column($rows,'account_code'));
        self::assertEqualsCanonicalizing(['100.01','100.01','50.02','50.02'],array_column($rows,'amount'));
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        self::assertSame('cz_504',$after['accounting_framework']);
        self::assertEquals(49.99,$after['available_profit']);
        self::assertSame($posted['entry_id'],$this->service->runProfitDistribution($this->supplier,$this->source,$this->body($after))['entry_id']);
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $this->service->revertProfitDistribution($this->supplier,$this->source,$after['target_period']['row_version']);
        self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
        self::assertNull($this->service->profitDistributionPreview($this->supplier,$this->source)['decision']);
    }

    public function testReversedDecisionRestoresExactJointAvailability(): void
    {
        $before=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $posted=$this->service->runProfitDistribution($this->supplier,$this->source,$this->body($before));
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier,$posted['entry_id']);
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        self::assertEquals(array_column($before['cells'],'available_cents','key'),array_column($after['cells'],'available_cents','key'));
        self::assertEquals(49.99,$after['available_profit']);
        $reapplied=$this->service->runProfitDistribution($this->supplier,$this->source,$this->body($after));
        self::assertNotSame($posted['entry_id'],$reapplied['entry_id']);
        $stmt=$this->db->pdo()->prepare("SELECT SUM(IF(l.side='credit',l.signed_amount,-l.signed_amount)) FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id=l.account_id JOIN journal_entries e ON e.id=l.entry_id
            WHERE e.supplier_id=? AND e.period_id=? AND a.account_code='931' AND e.posted_at IS NOT NULL");
        $stmt->execute([$this->supplier,$this->target]);
        self::assertEquals(0,(float)$stmt->fetchColumn());
    }

    public function testReversedDecisionIsNotPresentedAsAnActiveDecision(): void
    {
        $posted=$this->service->runProfitDistribution($this->supplier,$this->source,$this->body($this->service->profitDistributionPreview($this->supplier,$this->source)));
        $reversal=Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->supplier,$posted['entry_id']);
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        self::assertNull($after['existing_entry_id']);
        self::assertNull($after['decision']);
        try {
            $this->service->revertProfitDistribution($this->supplier,$this->source,$after['target_period']['row_version']);
            self::fail('A reversed decision cannot be deleted.');
        } catch(ClosingException $e) {
            self::assertSame('not_found',$e->errorCode);
        }
        $stmt=$this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->supplier,$posted['entry_id']]);
        self::assertSame($reversal,(int)$stmt->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fundUseDates')]
    public function testConsumedFundCannotBeRemovedByRewritingTheDecision(string $useDate): void
    {
        $before=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $body=$this->body($before);
        foreach($body['allocations'] as &$allocation) {
            if($allocation['amount']===100.01) $allocation['role']='result.funds';
        }
        unset($allocation);
        $posted=$this->service->runProfitDistribution($this->supplier,$this->source,$body);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier,'manual',null,[
            ['account_code'=>'911','side'=>'debit','amount'=>80,'dimensions'=>$this->dims('main')],
            ['account_code'=>'221','side'=>'credit','amount'=>80,'dimensions'=>$this->dims('main')],
        ],['entry_date'=>$useDate,'posted'=>true]);
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $body['target_row_version']=$after['target_period']['row_version'];
        $body['snapshot_hash']=$after['snapshot_hash'];
        self::assertSame($posted['entry_id'],$this->service->runProfitDistribution($this->supplier,$this->source,$body)['entry_id']);
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        try {
            $this->service->runProfitDistribution($this->supplier,$this->source,$this->body($after));
            self::fail('A decision financing a consumed fund cannot be reassigned.');
        } catch(ClosingException $e) {
            self::assertSame('distribution_settled',$e->errorCode);
        }
        self::assertSame($posted['entry_id'],$this->service->profitDistributionPreview($this->supplier,$this->source)['existing_entry_id']);
        $stmt=$this->db->pdo()->prepare("SELECT SUM(IF(l.side='credit',l.signed_amount,-l.signed_amount)) FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id=l.account_id JOIN journal_entries e ON e.id=l.entry_id
            WHERE e.supplier_id=? AND e.period_id=? AND a.account_code='911' AND e.posted_at IS NOT NULL");
        $stmt->execute([$this->supplier,$this->target]);
        self::assertEquals(20.01,(float)$stmt->fetchColumn());
    }

    public static function fundUseDates(): array
    {
        return [['2025-08-01'],['2025-09-01']];
    }

    public function testStaleSnapshotAndUnfundedLossAreRejectedWithoutWrite(): void
    {
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        $body['snapshot_hash']=str_repeat('0',64);
        $this->reject($body,'result_decision_stale');
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        foreach($body['allocations'] as &$allocation) $allocation['role']='result.funds';
        unset($allocation);
        $this->reject($body,'insufficient_distributable_resources');
    }

    public function testDecisionAndFullCloseOpenKeepThreeJointAxesAnd932Transfer(): void
    {
        $this->service->runProfitDistribution($this->supplier,$this->source,$this->body($this->service->profitDistributionPreview($this->supplier,$this->source)));
        $posting=Bootstrap::buildContainer()->get(PostingService::class);
        foreach([['main','602','credit',150.0],['economic','518','debit',50.0]] as [$activity,$code,$side,$amount]) {
            $origins = array_fill_keys(array_keys($this->dims($activity)), ['origin' => 'explicit', 'ref' => null]);
            $posting->postDocument($this->supplier,'manual',null,[
                ['account_code'=>$code,'side'=>$side,'amount'=>$amount,'dimensions'=>$this->dims($activity),'dimension_origins'=>$origins],
                ['account_code'=>'221','side'=>$side==='debit'?'credit':'debit','amount'=>$amount,'dimensions'=>$this->dims($activity),'dimension_origins'=>$origins],
            ],['entry_date'=>'2025-10-01','posted'=>true]);
        }
        $rv=fn():int=>(int)Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->findById($this->supplier,$this->target)['row_version'];
        $this->service->start($this->supplier,$this->target,$rv());
        $items=[];
        foreach($this->service->inventoryPreview($this->supplier,$this->target)['rows'] as $row) {
            $items[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];
        }
        $this->service->saveInventory($this->supplier,$this->target,$rv(),['complete'=>true],$items);
        $this->service->runPrecheck($this->supplier,$this->target,$rv());
        $this->service->confirmStep($this->supplier,$this->target,'depreciation','skipped',null,$rv());
        $fx=$this->service->fxPreview($this->supplier,$this->target);
        $this->service->runFxRevaluation($this->supplier,$this->target,[],$rv(),[],['snapshot_hash'=>$fx['snapshot_hash']]);
        foreach(['estimates','deferrals','provisions','income_tax'] as $step) $this->service->confirmStep($this->supplier,$this->target,$step,'skipped',null,$rv());
        $actor = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm(Bootstrap::buildContainer(), $this->supplier, $this->target, $actor);
        $this->service->closeBooks($this->supplier,$this->target,$rv());
        $opened=$this->service->openNext($this->supplier,$this->target,$rv());
        $stmt=$this->db->pdo()->prepare("SELECT l.id,l.side,l.amount,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.id");
        $stmt->execute([$opened['entry_id']]);
        $lines=$stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotContains('932',array_column($lines,'account_code'));
        $dims=(new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->lineDimensions($this->supplier,array_column($lines,'id'));
        $balances=[];
        foreach($lines as $line) {
            if($line['account_code']!=='931') continue;
            self::assertCount(3,$dims[(int)$line['id']]);
            $activity=$dims[(int)$line['id']][$this->catalog['activity']['id']];
            $balances[$activity]=($balances[$activity]??0)+(int)round((float)$line['amount']*100)*($line['side']==='credit'?1:-1);
        }
        self::assertSame(25001,$balances[$this->catalog['activity']['values']['main']]);
        self::assertSame(-10002,$balances[$this->catalog['activity']['values']['economic']]);
    }

    public function testFundAndOwnEquityDecisionsUseExactJointResources(): void
    {
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier,'manual',null,[
            ['account_code'=>'911','side'=>'credit','amount'=>60,'dimensions'=>$this->dims('economic')],
            ['account_code'=>'221','side'=>'debit','amount'=>60,'dimensions'=>$this->dims('economic')],
        ],['entry_date'=>'2025-07-15','posted'=>true]);
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        $body['allocations']=[];
        foreach($this->service->profitDistributionPreview($this->supplier,$this->source)['cells'] as $cell) {
            if($cell['available']<0) {
                $body['allocations'][]=['cell_key'=>$cell['key'],'role'=>'result.funds','amount'=>50.02];
            } else {
                $body['allocations'][]=['cell_key'=>$cell['key'],'role'=>'result.own_equity','amount'=>60];
                $body['allocations'][]=['cell_key'=>$cell['key'],'role'=>'result.funds','amount'=>40.01];
            }
        }
        $result=$this->service->runProfitDistribution($this->supplier,$this->source,$body);
        $stmt=$this->db->pdo()->prepare('SELECT a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY a.account_code,l.side');
        $stmt->execute([$result['entry_id']]);
        $rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(6,$rows);
        self::assertContains(['account_code'=>'901','side'=>'credit','amount'=>'60.00'],$rows);
        self::assertContains(['account_code'=>'911','side'=>'credit','amount'=>'40.01'],$rows);
        self::assertContains(['account_code'=>'911','side'=>'debit','amount'=>'50.02'],$rows);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('analyticalLossResources')]
    public function testActualLossCoverageUsesTheAvailableAnalyticalAccount(string $role,string $code): void
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type,normal_side,is_active,is_synthetic,parent_id)
            SELECT supplier_id,?,'Synthetic analytical resource','equity','credit',1,0,id FROM chart_of_accounts WHERE supplier_id=? AND account_code=?")
            ->execute([$code,$this->supplier,substr($code,0,3)]);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier,'manual',null,[
            ['account_code'=>$code,'side'=>'credit','amount'=>60.03,'dimensions'=>$this->dims('economic')],
            ['account_code'=>'221','side'=>'debit','amount'=>60.03,'dimensions'=>$this->dims('economic')],
        ],['entry_date'=>'2025-07-15','posted'=>true]);
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        foreach($body['allocations'] as &$allocation) {
            if($allocation['amount']===50.02) $allocation['role']=$role;
        }
        unset($allocation);
        $result=$this->service->runProfitDistribution($this->supplier,$this->source,$body);
        $stmt=$pdo->prepare("SELECT a.account_code,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id
            WHERE l.entry_id=? AND l.side='debit' AND a.account_code LIKE CONCAT(?,'%')");
        $stmt->execute([$result['entry_id'],substr($code,0,3)]);
        self::assertSame([['account_code'=>$code,'amount'=>'50.02']],$stmt->fetchAll(\PDO::FETCH_ASSOC));
        $after=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $resources=array_values(array_filter($after['resources'],static fn(array $cell):bool=>$cell['account_code']===$code));
        self::assertCount(1,$resources);
        self::assertSame(6003,$resources[0]['available_cents']);
        $stmt=$pdo->prepare("SELECT SUM(CASE WHEN l.side='credit' THEN l.signed_amount ELSE -l.signed_amount END) FROM journal_entry_lines l
            JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id
            WHERE e.supplier_id=? AND e.period_id=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND a.account_code=?");
        $stmt->execute([$this->supplier,$this->target,$code]);
        self::assertSame(1001,(int)round((float)$stmt->fetchColumn()*100));
        $recorded=array_values(array_filter($after['decision']['allocations'],static fn(array $item):bool=>$item['role']===$role));
        self::assertSame($code,$recorded[0]['account_code']);
    }

    public static function analyticalLossResources(): array
    {
        return [['result.funds','911.100'],['result.own_equity','901.100']];
    }
    public function testMissingRequiredJointDimensionDoesNotWriteHeader(): void
    {
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionRuleService::class)->create($this->supplier,
            ['dimension_type_id'=>$this->catalog['activity']['id'],'account_mask'=>'9','enforcement'=>'error']);
        $this->db->pdo()->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id=? AND dimension_type_id=?')
            ->execute([$this->supplier,$this->catalog['activity']['id']]);
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        try {
            $this->service->runProfitDistribution($this->supplier,$this->source,$body);
            self::fail('Missing required source dimension cannot be guessed.');
        } catch(ClosingException $e) {
            self::assertSame('dimension_required',$e->errorCode);
        }
        self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
    }

    public function testOppositeUnresolvedJointBalancesBlockClosingEvenAtZeroNet(): void
    {
        $this->opening('economic',49.99,'debit');
        $periods=Bootstrap::buildContainer()->get(AccountingPeriodRepository::class);
        $checks=$this->service->runPrecheck($this->supplier,$this->target,(int)$periods->findById($this->supplier,$this->target)['row_version'])['checks'];
        $pending=array_values(array_filter($checks,static fn(array $check):bool=>$check['key']==='vh_431_undistributed'));
        self::assertCount(1,$pending);
        self::assertFalse($pending[0]['ok']);
        $period=$periods->findById($this->supplier,$this->target);
        $this->expectException(ClosingException::class);
        $this->service->start($this->supplier,$this->target,(int)$period['row_version']);
    }

    public function testRetiredSourceKindIsNotSilentlyReclassified(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$this->kinds['main']]);
        $preview=$this->service->profitDistributionPreview($this->supplier,$this->source);
        $this->reject($this->body($preview),'dimension_closed');
        self::assertContains($this->kinds['main'],array_map(fn(array $cell):int=>$cell['dimensions'][$this->catalog['activity_kind']['id']],$preview['cells']));
    }

    private function reject(array $body,string $code): void
    {
        try { $this->service->runProfitDistribution($this->supplier,$this->source,$body); self::fail('Invalid decision must fail.'); }
        catch(ClosingException $e) { self::assertSame($code,$e->errorCode); }
        self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
    }

    public function testMissingDecisionEvidenceDoesNotWriteJournal(): void
    {
        $body=$this->body($this->service->profitDistributionPreview($this->supplier,$this->source));
        unset($body['decision_reference']);
        try {
            $this->service->runProfitDistribution($this->supplier,$this->source,$body);
            self::fail('Decision evidence is required.');
        } catch(ClosingException $e) {
            self::assertSame('accounting_framework_operation_not_supported',$e->errorCode);
        }
        self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
    }

    private function body(array $preview): array
    {
        return ['decision_date'=>'2025-08-01','decision_reference'=>'SYN-DECISION-1','decision_authority'=>'Synthetic authorised body',
            'target_row_version'=>$preview['target_period']['row_version'],'snapshot_hash'=>$preview['snapshot_hash']??'',
            'allocations'=>isset($preview['cells']) ? array_map(static fn(array $cell):array=>[
                'cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>abs($cell['available'])],$preview['cells'])
                : [['account_code'=>'932','amount'=>49.99]]];
    }

    private function opening(string $activity,float $amount,string $side): void
    {
        $dims=$this->dims($activity);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplier,'opening',null,[
            ['account_code'=>'931','side'=>$side,'amount'=>$amount,'dimensions'=>$dims],
            ['account_code'=>'221','side'=>$side==='debit'?'credit':'debit','amount'=>$amount,'dimensions'=>$dims],
        ],['entry_date'=>'2025-07-01','posted'=>true]);
    }

    private function dims(string $activity): array
    {
        return [$this->catalog['activity']['id']=>$this->catalog['activity']['values'][$activity],
            $this->catalog['activity_kind']['id']=>$this->kinds[$activity],
            $this->catalog['tax_regime']['id']=>$this->catalog['tax_regime']['values']['subject']];
    }
}
