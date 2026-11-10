<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\StockStatusPdfRenderer;
use MyInvoice\Service\Pdf\StockValuationPdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockSalesReportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use MyInvoice\Service\Stock\StockValuationCategoryGroups;
use MyInvoice\Service\Stock\StockValueTrendService;
use MyInvoice\Service\Pdf\StockValueTrendPdfRenderer;
use MyInvoice\Service\Stock\StockValuationJobService;
use MyInvoice\Service\Eshop\CatalogJobService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Skladové sestavy (Epic SKLAD §6, §8.1) — Stav zásob · Ocenění k datu · Prodeje.
 *
 *   GET /api/stock/reports/status              — data (filtry warehouse_id, item_type, below_min, active, q,
 *                                                 category_id; volitelné sloupce columns, attribute_id)
 *   GET /api/stock/reports/valuation?date=      — data k historickému datu (B8 limit-guard),
 *                                                 group_by=category[&group_parent_id] přidá souhrn podle kategorií
 *   POST /api/stock/reports/value-trend-jobs     — výpočet vývoje hodnoty na pozadí (from, to, warehouse_id?, group_parent_id?);
 *   GET  .../value-trend-jobs/{id}[/status]      — výsledek po kategoriích ke koncům měsíců a ke dni `to`, stav a průběh;
 *   POST .../value-trend-jobs/{id}/cancel        — zrušení
 *   GET /api/stock/reports/sales                — prodeje karet z řádků FV a dobropisů (právo i na vydané faktury)
 *   GET /api/stock/reports/{name}/export        — PDF/XLSX (?format=pdf|xlsx), name = status|valuation; sales jen XLSX
 */
final class StockReportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const REPORTS = ['status', 'valuation', 'value-trend', 'sales'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockReportService $service,
        private readonly StockStatusPdfRenderer $statusPdf,
        private readonly StockValuationPdfRenderer $valuationPdf,
        private readonly StockReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StockValuationJobService $valuationJobs,
        private readonly CatalogJobService $jobs,
        private readonly StockSalesReportService $sales,
        private readonly StockValuationCategoryGroups $categoryGroups,
        private readonly StockValueTrendService $valueTrend,
        private readonly StockValueTrendPdfRenderer $valueTrendPdf,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->service->status($supplierId, $this->statusFilters($request)));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function valuation(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $date = trim((string) ($q['date'] ?? (new \DateTimeImmutable())->format('Y-m-d')));
        $filters = [];
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        try {
            $grouping = $this->categoryGrouping($request, $q);
            $data = $this->service->valuation($supplierId, $date, $filters);
            if ($grouping !== null) {
                $data['category_groups'] = $this->categoryGroups->summarize($supplierId, $grouping['parent_id'], $data['items']);
            }
            return Json::ok($response, $data);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function createValueTrendJob(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', \MyInvoice\Security\AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $dates = StockValueTrendService::dates(trim((string) ($body['from'] ?? '')), trim((string) ($body['to'] ?? (new \DateTimeImmutable())->format('Y-m-d'))));
            $warehouseId = $body['warehouse_id'] ?? null;
            if ($warehouseId !== null && (!is_int($warehouseId) || $warehouseId < 1)) {
                return Json::error($response, 'validation_failed', 'Neplatný sklad.', 422);
            }
            $grouping = StockValuationCategoryGroups::normalize(array_merge($body, ['group_by' => 'category']));
            $job = $this->valueTrend->enqueue($supplierId, $dates, $warehouseId, $grouping['parent_id'],
                RequestAuthorization::allows($request, 'eshop', AccessLevel::READ), $this->userId($request));
            return Json::ok($response, $this->trendJobView($job), 202);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function valueTrendJobResult(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->valueTrendResult($request, $supplierId, (int) $args['id']));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    /** @return array<string,mixed> */
    private function valueTrendResult(Request $request, int $supplierId, int $jobId): array
    {
        return $this->valueTrend->result($supplierId, $jobId, RequestAuthorization::allows($request, 'eshop', AccessLevel::READ));
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function trendJobView(array $job): array
    {
        unset($job['input'], $job['lease_token']);
        // Součty po kategoriích jen přes výsledek s kontrolou práv; z reportu zbývá počet restartů pro UI.
        $job['report'] = ['restarts' => (int) ($job['report']['restarts'] ?? 0)];
        return $job;
    }

    public function valueTrendJobStatus(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $job = $this->jobs->find($supplierId, (int) $args['id']);
        if ($job === null || $job['kind'] !== StockValueTrendService::KIND) {
            return Json::error($response, 'not_found', 'Výpočet nenalezen.', 404);
        }
        return Json::ok($response, $this->trendJobView($job));
    }

    /** Zrušit smí zakladatel úlohy (stačí čtení skladu, výpočet nic nemění), jinak uživatel s právem zápisu do skladu. */
    public function cancelValueTrendJob(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', \MyInvoice\Security\AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $job = $this->jobs->find($supplierId, $id);
        if ($job === null || $job['kind'] !== StockValueTrendService::KIND) {
            return Json::error($response, 'not_found', 'Výpočet nenalezen.', 404);
        }
        if (($job['created_by'] === null || (int) $job['created_by'] !== $this->userId($request))
            && !RequestAuthorization::allows($request, 'stock', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Výpočet založil někdo jiný, zrušit ho může jen správce skladu.', 403);
        }
        if (!$this->jobs->cancel($supplierId, $id)) {
            return Json::error($response, 'job_state_conflict', 'Úlohu již nelze zrušit.', 409);
        }
        if ($this->jobs->find($supplierId, $id)['status'] === 'cancelled') {
            $this->valueTrend->dropQueue($supplierId, $id); // běžící úloha se uklidí v další dávce
        }
        return $this->valueTrendJobStatus($request, $response, $args);
    }

    /**
     * Prodeje skladových karet. Ukazuje odběratele a prodejní ceny, proto kromě
     * práva ke skladu (route map) vyžaduje i čtení vydaných faktur.
     */
    public function sales(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'invoices', \MyInvoice\Security\AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $page = filter_var($q['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($q['per_page'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($page === false || $perPage === false) {
            return Json::error($response, 'validation_failed', 'Neplatné stránkování.', 422);
        }
        try {
            $filters = StockSalesReportService::normalizeFilters($q);
            return Json::ok($response, $this->sales->report($supplierId, $filters, $page, $perPage));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function createValuationJob(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', \MyInvoice\Security\AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_string($body['date'] ?? null)
            || (isset($body['warehouse_id']) && (!is_int($body['warehouse_id']) || $body['warehouse_id'] < 1))) {
            return Json::error($response, 'validation_failed', 'Neplatné datum nebo sklad.', 422);
        }
        try {
            $filters = isset($body['warehouse_id']) ? ['warehouse_id' => $body['warehouse_id']] : [];
            $job = $this->valuationJobs->enqueue($supplierId, $body['date'], $filters);
            unset($job['input'], $job['lease_token']);
            return Json::ok($response, $job, 202);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function valuationJobResult(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $query = $request->getQueryParams();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($page === false || $limit === false) {
            return Json::error($response, 'validation_failed', 'Neplatné stránkování.', 422);
        }
        try {
            $grouping = $this->categoryGrouping($request, $query);
            $data = $this->valuationJobs->result($supplierId, (int) $args['id'], $page, $limit);
            if ($grouping !== null) {
                $data['category_groups'] = $this->valuationJobs->categoryGroups($supplierId, (int) $args['id'], $grouping['parent_id']);
            }
            return Json::ok($response, $data);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function valuationJobStatus(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $job = $this->jobs->find($supplierId, (int) $args['id']);
        if ($job === null || $job['kind'] !== 'stock_valuation' || !empty($job['input']['stock_take_id'])) {
            return Json::error($response, 'not_found', 'Ocenění nenalezeno.', 404);
        }
        unset($job['input'], $job['lease_token']);
        return Json::ok($response, $job);
    }

    public function cancelValuationJob(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', \MyInvoice\Security\AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $job = $this->jobs->find($supplierId, $id);
        if ($job === null || $job['kind'] !== 'stock_valuation' || !empty($job['input']['stock_take_id'])) {
            return Json::error($response, 'not_found', 'Ocenění nenalezeno.', 404);
        }
        if (!$this->jobs->cancel($supplierId, $id)) {
            return Json::error($response, 'job_state_conflict', 'Úlohu již nelze zrušit.', 409);
        }
        return $this->valuationJobStatus($request, $response, $args);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $name = (string) ($args['name'] ?? '');
        if (!in_array($name, self::REPORTS, true)) {
            return Json::error($response, 'not_found', 'Neznámá sestava.', 404);
        }
        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        if ($name === 'sales') {
            if (!$this->requirePermission($request, $response, 'invoices', \MyInvoice\Security\AccessLevel::READ, $err)) {
                return $err;
            }
            if ($format !== 'xlsx') {
                return Json::error($response, 'validation_failed', "Sestavu prodejů lze exportovat jen do XLSX.", 422);
            }
        }

        try {
            if ($name === 'sales') {
                $out = $this->xlsx->sales($this->sales->export($supplierId, StockSalesReportService::normalizeFilters($request->getQueryParams())));
            } elseif ($name === 'status') {
                $data = $this->service->status($supplierId, $this->statusFilters($request));
                $out = $format === 'pdf'
                    ? ['bytes' => $this->statusPdf->render($data), 'filename' => 'stav-zasob.pdf', 'mime' => 'application/pdf']
                    : $this->xlsx->status($data);
            } elseif ($name === 'value-trend') {
                $jobId = filter_var($request->getQueryParams()['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($jobId === false) {
                    return Json::error($response, 'validation_failed', 'Nejdřív spusťte výpočet a exportujte jeho výsledek (job_id).', 422);
                }
                $data = $this->valueTrendResult($request, $supplierId, $jobId);
                $out = $format === 'pdf'
                    ? ['bytes' => $this->valueTrendPdf->render($data), 'filename' => 'vyvoj-hodnoty-zasob-' . $data['dates'][0] . '-' . end($data['dates']) . '.pdf', 'mime' => 'application/pdf']
                    : $this->xlsx->valueTrend($data);
            } else {
                $q = $request->getQueryParams();
                $date = trim((string) ($q['date'] ?? (new \DateTimeImmutable())->format('Y-m-d')));
                $filters = !empty($q['warehouse_id']) ? ['warehouse_id' => (int) $q['warehouse_id']] : [];
                $grouping = $this->categoryGrouping($request, $q);
                if (isset($q['job_id'])) {
                    $jobId = filter_var($q['job_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($jobId === false) {
                        return Json::error($response, 'validation_failed', 'Neplatné ID ocenění.', 422);
                    }
                    $data = $this->valuationJobs->result($supplierId, $jobId, 1, 500);
                    $date = $data['date'];
                    $pages = (int) ceil($data['pagination']['total'] / 500);
                    for ($page = 2; $page <= $pages; $page++) {
                        $next = $this->valuationJobs->result($supplierId, $jobId, $page, 500);
                        array_push($data['items'], ...$next['items']);
                    }
                    if ($grouping !== null) {
                        $data['category_groups'] = $this->valuationJobs->categoryGroups($supplierId, $jobId, $grouping['parent_id']);
                    }
                } else {
                    $data = $this->service->valuation($supplierId, $date, $filters);
                    if ($grouping !== null) {
                        $data['category_groups'] = $this->categoryGroups->summarize($supplierId, $grouping['parent_id'], $data['items']);
                    }
                }
                $out = $format === 'pdf'
                    ? ['bytes' => $this->valuationPdf->render($data), 'filename' => 'oceneni-zasob-' . $date . '.pdf', 'mime' => 'application/pdf']
                    : $this->xlsx->valuation($data);
            }
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }

        $this->logger->log('report.stock_export', $this->userId($request), 'report', null,
            ['report' => $name, 'format' => $format],
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
     * Parametry i kategorie karet spravuje e-shop. Bez práva ho číst se sloupec parametru
     * i souhrn podle kategorií tiše vynechají, stejně jako sloupec parametru v seznamu karet
     * ({@see StockItemAction::list()}); neplatná hodnota ale vrací chybu i tak.
     *
     * @return array<string,mixed>
     */
    private function statusFilters(Request $request): array
    {
        $filters = StockReportService::normalizeStatusFilters($request->getQueryParams());
        if (!RequestAuthorization::allows($request, 'eshop', AccessLevel::READ)) {
            unset($filters['attribute_id']);
        }
        return $filters;
    }

    /**
     * @param array<string,mixed> $q
     * @return array{parent_id:?int}|null
     */
    private function categoryGrouping(Request $request, array $q): ?array
    {
        $grouping = StockValuationCategoryGroups::normalize($q);
        return RequestAuthorization::allows($request, 'eshop', AccessLevel::READ) ? $grouping : null;
    }

    private function mapStockError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof StockException) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        throw $e;
    }
}
