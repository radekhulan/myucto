<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Reconciliation;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Integration\Property\Services\Settlement\ServiceLifecycleNativeTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class ServiceLifecycleReconciliationSourcesTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;
    protected function setUp():void{$this->fixture=new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);$this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);}
    protected function tearDown():void{if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function native(string $method,mixed ...$args):mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    public static function mutations():array{return ['native stale'=>[false,false],'native rehash'=>[false,true],'opening stale'=>[true,false],'opening rehash'=>[true,true]];}
    #[DataProvider('mutations')]
    public function testCurrentRejectsCompletedUnwindCorruptionBeforeFinal(bool $opening,bool $rehash):void
    {
        $d=$this->native('openingBase',$opening?'service_profit_and_loss':'service_balance_sheet',false);$f=$d['f'];$owner=$d['owner'];if(!$opening){$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$lines=[];foreach([['388.210','debit'],['389.110','credit']] as [$code,$side])$lines[]=['account_code'=>$code,'side'=>$side,'amount'=>'11000.00','dimensions'=>$d['dims'],'dimension_origins'=>array_fill_keys(array_keys($d['dims']),['origin'=>'explicit','ref'=>null])];$entry=$container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,$lines,['entry_date'=>'2027-03-01','posted_by'=>$f['actor']]);$d['case']=$owner->openCase($f['supplier'],$f['units']['U02'],$f['service']['id'],'2027-01-01','2027-12-31','service_balance_sheet','Synthetic native current-year case',$f['actor'],'315.210');$owner->registerEstimate($f['supplier'],$d['case'],[$entry],'Synthetic native estimate');}$entries=$opening?$owner->unwind($f['supplier'],$d['case'],'2027-04-01',$f['actor']):$owner->unwindWithFinal($f['supplier'],$d['case'],'2027-04-01',$f['actor']);self::assertNull($owner->current($f['supplier'],$d['case'])['final']);$pdo=$this->db->pdo();$ledger=(new PaymentJournalProof($this->db))->current($f['supplier'],$entries[0]);$pdo->prepare('UPDATE journal_entry_lines SET amount=2 WHERE supplier_id=? AND id=?')->execute([$f['supplier'],$ledger['lines'][0]['id']]);
        if($rehash){$q=$pdo->prepare('SELECT id,receipt_json FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND journal_entry_id=?');$q->execute([$f['supplier'],$d['case'],$entries[0]]);$event=$q->fetch(\PDO::FETCH_ASSOC);$receipt=json_decode($event['receipt_json'],true,flags:JSON_THROW_ON_ERROR);$receipt['ledger']=(new PaymentJournalProof($this->db))->current($f['supplier'],$entries[0]);$pdo->prepare('UPDATE property_service_lifecycle_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($receipt),PropertyInput::hash($receipt),$f['supplier'],$event['id']]);}
        try{$owner->current($f['supplier'],$d['case']);self::fail('Completed unwind escaped verification before finalization.');}catch(\DomainException $error){self::assertSame('settlement_lifecycle_unwind_proof',$error->getMessage());}
    }
    public function testCanonicalReadAllowsPartialUnwindAndReportsPendingWithoutInventingAReceipt():void
    {
        $d=$this->native('openingBase','service_balance_sheet',false);$f=$d['f'];$owner=$d['owner'];$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$entries=[];
        foreach([['502','389.110'],['388.210','649']] as [$debit,$credit]){$lines=[];foreach([[$debit,'debit'],[$credit,'credit']] as [$code,$side])$lines[]=['account_code'=>$code,'side'=>$side,'amount'=>'11000.00','dimensions'=>$d['dims'],'dimension_origins'=>array_fill_keys(array_keys($d['dims']),['origin'=>'explicit','ref'=>null])];$entries[]=$container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,$lines,['entry_date'=>'2027-03-01','posted_by'=>$f['actor']]);}
        (new \MyInvoice\Repository\Property\Services\Settlement\ServiceLifecyclePolicyRepository($this->db))->declare($f['supplier'],$f['service']['id'],'2027-01-01','service_profit_and_loss','Synthetic approved P&L policy from 2027',$f['actor']);$case=$owner->openCase($f['supplier'],$f['units']['U02'],$f['service']['id'],'2027-01-01','2027-12-31','service_profit_and_loss','Synthetic approved P&L service policy',$f['actor'],'315.210');$owner->registerEstimate($f['supplier'],$case,$entries,'Synthetic approved coverage');$before=$owner->reconciliationSources($f['supplier'],$case);self::assertCount(2,$before['estimate']['receipt']['sources']);self::assertSame([],$before['unwinds']);self::assertSame([],$before['pending']);
        $event=(new \ReflectionMethod($owner,'newEvent'))->invoke($owner,$f['supplier'],$case,'unwind-0',['kind'=>'unwind','estimate'=>$before['estimate']['event_id'],'index'=>0,'accounting_date'=>'2027-04-01','actor'=>$f['actor']]);$entry=(new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyServiceLifecycle(\MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleProof::fromPending($this->db,$owner,$f['supplier'],$event));$partial=$owner->reconciliationSources($f['supplier'],$case);self::assertCount(1,$partial['unwinds']);self::assertSame($entry,$partial['unwinds'][0]['ledger']['entry']['id']);self::assertNull($owner->current($f['supplier'],$case)['final']);
        try{$owner->assertFinalDate($f['supplier'],$case,'2027-04-01');self::fail('Finalization accepted incomplete unwind set.');}catch(\DomainException $error){self::assertSame('settlement_lifecycle_estimate_not_unwound',$error->getMessage());}
        $pending=(new \ReflectionMethod($owner,'newEvent'))->invoke($owner,$f['supplier'],$case,'unwind-1',['kind'=>'unwind','estimate'=>$before['estimate']['event_id'],'index'=>1,'accounting_date'=>'2027-04-02','actor'=>$f['actor']]);$read=$owner->reconciliationSources($f['supplier'],$case);self::assertCount(1,$read['unwinds']);self::assertSame(['lifecycle_pending','lifecycle_unwind_without_final'],array_column($read['pending'],'code'));self::assertSame($pending,$read['pending'][0]['event_id']);self::assertSame('2027-04-02',$read['pending'][0]['accounting_date']);self::assertSame('lifecycle_pending',$read['pending'][0]['code']);
    }
    public function testReadReportsPendingFinalWhileCurrentRemainsStrict():void
    {
        $d=$this->native('openingBase','service_balance_sheet',false);$f=$d['f'];$owner=$d['owner'];$owner->unwind($f['supplier'],$d['case'],'2027-04-01',$f['actor']);$event=(new \ReflectionMethod($owner,'newEvent'))->invoke($owner,$f['supplier'],$d['case'],'final',['kind'=>'final','invoice_id'=>1,'line_id'=>1,'accounting_date'=>'2027-04-01','actor'=>$f['actor']]);$read=$owner->reconciliationSources($f['supplier'],$d['case']);self::assertNull($read['final']);self::assertSame(['lifecycle_pending','lifecycle_unwind_without_final'],array_column($read['pending'],'code'));self::assertSame($event,$read['pending'][0]['event_id']);
        try{$owner->current($f['supplier'],$d['case']);self::fail('Current accepted a pending final as complete.');}catch(\DomainException $error){self::assertSame('settlement_lifecycle_pending',$error->getMessage());}
    }

}
