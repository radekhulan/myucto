<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinkException;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/link-advance  body: {advance_id, append?}
 *
 * `append: true` přidá zálohu k zálohám, které faktura už vyúčtovává
 * ({@see PurchaseAdvanceLinks}: vyúčtování víc záloh jednou fakturou).
 *
 * Propojí finální přijatou fakturu se zálohovou (advance), aby se náklad
 * nepočítal dvakrát (záloha + vyúčtovací faktura). Vazba se ukládá na finální
 * fakturu; spárovaná záloha pak vypadne z nákladů/CRM/daně z příjmů.
 *
 * Vrací aktualizovaný invoice payload (vč. linked_advance) a `_warnings` ve stejném
 * tvaru jako create/update — zejména `advance_has_tax_document`. Bez něj by uživatel,
 * který nejdřív založí vyúčtovací fakturu v plné výši a AŽ POTOM na ni naváže zálohu
 * s DDKP, varování o dvojím odpočtu DPH nikdy neviděl (create/update už proběhly).
 */
final class LinkAdvancePurchaseInvoiceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): vazba na zálohu mění doklad.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forPurchaseInvoice($existing), 'purchase_invoice', $id)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $advanceId = (int) ($body['advance_id'] ?? 0);
        if ($advanceId <= 0) {
            return Json::error($response, 'invalid_advance', 'Chybí advance_id.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        // `append` přidá zálohu k už vyúčtovaným (faktura s víc zálohami). Bez něj zůstává
        // vazba jedinou zálohou; faktuře s víc zálohami by ostatní nahradila, proto ji odmítne.
        $append = filter_var($body['append'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $links = new PurchaseAdvanceLinks($this->db);
        try {
            if ($append && $links->advanceIds($supplierId, $id) !== []) {
                $links->append($supplierId, $id, $advanceId, isset($user['id']) ? (int) $user['id'] : null);
            } else {
                $this->repo->linkAdvance($id, $advanceId, $supplierId);
            }
        } catch (PurchaseAdvanceLinkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->status);
        } catch (\Throwable $e) {
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.advance_linked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'advance_id' => $advanceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        if (is_array($invoice) && CreatePurchaseInvoiceAction::finalRisksDoubleVatDeduction($this->db, $invoice, $supplierId)) {
            $invoice['_warnings'] = ['advance_has_tax_document'];
        }

        return Json::ok($response, $invoice);
    }
}
