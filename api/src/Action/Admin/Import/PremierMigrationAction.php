<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Migration\Premier\PremierImportJobService;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use MyInvoice\Service\Migration\Shared\NonprofitMigrationNotice;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Průvodce „Přechod z PREMIER" - nahrání zálohy dat (`.izip`/`.icab`), náhled, zkouška
 * nanečisto a ostrý převod vybraných roků na pozadí, protokoly.
 *
 *   POST /api/admin/imports/premier/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/premier/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/premier/uploads/{token}/complete
 *   GET  /api/admin/imports/premier/uploads/{token}
 *   POST /api/admin/imports/premier/uploads/{token}/start          {mode, years: int[] (nebo year)}
 *   GET  /api/admin/imports/premier/runs
 *   GET  /api/admin/imports/premier/runs/{id}
 *   DELETE /api/admin/imports/premier/runs/{id}                    jen zkouška nanečisto
 *
 * Záloha se pořizuje v PREMIER („Správce → Záloha dat") a nahrává po částech jako export
 * POHODY/Money S3 ({@see AbstractMigrationAction}). Na rozdíl od POHODY nese jedna záloha
 * VŠECHNY účetní roky, takže se po ostrém převodu nemaže - uživatel v ní postupně převádí
 * rok po roku. Stav běžícího převodu jde přes společné GET /api/admin/imports/{id}.
 */
final class PremierMigrationAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = PremierImportJobService::class;
    protected const EXCEPTION_CLASS = PremierException::class;
    protected const ALLOWED_EXTENSIONS = ['izip', 'icab', 'zip'];
    protected const MAX_BYTES = MigrationUploadLimits::PREMIER_MAX_BYTES;

    protected const TEXT_DENIED = 'Převod z PREMIER smí spustit jen admin nebo účetní.';
    protected const TEXT_INVALID_FILE_TYPE = 'Nahrajte zálohu dat z PREMIER (soubor .izip nebo .icab).';
    protected const TEXT_INVALID_SIZE = 'Chybí velikost zálohy.';
    protected const TEXT_TOO_LARGE = 'Záloha je příliš velká (nejvýš 2 GB).';
    protected const TEXT_TOO_MANY_UPLOADS = 'Firma má rozpracovaných příliš mnoho záloh, počkejte na dokončení běžícího zpracování.';
    protected const TEXT_CHUNK_MISSING = 'Chybí část zálohy.';
    protected const TEXT_OFFSET_MISSING = 'Chybí pozice části zálohy.';
    protected const TEXT_ALREADY_PROCESSING = 'Jiná záloha PREMIER se právě zpracovává, počkejte na jeho dokončení.';
    protected const TEXT_UPLOAD_FAILED = 'Zálohu PREMIER se nepodařilo načíst.';
    protected const TEXT_UPLOAD_INCOMPLETE = 'Záloha ještě není nahraná celá.';
    protected const TEXT_MIGRATION_REQUIRED = 'Chybí databázová migrace pro převod z PREMIER - spusťte `php api/bin/migrate.php`.';
    protected const RUN_ENTITY = 'premier_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.premier_dry_run_deleted';

    public function __construct(
        ImportJobRepository $jobs,
        PremierImportRepository $runs,
        private readonly PremierImporter $importer,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return PremierUploads::store();
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
            $meta = PremierUploads::meta($supplierId, $token);
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        $preflight = [];
        $defaultYear = null;
        // Roky deníku a poslední měsíc mezd má přehled nahrání v `meta.json`. Kontrola
        // každého roku by jinak znovu přečetla celý deník i mzdy zálohy (u zálohy o pár
        // letech desítky sekund, přes timeout webserveru).
        $backup = null;
        $open = function () use (&$backup, $supplierId, $token, $meta): PremierBackup {
            return $backup ??= PremierBackup::open(PremierUploads::backupDir($supplierId, $token))->withOverview((array) $meta['agendas']);
        };
        // Roky průvodce = účetní roky firmy (u hospodářského roku s hranicemi období).
        try {
            $meta['agendas'] = $this->importer->companyAgendas($supplierId, (array) ($meta['agendas'] ?? []), $supplierIco, $open);
        } catch (\Throwable $e) {
            if (!$e instanceof PremierException) {
                error_log(sprintf('PREMIER: účetní roky zálohy %s firmy %d selhaly: %s', $token, $supplierId, (string) $e));
            }
            $meta['agendas'] = PremierBackup::withoutMonths((array) ($meta['agendas'] ?? []));
        }
        foreach ($meta['agendas'] as $agenda) {
            if ($supplierIco === '' || (string) $agenda['ico'] !== $supplierIco) {
                continue;
            }
            $year = (int) $agenda['year'];
            $defaultYear = max($defaultYear ?? 0, $year);
            try {
                $preflight[(string) $year] = $this->importer->preflight($supplierId, $open(), $year);
            } catch (\Throwable $e) {
                if (!$e instanceof PremierException) {
                    error_log(sprintf('PREMIER: náhled zálohy %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                }
                $preflight[(string) $year] = [['level' => 'error', 'code' => 'backup_unreadable',
                    'message' => $e instanceof PremierException ? $e->getMessage() : 'Zálohu nejde přečíst.', 'context' => []]];
            }
        }
        return Json::ok($response, NonprofitMigrationNotice::attach($this->db, $supplierId, $meta + [
            'status' => PremierUploads::STATUS_READY,
            'supplier_ico' => $supplierIco,
            'default_year' => $defaultYear,
            'preflight' => (object) $preflight,
        ]));
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
        $mode = (string) ($body['mode'] ?? 'dry_run');
        if (!in_array($mode, ['dry_run', 'import'], true)) {
            return Json::error($response, 'invalid_mode', 'Neznámý režim převodu.', 422);
        }
        if (!ImportYears::validBody($body)) {
            return Json::error($response, 'invalid_year', 'Roky převodu musí být seznam roků.', 422);
        }
        $acceptDifferences = self::acceptDifferences($body, $mode);
        if ($acceptDifferences === null) {
            return self::invalidAcceptDifferences($response);
        }
        $years = ImportYears::fromParams($body);
        if ($years === []) {
            return Json::error($response, 'invalid_year', 'Vyberte aspoň jeden rok převodu.', 422);
        }
        if ($mode === 'import') {
            $missing = self::missingLiveImportRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník a mění nastavení firmy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        try {
            $meta = PremierUploads::meta($supplierId, $token);
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        try {
            // Rok výběru = účetní rok firmy, stejně jako v náhledu průvodce a v převodu.
            $agendas = ['agendas' => $this->importer->companyAgendas($supplierId, (array) ($meta['agendas'] ?? []), $supplierIco,
                static fn (): PremierBackup => PremierBackup::open(PremierUploads::backupDir($supplierId, $token))->withOverview((array) $meta['agendas']))];
        } catch (PremierException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422);
        }
        foreach ($years as $year) {
            if (PremierImportJobService::agenda($agendas, $supplierIco, $year) === null) {
                return Json::error($response, 'invalid_year', "Záloha neobsahuje účetní rok {$year} s IČO této firmy.", 422, ['year' => $year]);
            }
        }
        PremierUploads::touch($supplierId, $token);
        $running = $this->alreadyRunning($response, $supplierId);
        if ($running !== null) {
            return $running;
        }

        $userId = self::userId($request);
        $jobId = $this->createRunJob($response, $supplierId, [
            'token' => $token,
            'mode' => $mode,
            // Vybrané roky vzestupně; `year` = první z nich pro starší čtení parametrů jobu.
            'years' => $years,
            'year' => $years[0],
            'ico' => $supplierIco,
            // Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto.
            'accept_differences' => $acceptDifferences,
        ], $userId);
        if ($jobId instanceof Response) {
            return $jobId;
        }
        $this->spawnWorker($jobId);
        $this->logger->log('import.premier_started', $userId, 'import_job', $jobId, ['mode' => $mode, 'years' => $years] + ($acceptDifferences ? ['accept_differences' => true] : []),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $mode], 201);
    }

    /**
     * Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník a přepíná režim
     * účetnictví a automatiku. Zkouška nanečisto stačí s `utilities.import`.
     *
     * @return list<string>
     */
    public static function missingLiveImportRights(Request $request): array
    {
        return self::missingRights($request, self::LIVE_IMPORT_RIGHTS);
    }

    private function supplierIco(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return PartnerImporter::ico((string) $stmt->fetchColumn());
    }
}
