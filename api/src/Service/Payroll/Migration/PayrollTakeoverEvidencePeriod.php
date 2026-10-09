<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Jeden úsek zákonné evidence převzaté z předchozího mzdového systému: stav, od kdy
 * (do kdy) platí a čím je doložený.
 *
 * Stejný tvar nese daňová rezidence (`czech-resident` / `non-resident`), prohlášení
 * poplatníka (`signed` / `not-signed`), sleva pracujícího důchodce, zdravotní pojištění
 * (stav = kód pojišťovny) i příslušnost k sociálnímu pojištění (`czech` / `foreign`).
 * Odkaz na zdroj a poznámku skládá čtečka zdroje: jen ona ví, z čeho údaj pochází.
 * `country` nese stát daňové rezidence nerezidenta, pokud ho zdroj vede.
 */
final readonly class PayrollTakeoverEvidencePeriod
{
    public function __construct(
        public string $status,
        public string $from,
        public ?string $to = null,
        public ?string $reference = null,
        public string $note = '',
        public ?string $country = null,
    ) {}

    /**
     * Začátek zákonné evidence OSOBY: nejstarší začátek ze všech jejích převáděných vztahů.
     *
     * Evidence (zdravotní pojištění, příslušnost k sociálnímu pojištění, daňová
     * rezidence) je na osobě a převod ji zapisuje jen do prázdných řad, tedy z vztahu,
     * který zpracuje první. Ten ale nemusí být nejstarší (souběžná starší dohoda,
     * nový poměr s nižším pořadím) a měsíce staršího vztahu by zůstaly bez evidence.
     * Čtečka zdroje proto začátek počítá přes všechny vztahy osoby touhle metodou.
     *
     * @param iterable<array{0:string,1:string}> $starts [klíč osoby, začátek `YYYY-MM-DD`]
     * @return array<string,string> klíč osoby => nejstarší začátek
     */
    public static function earliestByPerson(iterable $starts): array
    {
        $earliest = [];
        foreach ($starts as [$person, $from]) {
            if (!isset($earliest[$person]) || $from < $earliest[$person]) {
                $earliest[$person] = $from;
            }
        }

        return $earliest;
    }
}
