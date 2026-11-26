<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/**
 * Sloupce řádku přijaté faktury, které náhrada položek NEPOSLALA, se převezmou z uloženého řádku.
 *
 * ── Co to opravuje ──────────────────────────────────────────────────────────────────
 * `PurchaseInvoiceRepository::replaceItems()` je DELETE + INSERT. Klient API, který o kódu
 * položky dodavatele, EAN, Intrastatu, vazbě na skladovou kartu, na řádek nákupní objednávky
 * ani o účtech a předkontaci řádku neví, je v PUT neposílá, a náhrada je tiše vynulovala.
 * Na těch sloupcích přitom stojí párování řádku s kartou při příjemce
 * ({@see \MyInvoice\Service\Stock\PurchaseLineStockMatcher}), uzavření objednávky příjmem
 * z faktury a cena příjmu z objednávky, hlášení Intrastat a zaúčtování řádku, které
 * nastavila účetní. Editor i MCP je posílají
 * vždy, takže na ně se pravidlo nevztahuje.
 *
 * ── Pravidla ────────────────────────────────────────────────────────────────────────
 * 1. Rozhoduje `array_key_exists()`, ne prázdná hodnota: explicitní `null` je pokyn
 *    sloupec vymazat a přebít se nesmí. Táž úvaha jako u
 *    {@see \MyInvoice\Action\Invoice\DerivesMissingOssColumns}.
 * 2. Uložený řádek se hledá podle `id` položky. Pošle-li klient `id`, které doklad nemá,
 *    jde o nový řádek a nic se nepřebírá.
 * 3. Bez `id` se páruje pořadím (jako {@see \MyInvoice\Service\Invoice\DocumentItemsPayload}),
 *    ale jen na řádek se stejným popisem. Po smazání nebo přeřazení řádků by jinak EAN
 *    a skladová karta jednoho zboží přeskočily na jiné.
 *
 * Stejné párování používá {@see PurchaseItemReferences} pro vazby na řádky.
 */
final class StoredPurchaseItemColumns
{
    public const COLUMNS = [
        'stock_item_id',
        'seller_item_code',
        'item_ean',
        'intrastat_cn8_code',
        'intrastat_country_of_origin',
        'expense_account_code',
        'counter_account_code',
        'posting_rule_key',
        'accrual_from',
        'accrual_to',
        'advance_purchase_invoice_id',
        'purchase_order_line_id',
    ];

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $stored
     * @return array<int,int> nová pozice => stará pozice, pouze jednoznačné shody
     */
    public static function match(array $items, array $stored): array
    {
        $byId = [];
        foreach ($stored as $index => $row) {
            $byId[(int) $row['id']] = $index;
        }

        $matches = [];
        foreach ($items as $index => $item) {
            $itemId = $item['id'] ?? null;
            if (is_numeric($itemId) && (int) $itemId > 0) {
                if (isset($byId[(int) $itemId])) {
                    $matches[$index] = $byId[(int) $itemId];
                }
            } elseif (isset($stored[$index])
                && self::sameDescription($item['description'] ?? null, $stored[$index]['description'] ?? null)) {
                $matches[$index] = $index;
            }
        }
        $claims = array_count_values($matches);

        return array_filter($matches, static fn (int $index): bool => $claims[$index] === 1);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $stored
     * @param array<int,int> $matches viz {@see match()}
     * @return list<array<string,mixed>>
     */
    public static function carryOver(array $items, array $stored, array $matches): array
    {
        foreach ($matches as $new => $old) {
            foreach (self::COLUMNS as $column) {
                if (!array_key_exists($column, $items[$new])) {
                    $items[$new][$column] = $stored[$old][$column];
                }
            }
        }

        return $items;
    }

    private static function sameDescription(mixed $a, mixed $b): bool
    {
        return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
    }
}
