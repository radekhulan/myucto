<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
final class OpeningReconciliation
{
    public function __construct(private readonly Connection $db){}
    public function at(int $supplier,string $from,string $to,?int $unit=null):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening reconciliation requires a stable snapshot.');
        $q=$this->db->pdo()->prepare("SELECT e.id,e.reversed_by FROM journal_entries e WHERE e.supplier_id=? AND e.source_type='opening' AND e.entry_date BETWEEN ? AND ? ORDER BY e.id");$q->execute([$supplier,$from,$to]);$entries=$q->fetchAll(PDO::FETCH_ASSOC);$uncovered=[];$roles=[];$states=[];
        foreach($entries as $entry){
            $q=$this->db->pdo()->prepare('SELECT id,status,journal_entry_id,reversal_entry_id FROM property_opening_batches WHERE supplier_id=? AND (journal_entry_id=? OR reversal_entry_id=?) ORDER BY id');$q->execute([$supplier,$entry['id'],$entry['id']]);$owners=$q->fetchAll(PDO::FETCH_ASSOC);
            $owner=null;$reversal=false;foreach($owners as $candidate)if((int)($candidate['reversal_entry_id']??0)===(int)$entry['id']){$owner=$candidate;$reversal=true;break;}
            if($owner===null)foreach($owners as $candidate)if((int)$candidate['journal_entry_id']===(int)$entry['id']){$owner=$candidate;break;}
            $q=$this->db->pdo()->prepare('SELECT l.id,l.amount,c.account_code FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id=l.account_id AND c.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.id');$q->execute([$supplier,$entry['id']]);$lines=$q->fetchAll(PDO::FETCH_ASSOC);
            if($owner===null){if((new \MyInvoice\Service\Accounting\Closing\AnnualOpeningReadProof($this->db))->matches($supplier,(int)$entry['id']))continue;foreach($lines as $line)if(OpeningRolePolicy::relevant($line['account_code']))$uncovered[]=(int)$line['id'];continue;}
            $q=$this->db->pdo()->prepare('SELECT id FROM property_opening_items WHERE supplier_id=? AND batch_id=? ORDER BY id');$q->execute([$supplier,$owner['id']]);$claims=[];
            foreach((new OpeningReadProof($this->db))->items($supplier,array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),false) as $item){
                foreach($item['components'] as $part){$lineId=$part['line']['id'];$claims[$lineId]=bcadd($claims[$lineId]??'0',$part['amount_cents'],0);if($unit===null||$item['snapshot']['unit_id']===$unit)$roles[$part['role']]=bcadd($roles[$part['role']]??'0',($reversal?'-':'').$part['amount_cents'],0);}
                if(!$reversal&&in_array($item['snapshot']['kind'],['advance','service_carry'],true)&&($unit===null||$item['snapshot']['unit_id']===$unit))$states[]=['item_id'=>$item['id'],'batch_id'=>$owner['id'],'kind'=>$item['snapshot']['kind'],'unit_id'=>$item['snapshot']['unit_id'],'service_id'=>$item['snapshot']['service_id'],'period_from'=>$item['snapshot']['period_from'],'period_to'=>$item['snapshot']['period_to'],'state'=>$item['snapshot']['advance_state']??$item['snapshot']['service_state'],'active'=>$item['active'],'source_hash'=>$item['snapshot_hash']];
            }
            if(!$reversal)foreach($lines as $line)if(OpeningRolePolicy::relevant($line['account_code'])&&($claims[(int)$line['id']]??'0')!==bcmul($line['amount'],'100',0))$uncovered[]=(int)$line['id'];
        }
        ksort($roles);return ['coverage'=>$uncovered===[]?'complete':'incomplete','uncovered_line_ids'=>$uncovered,'role_cents'=>$roles,'states'=>$states,'scope'=>'opening_journals_only'];
    }
}
