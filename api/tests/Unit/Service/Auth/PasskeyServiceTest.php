<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\WebAuthnConfig;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyServiceTest extends TestCase
{
    use OpensslConfigTrait;

    private PasskeyService $service;

    protected function setUp(): void
    {
        $config = new WebAuthnConfig(new Config(['app' => ['url' => 'https://invoice.example.cz']]));
        $this->service = new PasskeyService($config);
    }

    public function testRegistrationOptionsEnforceDiscoverableCredentialAndUserVerification(): void
    {
        $handle = random_bytes(32);
        $existing = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            $handle,
            [$existing],
            $challenge,
        );

        self::assertSame('MyInvoice.cz', $options['rp']['name']);
        self::assertSame('invoice.example.cz', $options['rp']['id']);
        self::assertSame('user@example.invalid', $options['user']['name']);
        self::assertSame('Synthetic User', $options['user']['displayName']);
        self::assertSame(self::base64url($handle), $options['user']['id']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(
            [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            $options['pubKeyCredParams'],
        );
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertTrue($options['authenticatorSelection']['requireResidentKey']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(self::base64url($existing->publicKeyCredentialId), $options['excludeCredentials'][0]['id']);
    }

    public function testAssertionOptionsAreRestrictedToKnownCredentialsAndRequireVerification(): void
    {
        $handle = random_bytes(32);
        $credential = $this->credential($handle);
        $challenge = random_bytes(32);

        $options = $this->service->assertionOptions([$credential], $challenge);

        self::assertSame('invoice.example.cz', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame(self::base64url($credential->publicKeyCredentialId), $options['allowCredentials'][0]['id']);
        self::assertSame(['internal', 'hybrid'], $options['allowCredentials'][0]['transports']);
    }

    public function testAssertionOptionsRejectEmptyCredentialList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertionOptions([], random_bytes(32));
    }

    public function testDiscoverableAssertionOptionsOmitCredentialListAndRequireVerification(): void
    {
        $challenge = random_bytes(32);

        $options = $this->service->discoverableAssertionOptions($challenge);

        self::assertSame('invoice.example.cz', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(self::base64url($challenge), $options['challenge']);
        self::assertSame([], $options['allowCredentials']);
    }

    public function testMalformedRegistrationPayloadIsMappedToSanitizedError(): void
    {
        $options = $this->service->registrationOptions(
            'user@example.invalid',
            'Synthetic User',
            random_bytes(32),
            [],
            random_bytes(32),
        );

        try {
            $this->service->verifyRegistration(
                ['rawId' => 'secret-credential-material', 'response' => ['attestationObject' => 'secret']],
                $options,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Registraci passkey se nepodařilo ověřit.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testMalformedAssertionPayloadIsMappedToSanitizedError(): void
    {
        $credential = $this->credential(random_bytes(32));
        $options = $this->service->assertionOptions([$credential], random_bytes(32));

        try {
            $this->service->verifyAssertion(
                ['rawId' => 'secret-credential-material', 'response' => ['signature' => 'secret']],
                $options,
                $credential,
            );
            self::fail('Malformed payload must fail.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Ověření passkey selhalo.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertSame(PasskeyVerificationException::REASON_MALFORMED, $e->reason);
            self::assertStringNotContainsString('secret', (string) $e->detail);
        }
    }

    /**
     * Důvod odmítnutí jde do auditního logu: bez něj se u správce klíčů, který
     * vrací neplatné ověření, nedalo zjistit, co nesedí.
     */
    public function testRejectedAssertionCarriesReasonForAudit(): void
    {
        [$credential, $options, $payload] = $this->signedAssertion(storedCounter: 0, responseCounter: 0);

        $tampered = $payload;
        $tampered['response']['signature'] = self::base64url(random_bytes(70));
        try {
            $this->service->verifyAssertion($tampered, $options, $credential);
            self::fail('Neplatný podpis musí selhat.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame('Ověření passkey selhalo.', $e->getMessage());
            self::assertSame(PasskeyVerificationException::REASON_ASSERTION_INVALID, $e->reason);
            self::assertNotSame('', (string) $e->detail);
        }

        $foreign = $payload;
        $foreign['id'] = $foreign['rawId'] = self::base64url(random_bytes(32));
        try {
            $this->service->verifyAssertion($foreign, $options, $credential);
            self::fail('Cizí credential musí selhat.');
        } catch (PasskeyVerificationException $e) {
            self::assertSame(PasskeyVerificationException::REASON_CREDENTIAL_MISMATCH, $e->reason);
        }
    }

    public function testDiscoverableAssertionRequiresMatchingReturnedUserHandle(): void
    {
        [$credential, $options, $payload] = $this->discoverableAssertion();

        $verified = $this->service->verifyDiscoverableAssertion(
            $payload,
            $options,
            $credential,
        );
        self::assertSame(1, $verified->counter);

        $payload['response']['userHandle'] = null;
        $this->expectException(PasskeyVerificationException::class);
        $this->service->verifyDiscoverableAssertion(
            $payload,
            $options,
            $credential,
        );
    }

    /**
     * Regrese #4: knihovna ověřovaný záznam mutuje na místě. Když dostala rovnou
     * uloženou credential, přepsala jí counter dřív, než ho volající použil jako
     * klíč optimistického zámku `WHERE sign_count = ?` — UPDATE pak nesedl na
     * žádný řádek a přihlášení skončilo jako counter anomálie u KAŽDÉHO
     * autentikátoru, který counter reálně zvyšuje.
     */
    public function testAssertionLeavesStoredRecordAtDatabaseState(): void
    {
        [$credential, $options, $payload] = $this->signedAssertion(storedCounter: 4, responseCounter: 9);

        $verified = $this->service->verifyAssertion($payload, $options, $credential);

        self::assertNotSame($credential, $verified);
        self::assertSame(4, $credential->counter, 'Uložený záznam musí zůstat obrazem DB řádku.');
        self::assertSame(9, $verified->counter);
    }

    /**
     * Autentikátor bez podpory counteru (Microsoft Password Manager i další
     * synchronizované passkeys) posílá vždy 0. Podle WebAuthn L3 se kontrola
     * counteru přeskakuje, právě když je přijatá i uložená hodnota nula.
     */
    public function testZeroSignCountAuthenticatorIsAccepted(): void
    {
        [$credential, $options, $payload] = $this->signedAssertion(
            storedCounter: 0,
            responseCounter: 0,
            credentialIdBytes: 16,
            transports: ['hybrid', 'internal'],
            backedUp: true,
        );

        $verified = $this->service->verifyAssertion($payload, $options, $credential);

        self::assertSame(0, $verified->counter);
        self::assertTrue($verified->backupEligible);
        self::assertTrue($verified->backupStatus);
    }

    /**
     * Ochrana proti klonované credential: jakmile je uložený counter nenulový,
     * musí přijatá hodnota růst. Stejná i nižší je odmítnutí, ne přihlášení.
     */
    public function testNonZeroSignCountMustIncrease(): void
    {
        foreach ([5, 3, 0] as $responseCounter) {
            [$credential, $options, $payload] = $this->signedAssertion(
                storedCounter: 5,
                responseCounter: $responseCounter,
            );

            try {
                $this->service->verifyAssertion($payload, $options, $credential);
                self::fail("Counter {$responseCounter} proti uloženému 5 musí být odmítnut.");
            } catch (PasskeyVerificationException $e) {
                self::assertSame('Ověření passkey selhalo.', $e->getMessage());
            }
            self::assertSame(5, $credential->counter);
        }
    }

    public function testCredentialIdDecodesUnpaddedBase64Url(): void
    {
        $rawId = random_bytes(32);
        self::assertSame($rawId, $this->service->credentialId([
            'rawId' => self::base64url($rawId),
        ]));
    }

    public function testCredentialIdRejectsMalformedOrOversizedValues(): void
    {
        foreach ([
            [],
            ['rawId' => '*not-base64url*'],
            ['rawId' => self::base64url(str_repeat('x', 1025))],
        ] as $payload) {
            try {
                $this->service->credentialId($payload);
                self::fail('Neplatné rawId musí být odmítnuto.');
            } catch (PasskeyVerificationException $e) {
                self::assertSame('Ověření passkey selhalo.', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    private function credential(string $handle): CredentialRecord
    {
        return CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal', 'hybrid'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            $handle,
            0,
            null,
            true,
            false,
            true,
        );
    }

    /**
     * @return array{CredentialRecord,array<string,mixed>,array<string,mixed>}
     */
    private function discoverableAssertion(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ] + self::opensslConfigArgs());
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        // openssl_pkey_get_details() vrací souřadnice jako velké celé číslo, tedy
        // bez vedoucích nul. U P-256 proto zhruba každý 128. klíč vyjde kratší než
        // 32 bajtů. COSE souřadnice ale musí být přesně 32 bajtů, takže se doplní
        // zleva — bez toho by test náhodně padal (a padal, hned na prvním CI běhu).
        $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
        self::assertSame(32, strlen($x));
        self::assertSame(32, strlen($y));

        $credentialId = random_bytes(32);
        $userHandle = random_bytes(32);
        $credentialPublicKey = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            . $x
            . "\x22\x58\x20"
            . $y;
        $credential = CredentialRecord::create(
            $credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            $credentialPublicKey,
            $userHandle,
            0,
            null,
            false,
            false,
            true,
        );

        $challenge = random_bytes(32);
        $options = $this->service->discoverableAssertionOptions($challenge);
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::base64url($challenge),
            'origin' => 'https://invoice.example.cz',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', 'invoice.example.cz', true)
            . "\x05"
            . pack('N', 1);
        self::assertTrue(openssl_sign(
            $authenticatorData . hash('sha256', $clientDataJson, true),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));

        return [
            $credential,
            $options,
            [
                'id' => self::base64url($credentialId),
                'rawId' => self::base64url($credentialId),
                'type' => 'public-key',
                'authenticatorAttachment' => 'platform',
                'clientExtensionResults' => [],
                'response' => [
                    'clientDataJSON' => self::base64url($clientDataJson),
                    'authenticatorData' => self::base64url($authenticatorData),
                    'signature' => self::base64url($signature),
                    'userHandle' => self::base64url($userHandle),
                ],
            ],
        ];
    }

    /**
     * Podepsaná non-discoverable assertion nad čerstvým P-256 klíčem.
     *
     * @param list<string> $transports
     * @return array{CredentialRecord,array<string,mixed>,array<string,mixed>}
     */
    private function signedAssertion(
        int $storedCounter,
        int $responseCounter,
        int $credentialIdBytes = 32,
        array $transports = ['internal'],
        bool $backedUp = false,
    ): array {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ] + self::opensslConfigArgs());
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        // Doplnění na 32 bajtů zleva — viz komentář v discoverableAssertion().
        $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);

        $credentialId = random_bytes($credentialIdBytes);
        $userHandle = random_bytes(32);
        $credential = CredentialRecord::create(
            $credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $transports,
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y,
            $userHandle,
            $storedCounter,
            null,
            $backedUp,
            $backedUp,
            true,
        );

        $challenge = random_bytes(32);
        $options = $this->service->assertionOptions([$credential], $challenge);
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::base64url($challenge),
            'origin' => 'https://invoice.example.cz',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // UP|UV, plus BE|BS u zálohovaných (synchronizovaných) passkeys.
        $flags = 0x05 | ($backedUp ? 0x18 : 0x00);
        $authenticatorData = hash('sha256', 'invoice.example.cz', true)
            . chr($flags)
            . pack('N', $responseCounter);
        self::assertTrue(openssl_sign(
            $authenticatorData . hash('sha256', $clientDataJson, true),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));

        return [
            $credential,
            $options,
            [
                'id' => self::base64url($credentialId),
                'rawId' => self::base64url($credentialId),
                'type' => 'public-key',
                'authenticatorAttachment' => 'platform',
                'clientExtensionResults' => [],
                'response' => [
                    'clientDataJSON' => self::base64url($clientDataJson),
                    'authenticatorData' => self::base64url($authenticatorData),
                    'signature' => self::base64url($signature),
                    'userHandle' => self::base64url($userHandle),
                ],
            ],
        ];
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
