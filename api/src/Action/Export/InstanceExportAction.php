<?php

declare(strict_types=1);

namespace MyInvoice\Action\Export;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Export\Instance\InstanceExportException;
use MyInvoice\Service\Export\Instance\InstanceExportJobStore;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Stream;

/**
 * Kompletní export dat firmy (H-14) — zákazník si ho vyžádá a stáhne sám.
 *
 *   GET    /api/admin/instance-export                  přehled běhů + co export umí
 *   POST   /api/admin/instance-export/start            založí běh a spustí ho na pozadí
 *   GET    /api/admin/instance-export/{id}             stav (polling)
 *   GET    /api/admin/instance-export/{id}/download    stažení hotového archivu
 *   POST   /api/admin/instance-export/{id}/cancel      zrušení
 *   DELETE /api/admin/instance-export/{id}             smazání běhu i archivu
 *
 * Přístup: všechno pod `/api/admin/` je pro superadmina (fail-closed fallback
 * v {@see \MyInvoice\Security\RoutePermissionMap::match()}); guard v akci je druhá
 * vrstva pro volání mimo middleware. Archiv je kompletní účetnictví firmy —
 * účetní ani readonly uživatel si ho vyžádat nemá.
 *
 * Archiv NELEŽÍ v docrootu: ukládá se přes {@see RuntimePaths} do
 * `storage/instance-exports/sup-{id}` a ven jde jen tímhle autentizovaným
 * endpointem. Stahuje se VÝHRADNĚ soubor dohledaný přes id v `instance_exports`
 * omezené na aktuální firmu — z requestu se nikdy nebere cesta ani název.
 *
 * Export obsahuje volitelný verzovaný obnovitelný archiv. Obnova se spouští jen
 * explicitně z CLI do NOVÉ firmy; webový export proto nemůže přepsat existující
 * data ani se stát cestou k nechtěnému rollbacku.
 */
final class InstanceExportAction
{
    public function __construct(
        private readonly InstanceExportJobStore $jobs,
        private readonly InstanceExportService $export,
        private readonly Config $config,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
    ) {}

    /** GET / — běhy firmy + metadata pro UI. */
    public function list(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $sid = SupplierGuard::currentId($request);
        // Ukliď mrtvé běhy, ať UI neukazuje věčné „běží" a nebrání spuštění dalšího.
        $this->jobs->reapStale($sid);

        $profile = $this->supplierProfile($sid);
        return Json::ok($response, [
            'parts' => InstanceExportService::ALL_PARTS,
            'encrypted' => BackupEncryption::passwordFromConfig($this->config) !== '',
            'ttl_days' => (int) $this->config->get('export.instance.ttl_days', 14),
            'profile' => $profile,
            'active' => $this->present($this->jobs->activeFor($sid)),
            'items' => array_map(fn (?array $j): ?array => $this->present($j), $this->jobs->listForSupplier($sid, 20)),
        ]);
    }

    /** POST /start — založí běh a odpojí ho do procesu na pozadí. */
    public function start(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rawParts = $body['parts'] ?? [];
        $parts = InstanceExportService::normalizeParts(
            is_array($rawParts) ? array_map('strval', $rawParts) : [],
        );
        [$dateFrom, $dateTo, $rangeError] = $this->parseRange($body);
        if ($rangeError !== null) {
            return Json::error($response, 'validation_failed', $rangeError, 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        try {
            $jobId = $this->jobs->create($sid, $parts, $dateFrom, $dateTo, $userId ?: null);
        } catch (InstanceExportException $e) {
            // Druhý souběžný export se odmítne, ne zařadí do fronty — deset čekajících
            // procesů nad kompletním účetnictvím je DoS, ne trpělivost.
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, [
                'active_job_id' => $this->jobs->activeFor($sid)['id'] ?? null,
            ]);
        }

        $rootDir = \MyInvoice\Bootstrap::rootDir();
        $spawned = BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/export-instance.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('instance-export.log'),
            $rootDir,
            $diag,
        );
        if (!$spawned) {
            $this->jobs->markFailed($jobId, 'Nepodařilo se spustit proces exportu na pozadí (' . (string) $diag . ').');
            $this->log->error('Export instance: spawn workeru selhal', ['job_id' => $jobId, 'diag' => $diag]);
            return Json::error($response, 'spawn_failed', 'Export se nepodařilo spustit na pozadí.', 500);
        }

        $this->logEvent($request, 'export.instance_started', $jobId, [
            'parts' => $parts, 'date_from' => $dateFrom, 'date_to' => $dateTo,
        ]);
        return Json::ok($response, ['id' => $jobId, 'status' => 'queued', 'parts' => $parts], 201);
    }

    /** GET /{id} — stav běhu (UI polling). */
    public function status(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $job = $this->owned($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export nenalezen.', 404);
        }
        return Json::ok($response, $this->present($job, withLog: true));
    }

    /** GET /{id}/download — stažení hotového archivu. */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $job = $this->owned($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export nenalezen.', 404);
        }
        if (($job['status'] ?? '') !== 'completed' || empty($job['result_path'])) {
            return Json::error($response, 'not_ready', 'Export ještě není připravený ke stažení.', 409);
        }

        // Cesta pochází výhradně z DB řádku omezeného na aktuální firmu; guard níž je
        // defense-in-depth (case-insensitive kvůli Windows realpath casingu).
        $abs = $this->export->safeResultPath((string) $job['result_path']);
        if ($abs === null) {
            return Json::error($response, 'file_unavailable', 'Archiv už není k dispozici (nejspíš expiroval).', 410);
        }
        $handle = fopen($abs, 'rb');
        if ($handle === false) {
            return Json::error($response, 'file_unavailable', 'Archiv nelze otevřít.', 410);
        }

        $name = (string) ($job['result_name'] ?: 'myucto-export.zip');
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $name) ?? $name;

        $this->logEvent($request, 'export.instance_downloaded', (int) $job['id'], [
            'file' => $safeName, 'sha256' => $job['sha256'] ?? null,
        ]);

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) filesize($abs))
            // Kontrolní součet celku putuje s odpovědí, ať jde ověřit úplnost stažení
            // i bez sidecar souboru vedle archivu.
            ->withHeader('X-Checksum-SHA256', (string) ($job['sha256'] ?? ''))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** POST /{id}/cancel — zruší běžící/čekající export. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $job = $this->owned($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export nenalezen.', 404);
        }
        $ok = $this->jobs->requestCancel((int) $job['id'], SupplierGuard::currentId($request));
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /{id} — smaže běh i vygenerovaný archiv. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) {
            return $err;
        }
        $job = $this->owned($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export nenalezen.', 404);
        }
        if (!empty($job['result_path'])) {
            $abs = $this->export->safeResultPath((string) $job['result_path']);
            if ($abs !== null) {
                @unlink($abs);
                @unlink($abs . '.sha256');
            }
        }
        $this->jobs->delete((int) $job['id'], SupplierGuard::currentId($request));
        $this->logEvent($request, 'export.instance_deleted', (int) $job['id'], [
            'file' => $job['result_name'] ?? null,
        ]);
        return Json::ok($response, ['ok' => true, 'deleted' => true]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function guard(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Kompletní export dat smí spustit jen správce instalace.', 403);
        }
        return null;
    }

    /**
     * Běh omezený na AKTUÁLNÍ firmu. Jediná cesta, jak se v akci dostat k řádku —
     * `findById()` (bez tenant filtru) je vyhrazený workeru.
     *
     * @return array<string,mixed>|null
     */
    private function owned(Request $request, array $args): ?array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        return $this->jobs->find($id, SupplierGuard::currentId($request));
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:?string,1:?string,2:?string} [from, to, error]
     */
    private function parseRange(array $body): array
    {
        $from = isset($body['date_from']) && $body['date_from'] !== '' ? (string) $body['date_from'] : null;
        $to = isset($body['date_to']) && $body['date_to'] !== '' ? (string) $body['date_to'] : null;
        if (($from === null) !== ($to === null)) {
            return [null, null, 'Rozsah se zadává celý (od i do), nebo vůbec.'];
        }
        foreach ([$from, $to] as $value) {
            if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return [null, null, 'Datum musí být ve tvaru RRRR-MM-DD.'];
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            return [null, null, 'Datum „od" nesmí být později než „do".'];
        }
        return [$from, $to, null];
    }

    /**
     * @param array<string,mixed>|null $job
     * @return array<string,mixed>|null
     */
    private function present(?array $job, bool $withLog = false): ?array
    {
        if ($job === null) {
            return null;
        }
        $manifest = is_array($job['manifest'] ?? null) ? $job['manifest'] : null;
        $out = [
            'id' => $job['id'],
            'status' => $job['status'],
            'parts' => $job['parts'],
            'date_from' => $job['date_from'],
            'date_to' => $job['date_to'],
            'total_steps' => $job['total_steps'],
            'processed_steps' => $job['processed_steps'],
            'current_step' => $job['current_step'],
            'last_error' => $job['last_error'],
            'cancel_requested' => $job['cancel_requested'],
            'result_name' => $job['result_name'],
            'size_bytes' => $job['size_bytes'],
            'sha256' => $job['sha256'],
            'encrypted' => $job['encrypted'],
            'expires_at' => $job['expires_at'],
            'created_at' => $job['created_at'],
            'finished_at' => $job['finished_at'],
            'downloadable' => $job['status'] === 'completed' && !empty($job['result_path']),
            'warning_count' => (int) ($manifest['sections']['doklady']['warnings'] ?? 0)
                + (int) ($manifest['sections']['prilohy']['warnings'] ?? 0),
        ];
        if ($withLog) {
            $out['log_text'] = $job['log_text'];
            // Manifest je pro UI zajímavý jen shrnutím — celý (se seznamem stovek
            // tabulek) by z pollingu udělal zbytečně tučný požadavek.
            $out['summary'] = $manifest === null ? null : [
                'entries' => $manifest['totals']['entries'] ?? null,
                'tables' => count($manifest['sections']['data']['tables'] ?? [])
                    + count($manifest['sections']['data']['shared_tables'] ?? [])
                    + count($manifest['sections']['data']['shared_payroll_tables'] ?? [])
                    + count($manifest['sections']['data']['identity']['entries'] ?? []),
                'documents' => $manifest['sections']['doklady'] ?? null,
                'files' => $manifest['sections']['prilohy']['files'] ?? null,
                'restore' => $manifest['sections']['obnova'] ?? null,
                'vat' => $manifest['sections']['dph'] ?? null,
                'closing' => $manifest['sections']['uzaverky'] ?? null,
            ];
        }
        return $out;
    }

    /** @return array{accounting_mode:string,is_vat_payer:bool,vat_period:?string} */
    private function supplierProfile(int $supplierId): array
    {
        return $this->export->supplierProfile($supplierId);
    }

    /** @param array<string,mixed> $payload */
    private function logEvent(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log(
            $action,
            (int) ($user['id'] ?? 0),
            'instance_export',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request),
        );
    }
}
