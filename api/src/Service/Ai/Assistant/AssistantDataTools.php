<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai\Assistant;

use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mcp\ClientMcpToolPolicy;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\StaffMcpToolPolicy;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Sestaví datové nástroje AI asistenta pro přihlášeného uživatele a aktuální firmu.
 *
 * Nezávisí na tom, jestli je zapnutý serverový MCP pro externí AI: asistent je funkce
 * aplikace, katalog jen sdílí. Potřebuje Node.js a PHP CLI dostupné webovému procesu
 * (stejné předpoklady jako serverový MCP); bez nich asistent pracuje jen s manuálem.
 */
final class AssistantDataTools
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly Config $config,
        private readonly PermissionResolver $roles,
        private readonly StaffMcpToolPolicy $staffTools,
        private readonly ClientMcpToolPolicy $clientTools,
        private readonly LoggerInterface $logger,
        private readonly string $mcpDir = __DIR__ . '/../../../../../MCP',
    ) {}

    public function forRequest(Request $request): ?AssistantDataSource
    {
        $supplierId = SupplierGuard::currentId($request);
        $sessionToken = $request->getAttribute(AuthMiddleware::ATTR_TOKEN);
        if ($supplierId <= 0 || !RequestAuthorization::isSessionAuth($request)
            || !is_string($sessionToken) || $sessionToken === '') {
            return null;
        }
        $script = $this->mcpDir . '/src/hosted-relay.mjs';
        $manifest = $this->routeManifest();
        if (!is_file($script) || $manifest === null || !$this->runtimeAvailable()) {
            return null;
        }
        $php = $this->hosted->managedRelay() ? $this->hosted->relayPhpBinary() : $this->hosted->phpBinary();
        if ($php === null) {
            return null;
        }
        $role = $this->roles->resolve($request);
        $allowed = $role->isClientType()
            ? $this->clientTools->allowed($request)
            : $this->staffTools->allowedForCurrentCompany($request);

        $forwardedHeader = 'HTTP_' . str_replace('-', '_', strtoupper(
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For')
        ));
        $serverParams = array_intersect_key($request->getServerParams(), array_flip([
            'REMOTE_ADDR', IpMatcher::TRUSTED_CLIENT_IP_PARAM, $forwardedHeader,
        ]));

        return new McpAssistantDataSource(
            $this->hosted->nodeBinary(),
            $php,
            $script,
            rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1',
            trim((string) @file_get_contents(dirname($this->mcpDir) . '/VERSION')),
            $supplierId,
            $sessionToken,
            $serverParams,
            $allowed,
            $manifest,
            $this->logger,
        );
    }

    private function runtimeAvailable(): bool
    {
        try {
            return !$this->hosted->managedInstallation() && $this->hosted->nodeAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, array{write:bool, api:list<array{string,string}>}>|null */
    private function routeManifest(): ?array
    {
        $json = @file_get_contents($this->mcpDir . '/src/tool-routes.generated.json');
        $decoded = is_string($json) ? json_decode($json, true, 32) : null;
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
