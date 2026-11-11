# 77. Docházka a směny

> Návod, jak plánovat směny, zapisovat odpracovaný čas a schválit měsíc
> docházky, aby z něj vznikly zákonné příplatky a podklad pro mzdy. Pro
> mzdové účetní a vedoucí, kteří docházku vedou.

## 77.1 Kdy to potřebujete

Kapitolu otevřete, když:

- začíná měsíc a chcete zaměstnancům rozvrhnout směny,
- zapisujete skutečně odpracovaný čas, přesčas, noční nebo víkendovou práci,
- máte docházku v docházkovém systému nebo v tabulce a chcete ji nahrát,
- se blíží mzdy a musíte schválit měsíc docházky,
- zaměstnanec pracoval přesčas a čerpá za něj náhradní volno,
- zaměstnanec měl pracovní pohotovost,
- aplikace hlásí blížící se nebo překročený limit přesčasů nebo rozsahu dohody.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| na začátku měsíce | Rozvrhnout směny (jen nepravidelná pracovní doba) | `Mzdy → Docházka a směny`, u řádku **Další → Rozvrhnout směny podle kalendáře** |
| průběžně | Zapisovat skutečnost, přesčas a příznaky | `Mzdy → Docházka a směny` |
| průběžně | Zadat dovolenou, nemoc a jinou nepřítomnost | `Mzdy → Docházka a směny`, **Zadat nepřítomnost** nebo u řádku **Nepřítomnost** |
| před mzdovým během | Schválit měsíc docházky všem | `Mzdy → Docházka a směny`, **Schválit měsíc všem** |
| jednou při sjednání | Nastavit sazby příplatků | `Mzdy → Nastavení mezd`, záložka **Politiky a připravenost**; karta pracovního vztahu |

## 77.2 Než začnete

1. **Aktivní pracovní vztah s pracovním režimem.** Zaměstnanec musí mít
   aktivní vztah, úvazek a pracovní kalendář (karta pracovního vztahu
   v `Mzdy → Zaměstnanci`).
2. **Oprávnění.** Potřebujete mzdové oprávnění. Import docházky vyžaduje
   právo zapisovat docházku. Zásady příplatků na kartě vztahu mění jen
   uživatel s právem zapisovat do pracovních vztahů.
3. **Sazby příplatků.** Firemní výchozí sazby nastavíte v
   `Mzdy → Nastavení mezd`, záložce **Politiky a připravenost**, v části
   **Výchozí sazby příplatků (§ 114–118)**. Práce ve svátek a ve ztíženém
   prostředí navíc potřebuje sjednanou zásadu na kartě vztahu
   ([§ 77.5](#775-krok-za-krokem-sjednani-priplatku-u-pracovniho-vztahu)).
4. **Průměrný výdělek.** Pro příplatky a pohotovost musí být schválený
   průměrný výdělek pro rozhodné čtvrtletí.

## 77.3 Krok za krokem: docházka za měsíc

**Sjednaná hodinová sazba** se eviduje na kartě pracovního vztahu odděleně
od docházky a průměrného výdělku. Samotná sazba není mzdovou částkou:
[hodinový výpočet](79_Rychly_mesicni_vstup.md#79812-vypocet-hodinove-mzdy-a-dolozeni-naroku)
potřebuje také přesně doložené a schválené hodiny. Sazba nenahrazuje
schválený průměr pro příplatky. Pole **Hodinová sazba**
v importu docházky znamená pravděpodobný výdělek, nikoli sjednanou sazbu.
Nemá-li řádek podkladů žádnou částku mzdy, jen odpracované hodiny a sazbu,
import z nich mzdu dopočte (sazba × odpracované hodiny, na haléře) a vytvoří
mzdový vstup složky **Hodinová mzda podle docházky**. Souhrn importu ukáže,
komu a za kolik mzda vznikne a komu ne: bez hodin v podkladech mzda nevznikne
a vztahu s hodinovým odměňováním na kartě ji import nevytvoří, protože
z importovaného souhrnu hodin ho mzdový běh nespočítá. Takovému vztahu
přepněte na kartě způsob odměňování na měsíční (u dohody stačí 0 Kč)
a načtěte náhled znovu. Proto karta i hromadná úprava podmínek nedovolí
přepnout vztah s hodinami z importu na hodinové odměňování bez výslovného
potvrzení.
Podrobnosti jsou v [Zaměstnancích](86_Zamestnanci.md#866-krok-za-krokem-zmena-mzdy-uvazku-nebo-podminek-vztahu).

Nahoře na stránce je průvodce **Jak zpracovat měsíc** se třemi kroky:
odpracovaný čas, nepřítomnosti a schválení měsíce všem.

1. Otevřete `Mzdy → Docházka a směny` a zvolte měsíc v poli **Období**.
2. Dovolenou, nemoc, ošetřovné a jiné nepřítomnosti zadejte tlačítkem
   **Zadat nepřítomnost** (nebo **Nepřítomnost** u řádku zaměstnance).
   Mřížka pak tyto dny vynechá. U nepravidelné pracovní doby zkontrolujte
   naplánované směny: u řádku **Další → Rozvrhnout směny podle kalendáře**.
   Směny potřebuje náhrada mzdy při nemoci a za dovolenou.
3. Doplňte skutečně odpracovaný čas (**Skutečně odpracovaný čas**). V měsíční
   mřížce můžete použít **Vyplnit pracovní dny**, **Zkopírovat minulý měsíc**
   nebo **Import z CSV nebo XLSX** a změny uložit tlačítkem **Uložit mřížku**.
   Noční,
   víkendovou, svátkovou práci a ztížené prostředí zapište jako příznak
   **k** odpracované době, ne místo ní. Noc a víkend zapisovat nemusíte:
   bez příznaku je hodinový výpočet odvodí ze směny sám (viz
   [Noc a víkend odvozené ze směn](#noc-a-vikend-odvozene-ze-smen)).
4. U směny s pracovní pohotovostí vyplňte **Pohotovost (min)**.
5. Porovnejte docházku s absencemi, svátky a změnami vztahu.
6. Klikněte na **Schválit měsíc všem**. Dialog hromadného schválení předvyplní
   souhrn pro každý vztah. Pokud pracovnímu poměru nepokryje fond odpracovaná
   doba spolu se svátky v pracovní dny, dialog to ukáže a nabídne **Doplnit
   chybějící pracovní dny podle kalendáře**. Vztahy s nepřítomností schválíte tlačítkem
   **Schválit jednotlivě** přímo z dialogu. Jednotlivý měsíc schválíte také
   tlačítkem **Schválit měsíc** u řádku.
7. V dialogu **Potvrzení pracovního souhrnu JMHZ** zkontrolujte předvyplněné
   hodiny (fond, **Skutečně odpracováno**, neodpracované hodiny po druzích),
   výslovně odpovězte na otázky **Byly v měsíci neodpracované hodiny?**
   a **Byly v měsíci překážky v práci?** a klikněte na **Potvrdit a schválit
   měsíc**. U vztahu bez sledované docházky pomůže tlačítko **Odpracoval
   přesně předepsaný fond**.

**Jak poznáte, že je hotovo:** Měsíc je schválený a uzamčený. Zákonné
příplatky a odměna za pohotovost jsou zapsané jako schválené mzdové vstupy
a období je připravené pro [mzdový běh](80_Mzdove_behy.md).

> [!WARNING]
> Docházku schvalte dřív, než u mzdového běhu kliknete na **Spočítat mzdy**.
> Po uzamčení vstupů se příplatky do běhu nedostanou a schválení měsíce
> s příplatky skončí chybou. Pak v mzdovém běhu klikněte na **Zrušit běh**,
> měsíc schvalte a v běhu dejte **Začít znovu a spočítat**.

## 77.4 Krok za krokem: import docházky z CSV nebo XLSX

Pro doložení hodin bez vedení jednotlivých docházkových záznamů slouží také
ruční souhrn v [Rychlém měsíčním vstupu](79_Rychly_mesicni_vstup.md#79811-dolozene-odpracovane-hodiny).
Je to samostatný zdroj za celý měsíc vztahu: jeho hodiny se s docházkou ani
importem nesčítají. Nahrazení zdroje vyžaduje výslovné potvrzení a důvod,
schválený měsíc je nutné nejprve znovu otevřít. Schválení ručního souhrnu
hodin samo nepotvrzuje všechny údaje pro JMHZ.

Ruční souhrn a změnu zdroje lze použít jen u vztahu s hodinovým
odměňováním v daném měsíci. Přehled měsíce i historie ukazují hodiny
vybraného zdroje. Při použití souhrnu se původní denní zápisy zobrazují
odděleně jako nepoužité; denní mřížka je uzamčená. Chcete-li je znovu
použít, změňte výslovně zdroj podkladů. Importovaný měsíc měsíčně
odměňovaného vztahu se v přehledu i mřížce zobrazuje jako dosud.

Měsíc, který bere hodiny z importu docházky, přepnete na směny jedním krokem
tlačítkem **Počítat ze směn** u zaměstnance v `Mzdy → Docházka a směny` nebo
v panelu **Podklady hodin**. Po potvrzení v dialogu se zruší neschválené
mzdové vstupy z importu tohoto vztahu za měsíc (aby se nevyplatily vedle
hodin ze směn dvakrát) a zdroj hodin se přepne na docházku. Schválené nebo
zamčené vstupy z importu se nemění: akce se pak neprovede a hláška řekne,
kolik jich je. Vraťte je v Mzdových vstupech do konceptu (u zamčených nejdřív
znovu otevřete mzdový běh) a akci opakujte. Obecná změna zdroje v panelu
**Podklady hodin** → **Změnit vybraný zdroj hodin** vyžaduje, aby importované
vstupy byly zrušené předem. Doba, kterou nelze přesně vyjádřit celými minutami,
se zachová v přesném zápisu a nepřepočítává se na zaokrouhlené minuty.

U vztahu, který v měsíci nemá hodinovou odměnu ani sjednanou měsíční mzdu
(typicky brigádník na DPP s hotovými částkami z importu), nabízí docházka
u zaměstnance i panel **Podklady hodin** akci **Zapisovat směny, částky
ponechat**. Zdroj hodin se přepne na směny a importované mzdové vstupy
zůstanou beze změny: mzda se u takového vztahu z hodin nepočítá, takže se nic
nevyplatí dvakrát. Hodiny pro JMHZ a evidenci pracovní doby se pak berou ze
směn, příplatky a odměna za pohotovost se ze směn nepočítají, dokud jsou
importované vstupy aktivní. Akci stačí právo zápisu docházky. U vztahu
s hodinovou odměnou nebo měsíční mzdou ji server odmítne: hodinová mzda by
se ze směn spočítala znovu (použijte **Počítat ze směn**) a u měsíční mzdy
rozhoduje zdroj hodin o krácení, proto zůstává souhrn importu.

Má-li měsíc publikovaný rozpis směn, nabídne potvrzovací dialog obou akcí
volbu **Převzít rozpis směn jako odpracovaný čas**. Je předem zapnutá, když
měsíc nemá žádný časový záznam. Každá publikovaná směna měsíce se v téže
operaci zapíše jako časový záznam běžné práce se stejným začátkem, koncem,
přestávkou a časovým pásmem; záznam nese odkaz na svou směnu. Platí pro něj
stejné kontroly jako pro ručně zapsaný záznam (otevřený měsíc, překryv).

Původní souhrn importu zůstává v historii měsíce. Liší-li se součet hodin
ze směn od hodin původního souhrnu, ukáže docházka u zaměstnance i v dialogu
schválení nenápadné upozornění s oběma čísly. Schválení neblokuje.

1. V `Mzdy → Docházka a směny` klikněte na **Import**.
2. Vyberte soubor CSV nebo XLSX s povinnými sloupci (viz
   [§ 77.9.1](#7791-format-importu-dochazky)).
3. Klikněte na **Zkontrolovat**. Vznikne náhled, nic se nezapíše.
4. Projděte souhrn platných, chybných a duplicitních řádků. Chybné řádky jsou
   vypsané česky s číslem řádku; opravte je ve zdrojovém souboru.
5. Klikněte na **Importovat platné řádky**.

**Jak poznáte, že je hotovo:** Platné řádky jsou v měsíční mřížce. Opakované
nahrání téhož souboru nic nezdvojí.

## 77.5 Krok za krokem: sjednání příplatků u pracovního vztahu

Použijte, když se sazby u člověka liší od firemních, nebo když pracuje ve
svátek či ve ztíženém prostředí.

1. Otevřete kartu pracovního vztahu a část **Zásady zákonných příplatků
   (§ 114–118)**.
2. Klikněte na **Nová verze zásady** a vyplňte **Platí od**.
3. U **Přesčas (§ 114)** a **Svátek (§ 115)** zvolte režim: **Příplatek**,
   **Náhradní volno** nebo u přesčasu **Zahrnuto ve mzdě (§ 114 odst. 3)**.
4. Pracuje-li zaměstnanec ve ztíženém prostředí, vyplňte **Počet ztěžujících
   vlivů (§ 117)**.
5. V části **Sjednané sazby** zvolte u každého druhu **Způsob sjednání**
   (**Procentem** nebo **Pevnou částkou za hodinu**) a sazbu. Prázdné pole
   znamená zákonné minimum.
6. Vyplňte **Odkaz na sjednání (smlouva, kolektivní smlouva)** a uložte.

**Jak poznáte, že je hotovo:** Zásada je v části **Platná zásada** a měsíc
s prací ve svátek nebo ve ztíženém prostředí jde schválit.

Datum **Platí od** je povinné a vyplňujete ho výslovně podle sjednání.
Nepoužitou zásadu lze tlačítkem **Opravit zásadu** upravit včetně začátku
platnosti, i v seznamu starších verzí. Změna zachová návaznost sousedních
verzí. Pokud do dotčeného období zasahuje zmrazená nebo schválená revize
mzdového běhu tohoto vztahu, oprava se odmítne. Použitou zásadu měňte
novou verzí od dosud nezmrazeného období.

## 77.6 Krok za krokem: náhradní volno za přesčas

1. V `Mzdy → Absence a dovolená` zapište den čerpání jako absenci druhu
   **Náhradní volno za přesčas**.
2. V `Mzdy → Docházka a směny` klikněte na **Náhradní volno** a zapište,
   ke kterému dni přesčasu se volno vztahuje, včetně data poskytnutí.

**Jak poznáte, že je hotovo:** U vztahu nesvítí upozornění na jednostranný
zápis a přesčas je vyjmutý z vyrovnávacího období.

## 77.7 Krok za krokem: souhlas s přesčasem nad nařízený rozsah

1. V `Mzdy → Docházka a směny` klikněte u zaměstnance na **Souhlas
   s přesčasem**.
2. Vyplňte dobu platnosti a označení dokumentu dohody a uložte.

**Jak poznáte, že je hotovo:** Přesčas ve dnech krytých dohodou se posuzuje
jako dohodnutý a limity nařízeného přesčasu se na něj nevztahují.

## 77.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Měsíc s prací ve svátek nejde schválit | Není sjednaná zásada pro svátek; nejde určit, zda náleží náhradní volno, nebo příplatek | Sjednejte zásadu na kartě vztahu ([§ 77.5](#775-krok-za-krokem-sjednani-priplatku-u-pracovniho-vztahu)). |
| Hláška „Doplňte počet ztěžujících vlivů…“ | Chybí doložený počet ztěžujících vlivů | Vyplňte **Počet ztěžujících vlivů (§ 117)** na kartě vztahu, případně u konkrétního zápisu. |
| Schválení měsíce hlásí chybějící průměrný výdělek | Pro rozhodné čtvrtletí není schválený průměr (u § 117 se nepotřebuje) | Schvalte průměrný výdělek, pak měsíc schvalte znovu. |
| Příznakových minut je za den víc než odpracovaných | Noc, víkend nebo svátek byly zapsané místo odpracované doby | Zapište odpracovanou dobu (`regular` nebo `overtime`) a příznak k ní. |
| Příznak leží mimo noční dobu, mimo sobotu a neděli, nebo mimo svátek | Chybný čas nebo kategorie | Opravte zápis. |
| Týž přesčas je i v rychlém měsíčním vstupu | Jeden přesčas nejde vykázat dvakrát | Rozhodněte se pro jednu cestu a druhou zrušte. |
| „Vstupy období jsou uzamčené mzdovým během, takže do nich schválení docházky nemůže zapsat příplatky.“ | Mzdový běh už zamkl vstupy | Otevřete mzdové běhy, otevřete opravu nebo obnovte podklady a měsíc docházky schvalte znovu. |
| Upozornění na limit přesčasu | Překročeno 8 hodin v týdnu, 150 hodin v roce nebo průměr ve vyrovnávacím období | Upravte rozvrh, případně zapište **Souhlas s přesčasem**. Výplatu to nezastaví. |
| Upozornění u náhradního volna | Je zapsaná jen absence, nebo jen vazba na den přesčasu | Doplňte druhou stranu ([§ 77.6](#776-krok-za-krokem-nahradni-volno-za-prescas)). |
| Upozornění na rozsah DPP nebo DPČ | Překročeno 300 hodin DPP v roce, nebo průměr DPČ | Upravte rozsah práce nebo smluvní vztah. Výplatu to nezastaví. |
| Upozornění, že se DPP blíží limitu | Osoba má na DPP v roce 240 hodin a víc (80 % limitu) | Naplánujte zbývající hodiny, nebo včas upravte smluvní vztah. Výplatu to nezastaví. |
| Import odmítl soubor | Vzorec, makro, vložený soubor, externí propojení, nebo překročené limity | Uložte tabulku jen se statickými hodnotami a v limitech ([§ 77.9.2](#7792-bezpecnost-a-limity-xlsx)). |

Časté chyby: plánovaná směna považovaná za odpracovanou dobu, dvojí započtení
hodin při překryvu směny a absence, hodiny zapsané k jinému souběžnému
vztahu, oprava docházky bez přepočtu otevřeného běhu.

## 77.9 Podrobnosti a pravidla

Docházka a směny určují plánovaný a skutečně odpracovaný čas. Jsou podkladem
pro mzdu, příplatky, překážky a kontrolu fondu pracovní doby. Samotná
přítomnost záznamu nepotvrzuje jeho správnost. Kontrolujte fond, odpočinek,
překryv směn, práci ve svátek, přesčas a návaznost na úvazek. Záznamy
docházky jsou osobní údaje; exportujte je jen oprávněným osobám a
v nezbytném rozsahu.

Období může být rozpracované, úplné nebo blokované nesouladem. Po převzetí do
otevřeného běhu se změna projeví až novým výpočtem. Uzavřené období
neupravujte bez opravy běhu.

Nepřítomnosti patří do [absencí](76_Absence_a_dovolena.md). Schválené
absence předvyplní neodpracované hodiny v pracovním souhrnu JMHZ podle druhu.
Hodiny neplaceného volna, výkonu veřejné funkce, neplacené překážky na straně
zaměstnance a trvání vztahu po neplatném skončení se navrhnou v bloku
**Neplacené volno**. Čísla aplikace sama nedopočítává, potvrzujete je vy.
Hlášení přijímá hodiny nejvýše na tři desetinná místa, takže odpracovanou dobu
ze zápisů v minutách (například 32 h 25 min) navrhne zaokrouhlenou na tisícinu
hodiny (32,417 h) a u pole to uvede.

U dohody (DPP, DPČ) se týdenní úvazek do hlášení neuvádí: dialog místo pole
ukáže „Neuvádí se (do hlášení jde kód 99)", jak to chtějí pokyny MPSV
k atributu 10261. Dní v evidenčním stavu je u dohody vždy 0. Sjednaný úvazek
za měsíc se u dohody bez rozvrhu navrhne jako odpracovaná doba včetně
dovolené (předpokládaný rozsah podle pokynů k atributu 10260).

#### Historie po měsících

Když je seznam zúžený na jeden pracovní vztah (například odkazem **Docházka**
z karty zaměstnance), nabídne se vedle pole **Období** volba rozsahu
**Měsíc / Rok / Vše**.

- **Měsíc** je běžné zadávání: měsíční mřížka, import i hromadné schválení.
- **Rok** a **Vše** přepnou obrazovku na čtení. Každý řádek je jeden měsíc
  s fondem, plánem, skutečností, rozdílem a stavem, se stejnými čísly, jaká
  ukazuje rozsah **Měsíc**. Delší historie se listuje po stránkách.
- Rok volby **Rok** se řídí polem **Období**.
- Tlačítkem **Otevřít měsíc** se z řádku vrátíte do zadávání.

Zvolený rozsah je součástí adresy, takže obnovení stránky ani sdílený odkaz
ho nezahodí. Zrušením zúžení na jeden vztah se rozsah vrací na **Měsíc**.

### 77.9.1 Formát importu docházky

CSV a XLSX používají stejnou datovou větu, stejnou kontrolu a stejný
výsledek. Import slouží pro docházkové systémy i vlastní tabulku. Chybné
řádky se neopravují odhadem.

Povinné sloupce:

- `employment_code`: označení pracovního vztahu z karty zaměstnance,
- `starts_at` a `ends_at`: začátek a konec včetně časového posunu, například
  `2026-10-05T08:00:00+02:00`,
- `timezone`: IANA časové pásmo, pro českou docházku obvykle `Europe/Prague`,
- `category`: `regular`, `overtime`, `night`, `weekend`, `holiday` nebo
  `difficult_environment`,
- `external_id`: jedinečný identifikátor záznamu ve zdrojovém systému.

Odpracovanou dobu tvoří jen `regular` a `overtime`. Zbylé čtyři kategorie
jsou **příznaky nad týmiž hodinami**, ne hodiny navíc. Noční směnu proto
importujte jako řádek `regular` (nebo `overtime`) **a k němu** řádek `night`
na stejné hodiny. Za jeden den nesmí být příznakových minut víc než
odpracovaných.

Volitelně lze přidat `employment_id` pro přesné párování souběžných vztahů
a `break_minutes` pro délku přestávky v celých nezáporných minutách. Odkaz na
zdrojový dokument se nevyžaduje. Za správnost párovacího kódu a hodnot
odpovídá uživatel, který potvrdí náhled.

Stejné `external_id` u stejného vztahu se podruhé nezapíše. Opakované
odeslání totožného souboru vrátí původní výsledek importu, takže požadavek
lze po přerušení spojení bezpečně zopakovat. Párování probíhá jen uvnitř
zvolené firmy a podle osobního čísla (kódu vztahu), nezávisle na tom, kolik
zaměstnanců je načteno v přehledu. Postup je stejný pro deset i pět set
zaměstnanců.

### 77.9.2 Bezpečnost a limity XLSX

Soubor smí mít nejvýše 5 MB, 10 000 datových řádků a 32 sloupců. U XLSX se
zpracuje jen první list. Aplikace přijímá jen statické hodnoty: vzorec,
makro, vložený soubor, externí propojení nebo neobvykle rozbalený archiv
odmítne ještě před náhledem a nic nezapíše. Kontrola omezuje i počet částí
archivu a jeho rozbalenou velikost, takže komprimovaný soubor nemůže
nekontrolovaně spotřebovat paměť serveru.

### 77.9.3 Limity práce přesčas

Aplikace u každého vztahu hlídá limity podle § 93 zákoníku práce a stav
ukazuje u zaměstnance:

- **8 hodin v jednotlivých týdnech** a **150 hodin v kalendářním roce**:
  meze přesčasu, který smí zaměstnavatel nařídit (§ 93 odst. 2). Týden se
  posuzuje jako pondělí až neděle bez ohledu na hranici měsíce.
- **Průměr 8 hodin týdně ve vyrovnávacím období** nejvýše 26 týdnů po sobě
  jdoucích (§ 93 odst. 4). Poměřuje se celkový přesčas, i dohodnutý. Na
  začátku pracovního poměru je okno kratší a strop s ním klesá.

Podkladem je evidence odpracovaného přesčasu v docházce, ne vyplacená
částka. Hlásí se i blížící se vyčerpání ročního limitu. Nad nařízený rozsah
lze přesčas požadovat jen na základě dohody se zaměstnancem (§ 93 odst. 3).
Bez evidované dohody se proti limitům poměřuje všechen přesčas.

Překročení limitu je vada na straně zaměstnavatele, ne chyba výpočtu.
Odpracovaný přesčas se podle § 114 platí, i když byl nařízen nad zákonný
rozsah. Nález se proto eviduje jako upozornění u revize mzdového běhu
a schválení ani výplatu nezastaví.

### 77.9.4 Náhradní volno za přesčas

Náhradní volno se eviduje na dvou místech, protože každý zápis odpovídá na
jinou otázku:

- **Absence druhu Náhradní volno za přesčas** je záznam o **dni čerpání**,
  vstup do docházky a mzdy. Za dobu čerpání mzda nepřísluší (§ 114 odst. 3),
  protože přesčas se už proplatil a volnem se nahrazuje jen příplatek.
- **Tlačítko Náhradní volno** v docházce zapisuje, **ke kterému dni
  přesčasu** se volno vztahuje. Podle toho se přesčas vyjímá
  z vyrovnávacího období (§ 93 odst. 5). Z limitů nařízeného přesčasu podle
  odst. 2 se neodečítá, tam zákon výjimku nemá.

Odvodit jedno z druhého nejde: absence den přesčasu nenese a jeden den
čerpání může vyrovnávat přesčas z několika dnů. Jednostranný zápis by se
projevil chybějícím vynětím z vyrovnávacího období, nebo neodpracovaným dnem
bez důvodu, proto na něj aplikace upozorní. Zápis bez data poskytnutí volna
se do měsíce nezařazuje a hlásí se zvlášť.

### 77.9.5 Zákonné příplatky ke mzdě (§ 114 až § 118)

Příplatky se berou z docházky, kde se odpracovaná doba a její příznaky
evidují po dnech. Druhou cestou je [rychlý měsíční vstup](79_Rychly_mesicni_vstup.md),
kde po kliknutí na **Zadat i příplatky** zadáte hodiny za měsíc a částku
dopočte aplikace. Tentýž příplatek za měsíc nejde vykázat z obou zdrojů
najednou, aplikace to zastaví.

> [!TIP]
> Firma, která docházku nevede, dostane příplatky podle § 115 až § 118 jen
> z rychlého měsíčního vstupu. Pro denní evidenci příznaků a automatický
> výpočet z odpracované doby je potřeba docházka.

| Ustanovení | Příplatek | Mzdová složka |
|---|---|---|
| § 114 | Příplatek za práci přesčas | `PRIPLATEK_PRESCAS` |
| § 115 | Příplatek za práci ve svátek | `PRIPLATEK_SVATEK` |
| § 116 | Příplatek za noční práci | `PRIPLATEK_NOCNI` |
| § 117 | Příplatek za práci ve ztíženém pracovním prostředí | `PRIPLATEK_ZTIZENE_PROSTREDI` |
| § 118 | Příplatek za práci v sobotu a v neděli | `PRIPLATEK_VIKEND` |

U měsíční mzdy schválení docházky s přesčasem založí vedle příplatku i
**dosaženou mzdu za přesčas** (§ 114 odst. 1) složkou `MZDA_HODINOVA`:
sjednaná měsíční mzda z podmínek vztahu dělená fondem měsíce včetně svátků,
krát hodiny přesčasu, zaokrouhleno na celé koruny. Náleží i tehdy, když se
místo příplatku poskytuje náhradní volno; nenáleží, je-li mzda sjednána
s přihlédnutím k práci přesčas (§ 114 odst. 3). Chybí-li v podmínkách vztahu
sjednaná měsíční mzda, částka se neodhaduje a dosaženou mzdu doplňte ručně
ve mzdových vstupech.

#### Odměna za pracovní pohotovost (§ 140)

Minuty pohotovosti se zadávají u směny v poli **Pohotovost (min)**. Při
schválení měsíce aplikace sečte pohotovost ze zveřejněných směn, jejichž
začátek padá do měsíce, a založí schválený mzdový vstup složky
`ODMENA_POHOTOVOST`: hodiny pohotovosti × průměrný hodinový výdělek × sazba.
Sazba je zákonné minimum 10 % průměrného výdělku, nebo vyšší sazba sjednaná
na kartě vztahu (pole **Odměna za pracovní pohotovost (% průměrného
výdělku)**, nejvýše 500 %). Nižší sazbu aplikace neuloží.

Bez schváleného průměrného výdělku pro čtvrtletí se měsíc s pohotovostí
neschválí; hláška řekne, který měsíc a kolik minut. V JMHZ se odměna
vykazuje v bloku *Odměny za pracovní pohotovost* (10343), mimo zúčtovanou
mzdu. Opakované schválení beze změny nic nepřidá, změna směn zapíše jen
rozdíl.

#### Za jednu hodinu může náležet víc příplatků

Příznaky (noc, víkend, svátek, ztížené prostředí) se do odpracované doby
nesčítají a příplatky se **sčítají vedle sebe a nevylučují se**:

- přesčas odpracovaný v noci o víkendu nese **tři** příplatky současně,
- práce ve svátek, který padne na sobotu, nese **dva** (§ 115 i § 118).

#### Noc a víkend odvozené ze směn

U hodinově odměňovaného vztahu se doba noční práce a práce v sobotu a neděli
odvodí ze zapsané běžné práce a přesčasu automaticky, pokud k ní v úseku
nezapíšete příznak noc nebo víkend:

- noc je doba v nočním okně legislativní sady (22 až 6 hodin),
- víkend je sobota a neděle v místním čase směny,
- přestávka, u které není známo, kdy proběhla, se klade mimo příplatkovou
  dobu. Příplatková doba je tedy kratší z hrubé doby v okně a čisté doby
  záznamu. Je to výklad ve prospěch zaměstnance: zákon stanoví minimum
  a vyšší plnění je přípustné.

Příklad: směna od soboty 18:00 do neděle 2:30 s přestávkou 30 minut má noc
4:30 h a víkend 8:00 h. Bez přestávky by víkend byl 8:30 h.

Zapíšete-li k odpracované době příznak noc nebo víkend, platí zapsaná doba
(ruční přebití) a odvození se pro daný druh nepoužije. Práce ve svátek se
neodvozuje, zapisuje se dál příznakem. V náhledu hodinové mzdy je u nároku
uvedeno, že doba byla odvozena automaticky ze směn.

#### Z čeho se počítají

| Ustanovení | Zákonná sazba | Základ |
|---|---|---|
| § 114 přesčas | nejméně 25 % | průměrný výdělek |
| § 115 svátek | nejméně 100 % | průměrný výdělek |
| § 116 noční práce | nejméně 10 % | průměrný výdělek |
| § 117 ztížené prostředí | nejméně 10 % za každý ztěžující vliv | **základní sazba minimální mzdy** |
| § 118 sobota a neděle | nejméně 10 % | průměrný výdělek |

Za noční práci se považuje doba mezi **22. a 6. hodinou**. Sazba § 117 se
nepřepočítává na kratší úvazek: příplatek je kompenzace vlivu prostředí, ne
odměna za odpracovaný čas.

Sazby, základy i noční okno bere aplikace z legislativní sady účinné pro
období. Sjednat lze **vyšší** sazbu vždy; **nižší** jen u noční práce (§ 116)
a u práce v sobotu a neděli (§ 118), protože jen tato ustanovení dovolují
sjednat jinou minimální výši. U § 114, § 115 a § 117 je zákonné „nejméně“
tvrdá podlaha a nižší sjednanou sazbu aplikace neuloží.

#### Procentem, nebo pevnou částkou za hodinu

Způsob sjednání volíte u každého druhu zvlášť:

- **Procentem** z průměrného výdělku (u § 117 ze základní sazby minimální
  mzdy),
- **Pevnou částkou za hodinu**, například „přesčas 75 Kč/h, víkend 42 Kč/h“
  (nejvýše 1 000 Kč za hodinu).

Pevná částka se hodí tam, kde ji máte ve mzdovém výměru nebo v kolektivní
smlouvě: zaměstnanec si ji přečte rovnou a nemusí čekat na čtvrtletní
průměr. Příplatek se pak počítá jako sjednaná částka krát odpracované hodiny
daného druhu. Obojí u téhož druhu naráz sjednat nejde; druhy navzájem
kombinovat můžete (přesčas procentem, víkend pevnou částkou).

Zákonné minimum platí i u pevné částky. Je-li sjednaná částka nižší,
aplikace u přesčasu (§ 114), svátku (§ 115) a ztíženého prostředí (§ 117)
dopočítá a vyplatí zákonné minimum. U noční práce (§ 116) a víkendu (§ 118)
ctí sjednanou částku, protože tam zákon nižší sjednání dovoluje. Rozdíl mezi
sjednanou a vyplacenou hodinovou částkou je vidět na výplatní pásce.

Pevná částka se nedá posoudit dopředu: zákonné minimum je podíl
z průměrného výdělku konkrétního člověka, takže táž částka je u jednoho nad
minimem a u druhého pod ním. Formulář proto u pevné částky minimum
nezobrazuje a vyhodnotí ho až výpočet mzdy.

#### Kde se sazba nastavuje: firma, nebo vztah

Platí pravidlo „konkrétnější vyhrává“:

| Úroveň | Kde | Pro koho platí |
|---|---|---|
| Firemní výchozí | `Mzdy → Nastavení mezd`, záložka **Politiky a připravenost**, část **Výchozí sazby příplatků (§ 114–118)** | Pro všechny vztahy bez vlastního sjednání |
| Sjednání na vztahu | karta pracovního vztahu, **Zásady zákonných příplatků (§ 114–118)** | Jen pro ten vztah; **přebíjí** firemní výchozí |

Platí-li ve firmě jedna kolektivní smlouva, zadejte sazby jednou na úrovni
firmy. Kartu vztahu použijte jen tam, kde se sjednání liší, typicky
u vedoucích (§ 114 odst. 3) nebo u jednotlivě dohodnutých podmínek. Zásada
svátku (§ 115) a počet ztěžujících vlivů (§ 117) se sjednávají na kartě
vztahu; počet vlivů jde přepsat i u jednotlivého zápisu docházky.

Zásady se **verzují podle platnosti**: nová kolektivní smlouva je nová verze
od data účinnosti, stará zůstává v historii a mzdy spočítané podle ní se
nemění. Opravit jde jen verzi, která právě platí a nemá konec platnosti.
Vyplněním **Ukončit platnost k** se zásada uzavře a od dalšího dne platí
zákonný výchozí stav.

Není-li sazba nikde, platí zákonné minimum ze sady pravidel. Prázdné pole
tedy neznamená „bez příplatku“, ale „podle zákona“. Na výplatní pásce a ve
stopě výpočtu je vidět, odkud sazba přišla: ze sjednání na vztahu, z firemní
zásady, nebo ze zákona.

#### Náhradní volno místo příplatku

Zákon ho zná jen u přesčasu (§ 114) a u svátku (§ 115).

- **Přesčas:** náhradní volno zapsané tlačítkem **Náhradní volno** se
  odečítá přesně podle dne přesčasu. Dokud lhůta běží, příplatek se
  nevyplácí. Není-li volno poskytnuto v dohodnuté době, nejpozději do konce
  třetího kalendářního měsíce po měsíci přesčasu, nárok na příplatek podle
  § 114 odst. 2 obživne a aplikace ho dopočítá. Totéž platí u volna
  zapsaného bez data poskytnutí. Je-li mzda sjednána s přihlédnutím k práci
  přesčas (§ 114 odst. 3), nepřísluší příplatek ani náhradní volno.
- **Svátek:** zákon má ve výchozím stavu náhradní volno; příplatek 100 %
  jen tehdy, je-li dohodnut (§ 115 odst. 1). Záznam o náhradním volnu nese
  den čerpání, ne den svátku, takže aplikace neví, za který svátek bylo
  volno poskytnuto. Bez sjednané zásady se proto výpočet bezpečně zastaví
  a nevyplatí nic: tiše vyplacený příplatek bez dohody by byl výdaj bez
  právního titulu. Na rozdíl od přesčasu nárok po marném uplynutí lhůty
  neobživne. Mzda sjednaná s přihlédnutím k práci ve svátek neexistuje,
  § 114 odst. 3 se týká jen přesčasu.

#### Kdy příplatky vzniknou a co je zastaví

Příplatky se do mzdy promítnou ve chvíli, kdy schválíte měsíc docházky, ve
stejné transakci. Nejde o samostatné tlačítko, na které by šlo zapomenout.
Chybějící podklad proto shodí i schválení měsíce a aplikace uvede konkrétní
důvod (přehled je v [§ 77.8](#778-kdyz-neco-nejde)). Měsíc s prací ve svátek
bez sjednané zásady nebo s prací ve ztíženém prostředí bez počtu vlivů není
schválitelná evidence, nedá se z ní spočítat mzda. Počet ztěžujících vlivů
plyne z nařízení vlády a z konkrétního pracoviště, proto ho aplikace
neodhaduje.

Znovuotevření měsíce zapsané příplatky neruší. Opětovné schválení dopočítá
jen rozdíl; snížení vznikne jako opravný záporný vstup, ne přepsáním
původního. Opakované schválení beze změny nezapíše nic.

### 77.9.6 Rozsah dohod o provedení práce a o pracovní činnosti

Kontrola mzdového běhu poměřuje odpracované hodiny dohod se zákonným
rozsahem:

- **DPP (§ 75 zákoníku práce):** nejvýš **300 hodin v kalendářním roce**
  u téhož zaměstnavatele, sčítají se všechny DPP téže osoby.
- **DPČ (§ 76 odst. 2):** v průměru nejvýš **polovina stanovené týdenní
  pracovní doby**, tedy 20 hodin týdně. Průměr se posuzuje za dobu dohody,
  nejdéle za 52 týdnů, u každé DPČ zvlášť. Aplikace bere posledních dvanáct
  kalendářních měsíců včetně měsíce mzdy, u dohody uzavřené později od
  jejího začátku, a limit krátí poměrně podle počtu dnů.

Podkladem jsou odpracované hodiny ze schválené docházky, u firmy po
přechodu v průběhu roku i hodiny převzaté z předchozího programu.
Je-li vybraný ruční souhrn, použije se jeho aktuální schválená revize
včetně zlomků hodin; potvrzení JMHZ pro samotný limit není potřeba.
Staré nahrazené denní zápisy ani jejich výkazový souhrn se nepřičítají.
DPP i DPČ se posuzují bez zaokrouhlení přesné doby na minuty: také
`300,001` hodin DPP už překračuje roční limit. Schválená nula je známý
součet; neúplný nebo znovu otevřený ruční podklad vyvolá upozornění,
že rozsah nelze ověřit, místo falešné nuly.
Překročení se u revize běhu objeví jako upozornění se jménem, obdobím
a průměrem a s odkazem na podmínky vztahu. Výpočet ani schválení nezastaví:
odpracovanou práci je potřeba zaplatit, jen je třeba upravit rozsah práce
nebo smluvní vztah.

U DPP hlásí aplikace i to, že se osoba k limitu **blíží**. Od **240 hodin**
(80 % limitu) přidá kontrola mzdového běhu upozornění se jménem, počtem
odpracovaných a zbývajících hodin, dokud osoba 300 hodin nepřekročí; přesně
300 hodin je ještě v limitu. Upozornění je jen informace, výpočet nezastaví
a v kontrole připravenosti běhu nic neblokuje. Totéž hlásí náhled importu
docházky: k dosavadním hodinám roku do konce předchozího měsíce přičte
odpracované hodiny z importovaného souboru a u osob přiřazených na DPP vypíše
upozornění při dosažení prahu nebo překročení limitu. Přehled po osobách je
v seznamu zaměstnanců ([§ 86](86_Zamestnanci.md)), včetně zobrazení
**DPP u limitu 300 h**.

### 77.9.7 Pracovní doba mladistvých

Mladistvý je zaměstnanec mladší 18 let (§ 350 odst. 2 zákoníku práce); věk se
počítá z data narození osoby ke každému dni. Den 18. narozenin a dny po něm se
už nekontrolují. Bez data narození se kontrola neprovede.

Aplikace kontroluje skutečně odpracované časové záznamy (běžná práce
a přesčas), ne plán směn. Doba všech pracovněprávních vztahů osoby u firmy se
sčítá. Den a týden záznamu určuje jeho místní začátek, týden začíná pondělím.

| Kontrola | Pravidlo |
|---|---|
| Délka směny (§ 79a) | v jednotlivém dni nejvýše 8 h (8:00 projde, 8:01 ne) |
| Týdenní doba (§ 79a) | nejvýše 40 h za týden |
| Odpočinek mezi směnami (§ 90 odst. 1) | nejméně 12 h mezi koncem poslední práce dne a začátkem práce dalšího dne |
| Noční práce (§ 245 odst. 1) | žádná práce v noční době 22:00 až 6:00 |
| Přestávka (§ 88 odst. 1) | záznam bez přestávky delší než 4,5 h |

Výjimku pro mladistvého staršího 16 let (noční práce nejvýše 1 h, potřebná
pro přípravu na povolání a navazující na denní práci) aplikace sama nepozná;
nález ji jen připomene. Přestávka se posuzuje jen u záznamu, který žádnou
přestávku nemá, protože její poloha uvnitř záznamu se neeviduje.

Nálezy se v `Mzdy → Docházka a směny` zobrazí u zaměstnance v bloku
**Pracovní doba mladistvého** s dnem a konkrétními hodnotami, v dialogu
schválení měsíce jako upozornění a v kontrole mzdového běhu jako varování.
Mzdu neovlivňují a nic neblokují: odpracovanou dobu je potřeba zaplatit,
jen je třeba upravit rozvrh práce.

## 77.10 Související kapitoly

- [Absence a dovolená](76_Absence_a_dovolena.md): nepřítomnosti a náhradní
  volno.
- [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md): jednorázové odměny
  a příplatky bez docházky.
- [Mzdové běhy](80_Mzdove_behy.md): výpočet mezd z docházky.
- [Zaměstnanci](86_Zamestnanci.md): pracovní vztah, kalendář a zásady
  příplatků.
- [Nastavení mezd](90_Nastaveni_mezd.md): firemní sazby příplatků.

Placené náhradní volno za práci ve svátek je v potvrzení pracovního
souhrnu JMHZ samostatné pole, pokud je tato nepřítomnost v měsíci
skutečně evidovaná a podklad ji podporuje. Zkontrolujte předvyplněné
hodiny; jsou již součástí neodpracovaných i placených hodin a do
úhrnů se nepřičítají podruhé. Nezaměňujte je s neplaceným náhradním
volnem za přesčas. Historické souhrny bez tohoto údaje se zpětně
nedoplňují.
