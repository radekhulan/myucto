# 40. Režim OSS (One Stop Shop)

### 40.1.1 Cesta: `Daně → OSS přiznání`

Režim jednoho správního místa (**One Stop Shop**, § 110a a násl. ZDPH) umožňuje
odvést daň z přeshraničních plnění spotřebitelům v jiných členských státech EU
**jedním kvartálním přiznáním v Česku** místo registrace k DPH v každé zemi zvlášť.
Daň se počítá sazbou státu spotřeby, přiznává se v eurech a česká finanční správa
ji přepošle do cílových států.

Tahle kapitola popisuje celý řetěz: kdy se registrovat, co nastavit, jak řádek do
OSS vzniká, co se dělá s plněními, u kterých si systém není jistý, jak se OSS daň
účtuje a jak se sestaví a doloží podání. Související témata mají vlastní kapitoly —
[import zahraničních dokladů](21_Importy.md#216-214b-zahranicni-doklady-a-rezim-oss),
[hromadné nastavení OSS](14_Faktury.md#1432-hromadne-nastaveni-oss),
[sazby a číselníky](92_Nastaveni.md#9212-sazby-dph) a
[daňový průvodce](35_Fakturujeme.md#355-zahranicni-fakturace-eu-oss-a-treti-zeme).

> [!NOTE]
> MyÚčto podporuje **režim EU** (plnění z ČR spotřebitelům v jiných členských
> státech). Režim mimo EU ani dovozní režim **IOSS** aplikace nevede — pro ně
> nemá ani přiznání, ani rozpoznání odvodu z banky.

## 40.2 K čemu OSS je a kdy se registrovat

### 40.2.1 Kdy plnění patří do státu spotřeby

Do OSS patří plnění, u kterých se **místo plnění přesouvá do státu odběratele**:

- **prodej zboží na dálku** do jiného členského státu spotřebiteli (typicky e-shop),
- **digitální (TBE) služby** — telekomunikační, rozhlasové a televizní vysílání
  a elektronicky poskytované služby,
- další služby, u kterých místo plnění určuje sídlo příjemce.

Společné mají to, že odběratel je **osoba nepovinná k dani** (spotřebitel bez DIČ)
z **jiného členského státu EU**. Dodání osobě s platným DIČ do OSS **nepatří** —
je to osvobozené dodání do jiného členského státu (ř. 20 nebo 21 přiznání)
a vykazuje se v [souhrnném hlášení](39_Souhrnne_hlaseni.md).

Běžné služby B2C, u kterých místo plnění zůstává v ČR podle § 9 odst. 2 ZDPH
(konzultace, řemeslo, hodinová práce), se fakturují s českou daní a do OSS
nevstupují — i když je odběratel z Polska.

### 40.2.2 Práh 10 000 EUR (§ 8 odst. 3 ZDPH)

Dokud součet **všech** přeshraničních B2C plnění do EU za kalendářní rok
nepřekročí **10 000 EUR**, může dodavatel plnění dál zdaňovat českou sazbou.
Po překročení se místo plnění přesune do státu spotřeby a je nutné buď se
registrovat k DPH v každé cílové zemi, nebo použít OSS. Registrace je možná
i **dobrovolně** před dosažením prahu.

Práh je **celounijní a společný pro zboží i služby**. Do součtu se proto počítají
i plnění, která zatím fakturuješ s českou daní — kdyby se sčítala jen ta už
označená jako OSS, práh by nikdy nemohl být překročen.

### 40.2.3 Sledování prahu v aplikaci

Na stránce **Daně → OSS přiznání** je blok **Čerpání prahu 10 000 EUR** za zvolený
kalendářní rok. Ukazuje součet v EUR, procento vyčerpání, rozpad podle států
a případné datum překročení.

| Situace | Co aplikace hlásí |
|---|---|
| Od 80 % prahu | Upozornění na blížící se limit se sledováním zbytku roku |
| Práh překročen | Datum překročení a výzva ověřit registraci do OSS |
| Práh překročen, OSS vypnutý | Že se plnění dál fakturují s českou daní |
| OSS zapnutý, práh nedosažen | Že dobrovolná registrace je možná, ale je dobré si ji potvrdit — jinak daň míří do nesprávného státu |
| Nepřepočtené řádky | Kolik řádků se nepodařilo přepočíst do EUR, takže skutečné čerpání je vyšší |

Do součtu vstupují všechna plnění za rok odběratelům ze států EU mimo zemi
dodavatele a bez DIČ; koncepty, stornované doklady, proformy a penalizační faktury
se vylučují.

> [!WARNING]
> Přepočet do EUR je **orientační** — používá denní kurz ČNB k datu plnění, kdežto
> směrnice pracuje s pevným přepočtem. U hodnot blízko limitu si čerpání ověř
> s účetní. Sledování prahu samo OSS nezapne, doklady nepřeklasifikuje ani
> nerozhodne, jaký režim se na plnění právně vztahuje.

## 40.3 Nastavení

### 40.3.1 Zapnutí režimu a platnost registrace

**Cesta: `Nastavení → Daně a účetnictví → Režim OSS (One Stop Shop)`.** Je to
čtvrtá modulová karta, hned za *Vést účetnictví*, *Vést mzdy* a *Vést skladovou
evidenci*. Zaškrtnutím **OSS režim** se odkryjí čtyři pole:

| Pole | Význam |
|---|---|
| **Země identifikace** | Stát, ve kterém je dodavatel k OSS registrovaný — typicky `CZ` |
| **Měna podání** | Měna, ve které se přiznání podává — pro EPO **`EUR`** |
| **Platné od** | Den, od kterého registrace platí |
| **Platné do** | Den ukončení registrace; prázdné = trvá |

Platnost se vyhodnocuje **k datu plnění každého řádku**, ne k datu vystavení
dokladu ani k dnešku. Doklad s datem plnění před začátkem registrace zůstane
tuzemský — a to je správně. Když registrace začala nebo skončila **uvnitř**
vykazovaného čtvrtletí, přenese se hranice i do podání.

Po zapnutí se v editoru položek objeví OSS pole a v menu **Daně** stránka
**OSS přiznání**. Bez zapnutého režimu se položka menu nezobrazí a přímý odkaz
přesměruje na úvodní stránku.

> Jiná měna podání než `EUR` není zakázaná, ale náhled i export na ni upozorní —
> EPO očekává částky v eurech.

### 40.3.2 Sazby DPH pro cizí země — hlídej pole Stát

Aby šla na položku vybrat zahraniční sazba, musí být v číselníku **DPH sazeb**
([§ 92.1.2](92_Nastaveni.md#9212-sazby-dph)) založená — například `PL-23`, `SK-23`,
`HU-27`. Zakládá se stejně jako tuzemská sazba, ale s jedním rozdílem, který je
**nejčastější příčinou toho, že import doklad odmítne**:

> [!WARNING]
> **Formulář předvyplňuje pole Stát na `CZ`.** Sazba pojmenovaná `PL-23`, která má
> ve sloupci Stát `CZ`, je pro systém **česká sazba ve výši 23 %** — a takovou
> Česko nezná. Kód sazby je jen popisek; rozhoduje sloupec Stát.

Co se v takovém stavu stane:

- **Import zahraničních dokladů se zastaví** a v reportu adresně řekne, že kód
  `PL-23` v číselníku sice je, ale se zemí `CZ`, a že je potřeba u něj opravit zemi
  na `PL` a import zopakovat.
- Když sazba pro danou zemi a procento **neexistuje vůbec**, hláška napřed vyzve
  ověřit, že plnění opravdu patří do té země, a teprve pak navede k jejímu založení —
  včetně připomínky, že formulář zemi předvyplňuje na `CZ`.

Je to **záměrná pojistka, ne chyba**: kdyby se sazba se špatnou zemí použila,
skončila by cizí daň v českém přiznání k DPH. Zemi zkontroluj dřív, než spustíš
import nebo hromadnou úpravu OSS.

Jak se sazba páruje na položku:

| Situace | Co se stane |
|---|---|
| Sazba pro danou zemi a procento platná k datu plnění | Naváže se, nic se nehlásí |
| Táž země a procento, ale mimo uvedenou platnost | Naváže se **s varováním**; do dokladu se otiskne procento, výkazy počítají z něj |
| Žádná shoda na zemi a procento | **Odmítne se celý doklad** — žádné „nejbližší procento" |

Sazby s příznakem reverse charge se pro OSS nepárují.

> Tabulka DPH sazeb slouží jen k tomu, aby se sazba dala na položku vybrat.
> **Autoritou o tom, kam plnění patří, není** — tou je číselník sazeb členských
> států popsaný níže. Důvod je prostý: DPH sazby si zakládá uživatel a může v nich
> mít překlep, kdežto číselník je nezávislý.

### 40.3.3 Číselník sazeb členských států

**Cesta: `Nastavení → Číselníky → Sazby států OSS`**
([§ 92.1.2b](92_Nastaveni.md#9214-9212b-sazby-statu-oss)).

Je to **kontrolní číselník** sazeb DPH platných v jednotlivých členských státech —
ne sazby pro doklad. Aplikace se ho ptá na jedinou věc: *platí tahle sazba v téhle
zemi k tomuhle datu?* Odpověď rozhoduje o tom, jestli je plnění tuzemské, nebo
patří do OSS.

Číselník je dodaný s aplikací, sdílený celou instalací a **běžně se needituje**.
Měnit ho smí jen správce instance a jen z webového rozhraní.

| Sloupec | Význam |
|---|---|
| **Stát** | Dvoupísmenný kód členského státu |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Sazba** | Procento |
| **Platí od** / **Platí do** | Historie sazby; prázdné „Platí do" = platí dosud |
| **Poznámka** | Volný text |
| **Původ** | `systémová` (dodaná s aplikací) nebo `vlastní` (přidal uživatel) |

**Kdy do něj sáhnout.** Jakmile některý členský stát změní sazbu a systémový
číselník ji ještě neobsahuje, **zkrať platnost** dosavadní systémové sazby ke dni
před účinností změny a **vedle ní založ vlastní** s novým procentem. Dokud to
neuděláš, bude aplikace u dokladů s novou sazbou hlásit, že sazba v číselníku k
datu plnění není.

Systémový řádek nelze přepsat ani smazat — jeho hodnoty používá aktualizační
migrace k rozpoznání, co je vlastní záznam. Povolené jsou u něj jen dvě akce:
**Zkrátit** platnost k datu a **Vyřadit** (a zase Vrátit).

Když sazba na dokladu číselníku neodpovídá, aplikace **varuje, ale neblokuje** —
číselník může být zastaralý a poslední slovo má člověk. Varování se objeví
v náhledu podání i v náhledu hromadné úpravy a rozlišuje čtyři situace: číselník
v databázi vůbec není, stát v něm není, procento v té zemi k datu neplatí (s výčtem
těch, které platí), nebo procento sice platí, ale pod jiným typem sazby, než jaký
doklad deklaruje.

> [!NOTE]
> Hláška **„Číselník v databázi není — chybí migrace"** není totéž jako „stát
> v číselníku chybí". Znamená, že se po aktualizaci nespustily databázové migrace;
> spusť je (`php api/bin/migrate.php`). Do té doby se neověří žádný stát a import
> zahraničních dokladů se vůbec nerozběhne.

Stránka číselníku zároveň kontroluje, zda má každý členský stát k dnešnímu dni
alespoň jednu platnou sazbu. Pokud zobrazí seznam zemí s chybějícím pokrytím,
nejde o chybu jednotlivého dokladu: nejprve spusť všechny databázové migrace a
číselník znovu načti. Přetrvávající mezeru doplň vlastní platnou sazbou až po
ověření její správnosti. Ručně přidané sazby aktualizační migrace nepřepisuje.

### 40.3.4 Výchozí nastavení na kartě odběratele

Karta klienta má sekci **Režim OSS** se dvěma poli:

| Pole | Volby | K čemu |
|---|---|---|
| **Režim OSS** | Automaticky *(doporučeno)* / Neuplatňovat OSS | Umožní OSS u konkrétního odběratele **vyloučit** |
| **Výchozí typ plnění pro OSS** | Odvodit automaticky / Zboží / Služba | Použije se, když typ plnění nejde určit z měrné jednotky položky |

**Karta umí OSS jedině vyloučit, vynutit ne.** Vyloučení se hodí u odběratele,
o kterém víš, že je osobou povinnou k dani, jen zatím nedodal DIČ. Opačný směr
karta nenabízí schválně: o tom, že plnění do OSS patří, rozhoduje sazba, země
odběratele a číselník — ne uložený úmysl na kartě.

Vyloučení je přitom bezpečné: pravidlo z [§ 40.3.2](#4042-rozhodovaci-pravidlo)
platí dál, takže ani u vyloučeného odběratele se cizí sazba nestane tuzemskou —
řádek se místo toho odmítne s hláškou.

Výchozí typ plnění je nejlevnější způsob, jak se zbavit opakované ruční práce
u e-shopu se zbožím — viz [§ 40.9](#4010-na-co-si-dat-pozor).

> **Výchozí země spotřeby na kartě záměrně není.** Země se bere z adresy odběratele
> na konkrétním dokladu, protože ta je pravdivější než uložená karta.

## 40.4 Jak vzniká OSS řádek

Zařazení do OSS je **vlastnost jednotlivého řádku faktury**, ne celého dokladu.
Odvozuje se **automaticky ve všech vstupních kanálech** — při importu, u pravidelné
fakturace, při synchronizaci z iDokladu a Fakturoidu, při čtení PDF i přes veřejné
API. V editoru faktury zůstává ruční přepínač, ale i tam běží stejné kontroly.

### 40.4.1 Podmínky, které OSS vylučují

Nejdřív se vyhodnotí, jestli řádek vůbec může být OSS. Stačí jediná z těchto
podmínek a OSS je vyloučené:

| Podmínka | Poznámka |
|---|---|
| Chybí nebo je nečitelné **datum plnění** | Bez data nejde ověřit ani platnost registrace, ani platnost sazby |
| Chybí **číselník sazeb členských států** | Nespuštěné migrace — viz [§ 40.2.3](#4033-ciselnik-sazeb-clenskych-statu) |
| Firma **nemá zapnutý OSS režim** | |
| Datum plnění leží **mimo platnost registrace** | |
| Doklad je v režimu **přenesené daňové povinnosti** | |
| Odběratel **nemá vyplněnou zemi** | |
| Odběratel je ze **země dodavatele** | „Tuzemsko" se bere ze země dodavatele, ne natvrdo z ČR |
| Odběratel je **mimo EU** | |
| Odběratel **má DIČ** | Tedy B2B — do OSS nepatří |
| Karta odběratele **OSS vylučuje** | Viz [§ 40.2.4](#4034-vychozi-nastaveni-na-karte-odberatele) |
| Sazba řádku je **0 %** | Osvobození, reverse charge a vývoz se vykazují bez daně |

Každá podmínka má vlastní hlášku i konkrétní radu, co doplnit.

### 40.4.2 Rozhodovací pravidlo

Zbytek rozhodne **číselník sazeb členských států**, kterému se položí dvě otázky:
*platí tahle sazba v zemi dodavatele k datu plnění?* a *platí ve státě spotřeby?*
Každá má tři možné odpovědi — **platí / neplatí / nevím**.

Celé pravidlo se dá shrnout jednou větou:

> **Do tuzemského přiznání smí jen řádek, u kterého číselník POZITIVNĚ potvrdí, že
> sazba v zemi dodavatele k datu plnění opravdu platí.** Každá jiná odpověď —
> neplatí, nevím, nečitelné datum — znamená, že se řádek do tuzemska nepustí.

| Odpověď za stát spotřeby ↓ / za zemi dodavatele → | **platí** | **neplatí** | **nevím** |
|---|---|---|---|
| OSS je vyloučené (§ 40.3.1) | tuzemské plnění | **odmítnuto** | **odmítnuto** |
| **neplatí** | tuzemské plnění | OSS, typ sazby prázdný | OSS + k posouzení |
| **platí** | OSS + k posouzení | **OSS** (čistý případ) | OSS + k posouzení |
| **nevím** | OSS + k posouzení | OSS, typ sazby prázdný | OSS + k posouzení |

Řádek s nulovou sazbou je z pravidla vyňatý — číselník nulové sazby nevede.

### 40.4.3 Co systém odmítne a proč

**Odmítnutí** nastane, když je OSS z nějakého důvodu vyloučené, ale číselník
zároveň nepotvrdí, že sazba v zemi dodavatele platí. Typický případ: doklad se
sazbou 23 % pro odběratele, který má DIČ, nebo doklad se zahraniční sazbou z doby
před začátkem registrace.

Hláška má vždycky dvě věty — proč a co s tím. Například že sazba 23 % podle
číselníku v zemi dodavatele k datu plnění neplatí, takže řádek nemůže být tuzemské
plnění, ale do OSS ho zařadit nelze, protože firma nemá zapnutý režim OSS.

Důvod téhle přísnosti je zásadní a stojí za zapamatování:

> **Sazba, kterou číselník v zemi dodavatele nezná, se nikdy nevykáže jako tuzemské
> plnění.** Kdyby ano, polská nebo maďarská daň by tiše skončila na ř. 1 českého
> přiznání k DPH jako česká daň na výstupu, kde ji mezi stovkami tuzemských řádků
> nikdo nenajde — až přijde výzva. Aplikace se raději zastaví a řekne, co opravit.

### 40.4.4 Typ sazby a typ plnění

**Typ sazby** (základní / snížená / druhá snížená / parkovací) se **nikdy
nedomýšlí**. Buď ho potvrdí číselník podle země a procenta, nebo zůstane prázdný
s varováním. Řádek bez typu sazby se do podání nedostane — doplň ho na položce nebo
hromadnou úpravou.

**Typ plnění** (zboží / služba) se hledá od nejkonkrétnějšího signálu:

1. **měrná jednotka položky**,
2. **výchozí typ plnění z karty odběratele**,
3. **převažující činnost dodavatele** (CZ-NACE),
4. výchozí **„služba"** — a to je hlášené varování, ne tichý dosazený údaj.

Poslední bod je v praxi nejdůležitější: jednotka `ks` je záměrně vedená jako
neutrální (je to výchozí hodnota, takže netvrdí nic), takže e-shop se zbožím
skončí u „služby", pokud nemá vyplněný CZ-NACE nebo výchozí typ na kartě
odběratele. Viz [§ 40.9](#4010-na-co-si-dat-pozor).

### 40.4.5 Rozdíly mezi kanály

Odvození je ve všech kanálech totožné. Liší se jen to, **co se stane s odmítnutým
řádkem** — a to podle toho, jestli je zdroj pravdy venku a dá se běh zopakovat:

| Kanál | Chování |
|---|---|
| **Import souborů** (Pohoda XML, ISDOC), **iDoklad**, **Fakturoid**, **AI extrakce** | **Doklad se nevytvoří.** Chyba jmenuje konkrétní položku. Po opravě se běh zopakuje a doplní jen chybějící doklady |
| **Pravidelná fakturace** (cron) | Doklad **vzniknout musí** — jinak by chybějící číselník zastavil fakturaci. Řádek zůstane mimo OSS a povinně dostane příznak **k ručnímu posouzení** |
| **Veřejné API** bez OSS údajů | Režim se odvodí; do odpovědi jde poznámka a řádky, u kterých místo plnění určit nešlo, se označí k posouzení |
| **Editor faktury** | Rozhoduje uživatel přepínačem OSS na řádku; kontrola soudržnosti dokladu běží stejně |

**Šablony pravidelných faktur** si OSS pamatují jako **rozhodnutí člověka**, takže
mají přednost před odvozením a příznak k posouzení u nich nevzniká. Jediná výjimka:
pokud k datu plnění generovaného dokladu registrace do OSS neplatí, uložené
rozhodnutí se nepoužije, jede se odvozením a řádek příznak k posouzení dostane.

Nastavuje se **přímo na položce šablony** (`Faktury → Pravidelné → editor šablony`):
u řádku je zaškrtávátko **OSS** a pod ním stát spotřeby, typ sazby a typ plnění —
stejná pole jako na řádku faktury, jen bez kurzu, přepočtených částek a opravy
období (to jsou vlastnosti konkrétního dokladu, ne předpisu). Stát spotřeby je
povinný: bez něj se řádek uloží jako tuzemský, protože položku s OSS a bez země by
cron při každém běhu vyrobil neplatnou. Prázdný typ sazby doplní při generování
odvození, ale jen když mluví o **témže** státu spotřeby. Podrobně
[§ 17.2.3](17_Pravidelne_fakturace.md#1724-polozky).

Bez uloženého rozhodnutí šablona **mlčí** a rozhoduje odvození při každém generování —
tak fungují všechny šablony založené dřív, než přibyla OSS pole. Pro e-shop
fakturující spotřebitelům v EU je to bezpečná výchozí cesta; uložené rozhodnutí má
smysl tam, kde odvození samo nestačí (typ sazby, který číselník nepotvrdil, nebo typ
plnění, který z jednotky ani z CZ-NACE nevyplývá).

> Na začátku každého importního běhu proběhne rychlá kontrola číselníku. Když
> tabulka chybí nebo číselník nevede ani jednu sazbu pro zemi dodavatele, ohlásí
> se to jednou nahlas — jinak by se odmítl každý doklad se sazbou nad 0 %, včetně
> ryze české faktury.

## 40.5 Plnění k ručnímu posouzení

Některá plnění systém zařadit umí, ale ne s jistotou. Označí je proto **k ručnímu
posouzení**. Nejde o chybu, jde o otázku, kterou musí zodpovědět člověk.

### 40.5.1 Dva stavy, které vypadají podobně

Sporné řádky končí na **dvou různých místech** a každé se řeší jinou otázkou:

| Stav | Kde daň leží | Jak vzniká | Na co se ptát |
|---|---|---|---|
| **Nejisté — v OSS podání** | V OSS podání | Sazba platí i v zemi dodavatele (21 % zná ČR, Nizozemsko, Belgie, Španělsko, Litva i Lotyšsko), číselník neuměl odpovědět, nebo si doklad protiřečí | Sedí země spotřeby a typ sazby? Nepatří plnění do tuzemska? |
| **Nejisté — v tuzemsku** | V přiznání k DPH na ř. 1 a 2 | Automatický kanál místo plnění neurčil a doklad zahodit nesměl; nebo řádek nese tuzemskou sazbu, přestože jde o přeshraniční B2C plnění a registrace k datu plnění platí | Patří plnění do tuzemského přiznání? Nemá jít do OSS? |

**Proč se import rozhoduje ve prospěch OSS.** Chybně zařazený OSS řádek uvidíš
v náhledu podání, který má pár řádků. Chybně zařazený tuzemský řádek zmizí mezi
stovkami řádků přiznání k DPH. Ze dvou možných omylů je ten první levnější.
U kanálů, které běží bez lidského zásahu, je to obráceně — do OSS podání nemá jít
nic, co nikdo nepotvrdil.

Zvláštní případ druhého stavu: řádek je zdaněný **tuzemskou** sazbou, přestože jde
o přeshraniční plnění spotřebiteli bez DIČ a firma má k datu plnění aktivní
registraci. Aplikace **sazbu ani zařazení nemění** — uvádí je doklad a registrace
je dobrovolná, takže plnění tuzemské být může — jen se rozpor označí. U odběratele
s vyloučeným OSS se tenhle rozpor nehlásí vůbec, byl by to šum na každé jeho
faktuře.

### 40.5.2 Kde je najdeš

| Kde | Co uvidíš |
|---|---|
| **Seznam faktur → filtr Místo plnění (OSS)** | Čtyři volby: *vše* / *Nejisté místo plnění (OSS)* (obojí najednou) / *Nejisté — v OSS podání* / *Nejisté — v tuzemsku*. Filtr jde do URL i do uložených filtrů a je vidět, i když OSS zapnuté nemáš |
| **Štítky u varsymbolu v seznamu** | Žlutý **OSS ?** = řádek v OSS podání, **ČR ?** = řádek v tuzemsku. Doklad rozpadlý mezi obojí nese oba. Počítají se vždy, i bez zapnutého filtru |
| **Náhled OSS podání** | Jedno souhrnné varování za období s počtem řádků a přímým proklikem do seznamu faktur na první skupinu |
| **Přiznání k DPH** | Varování se seznamem dokladů — jen druhá skupina, tedy ta, která vstupuje na ř. 1 a 2 |
| **Report importu** | Souhrn běhu: *položek k ručnímu posouzení*, *položek bez typu sazby OSS*, *dobropisů bez období opravy*. Souhrn po zavření stránky zmizí, filtr v seznamu faktur ne |

Rozhodnutí uděláš v editoru faktury (přepínač OSS na položce) nebo hromadně —
[§ 40.5](#406-hromadna-editace-oss); výběr **Jen řádky k ručnímu posouzení**
zabírá oba stavy najednou.

### 40.5.3 Doklad rozpadlý mezi obojí

Kontrola soudržnosti běží při **každém** uložení dokladu. Když jedna faktura
obsahuje zároveň OSS řádky a tuzemsky zdaněné řádky, leží ve dvou různých
přiznáních. Doklad se **nezamítá** — smíšená faktura umí vzniknout legitimně —
ale **označí se obě strany rozporu** a uživatel dostane výzvu zkontrolovat sazby.
Nulové sazby a slevové řádky se do posouzení nepočítají.

## 40.6 Hromadná editace OSS

Po migraci nebo po importu zůstanou desítky až stovky řádků, u kterých je potřeba
údaje doplnit nebo opravit. Proklikat je po jednom není reálné, proto má seznam
faktur hromadnou akci **Nastavit OSS (N)** —
[§ 14.3.2](14_Faktury.md#1432-hromadne-nastaveni-oss).

Typický postup: vyfiltruj doklady filtrem **Místo plnění (OSS)**, označ je, spusť
akci, projdi náhled, potvrď.

### 40.6.1 Dialog a povinný náhled

| Pole | Volby |
|---|---|
| **Které položky** | Jen řádky k ručnímu posouzení *(výchozí)* / jen OSS řádky bez typu sazby / všechny OSS řádky / všechny položky dokladu |
| **Režim OSS** | Zapnout OSS / Vypnout OSS (plnění je tuzemské) / Ponechat beze změny |
| **Země spotřeby** | Členský stát, do kterého plnění patří |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Typ plnění** | Zboží / Služby |
| **Označit řádky jako posouzené** | Zhasne příznak „místo plnění k ručnímu posouzení" |

**Náhled je povinný** — bez něj změnu provést nelze. Ukazuje, kolik dokladů
a položek se změní, kolik se přeskočí a proč, a jaká varování k sazbám vznikla.
Teprve pak jde kliknout **Provést změnu**.

Další omezení:

- na dávku je limit **200 dokladů**;
- **provedení** změny jde jen z webového rozhraní, ne přes API token (náhled ano);
- klientská role akci nemá vůbec.

Celá akce se odmítne, když se pokusíš zapnout OSS bez zapnutého režimu u firmy,
zvolit jako zemi spotřeby zemi identifikace dodavatele (takové plnění je tuzemské),
nebo zemi, která není členským státem EU.

Volba **Označit řádky jako posouzené** existuje proto, že potvrzení místa plnění je
rozhodnutí člověka a systém ho sám neruší.

### 40.6.2 Co se přeskočí a proč

Akce nemá „provést i tak". Příznak OSS rozhoduje, jestli řádek jde do českého
přiznání, nebo do OSS podání, takže na dokladu, který už je odevzdaný nebo zamčený,
se nepřepisuje. Přeskočí se **celý doklad**, ne jen sporný řádek:

| Důvod | Vysvětlení |
|---|---|
| Doklad neexistuje nebo patří jiné firmě | |
| Stornovaný doklad | Nedituje se |
| Doklad je uzamčen | Zaúčtovaný, v uzavřeném účetním období, v uzávěrce nebo pod daňovým zámkem |
| Období už bylo podáno | Přiznání k DPH, kontrolní hlášení nebo OSS přiznání za to období. Řeší se opravným či dodatečným tvrzením, ne přepsáním dokladu |
| Záznamy roku jsou zadržené podle § 32 ZoÚ | Retenční hold |
| Datum plnění mimo platnost registrace | Zapnout OSS na dokladu z doby, kdy registrace neplatila, by ho odstranilo z českého přiznání, aniž by se objevil v OSS podání |
| Bez země spotřeby by OSS řádek nešel podat | Doplň zemi spotřeby ve stejném dialogu |
| Sazba řádku v tuzemsku nepotvrzena | Viz [§ 40.5.3](#4063-vypnuti-oss-je-hlidane-stejne-jako-zapnuti) |
| Doklad nemá položku ve výběru / položky už hodnoty mají | Není co měnit |

„Podáno" znamená **prokazatelně odevzdaný** snapshot. Samotné stažení XML podáním
není.

### 40.6.3 Vypnutí OSS je hlídané stejně jako zapnutí

Zhasnout příznak OSS znamená přesunout daň z OSS podání **na ř. 1 českého
přiznání**. Je to tedy stejně vážný krok jako zapnutí, jen opačným směrem — a proto
se ptáme téhož číselníku téže otázky:

- Řádek, který se **stěhuje** (byl OSS a přestává jím být), a číselník sazbu v zemi
  dodavatele **nepotvrdí** → **celý doklad se přeskočí**. Odpověď „nevím" (chybí
  číselník, stát k datu nezná, nečitelné datum plnění) se bere stejně jako
  „neplatí".
- Řádek, který **mimo OSS byl už předtím**, se nikam nestěhuje → změna projde, jen
  se vypíše varování, ať ověříš, jestli do tuzemského přiznání opravdu patří. Bez
  téhle výjimky by nešlo odklikat řádky označené „nevím" z automatických kanálů,
  což je hlavní důvod, proč výběr *Jen řádky k ručnímu posouzení* existuje.
- Sazba 0 % je z kontroly vyňatá.

Vypnutí OSS zároveň **vynuluje zemi spotřeby, typ sazby i typ plnění**. Peněžní
údaje (ručně zadaný kurz, ručně zadané částky v měně podání) se záměrně nemění —
vynulovat by zahodilo ruční práci.

Po zásahu se příznak „k ručnímu posouzení" **přepočítá**. Pokud doklad i po změně
leží zároveň v OSS podání a v tuzemském přiznání, příznak se vrátí — volba
„Označit řádky jako posouzené" ho nedokáže odklikat pryč, dokud rozpor trvá,
a náhled to dopředu ohlásí.

Pokud dávka narazí na chybu, **zastaví se u prvního dokladu, který neprošel**,
a výsledek vypíše, které doklady jsou už změněné a které se ani nezkusily.
Změněným dokladům se zahodí PDF cache, protože doklad nese OSS doložku.

## 40.7 Doklad navenek

### 40.7.1 Doložka na faktuře

Jakmile je na dokladu **aspoň jeden** OSS řádek, nese doklad **OSS doložku** — a to
shodně v PDF i ve veřejném náhledu („web faktura"), česky nebo anglicky podle
jazyka dokladu.

- **Doklad celý v OSS:** „Daň je přiznána a odvedena ve státě spotřeby v režimu
  jednoho správního místa (One Stop Shop) podle § 110a a násl. zákona o DPH."
- **Smíšený doklad:** opatrnější formulace, která výslovně říká, že se týká jen
  položek v režimu OSS.

Za větou se jmenovitě vypíšou **státy spotřeby**. Výčet je buď úplný, nebo se
nevypíše vůbec — kdyby některý OSS řádek zemi neměl, neúplný výčet by na dokladu
lhal. Slevové řádky se nepočítají mezi řádky plnění, takže z dokladu celého v OSS
nedělají smíšený.

V editoru nese OSS řádek informační štítek **Jedno správní místo**.

### 40.7.2 Exporty

| Export | Chování |
|---|---|
| **Pohoda XML** | Doklad s OSS řádkem se **neexportuje**. Export to řekne s vysvětlením |
| **Stereo XML** | Totéž — doklad se odmítne s vysvětlením |
| **ISDOC** | Projde, ale OSS nijak neoznačuje — přenáší se jen procento sazby |

Důvod odmítnutí u Pohoda XML: její formát vede sazbu DPH jako **výčet tuzemských
úrovní** (základní / snížená / nulová) a nemá kam zapsat zemi spotřeby. Polská
sazba 23 % by do Pohody dorazila jako česká základní. Export proto raději nic
neudělá, než aby cizí sazbu tiše vydával za českou —
[§ 20.4.7](20_Exporty.md#2047-doklad-v-rezimu-oss-se-do-pohody-neexportuje).

Řádky v režimu OSS vykaž přes **Daně → OSS přiznání** a doklady s nimi z exportu
do Pohody nebo Sterea vyřaď.

## 40.8 Účtování OSS daně

Daň v režimu OSS **není česká daň na výstupu**. Patří jinému členskému státu, do
přiznání k DPH ani do kontrolního hlášení nevstupuje a odvádí se samostatně. Proto
se neúčtuje na 343, ale na vlastní účet:

> **345.100 — DPH v režimu OSS (jiný členský stát)**, obsazované předkontací
> `oss.output.vat`.

**Co to znamená prakticky.** Na účtu 343 zůstává přesně to, co jde do přiznání
k DPH, takže **zůstatek 343 jde s přiznáním srovnat**. Dokud OSS daň končila na
343, srovnat se nedal. V rozvaze je 345.100 součástí téže položky **„Stát —
daňové závazky a dotace"** jako 343, takže se ve výkazech nic nemění — mění se jen
možnost kontroly.

Zápisy vydané faktury:

| Účet | Strana | Co |
|---|---|---|
| 311 | MD | Celá pohledávka |
| Výnosový účet (602, …) | D | **Základ tuzemský i OSS jde na týž výnosový účet** — výnos je výnos bez ohledu na to, kterému státu daň patří |
| 343 | D | Jen tuzemská daň |
| **345.100** | D | Jen OSS daň, a jen když je nenulová |

**Smíšená faktura** se zaúčtuje jedním dokladem, jen se daňová noha rozdělí mezi
343 a 345.100. **Dobropis i storno** obracejí obě daňové nohy.

**Úhrada OSS závazku z banky** se rozpozná zvlášť a zaúčtuje **MD 345.100 / D 221**.
Platba se pozná podle **čísla účtu finanční správy vyhrazeného pro OSS**, ne podle
variabilního symbolu — referenční číslo OSS platby má tvar `CZ/CZ<DIČ>/Qn.RRRR`,
což není číselný variabilní symbol. Odvádí se v měně podání, tedy v eurech.

> [!NOTE]
> **Daňový doklad k přijaté platbě (záloha) se v režimu OSS nevydává** — daň se
> přiznává ke dni přijetí úplaty přímo v OSS přiznání. Doklad, který by nesl jen
> OSS řádky, proto skončí hlasitou chybou „nelze zaúčtovat", nikdy tiše bez daňové
> nohy.

Účet lze v předkontacích u pravidla `oss.output.vat` změnit — například na vlastní
analytiku **pod 343**. To ale nedělej: součet syntetiky 343 (tedy 343 včetně
[analytik vstupu, výstupu a zúčtování](81_Ucetni_osnova.md#8132-analytiky-dph-343100-343200-a-343900))
se pak přestane shodovat s tuzemským přiznáním k DPH a OSS daň jiného státu by
navíc vstoupila do [měsíčního zúčtování DPH](81_Ucetni_osnova.md#8133-mesicni-zuctovani-dph).
Přesně kvůli tomu má OSS daň vlastní účet **345.100**.

## 40.9 Přiznání a podání

### 40.9.1 Kvartální náhled

**Cesta: `Daně → OSS přiznání`.** Nahoře se volí **rok a čtvrtletí**, pod tím jsou
čtyři záložky — **Náhled**, **Archiv podání**, **Rekonciliace**, **Evidence § 110f** —
a tlačítko **Stáhnout XML**.

Do přiznání vstupují jednotlivé OSS řádky vydaných faktur, jejichž datum plnění
patří do zvoleného čtvrtletí. Aplikace je seskupí podle **státu spotřeby, typu
plnění, typu sazby a procenta** a oddělí běžná plnění od oprav za dřívější období.
Výpočet vychází z řádkových základů a daně, ne z hlaviček dokladů.

Karty nahoře: **Období**, **Základ daně**, **DPH z plnění**, **Opravy DPH**,
**DPH celkem** a **Termín podání**. Termín je **konec kalendářního měsíce
následujícího po skončení čtvrtletí** (Q1 → 30. 4.).

Tabulka po státech ukazuje sazbu, typ sazby, základ, daň a počet řádků;
rozbalovací **Detail řádků** vypíše jednotlivé doklady včetně měny a kurzu.

Náhled kontroluje zejména vyplněnou zemi spotřeby, existenci a shodu sazby proti
číselníku, přítomnost typu sazby, přepočet do měny podání a údaje potřebné pro
opravy minulých období. OSS řádky jsou současně vyřazené z českého přiznání k DPH,
kontrolního hlášení i [Knihy DPH](37_Kniha_DPH.md).

### 40.9.2 Přepočet do měny podání

Částky v jiné měně se do měny podání přepočtou **kurzem Evropské centrální banky
zveřejněným pro poslední den zdaňovacího období** (čl. 91 směrnice 2006/112/ES) —
**jedním kurzem pro celé čtvrtletí**, ne denním kurzem k datu plnění.

- Když ECB pro poslední den kurz nezveřejnila (víkend, svátek TARGET), použije se
  **nejbližší následující den**; použité datum vidíš v souhrnu náhledu.
- **Kurz ČNB k datu plnění se tu nepoužívá** — ten platí pro tuzemský základ daně,
  ne pro OSS podání.
- **Ruční kurz i ruční částky zadané na položce mají přednost vždy.**
- Dokud kurz pro dané čtvrtletí neexistuje (období ještě neskončilo, výpadek),
  zůstanou řádky nepřepočtené, náhled to jmenovitě oznámí a **XML nejde vytvořit**.

### 40.9.3 Opravy minulých období

Oprava plnění za dřívější čtvrtletí patří v OSS podání do **samostatného oddílu
s uvedením opravovaného období**. Zadává se **na položce faktury** v editoru
v poli **Oprava období**: buď *Běžné plnění* (výchozí), nebo konkrétní čtvrtletí
ve tvaru `RRRRQn`. Nabízí se čtvrtletí od `2021Q3` po to, které předchází
aktuálnímu.

Oprava se **nepřepočítává kurzem běžného čtvrtletí**, ale kurzem **opravovaného**
období. Hledá se ve dvou krocích: nejdřív v evidenci § 110f zapsané k podání toho
čtvrtletí, potom v kurzu ECB pro jeho poslední den. Zdroj kurzu je vidět v souhrnu
náhledu. Když neuspěje ani jeden, je oprava neplatná, řádek zůstane nepřepočtený
a export se zastaví s vysvětlením — pomůže ruční kurz nebo ruční částky na položce.

**Dobropis nebo storno bez vyplněného původního období** podání nezablokuje, ale
náhled na něj upozorní: oprava se započte do běžného čtvrtletí, tedy do jiného, než
kam patří. Import původní období nedoplňuje — v souboru není z čeho ho poznat.

### 40.9.4 XML formuláře OSSEI1

Stažení vytvoří XML formuláře **`OSSEI1`** v měně nastavené pro OSS. Struktura:

| Věta | Obsah |
|---|---|
| **VetaD** | Hlavička: rok a čtvrtletí, název firmy, DIČ, IBAN a BIC účtu v měně podání; volitelně hranice registrace, když začala nebo skončila uvnitř čtvrtletí |
| **VetaP** | DIČ |
| **VetaR** | Běžná plnění agregovaná po státu spotřeby, typu plnění, typu sazby a procentu. Typ plnění `G` = zboží, `S` = služby; typ sazby `Z` = základní, `S` = ostatní |
| **VetaO** | Opravy minulých období — opravovaný rok, čtvrtletí a stát spotřeby |

**Export se zastaví**, když jsou v období neplatná původní období oprav, chybí
přepočet do měny podání, nebo některý řádek nemá zemi spotřeby, platný typ plnění
či platný typ sazby.

**Export jen varuje** při chybějícím DIČ, měně podání jiné než EUR, chybějícím IBAN
nebo vynechané opravě.

Export vyžaduje oprávnění exportovat daňové výkazy, uloží neměnný snapshot
zdrojových dat do archivu a zapíše akci do activity logu.

> [!WARNING]
> Aplikace XML **sama neodesílá**. Před podáním ověř varování, součty a registraci;
> vygenerovaný soubor je pomůcka, ne náhrada odborné kontroly.

### 40.9.5 Kde se OSS přiznání podává

XML má formát **`OSSEI1`**, ale **obecnou cestou EPO ho podat nelze**. Daňový portál
písemnost sice rozpozná — zobrazí *„DAP OSS - režim EU - Přiznání k DPH platné od
1. 7. 2021"* — a vzápětí ji odmítne hláškou:

> Pro práci s písemností „DAP OSS - režim EU - Přiznání k DPH platné od 1.7.2021"
> musíte být přihlášeni v aplikaci MOSS/OSS!

**MOSS/OSS je samostatná aplikace Daňového portálu**, v horní liště vedle EPO,
Registru DPH, Vracení DPH a DAC7. Přihlášení do EPO pro ni neplatí — je potřeba se
přihlásit přímo do ní.

Postup je tedy:

1. V **Daně → OSS přiznání** projdi náhled a varování.
2. **Stáhni XML** tlačítkem *Stáhnout XML*.
3. Na Daňovém portálu se přihlas do aplikace **MOSS/OSS** a nahraj soubor tam.
4. Vrať se do MyÚčta a v **Nástroje → EPO podání a archiv** označ snapshot jako
   podaný, případně k němu přilož potvrzení.

> [!NOTE]
> Proto u OSS snapshotu v archivu podání **není tlačítko Otevřít a podat v EPO**
> ani u něj nefunguje asistované předání přes API. Nezobrazí se ani panel
> **Přímé podání se ZAREP**: přímé podání jde na týž endpoint portálu, takže se
> láme o stejnou podmínku — jen by uživatel předtím zbytečně odemkl podpisový klíč.
> Nabízet kteroukoli z těch cest by znamenalo posílat uživatele na chybu portálu.
> Ostatní formuláře (DPH, kontrolní a souhrnné hlášení, daň z příjmů) obě cesty
> mají — viz
> [kapitola 70](89_Archiv_podani_a_rekonciliace.md#894-asistovane-podani-pres-epo).

### 40.9.6 Archiv podání a rekonciliace

Záložka **Archiv podání** vypisuje všechny archivované OSS snapshoty s časem
vzniku, stavem, výsledkem validace, **SHA-256 otiskem** a odkazem na stažení
uloženého souboru. Tytéž snapshoty leží ve společném archivu v
**Nástroje → EPO podání a archiv** ([kapitola 70](89_Archiv_podani_a_rekonciliace.md)),
kde se k nim připojují pokusy o podání, doručenky a označení „podáno".

> Archivovaný soubor prokazuje, **co vzniklo — ne že bylo podáno**. Po odeslání
> snapshot označ jako podaný, jinak archiv není důkazem podání.

Záložka **Rekonciliace** porovnává archivované podání s tím, co by se za totéž
období podalo dnes. Neimportuje cizí XML — srovnává uložený podklad s aktuálním
náhledem, takže odhalí doklad opravený zpětně po podání, doklad, který z období
zmizel (storno, přesun data plnění), i přesun daně do jiného státu.

Výsledkem je jeden ze čtyř závěrů: za období není nic archivováno; archivované
podání nemá uložený podklad (porovnej ručně proti staženému XML); dnešní náhled
odpovídá; nebo se dnešní náhled liší — pak zvaž opravné podání za původní období.
Rozdíly se vypisují v součtech, v řádcích podání i jako seznam dokladů změněných
po podání.

### 40.9.7 Evidence § 110f

Evidence vybraných plnění podle **§ 110f ZDPH** (a čl. 63c prováděcího nařízení
Rady (EU) č. 282/2011) se uchovává **10 let od konce kalendářního roku, ve kterém
bylo plnění uskutečněno**, a na žádost správce daně se poskytne elektronicky.

Záznamy vznikají **při stažení OSS XML**, z téhož čtení dat jako podání, a jsou
**write-once** — nelze je změnit ani smazat. Hodnoty se kopírují, nedopočítávají se
z živých dokladů, protože evidence musí i za deset let ukazovat, co bylo podkladem
podání. Sloupec **Uchovat do** říká, kdy lhůta končí.

Data jde stáhnout tlačítky **Export CSV** a **Export JSON**.

Záložka zároveň poctivě vypisuje sekci **Body čl. 63c, které aplikace doložit
neumí** — zálohy přijaté před uskutečněním plnění (nemají vazbu na konkrétní OSS
řádek), místo zahájení a ukončení přepravy u zboží, a doklad o vrácení zboží
(vrácení je zachyceno opravným dokladem, ne důkazem o vrácení věci). Tyhle body si
v případě kontroly dolož jinak.

## 40.10 Na co si dát pozor

Tenhle oddíl shrnuje věci, které **nejsou vadou aplikace**, ale rozejdou se
s očekáváním — a některé musí uživatel opravit ručně.

### 40.10.1 Typ plnění u položek v kusech je odhad

Jednotka `ks` je záměrně **neutrální** — je to výchozí hodnota, takže o zboží ani
službě netvrdí nic. Když soubor jednotku nenese vůbec, dosadí se výchozí **„služba"**
a do podání jde typ plnění `S`. **Pro e-shop se zbožím je to špatně, patří tam `G`.**

Hláška se u dokladu objeví **jen jednou**, u první položky, i když se týká všech —
je to záměrná deduplikace, aby dvacetipoložková faktura nevyrobila dvacet stejných
vět.

Dvě cesty, jak to napravit:

1. **hromadná úprava OSS** nad výběrem dokladů ([§ 40.5](#406-hromadna-editace-oss));
2. **výchozí typ plnění na kartě odběratele** — nové doklady ho pak dostanou samy
   a ruční práce se neopakuje. Případně doplnit dodavateli CZ-NACE.

### 40.10.2 Dobropisy a jejich původní období

Import ani jiný automatický kanál **původní období opravy nedoplní** — v žádném
zdrojovém souboru není z čeho ho poznat. Dokud ho na položce nevyplníš, vykáže se
oprava do **běžného** čtvrtletí místo do toho, kam patří. Kolik takových dokladů
je, říká souhrn importu i náhled podání.

### 40.10.3 Haléřové rozdíly u množství větší než jedna

Jednotková cena bez DPH se vede na **dvě desetinná místa**. Když vyjde na víc
(např. 0,2683 EUR za kus) a množství je větší než 1, přenásobením vznikne rozdíl
proti zdrojovému systému — na jednom dokladu jde o haléře, na kvartálním podání
o jednotky eur.

Není to chyba importu, je to mez datového modelu. **Při rekonciliaci OSS podání
proti zdrojovým dokladům tyhle rozdíly očekávej** a nehledej za nimi chybu.

### 40.10.4 Země spotřeby se bere z odběratele, ne z měny

Doklad v **eurech** pro **slovenského** odběratele jde do **SK** se slovenskou
sazbou, ne do nějaké „eurozóny". Rozhoduje země odběratele **na konkrétním
dokladu**, ne měna a ne uložená karta klienta.

To druhé má praktický důvod: odběratel bez IČO i DIČ (tedy každý spotřebitel) se
páruje podle shody jména, takže při tisících spotřebitelů může jeden Jan Novák
skončit na kartě jiného Jana Nováka. Daňově to neškodí — zařazení bere zemi
z dokladu — ale v adresáři to nepořádek udělá.

### 40.10.5 Nulová sazba pro odběratele s DIČ

Dodávka s **nulovou sazbou** odběrateli s platným DIČ do OSS nepatří — je to
osvobozené dodání do jiného členského státu. Zařadí se podle měrné jednotky buď
jako **dodání zboží** (ř. 20 přiznání, kód 0 v souhrnném hlášení), nebo jako
**poskytnutí služby** (ř. 21, kód 3). Protože jednotka `ks` nic netvrdí, u zboží
může vyjít služba. **Zkontroluj to** a případně oprav —
[souhrnné hlášení](39_Souhrnne_hlaseni.md) se řídí toutéž klasifikací.

### 40.10.6 Historické doklady z doby před nastavením OSS

Doklady, které do systému natekly dřív, než byl OSS správně nastavený, mohou mít
příznak OSS prázdný a jejich zahraniční daň může být vykázaná v českém přiznání.
Než podáš přiznání za období, do kterého takový import spadl, projdi si zahraniční
doklady v tom období a ověř, že v přiznání k DPH nefigurují. Filtr **Místo plnění
(OSS)** a hromadná úprava jsou na to ta správná dvojice.

### 40.10.7 Náhled je poslední kontrolní bod

Náhled OSS podání je krátký — řádek na kombinaci **stát × sazba** — a je to
**poslední místo, kde se chyba dá chytit** dřív, než XML odejde na portál.
Než ho stáhneš, projdi varování, ověř počet řádků k ručnímu posouzení a porovnej
součty s tím, co čekáš.
