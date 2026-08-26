# 34. E-shop

**Cesta: `Zboží → E-shop`** *(poslední položka sekce Zboží, viditelná jen když je
v [Nastavení](92_Nastaveni.md) zapnutý modul **Sklad**)*

Modul **E-shop** rozšiřuje skladovou kartu zboží (`Zboží → Skladové karty`) o vše,
co potřebuješ pro **prodej přes e-shop**: vícejazyčný popis a SEO, zařazení do
kategorií a označení štítky, typované parametry/atributy, poplatky (autorský,
recyklační…), cenotvorbu odvozenou z nákupní ceny ve více měnách, dodavatele
zboží a hromadný import. Stránka `/eshop` sama o sobě **needituje jednotlivé
zboží** — je to sada **číselníků a nastavení**, které pak využiješ na kartě
konkrétní položky v editoru skladové karty (záložky „Jazyky", „Kategorie &
štítky", „Parametry", „Ceny", „Dodavatelé", „Přílohy").

Kapitola má dvě části: **§ 34.1–34.7** popisují číselníky a import na stránce
`/eshop`, **§ 34.8–34.10** pak cenotvorbu a dodavatele, které se zadávají přímo
na kartě zboží. Číselník jazyků je popsaný samostatně v
[§ 34.13](#3413-jazyky). Pokud tě zajímá jen nacenění katalogu, začni
[§ 34.8](#348-cenotvorba).

> [!NOTE]
> **Karta zboží nemusí mít skladový stav.** Pokud u položky vypneš příznak
> „Skladová položka" (`is_stocked = 0`), karta funguje bez jediné příjemky —
> jen se nacení a popíše, prodává se přes dodavatele (dropshipping) a skladové
> množství se nesleduje. To je hlavní důvod, proč e-shopová prezentace žije
> jako nadstavba nad skladem, ne jako podmínka „napřed naskladni".

> [!IMPORTANT]
> **Kolik smí e-shop nabídnout: skladem mínus rezervováno.** U skladové položky
> se pro e-shop nepočítá holý fyzický stav, ale **prodejné množství** =
> *skladem − rezervováno*, kde rezervované je zboží už vyfakturované zákazníkovi
> a dosud nevydané ze skladu. Díky tomu se stejný kus neprodá dvakrát.
> **Zboží na cestě** (objednané u dodavatele, ještě nedodané) se do nabídky
> **záměrně nepromítá.** Podrobně
> [§ 33.9 Kolik toho vlastně máš](33_Sklad.md#339-kolik-toho-vlastne-mas-skladem-rezervovano-na-ceste-u-dodavatele).

## 34.1 Přehled záložek

Stránka `/eshop` má nahoře vodorovné taby, mezi kterými se přepínáš (stav
tabu se ukládá do URL, takže jde odkázat i naback/refresh):

| Záložka | Obsah |
|---|---|
| **Výrobci** | Číselník výrobců/značek zboží |
| **Kategorie** | Strom kategorií e-shopového katalogu |
| **Atributy** | Typované parametry zboží (barva, rozměr, výkon…) vč. voleb pro výběrové atributy |
| **Tagy** | Barevné štítky zboží |
| **Poplatky** | Typy poplatků (autorský, recyklační/PHE…) s vlastní sazbou DPH |
| **Jazyky** | Jazykové mutace, ve kterých vedeš názvy a popisy zboží a kategorií ([§ 34.13](#3413-jazyky)) |
| **Sklady** | Stejná záložka jako `Zboží → Skladové karty → Sklady` — sklady patří oběma pohledům |
| **Import zboží** | Hromadný import/aktualizace karet z XLSX/CSV |

Každý číselník (Výrobci, Kategorie, Atributy, Tagy, Poplatky, Jazyky) má stejný tvar:
tabulka existujících záznamů, tlačítko **„Nový…"** vpravo nahoře a u každého
řádku ikony **tužky** (upravit) a **koše** (smazat). Editace i mazání jsou
dostupné jen uživatelům s právem zápisu — u readonly uživatele akční sloupec
zmizí úplně.

## 34.2 Výrobci

Jednoduchý číselník značek: **Kód**, **Název**, **Web** (odkaz, otevře se v
novém okně), **Pořadí** (řadí výpis v e-shopu), příznak **Exportovat** (zda se
výrobce má zobrazit na e-shopu) a **Aktivní/Neaktivní**. Kód musí být v rámci
firmy jedinečný.

Výrobce následně přiřadíš konkrétní kartě zboží v poli „Výrobce" na záložce
„Obecné" editoru skladové karty. Import zboží umí výrobce **přiřadit podle
kódu**, ale **nezakládá je** — pokud kód v souboru neexistuje mezi výrobci,
řádek importu skončí chybou s instrukcí založit výrobce nejdřív ručně zde.

## 34.3 Kategorie

Kategorie tvoří **strom** (kategorie může mít nadřazenou kategorii, ta svou
vlastní atd.) — v tabulce se odsazují podle hloubky a mají ikonu šipky u
podkategorií. Formulář obsahuje:

- **Kód**, **Název**,
- **Nadřazená kategorie** — vyhledávací select se seznamem existujících
  kategorií (odsazeno podle úrovně); prázdné = kategorie první úrovně,
- **Pořadí** zobrazení,
- příznak **Exportovat** (viditelnost na e-shopu) a **Aktivní**.

Sloupec **Cesta** v tabulce ukazuje interní materializovanou cestu stromem
(`/12/45/`) — slouží k rychlému dohledání podstromu, uživatelsky důležitý je
hlavně vizuální odsazený název.

### 34.3.1 Přesun kategorie

Tlačítko se šipkami u řádku otevře dialog **„Přesunout kategorii"**, kde
vybereš novou nadřazenou kategorii (nebo necháš prázdné pro přesun na
nejvyšší úroveň). Nabídka **vylučuje samotnou kategorii a celý její
podstrom** — nelze tedy kategorii zacyklit (udělat z ní vlastního potomka).
Přesun rovnou přepočítá cestu/hloubku pro celý přesouvaný podstrom.

Kategorie s podřízeným zbožím nebo podkategoriemi nelze smazat — systém
nabídne archivaci místo mazání (viz [§ 34.11](#3411-mazani-vs-archivace)).

Konkrétní kartě zboží přiřadíš jednu i více kategorií na záložce „Kategorie &
štítky" editoru skladové karty, kde navíc označíš jednu jako **hlavní**
(pro drobečkovou navigaci a kanonickou URL na e-shopu).

## 34.4 Atributy (parametry)

Atributy jsou **typované parametry zboží** — na rozdíl od volného textu mají
přesně daný datový typ, takže je lze na e-shopu i filtrovat. Formulář nového
atributu obsahuje:

- **Kód** a **Název** (např. „barva", „Barva"),
- **Datový typ**: `Text`, `Number`, `Boolean`, nebo `Enum (Volby)` — u posledního
  se atribut vybírá z předem definovaného seznamu hodnot,
- **Měrná jednotka** (nepovinná, např. „kg", „cm", „ks" — smysl dává hlavně u
  `Number`),
- **Pořadí** zobrazení,
- **Filtrovatelný** — příznak pro budoucí facetové filtrování na e-shopu,
- **Vícehodnotový** — povolí u karty zboží přiřadit atributu víc hodnot najednou
  (typicky u `Enum`, např. „dostupné velikosti"),
- **Aktivní/Neaktivní**.

### 34.4.1 Volby atributu (jen typ Enum)

V modálu úpravy atributu je pod základním formulářem sekce **„Možnosti/Volby
atributu"** — tabulka voleb (Kód, Popisek, Pořadí) a formulář pro přidání
nové volby. Chování se liší podle toho, zda atribut zakládáš, nebo upravuješ:

- **Při zakládání** nového atributu se volby jen **bufferují lokálně** (ještě
  nemají server ID) a **odešlou se na server až po uložení atributu** —
  postupně, ve frontě; pokud část requestů selže, zbytek fronty přežije i po
  opravě a opětovném uložení.
- **Při editaci** existujícího atributu se volby ukládají/mažou **rovnou** přes
  API (atribut už ID má).

Každá volba má svůj **Kód** (technický, používá se i při párování importu/
API) a **Popisek** (zobrazovaný text, typicky se z něj kód automaticky
odvozuje jako slug — lze ho ale ručně přepsat).

Konkrétní hodnoty atributů (typované vstupy dle datového typu) se zadávají na
záložce „Parametry" editoru skladové karty pro každou kartu zvlášť.

## 34.5 Tagy

Tagy jsou volné barevné štítky zboží (např. „Novinka", „Výprodej", „TOP
prodej") — na rozdíl od kategorií a atributů nejsou hierarchické ani typované,
slouží jen k vizuálnímu odlišení a filtrování na e-shopu. Formulář: **Kód**,
**Název**, **Barva** (textové pole ve formátu `#RRGGBB` + barevný picker vedle
něj pro pohodlný výběr) a **Aktivní**. V tabulce vidíš barevný čtvereček u
každého tagu, aby bylo na první pohled jasné, jak bude vypadat na e-shopu.

Konkrétní kartě zboží přiřadíš libovolný počet tagů na záložce „Kategorie &
štítky" editoru skladové karty.

## 34.6 Poplatky

Poplatky reprezentují dodatečné zákonné příplatky ke zboží — typicky
**autorský poplatek** nebo **recyklační poplatek (PHE)** za elektrozařízení.
Formulář: **Kód** (interní, např. `copyright`, `recycling`), **Název**,
**Sazba DPH** (výběr z číselníku sazeb DPH, nebo „Bez DPH" — poplatek tak může
mít jinou sazbu než samotné zboží) a **Aktivní**.

Konkrétnímu zboží pak přiřadíš konkrétní **částku** poplatku (v dané měně, s
příznakem, zda je částka „s DPH") přímo na kartě zboží. Poplatek s
navázaným zbožím nelze smazat, jen archivovat.

> [!TIP]
> Recyklační poplatek (PHE) je u elektrozařízení a baterií ze zákona povinný
> a musí být na e-shopu **viditelně uveden odděleně od ceny zboží** — proto je
> namodelovaný jako samostatný typ poplatku s vlastním režimem DPH, ne jako
> součást prodejní ceny.

## 34.7 Import zboží

Záložka **„Import zboží"** umožní hromadně **založit nové i aktualizovat
existující** skladové karty ze souboru **XLSX nebo CSV** (max 2 MB).

### 34.7.1 Postup

1. **Přetáhni soubor** do vyznačené plochy, nebo klikni a vyber ho ručně.
2. Zaškrtávátko **„Jen náhled (dry-run) — nic se neuloží"** je při prvním
   nahrání zapnuté — doporučený postup je vždy nejdřív spustit **náhled**.
3. Tlačítko se podle stavu přepínače jmenuje **„Zobrazit náhled"** nebo rovnou
   **„Importovat"**.
4. Po náhledu se zobrazí **report** — souhrn (počet nových / změn / beze
   změny / chyb) a řádková tabulka s detailem každého řádku souboru.
5. Pokud náhled **neobsahuje žádnou chybu**, objeví se tlačítko **„Potvrdit
   import"**, které provede tentýž soubor **naostro** bez nutnosti ho nahrávat
   znovu.
6. Filtr **„Jen problémy"** nad tabulkou zobrazí jen řádky se stavem chyba
   nebo s doprovodnou zprávou.

### 34.7.2 Sloupce souboru

Povinný je jen **`sku`** — slouží jako **identita řádku** (podle něj se pozná,
jde-li o nové zboží, nebo aktualizaci existujícího). Přijímají se české i
anglické varianty názvů sloupců (např. `nazev`/`name`, `vyrobce`/`manufacturer`/
`znacka`/`brand`), pořadí sloupců není podstatné — párují se podle hlavičky.

| Sloupec | Význam |
|---|---|
| `sku` | Katalogové číslo — povinné, max 50 znaků, musí být v souboru jedinečné (i bez ohledu na velikost písmen) |
| `nazev` | Název zboží — povinný jen při **zakládání** nové karty |
| `jednotka` | Měrná jednotka (default `ks`, pokud sloupec chybí) |
| `ean` | Čárový kód |
| `cena` | Prodejní cena bez DPH — přijímá český i anglický formát čísla (`1 234,50` i `1234.50`) |
| `vyrobce` | **Kód existujícího** výrobce — pokud neexistuje, řádek skončí chybou (výrobce se importem nezakládá, založ ho nejdřív v číselníku Výrobci) |
| `skladem` | `1`/`0` (nebo `ano`/`ne`) — je karta skladová položka, nebo se prodává jen přes dodavatele |
| `export_eshop` | `1`/`0` — má se karta zobrazit na e-shopu |
| `hmotnost_g` | Hmotnost v gramech (celé číslo, pro výpočet dopravy) |
| `zaruka_mesice` | Záruka v měsících |
| `dodaci_lhuta_dny` | Dodací lhůta ve dnech |

### 34.7.3 Chování importu

- **Nikdy nemaže** — import zboží ani nesmaže existující kartu, ani z ní
  neodstraní hodnotu, která v souboru chybí (aktualizuje se **jen sloupec,
  který je v souboru skutečně vyplněný**).
- Řádky se stavem **„Beze změny"** se přeskočí (import je idempotentní —
  opakované nahrání téhož souboru nic nezmění).
- Řádek se stavem **„Nový"** založí kartu jako `zboží` (item_type `goods`).
- Řádek se stavem **„Chyba"** (např. chybějící povinné pole, duplicitní SKU v
  souboru, neplatná cena, neexistující kód výrobce, hodnota mimo povolený
  rozsah) se **nezapíše** a v detailu vidíš konkrétní důvod.
- Ostrý import je **all-or-nothing v rámci dávky** — pokud náhled hlásí
  chyby, „Potvrdit import" se nenabídne a musíš soubor nejdřív opravit.

> [!WARNING]
> Sloupec `vyrobce` **nezakládá nové výrobce** — očekává kód už existujícího
> záznamu z číselníku Výrobci ([§ 34.2](#342-vyrobci)). Připrav si tedy
> číselník výrobců dřív, než spustíš import velkého katalogu.

## 34.8 Cenotvorba

**Cesta: `Zboží → Skladové karty → (karta) → záložka „Ceny"`**

Zatímco stránka `/eshop` drží číselníky, samotná **cena zboží** se rodí na kartě
konkrétní položky. Prodejní cena přitom není hodnota, kterou prostě zadáš — je
to **výsledek výpočtu**, který systém přepočítává z nákupní ceny.

> [!IMPORTANT]
> **MyÚčto má dva oddělené cenové subsystémy a nemíchají se.** Jednoduchý
> **Ceník** ([§ 92.1.5](92_Nastaveni.md)) je určený pro fakturaci služeb a umí
> ceny per zákazník. **Sklad + E-shop** má vlastní cenotvorbu odvozenou z
> nákupní ceny, popsanou zde. Po zapnutí modulu Sklad **Ceník z menu zmizí** —
> ceny se mezi nimi nepřenášejí.

### 34.8.1 Jak cena vzniká

Řetěz má pět kroků:

| # | Krok | Kde se nastavuje |
|---|---|---|
| 1 | **Nákupní cena v CZK** (nákladová báze) | pole „Cenová báze" na záložce Obecné + skladové pohyby / dodavatelé |
| 2 | **Přirážka %** nebo **fixní cena** | záložka Ceny, sloupce „Režim" a „Přirážka % / Fixní cena" |
| 3 | **Přepočet do cílové měny** kurzem | automaticky z kurzovního lístku |
| 4 | **Zaokrouhlení** | záložka Ceny, sloupec „Zaokrouhlení" |
| 5 | **Výsledná cena** (bez DPH) | sloupec „Výsledná cena" — jen ke čtení |

```
nákupní cena (CZK)
        │
        ├── režim Přirážka % ──→ × (1 + přirážka/100) ──→ ÷ kurz (cizí měna)
        │                                                        │
        └── režim Fixní cena ────────────────────────────────────┤
                                                                 ▼
                                                         zaokrouhlení
                                                                 │
                                                                 ▼
                                                    výsledná cena bez DPH
```

Celý výpočet běží v **celočíselné haléřové aritmetice** (žádná desetinná čísla
s plovoucí čárkou), takže se v cenách nekumulují zaokrouhlovací chyby.

### 34.8.2 Nákupní cena — cenová báze

Pole **„Cenová báze"** na záložce „Obecné" určuje, **odkud systém vezme nákupní
cenu**, ze které se počítá přirážka:

| Cenová báze | Odkud bere nákupní cenu | Kdy ji použít |
|---|---|---|
| **Vážený průměr** | Průměrná pořizovací cena skladových zásob (`Σ hodnota ÷ Σ množství` **napříč všemi sklady**) | Výchozí volba pro běžné skladové zboží — cena se plynule přizpůsobuje nákupům |
| **Poslední nákup** | Jednotková cena z **poslední zaúčtované příjemky** | Když se nákupní ceny rychle mění a chceš marži počítat z aktuální hladiny, ne z historického průměru |
| **Ruční** | **Nákupní cena preferovaného dodavatele** ze záložky Dodavatelé | Zboží bez skladu (dropshipping) nebo když se řídíš ceníkem dodavatele, ne skutečnými nákupy |

Podrobnosti o tom, jak se vážený průměr počítá při příjmu a výdeji, jsou
v [§ 33.3 Oceňování zásob](33_Sklad.md).

Pokud zvolený zdroj nákupní cenu **nevrátí** (např. „Vážený průměr" u karty bez
jediné příjemky), systém zkusí náhradní zdroje v pořadí:

**zvolená báze → poslední nákup → preferovaný dodavatel**

Teprve když selžou všechny tři, zůstane prodejní cena prázdná (`—`). Nákupní
cena **nula nebo záporná se nepočítá jako platná** — přirážka z nulového nákladu
nedává smysl, takže se pokračuje dalším zdrojem v řetězu.

> [!NOTE]
> Díky záložnímu řetězu funguje karta se „skladovou" bází i **před první
> příjemkou** — nacení se podle dodavatele a jakmile naskladníš, přepne se
> automaticky na skutečná skladová data.

### 34.8.3 Přirážka vs. marže — nepleť si je

Systém pracuje s **přirážkou** (*markup*), ne s marží. Rozdíl je zásadní a je
nejčastějším zdrojem zklamání z výsledné ziskovosti:

- **Přirážka %** = kolik procent **nákupní ceny** přidáváš.
  `přirážka = (prodej − nákup) ÷ nákup × 100`
- **Marže %** = jaký podíl **prodejní ceny** ti zůstane.
  `marže = (prodej − nákup) ÷ prodej × 100`

Přirážka 30 % tedy **neznamená** marži 30 %. Nákup 1 000 Kč + 30 % přirážky =
prodej 1 300 Kč, hrubý zisk 300 Kč, ale marže je `300 ÷ 1 300 = 23,1 %`.

| Přirážka % (zadáváš) | Marže % (dostaneš) | | Chceš marži % | Zadej přirážku % |
|---:|---:|---|---:|---:|
| 10 | 9,1 | | 10 | 11,11 |
| 20 | 16,7 | | 15 | 17,65 |
| 25 | 20,0 | | 20 | 25,00 |
| 30 | 23,1 | | 25 | 33,33 |
| 40 | 28,6 | | 30 | 42,86 |
| 50 | 33,3 | | 40 | 66,67 |
| 100 | 50,0 | | 50 | 100,00 |

Vzorce pro přepočet:

- `marže = přirážka ÷ (100 + přirážka) × 100`
- `přirážka = marže ÷ (100 − marže) × 100`

> [!TIP]
> Když ti dodavatel nebo konkurence mluví o „rabatu", myslí zpravidla **slevu z
> doporučené ceny**, tedy ještě třetí veličinu. Než si nastavíš přirážky napříč
> katalogem, ujasni si, které z těch tří čísel vlastně máš — u velkých katalogů
> je omyl v tomhle bodě dražší než cokoli jiného.

> [!WARNING]
> **Marži systém nikde nepočítá ani nereportuje** — ukládá se jen zadaná
> přirážka. Pokud chceš hlídat skutečnou ziskovost, musíš si ji spočítat sám z
> nákupní a prodejní ceny (viz [§ 34.10.4](#34104-kontrola-marze)).

### 34.8.4 Záložka „Ceny"

Karta může mít **libovolný počet cenových řádků — jeden na měnu**. Tlačítkem
**„Přidat měnu"** přidáš řádek, ikonou koše ho odebereš.

| Sloupec | Význam |
|---|---|
| **Měna** | Kód měny podle ISO 4217 (3 písmena, např. `CZK`, `EUR`) — jedinečný v rámci karty |
| **Režim** | **Přirážka %** (dopočet z nákladové báze) nebo **Fixní cena** (pevná částka) |
| **Přirážka % / Fixní cena** | Hodnota podle zvoleného režimu — pole se přepíná automaticky |
| **Zaokrouhlení** | Bez / na haléře / desetihaléře / půlkoruny / koruny / na 9 na konci ([§ 34.8.5](#3485-zaokrouhleni)) |
| **Ruční** | Zafixuje cenu — přepočet ji nepřepíše |
| **Výsledná cena** | Dopočtená cena **bez DPH** — jen ke čtení |
| **Kurz** | Kurz použitý při posledním přepočtu (u CZK prázdný) |

Tlačítko **„Přepočítat"** nejdřív uloží aktuální nastavení řádků a **teprve pak
spustí výpočet** — nemusíš tedy ukládat zvlášť.

> [!IMPORTANT]
> **Řádek v CZK má zvláštní postavení: zrcadlí se do prodejní ceny skladové
> karty**, a tím i do výchozí ceny na řádku faktury. Ostatní měny slouží jen
> e-shopové prezentaci a do fakturace nevstupují. Když kartě CZK řádek
> **nezaložíš**, prodejní cena karty zůstane na té hodnotě, kterou jsi zadal
> ručně (nebo naimportoval) — cenotvorba ji nebude aktualizovat.

**Ruční přepis** vyřadí řádek z automatického dopočtu a chová se dvěma způsoby
podle toho, co je v hodnotovém poli:

- **„Ruční" + režim Fixní cena + zadaná částka** → cena je přesně tato částka
  (jen se zaokrouhlí dle nastavení). Tohle je způsob, jak zboží nacenit napevno.
- **„Ruční" + režim Přirážka %** → cena **zamrzne na poslední dopočtené
  hodnotě** a přestane reagovat na změny nákupní ceny i kurzu.

Odškrtnutím „Ruční" se řádek vrátí do automatického režimu a nejbližší přepočet
cenu přepíše.

Sloupec „Výsledná cena" zůstane prázdný (`—`) ve dvou situacích:

| Příčina | Co s tím |
|---|---|
| **Chybí nákupní cena** — žádný zdroj v záložním řetězu nic nevrátil | Naskladni příjemkou, nebo doplň preferovaného dodavatele s nákupní cenou |
| **Chybí kurz** — v kurzovním lístku není kurz dané měny | Doplň kurz ([§ 34.8.6](#3486-cizi-meny-a-kurzy)) a spusť přepočet |

### 34.8.5 Zaokrouhlení

Zaokrouhluje se **až úplně nakonec**, na výslednou cenu bez DPH, matematicky
(půlka nahoru). Pro dopočtenou cenu **1 035,7335 Kč** dopadnou režimy takto:

| Režim | Výsledek | Poznámka |
|---|---:|---|
| **Bez** | 1 035,73 | Jen normalizace na haléře |
| **Na haléře (0,01)** | 1 035,73 | Totožné s „Bez" |
| **Na desetihaléře (0,10)** | 1 035,70 | |
| **Na půlkoruny (0,50)** | 1 035,50 | |
| **Na koruny (1)** | 1 036,00 | Nejčastější volba pro běžný retail |
| **Na 9 na konci** | 1 039,00 | Psychologická cena — nejbližší celá koruna končící devítkou |

Režim **„Na 9 na konci"** zaokrouhlí nejdřív na celé koruny a pak vybere
nejbližší číslo končící devítkou; při stejné vzdálenosti volí **nahoru**.
Nejnižší cena, kterou tenhle režim vytvoří, je 9 Kč.

> [!TIP]
> Zaokrouhlení nastavuj **per měnu**. „Na 9 na konci" dává skvělý smysl u
> korunových cen, ale u eurových řádků často vyrobí zbytečně hrubý skok —
> tam bývá vhodnější „Na haléře" nebo „Na desetihaléře".

### 34.8.6 Cizí měny a kurzy

U řádku v cizí měně systém převede **nákupní cenu v CZK** kurzem do cílové měny
a **až pak** aplikuje přirážku a zaokrouhlení.

**Příklad:** nákupní cena 812,34 Kč, přirážka 27,5 %, kurz EUR 25,30 Kč:

```
812,34 ÷ 25,30 = 32,108300 EUR   (nákup v EUR)
32,108300 × 1,275 = 40,938082    (+ přirážka 27,5 %)
zaokrouhlení „Na haléře"    →    40,94 EUR
```

Marže tedy zůstává stejná jako v CZK, ale **eurová cena plave s kurzem** — při
posílení koruny sama klesá.

> [!IMPORTANT]
> **Kurz se bere výhradně z kurzovního lístku v databázi**, nikdy se nestahuje
> živě z ČNB — přepočet ceny nesmí záviset na dostupnosti cizí služby. Použije
> se **nejbližší kurz s datem ≤ dnešek**. Když pro danou měnu není v lístku
> žádný kurz, cena se **nedopočte vůbec** (zůstane prázdná) — cena se nikdy
> nespočítá z odhadnutého nebo nulového kurzu.

### 34.8.7 Kdy se cena přepočítá

Přepočet **není v databázi ani na časovači** — spouští ho aplikace v těchto
okamžicích:

| Událost | Přepočet |
|---|:---:|
| Uložení cenových řádků (záložka Ceny) | ✅ automaticky |
| Kliknutí na **„Přepočítat"** | ✅ vynuceně |
| Uložení dodavatelů (záložka Dodavatelé) | ✅ automaticky |
| Uložení karty zboží | ✅ automaticky |
| **Zaúčtování příjemky** (změní vážený průměr) | ❌ **ne** |
| **Import nových kurzů** | ❌ **ne** |
| Hromadné přecenění katalogu | ❌ nedostupné |

> [!WARNING]
> **Tohle je nejdůležitější provozní úskalí celé cenotvorby.** Zaúčtování
> příjemky změní váženou průměrnou nákupní cenu, ale **prodejní ceny odvozené
> přirážkou se samy nepřepočtou** — zůstanou na hodnotě z posledního přepočtu.
> Totéž platí po aktualizaci kurzů u cen v cizí měně. Po naskladnění za jinou
> nákupní cenu (a po výraznějším pohybu kurzu) je potřeba **projít dotčené
> karty a kliknout na „Přepočítat"**. Hromadné přecenění v aplikaci není,
> takže se to dělá kartu po kartě.

> [!NOTE]
> **Akčních cen** ([§ 34.8.9](#3489-akcni-ceny)) se přepočet netýká — je to
> zadaná částka, ne odvozená hodnota. Jejich platnost naopak řídí čas a počet
> kusů automaticky, bez jakéhokoli přepočtu.

### 34.8.8 Co cena obsahuje — DPH a poplatky

- Všechny ceny v cenotvorbě jsou **bez DPH**. Sazbu DPH má karta zvlášť
  (pole na záložce Obecné) a připočítává se až na dokladu.
- **Poplatky** (recyklační/PHE, autorský — [§ 34.6](#346-poplatky))
  **nejsou součástí prodejní ceny**. Vedou se jako samostatné částky s vlastní
  sazbou DPH, protože zákon vyžaduje jejich oddělené uvedení. Do přirážky ani
  do zaokrouhlení nevstupují.
- **Sleva** na kartě zboží se dělá **akční cenou** ([§ 34.8.9](#3489-akcni-ceny)).
  Kromě toho existuje ještě procentní **sleva na úrovni dokladu** (na faktuře),
  která se počítá až z ceny, kterou akce vrátí.

### 34.8.9 Akční ceny

**Cesta: `Zboží → Skladové karty → (karta) → záložka „Ceny" → sekce „Akční ceny"`**

Akční cena je **dočasná sleva položená nad standardní cenou**. Standardní
cenotvorba (přirážka, přepočet měn, zaokrouhlení) běží dál beze změny — akce
jen po dobu své platnosti přebije výsledek. Jakmile akce skončí, cena se sama
vrátí na standardní hladinu; **není potřeba nic ručně vracet**.

Akce má tři omezení a **každé z nich je nepovinné**:

| Omezení | Pole | Prázdné znamená |
|---|---|---|
| **Časové okno** | „Platí od" / „Platí do" | bez omezení (i jen jedna strana) |
| **Počet kusů** | „Počet kusů" (+ číslo u volby *Omezený počet*) | výchozí je *Do vyprodání zásob* |
| **Akční cena** | „Akční cena" | povinná — to je jádro akce |

#### Tři režimy množstevního stropu

**1. Do vyprodání zásob** *(výchozí)* — akce platí, dokud je zboží skladem, a
nejvýš na tolik kusů, kolik je právě na skladě. Strop se **neodečítá**: čte se
živě ze skladu, takže **doskladněním akci znovu „nabiješ"**.

> *Příklad:* prodáváš termosku za 890 Kč a chceš ji vyprodat za 690 Kč. Na
> skladě je 12 ks. Založíš akci `690` v režimu **Do vyprodání zásob** bez data.
> Prvních 12 ks se prodá za 690 Kč; jakmile stav klesne na nulu, faktury se zase
> nacení na 890 Kč. Když ti dorazí dalších 5 ks, akce se sama znovu rozjede —
> pro trvalé ukončení akci **vypni** (odškrtni „Aktivní") nebo smaž.

**2. Omezený počet kusů** — pevný rozpočet („prvních N kusů"). Prodej ho
odečítá a **doskladnění ho neobnoví**. Aplikace vyčerpané množství **dopočítává
z vystavených faktur** dané karty a měny v období akce; storno, smazání faktury
i dobropis rozpočet zase uvolní.

> *Příklad:* uvádíš novinku a chceš dát **prvních 100 ks za 1 490 Kč** místo
> 1 990 Kč. Založíš akci `1490`, režim **Omezený počet kusů**, počet `100`.
> Sloupec „Zbývá" ti průběžně ukazuje, kolik kusů z rozpočtu zbývá; po stotisícím
> kusu se akce označí jako **Vyčerpaná** a nové faktury se nacení na 1 990 Kč,
> i kdyby na skladě leželo dalších 300 ks.

**3. Bez omezení počtu** — žádný množstevní strop. Hodí se pro akci, kterou
řídíš výhradně kalendářem, nebo pro zboží bez skladové evidence (dropshipping,
`is_stocked = 0`), kde by režim „do vyprodání zásob" znamenal nulu.

> *Příklad:* letní sleva na službu montáže od 1. 7. do 31. 8. Založíš akci
> s cenou `990`, „Platí od" `1. 7.`, „Platí do" `31. 8.` a režim **Bez omezení
> počtu**. Celé dva měsíce se fakturuje 990 Kč bez ohledu na počet zakázek,
> 1. 9. se cena sama vrátí na standardní hladinu.

#### Časové okno

Datum „Platí od" i „Platí do" jsou **včetně** daného dne a obě jsou nepovinná:

- **obě prázdná** — akce platí, dokud ji nevypneš (nebo dokud jí nedojde strop),
- **jen „od"** — akce se sama spustí v zadaný den (do té doby má stav
  **Naplánovaná**),
- **jen „do"** — akce platí hned a v zadaný den večer sama skončí (stav
  **Skončila**).

> [!TIP]
> Kombinace „jen do" + režim **Do vyprodání zásob** je nejběžnější výprodej:
> *„sleva do konce měsíce, nebo do vyprodání zásob — co nastane dřív"*.

#### Několik akcí najednou

Na téže kartě a měně smí platit **víc akcí současně** — sezónní i produktové
kampaně se běžně vrství. Vyhrává **nejnižší platná cena** (při shodě novější
záznam), takže zákazník vždy dostane tu nejlepší. Akce, která by byla **dražší
než standardní cena**, se ignoruje — po snížení běžné ceny tedy stará „akce"
zboží nezdraží.

#### Když strop nestačí na celý řádek

Uplatnění je **vše nebo nic per řádek dokladu**. Když z akce zbývá 3 ks a ty
fakturuješ 5 ks, míchaná jednotková cena by rozbila vztah *cena × množství =
základ* a na faktuře by se nedala vysvětlit. Aplikace proto:

1. zkusí **další akci v pořadí** (dražší akce s volnějším stropem je pořád lepší
   než plná cena),
2. a když ani ta nestačí, nacení celý řádek **standardní cenou**.

Chceš-li v takové situaci prodat část akčně, **rozděl řádek** na dva — 3 ks
s akční cenou a 2 ks se standardní.

#### Stavy akce

Sloupec „Stav" ti u každého řádku řekne, co se s ním právě děje:

| Stav | Význam |
|---|---|
| **Probíhá** | akce se právě uplatňuje |
| **Naplánovaná** | „Platí od" je v budoucnosti |
| **Skončila** | „Platí do" už je v minulosti |
| **Vypnutá** | odškrtnuté „Aktivní" (akce se schovává, ale nemaže) |
| **Vyčerpaná** | došel množstevní strop, nebo zboží není skladem |

> [!NOTE]
> Akční cena je **bez DPH**, stejně jako celá cenotvorba, a platí vždy jen pro
> **jednu měnu** — pro akci v eurech založ druhý řádek s `EUR`. Na rozdíl od
> standardní ceny se akční cena **nepřepočítává** ani kurzem, ani přirážkou;
> je to prostě zadaná částka.

#### Kde všude se akční cena projeví

Akční cena není jen ozdoba karty — aplikace ji dosazuje všude, kde zboží
naceňuje:

- v **seznamu skladových karet** (původní cena přeškrtnutá, akční zeleně),
- na **detailu karty**,
- při **vložení zboží do faktury** — do řádku se předvyplní akční cena a
  aplikace tě na to upozorní hláškou.

## 34.9 Dodavatelé zboží

**Cesta: `Zboží → Skladové karty → (karta) → záložka „Dodavatelé"`**

Ke kartě zboží můžeš přiřadit **libovolný počet dodavatelů** — každého s
vlastními podmínkami. Slouží ke dvěma věcem: jako **podklad pro nákup** a jako
**zdroj nákupní ceny** pro cenovou bázi „Ruční".

| Pole | Význam |
|---|---|
| **Dodavatel** | Výběr z klientů, kteří mají v adresáři zapnutou **roli dodavatele** ([§ 18](18_Klienti.md)) |
| **Kód u dodavatele** | Katalogové číslo, pod kterým položku vede dodavatel (max 80 znaků) — tiskne se na objednávku a páruje se podle něj ceník |
| **Nákupní cena** | Cena, za kterou od něj nakupuješ |
| **Měna** | Měna nákupní ceny (default `CZK`) |
| **Dodání (dny)** | Dodací lhůta — u zboží bez skladu tvoří dostupnost na e-shopu |
| **Skladem (ks)** | Množství, které dodavatel drží — orientační, needituje tvůj sklad; sčítá se do veličiny **U dodavatele** ([§ 33.9.4](33_Sklad.md#3394-u-dodavatele)) |
| **Preferovaný** | Přepínač; **nejvýš jeden dodavatel na kartu** |
| **Poznámka** | Volný text (sezónnost, kontakt…) |

> [!NOTE]
> Tahle záložka je **pohled po kartách** na tatáž data, která stránka
> **Sklad → U dodavatele** ukazuje přes celý katalog — jde o jeden a týž záznam.
> Kompletní sada polí (**minimální odběr**, **balení**, **dostupnost**,
> **cena platí do**, **aktivní**) i **import ceníku** jsou popsané
> v [§ 33.10 Nabídky dodavatelů](33_Sklad.md#3310-nabidky-dodavatelu-u-dodavatele).
> Právě minimální odběr a balení používá návrh
> [doplnění zásob](33_Sklad.md#3312-doplneni-zasob-co-objednat).

### 34.9.1 Preferovaný dodavatel

Preferovaný dodavatel je ten, jehož **nákupní cena se použije při cenové bázi
„Ruční"** a jako poslední článek záložního řetězu ([§ 34.8.2](#3482-nakupni-cena-cenova-baze)).
Je-li jeho cena v cizí měně, převede se do CZK kurzem k danému dni — a
**chybí-li kurz, nákupní cena z něj nepůjde získat**.

> [!NOTE]
> Aby se klient v nabídce dodavatelů vůbec objevil, musí mít v adresáři zapnutý
> **příznak dodavatele**. Pokud je seznam prázdný, nejdřív roli zapni u
> příslušných klientů — na kartě zboží se dodavatel založit nedá.

### 34.9.2 Dropshipping — zboží bez skladu

Vypneš-li na kartě příznak **„Skladová položka"**, karta žije **jen z
dodavatelů**: skladové množství se nesleduje, dostupnost a dodací lhůta se berou
od dodavatele a nákupní cena pro cenotvorbu přijde z preferovaného dodavatele.
Kombinace **„Skladová položka" vypnutá + cenová báze „Ruční" + preferovaný
dodavatel s cenou** je doporučené nastavení pro dropshipping.

> [!NOTE]
> Dropshippingové zboží **nákupní modul neobjednává**. Návrh
> [doplnění zásob](33_Sklad.md#3312-doplneni-zasob-co-objednat) pracuje jen
> s kartami, které mají vyplněnou **minimální zásobu** — u zboží bez skladu žádná
> není a nemá být. Potřebuješ-li takovou položku přesto objednat, založ
> [objednávku](33_Sklad.md#3311-objednavky-u-dodavatele) ručně.

## 34.10 Praktické postupy cenotvorby

### 34.10.1 Nacenění nového skladového zboží

1. Založ kartu, nech **„Skladová položka"** zapnutou.
2. Cenová báze → **Vážený průměr**.
3. Naskladni příjemkou (tím vznikne nákupní cena).
4. Záložka **Ceny** → „Přidat měnu" → `CZK`, režim **Přirážka %**, hodnota podle
   cílové marže (viz převodní tabulka v [§ 34.8.3](#3483-prirazka-vs-marze-neplet-si-je)),
   zaokrouhlení **Na koruny** nebo **Na 9 na konci**.
5. **„Přepočítat"** a zkontroluj sloupec „Výsledná cena".

### 34.10.2 Nacenění dropshippingového zboží

1. Vypni **„Skladová položka"**, cenová báze → **Ruční**.
2. Záložka **Dodavatelé** → přidej dodavatele s **nákupní cenou, měnou, kódem a
   dodací lhůtou**, označ ho jako **Preferovaného**.
3. Záložka **Ceny** → `CZK` s přirážkou → **„Přepočítat"**.

### 34.10.3 Změna dodavatele nebo jeho ceníku

Po přecenění od dodavatele:

1. Uprav nákupní cenu na záložce **Dodavatelé** (u karet s bází „Ruční" se cena
   přepočte hned po uložení). Přepočet prodejních cen spustí **každý** zápis
   nabídky — tedy i její založení a smazání.
2. Přesouváš-li nákup k jinému dodavateli, přepni u něj přepínač
   **Preferovaný** — starého nech v seznamu jako záložní zdroj.

U rozsáhlejšího ceníku se vyplatí **hromadný import z XLSX nebo CSV**
(**Sklad → U dodavatele → Import ceníku**) — páruje se podle SKU karty
a dodavatele, běží v náhledu s výpisem změn `z → na` a nikdy nic nemaže ani
nezakládá. Formát souboru a chování popisuje
[§ 33.10.2](33_Sklad.md#33102-import-ceniku-dodavatele); tamtéž je i upozornění,
že v tomto vydání tlačítko importu ještě nedoběhne.

### 34.10.4 Kontrola marže

Systém marži nepočítá, ale máš k dispozici obě čísla:

- **nákupní cenu** — ve skladových sestavách (ocenění zásob,
  [§ 33.8](33_Sklad.md#338-skladove-sestavy)) nebo na záložce Dodavatelé,
- **prodejní cenu** — ve sloupci „Výsledná cena".

Marži pak spočítáš jako `(prodej − nákup) ÷ prodej × 100`. Pro pravidelnou
kontrolu se vyplatí vyexportovat skladové karty a dopočítat ji v tabulkovém
procesoru.

### 34.10.5 Akce a výprodej

Na akční ceny má karta vlastní sekci na záložce „Ceny" —
[§ 34.8.9](#3489-akcni-ceny). Postup:

1. V sekci **„Akční ceny"** klikni na **„Přidat akci"**, zadej akční částku a
   měnu.
2. Vyplň, čím má být akce omezená: **datum** („Platí od" / „Platí do"),
   **počet kusů**, nebo obojí. Nevyplněné omezení prostě neplatí.
3. Ulož kartu. Standardní cenu **nech být** — akce ji přebije jen dokud platí a
   po skončení se cena sama vrátí zpět.
4. Zboží v akci si můžeš navíc označit **tagem** (např. „Výprodej",
   [§ 34.5](#345-tagy)) kvůli filtrování a exportu.

> [!TIP]
> Starší návod („Fixní cena" + zaškrtnutá **„Ruční"**) už používej jen na
> **trvalou** změnu ceny. Pro časově nebo množstevně omezenou slevu je akční
> cena vždycky lepší — nemusíš si hlídat její konec.

## 34.11 Mazání vs. archivace

U všech číselníků (výrobci, kategorie, atributy, tagy, poplatky) platí stejné
pravidlo: pokud je záznam **použitý u nějakého zboží**, smazání ho odmítne a
zobrazí hlášku, že je „v použití" — řešením je záznam **archivovat**
(zaškrtávátko „Aktivní" ve formuláři vypnout) místo mazání. Archivované
záznamy zůstávají v tabulce (zešednou), ale nenabízí se při zakládání nového
zboží ani v exportu na e-shop.

## 34.12 Omezení a tipy

### 34.12.1 Číselníky a import

- Modul E-shop je **dostupný jen se zapnutým Skladem** — bez něj se položka v
  menu vůbec nezobrazí (nastavuje se v [§ 53. Nastavení](92_Nastaveni.md)).
- Kódy (výrobce, kategorie, atribut, tag, poplatek) jsou jedinečné **v rámci
  firmy** — při kolizi vrátí formulář chybu „…s tímto kódem už existuje".
- Kategorie nelze přesunout do vlastního podstromu (ochrana proti zacyklení
  stromu).
- Atribut typu `Enum` bez alespoň jedné volby nemá u karty zboží co nabídnout
  k výběru — volby zakládej rovnou při vytváření atributu.
- Import zboží zvládne jen **XLSX/CSV do 2 MB** a nikdy nezakládá výrobce —
  connect-the-dots pořadí je: nejdřív číselníky (výrobci), pak import.
- Readonly uživatelé vidí všechny záložky i importní report, ale nemají
  tlačítka pro zápis (nový/upravit/smazat/import naostro).

> [!TIP]
> Před prvním importem velkého katalogu spusť náhled (dry-run), projdi
> sloupec „Jen problémy" a chyby oprav přímo ve zdrojovém souboru — teprve
> pak spouštěj ostrý import. Ušetříš si tak opakované ruční opravy karet po
> částečně nepovedeném importu.

### 34.12.2 Cenotvorba

Ať si nastavíš očekávání správně — tohle cenotvorba v MyÚčto **neumí**:

| Chybějící funkce | Náhradní řešení |
|---|---|
| **Cenové hladiny / skupiny zákazníků** | Ceny per zákazník existují jen v jednoduchém **Ceníku** pro fakturaci ([§ 92.1.5](92_Nastaveni.md)), který se se skladem nekombinuje |
| **Množstevní slevy** (od X ks levněji) | Samostatná karta pro balení, nebo sleva na dokladu — akční cena umí jen *strop* počtu kusů, ne cenové pásmo |
| **Částečné uplatnění akce v jednom řádku** | Akce je vše nebo nic per řádek — rozděl řádek ([§ 34.8.9](#3489-akcni-ceny)) |
| **Historie cen** | Není — uchovává se jen aktuální hodnota a datum posledního přepočtu |
| **Hromadné přecenění** | Kartu po kartě přes „Přepočítat" |
| **Automatický feed nákupních cen od dodavatele** | Ceník se importuje ručně z XLSX/CSV ([§ 33.10.2](33_Sklad.md#33102-import-ceniku-dodavatele)), online napojení na dodavatele není |
| **Automatický přepočet po příjemce / po importu kurzů** | Ruční „Přepočítat" ([§ 34.8.7](#3487-kdy-se-cena-prepocita)) |
| **Výpočet a reporting marže** | Ručně z nákupní a prodejní ceny |
| **XML feed pro Heureku / Zboží.cz** | Příznak „Exportovat do e-shopu" je jen označení pro externí systém |

## 34.13 Jazyky

**Cesta: `E-shop → Jazyky`**

Číselník jazykových mutací, ve kterých vedeš názvy a popisy zboží a kategorií.
Karta zboží na záložce „Jazyky" nabídne **právě jazyky z tohoto číselníku** —
žádný pevný seznam, který by ti vnucoval jazyky, ve kterých neprodáváš.

| Pole | Význam |
|---|---|
| **Kód** | Kód jazyka ve tvaru `cs`, nebo `pt-BR` pro regionální variantu. Používá se v datech překladů |
| **Název** | Jak se jazyk zobrazí v nabídce (`Čeština`, `English`) |
| **Pořadí** | Řadí jazyky v nabídce; při shodě rozhoduje kód |
| **Výchozí jazyk** | Předvyplní se na nové kartě. Výchozí smí být jen jeden |
| **Aktivní** | Neaktivní (archivovaný) jazyk se nenabízí pro nové překlady |

Ve formuláři je nahoře **rychlá volba** nejčastějších jazyků — klepnutím
předvyplní kód i název, oboje jde pak přepsat.

> [!IMPORTANT]
> **Kód jazyka, ke kterému už existují překlady, nelze změnit.** Překlady jsou
> na jazyk navázané hodnotou kódu, ne odkazem — přejmenování by je od číselníku
> odpojilo. Chceš-li jazyk opravdu vyměnit, založ nový a překlady přepiš.

Jazyk s uloženými překlady **nejde smazat**, jen archivovat. Archivace ho
stáhne z nabídky pro nové překlady, ale už uložené texty zůstávají a karta
s takovým překladem jde dál uložit.

> [!NOTE]
> Při zapnutí modulu dostaneš do číselníku češtinu a všechny jazyky, ve kterých
> už nějaký překlad existuje. Nová firma, která si sklad zapne později, začíná
> s prázdným číselníkem — jazyky si přidá sama, než začne zboží překládat.
