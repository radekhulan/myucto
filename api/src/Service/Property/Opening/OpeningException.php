<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
final class OpeningException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=409,public readonly array $details=[]){parent::__construct($message);}
    public static function reject(string $reason,array $details=[]):never
    {
        throw new self('property_opening_'.$reason,'Počáteční stav nelze potvrdit. Zkontrolujte podklady a označené vazby.',409,$details);
    }
}
