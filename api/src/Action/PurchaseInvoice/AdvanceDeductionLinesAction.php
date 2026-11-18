<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceDeduction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/advance-deduction-lines?advance_id=
 *
 * Řádky odpočtu poskytnuté zálohy podle jejích daňových dokladů k platbě (DDKP): za každý
 * DDKP a sazbu jeden záporný řádek se základem a daní DDKP. Editor konečné faktury je
 * vloží jako odpočtové řádky zálohy ({@see PurchaseAdvanceDeduction}). Funguje i pro
 * doklad, který ještě není uložený, proto se ptá na zálohu, ne na konečnou fakturu.
 * Záloha bez živého DDKP vrací prázdný seznam.
 */
final class AdvanceDeductionLinesAction
{
    public function __construct(private readonly PurchaseAdvanceDeduction $deduction) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $advanceId = (int) ($request->getQueryParams()['advance_id'] ?? 0);
        if ($advanceId <= 0) {
            return Json::error($response, 'invalid_advance', 'Chybí advance_id.', 400);
        }
        $supplierId = SupplierGuard::currentId($request);

        return Json::ok($response, [
            'advance_id' => $advanceId,
            'lines'      => $this->deduction->linesFromTaxDocuments($supplierId, $advanceId),
        ]);
    }
}
