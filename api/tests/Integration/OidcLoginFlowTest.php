<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Auth\OidcAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\Oidc\ExternalIdentityRepository;
use MyInvoice\Service\Auth\Oidc\IdTokenVerifier;
use MyInvoice\Service\Auth\Oidc\JwksCache;
use MyInvoice\Service\Auth\Oidc\OidcException;
use MyInvoice\Service\Auth\Oidc\OidcFlowStore;
use MyInvoice\Service\Auth\Oidc\OidcInviteService;
use MyInvoice\Service\Auth\Oidc\OidcLoginService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\FakeOidcIdp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přihlášení přes Entra ID od pozvánky po vynucení pro firmu. Poskytovatel je
 * syntetický (FakeOidcIdp), databáze testovací.
 */
#[Group('integration')]
final class OidcLoginFlowTest extends TestCase
{
    private const IP = '203.0.113.17';
    private const UA = 'OidcLoginFlowTest';

    private Connection $db;
    private Config $config;
    private FakeOidcIdp $idp;
    private OidcLoginService $service;
    private OidcInviteService $invites;
    private ExternalIdentityRepository $identities;
    private SessionManager $sessions;
    private LoginPolicyService $policy;
    private int $userId = 0;
    private string $email = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $base = Config::load($rootDir);
        $data = $base->all();
        $data['app']['url'] = 'https://ucto.example.test';
        $data['auth']['oidc'] = [
            'enabled' => true,
            'session_lifetime_hours' => 12,
            'providers' => ['entra' => [
                'enabled' => true,
                'label' => 'Microsoft',
                'tenant_id' => FakeOidcIdp::TENANT,
                'client_id' => FakeOidcIdp::CLIENT,
                'client_secret' => 'test-secret',
                'allow_guests' => true,
            ]],
        ];
        $this->config = new Config($data, $base->dataDir());
        try {
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM user_external_identities LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }

        $this->idp = new FakeOidcIdp();
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-it-' . bin2hex(random_bytes(4));
        $registry = new OidcProviderRegistry($this->config);
        $clock = new DatabaseSecurityClock();
        $this->sessions = new SessionManager($this->db, $this->config, $clock);
        $this->identities = new ExternalIdentityRepository($this->db);
        $this->invites = new OidcInviteService($this->db);
        $this->policy = new LoginPolicyService($this->config, $registry);
        $this->service = new OidcLoginService(
            $this->db,
            $registry,
            $this->idp,
            new IdTokenVerifier(new JwksCache($this->idp, $this->cacheDir)),
            new OidcFlowStore($this->db),
            $this->identities,
            $this->invites,
            $this->policy,
            new MfaStepUpService(
                new MfaStepUpProofStore($this->db, $clock),
                new MfaPolicyService($this->config),
                new PasskeyCredentialRepository($this->db),
            ),
            $this->sessions,
            new ActivityLogger($this->db),
            new \MyInvoice\Service\Auth\SessionLockService($this->db,
                new \MyInvoice\Service\Auth\SessionLockPolicy($this->config), $clock,
                new \MyInvoice\Service\Auth\WebAuthnCeremonyStore($this->db, $clock)),
            $clock,
        );
        $this->createUser();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->userId > 0) {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM mfa_step_up_proofs WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM oidc_login_flows WHERE user_id = ? OR user_id IS NULL')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testInviteLinksIdentityAndLaterLoginMatchesByOidNotEmail(): void
    {
        $oid = self::guid();
        $token = $this->invites->issue($this->userId, 'entra', null);

        $result = $this->loginRoundTrip($oid, invite: $token);
        self::assertSame($this->userId, (int) $result->user['id']);
        self::assertNotNull($result->externalIdentityId);

        // Druhé přihlášení bez pozvánky: páruje se podle oid, e-mail z tokenu je jiný.
        $again = $this->loginRoundTrip($oid, claims: ['preferred_username' => 'nekdo-jiny@example.test']);
        self::assertSame($this->userId, (int) $again->user['id']);

        // Pozvánka je jednorázová.
        $this->expectOidcError(OidcException::INVITE_INVALID, fn () => $this->loginRoundTrip(self::guid(), invite: $token));
    }

    public function testInviteIsRejectedForOtherEmailAndStaysValidForInvitee(): void
    {
        $token = $this->invites->issue($this->userId, 'entra', null);
        $this->expectOidcError(
            OidcException::IDENTITY_MISMATCH,
            fn () => $this->loginRoundTrip(self::guid(), invite: $token, claims: ['preferred_username' => 'cizi@example.test']),
        );
        $result = $this->loginRoundTrip(self::guid(), invite: $token);
        self::assertSame($this->userId, (int) $result->user['id']);
    }

    public function testStepUpWithoutFreshAuthTimeIsRejected(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));

        $this->expectOidcError(OidcException::TOKEN_INVALID, fn () => $this->stepUpRoundTrip($session['token'], $oid, claims: []));
        $this->expectOidcError(
            OidcException::TOKEN_INVALID,
            fn () => $this->stepUpRoundTrip($session['token'], $oid, claims: ['auth_time' => time() - 3600]),
        );
    }

    public function testGoogleLoginLinksByInviteAndYieldsBrowserBoundTicket(): void
    {
        $invite = $this->invites->issue($this->userId, 'google', null);
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::googleProvider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', inviteToken: $invite);
        $this->idp->nextClaims = $this->idp->googleClaims('424242', (string) $this->idp->lastNonce, ['email' => $this->email]);
        $result = $this->service->complete(
            FakeOidcIdp::googleProvider(),
            ['state' => self::queryParam($url, 'state'), 'code' => 'c'],
            $binding,
            null,
            self::IP,
            self::UA,
        );
        self::assertSame($this->userId, (int) $result->user['id']);

        $flows = new OidcFlowStore($this->db);
        $ticket = $flows->createTicket('google', $binding, $this->userId, (int) $result->externalIdentityId, '/invoices');
        self::assertNull($flows->peekTicket($ticket, OidcFlowStore::newBinding()), 'Lístek z cizího prohlížeče.');
        self::assertSame($this->userId, $flows->peekTicket($ticket, $binding)['user_id'] ?? null);
        // Opakované čtení je povolené (heslo → druhý faktor), spotřebuje ho až vydání sezení.
        self::assertNotNull($flows->peekTicket($ticket, $binding));
        $flows->consumeTicket($ticket);
        self::assertNull($flows->peekTicket($ticket, $binding));

        // Po odpojení identity už nevydá sezení ani dřív vydaný lístek.
        $second = $flows->createTicket('google', $binding, $this->userId, (int) $result->externalIdentityId, null);
        $this->identities->unlink($this->userId, (int) $result->externalIdentityId);
        self::assertNull($flows->peekTicket($second, $binding));

        // Lístek nejde podstrčit jako state návratu od poskytovatele.
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->service->complete(
            FakeOidcIdp::googleProvider(), ['state' => $ticket, 'code' => 'c'], $binding, null, self::IP, self::UA,
        ));
    }

    public function testFirstFactorProviderCannotStartStepUp(): void
    {
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));
        $this->expectOidcError(OidcException::PROVIDER_DISABLED, fn () => $this->service->start(
            FakeOidcIdp::googleProvider(),
            OidcFlowStore::PURPOSE_STEP_UP,
            OidcFlowStore::newBinding(),
            '/settings',
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_EPO_CERTIFICATE,
        ));
    }

    public function testUnlinkedIdentityIsRejectedEvenWithMatchingEmail(): void
    {
        $email = (string) $this->db->pdo()->query('SELECT email FROM users WHERE id = ' . $this->userId)->fetchColumn();
        $this->expectOidcError(
            OidcException::NOT_LINKED,
            fn () => $this->loginRoundTrip(self::guid(), claims: ['preferred_username' => $email, 'email' => $email]),
        );
    }

    public function testStateIsSingleUseAndBoundToBrowser(): void
    {
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, null);
        $state = self::queryParam($url, 'state');

        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->complete($state, OidcFlowStore::newBinding()));

        // Odmítnutí s cizí vazbou záznam nespotřebuje, se správnou vazbou ale jen jednou.
        $this->idp->nextClaims = $this->idp->claims(self::guid(), (string) $this->idp->lastNonce);
        try {
            $this->complete($state, $binding);
        } catch (OidcException) {
        }
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->complete($state, $binding));
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);

        $this->expectOidcError(OidcException::USER_INACTIVE, fn () => $this->loginRoundTrip($oid));
    }

    public function testExternalSessionIsCappedToConfiguredLifetime(): void
    {
        $oid = self::guid();
        $result = $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create(
            $this->userId,
            self::IP,
            self::UA,
            SessionAuthContext::externalIdentity('entra', new \DateTimeImmutable(), (int) $result->externalIdentityId),
        );
        $lifetime = $session['expires_at'] - $session['issued_at']->getTimestamp();
        self::assertLessThanOrEqual(12 * 3600, $lifetime);
        self::assertGreaterThan(11 * 3600, $lifetime);
    }

    public function testStepUpIssuesProofOnlyForLinkedIdentityOfSameSession(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));

        $result = $this->stepUpRoundTrip($session['token'], $oid);
        self::assertNotNull($result->stepUpToken);

        // Přihlášení jiným účtem u poskytovatele proof nevydá.
        $this->expectOidcError(
            OidcException::IDENTITY_MISMATCH,
            fn () => $this->stepUpRoundTrip($session['token'], self::guid()),
        );
    }

    public function testStepUpCannotCompleteAfterEntraStopsReplacingMfa(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(
            FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_STEP_UP, $binding, '/settings',
            $this->userId, $session['token'], MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
        $data = $this->config->all();
        $data['auth']['oidc']['providers']['entra']['skip_mfa'] = false;
        $provider = (new OidcProviderRegistry(new Config($data)))->get('entra');
        self::assertNotNull($provider);
        $this->expectOidcError(OidcException::PROVIDER_DISABLED, fn () => $this->service->complete(
            $provider, ['state' => self::queryParam($url, 'state'), 'code' => 'synthetic-code'],
            $binding, $session['token'], self::IP, self::UA,
        ));
    }

    public function testStepUpFromOtherSessionIsRejected(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));
        $other = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));

        $this->expectOidcError(
            OidcException::FLOW_INVALID,
            fn () => $this->stepUpRoundTrip($session['token'], $oid, $other['token']),
        );
    }

    public function testLinkCannotCompleteAfterManualSessionLock(): void
    {
        [$session, $binding, $state] = $this->startLink();
        $this->lockForCallback($session['token']);
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->complete($state, $binding, $session['token']));
        self::assertSame(0, $this->identities->countForUser($this->userId));
    }

    public function testLinkCannotCompleteAfterIdleDeadline(): void
    {
        [$session, $binding, $state] = $this->startLink();
        $this->db->pdo()->prepare('UPDATE users SET session_lock_after_minutes = 1 WHERE id = ?')->execute([$this->userId]);
        $this->db->pdo()->prepare('UPDATE sessions SET last_user_activity_at = UTC_TIMESTAMP(6) - INTERVAL 2 MINUTE WHERE id = ?')
            ->execute([$session['token']]);
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->complete($state, $binding, $session['token']));
        self::assertSame(0, $this->identities->countForUser($this->userId));
    }

    public function testLinkCannotCompleteAfterLocalUserDeactivation(): void
    {
        [$session, $binding, $state] = $this->startLink();
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->complete($state, $binding, $session['token']));
        self::assertSame(0, $this->identities->countForUser($this->userId));
    }

    public function testActiveSessionCanCompleteLink(): void
    {
        [$session, $binding, $state] = $this->startLink();
        self::assertSame(OidcFlowStore::PURPOSE_LINK, $this->complete($state, $binding, $session['token'])->purpose);
        self::assertSame(1, $this->identities->countForUser($this->userId));
    }

    public function testIdentityLinkedToOneAccountCannotBeLinkedToAnother(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        self::assertSame(1, $this->identities->countForUser($this->userId));

        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, id, 1 FROM roles WHERE system_key = ? LIMIT 1'
        )->execute(['oidc-other-' . bin2hex(random_bytes(6)) . '@example.test', 'x', 'OIDC other', 'accountant']);
        $other = (int) $pdo->lastInsertId();
        try {
            $session = $this->sessions->create($other, self::IP, self::UA, SessionAuthContext::basic('password'));
            $binding = OidcFlowStore::newBinding();
            $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LINK, $binding, '/profile', $other, $session['token']);
            $this->idp->nextClaims = $this->idp->claims($oid, (string) $this->idp->lastNonce);

            $this->expectOidcError(
                OidcException::ALREADY_LINKED,
                fn () => $this->complete(self::queryParam($url, 'state'), $binding, $session['token']),
            );
            self::assertSame(0, $this->identities->countForUser($other));
            self::assertSame(1, $this->identities->countForUser($this->userId));
        } finally {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$other]);
            $pdo->prepare('DELETE FROM oidc_login_flows WHERE user_id = ?')->execute([$other]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$other]);
        }
    }

    public function testStepUpCannotIssueProofForLockedSession(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));
        $this->lockForCallback($session['token']);
        $this->expectOidcError(OidcException::FLOW_INVALID, fn () => $this->stepUpRoundTrip($session['token'], $oid));
    }

    public function testSilentRenewalSendsPromptNoneAndSignsInLinkedUser(): void
    {
        $oid = self::guid();
        $this->loginRoundTrip($oid, invite: $this->invites->issue($this->userId, 'entra', null));

        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', silent: true);
        self::assertSame('none', self::queryParam($url, 'prompt'));
        $this->idp->nextClaims = $this->idp->claims($oid, (string) $this->idp->lastNonce, ['preferred_username' => $this->email]);
        $result = $this->complete(self::queryParam($url, 'state'), $binding);
        self::assertSame(OidcFlowStore::PURPOSE_LOGIN, $result->purpose);
        self::assertSame('/invoices', $result->returnTo);
        self::assertSame($this->userId, (int) $result->user['id']);

        // Zablokovaný uživatel tichým obnovením neprojde.
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', silent: true);
        $this->idp->nextClaims = $this->idp->claims($oid, (string) $this->idp->lastNonce);
        try {
            $this->complete(self::queryParam($url, 'state'), $binding);
            self::fail('Deaktivovaný účet se tiše přihlásil.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::USER_INACTIVE, $e->errorCode);
            self::assertFalse($e->interactiveRetry);
        }
    }

    public function testSilentRenewalIsOnlyForStrongProviderLoginWithoutInvite(): void
    {
        $plain = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, OidcFlowStore::newBinding(), null);
        self::assertSame('select_account', self::queryParam($plain, 'prompt'));

        $google = $this->service->start(FakeOidcIdp::googleProvider(), OidcFlowStore::PURPOSE_LOGIN, OidcFlowStore::newBinding(), null, silent: true);
        self::assertSame('select_account', self::queryParam($google, 'prompt'));

        $invite = $this->invites->issue($this->userId, 'entra', null);
        $invited = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, OidcFlowStore::newBinding(), null, inviteToken: $invite, silent: true);
        self::assertSame('select_account', self::queryParam($invited, 'prompt'));
    }

    public function testSilentRenewalInteractionErrorsContinueInteractively(): void
    {
        foreach (['login_required', 'interaction_required', 'consent_required', 'account_selection_required'] as $error) {
            $binding = OidcFlowStore::newBinding();
            $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', silent: true);
            try {
                $this->service->complete(FakeOidcIdp::provider(), ['state' => self::queryParam($url, 'state'), 'error' => $error], $binding, null, self::IP, self::UA);
                self::fail('Návrat s chybou ' . $error . ' prošel.');
            } catch (OidcException $e) {
                self::assertTrue($e->interactiveRetry, $error);
                self::assertSame('/invoices', $e->returnTo);
            }
        }

        // Jiný účet přihlášený u poskytovatele: uživatel si vybere účet sám.
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', silent: true);
        $this->idp->nextClaims = $this->idp->claims(self::guid(), (string) $this->idp->lastNonce);
        try {
            $this->complete(self::queryParam($url, 'state'), $binding);
            self::fail('Nepropojená identita prošla.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::NOT_LINKED, $e->errorCode);
            self::assertTrue($e->interactiveRetry);
        }

        // Odmítnutí uživatelem ani chyba poskytovatele se interaktivně neopakují.
        foreach (['access_denied', 'server_error'] as $error) {
            $binding = OidcFlowStore::newBinding();
            $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LOGIN, $binding, '/invoices', silent: true);
            try {
                $this->service->complete(FakeOidcIdp::provider(), ['state' => self::queryParam($url, 'state'), 'error' => $error], $binding, null, self::IP, self::UA);
                self::fail('Návrat s chybou ' . $error . ' prošel.');
            } catch (OidcException $e) {
                self::assertFalse($e->interactiveRetry, $error);
            }
        }
    }

    public function testSilentRenewalLoginRequiredRedirectsToInteractiveLoginWithSameReturnTo(): void
    {
        $first = $this->actionLogin(['silent' => '1', 'return_to' => '/invoices?status=unpaid']);
        self::assertSame('none', self::queryParam($first['location'], 'prompt'));

        $callback = $this->actionCallback($first, ['error' => 'login_required']);
        self::assertSame(302, $callback->getStatusCode());
        self::assertSame('/api/auth/oidc/entra/login?return_to=%2Finvoices%3Fstatus%3Dunpaid', $callback->getHeaderLine('Location'));

        // Druhý pokus je běžné přihlášení: výběr účtu a při stejné chybě hláška, ne další kolo.
        parse_str((string) parse_url($callback->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $second = $this->actionLogin($query, $first['binding']);
        self::assertSame('select_account', self::queryParam($second['location'], 'prompt'));
        $again = $this->actionCallback($second, ['error' => 'login_required']);
        self::assertSame('/login?sso_error=' . OidcException::CANCELLED, $again->getHeaderLine('Location'));
    }

    public function testSilentRenewalDropsReturnToOutsideApp(): void
    {
        foreach (['https://evil.example/', '//evil.example/x', '/api/auth/logout'] as $returnTo) {
            $start = $this->actionLogin(['silent' => '1', 'return_to' => $returnTo]);
            self::assertSame('none', self::queryParam($start['location'], 'prompt'));
            $callback = $this->actionCallback($start, ['error' => 'login_required']);
            self::assertSame('/api/auth/oidc/entra/login', $callback->getHeaderLine('Location'), $returnTo);
        }
        self::assertNull(OidcAction::safeReturnTo('https://evil.example/'));
        self::assertSame('/api/auth/oidc/entra/login', OidcAction::interactiveLoginPath('entra', '//evil.example'));
    }

    /**
     * @param array<string,mixed> $query
     * @return array{location:string,binding:string}
     */
    private function actionLogin(array $query, ?string $binding = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/oidc/entra/login')
            ->withQueryParams($query);
        if ($binding !== null) {
            $request = $request->withCookieParams([$this->bindingCookieName() => $binding]);
        }
        $response = $this->oidcAction()->login($request, (new ResponseFactory())->createResponse(), ['provider' => 'entra']);
        self::assertSame(302, $response->getStatusCode());
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/^' . preg_quote($this->bindingCookieName(), '/') . '=([a-f0-9]{64});/', $cookie);
        preg_match('/=([a-f0-9]{64});/', $cookie, $match);
        return ['location' => $response->getHeaderLine('Location'), 'binding' => $match[1]];
    }

    /**
     * @param array{location:string,binding:string} $start
     * @param array<string,string> $query
     */
    private function actionCallback(array $start, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/oidc/entra/callback', ['REMOTE_ADDR' => self::IP])
            ->withQueryParams(['state' => self::queryParam($start['location'], 'state')] + $query)
            ->withCookieParams([$this->bindingCookieName() => $start['binding']]);
        return $this->oidcAction()->callback($request, (new ResponseFactory())->createResponse(), ['provider' => 'entra']);
    }

    private function bindingCookieName(): string
    {
        return OidcFlowStore::bindingCookieName((string) $this->config->get('session.cookie_name', '__Host-myinvoice_session'));
    }

    /** Akce s reálnou službou; přihlášení a ověření citlivé operace se na chybové cestě nevolají. */
    private function oidcAction(): OidcAction
    {
        $clock = new class implements \Psr\Clock\ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
        return new OidcAction(
            $this->service,
            $this->identities,
            (new \ReflectionClass(LoginSessionIssuer::class))->newInstanceWithoutConstructor(),
            $this->sessions,
            (new \ReflectionClass(SensitiveOperationReauth::class))->newInstanceWithoutConstructor(),
            $this->policy,
            new ActivityLogger($this->db),
            new IpMatcher($this->config),
            $this->config,
            $clock,
            new ResponseFactory(),
            new OidcFlowStore($this->db),
        );
    }

    private function startLink(): array
    {
        $session = $this->sessions->create($this->userId, self::IP, self::UA, SessionAuthContext::basic('password'));
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(FakeOidcIdp::provider(), OidcFlowStore::PURPOSE_LINK, $binding, '/profile', $this->userId, $session['token']);
        $this->idp->nextClaims = $this->idp->claims(self::guid(), (string) $this->idp->lastNonce);
        return [$session, $binding, self::queryParam($url, 'state')];
    }

    private function lockForCallback(string $token): void
    {
        $credentials = new PasskeyCredentialRepository($this->db);
        $record = \Webauthn\CredentialRecord::create(
            random_bytes(32), 'public-key', ['internal'], 'none', \Webauthn\TrustPath\EmptyTrustPath::create(),
            \Symfony\Component\Uid\Uuid::fromBinary(str_repeat("\0", 16)), random_bytes(77),
            $credentials->userHandle($this->userId), 0, null, true, false, true,
        );
        $credentials->save($this->userId, $record, 'Synthetic lock key');
        $clock = new DatabaseSecurityClock();
        $locks = new \MyInvoice\Service\Auth\SessionLockService($this->db,
            new \MyInvoice\Service\Auth\SessionLockPolicy($this->config), $clock,
            new \MyInvoice\Service\Auth\WebAuthnCeremonyStore($this->db, $clock));
        self::assertTrue($locks->lockManually($token)->locked);
    }

    /** @param array<string,mixed> $claims */
    private function loginRoundTrip(string $oid, ?string $invite = null, array $claims = []): \MyInvoice\Service\Auth\Oidc\OidcResult
    {
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(
            FakeOidcIdp::provider(),
            OidcFlowStore::PURPOSE_LOGIN,
            $binding,
            '/invoices',
            inviteToken: $invite,
        );
        $this->idp->nextClaims = $this->idp->claims($oid, (string) $this->idp->lastNonce, $claims + ['preferred_username' => $this->email]);
        return $this->complete(self::queryParam($url, 'state'), $binding);
    }

    /** @param array<string,mixed> $claims */
    private function stepUpRoundTrip(string $sessionToken, string $oid, ?string $callbackSession = null, ?array $claims = null): \MyInvoice\Service\Auth\Oidc\OidcResult
    {
        $binding = OidcFlowStore::newBinding();
        $url = $this->service->start(
            FakeOidcIdp::provider(),
            OidcFlowStore::PURPOSE_STEP_UP,
            $binding,
            '/settings',
            $this->userId,
            $sessionToken,
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
        self::assertSame('login', self::queryParam($url, 'prompt'));
        $claims ??= ['auth_time' => time()];
        $this->idp->nextClaims = $this->idp->claims($oid, (string) $this->idp->lastNonce, $claims);
        return $this->complete(self::queryParam($url, 'state'), $binding, $callbackSession ?? $sessionToken);
    }

    private function complete(string $state, string $binding, ?string $sessionToken = null): \MyInvoice\Service\Auth\Oidc\OidcResult
    {
        return $this->service->complete(
            FakeOidcIdp::provider(),
            ['state' => $state, 'code' => 'code-' . bin2hex(random_bytes(8))],
            $binding,
            $sessionToken,
            self::IP,
            self::UA,
        );
    }

    private function expectOidcError(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (OidcException $e) {
            self::assertSame($code, $e->errorCode);
            return;
        }
        self::fail('Očekávaná chyba ' . $code . ' nenastala.');
    }

    private function createUser(): void
    {
        $pdo = $this->db->pdo();
        $this->email = 'oidc-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, id, 1 FROM roles WHERE system_key = ? LIMIT 1'
        )->execute([
            $this->email,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            'OIDC test',
            'accountant',
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        if ($this->userId < 1) {
            $this->markTestSkipped('Nepodařilo se založit uživatele.');
        }
    }

    private static function queryParam(string $url, string $name): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return (string) ($query[$name] ?? '');
    }

    private static function guid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }
}
