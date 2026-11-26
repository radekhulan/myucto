<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\JournalDescriptionRebuilder;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Oprava firem, kterým převod z POHODY před verzí 6.72 založil ostatní závazky (agenda
 * Ostatní závazky, mapa převodu `commitment|číslo|datum`) jako přijaté faktury.
 *
 * Ostatní závazek bez členění DPH s nulovou daní (mzdy, odvody, nájem od neplátce, doplatek
 * daně…) převod bral jako fakturu v přiznání. Doklad, který v POHODĚ vlastní zápis nemá
 * (předpis nese interní doklad mezd nebo počáteční stav), zůstal přijatou fakturou bez
 * zápisu: Doúčtování ho nabízí k zaúčtování podruhé a doklad minulého období visí
 * v saldokontu 321, ačkoli deník ho vede na 325, 331, 336… Oprava ho převede na ostatní
 * závazek stejně, jak ho dnes zakládá převod ({@see OtherItemImporter}):
 *  - doklad s vlastním zápisem v deníku POHODY (zápis zdroje Ostatní závazky se stejným
 *    číslem, nebo s původním číslem přečíslovaného dokladu, {@see PohodaJournal::renumberedEntries()})
 *    zápis převezme jako zaúčtovaný ostatní závazek (`posted`, zdroj zápisu `other_item`),
 *  - doklad bez zápisu se založí jako potvrzený bez zaúčtování; účet závazku vezme ze
 *    zaúčtování jeho úhrad, jinak zůstane neurčený,
 *  - s `$includePosted` i zaúčtované přijaté faktury z ostatních závazků bez DPH: jejich
 *    zápis převezme ostatní závazek.
 * Úhrady z banky a pokladny se z párování faktury přesunou do přiřazení úhrad položky
 * ({@see OtherItemService::allocate()}, nic se neúčtuje), uhrazené jinak (minulý rok,
 * zápočet) jde do `settled_outside_amount`. Mapa převodu a vazby úhrad v ní se přepíšou na
 * ostatní položku, takže opakovaný převod nic nezdvojí. Přijatá faktura se smaže.
 *
 * Ostatní závazek s DPH je přijatá faktura oprávněně; má-li v deníku zápis s původním
 * číslem přečíslovaného dokladu, dostane vazbu na něj.
 *
 * Nové zápisy oprava nikdy nezakládá. Doklad nemění (jen ohlásí), když leží v uzavřeném,
 * rozpracovaném nebo zamčeném období (data dokladu, zápisu i úhrad), je v přiznání DPH
 * podle evidence DPH, je v cizí měně, nemá kladnou částku, má víc možných zápisů, zápis
 * neodpovídá jednomu účtu závazku, jeho účet vlastní daňový modul (341, 343), má v deníku
 * další zápis se zdrojem faktury nebo ve skupině zaúčtování, má připojený soubor, navazuje
 * na něj cokoli dalšího (majetek, sklad, zápočet, dimenze, příkaz k úhradě, časové
 * rozlišení, AI…) nebo jeho úhrada z banky či pokladny hradí i jiný doklad. Zápisy čísla
 * dokladu se berou jen z jeho agendy ({@see DocumentLinker::agendaEntries()}). Nečekaná
 * chyba vrátí jen změny dané firmy (stav `failed`), běh pokračuje další firmou. Každý doklad má vlastní savepoint: odmítnutý se vrátí celý.
 * Nanečisto proběhne totéž a na konci se vrátí; výpis ukazuje i saldokonto 321 a 325 proti
 * hlavní knize před a po. Opakovaný běh nic nemění.
 */
final class PohodaOtherLiabilityRepair
{
    public const AUDIT_ACTION = 'purchase_invoice.pohoda_other_liability_repaired';

    /** Saldokontní účty, jejichž rozdíl proti hlavní knize výpis ukazuje. */
    public const SALDO_ACCOUNTS = ['321', '325'];

    /** Navazující záznamy, kvůli kterým se doklad nepřevádí (tabulka => podmínka nad `?` = id dokladu). */
    private const DEPENDENTS = [
        'majetek' => ['assets', 'purchase_invoice_id = ?'],
        'technické zhodnocení' => ['asset_improvements', 'purchase_invoice_id = ?'],
        'drobný majetek' => ['small_assets', 'purchase_invoice_id = ?'],
        'skladový doklad' => ['stock_documents', 'purchase_invoice_id = ?'],
        'pořizovací náklady skladu' => ['stock_landed_costs', 'purchase_invoice_id = ?'],
        'tankování' => ['fuelings', 'source_purchase_invoice_id = ?'],
        'účtenka knihy jízd' => ['logbook_fuel_scans', 'purchase_invoice_id = ?'],
        'přeúčtování' => ['accounting_reclassification_items', 'purchase_invoice_id = ?'],
        'objednávka' => ['purchase_order_invoice_links', 'purchase_invoice_id = ?'],
        'příkaz k úhradě' => ['payment_order_items', 'purchase_invoice_id = ?'],
        'odeslání ke schválení' => ['purchase_invoice_submissions', 'purchase_invoice_id = ?'],
        'žádost o doklad' => ['document_requests', 'purchase_invoice_id = ?'],
        'čerpání zálohy' => ['purchase_advance_deductions', '(final_invoice_id = ? OR advance_invoice_id = ?)'],
        'opravy DPH' => ['vat_s74b_corrections', 'purchase_invoice_id = ?'],
        'úprava odpočtu' => ['vat_deduction_adjustments', 'purchase_invoice_id = ?'],
        'oprava při registraci DPH' => ['vat_registration_corrections', 'purchase_invoice_id = ?'],
        'neuhrazený závazek v dani z příjmů' => ['tax_unpaid_liability_addbacks', 'purchase_invoice_id = ?'],
        'zápočet dobropisu' => ['credit_note_offsets', "doc_type = 'purchase_invoice' AND (invoice_id = ? OR credit_note_id = ?)"],
        'dohoda o zápočtu' => ['offset_agreement_items', "doc_type = 'purchase_invoice' AND doc_id = ?"],
        'zápočet proti účtu' => ['invoice_settlements', "doc_type = 'purchase_invoice' AND doc_id = ?"],
        'párování saldokonta' => ['journal_settlement_allocations', "doc_type = 'purchase_invoice' AND doc_id = ?"],
        'dimenze dokladu' => ['document_dimensions', "doc_type = 'purchase_invoice' AND doc_id = ?"],
        'připojený dokument' => ['document_links', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'navázaný doklad' => ['purchase_invoices', '(advance_purchase_invoice_id = ? OR parent_purchase_invoice_id = ? OR advance_link_suggested_id = ?)'],
        'časové rozlišení' => ['accrual_items', "source_type = 'purchase_invoice' AND source_id = ?"],
        'rozpad dimenzí' => ['document_dimension_splits', "doc_type = 'purchase_invoice' AND doc_id = ?"],
        'oprava DPH podle § 43' => ['vat_s43_corrections', "source_type = 'purchase_invoice' AND source_id = ?"],
        'hromadná operace účetnictví' => ['accounting_bulk_items', "source_type = 'purchase_invoice' AND source_id = ?"],
        'poznámka peněžního deníku' => ['cash_journal_notes', "source_type = 'purchase_invoice' AND source_id = ?"],
        'oprava zaúčtování' => ['accounting_corrections', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'vytěžení AI' => ['ai_jobs', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'návrh AI' => ['ai_suggestions', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'učení AI' => ['ai_embeddings', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'kontrola přílohy' => ['attachment_checks', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'podpis dokumentu' => ['signature_document_overrides', "entity_type = 'purchase_invoice' AND entity_id = ?"],
        'audit párování banky' => ['bank_match_audit', 'purchase_invoice_id = ?'],
    ];

    private readonly JournalEntryLinker $linker;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $imports,
        private readonly MigrationCheckLocks $locks,
        private readonly OtherItemService $otherItems,
        private readonly SaldoService $saldo,
        private readonly VatLedgerService $vat,
        private readonly ActivityLogger $activity,
        private readonly AccountingPeriodRepository $periods,
    ) {
        $this->linker = new JournalEntryLinker($db, 'Pohody');
    }

    /** @return list<int> firmy s dokončeným převodem z POHODY */
    public function convertedSuppliers(): array
    {
        return $this->imports->completedSuppliers();
    }

    /**
     * Firmy po jedné. Nečekaná chyba firmy vrátí jen její změny: firma dostane stav `failed`
     * s důvodem a běh pokračuje další firmou. Odvolaná transakce (deadlock, lock wait) běh
     * zastaví, opakování je na volajícím.
     *
     * @param list<int> $supplierIds
     * @param (callable(array<string,mixed>):void)|null $onFirm výpis firmy hned po jejím zpracování
     * @return list<array<string,mixed>> výpis po firmách ({@see supplier()})
     */
    public function run(array $supplierIds, bool $commit, ?int $userId = null, bool $includePosted = false, ?callable $onFirm = null): array
    {
        $out = [];
        foreach ($supplierIds as $supplierId) {
            $this->locks->reset();
            try {
                $row = $this->supplier((int) $supplierId, $commit, $userId, $includePosted);
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e)) {
                    throw $e;
                }
                $row = ['supplier_id' => (int) $supplierId, 'company' => '', 'status' => 'failed',
                    'reason' => get_class($e) . ': ' . $e->getMessage(), 'documents' => [], 'saldo_as_of' => null, 'saldo' => [],
                    'totals' => ['converted' => 0, 'converted_czk' => 0.0, 'posted' => 0, 'unposted' => 0, 'linked' => 0,
                        'refused' => 0, 'refused_czk' => 0.0, 'posted_kept' => 0, 'vat_kept' => 0]];
            }
            $out[] = $row;
            if ($onFirm !== null) {
                $onFirm($row);
            }
        }
        return $out;
    }

    /**
     * @return array{supplier_id:int,company:string,status:'repaired'|'would_repair'|'nothing'|'skipped',reason:?string,
     *               documents:list<array<string,mixed>>,totals:array<string,int|float>,saldo_as_of:?string,
     *               saldo:array<string,array{before:?float,after:?float}>}
     */
    private function supplier(int $supplierId, bool $commit, ?int $userId, bool $includePosted): array
    {
        $pdo = $this->db->pdo();
        $head = $pdo->prepare('SELECT company_name, accounting_mode FROM supplier WHERE id = ?');
        $head->execute([$supplierId]);
        $firm = $head->fetch(PDO::FETCH_ASSOC);
        $row = [
            'supplier_id' => $supplierId, 'company' => (string) ($firm['company_name'] ?? ''), 'status' => 'nothing', 'reason' => null,
            'documents' => [], 'saldo_as_of' => null, 'saldo' => [],
            'totals' => ['converted' => 0, 'converted_czk' => 0.0, 'posted' => 0, 'unposted' => 0, 'linked' => 0,
                'refused' => 0, 'refused_czk' => 0.0, 'posted_kept' => 0, 'vat_kept' => 0],
        ];
        if ($firm === false) {
            return ['status' => 'skipped', 'reason' => 'Firma neexistuje.'] + $row;
        }
        if ((string) $firm['accounting_mode'] !== 'double_entry') {
            return ['status' => 'skipped', 'reason' => 'Daňová evidence: doklady se neúčtují, přijatá faktura z ostatního závazku je správně.'] + $row;
        }
        [$periodId, $asOf] = $this->saldoPeriod($supplierId);
        $row['saldo_as_of'] = $asOf;
        // Nanečisto se vrací celá firma; v cizí transakci (testy) savepointem.
        $own = !$pdo->inTransaction();
        $entries = [];
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT pohoda_other_liability_firm');
        try {
            $before = $this->saldoDifferences($supplierId, $periodId, $asOf);
            $index = $this->entryIndex($supplierId);
            $numbers = $this->commitmentNumbers($supplierId);
            foreach ($this->candidates($supplierId) as $c) {
                $hasVat = abs((float) $c['total_vat']) >= 0.005 || (int) $c['vat_items'] > 0;
                if ((int) $c['posted'] === 1) {
                    if ($hasVat || !$includePosted) {
                        $row['totals'][$hasVat ? 'vat_kept' : 'posted_kept']++;
                        continue;
                    }
                }
                $doc = $this->document($c);
                try {
                    $plan = $hasVat ? $this->planLink($supplierId, $c, $index, $numbers) : $this->planConversion($supplierId, $c, $index, $numbers);
                    if ($plan === null) {
                        $row['totals']['vat_kept']++;
                        continue;
                    }
                    $pdo->exec('SAVEPOINT pohoda_other_liability');
                    try {
                        $doc = array_replace($doc, $plan['action'] === 'link'
                            ? $this->applyLink($supplierId, $c, $plan, $userId)
                            : $this->applyConversion($supplierId, $c, $plan, $userId));
                        $pdo->exec('RELEASE SAVEPOINT pohoda_other_liability');
                    } catch (PohodaOtherLiabilityRefused | OtherItemException $e) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_other_liability');
                        $pdo->exec('RELEASE SAVEPOINT pohoda_other_liability');
                        throw $e instanceof PohodaOtherLiabilityRefused ? $e : new PohodaOtherLiabilityRefused('Úhradu nejde přiřadit ostatní položce: ' . $e->getMessage());
                    }
                    $doc['state'] = 'change';
                    if ($plan['action'] === 'link') {
                        $row['totals']['linked']++;
                    } else {
                        $row['totals']['converted']++;
                        $row['totals']['converted_czk'] += $doc['amount'];
                        $row['totals'][$plan['entry_id'] !== null ? 'posted' : 'unposted']++;
                    }
                } catch (PohodaOtherLiabilityRefused $e) {
                    $doc['state'] = 'refused';
                    $doc['reason'] = $e->getMessage();
                    $row['totals']['refused']++;
                    $row['totals']['refused_czk'] += $doc['amount'];
                }
                $row['documents'][] = $doc;
            }
            $after = $this->saldoDifferences($supplierId, $periodId, $asOf);
            foreach (self::SALDO_ACCOUNTS as $code) {
                $row['saldo'][$code] = ['before' => $before[$code] ?? null, 'after' => $after[$code] ?? null];
            }
            $changed = $row['totals']['converted'] + $row['totals']['linked'] > 0;
            if ($commit && $changed) {
                $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT pohoda_other_liability_firm');
            } elseif ($own) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_other_liability_firm');
                $pdo->exec('RELEASE SAVEPOINT pohoda_other_liability_firm');
            }
            if ($changed) {
                $row['status'] = $commit ? 'repaired' : 'would_repair';
            }
            $entries = $commit && $own ? array_values(array_filter(array_column($row['documents'], 'entry_id'))) : [];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own && !TransactionFailure::aborted($e) && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_other_liability_firm');
                $pdo->exec('RELEASE SAVEPOINT pohoda_other_liability_firm');
            }
            throw $e;
        }
        if ($entries !== []) {
            $this->refreshDescriptions($supplierId, $entries, $row);
        }
        $row['totals']['converted_czk'] = round((float) $row['totals']['converted_czk'], 2);
        $row['totals']['refused_czk'] = round((float) $row['totals']['refused_czk'], 2);
        return $row;
    }

    /**
     * Přijaté faktury z ostatních závazků podle mapy převodu, s příznakem vlastního zaúčtování.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_id, pi.issue_date, pi.due_date, pi.tax_date, pi.booked_at, pi.paid_at, pi.status,
                    pi.document_kind, pi.total_with_vat, pi.total_vat, pi.amount_to_pay, pi.project_id, pi.note_above_items,
                    pi.payment_variable_symbol, pi.payment_account_number, pi.payment_bank_code, pi.pdf_path, pi.source_path, cur.code AS currency,
                    (SELECT pim.agenda_year FROM pohoda_imports pim WHERE pim.id = m.run_id AND pim.supplier_id = m.supplier_id) AS agenda_year,
                    cl.company_name AS vendor_name, m.pohoda_key,
                    (SELECT COUNT(*) FROM purchase_invoice_items it WHERE it.purchase_invoice_id = pi.id AND it.total_vat <> 0) AS vat_items,
                    " . DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id', otherItemCover: false) . " AS posted,
                    " . PurchaseSettledExpr::settled('pi') . " AS settled
               FROM pohoda_import_map m
               JOIN purchase_invoices pi ON pi.id = m.target_id AND pi.supplier_id = m.supplier_id
               JOIN currencies cur ON cur.id = pi.currency_id
               LEFT JOIN clients cl ON cl.id = pi.vendor_id
              WHERE m.supplier_id = ? AND m.kind = 'purchase_invoice' AND m.pohoda_key LIKE 'commitment|%'
                AND pi.status NOT IN ('draft', 'cancelled')
              ORDER BY pi.issue_date, pi.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function document(array $c): array
    {
        return [
            'id' => (int) $c['id'], 'document_no' => (string) $c['varsymbol'], 'issue_date' => (string) $c['issue_date'],
            'title' => (string) ($c['note_above_items'] ?? ''), 'amount' => round((float) $c['total_with_vat'], 2),
            'status' => (string) $c['status'], 'action' => null, 'account' => null, 'entry_id' => null,
            'other_item_id' => null, 'payments' => 0, 'state' => null, 'reason' => null,
        ];
    }

    /**
     * Převod přijaté faktury bez DPH na ostatní závazek: zápis, účet a úhrady.
     *
     * @param array<string,mixed> $c
     * @param array<string,array<string,list<array{id:int,date:string}>>> $index
     * @param array<string,true> $numbers
     * @return array<string,mixed>
     */
    private function planConversion(int $supplierId, array $c, array $index, array $numbers): array
    {
        $id = (int) $c['id'];
        $amount = round((float) $c['total_with_vat'], 2);
        if ((string) $c['currency'] !== 'CZK') {
            throw new PohodaOtherLiabilityRefused('Doklad je v cizí měně.');
        }
        if ($amount < 0.01 || (string) $c['document_kind'] !== 'invoice') {
            throw new PohodaOtherLiabilityRefused('Doklad nemá kladnou částku (dobropis či nulový doklad).');
        }
        if ($c['project_id'] !== null) {
            throw new PohodaOtherLiabilityRefused('Doklad je přiřazený k projektu.');
        }
        if (($c['pdf_path'] ?? '') !== '' || ($c['source_path'] ?? '') !== '') {
            throw new PohodaOtherLiabilityRefused('Doklad má připojený soubor (PDF nebo zdroj); převeďte ho ručně.');
        }
        $this->assertNoDependents($supplierId, $id);
        if ($this->inVatReturn($supplierId, $id, (string) $c['issue_date'])) {
            throw new PohodaOtherLiabilityRefused('Doklad je podle evidence DPH v přiznání; zůstává přijatou fakturou.');
        }
        $number = (string) $c['varsymbol'];
        $entryId = null;
        if ((int) $c['posted'] === 1) {
            $own = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'purchase_invoice'
                AND source_id = ? AND reversed_by IS NULL AND posted_at IS NOT NULL");
            $own->execute([$supplierId, $id]);
            $ids = array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN));
            if (count($ids) !== 1) {
                throw new PohodaOtherLiabilityRefused('Doklad nemá právě jeden vlastní zápis v deníku.');
            }
            $entryId = $ids[0];
        } else {
            $entryId = $this->sourceEntry($supplierId, $c, $index, $numbers);
        }
        $this->assertNoForeignEntries($supplierId, $id, $entryId);
        $posting = null;
        $entryDate = null;
        if ($entryId !== null) {
            [$posting, $entryDate] = $this->entryPosting($supplierId, $entryId, $amount);
            if ($posting === null) {
                throw new PohodaOtherLiabilityRefused('Zápis dokladu v deníku neodpovídá jednomu účtu závazku proti protiúčtům.');
            }
        }
        $payments = $this->payments($supplierId, $id);
        $account = $posting['account'] ?? $this->paymentAccount($supplierId, $id);
        if ($account !== null && MigratedOtherItemPolicy::moduleOwned($account)) {
            throw new PohodaOtherLiabilityRefused("Závazek je na účtu {$account}, jehož předpisy vlastní daňový modul; ponechte ho nebo vyřešte ručně.");
        }
        $dates = [(string) $c['issue_date'], $c['tax_date'], $c['booked_at'], $entryDate, ...array_column($payments, 'date')];
        foreach ($dates as $d) {
            $reason = $this->locks->reason($supplierId, $d === null ? null : (string) $d);
            if ($reason !== null) {
                throw new PohodaOtherLiabilityRefused($reason);
            }
        }
        $paid = (string) $c['status'] === 'paid' ? $amount : min($amount, max(0.0, round((float) $c['settled'], 2)));
        return [
            'action' => $entryId !== null ? 'other_item_posted' : 'other_item', 'entry_id' => $entryId, 'entry_date' => $entryDate,
            'account' => $account, 'posting' => $posting, 'payments' => $payments, 'paid' => $paid, 'amount' => $amount,
        ];
    }

    /**
     * Přijatá faktura s DPH bez zápisu, jejíž zápis nese původní číslo přečíslovaného dokladu.
     *
     * @param array<string,mixed> $c
     * @param array<string,array<string,list<array{id:int,date:string}>>> $index
     * @param array<string,true> $numbers
     * @return array<string,mixed>|null null = doklad nemá co navázat
     */
    private function planLink(int $supplierId, array $c, array $index, array $numbers): ?array
    {
        if ((int) $c['posted'] === 1) {
            return null;
        }
        $entryId = $this->sourceEntry($supplierId, $c, $index, $numbers);
        if ($entryId === null) {
            return null;
        }
        $sum = $this->db->pdo()->prepare("SELECT e.entry_date, COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 0)
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            WHERE e.id = ? AND e.supplier_id = ? GROUP BY e.entry_date");
        $sum->execute([$entryId, $supplierId]);
        [$entryDate, $credit] = $sum->fetch(PDO::FETCH_NUM) ?: [null, 0];
        if (abs((float) $credit - (float) $c['total_with_vat']) >= 0.005) {
            throw new PohodaOtherLiabilityRefused('Zápis s původním číslem dokladu nemá částku dokladu.');
        }
        $reason = $this->locks->reason($supplierId, $entryDate === null ? null : (string) $entryDate);
        if ($reason !== null) {
            throw new PohodaOtherLiabilityRefused($reason);
        }
        return ['action' => 'link', 'entry_id' => $entryId, 'entry_date' => $entryDate];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function applyLink(int $supplierId, array $c, array $plan, ?int $userId): array
    {
        $id = (int) $c['id'];
        $this->linker->attach($supplierId, $userId, 'purchase_invoice', 'manual', 'purchase_invoice', $id, [(int) $plan['entry_id']]);
        $owner = $this->db->pdo()->prepare("SELECT 1 FROM journal_entries WHERE id = ? AND supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?");
        $owner->execute([(int) $plan['entry_id'], $supplierId, $id]);
        if ($owner->fetchColumn() === false) {
            throw new PohodaOtherLiabilityRefused('Zápis s původním číslem dokladu už vlastní jiný doklad.');
        }
        $this->activity->log(self::AUDIT_ACTION, $userId, 'purchase_invoice', $id, [
            'action' => 'link', 'document_no' => (string) $c['varsymbol'], 'journal_entry_id' => (int) $plan['entry_id'],
        ], supplierId: $supplierId);
        return ['action' => 'link', 'entry_id' => (int) $plan['entry_id']];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function applyConversion(int $supplierId, array $c, array $plan, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $id = (int) $c['id'];
        $number = (string) $c['varsymbol'];
        $entryId = $plan['entry_id'];
        $posting = $plan['posting'];
        $symbol = trim((string) ($c['payment_variable_symbol'] ?? ''));
        $note = 'Převzato z POHODY, doklad ' . $number . '. Ostatní závazek, který starší převod založil jako přijatou fakturu'
            . ($entryId !== null ? '; převzal svůj zápis z deníku POHODY.'
                : '; v deníku POHODY nemá vlastní zápis (předpis nese jiný doklad nebo počáteční stav), položka se proto neúčtuje.');
        $dup = $pdo->prepare('SELECT 1 FROM other_items WHERE supplier_id = ? AND document_no = ?');
        $dup->execute([$supplierId, mb_substr($number, 0, 50)]);
        if ($dup->fetchColumn() !== false) {
            throw new PohodaOtherLiabilityRefused('Číslo dokladu už má jiná ostatní pohledávka nebo závazek.');
        }
        $title = trim((string) ($c['note_above_items'] ?? ''));
        $pdo->prepare(
            'INSERT INTO other_items
              (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
               currency, amount, amount_czk, variable_symbol, counterparty_account, counterparty_bank_code, account_code, counter_account_code,
               note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
             VALUES (?, \'payable\', \'other\', ?, ?, ?, ?, ?, ?, \'CZK\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, mb_substr($title !== '' ? $title : $number, 0, 255), $c['vendor_id'],
            $c['vendor_name'] !== null ? mb_substr((string) $c['vendor_name'], 0, 190) : null,
            $c['issue_date'], $plan['entry_date'] ?? ($c['booked_at'] !== null ? substr((string) $c['booked_at'], 0, 10) : $c['issue_date']),
            $c['due_date'] ?? $c['issue_date'], number_format($plan['amount'], 2, '.', ''), number_format($plan['amount'], 2, '.', ''),
            preg_match('/^\d{1,20}$/', $symbol) === 1 ? $symbol : null,
            ($c['payment_account_number'] ?? '') !== '' ? mb_substr((string) $c['payment_account_number'], 0, 34) : null,
            ($c['payment_bank_code'] ?? '') !== '' ? mb_substr((string) $c['payment_bank_code'], 0, 10) : null,
            $plan['account'], $posting !== null && count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null,
            $note, $entryId !== null ? 'posted' : 'confirmed', mb_substr($number, 0, 50), $entryId,
            $entryId !== null ? $this->postedAt($supplierId, $entryId) : null, $userId, $userId,
        ]);
        $itemId = (int) $pdo->lastInsertId();
        if ($posting !== null && count($posting['lines']) > 1) {
            $line = $pdo->prepare('INSERT INTO other_item_posting_lines (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($posting['lines'] as $position => $l) {
                $line->execute([$supplierId, $itemId, $position + 1, $l['account_code'], number_format($l['amount'], 2, '.', '')]);
            }
        }
        if ($entryId !== null) {
            $owner = $pdo->prepare("UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                WHERE id = ? AND supplier_id = ? AND ((source_type = 'purchase_invoice' AND source_id = ?) OR (source_type = 'manual' AND source_id IS NULL))");
            $owner->execute([$itemId, $entryId, $supplierId, $id]);
            if ($owner->rowCount() !== 1) {
                throw new PohodaOtherLiabilityRefused('Zápis dokladu mezitím převzal jiný doklad.');
            }
        }
        $pdo->prepare("DELETE FROM journal_document_postings WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id = ?")->execute([$supplierId, $id]);
        $pdo->prepare("DELETE FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id = ?")->execute([$supplierId, $id]);

        // Úhrady: párování faktury se uvolní, po smazání faktury se přiřadí položce.
        foreach ($plan['payments'] as $p) {
            if ($p['kind'] === 'bank') {
                $pdo->prepare('DELETE FROM payment_matches WHERE id = ? AND supplier_id = ?')->execute([$p['match_id'], $supplierId]);
                $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', match_reason = NULL, matched_at = NULL, matched_by = NULL,
                    matched_invoice_id = NULL WHERE id = ?")->execute([$p['source_id']]);
            } else {
                $pdo->prepare("UPDATE cash_documents SET purchase_invoice_id = NULL, purpose = 'other', settled_document_amount = NULL
                    WHERE id = ? AND supplier_id = ?")->execute([$p['source_id'], $supplierId]);
            }
        }
        $key = (string) $c['pohoda_key'];
        $map = $pdo->prepare("UPDATE pohoda_import_map SET kind = 'other_item', target_id = ? WHERE supplier_id = ? AND kind = 'purchase_invoice' AND pohoda_key = ? AND target_id = ?");
        $map->execute([$itemId, $supplierId, $key, $id]);
        if ($map->rowCount() !== 1) {
            throw new PohodaOtherLiabilityRefused('Mapa převodu dokladu se mezitím změnila.');
        }
        $paymentKeys = $pdo->prepare("SELECT pohoda_key, target_id FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'payment' AND pohoda_key LIKE ?");
        $paymentKeys->execute([$supplierId, 'purchase_invoice|' . $id . '|%']);
        $paymentMap = $paymentKeys->fetchAll(PDO::FETCH_ASSOC);

        $allocations = ['bank' => [], 'cash' => []];
        foreach ($plan['payments'] as $p) {
            $this->otherItems->allocate($supplierId, $itemId, [
                $p['kind'] === 'bank' ? 'bank_transaction_id' : 'cash_document_id' => $p['source_id'], 'amount' => $p['amount'],
            ], $userId);
            $column = $p['kind'] === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
            $alloc = $pdo->prepare("SELECT id FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? AND {$column} = ?");
            $alloc->execute([$supplierId, $itemId, $p['source_id']]);
            $allocations[$p['kind']][$p['kind'] === 'bank' ? $p['match_id'] : $p['source_id']] = (int) $alloc->fetchColumn();
        }
        // Vazby úhrad v mapě převodu: opakovaný převod je pozná jako přiřazené úhrady položky.
        $repoint = $pdo->prepare("UPDATE pohoda_import_map SET pohoda_key = ?, target_id = ? WHERE supplier_id = ? AND kind = 'payment' AND pohoda_key = ?");
        $drop = $pdo->prepare("DELETE FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'payment' AND pohoda_key = ?");
        foreach ($paymentMap as $m) {
            $liq = substr((string) $m['pohoda_key'], strlen('purchase_invoice|' . $id . '|'));
            // Cíl vazby úhrady je u banky id párování, u pokladny id pokladního dokladu; shodné
            // id obou druhů nejde rozlišit a vazba se zahodí (opakovaný převod úhradu jen ohlásí).
            $bank = $allocations['bank'][(int) $m['target_id']] ?? null;
            $cash = $allocations['cash'][(int) $m['target_id']] ?? null;
            $target = $bank !== null && $cash !== null ? null : ($bank ?? $cash);
            if ($target === null) {
                $drop->execute([$supplierId, $m['pohoda_key']]);
            } else {
                $repoint->execute(['other_item|' . $itemId . '|' . $liq, $target, $supplierId, $m['pohoda_key']]);
            }
        }
        $allocated = round(array_sum(array_column($plan['payments'], 'amount')), 2);
        $outside = max(0.0, round($plan['paid'] - $allocated, 2));
        if ($outside >= 0.01) {
            $pdo->prepare('UPDATE other_items SET settled_outside_amount = ? WHERE id = ? AND supplier_id = ?')
                ->execute([number_format($outside, 2, '.', ''), $itemId, $supplierId]);
        }
        // Faktura se maže až nakonec: po smazání (kaskáda na položky s FULLTEXT indexem) už
        // nic nehází a savepoint dokladu se přes ni nevrací.
        $pdo->prepare('DELETE FROM purchase_invoice_inbox_dismissed WHERE supplier_id = ? AND purchase_invoice_id = ?')->execute([$supplierId, $id]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ? AND supplier_id = ?')->execute([$id, $supplierId]);
        $this->activity->log(self::AUDIT_ACTION, $userId, 'other_item', $itemId, [
            'action' => $plan['action'], 'document_no' => $number, 'purchase_invoice_id' => $id, 'journal_entry_id' => $entryId,
            'account_code' => $plan['account'], 'amount' => $plan['amount'], 'payments' => count($plan['payments']), 'settled_outside' => $outside,
        ], supplierId: $supplierId);
        return [
            'action' => $plan['action'], 'account' => $plan['account'], 'entry_id' => $entryId, 'other_item_id' => $itemId,
            'payments' => count($plan['payments']),
        ];
    }

    /**
     * Popis převzatých zápisů podle nového vlastníka, až po zápisu opravy. Popis je komfort:
     * jeho selhání opravu nevrací, jen se ohlásí.
     *
     * @param list<int> $entryIds
     * @param array<string,mixed> $row
     */
    private function refreshDescriptions(int $supplierId, array $entryIds, array &$row): void
    {
        try {
            (new JournalDescriptionRebuilder($this->db, new JournalDescriptionBuilder($this->db)))
                ->rebuild(['supplier_id' => $supplierId, 'entry_ids' => array_map('intval', $entryIds)]);
        } catch (\RuntimeException | \PDOException $e) {
            $row['reason'] = 'Popisy převzatých zápisů se nepodařilo doplnit: ' . $e->getMessage()
                . ' Doplníš je skriptem api/bin/rebuild-journal-descriptions.php.';
        }
    }

    private function assertNoDependents(int $supplierId, int $id): void
    {
        foreach (self::DEPENDENTS as $label => [$table, $condition]) {
            $params = array_fill(0, substr_count($condition, '?'), $id);
            $supplierColumn = $table === 'payment_order_items' ? null : 'supplier_id';
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE " . ($supplierColumn !== null ? "{$supplierColumn} = ? AND " : '') . "{$condition} LIMIT 1");
            $stmt->execute($supplierColumn !== null ? [$supplierId, ...$params] : $params);
            if ($stmt->fetchColumn() !== false) {
                throw new PohodaOtherLiabilityRefused("Na doklad navazuje {$label}; převeďte ho ručně.");
            }
        }
    }

    /** Doklad má v evidenci DPH řádek přiznání (základ nebo daň). */
    private function inVatReturn(int $supplierId, int $id, string $issueDate): bool
    {
        $year = (int) substr($issueDate, 0, 4);
        foreach ($this->vat->iterateReturnRows($supplierId, sprintf('%04d-01-01', $year - 1), sprintf('%04d-12-31', $year + 1), false, ['purchase' => [$id]]) as $r) {
            $amount = abs((float) ($r['base_czk'] ?? 0)) >= 0.005 || abs((float) ($r['vat_czk'] ?? 0)) >= 0.005;
            $line = ($r['dphdp3_line'] ?? null) !== null || ($r['dphdp3_line_secondary'] ?? null) !== null || ($r['kh_section'] ?? null) !== null;
            if ($amount && $line && empty($r['zero_rate_outside_return'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Úhrady faktury z banky a pokladny, které jde přesunout na ostatní položku.
     *
     * @return list<array{kind:'bank'|'cash',match_id:?int,source_id:int,amount:float,date:string}>
     */
    private function payments(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $bank = $pdo->prepare(
            "SELECT pm.id, pm.bank_transaction_id, pm.amount, bt.amount AS tx_amount, DATE(bt.posted_at) AS paid_on, UPPER(COALESCE(bt.currency, 'CZK')) AS currency,
                    (SELECT COUNT(*) FROM payment_matches o WHERE o.bank_transaction_id = pm.bank_transaction_id AND o.id <> pm.id) AS others,
                    (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.bank_transaction_id = pm.bank_transaction_id) AS issued,
                    (SELECT COUNT(*) FROM other_item_allocations a WHERE a.bank_transaction_id = pm.bank_transaction_id) AS allocated
               FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?"
        );
        $bank->execute([$supplierId, $id]);
        foreach ($bank->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['others'] > 0 || (int) $r['issued'] > 0 || (int) $r['allocated'] > 0) {
                throw new PohodaOtherLiabilityRefused('Bankovní úhrada dokladu hradí i jiné doklady.');
            }
            if ((string) $r['currency'] !== 'CZK' || (float) $r['tx_amount'] >= 0) {
                throw new PohodaOtherLiabilityRefused('Bankovní úhrada dokladu není výdaj v Kč.');
            }
            $out[] = ['kind' => 'bank', 'match_id' => (int) $r['id'], 'source_id' => (int) $r['bank_transaction_id'],
                'amount' => round(abs((float) $r['amount']), 2), 'date' => (string) $r['paid_on']];
        }
        $cash = $pdo->prepare(
            "SELECT cd.id, cd.total_amount, cd.doc_type, cd.issue_date, cd.status, UPPER(cd.currency_code) AS currency,
                    (SELECT COUNT(*) FROM cash_document_vat_lines v WHERE v.cash_document_id = cd.id AND v.vat_amount <> 0) AS vat_lines
               FROM cash_documents cd WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ?"
        );
        $cash->execute([$supplierId, $id]);
        foreach ($cash->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string) $r['doc_type'] !== 'out' || (string) $r['status'] !== 'posted' || (string) $r['currency'] !== 'CZK' || (int) $r['vat_lines'] > 0) {
                throw new PohodaOtherLiabilityRefused('Pokladní úhrada dokladu není výdaj v Kč bez DPH.');
            }
            if ($this->cashSharedByOtherDocument($supplierId, $id, (int) $r['id'])) {
                throw new PohodaOtherLiabilityRefused('Pokladní doklad úhrady hradí i jiné doklady.');
            }
            $out[] = ['kind' => 'cash', 'match_id' => null, 'source_id' => (int) $r['id'],
                'amount' => round(abs((float) $r['total_amount']), 2), 'date' => (string) $r['issue_date']];
        }
        return $out;
    }

    /** Účet závazku podle zaúčtování úhrad dokladu (strana MD na účtu závazků), jen jednoznačný. */
    private function paymentAccount(int $supplierId, int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT a.account_code FROM (
                    SELECT e.id FROM payment_matches pm
                      JOIN journal_entries e ON e.supplier_id = pm.supplier_id AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                       AND e.reversed_by IS NULL AND e.posted_at IS NOT NULL
                     WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?
                    UNION
                    SELECT cd.journal_entry_id FROM cash_documents cd
                     WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND cd.journal_entry_id IS NOT NULL
                ) pe
               JOIN journal_entry_lines l ON l.entry_id = pe.id AND l.supplier_id = ? AND l.side = 'debit'
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE a.account_type = 'liability'"
        );
        $stmt->execute([$supplierId, $id, $supplierId, $id, $supplierId]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($codes) === 1 ? (string) $codes[0] : null;
    }

    /**
     * Kontace zápisu jako ostatního závazku ({@see MigratedOtherItemPolicy::postingLines()}).
     *
     * @return array{0:?array{account:string,lines:list<array{account_code:string,amount:float}>},1:?string}
     */
    private function entryPosting(int $supplierId, int $entryId, float $amount): array
    {
        $head = $this->db->pdo()->prepare('SELECT entry_date FROM journal_entries WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL AND posted_at IS NOT NULL');
        $head->execute([$entryId, $supplierId]);
        $date = $head->fetchColumn();
        if ($date === false) {
            return [null, null];
        }
        $lines = $this->db->pdo()->prepare(
            'SELECT l.side, l.amount, a.account_code, a.account_type FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $supplierId]);
        return [MigratedOtherItemPolicy::postingLines($lines->fetchAll(PDO::FETCH_ASSOC), 'payable', $amount), (string) $date];
    }

    private function postedAt(int $supplierId, int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT posted_at FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Volné (nikým nevlastněné) zápisy zdroje Ostatní závazky převedené z POHODY podle čísla.
     *
     * @return array<string,list<array{year:int,id:int,date:string}>>
     */
    private function entryIndex(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT m.pohoda_key, e.id FROM pohoda_import_map m
               JOIN journal_entries e ON e.id = m.target_id AND e.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.kind = 'journal_entry' AND m.pohoda_key LIKE ?
                AND e.source_type = 'manual' AND e.source_id IS NULL AND e.reversed_by IS NULL AND e.posted_at IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = e.supplier_id AND oi.journal_entry_id = e.id)"
        );
        $stmt->execute([$supplierId, '%|' . PohodaJournal::COMMITMENT . '|%']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $parts = explode('|', (string) $r['pohoda_key'], 4);
            if (count($parts) === 4 && $parts[1] === PohodaJournal::COMMITMENT) {
                $out[$parts[2]][] = ['year' => (int) $parts[0], 'id' => (int) $r['id'], 'date' => $parts[3]];
            }
        }
        return $out;
    }

    /** @return array<string,true> čísla ostatních závazků v mapě převodu */
    private function commitmentNumbers(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT pohoda_key FROM pohoda_import_map WHERE supplier_id = ? AND kind IN ('purchase_invoice', 'other_item', 'tax_advance') AND pohoda_key LIKE 'commitment|%'");
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $out[explode('|', (string) $key, 3)[1] ?? ''] = true;
        }
        return $out;
    }

    /**
     * Volný zápis dokladu: se stejným číslem (všechny zápisy čísla jsou jeden doklad jen
     * tehdy, když je jediný), jinak s původním číslem přečíslovaného dokladu. Zápisy čísla
     * z jiné agendy patří jinému dokladu stejně jako při převodu ({@see DocumentLinker::agendaEntries()});
     * rok agendy je rok běhu převodu, který doklad založil.
     *
     * @param array<string,mixed> $c
     * @param array<string,list<array{year:int,id:int,date:string}>> $index
     * @param array<string,true> $numbers
     */
    private function sourceEntry(int $supplierId, array $c, array $index, array $numbers): ?int
    {
        $index = $this->agendaIndex($supplierId, $index, $c['agenda_year'] !== null ? (int) $c['agenda_year'] : null);
        $number = (string) $c['varsymbol'];
        $exact = $index[$number] ?? [];
        if (count($exact) > 1) {
            throw new PohodaOtherLiabilityRefused('Číslo dokladu má v deníku víc zápisů; přiřaďte zápis ručně.');
        }
        if ($exact !== []) {
            return $exact[0]['id'];
        }
        // Datum zápisu je datum zaúčtování; doklad nese vystavení (klíč mapy), DUZP i zaúčtování.
        $dates = array_fill_keys(array_filter([self::keyDate((string) $c['pohoda_key']), (string) $c['issue_date'],
            substr((string) ($c['tax_date'] ?? ''), 0, 10), substr((string) ($c['booked_at'] ?? ''), 0, 10)]), true);
        $key = PohodaJournal::numberKey($number);
        $found = 0;
        foreach ($index as $entryNumber => $entries) {
            if ((string) $entryNumber !== $number && !isset($numbers[(string) $entryNumber]) && PohodaJournal::numberKey((string) $entryNumber) === $key) {
                $found += count(array_filter($entries, static fn (array $e): bool => isset($dates[$e['date']])));
            }
        }
        if ($found > 1) {
            throw new PohodaOtherLiabilityRefused('Přečíslovaný doklad má v deníku víc možných zápisů; přiřaďte zápis ručně.');
        }
        $entries = PohodaJournal::renumberedEntries($index, $number, array_keys($dates), $numbers);
        return $entries === [] ? null : $entries[0]['id'];
    }

    /** @var array<string,array<string,list<array{year:int,id:int,date:string}>>> */
    private array $agendaIndexes = [];

    /**
     * Zápisy po číslech zúžené na agendu roku `$year`, jak je vidí převod.
     *
     * @param array<string,list<array{year:int,id:int,date:string}>> $index
     * @return array<string,list<array{year:int,id:int,date:string}>>
     */
    private function agendaIndex(int $supplierId, array $index, ?int $year): array
    {
        if ($year === null) {
            return $index;
        }
        $cacheKey = $supplierId . '|' . $year . '|' . count($index);
        if (!isset($this->agendaIndexes[$cacheKey])) {
            $yearStart = $this->periods->fiscalCalendar($supplierId)->periodStart($year);
            $this->agendaIndexes = [$cacheKey => array_map(static fn (array $entries): array =>
                DocumentLinker::agendaEntries($entries, $year, $yearStart), $index)];
        }
        return $this->agendaIndexes[$cacheKey];
    }

    /**
     * Zápis se zdrojem faktury nebo vazba skupiny zaúčtování mimo převzatý zápis (storno,
     * nezaúčtovaný zápis, přeúčtování): doklad má v deníku víc, než oprava umí převzít.
     */
    private function assertNoForeignEntries(int $supplierId, int $id, ?int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT (SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ? AND id <> ?)
                  + (SELECT COUNT(*) FROM journal_document_postings WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id = ? AND entry_id <> ?)"
        );
        $stmt->execute([$supplierId, $id, $entryId ?? 0, $supplierId, $id, $entryId ?? 0]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new PohodaOtherLiabilityRefused('Doklad má v deníku další zápis (storno, nezaúčtovaný nebo přeúčtovaný zápis); převeďte ho ručně.');
        }
    }

    /**
     * Pokladní doklad hradí podle mapy převodu i jiný doklad: vazba úhrady jiného dokladu
     * míří na tentýž pokladní doklad. Cíl vazby je u banky id párování a u ostatní položky
     * id přiřazení, takové shody id se nepočítají.
     */
    private function cashSharedByOtherDocument(int $supplierId, int $id, int $cashId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT pohoda_key FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'payment' AND target_id = ? AND pohoda_key NOT LIKE ?");
        $stmt->execute([$supplierId, $cashId, 'purchase_invoice|' . $id . '|%']);
        $match = $pdo->prepare('SELECT 1 FROM payment_matches WHERE id = ? AND supplier_id = ? AND (invoice_id = ? OR purchase_invoice_id = ?)');
        $allocation = $pdo->prepare('SELECT 1 FROM other_item_allocations WHERE id = ? AND supplier_id = ? AND other_item_id = ? AND cash_document_id IS NULL');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            [$doc, $docId] = array_pad(explode('|', (string) $key, 3), 2, '');
            if (in_array($doc, ['invoice', 'purchase_invoice'], true)) {
                $match->execute([$cashId, $supplierId, (int) $docId, (int) $docId]);
                if ($match->fetchColumn() !== false) {
                    continue;
                }
            } elseif ($doc === 'other_item') {
                $allocation->execute([$cashId, $supplierId, (int) $docId]);
                if ($allocation->fetchColumn() !== false) {
                    continue;
                }
            } elseif ($doc === 'tax_advance') {
                continue;
            }
            return true;
        }
        return false;
    }

    private static function keyDate(string $key): ?string
    {
        $parts = explode('|', $key, 3);
        return count($parts) === 3 && $parts[2] !== '' ? $parts[2] : null;
    }

    /**
     * Účetní období pro výpis saldokonta: období dneška, jinak poslední založené.
     *
     * @return array{0:?int,1:?string}
     */
    private function saldoPeriod(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, ends_on FROM accounting_periods WHERE supplier_id = ?
            ORDER BY (starts_on <= CURDATE()) DESC, starts_on DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p === false) {
            return [null, null];
        }
        return [(int) $p['id'], min((string) $p['ends_on'], date('Y-m-d'))];
    }

    /** @return array<string,float> účet => rozdíl hlavní knihy a otevřených položek */
    private function saldoDifferences(int $supplierId, ?int $periodId, ?string $asOf): array
    {
        if ($periodId === null) {
            return [];
        }
        $out = [];
        foreach (self::SALDO_ACCOUNTS as $code) {
            try {
                foreach ($this->saldo->build($supplierId, $periodId, $asOf, $code)['accounts'] ?? [] as $block) {
                    $out[$code] = round(($out[$code] ?? 0.0) + (float) $block['difference'], 2);
                }
            } catch (ReportException) {
                // Saldokonto nad strop položek: rozdíl výpis neukáže.
            }
        }
        return $out;
    }
}
