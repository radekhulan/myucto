<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

/**
 * Poměrná část měsíční mzdy, když zaměstnanec neodpracoval celý měsíc.
 *
 * ── Proč podle HODIN, a ne podle pracovních dnů ─────────────────────────────
 *
 * Základním pojmovým znakem mzdy je, že přísluší ZA VYKONANOU PRÁCI (§ 109
 * odst. 1 ZP). Odpracuje-li zaměstnanec jen část měsíce, určí se poměrná část
 * sjednané měsíční mzdy podle pracovní doby — tak to vykládá i Nejvyšší soud
 * v rozsudku 21 Cdo 2801/2021, kde s poměrem odpracovaných hodin pracuje
 * výslovně (8 hodin z měsíčního fondu 128).
 *
 * Poměr pracovních DNŮ dá stejný výsledek jen tehdy, jsou-li všechny směny
 * stejně dlouhé. Při nerovnoměrném rozvržení (§ 78 odst. 1 písm. m) ZP) může
 * deset zameškaných dnů znamenat výrazně jiný počet hodin než deset jiných dnů
 * téhož měsíce, takže by za stejný počet zameškaných hodin vycházela různá
 * mzda. Kratší úvazek na tom nic nemění: sjednaná měsíční částka už kratší
 * pracovní době odpovídá a poměr se počítá z JEHO fondu, ne z obecného
 * čtyřicetihodinového týdne.
 *
 * ── Každá naplánovaná minuta právě jednou ───────────────────────────────────
 *
 * Fond měsíce se rozpadá na dobu krytou základní mzdou a na dobu nahrazenou
 * jiným titulem (dovolená § 222, náhrada při DPN § 192, jiná placená překážka,
 * dávka nemocenského pojištění, neplacené volno). Základní mzda se počítá
 * VÝHRADNĚ z první skupiny — jinak by se tatáž doba vyplatila dvakrát.
 *
 * ── Svátek ──────────────────────────────────────────────────────────────────
 *
 * Svátek, který připadne na obvyklý pracovní den, měsíční mzdu nekrátí
 * (§ 115 odst. 3 ZP — žádná mzda „neušla"). DO FONDU PROTO NEVSTUPUJE: fond je
 * skutečná odpracovávaná povinnost měsíce a jen z ní smí plynout hodinová
 * hodnota sjednané měsíční částky. Přičíst svátek do jmenovatele by tuhle
 * hodnotu naředilo a zaměstnanec by za tutéž zameškanou hodinu přišel o míň,
 * než kolik za ni dostal náhradou.
 *
 * Svátek uvnitř okna náhrady při DPN se přesto MEZI NAHRAZENÉ MINUTY počítá:
 * náhrada za něj podle § 192 odst. 1 ZP náleží, takže tatáž doba nesmí zůstat
 * i v základní mzdě. Nahrazené minuty proto mohou fond o svátky přesáhnout —
 * pak v základní mzdě nezbývá nic a zbytek už kryje sama náhrada.
 *
 * ── Zaokrouhlení ────────────────────────────────────────────────────────────
 *
 * Jednou, až na konci, na celé koruny nahoru (§ 142 odst. 2 ZP). Mezikroky se
 * počítají přesným zlomkem — zaokrouhlený hodinový výdělek vynásobený hodinami
 * by ukrojil haléře, o kterých zákon nemluví.
 */
final class MonthlyWageProration
{
    /**
     * @param array<string,int> $replacedMinutesByTitle titul náhrady => minuty
     *        vyňaté ze základní mzdy; klíč je jen popisný a do aritmetiky
     *        nevstupuje jinak než součtem
     */
    public static function calculate(
        int $monthlyGrossMinor,
        int $fundMinutes,
        array $replacedMinutesByTitle,
    ): MonthlyWageProrationResult {
        if ($monthlyGrossMinor < 0) {
            throw new InvalidArgumentException('Měsíční mzda nesmí být záporná.');
        }
        if ($fundMinutes <= 0) {
            throw new InvalidArgumentException(
                'Měsíční mzdu nelze krátit bez kladného fondu pracovní doby.',
            );
        }
        $replaced = 0;
        foreach ($replacedMinutesByTitle as $title => $minutes) {
            if ($minutes < 0) {
                throw new InvalidArgumentException(
                    "Nahrazené minuty titulu {$title} nesmí být záporné.",
                );
            }
            $replaced += $minutes;
        }

        // Svátek proplacený náhradou při DPN ve fondu není, ale mezi nahrazenými
        // minutami ano — přesah je tedy legitimní stav, ne chyba dat. Že vstup
        // dává smysl, ověřuje volající; tady zbývá jen nezáporný zbytek.
        $retained = max(0, $fundMinutes - $replaced);
        // Celý měsíc odpracovaný: sjednaná částka se vrací beze změny. Průchod
        // zlomkem by u ní byl jen příležitost k haléřovému posunu.
        $amount = $retained === $fundMinutes
            ? $monthlyGrossMinor
            : RoundingMode::Ceil->roundFraction(
                $monthlyGrossMinor * $retained,
                $fundMinutes * 100,
            ) * 100;

        return new MonthlyWageProrationResult(
            $monthlyGrossMinor,
            $fundMinutes,
            $retained,
            $replaced,
            array_map(intval(...), $replacedMinutesByTitle),
            $amount,
        );
    }
}
