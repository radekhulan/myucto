<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionDeviceClass;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\License\PayrollUsagePolicy;
use MyInvoice\Service\License\SeatPolicy;
use MyInvoice\Service\License\SessionConcurrencyPolicy;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Obecné parametry licence (`params` v tokenu) a dvě funkce, které na nich
 * stojí: limit souběžných relací podle druhu zařízení a započítávání tokenů
 * se zápisem do licenčních míst.
 *
 * Vše běží v jedné transakci, která se na konci vrací.
 */
#[Group('integration')]
final class LicenseParamsTest extends TestCase
{
    use LicenseTokenTrait;

    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    private const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Connection $db;
    private Config $config;
    private LicenseService $license;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        if (!$this->db->hasTable('license') || !$this->db->hasColumn('sessions', 'device_class')) {
            self::fail('Chybí migrace license / 2245 — testovací DB není aktuální.');
        }
        $this->config = Config::load(dirname(__DIR__, 4));
        $this->license = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
            $this->createStub(LicenseClient::class),
        );

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testParamsFromValidTokenAreExposedAndInvalidOnesDropped(): void
    {
        $this->setToken([
            'max_browser_sessions' => 1,
            'max_mobile_sessions' => 2,
            'count_write_tokens_as_seats' => false,
            'Bad-Code' => 5,
            'nested' => ['x' => 1],
            'text' => '3',
        ]);
        $state = $this->license->current();

        self::assertSame(LicenseState::ACTIVE, $state->state);
        self::assertSame(
            ['max_browser_sessions' => 1, 'max_mobile_sessions' => 2, 'count_write_tokens_as_seats' => false],
            $state->params,
        );
        self::assertSame(2, $state->intParam(LicenseState::PARAM_MAX_MOBILE_SESSIONS));
        self::assertSame(0, $state->intParam('unknown_param'));
    }

    /** Propadlá licence je bezplatný základ: žádný parametr ji nesmí zpřísnit. */
    public function testExpiredLicenseHasNoParams(): void
    {
        $this->setToken(['max_browser_sessions' => 1], ['valid_until' => time() - 60]);
        $state = $this->license->current();

        self::assertSame(LicenseState::DEGRADED, $state->state);
        self::assertSame([], $state->params);
    }

    public function testBrowserLoginOverLimitRevokesOlderBrowserSessionOnly(): void
    {
        $this->setToken(['max_browser_sessions' => 1, 'max_mobile_sessions' => 1]);
        $userId = $this->createWriteUser();
        $sessions = $this->sessions();

        $first = $this->login($sessions, $userId, self::BROWSER_UA);
        $mobile = $this->login($sessions, $userId, self::MOBILE_UA);
        $second = $this->login($sessions, $userId, self::BROWSER_UA);

        self::assertNull($sessions->load($first), 'Starší relace v prohlížeči musí skončit.');
        self::assertTrue($sessions->isSuperseded($first));
        self::assertNotNull($sessions->load($mobile), 'Mobilní relace se limitem prohlížeče nemění.');
        self::assertNotNull($sessions->load($second));
        self::assertFalse($sessions->isSuperseded($second));
    }

    public function testLimitOfTwoKeepsBothSessions(): void
    {
        $this->setToken(['max_browser_sessions' => 2]);
        $userId = $this->createWriteUser();
        $sessions = $this->sessions();

        $first = $this->login($sessions, $userId, self::BROWSER_UA);
        $second = $this->login($sessions, $userId, self::BROWSER_UA);
        $third = $this->login($sessions, $userId, self::BROWSER_UA);

        self::assertNull($sessions->load($first));
        self::assertNotNull($sessions->load($second));
        self::assertNotNull($sessions->load($third));
    }

    /** Bez parametru (starší token, instalace bez licence) se nic neomezuje. */
    public function testWithoutParamSessionsAreUnlimited(): void
    {
        $this->setToken([]);
        $userId = $this->createWriteUser();
        $sessions = $this->sessions();

        $first = $this->login($sessions, $userId, self::BROWSER_UA);
        $second = $this->login($sessions, $userId, self::BROWSER_UA);

        self::assertNotNull($sessions->load($first));
        self::assertNotNull($sessions->load($second));
    }

    public function testDeviceClassFromUserAgent(): void
    {
        self::assertSame(SessionDeviceClass::BROWSER, SessionDeviceClass::fromUserAgent(self::BROWSER_UA));
        self::assertSame(SessionDeviceClass::MOBILE, SessionDeviceClass::fromUserAgent(self::MOBILE_UA));
        self::assertSame(SessionDeviceClass::MOBILE, SessionDeviceClass::fromUserAgent(
            'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
        ));
        self::assertSame(SessionDeviceClass::BROWSER, SessionDeviceClass::fromUserAgent(''));
    }

    public function testWriteTokensCountAsSeatsOnlyWithParam(): void
    {
        $userId = $this->createWriteUser();
        $this->setToken(['count_write_tokens_as_seats' => false], ['users' => 0]);
        $tokens = $this->tokens();
        $pdo = $this->db->pdo();
        $baseline = $this->license->current()->usersActive;

        $tokens->generateInTransaction($pdo, $userId, null, 'zápis', 'read_write');
        $tokens->generateInTransaction($pdo, $userId, null, 'čtení', 'read');
        self::assertSame($baseline, $this->license->current()->usersActive, 'Bez parametru tokeny místa nezabírají.');

        $this->setToken(['count_write_tokens_as_seats' => true], ['users' => 0]);
        self::assertSame($baseline + 1, $this->license->current()->usersActive, 'Počítá se jen token se zápisem.');
    }

    public function testWriteTokenOverSeatLimitIsRejectedButReadTokenPasses(): void
    {
        $userId = $this->createWriteUser();
        $this->setToken(['count_write_tokens_as_seats' => true], ['users' => 0]);
        $occupied = $this->license->current()->usersActive;
        $this->setToken(['count_write_tokens_as_seats' => true], ['users' => $occupied]);
        $tokens = $this->tokens();
        $pdo = $this->db->pdo();

        $read = $tokens->generateInTransaction($pdo, $userId, null, 'čtení', 'read');
        self::assertGreaterThan(0, $read['id']);

        $this->expectException(LicenseSeatLimitExceeded::class);
        $tokens->generateInTransaction($pdo, $userId, null, 'zápis', 'read_write');
    }

    public function testWriteTokenIsNotGatedWithoutParam(): void
    {
        $userId = $this->createWriteUser();
        $this->setToken([], ['users' => 1]);

        $token = $this->tokens()->generateInTransaction($this->db->pdo(), $userId, null, 'zápis', 'read_write');

        self::assertGreaterThan(0, $token['id']);
    }

    private function sessions(): SessionManager
    {
        return new SessionManager(
            $this->db,
            $this->config,
            new DatabaseSecurityClock(),
            new SessionConcurrencyPolicy($this->license),
        );
    }

    private function tokens(): ApiTokenService
    {
        return new ApiTokenService(
            $this->db,
            new RedisFactory($this->config),
            new LicenseCapacityGate($this->db, $this->license, new SeatPolicy($this->db), new PayrollUsagePolicy($this->db)),
        );
    }

    private function login(SessionManager $sessions, int $userId, string $userAgent): string
    {
        $pdo = $this->db->pdo();
        $created = $sessions->createInTransaction(
            $pdo,
            (new DatabaseSecurityClock())->capture($pdo),
            $userId,
            '192.0.2.10',
            $userAgent,
            SessionAuthContext::basic('password'),
        );
        return $created['token'];
    }

    private function createWriteUser(): int
    {
        $pdo = $this->db->pdo();
        $roleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE system_key = 'superadmin' AND is_active = 1 LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([
            'license-params-' . bin2hex(random_bytes(6)) . '@example.test',
            password_hash('synthetic-test-password', PASSWORD_DEFAULT),
            'Parametry licence',
            $roleId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $overrides
     */
    private function setToken(array $params, array $overrides = []): void
    {
        $token = $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-' . bin2hex(random_bytes(4)),
            'params'        => (object) $params,
        ], $overrides));
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, token_payload = NULL WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $token]);
        $this->license->forgetCachedRow();
    }
}
