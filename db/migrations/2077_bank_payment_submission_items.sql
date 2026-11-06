-- Odeslání příkazu, které banka zakládá po jednotlivých platbách (Česká spořitelna,
-- Premium Payments v1). API banky nemá idempotenční klíč, proto se každá položka
-- eviduje dřív, než se pošle: po pádu nebo timeoutu se pokračuje jen z evidence
-- a položka s nejasným výsledkem se znovu neposílá.
--
-- Stavy odeslání: creating (položky se zakládají), awaiting_signature (dávka čeká
-- na podpis v bance), signed (podepsáno), completed (všechny platby provedeny),
-- partially_rejected (část plateb banka odmítla), expired (bez podpisu propadlo).
SET NAMES utf8mb4;

ALTER TABLE bank_payment_order_submissions
    MODIFY COLUMN status ENUM('accepted_awaiting_authorization','rejected','unknown','import_started',
        'creating','awaiting_signature','signed','completed','partially_rejected','expired') NOT NULL,
    ADD COLUMN IF NOT EXISTS provider_sign_hash VARCHAR(128) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS provider_poll_id VARCHAR(64) NULL AFTER provider_sign_hash,
    ADD COLUMN IF NOT EXISTS signature_state VARCHAR(40) NULL AFTER provider_poll_id,
    ADD COLUMN IF NOT EXISTS signature_requested_at DATETIME NULL AFTER signature_state,
    ADD COLUMN IF NOT EXISTS last_polled_at DATETIME NULL AFTER signature_requested_at,
    ADD COLUMN IF NOT EXISTS next_poll_at DATETIME NULL AFTER last_polled_at,
    ADD KEY IF NOT EXISTS idx_bank_submission_poll (provider, status, next_poll_at);

CREATE TABLE IF NOT EXISTS bank_payment_submission_items (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id              BIGINT UNSIGNED NOT NULL,
    supplier_id                INT UNSIGNED NOT NULL,
    line_no                    SMALLINT UNSIGNED NOT NULL,
    instruction_identification VARCHAR(35) NOT NULL,
    amount_minor               BIGINT UNSIGNED NOT NULL,
    creditor_iban              VARCHAR(34) NOT NULL,
    variable_symbol            VARCHAR(10) NULL,
    status                     ENUM('planned','sending','created','rejected','unknown') NOT NULL DEFAULT 'planned',
    provider_sign_id           VARCHAR(64) NULL,
    provider_hash              VARCHAR(128) NULL,
    instruction_status         VARCHAR(8) NULL,
    status_change_info         VARCHAR(64) NULL,
    error_code                 VARCHAR(80) NULL,
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bank_submission_item_line (submission_id, line_no),
    KEY idx_bank_submission_item_supplier (supplier_id, submission_id),
    CONSTRAINT fk_bank_submission_item_submission
        FOREIGN KEY (submission_id) REFERENCES bank_payment_order_submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
