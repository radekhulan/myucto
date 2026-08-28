-- MyÚčto.cz — W20: KDO drží nárok na zákonný příplatek § 114 až § 118 ZP
-- za daný pracovní vztah a měsíc — docházka, nebo ruční měsíční zadání.
--
-- PROČ TABULKA A NE JEN DOTAZ PŘED ZÁPISEM
--
-- Od W20 vedou k témuž nároku DVĚ cesty: materializace ze schválené docházky
-- (`PayrollSurchargeInputMaterializer`) a ruční zadání hodin v rychlém měsíčním
-- vstupu (`PayrollQuickInputRepository`). Kdyby se obě uplatnily na týž druh
-- příplatku a týž měsíc, zaměstnanec dostane zaplaceno dvakrát — a protože obě
-- částky vypadají věrohodně, pozná se to až při kontrole.
--
-- Obě strany si to hlídají i dotazem („existuje už vstup té druhé cesty?"), ale
-- SELECT následovaný INSERTem je klasické okno: schválení docházky a uložení
-- rychlého vstupu jsou dvě různé transakce, každá si přečte, že ta druhá ještě
-- nic nezapsala, a obě zapíšou. Zamykají přitom různé řádky — materializace
-- `payroll_time_months`, rychlé zadání `payroll_employments` — takže je nic
-- neserializuje.
--
-- Unikátní klíč přes (firma, vztah, období, druh příplatku) tohle okno zavírá
-- na úrovni databáze. Druhý zapisovatel dostane porušení integrity, ne tichý
-- duplikát, a hlásit se pak dá KTERÝ druh a ODKUD koliduje.
--
-- CO TU NENÍ
--
-- Žádné částky, žádné hodiny a ani odkaz na mzdový vstup. Částky patří do
-- `payroll_inputs` a do ledgeru materializací (migrace 1627); držet je i tady
-- by znamenalo dvě pravdy o téže koruně. Odkaz na vstup by navíc lhal: druh
-- příplatku má za měsíc jeden nárok, ale klidně několik vstupů za sebou
-- (původní zápis a k němu opravy). Tahle tabulka drží JEDINOU věc: kdo si druh
-- za daný měsíc nárokuje.
--
-- PROČ SE ŘÁDEK SMÍ MAZAT
--
-- Na rozdíl od ledgeru materializací tu nejde o auditní stopu splněného nároku
-- (tu drží `payroll_surcharge_input_materializations` a `payroll_inputs`), ale
-- o aktuální stav vlastnictví. Vyprázdní-li uživatel v rychlém zadání hodiny,
-- svůj nárok pouští a docházka ho smí převzít; kdyby řádek zůstal, měsíc by už
-- nešlo z docházky doplnit vůbec. Kdo a kdy nárok držel, zůstává čitelné
-- z auditní stopy mzdových vstupů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_surcharge_period_claims (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  employment_id   BIGINT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  -- Shodné s `PayrollSurchargeKind` i s kategorií docházky
  -- (`payroll_time_entries.category`, migrace 1201).
  surcharge_kind  ENUM('overtime','holiday','night','weekend',
                       'difficult_environment') NOT NULL,
  -- `time`  = nárok vznikl materializací ze schválené docházky
  -- `manual` = nárok zadal uživatel v rychlém měsíčním vstupu
  -- Hodnoty schválně odpovídají `payroll_inputs.source_kind`, ať se nemusí
  -- překládat mezi dvěma číselníky.
  claim_source    ENUM('time','manual') NOT NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

  -- Jádro celé migrace: druh příplatku má za měsíc nejvýš jednoho vlastníka.
  UNIQUE KEY uq_payroll_surcharge_claim_scope
    (supplier_id, employment_id, period_start, surcharge_kind),
  UNIQUE KEY uq_payroll_surcharge_claim_supplier_id (supplier_id, id),
  KEY idx_payroll_surcharge_claim_period (supplier_id, period_start, claim_source),
  CONSTRAINT fk_payroll_surcharge_claim_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_surcharge_claim_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí u CHECK `IF NOT EXISTS`, takže se nejdřív zahazuje.
ALTER TABLE payroll_surcharge_period_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_claim_period;
ALTER TABLE payroll_surcharge_period_claims
  ADD CONSTRAINT chk_payroll_surcharge_claim_period
  CHECK (DAY(period_start) = 1);

ALTER TABLE payroll_surcharge_period_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_claim_row_version;
ALTER TABLE payroll_surcharge_period_claims
  ADD CONSTRAINT chk_payroll_surcharge_claim_row_version
  CHECK (row_version > 0);
