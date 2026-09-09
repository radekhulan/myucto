<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * § 36a ZDPH + § 23 odst. 7 ZDP — spojené osoby a ceny obvyklé.
 *
 * Obojí měla matice jako CHYBÍ s vysokým rizikem a platilo to doslova: pojem „spojená
 * osoba" neměl v repozitáři jediný výskyt. Je to přitom typický terč doměrku — správce
 * daně ho hledá přednostně, protože je snadno prokazatelný z rejstříků a účetnictví.
 *
 * ── Co systém umí a co ne ───────────────────────────────────────────────────
 * Spojení osob je právní a faktický vztah, který z faktur vyčíst nelze; označí ho
 * uživatel ({@see \MyInvoice\Repository\ClientRepository} příznak `related_party`).
 * Ani „cenu obvyklou" systém obecně nezná — to je tržní veličina.
 *
 * Zná ale jednu její silnou aproximaci, a to z vlastních dat: JAK DRAHO prodal TOTÉŽ
 * nespojeným osobám. {@see priceDeviations()} porovná jednotkovou cenu položky fakturované
 * spojené osobě s MEDIÁNEM jednotkových cen téže položky fakturovaných nespojeným.
 * Medián, ne průměr — jediná odlehlá faktura (sleva, likvidace zásob) by průměr utáhla
 * a srovnání by ukázalo odchylku tam, kde žádná není.
 *
 * Kde srovnání není (položka se nespojeným nikdy neprodávala), systém odchylku NETVRDÍ
 * a transakci jen vypíše — doložit cenu obvyklou je pak na účetní. Dopočítat ji odhadem
 * by znamenalo podložit daňové tvrzení číslem, které nemá oporu v datech.
 *
 * Nárok na odpočet u protistrany, na kterém § 36a závisí, systém nevidí vůbec — proto
 * je výstup upozornění, ne automatická úprava základu daně.
 *
 * Read-only vůči účetnictví: nic neúčtuje.
 */
final class RelatedPartyService
{
    /**
     * Odchylka, od které má smysl na cenu upozornit. Pod ní jde spíš o běžné cenové
     * rozpětí (množstevní sleva, jiné období) než o převod zisku; hlásit každé procento
     * by kontrolu utopilo v šumu a účetní by ji přestala číst.
     */
    public const DEVIATION_THRESHOLD_PCT = 20.0;

    /** Kolik srovnatelných faktur nespojeným osobám musí existovat, aby medián něco znamenal. */
    public const MIN_COMPARABLE_SAMPLES = 2;

    /**
     * Jednotky, u kterých `unit_price_without_vat` opravdu JE jednotková cena.
     *
     * Bez tohohle filtru vracela kontrola 71 nálezů a všech 71 bylo planých. Porovnávaly
     * se totiž řádky typu „Výkaz víceprací — 2026-05" s množstvím 1 ks a cenou rovnou
     * CELÉMU měsíčnímu souhrnu daného zákazníka, a měsíční paušály sjednané per zákazník.
     * Medián takové množiny nemá věcný obsah — mezi dvěma po sobě jdoucími měsíci skočil
     * o 130 % (7 500 → 17 250 Kč). To není cenová odchylka, to je jiný rozsah práce.
     */
    private const COMPARABLE_UNITS = ['ks', 'kus', 'kusy', 'h', 'hod', 'hodina', 'hodin', 'kg', 'm', 'm2', 'm3', 'l'];

    /**
     * Minimální množství na řádku. Řádek na 1 kus bývá souhrn nebo paušál („práce za
     * květen"), ne opakovaně prodávaná položka se srovnatelnou cenou.
     */
    private const MIN_COMPARABLE_QUANTITY = 1.0;

    /**
     * Typy dokladů, které jsou zdanitelným plněním.
     *
     * Zálohová faktura (proforma) plněním NENÍ a má navazující ostrý doklad — bez filtru
     * se plnění započetlo dvakrát. V produkci šlo o desítky proforem v řádu milionů,
     * všechny s navazující fakturou. Týž výčet používají `VatRegistrationService`
     * i `VatPeriodEntitlementService`; tahle služba jako jediná filtr neměla.
     */
    private const ISSUED_TAXABLE_TYPES = "'invoice', 'credit_note', 'tax_document'";

    /** Přijatá strana: záloha není plnění, daňový doklad k ní ano. */
    private const RECEIVED_TAXABLE_KINDS = "'invoice', 'receipt', 'credit_note', 'tax_document'";

    public function __construct(private readonly Connection $db) {}

    /**
     * Zdanitelná plnění se spojenými osobami za období — obě strany.
     *
     * @return list<array{
     *   direction:string, doc_type:string, doc_id:int, doc_no:string, partner_name:string,
     *   related_party_type:?string, tax_date:string, amount:float
     * }>
     */
    public function transactions(int $supplierId, string $from, string $to): array
    {
        $sql =
            "SELECT 'issued' AS direction, 'invoice' AS doc_type, i.id AS doc_id,
                    COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                    c.company_name AS partner_name, c.related_party_type,
                    i.effective_tax_date AS tax_date,
                    ROUND(i.total_without_vat, 2) AS amount
               FROM invoices i
               JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ? AND c.related_party = 1
                AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type IN (" . self::ISSUED_TAXABLE_TYPES . ")
                AND i.effective_tax_date BETWEEN ? AND ?
              UNION ALL
             SELECT 'received', 'purchase_invoice', p.id,
                    COALESCE(NULLIF(p.vendor_invoice_number, ''), CONCAT('#', p.id)),
                    v.company_name, v.related_party_type,
                    p.effective_cost_date,
                    ROUND(p.total_without_vat, 2)
               FROM purchase_invoices p
               JOIN clients v ON v.id = p.vendor_id AND v.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND v.related_party = 1
                AND p.status NOT IN ('draft','cancelled')
                AND p.document_kind IN (" . self::RECEIVED_TAXABLE_KINDS . ")
                AND p.effective_cost_date BETWEEN ? AND ?
              ORDER BY tax_date, doc_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $from, $to, $supplierId, $from, $to]);

        return array_map(static fn (array $r): array => [
            'direction'          => (string) $r['direction'],
            'doc_type'           => (string) $r['doc_type'],
            'doc_id'             => (int) $r['doc_id'],
            'doc_no'             => (string) $r['doc_no'],
            'partner_name'       => (string) ($r['partner_name'] ?? ''),
            'related_party_type' => $r['related_party_type'] === null ? null : (string) $r['related_party_type'],
            'tax_date'           => (string) $r['tax_date'],
            'amount'             => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * MĚŘITELNÉ odchylky: položka fakturovaná spojené osobě vs. medián jednotkových cen
     * téže položky fakturovaných nespojeným osobám ve stejném období.
     *
     * Vrací jen položky, kde srovnání existuje (aspoň {@see MIN_COMPARABLE_SAMPLES}
     * srovnatelných faktur) a odchylka přesahuje {@see DEVIATION_THRESHOLD_PCT}.
     * Chybí-li srovnání, položka se nevrací — tvrdit odchylku proti neexistujícímu
     * vzorku by bylo horší než mlčet.
     *
     * @return list<array{
     *   doc_type:string, doc_id:int, doc_no:string, partner_name:string, description:string,
     *   unit_price:float, market_price:float, deviation_pct:float, samples:int, note:string
     * }>
     */
    public function priceDeviations(int $supplierId, string $from, string $to): array
    {
        $market = $this->unrelatedUnitPrices($supplierId, $from, $to);
        if ($market === []) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id AS doc_id,
                    COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                    c.company_name AS partner_name,
                    ii.description,
                    ROUND(ii.unit_price_without_vat, 2) AS unit_price
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
               JOIN clients c  ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ? AND c.related_party = 1
                AND i.status NOT IN ('draft','cancelled')
                AND i.effective_tax_date BETWEEN ? AND ?
                AND ii.unit_price_without_vat > 0
                AND ii.quantity > " . self::MIN_COMPARABLE_QUANTITY . "
                AND LOWER(TRIM(COALESCE(ii.unit, ''))) IN (" . self::comparableUnitsSql() . ")
              ORDER BY i.id, ii.order_index"
        );
        $stmt->execute([$supplierId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = self::itemKey((string) $row['description']);
            if (!isset($market[$key]) || count($market[$key]) < self::MIN_COMPARABLE_SAMPLES) {
                continue;
            }

            $unitPrice = (float) $row['unit_price'];
            $marketPrice = self::median($market[$key]);
            if ($marketPrice <= 0.0) {
                continue;
            }

            $deviation = round(($unitPrice - $marketPrice) / $marketPrice * 100, 2);
            if (abs($deviation) < self::DEVIATION_THRESHOLD_PCT) {
                continue;
            }

            $out[] = [
                'doc_type'      => 'invoice',
                'doc_id'        => (int) $row['doc_id'],
                'doc_no'        => (string) $row['doc_no'],
                'partner_name'  => (string) ($row['partner_name'] ?? ''),
                'description'   => (string) $row['description'],
                'unit_price'    => $unitPrice,
                'market_price'  => round($marketPrice, 2),
                'deviation_pct' => $deviation,
                'samples'       => count($market[$key]),
                'note'          => sprintf(
                    '%+.1f %% proti mediánu %s Kč z %d faktur nespojeným osobám.',
                    $deviation,
                    number_format($marketPrice, 2, ',', ' '),
                    count($market[$key]),
                ),
            ];
        }

        return $out;
    }

    /**
     * Úpravy základu daně podle § 23 odst. 7 za rok — podklad pro DPPO.
     *
     * @return array{rows:list<array<string,mixed>>, total_increase:float, total_decrease:float, net_delta:float}
     */
    public function adjustments(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.client_id, c.company_name, a.movement, a.amount, a.reason
               FROM tax_related_party_adjustments a
          LEFT JOIN clients c ON c.id = a.client_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.fiscal_year = ?
           ORDER BY a.id'
        );
        $stmt->execute([$supplierId, $fiscalYear]);

        $rows = [];
        $increase = 0.0;
        $decrease = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $amount = round((float) $r['amount'], 2);
            $movement = (string) $r['movement'];
            if ($movement === 'increase') {
                $increase += $amount;
            } else {
                $decrease += $amount;
            }
            $rows[] = [
                'id'           => (int) $r['id'],
                'client_id'    => $r['client_id'] === null ? null : (int) $r['client_id'],
                'partner_name' => (string) ($r['company_name'] ?? ''),
                'movement'     => $movement,
                'amount'       => $amount,
                'reason'       => (string) $r['reason'],
            ];
        }

        return [
            'rows'           => $rows,
            'total_increase' => round($increase, 2),
            'total_decrease' => round($decrease, 2),
            'net_delta'      => round($increase - $decrease, 2),
        ];
    }

    /**
     * Zaeviduje úpravu základu daně podle § 23 odst. 7.
     *
     * @param 'increase'|'decrease' $movement
     */
    public function recordAdjustment(
        int $supplierId,
        int $fiscalYear,
        float $amount,
        string $reason,
        ?int $clientId = null,
        string $movement = 'increase',
        ?int $userId = null,
    ): int {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Částka musí být kladná; směr určuje movement.');
        }
        if (!in_array($movement, ['increase', 'decrease'], true)) {
            throw new \InvalidArgumentException('movement je increase nebo decrease.');
        }
        if (trim($reason) === '') {
            // § 23/7 se uplatní právě tehdy, když rozdíl NENÍ uspokojivě doložen — důvod
            // je tedy jádro položky, ne poznámka. Bez něj se úprava při kontrole neobhájí.
            throw new \InvalidArgumentException('Důvod úpravy je povinný — čím je rozdíl doložen, nebo proč doložen není.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_related_party_adjustments
                (supplier_id, client_id, fiscal_year, movement, amount, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $clientId, $fiscalYear, $movement, round($amount, 2), trim($reason), $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function deleteAdjustment(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM tax_related_party_adjustments WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    /**
     * Jednotkové ceny položek fakturovaných NESPOJENÝM osobám, seskupené podle názvu.
     *
     * @return array<string, list<float>>
     */
    private function unrelatedUnitPrices(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.description, ROUND(ii.unit_price_without_vat, 2) AS unit_price
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
               JOIN clients c  ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ? AND c.related_party = 0
                AND i.status NOT IN ('draft','cancelled')
                AND i.effective_tax_date BETWEEN ? AND ?
                AND ii.unit_price_without_vat > 0
                AND ii.quantity > " . self::MIN_COMPARABLE_QUANTITY . "
                AND LOWER(TRIM(COALESCE(ii.unit, ''))) IN (" . self::comparableUnitsSql() . ")"
        );
        $stmt->execute([$supplierId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[self::itemKey((string) $r['description'])][] = (float) $r['unit_price'];
        }

        return $out;
    }

    /**
     * Klíč pro spárování téže položky napříč fakturami. Popis se mezi doklady liší
     * v drobnostech (velikost písmen, mezery navíc), takže by přesná shoda spárovala
     * málo co a srovnání by vzniklo jen výjimečně.
     */
    private static function itemKey(string $description): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $description) ?? $description));
    }

    /** Whitelist jednotek do SQL. Hodnoty jsou konstanta v kódu, ne uživatelský vstup. */
    private static function comparableUnitsSql(): string
    {
        return "'" . implode("', '", self::COMPARABLE_UNITS) . "'";
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
