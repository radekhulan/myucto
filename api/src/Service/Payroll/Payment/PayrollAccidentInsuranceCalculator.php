<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Zákonné pojištění odpovědnosti zaměstnavatele za škodu při pracovním úrazu
 * a nemoci z povolání — vyhláška č. 125/1993 Sb., ve znění účinném od
 * 1. 1. 2012 (verze 6; sazby přílohy č. 2 platí ve znění vyhlášky
 * č. 487/2001 Sb. od 1. 1. 2002).
 *
 * § 12 odst. 2: „Základem pro výpočet pojistného je souhrn vyměřovacích
 * základů za uplynulé kalendářní čtvrtletí všech zaměstnanců, které v tomto
 * období zaměstnavatel zaměstnával. K výpočtu použije sazbu uvedenou v příloze
 * této vyhlášky pro příslušnou kategorii určenou podle převažující základní
 * činnosti tvořící předmět podnikání zaměstnavatele."
 *
 * Minimum 100 Kč za kalendářní čtvrtletí je poslední větou PŘÍLOHY č. 2, ne
 * paragrafu — celé pojistné řeší jediný § 12. Předchozí verze tohohle
 * docblocku citovala § 3 odst. 1 (náklady soudního projednávání) a § 5 (měna
 * pojistného plnění); obojí bylo špatně a je opravené proti úplnému znění
 * předpisu. Sazebník přílohy č. 2 drží
 * {@see \MyInvoice\Service\Payroll\AccidentInsuranceRateSchedule}.
 *
 * ── ZAOKROUHLENÍ ──────────────────────────────────────────────────────────
 *
 * Vyhláška o zaokrouhlení MLČÍ: slovo „zaokrouhl" se v celém úplném znění
 * (§ 1 až § 19 včetně přílohy č. 2 a poznámek pod čarou) nevyskytuje ani
 * jednou. Nemá ho ani metodika Kooperativy nebo Generali České pojišťovny,
 * které pojištění provozují. Pravidla o zaokrouhlení nahoru v zákonech
 * č. 589/1992 Sb. a č. 592/1992 Sb. platí pro sociální a zdravotní pojistné,
 * ne pro tuhle vyhlášku — nelze je sem přenést.
 *
 * Zaokrouhlujeme proto NAHORU na celé koruny, a to jako vědomé rozhodnutí,
 * ne jako citaci:
 *   - § 12 odst. 2 ukládá výpočet i platbu ZAMĚSTNAVATELI; pojišťovna žádný
 *     výměr ani předpis pojistného neposílá, takže musí vzniknout částka
 *     v celých korunách, kterou lze odeslat,
 *   - § 12 odst. 9 zvyšuje nedoplatek o 10 % za každý započatý měsíc.
 *     Zaokrouhlení nahoru nedoplatek vytvořit nemůže, dolů ano.
 *   - Cenou je přeplatek do 1 Kč za čtvrtletí, který se podle § 12 odst. 6
 *     („Nespotřebované pojistné se nevrací.") nevrací. Proti riziku sankce
 *     podle odstavce 9 je to zanedbatelné.
 */
final class PayrollAccidentInsuranceCalculator
{
    /**
     * Poslední věta přílohy č. 2 vyhlášky č. 125/1993 Sb. — „Minimální
     * pojistné za kalendářní čtvrtletí je 100 Kč." Hodnota je i v připnutém
     * sazebníku (`legal.minimum_quarterly_premium_czk`); že se obě strany
     * neprocházejí, hlídá AccidentInsuranceRateScheduleTest.
     */
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
        $premiumCzk = max(
            $premiumCzk,
            intdiv(self::MINIMUM_QUARTERLY_PREMIUM_MINOR, 100),
        );

        return $premiumCzk * 100;
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
