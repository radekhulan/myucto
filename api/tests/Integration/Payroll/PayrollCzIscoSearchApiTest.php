<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollCzIscoAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** GET /api/payroll/cz-isco — našeptávač klasifikace zaměstnání ČSÚ. */
#[Group('integration')]
final class PayrollCzIscoSearchApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollCzIscoAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DI kontejner.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollCzIscoAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testFindsByCodeAndByNameWithoutDiacritics(): void
    {
        $byCode = $this->search(['q' => '43111']);
        self::assertSame(200, $byCode->getStatusCode(), (string) $byCode->getBody());
        $items = $this->json($byCode)['items'];
        self::assertSame('43111', $items[0]['code']);
        self::assertSame('Účetní všeobecní', $items[0]['label']);
        self::assertSame('4311', $items[0]['parent_code']);

        $byName = $this->json($this->search(['q' => 'ucetni']))['items'];
        self::assertContains('43111', array_column($byName, 'code'));
    }

    public function testHonoursLimitAndExposesCodebookProvenance(): void
    {
        $body = $this->json($this->search(['q' => 'ucetni', 'limit' => '3']));

        self::assertCount(3, $body['items']);
        self::assertSame('cz-isco-2026-02-01-v1', $body['codebook']['package_key']);
        self::assertSame('2026-02-01', $body['codebook']['classification_version']);
        self::assertSame('CC BY 4.0', $body['codebook']['licence']);
        self::assertSame(1992, $body['codebook']['entry_count']);
    }

    public function testRejectsEmptyShortAndOversizedQueries(): void
    {
        foreach ([[], ['q' => ''], ['q' => 'u'], ['q' => 'ucetni', 'limit' => '500']] as $params) {
            $response = $this->search($params);
            self::assertSame(422, $response->getStatusCode(), json_encode($params));
            self::assertSame('validation_failed', $this->json($response)['error']['code']);
        }
    }

    public function testBearerTokenCannotReachTheSuggester(): void
    {
        $response = $this->search(['q' => 'ucetni'], 'accountant', 'bearer');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    /** @param array<string,string> $params */
    private function search(
        array $params,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/cz-isco')
            ->withQueryParams($params)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $this->action->search($request, new Response());
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
