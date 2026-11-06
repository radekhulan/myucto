<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Návrh vazby používá jen vlastní účty a řady skutečných bankovních výpisů zdroje. */
final class StereoNxBankAccountSeries
{
    /**
     * @param list<array<string,mixed>> $accounts LFirmaUc
     * @param list<array<string,mixed>> $statements CBanka
     * @return array{requires_confirmation:bool,ambiguous:bool,assignments:list<array{account_index:int,account_label:string,series:string}>,missing_accounts:int,unmatched_series:list<string>}
     */
    public static function plan(array $accounts, array $statements): array
    {
        $mapped = [];
        $missing = 0;
        $duplicate = false;
        foreach ($accounts as $row) {
            $series = trim((string) ($row['DoklRada'] ?? ''));
            if ($series === '') {
                $missing++;
                continue;
            }
            $key = 'series:' . $series;
            if (isset($mapped[$key])) $duplicate = true;
            $mapped[$key] = true;
        }
        $series = [];
        $invalidStatement = false;
        foreach ($statements as $row) {
            $value = trim((string) ($row['DoklRada'] ?? ''));
            if ($value === '') {
                $invalidStatement = true;
                continue;
            }
            $series['series:' . $value] = $value;
        }
        $unmatched = array_values(array_diff_key($series, $mapped));
        sort($unmatched, SORT_STRING);
        $assignments = [];
        // Ani zbývající jedna řada mezi více účty nedokazuje, kterému účtu patří.
        if (!$duplicate && !$invalidStatement && count($accounts) === 1 && $missing === 1
            && count($series) === 1 && count($unmatched) === 1
            && (trim((string) ($accounts[0]['BaUcet'] ?? '')) !== ''
                || trim((string) ($accounts[0]['IBAN'] ?? '')) !== '')) {
            $account = $accounts[0];
            $label = trim((string) ($account['NazevUctu'] ?? ''));
            if ($label === '') {
                $number = trim((string) ($account['BaUcet'] ?? ''));
                $bank = trim((string) ($account['KodBanky'] ?? ''));
                $label = $number !== '' ? $number . ($bank !== '' ? ' / ' . $bank : '')
                    : trim((string) ($account['IBAN'] ?? ''));
            }
            $assignments[] = ['account_index' => 0, 'account_label' => $label, 'series' => $unmatched[0]];
        }
        return ['requires_confirmation' => $assignments !== [],
            // Nepokryté řady účetní převod přeskočí s varováním. Duplicitní vazba
            // vlastních účtů by naopak mohla přiřadit pohyb nesprávnému účtu.
            'ambiguous' => $duplicate,
            'assignments' => $assignments, 'missing_accounts' => $missing, 'unmatched_series' => $unmatched];
    }
}
