<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Funds\FundingRead;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class FundingHistoryTest extends TestCase
{
    use FundingFixture;
    public static function sourceHistories():array{return [['income',false],['income',true],['transfer',false],['transfer',true],['issued',false],['issued',true],['opening',true]];}
    #[DataProvider('sourceHistories')]
    public function testLaterReversalPreservesHistoricalSourceDetails(string $kind,bool $knowledge):void
    {
        $this->fundingOpening();$s=$this->f['supplier'];$date='2026-03-01';$operation=null;$charge=null;
        if(in_array($kind,['income','transfer'],true)){
            $operation=$this->fundingApply($this->fundingRequest('income','2026-03-01','6000000')+['bank_id'=>$this->fundingBank('2026-03-01','60000.00'),'key'=>$this->fundingKey('2026-03-01')],'history-income');
            if($kind==='transfer'){$lot=$operation['receipt']['effects'][0];$date='2026-03-02';$operation=$this->fundingApply($this->fundingRequest('transfer',$date,'100')+['dispositions'=>[['source_id'=>$lot['id'],'amount_cents'=>'100','party_ids'=>$lot['parties']['members'],'evidence_ref'=>'Synthetic historical transfer']],'target'=>'long_term','purpose_ref'=>'Synthetic future purpose','settlement_due_on'=>'2029-12-31'],'history-transfer');}
            $stamp=$operation['recorded_at'];
        }elseif($kind==='issued'){$batch=$this->fundingIssue();$charge=$batch['charges'][0];$stamp=(string)$this->db->pdo()->query('SELECT MAX(recorded_at) FROM unit_charge_issue_audit WHERE supplier_id='.(int)$s)->fetchColumn();}
        else{$stamp=(string)$this->db->pdo()->query('SELECT MAX(recorded_at) FROM property_opening_batches WHERE supplier_id='.(int)$s)->fetchColumn();}
        $known=$knowledge?(new \DateTimeImmutable($stamp,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'):null;$queryDate=$knowledge?'2026-12-31':$date;$read=new FundingRead($this->db);$before=$read->at($s,$this->f['house']['id'],$queryDate,$known);self::assertTrue($before['matches']);
        if($operation!==null){$request=$this->fundingRequest('reverse','2026-04-01');unset($request['amount_cents']);$this->fundingApply($request+['operation_id'=>$operation['id']],'history-reversal');}
        elseif($charge!==null){$this->container->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class)->reverse($s,$charge['id'],['row_version'=>$charge['row_version'],'accounting_date'=>'2026-04-01','effective_at'=>'2026-04-01T12:00:00+02:00','reason'=>'Synthetic later native reversal','evidence_ref'=>'Synthetic reversal instruction','idempotency_key'=>'history-issued-reversal'],$this->f['actor']);}
        else{$q=$this->db->pdo()->prepare('SELECT id FROM property_opening_batches WHERE supplier_id=? ORDER BY id LIMIT 1');$q->execute([$s]);$original=(new \MyInvoice\Service\Property\Opening\OpeningReadProof($this->db))->batch($s,(int)$q->fetchColumn(),true);$request=\MyInvoice\Service\Property\Opening\OpeningRequest::replay($original['request']);$request['mode']='revision';$request['expected_revision']=1;$request['reason']='Synthetic later opening correction';$owner=$this->container->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$owner->apply($s,$request,$owner->preview($s,$request)['preview_hash'],'history-opening-replacement',$this->f['actor']);}
        $after=$read->at($s,$this->f['house']['id'],$queryDate,$known);self::assertTrue($after['matches']);foreach(['totals','unit_balances','income_lots','sources']as$field)self::assertSame($before[$field],$after[$field],$kind.' historical '.$field);
    }
}
