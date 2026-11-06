<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Http\AfterResponseTasks;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Zapomenuté heslo nesmí dobou odpovědi prozradit, že účet existuje.
 *
 * SMTP odeslání (desítky až stovky ms) běželo jen pro existující účet uvnitř
 * requestu. Teď jde e-mail až po odeslání odpovědi; kde to SAPI neumí, posílá
 * se synchronně a obě větve se srovnají na společné minimum.
 */
#[Group('integration')]
final class ForgotPasswordTimingTest extends TestCase
{
    private const IP = '203.0.113.58';

    private Connection $db;
    private Config $config;
    private string $email = '';
    private int $userId = 0;
    private int $mailsSent = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $loaded = Config::load($rootDir);
            $data = $loaded->all();
            $data['redis']['enabled'] = false;
            $data['captcha']['provider'] = 'none';
            $this->config = new Config($data, $loaded->dataDir());
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM password_resets LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }

        $this->email = 'forgot-' . bin2hex(random_bytes(5)) . '@example.test';
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, role_id, 1 FROM users WHERE role_id IS NOT NULL ORDER BY id LIMIT 1'
        )->execute([$this->email, password_hash('Synthetic-Forgot-2026', PASSWORD_BCRYPT), 'Forgot test']);
        $this->userId = (int) $pdo->lastInsertId();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Nepodařilo se založit dočasného uživatele.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->userId <= 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$this->userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $emailHash = sha1(strtolower($this->email));
        $pdo->prepare('DELETE FROM rate_limit_counters WHERE bucket_key LIKE ?')->execute(['rl:forgot:email:' . $emailHash]);
    }

    public function testResetMailForExistingAccountIsSentOnlyAfterResponse(): void
    {
        $tasks = new AfterResponseTasks(new NullLogger(), true);

        $response = $this->forgot($this->email, $tasks);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->mailsSent, 'E-mail se nesmí posílat uvnitř requestu.');
        self::assertTrue($tasks->hasPending());

        $tasks->run();

        self::assertSame(1, $this->mailsSent, 'Existující účet musí e-mail dostat.');
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$this->userId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testExistingAccountInOtherCaseStillGetsMail(): void
    {
        $tasks = new AfterResponseTasks(new NullLogger(), true);

        $this->forgot(strtoupper($this->email), $tasks);
        $tasks->run();

        self::assertSame(1, $this->mailsSent);
    }

    public function testUnknownAccountDefersNothing(): void
    {
        $tasks = new AfterResponseTasks(new NullLogger(), true);

        $response = $this->forgot('nikdo-' . bin2hex(random_bytes(4)) . '@example.test', $tasks);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($tasks->hasPending());
        self::assertSame(0, $this->mailsSent);
    }

    /** Bez možnosti odpojit klienta: mail synchronně, obě větve aspoň stejné minimum. */
    public function testWithoutDetachBothBranchesTakeTheSameMinimum(): void
    {
        $tasks = new AfterResponseTasks(new NullLogger(), false);
        $minimumNs = ForgotPasswordAction::MIN_SYNC_RESPONSE_MS * 1_000_000;

        $start = hrtime(true);
        $this->forgot($this->email, $tasks);
        $existing = hrtime(true) - $start;
        self::assertSame(1, $this->mailsSent);

        $start = hrtime(true);
        $this->forgot('nikdo-' . bin2hex(random_bytes(4)) . '@example.test', $tasks);
        $unknown = hrtime(true) - $start;

        self::assertGreaterThanOrEqual($minimumNs, $existing);
        self::assertGreaterThanOrEqual($minimumNs, $unknown);
        self::assertFalse($tasks->hasPending());
    }

    private function forgot(string $email, AfterResponseTasks $tasks): ResponseInterface
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(function (): string {
            $this->mailsSent++;
            return '250 OK';
        });
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn(self::IP);
        $turnstile = $this->createStub(TurnstileVerifier::class);
        $turnstile->method('verify')->willReturn(true);
        $redis = new RedisFactory($this->config);

        $action = new ForgotPasswordAction(
            $this->db,
            $mailer,
            new ActivityLogger($this->db),
            $ipMatcher,
            $this->config,
            new BruteForceGuard($this->config, $redis, $this->db),
            $turnstile,
            new RateLimitMiddleware($this->config, $redis, new ResponseFactory(), new IpMatcher(), $this->db),
            $tasks,
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/forgot', ['REMOTE_ADDR' => self::IP])
            ->withParsedBody(['email' => $email]);

        return $action($request, (new ResponseFactory())->createResponse());
    }
}
