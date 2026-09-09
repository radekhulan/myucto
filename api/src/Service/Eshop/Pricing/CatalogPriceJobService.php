<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;

final class CatalogPriceJobService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly PriceCalculationService $calculation,
        private readonly PricingRuleResolver $rules,
    ) {}

    public function enqueue(int $supplierId, ?array $itemIds = null): int
    {
        $sql = 'SELECT DISTINCT p.stock_item_id, i.row_version FROM stock_item_prices p
            JOIN stock_items i ON i.supplier_id = p.supplier_id AND i.id = p.stock_item_id WHERE p.supplier_id = ?';
        $params = [$supplierId];
        if ($itemIds !== null) {
            $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
            if ($itemIds === []) {
                return 0;
            }
            $sql .= ' AND p.stock_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            $params = array_merge($params, $itemIds);
        }
        $onDate = date('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT \'item\' AS kind, stock_item_id AS entry_key, row_version AS entry_value, NULL AS source_date FROM (' . $sql . ') items
            UNION ALL SELECT \'rate\', currency_code, rate, rate_date FROM (
                SELECT currency_code, rate, rate_date, ROW_NUMBER() OVER (PARTITION BY currency_code ORDER BY rate_date DESC) AS position
                FROM exchange_rates WHERE rate_date <= ?
            ) rates WHERE position = 1');
        $stmt->execute([...$params, $onDate]);
        $versions = [];
        $rates = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $entry) {
            if ($entry['kind'] === 'item') {
                $versions[(int) $entry['entry_key']] = (int) $entry['entry_value'];
            } else {
                $rates[$entry['entry_key']] = ['rate' => $entry['entry_value'], 'rate_date' => $entry['source_date']];
            }
        }
        ksort($versions, SORT_NUMERIC);
        $ids = array_keys($versions);
        if ($ids === []) {
            return 0;
        }
        return $this->jobs->enqueue($supplierId, 'price_recompute', [
            'item_ids' => $ids,
            'item_versions' => $versions,
            'on_date' => $onDate,
            'rates' => $rates,
            'pricing_policy' => $this->rules->snapshot($supplierId, $onDate),
        ], count($ids), 3);
    }

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        $job = $this->jobs->claim($supplierId, 'price_recompute');
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($batch = 0; $batch < max(1, min(100, $maxBatches)); $batch++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token, function (array $current) use ($supplierId): array {
                    if (!in_array($current['input_version'], [1, 2, 3], true)) {
                        throw new \RuntimeException('unsupported_input_version');
                    }
                    $ids = $current['input']['item_ids'];
                    if ($current['input_version'] < 3) {
                        $replacement = $this->enqueue($supplierId, array_slice($ids, $current['checkpoint']));
                        return ['checkpoint' => count($ids), 'done' => true, 'report' => ['replacement_job_id' => $replacement, 'reason' => 'input_snapshot_upgrade']];
                    }
                    $snapshot = new PricingSnapshot(
                        $current['input']['on_date'],
                        $current['input']['rates'],
                        $current['input']['pricing_policy'],
                    );
                    $selection = array_slice($ids, $current['checkpoint'], 100);
                    $report = $current['report'] + ['processed' => 0, 'succeeded' => 0, 'failed_items' => [], 'stale_items' => [], 'replacement_job_ids' => []];
                    $stale = [];
                    foreach ($selection as $id) {
                        $this->db->pdo()->exec('SAVEPOINT price_job_item');
                        try {
                            $this->calculation->recompute($supplierId, (int) $id, snapshot: $snapshot, expectedRowVersion: (int) $current['input']['item_versions'][$id]);
                            $report['succeeded']++;
                        } catch (PricingInputException $e) {
                            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT price_job_item');
                            $failure = ['item_id' => (int) $id, 'error_code' => $e->errorCode] + $e->details;
                            if ($e->errorCode === 'stale_price_input') {
                                $stale[] = (int) $id;
                                $report['stale_items'][] = $failure;
                            } else {
                                $report['failed_items'][] = $failure;
                            }
                        }
                        $this->db->pdo()->exec('RELEASE SAVEPOINT price_job_item');
                    }
                    if ($stale !== []) {
                        $report['replacement_job_ids'][] = $this->enqueue($supplierId, $stale);
                    }
                    $checkpoint = $current['checkpoint'] + count($selection);
                    $report['processed'] = $checkpoint;
                    return ['checkpoint' => $checkpoint, 'done' => $checkpoint === count($ids), 'report' => $report];
                }, consistentSnapshot: true);
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'price_recompute_failed');
            throw $e;
        }
    }
}
