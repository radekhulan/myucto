<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

/** Dočasná session je v prohlížeči dostupná jen uvnitř obálky konkrétního přihlášení. */
final class DomainLoginSessionCookie
{
    public function __construct(
        private readonly Config $config,
        private readonly SecretEncryption $encryption,
    ) {}

    public function standardName(): string
    {
        return (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
    }

    public function name(string $requestToken): string
    {
        return $this->standardName() . '_domain_' . hash('sha256', $requestToken);
    }

    public function seal(string $token, string $requestToken, string $state): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Neplatný token dočasné session.');
        }
        return rtrim(strtr(base64_encode($this->encryption->encryptFor(
            $token, self::context($requestToken, $state),
        )), '+/', '-_'), '=');
    }

    public function open(string $cookie, string $requestToken, string $state): ?string
    {
        if (strlen($cookie) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $cookie) !== 1) return null;
        $envelope = base64_decode(strtr($cookie, '-_', '+/'), true);
        // decryptFor má zpětně kompatibilní plaintext/v1 fallback, který sem nesmí proniknout.
        if ($envelope === false || !str_starts_with($envelope, 'enc:v2:')) return null;
        try {
            $token = $this->encryption->decryptFor($envelope, self::context($requestToken, $state));
        } catch (\RuntimeException) {
            return null;
        }
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : null;
    }

    private static function context(string $requestToken, string $state): string
    {
        return 'domain-login-session:v1:' . $requestToken . ':' . $state;
    }
}
