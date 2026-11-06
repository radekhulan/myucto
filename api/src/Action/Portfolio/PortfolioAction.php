<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portfolio;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use MyInvoice\Service\Portfolio\PortfolioCheckCache;
use MyInvoice\Service\Portfolio\PortfolioCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přehled firem pro účetní kancelář (cross-supplier dashboard, Fáze F,
 * audit 2026-07 P2/M). Na rozdíl od zbytku appky NENÍ scoped na X-Supplier-Id —
 * agreguje přes všechny firmy uživatele (viz PortfolioAggregationService).
 * Role 'client' sem nemá přístup (PermissionMiddleware).
 */
final class PortfolioAction
{
    public function __construct(
        private readonly PortfolioAggregationService $portfolio,
        private readonly PortfolioCheckService $checks,
        private readonly PortfolioCheckCache $checkCache,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        return Json::ok($response, $this->portfolio->overview(
            $userId,
            RequestAuthorization::isSuperadmin($request),
            new \DateTimeImmutable(),
            RequestAuthorization::boundSupplierId($request),
        ));
    }

    /**
     * Souhrn měsíční kontroly JEDNÉ firmy. Samostatný endpoint (ne součást
     * `overview`) schválně: kontroly jsou o řád dražší než zbytek přehledu a
     * klient si je dotahuje po firmách, aby se tabulka zobrazila hned.
     *
     * Přístup ke KAŽDÉ firmě se ověřuje stejně jako u přehledu — seznam povolených
     * ID staví PortfolioAggregationService z `user_suppliers`, takže tudy nejde
     * přečíst cizí firmu. Token vázaný na firmu (a vlastní doména) projde jen
     * pro tuto firmu.
     */
    public function monthlyCheck(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $supplierId = (int) ($args['supplierId'] ?? 0);
        if (!$this->portfolio->userCanAccess(
            $userId,
            RequestAuthorization::isSuperadmin($request),
            $supplierId,
            RequestAuthorization::boundSupplierId($request),
        )) {
            return Json::error($response, 'not_found', 'Firma nenalezena.', 404);
        }

        $now = new \DateTimeImmutable();

        return Json::ok($response, [
            'summary' => $this->checkCache->remember($supplierId, $now, fn () => $this->checks->summary($supplierId, $now)),
        ]);
    }
}
