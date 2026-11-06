<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dokončení přihlášení jedné domény (bank, odometer, …) po návratu na společný callback.
 * {@see OAuthCallbackDispatcher} najde rozpracované přihlášení podle hashe state a přepne
 * požadavek do jeho firmy; handler rozhodne o oprávnění a přihlášení si jednorázově
 * převezme přes {@see OAuthClientFlow::claim()}.
 */
interface OAuthCallbackHandler
{
    public function domain(): string;

    /** Smí uživatel přihlášení dokončit? `$request` je už přepnutý do firmy přihlášení. */
    public function allows(Request $request): bool;

    /** Dokončí přihlášení; vrací adresu, kam přesměrovat prohlížeč. Chybu převede na adresu sám. */
    public function complete(OAuthCallback $callback): string;

    /**
     * Adresa pro neúspěch zjištěný před převzetím (bez oprávnění, vypršelý nebo použitý state).
     *
     * @param array<string,mixed> $session údaje přihlášení bez tajemství
     */
    public function failureLocation(array $session, string $errorCode): string;
}
