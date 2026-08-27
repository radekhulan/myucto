<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\ActivityLogger;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;

final class LoginSessionIssuer
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionManager $sessions,
        private readonly BruteForceGuard $bruteForce,
        private readonly ActivityLogger $logger,
        private readonly TrustedDeviceService $trustedDevices,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly MfaOfferService $mfaOffers,
        private readonly SessionLockPolicy $lockPolicy,
        private readonly UserRoleProfile $roleProfile,
    ) {}

    /**
     * @param array<string,mixed> $user
     */
    public function issue(
        Response $response,
        array $user,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
        bool $issueTrustedDevice = false,
    ): Response {
        $userId = (int) ($user['id'] ?? 0);
        $email = (string) ($user['email'] ?? '');
        if ($userId < 1 || $email === '') {
            throw new \InvalidArgumentException('Login issuer vyžaduje platného uživatele.');
        }

        $session = $this->sessions->create($userId, $ip, $userAgent, $authContext);

        $this->db->pdo()->prepare(
            'UPDATE users
                SET last_login_at = UTC_TIMESTAMP(6), last_login_ip = ?, last_login_ua = ?
              WHERE id = ?'
        )->execute([
            @inet_pton($ip) ?: null,
            mb_substr($userAgent, 0, 255),
            $userId,
        ]);

        return $this->issuePrepared(
            $response,
            $user,
            $ip,
            $userAgent,
            $authContext,
            $session,
            $issueTrustedDevice,
        );
    }

    /**
     * Dokončí HTTP/audit část loginu pro session vytvořenou v širší atomické
     * transakci (například passkey login).
     *
     * @param array<string,mixed> $user
     * @param array{token:string,csrf_token:string,expires_at:int,issued_at?:\DateTimeImmutable} $session
     */
    public function issuePrepared(
        Response $response,
        array $user,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
        array $session,
        bool $issueTrustedDevice = false,
    ): Response {
        $userId = (int) ($user['id'] ?? 0);
        $email = (string) ($user['email'] ?? '');
        if ($userId < 1
            || $email === ''
            || !isset($session['token'], $session['csrf_token'], $session['expires_at'])
        ) {
            throw new \InvalidArgumentException('Login issuer vyžaduje platného uživatele a session.');
        }

        $this->bruteForce->recordSuccess($email, $ip);
        $this->logger->log(
            'auth.login',
            $userId,
            'user',
            $userId,
            ['email' => $email, 'auth_method' => $authContext->authMethod],
            $ip,
            $userAgent,
        );

        $now = ($session['issued_at'] ?? null) instanceof \DateTimeImmutable
            ? $session['issued_at']
            : $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $totpEnabled = (int) ($user['totp_enabled'] ?? 0) === 1;
        $passkeyCount = $this->credentials->countActiveForUser($userId);
        $mfaMethods = [];
        if ($passkeyCount > 0) {
            $mfaMethods[] = 'passkey';
        }
        if ($totpEnabled) {
            $mfaMethods[] = 'totp';
        }
        $requireTotp = (bool) $this->config->get('auth.require_totp', false);
        // ⚠️ Viz {@see \MyInvoice\Middleware\RequireMfaMiddleware} — o vynucení
        // rozhoduje aktuální politika, ne úroveň zapsaná do session při vydání.
        $mustSetupMfa = $authContext->assuranceLevel === 'setup' && $this->mfaPolicy->isRequired();
        // Musí být i tady, ne jen v MeAction: přihlašovací odpověď je pro frontend
        // první podoba uživatele a chybějící pole by se četlo jako „nenabízet".
        $shouldOfferMfa = $this->mfaOffers->shouldOffer($userId, $mfaMethods !== []);
        $userTimeout = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        $effectiveTimeout = $this->lockPolicy->effectiveTimeoutMinutes($userTimeout);
        $idleExpiresAt = $authContext->assuranceLevel !== 'setup' && $effectiveTimeout > 0
            ? self::isoUtc($now->modify(sprintf('+%d minutes', $effectiveTimeout)))
            : null;
        $cookieSecure = (bool) $this->config->get('session.cookie_secure', true);
        $cookieSameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        $roleProfile = $this->roleProfile->forUser($userId);
        $result = Json::ok($response, [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'name' => (string) ($user['name'] ?? ''),
                // MyÚčto posílá jemnozrnnou roli jako objekt (upstream má prostý string) —
                // frontend na ní staví `role.type` i `role.system_key`.
                'role' => $roleProfile,
                'is_superadmin' => $this->roleProfile->isSuperadmin($userId),
                'locale' => (string) ($user['locale'] ?? 'cs'),
                'totp_enabled' => $totpEnabled,
                'must_setup_totp' => $requireTotp && !$totpEnabled,
                'mfa_enabled' => $mfaMethods !== [],
                'mfa_methods' => $mfaMethods,
                'passkey_count' => $passkeyCount,
                'must_setup_mfa' => $mustSetupMfa,
                'should_offer_mfa' => $shouldOfferMfa,
            ],
            'csrf_token' => $session['csrf_token'],
            'require_totp' => $requireTotp,
            'require_mfa' => $this->mfaPolicy->isRequired(),
            'allowed_mfa_methods' => $this->mfaPolicy->allowedMethods(),
            'session_state' => 'active',
            'lock_after_minutes' => $effectiveTimeout,
            'server_time' => self::isoUtc($now),
            'idle_expires_at' => $idleExpiresAt,
        ])->withHeader(
            'Set-Cookie',
            $this->cookie(
                (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session'),
                $session['token'],
                max(0, $session['expires_at'] - $now->getTimestamp()),
                $cookieSameSite,
                $cookieSecure,
            ),
        );

        if ($issueTrustedDevice) {
            $trustedToken = $this->trustedDevices->issue($userId, $ip, $userAgent);
            $result = $result->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    $this->trustedDevices->cookieName(),
                    $trustedToken,
                    $this->trustedDevices->days() * 86400,
                    $cookieSameSite,
                    $cookieSecure,
                ),
            );
        }

        return $result;
    }

    private function cookie(
        string $name,
        string $value,
        int $maxAge,
        string $sameSite,
        bool $secure,
    ): string {
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Neplatný název auth cookie.');
        }
        if (!in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new \InvalidArgumentException('Neplatná SameSite politika auth cookie.');
        }

        return sprintf(
            '%s=%s; HttpOnly; Path=/; Max-Age=%d; SameSite=%s%s',
            $name,
            $value,
            max(0, $maxAge),
            $sameSite,
            $secure ? '; Secure' : '',
        );
    }

    private static function isoUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
