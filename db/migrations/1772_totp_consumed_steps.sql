CREATE TABLE IF NOT EXISTS totp_used_steps (
    secret_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    time_step BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (secret_fingerprint, time_step),
    KEY idx_totp_used_steps_time_step (time_step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
