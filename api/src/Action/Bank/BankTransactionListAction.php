<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Bank\BankMovementListService;
use MyInvoice\Security\RequestAuthorization;
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
    public function __construct(private readonly BankMovementListService $movements, private readonly ClientBankAccess $clientAccess) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        $result = $this->movements->list($supplierId, $request->getQueryParams(), 'all', RequestAuthorization::isClientType($request));
        $result['items'] = $this->clientAccess->redactTransactions($request, $result['items']);
        return Json::ok($response, $result);
    }

    /** GET /api/bank-transactions/{id} — jeden pohyb ve tvaru řádku přehledu. */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        $id = (int) ($args['id'] ?? 0);
        $result = $this->movements->list($supplierId, ['id' => $id, 'per_page' => 1], 'all', RequestAuthorization::isClientType($request));
        $items = $this->clientAccess->redactTransactions($request, $result['items']);
        if ($id <= 0 || $items === []) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        return Json::ok($response, $items[0]);
    }
}
