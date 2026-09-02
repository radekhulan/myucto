-- Špatně datovanou registraci účtárny u ČSSZ jde vzít zpět.
--
-- PROČ: variabilní symbol přiděluje ČSSZ při registraci zaměstnavatele, ne
-- v den, kdy ho účetní opíše do aplikace. Formulář přesto nabízel jako
-- účinnost DNEŠEK. Kdo ho tak uložil, dostal verzi platnou od dneška — a
-- protože nová verze musí navazovat AŽ ZA poslední uloženou, dřívější
-- účinnost už doplnit nešlo. Mzdy za předchozí měsíce pak neměly platnou
-- registraci a nedaly se spustit. Z aplikace z toho nevedla žádná cesta:
-- UPDATE i DELETE zakazuje trigger.
--
-- Append-only zůstává, jen se zužuje na to, co skutečně chrání: historii,
-- o kterou se něco opírá. Smazat jde POUZE NEJNOVĚJŠÍ verze účtárny —
-- starší, podle kterých se už mohlo počítat a podávat, ne. Že na verzi
-- nevisí podání ani mzdový běh, ověřuje servisní vrstva; trigger na to
-- nedosáhne a tvářit se, že ano, by bylo horší než nechat kontrolu výš.
--
-- UPDATE zůstává zakázaný. Oprava je smazání a nové vložení, aby otisk
-- verze vždy patřil k obsahu, který v ní opravdu byl.

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_office_registration_immutable_delete//

CREATE TRIGGER trg_payroll_office_registration_immutable_delete
BEFORE DELETE ON payroll_office_registration_versions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_office_registration_versions
     WHERE supplier_id = OLD.supplier_id
       AND office_id = OLD.office_id
       AND effective_from > OLD.effective_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Only the newest payroll office registration version can be removed';
  END IF;
END//

DELIMITER ;
