# 125. Časové rozlišení

> Agenda pro náklady a výnosy příštích období, které se rozpouštějí podle smlouvy: položka nese období,
> základ a metodu, z nich vznikne plán rozpouštění a jeho řádky zaúčtujete do deníku. Agenda drží zůstatek
> každé smlouvy na účtech 381 a 384 a odsouhlasuje ho s hlavní knihou. Pro účetní firmy v podvojném účetnictví.

## 125.1 Kdy to potřebujete

Agendu otevřete, když:

- máte předplacený náklad na více období (pojistné, nájem, licence, předplatné) zaúčtovaný na **381 Náklady
  příštích období** a chcete ho rozpouštět po měsících nebo po letech,
- máte přijatý výnos patřící do dalších období (nájem, servisní smlouva, předplatné) zaúčtovaný na
  **384 Výnosy příštích období**,
- potřebujete ke dni soupis zůstatků 381 a 384 po smlouvách a jejich odsouhlasení s hlavní knihou,
- smlouva skončila dřív a zbývající zůstatek je třeba odepsat,
- převádíte firmu z POHODY a chcete navázat její agendu časového rozlišení.

Agenda je v menu **Účetnictví → Časové rozlišení**, hned pod interními doklady.

## 125.2 Než začnete

1. **Podvojné účetnictví.** V daňové evidenci agenda není dostupná.
2. **Oprávnění.** Čtení stačí k prohlížení a exportu, správa a zaúčtování potřebují další oprávnění
   ([§ 125.10.10](#1251010-opravneni)).
3. **Zápis vzniku.** Agenda účtuje jen rozpouštění a ukončení. Částku na 381 nebo 384 musí do deníku
   zapsat doklad, ke kterému položka patří: přijatá či vydaná faktura s řádkem na 381 či 384, ostatní
   pohledávka nebo závazek, interní doklad nebo převzatý zápis. Položka tento zápis naváže a z vazeb počítá
   svůj zůstatek.
4. **Účty v osnově.** Účet položky (381 nebo 384, případně jejich analytika), protiúčet rozpouštění
   a protiúčet ukončení musí být v [účtovém rozvrhu](66_Ucetni_osnova.md).

## 125.3 Dvě cesty časového rozlišení

MyÚčto rozlišuje náklady a výnosy příštích období dvěma způsoby. Každý doklad patří vždy jen jedné cestě.

<!-- cols: 22 39 39 -->
| | Položka agendy | Období na řádku dokladu |
|---|---|---|
| Kde vzniká | V agendě, ručně nebo z dokladu | Pole období od a do na řádku faktury nebo u ostatní pohledávky a závazku |
| Zaúčtování dokladu | Na 381 nebo 384 | Celé do nákladu nebo výnosu |
| Odklad | Už je v zápisu dokladu | Krok uzávěrky **Časové rozlišení** odloží budoucí část k rozvahovému dni |
| Rozpouštění | Řádky plánu agendy (měsíčně či ročně) | Otevření dalšího roku |
| Vhodné pro | Smlouvy, které chcete sledovat a rozpouštět průběžně | Jednorázové doklady, kde stačí roční odklad |

Záložka **Spravuje uzávěrka** v agendě jen zobrazuje řádky dokladů s obdobím, které odkládá uzávěrka,
a jejich budoucí část k datu. Nic se tam neukládá ani neúčtuje. Postup uzávěrky popisuje
[§ 72.4.4](72_Uzaverka.md#7244-kroky-4-a-5-dohadne-polozky-a-casove-rozliseni).

Jakmile k řádku dokladu založíte a aktivujete položku agendy, uzávěrka tento řádek přestane odkládat
a otevření roku ho nerozpouští. Ostatní řádky dokladu s obdobím odkládá uzávěrka dál. Koncept položky
doklad ještě nepřebírá. Má-li doklad řádky s obdobím, editor je nabídne v poli **Řádek dokladu, který položka
spravuje** a předvyplní řádek, který odpovídá částkou a obdobím. Volba **Žádný** znamená, že položka nepřebírá
žádný řádek a uzávěrka odkládá všechny řádky dokladu dál. Řádek, který neodpovídá částkou ani obdobím,
se položce sám nepřiřadí, ani když je na dokladu jediný.

Řádek, který už uzávěrka odložila, převezmete do agendy z panelu **Časové rozlišení** v detailu dokladu
akcí **Převzít do agendy**. Položka dostane zůstatek k datu, od kterého ho rozpouští (den po posledním
rozpuštění v otevření roku), naváže se na zápis odkladu a plán začne tímto dnem. Řádek dokladu převezme
ze záznamu kroku uzávěrky, takže otevření roku tranši znovu nerozpustí, ani když se mezitím změnilo období
řádku. Odložila-li uzávěrka víc řádků dokladu, vyberte, který položka převezme.

Položka založená z interního dokladu, který není vazbou **Souvisí** spojený se zdrojovou fakturou, ukáže
upozornění: uzávěrka by fakturu s obdobím od–do odložila znovu.

## 125.4 Krok za krokem: založit položku

### 125.4.1 Ručně

1. Otevřete `Účetnictví → Časové rozlišení` a založte novou položku.
2. Zvolte **druh** (náklady příštích období 381, nebo výnosy příštích období 384) a **účet** položky.
3. Vyplňte název, základ, období od a do, metodu rozpouštění a **protiúčet** (nákladový nebo výnosový
   účet, na který se rozpouští). Volitelně doplňte protiúčet ukončení, partnera, číslo smlouvy
   a středisko ([§ 125.10.1](#125101-pole-polozky)).
4. Zkontrolujte **náhled plánu** pod formulářem. Přepočítává se při každé změně základu, období a metody.
5. Nechte zaškrtnuté **Aktivovat hned**, nebo položku uložte jako koncept a aktivujte později.
   Aktivovat jde jen položku s navázaným **zápisem vzniku** (kromě ruční metody), jinak by plán neměl
   z čeho rozpouštět.
6. Ruční položku bez zdrojového dokladu uložte jako koncept, zápis vzniku jí přiřaďte v odsouhlasení
   ([§ 125.7](#1257-krok-za-krokem-odsouhlasit-agendu-s-hlavni-knihou)) a teprve potom ji aktivujte.

**Jak poznáte, že je hotovo:** Položka má stav **Aktivní**, v detailu je plán rozpouštění a zůstatek
odpovídá zápisu vzniku.

### 125.4.2 Z dokladu

Detail vydané faktury, přijaté faktury, ostatní pohledávky či závazku a interního dokladu ukazuje panel
**Časové rozlišení**, pokud doklad účtuje na účty 381 až 385 nebo k němu položka už existuje.

1. V panelu zkontrolujte řádky dokladu na účtech časového rozlišení a navázané položky.
2. U řádku bez položky zvolte **Založit položku**. Editor převezme zdrojový doklad, partnera, účet, částku
   a zápis vzniku. Má-li řádek dokladu vyplněné období, předvyplní ho také. U faktury s řádky s obdobím
   zkontrolujte vybraný řádek dokladu ([§ 125.3](#1253-dve-cesty-casoveho-rozliseni)).
3. Doplňte metodu a protiúčet a položku uložte.

Ze stejného panelu otevřete existující položku dokladu.

### 125.4.3 Z řádku bez položky

V odsouhlasení s hlavní knihou je seznam zápisů na 381 až 385, které nenese žádná položka
([§ 125.10.6](#125106-odsouhlaseni-a-radky-bez-polozky)). U řádku zvolte **Založit položku**. Editor předvyplní
druh podle účtu, účet, základ ve výši nenavázané části a zápis vzniku.

## 125.5 Krok za krokem: zaúčtovat rozpuštění

1. Otevřete detail položky. Splatné řádky plánu (datum řádku je dnes nebo dřív a řádek není zaúčtovaný)
   nabízejí akci **Zaúčtovat**. Seznam položek lze seřadit podle nejbližšího rozpuštění.
2. Před zaúčtováním se zobrazí **náhled zápisu**: datum, účty, strany a částka. Náhled hlásí, je-li datum
   v uzavřeném či uzavíraném období nebo za zámkem účtování k datu
   ([§ 52.11](52_Ucetni_denik.md#5211-krok-za-krokem-zamek-uctovani-k-datu)). Takový řádek zaúčtovat nejde.
3. Potvrďte zaúčtování. Vznikne zápis k poslednímu dni úseku (u měsíční metody ke konci měsíce),
   s číslem dokladu podle čísla položky.

Řádky se účtují po sobě: zaúčtovat jde jen nejstarší nezaúčtovaný řádek, který lze zaúčtovat. Starší řádek,
jehož datum leží v uzavřeném období nebo za zámkem účtování k datu, pořadí neblokuje: zaúčtovaný řádek
převezme jeho částku a úsek a starší řádek z plánu zmizí. Zápis nikdy nerozpustí víc, než je zůstatek položky
v deníku (ručně přiřazené rozpuštění zůstatek už snížilo).

Zaúčtované rozpuštění vezmete zpět akcí **Stornovat** u řádku, vždy od posledního zaúčtovaného. Vznikne
protizápis ke dni původního zápisu a řádek se vrátí do plánu. Storno jde jen v otevřeném a neuzamčeném
období a ne u ukončené položky (nejdřív zrušte ukončení).

**Jak poznáte, že je hotovo:** Řádek má stav **Zaúčtováno** s odkazem na zápis v deníku a zůstatek položky
se snížil. Po zaúčtování posledního řádku s nulovým zůstatkem přejde položka do stavu **Dokončená**.

## 125.6 Krok za krokem: předčasně ukončit položku

1. V detailu aktivní položky zvolte ukončení a zadejte **datum ukončení**, případně jiný **protiúčet
   ukončení** a důvod.
2. Náhled ukáže jeden zápis o dvou částech:
   - **rozpuštění** za dny od posledního zaúčtovaného rozpuštění do data ukončení na protiúčet rozpouštění,
   - **zbytek** zůstatku k datu ukončení na protiúčet ukončení (bez něj na protiúčet rozpouštění).
3. Jsou-li po datu ukončení zaúčtovaná rozpuštění, náhled je vypíše a nabídne jejich storno. Rozpuštění
   převzaté z jiného programu se stornují jen po výslovném potvrzení.
4. Potvrďte ukončení. Položka přejde do stavu **Ukončená** a nezaúčtované řádky plánu se zruší.

Datum ukončení nesmí být před koncem posledního zaúčtovaného rozpuštění, které datum ukončení přesahuje.
Aplikace v tom případě ohlásí den, od kterého ukončení jde, nebo to rozpuštění nejdřív stornujte.
Zápis ukončení i storna budoucích rozpuštění musí ležet v otevřeném a neuzamčeném období.

**Zrušení ukončení** zapíše storno zápisu ukončení ke dni ukončení, vrátí položku mezi aktivní a dopočte
plán. Jde jen tehdy, když je den ukončení v otevřeném a neuzamčeném období. Stornovaná budoucí rozpuštění
se nevracejí, plán je dopočte znovu.

## 125.7 Krok za krokem: odsouhlasit agendu s hlavní knihou

1. V agendě otevřete odsouhlasení a zvolte datum.
2. Tabulka po účtech 381 až 385 ukazuje: **hlavní kniha** (zůstatek účtu k datu), **položky** (součet
   zůstatků položek agendy), **odklad uzávěrky** (zůstatek zápisů kroku Časové rozlišení a jejich rozpuštění)
   a **rozdíl**.
3. Je-li rozdíl nenulový, otevřete seznam **řádků bez položky**. U každého zápisu zvolte:
   - **Přiřadit k položce**: zápis se naváže na vybranou položku se stejným účtem (celá nenavázaná část,
     nebo zadaná částka). Plán položky se přepočte na nový zůstatek,
   - **Založit položku**: viz [§ 125.4.3](#12543-z-radku-bez-polozky).
4. Ruční vazbu omylem přiřazenou odeberete v detailu položky. Vazbu ze zaúčtování agendy tak zrušit nejde,
   použijte storno rozpuštění nebo ukončení. Vazbu převzatou z POHODY také ne: položku zrušte nebo
   převezměte znovu. Zrušená položka své vazby uvolní a zápisy se vrátí mezi řádky bez položky.
   Poslední zápis vzniku aktivní položky odebrat nejde, nejdřív přiřaďte jiný nebo položku zrušte.

**Jak poznáte, že je hotovo:** Rozdíl je na všech účtech nulový a kontrola rozdílu agendy v uzávěrce
nehlásí varování.

## 125.8 Krok za krokem: převzít časové rozlišení z POHODY

Po převodu účetnictví z POHODY ([kap. 107](107_Prechod_z_POHODY.md)) jsou zápisy její agendy Časové
rozlišení v deníku jako ruční zápisy s čísly typu `24CR0001`.

1. V agendě spusťte převzetí z POHODY. Průvodce nejdřív ukáže náhled: navržené položky, ukončení, rozpad
   počátečního stavu po účtech a souhrn (počet položek, nové, ukončené, bez zdrojového dokladu,
   nerozepsaný zůstatek).
2. Projděte položky bez spárovaného zdrojového dokladu a nespárovaná ukončení.
3. Potvrďte převzetí. Položky se založí, převzaté zápisy se navážou a plán za nimi dopočte agenda.
   **Nic se neúčtuje.**
4. Zkontrolujte položku **Nerozepsaný zůstatek PS** (pokud vznikla) a odsouhlasení s hlavní knihou.
5. Projděte **upozornění k převzetí**. Položka, jejíž zdrojový doklad nemá v deníku zápis na účtu položky
   ani podíl na počátečním stavu, se převezme jako **koncept** bez plánu. Přiřaďte jí zápis vzniku
   v odsouhlasení a aktivujte ji.

Převzetí lze spustit opakovaně, například po dalším převodu deníku. Existující položky se nepřepíšou,
doplní se jen nové zápisy a přepočte nerozepsaný zůstatek. Pravidla párování popisuje
[§ 125.10.9](#125109-prevzeti-z-pohody).

## 125.9 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Druh nejde zvolit, jen 381 a 384 | Agenda rozpouští náklady a výnosy příštích období | Účty 382, 383 a 385 se zobrazují jen v odsouhlasení. |
| Položku nejde aktivovat | Chybí protiúčet rozpouštění | Doplňte protiúčet, nebo zvolte ruční metodu. |
| Položku nejde aktivovat, chybí zápis vzniku | Položka nemá navázaný zápis, ze kterého se rozpouští | Přiřaďte zápis vzniku v odsouhlasení a pak položku aktivujte. |
| Základ je odmítnut | Základ je záporný | Zadejte kladný základ. Dobropis přiřaďte k původní položce jako zápis, nebo založte položku opačného druhu. |
| Editor chce vybrat řádek dokladu | Částce a období odpovídá víc řádků dokladu | Vyberte řádek, který položka spravuje, nebo zvolte **Žádný**. |
| Protiúčet je odmítnut | Protiúčet nesmí být účet časového rozlišení (38x) | Zvolte nákladový, výnosový nebo jiný rozvahový účet. |
| Řádek plánu nejde zaúčtovat | Datum řádku je v budoucnu, nebo období je uzavřené, uzavírané či uzamčené | Zaúčtujte ho v den konce úseku nebo později, v otevřeném období. |
| Zůstatek položky je záporný nebo nulový | Položka nemá navázaný zápis vzniku | Přiřaďte zápis v odsouhlasení ([§ 125.7](#1257-krok-za-krokem-odsouhlasit-agendu-s-hlavni-knihou)). |
| Ukončení hlásí rozpuštění po datu | Po datu ukončení jsou zaúčtovaná rozpuštění | Potvrďte jejich storno, u převzatých výslovně. |
| Ukončení odmítne datum | Datum je před koncem zaúčtovaného rozpuštění | Zvolte pozdější datum nebo rozpuštění stornujte. |
| Ukončení nemá co zaúčtovat | Položka nemá k datu žádný zůstatek | Zkontrolujte vazby a datum. |
| Ukončení nejde zrušit | Den ukončení je v uzavřeném nebo uzamčeném období | Zrušení jde jen v otevřeném a neuzamčeném období. |
| Položku nejde zrušit ani smazat | Má zaúčtované rozpuštění agendy | Rozpuštění nejdřív stornujte. |
| Druh nebo účet nejde změnit | Položka má zaúčtované nebo převzaté rozpuštění | Založte novou položku. |
| Uzávěrka hlásí rozdíl agendy | Na 381 až 385 jsou zápisy bez položky | Přiřaďte je nebo z nich založte položky. |

## 125.10 Podrobnosti a pravidla

### 125.10.1 Pole položky

- **Číslo**: bez zadání `CR-RRRR-NNNN` podle roku začátku období.
- **Název**, **číslo smlouvy**, **poznámka**.
- **Druh**: **náklady příštích období** (381, normální strana MD) nebo **výnosy příštích období** (384,
  normální strana Dal). Zůstatky se v agendě ukazují kladně na normální straně.
- **Účet**: 381 nebo 384, případně jejich analytika.
- **Protiúčet**: účet, na který se rozpouští (náklad u 381, výnos u 384). Povinný pro všechny metody kromě
  ruční.
- **Protiúčet ukončení**: účet pro zbytek zůstatku při předčasném ukončení, například mimořádný náklad.
  Bez něj se zbytek ukončí na protiúčet rozpouštění. Při ukončení ho lze změnit.
- **Základ**: kladná částka, která se za celé období rozpustí. Směr určuje druh, záporný základ editor
  i náhled plánu odmítnou.
- **Řádek dokladu**: u položky z faktury řádek s obdobím, který položka spravuje ([§ 125.3](#1253-dve-cesty-casoveho-rozliseni)).
- **Období od a do** a **metoda** ([§ 125.10.3](#125103-metody-rozpousteni)).
- **Partner**, **středisko** (přenáší se na řádek protiúčtu zápisu) a zdrojový doklad.
- **Aktivovat hned**: položka se uloží rovnou jako aktivní s plánem, jinak jako koncept.
- **Náhled plánu**: živý výpočet řádků plánu podle zadaných údajů.

Upravit lze koncept a aktivní položku. Jakmile má položka navázaný zápis (vznik, rozpuštění, převzatý
zápis), druh a účet zůstávají pevné. Změna poznámky, názvu či protiúčtu plán nemění. Změna základu,
období nebo metody přepočte nezaúčtované řádky: zaúčtované a převzaté řádky i nezaúčtované řádky před nimi
zůstávají na svém místě a zbývající zůstatek položky se rozloží do nezaúčtovaných řádků poměrem dnů.
Plán se tedy řídí zůstatkem podle navázaných zápisů, ne zadaným základem. Editor na to při změně základu
upozorní: aby se změna promítla do plánu, navažte zápis s novou částkou.
Když položku mezitím upravil někdo jiný, uložení odmítne a vyzve k novému načtení.

### 125.10.2 Stavy položky

<!-- cols: 22 78 -->
| Stav | Význam |
|---|---|
| Koncept | Uložená položka bez plánu. Lze ji upravit, aktivovat, zrušit nebo smazat. |
| Aktivní | Má plán rozpouštění a rozpouští se. |
| Dokončená | Všechny řádky plánu jsou zaúčtované nebo převzaté a zůstatek je nulový. Storno rozpuštění ji vrátí mezi aktivní. |
| Ukončená | Předčasně ukončená jedním zápisem k datu ukončení. Zrušením ukončení se vrátí mezi aktivní. |
| Zrušená | Zrušená bez zaúčtovaného rozpuštění agendy. Nepočítá se do odsouhlasení. |

### 125.10.3 Metody rozpouštění

<!-- cols: 26 74 -->
| Metoda | Plán |
|---|---|
| Měsíčně podle dnů | Řádek za každý kalendářní měsíc ke konci měsíce, první a poslední měsíc poměrně. Částka je rozdíl kumulativně uznané části podle dnů včetně obou krajů, takže součet řádků je přesně základ bez haléřového zbytku. Stejně počítá uzávěrka i POHODA. |
| Měsíčně rovným dílem | Řádek za každý měsíc období, každý měsíc stejným dílem bez ohledu na počet dnů. Haléřový zbytek nese poslední řádek. |
| Ročně | Řádek za každý kalendářní rok k 31. 12., poslední ke konci období, částka podle dnů. |
| Ručně | Bez plánu. Rozpouští se ručními zápisy, které k položce přiřadíte. |

Pokračuje-li plán za zaúčtovanými nebo převzatými řádky, začíná dnem po nich a cílem je zbývající
zůstatek. Rozdíl mezi zbývajícím zůstatkem a součtem plánu vyrovná poslední řádek. Nezaúčtované řádky
s datem v uzavřeném období (například v uzavřeném minulém roce) se při přepočtu přičtou k prvnímu řádku
v otevřeném období, takže neblokují položku ani uzavření dalšího roku. Řádky v uzavíraném období zůstávají,
zaúčtuje je krok uzávěrky. Řádek, který nese rozpuštění za uzavřené období, detail položky i náhled
zaúčtování označí upozorněním s jeho částkou: účtuje se do běžného období a u významné částky zvažte opravu
minulého období (426/428).

### 125.10.4 Zápisy agendy

Rozpuštění snižuje zůstatek položky:

<!-- cols: 34 66 -->
| Druh | Zápis rozpuštění |
|---|---|
| Náklady příštích období | MD protiúčet (náklad) / D 381 |
| Výnosy příštích období | MD 384 / D protiúčet (výnos) |

Zápisy rozpuštění a ukončení mají v deníku vlastní druh zdroje (rozpuštění, resp. ukončení časového
rozlišení). Číslo dokladu
je číslo položky a popis nese číslo a název položky. Z deníku i z opisu účtu vede zdroj zápisu na detail
položky. Zápis agendy nejde zaúčtovat dvakrát na tentýž řádek plánu. Kontrolu období, zámku k datu
a vyrovnanosti dělá stejné účtovací jádro jako u ostatních dokladů, zámek k datu potvrzením obejít nejde.

Zápis předčasného ukončení spojí rozpuštění do data ukončení a zbytek zůstatku do jednoho zápisu ke dni
ukončení. Rozpuštění za rozpracovaný úsek se počítá stejně jako plán (u metody rovným dílem poměrně
ve zbývajícím měsíci). Převzatý řádek plánu (z POHODY) agenda sama nestornuje bez výslovného potvrzení.

### 125.10.5 Zrušení a smazání položky

- **Zrušit** lze koncept nebo aktivní položku, dokud nemá zaúčtované rozpuštění agendy. Nezaúčtované řádky
  plánu se odstraní. Zrušená položka se do odsouhlasení nepočítá.
- **Smazat** lze koncept nebo zrušenou položku bez zaúčtování. Položka s historií zaúčtování se nemaže,
  rozpuštění nejdřív stornujte.

### 125.10.6 Odsouhlasení a řádky bez položky

Zůstatek účtu v hlavní knize se počítá jako v obratové předvaze: počáteční stav a pohyby účetního období,
do kterého datum patří, bez závěrkového zápisu. Rozpadá se na tři části:

- **položky**: vazby položek agendy na zaúčtované zápisy k datu (zrušené položky se nepočítají),
- **odklad uzávěrky**: zápisy kroku Časové rozlišení a jejich rozpuštění v otevření roku
  ([§ 125.3](#1253-dve-cesty-casoveho-rozliseni)),
- **rozdíl**: řádky na 381 až 385, které nenese ani položka, ani uzávěrka.

Seznam řádků bez položky vypíše zápisy období do data a jejich nenavázanou část. U počátečního stavu se za
navázanou bere část, kterou nesou položky a odklad uzávěrky z minulých let. Stornovaný zápis a jeho storno
se vzájemně ruší. Účet, jehož nenavázané řádky se k datu vyrovnají (dohad a jeho vypořádání), se nevypisuje.
Přiřadit lze jen zápis na stejném účtu, jaký má položka, a nejvýš jeho nenavázanou část. Zvýšení zůstatku
se přiřadí jako vznik, snížení jako rozpuštění.

### 125.10.7 Rozpis k datu

Export **Rozpis časového rozlišení k datu** (PDF nebo XLSX) z agendy vypíše položky podle aktuálních filtrů
seznamu po účtech: základ, rozpuštěno a zůstatek k datu, se součty a s odsouhlasením na hlavní knihu.
Slouží jako inventurní soupis účtů 381 až 385 k [inventarizaci](69_Inventarizace_rozvahovych_uctu.md).

### 125.10.8 Uzávěrka, průběžný výkaz a projekce DPPO

**Krok uzávěrky Časové rozlišení.** Blok agendy v kroku ukáže nezaúčtované řádky plánu splatné do
rozvahového dne (roční i měsíční). Když se období uzavírá, zaúčtuje je akce **Zaúčtovat rozpuštění agendy
do konce období** najednou; potřebuje oprávnění k uzavření období. V otevřeném období je zaúčtujte v agendě.

**Uzavření knih** se zastaví, dokud v agendě zůstává nezaúčtované rozpuštění splatné do konce období.

Uzávěrka ani otevření dalšího roku položky agendy neodkládají ani nerozpouštějí. Řádky dokladů, ke kterým
položka agendy existuje, vynechají.

**Kontrola rozdílu agendy.** Předběžné kontroly uzávěrky a [měsíční kontrola](62_Mesicni_kontrola.md)
porovnají agendu s hlavní knihou k datu a při rozdílu ohlásí varování **rozdíl agendy časového rozlišení
proti hlavní knize** s účty a částkami, spolu s počtem nezaúčtovaných splatných rozpuštění. Rozdíl uzavření
knih neblokuje, rozhoduje účetní. Firma bez položek agendy kontrolu nemá.

**Průběžný výkaz.** Volba **Průběžné časové rozlišení a odpisy** ve výsledovce a rozvaze
([§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni-a-odpisy)) přidá to, co agenda ještě
nezaúčtovala: u měsíčních metod řádky plánu splatné do data výkazu (rozpracovaný měsíc ne), u roční metody
poměrnou část podle dnů od posledního rozpuštění. Ruční metoda nepřidá nic. Zaúčtované rozpuštění je
v deníku a výkaz ho čte odtud. Rozpuštění nebo ukončení zaúčtované s datem po dni výkazu v deníku k tomu
dni není: výkaz z něj ukáže část do data výkazu, u ruční metody poměr dnů doby, kterou zápis kryje.

**Projekce DPPO.** Panel projekce závěrkových operací v přiznání k DPPO
([§ 43.11.7](43_Dan_z_prijmu.md#43117-jak-se-pocita-dppo)) zahrne nezaúčtované rozpuštění položek agendy do
konce období (náklad z 381 sníží výsledek, výnos z 384 ho zvýší). Po zaúčtování řádek z projekce zmizí.
Odklad dokladu, který převzala agenda, projekce nehlásí jako nezaúčtované rozpuštění minulého roku.

### 125.10.9 Převzetí z POHODY

- **Seskupení.** Ruční zápisy s číslem dokladu ve tvaru `RRCRnnnn` (například `24CR0001`) tvoří jednu
  položku. Účet položky je účet 38x s největším obratem skupiny, protiúčet nejčastější účet mimo 38x.
- **Zdrojový doklad** se páruje podle čísla na konci popisu zápisu: vydaná faktura podle variabilního symbolu,
  přijatá podle čísla dodavatele nebo variabilního symbolu, ostatní pohledávka či závazek podle čísla.
  Ze zdroje se převezme partner, začátek období (datum vystavení) a základ (řádek dokladu na účtu položky,
  jinak základ dokladu).
- **Metoda** podle rytmu: rozpuštění jen ke konci účetního roku znamenají ročně, rozpuštění od sebe méně než
  300 dní měsíčně podle dnů. Konec období se dopočte z denní sazby rozpouštění. Bez zdroje nebo konce období dostane položka
  ruční metodu.
- **Ukončení** se hledají v interních a ručních zápisech mimo řadu CR, které snižují 381 či 384 a v popisu
  mluví o ukončení, vyřazení nebo předčasném ukončení. Smlouva se pozná podle čísla v textu (`20240005`
  odpovídá `24CR0005`), jinak podle data posledního rozpuštění. Doklad pro více smluv se rozdělí podle
  odhadu jejich zůstatku k datu ukončení. Nespárovaná ukončení náhled vypíše zvlášť.
- **Počáteční stav.** Souhrnný počáteční stav účtu se rozepíše na položky: u ukončených smluv přesně
  (rozpuštění roku a ukončení), u běžících rekonstrukcí z dokladu a rytmu. Zbytek proti počátečnímu stavu
  připadne položce **Nerozepsaný zůstatek PS** (ruční metoda), takže odsouhlasení sedí na haléř a rozdíl je
  vidět. Rozepište ji na smlouvy nebo vyjasněte.
- **Navázání.** Převzaté zápisy roku (i předgenerované do budoucna) se navážou jako převzaté řádky plánu.
  Plán za nimi dopočte agenda.
- **Nic se neúčtuje** a převzetí je opakovatelné. Položky se poznají podle čísla z POHODY. Existující
  položka si ponechá svůj rozpad i úpravy; položku, kterou agenda ukončila, dokončila nebo kterou jste
  zrušili, převzetí nezmění. Převzatý zápis se nenaváže na úsek, který už rozpustila agenda (náhled ho ukáže
  jako konflikt), ani na zápis vzniku navázaný na jinou položku. Klesne-li nerozepsaný zůstatek PS na nulu,
  položka zbytku se zruší.
- **Položka bez zápisu vzniku.** Smlouva, jejíž zdrojový doklad nemá v deníku zápis na účtu položky ani
  podíl na počátečním stavu, se převezme jako koncept bez plánu a náhled ji uvede v upozorněních. Do
  odsouhlasení se počítá s převzatými zápisy, ale nic nehlásí k zaúčtování a neblokuje uzavření roku.
  Opakované převzetí ji doplní o nové zápisy a aktivuje, jakmile zápis vzniku najde.

### 125.10.10 Oprávnění

| Činnost | Potřebné oprávnění |
|---|---|
| Prohlížení agendy, odsouhlasení, panel v dokladu, export | **Časové rozlišení** |
| Založení, úprava, aktivace, zrušení a smazání položky, přiřazení zápisů, převzetí z POHODY | navíc **Spravovat položky časového rozlišení** |
| Zaúčtování a storno rozpuštění, ukončení a jeho zrušení | navíc **Zaúčtovat doklad** |
| Zaúčtování rozpuštění agendy v kroku uzávěrky | navíc **Zaúčtovat doklad** a **Uzavřít období** |

Oprávnění se nastavují v rolích ([kap. 101](101_Bezpecnost.md)). Agenda je jen pro podvojné účetnictví.

## 125.11 Související kapitoly

- [Uzávěrka](72_Uzaverka.md) - krok Časové rozlišení a odklad dokladů s obdobím
- [Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md) - zůstatky 381 až 385 k rozvahovému dni
- [Ostatní pohledávky a závazky](52_Ucetni_denik.md#521414-ostatni-pohledavky-a-zavazky) a [Interní doklady](52_Ucetni_denik.md#521415-interni-doklady)
- [Výsledovka - průběžné časové rozlišení](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni-a-odpisy)
- [Přechod z POHODY](107_Prechod_z_POHODY.md)
