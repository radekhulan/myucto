<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Mail\Mailer;

/**
 * Vystaví pozvánku k propojení účtu s poskytovatelem identity a pošle ji
 * uživateli e-mailem. Sdílí ji správa identit uživatele, zakládání uživatele
 * při vynuceném poskytovateli i nástroj `api/bin/oidc-invite.php`.
 */
class OidcInviteMailer
{
    public function __construct(
        private readonly OidcInviteService $invites,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {}

    /** Vystaví pozvánku a vrátí odkaz, kterým se uživatel přihlásí u poskytovatele. */
    public function issueLink(int $userId, OidcProvider $provider, ?int $createdBy): string
    {
        $token = $this->invites->issue($userId, $provider->id, $createdBy);
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        return $appUrl . '/api/auth/oidc/' . $provider->id . '/login?invite=' . $token;
    }

    /**
     * Vystaví pozvánku a pošle ji e-mailem. Vrací odpověď SMTP serveru,
     * chyba odeslání letí ven výjimkou.
     */
    public function send(
        int $userId,
        string $email,
        string $name,
        string $locale,
        OidcProvider $provider,
        ?int $createdBy,
    ): string {
        $locale = in_array($locale, ['cs', 'en'], true) ? $locale : 'cs';
        return $this->mailer->sendTemplate('user_sso_invite', $locale, [$email], [
            'name' => $name,
            'provider' => $provider->label,
            'inviteLink' => $this->issueLink($userId, $provider, $createdBy),
            'expiresIn' => $locale === 'en' ? '3 days' : '3 dny',
        ]);
    }
}
