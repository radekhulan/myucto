<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Společné kusy rekonciliace převodu: obratová předvaha MyÚčta po syntetických účtech,
 * porovnání se zdrojovým programem, vnitřní kontroly předvahy, kontrola rozvahy
 * a řádek kontroly dokladů proti deníku.
 *
 * SQL kontroly dokladů proti deníku zůstává u zdroje: liší se mapou převodu, rozsahem
 * období, vynecháním otevíracího zápisu i měnou banky (viz reconcilery).
 */
final class TrialBalanceReconciliation
{
    /**
     * Předvaha MyÚčta ({@see \MyInvoice\Service\Accounting\Reports\TrialBalanceService::build()})
     * po syntetických účtech: netto PS, obrat a KS (MD − D), zaokrouhlené na haléře.
     *
     * @param iterable<array<string,mixed>> $rows řádky předvahy
     * @param (callable(string):bool)|null $skip syntetika, která se do porovnání nebere
     * @return array<string,array{0:float,1:float,2:float}>
     */
    public static function synthetic(iterable $rows, ?callable $skip = null): array
    {
        $mine = [];
        foreach ($rows as $row) {
            $syn = substr((string) $row['account_code'], 0, 3);
            if ($skip !== null && $skip($syn)) {
                continue;
            }
            $mine[$syn] ??= [0.0, 0.0, 0.0];
            $mine[$syn][0] += (float) $row['ps_md'] - (float) $row['ps_d'];
            $mine[$syn][1] += (float) $row['turnover_md'] - (float) $row['turnover_d'];
            $mine[$syn][2] += (float) $row['ks_md'] - (float) $row['ks_d'];
        }
        foreach ($mine as $syn => $v) {
            $mine[$syn] = [round($v[0], 2), round($v[1], 2), round($v[2], 2)];
        }
        return $mine;
    }

    /**
     * Rozdíly MyÚčto × zdroj po účtech (PS, obrat, KS). Účet bez pohybu na obou stranách
     * se nevypisuje.
     *
     * @param array<string,array{0:float,1:float,2:float}> $mine
     * @param array<string,array{0:float,1:float,2:float}> $theirs
     * @return list<array{account:string,myucto:array{0:float,1:float,2:float},money:array{0:float,1:float,2:float}}>
     */
    public static function compare(array $mine, array $theirs): array
    {
        $diffs = [];
        $codes = array_unique(array_merge(array_map('strval', array_keys($mine)), array_map('strval', array_keys($theirs))));
        sort($codes, SORT_STRING);
        foreach ($codes as $code) {
            $a = $mine[$code] ?? [0.0, 0.0, 0.0];
            $b = $theirs[$code] ?? [0.0, 0.0, 0.0];
            foreach ([0, 1, 2] as $i) {
                if (!ReconciliationTolerance::sameCent((float) $a[$i], (float) $b[$i])) {
                    // Klíč `money` je historický (první převod byl z Money S3), čte ho protokol i UI.
                    $diffs[] = ['account' => (string) $code, 'myucto' => $a, 'money' => $b];
                    break;
                }
            }
        }
        return $diffs;
    }

    /**
     * Vnitřní kontroly předvahy a kontrola proti deníku zdroje.
     *
     * @param array<string,mixed> $tb výsledek TrialBalanceService::build()
     * @param string $journalKey klíč kontroly proti deníku zdroje (`money_journal`, `pohoda_journal`…)
     * @param list<array<string,mixed>> $journalDiffs výsledek {@see compare()}
     * @param int $accounts počet účtů předvahy zdroje
     * @return list<array<string,mixed>>
     */
    public static function checks(array $tb, string $journalKey, array $journalDiffs, int $accounts): array
    {
        return [
            ['key' => 'turnover_balanced', 'ok' => (bool) $tb['checks']['turnover_balanced']],
            ['key' => 'matches_journal', 'ok' => (bool) $tb['checks']['matches_journal']],
            ['key' => 'opening_balanced', 'ok' => (bool) $tb['checks']['opening_balanced']],
            ['key' => 'no_drafts', 'ok' => (int) $tb['draft_count'] === 0],
            ['key' => $journalKey, 'ok' => $journalDiffs === [], 'accounts' => $accounts],
        ];
    }

    /**
     * Rozvaha musí vyjít: účet, který mapa výkazů nezná (syntetika ze zdrojové osnovy
     * mimo šablonu), by ve výkazech chyběl, i když předvaha sedí na haléř.
     *
     * Řádky aktiv se záporným netto jdou do protokolu jako varování, ne jako neúspěšná
     * kontrola: převod je v pořádku, jde o zařazení do výkazu (výjimky mapování), které
     * na uzávěrku převzatého roku nemá vliv.
     *
     * @param array<string,mixed> $balanceSheet výsledek FinancialStatementService::balanceSheet()
     * @param bool $framework504 převod umí rámec 504 (rovnováha rozvahy se ověřuje stejně, porovnání výkazů s cizím programem ne)
     * @return array{check:array{key:string,ok:bool},unmapped:list<array{account:string,name:string,balance:float}>,negative_net_rows:list<array{row_code:string,column:string,net:float}>}
     */
    public static function balanceSheet(array $balanceSheet, bool $framework504 = false): array
    {
        if (!$framework504) {
            self::assertStatementFrameworkSupported($balanceSheet['accounting_framework'] ?? 'cz_500');
        }
        $unmapped = array_map(
            static fn (array $u): array => ['account' => (string) $u['account_code'], 'name' => (string) $u['name'], 'balance' => round((float) $u['balance'], 2)],
            (array) ($balanceSheet['checks']['unmapped_accounts'] ?? [])
        );
        $negative = array_map(
            static fn (array $r): array => ['row_code' => (string) $r['row_code'], 'column' => (string) $r['column'], 'net' => round((float) $r['net'], 2)],
            (array) ($balanceSheet['checks']['negative_net_rows'] ?? [])
        );
        return [
            'check' => ['key' => 'balance_sheet_balanced', 'ok' => (bool) ($balanceSheet['checks']['balanced'] ?? false) && $unmapped === []],
            'unmapped' => $unmapped,
            'negative_net_rows' => $negative,
        ];
    }

    /**
     * Text varování protokolu k řádkům aktiv se záporným netto, null když žádný není.
     *
     * @param list<array{row_code:string,column:string,net:float}> $rows výsledek {@see balanceSheet()}
     */
    public static function negativeNetWarning(int $year, array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }
        $parts = array_map(
            static fn (array $r): string => sprintf(
                '%s %s Kč%s',
                $r['row_code'],
                number_format((float) $r['net'], 2, ',', ' '),
                $r['column'] === 'previous' ? ' (minulé období)' : '',
            ),
            $rows,
        );

        return sprintf(
            'Rok %d: rozvaha má řádek aktiv se záporným netto (%s). Opravná položka je nejspíš zařazená jinam než pohledávka, ke které patří; upravte výjimky mapování výkazů.',
            $year,
            implode('; ', $parts),
        );
    }

    public static function assertStatementFrameworkSupported(string $framework): void
    {
        if ($framework === 'cz_504') {
            throw new \MyInvoice\Service\Accounting\Reports\ReportException('statement_conversion_framework_unsupported',
                'Převod účetních výkazů podle vyhlášky 504 a jejich porovnání s jiným účetním programem zatím nejsou podporovány.');
        }
    }

    /** @param list<array{ok:bool}> $checks */
    public static function allOk(array $checks): bool
    {
        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return $ok;
    }

    /**
     * Řádek kontroly dokladů proti deníku.
     *
     * @return array{key:string,documents:float,journal:float,ok:bool,other_accounts:int}
     */
    public static function documentRow(string $key, float $documents, float $journal, int $otherAccounts): array
    {
        return ['key' => $key, 'documents' => $documents, 'journal' => $journal, 'ok' => ReconciliationTolerance::sameCent($documents, $journal), 'other_accounts' => $otherAccounts];
    }

    /**
     * Rozdíl kontroly vysvětlený rozdíly jednotlivých dokladů, které jsou už ve zdroji:
     * každý doklad musí mít ve zdroji nenulový rozdíl shodný na haléř s rozdílem v MyÚčtu
     * a součet vysvětlených rozdílů musí dát rozdíl celé kontroly.
     *
     * @param list<array{document_no:string,difference:float}> $mine rozdíly doklad − deník
     *        v MyÚčtu (po zápisech nebo po dokladech, podle zdroje)
     * @param array<string,float> $source číslo dokladu → týž rozdíl ve zdroji
     */
    public static function explainedBySource(array $mine, array $source, float $documents, float $journal): bool
    {
        if ($mine === []) {
            return false;
        }
        $total = 0.0;
        foreach ($mine as $e) {
            $theirs = $source[$e['document_no']] ?? null;
            if ($theirs === null || ReconciliationTolerance::isZeroCent($theirs) || !ReconciliationTolerance::sameCent($theirs, $e['difference'])) {
                return false;
            }
            $total += $e['difference'];
        }
        return ReconciliationTolerance::sameCent(round($documents - $journal, 2), round($total, 2));
    }
}
