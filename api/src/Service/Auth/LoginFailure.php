<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Neúspěch kroku přihlášení z {@see PasswordLoginVerifier}. Akce z něj skládá
 * odpověď (`Json::error`) a podle potřeby přidá vlastní `extra` (třeba seznam
 * metod druhého faktoru).
 */
final class LoginFailure
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {}

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'Neplatné přihlašovací údaje.', 401);
    }
}
