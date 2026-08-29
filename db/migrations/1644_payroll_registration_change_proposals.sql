-- MyÚčto.cz — detekce změn hlásitelných do registru pojištěnců (REGZEC A3).
--
-- Registrační události dosud vznikaly jen ručně: kdo změnil adresu nebo druh
-- činnosti, nedostal od aplikace ani upozornění, že to má do osmi dnů ohlásit
-- (§ 19 odst. 5 zákona č. 323/2025 Sb.). Tahle tabulka drží NÁVRH povinnosti,
-- kterou detekce našla, spolu s jejím termínem — aby lhůta měla kde běžet
-- i tehdy, když ji aplikace sama odbavit neumí.
--
-- Co tu ZÁMĚRNĚ není: hodnoty změněných údajů. `findings_json` nese jen cesty,
-- skupiny a kód akce. Osobní údaje mají svoje šifrované úložiště
-- (`payroll_registration_a1_profiles`, `payroll_person_identity_history`)
-- a kopírovat je sem v otevřené podobě jen kvůli tomu, aby se rychleji
-- vypisoval seznam, by z hlídače lhůt udělalo druhou, nechráněnou kartotéku.
-- Hodnoty se dopočítají živě při čtení.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_registration_change_proposals (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  -- Změna kódu zdravotní pojišťovny zakládá DVĚ povinnosti: hlášení do
  -- registru pojištěnců i oznámení oběma pojišťovnám podle § 10 odst. 1
  -- písm. b) zákona č. 48/1997 Sb. JMHZ tu druhou nenahrazuje, proto je to
  -- samostatný řádek s vlastním termínem, ne poznámka pod čarou.
  duty_kind             VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_code           TINYINT UNSIGNED NULL,
  baseline_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  current_fingerprint   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  detected_on           DATE NOT NULL,
  due_on                DATE NOT NULL,
  deadline_ruleset_id   VARCHAR(96) NOT NULL,
  deadline_source       VARCHAR(255) NOT NULL,
  findings_json         LONGTEXT NOT NULL CHECK (JSON_VALID(findings_json)),
  status                ENUM('open','filed','dismissed','superseded')
                          NOT NULL DEFAULT 'open',
  resolution_note       VARCHAR(500) NULL,
  resolved_event_id     BIGINT UNSIGNED NULL,
  resolved_by           BIGINT UNSIGNED NULL,
  resolved_at           DATETIME NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_registration_change_proposal_supplier_id
    (supplier_id, id),
  -- Idempotence návrhů: tentýž rozešlý stav téhož vztahu smí mít nejvýše jeden
  -- návrh na povinnost. Bez tohohle klíče by každé otevření karty založilo
  -- další kopii téže lhůty.
  UNIQUE KEY uq_payroll_registration_change_proposal_state (
    supplier_id, environment, employment_id, duty_kind, current_fingerprint
  ),
  KEY idx_payroll_registration_change_proposal_due (
    supplier_id, environment, status, due_on, id
  ),
  KEY idx_payroll_registration_change_proposal_employment (
    supplier_id, environment, employment_id, status, id
  ),
  CONSTRAINT fk_payroll_registration_change_proposal_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_change_proposal_event
    FOREIGN KEY (supplier_id, resolved_event_id)
    REFERENCES payroll_registration_event_snapshots (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_change_proposal_resolver
    FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_registration_change_proposal_duty CHECK (
    duty_kind IN ('regzec_change','health_insurer_change')
  ),
  CONSTRAINT chk_payroll_registration_change_proposal_action CHECK (
    (duty_kind = 'regzec_change' AND action_code BETWEEN 3 AND 7)
    OR (duty_kind = 'health_insurer_change' AND action_code IS NULL)
  ),
  CONSTRAINT chk_payroll_registration_change_proposal_hashes CHECK (
    baseline_fingerprint REGEXP '^[0-9a-f]{64}$'
    AND current_fingerprint REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_registration_change_proposal_resolution CHECK (
    (status IN ('open','superseded') AND resolved_at IS NULL)
    OR (status IN ('filed','dismissed') AND resolved_at IS NOT NULL)
  ),
  CONSTRAINT chk_payroll_registration_change_proposal_dates CHECK (
    due_on >= detected_on
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vodoznak posledního proběhlého porovnání.
--
-- Bez něj by hlídač termínů musel při každém otevření DEŠIFROVAT profil
-- a identitu všech zaměstnanců — u firmy s pěti sty lidmi pět set
-- dešifrování na jedno zobrazení dashboardu. Vodoznak se přitom dá spočítat
-- čistě v SQL z `id` a `row_version` zdrojových tabulek, takže se opravdu
-- porovnává jen to, co se mezitím pohnulo.
--
-- Proč `MAX(id)` I `SUM(row_version)`: přírůstkové tabulky (profil A1,
-- historie identity) se mění zakládáním řádků, takže se hne maximum `id`;
-- tabulky měněné na místě (identifikátory, podmínky vztahu) hýbou verzí.
-- Jedno bez druhého by změnu propáslo.
CREATE TABLE IF NOT EXISTS payroll_registration_change_scans (
  supplier_id       INT UNSIGNED NOT NULL,
  environment       ENUM('production','test') NOT NULL,
  employment_id     BIGINT UNSIGNED NOT NULL,
  source_watermark  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  baseline_snapshot_id BIGINT UNSIGNED NULL,
  scanned_at        DATETIME NOT NULL,

  PRIMARY KEY (supplier_id, environment, employment_id),
  CONSTRAINT fk_payroll_registration_change_scan_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_registration_change_scan_watermark CHECK (
    source_watermark REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
