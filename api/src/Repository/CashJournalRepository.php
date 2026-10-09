<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Peněžní deník daňové evidence (Epic DE, A2) — READ-ONLY agregátor kasové báze.
 *
 * NIKDY nemutuje cash_documents / bank_transactions / bank_statements / invoice /
 * payment tabulky (pravidlo 1020 R1). Skládá tři nohy pohybů (§4.1 spec):
 *   A) cash_documents (status='posted')        — fyzická hotovost, self-klasifikace přes purpose
 *   B) bank_transactions                        — fyzická banka; tenant scoping NE přes supplier_id
 *      (sloupec neexistuje, R4), ale přes shodu bank_statements.account_number s účty daného
 *      supplieru v currencies (AccountNumberNormalizer::matchesAny — přesně jako StatementMatcher)
 *   C) virtuální úhrady bez fyzického dokladu   — invoice_payments (source manual/mark_paid/legacy)
 *      a ručně zaplacené purchase_invoices bez payment_matches i bez cash dokladu (R2);
 *      úhrada s referencí GOPAY: u firmy s nastaveným GoPay patří peněžnímu prostředku GoPay
 *   G) pohyby vyúčtování GoPay mimo spárované platby (poplatky, vratky, výplata na účet)
 *
 * Každý řádek nese `instrument` (cash / bank / gopay / virtual), po kterém se dá
 * sesouhlasit zůstatek jednotlivého peněžního prostředku.
 *
 * Dedup (R3): invoice_payments source IN ('bank','cash') a payment_matches jsou ANOTACE
 * pohybu nohy A/B (přes cash_documents.invoice_payment_id, resp. bank_transaction_id) —
 * do řádků deníku NEvstupují jako samostatné řádky. Noha C je proto omezena na
 * source IN ('manual','mark_paid','legacy'); noha B je jimi jen LEFT-JOIN klasifikována.
 *
 * Klasifikace §7b/§23 probíhá v CashJournalService — repo vrací jen surové vstupy
 * (purpose, DPH split z cash_document_vat_lines, invoice/PF vazby, override z 1027).
 * Běžný zůstatek přes SQL window nad UNIONem; opening_balance = pohyby před `from`.
 */
final class CashJournalRepository
{
    /** Ochranný strop stránkování (řádky na dotaz). */
    private const MAX_LIMIT = 5000;

    public function __construct(private readonly Connection $db) {}

    /**
     * Řádky deníku v rozsahu [from, to] s běžnou deltou (running_delta) přes SQL window.
     * running_balance = openingBalance(from) + running_delta (dopočítá service).
     *
     * @return list<array<string,mixed>>
     */
    public function movements(int $supplierId, string $from, string $to, bool $isVatPayer = false, ?int $limit = null, int $offset = 0): array
    {
        $union = $this->unionSql($supplierId, $this->rangePredicate($from, $to), $isVatPayer);
        if ($union === null) {
            return [];
        }
        $limitClause = '';
        if ($limit !== null) {
            $lim = max(1, min(self::MAX_LIMIT, $limit));
            $off = max(0, $offset);
            $limitClause = " LIMIT {$lim} OFFSET {$off}";
        }
        $sql =
            "SELECT * FROM (
                SELECT t.*,
                       SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END)
                         OVER (ORDER BY t.movement_date, t.source_type, t.source_id
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_delta
                  FROM ( {$union} ) t
             ) w
             ORDER BY w.movement_date, w.source_type, w.source_id{$limitClause}";

        $stmt = $this->db->pdo()->query($sql);
        return array_map([$this, 'castRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Otevírací zůstatek k datu `from` = signed součet všech pohybů (tří noh) s
     * movement_date < from. Tentýž tenant scoping i dedup jako movements().
     */
    public function openingBalance(int $supplierId, string $from, bool $isVatPayer = false): float
    {
        $union = $this->unionSql($supplierId, $this->beforePredicate($from), $isVatPayer);
        if ($union === null) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END), 0)
                  FROM ( {$union} ) t";
        return round((float) $this->db->pdo()->query($sql)->fetchColumn(), 2);
    }

    /**
     * Otevírací zůstatek k datu `from` po peněžních prostředcích (cash, bank, gopay,
     * virtual = úhrady bez fyzického pohybu). Součet hodnot = openingBalance().
     *
     * @return array<string,float>
     */
    public function openingBalancesByInstrument(int $supplierId, string $from, bool $isVatPayer = false): array
    {
        $union = $this->unionSql($supplierId, $this->beforePredicate($from), $isVatPayer);
        if ($union === null) {
            return [];
        }
        $sql = "SELECT t.instrument, COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END), 0) AS balance
                  FROM ( {$union} ) t
                 GROUP BY t.instrument";
        $out = [];
        foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['instrument']] = round((float) $row['balance'], 2);
        }
        return $out;
    }

    /** Počet řádků deníku v rozsahu (pro stránkování). */
    public function count(int $supplierId, string $from, string $to, bool $isVatPayer = false): int
    {
        $union = $this->unionSql($supplierId, $this->rangePredicate($from, $to), $isVatPayer);
        if ($union === null) {
            return 0;
        }
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM ( {$union} ) t")->fetchColumn();
    }

    // ── UNION builder ────────────────────────────────────────────────────────

    /**
     * Sestaví UNION ALL tří noh se sdílenou projekcí sloupců. `$datePredicate` je
     * `sprintf` template s jedním `%s` pro datový sloupec konkrétní nohy
     * (např. "%s BETWEEN '2099-01-01' AND '2099-12-31'"). supplier_id a statement id
     * jsou inline int (validované), datumy inline validované regexem — EMULATE_PREPARES
     * je false, tak se vyhýbáme reuse pojmenovaných parametrů. Vrací NULL, pokud by
     * union neměl žádnou nohu (nemělo by nastat — leg A/C jsou vždy přítomné).
     */
    private function unionSql(int $supplierId, string $datePredicate, bool $isVatPayer = false): ?string
    {
        $sid = $supplierId; // int, bezpečné pro inline
        $vp = $isVatPayer ? 1 : 0; // literál pro DPH prorata v agregacích (R7)
        $payerAtIp = VatStatusService::payerAtExpr((string) $sid, 'ip.paid_on', (string) $vp);
        $payerAtBank = VatStatusService::payerAtExpr((string) $sid, 'bt2.posted_at', (string) $vp);
        $legs = [];

        // ── Noha A — hotovost (cash_documents posted) ────────────────────────
        // POZOR: cash_documents.total_amount i cash_document_vat_lines jsou v DB už v CZK
        // (CashDocumentService::resolveCurrency() / convertVatLinesToCzk() přepočítají
        // valutový doklad kurzem PŘED uložením, viz migrace 1114). Kurzem se tu proto
        // NENÁSOBÍ — jinak vznikne dvojí přepočet.
        $legs[] =
            "SELECT 'cash' AS source_type, cd.id AS source_id, cd.issue_date AS movement_date,
                    cd.doc_type AS direction, ROUND(cd.total_amount, 2) AS amount,
                    COALESCE(cd.doc_number, '') AS doc_no, COALESCE(cd.partner_name, '') AS partner,
                    COALESCE(cd.description, '') AS description,
                    cd.purpose AS cash_purpose, cav.base_sum AS cash_vat_base, cav.vat_sum AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    cd.invoice_id AS invoice_id, ci.invoice_type AS inv_type,
                    ci.total_without_vat AS inv_without_vat, ci.total_vat AS inv_vat,
                    ci.total_with_vat AS inv_with_vat,
                    ci.income_tax_exempt AS inv_exempt, ci.status AS inv_status,
                    cd.purchase_invoice_id AS purchase_invoice_id,
                    cpi.total_without_vat AS pi_without_vat, cpi.total_vat AS pi_vat,
                    cpi.total_with_vat AS pi_with_vat,
                    cpi.tax_deductible AS pi_deductible, cpi.document_kind AS pi_kind,
                    cpi.vat_deduction AS pi_vat_deduction, cpi.vat_deduction_percent AS pi_vat_deduction_percent,
                    cpi.is_fixed_asset AS pi_is_fixed_asset,
                    ccls.tax_bucket AS override_bucket,
                    " . self::otherItemKindSql($sid, 'cash_document_id', 'cd.id') . " AS other_item_kind,
                    " . self::otherItemAllocatedSql($sid, 'cash_document_id', 'cd.id') . " AS other_item_allocated,
                    'cash' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type
               FROM cash_documents cd
               LEFT JOIN (SELECT cash_document_id,
                                  SUM(CASE WHEN tax_treatment = 'deductible'
                                           THEN base_amount + vat_amount *
                                                (1 - CASE vat_deduction
                                                       WHEN 'full' THEN 1
                                                       WHEN 'none' THEN 0
                                                       ELSE vat_deduction_percent / 100 END)
                                           ELSE 0 END) AS base_sum,
                                  SUM(base_amount + vat_amount -
                                      CASE WHEN tax_treatment = 'deductible'
                                           THEN base_amount + vat_amount *
                                                (1 - CASE vat_deduction
                                                       WHEN 'full' THEN 1
                                                       WHEN 'none' THEN 0
                                                       ELSE vat_deduction_percent / 100 END)
                                           ELSE 0 END) AS vat_sum
                            FROM cash_document_vat_lines GROUP BY cash_document_id) cav
                      ON cav.cash_document_id = cd.id
               LEFT JOIN invoices ci          ON ci.id = cd.invoice_id AND ci.supplier_id = {$sid}
               LEFT JOIN purchase_invoices cpi ON cpi.id = cd.purchase_invoice_id AND cpi.supplier_id = {$sid}
               LEFT JOIN de_movement_classification ccls
                      ON ccls.supplier_id = {$sid} AND ccls.source_type = 'cash' AND ccls.cash_document_id = cd.id
              WHERE cd.supplier_id = {$sid} AND cd.status = 'posted'
                AND " . sprintf($datePredicate, 'cd.issue_date');

        // ── Noha B — banka (tenant scoping přes account-number match, R4) ─────
        // Příjem/výdaj se počítá AGREGOVANĚ per bank_transaction_id (jeden bankovní pohyb =
        // jeden řádek deníku, i když settluje N faktur/PF — #89 split), převedeno na CZK
        // (H1). Dedup vůči noze C: fyzická naba-B nese příjem, když existuje invoice_payments
        // s bank_transaction_id = bt.id (JAKÝKOLI source — bank/split/rekonciliovaná manual);
        // legacy 1:1 (jen matched_invoice_id bez ip) je stále příjem (§4.2). alreadyPaid
        // (matched_invoice_id + virtuální C1 platba bez fyz. vazby) se z nohy B VYPUSTÍ, ať
        // se receipt nezapočte 2× (počítá ho noha C1).
        $stmtIds = $this->matchingStatementIds($supplierId);
        if ($stmtIds !== []) {
            $inList = implode(',', array_map('intval', $stmtIds));
            // Kurz na CZK pro nespárované / poplatkové pohyby: rate z exchange_rates ke dni
            // pohybu (nejbližší předchozí); CZK a neznámá měna → 1 (nominál, dokumentovaný fallback).
            $feeRate = "IF(COALESCE(NULLIF(bt.currency, ''), 'CZK') = 'CZK', 1,
                               " . self::nearestRateSql('bt.currency', 'bt.posted_at') . ')';
            // 8a: pm.amount je uložen v měně BANKOVNÍHO POHYBU (StatementMatcher ukládá $absAmount
            // v měně bt), NE v měně PF. Převod na CZK proto musí jít přes kurz měny bankovního
            // pohybu (mirror $feeRate, jen nad bt2 uvnitř agregace), ne přes pi.exchange_rate —
            // jinak by se CZK banka platící EUR PF nadhodnotila ~24×. Daňový poměr (bez/s DPH) je
            // bezrozměrný a bere se dál z PF.
            $btRate = "IF(COALESCE(NULLIF(bt2.currency, ''), 'CZK') = 'CZK', 1,
                              " . self::nearestRateSql('bt2.currency', 'bt2.posted_at') . ')';
            // G4: kurz inkasa faktury k DATU ÚHRADY (ip.paid_on), ne k datu vystavení
            // faktury (i.exchange_rate je zafixovaný při vystavení — kasová báze vyžaduje
            // kurz ke dni skutečného peněžního toku). Fallback na kurz faktury, když pro
            // dané datum/měnu chybí záznam v exchange_rates (NE tichý fallback na 1:1).
            $ipRateBank = $this->ipRateSql('i');
            $legs[] =
                "SELECT 'bank' AS source_type, bt.id AS source_id, bt.posted_at AS movement_date,
                        CASE WHEN bt.amount >= 0 THEN 'in' ELSE 'out' END AS direction,
                        ROUND(COALESCE(binc.czk_amount, bexp.czk_amount, ABS(bt.amount) * {$feeRate}), 2) AS amount,
                        COALESCE(NULLIF(bt.variable_symbol, ''), bt.bank_ref, '') AS doc_no,
                        COALESCE(bt.counterparty_name, '') AS partner,
                        COALESCE(bt.description, '') AS description,
                        NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                        CASE WHEN binc.btid IS NOT NULL THEN 'income'
                             WHEN bexp.btid IS NOT NULL THEN 'expense'
                             ELSE NULL END AS bank_class,
                        binc.czk_base AS bank_income_base, binc.czk_exempt AS bank_income_exempt,
                        bi.id AS invoice_id,
                        bi.invoice_type AS inv_type,
                        bi.total_without_vat AS inv_without_vat, bi.total_vat AS inv_vat,
                        bi.total_with_vat AS inv_with_vat,
                        bi.income_tax_exempt AS inv_exempt, bi.status AS inv_status,
                        bexp.any_pi_id AS purchase_invoice_id,
                        bpi.total_without_vat AS pi_without_vat, bpi.total_vat AS pi_vat,
                        bpi.total_with_vat AS pi_with_vat,
                        bpi.tax_deductible AS pi_deductible, bpi.document_kind AS pi_kind,
                        bpi.vat_deduction AS pi_vat_deduction, bpi.vat_deduction_percent AS pi_vat_deduction_percent,
                        bpi.is_fixed_asset AS pi_is_fixed_asset,
                        bcls.tax_bucket AS override_bucket,
                        " . self::otherItemKindSql($sid, 'bank_transaction_id', 'bt.id') . " AS other_item_kind,
                        " . self::otherItemAllocatedSql($sid, 'bank_transaction_id', 'bt.id') . " AS other_item_allocated,
                        'bank' AS instrument,
                        (SELECT MIN(gc.id) FROM gopay_clearings gc
                          WHERE gc.supplier_id = {$sid} AND gc.bank_transaction_id = bt.id) AS gopay_clearing_id,
                        NULL AS gopay_type
                   FROM bank_transactions bt
                   LEFT JOIN (
                        SELECT ip.bank_transaction_id AS btid,
                               SUM(ROUND(ip.amount * {$ipRateBank}, 2)) AS czk_amount,
                               -- Paid gross contains rounding. Only the stored VAT is
                               -- excluded from income (same rule as CashJournalService::prorateBase).
                               SUM(ROUND(CASE WHEN i.income_tax_exempt = 1 THEN 0
                                              WHEN {$payerAtIp} = 1 AND i.total_with_vat <> 0
                                                   THEN ip.amount * (1 - LEAST(1, GREATEST(0, i.total_vat / i.total_with_vat)))
                                              ELSE ip.amount END
                                         * {$ipRateBank}, 2)) AS czk_base,
                               -- Osvobozená noha se dělí na základ/DPH STEJNĚ jako zdanitelná
                               -- vedle ní (#52): u plátce DPH je osvobozený PŘÍJEM částka bez
                               -- DPH — DPH z takové faktury je průběžná položka státu, ne příjem
                               -- poplatníka. Zbytek (amount − czk_base − czk_exempt) padne do
                               -- income_nontax v CashJournalService::bankIncomeAlloc().
                               SUM(ROUND(CASE WHEN i.income_tax_exempt <> 1 THEN 0
                                              WHEN {$payerAtIp} = 1 AND i.total_with_vat <> 0
                                                   THEN ip.amount * (1 - LEAST(1, GREATEST(0, i.total_vat / i.total_with_vat)))
                                              ELSE ip.amount END
                                         * {$ipRateBank}, 2)) AS czk_exempt,
                               MIN(ip.invoice_id) AS any_invoice_id
                          FROM invoice_payments ip
                          JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = {$sid}
                         WHERE ip.supplier_id = {$sid} AND ip.bank_transaction_id IS NOT NULL
                           AND i.status <> 'cancelled'
                         GROUP BY ip.bank_transaction_id
                   ) binc ON binc.btid = bt.id
                   -- Výdajová strana nese už JEN celkovou částku a vazbu na doklad. Daňový
                   -- základ počítá TaxExpenseAllocationCalculator::forBankPayment()
                   -- (CashJournalService::classify → bankExpenseAlloc), ne tohle SQL.
                   -- Dřív tu byl i sloupec czk_base s vlastní kopií pravidel (filtr na
                   -- advance/tax_document, prorata dle vat_deduction) — po přechodu na
                   -- kalkulátor ho ale nikdo nečetl. Zůstal jako mrtvý kód, který navíc
                   -- SVÁDĚL k závěru, že bankovní noha zálohy vylučuje — reálná cesta je
                   -- takhle nevylučuje. Odstraněn, ať se ta past neopakuje; příjmová
                   -- strana (binc.czk_base) používaná JE.
                   LEFT JOIN (
                        SELECT pm.bank_transaction_id AS btid,
                               SUM(ROUND(pm.amount * {$btRate}, 2)) AS czk_amount,
                               MIN(pm.purchase_invoice_id) AS any_pi_id
                          FROM payment_matches pm
                          JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = {$sid}
                          JOIN bank_transactions bt2 ON bt2.id = pm.bank_transaction_id
                         WHERE pm.supplier_id = {$sid} AND pm.purchase_invoice_id IS NOT NULL
                         GROUP BY pm.bank_transaction_id
                   ) bexp ON bexp.btid = bt.id
                   LEFT JOIN invoices bi
                          ON bi.id = COALESCE(binc.any_invoice_id,
                                              CASE WHEN bexp.btid IS NULL THEN bt.matched_invoice_id END)
                         AND bi.supplier_id = {$sid} AND bi.status <> 'cancelled'
                   LEFT JOIN purchase_invoices bpi ON bpi.id = bexp.any_pi_id AND bpi.supplier_id = {$sid}
                   LEFT JOIN de_movement_classification bcls
                          ON bcls.supplier_id = {$sid} AND bcls.source_type = 'bank' AND bcls.bank_transaction_id = bt.id
                  WHERE bt.statement_id IN ({$inList})
                    AND NOT (
                          binc.btid IS NULL AND bexp.btid IS NULL AND bt.matched_invoice_id IS NOT NULL
                          AND EXISTS (SELECT 1 FROM invoice_payments y
                                        JOIN invoices yi ON yi.id = y.invoice_id AND yi.supplier_id = {$sid}
                                                        AND yi.status <> 'cancelled'
                                       WHERE y.invoice_id = bt.matched_invoice_id AND y.supplier_id = {$sid}
                                         AND y.bank_transaction_id IS NULL
                                         AND y.source IN ('manual', 'mark_paid', 'legacy'))
                    )
                    AND " . sprintf($datePredicate, 'bt.posted_at');
        }

        // ── Noha C1 — virtuální příjmy (invoice_payments bez fyzického dokladu) ─
        // Dedup (C1): jen platby BEZ bankovní vazby (bank_transaction_id IS NULL) — rekonciliovaná
        // manual platba (bt_id doplněný) i split (source='bank') nese fyzická noha B. Zrušené
        // faktury (M1) se vylučují.
        // G4: stejný princip jako u nohy B — kurz ke dni úhrady (ip.paid_on), fallback
        // na kurz faktury (ii.exchange_rate) místo tichého 1:1.
        $ipRateC1 = $this->ipRateSql('ii');
        $legs[] =
            "SELECT 'invoice_payment' AS source_type, ip.id AS source_id, ip.paid_on AS movement_date,
                    'in' AS direction,
                    ROUND(ip.amount * {$ipRateC1}, 2) AS amount,
                    COALESCE(ii.varsymbol, '') AS doc_no, COALESCE(icl.company_name, '') AS partner,
                    COALESCE(ip.note, '') AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    ip.invoice_id AS invoice_id, ii.invoice_type AS inv_type,
                    ii.total_without_vat AS inv_without_vat, ii.total_vat AS inv_vat,
                    ii.total_with_vat AS inv_with_vat,
                    ii.income_tax_exempt AS inv_exempt, ii.status AS inv_status,
                    NULL AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    NULL AS pi_deductible, NULL AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    CASE WHEN ip.bank_reference LIKE BINARY '" . \MyInvoice\Service\Accounting\GoPay\GoPayPendingService::REFERENCE_PREFIX . "%'
                              AND EXISTS (SELECT 1 FROM gopay_settings gs
                                           WHERE gs.supplier_id = {$sid} AND gs.currency = ip.currency)
                         THEN 'gopay' ELSE 'virtual' END AS instrument,
                    NULL AS gopay_clearing_id, NULL AS gopay_type
               FROM invoice_payments ip
               JOIN invoices ii      ON ii.id = ip.invoice_id AND ii.supplier_id = {$sid}
               LEFT JOIN clients icl ON icl.id = ii.client_id
              WHERE ip.supplier_id = {$sid} AND ip.source IN ('manual', 'mark_paid', 'legacy')
                AND ip.bank_transaction_id IS NULL
                AND ii.status <> 'cancelled'
                AND " . sprintf($datePredicate, 'ip.paid_on');

        // ── Noha C2 — virtuální výdaje (ručně zaplacené PF bez fyz. vazby) ────
        // Zápočet dobropisu (1988) není peněžní tok: dobropis vyrovnaný zápočtem se
        // nezapisuje vůbec a faktura jen svým zbytkem, který se skutečně zaplatil.
        $cnOffsets = "COALESCE((SELECT SUM(cno.amount) FROM credit_note_offsets cno
                                 WHERE cno.supplier_id = pi.supplier_id AND cno.doc_type = 'purchase_invoice'
                                   AND cno.invoice_id = pi.id), 0)";
        $legs[] =
            "SELECT 'purchase_invoice' AS source_type, pi.id AS source_id, pi.paid_at AS movement_date,
                    'out' AS direction,
                    ROUND((COALESCE(pi.amount_to_pay, pi.total_with_vat) - {$cnOffsets}) * {$this->piRateSql()}, 2) AS amount,
                    COALESCE(pi.vendor_invoice_number, '') AS doc_no, COALESCE(pv.company_name, '') AS partner,
                    '' AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    NULL AS invoice_id, NULL AS inv_type, NULL AS inv_without_vat, NULL AS inv_vat, NULL AS inv_with_vat,
                    NULL AS inv_exempt, NULL AS inv_status,
                    pi.id AS purchase_invoice_id, pi.total_without_vat AS pi_without_vat, pi.total_vat AS pi_vat,
                    pi.total_with_vat AS pi_with_vat, pi.tax_deductible AS pi_deductible,
                    pi.document_kind AS pi_kind, pi.vat_deduction AS pi_vat_deduction,
                    pi.vat_deduction_percent AS pi_vat_deduction_percent,
                    pi.is_fixed_asset AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'virtual' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type
               FROM purchase_invoices pi
               LEFT JOIN currencies pcur ON pcur.id = pi.currency_id
               LEFT JOIN clients pv      ON pv.id = pi.vendor_id
              WHERE pi.supplier_id = {$sid} AND pi.paid_at IS NOT NULL
                AND pi.status <> 'cancelled'
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id)
                AND NOT EXISTS (SELECT 1 FROM cash_documents cd2
                                 WHERE cd2.purchase_invoice_id = pi.id AND cd2.status = 'posted')
                AND NOT EXISTS (SELECT 1 FROM credit_note_offsets cno2
                                 WHERE cno2.doc_type = 'purchase_invoice' AND cno2.credit_note_id = pi.id)
                AND NOT ({$cnOffsets} > 0 AND ABS(COALESCE(pi.amount_to_pay, pi.total_with_vat) - {$cnOffsets}) <= 0.005)
                AND " . sprintf($datePredicate, 'pi.paid_at');

        // ── Noha G — GoPay jako samostatný peněžní prostředek (pohyby vyúčtování) ──
        // Příjem z platby kartou nebo převodem přes GoPay nese úhrada faktury (noha C1,
        // instrument 'gopay') ke dni inkasa. Z vyúčtování se proto bere všechno OSTATNÍ:
        // poplatky (daňový výdaj ke dni srážky), dobropis poplatků, vratka k dobropisu
        // (snižuje příjem), výplata na bankovní účet (převod, protějšek bankovního pohybu
        // spárovaného s vyúčtováním) a kreditní pohyb, který se nespároval s úhradou faktury
        // (nezařazený příjem, blokuje uzávěrku). Spárovaný kreditní pohyb tu NENÍ: jeho
        // příjem už je v noze C1, započetl by se dvakrát.
        $gopayRate = "IF(gc.currency = 'CZK', 1, " . self::nearestRateSql('gc.currency', 'gm.performed_on') . ')';
        $legs[] =
            "SELECT 'gopay' AS source_type, gm.id AS source_id, gm.performed_on AS movement_date,
                    CASE WHEN gm.movement_type IN ('credit', 'fee_credit') THEN 'in' ELSE 'out' END AS direction,
                    ROUND(ABS(gm.amount) * {$gopayRate}, 2) AS amount,
                    COALESCE(gcn.varsymbol, gc.clearing_id) AS doc_no,
                    'GoPay' AS partner,
                    CASE gm.movement_type
                         WHEN 'credit'       THEN 'GoPay: platba bez spárované úhrady faktury'
                         WHEN 'storno'       THEN 'GoPay: vratka k dobropisu'
                         WHEN 'storno_fee'   THEN 'GoPay: poplatek za vratku'
                         WHEN 'clearing_fee' THEN 'GoPay: poplatky za zpracování plateb'
                         WHEN 'fee_credit'   THEN 'GoPay: dobropis poplatků'
                         WHEN 'payout'       THEN 'GoPay: výplata na bankovní účet'
                    END AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    gcn.id AS invoice_id, gcn.invoice_type AS inv_type,
                    gcn.total_without_vat AS inv_without_vat, gcn.total_vat AS inv_vat,
                    gcn.total_with_vat AS inv_with_vat,
                    gcn.income_tax_exempt AS inv_exempt, gcn.status AS inv_status,
                    NULL AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    NULL AS pi_deductible, NULL AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'gopay' AS instrument, gc.id AS gopay_clearing_id, gm.movement_type AS gopay_type
               FROM gopay_movements gm
               JOIN gopay_clearings gc ON gc.id = gm.clearing_id AND gc.supplier_id = {$sid}
               LEFT JOIN invoices gcn
                      ON gcn.id = gm.credit_note_id AND gcn.supplier_id = {$sid}
                     AND gm.movement_type = 'storno' AND gm.status = 'posted'
              WHERE gm.supplier_id = {$sid}
                AND NOT (gm.movement_type = 'credit' AND gm.status = 'posted' AND gm.invoice_payment_id IS NOT NULL)
                AND " . sprintf($datePredicate, 'gm.performed_on');

        return $legs === [] ? null : implode("\nUNION ALL\n", $legs);
    }

    /**
     * G4: SQL fragment kurzu invoice_payments řádku (ip) ke dni ÚHRADY (ip.paid_on),
     * ne ke dni vystavení faktury. `$invoiceAlias` je alias JOINnuté `invoices` tabulky
     * v daném kontextu (fallback na její `exchange_rate`, když pro ip.currency/paid_on
     * není v exchange_rates žádný záznam). Explicitní `IF(currency='CZK', 1, …)` (mirror
     * PostingService::fxRate) místo spoléhání na COALESCE větev — kdyby import omylem
     * uložil u CZK faktury nenulový exchange_rate, nesmí se jím CZK úhrada vynásobit.
     */
    private function ipRateSql(string $invoiceAlias): string
    {
        return "IF(COALESCE(NULLIF(ip.currency, ''), 'CZK') = 'CZK', 1,
                    COALESCE(" . self::backwardRateSql('ip.currency', 'ip.paid_on')
                        . ", {$invoiceAlias}.exchange_rate, "
                        . self::forwardRateSql('ip.currency', 'ip.paid_on') . '))';
    }

    private function piRateSql(): string
    {
        return "IF(COALESCE(pcur.code, 'CZK') = 'CZK', 1,
                    COALESCE(" . self::backwardRateSql('pcur.code', 'pi.paid_at')
                        . ', pi.exchange_rate, '
                        . self::forwardRateSql('pcur.code', 'pi.paid_at') . '))';
    }

    /**
     * Kurz „nejbližší známý" pro výrazy, které nemají čím dalším padnout (bankovní
     * poplatek / nespárovaný pohyb nevisí na dokladu, takže tu není žádný
     * `exchange_rate` do zálohy).
     */
    private static function nearestRateSql(string $currencyExpr, string $dateExpr): string
    {
        return 'COALESCE(' . self::backwardRateSql($currencyExpr, $dateExpr)
            . ', ' . self::forwardRateSql($currencyExpr, $dateExpr) . ')';
    }

    /**
     * Poslední kurz vyhlášený K ROZHODNÉMU DNI (nejbližší předchozí) — jediná
     * varianta, která odpovídá § 4 ZoÚ, a proto vždy první v pořadí.
     */
    private static function backwardRateSql(string $currencyExpr, string $dateExpr): string
    {
        return "(SELECT er.rate FROM exchange_rates er
                   WHERE er.currency_code = {$currencyExpr} AND er.rate_date <= {$dateExpr}
                  ORDER BY er.rate_date DESC LIMIT 1)";
    }

    /**
     * Nouzový kurz z nejbližšího POZDĚJŠÍHO dne. Použije se, až když ke dni pohybu
     * není žádný starší kurz ani kurz na dokladu — typicky u pohybu staršího, než
     * kam sahá kurzová historie instalace (#28: čerstvý tenant má v `exchange_rates`
     * jen dnešek, ale zaeviduje loňskou úhradu).
     *
     * Kurz sousedního dne je od vyhlášeného v řádu desetin procenta; vypustit pohyb
     * z daňového základu úplně (dřívější chování: NULL → výjimka → 500 na celý deník)
     * je proti tomu chyba o celou jeho hodnotu. Přiblížení není tiché —
     * {@see \MyInvoice\Service\TaxEvidence\CashJournalService} ho hlásí ve `warnings[]`
     * a zároveň se pokusí pravý kurz dotáhnout z ČNB, takže při dalším otevření
     * deníku je řádek oceněný správně.
     */
    private static function forwardRateSql(string $currencyExpr, string $dateExpr): string
    {
        return "(SELECT er.rate FROM exchange_rates er
                   WHERE er.currency_code = {$currencyExpr} AND er.rate_date > {$dateExpr}
                  ORDER BY er.rate_date ASC LIMIT 1)";
    }

    /**
     * Dvojice (měna, den), pro které deník do `$to` nemá čím ocenit cizoměnový pohyb:
     * v `exchange_rates` k tomu dni není ani žádný STARŠÍ kurz dané měny. `forward_date`
     * je nejbližší pozdější známý den téže měny (nouzové ocenění, viz forwardRateSql),
     * nebo NULL, když o měně nevíme vůbec nic.
     *
     * Podklad pro dotažení kurzu za běhu ({@see \MyInvoice\Service\TaxEvidence\CashJournalService}):
     * seznam je krátký (jen dny, které opravdu chybí) a po doplnění kurzů zůstane prázdný,
     * takže na zaplněné historii je to jeden levný dotaz navíc.
     *
     * Rozsah je ZÁMĚRNĚ `<= $to`, ne jen zobrazené období — pohyby před `from` vstupují
     * do počátečního zůstatku, a ten by jinak neoceněné pohyby tiše přeskočil.
     *
     * @return list<array{currency:string, date:string, forward_date:?string}>
     */
    public function unpricedCurrencyDays(int $supplierId, string $to, int $limit = 25): array
    {
        $sid = $supplierId;
        $toDate = $this->assertDate($to);
        $lim = max(1, min(self::MAX_LIMIT, $limit));

        $sources = [
            "SELECT COALESCE(NULLIF(ip.currency, ''), 'CZK') AS c, ip.paid_on AS d
               FROM invoice_payments ip
              WHERE ip.supplier_id = {$sid} AND ip.paid_on <= '{$toDate}'",
            "SELECT COALESCE(pcur.code, 'CZK') AS c, pi.paid_at AS d
               FROM purchase_invoices pi
               LEFT JOIN currencies pcur ON pcur.id = pi.currency_id
              WHERE pi.supplier_id = {$sid} AND pi.paid_at IS NOT NULL AND pi.paid_at <= '{$toDate}'
                AND pi.status <> 'cancelled'",
            "SELECT gc.currency AS c, gm.performed_on AS d
               FROM gopay_movements gm
               JOIN gopay_clearings gc ON gc.id = gm.clearing_id AND gc.supplier_id = {$sid}
              WHERE gm.supplier_id = {$sid} AND gm.performed_on <= '{$toDate}'",
        ];
        $stmtIds = $this->matchingStatementIds($supplierId);
        if ($stmtIds !== []) {
            $inList = implode(',', array_map('intval', $stmtIds));
            $sources[] = "SELECT COALESCE(NULLIF(bt.currency, ''), 'CZK') AS c, bt.posted_at AS d
                            FROM bank_transactions bt
                           WHERE bt.statement_id IN ({$inList}) AND bt.posted_at <= '{$toDate}'";
        }

        $sql = 'SELECT k.c AS currency, k.d AS `date`,
                       (SELECT MIN(er.rate_date) FROM exchange_rates er
                         WHERE er.currency_code = k.c AND er.rate_date > k.d) AS forward_date
                  FROM ( ' . implode("\nUNION\n", $sources) . " ) k
                 WHERE k.c <> 'CZK' AND k.d IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM exchange_rates er
                                    WHERE er.currency_code = k.c AND er.rate_date <= k.d)
                 ORDER BY k.d
                 LIMIT {$lim}";

        $out = [];
        foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'currency'     => (string) $row['currency'],
                'date'         => (string) $row['date'],
                'forward_date' => $row['forward_date'] === null ? null : (string) $row['forward_date'],
            ];
        }
        return $out;
    }

    /**
     * ID bankovních výpisů daného supplieru — bank_statements.account_number matchnuté
     * proti currencies(account_number, iban) přes AccountNumberNormalizer::matchesAny
     * (přesně jako StatementMatcher, R4). NIKDY se nepoužívá bank_transactions.supplier_id
     * (sloupec neexistuje). Prázdné pole = supplier nemá spárovaný žádný výpis → noha B odpadá.
     *
     * Public (G2): {@see \MyInvoice\Repository\MovementClassificationRepository} ji
     * potřebuje pro stejný tenant-scope check před zápisem ruční klasifikace (1027)
     * bankovního pohybu — de_movement_classification na bank_transaction_id nemá FK
     * (viz komentář migrace 1027), takže se tenant hlídá stejně jako tady.
     *
     * @return list<int>
     */
    public function matchingStatementIds(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $allAccounts = $pdo->query(
            'SELECT supplier_id, account_number, bank_code, iban FROM currencies
              WHERE supplier_id IS NOT NULL AND (account_number IS NOT NULL OR iban IS NOT NULL)'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        $statements = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM bank_statements
              WHERE account_number IS NOT NULL AND account_number <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($statements as $s) {
            if ($s['supplier_id'] !== null) {
                if ((int) $s['supplier_id'] === $supplierId) {
                    $ids[] = (int) $s['id'];
                }
                continue;
            }
            $stmtAccount = (string) $s['account_number'];
            // LOW: prázdná normalizovaná forma ('0'/'-'/'0000' → '') by planě matchla jiný blank účet.
            if (AccountNumberNormalizer::normalize($stmtAccount) === '') {
                continue;
            }
            $candidateOwners = [];
            foreach ($allAccounts as $acc) {
                $accNo = isset($acc['account_number']) && is_string($acc['account_number']) ? $acc['account_number'] : null;
                $iban  = isset($acc['iban']) && is_string($acc['iban']) ? $acc['iban'] : null;
                $statementBank = trim((string) ($s['bank_code'] ?? ''));
                $accountBank = trim((string) ($acc['bank_code'] ?? ''));
                if ($accNo !== null && $statementBank !== '' && $accountBank !== '' && $statementBank !== $accountBank
                    && !AccountNumberNormalizer::matchesAny($stmtAccount, null, $iban)) {
                    continue;
                }
                if (AccountNumberNormalizer::matchesAny($stmtAccount, $accNo, $iban)) {
                    $candidateOwners[(int) $acc['supplier_id']] = true;
                }
            }
            if (count($candidateOwners) === 1 && isset($candidateOwners[$supplierId])) {
                $ids[] = (int) $s['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * H2: počet bankovních úhrad (invoice_payments s bank_transaction_id JAKÉHOKOLI source —
     * dedup nohou B/C1 klíčuje na bt_id bez ohledu na source — + payment_matches), jejichž
     * bank_transaction_id NEleží v žádném spárovaném výpisu daného supplieru (matchingStatementIds).
     * Signalizuje, že se změnil bankovní účet / currencies.account_number a historické výpisy
     * přestaly matchovat → celá bankovní historie by jinak z daňového základu tiše zmizela.
     */
    public function orphanedBankPaymentCount(int $supplierId): int
    {
        $stmtIds = $this->matchingStatementIds($supplierId);
        $inList = $stmtIds === [] ? '0' : implode(',', array_map('intval', $stmtIds));
        $sid = $supplierId;
        $sql =
            "SELECT
                (SELECT COUNT(*) FROM invoice_payments ip
                   JOIN bank_transactions bt ON bt.id = ip.bank_transaction_id
                  WHERE ip.supplier_id = {$sid}
                    AND ip.bank_transaction_id IS NOT NULL
                    AND bt.statement_id NOT IN ({$inList}))
              + (SELECT COUNT(*) FROM payment_matches pm
                   JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                  WHERE pm.supplier_id = {$sid}
                    AND pm.bank_transaction_id IS NOT NULL
                    AND bt.statement_id NOT IN ({$inList}))";
        return (int) $this->db->pdo()->query($sql)->fetchColumn();
    }

    // ── date predicates (validované, inline — viz unionSql) ──────────────────

    private function rangePredicate(string $from, string $to): string
    {
        return "%s BETWEEN '" . $this->assertDate($from) . "' AND '" . $this->assertDate($to) . "'";
    }

    private function beforePredicate(string $from): string
    {
        return "%s < '" . $this->assertDate($from) . "'";
    }

    /** Tvrdá validace formátu data (YYYY-MM-DD) před inline do SQL. */
    private function assertDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException('CashJournalRepository: neplatný formát data „' . $date . '".');
        }
        return $date;
    }

    /**
     * NULL v `amount` znamená, že se cizoměnový pohyb neměl čím ocenit: ke dni úhrady
     * (ani zpětně) není v `exchange_rates` kurz a doklad nemá ani vlastní `exchange_rate`.
     *
     * Dřív tady letěla výjimka a shodila SESTAVENÍ CELÉHO DENÍKU na 500 (#28) — účetní
     * neviděl ani zbytek roku a z hlášky nepoznal, který doklad je vadný. Řádek proto
     * projde s nulou a příznakem `fx_rate_missing`; {@see \MyInvoice\Service\TaxEvidence\CashJournalService}
     * z něj udělá blokující varování s identifikací dokladu. Nula, ne tichý přepočet
     * kurzem 1:1 — ten by pohyb podhodnotil ~25× a v daňovém základu by to nikdo nenašel.
     * Do agregací (running_delta, opening/closing balance) NULL stejně nevstupoval,
     * SQL `SUM` ho ignoruje, takže se čísla proti dosavadnímu chování nemění.
     *
     * @param array<string,mixed> $r @return array<string,mixed>
     */
    /**
     * Strana a druh ostatní pohledávky nebo závazku, ke kterému je pohyb přiřazen
     * (other_item_allocations), ve tvaru `payable:loan`; 'mixed' u více různých, NULL bez
     * přiřazení. Zařazení v deníku podle něj rozhoduje
     * {@see \MyInvoice\Service\TaxEvidence\CashJournalService::isOtherItemPrincipal()}.
     */
    private static function otherItemKindSql(int $sid, string $column, string $sourceExpr): string
    {
        return "(SELECT CASE WHEN COUNT(*) = 0 THEN NULL
                             WHEN COUNT(DISTINCT oi.side, oi.kind) = 1 THEN MIN(CONCAT(oi.side, ':', oi.kind))
                             ELSE 'mixed' END
                   FROM other_item_allocations oia
                   JOIN other_items oi ON oi.id = oia.other_item_id AND oi.supplier_id = oia.supplier_id
                  WHERE oia.supplier_id = {$sid} AND oia.{$column} = {$sourceExpr} AND oia.reversed_on IS NULL)";
    }

    /** Součet částek pohybu přiřazených k ostatním položkám (CZK, jiná měna se přiřadit nedá). */
    private static function otherItemAllocatedSql(int $sid, string $column, string $sourceExpr): string
    {
        return "(SELECT SUM(oia.amount) FROM other_item_allocations oia
                  WHERE oia.supplier_id = {$sid} AND oia.{$column} = {$sourceExpr} AND oia.reversed_on IS NULL)";
    }

    private function castRow(array $r): array
    {
        $r['other_item_kind'] = isset($r['other_item_kind']) ? (string) $r['other_item_kind'] : null;
        $r['other_item_allocated'] = isset($r['other_item_allocated']) ? round((float) $r['other_item_allocated'], 2) : null;
        $r['fx_rate_missing'] = !array_key_exists('amount', $r) || $r['amount'] === null;
        $r['source_id']    = (int) $r['source_id'];
        $r['instrument']   = (string) ($r['instrument'] ?? 'virtual');
        $r['gopay_clearing_id'] = isset($r['gopay_clearing_id']) ? (int) $r['gopay_clearing_id'] : null;
        $r['gopay_type']   = isset($r['gopay_type']) ? (string) $r['gopay_type'] : null;
        $r['amount']       = $r['fx_rate_missing'] ? 0.0 : round((float) $r['amount'], 2);
        $r['running_delta'] = isset($r['running_delta']) ? round((float) $r['running_delta'], 2) : 0.0;
        foreach (['invoice_id', 'purchase_invoice_id'] as $k) {
            $r[$k] = ($r[$k] === null) ? null : (int) $r[$k];
        }
        foreach (['cash_vat_base', 'cash_vat_amount', 'inv_without_vat', 'inv_vat', 'inv_with_vat',
                  'pi_without_vat', 'pi_vat', 'pi_with_vat', 'pi_vat_deduction_percent',
                  'bank_income_base', 'bank_income_exempt'] as $k) {
            $r[$k] = (!array_key_exists($k, $r) || $r[$k] === null) ? null : round((float) $r[$k], 2);
        }
        $r['inv_exempt']    = ($r['inv_exempt'] === null) ? null : (int) $r['inv_exempt'];
        $r['pi_deductible'] = ($r['pi_deductible'] === null) ? null : (int) $r['pi_deductible'];
        $r['pi_is_fixed_asset'] = ($r['pi_is_fixed_asset'] === null) ? null : (int) $r['pi_is_fixed_asset'];
        return $r;
    }
}
