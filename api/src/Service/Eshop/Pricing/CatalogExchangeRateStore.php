<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;

final class CatalogExchangeRateStore
{
    public function __construct(private readonly Connection $db, private readonly CatalogPriceJobService $jobs) {}

    public function save(string $date, array $rates): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE fetched_at = IF(rate <> VALUES(rate), NOW(), fetched_at), rate = VALUES(rate)');
            $changed = [];
            foreach ($rates as $code => $rate) {
                $stmt->execute([$date, $code, $rate]);
                if ($stmt->rowCount() > 0) {
                    $changed[] = $code;
                }
            }
            if ($changed !== [] && $date <= date('Y-m-d')) {
                $placeholders = implode(',', array_fill(0, count($changed), '?'));
                $affected = $pdo->prepare('SELECT DISTINCT p.supplier_id FROM stock_item_prices p
                    WHERE p.currency_code IN (' . $placeholders . ')
                    OR EXISTS (SELECT 1 FROM stock_item_vendors v WHERE v.supplier_id = p.supplier_id
                        AND v.stock_item_id = p.stock_item_id AND v.currency_code IN (' . $placeholders . '))');
                $affected->execute(array_merge($changed, $changed));
                foreach ($affected->fetchAll(\PDO::FETCH_COLUMN) as $supplierId) {
                    $this->jobs->enqueue((int) $supplierId);
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
