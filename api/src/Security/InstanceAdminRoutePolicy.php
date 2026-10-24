<?php

declare(strict_types=1);

namespace MyInvoice\Security;

/** Uzavřený seznam operací technického účtu; platí i před veřejnými/self-service výjimkami. */
final class InstanceAdminRoutePolicy
{
    /** @param array<string,mixed> $user */
    public static function isTechnicalUser(array $user): bool
    {
        return ($user['is_instance_admin'] ?? false) === true
            || ($user['role_summary']['type'] ?? null) === 'instance_admin'
            || ($user['role_summary']['system_key'] ?? null) === 'instance_admin';
    }

    public static function isTechnicalEndpoint(string $method, string $path): bool
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        return match ($method) {
            'GET' => in_array($path, ['/api/instance-admin/status', '/api/instance-admin/suppliers',
                '/api/instance-admin/smtp', '/api/instance-admin/audit'], true)
                || preg_match('#^/api/instance-admin/suppliers/[1-9][0-9]*/(status|smtp)$#D', $path) === 1,
            'PUT' => $path === '/api/instance-admin/smtp',
            'POST' => $path === '/api/instance-admin/smtp/test'
                || preg_match('#^/api/instance-admin/suppliers/[1-9][0-9]*/smtp/test$#D', $path) === 1,
            default => false,
        };
    }

    /** Cesta je již jednou normalizovaná pomocí RequestPath, stejně jako ji vidí router. */
    public static function allows(string $method, string $path): bool
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        if (self::isTechnicalEndpoint($method, $path)) return true;
        if ($method === 'GET') {
            return in_array($path, [
                '/api/version', '/api/csrf-token', '/api/auth/setup-status', '/api/auth/domain-context',
                '/api/auth/me', '/api/auth/totp/status', '/api/auth/webauthn/credentials',
                '/api/auth/mfa/recovery-codes', '/api/auth/session/status', '/api/auth/session/lock-preference',
            ], true);
        }
        if ($method === 'PUT') return $path === '/api/auth/session/lock-preference';
        if (in_array($method, ['PATCH', 'DELETE'], true)) {
            return preg_match('#^/api/auth/webauthn/credentials/[1-9][0-9]*$#D', $path) === 1;
        }
        if ($method !== 'POST') return false;
        return in_array($path, [
            '/api/auth/logout', '/api/auth/change-password', '/api/auth/totp/setup', '/api/auth/totp/enable',
            '/api/auth/webauthn/register/options', '/api/auth/webauthn/register/verify',
            '/api/auth/webauthn/step-up/options', '/api/auth/webauthn/step-up/verify',
            '/api/auth/mfa/step-up/totp', '/api/auth/mfa/step-up/recovery', '/api/auth/mfa/recovery-codes',
            '/api/auth/mfa/offer/dismiss', '/api/auth/session/activity', '/api/auth/session/lock',
            '/api/auth/session/unlock/options', '/api/auth/session/unlock/verify',
        ], true) || preg_match('#^/api/auth/webauthn/credentials/[1-9][0-9]*/test/(options|verify)$#D', $path) === 1;
    }
}
