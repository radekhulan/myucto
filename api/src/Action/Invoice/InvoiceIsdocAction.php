<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceIsdocService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/isdoc — ISDOC XML jedné vystavené faktury.
 *
 * Doplňuje symetrii s /api/purchase-invoices/{id}/isdoc (přijaté ho mají,
 * vystavené dosud jen hromadně přes admin export). Pro integrace: účetní
 * software zákazníka si stáhne strojově čitelnou fakturu bez ZIP obalu.
 *
 * Supplier scope: faktura musí patřit aktuálnímu dodavateli (X-Supplier-Id),
 * jinak 404 — stejně jako GetInvoiceAction.
 */
final class InvoiceIsdocAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceIsdocService $isdoc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        // Draft nemá číslo (varsymbol) — ISDOC dává smysl až pro vystavený doklad.
        if (($invoice['status'] ?? '') === 'draft') {
            return Json::error($response, 'validation_failed', 'Koncept nelze exportovat do ISDOC — nejdřív fakturu vystavte.', 400);
        }
        // Stornovanou fakturu (status=cancelled, viz CancelInvoiceAction) hromadný
        // export za období (ExportAction::findInvoiceIds) záměrně vynechává —
        // stejné pravidlo platí i tady, ať účetní software nedostane tentýž doklad
        // jinak podle toho, kterým z obou endpointů ho stáhne.
        if (($invoice['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'invalid_state', 'Stornovanou fakturu nelze exportovat do ISDOC.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $xml = $this->isdoc->buildXml($invoice, persistRate: !DemoReadOnlyMiddleware::enabled($request));
        } catch (\Throwable $e) {
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }
        try {
            $xml = $this->isdoc->signXml(
                $xml,
                $invoice,
                isset($user['id']) ? (int) $user['id'] : null,
            );
        } catch (\Throwable $e) {
            return Json::internalError($response, 'signing_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.isdoc_exported', isset($user['id']) ? (int) $user['id'] : null, 'invoice', $id,
            null, $ip, $request->getHeaderLine('User-Agent'), $sid);

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . InvoiceIsdocService::filename($invoice) . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
