-- Výplatní účet GoPay nesmí mít ve schématu konkrétní číslo účtu.
--
-- PROČ: migrace 1700 dala sloupci `payout_account_number` výchozí hodnotu
-- s reálným číslem účtu jednoho zákazníka. Nešlo tedy jen o citlivý údaj
-- v repozitáři — každá nová instalace by dostala CIZÍ účet předvyplněný jako
-- svůj a nikdo by si toho nemusel všimnout, protože pole vypadá vyplněně.
--
-- Samotná 1700 je opravená (nebyla nikdy vydaná a migrace se evidují podle
-- jména, ne otisku, takže se nikde nespustí znovu). Tahle migrace je tu pro
-- databáze, které starou verzi 1700 už aplikovaly.
--
-- Párování výplat je na tom postavené: shoda čísla protistrany je jednou
-- z podmínek, za kterých se clearing spáruje s bankovním pohybem. Cizí
-- předvyplněná hodnota by proto tiše párovala proti účtu, který s firmou
-- nemá nic společného.
--
-- Prázdná hodnota je bezpečná: čtecí cesta na ni reaguje tak, že kandidáta
-- nespáruje (`$transactionAccount === ''` vede k vynechání), takže dokud
-- účet nikdo nevyplní, automatika mlčí místo aby hádala.
--
-- ULOŽENÉ HODNOTY SE NEMĚNÍ. Kdo si účet vyplnil (i kdyby jen tím, že přijal
-- výchozí hodnotu), má ho dál — přepsat cizí konfiguraci by bylo horší než
-- ta původní chyba.

ALTER TABLE gopay_settings
  ALTER COLUMN payout_account_number SET DEFAULT '';
