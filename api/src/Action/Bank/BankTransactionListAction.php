<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Bank\BankMovementListService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/bank-transactions — všechny bankovní pohyby firmy napříč účty a roky
 * (záložka „Všechny pohyby"). Patří k bankovním výpisům, ne k účetnictví: čte ho
 * každá firma s výpisy bez ohledu na režim účetnictví a licenci. Oprávnění `bank`
 * (čtení) hlídá RoutePermissionMap stejně jako u výpisů.
 */
final class BankTransactionListAction
{
    public function __construct(private readonly BankMovementListService $movements) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        return Json::ok($response, $this->movements->list($supplierId, $request->getQueryParams(), 'all'));
    }
}
