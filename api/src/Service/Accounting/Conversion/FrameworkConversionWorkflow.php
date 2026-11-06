<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,ChartOfAccountsRepository,FrameworkConversionRepository as R};
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionWorkflow
{
    public function __construct(private readonly Connection $db,private readonly R $read,private readonly FrameworkConversionPlanner $planner,private readonly FrameworkConversionJournalAdapter $journal,private readonly AccountingFrameworkService $framework,private readonly ChartOfAccountsRepository $chart){}
    public function choices(int $supplier,int $source,int $target):array{return $this->planner->choices($supplier,$source,$target);}
    public function preview(int $supplier,array $command,int $actor):array
    {E::need($actor>0,'conversion_actor_required',401);$plan=$this->planner->plan($supplier,$command);return ['state_hash'=>$plan['state_hash'],'preview_hash'=>R::hash([$supplier,$actor,$command,$plan]),'plan'=>$plan];}
    public function confirm(int $supplier,array $command,int $actor,string $state,string $preview,string $token):array
    {
        E::need($actor>0&&preg_match('/^[a-f0-9]{64}$/D',$state)===1&&preg_match('/^[a-f0-9]{64}$/D',$preview)===1&&preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$token)===1,'conversion_command_invalid',422);
        return $this->read->transaction($supplier,function()use($supplier,$command,$actor,$state,$preview,$token){
            $identity=R::hash([$actor,$command,$state,$preview]);$previous=$this->read->rows('SELECT request_hash,response_json FROM accounting_framework_conversion_commands WHERE supplier_id=? AND command_token=?',[$supplier,$token],true)[0]??null;
            if($previous){E::need(hash_equals($previous['request_hash'],$identity),'conversion_idempotency_conflict');return json_decode($previous['response_json'],true,512,JSON_THROW_ON_ERROR);}
            $plan=$this->planner->plan($supplier,$command,true);E::need(hash_equals($state,$plan['state_hash'])&&hash_equals($preview,R::hash([$supplier,$actor,$command,$plan])),'conversion_preview_stale');
            $defs=new AccountDefinitionRepository($this->db);$existing=array_column($plan['preimage']['accounts'],null,'account_code');
            foreach($plan['definitions'] as $definition)if($definition['existing']){$account=(int)$existing[$definition['account_code']]['id'];$defs->change($supplier,$account,$plan['effective_from'],$definition['definition'],$defs->stateHash($supplier,$account,true),$actor,$command['evidence']);}
            foreach($plan['definitions'] as $definition)if(!$definition['existing']){$d=$definition['definition'];$this->chart->insert($supplier,['account_code'=>$definition['account_code'],'account_type'=>$definition['account_type'],'is_synthetic'=>$definition['parent_code']===null,'parent_id'=>$definition['parent_code']===null?null:(int)$this->chart->findByCode($supplier,$definition['parent_code'],$plan['effective_from'])['id']]+$d,$plan['effective_from'],$actor);}
            $this->framework->changeFromPeriodStart($supplier,$plan['effective_from'],'cz_504',$actor);
            $catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($supplier,$plan['effective_from'],$actor);
            foreach($plan['mapped_cells'] as $cell){$kind=$cell['target']['classification']['activity_kind'];$q=$this->db->pdo()->prepare('SELECT id,name FROM dimension_values WHERE supplier_id=? AND type_id=? AND code=? FOR UPDATE');$q->execute([$supplier,$catalog['activity_kind']['id'],$kind['code']]);$old=$q->fetch(\PDO::FETCH_ASSOC);if($old)E::need($old['name']===$kind['name'],'conversion_activity_kind_conflict');else{$q=$this->db->pdo()->prepare('INSERT INTO dimension_values(supplier_id,type_id,code,name) VALUES(?,?,?,?)');$q->execute([$supplier,$catalog['activity_kind']['id'],$kind['code'],$kind['name']]);}}
            $lines=(new FrameworkConversionMaterializer($this->db))->lines($supplier,$plan);
            $header=['supplier_id'=>$supplier,'period_id'=>$plan['target_period_id'],'entry_date'=>$plan['effective_from'],'document_date'=>$plan['effective_from'],'source_type'=>'opening','source_id'=>$plan['target_period_id'],'document_no'=>'500-504/'.$plan['effective_from'],'description'=>$command['evidence'],'posted_at'=>gmdate('Y-m-d H:i:s'),'posted_by'=>$actor];
            $receipt=['version'=>1,'request_hash'=>R::hash($command),'plan'=>$plan,'header'=>$header,'lines'=>$lines];
            $q=$this->db->pdo()->prepare("INSERT INTO accounting_framework_conversions(supplier_id,source_period_id,target_period_id,source_entry_id,effective_from,status,source_hash,request_json,receipt_json,receipt_hash,created_by,recorded_at) VALUES(?,?,?,?,?,'pending',?,?,?,?,?,UTC_TIMESTAMP(6))");$q->execute([$supplier,$plan['source_period_id'],$plan['target_period_id'],$plan['source_entry_id'],$plan['effective_from'],$plan['source_hash'],R::json($command),R::json($receipt),R::hash($receipt),$actor]);$conversion=(int)$this->db->pdo()->lastInsertId();
            $entry=$this->journal->insert($supplier,$conversion);$ledger=$this->read->writtenLedger($supplier,$entry,$header,$lines);$actual=$this->read->rows('SELECT * FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id',[$supplier,$entry],true);E::need(count($actual)===count($lines),'conversion_written_lines_changed');
            foreach($plan['mapped_cells'] as $index=>$cell){$q=$this->db->pdo()->prepare('INSERT INTO accounting_framework_conversion_cells(supplier_id,conversion_id,cell_key,opening_line_id,source_json,target_json) VALUES(?,?,?,?,?,?)');$q->execute([$supplier,$conversion,$cell['source']['cell_key'],$actual[$index*2]['id'],R::json($cell['source']),R::json($lines[$index*2])]);}
            $receipt['ledger']=$ledger;$receipt['opening_entry_id']=$entry;$receipt['opening_line_ids']=array_map('intval',array_column($actual,'id'));$receipt['postimage']=$this->postimage($supplier);$receipt['created_ids']=$this->createdIds($plan['preimage'],$receipt['postimage']);$q=$this->db->pdo()->prepare("UPDATE accounting_framework_conversions SET status='applied',opening_entry_id=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=? AND status='pending'");$q->execute([$entry,R::json($receipt),R::hash($receipt),$supplier,$conversion]);E::need($q->rowCount()===1,'conversion_write_conflict');
            $result=['conversion_id'=>$conversion,'opening_entry_id'=>$entry];$q=$this->db->pdo()->prepare('INSERT INTO accounting_framework_conversion_commands(supplier_id,command_token,request_hash,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$supplier,$token,$identity,R::json($result),$actor]);return $result;
        });
    }
    public function cancellationPreview(int $supplier,int $conversion,int $actor):array
    {
        E::need($actor>0,'conversion_actor_required',401);
        return $this->read->transaction($supplier,function()use($supplier,$conversion,$actor){$proof=FrameworkConversionCancellationProof::current($this->db,$supplier,$conversion);return ['conversion_id'=>$conversion,'receipt_hash'=>$proof->row['receipt_hash'],'preview_hash'=>R::hash([$supplier,$actor,$conversion,$proof->row['receipt_hash']])];});
    }
    public function cancel(int $supplier,int $conversion,int $actor,string $receiptHash,string $previewHash,string $token):array
    {
        E::need($actor>0&&preg_match('/^[a-f0-9]{64}$/D',$receiptHash)===1&&preg_match('/^[a-f0-9]{64}$/D',$previewHash)===1&&preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$token)===1,'conversion_command_invalid',422);
        return $this->read->transaction($supplier,function()use($supplier,$conversion,$actor,$receiptHash,$previewHash,$token){
            $identity=R::hash(['cancel',$supplier,$conversion,$actor,$receiptHash,$previewHash]);$previous=$this->read->rows('SELECT request_hash,response_json FROM accounting_framework_conversion_commands WHERE supplier_id=? AND command_token=?',[$supplier,$token],true)[0]??null;if($previous){E::need(hash_equals($previous['request_hash'],$identity),'conversion_idempotency_conflict');return json_decode($previous['response_json'],true,512,JSON_THROW_ON_ERROR);}
            $proof=FrameworkConversionCancellationProof::current($this->db,$supplier,$conversion);E::need(hash_equals($proof->row['receipt_hash'],$receiptHash)&&hash_equals($previewHash,R::hash([$supplier,$actor,$conversion,$receiptHash])),'conversion_preview_stale');
            $this->journal->cancel($supplier,$conversion,$actor,$receiptHash);$this->restore($supplier,$proof->row['receipt']);
            $source=$this->read->source($supplier,(int)$proof->row['source_period_id'],(int)$proof->row['target_period_id'],true);E::need(hash_equals($proof->row['source_hash'],$source['source_hash']),'conversion_source_changed');
            $result=['conversion_id'=>$conversion,'status'=>'cancelled'];$q=$this->db->pdo()->prepare('INSERT INTO accounting_framework_conversion_commands(supplier_id,command_token,request_hash,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$supplier,$token,$identity,R::json($result),$actor]);return $result;
        });
    }
    public function createdIds(array $before,array $after):array
    {
        $out=[];foreach(['accounting_account_definition_history'=>'history','chart_of_accounts'=>'accounts','supplier_accounting_frameworks'=>'frameworks','dimension_values'=>'dimension_values','dimension_types'=>'dimension_types'] as $table=>$key)$out[$table]=array_values(array_diff(array_map('intval',array_column($after[$table],'id')),array_map('intval',array_column($before[$key],'id'))));return $out;
    }
    private function restore(int $supplier,array $receipt):void
    {
        $created=$this->createdIds($receipt['plan']['preimage'],$receipt['postimage']);E::need($created===$receipt['created_ids'],'conversion_created_rows_changed');
        foreach(['accounting_account_definition_history','chart_of_accounts','supplier_accounting_frameworks','dimension_values','dimension_types'] as $table){
            $ids=$created[$table];if($table==='chart_of_accounts')rsort($ids,SORT_NUMERIC);
            foreach($ids as $id){$q=$this->db->pdo()->prepare('DELETE FROM '.$table.' WHERE supplier_id=? AND id=?');$q->execute([$supplier,$id]);E::need($q->rowCount()===1,'conversion_owned_row_changed');}
        }
        $q=$this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled=? WHERE id=? AND dimensions_enabled=?');$q->execute([$receipt['plan']['preimage']['dimensions_enabled'],$supplier,$receipt['postimage']['supplier'][0]['dimensions_enabled']]);
    }
    public function postimage(int $supplier):array
    {
        $out=[];foreach(['chart_of_accounts','accounting_account_definition_history','supplier_accounting_frameworks','dimension_types','dimension_values'] as $table)$out[$table]=$this->read->rows('SELECT * FROM '.$table.' WHERE supplier_id=? ORDER BY id',[$supplier],true);$out['supplier']=$this->read->rows('SELECT dimensions_enabled FROM supplier WHERE id=?',[$supplier],true);return $out;
    }
}
