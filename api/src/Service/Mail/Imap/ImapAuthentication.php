<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\Imap;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthProviderConfig;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

final class ImapAuthentication
{
    /** @var array<string,array{token:string,expires:int}> */
    private array $tokens = [];

    public function __construct(
        private readonly OAuthTokenClient $oauth,
        private readonly SecretEncryption $secrets,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
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

    /**
     * Do logu ani do UI nepředáváme text z protokolu, může obsahovat příkaz XOAUTH2.
     *
     * @param array<string,mixed> $settings
     */
    public function reportFailure(\Throwable $error, string $stage, string $purpose, string $operation, array $settings): string
    {
        [$code, $message] = $this->describeFailure($error, $stage);
        $this->logger->warning('imap_oauth_failure', [
            'stage' => $stage,
            'code' => $code,
            'oauth_http_status' => $error instanceof OAuthException ? $error->httpStatus : null,
        ] + $this->logContext($purpose, $operation, $settings));

        return 'Microsoft 365: ' . $message . ' [' . $code . ']';
    }

    /** @param array<string,mixed> $settings */
    public function reportProbeSuccess(string $purpose, string $operation, array $settings, int $folderCount): void
    {
        $this->logger->info('imap_oauth_probe_succeeded', [
            'folder_count' => $folderCount,
        ] + $this->logContext($purpose, $operation, $settings));
    }

    /** @param array<string,mixed> $settings */
    private function logContext(string $purpose, string $operation, array $settings): array
    {
        return [
            'purpose' => $purpose,
            'operation' => $operation,
            'supplier_id' => (int) ($settings['supplier_id'] ?? 0) ?: null,
            'connection_id' => (int) ($settings['id'] ?? 0) ?: null,
        ];
    }

    /** @return array{string,string} */
    private function describeFailure(\Throwable $error, string &$stage): array
    {
        if ($error instanceof OAuthException) {
            $stage = 'token';
            return match ($error->errorCode) {
                OAuthException::ENCRYPTION_KEY => ['oauth_secret_unreadable', 'Client Secret nelze načíst. Ověř šifrovací klíč aplikace nebo zadej secret znovu.'],
                OAuthException::INVALID_CLIENT, OAuthException::INVALID_GRANT
                    => ['oauth_credentials_rejected', 'Entra odmítla přihlašovací údaje. Ověř Tenant ID, Client ID a platnost Client Secret.'],
                OAuthException::REMOTE_UNAVAILABLE => ['oauth_endpoint_unreachable', 'Autorizační služba Microsoft Entra není dostupná.'],
                OAuthException::RATE_LIMITED => ['oauth_rate_limited', 'Microsoft Entra omezila četnost požadavků. Zkus to později.'],
                default => ['oauth_token_failed', 'Získání tokenu selhalo. Ověř registraci aplikace a souhlas správce.'],
            };
        }

        if ($stage === 'connect') {
            return $this->describeConnectFailure($error, $stage);
        }

        return match ($stage) {
            'list_folders' => ['imap_list_failed', 'Přihlášení k Exchange proběhlo, ale seznam složek se nepodařilo načíst.'],
            'select_folder' => ['imap_folder_failed', 'Přihlášení k Exchange proběhlo, ale cílovou složku se nepodařilo otevřít nebo vytvořit.'],
            'read_messages' => ['imap_read_failed', 'Přihlášení k Exchange proběhlo, ale zprávy se nepodařilo načíst.'],
            'write_message' => ['imap_write_failed', 'Přihlášení k Exchange proběhlo, ale zprávu se nepodařilo uložit do složky.'],
            'update_message' => ['imap_update_failed', 'Přihlášení k Exchange proběhlo, ale zprávu se nepodařilo označit nebo přesunout.'],
            default => ['imap_configuration_invalid', 'Zkontroluj konfiguraci připojení Microsoft 365.'],
        };
    }

    /** @return array{string,string} */
    private function describeConnectFailure(\Throwable $error, string &$stage): array
    {
        $rejected = false;
        for ($item = $error; $item !== null; $item = $item->getPrevious()) {
            $message = $item->getMessage();
            if (preg_match('/certificate verify|peer certificate|SSL operation failed/i', $message)) {
                return ['imap_tls_failed', 'Zabezpečené spojení s Exchange na portu 993 selhalo. Ověř důvěryhodnost certifikátu a TLS.'];
            }
            if (preg_match('/timed? out|timeout/i', $message)) {
                return ['imap_timeout', 'Exchange na portu 993 neodpověděl včas. Ověř síť a firewall.'];
            }
            $rejected = $rejected || $item instanceof AuthFailedException
                || preg_match('/got failure response: (NO|BAD) |AUTHENTICATE failed|authenticated but not connected/i', $message) === 1;
        }

        if ($rejected) {
            $stage = 'authenticate';
            return ['imap_auth_rejected', 'Token byl získán, ale Exchange odmítl přihlášení IMAP. Ověř registraci aplikace v Exchange, oprávnění FullAccess ke schránce a povolený IMAP.'];
        }

        return ['imap_connection_failed', 'Spojení s Exchange se nepodařilo navázat. Ověř dostupnost outlook.office365.com na portu 993.'];
    }
}
