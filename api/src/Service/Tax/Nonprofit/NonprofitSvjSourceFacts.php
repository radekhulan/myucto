<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class NonprofitSvjSourceFacts
{
    public function __construct(private readonly Connection $db) {}

    public function attach(int $supplier,array $snapshot):array
    {
        if(($snapshot['legal_profile']['profile']['kind']??null)!=='svj')return$snapshot;
        $ids=[];foreach($snapshot['sources']as$source)$ids[(int)($source['origin']['line_id']??$source['line_id'])]=true;
        if($ids===[])return$snapshot;
        $s=$this->db->pdo()->prepare('SELECT DISTINCT e.case_id,e.receipt_json FROM property_service_lifecycle_events e WHERE e.supplier_id=? AND e.receipt_json IS NOT NULL ORDER BY e.case_id,e.id');$s->execute([$supplier]);$cases=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){$receipt=json_decode($row['receipt_json'],true,512,JSON_THROW_ON_ERROR);if(self::touches($receipt,$ids))$cases[(int)$row['case_id']]=true;}
        $map=[];$owner=new ServiceLifecycleRepository($this->db);
        foreach(array_keys($cases)as$id){
            $state=$owner->reconciliationSources($supplier,$id);
            foreach($state['pending']as$pending)if(($pending['accounting_date']??'')>=$snapshot['period']['starts_on']&&($pending['accounting_date']??'')<=$snapshot['period']['ends_on'])NonprofitTaxRules::invalid('Vyúčtování služeb obsahuje nedokončenou operaci v posuzovaném období.');
            foreach(self::project($state)as$line=>$facts){if(isset($map[$line])&&$map[$line]!==$facts)NonprofitTaxRules::invalid('Řádek služby má více právních vlastníků.');$map[$line]=$facts;}
        }
        $funding=new FundingRepository($this->db);
        $query=$this->db->pdo()->prepare("SELECT id FROM property_funding_operations WHERE supplier_id=? AND kind='draw' AND accounting_date<=? AND receipt_json IS NOT NULL ORDER BY id FOR UPDATE");$query->execute([$supplier,$snapshot['period']['ends_on']]);
        foreach($query->fetchAll(PDO::FETCH_COLUMN)as$id){
            $operation=$this->fundingOperation($funding,$supplier,(int)$id);
            $reverse=$this->db->pdo()->prepare('SELECT id FROM property_funding_operations WHERE supplier_id=? AND reversal_of=? AND receipt_json IS NOT NULL ORDER BY id FOR UPDATE');$reverse->execute([$supplier,$operation['id']]);$reverseIds=$reverse->fetchAll(PDO::FETCH_COLUMN);
            if(count($reverseIds)>1)NonprofitTaxRules::invalid('Příspěvek správy má více protizápisů.');
            $reversal=$reverseIds===[]?null:$this->fundingOperation($funding,$supplier,(int)$reverseIds[0]);
            if(!self::touches($operation['snapshot'],$ids)&&!self::touches($operation['receipt'],$ids)&&($reversal===null||!self::touches($reversal['receipt'],$ids)))continue;
            $cost=$operation['snapshot']['proofs']['cost'];
            if(isset($ids[(int)$cost['line']['id']])){
                $actual=(new PaymentJournalProof($this->db))->current($supplier,$cost['ledger']['entry']['id']);
                if(PropertyInput::hash($actual)!==PropertyInput::hash($cost['ledger']))NonprofitTaxRules::invalid('Původní náklad správy se změnil a vyžaduje nové posouzení.');
            }
            foreach(self::projectFunding($operation,$reversal,$snapshot['period']['ends_on'])as$line=>$facts){
                if(!isset($map[$line])){$map[$line]=$facts;continue;}
                if(($map[$line]['owner']??null)!=='property_funding'||$map[$line]['kind']!=='expense'||$facts['kind']!=='expense'||$map[$line]['cost_cents']!==$facts['cost_cents']||$map[$line]['ledger_hash']!==$facts['ledger_hash'])NonprofitTaxRules::invalid('Řádek má neslučitelné právní podklady správy.');
                $map[$line]['covered_cost_cents']+=$facts['covered_cost_cents'];$map[$line]['funding_sources']=array_merge($map[$line]['funding_sources'],$facts['funding_sources']);
                if($map[$line]['covered_cost_cents']>$map[$line]['cost_cents'])NonprofitTaxRules::invalid('Čerpání příspěvků překračuje doložený náklad.');
            }
        }
        foreach($snapshot['sources']as&$source){$line=(int)($source['origin']['line_id']??$source['line_id']);if(!isset($map[$line]))continue;$facts=$map[$line];
            if(isset($facts['cost_cents'])&&((int)$source['line_id']!==$line||abs($source['amount_cents'])!==$facts['cost_cents']))NonprofitTaxRules::invalid('Rozdělený náklad správy vyžaduje přesné nativní přiřazení účelu jednotlivých řádků.');
            $source['svj_owner_facts']=$facts;
        }unset($source);
        return$snapshot;
    }

    public static function project(array $state):array
    {
        if(($state['case']['policy']??null)!=='service_profit_and_loss')return[];
        $case=$state['case'];$base=['owner'=>'property_service_lifecycle','case_id'=>$case['id'],'unit_id'=>$case['unit_id'],'service_id'=>$case['service_id'],'period_from'=>$case['period_from'],'period_to'=>$case['period_to'],'legal_basis'=>'zdp_18_2f_3','case_hash'=>NonprofitTaxRules::hash($case)];$out=[];
        $add=static function(array $ledger,int $event)use(&$out,$base):void{
            foreach($ledger['lines']as$line){$code=$line['account_code'];if(!str_starts_with($code,'5')&&!str_starts_with($code,'6'))continue;
                if(str_starts_with($code,'59')||(str_starts_with($code,'6')&&!str_starts_with($code,'649')))NonprofitTaxRules::invalid('Nativní vyúčtování obsahuje nepodporovaný výsledkový titul.');
                $out[(int)$line['id']]=$base+['event_id'=>$event,'entry_id'=>$ledger['entry']['id'],'line_id'=>$line['id'],'ledger_hash'=>NonprofitTaxRules::hash($ledger),'kind'=>str_starts_with($code,'6')?'revenue':'expense'];
            }
        };
        if($state['estimate']!==null)foreach($state['estimate']['receipt']['sources']as$ledger)$add($ledger,(int)$state['estimate']['event_id']);
        foreach($state['unwinds']as$unwind)$add($unwind['ledger'],(int)($unwind['event_id']??$unwind['id']));
        if($state['final']!==null){$final=$state['final'];$add($final['ledger'],(int)$final['event_id']);$line=$final['invoice']['line'];
            if($final['amount_cents']!==bcmul($line['amount'],'100',0))NonprofitTaxRules::invalid('Právní titul nákladu musí pokrýt přesný původní řádek služby.');
            $out[(int)$line['id']]=$base+['event_id'=>$final['event_id'],'entry_id'=>$final['invoice']['ledger']['entry']['id'],'line_id'=>$line['id'],'ledger_hash'=>NonprofitTaxRules::hash($final['invoice']),'kind'=>'expense'];
        }
        ksort($out,SORT_NUMERIC);return$out;
    }

    private function fundingOperation(FundingRepository $owner,int $supplier,int $id):array
    {
        try{return$owner->operation($supplier,$id);}
        catch(\MyInvoice\Service\Property\Funds\FundingException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Property\Opening\OpeningException $error){throw new NonprofitTaxAssessmentException('nonprofit_tax_svj_source_changed','Nativní podklad správy se změnil a vyžaduje nové posouzení.',409);}
    }

    public static function projectFunding(array $operation,?array $reverse,string $periodEnd):array
    {
        if($operation['kind']!=='draw'||$operation['accounting_date']>$periodEnd)return[];
        $advances=$operation['snapshot']['proofs']['advances']??[];if($advances===[])NonprofitTaxRules::invalid('Příspěvek správy nemá doložený původ.');
        $sources=[];
        foreach($advances as$advance){
            $kind=$advance['pointer']['kind']??null;
            if($kind==='issued'){
                $proof=$advance['proof'];$role=$proof['line']['component']['kind']??null;
                if(!in_array($role,['long_term_advance','admin_advance'],true))NonprofitTaxRules::invalid('Příspěvek správy nemá doložený nativní druh.');
                $sources[]=['kind'=>'issued','source'=>$advance['pointer'],'role'=>$role,'proof_hash'=>PropertyInput::hash($proof)];
            }elseif($kind==='opening'){
                $proof=$advance['proof'];$fact=$proof['snapshot'];$parts=array_column($proof['components'],null,'id');$part=$parts[$advance['pointer']['id']]??null;
                if(($fact['kind']??null)!=='advance'||($fact['service_id']??null)!==null||!in_array($part['role']??null,['long_term_advance','admin_advance'],true))NonprofitTaxRules::invalid('Převzatý příspěvek nemá přesný právní druh a původ.');
                foreach(['title_ref','evidence_ref']as$field)PropertyInput::text($fact[$field]??null,$field,4000);PropertyInput::text($fact['advance_state']['purpose_ref']??null,'purpose_ref',4000);
                $sources[]=['kind'=>'opening','source'=>$advance['pointer'],'item_id'=>$proof['id'],'snapshot_hash'=>$proof['snapshot_hash'],'batch_request_hash'=>$proof['batch']['request_hash'],'role'=>$part['role'],'title_ref'=>$fact['title_ref'],'evidence_ref'=>$fact['evidence_ref'],'purpose_ref'=>$fact['advance_state']['purpose_ref']];
            }else NonprofitTaxRules::invalid('Původ převáděných prostředků vyžaduje samostatně doložené daňové posouzení.');
        }
        $base=['owner'=>'property_funding','legal_basis'=>'zdp_18_2f_2','operation_id'=>$operation['id'],'request_hash'=>$operation['request_hash'],'snapshot_hash'=>$operation['snapshot_hash'],'receipt_hash'=>$operation['receipt_hash'],'classification'=>$operation['request']['classification'],'ownership'=>$operation['request']['ownership']??null,'advance_sources'=>$sources];$out=[];
        $revenue=static function(array $record,array $facts)use(&$out,$periodEnd):void{
            if($record['accounting_date']>$periodEnd)return;
            foreach($record['receipt']['ledger']['lines']as$line){if(!str_starts_with($line['account_code'],'6'))continue;if(!str_starts_with($line['account_code'],'649'))NonprofitTaxRules::invalid('Příspěvek správy obsahuje nepodporovaný výsledkový účet.');
                $out[(int)$line['id']]=$facts+['kind'=>'revenue','entry_id'=>$record['journal_entry_id'],'line_id'=>$line['id'],'ledger_hash'=>NonprofitTaxRules::hash($record['receipt']['ledger'])];
            }
        };
        $revenue($operation,$base);
        if($reverse!==null){if($reverse['kind']!=='reverse'||$reverse['reversal_of']!==$operation['id'])NonprofitTaxRules::invalid('Protizápis nemá přesnou návaznost na příspěvek.');$revenue($reverse,array_replace($base,['operation_id'=>$reverse['id'],'request_hash'=>$reverse['request_hash'],'snapshot_hash'=>$reverse['snapshot_hash'],'receipt_hash'=>$reverse['receipt_hash'],'reversal_of'=>$operation['id']]));}
        $cost=$operation['snapshot']['proofs']['cost'];$total=(string)$cost['amount_cents'];$amount=(string)$operation['request']['amount_cents'];
        if(bccomp($total,'0',0)<=0||bccomp($total,(string)PHP_INT_MAX,0)>0||bccomp($amount,'0',0)<=0||bccomp($amount,$total,0)>0)NonprofitTaxRules::invalid('Příspěvek nemá přesnou podporovanou částku nákladu.');
        $line=(int)$cost['line']['id'];$out[$line]=['owner'=>'property_funding','kind'=>'expense','legal_basis'=>'zdp_18_2f_2','line_id'=>$line,'ledger_hash'=>PropertyInput::hash($cost['ledger']),'cost_cents'=>(int)$total,'related_out_of_scope_cents'=>(int)$total,'covered_cost_cents'=>$reverse===null||$reverse['accounting_date']>$periodEnd?(int)$amount:0,'funding_sources'=>[$base]];
        ksort($out,SORT_NUMERIC);return$out;
    }

    private static function touches(array $value,array $ids):bool
    {
        if(isset($value['entry'],$value['lines'])&&is_array($value['entry'])&&is_array($value['lines']))foreach($value['lines']as$line)if(isset($ids[(int)($line['id']??0)]))return true;
        foreach($value as$item)if(is_array($item)&&self::touches($item,$ids))return true;return false;
    }
}
