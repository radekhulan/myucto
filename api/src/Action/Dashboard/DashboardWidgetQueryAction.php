<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Dashboard\DashboardWidgetDataService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Hromadný read-only dotaz pouze pro přihlášenou webovou relaci. */
final class DashboardWidgetQueryAction
{
    public function __construct(private readonly DashboardWidgetRegistry $registry, private readonly DashboardWidgetDataService $data) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['board'] ?? null) || !in_array($body['board'], ['home', 'portal'], true)) {
            return Json::error($response, 'invalid_dashboard_query', 'Neplatný požadavek přehledu.', 422);
        }
        if (!RequestAuthorization::canAccessDashboardBoard($request, $body['board'])) {
            return Json::error($response, 'forbidden', 'Přístup byl odepřen.', 403);
        }
        if (array_diff(array_keys($body), ['board', 'widgets']) !== []) {
            return Json::error($response, 'invalid_dashboard_query', 'Neplatný požadavek přehledu.', 422);
        }
        try {
            $layout = $this->registry->validate(['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => $body['widgets'] ?? null]);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'invalid_dashboard_query', 'Neplatný požadavek přehledu.', 422);
        }
        return Json::ok($response, $this->data->query($request, $layout['widgets']));
    }
}
