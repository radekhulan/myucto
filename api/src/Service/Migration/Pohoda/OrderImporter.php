<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Service\Migration\Shared\MigratedInventoryWriter;
use MyInvoice\Service\Migration\Shared\MigrationHomeCurrency;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Service\Stock\StockException;

/**
 * Otevřené objednávky z převodu datového souboru POHODY (`OBJ`, `OBJpol` v
 * `89_ucetnictvi_mdb.xml`, {@see PohodaMdbAccounting::orders()}).
 *
 * Vydaná objednávka dodavateli se převede jako nákupní objednávka ve stavu odeslaná, takže
 * na skladě jde přijímat proti ní ({@see PurchaseOrderService}); přijatá objednávka od
 * odběratele jako koncept prodejní objednávky ({@see SalesOrderService}), zboží se rezervuje
 * až jejím potvrzením. Převádí se jen nevyřízené: objednávka s příznakem vyřízeno nebo
 * stornem se přeskočí, z položek jen nedodané množství (`Mnozstvi - Dodano`). Položka
 * s kartou zásoby, kterou převod skladu převedl, se na ni naváže; jinak zůstane textovou
 * položkou bez karty a protokol to ohlásí.
 *
 * Objednávka není účetní případ: krok nezakládá zápis v deníku ani skladový pohyb. Mapa
 * převodu drží objednávku pod jejím číslem, opakovaný převod (i další rok agendy, do kterého
 * POHODA nevyřízené objednávky přenáší) ji nezdvojí.
 */
final class OrderImporter
{
    public const STEP = 'orders';

    /** Položek bez karty vyjmenovaných v upozornění. */
    private const SAMPLE = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly PurchaseOrderRepository $purchaseOrderRows,
        private readonly SalesOrderService $salesOrders,
        private readonly MigrationVatRateLookup $vatRates,
        private readonly MigrationHomeCurrency $homeCurrency,
        private readonly MigratedInventoryWriter $inventory,
        private readonly PartnerImporter $partners,
    ) {}

    public function import(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $orders = $ctx->export->orders();
        if ($orders === null) {
            $p->info(self::STEP, 'no_orders_export', 'Export neobsahuje objednávky. Převádí je jen export datového souboru POHODY novějším exportním nástrojem.');
            return;
        }
        // Zkouška nanečisto sklad nezapíná, jen ho převede; objednávky pak projdou s ním.
        if (!$this->inventory->stockEnabled($ctx->supplierId)
            && !($ctx->dryRun && $this->map->hasAny($ctx->supplierId, PohodaImportRepository::KIND_STOCK_WAREHOUSE))) {
            $p->info(self::STEP, 'orders_stock_disabled', 'Objednávky patří ke skladu, firma ho v MyÚčtu nemá zapnutý. Objednávky se nepřevedly; po převodu skladu nebo jeho zapnutí převod spusťte znovu, objednávky se doplní.');
            return;
        }
        $cards = $this->cards($ctx);
        if ($cards === null) {
            $p->info(self::STEP, 'orders_without_stock_export', 'Export neobsahuje sklad (92_sklad.xml), položky objednávek se převedou bez skladových karet.');
        }
        $summary = ['without_card' => [], 'foreign_in_czk' => 0, 'sales' => 0, 'purchase' => 0];
        foreach ($orders as $order) {
            $this->importOrder($ctx, $order, $cards ?? [], $summary);
        }
        if ($summary['without_card'] !== []) {
            $p->warn(self::STEP, 'order_lines_without_card', sprintf(
                '%d položek objednávek nemá v MyÚčtu skladovou kartu (karta se nepřevedla se skladem), převedly se jako textové položky: %s. Na skladě je přijměte nebo vydejte ručně, případně kartu doplňte do objednávky.',
                count($summary['without_card']), implode(', ', array_slice($summary['without_card'], 0, self::SAMPLE)) . (count($summary['without_card']) > self::SAMPLE ? ' …' : ''),
            ), ['items' => array_slice($summary['without_card'], 0, self::SAMPLE)]);
        }
        if ($summary['foreign_in_czk'] > 0) {
            $p->warn(self::STEP, 'orders_foreign_currency_in_czk', "{$summary['foreign_in_czk']} objednávek je v POHODĚ v cizí měně, kterou firma v MyÚčtu nevede (nebo bez kurzu). Převedly se v Kč podle částek POHODY.");
        }
        if ($summary['purchase'] > 0) {
            $p->info(self::STEP, 'purchase_orders_sent', "Vydané objednávky ({$summary['purchase']}) jsou převedené jako odeslané se zbývajícím nedodaným množstvím, na skladě jde příjemkou přijímat proti nim.");
        }
        if ($summary['sales'] > 0) {
            $p->info(self::STEP, 'sales_orders_draft', "Přijaté objednávky ({$summary['sales']}) jsou převedené jako koncepty prodejních objednávek; zboží se rezervuje až jejich potvrzením.");
        }
    }

    /**
     * @param array<string,mixed> $order {@see PohodaMdbAccounting::orders()}
     * @param array<string,array{item:?int,warehouse:?int,sku:string}> $cards
     * @param array{without_card:list<string>,foreign_in_czk:int,sales:int,purchase:int} $summary MĚNÍ SE
     */
    private function importOrder(PohodaContext $ctx, array $order, array $cards, array &$summary): void
    {
        $p = $ctx->protocol;
        $type = $order['type'];
        if ($type === null) {
            $p->count(self::STEP, 'orders_unknown_type');
            return;
        }
        $kind = $type === 'issued' ? PohodaImportRepository::KIND_PURCHASE_ORDER : PohodaImportRepository::KIND_SALES_ORDER;
        $key = $order['number'] !== '' ? $order['number'] : 'id:' . $order['id'];
        if ($this->map->get($ctx->supplierId, $kind, $key) !== null) {
            $p->count(self::STEP, 'existing');
            return;
        }
        if ($order['cancelled']) {
            $p->count(self::STEP, 'skipped_cancelled');
            return;
        }
        if ($order['fulfilled']) {
            $p->count(self::STEP, 'skipped_fulfilled');
            return;
        }
        $date = $order['date'] !== '' ? $order['date'] : $ctx->periodEnd();
        if ($ctx->skipsDate($date)) {
            $p->count(self::STEP, 'later_year_skipped');
            return;
        }
        $currency = $this->currency($ctx, $order);
        $lines = [];
        $quantified = false;
        $withoutCard = [];
        foreach ($order['items'] as $item) {
            $line = $this->line($ctx, $item, $cards, $date, $currency['foreign'], $type, $quantified);
            if ($line === null) {
                continue;
            }
            if ($line['card_missing']) {
                $withoutCard[] = $item['code'] !== '' ? $item['code'] : ($item['text'] !== '' ? mb_substr($item['text'], 0, 40) : 'ID ' . $item['id']);
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            $p->count(self::STEP, $quantified ? 'skipped_fulfilled' : 'skipped_without_items');
            return;
        }
        // Partner jako u dokladů: z převedeného adresáře, podle IČO, jinak z údajů objednávky.
        $partner = PartnerImporter::snapshot($order['partner']);
        $label = $order['number'] !== '' ? $order['number'] : 'ID ' . $order['id'];
        if (!isset($ctx->clientsByPohodaId[$partner['pohoda_id']]) && $partner['name'] === '' && $partner['ico'] === '') {
            $p->warn(self::STEP, 'order_partner_missing', ($type === 'issued' ? 'Vydaná' : 'Přijatá') . " objednávka {$label} nemá partnera, nepřevedla se.", ['order' => $label]);
            $p->count(self::STEP, 'skipped_without_partner');
            return;
        }
        if ($order['currency'] !== '' && $order['currency'] !== 'CZK' && $currency['foreign'] === null) {
            $summary['foreign_in_czk']++;
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT pohoda_order');
        try {
            $clientId = $this->partners->resolvePartner($ctx, $partner);
            $id = $type === 'issued'
                ? $this->purchaseOrder($ctx, $order, $clientId, $date, $currency, $lines)
                : $this->salesOrder($ctx, $order, $clientId, $currency, $lines);
            $pdo->exec('RELEASE SAVEPOINT pohoda_order');
        } catch (StockException|SalesOrderException $e) {
            // Doménová chyba služby objednávek (neaktivní sklad, archivovaný partner …)
            // vznikne před zápisem; vrátí se jen tahle objednávka, ostatní se převedou.
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_order');
            $p->warn(self::STEP, 'order_rejected', ($type === 'issued' ? 'Vydaná' : 'Přijatá') . " objednávka {$label} se nepřevedla: " . $e->getMessage(), ['order' => $label]);
            $p->count(self::STEP, 'skipped_rejected');
            return;
        }
        if ($id === null) {
            $p->count(self::STEP, 'orders_without_warehouse');
            return;
        }
        $this->map->put($ctx->supplierId, $kind, $key, $id, $ctx->runId);
        $p->count(self::STEP, $type === 'issued' ? 'purchase_orders' : 'sales_orders');
        $p->count(self::STEP, 'created');
        $p->count(self::STEP, 'lines', count($lines));
        $summary[$type === 'issued' ? 'purchase' : 'sales']++;
        foreach ($lines as $line) {
            if ($line['partial']) {
                $p->count(self::STEP, 'lines_partially_delivered');
            }
        }
        if ($withoutCard !== []) {
            $p->count(self::STEP, 'lines_without_card', count($withoutCard));
            array_push($summary['without_card'], ...$withoutCard);
        }
    }

    /**
     * Zbývající (nedodaná) část položky; `null` = položka se nepřevádí (textový řádek bez
     * množství, plně dodaná, záporná cena v nákupu). `$quantified` = objednávka má aspoň
     * jednu položku s množstvím.
     *
     * @param array<string,mixed> $item
     * @param array<string,array{item:?int,warehouse:?int,sku:string}> $cards
     * @param array{id:int,rate:float}|null $foreign
     * @return array<string,mixed>|null
     */
    private function line(PohodaContext $ctx, array $item, array $cards, string $date, ?array $foreign, string $type, bool &$quantified): ?array
    {
        $p = $ctx->protocol;
        $ordered = (float) $item['quantity'];
        if ($ordered <= 0) {
            $p->count(self::STEP, 'lines_skipped_text');
            return null;
        }
        $quantified = true;
        $remaining = round($ordered - max(0.0, (float) $item['delivered']), 3);
        if ($remaining <= 0) {
            $p->count(self::STEP, 'lines_delivered');
            return null;
        }
        $card = $item['card'] !== '' ? ($cards[$item['card']] ?? null) : null;
        $itemId = $card['item'] ?? null;
        // Množství v alternativní jednotce karty (`MJKoef`) se přepočte na jednotku karty.
        $ratio = $itemId !== null && abs((float) $item['unit_ratio'] - 1.0) > 0.000001 ? (float) $item['unit_ratio'] : 1.0;
        $qty = round($remaining * $ratio, 3);
        if ($qty <= 0) {
            $p->count(self::STEP, 'lines_skipped_text');
            return null;
        }
        $percent = $item['vat_percent'] ?? StockImporter::vatPercent((int) $item['vat_slot'], (int) substr($date, 0, 4));
        $vatRateId = $percent === null ? null : $this->vatRates->find((float) $percent, $date);
        $unitPrice = self::unitPrice($item, $ordered * $ratio, $foreign, (float) ($percent ?? 0));
        if ($unitPrice < 0 && ($type === 'issued' || $itemId !== null)) {
            $p->count(self::STEP, 'lines_skipped_negative');
            return null;
        }
        return [
            'stock_item_id' => $itemId,
            'warehouse_id' => $itemId !== null ? $card['warehouse'] : null,
            'card_missing' => $item['card'] !== '' && $itemId === null,
            'description' => mb_substr($item['text'] !== '' ? $item['text'] : ($item['code'] !== '' ? $item['code'] : ($card['sku'] ?? '')), 0, 500),
            'code' => $item['code'],
            'unit' => $ratio === 1.0 && $item['unit'] !== '' ? mb_substr($item['unit'], 0, 20) : null,
            'quantity' => number_format($qty, 3, '.', ''),
            'unit_price' => $unitPrice,
            'vat_rate_id' => $vatRateId,
            'vat_percent' => $percent,
            'partial' => $remaining < $ordered,
            'ordered' => $ordered,
            'delivered' => (float) $item['delivered'],
        ];
    }

    /**
     * Cena za jednotku bez DPH: základ položky (`Kc`, v cizí měně `Cm`) po slevě děleno
     * objednaným množstvím; položka bez základu z ceny za jednotku (s DPH přepočtená sazbou).
     *
     * @param array<string,mixed> $item
     * @param array{id:int,rate:float}|null $foreign
     */
    private static function unitPrice(array $item, float $quantity, ?array $foreign, float $percent): float
    {
        $total = $foreign !== null ? $item['foreign_price'] : $item['price'];
        $unit = $foreign !== null ? $item['foreign_unit_price'] : $item['unit_price'];
        if ($total !== null && (float) $total != 0.0 && $quantity > 0) {
            return round((float) $total / $quantity, 6);
        }
        $unit = (float) ($unit ?? 0);
        return round($item['prices_include_vat'] ? $unit / (1 + $percent / 100) : $unit, 6);
    }

    /**
     * @param array<string,mixed> $order
     * @param array{id:int,foreign:array{id:int,rate:float}|null} $currency
     * @param list<array<string,mixed>> $lines
     * @return int|null purchase_orders.id; `null` = firma nemá aktivní sklad
     */
    private function purchaseOrder(PohodaContext $ctx, array $order, int $vendorId, string $date, array $currency, array $lines): ?int
    {
        $warehouseId = null;
        foreach ($lines as $line) {
            $warehouseId ??= $line['warehouse_id'];
        }
        $warehouseId ??= $this->defaultWarehouse($ctx->supplierId);
        $label = $order['number'] !== '' ? $order['number'] : 'ID ' . $order['id'];
        if ($warehouseId === null) {
            $ctx->protocol->warn(self::STEP, 'order_without_warehouse', "Vydaná objednávka {$label} se nepřevedla: firma nemá aktivní sklad, do kterého by se přijímala.", ['order' => $label]);
            return null;
        }
        $internal = "Převzato z POHODY, vydaná objednávka {$label}." . ($order['note'] !== '' ? "\n" . $order['note'] : '');
        $created = $this->purchaseOrders->create($ctx->supplierId, [
            'vendor_id' => $vendorId,
            'order_date' => $date,
            'expected_date' => $order['delivery'] !== '' ? $order['delivery'] : null,
            'warehouse_id' => $warehouseId,
            'currency_id' => $currency['id'],
            'exchange_rate' => $currency['foreign']['rate'] ?? null,
            'vendor_reference' => $order['partner_reference'],
            'note' => $order['text'],
            'internal_note' => $internal,
            'lines' => array_map(static fn (array $l): array => [
                'stock_item_id' => $l['stock_item_id'],
                'warehouse_id' => $l['warehouse_id'],
                'vendor_sku' => $l['stock_item_id'] === null && $l['code'] !== '' ? $l['code'] : null,
                'description' => $l['description'],
                'unit' => $l['unit'],
                'qty_ordered' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'vat_rate_id' => $l['vat_rate_id'],
                'expected_date' => $order['delivery'] !== '' ? $order['delivery'] : null,
                'note' => $l['partial'] ? self::partialNote($l) : null,
            ], $lines),
        ], $ctx->userOrNull());
        $id = (int) $created['id'];
        // Číslo z POHODY, pokud ho firma ještě nemá; jinak přidělí číslo řada objednávek.
        $number = mb_substr($order['number'], 0, 30);
        if ($number !== '' && !$this->purchaseNumberTaken($ctx->supplierId, $number)) {
            $this->purchaseOrderRows->assignNumber($ctx->supplierId, $id, $number);
        } elseif ($number !== '') {
            $ctx->protocol->count(self::STEP, 'renumbered');
        }
        $this->purchaseOrders->send($ctx->supplierId, $id, $ctx->userOrNull());
        return $id;
    }

    /**
     * @param array<string,mixed> $order
     * @param array{id:int,foreign:array{id:int,rate:float}|null} $currency
     * @param list<array<string,mixed>> $lines
     */
    private function salesOrder(PohodaContext $ctx, array $order, int $clientId, array $currency, array $lines): ?int
    {
        $date = $order['date'] !== '' ? $order['date'] : $ctx->periodEnd();
        $default = null;
        $payload = [];
        foreach ($lines as $line) {
            $vatRateId = $line['vat_rate_id'] ?? $this->vatRates->find(0.0, $date);
            if ($vatRateId === null) {
                throw new SalesOrderException('vat_rate_not_found', 'Sazbu DPH ' . ($line['vat_percent'] ?? '?') . ' % položky „' . $line['description'] . '“ MyÚčto nezná.');
            }
            $warehouseId = $line['warehouse_id'];
            if ($line['stock_item_id'] !== null && $warehouseId === null) {
                $warehouseId = $default ??= $this->defaultWarehouse($ctx->supplierId);
                if ($warehouseId === null) {
                    return null;
                }
            }
            $payload[] = [
                'stock_item_id' => $line['stock_item_id'],
                'warehouse_id' => $warehouseId,
                'description' => $line['description'] !== '' ? $line['description'] : 'Položka objednávky',
                'unit' => $line['unit'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => number_format((float) $line['unit_price'], 6, '.', ''),
                'discount_percent' => '0',
                'vat_rate_id' => $vatRateId,
            ];
        }
        $created = $this->salesOrders->create($ctx->supplierId, [
            'client_id' => $clientId,
            'order_number' => $this->salesNumber($ctx->supplierId, $order['number']),
            'external_source' => 'pohoda',
            'external_id' => mb_substr($order['number'] !== '' ? $order['number'] : 'id:' . $order['id'], 0, 191),
            'currency_id' => $currency['id'],
            'exchange_rate' => $currency['foreign']['rate'] ?? null,
            'prices_include_vat' => false,
            'allocation_policy' => 'partial',
            'lines' => $payload,
        ], $ctx->userOrNull());
        return (int) $created['id'];
    }

    /**
     * Měna objednávky: cizí měna, kterou firma vede, s kurzem za jednotku; jinak koruna
     * firmy (částky v Kč z POHODY).
     *
     * @param array<string,mixed> $order
     * @return array{id:int,foreign:array{id:int,rate:float}|null}
     */
    private function currency(PohodaContext $ctx, array $order): array
    {
        $home = $this->homeCurrency->id($ctx->supplierId);
        $code = (string) $order['currency'];
        if ($code === '' || $code === 'CZK' || strlen($code) !== 3 || (float) $order['rate'] <= 0) {
            return ['id' => $home, 'foreign' => null];
        }
        $id = $this->homeCurrency->idForCode($ctx->supplierId, $code);
        if ($id <= 0) {
            return ['id' => $home, 'foreign' => null];
        }
        $units = (float) $order['units'] > 0 ? (float) $order['units'] : 1.0;
        return ['id' => $id, 'foreign' => ['id' => $id, 'rate' => round((float) $order['rate'] / $units, 6)]];
    }

    /**
     * Karty zásob POHODY (`SKz.ID`) s kartou a skladem, které z nich převod skladu založil.
     * `null` = export sklad nenese.
     *
     * @return array<string,array{item:?int,warehouse:?int,sku:string}>|null
     */
    private function cards(PohodaContext $ctx): ?array
    {
        $path = $ctx->export->path('stock');
        if ($path === null) {
            return null;
        }
        $stock = PohodaStock::read($path);
        $items = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_STOCK_ITEM);
        $warehouses = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_STOCK_WAREHOUSE);
        $out = [];
        foreach ($stock->cards as $card) {
            $sku = trim($card['IDS']);
            $code = $stock->warehouses[$card['RefSklad']]['code'] ?? null;
            $out[$card['ID']] = [
                'item' => $sku !== '' ? ($items[$sku] ?? null) : null,
                'warehouse' => $code !== null ? ($warehouses[$code] ?? null) : null,
                'sku' => $sku,
            ];
        }
        return $out;
    }

    private function defaultWarehouse(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM warehouses WHERE supplier_id = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function purchaseNumberTaken(int $supplierId, string $number): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM purchase_orders WHERE supplier_id = ? AND order_number = ?');
        $stmt->execute([$supplierId, $number]);
        return $stmt->fetchColumn() !== false;
    }

    /** Číslo z POHODY, je-li ve firmě volné; jinak s předponou, nakonec číslo od služby. */
    private function salesNumber(int $supplierId, string $number): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM sales_orders WHERE supplier_id = ? AND order_number = ?');
        foreach ($number !== '' ? [$number, 'POHODA-' . $number] : [] as $candidate) {
            if (mb_strlen($candidate) > 50) {
                continue;
            }
            $stmt->execute([$supplierId, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $line */
    private static function partialNote(array $line): string
    {
        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
        return sprintf('V POHODĚ objednáno %s, dodáno %s.', $fmt((float) $line['ordered']), $fmt((float) $line['delivered']));
    }
}
