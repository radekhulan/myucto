<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Přepočet rekapitulace DPH a celkových součtů faktury z cizí měny do CZK
 * při použití zafixovaného kurzu (invoices.exchange_rate).
 *
 * Zaokrouhlování: HALF_UP, 2 desetinná místa, **zvlášť per VAT skupina** —
 * tj. pro každou sazbu se zaokrouhlí přepočtený základ a DPH samostatně,
 * teprve potom se sčítají do `total_without_vat_czk` / `total_vat_czk`.
 * Tím se vyhneme drobným rozdílům 0,01 CZK způsobeným násobnými přepočty.
 *
 * Převzaté částky v Kč se v příslušné skupině přičtou přímo. Kurzem se násobí
 * pouze zbytek skupiny.
 */
final class CzkRecap
{
    /**
     * @param list<array{rate: float, base: float, vat: float}> $vatBreakdown
     * @param list<array<string,mixed>> $items
     * @return array{
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   breakdown: list<array{rate: float, base_czk: float, vat_czk: float, with_vat_czk: float}>,
     *   total_without_vat_czk: float,
     *   total_vat_czk: float,
     *   total_with_vat_czk: float
     * }
     */
    public static function build(
        array $vatBreakdown,
        float $exchangeRate,
        string $rateDate,
        bool $fallbackUsed = false,
        array $items = [],
        ?float $sourceTotal = null,
    ): array {
        $rows = [];
        $totalBase = 0.0;
        $totalVat  = 0.0;
        $imported = self::importedByRate($items);

        foreach ($vatBreakdown as $b) {
            $source = $imported[self::vatKey((float) $b['rate'])] ?? [];
            $baseCzk = self::groupAmount((float) $b['base'], $exchangeRate, $source['base'] ?? []);
            $vatCzk = self::groupAmount((float) $b['vat'], $exchangeRate, $source['vat'] ?? []);
            $rows[] = [
                'rate'         => (float) $b['rate'],
                'base_czk'     => $baseCzk,
                'vat_czk'      => $vatCzk,
                'with_vat_czk' => self::roundHalfUp($baseCzk + $vatCzk),
            ];
            $totalBase += $baseCzk;
            $totalVat  += $vatCzk;
        }

        $totalBase = self::roundHalfUp($totalBase);
        $totalVat  = self::roundHalfUp($totalVat);

        return [
            'rate'                  => $exchangeRate,
            'rate_decimals'         => self::rateDecimals($exchangeRate),
            'rate_date'             => $rateDate,
            'fallback_used'         => $fallbackUsed,
            'breakdown'             => $rows,
            'total_without_vat_czk' => $totalBase,
            'total_vat_czk'         => $totalVat,
            'total_with_vat_czk'    => $sourceTotal ?? self::roundHalfUp($totalBase + $totalVat),
        ];
    }

    /**
     * Počet desetinných míst pro tisk kurzu: všechna platná místa (min. 3, max. 6),
     * aby PDF ukazovalo přesně ten kurz, kterým se přepočítává (24,9999; 0,06123).
     */
    public static function rateDecimals(float $rate): int
    {
        $s = rtrim(sprintf('%.6F', $rate), '0');
        $dot = strpos($s, '.');
        $decimals = $dot === false ? 0 : strlen($s) - $dot - 1;
        return max(3, min(6, $decimals));
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{rate:float,vat_czk:float}>
     */
    public static function buildCzechVatForDocument(array $items, float $exchangeRate): array
    {
        if ($exchangeRate <= 0) return [];

        $items = array_filter($items, static fn (array $item): bool => empty($item['oss_applicable']));
        $imported = self::importedByRate($items);
        $groups = [];
        foreach ($items as $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
            $key = self::vatKey($rate);
            if (!isset($groups[$key])) {
                $groups[$key] = ['rate' => $rate, 'vat' => 0.0];
            }
            $groups[$key]['vat'] += (float) ($item['total_vat'] ?? 0);
        }

        $rows = [];
        foreach ($groups as $key => $group) {
            $vat = self::groupAmount(self::roundHalfUp($group['vat']), $exchangeRate, $imported[$key]['vat'] ?? []);
            if (abs($vat) < 0.000001) continue;
            $rows[] = [
                'rate'    => (float) $group['rate'],
                'vat_czk' => $vat,
            ];
        }
        usort($rows, static fn (array $a, array $b) => $b['rate'] <=> $a['rate']);
        return $rows;
    }

    /**
     * Přepočet jedné skupiny sazby rozdělený na řádky dokladu: Kč řádků dají v součtu
     * přesně Kč skupiny z rekapitulace ({@see build()}), tedy přepočet SOUČTU skupiny,
     * ne součet přepočtů řádků. Ty se rozcházejí o haléře (467,35 + 8,35 + 0,34 EUR
     * × 24,36 = 11 384,65 + 203,41 + 8,28 = 11 596,34 Kč po řádcích, 476,04 × 24,36
     * = 11 596,33 Kč za skupinu) a daňový doklad uvádí částku skupiny.
     *
     * Rozdíl se rozdělí metodou největšího zbytku: haléř dostane řádek, jehož přesný
     * součin zaokrouhlení posunulo nejdál opačným směrem. Řádek s nulovou částkou haléř
     * nedostane nikdy. Převzaté Kč (`import_tax_*_czk`) sem nepatří: rekapitulace je ke
     * skupině přičítá přímo a kurzem násobí jen zbytek, volající proto předává jen řádky
     * bez nich.
     *
     * @param array<array-key,float> $foreign částky řádků skupiny v měně dokladu
     * @return array<array-key,float> Kč řádků se stejnými klíči
     */
    public static function splitGroup(array $foreign, float $exchangeRate): array
    {
        if ($foreign === []) {
            return [];
        }
        $cents = [];
        $remainders = [];
        $sum = 0.0;
        foreach ($foreign as $key => $amount) {
            $amount = (float) $amount;
            $sum += $amount;
            $rounded = self::multiplyHalfUp($amount, $exchangeRate);
            $cents[$key] = (int) round($rounded * 100);
            $remainders[$key] = self::remainderUnits($amount, $exchangeRate, $rounded);
        }
        $diff = (int) round(self::multiplyHalfUp(round($sum, 2), $exchangeRate) * 100) - array_sum($cents);
        if ($diff !== 0) {
            $order = array_keys(array_filter($foreign, static fn ($amount): bool => abs((float) $amount) >= 0.005));
            $position = array_flip($order);
            usort($order, static fn ($a, $b): int => $diff > 0
                ? [$remainders[$b], abs((float) $foreign[$b]), $position[$a]] <=> [$remainders[$a], abs((float) $foreign[$a]), $position[$b]]
                : [$remainders[$a], -abs((float) $foreign[$a]), $position[$a]] <=> [$remainders[$b], -abs((float) $foreign[$b]), $position[$b]]);
            $step = $diff > 0 ? 1 : -1;
            for ($i = 0, $n = count($order); $diff !== 0 && $n > 0; $i++) {
                $cents[$order[$i % $n]] += $step;
                $diff -= $step;
            }
        }
        return array_map(static fn (int $c): float => $c / 100, $cents);
    }

    /**
     * Přesný součin částky a kurzu minus jeho zaokrouhlení, v jednotkách 1e-10 Kč (celé
     * číslo, aby shodné zbytky dvou řádků rozhodlo pořadí, ne šum floatu).
     */
    private static function remainderUnits(float $amount, float $exchangeRate, float $rounded): int
    {
        if (function_exists('bcmul')) {
            $product = bcmul(sprintf('%.6F', $amount), sprintf('%.6F', $exchangeRate), 10);
            return (int) bcmul(bcsub($product, sprintf('%.2F', $rounded), 10), '10000000000', 0);
        }
        return (int) round(($amount * $exchangeRate - $rounded) * 1e10);
    }

    /** @param list<array<string,mixed>> $items */
    private static function importedByRate(array $items): array
    {
        $groups = [];
        foreach (InvoiceItemKind::amountLines($items) as $item) {
            $key = self::vatKey((float) ($item['vat_rate_snapshot'] ?? 0));
            foreach (['base' => 'total_without_vat', 'vat' => 'total_vat'] as $kind => $field) {
                $source = $item["import_tax_{$kind}_czk"] ?? null;
                if ($source !== null) {
                    $groups[$key][$kind] ??= ['foreign' => 0.0, 'czk' => 0.0];
                    $groups[$key][$kind]['foreign'] += (float) $item[$field];
                    $groups[$key][$kind]['czk'] += (float) $source;
                }
            }
        }
        return $groups;
    }

    private static function vatKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    private static function groupAmount(float $value, float $exchangeRate, array $source): float
    {
        return self::roundHalfUp(
            ($source['czk'] ?? 0.0) + self::multiplyHalfUp($value - ($source['foreign'] ?? 0.0), $exchangeRate),
        );
    }

    /**
     * Násobení dvou floatů s HALF_UP zaokrouhlením na 2 dp. Používá bcmath kvůli
     * binární nepřesnosti — např. 21,00 × 24,365 = 511,665, ale ve floatu
     * 511,66499999..., takže `round(...HALF_UP)` vrací 511,66 místo 511,67.
     * S bcmath dostaneme přesný 511,67.
     *
     * **Veřejné jako SSOT přepočtu měny.** Registr SSOT vedl „CzkRecap (bcmath) vs
     * 3 další" jako neověřené hlášení agenta; měřením se potvrdilo, že drift je
     * REÁLNÝ — na 1,6 mil. kombinací částek a reálných kurzů ČNB se metody rozešly
     * 603× (např. 3,00 × 25,305 → bcmath 75,92, `round()` 75,91). Rozdíl je vždy
     * jeden haléř a vzniká, když přesný součin padne na půlhaléřovou hranici.
     * Sonda: `private/scripts/czk_conversion_sweep.php`.
     */
    public static function multiplyHalfUp(float $a, float $b): float
    {
        if (function_exists('bcmul')) {
            $sa = sprintf('%.6F', $a);
            $sb = sprintf('%.6F', $b);
            $product = bcmul($sa, $sb, 10);
            $bump = str_starts_with($product, '-') ? '-0.005' : '0.005';
            return (float) bcadd($product, $bump, 2);
        }
        return round($a * $b, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * HALF_UP zaokrouhlení už zaokrouhleného mezisoučtu (např. base+vat) — float
     * imprecision tu nehrozí, protože sčítáme dvě hodnoty s 2 dp.
     */
    private static function roundHalfUp(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
}
