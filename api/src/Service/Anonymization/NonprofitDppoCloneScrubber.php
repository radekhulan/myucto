<?php

declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitLegalProfileService,NonprofitTaxRules};
use PDO;

final class NonprofitDppoCloneScrubber
{
    public static function capture(Connection $db):array
    {
        self::assertClonePhase($db);
        return(new DimensionPeriodGuard($db))->transaction(function()use($db):array{
            $pdo=$db->pdo();$profiles=[];$legal=new NonprofitLegalProfileService($db);
            foreach($pdo->query('SELECT DISTINCT supplier_id,period_id FROM nonprofit_legal_profiles ORDER BY supplier_id,period_id')->fetchAll(PDO::FETCH_ASSOC)as$owner){$supplier=(int)$owner['supplier_id'];(new DimensionPeriodGuard($db))->lockPeriods($supplier);array_push($profiles,...$legal->history($supplier,(int)$owner['period_id'],true));}
            // Archivy 504 a evidenci úspor zachytávají po dávkách NonprofitDppoArchiveCloneCapture
            // a NonprofitTaxSavingsCloneScrubber; tady zůstávají jen historie právních profilů.
            return['database'=>(string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'profiles'=>$profiles];
        });
    }

    public static function runProfiles(Connection $db,TextScrubber $scrubber,array $capture):array
    {
        self::assertClonePhase($db);
        if(($capture['database']??null)!==(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn()||!is_array($capture['profiles']??null))throw new \LogicException('DPPO clone requires its native profile capture.');
        return(new DimensionPeriodGuard($db))->transaction(function()use($db,$scrubber,$capture):array{
            $rebased=self::rebaseProfiles($capture['profiles'],$scrubber);
            $update=$db->pdo()->prepare('UPDATE nonprofit_legal_profiles SET profile_json=?,module_proof_json=?,reason=?,idempotency_key=?,request_hash=?,record_hash=? WHERE supplier_id=? AND id=?');$owners=[];
            foreach($rebased['rows']as$row){$update->execute([NonprofitTaxRules::canonicalJson($row['profile']),NonprofitTaxRules::canonicalJson($row['module_proof']),$row['reason'],$row['idempotency_key'],$row['request_hash'],$row['record_hash'],$row['supplier_id'],$row['id']]);$owners[$row['supplier_id'].':'.$row['period_id']]=[$row['supplier_id'],$row['period_id']];}
            foreach($owners as[$supplier,$period])(new NonprofitLegalProfileService($db))->history($supplier,$period,true);
            return$rebased['hashes'];
        });
    }

    public static function rebaseProfiles(array $rows,TextScrubber $scrubber,array $hashes=[]):array
    {
        $previous=[];$out=[];
        foreach($rows as$row){$key=$row['supplier_id'].':'.$row['period_id'];$parent=$previous[$key]??null;
            if($row['revision_no']!==($parent['revision_no']??0)+1||$row['previous_id']!==($parent['id']??null))throw new \LogicException('DPPO clone profile history is not contiguous.');
            $oldRequest=$row['request_hash'];$oldRecord=$row['record_hash'];$row['profile']=self::transform($scrubber,$row['profile'],$hashes);$row['module_proof']=self::transform($scrubber,$row['module_proof'],$hashes);$row['reason']=$scrubber->scrub($row['reason']);$row['idempotency_key']='clone-dppo-profile-'.$row['id'];
            $row['request_hash']=NonprofitTaxRules::hash(['period_id'=>$row['period_id'],'expected_revision'=>$row['revision_no']-1,'profile'=>$row['profile'],'reason'=>$row['reason'],'actor'=>$row['created_by']]);
            $record=[];foreach(['supplier_id','period_id','revision_no','previous_id','profile','module_proof','reason','idempotency_key','request_hash','created_by']as$field)$record[$field]=$row[$field];$record['parent_hash']=$parent['record_hash']??null;$row['record_hash']=NonprofitTaxRules::hash($record);
            foreach([$oldRequest=>$row['request_hash'],$oldRecord=>$row['record_hash']]as$old=>$new){if(isset($hashes[$old])&&$hashes[$old]!==$new)throw new \LogicException('DPPO clone has conflicting profile hash replacements.');$hashes[$old]=$new;}
            $out[]=$row;$previous[$key]=$row;
        }
        return['rows'=>$out,'hashes'=>$hashes];
    }

    public static function transform(TextScrubber $scrubber,array $data,array $hashes=[]):array
    {
        $after=json_decode($scrubber->scrubJson(NonprofitTaxRules::canonicalJson($data)),true,512,JSON_THROW_ON_ERROR);
        $walk=static function(array $before,array $value,string $parent='')use(&$walk,$hashes):array{
            foreach($before as$key=>$item){
                if(is_array($item)){$value[$key]=$walk($item,$value[$key],(string)$key);continue;}
                if(!is_string($item))continue;
                $field=(string)$key;$hashField=str_ends_with($field,'_hash')||in_array($field,['source_sha256','xml_sha256','hash'],true);
                if($hashField&&isset($hashes[$item])){$value[$key]=$hashes[$item];continue;}
                $amountField=in_array($field,['amount','amount_foreign','fx_rate'],true)||str_ends_with($field,'_cents')||str_ends_with($field,'_kc');
                $dateField=in_array($field,['date','entry_date','document_date','starts_on','ends_on','effective_from','effective_to','created_at','posted_at'],true);
                if(($hashField&&preg_match('/^[a-f0-9]{64}$/D',$item)===1)
                    ||($amountField&&preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$item)===1)
                    ||($dateField&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[T ][0-9:.+Z-]+)?$/D',$item)===1)
                    ||in_array($key,['kind','owner','entity_type','ruleset','reduction_band','regime','method','mode','scope','status','final_regime','final_method','tax_regime','activity','account_code','currency_code','assignment_origin','semantic_key','saving_qualified_purpose','income_treatment'],true)
                    ||in_array($parent,['account_prefixes','source_members','allowed_purposes'],true)
                    ||($key==='legal_basis'&&preg_match('/^(?:zdp|zou|vyhl)_[a-z0-9_]+$/D',$item)===1))$value[$key]=$item;
            }
            return$value;
        };
        return$walk($data,$after);
    }

    private static function assertClonePhase(Connection $db):void
    {
        if($db->pdo()->inTransaction()||TriggerMetadata::any($db->pdo()))throw new \LogicException('DPPO clone must run before trigger installation outside an active transaction.');
    }
}
