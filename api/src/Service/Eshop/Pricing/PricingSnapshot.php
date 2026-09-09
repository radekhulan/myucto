<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

final readonly class PricingSnapshot
{
    public function __construct(public string $onDate, public array $rates) {}

    public function rateFor(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'CZK') {
            return '1';
        }
        $rate = $this->rates[$currency]['rate'] ?? null;
        if ($rate === null || bccomp($rate, '0', 6) <= 0) {
            throw new PricingInputException('missing_exchange_rate', ['currency_code' => $currency, 'on_date' => $this->onDate]);
        }
        return $rate;
    }
}
