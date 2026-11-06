<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Diagnostika bezpečnostně citlivých výchozích hodnot instalace.
 *
 * Instalátor Dockeru dřív zapisoval `app.env = development` a
 * `session.cookie_secure = false` a nic je nezměnilo, když instance později
 * dostala veřejnou HTTPS adresu. Session cookie pak odcházela i přes HTTP a
 * vývojový režim vypínal produkční pojistky. Konfiguraci tu ZÁMĚRNĚ jen
 * hlásíme a nic sami nepřepínáme: vynucená Secure cookie na instalaci, kam se
 * ve skutečnosti chodí přes HTTP, by znemožnila přihlášení.
 *
 * Totéž platí pro `app.payroll_hash_key`. Bez něj se klíč mzdových otisků
 * odvozuje z pepperu. Na existující instalaci ho NELZE jen tak doplnit, protože
 * nový klíč by zneplatnil všechny uložené otisky. Proto jen upozornění.
 *
 * Výstup nikdy neobsahuje hodnoty konfigurace, jen stabilní kód a návod.
 */
final class InstanceSecurityConfigurationCheck
{
    public const CODE_COOKIE_INSECURE = 'session_cookie_insecure';
    public const CODE_ENV_DEVELOPMENT = 'app_env_development';
    public const CODE_PAYROLL_HASH_KEY = 'payroll_hash_key_fallback';

    public function __construct(private readonly Config $config) {}

    /**
     * @return list<array{code:string,message:string}>
     */
    public function warnings(): array
    {
        $warnings = [];
        $url = $this->config->get('app.url', '');
        $url = is_string($url) ? trim($url) : '';
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]'));
        $isHttps = $scheme === 'https';
        $isLocalHost = self::isLocalHost($host);

        if ($isHttps && !$this->cookieSecure()) {
            $warnings[] = [
                'code' => self::CODE_COOKIE_INSECURE,
                'message' => 'app.url používá HTTPS, ale session.cookie_secure je vypnuté. '
                    . 'Přihlašovací cookie se tak může odeslat i nešifrovaně. Nastavte '
                    . 'session.cookie_secure = true (a ideálně cookie s prefixem __Host-).',
            ];
        }

        $env = (string) $this->config->get('app.env', 'production');
        if ($env === 'development' && ($isHttps || ($host !== '' && !$isLocalHost))) {
            $warnings[] = [
                'code' => self::CODE_ENV_DEVELOPMENT,
                'message' => 'Instance běží v režimu app.env = development, ačkoli app.url '
                    . 'nevede na localhost. Vývojový režim je jen pro lokální instalaci; '
                    . 'pro veřejně dostupnou instanci nastavte app.env = production.',
            ];
        }

        if (!$this->hasPayrollHashKey()) {
            $warnings[] = [
                'code' => self::CODE_PAYROLL_HASH_KEY,
                'message' => 'app.payroll_hash_key není nastavený, klíč mzdových otisků '
                    . '(rodné číslo, číslo dokladu) se odvozuje z app.pepper. Pepper proto '
                    . 'chraňte stejně jako šifrovací klíč. Na instalaci s mzdovými daty klíč '
                    . 'NEDOPLŇUJTE ani neměňte: uložené otisky by přestaly platit.',
            ];
        }

        return $warnings;
    }

    private function cookieSecure(): bool
    {
        $value = $this->config->get('session.cookie_secure', true);
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    private function hasPayrollHashKey(): bool
    {
        $value = $this->config->get('app.payroll_hash_key', '');

        return is_string($value) && trim($value) !== '';
    }

    private static function isLocalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        return str_starts_with($host, '127.');
    }
}
