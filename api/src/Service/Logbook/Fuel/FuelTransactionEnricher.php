<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

use MyInvoice\Service\Invoice\UnitPricePrecision;

/**
 * Doplní vytěženým transakcím chybějící údaje z faktury (page 1) jako fallback,
 * když detailní výpis (page 2) nestačí:
 *
 *   • Množství (litry): starší zhuštěný Axigon formát neumí spolehlivě rozdělit
 *     zřetězené hodnoty → quantity zůstává null. Litry ale jsou jako strukturovaná
 *     položka na první straně faktury (purchase_invoice_items). Doplníme je sem;
 *     u více transakcí téhož paliva rozdělíme úhrn litrů proporčně dle částky bez DPH
 *     (u jediné transakce přesně, u stejné jednotkové ceny i u více transakcí přesně).
 *   • Datum: primárně z položky tankování (parser str. 2). Když chybí, fallback na
 *     DUZP faktury (tax_date), jinak datum vystavení.
 *
 * Nikdy nepřepisuje hodnoty, které už z detailu přišly — pouze doplňuje prázdné.
 */
final class FuelTransactionEnricher
{
    /**
     * @param list<array<string,mixed>> $transactions
     * @param array<string,mixed>       $invoice
     * @return list<array<string,mixed>>
     */
    public function enrich(array $transactions, array $invoice): array
    {
        $fallbackDate = $this->taxDate($invoice);
        foreach ($transactions as &$t) {
            if (trim((string) ($t['fueled_date'] ?? '')) === '' && $fallbackDate !== null) {
                $t['fueled_date'] = $fallbackDate;
            }
        }
        unset($t);

        $this->fillQuantitiesFromItems($transactions, $invoice);
        return $transactions;
    }

    /** DUZP (tax_date) → datum vystavení. */
    private function taxDate(array $invoice): ?string
    {
        foreach (['tax_date', 'issue_date'] as $k) {
            $v = trim((string) ($invoice[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return null;
    }

    /**
     * Doplní quantity (a unit_price) palivovým transakcím bez množství z palivových
     * položek faktury. Úhrn litrů dané palivové položky rozdělí proporčně dle základu.
     *
     * @param list<array<string,mixed>> $transactions
     */
    private function fillQuantitiesFromItems(array &$transactions, array $invoice): void
    {
        $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];

        // Úhrn množství (litry/kWh) a jednotková cena per normalizovaný typ paliva z položek faktury.
        $pool = []; // normType => ['liters' => float, 'unit_price' => ?float, 'unit' => string]
        foreach ($items as $it) {
            $desc = (string) ($it['description'] ?? '');
            if ($desc === '' || !FuelKeywords::isFuel($desc)) continue;
            $qty = isset($it['quantity']) ? (float) $it['quantity'] : 0.0;
            if ($qty <= 0) continue;
            $key = FuelKeywords::normalize($desc);
            $pool[$key] ??= ['liters' => 0.0, 'unit_price' => null, 'unit' => FuelKeywords::canonicalUnit($it['unit'] ?? null, $desc)];
            $pool[$key]['liters'] += $qty;
            if ($pool[$key]['unit_price'] === null && isset($it['unit_price_without_vat']) && $it['unit_price_without_vat'] !== null) {
                $pool[$key]['unit_price'] = (float) $it['unit_price_without_vat'];
            }
        }
        if ($pool === []) return;

        $soleType = count($pool) === 1 ? array_key_first($pool) : null;

        // Palivové transakce bez množství → seskup dle přiřazené palivové položky.
        $groups = []; // poolKey => list<int index>
        foreach ($transactions as $i => $t) {
            if (empty($t['is_fuel'])) continue;
            $q = $t['quantity'] ?? null;
            if ($q !== null && (float) $q > 0) continue; // už má litry z detailu

            $norm = FuelKeywords::normalize((string) ($t['fuel_type'] ?? ''));
            $key = $this->matchPoolKey($norm, $pool) ?? $soleType;
            if ($key === null) continue; // nelze jednoznačně přiřadit
            $groups[$key][] = $i;
        }

        foreach ($groups as $key => $idxs) {
            $liters = $pool[$key]['liters'];
            if ($liters <= 0) continue;

            $weights = [];
            $sumW = 0.0;
            foreach ($idxs as $i) {
                $w = (float) ($transactions[$i]['amount_without_vat'] ?? 0);
                if ($w <= 0) $w = (float) ($transactions[$i]['amount_with_vat'] ?? 0);
                $weights[$i] = max(0.0, $w);
                $sumW += $weights[$i];
            }
            $n = count($idxs);
            foreach ($idxs as $i) {
                $share = $sumW > 0 ? $weights[$i] / $sumW : 1.0 / $n;
                $qty = round($liters * $share, 2);
                if ($qty <= 0) continue;
                $transactions[$i]['quantity'] = $qty;
                $transactions[$i]['unit'] = $pool[$key]['unit'];
                $base = (float) ($transactions[$i]['amount_without_vat'] ?? 0);
                if ($base > 0) {
                    $transactions[$i]['unit_price'] = UnitPricePrecision::normalize($base / $qty);
                } elseif ($pool[$key]['unit_price'] !== null) {
                    $transactions[$i]['unit_price'] = $pool[$key]['unit_price'];
                }
            }
        }
    }

    /**
     * Přiřadí normalizovaný typ paliva transakce ke klíči poolu položek (přesná shoda
     * nebo oboustranné containment, např. „premiova nafta" ⊃ „nafta").
     *
     * @param array<string,array{liters:float,unit_price:?float,unit:string}> $pool
     */
    private function matchPoolKey(string $norm, array $pool): ?string
    {
        if ($norm === '') return null;
        if (isset($pool[$norm])) return $norm;
        foreach ($pool as $pk => $_) {
            if ($pk !== '' && (str_contains($pk, $norm) || str_contains($norm, $pk))) return $pk;
        }
        return null;
    }
}
