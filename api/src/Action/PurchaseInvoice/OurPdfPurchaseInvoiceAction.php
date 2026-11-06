<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/our-pdf
 *
 * Vygeneruje naši PDF kopii přijaté faktury (mimic dodavatelského PDF).
 * Použít, když nemáme originál (z importu jen metadata) nebo chceme vlastní layout.
 *
 * `?inline=1` → Content-Disposition: inline (pro iframe preview).
 */
final class OurPdfPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoicePdfRenderer $renderer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'invalid_id', 'Neplatné ID', 400);

        $supplierId = SupplierGuard::currentId($request);
        try {
            $pdf = $this->renderer->render($id, $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'render_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.our_pdf_downloaded', $user['id'] ?? null, 'purchase_invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'));

        $inline = !empty($request->getQueryParams()['inline']);
        $filename = sprintf('purchase-invoice-%d-our.pdf', $id);

        $response->getBody()->write($pdf);
        $resp = $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition',
                ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($inline) {
            $resp = $resp
                ->withHeader('Content-Security-Policy', "frame-ancestors 'self'")
                ->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }
        return $resp;
    }
}
