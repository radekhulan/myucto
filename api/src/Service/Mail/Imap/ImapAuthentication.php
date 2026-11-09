<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\Imap;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthProviderConfig;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use Psr\Clock\ClockInterface;

final class ImapAuthentication
{
    /** @var array<string,array{token:string,expires:int}> */
    private array $tokens = [];

    public function __construct(
        private readonly OAuthTokenClient $oauth,
        private readonly SecretEncryption $secrets,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Vrací volby pro Webklex. Token patří pouze do tohoto interního pole.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function options(#[\SensitiveParameter] array $settings, string $purpose, string $prefix = ''): array
    {
        if (($settings[$prefix . 'auth_mode'] ?? ImapAuthSettings::PASSWORD) !== ImapAuthSettings::MICROSOFT) {
            return [];
        }
        $auth = ImapAuthSettings::normalize($settings, $settings, $prefix);
        $tenant = $auth[$prefix . 'oauth_tenant_id'];
        $client = $auth[$prefix . 'oauth_client_id'];
        $mailbox = $auth[$prefix . 'username'];
        $context = ImapAuthSettings::context($auth, (int) ($settings['supplier_id'] ?? 0), (int) ($settings['id'] ?? 0), $purpose, $prefix);
        $secret = $auth[$prefix . 'oauth_client_secret'];
        if ($secret === null) {
            try {
                $secret = $this->secrets->decryptFor($auth[$prefix . 'oauth_client_secret_enc'], $context);
            } catch (\RuntimeException) {
                throw new OAuthException(OAuthException::ENCRYPTION_KEY, 'Client Secret nelze načíst.');
            }
        }

        $key = hash('sha256', $context . "\0" . $secret);
        $now = $this->clock->now()->getTimestamp();
        $entry = $this->tokens[$key] ?? null;
        if ($entry === null || $entry['expires'] <= $now + 30) {
            $token = $this->oauth->clientCredentials(new OAuthProviderConfig(
                $client,
                'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
                scope: 'https://outlook.office365.com/.default',
                clientSecret: $secret,
            ));
            $entry = $this->tokens[$key] = ['token' => $token->accessToken, 'expires' => $now + $token->expiresIn];
        }

        return [
            'host' => ImapAuthSettings::HOST,
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $mailbox,
            'password' => $entry['token'],
            'authentication' => 'oauth',
        ];
    }

    /** Pro OAuth nepřebíráme text výjimky z protokolu, může obsahovat celý příkaz XOAUTH2. */
    public static function failureMessage(\Throwable $error): string
    {
        if ($error instanceof OAuthException) {
            return match ($error->errorCode) {
                OAuthException::ENCRYPTION_KEY, OAuthException::INVALID_CLIENT, OAuthException::INVALID_GRANT
                    => 'Microsoft 365: ověř Client ID, Tenant ID a platnost uloženého Client Secret.',
                OAuthException::REMOTE_UNAVAILABLE, OAuthException::RATE_LIMITED
                    => 'Microsoft 365: autorizační služba je dočasně nedostupná.',
                default => 'Microsoft 365: získání přístupového tokenu selhalo. Ověř registraci aplikace a souhlas správce.',
            };
        }

        return 'Microsoft 365: operace IMAP selhala. Ověř povolení IMAP, přístup aplikace ke schránce a cílovou složku.';
    }
}
