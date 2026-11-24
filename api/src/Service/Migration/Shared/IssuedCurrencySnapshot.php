<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

final class IssuedCurrencySnapshot
{
    private const ITEM_TOLERANCE_CENTS = 100;
    private const DOCUMENT_TOLERANCE_CENTS = 500;

    /**
     * @param array<string,mixed> $amounts Částky v Kč před převzetím, případně výslovný odpočet zálohy v měně.
     * @return array<string,mixed>|string Částky v měně a korunový otisk, nebo důvod pro převzetí v Kč.
     */
    public static function prepare(array $amounts, ?float $rate, ?float $foreignTotal): array|string
    {
        if ($rate === null || !is_finite($rate) || $rate <= 0.0) {
            return 'doklad nemá kurz';
        }
        if ($amounts['items'] === []) {
            return 'doklad nemá položky';
        }
        if (!empty($amounts['summary_mismatch'])) {
            return 'korunové položky nesouhlasí s celkem ze zdroje';
        }

        $advance = (float) ($amounts['advance'] ?? 0.0);
        $foreignAdvance = round((float) ($amounts['foreign_advance'] ?? 0.0), 2);
        if (!is_finite($advance) || !is_finite($foreignAdvance) || !self::sameSign($foreignAdvance, $advance)
            || ($foreignAdvance !== 0.0 && ($foreignTotal === null || self::difference($foreignAdvance, $rate, $advance) > self::ITEM_TOLERANCE_CENTS))) {
            return 'odpočet nedaňové zálohy - zdroj ho vede v Kč nebo nemá doloženou odpovídající částku v měně dokladu';
        }

        $deviation = 0;
        foreach (array_values($amounts['items']) as $i => $item) {
            $lineDeviation = 0;
            foreach (['base' => 'základ', 'vat' => 'daň'] as $key => $label) {
                $foreign = $item['foreign_' . $key] ?? null;
                $home = (float) $item[$key];
                if (!is_numeric($foreign) || !is_finite((float) $foreign) || !is_finite($home)) {
                    return 'položky dokladu nemají částky v měně dokladu';
                }
                $lineDeviation += self::difference((float) $foreign, $rate, $home);
                if (!self::sameSign((float) $foreign, $home) || $lineDeviation > self::ITEM_TOLERANCE_CENTS) {
                    return sprintf('%s %d. položky %s × kurz %s dává %s Kč, zdroj má %s Kč, odchylka položky je mimo toleranci',
                        $label, $i + 1, number_format((float) $foreign, 2, ',', ' '),
                        rtrim(rtrim(number_format($rate, 6, ',', ''), '0'), ','),
                        number_format(ForeignCurrencyTakeover::toHome((float) $foreign, $rate), 2, ',', ' '),
                        number_format($home, 2, ',', ' '));
                }
            }
            $deviation += $lineDeviation;
        }
        $limit = min(self::DOCUMENT_TOLERANCE_CENTS, count($amounts['items']) * self::ITEM_TOLERANCE_CENTS);
        if ($deviation > $limit) {
            return 'součet odchylek položek překračuje toleranci dokladu';
        }

        $items = ForeignCurrencyTakeover::foreignItems($amounts['items']);
        $totals = ForeignCurrencyTakeover::totals($items);
        $gross = $totals['total'];
        if ($foreignTotal !== null) {
            $foreignTotal = round($foreignTotal, 2);
            if ($foreignAdvance !== 0.0 && self::cents($foreignTotal - $gross + $foreignAdvance) === 0) {
                $foreignTotal = round($foreignTotal + $foreignAdvance, 2);
            }
            if (!is_finite($foreignTotal)
                || abs(self::cents(ForeignCurrencyTakeover::toHome($foreignTotal - $gross, $rate))) > self::ITEM_TOLERANCE_CENTS) {
                return 'celkem v měně dokladu nesouhlasí se součtem položek';
            }
            $gross = $foreignTotal;
        }

        $homeTotal = (float) $amounts['total'];
        if (!is_finite($homeTotal)) {
            return 'celkem v Kč ze zdroje není platná částka';
        }
        $homeDue = round($homeTotal - $advance, 2);
        $due = round($gross - $foreignAdvance, 2);
        foreach ([[$gross, $homeTotal], [$due, $homeDue]] as [$foreign, $home]) {
            if (!self::sameSign($foreign, $home) || self::difference($foreign, $rate, $home) > $limit) {
                return 'celkem v měně po přepočtu neodpovídá Kč ze zdroje v toleranci dokladu';
            }
        }

        return [
            'items' => $items,
            'base' => $totals['base'],
            'vat' => $totals['vat'],
            'total' => $gross,
            'rounding' => round($gross - $totals['total'], 2),
            'advance' => $foreignAdvance,
            'snapshot' => [
                'import_total_without_vat_czk' => round((float) $amounts['base'], 2),
                'import_total_vat_czk' => round((float) $amounts['vat'], 2),
                'import_total_with_vat_czk' => $homeTotal,
                'import_amount_to_pay_czk' => $homeDue,
                'import_advance_paid_czk' => $advance,
            ],
        ] + $amounts;
    }

    private static function difference(float $foreign, float $rate, float $home): int
    {
        return abs(self::cents(ForeignCurrencyTakeover::toHome($foreign, $rate)) - self::cents($home));
    }

    private static function sameSign(float $foreign, float $home): bool
    {
        return (self::cents($foreign) <=> 0) === (self::cents($home) <=> 0);
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
