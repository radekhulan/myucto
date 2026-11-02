<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Mcp\ClientMcpToolPolicy;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Skutečná resoluce rolí nad izolovanou SQLite; doménová politika i RBAC jsou produkční. */
final class ClientMcpToolPolicyTest extends TestCase
{
    #[TestWith(['client_read'])]
    #[TestWith(['client_write'])]
    #[TestWith(['accountant'])]
    #[TestWith(['admin'])]
    public function testCatalogChangesOnlyByWorkReportsAndVatRates(string $preset): void
    {
        // Zmrazená nabídka původního client-tools.json nad b939f31a7; není zdrojem produkčních práv.
        $baseline = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Mcp/client-catalog-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../../MCP/src/tool-api.generated.json'), true, flags: JSON_THROW_ON_ERROR);
        $allowed = $this->catalog($preset);
        foreach (['read', 'read_write'] as $scope) {
            $actual = array_values(array_filter($allowed, fn ($name) => $scope === 'read_write' || !$manifest[$name]['write']));
            sort($actual);
            $additions = ['get_work_report', 'list_vat_rates'];
            if ($scope === 'read_write' && $preset !== 'client_read') {
                array_push($additions, 'add_work_report_entry', 'add_work_report_material', 'remove_work_report_entry');
            }
            $expected = [...$baseline[$preset][$scope], ...$additions];
            sort($expected);
            self::assertSame($expected, $actual, $preset . ' / ' . $scope);
        }
    }

    public function testWorkReportRequiresAllLookupPermissions(): void
    {
        self::assertContains('add_work_report_entry', $this->catalog('client_write'));
        foreach (['projects', 'clients', 'settings.company'] as $missing) {
            $allowed = $this->catalog('client_write', $missing);
            self::assertNotContains('add_work_report_entry', $allowed, $missing);
            self::assertContains('get_work_report', $allowed);
            self::assertContains('remove_work_report_entry', $allowed);
        }
    }

    public function testOnlyIdentityToolsAreAvailableWithoutAgendaPermissions(): void
    {
        $actual = $this->catalog('client_none');
        sort($actual);
        self::assertSame(['whoami'], $actual);
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../../MCP/src/tool-api.generated.json'), true, flags: JSON_THROW_ON_ERROR);
        $routes = new RoutePermissionMap();
        $selfService = [];
        foreach ($manifest as $name => $contract) {
            if (array_all($contract['api'], fn ($operation) => !str_contains($operation[1], '{')
                && $routes->match(...$operation)?->kind === RoutePermissionMap::SELF_SERVICE)) $selfService[] = $name;
        }
        sort($selfService);
        self::assertSame(['whoami'], $selfService);
    }

    #[TestWith([''])]
    #[TestWith(['{'])]
    #[TestWith(['null'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[]}}'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[["PUT","/api/invoices/{id}"]]}}'])]
    #[TestWith(['{"synthetic":{"write":false,"api":[["GET","/api/invoices/1"]]}}'])]
    #[TestWith(['{"synthetic":{"api":[["GET","/api/invoices"]]}}'])]
    public function testInvalidManifestFailsClosed(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-manifest-');
        try {
            file_put_contents($path, $content);
            self::assertSame([], $this->catalog('admin', manifestPath: $path));
        } finally {
            unlink($path);
        }
    }

    public function testMissingOrUnreadableManifestFailsClosed(): void
    {
        self::assertSame([], $this->catalog('admin', manifestPath: __DIR__ . '/missing-manifest.json'));
        self::assertSame([], $this->catalog('admin', manifestPath: __DIR__));
        $path = tempnam(sys_get_temp_dir(), 'mcp-manifest-');
        try {
            file_put_contents($path, '{}');
            chmod($path, 0000);
            if (!is_readable($path)) self::assertSame([], $this->catalog('admin', manifestPath: $path));
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }

    /** @return list<string> */
    public function catalog(string $preset, ?string $missing = null, ?string $manifestPath = null): array
    {
        $catalog = new PermissionCatalog();
        $type = str_starts_with($preset, 'client_') ? 'client' : 'staff';
        $permissions = $preset === 'accountant' ? $catalog->legacyPreset('accountant') : [];
        if ($preset !== 'accountant' && $preset !== 'client_none') {
            foreach ($catalog->all() as $key => $definition) {
                if (in_array($type, $definition['role_types'], true)) $permissions[$key] = $preset === 'client_read' ? 1 : 2;
            }
        }
        // Testujeme čtecí i zápisový token nad stejnou maticí agend.
        $permissions['profile.mcp'] = 2;
        unset($permissions[$missing ?? '']);
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, system_key TEXT, name TEXT, role_type TEXT, is_active INTEGER);
            CREATE TABLE role_permissions (role_id INTEGER, permission_key TEXT, access_level INTEGER)');
        $pdo->prepare('INSERT INTO roles VALUES (1, ?, ?, ?, 1)')
            ->execute([$type === 'staff' ? $preset : null, 'Syntetická MCP role', $type]);
        $insert = $pdo->prepare('INSERT INTO role_permissions VALUES (1, ?, ?)');
        foreach ($permissions as $key => $level) $insert->execute([$key, $level]);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(1, false, null));
        $roles = new PermissionResolver($db, $suppliers, $catalog);
        $routes = new RoutePermissionMap();
        $arguments = [$roles, new PermissionChecker($catalog), $routes, new ClientRoutePolicy($routes, $catalog)];
        if ($manifestPath !== null) $arguments[] = $manifestPath;
        $policy = new ClientMcpToolPolicy(...$arguments);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://portal.example.test/mcp')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role_id' => 1, 'role_summary' => ['type' => $type]]);
        return $policy->allowed($request);
    }
}
