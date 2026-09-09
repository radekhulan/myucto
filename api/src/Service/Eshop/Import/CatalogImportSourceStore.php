<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\EshopException;
use Psr\Http\Message\UploadedFileInterface;

final class CatalogImportSourceStore
{
    public function __construct(private readonly Connection $db) {}

    public function upload(int $supplierId, UploadedFileInterface $file, ?int $createdBy): array
    {
        $name = basename(str_replace('\\', '/', $file->getClientFilename() ?? ''));
        $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($supplierId < 1 || $file->getError() !== UPLOAD_ERR_OK
            || !in_array($format, ['csv', 'xlsx'], true) || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $name) || ($file->getSize() ?? 0) > CatalogImportReader::MAX_BYTES) {
            throw new EshopException('import_file_invalid', 'Vyberte CSV nebo XLSX do 50 MB.', 422);
        }
        $key = bin2hex(random_bytes(32));
        $directory = RuntimePaths::storage('catalog-import/' . $supplierId);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('import_storage_unavailable');
        }
        $path = $directory . '/' . $key;
        $target = fopen($path, 'xb');
        if ($target === false) {
            throw new \RuntimeException('import_storage_unavailable');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            $source = $file->getStream();
            $source->rewind();
            while (!$source->eof()) {
                $chunk = $source->read(65536);
                $size += strlen($chunk);
                if ($size > CatalogImportReader::MAX_BYTES) {
                    throw new EshopException('import_file_too_large', 'Soubor překračuje 50 MB.', 422);
                }
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('import_storage_failed');
                }
                hash_update($hash, $chunk);
            }
            fclose($target);
            $target = null;
            if ($size === 0) {
                throw new EshopException('import_file_empty', 'Soubor je prázdný.', 422);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $validMime = $format === 'xlsx'
                ? in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)
                : (str_starts_with((string) $mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true));
            if (!$validMime) {
                throw new EshopException('import_file_invalid', 'Obsah neodpovídá CSV nebo XLSX.', 422);
            }
            $stmt = $this->db->pdo()->prepare('INSERT INTO catalog_import_sources
                (supplier_id, storage_key, original_name, format, size_bytes, sha256, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $key, $name, $format, $size, hash_final($hash), $createdBy]);
            return $this->get($supplierId, (int) $this->db->pdo()->lastInsertId());
        } catch (\Throwable $e) {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    public function get(int $supplierId, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, original_name, format, size_bytes, sha256, created_at
            FROM catalog_import_sources WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $sourceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new EshopException('not_found', 'Zdroj importu nenalezen.', 404);
        }
        $row['id'] = (int) $row['id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        return $row;
    }

    public function path(int $supplierId, int $sourceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT storage_key, sha256 FROM catalog_import_sources WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $sourceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || !preg_match('/^[a-f0-9]{64}$/D', $row['storage_key'])) {
            throw new EshopException('not_found', 'Zdroj importu nenalezen.', 404);
        }
        $directory = realpath(RuntimePaths::storage('catalog-import/' . $supplierId));
        $path = $directory === false ? false : realpath($directory . '/' . $row['storage_key']);
        if ($path === false || !str_starts_with(strtolower($path), strtolower($directory . DIRECTORY_SEPARATOR))
            || !is_file($path) || !hash_equals($row['sha256'], hash_file('sha256', $path))) {
            throw new EshopException('import_source_changed', 'Uložený zdroj importu není dostupný nebo byl změněn.', 409);
        }
        return $path;
    }
}
