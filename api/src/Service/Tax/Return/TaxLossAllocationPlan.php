<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

final class TaxLossAllocationPlan
{
    public static function build(array $rows, int $year, float $requested, int $carryYears, int $carrybackYears, float $carrybackLimit): array
    {
        $requestedCents = self::cents($requested);
        if (bccomp($requestedCents, '0', 0) < 0) throw new \InvalidArgumentException('Loss allocation cannot be negative.');
        usort($rows, static fn (array $a, array $b): int => [(int) $a['origin_year'], (int) $a['id']] <=> [(int) $b['origin_year'], (int) $b['id']]);
        $available = '0'; $left = $requestedCents; $chunks = [];
        foreach ($rows as $row) {
            $origin = (int) $row['origin_year'];
            if (!self::eligibleOrigin($origin, $year, $carryYears, $carrybackYears)) continue;
            $remaining = bcsub(self::cents($row['amount']), self::cents($row['applied']), 0);
            if ($origin > $year) {
                $room = bcsub(self::cents($carrybackLimit), self::cents($row['carried_back']), 0);
                if (bccomp($room, $remaining, 0) < 0) $remaining = $room;
            }
            if (bccomp($remaining, '0', 0) <= 0) continue;
            $available = bcadd($available, $remaining, 0);
            $chunk = bccomp($remaining, $left, 0) < 0 ? $remaining : $left;
            if (bccomp($chunk, '0', 0) > 0) {
                $chunks[] = ['loss_id' => (int) $row['id'], 'origin_year' => $origin, 'amount_cents' => $chunk];
                $left = bcsub($left, $chunk, 0);
            }
        }
        return ['requested_cents' => $requestedCents, 'available_cents' => $available, 'allocated_cents' => bcsub($requestedCents, $left, 0), 'unallocated_cents' => $left, 'chunks' => $chunks];
    }

    public static function eligibleOrigin(int $originYear, int $appliedYear, int $carryYears, int $carrybackYears): bool
    {
        return $originYear !== $appliedYear
            && $originYear >= $appliedYear - $carryYears
            && $originYear <= $appliedYear + $carrybackYears;
    }

    public static function cents(float|string $amount): string
    {
        if (is_float($amount)) {
            if (!is_finite($amount)) throw new \InvalidArgumentException('Invalid loss amount.');
            $amount = sprintf('%.2F', $amount);
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $amount) !== 1) throw new \InvalidArgumentException('Invalid loss amount.');
        return bcmul($amount, '100', 0);
    }

    public static function amount(string $cents): string
    {
        return bcdiv($cents, '100', 2);
    }
}
