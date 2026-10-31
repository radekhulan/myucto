# 25. AI extrakce faktur

> Návod, jak nastavit AI poskytovatele a nechat z PDF vytěžit přijaté i vydané
> faktury, a jak vytěžené doklady zkontrolovat, než je zaúčtujete. Pro každého,
> kdo nechce přepisovat faktury ručně. Nastavení poskytovatele dělá administrátor.

## 25.1 Kdy to potřebujete

- Máte hromadu PDF faktur od dodavatelů a nechcete je přepisovat ručně.
- Nahráváte vydané faktury z jiného systému jako PDF a potřebujete z nich koncepty.
- Po importu vidíte na faktuře žlutou hlášku **Ke kontrole** a nevíte, co s ní.
- Potřebujete, aby data z dokladů neopouštěla EU, nebo vůbec neopouštěla vaši infrastrukturu.
- Chcete změnit poskytovatele AI nebo model (například kvůli ceně či přesnosti).

[Přijaté](23_Prijate_faktury.md) i vydané faktury lze importovat z PDF pomocí AI extrakce. Extrakci provádí jeden z pěti
podporovaných poskytovatelů AI (Anthropic Claude, Azure OpenAI, OpenAI, Google Gemini nebo Ollama lokální). Volbu a
přihlašovací údaje nastavíte podle [§ 25.3](#253-krok-za-krokem-nastavit-poskytovatele-ai).

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavedení | Nastavit poskytovatele AI a vyzkoušet připojení | `Firma → AI nastavení`, postup v [§ 25.3](#253-krok-za-krokem-nastavit-poskytovatele-ai) |
| průběžně | Nahrát PDF přijatých faktur | `Nákup → AI import`, postup v [§ 25.4](#254-krok-za-krokem-importovat-prijate-faktury-z-pdf) |
| po každém importu | Zkontrolovat doklady s hlášením | okno **Kontrola vytěžených dokladů**, postup v [§ 25.5](#255-krok-za-krokem-zkontrolovat-vytezene-doklady) |
| podle potřeby | Nahrát PDF vydaných faktur jako koncepty | `Prodej → AI import`, postup v [§ 25.6](#256-krok-za-krokem-importovat-vydane-faktury-z-pdf) |

## 25.2 Než začnete

- **Oprávnění.** Nastavení poskytovatele vidí jen administrátoři (položka `Firma → AI nastavení`).
  Import přijatých faktur vyžaduje oprávnění k AI vytěžování přijatých faktur, import vydaných oprávnění vytvářet vydané faktury.
  Klient potřebuje také právo přijaté faktury vytvářet, povolení vytěžování u konkrétní firmy a nastaveného aktivního
  poskytovatele AI této firmy.
- **API klíč** zvoleného poskytovatele (u Ollamy klíč není potřeba). Postup získání je v dokumentaci poskytovatele.
- **Rezidence dat.** Pokud je pro vás důležitá EU, vyberte poskytovatele podle tabulky v
  [§ 25.9.14](#25914-eu-rezidence-dat-co-to-znamena-a-jak-se-vynucuje).
- **Citlivé doklady.** Obsah nahraného PDF (položky, IČO a DIČ dodavatele, vlastní data) jde přes HTTPS na servery zvoleného
  poskytovatele. Pro obzvlášť citlivé doklady zvažte import ISDOC (data zůstanou lokálně, AI se vůbec nevolá, viz
  [§ 21.9.14](21_Importy.md#21914-import-prijatych-faktur-pravidla-a-scan-inbox)), nebo lokální model přes Ollamu.

## 25.3 Krok za krokem: nastavit poskytovatele AI

1. Otevřete `Firma → AI nastavení`. Pokud AI ještě není nastavená, je sekce **Nastavení AI extrakční brány** rozbalená.
2. Vyberte poskytovatele v přepínači s pěti tlačítky: Anthropic, Azure OpenAI, OpenAI, Gemini nebo Ollama. Kliknutí jen přepne,
   jaké přihlašovací údaje se dole upravují, **neuloží** to ještě aktivní volbu.
3. Ve formuláři **Přihlašovací údaje** vyplňte **API klíč** a volitelně **Model**. U Azure OpenAI vyplňte navíc
   **Azure endpoint**, **Deployment** a **API verzi**. U Ollamy vyplňte adresu (viz [§ 25.7](#257-krok-za-krokem-pouzit-lokalni-model-pres-ollamu)).
4. Klikněte na **Test připojení**. Aplikace klíč a endpoint ověří reálným voláním a ukáže, jaký model odpověděl, nebo chybu.
5. Pokud potřebujete data jen v EU, zaškrtněte **Vynutit EU rezidenci dat** (viz [§ 25.9.14](#25914-eu-rezidence-dat-co-to-znamena-a-jak-se-vynucuje)).
6. Klikněte na **Uložit nastavení brány**. Teprve tím se aktivní poskytovatel a EU volba zapíší k firmě.

**Jak poznáte, že je hotovo:** u poskytovatele je štítek **aktivní** a nahoře zůstane zelená značka s jeho jménem
(případně štítek **EU data residency**). Zvolený poskytovatel bez klíče má místo toho štítek **bez klíče** a extrakce zatím neběží.

> [!TIP]
> Nejste si jistí, kterého poskytovatele zvolit? Anthropic Claude je výchozí a nejodladěnější volba (nativní čtení PDF, nejlepší
> přesnost na komplexních fakturách). Azure OpenAI zvolte, pokud firma potřebuje EU rezidenci dat se smluvním zajištěním nebo už
> Azure OpenAI používá pro jiné účely.

Volitelné kroky:

- **Povolit klientům AI vytěžování.** V AI nastavení firmy povolíte klientům položku `Nákup → AI import`.
  Volba je standardně vypnutá a platí jen pro vybranou firmu. Klient používá jejího aktivního poskytovatele a její uložené
  přihlašovací údaje; samotný klíč se mu nezobrazuje. U vlastní klientské role povolte také vytěžování a vytváření přijatých
  faktur. Klient si toto povolení ani měsíční limit sám změnit nemůže.
- **Měsíční limit klientského vytěžování přijatých dokladů.** Výchozí hodnota je 100 pokusů, nastavit lze 0 až 100 000. Nula klientské
  vytěžování přijatých dokladů zablokuje. Limit sdílejí všichni klienti firmy; účetní ho svým vytěžováním nečerpá. Nastavení i klientský AI import
  přijatých faktur ukazují aktuální měsíc, počet použitých a zbývajících pokusů. Každý platný soubor předaný ke zpracování spotřebuje jeden
  pokus, včetně chyby, duplicity a strukturovaného ISDOC/ISDOCX. Neplatný upload nebo odmítnuté oprávnění se nepočítají.
  Pokusy se nevracejí, na začátku kalendářního měsíce se čerpání obnoví. Po vyčerpání se dávka zastaví; doklady lze zadat
  ručně nebo účetní zvýší limit. Jde o počet pokusů, cenu volání AI určuje poskytovatel.
  Při vytěžování přijatých dokladů klient používá uložený model firmy, nemůže pro jednotlivý import vybírat jiný a AI jej
  automaticky nepřepíná na silnější model.
- **Uložit nastavení do všech firem.** Zaškrtnutím **Uložit nastavení do všech firem** se stejný poskytovatel, klíč, model, region
  dat, EU rezidence a míra uvažování uloží i do ostatních firem, ve kterých smíte měnit nastavení AI. Volba **Jen do firem s
  nenastavenou AI** (výchozí zapnutá) přeskočí firmy, jejichž aktivní poskytovatel už má klíč. Detaily v
  [§ 25.9.13](#25913-poskytovatele-ai-a-jejich-nastaveni).
- **Ladění extrakce.** Pod formulářem nastavíte **Míru uvažování AI** a **Poznámky k extrakci**, uložíte tlačítkem
  **Uložit ladění** (viz [§ 25.9.16](#25916-ladeni-extrakce-poznamky-a-mira-uvazovani)).
- **Model pro dotaz účetnímu asistentovi.** V `Účetnictví → AI asistent` vyberete u vstupu dotazu **Model** aktivního
  poskytovatele, například `gpt-5.5` místo `gpt-5.4-mini`. Volba platí pro další odesílané dotazy na této stránce a nemění
  výchozí model firmy. U každé odpovědi je uveden použitý model, také při otevření uložené konverzace.
  Navrhne-li asistent v odpovědi ostatní pohledávku nebo závazek, nabídne tlačítko **Založit v Ostatních pohledávkách
  a závazcích**. Po potvrzení vznikne pouze koncept, který po kontrole zaúčtujete sami. K založení potřebujete právo
  zapisovat do Ostatních pohledávek a závazků.
- **Interní doklad od asistenta.** Na vnitřní účetní operace („přeúčtuj 5 000 z 518 na 501", „zaúčtuj odpisy",
  „dohadná položka na energie", zápočet, kurzový rozdíl) asistent v podvojném účetnictví navrhne interní doklad: druh,
  popis, datum účetního případu, datum dokladu a řádky MD a D s částkami, případně s dimenzemi firmy. Půjčky, nájem,
  kauce a jiné pohledávky či závazky se sledováním úhrad navrhne jako ostatní pohledávku nebo závazek. Když z dotazu
  nepozná, kam operace patří, nebo chybí částka či datum, zeptá se. Návrh zkontroluje server stejnými pravidly jako
  zaúčtování ručního interního dokladu: účty musí být v účtové osnově firmy a aktivní k datu, strany MD a D vyrovnané,
  účetní období otevřené a datum mimo zámek účtování, závěrkové účty ani podrozvaha proti rozvaze projít nemohou.
  Nalezené chyby asistent vypíše u návrhu a tlačítka zůstanou neaktivní. Platný návrh uložíte tlačítkem **Uložit jako
  koncept**, nebo ho tlačítkem **Založit a zaúčtovat** rovnou zaúčtujete s číslem z řady ID; po založení vede odkaz
  **Otevřít doklad** na jeho detail. Dokumenty přiložené k dotazu se na doklad navážou. Návrh interního dokladu asistent
  nabídne jen uživateli s právem zapisovat ruční zápisy, zaúčtovat ho lze jen s oprávněním zaúčtovat v deníku. Viz
  [Interní doklady](52_Ucetni_denik.md#521415-interni-doklady).
- **Smazání klíče.** Tlačítko **koš** u poskytovatele smaže jeho uložené přihlašovací údaje (po potvrzení). Pokud byl aktivní,
  extrakce přestane fungovat, dokud nenastavíte jiného poskytovatele nebo klíč nevložíte znovu.

## 25.4 Krok za krokem: importovat přijaté faktury z PDF

1. Otevřete `Nákup → AI import`.
2. Vyberte nebo přetáhněte jeden nebo více souborů (PDF, obrázek), nebo na telefonu a tabletu klikněte na **Vyfotit**: doklad se vyfotí, zmenší a po potvrzení náhledu se použije jako vybraný soubor. Při více souborech vznikne dávka, která se zpracuje
   postupně po jednom.
   Nahrané obrázky se pro vytěžení a archivaci převedou do PDF se zachovaným poměrem stran, bez deformace či ořezu.
3. Počkejte na zpracování. U každého souboru uvidíte stav, dodavatele, částku a **typ dokladu**.
4. Případně změňte **Typ dokladu** přímo v tabulce (například účtenku placenou kartou, kterou AI zařadila jako
   *Účtenka / paragon*, chcete vést jako *Faktura*).
5. Klikněte na **Otevřít** u vytvořeného konceptu, nebo na **Zobrazit v přijatých fakturách** (seznam vyfiltrovaný na tuto dávku).

**Jak poznáte, že je hotovo:** u každého souboru je odkaz **Otevřít** na koncept přijaté faktury. Nahoře na stránce vidíte
**poslední import** (datum a počet dokladů). Starší importy najdete v seznamu přijatých faktur ve filtru **Import (dávka)**.

Po importu se otevře okno kontroly (viz [§ 25.5](#255-krok-za-krokem-zkontrolovat-vytezene-doklady)). Doklad, na kterém je napsáno
„zaplaceno", se zakládá jako **koncept**, aby šel po vytěžení volně upravit. Hlášení na to upozorní a tlačítko
**Potvrdit a označit jako uhrazenou** doklad přijme a uhradí k datu vystavení. Výjimkou je účtenka zaplacená kartou, když má firma
zapnuté vypořádání plateb kartou: při importu pracovníkem se hned uhradí, spáruje s pohybem karty a zaúčtuje.

**Klientský import** vytváří nové doklady vždy jako koncepty v právě vybrané firmě, i u zaplacených účtenek.
Klient zkontroluje údaje a na detailu použije **Označit jako přijaté**, stejně jako u ručního zadání. Vyžaduje-li firma
schválení, doklad nejprve projde schvalováním. Koncept se do souhrnu nákladů nezapočítává; přijatý doklad se zahrne podle
stejných pravidel jako ručně zadaná faktura. Doklad jiné firmy se při klientském importu automaticky nepřesouvá.

Pokud brána ještě není vůbec nakonfigurovaná a pokusíte se o AI import v [Import přijatých](21_Importy.md#21914-import-prijatych-faktur-pravidla-a-scan-inbox),
zobrazí se upozornění s odkazem na **AI nastavení**.

## 25.5 Krok za krokem: zkontrolovat vytěžené doklady

Po AI importu se otevře okno **Kontrola vytěžených dokladů**. Prochází doklady jeden po druhém a ukáže jen ty, které kontrolu
potřebují: mají hlášení z vytěžení, nebo jim chybí dimenze povinná podle
[pravidel dimenzí](114_Dimenze.md#1141110-pravidla-dimenzi-podle-uctu) (bez ní by doklad nešel zaúčtovat). Když takový doklad není,
okno jen oznámí, že není co kontrolovat.

Vedle formuláře (na užší obrazovce pod ním) okno ukazuje **Náhled originálu** právě kontrolovaného dokladu, takže vytěžené údaje
porovnáte s PDF bez přepínání; tlačítkem **Skrýt PDF** / **Zobrazit PDF** v patičce ho schováte nebo znovu zobrazíte.

1. Nahoře zkontrolujte dodavatele, číslo dokladu, datum, stav a částku. Odkaz **Otevřít doklad** otevře editor.
2. Projděte ostatní části hlášení (reverse charge, nesouhlasící součty apod.). U každé, kterou jste ověřili, klikněte na **Vyřešeno**.
3. U **druhu nákladu po položkách** zkontrolujte položky orámované červeně (AI navrhuje druh, ale zatím není zvolený). Návrh
   převezmete tlačítkem **Použít**, všechny najednou tlačítkem **Použít návrhy AI**.
4. Má-li firma zapnuté [dimenze](114_Dimenze.md), zkontrolujte sekci **Dimenze dokladu** nad položkami. Chybí-li povinná dimenze,
   je sekce orámovaná červeně a řekne, na kterém účtu ji pravidlo vyžaduje. Jinou dimenzi pro jednotlivou položku nastavíte
   štítkem u řádku.
5. Klikněte na **Uložit a další** (u posledního dokladu **Uložit a dokončit**). Tlačítko **Přeskočit** nechá doklad beze změny.

**Jak poznáte, že je hotovo:** upozornění z detailu dokladu zmizelo a doklad přestal být „ke kontrole".

Uložením potvrzujete, že jste doklad zkontrolovali, takže body hlášení zobrazené v okně se vyřídí. Zůstanou jen návrhy druhu
nákladu u řádků, kterým druh nevyberete. Dimenze, včetně převzatého návrhu, se uloží přímo do dokladu. **Přeskočit** návrh
dimenzí neuloží.

Okno se otevírá:

- samo po importu na stránce `Nákup → AI import` (jednotlivý doklad i dávka), znovu tlačítkem **Zkontrolovat vytěžené**,
- po **Vytěžit a vytvořit** v příchozích dokladech, před otevřením editoru,
- po ručním spuštění **scan inboxu** ([§ 21](21_Importy.md)),
- tlačítkem **Zkontrolovat** ve žlutém hlášení v detailu faktury, případně v červeném upozornění na chybějící povinnou dimenzi,
- tlačítkem **Zkontrolovat vytěžené** v seznamu přijatých faktur (vybrané řádky, jinak všechny načtené doklady s hlášením a
  koncepty bez povinné dimenze).

V editoru faktury jsou tytéž položky orámované červeně a návrh AI je u výběru druhu nákladu s tlačítkem **Použít**.

## 25.6 Krok za krokem: importovat vydané faktury z PDF

1. Otevřete `Prodej → AI import`. Položku vidí uživatel, který smí vytvářet vydané faktury.
2. Přetáhněte PDF, obrázek, ISDOC nebo ISDOCX. Při přetažení více souborů vznikne dávka, zpracuje se postupně po jednom.
   Pro jednotlivý import i dávku lze dočasně vybrat jiný model z whitelistu aktivního poskytovatele.
3. Po úspěchu klikněte na odkaz do editoru.
4. Ověřte odběratele, typ dokladu, DUZP, splatnost, režim cen s/bez DPH, sazby a text položek.

**Jak poznáte, že je hotovo:** u každého souboru je výsledek a odkaz na vytvořený **koncept** vydané faktury. Import sám fakturu
nevystaví, neodešle a nezaúčtuje.

Podrobnosti o chování importu jsou v [§ 25.9.19](#25919-ai-import-vydanych-faktur-podrobnosti).

## 25.7 Krok za krokem: použít lokální model přes Ollamu

Tento postup je pro správce. Místo cloudového poskytovatele může vytěžování i AI návrhy kontací běžet na vlastním stroji přes
[Ollamu](https://ollama.com). Doklady pak neopouštějí vaši infrastrukturu.

1. Nainstalujte Ollamu na stroj s grafickou kartou (doporučeno) nebo na server MyÚčta.
2. Stáhněte model, který umí číst obrázky (v knihovně Ollamy má označení *vision*), příkazem `ollama pull <název modelu>`.
   Seznam modelů ukáže `ollama list`.
3. Aby byla Ollama dostupná z Docker kontejneru nebo ze sítě (nikoli jen z `localhost`), spusťte ji s `OLLAMA_HOST=0.0.0.0`.
4. V `Firma → AI nastavení` v sekci **Nastavení AI extrakční brány** vyberte **Ollama (lokální)** a zadejte adresu podle tabulky:

<!-- cols: 45 55 -->
| Kde běží MyÚčto | Adresa Ollamy |
|---|---|
| Docker, Ollama na stejném stroji (Windows, macOS) | `http://host.docker.internal:11434` |
| Docker na Linuxu | `http://host.docker.internal:11434` a ve službě aplikace `extra_hosts: ["host.docker.internal:host-gateway"]` |
| Přímo na serveru (IIS, Apache) | `http://localhost:11434` |
| Ollama na jiném stroji v síti | `http://192.168.x.y:11434` |

5. Klikněte na **Načíst modely** a vyberte model. Štítek *čte obrázky* označuje modely, které vytěží i skeny a fotky, model bez
   něj zpracuje jen PDF s textovou vrstvou.
6. Uložte nastavení. Po uložení proběhne test spojení.

Název hostu s podtržítkem (`gpu_server.lan`) adresa nepřijme, zadejte IP adresu nebo název bez znaku `_`. API klíč vyplňte,
jen pokud je Ollama za reverse proxy s autentizací. Při změně adresy se uložený klíč smaže.

**Jak poznáte, že je hotovo:** test spojení uspěl a pokusný import jedné faktury doběhl. Rychlost, limity a bezpečnostní pravidla
jsou v [§ 25.9.18](#25918-lokalni-model-pres-ollamu-podrobnosti).

## 25.8 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Faktura má žluté zvýraznění a ikonu varování, filtr **Ke kontrole** ji ukáže | Součet řádků se liší od základu daně z PDF o víc než 2 % | Otevřete doklad, ověřte řádky proti PDF a hlášení vyřešte (viz [§ 25.9.2](#2592-jak-zrusit-warning)) |
| Tlačítko poskytovatele je neaktivní a pod přepínačem je červené upozornění o nepodporované EU rezidenci | Zapnuté **Vynutit EU rezidenci dat** u poskytovatele, který EU neumí | Zvolte Azure OpenAI nebo OpenAI s EU endpointem, nebo vynucení vypněte |
| Chyba `residency_conflict` | Konfigurace by poslala data mimo EU, i když je EU vynucená | Opravte endpoint poskytovatele, žádná data se neodeslala |
| Poskytovatel má štítek **bez klíče** | Je zvolený, ale nemá uložený klíč | Vložte klíč a uložte nastavení |
| Test připojení selže hned | Špatný formát klíče (Anthropic `sk-ant-`, OpenAI `sk-`) | Zkontrolujte, že jste vložili celý klíč |
| Anthropic hlásí, že klíč není přiřazený k workspace a požadavek musí obsahovat `anthropic-workspace-id` | Klíč nepatří do žádného workspace | Vyplňte **ID workspace** z Claude Console, nebo použijte klíč přiřazený k workspace |
| Extrakce větších souborů vrátí chybu | Překročen limit PDF (Anthropic 32 MB, ostatní 20 MB) | Zmenšete soubor nebo ho rozdělte |
| Faktura obsahuje řádek **KOREKCE** s nulovými množstvími | Model nerozparsoval složitou fakturu a součet se lišil o víc než 50 % | Doplňte množství a ceny k řádkům a korekční řádek smažte (viz [§ 25.9.4](#2594-katastrofalni-mismatch-placeholder)) |
| Doklad z paliv nebo vyúčtování nemá položky | Doklad nemá spolehlivý rozpad řádkových částek odpovídající rekapitulaci | Zkontrolujte souhrnnou rekapitulaci a potřebné řádky doplňte ručně (viz [§ 25.9.7](#2597-doklad-bez-jednotkovych-cen-zalozeni-ze-souhrnne-rekapitulace)) |
| U dokladu chybí splatnost | Doklad ji neuvádí a AI ji neodhaduje | Doplňte splatnost v editoru |
| DIČ dodavatele ze skupinové registrace nesouhlasí s dokladem | ARES může vrátit vlastní DIČ člena nebo společné DIČ skupiny podle stavu registrace | Porovnejte údaje s dokladem a ověřte skupinové DIČ v registru (viz [§ 25.9.9](#2599-dodavatel-se-skupinovou-registraci-k-dph-dve-dic-na-dokladu)) |
| Import Ollamou je extrémně pomalý, nebo skončí po zhruba 2 minutách | Model se nevejde do paměti grafické karty, nebo prohlížeč po 2 minutách přestane čekat | Zvolte menší model, nebo import nechte běžet přes scan inbox (viz [§ 25.9.18](#25918-lokalni-model-pres-ollamu-podrobnosti)) |
| Test spojení hlásí `ollama_curl_missing` | Chybí PHP rozšíření `curl` | Doinstalujte rozšíření (v Docker image je) |

## 25.9 Podrobnosti a pravidla

### 25.9.1 Sanity check a příznak „Ke kontrole"

Při AI extrakci z PDF se po importu automaticky spustí **sanity check**: sečtou se řádky bez DPH a porovnají s celkovým základem
daně, který AI přečetla z PDF „K úhradě". Pokud se hodnoty liší o víc než 2 %, faktura získá příznak **Ke kontrole** a uživatel
by měl řádky před zaúčtováním ověřit.

Indikátory v aplikaci:

- **Žluté zvýraznění řádku** a ikona varování vedle čísla faktury v seznamu přijatých faktur.
- **Filtr Ke kontrole** v horní liště seznamu zobrazí jen faktury s aktivním příznakem.
- **Žlutý banner** v detailu i editoru faktury s diagnostickým textem (například *„součet řádků bez DPH (XX) je vyšší než
  AI-vrácený základ daně bez DPH (YY) - rozdíl Z %"*).

### 25.9.2 Jak zrušit warning

- Hlášení mizí **po částech**. Odrážka návrhu druhu nákladu zmizí sama, jakmile řádek druh nákladu dostane (v editoru i v
  kontrolním okně). S poslední odrážkou zmizí celá sekce.
- Ostatní body (reverse charge, nesedící součty apod.) odstraní tlačítko **Vyřešeno** u daného bodu. Ostatní body zůstávají.
- Tlačítko **Beru na vědomí** v banneru smaže celé hlášení najednou.
- Když z hlášení nezbude nic, doklad přestane být „ke kontrole".
- **Automaticky** při přechodu z konceptu na další stav (přijatá, zaúčtovaná, uhrazená). Posunutím stavu jste data ověřili.

### 25.9.3 Auto-upgrade modelu

Pokud levnější model (Haiku 4.5) vrátí slabý výsledek (dodavatel se shoduje s vaší firmou, nebo se součet řádků výrazně liší
od celku), extraktor automaticky zkusí znovu se silnějším modelem (Sonnet 4.6, zhruba 4krát dražší za extrakci). Pokud máte
Sonnet jako výchozí, opakování se přeskočí.

### 25.9.4 Katastrofální mismatch (placeholder)

Když ani silnější model nezvládne rozparsovat řádky (typicky komplexní vícesloupcové servisní faktury) a součet řádků se liší
od celku o víc než 50 %, extraktor:

1. zachová **popisy řádků** z AI extraktu (jsou obvykle správně),
2. vynuluje jejich **množství a jednotkovou cenu** (0),
3. přidá první řádek **KOREKCE** s AI celkem z „K úhradě", aby seděl celkový součet faktury.

Uživatel pak postupně doplní množství a cenu k jednotlivým řádkům a nakonec smaže korekční řádek.

### 25.9.5 Dodatečná kontrola uložených faktur

CLI skript `php api/bin/recheck-ai-extracted-invoices.php` projde přijaté faktury s PDF přílohou, znovu spustí AI extrakci
a porovná AI celek s aktuálním celkem v databázi. Při rozdílu nad práh (výchozí 2 %) zapíše varování:

```
php api/bin/recheck-ai-extracted-invoices.php                    # dry-run
php api/bin/recheck-ai-extracted-invoices.php --apply            # zápis
php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1
php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05
```

### 25.9.6 Dodavatel neplátce DPH

Při AI importu se ověří **plátcovství dodavatele** (ARES a VIES, případně signál z dokladu „DIČ: Neplátce DPH"). U neplátce
se automaticky nastaví **Bez nároku na odpočet** a doplní varování. Konzistentně vyčíslená DPH zůstane součástí závazku
a nákladu, sazby se vynulují pouze bez doložené daně. Kontrola tak zachová částku dodavatele a odpočet se nedostane do
přiznání. Detail viz [§ 23.11.7](23_Prijate_faktury.md#23117-danova-uznatelnost-a-narok-na-odpocet).

### 25.9.7 Doklad bez jednotkových cen: založení ze souhrnné rekapitulace

Souhrnné doklady za období (typicky měsíční vyúčtování palivových karet) často **jednotkovou cenu vůbec neuvádějí**, mají jen
množství, částku za řádek a dole daňovou rekapitulaci. Když je navíc doklad uvádí ve dvou variantách, před slevou a po slevě,
dopočítaná jednotková cena vyjde z nesprávných čísel a doklad se rozejde s rekapitulací.

Pokud doklad uvádí částky bez DPH za jednotlivé řádky a jejich základy i daň souhlasí s rekapitulací po sazbách,
MyÚčto.cz **zachová jednotlivé položky** jako `1 ks × částka řádku`. Samotná absence jednotkové ceny rozpad neruší.

Když řádkové částky chybí nebo s rekapitulací nesouhlasí, založí se doklad ze souhrnné daňové rekapitulace: **jeden řádek
na sazbu DPH** (`1 ks × základ daně`). Do popisu řádku se přenesou názvy plnění z dokladu, takže zůstane poznat, o co šlo
(PHM, poplatky).

Při souhrnném založení koncept nese **varování**, že rozpad položek nebyl spolehlivý. Pokud potřebujete doklad rozepsaný,
doplňte řádky ručně. Doklady s uvedenými jednotkovými cenami se přebírají včetně rozpadu na položky.

**Nulové řádky** se do dokladu nepřebírají. Typicky předplatné, které rozepisuje kvóty zahrnuté v ceně („50 GB reserved logs
- 0,00"), nemění základ ani DPH. Slevy se zápornou částkou zůstávají. Pokud má doklad nulové všechny řádky, převezmou se všechny.

### 25.9.8 Kontrola dat a identifikátorů při extrakci

- **Datum objednávky není datum vystavení.** Extraktor přijme jako datum vystavení jen údaj, který je tak na dokladu označený.
  Datum objednávky, expedice, tisku nebo přijetí objednávky za něj nezamění.
- **Chybějící splatnost se neodhaduje.** Pokud na dokladu datum splatnosti není, výsledek ho nechá neurčené a doplníte ho při
  kontrole v editoru. Stejně tak se extraktor nesnaží „opravovat" DUZP jen proto, že předchází datu vystavení.
- **Datum přijetí se bere z dokladu, ne ze dne vytěžení.** Do pole **Datum přijetí** se doplní **datum vystavení** dokladu,
  a když ho doklad nenese, DUZP. Datum v budoucnosti se nepoužije nikdy. Nemá-li doklad čitelné vůbec žádné datum, zůstane
  den importu a doklad na to upozorní žlutou hláškou. Chcete-li jako datum přijetí den importu, přepněte v
  `Firma → Nastavení` na záložce **Fakturace** volbu **Datum přijetí u importovaných přijatých dokladů** na „Den importu",
  volba se pamatuje pro celou firmu. Podrobnosti a platnost napříč formáty jsou v
  [§ 21.9.14](21_Importy.md#21914-import-prijatych-faktur-pravidla-a-scan-inbox). Na období nároku na odpočet DPH to vliv nemá,
  dokud datum přijetí nezadáte ručně (viz [Kniha DPH](42_Kniha_DPH.md)).
- **IČO s vedoucí nulou zůstává osmimístné.** Vyhledání i párování dodavatele používá normalizovaný osmimístný tvar, takže
  například `01234567` není zaměněno za jiný identifikátor ani uloženo bez úvodní nuly.

### 25.9.9 Dodavatel se skupinovou registrací k DPH (dvě DIČ na dokladu)

Doklad odštěpného závodu nebo člena **skupinové registrace k DPH** může uvádět dvě různá čísla: **DIČ** samotného subjektu
(typicky `CZ` + IČO) a **DIČ k DPH** skupiny. Extrakce je vytěží odděleně:

- karta dodavatele se dohledá především podle **IČO**; jednotliví členové skupiny tak zůstávají oddělení,
- pokud ARES nepotvrdí plátcovství a doklad uvádí odlišné **DIČ k DPH**, ověří se také v registru plátců.

Při novém načtení údajů dodavatele podle IČO ARES u aktivní skupinové registrace předvyplní skupinové DIČ a příznak plátce. Pokud ARES skupinové DIČ nevrátí, neaktivní vlastní DIČ se místo něj nedoplní. IČO přitom zůstává identifikátorem konkrétního člena, zatímco skupinové DIČ může sdílet více členů. Při vytěžení zkontrolujte čísla proti dokladu; skupinové DIČ lze samostatně ověřit v registru plátců. Automatické předvyplnění vychází z aktuálního stavu ARES a nepotvrzuje platnost registrace k historickému datu dokladu.

### 25.9.10 Reverse charge ze zahraničí: automatika

Když extraktor detekuje **reverse charge** (zahraniční dodavatel a všechny řádky bez DPH), doklad automaticky daňově připraví:

- AI klasifikuje **povahu plnění** (zboží, služba) přímo z dokladu (VIN a vozidlo znamená zboží, SaaS, licence a API znamená služba).
- Položky dostanou **tuzemskou sazbu 21 %** a klasifikační kód: **23** (pořízení zboží z EU, ř. 3 a ř. 43, KH A.2), **24e**
  (služba z EU, ř. 5 a ř. 43, KH A.2), **24** (služba ze 3. země, ř. 12 a ř. 43), **25** (dovoz zboží ze 3. země, ř. 7 a ř. 43).
  Částka k úhradě se nemění, daň zůstává na dokladu nulová, samovyměří se až ve výkazech.
- U **služeb** rozhoduje o tom, jestli jde o plnění z EU, nebo ze 3. země, **registrace dodavatele k DPH**, ne jeho adresa.
  Fakturuje-li firma se sídlem mimo EU přes registraci v některém členském státě (na dokladu má například DIČ začínající `IE`),
  je to osoba registrovaná v jiném členském státě a služba od ní patří na ř. 5 (kód **24e**), ne na ř. 12. Doklad na to upozorní
  varováním, ověřte, že jde o platnou registraci k DPH.
- U **zboží** se registrace dodavatele neuplatní a rozhoduje, **odkud bylo zboží odesláno**: z jiného členského státu jde o
  pořízení z EU (kód 23), ze 3. země o dovoz (kód 25). Zkontrolujte to na dokladu a případně kód změňte.
- U **pořízení zboží z EU** se dopočítá zákonné **DUZP dle § 25** (15. den měsíce po dodání, pokud doklad nebyl vystaven dříve)
  a k němu se naváže **kurz ČNB**, pozdě vystavená faktura tak spadne do správného období DPH.
- Do dokladu se zapíše **informační varování** s rekapitulací, co se nastavilo. Zkontrolujte hlavně zboží vs. služba a případně
  změňte kód (zboží 23 nebo 25, služba 24 nebo 24e).
- Povahu plnění AI určuje **za celý doklad**, všechny položky tedy dostanou stejný kód. U smíšeného dokladu (zboží i služba)
  změňte kód jednotlivé položky v okně kontroly vytěžení nebo v editoru v podrobnostech položky (šipka za druhem nákladu).

Detail daňové logiky viz [§ 23.11.10](23_Prijate_faktury.md#231110-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba).

### 25.9.11 Hromadný import, typ dokladu a poslední import

Na stránce `Nákup → AI import` lze vybrat nebo přetáhnout víc souborů naráz. Vznikne z nich dávka, která se zpracuje postupně
po jednom. Po zpracování zůstane na stránce tabulka výsledků: soubor, stav, dodavatel, částka, **typ dokladu** a odkaz
**Otevřít** na vytvořený koncept.

**Typ dokladu** jde změnit přímo v tabulce, bez otevírání editoru. Typicky jde o účtenku placenou kartou, kterou AI zařadí jako
*Účtenka / paragon* a účetní ji chce vést jako *Faktura*. Stejná volba je i u jednotlivě importovaného dokladu. Změna se týká
jen zařazení, částky ani DPH se nepřepočítávají. Záloha se tu měnit nedá (má vazby na vyúčtování), přepněte ji v editoru dokladu.

Po dokončení dávky ukáže souhrn počet úspěšných a chybných dokladů a tlačítko **Zobrazit v přijatých fakturách**, které otevře
seznam vyfiltrovaný na tuto dávku. Tam lze vybraným dokladům změnit typ hromadně akcí **Nastavit typ**.

### 25.9.12 Kontrola vytěžených dokladů: podrobnosti

- Druh nákladu jde v okně změnit i u dokladu, který už koncept není, bez vynucené úpravy v editoru. Klient z portálu ho mění
  jen u konceptu. Zaúčtovaný doklad v otevřeném období se po změně přeúčtuje. Doklad v uzavřeném období nebo stornovaný okno
  jen zobrazí, opravu je potřeba udělat v editoru.
- Prázdné typy dimenzí v sekci **Dimenze dokladu** se předvyplní výchozími dimenzemi dodavatele a zakázky a co zbude, návrhem
  z posledního dokladu téhož dodavatele. Předvyplněná hodnota je označená **Návrh** i se zdrojem.
- U návrhu druhu nákladu je jistota a zdůvodnění.
- U dokladu v režimu přenesené daňové povinnosti nabízí okno u každé položky i **členění DPH** (například 24e místo 23).
  Změna se týká jen vybrané položky, částky ani druh nákladu nemění a platí pro ni stejná pravidla jako pro druh nákladu.

### 25.9.13 Poskytovatelé AI a jejich nastavení

AI extrakce neběží natvrdo nad jedním modelem, MyÚčto.cz nabízí **AI bránu** s pěti poskytovateli, mezi kterými si každá firma
vybere podle toho, co už používá, kde chce mít API klíč a jaké má požadavky na rezidenci dat:

- **Anthropic Claude** - BYOK (vlastní klíč z `platform.claude.com`), výchozí model `claude-haiku-4-5`, dále `claude-sonnet-5`,
  `claude-sonnet-4-6`, `claude-opus-5`, `claude-opus-4-8`, `claude-opus-4-7` a `claude-fable-5`. Výchozí volba, na kterou je AI
  extrakce primárně odladěná. Umí nativně číst PDF jako dokument (ne jen text či obrázek), takže má nejlepší přesnost na
  vícesloupcových a naskenovaných fakturách.
- **Azure OpenAI** - vlastní Azure resource (`endpoint`, `deployment`, `api_version`), hodí se, pokud firma už má Azure OpenAI
  smlouvu nebo potřebuje EU rezidenci dat se smluvním zajištěním od Microsoftu.
- **OpenAI** (přímé API) - BYOK klíč z platformy OpenAI, výchozí model `gpt-5.4-mini`, dále `gpt-5.6-sol`, `gpt-5.6-terra`,
  `gpt-5.6-luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5.1`, `gpt-5`, `gpt-4.1`, `gpt-4o` a jejich `mini` a `nano` varianty. Modely řady
  `gpt-5` interně „přemýšlejí", bývají proto výrazně pomalejší než ostatní brány (u běžné faktury desítky sekund místo
  jednotek), přesnost je srovnatelná.
- **Google Gemini** - BYOK klíč z Google AI Studio, výchozí model `gemini-3.7-flash`, dostupné jsou také `gemini-3.6-flash`,
  `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`, `gemini-3.1-pro-preview` a `gemini-2.5-pro`.
- **Ollama (lokální)** - open-source model spuštěný na vlastním stroji, bez API klíče (komunikace přes lokální nebo privátní
  síť). Data neopouštějí vaši infrastrukturu. Viz [§ 25.7](#257-krok-za-krokem-pouzit-lokalni-model-pres-ollamu).

Nastavení je **na firmu** (celá firma sdílí jednoho aktivního poskytovatele a jeho přihlašovací údaje, ne po jednotlivých
uživatelích).

Podrobnosti k formuláři:

- Zelená značka u tlačítka poskytovatele znamená, že poskytovatel už má uložené přihlašovací údaje. Štítek **aktivní** nese
  poskytovatel, přes kterého extrakce opravdu běží.
- **Anthropic, OpenAI, Gemini**: jen pole **API klíč** (BYOK, jen pro zápis, po uložení se nikdy nezobrazí zpátky, jen
  placeholder „uloženo") a volitelně **Model** (výběr z povoleného whitelistu daného poskytovatele, prázdné znamená výchozí
  model poskytovatele). **Azure OpenAI** navíc **Azure endpoint**, **Deployment** (název nasazeného modelu v Azure resource)
  a **API verze**. **Anthropic** má navíc nepovinné pole **ID workspace** (začíná `wrkspc_`), které vyplníte jen u klíče
  nepřiřazeného k žádnému workspace; Anthropic takový klíč bez ID odmítne s hláškou, že požadavek musí obsahovat
  `anthropic-workspace-id`.
- **Test připojení** ověří klíč a endpoint reálným voláním a nahlásí, jaký model odpověděl (nebo chybu). Před uložením se
  kontroluje i základní formát klíče (Anthropic musí začínat `sk-ant-`, OpenAI `sk-`, Gemini přijímá podporované standardní
  i autorizační klíče z AI Studia), ušetří to zbytečný test s očividně špatně vloženým klíčem.
- **Uložit nastavení do všech firem** uloží stejného poskytovatele, klíč, model, region dat, EU rezidenci a míru uvažování i do
  ostatních firem, ve kterých smíte měnit nastavení AI. Volba **Jen do firem s nenastavenou AI** (výchozí zapnutá) přeskočí
  firmy, jejichž aktivní poskytovatel už má klíč, takže fungující nastavení jinde nepřepíšete. Klíč se otestuje jen jednou, na
  aktuální firmě. Když test neprojde, do dalších firem se nic neuloží. Každá firma dostane vlastní šifrovanou kopii klíče.
  **Poznámky k extrakci** se nekopírují, každá firma si drží vlastní. Firma, která vyžaduje EU rezidenci dat, nastavení bez EU
  regionu nedostane a ve výsledku je uvedená jako přeskočená. Po uložení se pod formulářem vypíše, do kterých firem se
  nastavení uložilo a které se přeskočily a proč.
- U nakonfigurovaného poskytovatele vidíte i **počet dosud provedených extrakcí** (počítadlo na poskytovatele, nezávislé na
  tom, jestli je zrovna aktivní) a jeho **štítek rezidence** (například *„EU (Azure OpenAI)"*).

### 25.9.14 EU rezidence dat: co to znamená a jak se vynucuje

Zaškrtávátko **Vynutit EU rezidenci dat** říká aplikaci: *„tato firma smí AI extrakci posílat jen na servery fyzicky v EU,
nikdy do USA."* Hodí se pro firmy se zpřísněnými požadavky na GDPR a rezidenci dat (například veřejná správa, citlivější obory,
interní compliance politika). Region dat (EU nebo US) volíte polem **Region dat**.

Ne každý poskytovatel to ale umí stejně:

<!-- cols: 18 14 68 -->
| Poskytovatel | EU-schopný? | Jak se region určí |
|---|---|---|
| **Azure OpenAI** | Ano | Podle hostname Azure endpointu. Pokud obsahuje token EU regionu (`westeurope`, `swedencentral`, `germanywestcentral`, `northeurope`, `francecentral`, `norwayeast`, `switzerlandnorth`, `polandcentral`, `italynorth`, `spaincentral`, `uksouth`, `ukwest` a další), region je EU. Jinak platí deklarovaný region dodavatele, ale jen pro **ověřený Azure host**, neplatný nebo cizí host padá fail-closed na US. |
| **OpenAI** | Ano | Jen když je **Base URL** nastaveno přesně na `https://eu.api.openai.com` (OpenAI project data residency). Cokoli jiného (včetně prázdného pole, tedy výchozího `api.openai.com`) je US. |
| **Anthropic Claude** | Ne | Přímé API nabízí jen US region. |
| **Google Gemini** | Ne | Přímá integrace přes AI Studio používá US, regionální endpoint Vertex AI není součástí této integrace. |

Pokud zaškrtnete **Vynutit EU rezidenci dat** u poskytovatele, který EU neumí (Anthropic, Gemini, nebo Azure či OpenAI se
špatně nastaveným endpointem), tlačítko poskytovatele se v přepínači **znepřístupní** a pod přepínačem se zobrazí červené
upozornění *„Vybraný poskytovatel/konfigurace nepodporuje EU rezidenci..."*. Volba **Region dat: US** je navíc zamčená, dokud
je vynucení EU zapnuté.

> [!WARNING]
> Tohle není jen kosmetika ve formuláři. Server vynucuje stejné pravidlo **fail-closed** i nezávisle na uživatelském rozhraní.
> Každé volání extrakce (i test připojení, i automatický upgrade na silnější model) si znovu ověří skutečný region podle
> konfigurace poskytovatele. Pokud by se dostala firma s požadavkem na EU přesto na endpoint mimo EU, volání skončí chybou
> `residency_conflict`, **žádná data se neodešlou** a žádný cross-provider ani cross-tenant fallback se nekoná. Buď proběhne
> extrakce ve správném regionu, nebo neproběhne vůbec.

### 25.9.15 Výběr modelu a chování shodné napříč poskytovateli

Ať zvolíte kteréhokoli poskytovatele, chování zbytku extrakce zůstává stejné. Funkce popsané výše v této kapitole (sanity check,
příznak **Ke kontrole**, auto-upgrade modelu, katastrofální mismatch, kontrola plátcovství DPH, automatika reverse charge)
fungují nad výsledkem **libovolného** aktivního poskytovatele, ne jen nad Anthropic:

- **Výchozí model** (pole *Model* ve formuláři přihlašovacích údajů) se použije, pokud u konkrétního importu nezvolíte jiný.
  Whitelist modelů je uzavřený, nelze zapsat libovolný řetězec, jen jeden z nabízených.
- Auto-upgrade z [Auto-upgrade modelu](#2593-auto-upgrade-modelu) platí analogicky u všech poskytovatelů a jde vždy o
  **jeden stupeň nahoru** po žebříčku: Anthropic `haiku → sonnet → opus → fable`, OpenAI a Azure
  `nano → mini → plný model`, Gemini `lite → flash → pro`. Stupeň, který nemá ve whitelistu žádný model, se přeskočí. Upgrade
  **nikdy nepřeskočí do jiného regionu**, zůstává u stejného poskytovatele a stejné rezidence dat.
- Výsledek extrakce nese **štítek původu**. U naimportované faktury vidíte, který poskytovatel a jaký region data zpracoval
  (užitečné pro audit a kontrolu, zvlášť když je zapnuté vynucení EU rezidence).
- Maximální velikost PDF k extrakci se liší poskytovatel od poskytovatele (Anthropic 32 MB, ostatní 20 MB), u větších souborů
  extrakce vrátí chybu.

### 25.9.16 Ladění extrakce: poznámky a míra uvažování

Pod formulářem přihlašovacích údajů jsou dvě volby, které platí pro **celou firmu napříč poskytovateli** a nepřenastavují se
při přepnutí brány.

**Míra uvažování AI** rozhoduje, kolik práce si model dá, než odpoví:

<!-- cols: 34 66 -->
| Volba | Co dělá |
|---|---|
| **Výchozí (podle modelu)** | Neposílá poskytovateli nic navíc. Doporučené. |
| **Rychle a levně** | Zkrátí uvažování. Nižší cena a latence, u složitých faktur na úkor přesnosti. |
| **Přesně (víc uvažování)** | Nechá model uvažovat déle. Vyšší přesnost na komplikovaných dokladech, ale pomalejší a dražší. |

Ne každý model to umí. `claude-haiku-4-5` a modely řady `gpt-4` volbu nemají a prostě ji ignorují (extrakce běží dál, jen bez
efektu). U Azure OpenAI se volba neuplatní vůbec, protože pod deploymentem může být libovolný model.

**Poznámky k extrakci** jsou volný text (maximálně 2000 znaků), který se připojí k zadání pro AI. Patří sem to, co model nemá
odkud vědět o *vašich* fakturách:

```
Dodavatel ACME píše variabilní symbol do pole "Reference", ne do VS.
Faktury z Irska jsou vždy bez DPH (reverse charge).
U Vodafonu ber částku z řádku "Celkem k úhradě", ne z rekapitulace.
```

Text jde do zadání jako **doplňující kontext, ne jako pravidlo**. Nikdy nepřebije schéma výsledku ani kontrolní logiku popsanou
výše v této kapitole. Když si poznámka odporuje s pravidly extrakce, platí pravidla. Delší text se ořízne na 2000 znaků.

> [!TIP]
> Poznámky pište konkrétně a k jednomu dodavateli, ne obecně. „Buďte přesnější" modelu nepomůže, „faktury od ACME mají datum
> plnění v pravém horním rohu" ano. Když se stejná chyba opakuje u jednoho dodavatele, je to přesně případ pro poznámku.

Obojí se ukládá tlačítkem **Uložit ladění** (je aktivní jen při skutečné změně) a projeví se okamžitě na další extrakci.

### 25.9.17 Omezení a tipy

- Aktivní poskytovatel je nastavení **celé firmy**, ne uživatele. Změna se projeví pro všechny uživatele firmy okamžitě po uložení.
- API klíč je **jen pro zápis**: jakmile ho jednou uložíte, aplikace ho už nikdy nezobrazí zpět (ani administrátorovi), jen
  potvrdí, že je nastavený. Pro změnu klíče ho zadejte znovu celý, prázdné pole znamená zachovat stávající.
- Než přepnete poskytovatele naostro, použijte **Test připojení**. Ověří klíč i to, že vrácený model odpovídá whitelistu.
- Obsah nahraného PDF (položky, IČO a DIČ dodavatele, vlastní data) jde přes HTTPS na servery zvoleného poskytovatele.

### 25.9.18 Lokální model přes Ollamu: podrobnosti

**Jak se doklad zpracuje.** Model dostane obrázky prvních 6 stran dokladu a k nim text z PDF, protože čísla dokladu, IBAN,
variabilní symbol a částky jsou v textu přesnější než na obrázku. Volba **rychle / přesně** u modelů, které umí přemýšlet
(capability *thinking*), vypíná nebo zapíná přemýšlení. Na obrázky stránek potřebuje server přednostně nástroj `pdftoppm`
z balíku Poppler (je v Docker image), záložně Imagick s Ghostscriptem. Bez nich se posílá jen text a sken bez textové vrstvy
nejde vytěžit. Spojení s Ollamou vyžaduje PHP rozšíření `curl` (v Docker image je), bez něj ohlásí test spojení chybu
`ollama_curl_missing`.

**Rychlost a časový limit.** Rychlost závisí hlavně na tom, jestli se model celý vejde do paměti grafické karty:

- Když se model vejde do VRAM celý, trvá jedna faktura desítky sekund.
- Když se nevejde (model i s kontextem potřebuje víc paměti, než má karta volné), běží část modelu na procesoru a jedna faktura
  může trvat i několik minut.
- První dotaz po delší pauze navíc čeká, než Ollama model načte do paměti. MyÚčto ji žádá, aby model po každém dotazu držela
  načtený 30 minut.

Pokud je vytěžování pomalé, zvolte menší model, který čte obrázky, nebo grafickou kartu s větší pamětí.

Velikost kontextu nastavíte proměnnou `MYINVOICE_OLLAMA_NUM_CTX` (výchozí 32768, rozsah 8192 až 131072). Menší kontext zabere
méně paměti grafické karty, takže se menší model vejde do VRAM celý a odpovídá výrazně rychleji. Při hodnotě 16384 se do
kontextu vejdou zhruba 3 až 4 strany dokladu, delší doklady potřebují výchozí hodnotu.

Výchozí limit na jeden doklad je 110 s a počítá se do něj i příprava obrázků stránek. Delší limit nastavíte proměnnou
`MYINVOICE_OLLAMA_TIMEOUT` (v sekundách):

- **Import z prohlížeče** (AI import přijaté i vydané faktury) čeká na výsledek nejvýš zhruba 2 minuty, pak to prohlížeč vzdá.
  Vyšší `MYINVOICE_OLLAMA_TIMEOUT` ho neprodlouží. Webserver ale musí požadavek nechat ty 2 minuty doběhnout: v Docker image
  s nginx je `fastcgi_read_timeout` 120 s (nastavení je uvnitř image, pro změnu připojte vlastní `nginx.conf` jako bind mount
  do `/etc/nginx/nginx.conf`), na IIS zvyšte u FastCGI `activityTimeout` i `requestTimeout`.
- **Zpracování na pozadí** (scan inboxu přes `cron-scan-purchase-inbox` a AI návrhy kontací přes `cron-ai-worker`) běží mimo
  webserver, takže vyšší `MYINVOICE_OLLAMA_TIMEOUT` pomůže právě tady. Pomalý model proto nechte vytěžovat hlavně přes scan inbox.

**Rezidence dat a bezpečnost.**

- Ollama na adrese v lokální nebo privátní síti (`localhost`, `10.x`, `172.16-31.x`, `192.168.x`) se počítá jako **Lokální**
  a splní i požadavek na EU rezidenci dat. AI návrhy kontací pak nevyžadují potvrzení DPA.
- Adresa ve veřejném internetu se počítá jako **Vzdálená**: EU rezidenci nesplní a AI návrhy vyžadují potvrzení DPA jako
  u cloudových poskytovatelů.
- Adresy cloudových metadat, link-local a multicast jsou zakázané vždy. Provozovatel instance může povolené cíle omezit
  proměnnou `MYINVOICE_OLLAMA_ALLOWED_HOSTS` (čárkami oddělené názvy hostů nebo rozsahy, například `gpu.lan,10.0.0.0/8`).
- Ve spravované instalaci je Ollama dostupná jen na adresách, které provozovatel povolil v `MYINVOICE_OLLAMA_ALLOWED_HOSTS`.
  Bez této proměnné se žádná adresa uložit nedá.

### 25.9.19 AI import vydaných faktur: podrobnosti

Stránka `Prodej → AI import` přijímá PDF, obrázek, ISDOC nebo ISDOCX a vždy vytváří jen **koncept vydané faktury**. Pokud
soubor obsahuje platný ISDOC, aplikace použije jeho strukturovaná data a AI vůbec nevolá. Teprve když strukturovaná data chybí,
použije aktivního poskytovatele a model z [§ 25.9.13](#25913-poskytovatele-ai-a-jejich-nastaveni).

Z jednoho souboru aplikace vytěží odběratele, data dokladu, měnu, platební údaje a položky. Odběratele vyhledá nebo založí,
vytvoří koncept stejnou interní cestou jako ruční editor a přepočítá jeho součty. Číslo z dokladu převezme jen tehdy, pokud
v aktuální firmě nekoliduje, jinak dostane koncept nové číslo až při vystavení. U ISDOC navíc ověří, že dodavatelem je aktuálně
zvolená firma. Existující ISDOC se stejným variabilním symbolem vrátí jako duplicita místo založení druhé faktury.

Při přetažení více souborů vznikne dávka. Zpracovává se **postupně po jednom**, aby nepřetížila limit poskytovatele. U každého
řádku je samostatný výsledek a odkaz na vytvořený koncept. Maximální velikost jednoho uploadu je 32 MiB, konkrétní poskytovatel
může mít nižší limit uvedený v [§ 25.9.15](#25915-vyber-modelu-a-chovani-shodne-napric-poskytovateli).

Výsledek nese zdroj (`ISDOC`, `AI` nebo duplicita), poskytovatele, model, region a případně spotřebu tokenů. Tyto údaje se spolu
s názvem a velikostí souboru zapisují do activity logu, samotné přihlašovací údaje ani obsah dokladu se do něj nezapisují.

## 25.10 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md) - kam se vytěžené doklady ukládají.
- [Import přijatých faktur](21_Importy.md#21914-import-prijatych-faktur-pravidla-a-scan-inbox) - ISDOC a další zdroje bez AI.
- [Dimenze](114_Dimenze.md) - povinné dimenze při kontrole vytěžených dokladů.
- [Export přijatých](24_Export_prijatych.md) - předání dokladů účetní.
