<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\NodeBridge;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HostedMcpSettingsAction
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly NodeBridge $bridge,
        private readonly ?\MyInvoice\Service\Mcp\McpResourcePolicy $resources = null,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->state($request));
    }

    public function update(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Nastavení MCP může změnit pouze správce.', 403);
        }
        if ($this->hosted->managedInstallation()) {
            return Json::error($response, 'managed_installation', 'Serverový MCP není ve spravované instalaci dostupný.', 409);
        }
        $body = (array) $request->getParsedBody();
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return Json::error($response, 'invalid_enabled', 'Pole enabled musí být boolean.', 422);
        }
        if ($body['enabled'] && !$this->hosted->nodeAvailable()) {
            return Json::error($response, 'node_unavailable', 'Serverový MCP vyžaduje Node.js.', 422);
        }
        if ($this->hosted->managedByEnvironment()) {
            return Json::error($response, 'managed_by_environment', 'Nastavení MCP řídí proměnná prostředí.', 409);
        }
        $this->hosted->setEnabled($body['enabled']);
        return Json::ok($response, $this->state($request));
    }

    public function diagnostics(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Diagnostika MCP je dostupná pouze správci.', 403);
        }
        if (!$this->hosted->enabled()) {
            return Json::error($response, 'mcp_disabled', 'Serverový MCP není zapnutý.', 409);
        }
        try {
            $cursor = null;
            $count = 0;
            for ($page = 0; $page < 20; $page++) {
                $result = $this->bridge->execute([
                    'operation' => 'list', 'scope' => 'read', 'cursor' => $cursor === null ? 0 : (int) $cursor,
                ]);
                if (!isset($result['tools']) || !is_array($result['tools']) || $result['tools'] === []) {
                    throw new \RuntimeException('Most nevrátil žádné nástroje.');
                }
                $count += count($result['tools']);
                $cursor = $result['nextCursor'] ?? null;
                if ($cursor === null) return Json::ok($response, ['available' => true, 'tools_count' => $count]);
            }
            throw new \RuntimeException('Seznam nástrojů má příliš mnoho stránek.');
        } catch (\Throwable $e) {
            return Json::error($response, 'mcp_bridge_unavailable', 'MCP most nelze spustit.', 503, [
                'detail' => substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    private function state(Request $request): array
    {
        return [
            'enabled' => $this->hosted->enabled(),
            'node_available' => $this->hosted->nodeAvailable(),
            'endpoint' => $this->resources?->endpoint($request) ?? $this->hosted->endpoint(),
            'managed_by_environment' => $this->hosted->managedByEnvironment(),
            'managed_relay' => $this->hosted->managedRelay(),
        ];
    }
}
