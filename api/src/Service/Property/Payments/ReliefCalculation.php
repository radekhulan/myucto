<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
final class ReliefCalculation
{
    public static function calculate(string $original,string $reduced,string $paid,string $reduction):array
    {
        foreach([$original,$reduced,$paid,$reduction] as $amount)StatutoryAllocationPolicy::cents($amount);
        $principal=bcsub($original,$reduced,0);
        if(bccomp($principal,'0',0)<0||bccomp($paid,$principal,0)>0||bccomp($reduction,$principal,0)>0)throw new \DomainException('correction_capacity');
        $next=bcsub($principal,$reduction,0);$relief=bccomp($paid,$next,0)>0?bcsub($paid,$next,0):'0';
        $retained=bcsub($paid,$relief,0);
        return ['principal_cents'=>$next,'paid_cents'=>$retained,'relief_cents'=>$relief,'unpaid_cents'=>bcsub($next,$retained,0)];
    }
}
