<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Http\InvoiceListFilters;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AccountingBulkJobs;
use MyInvoice\Service\Accounting\Bulk\BulkActionException;
use MyInvoice\Service\Accounting\Bulk\BulkActionService;
use MyInvoice\Service\Accounting\Bulk\BulkOperations;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\Bulk\DeleteOperation;
use MyInvoice\Service\Accounting\Bulk\DocumentItemSearch;
use MyInvoice\Service\Accounting\Bulk\SetDimensionsOperation;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné akce v seznamech (Účetní deník, Vydané faktury, Přijaté faktury).
 *
 *   GET  /api/accounting/bulk-actions?target=          … dostupné operace + poslední běhy
 *   POST /api/accounting/bulk-actions/start            … běh nanečisto, nebo ostrý běh podle něj
 *   GET  /api/accounting/bulk-actions/{id}             … stav úlohy pro polling
 *   GET  /api/accounting/bulk-actions/{id}/items       … výsledek po záznamech (stránkovaně)
 *   POST /api/accounting/bulk-actions/{id}/rollback    … vrátí ostrý běh (ne u smazání)
 *   POST /api/accounting/bulk-actions/{id}/cancel
 *
 * Výběr je buď ruční (`ids` z aktuální firmy), nebo „všech N podle filtru"
 * (`list_query` = query parametry seznamu vyhodnocené kódem seznamu). Obojí se při
 * spuštění převede na pevný seznam id, nejvýš {@see BulkSelection::LIMIT}.
 *
 * Spuštění smí správce firmy nebo role s právem `accounting.bulk_changes`
 * ({@see RequestAuthorization::canBulkChange()}); přeúčtování účtu dál vyžaduje
 * právo hromadného přeúčtování ({@see RequestAuthorization::canBulkRepost()}). Každý
 * záznam pak projde právy a pojistkami úpravy jednoho záznamu za uživatele, který
 * úlohu spustil. Ostrý běh nebere výběr z požadavku: zpracuje jen to, co ukázal
 * dokončený běh nanečisto, bez vyřazených položek. Smazání vyžaduje opsaný počet.
 */
final class BulkActionAction
{
    private const HISTORY = 10;
    private const PARAMS = [
        'from_account', 'to_account', 'side', 'mode', 'dimensions', 'scope', 'account_prefixes', 'rule_key', 'journal_fallback',
        'vat_classification_code', 'vat_rate_id',
    ];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly AccountingBulkItemRepository $items,
        private readonly BulkOperations $operations,
        private readonly BulkSelection $selection,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly JournalAction $journal,
        private readonly DimensionService $dimensions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentItemSearch $itemSearch,
    ) {}

    /** GET /api/accounting/bulk-actions?target= */
    public function status(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $target = (string) ($request->getQueryParams()['target'] ?? '');
        if (!in_array($target, BulkSelection::TARGETS, true) && !DocumentItemSearch::isItemTarget($target)) {
            return Json::error($response, 'validation_failed', 'Neznámý druh záznamů hromadné akce.', 422);
        }
        if (($deny = $this->denyList($request, $response, $target)) !== null) {
            return $deny;
        }
        $operations = [];
        foreach (BulkOperations::keysFor($target) as $key) {
            $operation = $this->operations->get($key, $target);
            $reason = $operation->unavailableReason($request, $supplierId, $target);
            $operations[] = [
                'key' => $key, 'available' => $reason === null, 'reason' => $reason, 'rollback' => $operation->supportsRollback(),
            ];
        }
        $canRun = array_filter($operations, static fn (array $o): bool => $o['available']) !== [];
        $all = $this->jobs->listForTenant($supplierId, BulkActionService::SOURCE, limit: 50);
        $mine = array_values(array_filter($all, static fn (array $j): bool => (($j['params'] ?? [])['target'] ?? null) === $target));

        return Json::ok($response, [
            'can_run'    => $canRun,
            'limit'      => BulkSelection::LIMIT,
            'operations' => $operations,
            'jobs'       => array_map(fn (array $j): array => $this->view($request, $j, $all), array_slice($mine, 0, self::HISTORY)),
        ]);
    }

    /** POST /api/accounting/bulk-actions/start */
    public function start(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        if (!RequestAuthorization::canBulkChange($request) && !RequestAuthorization::canBulkRepost($request)) {
            return Json::error($response, 'forbidden', 'Hromadné akce smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $params = ($body['dry_run'] ?? true) !== false
                ? $this->dryRunParams($request, $supplierId, $body)
                : $this->applyParams($request, $supplierId, $body);
        } catch (BulkActionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Hromadná akce už ve firmě běží (úloha #{$running['id']}, stav: {$running['status']}).", 409,
                ['existing_job_id' => $running['id']]);
        }
        return $this->spawn($request, $response, $supplierId, $params);
    }

    /** POST /api/accounting/bulk-actions/{id}/rollback */
    public function rollback(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BulkActionService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $params = (array) $job['params'];
        try {
            $operation = $this->operations->get((string) ($params['operation'] ?? ''), (string) ($params['target'] ?? ''));
        } catch (BulkActionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $all = $this->jobs->listForTenant($supplierId, BulkActionService::SOURCE, limit: 50);
        if (!$operation->supportsRollback() || !AccountingBulkJobs::canRollback($job, $all)) {
            return Json::error($response, 'rollback_not_available', 'Vrátit jde jen dokončený ostrý běh, který se ještě nevracel. Smazání vrátit nejde.', 409);
        }
        $reason = $operation->unavailableReason($request, $supplierId, (string) $params['target']);
        if ($reason !== null) {
            return Json::error($response, 'forbidden', $reason, 403);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Hromadná akce už ve firmě běží (úloha #{$running['id']}, stav: {$running['status']}).", 409,
                ['existing_job_id' => $running['id']]);
        }
        unset($params['dry_run_job_id'], $params['excluded_item_ids'], $params['confirm_count']);
        return $this->spawn($request, $response, $supplierId, ['kind' => 'rollback', 'rollback_of_job_id' => (int) $job['id']] + $params);
    }

    /** GET /api/accounting/bulk-actions/{id} */
    public function job(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BulkActionService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        if (($deny = $this->denyList($request, $response, (string) (($job['params'] ?? [])['target'] ?? ''))) !== null) {
            return $deny;
        }
        $all = $this->jobs->listForTenant($supplierId, BulkActionService::SOURCE, limit: 50);
        return Json::ok($response, $this->view($request, $job, $all) + ['log_text' => $job['log_text'] ?? null]);
    }

    /** GET /api/accounting/bulk-actions/{id}/items?status=&page=&per_page= */
    public function itemsPage(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BulkActionService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $params = (array) ($job['params'] ?? []);
        if (($deny = $this->denyList($request, $response, (string) ($params['target'] ?? ''))) !== null) {
            return $deny;
        }
        $query = $request->getQueryParams();
        $status = isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null;
        if ($status !== null && !in_array($status, AccountingBulkItemRepository::STATUSES, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný stav položky.', 422);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 50)));
        $result = $this->items->page($supplierId, (int) $job['id'], $status, $page, $perPage);
        if (($params['operation'] ?? null) === SetDimensionsOperation::KEY) {
            $valueIds = [];
            foreach ($result['items'] as $item) {
                foreach ([$item['before'], $item['after']] as $rows) {
                    foreach ((array) $rows as $row) {
                        array_push($valueIds, ...array_map('intval', array_values((array) ($row['dimensions'] ?? []))));
                        foreach ((array) ($row['splits'] ?? []) as $shares) {
                            array_push($valueIds, ...array_map('intval', array_keys((array) $shares)));
                        }
                    }
                }
            }
            $labels = $this->dimensions->labels($supplierId, $valueIds);
            $result['labels'] = ['types' => (object) $labels['types'], 'values' => (object) $labels['values']];
        }
        return Json::ok($response, $result);
    }

    /** POST /api/accounting/bulk-actions/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canBulkChange($request) && !RequestAuthorization::canBulkRepost($request)) {
            return Json::error($response, 'forbidden', 'Hromadné akce smí správce firmy nebo role s právem Hromadné změny dokladů a zápisů.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($id, $supplierId);
        if ($job === null || $job['source'] !== BulkActionService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $this->jobs->requestCancel($id, $supplierId);
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws BulkActionException
     */
    private function dryRunParams(Request $request, int $supplierId, array $body): array
    {
        $target = (string) ($body['target'] ?? '');
        if (!DocumentItemSearch::isItemTarget($target)) {
            BulkSelection::assertTarget($target);
        }
        $operation = $this->operations->get((string) ($body['operation'] ?? ''), $target);
        $reason = $operation->unavailableReason($request, $supplierId, $target);
        if ($reason !== null) {
            throw new BulkActionException('operation_unavailable', $reason, 409);
        }
        if (!$this->mayReadList($request, $target)) {
            throw new BulkActionException('forbidden', 'Chybí oprávnění k seznamu záznamů.', 403);
        }
        $selection = $body['selection'] ?? null;
        if (!is_array($selection) || (array_key_exists('ids', $selection) === array_key_exists('list_query', $selection))) {
            throw new BulkActionException('validation_failed', 'Zvolte vybrané záznamy, nebo všechny podle filtru seznamu.', 422);
        }
        $ids = match (true) {
            DocumentItemSearch::isItemTarget($target) && array_key_exists('ids', $selection)
                => $this->itemSearch->explicitIds($supplierId, $target, $selection['ids']),
            DocumentItemSearch::isItemTarget($target) => $this->itemSelection($supplierId, $target, $selection['list_query']),
            array_key_exists('ids', $selection) => $this->selection->explicitIds($supplierId, $target, $selection['ids']),
            default => $this->listSelection($request, $supplierId, $target, $selection['list_query']),
        };
        $raw = is_array($body['params'] ?? null) ? $body['params'] : [];
        $params = $operation->normalizeParams($supplierId, $target, ['ids' => $ids] + $raw);

        return [
            'kind' => 'dry_run',
            'operation' => $operation->key(),
            'target' => $target,
            'selection_count' => count($ids),
            'ids' => $ids,
        ] + $params;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws BulkActionException
     */
    private function applyParams(Request $request, int $supplierId, array $body): array
    {
        $dryJobId = (int) ($body['dry_run_job_id'] ?? 0);
        $dryJob = $this->jobs->find($dryJobId, $supplierId);
        $all = $this->jobs->listForTenant($supplierId, BulkActionService::SOURCE, limit: 50);
        if ($dryJob === null || $dryJob['source'] !== BulkActionService::SOURCE || !AccountingBulkJobs::canApply($dryJob, $all)) {
            throw new BulkActionException('matching_dry_run_required', 'Provést jde jen dokončený běh nanečisto, který se ještě neprovedl.', 409);
        }
        $params = (array) $dryJob['params'];
        $operation = $this->operations->get((string) ($params['operation'] ?? ''), (string) ($params['target'] ?? ''));
        $reason = $operation->unavailableReason($request, $supplierId, (string) $params['target']);
        if ($reason !== null) {
            throw new BulkActionException('operation_unavailable', $reason, 409);
        }
        $excluded = $body['excluded_item_ids'] ?? [];
        if (!is_array($excluded) || !array_is_list($excluded) || count($excluded) > BulkSelection::LIMIT
            || array_filter($excluded, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new BulkActionException('validation_failed', 'Neplatný seznam vyřazených položek.', 422);
        }
        $excluded = array_values(array_unique($excluded));
        if ($operation->key() === DeleteOperation::KEY) {
            $would = array_column($this->items->byStatus($supplierId, $dryJobId, 'would_change'), 'id');
            $expected = count($would) - count(array_intersect($would, $excluded));
            if (!isset($body['confirm_count']) || (int) $body['confirm_count'] !== $expected) {
                throw new BulkActionException('confirm_count_mismatch',
                    'Smazání nejde vrátit. Pro potvrzení opište počet záznamů, které se smažou (' . $expected . ').', 422,
                    ['expected' => $expected]);
            }
        }
        return array_merge($params, [
            'kind' => 'apply',
            'dry_run_job_id' => $dryJobId,
            'excluded_item_ids' => $excluded,
        ]);
    }

    /**
     * „Všech N podle filtru": query parametry seznamu vyhodnotí kód seznamu.
     *
     * @return list<int>
     * @throws BulkActionException
     */
    private function listSelection(Request $request, int $supplierId, string $target, mixed $query): array
    {
        if (!is_array($query)) {
            throw new BulkActionException('validation_failed', 'Neplatný filtr seznamu.', 422);
        }
        unset($query['page'], $query['per_page']);
        $scoped = $request->withQueryParams($query);
        $limit = BulkSelection::LIMIT + 1;
        if ($target === 'journal_entry') {
            $result = $this->journal->bulkSelection($scoped, $supplierId, $limit);
        } else {
            $filters = $target === 'invoice' ? InvoiceListFilters::issued($scoped) : InvoiceListFilters::purchase($scoped);
            if (is_string($filters)) {
                throw new BulkActionException('validation_failed', $filters, 422);
            }
            if ($target === 'invoice') {
                $result = $this->invoices->listIds($filters, $limit);
            } else {
                $filter = (array) ($query['filter'] ?? []);
                $viewer = isset($filter['has_visual_document']) && RequestAuthorization::allows($scoped, 'documents', AccessLevel::READ)
                    ? DocumentViewerResolver::fromRequest($scoped) : null;
                $result = $this->purchases->listIds($filters, $limit, $viewer);
            }
        }
        if (is_string($result)) {
            throw new BulkActionException('validation_failed', $result, 422);
        }
        if ($result['total'] > BulkSelection::LIMIT) {
            throw BulkSelection::tooMany($result['total']);
        }
        if ($result['ids'] === []) {
            throw new BulkActionException('no_items', 'Filtru seznamu neodpovídá žádný záznam.', 422);
        }
        sort($result['ids']);
        return $result['ids'];
    }

    /**
     * „Všech N podle filtru" v agendě Položky dokladů: filtr položek ({@see DocumentItemSearch::filters()}).
     *
     * @return list<int>
     * @throws BulkActionException
     */
    private function itemSelection(int $supplierId, string $target, mixed $query): array
    {
        if (!is_array($query)) {
            throw new BulkActionException('validation_failed', 'Neplatný filtr položek.', 422);
        }
        $ids = $this->itemSearch->matchingIds($supplierId, $target, DocumentItemSearch::filters($query), BulkSelection::LIMIT + 1);
        if (count($ids) > BulkSelection::LIMIT) {
            throw new BulkActionException('too_many_items',
                'Filtru odpovídá víc než ' . BulkSelection::LIMIT . ' položek, najednou jde zpracovat nejvýš ' . BulkSelection::LIMIT
                    . '. Zužte filtr nebo vyberte méně položek.',
                422, ['matching' => null, 'limit' => BulkSelection::LIMIT]);
        }
        if ($ids === []) {
            throw new BulkActionException('no_items', 'Filtru neodpovídá žádná položka.', 422);
        }
        sort($ids);
        return $ids;
    }

    private function mayReadList(Request $request, string $target): bool
    {
        $module = match ($target) {
            'invoice', 'invoice_item' => 'invoices',
            'purchase_invoice', 'purchase_invoice_item' => 'purchase_invoices',
            default => 'accounting',
        };
        return RequestAuthorization::allows($request, $module, AccessLevel::READ);
    }

    private function denyList(Request $request, Response $response, string $target): ?Response
    {
        return $this->mayReadList($request, $target)
            ? null
            : Json::error($response, 'forbidden', 'Chybí oprávnění k seznamu záznamů.', 403);
    }

    /**
     * @param array<string,mixed> $job
     * @param list<array<string,mixed>> $all
     * @return array<string,mixed>
     */
    private function view(Request $request, array $job, array $all): array
    {
        $params = (array) ($job['params'] ?? []);
        $operation = (string) ($params['operation'] ?? '');
        $rollback = $operation !== DeleteOperation::KEY;
        $canRun = RequestAuthorization::canBulkChange($request)
            || ($operation === JournalRebookService::KEY && RequestAuthorization::canBulkRepost($request));
        $view = AccountingBulkJobs::view($job, $all, $canRun, self::PARAMS);
        return [
            'operation' => $operation,
            'target' => (string) ($params['target'] ?? ''),
            'selection_count' => (int) ($params['selection_count'] ?? 0),
            'rollback_supported' => $rollback,
        ] + array_merge($view, ['can_rollback' => $rollback && $view['can_rollback']]);
    }

    /** @param array<string,mixed> $params */
    private function spawn(Request $request, Response $response, int $supplierId, array $params): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, BulkActionService::SOURCE, $params, $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== BulkActionService::SOURCE) {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro tuto úlohu - spusťte `php api/bin/migrate.php`.', 500);
        }

        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );

        $this->logger->log('accounting.bulk_action_started', $userId, 'import_job', $jobId,
            array_diff_key($params, ['ids' => true]) + ['ids_count' => count((array) ($params['ids'] ?? []))],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    /**
     * Hromadná úloha, která ve firmě právě běží (Hromadné akce i stránka Přeúčtovat):
     * dva běhy naráz by měnily tytéž doklady proti sobě. Zaseknutý běh se nejdřív uklidí.
     *
     * @return ?array{id:int, status:string}
     */
    private function running(int $supplierId): ?array
    {
        foreach ([BulkActionService::SOURCE, JournalRebookService::SOURCE] as $source) {
            $this->jobs->reapStale($supplierId, $source);
            foreach ($this->jobs->listForTenant($supplierId, $source, limit: 5) as $existing) {
                if (in_array($existing['status'], ['queued', 'running'], true)) {
                    return ['id' => (int) $existing['id'], 'status' => (string) $existing['status']];
                }
            }
        }
        return null;
    }
}
