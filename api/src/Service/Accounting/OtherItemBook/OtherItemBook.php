<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\OtherItemBook;

/**
 * Kniha ostatních pohledávek a závazků: hlavička sestavy, řádky čtené proudem po dávkách
 * a součty celého výběru.
 *
 * Řádky odpovídají výpisu: „Zbývá uhradit“ a stav počítají u rozdělených splátek i úrok
 * a poplatek, stejně jako sloupec a odznak výpisu. Součty jsou tytéž jako součty výpisu
 * (saldo jistiny, jen potvrzené a zaúčtované položky, přeplatek jedné položky nesnižuje
 * jinou), po straně a měně bez přepočtu: pohledávky a závazky se nesčítají.
 */
final class OtherItemBook
{
    public const BATCH_SIZE = 500;

    private bool $consumed = false;

    /**
     * @param array<string,mixed> $meta title, supplier, period_label, selection, scope_note, printed_by, printed_at
     * @param \Closure(array{0:string,1:int}|null, int): list<array<string,mixed>> $fetchBatch dávka už normalizovaných řádků
     * @param list<array{side:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}> $totals
     */
    public function __construct(
        public readonly array $meta,
        private readonly \Closure $fetchBatch,
        private readonly array $totals,
        public readonly bool $showPosting,
        public readonly string $domesticCurrency = 'CZK',
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
            $batch = ($this->fetchBatch)($after, self::BATCH_SIZE);
            foreach ($batch as $row) {
                yield $row;
            }
            $last = $batch === [] ? null : $batch[count($batch) - 1];
            $after = $last === null ? null : [(string) $last['due_on'], (int) $last['id']];
        } while (count($batch) === self::BATCH_SIZE);
    }

    /**
     * Součty po straně (pohledávky, pak závazky) a měně, domácí měna první.
     *
     * @return list<array{side:string,side_label:string,currency:string,count:int,amount:float,paid_amount:float,remaining_amount:float}>
     */
    public function totals(): array
    {
        $totals = $this->totals;
        usort($totals, fn (array $a, array $b): int =>
            [$a['side'] !== 'receivable', $a['currency'] !== $this->domesticCurrency, $a['currency']]
            <=> [$b['side'] !== 'receivable', $b['currency'] !== $this->domesticCurrency, $b['currency']]);
        return array_map(static fn (array $t): array => $t + [
            'side_label' => OtherItemBookService::SIDE_TOTAL_LABELS[$t['side']] ?? $t['side'],
        ], $totals);
    }
}
