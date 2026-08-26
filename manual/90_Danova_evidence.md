# 90. Daňová evidence

Modul **Daňová evidence** je určen firmám s **režimem účetnictví „Daňová evidence"**
(OSVČ vedoucí evidenci podle §7b zákona o daních z příjmů — kasová báze, tedy podle
data úhrady, ne podle vystavení dokladu). Je to **alternativa k podvojnému
účetnictví** — jde o dva vzájemně se vylučující režimy jedné firmy (dodavatele),
mezi kterými se přepíná v nastavení dodavatele (viz [Multi_supplier](91_Multi_supplier.md)).
Firma v režimu „Podvojné účetnictví" místo této sekce vidí plnohodnotné **Účetnictví**
(deník, hlavní kniha, rozvaha, výsledovka) popsané v [§ 44 a násl.](45_Ucetni_denik.md).

> [!NOTE]
> Daňová evidence **nezavádí žádnou vlastní účetní knihu ani zápisy**. Peněžní deník
> i přehled pohledávek/závazků jsou sestavy postavené nad daty, která už v systému
> existují — vydanými a přijatými fakturami, pokladními doklady a spárovanými
> bankovními pohyby. Částky, doklady ani protistrany v nich needituješ; to zajistíš
> na zdrojovém dokladu. Jedinou výjimkou je **ruční zařazení** u bankovních/pokladních
> pohybů bez navázaného dokladu — u nich přímo v deníku přepíšeš daňovou kategorii
> (viz [§ 90.6](#906-nezarazene-pohyby-a-varovani)).

Peněžní deník je zdrojem daně z příjmů, nikoli DPH. DPHDP3, KH, SH a Kniha DPH
používají společnou řádkovou evidenci z faktur a daňových pokladních dokladů podle
DUZP či pravidel nároku na odpočet. Přepnutí mezi daňovou evidencí a účetnictvím
proto samo nesmí změnit výsledek DPH. Viz [Výkazy DPH](36_Vykazy_DPH.md).

## 90.1 Zapnutí režimu a dostupnost v menu

Režim účetnictví je nastavení **per dodavatel** (firmu) — přepínáš ho v editaci
dodavatele mezi „Daňová evidence" a „Podvojné účetnictví" (viz
[Multi_supplier](91_Multi_supplier.md)). Podle aktuálně zvoleného režimu se v hlavním
menu zobrazí buď sekce **Účetnictví**, nebo sekce **Daňová evidence** — nikdy obě
najednou.

Sekce **Daňová evidence** v menu obsahuje dvě položky:

- **Peněžní deník** — chronologický přehled příjmů a výdajů s daňovou klasifikací.
- **Pohledávky a závazky** — věkové rozložení nezaplacených vydaných a přijatých
  faktur.

Přímý vstup na URL těchto stránek je stejně jako u zobrazení v menu vázaný na
aktuální režim dodavatele — pokud firma vede podvojné účetnictví, přesměruje tě
systém na úvodní stránku.

> [!TIP]
> **Pokladna funguje v obou režimech.** Bez ohledu na to, jestli firma vede daňovou
> evidenci nebo podvojné účetnictví, můžeš vystavovat příjmové a výdejové pokladní
> doklady (PPD/VPD) — viz [Pokladna](30_Pokladna.md). V daňové evidenci ale doklad
> **nevytváří žádný zápis do účetního deníku** (ten v tomto režimu neexistuje) — pohyb
> se rovnou promítne do peněžního deníku popsaného v této kapitole.

## 90.2 Peněžní deník — filtry a rozsah

Stránka **Peněžní deník** nabízí v horním panelu tři filtry:

- **Rok** — výběr z posledních šesti let (aktuální + 5 předchozích); přednastaven
  je aktuální rok.
- **Od** / **Do** — vlastní datumový rozsah; pokud vyplníš oba, má přednost před
  volbou roku.

Po každé změně filtru se sestava přenačte automaticky (není potřeba samostatné
tlačítko „Zobrazit").

Vpravo nahoře jsou k dispozici:

- **Výběr sloupců** a **hustota řádků** — stejné ovládací prvky jako u ostatních
  tabulek v aplikaci (uložené preference per uživatel).
- **Export PDF** / **Export XLSX** — vygeneruje sestavu za aktuálně zvolený rozsah
  pod názvem `penezni-denik-{rok}.pdf` / `.xlsx`.

## 90.3 Souhrn (počáteční a konečný zůstatek, totály)

Nad tabulkou pohybů je panel **Souhrn** s běžným zůstatkem a rozpadem do daňových
kbelíků za celé zvolené období:

| Položka | Význam |
|---|---|
| Počáteční zůstatek | Zůstatek hotovosti/účtu k prvnímu dni období |
| Daňový příjem | Příjem vstupující do základu daně z příjmů |
| Osvobozený příjem | Příjem označený jako osvobozený od daně (faktura má příznak) |
| Nedaňový příjem | Příjem mimo základ daně (např. DPH složka, nedaňový pokladní příjem) |
| Daňový výdaj | Výdaj uznatelný pro základ daně z příjmů |
| Nedaňový výdaj | Výdaj neuznatelný (např. vlastní daň/pojistné, odpočitatelná DPH, nedaňový doklad) |
| Převody | Přesuny hotovosti mezi bankou a pokladnou — mimo základ daně |
| Soukromé | Soukromé vklady/výběry — mimo základ daně |
| Nezařazeno | *(zobrazí se jen když existuje)* — pohyby čekající na zařazení, viz § 90.6 |
| Konečný zůstatek | Zůstatek k poslednímu dni období |

Dole je zvýrazněný řádek **Daňový základ (příjem − výdaj)** = daňový příjem minus
daňový výdaj — to je číslo, které se má shodovat s podklady pro přiznání k dani
z příjmů.

Částky se počítají na haléře. Příklad u plátce s plným nárokem: úhrada přijaté
faktury 12 100 Kč, z toho základ 10 000 Kč a DPH 2 100 Kč, vytvoří daňový výdaj
10 000 Kč a nedaňový výdaj 2 100 Kč. Při 60% poměrném odpočtu je uznatelný výdaj
10 840 Kč a nedaňová odpočitatelná DPH 1 260 Kč. Konečné DPFO provede vlastní
formulářová zaokrouhlení popsaná v
[kapitole Daň z příjmů](38_Dan_z_prijmu.md#382-jak-se-pocita-dpfo).

## 90.4 Tabulka pohybů

Hlavní tabulka řadí všechny pohyby chronologicky a pro každý zobrazuje běžný
zůstatek po daném řádku. Dostupné (a přes výběr sloupců vypínatelné) sloupce:

| Sloupec | Význam |
|---|---|
| Datum | Datum pohybu (úhrady) |
| Doklad | Číslo dokladu; pokud má vazbu na fakturu nebo přijatou fakturu, je proklikatelné na její detail |
| Protistrana | Partner (odběratel/dodavatel) |
| Popis | Popis pohybu |
| Příjem / Výdaj | Částka v příslušném směru |
| Běžný zůstatek | Kumulovaný zůstatek k danému řádku |
| Klasifikace | Barevný štítek s daňovým zařazením (viz § 90.5) |
| Zdroj *(skrytý)* | Odkud pohyb pochází — pokladna, banka nebo virtuální noha z úhrady faktury |
| Základ *(skrytý)* | Daňový základ pohybu (bez DPH složky) |
| DPH *(skrytý)* | DPH složka pohybu |

Sloupce Zdroj, Základ a DPH jsou ve výchozím zobrazení skryté — zapneš je přes
výběr sloupců, hodí se pro kontrolu, jak se DPH rozpočítalo u pohybů s vazbou na
plátcovskou fakturu.

## 90.5 Daňová klasifikace pohybů

Každý pohyb (pokladní doklad, spárovaná bankovní úhrada nebo virtuální noha z
úhrady faktury) systém automaticky zařadí do jednoho z kbelíků podle §7b/§23 ZDP:

| Klasifikace | Kdy nastane |
|---|---|
| **Daňový příjem** | Inkaso vydané faktury (bez příznaku osvobození), tržba z pokladního dokladu s účelem „Prodej" |
| **Osvobozený příjem** | Inkaso faktury s příznakem „osvobozeno od daně z příjmů" |
| **Nedaňový příjem** | DPH složka příjmu; pokladní doklad s účelem „Ostatní" na straně příjmu |
| **Daňový výdaj** | Úhrada daňově uznatelné přijaté faktury nebo zaplacené provozní zálohy; nákup z pokladny s účelem „Nákup" |
| **Nedaňový výdaj** | Úhrada přijaté faktury bez příznaku uznatelnosti; odpočitatelná DPH; vlastní daň z příjmů a pojistné; pokladní doklad „Ostatní" na straně výdeje |
| **Převod** | Pokladní doklad s účelem „Převod" (přesun hotovost ↔ banka) |
| **Soukromé** | Soukromé vklady/výběry (ruční zařazení) |
| **Nezařazeno** | Pohyb, který systém nedokázal automaticky zařadit — viz § 90.6 |

U firem, které jsou **plátci DPH**, se u výdaje počítá částka uznatelná pro daň
z příjmů jako **základ + skutečně neodpočitatelná část DPH**. Při plném nároku
jde do daňového výdaje jen základ, při nulovém nároku celé brutto a při poměrném
nebo kráceném nároku základ plus neuplatněná část DPH. Výpočet je stejný pro
banku, pokladnu i ručně označenou úhradu a respektuje řádkové alokace smíšeného
dokladu. U neplátce jde do daňového kbelíku celé brutto. Plátcovství se posuzuje
k datu pohybu podle historie účinnosti v Nastavení firmy, nikoli podle dnešního stavu.
U kráceného odpočtu musí být pro rok dostupný roční koeficient; bez něj nelze bezpečně
určit neodpočitatelnou DPH a dokončit roční uzávěrku.

Dobropisy a vratky (peníze jdoucí opačným směrem) se do stejného kbelíku
promítnou se záporným znaménkem — snižují tak daňový příjem/výdaj daného období
místo aby se počítaly zvlášť.

Jeden bankovní pohyb, kterým jsi jednou platbou uhradil(a) víc faktur najednou
(sloučená úhrada), se v deníku zobrazí jako **jeden řádek** se souhrnným
rozpadem daňový základ / osvobozeno / DPH za všechny spárované doklady.

Bankovní výpis se přiřadí dodavateli přednostně uloženým vlastníkem. U starších
výpisů bez této vazby se použije normalizované číslo účtu/IBAN a výpis se přijme jen
tehdy, má-li právě jednoho možného vlastníka. Shodné číslo účtu u více dodavatelů se
záměrně nezařadí automaticky. U cizoměnového pohybu ověř kurz uložený na dokladu;
chybějící kurz může zablokovat výpočet a nesmí se nahrazovat odhadem 1:1.

> [!TIP]
> Klasifikace pokladních dokladů se odvozuje automaticky ze zvoleného **Účelu
> dokladu** při vystavení PPD/VPD (Prodej, Nákup, Úhrada faktury, Převod,
> Ostatní — viz [§ 30.3.2 Účel dokladu](30_Pokladna.md#3032-ucel-dokladu)).
> Chceš-li tedy ovlivnit, kam pohyb v peněžním deníku spadne, uprav účel na
> pokladním dokladu nebo daňové příznaky (uznatelnost, osvobození) na faktuře.

## 90.6 Nezařazené pohyby a varování

Bankovní pohyb, který nemá vazbu na žádnou fakturu ani přijatou fakturu, systém
**nikdy tiše nezařadí jako nedaňový** — u příchozí platby by to mohlo podhodnotit
základ daně. Místo toho ho označí jako **Nezařazeno** a:

- zobrazí ho v tabulce s červeně podbarveným řádkem,
- u příchozích plateb (riziko podhodnocení příjmu) přidá **blokující upozornění**
  v červeném panelu nad souhrnem s výzvou pohyb zařadit,
- odchozí nezařazené platby vyhodnotí jen jako méně naléhavé upozornění.

Výjimkou jsou odchozí bankovní pohyby, jejichž popis odpovídá typickému
**bankovnímu poplatku** (např. obsahuje slovo „poplatek", „fee", „vedení účtu") —
ty se automaticky zařadí jako daňový výdaj bez nutnosti zásahu.

Pokud v žádném spárovaném bankovním výpisu nefiguruje část historie účtu (typicky
po změně čísla účtu v nastavení), zobrazí se navíc samostatné blokující
upozornění na **počet bankovních úhrad mimo spárované výpisy** — bez opravy by
tato část historie v základu daně chyběla úplně.

## 90.7 Kontrola vůči přiznanému příjmu

Pod souhrnem je sbalovací panel **Kontrola vůči přiznanému příjmu** (rozbalíš
kliknutím na hlavičku). Porovnává **daňový příjem z deníku** za daný rok s
**ročním příjmem** vypočteným z fakturační evidence (zaplacené faktury vystavené
v daném roce, bez příznaku osvobození) a zobrazí:

| Řádek | Význam |
|---|---|
| Daňový příjem z deníku | Součet daňového příjmu za pohyby zobrazené v deníku |
| Roční příjem (fakturační) | Kontrolní součet ze zaplacených faktur daného roku |
| Rozdíl | Daňový příjem z deníku minus roční příjem |
| Částečné úhrady (faktura není zaplacená) | Vysvětlující dílčí součet — inkaso u faktur, které ještě nemají stav „Zaplaceno" |
| Hotovostní prodej bez faktury | Vysvětlující dílčí součet — tržby z pokladny bez vazby na fakturu |
| Úhrady bez importu výpisu | Vysvětlující dílčí součet — inkasa zaevidovaná ručně/mimo automatické párování výpisu |
| Nevysvětlený zbytek | Rozdíl mezi celkovou odchylkou a součtem výše uvedených vysvětlení |

Barva částky **Rozdíl** je zelená, pokud je odchylka zanedbatelná (do 1 haléře),
jinak oranžová.

> [!NOTE]
> Tento panel je **informativní, ne kontrola shody**. Kasová báze deníku (podle
> data úhrady) a fakturační báze ročního příjmu se z principu můžou lišit —
> panel odchylku jen rozepíše na vysvětlitelné složky, nic nevynucuje ani
> nehlásí jako chybu.

## 90.8 Pohledávky a závazky

Stránka **Pohledávky a závazky** zobrazuje věkové rozložení (aging) nezaplacených
vydaných faktur (pohledávky) a přijatých faktur (závazky) — **nativně po měnách**,
bez přepočtu na CZK.

### 90.8.1 Filtr měny a KPI

Nahoře je filtr **Měna** (výchozí „Všechny") a tři ukazatele počítané za
posledních 12 měsíců:

- **Průměrná doba inkasa (DSO)** — průměrný počet dní mezi vystavením a úhradou
  zaplacených vydaných faktur, s velikostí vzorku.
- **Průměrná doba úhrady (DPO)** — totéž pro přijaté faktury (jak rychle firma
  sama platí dodavatelům).
- **Platební morálka (včas)** — procento zaplacených faktur uhrazených v den
  splatnosti nebo dřív, s celkovým počtem faktur ve vzorku.

### 90.8.2 Tabulky pohledávek a závazků

Obě tabulky (Pohledávky / Závazky) mají shodnou strukturu — řádek na měnu, sloupce
podle stáří po splatnosti:

| Sloupec | Rozsah |
|---|---|
| Do splatnosti | Faktury, kterým ještě neuplynula splatnost |
| 1–30 dní | Po splatnosti 1 až 30 dní |
| 31–90 dní | Po splatnosti 31 až 90 dní (sloučené pásmo) |
| 90+ dní | Po splatnosti víc než 90 dní |
| Celkem | Součet za měnu |

U tabulky závazků je nad seznamem poznámka, že **přijaté faktury se evidují jako
celek** — částečně uhrazená přijatá faktura se v přehledu zobrazuje v plné zbývající
výši, dokud není označena jako zcela zaplacená (v souladu s tím, jak Pokladna
i Platební příkazy vyžadují u přijatých faktur úhradu celé částky najednou, viz
[§ 26](26_Platebni_prikazy.md) a [§ 30.3.2](30_Pokladna.md#3032-ucel-dokladu)).

Export **PDF** / **XLSX** vpravo nahoře vygeneruje sestavu (`pohledavky-zavazky.pdf`
/ `.xlsx`) se stavem k okamžiku exportu — bez datumového filtru (sestava vždy
zobrazuje aktuální nezaplacené doklady).

### 90.8.3 Roční uzávěrka daňové evidence

Před finalizací DPFO se na stránce **Daně → Daň z příjmů** dokončuje roční uzávěrka
podle § 7b. Obsahuje kontrolní seznam peněžního deníku, inventury majetku a zásob,
pohledávek, závazků, vysokých nákupů, přechodů režimu a cizích měn. Zadávají se
počáteční a skutečné koncové stavy majetku, hotovosti, banky, zásob, pohledávek,
ostatních aktiv, dluhů, rezerv a odpisů. Zaškrtnutí checklistu potvrzuje, že kontrola
proběhla; aplikace sama fyzickou inventuru ani existenci důkazních dokumentů neověří.

Nepeněžní úpravy zahrnují zápočet, barter, naturální příjem, prominutí dluhu,
soukromou spotřebu, manko, škodu, inventurní rozdíl a jinou úpravu § 23. Uživatel
volí směr **zvýšení**, **snížení** nebo **neutrální**. První dva směry se přímo
promítnou do § 7, neutrální položka pouze uchová auditní stopu; systém neurčuje,
který směr je pro konkrétní případ právně správný.

Dokončením vznikne neměnný snapshot s kontrolním hashem. Jeho stavy se přenesou
do `VetaU` Přílohy 1 DPFO a nepeněžní zvýšení/snížení do příslušných řádků.
Uzávěrku nelze dokončit s blokující chybou deníku, nepodporovaným případem,
chybějícím konečným koeficientem kráceného odpočtu nebo neposouzeným vysokým
nákupem. Potřebuješ-li podklady opravit, vrať uzávěrku do rozpracovaného stavu,
proveď opravu a dokonči ji znovu.

Kontrola vysokých nákupů je bezpečnostní guard podle ročního limitu majetku, ne
automatické rozhodnutí, zda věc je dlouhodobým majetkem. Každý označený případ
porovnej s kartou majetku a daňovými odpisy. Pole pro nepodporované zvláštní případy
existuje na úrovni dat/API, ale běžná obrazovka je neumí kompletně popsat; takový
případ eviduj v pracovních podkladech a uzávěrku dokonči až po odborném posouzení.

## 90.9 Návaznost na ostatní moduly

- **Pokladna** ([§ 29](30_Pokladna.md)) — jediné místo, kde v režimu daňové
  evidence vznikají nové hotovostní pohyby; volba účelu dokladu určuje, jak se
  pohyb zařadí v peněžním deníku.
- **Přijaté faktury** ([§ 23](23_Prijate_faktury.md)) a **Platební příkazy**
  ([§ 26](26_Platebni_prikazy.md)) — příznak daňové uznatelnosti a druh dokladu
  (běžná faktura vs. záloha) na přijaté faktuře přímo určují, zda úhrada spadne
  do daňového, nebo nedaňového výdaje.
- **Daň z příjmů (DPFO)** ([samostatná kapitola](38_Dan_z_prijmu.md)) — u OSVČ v režimu daňové
  evidence čerpá report příjmy a výdaje přímo z **totálů peněžního deníku**
  (kasová báze podle data úhrady) místo z akruální evidence podle data
  uskutečnění; čísla v obou reportech by tak měla navazovat.
- **Více dodavatelů** ([§ 52](91_Multi_supplier.md)) — režim účetnictví (daňová
  evidence / podvojné účetnictví) je nastavení jednotlivého dodavatele; firma
  přepínající se mezi více dodavateli může mít každého v jiném režimu.

## 90.10 Přechodový můstek mezi evidencí a účetnictvím (přílohy č. 2 a 3 ZDP)

Když se firma vedená v daňové evidenci rozhodne přejít na **podvojné účetnictví**
(změna režimu, viz [§ 91.6.1](91_Multi_supplier.md)), nejde jen o
technické přepnutí a založení účtového rozvrhu — zákon (**příloha č. 3 zákona
o daních z příjmů**) vyžaduje, aby OSVČ k datu přechodu **jednorázově upravila
základ daně** o rozdíl mezi kasovou bází (daňová evidence — podle úhrady) a
akruální bází (účetnictví — podle vzniku plnění). Tahle úprava se promítá do
daňového přiznání za zdaňovací období, ve kterém bylo zahájeno vedení účetnictví
(příslušný řádek úpravy základu daně dle přílohy č. 3 ZDP v přiznání), **ne**
do samotného účetnictví — je to daňová záležitost, ne účetní zápis.

### 90.10.1 Co se do úpravy počítá

Podle přílohy č. 3 ZDP se základ daně upraví zejména o:

| Položka | Efekt na základ daně | Proč |
|---|---|---|
| Neuhrazené pohledávky (vydané faktury) k datu přechodu | **Zvyšují** základ daně | V daňové evidenci by se projevily jako příjem až při úhradě (kasová báze); účetnictví je eviduje k datu vzniku plnění, proto je nutné je k datu přechodu „dohnat" jednorázově. |
| Neuhrazené závazky (přijaté faktury) k datu přechodu | **Snižují** základ daně | Symetricky k pohledávkám — výdaj by se v daňové evidenci projevil až úhradou. |
| Hodnota zásob k datu přechodu | **Zvyšuje** základ daně | Nákup zboží/materiálu byl v daňové evidenci uplatněn jako daňový výdaj už při zaplacení, bez ohledu na to, že zásoba k datu přechodu ještě nebyla spotřebována nebo prodána. |
| Poskytnuté zálohy mimo hmotný majetek | **Zvyšují** základ daně | V daňové evidenci byly výdajem už při úhradě. |
| Přijaté zálohy | **Snižují** základ daně | V daňové evidenci byly příjmem už při inkasu. |
| Ceniny | **Zvyšují** základ daně | Jejich stav se do sestavy doplňuje ručně. |

Kromě těchto tří hlavních položek příloha č. 3 ZDP pamatuje i na kurzové rozdíly
u pohledávek/závazků v cizí měně a na dříve vytvořené rezervy či opravné položky.
Ty je ale potřeba posoudit individuálně s účetní nebo daňovým poradcem — systém
pro ně žádné podklady negeneruje.

### 90.10.2 Co systém nabízí — podklady pro přechod

Účetní režim se eviduje historicky s účinností vždy k 1. lednu; právnická osoba
nemůže zvolit daňovou evidenci. Přiznání za starší rok proto použije režim platný
právě pro tento rok. Přechodová sestava zůstává dostupná i po přepnutí na účetnictví.

Systém úpravu základu daně **neprovádí ani nezaúčtovává automaticky** — jde o
jednorázový zásah do daňového přiznání, ne o účetní zápis, který by šlo za
uživatele bezpečně odhadnout. Připraví ale **podklady**: seznam neuhrazených
vydaných a přijatých faktur, poskytnutých a přijatých záloh k zadanému datu s jejich součtem v Kč, dostupný na
vyžádání (např. přes podporu, nebo přímo pro technicky zdatnějšího uživatele):

```
GET /api/tax-evidence/transition-report?as_of=2026-12-31
GET /api/tax-evidence/transition-report?as_of=2026-12-31&direction=accounting_to_tax
```

Parametr `as_of` je datum, ke kterému se sestavují podklady (typicky poslední
den původního režimu). `direction` určuje směr: výchozí `tax_to_accounting`
počítá podle přílohy č. 3 ZDP, `accounting_to_tax` podle přílohy č. 2 ZDP.
Ve směru zpět závazky základ daně zvyšují, pohledávky, zásoby a ceniny ho snižují.
Odpověď obsahuje:

| Klíč | Význam |
|---|---|
| `receivables` | Seznam neuhrazených vydaných faktur k `as_of` — doklad, protistrana, měna, částka nativně i přepočtená na Kč. |
| `payables` | Totéž pro neuhrazené přijaté faktury. |
| `totals.receivables_czk` / `totals.payables_czk` | Součty v Kč napříč měnami (přepočet kurzem zafixovaným na dokladu). |
| `totals.net_adjustment_czk` | Čistý dopad na základ daně se znaménky podle zvoleného směru, včetně záloh a dostupného ocenění zásob. |
| `inventory` | Pokud firma **nemá zapnutý modul Sklad**, obsahuje jen poznámku, že hodnotu zásob je nutné doplnit ručně. Pokud sklad zapnutý má, obsahuje automatické ocenění zásob k datu přechodu metodou váženého průměru — před použitím v přiznání ho porovnej s fyzickou inventurou. |

Při změně mezi skutečnými výdaji a výdajovým paušálem aplikace vytvoří blokující
upozornění na úpravu podle § 23 odst. 8. Přechodová sestava připraví podklady, ale
současná obrazovka neumí potvrdit, že byla právní úprava vyřešena, ani ji propsat do
DPFO. V takovém roce nelze spoléhat na automatickou finalizaci; úpravu sestav s
daňovým poradcem a dokonči v EPO.

> [!WARNING]
> Sestava je **jen podklad**. Zanesení úpravy do daňového přiznání (a její
> případné zaokrouhlení či rozpad na řádky přiznání) dělá účetní ručně — systém
> úpravu nikam sám nezaúčtuje ani ji nepropisuje do přiznání k dani z příjmů
> (viz [Daň z příjmů](38_Dan_z_prijmu.md)).

> [!TIP]
> Sestavu má smysl spustit s `as_of` nastaveným na **poslední den, kdy firma
> ještě vedla daňovou evidenci** — obvykle 31. 12. předchozího roku, pokud
> přechod platí od 1. 1. Pokud se datum uložení přepnutí v aplikaci liší od
> okamžiku, ke kterému se přiznání skutečně vztahuje, zadej do `as_of` to
> správné rozvahové datum, ne den, kdy jsi nastavení uložil(a).

### 90.10.3 Průvodce aktivací podvojného účetnictví

Pokud má firma v daňové evidenci už doklady, po volbě **Podvojné účetnictví**
v nastavení pokračuje admin v pětikrokovém průvodci. Samotná volba režim ještě
nezapne:

1. **Datum zahájení** — určuje první den podvojného účetnictví. Starší doklady
   se jednotlivě nedoúčtují; jejich zůstatky patří do otevírací rozvahy.
2. **Otevírací rozvaha** — lze ji předvyplnit z podkladů daňové evidence a potom
   ručně upravit. Součet MD a D musí být shodný; protistranu účtu 701 doplní systém.
   Firma bez počátečních stavů může krok přeskočit.
3. **Kontrola** — projde faktury, pokladní doklady a bankovní transakce bez zápisu.
   Vedle vyrovnanosti deníku nezávisle porovná počet postovatelných vydaných a
   přijatých dokladů s počtem zpracovaných položek. Tím zachytí i chybějící
   samostatně vyrovnaný zápis, který by samotná kontrola MD = D neodhalila. U
   každé přeskočené položky zobrazí srozumitelný důvod. Přijaté zálohové výzvy
   mezi doklady čekající na účetní předpis nezařazuje; účtuje se až jejich úhrada
   proti účtu 314. U problematických faktur protokol zobrazí číslo, datum, důvod
   a odkaz přímo na detail dokladu. Pokračovat lze jen bez chyb, s úplným pokrytím
   dokladů a s vyrovnaným deníkem.
4. **Spuštění** — po výslovném potvrzení proběhne doúčtování na pozadí. Průvodce
   ukazuje aktuální fázi, průběh i protokol. Zamčená ani uzavřená období se nikdy
   neotevírají ani neobcházejí. Po předpisech dokladů zaúčtuje jednoznačně
   spárované bankovní úhrady; při aktivaci je nezastaví běžné nastavení automatiky
   „jen navrhovat“. Nakonec znovu přepočítá vyúčtovací doklady navázané na zálohy,
   aby doplnil zúčtování 324/311 nebo 321/314. Nespárované pohyby bez bezpečné
   vazby na doklad se automaticky nezaúčtují.
5. **Protokol** — po úspěchu se teprve zapne podvojné účetnictví a zůstane uložený
   přehled zpracovaných, přeskočených a chybných položek.

> [!NOTE]
> Průvodce automaticky nezakládá karty majetku ani neúčtuje odpisy. Přijatou
> fakturu označenou jako dlouhodobý majetek zaúčtuje na účet pořízení (typicky
> 042/321). Majetek pořízený před datem přechodu se založí jako historická karta
> a jeho pořizovací cena i oprávky patří do otevírací rozvahy; majetek pořízený
> po datu přechodu se následně zařadí do užívání a odpisy se zaúčtují v modulu
> **Účetnictví → Majetek**.

Pod průvodcem je stránkovaná historie všech kontrol a ostrých spuštění. Výběrem
staršího běhu lze znovu zobrazit jeho uložený protokol; dlouhá historie se proto
nikdy tiše neořízne jen na poslední záznamy.

Průvodce je bezpečně opakovatelný: nové spuštění už zaúčtované doklady nezdvojí.
Při přerušení nebo chybě zůstane původní účetní režim aktivní a průvodce nabídne
opravu a opakování. Také po dokončené aktivaci nabízí tlačítko **Doúčtovat
chybějící zápisy**. Jedním potvrzením znovu projde zbývající kandidáty, zaúčtuje
jen jednoznačné položky v otevřených obdobích a doplní zúčtování záloh; existující
zápisy díky idempotenci nezdvojí. Po dokončení lze položku **Aktivace a doúčtování**
tlačítkem **Skrýt z menu** odstranit z vlastní navigace; přímá adresa průvodce zůstává
dostupná.

> [!IMPORTANT]
> Otevírací rozvaha řeší účetní počáteční stavy. Nenahrazuje jednorázovou úpravu
> základu daně podle přílohy č. 3 ZDP popsanou výše; tu musí účetní posoudit a
> zanést do daňového přiznání samostatně.

## 90.11 Omezení a tipy

- Sestavy jsou z většiny **read-only** — v peněžním deníku ani v přehledu
  pohledávek a závazků needituješ částky ani doklady; opravu zařazení uděláš na
  zdrojovém dokladu (účel pokladního dokladu, příznak uznatelnosti/osvobození
  na faktuře) nebo doplněním čísla účtu v nastavení u nespárovaných bankovních
  výpisů. Výjimka je řádek **ruční zařazení** (`Zařadit…`) u bankovních/
  pokladních pohybů bez navázaného dokladu ([§ 90.6](#906-nezarazene-pohyby-a-varovani))
  — tam kategorii nastavíš přímo v deníku, přepsat lze i zpět přes **Zrušit
  ruční zařazení**.
- **Blokující upozornění nezmizí samo** — dokud zůstane nezařazený příchozí
  bankovní pohyb, systém ho bude v deníku i nadále vypisovat jako riziko
  podhodnocení základu daně.
- Přehled pohledávek a závazků počítá **nativně po měnách** — pokud potřebuješ
  jedno číslo v CZK napříč měnami, použij filtr měny a sečti ručně, sestava sama
  měny nesčítá.
- Kontrola vůči přiznanému příjmu je **informativní**, ne validace — odchylka
  kasové a fakturační báze je u daňové evidence očekávaná.
- Ruční klasifikace celého pohybu bez navázaného dokladu nezná řádkový rozpad DPH.
  Pokud vazba na fakturu existuje, oprav přednostně zdrojový doklad; ruční zařazení
  není náhradou správného DPH režimu.
- Označení DPH/KH jako skutečně odeslaného může posunout zámek daňových období.
  Pokus změnit rozhodný daňový doklad v zamčeném období může být odmítnut. Pouhé
  stažení XML zámek nevytváří; potvrzení z portálu vždy archivuj samostatně.
- Přechodová sestava, kontrolní seznam uzávěrky ani XSD validace nejsou právním
  posouzením. Zvláštní zahraniční operace, nestandardní zápočty, rezervy a opravné
  položky dokonči ručně.

> [!TIP]
> Pokud sestava dlouhodobě hlásí nenulové **Nezařazeno**, projdi si nejdřív
> odchozí i příchozí bankovní pohyby bez vazby na doklad — u opakujících se
> položek (např. bankovní poplatky, které heuristika nerozpoznala) je často
> rychlejší doplnit popis platby přímo v bance, než pohyb řešit ručně každý
> měsíc znovu.
