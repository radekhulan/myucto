<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Supplier\SupplierCreator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Interní štítky na fakturách a šablonách pravidelné fakturace (migrace 2349) jsou
 * volba firmy, výchozí vypnuto. Nastavení ji uloží a /auth/me ji předá editoru,
 * detailu i seznamům, které podle ní štítky skryjí.
 */
#[Group('integration')]
final class TagsEnabledSettingsTest extends TestCase
{
    private ContainerInterface $container;
    private \PDO $pdo;
    private SettingsAction $action;
    private ServerRequestInterface $request;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje: test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->action = $this->container->get(SettingsAction::class);
        $this->pdo->beginTransaction();

        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')->execute([
            'tags-enabled-' . bin2hex(random_bytes(6)) . '@example.test',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'Tags settings test', 'admin',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->supplierId = $this->container->get(SupplierCreator::class)->create([
            'company_name' => 'Syntetická firma se štítky', 'street' => 'Testovací 1',
            'city' => 'Testov', 'zip' => '10000', 'email' => 'stitky@example.test',
        ], $this->userId, false);
        $this->request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function supplier(): array
    {
        $response = $this->action->getSupplier($this->request, new Response());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function save(bool $enabled): void
    {
        $response = $this->action->updateSupplier($this->request->withParsedBody(['tags_enabled' => $enabled]), new Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function meTagsEnabled(): mixed
    {
        $request = $this->request->withAttribute(AuthMiddleware::ATTR_USER, [
            'id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true,
        ]);
        $response = $this->container->get(MeAction::class)($request, new Response());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        foreach ((array) ($body['suppliers'] ?? []) as $supplier) {
            if ((int) $supplier['id'] === $this->supplierId) {
                return $supplier['tags_enabled'] ?? null;
            }
        }
        self::fail('Firma v /auth/me chybí.');
    }

    public function testTagsAreOffByDefault(): void
    {
        self::assertFalse($this->supplier()['tags_enabled'] ?? null);
        self::assertFalse($this->meTagsEnabled());
    }

    public function testSettingRoundTripsAndReachesSession(): void
    {
        $this->save(true);
        self::assertTrue($this->supplier()['tags_enabled'] ?? null);
        self::assertTrue($this->meTagsEnabled(), 'Editor, detail i seznamy čtou volbu z /auth/me.');

        $this->save(false);
        self::assertFalse($this->supplier()['tags_enabled'] ?? null);
        self::assertFalse($this->meTagsEnabled());
    }
}
