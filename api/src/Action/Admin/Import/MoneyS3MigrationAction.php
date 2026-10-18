<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Průvodce „Přechod z Money S3" — nahrání zálohy agendy, náhled, volitelné sestavy
 * z Money k rekonciliaci, spuštění zkoušky nanečisto nebo ostrého převodu na pozadí
 * a protokoly.
 *
 *   POST /api/admin/imports/money-s3/uploads                        multipart `backup` (.lz)
 *   POST /api/admin/imports/money-s3/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/money-s3/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/money-s3/uploads/{token}/complete
 *   GET  /api/admin/imports/money-s3/uploads/{token}
 *   POST /api/admin/imports/money-s3/uploads/{token}/reports        multipart `report` + `year`
 *   POST /api/admin/imports/money-s3/uploads/{token}/start          {mode, close_history, first_period_start, from_year, disposal_year_tax}
 *   GET  /api/admin/imports/money-s3/runs
 *   GET  /api/admin/imports/money-s3/runs/{id}
 *   DELETE /api/admin/imports/money-s3/runs/{id}                    jen zkouška nanečisto
 *
 * Nahrávání po částech, stav, protokoly a práva jsou společné s ostatními převody
 * ({@see AbstractMigrationAction}). Stav běžícího převodu jde přes společné
 * GET /api/admin/imports/{id}.
 */
final class MoneyS3MigrationAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = MoneyS3ImportJobService::class;
    protected const EXCEPTION_CLASS = MoneyS3Exception::class;
    protected const ALLOWED_EXTENSIONS = ['lz', 'zip'];
    protected const MAX_BYTES = MigrationUploadLimits::MONEY_S3_MAX_BYTES;

    protected const TEXT_DENIED = 'Převod z Money S3 smí spustit jen admin nebo účetní.';
    protected const TEXT_INVALID_FILE_TYPE = 'Nahrajte zálohu agendy Money S3 (soubor .lz).';
    protected const TEXT_INVALID_SIZE = 'Chybí velikost zálohy.';
    protected const TEXT_TOO_LARGE = 'Záloha je příliš velká (nejvýš 4 GB).';
    protected const TEXT_TOO_MANY_UPLOADS = 'Firma má rozpracovaných příliš mnoho záloh, počkejte na dokončení běžícího zpracování.';
    protected const TEXT_CHUNK_MISSING = 'Chybí část zálohy.';
    protected const TEXT_OFFSET_MISSING = 'Chybí pozice části zálohy.';
    protected const TEXT_ALREADY_PROCESSING = 'Jiná záloha Money S3 se právě zpracovává, počkejte na její dokončení.';
    protected const TEXT_UPLOAD_FAILED = 'Zálohu agendy se nepodařilo načíst.';
    protected const TEXT_UPLOAD_INCOMPLETE = 'Záloha ještě není nahraná celá.';
    protected const TEXT_MIGRATION_REQUIRED = 'Chybí databázová migrace pro převod z Money S3 — spusťte `php api/bin/migrate.php`.';
    protected const RUN_ENTITY = 'money_s3_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.money_s3_dry_run_deleted';

    public function __construct(
        ImportJobRepository $jobs,
        MoneyS3ImportRepository $runs,
        private readonly MoneyS3Importer $importer,
        private readonly MoneyReportParser $reports,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
    ) {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return MoneyS3Uploads::store();
    }

    public function upload(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $file = $request->getUploadedFiles()['backup'] ?? null;
        $tooLarge = $file instanceof UploadedFileInterface
            ? in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            // Tělo nad post_max_size PHP zahodí celé — požadavek pak nemá žádný soubor.
            : $request->getUploadedFiles() === [] && (int) $request->getHeaderLine('Content-Length') > 0;
        if ($tooLarge) {
            return Json::error($response, 'upload_too_large',
                'Záloha je větší, než server přijme jedním požadavkem. Nahrajte ji průvodcem, který ji posílá po částech.', 413);
        }
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte zálohu agendy Money S3 (soubor .lz).', 400);
        }
        if ((int) ($file->getSize() ?? 0) > static::MAX_BYTES) {
            return Json::error($response, 'upload_too_large', 'Záloha je příliš velká.', 413);
        }

        MoneyS3Uploads::purgeStale($supplierId);
        if (!MoneyS3Uploads::makeRoom($supplierId, static::MAX_ACTIVE_UPLOADS)) {
            return Json::error($response, 'too_many_uploads', static::TEXT_TOO_MANY_UPLOADS, 429);
        }
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
        } catch (\Throwable $e) {
            error_log(sprintf('Money S3: načtení zálohy firmy %d selhalo: %s', $supplierId, (string) $e));
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
            $pending = $this->pendingUpload($supplierId, $token);
            if ($pending !== null) {
                return Json::ok($response, $pending);
            }
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $backup = Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            $meta = $this->currentPreview($supplierId, $token, $meta, $backup);
            $preflight = $this->importer->preflight($supplierId, $backup, AgendaInfo::fromArray((array) $meta['agenda']), new ImportOptions(),
                (array) $meta['journal_preview']);
            // Roky průvodce (tabulka let, „Převést od roku", předvahy) = účetní roky firmy.
            $meta['agenda'] = MoneyS3Importer::companyAgenda((array) $meta['agenda'], (array) $meta['journal_preview']);
            unset($meta['journal_preview']);
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        return Json::ok($response, $meta + [
            'status' => MoneyS3Uploads::STATUS_READY,
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
        // Rok předvahy = účetní rok firmy, pod kterým ji rekonciliace převodu hledá.
        try {
            $meta = $this->currentPreview($supplierId, $token, $meta);
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $agenda = MoneyS3Importer::companyAgenda((array) $meta['agenda'], (array) $meta['journal_preview']);
        // Obratová předvaha je sestava podvojného účetnictví; daňovou evidenci rekonciliace
        // porovnává s peněžním deníkem ze zálohy.
        $years = array_map('intval', array_column(array_filter((array) ($agenda['years'] ?? []),
            static fn (mixed $y): bool => is_array($y) && ($y['fiscal_year'] ?? null) !== null && ($y['accounting_mode'] ?? null) !== 'tax_evidence'), 'fiscal_year'));
        if (!in_array($year, $years, true)) {
            return Json::error($response, 'invalid_year', 'Rok sestavy musí být jeden z účetních roků zálohy.', 422);
        }
        $file = $request->getUploadedFiles()['report'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte obratovou předvahu z Money (CSV).', 400);
        }
        if ((int) ($file->getSize() ?? 0) > MigrationUploadLimits::MONEY_S3_MAX_REPORT_BYTES) {
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
        $fromYear = (int) ($body['from_year'] ?? 0);
        $acceptDifferences = self::acceptDifferences($body, $mode);
        if ($acceptDifferences === null) {
            return self::invalidAcceptDifferences($response);
        }
        try {
            $options = new ImportOptions(
                $mode,
                filter_var($body['close_history'] ?? true, FILTER_VALIDATE_BOOL),
                $firstPeriodStart !== '' ? $firstPeriodStart : null,
                [],
                [],
                filter_var($body['confirm_ico'] ?? false, FILTER_VALIDATE_BOOL),
                $fromYear > 0 ? $fromYear : null,
                (string) ($body['disposal_year_tax'] ?? ImportOptions::DISPOSAL_YEAR_TAX_HALF),
                $acceptDifferences,
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

        $running = $this->alreadyRunning($response, $supplierId);
        if ($running !== null) {
            return $running;
        }

        $userId = self::userId($request);
        $jobId = $this->createRunJob($response, $supplierId, [
            'token' => $token,
            'mode' => $options->mode,
            'close_history' => $options->closeHistory,
            'first_period_start' => $options->firstPeriodStart,
            'confirm_ico' => $options->confirmedIco,
            'from_year' => $options->fromYear,
            'disposal_year_tax' => $options->disposalYearTax,
            // Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto.
            'accept_differences' => $options->acceptDifferences,
        ], $userId);
        if ($jobId instanceof Response) {
            return $jobId;
        }

        $this->spawnWorker($jobId);
        $this->logger->log('import.money_s3_started', $userId, 'import_job', $jobId, $options->toArray(),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $options->mode], 201);
    }

    /**
     * Náhled agendy a rozvrh deníku spočítal job nahrání do `meta.json`. Znovu je číst ze
     * zálohy znamenalo projít deník každého roku čtyřikrát (u gigabajtové agendy přes deset
     * sekund při každém otevření průvodce). Nahrání před jejich zavedením je nemá a rozvrh
     * spočítaný v jiném kalendáři účetního roku firmy neplatí: dočtou se jednou a uloží.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     * @throws MoneyS3Exception
     */
    private function currentPreview(int $supplierId, string $token, array $meta, ?Ms3Backup $backup = null): array
    {
        if (!is_array($meta['agenda'] ?? null) || !$this->importer->journalPreviewCurrent($meta['journal_preview'] ?? null, $supplierId)) {
            $backup ??= Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            if (!is_array($meta['agenda'] ?? null) || !MoneyS3Importer::journalPreviewComplete($meta['journal_preview'] ?? null)) {
                $meta['agenda'] = AgendaInfo::fromBackup($backup)->toArray();
            }
            $meta['journal_preview'] = $this->importer->journalPreview($backup, $supplierId);
            MoneyS3Uploads::writeMeta($supplierId, $token, $meta);
        }
        return $meta;
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
        $required = self::LIVE_IMPORT_RIGHTS;
        if ($closeHistory) {
            $required[] = 'accounting.periods.close';
        }
        return self::missingRights($request, $required);
    }
}
