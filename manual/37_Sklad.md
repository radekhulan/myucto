# 37. Sklad

> Návod, jak vést skladovou evidenci: zapnout sklad, založit karty, naskladnit
> a vydat zboží, objednat u dodavatele, udělat inventuru a připravit Intrastat.
> Pro firmy, které nakupují a prodávají zboží či materiál nebo vyrábějí výrobky.

Modul **Sklad** vede skladové karty, příjemky, výdejky a převodky mezi sklady,
víc skladů, inventury a skladové sestavy. Napojuje se na
[Vydané faktury](14_Faktury.md) a [Přijaté faktury](23_Prijate_faktury.md): umí
automaticky vydat zboží při vystavení faktury a naskladnit zboží z přijaté
faktury. Vedle fyzického stavu vede **rezervace, zboží na cestě a skladovost
u dodavatele**, **objednávky vydané dodavatelům** a návrh **doplnění zásob**.
Prodejní objednávky v sekci **Prodej** rezervují skutečně dostupné zboží před
fakturací a předávají rezervaci do vychystání.

Sklad funguje **nezávisle na účetním režimu**, stejně dobře pro podvojné
účetnictví i pro daňovou evidenci. Skladové karty sdílí s
[e-shopovým modulem](38_Eshop.md) (ceny, kategorie, parametry, dodavatelé,
přílohy k produktu).

## 37.1 Kdy to potřebujete

- Začínáte vést sklad a potřebujete založit sklady, karty a počáteční stav.
- Přišlo zboží od dodavatele a musíte ho naskladnit.
- Prodáváte zboží a chcete, aby se při vystavení faktury samo vydalo ze skladu.
- Zjišťujete, co doobjednat, nebo chcete odeslat objednávku dodavateli.
- Je čas inventury nebo uzávěrky a potřebujete ocenění zásob.
- Vykazujete obchod se zbožím do EU a potřebujete podklad pro Intrastat.

<!-- cols: 24 44 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Zapnout skladovou evidenci a založit sklady | `Firma → Nastavení`, záložka **Daně a účetnictví**; `Sklad → E-shop`, záložka **Sklady**; postup v [§ 37.3](#373-krok-za-krokem-zapnout-sklad-a-zalozit-karty) |
| jednou při zavádění | Založit karty a naskladnit počáteční stav | `Sklad → Skladové karty`; `Sklad → Příjemky a výdejky`, **Import počátečních zásob**; [§ 37.4.3](#3743-pocatecni-stav-zasob) |
| při každém dodání zboží | Naskladnit příjemkou nebo z přijaté faktury | `Sklad → Příjemky a výdejky`, nebo detail přijaté faktury; [§ 37.4](#374-krok-za-krokem-naskladnit-zbozi) |
| při každém prodeji | Vydat zboží fakturou nebo ruční výdejkou | editor faktury, nebo `Sklad → Příjemky a výdejky`; [§ 37.5](#375-krok-za-krokem-vydat-zbozi) |
| průběžně | Zkontrolovat, co doobjednat, a objednat | `Sklad → Objednávky`, tlačítko **Doplnění zásob**; [§ 37.7](#377-krok-za-krokem-objednat-u-dodavatele) |
| při expedici | Vychystat a odeslat zásilku | `Sklad → Vychystání a expedice`; [§ 37.8](#378-krok-za-krokem-vychystat-a-expedovat) |
| k rozhodnému dni, nejméně při uzávěrce | Provést inventuru | `Sklad → Inventury`; [§ 37.6](#376-krok-za-krokem-inventura) |
| podle potřeby | Zjistit ocenění zásob nebo prodeje karet | `Sklad → Sestavy`; [§ 37.9](#379-krok-za-krokem-sestavy-a-oceneni) |
| za každé referenční období | Připravit CSV pro Intrastat | `Sklad → Intrastat export`; [§ 37.10](#3710-krok-za-krokem-intrastat) |

## 37.2 Než začnete

1. **Licence.** Přepínač skladu je dostupný jen s komerční licencí (viz
   [Licence a aktivace](105_Licence_a_aktivace.md)). Bez ní zůstane zamčený.
2. **Zapnutý modul.** Dokud sklad v nastavení firmy nezapnete, sekce **Sklad**
   se v menu nezobrazí a skladové funkce odmítají i čtení (hláška „Skladový
   modul není pro tuto firmu zapnutý“). Zapnutí je v
   [§ 37.3](#373-krok-za-krokem-zapnout-sklad-a-zalozit-karty).
3. **Oprávnění.** Skladové stránky vyžadují skladové oprávnění. Zápis
   objednávek dodavatelům vyžaduje samostatné oprávnění k zápisu objednávek
   ([§ 37.12.11.6](#3712116-opravneni)). Vychystání, expedice a odchylky při
   vychystání mají vlastní oprávnění.
4. **Dodavatelé v adresáři.** Pro objednávky potřebujete klienty s rolí
   dodavatele (viz [Klienti](18_Klienti.md)).

## 37.3 Krok za krokem: zapnout sklad a založit karty

### 37.3.1 Zapnutí skladu

1. Otevřete `Firma → Nastavení` a záložku **Daně a účetnictví**.
2. V oddílu **Vést skladovou evidenci** zaškrtněte **Vést skladovou evidenci**.
3. Podle potřeby nastavte tři navazující volby:
   - **Automatická výdejka při vystavení faktury** (výchozí zapnuto). Má-li faktura
     řádky napojené na skladovou kartu, při vystavení se sama založí a zaúčtuje
     výdejka. Vypněte ji, chcete-li výdejky zakládat ručně.
   - **Na PDF faktury rozepsat balení na základní jednotky** (výchozí zapnuto).
     U řádku fakturovaného v balení doplní PDF množství v základní jednotce.
   - **Zboží se počítá „na cestě“ od stavu**: **Odeslaná objednávka** (výchozí),
     nebo **Až potvrzená dodavatelem**.
4. Uložte nastavení tlačítkem v záhlaví stránky.

**Jak poznáte, že je hotovo:** v menu se objeví sekce **Sklad**.

### 37.3.2 Založení skladů

1. Otevřete `Sklad → E-shop` a záložku **Sklady**.
2. Přidejte sklad: vyplňte **kód** (nejvýš 20 znaků, v rámci firmy jedinečný)
   a **název** (nejvýš 100 znaků). Jeden sklad označte jako **Výchozí sklad**.
3. Uložte.

**Jak poznáte, že je hotovo:** sklad je v tabulce a u něj se ukazuje **celková
hodnota** zásob. Výchozí sklad se předvyplňuje do nových skladových dokladů.

### 37.3.3 Založení skladové karty

1. Otevřete `Sklad → Skladové karty` a klikněte na **Nová skladová karta**.
2. Vyplňte **Název** (povinný). **SKU** můžete nechat prázdné, doplní se z názvu.
3. Zvolte **Typ karty**: **Materiál**, **Zboží**, nebo **Výrobek**. Typ určuje,
   na které účty se v uzávěrce zaúčtuje stav zásob.
4. Vyplňte měrnou jednotku, EAN, sazbu DPH, prodejní cenu bez DPH a **minimální
   zásobu** (pod ní se karta zvýrazní a navrhuje se k doobjednání).
5. Chcete-li kartu prodávat v e-shopu, zapněte **Exportovat do e-shopu**.
6. Uložte.

**Jak poznáte, že je hotovo:** karta je v seznamu a jde otevřít její detail.
Po prvním uložení se v editoru zpřístupní další záložky (překlady, kategorie,
parametry, ceny, dodavatelé, přílohy), které popisuje kapitola [E-shop](38_Eshop.md).

> [!TIP]
> Kartu můžete založit dlouho před prvním nákupem, bez zásoby a bez pohybu, jen
> s nabídkami dodavatelů. Teprve podle nich se rozhodnete, jestli a od koho
> koupíte. Karta bez pohybu ukazuje ve všech množstvích nuly.

### 37.3.4 Hromadné založení z existujících dat

Katalog zboží lze nahrát importem (viz [Import zboží](38_Eshop.md#38117-import-zbozi)).
Počáteční zásoby naskladníte podle [§ 37.4.3](#3743-pocatecni-stav-zasob).

## 37.4 Krok za krokem: naskladnit zboží

### 37.4.1 Naskladnění příjemkou

1. Otevřete `Sklad → Příjemky a výdejky` a klikněte na **Nová příjemka**.
2. Zvolte **sklad**, **datum dokladu** a vyplňte **popis** (povinný, je to obsah
   účetního případu). **Partner** je volný text.
3. Přidejte řádky: skladovou kartu, **množství** a **jednotkovou cenu**.
4. Je-li k příjmu vedlejší náklad (doprava, clo, provize), přidejte ho jako
   **vedlejší pořizovací náklad** a zvolte, zda se rozpustí **podle hodnoty**,
   nebo **podle množství** (jen u rozpracované příjemky).
5. Klikněte na **Zaúčtovat**. Chcete-li doklad dokončit později, klikněte na
   **Uložit koncept**.

**Jak poznáte, že je hotovo:** doklad má číslo tvaru `PRI-RRRR-NNNN`, stav
**Zaúčtováno** a zásoba na kartě vzrostla (detail karty, záložka **Pohyby**).

### 37.4.2 Naskladnění z přijaté faktury

1. Otevřete detail [přijaté faktury](23_Prijate_faktury.md). Řádky, které se mají
   naskladnit, musí být napojené na skladovou kartu.
2. Klikněte na **Přijmout na sklad**. Tlačítko je jen u faktury, která má aspoň
   jeden dosud nenaskladněný řádek napojený na kartu.
3. V průvodci zkontrolujte **zbývající množství k naskladnění**. Můžete ho
   přepsat na menší (částečné naskladnění), zvolit jinou kartu, nebo rovnou
   z popisu řádku založit novou skladovou kartu.
4. Podle potřeby zahrňte vedlejší pořizovací náklady. Nabídnou se řádky téže
   faktury, které nemají skladovou kartu (typicky doprava).
5. Potvrďte. Vznikne **rozpracovaná příjemka**. Otevřete ji v
   `Sklad → Příjemky a výdejky` a zaúčtujte.

**Jak poznáte, že je hotovo:** příjemka s původem **Přijatá faktura** je
zaúčtovaná a zásoba vzrostla.

### 37.4.3 Počáteční stav zásob

1. Otevřete `Sklad → Příjemky a výdejky` a klikněte na **Import počátečních zásob**.
2. Vyberte sklad, datum, vlastní stálý identifikátor importu a soubor CSV nebo XLSX.
   U CSV nastavte kódování a oddělovač, u XLSX list. Po změně voleb se znovu
   načte ukázka s mapováním sloupců.
3. Zkontrolujte mapování. Povinné sloupce jsou stálé ID řádku, SKU, množství
   a pořizovací cena za jednotku. SKU i ID mohou začínat nulou, import je
   zachová jako text.
4. Spusťte kontrolu celého souboru. Teprve dokončený náhled bez chyb jde zaúčtovat.
5. Zaúčtujte. Vznikají běžné příjemky po dávkách.

**Jak poznáte, že je hotovo:** všechny dávky doběhly a zásoba je na kartách.
Průběh můžete bezpečně zavřít a znovu otevřít, dokončené dávky se neopakují.

> [!WARNING]
> Karty se sledováním šarží nebo sériových čísel naskladněte ruční příjemkou,
> kde lze vyplnit úplné alokace.

## 37.5 Krok za krokem: vydat zboží

### 37.5.1 Výdej fakturou

1. V editoru [vydané faktury](15_Faktura_editor.md) vyberte u řádku **skladovou
   kartu** a **sklad**, ze kterého se zboží vydá. Zobrazí se náhled dostupného
   množství.
2. Fakturu vystavte.

**Jak poznáte, že je hotovo:** při zapnuté automatické výdejce vznikne výdejka
s původem **Vydaná faktura**. Přehled navázaných výdejek je na detailu faktury.

Chybí-li zboží, vystavení skončí chybou „Nedostatek zásob pro výdej“ a faktura
zůstane konceptem. Opravte množství či sklad, nebo napřed naskladněte.

### 37.5.2 Ruční výdejka

1. Otevřete `Sklad → Příjemky a výdejky` a klikněte na **Nová výdejka**. Můžete
   také otevřít detail karty a použít **Nová výdejka**, která kartu předvyplní.
2. Zvolte sklad, datum, popis a řádky s množstvím. Cenu nezadáváte, spočítá se
   z klouzavého průměru při zaúčtování.
3. Klikněte na **Zaúčtovat**.

**Jak poznáte, že je hotovo:** doklad `VYD-RRRR-NNNN` je zaúčtovaný a zásoba klesla.

### 37.5.3 Převod mezi sklady

V editoru dokladu přepněte typ na **Převodka**, zvolte různý zdrojový a cílový
sklad, přidejte řádky a zaúčtujte. Číslo má tvar `PRE-RRRR-NNNN`.

### 37.5.4 Oprava chybného dokladu

Zaúčtovaný doklad nejde upravit. V editoru klikněte na **Stornovat**, vyplňte
**Důvod storna** a potvrďte. Vznikne protidoklad se stejnými hodnotami.

## 37.6 Krok za krokem: inventura

1. Otevřete `Sklad → Inventury` a klikněte na **Nová inventura**.
2. Zvolte **sklad**, **datum**, způsob zjištění skutečného stavu a odpovědné
   osoby. Poznámka je nepovinná.
3. Počkejte, až skončí příprava očekávaných stavů (průběh je vidět v detailu
   inventury), a klikněte na **Zahájit sčítání**.
4. U každé položky zadejte **skutečně napočítané množství**. Sedí-li stav, pomůže
   **Převzít očekávané u všech**. Rozepsané počty průběžně ukládejte
   tlačítkem **Uložit rozpracované**.
5. U kladného rozdílu zadejte reprodukční pořizovací cenu za jednotku. Kartu,
   kterou jste našli na skladu navíc, přidejte polem **Přidat kartu nalezenou
   na skladu**.
6. Zkontrolujte rekapitulaci a klikněte na **Uzavřít**. Uzavření nejde vzít zpět.

**Jak poznáte, že je hotovo:** inventura je ve stavu **Uzavřena**, z rekapitulace
se dá proklikat na rozdílovou příjemku (přebytky) a rozdílovou výdejku (manka).
Uzavřenou inventuru vytisknete jako **inventurní soupis PDF**.

> [!WARNING]
> Dokud je inventura ve stavu **Probíhá sčítání**, nejde na daném skladu
> zaúčtovat žádný jiný pohyb. Rozdělanou inventuru proto dokončete a uzavřete.

Pro průběžné kontroly slouží na stejné stránce **cyklické inventury** (viz
[§ 37.12.7](#37127-inventury)).

## 37.7 Krok za krokem: objednat u dodavatele

### 37.7.1 Zadání nabídky dodavatele

1. Otevřete `Sklad → U dodavatele` a přidejte nabídku: vyberte **Zboží**
   a **Dodavatele** (povinné), vyplňte **Kód u dodavatele**, **Nákupní cenu**,
   **Měnu**, **Lhůtu**, **Min. objednávku** a **Balení**.
2. Jednoho dodavatele na kartu označte jako **Hlavního dodavatele**.
3. Uložte.

Ceník dodavatele můžete nahrát najednou tlačítkem **Import ceníku** (postup v
[§ 37.12.10.2](#3712102-import-ceniku-dodavatele)).

### 37.7.2 Zjištění, co doobjednat

1. Otevřete `Sklad → Objednávky` a klikněte na **Doplnění zásob**.
2. Nastavte filtr (sklad, **Jen pod minimem**, **Koeficient**).
3. Zaškrtněte řádky a klikněte na **Vytvořit objednávky**. Vznikne jedna
   objednávka na dodavatele, vždy jako koncept. Karty bez dodavatele nebo
   množství se vypíšou jako přeskočené.

### 37.7.3 Odeslání a potvrzení objednávky

1. V `Sklad → Objednávky` otevřete koncept (nebo klikněte na **Nová objednávka**)
   a zkontrolujte hlavičku a řádky.
2. Klikněte na **PDF** a pošlete dokument dodavateli. Tlačítko **Odeslat** jen
   označí objednávku za odeslanou, e-mail nerozesílá.
3. Klikněte na **Odeslat**. Objednávka dostane číslo `OBJ-RRRR-NNNN` a zboží se
   začne počítat jako **na cestě**.
4. Potvrdí-li dodavatel jiný termín nebo množství, klikněte na **Potvrdit** a zapište
   potvrzené hodnoty.

**Jak poznáte, že je hotovo:** objednávka je ve stavu **Odesláno**, případně
**Potvrzeno**, a zboží je v dlaždici **Na cestě** na detailu karty.

### 37.7.4 Příjem zboží z objednávky

1. Na detailu objednávky ve stavu **Potvrzeno** nebo **Částečně přijato** klikněte
   na **Příjem na sklad**.
2. V dialogu zkontrolujte řádky se zbývajícím množstvím a ceny. Řádek s příznakem
   **Odhad** má cenu z objednávky.
3. Potvrďte. Vznikne rozpracovaná příjemka, skladem to zatím nehne.
4. Příjemku zaúčtujte v `Sklad → Příjemky a výdejky`.

**Jak poznáte, že je hotovo:** stav objednávky se sám přepne na **Částečně
přijato** nebo **Přijato** a pod řádky je sekce **Vzniklé příjemky**.

> [!WARNING]
> Odhadnutá cena vstupuje rovnou do klouzavého průměru karty. Po doručení faktury
> proto cenu na příjemce opravte dřív, než ji zaúčtujete (viz
> [§ 37.12.11.4](#3712114-prijem-zbozi-z-objednavky)).

### 37.7.5 Když dodavatel zbytek nedodá

Na detailu klikněte na **Zavřít zbytek**. Nedodané množství zmizí z „na cestě"
a doplnění zásob ho začne navrhovat znovu. Zavřenou objednávku vrátíte tlačítkem
**Znovu otevřít**.

## 37.8 Krok za krokem: vychystat a expedovat

1. Otevřete `Sklad → Vychystání a expedice`. Úlohu založíte z konceptu výdejky,
   nebo ji otevřete z detailu prodejní objednávky.
2. Na mobilu skladník skenuje **SKU nebo EAN** jednotlivých kusů. U sledovaných
   karet vybere sériová čísla nebo šarže a lokace.
3. Z vychystaného množství vytvořte zásilku (dopravce, tracking, položky)
   a zásilku expedujte. Expedice zaúčtuje právě jednu výdejku.
4. Vrací-li zákazník zboží, založte vratku u konkrétní zásilky a zvolte výsledek:
   **Vrátit do prodeje**, **Karanténa**, nebo **Vyřadit**.

**Jak poznáte, že je hotovo:** zásilka je expedovaná, vznikla výdejka a zbytek
úlohy zůstal otevřený jen u částečné expedice.

### 37.8.1 Prodejní objednávka s rezervací

1. Otevřete `Prodej → Prodejní objednávky` a objednávku zadejte.
2. **Potvrzením** objednávky se zásoba rezervuje na konkrétním skladu. Nestačí-li
   zásoba, platí zvolená politika: **Vše, nebo nic** potvrzení odmítne,
   **Povolit částečnou rezervaci** rezervuje dostupné a objednávku zařadí do fronty nedostatků.
3. Po doplnění skladu klikněte na detailu na **Rezervovat zbývající množství**.
4. Fakturu založíte akcí **Vytvořit fakturu**. Vznikne koncept vydané faktury,
   automaticky se nevystaví.

## 37.9 Krok za krokem: sestavy a ocenění

1. Otevřete `Sklad → Sestavy`.
2. Záložka **Stav zásob k datu** ukazuje množství, průměrnou cenu a hodnotu po
   kartách a skladech.
3. Záložka **Ocenění k datu** spočítá stav k historickému datu. Výpočet běží na
   pozadí, průběh je vidět na stránce.
4. Záložka **Prodeje** ukáže, kolik kusů, komu a za kolik jste prodali (filtry
   období, sklad, odběratel, kategorie; **Po odběratelích** a po kartách sečte).
5. Pro export použijte **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** sestava má součtový řádek (počet položek a celková
hodnota) a export se stáhl.

## 37.10 Krok za krokem: Intrastat

Pro podání od 1. 1. 2026 se používá aplikace Celní správy InstatEvo. MyÚčto hlášení
nepodává, jen připraví CSV a zkontroluje podklady.

1. Otevřete `Sklad → Intrastat export`.
2. Vyberte **referenční období** (nejstarší je leden 2026) a **směr pohybu**:
   odeslání pracuje s výdejkami navázanými na vydané faktury, přijetí s příjemkami
   navázanými na přijaté faktury.
3. Nastavte **výchozí kód transakce**, **druh dopravy**, **dodací podmínky**,
   **typ věty** a případný **statistický znak** podle platných číselníků a povahy
   obchodů.
4. Klikněte na **Zkontrolovat náhled**. Opravte všechny chyby na skladových
   kartách, fakturách nebo firemních údajích a náhled vytvořte znovu.
5. U náhledu bez chyb klikněte na **Stáhnout CSV pro InstatEvo**.
6. Klikněte na **Otevřít InstatEvo**, v aplikaci zvolte import z CSV, vyberte stažený
   soubor, projděte kontroly a hlášení odešlete.

**Jak poznáte, že je hotovo:** tlačítko stažení se zpřístupnilo, náhled je bez chyb
a InstatEvo soubor přijalo.

> [!WARNING]
> Změna období, směru nebo kódu zruší předchozí náhled. Před každým stažením
> vytvořte nový.

Údaje na skladové kartě (KN8, země původu, hmotnost) viz
[§ 37.12.2.2](#371222-udaje-skladove-karty-pro-intrastat).

## 37.11 Když něco nejde

<!-- cols: 36 30 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Skladový modul není pro tuto firmu zapnutý | Sklad není zapnutý v nastavení | `Firma → Nastavení`, záložka **Daně a účetnictví**, **Vést skladovou evidenci** |
| Skladová karta s tímto SKU už existuje | SKU musí být v rámci firmy jedinečné | Zvolte jiné SKU, nebo použijte existující kartu |
| Skladovou kartu nelze smazat - má skladové pohyby. Deaktivujte ji místo mazání. | Karta s pohybem se nemaže | Na detailu klikněte na **Deaktivovat** |
| Nedostatek zásob pro výdej | Doklad by způsobil záporný stav | Snižte množství, změňte sklad, nebo napřed naskladněte. Záporný stav nejde povolit. Nesedí-li sklad se skutečností, srovnejte ho inventurou |
| Na skladu probíhá inventura - dokončete ji před zaúčtováním pohybu. | Na skladu je otevřená inventura | Dokončete a uzavřete inventuru (`Sklad → Inventury`) |
| Na skladu už je rozpracovaná inventura. | Na sklad smí běžet jedna otevřená inventura | Dokončete rozpracovanou |
| Upravovat lze jen rozpracovaný (draft) doklad. / Smazat lze jen rozpracovaný (draft) doklad. | Zaúčtovaný doklad se neupravuje | Použijte **Stornovat** |
| Stornovat lze jen zaúčtovaný doklad. / Doklad už byl stornován. | Storno jde jen u zaúčtovaného dokladu a jen jednou | Nic, doklad už je stornovaný |
| Stornovaný doklad nelze zaúčtovat. | Stav je jednosměrný | Založte nový doklad |
| Množství přesahuje zbývající k příjmu z faktury | Chcete naskladnit víc, než je na faktuře | Snižte množství |
| Množství přesahuje zbývající k příjmu z objednávky. Potvrď nadměrnou dodávku, nebo množství uprav. | Dodavatel dodal víc, než zbývá | Zaškrtněte **Povolit nadměrné dodání**, nebo množství snižte |
| Sklad nelze smazat - má nenulový stav nebo skladové pohyby. Deaktivujte jej místo mazání. | Sklad s pohyby nejde smazat | Sklad deaktivujte |
| Sklad s tímto kódem už existuje | Kód skladu je v rámci firmy jedinečný | Zvolte jiný kód |
| Tento dodavatel už u karty nabídku má - upravte ji. | Dvojice zboží a dodavatel může být jen jednou | Upravte existující nabídku |
| Import obsahuje chyby - nic nebylo zapsáno. | Import ceníku je vše, nebo nic | Opravte chybné řádky a nahrajte soubor znovu |
| K objednávce už existuje příjem - místo storna uzavři nedodaný zbytek („Zavřít zbytek“) | Storno je nepřípustné po prvním příjmu | Použijte **Zavřít zbytek** |
| Upravovat lze jen rozpracovanou (draft) objednávku. Odeslanou objednávku uprav přes potvrzení nebo uzavření zbytku. | Odeslaná objednávka je uzamčená | Použijte **Potvrdit** nebo **Zavřít zbytek** |
| Cena je odhad z objednávky - po doručení faktury přeceňte. | Příjemka má cenu z objednávky | Cenu před zaúčtováním přepište, nebo zaúčtovanou příjemku stornujte a přijměte znovu |
| Náhled Intrastatu hlásí chybu a CSV nejde stáhnout | Chybí KN8, země původu, hmotnost, kurz, DIČ, nebo faktura obsahuje nenavázanou položku | Doplňte údaje podle [§ 37.12.15](#371215-intrastat-export-pro-instatevo) a vytvořte nový náhled |

## 37.12 Podrobnosti a pravidla

### 37.12.1 Zapnutí modulu

Skladovou evidenci zapnete v `Firma → Nastavení` na záložce **Daně a účetnictví**
v oddílu **Vést skladovou evidenci**. Dokud ji nezapnete, sekce Sklad se v menu
vůbec nezobrazí a skladové funkce odmítnou i čtecí požadavky, aby se ven nedostala
žádná data nezapnutého modulu:

- **Vést skladovou evidenci** je hlavní přepínač. Zpřístupní skladové karty, doklady,
  inventury i sestavy a přidá do menu sekci Sklad.
- **Automatická výdejka při vystavení faktury** se zobrazí jen po zapnutí evidence
  (výchozí zapnuto). Má-li faktura řádky napojené na skladovou kartu, při vystavení
  se automaticky založí a zaúčtuje výdejka. Vypnutím přejdete na ruční vydávání zboží.
  Použijte to, chcete-li mít nad výdejem plnou kontrolu nebo výdejky slučujete
  jinak, než fakturujete.
- **Na PDF faktury rozepsat balení na základní jednotky** se zobrazí jen po zapnutí
  evidence (výchozí zapnuto). U řádku fakturovaného v balení doplní PDF množství
  v základní jednotce ([§ 37.12.5.1](#371251-automaticky-vydej-pri-vystaveni-faktury)).
- **Zboží se počítá „na cestě“ od stavu** určuje, kdy se objednávka počítá jako
  zboží na cestě ([§ 37.12.9.3](#371293-na-ceste)).

Sklad účtuje **způsobem B** podle ČÚS 015 (bod 4.3). V průběhu roku se skladové
pohyby neúčtují na účty, jen evidují. Do účetnictví se promítne až **uzávěrkový
krok** (konečný a počáteční stav zásob, reklasifikace inventurních mank
a přebytků, viz uzávěrka v modulu Účetnictví). Je to jediný podporovaný způsob
účtování zásob, způsob A funkčně implementovaný není.

### 37.12.2 Skladové karty

`Sklad → Skladové karty` je stránkovaný seznam karet materiálu, zboží a výrobků
(výchozí stránkování 50 karet).

Vedle hledání podle SKU, názvu nebo EAN jsou rovnou **výrobce, dodavatel, kategorie
a dostupnost**. Kategorie zahrnuje i své podkategorie. Rozbalené **Filtry** přidávají
typ karty, sklad, štítky, chybějící obrázek, kategorii, cenu, výrobce či EAN,
rozsah množství a filtrovatelný atribut. Sklad omezí stav a hodnotu na vybraný sklad.
Podmínky platí pro celý katalog, ne jen pro zobrazenou stránku.

Hledání od tří znaků prochází i **sériová čísla a šarže** kusů na kartě a **textové
parametry** karty (například VIN nebo výrobní číslo uložené v parametru). Stačí část
čísla. Když karta vyhoví jen takto, zobrazí se pod jejím názvem, co hledání našlo,
například „Sériové číslo: …“ nebo „VIN: …“. Stejně hledá i výběr karty na řádku
faktury a skladového dokladu. Číselné parametry a parametry s výběrem hodnot se
prohledávají jen filtrem parametru.

Filtry si můžete uložit jako výchozí přes uložené filtry, sloupce zapnete či vypnete
výběrem sloupců a hustotu řádků přepínačem hustoty (viz
[Uložené filtry a předvolby](96_Nastaveni.md#96167-ulozene-filtry-a-predvolby-podrobnosti)).

Ikona **Otevřít rychlý detail** vedle SKU zobrazí kartu v postranním panelu.
Tlačítka **Předchozí** a **Další** procházejí celý filtrovaný seznam, případně
aktuální výběr včetně odškrtnutých výjimek. Panel ukazuje pořadí a nabízí otevření
plného detailu nebo editoru. **Import zboží** v liště vede na import katalogu.

Sloupce: **SKU**, **Název**, **Typ**, **MJ** (měrná jednotka), **Stav** (aktuální
množství, červeně a tučně, pokud je pod nastaveným minimem), **Hodnota** (ocenění
stavu v Kč), **Prům. cena** (dopočtená průměrná pořizovací cena za jednotku),
volitelně i **Prodejní cena**, **Min. zásoba** a **Aktivní**. Stav a hodnota se
počítají ze skutečných skladových pohybů v okamžiku zobrazení stránky, ne z uložených
souhrnných čísel karty.

Pole editoru nové karty:

- **Název** (povinný, nejvýš 255 znaků) a **SKU** (nejvýš 50 znaků). Necháte-li SKU
  prázdné, dopočítá se z názvu (bez diakritiky, velkými písmeny, zkráceno na 50 znaků).
  Nezbyde-li z názvu nic použitelného, dosadí se náhodný kód tvaru `SKU-XXXXXXXX`.
  Po prvním ručním zásahu do pole SKU se automatický přepočet vypne. SKU musí být
  v rámci firmy jedinečné, jinak se uložení odmítne („Skladová karta s tímto SKU
  už existuje“). Totéž hlídá i databáze, takže ke kolizi nedojde ani při souběžném
  zakládání.
- **Typ karty**: **Materiál** (spotřebovává se do nákladu 501), **Zboží** (do nákladu
  504) nebo **Výrobek** (uzávěrková kontace 123/583). Typ určuje, na jaké účty se
  v uzávěrce zaúčtuje konečný a počáteční stav a inventurní rozdíly. Řídí to
  posuzovací pravidla `stock.closing.material` (112/501), `stock.closing.goods`
  (132/504), `stock.opening.material` (501/112), `stock.opening.goods` (504/132),
  `stock.shortage.reclass.*` (549/501 nebo 549/504 pro inventurní manko)
  a `stock.surplus.*` (501/648 nebo 504/648 pro inventurní přebytek). Jsou
  k dispozici jako globální šablona, kterou lze firemně přenastavit stejně jako
  ostatní kontace.
- **Měrná jednotka** (výchozí „ks“), **EAN**, **sazba DPH** (výchozí do řádku
  faktury), **prodejní cena bez DPH** (výchozí do řádku faktury) a **minimální
  zásoba**. Klesne-li stav pod ni, karta se v seznamu i sestavách zvýrazní.
- **Poznámka** a příznak **Aktivní**. Neaktivní kartu jde jen deaktivovat, ne
  smazat, aby zůstala historie pohybů.

Po založení karty (jen v režimu úpravy) se zpřístupní i **e-shopové záložky**:
jazykové mutace popisu, kategorie a štítky, parametry, ceny v jednotlivých měnách,
dodavatelé a přílohy či obrázky. Skladová karta je totiž zároveň produktovou kartou
pro e-shop. Záložky popisuje kapitola [E-shop](38_Eshop.md).

#### 37.12.2.1 Hromadná úprava filtrovaných karet

Zaškrtávátkem u řádku vyberete jednu kartu nebo aktuální stránku. Je-li výsledků
víc, nabídka **Vybrat všech N výsledků** přepne výběr na celý aktuálně filtrovaný
katalog. Změna filtru výběr zruší, aby se hromadná akce nikdy neaplikovala na
neviditelně rozšířený jiný výsledek. Po výběru všech výsledků lze jednotlivé karty
opět odškrtnout.

**Hromadná úprava** nejprve vytvoří náhled změn po jednotlivých kartách. V náhledu
vidíte původní a zamýšlené hodnoty výrobce, kategorií, štítků, dodavatelů, aktivity,
exportu do e-shopu a minimální zásoby. Náhled se zpracuje jako úloha na pozadí, ale
až tlačítko **Použít změny na N kartách** založí úlohu, která změny zapíše. Její
průběh, případné konflikty verzí a chybové řádky jsou v dialogu i v historii úloh.
Dokončenou hromadnou úpravu lze jednou vrátit akcí **Obnovit**. Obnova sama vytváří
novou úlohu a nejde znovu obnovovat.

#### 37.12.2.2 Údaje skladové karty pro Intrastat

V úpravě uložené skladové karty je záložka **Intrastat**. Údaje z ní se použijí
pro každý vykazovaný pohyb dané položky:

- **Kód kombinované nomenklatury (KN8)** je osmimístný číselný sazebníkový kód
  zboží. Zapisuje se včetně případných nul na začátku.
- **Země původu** je dvoupísmenný kód země, například `CZ` nebo `DE`. Jde o zemi,
  ve které zboží vzniklo nebo bylo podstatně zpracováno, nikoli automaticky o zemi
  dodavatele či odběratele. Číselník obsahuje také `QU` pro neznámý původ a `QV`,
  pokud je znám pouze původ v EU.
- **Čistá hmotnost (kg)** je kladná hmotnost jedné základní měrné jednotky karty.
  Lze ji zadat s přesností na tři desetinná místa. Při exportu se vynásobí
  množstvím skladového pohybu. Pro elektrickou energii s KN8 `27160000` export
  automaticky použije povinnou konstantu `0,001`.
- **Doplňková měrná jednotka** je kód jednotky vyžadovaný u příslušného KN8,
  například `PCE`. Nevyžaduje-li KN8 doplňkovou jednotku, nechte pole prázdné. Kód
  vyberte podle aktuální kombinované nomenklatury. `ZZZ` se ukládá bez koeficientu
  a do CSV se pro něj uvede nula.
- **Množstevní koeficient** určuje počet doplňkových jednotek na jednu základní
  měrnou jednotku karty. Lze jej zadat s přesností na šest desetinných míst.
  Doplňková jednotka a koeficient se kromě kódu `ZZZ` vyplňují nebo mažou vždy
  společně.

KN8, země původu a čistá hmotnost mohou na kartě zůstat prázdné, dokud se položka
nemá vykazovat. Jakmile její pohyb vstoupí do Intrastatu, náhled chybějící povinný
údaj označí jako chybu a nedovolí stáhnout CSV.

Editor při odchodu ze stránky upozorní na neuložené změny. Prázdná připravená
čeština ani prázdné řádky aktivních prodejních měn se za změnu nepovažují. Lišta
**Uložit / Zrušit** zůstává při posouvání stránky dostupná. Záložky editoru lze
ovládat klávesami šipka vlevo a šipka vpravo, aktivní záložka se na úzké obrazovce
posune do viditelné části lišty.

#### 37.12.2.3 Detail karty a skladová kniha

Kliknutím na řádek v seznamu otevřete **detail karty**: dlaždice s počátečním
stavem, prodejní cenou, minimální zásobou a EAN, pod nimi záložka **Pohyby**, tedy
kompletní **skladová kniha** karty (datum, doklad s prokliknutím, sklad, množství
se znaménkem, jednotková cena, hodnota a **běžná bilance** po každém řádku).
Stránka pohybů se natahuje po 100 řádcích (lze vyžádat až 500 najednou), počáteční
bilance před zobrazenou stránkou se dopočítává ze všech předchozích řádků se stejnými
filtry. Stornované doklady se v knize zobrazí ztlumeně, ale zůstávají viditelné.

Sloupec **Faktura / partner** ukazuje, co pohyb vyvolalo: u výdeje k faktuře
a vratky k dobropisu číslo dokladu s proklikem a odběratele, u příjmu z přijaté
faktury číslo faktury dodavatele a dodavatele, u ručního dokladu volně zapsaného
partnera. Sloupec **Prodejní cena** má cenu bez DPH z řádku faktury, u ostatních
pohybů je prázdný. Jméno odběratele je z dokladu, tedy takové, jaké bylo při
vystavení. Uživatel bez práva číst vydané, resp. přijaté faktury vidí jen skladový
doklad, bez faktury, partnera a ceny. Oba sloupce jsou i v exportu skladové karty.

Akce v hlavičce detailu: **Nová výdejka** (předvyplní kartu do nového dokladu),
**Upravit**, **Prodeje karty** (otevře sestavu prodejů filtrovanou na tuto kartu,
viz [§ 37.12.8](#37128-skladove-sestavy)), **export do PDF** a **export do XLSX**
(kompletní skladová kniha karty, natažená dávkově po 500 řádcích bez ohledu na
počet pohybů) a **Deaktivovat** (jen je-li karta aktivní).

> [!WARNING]
> Skladovou kartu, která má jakýkoli skladový pohyb, nejde smazat. Pokus o smazání
> skončí hláškou „Skladovou kartu nelze smazat - má skladové pohyby. Deaktivujte ji
> místo mazání.“ a nabídne deaktivaci. Smazat lze jen čerstvě založenou kartu bez
> pohybů.

#### 37.12.2.4 Životní cyklus, duplikace a šablony

Karta může být **Rozpracovaná**, **Připravená** nebo **Vyřazená**. Nová karta
z duplikace nebo šablony vzniká vždy jako neaktivní a nepublikovaný koncept.
Akce **Připravit** ji aktivuje. Akce **Vyřadit** ji deaktivuje a vypne export do
e-shopu. Běžná editace ani import ji potom nemohou znovu publikovat. Historie
skladových pohybů zůstává čitelná a beze změny.

Akce **Duplikovat** otevře dialog, ve kterém zadáte nové SKU a název a výslovně
vyberete přenášené sekce: základní nebo e-shopové údaje, překlady, kategorie,
štítky, parametry, poplatky, ceny a dodavatele. SKU, EAN, skladové pohyby
a zásoby, média, přílohy ani externí identity se nikdy nekopírují. Cenová sekce
přenese nastavení cen, ale cenu znovu vypočítá pro novou kartu. Pokud pro výpočet
chybí náklad nebo odpovídající cenové pravidlo, karta se nevytvoří a aplikace
zobrazí chybu k doplnění cenových podkladů.

V sekci **Šablony skladových karet** na detailu lze stejným výběrem sekcí uložit
pojmenovaný snapshot. Pozdější změna zdrojové karty uloženou šablonu nezmění.
Tlačítko **Použít** vyžádá nové SKU a název a založí samostatný koncept, který lze
před přípravou běžně upravit. Šablony jsou oddělené pro každou firmu a lze je ze
stejné sekce smazat.

#### 37.12.2.5 Vazba na e-shopovou kartu

Skladová a e-shopová karta je v datech **jeden a týž záznam**, ale zobrazení
v e-shopu se neřídí typem karty (Materiál, Zboží, Výrobek). O exportu na e-shop
rozhoduje samostatný příznak **Exportovat do e-shopu** (v editoru na e-shopové
záložce), nezávislý na typu. I materiál nebo výrobek tedy jde nastavit jako
zobrazovaný v e-shopu a naopak běžnou kartu typu Zboží lze mít vedenou jen skladově.
Druhý příznak, **Skladem**, určuje, jestli e-shop pro danou kartu hlídá dostupnost
skladem. Podrobnosti k oběma příznakům a dalším e-shopovým polím (kategorie,
parametry, ceny, dodavatelé) najdete v kapitole [E-shop](38_Eshop.md).

#### 37.12.2.6 Virtuální sety

Karta bez zapnuté volby **Skladem** může být virtuálním setem. Z detailu takové
karty nebo z její úpravy otevřete **Konfigurovat set**. Samotná karta nenese zásobu:
rezervace, výdej a cena pracují se skutečnými komponentami setu.

Konfigurátor obsahuje pevné komponenty a skupiny voleb. Pevná komponenta se přidá
vždy. U skupiny zadáte minimum a maximum vybraných alternativ, například právě jednu
barvu nebo volitelný doplněk. Každá alternativa může mít vlastní množství a příplatek
v každé aktivní prodejní měně. Set může obsahovat jiný set, ale systém odmítne cyklus.

Pro každou aktivní prodejní měnu vyberte režim ceny: součet komponent, součet se
slevou nebo pevnou cenu. Nezávazná kalkulace v pravém panelu vyžaduje měnu, množství
a všechny povinné volby. Vypočte aktuální cenu a rozpad na komponenty. Údaj „od“
ani napsaná pevná cena sama o sobě nenahrazuje tuto kalkulaci: cenu objednávky vždy
určuje její zachycená kalkulace.

Sady lze číst, zakládat a upravovat i přes veřejné API, včetně hromadného
zakládání z PIM nebo e-shopu ([Sady přes API](104_API.md#104919-sady-virtualni-sety)).

#### 37.12.2.7 Kompletace výrobku

Stránka **Kompletace výrobků** převádí komponenty virtuálního setu na vlastní
skladovaný výrobek. Vyberete cílový aktivní výrobek vedený skladem, virtuální set
jako recept, sklad, datum a množství. Před potvrzením stránka ukáže aktuální
dostupnost všech komponent v daném skladu. Jednotkovou cenu výrobku nezadáváte:
hodnota vznikne ze skutečně vydaných komponent.

Kompletace v jediném kroku založí a zaúčtuje výdejku komponent i příjemku výrobku.
Historie vždy odkazuje na oba doklady. Je-li potřeba operaci vrátit, použijte
**Stornovat kompletaci**: stornují se společně oba doklady. Samostatné storno jedné
strany není povolené, aby se nezdvojila nebo neztratila hodnota zásob.

#### 37.12.2.8 Balení

Zboží, které prodáváte nebo nakupujete po kartonech, paletách či balících, dostane
na kartě **balení**, tedy nadřazené jednotky k základní jednotce karty. V editoru
karty na záložce **Obecné** u základní jednotky přidáte řádek balení: kód
z číselníku balení ([Balení](38_Eshop.md#381117-baleni)), poměr „1 KT = 8 ks"
a volitelně **EAN balení**. Jedno z balení můžete zvolit jako **výchozí prodejní
jednotku**, editor faktury ji po výběru karty předvyplní. Karta bez balení se chová
přesně jako dosud.

- Poměr se zadává číslem s nejvýše třemi desetinnými místy a ukládá se jako přesný
  zlomek.
- EAN balení je v rámci firmy jedinečný, nesmí ho nést jiná karta ani jiné balení.
  Našeptávač karet v editoru faktury najde kartu i podle EAN balení a rovnou vybere
  příslušné balení.
- Balení, které už nese jakýkoli řádek vydané nebo přijaté faktury (i koncept),
  nejde odebrat ani mu změnit poměr. Uložení skončí hláškou, že je balení použité.
- Převodní jednotky šarží ([§ 37.12.6](#37126-sklady-vice-skladu)) jsou samostatná
  sada: na faktury se nepromítají a jejich kód nejde použít jako balení.

Detail karty ukazuje balení jen jako přehled s odkazem do editoru.

### 37.12.3 Oceňování zásob

Sklad oceňuje zásoby **váženým aritmetickým klouzavým průměrem** (§ 49 odst. 3
vyhlášky 500/2002 Sb., ČÚS 015 bod 3.6). Při každém příjmu se dopočítá nová průměrná
cena z dosavadní hodnoty skladu a hodnoty přijaté dávky. Výdej se vždy oceňuje
aktuální průměrnou cenou v okamžiku zaúčtování dokladu, ne cenou z minulého příjmu.
Je to jediná podporovaná oceňovací metoda, FIFO ani LIFO modul nenabízí.

Uvnitř se nepočítá s desetinnými čísly, ale s celočíselnou aritmetikou: množství se
drží v **tisícinách kusu** a hodnota v **haléřích**. Haléřová hodnota skladu je vždy
zdrojem pravdy, průměrná jednotková cena (na 6 desetinných míst) je z ní jen
dopočítaná pro zobrazení. Zaokrouhluje se matematicky (na obě strany, ne jen dolů)
vždy jen na hranici haléře při každém jednotlivém pohybu, takže se zaokrouhlovací
chyba v čase nekumuluje. Speciální případ je výdej **celého** zbylého množství karty:
oceňuje se přesně zbývající hodnotou skladu, takže po něm nezůstane žádný haléřový
zbytek a stav klesne přesně na 0 Kč a 0 ks.

Systém **tvrdě zakazuje záporný stav zásob**. Pokus o výdej, převod nebo storno,
který by u jakékoli karty způsobil zápor, skončí chybou „Nedostatek zásob pro výdej“
a doklad se nezaúčtuje. Kontrola proběhne souhrnně za všechny řádky dokladu najednou
(ne na první nedostatkové položce) a vrátí seznam všech karet, kterým množství nesedí,
s požadovaným i dostupným množstvím. Proběhne dřív, než se dokladu přidělí číslo
řady, takže se při chybě žádné číslo „nespálí". Žádnou výjimku ani volbu „povolit
záporný stav" modul nemá. Pokud sklad neodpovídá skutečnosti, srovnejte ho nejdřív
inventurou ([§ 37.12.7](#37127-inventury)) nebo opravnou příjemkou.

> [!TIP]
> Kvůli celočíselné aritmetice v haléřích má oceňování technickou horní mez: jeden
> výdej unese hodnotu skladu zhruba do 10 milionů Kč a zhruba 5 milionů kusů
> současně, hodnota jedné karty smí dosáhnout zhruba 4,6 miliardy Kč. Pro běžný
> provoz malé a střední firmy jsou to meze bez praktického významu.

#### 37.12.3.1 Příklad výpočtu klouzavého průměru

Karta nemá zatím žádný pohyb (stav 0 ks, 0 Kč). Následují dva příjmy a jeden výdej:

| Datum | Doklad | Pohyb | Množství | Jedn. cena | Hodnota pohybu | Stav (ks) | Hodnota skladu | Prům. cena |
|---|---|---|--:|--:|--:|--:|--:|--:|
| 1.3. | PRI-2026-0001 | Příjem | +100 | 50,00 Kč | +5 000,00 Kč | 100 | 5 000,00 Kč | 50,0000 Kč |
| 5.3. | PRI-2026-0002 | Příjem (vč. dopravy 200 Kč) | +50 | 60,00 Kč | +3 200,00 Kč | 150 | 8 200,00 Kč | 54,6667 Kč |
| 12.3. | VYD-2026-0001 | Výdej | −80 | 54,6667 Kč (dopočteno) | −4 373,33 Kč | 70 | 3 826,67 Kč | 54,6667 Kč |

- U prvního příjmu je průměrná cena rovnou zadaná jednotková cena (50,00 Kč), protože
  na skladě předtím nic nebylo.
- U druhého příjmu je na řádku zadaná jednotková cena 60,00 Kč (50 ks × 60,00 Kč =
  3 000,00 Kč). K tomu se rozpustí vedlejší pořizovací náklad 200,00 Kč (doprava,
  viz [§ 37.12.4.4](#371244-vedlejsi-porizovaci-naklady)), takže do skladu vstoupí
  celkem 3 200,00 Kč. Nová průměrná cena se počítá z **celkové** hodnoty skladu po
  příjmu (5 000 + 3 200 = 8 200 Kč) děleno celkovým množstvím (100 + 50 = 150 ks)
  = 54,6667 Kč/ks, ne jen z ceny posledního příjmu.
- Výdej 80 ks se ocení aktuální průměrnou cenou 54,6667 Kč/ks, tj. hodnotou
  80 × 54,6667 = 4 373,33 Kč (zaokrouhleno na haléře). Po odečtení zbývá na skladě
  70 ks za 3 826,67 Kč, pořád v průměrné ceně 54,6667 Kč/ks. Výdej nemění jednotkovou
  cenu zbytku, jen úměrně sníží celkovou hodnotu.
- Kdyby se místo 80 ks vydalo **všech** zbývajících 70 ks (po předchozím výdeji),
  poslední výdej by se ocenil přesně zbývající hodnotou skladu bez zaokrouhlovacího
  zbytku a stav by klesl přesně na 0 ks a 0,00 Kč.

### 37.12.4 Skladové doklady

`Sklad → Příjemky a výdejky` je seznam všech skladových dokladů se záložkami
**Vše**, **Příjemky**, **Výdejky** a **Převodky** (stránkovaný, nejvýše 500 řádků
na stránku). Filtry: sklad, stav a fulltext. Sloupce zahrnují číslo dokladu, datum,
typ, sklad (u převodky „odkud → kam"), partnera, popis, **původ** a **stav**.

Doklad má tři podoby:

- **Příjemka** navýší stav na skladu (nákup, počáteční naskladnění, přebytek
  z inventury).
- **Výdejka** sníží stav na skladu (prodej, spotřeba, manko z inventury).
- **Převodka** přesune zásobu mezi dvěma sklady jedné firmy (zdrojový a cílový
  sklad musí být různé).

#### 37.12.4.1 Životní cyklus dokladu

Doklad prochází stavy **Rozpracován → Zaúčtováno → Stornováno** a přechody jsou
striktně jednosměrné. Ze stavu Zaúčtováno ani Stornováno se doklad nikdy nevrátí
zpátky do rozpracovaného.

- Nově založený doklad je **Rozpracován**. Dá se libovolně upravovat i smazat, ještě
  nehýbe skladem ani nemá přidělené číslo. Pokus upravit nebo smazat doklad, který
  už není rozpracovaný, skončí hláškou „Upravovat lze jen rozpracovaný (draft) doklad.“
  resp. „Smazat lze jen rozpracovaný (draft) doklad.“
- **Zaúčtování** doklad zamkne, přidělí mu **číslo řady** a teprve teď se promítne
  do stavu zásob a skladové knihy karty (viz [§ 37.12.4.2](#371242-cislovani-dokladu)).
  Opakované kliknutí na **Zaúčtovat** u už zaúčtovaného dokladu není chyba, systém
  ho vrátí beze změny (bezpečné proti dvojkliku). Zaúčtovat stornovaný doklad nejde
  („Stornovaný doklad nelze zaúčtovat."). Po zaúčtování už doklad ani jeho řádky
  nejde editovat.
- **Storno** je možné jen u zaúčtovaného dokladu. Storno draftu nebo už jednou
  stornovaného dokladu systém odmítne hláškou „Stornovat lze jen zaúčtovaný doklad.“
  resp. „Doklad už byl stornován.“ Storno založí **protidoklad**, který pohyb otočí
  zpět **beze změny hodnot**: množství, jednotková cena i hodnota řádků se zkopírují
  přesně tak, jak byly na originálu. Otáčí se jen typ dokladu (příjemka ↔ výdejka,
  u převodky se prohodí zdrojový a cílový sklad) a datum protidokladu zůstává shodné
  s originálem. Popisu protidokladu se automaticky předsadí „Storno {číslo originálu}“.
  Volitelný **důvod storna** se připojí za pomlčku. Technicky ho vyplnit nemusíte,
  pro obsah účetního případu ale doporučujeme. Doklad samotný zůstává ve stavu
  Stornováno, historie se nemaže. Storno odmítne i situaci, kdy by protidoklad sám
  způsobil zápor na skladě (typicky storno starší příjemky poté, co mezitím proběhl
  další výdej), a to chybou „Nedostatek zásob“. Odmítne ho také, pokud na cílovém
  skladu právě probíhá inventura: „Na skladu probíhá inventura - dokončete ji před
  zaúčtováním pohybu."

Doklad má i **Původ**, tedy informaci, odkud vznikl: **Ručně**, **Vydaná faktura**,
**Dobropis**, **Přijatá faktura** nebo **Inventura**. Doklady s jiným původem než
„Ručně“ typicky vznikají automaticky (viz [§ 37.12.5](#37125-vazba-na-faktury)
a [§ 37.12.7](#37127-inventury)) a v editoru se u nich zobrazí odkaz na zdrojový doklad.

#### 37.12.4.2 Číslování dokladů

Číslo dokladu má formát **`PRI-RRRR-NNNN`** (příjemka), **`VYD-RRRR-NNNN`** (výdejka)
nebo **`PRE-RRRR-NNNN`** (převodka): prefix, rok dokladu a čtyřmístné pořadové číslo.
Číslování běží **odděleně pro každou firmu, každý typ dokladu a každý rok** (rok se
bere z data dokladu, ne z data zaúčtování), takže si každá řada nezávisle začíná
od 0001. Číslo se přidělí teprve při zaúčtování, v téže databázové transakci jako
zápis pohybu. Přidělení je chráněné zamykacím dotazem, aby ani souběžné zaúčtování
dvou dokladů ve stejné vteřině nemohlo vygenerovat duplicitní číslo. Rozdílové
doklady z uzavřené inventury ([§ 37.12.7](#37127-inventury)) čerpají čísla ze
**stejné** běžné řady jako ruční doklady, nemají žádný zvláštní prefix.

#### 37.12.4.3 Editor dokladu

Nový doklad založíte tlačítkem **Nová příjemka** nebo **Nová výdejka** v seznamu.
U nového dokladu bez vazby si typ (Příjemka, Výdejka, Převodka) přepnete přímo
v editoru. Hlavička: **sklad** (u převodky zvlášť „odkud“ a „kam“), **datum dokladu**,
**popis** (povinný, jde o obsah účetního případu podle § 11 odst. 1 písm. c) zákona
o účetnictví) a nepovinný **partner** (volný text, dodavatel nebo odběratel).

**Řádky dokladu**: skladová karta (našeptávač podle SKU a názvu), **množství**,
u příjemky i **jednotková cena** (u výdejky a převodky se cena nezadává, dopočítá
se z klouzavého průměru při zaúčtování) a poznámka. U výdejky a převodky se u každého
řádku zobrazuje náhled **dostupného množství** na zvoleném skladu. Je jen
informativní, závaznou kontrolu dělá až zaúčtování. Nesedí-li množství, zobrazí se
u řádku hláška ve tvaru „{SKU} - {název}: požadováno {X}, skladem {Y}“.

Akce v hlavičce editoru: **Zaúčtovat** (uloží a rovnou zaúčtuje), **Uložit koncept**,
tisk do PDF a u zaúčtovaného dokladu **Stornovat** (přes dialog s polem **Důvod storna**).

#### 37.12.4.4 Vedlejší pořizovací náklady

U příjemky ve stavu rozpracováno (jen tehdy, po zaúčtování už se položky nedají
přidávat) můžete přidat libovolný počet položek **vedlejších pořizovacích nákladů**
(doprava, clo, provize apod. podle § 49 odst. 1 vyhlášky 500/2002 Sb.): popis, částka
a způsob rozpuštění, buď **podle hodnoty** (výchozí), nebo **podle množství**
přijímaných řádků.

Algoritmus rozpouštění je čistě celočíselný (v haléřích), aby součet rozpuštěných
částí vždy přesně souhlasil se zadanou částkou nákladu:

- Podíl každého řádku se počítá jako částka × (podíl řádku na základně). Základnou
  je buď součet hodnot řádků (u „podle hodnoty“), nebo součet množství (u „podle
  množství“). Výsledek se **zaokrouhlí vždy dolů** na celé haléře.
- Rozdíl mezi zadanou částkou a součtem takto zaokrouhlených podílů (vždy nezáporný,
  typicky pár haléřů) se celý přičte k **jednomu jedinému** řádku, tomu s nejvyšší
  hodnotou. Při shodě hodnot víc řádků vyhrává řádek s nižším pořadím.
- Ve výjimečném případě, kdy je základna nulová (například všechny řádky mají nulovou
  hodnotu i množství), se náklad rozdělí rovnoměrně a případný zbytek po haléřích jde
  postupně na první řádky (ne na řádek s nejvyšší hodnotou).
- Nákladová položka s nulovou nebo zápornou částkou se přeskočí a nerozpouští se
  vůbec.

Rozpuštěná částka se u řádku uloží zvlášť jako informativní **vedlejší náklad**, ale
zvyšuje i celkovou hodnotu, ze které se počítá klouzavý průměr (viz příklad
v [§ 37.12.3.1](#371231-priklad-vypoctu-klouzaveho-prumeru), kde druhý příjem obsahuje
dopravu 200 Kč).

### 37.12.5 Vazba na faktury

#### 37.12.5.1 Automatický výdej při vystavení faktury

Na řádku [vydané faktury](15_Faktura_editor.md) můžete vybrat **skladovou kartu**
a **sklad**, ze kterého se má zboží vydat. Zobrazí se náhled dostupného množství
stejně jako v editoru skladového dokladu.

Je-li zapnutá **automatická výdejka** ([§ 37.12.1](#37121-zapnuti-modulu)), při
vystavení běžné nebo finální faktury s takto napojenými řádky systém automaticky
založí a rovnou zaúčtuje výdejku (původ „Vydaná faktura“), v jedné transakci se
samotným vystavením faktury. Má-li faktura řádky z víc skladů, založí se samostatná
výdejka pro každý sklad. Proforma a daňový doklad k platbě skladem nehýbou, čerpá se
až finální faktura. Storno faktury (interní zrušení) automaticky stornuje i navázanou
výdejku a **dobropis** zboží vrátí zpátky na sklad **v původní pořizovací ceně**
výdeje k faktuře, ke které se dobropis vztahuje.

Datum automatické výdejky nebo vratky odpovídá **DUZP faktury**. Datum vystavení se
použije jen tehdy, když doklad DUZP nemá. Záporný skladový řádek na běžné faktuře se
zpracuje jako vratka na sklad, nikoli jako další výdej. Administrátorské smazání
vystavené faktury před smazáním atomicky stornuje i všechny její automatické
skladové doklady.

Pokud na skladě není dost zboží, vystavení faktury **skončí chybou „Nedostatek zásob
pro výdej"** ještě předtím, než se spotřebuje číslo řady faktury. Faktura zůstane
ve stavu koncept a dá se opravit (jiné množství, jiný sklad, nebo napřed naskladnit).
Na detailu faktury najdete i přehled výdejek a vratek navázaných na daný doklad.

Řádek fakturovaný v **balení** ([§ 37.12.2.8](#371228-baleni)) se vyskladní
v základní jednotce karty: 10 KT při poměru 1 KT = 8 ks vydá 80 ks. Stejně se
přepočítá kontrola dostupnosti před vystavením, vratka z dobropisu, rezervace
i čerpání množstevního stropu akční ceny. Řádek v základní jednotce, v neznámé
jednotce nebo v převodní jednotce šarží se vydá tak, jak je na faktuře (1:1).

Na PDF faktury se u řádku v balení pod jednotkou doplní „(celkem 80 ks)“. Rozpis
vypnete přepínačem **Na PDF faktury rozepsat balení na základní jednotky**
([§ 37.12.1](#37121-zapnuti-modulu)). Bez zapnuté skladové evidence se na PDF
nezobrazuje nikdy.

#### 37.12.5.2 Naskladnění z přijaté faktury

Na detailu [přijaté faktury](23_Prijate_faktury.md) je tlačítko **Přijmout na sklad**,
pokud má alespoň jeden dosud nenaskladněný řádek navázaný na skladovou kartu. Faktura
bez jediné takové vazby tlačítko nenabízí. Akce otevře průvodce naskladněním. Ten
nabídne řádky faktury s vazbou na skladovou kartu a **zbývající množství
k naskladnění**: fakturované množství mínus součet množství, které už bylo
naskladněno dřívějšími zaúčtovanými příjemkami napojenými na tentýž řádek faktury.
Pokus naskladnit víc systém odmítne hláškou „Množství přesahuje zbývající k příjmu
z faktury“ (s tolerancí na zaokrouhlení asi 0,0005 jednotky). Kontroluje se hned při
zakládání konceptu příjemky, ne až při zaúčtování. Pole lze ručně přepsat na menší
množství (částečné naskladnění). K jednotlivým řádkům lze zvolit existující kartu,
napojit jinou nebo rovnou z popisu řádku faktury **založit novou skladovou kartu**.

Volitelně lze zahrnout i **vedlejší pořizovací náklady**. Kandidáti se nabídnou
automaticky: jsou to řádky **téže** přijaté faktury, které nemají napojenou žádnou
skladovou kartu (typicky položka za dopravu vedle položek zboží na jedné faktuře).
Modul nehledá náklady na jiných fakturách stejného dodavatele ani podle textu popisu.
Náklady se rozpustí do ceny přijímaného zboží stejným algoritmem jako v editoru
skladového dokladu ([§ 37.12.4.4](#371244-vedlejsi-porizovaci-naklady)). Po potvrzení
vznikne **rozpracovaná příjemka** (původ „Přijatá faktura“) ve zvoleném skladu a datu.
Tu podle potřeby doplníte a zaúčtujete v `Sklad → Příjemky a výdejky`.

Řádek přijaté faktury v **balení** ([§ 37.12.2.8](#371228-baleni)) průvodce nabídne
v základní jednotce karty: 10 KT po 8 ks znamená 80 ks k naskladnění a pořizovací
cena za kus je hodnota řádku dělená 80. Hodnota řádku se tím nemění.

Pokud se obsah přijaté faktury po dřívějším naskladnění změní (typicky přepsáním
řádků faktury, které vazbu na starou příjemku „osiří“), průvodce na to upozorní,
abyste ověřili, jestli naskladněné množství pořád odpovídá aktuálnímu obsahu faktury.

### 37.12.6 Sklady (více skladů)

Firma může mít libovolný počet **skladů** (žádný horní limit není). Najdete je na
stránce `Sklad → E-shop`, záložka **Sklady**. Ke každému skladu zadáváte **kód**
(povinný, nejvýše 20 znaků, v rámci firmy jedinečný, jinak „Sklad s tímto kódem už
existuje“), **název** (povinný, nejvýše 100 znaků), poznámku a příznaky **Výchozí
sklad** a **Aktivní**. Tabulka u každého skladu ukazuje aktuální **celkovou hodnotu**
zásob.

Výchozí sklad se automaticky předvyplní při zakládání nového skladového dokladu.
Výchozí smí být vždy jen **jeden** sklad: nastavením nového výchozího se příznak
u předchozího sám shodí. Sklad, který má nenulový stav zásob nebo jakékoli skladové
pohyby (na kterémkoli z obou směrů převodky), **nejde smazat**. Pokus o smazání skončí
hláškou „Sklad nelze smazat - má nenulový stav nebo skladové pohyby. Deaktivujte jej
místo mazání." a nabídne deaktivaci.

Sklad lze rozdělit na volitelné **lokace** s vlastním kódem a názvem. Lokace vždy
patří právě jednomu skladu a nelze ji při editaci přesunout jinam. U karet se
sledováním šarží nebo sériových čísel může příjemka, výdejka a převodka určit
zdrojovou a cílovou lokaci. Karta bez zapnutého sledování dál používá běžný stav
skladu a při fakturaci ani při skladovém pohybu nevyžaduje žádnou lokaci.

Na kartě lze zapnout sledování **šarží a expirace** nebo **sériových čísel**.
Zaúčtování sledované karty vyžaduje rozdělit celé množství řádku do alokací.
Sériové číslo představuje vždy jeden nedělitelný kus a je jedinečné v rámci firmy
a karty. Šarže dovoluje množství a volitelné datum expirace. Detail karty ukazuje
aktuální rozpad i historii od příjmu přes převody a výdeje po vratku. Storno použije
přesně stejné identity a množství jako původní doklad.

Převodní jednotky šarží se definují přesným zlomkem vůči základní jednotce karty,
například 12 kusů jako 12/1, a slouží jen k zadání množství v alokacích šarží
a sériových čísel. Systém přijme jen převod, který lze beze zbytku vyjádřit
v tisícinách základní jednotky, nepoužívá plovoucí desetinné zaokrouhlení. Na faktury
se tyto jednotky nepromítají, k tomu slouží balení karty
([§ 37.12.2.8](#371228-baleni)).

### 37.12.7 Inventury

`Sklad → Inventury` slouží k fyzické kontrole skutečného stavu zásob podle
§ 29 a 30 zákona o účetnictví. Založíte ji tlačítkem **Nová inventura**: zvolíte
**sklad**, **datum**, způsob zjištění skutečného stavu a osoby odpovědné za zjištění
stavu a za provedení inventury. Poznámka je nepovinná.

Na daném skladu smí běžet vždy jen **jedna otevřená** inventura (ve stavu Založena
nebo Probíhá sčítání). Dokud ji neuzavřete, další na tentýž sklad založit nejde, a to
bez ohledu na zvolené datum („Na skladu už je rozpracovaná inventura."). Nezávisle na
tom je i v databázi vynucené, že na jeden sklad a den existuje nejvýš jeden záznam
inventury vůbec.

Průvodce inventurou má tři kroky. Mezi založením a sčítáním probíhá příprava
očekávaných stavů na pozadí (Založena, Příprava, Probíhá sčítání, Uzavřena). Dokud
příprava neskončí, nelze zadávat skutečné počty. Její průběh je vidět v detailu
inventury, po chybě lze přípravu opakovat a běžící přípravu zrušit.

1. **Založení.** Inventura je ve stavu **Založena**. Tlačítkem **Zahájit sčítání**
   se pořídí snapshot očekávaných stavů (množství i hodnota) **k rozhodnému datu
   inventury** přehráním skladové knihy. Zahrne karty, které na daném skladu k datu
   inventury někdy byly (měly na něm příjem, výdej nebo převod), včetně vyprodaných
   s očekávaným stavem 0, a také neaktivní karty s nenulovou zásobou k danému dni.
   Karty, které na skladu nikdy nebyly, v inventuře nejsou. Inventura přejde do
   stavu **Probíhá sčítání**. Po dobu sčítání nelze na daném skladu zaúčtovat žádný
   jiný skladový pohyb (příjemku, výdejku ani převodku z něj či do něj). Pokus
   o to skončí hláškou „Na skladu probíhá inventura - dokončete ji před zaúčtováním
   pohybu.“ Totéž platí i pro storno staršího dokladu na tomto skladu.
2. **Sčítání.** U každé položky zadáte **skutečně napočítané množství**. Systém
   průběžně dopočítává **rozdíl** oproti očekávanému stavu (zvýrazněný, pokud není
   nulový). Tlačítko **Převzít očekávané u všech** předvyplní všechny řádky
   očekávanou hodnotou (pro položky, které sedí). Rozepsané počty jde průběžně
   ukládat tlačítkem **Uložit rozpracované**, aniž by se inventura uzavřela. U kladného
   rozdílu se zadává reprodukční pořizovací cena za jednotku. Systém nabídne cenu
   očekávaného stavu nebo poslední známou cenu. Přebytek bez kladné ceny nelze
   uzavřít. Kartu, kterou na skladu najdete navíc a v inventuře není, přidáte polem
   **Přidat kartu nalezenou na skladu** pod řádky. Dostane očekávaný stav 0 a zapíšete
   k ní přebytek.
3. **Rekapitulace.** Po uzavření (tlačítko **Uzavřít**, s potvrzovacím dialogem,
   akci nejde vzít zpět) se zobrazí jen řádky s nenulovým rozdílem. Uzavření
   v jedné databázové transakci vytvoří (podle znaménka rozdílu) jednu souhrnnou
   **rozdílovou příjemku** pro všechny přebytky a/nebo jednu souhrnnou **rozdílovou
   výdejku** pro všechna manka. Obě jsou rovnou zaúčtované, s původem „Inventura“
   a popisem „Inventurní přebytek - inventura #…“ resp. „Inventurní manko -
   inventura #…“. Přebytek se ocení uloženou reprodukční pořizovací cenou, manko se
   ocení standardně jako běžný výdej (klouzavým průměrem v okamžiku zaúčtování).
   Číslo dokladu čerpají ze **stejné** řady jako ruční doklady
   ([§ 37.12.4.2](#371242-cislovani-dokladu)), žádný zvláštní prefix pro inventuru
   neexistuje. Inventura přejde do stavu **Uzavřena** a už se nedá znovu otevřít.
   Z rekapitulace se dá proklikat na vzniklé rozdílové doklady. Uzavřenou inventuru
   lze vytisknout jako **inventurní soupis PDF**. Obsahuje rozhodný den, okamžik
   zahájení a ukončení, způsob zjištění, odpovědné osoby, všechny řádky a podpisové
   záznamy.

> [!TIP]
> Pokud v kroku sčítání jen uložíte rozpracovaný stav a odejdete, inventura zůstává
> ve stavu „Probíhá sčítání“. Nezapomeňte se k ní vrátit a **uzavřít** ji, jinak bude
> blokovat zaúčtování ostatních dokladů na daném skladu.

Pro průběžné kontroly slouží na stejné stránce **cyklické inventury**. Příprava
běží jako trvalá úloha a uloží přesný seznam dokladů zaúčtovaných v okamžiku založení.
Koncept zaúčtovaný až později se do tohoto snapshotu nedostane. Běžný provoz skladu
může pokračovat. Fyzický stav proto zadávejte podle skutečnosti **v okamžiku uložení
počtu**. Uložení pod zámkem zachytí i aktuální evidenční stav každého řádku
a uzavření zaúčtuje rozdíl mezi těmito dvěma uloženými hodnotami. Pohyby provedené
po uložení počtu tak zůstanou zachované. Opakované uložení stejného počtu zachová
původní referenci i čas sčítání, změna počtu zaznamená nové sčítání. Lze inventarizovat
celý sklad nebo konkrétní lokaci. U sledovaných karet vznikají řádky po šaržích
a sériových číslech a manko za celý sklad se odečte ze skutečných lokací, na kterých
je šarže vedena. Cyklická inventura celého skladu zahrne jen karty, které na něm
někdy byly. Nalezenou kartu mimo ně zapíšete běžnou inventurou (pole **Přidat kartu
nalezenou na skladu**).

### 37.12.8 Skladové sestavy

`Sklad → Sestavy` nabízí tři záložky:

- **Stav zásob k datu** ukazuje aktuální množství, průměrnou cenu a hodnotu po
  jednotlivých kartách a skladech k okamžiku zobrazení. Řádky pod nastaveným minimem
  se zvýrazní. Filtr je jen na sklad.
- **Ocenění k datu** poskytne přehled **k historickému datu**. Výpočet běží jako úloha
  na pozadí a zobrazuje průběh. Hotové výsledky lze stránkovat. Opakovaný výpočet
  využívá uložené snapshoty, změna skladových pohybů dotčené snapshoty zneplatní.
- **Prodeje** viz [§ 37.12.8.1](#371281-prodeje-skladovych-karet).

Stav zásob a ocenění mají **součtový řádek** (počet položek a celková hodnota) a jdou
exportovat do **PDF** i **XLSX**. Každý export se zaznamenává do žurnálu aktivit
firmy (typ, formát, čas, uživatel).

#### 37.12.8.1 Prodeje skladových karet

Záložka **Prodeje** odpovídá na otázky typu „kolik kusů z této kategorie odebral
odběratel za rok, za kolik a které to byly“. Každý řádek je jeden řádek vydané faktury
nebo dobropisu se skladovou kartou: DUZP, doklad s proklikem, odběratel, karta, prodaná
sériová čísla nebo šarže, množství, cena za jednotku a celkem bez DPH.

- **Filtry:** období podle DUZP (výchozí od 1. ledna do dneška), sklad, odběratel,
  kategorie (včetně podkategorií) a hledání v kódu, názvu, textu řádku, prodaném
  sériovém čísle nebo šarži a textovém parametru karty.
- **Co se počítá:** vystavené faktury a dobropisy. Dobropis vždy snižuje tržbu.
  Prodané množství snižuje jen o zboží vrácené na sklad (vratka k dobropisu).
  Dobropis bez vratky, například dodatečná sleva, množství nemění. Koncept,
  stornovaný doklad, zálohová faktura a daňový doklad k platbě se nepočítají.
  Stornovaná výdejka se neukáže, v řádku jsou jen kusy, které opravdu odešly.
- **Sklad:** řádek faktury bez vybraného skladu patří k výchozímu skladu firmy,
  ze kterého ho vydala automatická výdejka.
- **Souhrn** nad tabulkou platí pro celý filtr, ne jen pro zobrazenou stránku: počet
  řádků, prodané množství (když mají všechny řádky stejnou jednotku) a tržbu bez DPH
  po měnách. Částky se nepřepočítávají kurzem, každá měna má svůj součet.
- **Po odběratelích** a po kartách sečte počet dokladů, řádků, množství a tržbu za
  každého odběratele nebo kartu. Kliknutím na řádek souhrnu se zobrazí jeho
  jednotlivé řádky.
- **Export XLSX** obsahuje všechny řádky filtru (nejvýše 20 000) a při seskupení
  i list se souhrnem.

Záložku vidí jen uživatel, který má kromě skladu i právo číst vydané faktury.

### 37.12.9 Skladem, rezervováno, na cestě, u dodavatele

Samotné číslo „skladem“ na otázku „můžu to prodat?“ neodpovídá. Část zásoby už je
vyfakturovaná zákazníkovi a jen fyzicky nevydaná, další kusy jsou objednané u dodavatele
a ještě nedorazily, a dodavatel má ve svém skladu ještě další. Modul proto vede
**čtyři množstevní veličiny** a dvě dopočtené hodnoty.

<!-- cols: 24 40 36 -->
| Veličina | Co znamená | Odkud se bere |
|---|---|---|
| **Skladem** | fyzický stav na skladě | zaúčtované skladové doklady ([§ 37.12.4](#37124-skladove-doklady)) |
| **Rezervováno** | vyfakturováno zákazníkovi, ale ještě nevydáno ze skladu | řádky vystavených faktur bez zaúčtované výdejky ([§ 37.12.9.2](#371292-rezervace)) |
| **Na cestě** | objednáno u dodavatele a ještě nedodáno | objednávky u dodavatele ([§ 37.12.11](#371211-objednavky-u-dodavatele)) |
| **U dodavatele** | kolik toho podle svého ceníku drží dodavatel | nabídky dodavatelů ([§ 37.12.10](#371210-nabidky-dodavatelu-u-dodavatele)) |

Z nich se počítají dvě odvozené hodnoty:

```text
    prodejné            = skladem − rezervováno
    volně k dispozici   = skladem − rezervováno + na cestě
```

> [!WARNING]
> **Zboží na cestě záměrně nezvyšuje to, co se smí nabízet k prodeji.** Do veličiny
> *prodejné*, tedy do čísla určeného e-shopu, se **na cestě nepočítá**. Prodávat něco,
> co ještě nedorazilo, je obchodní riziko (dodavatel nedodá, dodá později, dodá míň)
> a to má firma rozhodnout vědomě, ne aplikace za ni. *Volně k dispozici* je naproti
> tomu **plánovací** číslo pro nákup a rozhodování uvnitř firmy. Tam „na cestě“ smysl
> dává, protože jde o to, kdy zboží bude, ne co se dá slíbit dnes.

**Žádná z těch čtyř veličin se nikam neukládá.** Počítají se v okamžiku dotazu
z objednávek a dokladů. Objednané ani rezervované zboží nemá pořizovací cenu,
nevstupuje do rozvahy, a proto nepatří mezi skladové stavy, ze kterých se dělá
ocenění ([§ 37.12.3](#37123-ocenovani-zasob)) ani inventura
([§ 37.12.7](#37127-inventury)). Objednávka ani rezervace skladovou knihou nehýbe.
Veličiny se počítají v tisícinách jednotky, stejně jako zbytek skladu, takže na nich
nevzniká zaokrouhlovací chyba.

#### 37.12.9.1 Kde je uvidíte

Na **detailu skladové karty** jsou nahoře čtyři dlaždice: **Skladem** (pod ní menším
písmem *Volně k dispozici*), **Rezervováno**, **Na cestě** (dlaždice je proklik na
objednávky dané karty, pod číslem je *Očekáváno {datum}*, tedy nejbližší termín
dodání ze všech otevřených objednávek) a **U dodavatele**.

Karta, která nemá jediný pohyb ani objednávku, ukazuje ve všech čtyřech dlaždicích
**nuly**, ne prázdno. Je to záměr: „nula“ je odpověď, „-“ by byla chyba.

Hodnota **prodejné** vlastní dlaždici nemá. Je to číslo pro strojové odběratele
([REST API](104_API.md) a [MCP server](106_MCP_server.md)), odkud si ho bere e-shop.
Může vyjít i **záporně**: znamená to, že je vyfakturováno víc, než je fyzicky skladem,
a záměrně se to neschovává nulou.

> [!TIP]
> Náhled dostupnosti u řádku faktury a skladového dokladu pracuje pořád s **prostým
> fyzickým stavem**, ne s prodejným množstvím. Rezervace se od něj neodečítají. Je to
> jen informativní nápověda, závaznou kontrolu na zápornou zásobu dělá až zaúčtování.

#### 37.12.9.2 Rezervace

**Rezervaci vytvoří řádek vystavené faktury napojený na skladovou kartu, ke kterému
ještě neexistuje zaúčtovaná výdejka.** Rezervováno je tedy množství, které sice
fyzicky leží ve skladu, ale už je slíbené konkrétnímu odběrateli.

Rezervaci **netvoří** koncept faktury, proforma (zálohová faktura) ani stornovaná
faktura. Storno faktury rezervaci uvolní. Naopak **storno výdejky rezervaci vrátí**:
protidoklad se do součtu započítá se záporným znaménkem, takže se řádek faktury zase
tváří jako nevydaný.

> [!WARNING]
> **Firmy se zapnutou automatickou výdejkou ([§ 37.12.1](#37121-zapnuti-modulu))
> uvidí v rezervacích trvale nulu, a je to správně.** Když se výdejka zakládá
> a účtuje v tomtéž okamžiku jako vystavení faktury, žádné okno mezi „slíbeno“
> a „vydáno“ neexistuje a rezervovat není co. Rezervace mají smysl pro firmy, které
> automatickou výdejku **vypnuly** a zboží vydávají ze skladu ručně (typicky později,
> při expedici). Teprve tam vzniká mezera, ve které by se stejný kus dal prodat
> podruhé.

Rezervace se sčítají **za celou kartu**. Filtr na sklad je jen omezením, ne rozpadem.
U každé karty je k dispozici i rozpad na konkrétní faktury (číslo, odběratel, datum
vystavení, splatnost, množství).

#### 37.12.9.3 Na cestě

„Na cestě“ je součet toho, co je na **otevřených objednávkách** u dodavatelů a ještě
nedorazilo. Za každý řádek objednávky:

```text
    na cestě = max(0, potvrzeno (jinak objednáno) − uzavřený zbytek − přijato)
```

- **Potvrzené množství přebíjí objednané.** Objednáte 10 ks, dodavatel potvrdí 7,
  na cestě je 7, ne 10.
- **Přijaté množství se odečítá.** Z 10 objednaných přijmete 4, na cestě zůstane 6
  a objednávka přejde do stavu *Částečně přijato*.
- **Storno příjemky vrátí zboží zpátky „na cestu".** Protidoklad nese stejnou vazbu
  na řádek objednávky, takže se odečet zruší.
- **Nadměrná dodávka nikdy nedá zápor.** Výsledek je useknutý na nule.
- Řádek objednávky **bez skladové karty** (doprava, služba) do „na cestě“ nevstupuje.

Do „na cestě“ se počítají objednávky ve stavech **Odesláno**, **Potvrzeno**
a **Částečně přijato**. Koncept se nepočítá (ještě to není závazek), stejně jako
Přijato, Uzavřeno a Stornováno.

Firma si může přepnout, že se má počítat **až od potvrzení** dodavatelem (pak se
započítávají jen stavy Potvrzeno a Částečně přijato). Přepínač je v nastavení firmy
jako **Zboží se počítá „na cestě“ od stavu** s volbami **Odeslaná objednávka**
(výchozí) a **Až potvrzená dodavatelem** ([§ 37.12.1](#37121-zapnuti-modulu)).
Je-li pro vás odeslaná, ale nepotvrzená objednávka příliš měkký příslib, přepněte na
potvrzení.

Rozpad „na cestě“ ukáže, ze kterých konkrétních objednávek se číslo skládá (číslo
objednávky, stav, dodavatel, sklad, očekávaný termín, množství), seřazený podle
očekávaného termínu.

#### 37.12.9.4 U dodavatele

Poslední veličina je součet **skladovosti hlášené dodavateli**: sečtou se hodnoty
*Skladem u dodavatele* ze všech **aktivních** nabídek dané karty
([§ 37.12.10](#371210-nabidky-dodavatelu-u-dodavatele)). Nabídka bez vyplněného
množství přispěje nulou.

Je to **cizí, orientační údaj**: nikdo ho neověřuje a aplikace podle něj nic
neblokuje. Slouží k rozhodnutí „má to vůbec smysl objednávat?“ ještě předtím, než
dodavateli zavoláte.

### 37.12.10 Nabídky dodavatelů („u dodavatele“)

`Sklad → U dodavatele` je katalog dvojic **zboží × dodavatel**: kdo dané zboží
nabízí, za kolik, v jaké lhůtě a kolik ho má. Je to podklad pro objednávání
([§ 37.12.11](#371211-objednavky-u-dodavatele)), pro návrh doplnění zásob
([§ 37.12.12](#371212-doplneni-zasob-co-objednat)) i pro cenotvorbu e-shopu, která si
z preferovaného dodavatele bere nákupní cenu (viz
[Nákupní cena](38_Eshop.md#381182-nakupni-cena-cenova-baze)).

Tatáž data se dají editovat i z karty zboží, záložka **Dodavatelé**
([Dodavatelé zboží](38_Eshop.md#38119-dodavatele-zbozi)). Je to jeden a týž záznam, jen
jednou po kartách a jednou přes celý katalog.

#### 37.12.10.1 Pole nabídky

<!-- cols: 28 72 -->
| Pole | Význam |
|---|---|
| **Zboží** (povinné) | skladová karta (SKU + název) |
| **Dodavatel** (povinný) | klient s **rolí dodavatele** v adresáři ([Klienti](18_Klienti.md)) |
| **Kód u dodavatele** | katalogové číslo, pod kterým položku vede dodavatel (nejvýše 80 znaků). Tiskne se na objednávku a páruje se podle něj ceník |
| **Nákupní cena** | cena bez DPH, za kterou od něj nakupujete |
| **Měna** | ISO kód, výchozí `CZK` |
| **Lhůta (dní)** | dodací lhůta |
| **Skladem u dodavatele** | množství, které dodavatel hlásí. Sčítá se do veličiny *U dodavatele* |
| **Dostupnost** | Skladem / Na objednávku / Nedostupné / **Neznámá** (výchozí) |
| **Min. objednávka** | minimální odběr. Doplnění zásob pod něj nikdy nenavrhne méně |
| **Balení** | velikost balení. Objednávané množství se zaokrouhluje **nahoru** na celá balení |
| **Cena platí do** | do kdy ceníková cena platí. Prázdné znamená bez omezení |
| **Hlavní dodavatel** | nejvýš **jeden na kartu**, nastavením se příznak ostatním sám shodí |
| **Aktivní** | vyřazená nabídka zůstane v evidenci kvůli historii, ale nikam se nenabízí |
| **Poznámka** | volný text (nejvýše 255 znaků) |

Ke každé nabídce se navíc eviduje **kdy naposled se změnilo hlášené množství**
a **odkud data pocházejí** (ručně, import ceníku, automatický kanál). Údaj o stáří je
čistě informativní: nabídka nikdy „nevyprší“ sama od sebe a nic se podle stáří
neblokuje.

**Jedna dvojice zboží × dodavatel smí existovat jen jednou.** Pokus přidat druhou
nabídku téhož dodavatele k téže kartě skončí hláškou „Tento dodavatel už u karty
nabídku má - upravte ji.“

Každý zápis nabídky (založení, úprava i smazání) **spustí přepočet prodejních cen**
té karty. U karet s cenovou bází „Ruční“ se totiž prodejní cena odvíjí od nákupní
ceny hlavního dodavatele.

Seznam ukazuje u každé nabídky i **Naši zásobu** (kolik toho máte vy), takže lze
porovnat vlastní stav proti tomu, co drží dodavatel. Filtrovat jde fulltextem (SKU,
název, kód u dodavatele, dodavatel), podle dostupnosti, jen aktivní a jen hlavní
dodavatele.

#### 37.12.10.2 Import ceníku dodavatele

Tlačítko **Import ceníku** nahraje ceník v **XLSX nebo CSV do 2 MB**. U CSV se
oddělovač (`;` nebo `,`) rozpozná z prvního řádku sám a soubor se čeká v UTF-8.
U XLSX se čte **jen první list**, hodnoty se berou tak, jak jsou zapsané (buňka
začínající `=` zůstane textem, vzorec se nevyhodnocuje).

**Sloupce se poznají podle záhlaví**, žádné ruční mapování se nedělá. Názvy se
porovnávají bez ohledu na velikost písmen, diakritiku a oddělovače, takže
`Nákupní cena` i `nakupni_cena` sedí stejně:

| Sloupec | Povinný | Alternativní názvy | Poznámka |
|---|---|---|---|
| `sku` | **ano** | kod, code, katalog | SKU **existující** karty |
| `dodavatel` | ano (nebo `ico`) | vendor, supplier, firma | název dodavatele |
| `ico` | ne | ič, ičo, company_id | má **přednost** před názvem |
| `kod_dodavatele` | ne | vendor_sku | |
| `nakupni_cena` | ne | purchase_price, cena, price | `1 234,50` i `1234.50` |
| `mena` | ne | currency | výchozí `CZK` |
| `dodaci_lhuta_dny` | ne | delivery_days, delivery | |
| `skladem_u_dodavatele` | ne | stock_qty, skladem, mnozstvi | |
| `dostupnost` | ne | availability | skladem / na objednávku / nedostupné / neznámé |
| `min_objednavka` | ne | min_order_qty, moq | |
| `baleni` | ne | package_qty, package | |
| `cena_plati_do` | ne | price_valid_to, valid_to | `31.12.2026` i `2026-12-31` |
| `hlavni_dodavatel` | ne | is_preferred | 1/0, ano/ne |
| `aktivni` | ne | is_active | výchozí 1 |
| `poznamka` | ne | note | |

Chybí-li ve souboru sloupec `sku`, nebo současně `dodavatel` i `ico`, import se
odmítne celý. **Sloupec, který v souboru není, se nemění.** Sloupec, který tam je
a je prázdný, hodnotu **vymaže** (výjimkou jsou měna, hlavní dodavatel a aktivní,
u nich se prázdná hodnota ignoruje).

Chování importu:

- **Identita řádku je dvojice SKU karty × dodavatel**, přesně tak, jak je omezená
  i v datech. Páruje se **jen podle SKU**, nikdy podle EAN.
- **Import nikdy nic nemaže a nikdy nezakládá karty ani dodavatele.** Neznámé SKU je
  chyba řádku („Karta zboží se SKU „X“ neexistuje (založte ji nejdřív).“), stejně tak
  neznámý dodavatel. Má-li víc dodavatelů stejný název, řádek skončí chybou s výzvou
  doplnit sloupec `ico`.
- Stejná dvojice zboží a dodavatel dvakrát v jednom souboru je chyba řádku.
- Existující nabídka se aktualizuje jen v těch polích, která se skutečně liší.
  Beze změny se řádek započítá jako **Beze změny**.
- Po ostrém importu se přepočtou prodejní ceny všech dotčených karet.

**Import je dvoufázový a platí vše, nebo nic.** Nejdřív běží **náhled** (výchozí
zaškrtnuté **Jen náhled (nic nezapisovat)**), který vypíše po řádcích stav **Nová**,
**Změna**, **Beze změny** nebo **Chyba** a u změn i konkrétní `z → na` u každého pole.
Souhrn nahoře ukazuje počty. Ostré tlačítko **Provést import** se objeví, teprve když
je náhled bez jediné chyby. Ostrý běh s chybou nezapíše **nic** a vrátí hlášku „Import
obsahuje chyby - nic nebylo zapsáno."

### 37.12.11 Objednávky u dodavatele

`Sklad → Objednávky` (stránka **Objednávky dodavatelům**) je evidence toho, co jste
u dodavatele objednali a co z toho ještě nedorazilo. Objednávka je jediným zdrojem
veličiny **na cestě** ([§ 37.12.9](#37129-skladem-rezervovano-na-ceste-u-dodavatele))
a zároveň podkladem pro příjemku. Zboží z ní naskladníte i dřív, než přijde faktura.

> [!WARNING]
> **Objednávka není účetní případ.** Dokud nepřejde vlastnictví zboží, nevzniká
> závazek ani zásoba. Objednávka proto **nezakládá žádný zápis v účetním deníku**
> a nemá žádnou kontaci. Do účetnictví (a do ocenění zásob) vstoupí až zaúčtovaná
> příjemka. Sazba DPH na řádku je čistě orientační, aby seděl součet objednávky.
> Objednávka není daňový doklad a nárok na odpočet z ní nevzniká.

#### 37.12.11.1 Životní cyklus objednávky

Objednávka prochází sedmi stavy. Ručně se přepínají jen přechody **Odeslat**,
**Potvrdit**, **Zavřít zbytek**, **Storno** a **Znovu otevřít**. Stavy *Částečně
přijato* a *Přijato* si systém nastavuje sám podle toho, kolik zboží se z objednávky
skutečně naskladnilo.

```text
   ┌─────────┐   Odeslat    ┌──────────┐   Potvrdit   ┌───────────┐
   │ Koncept │─────────────►│ Odesláno │─────────────►│ Potvrzeno │
   └────┬────┘ přidělí číslo└────┬─────┘              └─────┬─────┘
        │ Smazat        „na cestě"│                          │
        ▼                        └───────────┬──────────────┘
     (zmizí)                                 │ zaúčtování příjemky
                                             ▼
                              ┌──────────────────────────┐
                              │    Částečně přijato      │
                              └─────────────┬────────────┘
                                            │ dorazilo všechno
                                            ▼
                                      ┌───────────┐
                                      │  Přijato  │
                                      └───────────┘

   Storno - z konceptu, Odesláno, Potvrzeno     Zavřít zbytek - z Odesláno, Potvrzeno,
   a jen dokud nic nedorazilo:                  Částečně přijato, Přijato:
        ──► Stornováno                               ──► Uzavřeno
            konečný stav, zpět už ne                     ──► Znovu otevřít
```

<!-- cols: 16 22 62 -->
| Přechod | Z jakého stavu | Co se stane |
|---|---|---|
| **Odeslat** | Koncept | Přidělí **číslo řady OBJ** ([§ 37.12.11.3](#3712113-cislovani-a-pdf)), zapíše okamžik odeslání a od té chvíle se zboží počítá jako **na cestě**. Objednávka bez jediného řádku se odeslat nedá. Opakované kliknutí nic nezkazí: vrátí objednávku beze změny a **další číslo nepropálí**. |
| **Potvrdit** | Odesláno (i opakovaně u Potvrzeno) | Zapíše, co dodavatel potvrdil: volitelně jiný **termín** na hlavičce a **potvrzené množství** i termín u jednotlivých řádků. Od té chvíle se „na cestě“ počítá z potvrzeného množství, ne z objednaného. Prázdný termín stávající nepřepíše. |
| *(automaticky)* | Odesláno, Potvrzeno | **Zaúčtování příjemky** navázané na objednávku ji samo přepne na *Částečně přijato* nebo *Přijato*. **Storno příjemky posun vrátí zpět** (Přijato → Částečně přijato → Odesláno či Potvrzeno) a množství se vrátí „na cestu". |
| **Zavřít zbytek** | Odesláno, Potvrzeno, Částečně přijato, Přijato | „Zbytek už nedorazí." Nedodané množství se na každém řádku odepíše jako stornované, takže zmizí z „na cestě“ a doplnění zásob ho zase začne navrhovat k objednání. Koncept se zavírat nedá, ten se maže nebo stornuje. |
| **Storno** | Koncept, Odesláno, Potvrzeno | Zruší celou objednávku. **Odmítne se, jakmile z objednávky existuje jakýkoli příjem**, hláškou „K objednávce už existuje příjem - místo storna uzavři nedodaný zbytek („Zavřít zbytek“)“. Storno je **konečné**. |
| **Znovu otevřít** | Uzavřeno | Vrátí uzavřenou objednávku mezi živé: zruší odepsaný zbytek a stav dopočítá podle toho, kolik se reálně přijalo. **Stornovanou objednávku znovu otevřít nelze.** |
| **Smazat** | Koncept | Smaže objednávku i s řádky. Odeslanou objednávku smazat nejde, ta se stornuje nebo uzavře. |

Upravovat se dá **jen koncept**. Pokus o úpravu odeslané objednávky skončí hláškou
„Upravovat lze jen rozpracovanou (draft) objednávku. Odeslanou objednávku uprav přes
potvrzení nebo uzavření zbytku.“ a v editoru je celý formulář uzamčený.

> [!TIP]
> Do plnění objednávky se počítají **jen řádky napojené na skladovou kartu**. Řádek za
> dopravu nebo službu (bez karty) vstupuje do ceny objednávky, ale ne do „objednáno,
> přijato, zbývá“ a ani do veličiny na cestě.

#### 37.12.11.2 Hlavička a řádky

**Hlavička**: **dodavatel** (povinný, z adresáře klientů, typicky s rolí dodavatele),
**datum objednávky** (povinné), **sklad**, na který se má dodat (povinný, musí být
aktivní), **měna** (povinná), volitelně **očekávané dodání**, **kurz** (zobrazí se
jen u cizí měny a je čistě orientační, ocenění určí až příjemka nebo faktura),
**reference dodavatele** (číslo, pod kterým objednávku vede dodavatel), **poznámka**
(tiskne se do PDF) a **interní poznámka** (do PDF se netiskne).

**Řádek**: skladová karta (nepovinná, bez ní jde o dopravu či službu), vlastní
**sklad** (přebije sklad z hlavičky), **kód u dodavatele**, **popis** (povinný,
prázdný se doplní z názvu karty), **měrná jednotka** (výchozí „ks“), **objednané
množství** (povinné, větší než 0), **cena za jednotku** (nesmí být záporná),
**sazba DPH** (orientační), **očekávané dodání řádku** a poznámka.

Součty **Celkem bez DPH** a **Celkem včetně DPH** v hlavičce se počítají
z **objednaného** množství. Po potvrzení jiného množství dodavatelem se
nepřepočítávají. Řádky v tabulce i v PDF už ale ukazují potvrzené množství, takže se
hlavičkový součet a součet řádků mohou rozejít. Berte ho jako orientační hodnotu
objednávky, ne jako fakturační podklad.

#### 37.12.11.3 Číslování a PDF

Číslo objednávky má formát **`OBJ-RRRR-NNNN`**: prefix, rok z **data objednávky**
a čtyřmístné pořadové číslo. Prefix `OBJ` je výchozí a dá se firmě přenastavit stejně
jako u ostatních řad dokladů.

**Číslo se přiděluje až při odeslání**, ne při založení. Koncepty žádné číslo nemají
(v seznamu i v detailu je u nich text **Koncept**), takže si můžete připravit libovolné
množství rozpracovaných objednávek, aniž byste spálili čísla v řadě. Přidělení je
chráněné zamykacím dotazem. Souběžné odeslání dvou objednávek nemůže vygenerovat
duplicitu a při chybě se číslo nespotřebuje.

Tlačítko **PDF** vytiskne objednávku pro dodavatele (funguje i u konceptu, kde místo
čísla stojí „koncept #…“). PDF obsahuje objednatele a dodavatele s IČ a DIČ, sklad
dodání, datum objednávky, požadovaný termín, referenci dodavatele, měnu, tabulku řádků
(kód u dodavatele, položka, množství, MJ, cena/MJ, celkem, termín), oba součty,
veřejnou poznámku a podpisové řádky **Vystavil / Schválil**. Interní poznámka se do PDF
nedostane.

#### 37.12.11.4 Příjem zboží z objednávky

Tlačítko **Příjem na sklad** (na detailu objednávky ve stavu Potvrzeno nebo Částečně
přijato) otevře dialog, který nabídne řádky se zbývajícím množstvím. Po potvrzení
vznikne **rozpracovaná příjemka** s původem „Objednávka“, navázaná na objednávku
i na jednotlivé její řádky. Skladem to zatím **nehne**. Příjemku zaúčtujete standardně
ve skladových dokladech ([§ 37.12.4.1](#371241-zivotni-cyklus-dokladu)) a teprve tím se
zásoba zvýší a stav objednávky přepočítá.

**Částečné dodávky** jsou normální stav: příjemek z jedné objednávky můžete udělat
kolik chcete, každá odečte svůj díl ze zbývajícího množství.

**Nadměrná dodávka se ve výchozím stavu odmítne.** Pokus přijmout víc, než zbývá,
skončí hláškou „Množství přesahuje zbývající k příjmu z objednávky. Potvrď nadměrnou
dodávku, nebo množství uprav.“ s výpisem dotčených řádků (požadováno, zbývá). Teprve
když v dialogu zaškrtnete **Povolit nadměrné dodání** (zaškrtávátko se objeví, až když
nějaký řádek limit překročí), příjem projde a dotčené řádky objednávky dostanou
natrvalo odznak **Nadměrné dodání**. Objednané množství se přitom nikdy samo nezvyšuje,
v objednávce zůstává to, co jste objednali.

##### Cena je zatím jen odhad

Pořizovací cenu na příjemce systém určuje v tomto pořadí:

1. **Z řádku přijaté faktury** navázaného na řádek objednávky (jednotková cena =
   částka řádku bez DPH ÷ množství). Cena je pak skutečná.
2. **Odhad z objednávky**: cena za jednotku z objednávky přepočtená kurzem
   z hlavičky. Řádek dostane v dialogu i na dokladu příznak **Odhad** a nahoře svítí
   varování „Cena je odhad z objednávky - po doručení faktury přeceňte.“
3. **Ručně přepsaná cena** v dialogu má přednost před obojím.

> [!WARNING]
> Odhadnutá cena **vstupuje rovnou do váženého klouzavého průměru** karty
> ([§ 37.12.3](#37123-ocenovani-zasob)) a tím i do ocenění všech následujících výdejů.
> Není to jen kosmetický údaj, dokud ji neopravíte, má karta špatnou průměrnou cenu.
>
> **Automatické přecenění příjemky po doručení faktury v aplikaci není.** Máte dvě
> cesty: buď nechat příjemku **v konceptu**, dokud faktura nedorazí, a cenu před
> zaúčtováním přepsat (nejlevnější varianta), nebo ji, je-li už zaúčtovaná,
> **stornovat** a přijmout znovu se správnou cenou. Storno je hodnotově neutrální
> a množství se navíc vrátí „na cestu“, takže se objednávka rozpadne zpátky do
> částečně přijatého stavu a příjem jde zopakovat.

#### 37.12.11.5 Seznam objednávek

Sloupce: **Číslo**, **Datum**, **Dodavatel**, **Sklad**, **Očekáváno**, **Objednáno**,
**Přijato**, **Zbývá**, **Celkem bez DPH** a **Stav**. Filtry: fulltext, **stav**
(volba *Otevřené* zahrne koncepty, odeslané, potvrzené i částečně přijaté), sklad,
rozsah data objednávky a „očekáváno do“. Sloupce i hustotu řádků nastavíte stejně
jako u ostatních přehledů.

Na detailu objednávky je pod řádky sekce **Vzniklé příjemky** s prokliky na
jednotlivé skladové doklady.

#### 37.12.11.6 Oprávnění

Čtení seznamu, detailu i PDF stačí běžné skladové oprávnění. Všechny zápisové akce
(založit, upravit, odeslat, potvrdit, zavřít, stornovat, znovu otevřít, smazat,
vytvořit příjemku i hromadné objednání) vyžadují samostatné oprávnění k zápisu
objednávek (kód `stock.orders.write`). Role „skladník“ s právem na skladové doklady
tedy objednávat nemůže, dokud jí právo nepřidáte. **Uživatelé klientského portálu se
k objednávkám nedostanou vůbec**, ani ke čtení, ani když mají skladové právo.

### 37.12.12 Doplnění zásob: co objednat

Doplnění zásob odpovídá na otázku „co a kolik mám doobjednat?". Nejde jen o seznam
karet pod minimem. Z návrhu se odečítá i to, co už je **na cestě**, a přičítá to, co
je **rezervované**.

#### 37.12.12.1 Jak se navržené množství počítá

Postupně, pro každou **aktivní** kartu, která má vyplněnou **minimální zásobu**:

```text
  1) cílová hladina  = minimální zásoba × koeficient      (výchozí koeficient 1,0)
  2) schodek         = cílová hladina − skladem + rezervováno − na cestě
  3) je-li schodek ≤ 0 → kartu nenavrhovat vůbec
  4) zaokrouhlit schodek NAHORU na celá balení hlavního dodavatele
  5) výsledek zvednout aspoň na minimální odběr hlavního dodavatele
```

- **Rezervované se přičítá.** Ty kusy sice fyzicky máte, ale už jsou slíbené někomu
  jinému, takže na doplnění minima nestačí.
- **Na cestě se odečítá.** Bez toho odečtu byste objednali podruhé to, co už je
  objednané.
- **Balení a minimální odběr** se berou z nabídky **hlavního dodavatele**
  ([§ 37.12.10](#371210-nabidky-dodavatelu-u-dodavatele)). Nemá-li karta nabídku, návrh
  zůstane v holém schodku, bez zaokrouhlení.
- Hlavního dodavatele vybírá pořadí **označený jako hlavní → nejnižší nákupní cena →
  nejstarší nabídka**. Ručně označený hlavní dodavatel tedy porazí i levnějšího.

Vedle navrženého množství se u karty ukáže i **schodek** (surové číslo před
zaokrouhlením), aby bylo vidět, kolik z návrhu přidalo balení a minimální odběr.

**Karta bez vyplněné minimální zásoby se nikdy nenavrhne**, stejně jako neaktivní
karta. Zboží, které nakupujete až na zakázku, tímto modulem neobjednáváte. Nastavte
mu minimum, nebo objednávejte ručně ([§ 37.12.11](#371211-objednavky-u-dodavatele)).

#### 37.12.12.2 Příklad

Karta *KAB-230* má minimální zásobu **50 ks**, skladem je **12 ks**, z toho **5 ks**
drží nevydaná faktura, a **20 ks** je na cestě z otevřené objednávky. Hlavní dodavatel
prodává po **balení 24 ks** a jeho minimální odběr je **10 ks**:

| Krok | Výpočet | Výsledek |
|---|---|--:|
| Cílová hladina | 50 × 1,0 | 50 ks |
| Schodek | 50 − 12 + 5 − 20 | **23 ks** |
| Zaokrouhlení na balení | strop(23 ÷ 24) × 24 | 24 ks |
| Minimální odběr | max(24; 10) | **24 ks** |

Návrh tedy zní **objednat 24 ks**, přestože „chybí do minima“ je na první pohled
38 ks (50 − 12). Kdyby se „na cestě“ neodečítalo, návrh by zněl 48 ks a po dodání
obou objednávek by na skladě leželo o 24 ks víc, než je potřeba.

#### 37.12.12.3 Kde návrh najdete

Tlačítko **Doplnění zásob** je v hlavičce seznamu objednávek
([§ 37.12.11.5](#3712115-seznam-objednavek)). Obrazovka **Doplnění zásob** počítá návrh
podle [§ 37.12.12.1](#3712121-jak-se-navrzene-mnozstvi-pocita) a ukazuje u každé karty
sklad, rezervace, zboží na cestě, minimum, navržené množství, hlavního dodavatele
a odhad ceny. Filtrovat jde podle skladu, **Jen pod minimem** a **Koeficientu**
(násobek minimální zásoby, na který se doobjednává). Zaškrtnuté řádky pak tlačítkem
**Vytvořit objednávky** hromadně založíte: vznikne jedna objednávka na dodavatele,
vždy jako koncept, a karty bez dodavatele nebo množství se vypíšou jako přeskočené.
Odeslat objednávku (a tím pustit zboží „na cestu“) můžete až na jejím detailu.
Stejný výpočet je dostupný i přes [REST API](104_API.md) a
[MCP server](106_MCP_server.md).

### 37.12.13 Vychystání, expedice a vratky

Stránka `Sklad → Vychystání a expedice` vede fyzický tok zboží odděleně od fakturace.
Vychystávací úlohu lze založit z konceptu výdejky. Převzetím se koncept atomicky
uzamkne pro tuto úlohu, takže jej už nelze samostatně upravit, smazat ani zaúčtovat
a zásoba se nevydá dvakrát. Pro prodejní objednávku zvolte příslušný zdroj
a identifikátor (UUID) objednávky, nebo otevřete vychystání přímo z jejího detailu.

Na mobilní obrazovce skladník skenuje **SKU nebo EAN**. Každý sken nese vlastní
identifikátor operace, takže opakování stejného požadavku po výpadku nepřidá další
kus. Cizí kód a množství nad požadovaný počet systém odmítne. Oprávněná odchylka
vyžaduje samostatné právo a uvedený důvod.

Z vychystaného množství vzniká jedna nebo více zásilek. Každá má vlastního
dopravce, tracking a vlastní položky. Expedice zásilky vytvoří a zaúčtuje právě
jednu výdejku přes běžnou skladovou knihu. Částečná expedice ponechá zbytek úlohy
otevřený a neodeslané množství dál alokované.

U sledovaných karet před zabalením vyberte konkrétní sériová čísla nebo množství
ze šarží a jejich skutečné lokace. Vratka nabídne jen jednotky z dané zásilky po
odečtení předchozích vratek. Při změně cílového skladu vyberte jeho lokaci znovu.

Vratka se vždy váže na konkrétní expedovanou zásilku a nesmí překročit její
historicky odeslané množství. U každé vratky se zvolí jeden výsledek:

- **Vrátit do prodeje** vytvoří příjemku do aktivního prodejného skladu.
- **Karanténa** vytvoří příjemku do aktivního skladu označeného jako neprodejný.
- **Vyřadit** uloží historickou dispozici bez příjmu zásoby.

Příjem vratky nepřipisuje peníze, nevytváří dobropis a nemění úhradu faktury.
Pokud fyzický tok faktury spravuje objednávka nebo vychystání, její vystavení ani
následný dobropis nevytvoří další automatický skladový pohyb. Fyzickou vratku
zaznamenejte u zásilky. Běžné faktury mimo tento tok používají dosavadní nastavení
automatického výdeje a vratky. Snapshot komponent, sledovaných jednotek, šarží
a sériových čísel zůstává u zásilky a vratky, takže pozdější změna karty nebo složení
setu historii nepřepíše.

Právo **Vychystání a expedice** dovoluje skenovat, balit, expedovat a přijímat
vratky. Právo **Odchylky při vychystání** navíc dovoluje potvrdit výslovně zdůvodněnou
odchylku. Běžné čtení stránky vyžaduje skladové oprávnění.

### 37.12.14 Prodejní objednávky a tvrdé rezervace

Stránka `Prodej → Prodejní objednávky` odděluje obchodní stav, platební stav a stav
expedice. Koncept lze měnit. Potvrzení uloží neměnný snapshot odběratele, cen, slev,
měny, kurzu, režimu cen s DPH a skladových komponent a potom atomicky rezervuje
dostupné množství na konkrétním skladu. Politika **Vše, nebo nic** potvrzení při
nedostatku odmítne. Politika **Povolit částečnou rezervaci** rezervuje dostupnou část
a objednávku zařadí do fronty nedostatků.

Po doplnění skladu použijte na detailu objednávky **Rezervovat zbývající množství**.
Akce přidá pouze dosud chybějící rezervaci. Existující vychystání rozšíří o nové
množství a zachová již odeslané zásilky, zbývající kusy lze odeslat další zásilkou.

Rezervace je tvrdá: další objednávka ani samostatná vychystávací úloha nemůže stejné
množství použít. Sklad musí být aktivní a prodejný. Částečná expedice spotřebuje jen
odeslané množství a zbytek zůstane rezervovaný pro další zásilku. Zrušení nebo
vypršení platnosti uvolní pouze dosud nespotřebovaný zbytek. Hromadné uvolnění
prošlých rezervací běží jako trvalá úloha a obrazovka ukazuje skutečný počet
zpracovaných objednávek.

Akce **Vytvořit fakturu** založí jeden koncept vydané faktury a zachová režim
**Ceny obsahují DPH**. Opakování akce vrátí stejný doklad. Faktura se automaticky
nevystaví a běžná fakturace nezískává žádný nový povinný krok. Platební stav lze
měnit nezávisle a sám o sobě nemění sklad.

Vratka rozlišuje peněžní řešení, například dobropis nebo refundaci, od fyzického
návratu zboží. Naskladnění vráceného zboží se provádí přes zásilku a skladový
protidoklad. Peněžní vypořádání tento pohyb samo nespouští.

### 37.12.15 Intrastat export pro InstatEvo

Pro podání od **1. 1. 2026** se používá aplikace Celní správy **InstatEvo**, která
nahradila InstatDesk a InstatOnline. MyÚčto hlášení samo nepodává. Připraví CSV
v oficiální dvacetisloupcové struktuře pro import do InstatEvo a před stažením
zkontroluje zdrojové doklady i povinné údaje. Postup je v
[§ 37.10](#3710-krok-za-krokem-intrastat).

Kódy vybírejte podle platných číselníků a skutečné povahy vykazovaných obchodů.
Výchozí hodnoty jsou pomůcka, nikoli náhrada za toto posouzení. Systém odmítne kódy,
které nejsou v aktuálním číselníku podporovaném exportem. Statistický znak je číselný
a jeho vazbu na KN8 následně ověří InstatEvo.

Export podporuje standardní věty `ST` a běžný prodej nebo nákup s kódem transakce
`11`, případně přímý obchod se soukromým spotřebitelem s kódem `12`. Vratky,
zpracování, finanční leasing, malé zásilky a další zvláštní pohyby vyžadují odlišná
pravidla pro hodnotu či obsah věty a do tohoto exportu zatím nevstupují. U odeslání
s kódem transakce `12` systém při chybějícím DIČ spotřebitele použije oficiální
zástupný identifikátor `QV123`.

Náhled zobrazí počet řádků, chyby, upozornění, celkovou čistou hmotnost a pro každý
řádek zdrojový doklad, partnera, KN8, zemi původu, hmotnost, doplňkové množství
a fakturovanou hodnotu. Upozornění, například zkrácení dlouhého popisu, stažení
neblokují. Tlačítko **Stáhnout CSV pro InstatEvo** se zpřístupní jen u náhledu bez
chyb.

Export zahrnuje jen **zaúčtované přeshraniční pohyby zboží uvnitř EU**, které jsou
navázané na řádek faktury. Tuzemské pohyby, pohyby vůči zemím mimo EU, rozpracované
doklady a stornované pohyby včetně jejich protidokladů se nevykazují. Převod mezi
vlastními sklady do exportu nevstupuje.

Stát fyzického odeslání nebo určení se přebírá ze země partnera uložené na faktuře.
Náhled na tento předpoklad upozorní. U trojstranného obchodu, kdy se stát partnera
liší od skutečného státu pohybu zboží, je potřeba řádek zkontrolovat v InstatEvo.
Samostatný stát fyzického pohybu se na skladovém dokladu zatím neeviduje.

Fakturovaná hodnota se vykazuje v **CZK**. U faktury v cizí měně proto musí být uložen
kurz do CZK. Hodnota řádku se poměrně přepočítá podle množství konkrétního skladového
pohybu. Fakturační řádky nemají samostatné označení, které by spolehlivě odlišilo
dopravu a jiné vedlejší výdaje od samostatně prodané služby. Export proto automaticky
započítá jen rozpoznané skladové zboží. Pokud faktura obsahuje jakýkoli nenulový řádek
nenavázané služby, dopravy, slevy nebo jiné položky, náhled zobrazí blokující chybu,
i když se víc takových řádků hodnotově navzájem vyruší. Jejich hodnotu do CSV nepřičte.
Takový doklad nelze automaticky exportovat, dokud není fakturovaná hodnota zboží
jednoznačně určitelná. Výkaz je potřeba připravit nebo opravit ručně v InstatEvo.

Pro vytvoření úplného řádku musí být k dispozici zejména:

- platné DIČ vykazující firmy a země jejího sídla,
- země partnera a u odeslání také jeho platné DIČ, případně u transakce `12`
  zástupný identifikátor `QV123` doplněný systémem,
- vazba skladového pohybu na příslušný řádek faktury, jeho množství a hodnotu,
- na skladové kartě platný KN8, země původu a kladná čistá hmotnost,
- u KN8 s doplňkovou jednotkou také její kód a kladný množstevní koeficient,
- měna faktury a u cizí měny kurz do CZK.

Změna období, směru nebo některého kódu zruší předchozí náhled. Před každým stažením
proto vytvořte nový náhled a zkontrolujte, že odpovídá aktuálním parametrům.

### 37.12.16 Omezení a tipy

- Modul podporuje jen **způsob B** účtování zásob (průběžná evidence bez účtování,
  promítnutí do účetnictví až uzávěrkou). Způsob A funkční není, ačkoliv je pro něj
  v datech i posuzovacích pravidlech připravené místo.
- Záporný stav zásob **nejde nijak povolit**. Nedostatek se musí vždy vyřešit
  příjmem nebo inventurou dřív, než doklad, který ho způsobuje, půjde zaúčtovat.
- Dlouhá historie pohybů se zpracovává po dávkách. Příprava inventur a ocenění
  vyžadují běžící plánovač s úlohou `cron-catalog-worker` (viz
  [Po instalaci](05_Po_instalaci.md)).
- Skladová karta typu **Výrobek** se při uzávěrce zaúčtuje na **MD 123 / D 583**,
  při otevření roku se počáteční stav zrcadlově rozpustí.
- Zaúčtovaný doklad se needituje. Jedinou cestou zpět je **storno** (protidoklad),
  ne oprava původního dokladu. Opakované kliknutí na **Zaúčtovat** u už zaúčtovaného
  dokladu ale chybu nehlásí (je to bezpečné proti dvojkliku).
- Zobrazení karty v e-shopu je nezávislé na jejím skladovém typu, řídí ho samostatný
  příznak **Exportovat do e-shopu** ([§ 37.12.2.5](#371225-vazba-na-e-shopovou-kartu)).

#### 37.12.16.1 Co objednávky ještě neumí

Nákupní část modulu záměrně řeší jen evidenci objednaného zboží. Tohle v ní **není**:

<!-- cols: 34 66 -->
| Chybí | Náhradní řešení |
|---|---|
| **Účetní zápis z objednávky** | Není chyba, ale záměr: objednávka není účetní případ. Do deníku vstoupí až zaúčtovaná příjemka. |
| **Párování objednávka ↔ přijatá faktura** | Obrazovka ani akce pro spárování neexistuje. Objednávku a fakturu k sobě dohledáte ručně přes číslo objednávky (pole **Reference dodavatele** a popis příjemky). |
| **Kontrola cenové odchylky** faktura vs. objednávka | Neprovádí se, cenu z faktury porovnejte ručně. |
| **Odeslání objednávky e-mailem** | Tlačítko **Odeslat** znamená „označ za odeslanou“, ne „odešli“. PDF stáhněte a pošlete dodavateli sami. |
| **Automatické přecenění příjemky po doručení faktury** | Nechat příjemku v konceptu, nebo ji po zaúčtování stornovat a přijmout znovu ([§ 37.12.11.4](#3712114-prijem-zbozi-z-objednavky)). |
| **Dropshipping**, objednávání zboží až na zakázku | Doplnění zásob pracuje jen s kartami, které mají **minimální zásobu**. Zboží na objednávku objednávejte ručně. Cenotvorbu pro dropshipping popisuje [kapitola E-shop](38_Eshop.md#381192-dropshipping-zbozi-bez-skladu). |
| **Schvalovací workflow objednávky** | PDF má podpisové pole „Schválil“, ale žádný schvalovací krok v aplikaci není. |
| **Hromadné akce nad existujícími objednávkami** | Hromadně jde jen zakládat (z návrhu doplnění zásob). Odeslat, uzavřít nebo stornovat se musí po jedné. |

> [!TIP]
> Skladovou kartu nemusíte mít vždy založenou dopředu. V průvodci **naskladněním
> z přijaté faktury** ([§ 37.4.2](#374-krok-za-krokem-naskladnit-zbozi)) ji jde
> založit rovnou z popisu řádku faktury, bez nutnosti přecházet napřed na skladové
> karty. A naopak, kartu si můžete založit dlouho předtím, než cokoli koupíte, jen
> s nabídkami dodavatelů, a teprve podle nich se rozhodnout, jestli a od koho
> objednáte.

## 37.13 Související kapitoly

- [E-shop](38_Eshop.md): číselníky, cenotvorba, dodavatelé zboží, balení a integrace.
- [Shoptet](39_Shoptet.md): objednávky a feed zásob pro Shoptet.
- [Vydané faktury](14_Faktury.md) a [Editor faktury](15_Faktura_editor.md): řádky
  napojené na skladovou kartu.
- [Přijaté faktury](23_Prijate_faktury.md): naskladnění z přijaté faktury.
- [Uzávěrka](72_Uzaverka.md): konečný a počáteční stav zásob.
- [Nastavení](96_Nastaveni.md): uložené filtry a předvolby zobrazení.
- [REST API](104_API.md) a [MCP server](106_MCP_server.md): množstevní veličiny
  a doplnění zásob.
- [Po instalaci](05_Po_instalaci.md): plánovač a úlohy na pozadí.
