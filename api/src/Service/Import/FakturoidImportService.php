<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Log\LoggerInterface;

/**
 * Fakturoid import orchestrátor — paralel s IdokladImportService.
 *
 * Stahuje:
 *   - Subjects (klienti/dodavatelé) → clients
 *   - Invoices                       → invoices
 *   - Expenses                       → purchase_invoices
 *
 * Dedup přes (supplier_id, fakturoid_id). Lifecycle stejný jako iDoklad
 * (markRunning → progress → markCompleted/Failed/Cancelled).
 *
 * Fakturoid pole rozdílná od iDoklad:
 *   - Subject má `registration_no` (IČO) + `vat_no` (DIČ)
 *   - Invoice má `subject_id` (foreign key) + `lines` (items array)
 *   - Lines: { name, quantity, unit_name, unit_price, vat_rate }
 *   - Subject type: "customer" | "supplier" | "both" → role mapping
 *
 * Platební stav (#121): doklady se zakládají jako draft, ale `status` z Fakturoidu
 * 'paid'/'cancelled' se promítne hned při importu (paid_at = `paid_on`) — viz
 * ImportedPaymentStateMapper. Ostatní stavy zůstávají draft (review flow).
 *
 * Částky (#128, #131): režim cen dokladu se přenáší do `prices_include_vat`, přijatý
 * doklad dostává zdrojovou rekapitulaci DPH (`vat_overrides`) a zaokrouhlení. Po
 * přepočtu se výsledek porovná se zdrojem ({@see FakturoidSourceDocument}) a rozdíl,
 * který nejde věrně převzít, se nahlásí ke kontrole.
 *
 * Výsledek úlohy (#129, #130): počty per agenda a přehled nepřenesených dokladů jdou
 * do `import_jobs.report`; úloha s odmítnutým nebo ke kontrole označeným dokladem
 * končí `completed_with_warnings`. Zkouška nanečisto prochází stejné kontroly
 * (sazby, přepočet, shoda se zdrojem) jako ostrý import, jen nic nezapíše.
 */
final class FakturoidImportService
{
    private const PROGRESS_FLUSH_EVERY = 10;
    /** Strop pro PDF ukládané do archivu (SEC-13) — víc než scan dokladu nepotřebujeme. */
    private const MAX_ARCHIVED_PDF_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly FakturoidClient $fakturoid,
        private readonly ImportJobRepository $jobs,
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly InvoiceCalculator $invCalc,
        private readonly PurchaseInvoiceCalculator $purCalc,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PurchaseInvoiceCnbApplier $cnbApplier,
        private readonly SnapshotBuilder $snapshots,
        private readonly ExchangeRateApplier $exchangeRateApplier,
        private readonly OssItemPlanner $planner,
        private readonly StatsRecomputer $stats,
        private readonly ImportedSubjectLinker $subjectLinker,
        private readonly AdvanceDeductionRepository $advanceDeductions,
    ) {}

    /**
     * Varování k OSS / párování sazby za PRÁVĚ ZPRACOVÁVANÝ doklad — `createIssued()`
     * job ID nezná, takže je do logu vysype volající hned po dokladu.
     *
     * @var list<string>
     */
    private array $ossWarnings = [];

    /**
     * ID klienta poslední úspěšně založené vydané faktury ({@see createIssued()}) —
     * `createIssued()` job ID nezná, ale volající potřebuje klienta pro dávkový
     * přepočet cache seznamu klientů po celém importu (viz `importInvoices()`).
     */
    private ?int $lastCreatedClientId = null;

    /** Vrátil `createExpense()` už existující doklad (dedup), ne nově založený? */
    private bool $lastExpenseExisted = false;

    /** Výstup úlohy pro `import_jobs.report` — viz {@see initReport()}. */
    private array $report = [];

    /** @var array<int,true> Fakturoid ID subjektů, které by zkouška nanečisto založila. */
    private array $dryRunSubjects = [];

    private const MAX_REPORTED_PROBLEMS = 500;

    public function run(int $jobId): void
    {
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) return;

        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $userId = (int) $job['created_by'];
            $dryRun = !empty($params['dry_run']);
            $incremental = !empty($params['incremental']);
            $downloadAttachments = !empty($params['download_attachments']);
            $bookmarkSince = $incremental ? $this->loadBookmark($supplierId) : null;

            $msg = 'Fakturoid import zahájen' . ($dryRun ? ' (dry-run)' : '');
            if ($incremental && $bookmarkSince !== null) $msg .= ', incremental od ' . $bookmarkSince;
            if ($downloadAttachments) $msg .= ', s přílohami';
            $this->jobs->appendLog($jobId, $msg . '.');
            $this->initReport($dryRun);

            if (!empty($params['include_clients']) || ($params['include_clients'] ?? null) === null) {
                $this->importSubjects($jobId, $supplierId, $userId, $dryRun, $bookmarkSince);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_issued']) || ($params['include_issued'] ?? null) === null) {
                $this->importInvoices($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_received']) || ($params['include_received'] ?? null) === null) {
                $this->importExpenses($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
            }

            $totals = $this->storeReport($jobId);
            if ($totals['failed'] > 0 || $totals['review'] > 0) {
                $this->jobs->appendLog($jobId, sprintf(
                    'Fakturoid import dokončen s chybami: nepřeneseno %d, ke kontrole %d. Přehled je ve výsledku úlohy.',
                    $totals['failed'],
                    $totals['review'],
                ));
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->appendLog($jobId, 'Fakturoid import dokončen.');
                $this->jobs->markCompleted($jobId);
            }
            // Zkouška nanečisto nic nezapsala, záložka inkrementálního importu se proto
            // posouvat nesmí.
            if (!$dryRun) {
                $this->db->pdo()->prepare(
                    'UPDATE supplier SET fakturoid_last_imported_at = NOW() WHERE id = ?'
                )->execute([$supplierId]);
            }
        } catch (CancelledException $e) {
            $this->storeReport($jobId);
            $this->jobs->appendLog($jobId, 'Fakturoid import zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('Fakturoid import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->storeReport($jobId);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function initReport(bool $dryRun): void
    {
        $this->report = ['dry_run' => $dryRun, 'agendas' => [], 'problems' => [], 'problems_omitted' => 0];
        $this->dryRunSubjects = [];
    }

    /** @param array<string,int> $counts */
    private function reportAgenda(string $agenda, array $counts): void
    {
        $this->report['agendas'][$agenda] = $counts + ['processed' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'review' => 0];
    }

    /**
     * Nepřenesený (`error`) nebo ke kontrole označený (`review`) doklad. `hint` je
     * klíč návodu v UI: `vat_rate` sazba DPH, `subject` chybějící subjekt,
     * `amounts` rozdíl částek proti Fakturoidu, `generic` ostatní.
     *
     * @param array<string,mixed> $doc
     */
    private function reportProblem(string $agenda, array $doc, string $severity, string $reason, string $hint, ?int $localId = null): void
    {
        if (count($this->report['problems']) >= self::MAX_REPORTED_PROBLEMS) {
            $this->report['problems_omitted']++;
            return;
        }
        $number = (string) (($doc['number'] ?? '') ?: ($doc['original_number'] ?? '') ?: ($doc['name'] ?? ''));
        $this->report['problems'][] = [
            'agenda' => $agenda,
            'fakturoid_id' => (int) ($doc['id'] ?? 0),
            'number' => $number !== '' ? $number : null,
            'local_id' => $localId,
            'severity' => $severity,
            'reason' => $reason,
            'hint' => $hint,
        ];
    }

    /**
     * Zapíše report a souhrnné počty úlohy. Jednotlivé agendy si počítadla v řádku
     * úlohy průběžně přepisují, po doběhnutí tam proto patří jejich součet.
     *
     * @return array{created:int, skipped:int, failed:int, review:int}
     */
    private function storeReport(int $jobId): array
    {
        $totals = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'review' => 0];
        if ($this->report === []) {
            return $totals;
        }
        foreach ($this->report['agendas'] as $agenda => $counts) {
            if ($agenda === 'subjects') {
                $totals['failed'] += $counts['failed'];
                continue;
            }
            foreach (array_keys($totals) as $k) {
                $totals[$k] += (int) ($counts[$k] ?? 0);
            }
        }
        try {
            $this->jobs->setReport($jobId, $this->report);
            $this->jobs->updateProgress($jobId, [
                'created_count' => $totals['created'],
                'skipped_count' => $totals['skipped'],
                'failed_count'  => $totals['failed'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Fakturoid import: výsledek úlohy se nepodařilo uložit', ['job_id' => $jobId, 'error' => $e->getMessage()]);
        }
        return $totals;
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        if (!empty($row['params'])) $row['params'] = json_decode((string) $row['params'], true);
        return $row;
    }

    private function checkCancel(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new CancelledException();
        }
    }

    private function importSubjects(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing subjects (clients/vendors)…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji subjekty z Fakturoid…');

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $linked = 0; $skipped = 0; $failed = 0; $processed = 0;

        foreach ($this->fakturoid->getAll($supplierId, 'subjects.json', $query) as $subj) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($subj['id'] ?? 0);
            if ($fakturoidId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) {
                $this->dryRunSubjects[$fakturoidId] = true;
                $created++;
                continue;
            }

            try {
                // Type: "customer" | "supplier" | "both"
                $type = (string) ($subj['type'] ?? 'customer');
                $isCustomer = $type === 'customer' || $type === 'both';
                $isVendor   = $type === 'supplier' || $type === 'both';
                if (!$isCustomer && !$isVendor) $isCustomer = true; // fallback

                $linkedId = $this->subjectLinker->linkExisting(
                    $supplierId, 'fakturoid_id', $fakturoidId,
                    (string) ($subj['registration_no'] ?? ''), (string) ($subj['vat_no'] ?? ''),
                    $isCustomer, $isVendor,
                );
                if ($linkedId !== null) { $linked++; continue; }

                $data = [
                    'company_name' => (string) ($subj['name'] ?? 'Fakturoid import'),
                    'ic'           => (string) ($subj['registration_no'] ?? '') ?: null,
                    'dic'          => (string) ($subj['vat_no'] ?? '') ?: null,
                    'street'       => (string) ($subj['street'] ?? '—'),
                    'city'         => (string) ($subj['city'] ?? '—'),
                    'zip'          => (string) ($subj['zip'] ?? '00000'),
                    'country_iso2' => strtoupper((string) ($subj['country'] ?? 'CZ')),
                    'main_email'   => (string) ($subj['email'] ?? '') ?: null,
                    'phone'        => (string) ($subj['phone'] ?? '') ?: null,
                    'language'     => 'cs',
                    'is_customer'  => $isCustomer,
                    'is_vendor'    => $isVendor,
                ];
                $clientId = $this->clients->create($data, $supplierId);
                $this->db->pdo()->prepare(
                    'UPDATE clients SET fakturoid_id = ? WHERE id = ?'
                )->execute([$fakturoidId, $clientId]);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Subject {$fakturoidId}: " . $e->getMessage());
                $this->reportProblem('subjects', $subj, 'error', $e->getMessage(), 'generic');
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        $this->jobs->appendLog($jobId, "Subjekty: vytvořeno {$created}, napojeno na existující kartu {$linked}, přeskočeno {$skipped} (z {$processed}).");
        $this->reportAgenda('subjects', [
            'processed' => $processed, 'created' => $created, 'linked' => $linked, 'skipped' => $skipped, 'failed' => $failed,
        ]);
    }

    private function importInvoices(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji vydané faktury z Fakturoid…');

        // Pre-flight číselníku sazeb členských států — bez něj by invariant proti úniku
        // cizí daně odmítl každý řádek se sazbou > 0 %, tedy i běžnou českou fakturu.
        $codebookProblem = $this->planner->codebookProblem($supplierId, 'Import vydaných faktur se nespustil.');
        if ($codebookProblem !== null) {
            $this->jobs->appendLog($jobId, $codebookProblem);
            throw new \RuntimeException($codebookProblem);
        }

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $skipped = 0; $failed = 0; $review = 0; $processed = 0;
        // Klienti dotčení nově založenými fakturami — cache seznamu klientů se přepočte
        // dávkově až na konci celého importu, ne po každém dokladu.
        $touchedClientIds = [];
        // Vazby mezi doklady (záloha → daňový doklad → konečná faktura, dobropis →
        // opravovaný doklad) se navazují až po převzetí všech dokladů: Fakturoid je vrací
        // od nejnovějšího, takže konečná faktura přichází dřív než její zálohy.
        $links = new FakturoidDocumentLinks();

        foreach ($this->fakturoid->getAll($supplierId, 'invoices.json', $query) as $inv) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($inv['id'] ?? 0);
            if ($fakturoidId === 0) continue;
            $links->remember($inv);

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            try {
                if ($dryRun) {
                    $diffs = $this->dryRunIssued($inv, $supplierId);
                    $invoiceId = null;
                } else {
                    $invoiceId = $this->createIssued($inv, $supplierId, $userId);
                    $this->db->pdo()->prepare('UPDATE invoices SET fakturoid_id = ? WHERE id = ?')->execute([$fakturoidId, $invoiceId]);
                    $computed = $this->invCalc->recompute($invoiceId);
                    $this->settleIssuedAdvance($invoiceId, $inv);
                    ImportedPaidInvoicePayment::record($this->db->pdo(), $invoiceId);
                    if ($downloadAttachments) {
                        $this->archiveIssuedPdf($supplierId, $invoiceId, $fakturoidId, $inv);
                    }
                    if ($this->lastCreatedClientId !== null) {
                        $touchedClientIds[$this->lastCreatedClientId] = true;
                    }
                    $diffs = FakturoidSourceDocument::differences(
                        $computed['vat_breakdown'],
                        FakturoidSourceDocument::vatSummary($inv),
                        !empty($inv['transferred_tax_liability']),
                    );
                }
                $created++;
                $links->created($fakturoidId);
                // Vydaný doklad ruční rekapitulaci DPH nemá (daň určuje vystavovatel
                // výpočtem z položek), takže rozdíl proti Fakturoidu jen nahlásí.
                if ($diffs !== []) {
                    $review++;
                    $reason = FakturoidSourceDocument::describe($diffs);
                    $this->jobs->appendLog($jobId, "Faktura {$fakturoidId}: {$reason} Doklad zkontrolujte.");
                    $this->reportProblem('issued', $inv, 'review', $reason, 'amounts', $invoiceId);
                }
                $overpaid = self::advanceOverpayment($inv);
                if ($overpaid !== null) {
                    $review++;
                    $this->jobs->appendLog($jobId, "Faktura {$fakturoidId}: {$overpaid}");
                    $this->reportProblem('issued', $inv, 'review', $overpaid, 'amounts', $invoiceId);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Faktura {$fakturoidId}: " . $e->getMessage());
                $this->reportProblem('issued', $inv, 'error', $e->getMessage(), FakturoidDocumentRejected::hintOf($e));
            }
            // Fakturoid sync nemá report jako file import — varování o nejednoznačném
            // místě plnění by jinak nebylo vidět nikde.
            foreach ($this->ossWarnings as $warning) {
                $this->jobs->appendLog($jobId, "Faktura {$fakturoidId}: " . $warning);
            }
            $this->ossWarnings = [];
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);

        $linked = $links->link($this->db->pdo(), $this->advanceDeductions, $supplierId, $userId, $dryRun);
        foreach ($linked['problems'] as $problem) {
            $review++;
            $this->jobs->appendLog($jobId, "Faktura {$problem['doc']['id']}: {$problem['reason']}");
            $this->reportProblem('issued', $problem['doc'], 'review', $problem['reason'], 'generic', $problem['local_id']);
        }
        $this->jobs->appendLog($jobId, sprintf(
            'Vazby dokladů: daňové doklady k zálohám %d, konečné faktury k zálohám %d, dobropisy k opravovaným dokladům %d.%s',
            $linked['tax_documents'],
            $linked['finals'],
            $linked['credit_notes'],
            $dryRun ? ' (dry-run)' : '',
        ));

        $this->jobs->appendLog($jobId, "Vydané faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed}, ke kontrole {$review} (z {$processed})." . ($dryRun ? ' (dry-run)' : ''));
        $this->reportAgenda('issued', [
            'processed' => $processed, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'review' => $review,
            'linked' => $linked['tax_documents'] + $linked['finals'] + $linked['credit_notes'],
        ]);

        // Cache seznamu klientů je jen cache — selhání přepočtu nesmí shodit dokončený
        // import, jen se zaloguje do úlohy (jinak by cache tiše zůstala stará).
        if ($touchedClientIds !== []) {
            try {
                $this->stats->recomputeMany(array_keys($touchedClientIds));
            } catch (\Throwable $e) {
                $this->jobs->appendLog($jobId, 'Přepočet cache klientů selhal: ' . $e->getMessage());
            }
        }
    }

    private function createIssued(array $i, int $supplierId, int $userId): int
    {
        $subjId = (int) ($i['subject_id'] ?? 0);
        $clientId = $this->resolveClient($subjId, $supplierId);
        if ($clientId === null) {
            throw new FakturoidDocumentRejected('subject', "Klient (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }
        $this->lastCreatedClientId = $clientId;

        $invoiceType = self::issuedType($i);

        // Sazba i OSS přes SDÍLENÝ plánovač. Fakturoid si dřív sazbu pároval vlastním
        // hledáním přes celou tabulku `vat_rates` (bez země, bez platnosti k datu,
        // bez is_reverse_charge) a při neúspěchu dosadil 21 % — cizí sazba se tak tiše
        // změnila na českou. Na VYDANÉ straně žádný takový fallback být nesmí: dosazená
        // sazba mění odvedenou daň, takže nenalezená sazba shodí doklad s hláškou.
        // Plánuje se PŘED createDraft(), ať po odmítnutí nezůstane prázdná faktura.
        $plan = $this->issuedPlan($i, $supplierId, $clientId);

        $payload = [
            'invoice_type'   => $invoiceType,
            'client_id'      => $clientId,
            'issue_date'     => (string) ($i['issued_on'] ?? date('Y-m-d')),
            'tax_date'       => $invoiceType === 'proforma' ? null : (string) ($i['taxable_fulfillment_due'] ?? $i['issued_on'] ?? date('Y-m-d')),
            'due_date'       => (string) ($i['due_on'] ?? $i['issued_on'] ?? date('Y-m-d')),
            'currency_id'    => $this->resolveCurrencyId((string) ($i['currency'] ?? 'CZK'), $supplierId, isActive: true),
            'reverse_charge' => !empty($i['transferred_tax_liability']),
            // #128: režim cen dokladu. Bez sazby DPH zůstává 0 jako dřív, viz
            // FakturoidSourceDocument::pricesIncludeVat(); výjimku popisuje issuedPlan().
            'prices_include_vat' => $plan['prices_include_vat'],
            'language'       => 'cs',
            // Číslo dokladu z `number`, VS z `variable_symbol` zvlášť (#249) — daňový doklad
            // k proformě nese VS proformy a pod ním by se doklad uložil s cizím číslem.
            'varsymbol'      => $this->uniqueVarsymbol((string) (($i['number'] ?? '') ?: ($i['variable_symbol'] ?? '')), $supplierId),
            'payment_method' => 'bank_transfer',
        ];
        $payload['payment_variable_symbol'] = VariableSymbolNormalizer::importedPaymentOverride(
            (string) $payload['varsymbol'],
            isset($i['variable_symbol']) ? (string) $i['variable_symbol'] : null,
        );
        $items = $plan['items'];

        $invoiceId = $this->invoices->createDraft($payload, $userId);

        if (!empty($items)) $this->invoices->replaceItems($invoiceId, $items);

        // #238: přenes měnový kurz. Fakturoid vrací pole `exchange_rate` (kurz, jímž
        // byla faktura vystavena) — má přednost před ČNB. Když chybí, dopočti z ČNB
        // k DUZP (ensureRate plní jen non-CZK doklad s NULL kurzem, nikdy nepřepíše).
        // Bez tohoto by VatLedgerService použil náhradní kurz 1.0 → EUR základ by se
        // do DPH/SH vykázal jako CZK.
        $srcRate = isset($i['exchange_rate']) ? (float) $i['exchange_rate'] : 0.0;
        if ($srcRate > 0 && strtoupper((string) ($i['currency'] ?? 'CZK')) !== 'CZK') {
            $this->invoices->setExchangeRate(
                $invoiceId,
                $srcRate,
                (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
            );
        } else {
            $this->exchangeRateApplier->ensureRate($invoiceId);
        }

        // #121: promítni platební stav z Fakturoidu — zaplacené/stornované doklady
        // nesmí zůstat viset jako nezaplacené pohledávky (a chytat upomínky).
        $this->applyIssuedPaymentState(
            $invoiceId,
            $clientId,
            (int) $payload['currency_id'],
            $supplierId,
            ImportedPaymentStateMapper::fromFakturoid($i),
            (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
            (string) $payload['issue_date'],
            (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
        );
        return $invoiceId;
    }

    /** @param array<string,mixed> $i */
    private static function issuedType(array $i): string
    {
        return FakturoidSourceDocument::issuedType($i);
    }

    /**
     * Rozhodné datum pro sazby: DUZP, u proformy (bez DUZP) datum vystavení.
     *
     * @param array<string,mixed> $i
     */
    private static function issuedTaxDate(array $i): string
    {
        if (self::issuedType($i) === 'proforma') {
            return (string) ($i['issued_on'] ?? date('Y-m-d'));
        }
        return (string) ($i['taxable_fulfillment_due'] ?? $i['issued_on'] ?? date('Y-m-d'));
    }

    /**
     * Položky vydaného dokladu v režimu cen dokladu (bez DPH, nebo s DPH u #128), za nimi
     * záporné odpočtové řádky zdaněných záloh. Konečná faktura je ve Fakturoidu vede mimo
     * `lines` (`paid_advances`); v MyÚčtu jsou to řádky dokladu stejně jako na vyúčtování
     * z proformy ({@see \MyInvoice\Service\Invoice\FinalFromProformaCreator}), protože
     * výkazy DPH sumují řádky: bez nich by se úplata zdaněná daňovým dokladem k záloze
     * zdanila podruhé.
     *
     * `$grossPrices` převezme ceny s DPH (`unit_price_with_vat`) místo cen bez DPH,
     * viz {@see self::issuedPlan()}.
     *
     * @param array<string,mixed> $i
     * @return list<array<string,mixed>>
     */
    private static function issuedLines(array $i, bool $grossPrices = false): array
    {
        $lines = [];
        foreach (($i['lines'] ?? []) as $line) {
            $lines[] = [
                'description'            => (string) ($line['name'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'duration_minutes'       => TimeBilling::inferDurationMinutes(
                    $line['quantity'] ?? 1,
                    $line['unit_name'] ?? 'ks',
                ),
                'unit'                   => (string) ($line['unit_name'] ?? 'ks'),
                'unit_price_without_vat' => $grossPrices
                    ? (float) FakturoidSourceDocument::lineUnitPriceWithVat($line)
                    : FakturoidSourceDocument::lineUnitPrice($line),
                'vat_rate'               => FakturoidSourceDocument::lineRate($line),
                'order_index'            => count($lines),
            ];
        }
        $gross = $grossPrices || FakturoidSourceDocument::pricesIncludeVat($i);
        foreach (FakturoidSourceDocument::paidAdvances($i) as $advance) {
            $lines[] = [
                'description'            => 'Odpočet zálohy: daňový doklad ' . ($advance['number'] !== '' ? $advance['number'] : '#' . $advance['id']),
                'quantity'               => 1.0,
                'duration_minutes'       => null,
                'unit'                   => '',
                'unit_price_without_vat' => $gross ? round($advance['base'] + $advance['vat'], 2) : $advance['base'],
                'vat_rate'               => $advance['rate'],
                'order_index'            => count($lines),
            ];
        }
        return $lines;
    }

    /**
     * Položky dokladu napárované na sazby a režim cen, ve kterém přepočet dá částky
     * Fakturoidu. Doklad s cenami bez DPH počítá Fakturoid po řádcích shora: cenu řádku
     * s DPH zaokrouhlí a daň dopočte jako rozdíl. Řádek s cenou na víc než haléře
     * (typicky „Zaokrouhlení" za −0,165 Kč) pak nese jinou daň, než jakou z téhož
     * základu spočítá náš výpočet zdola, a doklad by se o haléř rozešel s vystaveným
     * originálem. Když takový rozdíl vznikne a řádky nesou ceny s DPH, převezme se doklad
     * v režimu cen s DPH, ale jen pokud tak sedí na rekapitulaci Fakturoidu přesně.
     * Jinak zůstane původní režim a rozdíl se nahlásí ke kontrole jako dřív.
     *
     * Klient, kterého by založila tatáž zkouška nanečisto, ještě v databázi není
     * (`$clientId` null). Sazby se pak ověří tuzemsky, OSS plánování bez karty klienta
     * udělat nejde.
     *
     * @param array<string,mixed> $i
     * @return array{items: list<array<string,mixed>>, prices_include_vat: bool, diffs: list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}>}
     */
    private function issuedPlan(array $i, int $supplierId, ?int $clientId): array
    {
        $reverseCharge = !empty($i['transferred_tax_liability']);
        $summary = FakturoidSourceDocument::vatSummary($i);
        $pricesIncludeVat = FakturoidSourceDocument::pricesIncludeVat($i);
        $warnings = [];
        $items = $this->planIssued($supplierId, $clientId, $i, self::issuedLines($i), $warnings);
        $diffs = FakturoidSourceDocument::differences(
            InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat)['vat_breakdown'],
            $summary,
            $reverseCharge,
        );

        if ($diffs !== [] && !$pricesIncludeVat && self::hasGrossPrices($i)) {
            $grossWarnings = [];
            $grossItems = $this->planIssued($supplierId, $clientId, $i, self::issuedLines($i, true), $grossWarnings);
            $grossDiffs = FakturoidSourceDocument::differences(
                InvoiceMath::compute($grossItems, $reverseCharge, true)['vat_breakdown'],
                $summary,
                $reverseCharge,
            );
            if ($grossDiffs === []) {
                [$items, $warnings, $diffs, $pricesIncludeVat] = [$grossItems, $grossWarnings, [], true];
            }
        }

        array_push($this->ossWarnings, ...$warnings);
        return ['items' => $items, 'prices_include_vat' => $pricesIncludeVat, 'diffs' => $diffs];
    }

    /**
     * Nesou všechny řádky cenu s DPH a aspoň jeden sazbu nad nulou?
     *
     * @param array<string,mixed> $i
     */
    private static function hasGrossPrices(array $i): bool
    {
        $taxed = false;
        foreach (($i['lines'] ?? []) as $line) {
            if (!is_array($line) || FakturoidSourceDocument::lineUnitPriceWithVat($line) === null) {
                return false;
            }
            $taxed = $taxed || FakturoidSourceDocument::lineRate($line) > 0.0;
        }
        return $taxed;
    }

    /**
     * Sazby položek přes sdílený plánovač. Odmítnutý řádek odmítne doklad s návodem
     * k sazbě DPH (#129), nepodporovaná sazba se nikdy nenahrazuje jinou.
     *
     * @param array<string,mixed> $i
     * @param list<array<string,mixed>> $lines
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function planIssued(int $supplierId, ?int $clientId, array $i, array $lines, array &$warnings): array
    {
        if ($clientId === null) {
            $items = [];
            foreach ($lines as $idx => $line) {
                $match = $this->planner->resolveDomesticRate($supplierId, (float) $line['vat_rate'], self::issuedTaxDate($i));
                if (!$match->found()) {
                    throw new FakturoidDocumentRejected('vat_rate', sprintf('Položka č. %d: %s', $idx + 1, $match->message));
                }
                $items[] = $line + ['vat_rate_snapshot' => (float) $match->ratePercent];
            }
            return $items;
        }
        try {
            return $this->planner->planIssuedItems(
                $supplierId,
                $clientId,
                self::issuedTaxDate($i),
                !empty($i['transferred_tax_liability']),
                $lines,
                $warnings,
            );
        } catch (\RuntimeException $e) {
            throw new FakturoidDocumentRejected('vat_rate', $e->getMessage(), $e);
        }
    }

    /**
     * Zkouška nanečisto vydaného dokladu: tytéž předpoklady jako ostrý import
     * (subjekt, sazby, přepočet) a porovnání se zdrojem, bez zápisu (#130).
     *
     * @param array<string,mixed> $i
     * @return list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}>
     */
    private function dryRunIssued(array $i, int $supplierId): array
    {
        $subjId = (int) ($i['subject_id'] ?? 0);
        $clientId = $this->resolveClient($subjId, $supplierId);
        if ($clientId === null && !isset($this->dryRunSubjects[$subjId])) {
            throw new FakturoidDocumentRejected('subject', "Klient (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }
        $plan = $this->issuedPlan($i, $supplierId, $clientId);
        $this->ossWarnings = [];
        return $plan['diffs'];
    }

    /**
     * Částka k úhradě převzatého dokladu, kterou kryje přijatá úplata mimo jeho položky
     * ({@see ImportedIssuedDocumentPolicy}): daňový doklad k přijaté platbě je zaplacený
     * celý, konečná faktura snižuje částku k úhradě o nezdaněnou zálohu, nejvýš na nulu.
     * Volá se po přepočtu a před zápisem úhrady, aby úhrada nesla jen doplatek.
     *
     * @param array<string,mixed> $i
     */
    private function settleIssuedAdvance(int $invoiceId, array $i): void
    {
        $pdo = $this->db->pdo();
        if (self::issuedType($i) === 'tax_document') {
            ImportedIssuedDocumentPolicy::settleTaxDocument($pdo, $invoiceId);
            return;
        }
        $advance = FakturoidSourceDocument::untaxedAdvance($i);
        if ($advance > 0.0) {
            $pdo->prepare(
                "UPDATE invoices SET advance_paid_amount = GREATEST(0, LEAST(total_with_vat, ?))
                  WHERE id = ? AND invoice_type = 'invoice'"
            )->execute([$advance, $invoiceId]);
        }
    }

    /**
     * Zaplacená nezdaněná záloha převyšuje konečnou fakturu: přeplatek zůstává na
     * záloze a Fakturoid ho na faktuře neukazuje. Vrací text pro kontrolu, jinak null.
     *
     * @param array<string,mixed> $i
     */
    private static function advanceOverpayment(array $i): ?string
    {
        $advance = FakturoidSourceDocument::untaxedAdvance($i);
        $total = (float) ($i['total'] ?? 0);
        if ($advance <= 0.0 || $advance - $total <= 0.005) {
            return null;
        }
        return sprintf(
            'Uhrazená záloha %s převyšuje konečnou fakturu %s o %s. Faktura je zálohou uhrazená celá, '
                . 'přeplatek zálohy zkontrolujte (vrácení nebo další vyúčtování).',
            number_format($advance, 2, ',', ' '),
            number_format($total, 2, ',', ' '),
            number_format(round($advance - $total, 2), 2, ',', ' '),
        );
    }

    /**
     * Aplikuje namapovaný platební stav na čerstvě importovanou vydanou fakturu
     * (issue #121). Jen pro doklady ve stavu 'draft' (guard v WHERE) — existující
     * doklady, které už uživatel zpracoval, se nemění.
     *
     * Doklad opouští 'draft', proto dostává snapshoty (client/supplier/bank)
     * stejně jako file import (InvoiceImportService) a IssueInvoiceAction —
     * vystavené doklady musí mít zafixované údaje. sent_at = issue_date 12:00
     * (stejná aproximace jako file import). Storno bez mirror `cancellation`
     * záznamu — originál byl stornován už ve zdrojovém systému, interní storno
     * doklad by tu byl jen šum.
     *
     * @param ?array{status:string, paid_at:?string} $state  null = ponechat draft
     */
    private function applyIssuedPaymentState(int $invoiceId, int $clientId, int $currencyId, int $supplierId, ?array $state, string $fallbackPaidAt, string $issueDate, string $documentDate): void
    {
        if ($state === null) return;

        // Plátcovství DPH firmy k rozhodnému datu importovaného dokladu (tax ?? issue).
        $snapshots = $this->snapshots->build($clientId, $currencyId, $supplierId, null, $documentDate);

        $snapshotSql = 'client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ?';
        $snapshotParams = [
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
        ];

        if ($state['status'] === 'paid') {
            $this->db->pdo()->prepare(
                "UPDATE invoices SET status = 'paid', paid_at = ?, sent_at = ?, {$snapshotSql}
                  WHERE id = ? AND status = 'draft'"
            )->execute(array_merge(
                [$state['paid_at'] ?? $fallbackPaidAt, $issueDate . ' 12:00:00'],
                $snapshotParams,
                [$invoiceId],
            ));
        } elseif ($state['status'] === 'cancelled') {
            $this->db->pdo()->prepare(
                "UPDATE invoices SET status = 'cancelled', cancelled_at = NOW(), {$snapshotSql}
                  WHERE id = ? AND status = 'draft'"
            )->execute(array_merge($snapshotParams, [$invoiceId]));
        }
    }

    /**
     * Aplikuje 'paid' na čerstvě importovanou přijatou fakturu (issue #121).
     * Guard na status='draft' — createExpense může přes dedup guard vrátit
     * existující (už zpracovaný) doklad, ten nepřepisujeme.
     */
    private function applyPurchasePaymentState(int $purchaseId, int $supplierId, ?array $state, string $fallbackPaidAt): void
    {
        if ($state === null || $state['status'] !== 'paid') return;
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        )->execute([$state['paid_at'] ?? $fallbackPaidAt, $purchaseId, $supplierId]);
    }

    private function importExpenses(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing expenses (received invoices)…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté (expenses) z Fakturoid…');

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $skipped = 0; $failed = 0; $review = 0; $processed = 0;

        foreach ($this->fakturoid->getAll($supplierId, 'expenses.json', $query) as $exp) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($exp['id'] ?? 0);
            if ($fakturoidId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            try {
                if ($dryRun) {
                    $diffs = $this->dryRunExpense($exp, $supplierId);
                    $purchaseId = null;
                } else {
                    $purchaseId = $this->createExpense($exp, $supplierId, $userId);
                    $this->db->pdo()->prepare('UPDATE purchase_invoices SET fakturoid_id = ? WHERE id = ?')->execute([$fakturoidId, $purchaseId]);
                    $computed = $this->purCalc->recompute($purchaseId);
                    // Doklad vrácený dedup guardem už v systému byl a mohl ho někdo
                    // upravit — zdrojové částky se na něj nepřenášejí.
                    $diffs = $this->lastExpenseExisted ? [] : $this->alignExpenseToSource($purchaseId, $supplierId, $exp, $computed);
                    if ($downloadAttachments) {
                        $this->archiveExpensePdf($supplierId, $purchaseId, $exp);
                    }
                }
                $created++;
                if ($diffs !== []) {
                    $review++;
                    $reason = FakturoidSourceDocument::describe($diffs);
                    $this->jobs->appendLog($jobId, "Expense {$fakturoidId}: {$reason} Doklad zkontrolujte.");
                    $this->reportProblem('received', $exp, 'review', $reason, 'amounts', $purchaseId);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Expense {$fakturoidId}: " . $e->getMessage());
                $this->reportProblem('received', $exp, 'error', $e->getMessage(), FakturoidDocumentRejected::hintOf($e));
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed}, ke kontrole {$review} (z {$processed})." . ($dryRun ? ' (dry-run)' : ''));
        $this->reportAgenda('received', [
            'processed' => $processed, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'review' => $review,
        ]);
    }

    /**
     * Srovná čerstvě převzatý přijatý doklad na zdroj (#131): rozdíl rekapitulace DPH
     * do haléřového zaokrouhlení převezme jako `vat_overrides` (ruční rekapitulace dle
     * dokladu, § 73 ZDPH, kterou zná i editor), zaokrouhlení celkové částky jako
     * `rounding`. Rozdíl, který věrně převzít nejde, se nepřepisuje, doklad dostane
     * varování ke kontrole a vrátí se volajícímu do přehledu úlohy.
     *
     * @param array<string,mixed> $e
     * @param array{vat_breakdown: list<array<string,mixed>>} $computed
     * @return list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}>
     */
    private function alignExpenseToSource(int $purchaseId, int $supplierId, array $e, array $computed): array
    {
        $reverseCharge = !empty($e['transferred_tax_liability']);
        $summary = FakturoidSourceDocument::vatSummary($e);
        $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
        if ($diffs !== [] && !$reverseCharge) {
            $overrides = FakturoidSourceDocument::alignableOverrides($diffs, $computed['vat_breakdown']);
            if ($overrides !== null) {
                $this->purchaseRepo->setVatOverrides($purchaseId, $supplierId, $overrides);
                $computed = $this->purCalc->recompute($purchaseId);
                $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
            }
        }

        $rounding = FakturoidSourceDocument::roundingAdjustment($e);
        if ($rounding !== 0.0) {
            $this->purchaseRepo->setRounding($purchaseId, $supplierId, $rounding);
        }

        if ($diffs !== []) {
            $this->purchaseRepo->appendExtractionWarning(
                $purchaseId,
                $supplierId,
                'Import z Fakturoidu: ' . FakturoidSourceDocument::describe($diffs) . ' Doklad zkontrolujte proti originálu.',
            );
        }
        return $diffs;
    }

    /**
     * Zkouška nanečisto přijatého dokladu: sazby, přepočet a srovnání se zdrojem
     * stejně jako ostrý import, bez zápisu (#130). Rozdíl, který by ostrý import
     * srovnal rekapitulací, se nehlásí.
     *
     * @param array<string,mixed> $e
     * @return list<array{rate: float, base: float, vat: float, source_base: float, source_vat: float}>
     */
    private function dryRunExpense(array $e, int $supplierId): array
    {
        $subjId = (int) ($e['subject_id'] ?? 0);
        if ($this->resolveClient($subjId, $supplierId) === null && !isset($this->dryRunSubjects[$subjId])) {
            throw new FakturoidDocumentRejected('subject', "Dodavatel (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }
        $taxDate = (string) ($e['taxable_fulfillment_due'] ?? $e['issued_on'] ?? date('Y-m-d'));
        [$planned, $rates] = $this->expenseItems($e, $supplierId, $taxDate);
        $items = [];
        foreach ($planned as $idx => $item) {
            $items[] = $item + ['vat_rate_snapshot' => $rates[$idx]];
        }
        $reverseCharge = !empty($e['transferred_tax_liability']);
        $computed = InvoiceMath::compute($items, $reverseCharge, FakturoidSourceDocument::pricesIncludeVat($e));
        $summary = FakturoidSourceDocument::vatSummary($e);
        $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
        if ($diffs !== [] && !$reverseCharge) {
            $overrides = FakturoidSourceDocument::alignableOverrides($diffs, $computed['vat_breakdown']);
            if ($overrides !== null) {
                $computed = InvoiceMath::compute($items, $reverseCharge, FakturoidSourceDocument::pricesIncludeVat($e), $overrides);
                $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
            }
        }
        return $diffs;
    }

    /**
     * Položky přijatého dokladu se sazbou napárovanou sdíleným resolverem, vedle nich
     * procenta napárovaných sazeb (pro přepočet zkoušky nanečisto).
     *
     * @param array<string,mixed> $e
     * @return array{0: list<array<string,mixed>>, 1: list<float>}
     */
    private function expenseItems(array $e, int $supplierId, string $taxDate): array
    {
        // Přijatá strana OSS nemá (OSS je režim pro plnění, které POSKYTUJEME), ale sazbu
        // páruje týmž resolverem — filtr na zemi, platnost k datu a `is_reverse_charge`
        // se jí týká stejně: nula mohla dřív trefit reverse-charge sazbu, protože obě
        // mají 0,00 a rozlišilo je jen pořadí řádků.
        $items = [];
        $rates = [];
        foreach (($e['lines'] ?? []) as $idx => $line) {
            $rate = FakturoidSourceDocument::lineRate($line);
            // Nenamapovanou sazbu doklad ODMÍTNE, nefallbackuje na tuzemských 21 %.
            // Fallback tady dřív z německých 19 % udělal českou základní sazbu, takže se
            // cizí daň dostala na ř. 41 + KH B.3 jako nárok na odpočet — {@see VatRateMatch}
            // to zakazuje výslovně („tichý fallback na jinou sazbu je zakázaný") a vydaná
            // větev téhož importu se tak chová odjakživa (planIssuedItems hodí výjimku).
            // Import doklad zaznamená jako chybný s touhle hláškou v logu úlohy.
            $match = $this->planner->resolveDomesticRate($supplierId, $rate, $taxDate);
            if (!$match->found()) {
                throw new FakturoidDocumentRejected('vat_rate', sprintf('Položka č. %d: %s', $idx + 1, $match->message));
            }
            $rates[] = (float) $match->ratePercent;
            $items[] = [
                'description'            => (string) ($line['name'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'duration_minutes'       => TimeBilling::inferDurationMinutes(
                    $line['quantity'] ?? 1,
                    $line['unit_name'] ?? 'ks',
                ),
                'unit'                   => (string) ($line['unit_name'] ?? 'ks'),
                'unit_price_without_vat' => FakturoidSourceDocument::lineUnitPrice($line),
                'vat_rate_id'            => $match->id,
                'order_index'            => $idx,
            ];
        }
        return [$items, $rates];
    }

    private function createExpense(array $e, int $supplierId, int $userId): int
    {
        $subjId = (int) ($e['subject_id'] ?? 0);
        $vendorId = $this->resolveClient($subjId, $supplierId);
        if ($vendorId === null) {
            throw new FakturoidDocumentRejected('subject', "Dodavatel (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }
        $this->clients->markAsVendor($vendorId);
        $this->lastExpenseExisted = false;

        $issueDate = (string) ($e['issued_on'] ?? date('Y-m-d'));
        $taxDate   = (string) ($e['taxable_fulfillment_due'] ?? $issueDate);
        $dueDate   = (string) ($e['due_on'] ?? $issueDate);

        [$items] = $this->expenseItems($e, $supplierId, $taxDate);

        // Datum přijetí z dokladu, ne ze dne pullu (migrace 1848) — sdílené pravidlo
        // všech importních kanálů, {@see ImportedReceivedDatePolicy}.
        $receivedAt = ImportedReceivedDatePolicy::resolve(
            ImportedReceivedDatePolicy::modeForSupplier($this->db, $supplierId),
            $issueDate,
            $taxDate,
        );

        $payload = [
            'vendor_id'             => $vendorId,
            // #113: original_number = číslo dokladu dodavatele; number je jen interní číslo
            // přidělené Fakturoidem — to použij jen jako fallback, když original_number chybí.
            'vendor_invoice_number' => $this->sanitizeVendorNumber(
                trim((string) ($e['original_number'] ?? '')) !== ''
                    ? (string) $e['original_number']
                    : (string) ($e['number'] ?? '')
            ),
            'document_kind'         => 'invoice',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => $receivedAt['date'],
            // C6 (§ 73/1/a): received_at zůstává i po migraci 1848 jen údajem z dokladu,
            // ne vědomým zadáním účetní → 'import', aby VatLedgerService neposunul odpočet.
            'received_at_source'    => 'import',
            'currency_id'           => $this->resolveCurrencyId((string) ($e['currency'] ?? 'CZK'), $supplierId, isActive: false),
            'exchange_rate'         => isset($e['exchange_rate']) ? (float) $e['exchange_rate'] : null,
            // Kurz přinesl cizí systém, není odvozený z data (migrace 1303) → automatické
            // přenačtení po změně DUZP ho nepřepíše. ('fakturoid' zůstává jen pro historii —
            // nikdy se nezapsal a chová se stejně jako 'import'.)
            'exchange_rate_source'  => 'import',
            'reverse_charge'        => !empty($e['transferred_tax_liability']),
            // #128: režim cen dokladu. Bez sazby DPH zůstává 0 jako dřív, viz
            // FakturoidSourceDocument::pricesIncludeVat().
            'prices_include_vat'    => FakturoidSourceDocument::pricesIncludeVat($e),
            'language'              => 'cs',
            'items'                 => $items,
        ];
        // #229: poměrný odpočet DPH z Fakturoidu. Doklad od neplátce mimo reverse charge
        // zůstává bez nároku, jak ho založí výchozí pravidlo createDraft().
        $deduction = FakturoidSourceDocument::vatDeduction($e);
        if ($deduction !== null && !($this->vendorIsNonPayer($vendorId) && !$payload['reverse_charge'])) {
            $payload += $deduction;
        }
        if (!FakturoidSourceDocument::taxDeductible($e)) {
            $payload['tax_deductible'] = false;
        }
        // Dedup guard — re-import stejné faktury z Fakturoidu (typicky opakovaný pull)
        // by jinak hodil SQL 23000 duplicate key. Vrátíme existující ID.
        $existingId = $this->purchaseRepo->findIdByVendorInvoice(
            $supplierId, $vendorId,
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            $this->lastExpenseExisted = true;
            return $existingId;
        }

        $id = $this->purchaseRepo->createDraft($payload, $userId, $supplierId);
        if (!empty($items)) $this->purchaseRepo->replaceItems($id, $items);
        // Auto-ČNB kurz pro non-CZK fakturu pokud Fakturoid neobsahoval explicitní kurz
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            (string) ($e['currency'] ?? 'CZK'),
            (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? ''),
            $payload['exchange_rate'] ?? null,
        );
        // #121: Fakturoid eviduje výdaj jako zaplacený → promítni (jen na čerstvě
        // vytvořený doklad; dedup-vrácený existující doklad výše se nemění).
        $this->applyPurchasePaymentState(
            $id,
            $supplierId,
            ImportedPaymentStateMapper::fromFakturoid($e),
            $taxDate ?: $issueDate,
        );
        return $id;
    }

    // ── Helpers (shared s IdokladImportService logikou, jiná key names) ──

    private function resolveClient(int $fakturoidSubjectId, int $supplierId): ?int
    {
        if ($fakturoidSubjectId === 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $fakturoidSubjectId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function vendorIsNonPayer(int $vendorId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$vendorId]);
        $flag = $stmt->fetchColumn();
        return $flag !== false && $flag !== null && (int) $flag === 0;
    }

    private function resolveCurrencyId(string $code, int $supplierId, bool $isActive): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, ?, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $isActive ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    // Vlastní `loadVatRateMap()` + `matchVatRateId()` tu byly do sjednocení na
    // {@see \MyInvoice\Service\Vat\VatRateResolver}. Braly VŠECHNY sazby bez ohledu na
    // platnost (kvůli historickým dokladům 2019+ s dobovými sazbami CZ-15 / CZ-10) a
    // hledaly první shodné procento napříč tabulkou — tedy bez země i bez
    // `is_reverse_charge`. Resolver tentýž požadavek plní krokem 2 své kaskády (shoda
    // mimo platnost s varováním), ale k procentu se ptá i na zemi, takže polských 23 %
    // se nenaváže na českou sazbu.

    private function sanitizeVarsymbol(string $vs): string
    {
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '', $vs) ?? '';
        if ($vs === '') return 'FAKT-' . substr((string) random_int(1000, 9999), 0, 4);
        return substr($vs, 0, 20);
    }

    /**
     * Zajistí unikátnost varsymbolu vůči invoices(supplier_id, varsymbol).
     * Fakturoid běžně sdílí variabilní symbol mezi proformou a ostrou fakturou
     * (resp. dobropisem) → naše UNIQUE (uq_inv_supplier_varsymbol) by hodil
     * 1062 duplicate. Při kolizi disambiguujeme suffixem -N (ořez na 20 znaků
     * dle DB sloupce). Jako poslední záchrana null (UNIQUE povoluje více NULL).
     */
    private function uniqueVarsymbol(string $raw, int $supplierId): ?string
    {
        $base = $this->sanitizeVarsymbol($raw);
        if (!$this->varsymbolTaken($base, $supplierId)) return $base;
        for ($n = 2; $n <= 99; $n++) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, 20 - strlen($suffix)) . $suffix;
            if (!$this->varsymbolTaken($candidate, $supplierId)) return $candidate;
        }
        return null;
    }

    private function varsymbolTaken(string $vs, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $vs]);
        return $stmt->fetchColumn() !== false;
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'FAKT-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Stáhne Fakturoidem rendered PDF vydané faktury a uloží do imported_pdf_*
     * (paralelně s naším renderem pdf_path). Dedup přes SHA-256. Symetrické k iDokladu.
     */
    private function archiveIssuedPdf(int $supplierId, int $invoiceId, int $fakturoidId, array $inv): void
    {
        $pdf = $this->fakturoid->downloadInvoicePdf($supplierId, $fakturoidId);
        if ($pdf === null) return; // 204 = PDF se ještě generuje
        if (!$this->isStorablePdf($pdf)) {
            $this->logger->warning('Fakturoid: vydaná faktura nevrátila platné PDF, přeskočeno', [
                'supplier_id' => $supplierId,
                'invoice_id' => $invoiceId,
            ]);
            return;
        }

        $archiveRoot = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/invoices-imported'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices-imported');
        }
        $sha = hash('sha256', $pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) @file_put_contents($diskPath, $pdf);

        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $name = ((string) ($inv['number'] ?? 'invoice')) . '.pdf';
        $this->db->pdo()->prepare(
            'UPDATE invoices SET imported_pdf_path = ?, imported_pdf_hash = ?,
                                  imported_pdf_size_bytes = ?, imported_pdf_original_name = ?
              WHERE id = ?'
        )->execute([$relPath, $sha, strlen($pdf), $name, $invoiceId]);
    }

    /**
     * Stáhne přílohu výdaje (originální doklad od dodavatele) z Fakturoid a uloží
     * jako PDF přijaté faktury. Symetrické k iDokladu. Viz issue #261 —
     * Fakturoid vrací přílohy v poli `attachments`, ne ve skalárním `attachment`.
     */
    private function archiveExpensePdf(int $supplierId, int $purchaseInvoiceId, array $exp): void
    {
        $attachment = FakturoidExpenseAttachment::resolve($exp);
        if ($attachment === null) return; // výdaj bez přílohy

        $pdf = $this->fakturoid->downloadAttachment($supplierId, $attachment['download_url']);
        if ($pdf === null) return;
        // SEC-13 — druhá brána nad guardem v klientovi: do archivu nesmí nic než PDF.
        if (!$this->isStorablePdf($pdf)) {
            $this->logger->warning('Fakturoid: příloha výdaje není platné PDF, přeskočeno', [
                'supplier_id' => $supplierId,
                'purchase_invoice_id' => $purchaseInvoiceId,
            ]);
            return;
        }

        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $sha = hash('sha256', $pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) @file_put_contents($diskPath, $pdf);

        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $this->purchaseRepo->setPdfMetadata($purchaseInvoiceId, $supplierId, $relPath, $sha, strlen($pdf), $attachment['filename']);
    }

    /**
     * Obsah smí do archivu jen když je to reálné PDF v rozumné velikosti.
     * Magic bytes se hledají po odstranění případného BOM/whitespace na začátku,
     * stejně jako u ostatních upload cest (viz BankStatementAction).
     */
    private function isStorablePdf(string $content): bool
    {
        $len = strlen($content);
        if ($len < 5 || $len > self::MAX_ARCHIVED_PDF_BYTES) return false;
        return str_starts_with(ltrim($content, "\x00\x09\x0a\x0d\x20\xef\xbb\xbf"), '%PDF-');
    }

    private function loadBookmark(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT fakturoid_last_imported_at FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) return null;
        // Fakturoid `updated_since` chce ISO 8601 (s timezone)
        return date('c', strtotime((string) $val));
    }
}
