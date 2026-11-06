<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
/** Doslovná kopie legacy J6FundingCapacity před převodem na J6FundingProjection (e135a9c43); slouží jen jako referenční výstup testů. */
final class LegacyJ6FundingCapacityOracle
{
    public function __construct(private readonly Connection $db,private readonly NonprofitTaxAssessmentRepository $assessments,private readonly NonprofitTaxSourceRepository $sources) {}
    public function claimedCost(int $supplier,int $line,?int $excludePeriod=null):string
    {
        $total='0';foreach($this->costClaims($supplier,$line,$excludePeriod)as$claim)$total=bcadd($total,$claim['amount_cents'],0);return $total;
    }
    public function costClaims(int $supplier,int $line,?int $excludePeriod=null):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$excludePeriod):array{
            $guard->lockPeriods($supplier);$claims=[];
            foreach($this->assessments->effectiveOtherPeriods($supplier,$excludePeriod??0,true)as$row){
                if($row['status']!=='confirmed')continue;
                $links=array_values(array_filter($row['assessment']['funding']??[],static fn(array $link):bool=>$link['cost_id']==='line:'.$line));
                if($links===[])continue;$this->assertCurrentReceipt($supplier,$row);$total='0';
                foreach($links as$link){if(!is_int($link['amount_cents'])||$link['amount_cents']<=0)self::changed();$total=bcadd($total,(string)$link['amount_cents'],0);}
                $claims[]=['assessment_id'=>$row['id'],'period_id'=>$row['period_id'],'cost_line_id'=>$line,'amount_cents'=>$total,'input_hash'=>$row['input_hash'],'request_hash'=>$row['request_hash'],'links'=>$links];
            }
            return $claims;
        });
    }
    public function costEnvelope(int $supplier,int $line,?int $excludePeriod=null):?array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$excludePeriod):?array{
            $guard->lockPeriods($supplier);$found=null;
            foreach($this->assessments->effectiveOtherPeriods($supplier,$excludePeriod??0,true)as$row){
                if($row['status']!=='confirmed')continue;$declared=null;
                foreach($row['assessment']['funding_costs']??[]as$pool)if(in_array('line:'.$line,$pool['members']??[],true))$declared=$pool;
                if($declared===null)continue;$this->assertCurrentReceipt($supplier,$row);if($found!==null)self::changed();
                $rows=array_column($row['result']['rows'],null,'id');$pools=NonprofitTaxCalculator::forConnection($this->db)->fundingCostPools($row['result'],$row['assessment']);$pool=$pools[$declared['anchor']]??null;if($pool===null)self::changed();$amounts=[];
                foreach($pool['members']as$id)$amounts[(int)$rows[$id]['line_id']]=$rows[$id]['amount_cents'];ksort($amounts,SORT_NUMERIC);
                $found=['assessment_id'=>$row['id'],'period_id'=>$row['period_id'],'anchor'=>(int)$rows[$pool['id']]['line_id'],'members'=>array_keys($amounts),'line_amounts'=>$amounts,'net_cents'=>$pool['amount_cents'],'proof'=>['input_hash'=>$row['input_hash'],'request_hash'=>$row['request_hash'],'declared'=>$declared,'rows'=>array_intersect_key($rows,array_flip($pool['members']))]];
            }
            return $found;
        });
    }
    public function assertAssessment(int $supplier,array $snapshot,array $assessment,array $result):void
    {
        $guard=new DimensionPeriodGuard($this->db);
        $guard->transaction(function()use($guard,$supplier,$snapshot,$assessment,$result):void{
            $guard->lockPeriods($supplier);$period=(int)$snapshot['period']['id'];$current=$this->sources->snapshot($supplier,$period,true);if(!hash_equals($snapshot['input_hash'],$current['input_hash']))self::changed();
            $rows=array_column($result['rows'],null,'id');$catalog=$rows+($snapshot['funding_reference_sources']??[]);$byCost=[];$income=[];
            foreach($assessment['funding']??[]as$link){$byCost[$link['cost_id']]=bcadd($byCost[$link['cost_id']]??'0',(string)$link['amount_cents'],0);$income[$link['source_id']]=true;}
            $native=new NativeCostCoverage($this->db);$coverage=new CostCoverage($this->db);
            try{
                $incomePools=NonprofitTaxCalculator::forConnection($this->db)->fundingIncomePools($result,$snapshot,$assessment);$checked=[];
                foreach(array_keys($income)as$anchor){$pool=$incomePools[$anchor]??null;if($pool===null)self::changed();foreach($pool['members']as$id){if(isset($checked[$id]))continue;$checked[$id]=true;$source=$catalog[$id]??null;if(!$source)self::changed();if($source['amount_cents']<0)$native->incomePoolMember($supplier,(int)$source['line_id'],['6']);else$native->income($supplier,(int)$source['line_id'],['6']);}}
                $declared=[];foreach($assessment['funding_costs']??[]as$pool)$declared[$pool['anchor']]=true;
                foreach(NonprofitTaxCalculator::forConnection($this->db)->fundingCostPools($result,$assessment)as$pool){
                    $needs=isset($declared[$pool['id']]);foreach($pool['members']as$id)if(isset($byCost[$id]))$needs=true;if(!$needs)continue;
                    $total='0';
                    foreach($pool['members']as$id){$cost=$rows[$id];if($cost['amount_cents']<=0)continue;$proof=$native->forCost($supplier,(int)$cost['line_id']);if(!$proof['eligible'])throw new NonprofitTaxAssessmentException('nonprofit_tax_funding_native_credit_note','Náklad má nativní dobropis a vyžaduje průkazné vypořádání před financováním.',409);$direct=$proof['covered_cents'];$claimed=$coverage->claimed($supplier,(int)$cost['line_id'],['j6_period_id'=>$period]);$rowUse=bcadd(bcadd($byCost[$id]??'0',$claimed,0),(string)$direct,0);
                        if(bccomp($rowUse,(string)$cost['amount_cents'],0)>0)$this->overcovered();$total=bcadd($total,$rowUse,0);
                    }
                    if(bccomp($total,(string)max(0,$pool['amount_cents']),0)>0)$this->overcovered();
                }
            }catch(PostingException $e){throw new NonprofitTaxAssessmentException($e->errorCode,$e->getMessage(),$e->httpStatus);}
        });
    }
    public function assertCurrentReceipt(int $supplier,array $row):void
    {
        $guard=new DimensionPeriodGuard($this->db);
        $guard->transaction(function()use($guard,$supplier,$row):void{
            $guard->lockPeriods($supplier);
            $this->assertHistoricalReceipt($supplier,$row);
            $current=$this->sources->snapshot($supplier,$row['period_id'],true);if($current['findings']!==[]||!hash_equals($row['input_hash'],$current['input_hash']))self::changed();
        });
    }
    public function assertHistoricalReceipt(int $supplier,array $row):void
    {
        $guard=new DimensionPeriodGuard($this->db);
        $guard->transaction(function()use($guard,$supplier,$row):void{
            $guard->lockPeriods($supplier);
            if($row['supplier_id']!==$supplier || $row['status']!=='confirmed' || ($row['snapshot']['supplier_id']??null)!==$supplier || (int)($row['snapshot']['period']['id']??0)!==$row['period_id'])self::changed();
            $input=$row['snapshot'];unset($input['input_hash']);$request=['action'=>'confirm','period_id'=>$row['period_id'],'assessment'=>$row['assessment'],'input_hash'=>$row['input_hash'],'reason'=>$row['reason'],'actor'=>$row['created_by'],'expected_assessment_id'=>$row['previous_id']];
            if($row['ruleset']!==NonprofitTaxRules::VERSION||NonprofitTaxRules::hash($input)!==$row['input_hash']||($row['snapshot']['input_hash']??null)!==$row['input_hash']||NonprofitTaxRules::hash($request)!==$row['request_hash']||NonprofitTaxRules::hash(NonprofitTaxCalculator::forConnection($this->db)->calculate($row['snapshot'],$row['assessment']))!==NonprofitTaxRules::hash($row['result']))self::changed();
        });
    }
    private function overcovered():never{throw new NonprofitTaxAssessmentException('nonprofit_funding_cost_overcovered','Součet potvrzeného financování převyšuje skutečný náklad po opravách a přímém krytí.',422);}
    private static function changed():never{throw new NonprofitTaxAssessmentException('nonprofit_tax_funding_changed','Dříve potvrzené financování nákladu vyžaduje nové posouzení účetních podkladů.',409);}
}
