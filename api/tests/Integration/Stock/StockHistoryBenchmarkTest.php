<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockValuationJobService;
use MyInvoice\Tests\Support\StockHistoryFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

#[Group('benchmark')]
final class StockHistoryBenchmarkTest extends StockTestCase
{
    use StockHistoryFixtureTrait;

    public function testMeasuredWideCatalog(): void
    {
        if ((int) getenv('MYINVOICE_STOCK_BENCHMARK_CARDS') !== 30000) {
            self::markTestSkipped('Set MYINVOICE_STOCK_BENCHMARK_CARDS to 30000.');
        }
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $this->syntheticEmptyCards($sid, $wh, 30000);
        $jobs = $this->container->get(StockValuationJobService::class);
        $measurements = [];
        foreach (['empty' => '2099-01-31', 'snapshot' => '2099-02-01'] as $scenario => $date) {
            $start = microtime(true);
            $jobs->enqueue($sid, $date, ['warehouse_id' => $wh]);
            $ticks = 0;
            $before = $this->sqlCounters();
            do {
                $job = $jobs->tick($sid);
                $ticks++;
            } while ($job['status'] === 'queued' && $ticks < 30);
            $after = $this->sqlCounters();
            $seconds = microtime(true) - $start;
            self::assertSame('completed', $job['status']);
            self::assertSame(30000, $job['checkpoint']);
            self::assertSame(0, $job['report']['movements']);
            self::assertLessThanOrEqual(12, $ticks);
            $measurements[$scenario] = ['seconds' => $seconds, 'default_cron_ticks' => $ticks,
                'estimated_cron_minutes' => ($ticks - 1) + $seconds / 60,
                'sql' => $after['Questions'] - $before['Questions']];
        }
        fwrite(STDOUT, json_encode(['cards' => 30000, 'scenarios' => $measurements,
            'peak_bytes' => memory_get_peak_usage(true), 'php' => PHP_VERSION,
            'database' => $this->db->pdo()->query('SELECT VERSION()')->fetchColumn(),
            'os' => PHP_OS_FAMILY, 'hardware' => getenv('MYINVOICE_STOCK_BENCHMARK_HARDWARE') ?: null,
        ], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    public function testMeasuredHistory(): void
    {
        $size = (int) getenv('MYINVOICE_STOCK_BENCHMARK_MOVEMENTS');
        if (!in_array($size, [100000, 1000000], true)) {
            self::markTestSkipped('Set MYINVOICE_STOCK_BENCHMARK_MOVEMENTS to 100000 or 1000000.');
        }
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'HISTORY-BENCHMARK');
        $start = microtime(true);
        $this->syntheticHistory($sid, $wh, $item, $size);
        $seedSeconds = microtime(true) - $start;
        $referenceBefore = $this->sqlCounters();
        $start = microtime(true);
        $reference = $this->container->get(StockReportService::class)->valuation($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $referenceSeconds = microtime(true) - $start;
        $referenceAfter = $this->sqlCounters();
        $expected = number_format($size, 2, '.', '');
        self::assertSame($expected, $reference['totals']['value_total']);
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31', ['warehouse_id' => $wh]);
        $start = microtime(true);
        $jobBefore = $this->sqlCounters();
        do {
            $progress = $jobs->tick($sid, 100);
        } while ($progress !== null && $progress['status'] === 'queued');
        $jobSeconds = microtime(true) - $start;
        $jobAfter = $this->sqlCounters();
        self::assertSame('completed', $progress['status']);
        self::assertSame($reference['items'], $jobs->result($sid, $job['id'])['items']);
        $jobs->enqueue($sid, '2099-02-01', ['warehouse_id' => $wh]);
        $start = microtime(true);
        $snapshot = $jobs->tick($sid);
        $snapshotSeconds = microtime(true) - $start;
        self::assertSame(0, $snapshot['report']['movements']);
        $take = $this->takes->create($sid, ['warehouse_id' => $wh, 'take_date' => '2099-02-01',
            'counting_method' => 'physical_count', 'responsible_count_name' => 'Synthetic counter',
            'responsible_inventory_name' => 'Synthetic checker'], $this->userId);
        $start = microtime(true);
        $this->takes->prepare($sid, (int) $take['id'], $this->userId);
        $jobs->tick($sid);
        $inventorySeconds = microtime(true) - $start;
        $inventory = $this->takes->get($sid, (int) $take['id']);
        self::assertSame('counting', $inventory['status']);
        self::assertSame($expected, $inventory['lines'][0]['expected_value']);
        fwrite(STDOUT, json_encode([
            'movements' => $size, 'scenario' => 'single_sku_single_date', 'seed_seconds' => $seedSeconds,
            'reference_seconds' => $referenceSeconds, 'job_seconds' => $jobSeconds, 'snapshot_seconds' => $snapshotSeconds,
            'inventory_seconds' => $inventorySeconds,
            'peak_bytes' => memory_get_peak_usage(true), 'php' => PHP_VERSION,
            'database' => $this->db->pdo()->query('SELECT VERSION()')->fetchColumn(),
            'reference_sql' => $referenceAfter['Questions'] - $referenceBefore['Questions'],
            'job_sql' => $jobAfter['Questions'] - $jobBefore['Questions'],
            'reference_index_reads' => $referenceAfter['Handler_read_next'] - $referenceBefore['Handler_read_next'],
            'job_index_reads' => $jobAfter['Handler_read_next'] - $jobBefore['Handler_read_next'],
            'buffer_pool_bytes' => $this->db->pdo()->query('SELECT @@innodb_buffer_pool_size')->fetchColumn(),
            'os' => PHP_OS_FAMILY, 'processor' => getenv('PROCESSOR_IDENTIFIER') ?: null,
            'hardware' => getenv('MYINVOICE_STOCK_BENCHMARK_HARDWARE') ?: null,
        ], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function sqlCounters(): array
    {
        return array_map('intval', $this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Questions', 'Handler_read_next')")->fetchAll(\PDO::FETCH_KEY_PAIR));
    }
}
