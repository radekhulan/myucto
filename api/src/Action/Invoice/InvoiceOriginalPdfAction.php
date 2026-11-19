<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedInvoiceOriginalPdfStore;
use MyInvoice\Service\Invoice\OriginalPdfFilenameMatcher;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Ruční správa originálu PDF vydané faktury (`imported_pdf_*`):
 *   POST   /api/invoices/{id}/imported-pdf        nahraje nebo nahradí originál (multipart `file`)
 *   DELETE /api/invoices/{id}/imported-pdf        originál odebere
 *   POST   /api/invoices/imported-pdf/match       spáruje názvy souborů s fakturami firmy (náhled
 *                                                 hromadného nahrání, nic neukládá)
 *
 * Oprávnění je stejné jako u příloh faktury (`invoices`, zápis; hlídá RoutePermissionMap).
 * Originál je jen archivovaná kopie: nemění stav ani zaúčtování dokladu, a proto ho
 * neblokuje zamčené období ani zámek dokladu. Ukládá výhradně
 * {@see IssuedInvoiceOriginalPdfStore}, stejně jako importy z iDokladu a Fakturoidu.
 */
final class InvoiceOriginalPdfAction
{
    /** Strop počtu souborů v jednom náhledu párování. */
    private const MATCH_MAX_FILES = 500;
    /** Strop klíčů v jednom dotazu IN (). */
    private const KEY_CHUNK = 1000;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly IssuedInvoiceOriginalPdfStore $store,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function upload(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $id > 0 ? $this->invoices->find($id) : null;
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Soubor nebyl odeslán (pole file).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size > IssuedInvoiceOriginalPdfStore::MAX_BYTES) {
            return Json::error($response, 'file_too_large',
                'Soubor je příliš velký (max ' . (int) (IssuedInvoiceOriginalPdfStore::MAX_BYTES / 1024 / 1024) . ' MiB).', 413);
        }

        $bytes = (string) $file->getStream();
        if ($bytes === '') {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }
        if (strlen($bytes) > IssuedInvoiceOriginalPdfStore::MAX_BYTES) {
            return Json::error($response, 'file_too_large',
                'Soubor je příliš velký (max ' . (int) (IssuedInvoiceOriginalPdfStore::MAX_BYTES / 1024 / 1024) . ' MiB).', 413);
        }
        if (!IssuedInvoiceOriginalPdfStore::isStorablePdf($bytes)) {
            return Json::error($response, 'invalid_pdf', 'Soubor není PDF dokument.', 422);
        }

        $supplierId = (int) $invoice['supplier_id'];
        $stored = $this->store->store($id, $supplierId, $bytes, (string) $file->getClientFilename());
        if ($stored === null) {
            return Json::internalError($response, 'store_failed', 'Originál se nepodařilo uložit.');
        }

        $this->log($request, 'invoice.imported_pdf_uploaded', $id, [
            'sha256'        => $stored['imported_pdf_hash'],
            'size_bytes'    => $stored['imported_pdf_size_bytes'],
            'original_name' => $stored['imported_pdf_original_name'],
            'replaced'      => $stored['replaced'],
        ]);

        return Json::ok($response, $stored);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $id > 0 ? $this->invoices->find($id) : null;
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!$this->store->remove($id, (int) $invoice['supplier_id'])) {
            return Json::error($response, 'no_pdf', 'Faktura nemá nahraný originál PDF.', 404);
        }

        $this->log($request, 'invoice.imported_pdf_removed', $id, [
            'original_name' => $invoice['imported_pdf_original_name'] ?? null,
            'sha256'        => $invoice['imported_pdf_hash'] ?? null,
        ]);

        return Json::ok($response, ['ok' => true]);
    }

    public function match(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $files = [];
        foreach ((array) ($body['files'] ?? []) as $name) {
            if (is_string($name) && trim($name) !== '') {
                $files[] = mb_substr(trim($name), 0, 255);
            }
        }
        if ($files === []) {
            return Json::error($response, 'no_files', 'Nebyl zadán žádný soubor.', 422);
        }
        if (count($files) > self::MATCH_MAX_FILES) {
            return Json::error($response, 'too_many_files',
                'Najednou lze párovat nejvýše ' . self::MATCH_MAX_FILES . ' souborů.', 422);
        }

        $keys = [];
        foreach ($files as $name) {
            foreach (OriginalPdfFilenameMatcher::candidateKeys($name) as $key) {
                $keys[$key] = true;
            }
        }
        $candidates = $this->candidates($supplierId, array_map('strval', array_keys($keys)));
        $matched = OriginalPdfFilenameMatcher::match($files, array_values($candidates));

        $items = [];
        foreach ($matched as $row) {
            $items[] = $row + [
                'invoice'    => $row['invoice_id'] !== null ? ($candidates[$row['invoice_id']] ?? null) : null,
                'candidates' => array_values(array_filter(array_map(
                    static fn (int $cid): ?array => $candidates[$cid] ?? null,
                    $row['candidate_ids'],
                ))),
            ];
        }

        return Json::ok($response, ['items' => $items]);
    }

    /**
     * Faktury firmy, jejichž číslo nebo platební VS má některý z kandidátních klíčů.
     * Jde po indexech (supplier_id, varsymbol_match_key) a (supplier_id,
     * payment_variable_symbol_match_key); interní storna se nepárují.
     *
     * @param list<string> $keys
     * @return array<int, array<string, mixed>>
     */
    private function candidates(int $supplierId, array $keys): array
    {
        $out = [];
        foreach (array_chunk($keys, self::KEY_CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT i.id, i.varsymbol, i.payment_variable_symbol, i.invoice_type, i.status,
                           i.issue_date, i.total_with_vat, cur.code AS currency,
                           COALESCE(NULLIF(c.company_name, ''), TRIM(CONCAT_WS(' ', c.first_name, c.last_name))) AS client_name,
                           (i.imported_pdf_path IS NOT NULL) AS has_original
                      FROM invoices i
                      LEFT JOIN clients c ON c.id = i.client_id
                      LEFT JOIN currencies cur ON cur.id = i.currency_id
                     WHERE i.supplier_id = ? AND i.invoice_type <> 'cancellation'
                       AND (i.varsymbol_match_key IN ($in) OR i.payment_variable_symbol_match_key IN ($in))";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId, ...$chunk, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['id']] = [
                    'id'                         => (int) $r['id'],
                    'varsymbol'                  => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
                    'payment_variable_symbol'    => $r['payment_variable_symbol'] !== null ? (string) $r['payment_variable_symbol'] : null,
                    'invoice_type'               => (string) $r['invoice_type'],
                    'status'                     => (string) $r['status'],
                    'issue_date'                 => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'total_with_vat'             => $r['total_with_vat'] !== null ? (float) $r['total_with_vat'] : null,
                    'currency'                   => $r['currency'] !== null ? (string) $r['currency'] : null,
                    'client_name'                => $r['client_name'] !== null ? (string) $r['client_name'] : null,
                    'has_original'               => (int) $r['has_original'] === 1,
                ];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function log(Request $request, string $action, int $invoiceId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $invoiceId, $payload, $ip,
            $request->getHeaderLine('User-Agent'));
    }
}
