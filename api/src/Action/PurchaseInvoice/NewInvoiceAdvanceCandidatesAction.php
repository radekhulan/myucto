<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/advance-candidates?vendor_id=&currency_id=&total=
 *
 * Nespárované zálohy dodavatele pro konečnou fakturu, která ještě není uložená (editor
 * nového dokladu). Stejný výběr a řazení jako {@see AdvanceCandidatesAction}; vazbu
 * založí uložení dokladu (`advance_purchase_invoice_id` v těle POST).
 */
final class NewInvoiceAdvanceCandidatesAction
{
    public function __construct(private readonly PurchaseInvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $vendorId = (int) ($q['vendor_id'] ?? 0);
        $currencyId = (int) ($q['currency_id'] ?? 0);
        if ($vendorId <= 0 || $currencyId <= 0) {
            return Json::error($response, 'invalid_query', 'Chybí vendor_id nebo currency_id.', 400);
        }
        $total = is_numeric($q['total'] ?? null) ? (float) $q['total'] : 0.0;
        $supplierId = SupplierGuard::currentId($request);

        return Json::ok($response, [
            'candidates' => $this->repo->advanceCandidatesFor($vendorId, $currencyId, $total, $supplierId),
        ]);
    }
}
