<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoReduction
{
    public static function limit(int $baseKc, string $band, array $constants): int
    {
        self::amount($baseKc);
        $c = self::configuration($constants);
        [$minimum,$maximum] = match ($band) {
            'standard' => [$c['nonprofit_reduction_min'],$c['nonprofit_reduction_max']],
            'university_research' => [$c['nonprofit_reduction_university_min'],$c['nonprofit_reduction_university_max']],
            'ineligible' => [0,0],
            default => throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Snížení vyžaduje doloženou kategorii podle § 20 odst. 7.', 422),
        };
        $rate = json_encode($c['nonprofit_reduction_rate'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (str_contains(strtolower($rate), 'e')) {
            [$mantissa,$exponent] = explode('e', strtolower($rate));
            $scale = -(int) $exponent;
            $rate = bcdiv($mantissa, bcpow('10', (string) $scale, 0), $scale + strlen(explode('.', $mantissa)[1] ?? ''));
        }
        return min($baseKc, $maximum, max($minimum, (int) bcmul((string) $baseKc, $rate, 0)));
    }

    public static function configuration(array $constants): array
    {
        $keys = ['nonprofit_reduction_rate','nonprofit_reduction_min','nonprofit_reduction_max','nonprofit_reduction_university_min','nonprofit_reduction_university_max'];
        $out = [];
        foreach ($keys as $key) {
            $value = $constants[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || ($key === 'nonprofit_reduction_rate' ? $value > 1 : ($value > 99999999999999 || floor($value) !== (float) $value))) {
                throw new TaxReturnException('nonprofit_dppo_reduction_constants_invalid', 'Roční sazba musí být číslo 0 až 1 a limity nezáporné celé částky v rozsahu daňového formuláře.', 422);
            }
            $out[$key] = $key === 'nonprofit_reduction_rate' ? $value : (int) $value;
        }
        if ($out['nonprofit_reduction_min'] > $out['nonprofit_reduction_max'] || $out['nonprofit_reduction_university_min'] > $out['nonprofit_reduction_university_max']) {
            throw new TaxReturnException('nonprofit_dppo_reduction_constants_invalid', 'Dolní mez ročního snížení nesmí převyšovat horní mez.', 422);
        }
        return $out;
    }

    public static function unusedAddback(int $reductionKc, int $savingKc, int $qualifiedUseKc): int
    {
        foreach ([$reductionKc,$savingKc,$qualifiedUseKc] as $amount) self::amount($amount);
        if ($qualifiedUseKc > $savingKc || ($reductionKc === 0 && $savingKc !== 0)) {
            throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Použití úspory musí odpovídat doložené skutečné daňové úspoře.', 422);
        }
        if ($savingKc === 0) return 0;
        $numerator = bcmul((string) $reductionKc, (string) ($savingKc - $qualifiedUseKc), 0);
        $denominator = (string) $savingKc;
        $whole = bcdiv($numerator, $denominator, 0);
        $roundUp = bccomp(bcmul(bcmod($numerator, $denominator, 0), '2', 0), $denominator, 0) >= 0;
        return (int) $whole + (int) $roundUp;
    }

    private static function amount(int $amount): void
    {
        if ($amount < 0 || $amount > 99999999999999) {
            throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Částka musí být nezáporná a odpovídat rozsahu daňového formuláře.', 422);
        }
    }
}
