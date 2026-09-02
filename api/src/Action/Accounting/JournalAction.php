<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\AutomationProvenanceService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\JournalHistoryService;
use MyInvoice\Service\Accounting\JournalIntegrityService;
use MyInvoice\Service\Accounting\JournalLinkService;
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
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentSeriesService $series,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly AccountingPeriodRepository $periods,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly BankPostingService $bankPosting,
        private readonly AutomationProvenanceService $automationProvenance,
        private readonly JournalExportService $exportService,
        private readonly JournalPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly JournalHistoryService $history,
        private readonly JournalLinkService $links,
        private readonly JournalEntryDocumentLinkRepository $documentLinks,
        private readonly JournalEntryAttachmentRepository $attachments,
        private readonly JournalAttachmentStorage $attachmentStorage,
        private readonly JournalIntegrityService $integrity,
        private readonly LoggerInterface $log,
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

        $result = $this->journal->paginate($supplierId, $this->parseFilters($q, $supplierId), $perPage, $offset);
        $provenance = $this->automationProvenance->forJournalEntries(
            $supplierId,
            array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
        );
        // Odznak „má protějšek" (doklad ↔ úhrada) — batch přes celou stránku,
        // viz JournalLinkService::hasRelatedMap(). Bez něj by účetní musel rozbalit
        // každý řádek, aby zjistil, jestli je zápis na něco navázaný.
        $related = $this->links->hasRelatedMap($supplierId, $result['items']);
        foreach ($result['items'] as &$item) {
            $item['automation'] = $provenance[(int) $item['id']] ?? null;
            $item['has_related'] = isset($related[(int) $item['id']]);
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

        $filters = $this->parseFilters($request->getQueryParams(), $supplierId);
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
        if (!empty($q['account_from'])) $filters['account_from'] = mb_substr(trim((string) $q['account_from']), 0, 20);
        if (!empty($q['account_to']))   $filters['account_to'] = mb_substr(trim((string) $q['account_to']), 0, 20);
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
                $filters['account_from'] = $code;
                $filters['account_to'] = (bool) $account['is_synthetic'] ? $code . '999999' : $code;
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
        $entry['lines'] = array_map(static function (array $line) use ($accMap): array {
            $acc = $accMap[(int) $line['account_id']] ?? null;
            $line['account_code'] = $acc['code'] ?? null;
            $line['account_name'] = $acc['name'] ?? null;
            return $line;
        }, $entry['lines']);
        $entry['automation'] = $this->automationProvenance->forJournalEntries($supplierId, [$id])[$id] ?? null;
        // Měkké vazby na doklady (migrace 1514) — detail zápisu je jediné místo, kde
        // je uživatel spravuje, takže chodí rovnou s ním, ne dalším requestem.
        // VČETNĚ popisu dokladu: bez něj by se navázaná faktura v UI tvářila jako
        // smazaná, protože stejný seznam z /links popis nese.
        $entry['links'] = $this->links->documentLinks($supplierId, $id);

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
        $rawLines = $body['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            return Json::error($response, 'validation_failed', 'lines musí obsahovat alespoň jeden řádek.', 422);
        }

        $lines = [];
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) {
                return Json::error($response, 'validation_failed', "Řádek #{$i} má neplatný formát.", 422);
            }
            $side = (string) ($l['side'] ?? '');
            if ($side !== 'debit' && $side !== 'credit') {
                return Json::error($response, 'validation_failed', "Řádek #{$i}: side musí být 'debit' nebo 'credit'.", 422);
            }
            $line = [
                'account_code' => (string) ($l['account_code'] ?? ''),
                'side'         => $side,
                'amount'       => (float) ($l['amount'] ?? 0),
            ];
            if (isset($l['cost_center']) && trim((string) $l['cost_center']) !== '') {
                $line['cost_center'] = (string) $l['cost_center'];
            }
            $lines[] = $line;
        }

        $meta = array_merge($this->auditMeta($request), [
            'entry_date'  => $entryDate,
            'description' => $this->nullableString($body['description'] ?? null),
            'document_no' => $this->nullableString($body['document_no'] ?? null),
        ]);

        // Volitelné měkké vazby na existující doklady (migrace 1514). Validují se
        // PŘED zaúčtováním: neplatná vazba nesmí nechat v deníku zápis, o kterém si
        // uživatel myslí, že doklad nese. Zápis vazeb pak běží ve stejné transakci.
        $links = [];
        foreach ((array) ($body['links'] ?? []) as $i => $raw) {
            if (!is_array($raw)) {
                return Json::error($response, 'validation_failed', "Vazba #{$i} má neplatný formát.", 422);
            }
            $link = JournalDocumentLinkAction::validateLink($raw, $supplierId, $this->documentLinks, $code, $message);
            if ($link === null) {
                return Json::error($response, $code, $message, $code === 'not_found' ? 404 : 422);
            }
            $links[] = $link;
        }
        if (count($links) > JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY) {
            return Json::error(
                $response,
                'too_many_links',
                'Zápis může mít nejvýš ' . JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY . ' vazeb.',
                422
            );
        }

        // Opt-in číselná řada ID pro ruční zápisy (Epic F4, R13) — výdej čísla drží
        // FOR UPDATE zámek, proto transakce obepíná i postDocument (mezera po rollbacku
        // nevzniká). Vnější transakci (testy) respektuje stejně jako PostingService.
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($meta['document_no'] === null && !empty($this->settings->get($supplierId)['manual_doc_series'])) {
                $period = $this->periods->findForDate($supplierId, $entryDate);
                $fiscalYear = (int) ($period['fiscal_year'] ?? substr($entryDate, 0, 4));
                $meta['document_no'] = $this->series->next($supplierId, 'manual', $fiscalYear);
            }
            $entryId = $this->posting->postDocument($supplierId, 'manual', null, $lines, $meta);
            foreach ($links as $link) {
                $this->documentLinks->add(
                    $entryId,
                    $supplierId,
                    $link['doc_type'],
                    $link['doc_id'],
                    $link['note'],
                    $meta['user_id'] ?? null
                );
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->mapPostingError($response, $e);
        }

        $created = $this->journal->find($entryId, $supplierId);
        $created['links'] = $this->links->documentLinks($supplierId, $entryId);

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

        $meta = $this->auditMeta($request);
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
        // uživatel potvrzoval jiné řádky, než jaké se nakonec zapíšou.
        $aiOverride = $sourceType === 'purchase_invoice'
            ? $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId)
            : null;

        try {
            $lines = $sourceType === 'invoice'
                ? $this->posting->buildFromInvoice($supplierId, $docId)
                : $this->posting->buildFromPurchaseInvoice($supplierId, $docId, array_filter([
                    'debit_account_code' => $aiOverride,
                ], static fn (mixed $value): bool => $value !== null));
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
            'already_posted' => $this->existingEntryId($supplierId, $sourceType, $docId),
            'rule_basis'    => $sourceType === 'purchase_invoice'
                ? $this->ruleBasis($supplierId, $docId)
                : null,
        ]);
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
        $codes = array_values(array_unique(array_map(
            static fn (array $l): string => (string) $l['account_code'],
            $lines,
        )));
        if ($codes === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code, name FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code IN ({$ph})"
        );
        $stmt->execute(array_merge([$supplierId], $codes));
        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $names[(string) $r['account_code']] = (string) $r['name'];
        }

        return array_map(static function (array $l) use ($names): array {
            $code = (string) $l['account_code'];

            return [
                'account_code' => $code,
                'account_name' => $names[$code] ?? null,
                'side'         => (string) $l['side'],
                'amount'       => round((float) $l['amount'], 2),
                'cost_center'  => $l['cost_center'] ?? null,
            ];
        }, $lines);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function linesBalanced(array $lines): bool
    {
        $delta = 0;
        foreach ($lines as $l) {
            $cents = (int) round(((float) $l['amount']) * 100);
            $delta += (string) $l['side'] === 'debit' ? $cents : -$cents;
        }

        return $delta === 0;
    }

    /** Id už existujícího zápisu dokladu, nebo null. Náhled tím řekne, že je zaúčtováno. */
    private function existingEntryId(int $supplierId, string $sourceType, int $docId): ?int
    {
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
     * @return list<array{account_code:string, side:string, amount:float}>|null
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
            $out[] = ['account_code' => $code, 'side' => $side, 'amount' => $amount];
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
            $lines = $customLines ?? ($sourceType === 'invoice'
                ? $this->posting->buildFromInvoice($supplierId, $docId)
                : $this->posting->buildFromPurchaseInvoice($supplierId, $docId, array_filter([
                    'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId),
                ], static fn (mixed $value): bool => $value !== null)));

            $effectiveEntryDate = $entryDate ?? $docDate;
            $meta = array_merge($this->auditMeta($request), [
                'entry_date'  => $effectiveEntryDate,
                'document_no' => $this->nullableString($body['document_no'] ?? null),
                'description' => $this->nullableString($body['description'] ?? null),
            ]);
            $entryId = $this->posting->postDocument($supplierId, $sourceType, $docId, $lines, $meta);
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
            $reversalId = $this->posting->reverse($supplierId, $id, $meta);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        // Epic F6 (§4.7, R9): storno zápisu dokladu odemyká booked_at, pokud k source
        // po reversu neexistuje jiný aktivní posted zápis. Vědomé riziko L2 (klient může
        // odemčený doklad změnit před re-postem) kryje activity log + FE hint.
        if ($original !== null
            && in_array((string) ($original['source_type'] ?? ''), ['invoice', 'purchase_invoice'], true)
            && $original['source_id'] !== null
        ) {
            $this->unlockSourceAfterReverse(
                $request,
                $supplierId,
                (string) $original['source_type'],
                (int) $original['source_id'],
                $id,
                $reversalId,
            );
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
            if ((string) $entry['period_status'] !== 'open') {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'period_not_open',
                    'Zápis je v období „' . $entry['period_status'] . '“ — smazat lze jen zápis v otevřeném období.',
                    409,
                );
            }
            if ($entry['reversed_by'] !== null || (bool) $entry['is_reversal']) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'entry_has_reversal',
                    'Stornovaný zápis ani jeho protizápis nelze smazat samostatně.',
                    409,
                );
            }

            $lock = $pdo->prepare(
                'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE'
            );
            $lock->execute([$supplierId]);
            $lockedUntil = $lock->fetchColumn();
            if ($lockedUntil !== false && $lockedUntil !== null && (string) $entry['entry_date'] <= (string) $lockedUntil) {
                if ($ownTx) $pdo->rollBack();
                return Json::error(
                    $response,
                    'date_locked',
                    'Datum zápisu spadá do uzamčené části účetnictví.',
                    409,
                );
            }

            $sourceType = (string) $entry['source_type'];
            $sourceId = $entry['source_id'] === null ? null : (int) $entry['source_id'];
            $deletableSourceTypes = ['manual', 'invoice', 'purchase_invoice', 'bank', 'depreciation'];
            if (!in_array($sourceType, $deletableSourceTypes, true)
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

            if ($sourceType === 'bank') {
                $this->bankPosting->prepareEntryDeletion(
                    $supplierId,
                    (int) $sourceId,
                    $id,
                    $this->auditMeta($request) + ['reason' => 'delete'],
                );
            }

            $deleted = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?');
            $deleted->execute([$id, $supplierId]);
            if ($deleted->rowCount() !== 1) {
                throw new \RuntimeException('Účetní zápis se nepodařilo smazat.');
            }

            $statusFrom = null;
            $statusTo = null;
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
            } elseif ($sourceType === 'purchase_invoice') {
                $doc = $pdo->prepare(
                    'SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
                );
                $doc->execute([$sourceId, $supplierId]);
                $status = $doc->fetchColumn();
                if ($status !== false) {
                    $statusFrom = (string) $status;
                    $statusTo = $statusFrom === 'booked' ? 'received' : $statusFrom;
                    $pdo->prepare(
                        "UPDATE purchase_invoices
                            SET booked_at = NULL, booked_by = NULL,
                                status = CASE WHEN status = 'booked' THEN 'received' ELSE status END
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([$sourceId, $supplierId]);
                }
            } elseif ($sourceType === 'invoice') {
                $pdo->prepare(
                    'UPDATE invoices SET booked_at = NULL, booked_by = NULL
                      WHERE id = ? AND supplier_id = ?'
                )->execute([$sourceId, $supplierId]);
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
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
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

        $active = $pdo->prepare(
            'SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL
              LIMIT 1'
        );
        $active->execute([$supplierId, $sourceType, $sourceId]);
        if ($active->fetchColumn() !== false) {
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
        // Whitelisted table names (interní volání) — ne z uživatelského vstupu.
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(tax_date, issue_date) AS d FROM {$table} WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $d = $stmt->fetchColumn();
        return $d === false || $d === null ? null : (string) $d;
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
