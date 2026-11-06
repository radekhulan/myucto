<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Catalog;

use MyInvoice\Action\Property\Catalog\PropertyLegalRoleCatalogAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Property\Catalog\PropertyLegalRoleRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\Catalog\PropertyLegalRoleCatalog;
use MyInvoice\Tests\Support\PropertyLegalRoleFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PropertyLegalRoleCatalogActionTest extends TestCase
{
    use PropertyLegalRoleFixture;

    private Connection $db;
    private array $fixture;
    private PropertyLegalRoleCatalogAction $action;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->fixture = $this->legalRoleFixture($this->db);
        $this->action = new PropertyLegalRoleCatalogAction(new PropertyLegalRoleCatalog(new PropertyLegalRoleRepository($this->db)));
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testBearerPropertyReadCanUseCuratedListAndDetail(): void
    {
        [$status, $list] = $this->call('list', ['as_of' => '2026-06-01T10:00:00Z', 'known_at' => '2099-01-01T00:00:00Z'], [], ['property' => 1]);
        self::assertSame(200, $status);
        self::assertCount(12, $list['items']);
        [$status, $detail] = $this->call('detail', ['as_of' => '2026-06-01T10:00:00Z', 'known_at' => '2099-01-01T00:00:00Z'],
            ['unit_id' => (string) $this->fixture['units']['U02']], ['property' => 1]);
        self::assertSame(200, $status);
        self::assertSame($this->fixture['units']['U02'], $detail['items'][0]['unit_id']);
    }

    public function testPermissionAndQueryValidationFailClosed(): void
    {
        [$status] = $this->call('list', ['as_of' => '2026-06-01T10:00:00Z', 'known_at' => '2099-01-01T00:00:00Z'], [], []);
        self::assertSame(403, $status);
        foreach ([
            ['as_of' => '2026-06-01T10:00:00Z'],
            ['as_of' => '2026-06-01', 'known_at' => '2099-01-01T00:00:00Z'],
            ['as_of' => '2026-06-01T10:00:00Z', 'known_at' => '2099-01-01T00:00:00Z', 'title_ref' => 'leak'],
            ['as_of' => '2026-06-01T10:00:00Z', 'known_at' => '2099-01-01T00:00:00Z', 'limit' => []],
        ] as $query) {
            [$status, $body] = $this->call('list', $query, [], ['property' => 1]);
            self::assertSame(422, $status);
            self::assertStringStartsWith('property_', $body['error']['code']);
        }
    }

    private function call(string $method, array $query, array $args, array $permissions): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/property/catalog/unit-legal-roles')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->fixture['supplier'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fixture['actor'], 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(880, 'Synthetic property catalog role', 'staff', true, $permissions));
        $response = $this->action->{$method}($request, new Response(), $args);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }
}
