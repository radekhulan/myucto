<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PDO;

final class NonprofitLegalProfileService
{
    public function __construct(private readonly Connection $db) {}

    public function read(int $supplier,int $period):array
    {
        $row=$this->period($supplier,$period);
        return ['period'=>$row,'latest'=>$this->latest($supplier,$period),'entity_types'=>array_values(array_diff(NonprofitDppoLegalRules::ENTITY_TYPES,['commercial_corporation','municipality','region','health_insurance_company'])),'kinds'=>NonprofitDppoLegalRules::KINDS];
    }

    public function latest(int $supplier,int $period,bool $currentRead=false):?array
    {
        $history=$this->history($supplier,$period,$currentRead);
        return $history===[]?null:$history[array_key_last($history)];
    }

    public function history(int $supplier,int $period,bool $currentRead=false):array
    {
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Legal profile current reads require a transaction.');
        $s=$this->db->pdo()->prepare('SELECT * FROM nonprofit_legal_profiles WHERE supplier_id=? AND period_id=? ORDER BY revision_no'.($currentRead?' FOR UPDATE':''));$s->execute([$supplier,$period]);
        $rows=[];$previous=null;
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){
            foreach(['id','supplier_id','period_id','revision_no','created_by']as$field)$row[$field]=(int)$row[$field];
            $row['previous_id']=$row['previous_id']===null?null:(int)$row['previous_id'];
            foreach(['profile','module_proof']as$field){$row[$field]=json_decode($row[$field.'_json'],true,flags:JSON_THROW_ON_ERROR);unset($row[$field.'_json']);}
            if($row['revision_no']!==($previous['revision_no']??0)+1||$row['previous_id']!==($previous['id']??null)||!hash_equals($row['record_hash'],NonprofitTaxRules::hash($this->record($row,$previous))))$this->corrupt();
            $expected=NonprofitDppoLegalRules::profile($row['profile'],$row['module_proof']!==[]);
            if(NonprofitTaxRules::hash($expected)!==NonprofitTaxRules::hash($row['profile']))$this->corrupt();
            $request=['period_id'=>$period,'expected_revision'=>$row['revision_no']-1,'profile'=>$row['profile'],'reason'=>$row['reason'],'actor'=>$row['created_by']];
            if(!hash_equals($row['request_hash'],NonprofitTaxRules::hash($request)))$this->corrupt();
            $rows[]=$row;$previous=$row;
        }
        return $rows;
    }

    public function confirm(int $supplier,int $period,array $input,int $actor):array
    {
        if(array_diff(array_keys($input),['profile','expected_revision','reason','idempotency_key'])!==[]||!is_array($input['profile']??null)||array_diff(array_keys($input['profile']),['kind','entity_type','main_activity_nonbusiness','evidence','legal_basis'])!==[]
            ||!is_int($input['expected_revision']??null)||$input['expected_revision']<0||$actor<=0||!is_string($input['idempotency_key']??null)||preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D',$input['idempotency_key'])!==1)$this->invalid();
        $profile=NonprofitDppoLegalRules::profile($input['profile']);$reason=NonprofitTaxRules::evidence($input['reason']??null);
        $request=['period_id'=>$period,'expected_revision'=>$input['expected_revision'],'profile'=>$profile,'reason'=>$reason,'actor'=>$actor];$requestHash=NonprofitTaxRules::hash($request);
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$period,$input,$actor,$profile,$reason,$requestHash):array{
            $statuses=(new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$periodRow=$this->period($supplier,$period);
            if(!isset($statuses[$period]))$this->invalid();
            $s=$this->db->pdo()->prepare('SELECT period_id FROM nonprofit_legal_profiles WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');$s->execute([$supplier,$input['idempotency_key']]);$existing=$s->fetchColumn();
            if($existing!==false){foreach($this->history($supplier,(int)$existing,true)as$row)if($row['idempotency_key']===$input['idempotency_key']){if(!hash_equals($row['request_hash'],$requestHash))throw new TaxReturnException('nonprofit_legal_profile_idempotency_conflict','Klíč požadavku již patří jinému potvrzení právního postavení.',409);return$row;}}
            if(!in_array($statuses[$period],['open','closing'],true))throw new TaxReturnException('nonprofit_legal_profile_period_locked','Právní profil musí být potvrzen před uzamčením období.',409);
            $framework=new \MyInvoice\Service\Accounting\AccountingFrameworkService($this->db,new \MyInvoice\Repository\AccountingFrameworkRepository($this->db));
            if(array_values(array_unique($framework->forInterval($supplier,$periodRow['starts_on'],$periodRow['ends_on'],true)))!==['cz_504'])throw new TaxReturnException('nonprofit_legal_profile_framework_required','Právní profil DPPO 504 vyžaduje stejný účetní rámec v celém období.',422);
            if(!$profile['main_activity_nonbusiness']||in_array($profile['entity_type'],['commercial_corporation','municipality','region','health_insurance_company'],true))throw new TaxReturnException('nonprofit_legal_profile_framework_required','Doložená právní forma nebo hlavní činnost neodpovídá účetnímu rámci 504.',422);
            $previous=$this->latest($supplier,$period,true);
            if(($previous['revision_no']??0)!==$input['expected_revision'])throw new TaxReturnException('nonprofit_legal_profile_changed','Právní profil se změnil. Načtěte aktuální revizi.',409);
            $proof=$this->svjProof($supplier,$periodRow,true);
            $s=$this->db->pdo()->prepare("SELECT profile_json FROM nonprofit_legal_profiles WHERE supplier_id=? ORDER BY period_id,revision_no FOR UPDATE");$s->execute([$supplier]);
            foreach($s->fetchAll(PDO::FETCH_COLUMN)as$json)if((json_decode($json,true,flags:JSON_THROW_ON_ERROR)['kind']??null)==='svj'&&$profile['kind']!=='svj')$this->invalid();
            NonprofitDppoLegalRules::profile($profile,$proof!==[]);
            $row=['supplier_id'=>$supplier,'period_id'=>$period,'revision_no'=>$input['expected_revision']+1,'previous_id'=>$previous['id']??null,'profile'=>$profile,'module_proof'=>$proof,'reason'=>$reason,'idempotency_key'=>$input['idempotency_key'],'request_hash'=>$requestHash,'created_by'=>$actor];
            $hash=NonprofitTaxRules::hash($this->record($row,$previous));
            $s=$this->db->pdo()->prepare('INSERT INTO nonprofit_legal_profiles(supplier_id,period_id,revision_no,previous_id,profile_json,module_proof_json,reason,idempotency_key,request_hash,record_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$supplier,$period,$row['revision_no'],$row['previous_id'],NonprofitTaxRules::canonicalJson($profile),NonprofitTaxRules::canonicalJson($proof),$reason,$input['idempotency_key'],$requestHash,$hash,$actor]);
            return$this->latest($supplier,$period,true);
        });
    }

    public function snapshotProof(int $supplier,int $period,bool $currentRead=false):?array
    {
        $latest=$this->latest($supplier,$period,$currentRead);if($latest===null)return null;
        $periodRow=$this->period($supplier,$period);
        $module=$this->svjProof($supplier,$periodRow,$currentRead);
        NonprofitDppoLegalRules::profile($latest['profile'],$module!==[]);
        return ['id'=>$latest['id'],'revision_no'=>$latest['revision_no'],'profile'=>$latest['profile'],'record_hash'=>$latest['record_hash'],'request_hash'=>$latest['request_hash'],'module_proof'=>$module];
    }

    public function assessmentContext(int $supplier,int $period,bool $currentRead=false):array
    {
        $proof=$this->snapshotProof($supplier,$period,$currentRead);
        $module=$this->svjProof($supplier,$this->period($supplier,$period),$currentRead);
        $context=[];if($proof!==null)$context['legal_profile']=$proof;if($module!==[])$context['svj_module_proof']=$module;return$context;
    }

    private function svjProof(int $supplier,array $period,bool $currentRead):array
    {
        $proof=[];
        foreach((new PropertyProfileRepository($this->db))->history($supplier,$currentRead)as$row){
            if($row['enabled']&&substr($row['effective_from'],0,10)<=$period['ends_on'])$proof[]=['id'=>$row['id'],'revision_no'=>$row['revision_no'],'effective_from'=>$row['effective_from'],'effective_to'=>$row['effective_to'],'reason'=>$row['reason'],'title_ref'=>$row['title_ref']];
        }
        return$proof;
    }

    private function record(array $row,?array $previous):array
    {
        $keys=['supplier_id','period_id','revision_no','previous_id','profile','module_proof','reason','idempotency_key','request_hash','created_by'];$record=[];
        foreach($keys as$key)$record[$key]=$row[$key];$record['parent_hash']=$previous['record_hash']??null;return$record;
    }

    private function period(int $supplier,int $period):array
    {return(new AccountingPeriodRepository($this->db))->findById($supplier,$period)??throw new TaxReturnException('nonprofit_legal_profile_period_not_found','Účetní období nebylo nalezeno.',404);}
    private function invalid():never
    {throw new TaxReturnException('nonprofit_legal_profile_invalid','Právní profil vyžaduje platné podklady právní formy, revizi a identitu uživatele.',422);}
    private function corrupt():never
    {throw new TaxReturnException('nonprofit_legal_profile_proof_invalid','Historické potvrzení právního profilu není průkazné.',409);}
}
