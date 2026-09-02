<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přístupové údaje ke kanálu (migrace 1381).
 *
 * ── Jediné pravidlo, na kterém tu všechno stojí ──────────────────────────────
 * Existují DVĚ projekce a nikdy se nesmí prohodit:
 *   {@see PUBLIC_COLUMNS} — nikdy neobsahuje `*_ciphertext`; tohle jde do API,
 *   {@see SECRET_COLUMNS} — obsahuje je; volá se výhradně z odemykání.
 *
 * Je to stejná disciplína jako u `EpoSigningCredentialRepository`: maskování
 * se neřeší filtrováním pole po načtení (což se dá zapomenout), ale tím, že
 * se citlivý sloupec do dotazu vůbec nenapíše. Co se nevybere, to neunikne.
 */
final class SubmissionChannelCredentialRepository
{
    private const TABLE = 'submission_channel_credentials';

    /**
     * Platnost a otisk se čtou přes `COALESCE`: u vlastní kopie z vlastního
     * sloupce, u odkazu do sdíleného trezoru z trezoru. Kdyby se při navázání
     * zkopírovaly do řádku, rozešly by se v okamžiku, kdy uživatel certifikát
     * v trezoru obnoví — a přesně tomu má sjednocení zabránit.
     */
    private const PUBLIC_COLUMNS = 'c.id, c.supplier_id, c.environment, c.channel, c.label, c.box_id,
        c.auth_mode, c.credential_id,
        COALESCE(c.certificate_fingerprint, v.fingerprint_sha256) AS certificate_fingerprint,
        COALESCE(c.certificate_valid_to, v.valid_to) AS certificate_valid_to,
        v.label AS credential_label, v.subject_dn AS credential_subject,
        c.last_verified_at,
        c.inbox_polling_enabled, c.inbox_polling_enabled_at, c.inbox_polling_enabled_by,
        c.created_at, c.updated_at';

    private const SECRET_COLUMNS = self::PUBLIC_COLUMNS . ', c.certificate_ciphertext,
        c.certificate_passphrase_ciphertext';

    /**
     * Měkce smazaný certifikát se ZÁMĚRNĚ nepřipojuje: v kartě se pak
     * `credential_missing` rozsvítí a odemykání skončí pojmenovanou chybou,
     * místo aby se tvářilo, že je všechno v pořádku.
     */
    private const VAULT_JOIN = ' LEFT JOIN epo_signing_credentials v
              ON v.id = c.credential_id AND v.deleted_at IS NULL';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Veřejný pohled — bezpečný pro API odpověď.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublic(int $supplierId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . ' c' . self::VAULT_JOIN . '
              WHERE c.supplier_id = ? ORDER BY c.channel ASC, c.environment ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null Veřejný pohled. */
    public function findPublic(int $supplierId, string $channel, string $environment): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . ' c' . self::VAULT_JOIN . '
              WHERE c.supplier_id = ? AND c.channel = ? AND c.environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * ⚠️ Vrací ciphertexty. Volat VÝHRADNĚ z
     * {@see \MyInvoice\Service\Submission\SubmissionCredentialService::unlock()};
     * návratová hodnota nesmí opustit tu metodu.
     *
     * @return array<string,mixed>|null
     */
    public function findWithSecrets(int $supplierId, string $channel, string $environment): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::SECRET_COLUMNS . ' FROM ' . self::TABLE . ' c' . self::VAULT_JOIN . '
              WHERE c.supplier_id = ? AND c.channel = ? AND c.environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Uloží přístup. Právě jedna z cest k certifikátu smí být vyplněná —
     * hlídá to i CHECK z migrace 1711, ale tady se obě větve zapisují tak, aby
     * se nemohly potkat: navázání nuluje ciphertext, nahrání nuluje odkaz.
     * Zbytek po předchozím uložení by jinak zůstal ležet jako druhá kopie
     * klíče, o které nikdo neví.
     *
     * @param array{
     *   label:string, box_id:string,
     *   credential_id?:?int,
     *   certificate_ciphertext:?string, certificate_passphrase_ciphertext:?string,
     *   certificate_fingerprint:?string, certificate_valid_to:?string
     * } $data
     */
    public function save(int $supplierId, string $channel, string $environment, array $data, ?int $userId): void
    {
        $this->assertAvailable();
        $credentialId = isset($data['credential_id']) ? (int) $data['credential_id'] : 0;
        $credentialId = $credentialId > 0 ? $credentialId : null;
        // Historické sloupce `inbox_polling_*` se tu nenastavují. Automatické
        // vybírání bylo odstraněno; migrace 1530 případné staré volby vynuluje.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, environment, channel, label, box_id, auth_mode, credential_id,
                 certificate_ciphertext, certificate_passphrase_ciphertext,
                 certificate_fingerprint, certificate_valid_to, created_by)
             VALUES (?, ?, ?, ?, ?, \'certificate\', ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), box_id = VALUES(box_id),
                credential_id = VALUES(credential_id),
                certificate_ciphertext = VALUES(certificate_ciphertext),
                certificate_passphrase_ciphertext = VALUES(certificate_passphrase_ciphertext),
                certificate_fingerprint = VALUES(certificate_fingerprint),
                certificate_valid_to = VALUES(certificate_valid_to),
                last_verified_at = NULL'
        );
        $stmt->execute([
            $supplierId,
            $environment,
            $channel,
            $data['label'],
            $data['box_id'],
            $credentialId,
            $credentialId !== null ? null : $data['certificate_ciphertext'],
            $credentialId !== null ? null : $data['certificate_passphrase_ciphertext'],
            $credentialId !== null ? null : $data['certificate_fingerprint'],
            $credentialId !== null ? null : $data['certificate_valid_to'],
            $userId,
        ]);
    }

    public function delete(int $supplierId, string $channel, string $environment): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE supplier_id = ? AND channel = ? AND environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        return $stmt->rowCount() > 0;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Trezor přístupů ke kanálům není k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['inbox_polling_enabled'] = (bool) $row['inbox_polling_enabled'];
        $row['inbox_polling_enabled_by'] = $row['inbox_polling_enabled_by'] !== null
            ? (int) $row['inbox_polling_enabled_by']
            : null;
        $credentialId = ($row['credential_id'] ?? null) !== null ? (int) $row['credential_id'] : null;
        $row['credential_id'] = $credentialId;
        // Odkaz vede do prázdna — certifikát někdo z trezoru smazal. Karta to
        // musí říct dřív, než to řekne odmítnuté podání.
        $row['credential_missing'] = $credentialId !== null && ($row['credential_label'] ?? null) === null;
        return $row;
    }
}
