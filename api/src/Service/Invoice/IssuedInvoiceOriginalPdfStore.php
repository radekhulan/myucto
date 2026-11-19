<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use MyInvoice\Support\PdfBytes;

/**
 * Originál PDF vydané faktury (`invoices.imported_pdf_*`) — jediné místo, které ho
 * ukládá, nahrazuje, odebírá a hledá na disku. Volají ho importy z iDokladu a Fakturoidu
 * i ruční nahrání z detailu faktury, takže soubor leží vždy ve stejném úložišti
 * a ve stejném hash-shard layoutu ({@see PurchaseInvoicePdfArchiver::shardedRelPath()}).
 *
 * Úložiště je content-addressed: stejný obsah u dvou faktur téže firmy sdílí jeden
 * soubor. Nahrazený nebo odebraný soubor se proto maže, až když na něj žádná faktura
 * firmy neukazuje, a vždy až po úspěšném zápisu nového stavu do databáze.
 *
 * Originál nemění stav, částky ani zaúčtování dokladu; je to jen archivovaná kopie.
 */
final class IssuedInvoiceOriginalPdfStore
{
    /** Stejný strop jako ruční nahrání PDF přijaté faktury. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Kořen úložiště originálů vydaných faktur: `invoice.import_archive_storage`,
     * jinak sourozenec `storage.uploads_dir`, jinak runtime storage.
     */
    public function archiveRoot(): string
    {
        $dir = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/invoices-imported';
        }
        return RuntimePaths::storage('invoices-imported');
    }

    /** Bajty jsou PDF (hlavička `%PDF-` v prvních 1024 bajtech) a vejdou se do limitu. */
    public static function isStorablePdf(string $bytes): bool
    {
        $len = strlen($bytes);
        return $len >= 5 && $len <= self::MAX_BYTES && PdfBytes::isPdf($bytes);
    }

    /**
     * Uloží originál k faktuře firmy a nahradí předchozí. Starý soubor smaže až po
     * zápisu nových metadat, a jen když ho nesdílí jiná faktura firmy.
     *
     * Vrací null, když bajty nejsou PDF, faktura firmě nepatří nebo se soubor nepodařilo
     * zapsat; databáze se v tu chvíli nemění.
     *
     * @return array{imported_pdf_path:string, imported_pdf_hash:string, imported_pdf_size_bytes:int,
     *               imported_pdf_original_name:string, replaced:bool}|null
     */
    public function store(int $invoiceId, int $supplierId, string $bytes, string $originalName): ?array
    {
        if (!self::isStorablePdf($bytes)) {
            return null;
        }
        $bytes = PdfBytes::normalize($bytes);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT imported_pdf_path FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $previous = $stmt->fetchColumn();
        if ($previous === false) {
            return null;
        }
        $previous = (string) ($previous ?? '');

        $sha = hash('sha256', $bytes);
        $relPath = PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $absPath = PurchaseInvoicePdfArchiver::ensureShardPath($this->archiveRoot(), $supplierId, $sha);
        if (!$this->writeFile($absPath, $bytes)) {
            return null;
        }

        $name = self::sanitizeName($originalName);
        $size = strlen($bytes);
        $pdo->prepare(
            'UPDATE invoices SET imported_pdf_path = ?, imported_pdf_hash = ?,
                                 imported_pdf_size_bytes = ?, imported_pdf_original_name = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$relPath, $sha, $size, $name, $invoiceId, $supplierId]);

        if ($previous !== '' && $previous !== $relPath) {
            $this->deleteIfUnreferenced($supplierId, $previous);
        }

        return [
            'imported_pdf_path'          => $relPath,
            'imported_pdf_hash'          => $sha,
            'imported_pdf_size_bytes'    => $size,
            'imported_pdf_original_name' => $name,
            'replaced'                   => $previous !== '',
        ];
    }

    /**
     * Odebere originál z faktury firmy. Vrací false, když faktura firmě nepatří nebo
     * originál nemá.
     */
    public function remove(int $invoiceId, int $supplierId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT imported_pdf_path FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $previous = (string) ($stmt->fetchColumn() ?: '');
        if ($previous === '') {
            return false;
        }

        $pdo->prepare(
            'UPDATE invoices SET imported_pdf_path = NULL, imported_pdf_hash = NULL,
                                 imported_pdf_size_bytes = NULL, imported_pdf_original_name = NULL
              WHERE id = ? AND supplier_id = ?'
        )->execute([$invoiceId, $supplierId]);

        $this->deleteIfUnreferenced($supplierId, $previous);
        return true;
    }

    /**
     * Absolutní cesta k uloženému originálu, nebo null, když soubor neexistuje nebo by
     * cesta utekla z kořene úložiště. Porovnání je na Windows case-insensitive, protože
     * realpath() tam vrací nekonzistentní velikost písmen.
     */
    public function resolveAbsolutePath(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }
        $rootReal = realpath($this->archiveRoot());
        if ($rootReal === false) {
            return null;
        }
        $full = realpath($rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($full === false || !is_file($full)) {
            return null;
        }
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = $isWindows ? strtolower($full) : $full;
        $needle = ($isWindows ? strtolower($rootReal) : $rootReal) . DIRECTORY_SEPARATOR;
        return str_starts_with($haystack, $needle) ? $full : null;
    }

    /** Název souboru bez oddělovačů cest a řídicích znaků, vždy s příponou .pdf. */
    public static function sanitizeName(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/', '_', $name);
        $name = (string) preg_replace('/[\x00-\x1F"<>|*?:]/', '_', $name);
        $name = trim($name, '. _');
        if ($name === '') {
            $name = 'invoice.pdf';
        }
        if (!preg_match('/\.pdf$/i', $name)) {
            $name = (string) preg_replace('/\.[^.]{1,5}$/', '', $name) . '.pdf';
        }
        if (strlen($name) > 200) {
            $name = mb_strcut(substr($name, 0, -4), 0, 196, 'UTF-8') . '.pdf';
        }
        return $name;
    }

    /** Zápis přes dočasný soubor, aby čtenář nikdy neviděl rozepsané PDF. */
    private function writeFile(string $absPath, string $bytes): bool
    {
        if (is_file($absPath) && (int) @filesize($absPath) === strlen($bytes)) {
            return true;
        }
        $dir = dirname($absPath);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $absPath)) {
            $copied = @copy($tmp, $absPath);
            @unlink($tmp);
            return $copied;
        }
        return true;
    }

    /**
     * Smaže soubor, na který už žádná faktura firmy neukazuje. Cesta musí ležet ve
     * složce firmy, jinak se nemaže nic (obrana proti podvržené hodnotě ve sloupci).
     */
    private function deleteIfUnreferenced(int $supplierId, string $relPath): void
    {
        if (!str_starts_with($relPath, 'supplier-' . $supplierId . '/') || str_contains($relPath, '..')) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM invoices WHERE supplier_id = ? AND imported_pdf_path = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $relPath]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $abs = $this->resolveAbsolutePath($relPath);
        if ($abs !== null) {
            @unlink($abs);
        }
    }
}
