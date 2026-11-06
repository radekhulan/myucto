<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;

/**
 * Převod z Money S3 na pozadí (`import_jobs.source = money_s3_import`, migrace 1807).
 *
 * Tahle třída řeší jen to, co přidává job: najít nahranou zálohu, hlásit průběh, uložit
 * protokol k běhu a uklidit. Převod sám dělá {@see MoneyS3Importer}. Kostra jobu
 * (zpracování nahrané zálohy, zámek firmy, chyby) je společná s ostatními převody
 * ({@see AbstractImportJobService}).
 */
final class MoneyS3ImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'money_s3_import';

    protected const LOG_PREFIX = 'Money S3';
    protected const UPLOAD_NOUN = 'zálohy';
    protected const EXCEPTION_CLASS = MoneyS3Exception::class;
    protected const PREPARE_FAILED = 'Zálohu agendy se nepodařilo přečíst.';
    protected const RUN_FAILED = 'Převod z Money S3 selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
    protected const PREPARE_BUSY = 'Zálohu už zpracovává jiný proces.';
    protected const UPLOAD_INCOMPLETE = 'Záloha agendy není nahraná celá, nahrajte ji znovu.';
    protected const DEFAULT_FILE_NAME = 'agenda.lz';
    protected const UPLOADED_EVENT = 'import.money_s3_uploaded';
    protected const PREPARE_STEPS = ['Kontrolní součet zálohy', 'Rozbaluji zálohu agendy', 'Čtu údaje agendy'];

    /** Veřejné: tytéž kroky hlásí i dávkový převod ({@see MoneyS3BatchJobService}). */
    public const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'posting_rules' => 'Předkontace',
        'purchase_invoices' => 'Přijaté faktury',
        'issued_invoices' => 'Vydané faktury',
        'cash' => 'Pokladna',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady faktur',
        'closing' => 'Uzávěrka historických let',
        'reconciliation' => 'Rekonciliace',
        'done' => 'Dokončuji',
    ];

    public function __construct(
        ImportJobRepository $jobs,
        MoneyS3ImportRepository $runs,
        private readonly MoneyS3Importer $importer,
        ActivityLogger $logger,
    ) {
        parent::__construct($jobs, $runs, $logger);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return MoneyS3Uploads::store();
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        return Ms3Backup::extract($part, MoneyS3Uploads::agendaDir($supplierId, $token));
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        $agenda = AgendaInfo::fromBackup($extracted);
        return [
            // Náhled průvodce z toho staví kontrolu před převodem bez čtení zálohy.
            'meta' => ['agenda' => $agenda->toArray(), 'journal_preview' => $this->importer->journalPreview($extracted, $supplierId)],
            'activity' => ['agenda_ico' => $agenda->ico, 'version' => $agenda->version, 'years' => $agenda->fiscalYears()],
            'log' => 'Záloha agendy ' . $agenda->name . ' načtena.',
        ];
    }

    /** @param array<string,mixed> $job */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $mode = (string) ($params['mode'] ?? ImportOptions::MODE_DRY_RUN);
        $runId = null;

        try {
            $this->closeInterruptedRuns($jobId, $supplierId);
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $backup = Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            $options = new ImportOptions(
                $mode,
                (bool) ($params['close_history'] ?? true),
                isset($params['first_period_start']) && $params['first_period_start'] !== '' ? (string) $params['first_period_start'] : null,
                array_values(array_map('strval', (array) ($params['related_party_icos'] ?? []))),
                MoneyS3Uploads::reports($supplierId, $token),
                (bool) ($params['confirm_ico'] ?? false),
                (int) ($params['from_year'] ?? 0) > 0 ? (int) $params['from_year'] : null,
                (string) ($params['disposal_year_tax'] ?? ImportOptions::DISPOSAL_YEAR_TAX_HALF),
                (bool) ($params['accept_differences'] ?? false),
            );
            $agenda = (array) ($meta['agenda'] ?? []);
            $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                'agenda_ico' => $agenda['ico'] ?? null,
                'agenda_name' => $agenda['name'] ?? null,
                'money_version' => $agenda['version'] ?? null,
                'backup_sha256' => $meta['sha256'] ?? null,
            ], $userId > 0 ? $userId : null);

            $steps = MoneyS3Importer::stepKeys(AgendaInfo::isComplete($agenda) && AgendaInfo::fromArray($agenda)->taxRecordsOnly());
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps),
                'processed' => 0,
                'current_step' => $options->isDryRun() ? 'Zkouška nanečisto běží' : 'Připravuji převod',
            ]);
            $this->jobs->appendLog($jobId, ($options->isDryRun() ? 'Zkouška nanečisto' : 'Ostrý převod') . ' agendy ' . ($agenda['name'] ?? '') . '.');

            $progress = $options->isDryRun() ? null : $this->progressCallback($jobId, $steps, 0, null);
            $cancel = $options->isDryRun() ? null : $this->cancelCallback($jobId);

            // Náhled agendy a deníku spočítal job nahrání - převod zálohu kvůli nim znovu nečte.
            $protocol = $this->importer->run($supplierId, $userId, $backup, $options, $runId, $progress, $cancel,
                AgendaInfo::isComplete($agenda) ? AgendaInfo::fromArray($agenda) : null,
                MoneyS3Importer::journalPreviewComplete($meta['journal_preview'] ?? null) ? $meta['journal_preview'] : null);
            $result = $protocol->toArray();
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runs->finishRun($runId, $supplierId, $status, $result);

            $journal = array_column($result['steps'], null, 'key')['journal']['counts'] ?? [];
            [$errors, $warnings] = self::messageCounts($result);
            $this->jobs->updateProgress($jobId, [
                'processed' => count($steps),
                'created_count' => (int) ($journal['entries'] ?? 0),
                'skipped_count' => (int) ($journal['existing'] ?? 0),
                'failed_count' => $errors,
                'current_step' => $cancelled ? 'Zrušeno uživatelem' : 'Hotovo',
            ]);
            $this->jobs->appendLog($jobId, sprintf('Protokol #%d: %d chyb, %d upozornění.', $runId, $errors, $warnings));

            $this->finishJob($jobId, $status, 'Převod nedoběhl nebo nesedí rekonciliace — podrobnosti v protokolu #' . $runId . '.');
            if (!$options->isDryRun() && $status !== 'failed' && !$cancelled) {
                MoneyS3Uploads::purge($supplierId, $token);
            }
        } catch (\Throwable $e) {
            $message = $this->failureMessage($e, sprintf('převod zálohy %s firmy %d selhal', $token, $supplierId), static::RUN_FAILED);
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $message, 'steps' => []]);
            }
            $this->jobs->markFailed($jobId, $message);
        }
    }
}
