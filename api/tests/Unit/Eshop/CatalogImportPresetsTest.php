<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Import\CatalogImportPresets;
use MyInvoice\Service\Eshop\Import\CatalogImportProfile;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use PHPUnit\Framework\TestCase;

final class CatalogImportPresetsTest extends TestCase
{
    public function testAbraPresetMapsTheVerifiedPublicDemoHeader(): void
    {
        $preset = $this->preset('abra-flexi-cenik-csv-v1');
        self::assertSame('csv', $preset['format']);
        self::assertSame('public_demo_header', $preset['schema_evidence']);
        self::assertFalse($preset['version_export_verified']);

        [$header, $row] = $this->readRows('abra-flexi-cenik.synthetic.csv', 'csv', $preset['config']['reader']);
        self::assertSame(
            [
                'ean' => '0859123400007',
                'export_eshop' => true,
                'is_stocked' => true,
                'name' => 'Syntetická testovací položka',
                'note' => 'Pouze syntetická data',
                'price' => '123.45',
                'sku' => '000123',
            ],
            CatalogImportProfile::map($preset['config'], $header, $row),
        );
    }

    public function testPohodaPresetMapsOnlyTheFourDocumentedColumns(): void
    {
        $preset = $this->preset('pohoda-zasoby-xlsx-v1');
        self::assertSame('xlsx', $preset['format']);
        self::assertSame('documented_ui_columns', $preset['schema_evidence']);
        self::assertFalse($preset['version_export_verified']);
        self::assertSame(['sku', 'name', 'unit', 'ean'], array_column($preset['supported_columns'], 'target'));

        [$header, $row] = $this->readRows('pohoda-zasoby.synthetic.xlsx', 'xlsx', $preset['config']['reader']);
        self::assertSame(
            ['ean' => '0859123400014', 'name' => 'Syntetická testovací zásoba', 'sku' => '000456', 'unit' => 'ks'],
            CatalogImportProfile::map($preset['config'], $header, $row),
        );
    }

    public function testPresetKeepsExactHeadersAndRejectsAChangedExport(): void
    {
        $preset = $this->preset('pohoda-zasoby-xlsx-v1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import_missing_header');
        CatalogImportProfile::map($preset['config'], ['Kod', 'Název', 'M. j.', 'Čár. kód'], ['A', 'Test', 'ks', '1']);
    }

    private function preset(string $id): array
    {
        foreach ((new CatalogImportPresets())->all() as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }
        self::fail('Missing preset '.$id);
    }

    private function readRows(string $fixture, string $format, array $options): array
    {
        $rows = array_values(iterator_to_array((new CatalogImportReader())->rows(
            dirname(__DIR__, 2).'/Fixtures/catalog-import/'.$fixture,
            $format,
            $options,
        )));
        self::assertCount(2, $rows);
        return $rows;
    }
}
