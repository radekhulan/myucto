<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;

/**
 * Import zboží (goods) z XLSX/CSV (Epic ESHOP F3). Identita řádku = sku per
 * firma. Update mění jen sloupce přítomné v souboru; import NIKDY nemaže.
 * Nové zboží = item_type 'goods'. Reuse hardened bázové třídy (zip-bomb guard,
 * formula-injection ochrana přes getValue(), all-or-nothing tx, CZ/EN aliasy).
 * Cena money-safe jako string (žádný float — vlastní parser).
 */
final class ProductImportService extends AbstractCodebookImportService
{
    public function __construct(
        private readonly StockItemRepository $items,
        private readonly ManufacturerRepository $manufacturers,
        private readonly Connection $db,
        private readonly \MyInvoice\Service\Eshop\Pricing\PriceWriteService $priceWriter,
        private readonly \MyInvoice\Repository\StockItemPriceRepository $prices,
    ) {}

    public static function columns(): array
    {
        return [
            'sku'          => ['header' => 'sku', 'aliases' => ['kod', 'code', 'kód', 'katalog'],
                               'required' => 'ano', 'note' => 'max 50; identita řádku'],
            'name'         => ['header' => 'nazev', 'aliases' => ['name', 'název'],
                               'required' => 'nové zboží: ano', 'note' => 'max 255'],
            'unit'         => ['header' => 'jednotka', 'aliases' => ['unit', 'mj', 'jedn'],
                               'required' => 'ne (default ks)', 'note' => 'max 20'],
            'ean'          => ['header' => 'ean', 'aliases' => ['barcode', 'carovy_kod'],
                               'required' => 'ne', 'note' => 'max 20'],
            'price'        => ['header' => 'cena', 'aliases' => ['sale_price', 'prodejni_cena', 'cena_bez_dph'],
                               'required' => 'ne', 'note' => 'prodejní cena bez DPH (CZ 1 234,50 i EN 1234.50)'],
            'manufacturer' => ['header' => 'vyrobce', 'aliases' => ['manufacturer', 'znacka', 'brand', 'výrobce'],
                               'required' => 'ne', 'note' => 'kód existujícího výrobce (nevytváří se)'],
            'is_stocked'   => ['header' => 'skladem', 'aliases' => ['is_stocked', 'stocked'],
                               'required' => 'ne (default 1)', 'note' => '1/0, ano/ne; 0 = prodej přes dodavatele'],
            'export_eshop' => ['header' => 'export_eshop', 'aliases' => ['eshop', 'export'],
                               'required' => 'ne (default 0)', 'note' => '1/0, ano/ne'],
            'weight_g'     => ['header' => 'hmotnost_g', 'aliases' => ['weight_g', 'hmotnost', 'weight'],
                               'required' => 'ne', 'note' => 'gramy (celé číslo)'],
            'warranty'     => ['header' => 'zaruka_mesice', 'aliases' => ['warranty_months', 'zaruka', 'warranty'],
                               'required' => 'ne', 'note' => 'záruka v měsících'],
            'delivery'     => ['header' => 'dodaci_lhuta_dny', 'aliases' => ['delivery_days', 'dodaci_lhuta', 'delivery'],
                               'required' => 'ne', 'note' => 'dodací lhůta ve dnech'],
        ];
    }

    protected function requiredHeaderKeys(): array
    {
        return ['sku'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $pdo = $this->db->pdo();

        // Mapa kódů výrobců (case-insensitive dle DB collation).
        $manuByCode = [];
        foreach ($this->manufacturers->listForSupplier($supplierId) as $m) {
            $manuByCode[mb_strtolower((string) $m['code'])] = (int) $m['id'];
        }

        $reportRows = [];
        $writers = [];
        $seen = [];

        foreach ($rows as $line => $cols) {
            $sku = $this->col($cols, $map, 'sku');
            $row = ['line' => $line, 'key' => $sku, 'status' => 'skip'];

            if ($sku === '') {
                $reportRows[$line] = $this->err($row, 'Chybí SKU.');
                continue;
            }
            if (mb_strlen($sku) > 50) {
                $reportRows[$line] = $this->err($row, 'SKU „' . $sku . '" je delší než 50 znaků.');
                continue;
            }
            if (preg_match('/[\x00-\x1F]/', $sku) === 1) {
                $reportRows[$line] = $this->err($row, 'SKU obsahuje neplatné řídicí znaky.');
                continue;
            }
            // Klíč case-insensitive (DB UNIQUE (supplier_id, sku) je CI collation) —
            // jinak by „ABC" a „abc" prošly seen guardem a skončily duplicate-key 500.
            $seenKey = mb_strtolower($sku);
            if (isset($seen[$seenKey])) {
                $reportRows[$line] = $this->err($row, 'SKU „' . $sku . '" je v souboru vícekrát.');
                continue;
            }
            $seen[$seenKey] = true;

            // Parse hodnot přítomných sloupců.
            $has = fn (string $k): bool => isset($map[$k]);
            $name = $this->col($cols, $map, 'name');
            $unit = $this->col($cols, $map, 'unit');
            $ean = $this->col($cols, $map, 'ean');

            // Délkové limity (jinak strict-mode MariaDB hodí exception → 500).
            if ($has('name') && mb_strlen($name) > 255) {
                $reportRows[$line] = $this->err($row, 'Název je delší než 255 znaků.');
                continue;
            }
            if ($has('unit') && mb_strlen($unit) > 20) {
                $reportRows[$line] = $this->err($row, 'Jednotka je delší než 20 znaků.');
                continue;
            }
            if ($has('ean') && mb_strlen($ean) > 20) {
                $reportRows[$line] = $this->err($row, 'EAN je delší než 20 znaků.');
                continue;
            }

            $priceRaw = $this->col($cols, $map, 'price');
            $price = null;
            if ($priceRaw !== '') {
                $price = $this->parseMoney($priceRaw);
                if ($price === null) {
                    $reportRows[$line] = $this->err($row, 'Neplatná cena „' . $priceRaw . '".');
                    continue;
                }
            }

            [$isStocked, $e1] = $this->boolCell($cols, $map, 'is_stocked');
            [$exportEshop, $e2] = $this->boolCell($cols, $map, 'export_eshop');
            $err = $e1 ?? $e2;
            if ($err !== null) {
                $reportRows[$line] = $this->err($row, $err);
                continue;
            }
            [$weight, $e3] = $this->intCell($cols, $map, 'weight_g');
            [$warranty, $e4] = $this->intCell($cols, $map, 'warranty');
            [$delivery, $e5] = $this->intCell($cols, $map, 'delivery');
            $err = $e3 ?? $e4 ?? $e5;
            if ($err !== null) {
                $reportRows[$line] = $this->err($row, $err);
                continue;
            }

            $manuCode = $this->col($cols, $map, 'manufacturer');
            $manuId = null;
            $manuProvided = $has('manufacturer') && $manuCode !== '';
            if ($manuProvided) {
                $key = mb_strtolower($manuCode);
                if (!isset($manuByCode[$key])) {
                    $reportRows[$line] = $this->err($row, 'Výrobce s kódem „' . $manuCode . '" neexistuje (založte jej nejdřív).');
                    continue;
                }
                $manuId = $manuByCode[$key];
            }

            $existing = $this->items->findBySku($supplierId, $sku);

            if ($existing === null) {
                if (!$has('name') || $name === '') {
                    $reportRows[$line] = $this->err($row, 'Název je povinný pro nové zboží „' . $sku . '".');
                    continue;
                }
                $base = [
                    'sku'                    => $sku,
                    'name'                   => $name,
                    'item_type'              => 'goods',
                    'unit'                   => ($has('unit') && $unit !== '') ? $unit : 'ks',
                    'ean'                    => ($has('ean') && $ean !== '') ? $ean : null,
                    'sale_price_without_vat' => $price,
                    'is_active'              => true,
                ];
                $eshop = [
                    'manufacturer_id' => $manuId,
                    'warranty_months' => $warranty,
                    'delivery_days'   => $delivery,
                    'export_eshop'    => $exportEshop ?? false,
                    'is_stocked'      => $isStocked ?? true,
                    'weight_g'        => $weight,
                    'pricing_base'    => 'weighted_avg',
                ];
                $writers[] = function () use ($supplierId, $base, $eshop): void {
                    $id = $this->items->insert($supplierId, $base);
                    $this->items->updateEshopFields($supplierId, $id, $eshop);
                    if ($base['sale_price_without_vat'] !== null) {
                        $this->writePrice($supplierId, $id, $base['sale_price_without_vat']);
                    }
                };
                $row['status'] = 'create';
                $reportRows[$line] = $row;
                continue;
            }

            // Update — jen přítomné sloupce, které se liší.
            $changes = [];
            $base = [
                'sku'                    => $sku,
                'name'                   => (string) $existing['name'],
                'item_type'              => (string) $existing['item_type'],
                'unit'                   => (string) $existing['unit'],
                'ean'                    => $existing['ean'],
                'vat_rate_id'            => $existing['vat_rate_id'],
                'sale_price_without_vat' => $existing['sale_price_without_vat'],
                'min_qty'                => $existing['min_qty'],
                'is_active'              => (bool) $existing['is_active'],
                'note'                   => $existing['note'],
            ];
            $eshop = [
                'manufacturer_id' => $existing['manufacturer_id'] ?? null,
                'warranty_months' => $existing['warranty_months'] ?? null,
                'delivery_days'   => $existing['delivery_days'] ?? null,
                'export_eshop'    => (bool) ($existing['export_eshop'] ?? false),
                'is_stocked'      => (bool) ($existing['is_stocked'] ?? true),
                'weight_g'        => $existing['weight_g'] ?? null,
                'pricing_base'    => (string) ($existing['pricing_base'] ?? 'weighted_avg'),
            ];

            $this->diffStr($changes, $base, 'name', $has('name') ? $name : null, true);
            $this->diffStr($changes, $base, 'unit', ($has('unit')) ? $unit : null, true);
            $this->diffNullableStr($changes, $base, 'ean', $has('ean') ? $ean : null);
            if ($has('price') && $price !== null) {
                $newPrice = $price;
                $rule = $this->prices->findByCurrency($supplierId, (int) $existing['id'], 'CZK');
                if ((string) ($base['sale_price_without_vat'] ?? '') !== (string) ($newPrice ?? '')
                    || $rule === null || $rule['price_mode'] !== 'fixed'
                    || $rule['fixed_price'] !== $newPrice || $rule['rounding'] !== 'none'
                    || $rule['is_manual_override']) {
                    $changes['price'] = ['from' => $base['sale_price_without_vat'], 'to' => $newPrice];
                    $base['sale_price_without_vat'] = $newPrice;
                }
            }
            if ($manuProvided && ($eshop['manufacturer_id'] !== $manuId)) {
                $changes['manufacturer'] = ['from' => $eshop['manufacturer_id'], 'to' => $manuId];
                $eshop['manufacturer_id'] = $manuId;
            }
            $this->diffBool($changes, $eshop, 'is_stocked', $isStocked);
            $this->diffBool($changes, $eshop, 'export_eshop', $exportEshop);
            $this->diffInt($changes, $eshop, 'weight_g', $has('weight_g') ? $weight : null, $has('weight_g'));
            $this->diffInt($changes, $eshop, 'warranty_months', $has('warranty') ? $warranty : null, $has('warranty'));
            $this->diffInt($changes, $eshop, 'delivery_days', $has('delivery') ? $delivery : null, $has('delivery'));

            if ($changes === []) {
                $row['status'] = 'skip';
                $reportRows[$line] = $row;
                continue;
            }

            $id = (int) $existing['id'];
            $writers[] = function () use ($supplierId, $id, $base, $eshop, $changes): void {
                $this->items->update($supplierId, $id, $base);
                $this->items->updateEshopFields($supplierId, $id, $eshop);
                if (isset($changes['price'])) {
                    $this->writePrice($supplierId, $id, $base['sale_price_without_vat']);
                }
            };
            $row['status'] = 'update';
            $row['changes'] = $changes;
            $reportRows[$line] = $row;
        }

        return $this->summarize($dryRun, $reportRows, $writers, $pdo);
    }

    /** Money parser (string in → normalizovaný decimal string, žádný float). */
    private function parseMoney(string $s): ?string
    {
        $s = trim(str_replace(["\u{00A0}", ' '], '', $s));
        if ($s === '') {
            return null;
        }
        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }
        // Nezáporná cena; integer část ≤ 10 číslic (DECIMAL(12,2)) — mimo rozsah
        // by jinak skončilo PDO exception → 500.
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            return null;
        }
        if (strlen(explode('.', $s, 2)[0]) > 10) {
            return null;
        }
        $rounded = \MyInvoice\Service\Eshop\Pricing\PriceRounding::apply($s, 'none');
        return strlen(explode('.', $rounded, 2)[0]) > 10 ? null : $rounded;
    }

    private function writePrice(int $supplierId, int $itemId, ?string $price): void
    {
        if ($price === null) {
            $this->priceWriter->delete($supplierId, $itemId, 'CZK');
            return;
        }
        $this->priceWriter->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => $price,
        ]]);
    }

    /**
     * @param list<string> $cols
     * @param array<string,int> $map
     * @return array{0:?bool, 1:?string} [hodnota|null pokud nezadáno, chyba|null]
     */
    private function boolCell(array $cols, array $map, string $key): array
    {
        if (!isset($map[$key])) {
            return [null, null];
        }
        $raw = $this->col($cols, $map, $key);
        if ($raw === '') {
            return [null, null];
        }
        $b = self::parseBool($raw);
        if ($b === null) {
            return [null, 'Neplatná hodnota „' . $key . '": „' . $raw . '".'];
        }
        return [$b, null];
    }

    /**
     * @param list<string> $cols
     * @param array<string,int> $map
     * @return array{0:?int, 1:?string}
     */
    private function intCell(array $cols, array $map, string $key): array
    {
        if (!isset($map[$key])) {
            return [null, null];
        }
        $raw = $this->col($cols, $map, $key);
        if ($raw === '') {
            return [null, null];
        }
        if (!preg_match('/^\d+$/', $raw)) {
            return [null, 'Neplatné celé číslo „' . $key . '": „' . $raw . '".'];
        }
        return [(int) $raw, null];
    }

    /** @param array<string,mixed> $data */
    private function diffStr(array &$changes, array &$data, string $field, ?string $new, bool $skipEmpty): void
    {
        if ($new === null) {
            return;
        }
        if ($skipEmpty && $new === '') {
            return;
        }
        if ((string) $data[$field] !== $new) {
            $changes[$field] = ['from' => $data[$field], 'to' => $new];
            $data[$field] = $new;
        }
    }

    /** @param array<string,mixed> $data */
    private function diffNullableStr(array &$changes, array &$data, string $field, ?string $new): void
    {
        if ($new === null) {
            return; // sloupec není v souboru
        }
        $normalized = $new === '' ? null : $new;
        if ((string) ($data[$field] ?? '') !== (string) ($normalized ?? '')) {
            $changes[$field] = ['from' => $data[$field], 'to' => $normalized];
            $data[$field] = $normalized;
        }
    }

    /** @param array<string,mixed> $data */
    private function diffBool(array &$changes, array &$data, string $field, ?bool $new): void
    {
        if ($new === null) {
            return;
        }
        if ((bool) $data[$field] !== $new) {
            $changes[$field] = ['from' => (bool) $data[$field], 'to' => $new];
            $data[$field] = $new;
        }
    }

    /** @param array<string,mixed> $data */
    private function diffInt(array &$changes, array &$data, string $field, ?int $new, bool $provided): void
    {
        if (!$provided || $new === null) {
            return;
        }
        if ((int) ($data[$field] ?? 0) !== $new) {
            $changes[$field] = ['from' => $data[$field], 'to' => $new];
            $data[$field] = $new;
        }
    }

    /** @param array<string,mixed> $row */
    private function err(array $row, string $message): array
    {
        $row['status'] = 'error';
        $row['message'] = $message;
        unset($row['changes']);
        return $row;
    }
}
