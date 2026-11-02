<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Dashboard\DashboardLayoutConflict;
use MyInvoice\Service\Dashboard\DashboardLayoutService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardLayoutAction
{
    public function __construct(private readonly DashboardLayoutService $layouts) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, 'get');
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, 'put');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, 'reset');
    }

    private function handle(Request $request, Response $response, array $args, string $operation): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        $board = $args['board'] ?? '';
        try {
            if (!is_string($board)) throw new \InvalidArgumentException();
            DashboardWidgetRegistry::assertBoard($board);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'invalid_dashboard_board', 'Neplatná plocha přehledu.', 422);
        }
        if (!RequestAuthorization::canAccessDashboardBoard($request, $board)) {
            return Json::error($response, 'forbidden', 'Přístup k přehledu není povolen.', 403);
        }
        if ($operation !== 'get' && \MyInvoice\Middleware\DemoReadOnlyMiddleware::enabled($request)) {
            return Json::error($response, 'demo_read_only', 'V ukázkové verzi nelze rozložení uložit.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        try {
            if ($operation === 'get') return Json::ok($response, $this->layouts->get($userId, $board)
                + ['writable' => !\MyInvoice\Middleware\DemoReadOnlyMiddleware::enabled($request)]);
            $body = $request->getParsedBody();
            $keys = $operation === 'put' ? ['layout', 'revision'] : ['revision'];
            if (!is_array($body) || count($body) !== count($keys)
                || array_diff(array_keys($body), $keys) !== [] || !is_string($body['revision'] ?? null)
            ) {
                throw new \InvalidArgumentException('Neplatný požadavek na konfiguraci přehledu.');
            }
            $result = $operation === 'put'
                ? $this->layouts->put($userId, $board, $body['layout'], $body['revision'])
                : $this->layouts->reset($userId, $board, $body['revision']);
            return Json::ok($response, $result);
        } catch (DashboardLayoutConflict) {
            return Json::error($response, 'dashboard_layout_conflict', 'Rozložení přehledu mezitím změnilo jiné okno. Načtěte aktuální stav.', 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_dashboard_layout', $e->getMessage(), 422);
        }
    }
}
