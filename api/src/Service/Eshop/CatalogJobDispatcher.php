<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Service\Stock\StockValuationJobService;
use Psr\Container\ContainerInterface;

final class CatalogJobDispatcher
{
    public const HANDLERS = [
        'price_recompute' => CatalogPriceJobService::class,
        'stock_valuation' => StockValuationJobService::class,
    ];

    public function __construct(private readonly Connection $db, private readonly ContainerInterface $container) {}

    public function tick(int $maxBatches = 10): array
    {
        $kinds = array_keys(self::HANDLERS);
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT supplier_id, kind FROM catalog_jobs
            WHERE kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ") AND status IN ('queued','running') ORDER BY supplier_id, kind");
        $stmt->execute($kinds);
        $stats = ['processed' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $lane) {
            try {
                $result = $this->container->get(self::HANDLERS[$lane['kind']])->tick((int) $lane['supplier_id'], $maxBatches);
                if ($result !== null) {
                    $stats['processed']++;
                }
            } catch (\Throwable) {
                $stats['failed']++;
            }
        }
        return $stats;
    }
}
