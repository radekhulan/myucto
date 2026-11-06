<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Service\Accounting\Charges\ChargeAllocationEngine;
use MyInvoice\Service\Accounting\Charges\ExactFraction;

final class MembershipChargeCalculator
{
    public static function calculate(string $amount, string $mode, array $members): array
    {
        $subjects = [];
        foreach ($members as $member) $subjects[] = ['id'=>$member['member_id'] ?? null, 'basis'=>$member['basis'] ?? null];
        $result = ChargeAllocationEngine::allocate(ExactFraction::decimalCents($amount), $subjects, $mode);
        $lines = array_map(static fn(array $line): array => ['member_id'=>$line['subject_id']] + array_diff_key($line, ['subject_id'=>true]), $result['lines']);
        return ['rounding'=>ChargeAllocationEngine::ROUNDING, 'basis_total'=>$result['basis_total'], 'total_cents'=>$result['total_cents'], 'lines'=>$lines];
    }
}
