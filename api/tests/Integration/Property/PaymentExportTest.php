<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentExportRepository;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
final class PaymentExportTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testExportOwnsReservationOnceAndPartialRefundCancellationOnlyReleasesRemainder():void
    {
        $supplier=$this->f['supplier'];$base=$this->settledPaymentFixture('600','400');$credit=$base['operation']['credit_id'];
        $r=$this->correctionPayments()->reserve($supplier,$credit,1,['amount_cents'=>'300','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic refund instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic export account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
        $q=$this->db->pdo()->prepare("SELECT id FROM supplier_bank_accounts WHERE supplier_id=? AND currency='CZK' AND is_active=1 AND kind<>'credit_card' ORDER BY id LIMIT 1");$q->execute([$supplier]);$bank=(int)$q->fetchColumn();self::assertGreaterThan(0,$bank);
        $request=['reservation_ids'=>[$r['id']],'expected_versions'=>[$r['id']=>$r['row_version']],'bank_account_id'=>$bank,'due_date'=>'2026-02-01','reason'=>'Synthetic export','payment_symbols'=>[$r['id']=>['allow_missing_variable_symbol'=>true,'message'=>'Synthetic refund']]];
        $repo=Bootstrap::buildContainer()->get(PaymentExportRepository::class);$before=$this->counts();$preview=$repo->preview($supplier,$request);self::assertSame($before,$this->counts());
        $export=$repo->confirm($supplier,$request,$preview['preview_hash'],'opaque-export',$this->f['actor']);$bytes=base64_decode($export['content_base64'],true);self::assertStringContainsString('000000000300',$bytes);self::assertStringEndsWith("5 +\r\n",$bytes);self::assertSame('submitted',$export['reservation_statuses'][$r['id']]);
        $after=$this->counts();self::assertSame($export,$repo->confirm($supplier,$request,$preview['preview_hash'],'opaque-export',$this->f['actor']));self::assertSame($after,$this->counts());
        try{$this->correctionPayments()->cancelReservation($supplier,$r['id'],2,'Unproved cancellation','wrong-cancel',$this->f['actor']);self::fail('Submitted instruction released without evidence.');}catch(PaymentException $e){self::assertSame('property_payment_reservation_not_cancellable',$e->errorCode);}
        try{$repo->confirm($supplier,$request,$preview['preview_hash'],'second-export',$this->f['actor']);self::fail('Exported twice.');}catch(PaymentException $e){self::assertContains($e->errorCode,['property_payment_reservation_version_conflict','property_payment_reservation_not_exportable']);}
        $out=$this->correctionBank('-1.00');$refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$out,'expected_version'=>0],'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic partial bank refund','reservation_id'=>$r['id'],'expected_reservation_version'=>2];
        $operation=$this->correctionPayments()->apply($supplier,$refund,$this->correctionPayments()->preview($supplier,$refund)['preview_hash'],'refund',$this->f['actor']);
        $current=$this->correctionPayments()->reservation($supplier,$r['id']);self::assertSame('100',$current['paid_cents']);self::assertSame('submitted',$current['status']);
        $cancel=$repo->cancelSubmission($supplier,$r['id'],$current['row_version'],'Cancel remaining instruction','Synthetic bank cancellation confirmation','cancel-export',$this->f['actor']);
        self::assertSame('submitted',$cancel['status'],'Textual cancellation of a sent order must not release the reservation.');self::assertTrue($cancel['cancel_requested']);self::assertSame('100',$cancel['paid_cents']);
        $blocked=$this->correctionPayments()->credit($supplier,$credit);self::assertSame('200',$blocked['reserved_cents']);self::assertSame('100',$blocked['available_cents']);
        self::assertSame($cancel,$repo->cancelSubmission($supplier,$r['id'],$current['row_version'],'Cancel remaining instruction','Synthetic bank cancellation confirmation','cancel-export',$this->f['actor']));
        $statement=(int)$this->db->pdo()->query('SELECT statement_id FROM bank_transactions WHERE id='.$out)->fetchColumn();
        $released=$repo->releaseUnexecuted($supplier,$r['id'],$cancel['row_version'],$statement,'Synthetic statement without the refund','release-export',$this->f['actor']);
        self::assertSame('failed',$released['status']);self::assertFalse($released['cancel_requested']);
        $currentCredit=$this->correctionPayments()->credit($supplier,$credit);self::assertSame('300',$currentCredit['balance_cents']);self::assertSame('0',$currentCredit['reserved_cents']);self::assertSame('300',$currentCredit['available_cents']);
        self::assertSame($released,$repo->releaseUnexecuted($supplier,$r['id'],$cancel['row_version'],$statement,'Synthetic statement without the refund','release-export',$this->f['actor']));
        $retry=$repo->confirm($supplier,$request,$preview['preview_hash'],'opaque-export',$this->f['actor']);self::assertSame($export['content_base64'],$retry['content_base64']);self::assertSame('failed',$retry['reservation_statuses'][$r['id']]);
        $request['reason']='Changed request';try{$repo->confirm($supplier,$request,$preview['preview_hash'],'opaque-export',$this->f['actor']);self::fail('Changed retry accepted.');}catch(PaymentException $e){self::assertSame('property_payment_idempotency_conflict',$e->errorCode);}
        $this->correctionPayments()->reverse($supplier,$operation['id'],$operation['row_version'],'2026-02-02','Synthetic reversal after cancellation','reverse-cancelled-refund',$this->f['actor']);
        $restored=$this->correctionPayments()->reservation($supplier,$r['id']);self::assertSame('failed',$restored['status']);self::assertSame('0',$restored['paid_cents']);
        $creditState=$this->correctionPayments()->credit($supplier,$credit);self::assertSame('400',$creditState['balance_cents']);self::assertSame('0',$creditState['reserved_cents']);
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFullRefundReversalRestoresSubmittedAndLateExportFailureRollsBackOwner():void
    {
        $supplier=$this->f['supplier'];$base=$this->settledPaymentFixture('600','400');$credit=$base['operation']['credit_id'];$real=$this->correctionPayments();
        $r=$real->reserve($supplier,$credit,1,['amount_cents'=>'300','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);$bank=(int)$this->db->pdo()->lastInsertId();
        $request=['reservation_ids'=>[$r['id']],'expected_versions'=>[$r['id']=>1],'bank_account_id'=>$bank,'due_date'=>'2026-02-01','reason'=>'Synthetic export','payment_symbols'=>[$r['id']=>['variable_symbol'=>'20260001']]];
        $fault=$this->createMock(\MyInvoice\Repository\Property\Payments\PaymentRepository::class);
        $fault->expects(self::exactly(2))->method('credit')->willReturnCallback(fn($supplier,$id)=>$real->credit($supplier,$id));
        $fault->expects(self::exactly(3))->method('reservation')->willReturnCallback(function($supplier,$id)use($real):array{$row=$real->reservation($supplier,$id);if($row['status']==='submitted'){self::assertSame(1,$this->counts()['unit_payment_exports']);throw new \RuntimeException('Synthetic late export failure');}return $row;});
        $writer=new PaymentExportRepository($this->db,$fault);$preview=$writer->preview($supplier,$request);$before=$this->counts();
        try{$writer->confirm($supplier,$request,$preview['preview_hash'],'export',$this->f['actor']);self::fail('Late write did not fail.');}catch(\RuntimeException $e){self::assertSame('Synthetic late export failure',$e->getMessage());}
        self::assertSame($before,$this->counts());self::assertSame('reserved',$real->reservation($supplier,$r['id'])['status']);self::assertTrue($this->db->pdo()->inTransaction());
        $writer=new PaymentExportRepository($this->db,$real);$export=$writer->confirm($supplier,$request,$preview['preview_hash'],'export',$this->f['actor']);
        $out=$this->correctionBank('-3.00');$refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$out,'expected_version'=>0],'amount_cents'=>'300','accounting_date'=>'2026-02-01','reason'=>'Synthetic full refund','reservation_id'=>$r['id'],'expected_reservation_version'=>2];
        $operation=$real->apply($supplier,$refund,$real->preview($supplier,$refund)['preview_hash'],'full-refund',$this->f['actor']);self::assertSame('paid',$real->reservation($supplier,$r['id'])['status']);
        $real->reverse($supplier,$operation['id'],$operation['row_version'],'2026-02-02','Synthetic refund reversal','refund-reverse',$this->f['actor']);
        $restored=$real->reservation($supplier,$r['id']);self::assertSame('submitted',$restored['status']);self::assertSame('0',$restored['paid_cents']);self::assertSame('300',$real->credit($supplier,$credit)['reserved_cents']);
        self::assertSame('submitted',$writer->find($supplier,$export['id'])['reservation_statuses'][$r['id']]);
        try{$real->cancelReservation($supplier,$r['id'],$restored['row_version'],'Unproved cancellation','forbidden',$this->f['actor']);self::fail('Restored export lost cancellation guard.');}catch(PaymentException $e){self::assertSame('property_payment_reservation_not_cancellable',$e->errorCode);}
    }
    public function testSentOrderIsReleasedOnlyByStatementProvingNonExecution():void
    {
        $supplier=$this->f['supplier'];$base=$this->settledPaymentFixture('600','400');$credit=$base['operation']['credit_id'];
        $r=$this->correctionPayments()->reserve($supplier,$credit,1,['amount_cents'=>'300','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic refund instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic export account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);$bank=(int)$this->db->pdo()->lastInsertId();
        $request=['reservation_ids'=>[$r['id']],'expected_versions'=>[$r['id']=>1],'bank_account_id'=>$bank,'due_date'=>'2026-02-01','reason'=>'Synthetic export','payment_symbols'=>[$r['id']=>['allow_missing_variable_symbol'=>true]]];
        $repo=Bootstrap::buildContainer()->get(PaymentExportRepository::class);$repo->confirm($supplier,$request,$repo->preview($supplier,$request)['preview_hash'],'proof-export',$this->f['actor']);
        $pdo=$this->db->pdo();
        $statement=function(string $account,string $date)use($pdo,$supplier):int{$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic proof',?,?,'0100','CZK',?)")->execute([$supplier,hash('sha256',random_bytes(20)),$account,$date]);return (int)$pdo->lastInsertId();};
        $reject=function(int $statement,string $code)use($repo,$supplier,$r):void{try{$repo->releaseUnexecuted($supplier,$r['id'],2,$statement,'Synthetic proof','release-'.$statement,$this->f['actor']);self::fail('Release accepted without proof: '.$code);}catch(PaymentException $e){self::assertSame('property_payment_'.$code,$e->errorCode);}};
        $reject($statement('1000000005','2026-01-31'),'statement_before_due_date');
        $reject($statement('2000000042','2026-02-05'),'statement_account_mismatch');
        $executed=(int)$pdo->query('SELECT statement_id FROM bank_transactions WHERE id='.$this->correctionBank('-3.00'))->fetchColumn();
        $reject($executed,'refund_executed_on_statement');
        self::assertSame('submitted',$this->correctionPayments()->reservation($supplier,$r['id'])['status']);
        self::assertSame('300',$this->correctionPayments()->credit($supplier,$credit)['reserved_cents']);
        $released=$repo->releaseUnexecuted($supplier,$r['id'],2,$statement('1000000005','2026-02-03'),'Synthetic later statement without the refund','release-ok',$this->f['actor']);
        self::assertSame('failed',$released['status']);
        self::assertSame('0',$this->correctionPayments()->credit($supplier,$credit)['reserved_cents']);
    }
    private function counts():array
    {
        $out=[];foreach(['unit_payment_exports','unit_payment_export_items','unit_payment_audit','journal_entries'] as $table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$table]=(int)$q->fetchColumn();}return $out;
    }
}
