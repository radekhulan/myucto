<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\TripCategoryRepository;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use MyInvoice\Support\LocaleNumber;
use MyInvoice\Support\Spreadsheet\SafeSpreadsheetReader;
use MyInvoice\Support\Spreadsheet\SpreadsheetLimitException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import knihy jízd z CSV / XLSX. Hlavičkový řádek mapuje sloupce (CZ i EN aliasy),
 * pořadí libovolné. Validace + dopočet ujetých km + match auta/kategorie, per-řádek report.
 *
 * Sloupce: datum, [cas], auto, km_zacatek, km_konec, [ujeto], ucel, odkud, kam, [kategorie]
 */
final class TripImportService
{
    /** normalizovaný alias hlavičky → kanonický klíč */
    private const HEADER_ALIASES = [
        'datum' => 'date', 'date' => 'date',
        'cas' => 'time', 'time' => 'time',
        'auto' => 'car', 'vozidlo' => 'car', 'spz' => 'car', 'rz' => 'car', 'car' => 'car', 'vehicle' => 'car',
        'km zacatek' => 'odo_start', 'km start' => 'odo_start', 'tachometr start' => 'odo_start',
        'stav km od' => 'odo_start', 'start' => 'odo_start', 'odometer start' => 'odo_start',
        'km konec' => 'odo_end', 'km end' => 'odo_end', 'tachometr konec' => 'odo_end',
        'stav km do' => 'odo_end', 'konec' => 'odo_end', 'odometer end' => 'odo_end',
        'ujeto' => 'distance', 'ujete km' => 'distance', 'km' => 'distance', 'vzdalenost' => 'distance', 'distance' => 'distance',
        'ucel' => 'purpose', 'duvod' => 'purpose', 'duvod cesty' => 'purpose', 'ucel cesty' => 'purpose',
        'purpose' => 'purpose', 'reason' => 'purpose',
        'odkud' => 'origin', 'from' => 'origin', 'origin' => 'origin', 'start misto' => 'origin',
        'kam' => 'destination', 'to' => 'destination', 'destination' => 'destination', 'cil' => 'destination',
        'kategorie' => 'category', 'kategorie cesty' => 'category', 'category' => 'category', 'typ' => 'category',
    ];

    /** Strop datových řádků a sloupců sešitu (XLSX/XLS/ODS); větší soubor je třeba rozdělit. */
    public const MAX_ROWS = 10000;
    public const MAX_COLUMNS = 40;
    private const MAX_UNCOMPRESSED_BYTES = 10_000_000;

    public function __construct(
        private readonly CarRepository $cars,
        private readonly TripCategoryRepository $categories,
        private readonly TripRepository $trips,
    ) {}

    /**
     * @param bool $dryRun  true = neuloží nic, jen vrátí náhled (status 'preview' s namapovanými daty).
     * @return array{ok:bool, dry_run:bool, created:int, failed:int, rows:list<array<string,mixed>>}
     */
    public function import(int $supplierId, ?int $userId, string $content, string $filename, bool $dryRun = false): array
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        try {
            $matrix = in_array($ext, ['xlsx', 'xls', 'ods'], true)
                ? $this->readSpreadsheet($content)
                : $this->readCsv($content);
        } catch (SpreadsheetLimitException $e) {
            return ['ok' => false, 'dry_run' => $dryRun, 'created' => 0, 'failed' => 0, 'rows' => [],
                    'error' => self::limitMessage($e)];
        }

        if ($matrix === []) {
            return ['ok' => false, 'dry_run' => $dryRun, 'created' => 0, 'failed' => 0, 'rows' => [], 'error' => 'Soubor je prázdný nebo nečitelný.'];
        }

        $header = array_shift($matrix);
        $map = $this->mapHeader($header);
        if (!isset($map['date'])) {
            return ['ok' => false, 'dry_run' => $dryRun, 'created' => 0, 'failed' => 0, 'rows' => [],
                    'error' => 'Chybí sloupec „datum". Hlavička musí obsahovat alespoň datum a auto.'];
        }

        $defaultCarId = $this->cars->defaultCarId($supplierId);
        $created = 0; $failed = 0; $rows = [];
        $newCategories = []; // labely kategorií, které (by) import založil

        foreach ($matrix as $i => $cols) {
            $line = $i + 2; // +1 hlavička, +1 (1-based)
            if ($this->isBlankRow($cols)) continue;
            try {
                $trip = $this->mapRow($supplierId, $cols, $map, $defaultCarId, $dryRun, $newCategories);
                if ($dryRun) {
                    $rows[] = ['line' => $line, 'status' => 'preview'] + $trip;
                } else {
                    $id = $this->trips->create($supplierId, $trip, $userId);
                    $rows[] = ['line' => $line, 'status' => 'created', 'trip_id' => $id];
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = ['line' => $line, 'status' => 'failed', 'reason' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'dry_run' => $dryRun, 'created' => $created, 'failed' => $failed,
                'new_categories' => array_values($newCategories), 'rows' => $rows];
    }

    /**
     * @param list<string> $cols
     * @param array<string, list<int>> $map
     * @param array<string,string> $newCategories  akumulátor labelů kategorií k založení (by ref)
     */
    private function mapRow(int $supplierId, array $cols, array $map, ?int $defaultCarId, bool $dryRun, array &$newCategories): array
    {
        // Sloupec se může v hlavičce opakovat (např. 2× „kam" = tam i zpět) → spojíme
        // neprázdné hodnoty zadaným oddělovačem; „-" je placeholder = prázdné.
        $get = function (string $key, string $glue = ' ') use ($cols, $map): string {
            $vals = [];
            foreach ($map[$key] ?? [] as $idx) {
                $v = trim((string) ($cols[$idx] ?? ''));
                if ($v === '' || $v === '-') continue;
                $vals[] = $v;
            }
            return implode($glue, $vals);
        };

        $date = $this->parseDate($get('date'));
        if ($date === null) {
            throw new \InvalidArgumentException('Neplatné datum: „' . $get('date') . '".');
        }

        // Auto — podle SPZ/názvu, jinak default auto (když je jen jedno / nastavené).
        $carRaw = $get('car');
        $carId = null;
        if ($carRaw !== '') {
            $car = $this->cars->findByRegistrationOrName($supplierId, $carRaw);
            if ($car === null) throw new \InvalidArgumentException('Auto „' . $carRaw . '" neexistuje v číselníku.');
            $carId = (int) $car['id'];
        } else {
            $carId = $defaultCarId;
        }
        if ($carId === null) {
            throw new \InvalidArgumentException('Není určeno auto a neexistuje výchozí auto.');
        }

        // Tachometr se ukládá v celých km; ujeté km se ale dopočtou z přesných stavů
        // („128,5" → „135,2" = 6,7 km), ne až ze zaokrouhlených.
        $odoStartKm = LocaleNumber::toFloat($get('odo_start'));
        $odoEndKm   = LocaleNumber::toFloat($get('odo_end'));
        $odoStart = $odoStartKm === null ? null : (int) round($odoStartKm);
        $odoEnd   = $odoEndKm === null ? null : (int) round($odoEndKm);
        $distance = LocaleNumber::toFloat($get('distance'));
        if ($distance === null || $distance <= 0.0) {
            if ($odoStartKm !== null && $odoEndKm !== null && $odoEndKm >= $odoStartKm) {
                $distance = round($odoEndKm - $odoStartKm, 1);
            } else {
                throw new \InvalidArgumentException('Chybí ujeté km i platný stav tachometru (od/do).');
            }
        }

        // Kategorie cest: match existující, jinak založ (create-if-missing). V dry-runu
        // jen zaznamenáme, co by se založilo (žádný zápis).
        $categoryId = null;
        $catRaw = $get('category');
        if ($catRaw !== '') {
            $cat = $this->categories->findByLabelOrCode($supplierId, $catRaw);
            if ($cat !== null) {
                $categoryId = (int) $cat['id'];
            } else {
                $newCategories[mb_strtolower($catRaw)] = $catRaw; // nová kategorie (v obou módech)
                if (!$dryRun) {
                    $categoryId = $this->categories->findOrCreate($supplierId, $catRaw);
                }
            }
        }

        return [
            'car_id'         => $carId,
            'trip_date'      => $date,
            'time_start'     => $this->parseTime($get('time')),
            'time_end'       => null,
            'odometer_start' => $odoStart,
            'odometer_end'   => $odoEnd,
            'distance_km'    => $distance,
            'category_id'    => $categoryId,
            'purpose'        => ($p = $get('purpose')) !== '' ? $p : null,
            'origin'         => ($o = $get('origin', ' → ')) !== '' ? $o : null,
            'destination'    => ($d = $get('destination', ' → ')) !== '' ? $d : null,
        ];
    }

    /**
     * Hlavička → kanonický klíč => seznam indexů sloupců (sloupec se může opakovat).
     *
     * @param list<string> $header
     * @return array<string, list<int>>
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $norm = FuelKeywords::normalize((string) $name);
            $norm = str_replace(['_', '-', '.'], ' ', $norm);
            $norm = (string) preg_replace('/\s+/', ' ', trim($norm));
            if (isset(self::HEADER_ALIASES[$norm])) {
                $map[self::HEADER_ALIASES[$norm]][] = $idx;
            }
        }
        return $map;
    }

    /** @return list<list<string>> */
    private function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content; // strip BOM
        // Detekce oddělovače z prvního řádku (CZ Excel = středník).
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
        return $rows;
    }

    /**
     * Sešit čte sdílený {@see SafeSpreadsheetReader}: formát z obsahu, strop rozbalené
     * velikosti a počtu řádků/sloupců, jen existující buňky, dočasný soubor se maže vždy.
     *
     * @return list<list<string>>
     */
    private function readSpreadsheet(string $content): array
    {
        $reader = new SafeSpreadsheetReader(self::MAX_ROWS + 1, self::MAX_COLUMNS, self::MAX_UNCOMPRESSED_BYTES);
        // Formát buněk je potřeba (rozpoznání datumu), proto ne readDataOnly.
        $spreadsheet = $reader->load($content, [
            SafeSpreadsheetReader::TYPE_XLSX, SafeSpreadsheetReader::TYPE_XLS, SafeSpreadsheetReader::TYPE_ODS,
        ], false);
        try {
            // Datumové/časové buňky čteme z Excel SERIÁLU (locale-proof) → ISO, ne ze
            // zobrazeného řetězce: ten je dle formátu sešitu (často US M/D/Y) a parser
            // by ho odmítl. Ostatní buňky bereme jako formátovanou hodnotu (čísla/text).
            return $reader->matrix($spreadsheet->getActiveSheet(), static function (Cell $cell): string {
                $value = $cell->getValue();
                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $dt = ExcelDate::excelToDateTimeObject((float) $value);
                    return $dt->format('H:i:s') !== '00:00:00'
                        ? $dt->format('Y-m-d H:i')   // datum+čas (nebo jen čas → datum 1899)
                        : $dt->format('Y-m-d');
                }
                if (is_int($value) || is_float($value)) {
                    // Čísla surově — formát sešitu („1 234,5") by závisel na locale.
                    return (string) $value;
                }
                return (string) ($cell->getFormattedValue() ?? '');
            });
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private static function limitMessage(SpreadsheetLimitException $e): string
    {
        return match ($e->reason) {
            'too_many_rows'    => 'Soubor má více než ' . self::MAX_ROWS . ' řádků. Rozdělte ho prosím na menší části.',
            'too_many_columns' => 'Soubor má více než ' . self::MAX_COLUMNS . ' sloupců.',
            'too_large'        => 'Soubor je po rozbalení příliš velký.',
            default            => 'Soubor nelze přečíst. Podporované formáty jsou CSV, XLSX, XLS a ODS.',
        };
    }

    /** @param list<string> $cols */
    private function isBlankRow(array $cols): bool
    {
        foreach ($cols as $c) {
            if (trim((string) $c) !== '') return false;
        }
        return true;
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})#', $s, $m)) {
            $y = (int) $m[3]; if ($y < 100) $y += 2000;
            return checkdate((int) $m[2], (int) $m[1], $y)
                ? sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]) : null;
        }
        return null;
    }

    private function parseTime(string $s): ?string
    {
        if (preg_match('/(\d{1,2}):(\d{2})/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return null;
    }
}
