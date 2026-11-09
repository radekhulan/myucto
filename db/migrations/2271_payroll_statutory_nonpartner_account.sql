-- MyÚčto.cz — odměna jednatele za výkon funkce: 523/331 u jednatele, 523/366 u společníka.
--
-- Do teď se odměna za výkon funkce (relation_type statutory_body) účtovala vždy
-- proti závazku ke společníkům (statutory_gross_credit, výchozí 366). Jednatel,
-- který společníkem není, je ale věřitelem jako zaměstnanec, takže jeho závazek
-- patří na 331.
--
-- 1) Předkontace statutory_nonpartner_gross_credit_account (výchozí '' = nenastaveno).
--    Prázdná hodnota se do snímku běhu nezmrazí a odměna jednatele se účtuje přesně
--    jako dřív (PayrollAccountingDefaults::SNAPSHOT_GATED_ACCOUNTS). Stávajícím firmám
--    se proto nic nemění; nově zakládaná firma ji dostane předvyplněnou (331), když
--    účet v osnově má.
-- 2) payroll_employments.statutory_partner: vztah výkonu funkce, jehož člen orgánu je
--    zároveň společníkem. U něj zůstává závazek na statutory_gross_credit (366).
--    Příznak jde do snímku běhu jen u hodnoty 1.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS statutory_nonpartner_gross_credit_account VARCHAR(10) NOT NULL DEFAULT ''
    COMMENT 'závazek z odměny za výkon funkce jednatele, který není společníkem (523/331); prázdné = jako statutory_gross_credit'
    AFTER statutory_gross_credit_account;

ALTER TABLE payroll_employments
  ADD COLUMN IF NOT EXISTS statutory_partner TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = člen statutárního orgánu je zároveň společníkem (odměna za výkon funkce proti 366)'
    AFTER relation_type;
