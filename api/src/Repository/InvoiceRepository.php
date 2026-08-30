<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Support\PaymentMethods;
use PDO;

/**
 * CRUD pro faktury + položky + listing s grupováním po měsících (DUZP).
 *
 * Konvence řazení/grupování:
 *   "month bucket" = COALESCE(tax_date, issue_date) → "YYYY-MM"
 *   pro proformu (tax_date NULL) tedy padá na issue_date
 */
final class InvoiceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Cache existence sloupce income_tax_exempt (migrace 0087). Instalace nasazená
     * s kódem ≥ v4.9.3, ale pozadu s migracemi, sloupce nemá → bez této detekce
     * by každé uložení faktury spadlo na PDOException. S detekcí se faktura uloží
     * (jen bez příznaku osvobození), dokud migrace 0087 neproběhne.
     */
    private ?bool $hasIncomeTaxExempt = null;

    private function supportsIncomeTaxExempt(): bool
    {
        if ($this->hasIncomeTaxExempt === null) {
            $col = $this->db->pdo()->query("SHOW COLUMNS FROM invoices LIKE 'income_tax_exempt'")->fetch();
            $this->hasIncomeTaxExempt = $col !== false;
        }
        return $this->hasIncomeTaxExempt;
    }

    /**
     * Cache existence sloupce auto_send_reminders (migrace 0088). Stejná obrana jako
     * u income_tax_exempt — instalace s kódem, ale pozadu s migrací sloupec nemá;
     * bez detekce by uložení faktury spadlo. Výchozí chování (upomínky zapnuté) drží
     * DB default 1, takže vynechání sloupce při INSERT/UPDATE nic nerozbije.
     */
    private ?bool $hasAutoSendReminders = null;

    private function supportsAutoSendReminders(): bool
    {
        if ($this->hasAutoSendReminders === null) {
            $col = $this->db->pdo()->query("SHOW COLUMNS FROM invoices LIKE 'auto_send_reminders'")->fetch();
            $this->hasAutoSendReminders = $col !== false;
        }
        return $this->hasAutoSendReminders;
    }

    /**
     * Cache existence sloupce is_simplified (migrace 1170, § 30 ZDPH). Stejná obrana jako
     * u income_tax_exempt: instalace pozadu s migrací sloupec nemá a bez detekce by
     * každé uložení faktury spadlo. Bez sloupce se doklad uloží jako běžný — příznak je
     * úleva od náležitostí, jeho absence tedy nic nerozbije.
     */
    private ?bool $hasSimplified = null;

    private function supportsSimplified(): bool
    {
        if ($this->hasSimplified === null) {
            $col = $this->db->pdo()->query("SHOW COLUMNS FROM invoices LIKE 'is_simplified'")->fetch();
            $this->hasSimplified = $col !== false;
        }
        return $this->hasSimplified;
    }

    private ?bool $hasOssItemColumns = null;

    private function supportsOssItemColumns(): bool
    {
        if ($this->hasOssItemColumns === null) {
            $this->hasOssItemColumns = $this->db->hasColumn('invoice_items', 'oss_applicable');
        }
        return $this->hasOssItemColumns;
    }

    private ?bool $hasOssManualReview = null;

    /**
     * Guard pro `oss_needs_manual_review` je VLASTNÍ, ne společný se zbytkem OSS sloupců:
     * mezi migracemi 0137 (základ OSS) a 1293 (příznak) je řada verzí, takže instance
     * s OSS schématem a bez příznaku je běžný stav, ne teoretický. Shodně s
     * {@see \MyInvoice\Service\Import\InvoiceImportService::insertItems()} — import
     * i uložení dokladu zapisují do týchž sloupců a nesmí se rozejít v tom, kdy je vidí.
     */
    private function supportsOssManualReview(): bool
    {
        if ($this->hasOssManualReview === null) {
            $this->hasOssManualReview = $this->supportsOssItemColumns()
                && $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');
        }
        return $this->hasOssManualReview;
    }

    /**
     * Detail faktury podle PK. Vlastnictví se ověřuje v Action vrstvě
     * (SupplierGuard::owns nad vráceným řádkem) — tady jde jen o read-back.
     *
     * Druhá vrstva proti BOLA (security report 2026-08, R2 b): JOINy na klienta,
     * projekt a kategorii tržby jsou kotvené na `i.supplier_id`, takže i kdyby
     * v řádku uvízl cizí FK (starší data, chybějící guard v nějaké Action), cizí
     * popisky se do odpovědi nedostanou. `projects` vlastní `supplier_id` NEMÁ —
     * scope se odvozuje přes `clients.supplier_id` (FK fk_proj_client), proto
     * EXISTS místo prostého AND.
     *
     * `currencies` je schválně ve DVOU JOINech, ne v jednom scoped:
     *   - `cur` (nescoped) dodává identitu měny — kód, symbol, počet desetinných
     *     míst. Bez nich doklad nespočítáš ani nevykreslíš a podle BOLA sweepu
     *     (§3) je to údaj bez citlivosti (ISO kód měny).
     *   - `curown` (scoped na `i.supplier_id`) dodává BANKOVNÍ ÚDAJE, které na
     *     témže řádku `currencies` leží. Ty citlivé jsou — a při rozpadlé vazbě
     *     se vrátí NULL místo účtu cizí firmy.
     * Kdyby byl scoped rovnou `cur`, celý doklad by při rozpadlé vazbě zmizel
     * (404) — a testovací fixtures napříč repem legitimně staví izolovaného
     * dodavatele nad sdílený řádek měny. Rozpad vazby má zhasnout bankovní údaje,
     * ne celý doklad.
     */
    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT i.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.ic AS client_ic, c.dic AS client_dic,
                    c.language AS client_language,
                    c.reverse_charge AS client_reverse_charge,
                    u.name AS created_by_name,
                    p.name AS project_name, p.hourly_rate AS project_hourly_rate,
                    p.payment_due_days AS project_payment_due_days,
                    p.project_number AS project_number, p.contract_number AS contract_number,
                    p.requires_work_report_approval AS project_requires_approval,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.label AS currency_label,
                    curown.account_number AS bank_account_number, curown.bank_code AS bank_code,
                    curown.bank_name AS bank_name, curown.iban AS bank_iban, curown.bic AS bank_bic,
                    rcat.label AS revenue_category_label, rcat.code AS revenue_category_code
               FROM invoices i
               JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
          LEFT JOIN users u ON u.id = i.created_by
          LEFT JOIN projects p ON p.id = i.project_id
                    AND EXISTS (SELECT 1 FROM clients pc
                                 WHERE pc.id = p.client_id AND pc.supplier_id = i.supplier_id)
               JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN currencies curown ON curown.id = i.currency_id
                    AND curown.supplier_id = i.supplier_id
          LEFT JOIN revenue_categories rcat ON rcat.id = i.revenue_category_id
                    AND rcat.supplier_id = i.supplier_id
              WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);

        // Fakturační emaily projektu (jen popisky, používané v UI hlavičce)
        if (!empty($row['project_id'])) {
            $stmt2 = $pdo->prepare(
                'SELECT email, label FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt2->execute([(int) $row['project_id']]);
            $row['project_billing_emails'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row['project_billing_emails'] = [];
        }

        // VAT breakdown
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        // Sleva: discount_percent je header zdroj pravdy, discount_amount je KLADNÁ
        // magnituda slevy (= -součet záporných slevových položek item_kind='discount').
        $discountAmount = 0.0;
        foreach ($row['items'] as $it) {
            if (($it['item_kind'] ?? 'standard') === 'discount') {
                $discountAmount -= (float) $it['total_without_vat'];
            }
        }
        $row['totals'] = [
            'without_vat'        => $row['total_without_vat'],
            'vat'                => $row['total_vat'],
            'with_vat'           => $row['total_with_vat'],
            'rounding'           => $row['rounding'],
            'advance_paid_amount'=> $row['advance_paid_amount'],
            'amount_to_pay'      => $row['amount_to_pay'],
            'discount_percent'   => $row['discount_percent'],
            'discount_amount'    => round($discountAmount, 2),
        ];

        // CZK přepočet — jen pokud měna != CZK a faktura má zafixovaný kurz.
        // rate_date není uložené přímo na faktuře (kurz odpovídá issue_date — nebo
        // nejbližšímu dříve dostupnému dni); pro zobrazení použijeme issue_date faktury.
        if (
            !empty($row['exchange_rate'])
            && (string) ($row['currency'] ?? '') !== 'CZK'
        ) {
            $rateDate = (string) ($row['exchange_rate_date'] ?? $row['issue_date']);
            $fallback = $rateDate !== (string) $row['issue_date'];
            $row['czk_recap'] = CzkRecap::build(
                $row['vat_breakdown'],
                (float) $row['exchange_rate'],
                $rateDate,
                $fallback,
            );
        } else {
            $row['czk_recap'] = null;
        }

        // Související doklady (pro cross-link v detailu):
        //  - u proformy: vystavený daňový doklad k záloze (dítě, invoice_type='invoice')
        //  - u dokladu s parent_invoice_id: rodič (proforma / původní faktura u storna/dobropisu)
        $row['final_invoice'] = null;
        if (($row['invoice_type'] ?? '') === 'proforma') {
            $ch = $pdo->prepare(
                "SELECT id, varsymbol, status FROM invoices
                  WHERE parent_invoice_id = ? AND invoice_type = 'invoice'
                  ORDER BY id LIMIT 1"
            );
            $ch->execute([$id]);
            $c = $ch->fetch(PDO::FETCH_ASSOC);
            $row['final_invoice'] = $c === false ? null : [
                'id' => (int) $c['id'], 'varsymbol' => $c['varsymbol'], 'status' => $c['status'],
            ];
        }
        $row['parent_invoice'] = null;
        if (!empty($row['parent_invoice_id'])) {
            $par = $pdo->prepare('SELECT id, varsymbol, status, invoice_type FROM invoices WHERE id = ?');
            $par->execute([(int) $row['parent_invoice_id']]);
            $p = $par->fetch(PDO::FETCH_ASSOC);
            $row['parent_invoice'] = $p === false ? null : [
                'id' => (int) $p['id'], 'varsymbol' => $p['varsymbol'],
                'status' => $p['status'], 'invoice_type' => $p['invoice_type'],
            ];
        }

        // Existují u tohoto odběratele nespárované zálohy (proforma) k propojení?
        // Počítáme jen pro daňové doklady bez vazby — jinak nemá nabídka „spárovat" smysl.
        // UI tlačítko se schová, když je false (stejná podmínka jako advanceCandidates()).
        $row['has_advance_candidates'] = false;
        if (($row['invoice_type'] ?? '') === 'invoice' && empty($row['parent_invoice_id'])) {
            $cand = $pdo->prepare(
                "SELECT EXISTS (
                          SELECT 1 FROM invoices i
                           WHERE i.supplier_id = ? AND i.client_id = ?
                             AND i.invoice_type = 'proforma' AND i.status != 'cancelled'
                             AND i.id <> ?
                             AND NOT EXISTS (SELECT 1 FROM invoices ch
                                              WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice')
                             -- Záloha s vystavenými daňovými doklady k platbě (#89) se ručně
                             -- nepáruje (finál by neměl § 37a odpočty) — linkAdvance ji odmítá.
                             AND NOT EXISTS (SELECT 1 FROM invoices td
                                              WHERE td.parent_invoice_id = i.id AND td.invoice_type = 'tax_document'
                                                AND td.status NOT IN ('draft', 'cancelled'))
                        )"
            );
            $cand->execute([(int) $row['supplier_id'], (int) $row['client_id'], $id]);
            $row['has_advance_candidates'] = (bool) $cand->fetchColumn();
        }

        // Opačný směr: u nepropojené proformy — existují nepropojené daňové doklady
        // téhož odběratele, se kterými ji lze spárovat? (řídí tlačítko v detailu zálohy)
        $row['has_final_candidates'] = false;
        if (($row['invoice_type'] ?? '') === 'proforma' && empty($row['final_invoice'])) {
            $fcand = $pdo->prepare(
                "SELECT EXISTS (
                          SELECT 1 FROM invoices i
                           WHERE i.supplier_id = ? AND i.client_id = ?
                             AND i.invoice_type = 'invoice' AND i.status != 'cancelled'
                             AND i.parent_invoice_id IS NULL AND i.id <> ?
                        )"
            );
            $fcand->execute([(int) $row['supplier_id'], (int) $row['client_id'], $id]);
            $row['has_final_candidates'] = (bool) $fcand->fetchColumn();
        }

        return $row;
    }

    /**
     * Zafixuje exchange_rate + exchange_rate_date na faktuře (CZK / 1 jednotka cizí
     * měny + den, ke kterému kurz patří — viz fallback logiku CnbExchangeRateClient).
     * Volá se z ExchangeRateApplier po fetch z ČNB. NULL = vyresetuje (např. při
     * změně na CZK měnu).
     */
    public function setExchangeRate(int $invoiceId, ?float $rate, ?string $rateDate = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET exchange_rate = ?, exchange_rate_date = ? WHERE id = ?'
        )->execute([$rate, $rateDate, $invoiceId]);
    }

    /**
     * Volba „inkasovat hotově do této pokladny" (migrace 1327). Samostatný setter, ne
     * další sloupec v pozičním UPDATE updateDraft() — zápis je nezávislý na zbytku
     * dokladu a vlastnictví pokladny váže volající (TenantReferenceGuard).
     * NULL = volba zrušena; {@see \MyInvoice\Service\Accounting\Cash\CashSettlementService}
     * na to reaguje smazáním dřív založeného pokladního dokladu.
     */
    public function setCashRegisterId(int $invoiceId, int $supplierId, ?int $registerId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET cash_register_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$registerId !== null && $registerId > 0 ? $registerId : null, $invoiceId, $supplierId]);
    }

    // ── Propojení zálohové faktury (proforma) s vyúčtovacím daňovým dokladem ──
    // Symetrické s PurchaseInvoiceRepository::linkAdvance — u vydaných je „záloha"
    // = invoice_type='proforma', vazba se ukládá NA finální fakturu (parent_invoice_id),
    // shodně s flow „vystavit daňový doklad ze zálohy". Zaplacení (status) se nemění.

    /**
     * Kandidáti k propojení: nespárované zálohové faktury (invoice_type='proforma')
     * stejného odběratele a dodavatele jako finální faktura $finalId, které ještě
     * nejsou navázané na žádný daňový doklad. Řazení: stejná měna → nejbližší hrubá
     * částka → nejnovější.
     *
     * @return list<array<string,mixed>>
     */
    public function advanceCandidates(int $finalId, int $supplierId): array
    {
        $final = $this->find($finalId);
        if ($final === null || (int) ($final['supplier_id'] ?? 0) !== $supplierId) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date,
                    i.total_with_vat, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.client_id = ?
                AND i.invoice_type = 'proforma'
                AND i.status != 'cancelled'
                AND i.id <> ?
                AND NOT EXISTS (SELECT 1 FROM invoices ch
                                 WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice')
                -- Záloha s vystavenými daňovými doklady k platbě (#89) — viz has_advance_candidates.
                AND NOT EXISTS (SELECT 1 FROM invoices td
                                 WHERE td.parent_invoice_id = i.id AND td.invoice_type = 'tax_document'
                                   AND td.status NOT IN ('draft', 'cancelled'))
              ORDER BY (i.currency_id = ?) DESC,
                       ABS(i.total_with_vat - ?) ASC,
                       i.issue_date DESC, i.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $final['client_id'], $finalId,
            (int) $final['currency_id'], (float) $final['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'             => (int) $r['id'],
            'varsymbol'      => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'invoice_type'   => (string) $r['invoice_type'],
            'status'         => (string) $r['status'],
            'issue_date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat' => (float) $r['total_with_vat'],
            'currency'       => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Propojí daňový doklad ($finalId) se zálohovou fakturou ($advanceId) — uloží
     * parent_invoice_id na finální fakturu. Pokud finální nemá vyplněnou zálohu
     * (advance_paid_amount = 0), doplní ji = total_with_vat proformy, aby amount_to_pay
     * ukázal zbývající úhradu (amount_to_pay je generated column). Status NEMĚNÍ.
     *
     * Validace: oba doklady patří dodavateli, oba mají stejného odběratele,
     * $advanceId je proforma, $finalId je běžný daňový doklad (invoice) bez rodiče.
     *
     * @throws \RuntimeException při porušení validace
     */
    public function linkAdvance(int $finalId, int $advanceId, int $supplierId): void
    {
        if ($finalId === $advanceId) {
            throw new \RuntimeException('Nelze propojit doklad sám se sebou.');
        }
        $final   = $this->find($finalId);
        $advance = $this->find($advanceId);
        if ($final === null || $advance === null
            || (int) ($final['supplier_id'] ?? 0) !== $supplierId
            || (int) ($advance['supplier_id'] ?? 0) !== $supplierId) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        if (($advance['invoice_type'] ?? '') !== 'proforma') {
            throw new \RuntimeException('Propojit lze jen se zálohovou fakturou (proforma).');
        }
        if (($final['invoice_type'] ?? '') !== 'invoice') {
            throw new \RuntimeException('Zálohu lze vyúčtovat jen běžným daňovým dokladem.');
        }
        if (!empty($final['parent_invoice_id'])) {
            throw new \RuntimeException('Faktura už je propojena s jiným dokladem.');
        }
        if ((int) $final['client_id'] !== (int) $advance['client_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí mít stejného odběratele.');
        }
        if (($advance['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Stornovanou zálohovou fakturu nelze propojit.');
        }
        if ((int) $final['currency_id'] !== (int) $advance['currency_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí být ve stejné měně.');
        }

        // Záloha s vystavenými daňovými doklady k přijaté platbě (#89) se ručně
        // párovat nedá — finál by musel nést § 37a záporné odpočtové řádky (snižují
        // základ i daň), které ruční link nepřidává → už zdaněná úplata by se na
        // finálu zdanila podruhé. Správná cesta: „Vystavit daňový doklad" přímo
        // z proformy (FinalFromProformaCreator řádky vygeneruje).
        $tdStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'tax_document'
                AND status NOT IN ('draft', 'cancelled')"
        );
        $tdStmt->execute([$advanceId]);
        if ((int) $tdStmt->fetchColumn() > 0) {
            throw new \RuntimeException(
                'K záloze existují daňové doklady k přijaté platbě — finál vystav tlačítkem „Vystavit daňový doklad" '
                . 'z detailu zálohy (doplní odpočtové řádky), ruční propojení by daň zdvojilo.'
            );
        }

        // Výše odpočtu zálohy (#89): primárně SKUTEČNĚ přijaté platby proformy
        // (paid_total); legacy fallback pro proformy bez evidence plateb
        // (paid_total = 0, status paid z dob před #89) → celý total.
        // advance_paid_amount nesmí překročit částku dokladu — jinak by amount_to_pay
        // (generated = total_with_vat − advance_paid_amount) spadl do mínusu. Když je
        // záloha větší než faktura, odečteme jen do výše faktury (zbytek = 0 k úhradě).
        $finalTotal   = (float) $final['total_with_vat'];
        $paidTotal    = (float) ($advance['paid_total'] ?? 0);
        $advanceBase  = $paidTotal > 0 ? $paidTotal : (float) $advance['total_with_vat'];
        $advanceTotal   = min($advanceBase, $finalTotal);
        $setAdvancePaid = ((float) ($final['advance_paid_amount'] ?? 0)) == 0.0;

        $sql = 'UPDATE invoices SET parent_invoice_id = ?'
             . ($setAdvancePaid ? ', advance_paid_amount = ?' : '')
             . ' WHERE id = ? AND supplier_id = ? AND parent_invoice_id IS NULL';
        $params = $setAdvancePaid
            ? [$advanceId, $advanceTotal, $finalId, $supplierId]
            : [$advanceId, $finalId, $supplierId];
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Zruší propojení daňového dokladu se zálohovou fakturou (parent_invoice_id = NULL).
     * advance_paid_amount ponecháme (ruční korekce). Odpojí jen vazbu na proformu —
     * původní fakturu storna/dobropisu (non-proforma parent) se nedotkne.
     *
     * Jen pro invoice_type='invoice' (ručně párovatelný finál) — vazba daňového
     * dokladu k přijaté platbě (#89) je strukturální (drží § 37a odpočty finálu
     * i vazbu na platbu) a rozpojit nejde.
     */
    public function unlinkAdvance(int $finalId, int $supplierId): void
    {
        $type = $this->db->pdo()->prepare(
            'SELECT invoice_type, parent_invoice_id FROM invoices WHERE id = ? AND supplier_id = ?'
        );
        $type->execute([$finalId, $supplierId]);
        $row = $type->fetch(PDO::FETCH_ASSOC);
        if (($row['invoice_type'] ?? null) === 'tax_document') {
            throw new \RuntimeException(
                'Daňový doklad k přijaté platbě je vázaný na platbu zálohové faktury — propojení nelze zrušit. '
                . 'Pokud doklad nemá existovat, smaž koncept nebo ho stornuj.'
            );
        }
        if (($row['invoice_type'] ?? null) === 'invoice' && !empty($row['parent_invoice_id'])) {
            $taxDocs = $this->db->pdo()->prepare(
                "SELECT 1 FROM invoices
                  WHERE supplier_id = ? AND invoice_type = 'tax_document'
                    AND status NOT IN ('draft', 'cancelled')
                    AND (parent_invoice_id = ? OR id IN (
                        SELECT tax_document_invoice_id FROM invoice_payments
                         WHERE invoice_id = ? AND tax_document_invoice_id IS NOT NULL
                    ))
                  LIMIT 1"
            );
            $taxDocs->execute([
                $supplierId,
                (int) $row['parent_invoice_id'],
                (int) $row['parent_invoice_id'],
            ]);
            if ($taxDocs->fetchColumn() !== false) {
                throw new \RuntimeException(
                    'Finální faktura obsahuje odpočty záloh podle § 37a — propojení nelze zrušit. '
                    . 'Chybný finál nejdřív stornujte.'
                );
            }
        }
        $this->db->pdo()->prepare(
            "UPDATE invoices f
                JOIN invoices p ON p.id = f.parent_invoice_id
                SET f.parent_invoice_id = NULL
              WHERE f.id = ? AND f.supplier_id = ? AND f.invoice_type = 'invoice'
                AND p.invoice_type = 'proforma'"
        )->execute([$finalId, $supplierId]);
    }

    /**
     * Opačný směr párování — z detailu zálohové faktury ($proformaId) nabídneme
     * nepropojené daňové doklady (invoice_type='invoice', bez parent_invoice_id)
     * stejného odběratele a dodavatele. Vlastní propojení pak proběhne přes
     * linkAdvance($finalId, $proformaId). Řazení: stejná měna → nejbližší částka → nejnovější.
     *
     * @return list<array<string,mixed>>
     */
    public function finalCandidates(int $proformaId, int $supplierId): array
    {
        $proforma = $this->find($proformaId);
        if ($proforma === null || (int) ($proforma['supplier_id'] ?? 0) !== $supplierId) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date,
                    i.total_with_vat, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.client_id = ?
                AND i.invoice_type = 'invoice'
                AND i.status != 'cancelled'
                AND i.parent_invoice_id IS NULL
                AND i.id <> ?
              ORDER BY (i.currency_id = ?) DESC,
                       ABS(i.total_with_vat - ?) ASC,
                       i.issue_date DESC, i.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $proforma['client_id'], $proformaId,
            (int) $proforma['currency_id'], (float) $proforma['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'             => (int) $r['id'],
            'varsymbol'      => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'invoice_type'   => (string) $r['invoice_type'],
            'status'         => (string) $r['status'],
            'issue_date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat' => (float) $r['total_with_vat'],
            'currency'       => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function itemsFor(int $invoiceId): array
    {
        $ossSelect = $this->supportsOssItemColumns()
            ? ', ii.oss_applicable, ii.oss_consumer_country, ii.oss_rate_type, ii.oss_supply_type,
                    ii.oss_exchange_rate, ii.oss_exchange_rate_date, ii.oss_taxable_amount_return,
                    ii.oss_vat_amount_return, ii.oss_original_period'
            : '';
        // Příznak „k ručnímu posouzení" se musí ČÍST, ne jen zapisovat: kdyby ho detail
        // dokladu nevracel, editor by ho neměl co poslat zpět a replaceItems() (DELETE +
        // INSERT) by ho při prvním uložení faktury zahodil — sloupec by byl mrtvý a
        // kategorie „místo plnění neurčeno" by po zavření reportu importu zanikla.
        if ($this->supportsOssManualReview()) {
            $ossSelect .= ', ii.oss_needs_manual_review';
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id, ii.invoice_id, ii.description, ii.quantity, ii.unit,
                    ii.unit_price_without_vat, ii.vat_rate_id, ii.vat_rate_snapshot,
                    ii.total_without_vat, ii.total_vat, ii.total_with_vat,
                    ii.order_index, ii.item_kind, ii.linked_work_report_id,
                    ii.vat_classification_code, ii.stock_item_id, ii.warehouse_id,
                    ii.small_asset_id, ii.asset_id,
                    sa.name AS small_asset_name, a.name AS asset_name' . $ossSelect . ',
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
          LEFT JOIN small_assets sa ON sa.id = ii.small_asset_id
          LEFT JOIN assets a        ON a.id  = ii.asset_id
              WHERE ii.invoice_id = ?
              ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * Vrátí faktury seskupené po měsíci podle COALESCE(tax_date, issue_date).
     *
     * Output: ['data' => [{month: '2026-04', total_*, count, items: [...]} ...], 'meta' => ...]
     *
     * Pokud je $perPage > 0, vrací jen daný řez řádků (LIMIT/OFFSET); meta obsahuje
     * total/page/per_page/pages. Pro export CSV / sumy přes celý dataset volat s $perPage = 0.
     *
     * Filtr `unpaid_as_of` (YYYY-MM-DD) — neuhrazeno K DATU X, ne dnešní status; stejný
     * zdroj pravdy o úhradě jako SaldoRepository::fetchOpenInvoices, viz komentář u filtru.
     */
    /**
     * Rychlé hledání vystavených faktur podle čísla dokladu (varsymbol) pro globální
     * search box. Malý limit (dropdown).
     *
     * @return list<array{id:int, varsymbol:?string, invoice_type:string, status:string,
     *                    issue_date:?string, total_with_vat:float, currency:string, company_name:string}>
     */
    public function searchQuick(string $q, int $supplierId, int $limit = 6): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date,
                    i.total_with_vat, COALESCE(cur.code, 'CZK') AS currency,
                    c.company_name
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.varsymbol LIKE ?
              ORDER BY i.issue_date DESC, i.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$supplierId, '%' . $esc . '%']);
        return array_map(static fn (array $r) => [
            'id'             => (int) $r['id'],
            'varsymbol'      => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'invoice_type'   => (string) $r['invoice_type'],
            'status'         => (string) $r['status'],
            'issue_date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat' => (float) $r['total_with_vat'],
            'currency'       => (string) $r['currency'],
            'company_name'   => (string) $r['company_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Normalizace `filter[oss_review]` na rozsah {@see VatLedgerService::MANUAL_REVIEW_SCOPES}.
     *
     * `true` / `'1'` musí zůstat funkční: přesně tuhle hodnotu nesou uložené filtry
     * (SavedFilters ukládají query string) i odkazy vytvořené dřív, než filtr rozsah
     * uměl. Mapují se na `any`, tedy na ROZŠÍŘENÍ původního významu — to je celý smysl
     * opravy, uživateli se nemá nic schovat.
     */
    private static function ossReviewScope(mixed $raw): string
    {
        $scope = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($scope, VatLedgerService::MANUAL_REVIEW_SCOPES, true)
            ? $scope
            : VatLedgerService::MANUAL_REVIEW_SCOPE_ANY;
    }

    /**
     * Sentinel pro doklad BEZ kategorie tržby v `filter[revenue_category_id]`
     * i `filter[revenue_category_exclude]`. Číselné ID by to vyjádřit nešlo —
     * `revenue_category_id` je NULL a NULL se do IN/NOT IN nechytá.
     */
    public const REVENUE_CATEGORY_NONE = 'none';

    /**
     * Rozparsuje čárkou oddělený seznam kategorií tržby na vlastněná ID + sentinel `none`.
     *
     * Vrací `null`, když filtr nebyl zadaný (nebo v něm nebyl JEDINÝ použitelný token) —
     * to je no-op, ne prázdný výsledek. Naopak zadané, ale CIZÍ ID zmizí až tady, takže
     * volajícímu zbude prázdný seznam a on ho umí odlišit od nezadaného filtru; cizí
     * tenant tak nezjistí ani to, jestli ID existuje.
     *
     * @return array{ids: list<int>, none: bool}|null
     */
    private function revenueCategorySelection(mixed $raw, int $supplierId): ?array
    {
        if (is_array($raw)) {
            $tokens = $raw;
        } elseif (is_scalar($raw)) {
            $tokens = explode(',', (string) $raw);
        } else {
            return null;
        }

        $none = false;
        $ids = [];
        foreach ($tokens as $token) {
            if (!is_scalar($token)) continue;
            $token = trim((string) $token);
            if ($token === '') continue;
            if (strcasecmp($token, self::REVENUE_CATEGORY_NONE) === 0) {
                $none = true;
                continue;
            }
            if (ctype_digit($token) && (int) $token > 0) {
                $ids[] = (int) $token;
            }
        }
        if (!$none && $ids === []) return null;

        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            // Bez tenanta nelze vlastnictví ověřit → nic není vlastní (raději prázdno než únik).
            if ($supplierId <= 0) return ['ids' => [], 'none' => $none];
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM revenue_categories WHERE supplier_id = ? AND id IN ($place)"
            );
            $stmt->execute(array_merge([$supplierId], $ids));
            $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $ids = array_values(array_intersect($ids, $owned));
        }

        return ['ids' => $ids, 'none' => $none];
    }

    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'i.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $place = implode(',', array_fill(0, count($types), '?'));
            $where[] = "i.invoice_type IN ($place)";
            foreach ($types as $t) $params[] = $t;
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "i.status IN ($place)";
            foreach ($statuses as $s) $params[] = $s;
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[] = 'i.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        // Kategorie tržby — dvě nezávislé množiny: `revenue_category_id` ponechá JEN vybrané,
        // `revenue_category_exclude` vybrané skryje. Typický důvod pro exclude: drobné faktury
        // za předplatné mají vlastní kategorii a v seznamu jen překáží. Zadané obojí = include
        // vybere množinu a exclude ji zúží (obojí je AND ve WHERE).
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $catInclude = $this->revenueCategorySelection($filters['revenue_category_id'] ?? null, $supplierId);
        if ($catInclude !== null) {
            $parts = [];
            if ($catInclude['ids'] !== []) {
                $place = implode(',', array_fill(0, count($catInclude['ids']), '?'));
                $parts[] = "i.revenue_category_id IN ($place)";
                foreach ($catInclude['ids'] as $id) $params[] = $id;
            }
            if ($catInclude['none']) $parts[] = 'i.revenue_category_id IS NULL';
            // Zbyla-li po ověření vlastnictví prázdná množina (cizí / smazané ID), je správná
            // odpověď PRÁZDNO. Tiché „nefiltrovat" by ukázalo všechno a uživatel by věřil, že
            // vidí výběr.
            $where[] = $parts === [] ? '1=0' : '(' . implode(' OR ', $parts) . ')';
        }
        $catExclude = $this->revenueCategorySelection($filters['revenue_category_exclude'] ?? null, $supplierId);
        if ($catExclude !== null) {
            // NULL sémantika: `revenue_category_id NOT IN (…)` je u dokladu BEZ kategorie
            // UNKNOWN, takže by ho exclude vyhodil taky — a to nikdo nečeká. Doklady bez
            // kategorie proto zůstávají, dokud uživatel nevyloučí i sentinel `none`.
            if ($catExclude['ids'] !== []) {
                $place = implode(',', array_fill(0, count($catExclude['ids']), '?'));
                $where[] = $catExclude['none']
                    ? "(i.revenue_category_id IS NOT NULL AND i.revenue_category_id NOT IN ($place))"
                    : "(i.revenue_category_id IS NULL OR i.revenue_category_id NOT IN ($place))";
                foreach ($catExclude['ids'] as $id) $params[] = $id;
            } elseif ($catExclude['none']) {
                $where[] = 'i.revenue_category_id IS NOT NULL';
            }
            // Prázdné ids bez `none` = vyloučené kategorie tenantovi nepatří → nevylučuje se nic.
        }
        if (!empty($filters['year'])) {
            // Sargovatelný půlotevřený rozsah místo YEAR(...) — využije idx_inv_supplier_efftax.
            $y = (int) $filters['year'];
            $where[] = 'i.effective_tax_date >= ? AND i.effective_tax_date < ?';
            $params[] = sprintf('%04d-01-01', $y);
            $params[] = sprintf('%04d-01-01', $y + 1);
        }
        if (!empty($filters['month'])) {
            // MONTH() napříč roky nelze převést na souvislý rozsah — ponecháno na gen-col.
            $where[] = 'MONTH(i.effective_tax_date) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'i.effective_tax_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'i.effective_tax_date <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "i.status IN ('issued','sent','reminded')";
            // Pohledávky = vše kromě proforem + NEZAPLACENÉ NESPÁROVANÉ proformy
            // (zálohovky bez dceřiného ostrého dokladu) — ty jsou reálný dluh.
            // Dřív filtr proformy zcela vynechával (IN invoice,credit_note), takže
            // nezaplacené zálohové faktury se v "nezaplacené" vůbec neukázaly.
            // Spárovaná proforma se vynechá, dluh nese ostrý doklad. Zrcadlí dashboard
            // (receivableDocTypeSql) a InvoiceAmountPolicy.
            $where[] = "(i.invoice_type != 'proforma'"
                . " OR NOT EXISTS (SELECT 1 FROM invoices ch"
                . " WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))";
            // Finální daňový doklad k zaplacené proformě má amount_to_pay = 0 by design
            // (záloha pokryla celek) — není nezaplacený, jen status zůstal 'issued'.
            // Dobropisy (záporný total) ponecháváme. Částečné úhrady (#89): dlužná
            // částka = amount_to_pay - paid_total.
            $where[] = "(i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()";
            // Stejná pohledávková sémantika jako unpaid (vč. nespárovaných proforem).
            $where[] = "(i.invoice_type != 'proforma'"
                . " OR NOT EXISTS (SELECT 1 FROM invoices ch"
                . " WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))";
            $where[] = "(i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)";
        }
        // Neuhrazené K DATU X (task #4, účetní sestava „saldo bez saldokonta") — na rozdíl
        // od `unpaid_only`/`overdue` výše NEJDE o dnešní status/amount_to_pay, ale o stav,
        // jaký doklad měl k historickému dni. Zdroj pravdy o úhradě je STEJNÝ jako
        // v SaldoRepository::fetchOpenInvoices (audit 2026-07, H2): SUM invoice_payments.amount
        // s paid_on <= asOf proti amount_to_pay, stejnoměrně v měně faktury (invoice_payments
        // pokrývá bankovní, hotovostní i ruční platby jednotně — žádný zvláštní gap jako u PF).
        // Díky tomu doklad, který je DNES 'paid' ale platba přišla AŽ PO asOf, správně
        // vypadne jako neuhrazený k asOf (a naopak doklad splacený PŘED asOf zmizí, i když
        // dnešní status ještě 'issued' nestihl překlopit).
        if (!empty($filters['unpaid_as_of'])) {
            $asOf = (string) $filters['unpaid_as_of'];
            // Vystavený do X — koncept ještě není dluh, k X musí existovat jako doklad.
            $where[] = 'i.issue_date <= ?';
            $params[] = $asOf;
            $where[] = "i.status <> 'draft'";
            // Storno platí, jen když k X už proběhlo (H4b — cancelled_at jako den vyrovnání,
            // shodně se SaldoRepository).
            $where[] = "(i.status <> 'cancelled' OR i.cancelled_at IS NULL OR DATE(i.cancelled_at) > ?)";
            $params[] = $asOf;
            // Stejná pohledávková sémantika jako unpaid_only/overdue: spárovaná proforma dluh
            // nenese, dluh nese ostrý doklad.
            $where[] = "(i.invoice_type != 'proforma'"
                . " OR NOT EXISTS (SELECT 1 FROM invoices ch"
                . " WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))";
            // Guard „amount_to_pay > 0" (viz AGENTS.md) je tu implicitní: je-li amount_to_pay
            // už dnes 0 (finál kryt zálohou), rozdíl proti Σ plateb do asOf nikdy nepřekročí
            // toleranci a doklad se nevybere sám od sebe.
            $where[] = "(i.amount_to_pay - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip"
                . " WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id"
                . " AND ip.paid_on <= ?), 0)) > 0.005";
            $params[] = $asOf;
        }
        // Zaúčtováno / nezaúčtováno (podvojné účetnictví) — '1' = zaúčtováno (booked_at IS NOT NULL),
        // '0' = nezaúčtováno (IS NULL). V daňové evidenci je booked_at vždy NULL, filtr tam nemá smysl
        // (FE ho zobrazuje jen pro double_entry firmy).
        // Fronta „k zaúčtování" smí nabízet jen typy, které engine umí zaúčtovat — jinak slibuje akci,
        // která skončí chybou. Proforma není účetní doklad (účtuje se až inkaso zálohy 221/324 a finální
        // faktura), takže booked_at zůstane NULL navždy a bez tohoto filtru by ve frontě visela věčně.
        // Sdílená konstanta místo opisování seznamu — shodně s DocumentBackfill a PendingBackfillCounter.
        if (isset($filters['booked']) && $filters['booked'] !== null && $filters['booked'] !== '') {
            if ((string) $filters['booked'] === '1') {
                $where[] = 'i.booked_at IS NOT NULL';
            } else {
                $types = PostingService::POSTABLE_ISSUED_INVOICE_TYPES;
                $where[] = 'i.booked_at IS NULL AND i.invoice_type IN ('
                    . implode(',', array_fill(0, count($types), '?')) . ')';
                foreach ($types as $t) {
                    $params[] = $t;
                }
            }
        }
        // Nejisté místo plnění (OSS): řádek s příznakem k ručnímu posouzení. Bez tohohle
        // filtru je takový doklad NEDOHLEDATELNÝ — v seznamu vypadá jako každý jiný.
        //
        // Filtr pokrývá OBA konce, ne jen ten tuzemský: řádek v OSS s příznakem je vidět
        // jen ve varování náhledu podání a v reportu importu, který po zavření stránky
        // zmizí. Kdyby ho filtr nechytal, uživatel by si seznam vyčistil a v dobré víře
        // by mu zůstala druhá polovina sporných řádků. Který konec ho zajímá, říká
        // `oss_review` ('any' | 'oss' | 'domestic'); definici vlastní VatLedgerService,
        // ať se filtr a varování v přiznání nemůžou rozejít. `null` = schéma příznak
        // nezná, filtr je pak no-op (nefiltrovat je lepší než vrátit prázdno a tvrdit,
        // že nic takového není).
        if (!empty($filters['oss_review'])) {
            $flagged = VatLedgerService::manualReviewPredicate(
                $this->db,
                'ossii',
                self::ossReviewScope($filters['oss_review']),
            );
            if ($flagged !== null) {
                $where[] = 'EXISTS (SELECT 1 FROM invoice_items ossii'
                    . ' WHERE ossii.invoice_id = i.id AND ' . $flagged . ')';
            }
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            // Hledá i v TEXTU POLOŽEK faktury (EXISTS, ne JOIN — JOIN by fakturu znásobil na
            // počet položek a rozbil COUNT i stránkování). $whereSql je sdílený mezi count
            // i hlavním dotazem, takže stačí doplnit tady jednou.
            $where[] = '(i.varsymbol LIKE ? OR c.company_name LIKE ?'
                . ' OR EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id'
                . ' AND ii.description LIKE ?))';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = null;
        if ($perPage > 0) {
            $cntStmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM invoices i
                   JOIN clients c ON c.id = i.client_id
              LEFT JOIN projects p ON p.id = i.project_id
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE $whereSql"
            );
            $cntStmt->execute($params);
            $total = (int) $cntStmt->fetchColumn();
        }

        // Který konec nejistoty doklad nese. Bez toho by filtr „vše nejisté" vracel
        // nerozlišený seznam, ve kterém se dvě různé otázky („patří to sem vůbec?" vs.
        // „sedí země a typ sazby?") tváří stejně. Počítá se VŽDY, ne jen při zapnutém
        // filtru: doklad se sporným řádkem má být poznat i při běžném procházení.
        $ossReviewSelect = '';
        $flaggedDomestic = VatLedgerService::manualReviewPredicate(
            $this->db, 'ossii', VatLedgerService::MANUAL_REVIEW_SCOPE_DOMESTIC
        );
        $flaggedOss = VatLedgerService::manualReviewPredicate(
            $this->db, 'ossii', VatLedgerService::MANUAL_REVIEW_SCOPE_OSS
        );
        if ($flaggedDomestic !== null && $flaggedOss !== null) {
            $ossReviewSelect =
                ' EXISTS (SELECT 1 FROM invoice_items ossii WHERE ossii.invoice_id = i.id'
                . ' AND ' . $flaggedDomestic . ') AS oss_review_domestic,'
                . ' EXISTS (SELECT 1 FROM invoice_items ossii WHERE ossii.invoice_id = i.id'
                . ' AND ' . $flaggedOss . ') AS oss_review_oss,';
        }

        $sql = "SELECT $ossReviewSelect
                       i.id, i.varsymbol, i.invoice_type, i.parent_invoice_id, i.recurring_template_id,
                       i.client_id, i.project_id, i.supplier_id,
                       i.issue_date, i.tax_date, i.due_date,
                       i.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       i.total_without_vat, i.total_vat, i.total_with_vat,
                       i.advance_paid_amount, i.amount_to_pay, i.paid_total,
                       i.status, i.payment_method, i.revenue_category_id, i.exchange_rate,
                       i.sent_at, i.last_reminder_at, i.reminder_count,
                       i.paid_at, i.cancelled_at, i.booked_at,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       p.requires_work_report_approval AS project_requires_approval,
                       EXISTS (SELECT 1 FROM work_reports wr WHERE wr.invoice_id = i.id) AS has_work_report,
                       DATE_FORMAT(i.effective_tax_date, '%Y-%m') AS month_bucket
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE $whereSql
                 ORDER BY i.effective_tax_date DESC, i.id DESC";

        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= " LIMIT ? OFFSET ?";
        }

        // PDO nepodporuje míchání named (:foo) a positional (?) parametrů — vše positional.
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Grupování po měsíci
        $grouped = [];
        foreach ($rows as $row) {
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

            $cur = $row['currency'];
            if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                $grouped[$month]['totals_per_currency'][$cur] = [
                    'currency'        => $cur,
                    'without_vat'     => 0.0,
                    'vat'             => 0.0,
                    'with_vat'        => 0.0,
                    // Predikce: koncepty (draft) vystavených faktur/dobropisů – ještě nejsou
                    // obratem, ale ukazují, kolik je „rozpracováno" k vystavení v daném měsíci.
                    'draft_without_vat' => 0.0,
                    'draft_vat'         => 0.0,
                    'draft_with_vat'    => 0.0,
                ];
            }
            // Do obratu počítáme jen vystavené faktury + dobropisy + daňové doklady
            // k přijaté platbě (finál k záloze pak nese jen zbytek přes odpočtové řádky).
            // Vyloučeno: draft (koncepty), proforma (zálohovky), cancelled (storno), cancellation (interní storno).
            //
            // Dobropis obrat vždy SNIŽUJE (§ 4a ZDPH), proto se u něj znaménko vynucuje.
            // Dřív se jen spoléhalo na to, že má záporné částky — u dobropisu chybně
            // zadaného s kladným součtem (žádná negace; blokovaná je jen dvojí, viz
            // InvoiceAmountPolicy) by se obrat NAVÝŠIL. To je citlivé: obrat rozhoduje
            // o limitu registrace k DPH. Pro správně zadaný dobropis je to no-op.
            $sign = static fn (float $v): float => $v;
            if ($row['invoice_type'] === 'credit_note') {
                $sign = static fn (float $v): float => -abs($v);
            }
            if (in_array($row['status'], ['issued', 'sent', 'reminded', 'paid'], true)
                && in_array($row['invoice_type'], ['invoice', 'credit_note', 'tax_document'], true)) {
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += $sign((float) $row['total_without_vat']);
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += $sign((float) $row['total_vat']);
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += $sign((float) $row['total_with_vat']);
            } elseif ($row['status'] === 'draft'
                && in_array($row['invoice_type'], ['invoice', 'credit_note', 'tax_document'], true)) {
                // Koncepty do samostatné „predikce" (sčítají se k obratu až na FE pro predikovaný součet).
                $grouped[$month]['totals_per_currency'][$cur]['draft_without_vat'] += $sign((float) $row['total_without_vat']);
                $grouped[$month]['totals_per_currency'][$cur]['draft_vat']         += $sign((float) $row['total_vat']);
                $grouped[$month]['totals_per_currency'][$cur]['draft_with_vat']    += $sign((float) $row['total_with_vat']);
            }
        }

        // Round totals
        foreach ($grouped as &$m) {
            foreach ($m['totals_per_currency'] as &$t) {
                $t['without_vat']       = round($t['without_vat'], 2);
                $t['vat']               = round($t['vat'], 2);
                $t['with_vat']          = round($t['with_vat'], 2);
                $t['draft_without_vat'] = round($t['draft_without_vat'], 2);
                $t['draft_vat']         = round($t['draft_vat'], 2);
                $t['draft_with_vat']    = round($t['draft_with_vat'], 2);
            }
            $m['totals_per_currency'] = array_values($m['totals_per_currency']);
        }
        unset($m, $t);

        $meta = ['total' => $total ?? count($rows)];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return [
            'data' => array_values($grouped),
            'meta' => $meta,
        ];
    }

    public function createDraft(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        // Supplier_id se odvodí z client (immutable per client)
        $clientId = (int) $data['client_id'];
        $stmt = $pdo->prepare(
            'SELECT c.supplier_id, c.default_branding_profile_id, s.default_branding_profile_id AS supplier_branding_profile_id,
                    s.branding_profiles_enabled
               FROM clients c JOIN supplier s ON s.id = c.supplier_id WHERE c.id = ?'
        );
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client === false) {
            throw new \InvalidArgumentException("Client #$clientId nenalezen.");
        }
        $supplierId = (int) $client['supplier_id'];
        $parentInvoiceId = !empty($data['parent_invoice_id']) ? (int) $data['parent_invoice_id'] : null;
        if ($parentInvoiceId !== null) {
            $parent = $pdo->prepare(
                'SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ? LIMIT 1'
            );
            $parent->execute([$parentInvoiceId, $supplierId]);
            if ($parent->fetchColumn() === false) {
                throw new \InvalidArgumentException(
                    "Rodičovský doklad #$parentInvoiceId neexistuje nebo patří jiné firmě."
                );
            }
        }
        $brandingProfileId = empty($client['branding_profiles_enabled']) ? null : (array_key_exists('branding_profile_id', $data)
            ? $this->resolveBrandingProfileId($data['branding_profile_id'], $supplierId)
            : ($client['default_branding_profile_id'] !== null
                ? (int) $client['default_branding_profile_id']
                : ($client['supplier_branding_profile_id'] !== null ? (int) $client['supplier_branding_profile_id'] : null)));

        // Výchozí kategorie tržby — explicitní volba vyhrává, jinak default zakázky >
        // klienta (sdílený helper, viz resolveDefaultRevenueCategoryId). Stejnou logiku
        // používají i ostatní cesty zakládání vydané faktury (recurring, import).
        $projectId = isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null;
        $revenueCategoryId = (isset($data['revenue_category_id']) && $data['revenue_category_id'])
            ? (int) $data['revenue_category_id']
            : self::resolveDefaultRevenueCategoryId($pdo, $clientId, $projectId);

        // Volitelný ručně zadaný varsymbol (override automatického číslování při issue).
        // Trim + null-if-empty, max 20 znaků (DB sloupec varsymbol VARCHAR(20)).
        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol má max 20 znaků');
        }

        $paymentMethod = PaymentMethods::normalize($data['payment_method'] ?? null);

        $hasExempt = $this->supportsIncomeTaxExempt();
        $hasReminders = $this->supportsAutoSendReminders();
        $hasSimplified = $this->supportsSimplified();
        $sql = 'INSERT INTO invoices
            (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
             issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
             note_above_items, note_below_items, advance_paid_amount, discount_percent, varsymbol,
             payment_method, status, vat_classification_code, revenue_category, revenue_category_id,'
            . ($hasExempt ? ' income_tax_exempt, income_tax_exempt_reason,' : '')
            . ($hasReminders ? ' auto_send_reminders,' : '')
            . ($hasSimplified ? ' is_simplified,' : '')
            . ' created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?,'
            . ($hasExempt ? ' ?, ?,' : '')
            . ($hasReminders ? ' ?,' : '')
            . ($hasSimplified ? ' ?,' : '')
            . ' ?)';

        $params = [
            (string) ($data['invoice_type'] ?? 'invoice'),
            $parentInvoiceId,
            $clientId,
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            $supplierId,
            $brandingProfileId,
            (string) $data['issue_date'],
            ($data['invoice_type'] ?? 'invoice') === 'proforma' ? null : (string) ($data['tax_date'] ?? $data['issue_date']),
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            $manualVarsymbol,
            $paymentMethod,
            !empty($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            !empty($data['revenue_category']) ? (string) $data['revenue_category'] : null,
            $revenueCategoryId,
        ];
        if ($hasExempt) {
            $params[] = !empty($data['income_tax_exempt']) ? 1 : 0;
            $params[] = self::normalizeExemptReason($data['income_tax_exempt_reason'] ?? null);
        }
        if ($hasReminders) {
            $params[] = array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1;
        }
        if ($hasSimplified) {
            $params[] = !empty($data['is_simplified']) ? 1 : 0;
        }
        $params[] = $userId;

        $pdo->prepare($sql)->execute($params);

        return (int) $pdo->lastInsertId();
    }

    /**
     * POZN.: seznam editovatelných sloupců drž v sync s UpdateInvoiceAction::diffFields()
     * (audit „co se změnilo" v historii faktury). Přidáš-li sem user-facing sloupec,
     * přidej ho i tam, jinak ho audit detail tiše neuvede.
     */
    /**
     * @param bool $requireUnbooked Optimistický zámek (Epic F6, L1): UPDATE podmíněný
     *                              `booked_at IS NULL`. Vrací false, když doklad mezitím
     *                              někdo zaúčtoval (řádek existuje, ale booked_at je set).
     */
    public function updateDraft(int $id, array $data, bool $requireUnbooked = false): bool
    {
        // Varsymbol — měníme jen pokud je v payloadu klíč 'varsymbol' (allow null = vyčištění,
        // missing = nepsát vůbec). UpdateInvoiceAction klíč u vystavené faktury odstraní.
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

        $hasPaymentMethod = array_key_exists('payment_method', $data);
        $paymentMethod = null;
        if ($hasPaymentMethod) {
            $paymentMethod = PaymentMethods::normalize($data['payment_method']);
        }

        // Typ dokladu lze měnit jen u draftu (faktura/proforma/dobropis) — viz UpdateInvoiceAction,
        // který u vystavené faktury posílá nezměněný typ. Storno/cancellation se přes update nenastaví.
        $hasType = array_key_exists('invoice_type', $data)
            && in_array((string) $data['invoice_type'], ['invoice', 'proforma', 'credit_note', 'payment_calendar'], true);

        $hasExempt = $this->supportsIncomeTaxExempt();
        $hasReminders = $this->supportsAutoSendReminders();
        // Na rozdíl od `income_tax_exempt` se `is_simplified` zapisuje jen když klíč v
        // payloadu JE. Příznak nastavuje editor faktury, ale doklad ukládají i jiné cesty
        // (import, opakovaná fakturace) — ty by ho bez tohoto rozlišení tiše shodily na 0.
        $hasSimplified = $this->supportsSimplified() && array_key_exists('is_simplified', $data);
        $currentStmt = $this->db->pdo()->prepare('SELECT supplier_id, branding_profile_id FROM invoices WHERE id = ?');
        $currentStmt->execute([$id]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new \InvalidArgumentException("Invoice #$id nenalezena.");
        }
        $brandingProfileId = array_key_exists('branding_profile_id', $data)
            ? $this->resolveBrandingProfileId($data['branding_profile_id'], (int) $current['supplier_id'])
            : ($current['branding_profile_id'] !== null ? (int) $current['branding_profile_id'] : null);

        $sql = 'UPDATE invoices SET
                client_id = ?, project_id = ?, branding_profile_id = ?,
                issue_date = ?, tax_date = ?, due_date = ?,
                currency_id = ?, reverse_charge = ?, prices_include_vat = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?, discount_percent = ?,
                vat_classification_code = ?, revenue_category = ?, revenue_category_id = ?'
              . ($hasExempt ? ', income_tax_exempt = ?, income_tax_exempt_reason = ?' : '')
              . ($hasReminders ? ', auto_send_reminders = ?' : '')
              . ($hasSimplified ? ', is_simplified = ?' : '')
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ($hasPaymentMethod ? ', payment_method = ?' : '')
              . ($hasType ? ', invoice_type = ?' : '')
              . ' WHERE id = ?'
              . ($requireUnbooked ? ' AND booked_at IS NULL' : '');

        $params = [
            (int) $data['client_id'],
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            $brandingProfileId,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            !empty($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            !empty($data['revenue_category']) ? (string) $data['revenue_category'] : null,
            isset($data['revenue_category_id']) && $data['revenue_category_id'] ? (int) $data['revenue_category_id'] : null,
        ];
        if ($hasExempt) {
            $params[] = !empty($data['income_tax_exempt']) ? 1 : 0;
            $params[] = self::normalizeExemptReason($data['income_tax_exempt_reason'] ?? null);
        }
        if ($hasReminders) {
            $params[] = array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1;
        }
        if ($hasSimplified) $params[] = !empty($data['is_simplified']) ? 1 : 0;
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        if ($hasPaymentMethod) $params[] = $paymentMethod;
        if ($hasType) $params[] = (string) $data['invoice_type'];
        $params[] = $id;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        if ($requireUnbooked && $stmt->rowCount() === 0) {
            // rowCount 0 = buď booked_at podmínka nesedla, nebo identická data (MySQL
            // počítá changed rows). Rozliš dotazem — locked jen když booked_at není NULL.
            // Vědomě akceptovaný double-race: mezi UPDATE a tímto SELECTem může účetní
            // doklad zase odemknout → falešné 200 u no-op zápisu (ms okno, bez dopadu
            // na data — UPDATE nic nezměnil).
            $check = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE id = ? AND booked_at IS NULL');
            $check->execute([$id]);
            return $check->fetchColumn() !== false;
        }
        return true;
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE smaže invoice_items i work_reports
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    }

    /**
     * Přepíše položky faktury (smaže staré + insertne nové).
     *
     * Pokud má faktura header `discount_percent` > 0, po vložení uživatelských
     * položek se DOPOČÍTÁ záporná slevová položka (item_kind='discount') na každou
     * kombinaci sazba DPH + klasifikační kód — viz `materializeDiscountLines`.
     * Příchozí položky s item_kind='discount' se ignorují (sleva je vždy generovaná
     * z header pole, nikdy se neukládá z UI jako uživatelský řádek → žádné zdvojení).
     */
    /** Kladné id z payloadu, jinak NULL (0, '', null i nesmysly padnou na NULL). */
    private static function positiveIdOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Ověří vazby řádků na prodávané karty majetku (1177) — replaceItems je jediné místo,
     * kde vazba vzniká, takže je to jediné místo, kde ji jde uhlídat.
     *
     * Kontroluje čtyři věci:
     *   1. nejvýš JEDEN druh karty na řádek — DB CHECK to vynutit nemůže, oba sloupce mají
     *      FK ON DELETE SET NULL (MariaDB chyba 1901, narazilo se na to už v 1094),
     *   2. karta patří TÉŽE firmě jako faktura — id přitéká z API jako cizí vstup,
     *   3. karta je prodejná (v užívání) a není prodaná JINOU fakturou — jinak by automat
     *      prodal jednu věc dvakrát a soupis k inventarizaci by lhal,
     *   4. jedna karta nejvýš na jednom řádku dokladu.
     *
     * Vlastní faktura se z bodu 3 vyjímá: po vystavení je karta ve stavu 'sold'/'disposed'
     * právě tímhle dokladem a jeho re-uložení (force-edit, přepočet) nesmí spadnout.
     *
     * @param array<mixed> $items
     * @throws \InvalidArgumentException
     */
    private function assertItemAssetLinks(int $invoiceId, array $items): void
    {
        $smallIds = [];
        $longIds  = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $small = self::positiveIdOrNull($item['small_asset_id'] ?? null);
            $long  = self::positiveIdOrNull($item['asset_id'] ?? null);
            if ($small !== null && $long !== null) {
                throw new \InvalidArgumentException(
                    'Řádek nemůže prodávat drobný i dlouhodobý majetek zároveň — vyberte jednu kartu.'
                );
            }
            if ($small !== null) {
                if (isset($smallIds[$small])) {
                    throw new \InvalidArgumentException(
                        'Tatáž karta majetku je na faktuře na více řádcích — prodat ji lze jen jednou.'
                    );
                }
                $smallIds[$small] = true;
            }
            if ($long !== null) {
                if (isset($longIds[$long])) {
                    throw new \InvalidArgumentException(
                        'Tatáž karta majetku je na faktuře na více řádcích — prodat ji lze jen jednou.'
                    );
                }
                $longIds[$long] = true;
            }
        }
        if ($smallIds === [] && $longIds === []) {
            return;
        }

        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $supplierId = (int) ($stmt->fetchColumn() ?: 0);

        $this->assertCardsSellable('small_assets', array_keys($smallIds), $supplierId, $invoiceId, 'drobného');
        $this->assertCardsSellable('assets', array_keys($longIds), $supplierId, $invoiceId, 'dlouhodobého');
    }

    /**
     * @param list<int> $ids
     * @throws \InvalidArgumentException
     */
    private function assertCardsSellable(string $table, array $ids, int $supplierId, int $invoiceId, string $label): void
    {
        if ($ids === []) {
            return;
        }
        // Jména tabulek jsou literály z volajícího (ne user input), id jsou vázané parametry.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, name, status, sale_invoice_id FROM {$table}
              WHERE id IN ({$placeholders}) AND supplier_id = ?"
        );
        $stmt->execute([...$ids, $supplierId]);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['id']] = $row;
        }

        foreach ($ids as $id) {
            $card = $found[$id] ?? null;
            if ($card === null) {
                throw new \InvalidArgumentException(
                    'Karta ' . $label . ' majetku #' . $id . ' nebyla nalezena.'
                );
            }
            $soldBy = $card['sale_invoice_id'] !== null ? (int) $card['sale_invoice_id'] : null;
            if ($soldBy === $invoiceId) {
                continue;   // prodaná právě touhle fakturou — re-uložení dokladu je v pořádku
            }
            $name = (string) $card['name'];
            if ($soldBy !== null) {
                throw new \InvalidArgumentException(
                    'Majetek „' . $name . '" je už prodaný jinou fakturou (#' . $soldBy . ').'
                );
            }
            $status = (string) $card['status'];
            if ($status === 'disposed') {
                throw new \InvalidArgumentException('Majetek „' . $name . '" je vyřazený z evidence.');
            }
            if ($status !== 'in_use') {
                throw new \InvalidArgumentException(
                    'Majetek „' . $name . '" není v užívání (stav ' . $status . ') — prodat lze jen zařazený majetek.'
                );
            }
        }
    }

    public function replaceItems(int $invoiceId, array $items): void
    {
        $pdo = $this->db->pdo();

        // Vazby na karty majetku (1177) se ověřují PŘED smazáním starých řádků — chybná vazba
        // tak nechá fakturu netknutou, místo aby ji nechala bez položek.
        $this->assertItemAssetLinks($invoiceId, $items);

        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);

        $supportsOss = $this->supportsOssItemColumns();
        $supportsManualReview = $this->supportsOssManualReview();

        // Sloupce se skládají místo tří ručně psaných variant SQL: guard na příznak
        // „k ručnímu posouzení" je samostatný (viz supportsOssManualReview()), takže by
        // ručních variant přibývalo s každým dalším OSS sloupcem a počet otazníků by se
        // s nimi rozešel. Pořadí je zároveň pořadím hodnot z ossItemParams().
        $ossColumns = $supportsOss
            ? [
                'oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type',
                'oss_exchange_rate', 'oss_exchange_rate_date', 'oss_taxable_amount_return',
                'oss_vat_amount_return', 'oss_original_period',
            ]
            : [];
        if ($supportsManualReview) {
            $ossColumns[] = 'oss_needs_manual_review';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code,
                 stock_item_id, warehouse_id, small_asset_id, asset_id'
            . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?'
            . str_repeat(', ?', count($ossColumns))
            . ')'
        );

        $vatRates = $this->loadVatRates();

        // Reverse charge + země klienta + jazyk + sleva — z hlavičky.
        //   Klasifikační kód: CZ klient → '1'/'2'/'3' (tuzemsko podle sazby)
        //                     EU klient s 0% → '22' (služby), non-EU s 0% → '26' (vývoz)
        $metaStmt = $pdo->prepare(
            'SELECT i.reverse_charge, i.discount_percent, i.language, co.iso2,
                    COALESCE(i.tax_date, i.issue_date) AS doc_date
               FROM invoices i
               JOIN clients c    ON c.id  = i.client_id
               JOIN countries co ON co.id = c.country_id
              WHERE i.id = ?'
        );
        $metaStmt->execute([$invoiceId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['reverse_charge' => 0, 'discount_percent' => 0, 'language' => 'cs', 'iso2' => 'CZ', 'doc_date' => null];
        $reverseCharge = (bool) $meta['reverse_charge'];
        $countryIso = (string) ($meta['iso2'] ?? 'CZ');
        $discountPercent = self::clampDiscountPercent($meta['discount_percent'] ?? 0);
        $language = (string) ($meta['language'] ?? 'cs');
        // Základní sazba pro ROK DOKLADU z číselníku daňových konstant — určuje, kdy sazba
        // na řádku znamená „tuzemská základní" (ř. 1) a kdy sníženou (ř. 2). Shodně
        // s přijatou stranou ({@see PurchaseInvoiceRepository::replaceItems()}); natvrdo
        // 21 by po změně § 47 ZDPH tiše přeřadilo celý doklad na špatný řádek přiznání.
        $docYear = !empty($meta['doc_date']) ? (int) substr((string) $meta['doc_date'], 0, 4) : (int) date('Y');
        $standardRate = $this->taxConstants->vatRateStandard($docYear);

        // Slevu agregujeme po (vat_rate_id, vat_rate_snapshot, code) — báze = součet
        // round(qty*price, 2) jednotlivých řádků (stejné zaokrouhlení jako InvoiceMath).
        $discountGroups = [];
        $maxOrder = -1;

        foreach (array_values($items) as $i => $item) {
            // Systémové slevové řádky z UI ignorujeme — generují se z header pole níže.
            if (($item['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            // Auto-klasifikace pro DPH přiznání / KH — bez ní by faktura nedorazila
            // do výkazů (VatClassificationMapper SKIPNE řádky s code=NULL).
            //
            // U OSS řádku se ale TUZEMSKÝ default NEDOSAZUJE. Plnění se vykazuje v OSS
            // podání, ne v českém přiznání ani v KH, takže by kód byl mrtvá metadata —
            // a v okamžiku, kdy někdo `oss_applicable` zhasne, přestane řádek filtr
            // `VatLedgerService` držet a s dosazenou '1' by cizí daň dopadla na ř. 1.
            // Shodně s importem ({@see \MyInvoice\Service\Import\InvoiceImportService}),
            // který tuhle větev řeší stejně; ručně zadaný kód má přednost i tady.
            $explicitCode = ($item['vat_classification_code'] ?? null) !== null;
            $code = match (true) {
                $explicitCode                   => $item['vat_classification_code'],
                !empty($item['oss_applicable']) => null,
                default                         => self::defaultSaleClassificationCode(
                    $rate,
                    $reverseCharge,
                    $countryIso,
                    (string) ($item['unit'] ?? '') ?: null,
                    $standardRate,
                ),
            };
            $assetId = self::positiveIdOrNull($item['asset_id'] ?? null);
            // § 76/4 ZDPH: prodej DLOUHODOBÉHO majetku se vylučuje z výpočtu koeficientu —
            // číselník na to má varianty '1m'/'2m' (tatáž řádka přiznání i sekce KH jako
            // '1'/'2', liší se jen tímhle). Jen když kód NEPŘIŠEL z payloadu: ruční volba
            // účetní má přednost (a `??` sám o sobě ruční '1' od defaultního '1' nerozliší).
            // Drobný majetek se sem NEPOČÍTÁ — pořízením šel do spotřeby (501), dlouhodobým
            // majetkem nikdy nebyl, takže se z koeficientu nevylučuje.
            if (!$explicitCode && $assetId !== null && ($code === '1' || $code === '2')) {
                $code .= 'm';
            }
            $orderIndex = (int) ($item['order_index'] ?? $i);
            $params = [
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                $orderIndex,
                'standard',
                $code !== null ? (string) $code : null,
                // Vazba na skladovou kartu (Epic SKLAD, B5) — řídí auto-výdejku.
                isset($item['stock_item_id']) && (int) $item['stock_item_id'] > 0 ? (int) $item['stock_item_id'] : null,
                isset($item['warehouse_id']) && (int) $item['warehouse_id'] > 0 ? (int) $item['warehouse_id'] : null,
                // Vazba na prodávanou kartu majetku (1177) — řídí výnosový účet i automat prodeje.
                self::positiveIdOrNull($item['small_asset_id'] ?? null),
                $assetId,
            ];
            if ($supportsOss) {
                $params = array_merge($params, self::ossItemParams($item, $supportsManualReview));
            }
            $stmt->execute($params);

            $maxOrder = max($maxOrder, $orderIndex);
            if ($discountPercent > 0) {
                $base = round((float) ($item['quantity'] ?? 1) * (float) ($item['unit_price_without_vat'] ?? 0), 2);
                $oss = $supportsOss ? self::ossItemParams($item, $supportsManualReview) : [];
                $key = $vatRateId . '|' . ($code ?? '');
                if ($supportsOss) {
                    $ossKey = $oss;
                    $ossKey[6] = null;
                    $ossKey[7] = null;
                    $key .= '|' . implode('|', array_map(static fn ($v) => $v === null ? '' : (string) $v, $ossKey));
                }
                if (!isset($discountGroups[$key])) {
                    $discountGroups[$key] = [
                        'vat_rate_id' => $vatRateId,
                        'snapshot'    => $rate,
                        'code'        => $code,
                        'base'        => 0.0,
                    ];
                    if ($supportsOss) {
                        $discountGroups[$key]['oss'] = $oss;
                    }
                } elseif ($supportsOss) {
                    foreach ([6, 7] as $amountIndex) {
                        if ($oss[$amountIndex] !== null) {
                            $discountGroups[$key]['oss'][$amountIndex] =
                                (float) ($discountGroups[$key]['oss'][$amountIndex] ?? 0.0) + (float) $oss[$amountIndex];
                        }
                    }
                }
                $discountGroups[$key]['base'] += $base;
            }
        }

        if ($discountPercent > 0 && $discountGroups !== []) {
            $this->materializeDiscountLines(
                $stmt,
                $invoiceId,
                $discountPercent,
                $discountGroups,
                $maxOrder + 1,
                $language,
                $supportsOss,
            );
        }
    }

    /**
     * Vloží záporné slevové položky (item_kind='discount') — jednu na každou skupinu
     * (sazba DPH + klasifikační kód). unit_price = -round(báze * pct/100, 2), množství 1.
     * Díky tomu sleva sníží základ i DPH v dané sazbě a propíše se do všech DPH výkazů
     * (sumují invoice_items). Per-sazbu split = nutný pro správné DPH u smíšených sazeb.
     *
     * @param array<string, array{vat_rate_id:int, snapshot:float, code:?string, base:float}> $groups
     */
    private function materializeDiscountLines(
        \PDOStatement $stmt,
        int $invoiceId,
        float $discountPercent,
        array $groups,
        int $startOrder,
        string $language,
        bool $supportsOss,
    ): void {
        $label = self::discountLabel($discountPercent, $language);
        $order = $startOrder;
        foreach ($groups as $g) {
            $disc = round($g['base'] * $discountPercent / 100.0, 2);
            if ($disc == 0.0) {
                continue;
            }
            $params = [
                $invoiceId,
                $label,
                1.0,
                '',
                -$disc,
                $g['vat_rate_id'],
                $g['snapshot'],
                $order++,
                'discount',
                $g['code'] !== null ? (string) $g['code'] : null,
                // Slevový řádek nikdy nenese skladovou vazbu ani vazbu na kartu majetku
                // (sleva se generuje per sazba DPH, ne per položka — není ke které kartě).
                null,
                null,
                null,
                null,
            ];
            if ($supportsOss && isset($g['oss']) && is_array($g['oss'])) {
                $oss = $g['oss'];
                foreach ([6, 7] as $amountIndex) {
                    if ($oss[$amountIndex] !== null) {
                        $oss[$amountIndex] = -round((float) $oss[$amountIndex] * $discountPercent / 100.0, 2);
                    }
                }
                $params = array_merge($params, $oss);
            }
            $stmt->execute($params);
        }
    }

    /**
     * Hodnoty OSS sloupců v pořadí, ve kterém je skládá {@see replaceItems()}.
     *
     * `$withManualReview` přidá na KONEC příznak „k ručnímu posouzení" (migrace 1293).
     * Na konec schválně: indexy 6 a 7 (ruční základ a DPH do podání) se v seskupování
     * slevových řádků adresují číslem, takže vložení kamkoli jinam by slevu rozhodilo.
     * Slevový řádek příznak dědí po své skupině — je to táž nerozhodnutá dodávka,
     * jen s opačným znaménkem.
     *
     * @return list<mixed>
     */
    private static function ossItemParams(array $item, bool $withManualReview): array
    {
        $applicable = !empty($item['oss_applicable']) ? 1 : 0;

        // Příznak „k ručnímu posouzení" má DVA legitimní zdroje a oba se respektují:
        //
        //  1. `oss_needs_manual_review` z payloadu = kanál, který doklad zakládá, NEDOKÁZAL
        //     určit místo plnění. Platí NEZÁVISLE na `oss_applicable`, a to je podstatné:
        //     u cronu opakovaných faktur, iDokladu, Fakturoidu i AI extrakce je řádek
        //     s `oss_applicable = 0` a rozsvíceným příznakem JEDINÉ povolené „nevím"
        //     ({@see \MyInvoice\Service\Invoice\RecurringInvoiceGenerator::ossColumnsFor()} —
        //     odmítnutí nesmí zastavit cron, tak řádek zůstane mimo OSS, ale označený).
        //     Dřívější podmínka `$applicable === 1 && …` přesně tohle „nevím" při zápisu
        //     zahazovala, takže z nerozhodnutého řádku byl v datech řádek rozhodnutý —
        //     a hromadná editace, která ho má najít, o něm nevěděla.
        //
        //     Zhasnout příznak proto musí ten, kdo se ROZHODL: editor posílá
        //     `oss_needs_manual_review: false` sám, jakmile uživatel OSS na položce vypne
        //     (InvoiceEditor.vue, hlídá {@see \MyInvoice\Tests\Architecture\InvoiceEditorOssPayloadContractTest}),
        //     a hromadná akce má na totéž `clear_needs_review`. Repozitář si rozhodnutí
        //     nedomýšlí za ně.
        //  2. `oss_document_contradiction` = kontrola soudržnosti CELÉHO dokladu
        //     ({@see \MyInvoice\Service\Oss\OssDocumentCoherence}), která označuje i řádek
        //     TUZEMSKÝ — právě ten má člověk prověřit, a import ho tak značí od začátku.
        //     Nese ji vlastní klíč, protože ji nepočítá payload, ale server při KAŽDÉM
        //     uložení znovu: opravou sazby rozpor zmizí a příznak s ním.
        $review = $withManualReview
            ? [!empty($item['oss_needs_manual_review']) || !empty($item['oss_document_contradiction']) ? 1 : 0]
            : [];

        if ($applicable === 0) {
            return array_merge([0, null, null, null, null, null, null, null, null], $review);
        }

        $country = strtoupper(trim((string) ($item['oss_consumer_country'] ?? '')));
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $rateType = trim((string) ($item['oss_rate_type'] ?? '')) ?: null;
        $supplyType = (string) ($item['oss_supply_type'] ?? '');
        $supplyType = in_array($supplyType, ['goods', 'services'], true) ? $supplyType : null;
        $rate = isset($item['oss_exchange_rate']) && is_numeric($item['oss_exchange_rate'])
            ? (float) $item['oss_exchange_rate'] : null;
        $rateDate = self::dateOrNull($item['oss_exchange_rate_date'] ?? null);
        $taxable = isset($item['oss_taxable_amount_return']) && is_numeric($item['oss_taxable_amount_return'])
            ? (float) $item['oss_taxable_amount_return'] : null;
        $vat = isset($item['oss_vat_amount_return']) && is_numeric($item['oss_vat_amount_return'])
            ? (float) $item['oss_vat_amount_return'] : null;
        $period = strtoupper(trim((string) ($item['oss_original_period'] ?? '')));
        if ($period !== '' && (!preg_match('/^[0-9]{4}Q[1-4]$/', $period) || $period < '2021Q3')) {
            throw new \InvalidArgumentException('Původní OSS období musí mít formát RRRRQn a nesmí být před Q3 2021.');
        }

        return array_merge(
            [$applicable, $country, $rateType, $supplyType, $rate, $rateDate, $taxable, $vat, $period ?: null],
            $review,
        );
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /**
     * Lokalizovaný popis slevové položky, např. "Sleva 10 %" / "Discount 10 %".
     * Procenta bez zbytečných nul (10, 12.5).
     */
    public static function discountLabel(float $discountPercent, string $language = 'cs'): string
    {
        $pct = rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
        return ($language === 'en' ? 'Discount ' : 'Sleva ') . $pct . ' %';
    }

    /**
     * Ořeže slevu do rozsahu 0–100 % (2 desetinná místa).
     */
    public static function clampDiscountPercent(mixed $value): float
    {
        $v = is_numeric($value) ? (float) $value : 0.0;
        return round(max(0.0, min(100.0, $v)), 2);
    }

    /**
     * Důvod osvobození od daně z příjmů — trim, prázdné → null, max 190 znaků
     * (DB sloupec income_tax_exempt_reason VARCHAR(190)).
     */
    private static function normalizeExemptReason(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, 190);
    }

    /**
     * Default vat_classification_code podle sazby + RC + země klienta pro VYSTAVENÉ faktury.
     *
     * Mapování:
     *   CZ klient:
     *     21% → '1' (tuzemsko základní)
     *     12% → '2' (tuzemsko snížená)
     *     RC  → '25s' (§ 92a přenesená povinnost, ř. 25 + KH A.1)
     *     0%  → bez kódu (viz níž)
     *   EU klient (DE, SK, AT, …):
     *     0%  → '22' (poskytnutí služby do EU, B2B reverse charge — nejčastější CZ IT use case)
     *     21%/12% → tuzemsko sazby (B2C nebo CZ klient s EU adresou)
     *   Non-EU klient:
     *     0%  → '26' (vývoz zboží, ř. 22) nebo '26s' (služba mimo tuzemsko, ř. 26)
     *     jinak tuzemsko sazby
     *
     * Pro dodávky zboží do EU (kód '20') vs služby ('22') rozhoduje měrná jednotka
     * položky (`$unit`): fyzikální míra (kg/l/m…) → '20', časová (h/den…) → '22';
     * bez signálu ('ks'/neznámé) statistický default '22'. Sdílená logika s
     * VatClassificationDefaulter::classifyUnitsGoodsVsServices.
     *
     * `$standardRate` je základní sazba § 47 ZDPH pro ROK DOKLADU z číselníku daňových
     * konstant ({@see TaxConstantsRepository::vatRateStandard()}) a NEMÁ výchozí hodnotu
     * ZÁMĚRNĚ: dřívější default 21 znamenal, že volající, který kontext roku nepředal,
     * dostal tiše správnou odpověď jen do nejbližší změny sazby — a pak by přeřadil
     * plnění na špatný řádek přiznání, aniž by cokoli spadlo. Povinný parametr nutí
     * každé nové volání sáhnout do číselníku; hlídá to `SaleClassificationRateSourceTest`.
     */
    public static function defaultSaleClassificationCode(
        float $rate,
        bool $reverseCharge,
        ?string $clientCountryIso2,
        ?string $unit,
        float $standardRate,
    ): ?string {
        $r = (int) round($rate);
        $std = (int) round($standardRate);
        $iso = strtoupper((string) ($clientCountryIso2 ?? 'CZ'));
        // EU member states (ISO-2 kódy, bez CZ které je tuzemsko)
        $euCountries = [
            'AT','BE','BG','HR','CY','DK','EE','FI','FR','DE','GR','HU','IE','IT',
            'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        ];
        $isEu = in_array($iso, $euCountries, true);
        $isForeign = $iso !== 'CZ' && $iso !== '';

        // Zahraniční klient + nulová sazba → EU služby/zboží nebo plnění do 3. země.
        // Zboží od služby rozliší měrná jednotka položky (sdílená heuristika).
        if ($isForeign && $r === 0) {
            $isGoods = \MyInvoice\Service\Report\VatClassificationDefaulter::classifyUnitsGoodsVsServices(
                $unit !== null && $unit !== '' ? [$unit] : []
            ) === 'goods';
            // 3. země: vývoz ZBOŽÍ je '26' (§ 66, ř. 22 pln_vyvoz), ale SLUŽBA je plnění
            // s místem plnění mimo tuzemsko s nárokem na odpočet — '26s', ř. 26 (pln_ost).
            // Dřív dostalo '26' i poradenství pro US klienta, tedy vývoz zboží (migrace 1509).
            if (!$isEu) return $isGoods ? '26' : '26s';
            // EU: dodání zboží ('20', ř. 20) vs poskytnutí služby ('22', ř. 21).
            return $isGoods ? '20' : '22';
        }
        // Tuzemský odběratel v režimu přenesené povinnosti (§ 92a — stavební práce, odpad,
        // zlato) → ř. 25 (pln_rez_pren) + věta KH A.1. Doklad se vystavuje bez daně, takže
        // dřív propadl na '3' (osvobozeno, ř. 50) a plnění z KH úplně zmizelo. Hlavička
        // přitom dostávala od VatClassificationDefaulter správné '25s' — dva zdroje pravdy
        // proti sobě a ve výkazech vyhrává řádek.
        if (!$isForeign && $reverseCharge && $r === 0) return '25s';
        // Tuzemsko / B2C cizinec s českou DPH sazbou
        if ($r >= $std)          return '1';
        if ($r >= 5 && $r <= 15) return '2';
        // Nulová sazba u tuzemského odběratele NENÍ automaticky osvobozené plnění § 51.
        // Stejně často je to přeúčtování nákladů, náhrada škody, smluvní pokuta nebo jiné
        // plnění mimo předmět daně — a tvrdé '3' takové řádky posílalo na ř. 50, kde
        // nafukují jmenovatel koeficientu § 76 a snižují krácený odpočet. Osvobozené
        // plnění si uživatel označí sám; neklasifikovaný nulový řádek pojmenuje varování
        // v přiznání („nebyl zahrnut na ř. 50"), které bylo do teď mrtvý kód, protože
        // '3' se dosadilo vždy. Symetrické s přijatou stranou (issue #30).
        //
        // Pásmo 16 % až pod základní sazbu (historická CZ 20 %, cizí sazby mimo OSS) se
        // taky nemapuje — česká klasifikace pro ně neexistuje, viz tatáž mezera
        // v PurchaseInvoiceRepository::defaultClassificationCode().
        return null;
    }

    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * @return array<int, float>
     */
    public function vatRateMap(): array
    {
        return $this->loadVatRates();
    }

    /** @return array<int, string> */
    public function vatRateCountryMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, country FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = strtoupper((string) ($row['country'] ?? 'CZ'));
        }
        return $out;
    }

    /**
     * Chronologicky agregované úhrady vydané faktury pro výpočet úroku z prodlení.
     * Částky jsou v měně faktury (InvoicePaymentService je tak ukládá).
     *
     * @return list<array{paid_on:string, amount:float}>
     */
    public function paymentTimeline(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT paid_on, SUM(amount) AS amount
               FROM invoice_payments
              WHERE invoice_id = ? AND supplier_id = ?
              GROUP BY paid_on
              ORDER BY paid_on'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        return array_map(static fn (array $row) => [
            'paid_on' => (string) $row['paid_on'],
            'amount'  => (float) $row['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Poslední den prodlení pokrytý dřívější (nestornovanou) penalizační fakturou
     * k dané zdrojové faktuře — ochrana proti dvojímu vyúčtování téhož období
     * (PenaltyInvoiceService). NULL = žádná dřívější penalizace.
     */
    public function lastPenaltyCoveredThrough(int $parentInvoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(penalty_covered_through) FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'penalty' AND status != 'cancelled'"
        );
        $stmt->execute([$parentInvoiceId]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? null : (string) $value;
    }

    /** Uloží den, do kterého (včetně) penalizační faktura pokrývá prodlení. */
    public function setPenaltyCoveredThrough(int $invoiceId, string $coveredThrough): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET penalty_covered_through = ? WHERE id = ?'
        )->execute([$coveredThrough, $invoiceId]);
    }

    /**
     * Je odběratel zahraniční z EU? Pro country-aware klasifikaci RC:
     * tuzemský odběratel + reverse_charge = §92a (ř.25), zahraniční EU = dodání do JČS (ř.20).
     */
    public function clientIsEuForeign(int $clientId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(co.is_eu, 0) AS is_eu, COALESCE(co.iso2, 'CZ') AS iso2
               FROM clients c LEFT JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$clientId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) return false;
        return ((int) $r['is_eu'] === 1) && ((string) $r['iso2'] !== 'CZ');
    }

    private function castInvoice(array $row): array
    {
        $row['id']                  = (int) $row['id'];
        $row['client_id']           = (int) $row['client_id'];
        $row['project_id']          = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['parent_invoice_id']   = isset($row['parent_invoice_id']) && $row['parent_invoice_id'] !== null ? (int) $row['parent_invoice_id'] : null;
        if (array_key_exists('recurring_template_id', $row)) {
            $row['recurring_template_id'] = $row['recurring_template_id'] !== null ? (int) $row['recurring_template_id'] : null;
        }
        if (isset($row['currency_id']))   $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['supplier_id']))   $row['supplier_id'] = (int) $row['supplier_id'];
        $row['reverse_charge']      = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        // Vždy klíč vrátit, i na instalaci bez migrace 1170 — editor by jinak checkbox
        // po načtení dokladu tiše zrušil (undefined → false → uložení jako běžný doklad).
        $row['is_simplified']       = isset($row['is_simplified']) ? (bool) $row['is_simplified'] : false;
        $row['prices_include_vat']  = isset($row['prices_include_vat']) ? (bool) $row['prices_include_vat'] : false;
        if (array_key_exists('income_tax_exempt', $row)) {
            $row['income_tax_exempt'] = (bool) $row['income_tax_exempt'];
        }
        if (array_key_exists('auto_send_reminders', $row)) {
            $row['auto_send_reminders'] = (bool) $row['auto_send_reminders'];
        }
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding', 'advance_paid_amount', 'amount_to_pay', 'paid_total', 'discount_percent'] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        // Odvozený platební stav (#89) — unpaid/partially_paid/paid/overpaid; NULL pro draft/cancelled.
        if (array_key_exists('paid_total', $row) && array_key_exists('amount_to_pay', $row) && array_key_exists('status', $row)) {
            $row['payment_status'] = \MyInvoice\Service\Invoice\InvoicePaymentService::paymentStatus($row);
        }
        if (array_key_exists('exchange_rate', $row)) {
            $row['exchange_rate'] = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;
        }
        if (isset($row['client_reverse_charge'])) $row['client_reverse_charge'] = (bool) $row['client_reverse_charge'];
        if (array_key_exists('reminder_count', $row)) $row['reminder_count'] = (int) $row['reminder_count'];
        if (array_key_exists('approval_reminder_count', $row)) {
            $row['approval_reminder_count'] = (int) $row['approval_reminder_count'];
        }
        if (array_key_exists('project_requires_approval', $row)) {
            $row['project_requires_approval'] = $row['project_requires_approval'] !== null
                ? (bool) $row['project_requires_approval']
                : false;
        }
        if (array_key_exists('has_work_report', $row)) {
            $row['has_work_report'] = (bool) $row['has_work_report'];
        }
        foreach (['oss_review_domestic', 'oss_review_oss'] as $ossReviewFlag) {
            if (array_key_exists($ossReviewFlag, $row)) {
                $row[$ossReviewFlag] = (bool) $row[$ossReviewFlag];
            }
        }
        if (array_key_exists('revenue_category_id', $row)) {
            $row['revenue_category_id'] = $row['revenue_category_id'] !== null ? (int) $row['revenue_category_id'] : null;
        }
        if (array_key_exists('cash_register_id', $row)) {
            $row['cash_register_id'] = $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null;
        }
        if (array_key_exists('branding_profile_id', $row)) {
            $row['branding_profile_id'] = $row['branding_profile_id'] !== null ? (int) $row['branding_profile_id'] : null;
        }
        return $row;
    }

    private function resolveBrandingProfileId(mixed $value, int $supplierId): ?int
    {
        if ($value === null || $value === '') {
            $stmt = $this->db->pdo()->prepare('SELECT CASE WHEN branding_profiles_enabled = 1 THEN default_branding_profile_id ELSE NULL END FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $default = $stmt->fetchColumn();
            return $default !== false && $default !== null ? (int) $default : null;
        }
        $id = (int) $value;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bp.id FROM branding_profiles bp
               JOIN supplier s ON s.id = bp.supplier_id AND s.branding_profiles_enabled = 1
              WHERE bp.id = ? AND bp.supplier_id = ? AND bp.is_active = 1'
        );
        $stmt->execute([$id, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException("Brandingový profil #$id nenalezen.");
        }
        return $id;
    }

    /**
     * Sdílené řešení výchozí kategorie tržby pro NOVOU vydanou fakturu.
     * PŘEDNOST: výchozí kategorie zakázky (project) > výchozí kategorie klienta > NULL.
     *
     * Společný choke-point pro všechny cesty, které zakládají vydanou fakturu vlastním
     * INSERTem mimo createDraft (RecurringInvoiceGenerator, InvoiceImportService) —
     * aby se default aplikoval konzistentně. Volá se s explicitním PDO, takže nevyžaduje
     * DI repozitáře v těchto službách.
     */
    public static function resolveDefaultRevenueCategoryId(PDO $pdo, int $clientId, ?int $projectId): ?int
    {
        if ($projectId !== null) {
            $ps = $pdo->prepare('SELECT default_revenue_category_id FROM projects WHERE id = ?');
            $ps->execute([$projectId]);
            $pcat = $ps->fetchColumn();
            if ($pcat !== false && $pcat !== null) {
                return (int) $pcat;
            }
        }
        $cs = $pdo->prepare('SELECT default_revenue_category_id FROM clients WHERE id = ?');
        $cs->execute([$clientId]);
        $ccat = $cs->fetchColumn();
        return ($ccat !== false && $ccat !== null) ? (int) $ccat : null;
    }

    /**
     * Vygeneruje a uloží nový approval_token, nastaví status='requested',
     * vyresetuje předchozí decision/reminder pole. TTL je v dnech (cfg.approval.token_ttl_days).
     * Vrací nový token.
     */
    public function setApprovalRequested(int $invoiceId, int $ttlDays = 30): string
    {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $expiresExpr = 'DATE_ADD(NOW(), INTERVAL ' . max(1, $ttlDays) . ' DAY)';
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET approval_status = 'requested',
                    approval_token = ?,
                    approval_token_expires_at = $expiresExpr,
                    approval_requested_at = NOW(),
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL,
                    approval_reminder_at = NULL,
                    approval_reminder_count = 0
              WHERE id = ?"
        )->execute([$token, $invoiceId]);
        return $token;
    }

    /**
     * Uloží rozhodnutí (approved/rejected). $decidedBy = email klienta (z public formu)
     * nebo email aktuálního uživatele (z admin „Změnit stav"). Token zneplatněn.
     *
     * Token se zároveň překlopí do `approval_receipt_hash`, aby si držitel odkazu
     * mohl otevřít read-only stvrzenku (viz migrace 1185). Z hashe se token
     * nesestaví, takže rozhodnout znovu nejde.
     */
    public function setApprovalDecision(int $invoiceId, string $newStatus, ?string $decidedBy, ?string $rejectionReason): void
    {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Invalid approval status: $newStatus");
        }
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = ?,
                    approval_receipt_hash = CASE
                        WHEN approval_token IS NULL THEN approval_receipt_hash
                        ELSE SHA2(approval_token, 256)
                    END,
                    approval_token = NULL,
                    approval_decided_at = NOW(),
                    approval_decided_by_email = ?,
                    approval_rejection_reason = ?
              WHERE id = ?'
        )->execute([$newStatus, $decidedBy, $rejectionReason, $invoiceId]);
    }

    /**
     * Atomické rozhodnutí pro VEŘEJNÝ schvalovací tok (bez auth). Na rozdíl od
     * `setApprovalDecision` je UPDATE podmíněný `approval_token = ? AND
     * approval_status = 'requested'`, takže dva souběžné `decide` se stejným
     * tokenem serializuje DB — vyhraje právě jeden (rowCount === 1), druhý dostane
     * rowCount 0 a NESMÍ pokračovat (jinak by se faktura vystavila/poslala 2×).
     *
     * @return bool true pokud tento request rozhodnutí skutečně zapsal (vyhrál závod)
     */
    public function decideIfRequested(int $invoiceId, string $token, string $newStatus, ?string $decidedBy, ?string $rejectionReason): bool
    {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Invalid approval status: $newStatus");
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE invoices
                SET approval_status = ?,
                    approval_receipt_hash = SHA2(approval_token, 256),
                    approval_token = NULL,
                    approval_decided_at = NOW(),
                    approval_decided_by_email = ?,
                    approval_rejection_reason = ?
              WHERE id = ?
                AND approval_token = ?
                AND approval_status = 'requested'"
        );
        $stmt->execute([$newStatus, $decidedBy, $rejectionReason, $invoiceId, $token]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Reset approval na 'none' (pro admin „Změnit stav" → none). Token zneplatněn.
     */
    public function resetApproval(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = "none",
                    approval_token = NULL,
                    approval_receipt_hash = NULL,
                    approval_token_expires_at = NULL,
                    approval_requested_at = NULL,
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL,
                    approval_reminder_at = NULL,
                    approval_reminder_count = 0
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    /**
     * Najde fakturu podle approval_token. Pokud token expiroval (token_expires_at < NOW()),
     * vrátí null — pro caller je to stejný case jako neexistující token.
     */
    public function findByApprovalToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices
              WHERE approval_token = ?
                AND (approval_token_expires_at IS NULL OR approval_token_expires_at > NOW())'
        );
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id === false) return null;
        return $this->find((int) $id);
    }

    /**
     * Najde fakturu podle už ZKONZUMOVANÉHO approval tokenu (viz migrace 1185).
     *
     * Slouží jen ke stvrzence — rozhodnout se přes tuhle cestu nedá, protože
     * `decideIfRequested` hledá podle `approval_token`, který je po rozhodnutí
     * NULL. Expirace se tu záměrně neuplatňuje: rozhodnutí je konečné a shrnutí
     * dává smysl i po vypršení původní lhůty na odpověď.
     *
     * Rozhodnutí starší než migrace hash nemají — u nich se vrátí null a odkaz
     * dopadne jako dosud.
     */
    public function findByApprovalReceipt(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices
              WHERE approval_receipt_hash IS NOT NULL
                AND approval_receipt_hash = SHA2(?, 256)'
        );
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id === false) return null;
        return $this->find((int) $id);
    }

    /**
     * Vrátí public_token faktury (web faktura); pokud ještě neexistuje, vygeneruje
     * ho — bin2hex(random_bytes(24)) → 48 hex znaků (vzor approval_token).
     * UPDATE podmíněný `public_token IS NULL` serializuje souběžné ensure — vyhraje
     * první zápis, druhý si token přečte znovu.
     */
    public function ensurePublicToken(int $invoiceId): string
    {
        $pdo = $this->db->pdo();
        $sel = $pdo->prepare('SELECT public_token FROM invoices WHERE id = ?');
        $sel->execute([$invoiceId]);
        $existing = $sel->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(24));
        $upd = $pdo->prepare('UPDATE invoices SET public_token = ? WHERE id = ? AND public_token IS NULL');
        $upd->execute([$token, $invoiceId]);
        if ($upd->rowCount() === 1) {
            return $token;
        }
        $sel->execute([$invoiceId]);
        return (string) $sel->fetchColumn();
    }

    /**
     * Zneplatní stávající veřejný odkaz vygenerováním nového tokenu (stará URL
     * přestane platit). public_viewed_at se resetuje — vztahuje se k aktuálnímu odkazu.
     */
    public function regeneratePublicToken(int $invoiceId): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->pdo()->prepare(
            'UPDATE invoices SET public_token = ?, public_viewed_at = NULL WHERE id = ?'
        )->execute([$token, $invoiceId]);
        return $token;
    }

    /**
     * Lehká reference veřejně viditelné faktury podle public_token, nebo null.
     * Pravidlo viditelnosti (draft se nezobrazuje) je přímo v SQL, aby ho
     * konzument nemohl zapomenout (vzor findByApprovalToken s expirací).
     *
     * @return array{id:int, supplier_id:int}|null
     */
    public function publicInvoiceRefByToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id FROM invoices WHERE public_token = ? AND status <> 'draft'"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'supplier_id' => (int) $row['supplier_id']];
    }

    /** Najde veřejně viditelnou fakturu podle public_token (web faktura), nebo null. */
    public function findByPublicToken(string $token): ?array
    {
        $ref = $this->publicInvoiceRefByToken($token);
        return $ref === null ? null : $this->find($ref['id']);
    }

    /** Zaznamená zobrazení web faktury klientem (poslední anonymní přístup). */
    public function markPublicViewed(int $invoiceId): void
    {
        $this->db->pdo()->prepare('UPDATE invoices SET public_viewed_at = NOW() WHERE id = ?')
            ->execute([$invoiceId]);
    }

    /**
     * Pro admin „Approval inbox" + reminder cron. Vrací requested faktury filtrované
     * podle dní od poslední upomínky/žádosti.
     *
     * @param int|null $supplierId  null = všichni dodavatelé (cron)
     * @param int|null $minDaysSince  minimum dní od poslední aktivity (NULL = bez filtru)
     * @param int|null $maxReminders  filtr: vyber jen ty s reminder_count < limit
     * @return list<array<string,mixed>>
     */
    public function listForApprovalInbox(
        ?int $supplierId = null,
        ?string $statusFilter = null,
        ?int $minDaysSince = null,
        ?int $maxReminders = null,
        int $page = 1,
        int $perPage = 0,
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($supplierId !== null) {
            $where[] = 'i.supplier_id = ?';
            $params[] = $supplierId;
        }

        // Status counts pro tab badges — bez status filtru, ale se supplier scope
        // (+ minDaysSince/maxReminders pokud explicit, takže badge sedí s aplikovanými filtry).
        if ($perPage > 0) {
            $whereForCounts = $where;
            $paramsForCounts = $params;
            $whereForCounts[] = "i.approval_status != 'none'";
            if ($minDaysSince !== null) {
                $whereForCounts[] = 'COALESCE(i.approval_reminder_at, i.approval_requested_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)';
                $paramsForCounts[] = $minDaysSince;
            }
            if ($maxReminders !== null) {
                $whereForCounts[] = 'i.approval_reminder_count < ?';
                $paramsForCounts[] = $maxReminders;
            }
            $whereCountsSql = implode(' AND ', $whereForCounts);
            $stmtCounts = $this->db->pdo()->prepare(
                "SELECT
                    SUM(CASE WHEN i.approval_status = 'requested' THEN 1 ELSE 0 END) AS requested,
                    SUM(CASE WHEN i.approval_status = 'approved'  THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN i.approval_status = 'rejected'  THEN 1 ELSE 0 END) AS rejected,
                    COUNT(*) AS all_count
                 FROM invoices i
                WHERE $whereCountsSql"
            );
            $stmtCounts->execute($paramsForCounts);
            $statusCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['requested' => 0, 'approved' => 0, 'rejected' => 0, 'all_count' => 0];
        }

        if ($statusFilter !== null) {
            $where[] = 'i.approval_status = ?';
            $params[] = $statusFilter;
        } else {
            // default = jen non-none (vše co prošlo schvalovacím flow)
            $where[] = "i.approval_status != 'none'";
        }
        if ($minDaysSince !== null) {
            $where[] = 'COALESCE(i.approval_reminder_at, i.approval_requested_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $minDaysSince;
        }
        if ($maxReminders !== null) {
            $where[] = 'i.approval_reminder_count < ?';
            $params[] = $maxReminders;
        }

        $whereSql = implode(' AND ', $where);
        $limitSql = $perPage > 0 ? ' LIMIT ? OFFSET ?' : '';
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.supplier_id,
                       i.client_id, i.project_id, i.currency_id, i.language,
                       i.total_with_vat, i.amount_to_pay,
                       i.approval_status, i.approval_token, i.approval_token_expires_at,
                       i.approval_requested_at, i.approval_decided_at,
                       i.approval_decided_by_email, i.approval_rejection_reason,
                       i.approval_reminder_at, i.approval_reminder_count,
                       c.company_name AS client_company_name, c.main_email AS client_main_email,
                       p.name AS project_name,
                       cur.code AS currency
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE $whereSql
                 ORDER BY i.approval_requested_at DESC{$limitSql}";

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(fn (array $r) => $this->castInvoice($r), $rows);

        // BC: bez paginace (perPage=0) cron volá a očekává plochý seznam.
        if ($perPage <= 0) {
            return $items;
        }

        // Total pro aktuální filter (jen v paginated cestě)
        $stmtTotal = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoices i WHERE $whereSql"
        );
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        return [
            'data' => $items,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / max(1, $perPage)),
                'status_counts' => [
                    'all'       => (int) ($statusCounts['all_count'] ?? 0),
                    'requested' => (int) ($statusCounts['requested'] ?? 0),
                    'approved'  => (int) ($statusCounts['approved'] ?? 0),
                    'rejected'  => (int) ($statusCounts['rejected'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Označ že upomínka byla poslána (cron-send-approval-reminders.php).
     */
    public function markApprovalReminderSent(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_reminder_at = NOW(),
                    approval_reminder_count = approval_reminder_count + 1
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['invoice_id']             = (int) $row['invoice_id'];
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        $row['vat_rate_snapshot']      = (float) $row['vat_rate_snapshot'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        $row['linked_work_report_id'] = $row['linked_work_report_id'] !== null ? (int) $row['linked_work_report_id'] : null;
        $row['item_kind'] = (string) ($row['item_kind'] ?? 'standard');
        if (array_key_exists('stock_item_id', $row)) {
            $row['stock_item_id'] = $row['stock_item_id'] !== null ? (int) $row['stock_item_id'] : null;
        }
        if (array_key_exists('warehouse_id', $row)) {
            $row['warehouse_id'] = $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null;
        }
        // Vazba řádku na prodávanou kartu majetku (1177) — řídí výnosový účet i automat prodeje.
        foreach (['small_asset_id', 'asset_id'] as $assetField) {
            if (array_key_exists($assetField, $row)) {
                $row[$assetField] = $row[$assetField] !== null ? (int) $row[$assetField] : null;
            }
        }
        if (array_key_exists('oss_applicable', $row)) {
            $row['oss_applicable'] = (bool) $row['oss_applicable'];
            foreach (['oss_exchange_rate', 'oss_taxable_amount_return', 'oss_vat_amount_return'] as $field) {
                $row[$field] = $row[$field] !== null ? (float) $row[$field] : null;
            }
        }
        // Vlastní klíč (migrace 1293 přišla o řadu verzí později než zbytek OSS sloupců),
        // takže se testuje samostatně, ne pod oss_applicable.
        if (array_key_exists('oss_needs_manual_review', $row)) {
            $row['oss_needs_manual_review'] = (bool) $row['oss_needs_manual_review'];
        }
        return $row;
    }

    private function buildVatBreakdown(array $items): array
    {
        $bd = [];
        foreach ($items as $item) {
            $rate = (float) $item['vat_rate_snapshot'];
            $key = number_format($rate, 2, '.', '');
            if (!isset($bd[$key])) {
                $bd[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $bd[$key]['base'] += (float) $item['total_without_vat'];
            $bd[$key]['vat']  += (float) $item['total_vat'];
        }
        $out = [];
        foreach ($bd as $b) {
            $out[] = [
                'rate' => $b['rate'],
                'base' => round($b['base'], 2),
                'vat'  => round($b['vat'], 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $out;
    }
}
