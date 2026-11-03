<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Propojení účtů MyÚčto s identitami u poskytovatelů (`user_external_identities`).
 */
class ExternalIdentityRepository
{
    private const COLUMNS = 'id, user_id, provider, issuer, tenant_id, subject, email_at_link,
                             display_name, linked_by, created_at, last_used_at';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function findBySubject(ExternalIdentity $identity): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM user_external_identities
              WHERE provider = ? AND issuer = ? AND subject = ? LIMIT 1'
        );
        $stmt->execute([$identity->provider, $identity->issuer, $identity->subject]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM user_external_identities
              WHERE user_id = ? ORDER BY provider, created_at'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countForUser(int $userId, ?string $provider = null): int
    {
        $sql = 'SELECT COUNT(*) FROM user_external_identities WHERE user_id = ?';
        $params = [$userId];
        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $params[] = $provider;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param 'self'|'admin'|'invite' $linkedBy
     */
    public function link(int $userId, ExternalIdentity $identity, string $linkedBy): int
    {
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO user_external_identities
                    (user_id, provider, issuer, tenant_id, subject, email_at_link, display_name,
                     linked_by, created_at, last_used_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            )->execute([
                $userId,
                $identity->provider,
                $identity->issuer,
                $identity->tenantId,
                $identity->subject,
                $identity->email,
                $identity->name,
                $linkedBy,
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new OidcException(OidcException::ALREADY_LINKED, 'Identita je propojená s jiným účtem.');
            }
            throw $e;
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function touch(int $id, ExternalIdentity $identity): void
    {
        $this->db->pdo()->prepare(
            'UPDATE user_external_identities
                SET last_used_at = UTC_TIMESTAMP(6),
                    email_at_link = COALESCE(?, email_at_link),
                    display_name = COALESCE(?, display_name)
              WHERE id = ?'
        )->execute([$identity->email, $identity->name, $id]);
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $userId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM user_external_identities WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Smaže identitu a zruší sezení, která z ní vznikla.
     */
    public function unlink(int $userId, int $id): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM user_external_identities WHERE id = ? AND user_id = ?');
            $delete->execute([$id, $userId]);
            $deleted = $delete->rowCount() === 1;
            if ($deleted) {
                $pdo->prepare(
                    'UPDATE sessions SET revoked_at = UTC_TIMESTAMP(6)
                      WHERE user_id = ? AND external_identity_id = ? AND revoked_at IS NULL'
                )->execute([$userId, $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $deleted;
    }

    /**
     * Veřejná podoba pro UI: bez issueru a subjektu.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'email' => $row['email_at_link'] !== null ? (string) $row['email_at_link'] : null,
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'linked_by' => (string) $row['linked_by'],
            'created_at' => (string) $row['created_at'],
            'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
        ];
    }
}
