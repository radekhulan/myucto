<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
final class FundingException extends \DomainException
{
    public function __construct(public readonly string $errorCode, public readonly array $details = [], public readonly int $httpStatus = 409)
    {parent::__construct($errorCode);}
    public static function reject(string $code,array $details=[]):never {throw new self('property_funding_'.$code,$details);}
}
