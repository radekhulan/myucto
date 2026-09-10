<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

final class OpeningStockImportProfile
{
    public const FIELDS = ['external_id', 'sku', 'quantity', 'unit_cost'];

    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['source_key', 'warehouse_id', 'doc_date', 'mapping', 'reader']) !== []) {
            throw new \InvalidArgumentException('opening_import_profile_unknown_field');
        }
        $sourceKey = $input['source_key'] ?? null;
        $warehouseId = $input['warehouse_id'] ?? null;
        $docDate = $input['doc_date'] ?? null;
        if (!is_string($sourceKey) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $sourceKey)
            || !is_int($warehouseId) || $warehouseId < 1
            || !is_string($docDate) || !self::isDate($docDate)) {
            throw new \InvalidArgumentException('opening_import_profile_invalid');
        }
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || count($mapping) !== count(self::FIELDS)
            || array_diff(array_keys($mapping), self::FIELDS) !== []
            || array_diff(self::FIELDS, array_keys($mapping)) !== []) {
            throw new \InvalidArgumentException('opening_import_mapping_invalid');
        }
        $headers = [];
        foreach ($mapping as $header) {
            if (!is_string($header) || trim($header) === '' || mb_strlen($header) > 200) {
                throw new \InvalidArgumentException('opening_import_mapping_invalid');
            }
            $headers[] = trim($header);
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw new \InvalidArgumentException('opening_import_mapping_invalid');
        }
        $reader = $input['reader'] ?? [];
        if (!is_array($reader) || array_diff(array_keys($reader), ['encoding', 'delimiter', 'sheet']) !== []
            || !in_array($reader['encoding'] ?? 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true)
            || !in_array($reader['delimiter'] ?? ';', [';', ',', "\t", '|'], true)
            || !is_int($reader['sheet'] ?? 0) || ($reader['sheet'] ?? 0) < 0) {
            throw new \InvalidArgumentException('opening_import_reader_invalid');
        }
        return [
            'source_key' => $sourceKey,
            'warehouse_id' => $warehouseId,
            'doc_date' => $docDate,
            'mapping' => array_combine(self::FIELDS, array_map(static fn (string $field): string => trim($mapping[$field]), self::FIELDS)),
            'reader' => [
                'encoding' => $reader['encoding'] ?? 'UTF-8',
                'delimiter' => $reader['delimiter'] ?? ';',
                'sheet' => $reader['sheet'] ?? 0,
            ],
        ];
    }

    public static function map(array $profile, array $header, array $cells): array
    {
        $indexes = [];
        foreach ($header as $index => $label) {
            $label = trim((string) $label);
            if ($label !== '' && isset($indexes[$label])) {
                throw new \InvalidArgumentException('opening_import_duplicate_header');
            }
            $indexes[$label] = $index;
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $label = $profile['mapping'][$field];
            if (!array_key_exists($label, $indexes)) {
                throw new \InvalidArgumentException('opening_import_missing_header');
            }
            $values[$field] = trim((string) ($cells[$indexes[$label]] ?? ''));
        }
        if ($values['external_id'] === '' || mb_strlen($values['external_id']) > 255
            || $values['sku'] === '' || mb_strlen($values['sku']) > 50) {
            throw new \InvalidArgumentException('opening_import_identity_invalid');
        }
        $values['quantity'] = self::decimal($values['quantity'], 11, 3, false, 'opening_import_quantity_invalid');
        $values['unit_cost'] = self::decimal($values['unit_cost'], 9, 6, true, 'opening_import_unit_cost_invalid');
        return $values;
    }

    private static function decimal(string $value, int $integerDigits, int $scale, bool $allowZero, string $error): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:[.,]([0-9]+))?$/D', $value)) {
            throw new \InvalidArgumentException($error);
        }
        $value = str_replace(',', '.', $value);
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        if (strlen($integer) > $integerDigits || strlen($fraction) > $scale) {
            throw new \InvalidArgumentException($error);
        }
        $normalized = $integer . '.' . str_pad($fraction, $scale, '0');
        if (!$allowZero && $integer === '0' && trim($fraction, '0') === '') {
            throw new \InvalidArgumentException($error);
        }
        return $normalized;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
