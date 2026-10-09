<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Pure-function výpočty částek faktury — bez DB, bez závislostí.
 * Volá se z `InvoiceCalculator` (DB layer) i přímo z testů.
 *
 * Pravidla (režim ZDOLA, default — ceny položek jsou BEZ DPH):
 *  - Per item: total_without_vat = round(quantity * unit_price, 2)
 *              total_vat         = round(total_without_vat * rate / 100, 2)
 *              total_with_vat    = total_without_vat + total_vat
 *    (dělíme až nakonec base*rate/100, ne base*(rate/100) — viz issue #82, jinak
 *     float reprezentace koeficientu srazí 31,815 na 31,81 místo 31,82.)
 *  - Reverse charge (přenesená daňová povinnost): nominální sazba (21 %) ZŮSTÁVÁ pro
 *    zobrazení i breakdown, ale daň = 0 (dodavatel ji nevybírá, odvede ji zákazník).
 *    Řádek smí příznak dokladu přebít klíčem `reverse_charge` (bool): smíšený přijatý
 *    doklad nese vedle řádku § 92a i tuzemský řádek s daní dodavatele. O tom rozhoduje
 *    volající přes {@see \MyInvoice\Service\Report\VatLedgerService::isSelfAssessedDocumentLine()}.
 *  - Faktura: SUM jednotlivých položek
 *
 * Režim SHORA ($pricesIncludeVat = true — ceny položek jsou VČETNĚ DPH, typicky
 * účtenky/paragony/B2C): zdrojem pravdy řádku je cena S DPH (gross). DPH se počítá
 * koeficientovou metodou (§ 37 ZDPH): vat = round(gross * rate/(100+rate), 2),
 * base = gross - vat. Aby součet řádkové daně přesně odpovídal dani z celkového
 * základu dané sazby (a tedy sedělo DPH přiznání / KH / kniha DPH, které sčítají
 * uložené řádkové total_vat), dorovnáme zaokrouhlovací reziduum per sazba na
 * řádku s nejvyšší částkou (rounding distribution).
 *
 * Override rekapitulace DPH ($vatOverrides — jen přijaté faktury, § 73 ZDPH): když
 * doklad dodavatele zaokrouhlí DPH jinak, lze předat cílový základ/daň per sazba.
 * Reziduum vůči vypočtenému se dorovná na nejsilnějším řádku dané sazby (stejný
 * princip jako koeficientová distribuce výše) → uložené řádkové totály sednou na
 * doklad a VatLedger (čte je) reportuje přesně.
 */
final class InvoiceMath
{
    /**
     * @param list<array{quantity: float|int, duration_minutes?: int|null, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     *        V režimu shora je `unit_price_without_vat` chápán jako cena S DPH (gross).
     * @param list<array{rate: float|int, base?: float|int|null, vat?: float|int|null}> $vatOverrides
     *        Ruční rekapitulace DPH per sazba (přijaté faktury). Prázdné = bez overridu.
     * @return array{
     *     items: list<array{base: float, vat: float, with: float, rate: float}>,
     *     totals: array{without_vat: float, vat: float, with_vat: float},
     *     vat_breakdown: list<array{rate: float, base: float, vat: float}>
     * }
     */
    public static function compute(array $items, bool $reverseCharge = false, bool $pricesIncludeVat = false, array $vatOverrides = []): array
    {
        $perItem = $pricesIncludeVat
            ? self::linesTopDown($items, $reverseCharge)
            : self::linesBottomUp($items, $reverseCharge);

        $perItem = self::applyRateOverrides($perItem, $vatOverrides, $reverseCharge);

        return ['items' => $perItem] + self::summarize($perItem);
    }

    /**
     * Režim ZDOLA — ceny položek jsou bez DPH (netto). DPH zdola na řádek.
     *
     * @param list<array{quantity: float|int, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     * @return list<array{base: float, vat: float, with: float, rate: float}>
     */
    private static function linesBottomUp(array $items, bool $reverseCharge): array
    {
        $perItem = [];
        foreach ($items as $item) {
            // Nominální sazba zůstává (zobrazení + breakdown bucket); u RC se nuluje jen DAŇ.
            $rate  = (float) $item['vat_rate_snapshot'];

            $base = TimeBilling::invoiceAmount($item);
            // Dělit až nakonec (base*rate/100), ne base*(rate/100) — viz issue #82.
            $vat  = self::lineReverseCharge($item, $reverseCharge) ? 0.0 : round($base * $rate / 100.0, 2);
            $with = round($base + $vat, 2);

            $perItem[] = ['base' => $base, 'vat' => $vat, 'with' => $with, 'rate' => $rate];
        }
        return $perItem;
    }

    /**
     * Režim SHORA — ceny položek jsou včetně DPH (gross). Viz docblock třídy.
     *
     * @param list<array{quantity: float|int, duration_minutes?: int|null, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     * @return list<array{base: float, vat: float, with: float, rate: float}>
     */
    private static function linesTopDown(array $items, bool $reverseCharge): array
    {
        // 1. průchod — per řádek gross + provizorní daň koeficientem; seskup dle sazby.
        $lines = [];      // i => ['gross','rate','vat','base']
        $rateGroups = []; // rateKey => ['rate','grossSum','idx'[],'maxIdx','maxGross']
        foreach ($items as $i => $item) {
            $rate  = (float) $item['vat_rate_snapshot'];

            $gross = TimeBilling::invoiceAmount($item);
            $lineRc = self::lineReverseCharge($item, $reverseCharge);
            // Koeficient rate/(100+rate); u rate=0 i RC vychází daň 0.
            $vat   = ($lineRc || $rate <= 0.0) ? 0.0 : round($gross * $rate / (100.0 + $rate), 2);
            $base  = round($gross - $vat, 2);
            $lines[$i] = ['gross' => $gross, 'rate' => $rate, 'vat' => $vat, 'base' => $base];
            if ($lineRc) {
                continue; // daň 0 → do dorovnání sazby nevstupuje
            }

            $key = number_format($rate, 2, '.', '');
            if (!isset($rateGroups[$key])) {
                $rateGroups[$key] = ['rate' => $rate, 'grossSum' => 0.0, 'idx' => [], 'maxIdx' => $i, 'maxGross' => -1.0];
            }
            $rateGroups[$key]['grossSum'] += $gross;
            $rateGroups[$key]['idx'][] = $i;
            if (abs($gross) > $rateGroups[$key]['maxGross']) {
                $rateGroups[$key]['maxGross'] = abs($gross);
                $rateGroups[$key]['maxIdx']   = $i;
            }
        }

        // 2. průchod — dorovnat zaokrouhlovací reziduum daně per sazba na nejsilnějším
        // řádku, aby SUM(řádkový vat) == daň z celkového gross dané sazby (koeficient).
        foreach ($rateGroups as $g) {
            if ($g['rate'] <= 0.0) {
                continue; // daň 0 → není co dorovnávat
            }
            $grossSum  = round($g['grossSum'], 2);
            $targetVat = round($grossSum * $g['rate'] / (100.0 + $g['rate']), 2);
            $sumLineVat = 0.0;
            foreach ($g['idx'] as $i) {
                $sumLineVat += $lines[$i]['vat'];
            }
            $residual = round($targetVat - round($sumLineVat, 2), 2);
            if ($residual !== 0.0) {
                $mi = $g['maxIdx'];
                $lines[$mi]['vat']  = round($lines[$mi]['vat'] + $residual, 2);
                $lines[$mi]['base'] = round($lines[$mi]['gross'] - $lines[$mi]['vat'], 2);
            }
        }

        // 3. normalizuj na společný tvar (with = gross).
        $perItem = [];
        foreach ($items as $i => $_) {
            $l = $lines[$i];
            $perItem[] = ['base' => $l['base'], 'vat' => $l['vat'], 'with' => $l['gross'], 'rate' => $l['rate']];
        }
        return $perItem;
    }

    /** @param array<string,mixed> $item */
    private static function lineReverseCharge(array $item, bool $reverseCharge): bool
    {
        return array_key_exists('reverse_charge', $item) && $item['reverse_charge'] !== null
            ? (bool) $item['reverse_charge']
            : $reverseCharge;
    }

    /**
     * Aplikuj ruční rekapitulaci DPH per sazba (přijaté faktury, § 73 ZDPH). Reziduum
     * vůči vypočtenému základu/dani se přičte na nejsilnější řádek (max |base|) dané
     * sazby; `with` se přepočítá. U reverse-charge se override ignoruje (na dokladu
     * zahraničního dodavatele není česká DPH k srovnání).
     *
     * @param list<array{base: float, vat: float, with: float, rate: float}> $perItem
     * @param list<array{rate: float|int, base?: float|int|null, vat?: float|int|null}> $vatOverrides
     * @return list<array{base: float, vat: float, with: float, rate: float}>
     */
    private static function applyRateOverrides(array $perItem, array $vatOverrides, bool $reverseCharge): array
    {
        if ($reverseCharge || $vatOverrides === [] || $perItem === []) {
            return $perItem;
        }

        // Normalizuj override na rateKey => ['base'=>?float, 'vat'=>?float].
        $ov = [];
        foreach ($vatOverrides as $o) {
            if (!isset($o['rate'])) {
                continue;
            }
            $key = number_format((float) $o['rate'], 2, '.', '');
            $entry = [];
            if (array_key_exists('base', $o) && $o['base'] !== null) {
                $entry['base'] = round((float) $o['base'], 2);
            }
            if (array_key_exists('vat', $o) && $o['vat'] !== null) {
                $entry['vat'] = round((float) $o['vat'], 2);
            }
            if ($entry !== []) {
                $ov[$key] = $entry;
            }
        }
        if ($ov === []) {
            return $perItem;
        }

        // Seskup řádky dle sazby + najdi nejsilnější (max |base|).
        $groups = []; // key => ['idx'=>int[], 'maxIdx'=>int, 'maxAbs'=>float]
        foreach ($perItem as $i => $l) {
            $key = number_format((float) $l['rate'], 2, '.', '');
            if (!isset($groups[$key])) {
                $groups[$key] = ['idx' => [], 'maxIdx' => $i, 'maxAbs' => -1.0];
            }
            $groups[$key]['idx'][] = $i;
            $abs = abs((float) $l['base']);
            if ($abs > $groups[$key]['maxAbs']) {
                $groups[$key]['maxAbs'] = $abs;
                $groups[$key]['maxIdx'] = $i;
            }
        }

        foreach ($ov as $key => $entry) {
            if (!isset($groups[$key])) {
                continue; // override pro sazbu, která na faktuře není → ignoruj
            }
            $g  = $groups[$key];
            $mi = $g['maxIdx'];

            if (isset($entry['vat'])) {
                $sumVat = 0.0;
                foreach ($g['idx'] as $i) {
                    $sumVat += (float) $perItem[$i]['vat'];
                }
                $residual = round($entry['vat'] - round($sumVat, 2), 2);
                if ($residual !== 0.0) {
                    $perItem[$mi]['vat'] = round((float) $perItem[$mi]['vat'] + $residual, 2);
                }
            }
            if (isset($entry['base'])) {
                $sumBase = 0.0;
                foreach ($g['idx'] as $i) {
                    $sumBase += (float) $perItem[$i]['base'];
                }
                $residual = round($entry['base'] - round($sumBase, 2), 2);
                if ($residual !== 0.0) {
                    $perItem[$mi]['base'] = round((float) $perItem[$mi]['base'] + $residual, 2);
                }
            }
            $perItem[$mi]['with'] = round((float) $perItem[$mi]['base'] + (float) $perItem[$mi]['vat'], 2);
        }

        return $perItem;
    }

    /**
     * Sestav invoice totals + vat_breakdown ze (případně přepsaných) řádků.
     *
     * @param list<array{base: float, vat: float, with: float, rate: float}> $perItem
     * @return array{totals: array{without_vat: float, vat: float, with_vat: float}, vat_breakdown: list<array{rate: float, base: float, vat: float}>}
     */
    private static function summarize(array $perItem): array
    {
        $totalWithoutVat = 0.0;
        $totalVat = 0.0;
        $vatBuckets = [];
        foreach ($perItem as $l) {
            $totalWithoutVat += $l['base'];
            $totalVat        += $l['vat'];

            $key = number_format((float) $l['rate'], 2, '.', '');
            if (!isset($vatBuckets[$key])) {
                $vatBuckets[$key] = ['rate' => $l['rate'], 'base' => 0.0, 'vat' => 0.0];
            }
            $vatBuckets[$key]['base'] += $l['base'];
            $vatBuckets[$key]['vat']  += $l['vat'];
        }

        $totalWithoutVat = round($totalWithoutVat, 2);
        $totalVat        = round($totalVat, 2);
        $totalWithVat    = round($totalWithoutVat + $totalVat, 2);

        $breakdown = array_values(array_map(static fn (array $b) => [
            'rate' => $b['rate'],
            'base' => round($b['base'], 2),
            'vat'  => round($b['vat'], 2),
        ], $vatBuckets));
        usort($breakdown, static fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return [
            'totals' => [
                'without_vat' => $totalWithoutVat,
                'vat'         => $totalVat,
                'with_vat'    => $totalWithVat,
            ],
            'vat_breakdown' => $breakdown,
        ];
    }
}
