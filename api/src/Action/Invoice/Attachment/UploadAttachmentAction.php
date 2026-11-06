<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice\Attachment;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/invoices/{id}/attachments — multipart upload jednoho nebo více
 * souborů (field `file` / `file[]`).
 *
 * Limity:
 *   - max 10 MiB per soubor
 *   - max 20 MiB celkem na fakturu (sum přes všechny attachmenty)
 *   - whitelist MIME (PDF, běžné Office formáty, obrázky, prostý text)
 */
final class UploadAttachmentAction
{
    private const MAX_FILE_SIZE  = 10 * 1024 * 1024;
    private const MAX_TOTAL_SIZE = 20 * 1024 * 1024;

    /** @var array<string, list<string>>  MIME → povolené přípony (lower) */
    private const ALLOWED_MIME = [
        'application/pdf' => ['pdf'],
        // MS Office (modern + legacy)
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/msword'      => ['doc'],
        'application/vnd.ms-excel'      => ['xls'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        // OpenDocument
        'application/vnd.oasis.opendocument.text'         => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet'  => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'],
        // Text / CSV
        'text/plain' => ['txt', 'csv'],
        'text/csv'   => ['csv'],
        // Obrázky
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/gif'  => ['gif'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        // ZIP (běžně použitý kontejner — třeba balík fotek)
        'application/zip' => ['zip'],
    ];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Storno se klientovi neposílá — přílohy zde nemají smysl.
        if (($invoice['invoice_type'] ?? '') === 'cancellation') {
            return Json::error($response, 'unsupported_type', 'K internímu stornu nelze přidat přílohy.', 400);
        }

        $files = $request->getUploadedFiles();
        $list = [];
        if (isset($files['file'])) {
            $list = is_array($files['file']) ? $files['file'] : [$files['file']];
        }
        if (empty($list)) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }

        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $totalSoFar = $this->attachments->totalSize($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $created = [];
        foreach ($list as $file) {
            if (!$file instanceof UploadedFileInterface) continue;
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
            }

            $size = (int) ($file->getSize() ?? 0);
            if ($size <= 0) {
                return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
            }
            if ($size > self::MAX_FILE_SIZE) {
                return Json::error($response, 'file_too_large',
                    'Soubor je příliš velký (max ' . (int) (self::MAX_FILE_SIZE / 1024 / 1024) . ' MiB).', 413);
            }
            if ($totalSoFar + $size > self::MAX_TOTAL_SIZE) {
                return Json::error($response, 'total_too_large',
                    'Překročen celkový limit příloh (max ' . (int) (self::MAX_TOTAL_SIZE / 1024 / 1024) . ' MiB).', 413);
            }

            $originalName = trim((string) $file->getClientFilename());
            if ($originalName === '') {
                return Json::error($response, 'no_filename', 'Chybí název souboru.', 400);
            }

            // Cíl: připrav adresář předem (Slim moveTo() na non-CLI SAPI volá
            // move_uploaded_file(), která vyžaduje writable target dir — proto
            // nemůžeme používat sys_get_temp_dir, který někdy na IIS hlásí
            // not-writable, a přesouváme rovnou do finálního adresáře pod
            // dočasný název).
            $dir = InvoiceAttachmentRepository::dirFor($supplierId, $id);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_writable($dir)) {
                return Json::internalError($response, 'storage_not_writable',
                    'Adresář pro přílohy není zapisovatelný: ' . $dir, 500);
            }

            $tmpPath = $dir . '/.tmp-' . bin2hex(random_bytes(8));
            try {
                $file->moveTo($tmpPath);
            } catch (\Throwable $e) {
                return Json::internalError($response, 'move_failed',
                    'Nepodařilo se přesunout nahraný soubor: ' . $e->getMessage(), 500);
            }

            // Detekuj reálné MIME z obsahu (klient-side typ je nedůvěryhodný)
            $detectedMime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detectedMime = (string) finfo_file($finfo, $tmpPath);
                }
            }
            if ($detectedMime === '') {
                $detectedMime = (string) $file->getClientMediaType();
            }

            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExts = self::ALLOWED_MIME[$detectedMime] ?? null;
            if ($allowedExts === null) {
                @unlink($tmpPath);
                return Json::error($response, 'unsupported_type',
                    'Nepodporovaný formát souboru (' . $detectedMime . ').', 415);
            }
            if (!in_array($ext, $allowedExts, true)) {
                @unlink($tmpPath);
                return Json::error($response, 'extension_mismatch',
                    'Přípona souboru neodpovídá obsahu (.' . $ext . ' vs ' . $detectedMime . ').', 400);
            }

            $sha256 = hash_file('sha256', $tmpPath);
            if ($sha256 === false) {
                @unlink($tmpPath);
                return Json::error($response, 'hash_failed', 'Nepodařilo se spočítat hash souboru.', 500);
            }

            $safeName = $this->sanitizeFilename($originalName);
            $diskName = substr($sha256, 0, 8) . '-' . $safeName;
            $diskPath = $dir . '/' . $diskName;

            // Pokud už existuje soubor se stejným hash+jménem, nepřepisuj — stejný obsah.
            if (is_file($diskPath)) {
                @unlink($tmpPath);
            } else {
                if (!@rename($tmpPath, $diskPath)) {
                    if (!@copy($tmpPath, $diskPath)) {
                        @unlink($tmpPath);
                        return Json::error($response, 'store_failed', 'Nepodařilo se uložit soubor na disk.', 500);
                    }
                    @unlink($tmpPath);
                }
            }

            $attId = $this->attachments->insert(
                $id,
                $diskName,
                $originalName,
                $size,
                $sha256,
                $detectedMime,
                $userId,
            );
            $totalSoFar += $size;

            $this->logger->log('invoice.attachment_uploaded', $userId, 'invoice', $id, [
                'attachment_id' => $attId,
                'original_name' => $originalName,
                'size_bytes'    => $size,
                'mime_type'     => $detectedMime,
            ], $ip, $request->getHeaderLine('User-Agent'));

            $created[] = $attId;
        }

        return Json::ok($response, [
            'created'    => $created,
            'items'      => $this->attachments->listForInvoice($id),
            'total_size' => $totalSoFar,
        ]);
    }

    /**
     * Sanitizuje uživatelský filename pro bezpečné uložení na disku
     * (odstraní path separators a problematické znaky, zachová unicode).
     */
    private function sanitizeFilename(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/', '_', $name);
        $name = (string) preg_replace('/[\x00-\x1F"<>|*?:]/', '_', $name);
        $name = trim($name, ". _");
        if ($name === '') {
            $name = 'attachment';
        }
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = substr($base, 0, 190) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }
}
