<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export přijaté faktury do ISDOC nebo Pohoda XML.
 *   GET /api/purchase-invoices/{id}/isdoc   → ISDOC 6.0 XML
 *   GET /api/purchase-invoices/{id}/pohoda  → Pohoda XML (dataPack)
 */
final class ExportPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceExportService $exporter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function isdoc(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        try {
            $xml = $this->exporter->toIsdocXml($id, $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.isdoc_exported', $user['id'] ?? null, 'purchase_invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('purchase-invoice-%d.isdoc', $id);
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function pohoda(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        try {
            $xml = $this->exporter->toPohodaXml($id, $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.pohoda_exported', $user['id'] ?? null, 'purchase_invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('purchase-invoice-%d-pohoda.xml', $id);
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
