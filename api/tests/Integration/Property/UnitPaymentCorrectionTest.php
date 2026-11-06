<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\{DebtRepository,PaymentRepository,ReliefCorrectionRepository};
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\{ChargePreviewService};
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\{PaymentException,PaymentSourceProof,PaymentLocks};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;
final class UnitPaymentCorrectionTest extends TestCase
{
    use ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
    private Connection $db;private array $f;
    protected function setUp():void{$this->db=Bootstrap::buildContainer()->get(Connection::class);$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testPartialReliefPreservesMixedPaymentAndAlreadyUsedUnrelatedCredit():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('90000','5000');$payments=$this->correctionPayments();
        $reserve=$payments->reserve($f['supplier'],$base['operation']['credit_id'],1,['amount_cents'=>'3000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic unrelated credit disposition','recipient_account'=>'1000000005/0100'],'reserve',$f['actor']);
        $refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$this->correctionBank('-30.00'),'expected_version'=>0],'amount_cents'=>'3000','accounting_date'=>'2026-02-01','reservation_id'=>$reserve['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic unrelated refund'];
        $payments->apply($f['supplier'],$refund,$payments->preview($f['supplier'],$refund)['preview_hash'],'refund',$f['actor']);
        $request=$this->negativePaymentCorrection($base,'800','10000');$repo=$this->correctionWriter();$preview=$repo->preview($f['supplier'],$request);
        self::assertSame('10000',$preview['snapshot']['relief_cents']);
        $done=$repo->apply($f['supplier'],$request,$preview['preview_hash'],'correct',$f['actor']);
        self::assertSame('10000',$payments->credit($f['supplier'],$done['credit_id'])['balance_cents']);
        self::assertSame('2000',$payments->credit($f['supplier'],$base['operation']['credit_id'])['balance_cents']);
        self::assertSame('0',(new PaymentSourceProof($this->db))->current($f['supplier'],'bank',$base['bank'])['available_cents']);
        self::assertSame('80000',(new DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
        self::assertSame($done['id'],$repo->apply($f['supplier'],$request,$preview['preview_hash'],'correct',$f['actor'])['id']);
        try{$payments->reverse($f['supplier'],$base['operation']['id'],2,'2026-02-03','Synthetic invalid undo','undo-payment',$f['actor']);self::fail('Relieved allocation reversed independently.');}catch(PaymentException $e){self::assertSame('property_payment_allocation_has_relief',$e->errorCode);}
        try{$payments->reverse($f['supplier'],$done['operation_id'],2,'2026-02-03','Synthetic invalid undo','undo-relief',$f['actor']);self::fail('Relief detached from negative charge.');}catch(PaymentException $e){self::assertSame('property_payment_correction_owner_required',$e->errorCode);}
        $q=$this->db->pdo()->prepare("SELECT SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND a.account_code='315'");$q->execute([$f['supplier']]);
        self::assertSame(bcdiv(bcsub($base['total_claims'],'100000',0),'100',2),$q->fetchColumn());
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFullReliefReverseRequiresUnusedNewCreditAndRestoresExactOriginalPayment():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('100000','0');$request=$this->negativePaymentCorrection($base,'0','100000');$repo=$this->correctionWriter();$hash=$repo->preview($f['supplier'],$request)['preview_hash'];$done=$repo->apply($f['supplier'],$request,$hash,'full',$f['actor']);
        $reservation=$this->correctionPayments()->reserve($f['supplier'],$done['credit_id'],1,['amount_cents'=>'1','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic reserve','recipient_account'=>'1000000005/0100'],'reserve',$f['actor']);
        $reverse=['expected_version'=>2,'accounting_date'=>'2026-02-03','effective_at'=>'2026-02-03T10:00:00+01:00','reason'=>'Synthetic correction reversal','evidence'=>'Synthetic new decision'];
        try{$repo->reverse($f['supplier'],$done['id'],$reverse,'reverse',$f['actor']);self::fail('Reserved relief credit reversed.');}catch(PaymentException $e){self::assertSame('property_payment_credit_in_use',$e->errorCode);}
        self::assertSame('posted',$repo->find($f['supplier'],$done['id'])['status']);
        $this->correctionPayments()->cancelReservation($f['supplier'],$reservation['id'],1,'Synthetic withdrawal','cancel',$f['actor']);
        $realIssuance=$this->paymentFixtureContainer()->get(IssuanceRepository::class);
        $issuance=$this->createMock(IssuanceRepository::class);$issuance->expects(self::once())->method('reversePaymentCorrection')->willReturnCallback(function(...$args)use($realIssuance){$realIssuance->reversePaymentCorrection(...$args);throw new \RuntimeException('Synthetic mirror failure');});
        $failing=new ReliefCorrectionRepository($this->db,$this->paymentFixtureContainer()->get(PostingService::class),$issuance,$this->correctionPayments());
        try{$failing->reverse($f['supplier'],$done['id'],$reverse,'reverse',$f['actor']);self::fail('Second mirror failure not propagated.');}catch(\RuntimeException $e){self::assertSame('Synthetic mirror failure',$e->getMessage());}
        self::assertSame('posted',$repo->find($f['supplier'],$done['id'])['status']);$q=$this->db->pdo()->prepare("SELECT COUNT(*) FROM unit_payment_audit WHERE supplier_id=? AND action='reversal_requested'");$q->execute([$f['supplier']]);self::assertSame(0,(int)$q->fetchColumn());
        $reversed=$repo->reverse($f['supplier'],$done['id'],$reverse,'reverse',$f['actor']);self::assertSame('reversed',$reversed['status']);
        self::assertSame('100000',(new DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
        self::assertSame('0',(new PaymentSourceProof($this->db))->current($f['supplier'],'bank',$base['bank'])['available_cents']);
        self::assertSame($done['id'],$repo->apply($f['supplier'],$request,$hash,'full',$f['actor'])['id']);
        $changed=$request;$changed['reason']='Different original payload';try{$repo->apply($f['supplier'],$changed,$hash,'full',$f['actor']);self::fail('Changed retry accepted.');}catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
    }
    public function testCorrectionRefusesWrongAnchorsAndRollsBackActualS3PostIfReliefWriteFails():void
    {
        $f=$this->f;$base=$this->settledPaymentFixture('90000','0');$request=$this->negativePaymentCorrection($base,'800','10000');$repo=$this->correctionWriter();
        $bad=$request;$bad['anchors'][0]['original_line_id']=$bad['anchors'][0]['adjustment_line_id'];
        try{$repo->preview($f['supplier'],$bad);self::fail('Negative adjustment accepted as original principal.');}catch(PaymentException $e){self::assertSame('property_payment_correction_anchor',$e->errorCode);}
        $bad=$request;$bad['accounting_date']='2027-01-01';try{$repo->preview($f['supplier'],$bad);self::fail('Cross-period detached correction accepted.');}catch(PaymentException $e){self::assertSame('property_payment_correction_date_mismatch',$e->errorCode);}
        $bad=$request;$bad['reliefs'][0]['amount_cents']='9999';try{$repo->preview($f['supplier'],$bad);self::fail('Missing cent accepted.');}catch(PaymentException $e){self::assertSame('property_payment_relief_margin',$e->errorCode);}
        $preview=$repo->preview($f['supplier'],$request);$posting=$this->createMock(PostingService::class);$posting->expects(self::once())->method('postDocument')->willThrowException(new \RuntimeException('Synthetic relief posting failure'));
        $writer=new ReliefCorrectionRepository($this->db,$posting,Bootstrap::buildContainer()->get(IssuanceRepository::class),$this->correctionPayments());
        try{$writer->apply($f['supplier'],$request,$preview['preview_hash'],'rollback',$f['actor']);self::fail('Expected failure absent.');}catch(\RuntimeException $e){self::assertSame('Synthetic relief posting failure',$e->getMessage());}
        $q=$this->db->pdo()->prepare('SELECT status,journal_entry_id FROM unit_charges WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$request['adjustment_charge_id']]);self::assertSame(['status'=>'draft','journal_entry_id'=>null],$q->fetch(\PDO::FETCH_ASSOC));
        $q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_payment_corrections WHERE supplier_id=?');$q->execute([$f['supplier']]);self::assertSame(0,(int)$q->fetchColumn());
        self::assertSame('90000',(new DebtRepository($this->db))->current($f['supplier'],$base['debt']['id'])['paid']['principal:'.$base['line_id']]);
        self::assertSame('0',(new PaymentSourceProof($this->db))->current($f['supplier'],'bank',$base['bank'])['available_cents']);
    }
}
