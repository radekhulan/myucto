<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
final class OpeningOriginProof
{
    public function __construct(private readonly Connection $db){}
    public function debt(int $supplier,array $row,bool $current):array
    {
        if((int)$row['supplier_id']!==$supplier||$row['charge_id']!==null||($row['opening_item_id']??null)===null)OpeningException::reject('debt_origin');
        return $this->debtForItem($supplier,$row,(new OpeningReadProof($this->db))->item($supplier,(int)$row['opening_item_id'],$current));
    }
    private function debtForItem(int $supplier,array $row,array $item):array
    {
        if((int)$row['supplier_id']!==$supplier||$row['charge_id']!==null||(int)($row['opening_item_id']??0)!==$item['id']||$item['snapshot']['kind']!=='debt')OpeningException::reject('debt_origin');
        $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$expected=self::debtSnapshot($item['id'],$item['snapshot'],$item['components'],$item['batch']['journal_entry_id']);
        if(PropertyInput::hash($expected)!==$row['snapshot_hash']||PropertyInput::hash($snapshot)!==$row['snapshot_hash'])OpeningException::reject('debt_origin');
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        if(PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']])!==$row['request_hash']||$request!==['opening_item_id'=>$item['id']])OpeningException::reject('debt_origin');
        return $item+['debt_snapshot'=>$snapshot];
    }
    public function debts(int $supplier,array $rows,bool $current):array
    {
        foreach($rows as $row)if((int)$row['supplier_id']!==$supplier||$row['charge_id']!==null||($row['opening_item_id']??null)===null)OpeningException::reject('debt_origin');
        $items=(new OpeningReadProof($this->db))->items($supplier,array_map('intval',array_column($rows,'opening_item_id')),$current);$out=[];
        foreach($rows as $row)$out[(int)$row['id']]=$this->debtForItem($supplier,$row,$items[(int)$row['opening_item_id']]);
        return $out;
    }
    public function credits(int $supplier,array $rows,bool $current):array
    {
        foreach($rows as $row)if((int)$row['supplier_id']!==$supplier||$row['operation_id']!==null||($row['opening_item_id']??null)===null)OpeningException::reject('credit_origin');
        $items=(new OpeningReadProof($this->db))->items($supplier,array_map('intval',array_column($rows,'opening_item_id')),$current);$out=[];
        foreach($rows as $row)$out[(int)$row['id']]=$this->creditForItem($supplier,$row,$items[(int)$row['opening_item_id']]);
        return $out;
    }
    public function currentDebts(int $supplier,array $rows,array $allocations,array $receipts):array
    {
        $items=$this->debts($supplier,$rows,true);$out=[];$ledgerHashes=[];
        foreach($rows as $row){$id=(int)$row['id'];$out[$id]=$this->currentDebtForItem($supplier,$row,$allocations[$id]??[],$receipts[$id]??[],$items[$id],$ledgerHashes);}
        return $out;
    }
    public function accessories(int $supplier,array $debtRows,array $receiptRows,bool $current):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening proof requires a stable snapshot.');
        $debts=array_column($debtRows,null,'id');$selected=[];
        foreach($receiptRows as $receipt){$debt=$debts[(int)$receipt['debt_id']]??null;if($debt===null)OpeningException::reject('accessory_origin');
            $this->assertAccessoryRows($supplier,$debt,$receipt,$current);
            $selected[(int)$debt['id']]=$debt;
        }
        $items=$this->debts($supplier,array_values($selected),$current);$out=[];$ledgerHashes=[];
        foreach($receiptRows as $row){$id=(int)$row['debt_id'];$item=$items[$id];$out[(int)$row['id']]=$item+['receipt'=>$this->accessoryForItem($supplier,$debts[$id],$row,$item,$ledgerHashes)];}
        return $out;
    }
    public static function debtSnapshot(int $itemId,array $item,array $components,int $entry):array
    {
        $lines=[];$principal='0';foreach($components as $component)if($component['role']==='principal'){$principal=bcadd($principal,$component['amount_cents'],0);$lines[]=['id'=>$component['id'],'line'=>['amount_cents'=>$component['amount_cents'],'dimensions'=>$item['dimensions'],'account'=>['receivable_account'=>$component['line']['account_code']]],'opening_component_id'=>$component['id']];}
        return ['charge_id'=>null,'opening_item_id'=>$itemId,'unit_id'=>$item['unit_id'],'journal_entry_id'=>$entry,'lines'=>$lines,'liability_groups'=>$item['liability_groups'],'amount_cents'=>$principal,'title_ref'=>$item['title_ref'],'due_date'=>$item['due_date'],'legal'=>$item['legal'],'security'=>$item['security'],'damages_for'=>null,'reminders'=>$item['reminders']];
    }
    public function currentDebt(int $supplier,array $row,array $allocations,array $receiptRows):array
    {
        $ledgerHashes=[];return $this->currentDebtForItem($supplier,$row,$allocations,$receiptRows,$this->debt($supplier,$row,true),$ledgerHashes);
    }
    private function currentDebtForItem(int $supplier,array $row,array $allocations,array $receiptRows,array $item,array &$ledgerHashes):array
    {
        $snapshot=$item['debt_snapshot'];$id=(int)$row['id'];$components=array_column($item['components'],null,'id');$paid=[];$groups=[];$receipts=[];
        foreach($receiptRows as $receiptRow){
            $receipt=json_decode($receiptRow['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
            if(PropertyInput::hash($receipt)!==$receiptRow['receipt_hash']||$receipt['debt_id']!==$id||$receipt['request']['component']!==$receiptRow['component']||bcmul($receipt['line']['amount'],'100',0)!==$receiptRow['amount_cents']||PropertyInput::hash($receipt['liability_groups'])!==PropertyInput::hash($snapshot['liability_groups']))OpeningException::reject('accessory_origin');
            if($receiptRow['opening_component_id']!==null)$receipt=$this->accessoryForItem($supplier,$row,$receiptRow,$item,$ledgerHashes);
            else{if($receipt['line']['id']!==(int)$receiptRow['journal_line_id'])OpeningException::reject('accessory_origin');(new PaymentJournalProof($this->db))->assertSame($supplier,$receipt['ledger']);}
            $receipts[(int)$receiptRow['id']]=['id'=>(int)$receiptRow['id'],'component'=>$receiptRow['component'],'amount_cents'=>$receiptRow['amount_cents'],'receipt'=>$receipt];
        }
        foreach($item['components'] as $part)if($part['role']!=='principal'&&!array_any($receiptRows,fn($r)=>(int)($r['opening_component_id']??0)===$part['id']))OpeningException::reject('accessory_missing');
        foreach($allocations as $allocation){
            if($allocation['charge_id']!==null||$allocation['charge_line_id']!==null)OpeningException::reject('allocation_origin');
            if($allocation['opening_component_id']!==null){$part=$components[(int)$allocation['opening_component_id']]??null;if(!$part||$part['role']!=='principal'||$allocation['accessory_id']!==null)OpeningException::reject('allocation_origin');$key='principal:'.$part['id'];}
            else{if(!isset($receipts[(int)$allocation['accessory_id']]))OpeningException::reject('allocation_origin');$key='accessory:'.$allocation['accessory_id'];}
            $paid[$key]=bcadd($paid[$key]??'0',$allocation['amount_cents'],0);$groups[$allocation['liability_group']]=bcadd($groups[$allocation['liability_group']]??'0',$allocation['amount_cents'],0);
        }
        if(!$item['active']&&array_any($paid,fn($amount)=>$amount!=='0'))OpeningException::reject('history_corrupt');
        return ['id'=>$id,'active'=>$item['active'],'snapshot'=>$snapshot,'paid'=>$paid,'group_paid'=>$groups,'accessories'=>array_values($receipts),'reductions'=>[],'opening_accounting_date'=>$item['batch']['accounting_date'],'opening_effective_at'=>$item['batch']['effective_at']];
    }
    public function accessory(int $supplier,array $debtRow,array $receiptRow,bool $current):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening proof requires a stable snapshot.');
        $this->assertAccessoryRows($supplier,$debtRow,$receiptRow,$current);
        $item=$this->debt($supplier,$debtRow,$current);$ledgerHashes=[];
        return $item+['receipt'=>$this->accessoryForItem($supplier,$debtRow,$receiptRow,$item,$ledgerHashes)];
    }
    private function assertAccessoryRows(int $supplier,array $debtRow,array $receiptRow,bool $current):void
    {
        foreach([['unit_payment_debts',$debtRow],['unit_claim_accessory_receipts',$receiptRow]] as [$table,$row]){
            $query=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=? AND id=?'.($current?' FOR UPDATE':''));$query->execute([$supplier,$row['id']]);$actual=$query->fetch(\PDO::FETCH_ASSOC);
            if(!$actual||PropertyInput::hash($actual)!==PropertyInput::hash($row))OpeningException::reject('accessory_origin');
        }
    }
    public static function accessoryMaterialization(int $supplier,array $debtRow,array $row):array
    {
        $receipt=json_decode($row['receipt_json'],true,flags:JSON_THROW_ON_ERROR);
        if((int)$debtRow['supplier_id']!==$supplier||(int)$row['supplier_id']!==$supplier||$debtRow['charge_id']!==null||$row['charge_id']!==null||($debtRow['opening_item_id']??null)===null||($row['opening_component_id']??null)===null||$row['journal_line_id']!==null||(int)$row['debt_id']!==(int)$debtRow['id']||PropertyInput::hash($receipt)!==$row['receipt_hash']||($receipt['debt_id']??null)!==(int)$debtRow['id']||($receipt['opening_component_id']??null)!==(int)$row['opening_component_id']||($receipt['request']['component']??null)!==$row['component']||bcmul($receipt['line']['amount'],'100',0)!==$row['amount_cents']||($receipt['actor']??null)!==(int)$row['created_by'])OpeningException::reject('accessory_origin');
        if(array_key_exists('opening_origin',$receipt)){
            $origin=$receipt['opening_origin'];
            if(!is_array($origin)||array_key_exists('ledger',$receipt)||($origin['version']??null)!==1||($origin['item_id']??null)!==(int)$debtRow['opening_item_id']||($origin['component_id']??null)!==(int)$row['opening_component_id']||($origin['journal_entry_id']??null)!==(int)$row['journal_entry_id']||($origin['journal_line_id']??null)!==($receipt['line']['id']??null))OpeningException::reject('accessory_origin');
        }elseif(($receipt['ledger']['entry']['id']??null)!==(int)$row['journal_entry_id'])OpeningException::reject('accessory_origin');
        return $receipt;
    }
    private function accessoryForItem(int $supplier,array $debtRow,array $row,array $item,array &$ledgerHashes):array
    {
        $receipt=self::accessoryMaterialization($supplier,$debtRow,$row);$batch=$item['batch'];$fact=$item['snapshot'];
        $part=array_column($item['components'],null,'id')[(int)$row['opening_component_id']]??null;
        if(!$part||$part['role']==='principal'||$part['role']!==$row['component']||$part['amount_cents']!==$row['amount_cents']||(int)$row['journal_entry_id']!==$batch['journal_entry_id']||(int)$row['created_by']!==$batch['created_by']||($receipt['opening_effective_at']??null)!==$batch['effective_at']||PropertyInput::hash($receipt['liability_groups'])!==PropertyInput::hash($fact['liability_groups'])||PropertyInput::hash($receipt['request'])!==PropertyInput::hash(['component'=>$part['role'],'evidence'=>$fact['evidence_ref'],'liability_evidence'=>$fact['title_ref']]))OpeningException::reject('accessory_origin');
        $line=$part['line'];$line['amount']=bcdiv($part['amount_cents'],'100',2);
        if(PropertyInput::hash($receipt['line'])!==PropertyInput::hash($line))OpeningException::reject('accessory_origin');
        if(array_key_exists('opening_origin',$receipt)){
            $expected=['version'=>1,'batch_id'=>$batch['id'],'item_id'=>$item['id'],'component_id'=>$part['id'],'journal_entry_id'=>$batch['journal_entry_id'],'journal_line_id'=>$line['id'],'batch_snapshot_hash'=>$batch['snapshot_hash'],'item_snapshot_hash'=>$item['snapshot_hash']];
            if(PropertyInput::hash($receipt['opening_origin'])!==PropertyInput::hash($expected))OpeningException::reject('accessory_origin');
        }else{
            if(!isset($ledgerHashes[$batch['id']])){$ledger=$item['ledger'];$ledger['entry']['reversed_by']=null;$ledgerHashes[$batch['id']]=PropertyInput::hash($ledger);}
            if(PropertyInput::hash($receipt['ledger'])!==$ledgerHashes[$batch['id']])OpeningException::reject('accessory_origin');
        }
        return $receipt;
    }
    public function credit(int $supplier,array $row,bool $current):array
    {
        if($row['operation_id']!==null||($row['opening_item_id']??null)===null)OpeningException::reject('credit_origin');
        return $this->creditForItem($supplier,$row,(new OpeningReadProof($this->db))->item($supplier,(int)$row['opening_item_id'],$current));
    }
    private function creditForItem(int $supplier,array $row,array $item):array
    {
        if((int)$row['supplier_id']!==$supplier||$row['operation_id']!==null||(int)($row['opening_item_id']??0)!==$item['id']||$item['snapshot']['kind']!=='credit'||count($item['components'])!==1)OpeningException::reject('credit_origin');
        $fact=$item['snapshot'];$part=$item['components'][0];$line=$part['line'];$line['amount']=bcdiv($part['amount_cents'],'100',2);$parties=array_values(array_filter($fact['parties'],fn($party)=>in_array($party['id'],$fact['creditor_ids'],true)));
        $expected=['opening_item_id'=>$item['id'],'unit_id'=>$fact['unit_id'],'amount_cents'=>$fact['amount_cents'],'due_date'=>$fact['due_date'],'parties'=>$parties,'line'=>$line,'entitlement'=>['basis'=>'documented_opening','evidence'=>$fact['evidence_ref'],'title_ref'=>$fact['title_ref']]];
        if(PropertyInput::hash($expected)!==$row['snapshot_hash']||PropertyInput::hash(json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR))!==$row['snapshot_hash'])OpeningException::reject('credit_origin');return $item;
    }
}
