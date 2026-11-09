<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\Oidc\ExternalIdentityRepository;
use MyInvoice\Service\Auth\Oidc\OidcException;
use MyInvoice\Service\Auth\Oidc\OidcFlowStore;
use MyInvoice\Service\Auth\Oidc\OidcLoginService;
use MyInvoice\Service\Auth\ReauthException;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Přihlášení přes poskytovatele identity (OpenID Connect).
 *
 * Zahájení (`/login`) a návrat (`/callback`) jsou GET navigace prohlížeče, ne XHR:
 * CSP `form-action 'self'` by POST formulář s přesměrováním k poskytovateli
 * zablokovala a COOP vylučuje vyskakovací okno. Obojí běží bez session
 * (AuthMiddleware::SESSION_IGNORED_PATHS). Propojení a ověření citlivé operace
 * se zahajují POSTem z přihlášeného sezení a vrací adresu, na kterou frontend
 * přejde; návrat pak sezení ověří sám z cookie.
 *
 * Sezení po přihlášení se nastaví cookie na odpovědi návratu. Vyžaduje to
 * `session.cookie_samesite` Lax (výchozí): se Strict by prohlížeč po návratu
 * od poskytovatele cookie neposlal a propojení ani ověření by nešlo dokončit.
 */
final class OidcAction
{
    private const ERROR_PATH = '/login';

    public function __construct(
        private readonly OidcLoginService $oidc,
        private readonly ExternalIdentityRepository $identities,
        private readonly LoginSessionIssuer $loginIssuer,
        private readonly SessionManager $sessions,
        private readonly SensitiveOperationReauth $reauth,
        private readonly LoginPolicyService $loginPolicy,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly ResponseFactory $responses,
        private readonly OidcFlowStore $flows,
    ) {}

    /**
     * GET /api/auth/oidc/{provider}/login
     *
     * `silent=1` = tiché obnovení po vypršení sezení: poskytovatel uživatele
     * ověří bez dotazu (`prompt=none`). Když to bez uživatele nejde, návrat
     * pokračuje běžným přihlášením (viz callback), ne chybou.
     */
    public function login(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        $returnTo = self::safeReturnTo($query['return_to'] ?? null);
        $invite = is_string($query['invite'] ?? null) ? $query['invite'] : null;
        $hint = is_string($query['login_hint'] ?? null) ? trim($query['login_hint']) : null;
        $silent = ($query['silent'] ?? null) === '1';
        try {
            $provider = $this->oidc->provider((string) ($args['provider'] ?? ''));
            $binding = $this->binding($request);
            $url = $this->oidc->start(
                $provider,
                OidcFlowStore::PURPOSE_LOGIN,
                $binding,
                $returnTo,
                inviteToken: $invite,
                loginHint: $hint,
                silent: $silent,
            );
        } catch (OidcException $e) {
            return $this->redirect(self::ERROR_PATH . '?sso_error=' . $e->errorCode);
        }
        return $this->redirect($url, [$this->bindingCookie($binding)]);
    }

    /** GET /api/auth/oidc/{provider}/callback */
    public function callback(Request $request, Response $response, array $args): Response
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');
        $cookies = $request->getCookieParams();
        $binding = $cookies[$this->bindingCookieName()] ?? null;
        $sessionToken = $cookies[$this->sessionCookieName()] ?? null;
        $sessionToken = is_string($sessionToken) && $sessionToken !== '' ? $sessionToken : null;
        $clearBinding = $this->bindingCookie('', 0);

        try {
            $provider = $this->oidc->provider((string) ($args['provider'] ?? ''));
            $result = $this->oidc->complete(
                $provider,
                $request->getQueryParams(),
                is_string($binding) ? $binding : null,
                $sessionToken,
                $ip,
                $userAgent,
            );
        } catch (OidcException $e) {
            if ($e->interactiveRetry && isset($provider)) {
                // Tiché obnovení potřebuje uživatele: druhý pokus už je běžné
                // přihlášení bez `silent`, takže smyčka nevznikne.
                return $this->redirect(self::interactiveLoginPath($provider->id, $e->returnTo));
            }
            if ($e->purpose !== null && $e->purpose !== OidcFlowStore::PURPOSE_LOGIN) {
                return $this->redirect(($e->returnTo ?? '/') . '#sso_error=' . $e->errorCode, [$clearBinding]);
            }
            return $this->redirect(self::ERROR_PATH . '?sso_error=' . $e->errorCode, [$clearBinding]);
        } catch (StepUpOperationException) {
            return $this->redirect('/#sso_error=' . OidcException::IDENTITY_MISMATCH, [$clearBinding]);
        }

        if ($result->purpose === OidcFlowStore::PURPOSE_LINK) {
            return $this->redirect(($result->returnTo ?? '/') . '#sso_linked=1', [$clearBinding]);
        }
        if ($result->purpose === OidcFlowStore::PURPOSE_STEP_UP) {
            // Ve fragmentu, ne v query: fragment se neposílá na server, takže
            // proof neskončí v přístupových lozích ani v Referer.
            return $this->redirect(
                ($result->returnTo ?? '/') . '#sso_step_up=' . rawurlencode((string) $result->stepUpToken)
                    . '&sso_operation=' . rawurlencode((string) $result->stepUpOperation),
                [$clearBinding],
            );
        }

        // Poskytovatel, který nahrazuje jen heslo: sezení ještě nevzniká. Prohlížeč
        // dostane jednorázový lístek a projde s ním běžné přihlášení, kde se ověří
        // passkey, TOTP nebo vynucené MFA stejně jako po hesle (LoginAction).
        // Lístek je ve fragmentu (do logů se nedostane) a platí jen s vazbou
        // na tento prohlížeč, proto se vazební cookie tady nemaže.
        if (!$provider->isStrong()) {
            $ticket = $this->flows->createTicket(
                $provider->id,
                (string) $binding,
                (int) ($result->user['id'] ?? 0),
                (int) $result->externalIdentityId,
                $result->returnTo,
            );
            $fragment = 'sso_ticket=' . $ticket . '&sso_provider=' . rawurlencode($provider->id);
            if ($result->returnTo !== null) {
                $fragment .= '&return_to=' . rawurlencode($result->returnTo);
            }
            return $this->redirect(self::ERROR_PATH . '#' . $fragment, [$this->bindingCookie((string) $binding)]);
        }

        // Přihlášení nahrazuje sezení, se kterým prohlížeč přišel (zamčené,
        // z hesla, cizího uživatele). Staré se zruší, ať nezůstane viset.
        if ($sessionToken !== null) {
            $this->sessions->destroy($sessionToken);
        }
        $user = (array) $result->user;
        $issued = $this->loginIssuer->issue(
            $this->responses->createResponse(),
            $user,
            $ip,
            $userAgent,
            SessionAuthContext::externalIdentity(
                $provider->id,
                $this->clock->now(),
                (int) $result->externalIdentityId,
            ),
        );
        return $this->redirect($result->returnTo ?? '/', [...$issued->getHeader('Set-Cookie'), $clearBinding]);
    }

    /**
     * POST /api/auth/oidc/apple/callback — Apple vrací výsledek POSTem
     * (`response_mode=form_post`). Cross-site POST nenese cookie se SameSite=Lax,
     * takže vazbu na prohlížeč tady ověřit nejde. Odpověď jen přesměruje na GET
     * návrat se stejnými parametry, kde už cookie přijde a proběhne celé ověření.
     * Kód je jednorázový a bez PKCE verifieru i tajemství klienta nepoužitelný,
     * stejně jako u poskytovatelů, kteří ho posílají přímo v query.
     */
    public function formCallback(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $query = [];
        foreach (['state', 'code', 'error'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 8192
                && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1
            ) {
                $query[$key] = $value;
            }
        }
        $provider = preg_replace('/[^a-z]/', '', (string) ($args['provider'] ?? ''));
        return $this->responses->createResponse(303)
            ->withHeader('Location', '/api/auth/oidc/' . $provider . '/callback?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** POST /api/auth/oidc/{provider}/link — vrací adresu poskytovatele */
    public function link(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Propojení lze zahájit jen z přihlášeného sezení.');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->reauth->verify(
                $request,
                $userId,
                $body,
                'oidc.link',
                MfaStepUpService::OPERATION_OIDC_LINK,
                'auth.oidc_link_failed',
            );
        } catch (ReauthException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return $this->startForSession($request, $response, $args, OidcFlowStore::PURPOSE_LINK, $userId, null, $body);
    }

    /** POST /api/auth/oidc/{provider}/step-up — vrací adresu poskytovatele */
    public function stepUp(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Ověření lze zahájit jen z přihlášeného sezení.');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $operation = trim((string) ($body['operation'] ?? ''));
        if ($operation === '' || strlen($operation) > 190) {
            return Json::error($response, 'invalid_operation', 'Chybí účel ověření.', 422);
        }
        return $this->startForSession($request, $response, $args, OidcFlowStore::PURPOSE_STEP_UP, $userId, $operation, $body);
    }

    /** GET /api/auth/oidc/identities */
    public function identities(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Jen z přihlášeného sezení.');
        }
        return Json::ok($response, [
            'identities' => array_map(
                ExternalIdentityRepository::present(...),
                $this->identities->listForUser($userId),
            ),
        ]);
    }

    /** DELETE /api/auth/oidc/identities/{id} */
    public function unlink(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Jen z přihlášeného sezení.');
        }
        $id = (int) ($args['id'] ?? 0);
        $row = $this->identities->findForUser($userId, $id);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Propojení nenalezeno.', 404);
        }
        if ($this->loginPolicy->requiredProvider() === $row['provider']) {
            return Json::error(
                $response,
                'sso_required',
                'Bez tohoto propojení byste se nepřihlásili. Instalace vyžaduje přihlášení přes poskytovatele identity.',
                409,
            );
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->reauth->verify(
                $request,
                $userId,
                $body,
                'oidc.unlink',
                MfaStepUpService::OPERATION_OIDC_LINK,
                'auth.oidc_link_failed',
            );
        } catch (ReauthException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->identities->unlink($userId, $id);
        $this->activity->log('auth.oidc_unlinked', $userId, 'user', $userId, [
            'provider' => $row['provider'], 'identity_id' => $id, 'by' => 'self',
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

    /** @param array<string,mixed> $body */
    private function startForSession(
        Request $request,
        Response $response,
        array $args,
        string $purpose,
        int $userId,
        ?string $operation,
        array $body,
    ): Response {
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $provider = $this->oidc->provider((string) ($args['provider'] ?? ''));
            $binding = $this->binding($request);
            $url = $this->oidc->start(
                $provider,
                $purpose,
                $binding,
                self::safeReturnTo($body['return_to'] ?? null),
                $userId,
                $sessionToken,
                $operation,
                loginHint: $purpose === OidcFlowStore::PURPOSE_STEP_UP && is_string($user['email'] ?? null)
                    ? (string) $user['email']
                    : null,
            );
        } catch (OidcException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (StepUpOperationException $e) {
            return Json::error($response, 'step_up_operation_invalid', $e->getMessage(), 422);
        }
        return Json::ok($response, ['url' => $url])
            ->withAddedHeader('Set-Cookie', $this->bindingCookie($binding))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function userId(Request $request): ?int
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return null;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Zahájení běžného (interaktivního) přihlášení se stejnou návratovou cestou. */
    public static function interactiveLoginPath(string $providerId, ?string $returnTo): string
    {
        $path = '/api/auth/oidc/' . rawurlencode($providerId) . '/login';
        $returnTo = self::safeReturnTo($returnTo);
        return $returnTo !== null ? $path . '?return_to=' . rawurlencode($returnTo) : $path;
    }

    /**
     * Relativní cesta v aplikaci, nebo null. Absolutní adresy, `//host`
     * a zpětná lomítka by z návratu udělaly otevřené přesměrování.
     */
    public static function safeReturnTo(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 512
            || !str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || str_contains($value, '#')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || str_starts_with($value, '/api/')
        ) {
            return null;
        }
        return $value;
    }

    private function binding(Request $request): string
    {
        // Stávající vazbu prohlížeče použij znovu, ať dvě záložky nepřepíšou
        // jedna druhé rozběhnuté přihlášení.
        $existing = $request->getCookieParams()[$this->bindingCookieName()] ?? null;
        return OidcFlowStore::isBindingShape(is_string($existing) ? $existing : null)
            ? (string) $existing
            : OidcFlowStore::newBinding();
    }

    private function bindingCookieName(): string
    {
        return OidcFlowStore::bindingCookieName($this->sessionCookieName());
    }

    private function sessionCookieName(): string
    {
        return (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
    }

    private function bindingCookie(string $value, int $maxAge = 600): string
    {
        $secure = (bool) $this->config->get('session.cookie_secure', true);
        return sprintf(
            '%s=%s; HttpOnly; Path=/; Max-Age=%d; SameSite=Lax%s',
            $this->bindingCookieName(),
            $value,
            $maxAge,
            $secure ? '; Secure' : '',
        );
    }

    /** @param list<string> $cookies */
    private function redirect(string $location, array $cookies = []): Response
    {
        $response = $this->responses->createResponse(302)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
        foreach ($cookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }
        return $response;
    }
}
