<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
final class PaymentException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=409,public readonly array $details=[])
    { parent::__construct($message); }
    public static function reject(string $code,array $details=[]): never
    { throw new self('property_payment_'.$code,'Platbu nelze potvrdit bez aktuálních doložených podkladů. Zkontrolujte uvedený důvod.',409,$details); }
}
