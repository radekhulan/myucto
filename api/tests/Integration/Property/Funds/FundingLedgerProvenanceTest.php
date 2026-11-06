<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Funds\FundingRead;
use MyInvoice\Service\Property\Funds\FundingSourceProof;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;
final class FundingLedgerProvenanceTest extends TestCase
{
    use FundingFixture;
    private function manual(array $lines):int
    {
        $dims=(new FundingSourceProof($this->db))->dimensions($this->f['supplier'],$this->f['units']['U01']);
        foreach($lines as&$line)if(str_starts_with($line['account_code'],'955')){$line['dimensions']=$dims;$line['dimension_origins']=array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null]);}unset($line);
        return $this->container->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,$lines,['entry_date'=>'2026-03-01','posted_by'=>$this->f['actor']]);
    }
    public function testBalancedUnownedEntriesCannotProduceAFalseGreen():void
    {
        $this->fundingOpening();$this->manual([['account_code'=>'955.100','side'=>'debit','amount'=>'7.00'],['account_code'=>'955.100','side'=>'credit','amount'=>'7.00']]);$read=(new FundingRead($this->db))->at($this->f['supplier'],null,'2026-03-01');
        self::assertSame($read['totals'],$read['ledger_totals']);self::assertFalse($read['matches'],'A balanced orphan pair was accepted as owned funding.');self::assertCount(2,$read['unregistered_line_ids']);
    }
    public function testNativeLedgerReconciliationPreservesRedStornoSign():void
    {
        $this->fundingOpening();$this->manual([['account_code'=>'221','side'=>'debit','amount'=>'7.00','is_red_storno'=>true],['account_code'=>'955.100','side'=>'credit','amount'=>'7.00','is_red_storno'=>true]]);$read=(new FundingRead($this->db))->at($this->f['supplier'],null,'2026-03-01');
        self::assertSame('30000000',$read['totals']['955.100']);self::assertSame('29999300',$read['ledger_totals']['955.100']);self::assertFalse($read['matches']);self::assertCount(1,$read['unregistered_line_ids']);
    }

    public function testAnotherHouseDoesNotInvalidateScopedReconciliation():void
    {
        $this->fundingOpening();$s=$this->f['supplier'];$registry=new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db);$house=$registry->create($s,'house',self::registryRequest('SECOND',['name'=>'Synthetic second house','active'=>true,'address'=>['street'=>'Synthetic second street','city'=>'Synthetic','postal_code'=>'10000','country'=>'CZ'],'declaration_ref'=>'Synthetic separate deed','share_scope'=>'SECOND','registry_complete'=>true]),$this->f['actor']);$state=$this->f['states']['U01'];$state['unit_no']='SECOND-U01';$state['identifiers']=[];$state['attributes']['share']=['num'=>'1','den'=>'1'];$registry->create($s,'unit',self::registryRequest('SECOND-U01',$state)+['parent_id'=>$house['id']],$this->f['actor']);
        $request=$this->fundingRequest('income','2026-03-01','10000')+['bank_id'=>$this->fundingBank('2026-03-01','100.00'),'key'=>$this->fundingKey('2026-03-01')];$request['house_id']=$house['id'];$this->fundingApply($request,'other-house-income');$read=new FundingRead($this->db);self::assertTrue($read->at($s,null,'2026-03-01')['matches']);$scoped=$read->at($s,$this->f['house']['id'],'2026-03-01');self::assertTrue($scoped['matches'],json_encode($scoped['unregistered_line_ids']));self::assertSame([],$scoped['unregistered_line_ids']);self::assertSame('30000000',$scoped['totals']['955.100']);
    }
}
