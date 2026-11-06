<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;

final class PaymentOperationsReadTest extends TestCase
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
    public function testActualPostedDebtCreditReservationAndCorrectionRemainGrossAndTenantScoped():void
    {
        $base=$this->settledPaymentFixture('600','400');$supplier=$this->f['supplier'];$read=new PaymentOperationsReadRepository($this->db);
        $debt=$read->page($supplier,'debts')['items'][0];
        self::assertSame($base['debt']['id'],$debt['id']);self::assertSame(bcsub($debt['amount_cents'],'600',0),$debt['remaining_cents']);
        $credit=$read->page($supplier,'credits')['items'][0];self::assertSame('400',$credit['balance_cents']);self::assertSame('400',$credit['available_cents']);self::assertCount(1,$credit['parties']);
        $reservation=$this->correctionPayments()->reserve($supplier,$credit['id'],$credit['row_version'],['amount_cents'=>'250','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic partial refund instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        $row=$read->page($supplier,'credits')['items'][0];self::assertSame('400',$row['balance_cents']);self::assertSame('250',$row['reserved_cents']);self::assertSame('150',$row['available_cents']);
        $rows=$read->page($supplier,'reservations')['items'];self::assertSame($reservation['id'],$rows[0]['id']);self::assertSame('250',$rows[0]['remaining_cents']);
        $proof=$this->correctionPayments()->credit($supplier,$credit['id']);foreach(['balance_cents','reserved_cents','available_cents'] as $key)self::assertSame($proof[$key],$row[$key]);
        $correction=$this->negativePaymentCorrection($base,'5','100');$preview=$this->correctionWriter()->preview($supplier,$correction);$saved=$this->correctionWriter()->apply($supplier,$correction,$preview['preview_hash'],'correct',$this->f['actor']);
        $debt=$read->page($supplier,'debts')['items'][0];self::assertSame('0',$debt['remaining_cents']);
        self::assertSame('100',$read->page($supplier,'corrections')['items'][0]['relief_cents']);
        $credits=$read->page($supplier,'credits')['items'];self::assertSame('500',array_reduce($credits,fn($s,$r)=>bcadd($s,$r['balance_cents'],0),'0'));
        $chargePage=$read->page($supplier,'charges',0,2);self::assertCount(2,$chargePage['items']);self::assertNotNull($chargePage['next_cursor']);$next=$read->page($supplier,'charges',$chargePage['next_cursor'],2);self::assertGreaterThan($chargePage['next_cursor'],$next['items'][0]['id']);
        foreach(['charges','debts','credits','reservations','operations','corrections'] as $kind)self::assertSame([],$read->page($supplier+100000,$kind)['items']);
        self::assertSame([],$read->page($supplier,'credits',0,25,$this->f['units']['G12']+100000)['items']);
        $operation=$read->page($supplier,'operations',0,25)['items'][0];self::assertSame($base['operation']['id'],$operation['id']);self::assertNotEmpty($operation['allocations']);
        $this->correctionPayments()->cancelReservation($supplier,$reservation['id'],$reservation['row_version'],'Synthetic cancellation','cancel',$this->f['actor']);
        self::assertSame('0',$read->page($supplier,'reservations')['items'][0]['remaining_cents']);
        $now=\MyInvoice\Repository\Property\Receivables\PartyEventRepository::now();
        $projection=(new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db))->at($supplier,$now,$now,[]);
        $card=$read->card($supplier,$now,$now);self::assertSame($projection['totals'],$card['totals']);self::assertSame($projection['snapshot_hash'],$card['snapshot_hash']);self::assertFalse($card['coverage']['complete']);
        $detail=$read->detail($supplier,'debts',$base['debt']['id']);self::assertNotEmpty($detail['groups']);self::assertSame($debt['remaining_cents'],$detail['remaining_cents']);
        self::assertNotEmpty($read->parties($supplier,'Synthetic')['items']);self::assertSame([],$read->parties($supplier+100000,'Synthetic')['items']);
    }
    public function testPageQueryCountDoesNotGrowWithNumberOfCreditRows():void
    {
        $this->settledPaymentFixture('600','400');$read=new PaymentOperationsReadRepository($this->db);$supplier=$this->f['supplier'];$pdo=$this->db->pdo();
        $bank=$this->correctionBank('1.00');
        for($i=0;$i<5;$i++){
            $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>$i],'amount_cents'=>'10','accounting_date'=>'2026-02-01','reason'=>'Synthetic page credit','debts'=>[],
                'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$this->f['units']['G12'],'party_ids'=>[$this->f['clients'][1]],'evidence'=>'Synthetic title','due_date'=>'2026-03-01']];
            $this->correctionPayments()->apply($supplier,$request,$this->correctionPayments()->preview($supplier,$request)['preview_hash'],'page-credit-'.$i,$this->f['actor']);
        }
        $counts=[];
        foreach([1,25,100] as $limit){$before=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1];$page=$read->page($supplier,'credits',0,$limit);$counts[]=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1]-$before;self::assertCount($limit===1?1:6,$page['items']);}
        self::assertSame([4,4,4],$counts);
    }
}
