<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice\Book;

/**
 * Kniha vystavených / přijatých faktur: hlavička sestavy a řádky čtené proudem po dávkách.
 *
 * Součty se sčítají za běhu {@see rows()}, takže {@see totals()} platí až po projití řádků
 * (PDF i XLSX píšou patičku až za tabulku).
 *
 * Součty jsou po měnách, bez přepočtu na CZK. „Částka" a „Zbývá uhradit" jsou v měně
 * dokladu; přepočet zbývající částky kurzem dokladu by neodpovídal tomu, kolik korun
 * skutečně přijde nebo odejde (kurzové rozdíly vznikají až při úhradě), a koncept ani
 * doklad bez kurzu by se přepočíst nedal.
 *
 * Do součtů nevstupují koncepty ani stornované doklady (v tabulce jsou, označené).
 * Daňový doklad k přijaté platbě má vlastní součet: peníze přišly nebo odešly už na
 * zálohovou fakturu, k úhradě není nic.
 */
final class InvoiceBook
{
    public const ISSUED = 'issued';
    public const RECEIVED = 'received';

    public const BATCH_SIZE = 500;

    /** @var array<string, array<string, float|int|string>> */
    private array $totals = [];
    private bool $consumed = false;

    /**
     * @param array<string,mixed> $meta title, supplier, period_label, selection, printed_by, printed_at
     * @param \Closure(array{0:string,1:int}|null, int): list<array<string,mixed>> $fetchBatch
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $meta,
        private readonly \Closure $fetchBatch,
        public readonly string $domesticCurrency = 'CZK',
    ) {}

    /** @return \Generator<int, array<string,mixed>> */
    public function rows(): \Generator
    {
        if ($this->consumed) {
            throw new \LogicException('Řádky knihy faktur lze projít jen jednou.');
        }
        $this->consumed = true;

        $after = null;
        do {
            $batch = ($this->fetchBatch)($after, self::BATCH_SIZE);
            foreach ($batch as $row) {
                $row = $this->normalize($row);
                $this->accumulate($row);
                yield $row;
            }
            $last = $batch === [] ? null : $batch[count($batch) - 1];
            $after = $last === null ? null : [(string) $last['number'], (int) $last['id']];
        } while (count($batch) === self::BATCH_SIZE);
    }

    /**
     * Součty po měnách, domácí měna první.
     *
     * @return list<array<string, float|int|string>>
     */
    public function totals(): array
    {
        $totals = $this->totals;
        uksort($totals, fn (string $a, string $b): int => [$a !== $this->domesticCurrency, $a] <=> [$b !== $this->domesticCurrency, $b]);
        return array_map(static function (array $t): array {
            foreach (['total', 'to_pay', 'remaining_invoices', 'remaining_credit_notes', 'tax_documents'] as $k) {
                $t[$k] = round((float) $t[$k], 2);
            }
            return $t;
        }, array_values($totals));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        $type = (string) ($row['type'] ?? 'invoice');
        $status = (string) ($row['status'] ?? '');
        $row['category'] = match ($type) {
            'credit_note' => 'credit_note',
            'tax_document' => 'tax_document',
            default => 'invoice',
        };
        $row['cancelled'] = $status === 'cancelled' || $type === 'cancellation';
        $row['draft'] = $status === 'draft';
        $row['counted'] = !$row['cancelled'] && !$row['draft'];

        $amount = (float) ($row['total_with_vat'] ?? 0);
        $toPay = (float) ($row['amount_to_pay'] ?? 0);
        $remaining = (float) ($row['remaining_amount'] ?? 0);
        // Dobropis snižuje (§ 4a ZDPH); i chybně zadaný s kladným součtem se počítá záporně,
        // stejně jako v součtech seznamu faktur.
        if ($row['category'] === 'credit_note') {
            $amount = -abs($amount);
            $toPay = -abs($toPay);
            $remaining = -abs($remaining);
        }
        $row['amount'] = round($amount, 2);
        $row['to_pay'] = round($toPay, 2);
        $row['remaining'] = round($remaining, 2);
        $row['duzp'] = $row['tax_date'] ?? null;
        $row['description'] = self::description($row);

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function accumulate(array $row): void
    {
        if (!$row['counted']) {
            return;
        }
        $cur = (string) ($row['currency'] ?? '');
        $t = &$this->totals[$cur];
        $t ??= [
            'currency' => $cur, 'count' => 0, 'total' => 0.0, 'to_pay' => 0.0,
            'remaining_invoices' => 0.0, 'remaining_credit_notes' => 0.0, 'tax_documents' => 0.0,
        ];
        $t['count']++;
        if ($row['category'] === 'tax_document') {
            $t['tax_documents'] += $row['amount'];
            return;
        }
        $t['total'] += $row['amount'];
        $t['to_pay'] += $row['to_pay'];
        if ($row['category'] === 'credit_note') {
            $t['remaining_credit_notes'] += $row['remaining'];
        } else {
            $t['remaining_invoices'] += $row['remaining'];
        }
    }

    /**
     * Hlavní položka, jinak první řádek textu nad položkami.
     *
     * @param array<string,mixed> $row
     */
    private static function description(array $row): string
    {
        $text = trim((string) ($row['main_item'] ?? ''));
        if ($text === '') {
            $note = trim(strip_tags((string) ($row['note_above_items'] ?? '')));
            $text = trim((string) strtok($note, "\r\n"));
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return mb_strlen($text) > 120 ? rtrim(mb_substr($text, 0, 119)) . '…' : $text;
    }
}
