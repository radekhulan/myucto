<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

final readonly class PricingSnapshot
{
    public function __construct(
        public string $onDate,
        public array $rates,
        public array $pricingPolicy = [],
    ) {}

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

    public function businessRateFor(string $currency, string $source, int $maxAgeDays): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'CZK') {
            return ['rate' => '1', 'rate_date' => $this->onDate, 'source' => 'identity'];
        }
        $rate = $this->pricingPolicy['rates'][$source . ':' . $currency] ?? null;
        return self::validateBusinessRate($rate, $currency, $source, $this->onDate, $maxAgeDays);
    }

    public static function validateBusinessRate(
        ?array $rate,
        string $currency,
        string $source,
        string $onDate,
        int $maxAgeDays,
    ): array {
        if ($rate === null || bccomp((string) ($rate['rate'] ?? '0'), '0', 6) <= 0) {
            throw new PricingInputException('missing_pricing_exchange_rate', [
                'currency_code' => $currency,
                'source' => $source,
                'on_date' => $onDate,
            ]);
        }
        $rateDate = (string) $rate['rate_date'];
        $age = (new \DateTimeImmutable($rateDate))->diff(new \DateTimeImmutable($onDate))->days;
        if ($rateDate > $onDate || $age > $maxAgeDays) {
            throw new PricingInputException('stale_pricing_exchange_rate', [
                'currency_code' => $currency,
                'source' => $source,
                'on_date' => $onDate,
                'rate_date' => $rateDate,
                'max_age_days' => $maxAgeDays,
            ]);
        }
        return [
            'rate' => (string) $rate['rate'],
            'rate_date' => $rateDate,
            'source' => (string) $rate['source'],
        ];
    }
}
