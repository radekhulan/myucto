<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Service\Property\Payments\PaymentException;

final class PaymentExportPlan
{
    public static function bytes(array $snapshot):string
    {
        $items=[];
        foreach($snapshot['reservations'] as $row){
            $amount=$row['amount_cents'];
            if(bccomp($amount,'0',0)<=0||bccomp($amount,'999999999999',0)>0)PaymentException::reject('abo_amount_range');
            [$account,$bank]=explode('/',$row['request']['recipient_account']);$symbols=$snapshot['request']['payment_symbols'][$row['id']]??null;
            if(!is_array($symbols))PaymentException::reject('abo_symbols_required',['reservation_id'=>$row['id']]);
            $items[]=['account_number'=>$account,'bank_code'=>$bank,'amount_minor'=>(int)$amount,
                'variable_symbol'=>$symbols['variable_symbol']??null,'constant_symbol'=>$symbols['constant_symbol']??null,'specific_symbol'=>$symbols['specific_symbol']??null,
                'allow_missing_variable_symbol'=>$symbols['allow_missing_variable_symbol']??false,'message'=>$symbols['message']??$row['request']['disposition']];
        }
        try{return (new AboPaymentOrderWriter())->build(['client_name'=>$snapshot['payer']['label'],'payer_account_number'=>$snapshot['payer']['account_number'],'payer_bank_code'=>$snapshot['payer']['bank_code'],
            'payment_date'=>$snapshot['request']['due_date'],'items'=>$items]);}
        catch(\InvalidArgumentException $e){PaymentException::reject('abo_format',['reason'=>$e->getMessage()]);}
    }
}
