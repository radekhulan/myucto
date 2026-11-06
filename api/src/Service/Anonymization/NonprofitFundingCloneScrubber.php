<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
final class NonprofitFundingCloneScrubber
{
    private array $hashes=[];
    private function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber) {}
    public static function capture(Connection $db):array
    {
        $pdo=$db->pdo();
        if($pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Nonprofit clone capture must precede transformations and triggers.');
        $c=Bootstrap::buildContainer();$c->set(Connection::class,$db);$sources=$c->get(NonprofitTaxSourceRepository::class);$funding=$c->get(FundingService::class);
        $rows=$pdo->query('SELECT * FROM nonprofit_tax_assessments ORDER BY supplier_id,period_id,revision_no')->fetchAll(PDO::FETCH_ASSOC);$flags=[];$snapshots=[];$reports=[];
        foreach($rows as $row){
            $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$input=$snapshot;unset($input['input_hash']);
            if(!hash_equals($row['input_hash'],NonprofitTaxRules::hash($input))||($snapshot['input_hash']??null)!==$row['input_hash'])throw new \LogicException('Nonprofit clone source snapshot is corrupt.');
            $assessment=json_decode($row['assessment_json'],true,flags:JSON_THROW_ON_ERROR);$result=json_decode($row['result_json'],true,flags:JSON_THROW_ON_ERROR);
            if($row['ruleset']===NonprofitTaxRules::VERSION&&NonprofitTaxRules::hash(NonprofitTaxCalculator::forConnection($db)->calculate($snapshot,$assessment))!==NonprofitTaxRules::hash($result))throw new \LogicException('Nonprofit clone source result is corrupt.');
            $supplier=(int)$row['supplier_id'];$period=(int)$row['period_id'];$key=$supplier.':'.$period;
            try{$snapshots[$key]??=$sources->snapshot($supplier,$period);$flags[(int)$row['id']]=hash_equals($row['input_hash'],$snapshots[$key]['input_hash']);}
            catch(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException){$flags[(int)$row['id']]=false;}
            if(!isset($reports[$supplier]))$reports[$supplier]=$funding->read($supplier);
        }
        return ['flags'=>$flags,'reports'=>$reports];
    }
    public static function run(Connection $db,TextScrubber $scrubber,array $captured,array $externalHashes=[]):void
    {
        $pdo=$db->pdo();
        if($pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Nonprofit clone rewrite must precede trigger installation.');
        $owner=new self($db,$scrubber);$owner->hashes=$externalHashes;$foreignKeys=(int)$pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn();$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0');$pdo->beginTransaction();
        try{$owner->rewrite($captured);$pdo->commit();}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS='.$foreignKeys);}
    }
    private function rewrite(array $captured):void
    {
        $pdo=$this->db->pdo();$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$sources=$c->get(NonprofitTaxSourceRepository::class);
        $rows=$pdo->query('SELECT a.* FROM nonprofit_tax_assessments a JOIN accounting_periods p ON p.id=a.period_id AND p.supplier_id=a.supplier_id ORDER BY a.supplier_id,p.starts_on,a.revision_no FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            $supplier=(int)$row['supplier_id'];$period=(int)$row['period_id'];$id=(int)$row['id'];
            $snapshot=$this->transform(json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR));
            if($captured['flags'][$id]??false)$snapshot=$sources->snapshot($supplier,$period,true);
            else{$input=$snapshot;unset($input['input_hash']);$snapshot['input_hash']=NonprofitTaxRules::hash($input);}
            $this->map($row['input_hash'],$snapshot['input_hash']);
            $assessment=$this->transform(json_decode($row['assessment_json'],true,flags:JSON_THROW_ON_ERROR));
            $oldResult=json_decode($row['result_json'],true,flags:JSON_THROW_ON_ERROR);
            $result=$row['ruleset']===NonprofitTaxRules::VERSION?NonprofitTaxCalculator::forConnection($this->db)->calculate($snapshot,$assessment):$this->transform($oldResult);
            foreach(['accounting_result_cents','excluded_revenue_cents','cost_addbacks_cents','assessed_result_cents','funding_usage']as$key)if(NonprofitTaxRules::canonicalJson(['value'=>$result[$key]??null])!==NonprofitTaxRules::canonicalJson(['value'=>$oldResult[$key]??null]))throw new \LogicException('Nonprofit clone changed financial assessment.');
            $reason=$this->scrubber->scrub($row['reason']);
            $request=$row['status']==='withdrawn'?['action'=>'withdraw','period_id'=>$period,'expected_id'=>(int)$row['previous_id'],'reason'=>$reason,'actor'=>(int)$row['created_by']]
                :['action'=>'confirm','period_id'=>$period,'assessment'=>$assessment,'input_hash'=>$snapshot['input_hash'],'reason'=>$reason,'actor'=>(int)$row['created_by'],'expected_assessment_id'=>$row['previous_id']===null?null:(int)$row['previous_id']];
            $pdo->prepare('UPDATE nonprofit_tax_assessments SET input_hash=?,request_hash=?,idempotency_key=?,snapshot_json=?,assessment_json=?,result_json=?,reason=? WHERE supplier_id=? AND id=?')
                ->execute([$snapshot['input_hash'],NonprofitTaxRules::hash($request),'clone-tax-'.$id,NonprofitTaxRules::canonicalJson($snapshot),NonprofitTaxRules::canonicalJson($assessment),NonprofitTaxRules::canonicalJson($result),$reason,$supplier,$id]);
        }
        $funding=$pdo->query('SELECT * FROM nonprofit_funding_revisions ORDER BY supplier_id,source_key,revision_no FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$previous=[];$keys=[];
        foreach($funding as $row){
            $supplier=(int)$row['supplier_id'];$oldKey=$supplier.':'.$row['source_key'];$keys[$oldKey]??='funding-'.$row['id'];
            $policy=$this->transform(json_decode($row['policy_json'],true,flags:JSON_THROW_ON_ERROR));$basis=$this->transform(json_decode($row['basis_json'],true,flags:JSON_THROW_ON_ERROR));$request=$this->transform(json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR));
            $row['source_key']=$keys[$oldKey];$row['reason']=$this->scrubber->scrub($row['reason']);$row['idempotency_key']='clone-funding-'.$row['id'];
            $request['source_key']=$row['source_key'];$request['policy']=$policy;$request['reason']=$row['reason'];
            foreach(['id','supplier_id','period_id','provider_id','assessment_id','revision_no','created_by']as$key)$row[$key]=(int)$row[$key];
            $row['previous_id']=$row['previous_id']===null?null:(int)$row['previous_id'];$row['parent_hash']=$previous[$oldKey]['record_hash']??str_repeat('0',64);
            $row['policy']=$policy;$row['basis']=$basis;$row['request']=$request;$row['request_hash']=NonprofitTaxRules::hash($request);$row['record_hash']=FundingRepository::recordHash($row);
            $pdo->prepare('UPDATE nonprofit_funding_revisions SET source_key=?,parent_hash=?,policy_json=?,basis_json=?,request_json=?,reason=?,idempotency_key=?,request_hash=?,record_hash=? WHERE supplier_id=? AND id=?')
                ->execute([$row['source_key'],$row['parent_hash'],NonprofitTaxRules::canonicalJson($policy),NonprofitTaxRules::canonicalJson($basis),NonprofitTaxRules::canonicalJson($request),$row['reason'],$row['idempotency_key'],$row['request_hash'],$row['record_hash'],$supplier,$row['id']]);
            $previous[$oldKey]=$row;
        }
        foreach($rows as $row){
            $supplier=(int)$row['supplier_id'];$period=(int)$row['period_id'];$q=$pdo->prepare('SELECT input_hash FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=?');$q->execute([$supplier,$row['id']]);
            try{$matches=hash_equals($q->fetchColumn(),$sources->snapshot($supplier,$period,true)['input_hash']);}
            catch(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException){$matches=false;}
            if($matches!==($captured['flags'][(int)$row['id']]??false))throw new \LogicException('Nonprofit clone changed current assessment membership.');
        }
        foreach($captured['reports'] as $supplier=>$before){
            $after=$c->get(FundingService::class)->read((int)$supplier);
            if($this->availability($before)!==$this->availability($after))throw new \LogicException('Nonprofit clone changed purpose availability.');
        }
    }
    private function availability(array $report):array
    {
        $sources=[];
        foreach($report['sources']as$row){
            $anchor=$row['revision']['policy']['source_anchor'];
            if(isset($sources[$anchor]))throw new \LogicException('Nonprofit clone funding owner is ambiguous.');
            $sources[$anchor]=array_intersect_key($row,array_flip(['source_current','recognized_cents','budget_cents','drawdown_cents','budget_remaining_cents','available_cents']));
        }
        ksort($sources,SORT_STRING);return ['complete'=>$report['complete'],'sources'=>$sources];
    }
    private function keys(array $data,string $parent=''):array
    {
        $facts=['claim_exemption','qualifying_purpose','election_evidence','public_budget','domestic_bank_account','foundation_endowment','foundation_purpose_only','qualifying_membership','gross_interest_cents','withholding_cents','income_treatment','income_attribution_evidence','saving_qualified_purpose'];
        $out=[];
        foreach($data as$key=>$value){
            $free=$parent==='cohorts'||($parent==='facts'&&!in_array((string)$key,$facts,true))||(is_string($key)&&preg_match('/^(?:[a-z][a-z0-9_]*|line:[1-9][0-9]*)$/D',$key)!==1);
            $target=$free?$this->scrubber->scrub((string)$key):$key;
            if(array_key_exists($target,$out))throw new \LogicException('Nonprofit clone metadata key collision.');
            $out[$target]=is_array($value)?$this->keys($value,(string)$key):$value;
        }
        return $out;
    }
    private function transform(array $data):array
    {
        $keyed=$this->keys($data);$value=json_decode($this->scrubber->scrubJson(NonprofitTaxRules::canonicalJson($keyed)),true,flags:JSON_THROW_ON_ERROR);
        $restore=function(array $before,array $after,string $parent='')use(&$restore):array{
            foreach($before as$key=>$item){
                if($parent==='facts'&&!in_array($key,['income_treatment','saving_qualified_purpose'],true))continue;
                if(is_array($item)){$after[$key]=$restore($item,$after[$key],(string)$key);continue;}
                if(!is_string($item)){$after[$key]=$item;continue;}
                if(isset($this->hashes[$item])){$after[$key]=$this->hashes[$item];continue;}
                $decision=$parent==='decision'||preg_match('/^line:[1-9][0-9]*$/D',$parent)===1;
                if(in_array($parent,['account_prefixes','source_members'],true)
                    ||preg_match('/^line:[1-9][0-9]*$/D',$item)===1
                    ||str_ends_with((string)$key,'_hash')
                    ||($key==='title'&&$decision)
                    ||in_array($key,['kind','owner','entity_type','reduction_band','schema','ruleset','regime','method','mode','scope','status','final_regime','final_method','tax_regime','activity','account_code','currency_code','fx_rate','assignment_origin','semantic_key','code','date','entry_date','document_date','starts_on','ends_on','valid_from','valid_to'],true)
                    ||($key==='legal_basis'&&preg_match('/^zdp_[a-z0-9_]+$/D',$item)===1))$after[$key]=$item;
            }
            return $after;
        };
        return $restore($keyed,$value);
    }
    private function map(string $old,string $new):void
    {
        if(isset($this->hashes[$old])&&$this->hashes[$old]!==$new)throw new \LogicException('Conflicting nonprofit clone proof.');$this->hashes[$old]=$new;
    }
}
