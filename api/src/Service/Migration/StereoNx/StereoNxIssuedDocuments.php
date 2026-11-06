<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Převod položek vydaných faktur; zdrojová rekapitulace slouží i jako kontrolní součet. */
final class StereoNxIssuedDocuments
{
    public function __construct(private readonly StereoNxVat $vat) {}

    /** @param array<string,mixed> $header @param list<array<string,mixed>> $sourceItems
     * @return array<string,mixed> */
    public function plan(array $header, array $sourceItems, bool $accounting = false): array
    {
        $sourceType = $header['TypDokladu'] ?? null;
        if (($header['Agenda'] ?? null) !== 'VF'
            || !in_array($sourceType, $accounting ? ['F', 'D', 'P', 'Z'] : ['F'], true)
            || ($header['Stornovano'] ?? null) !== false) {
            throw new StereoNxException('issued_kind_unsupported', 'Nepodporovaný typ vydaného dokladu.');
        }
        if (!in_array($header['Mena'] ?? null, ['Kč', 'CZK'], true)
            || self::number($header, 'Kurz') !== 1.0 || self::number($header, 'KurzMn') !== 1.0
            || self::number($header, 'Zalohy') !== 0.0) {
            throw new StereoNxException('issued_currency_or_advance', 'Vydaný doklad vyžaduje samostatný převod měny nebo zálohy.');
        }
        if (!is_bool($header['CenySDPH'] ?? null)) {
            throw new StereoNxException('issued_price_mode', 'Chybí režim cen vydaného dokladu.');
        }
        $review = is_bool($header['ZpracovatDPH'] ?? null) ? [] : ['vat_participation_unassigned'];
        if (($header['ZpracovatDPH'] ?? null) === false && !in_array($sourceType, ['P', 'Z'], true)) {
            $review[] = 'vat_participation_disabled';
        }
        if ($accounting && in_array($sourceType, ['P', 'Z'], true) && ($header['ZpracovatDPH'] ?? null) === true) {
            $review[] = 'document_tax_mapping_unverified';
        }
        $items = [];
        if ($sourceItems === []) {
            $review[] = 'issued_lines_missing';
            foreach (['z', 's', 't', '0'] as $slot) {
                if (StereoNxRecapAmounts::isUnusedBucket($header, $slot)) continue;
                $base = self::money($header, $slot === '0' ? 'BezDane' : 'ZaklDPH' . $slot);
                $tax = $slot === '0' ? 0.0 : self::money($header, 'DPH' . $slot);
                if ($base === 0.0 && $tax === 0.0) continue;
                $rate = $slot === '0' ? 0.0 : self::number($header, 'SazbaDPH' . $slot);
                $items[] = $this->item($header, $slot, trim((string) ($header['Text'] ?? '')),
                    1.0, $base, $tax, $rate, 'vat_recap');
            }
        } else {
            foreach ($sourceItems as $source) {
                $advanceFlag = $source['Zaloha'] ?? false;
                $appliesAdvance = $advanceFlag === true;
                if (($source['Stornovano'] ?? null) !== false
                    || !is_bool($advanceFlag)
                    || ($appliesAdvance && !($accounting && $sourceType === 'F'))
                    || (($source['ZalohaProforma'] ?? false) !== false && !($accounting && in_array($sourceType, ['P', 'Z'], true)))
                    || ($accounting && in_array($sourceType, ['P', 'Z'], true)
                        && ($source['ZalohaProforma'] ?? null) !== true)) {
                    throw new StereoNxException('issued_line_unsupported', 'Nepodporovaný typ položky vydaného dokladu.');
                }
                if ($appliesAdvance) $review[] = 'advance_application_unlinked';
                $slot = strtolower(trim((string) ($source['TypSazby'] ?? '')));
                if (!in_array($slot, ['z', 's', 't', '0'], true)) {
                    throw new StereoNxException('issued_rate_slot', 'Neznámá sazební skupina vydané položky.');
                }
                $base = self::money($source, 'ZakladDPH');
                $tax = self::money($source, 'CelkemDPH');
                $rate = self::number($source, 'SazbaDPH');
                $quantity = self::number($source, 'Mnozstvi');
                $unit = self::number($source, 'JednCena');
                $discount = self::number($source, 'ProcSlevy');
                $pricedTotal = $header['CenySDPH'] === true ? round($base + $tax, 2) : $base;
                if ($discount < 0 || $discount > 100
                    || abs(round($quantity * $unit * (1 - $discount / 100), 2) - $pricedTotal) > 0.011) {
                    throw new StereoNxException('issued_line_amount_mismatch', 'Cena vydané položky neodpovídá základu.');
                }
                $items[] = $this->item($header, $slot, trim((string) ($source['Text'] ?? '')),
                    $quantity, $base, $tax, $rate, 'source_line', $unit, $discount);
            }
        }
        if ($items === []) throw new StereoNxException('issued_empty', 'Vydaný doklad nemá použitelné položky.');
        $base = round(array_sum(array_column($items, 'total_without_vat')), 2);
        $tax = round(array_sum(array_column($items, 'total_vat')), 2);
        $gross = round($base + $tax, 2);
        $sourceTotal = self::money($header, 'Celkem');
        $rounding = StereoNxRecapAmounts::isUnassignedZeroRounding($header, $gross, $sourceTotal)
            ? 0.0 : self::money($header, 'Zaokrouhleni');
        if ((int) round(($gross + $rounding) * 100) !== (int) round($sourceTotal * 100)) {
            throw new StereoNxException('issued_total_mismatch', 'Součet vydaných položek neodpovídá hlavičce.');
        }
        foreach (['z', 's', 't', '0'] as $slot) {
            $used = array_filter($items, static fn (array $item): bool => $item['source_slot'] === $slot);
            if ($used === [] && StereoNxRecapAmounts::isUnusedBucket($header, $slot)) continue;
            $slotBase = round(array_sum(array_map(static fn (array $item): float => $item['source_slot'] === $slot ? $item['total_without_vat'] : 0.0, $items)), 2);
            $slotTax = round(array_sum(array_map(static fn (array $item): float => $item['source_slot'] === $slot ? $item['total_vat'] : 0.0, $items)), 2);
            if (abs($slotBase - self::money($header, $slot === '0' ? 'BezDane' : 'ZaklDPH' . $slot)) > 0.011
                || ($slot !== '0' && abs($slotTax - self::money($header, 'DPH' . $slot)) > 0.011)) {
                throw new StereoNxException('issued_recap_mismatch', 'Položky vydaného dokladu nesouhlasí s rekapitulací.');
            }
        }
        return ['origin' => $sourceItems === [] ? 'vat_recap' : 'source_lines', 'items' => $items,
            'prices_include_vat' => $header['CenySDPH'], 'reverse_charge' => false,
            'source_vat_participation' => $header['ZpracovatDPH'] ?? null,
            'review_codes' => $review, 'requires_draft' => $review !== [],
            'total_without_vat' => $base, 'total_vat' => $tax,
            'total_with_vat' => $gross, 'rounding' => $rounding,
            'source_total_with_vat' => $sourceTotal];
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private function item(array $header, string $slot, string $description, float $quantity,
        float $base, float $tax, float $rate, string $origin,
        ?float $sourceUnit = null, ?float $sourceDiscount = null): array
    {
        if ($rate < 0 || $rate > 100 || ($rate === 0.0 && $tax !== 0.0)) {
            throw new StereoNxException('issued_rate_invalid', 'Neplatná sazba vydané položky.');
        }
        $class = $this->vat->sale(trim((string) ($header['TypDPH'] ?? '')), $slot);
        $unit = $quantity === 0.0 ? 0.0 : round($base / $quantity, 6);
        if ($header['CenySDPH'] === true && $quantity !== 0.0) $unit = round(($base + $tax) / $quantity, 6);
        return ['source_slot' => $slot, 'origin' => $origin, 'description' => $description,
            'quantity' => $quantity, 'unit_price' => $unit,
            'source_unit_price' => $sourceUnit, 'source_discount_percent' => $sourceDiscount,
            'vat_rate_percent' => $rate, 'vat_rate_snapshot' => $rate,
            'total_without_vat' => $base, 'total_vat' => $tax,
            'total_with_vat' => round($base + $tax, 2),
            'vat_classification_code' => $class['code'], 'vat_deduction' => 'full',
            'source_return_lines' => $class['source_lines']];
    }

    /** @param array<string,mixed> $row */
    private static function number(array $row, string $key): float
    {
        $value = $row[$key] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1.0e12) {
            throw new StereoNxException('issued_amount_invalid', 'Chybí nebo je neplatné číselné pole vydaného dokladu.');
        }
        return (float) $value;
    }

    /** @param array<string,mixed> $row */
    private static function money(array $row, string $key): float
    {
        return round(self::number($row, $key), 2);
    }
}
