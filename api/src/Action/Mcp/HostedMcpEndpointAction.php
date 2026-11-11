<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\McpFileLimits;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Update\VersionService;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HostedMcpEndpointAction
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly NodeBridge $bridge,
        private readonly VersionService $version,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly ?\MyInvoice\Service\Mcp\ClientMcpToolPolicy $clientTools = null,
        private readonly ?\MyInvoice\Service\Mcp\StaffMcpToolPolicy $staffTools = null,
    ) {}

    public function handle(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) {
            return Json::error($response, 'mcp_disabled', 'Serverový MCP není zapnutý.', 404);
        }
        if (!RequestAuthorization::isBearerAuth($request)) {
            $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
            return $this->unauthorized($response, $domain instanceof TenantDomainContext && $domain->locksSupplier() ? $domain->origin : null);
        }
        if ($request->getMethod() === 'GET') {
            return $response->withStatus(405)->withHeader('Allow', 'POST, DELETE');
        }
        if ($request->getMethod() === 'DELETE') {
            return $response->withStatus(405)->withHeader('Allow', 'POST');
        }

        // Strop nese nahrávaný soubor v base64 (McpFileLimits), ostatní volání jsou malá.
        $body = (string) $request->getBody();
        if (strlen($body) > McpFileLimits::MAX_ENVELOPE_BYTES) {
            return $this->rpc($response, null, -32600, 'Požadavek je příliš velký.', 413);
        }
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->rpc($response, null, -32700, 'Neplatný JSON.', 400);
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->rpc($response, null, -32600, 'Neplatný JSON-RPC požadavek.', 400);
        }
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        if (!is_string($method)) {
            return $this->rpc($response, $id, -32600, 'Chybí metoda.', 400);
        }
        if (!array_key_exists('id', $message)) {
            return $response->withStatus(202)->withHeader('Cache-Control', 'no-store');
        }

        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope = (string) ($token['scope'] ?? 'read');
        $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        $custom = $domain instanceof TenantDomainContext && $domain->locksSupplier();
        if ($custom && empty($token['mcp_client_surface'])) return $this->unauthorized($response, $domain->origin);
        $input = [
            'scope' => $scope,
            'mcpResource' => $token['mcp_resource'] ?? null,
            // Interní role dostane v nabídce jen nástroje použitelné aspoň v jedné její firmě;
            // přímé volání posoudí call() pro jediný nástroj.
            'allowedTools' => !empty($token['mcp_client_surface']) ? ($this->clientTools?->allowed($request) ?? [])
                : ($method === 'tools/list' ? $this->staffTools?->allowed($request) : null),
            'token' => substr($request->getHeaderLine('Authorization'), 7),
            'boundSupplierId' => ($token['supplier_id'] ?? null) !== null ? (int) $token['supplier_id'] : null,
            'lockedSupplierId' => $domain instanceof TenantDomainContext && $domain->locksSupplier()
                ? $domain->supplierId : null,
            'serverParams' => $request->getServerParams(),
        ];

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => $this->protocolVersion($message['params']['protocolVersion'] ?? null),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'myucto', 'version' => $this->version->getCurrentVersion()],
                    'instructions' => 'Nástrojem list_suppliers načtěte firmy dostupné uživateli. U firemních nástrojů zadejte supplier_id; členství a práva se ověřují při každém volání. Účetní deník, uzávěrky a daně jsou pouze ke čtení. Zapisovat lze koncepty ostatních pohledávek a závazků, které se nezaúčtují (včetně splátek, opakování, dimenzí a připojené smlouvy přes document_id nebo link_document s entity_type other_item), a analytické účty v účtové osnově (create_analytic_account, update_analytic_account). Zápisové a mazací akce vyžadují potvrzení uživatele.',
                ],
                'ping' => (object) [],
                'tools/list' => $this->listTools($message['params'] ?? null, $input),
                'tools/call' => $this->call($message['params'] ?? null, $input, $request),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->rpc($response, $id, -32602, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('mcp_backend_failed', [
                'method' => $method,
                'error_type' => $e::class,
                'error' => substr($e->getMessage(), 0, 500),
            ]);
            return $this->rpc($response, $id, -32603, 'MCP nástroj se nepodařilo dokončit.', 500);
        }
        if ($result === null) {
            return $this->rpc($response, $id, -32601, 'Neznámá metoda.', 200);
        }
        return Json::ok($response, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function listTools(mixed $params, array $input): array
    {
        $cursor = is_array($params) ? ($params['cursor'] ?? null) : null;
        if ($params !== null && !is_array($params)) {
            throw new \InvalidArgumentException('Neplatné parametry seznamu nástrojů.');
        }
        if ($cursor !== null && (!is_string($cursor) || preg_match('/^(0|[1-9][0-9]{0,3})$/D', $cursor) !== 1)) {
            throw new \InvalidArgumentException('Neplatný kurzor seznamu nástrojů.');
        }
        $result = $this->bridge->execute($input + ['operation' => 'list', 'cursor' => $cursor === null ? 0 : (int) $cursor]);
        if (!isset($result['tools']) || !is_array($result['tools'])) {
            throw new \InvalidArgumentException('Neplatný kurzor seznamu nástrojů.');
        }
        return $result;
    }

    private function call(mixed $params, array $input, Request $request): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null) || strlen($params['name']) > 128) {
            throw new \InvalidArgumentException('Neplatné jméno nástroje.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new \InvalidArgumentException('Neplatné argumenty nástroje.');
        }
        // Lokální obsluha list_suppliers musí projít stejnou politikou jako nástroje volané přes Node.
        if ($input['allowedTools'] !== null ? !in_array($params['name'], $input['allowedTools'], true)
            : $this->staffTools !== null && !$this->staffTools->allows($request, $params['name'])) {
            return ['content' => [['type' => 'text', 'text' => 'Pro tento nástroj nemáte oprávnění.']], 'isError' => true];
        }
        if ($params['name'] === 'list_suppliers') {
            return $this->listAccessibleSuppliers($request, $input['boundSupplierId']);
        }
        if ($input['boundSupplierId'] === null && $params['name'] !== 'whoami') {
            $supplierId = $arguments['supplier_id'] ?? null;
            if (!is_int($supplierId) || $supplierId < 1 || !in_array(
                $supplierId, array_column($this->accessibleSuppliers($request, null), 'id'), true,
            )) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Zadejte supplier_id firmy, ke které máte přístup.']],
                    'isError' => true,
                ];
            }
        }
        return $this->bridge->execute($input + [
            'operation' => 'call',
            'name' => $params['name'],
            'arguments' => $arguments,
        ]);
    }

    private function listAccessibleSuppliers(Request $request, ?int $boundSupplierId): array
    {
        return $this->supplierListResult($this->accessibleSuppliers($request, $boundSupplierId));
    }

    private function accessibleSuppliers(Request $request, ?int $boundSupplierId): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if ($domain instanceof TenantDomainContext && $domain->locksSupplier()) {
            if ($boundSupplierId !== null && $boundSupplierId !== $domain->supplierId) {
                return [];
            }
            $boundSupplierId = $domain->supplierId;
        }
        if (($user['is_superadmin'] ?? false) === true) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name
                   FROM supplier' . ($boundSupplierId !== null ? ' WHERE id = ?' : '') . ' ORDER BY id'
            );
            $stmt->execute($boundSupplierId !== null ? [$boundSupplierId] : []);
            $companies = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => (string) $row['name'],
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } else {
            $companies = [];
            foreach ($this->memberships->listForUser((int) ($user['id'] ?? 0)) as $row) {
                if ($boundSupplierId !== null && (int) $row['supplier_id'] !== $boundSupplierId) continue;
                $companies[] = ['id' => (int) $row['supplier_id'], 'name' => (string) $row['name']];
            }
        }
        return $companies;
    }

    private function supplierListResult(array $companies): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($companies, JSON_THROW_ON_ERROR)]],
            'structuredContent' => ['result' => $companies],
        ];
    }

    private function protocolVersion(mixed $requested): string
    {
        return in_array($requested, ['2025-11-25', '2025-06-18', '2025-03-26'], true)
            ? $requested : '2025-11-25';
    }

    private function unauthorized(Response $response, ?string $origin = null): Response
    {
        return Json::error($response, 'unauthorized', 'MCP vyžaduje přihlášení.', 401)
            ->withHeader('WWW-Authenticate', 'Bearer resource_metadata="'
                . ($origin ?? substr($this->hosted->endpoint(), 0, -4)) . '/.well-known/oauth-protected-resource"');
    }

    private function rpc(Response $response, mixed $id, int $code, string $message, int $status): Response
    {
        return Json::ok($response, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
