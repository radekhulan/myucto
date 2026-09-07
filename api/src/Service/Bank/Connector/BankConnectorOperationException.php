<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class BankConnectorOperationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
