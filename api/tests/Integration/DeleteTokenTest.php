<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Auth\Tokens\DeleteTokenAction;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Trvalé smazání API tokenu — doplněk ke zrušení.
 *
 * Zrušení nechá náhrobek, mazání ne. Proto tu jde hlavně o dvě věci: cizí token
 * nesmí jít smazat a po smazání musí zůstat auditní stopa — jinak by mazání
 * bylo jediná operace nad tokeny, po které není poznat, co zmizelo.
 *
 * Spustit: vendor/bin/phpunit --filter=DeleteTokenTest
 */
#[Group('integration')]
final class DeleteTokenTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private int $userId = 0;
    private int $otherUserId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM api_tokens LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }

        $this->userId = $this->createTempUser();
        $this->otherUserId = $this->createTempUser();
    }

    protected function tearDown(): void
    {
        foreach ([$this->userId, $this->otherUserId] as $id) {
            if ($id > 0) {
                $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            }
        }
    }

    private function createTempUser(): int
    {
        $pdo = $this->db->pdo();
        $hash = (new PasswordHasher($this->config))->hash('Str0ng-Test-Pwd-2026');
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, role_id, 1 FROM users WHERE role_id IS NOT NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute(['deltok-' . bin2hex(random_bytes(6)) . '@example.test', $hash, 'Delete token test']);
        return (int) $pdo->lastInsertId();
    }

    private function tokens(): ApiTokenService
    {
        return new ApiTokenService($this->db, new RedisFactory($this->config));
    }

    private function makeToken(int $userId, string $name = 'Test token'): int
    {
        return $this->tokens()->generate($userId, null, $name, 'read_write', null, true)['id'];
    }

    private function invoke(int $tokenId, int $actingUserId, string $authMethod = 'session'): ResponseInterface
    {
        $action = new DeleteTokenAction($this->tokens(), new ActivityLogger($this->db), new IpMatcher());
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', 'http://localhost/api/auth/tokens/' . $tokenId . '/purge',
                ['REMOTE_ADDR' => '203.0.113.44'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $actingUserId, 'role' => 'accountant']);

        return $action($request, (new ResponseFactory())->createResponse(), ['id' => (string) $tokenId]);
    }

    private function exists(int $tokenId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM api_tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function testOwnTokenIsDeletedForGood(): void
    {
        $tokenId = $this->makeToken($this->userId);

        $res = $this->invoke($tokenId, $this->userId);

        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($this->exists($tokenId), 'Řádek tokenu musí zmizet, ne jen dostat revoked_at');
    }

    /** Mazání smaže i doklad o tom, co token dělal — audit proto musí zůstat. */
    public function testDeletionLeavesAuditTrailWithTokenSnapshot(): void
    {
        $tokenId = $this->makeToken($this->userId, 'Mzdový import');

        self::assertSame(200, $this->invoke($tokenId, $this->userId)->getStatusCode());

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE action = 'api_token.deleted' AND entity_id = ? AND user_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$tokenId, $this->userId]);
        $payload = $stmt->fetchColumn();

        self::assertNotFalse($payload, 'Smazání se musí zapsat do aktivity');
        $decoded = (array) json_decode((string) $payload, true);
        self::assertSame('Mzdový import', $decoded['name'] ?? null);
        self::assertSame('read_write', $decoded['scope'] ?? null);
        self::assertTrue($decoded['allow_payroll_submission_docs'] ?? false);
        self::assertFalse($decoded['was_revoked'] ?? true, 'Token nebyl zrušený, jen rovnou smazaný');
    }

    public function testForeignTokenCannotBeDeleted(): void
    {
        $tokenId = $this->makeToken($this->otherUserId);

        $res = $this->invoke($tokenId, $this->userId);

        self::assertSame(404, $res->getStatusCode());
        self::assertTrue($this->exists($tokenId), 'Cizí token musí zůstat nedotčený');
    }

    /** Token nesmí umět smazat sebe ani sourozence — s ním by zmizela i stopa. */
    public function testBearerCannotDeleteToken(): void
    {
        $tokenId = $this->makeToken($this->userId);

        $res = $this->invoke($tokenId, $this->userId, 'bearer');
        $res->getBody()->rewind();
        $payload = (array) json_decode((string) $res->getBody()->getContents(), true);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('session_required', $payload['error']['code'] ?? null);
        self::assertTrue($this->exists($tokenId));
    }

    /** IP pravidla visí na tokenu přes FK CASCADE — nesmí po něm zůstat sirotci. */
    public function testIpRulesGoAwayWithTheToken(): void
    {
        $tokenId = $this->makeToken($this->userId);
        $this->tokens()->addIpRule($tokenId, $this->userId, '203.0.113.0/24', 'test');

        self::assertSame(200, $this->invoke($tokenId, $this->userId)->getStatusCode());

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM api_token_ips WHERE token_id = ?');
        $stmt->execute([$tokenId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }
}
