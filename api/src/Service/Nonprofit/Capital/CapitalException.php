<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
final class CapitalException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=422)
    {parent::__construct($message);}
}
