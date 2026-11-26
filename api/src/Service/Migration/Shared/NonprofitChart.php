<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

final class NonprofitChart
{
    /** @param iterable<string> $codes */
    public static function matches(iterable $codes): bool
    {
        $hasEquity504 = false;
        foreach ($codes as $code) {
            if (str_starts_with($code, '411')) {
                return false;
            }
            $hasEquity504 = $hasEquity504 || str_starts_with($code, '901') || str_starts_with($code, '931');
        }
        return $hasEquity504;
    }
}
