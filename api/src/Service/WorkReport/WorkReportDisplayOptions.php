<?php

declare(strict_types=1);

namespace MyInvoice\Service\WorkReport;

/** Sdílená pravidla zobrazení práce v PDF, veřejném náhledu a schvalování. */
final class WorkReportDisplayOptions
{
    public const DEFAULTS = ['compact' => false, 'date' => true, 'hours' => true, 'rate' => true, 'amount' => true];

    /** Vstup z API: null ruší výjimku, neznámé klíče a nelogické hodnoty odmítáme. */
    public static function validate(mixed $value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('Zobrazení výkazu musí být objekt nebo null.');
        }
        foreach ($value as $key => $enabled) {
            if (!array_key_exists($key, self::DEFAULTS) || !is_bool($enabled)) {
                throw new \InvalidArgumentException('Neplatná volba zobrazení výkazu: ' . $key . '.');
            }
        }
        return $value === [] ? null : $value;
    }

    /** JSON ze sloupce nebo pole již dekódované v aplikaci. */
    public static function decode(mixed $value): ?array
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) && $value !== [] ? array_intersect_key($value, self::DEFAULTS) : null;
    }

    /** Jednotlivé volby dědí firma → výkaz; explicitní false musí zůstat false. */
    public static function resolve(mixed $defaults = null, mixed $override = null): array
    {
        return array_replace(self::DEFAULTS, self::decode($defaults) ?? [], self::decode($override) ?? []);
    }

    /** Skryté hodnoty neposíláme ani v JSON veřejného výkazu. Interní data zůstávají úplná. */
    public static function forPublic(array $report): array
    {
        $options = $report['effective_display_options'] ?? self::resolve(null, $report['display_options'] ?? null);
        $report['effective_display_options'] = $options;
        foreach ($report['items'] as &$item) {
            if (!$options['date']) $item['work_date'] = null;
            if (!$options['hours']) {
                $item['hours'] = null;
                $item['duration_minutes'] = null;
            }
            if (!$options['rate']) $item['rate'] = null;
            if (!$options['amount']) $item['total_amount'] = null;
        }
        unset($item);
        if (!$options['hours']) $report['total_hours'] = null;
        if (!$options['amount']) $report['total_amount'] = null;
        return $report;
    }
}
