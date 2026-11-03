<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemCustomerPriceRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * JEDINÉ místo, které odpovídá na otázku „jaká je teď platná cena téhle karty
 * pro tohle množství" (migrace 1328 — akční ceny).
 *
 * Skládá dvě hladiny:
 *  1. STANDARDNÍ cena = `stock_item_prices.computed_price` dané měny; když
 *     cenový řádek neexistuje, u CZK se použije zrcadlo
 *     `stock_items.sale_price_without_vat` (karty vedené jen ve Skladu).
 *  2. AKČNÍ cena = nejlepší použitelný řádek `stock_item_promo_prices`.
 *
 * ── Pravidla výběru akce ────────────────────────────────────────────────────
 * Kandidáti = aktivní akce téže měny, které k `$onDate` spadají do svého okna
 * (`valid_from`/`valid_to`, obojí volitelné). Překryv akcí je povolený; pořadí
 * přednosti je NEJNIŽŠÍ CENA, při shodě novější záznam (vyšší id) — zákazník
 * dostane nejlepší inzerovanou cenu a výsledek je deterministický.
 *
 * Množstevní strop akce (`qty_mode`):
 *   - 'stock'     … strop = ŽIVÝ součet `stock_levels` přes sklady firmy. Neodečítá
 *                   se; doskladnění akci znovu „nabije" (do vyprodání zásob).
 *   - 'limited'   … strop = `qty_limit` − dopočítané čerpání z vystavených faktur.
 *   - 'unlimited' … bez stropu.
 *
 * ── Vyčerpání uprostřed řádku ───────────────────────────────────────────────
 * Rozhoduje se PER ŘÁDEK a je to VŠE NEBO NIC: akce se použije jen tehdy, když
 * zbývající strop pokryje CELÉ požadované množství. Míchaná jednotková cena na
 * daňovém dokladu (část kusů akčně, část plnou cenou) by rozbila vztah
 * `cena × množství = základ` a nedala se vysvětlit na faktuře. Když nejlevnější
 * akce na celý řádek nestačí, zkusí se další v pořadí (dražší akce s volnějším
 * stropem je pořád lepší než plná cena); když nestačí žádná, vrátí resolver
 * standardní cenu, důvod `qty_exceeds_remaining` a `promo_qty_available`, aby
 * uživateli mohlo UI nabídnout rozdělení řádku.
 *
 * Akce, která NENÍ levnější než standardní cena, se ignoruje (`not_cheaper`) —
 * po snížení běžné ceny by jinak stará „akce" cenu zdražila.
 *
 * ── Individuální ceny zákazníků (issue #17) ─────────────────────────────────
 * S `$clientId` se nejdřív hledá zákaznická cena karty platná k `$onDate`
 * v téže měně (`stock_item_customer_prices`). Ta NAHRAZUJE standardní cenu jako
 * základ: pevná cena → `fixed_price`, sleva → standardní cena × (1 − pct/100)
 * na šest desetinných míst jako cena karty. Akční cena se pak použije jen tehdy,
 * když je levnější než tento základ (stejná `not_cheaper` logika) — zákazník
 * dostane lepší z obou. Bez klienta se chování nemění. Množstevní stropy akcí se posuzují v ZÁKLADNÍCH
 * jednotkách karty (`$qty` musí volající převést přes StockUnitConverter).
 *
 * ── Cenové hladiny odběratelů (migrace 1833) ────────────────────────────────
 * Karta bez platné zákaznické ceny dostane základ podle aktivní hladiny
 * odběratele ({@see PriceLevelResolver}): pravidlo produktu / kategorie /
 * výrobce, jinak výchozí sleva hladiny. Akce pak soupeří s tímto základem stejně
 * jako se zákaznickou cenou. Odběratel bez hladiny, neaktivní hladina nebo firma
 * bez skladu → výsledek beze změny.
 *
 * Vše přes bcmath/string (money-safe, žádný float).
 */
final class EffectivePriceResolver
{
    /** Měřítko jednotkové ceny (DECIMAL(16,6)) a množství (shodné s DECIMAL(14,3)). */
    private const PRICE_SCALE = UnitPricePrecision::DECIMALS;
    private const QTY_SCALE = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPriceRepository $prices,
        private readonly StockItemPromoPriceRepository $promos,
        private readonly StockItemCustomerPriceRepository $customerPrices,
        private readonly PriceLevelResolver $priceLevels,
    ) {}

    /**
     * Platná cena jedné karty.
     *
     * @return array<string,mixed> viz {@see resolveMany()}
     */
    public function resolve(
        int $supplierId,
        int $stockItemId,
        string $currency = 'CZK',
        string $qty = '1',
        ?string $onDate = null,
        ?int $clientId = null,
        ?int $priceLevelId = null,
    ): array {
        $all = $this->resolveMany($supplierId, [$stockItemId], $currency, $qty, $onDate, $clientId, $priceLevelId);
        return $all[$stockItemId] ?? $this->emptyResult($stockItemId, $currency, null);
    }

    /**
     * Standardní ceny karet v dané měně (bez zákaznické ceny a akce).
     *
     * @param list<int> $stockItemIds
     * @return array<int,string>
     */
    public function standardPrices(int $supplierId, array $stockItemIds, string $currency = 'CZK'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stockItemIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CZK';
        return $this->basePrices($supplierId, $ids, $currency);
    }

    /**
     * Cena za základní jednotku podle řádku zákaznické ceny: pevná cena, nebo
     * standardní cena ponížená o slevu (šest míst, half-up). Záporná sleva je přirážka
     * (cenové hladiny); výsledek je i tak vždy nezáporný, protože sleva je nejvýš 100 %.
     * Sleva bez standardní ceny nemá z čeho počítat → null.
     *
     * @param array<string,mixed> $row řádek stock_item_customer_prices
     */
    public static function customerBaseline(?string $standardPrice, array $row): ?string
    {
        if ((string) $row['price_type'] === 'fixed') {
            return $row['fixed_price'] !== null ? self::unitPrice(bcadd((string) $row['fixed_price'], '0', self::PRICE_SCALE)) : null;
        }
        if ($standardPrice === null || $row['discount_pct'] === null) {
            return null;
        }
        $factor = bcsub('100', (string) $row['discount_pct'], 6);
        $raw = bcdiv(bcmul($standardPrice, $factor, 10), '100', 10);
        return self::unitPrice(bcadd($raw, '0.0000005', self::PRICE_SCALE));
    }

    /**
     * Dávková varianta pro seznamy (list karet, našeptávač) — konstantní počet
     * dotazů bez ohledu na počet karet.
     *
     * Každý prvek výsledku:
     *   stock_item_id, currency_code,
     *   base_price           … standardní cena bez DPH (string|null),
     *   unit_price           … PLATNÁ cena bez DPH (string|null) = akční nebo standardní,
     *   promo_applied        … bool,
     *   promo_reason         … applied|none|qty_exceeds_remaining|exhausted|not_cheaper,
     *   promo_qty_available  … kolik kusů by akce ještě pokryla (string|null = neomezeno),
     *   promo                … null nebo {id,label,promo_price,valid_from,valid_to,
     *                          qty_mode,qty_limit,qty_remaining}
     *
     * Jen když se použila zákaznická cena (`$clientId` + platný řádek), přibudou:
     *   standard_price       … standardní cena z cenotvorby (string|null),
     *   price_source         … customer_fixed|customer_discount|promo,
     *   customer_price_id    … id použité zákaznické ceny,
     *   customer_price       … {id,price_type,fixed_price,discount_pct,valid_from,valid_to}
     * a `base_price` je pak zákaznická cena (základ, se kterým se akce porovnává).
     *
     * `$priceLevelId` = hladina zvolená na dokladu: nahradí hladinu odběratele a platí
     * i bez odběratele. Individuální cena zákazníka má dál přednost. Neaktivní nebo
     * cizí hladina se nepoužije (validaci s chybou dělá volající, např. nacenění dokladu).
     *
     * Jen když se použila cenová hladina (odběratele nebo dokladu), přibudou:
     *   standard_price       … standardní cena z cenotvorby (string|null),
     *   price_source         … price_level_fixed|price_level_discount|promo,
     *   price_level          … {id,code,name},
     *   discount_pct         … sleva hladiny (string, záporná = přirážka), u pevné ceny null
     * a `base_price` je cena podle hladiny.
     *
     * @param list<int> $stockItemIds
     * @return array<int,array<string,mixed>> stock_item_id => výsledek
     */
    public function resolveMany(
        int $supplierId,
        array $stockItemIds,
        string $currency = 'CZK',
        string|array $qty = '1',
        ?string $onDate = null,
        ?int $clientId = null,
        ?int $priceLevelId = null,
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $stockItemIds),
            static fn (int $i): bool => $i > 0,
        )));
        if ($ids === []) {
            return [];
        }
        $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CZK';
        $onDate = $onDate ?? date('Y-m-d');
        $qty = is_array($qty) ? array_map($this->normalizeQty(...), $qty) : $this->normalizeQty($qty);

        $base = $this->basePrices($supplierId, $ids, $currency);
        $standard = $base;
        $customer = [];
        $level = null;
        $levelApplied = [];
        if ($clientId !== null && $clientId > 0) {
            foreach ($this->customerPrices->activeFor($supplierId, $clientId, $currency, $ids, $onDate) as $itemId => $row) {
                $baseline = self::customerBaseline($standard[$itemId] ?? null, $row);
                if ($baseline === null) {
                    continue;
                }
                $base[$itemId] = $baseline;
                $customer[$itemId] = $row;
            }
        }
        // Hladina až po zákaznické ceně, jen pro karty bez ní. Hladina dokladu má
        // přednost před hladinou odběratele a platí i bez odběratele.
        if ($priceLevelId !== null && $priceLevelId > 0) {
            $level = $this->priceLevels->activeLevel($supplierId, $priceLevelId);
        } elseif ($clientId !== null && $clientId > 0) {
            $level = $this->priceLevels->levelForClient($supplierId, $clientId);
        }
        if ($level !== null) {
            $rest = array_values(array_filter($ids, static fn (int $i): bool => !isset($customer[$i])));
            foreach ($this->priceLevels->baselines($supplierId, $level, $rest, $currency, $standard) as $itemId => $applied) {
                $base[$itemId] = $applied['price'];
                $levelApplied[$itemId] = $applied;
            }
        }
        $candidates = $this->promos->activeForItems($supplierId, $ids, $currency, $onDate);
        $limited = [];
        foreach ($candidates as $rows) {
            foreach ($rows as $promo) {
                if ($promo['qty_mode'] === 'limited') {
                    $limited[] = $promo;
                }
            }
        }
        $consumed = $this->promos->consumedMany($supplierId, $limited);
        $consumedById = [];
        foreach ($limited as $index => $promo) {
            $consumedById[$promo['id']] = $consumed[$index];
        }
        foreach ($candidates as &$rows) {
            foreach ($rows as &$promo) {
                $promo['_consumed_qty'] = $consumedById[$promo['id']] ?? '0.000';
            }
            unset($promo);
        }
        unset($rows);

        // Živý stav skladu jen pro karty, které nějakou akci v režimu 'stock' mají.
        $needStock = [];
        foreach ($candidates as $itemId => $rows) {
            foreach ($rows as $r) {
                if ((string) $r['qty_mode'] === 'stock') {
                    $needStock[] = (int) $itemId;
                    break;
                }
            }
        }
        $stockQty = $needStock === [] ? [] : $this->promos->stockQty($supplierId, $needStock);

        $out = [];
        foreach ($ids as $itemId) {
            $result = $this->decide(
                $supplierId,
                $itemId,
                $currency,
                is_array($qty) ? ($qty[$itemId] ?? '1') : $qty,
                $base[$itemId] ?? null,
                $candidates[$itemId] ?? [],
                $stockQty[$itemId] ?? '0.000',
            );
            // Klíče zákaznické ceny jen tam, kde se zákaznická cena opravdu použila —
            // bez klienta (nebo bez jeho ceny) je výsledek bajt po bajtu dnešní.
            $row = $customer[$itemId] ?? null;
            if ($row !== null) {
                $result['standard_price'] = $standard[$itemId] ?? null;
                $result['price_source'] = $result['promo_applied']
                    ? 'promo'
                    : ((string) $row['price_type'] === 'fixed' ? 'customer_fixed' : 'customer_discount');
                $result['customer_price_id'] = (int) $row['id'];
                $result['customer_price'] = [
                    'id'           => (int) $row['id'],
                    'price_type'   => (string) $row['price_type'],
                    'fixed_price'  => $row['fixed_price'],
                    'discount_pct' => $row['discount_pct'],
                    'valid_from'   => $row['valid_from'],
                    'valid_to'     => $row['valid_to'],
                ];
            }
            // Klíče hladiny taky jen tam, kde hladina cenu opravdu určila.
            $applied = $levelApplied[$itemId] ?? null;
            if ($applied !== null && $level !== null) {
                $result['standard_price'] = $standard[$itemId] ?? null;
                $result['price_source'] = $result['promo_applied']
                    ? 'promo'
                    : ($applied['rule_type'] === 'fixed' ? 'price_level_fixed' : 'price_level_discount');
                $result['price_level'] = ['id' => $level['id'], 'code' => $level['code'], 'name' => $level['name']];
                $result['discount_pct'] = $applied['discount_pct'];
            }
            $out[$itemId] = $result;
        }
        return $out;
    }

    /**
     * Stav akcí karty pro editor: ke každému řádku dopočítá zbývající strop a
     * slovní stav. Sdílí tutéž logiku stropu jako {@see resolveMany()}, aby se
     * UI a rozhodování o ceně nemohly rozejít.
     *
     * state: scheduled (ještě nezačala) | expired (skončila) | disabled (vypnutá)
     *        | exhausted (strop vyčerpaný / není skladem) | active
     *
     * @param list<array<string,mixed>> $promos řádky z repository
     * @return list<array<string,mixed>> tytéž řádky + qty_remaining, state
     */
    public function annotate(int $supplierId, array $promos, ?string $onDate = null): array
    {
        $onDate = $onDate ?? date('Y-m-d');
        $stockIds = [];
        foreach ($promos as $p) {
            if ((string) $p['qty_mode'] === 'stock') {
                $stockIds[] = (int) $p['stock_item_id'];
            }
        }
        $stockQty = $stockIds === [] ? [] : $this->promos->stockQty($supplierId, $stockIds);

        $out = [];
        foreach ($promos as $p) {
            $remaining = $this->remainingQty($supplierId, $p, $stockQty[(int) $p['stock_item_id']] ?? '0.000');
            $p['qty_remaining'] = $remaining;
            $p['state'] = $this->stateOf($p, $remaining, $onDate);
            $out[] = $p;
        }
        return $out;
    }

    /** @param array<string,mixed> $promo */
    private function stateOf(array $promo, ?string $remaining, string $onDate): string
    {
        if (!$promo['is_active']) {
            return 'disabled';
        }
        if ($promo['valid_from'] !== null && (string) $promo['valid_from'] > $onDate) {
            return 'scheduled';
        }
        if ($promo['valid_to'] !== null && (string) $promo['valid_to'] < $onDate) {
            return 'expired';
        }
        if ($remaining !== null && bccomp($remaining, '0', self::QTY_SCALE) <= 0) {
            return 'exhausted';
        }
        return 'active';
    }

    /**
     * @param list<array<string,mixed>> $candidates seřazené dle přednosti (cena ASC, id DESC)
     * @return array<string,mixed>
     */
    private function decide(
        int $supplierId,
        int $itemId,
        string $currency,
        string $qty,
        ?string $basePrice,
        array $candidates,
        string $stockQty,
    ): array {
        $result = $this->emptyResult($itemId, $currency, $basePrice);
        if ($candidates === []) {
            return $result;
        }

        $bestPartial = null; // akce, která by platila, kdyby se objednalo míň kusů
        $sawExhausted = false;

        foreach ($candidates as $promo) {
            $promoPrice = (string) $promo['promo_price'];

            // Akce dražší (nebo stejná) než standardní cena nemá co zlepšit. Kandidáti
            // jsou řazení od nejlevnějšího, takže dál už to jen zdražuje → konec.
            if ($basePrice !== null && bccomp($promoPrice, $basePrice, self::PRICE_SCALE) >= 0) {
                if ($result['promo_reason'] === 'none') {
                    $result['promo_reason'] = 'not_cheaper';
                }
                break;
            }

            $remaining = $this->remainingQty($supplierId, $promo, $stockQty);

            if ($remaining !== null && bccomp($remaining, '0', self::QTY_SCALE) <= 0) {
                $sawExhausted = true;
                continue;
            }
            if ($remaining !== null && bccomp($remaining, $qty, self::QTY_SCALE) < 0) {
                // Nestačí na celý řádek → zkus další (dražší) akci s volnějším stropem.
                $bestPartial ??= ['promo' => $promo, 'remaining' => $remaining];
                continue;
            }

            $result['promo_applied'] = true;
            $result['promo_reason'] = 'applied';
            $result['unit_price'] = $promoPrice;
            $result['promo_qty_available'] = $remaining;
            $result['promo'] = $this->promoInfo($promo, $remaining);
            return $result;
        }

        if ($bestPartial !== null) {
            $result['promo_reason'] = 'qty_exceeds_remaining';
            $result['promo_qty_available'] = $bestPartial['remaining'];
            $result['promo'] = $this->promoInfo($bestPartial['promo'], $bestPartial['remaining']);
            return $result;
        }
        if ($sawExhausted && $result['promo_reason'] === 'none') {
            $result['promo_reason'] = 'exhausted';
            $result['promo_qty_available'] = '0.000';
        }
        return $result;
    }

    /**
     * Zbývající strop akce; null = bez omezení.
     *
     * @param array<string,mixed> $promo
     */
    private function remainingQty(int $supplierId, array $promo, string $stockQty): ?string
    {
        return match ((string) $promo['qty_mode']) {
            // Do vyprodání zásob — živý stav, nic se neodečítá.
            'stock' => bcadd($stockQty, '0', self::QTY_SCALE),
            // Pevný rozpočet mínus dopočítané čerpání z vystavených faktur.
            'limited' => $this->clampToZero(bcsub(
                (string) ($promo['qty_limit'] ?? '0'),
                $promo['_consumed_qty'] ?? $this->promos->consumedQty($supplierId, $promo),
                self::QTY_SCALE,
            )),
            default => null, // unlimited
        };
    }

    /**
     * @param array<string,mixed> $promo
     * @return array<string,mixed>
     */
    private function promoInfo(array $promo, ?string $remaining): array
    {
        return [
            'id'            => (int) $promo['id'],
            'label'         => $promo['label'],
            'promo_price'   => (string) $promo['promo_price'],
            'valid_from'    => $promo['valid_from'],
            'valid_to'      => $promo['valid_to'],
            'qty_mode'      => (string) $promo['qty_mode'],
            'qty_limit'     => $promo['qty_limit'],
            'qty_remaining' => $remaining,
        ];
    }

    /**
     * Standardní ceny karet: cenový řádek dané měny, u CZK s fallbackem na
     * zrcadlo ve skladové kartě.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function basePrices(int $supplierId, array $ids, string $currency): array
    {
        $base = $this->prices->computedPricesFor($supplierId, $ids, $currency);
        if ($currency !== 'CZK') {
            return $base;
        }
        $missing = array_values(array_filter($ids, static fn (int $i): bool => !isset($base[$i])));
        if ($missing === []) {
            return $base;
        }
        $in = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sale_price_without_vat FROM stock_items
              WHERE supplier_id = ? AND sale_price_without_vat IS NOT NULL AND id IN (' . $in . ')'
        );
        $stmt->execute(array_merge([$supplierId], $missing));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $base[(int) $r['id']] = self::unitPrice((string) $r['sale_price_without_vat']);
        }
        return $base;
    }

    /** @return array<string,mixed> */
    private function emptyResult(int $itemId, string $currency, ?string $basePrice): array
    {
        return [
            'stock_item_id'       => $itemId,
            'currency_code'       => $currency,
            'base_price'          => $basePrice,
            'unit_price'          => $basePrice,
            'promo_applied'       => false,
            'promo_reason'        => 'none',
            'promo_qty_available' => null,
            'promo'               => null,
        ];
    }

    private static function unitPrice(string $price): string
    {
        return (string) UnitPricePrecision::toDecimalString($price);
    }

    private function normalizeQty(string $qty): string
    {
        $q = str_replace(',', '.', trim($qty));
        if ($q === '' || !is_numeric($q) || bccomp($q, '0', self::QTY_SCALE) <= 0) {
            $q = '1';
        }
        return bcadd($q, '0', self::QTY_SCALE);
    }

    private function clampToZero(string $v): string
    {
        return bccomp($v, '0', self::QTY_SCALE) < 0 ? '0.000' : $v;
    }
}
