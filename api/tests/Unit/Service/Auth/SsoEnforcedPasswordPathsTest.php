<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\ReauthException;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\FakeOidcIdp;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Při vynuceném přihlášení přes Microsoft Entra ID se heslo nepoužívá nikde:
 * citlivé operace, změna ani obnova hesla ho nepřijmou a step-up proof musí
 * pocházet z ověření u poskytovatele.
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoEnforcedPasswordPathsTest extends TestCase
{
    private function policy(bool $enforced, bool $skipMfa = true): LoginPolicyService
    {
        $config = new Config([
            'app' => ['url' => 'https://ucto.example.test'],
            'auth' => ['oidc' => [
                'enabled' => true,
                'required_provider' => $enforced ? 'entra' : '',
                'providers' => ['entra' => ['enabled' => true, 'tenant_id' => FakeOidcIdp::TENANT,
                    'client_id' => FakeOidcIdp::CLIENT, 'client_secret' => 'test-secret', 'skip_mfa' => $skipMfa]],
            ]],
        ]);
        return new LoginPolicyService($config, new OidcProviderRegistry($config));
    }

    public function testSensitiveOperationRejectsPasswordWithoutVerifyingIt(): void
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('verify');
        $reauth = $this->reauth($hasher, $this->policy(true));

        try {
            $reauth->verify($this->sessionRequest(), 7, ['password' => 'synthetic-password'], 'test', MfaStepUpService::OPERATION_EPO_CERTIFICATE, 'test.failed');
            self::fail('Heslo nesmí při vynucení projít.');
        } catch (ReauthException $e) {
            self::assertSame('sso_required', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testSensitiveOperationStillAcceptsPasswordWithoutEnforcement(): void
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->willReturn(true);
        $this->reauth($hasher, $this->policy(false))
            ->verify($this->sessionRequest(), 7, ['password' => 'synthetic-password'], 'test', MfaStepUpService::OPERATION_EPO_CERTIFICATE, 'test.failed');
        $this->addToAssertionCount(1);
    }

    public function testStrongEntraConfirmsOperationsOnlyThroughItself(): void
    {
        $stepUp = $this->stepUp($this->policy(true));

        self::assertSame(MfaStepUpService::OPERATION_EPO_CERTIFICATE, $stepUp->assertAllowed(7, MfaStepUpService::OPERATION_EPO_CERTIFICATE, 'oidc'));
        foreach (['passkey', 'totp', 'recovery'] as $method) {
            try {
                $stepUp->assertAllowed(7, MfaStepUpService::OPERATION_PASSKEY_REGISTER, $method);
                self::fail($method . ' nesmí při vynucené silné Entra potvrdit operaci.');
            } catch (StepUpOperationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFirstFactorEntraKeepsLocalFactorsForOperations(): void
    {
        $stepUp = $this->stepUp($this->policy(true, skipMfa: false));

        self::assertSame(MfaStepUpService::OPERATION_EPO_CERTIFICATE, $stepUp->assertAllowed(7, MfaStepUpService::OPERATION_EPO_CERTIFICATE, 'passkey'));
        self::assertSame(MfaStepUpService::OPERATION_EPO_CERTIFICATE, $stepUp->assertAllowed(7, MfaStepUpService::OPERATION_EPO_CERTIFICATE, 'totp'));
    }

    public function testPasswordChangeAndResetAreDisabled(): void
    {
        $policy = $this->policy(true);
        $change = new ChangePasswordAction(
            $this->createStub(Connection::class), $this->createStub(PasswordHasher::class),
            $this->createStub(SessionManager::class), $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class), $this->createStub(BruteForceGuard::class),
            $this->createStub(ApiTokenService::class), $policy,
        );
        $reset = new ResetPasswordAction(
            $this->createStub(Connection::class), $this->createStub(PasswordHasher::class),
            $this->createStub(SessionManager::class), $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class), $this->createStub(LoginSessionIssuer::class),
            new MfaPolicyService(new Config([])), $policy,
        );
        $body = ['current_password' => 'a', 'new_password' => 'b', 'new_password_confirm' => 'b',
            'token' => 'synthetic', 'password' => 'b', 'password_confirm' => 'b'];

        foreach ([$change, $reset] as $action) {
            $response = $action($this->sessionRequest()->withParsedBody($body), (new ResponseFactory())->createResponse());
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('password_login_disabled', (string) $response->getBody());
        }
    }

    private function reauth(PasswordHasher $hasher, LoginPolicyService $policy): SensitiveOperationReauth
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['email' => 'user@example.invalid', 'password_hash' => 'hash', 'totp_secret' => null, 'totp_enabled' => 0]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $bruteForce = $this->createStub(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        return new SensitiveOperationReauth(
            $db, $this->createStub(TotpService::class), $this->createStub(SecretEncryption::class),
            $this->createStub(ActivityLogger::class), new IpMatcher(), $hasher, $bruteForce,
            $this->createStub(MfaStepUpService::class), $policy,
        );
    }

    private function stepUp(LoginPolicyService $policy): MfaStepUpService
    {
        return new MfaStepUpService(
            $this->createStub(MfaStepUpProofStore::class),
            new MfaPolicyService(new Config(['auth' => ['allowed_mfa_methods' => ['passkey', 'totp']]])),
            $this->createStub(PasskeyCredentialRepository::class),
            $policy,
        );
    }

    private function sessionRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/test')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 7]);
    }
}
