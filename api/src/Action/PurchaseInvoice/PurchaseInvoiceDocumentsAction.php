<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PF ↔ DMS provázání (Epic F7, §6). Přivěšení DMS dokumentů k přijaté faktuře přes
 * `document_links(entity_type='purchase_invoice')` — fixní `pdf_` / `source_` sloupce PF
 * (subsystém B) zůstávají netknuté.
 *
 * Dvojitý scope-guard: (1) PF musí patřit tenantovi ({@see PurchaseInvoiceRepository::find}),
 * (2) každý dokument prochází DMS viewer scope guardem — list přes
 * {@see DocumentRepository::listByEntity} (guard uvnitř), link přes
 * {@see DocumentRepository::find} (viewer) před založením vazby.
 */
final class PurchaseInvoiceDocumentsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly DocumentRepository $documents,
        private readonly DocumentLinkRepository $links,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    /** GET /api/purchase-invoices/{id}/documents */
    public function list(Request $request, Response $response, array $args): Response
    {
        $sid = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id, $sid);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // listByEntity aplikuje DMS scope guard — user-scoped doklady cizích uživatelů se nevrátí.
        return Json::ok($response, [
            'documents' => $this->documents->listByEntity($sid, 'purchase_invoice', $id, $this->viewer($request)),
        ]);
    }

    /** POST /api/purchase-invoices/{id}/documents {document_id} */
    public function link(Request $request, Response $response, array $args): Response
    {
        $sid = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id, $sid);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if ($denied = $this->denyIfLocked(
            $request,
            $response,
            $this->locks->forPurchaseInvoice($invoice),
            'purchase_invoice',
            $id,
            true,
        )) return $denied;
        $body = (array) $request->getParsedBody();
        $documentId = (int) ($body['document_id'] ?? 0);
        if ($documentId <= 0) {
            return Json::error($response, 'bad_request', 'Chybí document_id.', 400);
        }
        // Dokument musí být VIDITELNÝ pro viewera (scope guard) — nelze přivěsit cizí user doklad.
        if ($this->documents->find($documentId, $sid, $this->viewer($request)) === null) {
            return Json::error($response, 'document_not_found', 'Dokument nenalezen.', 404);
        }
        $this->links->attach($sid, $documentId, 'purchase_invoice', $id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.document_linked', $user['id'] ?? null, 'purchase_invoice', $id,
            ['document_id' => $documentId], $ip, $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, [
            'documents' => $this->documents->listByEntity($sid, 'purchase_invoice', $id, $this->viewer($request)),
        ]);
    }

    /** DELETE /api/purchase-invoices/{id}/documents?document_id= */
    public function unlink(Request $request, Response $response, array $args): Response
    {
        $sid = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id, $sid);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if ($denied = $this->denyIfLocked(
            $request,
            $response,
            $this->locks->forPurchaseInvoice($invoice),
            'purchase_invoice',
            $id,
            true,
        )) return $denied;
        $body = (array) $request->getParsedBody();
        $q = $request->getQueryParams();
        $documentId = (int) ($body['document_id'] ?? $q['document_id'] ?? 0);
        if ($documentId > 0) {
            // Dokument musí být VIDITELNÝ pro viewera (scope guard) — zrcadlí link(),
            // ať cizí user doklad nejde odpojit „naslepo" jen podle document_id.
            if ($this->documents->find($documentId, $sid, $this->viewer($request)) === null) {
                return Json::error($response, 'document_not_found', 'Dokument nenalezen.', 404);
            }
            $this->links->detach($sid, $documentId, 'purchase_invoice', $id);
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('purchase_invoice.document_unlinked', $user['id'] ?? null, 'purchase_invoice', $id,
                ['document_id' => $documentId], $ip, $request->getHeaderLine('User-Agent'), $sid);
        }
        return Json::ok($response, [
            'documents' => $this->documents->listByEntity($sid, 'purchase_invoice', $id, $this->viewer($request)),
        ]);
    }

    /** DMS viewer kontext z ATTR_USER (role admin → vidí vše tenanta; jinak company + vlastní). */
    private function viewer(Request $request): DocumentViewerContext
    {
        return DocumentViewerResolver::fromRequest($request);
    }
}
