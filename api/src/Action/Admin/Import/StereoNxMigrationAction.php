<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Migration\StereoNx\StereoNxCompanyCompatibility;
use MyInvoice\Service\Migration\StereoNx\StereoNxCompanyProfile;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingService;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter;
use MyInvoice\Service\Migration\Shared\NonprofitMigrationNotice;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService;
use MyInvoice\Service\Migration\StereoNx\StereoNxPartnerCountries;
use MyInvoice\Service\Migration\StereoNx\StereoNxRunReportStore;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use MyInvoice\Service\Pdf\StereoNxProtocolPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Nahrání šifrované zálohy, výběr firmy, kontrolní běh a následný převod. Zkouška
 * nanečisto i převod běží na pozadí jako job ({@see StereoNxImportJobService}).
 *
 *   GET    /api/admin/imports/stereo-nx/uploads
 *   POST   /api/admin/imports/stereo-nx/uploads/chunked                    {file_name, size}
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/chunks             multipart `chunk` + `offset`
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/complete
 *   GET    /api/admin/imports/stereo-nx/uploads/{token}
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/preview            {password?}
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/company-profile    {company, fields, expected_values}
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/partner-countries  {company, blank_country_is_cz?, match_bank_account_series?, partner_countries?, password?}
 *   POST   /api/admin/imports/stereo-nx/uploads/{token}/run                {company, mode, blank_country_is_cz, match_bank_account_series?, partner_countries?, password?} → {job_id}
 *   GET    /api/admin/imports/stereo-nx/uploads/{token}/runs/{jobId}       výsledek doběhlého jobu
 *   GET    /api/admin/imports/stereo-nx/uploads/{token}/runs/{jobId}/pdf   protokol ke stažení
 *   DELETE /api/admin/imports/stereo-nx/uploads/{token}
 */
final class StereoNxMigrationAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly SecretEncryption $secrets,
        private readonly LoggerInterface $log,
        private readonly SettingsAction $settings,
        private readonly StereoNxRunReportStore $runReports,
        private readonly StereoNxAccountMappingService $accountMapping,
        private readonly StereoNxProtocolPdfRenderer $protocolPdf,
        private readonly StereoNxAccountingDocuments $accountingDocuments,
        private readonly StereoNxAccountingWriter $accountingJournal,
    ) {}

    public function init(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $name = basename(str_replace('\\', '/', (string) ($body['file_name'] ?? '')));
        $size = filter_var($body['size'] ?? null, FILTER_VALIDATE_INT);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip' || !is_int($size) || $size < 1) {
            return Json::error($response, 'invalid_backup', 'Vyberte zálohu Stereo NX ve formátu ZIP.', 422);
        }
        if ($size > StereoNxUploads::MAX_BYTES) {
            return Json::error($response, 'upload_too_large', 'Záloha přesahuje limit 2 GB.', 413);
        }
        $sid = SupplierGuard::currentId($request);
        if (!StereoNxUploads::prepareRoom($sid)) {
            return Json::error($response, 'too_many_uploads', 'Firma už má tři nahrané zálohy; některou odstraňte.', 429);
        }
        $token = StereoNxUploads::token();
        $dir = StereoNxUploads::dir($sid, $token);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return Json::error($response, 'storage_error', 'Úložiště záloh není dostupné.', 500);
        }
        StereoNxUploads::save($sid, $token, [
            'status' => 'uploading', 'size' => $size, 'received' => 0,
            'file_name' => mb_substr($name, 0, 200), 'uploaded_by' => $this->userId($request),
            'created_at' => time(),
        ]);
        return Json::ok($response, ['token' => $token, 'chunk_size' => StereoNxUploads::CHUNK_BYTES], 201);
    }

    public function index(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        return Json::ok($response, ['uploads' => StereoNxUploads::listForUser(
            SupplierGuard::currentId($request), $this->userId($request),
        )]);
    }

    /** @param array<string,string> $args */
    public function chunk(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $offset = filter_var($body['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $file = $request->getUploadedFiles()['chunk'] ?? null;
        if (!is_int($offset) || !$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'invalid_chunk', 'Chybí platná část zálohy.', 422);
        }
        try {
            $sid = SupplierGuard::currentId($request);
            $this->owner($sid, $args['token'], $this->userId($request));
            return Json::ok($response, ['received' => StereoNxUploads::append($sid, $args['token'], $offset, $file->getStream())]);
        } catch (StereoNxException $e) {
            if ($e->errorCode === 'chunk_offset_mismatch') {
                $path = StereoNxUploads::archive(SupplierGuard::currentId($request), $args['token']);
                clearstatcache(true, $path);
                return Json::error($response, $e->errorCode, $e->getMessage(), 409,
                    ['received' => is_file($path) ? (int) filesize($path) : 0]);
            }
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function complete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $this->owner($sid, $args['token'], $this->userId($request));
            return StereoNxUploads::locked($sid, $args['token'], static function (array $state) use ($sid, $args, $response): Response {
                if (($state['status'] ?? '') === 'ready') return Json::ok($response, ['token' => $args['token'], 'job_id' => null]);
                $path = StereoNxUploads::archive($sid, $args['token']);
                clearstatcache(true, $path);
                if (($state['status'] ?? '') !== 'uploading' || !is_file($path) || filesize($path) !== (int) $state['size']) {
                    throw new StereoNxException('upload_incomplete', 'Záloha ještě není nahraná celá.');
                }
                $state['status'] = 'ready';
                $state['sha256'] = hash_file('sha256', $path);
                StereoNxUploads::save($sid, $args['token'], $state);
                return Json::ok($response, ['token' => $args['token'], 'job_id' => null]);
            });
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        try {
            $state = $this->owner(SupplierGuard::currentId($request), $args['token'], $this->userId($request));
            return Json::ok($response, [
                'token' => $args['token'], 'status' => $state['status'], 'file_name' => $state['file_name'],
                'size' => $state['size'], 'received' => $state['received'],
                'dry_run_company' => $state['dry_run_company'] ?? null,
            ]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $this->ready($sid, $args['token'], $this->userId($request));
            $password = $this->password($request);
            $companies = StereoNxBackup::companies(StereoNxUploads::archive($sid, $args['token']), $password);
            $target = $this->target($sid);
            $items = [];
            foreach ($companies as $company) {
                $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $args['token']), $company['index'], $password);
                $identity = $backup->companyIdentity();
                $profile = $this->targetProfile($sid);
                $suggestions = StereoNxCompanyProfile::suggestions($identity, $profile);
                $current = [];
                foreach ($suggestions as $field => $_) $current[$field] = trim((string) ($profile[$field] ?? ''));
                $items[] = ['index' => $company['index'], 'label' => $company['label'],
                    'profile_suggestions' => $suggestions, 'profile_current' => $current,
                    'bank_account_series' => $backup->bankAccountSeriesPlan(),
                    'identity' => $identity, 'matches_target' => $identity['ico'] === $target['ico']];
            }
            return Json::ok($response, NonprofitMigrationNotice::attach($this->db, $sid, ['companies' => $items, 'target' => $target]));
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable) {
            return Json::error($response, 'backup_read_failed', 'Zálohu se nepodařilo bezpečně načíst.', 422);
        }
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        try {
            $sid = SupplierGuard::currentId($request);
            $uid = $this->userId($request);
            $this->owner($sid, $args['token'], $uid);
            StereoNxUploads::locked($sid, $args['token'], static function (array $state) use ($sid, $args): void {
                $state['status'] = 'deleting';
                StereoNxUploads::save($sid, $args['token'], $state);
            });
            StereoNxUploads::delete($sid, $args['token']);
            return Json::ok($response, ['deleted' => true]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        }
    }

    /**
     * Navrhne cíle skutečně použitých účtů bez zápisu do cílové firmy.
     *
     * @param array<string,string> $args
     */
    public function accountMapping(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $company = filter_var($body['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($company) || (isset($body['match_bank_account_series']) && !is_bool($body['match_bank_account_series']))) {
            return Json::error($response, 'invalid_selection', 'Vyberte firmu a platné přiřazení bankovních řad.', 422);
        }
        try {
            $sid = SupplierGuard::currentId($request);
            $this->ready($sid, $args['token'], $this->userId($request));
            $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $args['token']), $company, $this->password($request))
                ->withBankAccountSeriesMatching(($body['match_bank_account_series'] ?? false) === true);
            $target = $this->target($sid);
            StereoNxCompanyCompatibility::assertAccountingMode($backup->companyIdentity(), $target['accounting_mode']);
            if ($target['accounting_mode'] !== 'double_entry' || $backup->companyIdentity()['ico'] !== $target['ico']) {
                return Json::error($response, 'target_mismatch', 'Mapování účtů vyžaduje shodnou firmu v podvojném účetnictví.', 422);
            }
            return Json::ok($response, $this->accountMapping->propose($backup, $sid));
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        }
    }

    /** Náhled zdrojových protistran bez založení jobu nebo změny dat. @param array<string,string> $args */
    public function partnerCountries(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $company = filter_var($body['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($company)
            || (array_key_exists('blank_country_is_cz', $body) && !is_bool($body['blank_country_is_cz']))
            || (array_key_exists('match_bank_account_series', $body) && !is_bool($body['match_bank_account_series']))) {
            return Json::error($response, 'invalid_selection', 'Vyberte firmu a platné volby převodu.', 422);
        }
        try {
            $sid = SupplierGuard::currentId($request);
            $this->ready($sid, $args['token'], $this->userId($request));
            $partnerCountries = $this->partnerCountryConfiguration($body['partner_countries'] ?? null);
            $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $args['token']), $company, $this->password($request))
                ->withBankAccountSeriesMatching(($body['match_bank_account_series'] ?? false) === true);
            $backup->assertBankAccountSeriesReady();
            $identity = $backup->companyIdentity();
            $target = $this->target($sid);
            StereoNxCompanyCompatibility::assertAccountingMode($identity, $target['accounting_mode']);
            if ($identity['ico'] !== $target['ico'] || !in_array($target['accounting_mode'], ['tax_evidence', 'double_entry'], true) || !$identity['vat_payer'] || !$target['vat_payer']) {
                return Json::error($response, 'target_mismatch', 'Firma musí mít shodné IČO a být plátcem DPH v režimu daňové evidence nebo podvojného účetnictví.', 422);
            }
            // Stejné roky v klíčích jako v obou importérech: daňová evidence
            // používá kalendářní rok, účetnictví skutečný kalendář cílové firmy.
            $calendar = $target['accounting_mode'] === 'double_entry'
                ? $this->accountingJournal->calendar($sid) : FiscalCalendar::calendar();
            $plan = $this->accountingDocuments->prepare($backup, ($body['blank_country_is_cz'] ?? false) === true,
                $calendar, $partnerCountries);
            return Json::ok($response, ['partners' => $plan['partner_country_candidates']]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function run(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $company = filter_var($body['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $mode = (string) ($body['mode'] ?? '');
        $blankCountryIsCz = ($body['blank_country_is_cz'] ?? false) === true;
        $matchBankAccountSeries = ($body['match_bank_account_series'] ?? false) === true;
        if (array_key_exists('match_bank_account_series', $body) && !is_bool($body['match_bank_account_series'])) {
            return Json::error($response, 'invalid_selection', 'Potvrzení přiřazení bankovní řady musí být ano nebo ne.', 422);
        }
        if (!is_int($company) || !in_array($mode, ['dry_run', 'import'], true)) {
            return Json::error($response, 'invalid_selection', 'Vyberte firmu a režim převodu.', 422);
        }
        try {
            $accountMapping = StereoNxAccountMappingService::configuration($body['account_mapping'] ?? null);
            $partnerCountries = $this->partnerCountryConfiguration($body['partner_countries'] ?? null);
            $sid = SupplierGuard::currentId($request);
            $uid = $this->userId($request);
            $token = $args['token'];
            $state = $this->ready($sid, $token, $uid);
            $password = $this->password($request);
            $backup = StereoNxBackup::open(StereoNxUploads::archive($sid, $token), $company, $password)
                ->withBankAccountSeriesMatching($matchBankAccountSeries);
            $backup->assertBankAccountSeriesReady();
            $identity = $backup->companyIdentity();
            $target = $this->target($sid);
            StereoNxCompanyCompatibility::assertAccountingMode($identity, $target['accounting_mode']);
            if ($accountMapping !== null && $target['accounting_mode'] !== 'double_entry') {
                return Json::error($response, 'account_mapping_invalid', 'Mapování účtů je dostupné jen pro podvojné účetnictví.', 422);
            }
            if ($identity['ico'] !== $target['ico'] || !in_array($target['accounting_mode'], ['tax_evidence', 'double_entry'], true) || !$identity['vat_payer'] || !$target['vat_payer']) {
                return Json::error($response, 'target_mismatch', 'Firma musí mít shodné IČO a být plátcem DPH v režimu daňové evidence nebo podvojného účetnictví.', 422);
            }
            if (!$this->db->hasTable('stereo_nx_import_map')) {
                return Json::error($response, 'migration_required',
                    'Chybí databázová migrace pro převod ze Stereo NX. Spusťte php api/bin/migrate.php.', 503);
            }
            $hash = hash_file('sha256', StereoNxUploads::archive($sid, $token));
            if (!hash_equals((string) $state['sha256'], $hash)) {
                return Json::error($response, 'archive_changed', 'Nahraná záloha se změnila; nahrajte ji znovu.', 409);
            }
            if ($mode === 'import' && (($state['dry_run_company'] ?? null) !== $company
                || ($state['dry_run_blank_country_is_cz'] ?? null) !== $blankCountryIsCz
                || ($state['dry_run_match_bank_account_series'] ?? false) !== $matchBankAccountSeries
                || ($state['dry_run_account_mapping_hash'] ?? StereoNxAccountMappingService::fingerprint(null)) !== StereoNxAccountMappingService::fingerprint($accountMapping)
                || ($state['dry_run_partner_countries_hash'] ?? StereoNxPartnerCountries::fingerprint([])) !== StereoNxPartnerCountries::fingerprint($partnerCountries)
                || ($state['dry_run_accounting_mode'] ?? 'tax_evidence') !== $target['accounting_mode'])) {
                return Json::error($response, 'dry_run_required', 'Před převodem spusťte úspěšnou zkoušku nanečisto pro zvolenou firmu.', 409);
            }
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable $e) {
            $this->log->error('Stereo NX import failed', ['exception_class' => $e::class]);
            return Json::error($response, 'backup_read_failed', StereoNxImportJobService::FAILED, 500);
        }

        // Převod firmy běží nejvýš jeden naráz (importer drží řádek firmy po celou transakci).
        $this->jobs->reapStale($sid, StereoNxImportJobService::SOURCE);
        foreach ($this->jobs->listForTenant($sid, StereoNxImportJobService::SOURCE, limit: 20) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running', "Převod už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }
        $params = ['token' => $token, 'company' => $company, 'mode' => $mode, 'blank_country_is_cz' => $blankCountryIsCz,
            'match_bank_account_series' => $matchBankAccountSeries, 'sha256' => $hash, 'accounting_mode' => $target['accounting_mode'],
            'account_mapping' => $accountMapping, 'partner_countries' => $partnerCountries];
        if ($password !== null) {
            $params['password_enc'] = $this->secrets->encryptFor($password, StereoNxImportJobService::passwordContext($sid, $token));
        }
        $jobId = $this->jobs->create($sid, StereoNxImportJobService::SOURCE, $params, $uid);
        $stored = $this->jobs->find($jobId, $sid);
        if ($stored === null || ($stored['source'] ?? '') !== StereoNxImportJobService::SOURCE) {
            $this->jobs->delete($jobId, $sid);
            return Json::error($response, 'migration_required',
                'Chybí databázová migrace pro převod ze Stereo NX. Spusťte php api/bin/migrate.php.', 503);
        }
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
        return Json::ok($response, ['mode' => $mode, 'job_id' => $jobId, 'status' => 'queued'], 202);
    }

    /**
     * Výsledek (report) doběhlého jobu převodu nad touto zálohou.
     *
     * @param array<string,string> $args
     */
    public function result(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        try {
            ['job' => $job, 'report' => $report] = $this->runReport($request, $args);
            return Json::ok($response, ['mode' => ($job['params']['mode'] ?? '') === 'import' ? 'import' : 'dry_run', 'report' => $report]);
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function resultPdf(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, AccessLevel::READ)) return $denied;
        try {
            ['job' => $job, 'report' => $report] = $this->runReport($request, $args);
            $supplier = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
            $supplier->execute([SupplierGuard::currentId($request)]);
            $companyName = $supplier->fetchColumn();
            if ($companyName === false) throw new StereoNxException('target_missing', 'Cílová firma nebyla nalezena.');
            $bytes = $this->protocolPdf->render([
                'report' => $report, 'mode' => ($job['params']['mode'] ?? '') === 'import' ? 'import' : 'dry_run',
                'job_id' => (int) $job['id'], 'created_at' => $job['created_at'],
                'company_name' => (string) $companyName,
                'language' => ($request->getQueryParams()['lang'] ?? '') === 'en' ? 'en' : 'cs',
            ]);
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="stereo-nx-protocol-' . (int) $job['id'] . '.pdf"')
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (StereoNxException $e) {
            return $this->error($response, $e);
        } catch (\Throwable $e) {
            $this->log->error('Stereo NX protocol PDF failed', ['exception_class' => $e::class]);
            return Json::error($response, 'pdf_failed', 'Protokol PDF se nepodařilo vytvořit.', 500);
        }
    }

    /** @param array<string,string> $args
     * @return array{job:array<string,mixed>,report:array<string,mixed>} */
    private function runReport(Request $request, array $args): array
    {
        $sid = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $uid = $this->userId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || ($job['source'] ?? '') !== StereoNxImportJobService::SOURCE
            || (string) ($job['params']['token'] ?? '') !== $token || (int) ($job['created_by'] ?? 0) !== $uid) {
            throw new StereoNxException('not_found', 'Převod nebyl nalezen.');
        }
        $report = $this->runReports->read($sid, (int) $job['id'], $job['result_path'] ?? null);
        if ($report === null) {
            try {
                $state = StereoNxUploads::state($sid, $token);
            } catch (StereoNxException $e) {
                if ($e->errorCode !== 'upload_not_found') throw $e;
                $state = null;
            }
            if ($state !== null) {
                $this->owner($sid, $token, $uid);
                $report = StereoNxUploads::result($sid, $token, (int) $job['id']);
                if ($report !== null) {
                    try {
                        $stored = $this->runReports->save($sid, (int) $job['id'], $report);
                        $this->jobs->setResult((int) $job['id'], $stored['path'], $stored['name'], $stored['size'], $stored['mime']);
                    } catch (StereoNxException $e) {
                        // Výsledek je načtený; nepovedená trvalá kopie se zkusí při dalším čtení.
                        $this->log->warning('Stereo NX run report copy failed', ['job_id' => (int) $job['id'], 'code' => $e->errorCode]);
                    }
                }
            }
        }
        if ($report === null) {
            throw new StereoNxException('result_missing', in_array($job['status'], ['queued', 'running'], true)
                ? 'Převod ještě běží.'
                : (trim((string) ($job['last_error'] ?? '')) ?: 'Výsledek převodu není k dispozici.'));
        }
        return ['job' => $job, 'report' => $report];
    }

    /** @return array<string,string> */
    private function partnerCountryConfiguration(mixed $value): array
    {
        $configuration = StereoNxPartnerCountries::configuration($value);
        if ($configuration !== []) {
            StereoNxPartnerCountries::assertSupported($configuration,
                $this->db->pdo()->query('SELECT iso2 FROM countries')->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $configuration;
    }

    private function password(Request $request): ?string
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('password', $body)) return null;
        $password = $body['password'];
        if (!is_string($password) || $password === '' || strlen($password) > 512) {
            throw new StereoNxException('password_invalid', 'Neplatné heslo zálohy.');
        }
        return $password;
    }

    /** @return array<string,mixed> */
    private function owner(int $sid, string $token, int $uid): array
    {
        $state = StereoNxUploads::state($sid, $token);
        if ((int) ($state['uploaded_by'] ?? 0) !== $uid || $uid < 1) {
            throw new StereoNxException('upload_not_found', 'Nahraná záloha nebyla nalezena.');
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function ready(int $sid, string $token, int $uid): array
    {
        $state = $this->owner($sid, $token, $uid);
        if (($state['status'] ?? '') !== 'ready') throw new StereoNxException('upload_incomplete', 'Záloha ještě není nahraná celá.');
        return $state;
    }

    /** @return array{ico:string,accounting_mode:string,vat_payer:bool} */
    private function target(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, accounting_mode, is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new StereoNxException('target_missing', 'Cílová firma nebyla nalezena.');
        return ['ico' => preg_replace('/\D/', '', (string) $row['ic']),
            'accounting_mode' => (string) $row['accounting_mode'], 'vat_payer' => (bool) $row['is_vat_payer']];
    }

    /** @param array<string,string> $args */
    public function fillCompanyProfile(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $body = (array) ($request->getParsedBody() ?? []);
        $company = filter_var($body['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($company)) return Json::error($response, 'invalid_selection', 'Vyberte firmu ze zálohy.', 422);
        $fields = $body['fields'] ?? null;
        $expected = $body['expected_values'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || !is_array($expected)) {
            return Json::error($response, 'company_profile_selection', 'Vyberte konkrétní údaje firmy k převzetí.', 422);
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = false;
        $transactionStarted = false;
        try {
            $sid = SupplierGuard::currentId($request);
            $state = $this->ready($sid, $args['token'], $this->userId($request));
            $path = StereoNxUploads::archive($sid, $args['token']);
            if (!hash_equals((string) $state['sha256'], (string) hash_file('sha256', $path))) {
                throw new StereoNxException('archive_changed', 'Nahraná záloha se změnila; nahrajte ji znovu.');
            }
            $identity = StereoNxBackup::open($path, $company, $this->password($request))->companyIdentity();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT stereo_company_profile');
            $transactionStarted = true;
            $target = $this->targetProfile($sid, true);
            if (!StereoNxCompanyProfile::matchesCompany($identity, $target)) {
                throw new StereoNxException('ico_mismatch', 'IČO firmy v záloze a vybrané firmy se neshoduje.');
            }
            $values = StereoNxCompanyProfile::selected($identity, $target, $fields, $expected);
            // Sdílená správa firmy zachová oprávnění i validaci; očekávané hodnoty
            // a zámek brání přepsání souběžné změny po zobrazení náhledu.
            $result = $this->settings->updateSupplierById($request->withParsedBody($values), new \Slim\Psr7\Response(), ['id' => (string) $sid]);
            if ($result->getStatusCode() >= 400) {
                if ($ownsTransaction) $pdo->rollBack();
                else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT stereo_company_profile');
                    $pdo->exec('RELEASE SAVEPOINT stereo_company_profile');
                }
                return $result;
            }
            if ($ownsTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT stereo_company_profile');
            return Json::ok($response, ['filled_fields' => array_keys($values)]);
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($transactionStarted && $pdo->inTransaction()) {
                if ($ownsTransaction) $pdo->rollBack();
                else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT stereo_company_profile');
                    $pdo->exec('RELEASE SAVEPOINT stereo_company_profile');
                }
            }
            if ($e instanceof StereoNxException) return $this->error($response, $e);
            return Json::error($response, 'company_profile_failed', 'Údaje firmy se nepodařilo doplnit. Zkontrolujte je v nastavení firmy.', 422);
        }
    }

    private function targetProfile(int $supplierId, bool $lock = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, ' . implode(', ', StereoNxCompanyProfile::FIELDS)
            . ' FROM supplier WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new StereoNxException('target_missing', 'Cílová firma neexistuje.');
        return $row;
    }

    private function userId(Request $request): int
    {
        return (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
    }

    private function deny(Request $request, Response $response, AccessLevel $level = AccessLevel::WRITE): ?Response
    {
        if (!RequestAuthorization::allows($request, 'utilities.import', $level)) {
            return Json::error($response, 'forbidden', 'Převod Stereo NX vyžaduje oprávnění k importu.', 403);
        }
        if (SupplierGuard::currentId($request) < 1) return Json::error($response, 'no_supplier', 'Vyberte cílovou firmu.', 400);
        if ($this->userId($request) < 1) return Json::sessionRequired($response);
        return null;
    }

    private function error(Response $response, StereoNxException $e): Response
    {
        $status = in_array($e->errorCode, ['company_profile_changed', 'result_missing'], true) ? 409
            : (in_array($e->errorCode, ['upload_not_found', 'target_missing', 'not_found'], true) ? 404 : 422);
        return Json::error($response, $e->errorCode, $e->getMessage(), $status);
    }
}
