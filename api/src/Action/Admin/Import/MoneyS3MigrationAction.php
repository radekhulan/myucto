<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Průvodce „Přechod z Money S3" — nahrání zálohy agendy, náhled, volitelné sestavy
 * z Money k rekonciliaci, spuštění zkoušky nanečisto nebo ostrého převodu na pozadí
 * a protokoly.
 *
 *   POST /api/admin/imports/money-s3/uploads                        multipart `backup` (.lz)
 *   GET  /api/admin/imports/money-s3/uploads/{token}
 *   POST /api/admin/imports/money-s3/uploads/{token}/reports        multipart `report` + `year`
 *   POST /api/admin/imports/money-s3/uploads/{token}/start          {mode, close_history, first_period_start}
 *   GET  /api/admin/imports/money-s3/runs
 *   GET  /api/admin/imports/money-s3/runs/{id}
 *
 * Stav běžícího převodu jde přes společné GET /api/admin/imports/{id}.
 * Všechno je vázané na aktuální firmu: nahraná záloha leží pod jejím adresářem
 * a protokoly se čtou se `supplier_id`.
 */
final class MoneyS3MigrationAction
{
    private const MAX_BACKUP_BYTES = 1024 * 1024 * 1024;
    private const MAX_REPORT_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly MoneyS3ImportRepository $runs,
        private readonly MoneyS3Importer $importer,
        private readonly MoneyReportParser $reports,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function upload(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $file = $request->getUploadedFiles()['backup'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte zálohu agendy Money S3 (soubor .lz).', 400);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_BACKUP_BYTES) {
            return Json::error($response, 'upload_too_large', 'Záloha je příliš velká.', 413);
        }

        MoneyS3Uploads::purgeStale($supplierId);
        $token = MoneyS3Uploads::newToken();
        $dir = MoneyS3Uploads::dir($supplierId, $token);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště pro zálohy není zapisovatelné.', 500);
        }
        try {
            $file->moveTo($dir . '/backup.lz');
            $sha = (string) hash_file('sha256', $dir . '/backup.lz');
            $backup = Ms3Backup::extract($dir . '/backup.lz', MoneyS3Uploads::agendaDir($supplierId, $token));
            @unlink($dir . '/backup.lz');
            $agenda = AgendaInfo::fromBackup($backup);
            $preflight = $this->importer->preflight($supplierId, $backup, $agenda, new ImportOptions());
        } catch (MoneyS3Exception $e) {
            MoneyS3Uploads::purge($supplierId, $token);
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
        } catch (\Throwable) {
            MoneyS3Uploads::purge($supplierId, $token);
            return Json::error($response, 'backup_unreadable', 'Zálohu agendy se nepodařilo přečíst.', 422);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $meta = [
            'token' => $token,
            'file_name' => basename((string) ($file->getClientFilename() ?? 'agenda.lz')),
            'sha256' => $sha,
            'uploaded_at' => date('c'),
            'uploaded_by' => (int) ($user['id'] ?? 0),
            'agenda' => $agenda->toArray(),
        ];
        MoneyS3Uploads::writeMeta($supplierId, $token, $meta);
        $this->logger->log('import.money_s3_uploaded', (int) ($user['id'] ?? 0), 'supplier', $supplierId,
            ['agenda_ico' => $agenda->ico, 'version' => $agenda->version, 'years' => $agenda->fiscalYears()],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $meta + ['preflight' => $preflight, 'reports' => []], 201);
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        try {
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $backup = Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            $preflight = $this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions());
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        return Json::ok($response, $meta + [
            'preflight' => $preflight,
            'reports' => array_map('intval', array_keys(MoneyS3Uploads::reports($supplierId, $token))),
        ]);
    }

    /** @param array<string,string> $args */
    public function attachReport(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        try {
            $meta = MoneyS3Uploads::meta($supplierId, $token);
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $year = (int) ($body['year'] ?? 0);
        $years = array_map('intval', array_filter(array_column((array) ($meta['agenda']['years'] ?? []), 'fiscal_year')));
        if (!in_array($year, $years, true)) {
            return Json::error($response, 'invalid_year', 'Rok sestavy musí být jeden z účetních roků zálohy.', 422);
        }
        $file = $request->getUploadedFiles()['report'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte obratovou předvahu z Money (CSV).', 400);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_REPORT_BYTES) {
            return Json::error($response, 'upload_too_large', 'Sestava je příliš velká.', 413);
        }
        $content = (string) $file->getStream();
        $parsed = $this->reports->parse($content);
        if ($parsed['accounts'] === []) {
            return Json::error($response, 'report_unreadable', 'V souboru není obratová předvaha — očekává se CSV s účtem v prvním sloupci.', 422);
        }
        $path = MoneyS3Uploads::reportPath($supplierId, $token, $year);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            return Json::error($response, 'storage_not_writable', 'Úložiště pro zálohy není zapisovatelné.', 500);
        }
        file_put_contents($path, $content);
        return Json::ok($response, ['year' => $year, 'accounts' => count($parsed['accounts']), 'skipped_lines' => $parsed['skipped']], 201);
    }

    /** @param array<string,string> $args */
    public function start(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? ImportOptions::MODE_DRY_RUN);
        $firstPeriodStart = trim((string) ($body['first_period_start'] ?? ''));
        try {
            $options = new ImportOptions(
                $mode,
                filter_var($body['close_history'] ?? true, FILTER_VALIDATE_BOOL),
                $firstPeriodStart !== '' ? $firstPeriodStart : null,
                [],
                [],
                filter_var($body['confirm_ico'] ?? false, FILTER_VALIDATE_BOOL),
            );
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422);
        }
        if (!$options->isDryRun()) {
            $missing = self::missingLiveImportRights($request, $options->closeHistory);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník, mění nastavení firmy a uzavírá roky — chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        try {
            MoneyS3Uploads::meta($supplierId, $token);
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }

        // Živý worker drží zámek firmy i tehdy, když job dlouho nehlásí průběh (zkouška
        // nanečisto) — takový job se za mrtvý považovat nesmí.
        if (!$this->runs->isLockFree($supplierId)) {
            return Json::error($response, 'already_running', 'Převod této firmy právě běží.', 409);
        }
        $this->jobs->reapStale($supplierId, MoneyS3ImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($supplierId, MoneyS3ImportJobService::SOURCE, limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running', "Převod už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, MoneyS3ImportJobService::SOURCE, [
            'token' => $token,
            'mode' => $options->mode,
            'close_history' => $options->closeHistory,
            'first_period_start' => $options->firstPeriodStart,
            'confirm_ico' => $options->confirmedIco,
        ], $userId);
        $stored = $this->jobs->find($jobId, $supplierId);
        if ($stored === null || ($stored['source'] ?? '') !== MoneyS3ImportJobService::SOURCE) {
            $this->jobs->delete($jobId, $supplierId);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro převod z Money S3 — spusťte `php api/bin/migrate.php`.', 500);
        }

        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
        $this->logger->log('import.money_s3_started', $userId, 'import_job', $jobId, $options->toArray(),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $options->mode], 201);
    }

    public function runs(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->runs->listRuns(SupplierGuard::currentId($request))]);
    }

    /** @param array<string,string> $args */
    public function run(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $run = $this->runs->findRun((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        if ($run === null) {
            return Json::error($response, 'not_found', 'Protokol převodu nenalezen.', 404);
        }
        return Json::ok($response, $run);
    }

    /**
     * Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník, přepíná režim
     * účetnictví a automatiku (nastavení firmy) a volitelně uzavírá historické roky.
     * Zkouška nanečisto nic nezanechá, ta stačí s `utilities.import`.
     *
     * @return list<string>
     */
    public static function missingLiveImportRights(Request $request, bool $closeHistory): array
    {
        $required = ['accounting.journal.write', 'settings.company.write'];
        if ($closeHistory) {
            $required[] = 'accounting.periods.close';
        }
        return array_values(array_filter($required, static fn (string $key): bool => !RequestAuthorization::allows($request, $key, AccessLevel::WRITE)));
    }

    private function deny(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'utilities.import', $level)) {
            return Json::error($response, 'forbidden', 'Převod z Money S3 smí spustit jen admin nebo účetní.', 403);
        }
        if (SupplierGuard::currentId($request) === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }
        return null;
    }
}
