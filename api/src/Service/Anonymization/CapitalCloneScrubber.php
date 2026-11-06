<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Nonprofit\Capital\CapitalLedger;
use MyInvoice\Service\Nonprofit\Capital\CapitalPolicy;
use MyInvoice\Service\Nonprofit\Capital\CapitalRepository;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
final class CapitalCloneScrubber
{
    public static function capture(Connection $db):array
    {
        self::assertPhase($db);$db->pdo()->beginTransaction();
        try{$suppliers=$db->pdo()->query('SELECT DISTINCT supplier_id FROM nonprofit_capital_sources ORDER BY supplier_id')->fetchAll(PDO::FETCH_COLUMN);$out=[];foreach($suppliers as$id)$out[(int)$id]=(new CapitalService($db))->read((int)$id);$pending=$db->pdo()->query('SELECT c.id FROM nonprofit_capital_commands c LEFT JOIN nonprofit_capital_sources s ON s.supplier_id=c.supplier_id AND s.command_id=c.id LEFT JOIN nonprofit_capital_events e ON e.supplier_id=c.supplier_id AND e.command_id=c.id WHERE s.id IS NULL AND e.id IS NULL LIMIT 1')->fetchColumn();if($pending!==false)throw new \LogicException('Capital clone cannot discard a pending owner command.');$db->pdo()->commit();return $out;}catch(\Throwable $e){if($db->pdo()->inTransaction())$db->pdo()->rollBack();throw $e;}
    }
    public static function run(Connection $db,TextScrubber $scrubber,array $captured):void
    {
        self::assertPhase($db);$db->pdo()->beginTransaction();try{
            $repo=new CapitalRepository($db);$ledger=new CapitalLedger($db);$service=new CapitalService($db);$pdo=$db->pdo();
            foreach($captured as$supplier=>$before){
                $sources=$repo->sources((int)$supplier);$events=$repo->events((int)$supplier);$updated=[];$last=[];$reversed=[];foreach($events as$event)if($event['original_id']!==null)$reversed[$event['original_id']]=true;
                foreach($sources as$id=>$source){
                    $source['policy']=self::transform($scrubber,$source['policy']);$source['basis']=self::transform($scrubber,$source['basis']);$source['basis']['source']=$ledger->source((int)$supplier,$source['policy']);if($source['kind']==='investment')$source['basis']['asset']=$service->assetBasis((int)$supplier,$source['asset_id']);$source['record_hash']=CapitalRepository::sourceHash($source);
                    $command=$repo->command((int)$supplier,$source['command_id']);$request=self::transform($scrubber,$command['request']);$request['policy']=$source['policy'];$request['input_hash']=CapitalPolicy::hash(['policy'=>$source['policy'],'basis'=>$source['basis']]);self::command($db,$command,$request);
                    $pdo->prepare('UPDATE nonprofit_capital_sources SET policy_json=?,basis_json=?,record_hash=? WHERE supplier_id=? AND id=?')->execute([NonprofitTaxRules::canonicalJson($source['policy']),NonprofitTaxRules::canonicalJson($source['basis']),$source['record_hash'],$supplier,$id]);$sources[$id]=$source;
                }
                foreach($events as$id=>$event){
                    $source=$sources[$event['source_id']];$plan=self::transform($scrubber,$event['snapshot']);$plan['source']=$source;
                    if($event['operation']==='reverse'){$original=$updated[$event['original_id']]??throw new \LogicException('Capital clone reversal order is invalid.');$original['active']=true;$plan['original']=$original;}
                    elseif(!isset($reversed[$id])){
                        if($source['kind']==='investment'){$plan['depreciation']=$service->depreciationProof((int)$supplier,$source,$event['depreciation_id']);$plan['cost']=$plan['depreciation']['cost'];}
                        else{$plan['cost']=$ledger->cost((int)$supplier,$event['cost_line_id']);$plan['cost']['direct_coverage']=(new NativeCostCoverage($db))->forCost((int)$supplier,$event['cost_line_id']);}
                    }
                    $native=(new PaymentJournalProof($db))->current((int)$supplier,$event['entry_id']);if($event['operation']==='release')$native['entry']['reversed_by']=null;$event['receipt']=$native;$event['snapshot']=$plan;$event['parent_hash']=$last[$event['source_id']]['record_hash']??str_repeat('0',64);$event['record_hash']=CapitalRepository::eventHash($event);
                    $command=$repo->command((int)$supplier,$event['command_id']);$request=self::transform($scrubber,$command['request']);$request['plan']=$plan;$request['input_hash']=CapitalPolicy::hash($plan);self::command($db,$command,$request);
                    $pdo->prepare('UPDATE nonprofit_capital_events SET parent_hash=?,snapshot_json=?,receipt_json=?,record_hash=? WHERE supplier_id=? AND id=?')->execute([$event['parent_hash'],NonprofitTaxRules::canonicalJson($plan),NonprofitTaxRules::canonicalJson($native),$event['record_hash'],$supplier,$id]);$last[$event['source_id']]=$event;$updated[$id]=$event;
                }
                $after=$service->read((int)$supplier);if(self::financial($before)!==self::financial($after))throw new \LogicException('Capital clone changed financial availability.');
            }
            $pdo->commit();
        }catch(\Throwable $e){if($db->pdo()->inTransaction())$db->pdo()->rollBack();throw $e;}
    }
    private static function command(Connection $db,array $command,array $request):void
    {
        $db->pdo()->prepare('UPDATE nonprofit_capital_commands SET idempotency_key=?,request_json=?,request_hash=? WHERE supplier_id=? AND id=?')->execute(['clone-capital-'.$command['id'],NonprofitTaxRules::canonicalJson($request),CapitalPolicy::hash($request),$command['supplier_id'],$command['id']]);
    }
    private static function financial(array $data):array
    {
        $out=[];foreach($data['sources']as$row){$s=$row['source'];$out[$s['id']]=['source_line_id'=>$s['source_line_id'],'asset_id'=>$s['asset_id'],'provider_id'=>$s['provider_id'],'kind'=>$s['kind'],'amount_cents'=>$s['amount_cents'],'used_cents'=>$row['used_cents'],'available_cents'=>$row['available_cents'],'events'=>array_map(static fn(array $e)=>array_intersect_key($e,array_flip(['id','source_id','entry_id','operation','original_id','amount_cents','cost_line_id','depreciation_id','active'])),$row['events'])];}return $out;
    }
    private static function transform(TextScrubber $scrubber,array $data,string $parent=''):array
    {
        $after=json_decode($scrubber->scrubJson(NonprofitTaxRules::canonicalJson($data)),true,flags:JSON_THROW_ON_ERROR);
        foreach($data as$key=>$value){if(is_array($value)){$after[$key]=self::transform($scrubber,$value,(string)$key);continue;}if(!is_string($value)||in_array($parent,['account_prefixes'],true)||str_ends_with((string)$key,'_hash')||in_array($key,['kind','operation','action','status','origin','assignment_origin','source_type','account_code','account_type','side','amount','amount_foreign','currency_code','fx_rate','date','entry_date','document_date','valid_from','valid_to','created_at','posted_at'],true)||($key==='ref'&&preg_match('/^nonprofit-capital:[1-9][0-9]*$/D',$value)===1))$after[$key]=$value;}
        return $after;
    }
    private static function assertPhase(Connection $db):void
    {
        if($db->pdo()->inTransaction()||TriggerMetadata::any($db->pdo()))throw new \LogicException('Capital clone phase requires no live transaction or installed triggers.');
    }
}
