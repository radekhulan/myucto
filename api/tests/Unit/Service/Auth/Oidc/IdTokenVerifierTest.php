<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth\Oidc;

use MyInvoice\Service\Auth\Oidc\IdTokenVerifier;
use MyInvoice\Service\Auth\Oidc\JwksCache;
use MyInvoice\Service\Auth\Oidc\OidcException;
use MyInvoice\Tests\Support\FakeOidcIdp;
use PHPUnit\Framework\TestCase;

/**
 * Ověření id_tokenu z Entra ID: každá podmínka z OIDC Core 3.1.3.7, kterou
 * verifier kontroluje, má vlastní případ, u kterého token neprojde.
 */
final class IdTokenVerifierTest extends TestCase
{
    private const OID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const NONCE = 'nonce-0123456789abcdef';

    private FakeOidcIdp $idp;
    private IdTokenVerifier $verifier;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->idp = new FakeOidcIdp();
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-test-' . bin2hex(random_bytes(4));
        $this->verifier = new IdTokenVerifier(new JwksCache($this->idp, $this->cacheDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testValidTokenYieldsIdentityKeyedByOidAndTenant(): void
    {
        $token = $this->idp->sign($this->idp->claims(self::OID, self::NONCE));
        $identity = $this->verifier->verify(FakeOidcIdp::provider(), $token, self::NONCE);

        self::assertSame('entra', $identity->provider);
        self::assertSame(self::OID, $identity->subject);
        self::assertSame(FakeOidcIdp::TENANT, $identity->tenantId);
        self::assertSame('tester@example.test', $identity->email);
        self::assertFalse($identity->isGuest);
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function invalidClaims(): iterable
    {
        yield 'cizí vydavatel' => [['iss' => 'https://login.microsoftonline.com/00000000-0000-4000-8000-000000000000/v2.0']];
        yield 'cizí aplikace' => [['aud' => '00000000-0000-4000-8000-000000000000']];
        yield 'více příjemců bez azp' => [['aud' => [FakeOidcIdp::CLIENT, 'jina-aplikace']]];
        yield 'prošlý' => [['exp' => time() - 3600]];
        yield 'ještě neplatný' => [['nbf' => time() + 3600]];
        yield 'starý iat' => [['iat' => time() - 7200, 'exp' => time() + 3600]];
        yield 'jiný nonce' => [['nonce' => 'nonce-jineho-prihlaseni']];
        yield 'cizí tenant' => [['tid' => '00000000-0000-4000-8000-000000000000']];
        yield 'verze 1.0' => [['ver' => '1.0']];
        yield 'oid není GUID' => [['oid' => 'not-a-guid']];
    }

    /** @param array<string,mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClaims')]
    public function testRejectsInvalidClaims(array $override): void
    {
        $token = $this->idp->sign($this->idp->claims(self::OID, self::NONCE, $override));
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(), $token, self::NONCE);
    }

    public function testRejectsTokenWithoutNonceClaim(): void
    {
        $claims = $this->idp->claims(self::OID, self::NONCE);
        unset($claims['nonce']);
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(), $this->idp->sign($claims), self::NONCE);
    }

    public function testRejectsHmacTokenSignedWithPublicMaterial(): void
    {
        // Klasický útok: alg=HS256 s veřejným klíčem jako tajemstvím.
        $claims = $this->idp->claims(self::OID, self::NONCE);
        $token = \Firebase\JWT\JWT::encode($claims, str_repeat('k', 64), 'HS256', FakeOidcIdp::KID);
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(), $token, self::NONCE);
    }

    public function testRejectsUnsignedToken(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none","kid":"' . FakeOidcIdp::KID . '"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode($this->idp->claims(self::OID, self::NONCE))), '+/', '-_'), '=');
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(), $header . '.' . $payload . '.', self::NONCE);
    }

    public function testRejectsTokenSignedByForeignKey(): void
    {
        $foreign = new FakeOidcIdp();
        $token = $foreign->sign($this->idp->claims(self::OID, self::NONCE));
        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(), $token, self::NONCE);
    }

    public function testUnknownKidRefetchesKeysOnlyOncePerCooldown(): void
    {
        $this->verifier->verify(FakeOidcIdp::provider(), $this->idp->sign($this->idp->claims(self::OID, self::NONCE)), self::NONCE);
        self::assertSame(1, $this->idp->jwksFetches);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->verifier->verify(
                    FakeOidcIdp::provider(),
                    $this->idp->sign($this->idp->claims(self::OID, self::NONCE), 'RS256', 'unknown-kid'),
                    self::NONCE,
                );
                self::fail('Token s neznámým klíčem prošel.');
            } catch (OidcException) {
            }
        }
        // Čerstvá cache se kvůli neznámému kid nestahuje znovu (cooldown 60 s).
        self::assertSame(1, $this->idp->jwksFetches);
    }

    public function testGuestIsMarkedAndCanBeRejected(): void
    {
        $claims = $this->idp->claims(self::OID, self::NONCE, [
            'idp' => 'https://sts.windows.net/00000000-0000-4000-8000-000000000000/',
        ]);
        $identity = $this->verifier->verify(FakeOidcIdp::provider(), $this->idp->sign($claims), self::NONCE);
        self::assertTrue($identity->isGuest);

        $this->expectException(OidcException::class);
        $this->verifier->verify(FakeOidcIdp::provider(allowGuests: false), $this->idp->sign($claims), self::NONCE);
    }
}
