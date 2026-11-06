<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Číselníky chyb a varování z popisu datového rozhraní EET 2.0 v1.2 z 25. 8. 2026
 * (kap. 3.5.4 a 3.4.4). Texty správce daně jsou bez diakritiky; tady je k nim
 * český popis pro obsluhu a klasifikace výsledku.
 *
 * Kód 8 („technicka chyba nebo chyba dat“) se záměrně řadí mezi dočasné chyby:
 * správce daně jím hlásí i technický problém, takže tržbu nelze bez opakování
 * prohlásit za odmítnutou. Kdyby šlo o chybu dat, opakování skončí na lhůtě
 * a hlídání fronty ji ukáže.
 */
final class EetErrorCatalog
{
    /** @var array<int, array{text:string, description:string}> */
    public const ERRORS = [
        -1 => [
            'text' => 'Docasna technicka chyba zpracovani - odeslete prosim datovou zpravu pozdeji',
            'description' => 'Dočasná technická chyba na straně správce daně; zprávu je potřeba odeslat znovu později.',
        ],
        0 => [
            'text' => 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat',
            'description' => 'Ověřovací mód: zpráva je formálně v pořádku, tržba se neeviduje.',
        ],
        2 => [
            'text' => 'Kodovani XML neni platne',
            'description' => 'Zpráva není v kódování UTF-8.',
        ],
        3 => [
            'text' => 'XML zprava nevyhovela kontrole XML schematu',
            'description' => 'Zpráva nevyhověla schématu XSD.',
        ],
        4 => [
            'text' => 'Neplatny podpis SOAP zpravy',
            'description' => 'Podpis zprávy neplatí nebo pokladní certifikát není platný či důvěryhodný.',
        ],
        6 => [
            'text' => 'EIC poplatnika ma chybnou strukturu',
            'description' => 'EIČ poplatníka má chybnou strukturu.',
        ],
        7 => [
            'text' => 'Datova zprava je prilis velka',
            'description' => 'Zpráva včetně SOAP obálky přesáhla 12 kB.',
        ],
        8 => [
            'text' => 'Datova zprava nebyla zpracovana kvuli technicke chybe nebo chybe dat',
            'description' => 'Zpráva nebyla zpracována kvůli technické chybě nebo chybě dat.',
        ],
    ];

    /** @var array<int, array{text:string, description:string}> */
    public const WARNINGS = [
        1 => [
            'text' => 'EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu',
            'description' => 'EIČ ve zprávě se neshoduje s EIČ v pokladním certifikátu.',
        ],
        2 => [
            'text' => 'Chybny format EIC poverujiciho poplatnika',
            'description' => 'Chybný formát EIČ pověřujícího poplatníka.',
        ],
        4 => [
            'text' => 'Datum a cas uskutecneni trzby je novejsi nez datum a cas prijeti zpravy',
            'description' => 'Čas tržby je o více než 2 hodiny novější než čas přijetí zprávy.',
        ],
        5 => [
            'text' => 'Datum a cas uskutecneni trzby je vyrazne v minulosti',
            'description' => 'Čas tržby je výrazně v minulosti (více než 2 roky nebo před začátkem prostředí).',
        ],
        6 => [
            'text' => 'id_jednotky neodpovida formatem pridelenemu c. evidencni jednotky',
            'description' => 'Označení evidenční jednotky neodpovídá tvaru přidělovanému v DIS+.',
        ],
        7 => [
            'text' => 'EIC poverujiciho se shoduje s EIC poplatnika',
            'description' => 'EIČ pověřujícího poplatníka je shodné s EIČ poplatníka.',
        ],
    ];

    /** Klasifikace chybové odpovědi (kromě kódu 0, který závisí na módu zprávy). */
    public static function outcomeFor(int $code): EetOutcome
    {
        if ($code < 0 || $code === 8) {
            return EetOutcome::TemporaryError;
        }

        return EetOutcome::Rejected;
    }

    public static function errorDescription(int $code): string
    {
        if (isset(self::ERRORS[$code])) {
            return self::ERRORS[$code]['description'];
        }

        return $code < 0
            ? 'Technická chyba správce daně (kód rezervovaný pro budoucí použití).'
            : 'Neznámá chyba (kód rezervovaný pro budoucí použití).';
    }

    public static function warningDescription(int $code): string
    {
        return self::WARNINGS[$code]['description'] ?? 'Neznámé varování (kód rezervovaný pro budoucí použití).';
    }
}
