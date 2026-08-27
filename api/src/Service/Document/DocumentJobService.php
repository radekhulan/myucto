<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\ImportJobRepository;

/**
 * Worker služba pro background joby sekce Dokumenty (vzor MonthlyExportService):
 *   - document_zip_import  → rozbalí nahraný ZIP a naimportuje (vč. ZFO uvnitř),
 *   - document_zip_export  → sestaví ZIP z vybraných dokumentů ke stažení.
 *
 * Artefakty jobů leží v storage/documents/sup-{id}/_jobs/.
 */
final class DocumentJobService
{
    /** Throttling DB zápisů progresu / kontrol zrušení. */
    private const PROGRESS_EVERY = 1;       // progres po každém souboru (responzivní UI)
    private const CANCEL_CHECK_EVERY = 5;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly DocumentFolderRepository $folders,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) return;
        if (!$this->jobs->markRunning($jobId)) return; // race — jiný worker už běží

        $sid = (int) $job['supplier_id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $source = (string) $job['source'];

        try {
            if ($source === 'document_zip_import') {
                $this->runImport($jobId, $sid, $params, (int) ($job['created_by'] ?? 0) ?: null);
            } elseif ($source === 'document_folder_import') {
                $this->runFolderImport($jobId, $sid, $params, (int) ($job['created_by'] ?? 0) ?: null);
            } elseif ($source === 'document_zip_export') {
                // Viewer se rekonstruuje z request-time snapshotu; starý job bez
                // kategoriálního příznaku zůstává vůči mzdovým důkazům fail-closed.
                $createdBy = (int) ($job['created_by'] ?? 0) ?: null;
                $viewer = DocumentViewerContext::fromJobParams($params, $createdBy);
                $this->runExport($jobId, $sid, $params, $viewer);
            } else {
                $this->jobs->markFailed($jobId, "Source '{$source}' není podporován.");
            }
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /** @param array<string,mixed> $params */
    private function runImport(int $jobId, int $sid, array $params, ?int $userId): void
    {
        $zipPath = (string) ($params['zip_path'] ?? '');
        // Chunkovaný upload nemá zip_path v params — leží v _jobs/up-{jobId}/blob.
        if ($zipPath === '') {
            $zipPath = DocumentStorage::baseDir($sid) . '/_jobs/up-' . $jobId . '/blob';
        }
        $folderId = isset($params['folder_id']) && $params['folder_id'] !== null ? (int) $params['folder_id'] : null;

        if (!is_file($zipPath)) {
            $this->jobs->markFailed($jobId, 'ZIP soubor jobu nenalezen.');
            return;
        }

        $this->jobs->appendLog($jobId, 'Rozbaluji ZIP…');
        $entries = $this->ingest->extractZip($zipPath);
        $this->jobs->updateProgress($jobId, [
            'total_items'  => count($entries),
            'current_step' => 'Importuji soubory',
        ]);

        $tick = 0;
        $cancelled = false;
        $res = $this->ingest->ingestZipEntries(
            $entries,
            $sid,
            $folderId,
            $userId,
            function (int $processed, int $total, int $created) use ($jobId, &$tick): void {
                $tick++;
                if ($tick % self::PROGRESS_EVERY === 0 || $processed === $total) {
                    $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created]);
                }
            },
            function () use ($jobId, &$cancelled): bool {
                static $n = 0;
                $n++;
                if ($n % self::CANCEL_CHECK_EVERY !== 0) return false;
                $cancelled = $this->jobs->isCancelRequested($jobId);
                return $cancelled;
            },
        );

        @unlink($zipPath);

        $this->jobs->updateProgress($jobId, [
            'processed'     => count($entries),
            'created_count' => count($res['created_ids']),
            'skipped_count' => count($res['skipped']),
        ]);

        if ($res['cancelled']) {
            $this->jobs->appendLog($jobId, 'Zrušeno uživatelem (vytvořeno ' . count($res['created_ids']) . ').');
            $this->jobs->markCancelled($jobId);
            return;
        }
        $this->jobs->appendLog($jobId, 'Hotovo: ' . count($res['created_ids']) . ' souborů, '
            . count($res['skipped']) . ' přeskočeno.');
        $this->jobs->markCompleted($jobId);
    }

    /**
     * Upload celé složky: chunked nahrané soubory leží ve staging dir + manifest.jsonl
     * (po řádcích {f: staged_name, n: original_name, p: rel_dir}). Zpracujeme je stejně
     * jako běžný upload (vč. ZFO auto-rozbalení), s rekonstrukcí stromu z relativní cesty.
     *
     * @param array<string,mixed> $params
     */
    private function runFolderImport(int $jobId, int $sid, array $params, ?int $userId): void
    {
        $staging = (string) ($params['staging_dir'] ?? '');
        // Chunkovaný upload: staging je _jobs/up-{jobId}.
        if ($staging === '') {
            $staging = DocumentStorage::baseDir($sid) . '/_jobs/up-' . $jobId;
        }
        $staging = rtrim($staging, '/\\');
        $folderId = isset($params['folder_id']) && $params['folder_id'] !== null ? (int) $params['folder_id'] : null;
        $manifest = $staging . '/manifest.jsonl';

        if (!is_dir($staging) || !is_file($manifest)) {
            $this->jobs->markFailed($jobId, 'Staging složky jobu nenalezen.');
            return;
        }

        $lines = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $total = count($lines);
        $this->jobs->updateProgress($jobId, ['total_items' => $total, 'current_step' => 'Importuji soubory']);
        $this->jobs->appendLog($jobId, 'Importuji ' . $total . ' souborů ze složky…');

        $created = 0;
        $skipped = 0;
        $processed = 0;
        foreach ($lines as $line) {
            if ($processed % self::CANCEL_CHECK_EVERY === 0 && $this->jobs->isCancelRequested($jobId)) {
                $this->cleanupStaging($staging);
                $this->jobs->markCancelled($jobId);
                return;
            }
            $processed++;
            $e = json_decode($line, true);
            if (!is_array($e)) continue;

            $staged = $staging . '/' . basename((string) ($e['f'] ?? ''));
            if (!is_file($staged)) { $skipped++; continue; }

            $segments = array_values(array_filter(
                array_map([$this->storage, 'sanitizeFilename'], explode('/', trim(str_replace('\\', '/', (string) ($e['p'] ?? '')), '/'))),
                static fn(string $s): bool => $s !== '' && $s !== '.' && $s !== '..',
            ));
            $targetFolder = $this->ingest->ensureFolderPath($sid, $folderId, $segments, $userId);

            try {
                $res = $this->ingest->ingestUploadedTemp($staged, $sid, $targetFolder, (string) ($e['n'] ?? 'dokument'), $userId, 'keep');
                $created += count($res['created_ids']);
                $skipped += count($res['skipped']);
            } catch (DocumentException) {
                @unlink($staged);
                $skipped++;
            }
            $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        }

        $this->cleanupStaging($staging);
        $this->jobs->appendLog($jobId, 'Hotovo: ' . $created . ' souborů, ' . $skipped . ' přeskočeno.');
        $this->jobs->markCompleted($jobId);
    }

    private function cleanupStaging(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }

    /** @param array<string,mixed> $params */
    private function runExport(int $jobId, int $sid, array $params, DocumentViewerContext $viewer): void
    {
        $docIds = array_values(array_filter(array_map('intval', (array) ($params['ids'] ?? []))));
        $folderIds = array_values(array_filter(array_map('intval', (array) ($params['folder_ids'] ?? []))));
        if ($docIds === [] && $folderIds === []) {
            $this->jobs->markFailed($jobId, 'Nebyly vybrány žádné položky.');
            return;
        }
        if (!class_exists(\ZipArchive::class)) {
            $this->jobs->markFailed($jobId, 'ext-zip není dostupné.');
            return;
        }

        // Vybrané složky → dokumenty se zachováním stromu (prefix cesty v ZIP).
        // $plan: list<array{id:int, prefix:string}>  (prefix '' = kořen ZIP)
        [$plan, $seenDocIds] = $this->expandFolderSelection($sid, $folderIds, $viewer);
        // Volně vybrané dokumenty jdou do kořene ZIP; přeskoč ty, co už pokryla složka.
        foreach ($docIds as $id) {
            if (isset($seenDocIds[$id])) continue;
            $seenDocIds[$id] = true;
            $plan[] = ['id' => $id, 'prefix' => ''];
        }
        if ($plan === []) {
            $this->jobs->markFailed($jobId, 'Žádný soubor k zabalení.');
            return;
        }

        $jobsDir = DocumentStorage::baseDir($sid) . '/_jobs';
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0755, true) && !is_dir($jobsDir)) {
            $this->jobs->markFailed($jobId, 'Úložiště jobů není zapisovatelné.');
            return;
        }
        $relPath = 'sup-' . $sid . '/_jobs/export-' . $jobId . '.zip';
        $outPath = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('documents') . '/' . $relPath;

        $this->jobs->updateProgress($jobId, ['total_items' => count($plan), 'current_step' => 'Balím dokumenty']);
        $this->jobs->appendLog($jobId, 'Sestavuji ZIP z ' . count($plan) . ' souborů…');

        $zip = new \ZipArchive();
        if ($zip->open($outPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->jobs->markFailed($jobId, 'Nepodařilo se vytvořit ZIP.');
            return;
        }

        $used = [];
        $added = 0;
        $processed = 0;
        foreach ($plan as $item) {
            if ($processed % self::CANCEL_CHECK_EVERY === 0 && $this->jobs->isCancelRequested($jobId)) {
                $zip->close();
                @unlink($outPath);
                $this->jobs->markCancelled($jobId);
                return;
            }
            $doc = $this->documents->findRaw($item['id'], $sid, $viewer, false);
            $processed++;
            if ($doc === null) { $this->jobs->updateProgress($jobId, ['processed' => $processed]); continue; }
            $path = $this->storage->pathFor($sid, (string) $doc['sha256'], (string) $doc['filename']);
            if (!is_file($path)) { $this->jobs->updateProgress($jobId, ['processed' => $processed]); continue; }

            $name = $this->storage->sanitizeFilename((string) $doc['original_name']);
            $prefix = $item['prefix'] !== '' ? $item['prefix'] . '/' : '';
            $entry = $prefix . $name;
            $n = 1;
            while (isset($used[$entry])) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $entry = $prefix . pathinfo($name, PATHINFO_FILENAME) . '-' . (++$n) . ($ext !== '' ? '.' . $ext : '');
            }
            $used[$entry] = true;
            if ($zip->addFile($path, $entry)) $added++;
            if ($processed % self::PROGRESS_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $added]);
            }
        }
        $zip->close();

        if ($added === 0 || !is_file($outPath)) {
            @unlink($outPath);
            $this->jobs->markFailed($jobId, 'Žádný soubor k zabalení.');
            return;
        }

        $size = (int) filesize($outPath);
        $this->jobs->setResult($jobId, $relPath, 'dokumenty-' . $jobId . '.zip', $size, 'application/zip');
        $this->jobs->updateProgress($jobId, ['processed' => count($plan), 'created_count' => $added]);
        $this->jobs->appendLog($jobId, 'Hotovo: ' . $added . ' souborů, ' . round($size / 1024, 1) . ' KB.');
        $this->jobs->markCompleted($jobId);
    }

    /**
     * Rozbalí vybrané složky na plán dokumentů se zachováním stromu. Z vícenásobně
     * vybraných (složka i její podsložka) bere jen nejvyšší úroveň, aby se prefix
     * neduplikoval; každý dokument dostane cestu „Kořen/Podsložka".
     *
     * @param int[] $folderIds
     * @return array{0: list<array{id:int, prefix:string}>, 1: array<int,bool>}
     *               plán + množina už pokrytých document id (k dedup volných výběrů)
     */
    private function expandFolderSelection(int $sid, array $folderIds, DocumentViewerContext $viewer): array
    {
        $folderIds = array_values(array_filter(array_map('intval', $folderIds), static fn(int $v): bool => $v > 0));
        if ($folderIds === []) return [[], []];

        $byId = [];
        $childrenOf = [];
        foreach ($this->folders->listAll($sid) as $f) {
            $fid = (int) $f['id'];
            $byId[$fid] = $f;
            $childrenOf[(int) ($f['parent_id'] ?? 0)][] = $fid;
        }

        $selectedSet = [];
        foreach ($folderIds as $id) {
            if (isset($byId[$id])) $selectedSet[$id] = true;
        }
        // Nejvyšší vybrané = bez vybraného předka (jinak by se dokument zabalil 2×).
        $hasSelectedAncestor = static function (int $id) use ($byId, $selectedSet): bool {
            $cur = $byId[$id]['parent_id'] ?? null;
            $guard = 0;
            while ($cur !== null && $guard++ < 256) {
                if (isset($selectedSet[(int) $cur])) return true;
                $cur = $byId[(int) $cur]['parent_id'] ?? null;
            }
            return false;
        };

        // BFS podstromem každého top-level kořene; prefix = sanitizovaný strom názvů.
        $prefixByFolder = [];
        foreach (array_keys($selectedSet) as $root) {
            if ($hasSelectedAncestor($root)) continue;
            $stack = [[$root, $this->storage->sanitizeFilename((string) $byId[$root]['name'])]];
            while ($stack !== []) {
                [$fid, $prefix] = array_pop($stack);
                $prefixByFolder[$fid] = $prefix;
                foreach ($childrenOf[$fid] ?? [] as $child) {
                    $stack[] = [$child, $prefix . '/' . $this->storage->sanitizeFilename((string) $byId[$child]['name'])];
                }
            }
        }

        $plan = [];
        $seen = [];
        foreach ($this->documents->rawByFolderIds($sid, array_keys($prefixByFolder), $viewer) as $d) {
            $id = (int) $d['id'];
            $seen[$id] = true;
            $plan[] = ['id' => $id, 'prefix' => $prefixByFolder[(int) $d['folder_id']] ?? ''];
        }
        return [$plan, $seen];
    }
}
