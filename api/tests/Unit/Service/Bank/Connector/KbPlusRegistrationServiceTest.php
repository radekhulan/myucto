<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusRegistrationService;
use PHPUnit\Framework\TestCase;

final class KbPlusRegistrationServiceTest extends TestCase
{
    private const STATE = 'synthetic_registration_state_000001';
    private const REDIRECT_URI = 'https://example.invalid/api/bank/kb-plus/oauth/callback';

    public function testBuildsDocumentedRegistrationRedirectAndReturnsEphemeralKey(): void
    {
        $result = new KbPlusRegistrationService()->begin(
            $this->softwareStatement(),
            $this->application(),
            self::STATE,
        );
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);
        $request = json_decode(
            base64_decode($query['registrationRequest'], true),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );

        self::assertStringStartsWith(
            'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?',
            $result['url'],
        );
        self::assertSame(self::STATE, $query['state']);
        self::assertSame(self::STATE, $result['state']);
        self::assertSame('Syntetické MyÚčto', $request['clientName']);
        self::assertSame('Synthetic MyUcto', $request['clientNameEn']);
        self::assertSame('web', $request['applicationType']);
        self::assertSame([self::REDIRECT_URI], $request['redirectUris']);
        self::assertSame(['adaa', 'bpisp', 'statda'], $request['scope']);
        self::assertSame('AES-256', $request['encryptionAlg']);
        self::assertSame($this->softwareStatement(), $request['softwareStatement']);
        self::assertSame(32, strlen(base64_decode($request['encryptionKey'], true)));
        self::assertSame($request['encryptionKey'], $result['encryption_key']);
    }

    public function testDecryptsAndValidatesCredentialCallbackWithEncryptedState(): void
    {
        $service = new KbPlusRegistrationService();
        $begin = $service->begin($this->softwareStatement(), $this->application(), self::STATE);
        $callback = $this->encryptedCallback($begin['encryption_key'], $this->credentialResponse());

        $credentials = $service->complete(
            $begin['encryption_key'],
            self::STATE,
            self::REDIRECT_URI,
            ['adaa', 'bpisp', 'statda'],
            $callback,
        );

        self::assertSame([
            'client_id' => 'SyntheticClient-001',
            'client_secret' => 'synthetic-client-secret-0001',
            'scope' => 'adaa bpisp statda',
            'client_id_issued_at' => 1788750000,
        ], $credentials);
        self::assertArrayNotHasKey('registration_client_uri', $credentials);
        self::assertArrayNotHasKey('api_key', $credentials);
    }

    public function testAcceptsDocumentedQueryStateWhenEncryptedPayloadOmitsIt(): void
    {
        $service = new KbPlusRegistrationService();
        $begin = $service->begin($this->softwareStatement(), $this->application(), self::STATE);
        $response = $this->credentialResponse();
        unset($response['state']);
        $callback = $this->encryptedCallback($begin['encryption_key'], $response);
        $callback['state'] = self::STATE;

        $credentials = $service->complete(
            $begin['encryption_key'],
            self::STATE,
            self::REDIRECT_URI,
            ['adaa', 'bpisp', 'statda'],
            $callback,
        );

        self::assertSame('SyntheticClient-001', $credentials['client_id']);
    }

    public function testRejectsTamperedCiphertextWithoutExposingSecret(): void
    {
        $service = new KbPlusRegistrationService();
        $begin = $service->begin($this->softwareStatement(), $this->application(), self::STATE);
        $callback = $this->encryptedCallback($begin['encryption_key'], $this->credentialResponse());
        $callback['encryptedData'][5] = $callback['encryptedData'][5] === 'A' ? 'B' : 'A';

        try {
            $service->complete(
                $begin['encryption_key'],
                self::STATE,
                self::REDIRECT_URI,
                ['adaa', 'bpisp', 'statda'],
                $callback,
            );
            self::fail('Pozměněný callback nesmí být přijat.');
        } catch (BankConnectorException $e) {
            self::assertSame('kb_plus_registration_invalid', $e->errorCode);
            self::assertStringNotContainsString('synthetic-client-secret-0001', $e->getMessage());
        }
    }

    public function testRejectsMismatchedOrMissingState(): void
    {
        $service = new KbPlusRegistrationService();
        $begin = $service->begin($this->softwareStatement(), $this->application(), self::STATE);
        foreach (['different_registration_state_0001', null] as $state) {
            $response = $this->credentialResponse();
            if ($state === null) {
                unset($response['state']);
            } else {
                $response['state'] = $state;
            }
            $callback = $this->encryptedCallback($begin['encryption_key'], $response);
            try {
                $service->complete(
                    $begin['encryption_key'],
                    self::STATE,
                    self::REDIRECT_URI,
                    ['adaa', 'bpisp', 'statda'],
                    $callback,
                );
                self::fail('Callback bez odpovídajícího state nesmí být přijat.');
            } catch (BankConnectorException $e) {
                self::assertSame('kb_plus_registration_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsChangedRedirectOrReducedScope(): void
    {
        $service = new KbPlusRegistrationService();
        $begin = $service->begin($this->softwareStatement(), $this->application(), self::STATE);
        foreach (['redirect', 'scope'] as $change) {
            $response = $this->credentialResponse();
            if ($change === 'redirect') {
                $response['redirect_uris'] = 'https://attacker.invalid/callback';
            } else {
                $response['scopes'] = ['adaa'];
                $response['scope'] = 'adaa';
            }
            $callback = $this->encryptedCallback($begin['encryption_key'], $response);
            try {
                $service->complete(
                    $begin['encryption_key'],
                    self::STATE,
                    self::REDIRECT_URI,
                    ['adaa', 'bpisp', 'statda'],
                    $callback,
                );
                self::fail('Callback změněné aplikace nesmí být přijat.');
            } catch (BankConnectorException $e) {
                self::assertSame('kb_plus_registration_invalid', $e->errorCode);
            }
        }
    }

    /** @return array<string,mixed> */
    private function application(): array
    {
        return [
            'client_name' => 'Syntetické MyÚčto',
            'client_name_en' => 'Synthetic MyUcto',
            'redirect_uris' => [self::REDIRECT_URI],
            'scopes' => ['adaa', 'bpisp', 'statda'],
        ];
    }

    /** @return array<string,mixed> */
    private function credentialResponse(): array
    {
        return [
            'application_type' => 'web',
            'redirect_uris' => self::REDIRECT_URI,
            'scopes' => ['adaa', 'bpisp', 'statda'],
            'scope' => 'adaa bpisp statda',
            'response_types' => ['code'],
            'grant_types' => ['refresh_token', 'authorization_code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'client_id' => 'SyntheticClient-001',
            'client_secret' => 'synthetic-client-secret-0001',
            'state' => self::STATE,
            'api_key' => 'NOT_PROVIDED',
            'registration_client_uri' => 'https://example.invalid/registration/SyntheticClient-001',
            'client_id_issued_at' => 1788750000,
        ];
    }

    /** @param array<string,mixed> $payload @return array{salt:string,encryptedData:string} */
    private function encryptedCallback(string $base64Key, array $payload): array
    {
        $key = base64_decode($base64Key, true);
        self::assertIsString($key);
        $salt = random_bytes(12);
        $ciphertext = openssl_encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $salt,
            $tag,
        );
        self::assertIsString($ciphertext);
        return [
            'salt' => $this->base64Url($salt),
            'encryptedData' => $this->base64Url($ciphertext . $tag),
        ];
    }

    private function softwareStatement(): string
    {
        return $this->base64Url('{"alg":"HS256","typ":"JWT"}') . '.'
            . $this->base64Url('{"softwareId":"synthetic-software-001"}') . '.'
            . $this->base64Url('synthetic-signature');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
