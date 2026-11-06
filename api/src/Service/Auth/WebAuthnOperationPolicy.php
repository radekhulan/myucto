<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Sémantický inventář HTTP operací vázaných na nakonfigurovaný WebAuthn origin.
 *
 * Záměrně obsahuje i smíšený heslový login: po ověření hesla může založit passkey
 * MFA ceremonii, přestože jeho URL neobsahuje `webauthn`. Vstupní cesta už musí
 * být jednou normalizovaná vnějším middlewarem.
 */
final class WebAuthnOperationPolicy
{
    /**
     * @var array<string,array{
     *     method:string,
     *     method_aliases?:list<string>,
     *     route_pattern:string,
     *     path_pattern:string,
     *     example_path:string
     * }>
     */
    private const OPERATIONS = [
        'login.password_mfa_options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/login',
            'path_pattern' => '#^/api/auth/login$#D',
            'example_path' => '/api/auth/login',
        ],
        'login.passwordless_options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/login/options',
            'path_pattern' => '#^/api/auth/webauthn/login/options$#D',
            'example_path' => '/api/auth/webauthn/login/options',
        ],
        'login.verify' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/login/verify',
            'path_pattern' => '#^/api/auth/webauthn/login/verify$#D',
            'example_path' => '/api/auth/webauthn/login/verify',
        ],
        'credentials.list' => [
            'method' => 'GET',
            // Slim při HEAD automaticky dispatchuje odpovídající GET handler.
            'method_aliases' => ['HEAD'],
            'route_pattern' => '/api/auth/webauthn/credentials',
            'path_pattern' => '#^/api/auth/webauthn/credentials$#D',
            'example_path' => '/api/auth/webauthn/credentials',
        ],
        'registration.options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/register/options',
            'path_pattern' => '#^/api/auth/webauthn/register/options$#D',
            'example_path' => '/api/auth/webauthn/register/options',
        ],
        'registration.verify' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/register/verify',
            'path_pattern' => '#^/api/auth/webauthn/register/verify$#D',
            'example_path' => '/api/auth/webauthn/register/verify',
        ],
        'step_up.options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/step-up/options',
            'path_pattern' => '#^/api/auth/webauthn/step-up/options$#D',
            'example_path' => '/api/auth/webauthn/step-up/options',
        ],
        'step_up.verify' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/step-up/verify',
            'path_pattern' => '#^/api/auth/webauthn/step-up/verify$#D',
            'example_path' => '/api/auth/webauthn/step-up/verify',
        ],
        'credentials.rename' => [
            'method' => 'PATCH',
            'route_pattern' => '/api/auth/webauthn/credentials/{id:[0-9]+}',
            'path_pattern' => '#^/api/auth/webauthn/credentials/[0-9]+$#D',
            'example_path' => '/api/auth/webauthn/credentials/7',
        ],
        'credentials.revoke' => [
            'method' => 'DELETE',
            'route_pattern' => '/api/auth/webauthn/credentials/{id:[0-9]+}',
            'path_pattern' => '#^/api/auth/webauthn/credentials/[0-9]+$#D',
            'example_path' => '/api/auth/webauthn/credentials/7',
        ],
        'credentials.test_options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/credentials/{id:[0-9]+}/test/options',
            'path_pattern' => '#^/api/auth/webauthn/credentials/[0-9]+/test/options$#D',
            'example_path' => '/api/auth/webauthn/credentials/7/test/options',
        ],
        'credentials.test_verify' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/webauthn/credentials/{id:[0-9]+}/test/verify',
            'path_pattern' => '#^/api/auth/webauthn/credentials/[0-9]+/test/verify$#D',
            'example_path' => '/api/auth/webauthn/credentials/7/test/verify',
        ],
        'session_unlock.options' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/session/unlock/options',
            'path_pattern' => '#^/api/auth/session/unlock/options$#D',
            'example_path' => '/api/auth/session/unlock/options',
        ],
        'session_unlock.verify' => [
            'method' => 'POST',
            'route_pattern' => '/api/auth/session/unlock/verify',
            'path_pattern' => '#^/api/auth/session/unlock/verify$#D',
            'example_path' => '/api/auth/session/unlock/verify',
        ],
    ];

    public function requiresCanonicalOrigin(string $method, string $path): bool
    {
        return $this->operationFor($method, $path) !== null;
    }

    public function operationFor(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        foreach (self::OPERATIONS as $name => $operation) {
            $methods = [$operation['method'], ...($operation['method_aliases'] ?? [])];
            if (in_array($method, $methods, true)
                && preg_match($operation['path_pattern'], $path) === 1
            ) {
                return $name;
            }
        }
        return null;
    }

    /**
     * @return array<string,array{
     *     method:string,
     *     method_aliases?:list<string>,
     *     route_pattern:string,
     *     path_pattern:string,
     *     example_path:string
     * }>
     */
    public function inventory(): array
    {
        return self::OPERATIONS;
    }
}
