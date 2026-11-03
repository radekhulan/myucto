<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Pozvánka k propojení účtu s poskytovatelem identity. Správce ji vystaví
 * konkrétnímu uživateli, ten se přihlásí u poskytovatele a identita se naváže
 * na pozvaný účet. Pro první spojení se tak nepoužije e-mail z poskytovatele.
 */
class OidcInviteService
{
    public const TTL_HOURS = 168;

    public function __construct(private readonly Connection $db) {}

    public static function isTokenShape(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    /** Vystaví novou pozvánku, dřívější nepoužité pozvánky téhož uživatele zneplatní. */
    public function issue(int $userId, string $provider, ?int $createdBy): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE oidc_link_invites SET expires_at = UTC_TIMESTAMP(6)
                  WHERE user_id = ? AND provider = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)'
            )->execute([$userId, $provider]);
            $pdo->prepare(
                'INSERT INTO oidc_link_invites (user_id, provider, token_hash, created_by, created_at, expires_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL ' . self::TTL_HOURS . ' HOUR)'
            )->execute([$userId, $provider, hash('sha256', $token), $createdBy]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $token;
    }

    /** ID platné pozvánky, nebo null. Nespotřebuje ji. */
    public function findValid(#[\SensitiveParameter] string $token, string $provider): ?int
    {
        if (!self::isTokenShape($token)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM oidc_link_invites
              WHERE token_hash = ? AND provider = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)
              LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), $provider]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Atomicky spotřebuje pozvánku a vrátí pozvaného uživatele. */
    public function consume(int $inviteId, string $provider): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT user_id FROM oidc_link_invites WHERE id = ? AND provider = ?');
        $stmt->execute([$inviteId, $provider]);
        $userId = $stmt->fetchColumn();
        $update = $pdo->prepare(
            'UPDATE oidc_link_invites SET used_at = UTC_TIMESTAMP(6)
              WHERE id = ? AND provider = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)'
        );
        $update->execute([$inviteId, $provider]);
        if ($userId === false || $update->rowCount() !== 1) {
            throw new OidcException(OidcException::INVITE_INVALID, 'Pozvánka je neplatná, použitá nebo vypršela.');
        }
        return (int) $userId;
    }
}
