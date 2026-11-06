<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Comparator
{
    public static function decimal(int|float|string $value): ?string
    {
        $text = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : (string) $value;
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $text, $m)) {
            return null;
        }
        $exponent = (int) ($m[4] ?? 0);
        if (abs($exponent) > 1000 || strlen($text) > 10000) {
            throw new \RuntimeException('decimal_range');
        }
        $fraction = $m[3] ?? '';
        $digits = $m[2] . $fraction;
        $point = strlen($m[2]) + $exponent;
        if ($point < 0) {
            $digits = str_repeat('0', -$point) . $digits;
            $point = 0;
        }
        if ($point > strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }
        $integer = ltrim(substr($digits, 0, $point), '0');
        $fraction = rtrim(substr($digits, $point), '0');
        $normalized = ($integer === '' ? '0' : $integer) . ($fraction === '' ? '' : '.' . $fraction);
        return ($m[1] === '-' && $normalized !== '0' ? '-' : '') . $normalized;
    }

    public static function values(mixed $left, mixed $right): array
    {
        $differences = [];
        $numericCount = 0;
        self::walk($left, $right, '$', '', $differences, $numericCount);
        return ['equal' => $differences === [], 'numeric_count' => $numericCount, 'differences' => $differences];
    }

    public static function isIdentifierField(string $key): bool
    {
        return in_array($key, ['original_doc_number', 'document_number', 'doc_number', 'document_no', 'invoice_number', 'vendor_invoice_number', 'purchase_number', 'doc_no', 'invoice_no', 'varsymbol'], true)
            || preg_match('/(?:^|_)(?:symbol|reference|ref|hash|sha256|key)$/D', $key) === 1;
    }

    private static function walk(mixed $left, mixed $right, string $path, string $key, array &$diff, int &$count): void
    {
        if (is_array($left) && is_array($right)) {
            if ($left !== [] && $right !== [] && array_is_list($left) !== array_is_list($right)) {
                $diff[] = ['path' => $path, 'kind' => 'container_type'];
                return;
            }
            $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
            foreach ($keys as $child) {
                if (!array_key_exists($child, $left) || !array_key_exists($child, $right)) {
                    $diff[] = ['path' => $path . '/' . $child, 'kind' => !array_key_exists($child, $left) ? 'added_field' : 'removed_field'];
                    continue;
                }
                self::walk($left[$child], $right[$child], $path . '/' . $child, is_string($child) ? $child : $key, $diff, $count);
            }
            return;
        }
        $numeric = !self::isIdentifierField($key) && !preg_match('/(^id$|_id$|^year$|^month$|^fiscal_year$|^code$|_code$|^level$|^sort_order$|^row_version$|^accounting_decree$)/', $key);
        if ($numeric && (is_int($left) || is_float($left) || is_string($left)) && (is_int($right) || is_float($right) || is_string($right))) {
            $a = self::decimal($left);
            $b = self::decimal($right);
            if ($a !== null && $b !== null) {
                ++$count;
                if ($a !== $b) {
                    $diff[] = ['path' => $path, 'kind' => 'number'];
                }
                return;
            }
        }
        if ($left !== $right) {
            $diff[] = ['path' => $path, 'kind' => 'value_or_type'];
        }
    }

    public static function captures(array $left, array $right): array
    {
        $errors = [];
        foreach (['baseline' => $left, 'candidate' => $right] as $name => $capture) {
            if (($capture['status'] ?? null) !== 'ok') {
                $errors[] = 'capture_failed_' . $name;
            }
            $planned = array_keys($capture['manifest'] ?? []);
            $completed = array_keys($capture['cases'] ?? []);
            sort($planned);
            sort($completed);
            if ($planned !== $completed) {
                $errors[] = 'capture_incomplete_' . $name;
            }
        }
        foreach (['format', 'dataset_id', 'as_of', 'execution_date', 'coverage', 'manifest'] as $field) {
            if (!array_key_exists($field, $left) || !array_key_exists($field, $right) || $left[$field] !== $right[$field]) {
                $errors[] = 'capture_identity_' . $field;
            }
        }
        $differences = [];
        $numeric = 0;
        $a = $left['cases'] ?? [];
        $b = $right['cases'] ?? [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
            if (($a[$id]['status'] ?? null) !== 'ok' || ($b[$id]['status'] ?? null) !== 'ok') {
                $errors[] = 'case_failed_' . $id;
                continue;
            }
            $result = self::values($a[$id]['data'], $b[$id]['data']);
            $numeric += $result['numeric_count'];
            if (!$result['equal']) {
                $differences[$id] = $result['differences'];
            }
        }
        if ($numeric === 0) {
            $errors[] = 'no_numeric_coverage';
        }
        return ['status' => $errors === [] && $differences === [] ? 'ok' : 'error', 'numeric_count' => $numeric, 'errors' => $errors, 'differences' => $differences];
    }
}
