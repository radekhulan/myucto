-- Vlastní branding e-mailů a PDF je nově zapnutý u NOVĚ zakládaných firem.
--
-- Výchozí `0` znamenalo, že čerstvě zřízená instalace posílala faktury pod
-- značkou MyÚčto, i když firmu i její jméno zná od prvního okamžiku. Zákazník
-- to musel najít v Nastavení a zapnout, aby se v e-mailu vůbec objevil jeho
-- vlastní název — a kdo to nenašel, posílal odběratelům doklady s cizí hlavičkou.
--
-- Bez loga se nic nerozbije: branding v takovém případě vykreslí NÁZEV FIRMY
-- textem, což je pořád správnější než značka dodavatele software.
--
-- ⚠️ Mění se JEN výchozí hodnota sloupce, tedy chování pro budoucí řádky.
-- Existující firmy si své nastavení ponechají — přepnout někomu vzhled
-- odchozích dokladů bez jeho vědomí by byla svévole, ne oprava.
--
-- Idempotentní: opakované nastavení téhož DEFAULTu je bez efektu.

ALTER TABLE `supplier`
	ALTER COLUMN `email_branding_enabled` SET DEFAULT 1;
