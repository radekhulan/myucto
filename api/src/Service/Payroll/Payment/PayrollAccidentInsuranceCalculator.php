<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Zákonné pojištění odpovědnosti zaměstnavatele za škodu při pracovním úrazu
 * a nemoci z povolání — vyhláška č. 125/1993 Sb.
 *
 * § 3 odst. 1: vyměřovacím základem je souhrn vyměřovacích základů pro
 * pojistné na sociální zabezpečení zaměstnanců za uplynulé kalendářní
 * čtvrtletí. § 5: pojistné za kalendářní čtvrtletí činí nejméně 100 Kč, i
 * kdyby výpočet ze sazby vyšel nižší.
 *
 * Vyhláška výslovně neuvádí zaokrouhlení pojistného na celé koruny — na
 * rozdíl od měsíčního sociálního a zdravotního pojištění, kde je zaokrouhlení
 * nahoru dané zákonem. PŘEDPOKLAD K OVĚŘENÍ: pojistné se tu zaokrouhluje na
 * celé koruny nahoru analogicky k ostatním odvodům, aby platba i předpis vyšly
 * na celé koruny. Než se to ověří proti výměru konkrétní pojišťovny, nechte
 * tenhle docblock jako varování.
 */
final class PayrollAccidentInsuranceCalculator
{
    /** § 5 vyhlášky č. 125/1993 Sb. — 100 Kč za čtvrtletí. */
    public const MINIMUM_QUARTERLY_PREMIUM_MINOR = 10_000;

    /**
     * @param int $assessmentBaseMinor souhrn vyměřovacích základů sociálního
     *   pojištění za čtvrtletí, v haléřích (minor units)
     * @param string $ratePerMille sazba v promile, např. "4.20"
     */
    public function premiumMinor(int $assessmentBaseMinor, string $ratePerMille): int
    {
        if ($assessmentBaseMinor < 0) {
            throw new \InvalidArgumentException(
                'Vyměřovací základ zákonného pojištění nesmí být záporný.',
            );
        }
        $rateHundredths = self::rateHundredths($ratePerMille);
        // assessmentBaseMinor je v haléřích, rateHundredths je promile×100 —
        // dělitel tedy normalizuje na celé koruny: /100 (haléře → Kč) ×
        // /100 (setiny promile) × /1000 (promile → podíl) = /10 000 000.
        $numerator = $assessmentBaseMinor * $rateHundredths;
        $premiumCzk = $numerator === 0
            ? 0
            : intdiv($numerator + 9_999_999, 10_000_000);
        $premiumCzk = max($premiumCzk, self::MINIMUM_QUARTERLY_PREMIUM_MINOR / 100);

        return (int) $premiumCzk * 100;
    }

    private static function rateHundredths(string $ratePerMille): int
    {
        if (preg_match('/^([0-9]{1,4})(?:\.([0-9]{1,2}))?$/D', $ratePerMille, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Sazba zákonného pojištění musí být kladné číslo v promile.',
            );
        }
        $whole = (int) $match[1];
        $fraction = (int) str_pad($match[2] ?? '', 2, '0', STR_PAD_RIGHT);
        $hundredths = $whole * 100 + $fraction;
        if ($hundredths <= 0) {
            throw new \InvalidArgumentException(
                'Sazba zákonného pojištění musí být kladné číslo v promile.',
            );
        }

        return $hundredths;
    }
}
