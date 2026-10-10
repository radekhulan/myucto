<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

use MyInvoice\Support\LocaleNumber;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Společná báze pro import číselníků z XLSX/CSV (Epic F5 §4.4).
 *
 * Sdílí čtení souboru (XLSX explicitně přes Xlsx reader — žádná autodetekce,
 * setReadDataOnly(true), XmlScanner v defaultu ZAPNUTÝ), mapování hlaviček přes
 * aliasy (CZ kanonické + EN), a parsery (datum / desetinné / bool / enum).
 *
 * Formula/CSV injection: buňky se čtou přes getValue() (raw) — hodnota začínající
 * '=' zůstává textem, NIKDY getCalculatedValue() (§4.6).
 *
 * All-or-nothing: report se počítá vždy (dry-run i ostrý běh identicky); zápis jen
 * když je ostrý běh bez chyb, v jedné transakci (ownTx pattern — v testech běží pod
 * transakcí volajícího).
 */
abstract class AbstractCodebookImportService
{
    protected const MAX_ROWS = 2000;
    protected const MAX_COLUMNS = 200;
    protected const MAX_UNCOMPRESSED_BYTES = 20_000_000;

    /**
     * @return array{ok:bool, dry_run:bool, created:int, updated:int, skipped:int, failed:int,
     *                rows:list<array{line:int, key:string, status:'create'|'update'|'skip'|'error',
     *                                changes?:array<string,array{from:mixed,to:mixed}>, message?:string}>}
     */
    public function import(int $supplierId, ?int $userId, string $content, string $filename, bool $dryRun): array
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $matrix = $ext === 'csv' ? $this->readCsv($content) : $this->readSpreadsheet($content);

        if ($matrix === []) {
            return $this->fileError('Soubor je prázdný nebo nečitelný.');
        }

        $header = array_shift($matrix);
        $map = self::mapHeader($header, static::aliasMapForColumns(static::columns()));

        foreach ($this->requiredHeaderKeys() as $req) {
            if (!isset($map[$req])) {
                return $this->fileError('Chybí povinný sloupec „' . $this->headerLabel($req) . '".');
            }
        }

        // Prázdné datové řádky vynecháme úplně (nezapočítávají se do reportu).
        $rows = [];
        foreach ($matrix as $i => $cols) {
            if ($this->isBlankRow($cols)) {
                continue;
            }
            $rows[$i + 2] = $cols; // +1 hlavička, +1 (1-based)
        }

        return $this->process($supplierId, $map, $rows, $dryRun);
    }

    // ── template method ────────────────────────────────────────────────────────

    /**
     * Zpracuje datové řádky do reportu; ostrý běh bez chyb zapíše v transakci.
     *
     * @param array<string,int> $map          field_key => index sloupce
     * @param array<int,list<string>> $rows    line (1-based) => sloupce
     */
    abstract protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array;

    /**
     * Definice sloupců: field_key => {header, aliases[], required, note}.
     *
     * @return array<string,array{header:string, aliases:list<string>, required:string, note:string}>
     */
    abstract public static function columns(): array;

    /** field_keys, bez kterých nelze soubor zpracovat (identita řádku). @return list<string> */
    abstract protected function requiredHeaderKeys(): array;

    // ── shromáždění výsledku (ownTx zápis) ──────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows report řádky (status create/update/skip/error)
     * @param list<callable():void> $writers zapisovací closury (jen create/update), v pořadí
     * @param \PDO $pdo
     */
    protected function summarize(bool $dryRun, array $rows, array $writers, \PDO $pdo): array
    {
        $created = $updated = $skipped = $failed = 0;
        foreach ($rows as $r) {
            match ($r['status']) {
                'create' => $created++,
                'update' => $updated++,
                'skip'   => $skipped++,
                'error'  => $failed++,
                default  => null,
            };
        }

        if (!$dryRun && $failed === 0 && $writers !== []) {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                foreach ($writers as $w) {
                    $w();
                }
                if ($ownTx) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return [
            'ok'      => $failed === 0,
            'dry_run' => $dryRun,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'rows'    => array_values($rows),
        ];
    }

    /** @return array{ok:false, dry_run:bool, created:int, updated:int, skipped:int, failed:int, rows:list<array<string,mixed>>} */
    private function fileError(string $message): array
    {
        return [
            'ok'      => false,
            'dry_run' => true,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 1,
            'rows'    => [['line' => 1, 'key' => '', 'status' => 'error', 'message' => $message]],
        ];
    }

    private function headerLabel(string $fieldKey): string
    {
        return (string) (static::columns()[$fieldKey]['header'] ?? $fieldKey);
    }

    // ── čtení souboru ────────────────────────────────────────────────────────────

    /**
     * XLSX explicitně přes Xlsx reader (žádná autodetekce), setReadDataOnly(true),
     * XmlScanner (XXE) v defaultu ZAPNUTÝ. Kontrola rozměru listu proti zip-bombě.
     *
     * @return list<list<string>>
     */
    protected function readSpreadsheet(string $content): array
    {
        $base = tempnam(sys_get_temp_dir(), 'cbimp_');
        $tmp = $base . '.xlsx';
        file_put_contents($tmp, $content);
        try {
            // Zip-bomba: součet NEkomprimovaných velikostí položek ověřit PŘED load(),
            // který by jinak celý obsah materializoval do paměti.
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new CodebookImportException('bad_file', 'Soubor nelze přečíst jako XLSX.', 415);
            }
            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $uncompressed += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $zip->close();
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new CodebookImportException('too_many_rows', 'Soubor je po rozbalení příliš velký.', 422);
            }

            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            try {
                $spreadsheet = $reader->load($tmp);
            } catch (\Throwable $e) {
                throw new CodebookImportException('bad_file', 'Soubor nelze přečíst jako XLSX.', 415);
            }
            $sheet = $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getSheet(0) : $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestDataRow();
            $highestColLetter = $sheet->getHighestDataColumn();
            $highestCol = Coordinate::columnIndexFromString($highestColLetter);
            if ($highestCol > self::MAX_COLUMNS) {
                throw new CodebookImportException('bad_file', 'Soubor má příliš mnoho sloupců.', 422);
            }
            if ($highestRow - 1 > self::MAX_ROWS) {
                throw new CodebookImportException('too_many_rows', 'Soubor má více než ' . self::MAX_ROWS . ' řádků.', 422);
            }

            $out = [];
            for ($r = 1; $r <= $highestRow; $r++) {
                $cells = [];
                for ($c = 1; $c <= $highestCol; $c++) {
                    // getValue() = raw (formule zůstane textem "=…"); NIKDY getCalculatedValue().
                    $v = $sheet->getCell([$c, $r])->getValue();
                    $cells[] = $this->cellToString($v);
                }
                $out[] = $cells;
            }
            $spreadsheet->disconnectWorksheets();
            return $out;
        } finally {
            @unlink($tmp);
            @unlink($base);
        }
    }

    /** zkopírováno z TripImportService, nerefaktorovat kvůli upstream */
    protected function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content; // strip BOM
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(fn ($v) => (string) ($v ?? ''), $row);
        }
        fclose($stream);
        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new CodebookImportException('too_many_rows', 'Soubor má více než ' . self::MAX_ROWS . ' řádků.', 422);
        }
        return $rows;
    }

    private function cellToString(mixed $v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return (string) $v;
    }

    /** @param list<string> $cols */
    protected function isBlankRow(array $cols): bool
    {
        foreach ($cols as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }

    // ── mapování hlaviček + parsery (kopie vzorů TripImportService) ───────────────

    /**
     * Odstraní diakritiku, lowercase, sjednotí oddělovače (_ - .) a whitespace.
     * zkopírováno z TripImportService/FuelKeywords, nerefaktorovat kvůli upstream.
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = [
            'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r',
            'š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','ä'=>'a','ö'=>'o','ü'=>'u',
            'ô'=>'o','ľ'=>'l','ĺ'=>'l','ŕ'=>'r',
        ];
        $s = strtr($s, $map);
        $s = str_replace(['_', '-', '.'], ' ', $s);
        return (string) preg_replace('/\s+/', ' ', trim($s));
    }

    /**
     * Normalizovaný alias => field_key z definice sloupců.
     *
     * @param array<string,array{header:string, aliases:list<string>, required:string, note:string}> $columns
     * @return array<string,string>
     */
    public static function aliasMapForColumns(array $columns): array
    {
        $out = [];
        foreach ($columns as $field => $def) {
            $out[self::normalize((string) $def['header'])] = $field;
            foreach ($def['aliases'] as $alias) {
                $out[self::normalize((string) $alias)] = $field;
            }
        }
        return $out;
    }

    /**
     * Hlavička → field_key => index sloupce (první výskyt vyhrává).
     *
     * @param list<string> $header
     * @param array<string,string> $aliasMap
     * @return array<string,int>
     */
    public static function mapHeader(array $header, array $aliasMap): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $norm = self::normalize((string) $name);
            if ($norm === '' || !isset($aliasMap[$norm])) {
                continue;
            }
            $field = $aliasMap[$norm];
            if (!isset($map[$field])) {
                $map[$field] = $idx;
            }
        }
        return $map;
    }

    /** Hodnota sloupce field_key z řádku (trim). @param list<string> $cols @param array<string,int> $map */
    protected function col(array $cols, array $map, string $field): string
    {
        if (!isset($map[$field])) {
            return '';
        }
        return trim((string) ($cols[$map[$field]] ?? ''));
    }

    /** Desetinné číslo CZ (12 345,67) i EN (12,345.67), viz LocaleNumber. */
    public static function parseDecimal(string $s): ?float
    {
        return LocaleNumber::toFloat($s);
    }

    /** 1/0, ano/ne, yes/no, true/false → bool; jinak null. */
    public static function parseBool(string $s): ?bool
    {
        $n = self::normalize($s);
        if ($n === '') {
            return null;
        }
        if (in_array($n, ['1', 'ano', 'yes', 'true', 'y', 'a'], true)) {
            return true;
        }
        if (in_array($n, ['0', 'ne', 'no', 'false', 'n'], true)) {
            return false;
        }
        return null;
    }

    /** d.m.Y, Y-m-d, nebo Excel serial → Y-m-d; jinak null. */
    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})#', $s, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += 2000;
            }
            return checkdate((int) $m[2], (int) $m[1], $y)
                ? sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]) : null;
        }
        // Excel serial (raw numeric buňka bez number-formatu při readDataOnly).
        if (is_numeric($s)) {
            $serial = (float) $s;
            if ($serial >= 1 && $serial <= 2958465) {
                try {
                    return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Hodnota → kanonický enum přes alias mapu (normalizovaný vstup → kanonická EN
     * hodnota). Prázdné vrací $default. Neznámé vrací null (caller řeší error).
     *
     * @param array<string,string> $aliasMap normalizovaný alias => kanonická hodnota
     */
    public static function parseEnum(string $value, array $aliasMap, ?string $default = null): ?string
    {
        $n = self::normalize($value);
        if ($n === '') {
            return $default;
        }
        return $aliasMap[$n] ?? null;
    }
}
