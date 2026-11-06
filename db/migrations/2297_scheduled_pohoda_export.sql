-- MyÚčto.cz — plánovaný (automatický) export vydaných dokladů do Pohody
--
-- Firma si zapne měsíční export: v zadaný den v měsíci se doklady za uplynulý měsíc
-- vyexportují do Pohoda XML a pošlou e-mailem účetní.
--   scheduled_pohoda_export        nastavení (jeden řádek na firmu); start_month = první měsíc,
--                                  který se exportuje (před zapnutím plánu se historie neposílá)
--   scheduled_pohoda_export_run    evidence běhů (ok / queued = e-mail čeká ve frontě pošty / error)
--   scheduled_pohoda_export_doc    otisk každého dokladu v měsíci v okamžiku běhu; podle něj se pozná,
--                                  že se doklad po odeslání změnil, přibyl nebo zmizel
-- Měsíc s úspěšným během cron znovu nedělá; ruční běh nad už odeslaným měsícem musí být potvrzený.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_pohoda_export (
  supplier_id     INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled         TINYINT(1) NOT NULL DEFAULT 0,
  run_day         TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Den v měsíci 1–28, od kterého se uplynulý měsíc exportuje',
  include_credit_notes TINYINT(1) NOT NULL DEFAULT 1,
  recipient_email VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Příjemce exportu (účetní)',
  start_month     CHAR(7) NULL DEFAULT NULL COMMENT 'YYYY-MM, první exportovaný měsíc; nastaví se při zapnutí',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_spe_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_pohoda_export_run (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  period_label CHAR(7) NOT NULL COMMENT 'YYYY-MM exportovaného měsíce',
  trigger_by   ENUM('cron','manual') NOT NULL DEFAULT 'cron',
  status       ENUM('ok','queued','error') NOT NULL,
  doc_count    INT UNSIGNED NOT NULL DEFAULT 0,
  include_credit_notes TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Rozsah běhu: zahrnuty dobropisy; změny po odeslání se porovnávají ve stejném rozsahu',
  recipient_email VARCHAR(255) NOT NULL DEFAULT '',
  outbox_id    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Zpráva ve frontě pošty, dokud status = queued',
  warned_changed INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Kolik změněných dokladů už bylo oznámeno e-mailem',
  error        VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Bezpečný text bez detailů SMTP, ty jsou v protokolu aktivit',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_spe_run_supplier_period (supplier_id, period_label, status),
  KEY idx_spe_run_supplier_created (supplier_id, created_at),
  CONSTRAINT fk_spe_run_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_pohoda_export_doc (
  run_id     INT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  fp         CHAR(32) NOT NULL COMMENT 'MD5 obsahu dokladu (číslo, data, částky, položky, storno)',
  varsymbol  VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Číslo dokladu pro výpis změn v e-mailu',
  cancelled  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Doklad byl při běhu stornovaný, a tedy se neexportoval',
  PRIMARY KEY (run_id, invoice_id),
  CONSTRAINT fk_spe_doc_run FOREIGN KEY (run_id) REFERENCES scheduled_pohoda_export_run(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
