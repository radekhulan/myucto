# 33. Sklad

Modul **Sklad** je skladová evidence pro firmy, které nakupují a prodávají materiál,
zboží nebo vyrábí výrobky. Vede skladové karty, příjemky/výdejky/převodky mezi
sklady, umožňuje víc skladů, inventury a základní skladové sestavy. Napojuje se na
[Vydané faktury](14_Faktury.md) a [Přijaté faktury](23_Prijate_faktury.md) — umí
automaticky vydat zboží ze skladu při vystavení faktury a naskladnit zboží z přijaté
faktury. Vedle fyzického stavu vede i **rezervace, zboží na cestě a skladovost
u dodavatele** (§ 33.9), **objednávky vydané dodavatelům** (§ 33.11) a návrh
**doplnění zásob** (§ 33.12).

V menu ho najdeš pod sekcí **Sklad** (zobrazí se jen po zapnutí modulu):
**Skladové karty**, **Příjemky a výdejky**, **Objednávky dodavatelům**
(§ 33.11), **U dodavatele** (§ 33.10), **Inventury**, **Sestavy**. Číselník
**Sklady** (jednotlivé sklady firmy) je záložka na stránce **E-shop** — modul Sklad
totiž sdílí skladové karty s [e-shopovým modulem](34_Eshop.md) (ceny, kategorie,
parametry, dodavatelé, přílohy k produktu).

> [!NOTE]
> Sklad je **doplňkový, nepovinný modul (opt-in)** — dokud ho v Nastavení firmy
> nezapneš, sekce Sklad se v menu vůbec nezobrazí a všechny skladové API endpointy
> vrací chybu „Skladový modul není pro tuto firmu zapnutý" (HTTP 403) — a to i na
> čtecí požadavky, aby se do frontendu vůbec nedostala žádná data nezapnutého
> modulu. Funguje **nezávisle na účetním režimu** — stejně dobře pro podvojné
> účetnictví i pro daňovou evidenci.

## 33.1 Zapnutí modulu

Sklad zapneš na stránce úpravy firmy (**Systém → Nastavení**, viz
[§ 91.5 Editace dodavatele](91_Multi_supplier.md#915-editace-dodavatele)) v samostatné
sekci **Vést skladovou evidenci**:

- **Vést skladovou evidenci** — hlavní přepínač (interně sloupec `stock_enabled` na
  firmě). Zpřístupní skladové karty, doklady, inventury i skladové sestavy a přidá
  do menu sekci Sklad.
- **Automatická výdejka při vystavení faktury** *(zobrazí se jen po zapnutí evidence,
  interně sloupec `stock_auto_issue`, výchozí zapnuto)* — když má faktura řádky
  napojené na skladovou kartu, při vystavení faktury se automaticky založí a
  zaúčtuje výdejka. Vypnutím přepínače přejdeš na ruční vydávání zboží ze skladu
  (výdejky si zakládáš sám/sama) — použij to, pokud chceš mít nad výdejem plnou
  kontrolu nebo výdejky slučuješ jinak, než jak fakturuješ.

Sklad interně účtuje **způsobem B** dle ČÚS 015 (bod 4.3) — v průběhu roku se
skladové pohyby neúčtují na účty, jen evidují; do účetnictví se promítne až
**uzávěrkovým krokem** (konečný/počáteční stav zásob, reklasifikace inventurních
mank a přebytků — viz uzávěrka v modulu Účetnictví). To je aktuálně jediný podporovaný
způsob účtování zásob; datový model má připravený i sloupec `stock_method` s hodnotami
`B`/`A`, ale zapisuje se do něj natvrdo jen `B` — přepnutí na způsob A není v tomto
vydání funkčně implementované.

## 33.2 Skladové karty

**Sklad → Skladové karty** je stránkovaný seznam karet materiálu, zboží a výrobků
(výchozí stránkování 50 karet na stránku).

**Filtry**: typ karty (Materiál/Zboží/Výrobek), sklad (omezí zobrazený stav a hodnotu
na vybraný sklad), **jen pod minimem**, jen aktivní a fulltextové hledání (SKU, název,
EAN). Filtry si můžeš uložit jako výchozí přes uložené filtry, sloupce si zapneš/vypneš
přes výběr sloupců a hustotu řádků přes přepínač hustoty (viz
[§ 92.9 Uložené filtry a předvolby zobrazení](92_Nastaveni.md#929-ulozene-filtry-a-predvolby-zobrazeni)).

Sloupce: **SKU**, **Název**, **Typ**, **MJ** (měrná jednotka), **Stav** (aktuální
množství — červeně a tučně, pokud je pod nastaveným minimem), **Hodnota** (ocenění
stavu v Kč), **Prům. cena** (dopočtená průměrná pořizovací cena za jednotku),
volitelně i **Prodejní cena**, **Min. zásoba** a **Aktivní**. Stav a hodnota se
počítají ze skutečných skladových pohybů v momentě zobrazení stránky, ne z uložených
souhrnných čísel karty.

Kliknutím na **Novou skladovou kartu** se otevře editor se základními poli:

- **Název** *(povinné, max 255 znaků)* a **SKU** *(max 50 znaků)* — pokud SKU necháš
  prázdné, dopočítá se automaticky ze zadaného názvu (bez diakritiky, VELKÝMI
  písmeny, zkráceno na 50 znaků; když by ze jména nezbylo nic použitelného, dosadí se
  namísto toho náhodný kód tvaru `SKU-XXXXXXXX`); po prvním ručním zásahu do pole SKU
  se automatický přepočet vypne. SKU musí být v rámci firmy unikátní — při shodě
  systém odmítne uložení chybou „Skladová karta s tímto SKU už existuje" (HTTP 409);
  totéž hlídá i databázový unikátní klíč na dvojici firma + SKU, takže ke kolizi
  nemůže dojít ani při souběžném zakládání.
- **Typ karty**: **Materiál** (spotřebovává se do nákladu 501), **Zboží** (do nákladu
  504) nebo **Výrobek** (uzávěrková kontace 123/583).
  Typ určuje, na jaké účty se v uzávěrce zaúčtuje konečný/počáteční stav a inventurní
  rozdíly — viz posuzovací pravidla `stock.closing.material` (112/501),
  `stock.closing.goods` (132/504), `stock.opening.material` (501/112),
  `stock.opening.goods` (504/132), `stock.shortage.reclass.*` (549/501 nebo 549/504
  pro inventurní manko) a `stock.surplus.*` (501/648 nebo 504/648 pro inventurní
  přebytek); tato posuzovací pravidla jsou uzávěrce k dispozici jako globální šablona,
  kterou lze firemně přenastavit stejně jako ostatní kontace.
- **Měrná jednotka** (výchozí „ks"), **EAN**, **sazba DPH** (výchozí do řádku faktury),
  **prodejní cena bez DPH** (výchozí do řádku faktury) a **minimální zásoba** — pokud
  stav klesne pod tuto hodnotu, karta se v seznamu i sestavách zvýrazní.
- **Poznámka** a příznak **Aktivní** — needativní kartu jde jen deaktivovat, ne smazat
  (viz níže), aby zůstala historie pohybů.

Po založení karty (jen v režimu úpravy) se zpřístupní i **e-shopové taby** — jazykové
mutace popisu, kategorie a štítky, parametry, ceny v jednotlivých měnách, dodavatelé
a přílohy/obrázky. Skladová karta je totiž zároveň produktovou kartou pro e-shop;
tyto taby popisuje kapitola o e-shopu.

### 33.2.1 Detail karty a skladová kniha

Kliknutím na řádek v seznamu se otevře **detail karty**: dlaždice s počátečním
stavem, prodejní cenou, minimální zásobou a EAN, pod nimi záložka **Pohyby** —
kompletní **skladová kniha** karty (datum, doklad s prokliknutím, sklad, množství se
znaménkem, jednotková cena, hodnota a **běžná bilance** po každém řádku). Stránka
pohybů se natahuje po 100 řádcích (dá se vyžádat až 500 najednou), počáteční bilance
před zobrazenou stránkou se dopočítává ze všech předchozích řádků se stejnými filtry.
Stornované doklady se v knize zobrazí ztlumeně, ale zůstávají viditelné.

Akce v hlavičce detailu: **Nová výdejka** (rovnou předvyplní kartu do nového dokladu),
**Upravit**, **export do PDF** a **export do XLSX** (kompletní skladová kniha karty,
natažená dávkově po 500 řádcích bez ohledu na to, kolik pohybů karta má) a
**Deaktivovat** (jen pokud je karta aktivní).

> [!NOTE]
> Skladovou kartu, která má jakýkoli skladový pohyb, **nejde smazat** — pokus o smazání
> vrátí chybu „Skladovou kartu nelze smazat — má skladové pohyby. Deaktivujte ji místo
> mazání." (HTTP 409) a nabídne deaktivaci. Smazat lze pouze čerstvě založenou kartu
> bez pohybů.

### 33.2.2 Vazba na e-shopovou kartu

Skladová a e-shopová karta je v datech **jeden a týž záznam** (tabulka skladových
karet nese i e-shopové sloupce), ale zobrazení v e-shopu se neřídí typem karty
(Materiál/Zboží/Výrobek) — o tom, jestli se karta má exportovat na e-shop, rozhoduje
samostatný, na typu nezávislý příznak **Exportovat do e-shopu** (v editoru na
e-shopové záložce). I materiál nebo výrobek tedy technicky jde nastavit jako
zobrazovaný v e-shopu, a naopak běžnou kartu typu Zboží lze mít vedenou jen skladově,
bez e-shopové prezentace. Druhý související příznak, **Skladem** (interně
`is_stocked`), určuje, jestli e-shop pro danou kartu vůbec hlídá dostupnost skladem —
podrobnosti k oběma příznakům a dalším e-shopovým polím (kategorie, parametry, ceny,
dodavatelé) najdeš v kapitole [E-shop](34_Eshop.md).

## 33.3 Oceňování zásob

Sklad oceňuje zásoby **váženým aritmetickým klouzavým průměrem** (§ 49 odst. 3
vyhlášky 500/2002 Sb., ČÚS 015 bod 3.6) — při každém příjmu se dopočítá nová průměrná
cena z dosavadní hodnoty skladu a hodnoty přijaté dávky; výdej se pak vždy oceňuje
aktuální průměrnou cenou v okamžiku zaúčtování dokladu, ne cenou z minulého příjmu.
Je to jediná podporovaná oceňovací metoda — FIFO/LIFO modul nenabízí.

Uvnitř se nepočítá s desetinnými čísly (float), ale s celočíselnou aritmetikou:
množství se drží v **tisícinách kusu** a hodnota v **haléřích** — haléřová hodnota
skladu je vždy zdrojem pravdy, průměrná jednotková cena (na 6 desetinných míst) je
z ní jen dopočítaná pro zobrazení. Zaokrouhluje se (matematicky, „na obě strany", ne
jen dolů) vždy jen na hranici haléře při každém jednotlivém pohybu, takže se
zaokrouhlovací chyba v čase nekumuluje. Speciální případ je výdej **celého** zbylého
množství karty — ten se vždy oceňuje přesně zbývající hodnotou skladu, takže po něm
nezůstane žádný haléřový „sediment" a stav klesne přesně na 0 Kč / 0 ks.

Systém **tvrdě zakazuje záporný stav zásob** — pokus o výdej/převod/storno, který by
u jakékoli karty způsobil zápor, skončí chybou „Nedostatek zásob pro výdej" (HTTP 409)
a doklad se nezaúčtuje. Kontrola proběhne souhrnně za všechny řádky dokladu najednou
(ne na první nedostatkové položce) a vrátí seznam všech karet, kterým množství
nesedí, s požadovaným i dostupným množstvím — proběhne dřív, než se dokladu přidělí
číslo řady, takže se při chybě žádné číslo „nespálí". Žádnou výjimku ani „povolit
záporný stav" volbu modul nemá — pokud sklad neodpovídá skutečnosti, je potřeba to
nejdřív srovnat inventurou (§ 33.7) nebo opravnou příjemkou.

> [!NOTE]
> Čistě technicky (kvůli celočíselné aritmetice v haléřích) má oceňování horní mez:
> jeden výdej unese hodnotu skladu zhruba do 10 milionů Kč a zhruba 5 milionů kusů
> současně, hodnota jedné karty smí dosáhnout zhruba 4,6 miliardy Kč. Pro běžný
> provoz malé a střední firmy jsou to meze bez praktického významu.

### 33.3.1 Příklad výpočtu klouzavého průměru

Karta nemá zatím žádný pohyb (stav 0 ks / 0 Kč). Následují dva příjmy a jeden výdej:

| Datum | Doklad | Pohyb | Množství | Jedn. cena | Hodnota pohybu | Stav (ks) | Hodnota skladu | Prům. cena |
|---|---|---|--:|--:|--:|--:|--:|--:|
| 1.3. | PRI-2026-0001 | Příjem | +100 | 50,00 Kč | +5 000,00 Kč | 100 | 5 000,00 Kč | 50,0000 Kč |
| 5.3. | PRI-2026-0002 | Příjem (vč. dopravy 200 Kč) | +50 | 60,00 Kč | +3 200,00 Kč | 150 | 8 200,00 Kč | 54,6667 Kč |
| 12.3. | VYD-2026-0001 | Výdej | −80 | 54,6667 Kč (dopočteno) | −4 373,33 Kč | 70 | 3 826,67 Kč | 54,6667 Kč |

- U prvního příjmu je průměrná cena rovnou zadaná jednotková cena (50,00 Kč), protože
  na skladě předtím nic nebylo.
- U druhého příjmu je na řádku zadaná jednotková cena 60,00 Kč (50 ks × 60,00 Kč =
  3 000,00 Kč), k tomu se ale rozpustí vedlejší pořizovací náklad 200,00 Kč (doprava —
  viz § 33.4.4), takže do skladu vstoupí celkem 3 200,00 Kč. Nová průměrná cena se
  počítá z **celkové** hodnoty skladu po příjmu (5 000 + 3 200 = 8 200 Kč) děleno
  celkovým množstvím (100 + 50 = 150 ks) = 54,6667 Kč/ks — ne jen z ceny posledního
  příjmu.
- Výdej 80 ks se ocení aktuální průměrnou cenou 54,6667 Kč/ks, tj. hodnotou
  80 × 54,6667 = 4 373,33 Kč (zaokrouhleno na haléře). Po odečtení zbývá na skladě
  70 ks za 3 826,67 Kč — pořád ve stejné průměrné ceně 54,6667 Kč/ks (výdej nemění
  jednotkovou cenu zbytku, jen úměrně sníží celkovou hodnotu).
- Kdyby se místo 80 ks vydalo **všech** zbývajících 70 ks (po předchozím výdeji),
  poslední výdej by se ocenil přesně zbývající hodnotou skladu bez zaokrouhlovacího
  zbytku a stav by klesl přesně na 0 ks / 0,00 Kč.

## 33.4 Skladové doklady

**Sklad → Skladové doklady** je seznam všech skladových dokladů se záložkami
**Vše / Příjemky / Výdejky / Převodky** (stránkovaný, max 500 řádků na stránku).
Filtry: sklad, stav a fulltext; sloupce zahrnují číslo dokladu, datum, typ, sklad (u
převodky „odkud → kam"), partnera, popis, **původ** a **stav**.

Doklad má tři podoby:

- **Příjemka** — navýší stav na skladu (nákup, počáteční naskladnění, přebytek
  z inventury…).
- **Výdejka** — sníží stav na skladu (prodej, spotřeba, manko z inventury…).
- **Převodka** — přesune zásobu mezi dvěma sklady jedné firmy (zdrojový a cílový
  sklad musí být různé).

### 33.4.1 Životní cyklus dokladu

Doklad prochází stavy **Rozpracován (`draft`) → Zaúčtováno (`posted`) → Stornováno
(`reversed`)** a přechody jsou striktně jednosměrné — z `posted`/`reversed` už se
doklad nikdy nevrátí zpátky do `draft`:

- Nově založený doklad je **Rozpracován (draft)** — dá se libovolně upravovat i
  smazat, ještě nehýbe skladem ani nemá přidělené číslo. Pokus upravit nebo smazat
  doklad, který už není v draftu, vrátí chybu „Upravovat lze jen rozpracovaný (draft)
  doklad." resp. „Smazat lze jen rozpracovaný (draft) doklad." (obojí HTTP 422).
- **Zaúčtování** doklad zamkne, přidělí mu **číslo řady** a teprve teď se promítne do
  stavu zásob a skladové knihy karty (podrobně k číslování § 33.4.2). Opakované
  kliknutí na Zaúčtovat u už zaúčtovaného dokladu není chyba — systém ho jen vrátí
  beze změny (bezpečné proti dvojkliku). Zaúčtovat stornovaný doklad ale nejde
  („Stornovaný doklad nelze zaúčtovat.", HTTP 422). Po zaúčtování už doklad ani jeho
  řádky nejde editovat.
- **Storno** je možné jen u zaúčtovaného dokladu — storno draftu nebo už jednou
  stornovaného dokladu odmítne chybou „Stornovat lze jen zaúčtovaný doklad." resp.
  „Doklad už byl stornován." (obojí HTTP 422). Storno založí **protidoklad**, který
  pohyb otočí zpět **beze změny hodnot** — množství, jednotková cena i hodnota řádků
  se do protidokladu zkopírují přesně tak, jak byly na originálu (hodnotová
  neutralita), otáčí se jen typ dokladu (příjemka ↔ výdejka; u převodky se prohodí
  zdrojový a cílový sklad) a datum protidokladu zůstává shodné s originálem. Popisu
  protidokladu se automaticky předsadí „Storno {číslo originálu}"; volitelný **důvod
  storna**, pokud ho vyplníš, se připojí za pomlčku (typicky pro obsah účetního
  případu ho vyplníš, ale technicky vyplnění vynucené není). Doklad samotný zůstává
  ve stavu Stornováno, historie se nemaže. Storno odmítne i situace, kdy by
  protidoklad sám o sobě způsobil zápor na skladě (typicky storno starší příjemky
  poté, co mezitím proběhl další výdej) — stejnou chybou „Nedostatek zásob", nebo
  pokud na cílovém skladu právě probíhá inventura (§ 33.7) — chybou „Na skladu
  probíhá inventura — dokončete ji před zaúčtováním pohybu." (HTTP 409).

Doklad má i **Původ (origin)** — informaci, odkud vznikl: **Ručně**, **Vydaná
faktura**, **Dobropis**, **Přijatá faktura** nebo **Inventura**. Doklady s jiným
původem než „Ručně" typicky vznikají automaticky (viz § 33.5 a § 33.7) a v editoru
se u nich zobrazí odkaz na zdrojový doklad.

### 33.4.2 Číslování dokladů

Číslo dokladu má formát **`PRI-RRRR-NNNN`** (příjemka), **`VYD-RRRR-NNNN`** (výdejka)
nebo **`PRE-RRRR-NNNN`** (převodka) — prefix, rok dokladu a čtyřmístné pořadové
číslo. Číslování běží **odděleně pro každou firmu, každý typ dokladu a každý rok**
(rok se bere z data dokladu, ne z data zaúčtování), takže si každá řada nezávisle
začíná od 0001. Číslo se přidělí teprve při zaúčtování, v rámci téže databázové
transakce jako zápis pohybu — přidělení je chráněné zamykacím dotazem, aby ani
souběžné zaúčtování dvou dokladů ve stejné vteřině nemohlo vygenerovat duplicitní
číslo. Rozdílové doklady z uzavřené inventury (§ 33.7) čerpají čísla ze **stejné**
běžné řady jako ruční doklady — nemají žádný zvláštní prefix.

### 33.4.3 Editor dokladu

Nový doklad založíš tlačítkem **Nová příjemka** nebo **Nová výdejka** v seznamu (u
nového dokladu bez vazby si typ dokladu i přepneš přímo v editoru — Příjemka /
Výdejka / Převodka). Hlavička: **sklad** (u převodky zvlášť „odkud" a „kam"),
**datum dokladu**, **popis** (povinný, jde o obsah účetního případu dle § 11/1/c
zákona o účetnictví) a nepovinný **partner** (volný text — dodavatel/odběratel).

**Řádky dokladu**: skladová karta (našeptávač podle SKU/názvu), **množství**,
u příjemky i **jednotková cena** (u výdejky/převodky se cena nezadává — dopočítá se
automaticky z klouzavého průměru při zaúčtování) a poznámka. U výdejky/převodky se
u každého řádku zobrazuje náhled **dostupného množství** na zvoleném skladu — je jen
informativní, závaznou kontrolu dělá až zaúčtování; pokud množství nesedí, zobrazí se
u řádku hláška ve tvaru „{SKU} — {název}: požadováno {X}, skladem {Y}".

Akce v hlavičce editoru: **Zaúčtovat** (uloží a rovnou zaúčtuje), **Uložit koncept**,
**Tisk (PDF)** a u zaúčtovaného dokladu **Stornovat** (přes modální dialog s polem
„Důvod storna").

### 33.4.4 Vedlejší pořizovací náklady

U příjemky ve stavu rozpracováno (jen tehdy — po zaúčtování už se položky nedají
přidávat) můžeš přidat libovolný počet položek **vedlejších pořizovacích nákladů**
(doprava, clo, provize apod. podle § 49 odst. 1 vyhlášky 500/2002 Sb.): popis, částka
a způsob rozpuštění — **podle hodnoty** (výchozí) nebo **podle množství** přijímaných
řádků.

Algoritmus rozpouštění je čistě celočíselný (v haléřích), aby součet rozpuštěných
částí vždy přesně souhlasil se zadanou částkou nákladu:

- Podíl každého řádku se počítá jako `částka × (podíl řádku na základně) `, kde
  základnou je buď součet hodnot řádků (u „podle hodnoty"), nebo součet množství
  (u „podle množství") — výsledek se **zaokrouhlí vždy dolů** (na celé haléře).
- Rozdíl mezi zadanou částkou a součtem takto zaokrouhlených podílů (vždy nezáporný,
  typicky pár haléřů) se celý přičte k **jednomu jedinému** řádku — tomu s nejvyšší
  hodnotou; při shodě hodnot víc řádků vyhrává řádek s nižším pořadím.
- Ve výjimečném případě, kdy je základna nulová (např. všechny řádky mají nulovou
  hodnotu i množství), se náklad rozdělí rovnoměrně a případný zbytek po haléřích jde
  postupně na první řádky (ne na řádek s nejvyšší hodnotou).
- Nákladová položka s nulovou nebo zápornou částkou se přeskočí a nerozpouští se
  vůbec.

Rozpuštěná částka se u řádku uloží zvlášť jako informativní **vedlejší náklad**, ale
zvyšuje i celkovou hodnotu, ze které se počítá klouzavý průměr (viz worked example
v § 33.3.1, kde druhý příjem obsahuje dopravu 200 Kč).

## 33.5 Vazba na faktury

### 33.5.1 Automatický výdej při vystavení faktury

Na řádku [vydané faktury](15_Faktura_editor.md) můžeš vybrat **skladovou kartu** a
**sklad**, ze kterého se má zboží vydat — zobrazí se náhled dostupného množství
stejně jako v editoru skladového dokladu.

Je-li zapnutá **automatická výdejka** (§ 33.1), při vystavení běžné/finální faktury
s takto napojenými řádky systém automaticky založí a rovnou zaúčtuje výdejku
(původ „Vydaná faktura"), v jedné transakci se samotným vystavením faktury. Pokud má
faktura řádky z víc skladů, založí se samostatná výdejka pro každý sklad. Proforma
a daňový doklad k platbě skladem nehýbou — čerpá se až finální faktura. Storno
faktury (interní zrušení) automaticky stornuje i navázanou výdejku a **dobropis**
zboží vrátí zpátky na sklad **v původní pořizovací ceně** výdeje k faktuře, ke které
se dobropis vztahuje.

Datum automatické výdejky nebo vratky odpovídá **DUZP faktury**; datum vystavení se
použije jen tehdy, když doklad DUZP nemá. Záporný skladový řádek na běžné faktuře
se zpracuje jako vratka na sklad, nikoli jako další výdej. Administrátorské smazání
vystavené faktury před smazáním atomicky stornuje i všechny její automatické
skladové doklady.

Pokud na skladě není dost zboží, vystavení faktury **skončí chybou „Nedostatek
zásob pro výdej"** (HTTP 409) ještě předtím, než se spotřebuje číslo řady faktury —
faktura zůstane ve stavu koncept a dá se opravit (jiné množství, jiný sklad, nebo
napřed naskladnit). Na detailu faktury najdeš i přehled výdejek/vratek navázaných na
daný doklad.

### 33.5.2 Naskladnění z přijaté faktury

Na detailu [přijaté faktury](23_Prijate_faktury.md) je tlačítko **Naskladnit**, pokud
má alespoň jeden dosud nenaskladněný řádek navázaný na skladovou kartu. Faktura bez
jediné takové vazby tlačítko nenabízí. Akce otevře průvodce naskladněním. Průvodce
nabídne řádky faktury s vazbou na skladovou
kartu a **zbývající množství k naskladnění** — spočítá se jako fakturované množství
mínus součet množství, které už bylo naskladněno dřívějšími (zaúčtovanými) příjemkami
napojenými na tentýž řádek faktury; pokud by ses pokusil/a naskladnit víc, systém to
odmítne chybou „Množství přesahuje zbývající k příjmu z faktury" (HTTP 409, s malou
tolerancí na zaokrouhlení cca 0,0005 jednotky) — kontroluje se hned při zakládání
konceptu příjemky, ne až při zaúčtování. Pole se dá i ručně přepsat na menší množství
(částečné naskladnění), k jednotlivým řádkům lze zvolit existující kartu, napojit
jinou nebo rovnou z popisu řádku faktury **založit novou skladovou kartu**.

Volitelně lze zahrnout i **vedlejší pořizovací náklady** — kandidáti se nabídnou
automaticky: jsou to řádky **téže** přijaté faktury, které nemají napojenou žádnou
skladovou kartu (typicky položka za dopravu vedle položek zboží na jedné faktuře);
modul nehledá náklady na jiných fakturách stejného dodavatele ani podle textu popisu.
Náklady se rozpustí do ceny přijímaného zboží stejným algoritmem jako v editoru
skladového dokladu (§ 33.4.4). Po potvrzení vznikne **draft příjemka** (původ
„Přijatá faktura") ve zvoleném skladu a datu, kterou pak podle potřeby doplníš a
zaúčtuješ v modulu Skladové doklady.

Pokud se obsah přijaté faktury po dřívějším naskladnění změní (typicky přepsáním
řádků faktury, které vazbu na starou příjemku „osiří"), průvodce na to upozorní, aby
sis ověřil/a, jestli naskladněné množství pořád odpovídá aktuálnímu obsahu faktury.

## 33.6 Sklady (více skladů)

Firma může mít libovolný počet **skladů** (v kódu není žádný horní limit) — najdeš je
na stránce **E-shop**, záložka **Sklady**. Ke každému skladu zadáváš **kód**
*(povinný, max 20 znaků, musí být v rámci firmy unikátní — jinak „Sklad s tímto kódem
už existuje", HTTP 409)*, **název** *(povinný, max 100 znaků)*, poznámku a příznaky
**Výchozí sklad** a **Aktivní**. Tabulka u každého skladu ukazuje aktuální
**celkovou hodnotu** zásob na něm.

Výchozí sklad se automaticky předvyplní při zakládání nového skladového dokladu;
výchozí smí být vždy jen **jeden** sklad — nastavením nového výchozího se příznak
u předchozího výchozího skladu sám shodí. Sklad, který má nenulový stav zásob nebo
jakékoli skladové pohyby (na kterémkoli z obou směrů převodky), **nejde smazat** —
pokus o smazání vrátí chybu „Sklad nelze smazat — má nenulový stav nebo skladové
pohyby. Deaktivujte jej místo mazání." (HTTP 409) a nabídne deaktivaci místo mazání.

## 33.7 Inventury

**Sklad → Inventury** slouží k fyzické kontrole skutečného stavu zásob dle
§ 29–30 zákona o účetnictví. Založíš ji tlačítkem **Nová inventura** — zvolíš
**sklad**, **datum**, způsob zjištění skutečného stavu a osoby odpovědné za zjištění
stavu a za provedení inventury; poznámka je nepovinná.

Na daném skladu smí běžet vždy jen **jedna otevřená** inventura (ve stavu Založena
nebo Probíhá sčítání) — dokud ji neuzavřeš, další na tentýž sklad založit nejde, a to
bez ohledu na zvolené datum („Na skladu už je rozpracovaná inventura.", HTTP 409).
Nezávisle na tom je i v databázi vynucené, že na jeden sklad a den existuje nejvýš
jeden záznam inventury vůbec.

Průvodce inventurou má tři kroky (stavy `draft` → `counting` → `closed`, striktně
jednosměrné — inventuru nejde smazat ani vrátit do předchozího kroku):

1. **Založení** — inventura je ve stavu **Založena**; tlačítkem **Zahájit sčítání**
   se pořídí snapshot očekávaných stavů (množství i hodnota) **k rozhodnému datu
   inventury** replayem skladové knihy. Zahrne všechny aktivní karty firmy, i ty bez
   pohybu, a také neaktivní karty s nenulovou zásobou k danému dni. Inventura přejde do
   stavu **Probíhá sčítání**. Po dobu sčítání nelze na daném skladu zaúčtovat žádný
   jiný skladový pohyb (příjemku, výdejku ani převodku z/do něj) — pokus o to skončí
   chybou „Na skladu probíhá inventura — dokončete ji před zaúčtováním pohybu."
   (HTTP 409); totéž platí i pro storno staršího dokladu na tomto skladu.
2. **Sčítání** — u každé položky zadáš **skutečně napočítané množství**; systém
   průběžně dopočítává **rozdíl** oproti očekávanému stavu (zvýrazněný, pokud není
   nulový). Tlačítko **Napočítat vše dle očekávání** rychle předvyplní všechny
   řádky očekávanou hodnotou (pro položky, které sedí). Rozepsané počty jde průběžně
   ukládat tlačítkem **Uložit průběh**, aniž by se inventura uzavřela. U kladného
   rozdílu se zadává reprodukční pořizovací cena za jednotku; systém nabídne cenu
   očekávaného stavu nebo poslední známou cenu. Přebytek bez kladné ceny nelze uzavřít.
3. **Rekapitulace** — po uzavření (tlačítko **Uzavřít**, s potvrzovacím dialogem —
   akci nejde vzít zpět) se zobrazí jen řádky s nenulovým rozdílem. Uzavření
   v jedné databázové transakci vytvoří (podle znaménka rozdílu) jednu souhrnnou
   **rozdílovou příjemku** pro všechny přebytky a/nebo jednu souhrnnou **rozdílovou
   výdejku** pro všechna manka, obě rovnou zaúčtované, s původem „Inventura" a
   popisem „Inventurní přebytek — inventura #…" resp. „Inventurní manko — inventura
   #…". Přebytek se ocení uloženou reprodukční pořizovací cenou, manko se
   ocení standardně jako běžný výdej (klouzavým průměrem v okamžiku zaúčtování).
   Číslo dokladu čerpají ze **stejné** řady jako ruční doklady (§ 33.4.2), žádný
   zvláštní prefix pro inventuru neexistuje. Inventura přejde do stavu **Uzavřena**
   a už se nedá znovu otevřít; z rekapitulace se na vzniklé rozdílové doklady dá
   proklikat. Uzavřenou inventuru lze vytisknout jako **inventurní soupis PDF**;
   obsahuje rozhodný den, okamžik zahájení a ukončení, způsob zjištění, odpovědné
   osoby, všechny řádky a podpisové záznamy.

> [!TIP]
> Pokud v kroku sčítání jen uložíš rozpracovaný stav a odejdeš, inventura zůstává
> „Probíhá sčítání" — nezapomeň se k ní vrátit a **uzavřít** ji, jinak zůstane blokovat
> zaúčtování ostatních dokladů na daném skladu.

## 33.8 Skladové sestavy

**Sklad → Sestavy** nabízí dvě záložky:

- **Stav zásob** — aktuální množství, průměrná cena a hodnota po jednotlivých
  kartách a skladech k okamžiku zobrazení; řádky pod nastaveným minimem se zvýrazní.
  Filtr jen na sklad (bez data — je to okamžitý stav).
- **Ocenění** — stejný přehled, ale **k libovolnému historickému datu** (přepočet ze
  skladové knihy zpětně — systém přehraje všechny zaúčtované pohyby firmy do
  zadaného data znovu od nuly). Pokud má firma přes **50 000** zaúčtovaných/
  stornovaných skladových řádků, přepočet k historickému datu odmítne s hláškou
  „Příliš mnoho skladových pohybů — sestava k historickému datu se pro tak velký
  objem generuje asynchronně." (HTTP 422) — v tom případě zvol kratší období nebo
  aktuální den, kde se historický přepočet nepoužívá.

Obě sestavy mají **součtový řádek** (počet položek a celková hodnota) a jdou
exportovat do **PDF** i **XLSX**; každý export se zaznamenává do žurnálu aktivit
firmy (typ, formát, čas, uživatel).

## 33.9 Kolik toho vlastně máš — skladem, rezervováno, na cestě, u dodavatele

Samotné číslo „skladem" na otázku *„můžu to prodat?"* neodpovídá. Část zásoby už
je vyfakturovaná zákazníkovi a jen fyzicky nevydaná, další kusy jsou objednané
u dodavatele a ještě nedorazily, a dodavatel má ve svém skladu ještě další.
Modul proto vede **čtyři množstevní veličiny** a dvě dopočtené hodnoty.

| Veličina | Co znamená | Odkud se bere |
|---|---|---|
| **Skladem** (`on_hand`) | fyzický stav na skladě | zaúčtované skladové doklady (§ 33.4) |
| **Rezervováno** (`reserved`) | vyfakturováno zákazníkovi, ale ještě nevydáno ze skladu | řádky vystavených faktur bez zaúčtované výdejky (§ 33.9.2) |
| **Na cestě** (`in_transit`) | objednáno u dodavatele a ještě nedodáno | objednávky u dodavatele (§ 33.11) |
| **U dodavatele** (`at_vendor`) | kolik toho podle svého ceníku drží dodavatel | nabídky dodavatelů (§ 33.10) |

Z nich se počítají dvě odvozené hodnoty:

```text
    prodejné (sellable)      = skladem − rezervováno
    volně k dispozici (ATP)  = skladem − rezervováno + na cestě
```

> [!IMPORTANT]
> **Zboží na cestě záměrně nezvyšuje to, co se smí nabízet k prodeji.**
> Do veličiny *prodejné* — tedy do čísla, které je určené e-shopu — se **na cestě
> nepočítá**. Prodávat něco, co ještě nedorazilo, je obchodní riziko (dodavatel
> nedodá, dodá později, dodá míň) a tohle rozhodnutí má udělat firma vědomě, ne
> aplikace za ni. *Volně k dispozici (ATP)* je naproti tomu **plánovací** číslo pro
> nákup a rozhodování uvnitř firmy — tam „na cestě" smysl dává, protože jde o to,
> kdy zboží bude, ne co se dá slíbit dnes.

**Žádná z těch čtyř veličin se nikam neukládá** — počítají se v okamžiku dotazu
z objednávek a dokladů. Objednané ani rezervované zboží nemá pořizovací cenu,
nevstupuje do rozvahy, a proto nepatří mezi skladové stavy, ze kterých se dělá
ocenění (§ 33.3) ani inventura (§ 33.7) — objednávka ani rezervace se skladovou
knihou nehýbe.

Veličiny se počítají v tisícinách jednotky, stejně jako zbytek skladu, takže na
nich nevzniká zaokrouhlovací chyba.

### 33.9.1 Kde je uvidíš

Na **detailu skladové karty** (§ 33.2.1) jsou nahoře čtyři dlaždice:
**Skladem** (pod ní menším písmem *Volně k dispozici*), **Rezervováno**,
**Na cestě** (dlaždice je proklik na objednávky dané karty; pod číslem je
*Očekáváno {datum}* — nejbližší termín dodání ze všech otevřených objednávek)
a **U dodavatele**.

Karta, která nemá jediný pohyb ani objednávku, ukazuje ve všech čtyřech
dlaždicích **nuly** — ne prázdno. Je to záměr: „nula" je odpověď, „—" by byla
chyba (viz § 33.9.4).

Hodnota **prodejné** vlastní dlaždici nemá; je to číslo pro strojové odběratele
(REST API `GET /api/stock/quantities` a [MCP server](101_MCP_server.md)),
odkud si ho bere e-shop. Může vyjít i **záporně** — to znamená, že je
vyfakturováno víc, než je fyzicky skladem, a záměrně se to neschovává nulou.

> [!NOTE]
> Náhled dostupnosti u řádku faktury a skladového dokladu (§ 33.4.3, § 33.5.1)
> pracuje pořád s **prostým fyzickým stavem**, ne s prodejným množstvím —
> rezervace se od něj neodečítají. Je to jen informativní nápověda; závaznou
> kontrolu na zápornou zásobu dělá až zaúčtování.

### 33.9.2 Rezervace

**Rezervaci vytvoří řádek vystavené faktury napojený na skladovou kartu, ke
kterému ještě neexistuje zaúčtovaná výdejka.** Rezervováno je tedy množství, které
sice fyzicky leží ve skladu, ale už je slíbené konkrétnímu odběrateli.

Rezervaci **netvoří**: koncept faktury, proforma (zálohová faktura) ani
stornovaná faktura. Storno faktury rezervaci uvolní. Naopak **storno výdejky
rezervaci vrátí** — protidoklad se do součtu započítá se záporným znaménkem,
takže se řádek faktury zase tváří jako nevydaný.

> [!IMPORTANT]
> **Firmy se zapnutou automatickou výdejkou (§ 33.1) uvidí v rezervacích trvale
> nulu — a je to správně.** Když se výdejka zakládá a účtuje v tomtéž okamžiku
> jako vystavení faktury, žádné okno mezi „slíbeno" a „vydáno" prostě neexistuje
> a rezervovat není co. Rezervace mají smysl pro firmy, které automatickou
> výdejku **vypnuly** a zboží vydávají ze skladu ručně (typicky později, při
> expedici) — teprve tam vzniká mezera, ve které by se stejný kus dal prodat
> podruhé.

Rezervace se sčítají **za celou kartu**; filtr na sklad je jen omezením, ne
rozpadem. U každé karty je k dispozici i rozpad na konkrétní faktury (číslo,
odběratel, datum vystavení, splatnost, množství).

### 33.9.3 Na cestě

„Na cestě" je součet toho, co je na **otevřených objednávkách** u dodavatelů
a ještě nedorazilo. Za každý řádek objednávky:

```text
    na cestě = max(0, potvrzeno (jinak objednáno) − uzavřený zbytek − přijato)
```

- **Potvrzené množství přebíjí objednané.** Objednáš 10 ks, dodavatel potvrdí 7 →
  na cestě je 7, ne 10.
- **Přijaté množství se odečítá.** Z 10 objednaných přijmeš 4 → na cestě zůstane 6
  a objednávka přejde do stavu *Částečně přijato*.
- **Storno příjemky vrátí zboží zpátky „na cestu"** — protidoklad nese stejnou
  vazbu na řádek objednávky, takže se odečet zruší.
- **Nadměrná dodávka nikdy nedá zápor** — výsledek je useknutý na nule.
- Řádek objednávky **bez skladové karty** (doprava, služba) do „na cestě" nevstupuje.

Do „na cestě" se počítají objednávky ve stavech **Odesláno, Potvrzeno
a Částečně přijato**. Koncept se nepočítá (ještě to není závazek), stejně jako
Přijato, Uzavřeno a Stornováno.

Firma si může přepnout, že se má počítat **až od potvrzení** dodavatelem (pak se
započítávají jen stavy Potvrzeno a Částečně přijato) — přepínač
`stock_in_transit_from` na firmě.

Přepínač najdeš v **Nastavení → Daně a účetnictví**, v sekci skladu, jako
„Zboží se počítá „na cestě" od stavu". Výchozí je *Odesláno*. Pokud je pro tebe
odeslaná, ale nepotvrzená objednávka příliš měkký příslib, přepni na
*Potvrzeno* — pak se do „na cestě" započítají až objednávky, které dodavatel
potvrdil.

Rozpad „na cestě" ukáže, ze kterých konkrétních objednávek se číslo skládá
(číslo objednávky, stav, dodavatel, sklad, očekávaný termín, množství), seřazený
podle očekávaného termínu.

### 33.9.4 U dodavatele

Poslední veličina je součet **skladovosti hlášené dodavateli** — sečtou se
hodnoty *Skladem u dodavatele* ze všech **aktivních** nabídek dané karty
(§ 33.10). Nabídka bez vyplněného množství přispěje nulou.

Je to **cizí, orientační údaj** — nikdo ho neověřuje a aplikace podle něj nic
neblokuje. Slouží k rozhodnutí „má to vůbec smysl objednávat?" ještě předtím,
než dodavateli zavoláš.

## 33.10 Nabídky dodavatelů („u dodavatele")

**Sklad → U dodavatele** je katalog dvojic **zboží × dodavatel** — kdo dané zboží
nabízí, za kolik, v jaké lhůtě a kolik ho má. Je to podklad pro objednávání
(§ 33.11), pro návrh doplnění zásob (§ 33.12) i pro cenotvorbu e-shopu, která si
z preferovaného dodavatele bere nákupní cenu (viz
[§ 34.8.2](34_Eshop.md#3482-nakupni-cena-cenova-baze)).

Tatáž data se dají editovat i z karty zboží, záložka **Dodavatelé**
([§ 34.9](34_Eshop.md#349-dodavatele-zbozi)) — je to jeden a týž záznam, jen
jednou po kartách a jednou přes celý katalog.

> [!TIP]
> **Karta funguje dřív, než cokoli objednáš.** Skladovou kartu si můžeš založit
> bez jediné příjemky, bez zásoby a bez objednávky, navěsit na ni nabídky
> dodavatelů s cenou a skladovostí a teprve pak řešit, jestli a od koho nakoupíš.
> Karta bez pohybu se v seznamech i v množstevních veličinách chová korektně —
> ukáže nuly, ne prázdno.

### 33.10.1 Pole nabídky

| Pole | Význam |
|---|---|
| **Zboží** *(povinné)* | skladová karta (SKU + název) |
| **Dodavatel** *(povinný)* | klient s **rolí dodavatele** v adresáři ([§ 18](18_Klienti.md)) |
| **Kód u dodavatele** | katalogové číslo, pod kterým položku vede dodavatel (max 80 znaků) — tiskne se na objednávku a páruje se podle něj ceník |
| **Nákupní cena** | cena bez DPH, za kterou od něj nakupuješ |
| **Měna** | ISO kód, výchozí `CZK` |
| **Lhůta (dní)** | dodací lhůta |
| **Skladem u dodavatele** | množství, které dodavatel hlásí — sčítá se do veličiny *U dodavatele* (§ 33.9.4) |
| **Dostupnost** | Skladem / Na objednávku / Nedostupné / **Neznámá** *(výchozí)* |
| **Min. objednávka** | minimální odběr; doplnění zásob pod něj nikdy nenavrhne méně |
| **Balení** | velikost balení; objednávané množství se zaokrouhluje **nahoru** na celá balení |
| **Cena platí do** | do kdy ceníková cena platí; prázdné = bez omezení |
| **Hlavní dodavatel** | nejvýš **jeden na kartu** — nastavením se příznak ostatním sám shodí |
| **Aktivní** | vyřazená nabídka zůstane v evidenci kvůli historii, ale nikam se nenabízí |
| **Poznámka** | volný text (max 255 znaků) |

Ke každé nabídce se navíc eviduje **kdy naposled se změnilo hlášené množství**
a **odkud data pocházejí** (ručně / import ceníku / automatický kanál). Údaj
o stáří je čistě informativní — nabídka nikdy „nevyprší" sama od sebe a nic se
podle stáří neblokuje.

**Jedna dvojice zboží × dodavatel smí existovat jen jednou.** Pokus přidat
druhou nabídku téhož dodavatele k téže kartě skončí chybou „Tento dodavatel už
u karty nabídku má — upravte ji." (HTTP 409).

Každý zápis nabídky (založení, úprava i smazání) **spustí přepočet prodejních
cen** té karty — u karet s cenovou bází „Ruční" se totiž prodejní cena odvíjí od
nákupní ceny hlavního dodavatele.

Seznam ukazuje u každé nabídky i **Naši zásobu** (kolik toho máš ty), takže se
dá porovnat vlastní stav proti tomu, co drží dodavatel. Filtrovat jde fulltextem
(SKU, název, kód u dodavatele, dodavatel), podle dostupnosti, jen aktivní a jen
hlavní dodavatele.

### 33.10.2 Import ceníku dodavatele

Tlačítko **Import ceníku** nahraje ceník v **XLSX nebo CSV do 2 MB**. U CSV se
oddělovač (`;` nebo `,`) rozpozná z prvního řádku sám a soubor se čeká v UTF-8;
u XLSX se čte **jen první list**, hodnoty se berou tak, jak jsou zapsané (buňka
začínající `=` zůstane textem, vzorec se nevyhodnocuje).

**Sloupce se poznají podle záhlaví**, žádné ruční mapování se nedělá. Názvy se
porovnávají bez ohledu na velikost písmen, diakritiku a oddělovače, takže
`Nákupní cena` i `nakupni_cena` sedí stejně:

| Sloupec | Povinný | Alternativní názvy | Poznámka |
|---|---|---|---|
| `sku` | **ano** | kod, code, katalog | SKU **existující** karty |
| `dodavatel` | ano *(nebo `ico`)* | vendor, supplier, firma | název dodavatele |
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
odmítne celý. **Sloupec, který v souboru není, se nemění**; sloupec, který tam je
a je prázdný, hodnotu **vymaže** (výjimkou jsou měna, hlavní dodavatel a aktivní —
prázdná hodnota se u nich ignoruje).

Chování importu:

- **Identita řádku je dvojice SKU karty × dodavatel** — přesně tak, jak je
  omezená i v datech. Páruje se **jen podle SKU**, nikdy podle EAN.
- **Import nikdy nic nemaže a nikdy nezakládá karty ani dodavatele.** Neznámé
  SKU je chyba řádku („Karta zboží se SKU „X" neexistuje (založte ji nejdřív)."),
  stejně tak neznámý dodavatel. Když má víc dodavatelů stejný název, řádek skončí
  chybou s výzvou doplnit sloupec `ico`.
- Stejná dvojice zboží + dodavatel dvakrát v jednom souboru = chyba řádku.
- Existující nabídka se aktualizuje jen v těch polích, která se skutečně liší;
  beze změny se řádek započítá jako **Beze změny**.
- Po ostrém importu se přepočtou prodejní ceny všech dotčených karet.

**Import je dvoufázový a je vše, nebo nic.** Nejdřív běží **náhled** (výchozí
zaškrtnuté „Jen náhled (nic nezapisovat)"), který vypíše po řádcích stav
**Nová / Změna / Beze změny / Chyba** a u změn i konkrétní `z → na` u každého
pole; souhrn nahoře ukazuje počty. Ostré tlačítko **Provést import** se objeví,
teprve když je náhled bez jediné chyby. Ostrý běh s chybou nezapíše **nic**
a vrátí hlášku „Import obsahuje chyby — nic nebylo zapsáno."

> [!WARNING]
> **V tomto vydání je import ceníku funkční jen v náhledu na úrovni služby —
> HTTP endpoint `POST /api/stock/vendor-offers/import` selže dřív, než se ke
> zpracování souboru dostane.** Tlačítko **Import ceníku** proto v aplikaci
> zatím nedoběhne. Než bude opravené, zadávej změny ceníku ručně na nabídkách
> (§ 33.10.1) nebo přes [MCP server](101_MCP_server.md), který nabídky umí
> zakládat i upravovat po jedné.

## 33.11 Objednávky u dodavatele

**Sklad → Objednávky dodavatelům** je evidence toho, co jsi u dodavatele objednal
a co z toho ještě nedorazilo. Objednávka je jediným zdrojem veličiny **na cestě**
(§ 33.9) a zároveň podkladem pro příjemku — zboží z ní naskladníš i dřív, než
přijde faktura.

> [!IMPORTANT]
> **Objednávka není účetní případ.** Dokud nepřejde vlastnictví zboží, nevzniká
> závazek ani zásoba — objednávka proto **nezakládá žádný zápis v účetním deníku**
> a nemá vůbec žádnou kontaci. Do účetnictví (a do ocenění zásob) vstoupí až
> zaúčtovaná příjemka. Sazba DPH na řádku je čistě orientační, aby seděl součet
> objednávky; objednávka není daňový doklad a nárok na odpočet z ní nevzniká.

### 33.11.1 Životní cyklus objednávky

Objednávka prochází sedmi stavy. Ručně se přepínají jen čtyři přechody
(**Odeslat**, **Potvrdit**, **Zavřít zbytek**, **Storno**, plus **Znovu otevřít**);
stavy *Částečně přijato* a *Přijato* si systém nastavuje sám podle toho, kolik
zboží se z objednávky skutečně naskladnilo.

```text
   ┌─────────┐   Odeslat    ┌──────────┐   Potvrdit   ┌───────────┐
   │ Koncept │─────────────►│ Odesláno │─────────────►│ Potvrzeno │
   │  draft  │ přidělí číslo│   sent   │              │ confirmed │
   └────┬────┘ → „na cestě" └────┬─────┘              └─────┬─────┘
        │ Smazat                 │                          │
        ▼                        └───────────┬──────────────┘
     (zmizí)                                 │ zaúčtování příjemky
                                             ▼
                              ┌──────────────────────────┐
                              │    Částečně přijato      │
                              │   partially_received     │
                              └─────────────┬────────────┘
                                            │ dorazilo všechno
                                            ▼
                                      ┌───────────┐
                                      │  Přijato  │
                                      │ received  │
                                      └───────────┘

   Storno — z draft / sent / confirmed      Zavřít zbytek — z sent / confirmed /
   a jen dokud nic nedorazilo:              partially_received / received:
        ──► Stornováno (cancelled)               ──► Uzavřeno (closed)
            konečný stav, zpět už ne                 ──► Znovu otevřít
```

| Přechod | Z jakého stavu | Co se stane |
|---|---|---|
| **Odeslat** | Koncept | Přidělí **číslo řady OBJ** (§ 33.11.3), zapíše okamžik odeslání a od té chvíle se zboží počítá jako **na cestě**. Objednávka bez jediného řádku se odeslat nedá. Opakované kliknutí nic nezkazí — vrátí objednávku beze změny a **další číslo nepropálí**. |
| **Potvrdit** | Odesláno (i opakovaně u Potvrzeno) | Zapíše, co dodavatel potvrdil: volitelně jiný **termín** na hlavičce a **potvrzené množství** i termín u jednotlivých řádků. Od té chvíle se „na cestě" počítá z potvrzeného množství, ne z objednaného. Prázdný termín stávající nepřepíše. |
| *(automaticky)* | Odesláno / Potvrzeno | **Zaúčtování příjemky** navázané na objednávku ji samo přepne na *Částečně přijato* nebo *Přijato*. **Storno příjemky posun vrátí zpět** (Přijato → Částečně přijato → Odesláno/Potvrzeno) a množství se vrátí „na cestu". |
| **Zavřít zbytek** | Odesláno, Potvrzeno, Částečně přijato, Přijato | „Zbytek už nedorazí." Nedodané množství se na každém řádku odepíše jako stornované, takže zmizí z „na cestě" a doplnění zásob (§ 33.12) ho zase začne navrhovat k objednání. Koncept se zavírat nedá — ten se maže nebo stornuje. |
| **Storno** | Koncept, Odesláno, Potvrzeno | Zruší celou objednávku. **Odmítne se, jakmile z objednávky existuje jakýkoli příjem** — chybou „K objednávce už existuje příjem — místo storna uzavři nedodaný zbytek („Zavřít zbytek")" (HTTP 409). Storno je **konečné**, zpět už se z něj nedá. |
| **Znovu otevřít** | Uzavřeno | Vrátí uzavřenou objednávku mezi živé: zruší odepsaný zbytek a stav dopočítá podle toho, kolik se reálně přijalo. **Stornovanou objednávku znovu otevřít nelze.** |
| **Smazat** | Koncept | Smaže objednávku i s řádky. Odeslanou objednávku smazat nejde — ta se stornuje nebo uzavře. |

Upravovat se dá **jen koncept**. Pokus o úpravu odeslané objednávky vrátí chybu
„Upravovat lze jen rozpracovanou (draft) objednávku. Odeslanou objednávku uprav
přes potvrzení nebo uzavření zbytku." (HTTP 409) a v editoru je celý formulář
uzamčený.

> [!NOTE]
> Do plnění objednávky se počítají **jen řádky napojené na skladovou kartu**.
> Řádek za dopravu nebo službu (bez karty) vstupuje do ceny objednávky, ale ne do
> „objednáno / přijato / zbývá" a ani do veličiny na cestě.

### 33.11.2 Hlavička a řádky

**Hlavička**: **dodavatel** *(povinný, z adresáře klientů — typicky s rolí
dodavatele)*, **datum objednávky** *(povinné)*, **sklad**, na který se má dodat
*(povinný, musí být aktivní)*, **měna** *(povinná)*, volitelně **očekávané
dodání**, **kurz** (zobrazí se jen u cizí měny a je čistě orientační — ocenění
určí až příjemka nebo faktura), **reference dodavatele** (číslo, pod kterým
objednávku vede dodavatel), **poznámka** (tiskne se do PDF) a **interní poznámka**
(do PDF se netiskne).

**Řádek**: skladová karta *(nepovinná — bez ní jde o dopravu či službu)*, vlastní
**sklad** (přebije sklad z hlavičky), **kód u dodavatele**, **popis** *(povinný;
prázdný se doplní z názvu karty)*, **měrná jednotka** (výchozí „ks"),
**objednané množství** *(povinné, musí být větší než 0)*, **cena za jednotku**
*(nesmí být záporná)*, **sazba DPH** (orientační), **očekávané dodání řádku**
a poznámka.

Součty **Celkem bez DPH** a **Celkem včetně DPH** v hlavičce se počítají
z **objednaného** množství. Po potvrzení jiného množství dodavatelem se
nepřepočítávají — řádky v tabulce i v PDF už ale ukazují potvrzené množství,
takže se hlavičkový součet a součet řádků mohou rozejít. Ber ho jako orientační
hodnotu objednávky, ne jako fakturační podklad.

### 33.11.3 Číslování a PDF

Číslo objednávky má formát **`OBJ-RRRR-NNNN`** — prefix, rok z **data objednávky**
a čtyřmístné pořadové číslo. Prefix `OBJ` je výchozí a dá se firmě přenastavit
stejně jako u ostatních řad dokladů.

**Číslo se přiděluje až při odeslání**, ne při založení. Koncepty žádné číslo
nemají (v seznamu i v detailu je u nich text **„Koncept"**), takže si můžeš
připravit libovolné množství rozpracovaných objednávek, aniž bys spálil čísla
v řadě. Přidělení je chráněné zamykacím dotazem — souběžné odeslání dvou
objednávek nemůže vygenerovat duplicitu a při chybě se číslo nespotřebuje.

Tlačítko **PDF** vytiskne objednávku pro dodavatele (funguje i u konceptu, kde
místo čísla stojí „koncept #…"). PDF obsahuje objednatele a dodavatele s IČ/DIČ,
sklad dodání, datum objednávky, požadovaný termín, referenci dodavatele, měnu,
tabulku řádků (kód u dodavatele, položka, množství, MJ, cena/MJ, celkem, termín),
oba součty, veřejnou poznámku a podpisové řádky **Vystavil / Schválil**. Interní
poznámka se do PDF nedostane.

### 33.11.4 Příjem zboží z objednávky

Tlačítko **Příjem na sklad** (na detailu objednávky ve stavu Potvrzeno nebo
Částečně přijato) otevře dialog, který nabídne řádky se zbývajícím množstvím.
Po potvrzení vznikne **draft příjemka** s původem „Objednávka", navázaná na
objednávku i na jednotlivé její řádky. Skladem to zatím **nehne** — příjemku
zaúčtuješ standardně ve Skladových dokladech (§ 33.4.1) a teprve tím se zásoba
zvýší a stav objednávky přepočítá.

**Částečné dodávky** jsou normální stav: příjemek z jedné objednávky můžeš udělat
kolik chceš, každá odečte svůj díl ze zbývajícího množství.

**Nadměrná dodávka se ve výchozím stavu odmítne** — pokus přijmout víc, než
zbývá, skončí chybou „Množství přesahuje zbývající k příjmu z objednávky. Potvrď
nadměrnou dodávku, nebo množství uprav." (HTTP 409) s výpisem dotčených řádků
(požadováno / zbývá). Teprve když v dialogu zaškrtneš **Povolit nadměrné dodání**
(zaškrtávátko se objeví, až když nějaký řádek limit překročí), příjem projde
a dotčené řádky objednávky dostanou natrvalo badge **„Nadměrné dodání"**.
Objednané množství se přitom nikdy samo nezvyšuje — v objednávce zůstává to, co
jsi objednal.

#### Cena je zatím jen odhad

Pořizovací cenu na příjemce systém určuje v tomto pořadí:

1. **Z řádku přijaté faktury** navázaného na řádek objednávky (jednotková cena =
   částka řádku bez DPH ÷ množství). Cena je pak skutečná.
2. **Odhad z objednávky** — cena za jednotku z objednávky přepočtená kurzem
   z hlavičky. Řádek dostane v dialogu i na dokladu příznak **„Odhad"** a nahoře
   svítí varování **„Cena je odhad z objednávky — po doručení faktury přeceňte."**
3. **Ručně přepsaná cena** v dialogu má přednost před obojím.

> [!WARNING]
> Odhadnutá cena **vstupuje rovnou do váženého klouzavého průměru** karty
> (§ 33.3) a tím i do ocenění všech následujících výdejů. Není to jen kosmetický
> údaj — dokud ji neopravíš, má karta špatnou průměrnou cenu.
>
> **Automatické přecenění příjemky po doručení faktury v aplikaci není.** Máš dvě
> cesty: buď nechat příjemku **v konceptu**, dokud faktura nedorazí, a cenu před
> zaúčtováním jen přepsat (nejlevnější varianta), nebo — je-li už zaúčtovaná —
> ji **stornovat** a přijmout znovu se správnou cenou. Storno je hodnotově
> neutrální a množství se navíc vrátí „na cestu", takže se objednávka rozpadne
> zpátky do částečně přijatého stavu a příjem jde zopakovat.

### 33.11.5 Seznam objednávek

Sloupce: **Číslo**, **Datum**, **Dodavatel**, **Sklad**, **Očekáváno**,
**Objednáno**, **Přijato**, **Zbývá**, **Celkem bez DPH** a **Stav**. Filtry:
fulltext, **stav** (volba *Otevřené* zahrne koncepty, odeslané, potvrzené
i částečně přijaté), sklad, rozsah data objednávky a „očekáváno do". Sloupce
i hustotu řádků si nastavíš stejně jako u ostatních přehledů.

Na detailu objednávky je pod řádky sekce **Vzniklé příjemky** s prokliky na
jednotlivé skladové doklady.

### 33.11.6 Oprávnění

Čtení seznamu, detailu i PDF stačí běžné skladové oprávnění. Všechny zápisové
akce (založit, upravit, odeslat, potvrdit, zavřít, stornovat, znovu otevřít,
smazat, vytvořit příjemku i hromadné objednání) vyžadují samostatné oprávnění
**`stock.orders.write`** — role „skladník" s právem na skladové doklady tedy
objednávat nemůže, dokud jí právo nepřidáš. **Uživatelé klientského portálu se
k objednávkám nedostanou vůbec**, ani ke čtení, ani když mají skladové právo.

## 33.12 Doplnění zásob — co objednat

Doplnění zásob odpovídá na otázku *„co a kolik mám doobjednat?"*. Nejde jen
o seznam karet pod minimem — z návrhu se odečítá i to, co už je **na cestě**,
a přičítá to, co je **rezervované**.

### 33.12.1 Jak se navržené množství počítá

Postupně, pro každou **aktivní** kartu, která má vyplněnou **minimální zásobu**:

```text
  1) cílová hladina  = minimální zásoba × koeficient      (výchozí koeficient 1,0)
  2) schodek         = cílová hladina − skladem + rezervováno − na cestě
  3) je-li schodek ≤ 0 → kartu nenavrhovat vůbec
  4) zaokrouhlit schodek NAHORU na celá balení hlavního dodavatele
  5) výsledek zvednout aspoň na minimální odběr hlavního dodavatele
```

- **Rezervované se přičítá** — ty kusy sice fyzicky máš, ale už jsou slíbené
  někomu jinému, takže na doplnění minima nestačí.
- **Na cestě se odečítá** — a přesně v tomhle je celý smysl. Bez toho odečtu bys
  objednal podruhé to, co už je objednané.
- **Balení a minimální odběr** se berou z nabídky **hlavního dodavatele**
  (§ 33.10). Nemá-li karta nabídku, návrh zůstane v holém schodku, bez
  zaokrouhlení.
- Hlavního dodavatele vybírá pořadí **označený jako hlavní → nejnižší nákupní
  cena → nejstarší nabídka**; ručně označený hlavní dodavatel tedy porazí
  i levnějšího.

Vedle navrženého množství se u karty ukáže i **schodek** (surové číslo před
zaokrouhlením), aby bylo vidět, kolik z návrhu přidalo balení a minimální odběr.

**Karta bez vyplněné minimální zásoby se nikdy nenavrhne** — stejně tak
neaktivní karta. Zboží, které nakupuješ až na zakázku, tedy tímhle modulem
neobjednáváš; nastav mu minimum, nebo objednávej ručně (§ 33.11).

### 33.12.2 Příklad

Karta *KAB-230* má minimální zásobu **50 ks**, skladem je **12 ks**, z toho
**5 ks** drží nevydaná faktura, a **20 ks** je na cestě z otevřené objednávky.
Hlavní dodavatel prodává po **balení 24 ks** a jeho minimální odběr je **10 ks**:

| Krok | Výpočet | Výsledek |
|---|---|--:|
| Cílová hladina | 50 × 1,0 | 50 ks |
| Schodek | 50 − 12 + 5 − 20 | **23 ks** |
| Zaokrouhlení na balení | strop(23 ÷ 24) × 24 | 24 ks |
| Minimální odběr | max(24; 10) | **24 ks** |

Návrh tedy zní **objednat 24 ks**, přestože „chybí do minima" je na první pohled
38 ks (50 − 12). Kdyby se „na cestě" neodečítalo, návrh by zněl 48 ks a po
dodání obou objednávek by na skladě leželo o 24 ks víc, než je potřeba.

### 33.12.3 Jak se k němu dostaneš

Tlačítko **Doplnění zásob** je v hlavičce seznamu objednávek (§ 33.11.5).

> [!WARNING]
> **Vlastní obrazovka doplnění zásob v tomto vydání ještě není.** Tlačítko
> **Doplnění zásob** vede zatím jen na **seznam skladových karet s filtrem
> „jen pod minimem"** (§ 33.2) — a ten pracuje s **prostým** porovnáním
> „skladem < minimum". Nezná rezervace, neodečítá zboží na cestě a nenavrhuje
> množství. Kompletní výpočet podle § 33.12.1 i **hromadné založení objednávek
> z návrhu** (jedna objednávka na dodavatele, vždy jako koncept, karty bez
> dodavatele se vypíšou jako přeskočené) jsou zatím dostupné **jen přes
> [REST API](99_API.md) a [MCP server](101_MCP_server.md)** — asistenta se tedy
> zeptat můžeš, na obrazovce to zatím neuvidíš.

## 33.13 Omezení a tipy

- Modul podporuje jen **způsob B** účtování zásob (průběžná evidence bez účtování,
  promítnutí do účetnictví až uzávěrkou) — způsob A není v tomto vydání funkční,
  ačkoliv je pro něj v datech (`stock_method`) i posuzovacích pravidlech místo
  připravené.
- Záporný stav zásob **nejde nijak povolit** — nedostatek se musí vždy vyřešit
  příjmem/inventurou dřív, než doklad, který ho způsobuje, půjde zaúčtovat.
- Počet skladů ani počet skladových karet není v aplikaci nijak omezen; jediný
  reálný limit je **50 000 zaúčtovaných pohybů firmy** pro okamžitý přepočet
  historického ocenění (§ 33.8) — nad tuto hranici je potřeba zvolit kratší
  období.
- Skladová karta typu **Výrobek** se při uzávěrce zaúčtuje na **MD 123 / D 583**;
  při otevření roku se počáteční stav zrcadlově rozpustí.
- Zaúčtovaný doklad se needituje — jedinou cestou zpět je **storno** (protidoklad),
  ne oprava původního dokladu; opakované kliknutí na Zaúčtovat u už zaúčtovaného
  dokladu ale chybu nehlásí (je to bezpečné proti dvojkliku).
- Zobrazení karty v e-shopu je nezávislé na jejím skladovém typu — řídí ho
  samostatný příznak **Exportovat do e-shopu** (§ 33.2.2).

### 33.13.1 Co objednávky ještě neumí

Nákupní část modulu je první vydání a záměrně řeší jen evidenci objednaného
zboží. Tohle v ní **není**:

| Chybí | Náhradní řešení |
|---|---|
| **Účetní zápis z objednávky** | Není chyba, ale záměr — objednávka není účetní případ. Do deníku vstoupí až zaúčtovaná příjemka. |
| **Párování objednávka ↔ přijatá faktura** | Obrazovka ani akce pro spárování neexistuje. Objednávku a fakturu k sobě dohledáš ručně přes číslo objednávky (pole „Reference dodavatele" a popis příjemky). |
| **Kontrola cenové odchylky** faktura vs. objednávka | Neprovádí se; cenu z faktury si porovnej ručně. |
| **Odeslání objednávky e-mailem** | Tlačítko **Odeslat** znamená „označ za odeslanou", ne „odešli". PDF (§ 33.11.3) stáhni a pošli dodavateli sám. |
| **Automatické přecenění příjemky po doručení faktury** | Nechat příjemku v konceptu, nebo ji po zaúčtování stornovat a přijmout znovu (§ 33.11.4). |
| **Dropshipping** — objednávání zboží až na zakázku | Doplnění zásob pracuje jen s kartami, které mají **minimální zásobu**. Zboží na objednávku objednávej ručně (§ 33.11). Cenotvorbu pro dropshipping popisuje [§ 34.9.2](34_Eshop.md#3492-dropshipping-zbozi-bez-skladu). |
| **Schvalovací workflow objednávky** | PDF má podpisové pole „Schválil", ale žádný schvalovací krok v aplikaci není. |
| **Hromadné akce nad existujícími objednávkami** | Hromadně jde jen *zakládat* (z návrhu doplnění zásob); odeslat, uzavřít nebo stornovat se musí po jedné. |
| **Obrazovka doplnění zásob a hromadné objednání** | Zatím jen přes API a MCP (§ 33.12.3). |
| **Import ceníku dodavatele přes UI** | V tomto vydání nedoběhne (§ 33.10.2) — zadávej nabídky ručně nebo přes MCP. |
| **Přepnutí „na cestě až od potvrzení" v nastavení** | Pole existuje jen v databázi (§ 33.9.3). |

> [!TIP]
> Skladovou kartu nemusíš mít vždy založenou dopředu — v průvodci **naskladněním
> z přijaté faktury** (§ 33.5.2) ji jde založit rovnou z popisu řádku faktury, bez
> nutnosti přecházet napřed na Skladové karty. A naopak: kartu si můžeš založit
> **dlouho předtím**, než cokoli koupíš — bez zásoby, bez pohybu, jen s nabídkami
> dodavatelů (§ 33.10), a teprve podle nich se rozhodnout, jestli a od koho
> objednáš.
