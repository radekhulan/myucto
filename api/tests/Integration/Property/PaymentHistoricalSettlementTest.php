<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Action\Property\Payments\PaymentOperationsAction;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PaymentHistoricalSettlementTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}

    public function testExistingChargeCanBeSettledAfterItsUnitDimensionIsRetired():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$bank=$this->correctionBank('1.00');
        $this->db->pdo()->prepare("UPDATE dimension_values v JOIN property_objects o ON o.supplier_id=v.supplier_id AND o.dimension_value_id=v.id SET v.is_active=0 WHERE o.supplier_id=? AND o.id=?")->execute([$f['supplier'],$base['charge']['unit_id']]);
        $debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];
        $request=['source'=>['kind'=>'bank','id'=>$bank],'clearing_cells'=>[['key'=>'original','amount_cents'=>'100','dimensions'=>[]]],'parts'=>[['key'=>'historic-claim','request'=>['kind'=>'allocation','amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic existing claim settlement','debts'=>[$base['debt']['id']], 'payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic disposition']]]]]];
        $facade=Bootstrap::buildContainer()->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade::class);$preview=$facade->preview($f['supplier'],$request);
        $done=$facade->confirm($f['supplier'],$request,$preview['preview_hash'],'historic-settlement',$f['actor']);self::assertSame('100',$done['settled_cents']);
        $debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->current($f['supplier'],$base['debt']['id']);self::assertSame('101',$debt['paid']['principal:'.$base['line_id']]);
    }
    public function testClosedHistoricalClaimCanBePaidInOpenYearWithDisabledProfile():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$bank=$this->correctionBank('1.00');
        $proof=new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db);
        $original=$proof->current($f['supplier'],$base['charge']['journal_entry_id']);
        $this->retire($base);
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($f['supplier'],[
            'expected_revision'=>1,'effective_from'=>'2027-01-01T00:00:00+01:00','effective_to'=>null,'enabled'=>false,
            'reason'=>'Synthetic discontinued module','title_ref'=>'Synthetic decision','idempotency_key'=>'disabled'], $f['actor']);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$f['supplier']]);
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($f['supplier'],2027,'2027-01-01','2027-12-31');
        $request=$this->settlementRequest($base,$bank);$request['accounting_date']='2027-01-02';
        $payments=$this->correctionPayments();$preview=$payments->preview($f['supplier'],$request);
        $done=$payments->apply($f['supplier'],$request,$preview['preview_hash'],'closed-retired',$f['actor']);
        self::assertSame('posted',$done['status']);
        self::assertSame('2027-01-02',$proof->current($f['supplier'],$done['journal_entry_id'])['entry']['entry_date']);
        self::assertSame($original,$proof->current($f['supplier'],$base['charge']['journal_entry_id']));
    }

    public function testCarrierBindsEveryTargetFieldAndCannotAuthorizeOrdinaryNewAssignments():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$bank=$this->correctionBank('1.00');$this->retire($base);
        $carrier=\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromCurrentPayment($this->db,$this->correctionPayments(),$f['supplier'],$this->settlementRequest($base,$bank));
        $journal=$carrier->journal($this->db);$policy=new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db);
        $policy->assertPropertyPaymentCarrierJournal($journal['header'],$journal['lines'],$carrier);
        try{$policy->assertJournal($journal['header'],$journal['lines']);self::fail('Ordinary new assignments accepted retired value.');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_dimension_unavailable',$e->errorCode);}
        foreach(['amount','dimensions','provenance','tenant','legacy'] as $case){
            $header=$journal['header'];$lines=$journal['lines'];$last=array_key_last($lines);
            if($case==='amount')foreach($lines as &$line)$line['amount']='0.99';unset($line);
            if($case==='dimensions')$lines[$last]['dimensions']=[];
            if($case==='provenance')$lines[$last]['dimension_origins']=[];
            if($case==='tenant')$header['supplier_id']++;
            if($case==='legacy')$lines[$last]['cost_center']='Synthetic changed legacy context';
            try{$policy->assertPropertyPaymentCarrierJournal($header,$lines,$carrier);self::fail('Changed carrier target accepted: '.$case);}
            catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_carrier_target_mismatch',$e->errorCode,$case);}
        }
    }

    public function testCarrierCannotBeReusedAfterCapacityMutationOrRollback():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$bank=$this->correctionBank('1.00');$request=$this->settlementRequest($base,$bank);$payments=$this->correctionPayments();
        $this->db->pdo()->exec('SAVEPOINT before_carrier');
        $carrier=\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromCurrentPayment($this->db,$payments,$f['supplier'],$request);
        $journal=$carrier->journal($this->db);
        $preview=$payments->preview($f['supplier'],$request);$payments->apply($f['supplier'],$request,$preview['preview_hash'],'consume-carrier',$f['actor']);
        try{$carrier->indices($this->db,$journal['header'],$journal['lines']);self::fail('Consumed source accepted old carrier.');}
        catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertContains($e->errorCode,['property_payment_source_version_conflict','property_payment_carrier_source_changed']);}
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_carrier');
        try{$carrier->indices($this->db,$journal['header'],$journal['lines']);self::fail('Rolled back proof accepted.');}
        catch(\LogicException $e){self::assertStringContainsString('rolled back transaction',$e->getMessage());}
    }

    public function testHistoricalCreditAndClaimCanBeSetOffAfterUnitRetirement():void
    {
        $f=$this->f;[$base,$origin,$request]=$this->setoffFixture();$payments=$this->correctionPayments();
        $repo=$this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Payments\CreditSetoffRepository::class);$preview=$repo->preview($f['supplier'],$request);
        $done=$repo->apply($f['supplier'],$request,$preview['preview_hash'],'retired-setoff',$f['actor']);
        self::assertSame('posted',$done['status']);self::assertSame('0',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);
        self::assertSame('101',(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
    }

    public function testReservedHistoricalCreditCanBePartiallyRefundedAfterUnitRetirement():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','400');$payments=$this->correctionPayments();
        $reserve=$payments->reserve($f['supplier'],$base['operation']['credit_id'],1,['amount_cents'=>'300','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic instruction','recipient_account'=>'1000000005/0100'],'historic-reserve',$f['actor']);
        $bank=$this->correctionBank('-1.00');$this->retire($base);
        $request=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic historical partial refund','reservation_id'=>$reserve['id'],'expected_reservation_version'=>1];
        $done=$payments->apply($f['supplier'],$request,$payments->preview($f['supplier'],$request)['preview_hash'],'historical-refund',$f['actor']);
        self::assertSame('posted',$done['status']);self::assertSame('300',$payments->credit($f['supplier'],$base['operation']['credit_id'])['balance_cents']);
        self::assertSame('100',$payments->reservation($f['supplier'],$reserve['id'])['paid_cents']);
    }

    public function testHistoricalReceiptDoesNotAuthorizeANewCreditOnRetiredUnit():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$bank=$this->correctionBank('1.00');$this->retire($base);
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic new credit','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$base['charge']['unit_id'],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic title','due_date'=>'2026-03-01']];
        try{$carrier=\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromCurrentPayment($this->db,$this->correctionPayments(),$f['supplier'],$request);$journal=$carrier->journal($this->db);
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertPropertyPaymentCarrierJournal($journal['header'],$journal['lines'],$carrier);self::fail('Historical source authorized new retired-unit credit.');}
        catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Property\PropertyException $e){self::assertSame('property_dimension_unavailable',$e->errorCode);}
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSetoffLateCompletionFailureRollsBackJournalCreditClaimsAndAudit():void
    {
        $f=$this->f;[$base,$origin,$request]=$this->setoffFixture();$payments=$this->correctionPayments();
        $repo=$this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Payments\CreditSetoffRepository::class);
        $preview=$repo->preview($f['supplier'],$request);$before=$this->financialCounts();
        $this->db->pdo()->exec('SAVEPOINT caller_setoff');
        $fault=$this->getMockBuilder(\MyInvoice\Repository\Property\Payments\CreditSetoffRepository::class)
            ->setConstructorArgs([$this->db,$this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class),$payments])->onlyMethods(['completePendingSetoff'])->getMock();
        $fault->expects(self::once())->method('completePendingSetoff')->willReturnCallback(function($db,$supplier,$id,$entry)use($repo,$payments,$origin):void{
            $repo->completePendingSetoff($db,$supplier,$id,$entry);self::assertSame('0',$payments->credit($supplier,$origin['credit_id'])['balance_cents']);
            self::assertSame('posted',$payments->operation($supplier,$id)['status']);$db->pdo()->exec('SAVEPOINT nested_setoff_completion');throw new \RuntimeException('Synthetic late setoff completion failure');
        });
        try{$fault->apply($f['supplier'],$request,$preview['preview_hash'],'late-setoff',$f['actor']);self::fail('Late setoff fault committed.');}
        catch(\RuntimeException $e){self::assertSame('Synthetic late setoff completion failure',$e->getMessage());}
        self::assertTrue($this->db->pdo()->inTransaction());self::assertSame($before,$this->financialCounts());
        try{$this->db->pdo()->exec('ROLLBACK TO SAVEPOINT nested_setoff_completion');self::fail('Nested completion marker survived owner rollback.');}
        catch(\PDOException $e){self::assertSame(1305,(int)$e->errorInfo[1]);}
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT caller_setoff');self::assertSame($before,$this->financialCounts());
        self::assertSame('100',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);
        $done=$repo->apply($f['supplier'],$request,$preview['preview_hash'],'late-setoff',$f['actor']);self::assertSame('posted',$done['status']);
    }
    private function setoffFixture():array
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$payments=$this->correctionPayments();
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$this->correctionBank('1.00'),'expected_version'=>0],'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic historical credit','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$base['charge']['unit_id'],'party_ids'=>[$debtor],'evidence'=>'Synthetic reciprocal title','due_date'=>'2026-02-01']];
        $origin=$payments->apply($f['supplier'],$request,$payments->preview($f['supplier'],$request)['preview_hash'],'historical-credit',$f['actor']);$this->retire($base);
        $request=['kind'=>'setoff','credit_id'=>$origin['credit_id'],'expected_credit_version'=>1,'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic reciprocal setoff','debts'=>[$base['debt']['id']],
            'payment'=>['debtor_id'=>$debtor,'payer_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic order']],
            'legal'=>['certain'=>true,'unconditional'=>true,'scope_cents'=>'100','mutuality_evidence'=>'Synthetic same creditor','eligibility_evidence'=>'Synthetic due debt','declaration_evidence'=>'Synthetic declaration','delivery_evidence'=>'Synthetic receipt','delivered_at'=>'2026-02-01T10:00:00+01:00','effect_evidence'=>'Synthetic effect']];
        return [$base,$origin,$request];
    }
    private function financialCounts():array
    {
        $out=[];foreach(['journal_entries','journal_entry_lines','unit_payment_operations','unit_payment_allocations','unit_credit_events','unit_payment_audit'] as $table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$table]=(int)$q->fetchColumn();}return $out;
    }

    private function retire(array $base):void
    {
        $this->db->pdo()->prepare("UPDATE dimension_values v JOIN property_objects o ON o.supplier_id=v.supplier_id AND o.dimension_value_id=v.id SET v.is_active=0 WHERE o.supplier_id=? AND o.id=?")->execute([$this->f['supplier'],$base['charge']['unit_id']]);
    }
    private function settlementRequest(array $base,int $bank):array
    {
        $debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];
        return ['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'100',
            'accounting_date'=>'2026-02-01','reason'=>'Synthetic existing claim settlement','debts'=>[$base['debt']['id']],
            'payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00',
                'choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic disposition']]];
    }

}
