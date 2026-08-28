# Mzdové běhy

## 63.1 Účel

Mzdový běh shromáždí data jednoho období, provede výpočet a uchová kontrolovatelný výsledek. Uzavření odděluje návrh od podkladu pro platby, účetnictví, dokumenty a podání.

## 63.2 Předpoklady a oprávnění

Musí být dokončeno nastavení zaměstnavatele, zaměstnanců, vztahů, kalendáře, absencí a vstupů. Uživatel potřebuje mzdové oprávnění; před uzavřením musí rozumět validačním hlášením.

## 63.3 Krokový postup

1. Otevřete **Mzdy → Mzdové běhy**, zvolte rok a měsíc a vytvořte návrh.
   Předtím schvalte měsíc docházky — zákonné příplatky vznikají jeho schválením
   a po uzamčení vstupů už se do běhu nedostanou.
2. Uzamkněte vstupy. Zůstal-li některý vstup v konceptu, běh to ohlásí jako
   blokaci a přímo u ní nabídne **Schválit vše (N)**; nemusíte kvůli tomu
   odcházet na jinou obrazovku.
3. Spusťte výpočet a projděte chyby, varování i výsledky jednotlivých zaměstnanců.
4. Porovnejte souhrny s docházkou, vstupy, srážkami a očekávanými odvody.
5. Po opravě zdroje spusťte nový výpočet; neupravujte vypočtený výsledek bez podkladu.
6. Schvalte běh. Samostatný krok **Zkontrolovat** už není povinný — schválení
   ho provede za vás. Následné činnosti provádějte z této schválené revize.

## 63.4 Stavy

Návrh je měnitelný, vypočtený návrh čeká na kontrolu a uzavřený běh je stabilním podkladem. Chyba blokuje pokračování; varování vyžaduje rozhodnutí uživatele. Storno či oprava musí zachovat auditní návaznost a nesmí přepsat historii.

## 63.5 Kontroly a bezpečnost

Kontrolujte hrubou a čistou mzdu, daň, pojistné, náhrady, srážky a náklad zaměstnavatele. Ověřte počet osob a souběhy. Celý postup může dokončit jedna účetní s příslušným oprávněním; případná interní kontrola další osobou je dobrovolným pravidlem firmy. Výpočet v aplikaci nenahrazuje odborné posouzení nepodporovaného případu.

## 63.6 Časté chyby

- Uzavření před dodáním absence nebo srážky.
- Oprava vstupu bez přepočtu.
- Záměna výpočtu za automatické zaúčtování či odeslání plateb.
- Přehlédnutí varování u souběhu nebo chybějícího identifikátoru.

## 63.7 Návaznosti

Po uzavření zkontrolujte [shodu účtování](64_Shoda_uctovani_mezd.md), připravte [platby](65_Platby_a_uhrady.md), [dokumenty](66_Dokumenty_a_vystupy.md) a [podání](68_Podani_a_hlaseni.md).



## 63.8 Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové běhy** založíš zpracování konkrétního měsíce. K období se
zadává také skutečné datum výplaty; podle něj se vybírají účinná pravidla
srážek. Datum výplaty nesmí být později než poslední den měsíce následujícího
po měsíci, za který mzda přísluší (§ 141 odst. 1 zákoníku práce); pozdější
datum aplikace odmítne jako chybu, protože je to kotva, ze které se odvozují
všechny navazující termíny odvodů.

Běžný běh má tři kroky: **Uzamknout vstupy → Vypočítat → Schválit**. Krok
**Zkontrolovat** zůstává jako samostatný příkaz pro firmu, která chce mít
kontrolu vidět jako vlastní událost, ale povinný není — **schválení ji provede
implicitně** a do historie běhu se zapíše jako kontrola provedená spolu se
schválením. Všechny kroky může provést jedna účetní, pokud má mzdové oprávnění;
pravidlo čtyř očí modul nezavádí. Jednotlivé změny a potvrzení zůstávají
v auditní stopě, takže firma může dobrovolně zapojit další kontrolu bez toho,
aby byl běžný tok blokován.

Vstupy do běhu se nemusí schvalovat po jednom. **Rychlý měsíční vstup** uloží
řádky rovnou jako schválené, má-li přihlášený uživatel právo mzdové vstupy
schvalovat; bez toho práva vznikají koncepty a schválí je někdo jiný.
Jednotlivé mzdové vstupy zadané v **Mzdy → Mzdové složky a vstupy** vznikají
vždy jako koncept, protože je nutné je umět ještě upravit i zrušit.

Koncepty, které v běhu zbyly, schválíte hromadně tlačítkem **Schválit vše (N)**
— buď v mzdových vstupech, nebo přímo u blokace v kartě běhu. Schvaluje se
najednou až 500 vstupů; už schválený se jen přeskočí, takže je bezpečné
tlačítko použít znovu. Dvoustupňový režim tedy zůstává možný, jen není povinný.

Skutečně prázdný technický běh lze tlačítkem **Smazat prázdný běh** odstranit
i po jeho zrušení. Tlačítko se zobrazí pouze tehdy, když běh nemá žádnou revizi,
uzamčené vstupy, výpočet, dokument, podání, platbu ani účetní stopu. Jakmile
běh obsahoval věcnou evidenci, zůstává kvůli auditu dohledatelný a lze jej jen
zrušit, nikoli smazat.

Uzamknutí vytvoří neměnný snapshot zaměstnanců, vztahů, složek, data výplaty
a měsíčních podkladů srážek. Pozdější změna živé karty už rozpracovanou revizi
nepřepíše. Oprava schváleného měsíce vytváří novou revizi; původní zůstává
dohledatelná.

Schválením opravné revize se předchozí schválená revize označí jako
**nahrazená**. Za jeden běh je tedy vždy právě jedna platná schválená revize
a nemůže se stát, že by dvě revize současně tvrdily výsledek téhož měsíce.
Dokumenty vydané z původní revize zůstávají platné a čitelné — každý se váže
na svou vlastní revizi. Archivní export nese celý řetěz, tedy platnou revizi
i všechny nahrazené.

Čistá mzda jedné osoby může vyjít **záporně** — typicky když se v měsíci bez
peněžního příjmu doplácí zdravotní pojištění. Výpočet to nezaokrouhlí na nulu
ani nezastaví: jde o legitimní stav, ze kterého vzniká **pohledávka za
zaměstnancem**. Ta se vykazuje samostatně, nesčítá se do čistých mezd a
nevytvoří platební závazek, takže se nikdy nemůže dostat do odchozí bankovní
dávky s obráceným znaménkem. Zápočet takové pohledávky v dalším měsíci je
ruční úkon účetní — § 147 zákoníku práce omezuje, co lze srazit bez souhlasu
zaměstnance.

Po výpočtu je u běhu dostupný **Rozpad daně ze závislé činnosti**. Pro každého
zaměstnance ukazuje zdanitelný a zákonně zaokrouhlený základ, základ a sazbu
jednotlivých pásem, daň před slevami, uplatněné a skutečně použité slevy,
zvýhodnění na děti, daňový bonus a případnou srážkovou daň. U souběhu vztahů
je vidět, který příjem spadl do zálohového nebo srážkového režimu.

Stav **Vyžaduje ruční kontrolu** není vypočtená nula. Rozpad vypíše konkrétní
důvody, například neověřené prohlášení k dani, rezidenci nebo nárok na dítě.
Nejdřív oprav podklad na kartě osoby a potom vytvoř novou revizi výpočtu;
historický snapshot se zpětně nemění.

Výpočet odděluje hotovost zahrnutou do exekučního základu od částek, které se
nesrážejí, například správně klasifikovaných cestovních náhrad. Vypočtená
srážka sníží částku k výplatě, ale neměnný výsledek a ledger
**sraženo / deponováno** vzniknou až společně se schválením. Neúplné důkazy,
více plátců bez ověřeného rozdělení nebo jiný stav vyžadující posouzení
schválení zablokují.

Schválení také promítne vypočtené standardní srážky do append-only ledgeru.
Oprava nepřepíše původní pohyb: zvýšení přidá pouze rozdíl a snížení vytvoří
reverzi navázanou na původní sražení. Opakované schválení částku nezapíše
podruhé.

Schválení zároveň automaticky vytvoří výplatní pásku každé zpracované osoby
a v podvojném účetnictví rozdílový mzdový deník. Použijí se předkontace
zmrazené při uzamknutí vstupů, takže pozdější změna nastavení nezmění již
zkontrolovanou revizi. Je-li účetní období uzamčené, datum deníku se posune na
první otevřený den. Schválení, zákonné kumulace, účetní zápis i pásky tvoří
jeden celek: selže-li některý krok, běh zůstane ve stavu **Zkontrolováno**
a nevznikne částečně schválená mzda.

> [!WARNING]
> Produkční schválení vyžaduje odborně schválený a aktivní legislativní
> ruleset pro příslušné období. Neaktivní nebo neúplný ruleset výpočet označí
> pro ruční kontrolu a schválení zablokuje; aplikace chybějící zákonné údaje
> neodhaduje.

U schválené revize nabídne karta běhu **Rozklad čisté mzdy**. Pro vybranou osobu
ukáže hrubý příjem, odvody zaměstnance, daň a bonus, čistou mzdu před srážkami,
jednotlivé srážky s titulem a pořadím, exekuční srážku a výslednou částku
k výplatě včetně rozdělení mezi platební cíle. Údaje se čtou ze zmrazené revize,
takže pozdější změna dohody ani výplatního pravidla je nezmění. Zobrazí se vždy
jen vybraná osoba a bankovní cíl pouze maskou účtu.

V podvojném účetnictví je pro schválenou revizi dostupná stránka
**Mzdy → Shoda účtování mezd**. Pro zvolené období porovná mzdovou revizi,
skutečně zaúčtovaný deník a platební závazky po kategoriích (hrubé mzdy,
pojistné hrazené zaměstnavatelem, sociální a zdravotní pojištění, daň,
ostatní srážky, exekuční srážky a čistá mzda) a u každé ukáže, na které
straně případný rozdíl vznikl. Účetně neutrální nepeněžní plnění se z hrubých
mezd vyčleňuje, takže z něj rozdíl nevzniká. Oprava schváleného měsíce se do porovnání
promítne správně — deník se sčítá napříč všemi revizemi běhu, protože
rozdílová revize účtuje jen rozdíl proti poslední zaúčtované revizi. Měsíc,
který ještě nebyl zaúčtován (vypnuté automatické zaúčtování, čekající krok,
nebo firma vedoucí daňovou evidenci), stránka označí jako nezaúčtovaný —
nejde o rozdíl. Stránka je čistě informační a nic nezapisuje ani do deníku,
ani do mzdové revize.
