-- Rozpad příspěvku zaměstnavatele na produkty spoření na stáří v zařazení složek.
--
-- Měsíční hlášení vykazuje příspěvek zaměstnavatele nejen úhrnem (10417), ale
-- i rozpadem podle druhu produktu: pojištění dlouhodobé péče (10418), penzijní
-- připojištění (10292), doplňkové penzijní spoření (10293), penzijní pojištění
-- (10294), životní pojištění (10295) a dlouhodobý investiční produkt (10296).
-- Katalog cílových atributů je zná, ale databázový CHECK z migrace 1343 měl
-- výčet povolených cílů končící u 10417, takže by zařazení na kterýkoli z nich
-- odmítla. Projevilo by se to tiše: zakládání výchozích zařazení výjimku spolkne
-- a zařazení by prostě nevzniklo.
--
-- MariaDB nezná `ADD CONSTRAINT IF NOT EXISTS` u CHECK, takže se podmínka
-- nejdřív zahodí a znovu založí; tím je migrace opakovaně spustitelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_component_jmhz_mappings
  DROP CONSTRAINT IF EXISTS chk_payroll_component_jmhz_mapping_target;

ALTER TABLE payroll_component_jmhz_mappings
  ADD CONSTRAINT chk_payroll_component_jmhz_mapping_target CHECK (
    target_attribute_id IN (
      '10328','10329','10330','10331','10332','10333','10334','10335','10336',
      '10337','10338','10339','10340','10341','10342','10343','10417',
      '10418','10292','10293','10294','10295','10296'
    )
  );
