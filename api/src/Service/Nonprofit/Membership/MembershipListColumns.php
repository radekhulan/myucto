<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

/**
 * Sloupce souboru seznamu členů: export je přesně importovatelný soubor (krok 8 #321).
 * Informační sloupce (dnešní stav, úhrady) import čte jen kvůli hlavičce a ignoruje je;
 * změnu nesou jen „Nový stav“, „Platí od“, „Podklad“ a „Doložení“. Kontakty ani adresy
 * soubor nenese, jen ID kontaktu a IČO pro napojení nového člena.
 */
final class MembershipListColumns
{
    /** Sloupce, které export vyplňuje informačně a import nečte. */
    public const INFORMATIVE = ['current_status', 'current_since', 'claim_count', 'prescribed', 'paid', 'open', 'payment_status', 'last_claim', 'last_claim_amount'];

    /** @return array<string,array{header:string, aliases:list<string>, required:string, note:string}> */
    public static function all(): array
    {
        return [
            'member_number' => ['header' => 'Členské číslo', 'aliases' => ['cislo clena', 'member number', 'member_number'], 'required' => 'ano',
                'note' => 'Existující člen se páruje jen podle členského čísla. Neznámé číslo = nový člen. Text, který sám začíná apostrofem, má v souboru apostrof zdvojený (\'\'-1 = číslo \'-1); import jeden odebere.'],
            'client_id' => ['header' => 'ID kontaktu', 'aliases' => ['client_id', 'contact id', 'id klienta'], 'required' => 'ne',
                'note' => 'Nový člen: existující kontakt v adresáři. U existujícího člena musí odpovídat jeho kontaktu.'],
            'ic' => ['header' => 'IČO', 'aliases' => ['ic', 'ico', 'company id'], 'required' => 'ne',
                'note' => 'Nový člen: kontakt s tímto IČO; když žádný není, založí se s ním nový kontakt.'],
            'name' => ['header' => 'Jméno / název', 'aliases' => ['jmeno', 'nazev', 'name', 'jmeno nazev'], 'required' => 'u nového kontaktu',
                'note' => 'Jen pro založení nového kontaktu. Podle jména se nikdy nepáruje.'],
            'current_status' => ['header' => 'Dnešní stav', 'aliases' => ['current status'], 'required' => 'ne', 'note' => 'Jen informace, import ji ignoruje.'],
            'current_since' => ['header' => 'Stav od', 'aliases' => ['current since'], 'required' => 'ne', 'note' => 'Jen informace, import ji ignoruje.'],
            'claim_count' => ['header' => 'Počet předpisů', 'aliases' => ['claim count'], 'required' => 'ne', 'note' => 'Za rok a označení příspěvku z filtru seznamu. Jen informace.'],
            'prescribed' => ['header' => 'Předepsáno', 'aliases' => ['prescribed'], 'required' => 'ne', 'note' => 'Jen informace.'],
            'paid' => ['header' => 'Uhrazeno', 'aliases' => ['paid'], 'required' => 'ne', 'note' => 'Prázdné, když úhradu nejde prokázat. Jen informace.'],
            'open' => ['header' => 'Zbývá', 'aliases' => ['open'], 'required' => 'ne', 'note' => 'Prázdné, když úhradu nejde prokázat. Jen informace.'],
            'payment_status' => ['header' => 'Stav úhrady', 'aliases' => ['payment status'], 'required' => 'ne', 'note' => 'paid, unpaid, unproven, none. Jen informace.'],
            'last_claim' => ['header' => 'Poslední předpis', 'aliases' => ['last claim'], 'required' => 'ne', 'note' => 'Označení a období posledního platného předpisu z filtru. Jen informace.'],
            'last_claim_amount' => ['header' => 'Částka posledního předpisu', 'aliases' => ['last claim amount'], 'required' => 'ne', 'note' => 'Jen informace.'],
            'new_status' => ['header' => 'Nový stav', 'aliases' => ['new status'], 'required' => 'ano (sloupec)',
                'note' => 'active, suspended, ended (také aktivní, pozastavené, ukončené). Prázdné = beze změny. Nový člen musí začínat stavem active.'],
            'new_from' => ['header' => 'Platí od', 'aliases' => ['effective from', 'effective_from'], 'required' => 'u změny',
                'note' => 'Datum RRRR-MM-DD nebo D. M. RRRR. Musí být po datu poslední platné revize.'],
            'reference' => ['header' => 'Podklad', 'aliases' => ['odkaz na doklad', 'reference'], 'required' => 'u změny',
                'note' => 'Odkaz na doklad (stanovy, přihláška, usnesení), nejvýše 1000 znaků.'],
            'evidence' => ['header' => 'Doložení', 'aliases' => ['dolozeni', 'evidence'], 'required' => 'ne',
                'note' => 'Doložení členského vztahu, nejvýše 2000 znaků. Prázdné převezme text podkladu.'],
        ];
    }

    /** @return list<string> */
    public static function headers(): array
    {
        return array_values(array_map(static fn (array $column): string => $column['header'], self::all()));
    }
}
