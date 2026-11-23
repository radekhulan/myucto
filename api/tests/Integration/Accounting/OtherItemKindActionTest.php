<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Closing\DocumentSeriesAction;
use MyInvoice\Action\Accounting\OtherItemKindAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Akce druhů ostatních položek: vlastní řada mění číselné řady, proto ji smí zapnout či
 * vypnout jen role se zápisem do účetnictví (stejně jako úpravu řad v Nástrojích). Audit
 * nese, co se změnilo, a řadu druhu.
 */
#[Group('integration')]
final class OtherItemKindActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private OtherItemKindAction $action;
    private DocumentSeriesAction $series;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(OtherItemKindAction::class);
        $this->series = $container->get(DocumentSeriesAction::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) self::markTestSkipped('Chybí firma nebo uživatel.');
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testOwnSeriesNeedsAccountingWriteOtherChangesDoNot(): void
    {
        $agenda = ['other_items' => AccessLevel::WRITE->value, 'accounting' => AccessLevel::READ->value];
        $created = $this->json($this->action->create($this->request($agenda, ['side' => 'payable', 'name' => 'Nájemné']), new Response()), 201);

        $denied = [
            fn () => $this->action->create($this->request($agenda, ['side' => 'payable', 'name' => 'Dotace', 'own_series' => true, 'prefix' => 'DT']), new Response()),
            fn () => $this->action->update($this->request($agenda, ['own_series' => true, 'prefix' => 'NJ']), new Response(), ['id' => (string) $created['id']]),
            fn () => $this->action->updateBuiltin($this->request($agenda, ['own_series' => true, 'prefix' => 'NJ']), new Response(), ['side' => 'payable', 'code' => 'rent']),
        ];
        foreach ($denied as $call) {
            self::assertSame(403, $call()->getStatusCode());
        }
        // Skrytí a přejmenování řadu nemění — stačí zápis do agendy, i když tělo own_series nese beze změny.
        $this->json($this->action->updateBuiltin($this->request($agenda, ['is_active' => false, 'own_series' => false]), new Response(), ['side' => 'payable', 'code' => 'rent']));
        $this->json($this->action->update($this->request($agenda, ['name' => 'Nájem klubovny']), new Response(), ['id' => (string) $created['id']]));

        $full = ['other_items' => AccessLevel::WRITE->value, 'accounting' => AccessLevel::WRITE->value];
        $enabled = $this->json($this->action->update($this->request($full, ['own_series' => true, 'prefix' => 'NJ']), new Response(), ['id' => (string) $created['id']]));
        self::assertTrue($enabled['own_series']);
        self::assertSame('NJ', $enabled['series_prefix']);

        $audit = $this->lastAudit((int) $created['id']);
        self::assertSame('other_payable', $audit['series_code']);
        self::assertSame('NJ', $audit['series_prefix']);
        self::assertSame(['own_series' => ['from' => false, 'to' => true]], $audit['changes']);
    }

    public function testDisabledKindSeriesStaysEditableInToolsNeverEnabledOneNot(): void
    {
        $full = ['other_items' => AccessLevel::WRITE->value, 'accounting' => AccessLevel::WRITE->value];
        $rent = $this->json($this->action->create($this->request($full, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ']), new Response()), 201);
        $this->json($this->action->update($this->request($full, ['own_series' => false]), new Response(), ['id' => (string) $rent['id']]));
        $plain = $this->json($this->action->create($this->request($full, ['side' => 'payable', 'name' => 'Odvod']), new Response()), 201);

        $year = ['code' => 'other_payable', 'year' => (string) date('Y')];
        $edited = $this->series->update($this->request($full, ['prefix' => 'NJA', 'register_id' => $rent['id']]), new Response(), $year);
        self::assertSame(200, $edited->getStatusCode(), (string) $edited->getBody());
        $refused = $this->series->update($this->request($full, ['prefix' => 'OD', 'register_id' => $plain['id']]), new Response(), $year);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/accounting/other-items/kinds')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
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
    private function lastAudit(int $kindId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT payload FROM activity_log
            WHERE supplier_id = ? AND entity_type = 'other_item_kind' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->supplierId, $kindId]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($payload);
        return $payload;
    }
}
