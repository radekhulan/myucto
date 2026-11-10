<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Reset všech MFA faktorů jednoho uživatele.
 *
 * Vypne TOTP, odvolá passkeys a záložní kódy, zruší důvěryhodná zařízení,
 * čekající e-mailové kódy, rozpracované WebAuthn ceremonie a step-up proofy
 * a ukončí všechna přihlášení uživatele. Sdílí ho nouzový CLI skript
 * `api/bin/reset-mfa.php` i reset superadminem v administraci uživatelů, aby
 * se seznam zrušených faktorů nerozešel.
 *
 * API tokeny a MCP připojení ruší jen na vyžádání. CLI je nouzová reakce na
 * ztrátu kontroly nad účtem a ruší je vždy; reset v administraci řeší ztracené
 * zařízení a integrace nechává běžet.
 *
 * Audit zapisuje volající, protože jen on ví, kdo reset provedl a proč.
 */
final class MfaResetService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionManager $sessions,
        private readonly MfaRecoveryCodeService $recoveryCodes,
        private readonly ApiTokenService $apiTokens,
    ) {}

    /**
     * @return array{
     *   totp_was_enabled:bool,
     *   passkeys_revoked:int,
     *   recovery_codes_revoked:int,
     *   trusted_devices_removed:int,
     *   login_otps_removed:int,
     *   ceremonies_removed:int,
     *   step_up_proofs_removed:int,
     *   api_tokens_revoked:int,
     *   mcp_grants_revoked:int,
     *   sessions_invalidated:int
     * }
     */
    public function reset(int $userId, bool $revokeIntegrations): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $user = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            $totpEnabled = $user->fetchColumn();
            if ($totpEnabled === false) {
                throw new \DomainException('Uživatel nenalezen.');
            }
            $pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')->execute([$userId]);
            $result = [
                'totp_was_enabled' => (int) $totpEnabled === 1,
                'passkeys_revoked' => $this->execute(
                    'UPDATE webauthn_credentials SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6))
                      WHERE user_id = ? AND revoked_at IS NULL',
                    $userId,
                ),
                'recovery_codes_revoked' => $this->recoveryCodes->revokeAll($userId),
                'trusted_devices_removed' => $this->execute('DELETE FROM trusted_devices WHERE user_id = ?', $userId),
                'login_otps_removed' => $this->execute('DELETE FROM login_otps WHERE user_id = ?', $userId),
                'ceremonies_removed' => $this->execute('DELETE FROM webauthn_ceremonies WHERE user_id = ?', $userId),
                'step_up_proofs_removed' => $this->execute('DELETE FROM mfa_step_up_proofs WHERE user_id = ?', $userId),
                'api_tokens_revoked' => 0,
                'mcp_grants_revoked' => 0,
                'sessions_invalidated' => 0,
            ];
            if ($revokeIntegrations) {
                $result['mcp_grants_revoked'] = $this->execute(
                    'UPDATE mcp_oauth_grants SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
                    $userId,
                );
                $result['api_tokens_revoked'] = $this->apiTokens->revokeAllForUser($userId);
            }
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Vlastní transakce správce relací, proto až po zrušení faktorů.
        $result['sessions_invalidated'] = $this->sessions->destroyAllForUser($userId);

        return $result;
    }

    private function execute(string $sql, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }
}
