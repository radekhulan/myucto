<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Funding;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use PDO;
final class FundingService
{
    public function __construct(private readonly Connection $db,private readonly FundingRepository $repository,
        private readonly FundingAssessmentEvidence $evidence,private readonly NonprofitTaxSourceRepository $sources,
        private readonly FundingEligibilityGuard $eligibility,private readonly DimensionPeriodGuard $periods) {}
    public function read(int $supplier): array
    {
        return $this->overview($supplier,false);
    }
    public function choices(int $supplier,int $period,string $search=''):array
    {
        $periodRow=$this->sources->period($supplier,$period);
        if(mb_strlen($search)>100)FundingPolicy::invalid('Hledaný poskytovatel je příliš dlouhý.');
        $q=$this->db->pdo()->prepare("SELECT id,company_name,first_name,last_name FROM clients WHERE supplier_id=? AND (company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY company_name,first_name,last_name,id LIMIT 101");
        $like='%'.trim($search).'%';$q->execute([$supplier,$like,$like,$like]);$providers=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($providers)>100;$providers=array_slice($providers,0,100);
        $providers=array_map(static fn($r)=>['id'=>(int)$r['id'],'label'=>$r['company_name']?:trim(($r['first_name']??'').' '.($r['last_name']??''))],$providers);
        $q=$this->db->pdo()->prepare("SELECT v.id,v.code,v.name FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND t.system_key='activity_kind' ORDER BY v.code,v.id");$q->execute([$supplier]);
        $activities=array_map(static fn($r)=>['id'=>(int)$r['id'],'label'=>$r['code'].' - '.$r['name']],$q->fetchAll(PDO::FETCH_ASSOC));
        $q=$this->db->pdo()->prepare("SELECT account_code,name FROM chart_of_accounts WHERE supplier_id=? AND account_code LIKE '5%' AND is_active=1 ORDER BY account_code");$q->execute([$supplier]);$accounts=array_map(static fn($r)=>['code'=>$r['account_code'],'label'=>$r['account_code'].' - '.$r['name']],$q->fetchAll(PDO::FETCH_ASSOC));
        $receipt=$this->evidence->periodReceipt($supplier,$period,false);
        $current=$receipt!==null&&$receipt['current'];
        [$options,$optionsMore]=$current?$this->evidence->candidates($receipt):[[],false];
        return ['period'=>$periodRow,'assessment_current'=>$current,'providers'=>$providers,'providers_more'=>$more,'activities'=>$activities,'accounts'=>$accounts,'sources'=>$options,'sources_more'=>$optionsMore];
    }
    public function preview(int $supplier,int $period,string $sourceKey,array $input): array
    {
        $policy=FundingPolicy::normalize($input); $this->key($sourceKey);
        $context=$this->context($supplier,$period,$sourceKey,$policy,false);
        return ['source_key'=>$sourceKey,'policy'=>$policy,'input_hash'=>$context['input_hash'],'expected_revision'=>$context['previous']['revision_no']??0,
            'report'=>$context['report'],'financial_authorization'=>false];
    }
    public function confirm(int $supplier,int $period,string $sourceKey,array $input,string $inputHash,int $expectedRevision,string $reason,string $token,int $actor): array
    {
        $policy=FundingPolicy::normalize($input); $this->key($sourceKey); $this->key($token);
        NonprofitTaxRules::evidence($reason);
        if($actor<=0||$expectedRevision<0||!preg_match('/^[a-f0-9]{64}$/D',$inputHash))FundingPolicy::invalid('Chybí platný autor, revize nebo otisk.');
        $requestInput=['supplier'=>$supplier,'period'=>$period,'source_key'=>$sourceKey,'policy'=>$policy,'input_hash'=>$inputHash,'expected_revision'=>$expectedRevision,'reason'=>$reason,'actor'=>$actor];
        $request=NonprofitTaxRules::hash($requestInput);
        return $this->periods->transaction(function()use($supplier,$period,$sourceKey,$policy,$inputHash,$expectedRevision,$reason,$token,$actor,$request,$requestInput):array{
            $statuses=$this->periods->lockPeriods($supplier);
            if(!isset($statuses[$period]))throw new NonprofitTaxAssessmentException('nonprofit_funding_period_not_found','Účetní období nebylo nalezeno.',404);
            $all=$this->repository->all($supplier,true);
            foreach($all as $row)if($row['idempotency_key']===$token){
                if(!hash_equals($row['request_hash'],$request))throw new NonprofitTaxAssessmentException('nonprofit_funding_idempotency_conflict','Klíč již patří jinému rozhodnutí.',409);
                return $row;
            }
            if(!in_array($statuses[$period],['open','closing'],true))throw new NonprofitTaxAssessmentException('nonprofit_funding_period_locked','V uzavřeném období nelze měnit účelový zdroj.',409);
            $context=$this->context($supplier,$period,$sourceKey,$policy,true);
            if(($context['previous']['revision_no']??0)!==$expectedRevision||!hash_equals($inputHash,$context['input_hash']))throw new NonprofitTaxAssessmentException('nonprofit_funding_sources_changed','Podklady nebo účelový zdroj se změnily. Načtěte nový náhled.',409);
            return $this->repository->append($supplier,$period,$sourceKey,$context['previous'],$policy,$context['basis'],$reason,$token,$request,$actor,$requestInput);
        });
    }
    private function context(int $supplier,int $period,string $key,array $policy,bool $lock): array
    {
        $this->sources->period($supplier,$period,$lock);
        $provider=$this->provider($supplier,$policy['provider_id'],$lock);
        $this->eligibilityReferences($supplier,$policy,$lock);
        $overview=$this->overview($supplier,$lock);
        $blocking=array_filter($overview['findings'],static fn($finding)=>$finding['code']!=='funding_source_not_current'||($finding['source_key']??null)!==$key);
        if($blocking!==[])FundingPolicy::invalid('Čerpání nemá aktuální potvrzené účetní a daňové podklady.','nonprofit_funding_assessment_incomplete');
        $latest=$this->repository->latest($supplier,$lock); $previous=$latest[$key]??null;
        if($previous!==null){
            foreach(['kind','provider_id','source_anchor'] as $field)if($previous['policy'][$field]!==$policy[$field])FundingPolicy::invalid('Právní původ účelového zdroje se nepřepisuje.');
            if(array_diff($previous['policy']['source_members'],$policy['source_members'])!==[])FundingPolicy::invalid('Původní členové zdroje musí zůstat v historii.');
        }
        foreach($this->repository->all($supplier,$lock) as $row)if($row['source_key']!==$key&&array_intersect($row['policy']['source_members'],$policy['source_members'])!==[])FundingPolicy::invalid('Tentýž účetní zdroj již patří jinému účelovému zdroji.','nonprofit_funding_source_already_used');
        $receipts=$this->evidence->receipts($supplier,$lock); $basis=null;
        $projections=$this->projections($receipts,[$policy]);
        foreach($receipts as $receipt){
            if(!$receipt['current'])continue;
            $catalog=$projections[$receipt['id']]['rows'];
            if(!isset($catalog[$policy['source_anchor']]))continue;
            if(FundingPolicy::poolMembers($projections[$receipt['id']]['pools'],$policy['source_anchor'])!==$policy['source_members'])continue;
            $members=[]; $amount=0;
            foreach($policy['source_members'] as $id){
                if(!isset($catalog[$id]))FundingPolicy::invalid('Člen zdroje chybí v potvrzeném posouzení.');
                FundingPolicy::assertTitle($policy,$catalog[$id]); $members[$id]=$catalog[$id]; $amount+=$catalog[$id]['amount_cents'];
            }
            if($catalog[$policy['source_anchor']]['amount_cents']<=0||$amount<=0)FundingPolicy::invalid('Zdroj musí mít kladný doložený čistý zůstatek.');
            $basis=['assessment_id'=>$receipt['id'],'assessment_input_hash'=>$receipt['input_hash'],'source_rows'=>FundingPolicy::sourceRows($members,$policy['source_members']),'recognized_cents'=>$amount,'provider'=>$provider];
            break;
        }
        if($basis===null)FundingPolicy::invalid('Účelový zdroj vyžaduje aktuální potvrzené posouzení právního titulu.','nonprofit_funding_assessment_incomplete');
        $proposal=['policy'=>$policy,'basis'=>$basis];
        foreach($receipts as $receipt)if($receipt['status']==='confirmed')$this->eligibility->assertProjection([$proposal],$projections[$receipt['id']]);
        $report=$this->sourceReport($proposal,$receipts,$projections);
        if($report['drawdown_cents']>$policy['budget_cents'])FundingPolicy::invalid('Nový rozpočet je nižší než potvrzené použití.','nonprofit_funding_budget_exceeded');
        $hash=NonprofitTaxRules::hash(['supplier'=>$supplier,'period'=>$period,'source_key'=>$key,'policy'=>$policy,'basis'=>$basis,
            'sources'=>array_map(static fn($row)=>$row['record_hash'],$latest),'receipt_ids'=>array_map(static fn($row)=>[$row['id'],$row['input_hash'],$row['current']],$receipts)]);
        return ['previous'=>$previous,'basis'=>$basis,'input_hash'=>$hash,'report'=>$report];
    }
    private function overview(int $supplier,bool $lock): array
    {
        $latest=$this->repository->latest($supplier,$lock); $receipts=$this->evidence->receipts($supplier,$lock); $findings=[];
        foreach($receipts as $row)if(!$row['current']&&$row['usage_count']>0)$findings[]=['code'=>'funding_assessment_not_current','assessment_id'=>$row['id'],'period_id'=>$row['period_id']];
        $projections=$this->projections($receipts,array_column($latest,'policy'));
        $reports=[];
        foreach($latest as $key=>$row){
            $current=false;
            foreach($receipts as $receipt){
                if(!$receipt['current'])continue;
                $catalog=$projections[$receipt['id']]['rows'];
                if(array_diff($row['policy']['source_members'],array_keys($catalog))!==[])continue;
                if(FundingPolicy::poolMembers($projections[$receipt['id']]['pools'],$row['policy']['source_anchor'])!==$row['policy']['source_members'])continue;
                $actual=FundingPolicy::sourceRows($catalog,$row['policy']['source_members']);
                if(NonprofitTaxRules::hash($actual)===NonprofitTaxRules::hash($row['basis']['source_rows']))$current=true;
            }
            if(!$current)$findings[]=['code'=>'funding_source_not_current','source_key'=>$key];
            $report=$this->sourceReport($row,$receipts,$projections);if(!$current)$report['available_cents']=null;
            $reports[]=['source_key'=>$key,'revision'=>$row,'source_current'=>$current]+$report;
        }
        return ['sources'=>$reports,'findings'=>$findings,'complete'=>$findings===[],'usage_basis'=>'confirmed_nonprofit_tax_assessments','financial_authorization'=>false];
    }

    /**
     * Projekce potvrzených posouzení omezená na kotvy a členy daných politik, jedna na posouzení.
     *
     * @return array<int,array>
     */
    private function projections(array $receipts,array $policies): array
    {
        $anchors=[];$members=[];
        foreach($policies as $policy){
            $anchors[$policy['source_anchor']]=true;
            foreach($policy['source_members'] as $id)$members[$id]=true;
        }
        $out=[];
        foreach($receipts as $receipt){
            if($receipt['status']!=='confirmed')continue;
            $out[$receipt['id']]=$anchors===[]?['rows'=>[],'pools'=>[],'links'=>[],'usage'=>[]]
                :$this->evidence->project($receipt,array_keys($anchors),array_keys($members));
        }
        return $out;
    }
    private function sourceReport(array $source,array $receipts,array $projections): array
    {
        $policy=$source['policy']; $uses=[]; $total=0;
        foreach($receipts as $receipt){
            if($receipt['status']!=='confirmed')continue;
            $rows=$projections[$receipt['id']]['rows'];
            foreach($projections[$receipt['id']]['links'] as $link)if($link['source_id']===$policy['source_anchor']){
                $total+=$link['amount_cents'];
                $uses[]=['assessment_id'=>$receipt['id'],'period_id'=>$receipt['period_id'],'current'=>$receipt['current'],
                    'cost'=>$rows[$link['cost_id']],'amount_cents'=>$link['amount_cents'],'evidence'=>$link['evidence']];
            }
        }
        return ['recognized_cents'=>$source['basis']['recognized_cents']??null,'budget_cents'=>$policy['budget_cents'],'drawdown_cents'=>$total,'budget_remaining_cents'=>$policy['budget_cents']-$total,'available_cents'=>isset($source['basis'])?min($policy['budget_cents'],$source['basis']['recognized_cents'])-$total:null,'uses'=>$uses];
    }
    private function eligibilityReferences(int $supplier,array $policy,bool $lock):void
    {
        $q=$this->db->pdo()->prepare("SELECT v.id FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND t.system_key='activity_kind'".($lock?' FOR UPDATE':''));$q->execute([$supplier]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if(array_diff($policy['activity_kind_ids'],$ids)!==[])FundingPolicy::invalid('Druh činnosti není součástí této firmy.');
        $q=$this->db->pdo()->prepare('SELECT account_code FROM '.\MyInvoice\Repository\AccountDefinitionRepository::relation($policy['valid_from'],$supplier,$lock)." a WHERE supplier_id=? AND account_code LIKE '5%' AND is_active=1".($lock?' FOR UPDATE':''));$q->execute([$supplier]);$codes=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($policy['account_prefixes']as$prefix){$found=false;foreach($codes as$code)if(str_starts_with($code,$prefix))$found=true;if(!$found)FundingPolicy::invalid('Způsobilý nákladový účet není součástí této firmy.');}
    }
    private function provider(int $supplier,int $provider,bool $lock): array
    {
        $q=$this->db->pdo()->prepare('SELECT id,company_name,first_name,last_name,ic,dic,street,city,zip,country_id FROM clients WHERE supplier_id=? AND id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$supplier,$provider]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new NonprofitTaxAssessmentException('nonprofit_funding_provider_not_found','Poskytovatel nebyl nalezen v této firmě.',404);
        $row['id']=(int)$row['id'];$row['country_id']=$row['country_id']===null?null:(int)$row['country_id'];return $row;
    }
    private function key(string $key): void
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/D',$key))FundingPolicy::invalid('Neplatný identifikátor účelového zdroje nebo požadavku.');
    }
}
