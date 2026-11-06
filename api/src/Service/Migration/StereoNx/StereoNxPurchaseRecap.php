<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;

/**
 * Náhradní položky přijatého dokladu z rekapitulace SPFH (jen CZK).
 * Hodnoty řádků jsou zdrojové: samovyměřená daň není daní placenou dodavateli.
 * Výsledkem je plán, nikoli zaúčtovaný doklad. Neznámé příznaky zůstávají k posouzení.
 */
final class StereoNxPurchaseRecap
{
    public function __construct(private readonly StereoNxVat $vat) {}

    /** @param array<string,mixed> $header
     * @return array<string,mixed> */
    public function plan(array $header, bool $accounting = false): array
    {
        $sourceType = $header['TypDokladu'] ?? null;
        if (($header['Agenda'] ?? null) !== 'PF'
            || !in_array($sourceType, $accounting ? ['F', 'Z'] : ['F'], true)) {
            throw new StereoNxException('purchase_kind_unsupported', 'Rekapitulaci lze převést jen pro přijatou fakturu.');
        }
        if (($header['Stornovano'] ?? null) !== false) {
            throw new StereoNxException('purchase_cancelled', 'Stornovaný nebo neověřený doklad nelze převést rekapitulací.');
        }
        if (!in_array($header['Mena'] ?? null, ['Kč', 'CZK'], true)
            || self::number($header, 'Kurz') !== 1.0 || self::number($header, 'KurzMn') !== 1.0) {
            throw new StereoNxException('purchase_currency_unsupported', 'Cizoměnová rekapitulace vyžaduje samostatné mapování.');
        }
        if (self::number($header, 'Zalohy') !== 0.0) {
            throw new StereoNxException('purchase_advance_unsupported', 'Odpočet zálohy nelze nahradit běžnou položkou.');
        }
        if (!array_key_exists('CenySDPH', $header) || (!is_bool($header['CenySDPH']) && $header['CenySDPH'] !== null)) {
            throw new StereoNxException('price_mode_invalid', 'Neplatný příznak cen s DPH.');
        }
        $pricesIncludeVat = $header['CenySDPH'];
        $review = $pricesIncludeVat === null ? ['price_mode_unassigned'] : [];
        if (!is_bool($header['ZpracovatDPH'] ?? null)) $review[] = 'vat_participation_unassigned';
        elseif ($header['ZpracovatDPH'] === false && $sourceType !== 'Z') $review[] = 'vat_participation_disabled';
        if ($accounting && $sourceType === 'Z' && ($header['ZpracovatDPH'] ?? null) === true) {
            $review[] = 'document_tax_mapping_unverified';
        }
        $items = [];
        $reverse = null;
        $code = trim((string) ($header['TypDPH'] ?? ''));
        $description = trim((string) ($header['Text'] ?? ''));
        foreach (['z', 's', 't', '0'] as $slot) {
            if (StereoNxRecapAmounts::isUnusedBucket($header, $slot)) continue;
            $base = self::money($header, $slot === '0' ? 'BezDane' : 'ZaklDPH' . $slot);
            $sourceVat = $slot === '0' ? 0.0 : self::money($header, 'DPH' . $slot);
            if ($base === 0.0 && $sourceVat === 0.0) continue;
            $rate = $slot === '0' ? 0.0 : self::number($header, 'SazbaDPH' . $slot);
            if ($rate < 0.0 || $rate > 100.0 || ($rate === 0.0 && $sourceVat !== 0.0)) {
                throw new StereoNxException('purchase_rate_invalid', 'Neplatná nebo chybějící sazba rekapitulace.');
            }
            $class = $this->vat->purchase($code, $slot);
            if ($class['in_return']) {
                if ($reverse !== null && $reverse !== $class['reverse']) {
                    throw new StereoNxException('purchase_mixed_vat', 'Smíšené samovyměření a tuzemská daň vyžadují samostatné mapování.');
                }
                $reverse = $class['reverse'];
            }
            if ($class['reverse'] && abs(round($base * $rate / 100, 2) - $sourceVat) >= 0.005) {
                $review[] = 'self_assessment_amount_mismatch';
            }
            $chargedVat = $class['reverse'] ? 0.0 : $sourceVat;
            $gross = round($base + $chargedVat, 2);
            if ($pricesIncludeVat === null && $chargedVat !== 0.0) {
                throw new StereoNxException('price_mode_required', 'Doklad s dodavatelskou DPH nemá určený režim cen.');
            }
            $items[] = [
                'source_slot' => $slot,
                'description' => $description,
                'quantity' => 1.0,
                'unit_price' => $pricesIncludeVat === true ? $gross : $base,
                'unit_price_without_vat' => $pricesIncludeVat === true ? $gross : $base,
                'vat_rate_percent' => $rate,
                'vat_rate_snapshot' => $rate,
                'total_without_vat' => $base,
                'total_vat' => $chargedVat,
                'total_with_vat' => $gross,
                'vat_classification_code' => $class['code'],
                'vat_deduction' => $class['deduction'],
                'source_return_lines' => $class['source_lines'],
                'source_self_assessed_vat' => $class['reverse'] ? $sourceVat : 0.0,
            ];
        }
        if ($items === []) {
            throw new StereoNxException('purchase_empty_recap', 'Rekapitulace neobsahuje žádnou nenulovou položku.');
        }
        if ($reverse === true) {
            // Přihrádka mimo přiznání (bez daně) na dokladu se samovyměřením: bez kódu by ji
            // evidence DPH podle příznaku `reverse_charge` zdanila jako samovyměření.
            foreach ($items as $i => $item) {
                if ($item['vat_classification_code'] === null && $item['total_vat'] === 0.0 && $item['source_self_assessed_vat'] === 0.0) {
                    $items[$i]['vat_classification_code'] = VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE;
                }
            }
        }
        $base = round(array_sum(array_column($items, 'total_without_vat')), 2);
        $vat = round(array_sum(array_column($items, 'total_vat')), 2);
        $total = round($base + $vat, 2);
        $sourceTotal = self::money($header, 'Celkem');
        $rounding = StereoNxRecapAmounts::isUnassignedZeroRounding($header, $total, $sourceTotal)
            ? 0.0 : self::money($header, 'Zaokrouhleni');
        if ((int) round(($total + $rounding) * 100) !== (int) round($sourceTotal * 100)) {
            throw new StereoNxException('purchase_recap_mismatch', 'Součet rekapitulace a uloženého zaokrouhlení nesouhlasí s celkem dokladu.');
        }
        return ['origin' => 'vat_recap', 'items' => $items,
            'prices_include_vat' => $pricesIncludeVat, 'reverse_charge' => $reverse ?? false,
            'source_vat_participation' => $header['ZpracovatDPH'] ?? null,
            'review_codes' => $review, 'requires_draft' => $review !== [],
            'total_without_vat' => $base, 'total_vat' => $vat,
            'total_with_vat' => $total, 'rounding' => $rounding, 'source_total_with_vat' => $sourceTotal];
    }

    /**
     * Účetní převod zachová položky jen při úplné shodě s rekapitulací hlavičky.
     * Daňové zařazení přebírá z téhož ověřeného mapování jako náhradní řádky.
     * @param array<string,mixed> $header @param list<array<string,mixed>> $sourceItems
     * @return array<string,mixed>
     */
    public function planWithLines(array $header, array $sourceItems): array
    {
        $recap = $this->plan($header, true);
        if ($sourceItems === []) return $recap;
        $bySlot = [];
        foreach ($recap['items'] as $item) $bySlot[$item['source_slot']] = $item;
        $items = [];
        $sourceSlots = [];
        $advanceApplied = false;
        foreach ($sourceItems as $source) {
            $advanceFlag = $source['Zaloha'] ?? false;
            if (($source['Stornovano'] ?? null) !== false || !is_bool($advanceFlag)
                || ($source['ZalohaProforma'] ?? false) !== false) {
                throw new StereoNxException('purchase_line_unsupported', 'Přijatá položka má neověřený příznak nebo je stornovaná.');
            }
            if ($advanceFlag) $advanceApplied = true;
            $slot = strtolower(trim((string) ($source['TypSazby'] ?? '')));
            if (!isset($bySlot[$slot])) {
                throw new StereoNxException('purchase_line_unsupported', 'Přijatá položka nemá odpovídající sazbu v rekapitulaci.');
            }
            $base = self::money($source, 'ZakladDPH');
            $sourceVat = self::money($source, 'CelkemDPH');
            $rate = self::number($source, 'SazbaDPH');
            $quantity = self::number($source, 'Mnozstvi');
            $unit = self::number($source, 'JednCena');
            $discount = $source['ProcSlevy'] ?? 0;
            if ((!is_int($discount) && !is_float($discount)) || !is_finite((float) $discount)
                || $discount < 0 || $discount > 100 || $quantity === 0.0
                || abs($rate - (float) $bySlot[$slot]['vat_rate_snapshot']) > 0.001) {
                throw new StereoNxException('purchase_line_unsupported', 'Neověřená sazba, množství nebo sleva přijaté položky.');
            }
            $chargedVat = $recap['reverse_charge'] ? 0.0 : $sourceVat;
            $gross = round($base + $chargedVat, 2);
            $priced = $recap['prices_include_vat'] === true ? $gross : $base;
            if (abs(round($quantity * $unit * (1 - $discount / 100), 2) - $priced) > 0.011) {
                throw new StereoNxException('purchase_line_unsupported', 'Cena přijaté položky nesouhlasí se zdrojovým základem.');
            }
            $items[] = $bySlot[$slot];
            $last = count($items) - 1;
            $items[$last]['description'] = trim((string) ($source['Text'] ?? ''));
            $items[$last]['quantity'] = $quantity;
            $items[$last]['unit_price'] = round($priced / $quantity, 6);
            $items[$last]['unit_price_without_vat'] = $items[$last]['unit_price'];
            $items[$last]['source_unit_price'] = $unit;
            $items[$last]['source_discount_percent'] = (float) $discount;
            $items[$last]['total_without_vat'] = $base;
            $items[$last]['total_vat'] = $chargedVat;
            $items[$last]['total_with_vat'] = $gross;
            $items[$last]['source_self_assessed_vat'] = $recap['reverse_charge'] ? $sourceVat : 0.0;
            $sourceSlots[$slot]['base'] = round(($sourceSlots[$slot]['base'] ?? 0) + $base, 2);
            $sourceSlots[$slot]['vat'] = round(($sourceSlots[$slot]['vat'] ?? 0) + $sourceVat, 2);
        }
        foreach ($bySlot as $slot => $recapItem) {
            $expectedVat = $recap['reverse_charge']
                ? $recapItem['source_self_assessed_vat'] : $recapItem['total_vat'];
            if (abs(($sourceSlots[$slot]['base'] ?? 0) - $recapItem['total_without_vat']) > 0.011
                || abs(($sourceSlots[$slot]['vat'] ?? 0) - $expectedVat) > 0.011) {
                throw new StereoNxException('purchase_line_unsupported', 'Přijaté položky nesouhlasí s rekapitulací DPH.');
            }
        }
        $recap['origin'] = 'source_lines';
        $recap['items'] = $items;
        if ($advanceApplied) {
            $recap['review_codes'][] = 'advance_application_unlinked';
            $recap['requires_draft'] = true;
        }
        return $recap;
    }

    /** @param array<string,mixed> $row */
    private static function number(array $row, string $key): float
    {
        $value = $row[$key] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1.0e12) {
            throw new StereoNxException('purchase_amount_invalid', 'Chybějící nebo neplatné číselné pole rekapitulace: ' . $key);
        }
        return (float) $value;
    }

    /** @param array<string,mixed> $row */
    private static function money(array $row, string $key): float
    {
        return round(self::number($row, $key), 2);
    }
}
