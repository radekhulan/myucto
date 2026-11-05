<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use ZipArchive;

/**
 * Čte podklady docházky: VŠECHNY listy XLSX a CSV do řídké mřížky buněk.
 *
 * Vzorce se nepřepočítávají — bere se hodnota, kterou uložil docházkový
 * systém (`getOldCalculatedValue()`). Přepočet by nad sešitem s chybějícími
 * listy nebo odkazy dal jiná čísla, než viděla účetní.
 *
 * Kontrola archivu drží tatáž pravidla jako
 * {@see \MyInvoice\Service\Payroll\Component\PayrollInputTabularParser}
 * (zakázaná makra, externí odkazy, vložené objekty, zip bomba), jen s limity
 * pro sešity o více listech; tamní kontrola je soukromá metoda s limity pro
 * jednolistový import vstupů.
 */
final class AttendanceWorkbookReader
{
    public const MAX_SHEETS = 40;
    public const MAX_ROWS = 10_000;
    public const MAX_COLUMNS = 250;
    private const MAX_XLSX_PARTS = 400;
    private const MAX_XLSX_UNCOMPRESSED_BYTES = 40_000_000;
    private const CSV_SHEET_NAME = 'CSV';
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Vestavěné formáty Excelu, které se v `styles.xml` neukládají (ECMA-376 §18.8.30). */
    private const BUILTIN_FORMATS = [
        0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00',
        9 => '0%', 10 => '0.00%', 11 => '0.00E+00', 12 => '# ?/?', 13 => '# ??/??',
        14 => 'mm-dd-yy', 15 => 'd-mmm-yy', 16 => 'd-mmm', 17 => 'mmm-yy',
        18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss',
        22 => 'm/d/yy h:mm', 37 => '#,##0 ;(#,##0)', 38 => '#,##0 ;[Red](#,##0)',
        39 => '#,##0.00;(#,##0.00)', 40 => '#,##0.00;[Red](#,##0.00)',
        45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mmss.0', 48 => '##0.0E+0', 49 => '@',
    ];

    private const ERROR_CODES = [
        '#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A',
        '#GETTING_DATA', '#SPILL!', '#CALC!', '#FIELD!', '#BLOCKED!', '#UNKNOWN!',
    ];

    /** @return list<AttendanceSheet> */
    public function read(string $fileName, int $fileIndex, string $extension, string $content): array
    {
        return match ($extension) {
            'xlsx' => $this->readXlsx($fileName, $fileIndex, $content),
            // Denní export pro PAMICA se sečte po osobách, ostatní CSV zůstává beze změny.
            'csv' => (static fn (AttendanceSheet $csv): array => AttendancePamicaExport::fromDailyCsv($csv) ?? [$csv])(
                $this->readCsv($fileName, $fileIndex, $content),
            ),
            'xml' => AttendancePamicaExport::fromXml($fileName, $fileIndex, $content),
            default => throw new \InvalidArgumentException(
                "Soubor „{$fileName}“ má nepodporovaný typ. Nahrajte XLSX, CSV nebo XML exportu pro PAMICA.",
            ),
        };
    }

    /** @return list<AttendanceSheet> */
    private function readXlsx(string $fileName, int $fileIndex, string $content): array
    {
        if (!str_starts_with($content, "PK\x03\x04")) {
            throw new \InvalidArgumentException(
                "Soubor „{$fileName}“ není sešit XLSX. Uložte ho v Excelu jako .xlsx a nahrajte znovu.",
            );
        }
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-attendance-');
        if ($tmp === false) {
            throw new \RuntimeException('Pro XLSX nelze vytvořit dočasný soubor.');
        }
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException('XLSX nelze bezpečně uložit k načtení.');
            }
            $this->assertSafeArchive($tmp, $fileName);
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                throw new \InvalidArgumentException("Soubor „{$fileName}“ nejde otevřít jako sešit XLSX.");
            }
            try {
                return $this->sheets($zip, $fileName, $fileIndex);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Sešit se čte proudově přímo z XML listů, ne přes PhpSpreadsheet.
     *
     * Import potřebuje jen hodnoty buněk, uložené výsledky vzorců a formát
     * čísla (kvůli trvání). PhpSpreadsheet navíc skládá styly, obrázky
     * a kreslení; u reálných podkladů s obrázky na prázdných listech se tím
     * načtení sešitu pod 1 MB protáhlo na desítky minut. Proudové čtení
     * zvládne tytéž sešity ve zlomku sekundy a paměť roste jen s počtem
     * neprázdných buněk.
     *
     * @return list<AttendanceSheet>
     */
    private function sheets(ZipArchive $zip, string $fileName, int $fileIndex): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ neobsahuje sešit Excelu.");
        }
        $targets = $this->relationshipTargets($zip);
        $sheets = [];
        $document = $this->xml($workbook, $fileName);
        foreach ($document->getElementsByTagNameNS(self::NS_MAIN, 'sheet') as $sheet) {
            $relationId = $sheet->getAttributeNS(self::NS_RELATIONSHIPS, 'id');
            $target = $targets[$relationId] ?? null;
            if ($target === null) {
                continue;
            }
            $sheets[] = ['name' => $sheet->getAttribute('name'), 'path' => $target];
        }
        if ($sheets === []) {
            throw new \InvalidArgumentException("Sešit „{$fileName}“ neobsahuje žádný list.");
        }
        if (count($sheets) > self::MAX_SHEETS) {
            throw new \InvalidArgumentException(
                "Sešit „{$fileName}“ má víc než " . self::MAX_SHEETS . ' listů. Odeberte listy, které k importu nepatří.',
            );
        }
        $strings = $this->sharedStrings($zip, $fileName);
        $formats = $this->cellFormats($zip, $fileName);

        $result = [];
        foreach ($sheets as $sheetIndex => $sheet) {
            $xml = $zip->getFromName($sheet['path']);
            if ($xml === false) {
                continue;
            }
            $result[] = $this->sheet($xml, $fileName, $fileIndex, $sheetIndex, $sheet['name'], $strings, $formats);
        }

        return $result;
    }

    /**
     * @param list<string> $strings
     * @param array<int,string> $formats
     */
    private function sheet(
        string $xml,
        string $fileName,
        int $fileIndex,
        int $sheetIndex,
        string $name,
        array $strings,
        array $formats,
    ): AttendanceSheet {
        $reader = $this->reader($xml, $fileName);
        $rows = [];
        $maxRow = 0;
        $maxColumn = 0;
        $truncated = false;
        $currentRow = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->namespaceURI !== self::NS_MAIN) {
                    continue;
                }
                if ($reader->localName === 'row') {
                    $currentRow = (int) $reader->getAttribute('r') ?: $currentRow + 1;
                    continue;
                }
                if ($reader->localName !== 'c') {
                    continue;
                }
                [$row, $column] = $this->reference((string) $reader->getAttribute('r'), $currentRow);
                if ($row > self::MAX_ROWS + 1 || $column > self::MAX_COLUMNS) {
                    $truncated = true;
                    $reader->next();
                    continue;
                }
                $cell = $this->cell($reader, $strings, $formats);
                if ($cell->isEmpty()) {
                    continue;
                }
                $rows[$row][$column] = $cell;
                $maxRow = max($maxRow, $row);
                $maxColumn = max($maxColumn, $column);
            }
        } finally {
            $reader->close();
        }
        ksort($rows);
        foreach ($rows as &$cells) {
            ksort($cells);
        }
        unset($cells);
        $warnings = $truncated
            ? ["List „{$name}“ v souboru „{$fileName}“ je větší než " . self::MAX_ROWS . ' řádků nebo '
                . self::MAX_COLUMNS . ' sloupců; načetla se jen tato část.']
            : [];

        return new AttendanceSheet($fileName, $fileIndex, $sheetIndex, $name, $rows, $maxRow, $maxColumn, $warnings);
    }

    /**
     * Buňka `<c>`: typ `t`, styl `s`, vzorec `<f>`, hodnota `<v>` nebo `<is>`.
     * Po návratu stojí čtečka na konci elementu buňky.
     *
     * @param list<string> $strings
     * @param array<int,string> $formats
     */
    private function cell(\XMLReader $reader, array $strings, array $formats): AttendanceCell
    {
        $type = (string) ($reader->getAttribute('t') ?? 'n');
        $style = (int) ($reader->getAttribute('s') ?? 0);
        if ($reader->isEmptyElement) {
            return AttendanceCell::empty();
        }
        $formula = false;
        $value = null;
        $inline = null;
        $depth = $reader->depth;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'f') {
                $formula = true;
            } elseif ($reader->localName === 'v') {
                $value = $reader->readString();
            } elseif ($reader->localName === 'is') {
                $inline = $this->richText($reader);
            }
        }

        if ($type === 'inlineStr') {
            return $inline === null ? AttendanceCell::empty() : $this->text($inline, $formula);
        }
        if ($value === null || $value === '') {
            return $formula ? AttendanceCell::error(AttendanceCell::NO_CACHED_VALUE, true) : AttendanceCell::empty();
        }

        return match ($type) {
            's' => $this->text($strings[(int) $value] ?? '', $formula),
            'str' => $this->text($value, $formula),
            'b' => AttendanceCell::bool($value === '1', $formula),
            'e' => AttendanceCell::error(strtoupper(trim($value)), $formula),
            'd' => AttendanceCell::string($value, $formula),
            default => is_numeric($value)
                ? AttendanceCell::number((float) $value, $formats[$style] ?? 'General', $formula)
                : $this->text($value, $formula),
        };
    }

    private function text(string $value, bool $formula): AttendanceCell
    {
        if (in_array(strtoupper(trim($value)), self::ERROR_CODES, true)) {
            return AttendanceCell::error(strtoupper(trim($value)), $formula);
        }

        return AttendanceCell::string($value, $formula);
    }

    /** Text uvnitř `<si>` / `<is>` včetně formátovaných úseků, bez fonetických přepisů. */
    private function richText(\XMLReader $reader): string
    {
        $text = '';
        $depth = $reader->depth;
        $phonetic = 0;
        if ($reader->isEmptyElement) {
            return '';
        }
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->depth === $depth) {
                    break;
                }
                if ($reader->localName === 'rPh') {
                    --$phonetic;
                }
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'rPh' && !$reader->isEmptyElement) {
                ++$phonetic;
            } elseif ($reader->localName === 't' && $phonetic === 0) {
                $text .= $reader->readString();
            }
        }

        return $text;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip, string $fileName): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $reader = $this->reader($xml, $fileName);
        $strings = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    $strings[] = $this->richText($reader);
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Index stylu buňky → kód formátu čísla. Vestavěné formáty Excelu se
     * v `styles.xml` neukládají, proto mají vlastní tabulku (jen ty, které
     * rozhodují o trvání a datu).
     *
     * @return array<int,string>
     */
    private function cellFormats(ZipArchive $zip, string $fileName): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $document = $this->xml($xml, $fileName);
        $custom = [];
        foreach ($document->getElementsByTagNameNS(self::NS_MAIN, 'numFmt') as $format) {
            $custom[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }
        $formats = [];
        $cellXfs = $document->getElementsByTagNameNS(self::NS_MAIN, 'cellXfs')->item(0);
        if ($cellXfs === null) {
            return [];
        }
        $index = 0;
        foreach ($cellXfs->childNodes as $xf) {
            if (!$xf instanceof \DOMElement || $xf->localName !== 'xf') {
                continue;
            }
            $id = (int) $xf->getAttribute('numFmtId');
            $formats[$index++] = $custom[$id] ?? self::BUILTIN_FORMATS[$id] ?? 'General';
        }

        return $formats;
    }

    /** @return array<string,string> id vztahu → cesta listu v archivu */
    private function relationshipTargets(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($xml === false) {
            return [];
        }
        $document = $this->xml($xml, 'xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            $target = str_replace('\\', '/', $relationship->getAttribute('Target'));
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            if (str_contains($path, '../')) {
                continue;
            }
            $targets[$relationship->getAttribute('Id')] = $path;
        }

        return $targets;
    }

    /** @return array{0:int,1:int} řádek a sloupec od 1 */
    private function reference(string $reference, int $currentRow): array
    {
        if (preg_match('/^([A-Z]{1,3})([0-9]+)$/', strtoupper($reference), $parts) !== 1) {
            throw new \InvalidArgumentException('Sešit obsahuje buňku bez platné adresy. Uložte ho v Excelu znovu.');
        }
        $column = 0;
        foreach (str_split($parts[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [(int) $parts[2] ?: $currentRow, $column];
    }

    private function xml(string $xml, string $fileName): \DOMDocument
    {
        $this->assertNoDoctype($xml, $fileName);
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \InvalidArgumentException(
                "Soubor „{$fileName}“ se nepodařilo přečíst jako sešit Excelu. Otevřete ho v Excelu, uložte znovu a nahrajte.",
            );
        }

        return $document;
    }

    private function reader(string $xml, string $fileName): \XMLReader
    {
        $this->assertNoDoctype($xml, $fileName);
        $reader = \XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        if (!$reader instanceof \XMLReader) {
            throw new \InvalidArgumentException(
                "Soubor „{$fileName}“ se nepodařilo přečíst jako sešit Excelu. Otevřete ho v Excelu, uložte znovu a nahrajte.",
            );
        }

        return $reader;
    }

    private function assertNoDoctype(string $xml, string $fileName): void
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException(
                "Sešit „{$fileName}“ obsahuje nepovolené definice XML. Uložte ho v Excelu znovu jako .xlsx.",
            );
        }
    }

    private function readCsv(string $fileName, int $fileIndex, string $content): AttendanceSheet
    {
        if (str_contains($content, "\0")) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ obsahuje binární data, nejde o textové CSV.");
        }
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = AttendanceCp1250::toUtf8($content);
            if ($converted === null) {
                throw new \InvalidArgumentException(
                    "Soubor „{$fileName}“ není v kódování UTF-8 ani Windows-1250. Uložte ho znovu jako CSV UTF-8.",
                );
            }
            $content = $converted;
        }
        $firstLine = '';
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }
        if ($firstLine === '') {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ je prázdný.");
        }
        $delimiter = ';';
        $best = 0;
        foreach ([';', ',', "\t"] as $candidate) {
            $count = count(str_getcsv($firstLine, $candidate, '"', ''));
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV nelze otevřít v paměti.');
        }
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        $maxColumn = 0;
        $rowNumber = 0;
        try {
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                ++$rowNumber;
                if ($rowNumber > self::MAX_ROWS + 1) {
                    throw new \InvalidArgumentException(
                        "Soubor „{$fileName}“ má víc než " . self::MAX_ROWS . ' řádků. Rozdělte ho.',
                    );
                }
                foreach ($values as $index => $raw) {
                    $column = $index + 1;
                    if ($column > self::MAX_COLUMNS || $raw === null) {
                        continue;
                    }
                    $text = trim($raw);
                    if ($text === '') {
                        continue;
                    }
                    $upper = mb_strtoupper($text, 'UTF-8');
                    $rows[$rowNumber][$column] = match ($upper) {
                        'PRAVDA', 'TRUE' => AttendanceCell::bool(true),
                        'NEPRAVDA', 'FALSE' => AttendanceCell::bool(false),
                        default => in_array($upper, self::ERROR_CODES, true)
                            ? AttendanceCell::error($upper)
                            : AttendanceCell::string($text),
                    };
                    $maxColumn = max($maxColumn, $column);
                }
            }
        } finally {
            fclose($stream);
        }

        return new AttendanceSheet(
            $fileName,
            $fileIndex,
            0,
            self::CSV_SHEET_NAME,
            $rows,
            $rows === [] ? 0 : (int) max(array_keys($rows)),
            $maxColumn,
        );
    }

    private function assertSafeArchive(string $path, string $fileName): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ nejde otevřít jako sešit XLSX.");
        }
        try {
            if ($zip->numFiles > self::MAX_XLSX_PARTS) {
                throw new \InvalidArgumentException("Sešit „{$fileName}“ obsahuje příliš mnoho částí.");
            }
            $uncompressed = 0;
            $hasWorkbook = false;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new \InvalidArgumentException("Sešit „{$fileName}“ obsahuje nečitelnou část.");
                }
                $normalized = strtolower(str_replace('\\', '/', (string) $stat['name']));
                if (str_contains($normalized, '../')
                    || str_starts_with($normalized, '/')
                    || str_contains($normalized, 'vbaproject.bin')
                    || str_starts_with($normalized, 'xl/externallinks/')
                    || str_starts_with($normalized, 'xl/embeddings/')
                    || $normalized === 'xl/connections.xml') {
                    throw new \InvalidArgumentException(
                        "Sešit „{$fileName}“ obsahuje makra, externí odkazy nebo vložené objekty. "
                        . 'Uložte ho jako obyčejný sešit .xlsx bez maker a externích propojení.',
                    );
                }
                $hasWorkbook = $hasWorkbook || $normalized === 'xl/workbook.xml';
                $size = (int) $stat['size'];
                if ($size > self::MAX_XLSX_UNCOMPRESSED_BYTES - $uncompressed) {
                    throw new \InvalidArgumentException(
                        "Sešit „{$fileName}“ je po rozbalení příliš velký. Odeberte listy, které k importu nepatří.",
                    );
                }
                $uncompressed += $size;
            }
            if (!$hasWorkbook) {
                throw new \InvalidArgumentException("Soubor „{$fileName}“ neobsahuje sešit Excelu.");
            }
        } finally {
            $zip->close();
        }
    }
}
