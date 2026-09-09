<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogPricingExchangeRateRepository;

/**
 * Čtení kurzu z exchange_rates pro eshopovou cenotvorbu (Epic ESHOP).
 *
 * Nejbližší kurz s rate_date ≤ zadané datum (vzor CashJournalRepository). rate =
 * CZK za 1 jednotku měny (ČNB, JPY/HUF už normalizované na jednotku). DB-only,
 * žádný živý fetch — přepočet ceny nesmí záviset na dostupnosti ČNB. Aritmetika
 * bcmath/string (money-safe). CZK vrací '1'.
 */
final class FxRateProvider
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogPricingExchangeRateRepository $pricingRates,
    ) {}

    /** CZK za 1 jednotku měny k datu (nebo null, když kurz není). CZK → '1'. */
    public function rateFor(string $currencyCode, string $onDate, ?PricingSnapshot $snapshot = null): ?string
    {
        if ($snapshot !== null) {
            return $snapshot->rateFor($currencyCode);
        }
        $code = strtoupper(trim($currencyCode));
        if ($code === '' || $code === 'CZK') {
            return '1';
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate FROM exchange_rates
              WHERE currency_code = ? AND rate_date <= ?
              ORDER BY rate_date DESC
              LIMIT 1'
        );
        $stmt->execute([$code, $onDate]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null || bccomp((string) $v, '0', 6) <= 0 ? null : (string) $v;
    }

    /** Převede částku v cizí měně na CZK: amount * rate. Null když kurz chybí. */
    public function toCzk(string $amount, string $currencyCode, string $onDate, ?PricingSnapshot $snapshot = null): ?string
    {
        $rate = $this->rateFor($currencyCode, $onDate, $snapshot);
        if ($rate === null) {
            return null;
        }
        return bcmul($amount, $rate, 6);
    }

    public function businessRateFor(
        int $supplierId,
        string $currencyCode,
        string $onDate,
        string $source,
        int $maxAgeDays,
        ?PricingSnapshot $snapshot = null,
    ): array {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($snapshot !== null) {
            return $snapshot->businessRateFor($currencyCode, $source, $maxAgeDays);
        }
        if ($currencyCode === 'CZK') {
            return ['rate' => '1', 'rate_date' => $onDate, 'source' => 'identity'];
        }
        return PricingSnapshot::validateBusinessRate(
            $this->pricingRates->latest($supplierId, $currencyCode, $source, $onDate),
            $currencyCode,
            $source,
            $onDate,
            $maxAgeDays,
        );
    }
}
