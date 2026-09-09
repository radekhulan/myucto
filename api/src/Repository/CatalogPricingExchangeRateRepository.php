<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogPricingExchangeRateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function upsert(
        int $supplierId,
        string $currencyCode,
        string $rateDate,
        string $source,
        string $rate,
    ): bool {
        $stmt = $this->db->pdo()->prepare('INSERT INTO stock_pricing_exchange_rates
            (supplier_id, currency_code, rate_date, source, rate) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rate = VALUES(rate)');
        $stmt->execute([$supplierId, $currencyCode, $rateDate, $source, $rate]);
        return $stmt->rowCount() > 0;
    }

    public function latest(
        int $supplierId,
        string $currencyCode,
        string $source,
        string $onDate,
    ): ?array {
        $stmt = $this->db->pdo()->prepare('SELECT currency_code, rate_date, source, rate
            FROM stock_pricing_exchange_rates
            WHERE supplier_id = ? AND currency_code = ? AND source = ? AND rate_date <= ?
            ORDER BY rate_date DESC LIMIT 1');
        $stmt->execute([$supplierId, $currencyCode, $source, $onDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function listForSupplier(int $supplierId, int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, currency_code, rate_date,
            source, rate, created_at, updated_at FROM stock_pricing_exchange_rates
            WHERE supplier_id = ? ORDER BY rate_date DESC, currency_code, source LIMIT ' . $limit);
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function snapshot(int $supplierId, array $pairs, string $onDate): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $currency = strtoupper((string) $pair['currency_code']);
            $source = (string) $pair['source'];
            $key = $source . ':' . $currency;
            if (isset($out[$key]) || $currency === 'CZK') {
                continue;
            }
            $rate = $this->latest($supplierId, $currency, $source, $onDate);
            if ($rate !== null) {
                $out[$key] = $rate;
            }
        }
        ksort($out);
        return $out;
    }

    public function currenciesUsedForPricing(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency_code FROM stock_pricing_profiles
            WHERE supplier_id = ? AND is_active = 1
            UNION SELECT currency_code FROM stock_item_vendors
            WHERE supplier_id = ? AND purchase_price IS NOT NULL');
        $stmt->execute([$supplierId, $supplierId]);
        return array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper($code),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        )));
    }

    private static function cast(array $row): array
    {
        return [
            'currency_code' => (string) $row['currency_code'],
            'rate_date' => (string) $row['rate_date'],
            'source' => (string) $row['source'],
            'rate' => (string) $row['rate'],
        ];
    }
}
