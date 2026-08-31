<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Support\ExchangeRateSources;
use MyInvoice\Support\PaymentMethods;
use MyInvoice\Support\PublicAuthorityFeeText;
use MyInvoice\Support\Sql\PayablePredicate;
use PDO;

/**
 * CRUD pro přijaté faktury (purchase invoices) — paralel k InvoiceRepository,
 * ale pro doklady, které dostáváme od dodavatelů.
 *
 * Klíčové rozdíly oproti vystaveným fakturám:
 *   - vendor_id místo client_id (vendor = protistrana, řádek v `clients` s is_vendor=1)
 *   - status lifecycle: draft → received → booked → paid (+ cancelled)
 *   - žádný approval / sent / reminder flow
 *   - varsymbol generovaný z purchase_invoice_counters dle per-supplier šablony
 *     (supplier.purchase_invoice_number_format) nebo defaultu {PP}{YY}{MM}{CCC}
 *     (např. PF2602001); {PP} dle daňového typu (PF/PN plný, KU/KN krácený, NU/NN bez nároku)
 *
 * Bezpečnostní pravidla:
 *   - Vždy filtrovat WHERE supplier_id = ? (tenant scope)
 *   - Mutating operace ověřit ownership přes find() s supplier_id
 *   - Žádné raw SQL s user input — vždy prepared statements
 */
final class PurchaseInvoiceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly AccountingModeRepository $accountingModes,
    ) {}

    /** @var array<int,bool> currency_id → je to CZK; viz isCzkCurrency() */
    private array $czkCurrencyCache = [];

    /**
     * Najde fakturu jen pokud patří danému tenantovi.
     * Vrací null jak pro neexistující, tak pro cizí (consistent — neprozrazuje cross-tenant existenci).
     */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.*,
                    c.company_name AS vendor_company_name, c.ic AS vendor_ic, c.dic AS vendor_dic,
                    c.is_vat_payer AS vendor_current_is_vat_payer,
                    c.main_email AS vendor_main_email, c.language AS vendor_language,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    pcur.code AS payment_currency, pcur.symbol AS payment_currency_symbol,
                    ec.label AS expense_category_label, ec.code AS expense_category_code,
                    prj.name AS project_name, prj.project_number AS project_number
               FROM purchase_invoices pi
               JOIN clients c        ON c.id   = pi.vendor_id
               JOIN currencies cur   ON cur.id = pi.currency_id
          LEFT JOIN currencies pcur  ON pcur.id = pi.payment_currency_id
          LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
          LEFT JOIN projects prj     ON prj.id = pi.project_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        // Efektivní plátcovství dodavatele pro tento doklad: primárně zmrazený snapshot
        // (migrace 0133), u legacy dokladů (NULL) fallback na živý příznak klienta. Editor
        // i daňová logika pracují s touto konkrétní bool hodnotou.
        $snapshot = $row['vendor_is_vat_payer'] ?? null;
        $vendorCurrent = $row['vendor_current_is_vat_payer'] ?? null;
        unset($row['vendor_current_is_vat_payer']);

        $row = $this->castInvoice($row);
        $row['vendor_is_vat_payer'] = $snapshot !== null
            ? (bool) (int) $snapshot
            : ($vendorCurrent !== null ? (bool) (int) $vendorCurrent : true);
        $row['items'] = $this->itemsFor($id);
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['vat_allocations'] = $this->vatAllocationsFor($id, $supplierId);
        $row['totals'] = [
            'without_vat'         => $row['total_without_vat'],
            'vat'                 => $row['total_vat'],
            'with_vat'            => $row['total_with_vat'],
            'rounding'            => $row['rounding'],
            'advance_paid_amount' => $row['advance_paid_amount'],
            'amount_to_pay'       => $row['amount_to_pay'],
        ];

        // Propojení se zálohou (advance):
        //  - linked_advance   = záloha, kterou tato finální faktura vyúčtovává
        //  - settled_by       = finální faktura vyúčtovávající tuto zálohu (reverzně)
        //  - advance_link_suggestion = AI návrh (suggest & confirm), čeká na potvrzení
        $row['linked_advance'] = $row['advance_purchase_invoice_id'] !== null
            ? $this->briefFor((int) $row['advance_purchase_invoice_id'], $supplierId)
            : null;
        $row['advance_link_suggestion'] = $row['advance_link_suggested_id'] !== null
            ? $this->briefFor((int) $row['advance_link_suggested_id'], $supplierId)
            : null;
        // Zpětná vazba „kdo mě vyúčtoval". Platí i pro samostatný daňový doklad k platbě —
        // ten zálohovou fakturu nahrazuje, takže se stejně jako ona jednou vyúčtuje konečnou
        // fakturou a uživatel to na něm musí vidět.
        $row['settled_by'] = in_array((string) ($row['document_kind'] ?? ''), ['advance', 'tax_document'], true)
            ? $this->settledByFor($id, $supplierId)
            : null;

        // Dobropis (credit_note): původní přijatá faktura, kterou opravuje (migrace 1096).
        //  - linked_parent = shrnutí té faktury (jen když je vazba nastavená a patří tenantovi)
        //  - has_parent_candidates = dobropis bez vazby, ale existuje faktura téhož dodavatele
        $row['linked_parent'] = ($row['parent_purchase_invoice_id'] ?? null) !== null
            ? $this->briefFor((int) $row['parent_purchase_invoice_id'], $supplierId)
            : null;
        $row['has_parent_candidates'] = false;
        if (($row['document_kind'] ?? '') === 'credit_note' && $row['parent_purchase_invoice_id'] === null) {
            $q = $this->db->pdo()->prepare(
                "SELECT EXISTS (
                          SELECT 1 FROM purchase_invoices pi
                           WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                             AND pi.document_kind = 'invoice' AND pi.status != 'cancelled'
                             AND pi.id <> ?
                        )"
            );
            $q->execute([$supplierId, (int) ($row['vendor_id'] ?? 0), $id]);
            $row['has_parent_candidates'] = (bool) $q->fetchColumn();
        }
        // Reverzní pohled: dobropisy, které tento doklad opravují (na faktuře uvidím
        // „opravováno dobropisem X"). Může jich být víc (částečné dobropisy).
        $row['corrected_by'] = ($row['document_kind'] ?? '') !== 'credit_note'
            ? $this->correctedByFor($id, $supplierId)
            : [];

        // Příznaky pro UI tlačítka „spárovat" (zobrazit jen když existuje protějšek):
        //  - has_advance_candidates    = vyúčtovací faktura bez vazby a existuje nespárovaná záloha
        //                                (zálohová faktura NEBO samostatný DDKP, viz advanceCandidates())
        //  - has_settlement_candidates = záloha (nebo samostatný DDKP) bez vyúčtování a existuje
        //                                nepropojená finální faktura
        //
        // Kandidáti musí zrcadlit advanceCandidates()/settlementCandidates() — jinak tlačítko
        // zůstane skryté i tam, kde by párování prošlo. Přesně tohle byla příčina #17: samostatný
        // DDKP (nákup kartou, bez zálohové faktury) linkAdvance()/advanceCandidates() propojit umí
        // (viz 7c59a643), ale tenhle příznak počítal jen s document_kind='advance' → tlačítko
        // „spárovat se zálohou" se u DDKP nikdy nezobrazilo a uživatel neměl jak vazbu založit.
        $row['has_advance_candidates'] = false;
        $row['has_settlement_candidates'] = false;
        $vendorId = (int) ($row['vendor_id'] ?? 0);
        $documentKind = (string) ($row['document_kind'] ?? '');
        // "Zálohou" se pro účely párování chová i samostatný DDKP (bez rodičovské zálohy) —
        // DDKP navázaný NA zálohu (parent_purchase_invoice_id != null) se vyúčtovává přes ni,
        // proto se sem NEPOČÍTÁ (viz linkAdvance()).
        $isAdvanceLike = $documentKind === 'advance'
            || ($documentKind === 'tax_document' && $row['parent_purchase_invoice_id'] === null);
        if (!in_array($documentKind, ['advance', 'tax_document'], true)) {
            if ($row['advance_purchase_invoice_id'] === null) {
                $q = $this->db->pdo()->prepare(
                    "SELECT EXISTS (
                              SELECT 1 FROM purchase_invoices pi
                               WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                                 AND (pi.document_kind = 'advance'
                                      OR (pi.document_kind = 'tax_document' AND pi.parent_purchase_invoice_id IS NULL))
                                 AND pi.status != 'cancelled'
                                 AND pi.id <> ?
                                 AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                                  WHERE s.advance_purchase_invoice_id = pi.id)
                            )"
                );
                $q->execute([$supplierId, $vendorId, $id]);
                $row['has_advance_candidates'] = (bool) $q->fetchColumn();
            }
        } elseif ($isAdvanceLike && $row['settled_by'] === null) {
            $q = $this->db->pdo()->prepare(
                "SELECT EXISTS (
                          SELECT 1 FROM purchase_invoices pi
                           WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                             AND pi.document_kind != 'advance' AND pi.status != 'cancelled'
                             AND pi.advance_purchase_invoice_id IS NULL AND pi.id <> ?
                        )"
            );
            $q->execute([$supplierId, $vendorId, $id]);
            $row['has_settlement_candidates'] = (bool) $q->fetchColumn();
        }

        // Upozornění na dokladu se zálohou/DDKP, který zůstává NESPÁROVANÝ, i když k němu
        // pravděpodobně patří konkrétní nespárovaná faktura téhož dodavatele: dnes se rozdíl
        // (zaplaceno kartou/zálohou vs. co fakturuje konečná faktura) tiše drží na 314 beze
        // stopy. U DDKP rovnou spočítá, kolik DPH zbývá doúčtovat na 343 — stejné číslo, které
        // dnes uživatel dostane až z hlášky advance_settlement_ambiguous při neúspěšném pokusu
        // o zaúčtování (viz PostingService::appendAdvanceSettlementPurchase).
        $row['unsettled_notice'] = null;
        if ($isAdvanceLike && $row['status'] !== 'cancelled' && $row['settled_by'] === null) {
            $paid = $this->paidAdvanceAmount($id, $supplierId);
            if ($paid > 0.0) {
                $candidate = null;
                $candidatesForNotice = $this->settlementCandidatesFor(
                    $id, $vendorId, (int) ($row['currency_id'] ?? 0), (float) ($row['total_with_vat'] ?? 0), $supplierId,
                );
                foreach ($candidatesForNotice as $c) {
                    if ($c['document_kind'] === 'invoice') {
                        $candidate = $c;
                        break;
                    }
                }
                if ($candidate !== null) {
                    $remainingVat = $documentKind === 'tax_document'
                        ? round((float) $candidate['total_vat'] - (float) $row['total_vat'], 2)
                        : null;
                    $label = $candidate['varsymbol'] ?? $candidate['vendor_invoice_number'] ?? ('#' . $candidate['id']);
                    $message = 'Na účtu 314 zůstává z tohoto dokladu otevřených '
                        . number_format($paid, 2, ',', ' ') . ' Kč. Od stejného dodavatele existuje '
                        . 'nespárovaná faktura ' . $label . ' (' . number_format((float) $candidate['total_with_vat'], 2, ',', ' ')
                        . ' Kč) — pravděpodobně k sobě patří, spárujte je tlačítkem výše.';
                    if ($remainingVat !== null) {
                        $message .= ' Po spárování zbývá na 343 doúčtovat ' . number_format($remainingVat, 2, ',', ' ')
                            . ' Kč (DPH faktury ' . number_format((float) $candidate['total_vat'], 2, ',', ' ')
                            . ' Kč − už uplatněná DPH z DDKP ' . number_format((float) $row['total_vat'], 2, ',', ' ') . ' Kč).';
                    }
                    $row['unsettled_notice'] = [
                        'paid_amount' => round($paid, 2),
                        'candidate' => [
                            'id'                    => (int) $candidate['id'],
                            'varsymbol'             => $candidate['varsymbol'],
                            'vendor_invoice_number' => $candidate['vendor_invoice_number'],
                            'total_with_vat'        => (float) $candidate['total_with_vat'],
                        ],
                        'remaining_vat_on_343' => $remainingVat,
                        'message'              => $message,
                    ];
                }
            }
        }

        // Bankovní úhrady dokladu — proklik z detailu na příslušný bankovní výpis.
        // Jen POSTED bank zápisy (journal_entries source_type='bank', reversed_by IS NULL),
        // vzor viz paidAdvanceAmount(). Splátky = víc řádků.
        $row['bank_payments'] = $this->bankPaymentsFor($id, $supplierId);
        // Hotovostní úhrady dokladu — pokladní doklady (PPD/VPD) POSTED přes journal_entry_id.
        $row['cash_payments'] = $this->cashPaymentsFor($id, $supplierId);
        // Úhrady zápočtem proti účtu (migrace 1126) — třetí zaúčtovaný kanál vedle banky
        // a pokladny. Bez něj by doklad uhrazený zápočtem hlásil mark_paid_unposted,
        // přestože zápis v deníku existuje.
        $row['settlement_payments'] = $this->settlementPaymentsFor($id, $supplierId);

        // Transparence ruční/legacy úhrady: status='paid', ale NEEXISTUJE žádná zaúčtovaná
        // úhrada (banka, pokladna ani zápočet) → závazek 321 zůstává v deníku otevřený
        // (viz doklad 163). FE ukáže výrazné upozornění; proklik není kam vést.
        //
        // Jen pro rok vedený v podvojném účetnictví. V daňové evidenci / paušálu žádný deník
        // ani účet 321 neexistuje, ruční „Uhrazeno" je tam jediný způsob, jak úhradu
        // zaznamenat — varování by hlásilo závadu, která nemůže nastat. Režim bereme k roku
        // dokladu (ne k dnešku): po přechodu na podvojné účetnictví musí starší doklady
        // zůstat tiše v evidenci a novější dál hlásit.
        $row['mark_paid_unposted'] = ($row['status'] === 'paid')
            && $row['bank_payments'] === []
            && $row['cash_payments'] === []
            && $row['settlement_payments'] === []
            && $this->accountingModes->forYear($supplierId, $this->documentYear($row)) === 'double_entry';
        return $row;
    }

    /**
     * Rok, do kterého doklad účetně patří — pod ním se hledá platný režim účetnictví.
     * Primárně DUZP (podle něj se účtuje), fallback datum vystavení a až nakonec dnešek.
     */
    private function documentYear(array $row): int
    {
        foreach (['tax_date', 'issue_date'] as $field) {
            $date = $row[$field] ?? null;
            if (is_string($date) && preg_match('/^(\d{4})-/', $date, $m) === 1) {
                return (int) $m[1];
            }
        }
        return (int) date('Y');
    }

    /**
     * Bankovní úhrady přijaté faktury pro proklik na výpis (statement_id). Zahrnuje
     * jen matche s POSTED bank zápisem (journal_entries source_type='bank',
     * source_id=bank_transaction_id, reversed_by IS NULL). Řazeno dle data zaúčtování.
     *
     * @return list<array{bank_transaction_id:int, statement_id:int, amount:float,
     *                     posted_at:string, counterparty:?string, currency:string,
     *                     journal_entry_id:int}>
     */
    private function bankPaymentsFor(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pm.bank_transaction_id, bt.statement_id, pm.amount,
                    bt.posted_at, bt.counterparty_name, je.id AS journal_entry_id,
                    UPPER(COALESCE(bt.currency, bs.currency, 'CZK')) AS currency
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN journal_entries je
                 ON je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                AND je.source_id = pm.bank_transaction_id AND je.reversed_by IS NULL
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?
              ORDER BY bt.posted_at, pm.id"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'bank_transaction_id' => (int) $r['bank_transaction_id'],
                'statement_id'        => (int) $r['statement_id'],
                'amount'              => (float) $r['amount'],
                'posted_at'           => (string) $r['posted_at'],
                'counterparty'        => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
                'currency'            => (string) $r['currency'],
                'journal_entry_id'    => (int) $r['journal_entry_id'],
            ];
        }
        return $out;
    }

    /**
     * Hotovostní úhrady přijaté faktury — pokladní doklady (PPD/VPD) navázané na
     * fakturu (purchase_invoice_id) a POSTED přes journal_entry_id (migrace 1019).
     * Proklik z detailu na zaúčtování úhrady (deník) a na příslušnou pokladnu.
     *
     * @return list<array{cash_document_id:int, doc_number:?string, amount:float,
     *                     date:string, register_id:int, register_name:?string,
     *                     journal_entry_id:int, currency:string}>
     */
    private function cashPaymentsFor(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT cd.id AS cash_document_id, cd.doc_number, cd.total_amount AS amount,
                    cd.issue_date, cd.register_id, cd.journal_entry_id,
                    cr.name AS register_name,
                    UPPER(COALESCE(cd.currency_code, 'CZK')) AS currency
               FROM cash_documents cd
               JOIN cash_registers cr ON cr.id = cd.register_id
              WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ?
                AND cd.status = 'posted' AND cd.journal_entry_id IS NOT NULL
              ORDER BY cd.issue_date, cd.id"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'cash_document_id' => (int) $r['cash_document_id'],
                'doc_number'       => $r['doc_number'] !== null ? (string) $r['doc_number'] : null,
                'amount'           => (float) $r['amount'],
                'date'             => (string) $r['issue_date'],
                'register_id'      => (int) $r['register_id'],
                'register_name'    => $r['register_name'] !== null ? (string) $r['register_name'] : null,
                'journal_entry_id' => (int) $r['journal_entry_id'],
                'currency'         => (string) $r['currency'],
            ];
        }
        return $out;
    }

    /**
     * Úhrady zápočtem proti účtu (invoice_settlements, migrace 1126) — proklik z detailu
     * na příslušný účetní zápis. Jen POTVRZENÉ zápočty; zrušené (status='cancelled') mají
     * protizápis a doklad se vrátil do nezaplaceného stavu, takže se jako úhrada nepočítají.
     *
     * @return list<array{settlement_id:int, amount:float, date:string, account_code:string,
     *                     account_name:string, note:?string, journal_entry_id:?int}>
     */
    private function settlementPaymentsFor(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.amount, s.settled_on, s.note, s.journal_entry_id,
                    a.account_code, a.name AS account_name
               FROM invoice_settlements s
               JOIN chart_of_accounts a ON a.id = s.account_id
              WHERE s.supplier_id = ? AND s.doc_type = 'purchase_invoice' AND s.doc_id = ?
                AND s.status = 'confirmed'
              ORDER BY s.settled_on, s.id"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'settlement_id'    => (int) $r['id'],
                'amount'           => (float) $r['amount'],
                'date'             => (string) $r['settled_on'],
                'account_code'     => (string) $r['account_code'],
                'account_name'     => (string) $r['account_name'],
                'note'             => $r['note'] !== null ? (string) $r['note'] : null,
                'journal_entry_id' => $r['journal_entry_id'] !== null ? (int) $r['journal_entry_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Stručné shrnutí přijaté faktury (pro propojení/odkazy v detailu). NULL pokud
     * neexistuje nebo nepatří tenantovi.
     *
     * @return array{id:int, varsymbol:?string, vendor_invoice_number:?string,
     *               document_kind:?string, status:string, issue_date:?string,
     *               total_with_vat:float, currency:string}|null
     */
    private function briefFor(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) return null;
        return [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ];
    }

    /** Finální faktura, která vyúčtovává tuto zálohu (reverzní pohled). */
    private function settledByFor(int $advanceId, int $supplierId): ?array
    {
        $id = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE advance_purchase_invoice_id = ? AND supplier_id = ? LIMIT 1'
        );
        $id->execute([$advanceId, $supplierId]);
        $finalId = $id->fetchColumn();
        return $finalId !== false ? $this->briefFor((int) $finalId, $supplierId) : null;
    }

    /**
     * Dobropisy, které opravují tuto přijatou fakturu (reverzní pohled k parent_purchase_invoice_id,
     * migrace 1096). Může jich být víc — částečné dobropisy k téže faktuře.
     *
     * @return list<array<string,mixed>>
     */
    private function correctedByFor(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM purchase_invoices
              WHERE parent_purchase_invoice_id = ? AND supplier_id = ?
                AND document_kind = 'credit_note'
              ORDER BY issue_date, id"
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $brief = $this->briefFor((int) $cid, $supplierId);
            if ($brief !== null) {
                $out[] = $brief;
            }
        }
        return $out;
    }

    /**
     * Items dané přijaté faktury, seřazené.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsFor(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.quantity, pii.unit,
                    pii.unit_price_without_vat, pii.vat_rate_id, pii.vat_rate_snapshot,
                    pii.total_without_vat, pii.total_vat, pii.total_with_vat,
                    pii.order_index, pii.vat_classification_code, pii.is_fixed_asset,
                    pii.expense_kind, pii.expense_account_code,
                    pii.accrual_from, pii.accrual_to,
                    pii.stock_item_id, si.sku AS stock_sku, si.name AS stock_name,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en,
                    sa.id AS small_asset_id, sa.name AS small_asset_name, sa.status AS small_asset_status
               FROM purchase_invoice_items pii
               JOIN vat_rates vr ON vr.id = pii.vat_rate_id
               LEFT JOIN stock_items si ON si.id = pii.stock_item_id
               -- Karta drobného majetku vzniklá z téhle položky (§DM) — vazba
               -- purchase_invoice_item_id → small_assets existovala, ale bez ní se karta
               -- musela dohledávat ručně. Detail dokladu teď rovnou nabídne vyřazení prodejem.
               LEFT JOIN small_assets sa ON sa.purchase_invoice_item_id = pii.id
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function vatAllocationsFor(int $purchaseInvoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, purchase_invoice_id, description, usage_type, vat_rate,
                    base_amount, vat_amount, total_amount, vat_deduction,
                    vat_deduction_percent, tax_treatment, account_code,
                    vat_classification_code, order_index
               FROM purchase_invoice_vat_allocations
              WHERE purchase_invoice_id = ? AND supplier_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'purchase_invoice_id', 'order_index'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            foreach (['vat_rate', 'base_amount', 'vat_amount', 'total_amount', 'vat_deduction_percent'] as $field) {
                $row[$field] = (float) $row[$field];
            }
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<array<string,mixed>> $allocations
     */
    public function replaceVatAllocations(int $purchaseInvoiceId, int $supplierId, array $allocations): void
    {
        $pdo = $this->db->pdo();
        $invoice = $pdo->prepare(
            'SELECT total_without_vat, total_vat, reverse_charge
               FROM purchase_invoices
              WHERE id = ? AND supplier_id = ?'
        );
        $invoice->execute([$purchaseInvoiceId, $supplierId]);
        $totals = $invoice->fetch(PDO::FETCH_ASSOC);
        if ($totals === false) {
            throw new \InvalidArgumentException('Přijatá faktura neexistuje.');
        }

        if ($allocations === []) {
            $pdo->prepare(
                'DELETE FROM purchase_invoice_vat_allocations
                  WHERE purchase_invoice_id = ? AND supplier_id = ?'
            )->execute([$purchaseInvoiceId, $supplierId]);
            $pdo->prepare('UPDATE purchase_invoices SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND supplier_id = ?')
                ->execute([$purchaseInvoiceId, $supplierId]);
            return;
        }
        if ((bool) $totals['reverse_charge']) {
            throw new \InvalidArgumentException('Účetní alokace zatím nelze použít u reverse charge dokladu.');
        }

        $allowedUsage = ['business', 'personal', 'mixed', 'non_deductible'];
        $allowedDeduction = ['full', 'none', 'proportional', 'reduced'];
        $allowedTax = ['deductible', 'non_deductible', 'not_expense'];
        $sumBase = 0.0;
        $sumVat = 0.0;
        $expectedStmt = $pdo->prepare(
            'SELECT vat_rate_snapshot, SUM(total_without_vat) AS base_amount,
                    SUM(total_vat) AS vat_amount
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              GROUP BY vat_rate_snapshot'
        );
        $expectedStmt->execute([$purchaseInvoiceId]);
        $expectedByRate = [];
        foreach ($expectedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expectedByRate[number_format((float) $row['vat_rate_snapshot'], 2, '.', '')] = [
                'base' => (float) $row['base_amount'], 'vat' => (float) $row['vat_amount'],
            ];
        }
        $allocatedByRate = [];
        foreach ($allocations as $allocation) {
            $description = trim((string) ($allocation['description'] ?? ''));
            $usage = (string) ($allocation['usage_type'] ?? 'business');
            $deduction = (string) ($allocation['vat_deduction'] ?? 'full');
            $taxTreatment = (string) ($allocation['tax_treatment'] ?? 'deductible');
            $accountCode = trim((string) ($allocation['account_code'] ?? ''));
            $base = round((float) ($allocation['base_amount'] ?? 0), 2);
            $vat = round((float) ($allocation['vat_amount'] ?? 0), 2);
            $total = round((float) ($allocation['total_amount'] ?? ($base + $vat)), 2);
            $percent = max(0.0, min(100.0, (float) ($allocation['vat_deduction_percent'] ?? 100)));
            $rateKey = number_format((float) ($allocation['vat_rate'] ?? 0), 2, '.', '');

            if ($description === '' || $accountCode === '') {
                throw new \InvalidArgumentException('Každá účetní alokace musí mít popis a účet.');
            }
            if (!in_array($usage, $allowedUsage, true)
                || !in_array($deduction, $allowedDeduction, true)
                || !in_array($taxTreatment, $allowedTax, true)
            ) {
                throw new \InvalidArgumentException('Účetní alokace obsahuje nepodporovaný režim.');
            }
            if ($usage === 'personal' && ($deduction !== 'none' || $taxTreatment !== 'not_expense')) {
                throw new \InvalidArgumentException('Osobní alokace musí být bez nároku na odpočet a nesmí být nákladem firmy.');
            }
            if ($usage === 'mixed' && $deduction !== 'proportional') {
                throw new \InvalidArgumentException('Smíšené využití musí používat poměrný odpočet podle § 75.');
            }
            if (abs(($base + $vat) - $total) > 0.02) {
                throw new \InvalidArgumentException('Základ a DPH účetní alokace neodpovídají její celkové částce.');
            }
            if ($deduction === 'none' && abs($percent) > 0.001) $percent = 0.0;
            if ($deduction === 'full' && abs($percent - 100.0) > 0.001) $percent = 100.0;
            $sumBase += $base;
            $sumVat += $vat;
            $allocatedByRate[$rateKey] ??= ['base' => 0.0, 'vat' => 0.0];
            $allocatedByRate[$rateKey]['base'] += $base;
            $allocatedByRate[$rateKey]['vat'] += $vat;
        }
        if (abs($sumBase - (float) $totals['total_without_vat']) > 0.02
            || abs($sumVat - (float) $totals['total_vat']) > 0.02
        ) {
            throw new \InvalidArgumentException('Součet účetních alokací musí odpovídat rekapitulaci DPH dokladu.');
        }
        foreach ($allocatedByRate as $rate => $allocated) {
            $expected = $expectedByRate[$rate] ?? null;
            if ($expected === null
                || abs($allocated['base'] - $expected['base']) > 0.02
                || abs($allocated['vat'] - $expected['vat']) > 0.02
            ) {
                throw new \InvalidArgumentException('Účetní alokace musí odpovídat rekapitulaci každé sazby DPH.');
            }
        }
        if (count($allocatedByRate) !== count($expectedByRate)) {
            throw new \InvalidArgumentException('Účetní alokace musí pokrýt všechny sazby DPH dokladu.');
        }

        $accounts = $pdo->prepare(
            'SELECT account_code FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1'
        );
        $accounts->execute([$supplierId]);
        $validAccounts = array_fill_keys($accounts->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($allocations as $allocation) {
            if (!isset($validAccounts[trim((string) ($allocation['account_code'] ?? ''))])) {
                throw new \InvalidArgumentException('Účetní alokace odkazuje na neaktivní nebo neexistující účet.');
            }
        }

        $pdo->prepare(
            'DELETE FROM purchase_invoice_vat_allocations
              WHERE purchase_invoice_id = ? AND supplier_id = ?'
        )->execute([$purchaseInvoiceId, $supplierId]);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, usage_type, vat_rate,
                 base_amount, vat_amount, total_amount, vat_deduction,
                 vat_deduction_percent, tax_treatment, account_code,
                 vat_classification_code, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($allocations) as $index => $allocation) {
            $deduction = (string) ($allocation['vat_deduction'] ?? 'full');
            $percent = max(0.0, min(100.0, (float) ($allocation['vat_deduction_percent'] ?? 100)));
            if ($deduction === 'none') $percent = 0.0;
            if ($deduction === 'full') $percent = 100.0;
            $base = round((float) ($allocation['base_amount'] ?? 0), 2);
            $vat = round((float) ($allocation['vat_amount'] ?? 0), 2);
            $stmt->execute([
                $supplierId, $purchaseInvoiceId, trim((string) $allocation['description']),
                (string) ($allocation['usage_type'] ?? 'business'),
                round((float) ($allocation['vat_rate'] ?? 0), 2),
                $base, $vat, round((float) ($allocation['total_amount'] ?? ($base + $vat)), 2),
                $deduction, $percent,
                (string) ($allocation['tax_treatment'] ?? 'deductible'),
                trim((string) $allocation['account_code']),
                ($allocation['vat_classification_code'] ?? null) ?: null,
                (int) ($allocation['order_index'] ?? $index),
            ]);
        }
        $pdo->prepare('UPDATE purchase_invoices SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND supplier_id = ?')
            ->execute([$purchaseInvoiceId, $supplierId]);
    }

    /**
     * Seznam přijatých faktur tenantu, seskupený po měsících podle **issue_date**
     * (datum vystavení faktury dodavatelem).
     *
     * Pozn.: NEpoužíváme DUZP (tax_date) protože dodavatel může vystavit fakturu
     * v jiném měsíci než je DUZP — typicky DUZP konec měsíce, vystavení následující
     * měsíc. Z účetního hlediska user fakturu uplatní v měsíci, kdy ji obdrží/byla
     * vystavena dodavatelem, ne v měsíci DUZP. DPH přiznání má vlastní logic dle
     * tax_date — viz DphPriznaniBuilder.
     *
     * Output: ['data' => [{month, count, totals_per_currency, invoices: [...]}], 'meta' => ...]
     *
     * Filtry:
     *   supplier_id (povinné — tenant scope)
     *   q, status, document_kind, vendor_id, year, month, date_from, date_to, currency, unpaid_only, overdue,
     *   unpaid_as_of (YYYY-MM-DD — neuhrazeno K DATU X, ne dnešní status; stejný zdroj
     *   pravdy o úhradě jako SaldoRepository::fetchOpenPurchases, viz komentář u filtru)
     */
    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId === 0) {
            return ['data' => [], 'meta' => ['total' => 0]];
        }

        $where = ['pi.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "pi.status IN ($place)";
            foreach ($statuses as $s) $params[] = (string) $s;
        }
        if (!empty($filters['document_kind'])) {
            $kinds = is_array($filters['document_kind']) ? $filters['document_kind'] : [$filters['document_kind']];
            $place = implode(',', array_fill(0, count($kinds), '?'));
            $where[] = "pi.document_kind IN ($place)";
            foreach ($kinds as $k) $params[] = (string) $k;
        }
        if (!empty($filters['vendor_id'])) {
            $where[] = 'pi.vendor_id = ?';
            $params[] = (int) $filters['vendor_id'];
        }
        // Zakázka (issue #29). `none` = doklady bez zakázky — jinak by nešlo dohledat,
        // co do ekonomiky akcí ještě nikdo nezařadil.
        if (!empty($filters['project_id'])) {
            if ((string) $filters['project_id'] === 'none') {
                $where[] = 'pi.project_id IS NULL';
            } else {
                $where[] = 'pi.project_id = ?';
                $params[] = (int) $filters['project_id'];
            }
        }
        if (!empty($filters['year'])) {
            // Sargovatelný půlotevřený rozsah místo YEAR(...) — využije idx_pi_supplier_issue.
            $y = (int) $filters['year'];
            $where[] = 'pi.issue_date >= ? AND pi.issue_date < ?';
            $params[] = sprintf('%04d-01-01', $y);
            $params[] = sprintf('%04d-01-01', $y + 1);
        }
        if (!empty($filters['month'])) {
            // MONTH() napříč roky nelze převést na souvislý rozsah — ponecháno.
            $where[] = 'MONTH(pi.issue_date) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'pi.issue_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'pi.issue_date <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "pi.status IN ('received','booked')";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "pi.status IN ('received','booked') AND pi.due_date <= CURDATE()";
        }
        // Neuhrazené K DATU X (task #4) — historický protějšek `unpaid_only`/`overdue`
        // výše. Přijaté faktury NEMAJÍ obdobu `invoice_payments` — zdroj pravdy o úhradě
        // je proto STEJNÝ jako v SaldoRepository::fetchOpenPurchases (audit 2026-07, H2/H3):
        // plná úhrada K asOf je autoritativní jen přes `status='paid' AND paid_at <= asOf`
        // (kryje bankovní, hotovostní i ruční úhradu — paid_at nastaví i CashDocumentService::
        // applySideEffects). Jinak best-effort ze Σ payment_matches s bank_transactions.
        // posted_at <= asOf (jen bankovní párování — KNOWN GAP H3 u cizoměnové ČÁSTEČNÉ
        // úhrady, shodně se saldokontem, ať si sestavy neodporují).
        if (!empty($filters['unpaid_as_of'])) {
            $asOf = (string) $filters['unpaid_as_of'];
            // Vystavená do X — koncept ještě není závazek.
            $where[] = 'pi.issue_date <= ?';
            $params[] = $asOf;
            $where[] = "pi.status <> 'draft'";
            // Storno platí, jen když k X už proběhlo (H4b, shodně se SaldoRepository).
            $where[] = "(pi.status <> 'cancelled' OR pi.cancelled_at IS NULL OR DATE(pi.cancelled_at) > ?)";
            $params[] = $asOf;
            // DDKP (§ 28 ZDPH) nikdy nezakládá závazek na 321 — jeho amount_to_pay je
            // GENERATED sloupec s plným brutto už zaplacené zálohy (viz PayablePredicate
            // + PayablePredicateCoverageTest B-13); bez vyloučení by tu vyšel jako fantomový
            // dluh v plné výši zálohy.
            $where[] = PayablePredicate::advanceVatDocumentCondition('pi');
            // NOT (plně uhrazeno k asOf podle autoritativního stavu) AND (zbývá k úhradě
            // podle bankovního párování k asOf) > tolerance.
            $where[] = "NOT (pi.status = 'paid' AND pi.paid_at IS NOT NULL AND DATE(pi.paid_at) <= ?)";
            $params[] = $asOf;
            $where[] = "(pi.amount_to_pay - COALESCE((SELECT SUM(pm.amount) FROM payment_matches pm"
                . " JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id"
                . " WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id"
                . " AND bt.posted_at <= ?), 0)) > 0.005";
            $params[] = $asOf;
        }
        // „Bez párování úhrady" — doklad NEMÁ žádnou zaúčtovanou úhradu: ani bankovní
        // match (payment_matches), ani hotovostní pokladní doklad (cash_documents POSTED).
        // Odhalí ručně/legacy uhrazené faktury (status='paid' bez zaúčtované úhrady) —
        // závazek 321 pak zůstává v deníku otevřený. Vylučuje cancelled (irelevantní) a
        // doklady kryté zálohou / na 0 Kč (amount_to_pay <= 0) — ty jsou vypořádané
        // zálohovým dokladem (advance_paid_amount), nemají a nemají mít bankovní/pokladní
        // párování, takže nejde o chybu (např. finální faktura na 0 Kč k uhrazené záloze).
        if (!empty($filters['unmatched'])) {
            // Pokladní VPD / ruční úhrada často není navázaná strukturálně
            // (cash_documents.purchase_invoice_id = NULL, purpose='other') — vazba je jen
            // v popisu zápisu („Úhrada PF <číslo>"). Takový doklad ale závazek v deníku
            // uzavírá (zápis MD 321). Vyloučíme proto i faktury, které mají POSTED zápis
            // s řádkem MD 321* a popisem odkazujícím na číslo dodavatelské faktury — tím
            // odpadnou faktury reálně uhrazené pokladnou/ručně, i bez strukturálního párování.
            $where[] = "pi.status IN ('paid','received','booked')
                AND pi.amount_to_pay > 0
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm
                                 WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id)
                AND NOT EXISTS (SELECT 1 FROM cash_documents cd
                                 WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id
                                   AND cd.status = 'posted')
                AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                  JOIN journal_entry_lines jl ON jl.entry_id = je.id
                                  JOIN chart_of_accounts ja ON ja.id = jl.account_id
                                 WHERE je.supplier_id = pi.supplier_id AND je.posted_at IS NOT NULL
                                   AND je.reversed_by IS NULL AND jl.side = 'debit'
                                   AND ja.account_code LIKE '321%'
                                   AND pi.vendor_invoice_number <> ''
                                   AND je.description LIKE CONCAT('%', pi.vendor_invoice_number, '%'))
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices cn
                                 WHERE cn.supplier_id = pi.supplier_id
                                   AND cn.document_kind = 'credit_note'
                                   AND cn.parent_purchase_invoice_id = pi.id)";
        }
        if (!empty($filters['needs_review'])) {
            $where[] = "pi.extraction_warning IS NOT NULL";
        }
        if (!empty($filters['import_batch_id'])) {
            $where[] = 'pi.import_batch_id = ?';
            $params[] = (string) $filters['import_batch_id'];
        }
        // „Předané k úhradě" — odvozená dimenze (příznak payment_ordered_at), NE status.
        // '1' = předané, '0' = nepředané. Status zůstává received/booked/paid (ortogonální).
        if (isset($filters['payment_ordered']) && $filters['payment_ordered'] !== null && $filters['payment_ordered'] !== '') {
            $where[] = ((string) $filters['payment_ordered'] === '1')
                ? 'pi.payment_ordered_at IS NOT NULL'
                : 'pi.payment_ordered_at IS NULL';
        }
        // Zaúčtováno / nezaúčtováno (podvojné účetnictví) — '1' = zaúčtováno (booked_at IS NOT NULL),
        // '0' = nezaúčtováno (IS NULL). V daňové evidenci je booked_at vždy NULL (FE filtr gatuje na
        // double_entry). Pozor: ortogonální ke starému stavu `status='booked'` — zaúčtovanost řeší booked_at.
        // Zálohové faktury se neúčtují (účtuje se až inkaso zálohy a vyúčtování) — engine je vylučuje
        // shodně v PostingService::post(), DocumentBackfill, PendingBackfillCounter i ClosingRepository,
        // takže fronta „k zaúčtování" je nesmí nabízet, jinak slibuje akci, která skončí 422.
        if (isset($filters['booked']) && $filters['booked'] !== null && $filters['booked'] !== '') {
            if ((string) $filters['booked'] === '1') {
                $where[] = 'pi.booked_at IS NOT NULL';
            } else {
                $where[] = "pi.booked_at IS NULL AND COALESCE(pi.document_kind, 'invoice') <> 'advance'";
            }
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query / unexpected match
            $q = addcslashes((string) $filters['q'], '%_\\');
            // Hledá i v TEXTU POLOŽEK dokladu (EXISTS, ne JOIN — JOIN by doklad znásobil na
            // počet položek). Uživatel typicky hledá „monitor", „Lenovo" apod. napříč doklady.
            $where[] = '(pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ? OR c.company_name LIKE ?'
                . ' OR EXISTS (SELECT 1 FROM purchase_invoice_items pii WHERE pii.purchase_invoice_id = pi.id'
                . ' AND pii.description LIKE ?))';
            $params[] = $q . '%';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        // MariaDB 10.2+ window function — COUNT(*) OVER() vrací total v každém řádku.
        // Místo 2 query (COUNT + SELECT s LIMIT) jeden round-trip, žádný race condition
        // mezi count a paginated select, žádný duplicate WHERE / JOIN parsing.
        $selectTotal = $perPage > 0 ? ', COUNT(*) OVER() AS total_rows' : '';

        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                       pi.vendor_id, pi.supplier_id,
                       pi.issue_date, pi.tax_date, pi.due_date, pi.received_at,
                       pi.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       pi.exchange_rate, pi.exchange_rate_date,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat,
                       pi.advance_paid_amount, pi.amount_to_pay,
                       pi.payment_ordered_at,
                       pi.status, pi.booked_at, pi.paid_at, pi.cancelled_at,
                       pi.extraction_warning, pi.vat_deduction, pi.vat_deduction_percent, pi.tax_deductible,
                       ec.label AS expense_category_label, ec.code AS expense_category_code,
                       pi.project_id, prj.name AS project_name,
                       c.company_name AS vendor_company_name, c.ic AS vendor_ic,
                       DATE_FORMAT(pi.issue_date, '%Y-%m') AS month_bucket,
                       EXISTS (SELECT 1 FROM purchase_invoices adv_f
                               WHERE adv_f.advance_purchase_invoice_id = pi.id) AS is_settled_advance,
                       -- §DM: doklad obsahuje drobný majetek → ikonka v seznamu. Stačí, že
                       -- ho nese JEDNA položka (faktura běžně míchá majetek se službou),
                       -- proto EXISTS, ne JOIN — ten by doklad znásobil na počet položek.
                       EXISTS (SELECT 1 FROM purchase_invoice_items pii
                               WHERE pii.purchase_invoice_id = pi.id
                                 AND pii.expense_kind = 'small_asset') AS has_small_asset
                       {$selectTotal}
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
             LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
             LEFT JOIN projects prj ON prj.id = pi.project_id
                 WHERE $whereSql
                 ORDER BY pi.issue_date DESC, pi.id DESC";

        $offset = 0;
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= ' LIMIT ? OFFSET ?';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // total_rows extrahujeme z prvního řádku (window function vrací stejnou hodnotu
        // v každém řádku). Pokud výsledek je prázdný a používáme pagination, total=0.
        $total = null;
        if ($perPage > 0) {
            $total = !empty($rows) ? (int) $rows[0]['total_rows'] : 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            unset($row['total_rows']); // metadata, nepatří do invoice payloadu
            // Spárovaná záloha = advance, na kterou ukazuje finální (vyúčtovací) faktura.
            // Zachytit z DB flagu PŘED castem a vyřadit z payloadu (interní metadata).
            $isSettledAdvance = (string) ($row['document_kind'] ?? '') === 'advance'
                && (int) ($row['is_settled_advance'] ?? 0) === 1;
            unset($row['is_settled_advance']);
            $row = $this->castInvoice($row);
            $month = (string) $row['month_bucket'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $grouped[$month]['invoices'][] = $row;
            $grouped[$month]['count']++;

            // Měsíční součet = reálný náklad. Vyřadit: draft/cancelled a spárovanou/zaplacenou
            // zálohu (advance) — náklad nese finální faktura, jinak 2× započteno.
            // Nespárovaná nezaplacená záloha se počítá (očekávaný náklad).
            // Řádek se i tak zobrazí (analogicky proforma u vystavených faktur).
            //
            // ZÁMĚRNĚ OPAČNĚ NEŽ CRM — nesjednocovat: tohle je ÚČETNÍ pohled (zaplacená
            // záloha sedí na 314, náklad nezakládá), zatímco CrmAggregationService
            // a PurchaseSummaryAction jsou informativní predikce, kde zaplacená
            // a nespárovaná záloha do nákladů patří. Rozhodnutí, ne chyba —
            // viz private/checks/NALEZY.md, N-019. Hlídá AdvanceCostPredicateParityTest.
            //
            // DDKP (§ 28 ZDPH) není náklad NIKDY — účtuje jen odpočet DPH ze zálohy (343/314),
            // náklad vzniká až u vyúčtovací faktury. Bez toho hlavička měsíce nesedí
            // s dlaždicí Náklady ani s costs_by_month o celé brutto DDKP.
            $excludedAdvance = $row['document_kind'] === 'advance'
                && ($row['status'] === 'paid' || $isSettledAdvance);
            $excludedVatDoc = $row['document_kind'] === 'tax_document';
            if (!in_array($row['status'], ['draft', 'cancelled'], true) && !$excludedAdvance && !$excludedVatDoc) {
                $cur = $row['currency'];
                if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                    $grouped[$month]['totals_per_currency'][$cur] = [
                        'currency'    => $cur,
                        'without_vat' => 0.0,
                        'vat'         => 0.0,
                        'with_vat'    => 0.0,
                    ];
                }
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += (float) $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += (float) $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += (float) $row['total_with_vat'];
            }
        }
        foreach ($grouped as &$g) {
            $g['totals_per_currency'] = array_values($g['totals_per_currency']);
        }
        unset($g);

        $meta = ['total' => $total ?? array_sum(array_column($grouped, 'count'))];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return ['data' => array_values($grouped), 'meta' => $meta];
    }

    /**
     * Vytvoří draft přijaté faktury. Vrací nové id.
     *
     * Pravidla:
     *   - vendor_id MUSÍ patřit do supplier_id (volající kontroluje přes SupplierGuard nad clients)
     *   - varsymbol je volitelný — pokud chybí, vygeneruje se až při přechodu na received
     *   - vendor_snapshot je povinné (uložíme aktuální vendor data jako immutable)
     */
    public function createDraft(array $data, int $userId, int $supplierId): int
    {
        $pdo = $this->db->pdo();

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        if ($vendorId === 0) {
            throw new \InvalidArgumentException('vendor_id chybí');
        }

        // Sanity check: vendor existuje a patří tenantovi
        $stmt = $pdo->prepare('SELECT supplier_id, default_expense_category_id, is_vat_payer FROM clients WHERE id = ?');
        $stmt->execute([$vendorId]);
        $vendorRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $vendorSupplier = (int) ($vendorRow['supplier_id'] ?? 0);
        if ($vendorSupplier !== $supplierId) {
            throw new \InvalidArgumentException("Vendor #$vendorId nepatří tomuto tenantovi.");
        }

        // Snapshot plátcovství dodavatele k datu plnění (migrace 0133). Volající může poslat
        // explicitní `vendor_is_vat_payer` (editor / import, který stav zná); jinak zmrazíme
        // AKTUÁLNÍ živý příznak klienta. Doklad si pak drží vlastní stav nezávisle na tom,
        // jak se plátcovství dodavatele později změní v registru.
        $vendorIsVatPayer = array_key_exists('vendor_is_vat_payer', $data)
            ? ($data['vendor_is_vat_payer'] === null ? null : ((bool) $data['vendor_is_vat_payer'] ? 1 : 0))
            : (array_key_exists('is_vat_payer', $vendorRow) && $vendorRow['is_vat_payer'] !== null
                ? ((bool) $vendorRow['is_vat_payer'] ? 1 : 0)
                : null);

        // Výchozí kategorie nákladu dodavatele — aplikuje se, pokud volající kategorii
        // explicitně neurčil. Platí pro manuální zadání i pro všechny importy
        // (AI, ISDOC/ZIP, iDoklad, Fakturoid, bankovní párování), které jdou tudy.
        // Sjednocuje chování se server-side backfillem v ClientRepository::update().
        $expenseCategoryId = (isset($data['expense_category_id']) && $data['expense_category_id'])
            ? (int) $data['expense_category_id']
            : (($vendorRow['default_expense_category_id'] ?? null) !== null
                ? (int) $vendorRow['default_expense_category_id']
                : null);

        // Vendor invoice number — povinné, validace max 50 znaků
        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        $documentKind = (string) ($data['document_kind'] ?? 'invoice');
        if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'], true)) {
            $documentKind = 'invoice';
        }

        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol má max 20 znaků');
        }

        // Snapshot vendoru — buď z payloadu, nebo načteme z DB
        $vendorSnapshot = $data['vendor_snapshot'] ?? null;
        if (!is_array($vendorSnapshot)) {
            $vendorSnapshot = $this->buildVendorSnapshot($vendorId);
        }

        // C6 (§ 73/1/a): 'manual' = účetní vědomě zadala datum přijetí ve formuláři,
        // 'import' = otisk data importu. Rozhoduje o období odpočtu ve VatLedgerService.
        $receivedAtSource = (($data['received_at_source'] ?? null) === 'manual') ? 'manual' : 'import';

        // Forma úhrady (migrace 1128). Pořadí: co poslal volající (editor / AI import)
        // → předvolba dodavatele (`clients.default_payment_method`) → 'bank_transfer'.
        // Předvolba dodavatele se otiskne jako source='vendor', aby ji pozdější ruční
        // volba účetní směla přepsat, ale sama nepřepsala nic silnějšího.
        [$paymentMethod, $paymentMethodSource] = $this->resolvePaymentMethodForCreate($data, $vendorId);

        // Kurz (migrace 1303). Korunový doklad kurz mít nesmí — PostingService i
        // VatLedgerService u CZK počítají s 1.0 natvrdo, takže uložená hodnota nic
        // nemění, jen čeká na agregaci bez pojistky na CZK. Symetrie s
        // ExchangeRateApplier::applyToInvoice() na vydané straně.
        $isCzk = $this->isCzkCurrency($data['currency_id'] ?? null);
        $exchangeRate = (!$isCzk && isset($data['exchange_rate'])) ? (float) $data['exchange_rate'] : null;
        $exchangeRateDate = (!$isCzk && !empty($data['exchange_rate_date'])) ? (string) $data['exchange_rate_date'] : null;
        $exchangeRateSource = ExchangeRateSources::normalize($data['exchange_rate_source'] ?? null);

        $sql = 'INSERT INTO purchase_invoices
            (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number, document_kind,
             issue_date, tax_date, due_date, received_at, received_at_source,
             currency_id, exchange_rate, exchange_rate_date, exchange_rate_source,
             reverse_charge, prices_include_vat, language, note_above_items, note_below_items,
             vendor_snapshot, own_snapshot,
             advance_paid_amount,
             payment_currency_id, payment_exchange_rate,
             paid_amount_payment_ccy, paid_amount_invoice_ccy, exchange_diff_base,
             payment_account_number, payment_bank_code, payment_iban, payment_bic,
             payment_variable_symbol, payment_account_source, payment_account_checked_at,
             payment_method, payment_method_source,
             status, vat_classification_code, vat_deduction, vat_deduction_percent, tax_deductible, is_fixed_asset, expense_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplierId,
            $vendorId,
            $vendorIsVatPayer,
            $manualVarsymbol,
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            $receivedAtSource,
            (int) $data['currency_id'],
            $exchangeRate,
            $exchangeRateDate,
            $exchangeRateSource,
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            json_encode($vendorSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            isset($data['own_snapshot']) && is_array($data['own_snapshot'])
                ? json_encode($data['own_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            ...$this->paymentColumns($data),
            $paymentMethod,
            $paymentMethodSource,
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional', 'reduced'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
            $expenseCategoryId,
            $userId,
        ]);

        $newId = (int) $pdo->lastInsertId();

        // Vazba dobropisu na opravovanou fakturu (migrace 1096) — INSERT výš je poziční a
        // křehký, proto ji dosadíme samostatným UPDATE jen když ji volající poslal. Tenant/
        // /self/kind validaci řeší Action; tady jen persistujeme int|null.
        if (array_key_exists('parent_purchase_invoice_id', $data)) {
            $parentId = ($data['parent_purchase_invoice_id'] ?? null) ? (int) $data['parent_purchase_invoice_id'] : null;
            if ($parentId !== null) {
                $u = $pdo->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ?
                                     WHERE id = ? AND supplier_id = ?');
                $u->execute([$parentId, $newId, $supplierId]);
            }
        }

        // Zakázka (issue #29) — týmž aditivním způsobem jako vazba dobropisu výš, aby
        // poziční INSERT zůstal beze změny. Tenant vazbu ověřuje Action
        // (TenantReferenceGuard), tady jen persistujeme int|null.
        $projectId = (isset($data['project_id']) && (int) $data['project_id'] > 0)
            ? (int) $data['project_id']
            : null;
        if ($projectId !== null) {
            $pdo->prepare('UPDATE purchase_invoices SET project_id = ?
                            WHERE id = ? AND supplier_id = ?')
                ->execute([$projectId, $newId, $supplierId]);
        }

        return $newId;
    }

    /**
     * Forma úhrady pro NOVÝ doklad (migrace 1128).
     *
     * Volající poslal hodnotu → bereme ji i s jeho zdrojem ('manual' z editoru,
     * 'ai' z extrakce). Nic neposlal → zkusíme předvolbu dodavatele
     * (`clients.default_payment_method`, NULL = dodavatel „nemá názor") a otiskneme
     * source='vendor'. Jinak 'bank_transfer'/'default'.
     *
     * @param array<string,mixed> $data
     * @return array{0:string,1:string}
     */
    private function resolvePaymentMethodForCreate(array $data, int $vendorId): array
    {
        if (array_key_exists('payment_method', $data) && PaymentMethods::isValid($data['payment_method'])) {
            return [
                PaymentMethods::normalize($data['payment_method']),
                PaymentMethods::normalizeSource($data['payment_method_source'] ?? 'manual'),
            ];
        }

        $vendorDefault = $this->vendorDefaultPaymentMethod($vendorId);
        if ($vendorDefault !== null) {
            return [$vendorDefault, 'vendor'];
        }

        return [PaymentMethods::DEFAULT, 'default'];
    }

    /** Uložený zdroj formy úhrady dokladu (default, když řádek/sloupec nejde přečíst). */
    private function currentPaymentMethodSource(int $id, int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT payment_method_source FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$id, $supplierId]);
            $v = $stmt->fetchColumn();
        } catch (\Throwable) {
            return 'default';
        }
        return $v === false ? 'default' : PaymentMethods::normalizeSource($v);
    }

    /**
     * Nastaví formu úhrady s respektem k prioritě zdrojů (manual > ai > vendor > default).
     * Používá ji AI extrakce — nikdy nesmí přebít ruční volbu účetní.
     *
     * @return bool true = zapsáno, false = silnější zdroj hodnotu ubránil
     */
    public function setPaymentMethod(int $id, int $supplierId, string $method, string $source): bool
    {
        if (!PaymentMethods::isValid($method)) {
            return false;
        }
        $source = PaymentMethods::normalizeSource($source);
        if (!PaymentMethods::canOverride($this->currentPaymentMethodSource($id, $supplierId), $source)) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET payment_method = ?, payment_method_source = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([PaymentMethods::normalize($method), $source, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Volba „uhradit hotově z této pokladny" (migrace 1327). Samostatný setter, ne
     * další sloupec v pozičním INSERT/UPDATE výš — zápis je nezávislý na zbytku
     * dokladu a vlastnictví pokladny váže volající (TenantReferenceGuard).
     * NULL = volba zrušena; {@see \MyInvoice\Service\Accounting\Cash\CashSettlementService}
     * na to reaguje smazáním dřív založeného pokladního dokladu.
     */
    public function setCashRegisterId(int $id, int $supplierId, ?int $registerId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET cash_register_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$registerId !== null && $registerId > 0 ? $registerId : null, $id, $supplierId]);
    }

    /** Předvolená forma úhrady dodavatele; null = neurčeno (nebo sloupec ještě není). */
    private function vendorDefaultPaymentMethod(int $vendorId): ?string
    {
        if ($vendorId <= 0) {
            return null;
        }
        try {
            $stmt = $this->db->pdo()->prepare('SELECT default_payment_method FROM clients WHERE id = ?');
            $stmt->execute([$vendorId]);
            $v = $stmt->fetchColumn();
        } catch (\Throwable) {
            // Sloupec chybí (starší DB bez migrace 1128) → chovej se jako „bez předvolby".
            return null;
        }
        return $v === false ? null : PaymentMethods::normalizeNullable($v);
    }

    /**
     * Z payloadu (`$data['payment']`) vytáhne 7 sloupců platebního účtu v pořadí
     * shodném s INSERT/UPDATE: account_number, bank_code, iban, bic, variable_symbol,
     * source, checked_at.
     *
     * `source` + `checked_at` se nastaví jen když je účet skutečně použitelný
     * (CZ účet+kód nebo IBAN), případně když volající vynutí `payment['checked']=true`
     * (lazy AI re-extrakce proběhla bez výsledku → gate proti opakování). Jinak
     * zůstávají NULL, aby lazy doplnění mohlo později proběhnout.
     *
     * @param array<string,mixed> $data
     * @return array{0:?string,1:?string,2:?string,3:?string,4:?string,5:?string,6:?string}
     */
    private function paymentColumns(array $data): array
    {
        $p = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $account = self::nullableString($p['account_number'] ?? null);
        $bank    = self::nullableString($p['bank_code'] ?? null);
        $iban    = self::nullableString($p['iban'] ?? null);
        $bic     = self::nullableString($p['bic'] ?? null);
        $vs      = self::nullableString($p['variable_symbol'] ?? null);

        $hasAccount = ($account !== null && $bank !== null) || $iban !== null;
        $allowed = ['isdoc', 'ai', 'ai_reextract', 'qr_image', 'manual'];
        $source = ($hasAccount && in_array($p['source'] ?? '', $allowed, true))
            ? (string) $p['source']
            : null;
        $checkedAt = ($hasAccount || !empty($p['checked'])) ? date('Y-m-d H:i:s') : null;

        return [$account, $bank, $iban, $bic, $vs, $source, $checkedAt];
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /**
     * Aktualizuje platební účet dodavatele (pro „Zaplatit pomocí QR"). Funguje
     * v jakémkoli stavu (účet chceme editovat i u received/booked). Použito
     * dedikovaným endpointem (ruční editace, source='manual') i lazy doplněním
     * z ISDOC/AI při otevření QR modalu.
     *
     * @param array<string,mixed> $payment account_number/bank_code/iban/bic/variable_symbol/source/checked
     */
    public function updatePaymentAccount(int $id, array $payment, int $supplierId): void
    {
        [$account, $bank, $iban, $bic, $vs, $source, $checkedAt] = $this->paymentColumns(['payment' => $payment]);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET
                 payment_account_number = ?, payment_bank_code = ?, payment_iban = ?, payment_bic = ?,
                 payment_variable_symbol = ?, payment_account_source = ?, payment_account_checked_at = ?
               WHERE id = ? AND supplier_id = ?'
        )->execute([$account, $bank, $iban, $bic, $vs, $source, $checkedAt, $id, $supplierId]);
    }

    /**
     * Nezaplacené přijaté faktury vhodné do platebního příkazu (status received/booked
     * a zbývá k úhradě). Vrací platební údaje příjemce + DIČ dodavatele (pro CRPDPH
     * ověření). Volitelný filtr měny (= měna účtu plátce).
     *
     * @param int|null $limit Bez LIMIT/OFFSET, když null (zpětně kompatibilní default).
     * @param bool $includeNonTransfer Zahrnout i faktury hrazené jinak než převodem
     *                                 (inkaso apod.) — viz {@see paymentCandidatesWhere}.
     * @return list<array<string,mixed>>
     */
    public function listPaymentCandidates(int $supplierId, ?string $currency = null, ?int $limit = null, int $offset = 0, bool $includeNonTransfer = false): array
    {
        [$where, $params] = $this->paymentCandidatesWhere($supplierId, $currency, $includeNonTransfer);
        $sql = "SELECT pi.id, pi.vendor_invoice_number, pi.varsymbol, pi.document_kind,
                       pi.vendor_id, pi.issue_date, pi.due_date,
                       pi.total_with_vat, pi.amount_to_pay, pi.rounding,
                       (pi.pdf_path IS NOT NULL AND pi.pdf_path <> '') AS has_pdf,
                       pi.payment_account_number, pi.payment_bank_code, pi.payment_iban, pi.payment_bic,
                       pi.payment_variable_symbol, pi.payment_constant_symbol,
                       pi.payment_method, pi.payment_method_source,
                       pi.payment_account_source, pi.payment_account_checked_at, pi.payment_ordered_at,
                       cur.code AS currency, cur.symbol AS currency_symbol,
                       c.company_name AS vendor_company_name, c.dic AS vendor_dic, c.ic AS vendor_ic,
                       -- Zápis, jehož zdrojem je tenhle doklad — otevírá náhled dokladu
                       -- bez odskoku ze stránky. Poddotaz, ne JOIN: k dokladu může být
                       -- víc zápisů (oprava, doúčtování) a JOIN by řádek zduplikoval.
                       (SELECT je.id FROM journal_entries je
                         WHERE je.supplier_id = pi.supplier_id
                           AND je.source_type = 'purchase_invoice'
                           AND je.source_id = pi.id
                           AND je.posted_at IS NOT NULL
                         ORDER BY je.id ASC LIMIT 1) AS journal_entry_id
                  FROM purchase_invoices pi
                  JOIN clients c     ON c.id   = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY pi.due_date ASC, pi.id ASC";
        // LIMIT/OFFSET inlinujeme jako validované inty (vzor StockItemRepository::list) —
        // native prepared statements neumí LIMIT/OFFSET s parametrem typu string.
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['vendor_id']      = (int) $r['vendor_id'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            $r['amount_to_pay']  = (float) $r['amount_to_pay'];
            $r['rounding']       = (float) ($r['rounding'] ?? 0);
            $r['has_pdf']        = (bool) $r['has_pdf'];
            $r['journal_entry_id'] = $r['journal_entry_id'] !== null ? (int) $r['journal_entry_id'] : null;
        }
        return $rows;
    }

    /** COUNT(*) se STEJNÝM WHERE jako {@see listPaymentCandidates} (bez LIMIT), pro paginaci. */
    public function countPaymentCandidates(int $supplierId, ?string $currency = null, bool $includeNonTransfer = false): int
    {
        [$where, $params] = $this->paymentCandidatesWhere($supplierId, $currency, $includeNonTransfer);
        $sql = "SELECT COUNT(*)
                  FROM purchase_invoices pi
                  JOIN clients c     ON c.id   = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE " . implode(' AND ', $where);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Sdílený WHERE pro {@see listPaymentCandidates} i {@see countPaymentCandidates} —
     * drž obojí v syncu, jinak se rozejde stránkování s totálem.
     *
     * `payment_method = 'bank_transfer'` je OPT-OUT filtr, ne natvrdo: inkaso (SIPO),
     * karta, dobírka ani zápočet se převodem neplatí a v příkazu k úhradě by vedly na
     * DVOJÍ platbu. Ale protože hodnota může být nastavená CHYBNĚ (AI, předvolba
     * dodavatele), musí jít filtr vypnout — jinak by taková faktura z obrazovky zmizela
     * BEZE STOPY a nikdo by ji nikdy nezaplatil. Proto `$includeNonTransfer`.
     *
     * Daňový doklad k poskytnuté záloze (DDKP, § 28 ZDPH) NENÍ platební cíl a filtr je
     * u něj NEPODMÍNĚNÝ — peníze odešly už na zálohovou fakturu, DDKP jen dokládá nárok
     * na odpočet DPH (343/314). Jeho `amount_to_pay` je přitom generated sloupec
     * `total_with_vat − advance_paid_amount`, takže nese PLNÉ BRUTTO už zaplacené zálohy;
     * bez tohoto filtru by DDKP ve stavu `received`/`booked` spadl do příkazu k úhradě
     * a dodavateli by odešla druhá platba. Stejné pravidlo drží StatementMatcher:630/768/823
     * (párování) a BankPostingService:702 (protiúčet); vydanou větev kryje strukturálně
     * InvoicePaymentService::PAYABLE_TYPES.
     *
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function paymentCandidatesWhere(int $supplierId, ?string $currency, bool $includeNonTransfer = false): array
    {
        $where = [
            "pi.supplier_id = ?",
            "pi.status IN ('received','booked')",
            "pi.amount_to_pay > 0",
            "pi.document_kind <> 'tax_document'",
        ];
        $params = [$supplierId];
        if (!$includeNonTransfer) {
            $where[] = "pi.payment_method = 'bank_transfer'";
        }
        if ($currency !== null && $currency !== '') {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper($currency);
        }
        return [$where, $params];
    }

    /**
     * Označí faktury jako zařazené do (vyexportovaného) platebního příkazu.
     * Status NEpřeklápí — to je samostatné rozhodnutí (mark_paid přes setStatus).
     *
     * @param list<int> $ids
     */
    public function markPaymentOrdered(array $ids, int $supplierId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET payment_ordered_at = NOW()
              WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
    }

    /**
     * Update draft přijaté faktury. Volající má ověřit, že je `status='draft'`.
     */
    /**
     * @param bool $requireUnbooked Optimistický zámek (Epic F6, L1): UPDATE podmíněný
     *                              `booked_at IS NULL`. Vrací false, když doklad mezitím
     *                              někdo zaúčtoval (řádek existuje, ale booked_at je set).
     */
    public function updateDraft(int $id, array $data, int $supplierId, bool $requireUnbooked = false): bool
    {
        $hasVarsymbol = array_key_exists('varsymbol', $data);
        $manualVarsymbol = null;
        if ($hasVarsymbol) {
            $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
            if ($manualVarsymbol === '') {
                $manualVarsymbol = null;
            } elseif (strlen($manualVarsymbol) > 20) {
                throw new \InvalidArgumentException('varsymbol má max 20 znaků');
            }
        }

        $kindStmt = $this->db->pdo()->prepare(
            'SELECT document_kind FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $kindStmt->execute([$id, $supplierId]);
        $currentKind = (string) ($kindStmt->fetchColumn() ?: '');

        // Druh dokladu píšeme JEN když ho volající poslal — stejně jako kurz, formu úhrady
        // nebo vazbu dobropisu níž. Bez téhle podmínky by částečný PUT (samotné DUZP,
        // poznámka) překlopil zálohu/účtenku/dobropis na 'invoice' jen proto, že klíč
        // v těle nebyl.
        if (array_key_exists('document_kind', $data)) {
            $documentKind = (string) ($data['document_kind'] ?? 'invoice');
            if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'], true)) {
                $documentKind = 'invoice';
            }
        } else {
            $documentKind = $currentKind !== '' ? $currentKind : 'invoice';
        }

        // Odchod z DDKP (daňový doklad k poskytnuté záloze, § 28 ZDPH) je destruktivní jen
        // tehdy, když na dokladu VISÍ VAZBA: patří k zálohové faktuře, nebo přes něj někdo
        // vyúčtoval konečnou fakturu. Pak by překlopením vznikl fantomový závazek v plné
        // výši už zaplacené zálohy, duplicitní náklad i duplicitní odpočet.
        // Samostatný DDKP bez vazeb žádnou takovou stopu nemá — a přesně tak vypadá doklad,
        // který AI klasifikovala špatně (obyčejná faktura s nadpisem „Daňový doklad"). Ten
        // musí jít opravit; dřív se změna druhu tiše zahodila a v editoru se pořád vracel
        // starý typ. Blokujeme proto jen vázaný DDKP, a hlasitě
        // ({@see taxDocumentKindChangeBlocker()} — týž SSOT používá i updateDocumentKind()).
        if ($currentKind === 'tax_document' && $documentKind !== 'tax_document') {
            $blocker = $this->taxDocumentKindChangeBlocker($id, $supplierId);
            if ($blocker !== null) {
                throw new \InvalidArgumentException($blocker);
            }
        }

        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        // Snapshot plátcovství dodavatele (migrace 0133) přepisujeme jen když ho volající
        // explicitně poslal (editor faktury / import, který stav zná). Ostatní update cesty
        // ho neposílají → zmrazený stav dokladu zůstává nedotčený (klíč pro historické doklady).
        $hasVendorVatPayer = array_key_exists('vendor_is_vat_payer', $data);
        $vendorIsVatPayer = null;
        if ($hasVendorVatPayer) {
            $vendorIsVatPayer = $data['vendor_is_vat_payer'] === null
                ? null
                : ((bool) $data['vendor_is_vat_payer'] ? 1 : 0);
        }

        // Platební účet pro QR platbu měníme jen když ho volající explicitně poslal
        // (editor faktury). Ostatní update cesty `payment` neposílají → účet zůstává.
        $hasPayment = array_key_exists('payment', $data);
        $paymentSet = '';
        $paymentParams = [];
        if ($hasPayment) {
            $paymentParams = $this->paymentColumns($data);
            $paymentSet = ', payment_account_number = ?, payment_bank_code = ?, payment_iban = ?, payment_bic = ?,'
                . ' payment_variable_symbol = ?, payment_account_source = ?, payment_account_checked_at = ?';
        }

        // C6 (§ 73/1/a): zdroj data přijetí přepisujeme jen když ho volající poslal —
        // jinak by manuální update bez received_at otisk 'manual' degradoval zpět na 'import'.
        $hasReceivedAtSource = array_key_exists('received_at_source', $data);
        $receivedAtSourceParam = null;
        $receivedAtSourceSet = '';
        if ($hasReceivedAtSource) {
            $receivedAtSourceParam = ($data['received_at_source'] === 'manual') ? 'manual' : 'import';
            $receivedAtSourceSet = ', received_at_source = ?';
        }

        // Vazba dobropisu na opravovanou fakturu (migrace 1096). Přepisujeme jen když ji
        // volající explicitně poslal (editor dokladu) — ostatní update cesty ji neposílají
        // → uložená vazba zůstává nedotčená. Tenant/self/kind validaci dělá Action.
        // Zakázka (issue #29): píšeme JEN když ji volající skutečně poslal. Bezpodmínečný
        // zápis by ji každému volajícímu, který pole vynechá (bankovní párování, AI import,
        // status transition), tiše vynuloval — a s ní by z výsledovky zmizel náklad akce.
        $hasProject = array_key_exists('project_id', $data);
        $projectParam = null;
        $projectSet = '';
        if ($hasProject) {
            $projectParam = ($data['project_id'] ?? null) && (int) $data['project_id'] > 0
                ? (int) $data['project_id']
                : null;
            $projectSet = ', project_id = ?';
        }

        $hasParent = array_key_exists('parent_purchase_invoice_id', $data);
        $parentParam = null;
        $parentSet = '';
        if ($hasParent) {
            $parentParam = ($data['parent_purchase_invoice_id'] ?? null) ? (int) $data['parent_purchase_invoice_id'] : null;
            $parentSet = ', parent_purchase_invoice_id = ?';
        }

        // Forma úhrady (migrace 1128) — píšeme jen když ji volající poslal A jeho zdroj
        // smí přepsat ten uložený (manual > ai > vendor > default). Bez téhle brány by
        // AI re-extrakce nebo změna předvolby dodavatele tiše přebila to, co účetní
        // ručně nastavila. Ostatní update cesty pole neposílají → zůstává nedotčené.
        $hasPaymentMethod = array_key_exists('payment_method', $data)
            && PaymentMethods::isValid($data['payment_method']);
        $paymentMethodSet = '';
        $paymentMethodParams = [];
        if ($hasPaymentMethod) {
            $newSource = PaymentMethods::normalizeSource($data['payment_method_source'] ?? 'manual');
            if (PaymentMethods::canOverride($this->currentPaymentMethodSource($id, $supplierId), $newSource)) {
                $paymentMethodSet = ', payment_method = ?, payment_method_source = ?';
                $paymentMethodParams = [PaymentMethods::normalize($data['payment_method']), $newSource];
            } else {
                $hasPaymentMethod = false;
            }
        }

        // Kurz (migrace 1303): píšeme jen sloupce, které volající SKUTEČNĚ poslal. Dřív se
        // zapisovaly všechny tři při každém PUT — volající, který je vynechal, si tím kurz
        // vynuloval (`exchange_rate = NULL`) a zdroj degradoval na výchozí hodnotu.
        // Korunový doklad kurz mít nesmí (past pro agregace) → u CZK vždy NULL, ať pošle
        // volající cokoliv; symetrie s ExchangeRateApplier::applyToInvoice() na vydané straně.
        $isCzk = $this->isCzkCurrency($data['currency_id'] ?? null);
        $rateSet = '';
        $rateParams = [];
        if ($isCzk) {
            $rateSet = ', exchange_rate = NULL, exchange_rate_date = NULL';
        } else {
            if (array_key_exists('exchange_rate', $data)) {
                $rateSet .= ', exchange_rate = ?';
                $rateParams[] = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null;
            }
            if (array_key_exists('exchange_rate_date', $data)) {
                $rateSet .= ', exchange_rate_date = ?';
                $rateParams[] = empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'];
            }
        }
        if (array_key_exists('exchange_rate_source', $data)) {
            $rateSet .= ', exchange_rate_source = ?';
            $rateParams[] = ExchangeRateSources::normalize($data['exchange_rate_source']);
        }

        $sql = 'UPDATE purchase_invoices SET
                vendor_id = ?, vendor_invoice_number = ?, document_kind = ?,
                issue_date = ?, tax_date = ?, due_date = ?, received_at = ?,
                currency_id = ?'
              . $rateSet . ',
                reverse_charge = ?, prices_include_vat = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?,
                payment_currency_id = ?, payment_exchange_rate = ?,
                paid_amount_payment_ccy = ?, paid_amount_invoice_ccy = ?, exchange_diff_base = ?,
                vat_classification_code = ?, vat_deduction = ?, vat_deduction_percent = ?, tax_deductible = ?, is_fixed_asset = ?, expense_category_id = ?'
              . $receivedAtSourceSet
              . $projectSet
              . $parentSet
              . ($hasVendorVatPayer ? ', vendor_is_vat_payer = ?' : '')
              . $paymentSet
              . $paymentMethodSet
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ' WHERE id = ? AND supplier_id = ?'
              . ($requireUnbooked ? ' AND booked_at IS NULL' : '');

        $params = [
            (int) $data['vendor_id'],
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            ...$rateParams,
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional', 'reduced'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
            isset($data['expense_category_id']) && $data['expense_category_id'] ? (int) $data['expense_category_id'] : null,
        ];
        if ($hasReceivedAtSource) $params[] = $receivedAtSourceParam;
        if ($hasProject) $params[] = $projectParam;
        if ($hasParent) $params[] = $parentParam;
        if ($hasVendorVatPayer) $params[] = $vendorIsVatPayer;
        if ($hasPayment) {
            array_push($params, ...$paymentParams);
        }
        if ($hasPaymentMethod) {
            array_push($params, ...$paymentMethodParams);
        }
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        $params[] = $id;
        $params[] = $supplierId;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        if ($requireUnbooked && $stmt->rowCount() === 0) {
            // rowCount 0 = buď booked_at podmínka nesedla, nebo identická data (MySQL
            // počítá changed rows). Rozliš dotazem — locked jen když booked_at není NULL.
            // Vědomě akceptovaný double-race: mezi UPDATE a tímto SELECTem může účetní
            // doklad zase odemknout → falešné 200 u no-op zápisu (ms okno, bez dopadu
            // na data — UPDATE nic nezměnil).
            $check = $this->db->pdo()->prepare(
                'SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ? AND booked_at IS NULL'
            );
            $check->execute([$id, $supplierId]);
            return $check->fetchColumn() !== false;
        }
        return true;
    }

    /**
     * Smaže fakturu — ON DELETE CASCADE smaže i items.
     * Volající kontroluje, že je status=draft.
     */
    public function delete(int $id, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM purchase_invoices WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /**
     * Přepíše items (smaže staré + insertne nové).
     * Volá se z SetItems action; následuje recompute z PurchaseInvoiceCalculator.
     */
    public function replaceItems(int $purchaseInvoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')
            ->execute([$purchaseInvoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset, expense_kind, expense_account_code,
                 accrual_from, accrual_to, stock_item_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $vatRates = $this->vatRateMap();

        // Reverse charge + země dodavatele — určuje klasifikační kód:
        //   CZ vendor → '40'/'41'/'42' (tuzemsko podle sazby)
        //   CZ vendor + RC → '5' (přenesená povinnost)
        //   EU vendor s 0% → '24e' (přijetí služby z EU, ř.5) — typický pro Microsoft Ireland apod.
        //   non-EU vendor s 0% → '24' (přijetí služby ze 3. země, ř.12) — Anthropic, GitHub apod.
        $metaStmt = $pdo->prepare(
            'SELECT pi.supplier_id, pi.reverse_charge, co.iso2,
                    COALESCE(pi.tax_date, pi.issue_date) AS doc_date
               FROM purchase_invoices pi
               JOIN clients c     ON c.id  = pi.vendor_id
               JOIN countries co  ON co.id = c.country_id
              WHERE pi.id = ?'
        );
        $metaStmt->execute([$purchaseInvoiceId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['supplier_id' => 0, 'reverse_charge' => 0, 'iso2' => 'CZ', 'doc_date' => null];
        $reverseCharge = (bool) $meta['reverse_charge'];
        $countryIso = (string) ($meta['iso2'] ?? 'CZ');

        // Skladová karta na řádku (volitelná, Epic SKLAD/ESHOP) — jen NAZNAČUJE
        // kartu pro předvyplnění příjemkového wizardu; skutečný pohyb dělá až
        // příjemka. Guard vlastnictví: přijmeme jen id patřící témuž tenantovi.
        $supplierId = (int) ($meta['supplier_id'] ?? 0);
        $ownedStock = [];
        $stockIds = [];
        foreach ($items as $it) {
            $sid = is_array($it) && isset($it['stock_item_id']) && $it['stock_item_id'] !== null && $it['stock_item_id'] !== ''
                ? (int) $it['stock_item_id'] : 0;
            if ($sid > 0) {
                $stockIds[] = $sid;
            }
        }
        if ($stockIds !== [] && $supplierId > 0) {
            $stockIds = array_values(array_unique($stockIds));
            $ph = implode(',', array_fill(0, count($stockIds), '?'));
            $q = $pdo->prepare("SELECT id FROM stock_items WHERE supplier_id = ? AND id IN ($ph)");
            $q->execute(array_merge([$supplierId], $stockIds));
            $ownedStock = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }
        // Základní sazba pro rok dokladu (číselník daňových konstant) — určuje, kdy
        // sazba znamená "tuzemská základní" v auto-klasifikaci.
        $docYear = !empty($meta['doc_date']) ? (int) substr((string) $meta['doc_date'], 0, 4) : (int) date('Y');
        $standardRate = $this->taxConstants->vatRateStandard($docYear);

        // Plátcovství TENANTA k datu dokladu. Tuzemský režim přenesené povinnosti (§ 92a)
        // funguje jen MEZI PLÁTCI — identifikovaná osoba ani neplátce v něm být nemůže,
        // takže by jí kód '5' vyrobil samovyměření na ř. 10 a větu KH B.1, které nedluží.
        // Samovyměření u ZAHRANIČNÍHO plnění (23/24/24e/25) zůstává: to se identifikované
        // osoby týká (§ 108) a je hlavním důvodem existence celého režimu.
        $docDate = !empty($meta['doc_date']) ? (string) $meta['doc_date'] : date('Y-m-d');
        $tenantIsVatPayer = true;
        if ($supplierId > 0 && $this->db->hasTable('supplier_vat_status_history')) {
            $tenantIsVatPayer = VatStatusService::flagsAt($pdo, $supplierId, $docDate)['is_vat_payer'];
        }

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            // Auto-klasifikace pro DPH přiznání / KH — pokud caller (importer / manual create)
            // neuvedl explicitní kód, default podle sazby + RC + country. Bez tohohle by
            // faktura NEDORAZILA do výkazů (VatClassificationMapper SKIPNE code=NULL).
            $code = $item['vat_classification_code'] ?? null;
            if ($code === null) {
                $code = self::defaultClassificationCode(
                    $rate,
                    $reverseCharge,
                    $countryIso,
                    $standardRate,
                    PublicAuthorityFeeText::indicatesPublicAuthorityFee((string) ($item['description'] ?? '')),
                    $tenantIsVatPayer,
                );
            }
            $sid = isset($item['stock_item_id']) && $item['stock_item_id'] !== null && $item['stock_item_id'] !== ''
                ? (int) $item['stock_item_id'] : 0;
            $stockItemId = ($sid > 0 && isset($ownedStock[$sid])) ? $sid : null;

            // §DM: `expense_kind` je autoritativní klasifikace, `is_fixed_asset` zůstává jako
            // příznak pro DPH (VatLedgerService → ř. 47 DPHDP3) a evidenci majetku. Držíme je
            // v souladu na JEDNOM místě, jinak se dva zdroje pravdy rozejdou hned prvním
            // uložením: expense_kind='fixed_asset' ⇔ is_fixed_asset=1.
            $kind = ExpenseKind::tryFromNullable(
                isset($item['expense_kind']) && $item['expense_kind'] !== '' ? (string) $item['expense_kind'] : null
            );
            $legacyFixed = !empty($item['is_fixed_asset']);
            if ($kind === null && $legacyFixed) {
                $kind = ExpenseKind::FixedAsset;   // starý caller posílá jen boolean
            }
            $isFixedAsset = $kind !== null
                ? ($kind === ExpenseKind::FixedAsset ? 1 : 0)
                : ($legacyFixed ? 1 : 0);

            $stmt->execute([
                $purchaseInvoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
                $code !== null ? (string) $code : null,
                $isFixedAsset,
                $kind?->value,
                // Účet na řádku (nepovinný) — platnost ověřuje PostingService proti osnově
                // tenanta; tady se jen normalizuje prázdný řetězec na NULL.
                isset($item['expense_account_code']) && trim((string) $item['expense_account_code']) !== ''
                    ? trim((string) $item['expense_account_code'])
                    : null,
                // Časové rozlišení nákladu (§DČR) — období od–do. Prázdné → NULL (bez rozlišení).
                // Odklad na 381 dělá až uzávěrka; tady se jen ULOŽÍ zadané období.
                self::normalizeAccrualDate($item['accrual_from'] ?? null),
                self::normalizeAccrualDate($item['accrual_to'] ?? null),
                $stockItemId,
            ]);
        }
    }

    /**
     * Normalizace data časového rozlišení řádku (§DČR) — přijme YYYY-MM-DD, jinak NULL.
     * Prázdný řetězec / null / neplatný formát = NULL (bez rozlišení, dosavadní chování).
     */
    private static function normalizeAccrualDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        return $d !== false ? $d->format('Y-m-d') : null;
    }

    /**
     * Default vat_classification_code podle sazby + RC + země dodavatele pro PŘIJATÉ faktury.
     *
     * Mapování:
     *   CZ vendor:
     *     RC + 21%      → '5'  (přenesená povinnost tuzemsko)
     *     21% standard  → '40' (přijaté plnění tuzemsko — základní)
     *     12% standard  → '41' (přijaté plnění tuzemsko — snížená)
     *     0%            → null (osvobozeno bez nároku — user si vybere)
     *   EU vendor (DE, SK, AT, IE, …):
     *     0% → '24e' (přijetí služby z EU, ř.5 — typický pro Microsoft Ireland)
     *     21%/12% → tuzemsko sazby (vendor v EU vykazuje českou DPH — vzácné)
     *   Non-EU vendor (US, UK, atd.):
     *     0% → '24' (přijetí služby ze 3. země / od neusazené osoby, ř.12 — Anthropic, GitHub)
     *     jinak tuzemsko sazby
     *
     * Pro pořízení zboží z EU ('23') či dovoz zboží ze 3. země ('25') si user
     * změní ručně — default 0%+zahraničí mapujeme na SLUŽBY, což je častější CZ IT use case.
     * AI import sem u RC dokladů nespadne: nastavuje explicitní kód (23/24/25 dle
     * supply_nature) + tuzemskou sazbu 21 % už v AiPdfExtractoru (issue #116).
     */
    public static function defaultClassificationCode(
        float $rate,
        bool $reverseCharge,
        ?string $vendorCountryIso2 = null,
        // Základní sazba pro rok dokladu (číselník daňových konstant). Default 21
        // drží zpětnou kompatibilitu pro volání bez kontextu (CLI backfill).
        float $standardRate = 21.0,
        // Poplatek orgánu veřejné moci ({@see PublicAuthorityFeeText}) — plnění
        // MIMO předmět daně, nikdy se nesamovyměřuje ani u zahraničního „dodavatele".
        bool $publicAuthorityFee = false,
        // Je TENANT plátcem DPH k datu dokladu? Neplátce ani identifikovaná osoba nemůže
        // být v tuzemském § 92a → kód '5' se jim nepřiřazuje (zahraniční samovyměření ano).
        bool $tenantIsVatPayer = true,
    ): ?string {
        $r = (int) round($rate);
        $std = (int) round($standardRate);
        $iso = strtoupper((string) ($vendorCountryIso2 ?? 'CZ'));
        $euCountries = [
            'AT','BE','BG','HR','CY','DK','EE','FI','FR','DE','GR','HU','IE','IT',
            'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        ];
        $isEu = in_array($iso, $euCountries, true);
        $isForeign = $iso !== 'CZ' && $iso !== '';

        // Zahraniční dodavatel + nulová sazba → reverse charge SLUŽBA (drtivě
        // nejčastější případ: digitální předplatná Anthropic/GitHub/Apple/Google).
        // EU → ř.5 (kód 24e), 3. země / neusazená osoba → ř.12 (kód 24). Pořízení
        // nebo dovoz ZBOŽÍ (ř.3 / ř.7) se ze samotné sazby nepozná → tam kód vybírá
        // AI dle povahy plnění (supply_nature) nebo uživatel ručně. Dřív se mimo-EU
        // 0 % defaultovalo na 25 (ř.7 dovoz zboží), což u služeb bylo věcně špatně.
        // Poplatek orgánu veřejné moci (soudní, správní, kolek) — orgán nejedná jako
        // osoba povinná k dani (§ 5 odst. 4 ZDPH / čl. 13 směrnice), plnění tedy NENÍ
        // předmětem daně a příjemci nevzniká povinnost přiznat daň ani u zahraničního
        // soudu/úřadu. Žádný kód → doklad zůstane mimo přiznání i KH (issue #30).
        if ($r === 0 && $publicAuthorityFee) {
            return null;
        }
        if ($isForeign && $r === 0) {
            return $isEu ? '24e' : '24';
        }
        // EU vendor + RC + 21 % → pořízení zboží z JČS (kód 23, ř. 3 + ř. 43 mirror + KH A.2).
        // Vzácnější použití (vendor obvykle fakturuje bez DPH), ale když má 21 % sazbu
        // (typicky reverse-charge invoice s vyčíslenou daní pro info), tohle je správně.
        if ($isEu && $reverseCharge && $r >= $std) return '23';
        // Dodavatel ze 3. země / neusazená osoba + RC + základní sazba → přijetí služby
        // (kód 24, ř. 12 + KH A.2). Dřív takový doklad propadl na tuzemský § 92a ('5',
        // ř. 10 + KH B.1), přestože přenesenou povinnost mezi plátci v tuzemsku
        // se zahraničním dodavatelem uplatnit nelze. Dovoz ZBOŽÍ (ř. 7, kód 25) se ze
        // sazby nepozná — ten vybírá AI dle povahy plnění nebo uživatel ručně.
        if ($isForeign && $reverseCharge && $r >= $std) return '24';
        // CZ tuzemsko (nebo zahraniční vendor s CZ DPH, vzácné). Tuzemský § 92a jen pro
        // plátce — neplátci/identifikované osobě kód '5' nepřiřadíme (viz $tenantIsVatPayer).
        if ($reverseCharge && $tenantIsVatPayer && $r >= $std) return '5';
        // Tuzemský § 92a doklad se vystavuje BEZ vyčíslené daně (sazba 0 %) — dřív takový
        // doklad propadl na null a do přiznání (ř.10 + KH B.1) se vůbec nedostal.
        if ($reverseCharge && $tenantIsVatPayer && $r === 0)   return '5';
        if ($r >= $std)                   return '40';
        // Snížené sazby 5–15 % (12 aktuální, 10/15 historické). Pásmo 16 až <std
        // záměrně nemapujeme (např. německá 19 % není česká DPH → user vybere ručně).
        if ($r >= 5 && $r <= 15)          return '41';
        return null;
    }

    /**
     * Doplní hlavičkovou klasifikaci z řádků, pokud na hlavičce žádná není.
     *
     * Kód na řádcích derivuje {@see defaultClassificationCode()} (zná zemi dodavatele,
     * plátcovství tenanta i povahu plnění), takže hlavička ho jen přebírá — nikdy
     * nerozhoduje sama. Dřív ji dopočítával VatClassificationDefaulter, který zemi
     * dodavatele nezná: doklad v tuzemském režimu přenesené povinnosti (§ 92e stavební
     * práce, 21 %) tak dostal '24e' → ř. 5 + KH A.2 (služba z EU) místo ř. 10 + KH B.1.
     *
     * Bere kód s největším součtem |total_with_vat| — u dokladu s víc kódy je hlavička
     * jen orientační, rozhoduje řádek (výkazy čtou COALESCE(položka, hlavička)).
     * Řádky BEZ kódu se do volby nepočítají, ale prázdná hlavička je legitimní výsledek:
     * u plnění mimo předmět daně (§ 5 odst. 4) i u osvobozeného tuzemského plnění
     * se kód nepřiřazuje záměrně.
     */
    public function syncHeaderClassificationFromItems(int $purchaseInvoiceId, int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT pii.vat_classification_code AS code,
                    SUM(ABS(COALESCE(pii.total_with_vat, 0))) AS weight
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ?
                AND pi.supplier_id = ?
                AND pii.vat_classification_code IS NOT NULL
           GROUP BY pii.vat_classification_code
           ORDER BY weight DESC, code ASC
              LIMIT 1'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $code = $stmt->fetchColumn();
        if ($code === false) {
            return;
        }
        $pdo->prepare(
            'UPDATE purchase_invoices
                SET vat_classification_code = ?
              WHERE id = ? AND supplier_id = ?
                AND (vat_classification_code IS NULL OR vat_classification_code = "")'
        )->execute([(string) $code, $purchaseInvoiceId, $supplierId]);
    }

    /**
     * Zafixuje exchange_rate + date + source. NULL rate = vyresetovat.
     */
    public function setExchangeRate(int $id, ?float $rate, ?string $rateDate, string $source, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET exchange_rate = ?, exchange_rate_date = ?, exchange_rate_source = ?
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$rate, $rateDate, ExchangeRateSources::normalize($source), $id, $supplierId]);
    }

    /**
     * Je měna dokladu koruna? Rozhoduje o tom, jestli se kurz vůbec smí uložit
     * (viz createDraft/updateDraft). Výsledek cachujeme per instanci — měny se
     * v průběhu requestu nemění.
     */
    private function isCzkCurrency(mixed $currencyId): bool
    {
        $cid = (int) ($currencyId ?? 0);
        if ($cid <= 0) {
            return false;
        }
        if (!array_key_exists($cid, $this->czkCurrencyCache)) {
            $stmt = $this->db->pdo()->prepare('SELECT code FROM currencies WHERE id = ?');
            $stmt->execute([$cid]);
            $this->czkCurrencyCache[$cid] = strtoupper((string) ($stmt->fetchColumn() ?: '')) === 'CZK';
        }

        return $this->czkCurrencyCache[$cid];
    }

    /**
     * Status transition. Volající ověří povolené přechody (state machine).
     * Side-efekty (timestamp pole) tady — booked_at, paid_at, cancelled_at.
     *
     * @param bool $requireUnbooked Optimistický zámek (Epic F6, L1): UPDATE podmíněný
     *                              `booked_at IS NULL`. Vrací false, když doklad mezitím
     *                              někdo zaúčtoval (guard-check → zápis TOCTOU okno).
     */
    public function setStatus(int $id, string $newStatus, int $supplierId, ?string $paidDate = null, ?int $bookedBy = null, bool $requireUnbooked = false): bool
    {
        if (!in_array($newStatus, ['draft', 'received', 'booked', 'paid', 'cancelled'], true)) {
            throw new \InvalidArgumentException("Invalid status: $newStatus");
        }

        $sets = ['status = ?'];
        $params = [$newStatus];

        if ($newStatus === 'booked') {
            $sets[] = 'booked_at = NOW()';
            // Epic F6: kdo zaúčtoval (zámek pro roli client) — nepřepisuj dřívějšího autora.
            $sets[] = 'booked_by = COALESCE(booked_by, ?)';
            $params[] = $bookedBy;
        } elseif ($newStatus === 'paid') {
            $sets[] = 'paid_at = ?';
            $params[] = $paidDate ?? date('Y-m-d');
        } elseif ($newStatus === 'cancelled') {
            $sets[] = 'cancelled_at = NOW()';
        } elseif ($newStatus === 'received') {
            // Reverse transition (paid→received / cancelled→received) — vyčisti timestamp
            // odpovídajícího "exit" stavu, aby data byla konzistentní.
            $sets[] = 'paid_at = NULL';
            $sets[] = 'cancelled_at = NULL';
            // Epic F6: návrat do received odemyká omylem zabookovanou PF (unbook endpoint
            // pro PF neexistuje) — ale jen bez aktivního posted zápisu; s ním zámek drží
            // deník (stejná still_posted sémantika jako BookInvoiceAction::unbook /
            // JournalAction::unlockSourceAfterReverse — nejdřív reverse).
            if (!$this->hasActivePostedEntry($id, $supplierId)) {
                $sets[] = 'booked_at = NULL';
                $sets[] = 'booked_by = NULL';
            }
        }

        $params[] = $id;
        $params[] = $supplierId;

        $sql = 'UPDATE purchase_invoices SET ' . implode(', ', $sets)
             . ' WHERE id = ? AND supplier_id = ?'
             . ($requireUnbooked ? ' AND booked_at IS NULL' : '');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        // State machine nezná no-op přechod (status se vždy mění), takže rowCount 0
        // při requireUnbooked = booked_at podmínka nesedla → doklad mezitím zaúčtován.
        return !$requireUnbooked || $stmt->rowCount() > 0;
    }

    /** Aktivní posted zápis k PF (posted_at NOT NULL, reversed_by NULL) — drží zámek. */
    private function hasActivePostedEntry(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Propojí finální fakturu ($finalId) se zálohou ($advanceId). Vazba se ukládá
     * NA FINÁLNÍ fakturu (advance_purchase_invoice_id), 1:1 (UNIQUE index).
     *
     * Validace: oba doklady patří tenantovi, $advanceId je advance, $finalId NENÍ
     * advance, a oba mají stejného dodavatele. Pokud finální nemá vyplněnou zálohu
     * (advance_paid_amount = 0), doplní ji = total_with_vat zálohy, aby amount_to_pay
     * ukázal zbývající úhradu. Návrh AI (advance_link_suggested_id) se zároveň vyčistí.
     *
     * @throws \RuntimeException při porušení validace
     */
    public function linkAdvance(int $finalId, int $advanceId, int $supplierId): void
    {
        if ($finalId === $advanceId) {
            throw new \RuntimeException('Nelze propojit doklad sám se sebou.');
        }
        $final   = $this->find($finalId, $supplierId);
        $advance = $this->find($advanceId, $supplierId);
        if ($final === null || $advance === null) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        // Vyúčtovat lze zálohovou fakturu, ale i SAMOSTATNÝ daňový doklad k platbě.
        //
        // U nákupu zaplaceného kartou žádná zálohová faktura nevzniká: prodejce vystaví
        // rovnou „daňový doklad ke dni přijaté úplaty" (§ 28/8 ZDPH) a fakturu na zboží
        // pošle až s dodáním. Ten doklad zálohovou fakturu NAHRAZUJE — visí na 314 a
        // uplatňuje se z něj odpočet. Dokud šlo propojit jen `advance`, neměl uživatel
        // konečnou fakturu čím spárovat: 314 zůstalo nevypořádané a hrozilo, že se DPH
        // uplatní podruhé, protože nic nedrželo informaci, že už byla nárokována.
        //
        // DDKP navázaný na zálohovou fakturu se propojuje PŘES NI, ne přímo — jinak by
        // se tatáž záloha dala vyúčtovat dvakrát (jednou přes zálohu, jednou přes DDKP).
        $advanceKind = (string) ($advance['document_kind'] ?? '');
        $advanceIsStandaloneDdkp = $advanceKind === 'tax_document'
            && ($advance['parent_purchase_invoice_id'] ?? null) === null;
        if ($advanceKind !== 'advance' && !$advanceIsStandaloneDdkp) {
            throw new \RuntimeException($advanceKind === 'tax_document'
                ? 'Tenhle daňový doklad k platbě patří k zálohové faktuře — propoj konečnou fakturu s ní.'
                : 'Propojit lze jen se zálohovou fakturou nebo se samostatným daňovým dokladem k platbě.');
        }
        if (in_array((string) ($final['document_kind'] ?? ''), ['advance', 'tax_document'], true)) {
            throw new \RuntimeException('Zálohu nelze vyúčtovávat jinou zálohou ani daňovým dokladem k platbě.');
        }
        if ((int) $final['vendor_id'] !== (int) $advance['vendor_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí být od stejného dodavatele.');
        }
        if (($advance['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Stornovanou zálohovou fakturu nelze propojit.');
        }
        if ((int) $final['currency_id'] !== (int) $advance['currency_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí být ve stejné měně.');
        }

        $paid = $this->paidAdvanceAmount($advanceId, $supplierId);
        $paidInInvoiceCurrency = (float) ($advance['paid_amount_invoice_ccy'] ?? 0);
        $advanceBase = $paidInInvoiceCurrency > 0.0
            ? $paidInInvoiceCurrency
            : ($paid > 0.0
                ? $paid
                : (($advance['status'] ?? '') === 'paid' ? (float) $advance['total_with_vat'] : 0.0));
        $advanceTotal = min($advanceBase, (float) $final['total_with_vat']);
        $setAdvancePaid = ((float) ($final['advance_paid_amount'] ?? 0)) == 0.0;

        $sql = 'UPDATE purchase_invoices
                   SET advance_purchase_invoice_id = ?, advance_link_suggested_id = NULL'
             . ($setAdvancePaid ? ', advance_paid_amount = ?' : '')
             . ' WHERE id = ? AND supplier_id = ?';
        $params = $setAdvancePaid
            ? [$advanceId, $advanceTotal, $finalId, $supplierId]
            : [$advanceId, $finalId, $supplierId];
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    private function paidAdvanceAmount(int $advanceId, int $supplierId): float
    {
        $bank = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(pm.amount), 0)
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN journal_entries je
                 ON je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                AND je.source_id = pm.bank_transaction_id AND je.reversed_by IS NULL
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?
                AND UPPER(COALESCE(bt.currency, bs.currency, 'CZK')) = cur.code"
        );
        $bank->execute([$supplierId, $advanceId]);

        $cash = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(cd.total_amount), 0)
               FROM cash_documents cd
               JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN journal_entries je
                 ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                AND je.source_id = cd.id AND je.reversed_by IS NULL
              WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ?
                AND UPPER(cd.currency_code) = cur.code"
        );
        $cash->execute([$supplierId, $advanceId]);

        return round((float) $bank->fetchColumn() + (float) $cash->fetchColumn(), 2);
    }

    /**
     * Zruší propojení finální faktury se zálohou (advance_paid_amount ponecháme — ruční korekce).
     *
     * Zaúčtovaný doklad rozpojit NELZE. Zúčtování zálohy se do zápisu promítá právě
     * podle téhle vazby (PostingService::appendAdvanceSettlementPurchase přidává
     * 321 MD / 314 D). Po rozpojení by v deníku zůstaly řádky odkazující na vazbu,
     * která už neexistuje, a re-post by vygeneroval jiný zápis — doklad a deník by
     * se rozešly. Zrcadlo guardu na vydané větvi
     * ({@see InvoiceRepository::unlinkAdvance}, kde se stejně chrání finál
     * s odpočtovými řádky § 37a).
     *
     * @throws \RuntimeException když je doklad zaúčtovaný
     */
    public function unlinkAdvance(int $finalId, int $supplierId): void
    {
        $posted = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL
              LIMIT 1"
        );
        $posted->execute([$supplierId, $finalId]);
        if ($posted->fetchColumn() !== false) {
            throw new \RuntimeException(
                'Zaúčtovanou fakturu nelze odpojit od zálohy — zúčtování zálohy je součástí '
                . 'účetního zápisu. Nejdřív zápis stornuj, teprve pak vazbu zruš.'
            );
        }

        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_purchase_invoice_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /** Uloží AI návrh propojení se zálohou (suggest & confirm) — neaplikuje vazbu. */
    public function suggestAdvanceLink(int $finalId, int $advanceId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = ?
                        WHERE id = ? AND supplier_id = ? AND advance_purchase_invoice_id IS NULL')
            ->execute([$advanceId, $finalId, $supplierId]);
    }

    /** Zahodí AI návrh propojení. */
    public function dismissAdvanceSuggestion(int $finalId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /**
     * Kandidáti k propojení: nespárované zálohy (document_kind='advance') stejného
     * dodavatele jako finální faktura $finalId, které ještě nejsou navázané na žádnou
     * finální fakturu. Seřazené od nejnovějších.
     *
     * @return list<array<string,mixed>>
     */
    public function advanceCandidates(int $finalId, int $supplierId): array
    {
        $final = $this->find($finalId, $supplierId);
        if ($final === null) return [];
        // Řazení: nejdřív stejná měna, pak nejbližší HRUBÁ částka (total_with_vat) k
        // finální faktuře — záloha bývá ve výši celé/části faktury. Porovnáváme proti
        // total_with_vat (před odečtem zálohy), NE amount_to_pay (to bývá 0, když je
        // faktura už uhrazená zálohou). Nakonec nejnovější.
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.vendor_id = ?
                -- Kromě zálohových faktur i SAMOSTATNÉ daňové doklady k platbě: u nákupu
                -- placeného kartou je takový doklad jedinou zálohou, která existuje.
                -- DDKP patřící k zálohové faktuře se nabízet nesmí — vyúčtuje se přes ni,
                -- jinak by šla tatáž záloha vyúčtovat dvakrát.
                AND (pi.document_kind = 'advance'
                     OR (pi.document_kind = 'tax_document' AND pi.parent_purchase_invoice_id IS NULL))
                AND pi.status != 'cancelled'
                AND pi.id <> ?
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY (pi.currency_id = ?) DESC,
                       ABS(pi.total_with_vat - ?) ASC,
                       pi.issue_date DESC, pi.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $final['vendor_id'], $finalId,
            (int) $final['currency_id'], (float) $final['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => (string) $r['document_kind'],
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Opačný směr párování — z detailu zálohy ($advanceId) nabídne nepropojené finální
     * faktury (document_kind != 'advance', bez advance_purchase_invoice_id) stejného
     * dodavatele. Vlastní propojení proběhne přes linkAdvance($finalId, $advanceId).
     * Řazení: stejná měna → nejbližší hrubá částka → nejnovější.
     *
     * @return list<array<string,mixed>>
     */
    public function settlementCandidates(int $advanceId, int $supplierId): array
    {
        $advance = $this->find($advanceId, $supplierId);
        if ($advance === null) return [];
        return $this->settlementCandidatesFor(
            $advanceId, (int) $advance['vendor_id'], (int) $advance['currency_id'],
            (float) $advance['total_with_vat'], $supplierId,
        );
    }

    /**
     * Jádro {@see settlementCandidates()} bez volání find() — používá i unsettled_notice
     * v {@see find()} samotném, kde by volání settlementCandidates($id, ...) rekurzivně
     * zavolalo find($id, ...) znovu (nekonečná rekurze). Volající si vendor/currency/total
     * nese v ruce (buď z už načteného řádku, nebo z find() ve veřejné metodě výše).
     *
     * @return list<array<string,mixed>>
     */
    private function settlementCandidatesFor(int $advanceId, int $vendorId, int $currencyId, float $totalWithVat, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, pi.total_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.vendor_id = ?
                AND pi.document_kind != 'advance'
                AND pi.status != 'cancelled'
                AND pi.advance_purchase_invoice_id IS NULL
                AND pi.id <> ?
              ORDER BY (pi.currency_id = ?) DESC,
                       ABS(pi.total_with_vat - ?) ASC,
                       pi.issue_date DESC, pi.id DESC
              LIMIT 50"
        );
        $stmt->execute([$supplierId, $vendorId, $advanceId, $currencyId, $totalWithVat]);
        return array_map(fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => (string) $r['document_kind'],
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'total_vat'             => (float) $r['total_vat'],
            'currency'              => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Rychlé hledání přijatých faktur podle čísla dokladu (naše varsymbol nebo číslo
     * dodavatele) pro globální search box. Malý limit (dropdown).
     *
     * @return list<array{id:int, varsymbol:?string, vendor_invoice_number:?string,
     *   document_kind:?string, status:string, issue_date:?string, total_with_vat:float,
     *   currency:string, company_name:string}>
     */
    public function searchQuick(string $q, int $supplierId, int $limit = 6): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat,
                    COALESCE(cur.code, 'CZK') AS currency, c.company_name
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND (pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ?)
              ORDER BY pi.issue_date DESC, pi.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$supplierId, '%' . $esc . '%', '%' . $esc . '%']);
        return array_map(static fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
            'company_name'          => (string) $r['company_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Najde nespárovanou zálohu (advance) téhož dodavatele, jejíž číslo dokladu nebo
     * variabilní symbol odpovídá odkazu z faktury (např. "zaplaceno zálohou č. X").
     * Porovnává bez mezer (variabilní symbol může být na dokladu rozdělený). Vrací
     * id pro AI návrh propojení, nebo null. Konzervativní (přesná shoda) — návrh
     * uživatel stejně potvrzuje.
     */
    public function findAdvanceByReference(int $supplierId, int $vendorId, string $reference): ?int
    {
        $norm = preg_replace('/\s+/', '', trim($reference)) ?? '';
        if ($norm === '') return null;
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                AND pi.document_kind = 'advance'
                AND pi.status != 'cancelled'
                AND (REPLACE(COALESCE(pi.vendor_invoice_number,''), ' ', '') = ?
                  OR REPLACE(COALESCE(pi.varsymbol,''), ' ', '') = ?)
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY pi.issue_date DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $vendorId, $norm, $norm]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Maximální počet pokusů přeskočit obsazené interní číslo (poslední pojistka). */
    private const MAX_VARSYMBOL_SKIP = 1000;

    /**
     * Vestavěná výchozí šablona interního čísla přijaté faktury (= dosavadní chování).
     * {PP}=daňový prefix, {YY}{MM}=období, {CCC}=čítač → např. PF2602001.
     */
    public const PURCHASE_DEFAULT_TEMPLATE = '{PP}{YY}{MM}{CCC}';

    /**
     * Vygeneruje další interní číslo přijaté faktury pro tenant + období dle
     * per-supplier šablony (supplier.purchase_invoice_number_format), nebo dle
     * vestavěného defaultu {PP}{YY}{MM}{CCC} (např. PF2602001). Atomicky inkrementuje
     * counter (INSERT … ON DUPLICATE KEY).
     *
     * Placeholdery šablony: {PP} daňový prefix (PF/PN/KU/KN/NU/NN), {YYYY}/{YY}/{MM}
     * datum, {C+} čítač (padding dle počtu C). Scope čítače plyne ze šablony: má-li
     * {MM} → měsíční řada, jinak {YYYY}/{YY} → roční, jinak jediná řada.
     *
     * Samoopravné (paralela k vydaným, #85/#103): když je counter pozadu za již
     * použitými čísly (ruční číslo „dopředu", import, úprava v DB), vygenerované
     * číslo nevezme — skočí za nejvyšší skutečně použité číslo dané řady a najde
     * první volné. Unique index `uq_pi_supplier_varsymbol` je definitivní pojistka.
     *
     * $period je YYYYMM (období DUZP/vystavení); čítačový klíč se z něj odvodí dle scope.
     */
    public function nextVarsymbol(int $supplierId, ?string $period = null, string $prefix = 'PF'): string
    {
        $period   = $period ?? date('Ym');
        $prefix   = preg_match('/^[A-Z]{2}$/', $prefix) ? $prefix : 'PF';
        $template = $this->purchaseTemplate($supplierId);
        $counterPeriod = $this->purchaseCounterPeriod($template, $period);

        $n        = $this->bumpPurchaseCounter($supplierId, $counterPeriod);
        $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);

        // Happy path: counter sedí, číslo je volné.
        if (!$this->purchaseVarsymbolExists($supplierId, $rendered)) {
            return $rendered;
        }

        // Counter pozadu → skoč rovnou za nejvyšší použité číslo řady, pak dolaď mezery.
        $highest = $this->highestUsedPurchaseCounter($supplierId, $template, $period);
        if ($highest >= $n) {
            $n        = $this->liftPurchaseCounterTo($supplierId, $counterPeriod, $highest + 1);
            $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);
        }

        $attempts = 0;
        while ($this->purchaseVarsymbolExists($supplierId, $rendered)) {
            if (++$attempts > self::MAX_VARSYMBOL_SKIP) {
                throw new \RuntimeException(
                    'Nepodařilo se najít volné interní číslo přijaté faktury ani po '
                    . self::MAX_VARSYMBOL_SKIP . " pokusech (období {$period}). Zadej číslo ručně."
                );
            }
            $n        = $this->bumpPurchaseCounter($supplierId, $counterPeriod);
            $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);
        }

        return $rendered;
    }

    /** Per-supplier šablona interního čísla přijaté faktury, nebo vestavěný default. */
    private function purchaseTemplate(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT purchase_invoice_number_format FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        $t = trim((string) ($stmt->fetchColumn() ?: ''));
        return $t !== '' ? $t : self::PURCHASE_DEFAULT_TEMPLATE;
    }

    /** Vyrenderuje číslo ze šablony: {PP} prefix, {YYYY}/{YY}/{MM} z období, {C+} čítač. */
    private function renderPurchaseNumber(string $template, string $prefix, string $period, int $counter): string
    {
        $out = strtr($template, [
            '{PP}'   => $prefix,
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $out) ?? $out;
    }

    /** Klíč čítače dle scope šablony: měsíční (YYYYMM) / roční (YYYY) / jediná řada (ALL). */
    private function purchaseCounterPeriod(string $template, string $period): string
    {
        if (str_contains($template, '{MM}')) {
            return $period; // YYYYMM
        }
        if (str_contains($template, '{YYYY}') || str_contains($template, '{YY}')) {
            return substr($period, 0, 4); // YYYY
        }
        return 'ALL';
    }

    /** Atomický increment counteru období; vrací novou hodnotu (≥1). */
    private function bumpPurchaseCounter(int $supplierId, string $period): int
    {
        $pdo = $this->db->pdo();
        // LAST_INSERT_ID(expr) vrátí nově nastavenou hodnotu i při UPDATE větvi (MariaDB).
        $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        )->execute([$supplierId, $period]);
        $n = (int) $pdo->lastInsertId();
        return $n === 0 ? 1 : $n;
    }

    private function purchaseVarsymbolExists(int $supplierId, string $varsymbol): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Nejvyšší čítač mezi přijatými fakturami daného období, jejichž interní číslo
     * odpovídá šabloně po dosazení data ({PP} = libovolný 2písmenný prefix → čítač
     * se počítá napříč daňovými typy). 0 = žádná shoda. Jen zrychlený skok —
     * korektnost garantuje exact-match smyčka v nextVarsymbol().
     */
    private function highestUsedPurchaseCounter(int $supplierId, string $template, string $period): int
    {
        [$regex, $likePrefix] = $this->buildPurchaseMatcher($template, $period);
        if ($regex === null) {
            return 0;
        }
        $like = $likePrefix . '%';
        $stmt = $this->db->pdo()->prepare(
            "SELECT varsymbol FROM purchase_invoices
              WHERE supplier_id = ? AND varsymbol IS NOT NULL AND varsymbol <> '' AND varsymbol LIKE ?"
        );
        $stmt->execute([$supplierId, $like]);

        $max = 0;
        while (($vs = $stmt->fetchColumn()) !== false) {
            if (preg_match($regex, (string) $vs, $m)) {
                $val = (int) $m[1];
                if ($val > $max) {
                    $max = $val;
                }
            }
        }
        return $max;
    }

    /**
     * Postaví [PCRE regex, LIKE prefix] pro zpětné vyparsování čítače z interního čísla.
     * Datumové placeholdery se dosadí konkrétně, {PP} → [A-Z]{2}, {C+} → (\d+).
     * LIKE prefix = literály (+ '__' za {PP}) až po první {C+} pro zúžení skenu.
     *
     * @return array{0: ?string, 1: string}  [regex nebo null (šablona bez čítače), likePrefix]
     */
    private function buildPurchaseMatcher(string $template, string $period): array
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return [null, ''];
        }
        $withDate = strtr($template, [
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        // Sentinely mimo regex/LIKE escaping.
        $marked = str_replace('{PP}', "\x00P\x00", $withDate);
        $marked = preg_replace('/\{C+\}/', "\x00C\x00", $marked) ?? $marked;
        $parts  = preg_split('/(\x00P\x00|\x00C\x00)/', $marked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $regex = '';
        $likePrefix = '';
        $beforeCounter = true;
        foreach ($parts as $p) {
            if ($p === "\x00P\x00") {
                $regex .= '[A-Z]{2}';
                if ($beforeCounter) {
                    $likePrefix .= '__';
                }
            } elseif ($p === "\x00C\x00") {
                $regex .= '(\d+)';
                $beforeCounter = false;
            } elseif ($p !== '') {
                $regex .= preg_quote($p, '/');
                if ($beforeCounter) {
                    $likePrefix .= $this->escapeLikePurchase($p);
                }
            }
        }
        return ['/^' . $regex . '$/', $likePrefix];
    }

    /** Escapuje znaky se zvláštním významem v LIKE (% _ \). */
    private function escapeLikePurchase(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Zvedne counter období na minimálně $value (GREATEST, nikdy nesnižuje); vrací výslednou hodnotu. */
    private function liftPurchaseCounterTo(int $supplierId, string $period, int $value): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
        )->execute([$supplierId, $period, $value]);
        $sel = $pdo->prepare(
            'SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = ? AND period = ?'
        );
        $sel->execute([$supplierId, $period]);
        return (int) $sel->fetchColumn();
    }

    /**
     * Po změně daňového uplatnění (vat_deduction / tax_deductible) přepíše daňový
     * PREFIX ({PP}) auto-generovaného interního čísla na ten odpovídající novému
     * typu — číselnou řadu i datum ponechá. Např. PF2602001 → NN2602001.
     *
     * No-op pro: draft (bez čísla), šablonu bez {PP} (pevný prefix, např. legacy
     * 'PF-…'), ručně zadaná / cizí čísla (neodpovídají šabloně) a když prefix sedí.
     */
    public function reprefixVarsymbol(int $id, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT varsymbol, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;

        $vs = (string) ($row['varsymbol'] ?? '');
        if ($vs === '') return; // draft / bez čísla

        $template = $this->purchaseTemplate($supplierId);
        // Bez {PP} se daňový prefix v čísle nevyskytuje → není co přepisovat (např. legacy 'PF-…').
        if (!str_contains($template, '{PP}')) return;

        $expected = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        $newVs = $this->swapTemplatePrefix($template, $vs, $expected);
        if ($newVs === null || $newVs === $vs) return; // ruční / cizí číslo, nebo prefix už sedí

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$newVs, $id, $supplierId]);
    }

    /**
     * Nahradí daňový prefix ({PP}) v interním čísle dle šablony za $newPrefix, ostatní
     * segmenty (datum, čítač, literály) zachová. Vrací null, když číslo neodpovídá
     * struktuře šablony (ruční / cizí číslo). Date-agnostické.
     */
    private function swapTemplatePrefix(string $template, string $varsymbol, string $newPrefix): ?string
    {
        $tokens = preg_split('/(\{PP\}|\{YYYY\}|\{YY\}|\{MM\}|\{C+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex  = '';
        foreach ($tokens as $tok) {
            $regex .= match (true) {
                $tok === '{PP}'                       => '(?<pp>[A-Z]{2})',
                $tok === '{YYYY}'                     => '\d{4}',
                $tok === '{YY}', $tok === '{MM}'      => '\d{2}',
                (bool) preg_match('/^\{C+\}$/', $tok) => '\d+',
                $tok === ''                           => '',
                default                               => preg_quote($tok, '/'),
            };
        }
        if (!preg_match('/^' . $regex . '$/', $varsymbol, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        [$pp, $offset] = $m['pp'];
        if ($pp === $newPrefix) {
            return $varsymbol;
        }
        return substr($varsymbol, 0, $offset) . $newPrefix . substr($varsymbol, $offset + strlen($pp));
    }

    /**
     * Prefix interního čísla přijaté faktury podle daňového typu:
     *   plný nárok    → PF (uznatelný) / PN (neuznatelný)
     *   poměrný §75   → KU / KN
     *   krácený §76   → KR / RN   (koeficient — vypořádání na úrovni roku)
     *   bez nároku    → NU / NN
     * Prefix musí být přesně 2 velká písmena ([A-Z]{2}, viz swapTemplatePrefix).
     */
    public static function varsymbolPrefix(string $vatDeduction, bool $taxDeductible): string
    {
        return match ($vatDeduction) {
            'none'         => $taxDeductible ? 'NU' : 'NN',
            'proportional' => $taxDeductible ? 'KU' : 'KN',
            'reduced'      => $taxDeductible ? 'KR' : 'RN',
            default        => $taxDeductible ? 'PF' : 'PN',
        };
    }

    /**
     * Přiřadí varsymbol fakture, pokud ho nemá. Idempotentní — pokud už ho má, nedělá nic.
     */
    public function ensureVarsymbol(int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT varsymbol, issue_date, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Purchase invoice #$id not found.");
        }
        if (!empty($row['varsymbol'])) {
            return (string) $row['varsymbol'];
        }

        $period = date('Ym', strtotime((string) $row['issue_date']));
        $prefix = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        $varsymbol = $this->nextVarsymbol($supplierId, $period, $prefix);

        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$varsymbol, $id, $supplierId]);
        return $varsymbol;
    }

    /**
     * Update totálů z items (volá PurchaseInvoiceCalculator).
     */
    /** Update jen rounding pole (volá AI import po extract). */
    public function setRounding(int $id, int $supplierId, float $rounding): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET rounding = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$rounding, $id, $supplierId]);
    }

    /**
     * Uloží (nebo vyčistí) ruční rekapitulaci DPH dle dokladu (§ 73 ZDPH).
     * Sanitizuje vstup na list `{rate, base, vat}` (čísla zaokrouhlená na 2 des. místa);
     * prázdné/`null` → NULL (žádný override, kalkulátor počítá standardně).
     *
     * @param list<array{rate?: float|int, base?: float|int|null, vat?: float|int|null}>|null $overrides
     */
    public function setVatOverrides(int $id, int $supplierId, ?array $overrides): void
    {
        $clean = [];
        foreach ($overrides ?? [] as $o) {
            if (!is_array($o) || !isset($o['rate']) || !is_numeric($o['rate'])) {
                continue;
            }
            $entry = ['rate' => round((float) $o['rate'], 2)];
            if (array_key_exists('base', $o) && $o['base'] !== null && is_numeric($o['base'])) {
                $entry['base'] = round((float) $o['base'], 2);
            }
            if (array_key_exists('vat', $o) && $o['vat'] !== null && is_numeric($o['vat'])) {
                $entry['vat'] = round((float) $o['vat'], 2);
            }
            // Override bez base i vat nemá smysl (= žádná změna pro tu sazbu).
            if (array_key_exists('base', $entry) || array_key_exists('vat', $entry)) {
                $clean[] = $entry;
            }
        }
        $json = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET vat_overrides = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$json, $id, $supplierId]);
    }

    /**
     * Zapíše (nebo vyčistí) diagnostický popis problému z AI extrakce.
     * UI ho zobrazí jako žluté upozornění, aby si uživatel data ověřil
     * (typicky: AI sečetla subtotaly jako další položky).
     */
    public function setExtractionWarning(int $id, int $supplierId, ?string $warning): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$warning, $id, $supplierId]);
    }

    /**
     * Přidá další varování k existujícímu (oddělené prázdným řádkem) místo přepsání.
     * Prázdná faktura → nastaví jen nové; prázdný vstup → no-op. Pro importéry, které
     * mohou přidat varování (rekapitulace DPH) vedle už existujícího (AI mismatch).
     */
    public function appendExtractionWarning(int $id, int $supplierId, string $warning): void
    {
        $warning = trim($warning);
        if ($warning === '') {
            return;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT extraction_warning FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $current = $stmt->fetchColumn();
        $combined = ($current === false || $current === null || trim((string) $current) === '')
            ? $warning
            : rtrim((string) $current) . "\n\n" . $warning;
        $pdo->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$combined, $id, $supplierId]);
    }

    /**
     * Označí přijatou fakturu identifikátorem importní dávky (#232). Volá se po
     * úspěšném vytvoření dokladu při hromadném AI importu, ať jde dávka později
     * dohledat/filtrovat v seznamu. Idempotentní (přepíše na stejnou hodnotu).
     */
    public function setImportBatchId(int $id, int $supplierId, string $batchId): void
    {
        $batchId = substr(trim($batchId), 0, 32);
        if ($batchId === '') {
            return;
        }
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET import_batch_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$batchId, $id, $supplierId]);
    }

    /**
     * Smí doklad OPUSTIT druh `tax_document` (DDKP, daňový doklad k poskytnuté záloze,
     * § 28 ZDPH)? Vrací `null` = ano, jinak hlášku pro uživatele.
     *
     * DDKP je všude výjimka: mimo náklady, mimo závazky, mimo příkaz k úhradě, vlastní
     * větev v {@see \MyInvoice\Service\Accounting\PostingService} (343/314 místo 5xx/321).
     * Ztratit ji smí jen doklad, na kterém žádná z těch vazeb nevisí:
     *   - `parent_purchase_invoice_id` = DDKP patřící k zálohové faktuře (odpočet DPH
     *     ze zálohy je navázaný na ni),
     *   - jiný doklad ho vyúčtoval jako zálohu (`advance_purchase_invoice_id`) —
     *     konečná faktura na něm drží zúčtování zálohy.
     * Samostatný DDKP bez vazeb je typicky jen špatná AI klasifikace obyčejné faktury
     * (nadpis „Daňový doklad") — tu musí jít opravit, ne ji jen stornovat.
     *
     * Jediné místo, kde tohle pravidlo žije: volá ho {@see updateDraft()} (editor
     * dokladu, tvrdě výjimkou) i {@see updateDocumentKind()} (rychlá změna ze seznamu).
     */
    public function taxDocumentKindChangeBlocker(int $id, int $supplierId): ?string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT document_kind, parent_purchase_invoice_id
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['document_kind'] !== 'tax_document') {
            return null;
        }
        if (($row['parent_purchase_invoice_id'] ?? null) !== null) {
            return 'Daňový doklad k platbě je navázaný na zálohovou fakturu a jeho DPH už byla '
                . 'uplatněna — druh dokladu proto změnit nelze. Nejdřív zrušte vazbu na zálohu, '
                . 'nebo doklad stornujte.';
        }
        $used = $pdo->prepare(
            'SELECT 1 FROM purchase_invoices
              WHERE supplier_id = ? AND advance_purchase_invoice_id = ? LIMIT 1'
        );
        $used->execute([$supplierId, $id]);
        if ($used->fetchColumn() !== false) {
            return 'Daňovým dokladem k platbě je už vyúčtovaná konečná faktura — druh dokladu '
                . 'proto změnit nelze. Nejdřív zrušte vyúčtování na konečné faktuře.';
        }
        return null;
    }

    /**
     * Rychlá změna typu dokladu (#232) — pro opravu po AI importu, kdy AI účtenku
     * klasifikuje jako `receipt` („Doklad o úhradě"), ale účetní ji chce vést jako
     * `invoice`. Řádkové totály ani `prices_include_vat` NEmění (jsou uložené), jde
     * jen o metadata/zařazení. Přechod z/na `advance` je vyloučen (má vazby na
     * settlement — ten se řeší jen v editoru); stornovaný doklad měnit nelze.
     *
     * @return string|null  chybová hláška (pro UI), nebo null při úspěchu
     */
    /**
     * Zařadí doklad k zakázce / vyřadí ho z ní (issue #29). Samostatně od update(),
     * protože dimenzi lze měnit i u ZAÚČTOVANÉHO dokladu — viz
     * {@see \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction}.
     * Tenant scope zakázky ověřuje Action (TenantReferenceGuard).
     */
    public function updateProject(int $id, int $supplierId, ?int $projectId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices SET project_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$projectId, $id, $supplierId]);
    }

    public function updateDocumentKind(int $id, int $supplierId, string $kind): ?string
    {
        $allowed = ['invoice', 'receipt', 'credit_note', 'advance'];
        if (!in_array($kind, $allowed, true)) {
            return 'Neplatný typ dokladu.';
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT document_kind, status FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'Doklad nenalezen.';
        }
        $current = (string) $row['document_kind'];
        if ($current === $kind) {
            return null; // no-op
        }
        if ((string) $row['status'] === 'cancelled') {
            return 'Stornovaný doklad nelze měnit.';
        }
        if ($current === 'advance' || $kind === 'advance') {
            return 'Změnu na/ze zálohy proveďte v editoru dokladu (má vazby na vyúčtování).';
        }
        // Do 'tax_document' se přepnout nedá (není v $allowed); ODEJÍT z něj smí jen DDKP
        // bez vazeb — týž SSOT jako v updateDraft().
        if ($current === 'tax_document') {
            $blocker = $this->taxDocumentKindChangeBlocker($id, $supplierId);
            if ($blocker !== null) {
                return $blocker;
            }
        }
        $pdo->prepare(
            'UPDATE purchase_invoices SET document_kind = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$kind, $id, $supplierId]);
        return null;
    }

    /**
     * Posledních N importních dávek (#232) — pro dropdown „dohledat import" v seznamu
     * přijatých. Vrací id dávky, čas první faktury v dávce a počet dokladů.
     *
     * @return list<array{import_batch_id:string, created_at:string, count:int}>
     */
    public function recentImportBatches(int $supplierId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT import_batch_id, MIN(created_at) AS created_at, COUNT(*) AS cnt
               FROM purchase_invoices
              WHERE supplier_id = ? AND import_batch_id IS NOT NULL
              GROUP BY import_batch_id
              ORDER BY created_at DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'import_batch_id' => (string) $r['import_batch_id'],
                'created_at'      => (string) $r['created_at'],
                'count'           => (int) $r['cnt'],
            ];
        }
        return $out;
    }

    public function updateTotals(int $id, float $withoutVat, float $vat, float $withVat, float $rounding): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vat, $withVat, $rounding, $id]);
    }

    /**
     * Vrátí ID faktury s daným pdf_hash u tenanta, nebo null. Pro dedup při PDF uploadu / inbox scanu.
     */
    public function findIdByPdfHash(int $supplierId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND pdf_hash = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Vrátí ID faktury s daným source_hash u tenanta, nebo null.
     *
     * Protějšek {@see findIdByPdfHash} pro STROJOVÝ originál (ISDOC/ISDOCX/XML). Holý
     * `.isdoc` žádné PDF nearchivuje, takže `pdf_hash` u něj zůstává prázdný a bez téhle
     * cesty by opakovaný sken téhož souboru pokaždé znovu prošel celým importem (nový
     * doklad sice neodmítl unikátní klíč, ale report ho počítal jako `created`).
     */
    public function findIdBySourceHash(int $supplierId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND source_hash = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Vrátí ID faktury s daným vendor_invoice_number u (tenant, vendor, issue_date) tuple,
     * nebo null pokud neexistuje. Respektuje UNIQUE KEY uq_pi_vendor_invoice — caller
     * tím detekuje "tahle faktura už je v systému" před voláním createDraft (které by
     * jinak hodilo SQLSTATE 23000 duplicate key).
     */
    public function findIdByVendorInvoice(int $supplierId, int $vendorId, string $vendorInvoiceNumber, string $issueDate): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ?
                AND vendor_invoice_number = ? AND issue_date = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $vendorId, $vendorInvoiceNumber, $issueDate]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Dohledá JEDNOZNAČNÉHO kandidáta na opravovanou fakturu dobropisu — běžnou fakturu
     * (`document_kind = 'invoice'`) téhož tenanta a dodavatele s daným číslem dokladu.
     * Vrací id JEN když existuje právě jeden takový doklad; při 0 nebo víc shodách null
     * (dohad do dat nepatří — stejná logika jako předvyplnění v migraci 1096).
     */
    public function findUniqueInvoiceIdByVendorNumber(int $supplierId, int $vendorId, string $vendorInvoiceNumber): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ?
                AND document_kind = \'invoice\'
                AND vendor_invoice_number = ?
              LIMIT 2'
        );
        $stmt->execute([$supplierId, $vendorId, $vendorInvoiceNumber]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /**
     * Set archived PDF metadata po úspěšném uložení souboru na disk.
     */
    public function setPdfMetadata(int $id, int $supplierId, string $path, string $hash, int $size, ?string $originalName): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET pdf_path = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_original_name = ?, pdf_uploaded_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([$path, $hash, $size, $originalName, $id, $supplierId]);
    }

    /**
     * Zápis metadat ZDROJOVÉHO artefaktu (strojový originál — ISDOC/ISDOCX/…).
     * Write-once: `AND source_path IS NULL` zaručí, že re-import / re-extrakce
     * nepřepíše evidenční stopu (kterou už jednou uloženou nesmíme měnit).
     */
    public function setSourceMetadata(int $id, int $supplierId, string $path, string $hash, int $size, ?string $originalName, string $format): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET source_path = ?, source_hash = ?, source_size_bytes = ?, source_original_name = ?,
                    source_format = ?, source_uploaded_at = NOW()
              WHERE id = ? AND supplier_id = ? AND source_path IS NULL'
        )->execute([$path, $hash, $size, $originalName, $format, $id, $supplierId]);
    }

    /**
     * Update totals na úrovni jedné položky (volá Calculator).
     */
    public function updateItemTotals(int $itemId, float $withoutVat, float $vatAmount, float $withVat): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoice_items
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vatAmount, $withVat, $itemId]);
    }

    /**
     * @return array<int, float> map [vat_rate_id => rate_percent]
     */
    public function vatRateMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * Postaví vendor_snapshot z aktuálního stavu clients row.
     */
    private function buildVendorSnapshot(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.company_name, c.first_name, c.last_name, c.ic, c.dic, c.tax_number,
                    c.street, c.city, c.zip, c.main_email, c.phone, c.language,
                    co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['id' => $vendorId] : $row;
    }

    /**
     * Group items by vat rate for breakdown table.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function buildVatBreakdown(array $items): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
            $key = number_format($rate, 2, '.', '');
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'vat_rate'    => $rate,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            $buckets[$key]['without_vat'] += (float) ($item['total_without_vat'] ?? 0);
            $buckets[$key]['vat']         += (float) ($item['total_vat'] ?? 0);
            $buckets[$key]['with_vat']    += (float) ($item['total_with_vat'] ?? 0);
        }
        ksort($buckets);
        return array_values($buckets);
    }

    private function castInvoice(array $row): array
    {
        foreach (['id', 'supplier_id', 'vendor_id', 'currency_id', 'payment_currency_id',
                  'created_by', 'pdf_size_bytes', 'source_size_bytes', 'expense_category_id',
                  'advance_purchase_invoice_id', 'advance_link_suggested_id',
                  'parent_purchase_invoice_id', 'cash_register_id', 'project_id'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) $row[$f] = (int) $row[$f];
        }
        $row['reverse_charge'] = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        $row['prices_include_vat'] = isset($row['prices_include_vat']) ? (bool) $row['prices_include_vat'] : false;
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        // §DM — jen v seznamu (list() ho počítá přes EXISTS). Podmíněně: v detailu sloupec
        // není a natvrdo dosazené false by lhalo o dokladu, který drobný majetek má.
        if (array_key_exists('has_small_asset', $row)) {
            $row['has_small_asset'] = (bool) $row['has_small_asset'];
        }
        $row['tax_deductible'] = !array_key_exists('tax_deductible', $row) || (bool) $row['tax_deductible'];
        $vatDeduction = (string) ($row['vat_deduction'] ?? '');
        $row['vat_deduction'] = in_array($vatDeduction, ['full', 'none', 'proportional', 'reduced'], true) ? $vatDeduction : 'full';
        $row['vat_deduction_percent'] = isset($row['vat_deduction_percent']) ? (float) $row['vat_deduction_percent'] : 100.0;
        foreach ([
            'total_without_vat', 'total_vat', 'total_with_vat', 'rounding',
            'advance_paid_amount', 'amount_to_pay',
            'exchange_rate', 'payment_exchange_rate',
            'paid_amount_payment_ccy', 'paid_amount_invoice_ccy', 'exchange_diff_base',
        ] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        // Decode JSON snapshots (DB column je longtext, ne JSON type)
        foreach (['vendor_snapshot', 'own_snapshot'] as $f) {
            if (isset($row[$f]) && is_string($row[$f]) && $row[$f] !== '') {
                $decoded = json_decode($row[$f], true);
                if (is_array($decoded)) $row[$f] = $decoded;
            }
        }
        // Ruční rekapitulace DPH dle dokladu (§ 73). NULL/prázdné → null (žádný override).
        if (array_key_exists('vat_overrides', $row)) {
            $raw = $row['vat_overrides'];
            $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
            $row['vat_overrides'] = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        foreach (['id', 'purchase_invoice_id', 'vat_rate_id', 'order_index', 'stock_item_id'] as $f) {
            if (isset($row[$f])) $row[$f] = (int) $row[$f];
        }
        foreach ([
            'quantity', 'unit_price_without_vat', 'vat_rate_snapshot',
            'total_without_vat', 'total_vat', 'total_with_vat',
        ] as $f) {
            if (isset($row[$f])) $row[$f] = (float) $row[$f];
        }
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        // Karta drobného majetku vzniklá z téhle položky (LEFT JOIN v itemsFor()) —
        // sbal do vnořeného objektu a syrové sa_* sloupce ze čtecího řádku odstraň.
        $row['small_asset'] = $row['small_asset_id'] !== null ? [
            'id'     => (int) $row['small_asset_id'],
            'name'   => (string) $row['small_asset_name'],
            'status' => (string) $row['small_asset_status'],
        ] : null;
        unset($row['small_asset_id'], $row['small_asset_name'], $row['small_asset_status']);
        return $row;
    }
}
