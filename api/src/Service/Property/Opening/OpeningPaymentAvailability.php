<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;

use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInstant;

final class OpeningPaymentAvailability
{
    public static function available(array $origin,string $date,?string $instant=null):bool
    {
        if(!isset($origin['opening_accounting_date']))return true;
        return $date>=$origin['opening_accounting_date']&&($instant===null||PropertyInstant::normalize($instant)>=$origin['opening_effective_at']);
    }
    public static function assertAvailable(array $origin,string $date,?string $instant=null):void
    {
        if(!self::available($origin,$date,$instant))PaymentException::reject('backdated_payment');
    }
}
