<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Převod ze Stereo NX na pozadí (`import_jobs.source = stereo_nx_import`) — zkouška
 * nanečisto i ostrý převod, stejně jako u ostatních převodů z cizích programů.
 *
 * Průvodce ověří zálohu, firmu a cílovou firmu v požadavku a založí job s tím, co
 * ověřil (firma v záloze, režim, otisk zálohy). Job pod zámkem zálohy
 * ({@see StereoNxUploads::locked()}) zopakuje kontrolu, že se záloha nezměnila a že
 * ostrému převodu předchází úspěšná zkouška téže firmy, spustí {@see StereoNxImporter}
 * a výsledek (report) uloží k záloze, odkud si ho průvodce po doběhnutí jobu stáhne.
 *
 * Importer průběh ani zrušení nehlásí (jeden běh = jedna transakce): job hlásí jen
 * začátek a konec a zrušit jde, dokud čeká ve frontě.
 *
 * Heslo zálohy (když ho uživatel zadal) jde do parametrů jobu zašifrované a vázané
 * na firmu a zálohu; job ho po přečtení z řádku odebere.
 */
final class StereoNxImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'stereo_nx_import';
    public const FAILED = 'Převod se nepodařilo dokončit; zkontrolujte stav dat.';

    protected const LOG_PREFIX = 'Stereo NX';
    protected const UPLOAD_NOUN = 'zálohy';
    protected const EXCEPTION_CLASS = StereoNxException::class;
    protected const RUN_FAILED = self::FAILED;

    public function __construct(
        ImportJobRepository $jobs,
        private readonly StereoNxImporter $importer,
        private readonly StereoNxAccountingImporter $accountingImporter,
        private readonly SecretEncryption $secrets,
        private readonly LoggerInterface $log,
        ActivityLogger $logger,
        private readonly MigrationCompanyLock $companyLock,
        private readonly StereoNxRunReportStore $runReports,
        private readonly Connection $db,
    ) {
        parent::__construct($jobs, null, $logger);
    }

    /** Kontext šifrování hesla v parametrech jobu: heslo nejde použít s jinou zálohou ani firmou. */
    public static function passwordContext(int $supplierId, string $token): string
    {
        return 'stereo-nx-backup:' . $supplierId . ':' . $token;
    }

    protected function uploads(): ChunkedUploadStore
    {
        return StereoNxUploads::store();
    }

    protected function supportsPrepareJob(): bool
    {
        return false;
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        throw new \LogicException('Stereo NX zpracovává nahranou zálohu v průvodci.');
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        throw new \LogicException('Stereo NX zpracovává nahranou zálohu v průvodci.');
    }

    protected function acquireCompanyLock(int $supplierId): bool
    {
        return $this->companyLock->acquire(self::SOURCE, $supplierId);
    }

    protected function releaseCompanyLock(int $supplierId): void
    {
        $this->companyLock->release(self::SOURCE, $supplierId);
    }

    /** @param array<string,mixed> $job */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $company = (int) ($params['company'] ?? -1);
        $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $dryRun = $mode === 'dry_run';
        $blankCountryIsCz = ($params['blank_country_is_cz'] ?? false) === true;
        $matchBankAccountSeries = ($params['match_bank_account_series'] ?? false) === true;
        $hash = (string) ($params['sha256'] ?? '');
        $accountingMode = (string) ($params['accounting_mode'] ?? 'tax_evidence');
        $accountMapping = $params['account_mapping'] ?? null;

        try {
            $password = isset($params['password_enc'])
                ? $this->secrets->decryptFor((string) $params['password_enc'], self::passwordContext($supplierId, $token))
                : null;
        } catch (\Throwable) {
            $this->jobs->removeParams($jobId, ['password_enc']);
            $this->jobs->markFailed($jobId, 'Heslo zálohy nelze přečíst, spusťte převod znovu.');
            return;
        }
        $this->jobs->removeParams($jobId, ['password_enc']);

        $this->jobs->updateProgress($jobId, [
            'total_items' => 1,
            'processed' => 0,
            'current_step' => $dryRun ? 'Zkouška nanečisto běží' : 'Převod běží',
        ]);
        $this->jobs->appendLog($jobId, ($dryRun ? 'Zkouška nanečisto' : 'Ostrý převod') . " firmy č. {$company} ze zálohy Stereo NX.");

        try {
            $backup = StereoNxBackup::open(StereoNxUploads::archive($supplierId, $token), $company, $password)
                ->withBankAccountSeriesMatching($matchBankAccountSeries);
            $accountMapping = StereoNxAccountMappingService::configuration($accountMapping);
            $partnerCountries = StereoNxPartnerCountries::configuration($params['partner_countries'] ?? null);
            if ($partnerCountries !== []) {
                StereoNxPartnerCountries::assertSupported($partnerCountries,
                    $this->db->pdo()->query('SELECT iso2 FROM countries')->fetchAll(\PDO::FETCH_COLUMN));
            }
            $report = StereoNxUploads::locked($supplierId, $token, function (array $locked) use ($jobId, $supplierId, $userId, $token, $company, $mode, $dryRun, $backup, $hash, $blankCountryIsCz, $matchBankAccountSeries, $accountingMode, $accountMapping, $partnerCountries): array {
                if (($locked['status'] ?? '') !== 'ready' || $hash === '' || !hash_equals((string) ($locked['sha256'] ?? ''), $hash)) {
                    throw new StereoNxException('upload_changed', 'Nahraná záloha se změnila.');
                }
                if ($mode === 'import' && (($locked['dry_run_company'] ?? null) !== $company
                    || ($locked['dry_run_blank_country_is_cz'] ?? null) !== $blankCountryIsCz
                    || ($locked['dry_run_match_bank_account_series'] ?? false) !== $matchBankAccountSeries
                    || ($locked['dry_run_account_mapping_hash'] ?? StereoNxAccountMappingService::fingerprint(null)) !== StereoNxAccountMappingService::fingerprint($accountMapping)
                    || ($locked['dry_run_partner_countries_hash'] ?? StereoNxPartnerCountries::fingerprint([])) !== StereoNxPartnerCountries::fingerprint($partnerCountries)
                    || ($locked['dry_run_accounting_mode'] ?? 'tax_evidence') !== $accountingMode)) {
                    throw new StereoNxException('dry_run_required', 'Před převodem spusťte úspěšnou zkoušku nanečisto.');
                }
                $importer = match ($accountingMode) {
                    'tax_evidence' => $this->importer,
                    'double_entry' => $this->accountingImporter,
                    default => throw new StereoNxException('target_mismatch', 'Nepodporovaný režim účetnictví.'),
                };
                $report = $accountingMode === 'double_entry'
                    ? $this->accountingImporter->run($backup, $supplierId, $userId, $dryRun, $blankCountryIsCz, $accountMapping, $partnerCountries)
                    : $importer->run($backup, $supplierId, $userId, $dryRun, $blankCountryIsCz, $partnerCountries);
                if ($dryRun) {
                    $locked['dry_run_company'] = ($report['ok'] ?? false) === true ? $company : null;
                    $locked['dry_run_accounting_mode'] = ($report['ok'] ?? false) === true ? $accountingMode : null;
                    $locked['dry_run_blank_country_is_cz'] = ($report['ok'] ?? false) === true ? $blankCountryIsCz : null;
                    $locked['dry_run_match_bank_account_series'] = ($report['ok'] ?? false) === true ? $matchBankAccountSeries : null;
                    $locked['dry_run_account_mapping_hash'] = ($report['ok'] ?? false) === true ? StereoNxAccountMappingService::fingerprint($accountMapping) : null;
                    $locked['dry_run_partner_countries_hash'] = ($report['ok'] ?? false) === true ? StereoNxPartnerCountries::fingerprint($partnerCountries) : null;
                } else {
                    $locked['dry_run_company'] = null;
                    $locked['dry_run_accounting_mode'] = null;
                    $locked['dry_run_blank_country_is_cz'] = null;
                    $locked['dry_run_match_bank_account_series'] = null;
                    $locked['dry_run_account_mapping_hash'] = null;
                    $locked['dry_run_partner_countries_hash'] = null;
                    $locked['imported_at'] = time();
                }
                StereoNxUploads::save($supplierId, $token, $locked);
                StereoNxUploads::saveResult($supplierId, $token, $jobId, $report);
                try {
                    $stored = $this->runReports->save($supplierId, $jobId, $report);
                    $this->jobs->setResult($jobId, $stored['path'], $stored['name'], $stored['size'], $stored['mime']);
                } catch (StereoNxException $e) {
                    // Převod je už zapsaný a výsledek leží u nahrané zálohy; trvalou kopii
                    // doplní první čtení výsledku. Chyba kopie nesmí z převodu udělat selhání.
                    $this->jobs->appendLog($jobId, $e->getMessage());
                }
                return $report;
            });
        } catch (StereoNxException $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $diagnostic = ['exception_class' => $e::class, 'job_id' => $jobId];
            if ($e instanceof \PDOException) {
                $sqlState = (string) $e->getCode();
                if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) $diagnostic['sqlstate'] = $sqlState;
                $driverCode = $e->errorInfo[1] ?? null;
                if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                    $diagnostic['driver_code'] = (int) $driverCode;
                }
            }
            $this->log->error('Stereo NX import failed', $diagnostic);
            $this->jobs->markFailed($jobId, self::FAILED);
            return;
        }

        $ok = ($report['ok'] ?? false) === true;
        $written = is_array($report['written'] ?? null) ? $report['written'] : [];
        $this->jobs->updateProgress($jobId, [
            'processed' => 1,
            'created_count' => (int) (($written['issued'] ?? 0) + ($written['purchases'] ?? 0) + ($written['journal_entries_created'] ?? 0)),
            'failed_count' => is_array($report['errors'] ?? null) ? count($report['errors']) : 0,
            'current_step' => 'Hotovo',
        ]);
        if ($ok) {
            $this->jobs->markCompleted($jobId);
        } else {
            $this->jobs->markFailed($jobId, $dryRun
                ? 'Zkouška nanečisto našla chyby, podrobnosti jsou ve výsledku.'
                : 'Převod neproběhl, podrobnosti jsou ve výsledku.');
        }
    }
}
