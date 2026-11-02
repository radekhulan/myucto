# Ruční akceptace párování po přijetí dokladu

Pouze syntetická data. Testujte v oddělené instalaci s řádnou free licencí,
nikoli v běžné firmě. Není nutné připojit skutečnou banku, AI ani placené účetnictví.
Nevypínejte licenční kontroly. Větev vyžaduje migraci 2231 a nový frontendový build.

1. V testovací firmě nastavte korunový bankovní účet `1000000005 / 0100`.
   Firma nesmí mít starší doklady ze stejného testu. Vypněte automatické e-maily.
2. V Bance nahrajte **bank-first.gpc**. Obsahuje dva odchozí pohyby z 30. 10. 2026:
   812,34 Kč (VS 910000001) a 913,45 Kč (VS 9999). Zatím zůstanou bez faktur.
3. Až potom nahrajte **1-invoice.isdoc** jako přijatý doklad. Zkontrolujte koncept
   a přijměte jej. V přehledu bankovních úhrad má být spárovaných 812,34 Kč,
   doklad má být uhrazený a musí mít právě jednu bankovní úhradu.
4. Nahrajte **2-invoice.isdoc**. V konceptu nastavte druh **Účtenka** a přijměte.
   Bankovní popis, VS a chybějící koncovka karty neposkytují jistou shodu;
   tlačítko **Spárovat s bankou (1)** oznámí návrh 913,45 Kč. Před potvrzením
   nesmí vzniknout bankovní úhrada.
5. Otevřete **Spárovat s bankou (1)**. V původním dialogu musí být pohyb předvybraný.
   Potvrďte **Spárovat úhradu**. Ověřte jednu úhradu, nulový zůstatek a odkaz na pohyb.
   Opětovným otevřením detailu ani opakovaným importem stejného výpisu nesmí
   vzniknout druhá platba. Po návratu do detailu musí být výsledek aktuální.
6. V novém testovacím kole dialog nejprve zavřete bez potvrzení. Úhrada nesmí vzniknout.
   Pokud v původním bankovním přehledu návrh odmítnete, při dalším otevření
   detailu nesmí být nabízen jako doporučení. Ruční výběr platby zůstane dostupný.

ISDOC používá syntetického odběratele. Pokud import nabídne kontrolu odběratele,
zkontrolujte jej v rámci testovací firmy. Soubory nepředstavují reálné doklady.
Zkoušku nejistého návrhu lze zopakovat i s evidenčním stavem „Zaplaceno“ bez
připojené bankovní platby, pokladny nebo zápočtu. K dalším kolům použijte novou
firmu nebo čistou testovací databázi, aby deduplikace správně rozlišila testy.
