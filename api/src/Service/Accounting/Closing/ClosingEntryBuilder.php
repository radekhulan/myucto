<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\AccountingFrameworkService;

/**
 * ClosingEntryBuilder — ČISTÁ třída bez DB (Epic F4, R8): ze zůstatků účtů
 * sestaví řádky uzavíracího a otevíracího zápisu (ČÚS 002).
 *
 * Strany se určují ZNAMÉNKEM netto zůstatku per KONKRÉTNÍ účet vč. analytik
 * (robustní i pro „přetočené" účty jako 343 v pohledávce). Peníze se porovnávají
 * v HALÉŘÍCH (int), nikdy přes float == (vzor PostingService).
 *
 * Vstupní řádek zůstatku = {account_id, account_code, name, bal} — `bal` je
 * signed netto (MD kladně), viz ClosingRepository::plBalances/bsBalances.
 * Výstupní řádek = {account_code, side: 'debit'|'credit', amount>0} — přesně
 * formát PostingService::postDocument lines; mapování kódů 701/702/710/431 na
 * account_id firmy dělá ClosingService (chybějící účet → missing_account).
 */
final class ClosingEntryBuilder
{
    /** Počáteční účet rozvažný (otevření knih). */
    public const ACCOUNT_OPENING = '701';

    /** Konečný účet rozvažný (uzavření knih). */
    public const ACCOUNT_CLOSING = '702';

    /** Účet zisků a ztrát. */
    public const ACCOUNT_PROFIT_LOSS = '710';

    /** Výsledek hospodaření ve schvalovacím řízení (VH do nového roku). */
    public const ACCOUNT_RETAINED_RESULT = '431';

    /**
     * Řádky uzavíracího zápisu (R8): (a) výsledkové účty proti 710, (b) VH
     * 710↔702, (c) rozvahové účty proti 702. Invariant PŘED zápisem:
     * Σ MD řádků na 702 == Σ D řádků na 702 (702 skončí na nule), jinak
     * ClosingException `closing_unbalanced_702` a nic se nezapisuje.
     *
     * @param list<array{account_id:int, account_code:string, name:string, bal:float}> $plBalances
     * @param list<array{account_id:int, account_code:string, name:string, bal:float}> $bsBalances
     * @return array{lines: list<array{account_code:string, side:'debit'|'credit', amount:float}>, profit: float}
     */
    public function closingLines(array $plBalances, array $bsBalances, ?AccountingRoleProfile $profile = null): array
    {
        $profile ??= new AccountingRoleProfile();
        $lines = [];
        $profitCents = 0;

        // (a) výsledkové páry dle account_code: debetní zůstatek B → MD 710 / D účet;
        //     kreditní → MD účet / D 710. profit = Σ(-bal) (kreditní netto 6xx > 5xx → zisk kladně).
        foreach ($this->sorted($plBalances) as $row) {
            $cents = self::cents((float) $row['bal']);
            if ($cents === 0) {
                continue;
            }
            $amount = abs($cents) / 100;
            $metadata = $profile->framework === AccountingFrameworkService::CZ_504 ? $row : [];
            $code = (string) $row['account_code'];
            if ($cents > 0) {
                $lines[] = self::line($profile->account('result.current'), 'debit', $amount, $metadata);
                $lines[] = self::line($code, 'credit', $amount, $metadata);
            } else {
                $lines[] = self::line($code, 'debit', $amount, $metadata);
                $lines[] = self::line($profile->account('result.current'), 'credit', $amount, $metadata);
            }
            $profitCents += -$cents;
        }

        $resultBalances = $profile->framework === AccountingFrameworkService::CZ_504
            ? self::resultBalances($plBalances)
            : [['bal' => $profitCents / 100]];
        foreach ($resultBalances as $result) {
            $cents = self::cents((float) $result['bal']);
            if ($cents > 0) {
                $lines[] = self::line($profile->account('result.current'), 'debit', $cents / 100, $result);
                $lines[] = self::line($profile->account('closing.final_balance'), 'credit', $cents / 100, $result);
            } elseif ($cents < 0) {
                $lines[] = self::line($profile->account('closing.final_balance'), 'debit', -$cents / 100, $result);
                $lines[] = self::line($profile->account('result.current'), 'credit', -$cents / 100, $result);
            }
        }

        // (c) rozvahové páry: debetní zůstatek → MD 702 / D účet; kreditní → MD účet / D 702.
        foreach ($this->sorted($bsBalances) as $row) {
            $cents = self::cents((float) $row['bal']);
            if ($cents === 0) {
                continue;
            }
            $amount = abs($cents) / 100;
            $code = (string) $row['account_code'];
            if ($profile->framework !== AccountingFrameworkService::CZ_504) unset($row['currency_code'], $row['amount_foreign'], $row['fx_rate']);
            if ($cents > 0) {
                $lines[] = self::line($profile->account('closing.final_balance'), 'debit', $amount, $row);
                $lines[] = self::line($code, 'credit', $amount, $row);
            } else {
                $lines[] = self::line($code, 'debit', $amount, $row);
                $lines[] = self::line($profile->account('closing.final_balance'), 'credit', $amount, $row);
            }
        }

        $this->assertZeroBalance($lines, $profile->account('closing.final_balance'), 'closing_unbalanced_702');

        return ['lines' => $lines, 'profit' => $profitCents / 100.0];
    }

    /**
     * Řádky otevíracího zápisu (R8 zrcadlo části c): debetní zůstatek B →
     * MD účet B / D 701; kreditní → MD 701 / D účet; VH: zisk MD 701 / D 431,
     * ztráta MD 431 / D 701. 701 končí na nule.
     *
     * @param list<array{account_id:int, account_code:string, name:string, bal:float}> $bsBalances
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    public function openingLines(array $bsBalances, float $profit, ?AccountingRoleProfile $profile = null, ?array $resultBalances = null): array
    {
        $profile ??= new AccountingRoleProfile();
        $lines = [];

        foreach ($this->sorted($bsBalances) as $row) {
            $cents = self::cents((float) $row['bal']);
            if ($cents === 0) {
                continue;
            }
            $amount = abs($cents) / 100;
            $code = $profile->openingAccount((string) $row['account_code']);
            if ($profile->framework !== AccountingFrameworkService::CZ_504) unset($row['currency_code'], $row['amount_foreign'], $row['fx_rate']);
            if ($cents > 0) {
                $lines[] = self::line($code, 'debit', $amount, $row);
                $lines[] = self::line($profile->account('closing.opening_balance'), 'credit', $amount, $row);
            } else {
                $lines[] = self::line($profile->account('closing.opening_balance'), 'debit', $amount, $row);
                $lines[] = self::line($code, 'credit', $amount, $row);
            }
        }

        $resultBalances = $profile->framework === AccountingFrameworkService::CZ_504 && $resultBalances !== null
            ? $resultBalances : [['bal' => $profit]];
        $resultCents = 0;
        foreach ($resultBalances as $result) {
            $cents = self::cents((float) $result['bal']);
            $resultCents += $cents;
            if ($cents > 0) {
                $lines[] = self::line($profile->account('closing.opening_balance'), 'debit', $cents / 100, $result);
                $lines[] = self::line($profile->account('result.pending_approval'), 'credit', $cents / 100, $result);
            } elseif ($cents < 0) {
                $lines[] = self::line($profile->account('result.pending_approval'), 'debit', -$cents / 100, $result);
                $lines[] = self::line($profile->account('closing.opening_balance'), 'credit', -$cents / 100, $result);
            }
        }
        if ($resultCents !== self::cents($profit)) {
            throw new ClosingException('opening_result_mismatch', 'Výsledek hospodaření nesouhlasí s převáděnými výsledkovými zůstatky.', 422);
        }

        $this->assertZeroBalance($lines, $profile->account('closing.opening_balance'), 'opening_unbalanced_701');

        return $lines;
    }

    public static function resultBalances(array $plBalances): array
    {
        return array_map(static fn (array $row): array => ['bal' => -(float) $row['bal']]
            + array_intersect_key($row, ['dimensions' => true, 'dimension_splits' => true, 'cost_center' => true, 'project_id' => true]), $plBalances);
    }

    /**
     * Invariant: Σ MD == Σ D řádků daného uzávěrkového účtu (účet skončí na
     * nule). Haléřová rovnost v int — jinak ClosingException a nic se nezapíše.
     *
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float}> $lines
     */
    private function assertZeroBalance(array $lines, string $accountCode, string $errorCode): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            if ($line['account_code'] !== $accountCode) {
                continue;
            }
            if ($line['side'] === 'debit') {
                $debit += self::cents($line['amount']);
            } else {
                $credit += self::cents($line['amount']);
            }
        }
        if ($debit !== $credit) {
            throw new ClosingException(
                $errorCode,
                'Závěrkový zápis není vyvážený: účet ' . $accountCode . ' MD ' . ($debit / 100)
                    . ' ≠ D ' . ($credit / 100) . ' — vstupní zůstatky nejsou bilančně konzistentní.',
                500,
            );
        }
    }

    /**
     * @param list<array{account_id:int, account_code:string, name:string, bal:float}> $rows
     * @return list<array{account_id:int, account_code:string, name:string, bal:float}>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['account_code'], (string) $b['account_code']));
        return $rows;
    }

    /**
     * @return array{account_code:string, side:'debit'|'credit', amount:float}
     */
    private static function line(string $code, string $side, float $amount, array $balance = []): array
    {
        /** @var array{account_code:string, side:'debit'|'credit', amount:float} */
        $currency = ($balance['account_code'] ?? null) === $code
            ? array_intersect_key($balance, ['currency_code' => true, 'amount_foreign' => true, 'fx_rate' => true]) : [];
        return ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2)]
            + array_intersect_key($balance, ['dimensions' => true, 'dimension_splits' => true, 'cost_center' => true, 'project_id' => true]) + $currency;
    }

    /** Peníze → haléře (int); vstup je zaokrouhlený DECIMAL(x,2) ↔ float. */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
