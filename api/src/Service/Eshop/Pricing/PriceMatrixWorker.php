<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;

final class PriceMatrixWorker
{
    private const BATCH_SIZE = 50;
    private const KINDS = [PriceMatrixService::PREVIEW_KIND, PriceMatrixService::APPLY_KIND];

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly StockItemRepository $stock,
        private readonly PriceMatrixPlanner $planner,
        private readonly PriceWriteService $writer,
    ) {}

    public function tick(int $supplierId, int $maxBatches = 10): ?array
    {
        foreach (self::KINDS as $kind) {
            $result = $this->tickKind($supplierId, $kind, $maxBatches);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Neznámý typ úlohy cenové matice.');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $job = $this->jobs->batch(
                    $supplierId,
                    $job['id'],
                    $token,
                    fn (array $current): array => $this->processBatch($supplierId, $current),
                    consistentSnapshot: $kind === PriceMatrixService::PREVIEW_KIND,
                );
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'price_matrix_failed');
            throw $e;
        }
    }

    private function processBatch(int $supplierId, array $job): array
    {
        if ($job['input_version'] !== 1 || !in_array($job['kind'], self::KINDS, true)) {
            throw new \RuntimeException('unsupported_input_version');
        }
        $snapshotData = $job['input']['snapshot'] ?? null;
        if (!is_array($snapshotData)) {
            throw new \RuntimeException('invalid_price_matrix_snapshot');
        }
        $snapshot = new PricingSnapshot(
            (string) $snapshotData['on_date'],
            (array) $snapshotData['rates'],
            (array) $snapshotData['pricing_policy'],
        );
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], self::BATCH_SIZE);
        foreach ($batch as $entry) {
            if ($entry['status'] !== 'pending') {
                continue;
            }
            if ($job['kind'] === PriceMatrixService::PREVIEW_KIND) {
                $this->previewItem($supplierId, $job, $entry, $snapshot);
            } else {
                $this->applyItem($supplierId, $job, $entry, $snapshot);
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : (int) end($batch)['ordinal'];
        return [
            'checkpoint' => $checkpoint,
            'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])],
        ];
    }

    private function previewItem(int $supplierId, array $job, array $entry, PricingSnapshot $snapshot): void
    {
        $item = $this->stock->find($supplierId, (int) $entry['stock_item_id']);
        if ($item === null) {
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', errorCode: 'unavailable');
            return;
        }
        if ((int) $item['row_version'] !== (int) $entry['expected_version']) {
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'conflict', $this->basicState($item), null, 'version_conflict');
            return;
        }
        try {
            $plan = $this->planner->preview($supplierId, $item, $job['input']['request'], $snapshot);
            $this->items->finish(
                $supplierId,
                $job['id'],
                $entry['ordinal'],
                $plan['changed'] ? 'ready' : 'unchanged',
                $plan['before'],
                $plan['after'],
            );
        } catch (PricingInputException $e) {
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', $this->basicState($item), null, $e->errorCode);
        } catch (\InvalidArgumentException) {
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', $this->basicState($item), null, 'validation_failed');
        }
    }

    private function applyItem(int $supplierId, array $job, array $entry, PricingSnapshot $snapshot): void
    {
        $before = $entry['input']['before'] ?? null;
        $desired = $entry['input']['after'] ?? null;
        if (!is_array($before) || !is_array($desired) || !is_array($desired['write'] ?? null)) {
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', errorCode: 'unavailable');
            return;
        }
        $this->db->pdo()->exec('SAVEPOINT price_matrix_item');
        try {
            $recomputeCurrencies = $job['input']['request']['reprice']
                ? (array) $job['input']['request']['currencies']
                : array_values(array_unique(array_merge(
                    array_column((array) $desired['write']['rows'], 'currency_code'),
                    (array) $desired['write']['delete_currencies'],
                )));
            $result = $this->writer->patchVersioned(
                $supplierId,
                (int) $entry['stock_item_id'],
                (int) $entry['expected_version'],
                (array) $desired['write']['rows'],
                (array) $desired['write']['delete_currencies'],
                $snapshot,
                $recomputeCurrencies,
            );
            $currencies = (array) $job['input']['request']['currencies'];
            $item = $this->stock->find($supplierId, (int) $entry['stock_item_id']);
            if ($item === null) {
                throw new \RuntimeException('price_matrix_item_missing_after_write');
            }
            $actual = $this->planner->stateFromRows($item, $result['prices'], $currencies);
            if (!$this->matchesExpectedCells((array) ($desired['cells'] ?? []), $actual['cells'], $currencies)) {
                $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT price_matrix_item');
                $currentItem = $this->stock->find($supplierId, (int) $entry['stock_item_id']);
                $current = $currentItem === null
                    ? null
                    : $this->planner->currentState($supplierId, $currentItem, $currencies);
                $this->items->finish(
                    $supplierId,
                    $job['id'],
                    $entry['ordinal'],
                    'conflict',
                    $before,
                    $current,
                    'version_conflict',
                );
                return;
            }
            unset($desired['write']);
            foreach ($currencies as $currency) {
                if (isset($actual['cells'][$currency]) && array_key_exists('deviation_pct', $desired['cells'][$currency] ?? [])) {
                    $actual['cells'][$currency]['deviation_pct'] = $desired['cells'][$currency]['deviation_pct'];
                }
            }
            $actual['issues'] = $desired['issues'] ?? [];
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'applied', $before, $actual);
        } catch (EshopException $e) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT price_matrix_item');
            $status = $e->errorCode === 'version_conflict' ? 'conflict' : 'failed';
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], $status, $before, $desired, $e->errorCode);
        } catch (PricingInputException $e) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT price_matrix_item');
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', $before, $desired, $e->errorCode);
        } catch (\Throwable) {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT price_matrix_item');
            $this->items->finish($supplierId, $job['id'], $entry['ordinal'], 'failed', $before, $desired, 'write_failed');
        } finally {
            $this->db->pdo()->exec('RELEASE SAVEPOINT price_matrix_item');
        }
    }

    private function basicState(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'sku' => (string) $item['sku'],
            'name' => (string) $item['name'],
            'row_version' => (int) $item['row_version'],
            'cells' => [],
        ];
    }

    /** @param list<string> $currencies */
    private function matchesExpectedCells(array $expected, array $actual, array $currencies): bool
    {
        $numeric = ['markup_pct', 'fixed_price', 'price', 'cost_czk', 'margin_pct', 'rate'];
        $fields = [
            'price_mode', 'markup_pct', 'fixed_price', 'rounding', 'is_manual_override',
            'use_pricing_rules', 'price', 'cost_czk', 'margin_pct', 'rate', 'rate_date',
            'rate_source', 'profile_id', 'rule_id', 'cost_source',
        ];
        foreach ($currencies as $currency) {
            $expectedCell = $expected[$currency] ?? null;
            $actualCell = $actual[$currency] ?? null;
            if ($expectedCell === null || $actualCell === null) {
                if ($expectedCell !== $actualCell) {
                    return false;
                }
                continue;
            }
            foreach ($fields as $field) {
                $expectedValue = $expectedCell[$field] ?? null;
                $actualValue = $actualCell[$field] ?? null;
                if ($expectedValue === null || $actualValue === null) {
                    if ($expectedValue !== $actualValue) {
                        return false;
                    }
                } elseif (in_array($field, $numeric, true)) {
                    if (bccomp((string) $expectedValue, (string) $actualValue, 8) !== 0) {
                        return false;
                    }
                } elseif ($expectedValue !== $actualValue) {
                    return false;
                }
            }
        }
        return true;
    }
}
