<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

final class CatalogImportProfile
{
    public const FIELDS = [
        'id', 'external_id', 'sku', 'name', 'unit', 'ean', 'item_type', 'vat_rate_id',
        'min_qty', 'is_active', 'note', 'manufacturer_code', 'manufacturer_id',
        'warranty_months', 'delivery_days', 'weight_g', 'export_eshop', 'is_stocked',
        'pricing_base', 'categories', 'tag_ids', 'i18n', 'attributes', 'fees', 'price', 'prices',
        'master_id', 'master_external_id', 'variant_options', 'inheritance', 'relations',
    ];

    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['identity', 'source_key', 'mode', 'mapping', 'blank', 'operations', 'reader']) !== []) {
            throw new \InvalidArgumentException('import_profile_unknown_field');
        }
        $identity = $input['identity'] ?? 'sku';
        $mode = $input['mode'] ?? 'upsert';
        $blank = $input['blank'] ?? 'preserve';
        if (!in_array($identity, ['id', 'sku', 'external_id'], true)
            || !in_array($mode, ['create', 'update', 'upsert'], true)
            || !in_array($blank, ['preserve', 'clear'], true)) {
            throw new \InvalidArgumentException('import_profile_invalid');
        }
        $source = $input['source_key'] ?? null;
        if ($identity === 'external_id' && (!is_string($source) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $source))) {
            throw new \InvalidArgumentException('import_source_key_required');
        }
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || !isset($mapping[$identity]) || array_diff(array_keys($mapping), self::FIELDS) !== []) {
            throw new \InvalidArgumentException('import_mapping_invalid');
        }
        if (isset($mapping['master_external_id']) && (!is_string($source) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $source))) {
            throw new \InvalidArgumentException('import_source_key_required');
        }
        foreach ($mapping as $header) {
            if (!is_string($header) || trim($header) === '' || mb_strlen($header) > 200) {
                throw new \InvalidArgumentException('import_mapping_invalid');
            }
        }
        $operations = $input['operations'] ?? [];
        if (!is_array($operations) || array_diff(array_keys($operations), self::FIELDS) !== []) {
            throw new \InvalidArgumentException('import_operations_invalid');
        }
        foreach ($operations as $field => $operation) {
            if (!in_array($operation, ['set', 'preserve', 'clear'], true)
                || (in_array($field, ['id', 'external_id', 'sku', 'name', 'unit', 'item_type'], true) && $operation === 'clear')
                || ($field === $identity && $operation !== 'set')) {
                throw new \InvalidArgumentException('import_operations_invalid');
            }
        }
        $reader = $input['reader'] ?? [];
        if (!is_array($reader) || array_diff(array_keys($reader), ['encoding', 'delimiter', 'sheet']) !== []
            || !in_array($reader['encoding'] ?? 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2'], true)
            || !in_array($reader['delimiter'] ?? ';', [';', ',', "\t", '|'], true)
            || !is_int($reader['sheet'] ?? 0) || ($reader['sheet'] ?? 0) < 0) {
            throw new \InvalidArgumentException('import_reader_invalid');
        }
        ksort($mapping);
        ksort($operations);
        return ['identity' => $identity, 'source_key' => ($identity === 'external_id' || isset($mapping['master_external_id'])) ? $source : null,
            'mode' => $mode, 'mapping' => $mapping, 'blank' => $blank, 'operations' => $operations,
            'reader' => ['encoding' => $reader['encoding'] ?? 'UTF-8', 'delimiter' => $reader['delimiter'] ?? ';', 'sheet' => $reader['sheet'] ?? 0]];
    }

    public static function map(array $profile, array $header, array $cells): array
    {
        $indexes = [];
        foreach ($header as $index => $label) {
            $label = trim($label);
            if ($label !== '' && array_key_exists($label, $indexes)) {
                throw new \InvalidArgumentException('import_duplicate_header');
            }
            $indexes[$label] = $index;
        }
        $result = [];
        foreach ($profile['mapping'] as $field => $label) {
            if (!array_key_exists(trim($label), $indexes)) {
                throw new \InvalidArgumentException('import_missing_header');
            }
            $operation = $profile['operations'][$field] ?? 'set';
            if ($operation === 'preserve') {
                continue;
            }
            $value = trim($cells[$indexes[trim($label)]] ?? '');
            if ($value === '' && $field === $profile['identity']) {
                throw new \InvalidArgumentException('import_identity_required');
            }
            if ($operation === 'clear' || ($value === '' && $profile['blank'] === 'clear')) {
                $result[$field] = self::empty($field);
            } elseif ($value !== '') {
                $result[$field] = self::value($field, $value);
            }
        }
        foreach ($profile['operations'] as $field => $operation) {
            if ($operation === 'clear') {
                $result[$field] = self::empty($field);
            }
        }
        return $result;
    }

    private static function empty(string $field): mixed
    {
        if (in_array($field, ['id', 'external_id', 'sku', 'name', 'unit', 'item_type', 'pricing_base', 'is_active', 'is_stocked', 'export_eshop'], true)) {
            throw new \InvalidArgumentException('import_required_value');
        }
        if ($field === 'inheritance') {
            return [];
        }
        return in_array($field, ['categories', 'tag_ids', 'i18n', 'attributes', 'fees', 'prices', 'variant_options', 'relations'], true) ? [] : null;
    }

    private static function value(string $field, string $value): mixed
    {
        if ($field === 'inheritance') {
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('import_json_invalid');
            }
            if (!str_starts_with($value, '{') || !is_array($decoded) || array_is_list($decoded)) {
                throw new \InvalidArgumentException('import_json_invalid');
            }
            return $decoded;
        }
        if (in_array($field, ['categories', 'tag_ids', 'i18n', 'attributes', 'fees', 'prices', 'variant_options', 'relations'], true)) {
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('import_json_invalid');
            }
            if (!str_starts_with($value, '[') || !is_array($decoded) || !array_is_list($decoded) || count($decoded) > 200) {
                throw new \InvalidArgumentException('import_json_invalid');
            }
            self::validateCollection($field, $decoded);
            return $decoded;
        }
        if (in_array($field, ['is_active', 'is_stocked', 'export_eshop'], true)) {
            return match (mb_strtolower($value)) {
                '1', 'true', 'ano', 'yes' => true,
                '0', 'false', 'ne', 'no' => false,
                default => throw new \InvalidArgumentException('import_boolean_invalid'),
            };
        }
        if (in_array($field, ['id', 'vat_rate_id', 'manufacturer_id', 'warranty_months', 'delivery_days', 'weight_g', 'master_id'], true)) {
            if (!preg_match('/^\d{1,10}$/D', $value) || (int) $value > 2147483647
                || (in_array($field, ['id', 'vat_rate_id', 'manufacturer_id', 'master_id'], true) && (int) $value < 1)) {
                throw new \InvalidArgumentException('import_integer_invalid');
            }
            return (int) $value;
        }
        if ($field === 'min_qty' || $field === 'price') {
            $value = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], $value);
            $pattern = $field === 'price' ? '/^\d{1,10}(?:\.\d{1,2})?$/D' : '/^\d{1,11}(?:\.\d{1,3})?$/D';
            if (!preg_match($pattern, $value)) {
                throw new \InvalidArgumentException('import_decimal_invalid');
            }
            return bcadd($value, '0', $field === 'price' ? 2 : 3);
        }
        $limit = match ($field) {
            'sku', 'manufacturer_code' => 50,
            'master_external_id' => 255,
            'unit', 'ean' => 20,
            'note' => 10000,
            default => 255,
        };
        if (mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('import_text_invalid');
        }
        if (($field === 'item_type' && !in_array($value, ['goods', 'material', 'product'], true))
            || ($field === 'pricing_base' && !in_array($value, ['weighted_avg', 'last_purchase', 'manual'], true))) {
            throw new \InvalidArgumentException('import_enum_invalid');
        }
        return $value;
    }

    private static function validateCollection(string $field, array $rows): void
    {
        foreach ($rows as $row) {
            if ($field === 'tag_ids' || ($field === 'categories' && is_int($row))) {
                if (!is_int($row) || $row < 1 || $row > 2147483647) {
                    throw new \InvalidArgumentException('import_reference_invalid');
                }
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException('import_json_row_invalid');
            }
            $idKey = match ($field) {
                'categories' => 'category_id',
                'attributes' => 'attribute_id',
                'fees' => 'fee_type_id',
                'variant_options' => 'attribute_id',
                'relations' => 'target_stock_item_id',
                default => null,
            };
            if ($idKey !== null && (!is_int($row[$idKey] ?? null) || $row[$idKey] < 1 || $row[$idKey] > 2147483647)) {
                throw new \InvalidArgumentException('import_reference_invalid');
            }
            foreach (['is_primary', 'vat_included', 'is_manual_override', 'use_pricing_rules', 'value_bool'] as $boolean) {
                if (array_key_exists($boolean, $row) && $row[$boolean] !== null && !is_bool($row[$boolean])) {
                    throw new \InvalidArgumentException('import_boolean_invalid');
                }
            }
            if ($field === 'i18n' && (!is_string($row['locale'] ?? null) || !preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $row['locale'])
                || !is_string($row['name'] ?? null) || trim($row['name']) === '' || mb_strlen($row['name']) > 255)) {
                throw new \InvalidArgumentException('import_translation_invalid');
            }
            if (in_array($field, ['fees', 'prices'], true) && isset($row['currency_code'])
                && (!is_string($row['currency_code']) || !preg_match('/^[A-Z]{3}$/D', $row['currency_code']))) {
                throw new \InvalidArgumentException('import_currency_invalid');
            }
            if ($field === 'prices' && !isset($row['currency_code'])) {
                throw new \InvalidArgumentException('import_currency_invalid');
            }
            if ($field === 'fees' && (!is_string($row['amount'] ?? null) || !preg_match('/^-?\d+(?:\.\d+)?$/D', $row['amount']))) {
                throw new \InvalidArgumentException('import_decimal_invalid');
            }
            if ($field === 'variant_options' && (!is_int($row['option_id'] ?? null) || $row['option_id'] < 1)) {
                throw new \InvalidArgumentException('import_reference_invalid');
            }
            if ($field === 'relations' && (!in_array($row['type'] ?? null, ['accessory', 'replacement', 'related'], true))) {
                throw new \InvalidArgumentException('import_enum_invalid');
            }
        }
    }
}
