<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\PurchaseBankMatching;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PurchaseBankMatchingAction
{
    public function __construct(private readonly PurchaseInvoiceRepository $repo, private readonly PurchaseBankMatching $matching) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response, 'Doporučení banky je dostupné jen z přihlášené relace.');
        $retry = $request->getMethod() === 'POST';
        if (!RequestAuthorization::allows($request, 'bank', AccessLevel::READ)
            || !RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)
            || ($retry && !RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE))) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění pro banku nebo doklad.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id,$supplierId) === null) {
            return Json::error($response, 'not_found', 'Doklad nebyl nalezen.', 404);
        }
        if ($retry) {
            $userId = (int) (($request->getAttribute(AuthMiddleware::ATTR_USER) ?? [])['id'] ?? 0);
            if ($userId <= 0) return Json::error($response, 'forbidden', 'Chybí přihlášený uživatel.', 403);
            $result = $this->matching->retry($supplierId,$id,$userId);
            if ($result['outcome'] === 'forbidden') return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
        }
        return Json::ok($response, $this->matching->read($supplierId,$id));
    }
}
