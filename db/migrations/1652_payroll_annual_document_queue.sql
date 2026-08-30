-- MyÚčto.cz — serverová fronta ROČNÍCH mzdových dokumentů.
--
-- Vlastní tabulky, ne rozšíření `payroll_document_batches` (1587). Měsíční
-- fronta je celá zavěšená na běhu a revizi: `run_id`/`revision_id` jsou NOT NULL
-- s cizími klíči, spouštěč nad dávkou vyžaduje schválenou revizi s odpovídajícím
-- otiskem a spouštěč nad položkou vyžaduje vypočtenou osobu téže revize.
-- To jsou pojistky, které měsíční pásky drží u zdroje — a roční dokumenty
-- (mzdový list, potvrzení o zdanitelných příjmech) žádnou revizi běhu nemají:
-- jejich rozsahem je zdaňovací období a zdrojový otisk vzniká až renderem.
-- Uvolnit kvůli nim sloupce na NULL a zahodit oba spouštěče by oslabilo
-- záruky, které nad výplatními páskami dnes platí.
--
-- Mechanika je naopak převzatá beze změny: pronájem přes `lease_token`,
-- počítadlo pokusů s exponenciálním odkladem, historie pokusů a stejný worker.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_annual_document_batches (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  tax_year              SMALLINT UNSIGNED NOT NULL,
  document_kind         ENUM(
                          'payroll_sheet',
                          'taxable_income_advance_certificate',
                          'taxable_income_withholding_certificate'
                        ) NOT NULL,
  -- `selected` = jedna osoba, `all` = všichni, kdo mají v roce schválený
  -- výsledek. Rozsah se ukládá kvůli zprávě v UI, cílový seznam je materializovaný
  -- v položkách — jinak by se dávka po přijetí nové osoby tiše měnila.
  scope                 ENUM('selected','all') NOT NULL,
  status                ENUM('queued','running','retry_wait','failed','completed')
                        NOT NULL DEFAULT 'queued',
  -- Logický klíč rozsahu (rok + druh + cíl). Neunikátní schválně: po dokončení
  -- dávky smí účetní tentýž rozsah spustit znovu.
  scope_key_hash        BINARY(32) NOT NULL,
  -- Unikátní klíč konkrétního spuštění. Dvojklik i zopakovaný požadavek se
  -- trefí do téhož řádku, dokud dávka běží.
  idempotency_key_hash  BINARY(32) NOT NULL,
  item_count            INT UNSIGNED NOT NULL,
  succeeded_count       INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count          INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count         INT UNSIGNED NOT NULL DEFAULT 0,
  requested_by          BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at            DATETIME NULL,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_document_batch_id (supplier_id, id),
  UNIQUE KEY uq_payroll_annual_document_batch_idempotency
    (supplier_id, idempotency_key_hash),
  KEY idx_payroll_annual_document_batch_scope
    (supplier_id, scope_key_hash, status, id),
  KEY idx_payroll_annual_document_batch_work (status, updated_at, id),
  CONSTRAINT fk_payroll_annual_document_batch_requester
    FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_annual_document_batch_year
    CHECK (tax_year BETWEEN 2000 AND 2199),
  CONSTRAINT chk_payroll_annual_document_batch_counts
    CHECK (item_count > 0
      AND succeeded_count <= item_count
      AND failed_count <= item_count
      AND skipped_count <= item_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_annual_document_batch_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  -- `skipped` je vlastní konec, ne selhání: osoba už potvrzení za rok má a jeho
  -- nahrazení je OPRAVA s povinným důvodem, který za účetní vymyslet nelze.
  status                ENUM('queued','processing','retry_wait','failed','succeeded','skipped')
                        NOT NULL DEFAULT 'queued',
  attempt_count         INT UNSIGNED NOT NULL DEFAULT 0,
  available_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_token           BINARY(16) NULL,
  locked_at             DATETIME NULL,
  document_id           BIGINT UNSIGNED NULL,
  last_error_code       VARCHAR(64) NULL,
  last_error_message    VARCHAR(500) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at          DATETIME NULL,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_document_batch_item_id (supplier_id, id),
  UNIQUE KEY uq_payroll_annual_document_batch_item_employee
    (supplier_id, batch_id, employee_id),
  KEY idx_payroll_annual_document_batch_item_work (status, available_at, id),
  KEY idx_payroll_annual_document_batch_item_batch
    (supplier_id, batch_id, status, id),
  KEY idx_payroll_annual_document_batch_item_document (supplier_id, document_id),
  CONSTRAINT fk_payroll_annual_document_batch_item_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_annual_document_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_document_batch_item_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_document_batch_item_document
    FOREIGN KEY (supplier_id, document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_annual_document_batch_item_lease
    CHECK ((status = 'processing' AND lease_token IS NOT NULL AND locked_at IS NOT NULL)
      OR (status <> 'processing' AND lease_token IS NULL AND locked_at IS NULL)),
  CONSTRAINT chk_payroll_annual_document_batch_item_result
    CHECK ((status = 'succeeded' AND document_id IS NOT NULL AND completed_at IS NOT NULL)
      OR (status <> 'succeeded' AND document_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_annual_document_batch_attempts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  item_id               BIGINT UNSIGNED NOT NULL,
  attempt_no            INT UNSIGNED NOT NULL,
  lease_token           BINARY(16) NOT NULL,
  status                ENUM('running','succeeded','failed','skipped','stale')
                        NOT NULL DEFAULT 'running',
  error_code            VARCHAR(64) NULL,
  error_message         VARCHAR(500) NULL,
  started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at           DATETIME NULL,

  UNIQUE KEY uq_payroll_annual_document_batch_attempt
    (supplier_id, item_id, attempt_no),
  KEY idx_payroll_annual_document_batch_attempt_batch
    (supplier_id, batch_id, started_at),
  CONSTRAINT fk_payroll_annual_document_batch_attempt_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_annual_document_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_document_batch_attempt_item
    FOREIGN KEY (supplier_id, item_id)
    REFERENCES payroll_annual_document_batch_items (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_annual_document_batch_attempt_no CHECK (attempt_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
