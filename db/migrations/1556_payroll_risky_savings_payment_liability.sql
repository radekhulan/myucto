-- MZ-08-W04: povinné spoření je samostatný institucionální závazek
-- v mzdových platebních příkazech, ne jen ručně označitelný stav.

SET NAMES utf8mb4;

ALTER TABLE payroll_payment_liabilities
  MODIFY COLUMN liability_kind ENUM(
    'net_wage','social_insurance','health_insurance',
    'advance_tax','withholding_tax','deduction',
    'enforcement','insolvency','benefit',
    'statutory_insurance','risky_savings','other'
  ) NOT NULL;

