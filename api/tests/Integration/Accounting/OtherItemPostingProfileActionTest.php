<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Action\Accounting\OtherItemKindAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Akce účetních profilů druhů a vlastní kontace úhrad: právo agendy ostatních položek,
 * firma jen z kontextu požadavku (cizí druh ani položka nejsou vidět) a audit změn.
 */
#[Group('integration')]
final class OtherItemPostingProfileActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private OtherItemKindAction $kinds;
    private OtherItemAction $items;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->kinds = $container->get(OtherItemKindAction::class);
        $this->items = $container->get(OtherItemAction::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) self::markTestSkipped('Chybí firma nebo uživatel.');
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $source);
        foreach ([$this->supplierId, $this->otherSupplierId] as $id) {
            $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$id]);
            $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($id);
            $container->get(AccountingPeriodRepository::class)->create($id, 2099, '2099-01-01', '2099-12-31');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testNewRoutesUseOtherItemsPermission(): void
    {
        foreach ([
            ['GET', '/api/accounting/other-items/kinds/5/posting-profiles', AccessLevel::READ],
            ['PUT', '/api/accounting/other-items/kinds/5/posting-profiles', AccessLevel::WRITE],
            ['PUT', '/api/accounting/other-items/kinds/builtin/payable/rent/posting-profiles', AccessLevel::WRITE],
            ['PUT', '/api/accounting/other-items/5/posting-overrides', AccessLevel::WRITE],
        ] as [$method, $path, $level]) {
            $permission = (new RoutePermissionMap())->match($method, $path);
            self::assertNotNull($permission, $method . ' ' . $path);
            self::assertSame(['other_items', $level], [$permission->key, $permission->minimum], $method . ' ' . $path);
        }
    }

    public function testReadersSeeProfilesOnlyWritersChangeThemAndAuditKeepsTheChange(): void
    {
        $read = ['other_items' => AccessLevel::READ->value];
        $write = ['other_items' => AccessLevel::WRITE->value];
        $body = ['origin' => ['debit_account_code' => '518', 'credit_account_code' => '325']];
        $args = ['side' => 'payable', 'code' => 'rent'];

        self::assertSame(403, $this->kinds->saveBuiltinPostingProfiles($this->request($read, $body), new Response(), $args)->getStatusCode());
        $saved = $this->json($this->kinds->saveBuiltinPostingProfiles($this->request($write, $body), new Response(), $args));
        self::assertSame('518', $saved['profiles']['origin']['debit_account_code']);

        $listed = $this->json($this->kinds->list($this->request($read, []), new Response()));
        $rent = array_values(array_filter($listed['items'], static fn (array $k): bool => $k['side'] === 'payable' && $k['builtin_code'] === 'rent'))[0];
        self::assertSame('518', $rent['posting_profiles']['origin']['debit_account_code']);
        self::assertSame('518', $rent['posting_suggestion']['debit_account_code']);

        $audit = $this->lastAudit('other_item_kind', (int) $saved['kind_id']);
        self::assertNull($audit['before']['origin']);
        self::assertSame('325', $audit['after']['origin']['credit_account_code']);

        $invalid = $this->kinds->saveBuiltinPostingProfiles($this->request($write, ['payment_bank' => ['debit_account_code' => '325']]), new Response(), $args);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('profile_breaks_saldo', (string) $invalid->getBody());
    }

    public function testForeignKindAndItemAreInvisible(): void
    {
        $write = ['other_items' => AccessLevel::WRITE->value];
        $foreignKind = $this->json($this->kinds->saveBuiltinPostingProfiles(
            $this->request($write, ['origin' => ['credit_account_code' => '325']], $this->otherSupplierId), new Response(),
            ['side' => 'payable', 'code' => 'rent']))['kind_id'];
        self::assertSame(404, $this->kinds->postingProfiles($this->request($write, []), new Response(), ['id' => (string) $foreignKind])->getStatusCode());
        self::assertSame(404, $this->kinds->savePostingProfiles($this->request($write, ['origin' => null]), new Response(), ['id' => (string) $foreignKind])->getStatusCode());

        $foreignItem = Bootstrap::buildContainer()->get(OtherItemService::class)->create($this->otherSupplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Cizí nájemné', 'issued_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'amount' => 100, 'counter_account_code' => '518',
        ], null);
        $response = $this->items->setPostingOverrides($this->request($write, ['payment_bank' => ['credit_account_code' => '221']]),
            new Response(), ['id' => (string) $foreignItem['id']]);
        self::assertSame(404, $response->getStatusCode());
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM other_item_posting_overrides WHERE other_item_id = ?');
        $count->execute([(int) $foreignItem['id']]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testOverridesEndpointNeedsWriteAndLogsChange(): void
    {
        $item = Bootstrap::buildContainer()->get(OtherItemService::class)->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Nájemné', 'issued_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'amount' => 100, 'counter_account_code' => '518',
        ], null);
        $body = ['posting_overrides' => ['payment_bank' => ['description_template' => 'Nájem {title}']]];
        $args = ['id' => (string) $item['id']];
        self::assertSame(403, $this->items->setPostingOverrides($this->request(['other_items' => AccessLevel::READ->value], $body), new Response(), $args)->getStatusCode());
        $result = $this->json($this->items->setPostingOverrides($this->request(['other_items' => AccessLevel::WRITE->value], $body), new Response(), $args));
        self::assertSame('item', $result['posting_rules']['payment_bank']['source']);
        self::assertSame('Nájem Nájemné', $result['posting_rules']['payment_bank']['description']);
        $audit = $this->lastAudit('other_item', (int) $item['id']);
        self::assertSame('Nájem {title}', $audit['after']['payment_bank']['description_template']);
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions, array $body, ?int $supplierId = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/accounting/other-items/kinds')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'staff'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Účetní', 'staff', true, $permissions));
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response, int $status = 200): array
    {
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        self::assertSame($status, $response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function lastAudit(string $entityType, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM activity_log
            WHERE supplier_id = ? AND entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$this->supplierId, $entityType, $id]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($payload);
        return $payload;
    }
}
