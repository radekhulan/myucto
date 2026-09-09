<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vazba cizích klíčů z TĚLA requestu na aktuálního tenanta (CWE-639 / BOLA).
 *
 * Vzorec chyby, který tenhle guard zavírá: Action ověří RODIČOVSKÝ objekt z URL
 * přes SupplierGuard::owns(), ale `*_id` z těla requestu zapíše bez kontroly,
 * komu patří. Read-back JOIN pak nemá tenant predikát → cizí řádek se vrátí
 * v odpovědi útočníka (externí security report 2026-08, R2).
 *
 * Použití v Action vrstvě:
 *
 *     $bad = $this->tenantRefs->violations(
 *         SupplierGuard::currentId($request),
 *         $body,
 *         ['client_id', 'project_id', 'currency_id'],
 *     );
 *     if ($bad !== []) {
 *         return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
 *     }
 *
 * ## Proč se seznam sloupců předává explicitně
 *
 * Mapa SCOPES je centrální (jedno místo, kde žije způsob scope-ování a jedno
 * místo, které hlídá schema test), ale KTERÉ sloupce se v daném requestu mají
 * ověřit, říká volající. Důvod je čistě schématický, ne stylový:
 *
 *   - `vendor_id` míří na `clients`, ne na neexistující tabulku `vendors` —
 *     název sloupce význam neurčuje;
 *   - `category_id` je v `trips` FK na `trip_categories`, ale ve `stock_category_i18n`
 *     a `stock_item_categories` FK na `stock_categories`. Jediná globální mapa by
 *     tenhle sloupec musela rozhodnout naslepo a v jednom z modulů by tiše lhala.
 *
 * Slepý sken celého těla by navíc kontroloval i klíče, které Action vůbec
 * nezapisuje (FE posílá celé objekty), a tvořil by z guardu existence oracle
 * na polích, která nikam nejdou. Explicitní výčet je zároveň dokumentace toho,
 * co Action reálně forwarduje do DB.
 */
final class TenantReferenceGuard
{
    /** Tabulka nese vlastní `supplier_id`. */
    public const VIA_SUPPLIER = 'supplier';

    /** Tabulka `supplier_id` NEMÁ — scope-uje se přes `clients.supplier_id`. */
    public const VIA_CLIENT = 'client';

    /**
     * Sloupec → [cílová tabulka, způsob scope-ování].
     *
     * Ověřeno dotazem do `information_schema.KEY_COLUMN_USAGE` /`COLUMNS`, ne
     * odhadem podle názvů (viz TenantReferenceGuardSchemaTest, který mapu drží
     * v souladu se skutečnými FK constrainty).
     *
     * Pozor na dvě věci, které z názvů sloupců vidět nejsou:
     *   - `vendor_id` → `clients` (dodavatelé i odběratelé leží v jedné tabulce,
     *     `vendors` v DB neexistuje; platí pro `purchase_invoices.vendor_id`
     *     i `fuelings.vendor_id`),
     *   - `projects` nemá `supplier_id` a scope-uje se nepřímo přes
     *     `clients.supplier_id` (FK `fk_proj_client`).
     *
     * `revenue_category_id` a `expense_category_id` deklarovaný FK nemají —
     * žijí jen tady a schema test je přeskakuje.
     *
     * ## Proč tu `invoice_id` / `purchase_invoice_id` / `asset_id` JSOU
     *
     * Předchozí kolo je sem vědomě nedalo (viz {@see \MyInvoice\Service\Stock\StockReferenceGuard}),
     * protože zápis do mapy okamžitě rozsvítí discovery test na pěti Action mimo sklad.
     * Samostatná revize je všech pět prošla a rozhodnutí otočila:
     *
     *   - **Schématicky jsou jednoznačné.** Past, kvůli které se sloupce do mapy nedávají
     *     naslepo (`category_id` míří v `trips` jinam než ve `stock_item_categories`), tu
     *     neplatí: v celém schématu míří KAŽDÝ `invoice_id` na `invoices`, každý
     *     `purchase_invoice_id` na `purchase_invoices` a každý `asset_id` na `assets` —
     *     a všechny tři cílové tabulky mají vlastní `supplier_id`. Ověřeno dotazem do
     *     `information_schema`, drží TenantReferenceGuardSchemaTest.
     *   - **Byla potřeba za běhu.** `Section79Action` psal `purchase_invoice_id` i `asset_id`
     *     do `vat_registration_corrections` bez jakékoli kontroly vlastnictví (a ta tabulka
     *     na obou sloupcích FK nemá, takže neexistovala ani databázová záchranná síť).
     *     Bez řádku v mapě by se ta kontrola musela pošesté ručně opsat do service.
     *   - **Cena je pět položek v `ALTERNATIVE_GUARDS`, ne otevřený seznam.** Zbylé čtyři
     *     Action vazbu mají, jen jiným idiomem (`find($supplierId,$id)`, `SupplierGuard::owns`,
     *     supplier-scoped `fetchInvoice`). Každá z nich má živý dvoutenantní protějšek
     *     v `tests/Integration/Security/ReportTenantReferenceIdorTest.php`, takže whitelist
     *     cituje ověřenou obranu, ne domněnku. Vynechat sloupce z mapy by stálo víc:
     *     discovery sken by pak NIKDY neuviděl novou Action, která `invoice_id` z těla čte —
     *     a to je přesně ta třída chyby, kvůli které R2 vzniklo.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const SCOPES = [
        'client_id'                  => ['clients',            self::VIA_SUPPLIER],
        'vendor_id'                  => ['clients',            self::VIA_SUPPLIER],
        'currency_id'                => ['currencies',         self::VIA_SUPPLIER],
        'payment_currency_id'        => ['currencies',         self::VIA_SUPPLIER],
        'revenue_category_id'        => ['revenue_categories', self::VIA_SUPPLIER],
        'expense_category_id'        => ['expense_categories', self::VIA_SUPPLIER],
        'branding_profile_id'        => ['branding_profiles',  self::VIA_SUPPLIER],
        'category_id'                => ['trip_categories',    self::VIA_SUPPLIER],
        'car_id'                     => ['cars',               self::VIA_SUPPLIER],
        'invoice_id'                 => ['invoices',           self::VIA_SUPPLIER],
        'purchase_invoice_id'        => ['purchase_invoices',  self::VIA_SUPPLIER],
        'source_purchase_invoice_id' => ['purchase_invoices',  self::VIA_SUPPLIER],
        'asset_id'                   => ['assets',             self::VIA_SUPPLIER],
        'cash_register_id'           => ['cash_registers',     self::VIA_SUPPLIER],
        'project_id'                 => ['projects',           self::VIA_CLIENT],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí sloupce z $columns, jejichž hodnota v $body ukazuje mimo tenanta.
     *
     * Prázdné pole = vše v pořádku. Chybějící / prázdná / nulová
     * hodnota se přeskakuje. Neplatné ID se odmítá. Jeden SELECT
     * na cílovou tabulku — `currency_id` + `payment_currency_id` se ptají jednou.
     *
     * @param array<string,mixed> $body    tělo requestu
     * @param list<string>        $columns sloupce, které Action zapisuje do DB
     * @return list<string>                podmnožina $columns, v původním pořadí
     */
    public function violations(int $supplierId, array $body, array $columns): array
    {
        return $this->check($supplierId, $body, $columns, self::SCOPES);
    }

    public function itemViolations(int $supplierId, array $items, array $columns): array
    {
        $scopes = [
            'price_list_item_id' => ['price_list_items', self::VIA_SUPPLIER],
            'stock_item_id' => ['stock_items', self::VIA_SUPPLIER],
            'warehouse_id' => ['warehouses', self::VIA_SUPPLIER],
        ];
        $body = [];
        $itemScopes = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                return ['items.' . $index];
            }
            foreach ($columns as $column) {
                if (!isset($scopes[$column])) {
                    throw new \InvalidArgumentException('Neznámý sloupec vazby položky.');
                }
                $key = 'items.' . $index . '.' . $column;
                $body[$key] = $item[$column] ?? null;
                $itemScopes[$key] = $scopes[$column];
            }
        }
        return $this->check($supplierId, $body, array_keys($body), $itemScopes);
    }

    private function check(int $supplierId, array $body, array $columns, array $scopes): array
    {
        /** @var array<string, array<string, array<int, list<string>>>> $byScope */
        $byScope = [];
        $present = [];
        $bad     = [];

        foreach ($columns as $column) {
            if (!isset($scopes[$column])) {
                throw new \InvalidArgumentException(
                    "TenantReferenceGuard: neznámý sloupec '{$column}' — doplň ho do SCOPES."
                );
            }
            $raw = $body[$column] ?? null;
            try {
                $id = ReferenceId::optional($raw);
            } catch (\InvalidArgumentException) {
                $present[] = $column;
                $bad[]     = $column;
                continue;
            }
            if ($id === null) {
                continue;
            }
            $present[] = $column;
            [$table, $via] = $scopes[$column];
            $byScope[$via][$table][$id][] = $column;
        }

        if ($present === []) {
            return [];
        }
        // Bez supplier kontextu nemůže nic patřit volajícímu → fail-closed.
        if ($supplierId <= 0) {
            return self::inOrder($columns, $present);
        }

        foreach ($byScope as $via => $tables) {
            foreach ($tables as $table => $idsToColumns) {
                $ids   = array_map('intval', array_keys($idsToColumns));
                $owned = $via === self::VIA_CLIENT
                    ? $this->ownedViaClient($table, $ids, $supplierId)
                    : $this->ownedDirect($table, $ids, $supplierId);

                foreach ($idsToColumns as $id => $cols) {
                    if (in_array((int) $id, $owned, true)) {
                        continue;
                    }
                    foreach ($cols as $col) {
                        $bad[] = $col;
                    }
                }
            }
        }

        return self::inOrder($columns, $bad);
    }

    /** Chybová hláška pro Json::error(..., 'invalid_reference', ...). */
    public static function message(array $violations): string
    {
        return 'Neplatná vazba na záznam mimo vaši firmu: ' . implode(', ', $violations) . '.';
    }

    /**
     * Tabulka s vlastním `supplier_id`.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function ownedDirect(string $table, array $ids, int $supplierId): array
    {
        // $table pochází výhradně z konstanty SCOPES, nikdy z requestu.
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT id FROM `{$table}` WHERE id IN ({$place}) AND supplier_id = ?"
        );
        $stmt->execute([...$ids, $supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Tabulka bez `supplier_id` — vlastnictví se odvozuje z `clients.supplier_id`
     * (dnes jen `projects`, FK `fk_proj_client`).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function ownedViaClient(string $table, array $ids, int $supplierId): array
    {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT t.id FROM `{$table}` t
               JOIN clients c ON c.id = t.client_id
              WHERE t.id IN ({$place}) AND c.supplier_id = ?"
        );
        $stmt->execute([...$ids, $supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Deduplikace + zachování pořadí podle $columns (stabilní hláška i testy).
     *
     * @param list<string> $columns
     * @param list<string> $subset
     * @return list<string>
     */
    private static function inOrder(array $columns, array $subset): array
    {
        $wanted = array_flip($subset);
        $out    = [];
        foreach ($columns as $column) {
            if (isset($wanted[$column]) && !in_array($column, $out, true)) {
                $out[] = $column;
            }
        }

        return $out;
    }
}
