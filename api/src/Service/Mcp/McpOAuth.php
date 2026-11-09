<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use PDO;

final class McpOAuth
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApiTokenService $tokens,
        private readonly McpResourcePolicy $resources,
    ) {}

    public function register(string $name, array $redirectUris): string
    {
        $id = self::randomToken('mi_mcp_client_');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $name, json_encode($redirectUris, JSON_THROW_ON_ERROR)]);
        return $id;
    }

    public function client(string $id): ?array
    {
        if (strlen($id) > 64) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT client_id, client_name, redirect_uris FROM mcp_oauth_clients WHERE client_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true);
        return $row;
    }

    public function createCode(
        string $clientId,
        int $userId,
        ?int $supplierId,
        string $redirectUri,
        string $challenge,
        string $scope,
        string $resource,
        ?string $issuedAuthMethod = null,
    ): string {
        $code = self::randomToken('mi_mcp_code_');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO mcp_oauth_codes
             (code_hash, client_id, user_id, supplier_id, redirect_uri, code_challenge, scope, resource,
              issued_auth_method, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))'
        );
        $stmt->execute([
            hash('sha256', $code), $clientId, $userId, $supplierId,
            $redirectUri, $challenge, $scope, $resource, $issuedAuthMethod, time() + 300,
        ]);
        return $code;
    }

    public function exchange(
        string $code,
        string $clientId,
        string $redirectUri,
        string $verifier,
        string $resource,
    ): ?array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, u.is_active AS user_active, r.is_active AS role_active
                   FROM mcp_oauth_codes c
                   JOIN users u ON u.id = c.user_id
                   JOIN roles r ON r.id = u.role_id
                  WHERE c.code_hash = ? AND c.expires_at > NOW() FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $code)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!is_array($row)
                || !hash_equals((string) $row['client_id'], $clientId)
                || !hash_equals((string) $row['redirect_uri'], $redirectUri)
                || !hash_equals((string) $row['resource'], $resource)
                || !hash_equals((string) $row['code_challenge'], $challenge)
                || (int) $row['user_active'] !== 1
                || (int) $row['role_active'] !== 1
                || !$this->resources->allowsGrant((string) $row['resource'], (int) $row['user_id'],
                    $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null, (string) $row['scope'], $row['issued_auth_method'] ?? null)
                || !$this->tokens->issuingIdentityFresh((int) $row['user_id'], $row['issued_auth_method'] ?? null, $pdo)
            ) {
                $pdo->rollBack();
                return null;
            }

            $pdo->prepare('DELETE FROM mcp_oauth_codes WHERE code_hash = ?')
                ->execute([hash('sha256', $code)]);
            $refresh = self::randomToken('mi_mcp_rt_');
            $access = $this->tokens->generateInTransaction(
                $pdo, (int) $row['user_id'], $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
                'MCP OAuth ' . substr($clientId, 0, 20), (string) $row['scope'],
                new \DateTimeImmutable('+1 hour'),
            );
            $pdo->prepare("UPDATE api_tokens SET audience = 'mcp', issued_auth_method = ? WHERE id = ?")
                ->execute([$row['issued_auth_method'] ?? null, $access['id']]);
            $pdo->prepare(
                'INSERT INTO mcp_oauth_grants
                 (client_id, user_id, supplier_id, scope, resource, refresh_hash, access_token_id, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))'
            )->execute([
                $clientId, $row['user_id'], $row['supplier_id'], $row['scope'], $row['resource'],
                hash('sha256', $refresh), $access['id'], time() + 90 * 86400,
            ]);
            $pdo->commit();
            return self::tokenResponse($access['plaintext'], $refresh, (string) $row['scope']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function refresh(string $refreshToken, string $clientId, ?string $resource = null): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT g.*, t.revoked_at AS token_revoked, t.issued_auth_method, u.is_active AS user_active,
                        r.is_active AS role_active
                   FROM mcp_oauth_grants g
                   JOIN api_tokens t ON t.id = g.access_token_id
                   JOIN users u ON u.id = g.user_id
                   JOIN roles r ON r.id = u.role_id
                  WHERE g.refresh_hash = ? AND g.revoked_at IS NULL
                    AND g.expires_at > NOW() FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $refreshToken)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || !hash_equals((string) $row['client_id'], $clientId)
                || $row['token_revoked'] !== null
                || ($resource !== null && !hash_equals((string) $row['resource'], $resource))
                || (int) $row['user_active'] !== 1
                || (int) $row['role_active'] !== 1
                || !$this->resources->allowsGrant((string) $row['resource'], (int) $row['user_id'],
                    $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null, (string) $row['scope'], $row['issued_auth_method'] ?? null)
                // Připojení z přihlášení přes Entra ID se obnoví, jen když se uživatel
                // přes Entra ID ověřil v rámci stropu sezení (zablokovaný už ne).
                || !$this->tokens->issuingIdentityFresh((int) $row['user_id'], $row['issued_auth_method'] ?? null, $pdo)
            ) {
                $pdo->rollBack();
                return null;
            }

            $nextRefresh = self::randomToken('mi_mcp_rt_');
            // Obnova počet oprávnění nemění: dočasný token se hned smaže.
            $access = $this->tokens->generateInTransaction(
                $pdo, (int) $row['user_id'], $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
                'MCP OAuth ' . substr($clientId, 0, 20), (string) $row['scope'],
                new \DateTimeImmutable('+1 hour'),
                seatGate: false,
            );
            $pdo->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$access['id']]);
            $rotate = $pdo->prepare(
                'UPDATE api_tokens
                    SET token_hash = ?, prefix = ?, expires_at = FROM_UNIXTIME(?),
                        last_used_at = NULL, last_used_ip = NULL
                  WHERE id = ? AND revoked_at IS NULL'
            );
            $rotate->execute([
                hash('sha256', $access['plaintext']), $access['prefix'], time() + 3600,
                $row['access_token_id'],
            ]);
            if ($rotate->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->prepare('UPDATE mcp_oauth_grants SET refresh_hash = ? WHERE id = ?')
                ->execute([hash('sha256', $nextRefresh), $row['id']]);
            $pdo->commit();
            return self::tokenResponse($access['plaintext'], $nextRefresh, (string) $row['scope']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function listForUser(int $userId, ?int $supplierId = null, ?string $resource = null): array
    {
        return $this->listGrants($userId, $supplierId, $resource);
    }

    /**
     * Připojení všech uživatelů instance i se jménem uživatele. Jen pro superadmina,
     * bránu drží volající akce.
     */
    public function listAll(): array
    {
        return $this->listGrants(null);
    }

    private function listGrants(?int $userId, ?int $supplierId = null, ?string $resource = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT g.id, c.client_name, g.supplier_id, s.display_name AS supplier_name,
                    s.company_name AS supplier_company, g.scope, g.created_at, g.expires_at,
                    g.revoked_at, t.revoked_at AS token_revoked_at, t.last_used_at,
                    g.resource, g.user_id, u.name AS user_name, u.email AS user_email
               FROM mcp_oauth_grants g
               JOIN mcp_oauth_clients c ON c.client_id = g.client_id
               LEFT JOIN supplier s ON s.id = g.supplier_id
               JOIN api_tokens t ON t.id = g.access_token_id
               LEFT JOIN users u ON u.id = g.user_id'
            . ($userId !== null ? ' WHERE g.user_id = ?' : '')
            . ($supplierId !== null ? ' AND g.supplier_id = ?' : '')
            . ($resource !== null ? ' AND g.resource = ?' : '')
            . ' ORDER BY g.revoked_at IS NOT NULL, g.created_at DESC, g.id DESC'
        );
        $stmt->execute([...($userId !== null ? [$userId] : []), ...($supplierId !== null ? [$supplierId] : []), ...($resource !== null ? [$resource] : [])]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
            $row['is_active'] = $row['revoked_at'] === null
                && $row['token_revoked_at'] === null
                && strtotime((string) $row['expires_at']) > time();
            unset($row['token_revoked_at']);
        }
        return $rows;
    }

    public function revokeForUser(int $grantId, int $userId, ?int $supplierId = null, ?string $resource = null): bool
    {
        return $this->revokeGrant($grantId, $userId, $supplierId, $resource) !== null;
    }

    /**
     * Odebrání připojení libovolného uživatele superadminem. Vrací vlastníka
     * připojení kvůli auditnímu záznamu, null když připojení neexistuje.
     */
    public function revokeAny(int $grantId): ?int
    {
        return $this->revokeGrant($grantId, null);
    }

    private function revokeGrant(int $grantId, ?int $userId, ?int $supplierId = null, ?string $resource = null): ?int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT access_token_id, user_id FROM mcp_oauth_grants WHERE id = ?'
                . ($userId !== null ? ' AND user_id = ?' : '')
                . ($supplierId !== null ? ' AND supplier_id = ?' : '')
                . ($resource !== null ? ' AND resource = ?' : '') . ' FOR UPDATE'
            );
            $stmt->execute([$grantId, ...($userId !== null ? [$userId] : []), ...($supplierId !== null ? [$supplierId] : []), ...($resource !== null ? [$resource] : [])]);
            $grant = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($grant === false) {
                $pdo->rollBack();
                return null;
            }
            $pdo->prepare('UPDATE mcp_oauth_grants SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?')
                ->execute([$grantId]);
            $pdo->prepare('UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?')
                ->execute([$grant['access_token_id']]);
            $pdo->commit();
            return (int) $grant['user_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function tokenResponse(string $access, string $refresh, string $scope): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh,
            'scope' => $scope . ' offline_access',
        ];
    }

    private static function randomToken(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
