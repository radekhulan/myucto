<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\UserSettings;

use MyInvoice\Action\UserSettings\FavoriteAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/** Oblíbené stránky uživatele: CRUD, izolace uživatelů, validace cesty a limit. */
#[Group('integration')]
final class FavoriteActionTest extends TestCase
{
    private Connection $db;
    private FavoriteAction $action;
    private int $userId = 0;
    private int $otherUserId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(FavoriteAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->inTx = true;

        // Prázdná testovací databáze nemá firmu ani uživatele; zakládají se v transakci a vrací se rollbackem.
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $country = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $currency = (int) ($pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $vat = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            if ($country === 0 || $currency === 0 || $vat === 0) {
                $this->markTestSkipped('Chybí číselníky pro založení testovací firmy.');
            }
            $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(['Testovací firma', 'Ulice 1', 'Praha', '11000', $country, 'firma@example.test', $currency, $vat]);
            $this->supplierId = (int) $pdo->lastInsertId();
        }
        $ids = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN);
        while (count($ids) < 2) {
            $pdo->prepare('INSERT INTO users (email, password_hash, name, role, is_active) VALUES (?, ?, ?, ?, 1)')
                ->execute([bin2hex(random_bytes(6)) . '@example.test', 'x', 'Test', 'admin']);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $this->userId = (int) $ids[0];
        $this->otherUserId = (int) $ids[1];

        $pdo->prepare('DELETE FROM user_favorites WHERE user_id IN (?, ?)')->execute([$this->userId, $this->otherUserId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCreateListRenameDeleteRoundtrip(): void
    {
        $c = $this->call('create', 'POST', ['body' => ['path' => '/invoices?status=overdue', 'title' => '  Faktury  po splatnosti ']]);
        self::assertSame(201, $c['status']);
        self::assertSame('/invoices?status=overdue', $c['body']['path']);
        self::assertSame('Faktury po splatnosti', $c['body']['title']);
        self::assertSame($this->supplierId, $c['body']['supplier_id']);

        $list = $this->call('list', 'GET');
        self::assertCount(1, $list['body']);
        self::assertSame($c['body']['id'], $list['body'][0]['id']);

        $u = $this->call('update', 'PUT', ['args' => ['id' => $c['body']['id']], 'body' => ['title' => 'Po splatnosti']]);
        self::assertSame(200, $u['status']);
        self::assertSame('Po splatnosti', $u['body']['title']);

        $d = $this->call('delete', 'DELETE', ['args' => ['id' => $c['body']['id']]]);
        self::assertSame(200, $d['status']);
        self::assertSame([], $this->call('list', 'GET')['body']);
    }

    public function testSamePageInSameCompanyReturnsExistingItem(): void
    {
        $first = $this->call('create', 'POST', ['body' => ['path' => '/clients', 'title' => 'Klienti']]);
        $second = $this->call('create', 'POST', ['body' => ['path' => '/clients', 'title' => 'Jiný název']]);
        self::assertSame(201, $first['status']);
        self::assertSame(200, $second['status']);
        self::assertSame($first['body']['id'], $second['body']['id']);
        self::assertCount(1, $this->call('list', 'GET')['body']);
    }

    public function testOtherUserNeitherSeesNorChangesFavorite(): void
    {
        $c = $this->call('create', 'POST', ['body' => ['path' => '/clients', 'title' => 'Klienti']]);
        $id = $c['body']['id'];

        self::assertSame([], $this->call('list', 'GET', ['user' => $this->otherUserId])['body']);
        self::assertSame(404, $this->call('update', 'PUT', ['user' => $this->otherUserId, 'args' => ['id' => $id], 'body' => ['title' => 'X']])['status']);
        self::assertSame(404, $this->call('delete', 'DELETE', ['user' => $this->otherUserId, 'args' => ['id' => $id]])['status']);
        self::assertCount(1, $this->call('list', 'GET')['body']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPaths(): iterable
    {
        yield 'bez lomítka' => ['invoices'];
        yield 'protokol-relativní' => ['//evil.example/x'];
        yield 'schéma' => ['https://evil.example/x'];
        yield 'schéma v query' => ['/redirect?to=https://evil.example'];
        yield 'zpětné lomítko' => ['/\\evil.example'];
        yield 'řídicí znak' => ["/invoices\n"];
        yield 'dot segment' => ['/invoices/../admin'];
        yield 'příliš dlouhá' => ['/' . str_repeat('a', 500)];
        yield 'prázdná' => [''];
        yield 'pole' => [['/x']];
        yield 'null' => [null];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathIsRejected(mixed $path): void
    {
        $r = $this->call('create', 'POST', ['body' => ['path' => $path, 'title' => 'X']]);
        self::assertSame(422, $r['status']);
        self::assertSame('invalid_path', $r['body']['error']['code']);
    }

    public function testEmptyOrTooLongTitleIsRejected(): void
    {
        self::assertSame(422, $this->call('create', 'POST', ['body' => ['path' => '/x', 'title' => '  ']])['status']);
        self::assertSame(422, $this->call('create', 'POST', ['body' => ['path' => '/x', 'title' => str_repeat('a', 191)]])['status']);
    }

    public function testLimitIsEnforcedPerUser(): void
    {
        for ($i = 1; $i <= FavoriteAction::MAX_FAVORITES; $i++) {
            self::assertSame(201, $this->call('create', 'POST', ['body' => ['path' => "/p$i", 'title' => "P$i"]])['status']);
        }
        $over = $this->call('create', 'POST', ['body' => ['path' => '/over', 'title' => 'Over']]);
        self::assertSame(422, $over['status']);
        self::assertSame('favorite_limit_reached', $over['body']['error']['code']);

        self::assertSame(201, $this->call('create', 'POST', ['user' => $this->otherUserId, 'body' => ['path' => '/over', 'title' => 'Over']])['status']);
    }

    /** @param array<string,mixed> $opts @return array{status:int,body:array<mixed>} */
    private function call(string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/user/favorites')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $opts['user'] ?? $this->userId, 'role' => 'accountant']);
        if (array_key_exists('body', $opts)) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
