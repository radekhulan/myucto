<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Service\Property\Receivables\ReceivableTimeline;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
final class ReceivableHistoryTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;private array $f;
    protected function setUp():void{$this->db=Bootstrap::buildContainer()->get(Connection::class);$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testActualPaymentReversalKeepsOriginalHistoricalBalanceAndSeparateCredit():void
    {
        $base=$this->settledPaymentFixture('3000','5000');$supplier=$this->f['supplier'];$reader=new FinancialHistoryRepository($this->db);
        $this->correctionPayments()->reverse($supplier,$base['operation']['id'],2,'2026-02-03','Synthetic withdrawal','reverse',$this->f['actor']);
        $history=$reader->read($supplier,true);$events=$history['debts'][$base['debt']['id']]['events'];
        $before=ReceivableTimeline::sum($events,'2026-02-02T23:59:59.999999+01:00','2027-01-01T00:00:00Z');
        self::assertSame('97000',$before['gross_receivable_cents']);
        self::assertSame('100000',ReceivableTimeline::sum($events,'2026-02-04T00:00:00+01:00','2027-01-01T00:00:00Z')['gross_receivable_cents']);
        self::assertSame('5000',ReceivableTimeline::sum($history['credits'][$base['operation']['credit_id']]['events'],'2026-02-04T00:00:00+01:00','2027-01-01T00:00:00Z')['gross_credit_cents']);
        self::assertNotEmpty($history['unregistered_charge_line_ids']);
    }
    public function testGrossHouseSaldoCountsSjmOnceAndNeverNetsDifferentCreditPerson():void
    {
        $base=$this->settledPaymentFixture('3000','5000');$projector=new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db);$supplier=$this->f['supplier'];
        $result=$projector->at($supplier,'2026-02-02T00:00:00+01:00','2027-01-01T00:00:00Z',[],true);
        self::assertSame(bcsub($base['total_claims'],'3000',0),$result['totals']['gross_receivable_cents']);self::assertSame('5000',$result['totals']['gross_credit_cents']);
        $a=$projector->at($supplier,'2026-02-02T00:00:00+01:00','2027-01-01T00:00:00Z',['client_id'=>$this->f['clients'][1]],true);
        $b=$projector->at($supplier,'2026-02-02T00:00:00+01:00','2027-01-01T00:00:00Z',['client_id'=>$this->f['clients'][2]],true);
        self::assertSame(array_column($a['claims'],'claim_key'),array_column($b['claims'],'claim_key'));
        self::assertSame($a['totals']['gross_receivable_cents'],$b['totals']['gross_receivable_cents']);self::assertSame('5000',$a['totals']['gross_credit_cents']);self::assertSame('0',$b['totals']['gross_credit_cents']);
        self::assertFalse($result['coverage']['complete']);self::assertTrue($result['coverage']['financial_complete']);self::assertSame('none',$result['coverage']['service_settlements']);
        $reconciliation=(new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db))->at($supplier,'2026-02-02');self::assertTrue($reconciliation['matches'],json_encode($reconciliation,JSON_THROW_ON_ERROR));
        self::assertTrue((new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db))->at($supplier,'2026-02-02',$base['charge']['unit_id'])['matches']);
        $unit=$projector->at($supplier,'2026-02-02T00:00:00+01:00','2027-01-01T00:00:00Z',['unit_id'=>$this->f['units']['U02']],true);
        $person=$projector->at($supplier,'2026-02-02T00:00:00+01:00','2027-01-01T00:00:00Z',['client_id'=>$this->f['clients'][3]],true);
        self::assertSame(bcdiv($unit['totals']['gross_receivable_cents'],'2',0),$person['totals']['gross_receivable_cents']);
        self::assertSame(array_column($unit['claims'],'claim_key'),array_column($person['claims'],'claim_key'));
        self::assertSame([], $projector->at($supplier,'2025-12-31T00:00:00Z','2027-01-01T00:00:00Z',[],true)['claims']);

    }
    public function testPartialRefundAndCancellationReserveOnlyRemainingCreditAtRecordedInstant():void
    {
        $base=$this->settledPaymentFixture('1','10000');$f=$this->f;$payments=$this->correctionPayments();$credit=$base['operation']['credit_id'];
        $reservation=$payments->reserve($f['supplier'],$credit,1,['amount_cents'=>'6000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic refund order','recipient_account'=>'1000000005/0100'],'reserve',$f['actor']);
        $bank=$this->correctionBank('-20.00');$request=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'2000','accounting_date'=>'2026-02-02','reason'=>'Synthetic partial refund','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1];
        $payments->apply($f['supplier'],$request,$payments->preview($f['supplier'],$request)['preview_hash'],'refund',$f['actor']);
        $before=\MyInvoice\Repository\Property\Receivables\PartyEventRepository::now();
        $payments->cancelReservation($f['supplier'],$reservation['id'],2,'Synthetic withdrawal of remaining order','cancel-reserve',$f['actor']);
        $after=\MyInvoice\Repository\Property\Receivables\PartyEventRepository::now();$events=(new FinancialHistoryRepository($this->db))->read($f['supplier'],true)['credits'][$credit]['events'];
        $past=ReceivableTimeline::sum($events,'2026-02-03T00:00:00+01:00',$after);self::assertSame('8000',$past['gross_credit_cents']);self::assertSame('0',$past['reserved_credit_cents']);
        $pending=ReceivableTimeline::sum($events,$before,$before);self::assertSame('4000',$pending['reserved_credit_cents']);self::assertSame('4000',$pending['available_credit_cents']);
        $cancelled=ReceivableTimeline::sum($events,$after,$after);self::assertSame('8000',$cancelled['gross_credit_cents']);self::assertSame('0',$cancelled['reserved_credit_cents']);self::assertSame('8000',$cancelled['available_credit_cents']);
    }

}
