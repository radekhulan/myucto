<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\PostingException;

/**
 * Kanonický producent VAT řádků — JEDNO místo se sdílenou logikou pro všechny
 * tři daňové reporty (DPH přiznání, kontrolní hlášení, Kniha DPH):
 *
 *   - filtr období (daňově korektní zařazení do měsíce):
 *       * vystavené → COALESCE(tax_date, issue_date) = DUZP (daň na výstupu k DUZP),
 *       * přijaté tuzemské → GREATEST(DUZP, vystavení) — nárok na odpočet nejdřív, když
 *         plátce drží daňový doklad (§ 73 odst. 1 písm. a ZDPH); zpětné DUZP tak padne
 *         do měsíce vystavení.
 *       * přijaté zahraniční RC (reverse_charge + vendor mimo CZ) → COALESCE(DUZP,
 *         vystavení) — povinnost přiznat daň vzniká k DUZP bez ohledu na držení dokladu
 *         (pořízení zboží z JČS § 25 odst. 1, služby § 24) a pozdní doklad odpočet
 *         neblokuje (§ 73 odst. 1 písm. b — „lze nárok prokázat jiným způsobem");
 *         u dovozu ze 3. země (§ 23) je trigger propuštění do režimu = tax_date a
 *         doklad (rozhodnutí CÚ) existuje od téhož dne. Issue #117.
 *     (Zobrazený sloupec tax_date dál nese skutečné DUZP, mění se jen příslušnost k období.)
 *   - filtr stavu: bez 'cancelled'; 'draft' jen pokud $includeDrafts (Kniha ano,
 *     DPH/KH ne); u vystavených navíc bez 'proforma', u přijatých bez 'advance'
 *     (zálohová/proforma není daňový doklad)
 *   - resolve klasifikačního kódu: řádek → hlavička → auto-default dle sazby + RC + směru
 *   - přepočet na CZK kurzem faktury
 *   - RC samovyměření (jen přijaté): když pii.total_vat=0 a (reverse_charge flag NEBO
 *     is_reverse_charge kódu) → daň = základ × sazba/100; má-li řádek sazbu 0 %
 *     (import z cizího dokladu), použije se sazba klasifikačního kódu (issue #116)
 *
 * Vrací per-(faktura, řádek) řádky; jednotlivé reporty si je projektují:
 *   - DPHDP3 / Kniha DPH: group by dphdp3_line
 *   - KH: group by faktura → sekce dle kh_section + práh + DIČ
 *
 * @phpstan-type LedgerRow array{
 *   source:string, invoice_id:int, doc_number:?string, vendor_invoice_number:?string,
 *   document_kind:?string, status:string, is_draft:bool, tax_date:?string, issue_date:?string,
 *   counterparty_name:string, counterparty_dic:?string, country_iso2:?string,
 *   code:?string, dphdp3_line:?string, dphdp3_line_secondary:?string, kh_section:?string,
 *   is_reverse_charge:bool, code_estimated:bool, vat_deduction_partial:bool, vat_rate:float, base_czk:float, vat_czk:float,
 *   total_with_vat_czk:float, is_fixed_asset:bool, exchange_rate:float, exchange_rate_missing:bool
 * }
 */
final class VatLedgerService
{
    /** @var array<int,string> Cache SQL seznamu RC kódů přijaté strany, per tenant. */
    private array $rcPurchaseCodesSql = [];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * @return list<array<string,mixed>> kanonické řádky (sale i purchase) za období
     */
    public function rows(int $supplierId, string $start, string $end, bool $includeDrafts = false): array
    {
        $map = $this->classificationMap($supplierId);
        // Práh základní/snížená sazba (per rok období) pro remapování 12% RC řádků (S3).
        $bucket = $this->taxConstants->vatBucketThreshold((int) substr($start, 0, 4));
        $rows = [];
        foreach ($this->fetchSales($supplierId, $start, $end, $includeDrafts) as $r) {
            $rows[] = $this->normalize($r, 'sale', $map, $bucket);
        }
        foreach ($this->fetchPurchases($supplierId, $start, $end, $includeDrafts) as $r) {
            $rows[] = $this->normalize($r, 'purchase', $map, $bucket);
        }
        foreach ($this->fetchCash($supplierId, $start, $end, $includeDrafts) as [$r, $direction]) {
            $rows[] = $this->normalize($r, $direction, $map, $bucket);
        }
        return $rows;
    }

    /**
     * Klasifikační mapa code → atributy (globální seed + per-tenant override).
     *
     * @return array<string, array{dphdp3_line:?string, dphdp3_line_secondary:?string,
     *                              kh_section:?string, vat_rate:?float, is_reverse_charge:bool}>
     */
    public function classificationMap(int $supplierId): array
    {
        // ORDER BY supplier_id IS NULL DESC → globální (NULL) řádky první, per-tenant
        // override poslední → v loopu přepíše globální seed (per-tenant override VYHRAJE).
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, label, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate,
                    is_reverse_charge, kod_pred_pl, kh_regime_code, kh_bad_debt
               FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
           ORDER BY supplier_id IS NULL DESC, display_order ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['code']] = [
                'label'                 => (string) $r['label'],
                'dphdp3_line'           => $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null,
                'dphdp3_line_secondary' => $r['dphdp3_line_secondary'] !== null ? (string) $r['dphdp3_line_secondary'] : null,
                'kh_section'            => $r['kh_section'] !== null ? (string) $r['kh_section'] : null,
                'vat_rate'              => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
                'is_reverse_charge'     => (bool) $r['is_reverse_charge'],
                'kod_pred_pl'           => isset($r['kod_pred_pl']) && $r['kod_pred_pl'] !== null ? (string) $r['kod_pred_pl'] : null,
                'kh_regime_code'         => isset($r['kh_regime_code']) && $r['kh_regime_code'] !== null ? (string) $r['kh_regime_code'] : null,
                'kh_bad_debt'            => isset($r['kh_bad_debt']) && $r['kh_bad_debt'] !== null ? (string) $r['kh_bad_debt'] : null,
            ];
        }
        return $map;
    }

    /**
     * SQL výraz období odpočtu přijatého dokladu (§ 73 ZDPH) nad aliasy `pi` (purchase_invoices)
     * a `co` (countries) — jediné místo pravdy, sdílí ho fetchPurchases i purchaseClaimInfo.
     * Zdůvodnění viz komentář ve fetchPurchases (received_at_source='manual', zahraniční RC…).
     */
    public static function purchaseClaimDateExpr(): string
    {
        // RC se pozná flagem `reverse_charge` NEBO klasifikačním kódem (23/24/24e/25) na
        // HLAVIČCE i na KTERÉKOLIV POLOŽCE — import (defaultClassificationCode) přiřadí kód
        // 24/24e na položku i bez hlavičkového flagu; bez této shody by se zahraniční doklad
        // zařadil přes GREATEST místo DUZP a samovyměření (ř.3/5/12) by uteklo do jiného
        // období (riziko nesouladu s FÚ, issue #117). Item-level signál přes korelovaný
        // EXISTS, ne přes JOIN — výraz sdílí purchaseClaimInfo bez joinu položek a EXISTS
        // vyhodnotí RC záležitost celého dokladu (co.iso2 <> 'CZ') identicky v obou dotazech
        // → fetchPurchases i drill-down kontroly 343 zůstanou konzistentní.
        return "CASE
                       WHEN (pi.reverse_charge = 1
                             OR pi.vat_classification_code IN ('23','24','24e','25')
                             OR EXISTS (SELECT 1 FROM purchase_invoice_items pcx
                                         WHERE pcx.purchase_invoice_id = pi.id
                                           AND pcx.vat_classification_code IN ('23','24','24e','25')))
                            AND COALESCE(co.iso2, 'CZ') <> 'CZ'
                           THEN COALESCE(pi.tax_date, pi.issue_date)
                       WHEN pi.received_at_source = 'manual' AND pi.received_at IS NOT NULL THEN
                           CASE
                               WHEN pi.received_at >= COALESCE(pi.tax_date, pi.issue_date)
                                    AND pi.received_at >= pi.issue_date THEN pi.received_at
                               WHEN COALESCE(pi.tax_date, pi.issue_date) >= pi.issue_date
                                    THEN COALESCE(pi.tax_date, pi.issue_date)
                               ELSE pi.issue_date
                           END
                       WHEN pi.tax_date IS NULL THEN pi.issue_date
                       WHEN pi.issue_date IS NULL THEN pi.tax_date
                       WHEN pi.tax_date >= pi.issue_date THEN pi.tax_date
                       ELSE pi.issue_date
                   END";
    }

    /**
     * Období odpočtu + metadata pro klasifikaci časových posunů § 73 (kontrola 343).
     * status/document_kind/vat_deduction umožňují klasifikátoru ověřit TYTÉŽ filtry,
     * jaké má fetchPurchases — doklad, který do přiznání nikdy nevstoupí (draft/
     * cancelled/advance/bez nároku), nesmí dostat timing_73 vysvětlení.
     *
     * @param list<int> $purchaseInvoiceIds
     * @return array<int, array{claim_date:string, received_at:?string,
     *                          received_at_source:?string, doc_number:?string,
     *                          status:string, document_kind:?string, vat_deduction:?string}>
     *         purchase_invoice_id => info; chybějící id = doklad neexistuje/cizí tenant
     */
    public function purchaseClaimInfo(int $supplierId, array $purchaseInvoiceIds): array
    {
        if ($purchaseInvoiceIds === []) {
            return [];
        }
        $expr = self::purchaseClaimDateExpr();
        $placeholders = implode(',', array_fill(0, count($purchaseInvoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id, {$expr} AS claim_date,
                   pi.received_at, pi.received_at_source, pi.varsymbol AS doc_number,
                   pi.status, pi.document_kind, pi.vat_deduction
              FROM purchase_invoices pi
              JOIN clients c    ON c.id = pi.vendor_id
         LEFT JOIN countries co ON co.id = c.country_id
             WHERE pi.supplier_id = ? AND pi.id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$supplierId], array_map('intval', $purchaseInvoiceIds)));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'claim_date'         => (string) $r['claim_date'],
                'received_at'        => $r['received_at'] !== null ? substr((string) $r['received_at'], 0, 10) : null,
                'received_at_source' => $r['received_at_source'] !== null ? (string) $r['received_at_source'] : null,
                'doc_number'         => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                'status'             => (string) $r['status'],
                'document_kind'      => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
                'vat_deduction'      => $r['vat_deduction'] !== null ? (string) $r['vat_deduction'] : null,
            ];
        }
        return $out;
    }

    /**
     * SQL výraz DIČ odběratele u VYDANÉHO dokladu (EPIC VH-04): preferuje snapshot
     * z dokladu (`client_snapshot.dic` = stav v okamžiku vystavení) s fallbackem na
     * živé `dic` klienta — pozdější změna DIČ na kartě klienta se nesmí zpětně
     * propsat do výkazů. Doklady bez snapshotu (legacy/import) nebo se snapshotem
     * bez DIČ padají na živý join, tj. dosavadní chování.
     *
     * MariaDB JSON_VALUE vrací nequotovaný skalár; SQLite (unit testy) má ekvivalent
     * json_extract. `hasColumn` kryje minimalistická testovací schémata bez sloupce.
     * U PŘIJATÝCH dokladů snapshot DIČ neexistuje — tam zůstává živý join.
     *
     * @param string $invoiceAlias alias tabulky invoices (např. 'i')
     * @param string $clientAlias  alias tabulky clients (např. 'c')
     */
    public static function saleCounterpartyDicExpr(Connection $db, string $invoiceAlias, string $clientAlias): string
    {
        if (!$db->hasColumn('invoices', 'client_snapshot')) {
            return "{$clientAlias}.dic";
        }

        // Živé DIČ jen jako fallback pro doklady BEZ snapshotu (drafty) nebo se stub
        // snapshotem bez klíče dic (staré importy). Snapshot s klíčem dic — byť
        // prázdným — znamená „odběratel DIČ v okamžiku vystavení neměl" a později
        // přidělené živé DIČ nesmí historický výkaz změnit (žádná resurekce).
        if ($db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return "CASE WHEN {$invoiceAlias}.client_snapshot IS NULL THEN {$clientAlias}.dic
                         WHEN json_type({$invoiceAlias}.client_snapshot, '$.dic') IS NOT NULL
                              THEN NULLIF(json_extract({$invoiceAlias}.client_snapshot, '$.dic'), '')
                         ELSE {$clientAlias}.dic END";
        }

        return "CASE WHEN {$invoiceAlias}.client_snapshot IS NULL THEN {$clientAlias}.dic
                     WHEN JSON_CONTAINS_PATH({$invoiceAlias}.client_snapshot, 'one', '$.dic')
                          THEN NULLIF(JSON_VALUE({$invoiceAlias}.client_snapshot, '$.dic'), '')
                     ELSE {$clientAlias}.dic END";
    }

    /** @return list<array<string,mixed>> */
    private function fetchSales(int $supplierId, string $start, string $end, bool $includeDrafts): array
    {
        $statusFilter = $includeDrafts ? "i.status != 'cancelled'" : "i.status NOT IN ('draft', 'cancelled')";
        // DIČ protistrany ze snapshotu dokladu s fallbackem na živého klienta (viz helper).
        $dicExpr = self::saleCounterpartyDicExpr($this->db, 'i', 'c');
        $ossFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? 'AND COALESCE(ii.oss_applicable, 0) = 0'
            : '';
        // Práh základní/snížená sazba pro fallback klasifikaci — per rok období
        // (číselník daňových konstant, ne natvrdo 20.5).
        // ZÁKLADNÍ sazba, ne práh mezi buckety: fallback rozhoduje, jestli je sazba česká
        // základní. Práh (21+12)/2 = 16,5 by za základní prohlásil i německých 19 %.
        $standardRate = $this->taxConstants->vatRateStandard((int) substr($start, 0, 4));
        $stmt = $this->db->pdo()->prepare("
            SELECT i.id AS invoice_id, i.varsymbol AS doc_number, i.varsymbol AS vendor_invoice_number,
                   i.invoice_type AS document_kind, i.status,
                   i.effective_tax_date AS tax_date, i.issue_date,
                   -- RAW kurz (bez COALESCE ...,1) — normalize() rozliší chybějící kurz
                   -- od CZK a nastaví příznak exchange_rate_missing (issue #238).
                   i.exchange_rate AS exchange_rate, COALESCE(cur.code, 'CZK') AS currency,
                   -- Vydaný dobropis snižuje daň na výstupu → do DPH evidence musí vstoupit
                   -- záporně. Naše vlastní vystavení i ruční pořízení ho ukládají záporně,
                   -- ale import z cizího systému (Fakturoid/iDoklad/ISDOC) může přinést
                   -- kladné částky a InvoiceAmountPolicy kladný dobropis nezakazuje —
                   -- na konvenci se tedy spolehnout nedá a kladně vzatý opravný doklad by
                   -- daň místo snížení NAVÝŠIL (ř. 1/2 + kladná věta KH A.4).
                   --
                   -- Normalizuje se CELÝ DOKLAD jedním znaménkem podle jeho součtu, ne každá
                   -- položka zvlášť — zdůvodnění a precedent viz tentýž výraz ve fetchPurchases().
                   (CASE WHEN i.invoice_type = 'credit_note' AND i.total_with_vat > 0
                         THEN -1 ELSE 1 END) * i.total_with_vat AS inv_total,
                   i.reverse_charge AS rc_flag,
                   c.company_name AS counterparty_name, {$dicExpr} AS counterparty_dic,
                   co.iso2 AS country_iso2, COALESCE(co.is_eu, 0) AS country_is_eu,
                   0 AS is_fixed_asset,
                   COALESCE(
                       ii.vat_classification_code, i.vat_classification_code,
                       CASE
                           -- Zahraniční EU odběratel + RC → poskytnutí služby do JČS
                           -- (kód 22, ř.21 + SH kód plnění 3). Zboží od služby fallback
                           -- nerozliší, takže drží TÝŽ statistický default jako SSOT
                           -- (InvoiceRepository::defaultSaleClassificationCode) — u typického
                           -- uživatele jsou přeshraniční služby častější než dodání zboží.
                           -- Dřív tu bylo natvrdo '20' (zboží), takže se doklad bez kódu vykázal
                           -- jinak podle toho, KUDY do výkazu vstoupil. Rozhoduje jednotka
                           -- položky, kterou vidí jen SSOT; fallback řeší už jen legacy řádky
                           -- úplně bez kódu a hlásí je příznakem code_estimated.
                           WHEN i.reverse_charge = 1
                                AND COALESCE(co.is_eu, 0) = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ' THEN '22'
                           -- Odběratel ze 3. země + RC = plnění s místem plnění mimo EU (bez české
                           -- DPH) → kód '26' (ř.22, MIMO KH). NESMÍ spadnout na '25s' (to je tuzemský
                           -- §92 → KH A.1 + ř.25) — jinak zahraniční plnění chybně leakuje do KH.
                           WHEN i.reverse_charge = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ' THEN '26'
                           -- Tuzemský odběratel + RC = přenesená daň. povinnost §92 → ř.25 (pln_rez_pren), KH A.1.
                           WHEN i.reverse_charge = 1 THEN '25s'
                           WHEN ii.vat_rate_snapshot >= ? - 0.5 THEN '1'
                           -- Jen ČESKÁ snížená sazba (12 %, historicky 10/15). Pásmo 16 %
                           -- až pod základní sazbu se NEMAPUJE: cizí sazba (DE 19 %) není
                           -- česká DPH a kód '2' by ji poslal na ř. 2 jako tuzemské plnění.
                           -- Shodně s InvoiceRepository::defaultSaleClassificationCode().
                           WHEN ii.vat_rate_snapshot BETWEEN 5 AND 15 THEN '2'
                           ELSE NULL
                       END
                   ) AS code,
                   ii.vat_rate_snapshot AS vat_rate,
                   ii.description AS description,
                   -- Totéž dokladové znaménko jako u inv_total výše (viz komentář tam).
                   (CASE WHEN i.invoice_type = 'credit_note' AND i.total_with_vat > 0
                         THEN -1 ELSE 1 END) * COALESCE(ii.total_without_vat, 0) AS base,
                   (CASE WHEN i.invoice_type = 'credit_note' AND i.total_with_vat > 0
                         THEN -1 ELSE 1 END) * COALESCE(ii.total_vat, 0) AS vat
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
         LEFT JOIN countries co ON co.id = c.country_id
              JOIN invoice_items ii ON ii.invoice_id = i.id
         LEFT JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND {$statusFilter}
               AND i.invoice_type NOT IN ('proforma', 'penalty')
               {$ossFilter}
               AND i.effective_tax_date BETWEEN ? AND ?
          ORDER BY i.effective_tax_date, i.id, ii.id
        ");
        $stmt->execute([$standardRate, $supplierId, $start, $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Řádky pro náhled OSS podání.
     *
     * `oss_needs_manual_review` (migrace 1293) má guard VLASTNÍ, ne společný se zbytkem
     * OSS sloupců: mezi migracemi 0137 a 1293 je řada verzí, takže instance s OSS
     * schématem a bez příznaku je běžný stav. Sloupec se veze s TÍMTO dotazem schválně —
     * náhled ho potřebuje ({@see \MyInvoice\Service\Oss\OssLedgerService::preview()})
     * a vlastní dotaz nad týmiž řádky by byl druhá definice toho, co je „řádek podání".
     *
     * @return list<array<string,mixed>>
     */
    public function ossRows(int $supplierId, string $start, string $end): array
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            return [];
        }
        $manualReview = $this->db->hasColumn('invoice_items', 'oss_needs_manual_review')
            ? 'ii.oss_needs_manual_review'
            : '0 AS oss_needs_manual_review';

        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id AS invoice_id, i.varsymbol AS doc_number, i.invoice_type, i.status,
                    i.effective_tax_date AS tax_date, i.issue_date,
                    COALESCE(cur.code, 'CZK') AS currency, c.company_name AS client_name,
                    ii.id AS item_id, ii.description, ii.vat_rate_snapshot,
                    ii.total_without_vat, ii.total_vat,
                    ii.oss_consumer_country, ii.oss_rate_type, ii.oss_supply_type,
                    ii.oss_exchange_rate, ii.oss_exchange_rate_date,
                    ii.oss_taxable_amount_return, ii.oss_vat_amount_return,
                    ii.oss_original_period, {$manualReview}
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND ii.oss_applicable = 1
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type NOT IN ('proforma', 'penalty')
                AND i.effective_tax_date BETWEEN ? AND ?
           ORDER BY i.effective_tax_date, i.id, ii.order_index, ii.id"
        );
        $stmt->execute([$supplierId, $start, $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Povolené rozsahy {@see manualReviewPredicate()} — sdílené s `filter[oss_review]`. */
    public const MANUAL_REVIEW_SCOPE_ANY = 'any';
    public const MANUAL_REVIEW_SCOPE_OSS = 'oss';
    public const MANUAL_REVIEW_SCOPE_DOMESTIC = 'domestic';

    /** @var list<string> */
    public const MANUAL_REVIEW_SCOPES = [
        self::MANUAL_REVIEW_SCOPE_ANY,
        self::MANUAL_REVIEW_SCOPE_OSS,
        self::MANUAL_REVIEW_SCOPE_DOMESTIC,
    ];

    /**
     * SQL predikát „řádek, který si má člověk projít" (`oss_needs_manual_review = 1`) —
     * bez `WHERE`, k připojení. `$scope` říká, KDE ten řádek skončil, protože každý
     * z těch dvou konců se řeší jinak:
     *
     *  - `domestic` (`oss_applicable = 0`) — kanál místo plnění NEURČIL a doklad zahodit
     *    nesměl (cron opakovaných faktur, iDoklad, Fakturoid, AI extrakce, API bez OSS
     *    klíčů), tak řádek zůstal mimo OSS a nejistota se uložila k položce
     *    ({@see \MyInvoice\Service\Oss\OssItemPlanner}). {@see fetchSales()} ho pustí na
     *    ř. 1/2 tuzemského přiznání jako každé jiné plnění, do OSS podání nejde. Otázka
     *    zní „patří sem vůbec?".
     *  - `oss` (`oss_applicable = 1`) — řádek se do OSS ZAŘADIL, ale s otazníkem:
     *    nejednoznačná sazba (21 % platí v ČR i v NL/BE/ES/LT/LV), číselník neuměl
     *    odpovědět, nebo si doklad protiřečí ({@see \MyInvoice\Service\Oss\OssDocumentCoherence}).
     *    Otázka zní „sedí země a typ sazby, nebo to patří do tuzemska?".
     *  - `any` — obojí; „ukaž mi všechno nedořešené".
     *
     * Rozsah se NESLÉVÁ do jedné podmínky natvrdo schválně. Varování v přiznání k DPH
     * ({@see DphPriznaniBuilder::appendSalesDataWarnings()}) smí mluvit jen o řádcích,
     * které do přiznání skutečně vstupují — tedy `domestic`; kdyby hlásilo i OSS řádky,
     * byl by to šum o dokladech, které v přiznání nejsou. Naopak filtr v seznamu faktur
     * ({@see \MyInvoice\Repository\InvoiceRepository::listGroupedByMonth()},
     * `filter[oss_review]`) musí umět OBOJÍ: kdyby uměl jen `domestic`, uživatel by si
     * filtr vyčistil a druhá polovina sporných řádků by mu zůstala schovaná v náhledu
     * OSS podání a v reportu importu, který po zavření stránky zmizí.
     *
     * Definice bydlí TADY a je jedna — dvě kopie podmínky by se rozešly hned, jak se
     * změní jedna.
     *
     * Vrací `null`, když schéma příznak nezná (mezi migracemi 0137 a 1293 je řada verzí) —
     * volající pak kontrolu vynechá, místo aby spadl na neexistujícím sloupci.
     *
     * STATICKÁ a s `Connection` v parametru schválně — stejně jako
     * {@see saleCounterpartyDicExpr()}. Predikát potřebuje i `InvoiceRepository`, a ten
     * na reportovací službu záviset nemá (repozitář pod službou, ne naopak). Fragment,
     * který si volající vloží do svého `WHERE`, tuhle vazbu nepotřebuje.
     *
     * @param  string  $itemAlias alias tabulky invoice_items (např. 'ii')
     * @param  string  $scope     jedna z {@see MANUAL_REVIEW_SCOPES}
     * @return ?string SQL fragment, nebo null když příznak ve schématu není
     */
    public static function manualReviewPredicate(
        Connection $db,
        string $itemAlias = 'ii',
        string $scope = self::MANUAL_REVIEW_SCOPE_DOMESTIC,
    ): ?string {
        if (!$db->hasColumn('invoice_items', 'oss_applicable')
            || !$db->hasColumn('invoice_items', 'oss_needs_manual_review')
        ) {
            return null;
        }

        $flagged = "COALESCE({$itemAlias}.oss_needs_manual_review, 0) = 1";

        return match ($scope) {
            self::MANUAL_REVIEW_SCOPE_ANY => $flagged,
            self::MANUAL_REVIEW_SCOPE_OSS => "COALESCE({$itemAlias}.oss_applicable, 0) = 1
                AND {$flagged}",
            // Neznámý rozsah se schválně chová jako `domestic`, ne jako `any`: rozšířit
            // tichou překlepem množinu řádků, o kterých se tvrdí, že jsou v tuzemském
            // přiznání, by bylo horší než ji zúžit na původní, ověřenou definici.
            default => "COALESCE({$itemAlias}.oss_applicable, 0) = 0
                AND {$flagged}",
        };
    }

    /**
     * Nejistý řádek TUZEMSKÉ větve — zúžená {@see manualReviewPredicate()} pro místa,
     * která mluví o tuzemském přiznání (varování v DPHDP3, {@see uncertainOssDocuments()}).
     *
     * @param  string  $itemAlias alias tabulky invoice_items (např. 'ii')
     * @return ?string SQL fragment, nebo null když příznak ve schématu není
     */
    public static function uncertainOssPredicate(Connection $db, string $itemAlias = 'ii'): ?string
    {
        return self::manualReviewPredicate($db, $itemAlias, self::MANUAL_REVIEW_SCOPE_DOMESTIC);
    }

    /**
     * Doklady s nejistým řádkem ({@see uncertainOssPredicate()}) v období — podklad pro
     * varování v přiznání k DPH.
     *
     * Filtry stavu a typu jsou ZÁMĚRNĚ tytéž jako ve {@see fetchSales()}: varovat se má
     * právě u dokladů, které do přiznání skutečně vstupují. Doklad, který se nevykazuje,
     * uživatele jen zaplaví šumem.
     *
     * @return list<array{doc_number:?string, invoice_id:int, items:int}> seřazeno podle DUZP
     */
    public function uncertainOssDocuments(int $supplierId, string $start, string $end, bool $includeDrafts = false): array
    {
        $predicate = self::uncertainOssPredicate($this->db, 'ii');
        if ($predicate === null) {
            return [];
        }
        $statusFilter = $includeDrafts ? "i.status != 'cancelled'" : "i.status NOT IN ('draft', 'cancelled')";

        $stmt = $this->db->pdo()->prepare("
            SELECT i.id AS invoice_id, i.varsymbol AS doc_number, COUNT(*) AS items,
                   MIN(i.effective_tax_date) AS tax_date
              FROM invoices i
              JOIN invoice_items ii ON ii.invoice_id = i.id
             WHERE i.supplier_id = ?
               AND {$statusFilter}
               AND i.invoice_type NOT IN ('proforma', 'penalty')
               AND {$predicate}
               AND i.effective_tax_date BETWEEN ? AND ?
          GROUP BY i.id, i.varsymbol
          ORDER BY MIN(i.effective_tax_date), i.id
        ");
        $stmt->execute([$supplierId, $start, $end]);

        return array_map(static fn (array $r): array => [
            'invoice_id' => (int) $r['invoice_id'],
            'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
            'items'      => (int) $r['items'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Klasifikační kódy PŘIJATÉ strany v režimu samovyměření, jako SQL seznam
     * (`'5','23','24','24e','25'`). Čte se z číselníku (`is_reverse_charge = 1`), ne
     * z natvrdo zapsaného výčtu: seed přibývá (§ 92c odpad, § 92d nemovitost — migrace
     * 1510) a tenant si smí v Číselnících přidat vlastní. Zapomenutý kód by doklad
     * vyhodil z evidence DPH úplně (filtr „bez nároku na odpočet" i § 72 plátcovství
     * pouštějí samovyměření právě přes tenhle seznam).
     *
     * Kódy jdou do SQL literálem, ne přes bind (jsou uvnitř složeného výrazu sdíleného
     * dvěma dotazy), takže se propouští jen bezpečný tvar — cokoli jiného se ignoruje.
     * Prázdný výsledek by seznam degradoval na „nic", proto fallback na seed.
     */
    private function reverseChargePurchaseCodesSql(int $supplierId): string
    {
        if (isset($this->rcPurchaseCodesSql[$supplierId])) {
            return $this->rcPurchaseCodesSql[$supplierId];
        }
        $codes = [];
        try {
            // Multi-tenant scope jako v classificationMap(): globální seed + vlastní kódy
            // tenanta, NIKDY kódy cizí firmy — jinak by si tenant bez vlastního číselníku
            // natáhl do evidence samovyměření podle kódu, který nikdy nezaložil.
            $stmt = $this->db->pdo()->prepare(
                "SELECT DISTINCT code FROM vat_classifications
                  WHERE is_reverse_charge = 1
                    AND direction IN ('purchase', 'both')
                    AND archived = 0
                    AND (supplier_id IS NULL OR supplier_id = ?)"
            );
            $stmt->execute([$supplierId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $code) {
                if (preg_match('/^[A-Za-z0-9_-]{1,8}$/', (string) $code) === 1) {
                    $codes[] = (string) $code;
                }
            }
        } catch (\Throwable) {
            // Chybějící tabulka (unit schémata nad SQLite) — fallback níž.
        }
        if ($codes === []) {
            $codes = ['5', '23', '24', '24e', '25'];
        }
        return $this->rcPurchaseCodesSql[$supplierId] = "'" . implode("','", $codes) . "'";
    }

    /**
     * Pozn. k odpočtu DPH na vstupu:
     *  - `vat_deduction = 'none'` (bez nároku — reprezentace, osobní spotřeba…) → do DPH
     *    evidence se VŮBEC nezahrnuje (ani Kniha DPH, ani DPHDP3, ani KH), jen účetní náklad.
     *  - `vat_deduction = 'proportional'` = **poměrný odpočet podle § 75** (vstup zčásti pro
     *    ekonomickou, zčásti pro neekonomickou činnost) → základ i daň se krátí na
     *    `vat_deduction_percent` (viz normalize()). Tyto řádky se v KH označí `pomer='A'`.
     *  - `vat_deduction = 'reduced'` = **krácený nárok § 76** (společné vstupy pro plnění
     *    s nárokem i osvobozená bez nároku dle § 51) → v DPHDP3 se PLNÁ daň routuje do
     *    sloupce „Krácený odpočet" (klíč řádku 40k/41k/42k); vlastní krácení vypořádacím
     *    koeficientem se počítá až na úrovni období/roku v {@see DphPriznaniBuilder}
     *    (ř. 52/53). Základ zůstává v témž atributu jako plná verze. Kombinace s RC
     *    samovyměřením není podporovaná (ř. 43 nemá krácený protějšek) → tvrdá chyba.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchPurchases(int $supplierId, string $start, string $end, bool $includeDrafts): array
    {
        $statusFilter = $includeDrafts ? "pi.status != 'cancelled'" : "pi.status NOT IN ('draft', 'cancelled')";
        // Práh základní/snížená sazba pro fallback klasifikaci — per rok období.
        // Viz fetchSales — rozhoduje ZÁKLADNÍ sazba, ne práh mezi buckety.
        $standardRate = $this->taxConstants->vatRateStandard((int) substr($start, 0, 4));

        // § 72 filtr plátcovství k DUZP dokladu (viz komentář ve WHERE). Fallback 1
        // (firmy bez historie) i chybějící tabulka (SQLite unit schémata) = beze změny.
        $rcCodes = $this->reverseChargePurchaseCodesSql($supplierId);
        $payerFilter = '';
        if ($this->db->hasTable('supplier_vat_status_history')) {
            $payerAtDuzp = \MyInvoice\Service\Vat\VatStatusService::payerAtExpr(
                'pi.supplier_id', 'COALESCE(pi.tax_date, pi.issue_date)', '1'
            );
            $payerFilter = "AND ({$payerAtDuzp} = 1
                    OR (COALESCE(pii.total_vat, 0) = 0
                        AND (pi.reverse_charge = 1
                             OR COALESCE(pii.vat_classification_code, pi.vat_classification_code) IN ({$rcCodes}))))";
        }

        // Období odpočtu (tuzemská plnění). Zákonně rozhoduje datum, kdy plátce doklad
        // fyzicky DRŽÍ (§ 73 odst. 1 písm. a ZDPH). received_at ale importy (iDoklad/
        // Fakturoid/ISDOC/AI/inbox/banka) plní na den IMPORTU, takže naivní
        // GREATEST(DUZP, received_at) by u zpětně importovaných dokladů (běžné) naházel
        // odpočet do měsíce importu. Proto received_at použijeme jen když ho účetní
        // VĚDOMĚ zadala ve formuláři (received_at_source='manual', C6) — pak GREATEST přes
        // received_at, DUZP i vystavení. U 'import'/legacy zůstává konzervativní
        // GREATEST(DUZP, vystavení): faktura se zpětným DUZP, ale vystavená v pozdějším
        // měsíci, spadá do měsíce vystavení (issue_date je spolehlivá proxy držení dokladu).
        // (Zobrazený sloupec tax_date dál ukazuje skutečné DUZP.)
        //
        // VÝJIMKA — zahraniční reverse charge (issue #117): u pořízení zboží z JČS vzniká
        // povinnost přiznat daň k DUZP bez ohledu na držení dokladu (§ 25 odst. 1 — 15. den
        // měsíce po pořízení, nebo dřívější vystavení dokladu) a pozdní doklad neblokuje ani
        // odpočet (§ 73 odst. 1 písm. b — nárok lze prokázat jiným způsobem; potvrzeno SDEU
        // C-895/19). Totéž pro přijetí služby ze zahraničí (§ 24 + § 73/1/b). U dovozu zboží
        // ze 3. země je trigger propuštění do celního režimu (§ 23) = tax_date a doklad
        // (rozhodnutí CÚ, § 73/1/c) existuje od téhož dne. Proto se zahraniční RC zařazuje
        // dle DUZP, ne GREATEST — jinak pozdě vystavená faktura posune samovyměření (ř. 3)
        // do špatného období (riziko doměrku). received_at_source se u ní IGNORUJE.
        // PŘEDPOKLAD: tax_date nese zákonné DUZP (AI import ho u pořízení z JČS dopočítává
        // dle § 25 — viz AiPdfExtractor::euAcquisitionTaxDate()).
        //
        // Tuzemský RC (kód 5) zůstává VĚDOMĚ na GREATEST(DUZP, vystavení) — právně spadá též
        // pod § 73/1/b, ale dodavatel musí doklad vystavit do 15 dnů od DUZP, takže rozdíl je
        // vzácný; ponecháno konzervativně (viz issue #117 diskuse).
        //
        // CASE místo GREATEST kvůli přenositelnosti (SQLite v testech GREATEST nemá).
        // Vnitřní CASE u 'manual' = GREATEST(received_at, DUZP, vystavení) rozepsané.
        // Sdílený výraz → WHERE (BETWEEN) i ORDER BY jsou vždy konzistentní.
        $periodExpr = self::purchaseClaimDateExpr();

        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id AS invoice_id, pi.varsymbol AS doc_number, pi.vendor_invoice_number,
                   pi.document_kind, pi.status,
                   pi.parent_purchase_invoice_id,
                   parent.vendor_invoice_number AS parent_vendor_invoice_number,
                   COALESCE(pi.tax_date, pi.issue_date) AS tax_date, pi.issue_date,
                   -- Období odpočtu POJMENOVANĚ v řádku (issue #9), týmž sdíleným výrazem,
                   -- kterým se filtruje a řadí — konzumenti (Kniha DPH, UI dokladu) tak
                   -- ukážou, ZA JAKÉ období doklad jde, místo aby to uživatel dedukoval
                   -- z toho, v jakém měsíci mu sestava řádek zobrazila.
                   {$periodExpr} AS claim_date,
                   pi.received_at, pi.received_at_source,
                   -- RAW kurz (bez COALESCE ...,1) — viz fetchSales / normalize() (issue #238).
                   pi.exchange_rate AS exchange_rate, COALESCE(cur.code, 'CZK') AS currency,
                   -- Přijatý dobropis snižuje odpočet → do DPH evidence musí vstoupit
                   -- záporně. Ruční pořízení i AI import ho u nás ukládají záporně, ale
                   -- import z cizího systému může přinést kladné částky, takže se na
                   -- konvenci nedá spolehnout.
                   --
                   -- Normalizuje se ale CELÝ DOKLAD jedním znaménkem podle jeho součtu,
                   -- NE každá položka zvlášť přes -ABS(). Opravný doklad totiž legitimně
                   -- obsahuje řádky obou znamének — vrácené zboží záporně a proti tomu
                   -- kladný storno poplatek nebo sleva na dopravné. Per-položkové -ABS()
                   -- by takový kladný řádek vykázalo záporně; na reálných datech téhle
                   -- firmy (doklady 3241359186 a 3241398688, 10/2024) to rozchází
                   -- přiznání o 31 Kč proti skutečně podanému. Dokladová normalizace
                   -- vnitřní poměr znamének zachová a u správně uloženého (záporného)
                   -- dobropisu je no-op.
                   (CASE WHEN pi.document_kind = 'credit_note' AND pi.total_with_vat > 0
                         THEN -1 ELSE 1 END) * pi.total_with_vat AS inv_total,
                   pi.reverse_charge AS rc_flag,
                   COALESCE(pii.vat_deduction, pi.vat_deduction) AS vat_deduction,
                   COALESCE(pii.vat_deduction_percent, pi.vat_deduction_percent) AS vat_deduction_percent,
                   c.company_name AS counterparty_name, c.dic AS counterparty_dic,
                   co.iso2 AS country_iso2, COALESCE(co.is_eu, 0) AS country_is_eu,
                   (CASE WHEN pii.is_fixed_asset = 1 OR pi.is_fixed_asset = 1 THEN 1 ELSE 0 END) AS is_fixed_asset,
                   COALESCE(
                       pii.vat_classification_code, pi.vat_classification_code,
                       CASE
                           -- Zahraniční dodavatel + RC NENÍ tuzemský §92a. Kód 5 by doklad
                           -- poslal na ř. 10 a do KH B.1, kam patří jen tuzemský přenos.
                           -- Zrcadlí se proto fallback prodejní strany: EU → 24e (přijetí
                           -- služby §9/1, ř. 5, KH A.2), 3. země → 24 (ř. 12, KH A.2).
                           -- Zboží od služby se z dat nepozná, proto default služba
                           -- + příznak code_estimated níže; pořízení zboží z EU (23)
                           -- a dovoz ze 3. země (25) si uživatel zvolí ručně.
                           WHEN pi.reverse_charge = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ'
                                AND COALESCE(co.is_eu, 0) = 1 THEN '24e'
                           WHEN pi.reverse_charge = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ' THEN '24'
                           WHEN pi.reverse_charge = 1 THEN '5'
                           WHEN pii.vat_rate_snapshot >= ? - 0.5 THEN '40'
                           -- Jen ČESKÁ snížená sazba (12 %, historicky 10/15). Nenamapovaná
                           -- cizí sazba (DE 19 %) tudy dřív dostala '41' → ř. 41 + KH B.3,
                           -- tedy ODPOČET NĚMECKÉ DANĚ. SSOT
                           -- (PurchaseInvoiceRepository::defaultClassificationCode) tohle
                           -- pásmo vědomě nemapuje a fallback ho nesmí přebít; doklad bez
                           -- kódu pojmenuje varování missing_vat_classification.
                           WHEN pii.vat_rate_snapshot BETWEEN 5 AND 15 THEN '41'
                           ELSE NULL
                       END
                   ) AS code,
                   -- Kód nebyl na dokladu, jen odhadnut fallbackem pro zahraniční RC.
                   -- Konzumenti (náhled KH) z toho staví upozornění, ať uživatel
                   -- případné zboží překlasifikuje ručně.
                   (CASE WHEN pii.vat_classification_code IS NULL AND pi.vat_classification_code IS NULL
                              AND pi.reverse_charge = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ'
                         THEN 1 ELSE 0 END) AS code_estimated,
                   pii.vat_rate_snapshot AS vat_rate,
                   pii.description AS description,
                   -- Totéž dokladové znaménko jako u inv_total výše (viz komentář tam).
                   (CASE WHEN pi.document_kind = 'credit_note' AND pi.total_with_vat > 0
                         THEN -1 ELSE 1 END) * COALESCE(pii.total_without_vat, 0) AS base,
                   (CASE WHEN pi.document_kind = 'credit_note' AND pi.total_with_vat > 0
                         THEN -1 ELSE 1 END) * COALESCE(pii.total_vat, 0) AS vat
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
         LEFT JOIN countries co ON co.id = c.country_id
         LEFT JOIN purchase_invoices parent ON parent.id = pi.parent_purchase_invoice_id
              JOIN (
                    SELECT i.id, i.purchase_invoice_id, i.description, i.vat_rate_snapshot,
                           i.total_without_vat, i.total_vat, i.vat_classification_code,
                           i.is_fixed_asset, NULL AS vat_deduction,
                           NULL AS vat_deduction_percent
                      FROM purchase_invoice_items i
                     WHERE NOT EXISTS (
                               SELECT 1 FROM purchase_invoice_vat_allocations a
                                WHERE a.purchase_invoice_id = i.purchase_invoice_id
                           )
                    UNION ALL
                    SELECT a.id, a.purchase_invoice_id, a.description, a.vat_rate,
                           a.base_amount, a.vat_amount, a.vat_classification_code,
                           0 AS is_fixed_asset, a.vat_deduction,
                           a.vat_deduction_percent
                      FROM purchase_invoice_vat_allocations a
                   ) pii ON pii.purchase_invoice_id = pi.id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND {$statusFilter}
               -- Zálohová / proforma (advance) NENÍ daňový doklad → ven z DPH evidence,
               -- symetricky k výstupní straně (fetchSales: invoice_type != 'proforma').
               -- Daňovým dokladem je až 'daňový doklad k přijaté platbě', ne tato výzva k platbě.
               -- COALESCE: NULL document_kind (legacy / neimportované doklady) = běžný
               -- doklad → ponechat (NULL <> 'advance' by jinak řádek vyřadilo).
               AND COALESCE(pi.document_kind, '') <> 'advance'
               -- Bez nároku na odpočet ('none') = běžně mimo DPH evidenci (reprezentace,
               -- osobní spotřeba — dodavatel už DPH naúčtoval). VÝJIMKA: reverse charge —
               -- povinnost přiznat daň příjemcem (§ 108, samovyměření ř.3/5/10/12) je
               -- NEZÁVISLÁ na nároku na odpočet (§ 72/4). Reprezentace/plnění bez nároku
               -- pořízené ze zahraničí (RC) tedy MUSÍ na výstup, jen odpočet (ř.43) se
               -- odepře (řeší normalize()+mapper přes příznak vat_deduction_none).
               -- Podmínka pii.total_vat = 0: pustíme jen SKUTEČNÉ samovyměření (dodavatel
               -- fakturoval BEZ DPH). Doklad s už naúčtovaným DPH + kódem RC (typicky B2C
               -- spotřebitelský nákup ze zahraničí přes OSS, chybně oklasifikovaný jako RC —
               -- Google One apod.) do evidence NEPATŘÍ: nejde o reverse charge (jinak by
               -- se zahraniční DPH omylem přiznalo na výstup). Tuzemské non-RC 'none' venku.
               AND (COALESCE(pii.vat_deduction, pi.vat_deduction) <> 'none'
                    OR (COALESCE(pii.total_vat, 0) = 0
                        AND (pi.reverse_charge = 1
                             OR COALESCE(pii.vat_classification_code, pi.vat_classification_code) IN ({$rcCodes}))))
               -- § 72: nárok na odpočet jen z plnění přijatých v době plátcovství. Doklad
               -- s DUZP mimo plátcovství (před registrací / po zrušení) do odpočtu nepatří —
               -- majetek při registraci řeší § 79 vlastní agendou (ř. 45). Samovyměření
               -- (RC/dovoz) zůstává: povinnost přiznat daň má i identifikovaná osoba a
               -- čerstvý plátce (§ 108) — normalize()+mapper mu odpočet odepřou jinde.
               {$payerFilter}
               -- Období odpočtu — sdílený výraz periodExpr (purchaseClaimDateExpr; zdůvodnění
               -- § 73, zahraniční RC dle DUZP i received_at_source='manual' viz tam). Sdílí ho
               -- purchaseClaimInfo (kontrola 343), aby se zařazení do období nikdy nerozešlo.
               AND {$periodExpr} BETWEEN ? AND ?
          ORDER BY {$periodExpr}, pi.id, pii.id
        ");
        $stmt->execute([$standardRate, $supplierId, $start, $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string, array<string,mixed>> $map
     * @param float $bucket práh základní/snížené sazby (remapování 12% RC řádků)
     * @return array<string,mixed>
     */
    private function normalize(array $r, string $source, array $map, float $bucket): array
    {
        // Kurz k účetní měně. CZK = vždy 1.0. U cizí měny bez zafixovaného kurzu (NULL/≤0)
        // použijeme náhradní 1.0, aby ne-daňové konzumenty (CRM náklady, trendy, přehledy)
        // nespadly, ALE ZÁROVEŇ nastavíme příznak exchange_rate_missing (issue #238).
        // Daňové XML výstupy (DPH/KH/SH) ho detekují přes missingExchangeRateRows() a při
        // stažení doplní oficiální kurz z ČNB (MissingExchangeRateFiller); tvrdá chyba jen
        // když ho ČNB nezná. Tichý přepočet kurzem 1.0 by jinak vykázal cizí měnu jako CZK.
        // Účetní posting má vlastní nezávislou bránu PostingService::fxRate (audit H1), která
        // missing_exchange_rate stále tvrdě odmítne. SQL nekoalescuje exchange_rate na 1, aby
        // sem NULL prošlo a příznak se dal spolehlivě odvodit.
        $isCzk = $r['currency'] === 'CZK';
        $rawRate = $r['exchange_rate'] === null ? null : (float) $r['exchange_rate'];
        $exchangeRateMissing = !$isCzk && ($rawRate === null || $rawRate <= 0.0);
        $rate = ($isCzk || $rawRate === null || $rawRate <= 0.0) ? 1.0 : $rawRate;
        $vatRate = (float) $r['vat_rate'];
        $baseRaw = (float) $r['base'];
        $vatRaw = (float) $r['vat'];

        $code = $r['code'] !== null ? (string) $r['code'] : null;
        $clsf = $code !== null ? ($map[$code] ?? null) : null;
        $isRc = ($clsf['is_reverse_charge'] ?? false) || (bool) $r['rc_flag'];

        // RC samovyměření jen u přijatých plnění (vendor fakturuje bez DPH).
        // Fallback sazby (issue #116): zahraniční doklad importovaný s řádkovou sazbou
        // 0 % (převzatou z cizího dokladu) by samovyměřil 0. Když je řádek RC a sazbu
        // nemá, vezmi tuzemskou sazbu z klasifikace (kódy 5/23/24/25 nesou 21.00) —
        // efektivní sazba se propíše i do row['vat_rate'], protože KH (A.2/B.1) a
        // DPHDP3 podle ní bucketují základ/daň do 21%/12% sloupců.
        if ($source === 'purchase' && $isRc && $vatRate == 0.0 && (float) ($clsf['vat_rate'] ?? 0) > 0) {
            $vatRate = (float) $clsf['vat_rate'];
        }
        // RC samovyměření: dodavatel fakturuje bez DPH (daň 0) → daň si dopočítá příjemce.
        // POZOR: daň se NEpočítá tady z cizoměnového základu, ale až níže ze základu
        // přepočteného na CZK (vat_czk) — viz komentář tam. Tady jen příznak.
        $rcSelfAssess = $source === 'purchase' && $vatRaw == 0.0 && $isRc && $vatRate > 0;

        // §75 poměrný odpočet — u běžného přijatého plnění krátíme řádek odpočtu přímo.
        // U reverse charge musí primární samovyměření zůstat v plné výši (§108); procento
        // se použije až na zrcadlový odpočet (ř.43/44) v mapperu.
        $isPartialDeduction = false;
        $deductionRatio = 1.0;
        if ($source === 'purchase' && ($r['vat_deduction'] ?? 'full') === 'proportional') {
            $deductionRatio = max(0.0, min(100.0, (float) ($r['vat_deduction_percent'] ?? 100))) / 100.0;
            if (!$rcSelfAssess) {
                $baseRaw = round($baseRaw * $deductionRatio, 2);
                $vatRaw  = round($vatRaw * $deductionRatio, 2);
            }
            $isPartialDeduction = true;
        }
        // 'none' bez nároku na odpočet: do evidence teče POUZE reverse charge (SQL už
        // tuzemské non-RC 'none' vyloučil). U RC zůstává výstupní samovyměření (ř.3/5/10/12
        // + KH A.2/B.1), ale zrcadlový odpočet ř.43 se v mapperu potlačí (§ 72/4). Základ
        // ani daň se tu NEkrátí — samovyměřená daň na výstupu je v plné výši.
        $isDeductionNone = $source === 'purchase' && ($r['vat_deduction'] ?? 'full') === 'none';

        // § 76 krácený nárok na odpočet ('reduced') — společné vstupy používané zároveň
        // pro plnění s nárokem i osvobozená bez nároku (§ 51). Na rozdíl od § 75 se NEKRÁTÍ
        // per doklad: základ i PLNÁ daň jdou beze změny, jen se dphdp3_line 40/41/42 přepíše
        // na „krácený" klíč 40k/41k/42k → DphPriznaniBuilder je vykáže ve sloupci „Krácený
        // odpočet" a krácení koeficientem promítne až souhrnně na ř. 52/53.
        $primaryLine   = $clsf['dphdp3_line'] ?? null;
        $secondaryLine = $clsf['dphdp3_line_secondary'] ?? null;
        if ($source === 'purchase' && ($r['vat_deduction'] ?? 'full') === 'reduced') {
            $docLabel = $r['doc_number'] !== null && $r['doc_number'] !== ''
                ? (string) $r['doc_number']
                : '#' . (string) $r['invoice_id'];
            if ($isRc) {
                // RC samovyměření + krácený nárok současně: ř. 43 (RC mirror odpočet) nemá
                // v DPHDP3 XSD krácený protějšek → nelze vykázat bez tiché chyby. Explicitní
                // stop, analogicky PostingService 'rc_partial_deduction_unsupported' (audit B1).
                throw new PostingException(
                    'rc_partial_deduction_unsupported',
                    "Doklad {$docLabel} kombinuje tuzemské samovyměření DPH (reverse charge) s kráceným "
                        . 'nárokem na odpočet dle § 76 — tuto kombinaci systém nevykazuje automaticky '
                        . '(ř. 43 nemá krácený protějšek); zaúčtuj a vykaž ji ručně.',
                );
            }
            if ($primaryLine !== null && in_array($primaryLine, ['40', '41', '42'], true)) {
                $primaryLine .= 'k';
            } elseif ($primaryLine !== null) {
                // Krácený nárok § 76 má v DPHDP3 XSD protějšek JEN u ř. 40/41/42 (sloupec
                // „Krácený odpočet"). Kdyby účetní označil 'reduced' na dokladu klasifikovaném
                // na jiný odpočtový řádek (ř. 43 mirror mimo RC, ř. 44/45 korekce, ř. 47 majetek),
                // plná daň by tiše propadla do sloupce „V plné výši" bez krácení koeficientem =
                // nadhodnocený odpočet. Konzervativně: tvrdý stop místo tiché aproximace.
                throw new PostingException(
                    'reduced_deduction_unsupported_line',
                    "Doklad {$docLabel} má krácený nárok na odpočet dle § 76, ale jeho klasifikace míří "
                        . "na řádek {$primaryLine} přiznání, který nemá krácený protějšek (krátit koeficientem "
                        . 'lze jen ř. 40/41/42). Změň klasifikaci na běžný odpočtový řádek, nebo doklad '
                        . 'zaúčtuj a vykaž ručně.',
                );
            }
        }

        $baseCzk = round($baseRaw * $rate, 2);
        // Daň u RC samovyměření = ZÁKLAD přepočtený na CZK × sazba (§ 37 odst. 1:
        // „daň se vypočte ze základu daně" — a základem je hodnota v Kč). Počítat ji
        // z cizoměnové daně přenásobené kurzem by dvojím zaokrouhlením rozešlo KH
        // oddíl A.2 a přiznání ř.3/43 o haléře (např. 305 312,26 × 21 % = 64 115,57 Kč,
        // ne 64 115,67 Kč jako round(EUR daň) × kurz). U běžných tuzemských dokladů
        // bereme skutečnou daň z dokladu přepočtenou kurzem (zpravidlo se neuplatní).
        $vatCzk = $rcSelfAssess
            ? round($baseCzk * $vatRate / 100, 2)
            : round($vatRaw * $rate, 2);
        $deductionBaseCzk = $rcSelfAssess && $isPartialDeduction
            ? round($baseCzk * $deductionRatio, 2)
            : $baseCzk;
        $deductionVatCzk = $rcSelfAssess && $isPartialDeduction
            ? round($vatCzk * $deductionRatio, 2)
            : $vatCzk;

        // S3 — snížená sazba (12 %) u samovyměřeného pořízení/služby: klasifikační kód
        // nese primární řádek pro 21 % (3/5/7/10/12) a mirror ř.43. Při skutečné snížené
        // sazbě (kladná pod prahem $bucket) přemapovat na 12% dvojče (4/6/8/11/13) a mirror
        // ř.44 — jinak by 12% pořízení z JČS vykázalo daň v 21% sloupci (ř.3) a KH oddíl A.2
        // (bucketuje přímo dle vat_rate) by se rozešel s přiznáním. Navazuje na §76 blok
        // výše (přípona „k") — obě transformace mění TÝŽ $primaryLine, na disjunktních
        // cestách (RC+krácený odpočet vyhodí výjimku), takže se nepřekrývají.
        if ($source === 'purchase' && $isRc && $vatRate > 0 && $vatRate < $bucket) {
            $reduced = ['3' => '4', '5' => '6', '7' => '8', '10' => '11', '12' => '13'];
            if ($primaryLine !== null && isset($reduced[$primaryLine])) {
                $primaryLine = $reduced[$primaryLine];
            }
            if ($secondaryLine === '43') {
                $secondaryLine = '44';
            }
        }

        // Totéž pro BĚŽNÁ tuzemská plnění: dvojice ř. 1↔2 (vystavené) a 40↔41 (přijaté).
        // Ručně zvolený kód pro základní sazbu na 12% řádku (překlep v editoru, import
        // s pevným kódem) jinak pošle základ na ř. 1/40, zatímco KH bucketuje podle
        // SKUTEČNÉ sazby a týž základ dá do 12% sloupce → přiznání a KH se rozejdou.
        // Cross-check to neodhalí, protože porovnává jen součet obou sazeb. Rozhoduje
        // sazba na řádku, ne kód: sazba je snapshot z dokladu a tu daň dodavatel opravdu
        // účtoval. Krácené klíče (přípona „k") tu být nemůžou — přiřazují se až níž.
        // Spouští se JEN při rozporu mezi sazbou klasifikace a sazbou řádku. Samotná
        // dvojice řádek/sazba nestačí: per-tenant override smí kód '40' vědomě namapovat
        // na ř. 41 (číselník to umožňuje) a takové mapování se přebít nesmí — tam sazba
        // kódu se sazbou řádku SOUHLASÍ, rozchází se až s výchozím významem řádku.
        $clsfRate = $clsf !== null && ($clsf['vat_rate'] ?? null) !== null ? (float) $clsf['vat_rate'] : null;
        $rateContradictsCode = $clsfRate !== null && $clsfRate > 0
            && (($clsfRate >= $bucket) !== ($vatRate >= $bucket));
        if (!$isRc && $vatRate > 0 && $rateContradictsCode) {
            $domesticPairs = $vatRate < $bucket
                ? ['1' => '2', '40' => '41']   // kód pro základní sazbu na sníženém řádku
                : ['2' => '1', '41' => '40'];  // a obráceně
            if ($primaryLine !== null && isset($domesticPairs[$primaryLine])) {
                $primaryLine = $domesticPairs[$primaryLine];
            }
        }

        return [
            'source'                => $source,
            'invoice_id'            => (int) $r['invoice_id'],
            'doc_number'            => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            // Vazba dobropisu na opravovanou fakturu (migrace 1096) — jen purchase SELECT je
            // exponuje; sale/cash řádky klíč nemají (?? null). Slouží jen obranné pojistce v KH
            // (KontrolniHlaseniBuilder), NEmění zařazení do sekcí ani XML výstup.
            'parent_purchase_invoice_id'   => isset($r['parent_purchase_invoice_id']) && $r['parent_purchase_invoice_id'] !== null ? (int) $r['parent_purchase_invoice_id'] : null,
            'parent_vendor_invoice_number' => isset($r['parent_vendor_invoice_number']) && $r['parent_vendor_invoice_number'] !== null ? (string) $r['parent_vendor_invoice_number'] : null,
            'status'                => (string) $r['status'],
            'is_draft'              => $r['status'] === 'draft',
            'tax_date'              => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            // Období odpočtu (§ 73) + jeho DŮVOD — issue #9. claim_date jde ze sdíleného
            // SQL výrazu, claim_basis se z něj jen ODVODÍ porovnáním se vstupními daty,
            // takže tu nevzniká druhá kopie pravidla, která by se mohla rozejít.
            'claim_date'            => self::dateOnly($r['claim_date'] ?? null),
            'claim_basis'           => $source === 'purchase' ? self::claimBasis($r) : null,
            'received_at'           => self::dateOnly($r['received_at'] ?? null),
            'received_at_source'    => isset($r['received_at_source']) && $r['received_at_source'] !== null
                ? (string) $r['received_at_source']
                : null,
            'counterparty_name'     => (string) ($r['counterparty_name'] ?? ''),
            'counterparty_dic'      => $r['counterparty_dic'] !== null ? (string) $r['counterparty_dic'] : null,
            'country_iso2'          => $r['country_iso2'] !== null ? strtoupper((string) $r['country_iso2']) : null,
            'country_is_eu'         => (bool) $r['country_is_eu'],
            'description'           => (string) ($r['description'] ?? ''),
            'label'                 => $clsf['label'] ?? '',
            'code'                  => $code,
            'dphdp3_line'           => $primaryLine,
            'dphdp3_line_secondary' => $secondaryLine,
            'kh_section'            => $clsf['kh_section'] ?? null,
            'kod_pred_pl'           => $clsf['kod_pred_pl'] ?? null,
            'kh_regime_code'         => $clsf['kh_regime_code'] ?? null,
            'kh_bad_debt'            => $clsf['kh_bad_debt'] ?? null,
            'is_reverse_charge'     => $isRc,
            // Kód nebyl na dokladu, jen odhadnut fallbackem pro zahraniční RC (24e/24).
            'code_estimated'        => !empty($r['code_estimated']),
            'vat_deduction_partial' => $isPartialDeduction,
            'vat_deduction_none'    => $isDeductionNone,
            'vat_rate'              => $vatRate,
            'currency'              => (string) $r['currency'],
            'base_czk'              => $baseCzk,
            'vat_czk'               => $vatCzk,
            'deduction_base_czk'    => $deductionBaseCzk,
            'deduction_vat_czk'     => $deductionVatCzk,
            'total_with_vat_czk'    => round((float) $r['inv_total'] * $rate, 2),
            'document_total'        => (float) $r['inv_total'],
            'is_fixed_asset'        => (bool) $r['is_fixed_asset'],
            'exchange_rate'         => $rate,
            'exchange_rate_missing' => $exchangeRateMissing,
        ];
    }

    /** Datum (DATE i DATETIME z PDO) na `YYYY-MM-DD`; prázdno → null. */
    private static function dateOnly(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : substr($s, 0, 10);
    }

    /**
     * Které datum období odpočtu reálně určilo (issue #9) — čistě POROVNÁNÍM výsledku
     * {@see purchaseClaimDateExpr()} se vstupy, ne druhou implementací pravidla.
     *
     * Pořadí odpovědí je záměrné: nejdřív DUZP, pak vystavení, teprve nakonec datum
     * přijetí. Když je datum přijetí ve shodě s dnem vystavení, je totiž **vystavení**
     * ta zajímavá informace — doklad nelze držet dřív, než vůbec vznikl (§ 73 odst. 1
     * písm. a ZDPH), takže dřívější DUZP odpočet do svého měsíce nestáhne. Uživatel,
     * který si datum přijetí ručně posune do minulosti, jinak marně hledá, proč se
     * období nehne.
     *
     * @param array<string,mixed> $r syrový řádek fetchPurchases
     * @return 'tax_date'|'issue_date'|'received_at'|null
     */
    private static function claimBasis(array $r): ?string
    {
        $claim = self::dateOnly($r['claim_date'] ?? null);
        if ($claim === null) {
            return null;
        }
        if ($claim === self::dateOnly($r['tax_date'] ?? null)) {
            return 'tax_date';
        }
        if ($claim === self::dateOnly($r['issue_date'] ?? null)) {
            return 'issue_date';
        }
        if (($r['received_at_source'] ?? null) === 'manual'
            && $claim === self::dateOnly($r['received_at'] ?? null)
        ) {
            return 'received_at';
        }
        return null;
    }

    /**
     * Daňová pojistka (issue #238): non-CZK řádek bez zafixovaného kurzu se ve
     * VatLedgeru dopočítá náhradním kurzem 1.0 → cizoměnový základ by se tiše vykázal
     * jako CZK. Vrací DISTINCT doklady (per zdroj+faktura) bez kurzu — akce si je při
     * stažení doplní z ČNB (MissingExchangeRateFiller), náhled je jen vypíše jako varování.
     *
     * @param list<array<string,mixed>> $rows kanonické řádky z rows()
     * @return list<array{invoice_id:int, source:string, currency:string, tax_date:?string, issue_date:?string, doc:string}>
     */
    public static function missingExchangeRateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (empty($r['exchange_rate_missing'])) {
                continue;
            }
            $key = (string) ($r['source'] ?? '') . ':' . (string) ($r['invoice_id'] ?? '0');
            if (isset($out[$key])) {
                continue;
            }
            $doc = (string) ($r['vendor_invoice_number'] ?? $r['doc_number'] ?? '')
                ?: ('#' . (string) ($r['invoice_id'] ?? '?'));
            $out[$key] = [
                'invoice_id' => (int) ($r['invoice_id'] ?? 0),
                'source'     => (string) ($r['source'] ?? ''),
                'currency'   => (string) ($r['currency'] ?? ''),
                'tax_date'   => isset($r['tax_date']) ? (string) $r['tax_date'] : null,
                'issue_date' => isset($r['issue_date']) ? (string) $r['issue_date'] : null,
                'doc'        => $doc,
            ];
        }
        return array_values($out);
    }

    /**
     * Popisné labely „doklad (měna)" z výstupu missingExchangeRateRows() — pro varování/chybu.
     *
     * @param list<array{doc:string, currency:string}> $missingRows
     * @return list<string>
     */
    public static function missingExchangeRateLabels(array $missingRows): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['doc'] . ' (' . $r['currency'] . ')',
            $missingRows,
        ));
    }

    /**
     * Daňové pokladní doklady (mini-epic POKLADNA #14, §4.2) — projekce do DPH
     * evidence. Jen `vat_mode='vat'` bez vazby na fakturu (úhrady faktur nesou DPH
     * na faktuře → neduplicita, R8). Tvar řádku 1:1 s fetchSales/fetchPurchases,
     * takže normalize() projde beze změny (rc=0, odpočet full, CZK). doc_type 'in'
     * → direction 'sale' (kódy 1/2), 'out' → 'purchase' (kódy 40/41). Zařazení do
     * období dle DUZP = den transakce (COALESCE(tax_date, issue_date)).
     *
     * M-9 (audit pokladny 2026-08) — `'CZK'`/`1`/`'CZ'`/`0` jsou ZÁMĚR, ne opomenutí,
     * a platí i pro VALUTOVOU pokladnu:
     *  - `currency`/`exchange_rate`: `cash_documents.total_amount` i řádky
     *    `cash_document_vat_lines` se ukládají už přepočtené na CZK (migrace 1114,
     *    `CashDocumentService::convertVatLinesToCzk`) — druhý přepočet by částky
     *    nafoukl (tatáž třída chyby jako C-1 v peněžním deníku).
     *  - `country_iso2`/`country_is_eu`: doklad s `vat_mode='vat'` nese sazbu z ČESKÉHO
     *    číselníku (`vat_rate_invalid` jinou nepustí), takže je to z konstrukce tuzemské
     *    zdanitelné plnění — hotovostní prodej přes pult, místo plnění v tuzemsku.
     *    Odvozovat zemi z partnera by takový doklad přeřadilo mezi dodání do JČS (§ 64)
     *    nebo vývoz (§ 66), tedy do osvobození, které se u prodeje za hotové neuplatní.
     *    Cizí měna sama o sobě daňový režim neurčuje. Hlídá se jen tvar DIČ protistrany
     *    (varování `cash.warning.dic_not_czech` při zaúčtování), aby se do KH A.4
     *    nedostalo cizí VAT ID vydávané za české.
     *
     * @return list<array{0: array<string,mixed>, 1: 'sale'|'purchase'}>
     */
    private function fetchCash(int $supplierId, string $start, string $end, bool $includeDrafts): array
    {
        $bucket = $this->taxConstants->vatBucketThreshold((int) substr($start, 0, 4));
        $statusFilter = $includeDrafts ? "cd.status != 'reversed'" : "cd.status = 'posted'";
        $stmt = $this->db->pdo()->prepare("
            SELECT cd.id AS invoice_id, cd.doc_number, cd.doc_number AS vendor_invoice_number,
                   'cash' AS document_kind, cd.status, cd.doc_type,
                   COALESCE(cd.tax_date, cd.issue_date) AS tax_date, cd.issue_date,
                   1 AS exchange_rate, 'CZK' AS currency,
                   cd.total_amount AS inv_total, 0 AS rc_flag,
                   cl.vat_deduction, cl.vat_deduction_percent,
                   COALESCE(cd.partner_name, '') AS counterparty_name, cd.partner_dic AS counterparty_dic,
                   'CZ' AS country_iso2, 0 AS country_is_eu,
                   -- Ř. 47 (hodnota pořízeného dlouhodobého majetku) je doplňující údaj
                   -- k odpočtu, takže dává smysl jen u VÝDAJOVÉHO dokladu; u příjmového
                   -- je to tržba, ne pořízení (migrace 1783, nález L-4).
                   CASE WHEN cd.doc_type = 'out' THEN cl.is_fixed_asset ELSE 0 END AS is_fixed_asset,
                   COALESCE(cl.vat_classification_code,
                            CASE WHEN cd.doc_type = 'in'
                                 THEN CASE WHEN cl.vat_rate >= ? THEN '1'  ELSE '2'  END
                                 ELSE CASE WHEN cl.vat_rate >= ? THEN '40' ELSE '41' END
                            END) AS code,
                   cl.vat_rate, cd.description, cl.base_amount AS base, cl.vat_amount AS vat
              FROM cash_documents cd
              JOIN cash_document_vat_lines cl ON cl.cash_document_id = cd.id
             WHERE cd.supplier_id = ?
               AND cd.vat_mode = 'vat'
               AND cd.invoice_id IS NULL AND cd.purchase_invoice_id IS NULL
               -- Rozsah nároku na odpočet (migrace 1120) platí i pro pokladnu: VPD za
               -- reprezentaci označený jako bez nároku na odpočet nesmí do evidence, jinak by si uživatel
               -- odečetl daň, kterou u téhož dokladu v podobě přijaté faktury nedostane
               -- (tentýž filtr má fetchPurchases). Výdejka = přijaté plnění (doc_type='out');
               -- u příjmového dokladu je to tržba a nárok na odpočet se na ni nevztahuje.
               -- Výjimka pro reverse charge tu není potřeba: hotovostní doklad je
               -- z konstrukce tuzemské zdanitelné plnění (rc_flag = 0, viz komentář výše).
               AND (cd.doc_type = 'in' OR cl.vat_deduction <> 'none')
               AND {$statusFilter}
               AND COALESCE(cd.tax_date, cd.issue_date) BETWEEN ? AND ?
          ORDER BY COALESCE(cd.tax_date, cd.issue_date), cd.id, cl.id
        ");
        $stmt->execute([$bucket, $bucket, $supplierId, $start, $end]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $direction = $r['doc_type'] === 'in' ? 'sale' : 'purchase';
            $out[] = [$r, $direction];
        }
        return $out;
    }
}
