-- Převzaté mzdy ze zdroje `jmhz`: úhrny měsíce z přijatých měsíčních hlášení JMHZ
-- předchozího mzdového programu.
--
-- PROČ vlastní zdroj a ne `other`: hlášení nese úhrny po vztazích a měsících stejně
-- jako tabulkový export, ale jiným programem a s jinou úplností (zdravotní vyměřovací
-- základ ani srážky v něm nejsou). Kontrolní sestava i přehled převzatých mezd tak
-- ukážou, odkud měsíc přišel, a import z hlášení nepřepíše řádek, který účetní
-- nahrála tabulkou (UNIQUE je po zdrojích).
--
-- ENUM musí zůstat v souladu s `PayrollMigrationReferenceTotalsWriter::SOURCES`
-- (hlídá `PayrollEnumContractTest`). MODIFY COLUMN je z podstaty opakovatelný.

SET NAMES utf8mb4;

-- Zachovat také zdroj z pracovní verze importu Stereo NX: tato starší migrace
-- může být dosud nespustěná, ale databáze již obsahuje jeho převzaté mzdy.
-- Zúžení ENUM by selhalo ještě před následnou migrací 1901.
ALTER TABLE payroll_migration_reference_totals
  MODIFY COLUMN source ENUM('pamica','pohoda','money_s3','other','jmhz','stereo_nx') NOT NULL;
