<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;

/**
 * Lhůty a prahy ročního zúčtování, které stojí PŘÍMO V ZÁKONĚ.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč to není v rulesetu
 * ─────────────────────────────────────────────────────────────────────────────
 * Ruleset drží hodnoty, které se ročníkově mění (sazby, částky slev, hranice
 * pásem) a které smí přepsat admin. Tady jsou naopak dvě věci, které jsou v ZDP
 * napsané jako den v roce a jedna koruna:
 *
 *   - 15. února a 31. března (§ 38ch odst. 1, 3, 4),
 *   - „více než 50 Kč" (§ 38ch odst. 5, § 35d odst. 8).
 *
 * Doména `Deadlines` je v rulesetu záměrně `ManualReview` — modul lhůty
 * neodvozuje, protože u podání závisí na agendě, kanálu a přechodných
 * ustanoveních. Roční zúčtování je proti tomu jednoznačné: den je v zákoně
 * a nezávisí na ničem dalším.
 *
 * Každá hodnota proto nese `source = 'statute'` do uloženého snapshotu — stejný
 * vzor jako `fiction_days_source` v migraci 1394.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Peněžní prahy jsou od 8/2026 i v rulesetu — a `isPayable()` i
 * `isAnnualBonusAmountEligible()` je ČTOU odtud
 * ─────────────────────────────────────────────────────────────────────────────
 * `settlement.payout_threshold` a `bonus.minimum_amount.yearly` v doméně daně
 * z příjmů nesou tatáž dvě čísla jako {@see PAYOUT_THRESHOLD_MINOR_UNITS}
 * a {@see ANNUAL_BONUS_MINIMUM_MINOR_UNITS}, ale výpočet čte prahy z
 * {@see \MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates}, ne
 * z téhle konstanty přímo — konstanta zůstává jen jako dokumentace zákonného
 * čísla a jako výchozí hodnota pro volání bez rulesetu (např. přímou stavbu
 * výsledku v testech). Dvě čísla pro jednu věc jsou riziko, takže se jejich
 * shoda ověřuje při každém sestavení sazeb — {@see AnnualTaxRates::forRuleset()}
 * zúčtování zastaví, jakmile se rozejdou. Lhůty (15. února, 31. března) v
 * rulesetu NEJSOU: doména `Deadlines` je záměrně `ManualReview`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Co tu ZÁMĚRNĚ není
 * ─────────────────────────────────────────────────────────────────────────────
 * Roční částky slev a daňového zvýhodnění. Ty se NEODVOZUJÍ ze zákona v kódu,
 * ale z rulesetu — viz AnnualTaxRates a jeho vysvětlení, proč je roční částka
 * slevy dvanáctinásobkem měsíční a proč u prahů výplaty totéž neplatí.
 */
final class AnnualSettlementStatute
{
    /** Zdroj hodnoty. Ukládá se, aby šlo starý výpočet přečíst i po změně pravidla. */
    public const SOURCE = 'statute';

    /**
     * § 38ch odst. 1: „…a to nejpozději do 15. února po uplynutí zdaňovacího
     * období." Týž den je v odst. 3 pro doklady od předchozích plátců a v
     * § 38k odst. 7 pro dodatečné prokázání rozhodných skutečností.
     */
    public const REQUEST_DEADLINE_MONTH = 2;
    public const REQUEST_DEADLINE_DAY = 15;

    /**
     * § 38ch odst. 4: „Výpočet daně a roční zúčtování … provede plátce daně
     * nejpozději do 31. března po uplynutí zdaňovacího období."
     */
    public const SETTLEMENT_DEADLINE_MONTH = 3;
    public const SETTLEMENT_DEADLINE_DAY = 31;

    /**
     * § 38ch odst. 5 a § 35d odst. 8: vyplatí se, „činí-li úhrnná výše … více
     * než 50 Kč". Pozor na ostrou nerovnost — přesně 50 Kč se nevyplácí.
     *
     * Není to totéž jako `bonus.minimum_amount.monthly` z rulesetu, i když je
     * tam dnes stejné číslo: tamto je práh MĚSÍČNÍHO daňového bonusu podle
     * § 35d odst. 4 a jeho nerovnost je NEOSTRÁ („alespoň 50 Kč"), kdežto tahle
     * je ostrá. Sloučit je by znamenalo, že novela jednoho tiše změní druhé —
     * a navíc obrátí operátor. Administrovatelný protějšek téhle hodnoty je
     * `settlement.payout_threshold`, ne měsíční bonus.
     */
    public const PAYOUT_THRESHOLD_MINOR_UNITS = 5_000;

    /**
     * § 35c odst. 3: „Poplatník může daňový bonus uplatnit, pokud jeho výše činí
     * alespoň 100 Kč." Na rozdíl od prahu výplaty je tahle nerovnost neostrá.
     */
    public const ANNUAL_BONUS_MINIMUM_MINOR_UNITS = 10_000;

    /** § 38ch odst. 1: poslední den lhůty pro žádost o zúčtování za `$taxYear`. */
    public static function requestDeadline(int $taxYear): DateTimeImmutable
    {
        return self::date(
            $taxYear + 1,
            self::REQUEST_DEADLINE_MONTH,
            self::REQUEST_DEADLINE_DAY,
        );
    }

    /** § 38ch odst. 3: poslední den, kdy smějí dojít doklady předchozích plátců. */
    public static function priorDocumentsDeadline(int $taxYear): DateTimeImmutable
    {
        return self::requestDeadline($taxYear);
    }

    /**
     * První den, kdy smí plátce zúčtování provést — 1. ledna po uplynutí
     * zdaňovacího období.
     *
     * § 38ch odst. 1 mluví o žádosti „po uplynutí zdaňovacího období" a odst. 4
     * o úhrnu mezd „za uplynulé zdaňovací období". Dokud rok běží, žádný roční
     * úhrn neexistuje; spodní konec lhůty je proto stejně tvrdý jako horní.
     */
    public static function settlementEarliest(int $taxYear): DateTimeImmutable
    {
        return self::date($taxYear + 1, 1, 1);
    }

    /** § 38ch odst. 4: poslední den, kdy smí plátce zúčtování provést. */
    public static function settlementDeadline(int $taxYear): DateTimeImmutable
    {
        return self::date(
            $taxYear + 1,
            self::SETTLEMENT_DEADLINE_MONTH,
            self::SETTLEMENT_DEADLINE_DAY,
        );
    }

    /**
     * § 38ch odst. 5 / § 35d odst. 8: „nejpozději při zúčtování mzdy za březen
     * po uplynutí zdaňovacího období". Vracíme první den toho měsíce, protože
     * mzdové období je v modulu vždycky prvním dnem měsíce.
     */
    public static function payoutPeriodStart(int $taxYear): DateTimeImmutable
    {
        return self::date($taxYear + 1, self::SETTLEMENT_DEADLINE_MONTH, 1);
    }

    /**
     * Přeplatek se vyplácí, jen když je VÍCE než práh (§ 38ch odst. 5:
     * „činí-li úhrnná výše tohoto přeplatku více než 50 Kč"). Nerovnost je
     * OSTRÁ — to je zákonná vlastnost pravidla a v kódu zůstává; SAMOTNÝ práh
     * se čte z rulesetu přes `$rates`, je-li předaný. Bez něj (přímá stavba
     * výsledku, např. v testech) platí zákonná konstanta jako dřív.
     */
    public static function isPayable(int $amountMinorUnits, ?AnnualTaxRates $rates = null): bool
    {
        $threshold = $rates?->payoutThresholdMinorUnits ?? self::PAYOUT_THRESHOLD_MINOR_UNITS;

        return $amountMinorUnits > $threshold;
    }

    /**
     * § 35c odst. 3: nerovnost je NEOSTRÁ. Práh se čte z rulesetu přes
     * `$rates`, je-li předaný — viz {@see isPayable()}.
     */
    public static function isAnnualBonusAmountEligible(int $amountMinorUnits, ?AnnualTaxRates $rates = null): bool
    {
        $threshold = $rates?->bonusMinimumAmountMinorUnits ?? self::ANNUAL_BONUS_MINIMUM_MINOR_UNITS;

        return $amountMinorUnits >= $threshold;
    }

    private static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-%02d', $year, $month, $day),
        );
        if ($date === false) {
            throw new \InvalidArgumentException(
                'Lhůtu ročního zúčtování nelze sestavit.',
            );
        }

        return $date;
    }
}
