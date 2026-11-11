<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\InvoiceListFilters;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Service\Invoice\Book\InvoiceBook;
use MyInvoice\Service\Invoice\Book\InvoiceBookService;
use MyInvoice\Service\Invoice\Book\InvoiceBookXlsxExporter;
use MyInvoice\Service\Pdf\InvoiceBookPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kniha vystavených a přijatých faktur nad aktuálním filtrem seznamu (bez stránkování).
 *
 *   GET /api/invoices/book?format=pdf|xlsx&filter[...]&q=…
 *   GET /api/purchase-invoices/book?format=pdf|xlsx&filter[...]&q=…
 *
 * Filtr čte {@see InvoiceListFilters} stejně jako seznam, firmu bere ze
 * SupplierScopeMiddleware a práva jsou stejná jako u seznamu.
 */
final class InvoiceBookAction
{
    public function __construct(
        private readonly InvoiceBookService $service,
        private readonly InvoiceBookPdfRenderer $pdf,
        private readonly InvoiceBookXlsxExporter $xlsx,
    ) {}

    public function issued(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $format = self::format($request);
        if ($format === null) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 422);
        }
        $filters = InvoiceListFilters::issued($request);
        if (is_string($filters)) {
            return Json::error($response, 'validation_failed', $filters, 422);
        }

        $book = $this->service->issued($filters, self::user($request));
        return $this->file($response, $book, $format, 'kniha-vystavenych-faktur');
    }

    public function received(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $format = self::format($request);
        if ($format === null) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 422);
        }
        $filters = InvoiceListFilters::purchase($request);
        if (is_string($filters)) {
            return Json::error($response, 'validation_failed', $filters, 422);
        }

        // Stejně jako seznam: bez práva číst Dokumenty se počítá jen vlastní archiv faktury.
        $documentViewer = isset($filters['has_visual_document'])
            && RequestAuthorization::allows($request, 'documents', AccessLevel::READ)
            ? DocumentViewerResolver::fromRequest($request) : null;
        $book = $this->service->received($filters, self::user($request), $documentViewer);
        return $this->file($response, $book, $format, 'kniha-prijatych-faktur');
    }

    private function file(Response $response, InvoiceBook $book, string $format, string $base): Response
    {
        if ($format === 'xlsx') {
            $out = $this->xlsx->export($book);
            $bytes = $out['bytes'];
            $mime = $out['mime'];
        } else {
            $bytes = $this->pdf->render(['book' => $book]);
            $mime = 'application/pdf';
        }
        $filename = $base . '-' . date('Y-m-d') . '.' . $format;
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function format(Request $request): ?string
    {
        $format = $request->getQueryParams()['format'] ?? 'pdf';
        return is_string($format) && in_array($format, ['pdf', 'xlsx'], true) ? $format : null;
    }

    /** @return array{name:string, email:string} */
    private static function user(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')];
    }
}
