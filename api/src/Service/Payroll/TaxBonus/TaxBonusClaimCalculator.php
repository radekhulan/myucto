<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxBonus;

/**
 * Rozdělí měsíční úhrny na dvě žádosti — § 35d odst. 5 (DPZMB1) a odst. 9 (DPZDB1).
 *
 * ## Proč je tu vůbec nějaké rozhodování
 *
 * Odvod záloh se snižuje o OBOJÍ: o vyplacené měsíční bonusy i o doplatky
 * z ročního zúčtování. Modul je proto všude sčítá do jednoho offsetu — pro odvod
 * na tom nezáleží. Žádosti ale mají každá vlastní tiskopis, takže se ten společný
 * offset musí rozdělit, a s ním i sražené zálohy, kterými se pokryl.
 *
 * Zákon pořadí nestanoví. Volíme pořadí ustanovení: nejdřív se zálohami pokryjí
 * měsíční bonusy (odst. 5), zbytkem záloh doplatky ze zúčtování (odst. 9). To má
 * dvě vlastnosti, na kterých záleží:
 *
 *  1. **Úhrn sedí.** `vlastní(odst. 5) + vlastní(odst. 9)` je algebraicky totéž
 *     jako `max(0, bonusy + doplatky − zálohy)`, tedy přesně
 *     `advance_tax_offset_unapplied_minor`, které si materializer ukládá do
 *     `payroll_payment_liabilities`. Obě žádosti dohromady nikdy nežádají
 *     o víc (ani o míň), než kolik systém vykázal jako pohledávku za správcem daně.
 *  2. **Běžný měsíc je jednoznačný.** V měsíci bez ročního zúčtování — což je
 *     jedenáct měsíců z dvanácti — je celý offset měsíční bonus a pořadí se
 *     nemá na čem projevit.
 *
 * Alternativa (nejdřív doplatky) by dala jiný rozpad mezi tiskopisy při stejném
 * součtu. Kdyby se ukázalo, že správce daně čeká jiné pořadí, mění se jen tahle
 * třída — nikoli data, ze kterých čerpá.
 */
final class TaxBonusClaimCalculator
{
    /**
     * @return array{
     *   monthly: ?TaxBonusClaim,
     *   annual: ?TaxBonusClaim,
     *   warnings: list<string>
     * } `null` = za období není o co žádat (bonusy se vešly do záloh).
     */
    public function calculate(TaxBonusClaimBasis $basis): array
    {
        $warnings = [];

        // Zálohy kryjí nejdřív měsíční bonusy (odst. 5), zbytkem doplatky (odst. 9).
        $advancesForMonthly = min($basis->advanceTaxMinor, $basis->monthlyBonusMinor);
        $advancesLeft = $basis->advanceTaxMinor - $advancesForMonthly;
        $advancesForAnnual = min($advancesLeft, $basis->annualSettlementMinor);

        $monthlyOwn = $basis->monthlyBonusMinor - $advancesForMonthly;
        $annualOwn = $basis->annualSettlementMinor - $advancesForAnnual;

        // Pojistka proti rozejití s tím, co je zaúčtované jako pohledávka.
        // Není to obrana proti překlepu ve vzorci výš, ale proti budoucí změně
        // pořadí nebo clampu, která by tuhle rovnost tiše porušila.
        if ($monthlyOwn + $annualOwn !== $basis->unappliedOffsetMinor()) {
            throw new \LogicException(
                'Rozpad žádostí neodpovídá nevyužitému převisu bonusů nad zálohami.',
            );
        }

        [$period] = self::periodParts($basis->periodStart);
        $bonusDate = $basis->paymentDate;
        if ($bonusDate === null) {
            // `d_bonus` je v obou schématech povinné a je to datum SKUTEČNÉ
            // výplaty, ne konec období. Bez něj se žádost nesestaví — dosadit
            // poslední den měsíce by znamenalo tvrdit správci daně datum,
            // které se nestalo.
            if ($monthlyOwn > 0 || $annualOwn > 0) {
                $warnings[] = 'Mzdový běh období nemá datum výplaty, '
                    . 'bez kterého nelze uvést datum výplaty bonusu (ř. d_bonus).';
            }

            return ['monthly' => null, 'annual' => null, 'warnings' => $warnings];
        }

        return [
            'monthly' => $monthlyOwn > 0
                ? $this->claim(
                    TaxBonusClaim::FORM_MONTHLY,
                    $period['year'],
                    $period['month'],
                    $bonusDate,
                    $basis->monthlyBonusMinor,
                    $advancesForMonthly,
                    $warnings,
                )
                : null,
            'annual' => $annualOwn > 0
                ? $this->claim(
                    TaxBonusClaim::FORM_ANNUAL,
                    // Zdaňovací období, za které se roční zúčtování provádělo,
                    // je rok PŘEDCHÁZEJÍCÍ měsíci výplaty doplatku (§ 38ch odst. 4
                    // a 5 — zúčtování se provádí po skončení roku, doplatek se
                    // vyplácí nejpozději při březnové mzdě).
                    $period['year'] - 1,
                    null,
                    $bonusDate,
                    $basis->annualSettlementMinor,
                    $advancesForAnnual,
                    $warnings,
                )
                : null,
            'warnings' => $warnings,
        ];
    }

    /** @param list<string> $warnings */
    private function claim(
        string $formCode,
        int $year,
        ?int $month,
        string $bonusDate,
        int $totalMinor,
        int $advancesMinor,
        array &$warnings,
    ): TaxBonusClaim {
        $total = $this->toCzk($totalMinor, 'úhrn vyplacených bonusů', $warnings);
        $advances = $this->toCzk($advancesMinor, 'úhrn sražených záloh', $warnings);
        // Zaokrouhlení obou řádků nezávisle by mohlo porušit `ř. 2 ≤ ř. 1`.
        $advances = min($advances, $total);

        return new TaxBonusClaim(
            $formCode,
            $year,
            $month,
            $bonusDate,
            $total,
            $advances,
            $total - $advances,
            $warnings,
        );
    }

    /**
     * Haléře → celé koruny. Schémata mají u `kc_*` `fractionDigits="0"`, takže
     * jiná možnost není. Mzdový bonus i záloha se v české mzdě počítají na celé
     * koruny, takže zbytek by znamenal, že se někde ztratila zaokrouhlovací
     * pravidlo — proto se na něj upozorní, místo aby se tiše zahodil.
     *
     * @param list<string> $warnings
     */
    private function toCzk(int $minor, string $label, array &$warnings): int
    {
        if ($minor % 100 !== 0) {
            $warnings[] = sprintf(
                '%s není v celých korunách (%d h) — do žádosti se zaokrouhlil.',
                ucfirst($label),
                $minor,
            );
        }

        return intdiv($minor + 50, 100);
    }

    /** @return array{0:array{year:int,month:int}} */
    private static function periodParts(string $periodStart): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($date === false) {
            throw new \InvalidArgumentException(
                'Období mzdového běhu není platné datum: ' . $periodStart,
            );
        }

        return [['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n')]];
    }
}
