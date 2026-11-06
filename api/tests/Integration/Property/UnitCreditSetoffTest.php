<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\{CreditSetoffRepository,DebtRepository};
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
final class UnitCreditSetoffTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;private array $f;
    protected function setUp():void{$this->db=Bootstrap::buildContainer()->get(Connection::class);$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testReservedCreditCannotBeSpentTwiceAndExactReversalRestoresBothSides():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$debtor=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$payments=$this->correctionPayments();
        $creditRequest=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$this->correctionBank('100.00'),'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reason'=>'Synthetic separate credit','debts'=>[],'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$base['charge']['unit_id'],'party_ids'=>[$debtor],'evidence'=>'Synthetic reciprocal credit title','due_date'=>'2026-02-01']];
        $origin=$payments->apply($f['supplier'],$creditRequest,$payments->preview($f['supplier'],$creditRequest)['preview_hash'],'credit',$f['actor']);
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$f['supplier'],$debtor);$reserve=$payments->reserve($f['supplier'],$origin['credit_id'],1,['amount_cents'=>'2000','party_ids'=>[$debtor],'disposition'=>'Synthetic refund instruction','recipient_account'=>'1000000005/0100'],'reserve',$f['actor']);
        $request=['kind'=>'setoff','credit_id'=>$origin['credit_id'],'expected_credit_version'=>2,'amount_cents'=>'8000','accounting_date'=>'2026-02-01','reason'=>'Synthetic reciprocal setoff','debts'=>[$base['debt']['id']],
            'payment'=>['debtor_id'=>$debtor,'payer_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic order']],
            'legal'=>['certain'=>true,'unconditional'=>true,'scope_cents'=>'8000','mutuality_evidence'=>'Synthetic same legal creditor','eligibility_evidence'=>'Synthetic due and enforceable debt','declaration_evidence'=>'Synthetic unconditional declaration','delivery_evidence'=>'Synthetic receipt','delivered_at'=>'2026-02-01T10:00:00+01:00','effect_evidence'=>'Synthetic effect date']];
        $repo=$this->paymentFixtureContainer()->get(CreditSetoffRepository::class);
        $bad=$request;$bad['payment']['debtor_id']=$f['clients'][1];$bad['payment']['payer_id']=$f['clients'][1];try{$repo->preview($f['supplier'],$bad);self::fail('Different creditor treated as reciprocal.');}catch(PaymentException $e){self::assertSame('property_payment_setoff_mutuality',$e->errorCode);}
        $bad=$request;$bad['legal']['certain']=false;try{$repo->preview($f['supplier'],$bad);self::fail('Uncertain claim set off.');}catch(PaymentException $e){self::assertSame('property_payment_setoff_eligibility',$e->errorCode);}
        $hash=$repo->preview($f['supplier'],$request)['preview_hash'];$done=$repo->apply($f['supplier'],$request,$hash,'setoff',$f['actor']);
        self::assertSame('2000',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);self::assertSame('0',$payments->credit($f['supplier'],$origin['credit_id'])['available_cents']);
        self::assertSame('8001',(new DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
        self::assertSame(['379.910','315'],array_column($done['snapshot']['lines'],'account_code'));
        $bad=$request;$bad['expected_credit_version']=3;$bad['amount_cents']='1';try{$repo->preview($f['supplier'],$bad);self::fail('Reserved credit spent.');}catch(PaymentException $e){self::assertSame('property_payment_credit_capacity',$e->errorCode);}
        try{$payments->reverse($f['supplier'],$origin['id'],2,'2026-02-02','Synthetic undo','origin-undo',$f['actor']);self::fail('Used credit reversed.');}catch(PaymentException $e){self::assertSame('property_payment_credit_in_use',$e->errorCode);}
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=?');$q->execute([$f['supplier'],$done['id']]);$base['allocation_id']=(int)$q->fetchColumn();
        $correction=$this->negativePaymentCorrection($base,'50','3001');$corrections=$this->correctionWriter();$relief=$corrections->apply($f['supplier'],$correction,$corrections->preview($f['supplier'],$correction)['preview_hash'],'setoff-relief',$f['actor']);
        self::assertSame('3001',$payments->credit($f['supplier'],$relief['credit_id'])['balance_cents']);self::assertSame('2000',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);
        try{$payments->reverse($f['supplier'],$done['id'],2,'2026-02-03','Synthetic undo','undo',$f['actor']);self::fail('Setoff relief detached.');}catch(PaymentException $e){self::assertSame('property_payment_allocation_has_relief',$e->errorCode);}
        $corrections->reverse($f['supplier'],$relief['id'],['expected_version'=>2,'accounting_date'=>'2026-02-03','effective_at'=>'2026-02-03T10:00:00+01:00','reason'=>'Synthetic correction undo','evidence'=>'Synthetic decision'],'relief-undo',$f['actor']);
        $payments->reverse($f['supplier'],$done['id'],2,'2026-02-03','Synthetic setoff withdrawal','undo',$f['actor']);
        self::assertSame('10000',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);self::assertSame('1',(new DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
        self::assertSame($done['id'],$repo->apply($f['supplier'],$request,$hash,'setoff',$f['actor'])['id']);$bad=$request;$bad['reason']='Changed';try{$repo->apply($f['supplier'],$bad,$hash,'setoff',$f['actor']);self::fail('Changed retry accepted.');}catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
        $payments->cancelReservation($f['supplier'],$reserve['id'],1,'Synthetic cancellation','cancel',$f['actor']);
        $payments->reverse($f['supplier'],$origin['id'],2,'2026-02-02','Synthetic credit undo','origin-undo',$f['actor']);self::assertSame('0',$payments->credit($f['supplier'],$origin['credit_id'])['balance_cents']);
    }
    public function testReversedUnpaidRegisteredDebtRemainsReadableWithoutBlockingOtherInventory():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('1','0');$this->correctionPayments()->reverse($f['supplier'],$base['operation']['id'],2,'2026-02-02','Synthetic undo','undo',$f['actor']);
        $this->paymentFixtureContainer()->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class)->reverse($f['supplier'],$base['charge']['id'],['row_version'=>2,'accounting_date'=>'2026-02-02','effective_at'=>'2026-02-02T10:00:00+01:00','reason'=>'Synthetic withdrawal','evidence_ref'=>'Synthetic decision','idempotency_key'=>'charge-undo'],$f['actor']);
        $all=(new DebtRepository($this->db))->allCurrent($f['supplier']);self::assertCount(1,$all);self::assertFalse($all[0]['active']);self::assertSame($base['debt']['snapshot'],$all[0]['snapshot']);
    }
}
