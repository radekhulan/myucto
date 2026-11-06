<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Supplier\SupplierCarryOver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/suppliers/carry-over — co lze při zakládání firmy převzít ze stávajících
 * firem ({@see SupplierCarryOver}). Bez tajemství: poskytovatel AI, počet a názvy
 * firem, veřejná metadata certifikátů. Interní endpoint pro UI, ne veřejné API.
 */
final class SupplierCarryOverAction
{
    public function __construct(private readonly SupplierCarryOver $carryOver) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canCreateSupplier($request)) {
            return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Převzetí ze stávajících firem je dostupné jen z webového rozhraní.');
        }

        return Json::ok($response, $this->carryOver->preview($request));
    }
}
