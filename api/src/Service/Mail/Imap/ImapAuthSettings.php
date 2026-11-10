<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\Imap;

use MyInvoice\Service\Auth\SecretEncryption;

/** Společná pravidla pro přihlašovací údaje příchozího i odchozího IMAP. */
final class ImapAuthSettings
{
    public const MICROSOFT = 'microsoft365';
    public const PASSWORD = 'password';
    public const HOST = 'outlook.office365.com';
    public const INCOMING = 'incoming';
    public const SENT = 'sent';

    /**
     * @param array<string,mixed> $draft Sloučené hodnoty formuláře.
     * @param array<string,mixed> $current Výhradně uložené hodnoty z databáze.
     * @return array<string,mixed>
     */
    public static function normalize(#[\SensitiveParameter] array $draft, array $current = [], string $prefix = ''): array
    {
        $mode = array_key_exists($prefix . 'auth_mode', $draft) ? $draft[$prefix . 'auth_mode'] : self::PASSWORD;
        $keys = [
            $prefix . 'auth_mode' => $mode,
            $prefix . 'oauth_tenant_id' => null,
            $prefix . 'oauth_client_id' => null,
            $prefix . 'oauth_client_secret' => null,
            $prefix . 'oauth_client_secret_enc' => null,
        ];
        if ($mode === self::PASSWORD) {
            return $keys;
        }
        if ($mode !== self::MICROSOFT) {
            throw new \InvalidArgumentException('Neplatný způsob přihlášení k IMAP.');
        }

        $tenant = self::uuid($draft[$prefix . 'oauth_tenant_id'] ?? null, 'Tenant ID');
        $client = self::uuid($draft[$prefix . 'oauth_client_id'] ?? null, 'Client ID');
        $mailbox = $draft[$prefix . 'username'] ?? null;
        if (!is_string($mailbox) || strlen(trim($mailbox)) > 190 || !filter_var(trim($mailbox), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Pro Microsoft 365 zadejte e-mailovou adresu schránky.');
        }
        $mailbox = strtolower(trim($mailbox));
        $secret = $draft[$prefix . 'oauth_client_secret'] ?? '';
        if (!is_string($secret) || strlen($secret) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $secret)) {
            throw new \InvalidArgumentException('Neplatná hodnota Client Secret pro Microsoft 365.');
        }

        $secret = trim($secret);
        $encrypted = null;
        if ($secret === '') {
            if (($current[$prefix . 'auth_mode'] ?? null) !== self::MICROSOFT
                || ($current[$prefix . 'oauth_tenant_id'] ?? null) !== $tenant
                || ($current[$prefix . 'oauth_client_id'] ?? null) !== $client
                || ($current[$prefix . 'username'] ?? null) !== $mailbox
                || empty($current[$prefix . 'oauth_client_secret_enc'])) {
                throw new \InvalidArgumentException('Zadejte Client Secret pro aktuální aplikaci a schránku Microsoft 365.');
            }
            $encrypted = $current[$prefix . 'oauth_client_secret_enc'];
        }

        return array_replace($keys, [
            $prefix . 'oauth_tenant_id' => $tenant,
            $prefix . 'oauth_client_id' => $client,
            $prefix . 'oauth_client_secret' => $secret === '' ? null : $secret,
            $prefix . 'oauth_client_secret_enc' => $encrypted,
            $prefix . 'username' => $mailbox,
            $prefix . 'host' => self::HOST,
            $prefix . 'port' => 993,
            $prefix . 'encryption' => 'ssl',
            $prefix . 'validate_cert' => true,
            $prefix . 'password' => null,
        ]);
    }

    /** @param array<string,mixed> $settings */
    public static function encryptedSecret(
        SecretEncryption $secrets,
        #[\SensitiveParameter] array $settings,
        int $supplierId,
        int $id,
        string $purpose,
        string $prefix = '',
    ): ?string {
        $secret = $settings[$prefix . 'oauth_client_secret'] ?? null;
        if ($secret === null) {
            return $settings[$prefix . 'oauth_client_secret_enc'] ?? null;
        }

        return $secrets->encryptFor($secret, self::context($settings, $supplierId, $id, $purpose, $prefix));
    }

    /**
     * Uložené heslo smí jít jen na server, pro který ho uživatel zadal. Jinak by
     * kdokoli s právem upravit účet přesměroval host na vlastní server a heslo
     * ke schránce si tam odchytil, aniž by ho kdy viděl.
     *
     * @param array<string,mixed> $current Uložené hodnoty z databáze.
     * @param array<string,mixed> $next Výsledné hodnoty, se kterými se heslo použije.
     */
    public static function assertPasswordTargetUnchanged(array $current, array $next): void
    {
        foreach (['host', 'port', 'encryption', 'username'] as $field) {
            if (self::targetValue($field, $current[$field] ?? null) !== self::targetValue($field, $next[$field] ?? null)) {
                throw new ImapPasswordRequiredException(
                    'Měníte server, port, šifrování nebo uživatele IMAP účtu. Zadejte proto znovu heslo.'
                );
            }
        }
    }

    private static function targetValue(string $field, mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        return match ($field) {
            'port' => (string) (int) $value,
            'host' => strtolower($value),
            default => $value,
        };
    }

    /** @param array<string,mixed> $settings */
    public static function context(array $settings, int $supplierId, int $id, string $purpose, string $prefix = ''): string
    {
        return implode(':', [
            'imap-oauth', $purpose, $supplierId, $id,
            $settings[$prefix . 'oauth_tenant_id'], $settings[$prefix . 'oauth_client_id'], $settings[$prefix . 'username'],
        ]);
    }

    private static function uuid(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', trim($value))) {
            throw new \InvalidArgumentException('Neplatný údaj Microsoft 365: ' . $field . '.');
        }
        return strtolower(trim($value));
    }
}
