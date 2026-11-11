<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tenant\DefaultSupplierService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výchozí firma přihlášeného uživatele.
 *
 *   PUT /api/auth/default-supplier   {"supplier_id": 12}
 *
 * Volá ji přepínač firem: zvolená firma se otevře i v jiném prohlížeči nebo
 * zařízení a má přednost před firmou, kterou server předvolil podle počtu
 * dokladů (DefaultSupplierService). Jen session — je to volba rozhraní, API
 * token si firmu vybírá hlavičkou.
 */
final class DefaultSupplierAction
{
    public function __construct(
        private readonly DefaultSupplierService $defaults,
        private readonly UserSupplierRepository $memberships,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = filter_var($body['supplier_id'] ?? null, FILTER_VALIDATE_INT);
        if ($supplierId === false || $supplierId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí platné supplier_id.', 422);
        }

        $isSuperadmin = (bool) ($user['is_superadmin'] ?? false);
        $membershipIds = $isSuperadmin ? [] : $this->memberships->allowedSupplierIds($userId);
        if (!$this->defaults->isAccessible($supplierId, $isSuperadmin, $membershipIds)) {
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáte oprávnění.', 403);
        }

        $this->defaults->store($userId, $supplierId);

        return Json::ok($response, ['default_supplier_id' => $supplierId]);
    }
}
