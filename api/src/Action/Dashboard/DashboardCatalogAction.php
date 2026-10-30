<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardCatalogAction
{
    public function __construct(private readonly DashboardWidgetAccess $access) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        $board = $request->getQueryParams()['board'] ?? 'portal';
        try {
            if (!is_string($board)) throw new \InvalidArgumentException();
            DashboardWidgetRegistry::assertBoard($board);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'invalid_dashboard_board', 'Neplatná plocha přehledu.', 422);
        }
        if (!RequestAuthorization::canAccessDashboardBoard($request, $board)) {
            return Json::error($response, 'forbidden', 'Přístup k přehledu není povolen.', 403);
        }
        $supplier = $this->access->supplier($request);
        if ($supplier === []) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        return Json::ok($response, $this->access->catalog($request, $board, $supplier));
    }
}
