<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Reconciliation;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Services\Settlement\{ServiceLifecycleRepository,SettlementDocumentRepository};
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\SettlementHistory;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;
final class ServiceReconciliationSources
{
    private array $rows=[];
    private array $full=[];
    private array $policies=[];
    private array $accounts=[];
    private array $pending=[];
    public function __construct(private readonly Connection $db){}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    public static function balanceAccount(string $code):bool{return preg_match('/^(315\.2[0-9]{2}|324\.1[0-9]{2}|388\.2[0-9]{2}|389\.1[0-9]{2})$/D',$code)===1;}
    private function policy(int $unit,int $service,string $policy,?string $account=null):void{$this->policies[$unit][$service][$policy]=true;if($account!==null)$this->accounts[$unit][$service][$account]=true;}
    private function add(int $unit,int $service,array $entry,array $line,string $amount,string $kind,int $source,bool $deduplicate=false):void
    {
        $key=(string)$line['id'];$fingerprint=PropertyInput::hash(['unit'=>$unit,'service'=>$service,'entry'=>$entry['id'],'line'=>$line,'amount'=>$amount]);
        if($deduplicate&&isset($this->full[$key])){if($this->full[$key]!==$fingerprint)throw new ServiceReconciliationException('source_partition');return;}
        if($deduplicate&&isset($this->rows[$key])){if($this->rows[$key]['amount_cents']!==$amount||$this->rows[$key]['unit_id']!==$unit||$this->rows[$key]['service_id']!==$service)throw new ServiceReconciliationException('source_partition');$this->full[$key]=$fingerprint;return;}
        $row=['line_id'=>$line['id'],'entry_id'=>$entry['id'],'unit_id'=>$unit,'service_id'=>$service,'account_code'=>$line['account_code'],'side'=>$line['side'],'amount_cents'=>$amount,'accounting_date'=>$entry['entry_date'],'source_kind'=>$kind,'source_id'=>$source];
        if(isset($this->rows[$key])){foreach(['entry_id','unit_id','service_id','account_code','side','accounting_date'] as $field)if($this->rows[$key][$field]!==$row[$field])throw new ServiceReconciliationException('source_partition');$this->rows[$key]['amount_cents']=bcadd($this->rows[$key]['amount_cents'],$amount,0);}else$this->rows[$key]=$row;
        if($deduplicate)$this->full[$key]=$fingerprint;
    }
    private function ledger(int $unit,int $service,array $ledger,string $kind,int $source,bool $pl=false,bool $deduplicate=false):void
    {
        foreach($ledger['lines'] as $line)if(self::balanceAccount($line['account_code'])||($pl&&preg_match('/^[56]/D',$line['account_code']))){if($pl&&preg_match('/^[56]/D',$line['account_code']))$this->policy($unit,$service,'service_profit_and_loss',$line['account_code']);$this->add($unit,$service,$ledger['entry'],$line,(string)JournalLineAmount::signedCents($line),$kind,$source,$deduplicate);}
    }
    private function reversal(int $supplier,array $ledger):?array
    {
        if($ledger['entry']['reversed_by']===null)return null;$reverse=(new PaymentJournalProof($this->db))->current($supplier,$ledger['entry']['reversed_by']);$want=array_map(JournalLineAmount::reversal(...),$ledger['lines']);
        if($reverse['entry']['source_type']!==$ledger['entry']['source_type']||$reverse['entry']['source_id']!==null||$reverse['entry']['entry_date']<$ledger['entry']['entry_date']||PropertyInput::hash(PaymentJournalProof::canonicalLines($want))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($reverse['lines'])))throw new ServiceReconciliationException('source_reversal');return $reverse;
    }
    public function read(int $supplier):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Service reconciliation sources require a stable owner transaction.');$this->rows=$this->full=$this->policies=$this->accounts=$this->pending=[];
        $reader=new IssuanceReadRepository($this->db);$proof=new IssuanceJournalProof($this->db);$native=new PaymentJournalProof($this->db);
        foreach($reader->stream($supplier,true) as $charge){if(!in_array($charge['status'],['posted','reversed'],true))continue;$proof->assertEntry($supplier,$charge['journal_entry_id'],$charge);$ledger=$native->current($supplier,$charge['journal_entry_id']);$planned=IssuanceJournalProof::lines($charge);$byLine=[];
            foreach($charge['snapshot']['lines'] as $i=>$fact)if($fact['component']['kind']==='service_advance'){$service=$fact['service_id'];if(!is_int($service))throw new ServiceReconciliationException('service_scope');$byLine[$i*2+1]=$service;}
            foreach($byLine as $i=>$service){$line=$ledger['lines'][$i];$this->policy($charge['unit_id'],$service,'service_advance',$line['account_code']);$this->add($charge['unit_id'],$service,$ledger['entry'],$line,bcmul($planned[$i]['amount'],'100',0),'issued',$charge['id']);}
            if($charge['status']==='reversed'){$proof->assertEntry($supplier,$charge['reversal_entry_id'],$charge,true);$reverse=$native->current($supplier,$charge['reversal_entry_id']);foreach($byLine as $i=>$service)$this->add($charge['unit_id'],$service,$reverse['entry'],$reverse['lines'][$i],bcmul($planned[$i]['amount'],'100',0),'issued_reversal',$charge['id']);}
        }
        $opening=new OpeningReadProof($this->db);foreach($this->q('SELECT id FROM property_opening_batches WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier])->fetchAll(PDO::FETCH_COLUMN) as $batch){$ids=array_map('intval',$this->q('SELECT id FROM property_opening_items WHERE supplier_id=? AND batch_id=? ORDER BY id FOR UPDATE',[$supplier,$batch])->fetchAll(PDO::FETCH_COLUMN));foreach($opening->items($supplier,$ids,true) as $item){$fact=$item['snapshot'];$unit=$fact['unit_id'];$service=$fact['service_id'];if(!is_int($service)||!in_array($fact['kind'],['advance','service_carry'],true))continue;$policy=$fact['service_state']['profile']??'service_advance';$this->policy($unit,$service,$policy);foreach(['expense_account','coverage_account'] as $key)if(isset($fact['service_state']['accrual_unwind'][$key]))$this->policy($unit,$service,'service_profit_and_loss',$fact['service_state']['accrual_unwind'][$key]);
            $reverse=$item['active']?null:$this->reversal($supplier,$item['ledger']);$positions=array_flip(array_column($item['ledger']['lines'],'id'));
            foreach($item['components'] as $part)if(in_array($part['role'],['service_advance','service_cost','accrual_receivable','accrual_payable'],true)){$this->policy($unit,$service,$policy,$part['line']['account_code']);$this->add($unit,$service,$item['ledger']['entry'],$part['line'],$part['amount_cents'],'opening',$item['id']);if($reverse!==null)$this->add($unit,$service,$reverse['entry'],$reverse['lines'][$positions[$part['line']['id']]],$part['amount_cents'],'opening_reversal',$item['id']);}
        }}
        $lifecycle=new ServiceLifecycleRepository($this->db);foreach($this->q('SELECT id FROM property_service_lifecycle_cases WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier])->fetchAll(PDO::FETCH_COLUMN) as $id){$state=$lifecycle->reconciliationSources($supplier,(int)$id);$case=$state['case'];$unit=$case['unit_id'];$service=$case['service_id'];$pl=$case['policy']==='service_profit_and_loss';$this->policy($unit,$service,$case['policy']);
            foreach($state['pending'] as $pending)$this->pending[]=$pending+['unit_id'=>$unit,'service_id'=>$service,'case_id'=>(int)$id,'period_to'=>$case['period_to']];
            if($state['estimate']===null&&$state['final']===null)$this->pending[]=['code'=>'lifecycle_estimate_missing','unit_id'=>$unit,'service_id'=>$service,'case_id'=>(int)$id,'accounting_date'=>null,'period_to'=>$case['period_to']];
            if($state['estimate']!==null&&$state['estimate']['receipt']['opening_item_id']===null)foreach($state['estimate']['receipt']['sources'] as $source)$this->ledger($unit,$service,$source,'estimate',(int)$id,$pl,true);
            foreach($state['unwinds'] as $unwind)$this->ledger($unit,$service,$unwind['ledger'],'unwind',$unwind['event_id'],$pl,true);
            if($state['final']!==null){$final=$state['final'];$this->ledger($unit,$service,$final['invoice']['ledger'],'invoice',$final['invoice']['invoice_id'],$pl,true);if(isset($final['ledger']))$this->ledger($unit,$service,$final['ledger'],'final',$final['event_id'],$pl,true);}
        }
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$documents=$container->get(SettlementDocumentRepository::class);$history=new SettlementHistory($this->db);
        foreach($this->q('SELECT id FROM property_service_settlements WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier])->fetchAll(PDO::FETCH_COLUMN) as $id){$document=$documents->find($supplier,(int)$id,true);$fact=$document['document'];if(isset($fact['cost']['sources']))(new \MyInvoice\Service\Property\Services\Settlement\SettlementCostProof($this->db))->assertRecorded($supplier,$fact['unit_id'],$fact['service_id'],$document['origin_receipt']['request']['accounting_date'],['from'=>$fact['period_from'],'to'=>$fact['period_to']],$fact['cost'],$container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class));foreach(\MyInvoice\Service\Property\Services\Settlement\SettlementEvidence::costSources($fact['cost']) as $cost){$row=$this->q('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$cost['source_id']])->fetchColumn();if(!$row)throw new ServiceReconciliationException('cost_source_missing');$ledger=$native->current($supplier,(int)$row);$positions=array_flip(array_column($ledger['lines'],'id'));$line=$ledger['lines'][$positions[$cost['source_id']]]??throw new ServiceReconciliationException('cost_source_missing');if(PropertyInput::hash($line)!==$cost['source_hash']||$line['is_red_storno']||$line['side']!=='debit')throw new ServiceReconciliationException('cost_source_changed');$registered=$this->rows[(string)$line['id']]??null;$partition=$registered!==null&&$registered['source_kind']==='opening'&&$registered['unit_id']===$fact['unit_id']&&$registered['service_id']===$fact['service_id']&&bccomp($registered['amount_cents'],$cost['amount_cents'],0)>=0;if(!$partition&&bcmul($line['amount'],'100',0)!==$cost['amount_cents'])throw new ServiceReconciliationException('cost_source_changed');if(!$partition){$this->add($fact['unit_id'],$fact['service_id'],$ledger['entry'],$line,$cost['amount_cents'],'document_cost',(int)$id,true);$reverse=$this->reversal($supplier,$ledger);if($reverse!==null)$this->add($fact['unit_id'],$fact['service_id'],$reverse['entry'],$reverse['lines'][$positions[$line['id']]],$cost['amount_cents'],'document_cost_reversal',(int)$id,true);}}
        }
        foreach($this->q('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? ORDER BY id FOR UPDATE',[$supplier])->fetchAll(PDO::FETCH_COLUMN) as $id){$app=$history->application($supplier,(int)$id,true);$document=$documents->find($supplier,$app['document_id'],true);$fact=$document['document'];$this->ledger($fact['unit_id'],$fact['service_id'],$app['receipt']['ledger'],$app['reversal_of']===null?'settlement':'settlement_reversal',(int)$id,false,true);}
        return ['rows'=>array_values($this->rows),'policies'=>$this->policies,'accounts'=>$this->accounts,'pending'=>$this->pending];
    }
}
