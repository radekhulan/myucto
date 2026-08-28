<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use RuntimeException;

/**
 * Podklad pro zákonný příplatek chybí nebo si odporuje.
 *
 * Vždycky FAIL-CLOSED: příplatek podle § 114 až § 118 je nárok zaměstnance,
 * takže „nevím" nikdy nesmí skončit nulou. Nula se vrací jedině tehdy, když
 * doložená evidence říká nula minut.
 */
final class PayrollSurchargeException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function of(string $reason, string $message): self
    {
        return new self($message, $reason);
    }
}
