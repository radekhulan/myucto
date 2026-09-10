<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PayrollEmployeeRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/logbook/drivers — zaměstnanci firmy k výběru řidiče vozidla.
 *
 * Vrací jen jméno a příznak aktivity: kniha jízd nepotřebuje (a nesmí dostat) mzdové
 * údaje, takže k výběru řidiče nestačí oprávnění ke mzdám a nemá ho vyžadovat.
 */
final class LogbookDriversAction
{
    public function __construct(private readonly PayrollEmployeeRepository $employees) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $rows = array_map(static fn (array $e): array => [
            'id'        => (int) $e['id'],
            'full_name' => (string) $e['full_name'],
            'is_active' => (bool) $e['is_active'],
        ], $this->employees->listForTenant($supplierId));
        return Json::ok($response, $rows);
    }
}
