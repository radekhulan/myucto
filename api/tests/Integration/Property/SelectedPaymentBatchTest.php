<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PartyEventRepository};
use MyInvoice\Service\Property\Payments\{ClaimAllocationPlanner,PaymentException,PaymentLocks};
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Receivables\PropertyLiabilityProjection;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../Support/SelectedBatchReferencePlanner.php';
require_once __DIR__.'/../../Support/SelectedBatchReferenceDebt.php';
final class SelectedPaymentBatchTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,ReceivableFixture;
    private Connection $db;
    private array $f;
    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void
    {
        if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
    }
    public function testBatchMatchesFrozenSingleReaderIncludingGlobalReductionMap():void
    {
        $base=$this->settledPaymentFixture('90000','0');$ids=[$base['debt']['id'],...$this->registerOtherDebts()];
        $request=$this->negativePaymentCorrection($base,'800','10000');$writer=$this->correctionWriter();
        $writer->apply($this->f['supplier'],$request,$writer->preview($this->f['supplier'],$request)['preview_hash'],'batch-correction',$this->f['actor']);
        $repo=new DebtRepository($this->db);$reference=new \MyInvoice\Repository\Property\Payments\SelectedBatchReferenceDebt($this->db);
        sort($ids,SORT_NUMERIC);$expected=array_map(fn($id)=>$reference->current($this->f['supplier'],$id),$ids);
        $actual=$repo->currentMany($this->f['supplier'],array_reverse($ids));
        self::assertSame($expected,$actual);self::assertSame(PropertyInput::hash($expected),PropertyInput::hash($actual));
        self::assertGreaterThan(1,count($actual));
        foreach($actual as $debt)self::assertSame('20000',$debt['reductions'][$base['line_id']]);
    }
    public function testSelectedAndAutomaticOmittedDebtRemainDifferentAndSelectedHashMatchesReference():void
    {
        $base=$this->settledPaymentFixture('1','0');$other=$this->registerOtherDebts();self::assertNotEmpty($other);
        $request=$this->payment($base);$planner=new ClaimAllocationPlanner($this->db);$source=['receipt'=>['date'=>'2026-02-01']];
        $reference=(new \MyInvoice\Service\Property\Payments\SelectedBatchReferencePlanner($this->db))->current($this->f['supplier'],$request,$source);
        $actual=$planner->current($this->f['supplier'],$request,$source);
        self::assertSame($reference,$actual);self::assertSame(PropertyInput::hash($reference),PropertyInput::hash($actual));
        self::assertSame([$base['debt']['id']],array_column($actual['allocations'],'debt_id'));
        unset($request['payment']['choice']);
        try{$planner->current($this->f['supplier'],$request,$source);self::fail('Automatic inventory accepted an omitted debt.');}
        catch(PaymentException $e){self::assertSame('property_payment_debt_inventory_incomplete',$e->errorCode);}
    }
    public static function badChoices():array
    {
        return [['empty'],['duplicate'],['missing_evidence'],['wrong_choice'],['unknown_wanted']];
    }
    #[DataProvider('badChoices')]
    public function testInvalidChoiceNeverAuthorizesSelectedPlan(string $case):void
    {
        $base=$this->settledPaymentFixture('1','0');$request=$this->payment($base);$id=$base['debt']['id'];
        if($case==='empty')$request['payment']['choice']['claim_ids']=[];
        elseif($case==='duplicate')$request['payment']['choice']['claim_ids']=[$id,$id];
        elseif($case==='missing_evidence')unset($request['payment']['choice']['evidence']);
        elseif($case==='wrong_choice')$request['payment']['choice']['claim_ids']=[$id+1000000];
        else{$request['debts']=[$id+1000000];$request['payment']['choice']['claim_ids']=[$id+1000000];}
        $rejected=false;
        try{(new ClaimAllocationPlanner($this->db))->current($this->f['supplier'],$request,['receipt'=>['date'=>'2026-02-01']]);}
        catch(PaymentException|PropertyException $e){
            $rejected=true;
            self::assertInstanceOf($case==='missing_evidence'?PropertyException::class:PaymentException::class,$e);
            self::assertSame(match($case){'missing_evidence'=>'property_invalid_input','unknown_wanted'=>'property_payment_debt_not_found',default=>'property_payment_legal_review'},$e->errorCode);
            if(in_array($case,['empty','duplicate','wrong_choice'],true))self::assertSame($case==='wrong_choice'?'debtor_choice_ineligible':'debtor_choice_unproven',$e->details['reason']);
        }
        self::assertTrue($rejected,'Invalid choice authorized a selected payment.');
    }
    public function testCurrentManyRejectsExistingDebtOfAnotherTenant():void
    {
        $base=$this->settledPaymentFixture('1','0');$foreign=$this->issuanceFixture($this->db);
        try{(new DebtRepository($this->db))->currentMany($foreign['supplier'],[$base['debt']['id']]);self::fail('Foreign tenant debt was accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_debt_not_found',$e->errorCode);}
    }
    public function testAnotherReadInSameTransactionReprovesCurrentJournal():void
    {
        $base=$this->settledPaymentFixture('1','0');$repo=new DebtRepository($this->db);$ids=[$base['debt']['id']];
        self::assertCount(1,$repo->currentMany($this->f['supplier'],$ids));
        $q=$this->db->pdo()->prepare('UPDATE journal_entry_lines SET cost_center=? WHERE supplier_id=? AND entry_id=?');
        $q->execute(['Synthetic changed legacy dimension',$this->f['supplier'],$base['charge']['journal_entry_id']]);self::assertGreaterThan(0,$q->rowCount());
        try{$repo->currentMany($this->f['supplier'],$ids);self::fail('A proof survived a current mutation.');}
        catch(IssuanceException $e){self::assertSame('property_charge_issue_ledger_mismatch',$e->errorCode);}
    }
    public function testRollbackToSavepointDoesNotPreserveProofAuthority():void
    {
        $base=$this->settledPaymentFixture('1','0');$repo=new DebtRepository($this->db);$ids=[$base['debt']['id']];
        $this->db->pdo()->exec('SAVEPOINT selected_batch_probe');$before=$repo->currentMany($this->f['supplier'],$ids);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT selected_batch_probe');
        self::assertSame($before,$repo->currentMany($this->f['supplier'],$ids));
        $q=$this->db->pdo()->prepare('UPDATE journal_entry_lines SET cost_center=? WHERE supplier_id=? AND entry_id=?');
        $q->execute(['Synthetic post rollback mutation',$this->f['supplier'],$base['charge']['journal_entry_id']]);
        $this->expectException(IssuanceException::class);$repo->currentMany($this->f['supplier'],$ids);
    }
    public function testUnrelatedJournalCorruptionIsStillFoundByFullAudit():void
    {
        $base=$this->settledPaymentFixture('1','0');$this->registerOtherDebts();$request=$this->payment($base);
        $q=$this->db->pdo()->prepare('SELECT c.journal_entry_id FROM unit_payment_debts d JOIN unit_charges c ON c.supplier_id=d.supplier_id AND c.id=d.charge_id WHERE d.supplier_id=? AND c.id<>? ORDER BY c.id LIMIT 1 FOR UPDATE');
        $q->execute([$this->f['supplier'],$base['charge']['id']]);$entry=(int)$q->fetchColumn();self::assertGreaterThan(0,$entry);
        $q=$this->db->pdo()->prepare('UPDATE journal_entry_lines SET cost_center=? WHERE supplier_id=? AND entry_id=?');$q->execute(['Synthetic unrelated corruption',$this->f['supplier'],$entry]);
        $plan=(new ClaimAllocationPlanner($this->db))->current($this->f['supplier'],$request,['receipt'=>['date'=>'2026-02-01']]);self::assertSame('ready',$plan['resolution']['status']);
        $this->expectException(IssuanceException::class);(new DebtRepository($this->db))->allCurrent($this->f['supplier']);
    }
    public function testBatchQueryCountDoesNotGrowPerDebtWithinOneChunk():void
    {
        $base=$this->settledPaymentFixture('1','0');$ids=[$base['debt']['id'],...$this->registerOtherDebts()];self::assertGreaterThan(3,count($ids));
        $repo=new DebtRepository($this->db);$supplier=$this->f['supplier'];
        $one=$this->queries(fn()=> $repo->currentMany($supplier,[$ids[0]]));
        $many=$this->queries(fn()=> $repo->currentMany($supplier,$ids));
        self::assertLessThanOrEqual($one+4,$many,'Batch proof acquired an extra round trip per debt.');
        self::assertLessThanOrEqual(80,$many,'Small batch unexpectedly runs an unbounded proof loop.');
    }
    public function testMixedS5ChainAndFinancialClosureMatchFullHistory():void
    {
        $base=$this->settledPaymentFixture('1000','0');$ids=$this->registerOtherDebts();self::assertNotEmpty($ids);
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=? AND id<>? ORDER BY id LIMIT 1');$q->execute([$this->f['supplier'],$base['charge']['id'],$base['debt']['id']]);$other=(int)$q->fetchColumn();self::assertGreaterThan(0,$other);
        $events=new PartyEventRepository($this->db);$old=$base['debt']['snapshot']['liability_groups'][0]['members'][0];
        $first=$this->request($base,$this->f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);
        $first['debts'][]=['debt_id'=>$other,'expected_event_id'=>null,'groups'=>$first['debts'][0]['groups']];
        $a=$events->confirm($this->f['supplier'],$first,$events->preview($this->f['supplier'],$first)['preview_hash'],'batch-transfer-a',$this->f['actor']);
        $second=$this->request($base,$this->f['clients'][3],2,$a['id'],'2026-02-03T10:00:00+01:00',[$this->f['clients'][2]]);
        $second['debts'][]=['debt_id'=>$other,'expected_event_id'=>$a['id'],'groups'=>$second['debts'][0]['groups']];
        $b=$events->confirm($this->f['supplier'],$second,$events->preview($this->f['supplier'],$second)['preview_hash'],'batch-transfer-b',$this->f['actor']);
        $reader=new FinancialHistoryRepository($this->db);$full=$reader->read($this->f['supplier'],true);$selected=$reader->readForDebts($this->f['supplier'],[$base['debt']['id']]);
        foreach($selected['debts'] as $id=>$debt)self::assertSame($full['debts'][$id],$debt);
        $history=$events->historyForDebts($this->f['supplier'],[$base['debt']['id']]);self::assertContains($other,$history['debt_ids']);self::assertSame([$a['id'],$b['id']],array_column($history['events'],'id'));
        $at='2026-02-04T10:00:00+01:00';$projection=new PropertyLiabilityProjection($this->db);
        $before=$projection->forPayment($this->f['supplier'],$at,$this->f['clients'][3],[$base['debt']['id']]);$after=$projection->forSelectedPayment($this->f['supplier'],$at,[$base['debt']['id']]);
        self::assertSame($before[$base['debt']['id']],$after[$base['debt']['id']]);self::assertSame($b['id'],$after[$base['debt']['id']]['head_event_id']);
        $request=$this->payment($base);$request['payment']['debtor_id']=$this->f['clients'][3];$request['payment']['payer_id']=$this->f['clients'][2];$request['payment']['received_at']=$at;$request['payment']['third_party']=['basis'=>'guarantor','evidence'=>'Synthetic documented guarantee'];$source=['receipt'=>['date'=>'2026-02-04']];
        $expected=(new \MyInvoice\Service\Property\Payments\SelectedBatchReferencePlanner($this->db))->current($this->f['supplier'],$request,$source);
        $actual=(new ClaimAllocationPlanner($this->db))->current($this->f['supplier'],$request,$source);
        self::assertSame($expected,$actual);self::assertSame(PropertyInput::hash($expected),PropertyInput::hash($actual));
    }
    private function payment(array $base):array
    {
        $debtor=$base['debt']['snapshot']['liability_groups'][0]['members'][0];
        return ['debts'=>[$base['debt']['id']],'amount_cents'=>'1','payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic debtor choice']]];
    }
    private function registerOtherDebts():array
    {
        $q=$this->db->pdo()->prepare('SELECT l.id,l.charge_id FROM unit_charge_lines l LEFT JOIN unit_payment_debt_lines d ON d.supplier_id=l.supplier_id AND d.charge_line_id=l.id WHERE l.supplier_id=? AND l.amount_cents>0 AND d.debt_id IS NULL ORDER BY l.id');$q->execute([$this->f['supplier']]);$out=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $line){
            $request=['charge_id'=>(int)$line['charge_id'],'expected_version'=>2,'principal_line_ids'=>[(int)$line['id']],'title_ref'=>'Synthetic independent debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding'],'damages_for'=>null];
            $debt=(new DebtRepository($this->db))->register($this->f['supplier'],$request,'batch-debt-'.$line['id'],$this->f['actor']);$out[]=$debt['id'];
        }
        return $out;
    }
    private function queries(callable $work):int
    {
        $count=fn()=>(int)$this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
        $before=$count();$work();return $count()-$before-1;
    }
}
