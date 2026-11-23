<?php

declare(strict_types=1);

namespace MyInvoice\Service\Demo;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedModeGuard;

/**
 * Jediná odpověď na otázku „běží tahle instalace jako veřejná ukázka?".
 *
 * Middleware {@see \MyInvoice\Middleware\DemoReadOnlyMiddleware} blokuje zápisové
 * METODY, jenže část aplikace zapisuje i při čtení: archiv stažených daňových XML,
 * vestavěné vzory importu nebo výchozí číselník mzdových složek se doplňují při GET.
 * Služby, které takové doplnění dělají, se ptají sem, ne na atribut požadavku — ten
 * by se musel protahovat přes repozitáře, které o HTTP nic nevědí.
 *
 * Zámek spravované instalace platí stejně jako u middleware: spravovaná zákaznická
 * instalace demo nikdy není, ať je v konfiguraci cokoli.
 */
final class DemoMode
{
    /**
     * E-maily účtů, kterým ukázka vydá API token přihlášením heslem
     * (`POST /api/v1/auth/password-token`), typicky účet pro review mobilní aplikace.
     * Prázdný seznam (výchozí) = výjimka vypnutá, ukázka token heslem nevydá nikomu.
     */
    public const KEY_PASSWORD_TOKEN_EMAILS = 'demo.password_token_emails';

    public function __construct(private readonly Config $config) {}

    public function enabled(): bool
    {
        return (new ManagedModeGuard($this->config))->effectiveFlag(
            ManagedModeGuard::KEY_DEMO_ENABLED,
            (bool) $this->config->get('demo.enabled', false),
        );
    }

    /**
     * Seznam z konfigurace normalizovaný na malá písmena bez okrajových mezer.
     * Přijme pole i řetězec oddělený čárkami.
     *
     * @return list<string>
     */
    public function passwordTokenEmails(): array
    {
        $value = $this->config->get(self::KEY_PASSWORD_TOKEN_EMAILS, []);
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $emails = [];
        foreach ($items as $item) {
            $email = mb_strtolower(trim((string) $item));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        return array_values(array_unique($emails));
    }

    public function allowsPasswordToken(string $email): bool
    {
        return in_array(mb_strtolower(trim($email)), $this->passwordTokenEmails(), true);
    }
}
