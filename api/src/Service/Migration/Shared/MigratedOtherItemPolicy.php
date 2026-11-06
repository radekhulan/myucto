<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Společná pravidla převodu ostatních pohledávek a závazků z cizích programů do agendy
 * Ostatní pohledávky a závazky (`other_items`).
 *
 * Předpisy mezd, odvodů a DPH (účty 331, 336, 341, 342, 343) vlastní mzdový a daňový
 * modul, převod je mezi ostatní položky nezakládá a nechá je jen v převzatém deníku.
 */
final class MigratedOtherItemPolicy
{
    /** Saldokontní účty, jejichž předpisy vlastní mzdy, DPH a daň z příjmů. */
    private const MODULE_ACCOUNTS = ['331', '336', '341', '342', '343'];

    public static function moduleOwned(string $account): bool
    {
        return in_array(substr(preg_replace('/\D+/', '', $account) ?? '', 0, 3), self::MODULE_ACCOUNTS, true);
    }

    /**
     * Kontace dokladu z jeho zápisu v deníku: na straně pohledávky (MD) či závazku (D)
     * jediný rozvahový účet v celé částce dokladu, na druhé straně protiúčty se stejným
     * součtem. Protiúčty se slučují podle účtu v pořadí řádků.
     *
     * @param list<array{side:string,amount:string|float,account_code:string,account_type?:string}> $rows
     * @param 'receivable'|'payable' $side
     * @return array{account:string,lines:list<array{account_code:string,amount:float}>}|null
     */
    public static function postingLines(array $rows, string $side, float $amount): ?array
    {
        if (!in_array($side, ['receivable', 'payable'], true) || $rows === []) return null;
        $balanceSide = $side === 'receivable' ? 'debit' : 'credit';
        $balanceType = $side === 'receivable' ? 'asset' : 'liability';
        $balance = null;
        $balanceSum = 0;
        $counter = [];
        $counterSum = 0;
        foreach ($rows as $row) {
            $code = (string) $row['account_code'];
            $cents = (int) round((float) $row['amount'] * 100);
            if ($row['side'] === $balanceSide) {
                if ($balance !== null && $balance !== $code) return null;
                if (($row['account_type'] ?? $balanceType) !== $balanceType) return null;
                $balance = $code;
                $balanceSum += $cents;
            } else {
                $counter[$code] = ($counter[$code] ?? 0) + $cents;
                $counterSum += $cents;
            }
        }
        $total = (int) round($amount * 100);
        if ($balance === null || $balanceSum !== $total || $counterSum !== $total) return null;
        $lines = [];
        foreach ($counter as $code => $cents) {
            $code = (string) $code;
            if ($code === $balance || preg_match('/^(315|325)(?:$|[.\-])/', $code) === 1 || $cents <= 0) return null;
            $lines[] = ['account_code' => $code, 'amount' => $cents / 100.0];
        }
        return ['account' => $balance, 'lines' => $lines];
    }
}
