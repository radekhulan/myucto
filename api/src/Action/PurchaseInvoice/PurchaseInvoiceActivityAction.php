<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\DocumentActivityFeed;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/activity
 *
 * Activity log pro přijatou fakturu — všechny purchase_invoice.* události
 * (created, updated, transitioned, pdf_uploaded, pdf_downloaded, pdf_deleted,
 *  exchange_rate_set, exchange_rate_reloaded, items_updated).
 *
 * Mirror invoice activity action: stejný response tvar pro frontend reuse komponenty,
 * bez IP adres a e-mailů uživatelů ({@see DocumentActivityFeed}).
 */
final class PurchaseInvoiceActivityAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentActivityFeed $feed,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        return Json::ok($response, $this->feed->forEntity('purchase_invoice', $id));
    }
}
