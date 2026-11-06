<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use Psr\Clock\ClockInterface;

final class SessionCookieFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    public function create(string $token, int $expiresAt): string
    {
        $name = $this->name();
        $sameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        $secure = (bool) $this->config->get('session.cookie_secure', true);
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Neplatný název auth cookie.');
        }
        if (!in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new \InvalidArgumentException('Neplatná SameSite politika auth cookie.');
        }

        return sprintf(
            '%s=%s; HttpOnly; Path=/; Max-Age=%d; Expires=%s; SameSite=%s%s',
            $name,
            $token,
            max(0, $expiresAt - $this->clock->now()->getTimestamp()),
            gmdate('D, d M Y H:i:s \G\M\T', $expiresAt),
            $sameSite,
            $secure ? '; Secure' : '',
        );
    }

    public function name(): string
    {
        return (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
    }
}
