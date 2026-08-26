# Mzdové příkazy a úhrady

## Účel

Agenda připravuje závazky z uzavřené mzdy: čisté mzdy, daň, sociální a zdravotní pojištění, srážky a další příjemce. Následně sleduje jejich úhradu.

## Předpoklady a oprávnění

Je nutný uzavřený běh, oprávnění `payroll.payments`, správné účty, termíny a symboly. Bankovní údaje musí být ověřeny z důvěryhodného zdroje.

## Krokový postup

1. U zaúčtovaného běhu zvolte **Připravit platby**. Aplikace otevře **Mzdy → Mzdové příkazy a úhrady** ve správném období a nabídne závazky daného běhu.
2. Zkontrolujte příjemce, účet, částku, splatnost a platební symboly každého závazku.
3. Na kartě **Co zaplatit** vyberte kompatibilní závazky a vytvořte mzdový příkaz ABO pro CZK, SEPA pro EUR, nebo evidenci hotovostní výplaty.
4. Platby autorizujte v bance podle interních pravidel firmy.
5. Po provedení spárujte úhrady a vyřešte rozdíly nebo vratky.

## Stavy

Připravená platba není odeslaná. Exportovaná čeká na autorizaci v bance. Uhrazená má odpovídající bankovní pohyb; částečně uhrazená nebo zamítnutá vyžaduje další krok.

## Kontroly a bezpečnost

Jedna účetní může připravit i dokončit celý tok. Při změně účtu aplikace vyžaduje ověřený podklad; před vytvořením příkazu porovnejte součet plateb s uzavřeným během a účetními závazky. Bankovní export chraňte jako citlivý soubor a po přenosu jej nenechávejte na sdíleném místě.

## Časté chyby

- Považování exportu za odeslanou platbu.
- Starý účet zaměstnance nebo instituce.
- Duplicitní export či ruční platba.
- Spárování podobné částky z jiného období.

## Návaznosti

Částky pocházejí z [mzdového běhu](63_Mzdove_behy.md), účetní závazky kontroluje [shoda účtování](64_Shoda_uctovani_mezd.md) a příjemce srážek popisují [kapitoly 58l](70_Dohody_o_srazkach.md) a [58m](71_Srazky_a_exekuce.md).



## Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové příkazy a úhrady** vybereš mzdové období a připravíš platební
závazky z aktuálních schválených revizí. Čistá mzda se vždy odvozuje z částky
po exekučních srážkách. Rozdělení mezi ověřené bankovní účty a hotovost se
znovu vypočte nad pravidly a účty zmrazenými při uzamčení vstupů; pozdější
změna živé karty už schválenou revizi nepřesměruje.

Bankovní cíl musí mít ve snapshotu úplné ověření, období platnosti a verzi
účtu. Schválená revize bez zmrazených výplatních účtů se k přípravě plateb
nenabídne. Pro její zaplacení nejprve
vytvoř opravnou revizi z aktuálních a ověřených podkladů. Selhání jedné
účtárny nezastaví přípravu ostatních běhů téhož měsíce; aplikace vypíše počet
nezpracovaných běhů a konkrétní důvod.

Stejná akce připraví také zdravotní pojistné samostatně pro každou pojišťovnu
z neměnného výsledku schválené revize. V nastavení musí mít konkrétní
pojišťovna právě jeden účet účinný ke splatnosti, úplný ověřovací podklad a
platební symboly. Seznam ukáže název a kód pojišťovny, maskovaný účet a stav
ověření; celé číslo účtu ani interní reference neposílá. Splatnost se vždy
odvodí z mzdového období, ne z dne, kdy byl historický běh vypočten nebo
opraven.

Ze stejných schválených podkladů se připraví závazky sociálního pojištění
a oddělené závazky zálohové a srážkové daně. Sociální pojistné se dělí podle
**mzdové účtárny pracovního vztahu**, protože zaměstnavatelský variabilní
symbol je na účtárně: běh zúžený na jednu účtárnu dá jeden závazek, celofiremní
běh tolik závazků, kolik různých účtáren mají vztahy v běhu. Součet těchto
závazků vždy odpovídá kořenovému výsledku, takže sedí i kontrolní součty
a rekonciliace účetnictví s platbami. Každý závazek používá zaměstnavatelský VS
své účtárny a účet ČSSZ účinný ke splatnosti; v platební dávce jsou to
samostatné platby, i když jdou na týž účet ČSSZ. Vztah bez mzdové účtárny
přípravu zastaví — bez ní není odvod pod jakým symbolem vykázat.
Přehled o výši pojistného (PVPOJ) se naopak podává za jednu registraci u OSSZ,
takže vyžaduje běh zúžený na jednu účtárnu; u celofiremního běhu přes víc
účtáren aplikace přípravu podání odmítne. Zálohová a srážková daň se neslučují: každá má vlastní účet
finančního úřadu a platební symboly. Záloha je splatná 20. den následujícího
měsíce, srážková daň jeho poslední den.

Sociální i zdravotní pojistné je splatné od 1. do 20. dne následujícího měsíce.
Připadne-li poslední den lhůty na sobotu, neděli nebo svátek, aplikace u všech
zákonných odvodů zapíše jako splatnost nejblíže následující pracovní den —
například odvody za 05/2026 nevyjdou na sobotu 20. 6. 2026, ale na pondělí
22. 6. 2026. Posunuté datum je i to, co uvidíš v seznamu závazků a co se použije
pro platební dávku; výplatní termín se neposouvá, ten se řídí mzdovým během.

Opakované stisknutí **Připravit závazky** je bezpečné a nevytvoří duplicity.
Opravná revize nezapisuje znovu celou mzdu, ale jen rozdíl proti předchozím
závazkům. Seznam ukazuje příjemce, druh závazku, způsob úhrady, splatnost,
částku a odvozený stav. Záporný rozdíl institucionálního odvodu se zobrazí jako
příchozí opravný závazek, ale nelze jej vložit do odchozí bankovní dávky;
vratku je nutné doložit skutečně přijatým bankovním pohybem nebo zaúčtovaným
příjmovým pokladním dokladem. Na kartě **Spárování úhrad** zvolte
**Potvrdit skutečně přijatou vratku**, vyberte nevyrovnaný příchozí závazek,
doklad ve stejné měně a přijatou částku. Aplikace dovolí i částečné přijetí,
ale před uložením vždy vyžaduje výslovné potvrzení účetní, že peníze byly
firmě skutečně připsány nebo přijaty do pokladny. Změna závazku, dokladu nebo
částky potvrzení zruší. Příchozí vratka nevytváří odchozí dávku; případná
reverze přidá samostatnou neměnnou událost s vlastním bankovním důkazem nebo
naváže na stornovaný původní pokladní doklad.

Na kartě **Co zaplatit** vybereš připravené závazky; karta **Mzdové příkazy**
ukazuje už vytvořené dávky. Aplikace podle
výplatních cílů nabídne účet plátce a formát ABO nebo SEPA, znovu ověří
nezměněné účty příjemců a vytvoří dávku. U zdravotní pojišťovny, ČSSZ i
finančního úřadu použije přesné zmrazené VS, SS a KS. Export se ukládá
šifrovaně přesně
v těch bajtech, které se stáhnou do banky. Opakování se stejným klíčem vrátí
tentýž export a nevytvoří další ekonomický závazek. Stažení vyžaduje právo
zápisu a používá krátkodobé jednorázové oprávnění.

Na kartě **Spárování úhrad** vybereš konkrétní alokaci závazku a kompatibilní
bankovní pohyb nebo zaúčtovaný pokladní doklad. Lze zapsat i částečnou úhradu.
Historie je neměnná: vratka nebo storno nevynuluje původní záznam, ale přidá
samostatnou reverzní událost s vlastním důkazem. Jeden bankovní nebo pokladní
důkaz nesmí současně převzít fakturace ani jiné párování.

Filtr období patří mzdové revizi, ne datu vytvoření dávky. V nabídce důkazů
proto zůstane i předčasná nebo opožděná platba vztahující se k otevřenému
závazku.

Skutečné datum úhrady vzniká výhradně z data zvoleného důkazu, nikdy z
plánovaného data výplaty mzdového běhu ani ze samotné existence exportního
souboru. Dokud nejsou všechny požadované částky průkazně spárovány a případné
vratky vyřešeny, aplikace závazek ani daňové potvrzení neoznačí za skutečně
uhrazené.
