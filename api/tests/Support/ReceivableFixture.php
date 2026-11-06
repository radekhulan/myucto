<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Repository\Property\{PropertyRegistryRepository,PropertyReadRepository};
trait ReceivableFixture
{
    private function request(array $base,int $owner,int $revision,?int $predecessor,string $at,array $guarantors):array
    {
        $f=$this->f;$state=$f['states']['G12'];$state['ownerships']=[['group_key'=>'owner-'.$revision,'kind'=>'single','title_ref'=>'Synthetic title','members'=>[$owner],'share'=>['num'=>'1','den'=>'1']]];
        $registry=new PropertyRegistryRepository($this->db);$registry->revise($f['supplier'],$base['charge']['unit_id'],'unit',['expected_revision'=>$revision,'effective_from'=>$at,'status'=>'confirmed','state'=>$state,'reason'=>'Synthetic sale','title_ref'=>'Synthetic title','idempotency_key'=>'registry-'.$revision],$f['actor']);
        $unit=(new PropertyReadRepository($this->db))->find($f['supplier'],$base['charge']['unit_id'],$at,null,true);$groups=[];
        foreach([$owner,...$guarantors] as $index=>$id)$groups[]=['group_key'=>($index===0?'owner-':'guarantor-').$id,'role'=>$index===0?'debtor':'guarantor','mode'=>'individual','basis'=>'documented_title','members'=>[$id],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic liability title','legal_reason'=>'Synthetic separately assessed chain','valid_from'=>$at,'valid_to'=>null];
        return ['unit_id'=>$base['charge']['unit_id'],'registry_revision_id'=>$unit['revision']['id'],'effective_at'=>$at,'decision'=>'transfer_confirmed','reason'=>'Synthetic conditional succession',
            'acquisition'=>['kind'=>'contractual_transfer','applicable_law'=>'89/2012 effective 2026','legal_basis'=>'1186(2),(3)','evidence'=>'Synthetic acquired ownership','had_and_could_discover'=>true,'knowledge_evidence'=>'Synthetic delivered certificate','guarantee_evidence'=>'Synthetic explicit continuing guarantee assessment'],
            'debts'=>[['debt_id'=>$base['debt']['id'],'expected_event_id'=>$predecessor,'groups'=>$groups]]];
    }
    private function bankAt(string $date,string $amount):int
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency) VALUES (?,?,?,'CZK')")->execute([$statement,$date,$amount]);$id=(int)$pdo->lastInsertId();
        $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>'debit','amount'=>$amount],['account_code'=>'379.900','side'=>'credit','amount'=>$amount]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);return $id;
    }
}
