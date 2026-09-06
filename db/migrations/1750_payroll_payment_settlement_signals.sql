-- MyÚčto.cz — provizorní důkaz úhrady mzdového závazku (bankovní avízo, ruční prohlášení).
--
-- Proč vedle `payroll_payment_matches`: ten je účetní platební kniha — nese
-- ověřený bankovní/pokladní důkaz, je nemazatelný, needitovatelný a vede na
-- protizápis do deníku. Avízo z e-mailu ani věta „zaplatil jsem z internetového
-- bankovnictví" takový důkaz NEJSOU: avízo je provizorní duplikát (týž pohyb
-- dorazí ještě jednou výpisem a teprve ten se účtuje) a prohlášení účetní není
-- doklad vůbec.
--
-- Zároveň ale obojí NÉST informaci má: do konce měsíce, než dorazí výpis, jinak
-- hlídač termínů tvrdí „nezaplaceno" o odvodu, který je dávno pryč z účtu.
-- Signál proto termín zhasne a v Mzdových příkazech se ukáže jako badge, ale
-- do `settled_minor` ani do salda NEVSTUPUJE — závazek zůstane otevřený, dokud
-- ho neuzavře skutečný pohyb z výpisu.
--
-- `resolved_at` vyplní rekonciliace ve chvíli, kdy závazek uzavře opravdové
-- spárování; signál pak zůstává jako auditní stopa „tohle jsme věděli dřív".

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_payment_settlement_signals (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id         INT UNSIGNED NOT NULL,
  liability_id        BIGINT UNSIGNED NOT NULL,
  origin              ENUM('bank_notice','manual') NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NULL,
  paid_on             DATE NOT NULL,
  amount_minor        BIGINT UNSIGNED NOT NULL,
  note                VARCHAR(190) NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at         TIMESTAMP NULL DEFAULT NULL,
  resolved_match_id   BIGINT UNSIGNED NULL,
  -- Jen jedno ŽIVÉ ruční prohlášení na závazek. Virtuální sloupec je jediný
  -- způsob, jak to uhlídat indexem: NULL se do UNIQUE nepočítá, takže vyřešené
  -- ani avízové řádky se navzájem neblokují.
  manual_slot         TINYINT UNSIGNED AS (
                        IF(origin = 'manual' AND resolved_at IS NULL, 1, NULL)
                      ) PERSISTENT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_settlement_signal_manual (
    supplier_id, liability_id, manual_slot
  ),
  UNIQUE KEY uq_payroll_settlement_signal_notice (
    supplier_id, liability_id, bank_transaction_id
  ),
  KEY idx_payroll_settlement_signal_open (supplier_id, liability_id, resolved_at),
  KEY idx_payroll_settlement_signal_tx (supplier_id, bank_transaction_id),
  CONSTRAINT fk_payroll_settlement_signal_liability
    FOREIGN KEY (supplier_id, liability_id)
    REFERENCES payroll_payment_liabilities (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_settlement_signal_origin CHECK (
    (origin = 'bank_notice' AND bank_transaction_id IS NOT NULL)
    OR (origin = 'manual' AND bank_transaction_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
