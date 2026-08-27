<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\PasskeyAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LastMfaFactorException;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeySessionTransitionService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\WebAuthnCeremony;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;

#[AllowMockObjectsWithoutExpectations]
final class PasskeyActionTest extends TestCase
{
    private Connection&MockObject $db;
    private PasskeyCredentialRepository&MockObject $credentials;
    private PasskeyService&MockObject $passkeys;
    private WebAuthnCeremonyStore&MockObject $ceremonies;
    private MfaStepUpService&MockObject $stepUp;
    private PasswordHasher&MockObject $passwords;
    private MfaPolicyService&MockObject $policy;
    private SessionManager&MockObject $sessions;
    private ActivityLogger&MockObject $logger;
    private IpMatcher&MockObject $ipMatcher;
    private LoginSessionIssuer&MockObject $loginIssuer;
    private ClockInterface&MockObject $clock;
    private SessionCookieFactory&MockObject $sessionCookies;
    private BruteForceGuard&MockObject $bruteForce;
    private PasskeySessionTransitionService&MockObject $sessionTransitions;
    private MfaProtectedOperationService&MockObject $protectedOperations;
    private MfaRecoveryCodeService&MockObject $recoveryCodes;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->credentials = $this->createMock(PasskeyCredentialRepository::class);
        $this->passkeys = $this->createMock(PasskeyService::class);
        $this->passkeys->method('isAvailable')->willReturn(true);
        $this->ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $this->stepUp = $this->createMock(MfaStepUpService::class);
        $this->passwords = $this->createMock(PasswordHasher::class);
        $this->policy = $this->createMock(MfaPolicyService::class);
        $this->sessions = $this->createMock(SessionManager::class);
        $this->logger = $this->createMock(ActivityLogger::class);
        $this->ipMatcher = $this->createMock(IpMatcher::class);
        $this->loginIssuer = $this->createMock(LoginSessionIssuer::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->sessionCookies = $this->createMock(SessionCookieFactory::class);
        $this->bruteForce = $this->createMock(BruteForceGuard::class);
        $this->sessionTransitions = $this->createMock(PasskeySessionTransitionService::class);
        $this->protectedOperations = $this->createMock(MfaProtectedOperationService::class);
        // Registrace passkey vydává první sadu záložních kódů; testy tu sledují
        // ceremonii, ne kódy, takže atrapa mlčí a vrací prázdno.
        $this->recoveryCodes = $this->createMock(MfaRecoveryCodeService::class);
    }

    public function testCredentialListContainsOnlyPublicMetadata(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $this->credentials->expects(self::once())
            ->method('findAllForUser')
            ->with(17)
            ->willReturn([$stored]);

        $response = $this->action()->credentials(
            $this->sessionRequest('GET', '/api/auth/webauthn/credentials'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $body['credentials'][0]['id']);
        self::assertSame('Pixel 9', $body['credentials'][0]['label']);
        self::assertSame(['internal'], $body['credentials'][0]['transports']);
        self::assertArrayNotHasKey('credential_id', $body['credentials'][0]);
        self::assertArrayNotHasKey('public_key', $body['credentials'][0]);
        self::assertArrayNotHasKey('aaguid', $body['credentials'][0]);
    }

    public function testBearerCannotUseCredentialManagement(): void
    {
        $this->credentials->expects(self::never())->method('findAllForUser');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/webauthn/credentials')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17]);

        $response = $this->action()->credentials(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->errorCode($response));
    }

    public function testRequiredMfaPreventsRevokingLastAllowedStrongFactor(): void
    {
        $this->protectedOperations->expects(self::once())
            ->method('revokePasskey')
            ->with(17, str_repeat('a', 64), 42, 'proof-token')
            ->willThrowException(new LastMfaFactorException());

        $request = $this->sessionRequest('DELETE', '/api/auth/webauthn/credentials/42')
            ->withParsedBody(['step_up_token' => 'proof-token']);
        $response = $this->action()->revoke(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '42'],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('last_mfa_factor', $this->errorCode($response));
    }

    public function testPhasedOutTotpCreatesFirstPasskeyOnlyRegistrationFlow(): void
    {
        $this->policy->method('isMethodAllowed')->willReturnMap([
            ['passkey', true],
            ['totp', false],
        ]);
        $this->mockFreshUser(['totp_enabled' => 1]);
        $this->stepUp->expects(self::once())
            ->method('consume')
            ->with(
                'transition-proof',
                17,
                str_repeat('a', 64),
                MfaStepUpService::OPERATION_PASSKEY_REGISTER,
            )
            ->willReturn(new MfaStepUpProof(
                17,
                MfaStepUpService::OPERATION_PASSKEY_REGISTER,
                'totp',
                null,
            ));
        $this->passwords->expects(self::never())->method('verify');
        $this->expectRegistrationOptions(
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
        );

        $response = $this->action()->registerOptions(
            $this->sessionRequest('POST', '/api/auth/webauthn/register/options')
                ->withParsedBody(['step_up_token' => 'transition-proof']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCurrentlyAllowedStrongFactorCreatesUnconstrainedRegistrationFlow(): void
    {
        $this->policy->method('isMethodAllowed')->willReturnMap([
            ['passkey', true],
            ['totp', true],
        ]);
        $this->mockFreshUser(['totp_enabled' => 1]);
        $this->stepUp->expects(self::once())
            ->method('consume')
            ->willReturn(new MfaStepUpProof(
                17,
                MfaStepUpService::OPERATION_PASSKEY_REGISTER,
                'totp',
                null,
            ));
        $this->expectRegistrationOptions(null);

        $response = $this->action()->registerOptions(
            $this->sessionRequest('POST', '/api/auth/webauthn/register/options')
                ->withParsedBody(['step_up_token' => 'strong-proof']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPasswordAuthorizedRegistrationIsLimitedToFirstPasskey(): void
    {
        $this->policy->method('isMethodAllowed')->willReturnMap([
            ['passkey', true],
        ]);
        $this->mockFreshUser(['totp_enabled' => 0]);
        $this->credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(0);
        $this->passwords->expects(self::once())
            ->method('verify')
            ->with('Synthetic-test-password-42', 'synthetic-hash')
            ->willReturn(true);
        $this->stepUp->expects(self::never())->method('consume');
        $this->expectRegistrationOptions(
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
        );

        $response = $this->action()->registerOptions(
            $this->sessionRequest('POST', '/api/auth/webauthn/register/options')
                ->withParsedBody(['current_password' => 'Synthetic-test-password-42']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testLoginVerifyIssuesStrongPasskeySessionOnlyAfterCounterUpdate(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $payload = ['rawId' => 'synthetic-base64url', 'response' => []];
        $this->ceremonies->expects(self::once())
            ->method('peekLoginContext')
            ->with('flow-token')
            ->willReturn([
                'purpose' => WebAuthnCeremonyStore::PURPOSE_LOGIN,
                'user_id' => 17,
            ]);
        $this->bruteForce->expects(self::once())
            ->method('isPasskeyLocked')
            ->with(17)
            ->willReturn(false);
        $now = new \DateTimeImmutable('2026-07-24 12:00:00 UTC');
        $context = \MyInvoice\Service\Auth\SessionAuthContext::strong('passkey', $now, 42);
        $session = [
            'token' => str_repeat('c', 64),
            'csrf_token' => str_repeat('d', 64),
            'expires_at' => 1_800_000_000,
            'issued_at' => $now,
        ];
        $user = [
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'totp_enabled' => 0,
        ];
        $this->sessionTransitions->expects(self::once())
            ->method('completeLogin')
            ->with('flow-token', $payload, '127.0.0.1', 'PHPUnit', 17)
            ->willReturn([
                'session' => $session,
                'credential' => $stored,
                'user' => $user,
                'auth_context' => $context,
            ]);
        $this->bruteForce->expects(self::once())
            ->method('recordPasskeySuccess')
            ->with(17);
        $this->ipMatcher->expects(self::exactly(2))
            ->method('clientIpFromRequest')
            ->willReturn('127.0.0.1');
        $this->loginIssuer->expects(self::once())
            ->method('issuePrepared')
            ->willReturnCallback(static function (
                \Psr\Http\Message\ResponseInterface $response,
                array $user,
                string $ip,
                string $userAgent,
                \MyInvoice\Service\Auth\SessionAuthContext $context,
                array $issuedSession,
            ): \Psr\Http\Message\ResponseInterface {
                self::assertSame(17, $user['id']);
                self::assertSame('127.0.0.1', $ip);
                self::assertSame('PHPUnit', $userAgent);
                self::assertSame('passkey', $context->authMethod);
                self::assertSame('strong', $context->assuranceLevel);
                self::assertSame(42, $context->authCredentialId);
                self::assertSame(str_repeat('c', 64), $issuedSession['token']);
                return $response->withStatus(204);
            });

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
            ]);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDiscoverableLoginResolvesUserFromCredentialAndIssuesStrongSession(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $payload = ['rawId' => 'synthetic-base64url', 'response' => ['userHandle' => 'synthetic']];
        $this->ceremonies->expects(self::once())
            ->method('peekLoginContext')
            ->with('flow-token')
            ->willReturn([
                'purpose' => WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
                'user_id' => null,
            ]);
        $this->sessionTransitions->expects(self::once())
            ->method('discoverableLoginUserId')
            ->with($payload)
            ->willReturn(17);
        $this->bruteForce->expects(self::once())
            ->method('isPasskeyLocked')
            ->with(17)
            ->willReturn(false);
        $now = new \DateTimeImmutable('2026-07-24 12:00:00 UTC');
        $context = \MyInvoice\Service\Auth\SessionAuthContext::strong('passkey', $now, 42);
        $session = [
            'token' => str_repeat('c', 64),
            'csrf_token' => str_repeat('d', 64),
            'expires_at' => 1_800_000_000,
            'issued_at' => $now,
        ];
        $user = [
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'totp_enabled' => 0,
        ];
        $this->sessionTransitions->expects(self::once())
            ->method('completeDiscoverableLogin')
            ->with('flow-token', $payload, '127.0.0.1', 'PHPUnit', 17)
            ->willReturn([
                'session' => $session,
                'credential' => $stored,
                'user' => $user,
                'auth_context' => $context,
            ]);
        $this->sessionTransitions->expects(self::never())->method('completeLogin');
        $this->bruteForce->expects(self::once())->method('recordPasskeySuccess')->with(17);
        $this->loginIssuer->expects(self::once())
            ->method('issuePrepared')
            ->with(
                self::anything(),
                $user,
                '127.0.0.1',
                'PHPUnit',
                $context,
                $session,
            )
            ->willReturnCallback(static fn (
                \Psr\Http\Message\ResponseInterface $response,
            ): \Psr\Http\Message\ResponseInterface => $response->withStatus(204));
        $this->ipMatcher->expects(self::exactly(2))
            ->method('clientIpFromRequest')
            ->willReturn('127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
            ]);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUnknownDiscoverableCredentialReturnsGenericErrorWithoutAccountBucket(): void
    {
        $payload = ['rawId' => 'unknown-base64url', 'response' => []];
        $this->ceremonies->expects(self::once())
            ->method('peekLoginContext')
            ->with('flow-token')
            ->willReturn([
                'purpose' => WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
                'user_id' => null,
            ]);
        $this->sessionTransitions->expects(self::once())
            ->method('discoverableLoginUserId')
            ->with($payload)
            ->willReturn(null);
        $this->bruteForce->expects(self::never())->method('isPasskeyLocked');
        $this->sessionTransitions->expects(self::once())
            ->method('completeDiscoverableLogin')
            ->with('flow-token', $payload, '127.0.0.1', 'PHPUnit', null)
            ->willThrowException(new PasskeyVerificationException('Ověření passkey selhalo.'));
        $this->bruteForce->expects(self::never())->method('recordPasskeyFailure');
        $this->loginIssuer->expects(self::never())->method('issuePrepared');
        $this->ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
            ]);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('passkey_verification_failed', $this->errorCode($response));
    }

    public function testLoginVerifyConsumesMalformedAttemptAndRecordsUserFailure(): void
    {
        $this->ceremonies->expects(self::once())
            ->method('peekLoginContext')
            ->with('flow-token')
            ->willReturn([
                'purpose' => WebAuthnCeremonyStore::PURPOSE_LOGIN,
                'user_id' => 17,
            ]);
        $this->bruteForce->expects(self::once())
            ->method('isPasskeyLocked')
            ->with(17)
            ->willReturn(false);
        $this->sessionTransitions->expects(self::once())
            ->method('completeLogin')
            ->with('flow-token', [], '127.0.0.1', 'PHPUnit', 17)
            ->willThrowException(new \MyInvoice\Service\Auth\PasskeyVerificationException(
                'Ověření passkey selhalo.',
            ));
        $this->bruteForce->expects(self::once())
            ->method('recordPasskeyFailure')
            ->with(17);
        $this->loginIssuer->expects(self::never())->method('issuePrepared');
        $this->ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody(['flow_token' => 'flow-token']);
        $response = $this->action()->loginVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('passkey_verification_failed', $this->errorCode($response));
    }

    public function testLoginVerifyReportsUnavailableConfigurationWithoutConsumingFlow(): void
    {
        $this->passkeys = $this->createMock(PasskeyService::class);
        $this->passkeys->expects(self::once())->method('isAvailable')->willReturn(false);
        $this->ceremonies->expects(self::never())->method('consumeLogin');

        $response = $this->action()->loginVerify(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/auth/webauthn/login/verify')
                ->withParsedBody(['flow_token' => 'flow-token']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('passkeys_unavailable', $this->errorCode($response));
    }

    public function testSetupRegistrationRevokesSetupSessionsAndReturnsRotatedCookie(): void
    {
        $stored = $this->storedCredential(42, 17, 'Pixel 9');
        $payload = ['rawId' => 'synthetic-base64url', 'response' => []];
        $ceremony = new WebAuthnCeremony(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            17,
            WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            random_bytes(32),
            ['challenge' => 'synthetic'],
        );
        $this->policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $this->ceremonies->expects(self::once())
            ->method('consumeRegistration')
            ->with('flow-token', 17, str_repeat('a', 64))
            ->willReturn($ceremony);
        $this->passkeys->expects(self::once())
            ->method('verifyRegistration')
            ->with($payload, $ceremony->options)
            ->willReturn($stored->record);
        $this->credentials->expects(self::once())
            ->method('save')
            ->with(17, $stored->record, 'Pixel 9', true)
            ->willReturn(42);
        $this->credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn($stored);
        $this->mockFreshUser();
        $now = new \DateTimeImmutable('2026-07-24 12:00:00 UTC');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $this->sessions->expects(self::once())
            ->method('completeSetup')
            ->willReturnCallback(static function (
                int $userId,
                string $token,
                string $ip,
                string $userAgent,
                \MyInvoice\Service\Auth\SessionAuthContext $context,
            ): array {
                self::assertSame(17, $userId);
                self::assertSame(str_repeat('a', 64), $token);
                self::assertSame('127.0.0.1', $ip);
                self::assertSame('PHPUnit', $userAgent);
                self::assertSame('strong', $context->assuranceLevel);
                self::assertSame('passkey', $context->authMethod);
                self::assertSame(42, $context->authCredentialId);
                return [
                    'token' => str_repeat('c', 64),
                    'csrf_token' => str_repeat('d', 64),
                    'expires_at' => 1_800_000_000,
                ];
            });
        $this->sessionCookies->expects(self::once())
            ->method('create')
            ->with(str_repeat('c', 64), 1_800_000_000)
            ->willReturn('__Host-myinvoice_session=rotated');

        $request = $this->sessionRequest('POST', '/api/auth/webauthn/register/verify')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'setup'])
            ->withParsedBody([
                'flow_token' => 'flow-token',
                'credential' => $payload,
                'label' => 'Pixel 9',
            ]);
        $response = $this->action()->registerVerify(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(str_repeat('d', 64), $body['csrf_token']);
        self::assertSame('active', $body['session_state']);
        self::assertFalse($body['must_setup_mfa']);
        self::assertSame('__Host-myinvoice_session=rotated', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function mockFreshUser(array $overrides = []): void
    {
        $row = array_replace([
            'id' => 17,
            'email' => 'synthetic@example.invalid',
            'name' => 'Synthetic User',
            'password_hash' => 'synthetic-hash',
            'totp_enabled' => 0,
        ], $overrides);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn($row);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $this->db->expects(self::once())->method('pdo')->willReturn($pdo);
    }

    private function expectRegistrationOptions(?string $constraint): void
    {
        $this->credentials->expects(self::once())
            ->method('findAllForUser')
            ->with(17)
            ->willReturn([]);
        $this->credentials->expects(self::once())
            ->method('userHandle')
            ->with(17)
            ->willReturn(str_repeat('h', 32));
        $this->passkeys->expects(self::once())
            ->method('registrationOptions')
            ->with(
                'synthetic@example.invalid',
                'Synthetic User',
                str_repeat('h', 32),
                [],
                self::callback(static fn (string $challenge): bool => strlen($challenge) === 32),
            )
            ->willReturn(['challenge' => 'synthetic-options']);
        $this->ceremonies->expects(self::once())
            ->method('create')
            ->with(
                WebAuthnCeremonyStore::PURPOSE_REGISTER,
                17,
                str_repeat('a', 64),
                $constraint,
                self::callback(static fn (string $challenge): bool => strlen($challenge) === 32),
                ['challenge' => 'synthetic-options'],
                '127.0.0.1',
                '',
            )
            ->willReturn('registration-flow');
        $this->ipMatcher->expects(self::once())
            ->method('clientIpFromRequest')
            ->willReturn('127.0.0.1');
    }

    private function action(): PasskeyAction
    {
        return new PasskeyAction(
            $this->db,
            $this->credentials,
            $this->passkeys,
            $this->ceremonies,
            $this->passwords,
            $this->policy,
            $this->sessions,
            $this->logger,
            $this->ipMatcher,
            $this->loginIssuer,
            $this->clock,
            $this->stepUp,
            $this->sessionCookies,
            $this->bruteForce,
            $this->sessionTransitions,
            $this->protectedOperations,
            $this->recoveryCodes,
        );
    }

    private function sessionRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['assurance_level' => 'strong']);
    }

    private function storedCredential(int $id, int $userId, string $label): StoredPasskeyCredential
    {
        $record = CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            random_bytes(32),
            0,
            null,
            true,
            false,
            true,
        );

        return new StoredPasskeyCredential(
            $id,
            $userId,
            $label,
            $record,
            '2026-07-24 12:00:00.000000',
            null,
            null,
        );
    }

    private function errorCode(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }
}
