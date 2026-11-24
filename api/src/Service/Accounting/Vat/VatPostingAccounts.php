<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Vat;

use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Vat\VatRateResolver;

final class VatPostingAccounts
{
    public const SIDES = ['input' => 'debit', 'output' => 'credit'];
    public const FALLBACKS = ['input' => PostingService::INPUT_VAT_ACCOUNT, 'output' => PostingService::OUTPUT_VAT_ACCOUNT];

    /** @return array{0:string,1:string}|null */
    public static function parseRateRule(string $key): ?array
    {
        if (preg_match('/^invoice\.vat\.(input|output)\.(\d{1,3}(?:\.\d{1,2})?)$/D', $key, $match) !== 1
            || (float) $match[2] <= 0 || VatRateResolver::rateKey((float) $match[2]) !== $match[2]) {
            return null;
        }
        return [$match[1], $match[2]];
    }

    public static function ruleCode(array $rules, string $key, string $side): ?string
    {
        $code = $rules[$key][$side . '_account_code'] ?? null;
        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function common(array $rules, string $direction): string
    {
        return self::ruleCode($rules, 'invoice.vat.' . $direction, self::SIDES[$direction]) ?? self::FALLBACKS[$direction];
    }

    public static function settlement(array $rules): string
    {
        return self::ruleCode($rules, 'vat.clearing.output', 'credit') ?? PostingService::VAT_SETTLEMENT_ACCOUNT;
    }

    /** @return array<string,array<int|string,string>> */
    public static function byRate(array $rules): array
    {
        $result = ['input' => [], 'output' => []];
        foreach ($rules as $key => $rule) {
            $parsed = self::parseRateRule($key);
            if ($parsed === null) {
                continue;
            }
            [$direction, $rate] = $parsed;
            $code = self::ruleCode($rules, $key, self::SIDES[$direction]);
            if ($code !== null) {
                $result[$direction][$rate] = $code;
            }
        }
        return $result;
    }

    /** Vrátí syntetický účet podle skutečné hierarchie osnovy. */
    public static function rootCode(array $chart, string $code): ?string
    {
        $byId = array_column($chart, null, 'id');
        $account = array_column($chart, null, 'account_code')[$code] ?? null;
        for ($remaining = count($chart); $account !== null && $remaining > 0; $remaining--) {
            if ($account['parent_id'] === null) {
                return $account['is_synthetic'] ? $account['account_code'] : null;
            }
            $account = $byId[$account['parent_id']] ?? null;
        }
        return null;
    }

    public static function validationError(array $chart, array $rules, string $direction, ?string $debit, ?string $credit): ?string
    {
        if (($direction === 'input' ? $credit : $debit) !== null) {
            return 'Účet DPH podle sazby lze vyplnit jen na straně daně.';
        }
        $code = $direction === 'input' ? $debit : $credit;
        if ($code === null) {
            return null;
        }
        $settlement = self::settlement($rules);
        $common = self::common($rules, $direction);
        $root = self::rootCode($chart, $common) ?? PostingService::VAT_SYNTHETIC;
        if ($code === $settlement || empty(array_column($chart, 'is_active', 'account_code')[$code])
            || self::rootCode($chart, $code) !== $root) {
            return "Vyberte aktivní účet pod syntetikou {$root}, odlišný od zúčtovacího účtu DPH.";
        }
        return null;
    }

    /**
     * Rozpad zachová celkovou daň dokladu, včetně zaokrouhlení poměrného odpočtu.
     * @param array<int|string,float> $byRate
     * @param array<int|string,string> $accounts
     * @return array<int|string,float>
     */
    public static function split(array $byRate, array $accounts, string $common, float $total, float $share = 1.0): array
    {
        if (array_intersect_key($accounts, $byRate) === []) {
            return $total === 0.0 ? [] : [$common => $total];
        }

        $amounts = [];
        foreach ($byRate as $rate => $amount) {
            $code = $accounts[$rate] ?? $common;
            $amounts[$code] = ($amounts[$code] ?? 0.0) + $amount * $share;
        }
        $amounts = array_map(static fn (float $amount): float => round($amount, 2), $amounts);
        $absolute = array_map('abs', $amounts);
        $largest = array_search(max($absolute), $absolute, true);
        $amounts[$largest] = round($amounts[$largest] + $total - array_sum($amounts), 2);

        return array_filter($amounts, static fn (float $amount): bool => $amount !== 0.0);
    }
}
