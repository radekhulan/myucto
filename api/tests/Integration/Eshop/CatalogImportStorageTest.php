<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportProfileStore;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\UploadedFile;

final class CatalogImportStorageTest extends StockTestCase
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

    public function testSourceHasImmutableHashAndDoesNotExposeStorageKey(): void
    {
        $supplier = $this->createSupplier();
        $store = $this->container->get(CatalogImportSourceStore::class);
        $temporary = tempnam(sys_get_temp_dir(), 'catalog-upload-');
        $this->files[] = $temporary;
        $content = "sku;name\n00001;Fixture\n";
        file_put_contents($temporary, $content);
        $source = $store->upload($supplier, new UploadedFile($temporary, '../../fixture.csv', 'text/csv', strlen($content)), $this->userId);
        self::assertSame('fixture.csv', $source['original_name']);
        self::assertSame(hash('sha256', $content), $source['sha256']);
        self::assertArrayNotHasKey('storage_key', $source);
        $path = $store->path($supplier, $source['id']);
        $this->files[] = $path;
        self::assertSame($content, file_get_contents($path));
        file_put_contents($path, 'tampered');
        $this->expectException(EshopException::class);
        $this->expectExceptionMessage('Uložený zdroj importu');
        $store->path($supplier, $source['id']);
    }

    public function testProfileNoOpAndOptimisticVersion(): void
    {
        $supplier = $this->createSupplier();
        $store = $this->container->get(CatalogImportProfileStore::class);
        $config = ['mapping' => ['sku' => 'sku', 'name' => 'name']];
        $first = $store->save($supplier, null, null, 'Fixture', $config);
        $same = $store->save($supplier, $first['id'], 1, 'Fixture', $config);
        self::assertSame(1, $same['version']);
        $next = $store->save($supplier, $first['id'], 1, 'Fixture changed', $config);
        self::assertSame(2, $next['version']);
        self::assertSame($first['config'], $next['config']);
        $this->expectException(EshopException::class);
        $store->save($supplier, $first['id'], 1, 'Stale', $config);
    }

    public function testProfileTenantCannotReadOrModifyForeignProfile(): void
    {
        $owner = $this->createSupplier();
        $other = $this->createSupplier();
        $store = $this->container->get(CatalogImportProfileStore::class);
        $profile = $store->save($owner, null, null, 'Fixture', ['mapping' => ['sku' => 'sku']]);
        self::assertSame([], $store->list($other));
        $this->expectException(EshopException::class);
        $store->save($other, $profile['id'], 1, 'Overwrite', ['mapping' => ['sku' => 'sku']]);
    }

    public function testSourceTenantCannotResolveForeignPath(): void
    {
        $owner = $this->createSupplier();
        $other = $this->createSupplier();
        $store = $this->container->get(CatalogImportSourceStore::class);
        $temporary = tempnam(sys_get_temp_dir(), 'catalog-upload-');
        $this->files[] = $temporary;
        file_put_contents($temporary, "sku\n001\n");
        $source = $store->upload($owner, new UploadedFile($temporary, 'fixture.csv', 'text/csv', 8), $this->userId);
        $this->files[] = $store->path($owner, $source['id']);
        $this->expectException(EshopException::class);
        $store->path($other, $source['id']);
    }
}
