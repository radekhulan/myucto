<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

final class ChargeException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422, public readonly array $details = []) { parent::__construct($message); }
}
