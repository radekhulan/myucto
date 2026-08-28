-- MyÚčto.cz — W19: převod vypočtených zákonných příplatků § 114 až § 118 ZP
-- do kanonických mzdových vstupů, auditovatelně a bez možnosti zdvojení.
--
-- PROČ VLASTNÍ LEDGER A NE JEN `payroll_inputs`
--
-- Výpočet příplatku (`PayrollSurchargeService`) je čistá funkce evidence
-- docházky, sjednané zásady a průměrného výdělku. Sám o sobě nic neplatí.
-- Mezi „spočítáno" a „vyplaceno" ale leží tři skutečnosti, které mzdový vstup
-- neunese a bez kterých se nedá poznat zdvojení:
--
--   1. Z JAKÉHO PODKLADU vstup vznikl (`evidence_hash`). Bez otisku podkladu
--      nejde odlišit „docházka se nezměnila, jen se materializace pustila
--      podruhé" od „docházka se změnila a nárok je jiný". První případ musí být
--      no-op, druhý musí vyrobit OPRAVU. Rozhodovat to podle částky nestačí:
--      dvě různé evidence mohou dát tutéž částku.
--   2. KOLIK UŽ JE ZA MĚSÍC A DRUH PŘÍPLATKU VYPLACENO (`cumulative_minor`).
--      Oprava po změně docházky nesmí zapsat novou PLNOU částku — to by
--      zaměstnanci zaplatilo příplatek dvakrát. Zapisuje se ROZDÍL proti tomu,
--      co už je schválené, a kumulativ je jediné místo, kde se ten rozdíl dá
--      spolehlivě určit i po několika opravách za sebou.
--   3. NAD KTEROU REVIZÍ DOCHÁZKY se rozhodovalo (`time_month_revision_no`).
--      Znovuotevřený a znovu schválený měsíc je nová právní skutečnost a musí
--      být v auditní stopě vidět, i když se částka nezměnila.
--
-- PROČ SE NIC NEPŘEPISUJE A NEMAŽE
--
-- Stejně jako u náhrady mzdy při DPN (migrace 1596): už vyplacený příplatek je
-- splněný zákonný nárok a jeho stopa se nesmí ztratit. Snížení nároku se proto
-- řeší DALŠÍM řádkem se zápornou částkou (`correction`, resp. `reversal`, když
-- nárok klesl na nulu), ne opravou předchozího. Zápis je append-only a hlídají
-- to triggery, ne jen dobrá vůle aplikace.
--
-- PROČ `sequence_no` A NE `materialization_kind` V UNIKÁTNÍM KLÍČI
--
-- U DPN stačil klíč (událost, období, druh), protože reverz je nejvýš jeden.
-- Tady jich může být libovolně mnoho: docházka se dá znovu otevřít a opravit
-- opakovaně a každé kolo vyrábí vlastní rozdíl. Pořadové číslo v rámci
-- (vztah, období, druh příplatku) proto drží posloupnost oprav a zároveň dělá
-- z opakovaného zápisu chybu integrity, ne tichý duplikát.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_surcharge_input_materializations (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  employment_id                 BIGINT UNSIGNED NOT NULL,
  period_start                  DATE NOT NULL,
  -- Hodnota je shodná s `PayrollSurchargeKind` i s kategorií docházky
  -- (`payroll_time_entries.category`, migrace 1201). Překlad mezi číselníky by
  -- byl místo, kde se dá ztratit hodina.
  surcharge_kind                ENUM('overtime','holiday','night','weekend',
                                     'difficult_environment') NOT NULL,
  sequence_no                   INT UNSIGNED NOT NULL,
  materialization_kind          ENUM('original','correction','reversal') NOT NULL,
  time_month_revision_no        INT UNSIGNED NOT NULL,
  input_id                      BIGINT UNSIGNED NOT NULL,
  -- Částka TOHOTO vstupu. U opravy je to rozdíl, tedy klidně záporná.
  amount_minor                  BIGINT NOT NULL,
  -- Kolik je po tomto řádku za druh příplatku a měsíc celkem vyplaceno.
  -- Nezáporná: víc než celý nárok se vrátit nedá a míň než nula se vyplatit nedá.
  cumulative_minor              BIGINT NOT NULL,
  evidence_hash                 BINARY(32) NOT NULL,
  supersedes_materialization_id BIGINT UNSIGNED NULL,
  source_snapshot_json          LONGTEXT NOT NULL
                                  CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash          BINARY(32) NOT NULL,
  created_by                    BIGINT UNSIGNED NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_surcharge_materialization_scope
    (supplier_id, employment_id, period_start, surcharge_kind, sequence_no),
  UNIQUE KEY uq_payroll_surcharge_materialization_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_surcharge_materialization_input (supplier_id, input_id),
  KEY idx_payroll_surcharge_materialization_supersedes
    (supplier_id, supersedes_materialization_id),
  CONSTRAINT fk_payroll_surcharge_materialization_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_surcharge_materialization_input
    FOREIGN KEY (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_surcharge_materialization_supersedes
    FOREIGN KEY (supplier_id, supersedes_materialization_id)
    REFERENCES payroll_surcharge_input_materializations (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_surcharge_materialization_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí u CHECK `IF NOT EXISTS`, takže se nejdřív zahazuje.
ALTER TABLE payroll_surcharge_input_materializations
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_materialization_period;
ALTER TABLE payroll_surcharge_input_materializations
  ADD CONSTRAINT chk_payroll_surcharge_materialization_period
  CHECK (DAY(period_start) = 1);

ALTER TABLE payroll_surcharge_input_materializations
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_materialization_sequence;
ALTER TABLE payroll_surcharge_input_materializations
  ADD CONSTRAINT chk_payroll_surcharge_materialization_sequence
  CHECK (sequence_no >= 1);

-- Kumulativ nesmí klesnout pod nulu ani u opravy: příplatek, který nikdy
-- nevznikl, nejde vzít zpátky.
ALTER TABLE payroll_surcharge_input_materializations
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_materialization_cumulative;
ALTER TABLE payroll_surcharge_input_materializations
  ADD CONSTRAINT chk_payroll_surcharge_materialization_cumulative
  CHECK (cumulative_minor >= 0);

-- První řádek je vždy `original` s kladnou částkou a bez předchůdce; každý
-- další je oprava a předchůdce mít MUSÍ, jinak by z ledgeru nešlo přečíst,
-- proti čemu se rozdíl počítal.
ALTER TABLE payroll_surcharge_input_materializations
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_materialization_direction;
ALTER TABLE payroll_surcharge_input_materializations
  ADD CONSTRAINT chk_payroll_surcharge_materialization_direction
  CHECK (
    (materialization_kind = 'original'
      AND sequence_no = 1
      AND supersedes_materialization_id IS NULL
      AND amount_minor > 0)
    OR
    (materialization_kind IN ('correction', 'reversal')
      AND sequence_no > 1
      AND supersedes_materialization_id IS NOT NULL
      AND amount_minor <> 0)
  );

-- Reverz je oprava, která nárok srazila na nulu. Rozlišuje se proto, aby se
-- v přehledu poznalo „nárok zanikl" od „nárok se změnil".
ALTER TABLE payroll_surcharge_input_materializations
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_materialization_reversal;
ALTER TABLE payroll_surcharge_input_materializations
  ADD CONSTRAINT chk_payroll_surcharge_materialization_reversal
  CHECK (
    materialization_kind <> 'reversal'
    OR (cumulative_minor = 0 AND amount_minor < 0)
  );

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_surcharge_materialization_immutable_update
BEFORE UPDATE ON payroll_surcharge_input_materializations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll surcharge input materializations are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_surcharge_materialization_immutable_delete
BEFORE DELETE ON payroll_surcharge_input_materializations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll surcharge input materializations are append-only';
END//

DELIMITER ;
