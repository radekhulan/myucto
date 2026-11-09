<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Mail\Imap\ImapAuthentication;
use MyInvoice\Service\Mail\Imap\ImapAuthSettings;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImapAuthenticationTest extends TestCase
{
    private const TENANT = '11111111-1111-4111-8111-111111111111';
    private const CLIENT = '22222222-2222-4222-8222-222222222222';
    private const SECRET = 'synthetic-client-secret';

    public function testClientCredentialsPinsServersAndReusesTokenUntilExpiry(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"token_type":"Bearer","access_token":"token-one","expires_in":120}'),
            new Response(200, [], '{"token_type":"Bearer","access_token":"token-two","expires_in":120}'),
        ]));
        $stack->push(Middleware::history($history));
        $clock = new MockClock('2026-11-09 10:00:00Z');
        $auth = new ImapAuthentication(new OAuthTokenClient(new Client(['handler' => $stack])), $this->secrets(), $clock);
        $settings = $this->settings() + [
            'host' => 'attacker.example.test',
            'port' => 143,
            'encryption' => 'none',
            'validate_cert' => false,
        ];

        self::assertSame([], $auth->options(['auth_mode' => 'password'], ImapAuthSettings::INCOMING));
        self::assertSame([
            'host' => 'outlook.office365.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => 'shared@example.test',
            'password' => 'token-one',
            'authentication' => 'oauth',
        ], $auth->options($settings, ImapAuthSettings::INCOMING));
        self::assertSame('token-one', $auth->options($settings, ImapAuthSettings::INCOMING)['password']);
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $body);
        self::assertSame([
            'client_id' => self::CLIENT,
            'grant_type' => 'client_credentials',
            'scope' => 'https://outlook.office365.com/.default',
            'client_secret' => self::SECRET,
        ], $body);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);

        $clock->sleep(100);
        self::assertSame('token-two', $auth->options($settings, ImapAuthSettings::INCOMING)['password']);
        self::assertCount(2, $history);
    }

    public function testCopyingStoredSecretToAnotherCompanyFailsBeforeTokenRequest(): void
    {
        $secrets = $this->secrets();
        $settings = $this->settings();
        $settings['oauth_client_secret_enc'] = ImapAuthSettings::encryptedSecret($secrets, $settings, 1, 2, ImapAuthSettings::INCOMING);
        unset($settings['oauth_client_secret']);
        $auth = new ImapAuthentication(
            new OAuthTokenClient(new Client(['handler' => new MockHandler([
                new Response(200, [], '{"access_token":"synthetic-bearer","expires_in":3600}'),
            ])])),
            $secrets,
            new MockClock(),
        );
        self::assertSame('synthetic-bearer', $auth->options($settings, ImapAuthSettings::INCOMING)['password']);
        $settings['supplier_id'] = 3;
        try {
            $auth->options($settings, ImapAuthSettings::INCOMING);
            self::fail('Cizí Client Secret se nesmí použít.');
        } catch (OAuthException $error) {
            self::assertSame(OAuthException::ENCRYPTION_KEY, $error->errorCode);
        }
    }

    public function testInvalidSecretIsReportedWithoutResponseBody(): void
    {
        $auth = new ImapAuthentication(new OAuthTokenClient(new Client(['handler' => new MockHandler([
            new Response(401, [], '{"error":"invalid_client","error_description":"synthetic-client-secret"}'),
        ])])), $this->secrets(), new MockClock());

        try {
            $auth->options($this->settings(), ImapAuthSettings::INCOMING);
            self::fail('Neplatný Client Secret musí přihlášení zastavit.');
        } catch (OAuthException $error) {
            self::assertSame(OAuthException::INVALID_CLIENT, $error->errorCode);
            $message = ImapAuthentication::failureMessage($error);
            self::assertStringContainsString('Client Secret', $message);
            self::assertStringNotContainsString(self::SECRET, $message . $error->getMessage());
        }
    }

    private function settings(): array
    {
        return [
            'supplier_id' => 1,
            'id' => 2,
            'auth_mode' => ImapAuthSettings::MICROSOFT,
            'oauth_tenant_id' => self::TENANT,
            'oauth_client_id' => self::CLIENT,
            'oauth_client_secret' => self::SECRET,
            'username' => 'shared@example.test',
        ];
    }

    private function secrets(): SecretEncryption
    {
        return new SecretEncryption(new Config(['app' => ['pepper' => 'synthetic-test-pepper']]));
    }
}
