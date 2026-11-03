<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Service\Invoice\UnitPricePrecision;

final class CurrencyConversionService
{
    /** @var array<string,array{rates:array<string,float>,rate_date:string,fallback_used:bool,source:string}|null> */
    private array $rateCache = [];

    public function __construct(private readonly CnbExchangeRateClient $cnb) {}

    /**
     * @return array{
     *   amount: float,
     *   source_currency: string,
     *   target_currency: string,
     *   cross_rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: string
     * }|null
     */
    public function convert(
        float $amount,
        string $sourceCurrency,
        string $targetCurrency,
        DateTimeImmutable $date,
        int $targetDecimals = 2,
    ): ?array {
        return $this->convertRounded($amount, $sourceCurrency, $targetCurrency, $date, max(0, min(2, $targetDecimals)));
    }

    /**
     * Převod jednotkové ceny (ceník): výsledek na šest desetinných míst jako cena
     * řádku dokladu. Částky a součty převádí {@see convert()} na haléře.
     *
     * @return array{amount: float, source_currency: string, target_currency: string, cross_rate: float,
     *               rate_date: string, fallback_used: bool, source: string}|null
     */
    public function convertUnitPrice(
        float $amount,
        string $sourceCurrency,
        string $targetCurrency,
        DateTimeImmutable $date,
    ): ?array {
        return $this->convertRounded($amount, $sourceCurrency, $targetCurrency, $date, UnitPricePrecision::DECIMALS);
    }

    /** @return array<string,mixed>|null */
    private function convertRounded(
        float $amount,
        string $sourceCurrency,
        string $targetCurrency,
        DateTimeImmutable $date,
        int $decimals,
    ): ?array {
        $source = strtoupper(trim($sourceCurrency));
        $target = strtoupper(trim($targetCurrency));
        if ($source === '' || $target === '') return null;

        if ($source === $target) {
            return [
                'amount' => round($amount, $decimals),
                'source_currency' => $source,
                'target_currency' => $target,
                'cross_rate' => 1.0,
                'rate_date' => $date->format('Y-m-d'),
                'fallback_used' => false,
                'source' => 'identity',
            ];
        }

        $codes = array_values(array_unique(array_filter(
            [$source, $target],
            static fn (string $code): bool => $code !== 'CZK',
        )));
        sort($codes);
        $cacheKey = $date->format('Y-m-d') . '|' . implode(',', $codes);
        if (!array_key_exists($cacheKey, $this->rateCache)) {
            $this->rateCache[$cacheKey] = $this->cnb->getRatesForCommonDate($codes, $date);
        }
        $rateSet = $this->rateCache[$cacheKey];
        if ($rateSet === null) return null;

        $sourceRate = $source === 'CZK' ? 1.0 : (float) ($rateSet['rates'][$source] ?? 0);
        $targetRate = $target === 'CZK' ? 1.0 : (float) ($rateSet['rates'][$target] ?? 0);
        if ($sourceRate <= 0 || $targetRate <= 0) return null;

        $crossRate = $sourceRate / $targetRate;
        return [
            'amount' => round($amount * $crossRate, $decimals),
            'source_currency' => $source,
            'target_currency' => $target,
            'cross_rate' => $crossRate,
            'rate_date' => (string) $rateSet['rate_date'],
            'fallback_used' => (bool) $rateSet['fallback_used'],
            'source' => (string) $rateSet['source'],
        ];
    }
}
