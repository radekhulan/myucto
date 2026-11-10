<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/**
 * Sloupce řádku přijaté faktury, které náhrada položek NEPOSLALA, se převezmou z uloženého řádku.
 *
 * ── Co to opravuje ──────────────────────────────────────────────────────────────────
 * `PurchaseInvoiceRepository::replaceItems()` je DELETE + INSERT. Klient API, který o kódu
 * položky dodavatele, EAN, Intrastatu ani o vazbě na skladovou kartu neví, je v PUT
 * neposílá, a náhrada je tiše vynulovala. Na těch sloupcích přitom stojí párování řádku
 * s kartou při příjemce ({@see \MyInvoice\Service\Stock\PurchaseLineStockMatcher})
 * a hlášení Intrastat. Editor i MCP je posílají vždy, takže na ně se pravidlo nevztahuje.
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
 * Volají ho obě akce, které položky přijaté faktury nahrazují:
 * {@see \MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction} (PUT dokladu)
 * a {@see \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction} (PUT položek).
 */
final class StoredPurchaseItemColumns
{
    public const COLUMNS = [
        'stock_item_id',
        'seller_item_code',
        'item_ean',
        'intrastat_cn8_code',
        'intrastat_country_of_origin',
    ];

    /**
     * @param array<int|string,mixed> $items       položky z těla požadavku
     * @param array<int,mixed>        $storedItems položky uloženého dokladu (s `id`)
     *
     * @return array<int|string,mixed> položky se stejnými klíči, chybějící sloupce doplněné
     */
    public static function carryOver(array $items, array $storedItems): array
    {
        $stored = array_values(array_filter($storedItems, 'is_array'));
        if ($stored === []) {
            return $items;
        }
        $byId = [];
        foreach ($stored as $row) {
            if ((int) ($row['id'] ?? 0) > 0) {
                $byId[(int) $row['id']] = $row;
            }
        }

        $position = 0;
        foreach ($items as $key => $item) {
            $index = $position++;
            if (!is_array($item)) {
                continue;
            }
            $missing = array_diff(self::COLUMNS, array_keys($item));
            if ($missing === []) {
                continue;
            }

            $source = null;
            $itemId = $item['id'] ?? null;
            if (is_numeric($itemId) && (int) $itemId > 0) {
                $source = $byId[(int) $itemId] ?? null;
            } elseif (isset($stored[$index])
                && self::sameDescription($item['description'] ?? null, $stored[$index]['description'] ?? null)) {
                $source = $stored[$index];
            }
            if ($source === null) {
                continue;
            }

            foreach ($missing as $column) {
                if (array_key_exists($column, $source)) {
                    $item[$column] = $source[$column];
                }
            }
            $items[$key] = $item;
        }

        return $items;
    }

    private static function sameDescription(mixed $a, mixed $b): bool
    {
        return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
    }
}
