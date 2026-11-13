<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\PasswordHasher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Black-box POST /api/v1/auth/password-token proti běžícímu serveru: veřejná cesta
 * bez session, cookies a CSRF (jak ji volá mobilní aplikace) a vrácený token
 * projde `GET /api/v1/auth/api-me`.
 *
 * Vyžaduje běžící server nad toutéž DB (env MYINVOICE_TEST_URL) se zapnutým
 * `auth.password_token.enabled`, jinak se přeskočí.
 * Spustit: vendor/bin/phpunit --filter=PasswordTokenHttpTest
 */
#[Group('integration')]
#[Group('http-integration')]
final class PasswordTokenHttpTest extends TestCase
{
    private const PASSWORD = 'Str0ng-Test-Pwd-2026';

    private string $baseUrl;
    private Connection $db;
    private Config $config;
    private int $userId = 0;
    private string $email = '';

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string) (getenv('MYINVOICE_TEST_URL') ?: 'https://dev.myucto.cz'), '/');
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->request('GET', '/api/health')['status'] === 0) {
            $this->markTestSkipped("Server {$this->baseUrl} unreachable");
        }

        $this->email = 'pwd-token-http-' . bin2hex(random_bytes(6)) . '@example.test';
        $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, id, 1 FROM roles WHERE system_key = ? LIMIT 1'
        )->execute([$this->email, (new PasswordHasher($this->config))->hash(self::PASSWORD), 'Password token HTTP test', 'admin']);
        $this->userId = (int) $this->db->pdo()->lastInsertId();

        // Server musí číst tutéž DB, do které test zakládá uživatele: sonda tokenem
        // založeným přímo v DB (špatné heslo by vrátilo 401 i nad jinou DB).
        $tokens = new ApiTokenService($this->db, new RedisFactory($this->config));
        $probeToken = $tokens->generate($this->userId, null, 'probe', 'read');
        $me = $this->request('GET', '/api/v1/auth/api-me', null, $probeToken['plaintext']);
        $tokens->revoke($probeToken['id'], $this->userId);
        if ($me['status'] !== 200) {
            $this->markTestSkipped("Server {$this->baseUrl} čte jinou databázi.");
        }

        // Endpoint musí server znát a mít zapnutý (auth.password_token.enabled).
        $probe = $this->request('POST', '/api/v1/auth/password-token', ['email' => $this->email, 'password' => 'x']);
        if ((json_decode($probe['body'], true)['error']['code'] ?? null) !== 'invalid_credentials') {
            $this->markTestSkipped('Server endpoint nezná nebo ho má vypnutý.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            (new BruteForceGuard($this->config, new RedisFactory($this->config), $this->db))
                ->recordSuccess($this->email, '127.0.0.1');
            $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$this->email]);
        }
    }

    public function testIssuedTokenPassesApiMe(): void
    {
        $res = $this->request('POST', '/api/v1/auth/password-token', [
            'email' => $this->email,
            'password' => self::PASSWORD,
            'device_name' => 'MyÚčto iPhone',
        ]);

        self::assertSame(201, $res['status'], $res['body']);
        $body = (array) json_decode($res['body'], true);
        self::assertStringStartsWith('mi_pat_', (string) ($body['token'] ?? ''));

        $me = $this->request('GET', '/api/v1/auth/api-me', null, (string) $body['token']);
        self::assertSame(200, $me['status'], $me['body']);
        $meBody = (array) json_decode($me['body'], true);
        self::assertSame($this->userId, $meBody['user']['id'] ?? null);
        self::assertSame('read_write', $meBody['token']['scope'] ?? null);
    }

    public function testWrongPasswordIsRejectedWithoutSessionOrCsrf(): void
    {
        $res = $this->request('POST', '/api/v1/auth/password-token', [
            'email' => $this->email,
            'password' => 'Spatne-Heslo-2026',
        ]);

        self::assertSame(401, $res['status'], $res['body']);
        self::assertSame('invalid_credentials', json_decode($res['body'], true)['error']['code'] ?? null);
        self::assertArrayNotHasKey('set-cookie', array_change_key_case($res['headers'], CASE_LOWER), 'Žádná session');
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    private function request(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $raw = is_string($resp) ? $resp : '';

        $headersOut = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[trim($k)] = trim($v);
            }
        }

        return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => $headersOut];
    }
}
