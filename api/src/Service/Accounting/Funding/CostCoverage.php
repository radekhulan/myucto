<?php
declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Funding;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\Funds\FundingException;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
final class CostCoverage
{
    public function __construct(private readonly Connection $db) {}
    public function capacity(int $supplier,int $line,array $context=[]):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$context):array{
            $guard->lockPeriods($supplier);$native=(new NativeCostCoverage($this->db))->financingCost($supplier,$line);$cost=null;
            foreach($native['ledger']['lines']as$row)if($row['id']===$line)$cost=\MyInvoice\Service\Accounting\JournalLineAmount::signedCents($row);
            if($cost===null)throw new \LogicException('Cost line is missing.');$state=$this->claims($supplier,$line,$context);$claimed=$state['claimed_cents'];$direct=$native['covered_cents'];$available=max(0,$cost-$direct-(int)$claimed);$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$envelope=$this->readJ6(fn()=>$c->get(J6FundingCapacity::class)->costEnvelope($supplier,$line,$context['j6_period_id']??null));$pool=null;
            if($envelope!==null){$poolClaimed='0';$poolDirect='0';$members=[];foreach($envelope['line_amounts']as$id=>$amount){if($amount<=0)continue;$members[$id]=$this->claims($supplier,(int)$id,$context);$poolClaimed=bcadd($poolClaimed,$members[$id]['claimed_cents'],0);$poolDirect=bcadd($poolDirect,(string)(new NativeCostCoverage($this->db))->financingCost($supplier,(int)$id)['covered_cents'],0);}$pool=$envelope+['claimed_cents'=>$poolClaimed,'direct_cents'=>$poolDirect,'claims'=>$members];$available=min($available,max(0,$envelope['net_cents']-(int)$poolClaimed-(int)$poolDirect));}
            return ['cost_cents'=>$cost,'direct_cents'=>$direct,'claimed_cents'=>$claimed,'available_cents'=>$native['eligible']?$available:0,'claims'=>$state['providers'],'pool'=>$pool];
        });
    }
    public function claimed(int $supplier,int $line,array $context=[]):string
    {
        return $this->claims($supplier,$line,$context)['claimed_cents'];
    }
    public function claims(int $supplier,int $line,array $context=[]):array
    {
        if(array_diff(array_keys($context),['j6_period_id'])!==[]||array_key_exists('j6_period_id',$context)&&(!is_int($context['j6_period_id'])||$context['j6_period_id']<=0))throw new \InvalidArgumentException('Invalid coverage replacement context.');
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$context):array{
            $guard->lockPeriods($supplier);
            if(isset($context['j6_period_id'])){$q=$this->db->pdo()->prepare('SELECT e.period_id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=? AND l.id=? FOR UPDATE');$q->execute([$supplier,$line]);if((int)$q->fetchColumn()!==$context['j6_period_id'])throw new \InvalidArgumentException('Coverage context does not own this cost.');}
            $c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);
            try{$svj=(new FundingRepository($this->db))->costClaims($supplier,$line);}catch(FundingException $e){throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus);}
            try{$capital=(new \MyInvoice\Service\Nonprofit\Capital\CapitalCapacity($this->db))->costClaims($supplier,$line);}catch(\MyInvoice\Service\Nonprofit\Capital\CapitalException $e){throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus);}
            $providers=['tax_saving'=>(new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($this->db))->costClaims($supplier,$line),'capital'=>$capital,'svj'=>$svj,'j6'=>$this->readJ6(fn()=>$c->get(J6FundingCapacity::class)->costClaims($supplier,$line,$context['j6_period_id']??null))];$total='0';foreach($providers as$claims)foreach($claims as$claim)$total=bcadd($total,$claim['amount_cents'],0);
            return ['claimed_cents'=>$total,'providers'=>$providers];
        });
    }
    private function readJ6(callable $read):mixed
    {
        try{return $read();}catch(NonprofitTaxAssessmentException $e){throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
