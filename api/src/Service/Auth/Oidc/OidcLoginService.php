<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;

/**
 * Přihlášení, propojení a ověření totožnosti přes poskytovatele identity.
 *
 * Účet v MyÚčto musí existovat předem. Identita se k němu naváže jen třemi
 * cestami: pozvánkou od správce, propojením z profilu přihlášeného uživatele,
 * nebo ručně správcem. Neznámá identita se nikdy nepřiřadí podle e-mailu.
 */
class OidcLoginService
{
    /** Čerstvost přihlášení u poskytovatele pro ověření citlivé operace. */
    private const STEP_UP_MAX_AUTH_AGE = 600;
    private const AUTH_TIME_SKEW = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly OidcProviderRegistry $providers,
        private readonly OidcHttpClient $http,
        private readonly IdTokenVerifier $verifier,
        private readonly OidcFlowStore $flows,
        private readonly ExternalIdentityRepository $identities,
        private readonly OidcInviteService $invites,
        private readonly LoginPolicyService $loginPolicy,
        private readonly MfaStepUpService $stepUp,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $activity,
        private readonly \MyInvoice\Service\Auth\SessionLockService $sessionLocks,
        private readonly \MyInvoice\Service\Auth\SecurityClock $securityClock,
    ) {}

    public function provider(string $id): OidcProvider
    {
        $provider = $this->providers->isEnabled() ? $this->providers->get($id) : null;
        if ($provider === null) {
            throw new OidcException(OidcException::PROVIDER_DISABLED, 'Přihlášení přes tohoto poskytovatele není zapnuté.');
        }
        return $provider;
    }

    /**
     * Zahájí přihlášení a vrátí adresu poskytovatele.
     */
    public function start(
        OidcProvider $provider,
        string $purpose,
        #[\SensitiveParameter] string $browserBinding,
        ?string $returnTo,
        ?int $userId = null,
        #[\SensitiveParameter] ?string $sessionToken = null,
        ?string $stepUpOperation = null,
        #[\SensitiveParameter] ?string $inviteToken = null,
        ?string $loginHint = null,
    ): string {
        $inviteId = null;
        if ($inviteToken !== null && $inviteToken !== '') {
            $inviteId = $this->invites->findValid($inviteToken, $provider->id);
            if ($inviteId === null) {
                throw new OidcException(OidcException::INVITE_INVALID, 'Pozvánka je neplatná, použitá nebo vypršela.');
            }
        }
        if ($purpose === OidcFlowStore::PURPOSE_STEP_UP) {
            // Proof citlivé operace vydá jen poskytovatel, kterému instalace svěřuje
            // celé přihlášení včetně MFA. Google, Apple ani Seznam druhý faktor
            // nezaručují, takže by přihlášení u nich nahradilo passkey.
            if (!$provider->isStrong()) {
                throw new OidcException(OidcException::PROVIDER_DISABLED, 'Tento poskytovatel ověření citlivé operace neumí.');
            }
            if ($userId === null || $stepUpOperation === null
                || $this->identities->countForUser($userId, $provider->id) === 0
            ) {
                throw new OidcException(OidcException::NOT_LINKED, 'Účet není propojený s poskytovatelem.');
            }
            // Ověří účel dřív, než uživatele pošleme k poskytovateli.
            $this->stepUp->assertAllowed((int) $userId, (string) $stepUpOperation, 'oidc');
        }

        $flow = $this->flows->create(
            $provider->id,
            $purpose,
            $browserBinding,
            $returnTo,
            $userId,
            $sessionToken,
            $stepUpOperation,
            $inviteId,
        );

        // Výběr účtu dokumentují jen Microsoft a Google; Apple a Seznam neznámé
        // parametry přihlášení nepotřebují.
        $extra = in_array($provider->id, ['entra', 'google'], true) ? ['prompt' => 'select_account'] : [];
        if ($purpose === OidcFlowStore::PURPOSE_STEP_UP) {
            // Vynutí nové zadání údajů u poskytovatele, ne jen tiché SSO. Při
            // `max_age` musí poskytovatel vrátit `auth_time` (OIDC Core 3.1.2.1),
            // podle kterého se čerstvost ověří (completeStepUp).
            $extra = [
                'prompt' => 'login',
                'max_age' => '0',
                'claims' => '{"id_token":{"auth_time":{"essential":true}}}',
            ];
        }
        if ($loginHint !== null && $loginHint !== '' && strlen($loginHint) <= 190) {
            $extra['login_hint'] = $loginHint;
        }

        return $this->http->authorizationUrl(
            $provider,
            $this->providers->redirectUri($provider),
            $flow['state'],
            $flow['nonce'],
            $flow['code_verifier'],
            $extra,
        );
    }

    /**
     * Dokončí návrat od poskytovatele.
     *
     * @param array<string,mixed> $query
     */
    public function complete(
        OidcProvider $provider,
        array $query,
        #[\SensitiveParameter] ?string $browserBinding,
        #[\SensitiveParameter] ?string $currentSessionToken,
        string $ip,
        string $userAgent,
    ): OidcResult {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $flow = $this->flows->consume($provider->id, $state, $browserBinding);
        try {
            return $this->completeFlow($provider, $flow, $query, $currentSessionToken, $ip, $userAgent);
        } catch (OidcException $e) {
            $e->purpose = $flow->purpose;
            $e->returnTo = $flow->returnTo;
            throw $e;
        }
    }

    /** @param array<string,mixed> $query */
    private function completeFlow(
        OidcProvider $provider,
        OidcFlow $flow,
        array $query,
        #[\SensitiveParameter] ?string $currentSessionToken,
        string $ip,
        string $userAgent,
    ): OidcResult {
        $error = $query['error'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new OidcException(
                in_array($error, ['access_denied', 'login_required', 'interaction_required', 'consent_required'], true)
                    ? OidcException::CANCELLED
                    : OidcException::PROVIDER_ERROR,
                'Poskytovatel přihlášení nedokončil.',
            );
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $identity = null;
        try {
            if ($flow->purpose === OidcFlowStore::PURPOSE_STEP_UP && !$provider->isStrong()) {
                throw new OidcException(OidcException::PROVIDER_DISABLED, 'Tento poskytovatel ověření citlivé operace neumí.');
            }
            $tokens = $this->http->exchangeCode($provider, $code, $this->providers->redirectUri($provider), $flow->codeVerifier);
            $identity = $provider->protocol === 'oauth2'
                ? $this->verifier->seznamIdentity($provider, $this->http->userinfo($provider, (string) $tokens['access_token']))
                : $this->verifier->verify($provider, (string) $tokens['id_token'], $flow->nonce);
            return match ($flow->purpose) {
                OidcFlowStore::PURPOSE_LOGIN => $this->completeLogin($provider, $flow, $identity, $ip, $userAgent),
                OidcFlowStore::PURPOSE_LINK => $this->completeLink($flow, $identity, $currentSessionToken, $ip, $userAgent),
                OidcFlowStore::PURPOSE_STEP_UP => $this->completeStepUp($flow, $identity, $currentSessionToken, $ip, $userAgent),
                default => throw new OidcException(OidcException::FLOW_INVALID),
            };
        } catch (OidcException | StepUpOperationException $e) {
            $oidcError = $e instanceof OidcException
                ? $e
                : new OidcException(OidcException::FLOW_INVALID, 'Účel ověření už neplatí.');
            $this->activity->log('auth.login_failed', $flow->userId, $flow->userId !== null ? 'user' : null, $flow->userId, [
                'login_method' => 'oidc:' . $provider->id,
                'purpose' => $flow->purpose,
                'reason' => $oidcError->errorCode,
                'email' => $identity?->email,
            ], $ip, $userAgent);
            throw $oidcError;
        }
    }

    private function completeLogin(
        OidcProvider $provider,
        OidcFlow $flow,
        ExternalIdentity $identity,
        string $ip,
        string $userAgent,
    ): OidcResult {
        $row = $this->identities->findBySubject($identity);
        if ($row === null && $flow->inviteId !== null) {
            $row = $this->acceptInvite($flow->inviteId, $identity, $ip, $userAgent);
        }
        if ($row === null) {
            throw new OidcException(OidcException::NOT_LINKED, 'Účet není propojený, požádejte správce o pozvánku.');
        }
        $userId = (int) $row['user_id'];
        $user = $this->loadUser($userId);
        if ($user === null) {
            throw new OidcException(OidcException::USER_INACTIVE, 'Účet je deaktivovaný.');
        }
        $required = $this->loginPolicy->requiredProvider();
        if ($required !== null && $required !== $provider->id) {
            throw new OidcException(OidcException::IDENTITY_MISMATCH, 'Účet se přihlašuje přes jiného poskytovatele.');
        }
        $this->identities->touch((int) $row['id'], $identity);

        return new OidcResult($flow->purpose, $flow->returnTo, $user, (int) $row['id']);
    }

    /** @return array<string,mixed> */
    private function acceptInvite(int $inviteId, ExternalIdentity $identity, string $ip, string $userAgent): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $userId = $this->invites->consume($inviteId, $identity->provider);
            $email = $this->lockUser($userId);
            // Pozvánka je nosný token, ale přijme ji jen osoba, jejíž účet u
            // poskytovatele nese stejnou adresu jako pozvaný účet. Párovacím
            // klíčem e-mail není, jen brání tomu, aby přeposlanou nebo uniklou
            // pozvánku použil kdokoli jiný z tenantu. Při neshodě se transakce
            // vrátí a pozvánka zůstane platná pro správnou osobu.
            if ($email === null || !$this->emailMatches($email, $identity)) {
                throw new OidcException(OidcException::IDENTITY_MISMATCH, 'Pozvánka patří jinému účtu.');
            }
            if ($this->identities->countForUser($userId, $identity->provider) > 0) {
                // Pozvaný už má jinou identitu téhož poskytovatele. Druhou by mohl
                // přidat jen ten, kdo drží pozvánku, a ne nutně majitel účtu.
                throw new OidcException(OidcException::ALREADY_LINKED, 'Účet už je propojený s jinou identitou.');
            }
            $this->identities->link($userId, $identity, 'invite');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->activity->log('auth.oidc_linked', $userId, 'user', $userId, [
            'provider' => $identity->provider, 'linked_by' => 'invite', 'email' => $identity->email,
            'guest' => $identity->isGuest,
        ], $ip, $userAgent);
        return $this->identities->findBySubject($identity)
            ?? throw new OidcException(OidcException::NOT_LINKED);
    }

    private function completeLink(
        OidcFlow $flow,
        ExternalIdentity $identity,
        ?string $currentSessionToken,
        string $ip,
        string $userAgent,
    ): OidcResult {
        $userId = $this->assertSameSession($flow, $currentSessionToken);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockUser($userId);
            $cutoff = $this->securityClock->capture($pdo);
            if (!$this->sessionLocks->isActiveInTransaction($pdo, $cutoff, (string) $currentSessionToken, $userId)) {
                throw new OidcException(OidcException::FLOW_INVALID, 'Sezení už není aktivní.');
            }
            $row = $this->identities->findBySubject($identity);
            if ($row !== null && (int) $row['user_id'] !== $userId) {
                throw new OidcException(OidcException::ALREADY_LINKED, 'Identita je propojená s jiným účtem.');
            }
            $linked = false;
            if ($row === null) {
                if ($this->identities->countForUser($userId, $identity->provider) > 0) {
                    throw new OidcException(OidcException::ALREADY_LINKED, 'Účet už je propojený s jinou identitou.');
                }
                $this->identities->link($userId, $identity, 'self');
                $linked = true;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($linked) {
            $this->activity->log('auth.oidc_linked', $userId, 'user', $userId, [
                'provider' => $identity->provider, 'linked_by' => 'self', 'email' => $identity->email,
                'guest' => $identity->isGuest,
            ], $ip, $userAgent);
        }
        return new OidcResult($flow->purpose, $flow->returnTo);
    }

    private function completeStepUp(
        OidcFlow $flow,
        ExternalIdentity $identity,
        ?string $currentSessionToken,
        string $ip,
        string $userAgent,
    ): OidcResult {
        $userId = $this->assertSameSession($flow, $currentSessionToken);
        $row = $this->identities->findBySubject($identity);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw new OidcException(OidcException::IDENTITY_MISMATCH, 'Přihlásili jste se jiným účtem, než který je propojený.');
        }
        // Ověření musí proběhnout až po zahájení step-upu. Bez `auth_time` nejde
        // poznat, jestli poskytovatel jen tiše nepoužil staré přihlášení (stačí
        // z adresy odstranit prompt=login), a proof by vznikl bez člověka u klávesnice.
        if ($identity->authTime === null
            || $identity->authTime < $flow->createdAt - self::AUTH_TIME_SKEW
            || $identity->authTime < time() - self::STEP_UP_MAX_AUTH_AGE
        ) {
            throw new OidcException(OidcException::TOKEN_INVALID, 'Poskytovatel nepotvrdil čerstvé přihlášení.');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockUser($userId);
            $cutoff = $this->securityClock->capture($pdo);
            if (!$this->sessionLocks->isActiveInTransaction($pdo, $cutoff, (string) $currentSessionToken, $userId)) {
                throw new OidcException(OidcException::FLOW_INVALID, 'Sezení už není aktivní.');
            }
            $token = $this->stepUp->issueInTransaction($pdo, $cutoff, $userId, (string) $currentSessionToken, (string) $flow->stepUpOperation, 'oidc');
            $this->identities->touch((int) $row['id'], $identity);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->activity->log('auth.oidc_step_up', $userId, 'user', $userId, [
            'provider' => $identity->provider, 'operation' => $flow->stepUpOperation,
        ], $ip, $userAgent);
        return new OidcResult($flow->purpose, $flow->returnTo, stepUpToken: $token, stepUpOperation: $flow->stepUpOperation);
    }

    private function assertSameSession(OidcFlow $flow, ?string $currentSessionToken): int
    {
        if ($flow->userId === null || $flow->sessionIdHash === null || $currentSessionToken === null
            || !hash_equals($flow->sessionIdHash, hash('sha256', $currentSessionToken))
        ) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Přihlášení patří k jinému sezení.');
        }
        $session = $this->sessions->load($currentSessionToken);
        if ($session === null || (int) $session['user_id'] !== $flow->userId) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Sezení už neplatí.');
        }
        $state = $this->sessionLocks->evaluate($currentSessionToken);
        if (!$state->sessionExists || $state->locked) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Sezení už není aktivní.');
        }
        return $flow->userId;
    }

    /** Zamkne řádek uživatele v aktivní transakci, vrátí jeho e-mail. */
    private function lockUser(int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT email FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        return is_string($email) ? $email : null;
    }

    private function emailMatches(string $email, ExternalIdentity $identity): bool
    {
        $expected = mb_strtolower(trim($email));
        foreach ([$identity->email, ...$identity->alternateEmails] as $candidate) {
            if (is_string($candidate) && $expected !== '' && mb_strtolower(trim($candidate)) === $expected) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function loadUser(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.email, u.name, u.role, u.role_id, u.locale,
                    u.is_active, u.totp_enabled, u.session_lock_after_minutes,
                    r.name AS role_name, r.role_type, r.is_active AS role_active, r.system_key
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
