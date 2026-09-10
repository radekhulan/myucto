<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockMediaRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\Import\CatalogImportService;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Eshop\Import\CatalogImportWorker;
use MyInvoice\Service\Eshop\Import\CatalogMediaFetchException;
use MyInvoice\Service\Eshop\Import\CatalogMediaFetcher;
use MyInvoice\Service\Eshop\Import\CatalogMediaImportService;
use MyInvoice\Service\Eshop\Import\CatalogMediaImportWorker;
use MyInvoice\Service\Eshop\ProductMediaIngestService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\UploadedFile;

final class CatalogMediaImportWorkflowTest extends StockTestCase
{
    private array $files = [];

    protected function setUp(): void
    {
        $root = Bootstrap::rootDir();
        $config = Config::load($root);
        $database = (string) $config->get('db.name');
        $expectedDatabase = getenv('MYINVOICE_DB_NAME');
        if (!is_string($expectedDatabase) || $expectedDatabase === '') {
            $expectedDatabase = getenv('MYSQL_DATABASE');
        }
        if (!is_string($expectedDatabase) || $expectedDatabase === '') {
            $expectedDatabase = $database;
        }
        self::assertMatchesRegularExpression('/_test$/D', $expectedDatabase);
        self::assertSame($expectedDatabase, $database);
        $connection = new Connection($config);
        $pdo = $connection->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->exec("INSERT IGNORE INTO countries (id, iso2, iso3, name_cs, name_en, is_eu)
                VALUES (1, 'CZ', 'CZE', 'Česko', 'Czechia', 1)");
            $pdo->exec("INSERT IGNORE INTO vat_rates
                (id, code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, display_order)
                VALUES (1, 'CZ21', 21.00, 'CZ', 'Základní', 'Standard', 1, 0, '2025-01-01', 1)");
            $pdo->exec("INSERT IGNORE INTO supplier
                (id, company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                VALUES (1, 'Synthetic fixture', 'Test 1', 'Praha', '11000', 1, 'fixture@example.test', 1, 1)");
            $pdo->exec("INSERT IGNORE INTO currencies
                (id, supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                VALUES (1, 1, 'CZK', 'Koruna česká', 'Kč', 'Koruna česká', 'Czech koruna', 2, 1, 1)");
            $pdo->exec("INSERT IGNORE INTO users (id, email, password_hash, name, role, locale, is_active)
                VALUES (1, 'fixture-user@example.test', '\$2y\$10\$abcdefghijklmnopqrstuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', 'Fixture user', 'admin', 'cs', 1)");
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $connection->close();
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testMediaJobEncryptsUrlsResumesAndDeduplicatesPerTenant(): void
    {
        $supplierId = $this->createSupplier();
        $otherSupplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'MEDIA-IMPORT');
        $initialVersion = $this->itemsRepo->find($supplierId, $itemId)['row_version'];
        $config = ['mapping' => ['sku' => 'sku', 'media_urls' => 'media']];
        $urls = '["https://cdn.example.test/one.png","https://cdn.example.test/two.png"]';
        $preview = $this->preview($supplierId, "sku;media\nMEDIA-IMPORT;\"" . str_replace('"', '""', $urls) . "\"\n", $config);

        self::assertSame(1, $preview['report']['counts']['ready']);
        $stageRow = $this->container->get(CatalogJobItemRepository::class)->page($supplierId, $preview['id'])['items'][0];
        self::assertSame('[media_urls:redacted]', $stageRow['input']['raw'][1]);
        self::assertStringNotContainsString('cdn.example.test', json_encode($stageRow, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('enc:v2:', json_encode($stageRow, JSON_THROW_ON_ERROR));

        $apply = $this->container->get(CatalogImportService::class)->apply($supplierId, $preview['id'], $this->userId);
        $apply = $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::APPLY_KIND);
        self::assertSame($initialVersion, $this->itemsRepo->find($supplierId, $itemId)['row_version']);
        $mediaJobId = (int) $apply['report']['media_job_id'];
        self::assertGreaterThan(0, $mediaJobId);
        self::assertNull($this->container->get(CatalogJobService::class)->find($otherSupplierId, $mediaJobId));

        $calls = 0;
        $worker = $this->mediaWorker(new class($calls) implements CatalogMediaFetcher {
            public function __construct(private int &$calls) {}
            public function assertSyntax(string $url): void {}
            public function fetch(string $url): array
            {
                $this->calls++;
                return ['body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
                    'original_name' => 'fixture.png'];
            }
        });
        $first = $worker->tickKind($supplierId, CatalogMediaImportService::KIND, 1);
        self::assertSame('queued', $first['status']);
        self::assertSame(1, $first['checkpoint']);
        self::assertSame(1, $this->mediaCount($supplierId, $itemId));

        $finished = $worker->tickKind($supplierId, CatalogMediaImportService::KIND, 1);
        self::assertSame('completed', $finished['status']);
        self::assertSame(1, $finished['report']['counts']['applied']);
        self::assertSame(1, $finished['report']['counts']['unchanged']);
        self::assertSame(2, $calls);
        self::assertSame(1, $this->mediaCount($supplierId, $itemId));
        self::assertSame(0, $this->mediaCount($otherSupplierId, $itemId));

        $repeatPreview = $this->preview($supplierId, "sku;media\nMEDIA-IMPORT;\"" . str_replace('"', '""', $urls) . "\"\n", $config);
        $this->container->get(CatalogImportService::class)->apply($supplierId, $repeatPreview['id'], $this->userId);
        $repeatApply = $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::APPLY_KIND);
        $repeat = $worker->tickKind($supplierId, CatalogMediaImportService::KIND, 2);
        self::assertSame('completed', $repeat['status']);
        self::assertSame(2, $repeat['report']['counts']['unchanged']);
        self::assertSame(1, $this->mediaCount($supplierId, $itemId));
        self::assertSame($initialVersion, $this->itemsRepo->find($supplierId, $itemId)['row_version']);
        self::assertGreaterThan($mediaJobId, (int) $repeatApply['report']['media_job_id']);
    }

    public function testRetryContinuesFromLastCommittedCheckpoint(): void
    {
        $supplierId = $this->createSupplier();
        $this->item($supplierId, 'MEDIA-RETRY');
        $preview = $this->preview($supplierId, "sku;media\nMEDIA-RETRY;\"[\"\"https://cdn.example.test/retry.png\"\"]\"\n", [
            'mapping' => ['sku' => 'sku', 'media_urls' => 'media'],
        ]);
        $this->container->get(CatalogImportService::class)->apply($supplierId, $preview['id'], $this->userId);
        $apply = $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::APPLY_KIND);

        $failed = $this->mediaWorker(new class implements CatalogMediaFetcher {
            public function assertSyntax(string $url): void {}
            public function fetch(string $url): array { throw new CatalogMediaFetchException('media_remote_unavailable', true); }
        })->tickKind($supplierId, CatalogMediaImportService::KIND, 1);
        self::assertSame('failed', $failed['status']);
        self::assertSame(0, $failed['checkpoint']);

        self::assertTrue($this->container->get(CatalogJobService::class)->retry($supplierId, (int) $apply['report']['media_job_id']));
        $finished = $this->mediaWorker(new class implements CatalogMediaFetcher {
            public function assertSyntax(string $url): void {}
            public function fetch(string $url): array
            {
                return ['body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
                    'original_name' => 'retry.png'];
            }
        })->tickKind($supplierId, CatalogMediaImportService::KIND, 1);
        self::assertSame('completed', $finished['status']);
        self::assertSame(1, $finished['checkpoint']);
    }

    public function testMediaEncryptionKeepsStageOrdinalWhenApplySkipsEarlierFailedRow(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'MEDIA-SECOND');
        $preview = $this->preview(
            $supplierId,
            "sku;name;media\n;Invalid;[]\nMEDIA-SECOND;;\"[\"\"https://cdn.example.test/second.png\"\"]\"\n",
            ['mapping' => ['sku' => 'sku', 'name' => 'name', 'media_urls' => 'media']],
        );

        self::assertSame(1, $preview['report']['counts']['failed']);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->container->get(CatalogImportService::class)->apply($supplierId, $preview['id'], $this->userId);
        $apply = $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::APPLY_KIND);

        $seenUrl = null;
        $finished = $this->mediaWorker(new class($seenUrl) implements CatalogMediaFetcher {
            public function __construct(private ?string &$seenUrl) {}
            public function assertSyntax(string $url): void {}
            public function fetch(string $url): array
            {
                $this->seenUrl = $url;
                return [
                    'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
                    'original_name' => 'second.png',
                ];
            }
        })->tickKind($supplierId, CatalogMediaImportService::KIND, 1);

        self::assertSame('completed', $finished['status']);
        self::assertSame('https://cdn.example.test/second.png', $seenUrl);
        self::assertSame(1, $this->mediaCount($supplierId, $itemId));
        self::assertSame(1, (int) $apply['total']);
    }

    public function testDuplicateMediaHeadersCannotLeakEitherUrlIntoReport(): void
    {
        $supplierId = $this->createSupplier();
        $preview = $this->preview(
            $supplierId,
            "sku;media;media\nMEDIA-DUP;https://cdn.example.test/one.png;https://cdn.example.test/two.png\n",
            ['mapping' => ['sku' => 'sku', 'media_urls' => 'media']],
        );
        $item = $this->container->get(CatalogJobItemRepository::class)->page($supplierId, $preview['id'])['items'][0];

        self::assertSame('import_duplicate_header', $item['error_code']);
        self::assertSame(['MEDIA-DUP', '[media_urls:redacted]', '[media_urls:redacted]'], $item['input']['raw']);
        self::assertStringNotContainsString('cdn.example.test', json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function testMediaEnqueueUsesBoundedKeysetPages(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'MEDIA-PAGED');
        $jobs = $this->container->get(CatalogJobService::class);
        $items = $this->container->get(CatalogJobItemRepository::class);
        $media = $this->container->get(CatalogMediaImportService::class);
        $stageJobId = $jobs->enqueue($supplierId, CatalogImportService::STAGE_KIND, [], 101, createdBy: $this->userId);
        $applyJobId = $jobs->enqueue($supplierId, CatalogImportService::APPLY_KIND, [
            'source_job_id' => $stageJobId,
        ], 101, createdBy: $this->userId);
        $rows = [];
        for ($ordinal = 1; $ordinal <= 101; $ordinal++) {
            $rows[] = [
                'ordinal' => $ordinal,
                'source_row' => $ordinal + 1,
                'stock_item_id' => $itemId,
                'input' => [
                    'media' => $media->sealUrls($supplierId, $stageJobId, $ordinal, [
                        'https://cdn.example.test/' . $ordinal . '.png',
                    ]),
                ],
            ];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $items->append($supplierId, $applyJobId, $rows);
            $stmt = $pdo->prepare("UPDATE catalog_job_items SET status = 'applied', after_json = ?
                WHERE supplier_id = ? AND job_id = ?");
            $stmt->execute([json_encode(['id' => $itemId], JSON_THROW_ON_ERROR), $supplierId, $applyJobId]);
            $mediaJobId = $media->enqueueFromApply($supplierId, $jobs->find($supplierId, $applyJobId));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::assertNotNull($mediaJobId);
        $stmt = $pdo->prepare("SELECT COUNT(*), MIN(ordinal), MAX(ordinal),
                MAX(CASE WHEN ordinal = 101 THEN JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.source_ordinal')) END)
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?");
        $stmt->execute([$supplierId, $mediaJobId]);
        self::assertSame([101, 1, 101, '101'], array_values($stmt->fetch(\PDO::FETCH_ASSOC)));
    }

    private function preview(int $supplierId, string $csv, array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-media-');
        file_put_contents($path, $csv);
        $this->files[] = $path;
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'fixture.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $this->container->get(CatalogImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->container->get(CatalogImportWorker::class)->tickKind($supplierId, CatalogImportService::STAGE_KIND);
    }

    private function mediaWorker(CatalogMediaFetcher $fetcher): CatalogMediaImportWorker
    {
        return new CatalogMediaImportWorker(
            $this->container->get(CatalogJobService::class),
            $this->container->get(CatalogJobItemRepository::class),
            $this->container->get(CatalogMediaImportService::class),
            $fetcher,
            $this->container->get(ProductMediaIngestService::class),
        );
    }

    private function mediaCount(int $supplierId, int $stockItemId): int
    {
        return count($this->container->get(StockMediaRepository::class)->listForItem($supplierId, $stockItemId));
    }
}
