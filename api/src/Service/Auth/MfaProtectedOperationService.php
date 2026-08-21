<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use PDO;

/**
 * Spojuje spotřebu účelového step-up proofu s chráněnou operací do jedné
 * databázové transakce. Uživatelský řádek serializuje souběžné bezpečnostní
 * operace před zamykáním session, credentials a účelového proofu.
 */
final class MfaProtectedOperationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecurityClock $clock,
        private readonly MfaStepUpService $stepUp,
        private readonly MfaPolicyService $policy,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly ApiTokenService $tokens,
    ) {}

    /**
     * @return array{plaintext:string,prefix:string,id:int}
     */
    public function createApiToken(
        int $userId,
        string $sessionToken,
        string $proofToken,
        ?int $supplierId,
        string $name,
        string $scope,
        ?\DateTimeImmutable $expiresAt,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $this->lockActiveUser($pdo, $userId);
            $this->lockCurrentSession($pdo, $cutoff, $userId, $sessionToken);
            $this->stepUp->consumeInTransaction(
                $pdo,
                $cutoff,
                $proofToken,
                $userId,
                $sessionToken,
                MfaStepUpService::OPERATION_API_TOKEN_CREATE,
            );
            $token = $this->tokens->generateInTransaction(
                $pdo,
                $userId,
                $supplierId,
                $name,
                $scope,
                $expiresAt,
            );
            $pdo->commit();
            return $token;
        } catch (OneTimeTokenException|StepUpOperationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Heslový re-auth, volitelná/povinná spotřeba MFA proofu a vydání grantu
     * proběhnou atomicky. Povinnost MFA určuje politika instance; heslo se
     * ověřuje vždy, aby samotná unesená browser session nestačila ke kopii firmy.
     *
     * @return array{id:int,public_id:string,plaintext:string,prefix:string,supplier_id:int,expires_at:string}
     */
    public function createTenantTransferGrant(
        TenantTransferGrantService $grants,
        BruteForceGuard $bruteForce,
        PasswordHasher $passwords,
        int $userId,
        string $sessionToken,
        string $password,
        string $proofToken,
        int $supplierId,
        string $requestIp,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $user = $this->lockActiveUser($pdo, $userId);
            $this->lockCurrentSession($pdo, $cutoff, $userId, $sessionToken);

            $state = $bruteForce->check($user['email'], $requestIp);
            if (in_array(
                $state,
                [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H],
                true,
            )) {
                $pdo->rollBack();
                throw new ProtectedOperationRateLimitedException(
                    'Příliš mnoho pokusů o chráněnou operaci.',
                );
            }

            $passwordValid = $password !== ''
                && $passwords->verify($password, $user['password_hash']);
            if ($password === '') {
                $passwords->dummyVerify();
            }
            if (!$passwordValid) {
                $bruteForce->recordFailure($user['email'], $requestIp);
                $pdo->commit();
                throw new ProtectedOperationAuthenticationException('wrong_password');
            }

            $proofToken = trim($proofToken);
            if ($this->policy->isRequired() && $proofToken === '') {
                throw new ProtectedOperationAuthenticationException('missing_step_up');
            }
            if ($proofToken !== '') {
                try {
                    $this->stepUp->consumeInTransaction(
                        $pdo,
                        $cutoff,
                        $proofToken,
                        $userId,
                        $sessionToken,
                        MfaStepUpService::OPERATION_TENANT_TRANSFER_GRANT_CREATE,
                    );
                } catch (OneTimeTokenException|StepUpOperationException $exception) {
                    $bruteForce->recordFailure($user['email'], $requestIp);
                    $pdo->commit();
                    throw new ProtectedOperationAuthenticationException(
                        'invalid_step_up',
                        $exception,
                    );
                }
            }

            $bruteForce->recordSuccess($user['email'], $requestIp);
            $grant = $grants->issueInTransaction(
                $pdo,
                $cutoff,
                $userId,
                $supplierId,
                $requestIp,
            );
            $pdo->commit();
            return $grant;
        } catch (ProtectedOperationAuthenticationException|ProtectedOperationRateLimitedException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revokePasskey(
        int $userId,
        string $sessionToken,
        int $credentialId,
        string $proofToken,
    ): StoredPasskeyCredential {
        $pdo = $this->db->pdo();
        $revokedSessionTokens = [];
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $user = $this->lockActiveUser($pdo, $userId);
            $currentFamily = $this->lockCurrentSession(
                $pdo,
                $cutoff,
                $userId,
                $sessionToken,
            );
            $revokedSessionTokens = $this->lockOtherSessionFamilies(
                $pdo,
                $userId,
                $currentFamily,
            );
            $activeCredentials = $this->credentials->lockAllActiveForUser($pdo, $userId);
            $credential = null;
            foreach ($activeCredentials as $candidate) {
                if ($candidate->id === $credentialId) {
                    $credential = $candidate;
                    break;
                }
            }
            if ($credential === null) {
                throw new \DomainException('Passkey už není aktivní.');
            }

            $remainingPasskeys = count($activeCredentials) - 1;
            $hasAllowedFactor = (
                $this->policy->isMethodAllowed('passkey') && $remainingPasskeys > 0
            ) || (
                $this->policy->isMethodAllowed('totp') && (int) $user['totp_enabled'] === 1
            );
            if ($this->policy->isRequired() && !$hasAllowedFactor) {
                throw new LastMfaFactorException(
                    'Při povinném MFA nelze odebrat poslední povolený silný faktor.',
                );
            }

            $this->stepUp->consumeInTransaction(
                $pdo,
                $cutoff,
                $proofToken,
                $userId,
                $sessionToken,
                'passkey.revoke:' . $credentialId,
            );
            if (!$this->credentials->revokeInTransaction(
                $pdo,
                $userId,
                $credentialId,
                $cutoff,
            )) {
                throw new \DomainException('Passkey už není aktivní.');
            }
            if ($revokedSessionTokens !== []) {
                $revoke = $pdo->prepare(
                    'UPDATE sessions
                        SET revoked_at = ?
                      WHERE user_id = ?
                        AND session_family_id <> ?
                        AND revoked_at IS NULL'
                );
                $revoke->execute([$cutoff->utcSql, $userId, $currentFamily]);
            }
            $pdo->commit();
        } catch (OneTimeTokenException|StepUpOperationException $e) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $credential;
    }

    /**
     * @return array{id:int,totp_enabled:int,email:string,password_hash:string}
     */
    private function lockActiveUser(PDO $pdo, int $userId): array
    {
        $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT id, totp_enabled, email, password_hash
               FROM users
              WHERE id = ? AND is_active = 1' . $forUpdate
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new \DomainException('Uživatel už není aktivní.');
        }
        if (!is_array($user)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatného uživatele.');
        }
        $email = $user['email'] ?? null;
        $passwordHash = $user['password_hash'] ?? null;
        if (!is_string($email) || !is_string($passwordHash)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatné přihlašovací údaje.');
        }
        return [
            'id' => self::databaseInt($user['id'] ?? null),
            'totp_enabled' => self::databaseInt($user['totp_enabled'] ?? 0),
            'email' => $email,
            'password_hash' => $passwordHash,
        ];
    }

    private function lockCurrentSession(
        PDO $pdo,
        SecurityTime $cutoff,
        int $userId,
        string $sessionToken,
    ): string {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $expiresCondition = $sqlite ? 'expires_at > ?' : 'expires_at > FROM_UNIXTIME(?)';
        $forUpdate = $sqlite ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT session_family_id
               FROM sessions
              WHERE id = ? AND user_id = ?
                AND ' . $expiresCondition . '
                AND replaced_at IS NULL
                AND revoked_at IS NULL' . $forUpdate
        );
        $stmt->execute([
            $sessionToken,
            $userId,
            $sqlite ? $cutoff->utcSql : $cutoff->epochSeconds,
        ]);
        $family = $stmt->fetchColumn();
        if (!is_string($family) || $family === '') {
            throw new \DomainException('Session už není dostupná.');
        }
        return $family;
    }

    /**
     * @return list<string>
     */
    private function lockOtherSessionFamilies(PDO $pdo, int $userId, string $currentFamily): array
    {
        $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT id
               FROM sessions
              WHERE user_id = ?
                AND session_family_id <> ?
                AND revoked_at IS NULL
              ORDER BY id' . $forUpdate
        );
        $stmt->execute([$userId, $currentFamily]);
        $tokens = [];
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $row) {
            if (is_string($row)) {
                $tokens[] = $row;
            }
        }
        return $tokens;
    }

    private static function databaseInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Databáze vrátila neplatné celé číslo.');
    }
}
