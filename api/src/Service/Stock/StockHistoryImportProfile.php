<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Http\IsoDate;

/**
 * Profil importu historických skladových dokladů: jeden řádek souboru = jeden řádek
 * dokladu, hlavička dokladu se na jeho řádcích opakuje a doklad drží pohromadě číslo.
 *
 * Typy: příjemka, výdejka, převodka, inventurní přebytek (příjemka s původem inventura)
 * a manko (výdejka s původem inventura). Nákupní cena je povinná jen u příjmu, výdej
 * a převodku ocení klouzavý průměr při importu.
 */
final class StockHistoryImportProfile
{
    public const REQUIRED = ['doc_number', 'doc_type', 'doc_date', 'warehouse', 'sku', 'quantity'];
    public const OPTIONAL = ['warehouse_to', 'unit_cost', 'partner', 'description', 'invoice_number', 'purchase_invoice_number', 'note'];
    public const TYPES = ['receipt', 'issue', 'transfer', 'surplus', 'shortage'];

    /** Nejvyšší počet řádků souboru (čtečka katalogových importů jich jinak bere 100 000). */
    public const MAX_ROWS = 300_000;

    private const TYPE_ALIASES = [
        'receipt' => 'receipt', 'prijemka' => 'receipt', 'příjemka' => 'receipt',
        'issue' => 'issue', 'vydejka' => 'issue', 'výdejka' => 'issue',
        'transfer' => 'transfer', 'prevodka' => 'transfer', 'převodka' => 'transfer',
        'surplus' => 'surplus', 'prebytek' => 'surplus', 'přebytek' => 'surplus',
        'shortage' => 'shortage', 'manko' => 'shortage',
    ];

    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['source_key', 'cutover_date', 'mapping', 'reader', 'number_prefixes', 'fill_negative']) !== []) {
            throw new \InvalidArgumentException('history_import_profile_unknown_field');
        }
        $sourceKey = $input['source_key'] ?? null;
        $cutover = $input['cutover_date'] ?? null;
        if (!is_string($sourceKey) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $sourceKey)
            || ($cutover !== null && (!is_string($cutover) || !IsoDate::isValid($cutover)))
            || !is_bool($input['fill_negative'] ?? false)) {
            throw new \InvalidArgumentException('history_import_profile_invalid');
        }
        if ($cutover !== null && $cutover > date('Y-m-d')) {
            throw new \InvalidArgumentException('history_import_cutover_future');
        }
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || array_diff(array_keys($mapping), [...self::REQUIRED, ...self::OPTIONAL]) !== []
            || array_diff(self::REQUIRED, array_keys($mapping)) !== []) {
            throw new \InvalidArgumentException('history_import_mapping_invalid');
        }
        $normalizedMapping = [];
        foreach ($mapping as $field => $header) {
            if ($header === null || $header === '') {
                continue;
            }
            if (!is_string($header) || trim($header) === '' || mb_strlen($header) > 200) {
                throw new \InvalidArgumentException('history_import_mapping_invalid');
            }
            $normalizedMapping[$field] = trim($header);
        }
        if (array_diff(self::REQUIRED, array_keys($normalizedMapping)) !== []
            || count(array_unique($normalizedMapping)) !== count($normalizedMapping)) {
            throw new \InvalidArgumentException('history_import_mapping_invalid');
        }
        $prefixes = $input['number_prefixes'] ?? [];
        if (!is_array($prefixes) || array_diff(array_keys($prefixes), self::TYPES) !== []) {
            throw new \InvalidArgumentException('history_import_prefix_invalid');
        }
        foreach ($prefixes as $prefix) {
            if (!is_string($prefix) || !preg_match('#^[A-Za-z0-9/._-]{0,10}$#D', $prefix)) {
                throw new \InvalidArgumentException('history_import_prefix_invalid');
            }
        }
        $reader = $input['reader'] ?? [];
        if (!is_array($reader) || array_diff(array_keys($reader), ['encoding', 'delimiter', 'sheet']) !== []
            || !in_array($reader['encoding'] ?? 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true)
            || !in_array($reader['delimiter'] ?? ';', [';', ',', "\t", '|'], true)
            || !is_int($reader['sheet'] ?? 0) || ($reader['sheet'] ?? 0) < 0) {
            throw new \InvalidArgumentException('history_import_reader_invalid');
        }
        return [
            'source_key' => $sourceKey,
            'cutover_date' => $cutover,
            'mapping' => $normalizedMapping,
            'number_prefixes' => array_filter($prefixes, static fn (string $prefix): bool => $prefix !== ''),
            'fill_negative' => (bool) ($input['fill_negative'] ?? false),
            'reader' => [
                'encoding' => $reader['encoding'] ?? 'UTF-8',
                'delimiter' => $reader['delimiter'] ?? ';',
                'sheet' => $reader['sheet'] ?? 0,
            ],
        ];
    }

    /** @return array<string,int> hlavička souboru → index sloupce */
    public static function headerIndexes(array $profile, array $header): array
    {
        $indexes = [];
        foreach ($header as $index => $label) {
            $label = trim((string) $label);
            if ($label !== '' && isset($indexes[$label])) {
                throw new \InvalidArgumentException('history_import_duplicate_header');
            }
            $indexes[$label] = $index;
        }
        foreach ($profile['mapping'] as $label) {
            if (!array_key_exists($label, $indexes)) {
                throw new \InvalidArgumentException('history_import_missing_header');
            }
        }
        return $indexes;
    }

    /**
     * Přečte a zkontroluje jeden řádek. Odkazy (sklad, karta, faktura) zůstávají jako text,
     * dohledá je worker po dávkách.
     */
    public static function map(array $profile, array $indexes, array $cells): array
    {
        $raw = [];
        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $field) {
            $label = $profile['mapping'][$field] ?? null;
            $raw[$field] = $label === null ? '' : trim((string) ($cells[$indexes[$label]] ?? ''));
        }
        foreach (self::REQUIRED as $field) {
            if ($raw[$field] === '') {
                throw new \InvalidArgumentException('history_import_' . $field . '_required');
            }
        }
        $type = self::TYPE_ALIASES[mb_strtolower($raw['doc_type'])] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException('history_import_doc_type_invalid');
        }
        $number = ($profile['number_prefixes'][$type] ?? '') . $raw['doc_number'];
        if (mb_strlen($number) > 30) {
            throw new \InvalidArgumentException('history_import_doc_number_too_long');
        }
        $date = self::date($raw['doc_date']);
        if ($profile['cutover_date'] !== null && $date >= $profile['cutover_date']) {
            throw new \InvalidArgumentException('history_import_doc_date_after_cutover');
        }
        // Historie bez data přechodu zamkne sklad do svého posledního dne; dnešek patří živým dokladům.
        if ($date >= date('Y-m-d')) {
            throw new \InvalidArgumentException('history_import_doc_date_not_past');
        }
        if (mb_strlen($raw['warehouse']) > 20 || mb_strlen($raw['warehouse_to']) > 20 || mb_strlen($raw['sku']) > 50) {
            throw new \InvalidArgumentException('history_import_reference_too_long');
        }
        if ($type === 'transfer'
            ? $raw['warehouse_to'] === '' || mb_strtolower($raw['warehouse_to']) === mb_strtolower($raw['warehouse'])
            : $raw['warehouse_to'] !== '') {
            throw new \InvalidArgumentException('history_import_warehouse_to_invalid');
        }
        $receipt = in_array($type, ['receipt', 'surplus'], true);
        if ($receipt && $raw['unit_cost'] === '') {
            throw new \InvalidArgumentException('history_import_unit_cost_required');
        }
        if ($type === 'transfer' && ($raw['invoice_number'] !== '' || $raw['purchase_invoice_number'] !== '')) {
            throw new \InvalidArgumentException('history_import_transfer_invoice');
        }
        if (mb_strlen($raw['invoice_number']) > 20 || mb_strlen($raw['purchase_invoice_number']) > 50) {
            throw new \InvalidArgumentException('history_import_invoice_number_too_long');
        }
        return [
            'doc_number' => $number,
            'doc_type' => $type,
            'doc_date' => $date,
            'warehouse' => $raw['warehouse'],
            'warehouse_to' => $raw['warehouse_to'] === '' ? null : $raw['warehouse_to'],
            'sku' => $raw['sku'],
            'quantity' => OpeningStockImportProfile::decimal($raw['quantity'], 11, 3, false, 'history_import_quantity_invalid'),
            'unit_cost' => $receipt ? OpeningStockImportProfile::decimal($raw['unit_cost'], 9, 6, true, 'history_import_unit_cost_invalid') : null,
            'partner' => self::text($raw['partner']),
            'description' => self::text($raw['description']),
            'invoice_number' => $raw['invoice_number'] === '' ? null : $raw['invoice_number'],
            'purchase_invoice_number' => $raw['purchase_invoice_number'] === '' ? null : $raw['purchase_invoice_number'],
            'note' => self::text($raw['note']),
        ];
    }

    /**
     * Klíč dokladu = výsledné číslo malými písmeny (unikátnost čísel ve firmě nerozlišuje
     * velikost písmen). Vrací ho i pro chybný řádek, aby šel zneplatnit celý doklad.
     */
    public static function documentKey(array $profile, array $indexes, array $cells): ?string
    {
        $number = trim((string) ($cells[$indexes[$profile['mapping']['doc_number']]] ?? ''));
        if ($number === '') {
            return null;
        }
        $type = self::TYPE_ALIASES[mb_strtolower(trim((string) ($cells[$indexes[$profile['mapping']['doc_type']]] ?? '')))] ?? null;
        return mb_strtolower(($type === null ? '' : ($profile['number_prefixes'][$type] ?? '')) . $number);
    }

    /** YYYY-MM-DD, d.m.yyyy nebo pořadové číslo dne z XLSX. */
    public static function date(string $value): string
    {
        if (preg_match('/^\d{1,6}(?:\.0+)?$/D', $value) && (int) $value > 0) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        } elseif (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/D', $value, $m)) {
            $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } else {
            $date = $value;
        }
        if (!IsoDate::isValid($date) || $date < '1900-01-01') {
            throw new \InvalidArgumentException('history_import_doc_date_invalid');
        }
        return $date;
    }

    private static function text(string $value): ?string
    {
        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
