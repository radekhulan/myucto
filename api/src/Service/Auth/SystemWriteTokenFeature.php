<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Plný zápis API tokenem (příznak `system_write`, issue #371) povoluje provozovatel
 * instalace přes `api.allow_system_write_tokens` v cfg.php (ENV
 * MYINVOICE_API_ALLOW_SYSTEM_WRITE_TOKENS). Výchozí stav je VYPNUTO.
 *
 * Vypnutí platí okamžitě i pro už vydané tokeny: token s příznakem se pak chová
 * jako běžný token ({@see \MyInvoice\Middleware\AuthMiddleware}), takže provozovatel
 * jedním přepínačem zavře všechny tokeny s plným zápisem naráz.
 */
final class SystemWriteTokenFeature
{
    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return filter_var(
            $this->config->get('api.allow_system_write_tokens', false),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === true;
    }

    /**
     * Platí příznak pro načtený řádek tokenu? Jen token pro veřejné API (ne OAuth
     * připojení MCP) se zápisem, s výslovně zapnutým příznakem a na povolené instanci.
     *
     * @param array<string,mixed> $tokenRow
     */
    public function appliesTo(array $tokenRow): bool
    {
        return !empty($tokenRow['allow_system_write'])
            && ($tokenRow['scope'] ?? '') === 'read_write'
            && ($tokenRow['audience'] ?? 'api') === 'api'
            && $this->isEnabled();
    }
}
