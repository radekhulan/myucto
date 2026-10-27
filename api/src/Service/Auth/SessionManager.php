<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Server-side browser sessions.
 *
 * MariaDB je jediná autorita pro expiraci, revokaci, session lineage a lock stav.
 */
final class SessionManager
{
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly SecurityClock $securityClock,
    ) {}

    /**
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function create(
        int $userId,
        string $ip,
        string $userAgent,
        ?SessionAuthContext $authContext = null,
    ): array {
        $authContext ??= SessionAuthContext::legacy();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $session = $this->createInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $ip,
                $userAgent,
                $authContext,
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $session;
    }

    /**
     * Volající vlastní transakci a musí mít zamčeného uživatele, pokud jde
     * o součást širšího bezpečnostního přechodu.
     *
     * `$supplierDomainId` váže session na vlastní doménu firmy. Taková session
     * platí jen na hostname této domény, nikdy na canonical adrese (viz
     * AuthMiddleware). NULL vydá běžnou canonical session.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function createInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        int $userId,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
        ?int $supplierDomainId = null,
    ): array {
        if (!$pdo->inTransaction() || $userId < 1) {
            throw new \LogicException('Vydání session vyžaduje aktivní transakci a uživatele.');
        }
        if ($supplierDomainId !== null && $supplierDomainId < 1) {
            throw new \InvalidArgumentException('Neplatná vazba session na doménu.');
        }
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa session.');
        }
        $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
        if ($lifetimeDays < 1 || $lifetimeDays > 365) {
            throw new \InvalidArgumentException('session.lifetime_days musí být 1 až 365.');
        }

        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $familyId = random_bytes(32);
        $expiresAt = $cutoff->epochSeconds + ($lifetimeDays * 86400);
        $stmt = $pdo->prepare(
            'INSERT INTO sessions
                (id, user_id, csrf_token, ip, user_agent, supplier_domain_id,
                 created_at, last_seen, expires_at,
                 auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                 last_user_activity_at, session_family_id, generation)
             VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
                     ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $token,
            $userId,
            $csrf,
            $packedIp,
            mb_substr($userAgent, 0, 255),
            $supplierDomainId,
            $cutoff->epochSeconds,
            $cutoff->epochSeconds,
            $expiresAt,
            $authContext->authMethod,
            $authContext->assuranceLevel,
            self::nullableUtc($authContext->mfaVerifiedAt),
            $authContext->authCredentialId,
            $cutoff->utcSql,
            $familyId,
            1,
        ]);

        return [
            'token' => $token,
            'csrf_token' => $csrf,
            'expires_at' => $expiresAt,
            'issued_at' => $cutoff->utc,
        ];
    }

    /**
     * Načte pouze aktuální aktivní generaci session.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $token): ?array
    {
        $context = $this->loadAuthenticationContext($token);
        return $context['session'] ?? null;
    }

    /**
     * Autoritativně načte session a jejího uživatele jedním dotazem. Uživatele
     * vrací i neaktivního, aby jej middleware mohl revokovat stejným způsobem
     * jako dosud.
     *
     * @return array{session:array<string,mixed>,user:array<string,mixed>}|null
     */
    public function loadAuthenticationContext(string $token): ?array
    {
        if (!self::isTokenShapeValid($token)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.user_id, s.csrf_token, s.ip, s.user_agent, s.supplier_domain_id,
                    UNIX_TIMESTAMP(s.created_at) AS created_at,
                    UNIX_TIMESTAMP(s.last_seen) AS last_seen,
                    UNIX_TIMESTAMP(s.expires_at) AS expires_at,
                    s.auth_method, s.assurance_level, s.mfa_verified_at,
                    s.auth_credential_id, s.last_user_activity_at, s.locked_at,
                    s.lock_reason, s.last_unlock_at, s.last_unlock_method,
                    HEX(s.session_family_id) AS session_family_id,
                    s.generation, s.replaced_at, s.revoked_at,
                    UTC_TIMESTAMP(6) AS evaluated_at,
                    UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AS evaluated_at_epoch,
                    u.id AS account_id, u.email AS account_email,
                    u.name AS account_name, u.role AS account_role,
                    u.locale AS account_locale, u.is_active AS account_is_active,
                    u.totp_enabled AS account_totp_enabled,
                    u.session_lock_after_minutes AS account_session_lock_after_minutes
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = ?
                AND s.expires_at > CURRENT_TIMESTAMP(6)
                AND s.replaced_at IS NULL
                AND s.revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $user = [
            'id' => (int) $row['account_id'],
            'email' => (string) $row['account_email'],
            'name' => (string) $row['account_name'],
            'role' => (string) $row['account_role'],
            'locale' => (string) $row['account_locale'],
            'is_active' => (int) $row['account_is_active'] === 1,
            'totp_enabled' => (int) ($row['account_totp_enabled'] ?? 0) === 1,
            'session_lock_after_minutes' => $row['account_session_lock_after_minutes'] !== null
                ? (int) $row['account_session_lock_after_minutes']
                : null,
        ];
        foreach (array_keys($row) as $key) {
            if (str_starts_with((string) $key, 'account_')) {
                unset($row[$key]);
            }
        }

        return ['session' => self::hydrate($row), 'user' => $user];
    }

    /**
     * Načte nahrazenou nebo revokovanou generaci výhradně pro idempotentní
     * logout celé session family. Volající ji nesmí použít k jiné autorizaci.
     *
     * @return array<string,mixed>|null
     */
    public function loadTombstoneForLogout(string $token): ?array
    {
        if (!self::isTokenShapeValid($token)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, csrf_token, ip, user_agent, supplier_domain_id,
                    UNIX_TIMESTAMP(created_at) AS created_at,
                    UNIX_TIMESTAMP(last_seen) AS last_seen,
                    UNIX_TIMESTAMP(expires_at) AS expires_at,
                    auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                    last_user_activity_at, locked_at, lock_reason,
                    last_unlock_at, last_unlock_method,
                    HEX(session_family_id) AS session_family_id,
                    generation, replaced_at, revoked_at
               FROM sessions
              WHERE id = ?
                AND expires_at > CURRENT_TIMESTAMP(6)
                AND (replaced_at IS NOT NULL OR revoked_at IS NOT NULL)
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * Provozní touch nesmí měnit lidskou aktivitu ani absolutní expiraci.
     */
    public function touch(string $token): void
    {
        if (!self::isTokenShapeValid($token)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE sessions
                SET last_seen = CURRENT_TIMESTAMP(6)
              WHERE id = ? AND expires_at > CURRENT_TIMESTAMP(6)
                AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([$token]);
    }

    /**
     * Omezí provozní last_seen zápis na nejvýše jeden za pět minut. Rozhodnutí
     */
    public function touchIfStale(string $token, int $lastSeenEpoch, int $evaluatedAtEpoch): void
    {
        if (!self::isTokenShapeValid($token)
            || $lastSeenEpoch > $evaluatedAtEpoch - self::TOUCH_INTERVAL_SECONDS
        ) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE sessions
                SET last_seen = CURRENT_TIMESTAMP(6)
              WHERE id = ?
                AND expires_at > CURRENT_TIMESTAMP(6)
                AND replaced_at IS NULL
                AND revoked_at IS NULL
                AND last_seen <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 5 MINUTE)'
        );
        $stmt->execute([$token]);
    }

    /**
     * Revokuje celou lineage i při logoutu přes starší nahrazenou generaci.
     * Volitelný audit proběhne ve stejné transakci; jeho chyba revokaci vrátí.
     */
    public function destroy(string $token, ?callable $onRevoked = null): void
    {
        $this->destroyMany([$token], $onRevoked);
    }

    /** Atomická revokace nejvýše 50 vybraných lineage, se společným transakčním auditem. */
    public function destroyMany(array $tokens, ?callable $onRevoked = null): void
    {
        $tokens = array_values(array_unique(array_filter($tokens, self::isTokenShapeValid(...))));
        if ($tokens === []) return;
        if (count($tokens) > 50) throw new \InvalidArgumentException('Příliš mnoho relací.');
        sort($tokens);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $placeholders = implode(',', array_fill(0, count($tokens), '?'));
            $family = $pdo->prepare('SELECT session_family_id FROM sessions WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
            $family->execute($tokens);
            $families = array_values(array_unique($family->fetchAll(PDO::FETCH_COLUMN)));
            if ($families === []) {
                $pdo->commit();
                return;
            }
            $placeholders = implode(',', array_fill(0, count($families), '?'));
            $revoke = $pdo->prepare(
                'UPDATE sessions SET revoked_at = COALESCE(revoked_at, ?)
                  WHERE session_family_id IN (' . $placeholders . ')'
            );
            $revoke->execute([$cutoff->utcSql, ...$families]);
            // Administrátorský audit musí uspět ve stejné transakci jako revokace.
            if ($onRevoked !== null) $onRevoked();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Atomicky revokuje všechny session families uživatele. Volitelná výjimka
     * zachová celou family předložené aktuální session.
     */
    public function destroyAllForUser(int $userId, ?string $exceptToken = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            if ($user->fetchColumn() === false) {
                $pdo->commit();
                return 0;
            }

            $exceptFamily = null;
            if ($exceptToken !== null && self::isTokenShapeValid($exceptToken)) {
                $stmt = $pdo->prepare(
                    'SELECT session_family_id FROM sessions WHERE id = ? AND user_id = ? LIMIT 1'
                );
                $stmt->execute([$exceptToken, $userId]);
                $value = $stmt->fetchColumn();
                $exceptFamily = is_string($value) && $value !== '' ? $value : null;
            }

            $params = [$userId];
            $where = 'user_id = ? AND revoked_at IS NULL';
            if ($exceptFamily !== null) {
                $where .= ' AND session_family_id <> ?';
                $params[] = $exceptFamily;
            }

            $revoke = $pdo->prepare(
                "UPDATE sessions SET revoked_at = ? WHERE {$where}"
            );
            $revoke->execute([$cutoff->utcSql, ...$params]);
            $revoked = $revoke->rowCount();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $revoked;
    }

    /**
     * Atomicky spotřebuje aktuální setup session, revokuje všechny ostatní
     * setup session uživatele a vydá novou plnou strong session v nové family.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,issued_at:\DateTimeImmutable}
     */
    public function completeSetup(
        int $userId,
        string $setupToken,
        string $ip,
        string $userAgent,
        SessionAuthContext $authContext,
    ): array {
        if ($userId < 1
            || !self::isTokenShapeValid($setupToken)
            || $authContext->assuranceLevel !== 'strong'
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na dokončení MFA setupu.');
        }
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('Neplatná IP adresa session.');
        }
        $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
        if ($lifetimeDays < 1 || $lifetimeDays > 365) {
            throw new \InvalidArgumentException('session.lifetime_days musí být 1 až 365.');
        }

        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $familyId = random_bytes(32);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $expiresAt = $cutoff->epochSeconds + ($lifetimeDays * 86400);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
            $user->execute([$userId]);
            if ($user->fetchColumn() === false) {
                throw new \DomainException('Uživatel už není aktivní.');
            }

            $current = $pdo->prepare(
                'SELECT id
                   FROM sessions
                  WHERE id = ?
                    AND user_id = ?
                    AND assurance_level = ?
                    AND expires_at > FROM_UNIXTIME(?)
                    AND replaced_at IS NULL
                    AND revoked_at IS NULL
                  FOR UPDATE'
            );
            $current->execute([$setupToken, $userId, 'setup', $cutoff->epochSeconds]);
            if ($current->fetchColumn() === false) {
                throw new \DomainException('Setup session už není dostupná.');
            }

            $revoke = $pdo->prepare(
                'UPDATE sessions
                    SET revoked_at = ?
                  WHERE user_id = ? AND assurance_level = ? AND revoked_at IS NULL'
            );
            $revoke->execute([$cutoff->utcSql, $userId, 'setup']);

            $insert = $pdo->prepare(
                'INSERT INTO sessions
                    (id, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
                     auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                     last_user_activity_at, session_family_id, generation)
                 VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?),
                         ?, ?, ?, ?, ?, ?, 1)'
            );
            $insert->execute([
                $token,
                $userId,
                $csrf,
                $packedIp,
                mb_substr($userAgent, 0, 255),
                $cutoff->epochSeconds,
                $cutoff->epochSeconds,
                $expiresAt,
                $authContext->authMethod,
                $authContext->assuranceLevel,
                self::nullableUtc($authContext->mfaVerifiedAt),
                $authContext->authCredentialId,
                $cutoff->utcSql,
                $familyId,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new \RuntimeException('Strong session se nepodařilo vytvořit.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'token' => $token,
            'csrf_token' => $csrf,
            'expires_at' => $expiresAt,
            'issued_at' => $cutoff->utc,
        ];
    }

    /**
     * Vrátí session povýšenou dokončením MFA setupu zpět na `setup`, když se
     * klíč, kterým se povýšila, při zkušebním ověření odvolal. Silné ověření
     * by jinak tvrdil klíč, který nefunguje, a uživatel by při povinném MFA
     * zůstal v aplikaci bez jediného faktoru (#225).
     */
    public function demoteToSetupInTransaction(
        PDO $pdo,
        string $token,
        int $userId,
        int $authCredentialId,
    ): bool {
        if (!$pdo->inTransaction()
            || !self::isTokenShapeValid($token)
            || $userId < 1
            || $authCredentialId < 1
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na snížení úrovně session.');
        }

        $stmt = $pdo->prepare(
            "UPDATE sessions
                SET auth_method = 'password', assurance_level = 'setup',
                    mfa_verified_at = NULL, auth_credential_id = NULL
              WHERE id = ? AND user_id = ?
                AND assurance_level = 'strong'
                AND auth_credential_id = ?
                AND replaced_at IS NULL
                AND revoked_at IS NULL"
        );
        $stmt->execute([$token, $userId, $authCredentialId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Atomicky nahradí zamčenou generaci novým ID a CSRF bez prodloužení
     * absolutní expirace nebo změny login assurance.
     *
     * @return array{token:string,csrf_token:string,expires_at:int,user_id:int,issued_at:\DateTimeImmutable}
     */
    public function rotateLocked(
        string $token,
        string $unlockMethod,
        ?int $authCredentialId = null,
    ): array {
        if (!self::isTokenShapeValid($token)
            || !in_array($unlockMethod, ['passkey'], true)
            || $authCredentialId === null
            || $authCredentialId < 1
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na rotaci zamčené session.');
        }

        $pdo = $this->db->pdo();
        $owner = $pdo->prepare('SELECT user_id FROM sessions WHERE id = ? LIMIT 1');
        $owner->execute([$token]);
        $userId = $owner->fetchColumn();
        if ($userId === false) {
            throw new \DomainException('Zamčená session už není dostupná.');
        }

        $pdo->beginTransaction();
        try {
            $cutoff = $this->securityClock->capture($pdo);
            $user = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
            $user->execute([(int) $userId]);
            if ($user->fetchColumn() === false) {
                throw new \DomainException('Uživatel už není aktivní.');
            }
            $rotated = $this->rotateLockedInTransaction(
                $pdo,
                $cutoff,
                $token,
                (int) $userId,
                $unlockMethod,
                $authCredentialId,
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $rotated;
    }

    /**
     * @return array{token:string,csrf_token:string,expires_at:int,user_id:int,issued_at:\DateTimeImmutable}
     */
    public function rotateLockedInTransaction(
        PDO $pdo,
        SecurityTime $cutoff,
        string $token,
        int $userId,
        string $unlockMethod,
        int $authCredentialId,
    ): array {
        if (!$pdo->inTransaction()
            || !self::isTokenShapeValid($token)
            || $userId < 1
            || $authCredentialId < 1
            || !in_array($unlockMethod, ['passkey'], true)
        ) {
            throw new \InvalidArgumentException('Neplatný požadavek na rotaci zamčené session.');
        }

        $select = $pdo->prepare(
            'SELECT user_id, UNIX_TIMESTAMP(expires_at) AS expires_at
               FROM sessions
              WHERE id = ?
                AND user_id = ?
                AND expires_at > FROM_UNIXTIME(?)
                AND locked_at IS NOT NULL
                AND replaced_at IS NULL
                AND revoked_at IS NULL
              FOR UPDATE'
        );
        $select->execute([$token, $userId, $cutoff->epochSeconds]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Zamčená session už není dostupná.');
        }

        $credential = $pdo->prepare(
            'SELECT id
               FROM webauthn_credentials
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL
              FOR UPDATE'
        );
        $credential->execute([$authCredentialId, $userId]);
        if ($credential->fetchColumn() === false) {
            throw new \DomainException('Passkey už není aktivní.');
        }

        $newToken = bin2hex(random_bytes(32));
        $newCsrf = bin2hex(random_bytes(32));
        $insert = $pdo->prepare(
            'INSERT INTO sessions
                (id, user_id, csrf_token, ip, user_agent, supplier_domain_id,
                 created_at, last_seen, expires_at,
                 auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                 last_user_activity_at, locked_at, lock_reason, last_unlock_at,
                 last_unlock_method, session_family_id, generation, replaced_at, revoked_at)
             SELECT ?, user_id, ?, ip, user_agent, supplier_domain_id,
                    created_at, FROM_UNIXTIME(?), expires_at,
                    auth_method, assurance_level, mfa_verified_at, auth_credential_id,
                    ?, NULL, NULL, ?, ?, session_family_id, generation + 1, NULL, NULL
               FROM sessions
              WHERE id = ? AND user_id = ?
                AND locked_at IS NOT NULL AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $insert->execute([
            $newToken,
            $newCsrf,
            $cutoff->epochSeconds,
            $cutoff->utcSql,
            $cutoff->utcSql,
            $unlockMethod,
            $token,
            $userId,
        ]);
        if ($insert->rowCount() !== 1) {
            throw new \RuntimeException('Novou generaci session se nepodařilo vytvořit.');
        }

        $replace = $pdo->prepare(
            'UPDATE sessions
                SET replaced_at = ?
              WHERE id = ? AND user_id = ?
                AND locked_at IS NOT NULL AND replaced_at IS NULL AND revoked_at IS NULL'
        );
        $replace->execute([$cutoff->utcSql, $token, $userId]);
        if ($replace->rowCount() !== 1) {
            throw new \RuntimeException('Původní generaci session se nepodařilo nahradit.');
        }

        return [
            'token' => $newToken,
            'csrf_token' => $newCsrf,
            'expires_at' => (int) $row['expires_at'],
            'user_id' => $userId,
            'issued_at' => $cutoff->utc,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $packedIp = (string) $row['ip'];
        $row['ip'] = $packedIp !== '' ? (@inet_ntop($packedIp) ?: '') : '';
        $row['user_id'] = (int) $row['user_id'];
        $row['supplier_domain_id'] = isset($row['supplier_domain_id'])
            ? (int) $row['supplier_domain_id']
            : null;
        $row['created_at'] = (int) $row['created_at'];
        $row['last_seen'] = (int) $row['last_seen'];
        $row['expires_at'] = (int) $row['expires_at'];
        if (array_key_exists('evaluated_at_epoch', $row)) {
            $row['evaluated_at_epoch'] = (int) $row['evaluated_at_epoch'];
        }
        $row['auth_credential_id'] = $row['auth_credential_id'] !== null
            ? (int) $row['auth_credential_id']
            : null;
        $row['generation'] = (int) $row['generation'];
        $row['session_family_id'] = strtolower((string) $row['session_family_id']);
        unset($row['id']);
        return $row;
    }

    private static function isTokenShapeValid(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function nullableUtc(?\DateTimeImmutable $time): ?string
    {
        return $time !== null ? self::utc($time) : null;
    }
}
