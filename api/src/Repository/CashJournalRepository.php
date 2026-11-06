<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\BankStatementSource;
use MyInvoice\Service\Bank\BankNoticeLifecycle;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Service\TaxEvidence\PaymentCoverage;
use MyInvoice\Support\Sql\CreditNoteOffsetRemainderExpr;
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

    /** Projekce alokací bankovního pohybu pro nohy, které bankovním pohybem nejsou. */
    private const NO_BANK_ALLOCATION =
        'NULL AS bank_alloc_czk, NULL AS bank_alloc_native, NULL AS bank_alloc_cross_ccy, NULL AS bank_native_amount';

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
    private function unionSql(int $supplierId, string $datePredicate, bool $isVatPayer = false, ?array $bankTransactionIds = null): ?string
    {
        $sid = $supplierId; // int, bezpečné pro inline
        $vp = $isVatPayer ? 1 : 0; // literál pro DPH prorata v agregacích (R7)
        $payerAtIp = VatStatusService::payerAtExpr((string) $sid, 'ip.paid_on', (string) $vp);
        $payerAtBank = VatStatusService::payerAtExpr((string) $sid, 'bt2.posted_at', (string) $vp);
        $bankIds = $bankTransactionIds === null ? null : implode(',', array_map('intval', $bankTransactionIds));
        $ipCoverage = self::ipCoverageSql($sid, $bankIds);
        // inv_vat/inv_with_vat a pi_vat/pi_with_vat slouží jen k dělení úhrady na základ
        // a DPH, proto nesou složení části dokladu placené penězi: faktura minus dobropisy
        // započtené proti ní (CreditNoteOffsetRemainderExpr). Bez zápočtu = doklad sám.
        // Zápočty se berou k datu řádku (úhrady); pozdější zápočet dřívější úhradu nemění,
        // rozdíl nese opravný řádek ke dni zápočtu (noha O). Zápočty dokladu se připojí jednou
        // jako odvozená tabulka ({alias}_cnr), ne korelovaným poddotazem pro každou úhradu.
        $remJoin = static fn (string $docType, string $alias, string $asOf): string =>
            CreditNoteOffsetRemainderExpr::joinSql($docType, $sid, $alias, $asOf, $alias . '_cnr');
        $remVat = static fn (string $alias): string => CreditNoteOffsetRemainderExpr::vatSql($alias, $alias . '_cnr');
        $remGross = static fn (string $alias): string => CreditNoteOffsetRemainderExpr::grossSql($alias, $alias . '_cnr');
        if ($bankIds !== null) {
            // Kontext několika bank nesmí materializovat zápočty celé firmy.
            $remJoin = static fn (string $docType, string $alias, string $asOf): string => '';
            $remVat = static fn (string $alias): string => CreditNoteOffsetRemainderExpr::vatAsOfOffsetSql(
                $alias === 'bpi' ? 'purchase_invoice' : 'invoice', $alias,
                $alias === 'i' ? 'ibt.posted_at' : 'bt.posted_at', '18446744073709551615',
            );
            $remGross = static fn (string $alias): string => CreditNoteOffsetRemainderExpr::grossAsOfOffsetSql(
                $alias === 'bpi' ? 'purchase_invoice' : 'invoice', $alias,
                $alias === 'i' ? 'ibt.posted_at' : 'bt.posted_at', '18446744073709551615',
            );
        }
        $legs = [];
        $bankLeg = null;

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
                    ci.total_without_vat AS inv_without_vat, " . $remVat('ci') . " AS inv_vat,
                    " . $remGross('ci') . " AS inv_with_vat,
                    ci.income_tax_exempt AS inv_exempt, ci.status AS inv_status,
                    CASE WHEN cip.id IS NOT NULL AND cip.amount <> 0
                         THEN ROUND(cd.total_amount * (cip.amount - ccov.covered) / cip.amount, 2)
                    END AS inv_overpaid,
                    cd.purchase_invoice_id AS purchase_invoice_id,
                    cpi.total_without_vat AS pi_without_vat, " . $remVat('cpi') . " AS pi_vat,
                    " . $remGross('cpi') . " AS pi_with_vat,
                    cpi.tax_deductible AS pi_deductible, cpi.document_kind AS pi_kind,
                    cpi.vat_deduction AS pi_vat_deduction, cpi.vat_deduction_percent AS pi_vat_deduction_percent,
                    cpi.is_fixed_asset AS pi_is_fixed_asset,
                    ccls.tax_bucket AS override_bucket,
                    " . self::otherItemKindSql($sid, 'cash_document_id', 'cd.id') . " AS other_item_kind,
                    " . self::otherItemAllocatedSql($sid, 'cash_document_id', 'cd.id') . " AS other_item_allocated,
                    'cash' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
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
               -- #247: pokladní úhrada faktury nese vazbu na konkrétní úhradu, a tedy i
               -- na to, jakou část dokladu hradí a co je přeplatek (viz ipCoverageSql).
               -- Doklad bez vazby (vratka k dobropisu) se dělí poměrem DPH jako dosud.
               LEFT JOIN invoice_payments cip ON cip.id = cd.invoice_payment_id
                                             AND cip.invoice_id = cd.invoice_id AND cip.supplier_id = {$sid}
               LEFT JOIN ({$ipCoverage}) ccov ON ccov.ip_id = cip.id
               LEFT JOIN purchase_invoices cpi ON cpi.id = cd.purchase_invoice_id AND cpi.supplier_id = {$sid}
               " . $remJoin('invoice', 'ci', 'cd.issue_date') . "
               " . $remJoin('purchase_invoice', 'cpi', 'cd.issue_date') . "
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
        $candidateStatements = $bankIds === null ? null : $this->db->pdo()->query(
            "SELECT DISTINCT statement_id FROM bank_transactions WHERE id IN ({$bankIds})"
        )->fetchAll(PDO::FETCH_COLUMN);
        $stmtIds = $this->matchingStatementIds($supplierId, $candidateStatements);
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
            // #247: legacy 1:1 pohyb (jen matched_invoice_id, bez úhrady v invoice_payments)
            // je úhradou sám. Krytou částku dokladu dopočítá stejné pravidlo jako
            // ipCoverageSql, dříve uhrazeno = úhrady faktury s dřívějším datem. Pohyb v jiné
            // měně, než je faktura, se s bruttem porovnat nedá, ten zůstává bez přeplatku.
            $legacyPrior = "(SELECT COALESCE(SUM(lp.amount), 0) FROM invoice_payments lp"
                . ($bankIds === null ? '' : ' FORCE INDEX (idx_invpay_invoice)') . "
                              WHERE lp.invoice_id = bi.id AND lp.supplier_id = {$sid}
                                AND lp.paid_on < bt.posted_at)";
            $legacyCovered = "ABS(LEAST(GREATEST(SIGN(bi.total_with_vat) * ({$legacyPrior} + bt.amount), 0), ABS(bi.total_with_vat))
                                - LEAST(GREATEST(SIGN(bi.total_with_vat) * {$legacyPrior}, 0), ABS(bi.total_with_vat)))";
            $legacyOverpaid =
                "CASE WHEN binc.btid IS NULL AND bexp.btid IS NULL AND bi.id IS NOT NULL AND bt.amount <> 0
                           AND COALESCE(NULLIF(bt.currency, ''), 'CZK')
                               = COALESCE((SELECT lc.code FROM currencies lc WHERE lc.id = bi.currency_id AND lc.supplier_id = {$sid}), 'CZK')
                      THEN ROUND(ROUND(ABS(bt.amount) * {$feeRate}, 2) * (ABS(bt.amount) - {$legacyCovered})
                                 / ABS(bt.amount), 2)
                 END";
            // #164: částka řádku je VŽDY skutečný bankovní pohyb. Součet navázaných úhrad
            // (binc/bexp) ho dřív přebíjel, takže duplicitní párování nafouklo výdaj
            // i zůstatek a nedopárovaný zbytek pohybu z deníku tiše zmizel. Alokace se
            // vrací vedle částky (bank_alloc_*) a CashJournalService podle nich jen dělí
            // pohyb do kbelíků; nesoulad s pohybem hlásí ve warnings[].
            $legs[] =
                "SELECT 'bank' AS source_type, bt.id AS source_id, bt.posted_at AS movement_date,
                        CASE WHEN bt.amount >= 0 THEN 'in' ELSE 'out' END AS direction,
                        ROUND(ABS(bt.amount) * {$feeRate}, 2) AS amount,
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
                        bi.total_without_vat AS inv_without_vat, " . $remVat('bi') . " AS inv_vat,
                        " . $remGross('bi') . " AS inv_with_vat,
                        bi.income_tax_exempt AS inv_exempt, bi.status AS inv_status,
                        CASE WHEN binc.btid IS NOT NULL THEN ABS(binc.czk_overpaid)
                             ELSE {$legacyOverpaid} END AS inv_overpaid,
                        bexp.any_pi_id AS purchase_invoice_id,
                        bpi.total_without_vat AS pi_without_vat, " . $remVat('bpi') . " AS pi_vat,
                        " . $remGross('bpi') . " AS pi_with_vat,
                        bpi.tax_deductible AS pi_deductible, bpi.document_kind AS pi_kind,
                        bpi.vat_deduction AS pi_vat_deduction, bpi.vat_deduction_percent AS pi_vat_deduction_percent,
                        bpi.is_fixed_asset AS pi_is_fixed_asset,
                        bcls.tax_bucket AS override_bucket,
                        " . self::otherItemKindSql($sid, 'bank_transaction_id', 'bt.id') . " AS other_item_kind,
                        " . self::otherItemAllocatedSql($sid, 'bank_transaction_id', 'bt.id') . " AS other_item_allocated,
                        'bank' AS instrument,
                        (SELECT MIN(gc.id) FROM gopay_clearings gc
                          WHERE gc.supplier_id = {$sid} AND gc.bank_transaction_id = bt.id) AS gopay_clearing_id,
                        NULL AS gopay_type,
                        COALESCE(binc.czk_amount, bexp.czk_amount) AS bank_alloc_czk,
                        COALESCE(binc.native_amount, bexp.native_amount) AS bank_alloc_native,
                        COALESCE(binc.cross_ccy, 0) AS bank_alloc_cross_ccy,
                        ABS(bt.amount) AS bank_native_amount
                   FROM bank_transactions bt
                   LEFT JOIN (
                        SELECT ip.bank_transaction_id AS btid,
                               SUM(ROUND(ip.amount * {$ipRateBank}, 2)) AS czk_amount,
                               -- Úhrada v měně faktury vs. pohyb v měně účtu: srovnat
                               -- se dají jen v CZK a rozdíl je kurzový, ne chyba párování.
                               SUM(ip.amount) AS native_amount,
                               MAX(COALESCE(NULLIF(ip.currency, ''), 'CZK')
                                   <> COALESCE(NULLIF(ibt.currency, ''), 'CZK')) AS cross_ccy,
                               -- Paid gross contains rounding. Only the stored VAT is
                               -- excluded from income (same rule as CashJournalService::prorateBase).
                               -- #247: poměrem DPH se dělí jen krytá část úhrady (bcov.covered,
                               -- nejvýš do dosud neuhrazeného brutta faktury). Přeplatek nad
                               -- brutto DPH nenese a celý zůstává v příjmu.
                               SUM(ROUND(CASE WHEN i.income_tax_exempt = 1 THEN 0
                                              WHEN {$payerAtIp} = 1 AND i.total_with_vat <> 0
                                                   THEN ip.amount - bcov.covered * LEAST(1, GREATEST(0, " . $remVat('i') . ' / ' . $remGross('i') . "))
                                              ELSE ip.amount END
                                         * {$ipRateBank}, 2)) AS czk_base,
                               -- Osvobozená noha se dělí na základ/DPH STEJNĚ jako zdanitelná
                               -- vedle ní (#52): u plátce DPH je osvobozený PŘÍJEM částka bez
                               -- DPH — DPH z takové faktury je průběžná položka státu, ne příjem
                               -- poplatníka. Zbytek (amount − czk_base − czk_exempt) padne do
                               -- income_nontax v CashJournalService::bankIncomeAlloc().
                               SUM(ROUND(CASE WHEN i.income_tax_exempt <> 1 THEN 0
                                              WHEN {$payerAtIp} = 1 AND i.total_with_vat <> 0
                                                   THEN ip.amount - bcov.covered * LEAST(1, GREATEST(0, " . $remVat('i') . ' / ' . $remGross('i') . "))
                                              ELSE ip.amount END
                                         * {$ipRateBank}, 2)) AS czk_exempt,
                               -- Přeplatek v CZK, podklad pro ruční zařazení pohybu (1027).
                               SUM(ROUND((ip.amount - bcov.covered) * {$ipRateBank}, 2)) AS czk_overpaid,
                               MIN(ip.invoice_id) AS any_invoice_id
                          FROM invoice_payments ip
                          JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = {$sid}
                          JOIN ({$ipCoverage}) bcov ON bcov.ip_id = ip.id
                          JOIN bank_transactions ibt ON ibt.id = ip.bank_transaction_id
                          " . $remJoin('invoice', 'i', 'ibt.posted_at') . "
                         WHERE ip.supplier_id = {$sid} AND ip.bank_transaction_id IS NOT NULL
                           AND i.status <> 'cancelled'
                           " . ($bankIds === null ? '' : "AND ip.bank_transaction_id IN ({$bankIds})") . "
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
                               SUM(pm.amount) AS native_amount,
                               MIN(pm.purchase_invoice_id) AS any_pi_id
                          FROM payment_matches pm
                          JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = {$sid}
                          JOIN bank_transactions bt2 ON bt2.id = pm.bank_transaction_id
                         WHERE pm.supplier_id = {$sid} AND pm.purchase_invoice_id IS NOT NULL
                           " . ($bankIds === null ? '' : "AND pm.bank_transaction_id IN ({$bankIds})") . "
                         GROUP BY pm.bank_transaction_id
                   ) bexp ON bexp.btid = bt.id
                   LEFT JOIN invoices bi
                          ON bi.id = COALESCE(binc.any_invoice_id,
                                              CASE WHEN bexp.btid IS NULL THEN bt.matched_invoice_id END)
                         AND bi.supplier_id = {$sid} AND bi.status <> 'cancelled'
                   LEFT JOIN purchase_invoices bpi ON bpi.id = bexp.any_pi_id AND bpi.supplier_id = {$sid}
                   " . $remJoin('invoice', 'bi', 'bt.posted_at') . "
                   " . $remJoin('purchase_invoice', 'bpi', 'bt.posted_at') . "
                   LEFT JOIN de_movement_classification bcls
                          ON bcls.supplier_id = {$sid} AND bcls.source_type = 'bank' AND bcls.bank_transaction_id = bt.id
                  WHERE bt.statement_id IN ({$inList})
                    " . ($bankIds === null ? '' : "AND bt.id IN ({$bankIds})") . "
                    AND NOT (
                          binc.btid IS NULL AND bexp.btid IS NULL AND bt.matched_invoice_id IS NOT NULL
                          AND " . ($bankIds === null ? 'EXISTS' : '0 <') . ' (SELECT '
                              . ($bankIds === null ? '1' : 'COUNT(*)') . " FROM invoice_payments y"
                              . ($bankIds === null ? '' : ' FORCE INDEX (idx_invpay_invoice)') . "
                                        JOIN invoices yi ON yi.id = y.invoice_id AND yi.supplier_id = {$sid}
                                                        AND yi.status <> 'cancelled'
                                       WHERE y.invoice_id = bt.matched_invoice_id AND y.supplier_id = {$sid}
                                         AND y.bank_transaction_id IS NULL
                                         AND y.source IN ('manual', 'mark_paid', 'legacy'))
                    )
                    AND " . BankNoticeLifecycle::activeSql('bt') . "
                    " . ($bankIds === null ? $this->provisionalMovementExclusionSql($stmtIds) : '') . "
                    AND " . sprintf($datePredicate, 'bt.posted_at');
            $bankLeg = $legs[array_key_last($legs)];
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
                    ii.total_without_vat AS inv_without_vat, " . $remVat('ii') . " AS inv_vat,
                    " . $remGross('ii') . " AS inv_with_vat,
                    ii.income_tax_exempt AS inv_exempt, ii.status AS inv_status,
                    ROUND(ip.amount * {$ipRateC1}, 2) - ROUND(ccov1.covered * {$ipRateC1}, 2) AS inv_overpaid,
                    NULL AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    NULL AS pi_deductible, NULL AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    CASE WHEN ip.bank_reference LIKE BINARY '" . \MyInvoice\Service\Accounting\GoPay\GoPayPendingService::REFERENCE_PREFIX . "%'
                              AND EXISTS (SELECT 1 FROM gopay_settings gs
                                           WHERE gs.supplier_id = {$sid} AND gs.currency = ip.currency)
                         THEN 'gopay' ELSE 'virtual' END AS instrument,
                    NULL AS gopay_clearing_id, NULL AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
               FROM invoice_payments ip
               JOIN invoices ii      ON ii.id = ip.invoice_id AND ii.supplier_id = {$sid}
               JOIN ({$ipCoverage}) ccov1 ON ccov1.ip_id = ip.id
               LEFT JOIN clients icl ON icl.id = ii.client_id
               " . $remJoin('invoice', 'ii', 'ip.paid_on') . "
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
        // Směr je u ruční úhrady vždy 'out'; dobropis se od faktury liší znaménkem částky.
        // Kladně uložený dobropis (import z cizího systému, přepnutý druh dokladu) se otočí
        // týmž pravidlem jako v evidenci DPH, jinak by výdaj místo snížení zvýšil.
        $docSign = \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSignSql('pi');
        $legs[] =
            "SELECT 'purchase_invoice' AS source_type, pi.id AS source_id, pi.paid_at AS movement_date,
                    'out' AS direction,
                    ROUND({$docSign} * (COALESCE(pi.amount_to_pay, pi.total_with_vat) - {$cnOffsets}) * {$this->piRateSql()}, 2) AS amount,
                    COALESCE(pi.vendor_invoice_number, '') AS doc_no, COALESCE(pv.company_name, '') AS partner,
                    '' AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    NULL AS invoice_id, NULL AS inv_type, NULL AS inv_without_vat, NULL AS inv_vat, NULL AS inv_with_vat,
                    NULL AS inv_exempt, NULL AS inv_status, NULL AS inv_overpaid,
                    pi.id AS purchase_invoice_id, pi.total_without_vat AS pi_without_vat, " . $remVat('pi') . " AS pi_vat,
                    " . $remGross('pi') . " AS pi_with_vat, pi.tax_deductible AS pi_deductible,
                    pi.document_kind AS pi_kind, pi.vat_deduction AS pi_vat_deduction,
                    pi.vat_deduction_percent AS pi_vat_deduction_percent,
                    pi.is_fixed_asset AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'virtual' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
               FROM purchase_invoices pi
               LEFT JOIN currencies pcur ON pcur.id = pi.currency_id
               LEFT JOIN clients pv      ON pv.id = pi.vendor_id
               " . $remJoin('purchase_invoice', 'pi', 'pi.paid_at') . "
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
                    -- Vratka k dobropisu: dobropis nikdy není fakturou zápočtu (CreditNoteOffsetService
                    -- zápočet proti dobropisu nepřipustí), dělí se tedy podle sebe.
                    gcn.total_without_vat AS inv_without_vat, gcn.total_vat AS inv_vat,
                    gcn.total_with_vat AS inv_with_vat,
                    gcn.income_tax_exempt AS inv_exempt, gcn.status AS inv_status, NULL AS inv_overpaid,
                    NULL AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    NULL AS pi_deductible, NULL AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'gopay' AS instrument, gc.id AS gopay_clearing_id, gm.movement_type AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
               FROM gopay_movements gm
               JOIN gopay_clearings gc ON gc.id = gm.clearing_id AND gc.supplier_id = {$sid}
               LEFT JOIN invoices gcn
                      ON gcn.id = gm.credit_note_id AND gcn.supplier_id = {$sid}
                     AND gm.movement_type = 'storno' AND gm.status = 'posted'
              WHERE gm.supplier_id = {$sid}
                AND NOT (gm.movement_type = 'credit' AND gm.status = 'posted' AND gm.invoice_payment_id IS NOT NULL)
                AND " . sprintf($datePredicate, 'gm.performed_on');

        // ── Noha O — oprava rozdělení dřívějších úhrad po zápočtu dobropisu ──
        // Úhrada se dělí jen podle zápočtů známých v den úhrady, pozdější zápočet ji nepřepíše
        // (uzavřený rok zůstane, jak byl). Rozdíl daňové části a DPH dřívějších úhrad faktury,
        // který zápočet vnese, nese tenhle řádek ke dni zápočtu. Peníze netečou, částka je 0;
        // rozdíl spočítá CashJournalService::offsetCorrectionAlloc() z podkladů
        // creditNoteOffsetCorrection() a řádek bez rozdílu vynechá.
        $legs[] =
            "SELECT 'credit_note_offset' AS source_type, o.id AS source_id, o.offset_on AS movement_date,
                    'in' AS direction, 0.00 AS amount,
                    COALESCE(oi.varsymbol, '') AS doc_no, COALESCE(ocl.company_name, '') AS partner,
                    CONCAT('Oprava rozdělení dřívějších úhrad po zápočtu dobropisu ', COALESCE(ocn.varsymbol, '')) AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    oi.id AS invoice_id, oi.invoice_type AS inv_type,
                    NULL AS inv_without_vat, NULL AS inv_vat, NULL AS inv_with_vat,
                    oi.income_tax_exempt AS inv_exempt, NULL AS inv_status, NULL AS inv_overpaid,
                    NULL AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    NULL AS pi_deductible, NULL AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'virtual' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
               FROM credit_note_offsets o
               JOIN invoices oi  ON oi.id = o.invoice_id AND oi.supplier_id = {$sid} AND oi.status <> 'cancelled'
               JOIN invoices ocn ON ocn.id = o.credit_note_id AND ocn.supplier_id = {$sid} AND ocn.status <> 'cancelled'
               LEFT JOIN clients ocl ON ocl.id = oi.client_id
              WHERE o.supplier_id = {$sid} AND o.doc_type = 'invoice'
                AND " . sprintf($datePredicate, 'o.offset_on');
        $legs[] =
            "SELECT 'credit_note_offset' AS source_type, o.id AS source_id, o.offset_on AS movement_date,
                    'out' AS direction, 0.00 AS amount,
                    COALESCE(opi.vendor_invoice_number, '') AS doc_no, COALESCE(opv.company_name, '') AS partner,
                    CONCAT('Oprava rozdělení dřívějších úhrad po zápočtu dobropisu ', COALESCE(ocp.vendor_invoice_number, '')) AS description,
                    NULL AS cash_purpose, NULL AS cash_vat_base, NULL AS cash_vat_amount,
                    NULL AS bank_class, NULL AS bank_income_base, NULL AS bank_income_exempt,
                    NULL AS invoice_id, NULL AS inv_type, NULL AS inv_without_vat, NULL AS inv_vat, NULL AS inv_with_vat,
                    NULL AS inv_exempt, NULL AS inv_status, NULL AS inv_overpaid,
                    opi.id AS purchase_invoice_id, NULL AS pi_without_vat, NULL AS pi_vat, NULL AS pi_with_vat,
                    opi.tax_deductible AS pi_deductible, opi.document_kind AS pi_kind, NULL AS pi_vat_deduction,
                    NULL AS pi_vat_deduction_percent, NULL AS pi_is_fixed_asset, NULL AS override_bucket,
                    NULL AS other_item_kind, NULL AS other_item_allocated,
                    'virtual' AS instrument, NULL AS gopay_clearing_id, NULL AS gopay_type,
                    " . self::NO_BANK_ALLOCATION . "
               FROM credit_note_offsets o
               JOIN purchase_invoices opi ON opi.id = o.invoice_id AND opi.supplier_id = {$sid} AND opi.status <> 'cancelled'
               JOIN purchase_invoices ocp ON ocp.id = o.credit_note_id AND ocp.supplier_id = {$sid} AND ocp.status <> 'cancelled'
               LEFT JOIN clients opv ON opv.id = opi.vendor_id
              WHERE o.supplier_id = {$sid} AND o.doc_type = 'purchase_invoice'
                AND " . sprintf($datePredicate, 'o.offset_on');

        if ($bankIds !== null) return $bankLeg;
        return $legs === [] ? null : implode("\nUNION ALL\n", $legs);
    }

    /**
     * Podklad opravného řádku zápočtu dobropisu (noha O): úhrady faktury s datem před
     * zápočtem a u vydané strany složení části placené penězi bez tohoto zápočtu a s ním
     * (u přijaté ho počítá TaxExpenseAllocationCalculator).
     *
     * Úhrady se berou ze stejných zdrojů, s týmiž filtry, kurzy a daty jako nohy A, B, C1
     * a C2, aby se oprava týkala právě úhrad, které deník rozdělil podle faktury: pokladní
     * doklad s účelem úhrady, bankovní úhrada (invoice_payments, payment_matches, starší
     * párování pohybu na fakturu) a ruční úhrada. Ruční daňové zařazení zachovává vlastní
     * pravidlo rozdělení; vyúčtování GoPay se podle faktury nedělí a chybí i tady. Kdo mění výběr řádků v nohách,
     * mění ho i tady (hlídá CreditNoteOffsetVatSplitTest).
     *
     * Bankovní úhrada vydané faktury nese částku v Kč nezaokrouhlenou, protože ji noha B
     * zaokrouhluje až po rozdělení; přijaté nese částku v měně pohybu, kterou na Kč převede
     * kalkulačka stejně jako u řádku pohybu.
     *
     * Podklady všech opravných řádků jednoho sestavení deníku se načtou dávkou
     * ({@see creditNoteOffsetCorrections()}), ne dotazy pro každý řádek.
     *
     * @return array{doc_type:string, invoice_id:int, offset_id:int, offset_on:string,
     *     vat_before:?float, gross_before:?float, vat_after:?float, gross_after:?float,
     *     payments:list<array{kind:string, date:string, payer_date:string, direction:string,
     *     amount:float, currency:string}>}|null
     */
    public function creditNoteOffsetCorrection(int $supplierId, int $offsetId): ?array
    {
        return $this->creditNoteOffsetCorrections($supplierId, [$offsetId])[$offsetId] ?? null;
    }

    /**
     * Podklady opravných řádků více zápočtů naráz ({@see creditNoteOffsetCorrection()}).
     *
     * @param list<int> $offsetIds
     * @return array<int, array{doc_type:string, invoice_id:int, offset_id:int, offset_on:string,
     *     vat_before:?float, gross_before:?float, vat_after:?float, gross_after:?float,
     *     payments:list<array{kind:string, date:string, payer_date:string, direction:string,
     *     amount:float, currency:string}>}> klíč id zápočtu
     */
    public function creditNoteOffsetCorrections(int $supplierId, array $offsetIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $offsetIds)));
        if ($ids === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $sid = $supplierId;
        $inIds = implode(',', $ids);
        $out = [];
        foreach ($pdo->query(
            "SELECT id, doc_type, invoice_id, offset_on FROM credit_note_offsets WHERE supplier_id = {$sid} AND id IN ({$inIds})"
        )->fetchAll(PDO::FETCH_ASSOC) as $offset) {
            $out[(int) $offset['id']] = [
                'doc_type' => (string) $offset['doc_type'],
                'invoice_id' => (int) $offset['invoice_id'],
                'offset_id' => (int) $offset['id'],
                'offset_on' => $this->assertDate((string) $offset['offset_on']),
                'vat_before' => null, 'gross_before' => null, 'vat_after' => null, 'gross_after' => null,
                'payments' => [],
            ];
        }
        if ($out === []) {
            return [];
        }

        foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'] as $type => $table) {
            foreach ($pdo->query(
                'SELECT o.id,
                        ' . CreditNoteOffsetRemainderExpr::vatAsOfOffsetSql($type, 'i', 'o.offset_on', 'o.id - 1') . ' AS vat_before,
                        ' . CreditNoteOffsetRemainderExpr::grossAsOfOffsetSql($type, 'i', 'o.offset_on', 'o.id - 1') . ' AS gross_before,
                        ' . CreditNoteOffsetRemainderExpr::vatAsOfOffsetSql($type, 'i', 'o.offset_on', 'o.id') . ' AS vat_after,
                        ' . CreditNoteOffsetRemainderExpr::grossAsOfOffsetSql($type, 'i', 'o.offset_on', 'o.id') . ' AS gross_after,
                        ' . CreditNoteOffsetRemainderExpr::remainingGrossSql($type, 'i', 'o.offset_on', 'o.id - 1') . ' AS coverage_before,
                        ' . CreditNoteOffsetRemainderExpr::remainingGrossSql($type, 'i', 'o.offset_on', 'o.id') . " AS coverage_after,
                        (SELECT MAX(previous.offset_on) FROM credit_note_offsets previous
                          WHERE previous.supplier_id = {$sid} AND previous.doc_type = '{$type}' AND previous.invoice_id = i.id
                            AND (previous.offset_on < o.offset_on OR (previous.offset_on = o.offset_on AND previous.id < o.id))) AS previous_offset_on
                   FROM credit_note_offsets o
                   JOIN {$table} i ON i.id = o.invoice_id AND i.supplier_id = o.supplier_id
                  WHERE o.supplier_id = {$sid} AND o.doc_type = '{$type}' AND o.id IN ({$inIds})"
            )->fetchAll(PDO::FETCH_ASSOC) as $split) {
                foreach (['vat_before', 'gross_before', 'vat_after', 'gross_after', 'coverage_before', 'coverage_after'] as $key) {
                    $out[(int) $split['id']][$key] = $split[$key] !== null ? (float) $split[$key] : null;
                }
                $out[(int) $split['id']]['previous_offset_on'] = $split['previous_offset_on'];
            }
        }
        $coverage = $this->creditNoteCorrectionPaymentCoverage($supplierId, $out);

        // Úhrady faktury s datem před zápočtem; `o` je zápočet, ke kterému úhrada patří.
        $overrideBucket = static fn (string $source, string $column, string $id): string =>
            "(SELECT mc.tax_bucket FROM de_movement_classification mc
                          WHERE mc.supplier_id = {$sid} AND mc.source_type = '{$source}' AND mc.{$column} = {$id}
                         )";
        $incomeOnTx = "EXISTS (SELECT 1 FROM invoice_payments y
                                JOIN invoices yi ON yi.id = y.invoice_id AND yi.supplier_id = {$sid} AND yi.status <> 'cancelled'
                               WHERE y.supplier_id = {$sid} AND y.bank_transaction_id = bt.id)";
        $stmtIds = $this->matchingStatementIds($supplierId, $this->correctionStatementCandidates($supplierId, $ids));
        $bankFilter = $stmtIds === [] ? null
            : 'bt.statement_id IN (' . implode(',', array_map('intval', $stmtIds)) . ')
               AND ' . BankNoticeLifecycle::activeSql('bt') . '
               ' . $this->provisionalMovementExclusionSql($stmtIds) . "
               AND NOT EXISTS (SELECT 1 FROM gopay_clearings gc WHERE gc.supplier_id = {$sid} AND gc.bank_transaction_id = bt.id)";
        $cashSql = static fn (string $docType, string $purpose, string $column): string =>
            "SELECT o.id AS offset_id,
                    CASE WHEN cd.invoice_id IS NOT NULL AND cd.invoice_payment_id IS NULL THEN 'legacy_cash' ELSE 'cash' END AS kind,
                    cd.issue_date AS date, cd.issue_date AS payer_date,
                    cd.doc_type AS direction, ROUND(cd.total_amount, 2) AS amount, '' AS currency,
                    " . ($docType === 'invoice' ? 'COALESCE(cd.invoice_payment_id, cd.id)' : 'cd.id') . " AS payment_ref,
                    " . $overrideBucket('cash', 'cash_document_id', 'cd.id') . " AS override_bucket, NULL AS bank_source_id, NULL AS native_rate
               FROM credit_note_offsets o
               JOIN cash_documents cd ON cd.supplier_id = {$sid} AND cd.{$column} = o.invoice_id AND cd.issue_date < o.offset_on
              WHERE o.supplier_id = {$sid} AND o.doc_type = '{$docType}' AND o.id IN ({$inIds})
                AND cd.status = 'posted' AND cd.purpose = '{$purpose}'";

        $parts = [
            $cashSql('invoice', 'invoice_payment', 'invoice_id'),
            "SELECT o.id AS offset_id, 'manual' AS kind, ip.paid_on AS date, ip.paid_on AS payer_date, 'in' AS direction,
                    ROUND(ip.amount * " . $this->ipRateSql('i') . ", 2) AS amount, '' AS currency, ip.id AS payment_ref, NULL AS override_bucket, NULL AS bank_source_id,
                    " . $this->ipRateSql('i') . " AS native_rate
               FROM credit_note_offsets o
               JOIN invoice_payments ip ON ip.supplier_id = {$sid} AND ip.invoice_id = o.invoice_id AND ip.paid_on < o.offset_on
               JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = {$sid}
              WHERE o.supplier_id = {$sid} AND o.doc_type = 'invoice' AND o.id IN ({$inIds})
                AND ip.bank_transaction_id IS NULL AND ip.source IN ('manual', 'mark_paid', 'legacy')",
            $cashSql('purchase_invoice', 'purchase_payment', 'purchase_invoice_id'),
        ];
        $cnOffsets = "COALESCE((SELECT SUM(cno.amount) FROM credit_note_offsets cno
                                 WHERE cno.supplier_id = pi.supplier_id AND cno.doc_type = 'purchase_invoice'
                                   AND cno.invoice_id = pi.id), 0)";
        $parts[] =
            "SELECT o.id AS offset_id, 'manual' AS kind, pi.paid_at AS date, pi.paid_at AS payer_date, 'out' AS direction,
                    ROUND(" . \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSignSql('pi') . "
                          * (COALESCE(pi.amount_to_pay, pi.total_with_vat) - {$cnOffsets}) * {$this->piRateSql()}, 2) AS amount,
                    '' AS currency, NULL AS payment_ref, NULL AS override_bucket, NULL AS bank_source_id, NULL AS native_rate
               FROM credit_note_offsets o
               JOIN purchase_invoices pi ON pi.id = o.invoice_id AND pi.supplier_id = {$sid}
               LEFT JOIN currencies pcur ON pcur.id = pi.currency_id
              WHERE o.supplier_id = {$sid} AND o.doc_type = 'purchase_invoice' AND o.id IN ({$inIds})
                AND pi.status <> 'cancelled' AND pi.paid_at IS NOT NULL AND pi.paid_at < o.offset_on
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id)
                AND NOT EXISTS (SELECT 1 FROM cash_documents cd2
                                 WHERE cd2.purchase_invoice_id = pi.id AND cd2.status = 'posted')
                AND NOT ({$cnOffsets} > 0 AND ABS(COALESCE(pi.amount_to_pay, pi.total_with_vat) - {$cnOffsets}) <= 0.005)";
        if ($bankFilter !== null) {
            $parts[] =
                "SELECT o.id AS offset_id, 'bank' AS kind, bt.posted_at AS date, ip.paid_on AS payer_date,
                        CASE WHEN bt.amount >= 0 THEN 'in' ELSE 'out' END AS direction,
                        ABS(ip.amount) * " . $this->ipRateSql('i') . " AS amount, '' AS currency, ip.id AS payment_ref,
                        " . $overrideBucket('bank', 'bank_transaction_id', 'bt.id') . " AS override_bucket, bt.id AS bank_source_id, NULL AS native_rate
                   FROM credit_note_offsets o
                   JOIN invoice_payments ip ON ip.supplier_id = {$sid} AND ip.invoice_id = o.invoice_id
                   JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = {$sid}
                   JOIN bank_transactions bt ON bt.id = ip.bank_transaction_id AND bt.posted_at < o.offset_on
                  WHERE o.supplier_id = {$sid} AND o.doc_type = 'invoice' AND o.id IN ({$inIds})
                    AND {$bankFilter}";
            // Starší párování pohybu přímo na fakturu, bez invoice_payments (noha B, `bi`).
            $parts[] =
                "SELECT o.id AS offset_id, 'legacy_bank' AS kind, bt.posted_at AS date, bt.posted_at AS payer_date,
                        CASE WHEN bt.amount >= 0 THEN 'in' ELSE 'out' END AS direction,
                        ROUND(ABS(bt.amount) * IF(COALESCE(NULLIF(bt.currency, ''), 'CZK') = 'CZK', 1,
                              " . self::nearestRateSql('bt.currency', 'bt.posted_at') . "), 2) AS amount,
                        '' AS currency, bt.id AS payment_ref,
                        " . $overrideBucket('bank', 'bank_transaction_id', 'bt.id') . " AS override_bucket, bt.id AS bank_source_id, NULL AS native_rate
                   FROM credit_note_offsets o
                   JOIN bank_transactions bt ON bt.matched_invoice_id = o.invoice_id AND bt.posted_at < o.offset_on
                  WHERE o.supplier_id = {$sid} AND o.doc_type = 'invoice' AND o.id IN ({$inIds})
                    AND {$bankFilter}
                    AND NOT {$incomeOnTx}
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm
                                      JOIN purchase_invoices ppi ON ppi.id = pm.purchase_invoice_id AND ppi.supplier_id = {$sid}
                                     WHERE pm.supplier_id = {$sid} AND pm.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments y
                                     WHERE y.invoice_id = o.invoice_id AND y.supplier_id = {$sid}
                                       AND y.bank_transaction_id IS NULL
                                       AND y.source IN ('manual', 'mark_paid', 'legacy'))";
            $parts[] =
                "SELECT o.id AS offset_id, 'bank' AS kind, bt.posted_at AS date, bt.posted_at AS payer_date,
                        CASE WHEN bt.amount >= 0 THEN 'in' ELSE 'out' END AS direction,
                        pm.amount AS amount, COALESCE(bt.currency, '') AS currency, pm.id AS payment_ref,
                        " . $overrideBucket('bank', 'bank_transaction_id', 'bt.id') . " AS override_bucket, bt.id AS bank_source_id, NULL AS native_rate
                   FROM credit_note_offsets o
                   JOIN payment_matches pm ON pm.supplier_id = {$sid} AND pm.purchase_invoice_id = o.invoice_id
                   JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.posted_at < o.offset_on
                  WHERE o.supplier_id = {$sid} AND o.doc_type = 'purchase_invoice' AND o.id IN ({$inIds})
                    AND {$bankFilter}
                    AND NOT {$incomeOnTx}";
        }

        $paymentsSql = implode("\nUNION ALL\n", $parts);
        $payer = VatStatusService::payerAtExpr((string) $sid, 'p.payer_date', "(SELECT is_vat_payer FROM supplier WHERE id = {$sid})");
        $rate = self::backwardRateSql('p.currency', 'p.date');
        $payments = $pdo->query("SELECT p.*, {$payer} AS is_vat_payer,
                                    CASE WHEN p.currency IN ('', 'CZK') THEN 1 ELSE {$rate} END AS exchange_rate
                               FROM ({$paymentsSql}) p")->fetchAll(PDO::FETCH_ASSOC);
        $bankContexts = $this->bankOverrideContexts($supplierId, $payments);
        $seenOverrides = [];
        foreach ($payments as $p) {
            $basis = $out[(int) $p['offset_id']];
            $bankContext = $bankContexts[$p['offset_id'] . ':' . ($p['bank_source_id'] ?? '')] ?? null;
            if ($bankContext !== null) {
                $key = $p['offset_id'] . ':' . $p['bank_source_id'];
                if (isset($seenOverrides[$key])) continue;
                $seenOverrides[$key] = true;
            }
            $paymentKind = $basis['doc_type'] === 'invoice' && !in_array($p['kind'], ['legacy_bank', 'legacy_cash'], true) ? 'invoice_payment' : (string) $p['kind'];
            $paymentCoverage = $coverage[$basis['doc_type'] . ':' . $basis['invoice_id'] . ':' . $paymentKind . ':' . ($p['payment_ref'] ?? '')] ?? null;
            $missingFx = $p['amount'] === null || $p['exchange_rate'] === null || (float) $p['exchange_rate'] <= 0;
            $out[(int) $p['offset_id']]['payments'][] = [
                'kind' => (string) $p['kind'],
                'date' => (string) $p['date'],
                'payer_date' => (string) $p['payer_date'],
                'direction' => (string) $p['direction'],
                'amount' => (float) $p['amount'],
                'currency' => (string) $p['currency'],
                'is_vat_payer' => (bool) $p['is_vat_payer'],
                'exchange_rate' => $p['exchange_rate'] !== null ? (float) $p['exchange_rate'] : null,
                'coverage' => $paymentCoverage,
                'override_bucket' => $p['override_bucket'],
                'override_context' => $bankContext,
                'native_rate' => $p['native_rate'] !== null ? (float) $p['native_rate'] : null,
                'fx_missing_movement' => $missingFx
                    ? [
                        'source_type' => $p['bank_source_id'] !== null ? 'bank' : ($basis['doc_type'] === 'invoice' ? 'invoice_payment' : 'purchase_invoice'),
                        'source_id' => (int) ($p['bank_source_id'] ?? $p['payment_ref'] ?? $basis['invoice_id']),
                        'movement_date' => (string) $p['date'], 'direction' => (string) $p['direction'],
                        'doc_no' => '', 'partner' => '',
                    ]
                    : null,
            ];
        }
        return $out;
    }

    /**
     * Skutečný kontext celé banky, dávkou i pro splity. Banky již prošly provisional filtrem
     * korekcí, proto omezená projekce neopakuje výběr dalších výpisů pro pokrytí avíz.
     */
    private function bankOverrideContexts(int $supplierId, array $payments): array
    {
        $out = [];
        $pairs = [];
        foreach ($payments as $p) {
            if (($p['override_bucket'] ?? '') !== '' && $p['bank_source_id'] !== null) {
                $pairs[$p['offset_id'] . ':' . $p['bank_source_id']] = [(int) $p['offset_id'], (int) $p['bank_source_id']];
            }
        }
        foreach (array_chunk(array_values($pairs), 200) as $chunk) {
            $bankIds = array_values(array_unique(array_column($chunk, 1)));
            $union = $this->unionSql($supplierId, '%s IS NOT NULL', false, $bankIds);
            if ($union === null) continue;
            $contexts = [];
            $payer = VatStatusService::payerAtExpr((string) $supplierId, 't.movement_date', "(SELECT is_vat_payer FROM supplier WHERE id = {$supplierId})");
            foreach ($this->db->pdo()->query("SELECT t.*, {$payer} AS override_is_vat_payer FROM ({$union}) t")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $contexts[(int) $row['source_id']] = $this->castRow($row);
            }
            $pairSql = [];
            foreach ($chunk as [$offsetId, $bankId]) {
                if (!isset($contexts[$bankId])) continue;
                $context = $contexts[$bankId];
                $pairSql[] = "SELECT {$offsetId} AS offset_id, {$bankId} AS bank_id, "
                    . (int) ($context['invoice_id'] ?? 0) . ' AS invoice_id, '
                    . (int) ($context['purchase_invoice_id'] ?? 0) . ' AS purchase_invoice_id';
            }
            if ($pairSql === []) continue;
            $pairSql = implode(' UNION ALL ', $pairSql);
            $fields = [];
            foreach (['before' => 'o.id - 1', 'after' => 'o.id'] as $phase => $through) {
                foreach (['invoice' => ['i', 'inv'], 'purchase_invoice' => ['pi', 'pi']] as $type => [$alias, $prefix]) {
                    $fields[] = CreditNoteOffsetRemainderExpr::vatAsOfOffsetSql($type, $alias, 'o.offset_on', $through) . " AS {$prefix}_vat_{$phase}";
                    $fields[] = CreditNoteOffsetRemainderExpr::grossAsOfOffsetSql($type, $alias, 'o.offset_on', $through) . " AS {$prefix}_with_vat_{$phase}";
                }
            }
            $sql = 'SELECT p.offset_id, p.bank_id, ' . implode(', ', $fields) . "
                      FROM ({$pairSql}) p
                      JOIN credit_note_offsets o ON o.supplier_id = {$supplierId} AND o.id = p.offset_id
                      LEFT JOIN invoices i ON i.supplier_id = {$supplierId} AND i.id = p.invoice_id
                      LEFT JOIN purchase_invoices pi ON pi.supplier_id = {$supplierId} AND pi.id = p.purchase_invoice_id";
            foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (['before', 'after'] as $phase) {
                    $context = $contexts[(int) $row['bank_id']];
                    foreach (['inv_vat', 'inv_with_vat', 'pi_vat', 'pi_with_vat'] as $field) $context[$field] = $row[$field . '_' . $phase];
                    $out[$row['offset_id'] . ':' . $row['bank_id']][$phase] = $context;
                }
            }
            $sql = "SELECT p.offset_id, p.bank_id, ip.id, ip.invoice_id, ip.paid_on,
                           ip.amount AS native_amount, " . $this->ipRateSql('i') . " AS exchange_rate,
                           " . CreditNoteOffsetRemainderExpr::remainingGrossSql('invoice', 'i', 'ip.paid_on', '18446744073709551615') . " AS gross_at_payment,
                           " . CreditNoteOffsetRemainderExpr::remainingGrossSql('invoice', 'i', 'o.offset_on', 'o.id - 1') . " AS gross_before,
                           " . CreditNoteOffsetRemainderExpr::remainingGrossSql('invoice', 'i', 'o.offset_on', 'o.id') . " AS gross_after
                      FROM ({$pairSql}) p
                      JOIN credit_note_offsets o ON o.supplier_id = {$supplierId} AND o.id = p.offset_id
                      JOIN invoice_payments ip ON ip.supplier_id = {$supplierId} AND ip.bank_transaction_id = p.bank_id
                      JOIN invoices i ON i.supplier_id = {$supplierId} AND i.id = ip.invoice_id AND i.status <> 'cancelled'";
            $incomePayments = $this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $documents = [];
            foreach ($incomePayments as $payment) $documents[(int) $payment['invoice_id']] = ['doc_type' => 'invoice', 'invoice_id' => (int) $payment['invoice_id']];
            $coverage = $this->creditNoteCorrectionPaymentCoverage($supplierId, array_values($documents));
            $adjustments = [];
            foreach ($incomePayments as $payment) {
                $c = $coverage['invoice:' . $payment['invoice_id'] . ':invoice_payment:' . $payment['id']] ?? null;
                $c = $c['raw'] ?? $c;
                if (!($c['comparable'] ?? false) || $payment['exchange_rate'] === null) continue;
                $overpaid = static fn (float $gross): float => round(
                    round((float) $payment['native_amount'] - PaymentCoverage::covered($c['prior'], $c['amount'], $gross), 2)
                        * (float) $payment['exchange_rate'], 2,
                );
                $anchor = $overpaid((float) $payment['gross_at_payment']);
                foreach (['before', 'after'] as $phase) {
                    $delta = $overpaid((float) $payment['gross_' . $phase]) - $anchor;
                    $key = $payment['offset_id'] . ':' . $payment['bank_id'];
                    $adjustments[$key][$phase] = ($adjustments[$key][$phase] ?? 0.0) + $delta;
                }
            }
            foreach ($adjustments as $key => $phases) {
                foreach ($phases as $phase => $delta) {
                    $context = &$out[$key][$phase];
                    $sign = $context['direction'] === 'in' ? 1 : -1;
                    $context['inv_overpaid'] = abs($sign * (float) $context['inv_overpaid'] + $delta);
                    unset($context);
                }
            }
        }
        return $out;
    }

    /**
     * Kandidáti podle vazeb opravovaných dokladů. U avíza je nutný původní širší výběr:
     * pokrytí oficiálními výpisy i legacy normalizace účtů rozhodují, zda jde o peněžní tok.
     */
    private function correctionStatementCandidates(int $supplierId, array $offsetIds): ?array
    {
        $in = implode(',', array_map('intval', $offsetIds));
        $parts = [];
        foreach (['invoice' => ['invoice_payments', 'invoice_id'], 'purchase_invoice' => ['payment_matches', 'purchase_invoice_id']] as $type => [$table, $column]) {
            $parts[] = "SELECT bt.statement_id
                          FROM credit_note_offsets o
                          JOIN {$table} p ON p.supplier_id = {$supplierId} AND p.{$column} = o.invoice_id
                          JOIN bank_transactions bt ON bt.id = p.bank_transaction_id AND bt.posted_at < o.offset_on
                         WHERE o.supplier_id = {$supplierId} AND o.doc_type = '{$type}' AND o.id IN ({$in})";
        }
        $parts[] = "SELECT bt.statement_id
                      FROM credit_note_offsets o
                      JOIN bank_transactions bt ON bt.matched_invoice_id = o.invoice_id AND bt.posted_at < o.offset_on
                     WHERE o.supplier_id = {$supplierId} AND o.doc_type = 'invoice' AND o.id IN ({$in})";
        $candidates = $this->db->pdo()->query(implode(' UNION ', $parts))->fetchAll(PDO::FETCH_COLUMN);
        if ($candidates === []) return [];
        $ids = implode(',', array_map('intval', $candidates));
        foreach ($this->db->pdo()->query("SELECT source FROM bank_statements WHERE id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN) as $source) {
            if (!BankStatementSource::isStatement((string) $source)) return null;
        }
        return $candidates;
    }

    public function legacyCashOverpayments(int $supplierId, array $cashIds): array
    {
        $rows = [];
        $documents = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $cashIds))), 200) as $chunk) {
            $in = implode(',', $chunk);
            $join = CreditNoteOffsetRemainderExpr::joinSql('invoice', $supplierId, 'i', 'cd.issue_date', 'legacy_cnr');
            $sql = "SELECT cd.id, cd.invoice_id, cd.total_amount, i.total_with_vat - legacy_cnr.gross AS remaining_gross
                      FROM cash_documents cd
                      JOIN invoices i ON i.id = cd.invoice_id AND i.supplier_id = {$supplierId}
                      {$join}
                     WHERE cd.supplier_id = {$supplierId} AND cd.id IN ({$in})
                       AND cd.invoice_payment_id IS NULL AND legacy_cnr.invoice_id IS NOT NULL";
            foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = $row;
                $documents[(int) $row['invoice_id']] = ['doc_type' => 'invoice', 'invoice_id' => (int) $row['invoice_id']];
            }
        }
        $coverage = $this->creditNoteCorrectionPaymentCoverage($supplierId, array_values($documents));
        $out = [];
        foreach ($rows as $row) {
            $payment = $coverage['invoice:' . $row['invoice_id'] . ':legacy_cash:' . $row['id']] ?? null;
            if (!($payment['comparable'] ?? false)) continue;
            $out[(int) $row['id']] = round((float) $row['total_amount'] * PaymentCoverage::overpaidShare(
                $payment['prior'], $payment['amount'], (float) $row['remaining_gross'],
            ), 2);
        }
        return $out;
    }

    private function creditNoteCorrectionPaymentCoverage(int $supplierId, array $corrections): array
    {
        $documents = [];
        foreach ($corrections as $correction) $documents[$correction['doc_type']][$correction['invoice_id']] = true;
        $coverage = [];
        foreach ($documents as $type => $documentIds) {
            foreach (array_chunk(array_keys($documentIds), 200) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                if ($type === 'invoice') {
                    $events = "SELECT ip.invoice_id AS document_id, 'invoice_payment' AS kind, ip.id,
                                      ip.paid_on AS event_date, 1 AS event_order, ip.amount,
                                      'DOC' AS event_currency, 'DOC' AS document_currency
                                 FROM invoice_payments ip
                                WHERE ip.supplier_id = {$supplierId} AND ip.invoice_id IN ({$in})
                                  AND ip.source <> 'credit_note'";
                } else {
                    $events = "SELECT pm.purchase_invoice_id AS document_id, 'bank' AS kind, pm.id,
                                      bt.posted_at AS event_date, 1 AS event_order,
                                      IF(bt.amount > 0, -ABS(pm.amount), ABS(pm.amount)) AS amount,
                                      COALESCE(NULLIF(bt.currency, ''), 'CZK') AS event_currency,
                                      COALESCE(cur.code, 'CZK') AS document_currency
                                 FROM payment_matches pm
                                 JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                                 JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = {$supplierId}
                                 LEFT JOIN currencies cur ON cur.id = pi.currency_id AND cur.supplier_id = {$supplierId}
                                WHERE pm.supplier_id = {$supplierId} AND pm.purchase_invoice_id IN ({$in})
                                UNION ALL
                               SELECT cd.purchase_invoice_id, 'cash', cd.id, cd.issue_date, 2,
                                      IF(cd.doc_type = 'in', -1, 1) * ABS(IF(COALESCE(NULLIF(cd.currency_code, ''), 'CZK') = 'CZK', cd.total_amount, cd.amount_foreign)),
                                      COALESCE(NULLIF(cd.currency_code, ''), 'CZK'), COALESCE(cur.code, 'CZK')
                                 FROM cash_documents cd
                                 JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id AND pi.supplier_id = {$supplierId}
                                 LEFT JOIN currencies cur ON cur.id = pi.currency_id AND cur.supplier_id = {$supplierId}
                                WHERE cd.supplier_id = {$supplierId} AND cd.purchase_invoice_id IN ({$in})
                                  AND cd.status = 'posted' AND cd.purpose = 'purchase_payment'
                                UNION ALL
                               SELECT s.doc_id, 'settlement', s.id, s.settled_on, 0, ABS(s.amount),
                                      COALESCE(cur.code, 'CZK'), COALESCE(cur.code, 'CZK')
                                 FROM invoice_settlements s
                                 JOIN purchase_invoices pi ON pi.id = s.doc_id AND pi.supplier_id = {$supplierId}
                                 LEFT JOIN currencies cur ON cur.id = pi.currency_id AND cur.supplier_id = {$supplierId}
                                WHERE s.supplier_id = {$supplierId} AND s.doc_type = 'purchase_invoice'
                                  AND s.doc_id IN ({$in}) AND s.status = 'confirmed'";
                }
                $sql = "SELECT e.*,
                               COALESCE(SUM(e.amount) OVER (PARTITION BY e.document_id ORDER BY e.event_date, e.event_order, e.id
                                   ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS prior,
                               SUM(e.event_currency <> e.document_currency) OVER (PARTITION BY e.document_id ORDER BY e.event_date, e.event_order, e.id
                                   ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS incompatible
                          FROM ({$events}) e";
                foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $coverage[$type . ':' . $row['document_id'] . ':' . $row['kind'] . ':' . $row['id']] = [
                        'prior' => (float) $row['prior'], 'amount' => (float) $row['amount'],
                        'comparable' => (int) $row['incompatible'] === 0,
                    ];
                }
                if ($type === 'invoice') {
                    $sql = "SELECT bt.id, bt.matched_invoice_id AS document_id, bt.amount,
                                   COALESCE(SUM(ip.amount), 0) AS prior,
                                   COALESCE(NULLIF(bt.currency, ''), 'CZK') = COALESCE(cur.code, 'CZK') AS comparable
                              FROM bank_transactions bt
                              JOIN invoices i ON i.id = bt.matched_invoice_id AND i.supplier_id = {$supplierId}
                              LEFT JOIN currencies cur ON cur.id = i.currency_id AND cur.supplier_id = {$supplierId}
                              LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id AND ip.supplier_id = {$supplierId}
                                   AND ip.source <> 'credit_note' AND ip.paid_on < bt.posted_at
                             WHERE bt.matched_invoice_id IN ({$in})
                             GROUP BY bt.id, bt.matched_invoice_id, bt.amount, bt.currency, cur.code";
                    foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $coverage['invoice:' . $row['document_id'] . ':legacy_bank:' . $row['id']] = [
                            'prior' => (float) $row['prior'], 'amount' => (float) $row['amount'],
                            'comparable' => (bool) $row['comparable'],
                        ];
                    }
                    $sql = "WITH legacy_documents AS (
                                SELECT DISTINCT invoice_id FROM cash_documents
                                 WHERE supplier_id = {$supplierId} AND invoice_id IN ({$in})
                                   AND purpose = 'invoice_payment' AND status = 'posted' AND invoice_payment_id IS NULL
                            )
                            SELECT e.*, COALESCE(SUM(e.amount) OVER (PARTITION BY e.document_id ORDER BY e.event_date, e.event_order, e.id
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS prior,
                                   SUM(e.event_currency <> e.document_currency) OVER (PARTITION BY e.document_id ORDER BY e.event_date, e.event_order, e.id
                                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS incompatible
                              FROM (
                                  SELECT ip.invoice_id AS document_id, 'invoice_payment' AS kind, ip.id,
                                         ip.paid_on AS event_date, 1 AS event_order, ip.amount,
                                         'DOC' AS event_currency, 'DOC' AS document_currency
                                    FROM invoice_payments ip JOIN legacy_documents ld ON ld.invoice_id = ip.invoice_id
                                   WHERE ip.supplier_id = {$supplierId} AND ip.source NOT IN ('cash', 'credit_note')
                                   UNION ALL
                                  SELECT cd.invoice_id, IF(cd.invoice_payment_id IS NULL, 'legacy_cash', 'invoice_payment'),
                                         COALESCE(cd.invoice_payment_id, cd.id), cd.issue_date, 2,
                                         IF(cd.doc_type = 'out', -1, 1) * ABS(IF(COALESCE(NULLIF(cd.currency_code, ''), 'CZK') = 'CZK', cd.total_amount, cd.amount_foreign)),
                                         COALESCE(NULLIF(cd.currency_code, ''), 'CZK'), COALESCE(cur.code, 'CZK')
                                    FROM cash_documents cd JOIN legacy_documents ld ON ld.invoice_id = cd.invoice_id
                                    JOIN invoices i ON i.id = cd.invoice_id AND i.supplier_id = {$supplierId}
                                    LEFT JOIN currencies cur ON cur.id = i.currency_id AND cur.supplier_id = {$supplierId}
                                   WHERE cd.supplier_id = {$supplierId} AND cd.status = 'posted' AND cd.purpose = 'invoice_payment'
                              ) e";
                    foreach ($this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $key = 'invoice:' . $row['document_id'] . ':' . $row['kind'] . ':' . $row['id'];
                        $coverage[$key] = [
                            'prior' => (float) $row['prior'], 'amount' => (float) $row['amount'],
                            'comparable' => (int) $row['incompatible'] === 0, 'raw' => $coverage[$key] ?? null,
                        ];
                    }
                }
            }
        }
        return $coverage;
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

    /**
     * #247: krytá část každé úhrady vydané faktury (`covered`, v měně faktury, znaménko
     * jako `ip.amount`). Úhrada hradí doklad jen do výše jeho dosud neuhrazeného brutta;
     * co platba přinese navíc, je přeplatek. Ten u plátce DPH žádnou DPH nenese, poměrem
     * DPH faktury se proto dělí jen krytá část (CashJournalService::invoiceIncomeBase).
     *
     * Dříve uhrazeno = součet všech předchozích úhrad téže faktury (jakýkoli source, i zápočet
     * a dobropis, protože i ty snižují neuhrazené brutto) v pořadí datum úhrady a id. Přeplatek
     * tak nese až úhrada, která zbytek faktury překročí, a součet DPH všech úhrad nikdy
     * nepřesáhne DPH faktury.
     *
     * Počítá se v relativních hodnotách vůči znaménku brutta (SIGN), takže dobropis se
     * zápornými součty a zápornými úhradami se chová zrcadlově k faktuře. Opačná úhrada
     * (vratka) nejdřív ubírá z přeplatku a teprve potom z kryté části. Nedoplatek zůstává
     * celý krytý, tedy dál poměrně dělený. Brutto 0 nemá co krýt, celá úhrada je mimo DPH
     * (shodně s prorateBase, která nulové brutto nedělí).
     *
     * Okno se počítá nad VŠEMI úhradami firmy bez datového filtru deníku, jinak by úhrada
     * z prosince neviděla zálohu z listopadu mimo zobrazené období.
     */
    private static function ipCoverageSql(int $sid, ?string $bankIds = null): string
    {
        $from = $bankIds === null ? 'invoice_payments ip'
            : "(SELECT DISTINCT scoped.invoice_id FROM invoice_payments scoped
                  WHERE scoped.supplier_id = {$sid} AND scoped.bank_transaction_id IN ({$bankIds})) scoped_docs
               STRAIGHT_JOIN invoice_payments ip FORCE INDEX (idx_invpay_invoice) ON ip.invoice_id = scoped_docs.invoice_id";
        return "SELECT q.ip_id,
                       q.s * (LEAST(GREATEST(q.s * (q.prior + q.amount), 0), q.w)
                              - LEAST(GREATEST(q.s * q.prior, 0), q.w)) AS covered
                  FROM (SELECT " . ($bankIds === null ? '' : 'STRAIGHT_JOIN ') . "ip.id AS ip_id, ip.amount,
                               SIGN(i.total_with_vat) AS s, ABS(i.total_with_vat) AS w,
                               COALESCE(SUM(ip.amount) OVER (PARTITION BY ip.invoice_id
                                                                ORDER BY ip.paid_on, ip.id
                                                                ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS prior
                          FROM {$from}
                          JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = {$sid}
                         WHERE ip.supplier_id = {$sid}) q";
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
                           WHERE bt.statement_id IN ({$inList}) AND bt.posted_at <= '{$toDate}'
                             AND " . BankNoticeLifecycle::activeSql('bt') . "
                             " . $this->provisionalMovementExclusionSql($stmtIds);
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
     * @param list<int>|null $candidateIds omezení na již vybrané výpisy; null zachovává celý původní výběr
     * @return list<int>
     */
    public function matchingStatementIds(int $supplierId, ?array $candidateIds = null): array
    {
        if ($candidateIds === []) return [];
        $pdo = $this->db->pdo();
        $allAccounts = $pdo->query(
            'SELECT supplier_id, account_number, bank_code, iban FROM currencies
              WHERE supplier_id IS NOT NULL AND (account_number IS NOT NULL OR iban IS NOT NULL)'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        $statements = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM bank_statements
              WHERE account_number IS NOT NULL AND account_number <> ''"
                . ($candidateIds === null ? '' : ' AND id IN (' . implode(',', array_map('intval', $candidateIds)) . ')')
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
     * #164: podmínka bankovní nohy, která vyřadí provizorní pohyby (e-mailové avízo,
     * iDoklad) tam, kde už platí oficiální výpis téhož účtu.
     *
     * Avízo je jen předběžná zpráva o pohybu. Dokud za jeho den chybí výpis, je jediným
     * dokladem peněžního toku a v deníku zůstává. Jakmile výpis týž den pokryje, nese
     * pohyb on a avízo by tentýž tok započetlo podruhé. Známé převzetí se vyřazuje
     * přes BankNoticeLifecycle i mimo období výpisu; tato dodatečná podmínka chrání
     * také starší data bez markeru převzetí a dosud nespárovaná avíza.
     *
     * Pokrytí = rozsah dat pohybů každého oficiálního výpisu účtu; sousední výpisy se
     * slévají přes mezeru do EmailNoticeReconciler::DATE_WINDOW_DAYS, aby denní výpisy
     * pokryly i víkend, kdy banka neúčtuje, ale karta platí. Delší mezera (chybějící
     * měsíc výpisů) pokrytá není a avíza z ní v deníku zůstanou.
     *
     * Ignorované avízo do deníku nepatří nikdy: ignoruje ho nahrazení výpisem nebo
     * uživatel, který tím říká, že k pohybu nedošlo. U oficiálního výpisu ignorování
     * jen vypíná párování, peníze se pohnuly, a proto se ho tahle podmínka netýká.
     *
     * Podvojné účetnictví avíza neúčtuje vůbec („Avízo se neúčtuje", BankPostingService).
     * Daňová evidence je kasová a firma bez importu výpisů nemá jiný doklad o pohybu
     * než avízo, proto se tu avízo počítá až do pokrytí výpisem.
     *
     * @param list<int> $stmtIds výpisy firmy z matchingStatementIds()
     */
    private function provisionalMovementExclusionSql(array $stmtIds): string
    {
        $pdo = $this->db->pdo();
        $inList = implode(',', array_map('intval', $stmtIds));
        $statements = $pdo->query(
            "SELECT id, source, account_number FROM bank_statements WHERE id IN ({$inList})"
        )->fetchAll(PDO::FETCH_ASSOC);

        $provisional = [];
        $official = [];
        foreach ($statements as $s) {
            $account = AccountNumberNormalizer::normalize((string) $s['account_number']);
            if (BankStatementSource::isStatement((string) $s['source'])) {
                $official[$account][] = (int) $s['id'];
            } else {
                $provisional[$account][] = (int) $s['id'];
            }
        }
        if ($provisional === []) {
            return '';
        }

        $covered = [];
        foreach ($provisional as $account => $ids) {
            $intervals = isset($official[$account]) ? $this->coveredIntervals($official[$account]) : [];
            if ($intervals === []) {
                continue;
            }
            $ranges = implode(' OR ', array_map(
                static fn (array $i): string => "bt.posted_at BETWEEN '{$i[0]}' AND '{$i[1]}'",
                $intervals,
            ));
            $covered[] = '(bt.statement_id IN (' . implode(',', $ids) . ") AND ({$ranges}))";
        }

        $allProvisional = implode(',', array_merge(...array_values($provisional)));
        $conditions = ["bt.match_status = 'ignored'"];
        if ($covered !== []) {
            $conditions[] = implode(' OR ', $covered);
        }
        return "AND NOT (bt.statement_id IN ({$allProvisional}) AND (" . implode(' OR ', $conditions) . '))';
    }

    /**
     * Období pokrytá oficiálními výpisy jednoho účtu jako seřazené, slité intervaly
     * [od, do] (YYYY-MM-DD).
     *
     * @param list<int> $statementIds
     * @return list<array{0:string,1:string}>
     */
    private function coveredIntervals(array $statementIds): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT MIN(posted_at) AS covered_from, MAX(posted_at) AS covered_to
               FROM bank_transactions
              WHERE statement_id IN (' . implode(',', array_map('intval', $statementIds)) . ')
              GROUP BY statement_id
              ORDER BY covered_from'
        )->fetchAll(PDO::FETCH_ASSOC);

        $merged = [];
        foreach ($rows as $row) {
            $from = substr((string) $row['covered_from'], 0, 10);
            $to = substr((string) $row['covered_to'], 0, 10);
            $last = count($merged) - 1;
            if ($last >= 0) {
                $gapLimit = (new \DateTimeImmutable($merged[$last][1]))
                    ->modify('+' . (EmailNoticeReconciler::DATE_WINDOW_DAYS + 1) . ' days')
                    ->format('Y-m-d');
                if ($from <= $gapLimit) {
                    $merged[$last][1] = max($merged[$last][1], $to);
                    continue;
                }
            }
            $merged[] = [$from, $to];
        }
        return $merged;
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
                  'bank_income_base', 'bank_income_exempt', 'bank_alloc_czk', 'bank_alloc_native',
                  'bank_native_amount', 'inv_overpaid'] as $k) {
            $r[$k] = (!array_key_exists($k, $r) || $r[$k] === null) ? null : round((float) $r[$k], 2);
        }
        $r['bank_alloc_cross_ccy'] = (int) ($r['bank_alloc_cross_ccy'] ?? 0) === 1;
        $r['inv_exempt']    = ($r['inv_exempt'] === null) ? null : (int) $r['inv_exempt'];
        $r['pi_deductible'] = ($r['pi_deductible'] === null) ? null : (int) $r['pi_deductible'];
        $r['pi_is_fixed_asset'] = ($r['pi_is_fixed_asset'] === null) ? null : (int) $r['pi_is_fixed_asset'];
        return $r;
    }
}
