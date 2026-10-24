<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;

/**
 * Čistá kontrola zdrojového účetního deníku před převodem.
 *
 * Kódy účtů zůstávají zdrojové. Účetní období kontace určuje KdyUcPripad podle
 * kalendáře účetního roku cílové firmy (kalendářní, nebo hospodářský rok):
 * porovnání deníku s předvahami Stereo potvrzuje výběr podle tohoto data,
 * nikoli podle pomocného Rok. Zdrojový Rok se zachová a rozdíl se oznámí. Bez
 * kalendáře firmy (diagnostika zálohy) se období počítá kalendářně. Shodu účetního
 * roku zdroje s rokem firmy kontroluje {@see StereoNxAccountingWriter} podle
 * počátečních stavů (pomocný Rok k tomu spolehlivý není).
 */
final class StereoNxAccountingJournalPlan
{
    /** @param list<array<string,mixed>> $journal @param list<array<string,mixed>> $chart */
    public static function build(array $journal, array $chart, ?FiscalCalendar $calendar = null): array
    {
        $calendar ??= FiscalCalendar::calendar();
        $chartCodes = [];
        $targetCodes = [];
        $blockers = [];
        $warnings = [];
        foreach ($chart as $index => $row) {
            $code = trim((string) ($row['Ucet'] ?? ''));
            if ($code === '') {
                $blockers[] = ['code' => 'chart_account_missing', 'row' => $index];
                continue;
            }
            $chartKey = '#' . $code;
            if (isset($chartCodes[$chartKey])) {
                $blockers[] = ['code' => 'chart_account_duplicate', 'account' => $code];
                continue;
            }
            $chartCodes[$chartKey] = $code;
            $targetCode = ImportedAccountCode::normalize($code);
            if (isset($targetCodes['#' . $targetCode])) {
                $blockers[] = ['code' => 'chart_account_normalization_collision', 'account' => $code,
                    'other_account' => $targetCodes['#' . $targetCode], 'target_account' => $targetCode];
            }
            $targetCodes['#' . $targetCode] = $code;
            if (strlen($targetCode) > 10) {
                $blockers[] = ['code' => 'target_account_code_too_long', 'account' => $code];
            }
        }

        $entries = [];
        $keys = [];
        $debit = 0;
        $credit = 0;
        $dates = [];
        foreach ($journal as $index => $row) {
            // Jeden zdrojový řádek dokladu má více kontací rozlišených polem Poradi.
            $parts = array_map(static fn (string $field): string => trim((string) ($row[$field] ?? '')), ['Agenda', 'DoklRada', 'DoklCislo', 'Klic', 'Poradi']);
            $key = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (in_array('', $parts, true) || isset($keys[$key])) {
                $blockers[] = ['code' => in_array('', $parts, true) ? 'journal_identity_missing' : 'journal_identity_duplicate', 'key' => $key, 'row' => $index];
                continue;
            }
            $keys[$key] = true;
            $date = trim((string) ($row['KdyUcPripad'] ?? ''));
            $year = $row['Rok'] ?? null;
            $month = $row['Mesic'] ?? null;
            if (!is_int($year) || $year < 1900 || $year > 9999) {
                $blockers[] = ['code' => 'journal_year_invalid', 'key' => $key];
            }
            if (!is_int($month) || $month < 1 || $month > 12) {
                $blockers[] = ['code' => 'journal_month_invalid', 'key' => $key];
            }
            if (!self::validDate($date)) {
                $blockers[] = ['code' => 'journal_date_invalid', 'key' => $key];
            } else {
                $dates[] = $date;
                if (is_int($year) && $year !== $calendar->fiscalYearOfDate($date)) {
                    $warnings[] = ['code' => 'journal_year_mismatch', 'key' => $key];
                }
                if (is_int($month) && $month !== (int) substr($date, 5, 2)) {
                    $blockers[] = ['code' => 'journal_month_mismatch', 'key' => $key];
                }
            }
            $md = trim((string) ($row['UcetMD'] ?? ''));
            $dal = trim((string) ($row['UcetD'] ?? ''));
            foreach ([['code' => $md, 'side' => 'debit'], ['code' => $dal, 'side' => 'credit']] as $part) {
                $account = $part['code'];
                $side = $part['side'];
                if ($account === '') {
                    $blockers[] = ['code' => 'journal_account_missing', 'key' => $key, 'side' => $side];
                } elseif (!isset($chartCodes['#' . $account])) {
                    $blockers[] = ['code' => 'journal_account_not_in_chart', 'key' => $key, 'side' => $side, 'account' => $account];
                }
                if (strlen(ImportedAccountCode::normalize($account)) > 10) $blockers[] = ['code' => 'target_account_code_too_long', 'key' => $key, 'account' => $account];
            }
            $rawAmount = $row['Celkem'] ?? null;
            if ((!is_int($rawAmount) && !is_float($rawAmount)) || !is_finite((float) $rawAmount)) {
                $blockers[] = ['code' => 'journal_amount_invalid', 'key' => $key];
                continue;
            }
            $amount = (float) $rawAmount;
            if (abs($amount) > 999999999999.99) {
                $blockers[] = ['code' => 'journal_amount_out_of_range', 'key' => $key];
                continue;
            }
            $cents = (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
            if (abs($debit) > PHP_INT_MAX - abs($cents)) {
                $blockers[] = ['code' => 'journal_total_out_of_range', 'key' => $key];
                continue;
            }
            // Účetní kontace přebíráme celé podle MD/Dal a Celkem. Výslovné NULL
            // znamená nepoužitý samostatný rozpad DPH, nikoli chybějící kontaci daně.
            $taxAmount = $row['DPH'] ?? null;
            if (!array_key_exists('DPH', $row)
                || ($taxAmount !== null && !is_int($taxAmount) && !is_float($taxAmount))) {
                $blockers[] = ['code' => 'journal_tax_amount_invalid', 'key' => $key];
            } elseif ($taxAmount !== null && (!is_finite((float) $taxAmount) || abs((float) $taxAmount) >= 0.005)) {
                $blockers[] = ['code' => 'journal_tax_amount_unverified', 'key' => $key];
            }
            $debit += $cents;
            $credit += $cents;
            $entries[] = ['source_key' => $key, 'date' => $date, 'source_year' => $year,
                'posting_year' => self::validDate($date) ? $calendar->fiscalYearOfDate($date) : null,
                'debit' => $md, 'credit' => $dal, 'amount_cents' => $cents];
        }
        sort($dates);
        return ['ok' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings, 'entries' => $entries,
            'summary' => ['entries' => count($entries), 'debit_cents' => $debit, 'credit_cents' => $credit,
                'date_from' => $dates[0] ?? null, 'date_to' => $dates[count($dates) - 1] ?? null],
            'chart_codes' => array_values($chartCodes)];
    }

    private static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
