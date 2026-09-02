-- MyÚčto.cz — datum ověření doručovacího kanálu přestává být povinné.
--
-- Migrace 1276 vyžadovala `delivery_verified_on` u každého jiného kanálu než
-- „vypnuto". Byla to naše podmínka, ne cizí: skutečnou pojistkou proti odeslání
-- výplatnice nepotvrzeným kanálem je PayrollSecureDeliveryPolicy, která bez data
-- neodešle nic ani z fronty, a PayrollSetupCheckService kanál hlásí jako
-- neověřený. Jediné, čeho podmínka dosáhla, bylo, že se s vybraným způsobem
-- předávání neuložil ani výplatní den nebo zaokrouhlení.
--
-- Opačný směr platí dál: vypnutý kanál nesmí nést datum ověření, protože takový
-- záznam neříká nic, co by se dalo přečíst.

SET NAMES utf8mb4;

-- MariaDB neumí `IF NOT EXISTS` u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_delivery_verification;

ALTER TABLE payroll_employer_policies
  ADD CONSTRAINT chk_payroll_employer_policy_delivery_verification
    CHECK (delivery_channel <> 'disabled' OR delivery_verified_on IS NULL);
