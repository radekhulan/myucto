<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;

final class CreditReservationState
{
    public static function initial(string $amount): array
    {
        return ['version'=>0,'liability_cents'=>StatutoryAllocationPolicy::cents($amount),'reservations'=>[]];
    }
    public static function available(array $state): string
    {
        $available=$state['liability_cents'];
        foreach ($state['reservations'] as $reservation) if (in_array($reservation['status'],['reserved','submitted'],true)) $available=bcsub($available,bcsub($reservation['amount_cents'],$reservation['paid_cents'],0),0);
        if (bccomp($available,'0',0)<0) throw new \DomainException('credit_overcommitted');
        return $available;
    }
    public static function reserve(array $state,int $expected,string $key,string $amount): array
    {
        StatutoryAllocationPolicy::cents($amount);
        if (isset($state['reservations'][$key])) {
            if ($state['reservations'][$key]['amount_cents']!==$amount) throw new \DomainException('idempotency_conflict');
            return $state;
        }
        self::cas($state,$expected);
        if ($key===''||bccomp($amount,'0',0)<=0||bccomp($amount,self::available($state),0)>0) throw new \DomainException('credit_insufficient');
        $state['reservations'][$key]=['status'=>'reserved','amount_cents'=>$amount,'paid_cents'=>'0','payments'=>[]]; ++$state['version']; return $state;
    }
    public static function cancel(array $state,int $expected,string $key): array
    {
        if (($state['reservations'][$key]['status']??null)==='cancelled') return $state;
        self::cas($state,$expected);
        if (($state['reservations'][$key]['status']??null)!=='reserved') throw new \DomainException('reservation_not_cancellable');
        $state['reservations'][$key]['status']='cancelled'; ++$state['version']; return $state;
    }
    public static function pay(array $state,int $expected,string $key,string $source,?string $amount=null): array
    {
        $reservation=$state['reservations'][$key]??null;
        foreach ($state['reservations'] as $otherKey=>$other) if (isset($other['payments'][$source])) {
            if ($otherKey!==$key || ($amount!==null && $other['payments'][$source]!==$amount)) throw new \DomainException('idempotency_conflict');
            return $state;
        }
        self::cas($state,$expected);
        if ($source===''||!in_array($reservation['status']??null,['reserved','submitted'],true)) throw new \DomainException('reservation_not_payable');
        $remaining=bcsub($reservation['amount_cents'],$reservation['paid_cents'],0);
        $amount=StatutoryAllocationPolicy::cents($amount??$remaining);
        if (bccomp($amount,'0',0)<=0 || bccomp($amount,$remaining,0)>0) throw new \DomainException('refund_amount_exceeds_reservation');
        $state['liability_cents']=bcsub($state['liability_cents'],$amount,0);
        $state['reservations'][$key]['paid_cents']=bcadd($reservation['paid_cents'],$amount,0);
        $state['reservations'][$key]['payments'][$source]=$amount;
        if ($state['reservations'][$key]['paid_cents']===$reservation['amount_cents']) $state['reservations'][$key]['status']='paid';
        ++$state['version']; return $state;
    }
    private static function cas(array $state,int $expected): void
    {
        if ($state['version']!==$expected) throw new \DomainException('stale_credit_version');
    }
}
