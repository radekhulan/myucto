<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FrameworkConversionRepository as R;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionJournalProof
{
    private readonly string $savepoint;
    private function __construct(private readonly Connection $db,public readonly int $conversionId,public readonly array $header,public readonly array $lines,private readonly array $receipt)
    {$this->savepoint='framework_conversion_'.bin2hex(random_bytes(10));$db->pdo()->exec('SAVEPOINT '.$this->savepoint);}
    public static function pending(Connection $db,int $supplier,int $id):self
    {
        E::need($db->pdo()->inTransaction(),'conversion_transaction_required');$r=new R($db);$row=$r->receipt($supplier,$id,true);E::need($row['status']==='pending'&&$row['opening_entry_id']===null,'conversion_pending_required');$receipt=$row['receipt'];$plan=$receipt['plan'];
        $source=$r->source($supplier,(int)$row['source_period_id'],(int)$row['target_period_id'],true);E::need(hash_equals($row['source_hash'],$source['source_hash']),'conversion_source_changed');
        $cells=$r->cells($source);E::need(array_column($plan['mapped_cells'],'source')===$cells,'conversion_cells_changed');
        E::need(R::hash($row['request'])===($receipt['request_hash']??null),'conversion_request_changed');
        E::need(count($plan['lines'])===count($cells)*2,'conversion_lines_changed');
        foreach($cells as $index=>$cell){$mapping=$row['request']['mapping'][$cell['cell_key']]??null;$line=$plan['lines'][$index*2];$contra=$plan['lines'][$index*2+1];E::need(is_array($mapping)&&$mapping['account_code']===$line['account_code']&&$line['source_dimensions']===$cell['dimensions']&&$line['classification']===array_intersect_key($mapping,array_flip(['activity','activity_kind','tax_regime'])),'conversion_mapping_changed');
            E::need($line['side']===($cell['czk_cents']>0?'debit':'credit')&&\MyInvoice\Service\Accounting\JournalLineAmount::cents($line['amount'])===abs($cell['czk_cents']),'conversion_amount_changed');
            E::need(($line['cost_center']??null)===($cell['cost_center']??null)&&($line['project_id']??null)===($cell['project_id']??null),'conversion_source_tuple_changed');
            E::need($cell['role']!=='pending_approval'||$line['account_code']==='931','conversion_current_profit_requires_931');E::need($cell['role']!=='retained'||$line['account_code']==='932','conversion_retained_requires_932');
            E::need($contra===array_replace($line,['account_code'=>'961','side'=>$line['side']==='debit'?'credit':'debit','currency_code'=>null,'amount_foreign'=>null,'fx_rate'=>null]),'conversion_counterpart_changed');
            E::need(($line['currency_code']??'CZK')===$cell['currency_code']&&\MyInvoice\Service\Accounting\JournalLineAmount::cents($line['amount_foreign']??'0')===abs($cell['foreign_cents']),'conversion_currency_changed');
        }
        $lines=(new FrameworkConversionMaterializer($db))->lines($supplier,$plan);E::need($lines===$receipt['lines'],'conversion_target_changed');
        $target=$source['target'];E::need($target['status']==='open','conversion_target_not_open');$entries=$r->rows('SELECT id FROM journal_entries WHERE supplier_id=? AND entry_date>=?',[$supplier,$target['starts_on']],true);E::need($entries===[],'conversion_target_not_empty');
        $header=$receipt['header'];E::need((int)$header['supplier_id']===$supplier&&(int)$header['period_id']===(int)$target['id']&&$header['source_type']==='opening'&&(int)$header['source_id']===(int)$target['id']&&$header['entry_date']===$target['starts_on']&&$header['posted_at']!==null&&(int)$header['posted_by']===(int)$row['created_by'],'conversion_header_changed');
        return new self($db,$id,$header,$lines,$receipt);
    }
    public function indices(Connection $db,array $header,array $lines):array
    {
        E::need($this->db->pdo()===$db->pdo()&&$db->pdo()->inTransaction(),'conversion_proof_expired');
        try{$db->pdo()->exec('RELEASE SAVEPOINT '.$this->savepoint);}catch(\PDOException){throw new E('conversion_proof_expired');}$db->pdo()->exec('SAVEPOINT '.$this->savepoint);
        $current=self::pending($db,(int)$header['supplier_id'],$this->conversionId);E::need($header===$this->header&&$lines===$this->lines&&$current->receipt===$this->receipt,'conversion_proof_changed');return array_fill_keys(array_keys($lines),true);
    }
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Owner proofs cannot be serialized.');}
    public function __unserialize(array $data):void{throw new \LogicException('Owner proofs cannot be restored.');}
}
