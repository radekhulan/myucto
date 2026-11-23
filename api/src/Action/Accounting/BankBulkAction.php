<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Action\Bank\BankPaymentCandidatesAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AccountingBulkJobs;
use MyInvoice\Service\Accounting\Bank\BankBulkService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as Psr7Response;

/**
 * Bankovní pohyby podle filtru (stránka Doúčtovat doklady): nespárované a nezaúčtované
 * pohyby hromadně zaúčtovat na protiúčet, nebo spárovat s jedním dokladem.
 *
 *   GET  /api/accounting/bank-bulk               … poslední běhy, účty výpisů, smí spustit?
 *   POST /api/accounting/bank-bulk/count         … kolik pohybů filtr zasáhne (nic nezapíše)
 *   POST /api/accounting/bank-bulk/start         … nanečisto, nebo ostře podle běhu nanečisto
 *   GET  /api/accounting/bank-bulk/{id}          … stav běhu
 *   GET  /api/accounting/bank-bulk/{id}/items    … výsledek po pohybech
 *   POST /api/accounting/bank-bulk/{id}/rollback … vrátí ostrý běh
 *   POST /api/accounting/bank-bulk/{id}/cancel
 *
 * Běh nanečisto i párování běží přímo v požadavku. Párování jednoho pohybu existuje
 * jen jako HTTP akce ({@see BankPaymentCandidatesAction::match()}, tatáž jako výběr
 * úhrady na detailu dokladu), takže ji hromadný krok volá s požadavkem uživatele
 * a jeho právy; vrácení stejně přes {@see BankStatementAction::unmatch()}. Hromadné
 * zaúčtování a jeho vrácení jdou na pozadí ({@see BankBulkService::run()}).
 */
final class BankBulkAction
{
    private const HISTORY = 10;
    private const PARAMS = [
        'action', 'date_from', 'date_to', 'direction', 'bank_account', 'text', 'counterparty_account',
        'variable_symbol', 'amount_min', 'amount_max', 'counter_account', 'description', 'document_type', 'document_id', 'document_no',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly BankBulkService $bulk,
        private readonly AccountingBulkItemRepository $items,
        private readonly AccountingModeRepository $modes,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly JournalEntryRepository $journal,
        private readonly BankPaymentCandidatesAction $candidates,
        private readonly BankStatementAction $bank,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/accounting/bank-bulk */
    public function status(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $all = $this->jobs->listForTenant($supplierId, BankBulkService::SOURCE, limit: 50);
        $canRun = $this->canRun($request, $supplierId);

        return Json::ok($response, [
            'can_run'     => $canRun,
            'can_match'   => $canRun && RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE),
            'limit'       => BankBulkService::LIMIT,
            'match_limit' => BankBulkService::MATCH_LIMIT,
            'accounts'    => $this->suggestions->transactionAccounts($supplierId),
            'jobs'        => array_map(
                static fn (array $j): array => AccountingBulkJobs::view($j, $all, $canRun, self::PARAMS),
                array_slice($all, 0, self::HISTORY),
            ),
        ]);
    }

    /** POST /api/accounting/bank-bulk/count */
    public function count(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        try {
            $filter = $this->bulk->normalizeFilter($supplierId, (array) ($request->getParsedBody() ?? []));
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $this->bulk->count($supplierId, $filter) + [
            'action_limit' => $filter['action'] === 'match' ? BankBulkService::MATCH_LIMIT : BankBulkService::LIMIT,
        ]);
    }

    /** POST /api/accounting/bank-bulk/start */
    public function start(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        if (!$this->canRun($request, $supplierId)) {
            return Json::error($response, 'forbidden', 'Hromadné úlohy účtování smí správce firmy nebo role s právem hromadně přeúčtovat doklady.', 403);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Úloha už běží (#{$running['id']}, stav: {$running['status']}).", 409, ['existing_job_id' => $running['id']]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            if (($body['dry_run'] ?? true) !== false) {
                $filter = $this->bulk->normalizeFilter($supplierId, $body);
                if ($filter['action'] === 'match' && !RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
                    return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
                }
                $count = $this->bulk->count($supplierId, $filter);
                $limit = $filter['action'] === 'match' ? BankBulkService::MATCH_LIMIT : BankBulkService::LIMIT;
                if ($count['matching'] > $limit) {
                    return Json::error($response, 'too_many_matches',
                        'Filtr zasahuje ' . $count['matching'] . ' pohybů, najednou jde zpracovat nejvýš ' . $limit . '. Zužte období nebo další podmínky.',
                        422, ['matching' => $count['matching'], 'limit' => $limit]);
                }
                if ($count['matching'] === 0) {
                    return Json::error($response, 'validation_failed', 'Filtru neodpovídá žádný nespárovaný nezaúčtovaný pohyb.', 422);
                }
                $jobId = $this->createJob($request, $supplierId, ['kind' => 'dry_run'] + $filter);
                $this->bulk->run($jobId);
                return Json::ok($response, ['job_id' => $jobId, 'status' => 'completed'], 201);
            }

            $dryJobId = (int) ($body['dry_run_job_id'] ?? 0);
            $dryJob = $this->jobs->find($dryJobId, $supplierId);
            $all = $this->jobs->listForTenant($supplierId, BankBulkService::SOURCE, limit: 50);
            if ($dryJob === null || $dryJob['source'] !== BankBulkService::SOURCE || !AccountingBulkJobs::canApply($dryJob, $all)) {
                return Json::error($response, 'matching_dry_run_required',
                    'Provést jde jen dokončený běh nanečisto, který se ještě neprovedl.', 409);
            }
            $excluded = $body['excluded_ids'] ?? [];
            if (!is_array($excluded) || !array_is_list($excluded) || count($excluded) > BankBulkService::LIMIT
                || array_filter($excluded, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
                return Json::error($response, 'validation_failed', 'Neplatný seznam vyřazených pohybů.', 422);
            }
            $params = array_merge((array) $dryJob['params'], [
                'kind' => 'apply', 'dry_run_job_id' => $dryJobId, 'excluded_ids' => array_values(array_unique($excluded)),
            ]);
        } catch (PostingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        if (($params['action'] ?? 'post') === 'match') {
            if (!RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
            }
            $jobId = $this->createJob($request, $supplierId, $params);
            $this->matchNow($request, $jobId, $supplierId, $params);
            return Json::ok($response, ['job_id' => $jobId, 'status' => 'completed'], 201);
        }
        $jobId = $this->createJob($request, $supplierId, $params);
        $this->spawn($jobId);
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    /** POST /api/accounting/bank-bulk/{id}/rollback */
    public function rollback(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if (!$this->canRun($request, $supplierId)) {
            return Json::error($response, 'forbidden', 'Hromadné úlohy účtování smí správce firmy nebo role s právem hromadně přeúčtovat doklady.', 403);
        }
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BankBulkService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        if (($running = $this->running($supplierId)) !== null) {
            return Json::error($response, 'already_running',
                "Úloha už běží (#{$running['id']}, stav: {$running['status']}).", 409, ['existing_job_id' => $running['id']]);
        }
        $all = $this->jobs->listForTenant($supplierId, BankBulkService::SOURCE, limit: 50);
        if (!AccountingBulkJobs::canRollback($job, $all)) {
            return Json::error($response, 'rollback_not_available', 'Vrátit jde jen dokončený ostrý běh, který se ještě nevracel.', 409);
        }
        $params = ['kind' => 'rollback', 'rollback_of_job_id' => (int) $job['id']]
            + array_intersect_key((array) $job['params'], array_flip(self::PARAMS));
        if (($params['action'] ?? 'post') === 'match') {
            if (!RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
            }
            $jobId = $this->createJob($request, $supplierId, $params);
            $this->unmatchNow($request, $jobId, $supplierId, (int) $job['id']);
            return Json::ok($response, ['job_id' => $jobId, 'status' => 'completed'], 201);
        }
        $jobId = $this->createJob($request, $supplierId, $params);
        $this->spawn($jobId);
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    /** GET /api/accounting/bank-bulk/{id} */
    public function job(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BankBulkService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $all = $this->jobs->listForTenant($supplierId, BankBulkService::SOURCE, limit: 50);
        return Json::ok($response, AccountingBulkJobs::view($job, $all, $this->canRun($request, $supplierId), self::PARAMS)
            + ['log_text' => $job['log_text'] ?? null]);
    }

    /** GET /api/accounting/bank-bulk/{id}/items */
    public function items(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || $job['source'] !== BankBulkService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $query = $request->getQueryParams();
        $status = isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null;
        if ($status !== null && !in_array($status, AccountingBulkItemRepository::STATUSES, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný stav položky.', 422);
        }
        return Json::ok($response, $this->items->page(
            $supplierId, (int) $job['id'], $status, max(1, (int) ($query['page'] ?? 1)), min(200, max(1, (int) ($query['per_page'] ?? 50))),
        ));
    }

    /** POST /api/accounting/bank-bulk/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění účtovat.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($id, $supplierId);
        if ($job === null || $job['source'] !== BankBulkService::SOURCE) {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $this->jobs->requestCancel($id, $supplierId);
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * Ostré párování v požadavku: každý pohyb přes {@see BankPaymentCandidatesAction::match()}
     * (zámek pohybu i dokladu, znovu ověří, že pohyb je volnou úhradou dokladu), pak se
     * ze stavu databáze ověří, že vazba vznikla.
     *
     * @param array<string,mixed> $params
     */
    private function matchNow(Request $request, int $jobId, int $supplierId, array $params): void
    {
        $this->jobs->markRunning($jobId);
        $filter = $this->bulk->normalizeFilter($supplierId, $params);
        $plan = $this->bulk->applyPlan($supplierId, (int) $params['dry_run_job_id'], $filter, array_map('intval', (array) $params['excluded_ids']));
        $this->jobs->updateProgress($jobId, ['total_items' => count($plan), 'current_step' => 'apply']);
        $field = $filter['document_type'] === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $counts = ['changed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($plan as $step) {
            $item = self::copy($step['dry']);
            $txId = (int) $item['source_id'];
            if ($step['excluded']) {
                $result = ['status' => 'excluded', 'reason_code' => 'excluded_by_user'] + $item;
            } elseif ($step['current'] === null) {
                $result = ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
            } else {
                try {
                    $resp = $this->candidates->match(
                        $request->withParsedBody([$field => (int) $filter['document_id']]), new Psr7Response(), ['id' => (string) $txId],
                    );
                } catch (\Throwable $e) {
                    $resp = Json::error(new Psr7Response(), 'match_failed', $e->getMessage(), 409);
                }
                if ($resp->getStatusCode() < 400 && $this->isMatched($supplierId, $txId, $filter['document_type'], (int) $filter['document_id'])) {
                    $entry = $this->journal->findBySource($supplierId, 'bank', $txId);
                    $result = ['status' => 'applied', 'result_entry_id' => $entry === null ? null : (int) $entry['id'],
                        'fingerprint' => $this->matchFingerprint($txId)] + $item;
                } else {
                    $result = ['status' => 'failed', 'reason_code' => 'match_failed', 'message' => self::errorMessage($resp)] + $item;
                }
            }
            $this->items->insert($jobId, $supplierId, $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
        }
        $this->finish($jobId, $counts);
        $this->logRun($request, $supplierId, $jobId, 'accounting.bank_bulk_matched', $counts + ['document_type' => $filter['document_type'], 'document_id' => $filter['document_id']]);
    }

    /** Vrácení párování v požadavku přes {@see BankStatementAction::unmatch()}. */
    private function unmatchNow(Request $request, int $jobId, int $supplierId, int $applyJobId): void
    {
        $this->jobs->markRunning($jobId);
        $applied = $this->items->byStatus($supplierId, $applyJobId, 'applied');
        $this->jobs->updateProgress($jobId, ['total_items' => count($applied), 'current_step' => 'rollback']);
        $counts = ['changed' => 0, 'skipped' => 0, 'failed' => 0];
        $canUnmatch = RequestAuthorization::allows($request, 'bank', AccessLevel::WRITE);
        foreach ($applied as $done) {
            $item = ['before' => $done['after'], 'after' => []] + self::copy($done);
            $txId = (int) $done['source_id'];
            if (!$canUnmatch) {
                $result = ['status' => 'failed', 'reason_code' => 'forbidden'] + $item;
            } elseif ($this->matchFingerprint($txId) !== $done['fingerprint']) {
                $result = ['status' => 'failed', 'reason_code' => 'changed_after_apply'] + $item;
            } else {
                try {
                    $resp = $this->bank->unmatch($request, new Psr7Response(), ['id' => (string) $txId]);
                } catch (\Throwable $e) {
                    $resp = Json::error(new Psr7Response(), 'unmatch_failed', $e->getMessage(), 409);
                }
                $result = $resp->getStatusCode() < 400
                    ? ['status' => 'applied'] + $item
                    : ['status' => 'failed', 'reason_code' => 'match_failed', 'message' => self::errorMessage($resp)] + $item;
            }
            $this->items->insert($jobId, $supplierId, $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
        }
        $this->finish($jobId, $counts);
        $this->logRun($request, $supplierId, $jobId, 'accounting.bank_bulk_unmatched', $counts + ['rollback_of_job_id' => $applyJobId]);
    }

    /** Vznikla vazba pohybu na doklad (úhrada vydané, párování přijaté faktury)? */
    private function isMatched(int $supplierId, int $txId, string $type, int $docId): bool
    {
        $stmt = $this->db->pdo()->prepare($type === 'invoice'
            ? 'SELECT 1 FROM invoice_payments WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id = ? LIMIT 1'
            : 'SELECT 1 FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND purchase_invoice_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $txId, $docId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Stav párování pohybu; vrácení ho porovná se stavem po ostrém běhu. */
    private function matchFingerprint(int $txId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.match_status, bt.matched_invoice_id,
                    (SELECT GROUP_CONCAT(CONCAT(ip.invoice_id, ':', ip.amount) ORDER BY ip.id) FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id) AS payments,
                    (SELECT GROUP_CONCAT(CONCAT(pm.purchase_invoice_id, ':', pm.amount) ORDER BY pm.id) FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id) AS matches
               FROM bank_transactions bt WHERE bt.id = ?"
        );
        $stmt->execute([$txId]);
        return hash('sha256', json_encode($stmt->fetch(\PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
    }

    private function canRun(Request $request, int $supplierId): bool
    {
        return RequestAuthorization::canBulkRepost($request) && $supplierId > 0 && $this->modes->hasDoubleEntry($supplierId);
    }

    /** @return ?array{id:int, status:string} */
    private function running(int $supplierId): ?array
    {
        $this->jobs->reapStale($supplierId, BankBulkService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, BankBulkService::SOURCE, limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return ['id' => (int) $existing['id'], 'status' => (string) $existing['status']];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $params */
    private function createJob(Request $request, int $supplierId, array $params): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, BankBulkService::SOURCE, $params, $userId);
        $this->logRun($request, $supplierId, $jobId, 'accounting.bank_bulk_started', $params);
        return $jobId;
    }

    private function spawn(int $jobId): void
    {
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function finish(int $jobId, array $counts): void
    {
        $this->jobs->updateProgress($jobId, [
            'processed'     => $counts['changed'] + $counts['skipped'] + $counts['failed'],
            'created_count' => $counts['changed'],
            'skipped_count' => $counts['skipped'],
            'failed_count'  => $counts['failed'],
        ]);
        $counts['failed'] > 0 ? $this->jobs->markCompletedWithWarnings($jobId) : $this->jobs->markCompleted($jobId);
    }

    /** @param array<string,mixed> $payload */
    private function logRun(Request $request, int $supplierId, int $jobId, string $event, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log($event, (int) ($user['id'] ?? 0) ?: null, 'import_job', $jobId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);
    }

    private static function errorMessage(Response $response): string
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return mb_substr((string) ($body['error']['message'] ?? ('HTTP ' . $response->getStatusCode())), 0, 500);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function copy(array $item): array
    {
        return array_intersect_key($item, array_flip([
            'entry_id', 'source_type', 'source_id', 'entry_date', 'document_no', 'description', 'amount', 'before', 'after', 'fingerprint',
        ]));
    }
}
