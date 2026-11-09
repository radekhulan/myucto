<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

/**
 * Druhy dokladů v číselníku řad ({@see NumberSeriesCatalog}) — jediné místo, které ví,
 * do jaké tabulky druh čísluje, jestli pro něj jde založit další řadu a vybrat ji při
 * zakládání dokladu, a v jakém číselném prostoru se jeho čísla nesmí potkat.
 *
 * Číselný prostor = tabulka dokladů s jednou unikátností čísla. Řady jednoho prostoru
 * (např. faktury, zálohy a dobropisy v `invoices.varsymbol`) se proto kontrolují proti
 * sobě navzájem, ať dvě řady nevydají totéž číslo.
 */
final class NumberSeriesKinds
{
    /**
     * @var array<string, array{space: ?string, selectable: bool, register: bool, group: string}>
     *   space      = číselný prostor ({@see self::SPACES}), null = druh bez tabulky dokladů v číselníku
     *   selectable = druh smí mít víc řad a řada se vybírá při zakládání dokladu
     *   register   = řada patří pokladně (rozsah cash_register), firemní platí pro všechny pokladny
     */
    public const KINDS = [
        'invoice'          => ['space' => 'invoices', 'selectable' => true,  'register' => false, 'group' => 'issued'],
        'proforma'         => ['space' => 'invoices', 'selectable' => true,  'register' => false, 'group' => 'issued'],
        'credit_note'      => ['space' => 'invoices', 'selectable' => true,  'register' => false, 'group' => 'issued'],
        'simplified'       => ['space' => 'invoices', 'selectable' => false, 'register' => false, 'group' => 'issued'],
        'purchase'         => ['space' => 'purchase', 'selectable' => true,  'register' => false, 'group' => 'purchase'],
        'cash_in'          => ['space' => 'cash',     'selectable' => true,  'register' => true,  'group' => 'cash'],
        'cash_out'         => ['space' => 'cash',     'selectable' => true,  'register' => true,  'group' => 'cash'],
        'manual'           => ['space' => 'internal', 'selectable' => true,  'register' => false, 'group' => 'accounting'],
        'stock_in'         => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'stock'],
        'stock_out'        => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'stock'],
        'stock_transfer'   => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'stock'],
        'purchase_order'   => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'stock'],
        'other_receivable' => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'accounting'],
        'other_payable'    => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'accounting'],
        'offset'           => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'accounting'],
        'closing'          => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'closing'],
        'opening'          => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'closing'],
        'fx'               => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'closing'],
        'transfer'         => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'closing'],
        'bank'             => ['space' => null, 'selectable' => false, 'register' => false, 'group' => 'bank'],
    ];

    /**
     * Tabulka dokladů číselného prostoru: sloupec čísla a datum, podle kterého se
     * řada dělí na období (pro kontrolu úplnosti a výdej čísla).
     *
     * @var array<string, array{table: string, number: string, date: string}>
     */
    public const SPACES = [
        'invoices' => ['table' => 'invoices',           'number' => 'varsymbol',   'date' => 'issue_date'],
        'purchase' => ['table' => 'purchase_invoices',  'number' => 'varsymbol',   'date' => 'issue_date'],
        'cash'     => ['table' => 'cash_documents',     'number' => 'doc_number',  'date' => 'issue_date'],
        'internal' => ['table' => 'internal_documents', 'number' => 'document_no', 'date' => 'entry_date'],
    ];

    /**
     * Názvy druhů a rozsahů pro hlášky serveru. Zrcadlí `number_series.kinds`
     * a `number_series.scopes` v `web/src/i18n/cs.json`, ze kterých skládá popisek
     * řady bez názvu frontend ({@see self::displayName()}).
     */
    private const KIND_NAMES = [
        'invoice' => 'Faktury', 'proforma' => 'Zálohové faktury', 'credit_note' => 'Dobropisy',
        'simplified' => 'Zjednodušené daňové doklady', 'purchase' => 'Přijaté doklady',
        'cash_in' => 'Příjmové pokladní doklady', 'cash_out' => 'Výdajové pokladní doklady',
        'manual' => 'Interní doklady', 'stock_in' => 'Příjemky', 'stock_out' => 'Výdejky',
        'stock_transfer' => 'Převodky', 'purchase_order' => 'Objednávky',
        'other_receivable' => 'Ostatní pohledávky', 'other_payable' => 'Ostatní závazky',
        'offset' => 'Zápočty', 'closing' => 'Uzávěrkové zápisy', 'opening' => 'Otevření účtů',
        'fx' => 'Kurzové rozdíly', 'transfer' => 'Převody', 'bank' => 'Bankovní výpisy',
    ];

    private const SCOPE_NAMES = [
        'cash_register' => 'pokladna', 'client' => 'klient', 'revenue_category' => 'kategorie tržby',
        'other_item_kind' => 'druh', 'bank_account' => 'účet',
    ];

    /** Šablona nové řady, dokud ji uživatel nezmění (`HP260001`). */
    public const DEFAULT_NATIVE_TEMPLATE = '{PREFIX}{YY}{CCCC}';

    /**
     * Zobrazovaný název řady: vlastní název, jinak (převedená řada má `name` prázdné)
     * druh dokladu doplněný o rozsah, např. „Faktury - klient Alfa s.r.o.".
     * Stejně skládá popisek seznam řad ve frontendu (`numberSeriesLabel`).
     *
     * @param string $scopeLabel název pokladny, klienta, kategorie nebo účtu ('' = firemní řada)
     */
    public static function displayName(string $kind, string $name, string $scopeType, string $scopeLabel): string
    {
        if (trim($name) !== '') {
            return trim($name);
        }
        $kindName = self::KIND_NAMES[$kind] ?? $kind;
        if ($scopeLabel === '') {
            return $kindName;
        }
        $scope = self::SCOPE_NAMES[$scopeType] ?? '';

        return $kindName . ' - ' . ($scope !== '' ? $scope . ' ' : '') . $scopeLabel;
    }

    public static function exists(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    public static function isSelectable(string $kind): bool
    {
        return self::KINDS[$kind]['selectable'] ?? false;
    }

    public static function isRegisterScoped(string $kind): bool
    {
        return self::KINDS[$kind]['register'] ?? false;
    }

    public static function space(string $kind): ?string
    {
        return self::KINDS[$kind]['space'] ?? null;
    }

    /** @return list<string> druhy, pro které jde založit další řadu */
    public static function selectableKinds(): array
    {
        return array_keys(array_filter(self::KINDS, static fn (array $k): bool => $k['selectable']));
    }

    /**
     * Druh řady pro vydaný doklad podle typu. Daňový doklad k platbě, penále a kalendáře
     * splátek jedou v řadě faktur stejně jako v {@see \MyInvoice\Service\Invoice\VarsymbolGenerator}.
     */
    public static function issuedKind(string $invoiceType): string
    {
        return match ($invoiceType) {
            'proforma'    => 'proforma',
            'credit_note' => 'credit_note',
            default       => 'invoice',
        };
    }

    public static function cashKind(string $docType): string
    {
        return $docType === 'in' ? 'cash_in' : 'cash_out';
    }
}
