<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

/** Pro měnu a den není kurz ({@see PaymentExchangeRate::forDay()}). */
final class ExchangeRateUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $currency, public readonly string $date)
    {
        parent::__construct('Kurz ČNB pro měnu ' . $currency . ' k ' . $date . ' není k dispozici.');
    }
}
