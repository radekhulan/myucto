<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ClosingPackageService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Uzávěrkový balíček — background job (import_jobs, source='closing_package'), který
 * do jednoho ZIPu sbalí VŠECHNY sestavy k uzávěrce zvoleného účetního období (rozvaha,
 * výsledovka, hlavní kniha, deník, obratová předvaha, kniha DPH, přiznání k dani).
 * Stejný lifecycle jako MonthlyExportAction (queued → running → completed|failed|cancelled).
 *
 *   GET    /api/reports/closing-package/preview?period_id=       → počty per sestava
 *   POST   /api/reports/closing-package/start                    → vytvoří job + spawn worker
 *   GET    /api/reports/closing-package/jobs                     → historie balíčků
 *   GET    /api/reports/closing-package/jobs/{id}                → stav jobu (polling)
 *   GET    /api/reports/closing-package/jobs/{id}/download       → stáhne hotový ZIP
 *   POST   /api/reports/closing-package/jobs/{id}/cancel         → zruší job
 *   DELETE /api/reports/closing-package/jobs/{id}                → smaže job + soubor
 *
 * Přístup: čtení vyžaduje READ, spuštění/zrušení/smazání WRITE (permission reports.export,
 * shodně s MonthlyExportAction). Navíc jen pro firmy vedené v podvojném účetnictví.
 */
final class ClosingPackageAction
{
    use GuardsAccountingMode;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ClosingPackageService $package,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    /** GET /preview — počty dostupných sestav per část. */
    public function preview(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $sid = SupplierGuard::currentId($request);
        if (!$this->requireDoubleEntry($this->db, $sid, $response, $err)) return $err;

        [$periodId, $errResp] = $this->parsePeriodId($request, $response);
        if ($errResp !== null) return $errResp;
        $period = $this->periods->findById($sid, $periodId);
        if ($period === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        try {
            $counts = $this->package->previewCounts($sid, $periodId);
            $metadata = $this->package->previewMetadata($sid, $periodId);
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $metadata + [
            'period_id'   => $periodId,
            'fiscal_year' => (int) $period['fiscal_year'],
            'counts'      => $counts,
        ]);
    }

    /** POST /start — vytvoří job a spustí worker na pozadí. */
    public function start(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response, AccessLevel::WRITE)) !== null) return $err;
        $sid = SupplierGuard::currentId($request);
        if (!$this->requireDoubleEntry($this->db, $sid, $response, $err)) return $err;
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        [$periodId, $errResp] = $this->parsePeriodId($request, $response);
        if ($errResp !== null) return $errResp;
        $period = $this->periods->findById($sid, $periodId);
        if ($period === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        // Ukliď zaseknuté joby (mrtvý worker), jinak by blokovaly nový start.
        $this->jobs->reapStale($sid, 'closing_package');
        foreach ($this->jobs->listForTenant($sid, 'closing_package', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Uzávěrkový balíček už se připravuje (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rawParts = $body['parts'] ?? [];
        try {
            $parts = $this->package->partsForPeriod($sid, $periodId,
                is_array($rawParts) ? array_map('strval', $rawParts) : []);
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $params = [
            'period_id'    => $periodId,
            'fiscal_year'  => (int) $period['fiscal_year'],
            'parts'        => $parts,
            'include_xlsx' => (bool) ($body['include_xlsx'] ?? false),
        ];

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($sid, 'closing_package', $params, $userId);
        $this->spawnWorker($jobId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('reports.closing_package_started', $userId, 'import_job', $jobId, $params,
            $ip, $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'params' => $params], 201);
    }

    /** GET /jobs — historie posledních balíčků (zůstávají ke stažení dokud nejsou uklizené). */
    public function list(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $sid = SupplierGuard::currentId($request);
        $out = array_map(static fn (array $j): array => [
            'id'                => $j['id'],
            'status'            => $j['status'],
            'params'            => $j['params'],
            'total_items'       => $j['total_items'] ?? null,
            'processed'         => $j['processed'],
            'created_count'     => $j['created_count'],
            'failed_count'      => $j['failed_count'],
            'current_step'      => $j['current_step'],
            'last_error'        => $j['last_error'],
            'cancel_requested'  => $j['cancel_requested'],
            'result_name'       => $j['result_name'] ?? null,
            'result_size'       => $j['result_size'] ?? null,
            'created_at'        => $j['created_at'],
            'finished_at'       => $j['finished_at'],
        ], $this->jobs->listForTenant($sid, 'closing_package', 15));
        return Json::ok($response, $out);
    }

    /** GET /jobs/{id} — stav jobu (polling). */
    public function jobStatus(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $job = $this->findOwnedJob($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        return Json::ok($response, $job);
    }

    /** GET /jobs/{id}/download — stáhne hotový ZIP. */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $job = $this->findOwnedJob($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        // `completed_with_warnings` (EP-6) je ke stažení stejně jako `completed` — povinné
        // jádro je kompletní, jen doplňkové části selhaly. Bez toho FE tlačítko stahování
        // nabízel, ale API vracelo not_ready.
        if (!in_array($job['status'] ?? '', ['completed', 'completed_with_warnings'], true) || empty($job['result_path'])) {
            return Json::error($response, 'not_ready', 'Balíček ještě není připravený ke stažení.', 409);
        }

        $abs = $this->package->resolveResultPath((string) $job['result_path']);
        $absReal = realpath($abs);
        $baseReal = realpath($this->package->storageBaseDir());
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        if ($absReal === false || !is_file($absReal) || $baseReal === false
            || !str_starts_with(
                $isWindows ? strtolower($absReal) : $absReal,
                ($isWindows ? strtolower($baseReal) : $baseReal) . DIRECTORY_SEPARATOR
            )) {
            return Json::error($response, 'file_unavailable', 'Soubor balíčku už není k dispozici.', 410);
        }

        $name = (string) ($job['result_name'] ?: 'uzaverkovy-balicek.zip');
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $name) ?? $name;
        $fp = fopen($absReal, 'rb');
        if ($fp === false) {
            return Json::error($response, 'file_unavailable', 'Soubor balíčku nelze otevřít.', 410);
        }

        return $response
            ->withBody(new Stream($fp))
            ->withHeader('Content-Type', (string) ($job['result_mime'] ?: 'application/zip'))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) filesize($absReal))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** POST /jobs/{id}/cancel — zruší běžící/čekající job. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response, AccessLevel::WRITE)) !== null) return $err;
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($this->jobs->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        $ok = $this->jobs->requestCancel($id, $sid);
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /jobs/{id} — smaže job i jeho soubor. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response, AccessLevel::WRITE)) !== null) return $err;
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        $job = $this->jobs->find($id, $sid);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        if (!empty($job['result_path'])) {
            $absReal = realpath($this->package->resolveResultPath((string) $job['result_path']));
            $baseReal = realpath($this->package->storageBaseDir());
            $isWindows = DIRECTORY_SEPARATOR === '\\';
            if ($absReal !== false && $baseReal !== false && is_file($absReal)
                && str_starts_with(
                    $isWindows ? strtolower($absReal) : $absReal,
                    ($isWindows ? strtolower($baseReal) : $baseReal) . DIRECTORY_SEPARATOR
                )) {
                @unlink($absReal);
            }
        }
        $this->jobs->delete($id, $sid);
        return Json::ok($response, ['ok' => true, 'deleted' => true]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $minimum = AccessLevel::READ,
    ): ?Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', $minimum)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        return null;
    }

    /** @return array{0:int,1:?Response} [periodId, errorResponse] */
    private function parsePeriodId(Request $request, Response $response): array
    {
        $merged = array_merge(
            (array) $request->getQueryParams(),
            (array) ($request->getParsedBody() ?? []),
        );
        $periodId = (int) ($merged['period_id'] ?? 0);
        if ($periodId <= 0) {
            return [0, Json::error($response, 'validation_failed', 'period_id je povinný.', 422)];
        }
        return [$periodId, null];
    }

    /** @return array<string,mixed>|null */
    private function findOwnedJob(Request $request, array $args): ?array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return null;
        return $this->jobs->find($id, SupplierGuard::currentId($request));
    }

    private function spawnWorker(int $jobId): void
    {
        $rootDir = \MyInvoice\Bootstrap::rootDir();
        \MyInvoice\Service\BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            \MyInvoice\Infrastructure\Config\RuntimePaths::log('import-worker.log'),
            $rootDir,
        );
    }
}
