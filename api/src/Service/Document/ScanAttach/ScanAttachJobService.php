<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;

/**
 * Worker dávky „připojit skeny k existujícím dokladům" (`import_jobs.source =
 * scan_attach`). Stovky až tisíce souborů, proto na pozadí a ve třech krocích:
 *
 *   1. ULOŽENÍ — soubory ze zdroje do sekce Dokumenty (složka „Skeny dokladů /
 *      Dávka N"). Obsah, který firma v Dokumentech už má (sha256), se znovu
 *      neukládá, jen se na něj dávka odkáže.
 *   2. VYTĚŽENÍ — AI přečte strany dokladu, čísla, částky, SPZ a kartu. Výsledek
 *      se ukládá k obsahu, takže už vytěžený sken se nevytěžuje.
 *   3. PÁROVÁNÍ — {@see ScanMatcher}; jisté shody se hned připojí, ostatní čekají
 *      na potvrzení v přehledu dávky.
 *
 * Běh je idempotentní: po pádu nebo zrušení se dávka pustí znovu a hotové soubory,
 * vytěžení i rozhodnutí uživatele (potvrzeno / odmítnuto) zůstanou.
 */
final class ScanAttachJobService
{
    public const SOURCE = ScanBatchRepository::SOURCE;
    public const FOLDER_ROOT = 'Skeny dokladů';

    private const CANCEL_CHECK_EVERY = 10;
    /** Jak dlouho dávka čeká, než doběhne jiná dávka téže firmy. */
    public const LOCK_WAIT_SECONDS = 6 * 3600;
    private const LOCK_POLL_SECONDS = 30;
    private const PROGRESS_EVERY = 25;

    private int $failed = 0;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ScanBatchRepository $batches,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly ScanExtractionService $extraction,
        private readonly DocumentExtractionRepository $extractions,
        private readonly ScanMatcher $matcher,
        private readonly ScanTargetRegistry $targets,
        private readonly ScanBatchService $service,
        private readonly PaymentCardRepository $cards,
        private readonly int $lockWaitSeconds = self::LOCK_WAIT_SECONDS,
    ) {}

    /** Staging dávky (chunkovaný upload) — přežije pád workeru, maže se po dokončení. */
    public static function stagingDir(int $supplierId, int $jobId): string
    {
        return DocumentStorage::baseDir($supplierId) . '/_jobs/scan-' . $jobId;
    }

    public function run(int $jobId, ?ScanSourceInterface $source = null): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || ($job['source'] ?? '') !== self::SOURCE) {
            return;
        }
        if (!$this->jobs->markRunning($jobId)) {
            return; // jiný worker ji už zpracovává
        }
        $sid = (int) $job['supplier_id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $userId = (int) ($job['created_by'] ?? 0) ?: null;
        $source ??= new UploadedScanSource(self::stagingDir($sid, $jobId));
        $this->failed = 0;

        $lock = $this->waitForBatchLock($jobId, $sid);
        if ($lock === 'cancelled') {
            $this->jobs->appendLog($jobId, 'Zrušeno uživatelem — dávku lze znovu spustit a naváže.');
            $this->jobs->markCancelled($jobId);
            return;
        }
        if ($lock === 'timeout') {
            $message = 'Jiná dávka skenů téže firmy pořád běží — spusťte tuto dávku znovu po jejím dokončení.';
            $this->jobs->appendLog($jobId, $message);
            $this->jobs->markFailed($jobId, $message);
            return;
        }

        try {
            // Dokončená dávka má nahrané soubory uklizené. Opakovaný běh (nové
            // párování po doplnění dokladů) pak pracuje s tím, co je v Dokumentech.
            if ($this->batches->countItems($sid, $jobId) > 0 && !$source->hasFiles()) {
                $this->jobs->appendLog($jobId, 'Soubory dávky jsou uložené v Dokumentech — navazuji vytěžením a párováním.');
                $stored = true;
            } else {
                $stored = $this->storeFiles($jobId, $sid, $userId, $source);
            }
            if (!$stored || !$this->extractFiles($jobId, $sid, $params)) {
                $this->jobs->appendLog($jobId, 'Zrušeno uživatelem — dávku lze znovu spustit a naváže.');
                $this->jobs->markCancelled($jobId);
                return;
            }
            $summary = $this->matchFiles($jobId, $sid, $params);
            // Nahrané soubory zůstanou, dokud se všechny nepodaří uložit — jinak by
            // je opakovaný běh už neměl odkud vzít. Opuštěné uklidí denní úklid.
            $unstored = $this->batches->countUnstoredItems($sid, $jobId);
            if ($unstored === 0) {
                $source->cleanup();
            } else {
                $this->jobs->appendLog($jobId, sprintf(
                    'Nepodařilo se uložit %d souborů — nahrané soubory dávky zůstávají, Spustit znovu je zkusí uložit znovu.',
                    $unstored,
                ));
            }
            $this->jobs->appendLog($jobId, sprintf(
                'Hotovo: připojeno %d, k potvrzení %d, skeny firmy bez dokladu %d, nerozpoznané %d.',
                $summary['attached'], $summary['proposed'], $summary['orphan'], $summary['unrecognized'],
            ));
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, 'Chyba: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        } finally {
            $this->batches->releaseBatchLock($sid);
        }
    }

    /**
     * Dávky téže firmy běží jedna po druhé ({@see ScanBatchRepository::acquireBatchLock()}).
     * Čekající dávka se hlásí jako živá a reaguje na zrušení.
     *
     * @return 'ok'|'cancelled'|'timeout'
     */
    private function waitForBatchLock(int $jobId, int $sid): string
    {
        if ($this->batches->acquireBatchLock($sid, 0)) {
            return 'ok';
        }
        $this->jobs->updateProgress($jobId, ['current_step' => 'Čekám na dokončení jiné dávky skenů']);
        $this->jobs->appendLog($jobId, 'Jiná dávka skenů téže firmy právě běží — čekám na její dokončení.');
        $deadline = time() + $this->lockWaitSeconds;
        while (time() < $deadline) {
            if ($this->jobs->isCancelRequested($jobId)) {
                return 'cancelled';
            }
            if ($this->batches->acquireBatchLock($sid, min(self::LOCK_POLL_SECONDS, max(1, $deadline - time())))) {
                return 'ok';
            }
            $this->batches->touchJob($sid, $jobId);
        }
        return 'timeout';
    }

    /** @return bool false = zrušeno */
    private function storeFiles(int $jobId, int $sid, ?int $userId, ScanSourceInterface $source): bool
    {
        $this->jobs->updateProgress($jobId, [
            'current_step' => 'Ukládám soubory', 'total_items' => $source->count(), 'processed' => 0,
        ]);
        $folderId = $this->ingest->ensureFolderPath($sid, null, [self::FOLDER_ROOT, 'Dávka ' . $jobId], $userId);

        $n = 0;
        $new = 0;
        foreach ($source->files() as $file) {
            if ($n % self::CANCEL_CHECK_EVERY === 0 && $this->jobs->isCancelRequested($jobId)) {
                if ($file->path !== '' && is_file($file->path)) {
                    @unlink($file->path);
                }
                return false;
            }
            $n++;
            if ($file->error !== null || $file->path === '') {
                $this->failed++;
                $this->jobs->appendLog($jobId, $file->name . ': ' . ($file->error ?? 'unreadable'));
                continue;
            }
            try {
                $sha = (string) hash_file('sha256', $file->path);
                $existing = $this->batches->itemBySha($sid, $jobId, $sha);
                if ($existing !== null && $existing['document_id'] !== null) {
                    continue; // hotové z předchozího běhu nebo duplicitní obsah v dávce
                }
                // Soubor, jehož uložení dřív selhalo, se zkusí uložit znovu.
                $documentId = $this->batches->documentIdBySha($sid, $sha);
                if ($documentId === null) {
                    // Ingest dočasný soubor PŘESUNE do úložiště.
                    $res = $this->ingest->ingestUploadedTemp($file->path, $sid, $folderId, $file->name, $userId, 'keep');
                    $documentId = (int) ($res['created_ids'][0] ?? 0) ?: null;
                    $new++;
                }
                $status = $documentId !== null ? 'stored' : 'skipped';
                if ($existing === null) {
                    $this->batches->insertItem($sid, $jobId, $file->name, $sha, $file->size, $documentId, $status, null);
                } else {
                    $this->batches->updateItem($sid, $existing['id'], ['document_id' => $documentId, 'status' => $status, 'error' => null]);
                }
            } catch (\Throwable $e) {
                $this->failed++;
                if (isset($existing)) {
                    $this->batches->updateItem($sid, $existing['id'], ['error' => $e->getMessage()]);
                } elseif (isset($sha)) {
                    $this->batches->insertItem($sid, $jobId, $file->name, $sha, $file->size, null, 'skipped', $e->getMessage());
                }
                $this->jobs->appendLog($jobId, $file->name . ': ' . $e->getMessage());
            } finally {
                if (is_file($file->path)) {
                    @unlink($file->path);
                }
                unset($sha, $existing);
            }
            $this->jobs->updateProgress($jobId, ['processed' => $n, 'failed_count' => $this->failed]);
        }
        $this->jobs->appendLog($jobId, sprintf('Soubory: %d, nově uloženo do Dokumentů %d.', $n, $new));
        return true;
    }

    /**
     * @param array<string,mixed> $params
     * @return bool false = zrušeno
     */
    private function extractFiles(int $jobId, int $sid, array $params): bool
    {
        if (array_key_exists('extract', $params) && !$params['extract']) {
            $this->jobs->appendLog($jobId, 'Vytěžení obsahu vypnuto — páruje se podle čárového kódu a čísla v názvu souboru.');
            return true;
        }
        $todo = array_values(array_filter(
            $this->batches->listItems($sid, $jobId),
            static fn (array $i): bool => $i['document_id'] !== null && $i['doc_filename'] !== null
                && in_array($i['status'], ['stored', 'extract_failed'], true),
        ));
        if ($todo === []) {
            return true;
        }
        if (!$this->extraction->isConfigured($sid)) {
            $this->jobs->appendLog($jobId, 'AI vytěžení není nastavené — páruje se podle čárového kódu a čísla v názvu souboru.');
            return true;
        }

        $this->jobs->updateProgress($jobId, ['current_step' => 'Vytěžuji obsah skenů', 'total_items' => count($todo), 'processed' => 0]);
        $n = 0;
        foreach ($todo as $item) {
            if ($n % self::CANCEL_CHECK_EVERY === 0 && $this->jobs->isCancelRequested($jobId)) {
                return false;
            }
            $n++;
            $path = $this->storage->pathFor($sid, (string) $item['sha256'], (string) $item['doc_filename']);
            $r = $this->extraction->extract($sid, (int) $item['document_id'], (string) $item['sha256'], $path);
            if ($r['status'] === 'failed') {
                $this->failed++;
                $this->batches->updateItem($sid, (int) $item['id'], ['status' => 'extract_failed', 'error' => $r['error']]);
                if ($r['fatal']) {
                    $this->jobs->appendLog($jobId, 'Vytěžení zastaveno: ' . $r['error']);
                    break;
                }
            } else {
                $this->batches->updateItem($sid, (int) $item['id'], ['status' => 'extracted', 'error' => null]);
            }
            $this->jobs->updateProgress($jobId, ['processed' => $n, 'failed_count' => $this->failed]);
        }
        return true;
    }

    /**
     * @param array<string,mixed> $params
     * @return array{attached:int,proposed:int,orphan:int,unrecognized:int}
     */
    private function matchFiles(int $jobId, int $sid, array $params): array
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Páruji skeny s doklady', 'total_items' => null, 'processed' => 0]);
        $from = isset($params['date_from']) && is_string($params['date_from']) ? $params['date_from'] : null;
        $to = isset($params['date_to']) && is_string($params['date_to']) ? $params['date_to'] : null;

        $targetList = [];
        foreach ($this->service->targetTypes($params) as $type) {
            $t = $this->targets->available($type);
            if ($t === null) {
                $this->jobs->appendLog($jobId, "Doklady typu {$type} teď nelze připojovat, přeskočeno.");
                continue;
            }
            foreach ($t->candidates($sid, $from, $to) as $c) {
                $c['key'] = $type . ':' . $c['id'];
                $targetList[] = $c;
            }
        }

        $items = $this->batches->listItems($sid, $jobId);
        $ext = $this->extractions->mapOkBySha($sid, array_column($items, 'sha256'), ScanExtractionNormalizer::SCHEMA_VERSION);
        $files = [];
        foreach ($items as $it) {
            if ($it['document_id'] === null) {
                $this->batches->updateItem($sid, (int) $it['id'], ['outcome' => ScanMatcher::OUTCOME_UNREADABLE]);
                continue;
            }
            $files[] = ['key' => (string) $it['id'], 'name' => (string) $it['file_name'], 'extraction' => $ext[(string) $it['sha256']] ?? null];
        }

        // Rozhodnutí z předchozích běhů: odmítnuté páry se znovu nenavrhnou,
        // připojené soubory se nepoužijí pro jiný doklad.
        $rejected = [];
        $claimed = [];
        $attachedTargets = [];
        foreach ($this->batches->listMatches($sid, $jobId) as $m) {
            $tk = $m['target_type'] . ':' . $m['target_id'];
            if ($m['state'] === 'rejected') {
                $rejected[] = $m['item_id'] . '|' . $tk;
            } elseif ($m['state'] === 'attached' || $m['state'] === 'confirmed') {
                $claimed[] = (string) $m['item_id'];
                $attachedTargets[] = $tk;
            }
        }
        // Doklad, který už má sken z jiné dávky, další sken podle obsahu nedostane
        // (čárový kód a číslo v názvu přidají další strany dál).
        foreach ($this->batches->targetsWithScanFromOtherBatches($sid, $jobId, $this->service->targetTypes($params)) as $tk) {
            $attachedTargets[] = $tk;
        }
        $this->batches->deleteProposed($sid, $jobId);

        $identity = $this->batches->supplierIdentity($sid);
        $result = $this->matcher->match($files, $targetList, [
            'own_ico' => $identity['ico'],
            'own_name' => $identity['name'],
            'own_plates' => $this->batches->ownPlates($sid),
            // Karty firmy z evidence platebních karet, i archivované: starší účtenka
            // patří kartě, která k datu dokladu platila.
            'own_cards' => array_map(static fn (array $c): array => [
                'last4' => (string) $c['last4'],
                'valid_from' => $c['valid_from'] ?? null,
                'valid_to' => $c['valid_to'] ?? null,
            ], $this->cards->listForSupplier($sid, true)),
            'trust_doc_no' => !empty($params['trust_doc_no']),
            'accept_likely' => !empty($params['accept_likely']),
            'rejected' => $rejected,
            'claimed_files' => array_values(array_unique($claimed)),
            'attached_targets' => array_values(array_unique($attachedTargets)),
        ]);

        $attachFailed = [];
        $written = 0;
        foreach ($result['targets'] as $tk => $r) {
            if ($r['files'] === []) {
                continue;
            }
            [$type, $id] = explode(':', (string) $tk, 2);
            foreach ($r['files'] as $fk) {
                $decision = $r['per_file'][$fk] ?? ['attach' => $r['attach'], 'level' => $r['level'], 'note' => $r['note']];
                $state = $decision['attach'] ? 'attached' : 'proposed';
                $note = $decision['note'];
                if ($decision['attach']) {
                    try {
                        $this->service->attachItem($sid, $type, (int) $id, (int) $fk);
                    } catch (\Throwable $e) {
                        $state = 'proposed';
                        $note = 'attach_failed';
                        $attachFailed[] = (int) $fk;
                        $this->jobs->appendLog($jobId, "Připojení se nepovedlo ({$tk}): " . $e->getMessage());
                    }
                }
                $this->batches->upsertMatch($sid, $jobId, (int) $fk, $type, (int) $id, $r['method'], $decision['level'], $r['score'], $state, $note);
                // Připojování tisíců skenů trvá; bez průběhu by úklid neaktivních
                // úloh dávku po čtvrthodině ukončil jako mrtvou.
                if (++$written % self::PROGRESS_EVERY === 0) {
                    $this->jobs->updateProgress($jobId, ['processed' => $written]);
                }
            }
        }
        foreach ($result['files'] as $fk => $f) {
            $this->batches->updateItem($sid, (int) $fk, ['outcome' => $f['outcome'], 'ownership' => $f['ownership']]);
            if (++$written % self::PROGRESS_EVERY === 0) {
                $this->batches->touchJob($sid, $jobId);
            }
        }
        foreach (array_unique($attachFailed) as $itemId) {
            $this->service->refreshOutcome($sid, $itemId);
        }

        $states = $this->batches->countMatchesByState($sid, $jobId);
        $outcomes = $this->batches->countItemsByOutcome($sid, $jobId);
        $summary = [
            'attached' => ($states['attached'] ?? 0) + ($states['confirmed'] ?? 0),
            'proposed' => $states['proposed'] ?? 0,
            'orphan' => $outcomes[ScanMatcher::OUTCOME_ORPHAN] ?? 0,
            'unrecognized' => ($outcomes[ScanMatcher::OUTCOME_FOREIGN] ?? 0) + ($outcomes[ScanMatcher::OUTCOME_UNKNOWN] ?? 0)
                + ($outcomes[ScanMatcher::OUTCOME_UNREADABLE] ?? 0),
        ];
        $this->jobs->updateProgress($jobId, [
            'processed' => count($files),
            'created_count' => $summary['attached'],
            'skipped_count' => $summary['proposed'],
            'failed_count' => $this->failed,
        ]);
        return $summary;
    }
}
