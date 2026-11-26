<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\DocumentLock;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Service\Import\ImportedPaidPurchase;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus;
use MyInvoice\Support\Sql\LinkedManualSettlementSql;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Oprava dokladů ve stavu „uhrazeno", které evidované úhrady nepokrývají.
 *
 * Uhrazený je doklad jen tehdy, když úhradu nese evidence: platby vydaného dokladu
 * (`invoice_payments`: banka, pokladna, zápočty, dobropisy, chráněné úhrady převzaté
 * z jiného programu), u přijatého {@see PurchaseSettledExpr::settled()}. Import souborů
 * dřív označil vydané doklady s prošlou splatností jako uhrazené bez jediné platby a převody
 * přijatých dokladů přebíraly stav ze zdroje bez krytí. Takový doklad zmizí z pohledávek
 * a závazků, upomínek, stárnutí i ze zápočtů, přestože ho nikdo nezaplatil.
 *
 * Doklad se vrátí na neuhrazený stejně jako „Vrátit na neuhrazený" v aplikaci, jen bez
 * mazání plateb: skutečné částečné úhrady zůstávají a `paid_total` se přepočte z nich, pak
 * {@see InvoiceUnmarkPaid}. Vydaný doklad navíc dostane vypnuté automatické upomínky
 * (`auto_send_reminders = 0`): staré doklady vrácené hromadně by jinak cron upomínek obeslal
 * všechny naráz. Webhook o vrácení úhrady se při opravě nevydává (integrace by dostaly stovky
 * událostí o dokladech, které nikdo nezaplatil), výpis ho jen počítá. Přijatý doklad se vrátí
 * na `booked` (zaúčtovaný), jinak na `received`, jako přechod z „uhrazeno" v detailu dokladu.
 *
 * Co oprava mění mimo stav: deník ani evidenci DPH ne (stav úhrady do nich nevstupuje).
 * Peněžní deník daňové evidence ano: přijatý doklad uhrazený bez úhrady je v něm výdajem
 * ke dni úhrady, vrácený už ne. Přijaté doklady firmy v daňové evidenci se proto vracejí jen
 * s `include_tax_evidence`. Vydaný doklad je v peněžním deníku jen svými platbami, vrácení
 * bez platby ho nemění.
 *
 * Přijatý doklad, který jako uhrazený převzal import z Fakturoidu či iDokladu, se nevrací,
 * ale doplní se mu krytí úhrady ze zdroje ({@see \MyInvoice\Service\Import\ImportedPaidPurchase}).
 * Přijatý doklad převedený z účetního programu se jen ohlásí: krytí doplní Kontrola dat po
 * převodu (`purchase_settled_uncovered`) podle pravidel zdroje.
 *
 * Mimo opravu zůstávají doklady, u kterých nedoplatek nic neznamená: částka k úhradě
 * nulová nebo záporná (krytá zálohou nebo dobropisem), haléřový zbytek do tolerance
 * párování, splátkový kalendář (stav nese součet splátek), DDKP přijatý (§ 28 ZDPH).
 *
 * Kategorie nálezů (každý doklad má jednu, v pořadí priority):
 *  - `unbacked_paid_total`: vydaný doklad nese uhrazenou částku (`paid_total`) vyšší než
 *    evidované platby (úhrada převzatá z jiného programu jen v `paid_total`). Nevrací se
 *    nikdy: přepočet by uhrazenou část smazal. Evidenci úhrady doplní oprava převodu
 *    (`repair-issued-remainders.php`, `repair-pohoda-prior-year-payments.php`,
 *    Kontrola dat po převodu),
 *  - `other_item`: vydaný doklad uhrazený úhradami ostatní pohledávky, řeší se tam,
 *  - `linked_unrecorded`: spárovaný bankovní pohyb nebo pokladní doklad bez záznamu platby,
 *    patří do párování, ne do opravy stavu,
 *  - `advance_drawn`: záloha (proforma, přijatá zálohová faktura), kterou už čerpá konečná
 *    faktura; vrácení by rozbilo vyúčtování, jen se ohlásí,
 *  - `manual`: přijatý doklad označený jako uhrazený uživatelem (přechod stavu, platební
 *    příkaz s označením zaplaceno, platba kartou) nebo založený v aplikaci bez importu či
 *    převodu (ruční označení bez úhrady je tam povolené a starší nemá stopu v auditu);
 *    opraví se jen s `includeManual`; uživatelovo označení má přednost i u dokladu z importu,
 *  - `source_paid`: přijatý doklad z Fakturoidu či iDokladu uhrazený ve zdroji, doplní se krytí,
 *  - `migration_source`: přijatý doklad převedený z účetního programu, jen se ohlásí,
 *  - `tax_evidence`: přijatý doklad firmy v daňové evidenci (ke dni úhrady), opraví se jen
 *    s `includeTaxEvidence`,
 *  - `ledger_closed`: pohledávka/závazek dokladu je v deníku vyrovnaná (zaplaceno mimo
 *    MyÚčto nebo kurzový rozdíl, úhradu stačí spárovat); opraví se jen s `includeLedgerClosed`,
 *  - `locked`: doklad v uzavřeném, uzavíraném nebo k datu zamčeném období; opraví se,
 *    pokud není `skipLocked`,
 *  - `reset`: k opravě.
 *
 * Výchozí běh je nanečisto. Oprava je idempotentní: opravený doklad už podmínkám výběru
 * nevyhovuje.
 */
final class PaidWithoutPaymentRepair
{
    public const RESET = 'reset';
    public const LOCKED = 'locked';
    public const LEDGER_CLOSED = 'ledger_closed';
    public const MANUAL = 'manual';
    public const LINKED_UNRECORDED = 'linked_unrecorded';
    public const OTHER_ITEM = 'other_item';
    public const UNBACKED_PAID_TOTAL = 'unbacked_paid_total';
    public const ADVANCE_DRAWN = 'advance_drawn';
    public const SOURCE_PAID = 'source_paid';
    public const MIGRATION_SOURCE = 'migration_source';
    public const TAX_EVIDENCE = 'tax_evidence';

    public const CATEGORIES = [self::UNBACKED_PAID_TOTAL, self::OTHER_ITEM, self::LINKED_UNRECORDED, self::ADVANCE_DRAWN, self::MANUAL,
        self::SOURCE_PAID, self::MIGRATION_SOURCE, self::TAX_EVIDENCE, self::LEDGER_CLOSED, self::LOCKED, self::RESET];

    public const PURCHASE_AUDIT_ACTION = 'purchase_invoice.transitioned';
    public const AUDIT_SOURCE = 'repair-paid-without-payment';

    private const PAGE_SIZE = 500;

    /** Tolerance vyrovnání v deníku v Kč (stejná jako dorovnání úhrad v bance). */
    private const LEDGER_TOLERANCE_CZK = FxPaymentSettlement::AMOUNT_TOLERANCE;

    /**
     * Nedoplatek, od kterého je vydaný doklad „uhrazený bez úhrady": tolerance plateb
     * v měně dokladu, stejně jako {@see InvoicePaymentService::recomputeLocked()} (jinak by
     * oprava vrátila doklad, který příští přepočet plateb označí znovu za uhrazený).
     * Přijaté doklady se řídí pravidlem aplikace pro „uhrazeno s rozdílem"
     * ({@see PurchaseSettledExpr::unsettledShortfallCondition()}: 1 Kč včetně zaokrouhlení).
     */
    private const ISSUED_TOLERANCE = InvoicePaymentService::TOLERANCE;

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly InvoiceUnmarkPaid $unmark,
        private readonly ActivityLogger $logger,
        private readonly AdvanceCoveredPaidStatus $advanceStatus,
        private readonly MigrationCheckLocks $paymentLocks,
    ) {}

    /** @return list<array{id:int,name:string}> */
    public function suppliers(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, company_name AS name FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows ?: []);
    }

    /**
     * @param 'invoice'|'purchase_invoice' $docType
     * @param array{include_ledger_closed?:bool,skip_locked?:bool,include_manual?:bool,include_tax_evidence?:bool} $options
     * @return \Generator<int,array<string,mixed>>
     */
    public function run(int $supplierId, string $docType, bool $commit, array $options = []): \Generator
    {
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) {
            throw new \InvalidArgumentException('Neplatný druh dokladu.');
        }
        $this->locks->reset();
        $afterId = 0;
        do {
            $docs = $docType === 'invoice' ? $this->issuedCandidates($supplierId, $afterId) : $this->purchaseCandidates($supplierId, $afterId);
            if ($docs === []) {
                break;
            }
            $afterId = (int) end($docs)['id'];
            $ids = array_map(static fn (array $d): int => (int) $d['id'], $docs);
            $ledger = $this->ledger($supplierId, $docType, $ids);
            $locks = $this->locks->lockedMapForSources($supplierId, $docType, $ids);
            foreach ($docs as $doc) {
                $id = (int) $doc['id'];
                $lock = $locks[$id] ?? null;
                $state = $ledger[$id] ?? ['posted' => 0.0, 'settled' => 0.0, 'paired' => false];
                $row = $this->classify($docType, $doc, $state, $lock, $options);
                // Krytí úhrady je evidence úhrady k datu úhrady: za zámkem účtování k datu ani
                // v podaném období DPH se nedoplňuje (stejně jako Kontrola dat po převodu).
                if ($row['category'] === self::SOURCE_PAID
                    && ($paymentLock = $this->paymentLocks->paymentRecordReason($supplierId, $doc['paid_at'] !== null ? (string) $doc['paid_at'] : null)) !== null) {
                    $row['eligible'] = false;
                    $row['outcome'] = 'kept';
                    $row['reason'] = $paymentLock;
                }
                if ($commit && $row['eligible']) {
                    try {
                        $row['outcome'] = match (true) {
                            $row['category'] === self::SOURCE_PAID => $this->coverPurchase($supplierId, $doc),
                            $docType === 'invoice' => $this->resetIssued($supplierId, $doc),
                            default => $this->resetPurchase($supplierId, $doc),
                        };
                    } catch (\Throwable $e) {
                        $row['outcome'] = 'failed';
                        $row['reason'] = $e->getMessage();
                    }
                }
                yield $row;
            }
        } while (count($docs) === self::PAGE_SIZE);
    }

    /** @return list<array<string,mixed>> */
    private function issuedCandidates(int $supplierId, int $afterId): array
    {
        $tolerance = self::sqlNumber(self::ISSUED_TOLERANCE);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT i.id, i.supplier_id, i.invoice_type, i.varsymbol AS document_no, i.issue_date, i.effective_tax_date,
                       i.booked_at, i.status, i.paid_at, i.sent_at, i.parent_invoice_id, i.amount_to_pay, i.paid_total,
                       i.exchange_rate, c.code AS currency, i.import_batch_id, i.other_item_id, i.idoklad_id, i.fakturoid_id,
                       (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id) AS settled,
                       (" . self::linkedUnrecordedSql('i') . ") AS linked_unrecorded,
                       (" . TakenOverRecord::documentSql('invoice', 'i') . ") AS taken_over,
                       0 AS manual, 0 AS tax_evidence,
                       (i.invoice_type = 'proforma'
                        AND (EXISTS (SELECT 1 FROM invoices f WHERE f.supplier_id = i.supplier_id AND f.parent_invoice_id = i.id
                                      AND f.invoice_type IN ('invoice', 'tax_document') AND f.status <> 'cancelled')
                             OR EXISTS (SELECT 1 FROM invoice_items di JOIN invoices f ON f.id = di.invoice_id
                                         WHERE di.advance_invoice_id = i.id AND f.status <> 'cancelled')
                             OR EXISTS (SELECT 1 FROM invoice_advance_deductions d JOIN invoices f ON f.id = d.final_invoice_id
                                         WHERE d.supplier_id = i.supplier_id AND d.advance_invoice_id = i.id AND f.status <> 'cancelled'))) AS advance_drawn
                  FROM invoices i
                  JOIN currencies c ON c.id = i.currency_id
                 WHERE i.supplier_id = ? AND i.status = 'paid' AND i.id > ?
                   AND i.invoice_type IN ('invoice', 'proforma') AND i.invoice_type <> 'tax_document' AND i.amount_to_pay > 0
                   AND NOT EXISTS (SELECT 1 FROM invoices cal WHERE cal.supplier_id = i.supplier_id AND cal.calendar_id = i.id)
             ) d
             WHERE ROUND(d.amount_to_pay - d.settled, 2) > {$tolerance}
             ORDER BY d.id LIMIT " . self::PAGE_SIZE
        );
        $stmt->execute([$supplierId, $afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function purchaseCandidates(int $supplierId, int $afterId): array
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT pi.id, pi.supplier_id, pi.document_kind AS invoice_type,
                       COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS document_no,
                       pi.issue_date, pi.effective_cost_date, pi.booked_at, pi.status, pi.paid_at, pi.amount_to_pay,
                       pi.exchange_rate, c.code AS currency, pi.import_batch_id, NULL AS other_item_id, 0 AS paid_total, pi.idoklad_id, pi.fakturoid_id,
                       ({$settled}) AS settled,
                       0 AS linked_unrecorded,
                       (" . TakenOverRecord::documentSql('purchase_invoice', 'pi') . ") AS taken_over,
                       (pi.payment_ordered_at IS NOT NULL
                        OR ((pi.card_last4 IS NOT NULL OR pi.payment_method = 'card') AND pi.idoklad_id IS NULL AND pi.fakturoid_id IS NULL)
                        OR EXISTS (SELECT 1 FROM activity_log al
                                    WHERE al.entity_type = 'purchase_invoice' AND al.entity_id = pi.id
                                      AND al.action = '" . self::PURCHASE_AUDIT_ACTION . "'
                                      AND JSON_VALUE(al.payload, '$.to') = 'paid')) AS manual,
                       (COALESCE((SELECT m.accounting_mode FROM supplier_accounting_modes m
                                   WHERE m.supplier_id = pi.supplier_id AND m.effective_from <= COALESCE(pi.paid_at, pi.issue_date)
                                   ORDER BY m.effective_from DESC LIMIT 1), s.accounting_mode) = 'tax_evidence') AS tax_evidence,
                       (pi.document_kind = 'advance'
                        AND (EXISTS (SELECT 1 FROM purchase_invoices f WHERE f.supplier_id = pi.supplier_id AND f.advance_purchase_invoice_id = pi.id
                                      AND f.status <> 'cancelled')
                             OR EXISTS (SELECT 1 FROM purchase_advance_deductions d JOIN purchase_invoices f ON f.id = d.final_invoice_id
                                         WHERE d.supplier_id = pi.supplier_id AND d.advance_invoice_id = pi.id AND f.status <> 'cancelled'))) AS advance_drawn
                  FROM purchase_invoices pi
                  JOIN supplier s ON s.id = pi.supplier_id
                  JOIN currencies c ON c.id = pi.currency_id
                 WHERE pi.supplier_id = ? AND pi.status = 'paid' AND pi.id > ?
                   AND pi.amount_to_pay > 0 AND pi.document_kind <> 'tax_document'
                   AND " . PurchaseSettledExpr::unsettledShortfallCondition('pi', 'c.code') . "
             ) d
             ORDER BY d.id LIMIT " . self::PAGE_SIZE
        );
        $stmt->execute([$supplierId, $afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $doc
     * @param array{posted:float,settled:float,paired:bool} $ledger
     * @param array<string,bool> $options
     * @return array<string,mixed>
     */
    private function classify(string $docType, array $doc, array $ledger, ?DocumentLock $lock, array $options): array
    {
        $rate = (string) $doc['currency'] === 'CZK' ? 1.0 : ((float) $doc['exchange_rate'] > 0 ? (float) $doc['exchange_rate'] : 1.0);
        $missing = round((float) $doc['amount_to_pay'] - (float) $doc['settled'], 2);
        $ledgerState = match (true) {
            $ledger['posted'] < 0.005 => 'not_posted',
            $ledger['paired'] || $ledger['posted'] - $ledger['settled'] <= self::LEDGER_TOLERANCE_CZK => 'closed',
            default => 'open',
        };
        $locked = $lock !== null && ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked);
        $origin = self::origin($doc);
        $flags = [
            self::UNBACKED_PAID_TOTAL => $docType === 'invoice' && (float) $doc['paid_total'] - (float) $doc['settled'] >= 0.005,
            self::OTHER_ITEM => !empty($doc['other_item_id']),
            self::LINKED_UNRECORDED => (bool) $doc['linked_unrecorded'],
            self::ADVANCE_DRAWN => (bool) $doc['advance_drawn'],
            // Uživatel doklad označil jako uhrazený sám (i doklad z importu či převodu): jeho
            // rozhodnutí má přednost před stavem ze zdroje. Soubory se přijaté doklady
            // importují jen jako koncept nebo přijaté, uhrazený doklad z importní dávky proto
            // označil uživatel až po importu.
            self::MANUAL => (bool) $doc['manual'] || ($docType === 'purchase_invoice' && in_array($origin, ['app', 'import'], true)),
            self::SOURCE_PAID => $docType === 'purchase_invoice' && in_array($origin, ['idoklad', 'fakturoid'], true),
            self::MIGRATION_SOURCE => $docType === 'purchase_invoice' && $origin === 'migration',
            self::TAX_EVIDENCE => $docType === 'purchase_invoice' && (bool) $doc['tax_evidence'],
            self::LEDGER_CLOSED => $ledgerState === 'closed',
            self::LOCKED => $locked,
        ];
        $category = self::RESET;
        foreach ($flags as $name => $on) {
            if ($on) {
                $category = $name;
                break;
            }
        }
        $eligible = match (true) {
            $category === self::SOURCE_PAID => true,
            in_array($category, [self::UNBACKED_PAID_TOTAL, self::OTHER_ITEM, self::LINKED_UNRECORDED, self::ADVANCE_DRAWN, self::MIGRATION_SOURCE], true) => false,
            $category === self::MANUAL && empty($options['include_manual']) => false,
            $flags[self::TAX_EVIDENCE] && empty($options['include_tax_evidence']) => false,
            $flags[self::LEDGER_CLOSED] && empty($options['include_ledger_closed']) => false,
            $flags[self::LOCKED] && !empty($options['skip_locked']) => false,
            default => true,
        };

        return [
            'doc_type' => $docType,
            'id' => (int) $doc['id'],
            'supplier_id' => (int) $doc['supplier_id'],
            'document_no' => (string) $doc['document_no'],
            'invoice_type' => (string) $doc['invoice_type'],
            'issue_date' => (string) $doc['issue_date'],
            'paid_at' => $doc['paid_at'] !== null ? substr((string) $doc['paid_at'], 0, 10) : null,
            'currency' => (string) $doc['currency'],
            'amount_to_pay' => round((float) $doc['amount_to_pay'], 2),
            'settled' => round((float) $doc['settled'], 2),
            'missing' => $missing,
            'missing_czk' => round($missing * $rate, 2),
            'origin' => $origin,
            'ledger' => $ledgerState,
            'ledger_open_czk' => round(max(0.0, $ledger['posted'] - $ledger['settled']), 2),
            'locked_reasons' => $locked ? array_values(array_intersect($lock->reasons(), ['period_closed', 'period_closing', 'date_locked'])) : [],
            'category' => $category,
            'flags' => array_keys(array_filter($flags)),
            'eligible' => $eligible,
            'outcome' => !$eligible ? 'kept' : ($category === self::SOURCE_PAID ? 'would_cover' : 'would_reset'),
            'reason' => self::reason($category),
            // Vrácení vydaného dokladu vypne automatické upomínky a webhook nevydá.
            'reminders_disabled' => $eligible && $docType === 'invoice',
            'webhook_suppressed' => $eligible && $docType === 'invoice',
        ];
    }

    /** @param array<string,mixed> $doc */
    private static function origin(array $doc): string
    {
        return match (true) {
            !empty($doc['import_batch_id']) => 'import',
            (bool) $doc['taken_over'] => 'migration',
            !empty($doc['idoklad_id']) => 'idoklad',
            !empty($doc['fakturoid_id']) => 'fakturoid',
            default => 'app',
        };
    }

    private static function reason(string $category): string
    {
        return match ($category) {
            self::UNBACKED_PAID_TOTAL => 'Uhrazená částka převzatá bez záznamu platby, doplňte evidenci úhrady opravou převodu, stav se nevrací.',
            self::OTHER_ITEM => 'Uhrazeno úhradami navázané ostatní pohledávky, řeší se v detailu pohledávky.',
            self::LINKED_UNRECORDED => 'Spárovaná úhrada bez záznamu platby, opravte spárováním v bance nebo pokladně.',
            self::ADVANCE_DRAWN => 'Zálohu už čerpá konečná faktura, vrácení by rozbilo vyúčtování. Ověřte úhradu zálohy ručně.',
            self::SOURCE_PAID => 'Uhrazeno ve Fakturoidu či iDokladu, doplní se krytí úhrady ze zdroje (úhrada mimo evidenci).',
            self::MIGRATION_SOURCE => 'Převedeno z účetního programu, krytí doplňte Kontrolou dat po převodu (Přijaté doklady uhrazené bez krytí).',
            self::TAX_EVIDENCE => 'Daňová evidence: doklad je výdajem v peněžním deníku ke dni úhrady, vrácení by ho z deníku odebralo. Jen s --include-tax-evidence.',
            self::MANUAL => 'Označeno jako uhrazené v aplikaci (ručně, platebním příkazem, platbou kartou nebo bez stopy původu), rozhodne uživatel.',
            self::LEDGER_CLOSED => 'Zaplaceno mimo MyÚčto, v deníku je pohledávka nebo závazek vyrovnaný, ponechat a úhradu spárovat.',
            self::LOCKED => 'Doklad v uzavřeném nebo zamčeném období.',
            default => 'Uhrazeno bez úhrady, v deníku nevyrovnáno nebo nezaúčtováno.',
        };
    }

    /** @param array<string,mixed> $doc */
    private function resetIssued(int $supplierId, array $doc): string
    {
        $pdo = $this->db->pdo();
        $id = (int) $doc['id'];
        $tolerance = self::sqlNumber(self::ISSUED_TOLERANCE);
        $reverted = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $id, $tolerance): bool {
            $pdo->beginTransaction();
            try {
                $check = $pdo->prepare(
                    "SELECT 1 FROM invoices i
                      WHERE i.supplier_id = ? AND i.id = ? AND i.status = 'paid' AND i.amount_to_pay > 0 AND i.other_item_id IS NULL
                        AND i.invoice_type <> 'tax_document'
                        AND ROUND(i.amount_to_pay - (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id), 2) > {$tolerance}
                        AND i.paid_total - (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id) < 0.005
                      FOR UPDATE"
                );
                $check->execute([$supplierId, $id]);
                if ($check->fetchColumn() === false) {
                    $pdo->rollBack();
                    return false;
                }
                // Úhrady zůstávají, paid_total se přepočte z nich (částečná úhrada tak dál drží
                // svou část). Stav vrací stejně jako přepočet plateb, jen bez webhooku
                // a s vypnutými automatickými upomínkami.
                $pdo->prepare(
                    "UPDATE invoices i
                        SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id),
                            i.status = IF(i.sent_at IS NOT NULL, 'sent', 'issued'), i.paid_at = NULL, i.auto_send_reminders = 0
                      WHERE i.supplier_id = ? AND i.id = ? AND i.status = 'paid'"
                )->execute([$supplierId, $id]);
                CalendarInstalmentService::syncCalendarStatus($pdo, $id);
                $pdo->commit();
                return true;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        if (!$reverted) {
            return 'skipped';
        }
        $this->unmark->revert($doc, null, self::audit($doc) + ['auto_send_reminders_disabled' => true, 'webhook_suppressed' => true], publishWebhook: false);
        return 'reset';
    }

    /** @param array<string,mixed> $doc */
    private function resetPurchase(int $supplierId, array $doc): string
    {
        $pdo = $this->db->pdo();
        $id = (int) $doc['id'];
        $shortfall = PurchaseSettledExpr::unsettledShortfallCondition('pi', 'c.code', currentRead: true);
        $posted = DocumentPostingGroup::postedEntryExistsSql('purchase_invoice', 'pi.id', 'pi.supplier_id', 'rpe');
        $target = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $id, $shortfall, $posted): ?string {
            $pdo->beginTransaction();
            try {
                $check = $pdo->prepare(
                    "SELECT IF(pi.booked_at IS NOT NULL OR {$posted}, 'booked', 'received')
                       FROM purchase_invoices pi JOIN currencies c ON c.id = pi.currency_id
                      WHERE pi.supplier_id = ? AND pi.id = ? AND pi.status = 'paid' AND pi.amount_to_pay > 0
                        AND pi.document_kind <> 'tax_document'
                        AND {$shortfall}
                      FOR UPDATE"
                );
                $check->execute([$supplierId, $id]);
                $target = $check->fetchColumn();
                if ($target === false) {
                    $pdo->rollBack();
                    return null;
                }
                $pdo->prepare("UPDATE purchase_invoices SET status = ?, paid_at = NULL WHERE supplier_id = ? AND id = ? AND status = 'paid'")
                    ->execute([(string) $target, $supplierId, $id]);
                $pdo->commit();
                return (string) $target;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        if ($target === null) {
            return 'skipped';
        }
        $this->logger->log(self::PURCHASE_AUDIT_ACTION, null, 'purchase_invoice', $id, [
            'from' => 'paid',
            'to' => $target,
            'previous_paid_at' => $doc['paid_at'] ?? null,
        ] + self::audit($doc), null, null, $supplierId);
        // Záloha vrácená mezi neuhrazené: konečné faktury, které ji ještě nečerpají, srovná
        // pravidlo krytí zálohou (čerpané zálohy oprava nevrací vůbec).
        try {
            $this->advanceStatus->afterPaymentsChanged($supplierId, [$id]);
        } catch (\Throwable $e) {
            error_log('PaidWithoutPaymentRepair: srovnání záloh po vrácení dokladu #' . $id . ' selhalo: ' . $e->getMessage());
        }
        return 'reset';
    }

    /**
     * Přijatý doklad uhrazený ve Fakturoidu či iDokladu: úhradu ze zdroje nese krytí mimo
     * evidenci stejně jako u nového importu ({@see ImportedPaidPurchase::cover()}). Stav,
     * datum úhrady ani peněžní deník se nemění.
     *
     * @param array<string,mixed> $doc
     */
    private function coverPurchase(int $supplierId, array $doc): string
    {
        $pdo = $this->db->pdo();
        $id = (int) $doc['id'];
        $before = $pdo->prepare('SELECT settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND id = ?');
        $before->execute([$supplierId, $id]);
        $outsideBefore = (float) $before->fetchColumn();
        ImportedPaidPurchase::cover($pdo, $id, $supplierId);
        $before->execute([$supplierId, $id]);
        $outsideAfter = (float) $before->fetchColumn();
        if (abs($outsideAfter - $outsideBefore) < 0.005) {
            return 'skipped';
        }
        $this->logger->log('purchase_invoice.import_settled_outside_restored', null, 'purchase_invoice', $id, [
            'source' => self::AUDIT_SOURCE,
            'note' => 'Uhrazeno ve zdroji importu bez krytí, doplněna úhrada mimo evidenci.',
            'settled_outside_before' => round($outsideBefore, 2),
            'settled_outside_after' => round($outsideAfter, 2),
        ], null, null, $supplierId);
        return 'covered';
    }

    /**
     * SQL podmínka „vydaný doklad `$alias` má spárovaný bankovní pohyb nebo pokladní doklad
     * bez záznamu platby". Úhrada tu je, jen ji párování nezapsalo; patří do párování, ne do
     * opravy stavu ani do doplnění platby (sdílí ji {@see \MyInvoice\Service\Migration\Shared\IssuedRemainderRepair}).
     */
    public static function linkedUnrecordedSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
        return "(EXISTS (SELECT 1 FROM bank_transactions bt
                          WHERE bt.matched_invoice_id = {$alias}.id AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')
                            AND NOT EXISTS (SELECT 1 FROM invoice_payments bp WHERE bp.invoice_id = {$alias}.id AND bp.bank_transaction_id = bt.id))
                 OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = {$alias}.supplier_id AND pm.invoice_id = {$alias}.id
                            AND NOT EXISTS (SELECT 1 FROM invoice_payments mp WHERE mp.invoice_id = {$alias}.id AND mp.bank_transaction_id = pm.bank_transaction_id))
                 OR EXISTS (SELECT 1 FROM cash_documents cd WHERE cd.supplier_id = {$alias}.supplier_id AND cd.invoice_id = {$alias}.id
                            AND cd.status = 'posted' AND cd.invoice_payment_id IS NULL))";
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function audit(array $doc): array
    {
        return [
            'source' => self::AUDIT_SOURCE,
            'note' => 'Uhrazeno bez evidované úhrady, stav vrácen podle úhrad.',
            'amount_to_pay' => round((float) $doc['amount_to_pay'], 2),
            'settled' => round((float) $doc['settled'], 2),
        ];
    }

    /**
     * Stav pohledávky (311, 315) nebo závazku (321, 325) dokladu v deníku, v Kč.
     *
     * `posted` = předpis z vlastního zaúčtování dokladu (i skupiny zaúčtování po řádcích),
     * `settled` = vyrovnání zápisy, které k dokladu patří: bankovní pohyby a pokladní doklady
     * navázané jen na tento doklad, zápočty proti účtu se zápisem a ruční zápisy navázané
     * na doklad ({@see LinkedManualSettlementSql}). `paired` = řádek předpisu leží ve
     * vyrovnané skupině párování (párovací symbol převodu z POHODY nebo okruh otevřených
     * položek). Stornované zápisy a jejich storna se nepočítají.
     *
     * @param list<int> $ids
     * @return array<int,array{posted:float,settled:float,paired:bool}>
     */
    private function ledger(int $supplierId, string $docType, array $ids): array
    {
        $pdo = $this->db->pdo();
        $in = implode(',', array_map('intval', $ids));
        [$prefixes, $side] = $docType === 'invoice' ? [['311', '315'], 'debit'] : [['321', '325'], 'credit'];
        $settleSide = $side === 'debit' ? 'credit' : 'debit';
        $account = implode(' OR ', array_map(static fn (string $p): string => LinkedManualSettlementSql::accountPrefixPredicate($p), $prefixes));
        $active = static fn (string $e): string => "{$e}.supplier_id = {$supplierId} AND {$e}.posted_at IS NOT NULL AND {$e}.reversed_by IS NULL
            AND NOT EXISTS (SELECT 1 FROM journal_entries {$e}_orig WHERE {$e}_orig.supplier_id = {$e}.supplier_id AND {$e}_orig.reversed_by = {$e}.id)";
        $signed = "CASE WHEN l.side = '{$side}' THEN l.signed_amount ELSE -l.signed_amount END";
        $lines = "JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id";

        $out = array_fill_keys($ids, ['posted' => 0.0, 'settled' => 0.0, 'paired' => false]);

        $own = $pdo->query(
            "SELECT o.doc_id, e.id AS entry_id, l.line_no, l.account_id, {$signed} AS amount
               FROM (" . DocumentPostingGroup::entriesSql($docType, $supplierId) . ") o
               JOIN journal_entries e ON e.id = o.entry_id
               {$lines}
              WHERE o.doc_id IN ({$in}) AND {$active('e')} AND ({$account})"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ownLines = [];
        foreach ($own as $r) {
            $out[(int) $r['doc_id']]['posted'] += (float) $r['amount'];
            $ownLines[] = $r;
        }

        // Bankovní pohyby a pokladní doklady navázané jen na tento doklad.
        $links = $docType === 'invoice'
            ? "SELECT bt.id AS tx_id, bt.matched_invoice_id AS doc_id FROM bank_transactions bt
                 WHERE bt.matched_invoice_id IN ({$in}) AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')
               UNION SELECT p.bank_transaction_id, p.invoice_id FROM invoice_payments p
                 WHERE p.supplier_id = {$supplierId} AND p.invoice_id IN ({$in}) AND p.bank_transaction_id IS NOT NULL"
            : "SELECT pm.bank_transaction_id AS tx_id, pm.purchase_invoice_id AS doc_id FROM payment_matches pm
                 WHERE pm.supplier_id = {$supplierId} AND pm.purchase_invoice_id IN ({$in})";
        $txDoc = [];
        foreach ($pdo->query($links)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $txDoc[(int) $r['tx_id']][(int) $r['doc_id']] = true;
        }
        if ($txDoc !== []) {
            $txIn = implode(',', array_keys($txDoc));
            // Pohyb sdílený s jiným dokladem (hromadná úhrada) se nepřičítá: zápis nenese rozpad.
            $shared = $pdo->query(
                "SELECT tx_id FROM (
                    SELECT pm.bank_transaction_id AS tx_id, CONCAT(IF(pm.invoice_id IS NULL, 'p', 'i'), COALESCE(pm.invoice_id, pm.purchase_invoice_id)) AS doc
                      FROM payment_matches pm WHERE pm.bank_transaction_id IN ({$txIn})
                    UNION SELECT p.bank_transaction_id, CONCAT('i', p.invoice_id) FROM invoice_payments p WHERE p.bank_transaction_id IN ({$txIn})
                    UNION SELECT bt.id, CONCAT('i', bt.matched_invoice_id) FROM bank_transactions bt WHERE bt.id IN ({$txIn}) AND bt.matched_invoice_id IS NOT NULL
                 ) x GROUP BY tx_id HAVING COUNT(DISTINCT doc) > 1"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($shared as $tx) {
                unset($txDoc[(int) $tx]);
            }
        }
        $bankDoc = [];
        foreach ($txDoc as $tx => $docs) {
            $bankDoc[$tx] = (int) array_key_first($docs);
        }
        $cashColumn = $docType === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $cashDoc = [];
        foreach ($pdo->query("SELECT id, {$cashColumn} AS doc_id FROM cash_documents
                               WHERE supplier_id = {$supplierId} AND {$cashColumn} IN ({$in}) AND status = 'posted'")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cashDoc[(int) $r['id']] = (int) $r['doc_id'];
        }
        foreach (['bank' => $bankDoc, 'cash' => $cashDoc] as $source => $map) {
            if ($map === []) {
                continue;
            }
            $rows = $pdo->query(
                "SELECT e.source_id, -SUM({$signed}) AS amount FROM journal_entries e {$lines}
                  WHERE {$active('e')} AND e.source_type = '{$source}' AND e.source_id IN (" . implode(',', array_keys($map)) . ") AND ({$account})
                  GROUP BY e.source_id"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[$map[(int) $r['source_id']]]['settled'] += (float) $r['amount'];
            }
        }

        // Zápočty proti účtu se zápisem v deníku.
        $rows = $pdo->query(
            "SELECT s.doc_id, -SUM({$signed}) AS amount FROM invoice_settlements s
               JOIN journal_entries e ON e.id = s.journal_entry_id
               {$lines}
              WHERE s.supplier_id = {$supplierId} AND s.doc_type = '{$docType}' AND s.doc_id IN ({$in}) AND s.status = 'confirmed'
                AND {$active('e')} AND ({$account})
              GROUP BY s.doc_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(int) $r['doc_id']]['settled'] += (float) $r['amount'];
        }

        // Ruční zápisy navázané na doklad a jejich rozpad.
        $manual = LinkedManualSettlementSql::settledSql($docType, $settleSide, $account, (string) $supplierId, "'9999-12-31'");
        foreach ($pdo->query("SELECT m.doc_id, m.settled FROM ({$manual}) m WHERE m.doc_id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['doc_id']]['settled'] += (float) $r['settled'];
        }

        foreach ($this->pairedLines($supplierId, $ownLines, $signed, $active('e')) as $docId) {
            $out[$docId]['paired'] = true;
        }
        return $out;
    }

    /**
     * Doklady, jejichž řádek předpisu leží ve vyrovnané skupině párování.
     *
     * @param list<array<string,mixed>> $ownLines
     * @return list<int>
     */
    private function pairedLines(int $supplierId, array $ownLines, string $signed, string $active): array
    {
        if ($ownLines === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $keys = implode(',', array_map(static fn (array $r): string => '(' . (int) $r['entry_id'] . ',' . (int) $r['line_no'] . ')', $ownLines));
        $docByLine = [];
        foreach ($ownLines as $r) {
            $docByLine[(int) $r['entry_id'] . ':' . (int) $r['line_no']] = (int) $r['doc_id'];
        }
        $tolerance = self::sqlNumber(self::LEDGER_TOLERANCE_CZK);
        $paired = [];
        // Párovací symbol převodu z POHODY: skupina řádků téhož účtu se stejným symbolem.
        $symbols = $pdo->query(
            "SELECT own.entry_id, own.line_no FROM journal_line_pairing own
               JOIN journal_entry_lines ol ON ol.supplier_id = own.supplier_id AND ol.entry_id = own.entry_id AND ol.line_no = own.line_no
              WHERE own.supplier_id = {$supplierId} AND (own.entry_id, own.line_no) IN ({$keys})
                AND (SELECT ABS(SUM({$signed})) FROM journal_line_pairing g
                       JOIN journal_entries e ON e.supplier_id = g.supplier_id AND e.id = g.entry_id
                       JOIN journal_entry_lines l ON l.supplier_id = g.supplier_id AND l.entry_id = g.entry_id AND l.line_no = g.line_no
                      WHERE g.supplier_id = own.supplier_id AND g.pairing_symbol = own.pairing_symbol
                        AND l.account_id = ol.account_id AND {$active}) <= {$tolerance}
                AND (SELECT COUNT(*) FROM journal_line_pairing c WHERE c.supplier_id = own.supplier_id AND c.pairing_symbol = own.pairing_symbol) > 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Okruh otevřených položek spárovaný v MyÚčtu.
        $groups = $pdo->query(
            "SELECT own.entry_id, own.line_no FROM journal_line_pairing_items own
              WHERE own.supplier_id = {$supplierId} AND (own.entry_id, own.line_no) IN ({$keys})
                AND (SELECT ABS(SUM({$signed})) FROM journal_line_pairing_items g
                       JOIN journal_entries e ON e.supplier_id = g.supplier_id AND e.id = g.entry_id
                       JOIN journal_entry_lines l ON l.supplier_id = g.supplier_id AND l.entry_id = g.entry_id AND l.line_no = g.line_no
                      WHERE g.supplier_id = own.supplier_id AND g.pairing_id = own.pairing_id AND {$active}) <= {$tolerance}
                AND (SELECT COUNT(*) FROM journal_line_pairing_items c WHERE c.supplier_id = own.supplier_id AND c.pairing_id = own.pairing_id) > 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach (array_merge($symbols, $groups) as $r) {
            $paired[$docByLine[(int) $r['entry_id'] . ':' . (int) $r['line_no']]] = true;
        }
        return array_keys($paired);
    }

    private static function sqlNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
