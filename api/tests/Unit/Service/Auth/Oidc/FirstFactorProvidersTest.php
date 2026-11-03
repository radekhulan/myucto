<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth\Oidc;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\Oidc\AppleClientSecret;
use MyInvoice\Service\Auth\Oidc\IdTokenVerifier;
use MyInvoice\Service\Auth\Oidc\JwksCache;
use MyInvoice\Service\Auth\Oidc\OidcException;
use MyInvoice\Service\Auth\Oidc\OidcProvider;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Tests\Support\FakeOidcIdp;
use PHPUnit\Framework\TestCase;

/**
 * Google, Apple a Seznam: poskytovatelé, kteří nahrazují jen heslo.
 */
final class FirstFactorProvidersTest extends TestCase
{
    private const NONCE = 'nonce-first-factor-0123456789';

    private FakeOidcIdp $idp;
    private IdTokenVerifier $verifier;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->idp = new FakeOidcIdp();
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-ff-' . bin2hex(random_bytes(4));
        $this->verifier = new IdTokenVerifier(new JwksCache($this->idp, $this->cacheDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testGoogleIdentityUsesSubAndAcceptsBothIssuerForms(): void
    {
        foreach (['https://accounts.google.com', 'accounts.google.com'] as $iss) {
            $token = $this->idp->sign($this->idp->googleClaims('1098765', self::NONCE, ['iss' => $iss]));
            $identity = $this->verifier->verify(FakeOidcIdp::googleProvider(), $token, self::NONCE);
            self::assertSame('1098765', $identity->subject);
            self::assertSame('tester@example.test', $identity->email);
        }
    }

    public function testGoogleUnverifiedEmailIsDropped(): void
    {
        $token = $this->idp->sign($this->idp->googleClaims('1098765', self::NONCE, ['email_verified' => false]));
        self::assertNull($this->verifier->verify(FakeOidcIdp::googleProvider(), $token, self::NONCE)->email);
    }

    public function testGoogleHostedDomainRestriction(): void
    {
        $provider = FakeOidcIdp::googleProvider(['firma.example']);
        $ok = $this->idp->sign($this->idp->googleClaims('1', self::NONCE, ['hd' => 'firma.example']));
        self::assertSame('1', $this->verifier->verify($provider, $ok, self::NONCE)->subject);

        $this->expectException(OidcException::class);
        $this->verifier->verify($provider, $this->idp->sign($this->idp->googleClaims('2', self::NONCE)), self::NONCE);
    }

    public function testGoogleTokenForOtherClientIsRejected(): void
    {
        $token = $this->idp->sign($this->idp->googleClaims('1', self::NONCE, ['aud' => '999-other.apps.googleusercontent.com']));
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::googleProvider(), $token, self::NONCE);
    }

    public function testAppleIdentityAndForeignIssuer(): void
    {
        $now = time();
        $claims = [
            'iss' => 'https://appleid.apple.com', 'aud' => 'cz.example.ucto.web', 'exp' => $now + 600,
            'iat' => $now, 'nonce' => self::NONCE, 'sub' => '001234.abcdef0123456789.0123',
            'email' => 'x1y2@privaterelay.appleid.com', 'email_verified' => 'true',
        ];
        $identity = $this->verifier->verify(FakeOidcIdp::appleProvider(), $this->idp->sign($claims), self::NONCE);
        self::assertSame('001234.abcdef0123456789.0123', $identity->subject);
        self::assertSame('x1y2@privaterelay.appleid.com', $identity->email);

        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::appleProvider(), $this->idp->sign(['iss' => 'https://evil.example'] + $claims), self::NONCE);
    }

    public function testSeznamIdentityFromUserinfo(): void
    {
        $provider = new OidcProvider('seznam', 'Seznam', 'https://login.szn.cz', 'https://login.szn.cz/a', 'https://login.szn.cz/t',
            '', 'client', 'secret', 'identity', null, true, protocol: 'oauth2', assurance: OidcProvider::FIRST_FACTOR);
        $identity = $this->verifier->seznamIdentity($provider, [
            'advert_user_id' => 'a1b2c3', 'username' => 'tester', 'domain' => 'seznam.cz',
            'firstname' => 'Test', 'lastname' => 'Tester',
        ]);
        self::assertSame('a1b2c3', $identity->subject);
        self::assertSame('tester@seznam.cz', $identity->email);
        self::assertSame('Test Tester', $identity->name);

        $this->expectException(OidcException::class);
        $this->verifier->seznamIdentity($provider, ['email' => 'bez-id@seznam.cz']);
    }

    public function testAppleClientSecretIsShortLivedEs256(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        $jwt = (new AppleClientSecret('ABCDE12345', 'KEY1234567', 'cz.example.ucto.web', $pem))->generate();
        $claims = (array) JWT::decode($jwt, new Key($public, 'ES256'));
        self::assertSame('ABCDE12345', $claims['iss']);
        self::assertSame('cz.example.ucto.web', $claims['sub']);
        self::assertSame('https://appleid.apple.com', $claims['aud']);
        self::assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
    }

    public function testOnlyEntraIsStrongInRegistry(): void
    {
        $registry = new OidcProviderRegistry(new Config([
            'app' => ['url' => 'https://ucto.example.test'],
            'auth' => ['oidc' => ['enabled' => true, 'providers' => [
                'entra' => ['enabled' => true, 'tenant_id' => FakeOidcIdp::TENANT, 'client_id' => FakeOidcIdp::CLIENT, 'client_secret' => 's'],
                'google' => ['enabled' => true, 'client_id' => FakeOidcIdp::GOOGLE_CLIENT, 'client_secret' => 's'],
                'seznam' => ['enabled' => true, 'client_id' => 'c', 'client_secret' => 's'],
                'apple' => ['enabled' => true, 'team_id' => 'bad'],
            ]]],
        ]));
        $strong = array_column($registry->publicList(), 'strong', 'id');
        self::assertSame(['entra' => true, 'google' => false, 'seznam' => false], $strong);
        self::assertArrayHasKey('apple', $registry->configurationErrors());
        self::assertSame(
            'https://ucto.example.test/api/auth/oidc/google/callback',
            $registry->redirectUri((array) $registry->get('google') !== [] ? $registry->get('google') : throw new \LogicException()),
        );
    }
}
