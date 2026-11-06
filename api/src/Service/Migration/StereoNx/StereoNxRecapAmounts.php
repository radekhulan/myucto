<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\JournalLineAmount;

/** Důkazy prázdných volitelných polí NX; povinné částky nadále ověřují převodníky. */
final class StereoNxRecapAmounts
{
    /** NX1 částka musí být číselného typu; převod haléřů sdílí účetní jádro. */
    public static function moneyCents(mixed $value): ?int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 999999999999.99) return null;
        return JournalLineAmount::cents($value);
    }

    /** @param array<string,mixed> $header */
    public static function isUnusedBucket(array $header, string $slot): bool
    {
        if (!in_array($slot, ['z', 's', 't', '0'], true)) return false;
        $fields = $slot === '0' ? ['BezDane'] : ['ZaklDPH' . $slot, 'DPH' . $slot];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $header)) return false;
            $value = $header[$field];
            if ($value !== null && ((!is_int($value) && !is_float($value)) || (float) $value !== 0.0)) return false;
        }
        return true;
    }

    /** NULL zaokrouhlení lze vynechat jen při nezávislé shodě položek s povinným celkem. */
    public static function isUnassignedZeroRounding(array $header, float $gross, float $sourceTotal): bool
    {
        return array_key_exists('Zaokrouhleni', $header) && $header['Zaokrouhleni'] === null
            && is_finite($gross) && is_finite($sourceTotal)
            && (int) round($gross * 100) === (int) round($sourceTotal * 100);
    }
}
