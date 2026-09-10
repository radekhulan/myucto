<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Obratová předvaha vyexportovaná z Money (CSV / text) → netto hodnoty po syntetických
 * účtech: počáteční stav, obrat a konečný stav jako MD − D.
 *
 * Netto je jediná reprezentace nezávislá na tom, jak která sestava strany prezentuje.
 * Řádek sestavy se pozná podle kódu účtu v prvním sloupci (3–6 číslic); čísla se berou
 * z ostatních sloupců:
 *   - 6 čísel = PS MD, PS D, obrat MD, obrat D, KS MD, KS D
 *   - 3 čísla = PS, obrat, KS (už netto)
 * Obsahuje-li sestava syntetický řádek i analytiky pod ním, platí syntetický řádek —
 * jinak by se součet započítal dvakrát.
 */
final class MoneyReportParser
{
    /**
     * @return array{accounts:array<string,array{0:float,1:float,2:float}>,skipped:int}
     */
    public function parse(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) @iconv('CP1250', 'UTF-8//TRANSLIT', $content);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\R/', $content) ?: [];
        $delimiter = self::delimiter($lines);

        $synthetic = [];
        $analytic = [];
        $skipped = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = $delimiter === null ? preg_split('/\s{2,}/', trim($line)) : str_getcsv($line, $delimiter, '"', '');
            $cells = array_map(static fn ($c): string => trim((string) $c), $cells ?: []);
            if ($cells === [] || preg_match('/^(\d{3,6})$/', $cells[0], $m) !== 1) {
                continue;
            }
            $code = $m[1];
            $numbers = [];
            foreach (array_slice($cells, 1) as $cell) {
                $n = self::number($cell);
                if ($n !== null) {
                    $numbers[] = $n;
                }
            }
            $values = match (count($numbers)) {
                6 => [$numbers[0] - $numbers[1], $numbers[2] - $numbers[3], $numbers[4] - $numbers[5]],
                3 => $numbers,
                default => null,
            };
            if ($values === null) {
                $skipped++;
                continue;
            }
            $values = array_map(static fn (float $v): float => round($v, 2), $values);
            $syn = substr($code, 0, 3);
            if (strlen($code) === 3) {
                $synthetic[$syn] = $values;
            } else {
                $prev = $analytic[$syn] ?? [0.0, 0.0, 0.0];
                $analytic[$syn] = [round($prev[0] + $values[0], 2), round($prev[1] + $values[1], 2), round($prev[2] + $values[2], 2)];
            }
        }
        $accounts = $synthetic + $analytic;
        ksort($accounts, SORT_STRING);
        return ['accounts' => $accounts, 'skipped' => $skipped];
    }

    /** @param list<string> $lines */
    private static function delimiter(array $lines): ?string
    {
        $counts = [';' => 0, "\t" => 0, ',' => 0];
        foreach (array_slice($lines, 0, 50) as $line) {
            foreach ($counts as $d => $_) {
                $counts[$d] += substr_count($line, $d);
            }
        }
        if ($counts[';'] > 0) {
            return ';';
        }
        if ($counts["\t"] > 0) {
            return "\t";
        }
        return $counts[','] > 0 ? ',' : null;
    }

    /** „1 234,56", „-1234.56", „1 234,56-" → float; text → null. */
    private static function number(string $cell): ?float
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $cell);
        if ($s === '') {
            return null;
        }
        $negative = false;
        if (str_ends_with($s, '-')) {
            $negative = true;
            $s = substr($s, 0, -1);
        }
        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $s) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '.', $s);
        return $negative ? -$value : $value;
    }
}
