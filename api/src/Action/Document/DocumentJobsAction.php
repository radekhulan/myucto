<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Background joby sekce Dokumenty (vzor reports/monthly-export):
 *   - rozbalení nahraného ZIP (document_zip_import),
 *   - ZIP export vybraných dokumentů (document_zip_export).
 *
 * Lifecycle: start → spawn worker → frontend polluje status → download výsledku.
 */
final class DocumentJobsAction
{
    use DocumentActionTrait;

    private const SOURCES = ['document_zip_import', 'document_zip_export', 'document_folder_import'];
    /** Strop akumulovaného chunkovaného souboru (anti-DoS). */
    private const MAX_CHUNKED_BYTES = 1024 * 1024 * 1024; // 1 GiB

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly DocumentStorage $storage,
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
    ) {}

    /** POST /api/documents/zip-import — nahraj ZIP, rozbal na pozadí. */
    public function zipImport(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        $userId = $this->userId($request);

        $body = (array) $request->getParsedBody();
        $folderId = $this->optInt($body['folder_id'] ?? null);
        if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (is_array($file)) $file = $file[0] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Žádný ZIP nebyl odeslán.', 400);
        }
        $name = trim((string) $file->getClientFilename());
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            return Json::error($response, 'not_zip', 'Soubor není ZIP.', 415);
        }

        // Ulož ZIP do _jobs (musí přežít, než ho worker zpracuje).
        $jobsDir = DocumentStorage::baseDir($sid) . '/_jobs';
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0755, true) && !is_dir($jobsDir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
        }
        $zipPath = $jobsDir . '/import-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $file->moveTo($zipPath);
        } catch (\Throwable) {
            return Json::error($response, 'move_failed', 'Nahrání ZIP selhalo.', 500);
        }

        $jobId = $this->jobs->create($sid, 'document_zip_import', [
            'zip_path'  => $zipPath,
            'folder_id' => $folderId,
            'zip_name'  => $name,
        ], $userId ?? 0);
        if ($this->jobSourceMissing($jobId, $sid, 'document_zip_import')) {
            @unlink($zipPath);
            return $this->migrationError($response);
        }
        $this->spawnWorker($jobId);
        $this->logger->log('document.zip_import_started', $userId, 'document', null,
            ['job_id' => $jobId, 'zip' => $name], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued']);
    }

    /** POST /api/documents/export {ids[], folder_ids[]} — sestav ZIP na pozadí (složky se strukturou). */
    public function export(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        $userId = $this->userId($request);

        $body = (array) $request->getParsedBody();
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
        $folderIds = array_values(array_filter(array_map('intval', (array) ($body['folder_ids'] ?? []))));
        if ($ids === [] && $folderIds === []) {
            return Json::error($response, 'no_ids', 'Nebyly vybrány žádné položky.', 400);
        }

        // Job běží později bez HTTP requestu, proto zachová přesný viewer kontext
        // včetně kategoriálního oprávnění k citlivým mzdovým důkazům.
        $viewerParams = $this->viewer($request)->toJobParams();
        $jobId = $this->jobs->create($sid, 'document_zip_export',
            array_merge(['ids' => $ids, 'folder_ids' => $folderIds], $viewerParams), $userId ?? 0);
        if ($this->jobSourceMissing($jobId, $sid, 'document_zip_export')) {
            return $this->migrationError($response);
        }
        $this->spawnWorker($jobId);
        $this->logger->log('document.zip_export_started', $userId, 'document', null,
            ['job_id' => $jobId, 'count' => count($ids), 'folders' => count($folderIds)],
            $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued']);
    }

    // ───────────────── Chunkovaný upload (obchází PHP post_max_size) ─────────────────

    private function stagingDir(int $sid, int $jobId): string
    {
        return DocumentStorage::baseDir($sid) . '/_jobs/up-' . $jobId;
    }

    /** POST /api/documents/upload/start {mode: zip-explode|folder|single, folder_id, name?} */
    public function uploadStart(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        $userId = $this->userId($request);
        $body = (array) $request->getParsedBody();

        $mode = (string) ($body['mode'] ?? '');
        if (!in_array($mode, ['zip-explode', 'folder', 'single'], true)) {
            return Json::error($response, 'bad_mode', 'Neplatný režim uploadu.', 400);
        }
        $folderId = $this->optInt($body['folder_id'] ?? null);
        if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }

        $source = $mode === 'zip-explode' ? 'document_zip_import' : 'document_folder_import';
        $params = ['folder_id' => $folderId, 'chunked' => true, 'mode' => $mode];
        if ($mode === 'single') {
            $params['single_name'] = mb_substr(trim((string) ($body['name'] ?? 'soubor')), 0, 255) ?: 'soubor';
        }
        $jobId = $this->jobs->create($sid, $source, $params, $userId ?? 0);
        if ($this->jobSourceMissing($jobId, $sid, $source)) {
            return $this->migrationError($response);
        }

        $staging = $this->stagingDir($sid, $jobId);
        if (!is_dir($staging) && !@mkdir($staging, 0755, true) && !is_dir($staging)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
        }
        return Json::ok($response, ['job_id' => $jobId, 'mode' => $mode]);
    }

    /** POST /api/documents/upload/chunk-bytes?job_id=N  (raw octet-stream chunk) — zip-explode/single. */
    public function uploadChunkBytes(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $jobId = (int) ($request->getQueryParams()['job_id'] ?? 0);
        $job = $this->jobs->find($jobId, $sid);
        if ($job === null || ($job['status'] ?? '') !== 'queued' || !in_array($job['source'], self::SOURCES, true)) {
            return Json::error($response, 'bad_job', 'Neplatný nebo již dokončený job.', 409);
        }
        $staging = $this->stagingDir($sid, $jobId);
        if (!is_dir($staging)) return Json::error($response, 'no_staging', 'Staging nenalezen.', 409);

        $blob = $staging . '/blob';
        $data = (string) $request->getBody();
        if ($data !== '') {
            if (@file_put_contents($blob, $data, FILE_APPEND) === false) {
                return Json::error($response, 'write_failed', 'Zápis chunku selhal.', 500);
            }
        }
        if ((int) @filesize($blob) > self::MAX_CHUNKED_BYTES) {
            @unlink($blob);
            return Json::error($response, 'too_large', 'Soubor je příliš velký.', 413);
        }
        return Json::ok($response, ['size' => (int) @filesize($blob)]);
    }

    /** POST /api/documents/upload/chunk-files?job_id=N  (multipart file[]+relpaths[]) — folder. */
    public function uploadChunkFiles(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $jobId = (int) ($request->getQueryParams()['job_id'] ?? 0);
        $job = $this->jobs->find($jobId, $sid);
        if ($job === null || ($job['status'] ?? '') !== 'queued' || $job['source'] !== 'document_folder_import') {
            return Json::error($response, 'bad_job', 'Neplatný nebo již dokončený job.', 409);
        }
        $staging = $this->stagingDir($sid, $jobId);
        if (!is_dir($staging)) return Json::error($response, 'no_staging', 'Staging nenalezen.', 409);

        $body = (array) $request->getParsedBody();
        $relpaths = isset($body['relpaths']) && is_array($body['relpaths']) ? array_values($body['relpaths']) : [];
        $files = $request->getUploadedFiles();
        $list = isset($files['file']) ? (is_array($files['file']) ? array_values($files['file']) : [$files['file']]) : [];

        $added = 0;
        $manifest = $staging . '/manifest.jsonl';
        foreach ($list as $idx => $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) continue;
            $name = trim((string) $file->getClientFilename());
            if ($name === '') continue;
            $part = $staging . '/p' . bin2hex(random_bytes(8));
            try { $file->moveTo($part); } catch (\Throwable) { continue; }
            $line = json_encode([
                'f' => basename($part),
                'n' => $name,
                'p' => isset($relpaths[$idx]) ? (string) $relpaths[$idx] : '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($manifest, $line . "\n", FILE_APPEND);
            $added++;
        }
        return Json::ok($response, ['added' => $added]);
    }

    /** POST /api/documents/upload/finish {job_id} — spustí worker. */
    public function uploadFinish(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $body = (array) $request->getParsedBody();
        $jobId = (int) ($body['job_id'] ?? $request->getQueryParams()['job_id'] ?? 0);
        $job = $this->jobs->find($jobId, $sid);
        if ($job === null || ($job['status'] ?? '') !== 'queued' || !in_array($job['source'], self::SOURCES, true)) {
            return Json::error($response, 'bad_job', 'Neplatný nebo již dokončený job.', 409);
        }
        $staging = $this->stagingDir($sid, $jobId);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];

        // 'single' režim: jediný soubor po bytech → zapiš 1řádkový manifest na blob.
        if (($params['mode'] ?? '') === 'single' && !empty($params['single_name'])
            && is_file($staging . '/blob') && !is_file($staging . '/manifest.jsonl')) {
            @file_put_contents($staging . '/manifest.jsonl', json_encode(
                ['f' => 'blob', 'n' => (string) $params['single_name'], 'p' => ''],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . "\n");
        }

        $this->spawnWorker($jobId);
        $this->logger->log('document.chunked_upload_finished', $this->userId($request), 'document', null,
            ['job_id' => $jobId, 'source' => $job['source']], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued']);
    }

    /**
     * Vlastnická osa jobu (Epic F7, §4.2): job patří svému tvůrci (import_jobs.created_by).
     * Admin tenanta vidí vše; jinak jen vlastní joby. Export ZIP může obsahovat cizí
     * user-scoped doklady (admin balí složku), takže bez téhle brány by kterýkoli
     * non-admin téhož dodavatele stáhl cizí osobní dokumenty přes list/download.
     *
     * @param array<string,mixed> $job
     */
    private function canAccessJob(array $job, Request $request): bool
    {
        $viewer = $this->viewer($request);
        if ($viewer->isAdmin) return true;
        return $viewer->userId !== null && (int) ($job['created_by'] ?? 0) === $viewer->userId;
    }

    /** GET /api/documents/jobs */
    public function list(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $all = $this->jobs->listForTenant($sid, null, 30);
        $jobs = array_values(array_filter(
            $all,
            fn($j) => in_array($j['source'], self::SOURCES, true) && $this->canAccessJob($j, $request),
        ));
        return Json::ok($response, ['jobs' => array_map([$this, 'jobView'], $jobs)]);
    }

    /** GET /api/documents/jobs/{id} */
    public function status(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || !in_array($job['source'], self::SOURCES, true) || !$this->canAccessJob($job, $request)) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        return Json::ok($response, $this->jobView($job));
    }

    /** GET /api/documents/jobs/{id}/download */
    public function download(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || ($job['status'] ?? '') !== 'completed' || empty($job['result_path'])
            || !$this->canAccessJob($job, $request)) {
            return Json::error($response, 'not_found', 'Výsledek není k dispozici.', 404);
        }
        $base = RuntimePaths::storage('documents');
        $abs = $base . '/' . ltrim((string) $job['result_path'], '/\\');
        // Path-traversal guard.
        $real = realpath($abs) ?: '';
        $baseReal = realpath($base) ?: $base;
        $n = static fn(string $p): string => DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('\\', '/', $p)) : $p;
        if ($real === '' || !str_starts_with($n($real) . '/', rtrim($n($baseReal), '/') . '/') || !is_file($real)) {
            return Json::error($response, 'not_found', 'Soubor výsledku nenalezen.', 404);
        }
        $stream = new Stream(fopen($real, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', (string) ($job['result_mime'] ?: 'application/zip'))
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[\r\n"\\\\]/', '_', (string) ($job['result_name'] ?: 'export.zip')) . '"')
            ->withHeader('Content-Length', (string) filesize($real))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    /** POST /api/documents/jobs/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || !in_array($job['source'], self::SOURCES, true) || !$this->canAccessJob($job, $request)) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        $ok = $this->jobs->requestCancel((int) $job['id'], $sid);
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /api/documents/jobs/{id} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || !in_array($job['source'], self::SOURCES, true) || !$this->canAccessJob($job, $request)) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        if (!empty($job['result_path'])) {
            $abs = RuntimePaths::storage('documents') . '/' . ltrim((string) $job['result_path'], '/\\');
            if (is_file($abs)) @unlink($abs);
        }
        $this->jobs->delete((int) $job['id'], $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * Obrana proti chybějící DB migraci: pokud `import_jobs.source` ENUM neobsahuje
     * danou hodnotu, MySQL ji v ne-striktním režimu tiše uloží jako '' (místo chyby).
     * Ověří, že se source skutečně uložil; jinak job smaže a vrátí true (= problém).
     */
    private function jobSourceMissing(int $jobId, int $sid, string $source): bool
    {
        $j = $this->jobs->find($jobId, $sid);
        if ($j !== null && ($j['source'] ?? '') === $source) {
            return false;
        }
        $this->jobs->delete($jobId, $sid);
        return true;
    }

    private function migrationError(Response $response): Response
    {
        return Json::error($response, 'migration_required',
            'Chybí databázová migrace pro tento typ úlohy — spusťte `php api/bin/migrate.php`.', 500);
    }

    private function spawnWorker(int $jobId): void
    {
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    /** @param array<string,mixed> $j */
    private function jobView(array $j): array
    {
        return [
            'id'               => (int) $j['id'],
            'source'           => (string) $j['source'],
            'status'           => (string) $j['status'],
            'total_items'      => $j['total_items'] !== null ? (int) $j['total_items'] : null,
            'processed'        => (int) $j['processed'],
            'created_count'    => (int) $j['created_count'],
            'skipped_count'    => (int) $j['skipped_count'],
            'failed_count'     => (int) $j['failed_count'],
            'current_step'     => $j['current_step'] ?? null,
            'last_error'       => $j['last_error'] ?? null,
            'cancel_requested' => (bool) $j['cancel_requested'],
            'result_name'      => $j['result_name'] ?? null,
            'result_size'      => $j['result_size'] ?? null,
            'created_at'       => (string) ($j['created_at'] ?? ''),
            'finished_at'      => $j['finished_at'] ?? null,
        ];
    }
}
