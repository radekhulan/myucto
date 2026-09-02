-- MyÚčto.cz — REGZEC A1: rozpracovaný profil přestává zakládat historii.
--
-- ## Proč
--
-- Profil A1 se od migrace 1609 vedl jako append-only řada verzí. Zní to
-- opatrně, ale nic to nechránilo:
--
--   * ČTECÍ CESTA ŽÁDNOU STARŠÍ VERZI NEČTE. `latestA1Profile()` má vždycky
--     `LIMIT 1` (nejnovější, případně nejnovější `verified`); detekce změn si
--     bere jen aktuální stav a porovnává ho proti ODESLANÉMU podání, ne proti
--     starší verzi profilu. Vodoznak v `PayrollRegistrationChangeProposal-
--     Repository` z tabulky čte jen `MAX(id)` a `SUM(row_version)`, tedy otisk
--     „něco se změnilo", ne obsah.
--   * DOKLAD O ODESLÁNÍ LEŽÍ JINDE. Co skutečně odešlo, je zmrazené v částech
--     podání (`payroll_submission_parts`, `payroll_submission_artifacts`)
--     a v protokolech o přijetí. Profil je vůči nim jen pracovní podklad, ze
--     kterého se obsah podání jednou vyrobil.
--   * ARCHIVAČNÍ POVINNOST SE VÁŽE NA PODANÝ ÚDAJ, ne na rozepsaný formulář.
--     Retenční katalog drží 45 let právě proto, že v tabulce může být řádek,
--     který do podání šel — ne kvůli konceptům.
--
-- Uživateli to naopak škodilo: každé „Uložit" založilo verzi, historie nebyla
-- nikde v UI dosažitelná a panel „snímek se rozešel s kmenovými daty" hlásil
-- rozdíl proti vlastnímu staršímu konceptu, se kterým nešlo nic dělat.
--
-- ## Co se mění
--
-- DELETE se povoluje, ale jen u řádku vztahu, za který ještě NEODEŠLO
-- registrační podání. Uložení pak není „přidej verzi", ale „nahraď pracovní
-- řádek" (smazání starého + vložení nového). UPDATE zůstává zakázaný: řádek se
-- nikdy nemění na místě, takže šifrovaný obsah a jeho otisk k sobě pořád patří
-- a nedá se přepsat pod rukama.
--
-- Jakmile za vztah jednou odešlo podání, chová se tabulka přesně jako dosud —
-- řádek, ze kterého vznikl odeslaný obsah, se nesmaže ani nepřepíše. Kritérium
-- je část podání za tenhle vztah a stav podání, ne `status = 'verified'`:
-- ověření znamená jen „prošlo přísnou kontrolou", což o odeslání nevypovídá
-- nic. Prostředí ani agenda se nerozlišují — doklad zanechalo i zkušební
-- podání a stejně tak předběžné přihlášení PREZEC.
--
-- `row_version` zůstává, ale už jen jako optimistický zámek proti souběžné
-- editaci téhož formuláře — ne jako číslo historické verze.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_registration_a1_profile_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_registration_a1_profile_immutable_delete
BEFORE DELETE ON payroll_registration_a1_profiles
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_submission_parts part
      JOIN payroll_submissions submission
        ON submission.supplier_id = part.supplier_id
       AND submission.environment = part.environment
       AND submission.id = part.submission_id
     WHERE part.supplier_id = OLD.supplier_id
       AND part.subject_reference = CONCAT('payroll_employment:', OLD.employment_id)
       AND part.agenda_code IN ('REGZEC25', 'PREZEC26')
       AND submission.status IN (
             'submitted', 'processing', 'accepted', 'partially_accepted'
           )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Payroll REGZEC A1 profile is retained once registration was submitted';
  END IF;
END//

DELIMITER ;
