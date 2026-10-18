<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\TaxEvidence\CashJournalService;

/**
 * Rekonciliace převedené daňové evidence: peněžní deník, který MyÚčto spočítá z převzatých
 * dokladů, banky a pokladny ({@see CashJournalService::build()}), proti peněžnímu deníku
 * Money ({@see Ms3CashBook}) — rok po roku a pohyb po pohybu.
 *
 * Porovnává se, co rozhoduje o dani: částka pohybu a jeho daňová část (daňový příjem
 * se znaménkem plus, daňový výdaj minus; DPH, nedaňové a soukromé pohyby jsou mimo základ).
 *   - pohyb MyÚčta, který v Money není (virtuální úhrada dokladu bez pohybu), by základ
 *     zdvojil — chyba,
 *   - jiná daňová část úhrad dokladu, chybějící pohyb, nezařazený pohyb (`nezarazeno`)
 *     nebo nepeněžní pohyb Money (zápočet) s daňovou částí — rozdíl k přijetí.
 */
final class TaxRecordsReconciler
{
    public const STEP = 'tax_records_reconciliation';

    /** Haléřová tolerance (zaokrouhlení poměrného rozdělení DPH). */
    private const TOLERANCE = 0.01;

    /** Kolik rozdílů roku nese kontext hlášky. */
    private const EXAMPLES = 20;

    public function __construct(private readonly CashJournalService $cashJournal) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $years = [];
        $index = [];
        foreach (['bank', 'cash'] as $kind) {
            foreach ($ctx->movements[$kind] as $movementKey => $id) {
                $index[$kind . '|' . $id] = $kind . '|' . $movementKey;
            }
        }
        foreach ($ctx->dirYears as $dir => $year) {
            $book = $ctx->cashBooks[$dir] ?? null;
            if ($book === null || $ctx->isLocked($year)) {
                continue;
            }
            $journal = $this->cashJournal->build($ctx->supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), ['year' => $year]);
            $rows = [];
            $extra = [];
            foreach ($journal['rows'] as $row) {
                $key = $row['source_type'] . '|' . $row['source_id'];
                if (isset($index[$key]) && str_contains($index[$key], '|' . $dir . '|')) {
                    $rows[$index[$key]] = $row;
                } else {
                    $extra[] = $row;
                }
            }

            $money = ['income_taxable' => 0.0, 'expense_taxable' => 0.0];
            $differences = [];
            $missing = [];
            $nonCash = [];
            // Porovnává se za uhrazený doklad a rok (pohyb bez vazby sám za sebe): částečné
            // úhrady jednoho dokladu dělí Money na základ a DPH jinak než poměrem a nulový
            // doklad Money (přeúčtování DPH zálohy) MyÚčto nepřebírá — za doklad se ale
            // daňová část sejít musí, jinak je to rozdíl s konkrétním dokladem.
            $groups = [];
            foreach ($book->movements as $movement) {
                $taxable = self::taxable($movement['parts']);
                if ($movement['source'] === 'ID') {
                    if (abs($taxable) >= self::TOLERANCE) {
                        $nonCash[] = $movement['document_no'];
                    }
                    continue;
                }
                foreach ($movement['parts'] as $part) {
                    if (in_array($part['bucket'], ['income_taxable', 'expense_taxable'], true)) {
                        $money[$part['bucket']] = round($money[$part['bucket']] + $part['amount'], 2);
                    }
                }
                $row = $rows[($movement['source'] === 'PK' ? 'cash' : 'bank') . '|' . $dir . '|' . $movement['number']] ?? null;
                if ($row === null && abs($movement['amount']) >= self::TOLERANCE) {
                    $missing[] = $movement['document_no'];
                    continue;
                }
                $key = self::group($movement);
                $groups[$key] ??= ['documents' => [], 'money_amount' => 0.0, 'myucto_amount' => 0.0, 'money_taxable' => 0.0, 'myucto_taxable' => 0.0];
                $groups[$key]['documents'][$movement['document_no']] = true;
                $groups[$key]['money_amount'] += $movement['amount'];
                $groups[$key]['money_taxable'] += $taxable;
                if ($row === null) {
                    continue;
                }
                $groups[$key]['myucto_amount'] += (float) ($row['income'] ?? 0) - (float) ($row['expense'] ?? 0);
                // Daňová část řádku MyÚčta se znaménkem směru pohybu (vratka k dobropisu je
                // příjem, který daňový výdaj snižuje).
                $groups[$key]['myucto_taxable'] += in_array($row['bucket'], ['income_taxable', 'expense_taxable'], true)
                    ? ($row['direction'] === 'in' ? 1 : -1) * abs((float) $row['base']) : 0.0;
            }
            foreach ($groups as $g) {
                $g = ['documents' => implode(', ', array_keys($g['documents']))] + array_map(static fn (float $v): float => round($v, 2), array_slice($g, 1));
                if (abs($g['myucto_amount'] - $g['money_amount']) >= self::TOLERANCE || abs($g['myucto_taxable'] - $g['money_taxable']) >= self::TOLERANCE) {
                    $differences[] = $g;
                }
            }

            $label = "Rok {$year}";
            $totals = $journal['totals'];
            $summary = [
                'year' => $year,
                'money' => ['income_taxable' => $money['income_taxable'], 'expense_taxable' => round(-$money['expense_taxable'], 2)],
                'myucto' => ['income_taxable' => (float) $totals['prijem_danovy'], 'expense_taxable' => (float) $totals['vydaj_danovy'],
                    'unclassified' => (float) $totals['nezarazeno']],
                'differences' => count($differences), 'missing' => count($missing), 'extra' => count($extra), 'non_cash' => count($nonCash),
            ];
            $summary['ok'] = $differences === [] && $missing === [] && $extra === [] && abs((float) $totals['nezarazeno']) < self::TOLERANCE
                && abs($summary['money']['income_taxable'] - $summary['myucto']['income_taxable']) < self::TOLERANCE
                && abs($summary['money']['expense_taxable'] - $summary['myucto']['expense_taxable']) < self::TOLERANCE;
            $years[] = $summary;

            if ($extra !== []) {
                $p->error(self::STEP, 'tax_records_extra_movement', sprintf(
                    '%s: peněžní deník MyÚčta má %d pohybů, které v peněžním deníku Money nejsou (například úhrada dokladu bez bankovního nebo pokladního pohybu). Daňový základ by se zdvojil.',
                    $label, count($extra),
                ), ['year' => $year, 'movements' => array_map(static fn (array $r): array => ['source_type' => $r['source_type'], 'source_id' => $r['source_id'], 'doc_no' => $r['doc_no']],
                    array_slice($extra, 0, self::EXAMPLES))]);
            }
            if (abs((float) $totals['nezarazeno']) >= self::TOLERANCE) {
                $p->difference(self::STEP, 'tax_records_unclassified', sprintf(
                    '%s: peněžní deník MyÚčta má nezařazené pohyby za %s Kč (například zaokrouhlení úhrady mimo doklad). Zařaďte je v peněžním deníku, jinak chybí v daňovém základu.',
                    $label, self::money((float) $totals['nezarazeno']),
                ), ['year' => $year]);
            }
            if ($differences !== []) {
                $p->difference(self::STEP, 'tax_records_difference', sprintf(
                    '%s: %d dokladů má v peněžním deníku MyÚčta jinou částku nebo daňovou část úhrad než v Money (například pohyby %s). '
                        . 'Money rozdělil DPH úhrady jinak, než ji MyÚčto rozpočítá poměrem, nebo přeúčtoval DPH zálohy nulovým dokladem.',
                    $label, count($differences), implode('; ', array_slice(array_column($differences, 'documents'), 0, 5)),
                ), ['year' => $year, 'differences' => array_slice($differences, 0, self::EXAMPLES)]);
            }
            if ($missing !== []) {
                $p->difference(self::STEP, 'tax_records_missing_movement', sprintf(
                    '%s: %d pohybů peněžního deníku Money v MyÚčtu chybí (například %s).',
                    $label, count($missing), implode(', ', array_slice($missing, 0, 5)),
                ), ['year' => $year, 'documents' => array_slice($missing, 0, self::EXAMPLES)]);
            }
            if ($nonCash !== []) {
                $p->difference(self::STEP, 'tax_records_non_cash', sprintf(
                    '%s: %d nepeněžních pohybů (zápočtů) Money s daňovým příjmem nebo výdajem převod nepřebírá jako pohyb (například %s). Doplňte je ručně.',
                    $label, count($nonCash), implode(', ', array_slice($nonCash, 0, 5)),
                ), ['year' => $year, 'documents' => array_slice($nonCash, 0, self::EXAMPLES)]);
            }
            foreach (['income_taxable' => 'daňové příjmy', 'expense_taxable' => 'daňové výdaje'] as $bucket => $text) {
                if (abs($summary['money'][$bucket] - $summary['myucto'][$bucket]) >= self::TOLERANCE && $differences === [] && $missing === []) {
                    $p->difference(self::STEP, 'tax_records_total_difference', sprintf(
                        '%s: %s v MyÚčtu %s Kč, v Money %s Kč.', $label, $text, self::money($summary['myucto'][$bucket]), self::money($summary['money'][$bucket]),
                    ), ['year' => $year, 'bucket' => $bucket]);
                }
            }
            $p->count(self::STEP, 'years');
        }
        $p->set('tax_records_reconciliation', $years);
        $p->finish(self::STEP);
    }

    /**
     * Daňová část pohybu Money se znaménkem peněžního toku (příjem +, výdaj −).
     *
     * @param list<array{bucket:?string,amount:float}> $parts
     */
    private static function taxable(array $parts): float
    {
        $sum = 0.0;
        foreach ($parts as $part) {
            if (in_array($part['bucket'], ['income_taxable', 'expense_taxable'], true)) {
                $sum += $part['amount'];
            }
        }
        return round($sum, 2);
    }

    /**
     * Doklad, za který se úhrady porovnávají: první uhrazený doklad pohybu, pohyb bez vazby
     * sám za sebe.
     *
     * @param array{source:string,number:int,parts:list<array{link:?array{table:string,dir:string,number:int}}>} $movement
     */
    private static function group(array $movement): string
    {
        foreach ($movement['parts'] as $part) {
            if ($part['link'] !== null) {
                return $part['link']['table'] . '|' . $part['link']['dir'] . '|' . $part['link']['number'];
            }
        }
        return 'pohyb|' . $movement['source'] . '|' . $movement['number'];
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
