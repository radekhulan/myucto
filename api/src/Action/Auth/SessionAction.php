<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasskeyCounterAnomalyException;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeySessionTransitionService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockPreferenceService;
use MyInvoice\Service\Auth\SessionLockResult;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SessionAction
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SessionLockService $locks,
        private readonly SessionLockPolicy $policy,
        private readonly SessionLockPreferenceService $preferences,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly PasskeyService $passkeys,
        private readonly WebAuthnCeremonyStore $ceremonies,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClockInterface $clock,
        private readonly BruteForceGuard $bruteForce,
        private readonly SessionCookieFactory $sessionCookies,
        private readonly PasskeySessionTransitionService $sessionTransitions,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        $result = $this->requestLockResult($request, $context);
        if (!$result->sessionExists) {
            return $this->expired($response);
        }

        return Json::ok($response, $this->state($context, $result));
    }

    public function activity(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        if ((array) ($request->getParsedBody() ?? []) !== []) {
            return Json::error(
                $response,
                'validation_failed',
                'Activity heartbeat nepřijímá klientský stav ani čas.',
                400,
            );
        }
        $result = $this->locks->recordActivity($context['token']);
        if (!$result->sessionExists) {
            return $this->expired($response);
        }
        if ($result->locked) {
            return Json::error($response, 'session_locked', 'Session je zamčená.', 423);
        }

        return Json::ok($response, $this->state($context, $result));
    }

    public function lock(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        if (!$this->passkeys->isAvailable()
            || $this->credentials->countActiveForUser($context['user_id']) === 0
        ) {
            return $this->manualLockUnavailable($response);
        }
        try {
            $result = $this->locks->lockManually($context['token']);
        } catch (\DomainException) {
            return $this->manualLockUnavailable($response);
        }
        if (!$result->sessionExists) {
            return $this->expired($response);
        }
        if ($result->transitioned) {
            $this->audit($request, 'auth.session_locked', $context['user_id'], [
                'reason' => 'manual',
            ]);
        }

        return Json::ok($response, $this->state($context, $result));
    }

    public function lockPreference(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }

        try {
            return Json::ok($response, $this->preferences->get($context['user_id']));
        } catch (\DomainException) {
            return $this->expired($response);
        }
    }

    public function updateLockPreference(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        $body = $request->getParsedBody();
        if (!is_array($body)
            || !array_key_exists('lock_after_minutes', $body)
            || array_keys($body) !== ['lock_after_minutes']
            || ($body['lock_after_minutes'] !== null && !is_int($body['lock_after_minutes']))
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'lock_after_minutes musí být celé číslo minut nebo null pro nastavení správce.',
                400,
            );
        }

        try {
            $before = $this->preferences->get($context['user_id']);
            $preference = $this->preferences->update(
                $context['user_id'],
                $body['lock_after_minutes'],
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\DomainException) {
            return $this->expired($response);
        }

        $context['user']['session_lock_after_minutes'] = $preference['user_lock_after_minutes'];
        $lock = $this->locks->evaluateForRequest(
            $context['token'],
            $context['session'],
            $preference['user_lock_after_minutes'],
        );
        if (!$lock->sessionExists) {
            return $this->expired($response);
        }
        if ($lock->transitioned) {
            $this->audit($request, 'auth.session_locked', $context['user_id'], [
                'reason' => $lock->reason,
            ]);
        }
        if ($before['user_lock_after_minutes'] !== $preference['user_lock_after_minutes']) {
            $this->audit($request, 'auth.session_lock_preference_changed', $context['user_id'], [
                'lock_after_minutes' => $preference['user_lock_after_minutes'],
                'effective_lock_after_minutes' => $preference['effective_lock_after_minutes'],
            ]);
        }

        return Json::ok($response, [
            ...$preference,
            'session' => $this->state($context, $lock),
        ]);
    }

    public function unlockOptions(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        $lock = $this->requestLockResult($request, $context);
        if (!$lock->sessionExists) {
            return $this->expired($response);
        }
        if (!$lock->locked) {
            return Json::error($response, 'session_not_locked', 'Session není zamčená.', 409);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        $stored = $this->credentials->findAllForUser($context['user_id']);
        if ($stored === []) {
            return Json::error(
                $response,
                'passkey_unavailable',
                'Pro tuto session není dostupná passkey k odemčení.',
                409,
            );
        }

        $challenge = random_bytes(32);
        $options = $this->passkeys->assertionOptions(
            array_map(
                static fn (StoredPasskeyCredential $credential) => $credential->record,
                $stored,
            ),
            $challenge,
        );
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_UNLOCK,
            $context['user_id'],
            $context['token'],
            null,
            $challenge,
            $options,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, [
            'flow_token' => $flowToken,
            'public_key' => $options,
        ]);
    }

    public function unlockVerify(Request $request, Response $response): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->expired($response);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $payload = $body['credential'] ?? null;
        if ($this->bruteForce->isPasskeyLocked($context['user_id'])) {
            return Json::error(
                $response,
                'too_many_attempts',
                'Příliš mnoho pokusů s passkey. Zkus to později.',
                429,
            );
        }

        try {
            $completed = $this->sessionTransitions->completeUnlock(
                trim((string) ($body['flow_token'] ?? '')),
                is_array($payload) ? $payload : [],
                $context['user_id'],
                $context['token'],
            );
            $rotated = $completed['session'];
            $stored = $completed['credential'];
        } catch (PasskeyCounterAnomalyException $e) {
            $this->audit($request, 'auth.passkey_counter_anomaly', $context['user_id'], [
                'credential_id' => $e->credentialId,
            ]);
            return $this->unlockFailed($request, $response, $context['user_id']);
        } catch (OneTimeTokenException|PasskeyVerificationException|\DomainException) {
            return $this->unlockFailed($request, $response, $context['user_id']);
        }
        $this->bruteForce->recordPasskeySuccess($context['user_id']);

        $newSession = $this->sessions->load($rotated['token']);
        if ($newSession === null) {
            throw new \RuntimeException('Novou generaci session nelze načíst.');
        }
        $newContext = [
            'token' => $rotated['token'],
            'user_id' => $context['user_id'],
            'user' => $context['user'],
            'session' => $newSession,
        ];
        $state = $this->locks->evaluateForRequest(
            $rotated['token'],
            $newSession,
            ($context['user']['session_lock_after_minutes'] ?? null) !== null
                ? (int) $context['user']['session_lock_after_minutes']
                : null,
        );
        $this->audit($request, 'auth.session_unlocked', $context['user_id'], [
            'method' => 'passkey',
            'credential_id' => $stored->id,
        ]);

        return Json::ok($response, $this->state($newContext, $state))
            ->withHeader('Set-Cookie', $this->sessionCookies->create(
                $rotated['token'],
                $rotated['expires_at'],
            ));
    }

    /**
     * @return array{token:string,user_id:int,user:array<string,mixed>,session:array<string,mixed>}|null
     */
    private function context(Request $request): ?array
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return null;
        }
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if ($token === '' || (int) ($user['id'] ?? 0) < 1 || !is_array($session)) {
            return null;
        }
        return [
            'token' => $token,
            'user_id' => (int) $user['id'],
            'user' => $user,
            'session' => $session,
        ];
    }

    /**
     * @param array{token:string,user_id:int,user:array<string,mixed>,session:array<string,mixed>} $context
     */
    private function requestLockResult(Request $request, array $context): SessionLockResult
    {
        $result = $request->getAttribute(SessionLockMiddleware::ATTR_RESULT);
        if ($result instanceof SessionLockResult) {
            return $result;
        }

        $userTimeout = ($context['user']['session_lock_after_minutes'] ?? null) !== null
            ? (int) $context['user']['session_lock_after_minutes']
            : null;
        return $this->locks->evaluateForRequest(
            $context['token'],
            $context['session'],
            $userTimeout,
        );
    }

    /**
     * @param array{token:string,user_id:int,user:array<string,mixed>,session:array<string,mixed>} $context
     * @return array<string,mixed>
     */
    private function state(array $context, SessionLockResult $lock): array
    {
        $userTimeout = ($context['user']['session_lock_after_minutes'] ?? null) !== null
            ? (int) $context['user']['session_lock_after_minutes']
            : null;
        $effectiveTimeout = $this->policy->effectiveTimeoutMinutes($userTimeout);
        $unlockMethods = $this->passkeys->isAvailable()
            && $this->credentials->countActiveForUser($context['user_id']) > 0
            ? ['passkey']
            : [];
        return [
            'session_state' => $lock->locked ? 'locked' : 'active',
            'csrf_token' => (string) ($context['session']['csrf_token'] ?? ''),
            'server_time' => $this->isoUtc($lock->evaluatedAt ?? $this->clock->now()),
            'idle_expires_at' => ($idle = $lock->idleExpiresAt($effectiveTimeout)) !== null
                ? $this->isoUtc($idle)
                : null,
            'lock_after_minutes' => $effectiveTimeout,
            'unlock_methods' => $unlockMethods,
            'user' => [
                'id' => $context['user_id'],
                'email' => (string) ($context['user']['email'] ?? ''),
                'name' => (string) ($context['user']['name'] ?? ''),
                // Stejný tvar jako /api/auth/me. Klient tenhle objekt při zamčené
                // session ukládá do auth storu jako plnohodnotného uživatele
                // (stores/auth.ts), takže `role` musí být souhrn role, ne string —
                // jinak `isClientRole` i `isSuperadmin` tiše propadnou na false.
                'role' => (array) ($context['user']['role_summary'] ?? []),
                'is_superadmin' => (bool) ($context['user']['is_superadmin'] ?? false),
                'locale' => (string) ($context['user']['locale'] ?? 'cs'),
                'totp_enabled' => (bool) ($context['user']['totp_enabled'] ?? false),
            ],
        ];
    }

    private function expired(Response $response): Response
    {
        return Json::error($response, 'session_expired', 'Session vypršela.', 401);
    }

    private function passkeysUnavailable(Response $response): Response
    {
        return Json::error(
            $response,
            'passkeys_unavailable',
            'Passkeys nejsou kvůli konfiguraci této instalace dostupné.',
            503,
        );
    }

    private function manualLockUnavailable(Response $response): Response
    {
        return Json::error(
            $response,
            'session_unlock_unavailable',
            'Ruční zámek vyžaduje alespoň jednu aktivní a dostupnou passkey.',
            409,
        );
    }

    private function unlockFailed(Request $request, Response $response, int $userId): Response
    {
        $this->bruteForce->recordPasskeyFailure($userId);
        $this->audit($request, 'auth.session_unlock_failed', $userId, null);
        return Json::error($response, 'session_unlock_failed', 'Session se nepodařilo odemknout.', 401);
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function audit(Request $request, string $action, int $userId, ?array $payload): void
    {
        $this->logger->log(
            $action,
            $userId,
            'user',
            $userId,
            $payload,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        );
    }

    private function clientIp(Request $request): string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }

    private function isoUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
