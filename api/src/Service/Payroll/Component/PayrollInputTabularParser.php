<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

final class PayrollInputTabularParser
{
    private const MAX_BYTES = 5_000_000;
    private const MAX_ROWS = 10_000;
    private const MAX_COLUMNS = 24;
    private const MAX_XLSX_FILES = 200;
    private const MAX_XLSX_UNCOMPRESSED_BYTES = 25_000_000;
    private const DEFAULT_REQUIRED = [
        'employment_id',
        'employment_code',
        'component_code',
        'amount_minor',
        'external_id',
    ];

    /**
     * @param list<string>|null $required
     * @return array{
     *   rows:list<array<string,string|int>>,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>
     * }
     */
    public function parse(string $format, string $content, ?array $required = null): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Importní soubor překračuje bezpečný limit 5 MB.');
        }
        $required ??= self::DEFAULT_REQUIRED;
        if ($required === [] || count($required) !== count(array_unique($required))) {
            throw new \LogicException('Povinné sloupce importu musí být neprázdné a jedinečné.');
        }
        return match ($format) {
            'csv' => $this->parseCsv($content, $required),
            'xlsx' => $this->parseXlsx($content, $required),
            default => throw new \InvalidArgumentException('Formát musí být csv nebo xlsx.'),
        };
    }

    /**
     * @param list<string> $required
     * @return array{
     *   rows:list<array<string,string|int>>,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>
     * }
     */
    private function parseCsv(string $content, array $required): array
    {
        if (str_contains($content, "\0")) {
            throw new \InvalidArgumentException('CSV obsahuje nepovolené binární znaky.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
            if (!mb_check_encoding($converted, 'UTF-8')) {
                throw new \InvalidArgumentException('CSV není v podporovaném textovém kódování.');
            }
            $content = $converted;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV nelze otevřít v bezpečné paměti.');
        }
        fwrite($stream, $content);
        rewind($stream);
        $first = fgets($stream);
        if ($first === false) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV je prázdné.');
        }
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($stream);
        $rawHeader = fgetcsv($stream, 0, $delimiter, escape: '');
        if ($rawHeader === false) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV nemá platnou hlavičku.');
        }
        $header = array_map(
            static fn (?string $value): string => trim($value ?? ''),
            $rawHeader,
        );
        $this->assertHeader($header, $required);

        $rows = [];
        $errors = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, $delimiter, escape: '')) !== false) {
            ++$line;
            if ($values === [null] || $values === ['']) {
                continue;
            }
            if ($line > self::MAX_ROWS + 1) {
                fclose($stream);
                throw new \InvalidArgumentException('Import smí obsahovat nejvýše 10000 řádků.');
            }
            if (count($values) !== count($header)) {
                $errors[] = $this->error(
                    $line,
                    'column_count',
                    null,
                    'Počet sloupců neodpovídá hlavičce.',
                );
                continue;
            }
            $combined = array_combine($header, $values);
            $row = ['row_number' => $line];
            foreach ($combined as $key => $value) {
                $row[$key] = trim($value ?? '');
            }
            $rows[] = $row;
        }
        fclose($stream);
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<string> $required
     * @return array{
     *   rows:list<array<string,string|int>>,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>
     * }
     */
    private function parseXlsx(string $content, array $required): array
    {
        if (!str_starts_with($content, "PK\x03\x04")) {
            throw new \InvalidArgumentException('XLSX nemá platnou signaturu OOXML archivu.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-input-');
        if ($tmp === false) {
            throw new \RuntimeException('Pro XLSX nelze vytvořit dočasný soubor.');
        }
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException('XLSX nelze bezpečně uložit k načtení.');
            }
            $this->assertSafeXlsxArchive($tmp);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $sheetNames = $reader->listWorksheetNames($tmp);
            if ($sheetNames === []) {
                throw new \InvalidArgumentException('XLSX neobsahuje žádný list.');
            }
            $reader->setLoadSheetsOnly([$sheetNames[0]]);
            $spreadsheet = $reader->load($tmp);
            try {
                $sheet = $spreadsheet->getSheet(0);
                $highestRow = $sheet->getHighestDataRow();
                $highestColumn = $sheet->getHighestDataColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                    $highestColumn,
                );
                if ($highestRow < 1) {
                    throw new \InvalidArgumentException('XLSX je prázdné.');
                }
                if ($highestRow > self::MAX_ROWS + 1) {
                    throw new \InvalidArgumentException(
                        'Import smí obsahovat nejvýše 10000 řádků.'
                    );
                }
                if ($highestColumnIndex > self::MAX_COLUMNS) {
                    throw new \InvalidArgumentException(
                        'Import smí obsahovat nejvýše 24 sloupců.'
                    );
                }
                $header = [];
                for ($column = 1; $column <= $highestColumnIndex; ++$column) {
                    $header[] = trim($this->cellValue($sheet->getCell([$column, 1])));
                }
                $this->assertHeader($header, $required);

                $rows = [];
                for ($rowNumber = 2; $rowNumber <= $highestRow; ++$rowNumber) {
                    $values = [];
                    $hasValue = false;
                    for ($column = 1; $column <= $highestColumnIndex; ++$column) {
                        $value = trim($this->cellValue(
                            $sheet->getCell([$column, $rowNumber]),
                        ));
                        $values[] = $value;
                        $hasValue = $hasValue || $value !== '';
                    }
                    if (!$hasValue) {
                        continue;
                    }
                    $combined = array_combine($header, $values);
                    $parsed = ['row_number' => $rowNumber];
                    foreach ($combined as $key => $value) {
                        $parsed[$key] = $value;
                    }
                    $rows[] = $parsed;
                }
                return ['rows' => $rows, 'errors' => []];
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function assertSafeXlsxArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException('XLSX nelze otevřít jako OOXML archiv.');
        }
        try {
            if ($zip->numFiles > self::MAX_XLSX_FILES) {
                throw new \InvalidArgumentException('XLSX obsahuje příliš mnoho částí.');
            }
            $uncompressed = 0;
            $hasWorkbook = false;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new \InvalidArgumentException('XLSX obsahuje nečitelnou část.');
                }
                $name = $stat['name'];
                $size = $stat['size'];
                $normalized = strtolower(str_replace('\\', '/', $name));
                if (str_contains($normalized, '../')
                    || str_starts_with($normalized, '/')
                    || str_contains($normalized, 'vbaproject.bin')
                    || str_starts_with($normalized, 'xl/externallinks/')
                    || str_starts_with($normalized, 'xl/embeddings/')
                    || $normalized === 'xl/connections.xml') {
                    throw new \InvalidArgumentException(
                        'XLSX obsahuje zakázané aktivní nebo externí prvky.'
                    );
                }
                $hasWorkbook = $hasWorkbook || $normalized === 'xl/workbook.xml';
                if ($size > self::MAX_XLSX_UNCOMPRESSED_BYTES - $uncompressed) {
                    throw new \InvalidArgumentException(
                        'Rozbalený XLSX překračuje bezpečný limit 25 MB.'
                    );
                }
                $uncompressed += $size;
            }
            if (!$hasWorkbook) {
                throw new \InvalidArgumentException('XLSX neobsahuje sešit.');
            }
        } finally {
            $zip->close();
        }
    }

    /** @param list<string> $header
     *  @param list<string> $required
     */
    private function assertHeader(array $header, array $required): void
    {
        if (count($header) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('Import smí obsahovat nejvýše 24 sloupců.');
        }
        if (count($header) !== count(array_unique($header))) {
            throw new \InvalidArgumentException('Hlavička obsahuje duplicitní názvy sloupců.');
        }
        foreach ($required as $requiredColumn) {
            if (!in_array($requiredColumn, $header, true)) {
                throw new \InvalidArgumentException(
                    "Hlavička neobsahuje povinný sloupec {$requiredColumn}."
                );
            }
        }
    }

    private function cellValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            throw new \InvalidArgumentException(
                'XLSX obsahuje vzorec; import přijímá jen statické hodnoty.'
            );
        }
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        throw new \InvalidArgumentException(
            'XLSX obsahuje nepodporovanou číselnou hodnotu; částky zadejte jako celé haléře.'
        );
    }

    /**
     * @return array{row_number:int,error_code:string,field_name:?string,error_message:string}
     */
    private function error(int $row, string $code, ?string $field, string $message): array
    {
        return [
            'row_number' => $row,
            'error_code' => $code,
            'field_name' => $field,
            'error_message' => $message,
        ];
    }
}
