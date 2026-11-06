-- MyÚčto.cz: předkontace prodaných víceúčelových poukazů a kreditu (§ 37c ZDPH).
--
-- PROČ: prodej víceúčelového poukazu se účtuje na přijaté zálohy a uplatnění je zápočet
-- proti nim. Na holém 324 se ale poukazy míchaly se zálohami z proforem a zůstatek
-- neříkal, kolik poukazů zbývá vyčerpat. Poukazy proto mají vlastní analytiku
-- „Prodané poukazy a kredit" (VoucherLiabilityAccount).
--
-- Globální předkontace nese prázdné účty záměrně: prázdná strana D = analytika .900
-- pod syntetikou účtu záloh z `advance.received.collection` (výchozí 324.900), takže
-- firma s přenastavenými zálohami dostane analytiku pod svou syntetikou. Vlastní účet
-- si firma nastaví přepisem této předkontace. Analytiku do osnovy zakládá aplikace
-- při prvním zaúčtování poukazu, migrace osnovy firem nemění.
--
-- Idempotence: INSERT gate-ovaný NOT EXISTS.

SET NAMES utf8mb4;

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'voucher.liability',
       'Prodané poukazy a kredit (§ 37c) - přijaté zálohy; prázdné D = analytika .900 pod účtem záloh',
       NULL, NULL, 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'voucher.liability');
