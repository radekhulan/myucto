<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

final class CanonicalJson
{
    /** @param array<array-key, mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(
            self::sort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sort(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
