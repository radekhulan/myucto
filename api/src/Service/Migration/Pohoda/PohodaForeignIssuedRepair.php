<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Closing\FxRevaluationService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\IssuedCurrencySnapshot;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

final class PohodaForeignIssuedRepair
{
    public const AUDIT_ACTION = 'invoice.pohoda_foreign_currency_repaired';
    public const PAYMENT_PENDING = 'převod měny po doložení úhrad a vazeb';
    public const PAST_PERIOD = 'past_period';

    private const NOTE_SQL = '%doklad v ___, převzat v Kč (%';
    private const NOTE_PATTERN = '/doklad v ([A-Z]{3}), převzat v Kč \(([^\r\n;]*)\)(?=;|\. K ruční kontrole:|$)/u';

    private readonly ForeignCurrencyTakeover $currencies;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceImporter $importer,
        private readonly MigrationCheckLocks $locks,
        private readonly FiledVatPeriodLookup $filed,
        private readonly ActivityLogger $activity,
        private readonly VatLedgerService $ledger,
        private readonly KontrolniHlaseniBuilder $kh,
        private readonly SaldoService $saldo,
        private readonly CashJournalRepository $cashJournal,
        private readonly StatsRecomputer $stats,
        private readonly PohodaIssuedPaymentConversion $payments,
        private readonly PohodaIssuedAdvanceGroups $advances,
        private readonly ClosingRepository $closing,
    ) {
        $this->currencies = new ForeignCurrencyTakeover($db, createMissingCurrency: true);
    }

    /** @return list<int> */
    public function affectedSuppliers(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT m.supplier_id
               FROM pohoda_import_map m JOIN invoices i ON i.id = m.target_id AND i.supplier_id = m.supplier_id
               JOIN currencies c ON c.id = i.currency_id
              WHERE m.kind = ? AND (m.pohoda_key LIKE 'issued%' OR (i.invoice_type = 'tax_document' AND m.pohoda_key LIKE 'internal|%')) AND i.exchange_rate IS NULL AND c.code = 'CZK'
                AND i.invoice_type IN ('invoice', 'proforma', 'credit_note', 'tax_document') AND i.note_below_items LIKE ?
              ORDER BY m.supplier_id"
        );
        $stmt->execute([PohodaImportRepository::KIND_INVOICE, self::NOTE_SQL]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function supplierIco(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return PartnerImporter::ico((string) $stmt->fetchColumn());
    }

    /**
     * @param PohodaExport|array<int,PohodaExport>|null $export Export, nebo exporty podle roku agendy.
     * @param list<int>|null $onlyIds Právě založené doklady při novém převodu.
     * @return list<array{id:int,document_no:string,issue_date:string,currency:string,status:string,reason:?string,
     *                    source_reason:string,source_year:?int,total_czk:float,rate:?float,total_foreign:?float,payments_converted:int}>
     */
    public function run(int $supplierId, PohodaExport|array|null $export, bool $commit, ?int $userId = null, ?array $onlyIds = null): array
    {
        $docs = $this->candidates($supplierId, $onlyIds);
        if ($docs === []) {
            return [];
        }
        $this->locks->reset();
        $start = $this->accountingStart($supplierId);
        $exports = [];
        foreach ($export instanceof PohodaExport ? [$export] : ($export ?? []) as $agenda) {
            $exports[$agenda->year] = $agenda;
        }
        $fallback = reset($exports) ?: null;
        $problems = array_map(fn (PohodaExport $e): ?string => $this->exportProblem($supplierId, $e), $exports);
        $exportFor = static fn (array $doc): ?PohodaExport => $exports[(int) $doc['agenda_year']] ?? $fallback;
        $pending = $out = [];
        foreach ($docs as $id => $doc) {
            $agenda = $exportFor($doc);
            [$status, $reason] = $this->eligibility($supplierId, $doc, $agenda, $agenda === null ? null : $problems[$agenda->year], $onlyIds !== null, $start);
            $out[$id] = [
                'id' => $id, 'document_no' => (string) $doc['varsymbol'], 'issue_date' => (string) $doc['issue_date'],
                'currency' => $doc['currency'], 'status' => $status, 'reason' => $reason, 'source_reason' => $doc['source_reason'],
                'source_year' => $doc['agenda_year'] !== null ? (int) $doc['agenda_year'] : null,
                'total_czk' => (float) $doc['total_with_vat'], 'rate' => null, 'total_foreign' => null, 'payments_converted' => 0,
            ];
            if ($status === 'convert') {
                $pending[$id] = $doc + ['export' => $agenda];
            }
        }
        if ($pending !== []) {
            $keys = [];
            foreach ($pending as $id => $doc) {
                $keys[$doc['export']->year][$id] = $doc['pohoda_key'];
            }
            foreach ($this->advances->families($supplierId, $pending) as $members) {
                foreach (array_diff_key($members, $pending) as $id => $doc) {
                    $year = (int) $doc['agenda_year'];
                    if ($doc['pohoda_key'] !== null && isset($exports[$year]) && $problems[$year] === null
                        && PartnerImporter::ico((string) $doc['agenda_ico']) === $exports[$year]->ico) {
                        $keys[$year][$id] = $doc['pohoda_key'];
                    }
                }
            }
            $sources = [];
            foreach ($keys as $year => $byId) {
                $records = $this->sources($supplierId, $exports[$year], array_values($byId));
                foreach ($byId as $id => $key) {
                    if (isset($records[$key])) {
                        $sources[$id] = $records[$key] + ['export' => $exports[$year], 'key' => $key];
                    }
                }
            }
            foreach ($this->apply($supplierId, $pending, $sources, $out, $commit, $userId, $onlyIds !== null) as $id => $result) {
                $out[$id] = array_replace($out[$id], $result);
            }
        }
        return array_values($out);
    }

    /** @return array<int,array<string,mixed>> */
    private function candidates(int $supplierId, ?array $onlyIds = null): array
    {
        if ($onlyIds === []) {
            return [];
        }
        $scope = $onlyIds === null ? '' : ' AND i.id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
        $unsupported = PohodaIssuedAdvanceGroups::unsupportedLinksSql();
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.client_id, i.project_id, i.invoice_type, i.issue_date, i.tax_date, i.effective_tax_date, i.due_date,
                    i.status, i.total_without_vat, i.total_vat, i.total_with_vat, i.rounding, i.advance_paid_amount,
                    i.amount_to_pay, i.paid_total, i.paid_at, i.sent_at, i.last_reminder_at, i.reminder_count, i.pdf_generated_at, i.pdf_path,
                    i.booked_at, i.prices_include_vat, i.parent_invoice_id, i.note_below_items, i.imported_pdf_path,
                    (i.import_total_with_vat_czk IS NOT NULL OR i.import_total_without_vat_czk IS NOT NULL OR i.import_total_vat_czk IS NOT NULL
                     OR i.import_amount_to_pay_czk IS NOT NULL OR i.import_advance_paid_czk IS NOT NULL) AS has_snapshot,
                    m.pohoda_key, r.agenda_ico, r.agenda_year,
                    (EXISTS (SELECT 1 FROM payment_matches p WHERE p.supplier_id = i.supplier_id AND p.invoice_id = i.id
                                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip
                                                 WHERE ip.bank_transaction_id = p.bank_transaction_id AND ip.invoice_id = i.id
                                                   AND ip.supplier_id = i.supplier_id))
                     OR EXISTS (SELECT 1 FROM bank_transactions b WHERE b.matched_invoice_id = i.id
                                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip
                                                 WHERE ip.bank_transaction_id = b.id AND ip.invoice_id = i.id
                                                   AND ip.supplier_id = i.supplier_id))
                     OR EXISTS (SELECT 1 FROM cash_documents p FORCE INDEX (idx_cashdoc_invoice) WHERE p.invoice_id = i.id AND p.supplier_id = i.supplier_id)
                     OR EXISTS (SELECT 1 FROM payment_events p WHERE p.supplier_id = i.supplier_id AND p.invoice_id = i.id)
                     OR EXISTS (SELECT 1 FROM payment_event_allocations p WHERE p.supplier_id = i.supplier_id AND p.invoice_id = i.id)) AS has_unsupported_payment,
                    (EXISTS (SELECT 1 FROM invoice_settlements p WHERE p.supplier_id = i.supplier_id AND p.doc_type = 'invoice' AND p.doc_id = i.id)
                     OR EXISTS (SELECT 1 FROM offset_agreement_items p WHERE p.supplier_id = i.supplier_id AND p.doc_type = 'invoice' AND p.doc_id = i.id)
                     OR EXISTS (SELECT 1 FROM credit_note_offsets p WHERE p.supplier_id = i.supplier_id AND p.doc_type = 'invoice' AND p.invoice_id = i.id)
                     OR EXISTS (SELECT 1 FROM credit_note_offsets p WHERE p.doc_type = 'invoice' AND p.credit_note_id = i.id AND p.supplier_id = i.supplier_id)
                     OR EXISTS (SELECT 1 FROM journal_settlement_allocations p WHERE p.supplier_id = i.supplier_id AND p.doc_type = 'invoice'
                                 AND p.doc_id = i.id AND p.kind = 'settlement')) AS has_settlement,
                    {$unsupported} AS has_unsupported_link,
                    (SELECT GROUP_CONCAT(DISTINCT e.entry_date) FROM journal_entries e FORCE INDEX (idx_je_supplier_source)
                      WHERE e.supplier_id = i.supplier_id AND e.source_type = 'invoice' AND e.source_id = i.id) AS journal_dates,
                    (SELECT GROUP_CONCAT(DISTINCT e.entry_date) FROM journal_entry_document_links l FORCE INDEX (idx_jedl_doc)
                       JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                      WHERE l.supplier_id = i.supplier_id AND l.doc_type = 'invoice' AND l.doc_id = i.id) AS linked_dates
               FROM pohoda_import_map m JOIN invoices i ON i.id = m.target_id AND i.supplier_id = m.supplier_id
               JOIN currencies c ON c.id = i.currency_id
          LEFT JOIN pohoda_imports r ON r.id = m.run_id AND r.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.kind = ? AND (m.pohoda_key LIKE 'issued%' OR (i.invoice_type = 'tax_document' AND m.pohoda_key LIKE 'internal|%')) AND i.exchange_rate IS NULL AND c.code = 'CZK'
                AND i.invoice_type IN ('invoice', 'proforma', 'credit_note', 'tax_document') AND i.note_below_items LIKE ?{$scope}
              ORDER BY i.issue_date, i.id"
        );
        $stmt->execute([$supplierId, PohodaImportRepository::KIND_INVOICE, self::NOTE_SQL]);
        $out = [];
        foreach ($stmt as $d) {
            if (preg_match(self::NOTE_PATTERN, (string) $d['note_below_items'], $match) !== 1) {
                continue;
            }
            $id = (int) $d['id'];
            $out[$id] = $d + ['currency' => $match[1], 'source_reason' => $match[2], 'multiple_keys' => isset($out[$id])];
        }
        return $out;
    }

    private function accountingStart(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(
                (SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ?),
                (SELECT MIN(entry_date) FROM journal_entries FORCE INDEX (idx_je_supplier_source)
                  WHERE supplier_id = ? AND source_type = 'opening' AND posted_at IS NOT NULL AND reversed_by IS NULL),
                (SELECT CONCAT(MIN(pohoda_key), '-01-01') FROM pohoda_import_map WHERE supplier_id = ? AND kind = ?)
            )"
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR]);
        return $stmt->fetchColumn() ?: null;
    }

    /** @return array{string,?string} */
    private function eligibility(int $supplierId, array $doc, ?PohodaExport $export, ?string $exportProblem, bool $newImport, ?string $start): array
    {
        if ($start !== null && max($doc['issue_date'], $doc['tax_date'] ?? '', $doc['effective_tax_date'] ?? '') < $start) {
            return [self::PAST_PERIOD, sprintf('Rozhodná data dokladu jsou před začátkem účetnictví (%s).', $start)];
        }
        $dates = array_unique(array_filter([
            $doc['issue_date'], $doc['tax_date'], $doc['effective_tax_date'], $doc['booked_at'], $doc['paid_at'],
            ...explode(',', (string) $doc['journal_dates']), ...explode(',', (string) $doc['linked_dates']),
        ]));
        foreach ($dates as $date) {
            $reason = $this->locks->reason($supplierId, $date, true);
            if ($reason !== null) {
                return ['locked', $reason];
            }
        }
        if (($date = $doc['effective_tax_date']) !== null && ($filed = $this->filed->describe($supplierId, $date, includeSummaryReport: true)) !== null) {
            return ['locked', sprintf('Leží v podaném období DPH (%s).', $filed)];
        }
        $blocked = match (true) {
            (bool) $doc['multiple_keys'] => 'Dokladu odpovídá více klíčů převodu.',
            (bool) $doc['has_snapshot'] => 'Doklad už nese korunové částky ze zdroje.',
            (bool) $doc['has_settlement'] => 'Doklad má zápočet nebo vyrovnání.',
            (bool) $doc['has_unsupported_payment'] => 'Doklad má evidovanou úhradu bez doložené alokace nebo s vazbou mimo banku.',
            $doc['sent_at'] !== null || $doc['last_reminder_at'] !== null || (int) $doc['reminder_count'] > 0 || $doc['status'] === 'reminded'
                => 'Doklad nebo upomínka už byly odeslány odběrateli.',
            $doc['pdf_generated_at'] !== null || $doc['pdf_path'] !== null || $doc['imported_pdf_path'] !== null => 'Doklad už má vystavené PDF.',
            ($doc['parent_invoice_id'] !== null && !in_array($doc['invoice_type'], ['invoice', 'tax_document'], true)) || (bool) $doc['has_unsupported_link']
                => 'Doklad má vazbu na jiný doklad nebo čerpání zálohy, které nelze bezpečně převést.',
            $doc['status'] === 'cancelled' || (bool) $doc['prices_include_vat'] => 'Doklad je stornovaný nebo používá ceny s DPH.',
            default => $exportProblem,
        };
        if ($blocked !== null) {
            return ['review', $blocked];
        }
        if ($export === null) {
            return ['needs_export', 'Oprava potřebuje původní export z POHODY.'];
        }
        if ($newImport) {
            return ['convert', null];
        }
        if ($doc['agenda_ico'] === null || (int) $doc['agenda_year'] === 0) {
            return ['review', 'U původního běhu převodu chybí IČO nebo rok agendy.'];
        }
        $agendaIco = PartnerImporter::ico((string) $doc['agenda_ico']);
        if ($agendaIco !== $export->ico) {
            return ['review', sprintf('Doklad převzal běh agendy IČO %s, export má IČO %s.', $agendaIco, $export->ico)];
        }
        if ((int) $doc['agenda_year'] !== $export->year) {
            return ['needs_export', sprintf('Doklad potřebuje původní export z POHODY za rok %d.', (int) $doc['agenda_year'])];
        }
        return ['convert', null];
    }

    private function exportProblem(int $supplierId, PohodaExport $export): ?string
    {
        $ico = $this->supplierIco($supplierId);
        if ($export->ico === '' || $export->ico !== $ico) {
            return sprintf('Export má IČO %s, firma má IČO %s.', $export->ico, $ico);
        }
        foreach ($export->files() as $file) {
            if ($file['exists'] && $file['ico'] !== '' && $file['ico'] !== $ico) {
                return sprintf('Soubor exportu %s je z agendy IČO %s, očekává se %s.', $file['file'], $file['ico'], $ico);
            }
        }
        return null;
    }

    /** @param list<string> $keys @return array<string,?array> */
    private function sources(int $supplierId, PohodaExport $export, array $keys): array
    {
        $wanted = array_fill_keys($keys, true);
        $ctx = new PohodaContext($supplierId, 0, $export, PohodaVat::fromExport($export), true, new ImportProtocol('dry_run'));
        $sources = [];
        foreach ([...array_keys(InvoiceImporter::ISSUED_AGENDAS), 'internal'] as $agenda) {
            if (!str_starts_with($agenda, 'issued') && $agenda !== 'internal') {
                continue;
            }
            $prefix = $agenda === 'internal' ? 'intDoc' : 'invoice';
            foreach ($export->records($agenda, $prefix) as $record) {
                $key = InvoiceImporter::purchaseSource($record, $agenda, $prefix)['key'];
                if (isset($wanted[$key])) {
                    $sources[$key] = array_key_exists($key, $sources) ? null : $this->importer->issuedRepairSource($ctx, $record, $agenda);
                }
            }
        }
        return $sources;
    }

    /** @param list<int> $ids @return array<int,list<array<string,mixed>>> */
    private function items(array $ids): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, invoice_id, order_index, description, quantity, unit, total_without_vat, total_vat, total_with_vat,
                    advance_invoice_id, oss_applicable, import_tax_base_czk, import_tax_vat_czk
               FROM invoice_items WHERE invoice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY invoice_id, order_index, id FOR UPDATE'
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt as $item) {
            $out[(int) $item['invoice_id']][] = $item;
        }
        return $out;
    }

    /** @return array{amounts:array,rate:float,items:array,source:array}|string */
    private static function plan(array $doc, array $items, ?array $source, ?array $advance, bool $linkedProforma): array|string
    {
        if ($source === null) {
            return 'Doklad v exportu chybí nebo se v něm vyskytuje vícekrát.';
        }
        $header = $source['doc'];
        $amounts = $source['amounts'];
        if (strtoupper(trim($header['foreign'])) !== $doc['currency']) {
            return 'Měna dokladu v exportu se liší od měny v poznámce převodu.';
        }
        $deduction = null;
        $deductionItems = [];
        if ($advance !== null) {
            if ($doc['currency'] !== $advance['currency']) {
                return 'Konečná faktura a záloha mají jinou měnu.';
            }
            $deduction = PohodaIssuedAdvanceGroups::deduction($amounts, $advance);
            if (is_string($deduction)) {
                return $deduction;
            }
            foreach ($amounts['advance_rows'] as $row) {
                if ($row['item_index'] !== null) {
                    $deductionItems[$row['item_index']] = true;
                }
            }
        }
        $sourceTax = $doc['invoice_type'] === 'proforma' ? $header['tax'] : ($header['tax'] ?? $header['issue']);
        if ($doc['issue_date'] !== $header['issue'] || $doc['tax_date'] !== $sourceTax || $doc['due_date'] !== $header['due']
            || (float) $doc['total_without_vat'] !== $amounts['base'] || (float) $doc['total_vat'] !== $amounts['vat']
            || (float) $doc['total_with_vat'] !== $amounts['total'] || (float) $doc['rounding'] !== $amounts['rounding']
            || (float) $doc['advance_paid_amount'] !== $amounts['advance'] || count($items) !== count($amounts['items'])) {
            return 'Termíny, korunové součty nebo počet položek se liší od původního exportu.';
        }
        foreach ($items as $index => $item) {
            if ((bool) $item['oss_applicable']) {
                return 'Doklad má položky OSS. Výsledek původního OSS podání vyžaduje samostatné ověření.';
            }
            if (($item['advance_invoice_id'] !== null && (!isset($deductionItems[$index])
                    || (int) $item['advance_invoice_id'] !== (int) $doc['parent_invoice_id']))
                || $item['import_tax_base_czk'] !== null || $item['import_tax_vat_czk'] !== null) {
                return 'Položka už má vazbu na zálohu nebo korunové částky ze zdroje.';
            }
            $original = $amounts['items'][$index];
            if ($item['description'] !== $original['description'] || $item['unit'] !== ($original['unit'] ?? 'ks')
                || (float) $item['quantity'] !== $original['quantity'] || (float) $item['total_without_vat'] !== $original['base']
                || (float) $item['total_vat'] !== $original['vat'] || (float) $item['total_with_vat'] !== round($original['base'] + $original['vat'], 2)) {
                return sprintf('Položka %d se liší od položky v původním exportu.', $index + 1);
            }
        }
        $rate = ForeignCurrencyTakeover::rate($header['foreign_rate'], $header['foreign_units']);
        $converted = IssuedCurrencySnapshot::prepare($amounts, $rate, $header['foreign_total'], $deduction, $linkedProforma);
        return is_array($converted) && $rate !== null
            ? ['amounts' => $converted, 'rate' => $rate, 'items' => $items, 'source' => $source]
            : (is_string($converted) ? ucfirst($converted) . '.' : 'Doklad nemá kurz.');
    }

    /**
     * Zápis i náhled běží v samostatné transakci. Po vrácení kola s rozdílem se zbývající
     * doklady ověří znovu. SAVEPOINT nepoužíváme kvůli FULLTEXT indexu položek.
     *
     * @return array<int,array<string,mixed>>
     */
    private function apply(int $supplierId, array $pending, array $sources, array $initial, bool $commit, ?int $userId, bool $newImport): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Oprava vydaných dokladů potřebuje vlastní transakci.');
        }
        $out = [];
        while ($pending !== []) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            try {
                $this->lockPeriodState($supplierId);
                $families = $this->advances->families($supplierId, $pending, true);
                $ids = array_keys($pending);
                $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
                $stmt->execute([$supplierId, ...$ids]);
                $stmt->closeCursor();
                $items = $this->items($ids);
                $this->locks->reset();
                $start = $this->accountingStart($supplierId);
                $docs = $this->candidates($supplierId, $ids);
                [$groups, $roots, $unchanged, $problems] = PohodaIssuedAdvanceGroups::scope($families, $pending, $sources, $initial);
                $plans = $outcomes = [];
                $proformas = array_filter($pending, static fn (array $doc): bool => $doc['invoice_type'] === 'proforma');
                foreach ($proformas + $pending as $id => $priorDoc) {
                    if (isset($problems[$id])) {
                        continue;
                    }
                    $doc = $docs[$id] ?? null;
                    if ($doc === null || $doc['pohoda_key'] !== $priorDoc['pohoda_key']
                        || $doc['parent_invoice_id'] !== $priorDoc['parent_invoice_id']) {
                        $problems[$id] = 'Doklad nebo jeho vazba se mezitím změnily.';
                        continue;
                    }
                    [$status, $reason] = $this->eligibility($supplierId, $doc, $priorDoc['export'], null, $newImport, $start);
                    if ($status !== 'convert') {
                        $outcomes[$id] = $status;
                        $problems[$id] = $reason;
                        continue;
                    }
                    $root = $roots[$id] ?? null;
                    $advance = null;
                    if ($doc['invoice_type'] === 'invoice' && $doc['parent_invoice_id'] !== null) {
                        $parent = $families[$root][$root] ?? null;
                        $advance = $parent === null ? null : PohodaIssuedAdvanceGroups::advance(
                            $parent, $plans[$root] ?? null, $unchanged[$root]['source'] ?? null,
                        );
                        if ($advance === null) {
                            $problems[$id] = $problems[$root] ?? 'Konečnou fakturu nelze převést bez doložené zálohy ve stejné měně.';
                            continue;
                        }
                    }
                    $linkedProforma = $root === $id && in_array('invoice', array_column($families[$root], 'invoice_type'), true);
                    $plan = self::plan($doc, $items[$id] ?? [], $sources[$id] ?? null, $advance, $linkedProforma);
                    if (is_string($plan)) {
                        $problems[$id] = $plan;
                        continue;
                    }
                    $plans[$id] = $plan + ['doc' => $doc, 'advance_group' => $root];
                }
                foreach (self::relatedProblems($problems, $groups) as $id => $reason) {
                    if (isset($pending[$id])) {
                        $out[$id] = ['status' => $outcomes[$id] ?? 'review', 'reason' => $reason];
                    }
                    unset($plans[$id], $unchanged[$id]);
                }
                if ($plans !== []) {
                    [$payments, $problems, $banks] = $this->payments->plan($supplierId, $plans, array_map(static fn (array $p): array => $p['doc'], $unchanged));
                    $linkProblems = PohodaIssuedAdvanceGroups::taxDocumentProblems($plans + $unchanged, $payments);
                    $groups = [...array_values($banks), ...$groups];
                    $problems = self::relatedProblems($problems + $linkProblems, $groups);
                    foreach ($problems as $id => $reason) {
                        if (isset($pending[$id])) {
                            $out[$id] = ['status' => 'review', 'reason' => $reason];
                        }
                        unset($plans[$id], $unchanged[$id]);
                    }
                    foreach ($plans as $id => &$plan) {
                        $plan['payment'] = $payments[$id];
                    }
                    unset($plan);
                }
                $pending = array_intersect_key($pending, $plans);
                if ($plans === []) {
                    $pdo->rollBack();
                    break;
                }
                $snapshotPlans = $plans;
                foreach ($unchanged as $id => $plan) {
                    $snapshotPlans[$id] = $plan + ['payment' => $payments[$id]];
                }
                $before = $this->snapshot($supplierId, $snapshotPlans);
                $this->write($supplierId, $plans);
                $changed = self::changed($before, $this->snapshot($supplierId, $snapshotPlans), array_keys($snapshotPlans));
                if ($changed !== []) {
                    $pdo->rollBack();
                    $reasons = array_map(static fn (string $area): string => $area === 'kurzový rozdíl'
                        ? 'Změna měny by změnila návrh kurzového rozdílu nebo podklady k němu. Zápis byl vrácen.'
                        : sprintf('Změna měny by změnila %s v Kč. Zápis byl vrácen.', $area), $changed);
                    foreach (self::relatedProblems($reasons, $groups) as $id => $reason) {
                        if (!isset($pending[$id])) {
                            continue;
                        }
                        $out[$id] = ['status' => 'review', 'reason' => $reason];
                        unset($pending[$id]);
                    }
                    continue;
                }
                if ($commit) {
                    $this->retireCrossCurrencySuggestions($supplierId, $plans);
                    $this->audit($supplierId, $plans, $userId);
                    $pdo->commit();
                } else {
                    $pdo->rollBack();
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            foreach ($plans as $id => $plan) {
                $out[$id] = ['status' => $commit ? 'converted' : 'convert', 'reason' => null,
                    'rate' => $plan['rate'], 'total_foreign' => $plan['amounts']['total'], 'payments_converted' => count($plan['payment']['rows'])];
            }
            if ($commit) {
                $this->recomputeStats($plans);
            }
            break;
        }
        return $out;
    }

    /** @param list<list<int>> $groups */
    private static function relatedProblems(array $problems, array $groups): array
    {
        do {
            $previous = count($problems);
            foreach ($groups as $members) {
                foreach ($members as $id) {
                    if (isset($problems[$id])) {
                        $problems += array_fill_keys($members, $problems[$id]);
                        break;
                    }
                }
            }
        } while ($previous !== count($problems));
        return $problems;
    }

    private static function cashMovement(array $movement): array
    {
        if ($movement['bank_class'] !== 'income' || !empty($movement['override_bucket'])) {
            return $movement;
        }
        $allocation = CashJournalService::bankIncomeAllocation($movement);
        unset($allocation['ratio'], $movement['bank_income_base'], $movement['bank_income_exempt'],
            $movement['bank_alloc_czk'], $movement['bank_alloc_native'], $movement['bank_alloc_cross_ccy']);
        return $movement + ['bank_allocation' => $allocation];
    }

    private function lockPeriodState(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        foreach ([
            'SELECT supplier_id FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE',
            'SELECT id FROM accounting_periods WHERE supplier_id = ? FOR UPDATE',
            'SELECT id FROM tax_submissions FORCE INDEX (idx_supplier_form_period) WHERE supplier_id = ? FOR UPDATE',
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        ] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId]);
            $stmt->closeCursor();
        }
    }

    private function write(int $supplierId, array $plans): void
    {
        $pdo = $this->db->pdo();
        $header = $pdo->prepare(
            'UPDATE invoices SET currency_id = ?, exchange_rate = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ?,
                    rounding = ?, advance_paid_amount = ?, paid_total = ?, note_below_items = ?, import_total_without_vat_czk = ?,
                    import_total_vat_czk = ?, import_total_with_vat_czk = ?, import_amount_to_pay_czk = ?, import_advance_paid_czk = ?
              WHERE supplier_id = ? AND id = ? AND exchange_rate IS NULL'
        );
        $item = $pdo->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ?,
                    import_tax_base_czk = ?, import_tax_vat_czk = ?
              WHERE invoice_id = ? AND id = ?'
        );
        $payment = $pdo->prepare('UPDATE invoice_payments SET amount = ?, currency = ? WHERE supplier_id = ? AND invoice_id = ? AND id = ?');
        $decisions = [];
        foreach ($plans as $id => $plan) {
            $doc = $plan['doc'];
            $rate = $plan['rate'];
            $fx = $decisions[$doc['currency']] ??= $this->currencies->resolveCurrency($supplierId, $doc['currency'], $rate);
            if (!$fx->inForeignCurrency()) {
                throw new \RuntimeException(sprintf('Doklad %s: %s.', $doc['varsymbol'], $fx->reason));
            }
            $amounts = $plan['amounts'];
            $snapshot = $amounts['snapshot'];
            $note = preg_replace_callback(self::NOTE_PATTERN, static fn (): string => sprintf(
                'doklad v %s, převzat v měně dokladu kurzem %s Kč', $doc['currency'],
                rtrim(rtrim(number_format($rate, 6, ',', ''), '0'), ','),
            ), (string) $doc['note_below_items'], 1);
            $header->execute([
                $fx->currencyId, $rate, $amounts['base'], $amounts['vat'], $amounts['total'], $amounts['rounding'], $amounts['advance'], $plan['payment']['paid'], $note,
                $snapshot['import_total_without_vat_czk'], $snapshot['import_total_vat_czk'], $snapshot['import_total_with_vat_czk'],
                $snapshot['import_amount_to_pay_czk'], $snapshot['import_advance_paid_czk'], $supplierId, $id,
            ]);
            if ($header->rowCount() !== 1) {
                throw new \RuntimeException(sprintf('Doklad %s se mezitím změnil.', $doc['varsymbol']));
            }
            foreach ($plan['payment']['rows'] as $paymentId => $amount) {
                $payment->execute([$amount, $doc['currency'], $supplierId, $id, $paymentId]);
                if ($payment->rowCount() !== 1) {
                    throw new \RuntimeException(sprintf('Úhrada dokladu %s se mezitím změnila.', $doc['varsymbol']));
                }
            }
            foreach ($amounts['items'] as $index => $line) {
                $item->execute([
                    $line['unit_price'], $line['base'], $line['vat'], round($line['base'] + $line['vat'], 2),
                    $line['home_base'], $line['home_vat'], $id, $plan['items'][$index]['id'],
                ]);
                if ($item->rowCount() !== 1) {
                    throw new \RuntimeException(sprintf('Položka dokladu %s se mezitím změnila.', $doc['varsymbol']));
                }
            }
        }
    }

    private function retireCrossCurrencySuggestions(int $supplierId, array $plans): void
    {
        $ids = [];
        foreach ($plans as $plan) {
            foreach ($plan['payment']['bank_ids'] as $id) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE bank_posting_suggestions SET status = 'superseded'
              WHERE supplier_id = ? AND bank_transaction_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                AND source = 'payment_match' AND note = 'cross_currency' AND status IN ('pending', 'needs_input', 'blocked')"
        );
        $stmt->execute([$supplierId, ...array_values($ids)]);
    }

    private function audit(int $supplierId, array $plans, ?int $userId): void
    {
        foreach ($plans as $id => $plan) {
            $doc = $plan['doc'];
            $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', $id, [
                'document_no' => $doc['varsymbol'],
                'currency' => $doc['currency'],
                'exchange_rate' => $plan['rate'],
                'total_with_vat_czk' => (float) $doc['total_with_vat'],
                'total_with_vat' => $plan['amounts']['total'],
                'note_before' => $doc['note_below_items'],
                'paid_total_czk_before' => (float) $doc['paid_total'],
                'paid_total_after' => $plan['payment']['paid'],
                'payments_converted' => count($plan['payment']['rows']),
            ], null, null, $supplierId);
        }
    }

    private function recomputeStats(array $plans): void
    {
        $docs = array_column($plans, 'doc');
        try {
            foreach (array_unique(array_column($docs, 'client_id')) as $clientId) {
                $this->stats->recomputeClient((int) $clientId);
            }
            foreach (array_unique(array_filter(array_column($docs, 'project_id'))) as $projectId) {
                $this->stats->recomputeProject((int) $projectId);
            }
        } catch (\Throwable $e) {
            error_log('Po opravě měny vydaných dokladů se nepodařilo obnovit statistiky: ' . $e->getMessage());
        }
    }

    /** @return array<int,array<string,list<string>>> */
    private function snapshot(int $supplierId, array $plans): array
    {
        $ids = array_keys($plans);
        $docs = array_map(static fn (array $plan): array => $plan['doc'], $plans);
        $dates = array_map(static fn (array $d): string => $d['effective_tax_date'] ?? $d['issue_date'], $docs);
        $from = min($dates);
        $to = max($dates);
        $out = [];
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, amount_to_pay, paid_total, exchange_rate, import_amount_to_pay_czk
            FROM invoices WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt as $doc) {
            $due = IssuedCzkAmounts::due($doc);
            $paid = IssuedCzkAmounts::allocated($doc, (float) $doc['paid_total'], (float) ($doc['exchange_rate'] ?? 1.0));
            $out[(int) $doc['id']]['saldo dokladu'][] = serialize([$paid, round($due - $paid, 2)]);
        }
        $byBank = [];
        foreach ($plans as $id => $plan) {
            foreach ($plan['payment']['bank_ids'] as $txId) {
                $byBank[$txId][] = $id;
            }
        }
        foreach ($this->ledger->iterateRows($supplierId, $from, $to, true, ['sale' => $ids]) as $row) {
            unset($row['currency'], $row['exchange_rate'], $row['document_total']);
            $out[$row['invoice_id']]['evidenci DPH'][] = serialize($row);
        }

        $periods = [];
        foreach ($dates as $date) {
            $year = (int) substr($date, 0, 4);
            $month = (int) substr($date, 5, 2);
            $periods[$year . '-M' . $month] = [$year, $month, 'monthly'];
            $quarter = (int) ceil($month / 3);
            $periods[$year . '-Q' . $quarter] = [$year, 3 * $quarter, 'quarterly'];
        }
        foreach ($periods as $periodKey => [$year, $month, $period]) {
            $report = $this->kh->sectionDocuments($supplierId, $year, $month, $period);
            foreach ($report['sections'] as $section => $part) {
                foreach ($part['rows'] as $row) {
                    if ($row['source'] === 'sale' && isset($docs[$row['invoice_id']])) {
                        $out[$row['invoice_id']]['kontrolní hlášení'][] = $periodKey . serialize($row);
                    }
                }
                $out[0]['kontrolní hlášení'][] = $periodKey . $section . serialize($part['totals']);
            }
            foreach ($report['excluded'] as $row) {
                if ($row['source'] === 'sale' && isset($docs[$row['invoice_id']])) {
                    $out[$row['invoice_id']]['kontrolní hlášení'][] = $periodKey . serialize($row);
                }
            }
        }

        $from = min($from, min(array_column($docs, 'issue_date')));
        $paidDates = [];
        foreach ($plans as $plan) {
            array_push($paidDates, ...$plan['payment']['dates']);
            if ($plan['doc']['paid_at'] !== null) {
                $paidDates[] = substr($plan['doc']['paid_at'], 0, 10);
            }
        }
        $from = min([$from, ...$paidDates]);
        $to = max([$to, max(array_column($docs, 'issue_date')), date('Y-m-d'), ...$paidDates]);
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ($mode->fetchColumn() === 'double_entry') {
            $advanceIds = array_keys(array_filter($plans, static fn (array $plan): bool => $plan['advance_group'] !== null));
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, ends_on FROM accounting_periods WHERE supplier_id = ? AND ends_on >= ? AND starts_on <= ? ORDER BY starts_on'
            );
            $stmt->execute([$supplierId, $from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $period) {
                $asOf = min($period['ends_on'], $to);
                foreach ($this->closing->openFxItems($supplierId, $period['ends_on'], issuedIds: $advanceIds) as $item) {
                    if (FxRevaluationService::isNonMonetaryAdvanceAccount((string) $item['account_code'])) {
                        continue;
                    }
                    $evidence = $this->closing->fxSettlementEvidence($supplierId, $item, $period['ends_on']);
                    $out[$item['doc_id']]['kurzový rozdíl'][] = serialize([$period['ends_on'], $item['source_line_id'],
                        $evidence['complete'], $evidence['remaining_foreign'], $evidence['booked_czk']]);
                }
                foreach ($this->saldo->build($supplierId, (int) $period['id'], $asOf)['accounts'] as $block) {
                    $account = $block['account']['code'] ?? '';
                    foreach ($block['partners'] as $partner) {
                        foreach ($partner['items'] as $item) {
                            if ($item['doc_type'] === 'invoice' && isset($docs[$item['doc_id']])) {
                                $out[$item['doc_id']]['saldokonto'][] = serialize([$period['id'], $account,
                                    $item['booked_czk'], $item['paid_czk'], $item['remaining_czk']]);
                            }
                        }
                    }
                    $out[0]['saldokonto'][] = serialize([$period['id'], $account, $block['gl_balance'], $block['open_items_total'], $block['difference']]);
                }
            }
        }

        foreach ([false, true] as $payer) {
            $hash = hash_init('sha256');
            hash_update($hash, serialize($this->cashJournal->openingBalance($supplierId, $from, $payer)));
            $limit = 5000;
            for ($offset = 0;; $offset += $limit) {
                $movements = $this->cashJournal->movements($supplierId, $from, $to, $payer, $limit, $offset);
                foreach ($movements as $movement) {
                    if ($movement['source_type'] === 'bank' && isset($byBank[$movement['source_id']])) {
                        $movement = self::cashMovement($movement);
                        foreach ($byBank[$movement['source_id']] as $id) {
                            $out[$id]['peněžní deník'][] = serialize([$payer, $movement]);
                        }
                    } else {
                        hash_update($hash, serialize($movement));
                    }
                }
                if (count($movements) < $limit) {
                    break;
                }
            }
            $out[0]['peněžní deník'][] = hash_final($hash);
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,string> */
    private static function changed(array $before, array $after, array $ids): array
    {
        $out = [];
        foreach ([...$ids, 0] as $id) {
            foreach (['evidenci DPH', 'kontrolní hlášení', 'saldo dokladu', 'saldokonto', 'peněžní deník', 'kurzový rozdíl'] as $area) {
                $a = $before[$id][$area] ?? [];
                $b = $after[$id][$area] ?? [];
                sort($a, SORT_STRING);
                sort($b, SORT_STRING);
                if ($a !== $b) {
                    $out[$id] = $area;
                    break;
                }
            }
        }
        if (isset($out[0])) {
            $global = $out[0];
            unset($out[0]);
            if ($out === []) {
                return array_fill_keys($ids, $global);
            }
        }
        return $out;
    }
}
