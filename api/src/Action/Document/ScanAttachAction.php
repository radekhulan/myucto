<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Service\Document\ScanAttach\ScanAttachJobService;
use MyInvoice\Service\Document\ScanAttach\ScanBatchService;
use MyInvoice\Service\Document\ScanAttach\ScanStagingCleaner;
use MyInvoice\Service\Document\ScanAttach\ScanTargetRegistry;
use MyInvoice\Service\Document\ScanAttach\UploadedScanSource;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Připojení skenů k existujícím dokladům (dávky na pozadí).
 *
 *   GET    /api/scan-attach/targets                 … typy dokladů, ke kterým lze připojovat
 *   GET    /api/scan-attach/batches                 … poslední dávky
 *   POST   /api/scan-attach/batches                 … založí dávku {mode: zip|files, targets[], date_from, date_to, accept_likely, trust_doc_no, extract}
 *   POST   /api/scan-attach/batches/{id}/chunk-bytes  … část nahrávaného ZIP (octet-stream)
 *   POST   /api/scan-attach/batches/{id}/chunk-files  … několik souborů (multipart file[])
 *   POST   /api/scan-attach/batches/{id}/finish     … spustí zpracování
 *   GET    /api/scan-attach/batches/{id}            … stav a přehled výsledku
 *   POST   /api/scan-attach/batches/{id}/resume     … znovu spustí (navázání po pádu / nové párování)
 *   POST   /api/scan-attach/batches/{id}/cancel
 *   DELETE /api/scan-attach/batches/{id}            … smaže dávku (dokumenty a vazby zůstanou)
 *   POST   /api/scan-attach/matches/{id}/confirm    … potvrdí navržený pár
 *   POST   /api/scan-attach/matches/{id}/reject     … odmítne navržený pár
 *
 * Dávka patří svému tvůrci; admin firmy vidí všechny (stejně jako joby Dokumentů).
 */
final class ScanAttachAction
{
    private const SOURCE = ScanAttachJobService::SOURCE;
    /** Strop velikosti dávky — ZIP i jednotlivé soubory dohromady (anti-DoS). */
    private const MAX_CHUNKED_BYTES = 2 * 1024 * 1024 * 1024;
    private const MAX_FILES = 20000;
    /** Rozpracované dávky firmy (nahrávané nebo čekající), každá až MAX_CHUNKED_BYTES na disku. */
    private const MAX_QUEUED_BATCHES = 3;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ScanBatchRepository $batches,
        private readonly ScanBatchService $service,
        private readonly ScanTargetRegistry $targets,
        private readonly ActivityLogger $logger,
        private readonly ScanStagingCleaner $stagingCleaner,
    ) {}

    /** GET /api/scan-attach/targets */
    public function targets(Request $request, Response $response): Response
    {
        $out = [];
        foreach ($this->targets->all() as $type => $t) {
            $out[] = [
                'type' => $type,
                'available' => $t->isAvailable(),
                'allowed' => RequestAuthorization::allows($request, $t->permission(), AccessLevel::WRITE),
            ];
        }
        return Json::ok($response, ['targets' => $out, 'default' => ScanBatchService::DEFAULT_TARGETS]);
    }

    /** GET /api/scan-attach/batches */
    public function list(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $rows = [];
        foreach ($this->jobs->listForTenant($sid, self::SOURCE, 30) as $job) {
            if (!$this->canAccess($job, $request)) {
                continue;
            }
            $rows[] = $this->jobView($job) + ['counts' => $this->batches->countItemsByOutcome($sid, (int) $job['id'])];
        }
        return Json::ok($response, ['batches' => $rows]);
    }

    /** POST /api/scan-attach/batches */
    public function start(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? '');
        if (!in_array($mode, ['zip', 'files'], true)) {
            return Json::error($response, 'bad_mode', 'Neplatný způsob nahrání (zip nebo files).', 422);
        }
        $types = is_array($body['targets'] ?? null) ? array_values(array_unique(array_map('strval', $body['targets']))) : ScanBatchService::DEFAULT_TARGETS;
        if ($types === []) {
            return Json::error($response, 'no_targets', 'Vyberte aspoň jeden typ dokladů.', 422);
        }
        foreach ($types as $type) {
            $t = $this->targets->available($type);
            if ($t === null) {
                return Json::error($response, 'bad_target', "K dokladům typu {$type} nelze připojovat.", 422);
            }
            if (!RequestAuthorization::allows($request, $t->permission(), AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', "Chybí oprávnění připojovat přílohy k dokladům typu {$type}.", 403);
            }
        }
        $dates = [];
        foreach (['date_from', 'date_to'] as $k) {
            $v = isset($body[$k]) && is_string($body[$k]) && $body[$k] !== '' ? $body[$k] : null;
            if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return Json::error($response, 'validation_failed', 'Datum musí být ve tvaru RRRR-MM-DD.', 422);
            }
            $dates[$k] = $v;
        }
        if ($dates['date_from'] !== null && $dates['date_to'] !== null && $dates['date_from'] > $dates['date_to']) {
            return Json::error($response, 'validation_failed', 'Datum „od" je po datu „do".', 422);
        }

        $this->jobs->reapStale($sid, self::SOURCE);
        $this->stagingCleaner->purge($sid);
        foreach ($this->jobs->listForTenant($sid, self::SOURCE, 10) as $existing) {
            if ($existing['status'] === 'running') {
                return Json::error($response, 'already_running',
                    "Dávka skenů už běží (#{$existing['id']}). Počkejte na její dokončení.", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        if ($this->batches->countQueuedBatches($sid) >= self::MAX_QUEUED_BATCHES) {
            return Json::error($response, 'too_many_batches',
                'Firma má rozpracované ' . self::MAX_QUEUED_BATCHES . ' dávky skenů. Dokončete jejich nahrání, nebo je smažte.', 409);
        }

        $params = [
            'mode' => $mode,
            'targets' => $types,
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
            'accept_likely' => !empty($body['accept_likely']),
            'trust_doc_no' => !empty($body['trust_doc_no']),
            'extract' => !array_key_exists('extract', $body) || !empty($body['extract']),
        ];
        $userId = $this->userId($request);
        $jobId = $this->jobs->create($sid, self::SOURCE, $params, $userId ?? 0);
        $stored = $this->jobs->find($jobId, $sid);
        if ($stored === null || ($stored['source'] ?? '') !== self::SOURCE) {
            $this->jobs->delete($jobId, $sid);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro tuto úlohu — spusťte `php api/bin/migrate.php`.', 500);
        }
        $dir = ScanAttachJobService::stagingDir($sid, $jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->jobs->delete($jobId, $sid);
            return Json::error($response, 'storage_not_writable', 'Úložiště dávek není zapisovatelné.', 500);
        }
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued'], 201);
    }

    /** POST /api/scan-attach/batches/{id}/chunk-bytes — ZIP po částech */
    public function chunkBytes(Request $request, Response $response, array $args): Response
    {
        $job = $this->uploadingJob($request, $args, 'zip');
        if ($job instanceof Response) {
            return $job;
        }
        $blob = ScanAttachJobService::stagingDir((int) $job['supplier_id'], (int) $job['id']) . '/blob';
        $data = (string) $request->getBody();
        if ($data !== '' && @file_put_contents($blob, $data, FILE_APPEND) === false) {
            return Json::error($response, 'write_failed', 'Zápis části souboru selhal.', 500);
        }
        clearstatcache(true, $blob);
        if ((int) @filesize($blob) > self::MAX_CHUNKED_BYTES) {
            @unlink($blob);
            return Json::error($response, 'too_large', 'Soubor je příliš velký.', 413);
        }
        $this->batches->touchJob((int) $job['supplier_id'], (int) $job['id']);
        return Json::ok($response, ['size' => (int) @filesize($blob)]);
    }

    /** POST /api/scan-attach/batches/{id}/chunk-files — několik souborů naráz */
    public function chunkFiles(Request $request, Response $response, array $args): Response
    {
        $job = $this->uploadingJob($request, $args, 'files');
        if ($job instanceof Response) {
            return $job;
        }
        $sid = (int) $job['supplier_id'];
        $dir = ScanAttachJobService::stagingDir($sid, (int) $job['id']);
        $manifest = $dir . '/manifest.jsonl';
        [$already, $bytes] = self::manifestTotals($manifest);
        $append = static function (array $entry) use ($manifest): void {
            @file_put_contents($manifest, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        };

        $files = $request->getUploadedFiles();
        $list = isset($files['file']) ? (is_array($files['file']) ? array_values($files['file']) : [$files['file']]) : [];
        $added = 0;
        foreach ($list as $file) {
            if ($already + $added >= self::MAX_FILES) {
                return Json::error($response, 'too_many_files', 'Dávka může mít nejvýš ' . self::MAX_FILES . ' souborů.', 413);
            }
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }
            $name = trim((string) $file->getClientFilename());
            if ($name === '') {
                continue;
            }
            $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 255);
            // Soubor nad strop jednoho souboru se neukládá; v dávce zůstane jako chyba.
            $declared = (int) ($file->getSize() ?? 0);
            if ($declared > UploadedScanSource::MAX_FILE_BYTES) {
                $append(['n' => $name, 'e' => 'too_large', 's' => $declared]);
                $added++;
                continue;
            }
            if ($bytes + $declared > self::MAX_CHUNKED_BYTES) {
                return $this->batchTooLarge($response);
            }
            $part = $dir . '/p' . bin2hex(random_bytes(8));
            try {
                $file->moveTo($part);
            } catch (\Throwable) {
                continue;
            }
            clearstatcache(true, $part);
            $size = (int) @filesize($part);
            if ($size > UploadedScanSource::MAX_FILE_BYTES) {
                @unlink($part);
                $append(['n' => $name, 'e' => 'too_large', 's' => $size]);
                $added++;
                continue;
            }
            if ($bytes + $size > self::MAX_CHUNKED_BYTES) {
                @unlink($part);
                return $this->batchTooLarge($response);
            }
            $append(['f' => basename($part), 'n' => $name, 's' => $size]);
            $bytes += $size;
            $added++;
        }
        $this->batches->touchJob($sid, (int) $job['id']);
        return Json::ok($response, ['added' => $added]);
    }

    private function batchTooLarge(Response $response): Response
    {
        return Json::error($response, 'batch_too_large',
            'Dávka může mít dohromady nejvýš ' . (int) (self::MAX_CHUNKED_BYTES / 1024 / 1024 / 1024) . ' GB.', 413);
    }

    /**
     * Počet souborů a součet velikostí už nahraných do dávky.
     *
     * @return array{0:int,1:int}
     */
    private static function manifestTotals(string $manifest): array
    {
        if (!is_file($manifest)) {
            return [0, 0];
        }
        $count = 0;
        $bytes = 0;
        foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $count++;
            $e = json_decode($line, true);
            if (is_array($e) && !isset($e['e'])) {
                $bytes += (int) ($e['s'] ?? 0);
            }
        }
        return [$count, $bytes];
    }

    /** POST /api/scan-attach/batches/{id}/finish */
    public function finish(Request $request, Response $response, array $args): Response
    {
        $job = $this->uploadingJob($request, $args, null);
        if ($job instanceof Response) {
            return $job;
        }
        $sid = (int) $job['supplier_id'];
        $dir = ScanAttachJobService::stagingDir($sid, (int) $job['id']);
        if (!is_file($dir . '/blob') && !is_file($dir . '/manifest.jsonl')) {
            return Json::error($response, 'no_files', 'Dávka neobsahuje žádné soubory.', 409);
        }
        $this->spawn((int) $job['id']);
        $this->logger->log('documents.scan_attach_started', $this->userId($request), 'import_job', (int) $job['id'],
            $job['params'] ?? [], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['job_id' => (int) $job['id'], 'status' => 'queued']);
    }

    /** GET /api/scan-attach/batches/{id} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $job = $this->ownedJob($request, $args);
        if ($job instanceof Response) {
            return $job;
        }
        $sid = (int) $job['supplier_id'];
        $running = in_array($job['status'], ['queued', 'running'], true);
        return Json::ok($response, $this->jobView($job) + [
            'log_text' => $job['log_text'] ?? null,
            'overview' => $running ? null : $this->service->overview($sid, $job),
        ]);
    }

    /** POST /api/scan-attach/batches/{id}/resume */
    public function resume(Request $request, Response $response, array $args): Response
    {
        $job = $this->ownedJob($request, $args);
        if ($job instanceof Response) {
            return $job;
        }
        $sid = (int) $job['supplier_id'];
        foreach ((array) (($job['params'] ?? [])['targets'] ?? ScanBatchService::DEFAULT_TARGETS) as $type) {
            $t = $this->targets->get((string) $type);
            if ($t !== null && !RequestAuthorization::allows($request, $t->permission(), AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', "Chybí oprávnění připojovat přílohy k dokladům typu {$type}.", 403);
            }
        }
        if (!$this->batches->requeue($sid, (int) $job['id'])) {
            return Json::error($response, 'not_resumable', 'Dávku teď nelze znovu spustit (ještě běží).', 409);
        }
        $this->spawn((int) $job['id']);
        return Json::ok($response, ['job_id' => (int) $job['id'], 'status' => 'queued']);
    }

    /** POST /api/scan-attach/batches/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $job = $this->ownedJob($request, $args);
        if ($job instanceof Response) {
            return $job;
        }
        $ok = $this->jobs->requestCancel((int) $job['id'], (int) $job['supplier_id']);
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /api/scan-attach/batches/{id} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $job = $this->ownedJob($request, $args);
        if ($job instanceof Response) {
            return $job;
        }
        if ($job['status'] === 'running') {
            return Json::error($response, 'running', 'Běžící dávku nejdřív zrušte.', 409);
        }
        $sid = (int) $job['supplier_id'];
        $this->batches->deleteBatch($sid, (int) $job['id']);
        $dir = ScanAttachJobService::stagingDir($sid, (int) $job['id']);
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        $this->jobs->delete((int) $job['id'], $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** POST /api/scan-attach/matches/{id}/confirm */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->decide($request, $response, $args, true);
    }

    /** POST /api/scan-attach/matches/{id}/reject */
    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->decide($request, $response, $args, false);
    }

    private function decide(Request $request, Response $response, array $args, bool $confirm): Response
    {
        $sid = SupplierGuard::currentId($request);
        $match = $this->batches->findMatch($sid, (int) ($args['id'] ?? 0));
        $job = $match !== null ? $this->jobs->find((int) $match['job_id'], $sid) : null;
        if ($match === null || $job === null || !$this->canAccess($job, $request)) {
            return Json::error($response, 'not_found', 'Návrh nenalezen.', 404);
        }
        if ($confirm) {
            $t = $this->targets->get((string) $match['target_type']);
            if ($t === null || !RequestAuthorization::allows($request, $t->permission(), AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'Chybí oprávnění připojit přílohu k tomuto dokladu.', 403);
            }
        }
        try {
            $res = $confirm
                ? $this->service->confirm($sid, (int) $match['id'], $this->userId($request))
                : $this->service->reject($sid, (int) $match['id'], $this->userId($request));
        } catch (\RuntimeException $e) {
            return Json::error($response, 'attach_failed', $e->getMessage(), 409);
        }
        if (!$res['ok']) {
            return Json::error($response, (string) $res['error'], 'Návrh už byl rozhodnut.', 409);
        }
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * Dávka ve stavu nahrávání (queued, ještě nespuštěná).
     *
     * @return array<string,mixed>|Response
     */
    private function uploadingJob(Request $request, array $args, ?string $mode): array|Response
    {
        $job = $this->ownedJob($request, $args);
        if ($job instanceof Response) {
            return $job;
        }
        if ($job['status'] !== 'queued' || ($mode !== null && (($job['params'] ?? [])['mode'] ?? '') !== $mode)) {
            return Json::error(new \Slim\Psr7\Response(), 'bad_job', 'Dávka už nepřijímá soubory.', 409);
        }
        if (!is_dir(ScanAttachJobService::stagingDir((int) $job['supplier_id'], (int) $job['id']))) {
            return Json::error(new \Slim\Psr7\Response(), 'no_staging', 'Úložiště dávky nenalezeno.', 409);
        }
        return $job;
    }

    /** @return array<string,mixed>|Response */
    private function ownedJob(Request $request, array $args): array|Response
    {
        $sid = SupplierGuard::currentId($request);
        $job = $sid > 0 ? $this->jobs->find((int) ($args['id'] ?? 0), $sid) : null;
        if ($job === null || ($job['source'] ?? '') !== self::SOURCE || !$this->canAccess($job, $request)) {
            return Json::error(new \Slim\Psr7\Response(), 'not_found', 'Dávka nenalezena.', 404);
        }
        return $job;
    }

    /** @param array<string,mixed> $job */
    private function canAccess(array $job, Request $request): bool
    {
        $viewer = DocumentViewerResolver::fromRequest($request);
        if ($viewer->isAdmin) {
            return true;
        }
        return $viewer->userId !== null && (int) ($job['created_by'] ?? 0) === $viewer->userId;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function clientIp(Request $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
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

    /**
     * @param array<string,mixed> $j
     * @return array<string,mixed>
     */
    private function jobView(array $j): array
    {
        $params = is_array($j['params'] ?? null) ? $j['params'] : [];
        return [
            'id' => (int) $j['id'],
            'status' => (string) $j['status'],
            'total_items' => $j['total_items'] !== null ? (int) $j['total_items'] : null,
            'processed' => (int) $j['processed'],
            'attached_count' => (int) $j['created_count'],
            'proposed_count' => (int) $j['skipped_count'],
            'failed_count' => (int) $j['failed_count'],
            'current_step' => $j['current_step'] ?? null,
            'last_error' => $j['last_error'] ?? null,
            'cancel_requested' => (bool) $j['cancel_requested'],
            'created_at' => (string) ($j['created_at'] ?? ''),
            'finished_at' => $j['finished_at'] ?? null,
            'params' => [
                'mode' => $params['mode'] ?? null,
                'targets' => $params['targets'] ?? ScanBatchService::DEFAULT_TARGETS,
                'date_from' => $params['date_from'] ?? null,
                'date_to' => $params['date_to'] ?? null,
                'accept_likely' => !empty($params['accept_likely']),
                'trust_doc_no' => !empty($params['trust_doc_no']),
                'extract' => !array_key_exists('extract', $params) || !empty($params['extract']),
            ],
        ];
    }
}
