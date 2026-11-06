-- Oprava DPH zařazení přijaté faktury (nárok na odpočet, klasifikace hlavičky, položek
-- a rozpisu DPH) bez storna i u zaúčtovaného dokladu. Je to běžná práce účetní, proto
-- nové právo purchase_invoices.vat_treatment dostanou správcovské role i výchozí účetní;
-- ostatní role (jen pro čtení, klient, vlastní role) zůstávají beze změny a správce ho
-- vlastní roli přidělí v editoru rolí.
-- INSERT IGNORE je bezpečný při opakovaném spuštění.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, 'purchase_invoices.vat_treatment', 2
FROM roles r
WHERE r.system_key IN ('admin', 'admin_plus', 'accountant');
