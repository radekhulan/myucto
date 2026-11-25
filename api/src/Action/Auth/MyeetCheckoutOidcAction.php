<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\Oidc\IdTokenVerifier;
use MyInvoice\Service\Auth\Oidc\OidcException;
use MyInvoice\Service\Auth\Oidc\OidcFlowStore;
use MyInvoice\Service\Auth\Oidc\OidcHttpClient;
use MyInvoice\Service\Auth\Oidc\OidcProvider;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MyeetCheckoutOidcAction
{
    private const COOKIE = '__Host-myeet_checkout_oidc';
    private const STATE_PREFIX = 'myeet.';
    private const ROUTE = '/api/public/myeet/auth/';

    public function __construct(
        private readonly Config $config,
        private readonly OidcProviderRegistry $providers,
        private readonly OidcHttpClient $http,
        private readonly OidcFlowStore $flows,
        private readonly IdTokenVerifier $verifier,
        private readonly OidcAction $signIn,
    ) {}

    public function start(Request $request, Response $response, array $args): Response
    {
        $provider = $this->provider($args);
        if ($provider === null || $this->checkoutOrigin() === null) {
            return $this->unavailable($response);
        }

        $query = $request->getQueryParams();
        $challenge = $this->field($query, 'challenge');
        $signature = $this->field($query, 'signature');
        $deadline = $this->field($query, 'expires');
        if (!OidcFlowStore::isBindingShape($challenge) || !ctype_digit($deadline) || strlen($deadline) > 12
            || (int) $deadline < time() || (int) $deadline > time() + 600
            || !hash_equals(
                hash_hmac('sha256', "myeet:checkout:start:v1\n{$provider->id}\n$challenge\n$deadline", $this->secret()),
                $signature,
            )) {
            return Json::error($response, 'myeet_auth_invalid', 'Přihlášení vypršelo.', 400);
        }

        $binding = OidcFlowStore::newBinding();
        $flow = $this->flows->create($provider->id, OidcFlowStore::PURPOSE_LOGIN, $binding, $challenge);
        $url = $this->http->authorizationUrl(
            $provider, $this->callbackUri($provider),
            $this->useSignInCallbacks() ? self::STATE_PREFIX . $flow['state'] : $flow['state'],
            $flow['nonce'], $flow['code_verifier'],
            $provider->id === 'google' ? ['prompt' => 'select_account'] : [],
        );
        return $this->redirect($response, $url)->withHeader('Set-Cookie', $this->cookie($binding, 600));
    }

    public function signInCallback(Request $request, Response $response, array $args): Response
    {
        $state = $this->field($request->getQueryParams(), 'state');
        if (in_array($args['provider'] ?? '', ['google', 'apple'], true)
            && $this->useSignInCallbacks() && str_starts_with($state, self::STATE_PREFIX)) {
            return $this->callback($request, $response, $args);
        }
        return $this->signIn->callback($request, $response, $args);
    }

    public function callback(Request $request, Response $response, array $args): Response
    {
        $provider = $this->provider($args);
        $origin = $this->checkoutOrigin();
        if ($provider === null || $origin === null) {
            return $this->unavailable($response);
        }

        $url = $origin . '/payment/authorized';
        $query = $request->getQueryParams();
        $binding = $request->getCookieParams()[self::COOKIE] ?? null;
        try {
            $state = $this->field($query, 'state');
            if ($this->useSignInCallbacks()) {
                if (!str_starts_with($state, self::STATE_PREFIX)) {
                    throw new OidcException(OidcException::FLOW_INVALID);
                }
                $state = substr($state, strlen(self::STATE_PREFIX));
            }
            $flow = $this->flows->consume($provider->id, $state, is_string($binding) ? $binding : null);
            if ($flow->purpose !== OidcFlowStore::PURPOSE_LOGIN || !OidcFlowStore::isBindingShape($flow->returnTo)
                || $this->field($query, 'error') !== '') {
                throw new OidcException(OidcException::FLOW_INVALID);
            }
            $tokens = $this->http->exchangeCode($provider, $this->field($query, 'code'), $this->callbackUri($provider), $flow->codeVerifier);
            $identity = $this->verifier->verify($provider, (string) $tokens['id_token'], $flow->nonce);
            if (!is_string($identity->email) || strlen($identity->email) > 190 || !filter_var($identity->email, FILTER_VALIDATE_EMAIL)) {
                throw new OidcException(OidcException::IDENTITY_MISMATCH);
            }
            $claims = json_encode([
                'challenge' => $flow->returnTo, 'expires' => time() + 180,
                'provider' => $identity->provider, 'issuer' => $identity->issuer, 'subject' => $identity->subject,
                'email' => $identity->email, 'name' => $identity->name,
            ], JSON_THROW_ON_ERROR);
            $key = hash_hmac('sha256', 'myeet:checkout:identity:v1', $this->secret(), true);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $url .= '?code=' . sodium_bin2base64($nonce . sodium_crypto_secretbox($claims, $nonce, $key), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (OidcException) {
            return $this->redirect($response, $url)->withHeader('Set-Cookie', $this->cookie('', 0));
        }
        return $this->redirect($response, $url)->withHeader('Set-Cookie', $this->cookie('', 0));
    }

    public function appleFormCallback(Request $request, Response $response): Response
    {
        if ($this->checkoutOrigin() === null || $this->providers->get('apple') === null) {
            return $this->unavailable($response);
        }

        $body = (array) $request->getParsedBody();
        $query = [];
        foreach (['state', 'code', 'error'] as $key) {
            $value = $this->field($body, $key);
            if ($value !== '' && strlen($value) <= 8192 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
                $query[$key] = $value;
            }
        }
        return $this->redirect($response, self::ROUTE . 'apple/callback?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function provider(array $args): ?OidcProvider
    {
        $id = $args['provider'] ?? '';
        return in_array($id, ['google', 'apple'], true) ? $this->providers->get($id) : null;
    }

    private function checkoutOrigin(): ?string
    {
        $origin = (string) $this->config->get('myeet.checkout_origin', '');
        return strlen($this->secret()) >= 32 && function_exists('sodium_crypto_secretbox')
            && preg_match('~^https://[a-z0-9][a-z0-9.-]+(?::[0-9]{1,5})?$~Di', $origin) === 1 ? $origin : null;
    }

    private function secret(): string
    {
        return (string) $this->config->get('myeet.checkout_token', '');
    }

    private function useSignInCallbacks(): bool
    {
        return $this->config->get('myeet.use_sign_in_callbacks', false) === true;
    }

    private function callbackUri(OidcProvider $provider): string
    {
        if ($this->useSignInCallbacks()) {
            return $this->providers->redirectUri($provider);
        }
        return rtrim((string) $this->config->get('app.url'), '/') . self::ROUTE . $provider->id . '/callback';
    }

    private function field(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : '';
    }

    private function cookie(string $value, int $maxAge): string
    {
        return self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . $maxAge . '; Secure; HttpOnly; SameSite=Lax';
    }

    private function unavailable(Response $response): Response
    {
        return Json::error($response, 'myeet_auth_unavailable', 'Přihlášení MyEET není dostupné.', 404);
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withStatus(303)->withHeader('Location', $to)
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
