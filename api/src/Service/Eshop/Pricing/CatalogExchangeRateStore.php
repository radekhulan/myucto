<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogPricingExchangeRateRepository;

final class CatalogExchangeRateStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogPriceJobService $jobs,
        private readonly CatalogPricingExchangeRateRepository $pricingRates,
    ) {}

    public function save(string $date, array $rates): void
    {
        $normalizedRates = [];
        foreach ($rates as $code => $rate) {
            $normalizedRates[strtoupper((string) $code)] = (string) $rate;
        }
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE fetched_at = IF(rate <> VALUES(rate), NOW(), fetched_at), rate = VALUES(rate)');
            $changed = [];
            foreach ($normalizedRates as $code => $rate) {
                $stmt->execute([$date, $code, $rate]);
                if ($stmt->rowCount() > 0) {
                    $changed[] = $code;
                }
            }
            $codes = array_values(array_unique(array_map(
                static fn (string $code): string => strtoupper($code),
                array_keys($normalizedRates),
            )));
            if ($codes !== []) {
                $placeholders = implode(',', array_fill(0, count($codes), '?'));
                $affected = $pdo->prepare('SELECT DISTINCT used.supplier_id, used.currency_code FROM (
                    SELECT p.supplier_id, p.currency_code
                    FROM stock_item_prices p
                    WHERE p.currency_code IN (' . $placeholders . ')
                    UNION ALL
                    SELECT v.supplier_id, v.currency_code
                    FROM stock_item_vendors v
                    INNER JOIN stock_item_prices p
                        ON p.supplier_id = v.supplier_id AND p.stock_item_id = v.stock_item_id
                    WHERE v.currency_code IN (' . $placeholders . ')
                    UNION ALL
                    SELECT profile.supplier_id, profile.currency_code
                    FROM stock_pricing_profiles profile
                    WHERE profile.currency_code IN (' . $placeholders . ')
                ) used
                ORDER BY used.supplier_id, used.currency_code');
                $affected->execute(array_merge($codes, $codes, $codes));
                $usedBySupplier = [];
                foreach ($affected->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $usedBySupplier[(int) $row['supplier_id']][] = (string) $row['currency_code'];
                }
                $changedSet = array_fill_keys($changed, true);
                foreach ($usedBySupplier as $supplierId => $usedCodes) {
                    $businessChanged = false;
                    $accountingChanged = false;
                    foreach ($usedCodes as $code) {
                        $businessChanged = $this->pricingRates->upsert(
                            $supplierId,
                            $code,
                            $date,
                            'cnb',
                            $normalizedRates[$code],
                        ) || $businessChanged;
                        $accountingChanged = isset($changedSet[$code]) || $accountingChanged;
                    }
                    if ($date <= date('Y-m-d') && ($businessChanged || $accountingChanged)) {
                        $this->jobs->enqueue($supplierId);
                    }
                }
            }
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
