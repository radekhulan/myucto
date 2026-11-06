<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\{CreditSetoffRepository,PaymentOperationsReadRepository};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;

final class PaymentOperationUnitScopeTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
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
    public function testUnitHistoryContainsRefundAndItsReversalWithKeysetPagination():void
    {
        $base=$this->settledPaymentFixture('600','400');$supplier=$this->f['supplier'];$payments=$this->correctionPayments();
        $reservation=$payments->reserve($supplier,$base['operation']['credit_id'],1,['amount_cents'=>'300','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic partial refund','recipient_account'=>'1000000005/0100'],'unit-refund-reserve',$this->f['actor']);
        $request=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$this->correctionBank('-1.00'),'expected_version'=>0],'amount_cents'=>'100','accounting_date'=>'2026-02-02','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic unit refund'];
        $refund=$payments->apply($supplier,$request,$payments->preview($supplier,$request)['preview_hash'],'unit-refund',$this->f['actor']);
        $this->assertUnitOperation($base['charge']['unit_id'],$refund['id']);
        $payments->reverse($supplier,$refund['id'],2,'2026-02-03','Synthetic refund reversal','unit-refund-reverse',$this->f['actor']);
        $this->assertUnitOperation($base['charge']['unit_id'],$refund['id']);
        $this->assertUnitOperation($base['charge']['unit_id'],$this->reversalId($refund['id']));
    }
    public function testCrossUnitSetoffAndReversalAppearForBothDebtAndConsumedCredit():void
    {
        $base=$this->settledPaymentFixture('1','0');$supplier=$this->f['supplier'];$debtor=$base['debt']['snapshot']['liability_groups'][0]['members'][0];
        $unit=array_values(array_filter($this->f['units'],fn($id)=>$id!==$base['charge']['unit_id']))[0];
        $payments=$this->correctionPayments();
        $creditRequest=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$this->correctionBank('100.00'),'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reason'=>'Synthetic independent cross-unit credit','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$unit,'party_ids'=>[$debtor],'evidence'=>'Synthetic reciprocal creditor title','due_date'=>'2026-02-01']];
        $credit=$payments->apply($supplier,$creditRequest,$payments->preview($supplier,$creditRequest)['preview_hash'],'cross-unit-credit',$this->f['actor']);
        $request=['kind'=>'setoff','credit_id'=>$credit['credit_id'],'expected_credit_version'=>1,'amount_cents'=>'100','accounting_date'=>'2026-02-01','reason'=>'Synthetic cross-unit setoff','debts'=>[$base['debt']['id']],
            'payment'=>['debtor_id'=>$debtor,'payer_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic debtor direction']],
            'legal'=>['certain'=>true,'unconditional'=>true,'scope_cents'=>'100','mutuality_evidence'=>'Synthetic same legal creditor','eligibility_evidence'=>'Synthetic due and enforceable debt','declaration_evidence'=>'Synthetic unconditional declaration','delivery_evidence'=>'Synthetic receipt','delivered_at'=>'2026-02-01T10:00:00+01:00','effect_evidence'=>'Synthetic effect date']];
        $writer=$this->paymentFixtureContainer()->get(CreditSetoffRepository::class);
        $setoff=$writer->apply($supplier,$request,$writer->preview($supplier,$request)['preview_hash'],'cross-unit-setoff',$this->f['actor']);
        foreach([$unit,$base['charge']['unit_id']] as $id)$this->assertUnitOperation($id,$setoff['id']);
        $payments->reverse($supplier,$setoff['id'],2,'2026-02-02','Synthetic setoff reversal','cross-unit-setoff-reverse',$this->f['actor']);
        foreach([$unit,$base['charge']['unit_id']] as $id){$this->assertUnitOperation($id,$setoff['id']);$this->assertUnitOperation($id,$this->reversalId($setoff['id']));}
    }
    private function reversalId(int $original):int
    {
        $q=$this->db->pdo()->prepare("SELECT id FROM unit_payment_operations WHERE supplier_id=? AND original_operation_id=? AND kind='reversal'");
        $q->execute([$this->f['supplier'],$original]);$id=(int)$q->fetchColumn();self::assertGreaterThan(0,$id);return $id;
    }
    private function assertUnitOperation(int $unit,int $id):void
    {
        $read=new PaymentOperationsReadRepository($this->db);$supplier=$this->f['supplier'];
        $all=array_column($read->page($supplier,'operations',0,100)['items'],null,'id');self::assertArrayHasKey($id,$all);
        $cursor=0;$ids=[];
        do{$page=$read->page($supplier,'operations',$cursor,1,$unit);foreach($page['items'] as $item)$ids[]=$item['id'];$cursor=$page['next_cursor'];}while($cursor!==null);
        self::assertContains($id,$ids,'Related operation disappeared from unit history.');
        self::assertSame(count($ids),count(array_unique($ids)));
        $items=$read->page($supplier,'operations',0,25,$unit,$id)['items'];self::assertCount(1,$items);self::assertSame($all[$id],$items[0]);
        $label=$read->page($supplier,'charges',0,1,$unit)['items'][0]['unit_label'];self::assertContains($label,$items[0]['unit_labels']);
        self::assertSame([],$read->page($supplier+100000,'operations',0,25,$unit)['items']);
        self::assertSame([],$read->page($supplier,'operations',0,25,$unit+100000,$id)['items']);
    }
}
