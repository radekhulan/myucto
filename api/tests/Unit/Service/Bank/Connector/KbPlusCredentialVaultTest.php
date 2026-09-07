<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use MyInvoice\Service\Bank\Connector\KbPlusCredentialVault;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class KbPlusCredentialVaultTest extends TestCase
{
    public function testRefreshRotationIsPersistedBeforeCredentialIsUsed(): void
    {
        $api = $this->createMock(KbPlusApiClient::class);
        $repository = $this->createMock(KbPlusOAuthRepository::class);
        $secrets = $this->createMock(SecretEncryption::class);
        $vault = new KbPlusCredentialVault($api, $repository, $secrets);
        $serialized = $vault->encode($this->credentials(['access_expires_at' => time() - 1]));

        $api->expects(self::once())->method('refreshAccessToken')->willReturn([
            'access_token' => 'rotated-access-token-0001',
            'token_type' => 'Bearer',
            'expires_in' => 180,
            'scope' => 'adaa bpisp',
            'refresh_token' => 'rotated-refresh-token-0001',
        ]);
        $secrets->expects(self::once())->method('encryptFor')
            ->with(self::callback(static fn (string $value): bool =>
                str_contains($value, 'rotated-refresh-token-0001')
                && str_contains($value, 'rotated-access-token-0001')
            ), KbPlusCredentialVault::context(7, 19))
            ->willReturn('enc:v2:rotated');
        $repository->expects(self::once())->method('replaceConnectionCredential')
            ->with(7, 19, 'enc:v2:rotated')->willReturn(true);

        $result = $vault->access($serialized);

        self::assertSame('rotated-access-token-0001', $result['access_token']);
        self::assertSame('rotated-refresh-token-0001', $result['credentials']['refresh_token']);
    }

    public function testRefreshResponseWithoutNewRefreshTokenKeepsExistingOne(): void
    {
        $api = $this->createMock(KbPlusApiClient::class);
        $repository = $this->createMock(KbPlusOAuthRepository::class);
        $secrets = $this->createMock(SecretEncryption::class);
        $vault = new KbPlusCredentialVault($api, $repository, $secrets);
        $serialized = $vault->encode($this->credentials(['access_expires_at' => time() - 1]));

        $api->method('refreshAccessToken')->willReturn([
            'access_token' => 'rotated-access-token-0001',
            'token_type' => 'Bearer',
            'expires_in' => 180,
            'scope' => 'adaa bpisp',
        ]);
        $secrets->method('encryptFor')->willReturn('enc:v2:rotated');
        $repository->method('replaceConnectionCredential')->willReturn(true);

        self::assertSame('synthetic-refresh-token-0001', $vault->access($serialized)['credentials']['refresh_token']);
    }

    public function testFailedPersistenceBlocksUseOfRotatedAccessToken(): void
    {
        $api = $this->createMock(KbPlusApiClient::class);
        $repository = $this->createMock(KbPlusOAuthRepository::class);
        $secrets = $this->createMock(SecretEncryption::class);
        $vault = new KbPlusCredentialVault($api, $repository, $secrets);
        $serialized = $vault->encode($this->credentials(['access_expires_at' => time() - 1]));
        $api->method('refreshAccessToken')->willReturn([
            'access_token' => 'rotated-access-token-0001', 'token_type' => 'Bearer',
            'expires_in' => 180, 'scope' => 'adaa bpisp',
        ]);
        $secrets->method('encryptFor')->willReturn('enc:v2:rotated');
        $repository->method('replaceConnectionCredential')->willReturn(false);

        try {
            $vault->access($serialized);
            self::fail('Access token se nesmí použít, pokud rotaci nešlo uložit.');
        } catch (BankConnectorException $e) {
            self::assertSame('credential_rotation_failed', $e->errorCode);
        }
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function credentials(array $override = []): array
    {
        return array_replace([
            'version' => 1,
            'supplier_id' => 7,
            'connection_id' => 19,
            'oauth_api_key' => 'synthetic-oauth-key',
            'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => 'synthetic-batchda-key',
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-client-secret',
            'redirect_uri' => 'https://example.invalid/callback',
            'scope' => 'adaa bpisp',
            'refresh_token' => 'synthetic-refresh-token-0001',
            'access_token' => 'synthetic-access-token-0001',
            'access_expires_at' => time() + 180,
            'account_id' => 'synthetic-account',
            'account_iban' => 'CZ0401000000191000000005',
            'account_currency' => 'CZK',
            'call_guard_key' => str_repeat('g', 43),
        ], $override);
    }
}
