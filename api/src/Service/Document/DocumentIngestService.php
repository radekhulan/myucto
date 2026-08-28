<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Repository\DmsMessageRepository;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use Psr\Log\LoggerInterface;

/**
 * Orchestruje uložení nahraného souboru do sekce Dokumenty:
 *   - běžný soubor → uložit + extrahovat text + náhled,
 *   - ZFO → uložit kontejner + rozbalit metadata zprávy + přílohy jako děti,
 *   - ZIP (režim explode) → bezpečně rozbalit + rekonstruovat strom složek.
 *
 * Sdílí logiku rekonstrukce stromu složek (z relativních cest) i pro upload
 * celého adresáře z prohlížeče (webkitdirectory).
 */
final class DocumentIngestService
{
    private const ZIP_FILE_CAP = 300 * 1024 * 1024;

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $files,
        private readonly DocumentFolderRepository $folders,
        private readonly DmsMessageRepository $dms,
        private readonly ZfoExtractor $zfo,
        private readonly ZipImporter $zipImporter,
        private readonly DocumentTextExtractor $textExtractor,
        private readonly ThumbnailGenerator $thumbnails,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Hlavní vstup pro jeden nahraný soubor (z dočasné cesty).
     *
     * @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>}
     * @throws DocumentException
     */
    public function ingestUploadedTemp(
        string $tmpPath,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
        string $zipMode = 'keep',
    ): array {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // ZFO — auto-rozbalení
        if ($ext === 'zfo') {
            $head = (string) @file_get_contents($tmpPath, false, null, 0, 64);
            if (ZfoExtractor::looksLikeZfo($head)) {
                return $this->handleZfo($tmpPath, $supplierId, $folderId, $originalName, $userId);
            }
        }

        // ZIP — režim explode (rozbalit a kategorizovat)
        if ($ext === 'zip' && $zipMode === 'explode') {
            return $this->handleZipExplode($tmpPath, $supplierId, $folderId, $userId);
        }

        // Běžný soubor (vč. ZIP v režimu keep)
        $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
        $id = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'manual', null);
        return ['kind' => 'plain', 'created_ids' => [$id], 'container_id' => null, 'skipped' => []];
    }

    /**
     * Uloží jediný originál bez synchronní extrakce textu a tvorby náhledu.
     *
     * Stagingové workflow potřebuje nejdřív spolehlivě převzít původní
     * bajty. Odvozená data vzniknou až při navazujícím zpracování.
     *
     * @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>}
     * @throws DocumentException
     */
    public function ingestOriginalTemp(
        string $tmpPath,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
    ): array {
        $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
        $id = $this->insertAndProcess(
            $stored,
            $supplierId,
            $folderId,
            $originalName,
            $userId,
            'manual',
            null,
            false,
        );
        return ['kind' => 'plain', 'created_ids' => [$id], 'container_id' => null, 'skipped' => []];
    }

    /**
     * Najde-nebo-vytvoří cestu složek z relativních segmentů pod baseFolderId.
     * @param list<string> $segments
     */
    public function ensureFolderPath(int $supplierId, ?int $baseFolderId, array $segments, ?int $userId): ?int
    {
        $cur = $baseFolderId;
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            $existing = $this->folders->findChildIdByName($supplierId, $cur, $seg);
            $cur = $existing ?? $this->folders->create($supplierId, $cur, $seg, $userId);
        }
        return $cur;
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @param array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string} $stored
     */
    private function insertAndProcess(
        array $stored,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
        string $source,
        ?int $parentId,
        bool $createDerivatives = true,
    ): int {
        $id = $this->documents->insert([
            'supplier_id'        => $supplierId,
            'folder_id'          => $folderId,
            // title = původní (čitelný) název pro zobrazení; na disk se nepoužívá.
            'title'              => mb_substr(trim($originalName), 0, 255) ?: 'dokument',
            'description'        => null,
            'original_name'      => $originalName,
            'filename'           => $stored['filename'],
            'sha256'             => $stored['sha256'],
            'mime_type'          => $stored['mime_type'],
            'size_bytes'         => $stored['size_bytes'],
            'doc_type'           => $stored['doc_type'],
            'source'             => $source,
            'parent_document_id' => $parentId,
            'uploaded_by'        => $userId,
        ]);

        // DUAL-WRITE (§4.5): documents inline sloupce = source of truth pro primary;
        // zrcadlíme je do role='primary' document_files řádku (uniformně jako backfill
        // 1024 — každý documents řádek má vlastní primary keyed na své document_id).
        // Extra soubory (role='attachment') přidává až files API (Commit 5).
        try {
            $this->files->add([
                'document_id'   => $id,
                'supplier_id'   => $supplierId,
                'role'          => 'primary',
                'sha256'        => $stored['sha256'],
                'filename'      => $stored['filename'],
                'original_name' => $originalName,
                'mime_type'     => $stored['mime_type'],
                'size_bytes'    => $stored['size_bytes'],
                'doc_type'      => $stored['doc_type'],
                'sort_order'    => 0,
                'uploaded_by'   => $userId,
            ]);
        } catch (\Throwable $e) {
            // Best-effort mirror — nikdy nepoloží ingest (documents inline zůstává SoT),
            // ale selhání logujeme: bez primary document_files řádku by dokument neměl
            // svůj soubor v novém subsystému A (diagnostikovatelné až přes tenhle log).
            $this->logger->warning('Document primary-file mirror insert failed', [
                'document_id' => $id,
                'supplier_id' => $supplierId,
                'sha256'      => $stored['sha256'],
                'error'       => $e->getMessage(),
            ]);
        }

        if ($createDerivatives) {
            $this->postProcess($id, $stored, $supplierId);
        }
        return $id;
    }

    /** Extrakce textu + náhled — best-effort, nikdy nepoloží ingest. */
    private function postProcess(int $id, array $stored, int $supplierId): void
    {
        try {
            $res = $this->textExtractor->extract($stored['abs_path'], $stored['doc_type'], $stored['ext']);
            $this->documents->setText($id, $res['text'], $res['status']);
        } catch (\Throwable) {
            $this->documents->setText($id, null, 'failed');
        }
        try {
            $res = $this->thumbnails->generate($stored['abs_path'], $stored['doc_type'], $stored['sha256'], $supplierId);
            $this->documents->setThumb($id, $res['path'], $res['status']);
        } catch (\Throwable) {
            $this->documents->setThumb($id, null, 'failed');
        }
    }

    /** @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>} */
    private function handleZfo(string $tmpPath, int $supplierId, ?int $folderId, string $originalName, ?int $userId): array
    {
        $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
        $containerId = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'manual', null);

        $created = [$containerId];
        $skipped = [];

        $der = (string) @file_get_contents($stored['abs_path']);
        try {
            $parsed = $this->zfo->extract($der);
        } catch (DocumentException $e) {
            // Nepodařilo se rozbalit — kontejner zůstane jako prostý soubor.
            return ['kind' => 'zfo', 'created_ids' => $created, 'container_id' => $containerId, 'skipped' => [
                ['name' => $originalName, 'reason' => $e->errorCode],
            ]];
        }

        $this->dms->insert($containerId, $parsed['metadata']);

        $attachments = $this->ingestZfoAttachments(
            $parsed['attachments'],
            $supplierId,
            $folderId,
            $userId,
            $containerId,
        );
        $created = array_merge($created, $attachments['created_ids']);
        $skipped = array_merge($skipped, $attachments['skipped']);

        return ['kind' => 'zfo', 'created_ids' => $created, 'container_id' => $containerId, 'skipped' => $skipped];
    }

    /** @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>} */
    private function handleZipExplode(string $tmpPath, int $supplierId, ?int $folderId, ?int $userId): array
    {
        if ((int) @filesize($tmpPath) > self::ZIP_FILE_CAP) {
            throw new DocumentException('zip_total_too_large', 'ZIP archiv je příliš velký.', 413);
        }
        $entries = $this->zipImporter->extractEntries($tmpPath);
        $res = $this->ingestZipEntries($entries, $supplierId, $folderId, $userId);
        @unlink($tmpPath);
        return ['kind' => 'zip', 'created_ids' => $res['created_ids'], 'container_id' => null, 'skipped' => $res['skipped']];
    }

    /**
     * Zpracuje již rozbalené ZIP entries (sdílí synchronní upload i background job).
     * $onProgress($processed, $total, $createdSoFar) — volitelný hlásič pokroku.
     * $isCancelled():bool — volitelná kontrola zrušení (job).
     *
     * @param list<array{segments:list<string>,name:string,bytes:string}> $entries
     * @return array{created_ids:list<int>,skipped:list<array{name:string,reason:string}>,cancelled:bool}
     */
    public function ingestZipEntries(
        array $entries,
        int $supplierId,
        ?int $folderId,
        ?int $userId,
        ?callable $onProgress = null,
        ?callable $isCancelled = null,
    ): array {
        $created = [];
        $skipped = [];
        $total = count($entries);
        $processed = 0;
        $cancelled = false;

        foreach ($entries as $entry) {
            if ($isCancelled !== null && $isCancelled()) { $cancelled = true; break; }

            $targetFolder = $this->ensureFolderPath($supplierId, $folderId, $entry['segments'], $userId);
            try {
                $stored = $this->storage->storeFromBytes($entry['bytes'], $supplierId, $entry['name']);
                if (strtolower($stored['ext']) === 'zfo'
                    && ZfoExtractor::looksLikeZfo((string) @file_get_contents($stored['abs_path'], false, null, 0, 64))) {
                    $sub = $this->ingestStoredZfo($stored, $supplierId, $targetFolder, $entry['name'], $userId);
                    $created = array_merge($created, $sub['created_ids']);
                    $skipped = array_merge($skipped, $sub['skipped']);
                } else {
                    $created[] = $this->insertAndProcess($stored, $supplierId, $targetFolder, $entry['name'], $userId, 'zip_extract', null);
                }
            } catch (DocumentException $e) {
                $skipped[] = ['name' => implode('/', array_merge($entry['segments'], [$entry['name']])), 'reason' => $e->errorCode];
            }
            $processed++;
            if ($onProgress !== null) $onProgress($processed, $total, count($created));
        }

        return ['created_ids' => $created, 'skipped' => $skipped, 'cancelled' => $cancelled];
    }

    /**
     * ZFO přijaté z kanálu (datová schránka) — bajty rovnou do DMS.
     *
     * Existuje proto, aby stažená zpráva šla do Dokumentů TOUTÉŽ cestou jako
     * ta, kterou dnes uživatel nahrává ručně: stejný kontejner, stejné
     * rozbalení příloh, stejné metadata v `dms_messages`. Druhá cesta k témuž
     * cíli by se dřív nebo později rozešla.
     *
     * @return array{container_id:int,created_ids:list<int>,skipped:list<array{name:string,reason:string}>}
     * @throws DocumentException
     */
    public function ingestZfoBytes(
        string $bytes,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
    ): array {
        $stored = $this->storage->storeFromBytes($bytes, $supplierId, $originalName);
        $result = $this->ingestStoredZfo($stored, $supplierId, $folderId, $originalName, $userId);
        return [
            'container_id' => $result['created_ids'][0] ?? 0,
            'created_ids' => $result['created_ids'],
            'skipped' => $result['skipped'],
        ];
    }

    /**
     * Znovu rozbalí přílohy z již uloženého ZFO bez stažení datové schránky.
     * Existující shodné přílohy zachová a nevytvoří duplicity.
     *
     * @return array{created_ids:list<int>,skipped:list<array{name:string,reason:string}>}
     */
    public function reextractZfoAttachments(
        int $containerId,
        int $supplierId,
        DocumentViewerContext $viewer,
        ?int $userId,
    ): array {
        $container = $this->documents->findRaw($containerId, $supplierId, $viewer);
        if ($container === null || (string) ($container['doc_type'] ?? '') !== 'zfo'
            || ($container['parent_document_id'] ?? null) !== null) {
            throw new DocumentException('zfo_not_found', 'ZFO dokument nebyl nalezen.', 404);
        }
        $path = $this->storage->pathFor(
            $supplierId,
            (string) $container['sha256'],
            (string) $container['filename'],
        );
        if (!is_file($path)) {
            throw new DocumentException('zfo_not_found', 'Soubor ZFO nebyl nalezen na disku.', 404);
        }

        $parsed = $this->zfo->extract((string) file_get_contents($path));
        return $this->ingestZfoAttachments(
            $parsed['attachments'],
            $supplierId,
            $container['folder_id'] !== null ? (int) $container['folder_id'] : null,
            $userId,
            $containerId,
            $this->documents->listChildren($containerId, $supplierId, $viewer),
        );
    }

    /** Bezpečně rozbalí ZIP z cesty na entries (pro job — vrací entries k ingestu). */
    public function extractZip(string $zipPath): array
    {
        if ((int) @filesize($zipPath) > self::ZIP_FILE_CAP) {
            throw new DocumentException('zip_total_too_large', 'ZIP archiv je příliš velký.', 413);
        }
        return $this->zipImporter->extractEntries($zipPath);
    }

    /** ZFO již uložené na disku (z entry ZIPu) → kontejner + rozbalení. */
    private function ingestStoredZfo(array $stored, int $supplierId, ?int $folderId, string $originalName, ?int $userId): array
    {
        $containerId = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'zip_extract', null);
        $created = [$containerId];
        $skipped = [];
        try {
            $parsed = $this->zfo->extract((string) @file_get_contents($stored['abs_path']));
        } catch (DocumentException $e) {
            return ['created_ids' => $created, 'skipped' => [['name' => $originalName, 'reason' => $e->errorCode]]];
        }
        $this->dms->insert($containerId, $parsed['metadata']);
        $attachments = $this->ingestZfoAttachments(
            $parsed['attachments'],
            $supplierId,
            $folderId,
            $userId,
            $containerId,
        );
        $created = array_merge($created, $attachments['created_ids']);
        $skipped = array_merge($skipped, $attachments['skipped']);
        return ['created_ids' => $created, 'skipped' => $skipped];
    }

    /**
     * @param list<array{name:string,mime:string,meta_type:string,bytes:string}> $attachments
     * @param list<array<string,mixed>> $existingChildren
     * @return array{created_ids:list<int>,skipped:list<array{name:string,reason:string}>}
     */
    private function ingestZfoAttachments(
        array $attachments,
        int $supplierId,
        ?int $folderId,
        ?int $userId,
        int $containerId,
        array $existingChildren = [],
    ): array {
        $created = [];
        $skipped = [];
        $known = [];
        /** @var array<string,int> $byBaseName */
        $byBaseName = [];
        /** @var array<int,string> $p7sChildren */
        $p7sChildren = [];

        foreach ($existingChildren as $child) {
            $name = (string) ($child['original_name'] ?? '');
            $known[strtolower($name) . "\0" . (string) ($child['sha256'] ?? '')] = true;
            $base = pathinfo($name, PATHINFO_FILENAME);
            $childId = (int) ($child['id'] ?? 0);
            if ($childId > 0) {
                $byBaseName[$base] = $childId;
                if (($child['doc_type'] ?? '') === 'p7s') {
                    $p7sChildren[$childId] = $base;
                }
            }
        }

        foreach ($attachments as $attachment) {
            $key = strtolower($attachment['name']) . "\0" . hash('sha256', $attachment['bytes']);
            if (isset($known[$key])) {
                continue;
            }
            try {
                $stored = $this->storage->storeZfoAttachmentFromBytes(
                    $attachment['bytes'],
                    $supplierId,
                    $attachment['name'],
                    $attachment['mime'],
                );
            } catch (DocumentException $e) {
                $skipped[] = ['name' => $attachment['name'], 'reason' => $e->errorCode];
                continue;
            }
            $childId = $this->insertAndProcess(
                $stored,
                $supplierId,
                $folderId,
                $attachment['name'],
                $userId,
                'zfo_extract',
                $containerId,
            );
            $created[] = $childId;
            $known[$key] = true;

            $base = pathinfo($attachment['name'], PATHINFO_FILENAME);
            $byBaseName[$base] = $childId;
            if ($stored['doc_type'] === 'p7s') {
                $p7sChildren[$childId] = $base;
            }
        }

        foreach ($p7sChildren as $signatureId => $base) {
            if (isset($byBaseName[$base]) && $byBaseName[$base] !== $signatureId) {
                $this->documents->setSignatureFor($signatureId, $byBaseName[$base]);
            }
        }

        return ['created_ids' => $created, 'skipped' => $skipped];
    }
}
