<?php
declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Accounting\PostingException;
final class J6FundingCapacity
{
    public function __construct(private readonly Connection $db,private readonly NonprofitTaxAssessmentRepository $assessments,private readonly NonprofitTaxSourceRepository $sources,private readonly J6FundingProjection $funding) {}
    public function claimedCost(int $supplier,int $line,?int $excludePeriod=null):string
    {
        $total='0';foreach($this->costClaims($supplier,$line,$excludePeriod)as$claim)$total=bcadd($total,$claim['amount_cents'],0);return $total;
    }
    /**
     * Přímé krytí nákladu potvrzenými posouzeními ostatních období (legacy i stránkovanými).
     * Čerpání se čte přes J6FundingProjection, takže stránkovaná revize se nevynechá.
     */
    public function costClaims(int $supplier,int $line,?int $excludePeriod=null):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$excludePeriod):array{
            $guard->lockPeriods($supplier);
            $claims=[];
            foreach($this->assessments->effectiveOtherPeriods($supplier,$excludePeriod??0,true)as$row){
                if($row['status']!=='confirmed')continue;
                $links=$this->funding->links($row,[],['line:'.$line]);
                if($links===[])continue;
                $this->assertCurrentRevision($supplier,$row);
                $total='0';
                foreach($links as$link){
                    if(!is_int($link['amount_cents']??null)||$link['amount_cents']<=0)self::changed();
                    $total=bcadd($total,(string)$link['amount_cents'],0);
                }
                $claims[]=['assessment_id'=>$row['id'],'period_id'=>$row['period_id'],'cost_line_id'=>$line,'amount_cents'=>$total,
                    'input_hash'=>$row['input_hash'],'request_hash'=>$row['request_hash'],'links'=>$links];
            }
            return $claims;
        });
    }

    /** Deklarovaný fond nákladu (čistý náklad po opravách) z potvrzeného posouzení ostatních období. */
    public function costEnvelope(int $supplier,int $line,?int $excludePeriod=null):?array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$excludePeriod):?array{
            $guard->lockPeriods($supplier);
            $found=null;
            foreach($this->assessments->effectiveOtherPeriods($supplier,$excludePeriod??0,true)as$row){
                if($row['status']!=='confirmed')continue;
                $pools=$this->funding->costPools($row,['line:'.$line]);
                if($pools===[])continue;
                $this->assertCurrentRevision($supplier,$row);
                if($found!==null||count($pools)!==1)self::changed();
                $pool=reset($pools);
                $rows=$this->funding->rows($row,$pool['members']);
                $amounts=[];$net=0;
                foreach($pool['members']as$id){
                    if(!isset($rows[$id]['line_id'],$rows[$id]['amount_cents']))self::changed();
                    $amounts[(int)$rows[$id]['line_id']]=$rows[$id]['amount_cents'];
                    $net+=$rows[$id]['amount_cents'];
                }
                ksort($amounts,SORT_NUMERIC);
                $found=['assessment_id'=>$row['id'],'period_id'=>$row['period_id'],'anchor'=>(int)$rows[$pool['anchor']]['line_id'],
                    'members'=>array_keys($amounts),'line_amounts'=>$amounts,'net_cents'=>$net,
                    'proof'=>['input_hash'=>$row['input_hash'],'request_hash'=>$row['request_hash'],'declared'=>$pool['declared'],'rows'=>$rows]];
            }
            return $found;
        });
    }

    /** Potvrzená revize jiného období musí odpovídat dnešním podkladům, jinak se z ní krytí nečte. */
    private function assertCurrentRevision(int $supplier,array $row):void
    {
        if(J6FundingProjection::isPaged($row)){
            if(!$this->funding->pagedCurrent($row,true))self::changed();
            return;
        }
        $this->assertCurrentReceipt($supplier,$row);
    }
    /** Legacy potvrzení: podklady se nezměnily a krytí nákladů napříč zdroji nepřekročí skutečný náklad. */
    public function assertAssessment(int $supplier,array $snapshot,array $assessment,array $result):void
    {
        $guard=new DimensionPeriodGuard($this->db);
        $guard->transaction(function()use($guard,$supplier,$snapshot,$assessment,$result):void{
            $guard->lockPeriods($supplier);
            $period=(int)$snapshot['period']['id'];
            $current=$this->sources->snapshot($supplier,$period,true);
            if(!hash_equals($snapshot['input_hash'],$current['input_hash']))self::changed();
            $this->assertCoverage($supplier,$period,['snapshot'=>$snapshot,'assessment'=>$assessment,'result'=>$result]);
        });
    }

    /**
     * Krytí nákladů uložené revize (i stránkované) napříč zdroji: přímé krytí, nároky ostatních
     * modulů a ostatních období J6 a nativní doklad členů fondu příjmů. Volá stránkové potvrzení
     * nad právě uloženým grafem ve stejné transakci.
     */
    public function assertRevisionCoverage(int $supplier,array $revision):void
    {
        $guard=new DimensionPeriodGuard($this->db);
        $guard->transaction(function()use($guard,$supplier,$revision):void{
            $guard->lockPeriods($supplier);
            $this->assertCoverage($supplier,(int)$revision['period_id'],$revision);
        });
    }

    private function assertCoverage(int $supplier,int $period,array $revision):void
    {
        $facts=$this->funding->coverageFacts($revision);
        $rows=$facts['rows'];
        $byCost=[];
        foreach($facts['links'] as $link)$byCost[$link['cost_id']]=bcadd($byCost[$link['cost_id']]??'0',(string)$link['amount_cents'],0);
        $native=new NativeCostCoverage($this->db);
        $coverage=new CostCoverage($this->db);
        try{
            $checked=[];
            foreach($facts['income_pools'] as $members){
                foreach($members as $id){
                    if(isset($checked[$id]))continue;
                    $checked[$id]=true;
                    $source=$rows[$id]??null;
                    if(!$source)self::changed();
                    if($source['amount_cents']<0)$native->incomePoolMember($supplier,(int)$source['line_id'],['6']);
                    else $native->income($supplier,(int)$source['line_id'],['6']);
                }
            }
            foreach($facts['cost_pools'] as $pool){
                $total='0';
                $net=0;
                foreach($pool['members'] as $id){
                    $cost=$rows[$id]??null;
                    if($cost===null)self::changed();
                    $net+=$cost['amount_cents'];
                    if($cost['amount_cents']<=0)continue;
                    $proof=$native->forCost($supplier,(int)$cost['line_id']);
                    if(!$proof['eligible'])throw new NonprofitTaxAssessmentException('nonprofit_tax_funding_native_credit_note','Náklad má nativní dobropis a vyžaduje průkazné vypořádání před financováním.',409);
                    $claimed=$coverage->claimed($supplier,(int)$cost['line_id'],['j6_period_id'=>$period]);
                    $rowUse=bcadd(bcadd($byCost[$id]??'0',$claimed,0),(string)$proof['covered_cents'],0);
                    if(bccomp($rowUse,(string)$cost['amount_cents'],0)>0)$this->overcovered();
                    $total=bcadd($total,$rowUse,0);
                }
                if(bccomp($total,(string)max(0,$net),0)>0)$this->overcovered();
            }
        }catch(PostingException $e){throw new NonprofitTaxAssessmentException($e->errorCode,$e->getMessage(),$e->httpStatus);}
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
