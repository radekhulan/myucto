<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Hromadný import tankování z CSV / XLSX (vzor {@see TripImportService}).
 *
 * Hlavička mapuje sloupce (CZ i EN aliasy), pořadí je libovolné. Povinné je datum
 * a částka (nebo množství × cena za jednotku).
 *
 * Idempotence: každý řádek dostane otisk `dedup_hash` z data, času, SPZ, částky, množství
 * a čísla účtenky. Opakovaný import téhož souboru nic nezdvojí — shodný řádek se jen
 * doplní o dříve chybějící litry / cenu / tachometr / vozidlo (FuelingRepository::insertScanned),
 * nikdy se nepřepíše. Náhled (dry-run) nic nezapisuje a duplicity hlásí předem.
 */
final class FuelingImportService
{
    /** normalizovaný alias hlavičky → kanonický klíč */
    private const HEADER_ALIASES = [
        'datum' => 'date', 'date' => 'date', 'datum tankovani' => 'date',
        'cas' => 'time', 'time' => 'time',
        'auto' => 'car', 'vozidlo' => 'car', 'spz' => 'car', 'rz' => 'car', 'car' => 'car', 'vehicle' => 'car',
        'registracni znacka' => 'car', 'license plate' => 'car', 'plate' => 'car',
        'palivo' => 'fuel_type', 'druh paliva' => 'fuel_type', 'produkt' => 'fuel_type', 'zbozi' => 'fuel_type',
        'fuel' => 'fuel_type', 'fuel type' => 'fuel_type', 'product' => 'fuel_type',
        'mnozstvi' => 'quantity', 'litry' => 'quantity', 'litru' => 'quantity', 'objem' => 'quantity',
        'l' => 'quantity', 'kwh' => 'quantity', 'quantity' => 'quantity', 'liters' => 'quantity', 'litres' => 'quantity',
        'jednotka' => 'unit', 'mj' => 'unit', 'unit' => 'unit',
        'cena za litr' => 'unit_price', 'cena za jednotku' => 'unit_price', 'jednotkova cena' => 'unit_price',
        'cena l' => 'unit_price', 'kc l' => 'unit_price', 'unit price' => 'unit_price', 'price per liter' => 'unit_price',
        'castka' => 'amount', 'celkem' => 'amount', 'castka s dph' => 'amount', 'cena celkem' => 'amount',
        'celkem s dph' => 'amount', 'amount' => 'amount', 'total' => 'amount', 'amount with vat' => 'amount',
        'bez dph' => 'amount_without_vat', 'zaklad' => 'amount_without_vat', 'castka bez dph' => 'amount_without_vat',
        'amount without vat' => 'amount_without_vat', 'net' => 'amount_without_vat',
        'dph' => 'amount_vat', 'vat' => 'amount_vat',
        'mena' => 'currency', 'currency' => 'currency',
        'tachometr' => 'odometer', 'stav tachometru' => 'odometer', 'stav km' => 'odometer', 'km' => 'odometer',
        'odometer' => 'odometer', 'mileage' => 'odometer',
        'stanice' => 'station', 'misto' => 'station', 'cerpaci stanice' => 'station', 'station' => 'station',
        'location' => 'station',
        'cislo uctenky' => 'receipt', 'uctenka' => 'receipt', 'doklad' => 'receipt', 'cislo dokladu' => 'receipt',
        'receipt' => 'receipt', 'receipt number' => 'receipt',
        'karta' => 'card', 'card' => 'card',
        'poznamka' => 'note', 'note' => 'note',
    ];

    public function __construct(
        private readonly CarRepository $cars,
        private readonly FuelingRepository $fuelings,
        private readonly VehicleResolver $vehicles,
    ) {}

    /**
     * @return array{ok:bool, dry_run:bool, created:int, updated:int, duplicates:int, failed:int,
     *               rows:list<array<string,mixed>>, error?:string}
     */
    public function import(int $supplierId, ?int $userId, string $content, string $filename, bool $dryRun = false): array
    {
        $empty = ['ok' => false, 'dry_run' => $dryRun, 'created' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0, 'rows' => []];
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $matrix = in_array($ext, ['xlsx', 'xls', 'ods'], true)
            ? $this->readSpreadsheet($content, $ext)
            : $this->readCsv($content);
        if ($matrix === []) {
            return $empty + ['error' => 'Soubor je prázdný nebo nečitelný.'];
        }

        $map = $this->mapHeader(array_shift($matrix));
        if (!isset($map['date']) || (!isset($map['amount']) && !(isset($map['quantity']) && isset($map['unit_price'])))) {
            return $empty + ['error' => 'Chybí sloupec „datum" nebo „částka". Hlavička musí obsahovat alespoň datum a částku.'];
        }

        $report = ['ok' => true, 'dry_run' => $dryRun, 'created' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0, 'rows' => []];
        $seen = [];
        foreach ($matrix as $i => $cols) {
            $line = $i + 2;
            if ($this->isBlankRow($cols)) continue;
            try {
                $row = $this->mapRow($supplierId, $cols, $map);
                $hash = $row['dedup_hash'];
                if ($dryRun) {
                    $dup = isset($seen[$hash]) || $this->fuelings->existsByDedup($supplierId, $hash);
                    $seen[$hash] = true;
                    $status = $dup ? 'duplicate' : 'preview';
                    $report[$dup ? 'duplicates' : 'created']++;
                    $report['rows'][] = ['line' => $line, 'status' => $status] + $this->preview($row);
                    continue;
                }
                $r = $this->fuelings->insertScanned($supplierId, $row, $userId);
                if ($r > 0) {
                    $report['created']++;
                    $report['rows'][] = ['line' => $line, 'status' => 'created', 'fueling_id' => $r];
                } elseif ($r < 0) {
                    $report['updated']++;
                    $report['rows'][] = ['line' => $line, 'status' => 'updated'];
                } else {
                    $report['duplicates']++;
                    $report['rows'][] = ['line' => $line, 'status' => 'duplicate'];
                }
            } catch (\InvalidArgumentException $e) {
                $report['failed']++;
                $report['rows'][] = ['line' => $line, 'status' => 'failed', 'reason' => $e->getMessage()];
            }
        }
        return $report;
    }

    /**
     * @param list<string> $cols
     * @param array<string, list<int>> $map
     * @return array<string,mixed> data pro FuelingRepository::insertScanned
     */
    private function mapRow(int $supplierId, array $cols, array $map): array
    {
        $get = static function (string $key) use ($cols, $map): string {
            foreach ($map[$key] ?? [] as $idx) {
                $v = trim((string) ($cols[$idx] ?? ''));
                if ($v !== '' && $v !== '-') return $v;
            }
            return '';
        };

        $date = $this->parseDate($get('date'));
        if ($date === null) {
            throw new \InvalidArgumentException('Neplatné datum: „' . $get('date') . '".');
        }
        $time = $this->parseTime($get('time')) ?? $this->parseTime($get('date'));

        $quantity = $this->parseFloat($get('quantity'));
        $unitPrice = $this->parseFloat($get('unit_price'));
        $amount = $this->parseFloat($get('amount'));
        if (($amount === null || $amount <= 0) && $quantity !== null && $unitPrice !== null) {
            $amount = round($quantity * $unitPrice, 2);
        }
        if ($amount === null || $amount <= 0) {
            throw new \InvalidArgumentException('Chybí kladná částka tankování.');
        }
        if ($quantity !== null && $quantity > 0 && $unitPrice === null) {
            $unitPrice = round($amount / $quantity, 4);
        }

        $carRaw = $get('car');
        $fuelType = $get('fuel_type');
        // Sloupec karty: uloží se jen koncovka, i kdyby soubor nesl celé číslo.
        $cardLast4 = CardNumberMask::normalizeLast4($get('card'));
        if ($carRaw !== '') {
            $carId = $this->vehicles->carIdByPlate($supplierId, $carRaw);
            $carMethod = 'plate';
            if ($carId === null) {
                $car = $this->cars->findByRegistrationOrName($supplierId, $carRaw);
                $carId = $car !== null ? (int) $car['id'] : null;
                $carMethod = 'explicit';
            }
            if ($carId === null) {
                throw new \InvalidArgumentException('Vozidlo „' . $carRaw . '" neexistuje v číselníku.');
            }
        } else {
            $vehicle = $this->vehicles->resolve($supplierId, ['card_last4' => $cardLast4, 'date' => $date]);
            $carId = $vehicle['car_id'];
            $carMethod = $vehicle['method'];
        }

        $unitRaw = $get('unit');
        if ($unitRaw === '' && $carId !== null && ($this->cars->find($carId, $supplierId)['fuel_type'] ?? null) === 'electric') {
            $unitRaw = 'kWh';
        }
        $receipt = $get('receipt');
        $currency = strtoupper($get('currency')) ?: 'CZK';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Neplatná měna: „' . $currency . '".');
        }

        $hash = hash('sha256', implode('|', [
            'import', $supplierId, $date, $time ?? '', VehicleResolver::normalizePlate($carRaw),
            number_format($amount, 2, '.', ''),
            $quantity !== null ? number_format($quantity, 3, '.', '') : '',
            mb_strtolower($receipt),
        ]));

        return [
            'car_id'             => $carId,
            'car_assigned_by'    => $carMethod,
            'card_last4'         => $cardLast4,
            'car_label'          => $carRaw,
            'fueled_date'        => $date,
            'fueled_time'        => $time,
            'fuel_type'          => $fuelType !== '' ? $fuelType : null,
            'quantity'           => $quantity,
            'unit'               => FuelKeywords::canonicalUnit($unitRaw !== '' ? $unitRaw : null, $fuelType),
            'unit_price'         => $unitPrice,
            'amount_without_vat' => $this->parseFloat($get('amount_without_vat')),
            'amount_vat'         => $this->parseFloat($get('amount_vat')),
            'amount_with_vat'    => $amount,
            'currency'           => $currency,
            'odometer'           => $this->parseInt($get('odometer')),
            'station'            => ($s = $get('station')) !== '' ? $s : null,
            'source'             => 'import',
            'receipt_number'     => $receipt !== '' ? $receipt : null,
            'note'               => ($n = $get('note')) !== '' ? $n : null,
            'dedup_hash'         => $hash,
        ];
    }

    /** @return array<string,mixed> */
    private function preview(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'car_id', 'car_assigned_by', 'card_last4', 'car_label', 'fueled_date', 'fueled_time', 'fuel_type', 'quantity', 'unit',
            'unit_price', 'amount_with_vat', 'currency', 'odometer', 'station', 'receipt_number',
        ]));
    }

    // ── čtení souboru a parsery — kopie vzoru TripImportService (nerefaktorovat kvůli upstreamu) ──

    /**
     * @param list<string> $header
     * @return array<string, list<int>>
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $norm = FuelKeywords::normalize((string) $name);
            $norm = str_replace(['_', '-', '.', '/', '(', ')'], ' ', $norm);
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
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
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

    /** @return list<list<string>> */
    private function readSpreadsheet(string $content, string $ext): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fuelimp_') . '.' . $ext;
        file_put_contents($tmp, $content);
        try {
            $reader = IOFactory::createReaderForFile($tmp);
            $reader->setReadDataOnly(false);
            $sheet = $reader->load($tmp)->getActiveSheet();
            $out = [];
            foreach ($sheet->getRowIterator() as $row) {
                $cellIter = $row->getCellIterator();
                $cellIter->setIterateOnlyExistingCells(false);
                $cells = [];
                foreach ($cellIter as $cell) {
                    $value = $cell->getValue();
                    if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                        $dt = ExcelDate::excelToDateTimeObject((float) $value);
                        $cells[] = $dt->format('H:i:s') !== '00:00:00' ? $dt->format('Y-m-d H:i') : $dt->format('Y-m-d');
                    } elseif (is_numeric($value)) {
                        // Čísla surově — formátovaná hodnota („1 234,50 Kč") by se hůř parsovala.
                        $cells[] = (string) $value;
                    } else {
                        $cells[] = (string) ($cell->getFormattedValue() ?? '');
                    }
                }
                $out[] = $cells;
            }
            return $out;
        } finally {
            @unlink($tmp);
        }
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
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})\s*[.\-/]\s*(\d{1,2})\s*[.\-/]\s*(\d{2,4})#', $s, $m)) {
            $y = (int) $m[3];
            if ($y < 100) $y += 2000;
            return checkdate((int) $m[2], (int) $m[1], $y)
                ? sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]) : null;
        }
        return null;
    }

    private function parseTime(string $s): ?string
    {
        if (preg_match('/(?:^|\s)(\d{1,2}):(\d{2})/', $s, $m) && (int) $m[1] < 24) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return null;
    }

    private function parseInt(string $s): ?int
    {
        $s = (string) preg_replace('/[^\d]/', '', $s);
        return $s === '' ? null : (int) $s;
    }

    private function parseFloat(string $s): ?float
    {
        $s = str_replace(["\u{00A0}", ' ', 'Kč', 'CZK', 'EUR', '€'], '', trim($s));
        if ($s === '') return null;
        // „1.234,50" (CZ s tečkou tisíců) i „1,234.50" (EN) → rozhoduje poslední oddělovač.
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }
}
