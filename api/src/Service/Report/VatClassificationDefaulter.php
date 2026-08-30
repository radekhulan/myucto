<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Auto-default VAT klasifikační kódy podle (direction, vat_rate, is_reverse_charge).
 *
 * **DB-driven** — defaultní mapování čte z `vat_classifications` table podle `vat_rate`.
 * Když se sazba změní (např. 21% → 20% k 1.1.2027), admin v Codebooks tab edituje
 * vat_classifications.vat_rate a defaulter automaticky chytne novou hodnotu.
 *
 * Pravidla per MF ČR (DPHDP3, aktuální seed):
 *   - Vystavená (sale, tuzemsko):    21% → 1,  12% → 2,  0% → bez defaultu
 *   - Vystavená (sale, reverse):     22 (EU služba §9/1; dodání zboží '20' se volí ručně)
 *   - Přijatá (purchase, tuzemsko):  21% → 40, 12% → 41, 0% → bez defaultu
 *   - Přijatá (purchase, reverse):   5  (tuzemský reverse charge)
 *
 * Algoritmus:
 *   1. Najdi v vat_classifications kód s direction matchne + vat_rate (tolerance 0.5%)
 *      + is_reverse_charge match + archived=0
 *   2. Vrátí code s nejmenším display_order (= "primární default" pro tu sazbu)
 *   3. Fallback hard-coded mapping pokud DB nemá kód (např. nový tenant before seed)
 */
final class VatClassificationDefaulter
{
    // Fallback mapy pro tuzemské sazby ({@see fallbackSaleTuzemsko()}, {@see
    // fallbackPurchaseTuzemsko()}) se klíčují AKTUÁLNÍ sazbou roku z {@see
    // TaxConstantsRepository::forYear()}, ne zamrzlým literálem — matchne seed
    // v migraci 0037, ale po legislativní změně sazby se chytí nové hodnoty.
    // '0.0' => '42' u přijaté je jen poslední záchrana byRateFallback() pro NEZNÁMOU
    // kladnou sazbu (např. cizí 19 %) — nulová sazba se sem už nedostane, viz defaultForPurchase().
    // RC + EU odběratel: statistický default SLUŽBA '22' (§ 9 odst. 1, ř.21) — použije se
    // až když ani jednotky položek, ani CZ-NACE dodavatele nedají signál „zboží" (viz
    // classifyEuReverseChargeSale). Dodání zboží do JČS = '20' (ř.20). Sjednoceno s
    // InvoiceRepository::defaultSaleClassificationCode (sdílený classifyUnitsGoodsVsServices).
    private const FALLBACK_SALE_REVERSE_EU       = '22';
    private const FALLBACK_SALE_REVERSE_DOMESTIC = '25s';  // RC + tuzemský odběratel = §92a dodavatel → ř.25 (pln_rez_pren), KH A.1
    private const FALLBACK_PURCHASE_REVERSE  = '5';

    /**
     * Signál ZBOŽÍ vs SLUŽBA pro RC prodej do EU z měrné jednotky položky.
     * `UNIT_SERVICE` = časové/výkonové jednotky (silně služba, § 9 odst. 1, ř.21).
     * `UNIT_GOODS`   = fyzikální míra / balení (silně dodání zboží, ř.20).
     * 'ks'/'kus' (defaultní hodnota sloupce) a neznámé jsou NEUTRÁLNÍ — nenesou signál,
     * rozhodne až CZ-NACE nebo statistický default.
     */
    private const UNIT_SERVICE = [
        'h','hod','hodina','hodiny','hodin','den','dny','dní','dni',
        'měsíc','mesic','měsíce','mesice','měs','mes','rok','roky','roků','roku',
        'min','minuta','minut',
    ];
    private const UNIT_GOODS = [
        'kg','g','dkg','mg','t','q','l','ml','dl','hl','cl',
        'm','cm','mm','km','bm','m2','m3','ha','ar',
        'pár','par','bal','balení','baleni','sada','paleta','karton',
    ];

    /** @var array<string, string>|null In-memory cache code→cache key (per request) */
    private ?array $cache = null;

    /** @var array<int, bool>|null cache supplier_id → obchoduje se zbožím (dle CZ-NACE) */
    private ?array $naceGoodsCache = null;

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * {@see FALLBACK_SALE_TUZEMSKO} klíčovaná AKTUÁLNÍ sazbou roku (rok z `$taxDate`,
     * jinak dnešní), ne zamrzlým literálem — po legislativní změně sazby se fallback
     * chytí nové hodnoty ze stejné roční sady jako zbytek DPH výpočtu.
     *
     * @return array<string,string>
     */
    private function fallbackSaleTuzemsko(?string $taxDate): array
    {
        $c = $this->taxConstants->forYear($this->fallbackYear($taxDate));

        return [
            $this->rateKey((float) $c['vat_rate_standard']) => '1',
            $this->rateKey((float) $c['vat_rate_reduced']) => '2',
        ];
    }

    /** @return array<string,string> */
    private function fallbackPurchaseTuzemsko(?string $taxDate): array
    {
        $c = $this->taxConstants->forYear($this->fallbackYear($taxDate));

        return [
            $this->rateKey((float) $c['vat_rate_standard']) => '40',
            $this->rateKey((float) $c['vat_rate_reduced']) => '41',
            '0.0' => '42',
        ];
    }

    private function fallbackYear(?string $taxDate): int
    {
        return $taxDate !== null ? (int) substr($taxDate, 0, 4) : (int) date('Y');
    }

    private function rateKey(float $rate): string
    {
        return number_format($rate, 1, '.', '');
    }

    /**
     * Default pro vystavenou fakturu (revenue side).
     *
     * `$taxDate` (volitelné) — když je zadán, dohledáme vat_rate platnou k tomu datu
     * (vat_rates.valid_from <= tax_date AND valid_to IS NULL OR >= tax_date). To řeší
     * scénář změny sazby od 1.1.2027 — faktury z 2026 chytnou starou klasifikaci,
     * faktury od 2027 novou (i když rate_percent se mění).
     *
     * `$supplierId` (volitelné, default 0 = jen globální) — multi-tenant scope.
     * Volej s tenant ID aby tenant viděl své custom kódy ELL.GLOBÁLNÍ; ne kódy jiných tenantů.
     */
    public function defaultForSale(float $vatRate, bool $reverseCharge = false, ?string $taxDate = null, int $supplierId = 0, bool $customerEuForeign = false, array $units = []): ?string
    {
        if ($reverseCharge) {
            $db = $this->lookup('sale', $vatRate, true, $taxDate, $supplierId);
            if ($db !== null) return $db;
            // Tuzemský §92a dodavatel → '25s' (ř.25). Zahraniční EU odběratel → rozliš
            // dodání zboží ('20', ř.20) od služby ('22', ř.21) podle reálného signálu.
            return $customerEuForeign
                ? $this->classifyEuReverseChargeSale($units, $supplierId)
                : self::FALLBACK_SALE_REVERSE_DOMESTIC;
        }
        if ($vatRate <= 0.0) {
            return null;
        }
        return $this->lookup('sale', $vatRate, false, $taxDate, $supplierId)
            ?? $this->byRateFallback($vatRate, $this->fallbackSaleTuzemsko($taxDate));
    }

    /**
     * RC prodej do EU: rozliš dodání ZBOŽÍ do JČS ('20', ř.20 dod_zb) od poskytnutí
     * SLUŽBY dle § 9 odst. 1 ('22', ř.21) podle nejlepšího dostupného signálu.
     * Nahrazuje slepý default z commitu 88794465 (audit follow-up):
     *
     *   1. **Měrné jednotky položek** (nejpřesnější) — časové (h/den/měsíc) → služba;
     *      fyzikální míra / balení (kg/l/m/m²/paleta) → zboží; 'ks'/neznámé = neutrální.
     *   2. **CZ-NACE dodavatele** (hrubší default) — oddíly 01–33 (zemědělství/těžba/
     *      výroba) a 45–47 (velko/maloobchod) obchodují se zbožím → '20'.
     *   3. **Statistický default '22'** — u typického uživatele (OSVČ / IT / poradenství)
     *      jsou přeshraniční služby častější než dodání zboží.
     *
     * @param list<string> $units měrné jednotky položek faktury
     */
    private function classifyEuReverseChargeSale(array $units, int $supplierId): string
    {
        $signal = self::classifyUnitsGoodsVsServices($units);
        if ($signal !== null) {
            return $signal === 'goods' ? '20' : self::FALLBACK_SALE_REVERSE_EU;
        }
        if ($supplierId > 0 && $this->supplierDealsInGoods($supplierId)) {
            return '20';
        }
        return self::FALLBACK_SALE_REVERSE_EU;
    }

    /**
     * Default pro přijatou fakturu (cost side).
     *
     * **Nulová sazba se tu NEROZHODUJE.** U 0 % závisí správný kód na zemi dodavatele
     * (tuzemsko osvobozené / neplátce → žádný řádek přiznání; EU služba § 9/1 → '24e',
     * ř.5; 3. země → '24', ř.12; tuzemský § 92a → '5') a tuhle znalost má jediný SSOT
     * {@see PurchaseInvoiceRepository::defaultClassificationCode()}, který doplní kód
     * při ukládání řádků. Vracíme `null`, ať ho nepřebijeme.
     *
     * Historicky se tady na 0 % sahalo do DB lookupu — jediný nereverse-charge purchase
     * kód se sazbou 0 % je '30' (pořízení zboží prostřední osobou při třístranném
     * obchodu, § 17), takže KAŽDÝ nulový doklad dostal '30' a mířil na ř. 30 přiznání
     * (issue #30). Symetricky k {@see defaultForSale()}, který u 0 % vrací null taky.
     */
    public function defaultForPurchase(float $vatRate, bool $reverseCharge = false, ?string $taxDate = null, int $supplierId = 0): ?string
    {
        if ($vatRate <= 0.0) {
            return null;
        }
        return $this->lookup('purchase', $vatRate, $reverseCharge, $taxDate, $supplierId)
            ?? ($reverseCharge ? self::FALLBACK_PURCHASE_REVERSE : $this->byRateFallback($vatRate, $this->fallbackPurchaseTuzemsko($taxDate)));
    }

    /**
     * DB lookup — najdi VAT klasifikační kód podle (direction, rate, reverse, taxDate).
     *
     * Algoritmus:
     *  1. Najdi v vat_rates ID sazby platné k taxDate s rate_percent matchnutou
     *     (vat_rates.valid_from <= taxDate AND (valid_to IS NULL OR >= taxDate)).
     *  2. V vat_classifications najdi kód s match (direction, vat_rate ≈, reverse).
     *  3. Pokud sazba není v `vat_rates` registrovaná, fallback na samotnou hodnotu.
     */
    private function lookup(string $direction, float $vatRate, bool $reverseCharge, ?string $taxDate = null, int $supplierId = 0): ?string
    {
        $key = "{$direction}:{$vatRate}:" . ($reverseCharge ? '1' : '0') . ':' . ($taxDate ?? '') . ':' . $supplierId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key] ?: null;
        }

        // Pokud je zadán taxDate, ověříme že sazba je v té době platná. Pokud admin
        // nastavil valid_to=2026-12-31 na CZ-21 a vytvořil CZ-20 s valid_from=2027,
        // pro fakturu z 2027 najdeme nový rate. Tady jen ověření existence;
        // mapování na vat_classifications stejně podle rate_percent.
        if ($taxDate !== null) {
            $rateCheck = $this->db->pdo()->prepare(
                "SELECT 1 FROM vat_rates
                  WHERE ABS(rate_percent - ?) < 0.5
                    AND valid_from <= ?
                    AND (valid_to IS NULL OR valid_to >= ?)
                  LIMIT 1"
            );
            $rateCheck->execute([$vatRate, $taxDate, $taxDate]);
            if ($rateCheck->fetchColumn() === false) {
                // Rate v té době neexistuje (možná uživatel uloží fakturu s sazbou
                // 21% mimo platný rozsah) — pokračujeme do lookup stejně, ale
                // logujeme by mohlo dávat smysl. Pro teď silent fallback.
            }
        }

        // **Multi-tenant scope** — vrátí globální seed (supplier_id IS NULL) NEBO
        // tenant-specific kódy. NIKDY kódy jiných tenantů. Pokud má tenant custom
        // kód se stejnou sazbou jako globální, preferujeme tenant-specific
        // (supplier_id NOT NULL = 1, ORDER DESC).
        $stmt = $this->db->pdo()->prepare(
            "SELECT code FROM vat_classifications
              WHERE archived = 0
                AND (direction = ? OR direction = 'both')
                AND ABS(COALESCE(vat_rate, -999) - ?) < 0.5
                AND is_reverse_charge = ?
                AND (supplier_id IS NULL OR supplier_id = ?)
           ORDER BY (supplier_id IS NOT NULL) DESC, display_order ASC
              LIMIT 1"
        );
        $stmt->execute([$direction, $vatRate, $reverseCharge ? 1 : 0, $supplierId]);
        $code = $stmt->fetchColumn();

        $result = $code !== false ? (string) $code : null;
        $this->cache[$key] = $result ?? '';
        return $result;
    }

    private function byRateFallback(float $vatRate, array $map): string
    {
        foreach ($map as $rateStr => $code) {
            if (abs($vatRate - (float) $rateStr) < 0.5) return $code;
        }
        return $map['0.0'] ?? '3';
    }

    /**
     * Rozliš „zboží" od „služby" z měrných jednotek položek (čistá funkce, bez DB).
     * Sdíleno mezi VatClassificationDefaulter a InvoiceRepository::defaultSaleClassificationCode.
     *
     * @param list<string> $units
     * @return 'goods'|'services'|null null = žádný jednoznačný signál (jen 'ks'/neznámé)
     */
    public static function classifyUnitsGoodsVsServices(array $units): ?string
    {
        $goods = 0;
        $service = 0;
        foreach ($units as $u) {
            $u = mb_strtolower(trim((string) $u));
            if ($u === '') continue;
            if (in_array($u, self::UNIT_SERVICE, true)) { $service++; continue; }
            if (in_array($u, self::UNIT_GOODS, true))   { $goods++;   continue; }
            // 'ks'/'kus'/neznámé = neutrální (defaultní hodnota sloupce, nenese signál)
        }
        if ($goods === 0 && $service === 0) return null;
        if ($goods > 0 && $service === 0)  return 'goods';
        if ($service > 0 && $goods === 0)  return 'services';
        // Smíšené — rozhodne převaha; při remíze opatrněji služba (ř.21).
        return $goods > $service ? 'goods' : 'services';
    }

    /**
     * Obchoduje dodavatel podle převažující CZ-NACE se zbožím? Oddíly (první 2 číslice)
     * 01–33 (sekce A/B/C — zemědělství, těžba, zpracovatelský průmysl) nebo 45–47
     * (sekce G — velko/maloobchod). Ostatní (služby, stavebnictví, doprava, IT) → false.
     */
    public static function naceIsGoods(string $nace): bool
    {
        $digits = preg_replace('/\D/', '', $nace) ?? '';
        if (strlen($digits) < 2) return false;
        $division = (int) substr($digits, 0, 2);
        return ($division >= 1 && $division <= 33) || ($division >= 45 && $division <= 47);
    }

    /** Obchoduje dodavatel převážně se zbožím (dle CZ-NACE)? Cache per request. */
    private function supplierDealsInGoods(int $supplierId): bool
    {
        if ($supplierId <= 0) return false;
        if (isset($this->naceGoodsCache[$supplierId])) return $this->naceGoodsCache[$supplierId];
        $stmt = $this->db->pdo()->prepare('SELECT cz_nace_code FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        $nace = (string) ($stmt->fetchColumn() ?: '');
        return $this->naceGoodsCache[$supplierId] = self::naceIsGoods($nace);
    }

    /**
     * Aplikuje default na header faktury (pokud chybí).
     * Většinou se aplikuje při uložení (CreateAction / UpdateAction).
     *
     * Pro header zvolíme dominantní sazbu z items (max(total) za sazbu).
     *
     * @param list<array{vat_rate?:float, total_with_vat?:float, unit?:string}> $items
     */
    public function suggestHeaderForInvoice(array $items, bool $reverseCharge, string $direction, ?string $taxDate = null, int $supplierId = 0, bool $customerEuForeign = false): ?string
    {
        // Najdi dominantní sazbu (s největší totální částkou) a sesbírej měrné jednotky
        // (signál zboží/služba pro RC prodej do EU).
        $byRate = [];
        $units = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate'] ?? 0);
            $total = abs((float) ($it['total_with_vat'] ?? 0));
            if (!isset($byRate[(string) $rate])) $byRate[(string) $rate] = 0.0;
            $byRate[(string) $rate] += $total;
            if (isset($it['unit']) && (string) $it['unit'] !== '') $units[] = (string) $it['unit'];
        }
        $dominantRate = (float) $this->taxConstants->forYear($this->fallbackYear($taxDate))['vat_rate_standard'];
        if (!empty($byRate)) {
            arsort($byRate);
            $dominantRate = (float) array_key_first($byRate);
        }
        return $direction === 'sale'
            ? $this->defaultForSale($dominantRate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units)
            : $this->defaultForPurchase($dominantRate, $reverseCharge, $taxDate, $supplierId);
    }
}
