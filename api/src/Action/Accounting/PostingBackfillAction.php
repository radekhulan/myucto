<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Doúčtování nezaúčtovaných dokladů — samostatná úloha, ne krok průvodce aktivací.
 *
 *   GET    /api/accounting/posting-backfill          … kolik dokladů čeká + poslední běhy
 *   POST   /api/accounting/posting-backfill/start    … spustí úlohu na pozadí
 *   GET    /api/accounting/posting-backfill/{id}     … stav pro polling
 *   POST   /api/accounting/posting-backfill/{id}/cancel
 *
 * Proč vlastní endpoint, když existuje hromadné zaúčtování z výběru: to má strop 500
 * dokladů a jede z označených řádků v seznamu. Po importu historie jde o tisíce dokladů,
 * které uživatel nemá jak označit — a hlavně o operaci, kterou po sobě žádné nastavení
 * nespustí ({@see \MyInvoice\Service\Accounting\PostingBackfillJobService}).
 */
final class PostingBackfillAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        // Počty nezaúčtovaných sdílí s průvodcem aktivací — kdyby si je tahle
        // obrazovka počítala vlastním dotazem, rozešla by se s ním při první změně
        // definice „nezaúčtovaný doklad".
        private readonly PendingBackfillCounter $pending,
        private readonly AccountingModeRepository $modes,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/accounting/posting-backfill */
    public function status(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }

        $jobs = array_map(
            static fn (array $j): array => self::jobView($j),
            $this->jobs->listForTenant($supplierId, 'document_backfill', limit: 5),
        );

        return Json::ok($response, [
            'pending' => $this->pending->count($supplierId),
            'jobs'    => $jobs,
        ]);
    }

    /** POST /api/accounting/posting-backfill/start — body { from?: 'YYYY-MM-DD', year?: int, dry_run?: bool } */
    public function start(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění účtovat.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'not_double_entry',
                'Firma nevede podvojné účetnictví — není co účtovat.', 409);
        }

        // Zaseknutá úloha (mrtvý worker) by jinak navždy blokovala další spuštění.
        $this->jobs->reapStale($supplierId, 'document_backfill');
        foreach ($this->jobs->listForTenant($supplierId, 'document_backfill', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Účtování už běží (úloha #{$existing['id']}, stav: {$existing['status']}).", 409,
                    ['existing_job_id' => $existing['id']],
                );
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $from = isset($body['from']) && is_string($body['from']) && $body['from'] !== '' ? $body['from'] : null;
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            return Json::error($response, 'validation_failed', 'Datum „od" musí být ve tvaru RRRR-MM-DD.', 422);
        }
        $year = isset($body['year']) && $body['year'] !== null && $body['year'] !== '' ? (int) $body['year'] : null;
        $params = ['from' => $from, 'year' => $year, 'dry_run' => !empty($body['dry_run'])];

        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, 'document_backfill', $params, $userId);

        // Chybějící migrace by MySQL v ne-striktním režimu uložila jako prázdný source
        // a úloha by uvízla navždy — radši ji hned zrušit a říct proč.
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== 'document_backfill') {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro tuto úlohu — spusťte `php api/bin/migrate.php`.', 500);
        }

        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );

        $this->logger->log('accounting.posting_backfill_started', $userId, 'import_job', $jobId, $params,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'] + $params, 201);
    }

    /** GET /api/accounting/posting-backfill/{id} */
    public function job(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k účetnímu deníku.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || ($job['source'] ?? '') !== 'document_backfill') {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }

        return Json::ok($response, self::jobView($job) + ['log_text' => $job['log_text'] ?? null]);
    }

    /** POST /api/accounting/posting-backfill/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění účtovat.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $job = $this->jobs->find($id, $supplierId);
        if ($job === null || ($job['source'] ?? '') !== 'document_backfill') {
            return Json::error($response, 'not_found', 'Úloha nenalezena.', 404);
        }
        $this->jobs->requestCancel($id, $supplierId);

        return Json::ok($response, ['ok' => true]);
    }

    /**
     * @param array<string,mixed> $j
     * @return array<string,mixed>
     */
    private static function jobView(array $j): array
    {
        return [
            'id'            => (int) $j['id'],
            'status'        => (string) $j['status'],
            'total_items'   => $j['total_items'] !== null ? (int) $j['total_items'] : null,
            'processed'     => (int) $j['processed'],
            'posted_count'  => (int) $j['created_count'],
            'skipped_count' => (int) $j['skipped_count'],
            'failed_count'  => (int) $j['failed_count'],
            'current_step'  => $j['current_step'] ?? null,
            'last_error'    => $j['last_error'] ?? null,
            'created_at'    => $j['created_at'] ?? null,
            'finished_at'   => $j['finished_at'] ?? null,
            'dry_run'       => !empty(($j['params'] ?? [])['dry_run']),
        ];
    }
}
