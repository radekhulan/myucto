# 34. E-shop

**Cesta: `Zboží → E-shop`** *(poslední položka sekce Zboží, viditelná jen když je
v [Nastavení](92_Nastaveni.md) zapnutý modul **Sklad**)*

Modul **E-shop** rozšiřuje skladovou kartu zboží (`Zboží → Skladové karty`) o vše,
co potřebuješ pro **prodej přes e-shop**: vícejazyčný popis a SEO, zařazení do
kategorií a označení štítky, typované parametry/atributy, poplatky (autorský,
recyklační…), cenotvorbu odvozenou z nákupní ceny ve více měnách, dodavatele zboží
a hromadný import. Stránka `/eshop` obsahuje číselníky, nastavení a správu
hlavních produktů s variantami. Obsah jednotlivého zboží upravuješ na kartě
konkrétní položky v editoru skladové karty (záložky „Jazyky", „Kategorie &
štítky", „Parametry", „Ceny", „Dodavatelé", „Přílohy").

Tlačítko **Uložit** v editoru zapíše základní údaje, e-shopový obsah, ceny,
akční ceny a dodavatele jako jeden celek. Pokud některá z těchto částí neprojde
kontrolou, neuloží se ani ostatní rozpracované změny. Když tutéž kartu mezitím
uloží jiný uživatel, editor změnu odmítne jako konflikt a ponechá rozepsané
hodnoty ve formuláři, aby je šlo porovnat s aktuálním stavem.

Při přepnutí na jinou stránku editor upozorní na dosud neuložené změny. Spodní
lišta s akcemi **Uložit** a **Zrušit** zůstává viditelná i při dlouhém formuláři.
Záložky mají stav v URL, ovládají se také šipkami vlevo/vpravo a aktivní záložka se
na telefonu automaticky posune do viditelné části lišty.

Záložka **Přílohy** má vlastní stav ukládání. Nahrání, změna pořadí, nastavení
hlavního obrázku, exportu i smazání se ukládají okamžitě a nejsou součástí
tlačítka **Uložit** pro zbytek karty.

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

Záložka **Import zboží** zakládá a aktualizuje skladové karty z CSV nebo XLSX do 50 MB. Soubor se uloží s kontrolním součtem; validace a zápis běží na pozadí jako samostatné úlohy. Průběh a výsledky zůstávají v historii úloh.

### 34.7.1 Postup

1. Vyber soubor. U CSV nastav oddělovač a kódování UTF-8, Windows-1250 nebo ISO-8859-2; u XLSX zvol list. Náhled ukáže hlavičku a první řádky.
2. Namapuj sloupce na údaje karty. Zvol identitu podle SKU, interního ID nebo externího ID v pojmenovaném zdroji. U externího ID se existující karta nehledá náhradně podle shodného SKU.
3. Vyber režim zakládání, aktualizace nebo obojí. Urči zachování či vymazání prázdných hodnot. Mapování a pravidla lze uložit jako verzovaný profil pro další soubor.
4. Spusť náhled. Úloha ověří celý soubor a uloží rozdíly před zápisem. Report je stránkovaný a rozlišuje připravené řádky, řádky beze změny, chyby a konflikty.
5. Potvrď aplikaci připravených řádků. Samostatná úloha zapisuje po dávkách a ukazuje průběh. Chybné řádky se nezapisují; oprav je ve zdroji a vytvoř nový náhled.
6. Obsahuje-li import adresy médií, navazující úloha je stáhne až po úspěšném zápisu karet. Průběh a chyby jednotlivých adres jsou vidět ve stejném reportu importu.

### 34.7.2 Předvolby ABRA Flexi a POHODA

Předvolba pouze vyplní mapování podporovaných sloupců. Nastavení můžeš před náhledem ručně upravit stejně jako běžný profil.

**ABRA Flexi - ceník v CZK (CSV)**

Předvolba načítá `cenaZaklBezDph` jako cenu v CZK bez DPH. Použij ji pro korunový ceník; u jiné měny toto cenové pole nemapuj a ceny nastav samostatně.

1. V API použij evidenci `cenik`, filtr `(exportNaEshop=true)` a formát `.csv`.
2. Vyžádej detail `custom:kod,nazev,eanKod,cenaZaklBezDph,skladove,exportNaEshop,popis`, kódování UTF-8 a oddělovač středník. Parametr ABRA Flexi se v URL píše `delimeter=%3B`.
3. Nahraj CSV a zvol předvolbu **ABRA Flexi - ceník v CZK**.

```text
/c/FIRMA/cenik/(exportNaEshop=true).csv?detail=custom:kod,nazev,eanKod,cenaZaklBezDph,skladove,exportNaEshop,popis&limit=0&encoding=utf-8&delimeter=%3B
```

Předvolba mapuje `kod`, `nazev`, `eanKod`, `cenaZaklBezDph`, `skladove`, `exportNaEshop` a `popis`. Hlavička byla ověřena proti veřejnému DEMO API ABRA Flexi. Soubor z konkrétní zákaznické instalace a konkrétní verze ERP ověřen nebyl. Podrobnosti popisuje [oficiální návod ABRA Flexi pro napojení e-shopu](https://www.flexibee.eu/napojeni-na-internetovy-obchod/) a [dokumentace podporovaných formátů](https://podpora.flexibee.eu/cs/articles/3638755-jak-zacit-s-api-flexi-5-6-podporovane-formaty).

**POHODA - tabulka zásob (XLSX)**

1. Otevři **Sklady > Zásoby** a v tabulce vyber **Export tabulky**.
2. Do exportu zařaď přesně sloupce `Kód`, `Název`, `M. j.` a `Čár. kód`. Výběr lze v POHODĚ uložit jako šablonu.
3. Zvol XLSX, identifikátory ponech jako text, nahraj první list a použij předvolbu **POHODA - tabulka zásob**.

POHODA předvolba záměrně neimportuje ceny, DPH ani skladové stavy. Přesné názvy čtyř sloupců vycházejí z oficiální dokumentace uživatelského rozhraní. Export z konkrétní verze POHODY ověřen nebyl. Postup exportu a ukládání šablon popisuje [oficiální návod POHODA](https://www.stormware.cz/podpora/faq/pohoda/198/Jak-mohu-vyexportovat-udaje-z-tabulky-do-excelu-nebo-jako-textovy-soubor/?id=3257&p=4); názvy polí jsou v [podrobném nastavení zásob](https://www.stormware.cz/prirucka-pohoda-online/Sklady/Podrobne_nastaveni/).

### 34.7.3 Sloupce souboru

Názvy a pořadí sloupců jsou volitelné, jejich význam určuje mapování. SKU a textové EAN zachovávají úvodní nuly. V XLSX proto identifikátory ukládej jako text; číslice odstraněné už tabulkovým editorem nelze obnovit.

| Údaj | Význam |
|---|---|
| SKU | Katalogové číslo, nejvýše 50 znaků; povinné při zakládání nové karty |
| Název | Povinný pro novou kartu, nejvýše 255 znaků |
| Jednotka a EAN | Jednotka má výchozí hodnotu `ks`, EAN zůstává textem |
| Prodejní cena v CZK | Pevná cena bez DPH, například `1234.50` nebo `1 234,50`; ostatní měny zůstávají zachované |
| Typ, DPH a minimum | Typ zboží/materiál/výrobek, ID existující sazby DPH a minimální množství |
| Výrobce | Kód nebo ID existujícího výrobce dané firmy |
| Aktivita a export | Logická hodnota `1`/`0`, `ano`/`ne`, `true`/`false` nebo `yes`/`no` |
| Hmotnost, záruka a dodání | Celá nezáporná čísla v gramech, měsících a dnech |
| Kategorie, štítky, překlady, parametry a poplatky | Pokročilé sloupce s JSON seznamy odpovídajícími údajům karty |
| Měnové ceny | JSON seznam cenových řádků; nelze současně mapovat jednoduchou CZK cenu a měnové ceny |
| Adresy médií | JSON seznam nejvýše 10 veřejných HTTPS adres obrázků nebo dokumentů pro jednu kartu |

### 34.7.4 Chování importu

- Nenamapované údaje se nemění. Prázdné hodnoty se standardně zachovávají; vymazání musí být zvolené pravidlem profilu nebo pole.
- Import nikdy nemaže kartu, která v souboru chybí. Skladové stavy a pohyby se tímto importem nezapisují.
- Stejné normalizované hodnoty, například `100` a `100.00`, nevytvářejí věcnou změnu ani novou verzi karty.
- Duplicitní identita označí všechny dotčené řádky jako chybné. Cizí nebo neexistující interní ID se nenahradí novou kartou.
- Novější ruční změnu mezi náhledem a aplikací import nepřepíše. Řádek skončí konfliktem a vyžaduje nový náhled.
- Dokončené dávky zůstávají zapsané. Po přerušení úloha pokračuje od checkpointu bez opakovaného založení karet; velký import nemá globální rollback.
- Vzorce v XLSX se nespouštějí. Neplatné hodnoty, příliš velké buňky a nebezpečně rozbalitelné soubory se odmítnou.
- Média se přidávají, import je nemaže. Stejný obsah se ke stejné kartě nepřipojí podruhé a opakovaný běh nezmění verzi karty.
- Výsledek zápisu produktů včetně konfliktů zůstává dostupný i během následného stahování médií; oba kroky mají vlastní výsledky.
- Stahování dovoluje pouze HTTPS, kontroluje cílovou IP při každém přesměrování a odmítá interní, lokální a vyhrazené sítě. Jedno médium může mít nejvýše 8 MiB.

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

Výpočet používá přesnou desetinnou aritmetiku; zaokrouhlení se aplikuje na výslednou cenu.

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

Systém nabízí **přirážku** i **cílovou marži**. Každý režim počítá procento z jiného základu:

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
> **Cílová marže** musí být alespoň 0 % a menší než 100 %. Cena se počítá jako
> `nákup ÷ (1 − marže / 100)`. Nákup 100 Kč a marže 25 % dají 133,33 Kč.
> Skutečný zisk dále ovlivňují akční ceny, slevy a dodatečné náklady.

### Cenové profily, pravidla a obchodní kurzy

V **Zboží → Cenová pravidla** nastavíš profil pro jednu měnu: přirážku nebo
cílovou marži, zaokrouhlení, zdroj obchodního kurzu a jeho maximální stáří.
Pravidlo přiřadí profil konkrétní kartě, kategorii, výrobci, dodavateli nebo celé firmě.
Přednost má karta, potom kategorie a výrobce, dodavatel a nakonec výchozí pravidlo.
Ve stejné úrovni rozhoduje vyšší priorita, při shodě dříve založené pravidlo.

Na cenovém řádku karty zapni **Cenová pravidla**. Výpočet pak přebírá profil;
ruční přepis ceny má nadále přednost. Bez této volby zůstává lokální nastavení
cenového řádku. Samotná změna profilu nezaloží kartám chybějící měnové řádky.

Obchodní kurzy mají měnu, datum, zdroj a hodnotu v CZK za jednotku měny.
Jsou oddělené podle firmy od účetního kurzovního lístku. Profil určuje povolené
stáří; chybějící nebo starý kurz je chyba, nepřepočítá cenu na nulu.
Změna profilu, pravidla či kurzu spustí úlohu na pozadí pro existující cenové řádky.
Průběh je vidět na stránce i v historii úloh. Uložení beze změny další běh nevytváří.
Běh používá uložená pravidla a kurzy; změněná karta dostane nový přepočet.
Historické doklady si zachovávají původní ceny a kurzy.

Správa i čtení profilů vyžadují právo na zápis e-shopu a skladových karet,
protože obsahují nákladové a maržové nastavení.
### Cenová matice

Záložka **Cenová matice** v e-shopu porovná ceny vybraných karet po měnách.
Vyber karty a měny a vytvoř náhled. Náhled i následné použití změn běží jako
úlohy na pozadí s průběhem a výsledkem po jednotlivých kartách.

U cen vidíš náklad, výslednou cenu, marži a použitý kurz nebo pravidlo.
Výsledek lze filtrovat podle stavu, měny, chybějící ceny, ruční výjimky nebo
odchylky od původní ceny. Hranici odchylky nastavíš před výpočtem náhledu.

Jednotlivou cenu lze uzamknout, nastavit jako pevnou, vrátit pod pravidla
nebo odstranit pro danou měnu. Změny se zapíší až po potvrzení hotového
náhledu. Konfliktní kartu oprav a zahrň do nového náhledu.

CSV původního nebo navrženého stavu umožní úpravy mimo aplikaci a zpětné
načtení. Zachovej hlavičku, identifikátor karty a měnu. Zpětné načtení opět
vytvoří náhled, který je nutné potvrdit. Jeden soubor může mít nejvýše 50 MB
a 90 000 cenových řádků. Přístup vyžaduje právo zápisu e-shopu i skladových
karet, protože matice zobrazuje také interní náklady.

### 34.8.4 Záložka „Ceny"

Záložka rovnou připraví **řádek pro každou aktivní prodejní měnu**. Prázdné
řádky se neukládají. Ikonou koše odstraníš uloženou cenu; aktivní měna zůstane
připravená k novému vyplnění. Dříve uložené ceny neaktivních měn jsou označené
a zůstávají viditelné.

| Sloupec | Význam |
|---|---|
| **Měna** | Kód měny podle ISO 4217 (3 písmena, např. `CZK`, `EUR`) — jedinečný v rámci karty |
| **Režim** | **Přirážka %**, **Cílová marže %** nebo **Fixní cena** (pevná částka) |
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

Přepočet spouští aplikace při zápisu ceny nebo prostřednictvím trvalé úlohy:

| Událost | Přepočet |
|---|:---:|
| Uložení cenových řádků (záložka Ceny) | ✅ automaticky |
| Kliknutí na **„Přepočítat"** | ✅ vynuceně |
| Uložení dodavatelů (záložka Dodavatelé) | ✅ automaticky |
| Uložení karty zboží | ✅ automaticky |
| **Zaúčtování nebo storno skladového dokladu** | Automaticky ve frontě pro dotčené karty |
| **Import změněných kurzů** | Automaticky ve frontě pro dotčené firmy |
| Hromadné přecenění katalogu | Tlačítkem v přehledu úloh |

Průběh najdeš v **Úlohách katalogu**. Úloha ukazuje stav a počet dokončených
položek. Po chybě ji lze opakovat od poslední dokončené dávky. Zrušení zastaví
další dávky, ale již uložené ceny ponechá. Pevné ceny zůstávají pevné.
Přepočet na pozadí vyžaduje běžící plánovač s úlohou `cron-catalog-worker`.

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
4. Záložka **Ceny**, připravený řádek `CZK`, režim **Přirážka %**, hodnota podle
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

Cílová marže slouží k nacenění. Pro kontrolu skutečné marže porovnej obě čísla:

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
| **Automatický feed nákupních cen od dodavatele** | Ceník se importuje ručně z XLSX/CSV ([§ 33.10.2](33_Sklad.md#33102-import-ceniku-dodavatele)), online napojení na dodavatele není |
| **Reporting skutečné marže** | Ručně z nákupní a prodejní ceny |
| **XML feed pro Heureku / Zboží.cz** | Příznak „Exportovat do e-shopu" je jen označení pro externí systém |

## 34.13 Jazyky

**Cesta: `E-shop → Jazyky`**

Číselník jazykových mutací, ve kterých vedeš názvy a popisy zboží a kategorií.
Karta zboží na záložce **Jazyky** rovnou otevře češtinu. Další jazyk vybereš
z aktivních jazyků tohoto číselníku; výběr ihned přidá a otevře jeho formulář.
Mezi rozepsanými překlady přepínáš jazykovými záložkami. Prázdný připravený
český řádek se neukládá, překlad s obsahem vyžaduje také název.

| Pole | Význam |
|---|---|
| **Kód** | Kód jazyka ve tvaru `cs`, nebo `pt-BR` pro regionální variantu. Používá se v datech překladů |
| **Název** | Jak se jazyk zobrazí v nabídce (`Čeština`, `English`) |
| **Pořadí** | Řadí jazyky v nabídce; při shodě rozhoduje kód |
| **Výchozí jazyk** | Výchozí jazyk číselníku. Výchozí smí být jen jeden; editor karty otevírá češtinu |
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
> s prázdným číselníkem. První uložení českého překladu do něj češtinu doplní;
> další jazyky přidáš v číselníku.
## 34.14 Varianty a vztahy produktů

Na stránce E-shop v záložce **Hlavní produkty** vytvoříš společný produkt a
připojíš k němu existující skladové karty jako varianty. Hlavní produkt nemá
vlastní SKU ani skladovou zásobu. Každá varianta si ponechá své SKU, ceny,
skladové pohyby a externí identitu.

Osy variant vybereš z jednohodnotových výčtových parametrů, například velikost
a barva. Pro každou variantu zadáš jednu možnost každé osy. Dvě varianty
stejného produktu nemohou mít stejnou kombinaci. Parametry určující variantu
měň přes hlavní produkt; běžná záložka Parametry jejich změnu odmítne.

Varianta standardně přebírá výrobce a obsah překladů hlavního produktu.
V záložce **Hlavní produkt** na kartě můžeš jednotlivá pole přepnout na vlastní
hodnotu. Náhled ukazuje výsledný obsah. Slug zůstává vlastní pro každé SKU.
Před odpojením varianty aplikace ukáže obsah, který na kartě zachová, aby
odpojením nezmizel převzatý popis ani výrobce.

V záložce **Vztahy** propojíš příslušenství, náhrady a související zboží.
Kopírování obsahových částí mezi více kartami běží jako trvalá úloha s průběhem
a výsledkem jednotlivých položek. Změněné karty se nepřepíší potichu, jejich
konflikty uvidíš ve výsledku.

## 34.15 Virtuální sety

Na kartě bez vlastní skladové zásoby otevři **Složení setu**. Set může obsahovat
pevné komponenty i další sety. Konfigurátor navíc nabízí povinné a volitelné
skupiny s omezením počtu voleb a měnovými příplatky. Cyklus ve složení systém
odmítne. Pro každou měnu lze použít součet cen komponent, procentní slevu nebo
pevnou cenu. Výpočet používá aktuální ceny komponent a zvolenou konfiguraci.
Set s různými sazbami DPH komponent nelze takto ocenit.

Virtuální set nemá vlastní zásobu a nelze jej přepnout na skladovanou kartu.
Pro předem vyrobené balení použij samostatnou kartu výrobku a **Kompletaci**
v modulu Sklad. Kompletace jednou transakcí vydá komponenty a přijme výrobek
ve stejné celkové hodnotě. Storno se provádí společně přes kompletaci.
Zpětný pohyb, který by změnil ocenění již zkompletovaných komponent, systém
odmítne; nejprve stornuj kompletaci nebo proveď korekci po ní.

## 34.16 Integrační centrum

**Cesta: `Zboží → Integrace`**

Integrační centrum spravuje obecná připojení e-shopů a dalších externích
systémů. U každého připojení nastavíš typ konektoru, provozní stav, mapování
skladu, měny a jazyka, vlastnictví jednotlivých polí, limit požadavků a dobu
uchování provozních záznamů. Přístupové údaje se ukládají šifrovaně a po
uložení se ve formuláři znovu nezobrazí.

Sekce **Webhook** vytvoří nový podpisový klíč a ukáže ho právě jednou. Externí
systém jím podepisuje tělo zprávy spolu s časovým razítkem. Otočením klíče se
předchozí klíč okamžitě zneplatní.

Přehled provozu ukazuje stáří poslední synchronizace, počty čekajících,
zpracovaných a chybových zpráv a bezpečné diagnostické kódy. Nezobrazuje obsah
zpráv ani přístupové údaje. Událost ve stavu trvalé chyby lze po odstranění
příčiny vrátit do fronty tlačítkem **Opakovat**.

Tlačítko **Spustit kontrolu** porovná mapované identity s místními daty. Kontrola
běží na pozadí po dávkách a její průběh zůstává v historii úloh. Stejná kontrola
se pro aktivní připojení spouští také pravidelně. Pokud se změnový kurzor
externího systému dostane mimo uchovávanou historii, systém výslovně vyžádá
nový úplný snapshot katalogu.
