<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{ClosingRepository,FrameworkConversionRepository as R};
use MyInvoice\Service\Accounting\{AccountingRoleProfile,PostingService};
use MyInvoice\Service\Accounting\Closing\ProfitDistribution504Builder as Builder;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
final class FrameworkConversionResultDecision
{
    public function __construct(private readonly Connection $db,private readonly R $read,private readonly PostingService $posting){}
    public function choices(int $supplier,int $conversion,string $date):array
    {return $this->read->transaction($supplier,fn()=>$this->context($supplier,$conversion,$date));}
    private function context(int $supplier,int $conversion,string $date):array
    {
        \MyInvoice\Repository\AccountDefinitionRepository::date($date);$row=$this->read->receipt($supplier,$conversion,true);E::need($row['status']==='applied','conversion_not_active');$source=$this->read->source($supplier,(int)$row['source_period_id'],(int)$row['target_period_id'],true);E::need(hash_equals($row['source_hash'],$source['source_hash']),'conversion_source_changed');
        E::need($source['source']['status']==='approved'&&$source['source']['approved_at']!==null&&$source['source']['approved_by']!==null,'conversion_source_approval_required');E::need($source['target']['status']==='open'&&$date>=$source['target']['starts_on']&&$date<=$source['target']['ends_on'],'conversion_decision_date_invalid',422);
        $old=$this->read->rows('SELECT id FROM accounting_framework_conversion_decisions WHERE supplier_id=? AND conversion_id=?',[$supplier,$conversion],true);E::need($old===[],'conversion_result_already_decided');
        E::need((new PaymentJournalProof($this->db))->current($supplier,(int)$row['opening_entry_id'])===$row['receipt']['ledger'],'conversion_opening_changed');
        $native=(new ClosingRepository($this->db))->pendingResultLines($supplier,(int)$row['target_period_id'],$date,'931',(int)$row['source_period_id'],true);$cells=Builder::cells($native);$expected=[];
        foreach($row['receipt']['ledger']['lines'] as $line)if($line['account_code']==='931')$expected[]=$line+['signed_amount'=>$line['amount']];
        E::need(self::financialCells($cells)===self::financialCells(Builder::cells($expected)),'conversion_pending_result_changed');E::need($cells!==[],'conversion_result_empty');
        return ['conversion_id'=>$conversion,'source_period_id'=>(int)$row['source_period_id'],'target_period_id'=>(int)$row['target_period_id'],'date'=>$date,'cells'=>$cells,'snapshot_hash'=>Builder::snapshot($cells),'receipt_hash'=>$row['receipt_hash'],'source_approval'=>array_intersect_key($source['source'],array_flip(['approved_at','approved_by','approval_body','approval_decision_ref','approval_document_hash'])),'target'=>$source['target'],'target_roles'=>[['role'=>'result.retained_profit','account_code'=>'932']]];
    }
    private static function financialCells(array $cells):array
    {$out=[];foreach($cells as $cell)$out[$cell['key']]=array_intersect_key($cell,array_flip(['account_code','dimensions','cost_center','project_id','available_cents']));ksort($out);return $out;}
    private function plan(int $supplier,int $conversion,array $body):array
    {
        E::need(array_diff(array_keys($body),['decision_date','decision_authority','decision_reference','allocations'])===[],'conversion_decision_invalid',422);
        foreach(['decision_date','decision_authority','decision_reference'] as $field)E::need(is_string($body[$field]??null)&&trim($body[$field])!==''&&mb_strlen($body[$field])<=1000,'conversion_decision_evidence_required',422);
        E::need(is_array($body['allocations']??null),'conversion_decision_invalid',422);foreach($body['allocations'] as $allocation)E::need(is_array($allocation)&&array_diff(array_keys($allocation),['cell_key','role','amount'])===[]&&($allocation['role']??null)==='result.retained_profit','conversion_decision_role_adoption_required',422);
        $context=$this->context($supplier,$conversion,$body['decision_date']);$lines=Builder::build($context['cells'],$body+['snapshot_hash'=>$context['snapshot_hash']],new AccountingRoleProfile('cz_504'));return ['context'=>$context,'lines'=>$lines];
    }
    public function preview(int $supplier,int $conversion,array $body,int $actor):array
    {E::need($actor>0,'conversion_actor_required',401);return $this->read->transaction($supplier,function()use($supplier,$conversion,$body,$actor){$plan=$this->plan($supplier,$conversion,$body);return ['state_hash'=>R::hash($plan['context']),'preview_hash'=>R::hash([$supplier,$conversion,$actor,$body,$plan]),'plan'=>$plan];});}
    public function confirm(int $supplier,int $conversion,array $body,int $actor,string $state,string $preview,string $token):array
    {
        E::need($actor>0&&preg_match('/^[a-f0-9]{64}$/D',$state)===1&&preg_match('/^[a-f0-9]{64}$/D',$preview)===1&&preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$token)===1,'conversion_command_invalid',422);
        return $this->read->transaction($supplier,function()use($supplier,$conversion,$body,$actor,$state,$preview,$token){$identity=R::hash(['result_decision',$conversion,$actor,$body,$state,$preview]);$old=$this->read->rows('SELECT request_hash,response_json FROM accounting_framework_conversion_commands WHERE supplier_id=? AND command_token=?',[$supplier,$token],true)[0]??null;if($old){E::need(hash_equals($old['request_hash'],$identity),'conversion_idempotency_conflict');return json_decode($old['response_json'],true,512,JSON_THROW_ON_ERROR);}
            $plan=$this->plan($supplier,$conversion,$body);E::need(hash_equals($state,R::hash($plan['context']))&&hash_equals($preview,R::hash([$supplier,$conversion,$actor,$body,$plan])),'conversion_preview_stale');
            $nativeLines=$this->posting->prepareDocumentLines($supplier,'profit_distribution',$plan['context']['source_period_id'],$plan['lines'],$body['decision_date'],true);$entry=$this->posting->postDocument($supplier,'profit_distribution',$plan['context']['source_period_id'],$plan['lines'],['entry_date'=>$body['decision_date'],'document_date'=>$body['decision_date'],'document_no'=>'500-504/VH/'.$conversion,'description'=>$body['decision_authority'].': '.$body['decision_reference'],'posted'=>true,'posted_by'=>$actor,'user_id'=>$actor]);
            $header=['supplier_id'=>$supplier,'period_id'=>$plan['context']['target_period_id'],'entry_date'=>$body['decision_date'],'document_date'=>$body['decision_date'],'document_no'=>'500-504/VH/'.$conversion,'description'=>$body['decision_authority'].': '.$body['decision_reference'],'source_type'=>'profit_distribution','source_id'=>$plan['context']['source_period_id'],'posted_by'=>$actor];$ledger=$this->read->writtenLedger($supplier,$entry,$header,$nativeLines);$receipt=['context'=>$plan['context'],'lines'=>$plan['lines'],'ledger'=>$ledger];$q=$this->db->pdo()->prepare('INSERT INTO accounting_framework_conversion_decisions(supplier_id,conversion_id,accounting_date,journal_entry_id,request_json,receipt_json,receipt_hash,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$supplier,$conversion,$body['decision_date'],$entry,R::json($body),R::json($receipt),R::hash($receipt),$actor]);$result=['decision_id'=>(int)$this->db->pdo()->lastInsertId(),'conversion_id'=>$conversion,'journal_entry_id'=>$entry];
            $q=$this->db->pdo()->prepare('INSERT INTO accounting_framework_conversion_commands(supplier_id,command_token,request_hash,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$supplier,$token,$identity,R::json($result),$actor]);return $result;
        });
    }
}
