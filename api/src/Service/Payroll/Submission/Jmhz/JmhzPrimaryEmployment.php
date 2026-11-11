<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Primární pracovněprávní vztah (JMHZ 10495) za osobu v jednom podání.
 *
 * Datový slovník 1.4.1.6 i 1.5.0.3 vede 10495 jako příznak formuláře
 * (`hlavicka.primarniPpv`) a kontroly 255 a 260 ho posuzují „za OIČ v rámci
 * podání": v podání musí být právě jeden primární vztah osoby. Příznak tedy
 * rozlišuje SOUBĚŽNÉ vztahy vykázané v témže měsíci, ne pořadí dohod v čase.
 *
 * Vykazuje-li osoba v měsíci jediný vztah, je primární on, i když ho evidence
 * vede jako vedlejší. Typicky agentura s navazujícími DPP: nová dohoda vznikla
 * jako vedlejší, protože ta předchozí v evidenci ještě byla primární, a po jejím
 * skončení už podmínky opravit nejdou. Nerozhodnutý údaj (`null`) se nedoplňuje,
 * a u více vztahů rozhoduje jen evidence.
 *
 * Jediné místo, odkud příznak berou příprava podání i sestavení formulářů.
 */
final class JmhzPrimaryEmployment
{
    /**
     * @param array<array-key,mixed> $employmentRows řádky s klíčem `employment`
     * @return array<array-key,?bool> účinný příznak podle klíče řádku
     */
    public static function flags(array $employmentRows): array
    {
        $flags = [];
        foreach ($employmentRows as $key => $row) {
            $employment = is_array($row) && is_array($row['employment'] ?? null)
                ? $row['employment']
                : [];
            $flag = $employment['is_primary'] ?? null;
            $flags[$key] = is_bool($flag) ? $flag : null;
        }
        if (count($flags) === 1 && reset($flags) === false) {
            $flags[array_key_first($flags)] = true;
        }

        return $flags;
    }
}
