<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document\Delivery;

/**
 * Cesta k zaměstnanci je zavřená. Nese strojový kód důvodu, aby ho fronta mohla
 * uložit do `last_error_code` a UI ho přeložilo — text výjimky se do e-mailu ani
 * do veřejné odpovědi nikdy nedostane.
 */
final class PayrollSecureDeliveryBlockedException extends \DomainException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
