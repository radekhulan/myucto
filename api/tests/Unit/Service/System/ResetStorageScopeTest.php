<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Service\System\ResetStorageScope;
use PHPUnit\Framework\TestCase;

/**
 * `reset.php` dřív mazal celé `storage/documents`, `invoices` a `purchase-invoices`.
 * Na stroji, kde úložiště sdílí víc databází, tím smazal soubory všech instancí.
 */
final class ResetStorageScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reset-scope-' . bin2hex(random_bytes(4));
        foreach (['documents/sup-1/ab', 'documents/sup-10', 'documents/sup-7', 'invoices/sup-1/2026-09', 'purchase-invoices/supplier-1', 'purchase-invoices/sources', 'supplier-logos'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        file_put_contents($this->root . '/documents/sup-1/ab/a.pdf', 'x');
        file_put_contents($this->root . '/documents/sup-10/b.pdf', 'x');
        file_put_contents($this->root . '/invoices/sup-1/2026-09/f.pdf', 'x');
        file_put_contents($this->root . '/purchase-invoices/supplier-1/p.pdf', 'x');
        file_put_contents($this->root . '/supplier-logos/sup-1.png', 'x');
        file_put_contents($this->root . '/supplier-logos/sup-10.png', 'x');
    }

    protected function tearDown(): void
    {
        ResetStorageScope::remove($this->root);
    }

    public function testPlanTouchesOnlyOwnSuppliers(): void
    {
        $plan = ResetStorageScope::plan($this->root, [1, 10], false);

        self::assertEqualsCanonicalizing([
            $this->root . '/documents/sup-1',
            $this->root . '/documents/sup-10',
            $this->root . '/invoices/sup-1',
            $this->root . '/purchase-invoices/supplier-1',
        ], $plan['own']);
        self::assertSame([$this->root . '/documents/sup-7'], $plan['foreign']);
    }

    public function testLogosOnlyOnFullReset(): void
    {
        $plan = ResetStorageScope::plan($this->root, [1], true);

        self::assertContains($this->root . '/supplier-logos/sup-1.png', $plan['own']);
        self::assertContains($this->root . '/supplier-logos/sup-10.png', $plan['foreign']);
        self::assertNotContains($this->root . '/supplier-logos/sup-1.png', ResetStorageScope::plan($this->root, [1], false)['own']);
    }

    public function testRemoveDeletesOnlyTheGivenTenant(): void
    {
        self::assertSame(1, ResetStorageScope::remove($this->root . '/documents/sup-1'));

        self::assertDirectoryDoesNotExist($this->root . '/documents/sup-1');
        self::assertFileExists($this->root . '/documents/sup-10/b.pdf');
        self::assertDirectoryExists($this->root . '/documents/sup-7');
    }

    public function testSupplierIdParsing(): void
    {
        self::assertSame(12, ResetStorageScope::supplierIdOf('sup-12'));
        self::assertSame(12, ResetStorageScope::supplierIdOf('supplier-12'));
        self::assertSame(12, ResetStorageScope::supplierIdOf('sup-12.png'));
        self::assertSame(12, ResetStorageScope::supplierIdOf('sup-12-brand-3-0123456789ab.png'));
        self::assertNull(ResetStorageScope::supplierIdOf('sources'));
        self::assertNull(ResetStorageScope::supplierIdOf('sup-12-old'));
    }

    public function testPlanIncludesPurchaseInvoiceSources(): void
    {
        mkdir($this->root . '/purchase-invoices/sources/supplier-1/ab', 0777, true);

        self::assertContains($this->root . '/purchase-invoices/sources/supplier-1', ResetStorageScope::plan($this->root, [1], false)['own']);
    }

    /**
     * `delete-company.php --files` dřív hledal jen `sup-N` ve dvou úrovních a nechal na
     * disku PDF přijatých faktur (`supplier-N`), jejich zdroje (`sources/supplier-N`),
     * podpisové profily a loga brandingových profilů.
     */
    public function testSupplierPathsFindsAllFilesOfOneCompanyOnly(): void
    {
        foreach (['purchase-invoices/sources/supplier-1/ab', 'purchase-invoices/sources/supplier-12', 'signing/profiles/supplier-1', 'documents/sup-12', 'cache/x/y/sup-1'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        file_put_contents($this->root . '/supplier-logos/sup-1-brand-2-0123456789ab.png', 'x');
        file_put_contents($this->root . '/supplier-logos/sup-12.png', 'x');

        self::assertSame([
            $this->root . '/documents/sup-1',
            $this->root . '/invoices/sup-1',
            $this->root . '/purchase-invoices/sources/supplier-1',
            $this->root . '/purchase-invoices/supplier-1',
            $this->root . '/signing/profiles/supplier-1',
            $this->root . '/supplier-logos/sup-1-brand-2-0123456789ab.png',
            $this->root . '/supplier-logos/sup-1.png',
        ], ResetStorageScope::supplierPaths($this->root, 1));
    }

    public function testDeleteCompanyUsesTheScope(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/bin/delete-company.php');

        self::assertStringContainsString('ResetStorageScope::supplierPaths(', $source);
    }

    public function testResetScriptUsesTheScope(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/bin/reset.php');

        self::assertStringContainsString('ResetStorageScope::plan(', $source);
        self::assertDoesNotMatchRegularExpression(
            "/RuntimePaths::storage\\('(documents|invoices|purchase-invoices)'\\)/",
            $source,
            'reset.php nesmí mazat celou oblast úložiště, jen adresáře firem této databáze.',
        );
    }
}
