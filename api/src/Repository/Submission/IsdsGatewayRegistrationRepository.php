<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Registrace odesílací brány ISDS u provozovatele (migrace 1411).
 *
 * Dvě projekce schválně:
 *   {@see PUBLIC_COLUMNS} ciphertext vůbec nevybírá a je to jediné, co smí
 *   ven do API — stejný vzor jako u `submission_channel_credentials`,
 *   {@see SECRET_COLUMNS} se používá jen v okamžiku volání brány.
 */
final class IsdsGatewayRegistrationRepository
{
    private const TABLE = 'isds_gateway_registrations';

    /**
     * Otisk a platnost přes `COALESCE`: vlastní kopie ze svého sloupce, odkaz
     * do sdíleného trezoru z trezoru (migrace 1711). Kopírovat je do řádku by
     * znamenalo druhou platnost, která se při obnově certifikátu rozejde.
     */
    private const PUBLIC_COLUMNS = 'g.id, g.environment, g.ats_id, g.label, g.return_url, g.error_url,
        g.concept_ttl_seconds, g.portal_host, g.service_host, g.user_login_policy, g.credential_id,
        COALESCE(g.certificate_fingerprint, v.fingerprint_sha256) AS certificate_fingerprint,
        COALESCE(g.certificate_valid_to, v.valid_to) AS certificate_valid_to,
        v.label AS credential_label, v.subject_dn AS credential_subject,
        g.is_active, g.created_by, g.created_at, g.updated_at';

    private const SECRET_COLUMNS = self::PUBLIC_COLUMNS
        . ', g.certificate_ciphertext, g.certificate_passphrase_ciphertext';

    /** Měkce smazaný certifikát se ZÁMĚRNĚ nepřipojuje — viz `credential_missing`. */
    private const VAULT_JOIN = ' LEFT JOIN epo_signing_credentials v
              ON v.id = g.credential_id AND v.deleted_at IS NULL';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return list<array<string,mixed>> Bez tajných hodnot — bezpečné pro API. */
    public function listPublic(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->db->pdo()->query(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . ' g' . self::VAULT_JOIN . '
              ORDER BY g.environment'
        );

        return $stmt === false ? [] : array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null Bez tajných hodnot. */
    public function findPublic(string $environment): ?array
    {
        return $this->fetch(self::PUBLIC_COLUMNS, $environment);
    }

    /**
     * ⚠️ Vrací ciphertext certifikátu. Volá se JEDINĚ z
     * {@see \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService::load()},
     * který ho hned promění v {@see \MyInvoice\Service\Submission\Channel\SensitiveValue}.
     *
     * @return array<string,mixed>|null
     */
    public function findWithSecrets(string $environment): ?array
    {
        return $this->fetch(self::SECRET_COLUMNS, $environment);
    }

    /** @param array<string,mixed> $data */
    public function save(string $environment, array $data, ?int $userId): void
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (environment, ats_id, label, return_url, error_url, concept_ttl_seconds,
                 portal_host, service_host, user_login_policy,
                 certificate_ciphertext, certificate_passphrase_ciphertext,
                 certificate_fingerprint, certificate_valid_to, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                -- Nahrání souboru ruší případný odkaz do sdíleného trezoru.
                -- Dvě zdrojové cesty naráz by znamenaly, že nikdo neví, kterým
                -- certifikátem se koncept odeslal.
                credential_id = NULL,
                ats_id = VALUES(ats_id), label = VALUES(label),
                return_url = VALUES(return_url), error_url = VALUES(error_url),
                concept_ttl_seconds = VALUES(concept_ttl_seconds),
                portal_host = VALUES(portal_host), service_host = VALUES(service_host),
                user_login_policy = VALUES(user_login_policy),
                certificate_ciphertext = VALUES(certificate_ciphertext),
                certificate_passphrase_ciphertext = VALUES(certificate_passphrase_ciphertext),
                certificate_fingerprint = VALUES(certificate_fingerprint),
                certificate_valid_to = VALUES(certificate_valid_to),
                is_active = VALUES(is_active)'
        );
        $stmt->execute([
            $environment,
            $data['ats_id'],
            $data['label'],
            $data['return_url'],
            $data['error_url'],
            $data['concept_ttl_seconds'],
            $data['portal_host'],
            $data['service_host'],
            $data['user_login_policy'],
            $data['certificate_ciphertext'],
            $data['certificate_passphrase_ciphertext'],
            $data['certificate_fingerprint'],
            $data['certificate_valid_to'],
            $data['is_active'] ? 1 : 0,
            $userId,
        ]);
    }

    public function setActive(string $environment, bool $active): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET is_active = ? WHERE environment = ?'
        );
        $stmt->execute([$active ? 1 : 0, $environment]);

        return $stmt->rowCount() > 0;
    }

    public function delete(string $environment): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . self::TABLE . ' WHERE environment = ?');
        $stmt->execute([$environment]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    private function fetch(string $columns, string $environment): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $columns . ' FROM ' . self::TABLE . ' g' . self::VAULT_JOIN . '
              WHERE g.environment = ?'
        );
        $stmt->execute([$environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['concept_ttl_seconds'] = (int) $row['concept_ttl_seconds'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['created_by'] = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        $credentialId = ($row['credential_id'] ?? null) !== null ? (int) $row['credential_id'] : null;
        $row['credential_id'] = $credentialId;
        $row['credential_missing'] = $credentialId !== null && ($row['credential_label'] ?? null) === null;

        return $row;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Tabulka ' . self::TABLE . ' neexistuje — spusťte migrace.');
        }
    }
}
