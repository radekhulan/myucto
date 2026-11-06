<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

final class ChargeCalculator
{
    public const ROUNDING = 'aggregate_half_up_largest_remainder_unit_id_v1';

    public static function calculate(array $rate, array $units): array
    {
        if (!in_array($rate['method'] ?? null, ['unit', 'floor_area', 'share', 'person', 'equal'], true)
            || !in_array($rate['mode'] ?? null, ['rate_per_basis', 'total_budget'], true)
            || ($rate['rounding'] ?? null) !== self::ROUNDING) throw new ChargeException('property_charge_method_invalid', 'Zvolte doloženou metodu a pravidlo zaokrouhlení.');
        $amount = ChargeFraction::decimalCents($rate['amount'] ?? null);
        if ($rate['mode'] === 'total_budget' && $amount['den'] !== '1') throw new ChargeException('property_charge_amount_invalid', 'Rozpočet musí být vyjádřen v celých haléřích.');
        $subjects = []; $ids = [];
        foreach ($units as $unit) {
            $id = $unit['id'] ?? null;
            if (!is_int($id) || $id <= 0 || isset($ids[$id])) throw new ChargeException('property_charge_units_invalid', 'Každá jednotka musí být ve výpočtu právě jednou.');
            $attribute = $rate['method'] === 'person' ? 'persons' : $rate['method'];
            $basis = in_array($attribute, ['unit','equal'], true) ? ['num'=>'1','den'=>'1'] : ($unit['attributes'][$attribute] ?? null);
            if (!is_array($basis)) throw new ChargeException('property_charge_basis_missing', 'Jednotce chybí doložený výpočetní základ.', details: ['unit_id'=>$id, 'attribute'=>$attribute]);
            $basis = \MyInvoice\Service\Property\PropertyRational::normalize($basis);
            $ids[$id] = true;
            $subjects[] = ['id'=>$id, 'basis'=>$basis];
        }
        if ($subjects === []) throw new ChargeException('property_charge_units_invalid', 'Vyberte konkrétní jednotky rozhodnutí.');
        if ($rate['mode'] === 'total_budget' && !array_filter($subjects, static fn(array $subject): bool => $subject['basis']['num'] !== '0') && ltrim($amount['num'], '-') !== '0') throw new ChargeException('property_charge_zero_basis', 'Nenulový rozpočet nelze rozdělit podle nulového základu.');
        $result = \MyInvoice\Service\Accounting\Charges\ChargeAllocationEngine::allocate($amount, $subjects, $rate['mode']);
        $lines = array_map(static fn(array $line): array => ['unit_id'=>$line['subject_id']] + array_diff_key($line, ['subject_id'=>true]), $result['lines']);
        return ['rounding'=>self::ROUNDING, 'basis_total'=>$result['basis_total'], 'total_cents'=>$result['total_cents'], 'lines'=>$lines];
    }
}
