<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\CsasOAuthRepository;
use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use MyInvoice\Service\Bank\Connector\KbPlusCredentialVault;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\CsasApiClient;
use MyInvoice\Service\Bank\Connector\CsasCredentialVault;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Obnova tokenu bank přes společnou vrstvu: běží pod zámkem napojení, nový refresh token
 * se uloží dřív, než se použije, a souběžný požadavek se starým přístupem obnovu nezopakuje.
 * Banka je atrapa; volání bank se počítají.
 */
#[Group('integration')]
final class BankTokenRotationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if ($this->container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Chybí šifrovací klíč aplikace.');
        }
        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) $this->markTestSkipped('Testovací DB nemá firmu.');
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testCsasRefreshRunsUnderConnectionLockAndConcurrentRequestReusesRotatedToken(): void
    {
        $connectionId = $this->connection('0800', 'csas');
        $credentials = ['version' => 1, 'supplier_id' => $this->supplierId, 'connection_id' => $connectionId,
            'api_key' => 'synthetic-key', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
            'redirect_uri' => 'https://example.invalid/callback', 'access_token' => 'synthetic-access-1',
            'refresh_token' => 'synthetic-refresh-1', 'access_expires_at' => time() - 1, 'account_id' => 'synthetic-account',
            'account_iban' => 'CZ7508000000001000000005', 'account_currency' => 'CZK', 'call_guard_key' => str_repeat('a', 64),
            'environment' => 'production'];
        $api = $this->createStub(CsasApiClient::class);
        $api->method('environment')->willReturn('production');
        $lock = NamedLockName::for($this->db, 'bank-token', $this->supplierId . ':' . $connectionId);
        $calls = [];
        $api->method('refresh')->willReturnCallback(function (array $c) use ($lock, &$calls): array {
            $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $stmt->execute([$lock]);
            $calls[] = [$c['refresh_token'], (int) $stmt->fetchColumn()];
            return ['access_token' => 'synthetic-access-2', 'refresh_token' => 'synthetic-refresh-2', 'expires_in' => 300];
        });
        $vault = new CsasCredentialVault($api, new CsasOAuthRepository($this->db), $this->container->get(SecretEncryption::class),
            $this->container->get(OAuthClientFlow::class));
        $stale = $vault->encode($credentials);
        $this->store($connectionId, $stale, CsasCredentialVault::context($this->supplierId, $connectionId));

        $first = $vault->access($stale);
        // Druhý požadavek načetl přístup před obnovou: pod zámkem dostane ten obnovený.
        $second = $vault->access($stale);

        self::assertSame([['synthetic-refresh-1', 1]], $calls, 'Obnova jednou a pod zámkem napojení.');
        self::assertSame(['synthetic-refresh-2', 'synthetic-refresh-2'], [$first['refresh_token'], $second['refresh_token']]);
        self::assertSame('synthetic-refresh-2', $vault->decode($this->stored($connectionId, CsasCredentialVault::context($this->supplierId, $connectionId)))['refresh_token']);
    }

    public function testKbPlusRefreshRunsUnderConnectionLockAndConcurrentRequestReusesRotatedToken(): void
    {
        $connectionId = $this->connection('0100', 'kb_plus');
        $credentials = ['version' => 1, 'supplier_id' => $this->supplierId, 'connection_id' => $connectionId,
            'oauth_api_key' => 'synthetic-oauth-key', 'adaa_api_key' => 'synthetic-adaa-key', 'batchda_api_key' => '',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback',
            'scope' => 'adaa', 'refresh_token' => 'synthetic-refresh-1', 'access_token' => 'synthetic-access-1',
            'access_expires_at' => time() - 1, 'account_id' => 'synthetic-account', 'account_iban' => 'CZ0401000000191000000005',
            'account_currency' => 'CZK', 'call_guard_key' => str_repeat('g', 43)];
        $api = $this->createStub(KbPlusApiClient::class);
        $lock = NamedLockName::for($this->db, 'bank-token', $this->supplierId . ':' . $connectionId);
        $calls = [];
        $api->method('refreshAccessToken')->willReturnCallback(function (array $c) use ($lock, &$calls): array {
            $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $stmt->execute([$lock]);
            $calls[] = [$c['refresh_token'], (int) $stmt->fetchColumn()];
            return ['access_token' => 'synthetic-access-2', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'adaa',
                'refresh_token' => 'synthetic-refresh-2'];
        });
        $vault = new KbPlusCredentialVault($api, new KbPlusOAuthRepository($this->db), $this->container->get(SecretEncryption::class),
            $this->container->get(OAuthClientFlow::class));
        $stale = $vault->encode($credentials);
        $this->store($connectionId, $stale, KbPlusCredentialVault::context($this->supplierId, $connectionId));

        $first = $vault->access($stale);
        $second = $vault->access($stale);

        self::assertSame([['synthetic-refresh-1', 1]], $calls, 'Obnova jednou a pod zámkem napojení.');
        self::assertSame(['synthetic-access-2', 'synthetic-access-2'], [$first['access_token'], $second['access_token']]);
        self::assertSame('synthetic-refresh-2', $vault->decode($this->stored($connectionId, KbPlusCredentialVault::context($this->supplierId, $connectionId)))['refresh_token']);
    }

    private function connection(string $bankCode, string $provider): int
    {
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                account_number, bank_code) VALUES (?, 'XTS', 'XTS', 'X', 'Test', 'Test', 2, 1, 0, '1000000005', ?)")
            ->execute([$this->supplierId, $bankCode]);
        return $this->container->get(BankConnectionRepository::class)->ensure($this->supplierId, (int) $this->pdo->lastInsertId(), $provider);
    }

    private function store(int $connectionId, string $serialized, string $context): void
    {
        $this->pdo->prepare('UPDATE bank_connections SET token_ciphertext = ? WHERE id = ?')
            ->execute([$this->container->get(SecretEncryption::class)->encryptFor($serialized, $context), $connectionId]);
    }

    private function stored(int $connectionId, string $context): string
    {
        $stmt = $this->pdo->prepare('SELECT token_ciphertext FROM bank_connections WHERE id = ?');
        $stmt->execute([$connectionId]);
        return $this->container->get(SecretEncryption::class)->decryptFor((string) $stmt->fetchColumn(), $context);
    }
}
