<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Kontrola zdroje agendy, kterou Money vede jako daňovou evidenci — nic nezapisuje.
 *
 * Peněžní deník Money je projekce bankovních a pokladních dokladů ({@see Ms3CashBook}).
 * Převod ho nepřebírá jako platby, staví na dokladech; deník musí proto sedět s doklady
 * na haléř a jeho vazby musí vést na doklady, které v záloze jsou. Kontroluje se:
 *
 *   - každý nenulový bankovní a pokladní doklad má řádky v deníku a jejich součet se
 *     znaménkem (příjem +, výdaj −) se rovná dokladu, a naopak deník nemá řádek bez dokladu,
 *   - interní doklad deník mít nemusí (zápočet bez peněz); má-li ho, sedí aspoň částka,
 *   - vazba řádku vede na doklad v roce `URok`,
 *   - pohyb i jeho sloupec peněžního deníku převod zná ({@see Ms3CashBook::COLUMN_BUCKETS}),
 *   - deník je v korunách (cizí měny převod daňové evidence nepřebírá),
 *   - počáteční stav účtu a pokladny navazuje na stav konce předchozího roku,
 *   - agenda nemá majetek ani mzdy, které převod daňové evidence zatím nepřebírá.
 *
 * Úroveň `error` je to, co převod daňové evidence zastaví; `warning` převod pustí.
 */
final class TaxRecordsPlan
{
    /** Kolik čísel dokladů nese kontext hlášky (zbytek jen počtem). */
    private const EXAMPLES = 5;

    /**
     * `$fromYear` = první převáděný rok (volba „Převést od roku"): starší roky se nekontrolují.
     *
     * @return array{
     *     years:list<array{dir:string,year:?int,movements:int,bank:int,cash:int,internal:int,links:array<string,int>,totals:array<string,float>}>,
     *     issues:list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     * }
     */
    public static function check(Ms3Backup $backup, AgendaInfo $agenda, ?int $fromYear = null): array
    {
        $issues = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$issues): void {
            $issues[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        foreach ([['MajInv', 'assets_not_supported', 'Agenda má v Money majetek. Převod daňové evidence majetek zatím nepřebírá.'],
            ['Mzdy', 'payroll_not_supported', 'Agenda má v Money mzdy. Převod daňové evidence mzdy zatím nepřebírá.']] as [$table, $code, $message]) {
            $t = $backup->table($table);
            if ($t !== null && $t->hasData() && $t->countRows() > 0) {
                $add('error', $code, $message);
            }
        }

        $paths = [];
        foreach ($backup->yearDirs() as $path) {
            $paths[basename($path)] = $path;
        }
        $targets = [];
        $targetNumbers = static function (string $dir, string $table) use ($backup, $paths, &$targets): array {
            if (!isset($targets[$dir][$table])) {
                $numbers = [];
                $t = isset($paths[$dir]) ? $backup->table($table, $paths[$dir]) : null;
                if ($t !== null && $t->hasData()) {
                    foreach ($t->rows(['Cislo']) as $r) {
                        $numbers[(int) ($r['Cislo'] ?? 0)] = true;
                    }
                }
                $targets[$dir][$table] = $numbers;
            }
            return $targets[$dir][$table];
        };

        $years = [];
        $chain = [];
        foreach ($agenda->years as $y) {
            if (($y['accounting_mode'] ?? null) !== 'tax_evidence' || !isset($paths[$y['dir']])) {
                continue;
            }
            $dir = (string) $y['dir'];
            $year = isset($y['money_year']) ? (int) $y['money_year'] : null;
            if ($fromYear !== null && ($year === null || $year < $fromYear)) {
                continue;
            }
            $label = $year !== null ? "Rok {$year} (adresář {$dir})" : "Rok v adresáři {$dir}";
            $context = ['year' => $year, 'dir' => $dir];
            $book = Ms3CashBook::read($backup, $paths[$dir]);

            $documents = [];
            $balances = [];
            foreach (['BK' => ['BankKnih', 'Ucet'], 'PK' => ['PoklKnih', 'Pokl'], 'ID' => ['IntDokl', null]] as $source => [$table, $accountField]) {
                $t = $backup->table($table, $paths[$dir]);
                if ($t === null || !$t->hasData()) {
                    continue;
                }
                foreach ($t->rows(array_values(array_filter(['Cislo', 'Doklad', 'Vydej', 'Celkem', $accountField]))) as $r) {
                    $amount = round((float) ($r['Celkem'] ?? 0), 2);
                    $signed = ((int) ($r['Vydej'] ?? 0)) === 1 ? -$amount : $amount;
                    $documents[Ms3CashBook::key($source, (int) ($r['Cislo'] ?? 0))] = ['source' => $source, 'document_no' => trim((string) ($r['Doklad'] ?? '')), 'amount' => $signed];
                    if ($accountField !== null) {
                        $account = trim((string) ($r[$accountField] ?? ''));
                        $balances[$account] = round(($balances[$account] ?? 0.0) + $signed, 2);
                    }
                }
            }

            $problems = [];
            $note = static function (string $code, string $documentNo) use (&$problems): void {
                $problems[$code][] = $documentNo;
            };
            foreach ($documents as $key => $doc) {
                $movement = $book->movements[$key] ?? null;
                if ($movement === null) {
                    if ($doc['source'] !== 'ID' && abs($doc['amount']) >= 0.005) {
                        $note('cashbook_projection_missing', $doc['document_no']);
                    }
                    continue;
                }
                if ($doc['source'] === 'ID') {
                    if (abs(abs($movement['amount']) - abs($doc['amount'])) >= 0.005) {
                        $note('cashbook_internal_mismatch', $doc['document_no']);
                    }
                } elseif (abs($movement['amount'] - $doc['amount']) >= 0.005) {
                    $note('cashbook_projection_mismatch', $doc['document_no']);
                }
            }
            $links = [];
            foreach ($book->movements as $key => $movement) {
                if (!isset($documents[$key])) {
                    $note('cashbook_orphan', $movement['document_no']);
                }
                $currency = mb_strtoupper($movement['currency']);
                if (!in_array($currency, ['', 'KČ', 'CZK'], true) || ($movement['rate'] !== 0.0 && abs($movement['rate'] - 1.0) > 1e-9)) {
                    $note('cashbook_foreign_currency', $movement['document_no']);
                }
                $linked = [];
                foreach ($movement['parts'] as $part) {
                    if (!isset($book->movementTypes[$part['movement']])) {
                        $note('cashbook_movement_unknown', $part['movement']);
                    } elseif ($part['bucket'] === null) {
                        $note('cashbook_column_unknown', (string) $part['column']);
                    }
                    $link = $part['link'];
                    if ($link === null) {
                        continue;
                    }
                    if (!isset($targetNumbers($link['dir'], Ms3CashBook::LINK_TABLES[$link['table']])[$link['number']])) {
                        $note('cashbook_link_orphan', $movement['document_no']);
                    } else {
                        $linked[$link['table'] . '|' . $link['dir'] . '|' . $link['number']] = $link['table'];
                    }
                }
                foreach ($linked as $table) {
                    $links[$table] = ($links[$table] ?? 0) + 1;
                }
            }
            $messages = [
                'cashbook_projection_missing' => ['error', 'doklady banky nebo pokladny nemají řádky v peněžním deníku'],
                'cashbook_projection_mismatch' => ['error', 'peněžní deník nesedí s částkou bankovního nebo pokladního dokladu'],
                'cashbook_orphan' => ['error', 'peněžní deník má řádky bez bankovního, pokladního nebo interního dokladu'],
                'cashbook_internal_mismatch' => ['warning', 'peněžní deník nesedí s částkou interního dokladu'],
                'cashbook_link_orphan' => ['error', 'řádky peněžního deníku odkazují na uhrazený doklad, který v záloze není'],
                'cashbook_movement_unknown' => ['error', 'peněžní deník používá typ pohybu, který číselník pohybů Money nemá'],
                'cashbook_column_unknown' => ['error', 'typ pohybu patří do sloupce peněžního deníku, který převod nezná'],
                'cashbook_foreign_currency' => ['error', 'peněžní deník obsahuje pohyby v cizí měně, které převod daňové evidence nepřebírá'],
            ];
            foreach ($messages as $code => [$level, $text]) {
                if (!isset($problems[$code])) {
                    continue;
                }
                $examples = array_values(array_unique($problems[$code]));
                $add($level, $code, sprintf('%s: %s (%d×, např. %s).', $label, $text, count($problems[$code]), implode(', ', array_slice($examples, 0, self::EXAMPLES))),
                    $context + ['count' => count($problems[$code]), 'examples' => array_slice($examples, 0, self::EXAMPLES)]);
            }

            $chain[] = ['year' => $year, 'dir' => $dir, 'label' => $label, 'accounts' => $book->accounts, 'balances' => $balances];
            $counts = array_count_values(array_column($book->movements, 'source'));
            ksort($links);
            $years[] = [
                'dir' => $dir,
                'year' => $year,
                'movements' => count($book->movements),
                'bank' => $counts['BK'] ?? 0,
                'cash' => $counts['PK'] ?? 0,
                'internal' => $counts['ID'] ?? 0,
                'links' => $links,
                'totals' => $book->totals(),
            ];
        }

        // Navázání počátečních stavů: stav na začátku roku = počáteční stav předchozího roku
        // + jeho pohyby. Jen roky Money s rokem a jen po sobě jdoucí.
        $chain = array_values(array_filter($chain, static fn (array $c): bool => $c['year'] !== null));
        usort($chain, static fn (array $a, array $b): int => $a['year'] <=> $b['year']);
        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            [$prev, $next] = [$chain[$i - 1], $chain[$i]];
            if ($next['year'] !== $prev['year'] + 1) {
                continue;
            }
            foreach ($next['accounts'] as $code => $account) {
                if (!isset($prev['accounts'][$code])) {
                    continue;
                }
                $expected = round($prev['accounts'][$code]['opening'] + ($prev['balances'][$code] ?? 0.0), 2);
                $difference = round($account['opening'] - $expected, 2);
                if (abs($difference) >= 0.005) {
                    $add('warning', 'cash_opening_chain_break', sprintf(
                        'Počáteční stav %s %s v roce %d (%s Kč) nenavazuje na konec roku %d (%s Kč), rozdíl %s Kč.',
                        $account['kind'] === 'P' ? 'pokladny' : 'účtu', $code, $next['year'],
                        self::money($account['opening']), $prev['year'], self::money($expected), self::money($difference),
                    ), ['year' => $next['year'], 'previous_year' => $prev['year'], 'account' => $code, 'difference' => $difference]);
                }
            }
        }

        return ['years' => $years, 'issues' => $issues];
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
