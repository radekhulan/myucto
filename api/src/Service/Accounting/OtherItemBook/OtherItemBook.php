<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\OtherItemBook;

/**
 * Kniha ostatních pohledávek a závazků: hlavička sestavy a řádky čtené proudem po dávkách.
 *
 * Řádky odpovídají výpisu: „Zbývá uhradit“ a stav počítají u rozdělených splátek i úrok
 * a poplatek, stejně jako sloupec a odznak výpisu.
 *
 * Součty se sčítají za běhu {@see rows()} z tisknutých řádků (jako kniha faktur), takže
 * {@see totals()} platí až po projití řádků a vždy odpovídá tomu, co sestava ukazuje.
 * Pravidlo je totéž jako u součtů výpisu (`OtherItemRepository::remainingTotals`): jen
 * potvrzené a zaúčtované položky, zbývá uhradit jako saldo jistiny a přeplatek jedné položky
 * nesnižuje jinou; po straně a měně bez přepočtu, pohledávky a závazky se nesčítají.
 */
final class OtherItemBook
{
    public const BATCH_SIZE = 500;

    /** @var array<string, array{side:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}> */
    private array $totals = [];
    private bool $consumed = false;
    private bool $exhausted = false;

    /**
     * @param array<string,mixed> $meta title, supplier, period_label, selection, scope_note, printed_by, printed_at
     * @param \Closure(array{0:string,1:int}|null, int): list<array<string,mixed>> $fetchBatch dávka už normalizovaných řádků
     */
    public function __construct(
        public readonly array $meta,
        private readonly \Closure $fetchBatch,
        public readonly bool $showPosting,
        public readonly string $domesticCurrency = 'CZK',
        private readonly int $batchSize = self::BATCH_SIZE,
    ) {}

    /** @return \Generator<int, array<string,mixed>> */
    public function rows(): \Generator
    {
        if ($this->consumed) {
            throw new \LogicException('Řádky knihy ostatních položek lze projít jen jednou.');
        }
        $this->consumed = true;

        $after = null;
        do {
            $batch = ($this->fetchBatch)($after, $this->batchSize);
            foreach ($batch as $row) {
                $this->accumulate($row);
                yield $row;
            }
            $last = $batch === [] ? null : $batch[count($batch) - 1];
            $after = $last === null ? null : [(string) $last['book_sort_key'], (int) $last['id']];
        } while (count($batch) === $this->batchSize);
        $this->exhausted = true;
    }

    /**
     * Součty po straně (pohledávky, pak závazky) a měně, domácí měna první.
     *
     * @return list<array{side:string,side_label:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}>
     */
    public function totals(): array
    {
        if (!$this->exhausted) {
            throw new \LogicException('Součty knihy ostatních položek platí až po projití všech řádků.');
        }
        $totals = array_values($this->totals);
        usort($totals, fn (array $a, array $b): int =>
            [$a['side'] !== 'receivable', $a['currency'] !== $this->domesticCurrency, $a['currency']]
            <=> [$b['side'] !== 'receivable', $b['currency'] !== $this->domesticCurrency, $b['currency']]);
        return array_map(static function (array $t): array {
            foreach (['amount', 'paid_amount', 'remaining_amount'] as $k) {
                $t[$k] = round($t[$k], 2);
            }
            return $t + ['side_label' => OtherItemBookService::SIDE_TOTAL_LABELS[$t['side']] ?? $t['side']];
        }, $totals);
    }

    /** @param array<string,mixed> $row */
    private function accumulate(array $row): void
    {
        if (!$row['counted']) {
            return;
        }
        $side = (string) $row['side'];
        $currency = (string) $row['currency'];
        $t = &$this->totals[$side . '|' . $currency];
        $t ??= ['side' => $side, 'currency' => $currency, 'count' => 0, 'amount' => 0.0, 'paid_amount' => 0.0, 'remaining_amount' => 0.0];
        $t['count']++;
        $t['amount'] += (float) $row['amount'];
        $t['paid_amount'] += (float) $row['paid_amount'];
        // Saldo jistiny jako součty výpisu: přeplatek (záporný zůstatek) se počítá jako nula.
        $t['remaining_amount'] += max((float) $row['remaining_amount'], 0.0);
    }
}
