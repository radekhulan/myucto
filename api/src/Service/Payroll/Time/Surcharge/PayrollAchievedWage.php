<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Calculation\RoundingMode;

/**
 * Dosažená mzda za hodiny práce přesčas podle § 114 odst. 1 ZP.
 *
 * ── Proč to není průměrný výdělek ───────────────────────────────────────────
 *
 * § 114 odst. 1 přiznává za práci přesčas DVĚ různé věci vedle sebe: „dosaženou
 * mzdu" a k ní „příplatek nejméně ve výši 25 % průměrného výdělku". Dosažená
 * mzda je mzda za skutečně odvedenou práci v tom měsíci, průměrný výdělek se
 * podle § 353 zjišťuje z PŘEDCHOZÍHO rozhodného období. Jsou to dvě různá čísla
 * a záměna je systematická vada:
 *
 *  - u rostoucí mzdy je průměrný výdělek NIŽŠÍ než dosažená mzda a zaměstnanec
 *    dostane za přesčasové hodiny míň, než mu náleží;
 *  - u klesající naopak.
 *
 * Ani jeden směr se na výplatní pásce nepozná, protože obě čísla vypadají
 * věrohodně. Proto se počítají odděleně.
 *
 * ── Jak se dosažená mzda určí u měsíční mzdy ────────────────────────────────
 *
 * Měsíční mzda pokrývá FOND pracovní doby období. Přesčas je z definice práce
 * NAD fond (§ 78 odst. 1 písm. i), takže za přesčasovou hodinu měsíční mzda
 * zaplacená není a dosažená mzda za ni je `měsíční mzda / fond hodin období`.
 * Fond se bere z pracovního kalendáře vztahu, ne z paušálních 160 nebo 174
 * hodin: v měsíci s 20 pracovními dny a v měsíci s 23 je to rozdíl přes 10 %
 * a paušál by ho tiše rozpustil.
 *
 * Bez kalendáře se dosažená mzda určit NEDÁ a odhad se nedělá — viz
 * {@see PayrollSurchargeException} a zásada fail-closed celé této skupiny tříd.
 *
 * ── Zaokrouhlení ────────────────────────────────────────────────────────────
 *
 * Jediným zlomkem, half-up na haléř, stejně jako {@see PayrollSurchargeLine}.
 * Mezikrok „hodinová sazba na haléře" se do výpočtu částky nepoužívá; slouží
 * jen ke čtení na mzdovém listu, kde se hodinová sazba uvádět musí.
 */
final class PayrollAchievedWage
{
    /**
     * Dosažená mzda za `$milliHours` tisícin hodiny.
     *
     * @param int $monthlyMinor měsíční mzda v haléřích
     * @param int $fundMinutes  fond pracovní doby období v minutách
     * @param int $milliHours   odpracované přesčasové hodiny v tisícinách hodiny
     */
    public static function forMilliHours(
        int $monthlyMinor,
        int $fundMinutes,
        int $milliHours,
    ): int {
        self::assertBasis($monthlyMinor, $fundMinutes);
        if ($milliHours < 0) {
            throw PayrollSurchargeException::of(
                'negative_hours',
                'Dosažená mzda se nepočítá ze záporného počtu hodin.',
            );
        }
        if ($milliHours === 0) {
            return 0;
        }

        // `monthlyMinor × milliHours × 60 / (fundMinutes × 1000)`.
        // Jmenovatel drží obě převodní konstanty najednou, aby se
        // nezaokrouhlovalo dvakrát: milihodiny → minuty (× 60 / 1000) a minuty
        // fondu → podíl měsíční mzdy.
        $numerator = self::multiplyExactly(
            self::multiplyExactly($monthlyMinor, $milliHours),
            60,
        );
        $denominator = self::multiplyExactly($fundMinutes, 1_000);

        return RoundingMode::HalfUp->roundFraction($numerator, $denominator);
    }

    /**
     * Hodinová sazba dosažené mzdy — POUZE pro zobrazení na mzdovém listu
     * (§ 142 odst. 5 ZP). Částka se z ní nepočítá, viz komentář třídy.
     */
    public static function hourlyMinor(int $monthlyMinor, int $fundMinutes): int
    {
        self::assertBasis($monthlyMinor, $fundMinutes);

        return RoundingMode::HalfUp->roundFraction(
            self::multiplyExactly($monthlyMinor, 60),
            $fundMinutes,
        );
    }

    private static function assertBasis(int $monthlyMinor, int $fundMinutes): void
    {
        if ($monthlyMinor <= 0) {
            throw PayrollSurchargeException::of(
                'achieved_wage_basis_missing',
                'Dosaženou mzdu za práci přesčas nelze určit: pro období není '
                . 'evidována kladná základní mzda. Doplňte ji a výpočet zopakujte.',
            );
        }
        if ($fundMinutes <= 0) {
            throw PayrollSurchargeException::of(
                'work_calendar_missing',
                'Dosaženou mzdu za práci přesčas nelze určit: pracovní vztah nemá '
                . 'pro toto období pracovní kalendář, ze kterého plyne fond pracovní '
                . 'doby. Přiřaďte kalendář a výpočet zopakujte.',
            );
        }
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw PayrollSurchargeException::of(
                'negative_factor',
                'Výpočet dosažené mzdy nepracuje se zápornými činiteli.',
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw PayrollSurchargeException::of(
                'overflow',
                'Výpočet dosažené mzdy překročil celočíselný rozsah.',
            );
        }

        return $left * $right;
    }
}
