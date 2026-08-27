<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\LastMfaFactorException;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeyCounterAnomalyException;
use MyInvoice\Service\Auth\PasskeySessionTransitionService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;

final class PasskeyAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly PasskeyService $passkeys,
        private readonly WebAuthnCeremonyStore $ceremonies,
        private readonly PasswordHasher $passwords,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoginSessionIssuer $loginIssuer,
        private readonly ClockInterface $clock,
        private readonly MfaStepUpService $stepUp,
        private readonly SessionCookieFactory $sessionCookies,
        private readonly BruteForceGuard $bruteForce,
        private readonly PasskeySessionTransitionService $sessionTransitions,
        private readonly MfaProtectedOperationService $protectedOperations,
        private readonly MfaRecoveryCodeService $recoveryCodes,
    ) {}

    public function credentials(Request $request, Response $response): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }
        $items = array_map(
            static fn (StoredPasskeyCredential $credential): array => [
                'id' => $credential->id,
                'label' => $credential->label,
                'transports' => $credential->record->transports,
                'backup_eligible' => $credential->record->backupEligible,
                'backup_state' => $credential->record->backupStatus,
                'created_at' => $credential->createdAt,
                'last_used_at' => $credential->lastUsedAt,
            ],
            $this->credentials->findAllForUser($context['user_id']),
        );

        return Json::ok($response, ['credentials' => $items]);
    }

    public function registerOptions(Request $request, Response $response): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        if (!$this->mfaPolicy->isMethodAllowed('passkey')) {
            return Json::error(
                $response,
                'mfa_method_not_allowed',
                'Registrace passkey není v této instalaci povolená.',
                403,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $user = $this->freshUser($context['user_id']);
        if ($user === null) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $hasStrongFactor = (int) $user['totp_enabled'] === 1
            || $this->credentials->countActiveForUser($context['user_id']) > 0;
        $requireNoActivePasskey = false;
        if ($hasStrongFactor) {
            try {
                $proof = $this->stepUp->consume(
                    trim((string) ($body['step_up_token'] ?? '')),
                    $context['user_id'],
                    $context['session_token'],
                    MfaStepUpService::OPERATION_PASSKEY_REGISTER,
                );
                $requireNoActivePasskey = !$this->mfaPolicy->isMethodAllowed(
                    $proof->authMethod,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return Json::error(
                    $response,
                    'step_up_proof_invalid',
                    'Je vyžadováno nové ověření silným faktorem.',
                    403,
                );
            }
        } elseif (!$this->passwords->verify(
            (string) ($body['current_password'] ?? ''),
            (string) $user['password_hash'],
        )) {
            return Json::error(
                $response,
                'current_password_invalid',
                'Aktuální heslo není správné.',
                403,
            );
        } else {
            $requireNoActivePasskey = true;
        }

        $stored = $this->credentials->findAllForUser($context['user_id']);
        $challenge = random_bytes(32);
        $options = $this->passkeys->registrationOptions(
            (string) $user['email'],
            trim((string) $user['name']) !== '' ? (string) $user['name'] : (string) $user['email'],
            $this->credentials->userHandle($context['user_id']),
            array_map(
                static fn (StoredPasskeyCredential $credential) => $credential->record,
                $stored,
            ),
            $challenge,
        );
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $context['user_id'],
            $context['session_token'],
            $requireNoActivePasskey
                ? WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY
                : null,
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

    public function registerVerify(Request $request, Response $response): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        if (!$this->mfaPolicy->isMethodAllowed('passkey')) {
            return Json::error(
                $response,
                'mfa_method_not_allowed',
                'Registrace passkey není v této instalaci povolená.',
                403,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $credentialPayload = $body['credential'] ?? null;

        try {
            $ceremony = $this->ceremonies->consumeRegistration(
                trim((string) ($body['flow_token'] ?? '')),
                $context['user_id'],
                $context['session_token'],
            );
            if (!is_array($credentialPayload)) {
                throw new PasskeyVerificationException('Chybí WebAuthn credential.');
            }
            if ($this->freshUser($context['user_id']) === null) {
                return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
            }
            $record = $this->passkeys->verifyRegistration($credentialPayload, $ceremony->options);
            $credentialId = $this->credentials->save(
                $context['user_id'],
                $record,
                (string) ($body['label'] ?? ''),
                $ceremony->operation
                    === WebAuthnCeremonyStore::REGISTRATION_CONSTRAINT_NO_ACTIVE_PASSKEY,
            );
        } catch (OneTimeTokenException|PasskeyVerificationException) {
            return Json::error(
                $response,
                'passkey_verification_failed',
                'Registraci passkey se nepodařilo ověřit.',
                400,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $credential = $this->credentials->findActiveForUserById($context['user_id'], $credentialId);
        $this->log(
            $request,
            'auth.passkey_registered',
            $context['user_id'],
            $credentialId,
            ['credential_id' => $credentialId, 'label' => $credential?->label],
        );

        $payload = [
            'credential' => $credential !== null ? self::publicCredential($credential) : null,
        ];

        // ⚠️ První sada záložních kódů patří ke KAŽDÉMU způsobu, jak si uživatel
        // zapne druhý faktor — ne jen k TOTP. Dokud to uměl jen TOTP, prošel
        // uživatel, který si zvolil passkey, celým prvním přihlášením a skončil
        // na nástěnce bez jediného kódu; kdyby o klíč přišel, zbýval zásah na
        // serveru. Existující sadu to nepřepisuje ani nespadne, viz
        // {@see MfaRecoveryCodeService::issueFirstBatch()}.
        $codes = $this->recoveryCodes->issueFirstBatch($context['user_id']);
        if ($codes !== []) {
            $payload['recovery_codes'] = $codes;
            $this->log($request, 'auth.recovery_codes_generated', $context['user_id'], $credentialId, ['reason' => 'passkey_registered']);
        }

        if ($context['assurance_level'] !== 'setup') {
            return Json::ok($response, $payload, 201);
        }

        try {
            $session = $this->sessions->completeSetup(
                $context['user_id'],
                $context['session_token'],
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                SessionAuthContext::strong('passkey', $this->clock->now(), $credentialId),
            );
        } catch (\DomainException) {
            return Json::error($response, 'session_expired', 'Setup session vypršela.', 401);
        }

        $payload['csrf_token'] = $session['csrf_token'];
        $payload['session_state'] = 'active';
        $payload['must_setup_mfa'] = false;
        return Json::ok($response, $payload, 201)
            ->withHeader('Set-Cookie', $this->sessionCookies->create(
                $session['token'],
                $session['expires_at'],
            ));
    }

    public function loginVerify(Request $request, Response $response): Response
    {
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $credentialPayload = $body['credential'] ?? null;
        $credentialPayload = is_array($credentialPayload) ? $credentialPayload : [];
        $flowToken = trim((string) ($body['flow_token'] ?? ''));
        $loginContext = $this->ceremonies->peekLoginContext($flowToken);
        $discoverable = ($loginContext['purpose'] ?? null)
            === WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN;
        $candidateUserId = $discoverable
            ? $this->sessionTransitions->discoverableLoginUserId($credentialPayload)
            : ($loginContext['user_id'] ?? null);
        if ($candidateUserId !== null && $this->bruteForce->isPasskeyLocked($candidateUserId)) {
            return $this->passkeyRateLimited($response);
        }
        try {
            $completed = $discoverable
                ? $this->sessionTransitions->completeDiscoverableLogin(
                    $flowToken,
                    $credentialPayload,
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                    $candidateUserId,
                )
                : $this->sessionTransitions->completeLogin(
                    $flowToken,
                    $credentialPayload,
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                    (int) ($candidateUserId ?? 0),
                );
            $verifiedUserId = (int) $completed['user']['id'];
            $this->bruteForce->recordPasskeySuccess($verifiedUserId);
            return $this->loginIssuer->issuePrepared(
                $response,
                $completed['user'],
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                $completed['auth_context'],
                $completed['session'],
            );
        } catch (PasskeyCounterAnomalyException $e) {
            if ($candidateUserId !== null) {
                $this->log(
                    $request,
                    'auth.passkey_counter_anomaly',
                    $candidateUserId,
                    $e->credentialId,
                    ['credential_id' => $e->credentialId],
                );
                $this->bruteForce->recordPasskeyFailure($candidateUserId);
            }
            return $this->loginVerificationFailed($response);
        } catch (OneTimeTokenException|PasskeyVerificationException) {
            if ($candidateUserId !== null) {
                $this->bruteForce->recordPasskeyFailure($candidateUserId);
                $this->logger->log(
                    'auth.login_failed',
                    $candidateUserId,
                    'user',
                    $candidateUserId,
                    ['reason' => 'passkey_invalid'],
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                );
            }
            return $this->loginVerificationFailed($response);
        }
    }

    /**
     * @param array<string,string> $args
     */
    public function rename(Request $request, Response $response, array $args): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }

        $credentialId = (int) ($args['id'] ?? 0);
        $credential = $this->credentials->findActiveForUserById($context['user_id'], $credentialId);
        if ($credential === null) {
            return $this->credentialOperationFailed($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            if (!$this->credentials->rename(
                $context['user_id'],
                $credentialId,
                (string) ($body['label'] ?? ''),
            )) {
                return $this->credentialOperationFailed($response);
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $updated = $this->credentials->findActiveForUserById($context['user_id'], $credentialId);
        if ($updated === null) {
            return $this->credentialOperationFailed($response);
        }
        $this->log(
            $request,
            'auth.passkey_renamed',
            $context['user_id'],
            $credentialId,
            ['credential_id' => $credentialId, 'label' => $updated->label],
        );

        return Json::ok($response, ['credential' => self::publicCredential($updated)]);
    }

    /**
     * @param array<string,string> $args
     */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }

        $credentialId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $credential = $this->protectedOperations->revokePasskey(
                $context['user_id'],
                $context['session_token'],
                $credentialId,
                trim((string) ($body['step_up_token'] ?? '')),
            );
        } catch (OneTimeTokenException|StepUpOperationException) {
            return Json::error(
                $response,
                'step_up_proof_invalid',
                'Je vyžadováno nové ověření silným faktorem.',
                403,
            );
        } catch (LastMfaFactorException) {
            return Json::error(
                $response,
                'last_mfa_factor',
                'Při povinném MFA nelze odebrat poslední povolený silný faktor.',
                409,
            );
        } catch (\DomainException) {
            return $this->credentialOperationFailed($response);
        }

        $this->log(
            $request,
            'auth.passkey_revoked',
            $context['user_id'],
            $credentialId,
            ['credential_id' => $credentialId, 'label' => $credential->label],
        );

        return Json::ok($response, ['revoked' => true]);
    }

    public function stepUpOptions(Request $request, Response $response): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $operation = $this->stepUp->assertAllowed(
                $context['user_id'],
                (string) ($body['operation'] ?? ''),
                'passkey',
            );
        } catch (StepUpOperationException) {
            return Json::error($response, 'step_up_operation_invalid', 'Nepovolený účel ověření.', 400);
        }

        $stored = $this->credentials->findAllForUser($context['user_id']);
        if ($stored === []) {
            return Json::error($response, 'passkey_unavailable', 'Uživatel nemá aktivní passkey.', 409);
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
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $context['user_id'],
            $context['session_token'],
            $operation,
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

    public function stepUpVerify(Request $request, Response $response): Response
    {
        $context = $this->sessionContext($request);
        if ($context === null) {
            return $this->sessionRequired($response);
        }
        if (!$this->passkeys->isAvailable()) {
            return $this->passkeysUnavailable($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $credentialPayload = $body['credential'] ?? null;
        $operation = trim((string) ($body['operation'] ?? ''));
        if ($this->bruteForce->isPasskeyLocked($context['user_id'])) {
            return $this->passkeyRateLimited($response);
        }

        try {
            $completed = $this->sessionTransitions->completeStepUp(
                trim((string) ($body['flow_token'] ?? '')),
                is_array($credentialPayload) ? $credentialPayload : [],
                $context['user_id'],
                $context['session_token'],
                $operation,
            );
            $stored = $completed['credential'];
            $operation = $completed['operation'];
            $proofToken = $completed['proof_token'];
            $this->bruteForce->recordPasskeySuccess($context['user_id']);
        } catch (PasskeyCounterAnomalyException $e) {
            $this->log(
                $request,
                'auth.passkey_counter_anomaly',
                $context['user_id'],
                $e->credentialId,
                ['credential_id' => $e->credentialId],
            );
            $this->bruteForce->recordPasskeyFailure($context['user_id']);
            return $this->stepUpVerificationFailed($response);
        } catch (OneTimeTokenException|PasskeyVerificationException|StepUpOperationException) {
            $this->bruteForce->recordPasskeyFailure($context['user_id']);
            return $this->stepUpVerificationFailed($response);
        }

        $this->log(
            $request,
            'auth.passkey_step_up',
            $context['user_id'],
            $stored->id,
            ['credential_id' => $stored->id, 'operation' => $operation],
        );
        return Json::ok($response, ['step_up_token' => $proofToken]);
    }

    /**
     * @return array{user_id:int,session_token:string,assurance_level:string}|null
     */
    private function sessionContext(Request $request): ?array
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return null;
        }
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (!is_array($user)
            || (int) ($user['id'] ?? 0) < 1
            || $token === ''
            || !is_array($session)
        ) {
            return null;
        }

        return [
            'user_id' => (int) $user['id'],
            'session_token' => $token,
            'assurance_level' => (string) ($session['assurance_level'] ?? 'legacy'),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function freshUser(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, password_hash, totp_enabled,
                    session_lock_after_minutes
               FROM users
              WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function publicCredential(StoredPasskeyCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'label' => $credential->label,
            'transports' => $credential->record->transports,
            'backup_eligible' => $credential->record->backupEligible,
            'backup_state' => $credential->record->backupStatus,
            'created_at' => $credential->createdAt,
            'last_used_at' => $credential->lastUsedAt,
        ];
    }

    private function sessionRequired(Response $response): Response
    {
        return Json::error(
            $response,
            'session_required',
            'Tento endpoint je dostupný pouze z přihlášené webové session.',
            403,
        );
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

    private function credentialOperationFailed(Response $response): Response
    {
        return Json::error(
            $response,
            'passkey_operation_failed',
            'Operaci s passkey nelze provést.',
            404,
        );
    }

    private function loginVerificationFailed(Response $response): Response
    {
        return Json::error(
            $response,
            'passkey_verification_failed',
            'Ověření passkey selhalo.',
            401,
        );
    }

    private function stepUpVerificationFailed(Response $response): Response
    {
        return Json::error(
            $response,
            'step_up_verification_failed',
            'Step-up ověření selhalo.',
            401,
        );
    }

    private function passkeyRateLimited(Response $response): Response
    {
        return Json::error(
            $response,
            'too_many_attempts',
            'Příliš mnoho pokusů s passkey. Zkus to později.',
            429,
        );
    }

    private function clientIp(Request $request): string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(
        Request $request,
        string $action,
        int $userId,
        int $credentialId,
        array $payload,
    ): void {
        $this->logger->log(
            $action,
            $userId,
            'webauthn_credential',
            $credentialId,
            $payload,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
        );
    }
}
