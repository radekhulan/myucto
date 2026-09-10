<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;

/**
 * Převod z Money S3 na pozadí (`import_jobs.source = money_s3_import`, migrace 1807).
 *
 * Tahle třída řeší jen to, co přidává job: najít nahranou zálohu, hlásit průběh, uložit
 * protokol k běhu a uklidit. Převod sám dělá {@see MoneyS3Importer}.
 *
 * Zkouška nanečisto běží v jedné transakci, která se vrací. Průběh do řádku jobu během
 * ní nezapisuje: zápis by držel zámek řádku až do konce a požadavek na zrušení z UI by
 * na něm visel. UI proto u zkoušky ukazuje jen „běží".
 *
 * Běh drží po celou dobu zámek firmy ({@see MoneyS3ImportRepository::acquireLock()}).
 * Job bez hlášení průběhu (zkouška nanečisto) by jinak po čtvrthodině vypadal jako
 * mrtvý, úklid by ho ukončil a mohl by se spustit druhý převod nad toutéž mapou.
 */
final class MoneyS3ImportJobService
{
    public const SOURCE = 'money_s3_import';

    private const STEP_LABELS = [
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
        private readonly ImportJobRepository $jobs,
        private readonly MoneyS3ImportRepository $runs,
        private readonly MoneyS3Importer $importer,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        if (!$this->runs->acquireLock($supplierId)) {
            $this->jobs->markFailed($jobId, 'Převod této firmy už běží v jiném procesu, druhý se nespouští.');
            return;
        }
        try {
            $this->runLocked($jobId, $job, $supplierId);
        } finally {
            $this->runs->releaseLock($supplierId);
        }
    }

    /** @param array<string,mixed> $job */
    private function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $mode = (string) ($params['mode'] ?? ImportOptions::MODE_DRY_RUN);
        $runId = null;

        try {
            $interrupted = $this->runs->closeInterruptedRuns($supplierId);
            if ($interrupted > 0) {
                $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených běhů převodu.");
            }
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $backup = Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            $options = new ImportOptions(
                $mode,
                (bool) ($params['close_history'] ?? true),
                isset($params['first_period_start']) && $params['first_period_start'] !== '' ? (string) $params['first_period_start'] : null,
                array_values(array_map('strval', (array) ($params['related_party_icos'] ?? []))),
                MoneyS3Uploads::reports($supplierId, $token),
                (bool) ($params['confirm_ico'] ?? false),
            );
            $agenda = (array) ($meta['agenda'] ?? []);
            $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                'agenda_ico' => $agenda['ico'] ?? null,
                'agenda_name' => $agenda['name'] ?? null,
                'money_version' => $agenda['version'] ?? null,
                'backup_sha256' => $meta['sha256'] ?? null,
            ], $userId > 0 ? $userId : null);

            $steps = MoneyS3Importer::stepKeys();
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps),
                'processed' => 0,
                'current_step' => $options->isDryRun() ? 'Zkouška nanečisto běží' : 'Připravuji převod',
            ]);
            $this->jobs->appendLog($jobId, ($options->isDryRun() ? 'Zkouška nanečisto' : 'Ostrý převod') . ' agendy ' . ($agenda['name'] ?? '') . '.');

            $progress = $options->isDryRun() ? null : function (string $step, int $done, int $total) use ($jobId, $steps): void {
                $index = array_search($step, $steps, true);
                $this->jobs->updateProgress($jobId, [
                    'processed' => $index === false ? count($steps) : (int) $index,
                    'current_step' => mb_substr(self::STEP_LABELS[$step] ?? $step, 0, 120),
                ]);
            };
            $cancel = $options->isDryRun() ? null : fn (): bool => $this->jobs->isCancelRequested($jobId);

            $protocol = $this->importer->run($supplierId, $userId, $backup, $options, $runId, $progress, $cancel);
            $result = $protocol->toArray();
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runs->finishRun($runId, $supplierId, $status, $result);

            $journal = array_column($result['steps'], null, 'key')['journal']['counts'] ?? [];
            $errors = 0;
            $warnings = 0;
            foreach ($result['steps'] as $s) {
                foreach ($s['messages'] as $m) {
                    $errors += $m['level'] === 'error' ? 1 : 0;
                    $warnings += $m['level'] === 'warning' ? 1 : 0;
                }
            }
            $this->jobs->updateProgress($jobId, [
                'processed' => count($steps),
                'created_count' => (int) ($journal['entries'] ?? 0),
                'skipped_count' => (int) ($journal['existing'] ?? 0),
                'failed_count' => $errors,
                'current_step' => $cancelled ? 'Zrušeno uživatelem' : 'Hotovo',
            ]);
            $this->jobs->appendLog($jobId, sprintf('Protokol #%d: %d chyb, %d upozornění.', $runId, $errors, $warnings));

            if ($cancelled) {
                $this->jobs->markCancelled($jobId);
            } elseif ($status === 'failed') {
                $this->jobs->markFailed($jobId, 'Převod nedoběhl nebo nesedí rekonciliace — podrobnosti v protokolu #' . $runId . '.');
            } elseif ($status === 'completed_with_warnings') {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
            if (!$options->isDryRun() && $status !== 'failed' && !$cancelled) {
                MoneyS3Uploads::purge($supplierId, $token);
            }
        } catch (\Throwable $e) {
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $e->getMessage(), 'steps' => []]);
            }
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }
}
