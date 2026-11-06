<?php

declare(strict_types=1);

namespace MyInvoice\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sdílené ohraničené čtení nahraných sešitů (XLSX / XLS / ODS) pro importy.
 *
 * Ochrany před load(), který by jinak celý obsah materializoval do paměti:
 *   - formát se určuje z obsahu (magic bytes), ne z přípony ani autodetekcí readeru,
 *   - u ZIP formátů strop počtu položek a součtu NEkomprimovaných velikostí (zip-bomba),
 *     šifrované položky se odmítají,
 *   - u ODS předběžný průchod content.xml: řádek s daty opakovaný přes
 *     `table:number-rows-repeated` za strop řádků se odmítne (reader by buňky
 *     opakovaného řádku vytvořil bez ohledu na read filtr),
 *   - read filtr nepustí do paměti buňky za stropem řádků a sloupců,
 *   - matrix() prochází jen existující buňky, ne celý obdélník do nejvyšší
 *     deklarované souřadnice.
 *
 * Dočasný soubor se maže vždy, i při chybě.
 */
final class SafeSpreadsheetReader
{
    public const TYPE_XLSX = 'Xlsx';
    public const TYPE_XLS = 'Xls';
    public const TYPE_ODS = 'Ods';

    private const ODS_MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';
    private const ODS_TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const ODS_OFFICE_NS = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * @param int $maxRows     nejvyšší povolené číslo řádku s daty (včetně hlavičky)
     * @param int $maxColumns  nejvyšší povolený index sloupce s daty
     */
    public function __construct(
        private readonly int $maxRows,
        private readonly int $maxColumns,
        private readonly int $maxUncompressedBytes = 20_000_000,
        private readonly int $maxZipEntries = 2000,
    ) {}

    /**
     * @param list<string> $allowedTypes podmnožina TYPE_* konstant
     * @throws SpreadsheetLimitException
     */
    public function load(string $content, array $allowedTypes, bool $readDataOnly): Spreadsheet
    {
        $tmp = @tempnam(sys_get_temp_dir(), 'ssimp_');
        if ($tmp === false) {
            throw new SpreadsheetLimitException('unreadable', 'Soubor nelze zpracovat.');
        }
        try {
            if (@file_put_contents($tmp, $content) === false) {
                throw new SpreadsheetLimitException('unreadable', 'Soubor nelze zpracovat.');
            }
            $type = $this->detectType($tmp, $content);
            if ($type === null || !in_array($type, $allowedTypes, true)) {
                throw new SpreadsheetLimitException('unreadable', 'Soubor není podporovaný sešit.');
            }
            if ($type !== self::TYPE_XLS) {
                $this->assertZipWithinLimits($tmp, $type);
            }

            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly($readDataOnly);
            $reader->setReadEmptyCells(false);
            // Filtr pouští o řádek a sloupec víc než strop, aby matrix() překročení poznal.
            $reader->setReadFilter(new class ($this->maxRows + 1, $this->maxColumns + 1) implements IReadFilter {
                public function __construct(private readonly int $rows, private readonly int $columns) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->rows && Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
                }
            });
            try {
                return $reader->load($tmp);
            } catch (\Throwable $e) {
                throw new SpreadsheetLimitException('unreadable', 'Soubor nelze přečíst jako sešit.', $e);
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Hodnoty listu jako hustá matice řádků od A1 po poslední buňku s daty. Prochází
     * jen existující buňky; prázdná místa doplní ''.
     *
     * @param callable(Cell):string $convert
     * @return list<list<string>>
     * @throws SpreadsheetLimitException
     */
    public function matrix(Worksheet $sheet, callable $convert): array
    {
        $values = [];
        $lastRow = 0;
        $lastColumn = 0;
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            $raw = $cell->getValue();
            if ($raw === null || $raw === '') {
                continue;
            }
            [$column, $row] = Coordinate::indexesFromString($coordinate);
            if ($row > $this->maxRows) {
                throw new SpreadsheetLimitException('too_many_rows', 'Soubor má příliš mnoho řádků.');
            }
            if ($column > $this->maxColumns) {
                throw new SpreadsheetLimitException('too_many_columns', 'Soubor má příliš mnoho sloupců.');
            }
            $values[$row][$column] = $convert($cell);
            $lastRow = max($lastRow, $row);
            $lastColumn = max($lastColumn, $column);
        }

        $out = [];
        for ($r = 1; $r <= $lastRow; $r++) {
            $cells = [];
            for ($c = 1; $c <= $lastColumn; $c++) {
                $cells[] = $values[$r][$c] ?? '';
            }
            $out[] = $cells;
        }
        return $out;
    }

    /** Typ sešitu podle obsahu; null = nepodporovaný nebo nečitelný. */
    private function detectType(string $path, string $content): ?string
    {
        if (str_starts_with($content, self::OLE_MAGIC)) {
            return self::TYPE_XLS;
        }
        if (!str_starts_with($content, "PK\x03\x04")) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            if ($zip->locateName('mimetype') !== false
                && trim((string) $zip->getFromName('mimetype', 128)) === self::ODS_MIMETYPE) {
                return self::TYPE_ODS;
            }
            return $zip->locateName('[Content_Types].xml') !== false ? self::TYPE_XLSX : null;
        } finally {
            $zip->close();
        }
    }

    private function assertZipWithinLimits(string $path, string $type): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new SpreadsheetLimitException('unreadable', 'Soubor nelze přečíst jako sešit.');
        }
        try {
            if ($zip->numFiles > $this->maxZipEntries) {
                throw new SpreadsheetLimitException('too_large', 'Soubor je po rozbalení příliš velký.');
            }
            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || (int) ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new SpreadsheetLimitException('unreadable', 'Soubor nelze přečíst jako sešit.');
                }
                $uncompressed += (int) ($stat['size'] ?? 0);
                if ($uncompressed > $this->maxUncompressedBytes) {
                    throw new SpreadsheetLimitException('too_large', 'Soubor je po rozbalení příliš velký.');
                }
            }
            if ($type === self::TYPE_ODS) {
                $xml = $zip->getFromName('content.xml');
                if (!is_string($xml)) {
                    throw new SpreadsheetLimitException('unreadable', 'Soubor nelze přečíst jako sešit.');
                }
                $this->assertOdsRowRepeats($xml);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * ODS reader vytvoří buňky řádku s daty pro každé opakování z
     * `table:number-rows-repeated` a read filtr přitom kontroluje jen první řádek
     * skupiny. Řádek s daty, který by zasáhl za strop, proto odmítneme předem.
     */
    private function assertOdsRowRepeats(string $xml): void
    {
        $xr = new \XMLReader();
        if (!$xr->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new SpreadsheetLimitException('unreadable', 'Soubor nelze přečíst jako sešit.');
        }
        $rowIndex = 0;
        $repeat = 1;
        $hasData = false;
        $inRow = false;
        try {
            while (@$xr->read()) {
                if ($xr->namespaceURI !== self::ODS_TABLE_NS) {
                    continue;
                }
                $name = $xr->localName;
                if ($xr->nodeType === \XMLReader::ELEMENT) {
                    if ($name === 'table') {
                        $rowIndex = 0;
                    } elseif ($name === 'table-row') {
                        $repeat = max(1, (int) ($xr->getAttributeNs('number-rows-repeated', self::ODS_TABLE_NS) ?? 1));
                        $hasData = false;
                        $inRow = true;
                        if ($xr->isEmptyElement) {
                            $rowIndex += $repeat;
                            $inRow = false;
                        }
                    } elseif ($inRow && ($name === 'table-cell' || $name === 'covered-table-cell')
                        && ($xr->getAttributeNs('value-type', self::ODS_OFFICE_NS) !== null
                            || $xr->getAttributeNs('formula', self::ODS_TABLE_NS) !== null)) {
                        $hasData = true;
                    }
                } elseif ($xr->nodeType === \XMLReader::END_ELEMENT && $name === 'table-row' && $inRow) {
                    $rowIndex += $repeat;
                    $inRow = false;
                    if ($hasData && $rowIndex > $this->maxRows) {
                        throw new SpreadsheetLimitException('too_many_rows', 'Soubor má příliš mnoho řádků.');
                    }
                }
            }
        } finally {
            $xr->close();
        }
    }
}
