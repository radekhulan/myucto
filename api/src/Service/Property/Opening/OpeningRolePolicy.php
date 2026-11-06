<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
final class OpeningRolePolicy
{
    public const ROLES=[
        'principal'=>['debt','debit','/^315(?:\.[134][0-9]{2})?$/D'],
        'determined_cost'=>['debt','debit','/^315\.4[0-9]{2}$/D'],
        'default_interest'=>['debt','debit','/^315\.4[0-9]{2}$/D'],
        'interest'=>['debt','debit','/^315\.4[0-9]{2}$/D'],
        'credit'=>['credit','credit','/^379\.910$/D'],
        'long_term_advance'=>['advance','credit','/^955(?:\.100)?$/D'],
        'admin_advance'=>['advance','credit','/^324\.200$/D'],
        'service_advance'=>['advance','credit','/^324\.1[0-9]{2}$/D'],
        'service_cost'=>['service_carry','debit','/^315\.2[0-9]{2}$/D'],
        'accrual_receivable'=>['service_carry','debit','/^388\.2[0-9]{2}$/D'],
        'accrual_payable'=>['service_carry','credit','/^389\.1[0-9]{2}$/D'],
    ];
    public static function assert(string $kind,string $role,string $account):string
    {
        $definition=self::ROLES[$role]??null;
        if($definition===null||$definition[0]!==$kind||preg_match($definition[2],$account)!==1)OpeningException::reject('account_role',['role'=>$role,'account_code'=>$account]);
        return $definition[1];
    }
    public static function relevant(string $account):bool
    {
        return in_array(substr($account,0,3),['315','324','955','388','389'],true)||$account==='379.910';
    }
    public static function personal(string $account):bool
    {
        return preg_match(self::ROLES['principal'][2],$account)===1;
    }
}
