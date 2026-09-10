<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

/**
 * Porovnání převzatých počátečních stavů s vypočtenými — ČISTÁ třída bez DB.
 *
 * Následující rok může mít otevírací zápis, který nevznikl otevřením roku v průvodci
 * (převod z jiného systému, ručně zadaná otevírací rozvaha). Otevření roku ho proto
 * nezakládá znovu, ale srovná účet po účtu s tím, co by samo zaúčtovalo
 * ({@see ClosingEntryBuilder::openingLines}). Při shodě převzatý zápis platí, při
 * rozdílu se nepokračuje bez výslovného rozhodnutí účetní.
 *
 * Klíč účtu: rozvažné účty 70x se vynechávají (v obou zápisech končí na nule, liší se
 * jen tím, jak kdo řádky párovat), 431 se srovnává za syntetiku — průvodce dává VH na
 * `431`, jiný systém ho často vede na analytice.
 */
final class OpeningTakeoverComparison
{
    public static function accountKey(string $code): ?string
    {
        if (str_starts_with($code, '70')) {
            return null;
        }
        return str_starts_with($code, ClosingEntryBuilder::ACCOUNT_RETAINED_RESULT)
            ? ClosingEntryBuilder::ACCOUNT_RETAINED_RESULT
            : $code;
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $expectedLines řádky vypočteného otevíracího zápisu
     * @param array<string,float> $existingBalances účet => signed netto převzatých zápisů (MD kladně)
     * @return array{diff: list<array{account_code:string, expected:float, existing:float, difference:float}>, accounts:int}
     */
    public static function compare(array $expectedLines, array $existingBalances): array
    {
        $expected = [];
        foreach ($expectedLines as $line) {
            $key = self::accountKey((string) $line['account_code']);
            if ($key === null) {
                continue;
            }
            $cents = self::cents((float) $line['amount']);
            $expected[$key] = ($expected[$key] ?? 0) + ($line['side'] === 'debit' ? $cents : -$cents);
        }

        $existing = [];
        foreach ($existingBalances as $code => $balance) {
            $key = self::accountKey((string) $code);
            if ($key !== null) {
                $existing[$key] = ($existing[$key] ?? 0) + self::cents((float) $balance);
            }
        }

        $keys = array_map('strval', array_unique(array_merge(array_keys($expected), array_keys($existing))));
        sort($keys, SORT_STRING);

        $diff = [];
        foreach ($keys as $key) {
            $mine = $expected[$key] ?? 0;
            $theirs = $existing[$key] ?? 0;
            if ($mine !== $theirs) {
                $diff[] = [
                    'account_code' => $key,
                    'expected' => $mine / 100,
                    'existing' => $theirs / 100,
                    'difference' => ($mine - $theirs) / 100,
                ];
            }
        }

        return [
            'diff' => $diff,
            'accounts' => count(array_filter($expected, static fn (int $c): bool => $c !== 0)),
        ];
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
