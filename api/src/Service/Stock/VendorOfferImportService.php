<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Support\LocaleNumber;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;
use PDO;

/**
 * Import ceníku dodavatele z XLSX/CSV („u dodavatele", Epic SKLAD fáze 3).
 *
 * Identita řádku = dvojice **SKU karty × dodavatel** (UNIQUE `uq_siv_item_client`).
 * Import NIKDY nemaže a NIKDY nezakládá karty ani klienty — na oboje je vlastní
 * obrazovka a tiché zakládání kmenových dat z ceníku je nejrychlejší cesta
 * k duplicitnímu katalogu. Neznámé SKU nebo neznámý dodavatel = chyba řádku.
 *
 * Dodavatel se hledá primárně podle IČO (jednoznačné), jinak podle názvu firmy;
 * dvě firmy stejného jména bez IČO skončí jako chyba řádku, ne jako tip.
 *
 * Zapsané řádky dostanou `data_source='import'`, aby šlo poznat, co přišlo
 * z ceníku a co zadal člověk. Reuse hardened bázové třídy (zip-bomb guard,
 * formula-injection ochrana přes getValue(), all-or-nothing tx, CZ/EN aliasy).
 * Ceny a množství jdou jako string (money-safe, žádný float).
 */
final class VendorOfferImportService extends AbstractCodebookImportService
{
    private const AVAILABILITY_ALIASES = [
        'in stock' => 'in_stock', 'instock' => 'in_stock', 'skladem' => 'in_stock',
        'ano' => 'in_stock', 'k dispozici' => 'in_stock',
        'on order' => 'on_order', 'onorder' => 'on_order', 'na objednavku' => 'on_order',
        'objednavka' => 'on_order',
        'unavailable' => 'unavailable', 'nedostupne' => 'unavailable',
        'vyprodano' => 'unavailable', 'ne' => 'unavailable',
        'unknown' => 'unknown', 'nezname' => 'unknown', 'neznamo' => 'unknown',
    ];

    public function __construct(
        private readonly StockItemRepository $items,
        private readonly StockItemVendorRepository $vendors,
        private readonly PriceRecomputeDispatcher $dispatcher,
        private readonly Connection $db,
    ) {}

    public static function columns(): array
    {
        return [
            'sku'          => ['header' => 'sku', 'aliases' => ['kod', 'code', 'kód', 'katalog'],
                               'required' => 'ano', 'note' => 'SKU existující karty zboží; karty se nezakládají'],
            'vendor'       => ['header' => 'dodavatel', 'aliases' => ['vendor', 'supplier', 'firma'],
                               'required' => 'ano (nebo ico)', 'note' => 'název dodavatele — karta klienta s příznakem „je dodavatel"'],
            'ico'          => ['header' => 'ico', 'aliases' => ['ič', 'ičo', 'vendor_ico', 'company_id'],
                               'required' => 'ne', 'note' => 'IČO dodavatele; má přednost před názvem'],
            'vendor_sku'   => ['header' => 'kod_dodavatele', 'aliases' => ['vendor_sku', 'kod u dodavatele', 'dodavatelsky_kod'],
                               'required' => 'ne', 'note' => 'max 80'],
            'price'        => ['header' => 'nakupni_cena', 'aliases' => ['purchase_price', 'cena', 'price'],
                               'required' => 'ne', 'note' => 'nákupní cena bez DPH (CZ 1 234,50 i EN 1234.50)'],
            'currency'     => ['header' => 'mena', 'aliases' => ['currency', 'currency_code', 'měna'],
                               'required' => 'ne (default CZK)', 'note' => 'ISO 4217, 3 písmena'],
            'delivery'     => ['header' => 'dodaci_lhuta_dny', 'aliases' => ['delivery_days', 'dodaci_lhuta', 'delivery'],
                               'required' => 'ne', 'note' => 'dodací lhůta ve dnech'],
            'stock_qty'    => ['header' => 'skladem_u_dodavatele', 'aliases' => ['stock_qty', 'skladem', 'mnozstvi', 'qty'],
                               'required' => 'ne', 'note' => 'množství, které dodavatel hlásí skladem'],
            'availability' => ['header' => 'dostupnost', 'aliases' => ['availability', 'availability_state', 'stav'],
                               'required' => 'ne', 'note' => 'skladem / na objednávku / nedostupné / neznámé'],
            'min_order'    => ['header' => 'min_objednavka', 'aliases' => ['min_order_qty', 'minimalni_objednavka', 'moq'],
                               'required' => 'ne', 'note' => 'minimální objednací množství'],
            'package'      => ['header' => 'baleni', 'aliases' => ['package_qty', 'balení', 'package'],
                               'required' => 'ne', 'note' => 'velikost balení (zaokrouhluje se na ni nahoru)'],
            'valid_to'     => ['header' => 'cena_plati_do', 'aliases' => ['price_valid_to', 'platnost_ceny', 'valid_to'],
                               'required' => 'ne', 'note' => 'datum konce platnosti ceny (31.12.2026 i 2026-12-31)'],
            'preferred'    => ['header' => 'hlavni_dodavatel', 'aliases' => ['is_preferred', 'preferred', 'hlavni'],
                               'required' => 'ne', 'note' => '1/0, ano/ne; nejvýš jeden na kartu'],
            'active'       => ['header' => 'aktivni', 'aliases' => ['is_active', 'active', 'aktivní'],
                               'required' => 'ne (default 1)', 'note' => '1/0, ano/ne'],
            'note'         => ['header' => 'poznamka', 'aliases' => ['note', 'poznámka'],
                               'required' => 'ne', 'note' => 'max 255'],
        ];
    }

    protected function requiredHeaderKeys(): array
    {
        return ['sku'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $pdo = $this->db->pdo();

        if (!isset($map['vendor']) && !isset($map['ico'])) {
            return [
                'ok' => false, 'dry_run' => true, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1,
                'rows' => [['line' => 1, 'key' => '', 'status' => 'error',
                            'message' => 'Chybí sloupec „dodavatel" nebo „ico" — bez dodavatele nelze nabídku zařadit.']],
            ];
        }

        [$vendorsByIco, $vendorsByName, $ambiguousNames] = $this->vendorIndex($supplierId);

        $reportRows = [];
        $writers = [];
        $touchedItems = [];
        $seen = [];

        foreach ($rows as $line => $cols) {
            $sku = $this->col($cols, $map, 'sku');
            $vendorName = $this->col($cols, $map, 'vendor');
            $ico = $this->col($cols, $map, 'ico');
            $key = $sku . ' / ' . ($vendorName !== '' ? $vendorName : $ico);
            $row = ['line' => $line, 'key' => $key, 'status' => 'skip'];

            if ($sku === '') {
                $reportRows[$line] = $this->err($row, 'Chybí SKU.');
                continue;
            }
            if ($vendorName === '' && $ico === '') {
                $reportRows[$line] = $this->err($row, 'Chybí dodavatel (název nebo IČO).');
                continue;
            }

            $item = $this->items->findBySku($supplierId, $sku);
            if ($item === null) {
                $reportRows[$line] = $this->err($row, 'Karta zboží se SKU „' . $sku . '" neexistuje (založte ji nejdřív).');
                continue;
            }
            $itemId = (int) $item['id'];

            $clientId = null;
            if ($ico !== '') {
                $clientId = $vendorsByIco[self::normalize($ico)] ?? null;
                if ($clientId === null) {
                    $reportRows[$line] = $this->err($row, 'Dodavatel s IČO „' . $ico . '" neexistuje nebo není označen jako dodavatel.');
                    continue;
                }
            } else {
                $normName = self::normalize($vendorName);
                if (isset($ambiguousNames[$normName])) {
                    $reportRows[$line] = $this->err($row, 'Dodavatelů s názvem „' . $vendorName . '" je víc — doplňte sloupec „ico".');
                    continue;
                }
                $clientId = $vendorsByName[$normName] ?? null;
                if ($clientId === null) {
                    $reportRows[$line] = $this->err($row, 'Dodavatel „' . $vendorName . '" neexistuje nebo není označen jako dodavatel.');
                    continue;
                }
            }

            $seenKey = $itemId . ':' . $clientId;
            if (isset($seen[$seenKey])) {
                $reportRows[$line] = $this->err($row, 'Dvojice zboží + dodavatel je v souboru vícekrát.');
                continue;
            }
            $seen[$seenKey] = true;

            [$values, $error] = $this->parseRow($cols, $map);
            if ($error !== null) {
                $reportRows[$line] = $this->err($row, $error);
                continue;
            }

            $existing = $this->vendors->findByItemAndClient($supplierId, $itemId, $clientId);

            if ($existing === null) {
                $data = $values + [
                    'client_id'   => $clientId,
                    'data_source' => 'import',
                ];
                if (array_key_exists('stock_qty', $data) && $data['stock_qty'] !== null) {
                    $data['stock_qty_updated_at'] = date('Y-m-d H:i:s');
                }
                $preferred = (bool) ($data['is_preferred'] ?? false);
                $writers[] = function () use ($supplierId, $itemId, $data, $preferred): void {
                    $id = $this->vendors->add($supplierId, $itemId, $data);
                    if ($preferred) {
                        $this->vendors->clearPreferredForItem($supplierId, $itemId, $id);
                    }
                };
                $touchedItems[$itemId] = true;
                $row['status'] = 'create';
                $reportRows[$line] = $row;
                continue;
            }

            // Update — jen sloupce přítomné v souboru, které se liší.
            $changes = [];
            foreach ($values as $field => $new) {
                $old = $existing[$field] ?? null;
                if ($this->same($field, $old, $new)) {
                    continue;
                }
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
            if ($changes === []) {
                $row['status'] = 'skip';
                $reportRows[$line] = $row;
                continue;
            }

            $write = [];
            foreach ($changes as $field => $diff) {
                $write[$field] = $diff['to'];
            }
            $write['data_source'] = 'import';
            if (array_key_exists('stock_qty', $changes)) {
                $write['stock_qty_updated_at'] = date('Y-m-d H:i:s');
            }
            $offerId = (int) $existing['id'];
            $preferred = !empty($write['is_preferred']);
            $writers[] = function () use ($supplierId, $offerId, $itemId, $write, $preferred): void {
                $this->vendors->updateOffer($supplierId, $offerId, $write);
                if ($preferred) {
                    $this->vendors->clearPreferredForItem($supplierId, $itemId, $offerId);
                }
            };
            $touchedItems[$itemId] = true;
            $row['status'] = 'update';
            $row['changes'] = $changes;
            $reportRows[$line] = $row;
        }

        $result = $this->summarize($dryRun, $reportRows, $writers, $pdo);

        // Cenotvorba s pricing_base=manual bere nákupní cenu preferovaného
        // dodavatele — stejný háček jako ProductVendorAction::put(). Až PO
        // commitu: přepočet čte ceny znovu z DB.
        if (!$dryRun && $result['ok'] && $touchedItems !== []) {
            $this->dispatcher->recomputeItems($supplierId, array_map('intval', array_keys($touchedItems)));
        }

        return $result;
    }

    /**
     * Dodavatelé tenanta indexovaní podle IČO a názvu.
     *
     * @return array{0:array<string,int>, 1:array<string,int>, 2:array<string,true>}
     */
    private function vendorIndex(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name, ic FROM clients WHERE supplier_id = ? AND is_vendor = 1'
        );
        $stmt->execute([$supplierId]);

        $byIco = [];
        $byName = [];
        $ambiguous = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['id'];
            $ic = trim((string) ($r['ic'] ?? ''));
            if ($ic !== '') {
                $byIco[self::normalize($ic)] = $id;
            }
            $name = self::normalize((string) $r['company_name']);
            if ($name === '') {
                continue;
            }
            if (isset($byName[$name])) {
                $ambiguous[$name] = true;
                continue;
            }
            $byName[$name] = $id;
        }

        return [$byIco, $byName, $ambiguous];
    }

    /**
     * Hodnoty přítomných sloupců (bez identity). Prázdná buňka u přítomného
     * sloupce = vynulovat, aby ceník uměl hodnotu i odebrat.
     *
     * @param list<string> $cols
     * @param array<string,int> $map
     * @return array{0:array<string,mixed>, 1:?string}
     */
    private function parseRow(array $cols, array $map): array
    {
        $out = [];
        $has = static fn (string $k): bool => isset($map[$k]);

        if ($has('vendor_sku')) {
            $v = $this->col($cols, $map, 'vendor_sku');
            if (mb_strlen($v) > 80) {
                return [[], 'Kód u dodavatele je delší než 80 znaků.'];
            }
            $out['vendor_sku'] = $v === '' ? null : $v;
        }
        if ($has('price')) {
            [$v, $e] = $this->decCell($cols, $map, 'price', 10, 'nákupní cena');
            if ($e !== null) {
                return [[], $e];
            }
            $out['purchase_price'] = $v;
        }
        if ($has('currency')) {
            $v = strtoupper($this->col($cols, $map, 'currency'));
            if ($v !== '') {
                if (!preg_match('/^[A-Z]{3}$/', $v)) {
                    return [[], 'Neplatný kód měny „' . $v . '" (očekává se ISO 4217, např. CZK).'];
                }
                $out['currency_code'] = $v;
            }
        }
        if ($has('delivery')) {
            $raw = $this->col($cols, $map, 'delivery');
            if ($raw === '') {
                $out['delivery_days'] = null;
            } else {
                if (!preg_match('/^\d+$/', $raw) || (int) $raw > 65535) {
                    return [[], 'Neplatná dodací lhůta „' . $raw . '".'];
                }
                $out['delivery_days'] = (int) $raw;
            }
        }
        if ($has('stock_qty')) {
            [$v, $e] = $this->decCell($cols, $map, 'stock_qty', 11, 'množství skladem u dodavatele');
            if ($e !== null) {
                return [[], $e];
            }
            $out['stock_qty'] = $v;
        }
        if ($has('availability')) {
            $raw = $this->col($cols, $map, 'availability');
            if ($raw !== '') {
                $state = self::AVAILABILITY_ALIASES[self::normalize($raw)] ?? null;
                if ($state === null) {
                    return [[], 'Neplatná dostupnost „' . $raw . '" (skladem / na objednávku / nedostupné / neznámé).'];
                }
                $out['availability_state'] = $state;
            }
        }
        foreach (['min_order' => 'min_order_qty', 'package' => 'package_qty'] as $key => $field) {
            if (!$has($key)) {
                continue;
            }
            [$v, $e] = $this->decCell($cols, $map, $key, 11, $key === 'min_order' ? 'minimální objednávka' : 'balení');
            if ($e !== null) {
                return [[], $e];
            }
            if ($v !== null && (float) $v <= 0) {
                return [[], 'Hodnota „' . static::columns()[$key]['header'] . '" musí být větší než nula.'];
            }
            $out[$field] = $v;
        }
        if ($has('valid_to')) {
            $raw = $this->col($cols, $map, 'valid_to');
            if ($raw === '') {
                $out['price_valid_to'] = null;
            } else {
                $d = self::parseDate($raw);
                if ($d === null) {
                    return [[], 'Neplatné datum platnosti ceny „' . $raw . '".'];
                }
                $out['price_valid_to'] = $d;
            }
        }
        foreach (['preferred' => 'is_preferred', 'active' => 'is_active'] as $key => $field) {
            if (!$has($key)) {
                continue;
            }
            $raw = $this->col($cols, $map, $key);
            if ($raw === '') {
                continue;
            }
            $b = self::parseBool($raw);
            if ($b === null) {
                return [[], 'Neplatná hodnota „' . static::columns()[$key]['header'] . '": „' . $raw . '".'];
            }
            $out[$field] = $b;
        }
        if ($has('note')) {
            $v = $this->col($cols, $map, 'note');
            if (mb_strlen($v) > 255) {
                return [[], 'Poznámka je delší než 255 znaků.'];
            }
            $out['note'] = $v === '' ? null : $v;
        }

        return [$out, null];
    }

    /**
     * @param list<string> $cols
     * @param array<string,int> $map
     * @return array{0:?string, 1:?string}
     */
    private function decCell(array $cols, array $map, string $key, int $maxIntDigits, string $label): array
    {
        $raw = $this->col($cols, $map, $key);
        if ($raw === '') {
            return [null, null];
        }
        $s = LocaleNumber::parse($raw) ?? '';
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            return [null, 'Neplatná hodnota „' . $label . '": „' . $raw . '".'];
        }
        if (strlen(explode('.', $s, 2)[0]) > $maxIntDigits) {
            return [null, 'Hodnota „' . $label . '" je mimo rozsah: „' . $raw . '".'];
        }
        return [$s, null];
    }

    /** Porovnání staré a nové hodnoty bez floatování (DECIMAL jde z DB jako string). */
    private function same(string $field, mixed $old, mixed $new): bool
    {
        if (in_array($field, ['is_preferred', 'is_active'], true)) {
            return (bool) $old === (bool) $new;
        }
        if ($old === null || $new === null) {
            return $old === null && $new === null;
        }
        if (in_array($field, ['purchase_price', 'stock_qty', 'min_order_qty', 'package_qty'], true)) {
            return bccomp((string) $old, (string) $new, 6) === 0;
        }
        return (string) $old === (string) $new;
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
