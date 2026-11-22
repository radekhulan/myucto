<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use Psr\Http\Message\UploadedFileInterface;

final class QuoteFileStorage
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    private const IMAGE_MIME = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
    ];

    private const ALLOWED_MIME = self::IMAGE_MIME + [
        'application/pdf' => ['pdf'],
        'image/webp' => ['webp'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/msword' => ['doc'],
        'application/vnd.ms-excel' => ['xls'],
        'application/zip' => ['zip'],
    ];

    public function __construct(private readonly QuotePdfRenderer $pdf) {}

    /** @return array{name:string,mime_type:string,size_bytes:int,storage_key:string} */
    public function store(UploadedFileInterface $upload, int $supplierId, int $quoteId, string $mode): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Nahrání přílohy nabídky selhalo.');
        }
        if (($upload->getSize() ?? 0) > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Jedna příloha může mít nejvýše 10 MiB.');
        }

        $name = str_replace('\\', '/', (string) $upload->getClientFilename());
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', basename($name)));
        if ($name === '' || strlen($name) > 240 || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Příloha má neplatný název.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = $this->writableDirectory($supplierId, $quoteId);
        $tmp = $dir . '/.tmp-upload-' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            $upload->moveTo($tmp);
            $size = filesize($tmp);
            if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException('Příloha je prázdná nebo překračuje limit 10 MiB.');
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!in_array($ext, self::ALLOWED_MIME[$mime] ?? [], true)) {
                throw new \InvalidArgumentException('Formát souboru není povolený nebo neodpovídá příponě.');
            }
            $this->validateMode($tmp, $mime, $mode);

            $key = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!@rename($tmp, $this->path($supplierId, $quoteId, $key))) {
                throw new \RuntimeException('Přílohu nabídky nelze uložit.');
            }
            return ['name' => $name, 'mime_type' => $mime, 'size_bytes' => $size, 'storage_key' => $key];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function validateMode(string $path, string $mime, string $mode): void
    {
        if (!in_array($mode, ['inline', 'append', 'email', 'internal'], true)) {
            throw new \InvalidArgumentException('Neplatné použití přílohy nabídky.');
        }
        if ($mode === 'inline') {
            $image = isset(self::IMAGE_MIME[$mime]) ? @getimagesize($path) : false;
            if ($image === false || ($image['mime'] ?? null) !== $mime
                || $image[0] < 1 || $image[1] < 1 || $image[0] * $image[1] > 12_000_000) {
                throw new \InvalidArgumentException('Do textu lze vložit obrázek JPG, PNG nebo GIF do 12 megapixelů.');
            }
        }
        if ($mode === 'append') {
            if ($mime !== 'application/pdf') {
                throw new \InvalidArgumentException('Na konec nabídky lze vložit pouze soubor PDF.');
            }
            $this->pdf->validateAppendix($path);
        }
    }

    public function path(int $supplierId, int $quoteId, string $storageKey): string
    {
        if (preg_match('/\A(?:[0-9a-f]{32}\.[a-z0-9]{1,5}|v[1-9][0-9]*-[0-9a-f]{32}\.pdf)\z/D', $storageKey) !== 1) {
            throw new \InvalidArgumentException('Neplatný klíč souboru nabídky.');
        }

        $dir = $this->directory($supplierId, $quoteId);
        $path = $dir . '/' . $storageKey;
        if (is_dir($dir)) {
            $this->assertDirectory($dir, $supplierId, $quoteId);
        }
        $resolved = realpath($path);
        if ($resolved !== false) {
            $base = realpath($dir);
            if ($base === false || strcasecmp(dirname($resolved), $base) !== 0
                || strcasecmp(basename($resolved), $storageKey) !== 0) {
                throw new \RuntimeException('Soubor nabídky neleží v očekávaném adresáři.');
            }
        }

        return $path;
    }

    public function archivePdf(int $supplierId, int $quoteId, int $versionId, string $bytes): string
    {
        if ($versionId < 1 || !str_starts_with($bytes, '%PDF-')) {
            throw new \InvalidArgumentException('Archiv nabídky vyžaduje platnou verzi a PDF.');
        }

        $dir = $this->writableDirectory($supplierId, $quoteId);
        $nonce = bin2hex(random_bytes(16));
        $tmp = $dir . '/.tmp-archive-' . $nonce . '.tmp';
        $key = 'v' . $versionId . '-' . $nonce . '.pdf';
        try {
            if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)
                || !@rename($tmp, $this->path($supplierId, $quoteId, $key))) {
                throw new \RuntimeException('PDF nabídky nelze archivovat.');
            }
            return $key;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function delete(int $supplierId, int $quoteId, string $storageKey): void
    {
        $path = $this->path($supplierId, $quoteId, $storageKey);
        if (is_file($path) && !@unlink($path) && is_file($path)) {
            throw new \RuntimeException('Soubor nabídky nelze odstranit.');
        }
    }

    private function directory(int $supplierId, int $quoteId): string
    {
        if ($supplierId < 1 || $quoteId < 1) {
            throw new \InvalidArgumentException('Firma nebo nabídka nebyla nalezena.');
        }
        return RuntimePaths::storage('offers/sup-' . $supplierId . '/' . $quoteId);
    }

    private function writableDirectory(int $supplierId, int $quoteId): string
    {
        $dir = $this->directory($supplierId, $quoteId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Úložiště nabídek není dostupné.');
        }
        $this->assertDirectory($dir, $supplierId, $quoteId);
        if (!is_writable($dir)) {
            throw new \RuntimeException('Úložiště nabídek není zapisovatelné.');
        }
        return $dir;
    }

    private function assertDirectory(string $dir, int $supplierId, int $quoteId): void
    {
        $root = realpath(RuntimePaths::storage('offers'));
        $actual = realpath($dir);
        $relative = 'sup-' . $supplierId . '/' . $quoteId;
        if ($root === false || $actual === false
            || strcasecmp(str_replace('\\', '/', $actual), rtrim(str_replace('\\', '/', $root), '/') . '/' . $relative) !== 0) {
            throw new \RuntimeException('Adresář nabídky neleží v očekávaném úložišti.');
        }
    }
}
