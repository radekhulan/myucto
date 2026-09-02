<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EpoSigningCredentialRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listOwnedForSupplier(int $ownerUserId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.label, c.fingerprint_sha256, c.subject_dn, c.issuer_dn,
                     c.serial_hex, c.valid_from, c.valid_to, c.ik_mpsv_present,
                    (SELECT COUNT(*)
                       FROM signing_credentials sc
                       JOIN signing_profiles sp ON sp.id = sc.profile_id
                      WHERE sc.vault_credential_id = c.id
                        AND sc.deleted_at IS NULL
                        AND sp.deleted_at IS NULL
                    ) AS linked_profiles_count,
                    (SELECT COUNT(*)
                       FROM signing_credentials sc
                       JOIN signing_profiles sp ON sp.id = sc.profile_id
                      WHERE sc.vault_credential_id = c.id
                        AND sp.supplier_id = ?
                        AND sc.deleted_at IS NULL
                        AND sp.deleted_at IS NULL
                    ) AS linked_supplier_profiles_count,
                    (SELECT MAX(a.tested_at)
                       FROM tax_submission_attempts a
                      WHERE a.signing_credential_id = c.id
                        AND a.channel = \'epo_direct\'
                        AND a.test_passed = 1
                        AND a.signing_fingerprint = c.fingerprint_sha256
                    ) AS epo_verified_at,
                     c.created_at, (cs.supplier_id IS NOT NULL) AS enabled_for_supplier
               FROM epo_signing_credentials c
               LEFT JOIN epo_signing_credential_suppliers cs
                 ON cs.credential_id = c.id AND cs.supplier_id = ?
              WHERE c.owner_user_id = ? AND c.deleted_at IS NULL
              ORDER BY c.valid_to DESC, c.id DESC'
        );
        $stmt->execute([$supplierId, $supplierId, $ownerUserId]);
        return array_map(
            fn (array $row): array => $this->normalizePublic($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * @param array{
     *   label:string,pfx_ciphertext:string,passphrase_ciphertext:string,
     *   fingerprint_sha256:string,subject_dn:string,issuer_dn:string,
     *   serial_hex:?string,valid_from:string,valid_to:string,ik_mpsv_present:bool
     * } $data
     */
    public function create(int $ownerUserId, array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO epo_signing_credentials
                (owner_user_id, label, pfx_ciphertext, passphrase_ciphertext,
                 fingerprint_sha256, subject_dn, issuer_dn, serial_hex,
                 valid_from, valid_to, ik_mpsv_present)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ownerUserId,
            $data['label'],
            $data['pfx_ciphertext'],
            $data['passphrase_ciphertext'],
            $data['fingerprint_sha256'],
            $data['subject_dn'],
            $data['issuer_dn'],
            $data['serial_hex'],
            $data['valid_from'],
            $data['valid_to'],
            $data['ik_mpsv_present'] ? 1 : 0,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array{
     *   label:string,pfx_ciphertext:string,passphrase_ciphertext:string,
     *   fingerprint_sha256:string,subject_dn:string,issuer_dn:string,
     *   serial_hex:?string,valid_from:string,valid_to:string,ik_mpsv_present:bool
     * } $data
     */
    public function createForSupplier(
        int $ownerUserId,
        int $supplierId,
        array $data,
    ): int {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $id = $this->create($ownerUserId, $data);
            if (!$this->setSupplierEnabled($id, $ownerUserId, $supplierId, true, $ownerUserId)) {
                throw new \RuntimeException('Credential supplier mapping failed.');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setSupplierEnabled(
        int $credentialId,
        int $ownerUserId,
        int $supplierId,
        bool $enabled,
        ?int $enabledBy,
    ): bool {
        if ($this->findOwned($credentialId, $ownerUserId) === null) {
            return false;
        }
        if ($enabled) {
            $this->db->pdo()->prepare(
                'INSERT INTO epo_signing_credential_suppliers
                    (credential_id, supplier_id, enabled_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    enabled_by = VALUES(enabled_by),
                    enabled_at = CURRENT_TIMESTAMP'
            )->execute([$credentialId, $supplierId, $enabledBy]);
        } else {
            $this->db->pdo()->prepare(
                'DELETE FROM epo_signing_credential_suppliers
                  WHERE credential_id = ? AND supplier_id = ?'
            )->execute([$credentialId, $supplierId]);
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $credentialId, int $ownerUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM epo_signing_credentials
              WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$credentialId, $ownerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeSecret($row) : null;
    }

    /**
     * Certifikát z trezoru BEZ vazby na vlastníka.
     *
     * Proč bez `owner_user_id`: systémový certifikát k datové schránce
     * a certifikát odesílací brány se odemykají i tam, kde žádný přihlášený
     * uživatel není (cron, callback brány). Vlastnictví je tu tedy nepoužitelná
     * podmínka — autorizaci nese `epo_signing_credential_suppliers`, kterou
     * ověřuje {@see isEnabledForSupplier()}, a u instalačně globální brány
     * oprávnění k jejímu nastavení.
     *
     * Měkce smazaný řádek se ZÁMĚRNĚ nevrací: odkaz na něj má skončit
     * pojmenovanou chybou volajícího, ne tichým průchodem.
     *
     * @return array<string,mixed>|null
     */
    public function findShared(int $credentialId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM epo_signing_credentials
              WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeSecret($row) : null;
    }

    /** Povolil vlastník certifikátu jeho použití pro tuhle firmu? */
    public function isEnabledForSupplier(int $credentialId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM epo_signing_credential_suppliers cs
               JOIN epo_signing_credentials c ON c.id = cs.credential_id
              WHERE cs.credential_id = ? AND cs.supplier_id = ?
                AND c.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$credentialId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function findUsable(int $credentialId, int $ownerUserId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*
               FROM epo_signing_credentials c
               JOIN epo_signing_credential_suppliers cs
                 ON cs.credential_id = c.id AND cs.supplier_id = ?
              WHERE c.id = ? AND c.owner_user_id = ? AND c.deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $credentialId, $ownerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeSecret($row) : null;
    }

    public function deleteOwned(int $credentialId, int $ownerUserId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE c
               FROM epo_signing_credentials c
              WHERE c.id = ? AND c.owner_user_id = ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM signing_credentials sc
                      JOIN signing_profiles sp ON sp.id = sc.profile_id
                     WHERE sc.vault_credential_id = c.id
                       AND sc.deleted_at IS NULL
                       AND sp.deleted_at IS NULL
                )'
        );
        $stmt->execute([$credentialId, $ownerUserId]);
        return $stmt->rowCount() > 0;
    }

    public function linkedProfileCount(int $credentialId, int $ownerUserId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM signing_credentials sc
               JOIN signing_profiles sp ON sp.id = sc.profile_id
               JOIN epo_signing_credentials c ON c.id = sc.vault_credential_id
              WHERE c.id = ? AND c.owner_user_id = ?
                AND sc.deleted_at IS NULL AND sp.deleted_at IS NULL'
        );
        $stmt->execute([$credentialId, $ownerUserId]);
        return (int) $stmt->fetchColumn();
    }

    public function linkedSupplierProfileCount(
        int $credentialId,
        int $ownerUserId,
        int $supplierId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM signing_credentials sc
               JOIN signing_profiles sp ON sp.id = sc.profile_id
               JOIN epo_signing_credentials c ON c.id = sc.vault_credential_id
              WHERE c.id = ? AND c.owner_user_id = ? AND sp.supplier_id = ?
                AND sc.deleted_at IS NULL AND sp.deleted_at IS NULL'
        );
        $stmt->execute([$credentialId, $ownerUserId, $supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function markIkMpsvPresent(int $credentialId, int $ownerUserId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE epo_signing_credentials
                SET ik_mpsv_present = 1
              WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$credentialId, $ownerUserId]);
        return $stmt->rowCount() > 0;
    }

    private function normalizePublic(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['ik_mpsv_present'] = (bool) $row['ik_mpsv_present'];
        $row['linked_profiles_count'] = (int) ($row['linked_profiles_count'] ?? 0);
        $row['linked_supplier_profiles_count'] = (int) ($row['linked_supplier_profiles_count'] ?? 0);
        $row['enabled_for_supplier'] = (bool) $row['enabled_for_supplier'];
        $row['epo_verified'] = $row['epo_verified_at'] !== null;
        $validFrom = strtotime((string) $row['valid_from']);
        $validTo = strtotime((string) $row['valid_to']);
        $row['valid_now'] = $validFrom !== false
            && $validTo !== false
            && $validFrom <= time()
            && $validTo >= time();
        return $row;
    }

    private function normalizeSecret(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['owner_user_id'] = (int) $row['owner_user_id'];
        $row['ik_mpsv_present'] = (bool) $row['ik_mpsv_present'];
        return $row;
    }
}
