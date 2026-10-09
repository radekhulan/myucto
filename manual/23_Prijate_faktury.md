# 23. Přijaté faktury (nákupy)

> Návod, jak do MyÚčta zadat doklady od dodavatelů, zkontrolovat jejich daňové
> zařazení, zaplatit je a zaúčtovat. Pro každého, kdo eviduje nákupy firmy: podnikatele,
> účetní i klienta, který předává doklady účetní.

## 23.1 Kdy to potřebujete

- Přišla vám faktura od dodavatele (e-mailem, poštou, v ISDOC) a chcete ji evidovat.
- Dodavatel poslal zálohovou fakturu a později vyúčtovací fakturu a nechcete počítat náklad dvakrát.
- Chcete zaplatit fakturu přes QR kód v mobilní bance, nebo označit, že je uhrazená.
- Potřebujete doklad zaúčtovat do účetního deníku.
- Účetní zpracovává doklady, které klienti nahráli do fronty příchozích dokladů.
- Faktura nemá správné daňové zařazení (odpočet DPH, daňová uznatelnost) a potřebujete ho opravit.

Přijaté faktury jsou doklady, které **dostáváte od dodavatelů**. Peníze z firmy odcházejí.
Oproti vystaveným fakturám se liší takto:

| | Vystavené faktury | Přijaté faktury |
|---|---|---|
| Směr peněz | klient platí vám (příjem) | vy platíte dodavateli (výdaj) |
| Protistrana | zákazník | dodavatel (stejný adresář firem, jiná role) |
| DPH | vybíráte výstupní DPH | odečítáte vstupní DPH |
| Číslování | vaše řada, například `2605001` | číslo dodavatele z originálu a navíc vaše interní číslo, například `PF2605001` (viz [§ 23.11.5](#23115-povinna-pole)) |
| Průběh | koncept → vystavená → odeslaná → zaplacená | koncept → přijatá → zaúčtovaná → uhrazená |
| Schvalování a odesílání | ano, klient potvrdí | ne, doklad jen evidujete |

### 23.1.1 Opakující se agenda

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| průběžně | Zadat nově došlé faktury | `Nákup → Přijaté faktury`, postup v [§ 23.3](#233-krok-za-krokem-zadat-prijatou-fakturu) |
| průběžně | Zpracovat doklady z fronty (portál, e-mail) | `Nákup → Příchozí doklady`, postup v [§ 23.5](#235-krok-za-krokem-zpracovat-prichozi-doklady) |
| před splatností | Zaplatit faktury, hromadně nebo přes QR | [§ 23.6](#236-krok-za-krokem-zaplatit-fakturu), hromadně [Platební příkazy](26_Platebni_prikazy.md) |
| po přijetí a uhrazení | Zaúčtovat doklady do deníku | detail faktury nebo seznam, postup v [§ 23.7](#237-krok-za-krokem-zauctovat-fakturu) |
| po uzávěrce měsíce | Předat doklady účetní | [Export přijatých](24_Export_prijatych.md) |

## 23.2 Než začnete

- **Dodavatel v adresáři.** Můžete ho vybrat ze seznamu, nebo založit přímo ve formuláři
  z ARES podle IČO (viz [Klienti](18_Klienti.md)).
- **Oprávnění.** Nabídka `Nákup → Příchozí doklady` vyžaduje oprávnění k příchozím dokladům,
  **AI import** oprávnění ke skenování přijatých faktur. Zaúčtovat smí jen administrátor nebo účetní.
- **Podvojné účetnictví.** Tlačítko **Zaúčtovat** a filtr **Zaúčtování** vidí jen firmy v režimu podvojného účetnictví.
- **Pokladna.** Platbu hotově z editoru nabídne aplikace jen tehdy, když máte založenou korunovou pokladnu
  (viz [Pokladna](32_Pokladna.md)).
- **Kurz.** U faktury v cizí měně potřebujete kurz k rozhodnému dni. Aplikace ho umí načíst z ČNB.

> [!TIP]
> Samostatné kapitoly k nákupní agendě: [Export přijatých faktur](24_Export_prijatych.md)
> (naše PDF, ISDOC, Pohoda) a [AI extrakce](25_AI_extrakce.md) (import z PDF přes nastaveného poskytovatele AI).

## 23.3 Krok za krokem: zadat přijatou fakturu

### 23.3.1 Z ISDOC, ISDOCX nebo PDF s vloženým ISDOC

1. Otevřete `Nákup → Přijaté faktury` a klikněte na **+ Nová přijatá faktura**.
2. Přetáhněte soubor ISDOC, ISDOCX nebo PDF/A-3 s vloženým ISDOC do **drag and drop zóny** nad formulářem.
3. Aplikace soubor zpracuje bez AI, ověří IČO odběratele, najde nebo založí dodavatele a otevře
   předvyplněný koncept včetně položek, DPH a platebních údajů.
4. Zkontrolujte údaje a uložte.

**Jak poznáte, že je hotovo:** faktura je v seznamu ve stavu **Koncept** a v hlavičce detailu je
štítek **ISDOC** (strojově čitelný originál se uchová jako důkazní stopa).

### 23.3.2 Z běžného PDF nebo fotky

1. Otevřete `Nákup → Přijaté faktury` a klikněte na **+ Nová přijatá faktura**.
2. Přetáhněte PDF nebo fotku (JPG, PNG, WEBP, HEIC) do zóny nad formulářem. Soubor se připraví jako příloha.
3. Vyplňte povinná pole (viz [§ 23.11.5](#23115-povinna-pole)): **Dodavatel**, **Číslo dokladu dodavatele**, **Typ dokladu**,
   **Datum vystavení**, **DUZP**, **Splatnost**, **Měna faktury**.
4. Přidejte položky tlačítkem **+ Přidat položku**. Souhrn dole se přepočítá sám.
5. Uložte. Originál se po prvním uložení automaticky archivuje mimo webroot.

Pro nestrukturované PDF můžete podle oprávnění použít také [AI extrakci](25_AI_extrakce.md).
Klient s právem předávat doklady může místo opisování kliknout na **Uložit a předat účetní**:
originál se jedním krokem uloží do podatelny a objeví se účetní v Příchozích dokladech.

**Jak poznáte, že je hotovo:** faktura je v seznamu ve stavu **Koncept** a PDF je dostupné v detailu
v sekci **Originální PDF od dodavatele**.

> [!TIP]
> ISDOC a ISDOCX se importuje jen při zakládání nové faktury. Dropzone na detailu nebo v editoru existující
> faktury slouží jen k doplnění PDF nebo fotografie, jinak by strukturovaný doklad založil druhou fakturu.

### 23.3.3 Faktura v cizí měně

1. V editoru vyberte **Měnu faktury**.
2. Klikněte na **Načíst z ČNB**. Načte se denní kurz k rozhodnému dni dokladu.
3. Pokud platíte z účtu v jiné měně než je faktura, klikněte na **Platba v jiné měně než měna faktury**
   a vyplňte měnu účtu, kurz a skutečně odeslanou částku (viz [§ 23.11.8](#23118-platba-v-jine-mene-multi-currency)).

**Jak poznáte, že je hotovo:** u faktury je vyplněný kurz k DUZP.

## 23.4 Krok za krokem: zkontrolovat daňové zařazení a přijmout doklad

1. Otevřete koncept a v boxu **Klasifikace** zkontrolujte **Nárok na odpočet DPH**
   (**Plný nárok**, **Bez nároku na odpočet**, **Krácený nárok (poměrný §75)**, **Krácený koeficientem (§76)**)
   a **Daňově uznatelný náklad**.
2. Pokud je dodavatel neplátce, nastavte přepínač **Dodavatel je plátce DPH** podle skutečnosti.
   Aplikace ho vyplní podle ARES a VIES, u neplátce nastaví **Bez nároku na odpočet**.
3. Je-li doklad v režimu přenesené daňové povinnosti, zaškrtněte **Reverse charge** a postupujte podle
   [§ 23.11.10](#231110-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba).
4. U položek vyberte **Druh nákladu** (**Služba**, **Materiál**, **Drobný majetek**, **Drobný nehmotný majetek**,
   **Dlouhodobý majetek**).
5. V boxu **Rekapitulace DPH** zkontrolujte základ a daň za každou sazbu. Pokud doklad dodavatele
   uvádí kvůli zaokrouhlení jiné částky, přepište je podle dokladu.
6. V detailu klikněte na **Označit jako přijaté**.

**Jak poznáte, že je hotovo:** faktura má stav **Přijatá**, dostala vaše interní číslo a nelze ji už
upravit bez administrátorského zásahu.

> [!WARNING]
> Přechod **Přijatá → Zaúčtovaná** je zablokovaný, dokud doklad nemá vyplněné DUZP. Bez DUZP by se doklad
> dostal do podkladů DPH s nejistým obdobím.

## 23.5 Krok za krokem: zpracovat příchozí doklady

1. Otevřete `Nákup → Příchozí doklady`.
2. Doklady do fronty dostanete z klientského portálu, e-mailem nebo je nahrajete sami tlačítkem **Nahrát do fronty**.
3. U dokladu zvolte, jak ho zpracovat: vytěžit přes ISDOC nebo AI, přepsat ručně, odmítnout, nebo klienta požádat o náhradu.
4. Pro více dokladů najednou je zaškrtněte a použijte hromadnou akci, například **Vytěžit označené**.

**Jak poznáte, že je hotovo:** z dokladu vznikl koncept přijaté faktury a originál se přesunul do archivu
(složka **Příchozí doklady / Archiv / rok / měsíc**).

Podrobnosti o frontě, hromadných akcích a odmítnutí jsou v [§ 23.11.3](#23113-prichozi-doklady).

## 23.6 Krok za krokem: zaplatit fakturu

### 23.6.1 Přes QR kód v mobilní bance

1. Otevřete detail nezaplacené faktury s kladnou částkou k úhradě.
2. Klikněte na **Zaplatit pomocí QR**.
3. Naskenujte kód v mobilní bankovní aplikaci a platbu potvrďte.
4. Pokud aplikace nezná účet dodavatele, klikněte na **Rozpoznat účet z faktury**, nebo **Zadat účet ručně**.

**Jak poznáte, že je hotovo:** QR platba je zobrazená se správným účtem, částkou a variabilním symbolem.
Fakturu pak označíte jako uhrazenou až po provedení platby.

### 23.6.2 Označit fakturu jako uhrazenou

1. V detailu klikněte na **Označit jako uhrazené**.
2. V okně zvolte **datum úhrady** (předvyplněno dnešním dnem) a **Způsob úhrady**: **Evidenčně**,
   **Pokladnou**, nebo **Zápočtem** proti zvolenému účtu (viz [§ 23.11.15](#231115-zpusoby-uhrady-prijate-faktury)).
3. Potvrďte.

**Jak poznáte, že je hotovo:** faktura má stav **Uhrazená**, nebo (u částečného zápočtu) vidíte
v detailu pod **K úhradě** částky **Uhrazeno** a **Zbývá uhradit**.

### 23.6.3 Hromadná platba

Více faktur zaplatíte najednou. V seznamu je zaškrtněte a klikněte na **Do příkazu k úhradě**. Postup je
v kapitole [Platební příkazy](26_Platebni_prikazy.md).

### 23.6.4 Platba hotově z pokladny z editoru

1. V editoru faktury nastavte pole **Způsob úhrady** na **Hotově**.
2. Vyberte **Pokladnu**.
3. Uložte fakturu, nebo ji převeďte do stavu **Přijatá**.

**Jak poznáte, že je hotovo:** faktura je ve stavu **Uhrazená** a v pokladně vznikl výdajový doklad
(viz [§ 23.11.13](#231113-zpusob-uhrady-a-platba-hotove-z-pokladny)).

## 23.7 Krok za krokem: zaúčtovat fakturu

1. Otevřete detail faktury ve stavu přijatá, zaúčtovaná nebo uhrazená.
2. Klikněte na **Zaúčtovat** a potvrďte dialog.
3. Pro více dokladů označte faktury v seznamu a klikněte na **Zaúčtovat (N)**.

**Jak poznáte, že je hotovo:** u faktury je účetní ikona **Zaúčtováno** (datum je v tooltipu)
a funguje proklik **Zobrazit v deníku**.

Tlačítko se u dokladu typu **Záloha** nezobrazuje: zálohová výzva není účetní předpis, účtuje se
až její skutečná úhrada. Kromě ručního zaúčtování lze zapnout automatické zaúčtování při přijetí
(viz [§ 96.11](96_Nastaveni.md#969-krok-za-krokem-automaticke-uctovani)).

Pokud zaúčtování selže, ukáže aplikace hlášku (chybějící kurz, uzavřené období, nevyvážený zápis,
chybějící účet v osnově). Tabulka hlášek je v [§ 16.1.3](16_Faktura_PDF.md#16103-zauctovani-do-deniku).

## 23.8 Krok za krokem: spárovat zálohu s vyúčtovací fakturou

Dělejte to, když vám dodavatel poslal nejdřív zálohovou fakturu a po zaplacení vyúčtovací (finální) fakturu.
Bez propojení by se náklad počítal dvakrát.

1. Otevřete detail **finální** faktury.
2. V boxu **Zálohová faktura** klikněte na **Spárovat se zálohou** a vyberte zálohu od stejného dodavatele.
3. Nevyúčtovanou zálohu můžete spárovat i z jejího detailu tlačítkem **Spárovat s fakturou**.

**Jak poznáte, že je hotovo:** v boxu je odkaz na zálohu a tlačítko **Zrušit propojení**. V detailu zálohy
vidíte, kterou fakturou je vyúčtována.

Pravidla propojení a jejich dopad na náklady, daň a DPH jsou v [§ 23.11.16](#231116-propojeni-zalohy-s-vyuctovaci-fakturou-proti-dvojimu-zapocteni).

## 23.9 Krok za krokem: automatický import ze složky (scan inbox)

Tento postup je pro správce. Dodavatelé vám posílají PDF e-mailem nebo máte sdílenou složku dokladů.

1. V konfiguračním souboru `cfg.php` nastavte inbox adresář (viz [§ 23.11.20](#231120-scan-inbox-automaticky-import-z-adresare)).
2. V seznamu `Nákup → Přijaté faktury` klikněte na **Nascanovat inbox**.
3. Po skončení si přečtěte přehled: vytvořeno, přeskočeno, chyby a detail po souborech.

**Jak poznáte, že je hotovo:** z nových souborů vznikly koncepty přijatých faktur.

## 23.10 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Doklad nejde zaúčtovat, přechod na **Zaúčtovaná** je zablokovaný | Chybí DUZP | Doplňte DUZP v editoru a zkuste to znovu |
| Doklad s volbou **Krácený koeficientem (§76)** nejde zaúčtovat ani zahrnout do přiznání | Není nastavený zálohový koeficient pro daný rok | Požádejte administrátora nebo účetní o nastavení koeficientu, viz [Výkazy DPH](41_Vykazy_DPH.md#41138-kraceny-odpocet-76-koeficient) |
| Při vytěžení se objeví hláška s číslem existující faktury, nová faktura nevznikla | Faktura od stejného dodavatele se stejným číslem a datem už existuje | Ověřte existující fakturu, nebo doklad ve frontě odmítněte |
| Při uložení se aplikace ptá, zda doklad uložit i se stejným číslem | Od dodavatele už máte doklad se stejným číslem a datem vystavení | Jde-li o další platbu téhož dokladu, například platebního kalendáře energií, potvrďte uložení. Jinak číslo opravte, ochrana proti omylu tím zůstává |
| Upozornění **Doklad nese DPH, ale nemá klasifikaci** | Sazba, kterou český číselník nezná (například německých 19 %), nebo dodavatel s neurčenou zemí | Zkontrolujte sazby na řádcích a vyberte klasifikaci DPH ručně |
| Po uložení varování, že se kurz nepřenačetl | Kurz zadal člověk, přišel z importu, nebo je ČNB nedostupná | Zkontrolujte kurz a případně ho znovu načtěte tlačítkem **Načíst z ČNB** |
| Upozornění na kombinaci reverse charge a omezeného nároku při zaúčtování | Taková kombinace se automaticky nezaúčtuje | Zaúčtujte doklad ručním zápisem v deníku |
| Tlačítko **Upravit** nebo **Smazat** chybí | Jde o doklad mimo stav Koncept | Smazat lze jen koncept, ostatní doklady stornujte |
| Chybí tlačítko **Zaplatit pomocí QR** | Faktura je zaplacená, nebo je částka k úhradě nulová | QR je jen pro nezaplacené faktury s kladnou částkou |
| Zaplacená faktura má štítek **uhrazeno s rozdílem** | Evidované úhrady nepokrývají celou částku o víc než 1 Kč | V detailu použijte **Vyrovnat zbytek** |
| Při nahrání hláška, že soubor už byl zpracován | Soubor se stejným otiskem v systému už je | Otevřete existující doklad podle čísla faktury v hlášce |
| Při skenu inbox hláška „Koncept z tohoto souboru byl smazán“ | Smazaný koncept si aplikace pamatuje | Chcete-li soubor přesto zpracovat, nahrajte ho ručně |
| Hláška „Nemáte založenou žádnou korunovou pokladnu - doklad zůstane neuhrazený“ | Pro platbu hotově není korunová pokladna | Založte pokladnu v [Pokladně](32_Pokladna.md) |
| Zaúčtování zálohy neproběhlo automaticky | Záloha nebo DDKP s vlastním daňovým dokladem | Postupujte podle hlášky, zúčtování zapište ručním zápisem |

## 23.11 Podrobnosti a pravidla

### 23.11.1 Seznam přijatých faktur

Šipka vlevo rozbalí položky faktury přímo v seznamu. Před popiskem **Popis** je tlačítko **Náhled** i u nezaúčtovaného
dokladu. Otevře stejný boční panel se shrnutím dokladu jako v účetním deníku a načítá se až po kliknutí. U zaúčtovaného
dokladu zobrazí i související účetní zápisy, pokud má uživatel oprávnění ke čtení účetnictví.

Sloupce lze přetahovat myší za záhlaví s tečkovanou ikonou. Barevná čára ukáže, kam se sloupec přesune. Pořadí záhlaví
i buněk se změní společně, také ve víceřádkovém zobrazení. Pořadí se automaticky ukládá do profilu přihlášeného uživatele
pro tento seznam a platí ve všech jeho firmách. V nabídce **Sloupce** je tlačítko **Obnovit pořadí sloupců**, které jedním
kliknutím vrátí původní pořadí a zachová vybrané sloupce, barvy i filtry.

Nabídka **Barvy položek** s ikonou palety umožňuje nastavit vlastní podklad buněk jednotlivých sloupců. Písmo se
automaticky přepne na černé nebo bílé podle kontrastu. Volby se ukládají automaticky pro přihlášeného uživatele, zvlášť
pro každý seznam, a platí ve všech jeho firmách. Tlačítko **Obnovit výchozí barvy** vrátí všechny barvy jedním kliknutím,
šipka u sloupce obnoví jen jeho barvu. Výběr sloupců, filtry a hustota se přitom nemění.

Seznam lze kliknutím na záhlaví sloupce řadit podle údajů dokladu, dodavatele, data či částky. Řazení se uplatní na celý
filtrovaný výsledek před stránkováním. První kliknutí řadí sestupně, druhé vzestupně a třetí vrátí výchozí pořadí.
Křížek v záhlaví tabulky vrátí výchozí řazení. Při vzestupném řazení podle DUZP se v měsíčním pohledu zobrazí nejstarší
měsíce první. Seznam načítá 50 faktur v jedné dávce. Při posunu dolů se u konce seznamu automaticky načte další stránka,
tlačítko **Načíst další** slouží k ručnímu načtení.

Přepínač nad tabulkou volí měsíční skupiny nebo souvislý seznam a nastavení se ukládá pro přihlášeného uživatele.
V měsíčním pohledu zaškrtávací políčko v záhlaví označí pouze zobrazené doklady daného měsíce. V souvislém seznamu zůstává
záhlaví při posunu na očích a tabulka má posuvník na spodním okraji. Měsíční přehled používá běžně posuvné záhlaví.
Na mobilu jsou vybrané údaje v kartách faktur.

#### Sloupce o úhradách

Sloupce **Uhrazeno celkem** a **Zbývá uhradit** ukazují, kolik je z faktury zaplaceno a kolik zbývá, vždy v měně faktury.
Počítají se ze všech úhrad dohromady: banka, pokladna, vzájemný zápočet i zápočet proti účtu. Korunová platba cizoměnové
faktury se přepočte do měny faktury. Faktura označená jako uhrazená bez jakékoli evidované úhrady se bere jako uhrazená
celá, daňový doklad k platbě (DDKP) nic nedluží. Uhrazená faktura, kterou evidované úhrady nepokrývají o víc než 1 Kč
(u cizí měny přepočteno kurzem faktury), má u stavu štítek **uhrazeno s rozdílem** a zbytek zvýrazněný. Všechny takové
faktury najdete filtrem **Uhrazeno s rozdílem**. Haléřový zbytek do 1 Kč dorovnává banka, štítek nedostane. Na mobilu se
u částečně uhrazené faktury zobrazí obě částky přímo v kartě.

#### Volitelné sloupce a sestavy

Nabídka **Sloupce** umožňuje doplnit základ daně, DPH, zůstatek k úhradě, zakázku, datum přijetí, datum předání k úhradě
a oddíl kontrolního hlášení (načítá se z Knihy DPH po zapnutí sloupce a nemá řazení). Lze zapnout také **Rozpad DPH** podle
sazeb a **Účty MD/Dal** ze zaúčtování. Účty zachovávají všechny strany zaúčtování: nejprve nákladové a výnosové, potom
ostatní rozvahové a nakonec účty DPH 343. Volitelné jsou také **Platební var. symbol**, **Forma úhrady**, **Stát protistrany**
z adresy dodavatele a **Poznámka dokladu** z textu nad a pod položkami. **Uhrazeno dne** ukazuje datum úhrady. **Členění DPH**
a **Řádky přiznání DPH** se načítají z Knihy DPH po zapnutí a stejně jako oddíl KH se v seznamu neřadí. **Štítky příloh**
obsahují štítky navázaných dokumentů dostupných uživateli. Uživatel s oprávněním k účetnictví může zobrazit **Poznámky deníku**
a účty MD/Dal. Firma se zapnutými dimenzemi může přidat také sloupec **Dimenze** s hodnotami z hlavičky a položek dokladu.
Podrobnosti se načtou jen při zobrazení příslušného sloupce.

V nabídce **Sloupce** jsou sestavy **Výchozí klient** (dosavadní stručný seznam), **Výchozí účetní** (DPH, předkontace
a oddíl KH) a **Výchozí komplet** (všechny údaje včetně oddílu KH). Po volbě sestavy lze jednotlivé sloupce dále měnit.
Sestava **Výchozí klient** zachovává na desktopu jeden řádek dokladu. Z úhrad zobrazuje **Zbývá uhradit**,
**Uhrazeno celkem** lze zapnout mezi volitelnými sloupci.

#### Zobrazení

Šířka sloupců se přizpůsobuje obsahu a dostupné šířce okna nebo pracovního panelu. Pokud se údaje nevejdou na jeden řádek,
zobrazí se každý doklad jako přehledný blok s vlastními popisky hodnot. Hlavní údaje jsou nahoře a doplňující údaje
na jemném podkladu pod nimi. Sloupce v každém řádku jsou stejně široké a využijí celou šířku. Záhlaví slouží k řazení
a přetahování sloupců. V nabídce **Sloupce** lze přepínačem **Zobrazovat popisky: Ano / Ne** popisky zapnout nebo vypnout.
Bez vlastní volby jsou vypnuté na jednom řádku a zapnuté při rozložení na více řádků. Vlastní volba se ukládá pro přihlášeného
uživatele a tento seznam ve všech jeho firmách. Při vypnutých popiscích se záhlaví rozloží do stejných řádků a šířek jako
hodnoty pod ním, se zapnutými popisky zůstává kompaktní. Přepínač **Hustota** mění výšku řádků i rozestupy víceřádkových
bloků. Kompaktní režim zobrazí více dokladů, prostornější nechává více místa kolem hodnot. Volba se ukládá pro uživatele
a tento seznam.

#### Filtry

Filtr **Neuhrazené k datu** na rozdíl od volby **Pouze neuhrazené** (dnešní stav) ukáže doklady vystavené do zvoleného dne,
u kterých k tomu dni nebyl uhrazen celý závazek. Doklad zaplacený až po tomto dni se proto ve výpisu objeví, i když má dnes
stav **Uhrazená**. Stejná funkce a definice úhrady je i u [vystavených faktur](14_Faktury.md#14114-filtry)
a sedí na [Saldokonto](60_Saldokonto.md). Další filtry: **Zaúčtování** (Vše, Zaúčtováno, Nezaúčtováno, jen podvojné
účetnictví) a **Předání k úhradě** (viz [Platební příkazy](26_Platebni_prikazy.md)). Filtry jdou do adresy stránky
a do uložených filtrů stejně jako u vydaných faktur. Na rozdíl od vydaných faktur seznam přijatých **nemá žádný CSV export**
v seznamu. Doklady předáte přes [Export přijatých](24_Export_prijatych.md) (ZIP, ISDOC, Pohoda, CSV tabulka).

#### Nákladová šablona a pravidlo účtování z detailu

V detailu faktury otevřete rozbalovací menu akcí a zvolte **Vytvořit nákladovou šablonu**. Otevře se stejný plný editor
jako v seznamu šablon. Dodavatel se předvyplní a můžete ho změnit. Přepínačem **Vázat pravidlo na dodavatele** vazbu vypnete,
aby pravidlo platilo obecně podle textu pro všechny dodavatele této firmy. Text položky je vždy povinný, i při zapnuté vazbě.
Můžete upravit i rozsah částky, prioritu a režim použití. Cílový účet nabízí hledání podle čísla i názvu. Uložení šablony
samo nezmění fakturu ani její zaúčtování.

Položka **Vytvořit pravidlo účtování** otevře původní formulář bankovního pravidla s názvem dodavatele, směrem, měnou
a variabilním symbolem. Obsahuje rozsah částky od/do, prioritu, strop automatiky, účty MD/Dal a test na historii.
Neobsahuje pevnou výchozí částku. Pravidlo samo nemění fakturu. Úhrady faktur se nadále párují, nikoli účtují tímto pravidlem.

### 23.11.2 Stavy přijaté faktury

<!-- cols: 22 44 34 -->
| Stav | Význam | Co lze |
|---|---|---|
| **Koncept** | Rozpracovaný doklad, ještě nepotvrzený jako platná faktura | Upravit, smazat, přejít na Přijatá |
| **Přijatá** | Doklad potvrzený jako platný, visí na nezaplacených | Označit jako zaúčtovanou, uhrazenou, stornovat |
| **Zaúčtovaná** | Předaná účetní nebo do účetnictví | Označit jako uhrazenou, stornovat |
| **Uhrazená** | Zaplaceno ručně nebo automaticky z bankovního výpisu | Konečný stav |
| **Stornovaná** | Stornovaný doklad, ponechaný pro audit | Konečný stav |

Smazat jde **jen koncept**. Pro pozdější stavy použijte **Stornovat**, zachová auditní stopu.

Z konceptu je k dispozici **Označit jako přijaté** a **Stornovat**, z přijaté **Označit jako zaúčtované**, **Označit jako uhrazené**
a **Stornovat**, ze zaúčtované **Označit jako uhrazené** a **Stornovat**. Tlačítko **Upravit** je dostupné jen u konceptu.
Po označení jako přijatá je doklad neměnný (kromě administrátorského zásahu u přijaté).

Stav **Zaúčtovaná** a zaúčtování do deníku jsou dvě různé věci. Přechod na stav **Zaúčtovaná** je jen pracovní značka
(doklad je hotový, předán dál). Skutečné zaúčtování do podvojného účetnictví, tedy vznik zápisu v
[Účetním deníku](52_Ucetni_denik.md), je samostatný krok (viz [§ 23.7](#237-krok-za-krokem-zauctovat-fakturu)) a řídí se
vlastním příznakem zaúčtování, ne stavem dokladu. Fakturu můžete mít ve stavu **Přijatá**, ale už zaúčtovanou, nebo
naopak ve stavu **Zaúčtovaná** a v deníku zatím nic.

Přechod **Přijatá → Zaúčtovaná** je zablokovaný, dokud doklad nemá vyplněné DUZP (datum uskutečnění zdanitelného plnění).
Blok platí jen pro nový přechod. Doklady zaúčtované před touto kontrolou (typicky z migrace historie) zůstávají beze změny
a jdou dál normálně uhradit nebo stornovat.

### 23.11.3 Příchozí doklady

Originály čekají na zpracování v `Nákup → Příchozí doklady`. Nejsou to koncepty přijatých faktur: do okamžiku kontroly
nevstupují do nákladů, závazků, cashflow ani evidence DPH. Účetní má u originálu náhled a může jej zpracovat přes
ISDOC nebo AI, přepsat ručně, odmítnout nebo klienta požádat o náhradu.

Do fronty vede několik cest:

<!-- cols: 70 30 -->
| Cesta | Kdo ji použije |
|---|---|
| **Portál → Doklady pro účetní**, dávka až 20 souborů | klient, spontánně |
| Nahrání dokladu k **vyžádanému požadavku** | klient, jako odpověď účetní |
| **Uložit a předat účetní** v editoru přijaté faktury | klient, když se doklad nevytěžil sám |
| **Nahrát do fronty** přímo na stránce Příchozí doklady, až 5000 souborů najednou, nahrávají se postupně a tlačítko ukazuje průběh | účetní u dokladů, které přišly mimo portál (e-mailem, papírově) |
| **Nahrát účtenku** u platby kartou bez dokladu, když není AI nebo vytěžení selže | účetní, doklad je rovnou navázaný na platbu kartou |

Účetní tak nemusí čekat na klienta: co dostane e-mailem nebo naskenuje, vloží do fronty sama a zpracuje to stejným
postupem. Při zapnutých dimenzích jde při nahrání zvolit i středisko (a další dimenze), propíše se do hlavičky
vytěženého dokladu ([§ 114](114_Dimenze.md#114116-dimenze-pri-vytezeni-a-nahrani-dokladu)). Podrobný průchod klientskou stranou
je v [§ 9.8 Klientský portál](09_Klientsky_portal.md#94-krok-za-krokem-predani-dokladu-ucetni).

Originály všech příchozích dokladů (z portálu, e-mailu i nahrané ručně) se ukládají v Dokumentech do složky
**Příchozí doklady / rok / měsíc** podle data převzetí, ne do kořene. Fronta ukazuje všechna podání bez ohledu na to,
ve které podsložce originál je.

**Odmítnutí originál nemaže.** Přepne podání do stavu Odmítnuto a napíše klientovi důvod. Samotný soubor zůstává
v Dokumentech i v auditní stopě. Uklidit frontu i s originálem (omylem nahraná fotka, spam) jde tlačítkem
**Smazat z fronty**. Má vlastní oprávnění *Trvale vyřadit z příchozí fronty* a originál posílá do koše Dokumentů, odkud ho
z disku odstraní až vysypání koše. U dokladu, který si účetní nahrála sama, se zpráva klientovi nevyžaduje.

**Hromadné akce.** Každý doklad v seznamu má vlevo zaškrtávátko a nad seznamem je **Označit vše** / **Odebrat vše**.
U označených dokladů jsou k dispozici akce:

<!-- cols: 30 70 -->
| Akce | Na které doklady se použije |
|---|---|
| **Vytěžit označené** | jen čekající doklady, po dokončení se otevře kontrola vytěžení všech vzniklých faktur |
| **Odmítnout označené** | jen čekající doklady, důvod je povinný, pokud mezi nimi je doklad od klienta |
| **Smazat označené z fronty** | všechny kromě zpracovaných a právě zpracovávaných, vyžaduje stejné oprávnění jako jednotlivé smazání |
| **Přidat dimenze** | čekající doklady a doklady čekající na doplnění, jen při zapnutých dimenzích |

Doklady, které pro akci nemají vhodný stav, se přeskočí a výsledek je uvede. Přidání dimenzí přepíše u dokladů jen zvolené
typy, ostatní dimenze zůstanou. Zvolené hodnoty se při vytěžení propíšou do hlavičky dokladu stejně jako dimenze zadané
při nahrání.

**Doklad, který už v systému je.** Nahrajete-li nebo vytěžíte doklad, ke kterému už existuje přijatá faktura (stejný
dodavatel, číslo a datum vystavení), nová faktura nevznikne. Fronta to ohlásí hláškou s číslem existující přijaté faktury
a doklad ve frontě zůstane vybraný, takže ho můžete ověřit nebo odmítnout. Hromadné vytěžení na konci uvede, kolik takových
dokladů bylo. Nahrání souboru, který už byl zpracován, ukáže číslo faktury, ze které vznikl. Stejná faktura v jiném PDF
existující faktuře nepřepíše PDF a znovu na ni nespustí automatiku.

Po zpracování se originál přesune do složky **Příchozí doklady / Archiv / rok / měsíc**, takže v příchozích zůstává
jen to, co na zpracování čeká. Originál se nemaže, je auditní stopou toho, co klient předal. Je-li to tentýž soubor jako PDF
výsledné faktury, k faktuře se znovu nepřipojuje, doklad ho už má jako své PDF. Jiný soubor (fotka převedená na PDF,
balíček ISDOCX) se k faktuře připojí v panelu **Dokumenty**. Faktura zůstává pro klienta needitovatelná i ve stavu Koncept,
účetní ji může dál opravit a dokončit běžným stavovým postupem. Pokud účetní výsledný koncept smaže, původní podání
se bezpečně vrátí do příchozí fronty k novému zpracování a originál z archivu zpět mezi čekající.

### 23.11.4 Nahrání dokladu: drag and drop, ISDOC a limity

Nad formulářem je zóna pro PDF, fotku, ISDOC nebo ISDOCX:

- Samostatný **ISDOC**, **ISDOCX** nebo PDF/A-3 s **vloženým ISDOC** se zpracuje deterministicky bez AI. Aplikace ověří IČO
  odběratele, vyhledá nebo založí dodavatele, vytvoří předvyplněný koncept včetně položek, DPH a platebních údajů a rovnou
  ho otevře v editoru ke kontrole. Tato cesta je dostupná i klientské roli s oprávněním vytvářet přijaté faktury. Vložený
  ISDOC se načte i z PDF, které vystavitel zamkl proti úpravám (otevře se bez hesla, heslo chrání jen oprávnění), jak to
  dělají například faktury z iÚčta.
- Běžné PDF bez vloženého ISDOC nebo fotka se pouze připraví jako příloha. Pole vyplníte ručně a originál se po prvním
  uložení automaticky **archivuje** mimo webroot. Pro nestrukturované PDF lze podle oprávnění použít
  [AI extrakci](25_AI_extrakce.md).
- **Strojově čitelný originál (ISDOC nebo ISDOCX) se uchová jako důkazní stopa.** U faktur importovaných ze strukturovaného
  zdroje (`.isdoc`, `.isdocx`, nebo ISDOC vložený v PDF/A-3) se vedle vizuálního PDF trvale archivuje i **původní strojový
  doklad**. Pro audit a kontrolu z finančního úřadu má při 10leté archivační lhůtě vyšší hodnotu než PDF render a umožňuje
  zpětnou rekonstrukci dat. V detailu faktury ho stáhnete přes štítek **ISDOC** v hlavičce nebo akci **Zdrojový doklad**
  v menu. Bajty se ukládají tak, jak přišly: `.isdocx` se nerozbaluje (zachová podpis obálky), vložený ISDOC se uloží jako
  vytažené XML a originál se nikdy nepřepíše.

ISDOC a ISDOCX se importuje při **zakládání nové faktury**. Dropzone na detailu nebo při editaci existující faktury slouží
jen k doplnění PDF či fotografie, nový strukturovaný doklad by tam nečekaně založil druhou fakturu. Fotku (JPG, PNG, WEBP,
HEIC) aplikace před archivací převede na PDF. Z `.isdocx` se vedle strojového originálu archivuje i zabalené PDF pro náhled.

**Náhled originálu** je u konceptu s daty vytěženými AI otevřený automaticky. U konceptu založeného ze strukturovaného
zdroje (ISDOC, ISDOCX) zůstává náhled sbalený, data jsou tam přesná. U konceptu vytěženého AI z prostého PDF (nebo fotky)
editor náhled otevře rovnou, ať máte originál na očích při kontrole vytěžených hodnot (sazba DPH, plátcovství dodavatele
apod.). Jakmile doklad jednou potvrdíte (přejde z konceptu dál), náhled se při dalším otevření editoru nevnucuje. Panel
můžete kdykoli sbalit nebo otevřít tlačítkem nad ním. Na široké obrazovce se v detailu i editoru otevře vpravo vedle dokladu
a při posouvání formuláře zůstává viditelný. Na užší obrazovce zůstane pod formulářem.

Limity:

- maximálně 20 MiB na soubor,
- přijímáme PDF, fotku (JPG, PNG, WEBP, HEIC) a ISDOC či ISDOCX (typ souboru se ověřuje na serveru),
- aplikace počítá otisk SHA-256 a deduplikuje doklady, při opakovaném importu se otevře existující doklad.

**Zaokrouhlení „k úhradě".** U dokladů se zaokrouhlením na celé koruny (typicky e-faktury z e-shopů) aplikace převezme
zaokrouhlení přímo z dokladu. Částka **K úhradě** pak sedí na haléř se skutečnou částkou na faktuře (a přesně se spáruje
s platbou v bance). Základ a DPH zůstávají nezměněné (správně pro přiznání DPH a kontrolní hlášení). Zaokrouhlení se vede
jako samostatná položka a promítne se do částky k úhradě, QR platby, platebního příkazu i vygenerovaného PDF (rekonstrukce
**Naše PDF** zobrazí řádek *Zaokrouhlení*).

### 23.11.5 Povinná pole

<!-- cols: 24 76 -->
| Pole | Význam |
|---|---|
| **Dodavatel** | Vyberte ze seznamu nebo začněte psát. Vyhledávání nabídne i firmu vedenou pouze jako odběratel, po úspěšném uložení faktury se jí doplní role dodavatele. Bez hledaného textu se nabízejí jen dodavatelé. Pokud firma v adresáři chybí, klikněte na **+ Vytvořit nového dodavatele** a využijte ARES podle IČO. |
| **Číslo dokladu dodavatele** | Tak, jak je vytištěno na originálu (například `FA-2026-001`). Maximálně 50 znaků. Jedinečné pro dvojici dodavatel a datum vystavení, stejnou fakturu nelze importovat dvakrát. |
| **Naše číslo** | Volitelné. Pokud ho necháte prázdné, vygeneruje se automaticky podle **šablony** při přechodu na stav Přijatá. Výchozí šablona je `{PP}{YY}{MM}{CCC}` (například `PF2602001`), prefix `{PP}` odpovídá daňovému typu (viz [§ 23.11.7](#23117-danova-uznatelnost-a-narok-na-odpocet)): **PF/PN** plný nárok (uznatelný/ne), **KU/KN** krácený §75, **KR/RN** krácený §76, **NU/NN** bez nároku. Počítadlo je za měsíc (přeteče na 4 a více míst nad 999 dokladů). Šablonu změníte v `Nastavení → Číslování faktur → Šablona pro přijatou fakturu` (například `PF-{YYYY}{MM}-{CCCC}` dá `PF-202605-0001`). Při ručním zadání čísla aplikace hlídá kolize a automatický generátor obsazená čísla přeskakuje. |
| **Typ dokladu** | Faktura, Účtenka / paragon, Dobropis, Záloha, Daňový doklad k platbě (pro filtrování v seznamu). |
| **Datum vystavení** | Z faktury. |
| **DUZP** (datum uskutečnění zdanitelného plnění) | Klíčové pro období DPH. Výchozí je datum vystavení. U **reverse charge** se doklad zařazuje do období DPH právě podle DUZP (povinnost přiznat daň vzniká bez ohledu na doručení dokladu). U **pořízení zboží z EU** je DUZP dle § 25 ZDPH **15. den měsíce následujícího po dodání**, pokud doklad nebyl vystaven dříve, editor to připomene nápovědou. |
| **Splatnost** | Z platebních podmínek dodavatele. |
| **Datum přijetí** | Kdy jste doklad fyzicky nebo e-mailem dostali. U ručně zakládaného dokladu je výchozí dnešek. U **importovaného** dokladu se přebírá z dokladu (datum vystavení, jinak DUZP), viz [§ 21.9.14](21_Importy.md#21914-import-prijatych-faktur-pravidla-a-scan-inbox). Firma si může nastavit, že se má místo toho použít den importu. |
| **Datum dodání** | Datum dodání či převzetí uvedené na dokladu (na zahraničním „Leistungsdatum" nebo „date of supply"). Evidenční údaj, DUZP zůstává ve svém poli. U **pořízení zboží z jiného členského státu** je vstupem výpočtu podle § 25 ZDPH: aplikace z něj ověří, že DUZP odpovídá 15. dni měsíce následujícího po dodání (nebo dřívějšímu datu vystavení). Necháte-li pole prázdné, aplikace datum **nedomýšlí**, doklad jen dostane upozornění, že § 25 nelze ověřit. |
| **Měna faktury** | Měna, ve které je doklad vystaven (USD, EUR, CZK a další). |
| **Kurz k DUZP** | Pokud je měna jiná než CZK, **musíte zafixovat kurz**. Tlačítko **Načíst z ČNB** stáhne denní kurz k rozhodnému dni dokladu. Korunový doklad kurz nemá, když měnu přepnete na CZK, kurz i jeho datum se vyprázdní. Viz [§ 23.11.12](#231112-kurz-cizi-meny-a-jeho-prenacitani). |
| **Reverse charge** | Zaškrtněte, pokud je doklad B2B s přenesenou daňovou povinností (pořízení zboží z EU, služby z EU nebo 3. země, tuzemský § 92a). Položkám nastavte **tuzemskou sazbu** (typicky 21 %) a odpovídající klasifikační kód. Daň na dokladu zůstane 0 (dodavatel ji neúčtuje), samovyměření i zrcadlový odpočet dopočítají výkazy DPH. Viz [§ 23.11.10](#231110-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba). |

Datumová pole (vystaveno, DUZP, splatnost, datum přijetí i časové rozlišení u položek) se zadávají ve formátu jazyka
aplikace: česky `d. m. rrrr`, anglicky `mm/dd/yyyy`, stejně jako všude jinde v aplikaci (viz [§ 1.1](01_Uvod.md)).
Ikona v poli otevře kalendář.

**Časové rozlišení položky** (období od-do, ze kterého uzávěrka odloží náklad příštích období na účet 381) zapnete
přepínačem **Časové rozlišení** u rekapitulace DPH a u položky volbou **Časově rozlišit**. Když text položky uvádí období
plnění („pojištění 28. 9. 2026 - 27. 9. 2027", „předplatné 10/2026 - 09/2027", „za rok 2027", „nájem za září 2026"),
editor ho pod položkou rovnou nabídne: **Použít jako časové rozlišení** období doplní a otevře řádek s daty,
**Nepoužívat** návrh u daného textu skryje. Samotné datum v textu (například DUZP) období není a návrh nevyvolá. Při vytěžení
dokladu přes AI i při importu ISDOC se období položky doplní samo, pokud ho doklad u položky uvádí. Zkontrolujte ho
v editoru před přijetím dokladu.

**Datum přijetí a období odpočtu DPH.** U ručně založené (tedy **ne** importované) tuzemské přijaté faktury se datum
přijetí počítá i do určení **období, ve kterém uplatníte nárok na odpočet DPH** (§ 73 odst. 1 písm. a ZDPH, nárok nelze
uplatnit dřív, než doklad fyzicky držíte). Období odpočtu je pozdější z trojice **DUZP, datum vystavení, datum přijetí**.
Typický případ: dodavatel pošle doklad s prosincovým DUZP až v lednu. Pokud datum přijetí ručně nastavíte na leden,
faktura spadne do lednové [Knihy DPH](42_Kniha_DPH.md) i přiznání, ne do prosincové. U faktur **importovaných**
(AI extrakce, ISDOC, iDoklad, Fakturoid, bankovní avízo, scan inboxu) se datum přijetí do tohoto výpočtu nepočítá,
import ho přebírá z dokladu (datum vystavení, jinak DUZP), což není totéž co vědomé posouzení účetní, kdy jste doklad
opravdu drželi. Jakmile na poli něco změníte, stane se z něj vědomé zadání a do výpočtu období odpočtu vstoupí.

### 23.11.6 Položky a druh výdaje

U hodinových služeb lze zadat dobu jako `H:MM` a hodinovou sazbu až na šest desetinných míst, stejně jako u
[vystavené faktury](15_Faktura_editor.md#1596-vykaz-vicepraci). Čas a sazbu přepište podle dokladu dodavatele.
Součty a rekapitulace DPH zůstávají v peněžních částkách. Skladové ceny mají nadále dvě desetinná místa.

Tlačítkem **+ Přidat položku** přidáte řádek. U každého řádku se zadává:

- popis,
- množství (například 1),
- měrná jednotka (ks, hod),
- cena za měrnou jednotku bez DPH,
- sazba DPH (z číselníku, 21 %, 12 %, 0 %),
- volitelně klasifikační kód DPH pro výkazy DPH (sekce Daně, výchozí podle sazby).

Souhrn dole se přepočítá automaticky po každé změně.

#### Druh výdaje po jednotlivých řádcích

U každé položky lze samostatně určit druh výdaje (pole **Druh nákladu**). Tato volba popisuje, co bylo pořízeno, a proto se nevyplňuje jen
jednou za celou fakturu:

<!-- cols: 22 40 38 -->
| Druh | Typické použití | Výchozí účetní směr |
|---|---|---|
| **Služba** | nájem, telefon, poradenství, pojištění | obvykle 518, konkrétní pravidlo může určit jiný účet |
| **Materiál** | spotřební materiál a zboží do spotřeby | obvykle 501 |
| **Drobný majetek** | samostatně evidovaný drobný majetek | obvykle 501 a evidence karty |
| **Drobný nehmotný majetek** | software a jiná nehmotná práva pod hranicí dlouhodobého majetku | obvykle 518 a evidence karty |
| **Dlouhodobý majetek** | pořízení určené k zařazení a odpisování | obvykle 042 |

Doklad tak může mít například jeden řádek jako službu a druhý jako dlouhodobý majetek. Druh výdaje je osa **co položka
je**, výsledný nákladový nebo majetkový účet je osa **kam se účtuje**. Proto například pojistné zůstává druhem Služba,
ale pravidlo může navrhnout účet 548 místo obecného 518. Výslednou kontaci vždy zkontrolujte při zaúčtování dokladu nebo
v Automatu.

U uložených řádků může aplikace zobrazit návrh se zdrojem, jistotou a stručným důvodem. Návrh se do řádku zapíše až
po kliknutí na **Použít**, nejasný nebo rozporný návrh se sám neaplikuje. Ruční volba má přednost a u dobropisu se zachová
věcný druh původního výdaje, při zaúčtování se pouze obrátí strany.

**Ceny s DPH (brutto režim).** Přepínačem **Ceny položek včetně DPH** (u DPH v hlavičce) lze zadat položky **včetně DPH**
(typicky účtenka nebo paragon), takže celková částka sedí na haléř. DPH se pak počítá „shora" koeficientovou metodou
(§ 37 ZDP). Zadání ceny do sloupce „Celkem s DPH" respektuje aktuální režim (nepřepíná ho), jednotková cena se v detailu
i PDF zobrazuje jako netto. Funguje stejně jako u vystavených faktur, viz
[§ 15.2.6](15_Faktura_editor.md#1592-hlavicka). AI import účtenek režim nastaví sám.

### 23.11.7 Daňová uznatelnost a nárok na odpočet

V boxu **Klasifikace** jsou dva nezávislé příznaky, které řídí, jak faktura vstupuje do daňových výkazů:

<!-- cols: 30 34 36 -->
| Příznak | Možnosti | Co ovlivňuje |
|---|---|---|
| **Nárok na odpočet DPH** | Plný nárok, Bez nároku na odpočet, Krácený nárok (poměrný §75), Krácený koeficientem (§76) | evidenci DPH |
| **Daňově uznatelný náklad** | ano, ne | daň z příjmů (DPFO, DPPO) |

**Nárok na odpočet DPH:**

- **Plný** (výchozí) - standardní odpočet, faktura jde do Knihy DPH, DPHDP3 (ř. 40 až 45) i Kontrolního hlášení.
- **Bez nároku** - faktura **vůbec nevstupuje** do evidence DPH (Kniha DPH, DPHDP3, KH), je to jen účetní náklad.
  Typicky reprezentace, osobní spotřeba.
- **Krácený (poměrný §75)** - odpočet jen v poměrné výši (například auto 70 % pro ekonomickou činnost). Po výběru zadáte
  **Odpočet %** a o toto procento se zkrátí základ i daň odpočtu v Knize DPH a DPHDP3 (ř. 40 až 45), zbytek je nedaňová část.
- **Krácený (koeficientem §76)** - pro **společné vstupy** používané zároveň pro plnění s nárokem na odpočet i pro plnění
  osvobozená bez nároku (§ 51), typicky nájem, energie, účetní služby u firem, které mají i osvobozené příjmy (pronájem,
  finanční nebo zdravotní služby). Na rozdíl od §75 se procento **nezadává na dokladu**: je to jeden **koeficient za celou
  firmu a rok**, spočtený z poměru zdanitelných a osvobozených plnění (podrobně
  [Výkazy DPH, Krácený odpočet § 76](41_Vykazy_DPH.md#41138-kraceny-odpocet-76-koeficient)). Než administrátor nebo účetní pro daný
  rok nastaví **zálohový koeficient**, doklad s touto volbou nejde **ani zaúčtovat, ani zahrnout do přiznání**, aplikace
  to odmítne srozumitelnou chybou.

**Daňově uznatelný náklad** řídí pouze daň z příjmů. V podvojném účetnictví se náklad vždy normálně projeví ve výsledku
hospodaření, když je příznak vypnutý, DPFO a DPPO jej přičtou zpět jako nedaňový. Při automatickém zaúčtování aplikace
zachová věcný druh nákladu a použije jeho nedaňovou analytiku: `501.990`, `511.990`, `518.990` nebo `548.990`. Konkrétní
pravidlo či ruční účet má přednost, takže reprezentace zůstane na `513`, sociální náklad na `528` a dar na `543`.
S DPH to nesouvisí (faktura může mít odpočitatelné DPH a být daňově neuznatelná, i naopak).

Oba příznaky jsou vidět i v **detailu** přijaté faktury (box Měna/DPH).

**Zaúčtování i u „Bez nároku", „Krácený (§75)" a „Krácený (§76)".** Zaúčtování přijaté faktury do
[Účetního deníku](52_Ucetni_denik.md) umí zpracovat i doklady s nárokem **Bez nároku** (celá částka včetně DPH jde na
nákladový účet, žádné 343) a **Krácený (§75)** (na účet 343 jde jen poměrná uplatněná část DPH, zbytek DPH jde spolu se
základem do nákladu). U **Krácený (§76)** se do deníku zaúčtuje **celá** DPH na účet 343, stejně jako u plného nároku,
protože krácení koeficientem se jednotlivého zápisu netýká, řeší se souhrnně až v přiznání DPH (ř. 52 a 53, viz
[Výkazy DPH](41_Vykazy_DPH.md#41138-kraceny-odpocet-76-koeficient)). Kombinace **reverse charge** se **současně** omezeným nárokem
(Bez nároku, Krácený §75, Krácený §76) se automaticky nezaúčtuje, takový doklad je nutné zaúčtovat ručním zápisem v deníku.

**Interní číslo se řídí daňovým typem.** Prefix automaticky generovaného interního čísla (viz
[§ 23.11.5](#23115-povinna-pole)) odpovídá těmto dvěma příznakům: **PF/PN** plný nárok (uznatelný/ne), **KU/KN** krácený
§75, **KR/RN** krácený §76, **NU/NN** bez nároku. Když u už očíslované faktury daňové uplatnění **změníte**, přepíše se jen
**prefix** (`PF2602001` dá `NN2602001`), číselná řada `{YYMM}{CCC}` i ručně zadaná čísla zůstanou. Počítadlo je **sdílené
za dodavatele a měsíc napříč všemi prefixy**, takže čísla jsou v rámci měsíce souvislá bez ohledu na daňový typ. Případné
mezery po smazaných konceptech jsou u interního označení neškodné (na rozdíl od vystavených faktur se u přijatých dokladů
souvislá řada nevyžaduje).

#### Rekapitulace DPH dle dokladu (§ 73 ZDPH)

Pod položkami je box **Rekapitulace DPH** se základem a daní **za každou sazbu**. Hodnoty se dopočítají ze řádků, ale pokud
doklad dodavatele uvádí kvůli zaokrouhlení jiný **základ** nebo **DPH**, můžete je **přepsat** přesně podle dokladu. Důvod
je daňový: nárok na odpočet je svázaný s **částkou daně uvedenou na dokladu** (§ 73 odst. 6 ZDPH), proto je primární shoda
s dokladem, ne náš přepočet.

- Přepsat lze základ i DPH, samostatně pro každou sazbu. Ručně upravené pole je zvýrazněné, odkaz **Spočítat automaticky**
  vrátí vypočtenou hodnotu.
- Přepsání se uloží do faktury a promítne se konzistentně do **přiznání DPH, kontrolního hlášení, Knihy DPH** i do **daně
  z příjmů** a daňového optimalizátoru.
- Při **AI importu** se rekapitulace předvyplní automaticky dle dokladu (pro jednu i více sazeb), pokud sedí v toleranci.
- Box se nezobrazuje u **reverse charge** (na dokladu zahraničního dodavatele není česká DPH).

#### Účetní alokace části dokladu

Pokud jedna faktura obsahuje podnikatelskou i přesně oddělitelnou osobní nebo nedaňovou část, klikněte v rekapitulaci
na **Rozdělit odpočet a zaúčtování**. Importované položky ani částky dodavatele se tím nemění. Pro každou sazbu vznikne
podnikatelský řádek a lze přidat další alokaci, například:

- podnikatelská část - plný odpočet, účet 518,
- osobní spotřeba společníka - bez nároku na odpočet, účet 355,
- osobní spotřeba zaměstnance - bez nároku na odpočet, účet 335,
- firemní nedaňový náklad - bez nároku, příslušný nákladový účet.

U osobní části zadejte známou částku **Celkem s DPH**. Základ a DPH se rozdělí podle rekapitulace sazby a podnikatelský
řádek se dopočítá jako zbytek. Součet alokací musí přesně odpovídat rekapitulaci každé sazby, jinak doklad nelze uložit.
Samostatně oddělená osobní položka není poměrný odpočet podle § 75: do evidence DPH vstoupí jen podnikatelská alokace
a příznak „Použit poměr" zůstane **NE**.

Při zaúčtování aplikace vytvoří jeden závazek 321 za celý doklad, ale jednotlivé alokace rozdělí na zvolené účty. Například
kombinace podnikatelské služby a osobní spotřeby společníka se zaúčtuje jako `518 + 343 + 355 / 321`. Účetní alokace nejsou
dostupné u dokladů s reverse charge.

> [!WARNING]
> **Druh výdaje a alokace DPH jsou dvě různé věci.** Druh se volí na položce podle toho, co bylo nakoupeno. Alokace
> rozděluje částku jedné sazby mezi podnikatelské, osobní a nedaňové použití a současně určuje nárok na odpočet. U více
> sazeb musí sedět každá sazba samostatně, aplikace nedovolí uložit rozpad, jehož součet se liší od rekapitulace dodavatele.

#### Dodavatel neplátce DPH znamená bez nároku na odpočet

Pokud je dodavatel **neplátce DPH**, na jeho dokladu žádná DPH není a **není co odpočítat**. Uplatnit odpočet by byla
daňová chyba (neoprávněný odpočet v ř. 40 přiznání nebo v sekci B kontrolního hlášení). MyÚčto proto plátcovství dodavatele
**sleduje a vynucuje**:

- **Zjištění plátcovství** - autoritativně z **ARES** podle IČO (CZ), u zahraničních EU subjektů z **VIES** podle DIČ.
  Ověří se online při výběru nebo editaci dodavatele ve formuláři (výsledek se cachuje 24 hodin).
- **Volba v editoru** - pod zaškrtávátkem **Reverse charge** je přepínač **Dodavatel je plátce DPH**. Nastaví se
  automaticky podle ARES a VIES, ale můžete ho vědomě přepsat.
- **Vynucení u neplátce** - když je dodavatel neplátce, faktura se automaticky nastaví na **Nárok na odpočet = Bez nároku**,
  sazby řádků se vynulují na 0 % a zobrazí se varování. Doklad pak do evidence DPH nevstupuje (je to jen účetní náklad).
  Přepsání je možné.
- **AI import** - extraktor plátcovství ověří a u neplátce (signál „DIČ: Neplátce DPH", nebo žádné DIČ a žádná DPH na
  řádcích, případně ARES) odpočet automaticky zakáže a doplní varování (viz [AI extrakce](25_AI_extrakce.md)).

Plátcovství dodavatele je vidět i ve **výpisu klientů a dodavatelů** jako štítek *Plátce DPH* (viz
[§ 18.1](18_Klienti.md#1871-seznam-klientu)).

**Zpětná oprava existujících dodavatelů.** Jednorázově spusťte `php api/bin/backfill-vendor-vat-payer.php`. Skript podle
ARES a VIES doplní příznak plátcovství a u neplátců opraví už zaevidované přijaté faktury (zakáže odpočet, sazby na 0 %,
**celková částka beze změny**). Výchozí běh je **dry-run** (jen náhled), zápis až s `--apply`.

### 23.11.8 Platba v jiné měně (multi-currency)

Klikněte na **Platba v jiné měně než měna faktury**, pokud máte tento scénář: faktura je v USD (1000 USD), ale platíte ji
z účtu v CZK (banka konvertuje na přibližně 24 500 Kč s 1 až 2% spreadem či poplatkem). V tomto bloku zadáte:

- měnu platebního účtu (například CZK),
- kurz platba na měnu faktury (například 0,0408 USD za CZK, nebo opačně podle rozhraní),
- kolik reálně odešlo z účtu (24 500 CZK).

Aplikace automaticky vypočte:

- **ekvivalent v měně faktury**, pro spárování proti částce k úhradě,
- **kurzový rozdíl** v základní měně (CZK). Záporný je kurzová ztráta, kladný zisk. Zaznamenává se pro reporting a účetně se
  automaticky promítne do správných řádků výkazů DPH.

### 23.11.9 Klasifikace DPH: co doplní aplikace a kdy ji nechá prázdnou

Pole **Klasifikace DPH** v sekci *Klasifikace* můžete nechat prázdné. Kód doplní aplikace při uložení podle sazby, **země
dodavatele**, reverse charge a plátcovství vaší firmy k datu dokladu. Hlavička dokladu kód jen přebírá z řádků, ručně
vybraný kód nikdy nepřepíše. Kompletní tabulka kódů i pravidel je ve [Výkazech DPH](41_Vykazy_DPH.md#411310-automaticke-prirazeni-klasifikace).

Tři situace, kdy zůstane klasifikace **prázdná záměrně**, a aplikace vás na to upozorní nad dokladem:

- **Poplatek orgánu veřejné moci** (soudní, správní, kolek, evropský platební rozkaz, `Gerichtskosten`, `court fee`). Orgán
  při výkonu veřejné správy není osobou povinnou k dani (§ 5 odst. 4 ZDPH), takže plnění není předmětem daně **ani
  u zahraničního soudu či úřadu**: nesamovyměřuje se podle § 9 odst. 1 a doklad nepatří do přiznání ani do kontrolního
  hlášení. Účetně je to běžný náklad, typicky 538 (Ostatní daně a poplatky). Jde-li přesto o běžnou přijatou službu,
  vyberte klasifikaci ručně.
- **Nulová sazba od tuzemského dodavatele** (osvobozené plnění, nákup od neplátce). Nula sama nerozliší osvobození bez nároku
  od plnění mimo předmět daně, takže kód vybírá účetní.
- **Sazba, kterou český číselník nezná** (například německých 19 %). Cizí daň nelze uplatnit jako odpočet, takže se nepřiřadí
  ani `40`, ani `41`. Upozornění `Doklad nese DPH, ale nemá klasifikaci` říká, že bez zásahu doklad do přiznání ani do KH
  nevstoupí. Zkontrolujte sazby.

**Automatika je pomůcka, ne rozhodnutí.** Daňové zařazení dokladu zůstává na uživateli a jeho účetní, návrh je vždy přepsatelný.

### 23.11.10 Reverse charge z EU: pořízení zboží vs. služba

Typický případ: nákup **zboží od EU dodavatele** (například auto z Německa). Doklad je vystaven **bez DPH** (osvobozené
intrakomunitární dodání) a daň si samovyměříte v ČR. Správné zaevidování:

<!-- cols: 22 39 39 -->
| Co | Zboží z EU (pořízení z JČS) | Služba z EU nebo 3. země |
|---|---|---|
| **Sazba na řádcích** | tuzemská **21 %** (případně 12 %) | tuzemská **21 %** |
| **Klasifikační kód** | **23** „Pořízení zboží z JČS" | **24** „Přijetí služby" |
| **Přiznání DPH** | ř. 3 (samovyměření) a ř. 43 (odpočet), u majetku navíc ř. 47 | ř. 5 nebo 12 a ř. 43 |
| **Kontrolní hlášení** | sekce **A.2** | - |
| **DUZP** | **§ 25**: 15. den měsíce po dodání, pokud doklad nebyl vystaven dříve | den uskutečnění služby |

Klíčové principy:

- **Sazba 0 % na řádku je chyba**, samovyměření by vyšlo nulové. Sazba na řádku je *nominální* (daň na dokladu zůstává 0,
  částka k úhradě se nemění), výkazy z ní dopočítají samovyměřenou daň i zrcadlový odpočet. Pojistka: pokud řádek
  s klasifikací reverse charge přesto sazbu nemá, výkazy použijí sazbu klasifikačního kódu (21 %).
- **Doklad se do období DPH zařadí podle DUZP.** Povinnost přiznat daň vzniká k DUZP bez ohledu na to, kdy faktura fyzicky
  dorazila (§ 25 odst. 1), a pozdní doklad neblokuje ani odpočet (§ 73 odst. 1 písm. b, nárok lze prokázat jiným způsobem,
  například protokolem o převzetí, smlouvou a platbou). Pozdě vystavená faktura za zboží převzaté v dubnu tak patří do
  **května** (DUZP 15. 5.), ne do měsíce vystavení.
- **Kurz ČNB se váže k DUZP** (§ 4 odst. 8, den vzniku povinnosti přiznat daň).
- **Datum dodání vyplňte.** Je to jediný vstup, ze kterého jde § 25 spočítat. Aplikace z něj DUZP **nepřepíše**, porovná ho
  s tím, co máte zadané, a když se rozejdou, upozorní. Prázdné datum dodání znamená, že § 25 nelze ověřit, a doklad to řekne
  nahlas místo toho, aby si datum domyslel.
- **AI import tohle vše nastaví sám**, viz [AI extrakce](25_AI_extrakce.md).

> [!WARNING]
> U **vybraných osobních automobilů** pohlídejte limit odpočtu dle § 72 (strop základu 2 000 000 Kč a DPH 420 000 Kč).
> Aplikace ho nehlídá.

### 23.11.11 Zaúčtování dobropisu

Přijatý dobropis (typ dokladu **Dobropis**, viz [§ 23.11.5](#23115-povinna-pole)) se umí zaúčtovat do
[Účetního deníku](52_Ucetni_denik.md) automaticky stejně jako běžná faktura. Aplikace pozná opravný doklad (typ Dobropis,
nebo záporná celková částka) a zápis automaticky **otočí strany MD/Dal** a použije absolutní částku, takže výsledný zápis
v deníku je čitelný (kladné částky na správné straně), ne matoucí záporná čísla. Funguje stejně v CZK i v cizí měně.

V editoru dobropisu vyberte v poli **Opravovaná faktura** také opravovanou přijatou fakturu od stejného dodavatele. Vazba je nepovinná, ale zajišťuje
dohledatelnou návaznost v obou detailech a správné promítnutí vráceného drobného majetku a kontrolního hlášení. Jednu
původní fakturu může opravovat více částečných dobropisů. Pokud vazbu nevyplníte, automatika ji nesmí odhadnout podle samotné
podobné částky.

**Zápočet dobropisu s fakturou.** V daňové evidenci se přijatý dobropis s vyplněnou opravovanou fakturou při přijetí
s fakturou automaticky započte, pokud faktura ještě není celá zaplacená: faktuře klesne **Zbývá uhradit** o částku
dobropisu, dobropis je vyrovnaný a dodavateli platíte jen rozdíl, který se spáruje s bankou. Pokryje-li dobropis celou
fakturu, je zaplacená i faktura. V podvojném účetnictví se dobropis automaticky nezapočítává, jen tlačítkem.
Zápočet se dělá jen v plné výši dobropisu. V detailu dobropisu ho zrušíte tlačítkem **Zrušit zápočet**, starší dobropis
započtete tlačítkem **Započíst s fakturou**. Do [peněžního deníku](74_Danova_evidence.md) zápočet nevstupuje, výdajem je
až skutečně zaplacený zbytek. DPH se nemění.

### 23.11.12 Kurz cizí měny a jeho přenačítání

Kurz na dokladu se váže k **rozhodnému dni**, tím je DUZP, a když na dokladu není, datum vystavení. Tentýž den používá
i evidence DPH a přepočet do účetního deníku.

**Když rozhodný den nebo měnu změníte, aplikace kurz sama přenačte**, ale jen tehdy, když ho původně sama odvodila z data.
U dokladu s vyplněným DUZP se změnou data vystavení rozhodný den nemění, takže se v takovém případě nic nepřepočítává.

Podle původu kurzu se rozhoduje takto:

<!-- cols: 55 45 -->
| Původ kurzu na dokladu | Přenačte se? |
|---|---|
| Denní kurz ČNB (tlačítko **Načíst z ČNB**) | **Ano**, k novému datu se načte znovu |
| Pevný kurz období (§ 24 odst. 7 ZoÚ) | **Ano**, použije se pevný kurz nového období |
| Ručně zadaný kurz (vepsaný do pole) | Ne |
| Kurz z dokladu dodavatele nebo z importu (ISDOC, AI extrakce z PDF, iDoklad, Fakturoid) | Ne |
| Doklady z doby před zavedením evidence původu kurzu | Ne (původ není známý) |

Když se kurz nepřenačte, aplikace to po uložení **oznámí varováním** a napíše důvod. Kurz si pak zkontrolujte (a případně
přepište ručně nebo znovu načtěte z ČNB). Stejné varování dostanete, když je ČNB nedostupná: doklad se uloží, kurz zůstane
původní.

Kurz **úhrady v jiné měně** ([§ 23.11.8](#23118-platba-v-jine-mene-multi-currency)) se přenačítáním nikdy nemění. Rozdíl
mezi kurzem předpisu a kurzem úhrady je legitimní kurzový rozdíl, ne chyba.

U zaúčtovaného dokladu, který administrátor opravuje vynuceně, se s novým kurzem **přepočte i zápis v účetním deníku**,
aby korunové částky odpovídaly dokladu.

### 23.11.13 Způsob úhrady a platba hotově z pokladny

Pole **Způsob úhrady** s volbou **Hotově** otevře výběr **Pokladna**. Přijatou fakturu tak zaplatíte z pokladny přímo
z editoru, aniž byste přecházeli do modulu Pokladna a doklad tam vypisovali ručně.

- Výchozí volba je **Nepoužít pokladnu**. Nabízejí se **jen korunové** pokladny
  ([§ 32.1](32_Pokladna.md#32111-ciselnik-pokladen)). Bez korunové pokladny se zobrazí hláška „Nemáte založenou žádnou
  korunovou pokladnu - doklad zůstane neuhrazený."
- Vyrovnání se spustí **při uložení faktury** a při přechodu do stavu **Přijatá** nebo **Zaúčtovaná**
  ([§ 23.11.2](#23112-stavy-prijate-faktury)). U konceptu se volba jen uloží a čeká.

Vznikne **výdajový pokladní doklad (VPD)** s účelem „Úhrada přijaté faktury", datem rovným datu vystavení faktury, popisem
„Úhrada přijaté faktury {číslo} hotově" a **celou částkou včetně DPH**. Částečná hotovostní úhrada přijaté faktury
podporovaná není. Doklad se rovnou zaúčtuje (**MD 321 / D analytika pokladny**, u zálohové přijaté faktury
**MD 314 / D pokladna**) a faktura se překlopí na **Uhrazená**. Doklad **nemá vlastní rozpad DPH**, daň už nese sama faktura
a úhrada ji neduplikuje.

Zrušení volby smaže pokladní doklad i jeho zápis a vrátí fakturu do předchozího stavu. Změna pokladny doklad přesune.
Ručně vystaveného pokladního dokladu se vyrovnání nikdy nedotkne. Podrobnosti, včetně chování při selhání a při stornu,
jsou u vydané faktury v [§ 15.2.7](15_Faktura_editor.md#1592-hlavicka), u přijaté faktury platí zrcadlově.

Nepodporuje se **cizoměnová faktura**, **valutová pokladna** a **daňový doklad k poskytnuté záloze (DDKP)**, ten se
samostatně nehradí. V těchto případech se vyrovnání jen přeskočí (s informativní hláškou) a faktura se normálně uloží.

### 23.11.14 Detail přijaté faktury

Po uložení nebo přechodu na detail vidíte:

- dodavatele (s IČO a DIČ), datumy, položky, rozpis DPH, součty, **K úhradě** a pod ní **Uhrazeno** a **Zbývá uhradit**
  (v měně faktury, ze všech úhrad dohromady),
- u uhrazené faktury, kterou evidované úhrady nepokrývají (typicky platba nižší o pár korun nebo eur), upozornění a akci
  **Vyrovnat zbytek**. Ta zbytek zaúčtuje jako zápočet proti zvolenému účtu (321 MD / zvolený účet D), předvolený je účet
  648, u cizí měny 663. Stav ani datum úhrady faktury se nemění, zbytek na 321 se tím vyrovná. Stejnou akci nabízí
  i částečně uhrazená faktura,
- kartu **Daňové zařazení** s přehledem údajů přímo rozhodujících o DPH: reverse charge, plátcovství dodavatele a nárok
  na odpočet včetně procenta u kráceného. Typ dokladu, klasifikaci DPH (kód i popis), daňovou uznatelnost, dlouhodobý
  majetek, kategorii nákladu a zakázku najdete ve sbalené sekci **Zaúčtování** spolu s kontací dokladu. Tato klasifikace je
  dostupná i před vznikem účetního zápisu. Sekce navíc ukazuje **nákladové pravidlo**, podle kterého se druh nákladu a účet
  určily (viz [§ 23.11.19](#231119-nakladove-pravidlo-a-preuctovani)). Ceny včetně DPH najdete v kartě Měna, u data přijetí
  je označené, jestli pochází z importu, nebo ho zadala účetní (viz [§ 23.11.7](#23117-danova-uznatelnost-a-narok-na-odpocet)),
- u položek **druh nákladu** (služba, materiál, drobný nebo dlouhodobý majetek), jejich vlastní klasifikaci DPH a období
  časového rozlišení,
- sekci **Originální PDF od dodavatele**, pokud jste ho nahráli, můžete ho stáhnout zpět,
- štítek **ISDOC** v hlavičce (a akci **Zdrojový doklad** v menu), který u faktur importovaných ze strukturovaného zdroje
  stáhne původní strojově čitelný originál (viz [§ 23.11.4](#23114-nahrani-dokladu-drag-and-drop-isdoc-a-limity)),
- tlačítka pro **přechod stavu** podle stavu faktury (viz [§ 23.11.2](#23112-stavy-prijate-faktury)),
- **Označit jako uhrazené**, které otevře okno s výběrem **data úhrady** (předvyplněno dneškem) a **způsobu úhrady**
  (viz [§ 23.11.15](#231115-zpusoby-uhrady-prijate-faktury)),
- tlačítko **Smazat**, dostupné jen u konceptu. Pro pozdější stavy použijte **Stornovat**,
- tlačítko **Zaplatit pomocí QR** u nezaplacených faktur s kladnou částkou k úhradě
  (viz [§ 23.11.17](#231117-zaplatit-pomoci-qr)).

### 23.11.15 Způsoby úhrady přijaté faktury

Okno **Označit jako uhrazené** nabízí tři způsoby a liší se tím, co po nich zůstane v deníku:

<!-- cols: 24 52 24 -->
| Způsob | Co vznikne | Částka |
|---|---|---|
| **Evidenčně** | nic, doklad je uhrazený jen v evidenci, závazek na 321 zůstává otevřený | plná výše |
| **Pokladnou** | výdajový pokladní doklad a zápis 321 MD / 211 D | plná výše |
| **Zápočtem** | zápis **321 MD / zvolený účet D** | i **částečná** |

**Evidenčně** je nouzová volba pro doklad, jehož úhradu do MyÚčta nedostanete. Uzávěrková kontrola takový doklad hlásí
jako *zaplacená faktura s otevřeným saldem na 321* a hlásí ho právem, protože deník o úhradě neví a závazek by se do závěrky
přenesl jako neuhrazený.

**Zápočtem** (proti zvolenému účtu) je způsob, jak vyrovnat závazek bez peněz: proti pohledávce za společníkem (355, 365), proti
mzdovému závazku (331), proti přijaté záloze u téhož dodavatele (314) nebo proti čemukoli jinému, co v osnově dává smysl.
Protiúčet se předvyplní z kontačního pravidla pro vyrovnání závazků, ale rozhoduje ten, který vyberete. Protiúčtem nesmí být
týž účet, na kterém doklad visí, vznikl by zápis „321 MD / 321 D", který nic nevyrovná.

Zápočet **může být částečný**: zbytek zůstane na dokladu otevřený, doklad zůstává ve stavu Přijatá nebo Zaúčtovaná a do
příkazu k úhradě i k dalšímu zápočtu vstupuje už jen svým zbytkem. Na *Uhrazená* se překlopí teprve zápočet, který zbytek
vynuluje. Zbytek se počítá ze všech kanálů úhrady dohromady (banka, vzájemný zápočet [§ 63](67_Zapocty.md) i zápočty proti
účtu), takže tutéž korunu nejde započíst dvakrát.

Zápočet jde **stornovat** (v přehledu úhrad v detailu dokladu). Storno vytvoří protizápis a když po vrácení jeho částky
zbytek zase vznikne, vrátí doklad ze stavu *Uhrazená* zpět. Doklad doplacený jiným kanálem zůstane uhrazený.

U cizoměnové faktury se částka zápočtu zadává v měně faktury a do deníku se převede kurzem, kterým je faktura předepsaná
na 321, takže na saldokontu nezůstane kurzový zbytek. V daňové evidenci se zápočet neúčtuje (deník tam není), ale doklad
vyrovná stejně.

### 23.11.16 Propojení zálohy s vyúčtovací fakturou (proti dvojímu započtení)

Když vám dodavatel pošle nejdřív **zálohovou fakturu** (typ dokladu *Záloha*, proforma) a po zaplacení samostatnou
**vyúčtovací (finální) fakturu**, máte v systému dva doklady na tentýž náklad. Bez propojení by se náklad počítal
**dvakrát** (Náklady, Zisk, daň z příjmů). Proto je lze spárovat.

V detailu **finální** faktury je box **Zálohová faktura**:

- Pokud vazba není, klikněte na **Spárovat se zálohou** a vyberte zálohu od stejného dodavatele. Propojit lze jen
  nestornovanou zálohu ve **stejné měně**. Nabídka řadí zálohy s **nejbližší částkou** (porovnává hrubou částku faktury
  *před* odečtem zálohy, takže i faktura uhrazená zálohou „na 0 Kč" se napáruje správně).
- Po spárování se zobrazí odkaz na zálohu a tlačítko **Zrušit propojení**. Na finální fakturu se zároveň doplní odečet
  skutečně uhrazené části zálohy, nejvýše do částky finální faktury, pokud byl nulový.
- V detailu **zálohy** vidíte reverzně, kterou fakturou je vyúčtována. Nevyúčtovanou zálohu lze spárovat i **odtud**,
  tlačítkem **Spárovat s fakturou** (nabídne nepropojené vyúčtovací faktury téhož dodavatele). Tlačítka se zobrazí jen,
  když existuje vhodný protějšek.

Jedna záloha může být navázaná **jen na jednu** finální fakturu.

**Nákup zaplacený kartou (bez zálohové faktury).** Když dodavatel žádnou zálohovou fakturu nevystaví a místo ní pošle
rovnou **daňový doklad k platbě** (typ dokladu *Daňový doklad k platbě*, § 28 odst. 8 ZDPH), typicky u platby kartou,
chová se tento samostatný DDKP jako záloha: box **Zálohová faktura** ho na finální faktuře nabídne mezi kandidáty stejně
jako zálohovou fakturu, spáruje se stejným tlačítkem a propojení funguje symetricky (z detailu DDKP i z detailu finální
faktury). DDKP, který už patří k jiné zálohové faktuře, se mezi kandidáty nenabízí, vyúčtovává se přes tu zálohu, ne přímo.

**Špatně určený typ dokladu.** Běžná faktura mívá v hlavičce nadpis „Daňový doklad", to ještě není daňový doklad
k platbě. Pokud takový doklad přesto skončí jako *Daňový doklad k platbě*, přepněte typ v editoru zpět na *Faktura* a uložte.
U zaúčtovaného dokladu se zároveň přeúčtuje účetní zápis. Změna projde jen u DDKP, na kterém nevisí vazba. Je-li navázaný
na zálohovou fakturu nebo je jím už vyúčtovaná konečná faktura, uložení skončí hláškou a nejdřív je potřeba zrušit tu vazbu.

Dokud propojení nevznikne, zůstává na DDKP viditelné **upozornění**, pokud je z něj na účtu 314 otevřený zůstatek
a od stejného dodavatele existuje nespárovaná faktura, která k němu pravděpodobně patří. Upozornění obsahuje odkaz
na tu fakturu a rovnou spočítanou částku DPH (viz níže), aby nezůstal viset beze stopy.

**Zaúčtování zálohového cyklu.** Zaplacení zálohové přijaté faktury se do [Účetního deníku](52_Ucetni_denik.md) zaúčtuje
jako **poskytnutá záloha** (MD 314 Poskytnuté zálohy / D 221 banka nebo 211 pokladna), ne jako běžný závazek 321, protože
záloha není daňový doklad. Když pak zaúčtujete finální (vyúčtovací) fakturu navázanou na tuto zálohu, zápis automaticky
doplní i **zúčtovací řádek zálohy** (MD 321 / D 314) ve výši skutečně **zaplacené** zálohy, ne nominální částky zálohové
faktury, takže i částečně zaplacená záloha se zúčtuje správně. Mimo automatiku zůstává vazba záloha na víc než jednu finální
fakturu, takový případ zaúčtujte ručním zápisem.

**Platba zálohy patří na zálohu, i když je konečná faktura už zaúčtovaná.** Spárujete-li platbu zálohy (i platbu kartou)
se zálohovou fakturou až po zaúčtování konečné faktury, nebo ji odpárujete a spárujete znovu, zúčtovací řádek 321/314
v zápisu konečné faktury se sám doplní, opraví nebo odebere. Ostatní řádky zápisu (i ručně přeúčtovaný nákladový účet)
zůstávají. V uzavřeném nebo zamčeném období se zápis nepřepíše a v historii faktury zůstane záznam, že zúčtování zálohy
nesedí. Totéž platí, když je úhrada zálohy z jiného roku než konečná faktura, zúčtování k datu úhrady pak zapište ručně.
Platbu neuhrazené zálohy nejde spárovat s konečnou fakturou, která ji vyúčtovává, aplikace odkáže na zálohu a automatické
párování ji faktuře nenabídne. Doplatek faktury nad zálohu se páruje normálně. Když je částka dvojznačná, párování
s fakturou projde až po potvrzení. Sekce **Zaúčtování** na detailu zálohy ukazuje zápis úhrady (314) a zápis konečné
faktury se zúčtováním, dokud záloha uhrazená není, říká, že se zaúčtuje při úhradě. Zálohová faktura se jako předpis
neúčtuje záměrně, automatické účtování ji proto ani nezkouší a nehlásí chybu.

**Záloha (nebo samostatný DDKP) s vlastním daňovým dokladem k platbě.** Má-li navázaná záloha svůj DDKP, nebo je-li
zálohou přímo samostatný DDKP, část účtu 314 už vyčerpala DPH (343/314), kterou DDKP uplatnil při platbě. Automatické
zúčtování na plnou zaplacenou částku by pak 314 přečerpalo do minusu o tuhle už uplatněnou daň, takže se v tomto případě
**nezaúčtuje automaticky**. Hláška u zaúčtování rovnou spočítá, kolik DPH z finální faktury zbývá doúčtovat na 343 nad rámec
toho, co DDKP uplatnil už při platbě. Zúčtování zálohy pak zapište ručním zápisem podle této částky.

**Co propojení (a zaplacení) ovlivní:**

<!-- cols: 30 70 -->
| Oblast | Chování zálohy |
|---|---|
| **Náklady, Zisk (statistiky)** | Spárovaná **nebo zaplacená** záloha se nepočítá (náklad nese vyúčtovací faktura). Nezaplacená a nespárovaná záloha se počítá jako očekávaný náklad. |
| **Daň z příjmů** | V **daňové evidenci DPFO** je zaplacená provozní záloha peněžním výdajem, při následném vyúčtování se už jednou zaplacená část nezapočte podruhé. V **podvojném účetnictví a DPPO** samotná záloha není nákladem, náklad nese až vyúčtování. |
| **Výkazy DPH** (Kniha DPH, DPHDP3, KH, souhrnné hlášení) | Záloha do nich **nevstupuje vůbec** (není daňový doklad, tím je až vyúčtovací faktura). |
| **Závazky a cashflow** | Nezaplacená záloha zůstává jako reálný závazek k úhradě. |

**AI návrh propojení.** Když naimportujete vyúčtovací fakturu přes AI extrakci z PDF (viz [AI extrakce](25_AI_extrakce.md))
a ta odkazuje na zálohu (text typu *„zaplaceno zálohou č. X"*), aplikace zkusí najít odpovídající zálohu a v detailu nabídne
**návrh propojení**. Stačí ho **Potvrdit** (nebo **Zamítnout**), nic se nepáruje automaticky.

### 23.11.17 Zaplatit pomocí QR

U **nezaplacené** přijaté faktury (stav koncept, přijatá, zaúčtovaná) s kladnou částkou k úhradě je v hlavičce detailu
tlačítko **Zaplatit pomocí QR**. Otevře okno s **QR platbou**, kterou naskenujete v mobilní bankovní aplikaci. Pro CZK
doklady je ve formátu **QR Platba (SPAYD)**, pro doklady v cizí měně jako **SEPA (EPC)**.

QR aplikace sestavuje z **platebního účtu dodavatele**, částky k úhradě a variabilního symbolu. U CZK může obsahovat také
skutečné datum splatnosti. Řídí ho samostatná volba `Firma → Nastavení → Fakturace → Datum splatnosti v QR platbě →
Přijaté doklady`, která je ve výchozím stavu vypnutá. Po zapnutí se datum splatnosti doplní do nově generovaného kódu SPAYD.
SEPA EPC datum splatnosti nepodporuje. Účet se získává v tomto pořadí:

1. **Z ISDOC.** Pokud má PDF vloženou přílohu ISDOC, vezme se z ní účet či IBAN i variabilní symbol (zdroj „z ISDOC").
2. **AI rozpoznání.** Když uložený účet není a doklad má PDF, lze ho jednorázově **rozpoznat z faktury** (krátký dotaz
   aktivnímu poskytovateli AI jen na platební údaje). Spustí se automaticky při otevření okna (vyžaduje nastavený API klíč,
   viz [AI extrakce](25_AI_extrakce.md)). Proběhne **jen jednou**, pokud účet na dokladu není, příště se už neptáme.
3. **Ručně.** Účet vyplníte nebo upravíte přímo v okně (tlačítko **Upravit účet**) nebo v editoru faktury v boxu
   **Platební účet dodavatele**. Stačí buď **číslo účtu a kód banky**, nebo **IBAN** (u zahraničních dodavatelů).
4. **Obrázek QR z PDF.** Když účet nelze získat, ale v PDF je obrázek, který vypadá jako QR kód (čtvercový, černobílý),
   zobrazí se jako **náhradní řešení** rovnou (kód nerozpoznáváme, jen ho ukážeme k naskenování). Nastavení data splatnosti
   takový převzatý obrázek změnit nemůže.

Známý účet se zobrazí i v **detailu** faktury (box *Platební účet dodavatele* vedle měny) a předvyplní se v editoru
i v okně QR. QR platbu uvidí i uživatel s rolí **jen pro čtení** (pokud je účet uložený), rozpoznání z faktury a ruční úpravu
účtu může provést jen uživatel s právem zápisu.

### 23.11.18 Zaúčtování do deníku a tlačítko Zaúčtovat

Tlačítko **Zaúčtovat** se zobrazí v hlavičce detailu jen firmám v režimu **podvojné účetnictví**, u faktur ve stavu
přijatá, zaúčtovaná nebo uhrazená, dokud doklad nemá účetní ikonu **Zaúčtováno** ani aktivní zápis v deníku. U dokladu
typu **Záloha** se tlačítko nezobrazuje: zálohová výzva není účetní předpis závazku, účtuje se až její skutečná úhrada
z banky nebo pokladny na účet 314. Funguje stejně jako u [vydaných faktur](16_Faktura_PDF.md#16103-zauctovani-do-deniku):
potvrzovací dialog, zápis podle [předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace), po úspěchu účetní ikona **Zaúčtováno**
(s datem v tooltipu) a proklik **Zobrazit v deníku**. Stejná tabulka chybových hlášek (chybějící kurz, uzavřené období,
nevyvážený zápis, chybějící účet v osnově) platí i tady, viz [§ 16.1.3](16_Faktura_PDF.md#16103-zauctovani-do-deniku).
Zaúčtovat smí jen administrátor nebo účetní.

**Hromadné zaúčtování.** Označte více faktur a klikněte na **Zaúčtovat (N)**. Nabídne se jen z vybraných ty přijaté,
zaúčtované nebo uhrazené, dosud nezaúčtované a jiné než zálohové. Doklady se účtují jeden po druhém (chyba jednoho neblokuje
ostatní), na konci je souhrn *„Zaúčtováno {ok}, chyby: {err}"*. Maximálně 500 dokladů na dávku.

**Automatické zaúčtování při přijetí** (volitelné, nastavuje administrátor, spustí se při přechodu na stav Přijatá), viz
[§ 96.11](96_Nastaveni.md#969-krok-za-krokem-automaticke-uctovani).

### 23.11.19 Nákladové pravidlo a přeúčtování

Sekce **Zaúčtování** na detailu ukazuje kromě kontace i řádek **Nákladové pravidlo**, tedy podle čeho se určil druh nákladu
a účet:

<!-- cols: 38 62 -->
| Co je vidět | Znamená |
|---|---|
| název pravidla | rozhodlo vaše [pravidlo nákladů](65_Sablony.md), tlačítko **Upravit pravidlo** otevře rovnou jeho formulář |
| „Bez pravidla (podle katalogu frází / klíčových slov / limitu §26/2 ZDP / návrhu AI)" | žádné firemní pravidlo za tím nestojí, chcete-li, aby se příště účtovalo podle vašeho, založte ho v Šablonách |
| „Bez pravidla (řádky se liší)" | řádky dokladu se rozhodly různě, jedno pravidlo za dokladem není |
| „Ručně (bez automatické klasifikace)" | druh a účet zvolila účetní ručně |
| „Pravidlo #N už neexistuje" | pravidlo se od zaúčtování smazalo, stopa po něm zůstává schválně |

Nákladové pravidlo vybírá jen **druh** nákladu, účty MD/Dal k tomu druhu určuje až **předkontace**. Tu sekce ukazuje hned
pod pravidlem, i s tlačítkem na její opravu, viz [§ 52.8.3](52_Ucetni_denik.md#521493-podle-ceho-se-uctovalo).

Sekce má u každého živého zápisu i tlačítko **Přeúčtovat** (jen administrátor a účetní), které otevře řádky existujícího
zápisu k opravě. Podle stavu období se zápis buď přepíše, nebo stornuje a zapíše znovu. Přesun nákladu mezi účty téže třídy
(například 511 na 518.100) se přepíše na místě i v měsíci zamčeném podaným DPH, dokud rok není v uzávěrce. Celý postup
i chování v zamčeném období popisuje [§ 52.8.2](52_Ucetni_denik.md#521492-preuctovani-z-dokladu-sekce-zauctovani).

Pod kontací každého zápisu je panel **Souvisí** (úhrady, bankovní pohyby a ručně navázané doklady s odkazem do deníku,
jejich kontací a poznámkami) a **Poznámky** zápisu, tytéž jako v deníku a u bankovního pohybu
([§ 52.6.3](52_Ucetni_denik.md#521473-poznamky-k-zapisu)).

### 23.11.20 Scan inbox: automatický import z adresáře

Pokud vám dodavatelé posílají PDF e-mailem nebo máte složku sdílených dokladů, nakonfigurujte **inbox adresář**
v `cfg.php`:

```php
'purchase_invoice' => [
    'inbox_dir'         => 'C:/inetpub/wwwroot/myucto.cz/inbox',
    'inbox_recursive'   => true,
    'allowed_exts'      => ['pdf', 'isdoc', 'isdocx', 'xml'],
    'archive_storage'   => __DIR__ . '/storage/purchase-invoices',
],
```

V seznamu Přijaté faktury klikněte na **Nascanovat inbox**:

- Aplikace rekurzivně projde nakonfigurovaný adresář.
- **Soubory se shodným základem jména bere jako jednu zásilku.** Dorazí-li faktura obvyklou dvojicí `faktura.pdf` +
  `faktura.isdoc` (nebo `.xml` či `.isdocx`), data se vezmou z ISDOC (jsou přesná a zadarmo) a PDF se k témuž dokladu jen
  archivuje jako čitelná podoba. **AI se v takovém případě nevolá vůbec** a nevzniká druhý koncept. Páruje se jen v rámci
  téhož adresáře a bez ohledu na velikost písmen.
- Pro každý soubor spočte SHA-256. Pokud už některý soubor zásilky v systému je (archivované PDF nebo strojový originál),
  přeskočí se celá zásilka.
- Soubory v adresáři po importu zůstávají. **Smažete-li koncept** (třeba soukromou fakturu, která do účetnictví nepatří),
  aplikace si soubor zapamatuje a další sken ho přeskočí s důvodem „Koncept z tohoto souboru byl smazán". Totéž platí
  po odebrání PDF z dokladu. Chcete-li takový soubor přesto zpracovat, nahrajte ho ručně přes import.
- Z PDF s vloženým ISDOC rozpozná data dodavatele a obsah.
- Samostatné `.isdoc` i `.isdocx` balíčky v inboxu rozbalí a naimportuje přímo (z `.isdocx` archivuje zabalené PDF
  pro náhled, pokud ale vedle leží PDF od dodavatele, použije se to).
- Prosté PDF (bez ISDOC a bez datového sourozence) jde na AI extrakci, je-li nakonfigurovaná. Jinak se přeskočí
  a doklad nahrajte přes formulář.

Okno po skončení zobrazí přehled: vytvořeno, přeskočeno, chyby a detail po souborech.

Pokud se u spárované dvojice nepodaří v textu PDF najít variabilní symbol z ISDOC **a** zároveň nesedí ani celková částka,
doklad i příloha přesto vzniknou, ale report u nich ukáže **varování**, že PDF možná patří k jiné faktuře. Skenovaný obraz
bez textové vrstvy se neověřuje (nemá čím), takže u něj varování nikdy nevyskočí.

**Bezpečnost:** soubory mimo nakonfigurovaný `inbox_dir` jsou odmítnuty (ochrana proti path traversal přes `realpath()`).
Maximum je 500 souborů na běh (ochrana proti zahlcení velkými adresáři).

### 23.11.21 Klienti vs. dodavatelé

V adresáři protistran se používají dvě role: klient, kterému fakturujete, a dodavatel, od kterého přijímáte faktury.
Některé firmy jsou **současně zákazník i dodavatel** (například partnerská IT firma, kterou fakturujete za development
a od níž kupujete hosting). Taková firma je jedna entita s oběma rolemi. Synchronizace z ARES, kontakty a historie jsou
sdílené.

V hlavním menu jsou samostatné položky **Klienti** a **Dodavatelé**. V adresáři můžete přepínat karty
**Klienti / Dodavatelé / Vše**, při založení z dodavatelské karty se role dodavatele předvyplní. Detail jedné protistrany
sdílí kontakty, údaje z ARES i historii, ale přehledy vydaných a přijatých dokladů zůstávají oddělené podle role.

### 23.11.22 Audit log

Akce s přijatými fakturami jsou logované v aktivním logu (`Systém → Log`):

- `purchase_invoice.created`
- `purchase_invoice.updated` / `force_updated`
- `purchase_invoice.items_updated`
- `purchase_invoice.exchange_rate_set`
- `purchase_invoice.transitioned` (s údaji o původním a novém stavu)
- `purchase_invoice.extraction_warning_dismissed`
- `purchase_invoice.advance_linked` / `advance_unlinked` (propojení se zálohou)
- `purchase_invoice.advance_suggestion_dismissed` (zamítnutý AI návrh propojení)
- `purchase_invoice.deleted`
- `purchase_invoice.pdf_uploaded` / `pdf_downloaded`
- `purchase_invoice.our_pdf_downloaded`
- `purchase_invoice.isdoc_exported` / `pohoda_exported`
- `purchase_invoice.inbox_scanned`

### 23.11.23 REST API

Všechny operace jsou dostupné i přes REST API (`/api/v1/purchase-invoices/*`), viz [Swagger UI](/api/docs) nebo
[Redoc](/api/reference). Token musí mít pro změny rozsah `read_write`.

## 23.12 Související kapitoly

- [Export přijatých faktur](24_Export_prijatych.md) - naše PDF, ISDOC, Pohoda a CSV pro účetní.
- [AI extrakce](25_AI_extrakce.md) - import z PDF přes nastaveného poskytovatele AI.
- [Platební příkazy](26_Platebni_prikazy.md) - hromadná úhrada přijatých faktur.
- [Účetní deník](52_Ucetni_denik.md) - kam se faktury zaúčtují.
- [Výkazy DPH](41_Vykazy_DPH.md) - jak faktury vstupují do přiznání a kontrolního hlášení.
