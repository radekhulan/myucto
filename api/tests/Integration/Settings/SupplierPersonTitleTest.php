<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class SupplierPersonTitleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SettingsAction $action;
    private TaxReturnService $taxReturns;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje nakonfigurovanou testovací DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(SettingsAction::class);
        $this->taxReturns = $container->get(TaxReturnService::class);
        $pdo = $this->db->pdo();
        $sourceId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceId, 'Test potřebuje výchozí syntetickou firmu.');
        self::assertGreaterThan(0, $this->userId, 'Test potřebuje výchozího testovacího uživatele.');
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $pdo->prepare('UPDATE supplier SET person_title = NULL WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public static function updatePaths(): iterable
    {
        yield 'aktuální firma' => [false];
        yield 'firma podle id' => [true];
    }

    #[DataProvider('updatePaths')]
    public function testTitlePersistsIsReturnedAndCanBeCleared(bool $byId): void
    {
        $response = $this->save(['person_title' => ' Ing. Ph.D. '], $byId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Ing. Ph.D.', $this->storedTitle());
        $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Ing. Ph.D.', ($json['data'] ?? $json)['person_title']);
        self::assertSame(200, $this->save(['display_name' => '__TEST Titul OSVČ'], $byId)->getStatusCode());
        self::assertSame('Ing. Ph.D.', $this->storedTitle(), 'Vynechané pole nesmí smazat uložený titul.');
        self::assertSame(200, $this->save(['person_title' => '   '], $byId)->getStatusCode());
        self::assertNull($this->storedTitle());
        self::assertSame(200, $this->save(['person_title' => null], $byId)->getStatusCode());
        self::assertNull($this->storedTitle());
    }

    #[DataProvider('updatePaths')]
    public function testTitleLimitCountsCharactersAndRejectsOverflowWithoutChangingData(bool $byId): void
    {
        $title = str_repeat('Ž', 10);
        self::assertSame(200, $this->save(['person_title' => $title], $byId)->getStatusCode());
        self::assertSame($title, $this->storedTitle());
        $response = $this->save(['person_title' => $title . 'Ž'], $byId);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('validation_failed', (string) $response->getBody());
        self::assertSame($title, $this->storedTitle());
    }

    public function testCsszOverviewLoadsTitleForXml(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET person_title = ? WHERE id = ?')->execute(['Ing.', $this->supplierId]);
        $load = new \ReflectionMethod(TaxReturnService::class, 'loadSupplierCssz');
        self::assertSame('Ing.', $load->invoke($this->taxReturns, $this->supplierId)['person_title'] ?? null);
    }

    private function save(array $body, bool $byId): Response
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true])
            ->withParsedBody($body);
        return $byId
            ? $this->action->updateSupplierById($request, new Response(), ['id' => (string) $this->supplierId])
            : $this->action->updateSupplier($request, new Response());
    }

    private function storedTitle(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT person_title FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchColumn();
    }
}
