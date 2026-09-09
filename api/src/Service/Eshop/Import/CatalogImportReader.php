<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class CatalogImportReader
{
    public const MAX_BYTES = 50_000_000;
    public const MAX_ROWS = 100_000;
    public const MAX_COLUMNS = 200;
    private const MAX_CELL_BYTES = 100_000;

    public function rows(string $path, string $format, array $options = [], int $afterRow = 0): \Generator
    {
        if ($afterRow < 0 || $afterRow > self::MAX_ROWS + 1 || !is_file($path) || !is_readable($path) || filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('import_file_invalid');
        }
        if ($format === 'csv') {
            yield from $this->csv($path, $options, $afterRow);
        } elseif ($format === 'xlsx') {
            yield from $this->xlsx($path, $options, $afterRow);
        } else {
            throw new \InvalidArgumentException('import_format_invalid');
        }
    }

    private function csv(string $path, array $options, int $afterRow): \Generator
    {
        $encoding = $options['encoding'] ?? 'UTF-8';
        $delimiter = $options['delimiter'] ?? ';';
        if (!in_array($encoding, ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true)
            || !in_array($delimiter, [';', ',', "\t", '|'], true)) {
            throw new \InvalidArgumentException('import_csv_options_invalid');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('import_file_unreadable');
        }
        try {
            $prefix = fread($stream, 3);
            if ($prefix !== "\xEF\xBB\xBF") {
                rewind($stream);
            } elseif ($encoding !== 'UTF-8') {
                throw new \InvalidArgumentException('import_encoding_invalid');
            }
            $line = 0;
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if (++$line > self::MAX_ROWS + 1) {
                    throw new \InvalidArgumentException('import_too_many_rows');
                }
                if ($line <= $afterRow) {
                    continue;
                }
                $values = array_map(static fn ($value): string => (string) ($value ?? ''), $values);
                if ($encoding !== 'UTF-8') {
                    $values = array_map(static function (string $value) use ($encoding): string {
                        $converted = iconv($encoding, 'UTF-8', $value);
                        if ($converted === false) {
                            throw new \InvalidArgumentException('import_encoding_invalid');
                        }
                        return $converted;
                    }, $values);
                }
                $this->validateCells($values);
                yield $line => $values;
            }
        } finally {
            fclose($stream);
        }
    }

    private function xlsx(string $path, array $options, int $afterRow): \Generator
    {
        $sheetIndex = $options['sheet'] ?? 0;
        if (!is_int($sheetIndex) || $sheetIndex < 0) {
            throw new \InvalidArgumentException('import_sheet_invalid');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('import_xlsx_invalid');
        }
        try {
            $bytes = 0;
            if ($zip->numFiles > 2000) {
                throw new \InvalidArgumentException('import_xlsx_too_large');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $bytes += $entry['size'] ?? 0;
                if ($bytes > 100_000_000 || ($entry['encryption_method'] ?? 0) !== 0) {
                    throw new \InvalidArgumentException('import_xlsx_too_large');
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Xlsx();
        $info = $reader->listWorksheetInfo($path);
        if (!isset($info[$sheetIndex])) {
            throw new \InvalidArgumentException('import_sheet_invalid');
        }
        $sheet = $info[$sheetIndex];
        if ($sheet['totalRows'] > self::MAX_ROWS + 1 || $sheet['totalColumns'] > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('import_sheet_too_large');
        }
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheet['worksheetName']);
        for ($start = $afterRow + 1; $start <= $sheet['totalRows']; $start += 100) {
            $end = min($start + 99, $sheet['totalRows']);
            $reader->setReadFilter(new class($start, $end) implements IReadFilter {
                public function __construct(private int $start, private int $end) {}
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->start && $row <= $this->end
                        && Coordinate::columnIndexFromString($columnAddress) <= CatalogImportReader::MAX_COLUMNS;
                }
            });
            $book = $reader->load($path);
            try {
                $worksheet = $book->getSheet(0);
                for ($row = $start; $row <= $end; $row++) {
                    $values = [];
                    for ($column = 1; $column <= $sheet['totalColumns']; $column++) {
                        $value = $worksheet->getCell([$column, $row])->getValue();
                        $values[] = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
                    }
                    $this->validateCells($values);
                    yield $row => $values;
                }
            } finally {
                $book->disconnectWorksheets();
                unset($book);
            }
        }
    }

    private function validateCells(array $values): void
    {
        if (count($values) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('import_too_many_columns');
        }
        $bytes = 0;
        foreach ($values as $value) {
            $bytes += strlen($value);
            if (strlen($value) > self::MAX_CELL_BYTES || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('import_cell_invalid');
            }
        }
        if ($bytes > 2_000_000) {
            throw new \InvalidArgumentException('import_row_too_large');
        }
    }
}
