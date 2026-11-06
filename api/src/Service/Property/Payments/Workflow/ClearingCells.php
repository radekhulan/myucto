<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Service\Property\Payments\PaymentException;

final class ClearingCells
{
    public static function normalize(string $total, array $input): array
    {
        self::amount($total);
        if (!array_is_list($input) || $input === []) self::reject('clearing_cells_invalid');
        $cells = [];
        $sum = '0';
        foreach ($input as $cell) {
            if (!is_array($cell) || array_diff(array_keys($cell), ['key', 'amount_cents', 'dimensions']) !== []) self::reject('clearing_cells_invalid');
            $key = self::key($cell['key'] ?? null);
            if (isset($cells[$key])) self::reject('clearing_cell_duplicate', ['cell_key' => $key]);
            $amount = self::amount($cell['amount_cents'] ?? null);
            $dimensions = $cell['dimensions'] ?? null;
            if (!is_array($dimensions)) self::reject('clearing_dimensions_invalid', ['cell_key' => $key]);
            foreach ($dimensions as $type => $value) {
                if (!is_int($type) || $type <= 0 || !is_int($value) || $value <= 0) self::reject('clearing_dimensions_invalid', ['cell_key' => $key]);
            }
            ksort($dimensions, SORT_NUMERIC);
            $cells[$key] = ['key' => $key, 'amount_cents' => $amount, 'dimensions' => $dimensions];
            $sum = bcadd($sum, $amount, 0);
        }
        if ($sum !== $total) self::reject('clearing_margin_mismatch', ['source_cents' => $total, 'cells_cents' => $sum]);
        ksort($cells, SORT_STRING);
        return array_values($cells);
    }

    public static function consume(array $cells, array $consumed, string $amount, ?array $selection, array $nativeConsumed = [], bool $pinned = false): array
    {
        self::amount($amount);
        $available = self::available($cells, $consumed);
        if ($selection === null) {
            if (count($available) !== 1) self::reject('clearing_selection_required');
            $selection = [['key' => array_key_first($available), 'amount_cents' => $amount]];
        }
        if (!array_is_list($selection) || $selection === []) self::reject('clearing_selection_invalid');
        $result = [];
        $sum = '0';
        foreach ($selection as $part) {
            if (!is_array($part) || array_diff(array_keys($part), ['key', 'amount_cents', 'native_minor']) !== []) self::reject('clearing_selection_invalid');
            $key = self::key($part['key'] ?? null);
            $partAmount = self::amount($part['amount_cents'] ?? null);
            if (isset($result[$key]) || !array_key_exists($key, $available)) self::reject('clearing_selection_invalid', ['cell_key' => $key]);
            if (bccomp($partAmount, $available[$key], 0) > 0) self::reject('clearing_capacity', ['cell_key' => $key, 'available_cents' => $available[$key], 'requested_cents' => $partAmount]);
            $selected = ['key' => $key, 'amount_cents' => $partAmount];
            $cell = null;
            foreach ($cells as $candidate) if (($candidate['key'] ?? null) === $key) { $cell = $candidate; break; }
            $native = self::native($cell, $consumed[$key] ?? '0', $nativeConsumed[$key] ?? '0', $partAmount, $pinned);
            if ($native === null) {
                if (array_key_exists('native_minor', $part)) self::reject('clearing_selection_invalid', ['cell_key' => $key]);
            } else {
                if (array_key_exists('native_minor', $part)) {
                    $selectedNative = self::amount($part['native_minor']);
                    if ((!$pinned && $selectedNative !== $native['amount']) || bccomp($selectedNative, bcsub($native['total'], $native['used'], 0), 0) > 0) self::reject('clearing_selection_invalid', ['cell_key' => $key]);
                    $native = $selectedNative;
                } else {
                    if ($pinned) self::reject('clearing_selection_invalid', ['cell_key' => $key]);
                    $native = $native['amount'];
                }
                $selected['native_minor'] = $native;
            }
            $result[$key] = $selected;
            $sum = bcadd($sum, $partAmount, 0);
        }
        if ($sum !== $amount) self::reject('clearing_selection_margin', ['requested_cents' => $amount, 'selected_cents' => $sum]);
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    public static function available(array $cells, array $consumed): array
    {
        $available = [];
        foreach ($cells as $cell) {
            $key = self::key($cell['key'] ?? null);
            if (isset($available[$key])) self::reject('clearing_cell_duplicate', ['cell_key' => $key]);
            $capacity = self::amount($cell['amount_cents'] ?? null, true);
            $used = self::amount($consumed[$key] ?? '0', true);
            if (bccomp($used, $capacity, 0) > 0) self::reject('clearing_capacity_corrupt', ['cell_key' => $key]);
            $available[$key] = bcsub($capacity, $used, 0);
        }
        if ($available === [] || array_diff(array_keys($consumed), array_keys($available)) !== []) self::reject('clearing_capacity_corrupt');
        return $available;
    }

    private static function amount(mixed $value, bool $zero = false): string
    {
        if (!is_string($value) || !preg_match($zero ? '/^(0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value)) self::reject('clearing_amount_invalid');
        return $value;
    }

    private static function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,79}$/D', $value)) self::reject('clearing_cell_key_invalid');
        return $value;
    }

    private static function native(?array $cell, string $used, string $nativeUsed, string $part, bool $pinned): ?array
    {
        $line = $cell['line'] ?? null;
        if (!is_array($line)) return null;
        $currency = $line['currency_code'] ?? null;
        $foreign = $line['amount_foreign'] ?? null;
        $rate = $line['fx_rate'] ?? null;
        if ($currency === null && $foreign === null && $rate === null) return null;
        if (!is_string($currency) || $currency === 'CZK' || !is_string($foreign) || !is_string($rate)) self::reject('clearing_fx_invalid');
        $totalNative = bcmul(\MyInvoice\Service\Accounting\JournalLineAmount::decimal($foreign), '100', 0);
        $totalCzk = self::amount($cell['amount_cents'] ?? null);
        $nativeUsed = self::amount($nativeUsed, true);
        if (bccomp($nativeUsed, $totalNative, 0) > 0) self::reject('clearing_capacity_corrupt', ['cell_key' => $cell['key']]);
        $afterCzk = bcadd($used, $part, 0);
        $native = $afterCzk === $totalCzk ? bcsub($totalNative, $nativeUsed, 0) : bcsub(self::ratio($totalNative, $afterCzk, $totalCzk), $nativeUsed, 0);
        if (!$pinned && bccomp($native, '0', 0) <= 0) self::reject('clearing_fx_unrepresentable', ['cell_key' => $cell['key']]);
        return ['amount' => $native, 'used' => $nativeUsed, 'total' => $totalNative];
    }

    private static function ratio(string $totalNative, string $czk, string $totalCzk): string
    {
        $numerator = bcmul($totalNative, $czk, 0);
        $quotient = bcdiv($numerator, $totalCzk, 0);
        $remainder = bcmod($numerator, $totalCzk, 0);
        return bccomp(bcmul($remainder, '2', 0), $totalCzk, 0) >= 0 ? bcadd($quotient, '1', 0) : $quotient;
    }

    private static function reject(string $code, array $details = []): never
    {
        throw new PaymentException('property_payment_' . $code, 'Zkontrolujte společné zařazení a dostupnou kapacitu uvedené části platby.', 409, $details);
    }
}
