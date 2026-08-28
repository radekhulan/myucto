-- MyÚčto.cz — produkční kvalifikace je interní release gate produktu.

SET NAMES utf8mb4;

-- Zákazník už nedokládá paralelní měsíce ani interní recovery protokol.
-- Historické kvalifikační tabulky a jejich neměnné auditní vazby zachováváme.
UPDATE payroll_module_state
   SET status = 'setup',
       activated_by = NULL,
       activated_at = NULL,
       row_version = row_version + 1
 WHERE status = 'qualification_required';
