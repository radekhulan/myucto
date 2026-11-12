<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;

/**
 * Ověření místních přihlašovacích údajů: e-mail a heslo, TOTP a záložní kód.
 *
 * Sdílí ho přihlášení webu ({@see \MyInvoice\Action\Auth\LoginAction}) a výměna
 * hesla za API token ({@see \MyInvoice\Action\Auth\PasswordTokenAction}). Obě cesty
 * tak mají stejné počítadlo pokusů v BruteForceGuard (pokusy přes web a přes API
 * se sčítají), stejné vyrovnání doby odpovědi pro neexistující účet a stejný
 * záznam do auditu. Rozhodnutí, který faktor se po heslu vyžaduje, zůstává
 * v akci: web umí passkey ceremonii a důvěryhodné zařízení, API ne.
 *
 * `$logContext` se přidá ke každému záznamu do auditu (např. kanál přihlášení).
 */
final class PasswordLoginVerifier
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly BruteForceGuard $bruteForce,
        private readonly ActivityLogger $logger,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly MfaRecoveryCodeService $recoveryCodes,
    ) {}

    /**
     * Uživatel pro přihlášení podle e-mailu, včetně role a stavu druhého faktoru.
     *
     * @return array<string,mixed>|null
     */
    public function findUser(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.email, u.name, u.role, u.role_id, u.locale, u.password_hash,
                    u.is_active, u.totp_secret, u.totp_enabled, u.session_lock_after_minutes,
                    r.name AS role_name, r.role_type,
                    r.is_active AS role_active, r.system_key
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * Existuje aktivní účet? Bez hesla ho volá přihlášení, kde identitu doložil
     * poskytovatel (lístek SSO); `verifyPassword` ho volá vždy.
     *
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $logContext
     */
    public function verifyActiveAccount(
        ?array $user,
        string $email,
        string $ip,
        string $userAgent,
        array $logContext = [],
    ): ?LoginFailure {
        if ($user !== null && (int) $user['is_active'] !== 0) {
            return null;
        }
        $this->hasher->dummyVerify();
        $this->bruteForce->recordFailure($email, $ip);
        $this->logger->log('auth.login_failed', null, null, null, [
            'email' => $email, 'reason' => $user !== null ? 'user_inactive' : 'user_not_found',
        ] + $logContext, $ip, $userAgent);
        return LoginFailure::invalidCredentials();
    }

    /**
     * Ověří heslo. Neexistující účet, neaktivní účet i špatné heslo dopadnou navenek
     * stejně (`invalid_credentials`) a se srovnatelnou dobou odpovědi (`dummyVerify`),
     * aby odpověď neprozradila, jestli e-mail existuje.
     *
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $logContext
     */
    public function verifyPassword(
        ?array $user,
        string $email,
        string $password,
        string $ip,
        string $userAgent,
        array $logContext = [],
    ): ?LoginFailure {
        $failure = $this->verifyActiveAccount($user, $email, $ip, $userAgent, $logContext);
        if ($failure !== null || $user === null) {
            return $failure ?? LoginFailure::invalidCredentials();
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->bruteForce->recordFailure($email, $ip);
            $this->logger->log('auth.login_failed', (int) $user['id'], 'user', (int) $user['id'], [
                'email' => $email, 'reason' => 'wrong_password',
            ] + $logContext, $ip, $userAgent);
            return LoginFailure::invalidCredentials();
        }

        return null;
    }

    /**
     * Ověří a spotřebuje TOTP kód (stejný kód nejde použít dvakrát). Chybný kód se
     * počítá do zámku TOTP uživatele i do zámku přihlášení (e-mail, IP).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $logContext
     */
    public function verifyTotp(
        array $user,
        string $email,
        string $code,
        string $ip,
        string $userAgent,
        array $logContext = [],
    ): ?LoginFailure {
        $userId = (int) $user['id'];
        // Per-user TOTP lockout — chrání 10⁶ keyspace proti brute-force
        if ($this->bruteForce->isTotpLocked($userId)) {
            $this->logger->log('auth.totp_locked', $userId, 'user', $userId, [
                'email' => $email,
            ] + $logContext, $ip, $userAgent);
            return new LoginFailure('too_many_attempts', 'Příliš mnoho TOTP pokusů. Zkus to později.', 429);
        }
        $totpSecret = $this->crypto->decrypt((string) $user['totp_secret']);
        if (!$this->totp->verifyAndConsume($this->db, $totpSecret, $code)) {
            $this->bruteForce->recordTotpFailure($userId);
            $this->bruteForce->recordFailure($email, $ip);
            $this->logger->log('auth.login_failed', $userId, 'user', $userId, [
                'email' => $email, 'reason' => 'totp_invalid',
            ] + $logContext, $ip, $userAgent);
            return new LoginFailure('invalid_totp', 'Neplatný TOTP kód.', 401);
        }
        $this->bruteForce->recordTotpSuccess($userId);

        return null;
    }

    /**
     * Uplatní jednorázový záložní kód. Chybný kód sdílí zámek s TOTP.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $logContext
     */
    public function useRecoveryCode(
        array $user,
        string $email,
        string $code,
        string $ip,
        string $userAgent,
        array $logContext = [],
    ): ?LoginFailure {
        $userId = (int) $user['id'];
        if ($this->bruteForce->isTotpLocked($userId)) {
            return new LoginFailure('too_many_attempts', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }
        if (!$this->recoveryCodes->consume($userId, $code, $ip)) {
            $this->bruteForce->recordTotpFailure($userId);
            $this->bruteForce->recordFailure($email, $ip);
            $this->logger->log('auth.login_failed', $userId, 'user', $userId, [
                'email' => $email, 'reason' => 'recovery_code_invalid',
            ] + $logContext, $ip, $userAgent);
            return new LoginFailure('invalid_recovery_code', 'Neplatný nebo už použitý záložní kód.', 401);
        }
        $this->bruteForce->recordTotpSuccess($userId);
        $this->logger->log('auth.recovery_code_login', $userId, 'user', $userId, [
            'email' => $email, 'remaining' => $this->recoveryCodes->remaining($userId),
        ] + $logContext, $ip, $userAgent);

        return null;
    }

    /**
     * Metody druhého faktoru nabídnuté ve fázi „heslo prošlo, čekáme na TOTP".
     * Seznam sestavuje výhradně server z uloženého stavu uživatele — klient si
     * o jinou metodu říct nemůže a passkey tu chybí schválně: ve webu by uživatel
     * s passkey skončil dřív ve větvi `mfa_required` s vázanou ceremonií, výměna
     * hesla za token passkey neumí vůbec.
     *
     * @return list<string>
     */
    public function totpStepMethods(int $userId): array
    {
        $methods = ['totp'];
        if ($this->recoveryCodes->hasUsable($userId)) {
            $methods[] = 'recovery';
        }
        return $methods;
    }

    /**
     * Po úspěšném ověření převede hash hesla na aktuální parametry. Souběžná změna
     * hesla (jiný hash v DB) se nepřepíše.
     *
     * @param array<string,mixed> $user
     */
    public function rehashPasswordIfNeeded(array &$user, string $password): void
    {
        if ($password === '') {
            return;
        }
        if (!$this->hasher->needsRehash((string) $user['password_hash'])) {
            return;
        }
        $newHash = $this->hasher->hash($password);
        $stmt = $this->db->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND HEX(password_hash) = HEX(?)');
        $stmt->execute([$newHash, (int) $user['id'], (string) $user['password_hash']]);
        if ($stmt->rowCount() === 1) {
            $user['password_hash'] = $newHash;
        }
    }
}
