<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\DocumentRepository;

/**
 * Bezpečné ukládání souborů sekce Dokumenty na disk.
 *
 * Layout (content-addressed): storage/documents/sup-{supplierId}/{sha[0:2]}/{sha256}
 *  - na disku JEN hash → žádný uživatelský vstup v cestě, exaktní dedup +
 *    korektní dedup-aware mazání; původní název žije v DB (original_name),
 *  - sanitizace názvu (sanitizeFilename) se používá pro zobrazení/ZIP entries,
 *  - MIME z obsahu (finfo), blocklist nebezpečných přípon + MIME (jinak vše projde
 *    jako doc_type 'other' — sekce Dokumenty je obecné úložiště, ne jen faktury),
 *  - path-traversal guard (cílová cesta musí ležet uvnitř sup-{id} kořene).
 *
 * Náhledy (thumbnaily) leží v storage/documents/sup-{id}/_thumbs/{sha8}.webp.
 */
final class DocumentStorage
{
    /** Tvrdý strop nezávisle na configu (anti-DoS). */
    private const ABSOLUTE_MAX_BYTES = 500 * 1024 * 1024;
    private const DEFAULT_MAX_BYTES   = 50 * 1024 * 1024;

    /**
     * Známé přípony → konkrétní typ dokumentu (enum documents.doc_type). NENÍ to
     * whitelist — cokoliv, co tu není a není v DANGEROUS_EXT, se uloží jako 'other'
     * (např. bankovní výpisy .gpc/.abo, .json, .log, …). Sekce Dokumenty je obecné
     * úložiště, ne jen na faktury.
     */
    private const EXT_TO_TYPE = [
        'pdf'  => 'pdf',
        'doc'  => 'docx', 'docx' => 'docx', 'rtf' => 'other', 'odt' => 'other',
        'xls'  => 'xlsx', 'xlsx' => 'xlsx', 'ods' => 'other', 'csv' => 'other',
        'ppt'  => 'other', 'pptx' => 'other', 'odp' => 'other',
        'xml'  => 'xml', 'isdoc' => 'xml', 'isdocx' => 'xml',
        'zfo'  => 'zfo',
        'p7s'  => 'p7s', 'p7m' => 'p7s', 'asice' => 'p7s', 'asics' => 'p7s',
        'zip'  => 'zip',
        'txt'  => 'other', 'md' => 'other', 'eml' => 'other',
        'jpg'  => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'webp' => 'image', 'heic' => 'image', 'heif' => 'image', 'bmp' => 'image',
        'tif'  => 'image', 'tiff' => 'image',
    ];

    /**
     * Nebezpečné přípony, které nikdy nepřijmeme (spustitelné soubory a skripty).
     * Blocklist — úložiště je obecné, ale spustitelný kód do něj nepatří. Servírování
     * je sice vždy `attachment` + `nosniff` (DocumentFileAction), tohle je obrana navíc.
     */
    private const DANGEROUS_EXT = [
        // Windows spustitelné / instalační
        'exe', 'msi', 'msix', 'msp', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'drv',
        'bat', 'cmd', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta', 'lnk', 'scf',
        'reg', 'inf', 'msc', 'gadget', 'ps1', 'psm1', 'psd1',
        // Unix / macOS spustitelné
        'sh', 'bash', 'zsh', 'csh', 'ksh', 'app', 'command',
        'deb', 'rpm', 'dmg', 'pkg', 'apk',
        // Serverové skripty (i kdyby se úložiště omylem servírovalo přes interpreter)
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'pm', 'py', 'pyc', 'pyo',
        'rb', 'jar', 'htaccess',
    ];

    /** MIME, které nikdy nepřijmeme (i kdyby přípona vypadala neškodně). */
    private const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable',
        'application/x-mach-binary', 'application/x-elf',
        'application/x-sh', 'application/x-shellscript', 'application/x-csh',
        'application/javascript', 'text/javascript', 'application/x-javascript',
        'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'application/x-msdos-program', 'application/vnd.microsoft.portable-executable',
    ];

    public function __construct(private readonly Config $config) {}

    public function maxFileBytes(): int
    {
        $v = (int) $this->config->get('documents.max_file_bytes', self::DEFAULT_MAX_BYTES);
        if ($v <= 0) $v = self::DEFAULT_MAX_BYTES;
        return min($v, self::ABSOLUTE_MAX_BYTES);
    }

    public static function baseDir(int $supplierId): string
    {
        return RuntimePaths::storage('documents') . '/sup-' . $supplierId;
    }

    public static function thumbsDir(int $supplierId): string
    {
        return self::baseDir($supplierId) . '/_thumbs';
    }

    public function dirFor(int $supplierId, string $sha256): string
    {
        return self::baseDir($supplierId) . '/' . substr($sha256, 0, 2);
    }

    public function pathFor(int $supplierId, string $sha256, string $filename): string
    {
        return $this->dirFor($supplierId, $sha256) . '/' . $filename;
    }

    /**
     * Klasifikuje příponu+MIME na doc_type. Blacklist přístup: nebezpečné přípony
     * a MIME odmítne (DocumentException), vše ostatní projde — známé přípony dostanou
     * konkrétní typ, zbytek 'other'.
     */
    public function classify(string $ext, string $detectedMime): string
    {
        $ext = strtolower($ext);
        $mime = strtolower(trim($detectedMime));

        if (in_array($mime, self::DANGEROUS_MIME, true)) {
            throw new DocumentException('unsupported_type', 'Tento typ souboru není povolen.', 415);
        }
        if (in_array($ext, self::DANGEROUS_EXT, true)) {
            throw new DocumentException('executable_blocked',
                'Spustitelné soubory nelze nahrát (.' . $ext . ').', 415);
        }
        return self::EXT_TO_TYPE[$ext] ?? 'other';
    }

    /**
     * Uloží soubor z dočasné cesty na disk (přesune/zkopíruje), validuje politiku.
     *
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    public function storeFromTemp(string $tmpPath, int $supplierId, string $originalName): array
    {
        return $this->storeFromTempWithPolicy($tmpPath, $supplierId, $originalName, false);
    }

    /**
     * Uloží přílohu vytaženou ze ZFO. HTML smí projít pouze touto úzkou cestou
     * a v DB dostane generický MIME; download endpoint je vždy vydává jako
     * `attachment` + `nosniff`, takže se v původu aplikace nevykreslí.
     *
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    public function storeZfoAttachmentFromBytes(
        string $bytes,
        int $supplierId,
        string $originalName,
        string $declaredMime,
    ): array {
        $tmp = $this->tmpPath($supplierId);
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new DocumentException('store_failed', 'Nepodařilo se zapsat dočasný soubor.', 500);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowDownloadOnlyHtml = in_array($ext, ['html', 'htm'], true)
            && in_array(strtolower(trim($declaredMime)), ['text/html', 'application/xhtml+xml'], true);

        return $this->storeFromTempWithPolicy($tmp, $supplierId, $originalName, $allowDownloadOnlyHtml);
    }

    /**
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    private function storeFromTempWithPolicy(
        string $tmpPath,
        int $supplierId,
        string $originalName,
        bool $allowDownloadOnlyHtml,
    ): array
    {
        if (!is_file($tmpPath)) {
            throw new DocumentException('move_failed', 'Dočasný soubor nenalezen.', 500);
        }
        $size = (int) filesize($tmpPath);
        if ($size <= 0) {
            @unlink($tmpPath);
            throw new DocumentException('empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > $this->maxFileBytes()) {
            @unlink($tmpPath);
            throw new DocumentException('file_too_large',
                'Soubor je příliš velký (max ' . (int) ($this->maxFileBytes() / 1024 / 1024) . ' MiB).', 413);
        }

        $detectedMime = $this->detectMime($tmpPath);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $downloadOnlyHtml = $allowDownloadOnlyHtml
            && in_array($ext, ['html', 'htm'], true)
            && in_array(strtolower($detectedMime), ['text/html', 'application/xhtml+xml'], true);

        try {
            $docType = $downloadOnlyHtml ? 'other' : $this->classify($ext, $detectedMime);
        } catch (DocumentException $e) {
            @unlink($tmpPath);
            throw $e;
        }

        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            @unlink($tmpPath);
            throw new DocumentException('hash_failed', 'Nepodařilo se spočítat hash souboru.', 500);
        }

        // Content-addressed: na disk jen hash, žádný uživatelský vstup v cestě.
        // Původní (i nebezpečný) název žije pouze v DB jako original_name.
        $diskName = $sha256;

        $dir = $this->dirFor($supplierId, $sha256);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            @unlink($tmpPath);
            throw new DocumentException('storage_not_writable', 'Úložiště dokumentů není zapisovatelné.', 500);
        }
        $diskPath = $dir . '/' . $diskName;
        $this->assertInsideBase($supplierId, $diskPath);

        if (is_file($diskPath)) {
            // Stejný obsah už existuje (dedup) — zahoď temp.
            @unlink($tmpPath);
        } elseif (!@rename($tmpPath, $diskPath)) {
            if (!@copy($tmpPath, $diskPath)) {
                @unlink($tmpPath);
                throw new DocumentException('store_failed', 'Nepodařilo se uložit soubor na disk.', 500);
            }
            @unlink($tmpPath);
        }

        return [
            'sha256'     => $sha256,
            'filename'   => $diskName,
            'size_bytes' => $size,
            'mime_type'  => $downloadOnlyHtml
                ? 'application/octet-stream'
                : ($detectedMime !== '' ? $detectedMime : 'application/octet-stream'),
            'doc_type'   => $docType,
            'abs_path'   => $diskPath,
            'ext'        => $ext,
        ];
    }

    /**
     * Uloží soubor ze surových bytů (přílohy ZFO / entries ZIP).
     * @return array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string}
     */
    public function storeFromBytes(string $bytes, int $supplierId, string $originalName): array
    {
        $tmp = $this->tmpPath($supplierId);
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new DocumentException('store_failed', 'Nepodařilo se zapsat dočasný soubor.', 500);
        }
        return $this->storeFromTemp($tmp, $supplierId, $originalName);
    }

    /** Vytvoří zapisovatelnou dočasnou cestu uvnitř sup-{id} kořene. */
    public function tmpPath(int $supplierId): string
    {
        $base = self::baseDir($supplierId);
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            throw new DocumentException('storage_not_writable', 'Úložiště dokumentů není zapisovatelné.', 500);
        }
        return $base . '/.tmp-' . bin2hex(random_bytes(8));
    }

    /**
     * Smaže fyzický soubor + náhled, ale jen pokud na sha256 neukazuje žádný jiný
     * záznam dodavatele (dedup-aware). $excludeIds = id právě mazaných dokumentů.
     */
    public function deleteIfOrphan(
        int $supplierId,
        string $sha256,
        string $filename,
        ?string $thumbPath,
        DocumentRepository $repo,
        array $excludeIds,
        array $excludeFileIds = [],
    ): void {
        // Union ref-counting (documents + document_files) — bajt (i náhled) odpojíme jen
        // když na sha neukazuje ani jeden z obou subsystémů A (§4.4). Náhled je pojmenovaný
        // podle sha, takže ho smíme smazat JEN když jsou orphan i samotné bajty — jinak by
        // přeživší doklad se stejným sha přišel o náhled.
        if ($repo->countBySha($supplierId, $sha256, $excludeIds, $excludeFileIds) === 0) {
            $path = $this->pathFor($supplierId, $sha256, $filename);
            if (is_file($path)) @unlink($path);
            if ($thumbPath !== null && $thumbPath !== '') {
                $abs = self::thumbsDir($supplierId) . '/' . basename($thumbPath);
                if (is_file($abs)) @unlink($abs);
            }
        }
    }

    /**
     * Smaže prázdné podadresáře pod sup-{id} (sha-shardy / _thumbs), které zůstanou
     * po vysypání koše. Volat po dedup-aware mazání souborů.
     */
    public function pruneEmptyDirs(int $supplierId): void
    {
        $base = self::baseDir($supplierId);
        if (!is_dir($base)) return;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $entries = @scandir($dir);
            if ($entries !== false && count(array_diff($entries, ['.', '..'])) === 0) {
                @rmdir($dir);
            }
        }
    }

    public function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                // PHP 8.5: finfo objekt se uvolní automaticky (finfo_close je deprecated).
                $m = (string) finfo_file($finfo, $path);
                if ($m !== '') return $m;
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Sanitizuje uživatelský filename pro bezpečné uložení (odstraní path
     * separators, control chars, vedoucí tečky; zachová unicode).
     */
    public function sanitizeFilename(string $name): string
    {
        $name = basename($name);                                  // utne adresářovou část
        $name = (string) preg_replace('/[\\\\\/]+/', '_', $name);
        $name = (string) preg_replace('/[\x00-\x1F"<>|*?:]/', '_', $name);
        // Unicode bidi/zero-width formátovací znaky (anti spoofing názvů: RTL override apod.)
        $name = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);
        $name = ltrim($name, '.');                                // žádné skryté/.. soubory
        $name = trim($name, ' _');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document';
        }
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = substr($base, 0, 190) . ($ext !== '' ? '.' . substr($ext, 0, 9) : '');
        }
        return $name;
    }

    /**
     * Path-traversal guard: cílová cesta musí ležet uvnitř sup-{id} kořene.
     * Na Windows porovnáváme case-insensitive (realpath casing je nekonzistentní).
     */
    private function assertInsideBase(int $supplierId, string $target): void
    {
        $base = self::baseDir($supplierId);
        $baseReal = realpath($base) ?: $base;
        $targetReal = realpath(dirname($target)) ?: dirname($target);
        $b = rtrim(str_replace('\\', '/', $baseReal), '/');
        $t = rtrim(str_replace('\\', '/', $targetReal), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $b = strtolower($b);
            $t = strtolower($t);
        }
        if ($t !== $b && !str_starts_with($t . '/', $b . '/')) {
            throw new DocumentException('path_traversal', 'Neplatná cílová cesta.', 400);
        }
    }
}
