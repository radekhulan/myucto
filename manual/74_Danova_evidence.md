# 74. Daňová evidence

> Návod pro OSVČ, které vedou daňovou evidenci podle § 7b zákona o daních z příjmů: jak číst peněžní deník,
> zařadit nezařazené pohyby, hlídat pohledávky a závazky, dokončit roční uzávěrku a případně přejít na podvojné účetnictví.

## 74.1 Kdy to potřebujete

- Chcete vidět příjmy a výdaje za rok a zjistit, z čeho se skládá daňový základ.
- V peněžním deníku vidíte červeně podbarvený řádek nebo upozornění na **Nezařazeno** a nevíte, co s ním.
- Potřebujete přehled, kdo vám dluží a komu dlužíte, a jak jsou doklady po splatnosti.
- Blíží se podání přiznání k dani z příjmů a je třeba dokončit roční uzávěrku daňové evidence.
- Zvažujete přechod z daňové evidence na podvojné účetnictví nebo zpět.
- Chcete předat peněžní deník účetní jako PDF nebo XLSX.

Modul **Daňová evidence** je určen firmám s **režimem účetnictví „Daňová evidence"** (OSVČ vedoucí evidenci podle § 7b zákona o
daních z příjmů, kasová báze, tedy podle data úhrady, ne podle vystavení dokladu). Je to **alternativa k podvojnému účetnictví**:
jde o dva vzájemně se vylučující režimy jedné firmy (dodavatele), mezi kterými se přepíná v nastavení dodavatele (viz
[Multi_supplier](95_Multi_supplier.md)). Firma v režimu „Podvojné účetnictví" místo této sekce vidí plnohodnotné **Účetnictví**
(deník, hlavní kniha, rozvaha, výsledovka) popsané v kapitole [Účetní deník](52_Ucetni_denik.md) a následujících.

<!-- cols: 24 46 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| průběžně / měsíčně | Zkontrolovat nezařazené pohyby a zařadit je | `Daňová evidence → Peněžní deník`, [§ 74.3](#743-krok-za-krokem-zkontrolovat-penezni-denik) a [§ 74.4](#744-krok-za-krokem-zaradit-nezarazeny-pohyb) |
| měsíčně | Zkontrolovat pohledávky a závazky po splatnosti | `Daňová evidence → Pohledávky a závazky`, [§ 74.5](#745-krok-za-krokem-sledovat-pohledavky-a-zavazky) |
| při evidenci jiných dluhů než faktur | Vést ostatní pohledávky a závazky | `Daňová evidence → Ostatní pohledávky a závazky` |
| před podáním DPFO | Dokončit roční uzávěrku | `Daně → Daň z příjmů`, [§ 74.6](#746-krok-za-krokem-dokoncit-rocni-uzaverku) |
| při změně režimu | Připravit podklady pro přechod | `Daňová evidence → Přechod DE → účetnictví`, [§ 74.7](#747-krok-za-krokem-prejit-na-podvojne-ucetnictvi) |

> [!TIP]
> Daňová evidence **nezavádí žádnou vlastní účetní knihu ani zápisy**. Peněžní deník i přehled pohledávek a závazků jsou sestavy
> postavené nad daty, která už v systému existují: vydanými a přijatými fakturami, pokladními doklady a spárovanými bankovními
> pohyby. Částky, doklady ani protistrany v nich needitujete, to zajistíte na zdrojovém dokladu. Jedinou výjimkou je **ruční zařazení**
> u bankovních a pokladních pohybů bez navázaného dokladu.

Peněžní deník je zdrojem daně z příjmů, nikoli DPH. DPHDP3, KH, SH a Kniha DPH používají společnou řádkovou evidenci z faktur
a daňových pokladních dokladů podle DUZP či pravidel nároku na odpočet. Přepnutí mezi daňovou evidencí a účetnictvím proto samo
nesmí změnit výsledek DPH. Viz [Výkazy DPH](41_Vykazy_DPH.md).

## 74.2 Než začnete

- Firma musí mít v nastavení dodavatele režim **Daňová evidence** (viz [§ 74.9.1](#7491-zapnuti-rezimu-a-dostupnost-v-menu)). Podle režimu se
  v menu zobrazí buď sekce **Účetnictví**, nebo **Daňová evidence**, nikdy obě. Přímý vstup na adresu stránek v jiném režimu vás
  přesměruje na úvodní stránku.
- Faktury (vydané i přijaté), pokladní doklady a bankovní výpisy musí být v aplikaci zadané a spárované. Peněžní deník z nich vychází.
- **Pokladna** funguje v obou režimech. V daňové evidenci pokladní doklad nevytváří žádný zápis do účetního deníku (ten v tomto
  režimu neexistuje), pohyb se rovnou promítne do peněžního deníku. Viz [Pokladna](32_Pokladna.md).
- Pro plátce DPH s kráceným odpočtem musí být pro rok dostupný roční koeficient.

## 74.3 Krok za krokem: zkontrolovat peněžní deník

1. Otevřete `Daňová evidence → Peněžní deník`.
2. Vyberte **Rok** (aktuální a 5 předchozích) nebo vyplňte **Od** a **Do** (vlastní rozsah má přednost před rokem). Sestava se po každé změně přenačte.
3. Zkontrolujte panel **Souhrn** a řádek **Daňový základ (příjem - výdaj)**. To je číslo, které se má shodovat s podklady pro přiznání k dani z příjmů.
4. Projděte červené upozornění nad souhrnem (viz [§ 74.4](#744-krok-za-krokem-zaradit-nezarazeny-pohyb)) a řádky s klasifikací **Nezařazeno**.
5. Případně rozbalte panel **Kontrola vůči přiznanému příjmu** a porovnejte daňový příjem s ročním příjmem z faktur (viz [§ 74.9.7](#7497-kontrola-vuci-priznanemu-prijmu)).
6. Pro předání účetní klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** panel **Souhrn** nehlásí **Nezařazeno** a nad ním není červené upozornění. Export se uloží jako
`penezni-denik-{rok}.pdf` nebo `.xlsx`.

Přes **Výběr sloupců** a hustotu řádků přizpůsobíte tabulku (stejné ovládací prvky jako u ostatních tabulek, nastavení se ukládá
pro uživatele). Sloupce **Zdroj**, **Základ** a **DPH** jsou ve výchozím zobrazení skryté, zapněte je pro kontrolu, jak se DPH
rozpočítalo u pohybů s vazbou na plátcovskou fakturu.

## 74.4 Krok za krokem: zařadit nezařazený pohyb

1. V peněžním deníku najděte řádek se štítkem **Nezařazeno** (červeně podbarvený).
2. Pokud pohyb patří k faktuře nebo pokladnímu dokladu, opravte zdrojový doklad: spárujte bankovní pohyb s fakturou, nebo upravte
   účel pokladního dokladu či příznaky uznatelnosti a osvobození na faktuře.
3. Pokud k pohybu doklad neexistuje, v řádku zvolte klasifikaci ve výběru **Zařadit...** (například soukromý vklad či výběr).
4. Zrušení ručního zařazení provedete volbou **Zrušit ruční zařazení**.

**Jak poznáte, že je hotovo:** hláška **Klasifikace uložena.** (po zrušení **Ruční zařazení zrušeno.**), řádek už není červený a
v souhrnu klesne položka **Nezařazeno**.

> [!WARNING]
> Blokující upozornění nezmizí samo. Dokud zůstane nezařazený příchozí bankovní pohyb, aplikace ho bude v deníku vypisovat jako
> riziko podhodnocení základu daně. Ruční klasifikace celého pohybu bez navázaného dokladu nezná řádkový rozpad DPH. Pokud vazba na
> fakturu existuje, opravte přednostně zdrojový doklad. Ruční zařazení není náhradou správného režimu DPH.

## 74.5 Krok za krokem: sledovat pohledávky a závazky

1. Otevřete `Daňová evidence → Pohledávky a závazky`.
2. Případně zúžte přehled filtrem **Měna** (výchozí je „Všechny").
3. Projděte tabulky **Pohledávky (vydané faktury)** a **Závazky (přijaté faktury)** po pásmech stáří po splatnosti.
4. Pro předání klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** víte, které doklady jsou po splatnosti, a znáte ukazatele DSO, DPO a platební morálky za posledních 12 měsíců.

Jiné neuhrazené případy než faktury vedete v `Daňová evidence → Ostatní pohledávky a závazky`. Potvrzení položky v daňové evidenci
nevytvoří podvojný účetní zápis ani samo o sobě nezakládá daňový příjem či výdaj. Přiřazená bankovní nebo pokladní úhrada sníží
otevřený zůstatek, daňové zařazení platby se dál řídí pravidly peněžního deníku.

## 74.6 Krok za krokem: dokončit roční uzávěrku

1. Otevřete `Daně → Daň z příjmů` před finalizací přiznání DPFO. Roční uzávěrka daňové evidence podle § 7b se dokončuje tady.
2. Projděte kontrolní seznam: peněžní deník, inventura majetku a zásob, pohledávky, závazky, vysoké nákupy, přechody režimu a cizí měny. Zaškrtnutí potvrzuje, že kontrola proběhla.
3. Zadejte počáteční a skutečné koncové stavy majetku, hotovosti, banky, zásob, pohledávek, ostatních aktiv, dluhů, rezerv a odpisů.
4. Přidejte případné nepeněžní úpravy (zápočet, barter, naturální příjem, prominutí dluhu, soukromá spotřeba, manko, škoda, inventurní rozdíl, jiná úprava § 23) a zvolte směr **zvýšení**, **snížení** nebo **neutrální**.
5. Každý označený vysoký nákup porovnejte s kartou majetku a daňovými odpisy.
6. Uzávěrku dokončete.

**Jak poznáte, že je hotovo:** vznikne neměnný snapshot s kontrolním hashem. Jeho stavy se přenesou do `VetaU` Přílohy 1 DPFO a nepeněžní zvýšení či snížení do příslušných řádků.

Potřebujete-li podklady opravit, vraťte uzávěrku do rozpracovaného stavu, proveďte opravu a dokončete ji znovu. Podrobnosti jsou
v [§ 74.9.9](#7499-rocni-uzaverka-danove-evidence).

## 74.7 Krok za krokem: přejít na podvojné účetnictví

Zákon (příloha č. 3 zákona o daních z příjmů) vyžaduje, aby OSVČ k datu přechodu z daňové evidence na účetnictví **jednorázově
upravila základ daně** o rozdíl mezi kasovou a akruální bází. Úprava patří do přiznání za zdaňovací období, ve kterém bylo zahájeno
vedení účetnictví, **ne** do samotného účetnictví.

1. Otevřete `Daňová evidence → Přechod DE → účetnictví` (stránka **Přechodový můstek**).
2. Zvolte směr (**Daňová evidence → účetnictví**, nebo **Účetnictví → daňová evidence**) a datum **K datu**. Nastavte poslední den, kdy firma ještě vedla původní režim, obvykle 31. 12. předchozího roku.
3. Zkontrolujte **Souhrn úpravy základu daně** a seznamy neuhrazených pohledávek a závazků.
4. Doplňte ručně hodnotu cenin a případně zásob, pokud nemáte zapnutý sklad.
5. Předejte sestavu účetní nebo daňovému poradci, který úpravu zanese do přiznání ručně.
6. Pak v nastavení dodavatele zvolte **Podvojné účetnictví** a pokračujte v průvodci aktivací (viz [§ 74.9.12](#74912-pruvodce-aktivaci-podvojneho-ucetnictvi)).

**Jak poznáte, že je hotovo:** máte uložený nebo vytištěný podklad a firma má po úspěšném průvodci zapnuté podvojné účetnictví s protokolem.

> [!WARNING]
> Sestava je **jen podklad**. Zanesení úpravy do daňového přiznání (a její případné zaokrouhlení či rozpad na řádky přiznání) dělá
> účetní ručně, aplikace úpravu nikam sama nezaúčtuje ani ji nepropisuje do přiznání k dani z příjmů (viz [Daň z příjmů](43_Dan_z_prijmu.md)).
> Otevírací rozvaha řeší účetní počáteční stavy a nenahrazuje jednorázovou úpravu základu daně podle přílohy č. 3 ZDP.

## 74.8 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Červené upozornění nad souhrnem o nezařazených příchozích pohybech | Bankovní příchozí platba nemá vazbu na fakturu ani přijatou fakturu | Zařaďte pohyb podle [§ 74.4](#744-krok-za-krokem-zaradit-nezarazeny-pohyb). Aplikace ho záměrně nezařadí tiše jako nedaňový |
| Upozornění na počet bankovních úhrad mimo spárované výpisy | Část historie účtu není v žádném spárovaném výpisu (typicky po změně čísla účtu v nastavení) | Opravte číslo účtu v nastavení a spárujte výpisy, jinak by část historie v základu daně chyběla |
| Kontrola vůči přiznanému příjmu ukazuje oranžový **Rozdíl** | Kasová báze deníku a fakturační báze ročního příjmu se z principu liší | Prohlédněte vysvětlující položky. Panel je informativní, nic nevynucuje |
| Při pohybu v cizí měně se nevypočte částka | Chybí kurz uložený na dokladu | Doplňte kurz na dokladu, nenahrazujte ho odhadem 1:1 |
| Chybí roční koeficient u kráceného odpočtu | Pro rok není koeficient | Nelze bezpečně určit neodpočitatelnou DPH ani dokončit roční uzávěrku, nejdřív koeficient nastavte |
| Roční uzávěrku nelze dokončit | Blokující chyba deníku, nepodporovaný případ, chybějící konečný koeficient nebo neposouzený vysoký nákup | Opravte podklady, vraťte uzávěrku do rozpracovaného stavu a dokončete ji znovu |
| Změna zdroje DPH/KH hlásí odmítnutí v zamčeném období | Označení DPH/KH jako skutečně odeslaného může posunout zámek daňových období | Změnu proveďte po odemčení, potvrzení z portálu vždy archivujte samostatně |
| V bance se objevují stále stejné nezařazené položky | Bankovní poplatky, které heuristika nerozpoznala | Doplňte popis platby v bance, nebo je zařaďte ručně |
| Stránku daňové evidence nevidíte | Firma vede podvojné účetnictví | Přepněte režim v nastavení dodavatele |

> [!TIP]
> Pokud sestava dlouhodobě hlásí nenulové **Nezařazeno**, projděte nejdřív odchozí i příchozí bankovní pohyby bez vazby na doklad. U
> opakujících se položek (například bankovní poplatky, které heuristika nerozpoznala) je často rychlejší doplnit popis platby přímo
> v bance, než pohyb řešit ručně každý měsíc znovu.

## 74.9 Podrobnosti a pravidla

### 74.9.1 Zapnutí režimu a dostupnost v menu

Režim účetnictví je nastavení **na dodavatele** (firmu). Přepínáte ho v editaci dodavatele mezi „Daňová evidence" a „Podvojné
účetnictví" (viz [Multi_supplier](95_Multi_supplier.md)). Podle aktuálně zvoleného režimu se v hlavním menu zobrazí buď sekce
**Účetnictví**, nebo sekce **Daňová evidence**, nikdy obě najednou.

Sekce **Daňová evidence** v menu obsahuje položky **Peněžní deník** (chronologický přehled příjmů a výdajů s daňovou klasifikací),
**Pohledávky a závazky** (věkové rozložení nezaplacených vydaných a přijatých faktur), **Ostatní pohledávky a závazky**
a **Přechod DE → účetnictví**.

Přímý vstup na adresu těchto stránek je stejně jako u zobrazení v menu vázaný na aktuální režim dodavatele. Pokud firma vede
podvojné účetnictví, přesměruje vás aplikace na úvodní stránku (přechodová sestava zůstává dostupná i po přepnutí na účetnictví).

### 74.9.2 Peněžní deník: filtry a rozsah

Horní panel nabízí tři filtry: **Rok** (posledních šest let, přednastaven aktuální), **Od** a **Do** (vlastní rozsah, pokud vyplníte
oba, má přednost před rokem). Po každé změně filtru se sestava přenačte automaticky. Vpravo nahoře je **Výběr sloupců**,
hustota řádků a **Export PDF** / **Export XLSX** za aktuálně zvolený rozsah.

### 74.9.3 Souhrn (počáteční a konečný zůstatek, totály)

Nad tabulkou pohybů je panel **Souhrn** s běžným zůstatkem a rozpadem do daňových kbelíků za celé zvolené období:

<!-- cols: 30 70 -->
| Položka | Význam |
|---|---|
| Počáteční zůstatek | Zůstatek hotovosti nebo účtu k prvnímu dni období |
| Daňový příjem | Příjem vstupující do základu daně z příjmů |
| Osvobozený příjem | Příjem označený jako osvobozený od daně (faktura má příznak) |
| Nedaňový příjem | Příjem mimo základ daně (například složka DPH, nedaňový pokladní příjem) |
| Daňový výdaj | Výdaj uznatelný pro základ daně z příjmů |
| Nedaňový výdaj | Výdaj neuznatelný (vlastní daň a pojistné, odpočitatelná DPH, nedaňový doklad) |
| Převody | Přesuny hotovosti mezi bankou a pokladnou, mimo základ daně |
| Soukromé | Soukromé vklady a výběry, mimo základ daně |
| Nezařazeno | *(zobrazí se jen když existuje)* pohyby čekající na zařazení, viz [§ 74.9.6](#7496-nezarazene-pohyby-a-varovani) |
| Konečný zůstatek | Zůstatek k poslednímu dni období |

Dole je zvýrazněný řádek **Daňový základ (příjem - výdaj)** = daňový příjem minus daňový výdaj.

Částky se počítají na haléře. Příklad u plátce s plným nárokem: úhrada přijaté faktury 12 100 Kč, z toho základ 10 000 Kč a DPH
2 100 Kč, vytvoří daňový výdaj 10 000 Kč a nedaňový výdaj 2 100 Kč. Při 60% poměrném odpočtu je uznatelný výdaj 10 840 Kč a
nedaňová odpočitatelná DPH 1 260 Kč. Konečné DPFO provede vlastní formulářová zaokrouhlení popsaná v
[kapitole Daň z příjmů](43_Dan_z_prijmu.md#43116-jak-se-pocita-dpfo).

### 74.9.4 Tabulka pohybů

Hlavní tabulka řadí všechny pohyby chronologicky a pro každý zobrazuje běžný zůstatek po daném řádku:

<!-- cols: 24 76 -->
| Sloupec | Význam |
|---|---|
| Datum | Datum pohybu (úhrady) |
| Doklad | Číslo dokladu, pokud má vazbu na fakturu nebo přijatou fakturu, je proklikatelné na její detail |
| Protistrana | Partner (odběratel nebo dodavatel) |
| Popis | Popis pohybu |
| Příjem / Výdaj | Částka v příslušném směru |
| Běžný zůstatek | Kumulovaný zůstatek k danému řádku |
| Klasifikace | Barevný štítek s daňovým zařazením (viz [§ 74.9.5](#7495-danova-klasifikace-pohybu)) |
| Zdroj *(skrytý)* | Odkud pohyb pochází: pokladna, banka nebo virtuální noha z úhrady faktury |
| Základ *(skrytý)* | Daňový základ pohybu (bez složky DPH) |
| DPH *(skrytý)* | Složka DPH pohybu |

### 74.9.5 Daňová klasifikace pohybů

Každý pohyb (pokladní doklad, spárovaná bankovní úhrada nebo virtuální noha z úhrady faktury) aplikace automaticky zařadí do
jednoho z kbelíků podle § 7b a § 23 ZDP:

<!-- cols: 24 76 -->
| Klasifikace | Kdy nastane |
|---|---|
| **Daňový příjem** | Inkaso vydané faktury (bez příznaku osvobození), tržba z pokladního dokladu s účelem „Prodej" |
| **Osvobozený příjem** | Inkaso faktury s příznakem „osvobozeno od daně z příjmů" |
| **Nedaňový příjem** | Složka DPH příjmu, pokladní doklad s účelem „Ostatní" na straně příjmu |
| **Daňový výdaj** | Úhrada daňově uznatelné přijaté faktury nebo zaplacené provozní zálohy, nákup z pokladny s účelem „Nákup" |
| **Nedaňový výdaj** | Úhrada přijaté faktury bez příznaku uznatelnosti, odpočitatelná DPH, vlastní daň z příjmů a pojistné, pokladní doklad „Ostatní" na straně výdeje |
| **Převod** | Pokladní doklad s účelem „Převod" (přesun hotovost a banka) |
| **Soukromé** | Soukromé vklady a výběry (ruční zařazení) |
| **Nezařazeno** | Pohyb, který aplikace nedokázala automaticky zařadit, viz [§ 74.9.6](#7496-nezarazene-pohyby-a-varovani) |

U firem, které jsou **plátci DPH**, se u výdaje počítá částka uznatelná pro daň z příjmů jako **základ + skutečně neodpočitatelná
část DPH**. Při plném nároku jde do daňového výdaje jen základ, při nulovém nároku celé brutto a při poměrném nebo kráceném nároku
základ plus neuplatněná část DPH. Výpočet je stejný pro banku, pokladnu i ručně označenou úhradu a respektuje řádkové alokace
smíšeného dokladu. U neplátce jde do daňového kbelíku celé brutto. Plátcovství se posuzuje k datu pohybu podle historie účinnosti
v Nastavení firmy, nikoli podle dnešního stavu. U kráceného odpočtu musí být pro rok dostupný roční koeficient, bez něj nelze
bezpečně určit neodpočitatelnou DPH a dokončit roční uzávěrku.

Dobropisy a vratky (peníze jdoucí opačným směrem) se do stejného kbelíku promítnou se záporným znaménkem, snižují tak daňový
příjem či výdaj daného období místo aby se počítaly zvlášť.

Jeden bankovní pohyb, kterým jste jednou platbou uhradili víc faktur najednou (sloučená úhrada), se v deníku zobrazí jako **jeden
řádek** se souhrnným rozpadem daňový základ / osvobozeno / DPH za všechny spárované doklady.

Bankovní výpis se přiřadí dodavateli přednostně uloženým vlastníkem. U starších výpisů bez této vazby se použije normalizované číslo
účtu nebo IBAN a výpis se přijme jen tehdy, má-li právě jednoho možného vlastníka. Shodné číslo účtu u více dodavatelů se záměrně
nezařadí automaticky. U cizoměnového pohybu ověřte kurz uložený na dokladu, chybějící kurz může zablokovat výpočet a nesmí se
nahrazovat odhadem 1:1.

Klasifikace pokladních dokladů se odvozuje automaticky ze zvoleného **Účelu dokladu** při vystavení PPD/VPD (Prodej, Nákup, Úhrada
faktury, Převod, Ostatní, viz [§ 32.11.3 Hlavička dokladu a účel](32_Pokladna.md#32113-hlavicka-dokladu-a-ucel)). Chcete-li tedy ovlivnit, kam pohyb
v peněžním deníku spadne, upravte účel na pokladním dokladu nebo daňové příznaky (uznatelnost, osvobození) na faktuře.

### 74.9.6 Nezařazené pohyby a varování

Bankovní pohyb, který nemá vazbu na žádnou fakturu ani přijatou fakturu, aplikace **nikdy tiše nezařadí jako nedaňový**, u příchozí
platby by to mohlo podhodnotit základ daně. Místo toho ho označí jako **Nezařazeno** a:

- zobrazí ho v tabulce s červeně podbarveným řádkem,
- u příchozích plateb (riziko podhodnocení příjmu) přidá **blokující upozornění** v červeném panelu nad souhrnem s výzvou pohyb zařadit,
- odchozí nezařazené platby vyhodnotí jen jako méně naléhavé upozornění.

Výjimkou jsou odchozí bankovní pohyby, jejichž popis odpovídá typickému **bankovnímu poplatku** (například obsahuje slovo
„poplatek", „fee", „vedení účtu"), ty se automaticky zařadí jako daňový výdaj bez nutnosti zásahu.

Druhou výjimkou je bankovní pohyb, který je **celý** přiřazený v `Daňová evidence → Ostatní pohledávky a závazky` ke splátce
**přijaté půjčky**, k vrácení **kauce k vrácení** nebo k vrácené **vratné kauci**. Takové peníze nejsou příjmem ani výdajem, takže
se zařadí jako nedaňový příjem nebo výdaj. Příchozí platba k **poskytnuté půjčce nebo splátce** zůstává nezařazená, protože splátka
prodeje je daňovým příjmem. Stejně tak pohyb přiřazený jen zčásti (splátka i s úrokem) nebo k jinému druhu (nájem, pojištění,
poplatek, náhrada): o zařazení rozhodnete sami.

Pokud v žádném spárovaném bankovním výpisu nefiguruje část historie účtu (typicky po změně čísla účtu v nastavení), zobrazí se
navíc samostatné blokující upozornění na **počet bankovních úhrad mimo spárované výpisy**. Bez opravy by tato část historie v
základu daně chyběla úplně.

### 74.9.7 Kontrola vůči přiznanému příjmu

Pod souhrnem je sbalovací panel **Kontrola vůči přiznanému příjmu** (rozbalíte kliknutím na hlavičku). Porovnává **daňový příjem
z deníku** za daný rok s **ročním příjmem** vypočteným z fakturační evidence (zaplacené faktury vystavené v daném roce, bez
příznaku osvobození):

<!-- cols: 34 66 -->
| Řádek | Význam |
|---|---|
| Daňový příjem z deníku | Součet daňového příjmu za pohyby zobrazené v deníku |
| Roční příjem (fakturační) | Kontrolní součet ze zaplacených faktur daného roku |
| Rozdíl | Daňový příjem z deníku minus roční příjem |
| Částečné úhrady (faktura není zaplacená) | Vysvětlující dílčí součet: inkaso u faktur, které ještě nemají stav „Zaplaceno" |
| Hotovostní prodej bez faktury | Vysvětlující dílčí součet: tržby z pokladny bez vazby na fakturu |
| Úhrady bez importu výpisu | Vysvětlující dílčí součet: inkasa zaevidovaná ručně nebo mimo automatické párování výpisu |
| Nevysvětlený zbytek | Rozdíl mezi celkovou odchylkou a součtem výše uvedených vysvětlení |

Barva částky **Rozdíl** je zelená, pokud je odchylka zanedbatelná (do 1 haléře), jinak oranžová. Tento panel je **informativní, ne
kontrola shody**. Kasová báze deníku (podle data úhrady) a fakturační báze ročního příjmu se z principu mohou lišit, panel odchylku
jen rozepíše na vysvětlitelné složky, nic nevynucuje ani nehlásí jako chybu.

### 74.9.8 Pohledávky a závazky

Stránka **Pohledávky a závazky** zobrazuje věkové rozložení (aging) nezaplacených vydaných faktur (pohledávky) a přijatých faktur
(závazky), **nativně po měnách**, bez přepočtu na CZK.

#### Filtr měny a ukazatele

Nahoře je filtr **Měna** (výchozí „Všechny") a tři ukazatele počítané za posledních 12 měsíců:

- **Průměrná doba inkasa (DSO)** - průměrný počet dní mezi vystavením a úhradou zaplacených vydaných faktur, s velikostí vzorku.
- **Průměrná doba úhrady (DPO)** - totéž pro přijaté faktury (jak rychle firma sama platí dodavatelům).
- **Platební morálka (včas)** - procento zaplacených faktur uhrazených v den splatnosti nebo dřív, s celkovým počtem faktur ve vzorku.

#### Tabulky pohledávek a závazků

Obě tabulky (Pohledávky, Závazky) mají shodnou strukturu, řádek na měnu, sloupce podle stáří po splatnosti. Kliknutím na záhlaví
lze měny seřadit podle vybraného pásma nebo celkové částky. Opakované kliknutí obrátí směr řazení v obou tabulkách.

<!-- cols: 30 70 -->
| Sloupec | Rozsah |
|---|---|
| Do splatnosti | Faktury, kterým ještě neuplynula splatnost |
| 1-30 dní | Po splatnosti 1 až 30 dní |
| 31-90 dní | Po splatnosti 31 až 90 dní (sloučené pásmo) |
| 90+ dní | Po splatnosti víc než 90 dní |
| Celkem | Součet za měnu |

U tabulky závazků je nad seznamem poznámka, že **přijaté faktury se evidují jako celek**: částečně uhrazená přijatá faktura se
v přehledu zobrazuje v plné zbývající výši, dokud není označena jako zcela zaplacená (v souladu s tím, jak Pokladna i Platební
příkazy vyžadují u přijatých faktur úhradu celé částky najednou, viz [§ 26](26_Platebni_prikazy.md) a
[§ 32.11.3](32_Pokladna.md#32113-hlavicka-dokladu-a-ucel)).

Export **PDF** / **XLSX** vpravo nahoře vygeneruje sestavu (`pohledavky-zavazky.pdf` / `.xlsx`) se stavem k okamžiku exportu, bez
datumového filtru (sestava vždy zobrazuje aktuální nezaplacené doklady). Přehled **nativně po měnách** sám měny nesčítá. Pokud
potřebujete jedno číslo v CZK napříč měnami, použijte filtr měny a sečtěte ručně.

### 74.9.9 Roční uzávěrka daňové evidence

Před finalizací DPFO se na stránce `Daně → Daň z příjmů` dokončuje roční uzávěrka podle § 7b. Obsahuje kontrolní seznam peněžního
deníku, inventury majetku a zásob, pohledávek, závazků, vysokých nákupů, přechodů režimu a cizích měn. Zadávají se počáteční a
skutečné koncové stavy majetku, hotovosti, banky, zásob, pohledávek, ostatních aktiv, dluhů, rezerv a odpisů. Zaškrtnutí
checklistu potvrzuje, že kontrola proběhla, aplikace sama fyzickou inventuru ani existenci důkazních dokumentů neověří.

Nepeněžní úpravy zahrnují zápočet, barter, naturální příjem, prominutí dluhu, soukromou spotřebu, manko, škodu, inventurní rozdíl a
jinou úpravu § 23. Uživatel volí směr **zvýšení**, **snížení** nebo **neutrální**. První dva směry se přímo promítnou do § 7,
neutrální položka pouze uchová auditní stopu. Aplikace neurčuje, který směr je pro konkrétní případ právně správný.

Dokončením vznikne neměnný snapshot s kontrolním hashem. Jeho stavy se přenesou do `VetaU` Přílohy 1 DPFO a nepeněžní zvýšení či
snížení do příslušných řádků. Uzávěrku nelze dokončit s blokující chybou deníku, nepodporovaným případem, chybějícím konečným
koeficientem kráceného odpočtu nebo neposouzeným vysokým nákupem. Potřebujete-li podklady opravit, vraťte uzávěrku do rozpracovaného
stavu, proveďte opravu a dokončete ji znovu.

Kontrola vysokých nákupů je bezpečnostní guard podle ročního limitu majetku, ne automatické rozhodnutí, zda věc je dlouhodobým
majetkem. Každý označený případ porovnejte s kartou majetku a daňovými odpisy. Pole pro nepodporované zvláštní případy existuje na
úrovni dat a API, ale běžná obrazovka je neumí kompletně popsat. Takový případ evidujte v pracovních podkladech a uzávěrku dokončete
až po odborném posouzení.

### 74.9.10 Návaznost na ostatní moduly

- **Pokladna** ([§ 32](32_Pokladna.md)) - jediné místo, kde v režimu daňové evidence vznikají nové hotovostní pohyby. Volba účelu
  dokladu určuje, jak se pohyb zařadí v peněžním deníku.
- **Přijaté faktury** ([§ 23](23_Prijate_faktury.md)) a **Platební příkazy** ([§ 26](26_Platebni_prikazy.md)) - příznak daňové
  uznatelnosti a druh dokladu (běžná faktura vs. záloha) na přijaté faktuře přímo určují, zda úhrada spadne do daňového, nebo
  nedaňového výdaje.
- **Daň z příjmů (DPFO)** ([samostatná kapitola](43_Dan_z_prijmu.md)) - u OSVČ v režimu daňové evidence čerpá report příjmy a
  výdaje přímo z **totálů peněžního deníku** (kasová báze podle data úhrady) místo z akruální evidence podle data uskutečnění,
  čísla v obou reportech by tak měla navazovat.
- **Více dodavatelů** ([§ 95](95_Multi_supplier.md)) - režim účetnictví (daňová evidence, podvojné účetnictví) je nastavení
  jednotlivého dodavatele. Firma přepínající se mezi více dodavateli může mít každého v jiném režimu.

### 74.9.11 Přechodový můstek mezi evidencí a účetnictvím (přílohy č. 2 a 3 ZDP)

Když se firma vedená v daňové evidenci rozhodne přejít na **podvojné účetnictví** (změna režimu, viz
[Multi_supplier](95_Multi_supplier.md)), nejde jen o technické přepnutí a založení účtového rozvrhu. Zákon (**příloha č. 3 zákona o daních
z příjmů**) vyžaduje, aby OSVČ k datu přechodu **jednorázově upravila základ daně** o rozdíl mezi kasovou bází (daňová evidence,
podle úhrady) a akruální bází (účetnictví, podle vzniku plnění). Tahle úprava se promítá do daňového přiznání za zdaňovací období,
ve kterém bylo zahájeno vedení účetnictví (příslušný řádek úpravy základu daně dle přílohy č. 3 ZDP v přiznání), **ne** do samotného
účetnictví. Je to daňová záležitost, ne účetní zápis.

#### Co se do úpravy počítá

Podle přílohy č. 3 ZDP se základ daně upraví zejména o:

<!-- cols: 28 18 54 -->
| Položka | Efekt na základ daně | Proč |
|---|---|---|
| Neuhrazené pohledávky (vydané faktury) k datu přechodu | **Zvyšují** základ daně | V daňové evidenci by se projevily jako příjem až při úhradě (kasová báze), účetnictví je eviduje k datu vzniku plnění, proto je nutné je k datu přechodu „dohnat" jednorázově. |
| Neuhrazené závazky (přijaté faktury) k datu přechodu | **Snižují** základ daně | Symetricky k pohledávkám, výdaj by se v daňové evidenci projevil až úhradou. |
| Hodnota zásob k datu přechodu | **Zvyšuje** základ daně | Nákup zboží či materiálu byl v daňové evidenci uplatněn jako daňový výdaj už při zaplacení, bez ohledu na to, že zásoba k datu přechodu ještě nebyla spotřebována nebo prodána. |
| Poskytnuté zálohy mimo hmotný majetek | **Zvyšují** základ daně | V daňové evidenci byly výdajem už při úhradě. |
| Přijaté zálohy | **Snižují** základ daně | V daňové evidenci byly příjmem už při inkasu. |
| Ceniny | **Zvyšují** základ daně | Jejich stav se do sestavy doplňuje ručně. |

Kromě těchto položek příloha č. 3 ZDP pamatuje i na kurzové rozdíly u pohledávek a závazků v cizí měně a na dříve vytvořené
rezervy či opravné položky. Ty je ale potřeba posoudit individuálně s účetní nebo daňovým poradcem, aplikace pro ně žádné
podklady negeneruje.

#### Co aplikace nabízí: podklady pro přechod

Účetní režim se eviduje historicky s účinností vždy k 1. lednu, právnická osoba nemůže zvolit daňovou evidenci. Přiznání za starší
rok proto použije režim platný právě pro tento rok. Přechodová sestava zůstává dostupná i po přepnutí na účetnictví.

Aplikace úpravu základu daně **neprovádí ani nezaúčtovává automaticky**. Jde o jednorázový zásah do daňového přiznání, ne o účetní
zápis, který by šlo za uživatele bezpečně odhadnout. Připraví ale **podklady**: stránku `Daňová evidence → Přechod DE → účetnictví`
se seznamem neuhrazených vydaných a přijatých faktur, poskytnutých a přijatých záloh k zadanému datu s jejich součtem v Kč. Stejná
data jsou dostupná i přes API:

```
GET /api/tax-evidence/transition-report?as_of=2026-12-31
GET /api/tax-evidence/transition-report?as_of=2026-12-31&direction=accounting_to_tax
```

Parametr `as_of` je datum, ke kterému se sestavují podklady (typicky poslední den původního režimu). Pokud se datum uložení
přepnutí v aplikaci liší od okamžiku, ke kterému se přiznání skutečně vztahuje, zadejte správné rozvahové datum, ne den, kdy jste
nastavení uložili. `direction` určuje směr: výchozí `tax_to_accounting` počítá podle přílohy č. 3 ZDP, `accounting_to_tax` podle
přílohy č. 2 ZDP. Ve směru zpět závazky základ daně zvyšují, pohledávky, zásoby a ceniny ho snižují. Odpověď obsahuje:

<!-- cols: 36 64 -->
| Klíč | Význam |
|---|---|
| `receivables` | Seznam neuhrazených vydaných faktur k `as_of`: doklad, protistrana, měna, částka nativně i přepočtená na Kč. |
| `payables` | Totéž pro neuhrazené přijaté faktury. |
| `totals.receivables_czk` / `totals.payables_czk` | Součty v Kč napříč měnami (přepočet kurzem zafixovaným na dokladu). |
| `totals.net_adjustment_czk` | Čistý dopad na základ daně se znaménky podle zvoleného směru, včetně záloh a dostupného ocenění zásob. |
| `inventory` | Pokud firma **nemá zapnutý modul Sklad**, obsahuje jen poznámku, že hodnotu zásob je nutné doplnit ručně. Pokud sklad zapnutý má, obsahuje automatické ocenění zásob k datu přechodu metodou váženého průměru, před použitím v přiznání ho porovnejte s fyzickou inventurou. |

Při změně mezi skutečnými výdaji a výdajovým paušálem aplikace vytvoří blokující upozornění na úpravu podle § 23 odst. 8. Přechodová
sestava připraví podklady, ale současná obrazovka neumí potvrdit, že byla právní úprava vyřešena, ani ji propsat do DPFO. V takovém
roce nelze spoléhat na automatickou finalizaci. Úpravu sestavte s daňovým poradcem a dokončete v EPO.

### 74.9.12 Průvodce aktivací podvojného účetnictví

Pokud má firma v daňové evidenci už doklady, po volbě **Podvojné účetnictví** v nastavení pokračuje administrátor v pětikrokovém
průvodci. Samotná volba režim ještě nezapne:

1. **Datum zahájení** - určuje první den podvojného účetnictví. Starší doklady se jednotlivě nedoúčtují, jejich zůstatky patří do otevírací rozvahy.
2. **Otevírací rozvaha** - lze ji předvyplnit z podkladů daňové evidence a potom ručně upravit. Součet MD a D musí být shodný, protistranu účtu 701 doplní aplikace. Firma bez počátečních stavů může krok přeskočit.
3. **Kontrola** - projde faktury, pokladní doklady a bankovní transakce bez zápisu. Vedle vyrovnanosti deníku nezávisle porovná počet postovatelných vydaných a přijatých dokladů s počtem zpracovaných položek. Tím zachytí i chybějící samostatně vyrovnaný zápis, který by samotná kontrola MD = D neodhalila. U každé přeskočené položky zobrazí srozumitelný důvod. Přijaté zálohové výzvy mezi doklady čekající na účetní předpis nezařazuje, účtuje se až jejich úhrada proti účtu 314. U problematických faktur protokol zobrazí číslo, datum, důvod a odkaz přímo na detail dokladu. Pokračovat lze jen bez chyb, s úplným pokrytím dokladů a s vyrovnaným deníkem.
4. **Spuštění** - po výslovném potvrzení proběhne doúčtování na pozadí. Průvodce ukazuje aktuální fázi, průběh i protokol. Zamčená ani uzavřená období se nikdy neotevírají ani neobcházejí. Po předpisech dokladů zaúčtuje jednoznačně spárované bankovní úhrady, při aktivaci je nezastaví běžné nastavení automatiky „jen navrhovat". Nakonec znovu přepočítá vyúčtovací doklady navázané na zálohy, aby doplnil zúčtování 324/311 nebo 321/314. Nespárované pohyby bez bezpečné vazby na doklad se automaticky nezaúčtují.
5. **Protokol** - po úspěchu se teprve zapne podvojné účetnictví a zůstane uložený přehled zpracovaných, přeskočených a chybných položek.

Průvodce automaticky nezakládá karty majetku ani neúčtuje odpisy. Přijatou fakturu označenou jako dlouhodobý majetek zaúčtuje na
účet pořízení (typicky 042/321). Majetek pořízený před datem přechodu se založí jako historická karta a jeho pořizovací cena i
oprávky patří do otevírací rozvahy. Majetek pořízený po datu přechodu se následně zařadí do užívání a odpisy se zaúčtují v modulu
`Nákup → Majetek` (viz [Majetek](28_Majetek.md)).

Pod průvodcem je stránkovaná historie všech kontrol a ostrých spuštění. Výběrem staršího běhu lze znovu zobrazit jeho uložený
protokol, dlouhá historie se proto nikdy tiše neořízne jen na poslední záznamy.

Průvodce je bezpečně opakovatelný: nové spuštění už zaúčtované doklady nezdvojí. Při přerušení nebo chybě zůstane původní účetní
režim aktivní a průvodce nabídne opravu a opakování. Také po dokončené aktivaci nabízí tlačítko **Doúčtovat chybějící zápisy**.
Jedním potvrzením znovu projde zbývající kandidáty, zaúčtuje jen jednoznačné položky v otevřených obdobích a doplní zúčtování záloh,
existující zápisy díky idempotenci nezdvojí. Po dokončení lze položku **Aktivace a doúčtování** tlačítkem **Skrýt z menu**
odstranit z vlastní navigace, přímá adresa průvodce zůstává dostupná.

### 74.9.13 Omezení a tipy

- Sestavy jsou z většiny **read-only**. V peněžním deníku ani v přehledu pohledávek a závazků needitujete částky ani doklady,
  opravu zařazení uděláte na zdrojovém dokladu (účel pokladního dokladu, příznak uznatelnosti či osvobození na faktuře) nebo doplněním
  čísla účtu v nastavení u nespárovaných bankovních výpisů. Výjimka je řádek **ruční zařazení** (**Zařadit...**) u bankovních a
  pokladních pohybů bez navázaného dokladu ([§ 74.9.6](#7496-nezarazene-pohyby-a-varovani)), kde kategorii nastavíte přímo v
  deníku, přepsat lze i zpět přes **Zrušit ruční zařazení**.
- **Blokující upozornění nezmizí samo.** Dokud zůstane nezařazený příchozí bankovní pohyb, aplikace ho bude v deníku i nadále
  vypisovat jako riziko podhodnocení základu daně.
- Kontrola vůči přiznanému příjmu je **informativní**, ne validace. Odchylka kasové a fakturační báze je u daňové evidence očekávaná.
- Označení DPH/KH jako skutečně odeslaného může posunout zámek daňových období. Pokus změnit rozhodný daňový doklad v zamčeném období
  může být odmítnut. Pouhé stažení XML zámek nevytváří, potvrzení z portálu vždy archivujte samostatně.
- Přechodová sestava, kontrolní seznam uzávěrky ani validace proti XSD nejsou právním posouzením. Zvláštní zahraniční operace,
  nestandardní zápočty, rezervy a opravné položky dokončete ručně.

### 74.9.14 Odhad daně a pojistného během roku

Stránka `Daňová evidence → Odhad daně a pojistného` ukáže pro zvolený rok, kolik vychází daň z příjmů a sociální a zdravotní
pojištění OSVČ podle dosud zapsaných pohybů. Počítá stejnými pravidly jako přiznání DPFO a přehledy pro ČSSZ a zdravotní
pojišťovnu, takže se po dokončení roku čísla nerozejdou.

- **Podklady § 7**: daňové příjmy a výdaje z peněžního deníku, potvrzené daňové odpisy, daňová zůstatková cena prodaného nebo
  zlikvidovaného majetku a úpravy základu z roční uzávěrky. Navíc přičte daňové odpisy roku, které ještě nejsou potvrzené: v daňové
  evidenci se potvrzují až k roční uzávěrce, ale do výdajů roku patří.
- **Skutečné výdaje a výdajový paušál**: obě varianty vedle sebe se stejnými příjmy, dílčím základem § 7, daní po slevách a
  zvýhodnění na děti (podle profilu v `Daně → Daň z příjmů`), pojistným a celkovou zátěží. Varianta zvolená v profilu je označená,
  levnější varianta má štítek **výhodnější**. Paušál respektuje zákonný strop podle § 7 odst. 7 ZDP. Profil s jednotlivými
  činnostmi se srovnává jen ve zvolené variantě.
- **Zálohy a vypořádání**: odhad za rok, zaplacené zálohy (ruční údaj v přiznání, jinak spárované zálohy z předpisu), zálohy, které
  podle předpisu ještě zbývá zaplatit, a výsledný doplatek nebo přeplatek.

Odhad příjmy a výdaje do konce roku nepromítá, pojistné ale počítá s ročním minimem vyměřovacího základu. Na začátku roku proto
často vychází pojistné z minima, i když za celý rok bude vyšší. Způsob uplatnění výdajů měňte v profilu přiznání; při přechodu mezi
paušálem a skutečnými výdaji upravte základ podle § 23 odst. 8 ZDP.

### 74.9.15 Pravidla nákladů v daňové evidenci

Pravidla nákladů ([§ 65.4](65_Sablony.md#654-krok-za-krokem-pravidlo-nakladu)) najdete v daňové evidenci v menu
`Daňová evidence → Pravidla nákladů`. Kritéria (dodavatel, část názvu dodavatele, popis položky, rozpětí ceny) i priorita
fungují stejně, jen pravidlo nenastavuje účet a nemá časové rozlišení předplatného. Místo účtu určuje:

- **Druh nákladu** položky: dlouhodobý majetek se do výdajů při úhradě nepočítá, drobný majetek se zapíše do evidence
  drobného majetku.
- **Daňovou uznatelnost dokladu** (Neměnit, Daňový výdaj, Nedaňový výdaj): podle ní peněžní deník řadí úhradu mezi daňové
  nebo nedaňové výdaje.

Automatická pravidla se uplatní při přijetí přijaté faktury. Druh nákladu doplní jen položkám, které ho ještě nemají
z ruční volby. Uznatelnost dokladu změní jen tehdy, když automatická pravidla zachytí **všechny** položky dokladu a shodují
se. Doklad, u kterého se pravidla rozcházejí nebo část položek nezachytila, zůstane beze změny a uznatelnost nastavíte ručně.

### 74.9.16 Pravidla bankovních pohybů

Pohyby bez dokladu se opakují: bankovní poplatky, převody mezi vlastními účty, vklady z osobních peněz. Místo ručního
zařazování každého pohybu v peněžním deníku (§ 74.4) si v menu `Daňová evidence → Pravidla bankovních pohybů` založte
pravidlo.

- **Podmínky:** směr (příchozí, odchozí), protiúčet, variabilní symbol, text ve zprávě nebo názvu protistrany a rozpětí
  částky. Vyplnit je potřeba aspoň protiúčet, variabilní symbol nebo text, platit musí všechny vyplněné.
- **Akce:** **Ignorovat** (pohyb se nepáruje s doklady a nenabízí se k párování) a **Zařazení v peněžním deníku**
  (stejné volby jako ruční zařazení). Výdaj zařazený jako daňový nebo nedaňový určuje daňovou uznatelnost stejně jako
  pravidla nákladů (§ 74.9.15).
- **Kdy se uplatní:** při načtení výpisu na pohyby, které nenašly doklad, a tlačítkem **Uplatnit na stávající pohyby**.
  Ruční zařazení v peněžním deníku má vždy přednost a pravidlo ho nepřepíše. Rozhoduje pravidlo s nejnižší prioritou.

> [!TIP]
> Ignorovaný pohyb v peněžním deníku zůstává, jinak by zůstatek deníku nesouhlasil s bankou. Ignorování jen říká, že k němu
> nepatří žádný doklad. Do kterého řádku deníku pohyb patří, určuje zařazení. Pro převod mezi vlastními účty proto zvolte
> **Ignorovat** a zařazení **Převod**.

## 74.10 Související kapitoly

- [Pokladna](32_Pokladna.md) - pokladní doklady, které se promítají do peněžního deníku.
- [Přijaté faktury](23_Prijate_faktury.md) - příznaky uznatelnosti a DPH.
- [Platební příkazy](26_Platebni_prikazy.md) - hromadná úhrada přijatých faktur.
- [Daň z příjmů](43_Dan_z_prijmu.md) - přiznání DPFO z totálů peněžního deníku.
- [Výkazy DPH](41_Vykazy_DPH.md) - DPH nezávislé na režimu.
- [Majetek](28_Majetek.md) - karty majetku a odpisy po přechodu.
- [Multi_supplier](95_Multi_supplier.md) - přepnutí režimu účetnictví.
