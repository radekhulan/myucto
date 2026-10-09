<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * Křížová kontrola DPHDP3 ↔ KH ↔ SH ↔ obrat účtu 343 (audit 2026-07, C8').
 *
 * Finanční správa páruje přiznání k DPH s kontrolním a souhrnným hlášením strojově —
 * jakýkoliv nesoulad je typicky výzva/kontrola. Tahle služba dělá tentýž smířovací krok
 * ještě PŘED podáním: sestaví oba/všechny výkazy z JEDNOHO účetního deníku
 * ({@see VatLedgerService}) přes jejich REÁLNÉ buildery a porovná řádky, které FÚ páruje:
 *
 *   1. DPHDP3 ř.1+2 (tuzemská plnění na výstupu)     ↔ KH A.4 + A.5
 *   2. DPHDP3 ř.10+11 (tuzemský reverse charge)      ↔ KH B.1
 *   3. DPHDP3 ř.20+21 (dodání zboží / služby do JČS) ↔ Souhrnné hlášení
 *   4. Obrat MD/D účtu 343 v deníku                  ↔ vlastní daň / nadměrný odpočet
 *
 * NEDUPLIKUJE logiku výkazů — volá jejich veřejné metody ({@see KontrolniHlaseniBuilder::invoiceSections},
 * {@see SouhrnneHlaseniBuilder::build}, {@see VatClassificationMapper}), aby se kontrola
 * nikdy nerozešla s tím, co se REÁLNĚ podá. Vrací jen NALEZENÉ rozdíly (prázdné pole = vše
 * sedí). Read-only, žádná mutace — bezpečné na libovolný GET.
 */
final class VatCrossCheckService
{
    /** Haléřová tolerance pro shodu základů (per-doklad i součtově). */
    private const EPS = 0.005;

    /**
     * Tolerance smíru obratu 343 vs daň z přiznání. Zaúčtovaná daň je v haléřích,
     * přiznání zaokrouhluje po řádcích na celé Kč — pár korun je zaokrouhlení, ne chyba;
     * chybějící/přebývající doklad se projeví řádově výš.
     */
    private const ACCOUNT_343_TOLERANCE = 1.0;

    /** @var array<string, list<array<string,mixed>>> Memo KH sekcí per období (volá se 2×). */
    private array $khSectionsMemo = [];

    public function __construct(
        private readonly DphPriznaniBuilder $dph,
        private readonly KontrolniHlaseniBuilder $kh,
        private readonly SouhrnneHlaseniBuilder $shv,
        private readonly VatClassificationMapper $mapper,
        private readonly VatLedgerService $ledger,
        private readonly Connection $db,
    ) {}

    /**
     * Sestaví smír pro dané období. Vrací seznam NALEZENÝCH nesouladů (prázdný = vše sedí).
     * Každý nález nese `blocking` (tvrdý rozdíl bránící podání) nebo je jen informativní
     * poznámka (`severity='info'`, blocking=false — např. přeskočená kontrola 343, když
     * nejsou všechny doklady zaúčtované).
     *
     * Tvar dokladu v `documents`: invoice_id, doc_number, source, declared, counter,
     * difference — kontrola 4 navíc plní reason ENUM('timing_73','value_mismatch',
     * 'missing_entry','extra_entry') + claim_period/entry_date/received_at a nález nese
     * `explained` (kontroly 1–3 tyto klíče neplní).
     *
     * @return list<array{
     *   check:string, label:string, severity:string, blocking:bool,
     *   declared:?float, counter:?float, difference:float,
     *   documents:list<array<string,mixed>>,
     *   note:?string
     * }>
     */
    public function check(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $period = $this->resolvePeriod($supplierId, $period);
        [$start, $end] = self::periodBounds($year, $month, $period);

        // Jediná projekce kanonických řádků — DPHDP3 strana i drill-down z ní.
        $rows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: false);

        $findings = [];

        $findings = array_merge($findings, $this->pendingApprovalFindings($supplierId, $start, $end));
        $findings = array_merge($findings, $this->checkDraftAdvanceTaxDocuments($supplierId, $start, $end));
        $findings = array_merge($findings, $this->checkReverseChargeDomesticCodes($rows));
        $findings = array_merge($findings, $this->checkDomesticVsKh($supplierId, $year, $month, $period, $rows));
        $findings = array_merge($findings, $this->checkReverseChargeVsKh($supplierId, $year, $month, $period, $rows));
        $findings = array_merge($findings, $this->checkEuSuppliesVsSh($supplierId, $year, $month, $period, $rows));
        $findings = array_merge($findings, $this->checkAccount343($supplierId, $year, $month, $period, $start, $end, $rows));

        return $findings;
    }

    /**
     * Jen kontrola přijatých dokladů ve schvalování — pro kontrolní hlášení, které zbytek
     * smíru (DPHDP3 ↔ KH ↔ SH ↔ 343) nepotřebuje. Tytéž nálezy vrací i {@see check()}.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingApprovalCheck(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $period = $this->resolvePeriod($supplierId, $period);
        [$start, $end] = self::periodBounds($year, $month, $period);
        return $this->pendingApprovalFindings($supplierId, $start, $end);
    }

    /**
     * Přijatý doklad čekající na schválení (nebo zamítnutý) je koncept, takže v přiznání
     * ani v KH není. Odpočet lze uplatnit později (§ 73), samovyměření ale patří do
     * období DUZP a dodatečně by šlo jen opravou přiznání. Proto dvě úrovně: doklady se
     * samovyměřením blokují stažení stejně jako ostatní tvrdé rozdíly, ostatní jsou jen
     * informace. Doklady i jejich zařazení do období dává
     * {@see VatLedgerService::pendingApprovalPurchases}.
     *
     * @return list<array<string,mixed>>
     */
    private function pendingApprovalFindings(int $supplierId, string $start, string $end): array
    {
        $pending = $this->ledger->pendingApprovalPurchases($supplierId, $start, $end);
        if ($pending === []) {
            return [];
        }

        $selfAssessed = [];
        $deductionOnly = [];
        foreach ($pending as $p) {
            $amount = $p['self_assessment'] ? $p['self_assessed_vat_czk'] : $p['vat_czk'];
            $doc = [
                'invoice_id'            => $p['invoice_id'],
                'doc_number'            => $p['vendor_invoice_number'] ?? $p['doc_number'],
                'vendor_invoice_number' => $p['vendor_invoice_number'],
                'source'                => 'purchase',
                'declared'              => 0.0,
                'counter'               => $amount,
                'difference'            => round(-$amount, 2),
                'tax_date'              => $p['tax_date'],
                'claim_date'            => $p['claim_date'],
                'partner_name'          => $p['counterparty_name'],
                'approval_status'       => $p['approval_status'],
                'self_assessment'       => $p['self_assessment'],
            ];
            if ($p['self_assessment']) {
                $selfAssessed[] = $doc;
            } else {
                $deductionOnly[] = $doc;
            }
        }

        $findings = [];
        if ($selfAssessed !== []) {
            $total = round(array_sum(array_column($selfAssessed, 'counter')), 2);
            $findings[] = [
                'check'      => 'pending_approval_self_assessment',
                'label'      => 'Přijaté doklady se samovyměřením čekají na schválení',
                'severity'   => 'mismatch',
                'blocking'   => true,
                'declared'   => 0.0,
                'counter'    => $total,
                'difference' => round(-$total, 2),
                'documents'  => $selfAssessed,
                'note'       => 'Doklady v režimu přenesení daňové povinnosti (samovyměření) čekají na schválení '
                    . 'nebo byly zamítnuty, a proto v podání chybí. Daň ze samovyměření patří do období DUZP '
                    . 'a nelze ji přesunout do pozdějšího období. Doklady před podáním schvalte, nebo stornujte.',
            ];
        }
        if ($deductionOnly !== []) {
            $total = round(array_sum(array_column($deductionOnly, 'counter')), 2);
            $findings[] = [
                'check'      => 'pending_approval_deduction',
                'label'      => 'Přijaté doklady čekají na schválení',
                'severity'   => 'info',
                'blocking'   => false,
                'declared'   => 0.0,
                'counter'    => $total,
                'difference' => round(-$total, 2),
                'documents'  => $deductionOnly,
                'note'       => 'Doklady čekají na schválení nebo byly zamítnuty, a proto v podání chybí. '
                    . 'Odpočet z nich můžete uplatnit později.',
            ];
        }

        return $findings;
    }

    /**
     * Přijatý doklad s přenesenou povinností, jehož řádek nese tuzemský kód 40/41/42
     * (issue #119). Kód řádku přebije hlavičku, takže řádek míří na odpočet ř. 40/41
     * bez daně na výstupu: plátce si odečte daň, kterou nepřiznal, a identifikované
     * osobě doklad z přiznání zmizí celý. Uložení dokladu kódy řádků srovná
     * ({@see PurchaseInvoiceRepository::reconcileReverseChargeItemCodes()}); tady se
     * vyjmenují doklady uložené dřív.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function checkReverseChargeDomesticCodes(array $rows): array
    {
        $docs = [];
        foreach ($rows as $row) {
            if (($row['source'] ?? '') !== 'purchase' || empty($row['is_reverse_charge'])
                || !in_array((string) ($row['code'] ?? ''), PurchaseInvoiceRepository::DOMESTIC_INPUT_CODES, true)) {
                continue;
            }
            $id = (int) $row['invoice_id'];
            $doc = $docs[$id] ?? [
                'invoice_id'            => $id,
                'doc_number'            => $row['vendor_invoice_number'] ?? $row['doc_number'],
                'vendor_invoice_number' => $row['vendor_invoice_number'],
                'source'                => 'purchase',
                'declared'              => 0.0,
                'counter'               => 0.0,
                'difference'            => 0.0,
                'tax_date'              => $row['tax_date'],
                'partner_name'          => $row['counterparty_name'],
            ];
            $doc['counter'] = round($doc['counter'] + (float) $row['vat_czk'], 2);
            $doc['difference'] = round(-$doc['counter'], 2);
            $docs[$id] = $doc;
        }
        if ($docs === []) {
            return [];
        }
        $total = round(array_sum(array_column($docs, 'counter')), 2);
        return [[
            'check'      => 'reverse_charge_domestic_code',
            'label'      => 'Doklady s přenesenou povinností mají na řádcích tuzemský kód DPH',
            'severity'   => 'mismatch',
            'blocking'   => true,
            'declared'   => 0.0,
            'counter'    => $total,
            'difference' => round(-$total, 2),
            'documents'  => array_values($docs),
            'note'       => 'Doklady mají zatrženou přenesenou daňovou povinnost, ale řádky nesou tuzemský kód 40, 41 '
                . 'nebo 42. Daň se proto nesamovyměří a jde jen do odpočtu, identifikované osobě doklad '
                . 'z přiznání zmizí. Otevřete doklad, zkontrolujte Klasifikaci DPH (služba z EU 24e, '
                . 'ze 3. země 24, zboží z EU 23) a uložte ho. Uložení kódy řádků srovná.',
        ]];
    }

    /**
     * M26: přijatá úplata zakládá povinnost přiznat daň i tehdy, když navazující DDKP
     * nebo finální doklad zůstal konceptem. Nález je informativní, ale konkrétní doklady
     * vyjmenuje před stažením přiznání.
     *
     * @return list<array<string,mixed>>
     */
    private function checkDraftAdvanceTaxDocuments(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.total_with_vat,
                    COALESCE(i.effective_tax_date, i.tax_date, i.issue_date) AS tax_date
               FROM invoices i
               JOIN invoices p ON p.id = i.parent_invoice_id
              WHERE i.supplier_id = ?
                AND p.supplier_id = i.supplier_id
                AND p.invoice_type = 'proforma'
                AND i.invoice_type IN ('tax_document', 'invoice')
                AND i.status = 'draft'
                AND COALESCE(i.effective_tax_date, i.tax_date, i.issue_date) BETWEEN ? AND ?
           ORDER BY tax_date, i.id"
        );
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Platby proformy, ke kterým nevznikl vůbec žádný živý DDKP ani finální doklad.
        $missing = $this->db->pdo()->prepare(
            "SELECT p.id, p.varsymbol, 'missing' AS invoice_type,
                    SUM(ip.amount) AS total_with_vat, MIN(ip.paid_on) AS tax_date
               FROM invoice_payments ip
               JOIN invoices p ON p.id = ip.invoice_id AND p.supplier_id = ip.supplier_id
              WHERE ip.supplier_id = ?
                AND p.invoice_type = 'proforma'
                AND p.reverse_charge = 0
                AND ip.paid_on BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM invoices c
                     WHERE c.supplier_id = p.supplier_id
                       AND c.status <> 'cancelled'
                       AND c.invoice_type = 'invoice'
                       AND c.parent_invoice_id = p.id
                       AND COALESCE(c.effective_tax_date, c.tax_date, c.issue_date) BETWEEN ? AND ?
                )
                AND NOT EXISTS (
                    SELECT 1 FROM invoices td
                     WHERE td.supplier_id = p.supplier_id
                       AND td.status <> 'cancelled'
                       AND td.invoice_type = 'tax_document'
                       AND td.id = ip.tax_document_invoice_id
                )
           GROUP BY p.id, p.varsymbol
           ORDER BY tax_date, p.id"
        );
        $missing->execute([$supplierId, $start, $end, $start, $end]);
        $rows = array_merge($rows, $missing->fetchAll(\PDO::FETCH_ASSOC));
        if ($rows === []) {
            return [];
        }

        $documents = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $amount = round((float) $row['total_with_vat'], 2);
            $total += $amount;
            $documents[] = [
                'invoice_id' => (int) $row['id'],
                'doc_number' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
                'source'     => 'sale',
                'declared'   => 0.0,
                'counter'    => $amount,
                'difference' => -$amount,
                'tax_date'   => (string) $row['tax_date'],
                'document_kind' => (string) $row['invoice_type'],
            ];
        }

        return [[
            'check'      => 'draft_advance_tax_documents',
            'label'      => 'Nevystavené daňové doklady k přijatým zálohám v období',
            'severity'   => 'info',
            'blocking'   => false,
            'declared'   => 0.0,
            'counter'    => round($total, 2),
            'difference' => round(-$total, 2),
            'documents'  => $documents,
            'note'       => 'V období jsou koncepty DDKP nebo finálních dokladů z proformy. Ověř jejich vystavení před podáním DPH; daňová povinnost vzniká přijetím úplaty.',
        ]];
    }

    /**
     * Jen kontrola 4 (obrat 343 vs. daň z přiznání) — reuse pro měsíční kontrolu
     * mimo DPH stránku (audit 2026-07, D8). Stejné vstupy/hranice jako check(),
     * ale bez zbytečného sestavování KH/SH smíru (1–3), který D8 nepotřebuje.
     *
     * @return list<array<string,mixed>>
     */
    public function checkAccountBalanceVsReturn(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $period = $this->resolvePeriod($supplierId, $period);
        [$start, $end] = self::periodBounds($year, $month, $period);
        $rows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: false);
        return $this->checkAccount343($supplierId, $year, $month, $period, $start, $end, $rows);
    }

    /** Má aspoň jeden nález blokovat stažení (nenulový tvrdý rozdíl)? */
    public function hasBlockingMismatch(array $findings): bool
    {
        foreach ($findings as $f) {
            if (!empty($f['blocking'])) {
                return true;
            }
        }
        return false;
    }

    // ── kontrola 1: DPHDP3 ř.1+2 ↔ KH A.4+A.5 ─────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function checkDomesticVsKh(int $supplierId, int $year, int $month, string $period, array $rows): array
    {
        // DPHDP3 strana: základ řádků 1/2 (tuzemská zdanitelná na výstupu) per doklad.
        $dphByInvoice = [];
        $docNo = [];
        foreach ($rows as $r) {
            if ($r['source'] !== 'sale' || !in_array((string) $r['dphdp3_line'], ['1', '2'], true)) {
                continue;
            }
            $id = (int) $r['invoice_id'];
            $dphByInvoice[$id] = ($dphByInvoice[$id] ?? 0.0) + (float) $r['base_czk'];
            $docNo[$id] = $r['doc_number'];
        }

        // KH strana: efektivní sekce A.4/A.5 (reálné směrování buildera).
        $khByInvoice = [];
        foreach ($this->khInvoiceSections($supplierId, $year, $month, $period) as $g) {
            if ($g['source'] !== 'sale' || !in_array((string) $g['section'], ['A.4', 'A.5'], true)) {
                continue;
            }
            $id = (int) $g['invoice_id'];
            $khByInvoice[$id] = ($khByInvoice[$id] ?? 0.0) + (float) $g['base21'] + (float) $g['base12'];
            $docNo[$id] ??= $g['doc_number'];
        }

        return $this->diffByInvoice(
            'dphdp3_vs_kh_domestic',
            'DPHDP3 ř.1+2 (tuzemská plnění na výstupu) ↔ KH A.4 + A.5',
            'sale',
            $dphByInvoice,
            $khByInvoice,
            $docNo,
        );
    }

    // ── kontrola 2: DPHDP3 ř.10+11 ↔ KH B.1 ───────────────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function checkReverseChargeVsKh(int $supplierId, int $year, int $month, string $period, array $rows): array
    {
        $dphByInvoice = [];
        $docNo = [];
        foreach ($rows as $r) {
            if ($r['source'] !== 'purchase' || !in_array((string) $r['dphdp3_line'], ['10', '11'], true)) {
                continue;
            }
            $id = (int) $r['invoice_id'];
            $dphByInvoice[$id] = ($dphByInvoice[$id] ?? 0.0) + (float) $r['base_czk'];
            $docNo[$id] = $r['doc_number'];
        }

        $khByInvoice = [];
        foreach ($this->khInvoiceSections($supplierId, $year, $month, $period) as $g) {
            if ($g['source'] !== 'purchase' || (string) $g['section'] !== 'B.1') {
                continue;
            }
            $id = (int) $g['invoice_id'];
            $khByInvoice[$id] = ($khByInvoice[$id] ?? 0.0) + (float) $g['base_total'];
            $docNo[$id] ??= $g['doc_number'];
        }

        return $this->diffByInvoice(
            'dphdp3_vs_kh_reverse_charge',
            'DPHDP3 ř.10+11 (tuzemský reverse charge) ↔ KH B.1',
            'purchase',
            $dphByInvoice,
            $khByInvoice,
            $docNo,
        );
    }

    // ── kontrola 3: DPHDP3 ř.20+21 ↔ Souhrnné hlášení ─────────────────────────

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function checkEuSuppliesVsSh(int $supplierId, int $year, int $month, string $period, array $rows): array
    {
        // DPHDP3 strana: ř.20 (dodání zboží do JČS) + ř.21 (služby do JČS) per doklad.
        $dphByInvoice = [];
        $docNo = [];
        $dphTotal = 0.0;
        foreach ($rows as $r) {
            if ($r['source'] !== 'sale' || !in_array((string) $r['dphdp3_line'], ['20', '21'], true)) {
                continue;
            }
            $id = (int) $r['invoice_id'];
            $base = (float) $r['base_czk'];
            $dphByInvoice[$id] = ($dphByInvoice[$id] ?? 0.0) + $base;
            $dphTotal += $base;
            $docNo[$id] = $r['doc_number'];
        }

        // SH strana per doklad: rekonstrukce TÉŽE inkluzní podmínky, jakou má
        // SouhrnneHlaseniBuilder::collectEuSupplies (kód plnění 20/22 → sh_type 0/3,
        // EU země ≠ CZ, s DIČ), nad TOUTÉŽ projekcí, kterou builder konzumuje. Tím dostaneme
        // per-doklad rozpad, který vysvětlí KAŽDÝ rozdíl — nejen doklady bez DIČ, ale i EU
        // plnění na tuzemské/ne-EU zemi (DPHDP3 ř.20/21 je zahrne, SH je vyloučí) nebo per-doklad
        // odchylku částky. Kód 31 (třístranný obchod, sh_type 2) míří na ř.31, ne ř.20/21 → mimo.
        $shByInvoice = [];
        $causeNoDic = false;
        $causeNonEu = false;
        foreach ($rows as $r) {
            if ($r['source'] !== 'sale' || !in_array((string) ($r['code'] ?? ''), ['20', '22'], true)) {
                continue;
            }
            $id = (int) $r['invoice_id'];
            $docNo[$id] ??= $r['doc_number'];
            $dicMissing = $r['counterparty_dic'] === null || trim((string) $r['counterparty_dic']) === '';
            $isEuSupply = !empty($r['country_is_eu']) && $r['country_iso2'] !== null && $r['country_iso2'] !== 'CZ';
            if ($dicMissing || !$isEuSupply) {
                // Nevstupuje do SH → SH strana zůstává 0; příčinu si poznamenáme pro notu.
                if ($dicMissing) { $causeNoDic = true; }
                if (!$isEuSupply) { $causeNonEu = true; }
                continue;
            }
            $shByInvoice[$id] = ($shByInvoice[$id] ?? 0.0) + (float) $r['base_czk'];
        }

        // Headline zůstává z REÁLNÉHO buildera (autoritativní „co se skutečně podá"):
        // typ 0 = zboží (ř.20), typ 3 = služby (ř.21). Typ 2 (třístranný) do smíru nepatří.
        // Sčítá se `summary.rows.amount` v haléřích, ne podaná `pln_hodnota`: SH zaokrouhluje
        // každý řádek na celé Kč nahoru (předpis EPO, viz SouhrnneHlaseniBuilder::formatAmount),
        // přiznání až součet. Rozdíl do 1 Kč na řádek SH je tedy zaokrouhlení, ne nesoulad.
        $shResult = $this->shv->build($supplierId, $year, $month, $period);
        $shTotal = 0.0;
        $shTriangular = 0.0;
        foreach ($shResult['summary']['rows'] ?? [] as $r) {
            $type = (string) $r['sh_type'];
            if ($type === '0' || $type === '3') {
                $shTotal += (float) $r['amount'];
            } elseif ($type === '2') {
                $shTriangular += (float) $r['amount'];
            }
        }

        $difference = round($dphTotal - $shTotal, 2);
        if (abs($difference) <= self::EPS) {
            return [];
        }

        // Úplný drill-down: per-doklad diff DPHDP3 vs SH strana (obě z téže projekce).
        $documents = [];
        $ids = array_unique(array_merge(array_keys($dphByInvoice), array_keys($shByInvoice)));
        sort($ids);
        foreach ($ids as $id) {
            $dd = round($dphByInvoice[$id] ?? 0.0, 2);
            $ss = round($shByInvoice[$id] ?? 0.0, 2);
            $d = round($dd - $ss, 2);
            if (abs($d) > self::EPS) {
                $documents[] = [
                    'invoice_id' => (int) $id,
                    'doc_number' => $docNo[$id] ?? null,
                    'source'     => 'sale',
                    'declared'   => $dd,
                    'counter'    => $ss,
                    'difference' => $d,
                ];
            }
        }

        $causes = [];
        if ($causeNoDic) { $causes[] = 'EU dodání bez DIČ odběratele'; }
        if ($causeNonEu) { $causes[] = 'plnění klasifikované jako EU, ale na tuzemské/ne-EU zemi'; }
        $causeText = $causes !== []
            ? implode(' nebo ', $causes) . ' (do SH nevstupují, přestože je DPHDP3 vykazuje)'
            : 'odlišná částka nebo klasifikace dokladu';
        $note = 'DPHDP3 ř.20+21 (dodání zboží/služeb do JČS) se neshoduje se souhrnným hlášením. '
            . 'Možná příčina: ' . $causeText . '. Doplň DIČ odběratele, oprav zemi/klasifikaci, '
            . 'nebo ověř zařazení dokladu.';
        if ($shTriangular > self::EPS) {
            $note .= sprintf(' Pozn.: SH obsahuje i třístranný obchod (%s Kč), který v DPHDP3 patří na ř.31, ne ř.20/21.',
                number_format($shTriangular, 0, ',', ' '));
        }

        return [[
            'check'      => 'dphdp3_vs_sh',
            'label'      => 'DPHDP3 ř.20+21 (plnění do JČS) ↔ Souhrnné hlášení',
            'severity'   => 'mismatch',
            'blocking'   => true,
            'declared'   => round($dphTotal, 2),
            'counter'    => round($shTotal, 2),
            'difference' => $difference,
            'documents'  => $documents,
            'note'       => $note,
        ]];
    }

    // ── kontrola 4: obrat účtu 343 ↔ vlastní daň / nadměrný odpočet ────────────

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function checkAccount343(int $supplierId, int $year, int $month, string $period, string $start, string $end, array $rows): array
    {
        $accountId = $this->resolve343AccountId($supplierId);
        if ($accountId === null) {
            // Firma bez podvojné osnovy (daňová evidence / nezaúčtováno) — 343 kontrola nedává smysl.
            return [];
        }

        // Srovnání dává smysl JEN když jsou VŠECHNY DPH doklady období zaúčtované — jinak
        // nesoulad je jen „nezaúčtováno", ne chyba. Vrátíme informativní poznámku místo
        // tvrdého rozdílu (blocking=false → nebrání stažení).
        $unposted = $this->countUnpostedDphDocs($supplierId, $rows);
        if ($unposted > 0) {
            return [[
                'check'      => 'account_343_vs_return',
                'label'      => 'Obrat účtu 343 ↔ vlastní daň / nadměrný odpočet',
                'severity'   => 'info',
                'blocking'   => false,
                'declared'   => null,
                'counter'    => null,
                'difference' => 0.0,
                'documents'  => [],
                'note'       => sprintf(
                    'Kontrola obratu účtu 343 přeskočena: v období je %d nezaúčtovaných DPH dokladů. '
                        . 'Zaúčtuj je a smír proběhne — do té doby by rozdíl znamenal jen „nezaúčtováno", ne chybu.',
                    $unposted,
                ),
            ]];
        }

        // Zaúčtovaný obrat 343 (vč. analytik pod syntetikou) za období dle entry_date.
        // VYLUČUJE úhrady/vratky DPH (343 proti bance/pokladně 221/211/261, doklad z banky/pokladny)
        // — ty vypořádávají už PŘIZNANOU daň minulých období, nejsou plněním období a v přiznání
        // za období nemají protějšek (detail viz booked343ByDoc). Headline i per-doklad drill-down
        // čtou TÝŽ filtrovaný obrat (booked343ByDoc) → invariant Σ per-doklad == headline platí
        // i po vyloučení a nedojde k dvojímu (odlišnému) výpočtu obratu.
        $booked = $this->booked343ByDoc($supplierId, $accountId, $start, $end);
        $booked343Net = 0.0;
        foreach ($booked as $b) {
            $booked343Net += $b['net'];
        }
        $booked343Net = round($booked343Net, 2); // D − MD = daň k odvodu

        // Daň z přiznání: EXAKTNÍ výstup−vstup z téhož deníku (443 se účtuje z týchž částek
        // jako DPH evidence → bezztrátové srovnání; zaokrouhlené přiznání ukážeme pro kontext).
        $dphResult = $this->dph->build($supplierId, $year, $month, $period);
        $byLine = $dphResult['summary']['lines'] ?? [];
        $exact = $this->mapper->dphSummaryTotals($byLine);
        $exactNet = round((float) $exact['due'], 2);
        $declaredNet = round((float) ($dphResult['summary']['tax_due'] ?? 0.0), 2); // Veta6 dan_zocelk − odp_zocelk

        $difference = round($booked343Net - $exactNet, 2);
        if (abs($difference) <= self::ACCOUNT_343_TOLERANCE) {
            return [];
        }

        // Per-doklad drill-down + klasifikace důvodů (§ 73 timing / věcný rozdíl).
        // Rozdíl plně vysvětlený časovými posuny odpočtu podle § 73 ZDPH neblokuje —
        // přiznání je věcně správně a rozdíl se v následujícím období přirozeně otočí.
        // Doklady s poměrným odpočtem § 75 / kráceným nárokem § 76 se timingem nikdy
        // nevysvětlují (koeficient se počítá až na úrovni období → explained by byl
        // nepřesný) — končí jako value_mismatch; bezpečný směr: kontrola blokuje,
        // nikdy tiše nepropustí. Blokuje i jakýkoli věcný (ne-timing) rozdíl dokladu
        // nad toleranci, i kdyby se rozdíly v součtu vynetovaly.
        $dd = $this->drillDown343($supplierId, $accountId, $start, $end, $rows, $booked);
        $unexplained = round($difference - $dd['explained'], 2);
        $hasMaterialMismatch = false;
        foreach ($dd['documents'] as $doc) {
            if ($doc['reason'] !== 'timing_73' && abs((float) $doc['difference']) > self::ACCOUNT_343_TOLERANCE) {
                $hasMaterialMismatch = true;
                break;
            }
        }
        $blocking = abs($unexplained) > self::ACCOUNT_343_TOLERANCE || $hasMaterialMismatch;

        if (!$blocking) {
            $note = sprintf(
                'Rozdíl %s Kč je vysvětlen časovým posunem odpočtu podle § 73 ZDPH (viz doklady) '
                    . '— přiznání je věcně správně a rozdíl se v následujícím období přirozeně otočí. '
                    . 'Stažení není blokováno.',
                number_format($difference, 2, ',', ' '),
            );
        } elseif (abs($unexplained) > self::ACCOUNT_343_TOLERANCE) {
            $note = sprintf(
                'Zaúčtovaný obrat účtu 343 (%s Kč) se neshoduje s daní z přiznání (%s Kč, přiznáno %s Kč). '
                    . 'Zkontrolujte, zda jsou zaúčtované všechny DPH doklady a zda účtování DPH odpovídá klasifikaci. '
                    . 'Časové posuny § 73 vysvětlují %s Kč, nevysvětlený zbytek %s Kč — zkontrolujte doklady v rozpisu.',
                number_format($booked343Net, 2, ',', ' '),
                number_format($exactNet, 2, ',', ' '),
                number_format($declaredNet, 2, ',', ' '),
                number_format($dd['explained'], 2, ',', ' '),
                number_format($unexplained, 2, ',', ' '),
            );
        } else {
            $note = sprintf(
                'Zaúčtovaný obrat účtu 343 (%s Kč) se neshoduje s daní z přiznání (%s Kč, přiznáno %s Kč). '
                    . 'Věcné rozdíly dokladů se v součtu vynetovaly (časové posuny § 73 vysvětlují %s Kč), '
                    . 'ale rozpis obsahuje rozdíly mimo § 73 — zkontrolujte doklady v rozpisu.',
                number_format($booked343Net, 2, ',', ' '),
                number_format($exactNet, 2, ',', ' '),
                number_format($declaredNet, 2, ',', ' '),
                number_format($dd['explained'], 2, ',', ' '),
            );
        }

        return [[
            'check'      => 'account_343_vs_return',
            'label'      => 'Obrat účtu 343 ↔ vlastní daň / nadměrný odpočet',
            'severity'   => $blocking ? 'mismatch' : 'info',
            'blocking'   => $blocking,
            'declared'   => $declaredNet,
            'counter'    => $booked343Net,
            'difference' => $difference,
            'explained'  => $dd['explained'],
            'documents'  => $dd['documents'],
            'note'       => $note,
        ]];
    }

    /**
     * Per-doklad drill-down rozdílu 343 ↔ přiznání + klasifikace důvodů.
     *
     * ⚠ Orientace `difference` = counter − declared (booked − exact, TÁŽ jako headline
     * rozdíl kontroly 4); kontroly 1–3 mají declared − counter. Invariant:
     * round(Σ documents.difference, 2) == headline difference (kryje test T5).
     *
     * @param list<array<string,mixed>> $rows projekce VatLedgerService za období
     * @param array<string, array{net:float, entry_date:string}> $booked filtrovaný obrat 343
     *        per doklad (bez úhrad/vratek DPH) — TÝŽ, ze kterého je spočítán headline
     * @return array{documents: list<array<string,mixed>>, explained: float}
     */
    private function drillDown343(int $supplierId, int $accountId, string $start, string $end, array $rows, array $booked): array
    {
        [$declared, $docNo] = $this->declared343ByDoc($rows);

        $keys = array_unique(array_merge(array_keys($booked), array_keys($declared)));
        sort($keys);

        // Rozdílové klíče + batch podklady pro klasifikaci (žádné per-doklad dotazy).
        $diffs = [];
        $bookedOnlyPurchase = [];
        $bookedOnlySale = [];
        $bookedOnlyCash = [];
        $declaredOnly = [];
        foreach ($keys as $k) {
            $b = round((float) ($booked[$k]['net'] ?? 0.0), 2);
            $d = round((float) ($declared[$k] ?? 0.0), 2);
            $diff = round($b - $d, 2);
            if (abs($diff) <= self::EPS) {
                continue;
            }
            $diffs[$k] = ['booked' => $b, 'declared' => $d, 'diff' => $diff];
            [$type, $id] = array_pad(explode(':', (string) $k, 2), 2, '0');
            if (abs($d) <= self::EPS && abs($b) > self::EPS) {
                if ($type === 'purchase_invoice') {
                    $bookedOnlyPurchase[] = (int) $id;
                } elseif ($type === 'invoice') {
                    $bookedOnlySale[] = (int) $id;
                } elseif ($type === 'cash') {
                    $bookedOnlyCash[] = (int) $id;
                }
            } elseif (abs($b) <= self::EPS && abs($d) > self::EPS) {
                $declaredOnly[] = (string) $k;
            }
        }

        $claimIds = $bookedOnlyPurchase;
        foreach ($declaredOnly as $k) {
            [$type, $id] = array_pad(explode(':', (string) $k, 2), 2, '0');
            if ($type === 'purchase_invoice') {
                $claimIds[] = (int) $id;
            }
        }
        $claimInfo = $this->ledger->purchaseClaimInfo($supplierId, $claimIds);
        $outside = $this->entry343Outside($supplierId, $accountId, $declaredOnly, $start, $end);
        $saleNo = $this->docNumbersByIds($supplierId, 'invoices', 'varsymbol', $bookedOnlySale);
        $cashNo = $this->docNumbersByIds($supplierId, 'cash_documents', 'doc_number', $bookedOnlyCash);

        $sourceMap = ['invoice' => 'sale', 'purchase_invoice' => 'purchase', 'cash' => 'cash', 'manual' => 'manual'];

        $documents = [];
        $explained = 0.0;
        foreach ($diffs as $k => $v) {
            [$type, $id] = array_pad(explode(':', (string) $k, 2), 2, '0');
            $id = (int) $id;
            $reason = 'value_mismatch';
            $claimPeriod = null;
            $entryDate = null;
            $receivedAt = null;
            $number = $docNo[$k] ?? null;

            if (abs($v['declared']) <= self::EPS && abs($v['booked']) > self::EPS) {
                // Deník ano, přiznání ne. timing_73 smí dostat JEN doklad, který v claim
                // období reálně vstoupí do přiznání — tj. splní tytéž filtry jako
                // fetchPurchases (status, advance, nárok na odpočet). Poměrný nárok § 75
                // timing nedostane nikdy (procento krácení je per doklad). Krácený nárok
                // § 76 ano: evidence nese plnou daň (ř. 40k/41k) stejně jako předpis 343
                // a koeficient se uplatní až souhrnně na ř. 52, takže posun je přesný.
                $reason = 'extra_entry';
                if ($type === 'purchase_invoice' && isset($claimInfo[$id])) {
                    $info = $claimInfo[$id];
                    $number ??= $info['doc_number'];
                    if ($info['claim_date'] !== ''
                        && ($info['claim_date'] > $end || $info['claim_date'] < $start)
                        && self::entersReturn($info)
                    ) {
                        if ($info['vat_deduction'] === 'proportional') {
                            $reason = 'value_mismatch';
                        } else {
                            $reason = 'timing_73';
                            $claimPeriod = substr($info['claim_date'], 0, 7);
                            $receivedAt = $info['received_at_source'] === 'manual' ? $info['received_at'] : null;
                        }
                    }
                } elseif ($type === 'invoice') {
                    $number ??= $saleNo[$id] ?? null;
                } elseif ($type === 'cash') {
                    $number ??= $cashNo[$id] ?? null;
                }
            } elseif (abs($v['booked']) <= self::EPS && abs($v['declared']) > self::EPS) {
                // Přiznání ano, deník v období ne. Zrcadlový timing_73 náleží JEN přijatému
                // dokladu s plným nárokem, jehož živý net mimo období odpovídá přiznanému
                // příspěvku — sale/cash zápis v jiném období není § 73 posun a odlišná
                // částka je věcný rozdíl.
                if (isset($outside[$k])) {
                    $info = $type === 'purchase_invoice' ? ($claimInfo[$id] ?? null) : null;
                    $isTiming = $info !== null
                        && $info['vat_deduction'] !== 'proportional'
                        && abs($outside[$k]['net'] - $v['declared']) <= self::ACCOUNT_343_TOLERANCE;
                    if ($isTiming) {
                        $reason = 'timing_73';
                        $entryDate = $outside[$k]['entry_date'];
                    } else {
                        $reason = 'value_mismatch';
                    }
                } else {
                    $reason = 'missing_entry';
                }
            }

            if ($reason === 'timing_73') {
                $explained = round($explained + $v['diff'], 2);
            }

            $documents[] = [
                'invoice_id'   => $id,
                'doc_type'     => $type === 'manual' ? 'journal_entry' : $type,
                'doc_number'   => $number,
                'source'       => $sourceMap[$type] ?? $type,
                'declared'     => $v['declared'],
                'counter'      => $v['booked'],
                'difference'   => $v['diff'],
                'reason'       => $reason,
                'claim_period' => $claimPeriod,
                'entry_date'   => $entryDate,
                'received_at'  => $receivedAt,
            ];
        }

        // Datum a protistrana pro VŠECHNY řádky rozpisu — jedním dotazem na typ dokladu.
        // Bez nich zůstával v detailu kontroly sloupec Datum prázdný a řádek neměl ani
        // protistranu: rozpis říkal „u dokladu 236 nesedí 83,77 Kč" a účetní neměla podle
        // čeho ten doklad v účetnictví najít. `entry_date` (jen u zrcadlového § 73 posunu)
        // se nepřepisuje — je to datum zápisu MIMO období, tedy jiná informace než datum
        // vystavení dokladu.
        $meta = [];
        foreach (['invoice', 'purchase_invoice', 'cash', 'journal_entry'] as $docType) {
            $ids = [];
            foreach ($documents as $d) {
                if ($d['doc_type'] === $docType) {
                    $ids[] = (int) $d['invoice_id'];
                }
            }
            if ($ids !== []) {
                $meta[$docType] = $this->docMetaByIds($supplierId, $docType, array_values(array_unique($ids)));
            }
        }
        foreach ($documents as &$d) {
            $m = $meta[$d['doc_type']][(int) $d['invoice_id']] ?? null;
            $d['doc_date'] = $d['entry_date'] ?? ($m['doc_date'] ?? null);
            $d['partner_name'] = $m['partner_name'] ?? null;
            $d['doc_number'] ??= $m['doc_number'] ?? null;
            // Částka nálezu = o kolik doklad nesedí. Bez ní se v detailu zobrazoval
            // prázdný sloupec Částka, přestože rozdíl je jádrem celé kontroly.
            $d['amount'] = $d['difference'];
        }
        unset($d);

        return ['documents' => $documents, 'explained' => $explained];
    }

    /**
     * Zaúčtovaný netto obrat 343 (credit − debit) per zdrojový doklad za období.
     * JEDINÝ zdroj obratu 343 pro kontrolu 4 — headline (Σ net) i per-doklad drill-down čtou
     * TÝŽ výsledek, takže invariant Σ per-doklad == headline platí (test §4 T5). Filtry jako
     * accountTurnovers (posted_at NOT NULL, bez filtru reversed_by — storno páry se vynetují,
     * entry_date BETWEEN, analytiky přes parent_id) PLUS níže popsané vyloučení úhrad/vratek DPH.
     *
     * VYLOUČENÍ ÚHRAD/VRATEK DPH: zápis z banky/pokladny (source_type IN ('bank','cash')),
     * jehož 343 řádek stojí VÝHRADNĚ proti peněžnímu účtu (211/221/261) a NEobsahuje žádnou
     * jinou (základovou/nákladovou/výnosovou) protistranu, je vypořádání JIŽ PŘIZNANÉ daně
     * minulého období na FÚ (nebo vratka nadměrného odpočtu) — pohybuje 343, ale není plněním
     * období a v přiznání za období nemá protějšek. Kdyby se do obratu započítal, kontrola by
     * hlásila falešný „nevysvětlený rozdíl" ve výši úhrady a zablokovala stažení. Podmínka
     * NOT EXISTS (jiná než 343/peněžní protistrana) záměrně NEVYLUČUJE reálné pokladní DPH
     * doklady (211/6xx/343) ani DPH z bankovních poplatků (568/343/221) — ty základovou/
     * nákladovou stranu mají, takže zůstávají a dál se řádně smiřují.
     *
     * @return array<string, array{net:float, entry_date:string}>
     *         klíč "{source_type}:{source_id}" | "manual:{entry_id}" (zápis bez zdroje)
     */
    private function booked343ByDoc(int $supplierId, int $accountId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(CONCAT(e.source_type, ':', e.source_id), CONCAT('manual:', e.id)) AS doc_key,
                    MIN(e.entry_date) AS entry_date,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) AS net
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                AND e.entry_date BETWEEN ? AND ?
                -- Uzavření a otevření knih (ČÚS 002) NENÍ daňová transakce: k rozvahovému
                -- dni se zůstatek 343 převede na 702 a k 1. dni dalšího období se přes 701
                -- vrátí. Bez téhle výjimky se celý zůstatek 343 objeví v obratu Q4 jako
                -- přebývající zápis a v Q1 dalšího roku s opačným znaménkem — kontrola pak
                -- hlásí dva falešné nesoulady, které se navzájem přesně ruší.
                --
                -- Vyplavalo to až spuštěním kontroly nad CELÝM rokem (CrossCheckSuite):
                -- uzávěrkový zápis padne jen do Q4, takže dřívější volání za jedno období
                -- na něj většinou nenarazilo. Je to přitom stejný druh výjimky jako
                -- vyloučení úhrad DPH níž — jen na něj nikdo nepomyslel.
                AND e.source_type NOT IN ('closing', 'opening')
                AND NOT (
                    e.source_type IN ('bank', 'cash')
                    AND EXISTS (
                        SELECT 1 FROM journal_entry_lines ml
                          JOIN chart_of_accounts mca ON mca.id = ml.account_id
                         WHERE ml.entry_id = e.id
                           AND (mca.account_code LIKE '211%' OR mca.account_code LIKE '221%' OR mca.account_code LIKE '261%')
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM journal_entry_lines ol
                          JOIN chart_of_accounts oca ON oca.id = ol.account_id
                         WHERE ol.entry_id = e.id
                           AND oca.account_code NOT LIKE '343%'
                           AND oca.account_code NOT LIKE '211%'
                           AND oca.account_code NOT LIKE '221%'
                           AND oca.account_code NOT LIKE '261%'
                    )
                )
              GROUP BY doc_key
             HAVING ABS(net) > 0.004"
        );
        $stmt->execute([$supplierId, $accountId, $accountId, $start, $end]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['doc_key']] = [
                'net'        => round((float) $r['net'], 2),
                'entry_date' => substr((string) $r['entry_date'], 0, 10),
            ];
        }
        return $out;
    }

    /**
     * Příspěvek dokladu k dani z přiznání (výstup − vstup), týž klíč jako booked343ByDoc.
     * sale → +vat_czk; purchase → −vat_czk; purchase RC samovyměření → 0 (výstup i vstup
     * se vyruší — shodně s předpisem MD 343 / D 343). Mapování klíče = countUnpostedDphDocs:
     * document_kind='cash' → 'cash', sale → 'invoice', jinak 'purchase_invoice'.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{0: array<string,float>, 1: array<string,?string>} [mapa, doc_number mapa]
     */
    private function declared343ByDoc(array $rows): array
    {
        $map = [];
        $docNo = [];
        foreach ($rows as $r) {
            $sourceType = ($r['document_kind'] ?? null) === 'cash'
                ? 'cash'
                : ($r['source'] === 'sale' ? 'invoice' : 'purchase_invoice');
            $key = $sourceType . ':' . (int) $r['invoice_id'];
            $docNo[$key] ??= $r['doc_number'] !== null ? (string) $r['doc_number'] : null;
            if ($r['source'] === 'sale') {
                $contribution = (float) $r['vat_czk'];
            } elseif (!empty($r['is_reverse_charge'])) {
                $contribution = 0.0;
            } else {
                $contribution = -(float) $r['vat_czk'];
            }
            $map[$key] = round(($map[$key] ?? 0.0) + $contribution, 2);
        }
        return [$map, $docNo];
    }

    /**
     * Živý netto obrat 343 (credit − debit) MIMO období pro dané klíče (zrcadlový směr
     * § 73: nárokováno teď, zaúčtováno dřív/později). Row-constructor IN nad
     * (source_type, source_id). Stornovaný zápis není živý net: reversed_by IS NULL ho
     * vyřadí (storno zrcadlo má source_id NULL, přes klíč by se nevynetovalo) a 343 řádky
     * vynetované na 0 uvnitř zápisu odfiltruje HAVING (→ missing_entry, ne timing);
     * klasifikátor navíc porovnává net s přiznaným příspěvkem, takže odlišná částka
     * skončí jako value_mismatch.
     *
     * @param list<string> $keys klíče "{source_type}:{source_id}"
     * @return array<string, array{entry_date:string, net:float}> jen nalezené s živým netem
     */
    private function entry343Outside(int $supplierId, int $accountId, array $keys, string $start, string $end): array
    {
        if ($keys === []) {
            return [];
        }
        $pairs = [];
        $params = [$supplierId, $accountId, $accountId, $start, $end];
        foreach ($keys as $k) {
            [$type, $id] = array_pad(explode(':', $k, 2), 2, '0');
            $pairs[] = '(?, ?)';
            $params[] = $type;
            $params[] = (int) $id;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT CONCAT(e.source_type, ':', e.source_id) AS doc_key,
                    MIN(e.entry_date) AS entry_date,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) AS net
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                AND e.entry_date NOT BETWEEN ? AND ?
                AND (e.source_type, e.source_id) IN (" . implode(',', $pairs) . ")
              GROUP BY doc_key
             HAVING ABS(net) > 0.004"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['doc_key']] = [
                'entry_date' => substr((string) $r['entry_date'], 0, 10),
                'net'        => round((float) $r['net'], 2),
            ];
        }
        return $out;
    }

    /**
     * Doklad v claim období reálně vstoupí do přiznání — tytéž filtry jako
     * {@see VatLedgerService::fetchPurchases} (status NOT IN draft/cancelled,
     * document_kind <> 'advance', vat_deduction <> 'none'; NULL vat_deduction je
     * v SQL vyřazen taky).
     *
     * @param array{status:string, document_kind:?string, vat_deduction:?string} $info
     */
    private static function entersReturn(array $info): bool
    {
        return !in_array($info['status'], ['draft', 'cancelled'], true)
            && ($info['document_kind'] ?? '') !== 'advance'
            && $info['vat_deduction'] !== null
            && $info['vat_deduction'] !== 'none';
    }

    /**
     * Čísla dokladů pro booked-only strany drill-downu (sale/cash bez řádku v projekci).
     *
     * @param list<int> $ids
     * @return array<int,?string>
     */
    /**
     * Číslo, datum vystavení a protistrana dokladů jedním dotazem — podklad pro detail
     * rozpisu 343. Pokladní doklad nese protistranu textem (`partner_name`), faktury přes
     * vazbu na `clients`; typ, který sem nepatří, vrací prázdno místo výjimky, aby rozpis
     * nespadl kvůli chybějícímu popisku.
     *
     * @param list<int> $ids
     * @return array<int, array{doc_number:?string, doc_date:?string, partner_name:?string}>
     */
    private function docMetaByIds(int $supplierId, string $docType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = match ($docType) {
            'invoice' => 'SELECT d.id, d.varsymbol AS doc_number, d.issue_date AS doc_date,
                                 c.company_name AS partner_name
                            FROM invoices d LEFT JOIN clients c ON c.id = d.client_id',
            'purchase_invoice' => 'SELECT d.id, d.varsymbol AS doc_number, d.issue_date AS doc_date,
                                          c.company_name AS partner_name
                                     FROM purchase_invoices d LEFT JOIN clients c ON c.id = d.vendor_id',
            'cash' => 'SELECT d.id, d.doc_number, d.issue_date AS doc_date, d.partner_name
                         FROM cash_documents d',
            // Ruční zápis na 343 (přeúčtování analytik, haléřové vyrovnání) doklad nemá,
            // takže se hlásí sám za sebe — ale datum a číslo zápisu v deníku JSOU. Bez
            // téhle větve zůstal jediný takový řádek rozpisu bez data i bez označení
            // a účetní neměla podle čeho ten zápis v deníku najít.
            'journal_entry' => 'SELECT d.id, d.document_no AS doc_number, d.entry_date AS doc_date,
                                       NULL AS partner_name
                                  FROM journal_entries d',
            default => null,
        };
        if ($sql === null) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare($sql . " WHERE d.supplier_id = ? AND d.id IN ({$placeholders})");
        $stmt->execute(array_merge([$supplierId], $ids));

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'doc_number'   => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                'doc_date'     => $r['doc_date'] !== null ? (string) $r['doc_date'] : null,
                'partner_name' => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
            ];
        }

        return $out;
    }

    private function docNumbersByIds(int $supplierId, string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, {$column} AS doc_number FROM {$table} WHERE supplier_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r['doc_number'] !== null ? (string) $r['doc_number'] : null;
        }
        return $out;
    }

    // ── společné pomůcky ──────────────────────────────────────────────────────

    /**
     * KH sekce per doklad — memoizované. `check()` je páruje dvakrát (A.4/A.5 a B.1);
     * bez memo by builder projel účetní deník (a jeho projekci) dvakrát zbytečně.
     *
     * @return list<array<string,mixed>>
     */
    private function khInvoiceSections(int $supplierId, int $year, int $month, string $period): array
    {
        $key = "{$supplierId}|{$year}|{$month}|{$period}";
        return $this->khSectionsMemo[$key] ??= $this->kh->invoiceSections($supplierId, $year, $month, $period);
    }

    /**
     * Diff dvou map (invoice_id → základ) → nález s drill-down dokladů, které jsou jen
     * na jedné straně nebo se liší částkou. Nic → prázdné pole (shoda).
     *
     * @param array<int,float> $dphByInvoice
     * @param array<int,float> $khByInvoice
     * @param array<int,?string> $docNo
     * @return list<array<string,mixed>>
     */
    private function diffByInvoice(string $check, string $label, string $source, array $dphByInvoice, array $khByInvoice, array $docNo): array
    {
        $documents = [];
        $ids = array_unique(array_merge(array_keys($dphByInvoice), array_keys($khByInvoice)));
        foreach ($ids as $id) {
            $dd = round($dphByInvoice[$id] ?? 0.0, 2);
            $kk = round($khByInvoice[$id] ?? 0.0, 2);
            $d = round($dd - $kk, 2);
            if (abs($d) > self::EPS) {
                $documents[] = [
                    'invoice_id' => (int) $id,
                    'doc_number' => $docNo[$id] ?? null,
                    'source'     => $source,
                    'declared'   => $dd,
                    'counter'    => $kk,
                    'difference' => $d,
                ];
            }
        }

        $dphTotal = round(array_sum($dphByInvoice), 2);
        $khTotal = round(array_sum($khByInvoice), 2);
        $difference = round($dphTotal - $khTotal, 2);

        if ($documents === [] && abs($difference) <= self::EPS) {
            return [];
        }

        return [[
            'check'      => $check,
            'label'      => $label,
            'severity'   => 'mismatch',
            'blocking'   => true,
            'declared'   => $dphTotal,
            'counter'    => $khTotal,
            'difference' => $difference,
            'documents'  => $documents,
            'note'       => 'Základ na straně DPHDP3 se neshoduje s kontrolním hlášením. '
                . 'FÚ obě strany páruje strojově — sjednoť klasifikaci nebo doplň chybějící doklad.',
        ]];
    }

    /**
     * Počet DPH-relevantních dokladů období, které NEMAJÍ aktivní zaúčtovaný zápis
     * (posted_at NOT NULL, reversed_by NULL). Doklady bereme z projekce DPH evidence
     * ({@see VatLedgerService}) — přesně to, co feeduje DPHDP3.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function countUnpostedDphDocs(int $supplierId, array $rows): int
    {
        // Distinct doklady per source_type deníku.
        $bySource = ['invoice' => [], 'purchase_invoice' => [], 'cash' => []];
        foreach ($rows as $r) {
            $sourceType = ($r['document_kind'] ?? null) === 'cash'
                ? 'cash'
                : ($r['source'] === 'sale' ? 'invoice' : 'purchase_invoice');
            $bySource[$sourceType][(int) $r['invoice_id']] = true;
        }

        $unposted = 0;
        foreach ($bySource as $sourceType => $ids) {
            if ($ids === []) {
                continue;
            }
            $idList = array_keys($ids);
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT source_id FROM journal_entries
                  WHERE supplier_id = ? AND source_type = ? AND source_id IN ({$placeholders})
                    AND posted_at IS NOT NULL AND reversed_by IS NULL"
            );
            $stmt->execute(array_merge([$supplierId, $sourceType], $idList));
            $posted = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
                $posted[(int) $sid] = true;
            }
            foreach ($idList as $id) {
                if (!isset($posted[$id])) {
                    $unposted++;
                }
            }
        }
        return $unposted;
    }

    /**
     * account_id syntetiky 343 pro tenanta (analytiky se rolují přes parent_id v
     * accountTurnovers). NULL = firma nemá účet 343 v osnově (daňová evidence / neseednuto).
     */
    private function resolve343AccountId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = '343'
              ORDER BY is_synthetic DESC, id ASC
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        return ($id === false || $id === null) ? null : (int) $id;
    }

    private function resolvePeriod(int $supplierId, ?string $period): string
    {
        if (in_array($period, ['monthly', 'quarterly'], true)) {
            return $period;
        }
        $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $p = (string) ($stmt->fetchColumn() ?: 'monthly');
        return in_array($p, ['monthly', 'quarterly'], true) ? $p : 'monthly';
    }

    /**
     * @return array{0:string, 1:string} [start, end] — shodné s KH/SH/mapper.
     */
    private static function periodBounds(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
        } else {
            $endMonth = $month;
            $start = sprintf('%04d-%02d-01', $year, $month);
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');
        return [$start, $end];
    }
}
