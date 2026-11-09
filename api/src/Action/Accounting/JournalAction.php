<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Action\Accounting\Reports\DimensionFilterParam;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\InvoiceListDetailsRepository;
use MyInvoice\Repository\DimensionListSummaryRepository;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\JournalListExtras;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\AccountCodeRange;
use MyInvoice\Service\Accounting\AccountCodeRangeException;
use MyInvoice\Service\Accounting\AutomationProvenanceService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\JournalEntryDeletionRules;
use MyInvoice\Service\Accounting\JournalHistoryService;
use MyInvoice\Service\Accounting\JournalIntegrityService;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\Accounting\InvoicePostingProposal;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\ManualJournalEntryException;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingOriginService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\JournalPdfRenderer;
use MyInvoice\Service\Ai\AiPostingOverrideResolver;
use MyInvoice\Service\Ai\EmbeddingWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Účetní deník (journal) — REST API (Epic F1). Zaúčtování jde VÝHRADNĚ přes
 * {@see PostingService} (jediný zdroj pravdy pro podvojnost, období, idempotenci).
 *
 *   GET  /api/accounting/journal                         — stránkovaný seznam (filtry)
 *   GET  /api/accounting/journal/{id}                    — zápis + řádky (kód/název účtu, měna)
 *   POST /api/accounting/journal                         — ruční zápis — účetní|admin
 *   POST /api/accounting/journal/post-invoice/{id}       — zaúčtuj vydanou fakturu (idempotentní)
 *   POST /api/accounting/journal/post-purchase/{id}      — zaúčtuj přijatou fakturu (idempotentní)
 *   POST /api/accounting/journal/{id}/reverse            — storno (zrcadlový protizápis)
 *   GET  /api/accounting/reports/journal/export           — export PDF/XLSX (audit 2026-07)
 *   GET  /api/accounting/journal/{id}/history             — SYSTEM VERSIONING timeline (audit 2026-07)
 *   GET  /api/accounting/journal/{id}/related             — protějšky doklad ↔ úhrada (JournalRelatedAction)
 */
final class JournalAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;
    use DimensionFilterParam;

    private const MAX_PER_PAGE = 200;

    /**
     * Strop počtu zápisů, které filtr `?integrity=` promítne do WHERE ... IN (…).
     * Nálezů integrity bývají jednotky až desítky; strop je pojistka proti
     * neomezenému IN listu, kdyby se v datech něco rozjelo.
     */
    private const INTEGRITY_FILTER_LIMIT = 1000;

    /** Strop počtu dokladů v jedné dávce hromadného zaúčtování (audit Fáze A review). */
    private const BULK_POST_LIMIT = 500;

    public function __construct(
        private readonly PostingService $posting,
        private readonly AiPostingOverrideResolver $aiOverrides,
        private readonly EmbeddingWriter $embeddingWriter,
        private readonly JournalEntryRepository $journal,
        private readonly InvoiceListDetailsRepository $listDetails,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentSeriesService $series,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly AccountingPeriodRepository $periods,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly DocumentRepostService $repost,
        private readonly PostingOriginService $postingOrigin,
        private readonly BankPostingService $bankPosting,
        private readonly AutomationProvenanceService $automationProvenance,
        private readonly JournalExportService $exportService,
        private readonly JournalPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly JournalHistoryService $history,
        private readonly JournalLinkService $links,
        private readonly JournalEntryAttachmentRepository $attachments,
        private readonly JournalAttachmentStorage $attachmentStorage,
        private readonly JournalIntegrityService $integrity,
        private readonly LoggerInterface $log,
        private readonly DimensionService $dimensions,
        private readonly DimensionListSummaryRepository $dimensionSummaries,
        private readonly JournalListExtras $listExtras,
        private readonly OtherItemService $otherItems,
        private readonly ManualJournalEntryService $manualEntries,
        private readonly InternalDocumentService $internalDocuments,
        private readonly InvoicePostingProposal $proposal,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = (int) ($q['per_page'] ?? 50);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset = ($page - 1) * $perPage;

        try {
            $filters = $this->parseFilters($q, $supplierId);
        } catch (AccountCodeRangeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $filters['sort_key'] = is_scalar($q['sort_key'] ?? null) ? (string) $q['sort_key'] : '';
        $filters['sort_dir'] = is_scalar($q['sort_dir'] ?? null) ? (string) $q['sort_dir'] : '';
        if (!$this->applyDimensionFilter($request, $response, $supplierId, $filters, $err)) return $err;
        $result = $this->journal->paginate($supplierId, $filters, $perPage, $offset);
        $provenance = $this->automationProvenance->forJournalEntries(
            $supplierId,
            array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
        );
        // Odznak „má protějšek" (doklad ↔ úhrada) — batch přes celou stránku,
        // viz JournalLinkService::hasRelatedMap(). Bez něj by účetní musel rozbalit
        // každý řádek, aby zjistil, jestli je zápis na něco navázaný.
        $related = $this->links->hasRelatedMap($supplierId, $result['items']);
        $includePosting = ($q['include_posting_accounts'] ?? null) === '1';
        $includeVat = ($q['include_vat_breakdown'] ?? null) === '1';
        $includeDimensions = ($q['include_dimensions'] ?? null) === '1';
        $entryIds = array_map(static fn (array $item): int => (int) $item['id'], $result['items']);
        $linesByEntry = $includePosting
            ? $this->journal->linesForEntries($entryIds, $supplierId)
            : [];
        $dimensionLabels = $includeDimensions ? $this->dimensionSummaries->forJournalEntries($supplierId, $entryIds) : [];
        // Poznámka a počet dokumentů jen pro zapnuté sloupce, jedním dotazem za stránku.
        $includeNotes = ($q['include_notes'] ?? null) === '1';
        $includeDocuments = ($q['include_documents'] ?? null) === '1';
        $notes = $includeNotes ? $this->listExtras->notes($supplierId, $entryIds) : [];
        $documentCounts = $includeDocuments ? $this->listExtras->documentCounts($supplierId, $entryIds) : [];
        // Odkaz na interní doklad, jehož zaúčtováním nebo stornem zápis je (migrace 2193).
        $internalDocuments = $this->internalDocuments->forJournalEntries($supplierId, $entryIds);
        $vatBySource = [];
        if ($includeVat) {
            foreach (['invoice', 'purchase_invoice'] as $source) {
                $ids = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) $item['source_id'],
                    array_filter($result['items'], static fn (array $item): bool => $item['source_type'] === $source && $item['source_id'] !== null),
                )));
                $vatBySource[$source] = $this->listDetails->vatForDocuments($supplierId, $source, $ids);
            }
        }
        foreach ($result['items'] as &$item) {
            $item['automation'] = $provenance[(int) $item['id']] ?? null;
            $item['has_related'] = isset($related[(int) $item['id']]);
            $item['internal_document'] = $internalDocuments[(int) $item['id']] ?? null;
            if ($includePosting) {
                $item['posting_lines'] = $linesByEntry[(int) $item['id']] ?? [];
            }
            if ($includeVat) {
                $item['vat_breakdown'] = $vatBySource[$item['source_type']][(int) $item['source_id']]['vat_breakdown'] ?? [];
                $item['source_currency'] = $vatBySource[$item['source_type']][(int) $item['source_id']]['source_currency'] ?? null;
            }
            if ($includeDimensions) {
                $item['dimension_labels'] = $dimensionLabels[(int) $item['id']] ?? [];
            }
            if ($includeNotes) {
                $item['note_preview'] = $notes[(int) $item['id']]['preview'] ?? null;
                $item['note_count'] = $notes[(int) $item['id']]['count'] ?? 0;
            }
            if ($includeDocuments) {
                $item['document_count'] = $documentCounts[(int) $item['id']] ?? 0;
            }
        }
        unset($item);
        return Json::ok($response, [
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/accounting/reports/journal/export — export deníku (audit 2026-07,
     * nález „Export a tisk účetního deníku"). Respektuje PŘESNĚ stejné filtry jako
     * list() — žádná vlastní logika filtrování navíc (§13 ZoÚ — kniha = to, co vidí
     * účetní na obrazovce).
     */
    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $filters = $this->parseFilters($request->getQueryParams(), $supplierId);
        } catch (AccountCodeRangeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if (!$this->applyDimensionFilter($request, $response, $supplierId, $filters, $err)) return $err;
        try {
            $data = $this->exportService->build($supplierId, $filters);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => 'ucetni-denik-' . ($filters['date_from'] ?? 'vse') . '_' . ($filters['date_to'] ?? 'vse') . '.pdf',
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->journal($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export deníku se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Export se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'journal', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * GET /api/accounting/journal/{id}/history — auditní timeline (audit 2026-07,
     * nález „Historie účetního zápisu v UI"). Viz {@see JournalHistoryService}.
     */
    public function history(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $data = $this->history->build($id, $supplierId);
        if ($data === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }
        return Json::ok($response, $data);
    }

    /**
     * @param array<string,mixed> $q query params (Request::getQueryParams())
     * @throws AccountCodeRangeException neplatný rozsah účtů (akce vrací 422)
     * @return array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float}
     */
    private function parseFilters(array $q, int $supplierId): array
    {
        $filters = [];
        // „Jen nálezy kontroly integrity deníku" — dashboard akce journal_integrity
        // umí prokliknout jen JEDEN zápis, takže u víc nálezů uživatel skončil na
        // nefiltrovaném deníku a neměl jak zjistit, co vlastně nesedí. Seznam se
        // počítá NAŽIVO (ne z journal_integrity_findings, kde je jen vzorek 100
        // nálezů z posledního nočního běhu), aby filtr odpovídal aktuálním datům.
        if (($q['integrity'] ?? '') === JournalIntegrityService::TYPE_AMOUNT_MISMATCH) {
            $filters['entry_ids'] = $this->integrity->amountMismatchEntryIds($supplierId, self::INTEGRITY_FILTER_LIMIT);
        }
        if (!empty($q['document_no'])) $filters['document_no'] = mb_substr(trim((string) $q['document_no']), 0, 50);
        if (!empty($q['period_id']))   $filters['period_id'] = (int) $q['period_id'];
        if (!empty($q['date_from']))   $filters['date_from'] = (string) $q['date_from'];
        if (!empty($q['date_to']))     $filters['date_to'] = (string) $q['date_to'];
        if (!empty($q['source_type'])) $filters['source_type'] = (string) $q['source_type'];
        if (!empty($q['source_id']))   $filters['source_id'] = (int) $q['source_id'];
        // Přesný odskok na jeden zápis (deep-link ?entry_id=) — bez něj se filtrovalo
        // jen datem zápisu a vedle hledaného se vypsal celý ten den.
        if (!empty($q['entry_id']))    $filters['entry_id'] = (int) $q['entry_id'];
        if (array_key_exists('posted', $q) && $q['posted'] !== '') {
            $filters['posted'] = filter_var($q['posted'], FILTER_VALIDATE_BOOLEAN);
        }
        if (in_array(($q['automation'] ?? null), ['auto', 'approved', 'manual'], true)) {
            $filters['automation'] = (string) $q['automation'];
        }
        // Stav stornování. `reversed` = zápis, který byl stornovaný; `reversal` = samotný
        // protizápis; `none` = zápis, kterého se stornování netýká. Bez filtru se v deníku
        // stornované dvojice hledají očima, protože stojí u sebe jen když se nefiltruje datem.
        if (in_array(($q['reversal'] ?? null), ['reversed', 'reversal', 'any', 'none'], true)) {
            $filters['reversal'] = (string) $q['reversal'];
        }
        // Rozsah účtů je prefixový včetně krajů (AccountCodeRange): 5 až 5 = celá třída 5.
        $from = $q['account_from'] ?? '';
        $to = $q['account_to'] ?? '';
        if (!is_string($from) || !is_string($to)) {
            throw new AccountCodeRangeException('account_from a account_to musí být jedna hodnota.');
        }
        $range = AccountCodeRange::parse($from, $to);
        if ($range->from !== '') $filters['account_from'] = $range->from;
        if ($range->to !== '')   $filters['account_to'] = $range->to;
        // Jedno vyhledávací pole přijímá i přesný kód účtu. Překládá se na tentýž
        // rozsah jako explicitní účetní filtr, takže částka i export zůstanou na
        // společné cestě. Explicitní rozsah má přednost a q pak zůstává fulltextem.
        if (!empty($q['q'])) {
            $query = mb_substr(trim((string) $q['q']), 0, 100);
            $account = !isset($filters['account_from']) && !isset($filters['account_to'])
                ? $this->findAccountFromSearch($supplierId, $query)
                : null;
            if ($account !== null) {
                $code = (string) $account['account_code'];
                // Prefixový rozsah: syntetika 221 až 221 zahrne i své analytiky.
                $filters['account_from'] = $code;
                $filters['account_to'] = $code;
            } else {
                $filters['q'] = $query;
            }
        }
        if (isset($q['amount_from']) && $q['amount_from'] !== '' && is_numeric($q['amount_from'])) {
            $filters['amount_from'] = (float) $q['amount_from'];
        }
        if (isset($q['amount_to']) && $q['amount_to'] !== '' && is_numeric($q['amount_to'])) {
            $filters['amount_to'] = (float) $q['amount_to'];
        }
        return $filters;
    }

    /**
     * Filtr na hodnotu dimenze (`dimension_value_id`, `dimension_descendants`) se
     * stejnými parametry i sémantikou řádku jako sestavy. Seznam i export ho sdílí.
     *
     * @param array<string,mixed> $filters
     */
    private function applyDimensionFilter(Request $request, Response $response, int $supplierId, array &$filters, ?Response &$err): bool
    {
        $dimension = $this->dimensionFilterParam($this->dimensions, $request, $response, $supplierId, $err);
        if ($dimension === false) {
            return false;
        }
        if ($dimension !== null) {
            $filters['dimension'] = $dimension;
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    private function findAccountFromSearch(int $supplierId, string $query): ?array
    {
        $exact = $this->accounts->findByCode($supplierId, $query);
        if ($exact !== null) return $exact;

        $normalized = preg_replace('/[.\s_-]+/u', '', $query) ?? '';
        if ($normalized === '' || !ctype_digit($normalized)) return null;

        $matches = array_values(array_filter(
            $this->accounts->listForTenant($supplierId, true),
            static fn (array $account): bool => preg_replace('/[.\s_-]+/u', '', (string) $account['account_code']) === $normalized,
        ));
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $entry = $this->journal->find($id, $supplierId);
        if ($entry === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $accMap = $this->accounts->idToAccountMap($supplierId);
        // Dimenze řádků (Firma → Dimenze) — jen id hodnot, názvy má klient v číselníku.
        $lineDims = $this->dimensions->entryLineDimensions($supplierId, $id);
        $lineSplits = $this->dimensions->entryLineSplits($supplierId, $id);
        $entry['lines'] = array_map(static function (array $line) use ($accMap, $lineDims, $lineSplits): array {
            $acc = $accMap[(int) $line['account_id']] ?? null;
            $line['account_code'] = $acc['code'] ?? null;
            $line['account_name'] = $acc['name'] ?? null;
            $line['dimensions'] = (object) ($lineDims[(int) $line['id']] ?? []);
            $line['dimension_splits'] = (object) ($lineSplits[(int) $line['id']] ?? []);
            return $line;
        }, $entry['lines']);
        // Jednoduchý zápis: dvojice a posouzení zápisu bez dvojic. Složený zápis bez
        // posouzení klíče nedostane, odpověď zůstává jako dřív.
        $entry += $this->journal->pairView($supplierId, $id, $entry['lines']);
        $entry['automation'] = $this->automationProvenance->forJournalEntries($supplierId, [$id])[$id] ?? null;
        // Měkké vazby na doklady (migrace 1514) — detail zápisu je jediné místo, kde
        // je uživatel spravuje, takže chodí rovnou s ním, ne dalším requestem.
        // VČETNĚ popisu dokladu: bez něj by se navázaná faktura v UI tvářila jako
        // smazaná, protože stejný seznam z /links popis nese.
        $entry['links'] = $this->links->documentLinks($supplierId, $id);
        $entry['internal_document'] = $this->internalDocuments->forJournalEntries($supplierId, [$id])[$id] ?? null;

        // ETag = row_version (silný validátor) — klient ho pošle zpět v If-Match (§ CAS).
        return Json::ok($response, $entry)->withHeader('ETag', '"' . $entry['row_version'] . '"');
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $entryDate = trim((string) ($body['entry_date'] ?? ''));
        if (!$this->isDate($entryDate)) {
            return Json::error($response, 'validation_failed', 'entry_date musí být datum (YYYY-MM-DD).', 422);
        }
        $meta = array_merge($this->auditMeta($request), [
            'entry_date'  => $entryDate,
            'description' => $this->nullableString($body['description'] ?? null),
            'document_no' => $this->nullableString($body['document_no'] ?? null),
        ]);

        // Tvar řádků, dimenze i volitelné měkké vazby na doklady (migrace 1514) ověřuje
        // sdílená služba ručních zápisů, stejnou cestou účtují i interní doklady.
        // Jednoduchý zápis: `pairs` (účet MD, účet Dal, částka). Ve složené formě se dvojice
        // převedou na řádky bez čísla dvojice; `lines` zůstává pro oba tvary.
        if (array_key_exists('pairs', $body) && array_key_exists('lines', $body)) {
            return Json::error($response, 'validation_failed', 'Zadejte buď pairs, nebo lines.', 422);
        }
        try {
            $lines = array_key_exists('pairs', $body)
                ? $this->manualEntries->normalizePairs($supplierId, $body['pairs'], $this->manualEntries->singleAt($supplierId, $entryDate))
                : $this->manualEntries->normalizeLines($supplierId, $body['lines'] ?? null);
            $links = $this->manualEntries->normalizeLinks($supplierId, $body['links'] ?? []);
        } catch (ManualJournalEntryException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        // Opt-in číselná řada ID pro ruční zápisy (Epic F4, R13) vydává číslo uvnitř
        // transakce zápisu; opakování po odvolané transakci řeší služba.
        $foreign = $this->db->pdo()->inTransaction();
        try {
            $entryId = $this->manualEntries->post($supplierId, $lines, $meta, $links);
        } catch (\Throwable $e) {
            if ($foreign && TransactionFailure::aborted($e)) {
                throw $e;
            }
            return $this->mapPostingError($response, $e);
        }

        $created = $this->journal->find($entryId, $supplierId);
        $created['links'] = $this->links->documentLinks($supplierId, $entryId);
        if ($this->posting->dimensionWarnings() !== []) {
            $created['dimension_warnings'] = $this->posting->dimensionWarnings();
        }

        return Json::ok($response, $created, 201);
    }

    public function postInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->postDocumentFrom($request, $response, $args, 'invoices', 'invoice');
    }

    public function postPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->postDocumentFrom($request, $response, $args, 'purchase_invoices', 'purchase_invoice');
    }

    /**
     * POST /api/accounting/journal/post-invoices-bulk — hromadné zaúčtování vydaných
     * faktur z výběru v seznamu. Tělo { ids: [1,2,3] }.
     */
    public function postInvoicesBulk(Request $request, Response $response): Response
    {
        return $this->postBulk($request, $response, 'invoice');
    }

    /**
     * POST /api/accounting/journal/post-purchases-bulk — hromadné zaúčtování přijatých
     * faktur z výběru v seznamu. Tělo { ids: [1,2,3] }.
     */
    public function postPurchasesBulk(Request $request, Response $response): Response
    {
        return $this->postBulk($request, $response, 'purchase_invoice');
    }

    /**
     * Sdílená smyčka hromadného zaúčtování. Každý doklad se účtuje SAMOSTATNĚ přes
     * {@see DocumentAutoPoster::post()} (vlastní transakce v PostingService), takže
     * jeden neúspěšný doklad NEzablokuje zbytek dávky. Vrací souhrnný report:
     *   { posted: [...ids], failed: [{id, error_code, message}] }
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    private function postBulk(Request $request, Response $response, string $sourceType): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $rawIds = $body['ids'] ?? null;
        if (!is_array($rawIds) || $rawIds === []) {
            return Json::error($response, 'validation_failed', 'ids musí obsahovat alespoň jedno ID dokladu.', 422);
        }
        // Deduplikace + jen kladná celá čísla (chráníme před nesmyslným vstupem).
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($v) => (int) $v,
            $rawIds,
        ), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return Json::error($response, 'validation_failed', 'ids neobsahuje žádné platné ID dokladu.', 422);
        }
        // Strop dávky (adversariální review Fáze A) — bez limitu by šlo poslat obří
        // pole ID a vynutit dlouhý request (N samostatných transakcí za sebou).
        if (count($ids) > self::BULK_POST_LIMIT) {
            return Json::error(
                $response,
                'validation_failed',
                'Najednou lze zaúčtovat max ' . self::BULK_POST_LIMIT . ' dokladů — rozděl výběr na menší dávky.',
                422,
            );
        }

        // Hromadné zaúčtování jen zajišťuje zápis; převzatý předpis ve skupině nechá být.
        $meta = $this->auditMeta($request) + ['posting_group_idempotent' => true];
        $userId = $this->userId($request);

        $posted = [];
        $failed = [];
        foreach ($ids as $docId) {
            try {
                $this->autoPoster->post($supplierId, $sourceType, $docId, $meta, $userId);
                $posted[] = $docId;
            } catch (UnbalancedEntryException $e) {
                $failed[] = ['id' => $docId, 'error_code' => 'unbalanced_entry', 'message' => $e->getMessage()];
            } catch (PostingException $e) {
                $failed[] = ['id' => $docId, 'error_code' => $e->errorCode, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                // Infrastrukturní selhání jednoho dokladu (adversariální review) nesmí
                // utnout zbytek dávky — zaznamenej a pokračuj dalším ID.
                $failed[] = ['id' => $docId, 'error_code' => 'internal', 'message' => $e->getMessage()];
            }
        }

        return Json::ok($response, ['posted' => $posted, 'failed' => $failed]);
    }

    /**
     * Segment z URL na `journal_entries.source_type`.
     *
     * Jediné místo, kde se ten překlad dělá — tři endpointy (plán, přeúčtování,
     * původ kontace) musí odpovědět na TENTÝŽ doklad, jinak by plán mluvil o jiném
     * zápisu než operace. Bankovní pohyb má v deníku zdroj `bank`, ne
     * `bank_transaction`.
     *
     * Veřejná schválně: špatné mapování by přeúčtovalo JINÝ doklad téhož id (bankovní
     * pohyb #30628 vs. faktura #30628), a to je přesně druh chyby, kterou musí chytit
     * test, ne až účetní v deníku.
     */
    public static function repostSourceType(string $segment): string
    {
        return match ($segment) {
            'purchase-invoices' => 'purchase_invoice',
            'bank-transactions' => 'bank',
            default             => 'invoice',
        };
    }

    /**
     * GET /api/accounting/journal/repost-plan/{source}/{id} — co se stane, když se
     * doklad přeúčtuje. Dialog „Přeúčtovat" z toho staví hlášku i předvyplněné řádky.
     *
     * Rozhodnutí přepsat × stornovat × odmítnout dělá {@see DocumentRepostService},
     * tedy TATÁŽ služba, která ho pak provede — náhled se s výsledkem nemůže rozejít.
     *
     * POST s tělem `{ lines: [...] }` vrátí rozhodnutí pro ty konkrétní opravené řádky
     * (přepis na místě v zamčeném datu, nebo důvod storna). Nic nezapisuje.
     */
    public function repostPlan(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $sourceType = self::repostSourceType((string) ($args['source'] ?? ''));
        $docId = (int) ($args['id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID dokladu.', 422);
        }

        try {
            if (strtoupper($request->getMethod()) === 'POST') {
                $body = (array) ($request->getParsedBody() ?? []);
                $lines = is_array($body['lines'] ?? null) ? $this->parsePostingLines($body['lines']) : null;
                if ($lines === null) {
                    return Json::error(
                        $response,
                        'validation_failed',
                        'Každý řádek potřebuje account_code, side (debit/credit) a kladnou částku.',
                        422,
                    );
                }
                return Json::ok($response, $this->repost->previewPlan($supplierId, $sourceType, $docId, $lines));
            }
            return Json::ok($response, $this->repost->plan($supplierId, $sourceType, $docId));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    /**
     * GET /api/accounting/journal/posting-origin/{source}/{id} — podle jaké šablony
     * kontace vznikla a kde se ta šablona opraví.
     *
     * Sekce Zaúčtování ukazovala jen výsledné účty; předkontace (`posting_rules`),
     * která je určila, v UI nebyla nikde — takže se opravovala kontace dokladu
     * místo šablony. Odpověď staví {@see PostingOriginService}.
     */
    public function postingOrigin(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $docId = (int) ($args['id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID dokladu.', 422);
        }

        return Json::ok($response, $this->postingOrigin->describe(
            $supplierId,
            self::repostSourceType((string) ($args['source'] ?? '')),
            $docId,
        ));
    }

    /**
     * POST /api/accounting/journal/repost/{source}/{id} — přeúčtování dokladu.
     *
     * Tělo: `{ lines: [{account_code, side, amount}], description?, confirm_date_shift?, dimensions? }`;
     * `dimensions` = `{header, items?}` dimenze dokladu, uložené v téže transakci.
     * Řádky jsou POVINNÉ: přeúčtování bez nich by bylo obyčejné znovuzaúčtování, na
     * které je `post-purchase/{id}`.
     */
    public function repost(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $sourceType = self::repostSourceType((string) ($args['source'] ?? ''));
        // Bankovní pohyb žádnou hlavičku „zaúčtováno kým" nemá (a `bank_transactions`
        // ani sloupec `supplier_id` — tenant se odvozuje z výpisu), takže se tabulka
        // dokladu doplňuje jen u faktur. Vlastnictví pohybu ověřuje
        // {@see \MyInvoice\Service\Accounting\Bank\BankPostingService::prepareRepostLines()}
        // a nezávisle na něm i `findBySource`, který hledá zápis TÉTO firmy.
        $table = match ($sourceType) {
            'invoice'          => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            default            => null,
        };
        $docId = (int) ($args['id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID dokladu.', 422);
        }
        if ($table !== null && $this->fetchDocDate($table, $supplierId, $docId) === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $lines = is_array($body['lines'] ?? null) ? $this->parsePostingLines($body['lines']) : null;
        if ($lines === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Každý řádek potřebuje account_code, side (debit/credit) a kladnou částku.',
                422,
            );
        }

        $meta = array_merge($this->auditMeta($request), array_filter([
            'description' => $this->nullableString($body['description'] ?? null),
        ], static fn ($v) => $v !== null));
        if (array_key_exists('expected_posting_entries', $body)) {
            $expected = $body['expected_posting_entries'];
            if (!is_array($expected) || !array_is_list($expected) || count($expected) > 5000) {
                return Json::error($response, 'validation_failed', 'Neplatný seznam zápisů zaúčtování.', 422);
            }
            foreach ($expected as $member) {
                if (!is_array($member) || !is_int($member['id'] ?? null) || $member['id'] <= 0
                    || !is_int($member['row_version'] ?? null) || $member['row_version'] <= 0) {
                    return Json::error($response, 'validation_failed', 'Neplatná verze zápisu zaúčtování.', 422);
                }
            }
            $meta['expected_posting_entries'] = $expected;
        }

        // Dimenze z dialogu se ukládají v TÉŽE transakci jako přeúčtování: nový zápis
        // si je při zaúčtování orazítkuje (a řádek nákladu rozdělí podle položek).
        // Odmítnuté přeúčtování tak nenechá doklad s dimenzemi, které deník nenese.
        $dimensionBody = is_array($body['dimensions'] ?? null) ? $body['dimensions'] : null;
        $dimensionDocType = match ($sourceType) {
            'purchase_invoice' => 'purchase_invoice',
            'bank'             => 'bank_transaction',
            default            => 'invoice',
        };
        $dimensionResult = null;
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        $ownTx ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT repost_with_dimensions');
        try {
            if ($dimensionBody !== null) {
                $dimensionResult = $this->dimensions->saveDocument(
                    $supplierId,
                    $dimensionDocType,
                    $docId,
                    (array) ($dimensionBody['header'] ?? []),
                    array_key_exists('items', $dimensionBody) ? (array) $dimensionBody['items'] : null,
                    true,
                );
            }
            $result = $this->repost->repost(
                $supplierId,
                $sourceType,
                $docId,
                $lines,
                $meta,
                (bool) ($body['confirm_date_shift'] ?? false),
            );
            $ownTx ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT repost_with_dimensions');
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                if (!$ownTx) {
                    throw $e;
                }
            } elseif ($pdo->inTransaction()) {
                $ownTx ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT repost_with_dimensions');
            }
            if ($e instanceof DimensionException) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            return $this->mapPostingError($response, $e);
        }
        if ($dimensionResult !== null) {
            $this->logger->log(
                'dimension.document_updated',
                $this->userId($request),
                'dimension',
                $docId,
                [
                    'doc_type'        => $dimensionDocType,
                    'via'             => 'repost',
                    'header'          => $dimensionResult['header'],
                    'items'           => $dimensionResult['items'],
                    'restamped_lines' => $dimensionResult['restamp']['lines'],
                    'locked_period'   => $dimensionResult['restamp']['locked'],
                    'entry_id'        => $result['entry_id'],
                ],
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );
        }

        // Po stornu původního zápisu je doklad odemčený jen do chvíle, než opravu
        // zapíšeme — obojí je v jedné transakci, takže zámek dokladu má zůstat.
        // COALESCE zachová původního „kdo zaúčtoval" (§11), když už tam někdo je.
        if ($table !== null) {
            $this->db->pdo()->prepare(
                "UPDATE {$table}
                    SET booked_at = COALESCE(booked_at, NOW()),
                        booked_by = COALESCE(booked_by, ?)
                  WHERE id = ? AND supplier_id = ?"
            )->execute([$this->userId($request), $docId, $supplierId]);
        }

        $entry = $this->journal->find((int) $result['entry_id'], $supplierId);
        $entry['repost'] = $result;
        return Json::ok($response, $entry);
    }

    /**
     * NÁHLED kontace dokladu — tatáž cesta jako zaúčtování, jen bez zápisu do deníku.
     *
     *   GET /api/accounting/invoices/{id}/posting-preview
     *   GET /api/accounting/purchase-invoices/{id}/posting-preview
     *
     * Vzniklo proto, že „Zaúčtovat" doklad zapsalo rovnou a uživatel návrh kontace
     * nikdy neviděl. U dokladu s kurzovým rozdílem má zápis víc než dva řádky a slepé
     * odklepnutí je přesně ten druh úkonu, po kterém se chyba hledá zpětně v deníku.
     *
     * Náhled volá TYTÉŽ `buildFrom*` metody jako zaúčtování — kdyby si počítal vlastní
     * návrh, rozešel by se s tím, co se opravdu zaúčtuje, a byl by horší než žádný.
     */
    public function postingPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $sourceType = (string) ($args['source'] ?? '') === 'purchase-invoices' ? 'purchase_invoice' : 'invoice';
        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $docId = (int) ($args['id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID dokladu.', 422);
        }

        $docDate = $this->fetchDocDate($table, $supplierId, $docId);
        if ($docDate === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        // Override z AI klasifikace je součástí NÁVRHU, ne až zaúčtování — jinak by
        // uživatel potvrzoval jiné řádky, než jaké se nakonec zapíšou. Řádky skládá
        // sdílený návrh ({@see InvoicePostingProposal}), tentýž jako zaúčtování.
        $aiOverride = $sourceType === 'purchase_invoice'
            ? $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId)
            : null;

        try {
            $lines = $this->proposal->lines($supplierId, $sourceType, $docId, $docDate);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, [
            'source_type'   => $sourceType,
            'doc_id'        => $docId,
            'entry_date'    => $docDate,
            'lines'         => $this->describeLines($supplierId, $lines),
            'balanced'      => self::linesBalanced($lines),
            'ai_override'   => $aiOverride,
            // Účtotvorná dimenze: kam zaúčtování přesune výsledkové syntetiky (návrh je nese dál).
            'dimension_routing' => $this->dimensionRouting($supplierId, $sourceType, $docId, $lines, $docDate),
            'already_posted' => $this->existingEntryId($supplierId, $sourceType, $docId),
            'rule_basis'    => $sourceType === 'purchase_invoice'
                ? $this->ruleBasis($supplierId, $docId)
                : null,
        ]);
    }

    /**
     * Náhled účtotvorné dimenze k návrhu kontace. Chyba náhledu (např. chybějící účet,
     * kterou návrh hlásí jinde) nesmí shodit celý náhled — vrátí se prázdný seznam.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function dimensionRouting(int $supplierId, string $sourceType, int $docId, array $lines, string $entryDate): array
    {
        try {
            return $this->posting->dimensionRoutingPreview($supplierId, $sourceType, $docId, $lines, $entryDate);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Podklad pro nabídku „příště účtovat stejně" — pravidlo klasifikace výdaje
     * postavené z opravené kontace.
     *
     * Vrací se JEN tehdy, když pravidlo jde postavit bez hádání: musí být znám dodavatel
     * (pravidlo se na něj váže) a druh výdaje musí být na dokladu jednoznačný. Druh totiž
     * neurčuje jen účet — `small_asset` zakládá kartu evidence, `fixed_asset` míří na 042
     * a odpisuje se. Pravidlo se špatným druhem by tiše rozhodovalo i o těchhle věcech,
     * takže u dokladu, kde se druhy míchají, se nabídka NEDĚLÁ.
     *
     * @return array{vendor_client_id:int, vendor_name:string, expense_kind:string}|null
     */
    private function ruleBasis(int $supplierId, int $docId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.vendor_id, c.company_name,
                    COUNT(DISTINCT it.expense_kind) AS kind_count,
                    MIN(it.expense_kind) AS expense_kind
               FROM purchase_invoices pi
               LEFT JOIN clients c ON c.id = pi.vendor_id
               LEFT JOIN purchase_invoice_items it
                      ON it.purchase_invoice_id = pi.id AND it.expense_kind IS NOT NULL
              WHERE pi.supplier_id = ? AND pi.id = ?
              GROUP BY pi.id, pi.vendor_id, c.company_name'
        );
        $stmt->execute([$supplierId, $docId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false
            || $row['vendor_id'] === null
            || (int) $row['kind_count'] !== 1
            || $row['expense_kind'] === null
        ) {
            return null;
        }

        return [
            'vendor_client_id' => (int) $row['vendor_id'],
            'vendor_name'      => (string) ($row['company_name'] ?? ''),
            'expense_kind'     => (string) $row['expense_kind'],
        ];
    }

    /**
     * Doplní k řádkům názvy účtů, ať uživatel nepotvrzuje holá čísla.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function describeLines(int $supplierId, array $lines): array
    {
        return $this->proposal->describe($supplierId, $lines);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function linesBalanced(array $lines): bool
    {
        return InvoicePostingProposal::balanced($lines);
    }

    /** Id už existujícího zápisu dokladu, nebo null. Náhled tím řekne, že je zaúčtováno. */
    private function existingEntryId(int $supplierId, string $sourceType, int $docId): ?int
    {
        $group = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $docId);
        if ($group !== []) return (int) $group[0]['id'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
    /**
     * Tvar řádků zaúčtování z požadavku, nebo null když některý řádek nesedí.
     *
     * Záměrně se NEDOPLŇUJÍ chybějící hodnoty: řádek bez účtu nebo s nulovou částkou
     * je chyba v zadání, ne něco, co se má domyslet. Doplněný dohad by se navíc zapsal
     * pod uživatelovým jménem, aniž by ho kdy viděl.
     *
     * @param list<mixed> $raw
     * @return list<array{account_code:string, side:string, amount:float, is_red_storno:bool}>|null
     */
    private function parsePostingLines(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $line) {
            if (!is_array($line)) {
                return null;
            }
            $code = trim((string) ($line['account_code'] ?? ''));
            $side = (string) ($line['side'] ?? '');
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($code === '' || !in_array($side, ['debit', 'credit'], true) || $amount <= 0) {
                return null;
            }
            if (array_key_exists('is_red_storno', $line) && !is_bool($line['is_red_storno'])) {
                return null;
            }
            $out[] = ['account_code' => $code, 'side' => $side, 'amount' => $amount,
                'is_red_storno' => (bool) ($line['is_red_storno'] ?? false)];
        }

        return $out === [] ? null : $out;
    }

    /**
     * Společná cesta pro zaúčtování dokladu (vydaná / přijatá faktura) přes
     * PostingService buildFrom* → postDocument. Idempotentní (source_type+source_id).
     *
     * @param 'invoices'|'purchase_invoices' $table
     * @param 'invoice'|'purchase_invoice'   $sourceType
     */
    private function postDocumentFrom(Request $request, Response $response, array $args, string $table, string $sourceType): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $docId = (int) ($args['id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné ID dokladu.', 422);
        }

        $docDate = $this->fetchDocDate($table, $supplierId, $docId);
        if ($docDate === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $entryDate = $this->nullableString($body['entry_date'] ?? null);
        if ($entryDate !== null && !$this->isDate($entryDate)) {
            return Json::error($response, 'validation_failed', 'entry_date musí být datum (YYYY-MM-DD).', 422);
        }

        // Vlastní řádky z popupu. Návrh systému je návrh, ne verdikt: kurzový rozdíl,
        // rozúčtování na střediska nebo jiný nákladový účet umí posoudit jen účetní.
        // Vyváženost a existenci účtů kontroluje PostingService — tady se jen ověří tvar,
        // aby se do služby nedostal řádek bez účtu nebo se zápornou částkou.
        $customLines = null;
        if (isset($body['lines']) && is_array($body['lines']) && $body['lines'] !== []) {
            $customLines = $this->parsePostingLines($body['lines']);
            if ($customLines === null) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Každý řádek potřebuje account_code, side (debit/credit) a kladnou částku.',
                    422,
                );
            }
        }

        try {
            $lines = $customLines ?? $this->proposal->lines($supplierId, $sourceType, $docId, $entryDate ?? $docDate);

            $effectiveEntryDate = $entryDate ?? $docDate;
            $meta = array_merge($this->auditMeta($request), [
                'entry_date'  => $effectiveEntryDate,
                'document_no' => $this->nullableString($body['document_no'] ?? null),
                'description' => $this->nullableString($body['description'] ?? null),
            ]);
            if ($customLines === null) {
                // Zaúčtování podle návrhu jen zajišťuje zápis. Vlastní řádky z popupu
                // jsou oprava a převzatou skupinu idempotentně přeskočit nesmí.
                $meta['posting_group_idempotent'] = true;
            }
            $entryId = $this->posting->postDocument($supplierId, $sourceType, $docId, $lines, $meta);
            $dimensionWarnings = $this->posting->dimensionWarnings();
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        // Epic F6 (§4.7): po úspěšném postu označ doklad jako zaúčtovaný — zámek pro
        // roli client. Existující booked_at/booked_by se nepřepisuje (ruční book dřív).
        // Workflow status PF se nemění — zůstává věcí účetní.
        $this->db->pdo()->prepare(
            "UPDATE {$table}
                SET booked_at = COALESCE(booked_at, NOW()),
                    booked_by = COALESCE(booked_by, ?)
              WHERE id = ? AND supplier_id = ?"
        )->execute([$this->userId($request), $docId, $supplierId]);
        if ($sourceType === 'purchase_invoice') {
            $this->embeddingWriter->enqueueFromDecision($supplierId, 'purchase_invoice', $docId);
        }

        $entry = $this->journal->find($entryId, $supplierId);
        if ($entryDate !== null && substr($effectiveEntryDate, 0, 4) !== substr($docDate, 0, 4)) {
            $entry['_warnings'] = ['entry_date_outside_document_year'];
        }
        if ($dimensionWarnings !== []) {
            $entry['dimension_warnings'] = $dimensionWarnings;
        }
        return Json::ok($response, $entry);
    }

    /**
     * PATCH /api/accounting/journal/{id}/description — §35 inline editace narativního
     * popisu na EXISTUJÍCÍM (i zaúčtovaném) zápisu. MIMO PostingService (nemění řádky/
     * období). Guardy + row_version bump + ATOMICKÝ before/after audit (uvnitř transakce
     * repozitáře, před commitem) řeší {@see JournalEntryRepository::updateDescription};
     * tady čteme 404 předem a předáváme audit kontext (§35 uspokojen auditem, ne zákazem
     * editace). requireWrite = účetní|admin.
     */
    public function updateDescription(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        if ($this->journal->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('description', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí pole description.', 422);
        }
        $text = $this->nullableString($body['description'] ?? null);
        if ($text !== null && mb_strlen($text) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        // Optimistická konkurence (Issue #15, část B): očekávanou verzi ber přednostně
        // z hlavičky If-Match, fallback na body row_version; nic z toho = bez CAS (kompat.).
        // Přítomný, ale nečitelný If-Match ale selže NAOSTRO (400) — CAS nesmí tiše vypadnout.
        $ifMatch = trim($request->getHeaderLine('If-Match'));
        $expectedVersion = null;
        if ($ifMatch !== '' && $ifMatch !== '*') {
            $parsed = $this->parseIfMatch($ifMatch);
            if ($parsed === null) {
                return Json::error($response, 'invalid_if_match',
                    'Hlavička If-Match není platný validátor (očekává se ETag s row_version, např. "5").', 400);
            }
            $expectedVersion = $parsed;
        } elseif (isset($body['row_version']) && is_numeric($body['row_version'])) {
            $expectedVersion = (int) $body['row_version'];
        }

        // §35 audit se zapisuje ATOMICKY uvnitř updateDescription (táž transakce, před
        // commitem) — proto sem předáváme audit kontext místo logování po commitu.
        $meta = $this->auditMeta($request);
        try {
            $this->journal->updateDescription(
                $id,
                $supplierId,
                $text,
                $this->userId($request),
                $meta['ip'],
                $meta['user_agent'],
                $expectedVersion,
            );
        } catch (\Throwable $e) {
            $resp = $this->mapPostingError($response, $e);
            // 409 version_conflict → přilož aktuální ETag, ať se klient může sesynchronizovat.
            if ($e instanceof PostingException && $e->errorCode === 'version_conflict') {
                $current = $this->journal->find($id, $supplierId);
                if ($current !== null) {
                    $resp = $resp->withHeader('ETag', '"' . $current['row_version'] . '"');
                }
            }
            return $resp;
        }

        $detail = $this->journal->find($id, $supplierId);
        return Json::ok($response, $detail)->withHeader('ETag', '"' . $detail['row_version'] . '"');
    }

    /**
     * Naparsuje jednu row_version z hodnoty If-Match validátoru (podporuje slabý prefix
     * `W/` i uvozovky). Vrací NULL, pokud hodnota není jednoznačný číselný ETag (garbage
     * i vícehodnotový seznam „1", "2") — volající to bere jako fail-closed (400 invalid_if_match),
     * NE jako „bez CAS". `*` řeší volající zvlášť (bez CAS). Server posílá vždy jen jeden
     * číselný ETag, takže korektní klient sem pošle přesně jednu hodnotu.
     */
    private function parseIfMatch(string $ifMatch): ?int
    {
        $v = (string) preg_replace('/^W\//i', '', $ifMatch);
        $v = trim($v, '"');
        return ctype_digit($v) ? (int) $v : null;
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        // Zdroj zápisu si přečti PŘED reversem — kvůli odemknutí dokladu níže (§4.7).
        $original = $this->journal->find($id, $supplierId);
        if (($original['source_type'] ?? null) === 'other_item') {
            return Json::error($response, 'other_item_use_detail',
                'Účetní zápis ostatní položky stornujte v jejím detailu, aby se aktualizoval i stav položky.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $entryDate = $this->nullableString($body['entry_date'] ?? null);
        if ($entryDate !== null && !$this->isDate($entryDate)) {
            return Json::error($response, 'validation_failed', 'entry_date musí být datum (YYYY-MM-DD).', 422);
        }

        $meta = array_merge($this->auditMeta($request), array_filter([
            'entry_date'  => $entryDate,
            'description' => $this->nullableString($body['description'] ?? null),
        ], static fn ($v) => $v !== null));

        try {
            $reversalId = TransactionFailure::retrying($this->db->pdo(), fn (): int =>
                (new DimensionPeriodGuard($this->db))->transaction(function () use ($request, $supplierId, $id, $meta): int {
                    (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
                    // Zápis interního dokladu se stornuje v detailu dokladu (kontrola pod zámkem zápisu).
                    $this->internalDocuments->assertJournalReversible($supplierId, $id);
                    $owner = (new DocumentPostingGroup($this->db))->owner($supplierId, $id, true);
                    $reversalId = $this->posting->reverse($supplierId, $id, $meta);
                    // I poslední ruční člen převzatého předpisu odemyká svůj doklad.
                    if ($owner !== null) {
                        $this->unlockSourceAfterReverse($request, $supplierId, $owner['doc_type'],
                            $owner['doc_id'], $id, $reversalId);
                    }
                    return $reversalId;
                }),
            );
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, $this->journal->find($reversalId, $supplierId), 201);
    }

    /**
     * DELETE /api/accounting/journal/{id} — odstranění chybného ručního zápisu,
     * zaúčtování faktury, bankovního pohybu nebo posledního odpisu v otevřeném a
     * nezamčeném období bez vytvoření protizápisu. U zdrojových workflow se zápis
     * i odemknutí/requeue provedou atomicky.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        $attachmentRows = [];
        try {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertEntriesFree($supplierId, [$id]);
            $stmt = $pdo->prepare(
                'SELECT je.id, je.period_id, je.entry_date, je.document_no, je.source_type,
                        je.source_id, je.posted_at, je.reversed_by, p.status AS period_status,
                        EXISTS(SELECT 1 FROM journal_entries r
                                WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id) AS is_reversal
                   FROM journal_entries je
                   JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$id, $supplierId]);
            $entry = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($entry === false) {
                if ($ownTx) $pdo->rollBack();
                return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
            }
            $lock = $pdo->prepare(
                'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE'
            );
            $lock->execute([$supplierId]);
            $lockedUntil = $lock->fetchColumn();
            $lockedUntil = ($lockedUntil === false || $lockedUntil === null) ? null : (string) $lockedUntil;
            $lockedAck = $this->lockedAcknowledged($request);
            if ($block = JournalEntryDeletionRules::blockSingle($entry, $lockedUntil, $lockedAck)) {
                if ($ownTx) $pdo->rollBack();
                return self::blockedResponse($response, $block);
            }
            $lockedOverride = JournalEntryDeletionRules::isDateLocked($entry, $lockedUntil);

            $sourceType = (string) $entry['source_type'];
            $sourceId = $entry['source_id'] === null ? null : (int) $entry['source_id'];
            if (!in_array($sourceType, JournalEntryDeletionRules::SINGLE_SOURCE_TYPES, true)
                || ($sourceType !== 'manual' && $sourceId === null)
            ) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'entry_delete_not_supported',
                    'Tento typ zápisu se ruší přes své zdrojové workflow nebo stornem.',
                    409,
                );
            }

            $depreciationRows = [];
            $assetId = null;
            $fiscalYear = null;
            $pausePreserved = false;
            if ($sourceType === 'depreciation') {
                $depreciation = $pdo->prepare(
                    "SELECT de.id, de.asset_id, de.fiscal_year, de.kind, de.amount, de.full_amount,
                            de.residual_value_end, de.is_paused, de.is_half, de.months_count,
                            de.detail, de.status, a.status AS asset_status
                       FROM depreciation_entries de
                       JOIN assets a ON a.id = de.asset_id AND a.supplier_id = de.supplier_id
                      WHERE de.id = ? AND de.supplier_id = ? AND de.kind = 'accounting'
                      FOR UPDATE"
                );
                $depreciation->execute([$sourceId, $supplierId]);
                $accountingDepreciation = $depreciation->fetch(\PDO::FETCH_ASSOC);
                if ($accountingDepreciation === false) {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error(
                        $response,
                        'depreciation_entry_not_found',
                        'Účetní řádek odpisu k tomuto zápisu nebyl nalezen.',
                        409,
                    );
                }

                $assetId = (int) $accountingDepreciation['asset_id'];
                $fiscalYear = (int) $accountingDepreciation['fiscal_year'];
                if ((string) $accountingDepreciation['asset_status'] === 'disposed') {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error(
                        $response,
                        'asset_disposed',
                        'Odpis vyřazeného majetku nelze smazat — nejdřív vraťte vyřazení.',
                        409,
                    );
                }

                $yearRows = $pdo->prepare(
                    'SELECT id, kind, fiscal_year, amount, full_amount, residual_value_end,
                            is_paused, is_half, months_count, detail, status
                       FROM depreciation_entries
                      WHERE supplier_id = ? AND asset_id = ?
                      ORDER BY fiscal_year, kind
                      FOR UPDATE'
                );
                $yearRows->execute([$supplierId, $assetId]);
                $allDepreciationRows = $yearRows->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $lastYear = max(array_map(static fn (array $row): int => (int) $row['fiscal_year'], $allDepreciationRows));
                if ($lastYear > $fiscalYear) {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error(
                        $response,
                        'later_year_confirmed',
                        'Existuje potvrzený odpis pozdějšího roku (' . $lastYear . ') — mažte roky odzadu.',
                        409,
                    );
                }
                $depreciationRows = array_values(array_filter(
                    $allDepreciationRows,
                    static fn (array $row): bool => (int) $row['fiscal_year'] === $fiscalYear,
                ));
                $pausePreserved = count(array_filter(
                    $depreciationRows,
                    static fn (array $row): bool => (string) $row['kind'] === 'tax' && (bool) $row['is_paused'],
                )) > 0;

            }

            $attachmentRows = $this->attachments->list($id, $supplierId);
            $deletedLines = $this->journal->linesForEntry($id, $supplierId);

            $statusFrom = null;
            $statusTo = null;
            // Smazaný zápis zaúčtování vrací interní doklad do konceptu (stejná transakce).
            if ($sourceType === 'manual' && $sourceId === null) {
                try {
                    if ($this->internalDocuments->forEntry($supplierId, $id) !== null
                        && !$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) {
                        if ($ownTx) $pdo->rollBack();
                        return $err;
                    }
                    if ($this->internalDocuments->prepareEntryDeletion($supplierId, $id, $this->userId($request)) !== null) {
                        $statusFrom = 'posted';
                        $statusTo = 'draft';
                    }
                } catch (InternalDocumentException $e) {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
                }
            }

            if ($sourceType === 'other_item') {
                if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)
                    || !$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) {
                    if ($ownTx) $pdo->rollBack();
                    return $err;
                }
                try {
                    $this->otherItems->prepareEntryDeletion($supplierId, (int) $sourceId, $id, $this->userId($request));
                } catch (OtherItemException $e) {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
                }
                $statusFrom = 'posted';
                $statusTo = 'draft';
            }

            if ($sourceType === 'bank') {
                $this->bankPosting->prepareEntryDeletion(
                    $supplierId,
                    (int) $sourceId,
                    $id,
                    $this->auditMeta($request) + ['reason' => 'delete'],
                );
            }

            (new \MyInvoice\Repository\AccountingSetupRepository($this->db))->detachReclassificationItemsFromEntries($supplierId, [$id]);

            $postingOwner = (new DocumentPostingGroup($this->db))->owner($supplierId, $id, true);
            $postingType = $postingOwner['doc_type'] ?? null;
            $postingId = $postingOwner['doc_id'] ?? null;
            $deleted = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?');
            $deleted->execute([$id, $supplierId]);
            if ($deleted->rowCount() !== 1) {
                throw new \RuntimeException('Účetní zápis se nepodařilo smazat.');
            }

            if ($sourceType === 'depreciation') {
                $deleteDepreciation = $pdo->prepare(
                    "DELETE FROM depreciation_entries
                      WHERE supplier_id = ? AND asset_id = ? AND fiscal_year = ?
                        AND (kind = 'accounting' OR (kind = 'tax' AND is_paused = 0))"
                );
                $deleteDepreciation->execute([$supplierId, $assetId, $fiscalYear]);
                if ($deleteDepreciation->rowCount() < 1) {
                    throw new \RuntimeException('Řádky odpisu se nepodařilo smazat.');
                }
            } elseif ($postingType === 'purchase_invoice' && ($postingId !== null
                && (new DocumentPostingGroup($this->db))->active($supplierId, $postingType, $postingId, true) === [])) {
                $doc = $pdo->prepare(
                    'SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
                );
                $doc->execute([$postingId, $supplierId]);
                $status = $doc->fetchColumn();
                if ($status !== false) {
                    $statusFrom = (string) $status;
                    $statusTo = $statusFrom === 'booked' ? 'received' : $statusFrom;
                    $pdo->prepare(
                        "UPDATE purchase_invoices
                            SET booked_at = NULL, booked_by = NULL,
                                status = CASE WHEN status = 'booked' THEN 'received' ELSE status END
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([$postingId, $supplierId]);
                }
            } elseif ($postingType === 'invoice' && ($postingId !== null
                && (new DocumentPostingGroup($this->db))->active($supplierId, $postingType, $postingId, true) === [])) {
                $pdo->prepare(
                    'UPDATE invoices SET booked_at = NULL, booked_by = NULL
                      WHERE id = ? AND supplier_id = ?'
                )->execute([$postingId, $supplierId]);
            }

            $this->logger->log(
                'accounting.entry_deleted',
                $this->userId($request),
                'journal_entry',
                $id,
                [
                    'period_id' => (int) $entry['period_id'],
                    'entry_date' => (string) $entry['entry_date'],
                    'document_no' => $entry['document_no'],
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_status_from' => $statusFrom,
                    'document_status_to' => $statusTo,
                    'asset_id' => $assetId,
                    'fiscal_year' => $fiscalYear,
                    'pause_preserved' => $pausePreserved,
                    'locked_override' => $lockedOverride ? $lockedUntil : null,
                    'depreciation_entries' => array_map(static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'kind' => (string) $row['kind'],
                        'fiscal_year' => (int) $row['fiscal_year'],
                        'amount' => (float) $row['amount'],
                        'full_amount' => (float) $row['full_amount'],
                        'residual_value_end' => (float) $row['residual_value_end'],
                        'is_paused' => (bool) $row['is_paused'],
                        'status' => (string) $row['status'],
                    ], $depreciationRows),
                    'lines' => array_map(static fn (array $line): array => [
                        'account_id' => (int) $line['account_id'],
                        'side' => (string) $line['side'],
                        'amount' => (float) $line['amount'],
                        'is_red_storno' => (bool) $line['is_red_storno'],
                        'currency_code' => $line['currency_code'],
                        'amount_foreign' => $line['amount_foreign'],
                        'cost_center' => $line['cost_center'],
                    ], $deletedLines),
                ],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'entry_delete_conflict',
                'Zápis se nepodařilo smazat, protože na něj navazuje jiný záznam v účetnictví '
                    . '(například mzdová platba, zápočet nebo vyúčtování). Nic se nesmazalo.',
                409,
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Odmítnutí kontrolou vazeb je věcná odpověď, ne chyba serveru.
            if ($e instanceof PostingException) {
                return $this->mapPostingError($response, $e);
            }
            throw $e;
        }

        if ($ownTx) {
            foreach ($attachmentRows as $attachment) {
                $this->attachmentStorage->deleteIfOrphan(
                    $supplierId,
                    (string) $attachment['sha256'],
                    (string) $attachment['filename'],
                    $this->attachments,
                );
            }
        }

        return Json::ok($response, array_filter([
            'ok' => true,
            'asset_id' => $assetId,
            'fiscal_year' => $fiscalYear,
            'pause_preserved' => $sourceType === 'depreciation' ? $pausePreserved : null,
        ], static fn ($value): bool => $value !== null));
    }

    /** Účetní vědomě potvrdil zásah do uzamčeného období ({@see JournalEntryDeletionRules::blockPeriod()}). */
    private function lockedAcknowledged(Request $request): bool
    {
        return ($request->getQueryParams()[JournalEntryDeletionRules::ACK_LOCKED_PARAM] ?? '') === '1';
    }

    /** @param array{code:string, message:string, can_acknowledge?:bool} $block */
    private static function blockedResponse(Response $response, array $block): Response
    {
        return Json::error(
            $response,
            $block['code'],
            $block['message'],
            409,
            !empty($block['can_acknowledge']) ? ['can_acknowledge' => true] : [],
        );
    }

    /**
     * DELETE /api/accounting/journal/{id}/reversal-pair — smaže CELOU storno dvojici
     * (původní zápis i jeho protizápis) v otevřeném a nezamčeném období.
     *
     * Storno je správná cesta, jak zrušit účinek zápisu, ale v deníku po něm navždy
     * zůstane dvojice, která se vzájemně ruší. Když šlo o zápis, který v účetnictví
     * nikdy neměl vzniknout (duplicitní bankovní pohyb po přepojení konektoru,
     * omylem zaúčtovaný doklad), je ta dvojice jen šum — v období, které se ještě
     * nikam nevykázalo, ji jde odstranit beze stopy v číslech: obě strany se ruší,
     * takže žádný zůstatek ani výkaz se smazáním nezmění.
     *
     * Záměrně to NENÍ součást {@see delete()}: tam se maže jeden zápis a stornovaného
     * se to dotknout nesmí. Tady se vědomě mažou dva a chce to vlastní potvrzení
     * i vlastní stopu v auditu.
     *
     * Brány (stejné jako u mazání jednoho zápisu, plus dvě navíc):
     *   - obě strany v období se stavem `open` a mimo uzamčenou část účetnictví,
     *   - protizápis sám nesmí být stornovaný a původní zápis nesmí být protizápisem
     *     něčeho dalšího — řetěz stornování se rozplétá odzadu, ne zprostředka,
     *   - zdroj z allowlistu ({@see delete()}); u zdrojů s vlastním workflow
     *     (mzdy, reklasifikace) se ruší přes ně.
     */
    public function deleteReversalPair(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        $attachmentRows = [];
        try {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$id]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$id]);
            $load = $pdo->prepare(
                'SELECT je.id, je.period_id, je.entry_date, je.document_no, je.description,
                        je.source_type, je.source_id, je.reversed_by, p.status AS period_status,
                        (SELECT r.id FROM journal_entries r
                          WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id LIMIT 1) AS reverses_entry_id
                   FROM journal_entries je
                   JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $load->execute([$id, $supplierId]);
            $selected = $load->fetch(\PDO::FETCH_ASSOC);
            if ($selected === false) {
                if ($ownTx) $pdo->rollBack();
                return Json::error($response, 'not_found', 'Účetní zápis nenalezen.', 404);
            }

            // Dvojici lze otevřít z kterékoli strany — z původního zápisu i z jeho storna.
            $originalId = $selected['reversed_by'] !== null
                ? (int) $selected['id']
                : ($selected['reverses_entry_id'] !== null ? (int) $selected['reverses_entry_id'] : 0);
            if ($originalId === 0) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'entry_not_reversed',
                    'Tenhle zápis žádné storno nemá — mazání dvojice se týká jen stornovaného zápisu a jeho protizápisu.',
                    409,
                );
            }
            if ($originalId !== (int) $selected['id']) {
                $load->execute([$originalId, $supplierId]);
                $original = $load->fetch(\PDO::FETCH_ASSOC);
                if ($original === false) {
                    if ($ownTx) $pdo->rollBack();
                    return Json::error($response, 'not_found', 'Stornovaný zápis nenalezen.', 404);
                }
            } else {
                $original = $selected;
            }
            $reversalId = (int) $original['reversed_by'];
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$originalId, $reversalId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$originalId, $reversalId]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$originalId, $reversalId]);
            (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertEntriesFree($supplierId, [$originalId, $reversalId]);
            $load->execute([$reversalId, $supplierId]);
            $reversal = $load->fetch(\PDO::FETCH_ASSOC);
            if ($reversal === false) {
                if ($ownTx) $pdo->rollBack();
                return Json::error($response, 'not_found', 'Protizápis nenalezen.', 404);
            }

            // Řetěz stornování rozplétej odzadu: storno storna je samostatná dvojice.
            if ($reversal['reversed_by'] !== null || $original['reverses_entry_id'] !== null) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'reversal_chain',
                    'Na tuhle dvojici navazuje další storno — začněte od posledního.',
                    409,
                );
            }

            $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $lockedUntil = $lock->fetchColumn();
            $lockedUntil = ($lockedUntil === false || $lockedUntil === null) ? null : (string) $lockedUntil;
            $lockedAck = $this->lockedAcknowledged($request);
            $lockedOverride = false;
            foreach ([$original, $reversal] as $row) {
                if ($block = JournalEntryDeletionRules::blockPeriod($row, $lockedUntil, $lockedAck)) {
                    if ($ownTx) $pdo->rollBack();
                    return self::blockedResponse($response, $block);
                }
                $lockedOverride = $lockedOverride || JournalEntryDeletionRules::isDateLocked($row, $lockedUntil);
            }

            $sourceType = (string) $original['source_type'];
            if (!in_array($sourceType, JournalEntryDeletionRules::PAIR_SOURCE_TYPES, true)) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'entry_delete_not_supported',
                    'Tento typ zápisu se ruší přes své zdrojové workflow.',
                    409,
                );
            }

            // Stornovaný interní doklad drží obě strany dvojice; mazáním by ukazoval do prázdna.
            if ($this->internalDocuments->forEntry($supplierId, (int) $original['id']) !== null) {
                if ($ownTx) $pdo->rollBack();
                return Json::error($response, 'internal_document_use_detail',
                    'Dvojice patří ke stornovanému internímu dokladu a zůstává v deníku jako jeho stopa.', 409);
            }

            $postingOwner = (new DocumentPostingGroup($this->db))->owner($supplierId, (int) $original['id'], true);
            $postingType = $postingOwner['doc_type'] ?? null;
            $postingId = $postingOwner['doc_id'] ?? null;
            $sourceId = $original['source_id'] === null ? null : (int) $original['source_id'];
            $deletedLines = [];
            foreach ([$original, $reversal] as $row) {
                $attachmentRows = array_merge($attachmentRows, $this->attachments->list((int) $row['id'], $supplierId));
                $deletedLines[(int) $row['id']] = $this->journal->linesForEntry((int) $row['id'], $supplierId);
            }

            // Doklad po stornu často nese NÁHRADNÍ živý zápis (přeúčtování stornem).
            // Dvojice se pak smaže, ale doklad zůstává zaúčtovaný: nevrací se do fronty
            // ani se neodemyká.
            $replacementId = null;
            if ($postingOwner !== null) {
                $members = (new DocumentPostingGroup($this->db))->active($supplierId, $postingType, $postingId, true);
                $replacementId = $members === [] ? null : (int) $members[0]['id'];
            } elseif ($sourceId !== null) {
                $replacement = $pdo->prepare(
                    'SELECT id FROM journal_entries
                      WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                        AND reversed_by IS NULL AND id NOT IN (?, ?)
                      LIMIT 1'
                );
                $replacement->execute([$supplierId, $sourceType, $sourceId, (int) $original['id'], $reversalId]);
                $found = $replacement->fetchColumn();
                $replacementId = $found === false ? null : (int) $found;
            }

            // Bankovní pohyb se vrací do fronty k zaúčtování. Zápis bez zdroje
            // (pohyb už smazaný) nemá co vracet — proto podmínka na source_id.
            if ($sourceType === 'bank' && $sourceId !== null && $replacementId === null) {
                $this->bankPosting->prepareEntryDeletion(
                    $supplierId,
                    $sourceId,
                    (int) $original['id'],
                    $this->auditMeta($request) + ['reason' => 'delete_reversal_pair'],
                );
            }

            (new \MyInvoice\Repository\AccountingSetupRepository($this->db))->detachReclassificationItemsFromEntries($supplierId, [(int) $original['id'], $reversalId]);

            // Původní zápis PRVNÍ. `journal_entries.reversed_by` je cizí klíč se SET NULL:
            // smazání protizápisu napřed by původní zápis „oživilo" (reversed_by NULL →
            // active_source_id) a vedle náhradního zápisu téhož dokladu by to narazilo
            // na unikát uq_je_supplier_active_source. Na původní zápis nic neodkazuje.
            foreach ([(int) $original['id'], $reversalId] as $deleteId) {
                $deleted = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?');
                $deleted->execute([$deleteId, $supplierId]);
                if ($deleted->rowCount() !== 1) {
                    throw new \RuntimeException('Účetní zápis se nepodařilo smazat.');
                }
            }

            $statusFrom = null;
            $statusTo = null;
            if ($replacementId === null && $postingId !== null && $postingType === 'purchase_invoice') {
                $doc = $pdo->prepare('SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ? FOR UPDATE');
                $doc->execute([$postingId, $supplierId]);
                $status = $doc->fetchColumn();
                if ($status !== false) {
                    $statusFrom = (string) $status;
                    $statusTo = $statusFrom === 'booked' ? 'received' : $statusFrom;
                    $pdo->prepare(
                        "UPDATE purchase_invoices
                            SET booked_at = NULL, booked_by = NULL,
                                status = CASE WHEN status = 'booked' THEN 'received' ELSE status END
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([$postingId, $supplierId]);
                }
            } elseif ($replacementId === null && $postingId !== null && $postingType === 'invoice') {
                $pdo->prepare('UPDATE invoices SET booked_at = NULL, booked_by = NULL WHERE id = ? AND supplier_id = ?')
                    ->execute([$postingId, $supplierId]);
            }

            $this->logger->log(
                'accounting.reversal_pair_deleted',
                $this->userId($request),
                'journal_entry',
                (int) $original['id'],
                [
                    'reversal_entry_id' => $reversalId,
                    'locked_override' => $lockedOverride ? $lockedUntil : null,
                    'period_id' => (int) $original['period_id'],
                    'entry_date' => (string) $original['entry_date'],
                    'document_no' => $original['document_no'],
                    'description' => $original['description'],
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_status_from' => $statusFrom,
                    'document_status_to' => $statusTo,
                    'replacement_entry_id' => $replacementId,
                    'entries' => array_map(static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'document_no' => $row['document_no'],
                        'entry_date' => (string) $row['entry_date'],
                    ], [$original, $reversal]),
                    'lines' => array_map(
                        static fn (array $lines): array => array_map(static fn (array $line): array => [
                            'account_id' => (int) $line['account_id'],
                            'side' => (string) $line['side'],
                            'amount' => (float) $line['amount'],
                            'is_red_storno' => (bool) $line['is_red_storno'],
                            'currency_code' => $line['currency_code'],
                            'amount_foreign' => $line['amount_foreign'],
                            'cost_center' => $line['cost_center'],
                        ], $lines),
                        $deletedLines,
                    ),
                ],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );

            if ($ownTx) $pdo->commit();
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'entry_delete_conflict',
                'Storno dvojici se nepodařilo smazat, protože na zápisy navazuje jiný záznam v účetnictví '
                    . '(například náhradní zápis dokladu nebo párování). Nic se nesmazalo.',
                409,
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PostingException) {
                return $this->mapPostingError($response, $e);
            }
            throw $e;
        }

        if ($ownTx) {
            foreach ($attachmentRows as $attachment) {
                $this->attachmentStorage->deleteIfOrphan(
                    $supplierId,
                    (string) $attachment['sha256'],
                    (string) $attachment['filename'],
                    $this->attachments,
                );
            }
        }

        return Json::ok($response, ['ok' => true, 'deleted_entry_ids' => [(int) $original['id'], $reversalId]]);
    }

    /**
     * Smaže booked_at/booked_by zdrojového dokladu po stornu zápisu, pokud už k němu
     * neexistuje jiný aktivní posted zápis (Epic F6, §4.7 + R9 audit).
     */
    private function unlockSourceAfterReverse(
        Request $request,
        int $supplierId,
        string $sourceType,
        int $sourceId,
        int $reversedEntryId,
        int $reversalId,
    ): void {
        $pdo = $this->db->pdo();

        if ((new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $sourceId, true) !== []) {
            return; // jiný aktivní zápis zámek drží dál
        }

        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $stmt = $pdo->prepare(
            "UPDATE {$table} SET booked_at = NULL, booked_by = NULL
              WHERE id = ? AND supplier_id = ? AND booked_at IS NOT NULL"
        );
        $stmt->execute([$sourceId, $supplierId]);

        if ($stmt->rowCount() > 0) {
            $this->logger->log(
                'document_lock.unlocked_by_reverse',
                $this->userId($request),
                $sourceType,
                $sourceId,
                ['entry_id' => $reversedEntryId, 'reversal_id' => $reversalId],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }
    }

    /** Datum účetního případu z dokladu (DUZP / vystavení). NULL = doklad neexistuje. */
    private function fetchDocDate(string $table, int $supplierId, int $id): ?string
    {
        return $this->proposal->documentDate($supplierId, $table === 'invoices' ? 'invoice' : 'purchase_invoice', $id);
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
