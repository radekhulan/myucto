<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vazba dokladových cizích klíčů skladu na aktuálního tenanta (CWE-639 / BOLA).
 *
 * Sklad si k dokladu ukládá vazby do jiných agend — `invoice_id`,
 * `purchase_invoice_id`, `stock_take_id` v hlavičce a `invoice_item_id`,
 * `purchase_invoice_item_id` na řádku. Všechny chodí z TĚLA requestu a před
 * touhle třídou se zapisovaly bez kontroly, komu cílový řádek patří (externí
 * security report 2026-08, sweep S102 — tam vedený jako „guarded", protože
 * extrakce viděla jen Action vrstvu; FK se konzumují až
 * v {@see StockDocumentService::validateBody()} a
 * {@see StockAcquisitionCostService::applyLandedCosts()}).
 *
 * ## Proč to nežije v `TenantReferenceGuard::SCOPES`
 *
 * Původně dva důvody; po navazující revizi (2026-08-04) zbyl jeden — ten schématický:
 *
 *   1. `invoice_items` a `purchase_invoice_items` NEMAJÍ `supplier_id` — vlastnictví
 *      se odvozuje přes rodičovský doklad. Centrální guard umí jen `VIA_SUPPLIER`
 *      a `VIA_CLIENT` (`clients.client_id`), tenhle tvar do něj nepatří. `stock_take_id`
 *      zůstává tady s nimi, ať skladový povrch drží jedna třída.
 *   2. ~~`invoice_id` / `purchase_invoice_id` rozsvítí discovery test na pěti Action
 *      mimo sklad~~ — VYŘEŠENO. Ta revize proběhla: oba sloupce (a `asset_id`) jsou
 *      od té doby v `TenantReferenceGuard::SCOPES`, čtyři z pěti Action dostaly
 *      zdůvodněnou položku v `ALTERNATIVE_GUARDS` s živým testem a pátá
 *      (`Section79Action`) byla skutečný nález a volá teď centrální guard.
 *      Hlavičkové `invoice_id` / `purchase_invoice_id` tu proto zůstávají jen kvůli
 *      volacímu tvaru (`refs` mapa se seznamy id vedle položkových sloupců), ne proto,
 *      že by do centrální mapy nepatřily.
 *
 * Sémantika je záměrně shodná s `TenantReferenceGuard`: prázdné pole = vše v
 * pořádku, `null` / `0` / prázdný řetězec = „nevyplněno" a neověřuje se
 * (to řeší validace), `supplierId <= 0` je fail-closed.
 */
final class StockReferenceGuard
{
    /**
     * Sloupec → tabulka s vlastním `supplier_id`.
     *
     * @var array<string, string>
     */
    public const DIRECT = [
        'invoice_id'          => 'invoices',
        'purchase_invoice_id' => 'purchase_invoices',
        'purchase_order_id'   => 'purchase_orders',
        'stock_take_id'       => 'stock_takes',
    ];

    /**
     * Sloupec → [tabulka BEZ `supplier_id`, sloupec na rodiče, rodičovská tabulka].
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const VIA_PARENT = [
        'invoice_item_id'          => ['invoice_items', 'invoice_id', 'invoices'],
        'purchase_invoice_item_id' => ['purchase_invoice_items', 'purchase_invoice_id', 'purchase_invoices'],
        // `purchase_order_lines` vlastní `supplier_id` MÁ (denormalizace pro tenant
        // filtr), takže by šel i DIRECT tvar. Přes rodiče se ověřuje záměrně: DIRECT
        // sloupce guard čte z HLAVIČKY dokladu, kdežto tenhle chodí per ŘÁDEK —
        // a JOIN na `purchase_orders.supplier_id` je navíc odolný vůči rozjetí
        // denormalizovaného sloupce vůči hlavičce objednávky.
        'purchase_order_line_id'   => ['purchase_order_lines', 'order_id', 'purchase_orders'],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí vazby, které ukazují mimo tenanta.
     *
     * @param array<string, list<int|string|null>|int|string|null> $refs sloupec → id (nebo seznam id)
     * @return list<array{column:string, id:int}> v pořadí sloupců, bez duplicit
     */
    public function violations(int $supplierId, array $refs): array
    {
        $bad = [];

        foreach ($refs as $column => $value) {
            try {
                $ids = self::ids($value);
            } catch (\InvalidArgumentException) {
                $bad[] = ['column' => (string) $column, 'id' => 0];
                continue;
            }
            if ($ids === []) {
                continue;
            }
            if (!isset(self::DIRECT[$column]) && !isset(self::VIA_PARENT[$column])) {
                throw new \InvalidArgumentException(
                    "StockReferenceGuard: neznámý sloupec '{$column}' — doplň ho do DIRECT/VIA_PARENT."
                );
            }
            // Bez supplier kontextu nemůže nic patřit volajícímu → fail-closed.
            $owned = $supplierId > 0 ? $this->owned($supplierId, (string) $column, $ids) : [];
            foreach ($ids as $id) {
                if (!in_array($id, $owned, true)) {
                    $bad[] = ['column' => (string) $column, 'id' => $id];
                }
            }
        }

        return $bad;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function owned(int $supplierId, string $column, array $ids): array
    {
        $place = implode(',', array_fill(0, count($ids), '?'));

        if (isset(self::DIRECT[$column])) {
            // Tabulka pochází výhradně z konstanty, nikdy z requestu.
            $table = self::DIRECT[$column];
            $stmt  = $this->db->pdo()->prepare(
                "SELECT id FROM `{$table}` WHERE id IN ({$place}) AND supplier_id = ?"
            );
        } else {
            [$table, $parentColumn, $parentTable] = self::VIA_PARENT[$column];
            $stmt = $this->db->pdo()->prepare(
                "SELECT c.id FROM `{$table}` c
                   JOIN `{$parentTable}` p ON p.id = c.`{$parentColumn}`
                  WHERE c.id IN ({$place}) AND p.supplier_id = ?"
            );
        }
        $stmt->execute([...$ids, $supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Kladná celá id z jedné hodnoty nebo seznamu; neplatné hodnoty se odmítají.
     *
     * @param list<int|string|null>|int|string|null $value
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($raw as $item) {
            $id = \MyInvoice\Http\ReferenceId::optional($item);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
