# 80. Mzdové běhy

> Návod, jak za měsíc spočítat, zkontrolovat a schválit mzdy a jak schválený
> měsíc opravit. Pro mzdové účetní a každého, kdo ve firmě zpracovává výplaty.

## 80.1 Kdy to potřebujete

Kapitolu otevřete, když:

- máte za měsíc schválenou docházku a vstupy a chcete spočítat mzdy,
- běh hlásí blokaci nebo varování a nevíte, co s tím,
- po spočítání jste schválili další vstup nebo nepřítomnost a běh ho nevidí,
- ve schváleném měsíci je chyba a musíte ho opravit,
- jste mzdy převzali z jiného programu a doplňujete zákonné údaje, zdravotní
  pojišťovny nebo pracoviště pro JMHZ,
- zaměstnanci trvá vztah, ale v měsíci nedostal žádný příjem,
- jste v roce přechodu z jiného mzdového programu a pracujete s převzatými
  měsíci.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po schválení docházky a vstupů | Založit běh, spočítat a schválit mzdy | `Mzdy → Mzdové běhy`, postup v [§ 80.3](#803-krok-za-krokem-spocitat-a-schvalit-mzdy) |
| hned po schválení | Zaúčtovat, připravit platby, vydat dokumenty a podat hlášení | karta běhu, postup v [§ 80.4](#804-krok-za-krokem-dokonceni-mesice-po-schvaleni) |
| po spárování plateb a odeslání podání | Uzavřít měsíc | karta běhu, tlačítko **Uzavřít** |
| když se ve schváleném měsíci najde chyba | Opravná revize | karta běhu, postup v [§ 80.5](#805-krok-za-krokem-oprava-schvaleneho-mesice) |

## 80.2 Než začnete

1. **Nastavení mezd.** Zaměstnavatel, zaměstnanci, pracovní vztahy, kalendář,
   absence a mzdové vstupy musí být hotové (průvodce je v
   [Úplné mzdy](75_Uplne_mzdy.md)).
2. **Schválená docházka.** Měsíc docházky schvalte v `Mzdy → Docházka a směny`
   ještě před spočítáním mezd. Zákonné příplatky vznikají právě schválením
   docházky a po uzamčení vstupů se do běhu nedostanou.
3. **Schválené vstupy.** Mzdové vstupy a nepřítomnosti měsíce musí být
   schválené. Koncepty můžete schválit hromadně i přímo z běhu.
4. **Oprávnění.** Potřebujete mzdové oprávnění. Celý běh může dokončit jedna
   účetní s příslušným oprávněním; kontrola další osobou je dobrovolné pravidlo
   firmy.
5. **Aktivní legislativní pravidla.** Pro období musí být odborně schválená
   a aktivní legislativní pravidla mezd (`Mzdy → Legislativní pravidla mezd`).

## 80.3 Krok za krokem: spočítat a schválit mzdy

1. Otevřete `Mzdy → Mzdové běhy`.
2. Vyplňte **Mzdové období** a **Datum výplaty** a klikněte na **Nový mzdový
   běh**. Datum výplaty nesmí být později než poslední den následujícího
   měsíce.
3. Klikněte na **Spočítat mzdy**. Jedno tlačítko uzamkne vstupy a spustí
   výpočet.
4. Zobrazí se **Kontrola před zahájením**. Projděte ji a potvrďte volbou
   **Přesto zahájit**.
5. Zůstal-li některý vstup v konceptu, běh to ohlásí jako blokaci. Klikněte
   přímo u ní na **Schválit vše**. Na jinou obrazovku nemusíte.
6. Projděte blokace, varování a výsledky jednotlivých zaměstnanců. U každého
   důvodu je odkaz na místo opravy. U skupiny osob použijte odkaz u konkrétního
   člověka. Karta vztahu se otevře přímo na příslušné sekci nebo poli,
   měsíční agenda ve správném období.
7. Porovnejte souhrny s docházkou, vstupy, srážkami a očekávanými odvody.
   Zkontrolujte hrubou i čistou mzdu, daň, pojistné, náhrady, srážky, náklad
   zaměstnavatele, počet osob a souběhy vztahů.
   U daně pomůže **Rozpad daně ze závislé činnosti**.
8. Po opravě zdroje klikněte na **Přepočítat**. Vypočtený výsledek nikdy
   neupravujte bez podkladu.
9. Klikněte na **Schválit**.

**Jak poznáte, že je hotovo:** Běh má stav **Schváleno** a hlavní tlačítko
nabízí **Zaúčtovat**. Každý zaměstnanec má výplatní pásku a v podvojném
účetnictví vznikl mzdový deník.

> [!TIP]
> Zaměstnanec, kterému vztah trvá, ale v měsíci nedostal žádný příjem (typicky
> dohoda, na které se nepracovalo), potřebuje u základní složky výslovně
> zadaný vstup **0 Kč**. Za vztah se pak podá nulový formulář JMHZ. Bez
> vstupu běh ohlásí chybějící mzdovou složku, aby zapomenutá mzda neprošla.

## 80.4 Krok za krokem: dokončení měsíce po schválení

Pořadí je dané a všechny kroky se dělají ze schválené revize.

1. Na kartě běhu klikněte na **Zaúčtovat** a ověřte
   [shodu účtování](81_Shoda_uctovani_mezd.md). U daňové evidence účetní zápis
   nevznikne a běh jen postoupí dál.
2. Klikněte na **Připravit platby** a vypořádejte
   [mzdové příkazy a úhrady](82_Platby_a_uhrady.md).
3. Vydejte [dokumenty](83_Dokumenty_a_vystupy.md).
4. Odešlete [podání a hlášení](85_Podani_a_hlaseni.md), hlavně JMHZ
   ([§ 85.3](85_Podani_a_hlaseni.md#853-krok-za-krokem-mesicni-hlaseni-jmhz)).
5. Klikněte na **Uzavřít**.

**Jak poznáte, že je hotovo:** Běh má stav **Uzavřeno**. Do stavu **Uhrazeno**
ho server překlopí sám podle spárovaných plateb; tlačítko pro potvrzení úhrady
neexistuje. Celý klikací postup měsíce je v
[§ 75.4](75_Uplne_mzdy.md#754-krok-za-krokem-zpracovani-mzdoveho-mesice).

## 80.5 Krok za krokem: oprava schváleného měsíce

1. Na kartě schváleného běhu klikněte na **Vyžádat opravu**. Běh přejde do
   stavu **Čeká na opravu**.
2. Opravte zdroj: mzdový vstup, nepřítomnost, kartu osoby nebo vztahu.
3. Klikněte na **Otevřít opravu**. Vznikne nová revize se stavem **Oprava
   otevřena**.
4. Klikněte na **Přepočítat**, zkontrolujte výsledek a klikněte na
   **Schválit**.
5. Opravnou revizi zaúčtujte a připravte platby stejně jako v
   [§ 80.4](#804-krok-za-krokem-dokonceni-mesice-po-schvaleni). Bylo-li
   odeslané JMHZ, podejte opravné hlášení
   ([§ 85.9](85_Podani_a_hlaseni.md#859-krok-za-krokem-oprava-nebo-storno-odeslaneho-hlaseni-jmhz)).

**Jak poznáte, že je hotovo:** Nová revize je **Schváleno** a předchozí
schválená revize je **Nahrazeno**. Za běh je vždy jen jedna platná schválená
revize.

Zrušený běh znovu otevřete tlačítkem **Začít z opravených vstupů**.

## 80.6 Krok za krokem: obnovení podkladů rozpracovaného běhu

Použijte, když jste po spočítání schválili další vstup, nepřítomnost nebo
změnu zákonné evidence (typicky doplatek, opravu nebo zapomenutý příplatek).

1. Otevřete kartu rozpracovaného běhu. Varování ukáže datum snímku a počet
   změn podle druhu.
2. Klikněte na **Obnovit podklady**.
3. Klikněte na **Přepočítat** a znovu schvalte výjimky u varování.
4. Klikněte na **Schválit**.

**Jak poznáte, že je hotovo:** Varování o změněných podkladech zmizí a
v historii běhu přibude událost **Podklady obnoveny**.

Nechcete-li nový vstup do měsíce zahrnout, zrušte ho nebo ho přesuňte do jiného
období. U schváleného běhu vede cesta přes **Vyžádat opravu**
([§ 80.5](#805-krok-za-krokem-oprava-schvaleneho-mesice)).

## 80.7 Krok za krokem: doplnění zákonných údajů po importu

Po převzetí zaměstnanců z importu běh často hlásí nedokončený zákonný výpočet
u většiny lidí, chybějící zdravotní pojišťovnu nebo chybějící pracoviště
pro JMHZ. Aplikace nabízí tři hromadné akce.

**Výchozí zákonná evidence** (daňová rezidence, příslušnost k pojištění):

1. U blokace na kartě běhu klikněte na **Doplnit výchozí údaje (N osob)**.
   Totéž je v `Mzdy → Zaměstnanci` pod **Doplnit výchozí zákonné údaje**;
   tam zvolíte měsíc, od kterého údaje platí.
2. Projděte náhled: komu se údaje doplní, kdo je vyřazený a proč, kdo nemá
   zdravotní pojišťovnu a kdo nemá prohlášení poplatníka. Náhled nic
   nezapisuje.
3. Chcete-li chybějící prohlášení zapsat jako nepodepsané, zaškrtněte
   **Zapsat chybějící prohlášení jako nepodepsané (N osob)**. Nejdřív si
   přečtěte, komu by to přepnulo zdanění na srážkovou daň.
4. Údaje uložte a pokračujte krokem, který nabídne dialog (**Spočítat mzdy**,
   **Obnovit podklady**, nová revize nebo **Vyžádat opravu**).

**Zdravotní pojišťovny:**

1. V `Mzdy → Zaměstnanci` klikněte na **Zdravotní pojišťovny hromadně**
   (výsledek importu hlášení na akci odkáže sám).
2. U každé osoby vyberte pojišťovnu a měsíc, od kterého platí. **Doplnit
   prázdné** vyplní stejnou pojišťovnu všem, kdo ji zatím nemají.
3. Uložte. Osoby bez vybrané pojišťovny se přeskočí.

**Místo výkonu práce pro JMHZ:**

1. V `Mzdy → Zaměstnanci`, nebo přímo u nálezu **Chybí ověřené číselníkové
   údaje pracoviště.** v testu JMHZ, klikněte na **Doplnit pracoviště**.
2. Zvolte pracoviště z nabídky (ve firmě už ověřená, nejčastější první), nebo
   jinou obec vyhledejte v číselníku.
3. Klikněte na **Doplnit pracoviště N vztahům**.
4. Doplněné údaje dostanete do běhu novou revizí: schválený běh vraťte
   k opravě, otevřete novou revizi, spočítejte, schvalte a hlášení připravte
   znovu.

**Jak poznáte, že je hotovo:** Výsledek každé akce ukáže doplněné,
přeskočené a neúspěšné osoby s důvodem. Po obnovení podkladů a novém výpočtu
blokace zmizí.

## 80.8 Krok za krokem: skrytí opakujícího se varování

Použijte u varování, které popisuje stav ve firmě v pořádku a opakoval by se
každý měsíc (osoba bez podepsaného prohlášení poplatníka, vztah bez založené
pracovní doby nebo bez mzdových vstupů).

1. Na kartě běhu nebo v kontrole před zahájením klikněte u varování na
   **Skrýt pro všech N osob** (u jedné osoby **Skrýt u této osoby**), nebo
   na **Skrýt tento typ ve firmě**.
2. V dialogu nechte vybrané osoby (výchozí jsou všechny), případně některé
   odškrtněte. Důvod je nepovinný.
3. Potvrďte.

**Jak poznáte, že je hotovo:** Varování se už nepočítá do kontrol a u běhu
se místo něj ukáže odkaz **Skrytá varování (N)**. Obnovit ho můžete v
`Mzdy → Nastavení mezd`, záložce **Skrytá varování**.

## 80.9 Krok za krokem: převzaté měsíce roku přechodu

Platí pro firmu, která v průběhu roku přešla z jiného mzdového programu.

1. Otevřete `Mzdy → Mzdové běhy` a zvolte období v roce přechodu.
2. V panelu **Převzaté měsíce roku přechodu** klikněte na **Zobrazit**.
3. U měsíce, který ještě převzatý není, klikněte na **Převzít měsíc**.
4. U převzatého měsíce si pod **Zobrazit doložení plateb** ověřte, co se za
   měsíc platilo.
5. Je-li převzetí chybné, klikněte na **Zrušit převzetí**, uveďte důvod
   a měsíc převezměte znovu z aktuálních podkladů.

**Jak poznáte, že je hotovo:** Měsíc má v seznamu běhů štítek **Převzato**
a panel se už sám nerozbaluje. Kontrolu převzatých dat proti přijatým
hlášením najdete v [Přechodu mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md).

## 80.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „…: pracovní vztah nemá v období žádnou schválenou mzdovou složku.“ | Vztah je v běhu, ale nemá schválený vstup | Doplňte a schvalte složku v měsíčních vstupech. Trvá-li vztah a příjem v měsíci opravdu nevznikl, zadejte u základní složky 0 Kč; za vztah se podá nulové hlášení JMHZ. Potom **Obnovit podklady**. |
| Blokace kvůli vstupu v konceptu | Vstup zůstal neschválený | U blokace klikněte na **Schválit vše**, nebo v `Mzdy → Mzdové složky a vstupy` na **Schválit N odpovídajících filtru**. |
| „…: v období je nerozhodnutá nepřítomnost“ | Nepřítomnost v měsíci čeká na schválení nebo u ní běží oprava; platí pro měsíční i hodinovou mzdu | Nepřítomnost schvalte nebo zamítněte (opravu dokončete) v `Mzdy → Absence a dovolená`, potom **Obnovit podklady**. |
| Schválení odmítnuto s výzvou k obnovení | Od zmrazení snímku přibyly nebo se změnily schválené podklady | Klikněte na **Obnovit podklady** a přepočítejte ([§ 80.6](#806-krok-za-krokem-obnoveni-podkladu-rozpracovaneho-behu)). |
| Přepočet nevzal opravený údaj | **Přepočítat** počítá ze zmrazeného snímku | **Obnovit podklady**, u schváleného běhu **Vyžádat opravu**. |
| Datum výplaty se neuloží | Je později než poslední den následujícího měsíce (§ 141 odst. 1 zákoníku práce) | Zadejte skutečné datum výplaty v zákonné lhůtě. |
| Rozpad daně ukazuje **Vyžaduje ruční kontrolu** | Chybí ověřené prohlášení, rezidence nebo nárok na dítě | Opravte podklad na kartě osoby a vytvořte novou revizi výpočtu. |
| Nedokončený zákonný výpočet u většiny lidí po importu | Osoby nemají rezidenci ani příslušnost k pojištění | **Doplnit výchozí údaje (N osob)** ([§ 80.7](#807-krok-za-krokem-doplneni-zakonnych-udaju-po-importu)). |
| Výpočet zdravotního pojištění skončí chybou | Osoba nemá zdravotní pojišťovnu (hlášení JMHZ ji nenese) | **Zdravotní pojišťovny hromadně**. |
| Test JMHZ hlásí **Chybí ověřené číselníkové údaje pracoviště.** | Vztah nemá místo výkonu práce z číselníku ČSSZ | **Doplnit pracoviště**, pak nová revize běhu. |
| Schválení zablokováno kvůli pravidlům | Legislativní pravidla pro období nejsou aktivní nebo úplná | Nechte aktivovat pravidla v `Mzdy → Legislativní pravidla mezd`. |
| Čistá mzda jedné osoby vyšla záporně | Typicky doplatek zdravotního pojištění v měsíci bez peněžního příjmu | Nejde o chybu, vznikla pohledávka za zaměstnancem ([§ 80.11.4](#80114-zaporna-cista-mzda)). |
| Varování nejde skrýt | Jde o blokující chybu, varování s výjimkou nebo překročení zákonného limitu | Vyřešte příčinu, nebo schvalte výjimku. |

Podrobný přehled řešení je v
[kontrolách mzdové agendy](999_Reseni_problemu.md#99962-kontroly-mzdove-agendy).

Časté chyby: uzavření měsíce před dodáním absence nebo srážky, oprava vstupu
bez přepočtu, záměna výpočtu za automatické zaúčtování nebo odeslání plateb,
přehlédnuté varování u souběhu nebo chybějícího identifikátoru.

## 80.11 Podrobnosti a pravidla

Mzdový běh shromáždí data jednoho období, provede výpočet a uchová
kontrolovatelný výsledek. Schválení odděluje návrh od podkladu pro platby,
účetnictví, dokumenty a podání. Výpočet v aplikaci nenahrazuje odborné
posouzení nepodporovaného případu.

### 80.11.1 Stavy běhu

<!-- cols: 30 70 -->
| Stav | Co znamená |
|---|---|
| **Koncept** | Běh je založený, vstupy nejsou uzamčené. |
| **Vstupy uzamčeny** | Snímek podkladů je zmrazený, čeká se na výpočet. |
| **Spočítáno** | Výsledek čeká na kontrolu a schválení. |
| **Zkontrolováno** | Technická kontrola proběhla. Schválení ji provádí samo, jako tlačítko se nenabízí. |
| **Schváleno** | Platný výsledek měsíce. |
| **Zaúčtováno** | Vznikl mzdový deník. |
| **Platby připraveny** | Vznikly platební závazky. |
| **Uhrazeno** | Všechny závazky běhu jsou pokryté platbami; nastaví server sám. |
| **Uzavřeno** | Měsíc je stabilní podklad. |
| **Čeká na opravu** / **Oprava otevřena** | Schválený měsíc se opravuje novou revizí. |
| **Zrušeno** | Běh je zrušený; znovu ho otevře **Začít z opravených vstupů**. |

Chyba blokuje pokračování, varování vyžaduje rozhodnutí uživatele. Storno ani
oprava nesmí přepsat historii.

### 80.11.2 Datum výplaty, snímek a revize

K období se zadává skutečné datum výplaty. Podle něj se vybírají účinná
pravidla srážek. Datum nesmí být později než poslední den měsíce následujícího
po měsíci, za který mzda přísluší (§ 141 odst. 1 zákoníku práce). Pozdější
datum aplikace odmítne jako chybu, protože se z něj odvozují všechny
navazující termíny odvodů.

Běžný běh má dva kroky: **Spočítat mzdy → Schválit**. Uzamčení vstupů
a výpočet jsou sloučené, protože jde o tutéž práci. Samostatné **Uzamknout
vstupy** se nabízí jen tam, kde sloučený krok nejde použít (opravné revize).
Krok **Zkontrolovat** existuje pro firmu, která chce mít kontrolu jako
samostatnou událost, ale povinný není a jako tlačítko se nenabízí. Schválení
ho provede samo a do historie běhu se zapíše jako kontrola provedená spolu se
schválením. Pravidlo čtyř očí modul nezavádí; změny a potvrzení zůstávají
v auditní stopě, takže firma může dobrovolně zapojit další kontrolu.

Uzamknutí vytvoří neměnný snímek zaměstnanců, vztahů, složek, data výplaty
a měsíčních podkladů srážek. Pozdější změna živé karty rozpracovanou revizi
nepřepíše. Oprava schváleného měsíce vytváří novou revizi a původní zůstává
dohledatelná. Schválením opravné revize se předchozí označí jako
**Nahrazeno**, takže výsledek měsíce tvrdí vždy jen jedna revize. Dokumenty
vydané z původní revize zůstávají platné a čitelné, každý se váže na svou
revizi. Archivní export nese celý řetěz revizí.

Prázdný technický běh smažete tlačítkem **Smazat prázdný běh**, i po jeho
zrušení. Tlačítko se ukáže, jen když běh nemá revizi, uzamčené vstupy,
výpočet, dokument, podání, platbu ani účetní stopu. Běh s věcnou evidencí
zůstává kvůli auditu dohledatelný a jde jen zrušit.

### 80.11.3 Schvalování vstupů

Vstupy se nemusí schvalovat po jednom. **Rychlý měsíční vstup** uloží řádky
rovnou jako schválené, má-li uživatel právo mzdové vstupy schvalovat; bez
něj vzniknou koncepty a schválí je někdo jiný. Vstupy zadané v
`Mzdy → Mzdové složky a vstupy` vznikají vždy jako koncept, aby šly upravit
i zrušit.

Koncepty, které v běhu zbyly, schválíte hromadně u blokace v kartě běhu,
nebo ve mzdových vstupech tlačítkem **Schválit N odpovídajících filtru**.
Odkaz u blokace otevře seznam konceptů měsíce. Schvalují se všechny koncepty
bez ohledu na počet; schválený se přeskočí, takže je bezpečné tlačítko použít
znovu. Dvoustupňový režim tak zůstává možný, ale není povinný.

### 80.11.4 Záporná čistá mzda

Čistá mzda jedné osoby může vyjít záporně, typicky když se v měsíci bez
peněžního příjmu doplácí zdravotní pojištění. Výpočet ji nezaokrouhlí na nulu
ani nezastaví. Vznikne **pohledávka za zaměstnancem**, která se vykazuje
samostatně, nesčítá se do čistých mezd a nevytvoří platební závazek. Do
bankovní dávky se proto nikdy nedostane s obráceným znaménkem. Zápočet
pohledávky v dalším měsíci je ruční úkon účetní; § 147 zákoníku práce omezuje,
co lze srazit bez souhlasu zaměstnance.

### 80.11.5 Trvající vztah bez příjmu

Vztah, za který se v měsíci nic nezúčtovalo, se v JMHZ hlásí nulovým
formulářem osoby. Nulový formulář vznikne jen z výslovně zadané nuly: zadejte
u základní složky 0 Kč. Běh bez jakéhokoli vstupu dál zastaví zapomenutou mzdu.
V měsíci bez příjmu se pro hlášení nevyžaduje průměrný výdělek; existující
průměr se vykazuje dál.

Hlášení bez jediného formuláře osoby se nepřipraví: řádné JMHZ jen se
souhrnnou a pojistnou částí ČSSZ odmítne (kontrola 232). Za měsíc, ve kterém
netrvalo žádné zaměstnání, se JMHZ nepodává (§ 7 odst. 2 zákona č. 323/2025
Sb.). Pokud už firma nikoho nezaměstnává, podejte odhlášku z evidence
zaměstnavatelů.

### 80.11.6 Rozpad daně a rozklad čisté mzdy

Po výpočtu je u běhu **Rozpad daně ze závislé činnosti**. Pro každého
zaměstnance ukazuje zdanitelný a zákonně zaokrouhlený základ, základ a sazbu
pásem, daň před slevami, uplatněné a skutečně použité slevy, zvýhodnění na
děti, daňový bonus a případnou srážkovou daň. U souběhu vztahů je vidět, který
příjem spadl do zálohového nebo srážkového režimu.

Stav **Vyžaduje ruční kontrolu** není vypočtená nula. Rozpad vypíše konkrétní
důvody, například neověřené prohlášení k dani, rezidenci nebo nárok na dítě.
Nejdřív opravte podklad na kartě osoby, potom vytvořte novou revizi výpočtu.
Historický snímek se zpětně nemění.

U schválené revize nabídne karta běhu **Rozklad čisté mzdy**. Pro vybranou
osobu ukáže hrubý příjem, odvody zaměstnance, daň a bonus, čistou mzdu před
srážkami, jednotlivé srážky s titulem a pořadím, exekuční srážku a částku
k výplatě včetně rozdělení mezi platební cíle. Údaje se čtou ze zmrazené
revize, takže je pozdější změna dohody ani výplatního pravidla nezmění.
Zobrazí se vždy jen vybraná osoba a bankovní cíl jen maskou účtu.

### 80.11.7 Co se děje při schválení

Výpočet odděluje hotovost zahrnutou do exekučního základu od částek, které se
nesrážejí (například správně klasifikované cestovní náhrady). Vypočtená
srážka sníží částku k výplatě, ale neměnný výsledek a evidence **sraženo /
deponováno** vzniknou až se schválením. Neúplné důkazy, více plátců bez
ověřeného rozdělení nebo jiný stav vyžadující posouzení schválení zablokují.

Schválení promítne vypočtené srážky do evidence, která se jen doplňuje.
Oprava nepřepíše původní pohyb: zvýšení přidá jen rozdíl a snížení vytvoří
reverzi navázanou na původní sražení. Opakované schválení částku nezapíše
podruhé.

Schválení zároveň vytvoří výplatní pásku každé zpracované osoby a v podvojném
účetnictví rozdílový mzdový deník. Použijí se předkontace zmrazené při
uzamknutí vstupů, takže pozdější změna nastavení nezmění zkontrolovanou
revizi. Je-li účetní období uzamčené, datum deníku se posune na první otevřený
den. Schválení, zákonné kumulace, účetní zápis i pásky tvoří jeden celek:
selže-li některý krok, běh zůstane **Zkontrolováno** a nevznikne částečně
schválená mzda.

> [!WARNING]
> Produkční schválení vyžaduje odborně schválená a aktivní legislativní
> pravidla pro příslušné období. Neaktivní nebo neúplná pravidla výpočet
> označí pro ruční kontrolu a schválení zablokují. Chybějící zákonné údaje
> aplikace neodhaduje.

V podvojném účetnictví pak pro schválenou revizi porovnáte mzdy, deník
a platební závazky na stránce `Mzdy → Shoda účtování mezd` (viz
[Shoda účtování mezd](81_Shoda_uctovani_mezd.md)). Stránka je jen informační
a nic nezapisuje.

### 80.11.8 Obnovení podkladů

Zamknutí vstupů i otevření opravy zmrazí snímek podkladů a **Přepočítat**
počítá vždy z něj. Karta rozpracovaného běhu (vstupy uzamčeny, spočítáno nebo
otevřená oprava) hlídá, jestli od zmrazení přibyly nebo se změnily schválené
mzdové vstupy, nepřítomnosti, pracovní vztahy v období nebo zákonná evidence
osob.

**Obnovit podklady** založí novou revizi téhož druhu (opravná zůstává
opravnou) ze stejných podkladů, jaké by vzalo zamknutí vstupů, nově schválené
vstupy zamkne a běh vrátí k přepočtu. Rozpracovaná revize zůstane v historii
jako zahozená. Schválenou revizi obnova nikdy nemění. Výjimky schválené
u varování předchozí revize se nepřenášejí, nová revize je vyžaduje znovu.
Obnovit podklady smí uživatel s právem počítat mzdy a zapisovat mzdové vstupy.

Schválení revize, ke které existují novější podklady, aplikace odmítne. Nejde
o varování s výjimkou: schválený vstup, který by revize vynechala, by zůstal
navždy nezaúčtovaný a nevyplacený.

### 80.11.9 Hromadné doplnění výchozí zákonné evidence

Po převzetí zaměstnanců z importu nemají osoby daňovou rezidenci ani
příslušnost k pojištění. Chybějící záznam o slevě pracujícího důchodce výpočet
nezastaví: slevu uplatňuje zaměstnanec sám, takže bez záznamu se neuplatňuje.

Náhled bere všechny osoby, které by vzal mzdový běh za daný měsíc, a ukáže:

- kolika osobám se doplní česká daňová rezidence, česká příslušnost
  k pojištění bez formuláře A1 a neuplatňování slevy pracujícího důchodce
  (sleva jen u osob mladších 60 let se známým datem narození),
- vyřazené osoby s důvodem: cizí prvek (adresa v cizině, cizí občanství,
  povolení k pobytu nebo práci, cizí legislativa, formulář A1, zahraniční
  pojištění nebo daňový režim, zahraniční údaj v evidenci), chybějící
  pracovní vztah v měsíci nebo vztah ukončený ve schváleném období,
- osoby bez zdravotní pojišťovny s odkazem na **Zdravotní pojišťovny
  hromadně**; výchozí stav pojišťovnu nezná, takže ji tato akce nedoplní,
- osoby bez prohlášení poplatníka.

Existující záznam se nikdy nepřepíše ani neukončí. Údaje platí od prvního dne
měsíce, u pozdějšího nástupu od měsíce nástupu. Do období se schválenou mzdou
se nezapisují a platí až od dalšího měsíce. Volba **Zapsat chybějící
prohlášení jako nepodepsané** je ve výchozím stavu vypnutá. Náhled u ní
jmenovitě vypíše osoby, kterým by nepodepsané prohlášení mohlo přepnout
zdanění na srážkovou daň. Týká se to každého druhu vztahu, i pracovního
poměru: bez prohlášení se sráží v každém měsíci, kdy příjem od vás nedosáhne
rozhodné částky (§ 6 odst. 4 ZDP). Zápis jde osobu po osobě stejnou cestou
jako karta osoby.

Spočítaný běh počítá ze zmrazeného snímku, samotný přepočet proto doplněné
údaje nevezme. Po uložení dialog nabídne další krok: u konceptu **Spočítat
mzdy**, u rozpracovaného běhu **Obnovit podklady** (potom spočítejte mzdy),
u zrušeného nebo opravného běhu otevření nové revize a u schváleného běhu
**Vyžádat opravu**.

Měsíční hlášení JMHZ ani export zaměstnanců ČSSZ kód zdravotní pojišťovny
nenesou. Po převzetí z hlášení ji proto v evidenci nemá nikdo a výpočet
zdravotního pojištění skončí chybou. **Zdravotní pojišťovny hromadně**
vypíše osoby, kterým ve zvoleném měsíci trvá vztah a pojišťovnu nemají.
Výchozí měsíc platnosti je měsíc nástupu. Zápis jde osobu po osobě do zákonné
evidence stejně jako na kartě osoby a výsledek ukáže, u koho se uložit
nepodařilo a proč.

Výjimky z minima zdravotního pojištění (například péče o dítě do 7 let
potvrzená pojišťovnou) a člena družstva nebo SVJ hromadné akce nedoplňují;
evidují se na kartě osoby a vztahu, viz [Zaměstnanci](86_Zamestnanci.md).

### 80.11.10 Místo výkonu práce pro JMHZ

Měsíční hlášení ČSSZ vyžaduje u každého vztahu místo výkonu práce jako kód
obce a státu z číselníku ČSSZ. Místo výkonu práce je sjednané v pracovní
smlouvě, aplikace ho proto neodhaduje. Náhled za zvolený měsíc ukáže, kolik
vztahů pracoviště nemá, kolik ho má ověřené a kolik vyplněné, ale
neověřitelné. Neověřitelné se nepřepisuje; opravte ho na kartě vztahu.

Obec a stát se ověří proti číselníku ČSSZ platnému po celý vykazovaný měsíc
a zapíší se jako oprava platné verze podmínek vztahu, stejně jako na kartě
vztahu, včetně záznamu v historii změn. Vyplněné pracoviště se nikdy
nepřepíše. Vztahy hlášené formulářem vězně, jiného příjmu nebo
mezinárodního pronájmu pracovní síly pracoviště nevyžadují.

### 80.11.11 Varování a jejich skrytí

Kontroly běhu se dělí na **blokující chyby** (zastaví výpočet nebo
schválení), **varování s výjimkou** (schválení čeká, dokud k nim neschválíte
výjimku) a **varování** (informace, schválení nezastaví). Varování vidíte
v kontrole před zahájením a na kartě běhu do jeho schválení. Schválením je
odsouhlasíte, takže u schváleného, zaúčtovaného, placeného i uzavřeného běhu
karta ukazuje jen blokující chyby a odkaz **Varování při výpočtu (N)**, který
je na požádání rozbalí.

Skrytí platí pro všechny další i už existující běhy, dokud ho neobnovíte.
**Skrýt tento typ ve firmě** platí i pro nové osoby. Skrytá varování se
nepočítají do kontrol. Souhrnné varování o počtu osob s nepodepsaným
prohlášením se po skrytí části osob ukáže se sníženým počtem a zmizí, až jsou
skryté všechny dotčené osoby.

Přehled skrytí v `Mzdy → Nastavení mezd`, záložce **Skrytá varování**, je
seskupený podle typu, s tím, kdo a kdy varování skryl a proč. Obnovit jde
jednotlivě i celou skupinu. Skrytí i obnovení se zapisují do auditního logu.
Skrývat a obnovovat smí uživatel s právem schvalovat mzdové běhy.

Skrýt nejde blokující chyby, varování s výjimkou ani překročení zákonných
limitů (dohoda o provedení práce nad 300 hodin, průměr dohody o pracovní
činnosti, přesčasy, exekuce, sleva na pojistném).

### 80.11.12 Převzaté měsíce roku přechodu

Firma, která přešla z jiného mzdového programu uprostřed roku, má v roce
přechodu měsíce vedené původním programem a měsíce, které počítá MyÚčto.
Hranicí je první mzdový měsíc nastavený u mzdového modulu. Za měsíc pod
hranicí mzdový běh založit nejde, MyÚčto ten měsíc nepočítalo.

Panel **Převzaté měsíce roku přechodu** zabírá ve výchozím stavu jeden řádek
s rozsahem převzatých měsíců a hranicí. Tlačítkem **Zobrazit** se rozbalí
seznam historických měsíců s převzatými mzdami (z převodu z PAMICA, POHODY,
PREMIER nebo z importu CSV/XLSX). Dokud nějaký měsíc čeká na převzetí, panel
se rozbalí sám. Rozbalení si aplikace pamatuje pro každého uživatele v jeho
prohlížeči. Panel se ukazuje jen při výběru období v roce přechodu (jiný rok
jen tehdy, když v něm měsíc čeká na převzetí) a u firmy bez převzatých měsíců
se nezobrazí. Převod z Money S3 převzaté mzdy jednotlivých zaměstnanců nemá
(Money je vede v šifrované databázi agendy); protokol převodu ukáže jen
kontrolní úhrny celé firmy po měsících, viz
[§ 103.10.2.2](103_Prechod_z_Money_S3.md#1031022-mzdy).

**Převzatý běh** je zrcadlo cizího výpočtu, ne pracovní dokument:

- Nic se nepočítá. Výsledek vzniká součtem převzatých řádků za měsíc;
  chybějící veličina zůstane nulová a nedopočítává se.
- Neprochází workflow. Nemá revizi, nejde uzamknout, přepočítat,
  zkontrolovat ani schválit. V seznamu běhů má štítek **Převzato**.
- Nezaúčtovává se. Doklady za historická období jsou v účetnictví už
  z převodu, druhý zápis vzniknout nesmí ani nemůže.
- Neopravuje se. Cestou zpět je **Zrušit převzetí** s důvodem.
- Nevstupuje do zákonných výstupů. Evidenční list, roční zúčtování,
  potvrzení o zdanitelných příjmech, vyúčtování daně ani hlášení pro ČSSZ
  a zdravotní pojišťovny z převzatého běhu nečerpají. Převzatá čísla pro ně
  mají vlastní cestu a nikdy se nesmí dostat do tiskopisu jako údaj
  spočítaný aplikací.

#### Doložení plateb za historický měsíc

Pod **Zobrazit doložení plateb** rozlišuje aplikace dvě jistoty:

- **Čistá mzda** je po osobách. Nese-li převzatý záznam datum výplaty,
  zobrazí se u částky; jinak zůstane sloupec **Zaplaceno** prázdný
  s poznámkou *datum neznámé*.
- **Odvody a srážky** (sociální a zdravotní pojištění, záloha na daň,
  srážková daň, daňový bonus, srážky ze mzdy) jsou součtem složek převzaté
  mzdy za celou firmu. Datum u nich není a nedoplňuje se, převzatá data
  nenesou doklad o odeslané platbě. Záloha na daň a daňový bonus zůstávají
  zvlášť, protože jejich rozdíl by byl dopočet aplikace, ne doložení.

Doložení plateb **není platba**. Nevzniká z něj platební závazek, dávka ani
úhrada, takže se neobjeví v saldu mzdových příkazů, v hlídači termínů ani
v účetním deníku. Za historický měsíc MyÚčto nikdy nic nedlužilo: závazek
vznikl i zanikl v původním programu a jeho účetní obraz je v knihách
z převodu. Otevřený závazek by trvale znečistil saldo dávno zaplacenými
částkami. Pro měsíce, které počítá MyÚčto, fungují mzdové příkazy, úhrady
i saldo dál přes [mzdové příkazy a úhrady](82_Platby_a_uhrady.md).

## 80.12 Související kapitoly

- [Úplné mzdy](75_Uplne_mzdy.md): průvodce celým mzdovým měsícem.
- [Absence a dovolená](76_Absence_a_dovolena.md) a
  [Docházka a směny](77_Dochazka_a_smeny.md): podklady běhu.
- [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md) a
  [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md).
- [Shoda účtování mezd](81_Shoda_uctovani_mezd.md),
  [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md),
  [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md),
  [Podání a hlášení](85_Podani_a_hlaseni.md).
- [Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md).
