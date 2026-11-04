<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\StockCategoryRepository;
use MyInvoice\Repository\StockItemCategoryRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Repository\StockPackagingUnitRepository;
use MyInvoice\Repository\StockPriceLevelRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Eshop\CategoryTreeService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Migration\Shared\MigratedInventoryException;
use MyInvoice\Service\Migration\Shared\MigratedInventoryWriter;
use MyInvoice\Service\Migration\Shared\MigratedStockOpening;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemPackagingService;

/**
 * Sklad z `92_sklad.xml`: sklady, karty zásob se stavem k datu exportu, ceníky jako
 * cenové hladiny, členění skladu jako kategorie, alternativní jednotky a dodavatel karty.
 * Historie pohybů se nepřevádí - MyÚčto vede sklad od data převodu.
 *
 * Typ karty: zásoba podle druhu zásoby z karty (zboží / materiál), bez druhu podle volby
 * skladu v průvodci; služba jako neskladová karta; komplet a výrobek jako výrobek (kusovník
 * a výrobu MyÚčto nevede, převod je jen vyjmenuje); textová položka a souprava se nepřevádějí.
 *
 * Počáteční stav jde jednou příjemkou za sklad ({@see MigratedStockOpening}), bez zápisu
 * v deníku: hodnotu zásob nese převedený deník (způsob B), sklad se promítne uzávěrkou.
 * Každý řádek stavu, karta, sklad, ceník a kategorie mají záznam v mapě převodu, takže
 * opakovaný převod nic nezdvojí ani nepřepíše.
 */
final class StockImporter
{
    public const STEP = 'stock';
    private const BATCH = 200;
    private const DESCRIPTION = 'Počáteční stav skladu převzatý z POHODY';

    public function __construct(
        private readonly PohodaImportRepository $map,
        private readonly MigratedInventoryWriter $writer,
        private readonly MigratedStockOpening $opening,
        private readonly MigrationVatRateLookup $vatRates,
        private readonly WarehouseRepository $warehouses,
        private readonly CategoryTreeService $categoryTree,
        private readonly StockCategoryRepository $categories,
        private readonly StockItemCategoryRepository $itemCategories,
        private readonly StockItemVendorRepository $vendors,
        private readonly StockPriceLevelRepository $levels,
        private readonly StockPackagingUnitRepository $packaging,
        private readonly StockItemPackagingService $units,
    ) {}

    public function import(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $path = $ctx->export->path('stock');
        if ($path === null) {
            $p->info(self::STEP, 'no_stock_export', 'Export neobsahuje sklad z datového souboru POHODY (92_sklad.xml). Vytvoří ho exportní nástroj.');
            return;
        }
        if ($ctx->stock === null) {
            $p->info(self::STEP, 'stock_not_selected', 'Sklad se v tomto převodu nepřevádí. Převádí se jen z nejnovější agendy exportu a jen když ho v průvodci vyberete.');
            return;
        }
        if (!$this->writer->stockEnabled($ctx->supplierId)) {
            $p->warn(self::STEP, 'stock_module_missing', 'Firma nemá zapnutý sklad. Zapněte ho v nastavení firmy a převod spusťte znovu, sklad se doplní.');
            return;
        }
        $stock = PohodaStock::read($path);
        $date = $stock->stockDate($ctx->year(), $ctx->calendar->periodEnd($ctx->year()));
        $end = $ctx->lastPeriodEnd();
        if ($end !== null && $date > $end) {
            $date = $end;
        }
        $p->info(self::STEP, 'stock_date', "Stav skladu se převádí k {$date}.", ['date' => $date]);

        $groups = $this->selectCards($ctx, $stock);
        $warehouseIds = $this->importWarehouses($ctx, $stock, $groups);
        $items = $this->importCards($ctx, $stock, $groups);
        $this->importOpening($ctx, $stock, $groups, $items, $warehouseIds, $date);
        $this->importPriceLists($ctx, $stock, $groups, $items, $date);

        $withComponents = 0;
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if (($stock->components[$row['card']['ID']] ?? 0) > 0) {
                    $withComponents++;
                    break;
                }
            }
        }
        if ($withComponents > 0) {
            $p->info(self::STEP, 'bill_of_materials', "{$withComponents} karet má v POHODĚ kusovník. MyÚčto výrobu ani kusovníky nevede, karty se převedly jako výrobky bez složení.", ['cards' => $withComponents]);
        }
    }

    /**
     * Karty k převodu podle kódu: řádky z vybraných skladů s typem a druhem karty.
     *
     * @return array<string,list<array{card:array<string,string>,warehouse:array{id:string,code:string,name:string},item_type:string,stocked:bool}>>
     */
    private function selectCards(PohodaContext $ctx, PohodaStock $stock): array
    {
        $p = $ctx->protocol;
        $choices = $ctx->stock['warehouses'] ?? [];
        $groups = [];
        $packages = 0;
        foreach ($stock->cards as $card) {
            $warehouse = $stock->warehouses[$card['RefSklad']] ?? null;
            if ($warehouse === null) {
                $p->count(self::STEP, 'skipped_without_warehouse');
                continue;
            }
            $choice = $choices[$warehouse['code']] ?? 'goods';
            if ($choice === 'skip') {
                $p->count(self::STEP, 'skipped_warehouse_cards');
                continue;
            }
            $type = (int) $card['RelSkTyp'];
            [$itemType, $stocked] = match ($type) {
                PohodaStock::TYPE_CARD => [PohodaStock::kindOf($card) ?? $choice, true],
                PohodaStock::TYPE_SERVICE => ['goods', false],
                PohodaStock::TYPE_SET, PohodaStock::TYPE_PRODUCT => ['product', true],
                default => [null, false],
            };
            if ($itemType === null) {
                $p->count(self::STEP, $type === PohodaStock::TYPE_TEXT ? 'skipped_text' : ($type === PohodaStock::TYPE_PACKAGE ? 'skipped_packages' : 'skipped_unknown_type'));
                $packages += $type === PohodaStock::TYPE_PACKAGE ? 1 : 0;
                continue;
            }
            $sku = trim($card['IDS']);
            if ($sku === '' || mb_strlen($sku) > 50) {
                $p->count(self::STEP, 'skipped_invalid_code');
                continue;
            }
            $groups[$sku][] = ['card' => $card, 'warehouse' => $warehouse, 'item_type' => $itemType, 'stocked' => $stocked];
        }
        if ($packages > 0) {
            $p->warn(self::STEP, 'stock_packages_skipped', "{$packages} karet typu souprava se nepřevedlo, MyÚčto soupravy nevede. Založte je jako sady.");
        }
        return $groups;
    }

    /**
     * Sklady s aspoň jednou převáděnou kartou. Sklad, který v MyÚčtu už byl a má pohyby,
     * dostane karty, ale ne počáteční stav (`null`).
     *
     * @param array<string,list<array<string,mixed>>> $groups
     * @return array<string,?int> kód skladu => warehouses.id pro počáteční stav
     */
    private function importWarehouses(PohodaContext $ctx, PohodaStock $stock, array $groups): array
    {
        $p = $ctx->protocol;
        $used = [];
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                $used[$row['warehouse']['code']] = $row['warehouse'];
            }
        }
        $out = [];
        foreach ($used as $code => $w) {
            $mapped = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_STOCK_WAREHOUSE, $code);
            if ($mapped !== null) {
                $out[$code] = $mapped;
                $p->count(self::STEP, 'warehouses_existing');
                continue;
            }
            $name = mb_substr($w['name'] !== '' ? $w['name'] : $code, 0, 100);
            try {
                $result = $this->writer->warehouse($ctx->supplierId, ['code' => trim($code), 'name' => $name]);
            } catch (MigratedInventoryException $e) {
                $p->warn(self::STEP, 'stock_warehouse_conflict', "Sklad {$code}: " . $e->getMessage() . ' Jeho karty se převedou bez stavu.', ['warehouse' => $code]);
                $out[$code] = null;
                continue;
            }
            if (!$result['created'] && $this->warehouses->hasStockOrMovements($ctx->supplierId, $result['id'])) {
                $p->warn(self::STEP, 'stock_warehouse_not_empty', "Sklad {$code} už v MyÚčtu je a má pohyby. Karty se převedou, stav do něj ne.", ['warehouse' => $code]);
                $out[$code] = null;
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_STOCK_WAREHOUSE, $code, $result['id'], $ctx->runId);
            $p->count(self::STEP, $result['created'] ? 'warehouses' : 'warehouses_matched');
            $out[$code] = $result['id'];
        }
        return $out;
    }

    /**
     * Karta za každý kód. Údaje karty bere řádek s největším stavem; liší-li se jednotka,
     * stav řádků s jinou jednotkou se nepřevede.
     *
     * @param array<string,list<array<string,mixed>>> $groups
     * @return array<string,array{id:int,unit:string,canonical:string,vat:float,stocked:bool}> kód => karta v MyÚčtu
     */
    private function importCards(PohodaContext $ctx, PohodaStock $stock, array $groups): array
    {
        $p = $ctx->protocol;
        $items = [];
        $tracked = 0;
        // Sazba DPH platí podle data (kalendářně) - ke konci účetního období agendy.
        $date = $ctx->calendar->periodEnd($ctx->year());
        foreach ($groups as $sku => $rows) {
            usort($rows, static fn (array $a, array $b): int => ((float) $b['card']['StavZ'] <=> (float) $a['card']['StavZ'])
                ?: ((int) $a['card']['ID'] <=> (int) $b['card']['ID']));
            $main = $rows[0];
            $card = $main['card'];
            foreach ($rows as $row) {
                if ($row['item_type'] !== $main['item_type'] || $row['card']['Nazev'] !== $card['Nazev']) {
                    $p->count(self::STEP, 'merged_with_differences');
                    break;
                }
            }
            $vat = self::vatPercent((int) $card['RelDPHp'], (int) substr($date, 0, 4));
            $data = [
                'sku' => (string) $sku,
                'name' => mb_substr($card['Nazev'] !== '' ? $card['Nazev'] : (string) $sku, 0, 255),
                'item_type' => $main['item_type'],
                'unit' => mb_substr($card['MJ'] !== '' ? $card['MJ'] : 'ks', 0, 20),
                'tracking_mode' => 'none',
                'ean' => PohodaStock::ean($card['EAN']),
                'vat_rate_id' => $vat === null ? null : $this->vatRates->find($vat, $date),
                'sale_price_without_vat' => (float) $card['ProdejKc'] > 0 ? self::unitPrice((float) $card['ProdejKc']) : null,
                'min_qty' => (float) $card['MinLim'] > 0 ? number_format((float) $card['MinLim'], 3, '.', '') : null,
                'intrastat_cn8_code' => null,
                'intrastat_net_mass_kg' => null,
                'intrastat_supplementary_unit' => null,
                'intrastat_supplementary_unit_coefficient' => null,
                'is_active' => true,
                'note' => null,
                'weight_g' => (float) $card['Hmotnost'] > 0 ? (int) round((float) $card['Hmotnost'] * 1000) : null,
                'is_stocked' => $main['stocked'],
            ];
            if ($card['EAN'] !== '' && $data['ean'] === null) {
                $p->count(self::STEP, 'invalid_ean');
            }
            if ((int) $card['RelSKzVC'] !== 0) {
                $p->count(self::STEP, 'tracking_not_migrated');
                $tracked++;
            }
            $mapped = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_STOCK_ITEM, (string) $sku);
            if ($mapped !== null) {
                $items[$sku] = ['id' => $mapped, 'unit' => $data['unit'], 'canonical' => $card['ID'], 'vat' => $vat ?? 0.0, 'stocked' => $main['stocked']];
                $p->count(self::STEP, 'items_existing');
                continue;
            }
            try {
                $result = $this->writer->item($ctx->supplierId, $data);
            } catch (MigratedInventoryException $e) {
                $p->warn(self::STEP, 'stock_item_conflict', "Karta {$sku} už v MyÚčtu je s jinými údaji, nepřevzala se ani její stav.", ['sku' => (string) $sku]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_STOCK_ITEM, (string) $sku, $result['id'], $ctx->runId);
            $items[$sku] = ['id' => $result['id'], 'unit' => $data['unit'], 'canonical' => $card['ID'], 'vat' => $vat ?? 0.0, 'stocked' => $main['stocked']];
            if (!$result['created']) {
                $p->count(self::STEP, 'items_matched');
                continue;
            }
            $p->count(self::STEP, match (true) {
                !$main['stocked'] => 'services',
                $main['item_type'] === 'product' => 'products',
                $main['item_type'] === 'material' => 'materials',
                default => 'goods',
            });
            $this->importUnits($ctx, $result['id'], $card, $data['unit']);
            $this->importCategory($ctx, $stock, $result['id'], $card);
            $this->importVendor($ctx, $result['id'], $card);
        }
        if ($tracked > 0) {
            $p->warn(self::STEP, 'stock_tracking_not_migrated', "{$tracked} karet vede v POHODĚ šarže nebo výrobní čísla. Převedly se bez nich, stav je souhrnný.");
        }
        return $items;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $groups
     * @param array<string,array{id:int,unit:string,canonical:string,vat:float,stocked:bool}> $items
     * @param array<string,?int> $warehouseIds
     */
    private function importOpening(PohodaContext $ctx, PohodaStock $stock, array $groups, array $items, array $warehouseIds, string $date): void
    {
        $p = $ctx->protocol;
        $done = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_STOCK_OPENING);
        $byWarehouse = [];
        $negative = 0;
        foreach ($groups as $sku => $rows) {
            $item = $items[$sku] ?? null;
            foreach ($rows as $row) {
                $card = $row['card'];
                $qty = (float) $card['StavZ'];
                if ($item === null || !$item['stocked'] || $qty == 0.0) {
                    continue;
                }
                $code = $row['warehouse']['code'];
                $key = $code . '|' . $sku;
                if (isset($done[$key])) {
                    $p->count(self::STEP, 'opening_existing');
                    continue;
                }
                if (($warehouseIds[$code] ?? null) === null) {
                    $p->count(self::STEP, 'opening_skipped_warehouse');
                    continue;
                }
                if ($qty < 0) {
                    $p->count(self::STEP, 'opening_negative');
                    $negative++;
                    continue;
                }
                $qtyT = (int) round($qty * 1000);
                $valueC = $stock->valueCents($card);
                if (abs($qty * 1000 - $qtyT) > 0.001 || $valueC < 0 || ($card['MJ'] !== '' ? mb_substr($card['MJ'], 0, 20) : 'ks') !== $item['unit']) {
                    $p->warn(self::STEP, 'stock_opening_skipped', "Stav karty {$sku} ve skladu {$code} se nepřevedl: "
                        . ($valueC < 0 ? 'záporné ocenění' : (abs($qty * 1000 - $qtyT) > 0.001 ? 'množství má víc než tři desetinná místa' : 'karta má v tomto skladu jinou jednotku')) . '.',
                        ['sku' => (string) $sku, 'warehouse' => $code]);
                    continue;
                }
                $byWarehouse[$code][] = ['key' => $key, 'stock_item_id' => $item['id'], 'qty_t' => $qtyT, 'value_c' => $valueC];
            }
        }
        if ($negative > 0) {
            $p->warn(self::STEP, 'stock_negative', "{$negative} karet má v POHODĚ záporný stav. Stav se jim nepřevedl, doplňte ho inventurou.");
        }
        $value = 0;
        foreach ($byWarehouse as $code => $lines) {
            foreach (array_chunk($lines, self::BATCH) as $batch) {
                try {
                    $ids = $this->opening->post($ctx->supplierId, $ctx->userOrNull(), (int) $warehouseIds[$code], $date, self::DESCRIPTION, $batch);
                } catch (MigratedInventoryException|StockException $e) {
                    throw new PohodaException($e instanceof MigratedInventoryException ? $e->reason : 'stock_opening_failed',
                        "Počáteční stav skladu {$code} nejde zaúčtovat: " . $e->getMessage());
                }
                foreach ($batch as $i => $line) {
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_STOCK_OPENING, $line['key'], $ids[$i], $ctx->runId);
                    $value += $line['value_c'];
                }
                $p->count(self::STEP, 'opening_lines', count($batch));
                $p->count(self::STEP, 'opening_documents');
            }
        }
        if ($value > 0) {
            $p->count(self::STEP, 'opening_value', round($value / 100, 2));
        }
    }

    /**
     * Ceníky kromě základního (ten je prodejní cena karty) jako cenové hladiny s pevnou
     * cenou každé karty. Cena s DPH se přepočte na cenu bez DPH sazbou karty.
     *
     * @param array<string,list<array<string,mixed>>> $groups
     * @param array<string,array{id:int,unit:string,canonical:string,vat:float,stocked:bool}> $items
     */
    private function importPriceLists(PohodaContext $ctx, PohodaStock $stock, array $groups, array $items, string $date): void
    {
        $p = $ctx->protocol;
        $lists = [];
        foreach ($stock->priceLists as $order => $list) {
            if ($list['type'] === 0) {
                continue;
            }
            if ($list['currency'] === '' || strlen($list['currency']) !== 3) {
                $p->warn(self::STEP, 'stock_price_list_currency', "Ceník {$list['code']} má měnu, kterou převod nezná, nepřevedl se.", ['price_list' => $list['code']]);
                continue;
            }
            $code = mb_substr($list['code'] !== '' ? $list['code'] : 'POHODA-' . $list['id'], 0, 50);
            $levelId = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_STOCK_PRICE_LEVEL, $code);
            $existing = [];
            if ($levelId !== null) {
                foreach ($this->levels->rulesForLevel($ctx->supplierId, $levelId) as $rule) {
                    if ($rule['match_type'] === 'product') {
                        $existing[$rule['match_id'] . '|' . $rule['currency_code']] = true;
                    }
                }
                $p->count(self::STEP, 'price_levels_existing');
            } elseif ($this->levels->findByCode($ctx->supplierId, $code) !== null) {
                $p->warn(self::STEP, 'stock_price_level_conflict', "Cenová hladina {$code} už v MyÚčtu je, ceník z POHODY se do ní nepřevedl.", ['price_list' => $code]);
                continue;
            } else {
                $discount = $list['type'] === 2 && $list['discount'] > 0 && $list['discount'] <= 100 ? $list['discount'] : 0.0;
                $levelId = $this->levels->insert($ctx->supplierId, [
                    'code' => $code,
                    'name' => mb_substr($list['name'] !== '' ? $list['name'] : $code, 0, 100),
                    'default_discount_pct' => number_format($discount, 3, '.', ''),
                    'is_active' => true,
                    'display_order' => $order,
                ]);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_STOCK_PRICE_LEVEL, $code, $levelId, $ctx->runId);
                $p->count(self::STEP, 'price_levels');
            }
            $lists[$list['id']] = ['level' => $levelId, 'currency' => $list['currency'], 'vat_included' => $list['vat_included'], 'existing' => $existing];
        }
        if ($lists === []) {
            return;
        }
        // Cenu karty bere jen řádek, ze kterého vznikla karta - kopie karty v jiném skladu
        // může mít v POHODĚ cenu jinou a pravidlo hladiny je pro kartu jedno.
        $byCard = [];
        foreach ($items as $sku => $item) {
            $byCard[$item['canonical']] = $item;
        }
        foreach ($stock->priceRows(array_fill_keys(array_keys($lists), true)) as $row) {
            $item = $byCard[$row['card']] ?? null;
            if ($item === null || $row['price'] <= 0) {
                continue;
            }
            $list = &$lists[$row['list']];
            $key = $item['id'] . '|' . $list['currency'];
            if (isset($list['existing'][$key])) {
                unset($list);
                continue;
            }
            $price = $list['vat_included'] ? $row['price'] / (1 + $item['vat'] / 100) : $row['price'];
            $this->levels->insertRule($ctx->supplierId, $list['level'], [
                'match_type' => 'product', 'match_id' => $item['id'], 'rule_type' => 'fixed', 'discount_pct' => null,
                'fixed_price' => self::unitPrice($price), 'currency_code' => $list['currency'], 'priority' => 0,
            ]);
            $list['existing'][$key] = true;
            unset($list);
            $p->count(self::STEP, 'price_rules');
        }
    }

    /** Alternativní jednotky karty (`MJ2`, `MJ3`) jako balení; kód chybějící v číselníku balení se doplní. */
    private function importUnits(PohodaContext $ctx, int $itemId, array $card, string $baseUnit): void
    {
        $units = [];
        foreach ([['MJ2', 'MJ2Koef'], ['MJ3', 'MJ3Koef']] as [$unitKey, $ratioKey]) {
            $code = mb_substr(trim($card[$unitKey]), 0, 20);
            $ratio = PohodaStock::unitRatio($card[$ratioKey]);
            if ($code === '' || $ratio === null || mb_strtolower($code) === mb_strtolower($baseUnit)) {
                continue;
            }
            $units[mb_strtolower($code)] = ['unit_code' => $code, 'numerator' => $ratio[0], 'denominator' => $ratio[1]];
        }
        if ($units === []) {
            return;
        }
        $codebook = $this->packaging->byLowerCode($ctx->supplierId);
        foreach ($units as $lower => $unit) {
            if (!isset($codebook[$lower])) {
                $this->packaging->insert($ctx->supplierId, ['code' => $unit['unit_code'], 'name' => $unit['unit_code'], 'is_active' => true, 'display_order' => 0]);
            } elseif (!$codebook[$lower]['is_active']) {
                $ctx->protocol->count(self::STEP, 'units_skipped');
                unset($units[$lower]);
            }
        }
        try {
            $this->units->save($ctx->supplierId, $itemId, ['units' => array_values($units)]);
            $ctx->protocol->count(self::STEP, 'units', count($units));
        } catch (StockException) {
            $ctx->protocol->count(self::STEP, 'units_skipped', count($units));
        }
    }

    /** Pojmenovaná větev členění skladu jako kategorie (kořen skladu bez názvu se nepřevádí). */
    private function importCategory(PohodaContext $ctx, PohodaStock $stock, int $itemId, array $card): void
    {
        $path = $stock->categoryPath($card);
        if ($path === []) {
            return;
        }
        $parentId = null;
        $names = [];
        foreach ($path as $name) {
            $names[] = $name;
            $key = sha1(implode("\x1f", $names));
            $id = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_STOCK_CATEGORY, $key);
            if ($id === null) {
                $label = implode(' / ', $names);
                $code = mb_strlen($label) <= 50 ? $label : 'POHODA-' . substr($key, 0, 12);
                $found = $this->categories->findByCode($ctx->supplierId, $code);
                if ($found !== null) {
                    $id = (int) $found['id'];
                } else {
                    try {
                        $id = (int) $this->categoryTree->create($ctx->supplierId, [
                            'parent_id' => $parentId, 'code' => $code, 'name' => mb_substr($name, 0, 150), 'export_eshop' => false,
                        ])['id'];
                    } catch (EshopException) {
                        $ctx->protocol->count(self::STEP, 'categories_skipped');
                        return;
                    }
                    $ctx->protocol->count(self::STEP, 'categories');
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_STOCK_CATEGORY, $key, $id, $ctx->runId);
            }
            $parentId = $id;
        }
        $this->itemCategories->add($ctx->supplierId, $itemId, (int) $parentId, true, 0);
    }

    /** Dodavatel karty, je-li v převedeném adresáři veden jako dodavatel. */
    private function importVendor(PohodaContext $ctx, int $itemId, array $card): void
    {
        $clientId = $ctx->clientsByPohodaId[$card['RefAD']] ?? null;
        if ($clientId === null) {
            return;
        }
        if ($this->vendors->filterOwnedVendors($ctx->supplierId, [$clientId]) === []) {
            $ctx->protocol->count(self::STEP, 'vendors_not_supplier');
            return;
        }
        $this->vendors->add($ctx->supplierId, $itemId, [
            'client_id' => $clientId,
            'purchase_price' => (float) $card['NakupC'] > 0 ? self::unitPrice((float) $card['NakupC']) : null,
            'currency_code' => 'CZK',
            'is_preferred' => true,
        ]);
        $ctx->protocol->count(self::STEP, 'vendors');
    }

    /** Cena karty na šest míst, tvar „12.50" / „0.0108" jako při čtení z databáze. */
    private static function unitPrice(float $price): string
    {
        return (string) UnitPricePrecision::toDecimalString(number_format($price, UnitPricePrecision::DECIMALS, '.', ''));
    }

    /** Sazba DPH karty z pořadí sazby POHODY; snížená podle kalendářního roku konce období agendy. */
    private static function vatPercent(int $slot, int $year): ?float
    {
        return match ($slot) {
            0 => 0.0,
            1 => $year >= 2024 ? 12.0 : 15.0,
            2 => 21.0,
            3 => 10.0,
            default => null,
        };
    }
}
