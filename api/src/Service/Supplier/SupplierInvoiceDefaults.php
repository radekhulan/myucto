<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Service\Invoice\DefaultInvoiceNote;

/** Sdílený výřez provozních výchozích hodnot pro nové doklady, bez daňových voleb. */
final class SupplierInvoiceDefaults
{
    public const FIELDS = [
        'default_payment_due_days',
        'default_payment_due_unit',
        'default_hourly_rate',
        DefaultInvoiceNote::ENABLED_COLUMN,
        DefaultInvoiceNote::COLUMNS['cs'],
        DefaultInvoiceNote::COLUMNS['en'],
    ];

    public const NOTE_MAX_LENGTH = 5000;
    // Zachováme rozsah existujícího INT UNSIGNED; delegace nemění platné firemní hodnoty.
    public const MAX_DUE_VALUE = 4294967295;

    /** Zprázdněná splatnost ponechá dosavadní hodnotu, prázdná sazba je nula. */
    public static function validate(array $body): array
    {
        if (array_key_exists('default_payment_due_days', $body)) {
            $value = $body['default_payment_due_days'];
            if (is_string($value)) $value = trim($value);
            if ($value === null || $value === '') {
                unset($body['default_payment_due_days']);
            } elseif ((!is_int($value) && !is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_DUE_VALUE]]) === false) {
                throw new \InvalidArgumentException('Výchozí splatnost musí být celé číslo od 0 do ' . self::MAX_DUE_VALUE . '.');
            } else {
                $body['default_payment_due_days'] = (int) $value;
            }
        }
        if (array_key_exists('default_payment_due_unit', $body)
            && !in_array($body['default_payment_due_unit'], ['days', 'month'], true)) {
            throw new \InvalidArgumentException("default_payment_due_unit musí být 'days' nebo 'month'.");
        }
        if (array_key_exists('default_hourly_rate', $body)) {
            $body['default_hourly_rate'] = self::rate($body['default_hourly_rate']);
        }
        if (array_key_exists(DefaultInvoiceNote::ENABLED_COLUMN, $body)) {
            $value = $body[DefaultInvoiceNote::ENABLED_COLUMN];
            if (!is_bool($value) && !in_array($value, [0, 1], true)) {
                throw new \InvalidArgumentException('Přepínač výchozí poznámky musí mít logickou hodnotu.');
            }
            $body[DefaultInvoiceNote::ENABLED_COLUMN] = (int) (bool) $value;
        }
        foreach (DefaultInvoiceNote::COLUMNS as $column) {
            if (!array_key_exists($column, $body)) continue;
            $value = $body[$column];
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("$column musí být text.");
            }
            $text = trim($value ?? '');
            if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
                throw new \InvalidArgumentException('Výchozí poznámka pod položkami je delší než ' . self::NOTE_MAX_LENGTH . ' znaků.');
            }
            $body[$column] = $text === '' ? null : $text;
        }
        return $body;
    }

    /** Decimal(14,6) kanonicky bez exponentu; nikdy nenecháme DB zaokrouhlit vstup. */
    private static function rate(mixed $value): string
    {
        $error = 'Výchozí hodinová sazba musí být číslo od 0 do 99999999,999999, nejvýše se šesti desetinnými místy.';
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > 99999999.999999) {
                throw new \InvalidArgumentException($error);
            }
            $formatted = number_format($value, 6, '.', '');
            if ((float) $formatted !== $value) throw new \InvalidArgumentException($error);
            $value = $formatted;
        }
        if ($value !== null && !is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException($error);
        }
        $rate = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) ($value ?? '')));
        if ($rate === '') $rate = '0';
        if (preg_match('/^([0-9]+)(?:\.([0-9]{1,6}))?$/D', $rate, $match) !== 1) {
            throw new \InvalidArgumentException($error);
        }
        $integer = ltrim($match[1], '0');
        if (strlen($integer) > 8) throw new \InvalidArgumentException($error);
        return ($integer === '' ? '0' : $integer) . '.' . str_pad($match[2] ?? '', 6, '0');
    }

    /** Validovaný vstup vs uložená hodnota; text ani čísla nejsou logické přepínače. */
    public static function sameValue(string $field, mixed $value, mixed $stored): bool
    {
        return match ($field) {
            'default_payment_due_days' => (int) $value === (int) $stored,
            'default_payment_due_unit' => $value === ($stored ?? 'days'),
            'default_hourly_rate' => self::rate($value) === self::rate($stored),
            DefaultInvoiceNote::ENABLED_COLUMN => (bool) $value === (bool) $stored,
            default => trim((string) ($value ?? '')) === trim((string) ($stored ?? '')),
        };
    }

    public static function normalizeResponse(array $row): array
    {
        $row['default_payment_due_days'] = (int) ($row['default_payment_due_days'] ?? 7);
        $row['default_payment_due_unit'] = (string) ($row['default_payment_due_unit'] ?? 'days');
        $row['default_hourly_rate'] = (float) ($row['default_hourly_rate'] ?? 1500);
        $row[DefaultInvoiceNote::ENABLED_COLUMN] = (bool) ($row[DefaultInvoiceNote::ENABLED_COLUMN] ?? false);
        foreach (DefaultInvoiceNote::COLUMNS as $column) {
            $row[$column] = isset($row[$column]) ? (string) $row[$column] : null;
        }
        return $row;
    }
}
