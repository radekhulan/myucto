<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Accounting\Archive\ArchiveService;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Stock\OpeningStockImportService;
use MyInvoice\Service\Stock\OpeningStockImportWorker;
use Slim\Psr7\UploadedFile;

final class OpeningStockImportWorkflowTest extends StockTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testHundredAndOneRowsRestartDedupTenantConflictAndValuation(): void
    {
        $sid = $this->createSupplier();
        $warehouse = $this->warehouse($sid);
        $item = $this->item($sid, '000007');
        $this->db->pdo()->prepare('UPDATE stock_items SET is_stocked = 1 WHERE supplier_id = ? AND id = ?')->execute([$sid, $item]);

        $rows = ["external_id;sku;quantity;unit_cost"];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = sprintf('%06d;000007;1;12.345678', $i);
        }
        $config = $this->config($warehouse, 'opening-2026');
        $preview = $this->enqueuePreview($sid, implode("\n", $rows) . "\n", $config);
        $worker = $this->container->get(OpeningStockImportWorker::class);

        $first = $worker->tickKind($sid, OpeningStockImportService::STAGE_KIND, 1);
        self::assertSame('queued', $first['status']);
        self::assertSame(100, $first['checkpoint']);
        $staged = $worker->tickKind($sid, OpeningStockImportService::STAGE_KIND, 1);
        self::assertSame('completed', $staged['status']);
        self::assertSame(101, $staged['report']['counts']['ready']);

        $service = $this->container->get(OpeningStockImportService::class);
        $apply = $service->apply($sid, $preview['id'], $this->userId);
        $firstApply = $worker->tickKind($sid, OpeningStockImportService::APPLY_KIND, 1);
        self::assertSame('queued', $firstApply['status']);
        self::assertSame(100, $firstApply['checkpoint']);
        $applied = $worker->tickKind($sid, OpeningStockImportService::APPLY_KIND, 1);
        self::assertSame('completed', $applied['status']);
        self::assertSame(101, $applied['report']['counts']['applied']);
        self::assertSame(['qtyT' => 101000, 'valueC' => 124735], $this->level($sid, $warehouse, $item));

        $pdo = $this->db->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM external_entity_map
            WHERE supplier_id = ? AND connection_id IS NULL AND source_key = 'opening-2026'
                AND entity_type = 'stock_opening_line'");
        $count->execute([$sid]);
        self::assertSame(101, (int) $count->fetchColumn());
        $documents = $pdo->prepare("SELECT COUNT(*) FROM stock_documents
            WHERE supplier_id = ? AND description LIKE 'Počáteční zásoby opening-2026 #%'");
        $documents->execute([$sid]);
        self::assertSame(2, (int) $documents->fetchColumn());

        $pdo->prepare("INSERT INTO external_entity_map
            (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, NULL, 'catalog-test', 'catalog_item', 'same-tenant-other-kind', ?)")
            ->execute([$sid, $item]);
        $archiveService = $this->container->get(ArchiveService::class);
        $archive = $archiveService->export($sid, $this->userId);
        $archivePath = $archiveService->filePath($sid, $archive);
        $this->files[] = $archivePath;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath) === true);
        $identityJsonl = $zip->getFromName('external_entity_map.jsonl');
        $zip->close();
        self::assertIsString($identityJsonl);
        $archivedIdentities = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($identityJsonl)))),
        );
        self::assertCount(101, $archivedIdentities);
        self::assertSame(['stock_opening_line'], array_values(array_unique(array_column($archivedIdentities, 'entity_type'))));

        $repeat = $this->enqueuePreview($sid, implode("\n", $rows) . "\n", $config);
        $repeat = $worker->tickKind($sid, OpeningStockImportService::STAGE_KIND, 2);
        self::assertSame(101, $repeat['report']['counts']['unchanged']);
        $repeatApply = $service->apply($sid, $repeat['id'], $this->userId);
        $repeatApply = $worker->tickKind($sid, OpeningStockImportService::APPLY_KIND, 1);
        self::assertSame('completed', $repeatApply['status']);
        self::assertSame(0, $repeatApply['total']);
        $documents->execute([$sid]);
        self::assertSame(2, (int) $documents->fetchColumn());

        $conflict = $this->enqueuePreview($sid, "external_id;sku;quantity;unit_cost\n000001;000007;1;99\n", $config);
        $conflict = $worker->tickKind($sid, OpeningStockImportService::STAGE_KIND, 1);
        self::assertSame(1, $conflict['report']['counts']['conflict']);

        $otherSid = $this->createSupplier();
        $otherWarehouse = $this->warehouse($otherSid);
        $otherItem = $this->item($otherSid, '000007');
        $pdo->prepare('UPDATE stock_items SET is_stocked = 1 WHERE supplier_id = ? AND id = ?')->execute([$otherSid, $otherItem]);
        $other = $this->enqueuePreview($otherSid, "external_id;sku;quantity;unit_cost\n000001;000007;2;3\n", $this->config($otherWarehouse, 'opening-2026'));
        $other = $worker->tickKind($otherSid, OpeningStockImportService::STAGE_KIND, 1);
        self::assertSame(1, $other['report']['counts']['ready']);
        $otherApply = $service->apply($otherSid, $other['id'], $this->userId);
        $otherApply = $worker->tickKind($otherSid, OpeningStockImportService::APPLY_KIND, 1);
        self::assertSame(1, $otherApply['report']['counts']['applied']);
        self::assertSame(['qtyT' => 2000, 'valueC' => 600], $this->level($otherSid, $otherWarehouse, $otherItem));

        $overflow = $this->enqueuePreview($sid,
            "external_id;sku;quantity;unit_cost\nrange;000007;99999999999.999;999999999.999999\n",
            $this->config($warehouse, 'opening-range'),
        );
        $overflow = $worker->tickKind($sid, OpeningStockImportService::STAGE_KIND, 1);
        self::assertSame('completed', $overflow['status']);
        self::assertSame(1, $overflow['report']['counts']['failed']);
    }

    private function enqueuePreview(int $supplierId, string $csv, array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'opening-flow-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'opening.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        return $this->container->get(OpeningStockImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
    }

    private function config(int $warehouseId, string $sourceKey): array
    {
        return [
            'source_key' => $sourceKey,
            'warehouse_id' => $warehouseId,
            'doc_date' => '2099-01-01',
            'mapping' => [
                'external_id' => 'external_id',
                'sku' => 'sku',
                'quantity' => 'quantity',
                'unit_cost' => 'unit_cost',
            ],
        ];
    }
}
