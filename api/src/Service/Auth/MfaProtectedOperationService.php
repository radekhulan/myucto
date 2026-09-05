<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
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
     * ⚠️ Volitelné schopnosti tokenu se sem musí PROPAGOVAT, jinak je tahle
     * větev tiše zahodí. Uživatel s passkey chodí VŽDY tudy, takže zapomenutý
     * parametr znamená, že zaškrtnuté políčko ve formuláři nemá žádný účinek —
     * token vznikne, vypadá správně a jen se chová, jako by ho nikdo nezaškrtl.
     * Přesně to potkalo `allow_payroll_submission_docs`.
     *
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
        bool $allowPayrollSubmissionDocs = false,
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
                $allowPayrollSubmissionDocs,
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
     * @return array{id:int,totp_enabled:int}
     */
    private function lockActiveUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, totp_enabled
               FROM users
              WHERE id = ? AND is_active = 1
              FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new \DomainException('Uživatel už není aktivní.');
        }
        return [
            'id' => (int) $user['id'],
            'totp_enabled' => (int) ($user['totp_enabled'] ?? 0),
        ];
    }

    private function lockCurrentSession(
        PDO $pdo,
        SecurityTime $cutoff,
        int $userId,
        string $sessionToken,
    ): string {
        $stmt = $pdo->prepare(
            'SELECT session_family_id
               FROM sessions
              WHERE id = ? AND user_id = ?
                AND expires_at > FROM_UNIXTIME(?)
                AND replaced_at IS NULL
                AND revoked_at IS NULL
              FOR UPDATE'
        );
        $stmt->execute([$sessionToken, $userId, $cutoff->epochSeconds]);
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
        $stmt = $pdo->prepare(
            'SELECT id
               FROM sessions
              WHERE user_id = ?
                AND session_family_id <> ?
                AND revoked_at IS NULL
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([$userId, $currentFamily]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
}
