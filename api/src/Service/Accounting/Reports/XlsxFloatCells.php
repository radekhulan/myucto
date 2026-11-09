<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Úprava čísel v uloženém XLSX: PhpSpreadsheet zapisuje desetinnou část jako rozdíl
 * `x - floor(x)`, takže 16445.6 skončí v souboru jako `16445.59999999999854`. Hodnota
 * je táž (stejný double), ale prohlížeče a importy ji ukazují v tomhle tvaru.
 *
 * Normalizace převede každé takové číslo na nejkratší zápis téhož doublu (PHP
 * `serialize_precision = -1`), tedy `16445.6`. Číslo se nezaokrouhluje, jen se jinak
 * zapíše; celá čísla a krátké zlomky zůstanou bajtově stejné.
 */
final class XlsxFloatCells
{
    private const LONG_FRACTION = '#<v>(-?\d+\.\d{12,})</v>#';

    public static function normalize(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'xl/worksheets/') || !str_ends_with($name, '.xml')) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if (!is_string($xml)) {
                continue;
            }
            $fixed = self::normalizeXml($xml);
            if ($fixed !== $xml) {
                $zip->addFromString($name, $fixed);
            }
        }
        $zip->close();
    }

    public static function normalizeXml(string $xml): string
    {
        return (string) preg_replace_callback(self::LONG_FRACTION, static function (array $m): string {
            $short = (string) (float) $m[1];
            if (!preg_match('/^-?\d+(\.\d+)?$/', $short)) {
                return $m[0];
            }
            return '<v>' . (str_contains($short, '.') ? $short : $short . '.0') . '</v>';
        }, $xml);
    }
}
