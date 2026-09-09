<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use MyInvoice\Service\Export\ExportFilename;
use PDO;

/**
 * Překládá interní content-addressed úložiště dokumentů do čitelné struktury ZIPu.
 *
 * Fyzický disk zůstává bezpečně pojmenovaný SHA-256, ale archiv není implementační
 * detail aplikace: dokumenty jsou podle složky a původního názvu, přílohy deníku
 * podle zápisu a názvu. Sjednocuje tak export firmy i provozní zálohu dokumentů.
 */
final class ReadableDocumentArchiveLayout
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param null|callable(array{table:string,id:?int,reason:string}):void $onWarning
     * @return list<array{source:string,entry:string,storage_path:string,sha256:?string,kind:string}>
     */
    public function forSupplier(int $supplierId, string $documentsPrefix, string $journalPrefix, ?callable $onWarning = null): array
    {
        $usedEntries = [];
        $knownSources = [];
        $items = [];

        foreach ($this->documentFiles($supplierId, $documentsPrefix, $usedEntries, $knownSources, $onWarning) as $item) {
            $items[] = $item;
        }
        foreach ($this->journalFiles($supplierId, $journalPrefix, $usedEntries, $knownSources, $onWarning) as $item) {
            $items[] = $item;
        }
        foreach ($this->orphanedFiles($supplierId, $documentsPrefix, $journalPrefix, $usedEntries, $knownSources) as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Pro provozní zálohu všech firem. Prefixy jsou logické cesty uvnitř ZIPu.
     *
     * @return list<array{source:string,entry:string,storage_path:string,sha256:?string,kind:string}>
     */
    public function all(string $documentsPrefix = 'storage/documents', string $journalPrefix = 'storage/journal'): array
    {
        $supplierIds = [];
        foreach ($this->pdo->query(
            'SELECT DISTINCT supplier_id FROM documents
             UNION SELECT DISTINCT supplier_id FROM journal_entry_attachments'
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $supplierIds[(int) $id] = true;
        }

        foreach ([RuntimePaths::storage('documents'), RuntimePaths::storage('journal')] as $base) {
            foreach (glob($base . '/sup-*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (preg_match('/\/sup-(\d+)$/', str_replace('\\', '/', $dir), $match)) {
                    $supplierIds[(int) $match[1]] = true;
                }
            }
        }

        $items = [];
        foreach (array_keys($supplierIds) as $supplierId) {
            foreach ($this->forSupplier(
                $supplierId,
                $documentsPrefix . '/sup-' . $supplierId,
                $journalPrefix . '/sup-' . $supplierId,
            ) as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @param array<string, true> $usedEntries
     * @param array<string, true> $knownSources
     * @return list<array{source:string,entry:string,storage_path:string,sha256:?string,kind:string}>
     */
    private function documentFiles(int $supplierId, string $prefix, array &$usedEntries, array &$knownSources, ?callable $onWarning): array
    {
        try {
            $folders = $this->folders($supplierId);
            $stmt = $this->pdo->prepare(
                'SELECT d.id AS document_id, d.folder_id, d.title, d.original_name, d.filename, d.sha256, d.deleted_at,
                        df.id AS file_id, df.role AS file_role, df.original_name AS file_original_name,
                        df.filename AS file_filename, df.sha256 AS file_sha256
                   FROM documents d
              LEFT JOIN document_files df
                     ON df.document_id = d.id AND df.supplier_id = d.supplier_id AND df.deleted_at IS NULL
                  WHERE d.supplier_id = ?
               ORDER BY d.id, df.sort_order, df.id'
            );
            $stmt->execute([$supplierId]);
        } catch (\Throwable) {
            if ($onWarning !== null) {
                $onWarning(['table' => 'documents', 'id' => null, 'reason' => 'source_query_failed']);
            }
            return [];
        }

        $items = [];
        $primarySeen = [];
        $reported = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documentId = (int) $row['document_id'];
            $candidates = [[
                'id' => $documentId,
                'table' => 'documents',
                'sha' => (string) ($row['sha256'] ?? ''),
                'filename' => (string) ($row['filename'] ?? ''),
                'name' => (string) (($row['original_name'] ?? '') ?: ($row['title'] ?? '')),
                'is_primary' => true,
            ]];
            if (($row['file_id'] ?? null) !== null) {
                $candidates[] = [
                    'id' => (int) $row['file_id'],
                    'table' => 'document_files',
                    'sha' => (string) ($row['file_sha256'] ?? ''),
                    'filename' => (string) ($row['file_filename'] ?? ''),
                    'name' => (string) (($row['file_original_name'] ?? '') ?: ($row['original_name'] ?? '') ?: ($row['title'] ?? '')),
                    'is_primary' => (string) ($row['file_role'] ?? '') === 'primary',
                ];
            }

            foreach ($candidates as $candidate) {
                $sha = $candidate['sha'];
                $filename = $candidate['filename'];
                if ($sha === '' || $filename === '') {
                    $warningKey = $candidate['table'] . ':' . $candidate['id'];
                    if (!isset($reported[$warningKey])) {
                        if ($onWarning !== null) {
                            $onWarning(['table' => $candidate['table'], 'id' => $candidate['id'], 'reason' => 'incomplete_source_metadata']);
                        }
                        $reported[$warningKey] = true;
                    }
                    continue;
                }
                $key = $documentId . ':' . $sha;
                if ($candidate['is_primary'] && isset($primarySeen[$key])) {
                    continue;
                }
                $primarySeen[$key] = true;

                $source = DocumentStorage::baseDir($supplierId) . '/' . substr($sha, 0, 2) . '/' . $filename;
                if (!is_file($source)) {
                    $warningKey = $candidate['table'] . ':' . $candidate['id'];
                    if (!isset($reported[$warningKey])) {
                        if ($onWarning !== null) {
                            $onWarning(['table' => $candidate['table'], 'id' => $candidate['id'], 'reason' => 'missing_source_file']);
                        }
                        $reported[$warningKey] = true;
                    }
                    continue;
                }
                $relative = $this->folderPath($folders, $row['folder_id'] === null ? null : (int) $row['folder_id']);
                if ($row['deleted_at'] !== null) {
                    $relative = 'Kos/' . $relative;
                }
                $entry = $this->uniqueEntry(
                    $prefix . '/' . $relative . '/' . $this->safeName($candidate['name'], 'dokument-' . $candidate['id']),
                    $candidate['id'],
                    $usedEntries,
                );
                $knownSources[$this->sourceKey($source)] = true;
                $items[] = [
                    'source' => $source,
                    'entry' => $entry,
                    'storage_path' => 'documents/sup-' . $supplierId . '/' . substr($sha, 0, 2) . '/' . $filename,
                    'sha256' => $sha,
                    'kind' => 'document',
                ];
            }
        }
        return $items;
    }

    /**
     * @param array<string, true> $usedEntries
     * @param array<string, true> $knownSources
     * @return list<array{source:string,entry:string,storage_path:string,sha256:?string,kind:string}>
     */
    private function journalFiles(int $supplierId, string $prefix, array &$usedEntries, array &$knownSources, ?callable $onWarning): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, entry_id, sha256, filename, original_name
                   FROM journal_entry_attachments
                  WHERE supplier_id = ?
               ORDER BY entry_id, id'
            );
            $stmt->execute([$supplierId]);
        } catch (\Throwable) {
            if ($onWarning !== null) {
                $onWarning(['table' => 'journal_entry_attachments', 'id' => null, 'reason' => 'source_query_failed']);
            }
            return [];
        }

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sha = (string) ($row['sha256'] ?? '');
            $filename = (string) ($row['filename'] ?? '');
            if ($sha === '' || $filename === '') {
                if ($onWarning !== null) {
                    $onWarning(['table' => 'journal_entry_attachments', 'id' => (int) $row['id'], 'reason' => 'incomplete_source_metadata']);
                }
                continue;
            }
            $source = JournalAttachmentStorage::baseDir($supplierId) . '/' . substr($sha, 0, 2) . '/' . $filename;
            if (!is_file($source)) {
                if ($onWarning !== null) {
                    $onWarning(['table' => 'journal_entry_attachments', 'id' => (int) $row['id'], 'reason' => 'missing_source_file']);
                }
                continue;
            }
            $id = (int) $row['id'];
            $entry = $this->uniqueEntry(
                $prefix . '/zapis-' . (int) $row['entry_id'] . '/'
                . $this->safeName((string) ($row['original_name'] ?? ''), 'priloha-' . $id),
                $id,
                $usedEntries,
            );
            $knownSources[$this->sourceKey($source)] = true;
            $items[] = [
                'source' => $source,
                'entry' => $entry,
                'storage_path' => 'journal/sup-' . $supplierId . '/' . substr($sha, 0, 2) . '/' . $filename,
                'sha256' => $sha,
                'kind' => 'journal_attachment',
            ];
        }
        return $items;
    }

    /**
     * @param array<string, true> $usedEntries
     * @param array<string, true> $knownSources
     * @return list<array{source:string,entry:string,storage_path:string,sha256:?string,kind:string}>
     */
    private function orphanedFiles(int $supplierId, string $documentsPrefix, string $journalPrefix, array &$usedEntries, array $knownSources): array
    {
        $items = [];
        foreach ([[DocumentStorage::baseDir($supplierId), $documentsPrefix, 'documents'], [JournalAttachmentStorage::baseDir($supplierId), $journalPrefix, 'journal']] as [$sourceDir, $prefix, $namespace]) {
            foreach (BackupFileCollector::collect([[$sourceDir, null, $prefix]], ['/_thumbs/', '/_jobs/'], ['.tmp-']) as $source => $entry) {
                if (isset($knownSources[$this->sourceKey($source)])) {
                    continue;
                }
                $entry = $this->uniqueEntry(str_replace($prefix . '/', $prefix . '/_neprirazene/', $entry), crc32($source), $usedEntries);
                $items[] = [
                    'source' => $source,
                    'entry' => $entry,
                    'storage_path' => $namespace . '/sup-' . $supplierId . '/' . ltrim(str_replace('\\', '/', substr($source, strlen($sourceDir))), '/'),
                    'sha256' => null,
                    'kind' => 'orphaned_file',
                ];
            }
        }
        return $items;
    }

    /** @return array<int, array{parent_id:?int,name:string,deleted:bool}> */
    private function folders(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, parent_id, name, deleted_at FROM document_folders WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $folders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $folders[(int) $row['id']] = [
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'name' => $this->safeName((string) ($row['name'] ?? ''), 'slozka-' . $row['id']),
                'deleted' => $row['deleted_at'] !== null,
            ];
        }
        return $folders;
    }

    /** @param array<int, array{parent_id:?int,name:string,deleted:bool}> $folders */
    private function folderPath(array $folders, ?int $folderId): string
    {
        if ($folderId === null || !isset($folders[$folderId])) {
            return 'Nezarazene';
        }
        $parts = [];
        $seen = [];
        while ($folderId !== null && isset($folders[$folderId]) && !isset($seen[$folderId])) {
            $seen[$folderId] = true;
            $folder = $folders[$folderId];
            array_unshift($parts, $folder['name']);
            $folderId = $folder['parent_id'];
        }
        return implode('/', $parts ?: ['Nezarazene']);
    }

    /** @param array<string, true> $usedEntries */
    private function uniqueEntry(string $entry, int $id, array &$usedEntries): string
    {
        $entry = str_replace('\\', '/', $entry);
        $candidate = $entry;
        $n = 1;
        while (isset($usedEntries[strtolower($candidate)])) {
            $extension = pathinfo($entry, PATHINFO_EXTENSION);
            $base = $extension === '' ? $entry : substr($entry, 0, -(strlen($extension) + 1));
            $candidate = $base . '-' . $id . ($n > 1 ? '-' . $n : '') . ($extension === '' ? '' : '.' . $extension);
            $n++;
        }
        $usedEntries[strtolower($candidate)] = true;
        return $candidate;
    }

    private function safeName(string $name, string $fallback): string
    {
        return ExportFilename::sanitize(basename($name), $fallback);
    }

    private function sourceKey(string $source): string
    {
        return strtolower(str_replace('\\', '/', $source));
    }
}
