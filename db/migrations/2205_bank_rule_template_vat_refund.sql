-- MyÚčto.cz — bankovní šablona „Vratka přeplatku DPH od finančního úřadu".
--
-- Katalog šablon měl jen odchozí platbu DPH (`remit.vat`, 343.900 / 221). Příchozí
-- vratku nadměrného odpočtu nebo přeplatku si firma musela psát jako vlastní
-- pravidlo. Kontace `vat.settlement.excess` (221 / 343.900) existuje od migrace 1006
-- (analytika 343.900 od 1323), chyběla jen šablona.
--
-- Kritérium je účet FÚ pro DPH (předčíslí 705, banka 0710) bez variabilního symbolu:
-- příchozí platba z tohoto účtu je z definice vypořádání DPH a VS vratky se mezi
-- úřady liší. Detektor úhrad daní příchozí pohyby neřeší, takže pravidlo z šablony
-- s ním nekoliduje.
--
-- Globální katalog (`bank_rule_template_defaults`) dostane šablonu pro nové firmy,
-- stávající firmy ji dostanou do svého katalogu (`bank_rule_templates`). Pravidlo
-- z ní vznikne až použitím šablony v UI, nic se samo nezaúčtuje.
--
-- Re-run safe: INSERT IGNORE nad unikátními klíči (template_key, resp.
-- supplier_id + template_key).

SET NAMES utf8mb4;

INSERT IGNORE INTO bank_rule_template_defaults
    (template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
     counterparty_prefix, vs_placeholder, message_contains, rule_key,
     default_priority, sort_order, is_active)
VALUES
    ('remit.vat.refund', 'Vratka přeplatku DPH od finančního úřadu',
     'VAT refund from the tax authority', 'incoming', 'bank.remittance.vat',
     '0710', '705', NULL, NULL, 'vat.settlement.excess', 100, 35, 1);

INSERT IGNORE INTO bank_rule_templates
    (supplier_id, template_key, name_cs, name_en, direction, operation_type,
     counterparty_bank, counterparty_prefix, vs_placeholder, message_contains,
     rule_key, default_priority, sort_order, is_active)
SELECT s.id, d.template_key, d.name_cs, d.name_en, d.direction, d.operation_type,
       d.counterparty_bank, d.counterparty_prefix, d.vs_placeholder, d.message_contains,
       d.rule_key, d.default_priority, d.sort_order, d.is_active
  FROM supplier s
  JOIN bank_rule_template_defaults d ON d.template_key = 'remit.vat.refund';
