<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MigrationBatchRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\MoneyS3\CodebookImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchUploads;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationCompanyResolver;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Průvodce „Přechod z Money S3", režim Dávka: více záloh (firem) najednou pro účetní
 * kancelář. Zálohy se nahrávají po částech jako u jedné firmy, dávka pak běží jedním
 * jobem na pozadí ({@see MoneyS3BatchJobService}) a protokol dávky je výpis jejích
 * položek (firem).
 *
 *   GET    /api/admin/imports/money-s3/batch/uploads                 zálohy a podaná přiznání dávky
 *   POST   /api/admin/imports/money-s3/batch/uploads/chunked         {file_name, size}
 *   POST   /api/admin/imports/money-s3/batch/uploads/{token}/chunks  multipart `chunk` + `offset`
 *   POST   /api/admin/imports/money-s3/batch/uploads/{token}/complete
 *   DELETE /api/admin/imports/money-s3/batch/uploads/{token}
 *   POST   /api/admin/imports/money-s3/batch/filings                 multipart `filing` (EPO XML DPPDP9)
 *   DELETE /api/admin/imports/money-s3/batch/filings/{id}
 *   POST   /api/admin/imports/money-s3/batch/start                   {tokens, filing_ids, mode, from_year, existing, …}
 *   GET    /api/admin/imports/money-s3/batch/jobs
 *   GET    /api/admin/imports/money-s3/batch/jobs/{id}
 *
 * Zálohy i dávky jsou vázané na aktuální firmu (kancelář, ze které účetní převádí);
 * převáděné firmy dávka najde podle IČO mezi firmami, ke kterým má uživatel přístup,
 * nebo je založí.
 */
final class MoneyS3BatchAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = MoneyS3BatchJobService::class;
    protected const EXCEPTION_CLASS = MoneyS3Exception::class;
    protected const ALLOWED_EXTENSIONS = ['lz', 'zip'];
    protected const MAX_BYTES = MigrationUploadLimits::MONEY_S3_MAX_BYTES;
    protected const MAX_ACTIVE_UPLOADS = MoneyS3BatchUploads::MAX_ACTIVE_UPLOADS;

    protected const TEXT_DENIED = 'Převod z Money S3 smí spustit jen admin nebo účetní.';
    protected const TEXT_INVALID_FILE_TYPE = 'Nahrajte zálohu agendy Money S3 (soubor .lz).';
    protected const TEXT_INVALID_SIZE = 'Chybí velikost zálohy.';
    protected const TEXT_TOO_LARGE = 'Záloha je příliš velká (nejvýš 4 GB).';
    protected const TEXT_TOO_MANY_UPLOADS = 'Dávka má příliš mnoho rozpracovaných záloh, počkejte na dokončení běžícího zpracování.';
    protected const TEXT_CHUNK_MISSING = 'Chybí část zálohy.';
    protected const TEXT_OFFSET_MISSING = 'Chybí pozice části zálohy.';
    protected const TEXT_ALREADY_PROCESSING = 'Jiná záloha dávky se právě zpracovává, počkejte na její dokončení.';
    protected const TEXT_UPLOAD_FAILED = 'Zálohu agendy se nepodařilo načíst.';
    protected const TEXT_UPLOAD_INCOMPLETE = 'Záloha ještě není nahraná celá.';
    protected const TEXT_MIGRATION_REQUIRED = 'Chybí databázová migrace pro dávkový převod z Money S3 — spusťte `php api/bin/migrate.php`.';
    protected const RUN_ENTITY = 'money_s3_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.money_s3_dry_run_deleted';

    public function __construct(
        ImportJobRepository $jobs,
        MoneyS3ImportRepository $runs,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
        private readonly MigrationBatchRepository $batches,
        private readonly MigrationCompanyResolver $companies,
        private readonly UserSupplierRepository $userSuppliers,
        private readonly Connection $db,
        private readonly \MyInvoice\Service\Tenant\SupplierAccessResolver $supplierAccess,
    ) {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return MoneyS3BatchUploads::store();
    }

    /** Zálohy dávky (připravené s přehledem agendy a firmou v MyÚčtu, rozpracované se stavem) a podaná přiznání. */
    public function listUploads(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $allowed = $this->allowedSupplierIds($request);
        $items = [];
        foreach (MoneyS3BatchUploads::listUploads($supplierId) as $u) {
            if (($u['status'] ?? '') !== ChunkedUploadStore::STATUS_READY) {
                $state = MoneyS3BatchUploads::store()->state($supplierId, (string) $u['token']);
                $items[] = $state !== null ? $this->pendingView($supplierId, (string) $u['token'], $state) : $u;
                continue;
            }
            $ico = CodebookImporter::ico((string) ($u['agenda']['ico'] ?? ''));
            $existing = $ico !== '' ? $this->companies->findExisting($ico, $allowed) : ['access' => MigrationCompanyResolver::ACCESS_NONE, 'supplier_id' => null];
            $items[] = $u + ['company' => $existing + ['name' => $existing['supplier_id'] !== null ? $this->companyName((int) $existing['supplier_id']) : null]];
        }
        $filings = array_map(static fn (array $f): array => ['id' => $f['id'], 'file_name' => $f['file_name']] + $f['filing']->toArray(),
            MoneyS3BatchUploads::filings($supplierId));
        return Json::ok($response, [
            'items' => $items,
            'filings' => $filings,
            'can_create_companies' => RequestAuthorization::canCreateSupplier($request),
            'current_group_id' => $this->currentGroupId($supplierId),
        ]);
    }

    /** @param array<string,string> $args */
    public function deleteUpload(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $store = MoneyS3BatchUploads::store();
        if (!$store->isValid($supplierId, $token) || !is_dir($store->dir($supplierId, $token))) {
            return Json::error($response, 'upload_not_found', 'Nahraná záloha nebyla nalezena.', 404);
        }
        $lock = $store->acquireJobLock($supplierId, $token);
        if ($lock === null) {
            return Json::error($response, 'upload_busy', 'Se zálohou právě pracuje převod nebo její zpracování.', 409);
        }
        $store->releaseJobLock($lock);
        $store->purge($supplierId, $token);
        return Json::ok($response, ['ok' => true]);
    }

    public function uploadFiling(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $file = $request->getUploadedFiles()['filing'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte podané přiznání k DPPO (EPO XML).', 400);
        }
        if ((int) ($file->getSize() ?? 0) > MoneyS3BatchUploads::MAX_FILING_BYTES) {
            return Json::error($response, 'upload_too_large', 'Soubor je na podané přiznání příliš velký.', 413);
        }
        try {
            $saved = MoneyS3BatchUploads::saveFiling($supplierId, (string) $file->getStream(), (string) ($file->getClientFilename() ?? 'dppo.xml'));
        } catch (TaxReturnException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422);
        } catch (MoneyS3Exception $e) {
            return $this->sourceError($response, $e);
        }
        return Json::ok($response, ['id' => $saved['id'], 'file_name' => basename((string) ($file->getClientFilename() ?? ''))] + $saved['filing']->toArray(), 201);
    }

    /** @param array<string,string> $args */
    public function deleteFiling(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        if (!MoneyS3BatchUploads::deleteFiling(SupplierGuard::currentId($request), (string) ($args['id'] ?? ''))) {
            return Json::error($response, 'not_found', 'Podané přiznání nebylo nalezeno.', 404);
        }
        return Json::ok($response, ['ok' => true]);
    }

    public function start(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $groupMode = (string) ($body['group_mode'] ?? 'none');
        try {
            $options = MoneyS3BatchOptions::fromArray([
                'group_id' => $groupMode === 'current' ? $this->currentGroupId($supplierId) : null,
                'group_name' => $groupMode === 'new' ? (string) ($body['group_name'] ?? '') : null,
                'related_party_icos' => [],
            ] + array_intersect_key($body, array_flip(['mode', 'from_year', 'existing', 'close_history', 'disposal_year_tax', 'related_parties', 'use_registry', 'take_over_filings'])));
        } catch (MoneyS3Exception $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422);
        }
        if ($groupMode === 'new' && $options->groupName === null) {
            return Json::error($response, 'group_name_missing', 'Zadejte název nové skupiny firem.', 422);
        }
        if ($groupMode === 'current' && $options->groupId === null) {
            return Json::error($response, 'no_group', 'Aktuální firma nepatří do žádné skupiny firem.', 422);
        }

        $tokens = array_values(array_unique(array_map('strval', (array) ($body['tokens'] ?? []))));
        if ($tokens === []) {
            return Json::error($response, 'no_backups', 'Vyberte aspoň jednu zálohu.', 422);
        }
        $backups = [];
        foreach ($tokens as $token) {
            try {
                $meta = MoneyS3BatchUploads::store()->meta($supplierId, $token);
            } catch (MoneyS3Exception $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), 404, ['token' => $token]);
            }
            $backups[] = [
                'token' => $token,
                'file_name' => (string) ($meta['file_name'] ?? ''),
                'agenda_ico' => (string) ($meta['agenda']['ico'] ?? ''),
                'agenda_name' => (string) ($meta['agenda']['name'] ?? ''),
                'ico' => (string) ($meta['agenda']['ico'] ?? ''),
                'backup_at' => (string) ($meta['agenda']['backup_at'] ?? ''),
                'mtime' => strtotime((string) ($meta['uploaded_at'] ?? '')) ?: 0,
            ];
        }
        // Z více záloh téže firmy platí nejnovější.
        $selection = MoneyS3BatchImporter::latestPerIco($backups);
        $items = array_map(static fn (int $i): array => $backups[$i], $selection['keep']);
        $superseded = array_map(static fn (int $i): string => $backups[$i]['token'], $selection['superseded']);

        $allowed = $this->allowedSupplierIds($request);
        $needsCreate = false;
        foreach ($items as $item) {
            $existing = $this->companies->findExisting($item['ico'], $allowed);
            $needsCreate = $needsCreate || $existing['access'] === MigrationCompanyResolver::ACCESS_NONE;
        }
        $canCreate = RequestAuthorization::canCreateSupplier($request);
        if ($needsCreate && !$canCreate && !$options->isDryRun()) {
            return Json::error($response, 'forbidden_permission', 'Dávka by zakládala firmy, které v MyÚčtu nejsou, a zakládat firmy nemáte oprávnění.', 403);
        }
        if (!$options->isDryRun()) {
            $missing = MoneyS3MigrationAction::missingLiveImportRights($request, $options->closeHistory);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník, mění nastavení firem a uzavírá roky — chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        $running = $this->alreadyRunning($response, $supplierId);
        if ($running !== null) {
            return $running;
        }

        $userId = self::userId($request);
        $actor = new MigrationBatchActor($userId, $allowed, $canCreate, !RequestAuthorization::isSuperadmin($request));
        $filingIds = array_values(array_filter(array_map('strval', (array) ($body['filing_ids'] ?? [])), static fn (string $id): bool => preg_match('/^[a-f0-9]{40}$/', $id) === 1));
        $jobId = $this->createRunJob($response, $supplierId, [
            'options' => $options->toArray(),
            'actor' => $actor->toArray(),
            'filing_ids' => $filingIds,
        ], $userId);
        if ($jobId instanceof Response) {
            return $jobId;
        }
        $this->batches->createItems($supplierId, $jobId, MoneyS3BatchJobService::ITEM_SOURCE, $items);
        foreach ($items as $item) {
            MoneyS3BatchUploads::store()->touch($supplierId, $item['token']);
        }

        $this->spawnWorker($jobId);
        $this->logger->log('import.money_s3_batch_started', $userId, 'import_job', $jobId,
            $options->toArray() + ['companies' => count($items), 'filings' => count($filingIds)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $options->mode, 'companies' => count($items), 'superseded' => $superseded], 201);
    }

    public function jobs(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $out = [];
        foreach ($this->jobs->listForTenant($supplierId, MoneyS3BatchJobService::SOURCE, limit: 20) as $job) {
            if (MoneyS3BatchJobService::isPrepareJob($job)) {
                continue;
            }
            $out[] = $this->jobView($job, $supplierId, false);
        }
        return Json::ok($response, ['items' => $out]);
    }

    /** @param array<string,string> $args */
    public function job(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $supplierId);
        if ($job === null || ($job['source'] ?? '') !== MoneyS3BatchJobService::SOURCE || MoneyS3BatchJobService::isPrepareJob($job)) {
            return Json::error($response, 'not_found', 'Dávka nenalezena.', 404);
        }
        return Json::ok($response, $this->jobView($job, $supplierId, true));
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function jobView(array $job, int $supplierId, bool $withProtocols): array
    {
        $items = $this->batches->items((int) $job['id'], $supplierId);
        foreach ($items as &$item) {
            if (!$withProtocols && is_array($item['summary'])) {
                unset($item['summary']['protocol']);
            }
            $item['target_name'] = $item['target_supplier_id'] !== null ? $this->companyName((int) $item['target_supplier_id']) : null;
        }
        unset($item);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        return [
            'id' => (int) $job['id'],
            'status' => (string) $job['status'],
            'created_at' => $job['created_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'current_step' => $job['current_step'] ?? null,
            'total_items' => (int) ($job['total_items'] ?? 0),
            'processed' => (int) ($job['processed'] ?? 0),
            'last_error' => $job['last_error'] ?? null,
            'options' => (array) ($params['options'] ?? []),
            'items' => $items,
        ];
    }

    /** @return list<int>|null firmy, ke kterým má uživatel přístup (null = všechny) */
    private function allowedSupplierIds(Request $request): ?array
    {
        $all = RequestAuthorization::isSuperadmin($request);
        $ids = $this->userSuppliers->allowedSupplierIds(self::userId($request));
        if ($all) $ids = array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $allowed = $this->supplierAccess->filterAllowedSupplierIds($request, $ids);
        return $all && $allowed === $ids ? null : $allowed;
    }

    private function currentGroupId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_group_id FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int) $id : null;
    }

    private function companyName(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT COALESCE(NULLIF(display_name, ''), company_name) FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string) $name : null;
    }
}
