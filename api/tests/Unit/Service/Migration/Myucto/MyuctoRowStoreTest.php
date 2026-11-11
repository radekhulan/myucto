<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoRowStore;
use PHPUnit\Framework\TestCase;

final class MyuctoRowStoreTest extends TestCase
{
    public function testRowsKeepExportOrderAndForkDoesNotChangeOriginal(): void
    {
        $store = MyuctoRowStore::fromArrays(['supplier' => [71 => ['id' => 71]], 'clients' => [5 => ['id' => 5, 'name' => 'B'], 3 => ['id' => 3, 'name' => 'A']]]);
        $store->addTable('invoices');
        self::assertSame(['supplier', 'clients', 'invoices'], $store->tables());
        self::assertSame([5, 3], $store->ids('clients'));
        self::assertTrue($store->isEmpty('invoices'));

        $fork = $store->fork();
        $fork->put('clients', 5, ['id' => 5, 'name' => 'B2']);
        $fork->remove('clients', 3);
        $fork->dropTable('invoices');
        $fork->sortById('clients');

        self::assertSame(['id' => 5, 'name' => 'B2'], $fork->get('clients', 5));
        self::assertFalse($fork->has('clients', 3));
        self::assertSame(['id' => 5, 'name' => 'B'], $store->get('clients', 5));
        self::assertSame(['id' => 3, 'name' => 'A'], $store->get('clients', '3'));
        self::assertSame(['supplier', 'clients', 'invoices'], $store->tables());
        self::assertSame(['supplier' => 1, 'clients' => 2, 'invoices' => 0], $store->counts());

        $store->sortById('clients');
        self::assertSame([3, 5], $store->ids('clients'));
    }

    public function testRowsSeeIndexAsOfIterationStart(): void
    {
        $store = MyuctoRowStore::fromArrays(['clients' => [1 => ['id' => 1], 2 => ['id' => 2]]]);
        $seen = [];
        foreach ($store->rows('clients') as $id => $row) {
            $store->remove('clients', 2);
            $store->put('clients', 3, ['id' => 3]);
            $seen[] = $id;
        }
        self::assertSame([1, 2], $seen);
        self::assertSame([1, 3], $store->ids('clients'));
    }

    /**
     * Otisk dat se dřív počítal jako sha256(serialize(...)) nad poli. Proudový výpočet
     * musí dát bajtově stejný výsledek, jinak by opakovaná obnova firmy obnovené starší
     * verzí skončila hláškou „importován s jinými daty".
     */
    public function testStreamedDigestEqualsFormerSerializedDigest(): void
    {
        $tables = [
            'supplier' => [71 => ['id' => 71, 'company_name' => 'Syntetická firma „s.r.o."', 'rate' => 21.5, 'flag' => true, 'note' => null]],
            'clients' => [],
            'bank_statements' => [9 => ['id' => 9, 'file_content' => "\0\xff binary\n", 'amount' => '-1.50']],
            'journal_entry_line_dimensions' => [1 => ['line_id' => 4, 'dimension_type_id' => 2], 2 => ['line_id' => 5, 'dimension_type_id' => 2]],
        ];
        $content = "%PDF-synthetic\0\n";
        $asset = ['table' => 'purchase_invoices', 'id' => 81, 'column' => 'pdf_path', 'area' => 'purchase-invoices',
            'storage_path' => 'purchase-invoices/sup-71/original.pdf', 'content' => $content, 'sha256' => hash('sha256', $content)];
        $shared = ['dimension_types' => [3 => ['id' => 3, 'code' => 'GPRJ', 'supplier_id' => null, 'supplier_group_id' => 1]]];
        $stored = [['area' => 'documents', 'path' => 'ab/doc.pdf', 'sha256' => str_repeat('a', 64), 'content' => 'x']];

        $parts = [$tables, [$asset]];
        $parts['shared_dimensions'] = $shared;
        $parts[] = array_map(static fn (array $file): array => [$file['area'], $file['path'], $file['sha256']], $stored);
        $former = hash('sha256', serialize($parts));

        $file = tempnam(sys_get_temp_dir(), 'myucto-digest-');
        try {
            file_put_contents($file, $content);
            $streamedAsset = ['file' => $file, 'size' => strlen($content)] + $asset;
            unset($streamedAsset['content']);
            self::assertSame($former, MyuctoImporter::digest(MyuctoRowStore::fromArrays($tables), [$asset], $shared, $stored));
            self::assertSame($former, MyuctoImporter::digest(MyuctoRowStore::fromArrays($tables), [$streamedAsset], $shared, $stored));
        } finally {
            @unlink($file);
        }
        self::assertSame(hash('sha256', serialize([$tables, []])), MyuctoImporter::digest(MyuctoRowStore::fromArrays($tables), [], [], []));
    }
}
