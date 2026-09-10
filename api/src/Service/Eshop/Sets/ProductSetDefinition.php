<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Sets;

use MyInvoice\Service\Eshop\EshopException;

final class ProductSetDefinition
{
    public static function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['components', 'groups', 'prices']) !== []) {
            self::fail('set_definition_invalid');
        }
        $components = self::rows($input['components'] ?? [], 100);
        $groups = self::rows($input['groups'] ?? [], 20);
        $result = ['components' => [], 'groups' => [], 'prices' => []];
        foreach ($components as $row) {
            if (array_diff(array_keys($row), ['item_id', 'quantity']) !== []) self::fail('set_component_invalid');
            $result['components'][] = self::component($row);
        }
        $codes = [];
        foreach ($groups as $group) {
            if (array_diff(array_keys($group), ['code', 'name', 'min', 'max', 'options']) !== []) self::fail('set_group_invalid');
            $code = self::code($group['code'] ?? null);
            if (isset($codes[$code])) self::fail('set_group_invalid');
            $codes[$code] = true;
            $min = $group['min'] ?? 0;
            $max = $group['max'] ?? 1;
            $options = self::rows($group['options'] ?? [], 50);
            if (!is_int($min) || !is_int($max) || $min < 0 || $max < 1 || $min > $max || $max > count($options)) self::fail('set_group_invalid');
            $normalized = ['code' => $code, 'name' => self::name($group['name'] ?? null), 'min' => $min, 'max' => $max, 'options' => []];
            $optionCodes = [];
            foreach ($options as $option) {
                if (array_diff(array_keys($option), ['code', 'name', 'item_id', 'quantity', 'surcharges']) !== []) self::fail('set_option_invalid');
                $optionCode = self::code($option['code'] ?? null);
                if (isset($optionCodes[$optionCode])) self::fail('set_option_invalid');
                $optionCodes[$optionCode] = true;
                $surcharges = self::currencyMap($option['surcharges'] ?? []);
                foreach ($surcharges as $currency => $amount) $surcharges[$currency] = self::money($amount);
                $normalized['options'][] = self::component($option) + ['code' => $optionCode, 'name' => self::name($option['name'] ?? null), 'surcharges' => $surcharges];
            }
            $result['groups'][] = $normalized;
        }
        if ($result['components'] === [] && $result['groups'] === []) self::fail('set_empty');
        foreach (self::currencyMap($input['prices'] ?? []) as $currency => $price) {
            if (!is_array($price) || array_diff(array_keys($price), ['mode', 'discount_pct', 'fixed_price']) !== []) self::fail('set_price_invalid');
            $mode = $price['mode'] ?? 'sum';
            if (!in_array($mode, ['sum', 'discount', 'fixed'], true)) self::fail('set_price_invalid');
            $discount = $mode === 'discount' ? self::decimal($price['discount_pct'] ?? null, 3, 3, true) : null;
            if ($discount !== null && bccomp($discount, '100', 3) > 0) self::fail('set_price_invalid');
            $result['prices'][$currency] = ['mode' => $mode, 'discount_pct' => $discount, 'fixed_price' => $mode === 'fixed' ? self::money($price['fixed_price'] ?? null) : null];
        }
        return $result;
    }

    public static function quantity(mixed $value): string
    {
        return self::decimal($value, 11, 3, false);
    }

    public static function money(mixed $value): string
    {
        return self::decimal($value, 12, 2, true);
    }

    public static function multiply(string $first, string $second): string
    {
        $exact = bcmul($first, $second, 6);
        $rounded = bcadd($exact, '0', 3);
        if (bccomp($exact, $rounded, 6) !== 0 || bccomp($rounded, '99999999999.999', 3) > 0 || bccomp($rounded, '0', 3) <= 0) self::fail('set_quantity_unrepresentable');
        return $rounded;
    }

    public static function references(array $definition): array
    {
        $ids = array_column($definition['components'], 'item_id');
        foreach ($definition['groups'] as $group) array_push($ids, ...array_column($group['options'], 'item_id'));
        return array_values(array_unique($ids));
    }

    private static function component(array $row): array
    {
        if (!is_int($row['item_id'] ?? null) || $row['item_id'] <= 0) self::fail('set_component_invalid');
        return ['item_id' => $row['item_id'], 'quantity' => self::quantity($row['quantity'] ?? null)];
    }

    private static function decimal(mixed $value, int $digits, int $scale, bool $zero): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,' . $digits . '}(?:\.\d{1,' . $scale . '})?$/D', (string) $value)) self::fail('set_decimal_invalid');
        $normalized = bcadd((string) $value, '0', $scale);
        if (!$zero && bccomp($normalized, '0', $scale) <= 0) self::fail('set_decimal_invalid');
        return $normalized;
    }

    private static function rows(mixed $rows, int $max): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) self::fail('set_definition_invalid');
        foreach ($rows as $row) if (!is_array($row) || array_is_list($row)) self::fail('set_definition_invalid');
        return $rows;
    }

    private static function currencyMap(mixed $rows): array
    {
        if (!is_array($rows) || count($rows) > 20) self::fail('set_currency_invalid');
        foreach ($rows as $code => $_) if (!is_string($code) || !preg_match('/^[A-Z]{3}$/D', $code)) self::fail('set_currency_invalid');
        ksort($rows);
        return $rows;
    }

    private static function code(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $value)) self::fail('set_code_invalid');
        return $value;
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 150) self::fail('set_name_invalid');
        return trim($value);
    }

    private static function fail(string $code): never
    {
        throw new EshopException($code, 'Neplatná definice setu.', 422);
    }
}
