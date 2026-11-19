<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AccountingBulkJobs;
use MyInvoice\Service\Accounting\JournalRebookService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné přeúčtování (stránka Přeúčtovat doklady), sesterská úloha k Doúčtování.
 *
 *   GET  /api/accounting/journal-rebook              … poslední běhy + smí spustit?
 *   POST /api/accounting/journal-rebook/count        … kolik dokladů filtr zasáhne (nic nezapíše)
 *   POST /api/accounting/journal-rebook/start        … běh nanečisto, nebo ostrý běh podle běhu nanečisto
 *   GET  /api/accounting/journal-rebook/{id}         … stav úlohy pro polling
 *   GET  /api/accounting/journal-rebook/{id}/items   … výsledek po dokladech (stránkovaně)
 *   POST /api/accounting/journal-rebook/{id}/rollback… vrátí ostrý běh
 *   POST /api/accounting/journal-rebook/{id}/cancel
 *
 * Ostrý běh nebere filtr z požadavku: přeúčtuje jen doklady, které ukázal dokončený
 * běh nanečisto, bez těch, které uživatel v náhledu vyřadil
 * ({@see JournalRebookService}).
 */
final class JournalRebookAction
{
    private const HISTORY = 10;
    private const PARAMS = [
        'from_account', 'to_account', 'side', 'date_from', 'date_to', 'source_types', 'text', 'amount_min', 'amount_max', 'mode',
        'partner_id', 'dimension_value_id', 'posting_rule_key', 'document_no_from', 'document_no_to',
    ];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly JournalRebookService $rebook,
        private readonly AccountingModeRepository $modes,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/accounting/journal-rebook */
    public function status(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $all = $this->jobs->listForTenant($supplierId, JournalRebookService::SOURCE, limit: 50);
        $canRun = RequestAuthorization::canRebookAccounts($request) && $this->modes->hasDoubleEntry($supplierId);

        return Json::ok($response, [
            'can_run' => $canRun,
            'limit'   => JournalRebookService::LIMIT,
            'jobs'    => array_map(
                fn (array $j): array => AccountingBulkJobs::view($j, $all, $canRun, self::PARAMS),
                array_slice($all, 0, self::HISTORY),
            ),
        ]);
    }

    /** POST /api/accounting/journal-rebook/count */
    public function count(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        try {
            $filter = $this->rebook->normalizeFilter($supplierId, (array) ($request->getParsedBody() ?? []));
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $this->rebook->count($supplierId, $filter));
    }

    /** POST /api/accounting/journal-rebook/start */
    public function start(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        if (!RequestAuthorization::canRebookAccounts($request)) {
            return Json::error($response, 'forbidden', 'Hromadné přeúčtování smí jen správce firmy.', 403);
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry', 'Firma nevede podvojné účetnictví.', 409);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Přeúčtování už běží (úloha #{$running['id']}, stav: {$running['status']}).", 409,
                ['existing_job_id' => $running['id']]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $dryRun = ($body['dry_run'] ?? true) !== false;
        try {
            if ($dryRun) {
                $filter = $this->rebook->normalizeFilter($supplierId, $body);
                $count = $this->rebook->count($supplierId, $filter);
                if ($count['matching'] > JournalRebookService::LIMIT) {
                    return Json::error($response, 'too_many_matches',
                        'Filtr zasahuje ' . $count['matching'] . ' dokladů, najednou jde přeúčtovat nejvýš '
                        . JournalRebookService::LIMIT . '. Zužte období nebo další podmínky.', 422,
                        ['matching' => $count['matching'], 'limit' => JournalRebookService::LIMIT]);
                }
                if ($count['matching'] === 0) {
                    return Json::error($response, 'validation_failed', 'Filtru neodpovídá žádný zaúčtovaný doklad.', 422);
                }
                $params = ['kind' => 'dry_run'] + $filter;
            } else {
                $dryJobId = (int) ($body['dry_run_job_id'] ?? 0);
                $dryJob = $this->jobs->find($dryJobId, $supplierId);
                $all = $this->jobs->listForTenant($supplierId, JournalRebookService::SOURCE, limit: 50);
                if ($dryJob === null || $dryJob['source'] !== JournalRebookService::SOURCE || !AccountingBulkJobs::canApply($dryJob, $all)) {
                    return Json::error($response, 'matching_dry_run_required',
                        'Přeúčtovat jde jen podle dokončeného běhu nanečisto, který se ještě neprovedl.', 409);
                }
                $excluded = $body['excluded_entry_ids'] ?? [];
                if (!is_array($excluded) || !array_is_list($excluded) || count($excluded) > JournalRebookService::LIMIT
                    || array_filter($excluded, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
                    return Json::error($response, 'validation_failed', 'Neplatný seznam vyřazených zápisů.', 422);
                }
                $params = array_merge((array) $dryJob['params'], [
                    'kind' => 'apply',
                    'dry_run_job_id' => $dryJobId,
                    'excluded_entry_ids' => array_values(array_unique($excluded)),
                ]);
            }
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->spawn($request, $response, $supplierId, $params);
    }

    /** POST /api/accounting/journal-rebook/{id}/rollback */
    public function rollback(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if (!RequestAuthorization::canRebookAccounts($request)) {
            return Json::error($response, 'forbidden', 'Hromadné přeúčtování smí jen správce firmy.', 403);
        }
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== JournalRebookService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Přeúčtování už běží (úloha #{$running['id']}, stav: {$running['status']}).", 409,
                ['existing_job_id' => $running['id']]);
        }
        $all = $this->jobs->listForTenant($supplierId, JournalRebookService::SOURCE, limit: 50);
        if (!AccountingBulkJobs::canRollback($job, $all)) {
            return Json::error($response, 'rollback_not_available', 'Vrátit jde jen dokončené přeúčtování, které se ještě nevracelo.', 409);
        }
        return $this->spawn($request, $response, $supplierId, [
            'kind' => 'rollback',
            'rollback_of_job_id' => (int) $job['id'],
        ] + array_intersect_key((array) $job['params'], array_flip(self::PARAMS)));
    }

    /** GET /api/accounting/journal-rebook/{id} */
    public function job(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== JournalRebookService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $all = $this->jobs->listForTenant($supplierId, JournalRebookService::SOURCE, limit: 50);
        $canRun = RequestAuthorization::canRebookAccounts($request);

        return Json::ok($response, AccountingBulkJobs::view($job, $all, $canRun, self::PARAMS) + ['log_text' => $job['log_text'] ?? null]);
    }

    /** GET /api/accounting/journal-rebook/{id}/items?status=&page=&per_page= */
    public function items(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== JournalRebookService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $query = $request->getQueryParams();
        $status = isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null;
        if ($status !== null && !in_array($status, ['would_change', 'unchanged', 'skipped', 'excluded', 'applied', 'failed'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný stav položky.', 422);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 50)));

        return Json::ok($response, $this->rebook->items($supplierId, (int) $job['id'], $status, $page, $perPage));
    }

    /** POST /api/accounting/journal-rebook/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění účtovat.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($id, $supplierId);
        if ($job === null || $job['source'] !== JournalRebookService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $this->jobs->requestCancel($id, $supplierId);

        return Json::ok($response, ['ok' => true]);
    }

    /** @param array<string,mixed> $params */
    private function spawn(Request $request, Response $response, int $supplierId, array $params): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, JournalRebookService::SOURCE, $params, $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== JournalRebookService::SOURCE) {
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

        $this->logger->log('accounting.journal_rebook_started', $userId, 'import_job', $jobId, $params,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    /**
     * Běh přeúčtování nebo hromadné akce, který ve firmě právě běží. Dva běhy naráz by přeúčtovaly tytéž
     * doklady proti sobě; zaseknutý běh (mrtvý worker) se nejdřív uklidí.
     *
     * @return ?array{id:int, status:string}
     */
    private function running(int $supplierId): ?array
    {
        // Hromadné akce v seznamech mění tytéž doklady, proto se hlídají spolu.
        foreach ([JournalRebookService::SOURCE, \MyInvoice\Service\Accounting\Bulk\BulkActionService::SOURCE] as $source) {
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
