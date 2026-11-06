<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Návrat z autorizačního serveru předaný handleru domény. `session` jsou údaje
 * přihlášení bez tajemství, `request` je přepnutý do firmy přihlášení.
 */
final class OAuthCallback
{
    /**
     * @param array<string,mixed>  $session
     * @param array<string,mixed>  $query   celý query string návratu (pro neobvyklé parametry poskytovatele)
     */
    public function __construct(
        public readonly array $session,
        #[\SensitiveParameter] public readonly string $state,
        #[\SensitiveParameter] public readonly string $code,
        public readonly ?string $error,
        public readonly array $query,
        public readonly Request $request,
    ) {}

    public function userId(): int
    {
        return (int) $this->session['user_id'];
    }

    public function supplierId(): int
    {
        return (int) $this->session['supplier_id'];
    }
}
