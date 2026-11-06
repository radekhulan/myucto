<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

final class PaymentCoverage
{
    public static function covered(float $prior, float $amount, float $gross): float
    {
        $gross = max(0.0, $gross);
        return min(max($prior + $amount, 0.0), $gross) - min(max($prior, 0.0), $gross);
    }

    public static function overpaidShare(float $prior, float $amount, float $gross): float
    {
        if (abs($amount) < 0.005) return 0.0;
        return max(0.0, min(1.0, ($amount - self::covered($prior, $amount, $gross)) / $amount));
    }
}
