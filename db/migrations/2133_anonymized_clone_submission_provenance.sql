-- Anonymizovaná kopie s finančními archivy DPPO 504: daňová podání, jejichž XML kopie
-- přepsala, nesou jen mapu transportu ze zdrojové instalace. Doklad o odeslání původního
-- XML se nesmí vydávat za doklad o odeslání pseudonymizovaného XML, proto taková podání
-- nejdou znovu odeslat ani zařadit do fronty. Podání založená až po kopii mapovaná nejsou.

ALTER TABLE tax_submissions
  ADD INDEX IF NOT EXISTS idx_tax_submission_archive_xml (supplier_id, form_code, period_year, xml_sha256, id);

CREATE TABLE IF NOT EXISTS anonymized_clone_submission_provenance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  tax_submission_id INT UNSIGNED NOT NULL,
  source_xml_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  target_xml_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transport_receipt_root CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transport_class ENUM('no_transport','cancelled_before_dispatch','source_provenance') NOT NULL,
  attempt_count INT UNSIGNED NOT NULL,
  status_event_count INT UNSIGNED NOT NULL,
  artifact_count INT UNSIGNED NOT NULL,
  outbox_count INT UNSIGNED NOT NULL,
  outbox_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_acsp_submission (supplier_id, tax_submission_id),
  KEY idx_acsp_source_xml (supplier_id, source_xml_sha256, tax_submission_id),
  KEY idx_acsp_target_xml (supplier_id, target_xml_sha256, tax_submission_id),
  CONSTRAINT fk_acsp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
  CONSTRAINT fk_acsp_submission FOREIGN KEY (tax_submission_id) REFERENCES tax_submissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
