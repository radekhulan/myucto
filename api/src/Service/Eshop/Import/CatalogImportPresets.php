<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

final class CatalogImportPresets
{
    public function all(): array
    {
        return [
            [
                'id' => 'abra-flexi-cenik-csv-v1',
                'system' => 'abra_flexi',
                'version' => 1,
                'format' => 'csv',
                'documentation_url' => 'https://www.flexibee.eu/napojeni-na-internetovy-obchod/',
                'schema_evidence' => 'public_demo_header',
                'version_export_verified' => false,
                'supported_columns' => [
                    ['source' => 'kod', 'target' => 'sku'],
                    ['source' => 'nazev', 'target' => 'name'],
                    ['source' => 'eanKod', 'target' => 'ean'],
                    ['source' => 'cenaZaklBezDph', 'target' => 'price'],
                    ['source' => 'skladove', 'target' => 'is_stocked'],
                    ['source' => 'exportNaEshop', 'target' => 'export_eshop'],
                    ['source' => 'popis', 'target' => 'note'],
                ],
                'config' => CatalogImportProfile::normalize([
                    'identity' => 'sku',
                    'mode' => 'upsert',
                    'blank' => 'preserve',
                    'mapping' => [
                        'sku' => 'kod',
                        'name' => 'nazev',
                        'ean' => 'eanKod',
                        'price' => 'cenaZaklBezDph',
                        'is_stocked' => 'skladove',
                        'export_eshop' => 'exportNaEshop',
                        'note' => 'popis',
                    ],
                    'reader' => ['encoding' => 'UTF-8', 'delimiter' => ';', 'sheet' => 0],
                ]),
            ],
            [
                'id' => 'pohoda-zasoby-xlsx-v1',
                'system' => 'pohoda',
                'version' => 1,
                'format' => 'xlsx',
                'documentation_url' => 'https://www.stormware.cz/podpora/faq/pohoda/198/Jak-mohu-vyexportovat-udaje-z-tabulky-do-excelu-nebo-jako-textovy-soubor/?id=3257&p=4',
                'schema_evidence' => 'documented_ui_columns',
                'version_export_verified' => false,
                'supported_columns' => [
                    ['source' => 'Kód', 'target' => 'sku'],
                    ['source' => 'Název', 'target' => 'name'],
                    ['source' => 'M. j.', 'target' => 'unit'],
                    ['source' => 'Čár. kód', 'target' => 'ean'],
                ],
                'config' => CatalogImportProfile::normalize([
                    'identity' => 'sku',
                    'mode' => 'upsert',
                    'blank' => 'preserve',
                    'mapping' => [
                        'sku' => 'Kód',
                        'name' => 'Název',
                        'unit' => 'M. j.',
                        'ean' => 'Čár. kód',
                    ],
                    'reader' => ['encoding' => 'UTF-8', 'delimiter' => ';', 'sheet' => 0],
                ]),
            ],
        ];
    }
}
