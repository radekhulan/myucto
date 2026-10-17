<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankNoticeConfirmationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 409, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
