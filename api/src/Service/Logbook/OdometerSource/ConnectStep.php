<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Další krok připojení zdroje. UI každý typ vykreslí stejně bez ohledu na zdroj:
 *
 *   device_code  uživatel opíše kód na stránce služby (OAuth Device Code Flow),
 *   redirect     uživatel se přihlásí na stránce služby a vrátí se zpět (OAuth),
 *   waiting      přihlášení ještě není potvrzené, zeptat se znovu za `interval` s,
 *   connected    hotovo, `credentials` jsou přístupy k uložení.
 *
 * `pending` a `credentials` jsou tajné; jádro je uloží jen šifrovaně a nikdy nevrací do UI.
 */
final class ConnectStep
{
    public const DEVICE_CODE = 'device_code';
    public const REDIRECT    = 'redirect';
    public const WAITING     = 'waiting';
    public const CONNECTED   = 'connected';

    /**
     * @param array<string,string|int>      $display     údaje pro UI (user_code, url, interval, …)
     * @param array<string,string|int>|null $pending     rozpracované přihlášení (null = beze změny)
     * @param array<string,string|int>|null $credentials přístupy po dokončení
     */
    private function __construct(
        public readonly string $type,
        public readonly array $display = [],
        public readonly ?array $pending = null,
        public readonly ?int $expiresIn = null,
        public readonly ?array $credentials = null,
    ) {}

    /** @param array<string,string|int> $pending */
    public static function deviceCode(string $userCode, string $url, int $interval, int $expiresIn,
        #[\SensitiveParameter] array $pending): self
    {
        return new self(self::DEVICE_CODE, ['user_code' => $userCode, 'url' => $url, 'interval' => $interval,
            'expires_in' => $expiresIn], $pending, $expiresIn);
    }

    /**
     * Návrat ze služby jde na společný callback (`ConnectContext::$redirectUri`), který kód
     * uloží k rozpracovanému přihlášení; zdroj ho dostane v dalším {@see OdometerSourceProvider::continueConnect()}
     * ve vstupu `code` spolu s `code_verifier`.
     *
     * @param array<string,string|int> $pending
     */
    public static function redirect(string $url, int $expiresIn, #[\SensitiveParameter] array $pending): self
    {
        return new self(self::REDIRECT, ['url' => $url, 'expires_in' => $expiresIn], $pending, $expiresIn);
    }

    /** @param array<string,string|int>|null $pending změněné rozpracované přihlášení (např. delší interval) */
    public static function waiting(int $interval, #[\SensitiveParameter] ?array $pending = null): self
    {
        return new self(self::WAITING, ['interval' => $interval], $pending);
    }

    /** @param array<string,string|int> $credentials */
    public static function connected(#[\SensitiveParameter] array $credentials): self
    {
        return new self(self::CONNECTED, credentials: $credentials);
    }
}
