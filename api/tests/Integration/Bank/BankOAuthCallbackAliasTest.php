<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\OAuth\OAuthCallbackAction;
use MyInvoice\Action\Settings\CsasOnboardingAction;
use MyInvoice\Action\Settings\KbPlusOnboardingAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Redirect URI registrovaná u banky zůstává aliasem společného callbacku: přihlášení
 * ze společné vrstvy převezme handler domény bank, přihlášení zahájené před přechodem
 * (bank_oauth_sessions) dokončí původní cesta. Účet je záměrně neaktivní, takže se
 * dokončení zastaví před výměnou kódu u banky.
 */
#[Group('integration')]
final class BankOAuthCallbackAliasTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    /** @var array<string,mixed> */
    private array $user;
    private int $supplierId = 0;
    private int $currencyId = 0;

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
        $userId = (int) ($this->pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active = 1 AND r.system_key = 'superadmin' ORDER BY u.id LIMIT 1")->fetchColumn() ?: 0);
        $user = $userId > 0 ? $this->container->get(CrossSupplierAuthorization::class)->activeUser($userId) : null;
        if ($source === 0 || $user === null) {
            $this->markTestSkipped('Testovací DB nemá firmu nebo aktivního superadmina.');
        }
        $this->user = $user;
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                account_number, bank_code) VALUES (?, 'XTS', 'XTS', 'X', 'Test', 'Test', 2, 0, 0, '1000000005', '0800')")
            ->execute([$this->supplierId]);
        $this->currencyId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testSharedFlowLoginIsCompletedThroughTheRegisteredBankCallback(): void
    {
        $state = $this->sharedLogin('csas');

        $response = $this->container->get(CsasOnboardingAction::class)->callback($this->request($state), new Response());

        // Návrat nese účet a důvod, aby hláška u účtu řekla, proč připojení neprošlo.
        self::assertSame('/bank?tab=accounts&csas=error&currency_id=' . $this->currencyId . '&code=account_inactive',
            $response->getHeaderLine('Location'));
        self::assertSame(['failed', 'account_inactive', ''], $this->sharedRow($state));
    }

    public function testSharedCallbackRouteCompletesBankLoginToo(): void
    {
        $state = $this->sharedLogin('csas');

        $response = $this->container->get(OAuthCallbackAction::class)($this->request($state, 0), new Response());

        self::assertStringStartsWith('/bank?tab=accounts&csas=error&currency_id=' . $this->currencyId . '&code=', $response->getHeaderLine('Location'));
        self::assertSame('failed', $this->sharedRow($state)[0]);
    }

    public function testLoginStartedBeforeTheSharedLayerStillFinishesThroughLegacySessions(): void
    {
        $state = bin2hex(random_bytes(32));
        $hash = hash('sha256', $state);
        $ciphertext = $this->container->get(SecretEncryption::class)->encryptFor((string) json_encode(['api_key' => 'synthetic-key',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'code_verifier' => str_repeat('b', 64),
            'redirect_uri' => 'https://example.invalid/api/settings/bank-connections/csas/oauth/callback']),
            'bank-csas-oauth:supplier:' . $this->supplierId . ':state:' . $hash);
        $this->pdo->prepare("INSERT INTO bank_oauth_sessions (state_hash, supplier_id, currency_id, user_id, provider, stage, secret_ciphertext, expires_at)
            VALUES (?, ?, ?, ?, 'csas', 'oauth', ?, TIMESTAMPADD(SECOND, 600, CURRENT_TIMESTAMP(6)))")
            ->execute([$hash, $this->supplierId, $this->currencyId, $this->user['id'], $ciphertext]);

        $response = $this->container->get(CsasOnboardingAction::class)->callback($this->request($state), new Response());

        self::assertSame('/bank?tab=accounts&csas=error&code=account_inactive', $response->getHeaderLine('Location'));
        $stmt = $this->pdo->prepare('SELECT status, secret_ciphertext FROM bank_oauth_sessions WHERE state_hash = ?');
        $stmt->execute([$hash]);
        self::assertSame(['status' => 'failed', 'secret_ciphertext' => ''], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testBankCallbackDoesNotCompleteLoginOfAnotherProvider(): void
    {
        $state = $this->sharedLogin('kb_plus');

        $response = $this->container->get(CsasOnboardingAction::class)->callback($this->request($state), new Response());

        self::assertSame('/bank?tab=accounts&csas=error&code=csas_oauth_expired', $response->getHeaderLine('Location'));
        self::assertSame('pending', $this->sharedRow($state)[0]);
    }

    public function testKbPlusConsentFromSharedFlowGoesThroughTheRegisteredKbCallback(): void
    {
        $guard = str_repeat('g', 43);
        $this->coolDown($guard);
        $state = $this->sharedLogin('kb_plus', ['call_guard_key' => $guard, 'state' => 'x']);

        $response = $this->container->get(KbPlusOnboardingAction::class)->oauthCallback($this->request($state), new Response());

        self::assertStringEndsWith('/bank?tab=accounts&kb_plus=error&currency_id=' . $this->currencyId . '&code=bank_rate_limited',
            $response->getHeaderLine('Location'));
        self::assertSame(['failed', 'bank_rate_limited', ''], $this->sharedRow($state));
    }

    public function testKbPlusConsentStartedBeforeTheSharedLayerStillFinishes(): void
    {
        $guard = str_repeat('h', 43);
        $this->coolDown($guard);
        $state = str_repeat('k', 43);
        $hash = hash('sha256', $state);
        $ciphertext = $this->container->get(SecretEncryption::class)->encryptFor((string) json_encode(['call_guard_key' => $guard,
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'state' => $state]),
            sprintf('kb-plus-oauth:supplier:%d:currency:%d:user:%d:state:%s:stage:oauth', $this->supplierId, $this->currencyId, $this->user['id'], $hash));
        $this->pdo->prepare("INSERT INTO bank_oauth_sessions (state_hash, supplier_id, currency_id, user_id, provider, stage, secret_ciphertext, expires_at)
            VALUES (?, ?, ?, ?, 'kb_plus', 'oauth', ?, TIMESTAMPADD(SECOND, 300, CURRENT_TIMESTAMP(6)))")
            ->execute([$hash, $this->supplierId, $this->currencyId, $this->user['id'], $ciphertext]);

        $response = $this->container->get(KbPlusOnboardingAction::class)->oauthCallback($this->request($state), new Response());

        self::assertStringEndsWith('&code=bank_rate_limited', $response->getHeaderLine('Location'));
        $stmt = $this->pdo->prepare('SELECT status, error_code FROM bank_oauth_sessions WHERE state_hash = ?');
        $stmt->execute([$hash]);
        self::assertSame(['status' => 'failed', 'error_code' => 'bank_rate_limited'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** Ochrana proti opakovanému volání banky zastaví dokončení dřív, než by se volala KB. */
    private function coolDown(string $callGuardKey): void
    {
        $key = base64_decode((string) $this->container->get(Config::class)->get('app.secret_encryption_key', ''), true);
        if ($key === false || strlen($key) !== 32) {
            self::markTestSkipped('Chybí šifrovací klíč aplikace.');
        }
        $this->pdo->prepare('INSERT INTO bank_connector_cooldowns (credential_hash, last_called_at) VALUES (?, CURRENT_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE last_called_at = CURRENT_TIMESTAMP(6)')->execute([hash_hmac('sha256', $callGuardKey, $key)]);
    }

    private function sharedLogin(string $provider, array $secret = []): string
    {
        $flow = $this->container->get(OAuthClientFlow::class);
        $authorization = $flow->authorize($provider === 'csas', $provider === 'csas' ? OAuthClientFlow::STATE_HEX : OAuthClientFlow::STATE_BASE64URL);
        $flow->store($authorization, $this->supplierId, (int) $this->user['id'], 'bank', $provider, $this->currencyId,
            OAuthClientFlow::FLOW_REDIRECT, $secret + ['api_key' => 'synthetic-key', 'client_id' => 'synthetic-client',
                'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback'], 600);
        return $authorization->state;
    }

    /** @return array{0:string, 1:?string, 2:string} */
    private function sharedRow(string $state): array
    {
        $stmt = $this->pdo->prepare('SELECT status, error_code, secret_ciphertext FROM oauth_sessions WHERE state_hash = ?');
        $stmt->execute([hash('sha256', $state)]);
        return $stmt->fetch(PDO::FETCH_NUM);
    }

    /** Návrat z banky: v prohlížeči přihlášený uživatel, firma podle volby v aplikaci (zde `$currentSupplier`). */
    private function request(string $state, ?int $currentSupplier = null): Request
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/bank-connections/csas/oauth/callback')
            ->withQueryParams(['state' => $state, 'code' => 'synthetic-code'])
            ->withAttribute(AuthMiddleware::ATTR_USER, $this->user)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $currentSupplier ?? $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin'));
    }
}
