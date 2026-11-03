<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\OAuth\Pkce;

/**
 * Rozběhnuté přihlášení u poskytovatele (`oidc_login_flows`). Do databáze jde
 * jen hash `state`; záznam je jednorázový, platí deset minut a je vázaný na
 * cookie prohlížeče, který přihlášení zahájil.
 */
class OidcFlowStore
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_LINK = 'link';
    public const PURPOSE_STEP_UP = 'stepup';

    private const PURPOSES = [self::PURPOSE_LOGIN, self::PURPOSE_LINK, self::PURPOSE_STEP_UP];
    private const TTL_SECONDS = 600;

    public function __construct(private readonly Connection $db) {}

    public static function isBindingShape(?string $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function newBinding(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array{state:string,nonce:string,code_verifier:string}
     */
    public function create(
        string $provider,
        string $purpose,
        #[\SensitiveParameter] string $browserBinding,
        ?string $returnTo,
        ?int $userId = null,
        #[\SensitiveParameter] ?string $sessionToken = null,
        ?string $stepUpOperation = null,
        ?int $inviteId = null,
    ): array {
        if (!in_array($purpose, self::PURPOSES, true) || !self::isBindingShape($browserBinding)) {
            throw new \InvalidArgumentException('Neplatné zahájení přihlášení.');
        }
        if ($purpose !== self::PURPOSE_LOGIN && ($userId === null || $sessionToken === null)) {
            throw new \InvalidArgumentException('Propojení a ověření vyžadují přihlášeného uživatele.');
        }
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = Pkce::verifier();

        $this->db->pdo()->prepare(
            'INSERT INTO oidc_login_flows
                (id, provider, purpose, nonce, code_verifier, browser_binding, return_to,
                 user_id, session_id_hash, step_up_operation, invite_id, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6),
                     UTC_TIMESTAMP(6) + INTERVAL ' . self::TTL_SECONDS . ' SECOND)'
        )->execute([
            hash('sha256', $state),
            $provider,
            $purpose,
            $nonce,
            $verifier,
            hash('sha256', $browserBinding),
            $returnTo,
            $userId,
            $sessionToken !== null ? hash('sha256', $sessionToken) : null,
            $stepUpOperation,
            $inviteId,
        ]);

        return ['state' => $state, 'nonce' => $nonce, 'code_verifier' => $verifier];
    }

    /**
     * Atomicky spotřebuje záznam. Ukradený `state` bez cookie původního
     * prohlížeče neprojde a druhé použití téhož `state` taky ne.
     */
    public function consume(string $provider, #[\SensitiveParameter] string $state, #[\SensitiveParameter] ?string $browserBinding): OidcFlow
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $state) !== 1 || !self::isBindingShape($browserBinding)) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Přihlášení vypršelo nebo bylo zahájeno v jiném prohlížeči.');
        }
        $id = hash('sha256', $state);
        $pdo = $this->db->pdo();
        $update = $pdo->prepare(
            'UPDATE oidc_login_flows
                SET consumed_at = UTC_TIMESTAMP(6)
              WHERE id = ? AND provider = ? AND browser_binding = ?
                AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)'
        );
        $update->execute([$id, $provider, hash('sha256', (string) $browserBinding)]);
        if ($update->rowCount() !== 1) {
            throw new OidcException(OidcException::FLOW_INVALID, 'Přihlášení vypršelo nebo bylo zahájeno v jiném prohlížeči.');
        }
        $stmt = $pdo->prepare(
            'SELECT provider, purpose, nonce, code_verifier, return_to, user_id,
                    session_id_hash, step_up_operation, invite_id
               FROM oidc_login_flows WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new OidcException(OidcException::FLOW_INVALID);
        }
        return new OidcFlow(
            provider: (string) $row['provider'],
            purpose: (string) $row['purpose'],
            nonce: (string) $row['nonce'],
            codeVerifier: (string) $row['code_verifier'],
            returnTo: $row['return_to'] !== null ? (string) $row['return_to'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            sessionIdHash: $row['session_id_hash'] !== null ? (string) $row['session_id_hash'] : null,
            stepUpOperation: $row['step_up_operation'] !== null ? (string) $row['step_up_operation'] : null,
            inviteId: $row['invite_id'] !== null ? (int) $row['invite_id'] : null,
        );
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM oidc_login_flows WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY LIMIT 5000'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
