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
 *
 * Hláška vypíše každý doklad zvlášť: číslo a datum pohybu, uhrazený doklad, rozdělení úhrady
 * v Money (s kódem pohybu peněžního deníku, podle kterého se dá v Money dohledat) a v MyÚčtu
 * a nejpravděpodobnější příčinu.
 */
final class TaxRecordsReconciler
{
    public const STEP = 'tax_records_reconciliation';

    /** Haléřová tolerance (zaokrouhlení poměrného rozdělení DPH). */
    private const TOLERANCE = 0.01;

    /** Kolik položek vypíše text hlášky; kontext jich nese až {@see ImportProtocol::LIST_LIMIT}. */
    private const EXAMPLES = 20;

    /** Doklad, na který vede vazba peněžního deníku Money ({@see Ms3CashBook::LINK_TABLES}). */
    private const LINK_LABELS = ['FV' => 'vydaná faktura', 'FP' => 'přijatá faktura', 'KZ' => 'ostatní závazek', 'KP' => 'ostatní pohledávka'];

    /** Zařazení části pohybu Money ({@see Ms3CashBook::COLUMN_BUCKETS}); `''` = sloupec, který převod nezná. */
    private const BUCKET_LABELS = [
        'income_taxable' => 'daňový příjem', 'expense_taxable' => 'daňový výdaj', 'income_nontax' => 'nedaňový příjem',
        'expense_nontax' => 'nedaňový výdaj', 'private' => 'vklad nebo osobní spotřeba', 'transfer' => 'převod',
        Ms3CashBook::VAT => 'DPH', '' => 'bez zařazení',
    ];

    public function __construct(
        private readonly CashJournalService $cashJournal,
        private readonly TaxRecordsImporter $taxRecords,
    ) {}

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
            $bySource = [];
            // Nezařazená část pohybu MyÚčta se znaménkem peněžního toku: celý pohyb bez
            // zařazení, nebo zbytek pohybu nad navázané doklady (varování deníku).
            $unclassified = [];
            foreach ($journal['rows'] as $row) {
                $source = $row['source_type'] . '|' . $row['source_id'];
                $bySource[$source] = $row;
                if ($row['unclassified']) {
                    $unclassified[$source] = self::signed($row);
                }
                if (isset($index[$source]) && str_contains($index[$source], '|' . $dir . '|')) {
                    $rows[$index[$source]] = $row;
                } else {
                    $extra[] = $row;
                }
            }
            foreach ($journal['warnings'] as $w) {
                if (($w['type'] ?? null) === 'bank_allocation_below_movement') {
                    $source = 'bank|' . $w['source_id'];
                    $unclassified[$source] = round(($unclassified[$source] ?? 0.0) + ($w['direction'] === 'in' ? 1 : -1) * (float) $w['amount'], 2);
                }
            }

            $money = ['income_taxable' => 0.0, 'expense_taxable' => 0.0];
            $missing = [];
            $nonCash = [];
            $moneyByRow = [];
            // Porovnává se za uhrazený doklad a rok (pohyb bez vazby sám za sebe): částečné
            // úhrady jednoho dokladu dělí Money na základ a DPH jinak než poměrem a nulový
            // doklad Money (přeúčtování DPH zálohy) MyÚčto nepřebírá — za doklad se ale
            // daňová část sejít musí, jinak je to rozdíl s konkrétním dokladem.
            $groups = [];
            foreach ($book->movements as $movement) {
                $taxable = self::taxable($movement['parts']);
                if ($movement['source'] === 'ID') {
                    if (abs($taxable) >= self::TOLERANCE) {
                        $nonCash[] = $movement;
                    }
                    continue;
                }
                foreach ($movement['parts'] as $part) {
                    if (in_array($part['bucket'], ['income_taxable', 'expense_taxable'], true)) {
                        $money[$part['bucket']] = round($money[$part['bucket']] + $part['amount'], 2);
                    }
                }
                $rowKey = ($movement['source'] === 'PK' ? 'cash' : 'bank') . '|' . $dir . '|' . $movement['number'];
                $moneyByRow[$rowKey] = $movement;
                $row = $rows[$rowKey] ?? null;
                if ($row === null && abs($movement['amount']) >= self::TOLERANCE) {
                    $missing[] = $movement;
                    continue;
                }
                $key = self::group($movement);
                $groups[$key] ??= [
                    'link' => self::link($movement), 'movements' => [], 'money_amount' => 0.0, 'money_taxable' => 0.0, 'money_parts' => [],
                    'reclass' => 0.0, 'reclass_documents' => [], 'myucto_rows' => 0, 'myucto_amount' => 0.0, 'myucto_taxable' => 0.0, 'myucto_unclassified' => 0.0,
                ];
                $g = &$groups[$key];
                $g['movements'][] = $movement;
                $g['money_amount'] += $movement['amount'];
                $g['money_taxable'] += $taxable;
                foreach ($movement['parts'] as $part) {
                    $partKey = ($part['bucket'] ?? '') . '|' . $part['movement'];
                    $g['money_parts'][$partKey] = ($g['money_parts'][$partKey] ?? 0.0) + $part['amount'];
                }
                if (abs($movement['amount']) < self::TOLERANCE && abs($taxable) >= self::TOLERANCE) {
                    $g['reclass'] += $taxable;
                    $g['reclass_documents'][] = $movement['document_no'];
                }
                if ($row !== null) {
                    $g['myucto_rows']++;
                    $g['myucto_amount'] += self::signed($row);
                    // Daňová část řádku MyÚčta se znaménkem směru pohybu (vratka k dobropisu je
                    // příjem, který daňový výdaj snižuje).
                    $g['myucto_taxable'] += in_array($row['bucket'], ['income_taxable', 'expense_taxable'], true)
                        ? ($row['direction'] === 'in' ? 1 : -1) * abs((float) $row['base']) : 0.0;
                    $g['myucto_unclassified'] += $unclassified[$row['source_type'] . '|' . $row['source_id']] ?? 0.0;
                }
                unset($g);
            }

            $differences = [];
            // Daňová část, o kterou se roky liší kvůli nezařazenému zbytku pohybu (hlásí ji
            // položka nezařazených pohybů), v jednotkách souhrnu roku.
            $explained = ['income_taxable' => 0.0, 'expense_taxable' => 0.0];
            foreach ($groups as $g) {
                foreach (['money_amount', 'money_taxable', 'reclass', 'myucto_amount', 'myucto_taxable', 'myucto_unclassified'] as $field) {
                    $g[$field] = round($g[$field], 2);
                }
                $amountDiff = round($g['myucto_amount'] - $g['money_amount'], 2);
                $taxableDiff = round($g['myucto_taxable'] - $g['money_taxable'], 2);
                if (abs($amountDiff) < self::TOLERANCE && abs($taxableDiff) < self::TOLERANCE) {
                    continue;
                }
                $income = self::isIncome($g);
                if (abs($amountDiff) < self::TOLERANCE && abs($g['myucto_unclassified']) >= self::TOLERANCE
                    && abs($taxableDiff + $g['myucto_unclassified']) < self::TOLERANCE) {
                    $explained[$income ? 'income_taxable' : 'expense_taxable'] += $income ? $taxableDiff : -$taxableDiff;
                    continue;
                }
                $differences[] = $this->difference($ctx, $g, $income, $amountDiff, $taxableDiff);
            }
            $unclassifiedItems = [];
            foreach ($unclassified as $source => $amount) {
                if (abs($amount) >= self::TOLERANCE && isset($bySource[$source])) {
                    $unclassifiedItems[] = $this->unclassifiedItem($ctx, $bySource[$source], isset($index[$source]) ? $moneyByRow[$index[$source]] ?? null : null, $amount);
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
                $p->error(self::STEP, 'tax_records_extra_movement', self::listed(sprintf(
                    '%s: peněžní deník MyÚčta má %s, které v peněžním deníku Money nejsou (například úhrada dokladu bez bankovního nebo pokladního pohybu). Daňový základ by se zdvojil:',
                    $label, self::plural(count($extra), 'pohyb', 'pohyby', 'pohybů'),
                ), array_map(static fn (array $r): string => sprintf('– %s z %s (%s)%s', trim((string) $r['doc_no']) ?: 'pohyb #' . $r['source_id'],
                    self::date($r['date']), self::flow(self::signed($r)), trim((string) $r['partner']) !== '' ? ', ' . trim((string) $r['partner']) : ''), $extra)),
                    ['year' => $year, 'movements' => array_map(static fn (array $r): array => ['source_type' => $r['source_type'], 'source_id' => $r['source_id'],
                        'doc_no' => $r['doc_no'], 'date' => $r['date'], 'amount' => self::signed($r)], array_slice($extra, 0, ImportProtocol::LIST_LIMIT))]);
            }
            if (abs((float) $totals['nezarazeno']) >= self::TOLERANCE) {
                $head = sprintf('%s: peněžní deník MyÚčta má nezařazené pohyby za %s Kč, které nevstoupí do daňového základu', $label, self::money((float) $totals['nezarazeno']));
                $p->difference(self::STEP, 'tax_records_unclassified',
                    $unclassifiedItems !== [] ? self::listed($head . ':', array_column($unclassifiedItems, 'text')) : $head . '.',
                    ['year' => $year, 'movements' => array_column(array_slice($unclassifiedItems, 0, ImportProtocol::LIST_LIMIT), 'context')]);
            }
            if ($differences !== []) {
                $p->difference(self::STEP, 'tax_records_difference', self::listed(sprintf(
                    '%s: %s v peněžním deníku MyÚčta jinou částku nebo daňovou část úhrad než v Money:',
                    $label, self::plural(count($differences), 'doklad má', 'doklady mají', 'dokladů má'),
                ), array_column($differences, 'text')), ['year' => $year, 'differences' => array_column(array_slice($differences, 0, ImportProtocol::LIST_LIMIT), 'context')]);
            }
            if ($missing !== []) {
                $p->difference(self::STEP, 'tax_records_missing_movement', self::listed(sprintf(
                    '%s: %s peněžního deníku Money v MyÚčtu chybí:', $label, self::plural(count($missing), 'pohyb', 'pohyby', 'pohybů'),
                ), array_map(static fn (array $m): string => sprintf('– %s z %s (%s)', $m['document_no'], self::date($m['date']), self::flow($m['amount'])), $missing)),
                    ['year' => $year, 'documents' => array_map(static fn (array $m): array => ['document_no' => $m['document_no'], 'date' => $m['date'], 'amount' => $m['amount']],
                        array_slice($missing, 0, ImportProtocol::LIST_LIMIT))]);
            }
            if ($nonCash !== []) {
                $p->difference(self::STEP, 'tax_records_non_cash', self::listed(sprintf(
                    '%s: %s Money s daňovým příjmem nebo výdajem převod nepřebírá jako pohyb. Doplňte je ručně:',
                    $label, self::plural(count($nonCash), 'nepeněžní pohyb (zápočet)', 'nepeněžní pohyby (zápočty)', 'nepeněžních pohybů (zápočtů)'),
                ), array_map(static function (array $m): string {
                    $parts = [];
                    foreach ($m['parts'] as $part) {
                        $parts[($part['bucket'] ?? '') . '|' . $part['movement']] = ($parts[($part['bucket'] ?? '') . '|' . $part['movement']] ?? 0.0) + $part['amount'];
                    }
                    return sprintf('– %s z %s: %s', $m['document_no'], self::date($m['date']), self::parts($parts, 1));
                }, $nonCash)), ['year' => $year, 'documents' => array_map(static fn (array $m): array => ['document_no' => $m['document_no'], 'date' => $m['date'],
                    'taxable' => self::taxable($m['parts'])], array_slice($nonCash, 0, ImportProtocol::LIST_LIMIT))]);
            }
            foreach (['income_taxable' => 'daňové příjmy', 'expense_taxable' => 'daňové výdaje'] as $bucket => $text) {
                if (abs($summary['myucto'][$bucket] - $summary['money'][$bucket] - $explained[$bucket]) >= self::TOLERANCE && $differences === [] && $missing === []) {
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
     * Položka rozdílu za uhrazený doklad: doklad, pohyby, rozdělení úhrad v Money a v MyÚčtu
     * a nejpravděpodobnější příčina. Částky jsou ve směru dokladu (u výdaje kladně výdaj).
     *
     * @param array<string,mixed> $g skupina pohybů dokladu z {@see run()}
     * @return array{text:string,context:array<string,mixed>}
     */
    private function difference(ImportContext $ctx, array $g, bool $income, float $amountDiff, float $taxableDiff): array
    {
        $sign = $income ? 1 : -1;
        $tax = $income ? 'daňový příjem' : 'daňový výdaj';
        $paid = [];
        $zero = [];
        foreach ($g['movements'] as $m) {
            if (abs($m['amount']) < self::TOLERANCE) {
                $zero[] = 'nulový doklad ' . $m['document_no'] . ' z ' . self::date($m['date']);
            } else {
                $paid[] = $m['document_no'] . ' z ' . self::date($m['date']) . ' (' . self::money($sign * $m['amount']) . ' Kč)';
            }
        }
        $movements = implode(', ', [...($paid !== [] ? [(count($paid) > 1 ? 'úhrady ' : 'úhrada ') . implode(', ', $paid)] : []), ...$zero]);

        if ($g['myucto_rows'] === 0) {
            $myucto = 'bez pohybu';
        } else {
            $rest = round($g['myucto_amount'] - $g['myucto_taxable'] - $g['myucto_unclassified'], 2);
            $myucto = $tax . ' ' . self::money($sign * $g['myucto_taxable']) . ' Kč'
                . (abs($rest) >= self::TOLERANCE ? ', DPH a nedaňové ' . self::money($sign * $rest) . ' Kč' : '')
                . (abs($g['myucto_unclassified']) >= self::TOLERANCE ? ', nezařazeno ' . self::money($sign * $g['myucto_unclassified']) . ' Kč' : '');
        }

        $moneyVat = 0.0;
        foreach ($g['money_parts'] as $partKey => $amount) {
            if (str_starts_with($partKey, Ms3CashBook::VAT . '|')) {
                $moneyVat += $amount;
            }
        }
        $moneyVat = round($moneyVat, 2);
        $whole = static fn (float $a, float $b): bool => abs($a - $b) < self::TOLERANCE;
        $taxGenitive = $income ? 'daňového příjmu' : 'daňového výdaje';
        [$cause, $reason] = match (true) {
            abs($g['reclass']) >= self::TOLERANCE => ['zero_document', sprintf(
                'Nulový doklad %s v Money přeúčtoval %s Kč %s (typicky DPH zálohy při vyúčtování faktury), MyÚčto nulové doklady nepřebírá.',
                implode(', ', $g['reclass_documents']), self::money(abs($g['reclass'])), $sign * $g['reclass'] < 0 ? "z {$taxGenitive} do DPH" : "z DPH do {$taxGenitive}",
            )],
            abs($amountDiff) >= self::TOLERANCE => ['amount', sprintf('Pohyby jsou v MyÚčtu o %s Kč %s než v Money.',
                self::money(abs($amountDiff)), $sign * $amountDiff > 0 ? 'vyšší' : 'nižší')],
            $whole($g['money_taxable'], 0.0) && $whole($moneyVat, $g['money_amount']) => ['vat_only',
                'Money vede celou úhradu jako DPH, MyÚčto ji rozdělí na základ a DPH poměrem DPH dokladu.'],
            $whole($g['money_taxable'], 0.0) => ['money_not_taxable',
                'Money úhradu do daňového základu nezahrnul, MyÚčto ano (podle dokladu).'],
            $whole($g['myucto_taxable'], 0.0) => ['myucto_not_taxable',
                'MyÚčto úhradu do daňového základu nezahrnulo (doklad je v MyÚčtu nedaňový nebo záloha), Money ano.'],
            $whole($g['money_taxable'], $g['money_amount']) => ['money_without_vat',
                'Money vede celou úhradu jako daňovou bez DPH, MyÚčto z ní oddělí DPH poměrem DPH dokladu.'],
            default => ['vat_split', 'Money rozdělil úhradu na základ a DPH jinak, než ji MyÚčto rozdělí poměrem DPH dokladu.'],
        };
        if (abs($taxableDiff) >= self::TOLERANCE) {
            $reason .= sprintf(' %s je v MyÚčtu o %s Kč %s.', ucfirst($tax), self::money(abs($taxableDiff)), $sign * $taxableDiff > 0 ? 'vyšší' : 'nižší');
        }
        $subject = $this->subject($ctx, $g['link']);
        return [
            'text' => sprintf('– %s, %s. Money: %s. MyÚčto: %s. %s', $subject, $movements, self::parts($g['money_parts'], $sign), $myucto, $reason),
            'context' => [
                'document' => $subject, 'documents' => implode(', ', array_column($g['movements'], 'document_no')), 'cause' => $cause,
                'money_amount' => $g['money_amount'], 'myucto_amount' => $g['myucto_amount'],
                'money_taxable' => $g['money_taxable'], 'myucto_taxable' => $g['myucto_taxable'],
            ],
        ];
    }

    /**
     * Položka nezařazeného pohybu MyÚčta: celý pohyb bez dokladu a zařazení, nebo zbytek
     * pohybu nad navázané doklady, a jak ho vede Money.
     *
     * @param array<string,mixed> $row řádek peněžního deníku MyÚčta
     * @param array<string,mixed>|null $movement pohyb peněžního deníku Money
     * @return array{text:string,context:array<string,mixed>}
     */
    private function unclassifiedItem(ImportContext $ctx, array $row, ?array $movement, float $amount): array
    {
        $total = self::signed($row);
        $document = $movement['document_no'] ?? (trim((string) $row['doc_no']) ?: 'pohyb #' . $row['source_id']);
        $text = sprintf('– %s z %s (%s)', $document, self::date($movement['date'] ?? $row['date']), self::flow($total));
        $sign = $total >= 0 ? 1 : -1;
        if ($movement === null) {
            $text .= ': pohyb v peněžním deníku Money není.';
        } elseif (abs($amount - $total) < self::TOLERANCE) {
            $parts = [];
            foreach ($movement['parts'] as $part) {
                $parts[($part['bucket'] ?? '') . '|' . $part['movement']] = ($parts[($part['bucket'] ?? '') . '|' . $part['movement']] ?? 0.0) + $part['amount'];
            }
            $text .= ': v MyÚčtu bez navázaného dokladu a bez zařazení, Money ho vede jako ' . self::parts($parts, $sign)
                . '. Zařaďte ho v peněžním deníku MyÚčta.';
        } else {
            $documents = [];
            $rest = [];
            foreach ($movement['parts'] as $part) {
                if ($part['link'] !== null) {
                    $documents[$this->subject($ctx, $part['link'])] = true;
                } else {
                    $rest[($part['bucket'] ?? '') . '|' . $part['movement']] = ($rest[($part['bucket'] ?? '') . '|' . $part['movement']] ?? 0.0) + $part['amount'];
                }
            }
            $text .= sprintf(': %s %s Kč, zbytek %s Kč zůstane nezařazený (zařazení v peněžním deníku platí pro celý pohyb).',
                $documents !== [] ? 'navázané doklady (' . implode(', ', array_keys($documents)) . ') pokrývají jen' : 'navázané úhrady pokrývají jen',
                self::money(abs($total - $amount)), self::money(abs($amount)))
                . ($rest !== [] ? ' Money zbytek vede jako ' . self::parts($rest, $sign) . '.' : '');
        }
        return [
            'text' => $text,
            'context' => ['document_no' => $document, 'date' => $movement['date'] ?? $row['date'], 'amount' => $total, 'unclassified' => round($amount, 2)],
        ];
    }

    /** Uhrazený doklad, jak ho pojmenuje hláška („přijatá faktura FP25002"). */
    private function subject(ImportContext $ctx, ?array $link): string
    {
        if ($link === null) {
            return 'pohyb bez dokladu';
        }
        return self::LINK_LABELS[$link['table']] . ' ' . ($this->taxRecords->linkedDocumentNo($ctx, $link) ?? "č. {$link['number']} ({$link['dir']})");
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
        $link = self::link($movement);
        return $link !== null ? $link['table'] . '|' . $link['dir'] . '|' . $link['number'] : 'pohyb|' . $movement['source'] . '|' . $movement['number'];
    }

    /**
     * @param array{parts:list<array{link:?array{table:string,dir:string,number:int}}>} $movement
     * @return array{table:string,dir:string,number:int}|null
     */
    private static function link(array $movement): ?array
    {
        foreach ($movement['parts'] as $part) {
            if ($part['link'] !== null) {
                return $part['link'];
            }
        }
        return null;
    }

    /** Doklad skupiny je příjem (vydaná faktura, pohledávka), pohyb bez dokladu podle směru. */
    private static function isIncome(array $g): bool
    {
        $table = $g['link']['table'] ?? null;
        return $table !== null ? in_array($table, ['FV', 'KP'], true) : ($g['money_amount'] ?: $g['myucto_amount']) >= 0;
    }

    /** Řádek peněžního deníku MyÚčta se znaménkem peněžního toku (příjem +, výdaj −). */
    private static function signed(array $row): float
    {
        return round((float) ($row['income'] ?? 0) - (float) ($row['expense'] ?? 0), 2);
    }

    /**
     * Části pohybu Money podle zařazení a kódu pohybu („DPH 210,00 Kč (VP_DPH)") ve směru
     * dokladu.
     *
     * @param array<string,float> $parts "zařazení|kód pohybu" => částka se znaménkem peněžního toku
     */
    private static function parts(array $parts, int $sign): string
    {
        $out = [];
        foreach ($parts as $partKey => $amount) {
            if (abs($amount) < self::TOLERANCE) {
                continue;
            }
            [$bucket, $code] = explode('|', $partKey, 2);
            $out[] = (self::BUCKET_LABELS[$bucket] ?? $bucket) . ' ' . self::money($sign * $amount) . ' Kč' . ($code !== '' ? " ({$code})" : '');
        }
        return $out !== [] ? implode(', ', $out) : 'bez částky';
    }

    /**
     * Text hlášky: úvodní věta a položky po řádcích, nejvýš {@see EXAMPLES}.
     *
     * @param list<string> $items
     */
    private static function listed(string $head, array $items): string
    {
        $more = count($items) - self::EXAMPLES;
        return $head . "\n" . implode("\n", array_slice($items, 0, self::EXAMPLES)) . ($more > 0 ? "\n… a dalších {$more}." : '');
    }

    private static function plural(int $count, string $one, string $few, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : ($count >= 2 && $count <= 4 ? $few : $many));
    }

    private static function flow(float $amount): string
    {
        return ($amount >= 0 ? 'příjem ' : 'výdaj ') . self::money(abs($amount)) . ' Kč';
    }

    private static function date(?string $date): string
    {
        $time = $date !== null ? strtotime($date) : false;
        return $time !== false ? date('j. n. Y', $time) : 'neznámého dne';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
