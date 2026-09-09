<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

final class PricingInputException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly array $details = [])
    {
        parent::__construct($errorCode);
    }
}
