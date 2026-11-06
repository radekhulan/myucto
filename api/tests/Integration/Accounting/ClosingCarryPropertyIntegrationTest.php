<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,JournalEntryRepository};
use MyInvoice\Service\Accounting\Closing\{ClosingService,ClosingCarryJournalProof,ClosingException};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\PropertyDimensionPolicy;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,NonprofitTaxAssessmentFixture};
use PHPUnit\Framework\TestCase;

final class ClosingCarryPropertyIntegrationTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private $container;
    private ClosingService $closing;
    private int $period;
    private int $entry;
    private array $tuples=[];
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
        $this->closing=$this->container->get(ClosingService::class);
        $q=$this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=2026');$q->execute([$this->f['supplier']]);$this->period=(int)$q->fetchColumn();
        $cat=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->f['supplier'],'2026-02-01');
        $q=$this->db->pdo()->prepare('SELECT o.id,o.dimension_value_id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id WHERE o.supplier_id=?');$q->execute([$this->f['supplier']]);$objects=array_column($q->fetchAll(\PDO::FETCH_ASSOC),null,'id');
        $lines=[];
        foreach(array_slice(array_values($this->f['units']),0,2)as $i=>$unit){
            $obj=$objects[$unit];$service=$objects[$this->f['service']['id']];
            $dims=[$cat['activity']['id']=>$cat['activity']['values']['main'],(int)$obj['type_id']=>(int)$obj['dimension_value_id'],(int)$service['type_id']=>(int)$service['dimension_value_id']];ksort($dims);$this->tuples[]=$dims;
            foreach([['221','debit'],['324','credit']] as [$account,$side])$lines[]=['account_code'=>$account,'side'=>$side,'amount'=>$i===0?60.0:40.0,'currency_code'=>'EUR','amount_foreign'=>$i===0?2.4:1.6,'fx_rate'=>25.0,'dimensions'=>$dims];
        }
        $this->entry=$this->container->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,$lines,['entry_date'=>'2026-02-01','posted'=>true]);
    }
    protected function tearDown():void {if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function rv(int $period):int{$q=$this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$period]);return(int)$q->fetchColumn();}
    private function retire():void
    {
        $ids=array_merge(...array_map('array_values',$this->tuples));
        $q=$this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([$this->f['supplier'],...$ids]);
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->f['supplier'],['expected_revision'=>1,'effective_from'=>'2027-01-01T00:00:00+01:00','effective_to'=>null,'enabled'=>false,'reason'=>'Synthetic closed activity','title_ref'=>'Synthetic decision','idempotency_key'=>'disabled-carry'],$this->f['actor']);
    }
    private function close(int $period):array
    {
        $supplier=$this->f['supplier'];$meta=['user_id'=>$this->f['actor'],'posted_by'=>$this->f['actor']];
        $this->closing->start($supplier,$period,$this->rv($period),$meta);$items=[];
        foreach($this->closing->inventoryPreview($supplier,$period)['rows']as $r)$items[(int)$r['account_id']]=['counted_balance'=>(float)$r['book_balance'],'resolution'=>'resolved','note'=>null];
        $this->closing->saveInventory($supplier,$period,$this->rv($period),['complete'=>true],$items,$meta);
        $this->closing->runPrecheck($supplier,$period,$this->rv($period),$meta);
        $this->closing->confirmStep($supplier,$period,'depreciation','skipped',null,$this->rv($period),$meta);
        $end=$this->db->pdo()->query('SELECT ends_on FROM accounting_periods WHERE id='.$period)->fetchColumn();
        $this->db->pdo()->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,'EUR',25) ON DUPLICATE KEY UPDATE rate=25")->execute([$end]);
        $initial=$this->closing->fxPreview($supplier,$period);
        $accounts=array_filter($initial['positions'],static fn(array $p):bool=>$p['account_code']==='324')?['324'=>['classification'=>'nonmonetary','reason'=>'Synthetic documented advance']]:[];
        $fx=$this->closing->fxPreview($supplier,$period,[],$accounts);
        $this->closing->runFxRevaluation($supplier,$period,[],$this->rv($period),$meta,['snapshot_hash'=>$fx['snapshot_hash'],'account_decisions'=>$accounts]);
        foreach(['estimates','deferrals','provisions','income_tax']as $step)$this->closing->confirmStep($supplier,$period,$step,'skipped',null,$this->rv($period),$meta);
        $this->closing->runStockValuation($supplier,$period,$this->rv($period),$meta);
        NonprofitTaxAssessmentFixture::confirm($this->container,$supplier,$period,$this->f['actor']);
        return $this->closing->closeBooks($supplier,$period,$this->rv($period),$meta);
    }
    private function proof():ClosingCarryJournalProof{return ClosingCarryJournalProof::forPending($this->db,$this->f['supplier'],$this->period,'close_books',$this->rv($this->period)-1);}
    private function expectedFailure(callable $call):void
    {
        try{$call();self::fail('Unverified carry accepted.');}catch(ClosingException $e){self::assertSame('closing_carry_changed',$e->errorCode);}
    }
    private function assertBalances(int $entry):void
    {
        $q=$this->db->pdo()->prepare('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND l.supplier_id=? ORDER BY l.line_no');$q->execute([$entry,$this->f['supplier']]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        $dims=(new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($this->f['supplier'],$entry,true);$found=[];
        foreach($rows as $r)if(in_array($r['account_code'],['221','324'],true)){$tuple=$dims[$r['id']]??[];ksort($tuple);$found[$r['account_code']][]=[(float)$r['amount'],(float)$r['amount_foreign'],$r['currency_code'],$tuple];}
        foreach(['221','324']as $account){self::assertCount(2,$found[$account]);$amounts=array_column($found[$account],0);sort($amounts);self::assertSame([40.0,60.0],$amounts);self::assertSame(4.0,array_sum(array_column($found[$account],1)));foreach($found[$account]as $r){self::assertSame('EUR',$r[2]);self::assertContains($r[3],$this->tuples);}}
    }
    public function testNativeCloseOpenAndNextYearCarryRetiredPropertyTuplesAndForeignNominal():void
    {
        $this->retire();$closed=$this->close($this->period);$this->assertBalances($closed['entry_id']);
        $opened=$this->closing->openNext($this->f['supplier'],$this->period,$this->rv($this->period));$this->assertBalances($opened['entry_id']);
        $annual=new \MyInvoice\Service\Accounting\Closing\AnnualOpeningReadProof($this->db);
        self::assertTrue($annual->matches($this->f['supplier'],$opened['entry_id']));
        foreach([$opened['entry_id'],$closed['entry_id'],$this->entry] as $mutatedEntry)foreach(['amount','amount_foreign'] as $field){
            $this->db->pdo()->exec('SAVEPOINT invalid_annual_opening');
            $q=$this->db->pdo()->prepare('UPDATE journal_entry_lines SET '.$field.'='.$field.'+0.01 WHERE supplier_id=? AND entry_id=?');
            $q->execute([$this->f['supplier'],$mutatedEntry]);
            self::assertFalse($annual->matches($this->f['supplier'],$opened['entry_id']));
            $coverage=(new \MyInvoice\Service\Property\Opening\OpeningReconciliation($this->db))->at($this->f['supplier'],'2027-01-01','2027-12-31');
            self::assertSame('incomplete',$coverage['coverage']);self::assertNotSame([],$coverage['uncovered_line_ids']);
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT invalid_annual_opening');
        }
        $this->db->pdo()->exec('SAVEPOINT rename_annual_account');
        $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
        $account=$accounts->findByCode($this->f['supplier'],'324');
        self::assertTrue($accounts->update($this->f['supplier'],$account['id'],['name'=>'Synthetic renamed advance']));
        self::assertTrue($annual->matches($this->f['supplier'],$opened['entry_id']));
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT rename_annual_account');
        $q=$this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=2027');$q->execute([$this->f['supplier']]);$next=['id'=>(int)$q->fetchColumn()];
        $closedNext=$this->close((int)$next['id']);$this->assertBalances($closedNext['entry_id']);
        $openedNext=$this->closing->openNext($this->f['supplier'],(int)$next['id'],$this->rv((int)$next['id']));$this->assertBalances($openedNext['entry_id']);
    }
    public function testProofRejectsChangedTargetAmountAndTenant():void
    {
        $this->close($this->period);$p=$this->proof();$lines=$p->lines;$lines[0]['amount']+=0.01;
        $this->expectedFailure(fn()=>(new PropertyDimensionPolicy($this->db))->assertClosingCarryJournal($p->header,$lines,$p));
        $header=$p->header;$header['supplier_id']++;
        $this->expectedFailure(fn()=>$p->indices($this->db,$header,$p->lines));
    }
    public function testProofRejectsCurrentSourceChange():void
    {
        $this->close($this->period);$p=$this->proof();$this->db->pdo()->prepare('UPDATE journal_entries SET row_version=row_version+1 WHERE supplier_id=? AND id=?')->execute([$this->f['supplier'],$this->entry]);
        $this->expectedFailure(fn()=>$p->indices($this->db,$p->header,$p->lines));
    }
    public function testProofCannotSurviveRollbackToEarlierSavepoint():void
    {
        $this->close($this->period);$this->db->pdo()->exec('SAVEPOINT before_closing_proof');$p=$this->proof();$this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_closing_proof');
        $this->expectedFailure(fn()=>$p->indices($this->db,$p->header,$p->lines));
    }
    public function testWrittenRetryPreservesPhysicalLineIds():void
    {
        $closed=$this->close($this->period);$q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE entry_id=? ORDER BY id');$q->execute([$closed['entry_id']]);$before=$q->fetchAll(\PDO::FETCH_COLUMN);
        $id=$this->container->get(JournalEntryRepository::class)->upsertCurrentClosingCarry($this->f['supplier'],$this->period,'close_books',$this->rv($this->period)-1);self::assertSame($closed['entry_id'],$id);$q->execute([$id]);self::assertSame($before,$q->fetchAll(\PDO::FETCH_COLUMN));
    }
    public function testOrdinaryPostingCannotUseRetiredClosingTuples():void
    {
        $this->retire();$this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->container->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>1,'dimensions'=>$this->tuples[0]],['account_code'=>'324','side'=>'credit','amount'=>1,'dimensions'=>$this->tuples[0]]],['entry_date'=>'2026-02-02','posted'=>true]);
    }
    public function testEmpty504PeriodClosesAndOpensWithoutInventedJournal():void
    {
        $this->container->get(PostingService::class)->reverse($this->f['supplier'],$this->entry,['reversal_date'=>'2026-02-02']);
        $closed=$this->close($this->period);self::assertNull($closed['entry_id']);
        $opened=$this->closing->openNext($this->f['supplier'],$this->period,$this->rv($this->period));self::assertNull($opened['entry_id']);
    }

    public function testHistoricalSourceWithoutStructuralRegistryBindingIsRejected():void
    {
        $q=$this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='unit'");$q->execute([$this->f['supplier']]);$type=(int)$q->fetchColumn();
        $this->db->pdo()->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')->execute([$type,$this->f['supplier'],'synthetic_orphan','Synthetic orphan']);$orphan=(int)$this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare('UPDATE journal_entry_line_dimensions SET dimension_value_id=? WHERE supplier_id=? AND dimension_type_id=?')->execute([$orphan,$this->f['supplier'],$type]);
        try{$this->close($this->period);self::fail('Unbound historical unit accepted.');}
        catch(ClosingException $e){self::assertSame('property_dimension_unavailable',$e->errorCode);}
    }

    public function testCallerTransactionPreservesExistingCarryAfterLateAuditFailure():void
    {
        $closed=$this->close($this->period);$supplier=$this->f['supplier'];$pdo=$this->db->pdo();
        $q=$pdo->prepare('SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key=\'close_books\'');$q->execute([$supplier,$this->period]);$payload=json_decode($q->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
        $q=$pdo->prepare('SELECT row_version FROM journal_entries WHERE supplier_id=? AND id=?');$q->execute([$supplier,$closed['entry_id']]);$version=(int)$q->fetchColumn();
        $payload['carry']['close_books']=array_replace($payload['carry']['close_books'],['status'=>'pending','expected_version'=>$this->rv($this->period),'previous_entry_id'=>$closed['entry_id'],'previous_entry_version'=>$version]);
        $pdo->prepare('UPDATE accounting_periods SET status=\'closing\' WHERE supplier_id=? AND id=?')->execute([$supplier,$this->period]);
        $pending=json_encode($payload,JSON_THROW_ON_ERROR);$pdo->prepare('UPDATE accounting_closing_steps SET payload=? WHERE supplier_id=? AND period_id=? AND step_key=\'close_books\'')->execute([$pending,$supplier,$this->period]);
        $q=$pdo->prepare('SELECT * FROM journal_entry_lines WHERE entry_id=? ORDER BY id');$q->execute([$closed['entry_id']]);$before=$q->fetchAll(\PDO::FETCH_ASSOC);
        $audit=$this->createMock(\MyInvoice\Service\ActivityLogger::class);$audit->expects(self::once())->method('log')->willThrowException(new \RuntimeException('Synthetic late carry audit failure'));
        try{(new JournalEntryRepository($this->db,$audit))->upsertCurrentClosingCarry($supplier,$this->period,'close_books',$this->rv($this->period));self::fail('Audit failure lost.');}
        catch(\RuntimeException $e){self::assertSame('Synthetic late carry audit failure',$e->getMessage());}
        self::assertTrue($pdo->inTransaction());$q->execute([$closed['entry_id']]);self::assertSame($before,$q->fetchAll(\PDO::FETCH_ASSOC));
        $q=$pdo->prepare('SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key=\'close_books\'');$q->execute([$supplier,$this->period]);self::assertSame($pending,$q->fetchColumn());
    }

}
