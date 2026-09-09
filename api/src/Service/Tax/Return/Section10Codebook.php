<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Číselník ostatních příjmů podle § 10 ZDP — sloupec 1 (druh příjmu, `kod_dr_prij10`)
 * a sloupec 5 (kód, `kod10`) tabulky 2. oddílu Přílohy č. 2 tiskopisu DPFDP7.
 *
 * JEDINÝ zdroj pravdy pro obě sady: čte ho ukládání vstupů
 * ({@see TaxReturnService::section10Items}), kalkulátor, stavěč XML
 * ({@see DpfoXmlBuilder::buildAppendix2}) i finalizační brána
 * ({@see DpfoEpoBusinessValidator}). Třída je záměrně veřejně volatelná —
 * `private` helper uvnitř jedné akce by se okopíroval dřív, než by se použil.
 *
 * Popisy jsou doslovné znění anotací `xs:documentation` u atributů `kod_dr_prij10`
 * a `kod10` ve schématu `api/xsd/dpfdp7_epo2.xsd` (shodné s úředním popisem struktury
 * DPFDP7 na daňovém portálu). Oba atributy jsou ve schématu `xs:string` s
 * `maxLength = 1` — kód je vždy JEDNO písmeno, ne seznam.
 */
final class Section10Codebook
{
    /** Bezúplatný příjem — jediný druh, ke kterému se váže kód „N" (nemovitost). */
    public const KIND_GRATUITOUS = 'G';

    /** Bezúplatný příjem, který je nemovitostí — smysluplný jen u druhu „G". */
    public const CODE_REAL_ESTATE = 'N';

    /** Popis položky, na kterou se převádí zrušený jednořádkový vstup `s10_other`. */
    public const LEGACY_ITEM_TEXT = 'Ostatní příjmy podle § 10';

    /** Druh příjmu (sloupec 1, `kod_dr_prij10`). @var array<string,string> */
    private const KINDS = [
        'A' => 'Příležitostná činnost',
        'B' => 'Prodej nemovitostí',
        'C' => 'Prodej movitých věcí',
        'D' => 'Prodej cenných papírů',
        'E' => 'Příjmy z převodu podle § 10 odst. 1 písm. c) zákona',
        'F' => 'Jiné ostatní příjmy (např. z hazardních her podle § 10 odst. 1 písm. h) bodů 2 až 6 zákona)',
        'G' => 'Bezúplatné příjmy',
        'H' => 'Příjmy z loterie a tomboly podle § 10 odst. 1 písm. h) bodu 1 zákona',
    ];

    /** Kód (sloupec 5, `kod10`) — volitelný. @var array<string,string> */
    private const CODES = [
        'P' => 'Příjmy ze zemědělské výroby s výdaji procentem z příjmů',
        'S' => 'Majetek ve společném jmění manželů',
        'Z' => 'Příjmy ze zdrojů v zahraničí',
        'N' => 'Bezúplatný příjem (druh „G"), který je nemovitostí',
    ];

    /** @return array<string,string> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /** @return array<string,string> */
    public static function codes(): array
    {
        return self::CODES;
    }

    public static function isKind(string $value): bool
    {
        return isset(self::KINDS[$value]);
    }

    public static function isCode(string $value): bool
    {
        return isset(self::CODES[$value]);
    }

    public static function kindLabel(string $value): ?string
    {
        return self::KINDS[$value] ?? null;
    }

    public static function codeLabel(string $value): ?string
    {
        return self::CODES[$value] ?? null;
    }

    /**
     * Vrátí platné písmeno druhu příjmu, jinak prázdný řetězec. Neznámou hodnotu
     * NEHÁDÁME na nejbližší kód — prázdný atribut a varování je podle zásady projektu
     * lepší než odhad, který projde tiše.
     */
    public static function normalizeKind(mixed $value): string
    {
        $s = strtoupper(trim((string) $value));
        return self::isKind($s) ? $s : '';
    }

    /** Vrátí platné písmeno kódu (P/S/Z/N), jinak prázdný řetězec. */
    public static function normalizeCode(mixed $value): string
    {
        $s = strtoupper(trim((string) $value));
        return self::isCode($s) ? $s : '';
    }

    /**
     * Číselník pro API/frontend — pole `{code, label}` v pořadí tiskopisu.
     *
     * @return array{kinds:list<array{code:string,label:string}>,codes:list<array{code:string,label:string}>}
     */
    public static function forApi(): array
    {
        $map = static fn (array $items): array => array_map(
            static fn (string $code, string $label): array => ['code' => $code, 'label' => $label],
            array_keys($items),
            array_values($items),
        );

        return ['kinds' => $map(self::KINDS), 'codes' => $map(self::CODES)];
    }

    /**
     * Převede zrušený jednořádkový agregát § 10 (`s10_other`) na JEDNU položku
     * `s10_items[]`. Bez převodu se vyplněné číslo do podání nikdy nedostalo:
     * zkušební EPO souhrnné `kc_prij10`/`kc_vyd10`/`kc_zd10p` bez alespoň jedné věty
     * VetaJ odmítá třemi křížovými kontrolami, takže {@see DpfoXmlBuilder} Přílohu č. 2
     * z agregátu vůbec nestavěl — účetní vyplnila pole, které skončilo v tichu.
     *
     * Volá se na OBOU stranách: při ukládání vstupů (převod se rovnou uloží) i při
     * načtení/přepočtu už uloženého přiznání (starší draft se opraví za běhu).
     * Druh příjmu převedená položka nemá — doplnit ho musí uživatel a
     * {@see DpfoEpoBusinessValidator} to před finalizací vytkne.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    public static function mergeLegacyAggregate(array $inputs): array
    {
        $legacy = is_array($inputs['s10_other'] ?? null) ? $inputs['s10_other'] : [];
        $income = round((float) ($legacy['income'] ?? 0), 2);
        $expenses = round((float) ($legacy['expenses'] ?? 0), 2);
        if ($income <= 0.0 && $expenses <= 0.0) {
            return $inputs;
        }

        $items = is_array($inputs['s10_items'] ?? null) ? array_values($inputs['s10_items']) : [];
        $items[] = [
            'kind_code' => '',
            'code' => '',
            'text' => self::LEGACY_ITEM_TEXT,
            'income' => $income,
            'expenses' => $expenses,
            'evidence_ref' => '',
        ];
        $inputs['s10_items'] = $items;
        $inputs['s10_other'] = ['income' => 0.0, 'expenses' => 0.0];

        return $inputs;
    }
}
