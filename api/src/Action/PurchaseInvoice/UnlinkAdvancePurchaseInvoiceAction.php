<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinkException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}/link-advance[?advance_id=]
 *
 * Zruší propojení finální faktury se zálohou. advance_paid_amount ponecháme
 * (ruční korekce), jen odpojíme vazbu — spárovaná záloha se opět zařadí do nákladů
 * dle běžného pravidla.
 */
final class UnlinkAdvancePurchaseInvoiceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
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

        // Zámek dokladu (Epic F6): rozpojení vazby na zálohu mění doklad.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forPurchaseInvoice($existing), 'purchase_invoice', $id)) {
            return $deny;
        }

        // `?advance_id=` odpojí jednu zálohu faktury s víc zálohami; bez něj všechny.
        $advanceId = (int) ($request->getQueryParams()['advance_id'] ?? 0);
        try {
            $this->repo->unlinkAdvance($id, $supplierId, $advanceId > 0 ? $advanceId : null);
        } catch (PurchaseAdvanceLinkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->status);
        } catch (\RuntimeException $e) {
            // Zaúčtovaný doklad — zúčtování zálohy je součástí zápisu. Zrcadlo
            // vydané větve (UnlinkAdvanceAction), která vrací týž kód.
            return Json::error($response, 'unlink_refused', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.advance_unlinked', $user['id'] ?? null, 'purchase_invoice', $id, [
            'previous_advance_id' => $advanceId > 0 ? $advanceId : ($existing['advance_purchase_invoice_id'] ?? null),
            'previous_advance_ids' => array_map(static fn (array $a): int => (int) $a['id'], (array) ($existing['linked_advances'] ?? [])),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
