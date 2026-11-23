<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Jak číst částky dokladu z Fakturoid API v3 a jak je porovnat s tím, co z nich
 * spočítá náš kalkulátor. Sdílí ho import ({@see FakturoidImportService}), jeho
 * zkouška nanečisto i oprava už převzatých dokladů
 * (`api/bin/fix-fakturoid-imported-amounts.php`), aby všechny tři cesty četly
 * zdroj stejně.
 *
 * Výpočet DPH tu NENÍ. Režim cen se převádí na `prices_include_vat` dokladu a DPH
 * spočítá {@see \MyInvoice\Service\Invoice\InvoiceMath}, rekapitulace přijatého
 * dokladu se převádí na jeho `vat_overrides` (§ 73 ZDPH), stejně jako u ručně
 * zadaného dokladu.
 *
 * Doklad bez DPH (neplátce, sazba 0 nebo chybějící) se chová přesně jako před
 * opravou #128: `prices_include_vat` zůstává 0, protože bez sazby cena s DPH nic
 * neznamená, a rekapitulace se nulovou sazbou nepřepisuje.
 */
final class FakturoidSourceDocument
{
    /** Rozdíl, který ještě považujeme za haléřové zaokrouhlení. */
    private const EPSILON = 0.005;

    /**
     * Největší rozdíl základu či daně jedné sazby, který se srovná rekapitulací.
     * Větší rozdíl není zaokrouhlení, ale nesoulad položek (sleva, chybějící řádek)
     * a ten se nepřepisuje, jen se doklad označí ke kontrole.
     */
    private const MAX_ALIGNABLE = 1.0;

    /**
     * Fakturoid `vat_price_mode`: `without_vat` (ceny položek bez DPH, výchozí)
     * nebo `from_total_with_vat` (ceny položek včetně DPH, #128).
     *
     * @param array<string,mixed> $doc
     */
    public static function pricesIncludeVat(array $doc): bool
    {
        if ((string) ($doc['vat_price_mode'] ?? '') !== 'from_total_with_vat') {
            return false;
        }
        foreach (($doc['lines'] ?? []) as $line) {
            if (is_array($line) && self::lineRate($line) > 0.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Daňová uznatelnost nákladu pro daň z příjmů (Fakturoid `tax_deductible`, výchozí
     * true). Neuznatelný je doklad jen při výslovném false; chybějící údaj zůstává
     * uznatelný jako před převzetím.
     *
     * @param array<string,mixed> $doc
     */
    public static function taxDeductible(array $doc): bool
    {
        $raw = $doc['tax_deductible'] ?? null;
        return $raw === null || filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Poměrný odpočet DPH přijatého dokladu (Fakturoid `proportional_vat_deduction`,
     * celé procento, výchozí 100) jako režim odpočtu dokladu (#229). Poměrný odpočet
     * podle § 75 je `vat_deduction = 'proportional'` s procentem, nula je doklad bez
     * nároku. Plný odpočet nebo chybějící údaj vrací null: režim pak určí výchozí
     * pravidlo zakládání dokladu, stejně jako před opravou.
     *
     * @param array<string,mixed> $doc
     * @return array{vat_deduction: string, vat_deduction_percent: float}|null
     */
    public static function vatDeduction(array $doc): ?array
    {
        $raw = $doc['proportional_vat_deduction'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }
        $percent = round((float) $raw, 2);
        if ($percent >= 100.0 || $percent < 0.0) {
            return null;
        }
        return $percent === 0.0
            ? ['vat_deduction' => 'none', 'vat_deduction_percent' => 0.0]
            : ['vat_deduction' => 'proportional', 'vat_deduction_percent' => $percent];
    }

    /** @param array<string,mixed> $line */
    public static function lineRate(array $line): float
    {
        return (float) ($line['vat_rate'] ?? 0);
    }

    /**
     * Jednotková cena v režimu dokladu: bez DPH, nebo s DPH u `from_total_with_vat`.
     * Do položky se ukládá tak, jak je, režim nese hlavička (`prices_include_vat`).
     *
     * @param array<string,mixed> $line
     */
    public static function lineUnitPrice(array $line): float
    {
        return (float) ($line['unit_price'] ?? 0);
    }

    /**
     * Jednotková cena s DPH (`unit_price_with_vat`), kterou Fakturoid u řádku vede vedle
     * ceny bez DPH. Null, když ji řádek nemá.
     *
     * @param array<string,mixed> $line
     */
    public static function lineUnitPriceWithVat(array $line): ?float
    {
        $value = $line['unit_price_with_vat'] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Druh vydaného dokladu Fakturoidu (`document_type`) v MyÚčtu. Daňový doklad
     * k přijaté platbě (`tax_document`) je náš `tax_document`, konečná faktura
     * k záloze (`final_invoice`) je běžná faktura s odpočtem zálohy, opravný doklad
     * (`correction`) dobropis.
     *
     * @param array<string,mixed> $doc
     */
    public static function issuedType(array $doc): string
    {
        return match ((string) ($doc['document_type'] ?? $doc['kind'] ?? 'invoice')) {
            'proforma'     => 'proforma',
            'correction'   => 'credit_note',
            'tax_document' => 'tax_document',
            default        => 'invoice',
        };
    }

    /**
     * Odpočty zdaněných záloh konečné faktury (`paid_advances`): každý řádek je daňový
     * doklad k přijaté platbě, jehož základ a daň konečná faktura odečítá (§ 37a ZDPH).
     * Fakturoid je vede mimo `lines` se zápornou cenou bez DPH (`price`) a daní (`vat`).
     *
     * @param array<string,mixed> $doc
     * @return list<array{id:int, number:string, rate:float, base:float, vat:float}>
     */
    public static function paidAdvances(array $doc): array
    {
        $out = [];
        foreach (($doc['paid_advances'] ?? []) as $row) {
            if (!is_array($row) || !is_numeric($row['price'] ?? null)) {
                continue;
            }
            $out[] = [
                'id'     => (int) ($row['id'] ?? 0),
                'number' => trim((string) ($row['number'] ?? '')),
                'rate'   => (float) ($row['vat_rate'] ?? 0),
                'base'   => -abs(round((float) $row['price'], 2)),
                'vat'    => -abs(round((float) ($row['vat'] ?? 0), 2)),
            ];
        }
        return $out;
    }

    /**
     * Odpočet nezdaněné zálohy na konečné faktuře: úhrada zálohové faktury, ke které
     * Fakturoid daňový doklad nevystavil. Tržbu ani daň nemění, jen snižuje částku
     * k úhradě. Fakturoid ji nevede v položkách, ale v rozdílu celkové a zbývající
     * částky dokladu (`total` − `remaining_amount`); vlastní úhrady konečné faktury
     * zbývající částku nesnižují. Vrací celou zaplacenou zálohu (může převyšovat
     * fakturu, strop řeší volající), u jiného druhu dokladu nulu.
     *
     * @param array<string,mixed> $doc
     */
    public static function untaxedAdvance(array $doc): float
    {
        if ((string) ($doc['document_type'] ?? '') !== 'final_invoice'
            || !is_numeric($doc['total'] ?? null) || !is_numeric($doc['remaining_amount'] ?? null)) {
            return 0.0;
        }
        $advance = round((float) $doc['total'] - (float) $doc['remaining_amount'], 2);
        return $advance > self::EPSILON ? $advance : 0.0;
    }

    /**
     * Zdrojová rekapitulace DPH (`vat_rates_summary`). Hlavičkové `subtotal` může
     * obsahovat zaokrouhlení, proto se porovnává s rekapitulací. Prázdná nebo
     * chybějící rekapitulace = není s čím porovnat.
     *
     * @param array<string,mixed> $doc
     * @return list<array{rate: float, base: float, vat: float}>
     */
    public static function vatSummary(array $doc): array
    {
        $out = [];
        foreach (($doc['vat_rates_summary'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['base']) || !is_numeric($row['base'])) {
                continue;
            }
            $key = number_format((float) ($row['vat_rate'] ?? 0), 2, '.', '');
            $out[$key] ??= ['rate' => (float) $key, 'base' => 0.0, 'vat' => 0.0];
            $out[$key]['base'] = round($out[$key]['base'] + (float) $row['base'], 2);
            $out[$key]['vat'] = round($out[$key]['vat'] + (float) ($row['vat'] ?? 0), 2);
        }
        return array_values($out);
    }

    /**
     * Zaokrouhlení celkové částky dokladu (`rounding_adjustment`). Jen haléřové
     * vyrovnání pod 1 jednotku měny, větší hodnota není zaokrouhlení.
     *
     * @param array<string,mixed> $doc
     */
    public static function roundingAdjustment(array $doc): float
    {
        $value = $doc['rounding_adjustment'] ?? null;
        if (!is_numeric($value)) {
            return 0.0;
        }
        $value = round((float) $value, 2);
        return abs($value) < 1.0 ? $value : 0.0;
    }

    /**
     * Rozdíly mezi vypočtenou rekapitulací a zdrojem, po sazbách. U přenesené daňové
     * povinnosti se porovnává jen základ: daň na dokladu dodavatele není.
     *
     * @param list<array{rate: float|int, base: float|int, vat: float|int}> $computed vat_breakdown z InvoiceMath
     * @param list<array{rate: float, base: float, vat: float}> $source
     * @return list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}>
     */
    public static function differences(array $computed, array $source, bool $reverseCharge = false): array
    {
        if ($source === []) {
            return [];
        }
        $calc = [];
        foreach ($computed as $row) {
            $key = number_format((float) $row['rate'], 2, '.', '');
            $calc[$key] ??= ['base' => 0.0, 'vat' => 0.0];
            $calc[$key]['base'] += (float) $row['base'];
            $calc[$key]['vat'] += (float) $row['vat'];
        }
        $src = [];
        foreach ($source as $row) {
            $src[number_format($row['rate'], 2, '.', '')] = $row;
        }

        $diffs = [];
        foreach (array_unique([...array_keys($calc), ...array_keys($src)]) as $key) {
            $key = (string) $key;
            $base = round($calc[$key]['base'] ?? 0.0, 2);
            $vat = round($calc[$key]['vat'] ?? 0.0, 2);
            $sBase = round($src[$key]['base'] ?? 0.0, 2);
            $sVat = round($src[$key]['vat'] ?? 0.0, 2);
            $vatDiffers = !$reverseCharge && abs($vat - $sVat) > self::EPSILON;
            if (abs($base - $sBase) > self::EPSILON || $vatDiffers) {
                $diffs[] = ['rate' => (float) $key, 'base' => $base, 'vat' => $vat, 'source_base' => $sBase, 'source_vat' => $sVat];
            }
        }
        return $diffs;
    }

    /**
     * Rekapitulace pro `purchase_invoices.vat_overrides`, která srovná přijatý doklad
     * na zdroj. Null, když rozdíl věrně převzít nejde (sazba na jedné straně chybí,
     * rozdíl je větší než zaokrouhlení, nebo jde o nulovou sazbu, kde rozdíl
     * základu není zaokrouhlení DPH). Prázdný seznam = není co srovnávat.
     *
     * @param list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}> $diffs
     * @param list<array{rate: float|int, base: float|int, vat: float|int}> $computed
     * @return list<array{rate: float, base: float, vat: float}>|null
     */
    public static function alignableOverrides(array $diffs, array $computed): ?array
    {
        if ($diffs === []) {
            return [];
        }
        $rates = [];
        foreach ($computed as $row) {
            $rates[number_format((float) $row['rate'], 2, '.', '')] = true;
        }
        $out = [];
        foreach ($diffs as $d) {
            $key = number_format($d['rate'], 2, '.', '');
            if ($d['rate'] <= 0.0 || !isset($rates[$key])
                || abs($d['base'] - $d['source_base']) > self::MAX_ALIGNABLE
                || abs($d['vat'] - $d['source_vat']) > self::MAX_ALIGNABLE) {
                return null;
            }
            $out[] = ['rate' => $d['rate'], 'base' => $d['source_base'], 'vat' => $d['source_vat']];
        }
        return $out;
    }

    /**
     * Lidská věta o rozdílech pro log, přehled úlohy i varování na dokladu.
     *
     * @param list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}> $diffs
     */
    public static function describe(array $diffs): string
    {
        $parts = [];
        foreach ($diffs as $d) {
            $parts[] = sprintf(
                'sazba %s %%: základ %s / DPH %s, ve Fakturoidu %s / %s',
                self::num($d['rate']),
                self::num($d['base']),
                self::num($d['vat']),
                self::num($d['source_base']),
                self::num($d['source_vat']),
            );
        }
        return 'Částky se liší od Fakturoidu (' . implode('; ', $parts) . ').';
    }

    private static function num(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
