-- MyÚčto.cz — Kniha jízd: automatický stav tachometru ze zdrojů (automobilky, služby).
--
--   odometer_source_accounts  napojení = účet u zdroje: jedny přístupy (jeden řetěz
--                             refresh tokenů) a denní limit volání pro všechna auta účtu.
--                             `provider` = kód zdroje (OdometerSourceProvider::code()),
--                             platné kódy hlídá OdometerSourceRegistry. Přístupy
--                             i rozpracované přihlášení jsou výhradně context-bound enc:v2
--                             ciphertext (SecretEncryption::encryptFor). `pending_state_hash`
--                             je SHA-256 hodnoty state, kterou generuje jádro (pro OAuth callback).
--   odometer_source_cars      auta knihy jízd přiřazená k účtu; `vehicle_ref` = identifikátor
--                             auta u zdroje (obvykle VIN). Jedno přiřazení na auto.
--   odometer_readings.source  'manual' nebo kód zdroje; denní stav se ukládá vedle ručního
--                             (UNIQUE car_id, reading_date, source).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS; MODIFY COLUMN je sám o sobě opakovatelný.

SET NAMES utf8mb4;

ALTER TABLE odometer_readings
    MODIFY COLUMN source VARCHAR(32) NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS odometer_source_accounts (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id            INT UNSIGNED NOT NULL,
    provider               VARCHAR(32) NOT NULL,
    status                 ENUM('pending','active','error','disconnected') NOT NULL DEFAULT 'pending',
    credentials_ciphertext TEXT NULL,

    pending_ciphertext     TEXT NULL,
    pending_state_hash     CHAR(64) NULL,
    pending_car_id         INT UNSIGNED NULL,
    pending_expires_at     DATETIME NULL,

    calls_day              DATE NULL,
    calls_used             SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    last_sync_at           DATETIME NULL,
    last_sync_status       ENUM('success','error') NULL,
    last_sync_error_code   VARCHAR(64) NULL,

    created_by             BIGINT UNSIGNED NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_odometer_source_account_state (pending_state_hash),
    KEY idx_odometer_source_account_supplier (supplier_id, status),
    KEY idx_odometer_source_account_pending_car (pending_car_id),

    CONSTRAINT fk_odometer_source_account_supplier    FOREIGN KEY (supplier_id)    REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_odometer_source_account_pending_car FOREIGN KEY (pending_car_id) REFERENCES cars(id)     ON DELETE SET NULL,
    CONSTRAINT fk_odometer_source_account_user        FOREIGN KEY (created_by)     REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS odometer_source_cars (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id            INT UNSIGNED NOT NULL,
    account_id             INT UNSIGNED NOT NULL,
    car_id                 INT UNSIGNED NOT NULL,
    vehicle_ref            VARCHAR(64) NOT NULL,

    last_sync_at           DATETIME NULL,
    last_sync_status       ENUM('success','warning','error') NULL,
    last_sync_error_code   VARCHAR(64) NULL,
    last_odometer          INT UNSIGNED NULL,
    last_value_at          DATETIME NULL,

    created_by             BIGINT UNSIGNED NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_odometer_source_car (car_id),
    UNIQUE KEY uq_odometer_source_car_vehicle (account_id, vehicle_ref),
    KEY idx_odometer_source_car_supplier (supplier_id, account_id),

    CONSTRAINT fk_odometer_source_car_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)                 ON DELETE CASCADE,
    CONSTRAINT fk_odometer_source_car_account  FOREIGN KEY (account_id)  REFERENCES odometer_source_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_odometer_source_car_car      FOREIGN KEY (car_id)      REFERENCES cars(id)                     ON DELETE CASCADE,
    CONSTRAINT fk_odometer_source_car_user     FOREIGN KEY (created_by)  REFERENCES users(id)                    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
