<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

/**
 * Sdílený helper pro CSV exporty účetnictví (UTF-8 BOM, `;` oddělovač).
 *
 * BOM je kvůli Excelu (bez něj rozbije diakritiku), `;` oddělovač je český
 * standard (Excel v CZ locale očekává středník).
 *
 * `safe()` je OWASP CSV-injection guard: buňkám začínajícím na `=`, `+`, `-`,
 * `@`, TAB nebo CR prefixuje `'`, jinak je Excel po double-clicku interpretuje
 * jako vzorec (`=cmd|'/c calc'!A1` apod.). Volá se jen na TEXTOVÉ buňky —
 * číselné (number_format) a datumové hodnoty se propouští beze změny.
 */
final class CsvWriter
{
    public static function safe(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && str_contains("=+-@\t\r\n", $s[0]) ? "'" . $s : $s;
    }

    /**
     * @param string[]                                        $header
     * @param iterable<array<int,string|int|float|null>>      $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
        fputcsv($fp, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';', '"', '');
        }
        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }
}
