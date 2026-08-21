-- Krátkodobé zdrojové granty pro přenos jedné firmy mezi instancemi MyÚčta.
-- Plaintext kód se nikdy neukládá; grant_hash je binární SHA-256.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_transfer_grants (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id                       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  grant_hash                      BINARY(32) NOT NULL,
  grant_prefix                    VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_id                     INT UNSIGNED NOT NULL,
  created_by_user_id              BIGINT UNSIGNED NOT NULL,
  expires_at                      DATETIME(6) NOT NULL,
  paired_at                       DATETIME(6) NULL,
  target_instance_fingerprint     BINARY(32) NULL,
  target_payload_key_fingerprint  BINARY(32) NULL,
  consumed_at                     DATETIME(6) NULL,
  revoked_at                      DATETIME(6) NULL,
  revoked_reason                  VARCHAR(32) NULL,
  last_used_at                    DATETIME(6) NULL,
  created_at                      DATETIME(6) NOT NULL,
  UNIQUE KEY uq_tenant_transfer_grant_public_id (public_id),
  UNIQUE KEY uq_tenant_transfer_grant_hash (grant_hash),
  KEY idx_tenant_transfer_grant_expiry (expires_at, revoked_at, consumed_at),
  KEY idx_tenant_transfer_grant_supplier (supplier_id, created_at),
  KEY idx_tenant_transfer_grant_creator (created_by_user_id, created_at),
  CONSTRAINT fk_tenant_transfer_grant_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tenant_transfer_grant_user
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_tenant_transfer_grant_pairing CHECK (
    (paired_at IS NULL AND target_instance_fingerprint IS NULL AND target_payload_key_fingerprint IS NULL)
    OR (paired_at IS NOT NULL AND target_instance_fingerprint IS NOT NULL AND target_payload_key_fingerprint IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_transfer_grant_audit (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grant_id              BIGINT UNSIGNED NULL,
  supplier_id           INT UNSIGNED NULL,
  actor_user_id         BIGINT UNSIGNED NULL,
  event                 VARCHAR(32) NOT NULL,
  outcome               ENUM('allowed','rejected') NOT NULL,
  reason                VARCHAR(64) NOT NULL,
  http_method           VARCHAR(8) NOT NULL,
  endpoint              VARCHAR(190) NOT NULL,
  ip                    VARBINARY(16) NULL,
  created_at            DATETIME(6) NOT NULL,
  KEY idx_tenant_transfer_grant_audit_grant (grant_id, created_at),
  KEY idx_tenant_transfer_grant_audit_supplier (supplier_id, created_at),
  KEY idx_tenant_transfer_grant_audit_actor (actor_user_id, created_at),
  KEY idx_tenant_transfer_grant_audit_ip (ip, created_at),
  CONSTRAINT fk_tenant_transfer_grant_audit_grant
    FOREIGN KEY (grant_id) REFERENCES tenant_transfer_grants(id) ON DELETE SET NULL,
  CONSTRAINT fk_tenant_transfer_grant_audit_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE SET NULL,
  CONSTRAINT fk_tenant_transfer_grant_audit_user
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
