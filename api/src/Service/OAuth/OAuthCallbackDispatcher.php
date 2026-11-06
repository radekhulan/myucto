<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Společný návrat z autorizačního serveru: podle hashe state najde rozpracované
 * přihlášení přihlášeného uživatele a předá ho handleru jeho domény.
 *
 * Firma se bere z přihlášení, ne z aktuálně zvolené firmy v prohlížeči: návrat
 * přesměrováním hlavičku firmy nenese. O oprávnění proto rozhoduje handler nad
 * požadavkem přepnutým do firmy přihlášení ({@see CrossSupplierAuthorization::scopedRequest()}),
 * včetně členství uživatele ve firmě.
 */
final class OAuthCallbackDispatcher
{
    private const AUTHORIZATION_ERRORS = ['access_denied', 'invalid_request', 'unauthorized_client',
        'unsupported_response_type', 'invalid_scope', 'server_error', 'temporarily_unavailable'];

    public function __construct(
        private readonly OAuthClientFlow $flow,
        private readonly OAuthCallbackRegistry $handlers,
        private readonly CrossSupplierAuthorization $suppliers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string|null $domain   alias konkrétní služby přijme jen přihlášení své domény
     * @param string|null $provider … a svého poskytovatele
     * @return string|null adresa přesměrování; null = state nepatří žádnému přihlášení
     *                     přihlášeného uživatele (případně v dané doméně a u poskytovatele)
     */
    public function dispatch(Request $request, ?string $domain = null, ?string $provider = null): ?string
    {
        $query = $request->getQueryParams();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $session = $userId > 0 ? $this->flow->find($state) : null;
        if ($session === null || $session['user_id'] !== $userId
            || ($domain !== null && $session['domain'] !== $domain) || ($provider !== null && $session['provider'] !== $provider)) {
            $this->log('unmatched', $query, $session);
            return null;
        }
        $handler = $this->handlers->get($session['domain']);
        if ($handler === null) {
            $this->log('no_handler', $query, $session);
            return null;
        }
        $scoped = $this->suppliers->scopedRequest($request, $session['supplier_id']);
        // Napojení na externí služby jsou interní správa firmy; klientský portál je nedokončí
        // ani tehdy, kdyby mu role omylem dala právo, které handler žádá.
        if ($scoped === null || RequestAuthorization::isClientType($scoped) || !$handler->allows($scoped)) {
            $this->log('forbidden', $query, $session);
            return $handler->failureLocation($session, 'forbidden');
        }
        if ($session['status'] !== 'pending' || $session['expired']) {
            $this->log('state_used_or_expired', $query, $session);
            return $handler->failureLocation($session, OAuthException::INVALID_STATE);
        }
        $error = $query['error'] ?? null;
        return $handler->complete(new OAuthCallback(
            $session,
            $state,
            is_string($query['code'] ?? null) ? $query['code'] : '',
            $error === null ? null : (in_array($error, self::AUTHORIZATION_ERRORS, true) ? $error : 'unspecified'),
            $query,
            $scoped,
        ));
    }

    /** Hodnoty state ani kódu se nelogují. */
    private function log(string $reason, array $query, ?array $session): void
    {
        $this->logger->warning('oauth_callback_rejected', [
            'reason'   => $reason,
            'domain'   => $session['domain'] ?? null,
            'provider' => $session['provider'] ?? null,
            'has_code' => is_string($query['code'] ?? null) && $query['code'] !== '',
            'has_state' => is_string($query['state'] ?? null) && $query['state'] !== '',
        ]);
    }
}
