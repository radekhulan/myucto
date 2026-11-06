<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Services\Settlement;

use MyInvoice\Service\Property\PropertyInput;

final class SettlementAmounts
{
    public static function calculate(mixed $prescribedCents, mixed $paidCents, mixed $actualCents): array
    {
        $prescribed = self::cents($prescribedCents, 'prescribed_cents');
        $paid = self::cents($paidCents, 'paid_cents');
        $actual = self::cents($actualCents, 'actual_cents');
        if (bccomp($paid, $prescribed, 0) > 0) PropertyInput::invalid('paid_cents');

        $applied = bccomp($prescribed, $actual, 0) <= 0 ? $prescribed : $actual;
        $newClaim = bccomp($actual, $prescribed, 0) > 0 ? bcsub($actual, $prescribed, 0) : '0';
        $unpaid = bcsub($prescribed, $paid, 0);
        $unused = bcsub($prescribed, $applied, 0);
        $cancelled = bccomp($unused, $unpaid, 0) <= 0 ? $unused : $unpaid;
        $credit = bccomp($paid, $actual, 0) > 0 ? bcsub($paid, $actual, 0) : '0';

        return [
            'advance_applied_cents' => $applied,
            'new_claim_cents' => $newClaim,
            'cancelled_unpaid_cents' => $cancelled,
            'new_credit_cents' => $credit,
            'remaining_old_claim_cents' => bcsub($unpaid, $cancelled, 0),
        ];
    }

    private static function cents(mixed $value, string $field): string
    {
        if (!is_string($value) || strlen($value) > 30 || !preg_match('/^(0|[1-9][0-9]{0,29})$/D', $value)) {
            PropertyInput::invalid($field);
        }
        return $value;
    }
}
