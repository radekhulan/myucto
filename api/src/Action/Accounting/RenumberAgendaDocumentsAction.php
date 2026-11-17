<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Renumber\AgendaRenumberService;
use MyInvoice\Service\Renumber\CashDocumentRenumberService;
use MyInvoice\Service\Renumber\InternalDocumentRenumberService;
use MyInvoice\Service\Renumber\OtherItemRenumberService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přečíslování dokladů měsíce v agendách s ročními řadami (náhled GET, provedení POST):
 *
 *   /api/accounting/cash-documents/renumber?period=2026-10&register_id=1   pokladna (výdajové doklady)
 *   /api/accounting/other-items/renumber?period=2026-10&side=payable        OP / OZ
 *   /api/accounting/internal-documents/renumber?period=2026-10              interní doklady
 *
 * POST nese tatáž pole v těle. Právo zápisu agendy hlídá RoutePermissionMap. Interní
 * plumbing (není ve veřejném API), klient přečíslovat nesmí — čísla spravuje účetní.
 */
final class RenumberAgendaDocumentsAction
{
    public function __construct(
        private readonly CashDocumentRenumberService $cash,
        private readonly OtherItemRenumberService $otherItems,
        private readonly InternalDocumentRenumberService $internal,
    ) {}

    public function cashPreview(Request $request, Response $response): Response
    {
        return $this->preview($this->cash, $request, $response, ['register_id']);
    }

    public function cashRenumber(Request $request, Response $response): Response
    {
        return $this->renumber($this->cash, $request, $response, ['register_id']);
    }

    public function otherItemsPreview(Request $request, Response $response): Response
    {
        return $this->preview($this->otherItems, $request, $response, ['side']);
    }

    public function otherItemsRenumber(Request $request, Response $response): Response
    {
        return $this->renumber($this->otherItems, $request, $response, ['side']);
    }

    public function internalPreview(Request $request, Response $response): Response
    {
        return $this->preview($this->internal, $request, $response, []);
    }

    public function internalRenumber(Request $request, Response $response): Response
    {
        return $this->renumber($this->internal, $request, $response, []);
    }

    /** @param list<string> $scopeKeys */
    private function preview(AgendaRenumberService $service, Request $request, Response $response, array $scopeKeys): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Přečíslování dokladů je vyhrazeno účetní.', 403);
        }
        $query = $request->getQueryParams();
        try {
            return Json::ok($response, $service->preview(
                SupplierGuard::currentId($request),
                (string) ($query['period'] ?? ''),
                array_intersect_key($query, array_flip($scopeKeys)),
            ));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    /** @param list<string> $scopeKeys */
    private function renumber(AgendaRenumberService $service, Request $request, Response $response, array $scopeKeys): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Přečíslování dokladů je vyhrazeno účetní.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            return Json::ok($response, $service->renumber(
                SupplierGuard::currentId($request),
                (string) ($body['period'] ?? ''),
                array_intersect_key($body, array_flip($scopeKeys)),
                isset($user['id']) ? (int) $user['id'] : null,
            ));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return Json::error($response, 'period_locked', $e->getMessage(), 409);
        }
    }
}
