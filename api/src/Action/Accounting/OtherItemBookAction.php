<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\OtherItemListFilters;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\OtherItemBook\OtherItemBookService;
use MyInvoice\Service\Accounting\OtherItemBook\OtherItemBookXlsxExporter;
use MyInvoice\Service\Pdf\OtherItemBookPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kniha ostatních pohledávek a závazků nad aktuálním filtrem výpisu (bez stránkování).
 *
 *   GET /api/accounting/other-items/book?format=pdf|xlsx&side=…&status=…&kind=…&kind_id=…&q=…&from=…&to=…
 *
 * Filtr čte {@see OtherItemListFilters} stejně jako výpis (včetně výchozího okna splatnosti),
 * firmu bere ze SupplierScopeMiddleware a právo je stejné jako u výpisu (`other_items` čtení).
 */
final class OtherItemBookAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly OtherItemBookService $service,
        private readonly OtherItemBookPdfRenderer $pdf,
        private readonly OtherItemBookXlsxExporter $xlsx,
    ) {}

    public function book(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        $format = $request->getQueryParams()['format'] ?? 'pdf';
        if (!is_string($format) || !in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 422);
        }
        $parsed = OtherItemListFilters::fromRequest($request);
        if (isset($parsed['error'])) {
            return Json::error($response, $parsed['error'], $parsed['message'], 422);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $book = $this->service->build(
            $this->currentSupplierId($request),
            $parsed,
            RequestAuthorization::allows($request, 'accounting', AccessLevel::READ),
            ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')],
        );
        if ($format === 'xlsx') {
            $out = $this->xlsx->export($book);
            $bytes = $out['bytes'];
            $mime = $out['mime'];
        } else {
            $bytes = $this->pdf->render(['book' => $book]);
            $mime = 'application/pdf';
        }
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="kniha-ostatnich-pohledavek-a-zavazku-' . date('Y-m-d') . '.' . $format . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
