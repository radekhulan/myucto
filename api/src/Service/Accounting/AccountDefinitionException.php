<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting;
final class AccountDefinitionException extends \DomainException
{
    public function __construct(public readonly string $errorCode,public readonly int $httpStatus=409)
    {
        parent::__construct('Definice účtu se změnila nebo ji nelze použít k vybranému datu.');
    }
}
