<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Migration\Shared\MigratedInventoryException;
use MyInvoice\Service\Migration\Shared\MigratedInventoryWriter;

/** Skladové karty a vozidla; stav skladu ani číselník opakovaných tras se nedomýšlí. */
final class StereoNxInventory
{
    private const KIND_WAREHOUSE = 'stock_warehouse';
    private const KIND_ITEM = 'stock_item';
    private const KIND_CAR = 'logbook_car';

    public function __construct(
        private readonly StereoNxImportMap $map,
        private readonly MigratedInventoryWriter $writer,
    ) {}

    /** @return array<string,mixed> `records` jsou interní a nesmějí se vracet v protokolu. */
    public function prepare(StereoNxBackup $backup): array
    {
        return self::fromTables([
            'SCenik' => iterator_to_array($backup->rows('SCenik'), false),
            'SSklady' => iterator_to_array($backup->rows('SSklady'), false),
            'SParamSkl' => iterator_to_array($backup->rows('SParamSkl'), false),
            'Kauta' => iterator_to_array($backup->rows('Kauta'), false),
            'Kcesty' => iterator_to_array($backup->rows('Kcesty'), false),
        ], $backup->companyIdentity(), $backup->companyIndex());
    }

    /**
     * Typy M/V/Z jsou doložené příručkou Stereo jako materiál/výrobek/zboží.
     * Ostatní uživatelsky měnitelné typy se nesmějí převést odhadem.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     * @param array{ico:string,dic?:string,name?:string,vat_payer?:bool} $identity
     * @return array<string,mixed>
     */
    public static function fromTables(array $tables, array $identity, int $companyIndex): array
    {
        foreach (['SCenik', 'SSklady', 'SParamSkl', 'Kauta', 'Kcesty'] as $name) {
            if (!array_key_exists($name, $tables)) {
                throw new StereoNxException('inventory_table_missing', 'Chybí zdrojová tabulka skladu nebo knihy jízd.');
            }
        }
        $ico = preg_replace('/\D/', '', (string) ($identity['ico'] ?? '')) ?? '';
        if (!preg_match('/^[0-9]{8}$/D', $ico) || $companyIndex < 0) {
            throw new StereoNxException('inventory_source_identity_invalid', 'Zdrojová firma nemá platnou identitu.');
        }
        $warnings = [];
        $records = ['warehouses' => [], 'items' => [], 'cars' => []];
        $seen = [];
        foreach ($tables['SSklady'] as $row) {
            $code = self::text($row['Sklad'] ?? null);
            $name = self::text($row['Nazev'] ?? null);
            if ($code === '' || mb_strlen($code) > 20 || $name === '' || mb_strlen($name) > 100) {
                throw new StereoNxException('warehouse_identity_invalid', 'Zdrojový sklad nemá platný kód nebo název.');
            }
            if (isset($seen[$code])) throw new StereoNxException('warehouse_duplicate', 'Zdroj obsahuje duplicitní sklad.');
            $seen[$code] = true;
            $record = ['source_key' => $code, 'code' => $code, 'name' => $name];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $records['warehouses'][] = $record;
        }

        $warehouseCodes = array_fill_keys(array_keys($seen), true);
        $priceMode = self::priceModes($tables['SParamSkl']);
        $skippedItems = 0;
        $seen = [];
        foreach ($tables['SCenik'] as $row) {
            $warehouse = self::text($row['Sklad'] ?? null);
            $sku = self::text($row['PolozkaSkladu'] ?? null);
            $key = $warehouse . '|' . $sku;
            if ($warehouse === '' || $sku === '' || mb_strlen($sku) > 50 || isset($seen[$key])) {
                throw new StereoNxException(isset($seen[$key]) ? 'stock_item_duplicate' : 'stock_item_identity_invalid',
                    'Zdrojová skladová karta nemá jednoznačnou identitu.');
            }
            $seen[$key] = true;
            if (!isset($warehouseCodes[$warehouse])) {
                $skippedItems++;
                self::warning($warnings, 'stock_item_warehouse_missing', 'Některé skladové karty odkazují na neznámý sklad a nepřevedou se.');
                continue;
            }
            $name = self::text($row['Nazev'] ?? null);
            $unit = self::text($row['Jednotka'] ?? null);
            if ($name === '' || mb_strlen($name) > 255 || mb_strlen($unit) > 20) {
                $skippedItems++;
                self::warning($warnings, 'stock_item_required_data_invalid', 'Některé skladové karty nemají platný název nebo jednotku a nepřevedou se.');
                continue;
            }
            $type = match (self::text($row['TypPolozky'] ?? null)) {
                'M' => 'material', 'V' => 'product', 'Z' => 'goods', default => null,
            };
            if ($type === null) {
                $skippedItems++;
                self::warning($warnings, 'stock_item_type_unsupported', 'Některé typy skladových položek nemají v MyÚčtu doložený protějšek a nepřevedou se.');
                continue;
            }
            $ean = self::ean($row['CarovyKod'] ?? null, $sku);
            if (self::text($row['CarovyKod'] ?? null) !== '' && $ean === null) {
                self::warning($warnings, 'stock_item_ean_implicit', 'Kód položky, který není ověřeným EAN, se nepřevede jako čárový kód.');
            }
            $salePrice = null;
            $mode = $priceMode[$warehouse] ?? $priceMode[''] ?? null;
            if ($mode === true) {
                $salePrice = UnitPricePrecision::toDecimalString(self::decimal($row['ProdejniCena'] ?? null, UnitPricePrecision::DECIMALS, false));
            } elseif (self::number($row['ProdejniCena'] ?? null) !== 0.0) {
                self::warning($warnings, 'stock_item_price_vat_unknown', 'Prodejní cena bez doloženého režimu DPH se nepřevede.');
            }
            if (self::number($row['Mnozstvi'] ?? null) !== 0.0 || self::number($row['MnozstviPocSt'] ?? null) !== 0.0) {
                self::warning($warnings, 'stock_balance_skipped', 'Stav skladu se bez úplné knihy pohybů a společného data řezu nepřevede; účetní deník zůstává autoritativní.');
            }
            if (self::text($row['SazbaDPHn'] ?? null) !== '' || self::text($row['SazbaDPHp'] ?? null) !== '') {
                self::warning($warnings, 'stock_vat_rate_skipped', 'Zdrojová zkratka sazby DPH nemá bezpečnou vazbu na historickou sazbu MyÚčta a nepřevede se.');
            }
            if (!is_bool($row['VyrobniCisla'] ?? null) || !is_bool($row['Neaktivni'] ?? null)) {
                $skippedItems++;
                self::warning($warnings, 'stock_item_flags_unknown', 'U některých skladových karet není doložen režim výrobních čísel nebo aktivita a nepřevedou se.');
                continue;
            }
            $weight = self::number($row['HmotnostKg'] ?? null);
            $intrastatCode = self::text($row['IntraKod'] ?? null);
            if ($intrastatCode !== '' && preg_match('/^[0-9]{8}$/D', $intrastatCode) !== 1) {
                self::warning($warnings, 'stock_intrastat_code_invalid', 'Neplatný osmimístný kód kombinované nomenklatury se nepřevede.');
                $intrastatCode = '';
            }
            $intrastatUnit = self::text($row['IntraDoplMJ'] ?? null);
            if (mb_strlen($intrastatUnit) > 3) {
                self::warning($warnings, 'stock_intrastat_unit_invalid', 'Neplatná doplňková jednotka Intrastatu se nepřevede.');
                $intrastatUnit = '';
            }
            $record = [
                'source_key' => $key, 'warehouse_code' => $warehouse, 'sku' => $sku, 'name' => $name,
                'item_type' => $type, 'unit' => $unit === '' ? 'ks' : $unit, 'ean' => $ean,
                'tracking_mode' => $row['VyrobniCisla'] === true ? 'serial' : 'none',
                'sale_price_without_vat' => $salePrice,
                'min_qty' => self::decimal($row['MinMnozstvi'] ?? null, 3, true),
                'is_active' => $row['Neaktivni'] === false,
                'note' => self::nullableText($row['Poznamka'] ?? null, 2000, 'stock_item_note_invalid'),
                'weight_g' => $weight > 0.0 ? (int) round($weight * 1000) : null,
                'intrastat_cn8_code' => $intrastatCode === '' ? null : $intrastatCode,
                'intrastat_net_mass_kg' => self::decimal($row['IntraVlHmMJ'] ?? null, 3, true),
                'intrastat_supplementary_unit' => $intrastatUnit === '' ? null : $intrastatUnit,
                'intrastat_supplementary_unit_coefficient' => self::decimal($row['IntraKoefMJ'] ?? null, 6, true),
            ];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $records['items'][] = $record;
        }

        $skippedCars = 0;
        $seen = [];
        foreach ($tables['Kauta'] as $row) {
            $key = self::text($row['Vozidlo'] ?? null);
            $registration = self::text($row['SPZ'] ?? null);
            if ($key === '' || strlen($key) > 190 || isset($seen[$key])) {
                throw new StereoNxException(isset($seen[$key]) ? 'car_duplicate' : 'car_identity_invalid', 'Zdrojové vozidlo nemá jednoznačnou identitu.');
            }
            $seen[$key] = true;
            if ($registration === '' || mb_strlen($registration) > 20) {
                $skippedCars++;
                self::warning($warnings, 'car_registration_missing', 'Vozidlo bez platné registrační značky se nepřevede.');
                continue;
            }
            $fuelSource = mb_strtolower(self::text($row['Palivo'] ?? null));
            $fuel = $fuelSource === 'nafta' ? 'diesel' : null;
            if ($fuelSource !== '' && $fuel === null) self::warning($warnings, 'car_fuel_unsupported', 'Neznámý zdrojový druh paliva se nepřevede.');
            if (self::hasAny($row, ['CisMotoru', 'PrumSpotreba', 'KonStavTach', 'CelkemUjeteKm', 'CelkemCerpano',
                'PlatnostEmise', 'ObjemNadrze', 'PlatnostTK', 'Ridic', 'DatumRegistrace', 'DatumVyrazeni'])) {
                self::warning($warnings, 'car_details_unmapped', 'Detail vozidla, pro který MyÚčto nemá odpovídající ověřené pole, zůstane k ručnímu doplnění.');
            }
            $odo = $row['PocStavKm'] ?? null;
            $odometer = is_int($odo) && $odo >= 0 ? $odo : null;
            $retired = self::text($row['DatumVyrazeni'] ?? null);
            if ($retired !== '' && !self::validDate($retired)) {
                $skippedCars++;
                self::warning($warnings, 'car_retirement_date_invalid', 'Vozidlo s neplatným datem vyřazení se nepřevede.');
                continue;
            }
            $record = [
                'source_key' => $key, 'registration' => $registration,
                'name' => self::nullableText($row['Nazev'] ?? null, 100, 'car_name_invalid'),
                'vin' => self::nullableText($row['VIN'] ?? null, 40, 'car_vin_invalid'), 'fuel_type' => $fuel,
                'odometer_start' => $odometer, 'is_archived' => $retired !== '',
                'note' => self::nullableText($row['Poznamka'] ?? null, 2000, 'car_note_invalid'),
            ];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $records['cars'][] = $record;
        }

        if ($tables['Kcesty'] !== []) {
            self::warning($warnings, 'route_templates_skipped', 'Opakované trasy nemají v MyÚčtu samostatný číselník; bez data nejde založit skutečné jízdy.');
        }
        return [
            'source_ico' => $ico, 'source_company_index' => $companyIndex,
            'counts' => [
                'warehouses_source' => count($tables['SSklady']), 'warehouses_ready' => count($records['warehouses']),
                'stock_items_source' => count($tables['SCenik']), 'stock_items_ready' => count($records['items']),
                'stock_items_skipped' => $skippedItems, 'cars_source' => count($tables['Kauta']),
                'cars_ready' => count($records['cars']), 'cars_skipped' => $skippedCars,
                'route_templates_source' => count($tables['Kcesty']), 'route_templates_skipped' => count($tables['Kcesty']),
            ],
            'warnings' => array_values($warnings), 'records' => $records,
        ];
    }

    /** @param array<string,mixed> $plan @return array{counts:array<string,int>,warnings:list<array{level:string,code:string,message:string}>} */
    public function write(array $plan, int $supplierId, ?int $userId): array
    {
        $records = $plan['records'] ?? null;
        $ico = $plan['source_ico'] ?? null;
        $companyIndex = $plan['source_company_index'] ?? null;
        if (!is_array($records) || !is_string($ico) || !is_int($companyIndex) || $supplierId <= 0) {
            throw new StereoNxException('inventory_plan_invalid', 'Plán převodu skladu a vozidel není platný.');
        }
        $counts = ['warehouses_created' => 0, 'warehouses_existing' => 0, 'stock_items_created' => 0,
            'stock_items_existing' => 0, 'cars_created' => 0, 'cars_existing' => 0,
            'route_templates_skipped' => (int) ($plan['counts']['route_templates_skipped'] ?? 0)];
        $warnings = [];
        try {
            $stockEnabled = $this->writer->stockEnabled($supplierId);
            if (!$stockEnabled && (($records['warehouses'] ?? []) !== [] || ($records['items'] ?? []) !== [])) {
                $counts['warehouses_skipped'] = count($records['warehouses'] ?? []);
                $counts['stock_items_skipped'] = count($records['items'] ?? []);
                self::warning($warnings, 'stock_module_missing', 'Firma nemá zapnutý modul Sklad; sklady a skladové karty se nepřevedly.');
            } else {
                foreach ($records['warehouses'] ?? [] as $record) {
                    $this->writeWarehouse($record, $supplierId, $ico, $companyIndex, $counts);
                }
                foreach ($records['items'] ?? [] as $record) {
                    $this->writeItem($record, $supplierId, $ico, $companyIndex, $counts);
                }
            }
            foreach ($records['cars'] ?? [] as $record) {
                $this->writeCar($record, $supplierId, $userId, $ico, $companyIndex, $counts);
            }
        } catch (MigratedInventoryException $e) {
            throw new StereoNxException($e->reason, $e->getMessage());
        }
        return ['counts' => $counts, 'warnings' => array_values($warnings)];
    }

    /** @param array<string,mixed> $record @param array<string,int> $counts */
    private function writeWarehouse(array $record, int $supplierId, string $ico, int $index, array &$counts): void
    {
        $mapped = $this->mapped($supplierId, $ico, $index, self::KIND_WAREHOUSE, $record);
        if ($mapped !== null) {
            $this->writer->verifyWarehouse($supplierId, $mapped, $record);
            $counts['warehouses_existing']++;
            return;
        }
        $result = $this->writer->warehouse($supplierId, $record);
        $counts[$result['created'] ? 'warehouses_created' : 'warehouses_existing']++;
        $this->map->put($supplierId, $ico, $index, self::KIND_WAREHOUSE,
            (string) $record['source_key'], (string) $record['source_hash'], $result['id']);
    }

    /** @param array<string,mixed> $record @param array<string,int> $counts */
    private function writeItem(array $record, int $supplierId, string $ico, int $index, array &$counts): void
    {
        $mapped = $this->mapped($supplierId, $ico, $index, self::KIND_ITEM, $record);
        if ($mapped !== null) {
            $this->writer->verifyItem($supplierId, $mapped, $record);
            $counts['stock_items_existing']++;
            return;
        }
        $result = $this->writer->item($supplierId, $record);
        $counts[$result['created'] ? 'stock_items_created' : 'stock_items_existing']++;
        $this->map->put($supplierId, $ico, $index, self::KIND_ITEM,
            (string) $record['source_key'], (string) $record['source_hash'], $result['id']);
    }

    /** @param array<string,mixed> $record @param array<string,int> $counts */
    private function writeCar(array $record, int $supplierId, ?int $userId, string $ico, int $index, array &$counts): void
    {
        $mapped = $this->mapped($supplierId, $ico, $index, self::KIND_CAR, $record);
        if ($mapped !== null) {
            $this->writer->verifyCar($supplierId, $mapped, $record);
            $counts['cars_existing']++;
            return;
        }
        $result = $this->writer->car($supplierId, $record, $userId !== null && $userId > 0 ? $userId : null);
        $counts[$result['created'] ? 'cars_created' : 'cars_existing']++;
        $this->map->put($supplierId, $ico, $index, self::KIND_CAR,
            (string) $record['source_key'], (string) $record['source_hash'], $result['id']);
    }

    /** @param array<string,mixed> $record */
    private function mapped(int $supplierId, string $ico, int $index, string $kind, array $record): ?int
    {
        $map = $this->map->get($supplierId, $ico, $index, $kind, (string) ($record['source_key'] ?? ''));
        if ($map === null) return null;
        if ($map['source_hash'] !== ($record['source_hash'] ?? null)) {
            throw new StereoNxException('inventory_source_changed', 'Zdrojový záznam se od předchozího převodu změnil.');
        }
        return $map['target_id'];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,bool> */
    private static function priceModes(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_bool($row['CenyBezDPH'] ?? null)) $out[self::text($row['Sklad'] ?? null)] = $row['CenyBezDPH'];
        }
        return $out;
    }

    private static function ean(mixed $value, string $sku): ?string
    {
        $ean = self::text($value);
        if ($ean === '' || $ean === $sku || !preg_match('/^[0-9]{8}$|^[0-9]{12,14}$/D', $ean)) return null;
        $sum = 0; $parity = strlen($ean) % 2;
        for ($i = 0, $n = strlen($ean) - 1; $i < $n; $i++) $sum += (int) $ean[$i] * (($i % 2) === $parity ? 3 : 1);
        return ((10 - ($sum % 10)) % 10) === (int) $ean[-1] ? $ean : null;
    }

    private static function decimal(mixed $value, int $places, bool $zeroAsNull): ?string
    {
        if (!is_int($value) && !is_float($value)) return null;
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || ($zeroAsNull && $number == 0.0)) return null;
        return number_format($number, $places, '.', '');
    }

    private static function number(mixed $value): float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : 0.0;
    }

    private static function text(mixed $value): string { return is_string($value) ? trim($value) : ''; }
    private static function nullableText(mixed $value, int $max, string $code): ?string
    {
        $text = self::text($value);
        if (mb_strlen($text) > $max) throw new StereoNxException($code, 'Zdrojový text překračuje délku cílového pole.');
        return $text === '' ? null : $text;
    }
    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    /** @param array<string,mixed> $row @param list<string> $fields */
    private static function hasAny(array $row, array $fields): bool
    {
        foreach ($fields as $f) {
            $value = $row[$f] ?? null;
            if ($value === true || self::text($value) !== '' || self::number($value) !== 0.0) return true;
        }
        return false;
    }
    /** @param array<string,array{level:string,code:string,message:string}> $warnings */
    private static function warning(array &$warnings, string $code, string $message): void
    {
        $warnings[$code] ??= ['level' => 'warning', 'code' => $code, 'message' => $message];
    }
}
