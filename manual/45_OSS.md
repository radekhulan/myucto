# 45. Režim OSS (One Stop Shop)

> Návod, jak odvést DPH z prodeje spotřebitelům v jiných členských státech EU
> jedním čtvrtletním přiznáním v Česku: kdy se registrovat, co nastavit, jak
> vyřešit sporná plnění a jak OSS přiznání sestavit a podat. Pro podnikatele
> a účetní, kteří prodávají zboží nebo digitální služby spotřebitelům v EU.

Režim jednoho správního místa (**One Stop Shop**, § 110a a násl. ZDPH) umožňuje
odvést daň z přeshraničních plnění spotřebitelům v jiných členských státech EU
**jedním čtvrtletním přiznáním v Česku** místo registrace k DPH v každé zemi zvlášť.
Daň se počítá sazbou státu spotřeby, přiznává se v eurech a česká finanční správa
ji přepošle do cílových států.

Stránka OSS při prvním otevření nabízí poslední uzavřené čtvrtletí, případně
čtvrtletí odpovídající již vybranému období DPH. Vlastní výběr roku a čtvrtletí
si pamatuje během sezení pro uživatele a firmu. Náhled, rekonciliace a evidence
používají stejné období; archiv zobrazuje jednotlivá uložená podání. OSS zůstává
čtvrtletní a jeho otevření nemění měsíční či čtvrtletní režim běžného přiznání DPH.

> [!TIP]
> MyÚčto podporuje **režim EU** (plnění z ČR spotřebitelům v jiných členských
> státech). Režim mimo EU ani dovozní režim **IOSS** aplikace nevede. Pro ně nemá
> ani přiznání, ani rozpoznání odvodu z banky.

## 45.1 Kdy to potřebujete

- Prodáváte e-shopem zboží spotřebitelům v jiných zemích EU.
- Poskytujete spotřebitelům v EU digitální služby (telekomunikační, vysílací
  nebo elektronicky poskytované).
- Blíží se vám práh 10 000 EUR přeshraničních plnění spotřebitelům za rok.
- Skončilo čtvrtletí a musíte podat OSS přiznání.
- V seznamu faktur vidíte štítky **OSS ?** nebo **ČR ?** a nevíte, co s nimi.
- Po importu nebo převodu zbyly desítky dokladů, u kterých je potřeba doplnit
  zemi spotřeby, typ sazby nebo typ plnění.
- Dobropis se týká plnění z dřívějšího čtvrtletí.
- Některý členský stát změnil sazbu DPH.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| průběžně během roku | Sledovat čerpání prahu 10 000 EUR | `Daně → OSS přiznání`, blok **Čerpání prahu 10 000 EUR** |
| jednou při zahájení | Zapnout režim OSS a vyplnit platnost registrace | `Nastavení → Daně a účetnictví`, karta **Režim OSS (One Stop Shop)** |
| po každém importu a před podáním | Vyřídit plnění k ručnímu posouzení | `Faktury`, filtr **Místo plnění (OSS)** |
| po skončení čtvrtletí | Zkontrolovat náhled a stáhnout XML | `Daně → OSS přiznání` |
| do konce měsíce po čtvrtletí (Q1 do 30. 4.) | Podat XML v aplikaci MOSS/OSS na Daňovém portálu | Daňový portál, aplikace MOSS/OSS |
| po podání | Označit snapshot jako podaný | `Daně → EPO podání a archív` |
| když stát změní sazbu | Zkrátit starou sazbu a založit novou | `Systém → Sazby a číselníky`, záložka **Sazby států OSS** |

## 45.2 Než začnete

- **Registrace k OSS** u finanční správy. Aplikace ji neřeší, jen si pamatuje,
  odkdy dokdy platí (viz [§ 45.3](#453-krok-za-krokem-zapnuti-oss-a-prvni-nastaveni)).
- **Licence.** OSS je placený modul. Po skončení zkušební doby bez platné licence
  položka **OSS přiznání** v menu nebude (viz [Licence a aktivace](104_Licence_a_aktivace.md)).
- **Zahraniční sazby DPH** založené v číselníku `Systém → Sazby a číselníky`, záložka **Sazby DPH**,
  například `PL-23`, `SK-23`, `HU-27`. Dbejte, aby měly správný **Stát** (viz
  [§ 45.4](#454-krok-za-krokem-zahranicni-sazby-dph-a-ciselnik-sazeb-statu)).
- **Země odběratele** vyplněná na dokladech. Podle ní se plnění zařazuje.
- **Oprávnění exportovat daňové výkazy** pro stažení XML.
- U e-shopu se zbožím **CZ-NACE** dodavatele nebo **Výchozí typ plnění pro OSS**
  na kartě odběratele, jinak se zboží považuje za službu (viz
  [§ 45.10.16](#451016-na-co-si-dat-pozor)).

## 45.3 Krok za krokem: zapnutí OSS a první nastavení

1. Otevřete `Nastavení → Daně a účetnictví`. Karta **Režim OSS (One Stop Shop)**
   je čtvrtá modulová karta, hned za *Vést účetnictví*, *Vést mzdy* a *Vést
   skladovou evidenci*.
2. Zaškrtněte **OSS režim**. Zobrazí se čtyři pole.
3. Do **Země identifikace** zadejte stát, ve kterém jste k OSS registrovaní (typicky `CZ`).
4. Do **Měna podání** zadejte `EUR`. EPO očekává částky v eurech.
5. Do **Platné od** zadejte den, od kterého registrace platí. Pole **Platné do**
   nechte prázdné, pokud registrace trvá.
6. Uložte nastavení.
7. Zkontrolujte zahraniční sazby DPH podle [§ 45.4](#454-krok-za-krokem-zahranicni-sazby-dph-a-ciselnik-sazeb-statu).
8. Chcete-li u některého odběratele OSS vyloučit, nastavte to na jeho kartě
   (viz [§ 45.10.7](#45107-nastaveni-na-karte-odberatele)).

**Jak poznáte, že je hotovo:** V editoru položek faktury se objeví OSS pole
a v menu **Daně** se objeví stránka **OSS přiznání**. Bez zapnutého režimu se
položka menu nezobrazí a přímý odkaz přesměruje na úvodní stránku.

> [!WARNING]
> Platnost registrace se vyhodnocuje k **datu plnění** každého řádku, ne k datu
> vystavení dokladu ani k dnešku. Doklad s datem plnění před začátkem registrace
> zůstane tuzemský, a to je správně.

## 45.4 Krok za krokem: zahraniční sazby DPH a číselník sazeb států

### Založení zahraniční sazby

1. Otevřete `Systém → Sazby a číselníky`, záložka **Sazby DPH**.
2. Založte novou sazbu stejně jako tuzemskou, například `PL-23`.
3. Ve sloupci **Stát** zvolte skutečný stát sazby (`PL`), ne předvyplněné `CZ`.
4. Uložte. Poté import zahraničních dokladů nebo hromadnou úpravu spusťte znovu.

**Jak poznáte, že je hotovo:** Sazbu lze vybrat na položce a import ani
hromadná úprava k ní nehlásí chybu.

> [!WARNING]
> Formulář předvyplňuje pole **Stát** na `CZ`. Sazba `PL-23` se státem `CZ` je pro
> systém česká sazba 23 % a takovou Česko nezná. Kód sazby je jen popisek, rozhoduje
> sloupec **Stát**. Je to záměrná pojistka: kdyby se sazba se špatnou zemí použila,
> skončila by cizí daň v českém přiznání k DPH.

### Aktualizace číselníku při změně sazby státu

Použijte, když členský stát změní sazbu a systémový číselník ji ještě neobsahuje.

1. Otevřete `Systém → Sazby a číselníky`, záložka **Sazby států OSS**. Změny může provést jen
   správce instance z webového rozhraní.
2. U dosavadní systémové sazby klikněte na **Zkrátit** a zadejte **Zkrátit platnost
   k datu** (den před účinností změny).
3. Vedle ní založte tlačítkem **Nová sazba** vlastní sazbu s novým procentem a datem **Platí od**.

**Jak poznáte, že je hotovo:** U dokladů s novou sazbou aplikace přestane hlásit,
že sazba v číselníku k datu plnění není. Nová sazba má ve sloupci **Původ** hodnotu `vlastní`.

## 45.5 Krok za krokem: vyřízení plnění k ručnímu posouzení

Některá plnění systém zařadit umí, ale ne s jistotou. Označí je **k ručnímu
posouzení**. Nejde o chybu, jde o otázku, kterou musí zodpovědět člověk.

1. Otevřete `Faktury` a v seznamu zvolte filtr **Místo plnění (OSS)**. Volba
   **Nejisté místo plnění (OSS)** ukáže oba druhy sporných plnění najednou.
2. Otevřete doklad se štítkem **OSS ?** (řádek je v OSS podání) a ověřte
   **Země** a **Typ sazby**. Patří-li plnění do tuzemska, přepínač **OSS** na položce vypněte.
3. U dokladu se štítkem **ČR ?** (řádek je v tuzemském přiznání na ř. 1 a 2)
   rozhodněte, jestli tam opravdu patří. Pokud ne, zapněte u položky **OSS**
   a vyplňte **Země**, **Typ sazby** a **Typ plnění**.
4. Rozhodnutí potvrďte křížkem u příznaku **k posouzení** na položce
   (**Označit řádek za posouzený**).
5. Mnoho dokladů najednou řešte hromadnou úpravou (viz [§ 45.6](#456-krok-za-krokem-hromadna-uprava-oss)).

**Jak poznáte, že je hotovo:** Filtr **Nejisté místo plnění (OSS)** nevrací žádný
doklad a v náhledu OSS přiznání zmizelo souhrnné varování o řádcích k posouzení.

Sporné řádky se zobrazují na více místech:

<!-- cols: 34 66 -->
| Kde | Co uvidíte |
|---|---|
| Seznam faktur, filtr **Místo plnění (OSS)** | Čtyři volby: *Místo plnění (OSS): vše*, *Nejisté místo plnění (OSS)* (obojí najednou), *Nejisté - v OSS podání*, *Nejisté - v tuzemsku*. Filtr jde do adresy i do uložených filtrů a je vidět, i když OSS zapnuté nemáte |
| Štítky u variabilního symbolu v seznamu | Žlutý **OSS ?** = řádek v OSS podání, **ČR ?** = řádek v tuzemsku. Doklad rozpadlý mezi obojí nese oba |
| Náhled OSS přiznání | Souhrnné varování za období s počtem řádků a odkazem **Zobrazit doklady s řádky k posouzení** v seznamu faktur |
| Přiznání k DPH | Varování se seznamem dokladů, jen pro skupinu, která vstupuje na ř. 1 a 2 |
| Report importu | Souhrn běhu: položky k ručnímu posouzení, položky bez typu sazby OSS, dobropisy bez období opravy. Souhrn po zavření stránky zmizí, filtr v seznamu faktur ne |

## 45.6 Krok za krokem: hromadná úprava OSS

Použijte po migraci nebo importu, když je potřeba doplnit nebo opravit údaje
u desítek dokladů.

1. Otevřete `Faktury` a vyfiltrujte doklady filtrem **Místo plnění (OSS)**.
2. Označte je a klikněte na **Nastavit OSS (N)**.
3. V dialogu **Hromadné nastavení OSS** zvolte **Které položky**, **Režim OSS**,
   **Země spotřeby**, **Typ sazby** a **Typ plnění**. Ostatní hodnoty můžete nechat
   na volbě „ponechat“ (nebo **Ponechat beze změny** u režimu OSS).
4. Chcete-li zhasnout příznak k posouzení, zaškrtněte **Označit řádky jako posouzené**.
5. Klikněte na **Zobrazit náhled**. Náhled je povinný, bez něj změnu provést nelze.
   Zkontrolujte, kolik dokladů se změní, kolik se přeskočí a proč, a jaká varování k sazbám vznikla.
6. Klikněte na **Provést změnu**.

**Jak poznáte, že je hotovo:** Aplikace ohlásí „OSS nastaveno u N dokladů". Zbylé
doklady jsou vypsané jako přeskočené i s důvodem.

> [!WARNING]
> Na dávku je limit 200 dokladů. Provedení změny jde jen z webového rozhraní,
> ne přes API token (náhled ano). Klientská role akci nemá vůbec.

## 45.7 Krok za krokem: oprava plnění z minulého čtvrtletí

Použijte u dobropisu nebo storna, které opravuje plnění z dřívějšího čtvrtletí.

1. Otevřete doklad v editoru faktury.
2. U položky v poli **Oprava období** zvolte konkrétní čtvrtletí ve tvaru `RRRRQn`.
   Výchozí volba je **Běžné plnění**. Nabízí se čtvrtletí od `2021Q3` po to, které
   předchází aktuálnímu.
3. Doklad uložte a v `Daně → OSS přiznání` ověřte, že oprava je v oddílu **Opravy minulých období**.

**Jak poznáte, že je hotovo:** V náhledu je oprava v samostatném oddílu
s uvedením opravovaného čtvrtletí a mezi varováními už není upozornění na dobropis
bez původního období.

> [!TIP]
> Import ani jiný automatický kanál původní období opravy nedoplní, v souboru není
> z čeho ho poznat. Kolik takových dokladů je, říká souhrn importu i náhled podání.

## 45.8 Krok za krokem: čtvrtletní OSS přiznání

1. Otevřete `Daně → OSS přiznání`.
2. Nahoře zvolte **rok a čtvrtletí**. Pod tím jsou záložky **Náhled**, **Archiv
   podání**, **Rekonciliace** a **Evidence § 110f**.
3. Na záložce **Náhled** zkontrolujte karty **Období**, **Základ daně**, **DPH
   z plnění**, **Opravy DPH**, **DPH celkem** a **Termín podání**.
4. Projděte **Upozornění**. Vyřešte řádky k ručnímu posouzení (viz
   [§ 45.5](#455-krok-za-krokem-vyrizeni-plneni-k-rucnimu-posouzeni)).
5. Projděte tabulku po státech a v rozbalovacím **Detail řádků** porovnejte doklady
   se zdrojem. Kurz pro přepočet je popsán v souhrnu náhledu.
6. Klikněte na **Stáhnout XML**. Aplikace uloží neměnný snapshot do archivu.
7. Přihlaste se na Daňovém portálu do aplikace **MOSS/OSS** (přihlášení do EPO pro
   ni neplatí) a XML tam nahrajte.
8. Vraťte se do MyÚčta, otevřete `Daně → EPO podání a archív`, označte snapshot jako
   podaný a případně k němu přiložte potvrzení.
9. Daň uhraďte v měně podání (eura). Platba z banky se zaúčtuje sama (viz
   [§ 45.10.12](#451012-uctovani-oss-dane)).

**Jak poznáte, že je hotovo:** Snapshot v archivu má stav **Podáno**
(`Daně → EPO podání a archív`) a máte uložené potvrzení z portálu.

> [!WARNING]
> Aplikace XML sama neodesílá. Před podáním ověřte varování, součty a registraci.
> Vygenerovaný soubor je pomůcka, ne náhrada odborné kontroly.

Termín podání je konec kalendářního měsíce následujícího po skončení čtvrtletí
(Q1 do 30. 4.). Stažení XML podáním není. Bez kroku 8 archiv neprokazuje, že bylo
podáno.

## 45.9 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Položka **OSS přiznání** v menu chybí | Není zapnutý OSS režim, nebo skončila zkušební doba a chybí licence | Zapněte režim podle [§ 45.3](#453-krok-za-krokem-zapnuti-oss-a-prvni-nastaveni), případně aktivujte licenci |
| Import zahraničních dokladů se zastavil, hláška uvádí kód sazby (například `PL-23`) se zemí `CZ` | Sazba v číselníku má špatný **Stát** | Opravte u sazby zemi na skutečnou a import zopakujte ([§ 45.4](#454-krok-za-krokem-zahranicni-sazby-dph-a-ciselnik-sazeb-statu)) |
| Hláška, že sazba pro zemi a procento neexistuje | Sazba v číselníku vůbec není | Ověřte, že plnění patří do té země, a sazbu založte se správným státem |
| Hláška **Číselník v databázi není - chybí migrace** | Po aktualizaci se nespustily databázové migrace | Správce spustí `php api/bin/migrate.php`. Do té doby se neověří žádný stát a import zahraničních dokladů se nerozběhne |
| Stránka číselníku vypíše seznam států bez platné sazby | Číselník nemá k dnešnímu dni platnou sazbu pro tyto státy | Spusťte všechny migrace a číselník znovu načtěte. Přetrvávající mezeru doplňte vlastní sazbou až po ověření její správnosti |
| Varování, že sazba v číselníku k datu plnění není | Stát změnil sazbu a číselník ji nezná, nebo doklad nese překlep | Aktualizujte číselník ([§ 45.4](#454-krok-za-krokem-zahranicni-sazby-dph-a-ciselnik-sazeb-statu)) nebo opravte sazbu na dokladu |
| Doklad byl odmítnut: sazba v zemi dodavatele k datu plnění neplatí a OSS nelze použít | OSS je pro řádek vyloučené (například odběratel má DIČ, datum před registrací) a číselník nepotvrdí tuzemskou sazbu | Hláška říká, co opravit. Opravte sazbu nebo údaje odběratele ([§ 45.10.8](#45108-jak-vznika-oss-radek)) |
| Řádek má štítek **chybí typ sazby** | Typ sazby se nikdy nedomýšlí a číselník ho nepotvrdil | Doplňte typ sazby na položce nebo hromadnou úpravou. Řádek bez typu sazby se do podání nedostane |
| E-shop se zbožím má v podání typ plnění `S` (služba) | Jednotka `ks` je neutrální a bez dalších údajů se dosadí „služba" | Použijte hromadnou úpravu nebo nastavte **Výchozí typ plnění pro OSS** na kartě odběratele ([§ 45.10.16](#451016-na-co-si-dat-pozor)) |
| Hromadná úprava přeskočila doklad | Doklad je stornovaný, zamčený, období už bylo podáno nebo jiný důvod z náhledu | Důvod je v náhledu ([§ 45.10.10](#451010-hromadna-uprava-dialog-preskakovani-a-vypnuti)) |
| Hromadná změna se zastavila, hláška **Hromadná změna se zastavila** | Dávka narazila na chybu u dokladu | Přečtěte, které doklady jsou změněné a které nezkoušené. Po odstranění příčiny akci spusťte znovu jen nad nimi |
| Hláška, že uložené PDF je staré | Data jsou zapsaná, ale cache PDF se nezahodila | Vygenerujte PDF znovu, než doklad odešlete |
| Náhled hlásí **Pro zvolené čtvrtletí nejsou označené žádné OSS řádky** | Žádný vydaný doklad nemá OSS řádek s datem plnění v čtvrtletí | Zkontrolujte datum plnění, platnost registrace a filtr **Místo plnění (OSS)** |
| **Stáhnout XML** nejde, řádky jsou nepřepočtené | Kurz ECB pro dané čtvrtletí zatím není (období neskončilo, výpadek) | Počkejte na kurz, nebo zadejte ruční kurz či ruční částky na položce |
| Export se zastavil kvůli oprávnění | Chybí oprávnění exportovat daňové výkazy | Požádejte správce firmy o oprávnění |
| Export se zastavil kvůli neplatnému původnímu období opravy, chybějící zemi spotřeby, typu plnění nebo typu sazby | Řádek nelze přepočítat nebo zařadit | Opravte řádek podle hlášky, případně zadejte ruční kurz či částky |
| EPO hlásí: musíte být přihlášeni v aplikaci MOSS/OSS | OSS přiznání se obecnou cestou EPO podat nedá | Přihlaste se do aplikace **MOSS/OSS** na Daňovém portálu a XML nahrajte tam ([§ 45.10.14](#451014-kde-se-oss-priznani-podava)) |
| U OSS snapshotu v archivu chybí **Otevřít a podat v EPO** a panel **Přímé podání se ZAREP** | OSS se těmito kanály podat nedá | Podejte v aplikaci MOSS/OSS a snapshot označte jako podaný |
| Export do Pohoda XML nebo Stereo XML doklad odmítl | Doklad nese OSS řádek a formát nemá kam zapsat zemi spotřeby | Doklad z exportu vyřaďte a OSS řádky vykažte přes `Daně → OSS přiznání` ([§ 45.10.11](#451011-doklad-navenek-dolozka-a-exporty)) |
| Záložka **Rekonciliace** hlásí, že se dnešní náhled liší | Doklad byl po podání opraven nebo se změnil | Posuďte opravné podání za původní období ([§ 45.10.15](#451015-archiv-podani-rekonciliace-a-evidence-110f)) |
| Zaúčtování dokladu skončilo chybou „nelze zaúčtovat" | Doklad nese jen OSS řádky a jde o daňový doklad k přijaté platbě (záloha) | V OSS se takový doklad nevydává. Daň se přiznává ke dni přijetí úplaty přímo v OSS přiznání |

## 45.10 Podrobnosti a pravidla

### 45.10.1 Kdy plnění patří do státu spotřeby

Do OSS patří plnění, u kterých se **místo plnění přesouvá do státu odběratele**:

- **prodej zboží na dálku** do jiného členského státu spotřebiteli (typicky e-shop),
- **digitální (TBE) služby**: telekomunikační, rozhlasové a televizní vysílání
  a elektronicky poskytované služby,
- další služby, u kterých místo plnění určuje sídlo příjemce.

Společné mají to, že odběratel je **osoba nepovinná k dani** (spotřebitel bez DIČ)
z **jiného členského státu EU**. Dodání osobě s platným DIČ do OSS **nepatří**.
Je to osvobozené dodání do jiného členského státu (ř. 20 nebo 21 přiznání)
a vykazuje se v [souhrnném hlášení](44_Souhrnne_hlaseni.md).

Běžné služby B2C, u kterých místo plnění zůstává v ČR podle § 9 odst. 2 ZDPH
(konzultace, řemeslo, hodinová práce), se fakturují s českou daní a do OSS
nevstupují, i když je odběratel z Polska.

Související kapitoly: [import zahraničních dokladů](21_Importy.md#2197-zahranicni-doklady-a-rezim-oss),
[hromadné nastavení OSS](14_Faktury.md#148-krok-za-krokem-hromadne-nastaveni-oss),
[sazby a číselníky](96_Nastaveni.md#963-krok-za-krokem-ciselniky) a
[daňový průvodce](40_Fakturujeme.md#40913-zahranicni-fakturace).

### 45.10.2 Práh 10 000 EUR

Dokud součet **všech** přeshraničních B2C plnění do EU za kalendářní rok
nepřekročí **10 000 EUR** (§ 8 odst. 3 ZDPH), může dodavatel plnění dál zdaňovat
českou sazbou. Po překročení se místo plnění přesune do státu spotřeby a je nutné
buď se registrovat k DPH v každé cílové zemi, nebo použít OSS. Registrace je možná
i **dobrovolně** před dosažením prahu.

Práh je **celounijní a společný pro zboží i služby**. Do součtu se proto počítají
i plnění, která zatím fakturujete s českou daní. Kdyby se sčítala jen ta už
označená jako OSS, práh by nikdy nemohl být překročen.

### 45.10.3 Sledování prahu v aplikaci

Na stránce `Daně → OSS přiznání` je blok **Čerpání prahu 10 000 EUR** za zvolený
kalendářní rok. Ukazuje součet v EUR, procento vyčerpání, rozpad podle států
a případné datum překročení.

<!-- cols: 40 60 -->
| Situace | Co aplikace hlásí |
|---|---|
| Od 80 % prahu | Upozornění na blížící se limit se sledováním zbytku roku |
| Práh překročen | Datum překročení a výzva ověřit registraci do OSS |
| Práh překročen, OSS vypnutý | Že se plnění dál fakturují s českou daní |
| OSS zapnutý, práh nedosažen | Že dobrovolná registrace je možná, ale je dobré si ji potvrdit, jinak daň míří do nesprávného státu |
| Nepřepočtené řádky | Kolik řádků se nepodařilo přepočíst do EUR, takže skutečné čerpání je vyšší |

Do součtu vstupují všechna plnění za rok odběratelům ze států EU mimo zemi
dodavatele a bez DIČ. Koncepty, stornované doklady, proformy a penalizační
faktury se vylučují.

> [!WARNING]
> Přepočet do EUR je **orientační**. Používá denní kurz ČNB k datu plnění, kdežto
> směrnice pracuje s pevným přepočtem. U hodnot blízko limitu si čerpání ověřte
> s účetní. Sledování prahu samo OSS nezapne, doklady nepřeklasifikuje ani
> nerozhodne, jaký režim se na plnění právně vztahuje.

### 45.10.4 Platnost registrace

Pole **Země identifikace**, **Měna podání**, **Platné od** a **Platné do** jsou
popsána v [§ 45.3](#453-krok-za-krokem-zapnuti-oss-a-prvni-nastaveni). Platnost se
vyhodnocuje **k datu plnění každého řádku**, ne k datu vystavení dokladu ani
k dnešku. Když registrace začala nebo skončila **uvnitř** vykazovaného čtvrtletí,
přenese se hranice i do podání (volitelně ve větě **VetaD** XML).

Jiná měna podání než `EUR` není zakázaná, ale náhled i export na ni upozorní.
EPO očekává částky v eurech.

### 45.10.5 Sazby DPH cizích zemí a pole Stát

Aby šla na položku vybrat zahraniční sazba, musí být v číselníku **Sazby DPH**
([§ 96.3](96_Nastaveni.md#963-krok-za-krokem-ciselniky)) založená. Zakládá se stejně jako
tuzemská sazba, ale s jedním rozdílem, který je **nejčastější příčinou toho, že
import doklad odmítne**: formulář předvyplňuje pole **Stát** na `CZ`, kdežto
rozhoduje právě tento sloupec, ne kód sazby.

Co se při špatném státu stane:

- **Import zahraničních dokladů se zastaví** a v reportu adresně řekne, že kód
  (například `PL-23`) v číselníku sice je, ale se zemí `CZ`, a že je potřeba u něj
  opravit zemi na `PL` a import zopakovat.
- Když sazba pro danou zemi a procento **neexistuje vůbec**, hláška napřed vyzve
  ověřit, že plnění opravdu patří do té země, a teprve pak navede k jejímu založení,
  včetně připomínky, že formulář zemi předvyplňuje na `CZ`.

Je to **záměrná pojistka, ne chyba**. Zemi zkontrolujte dřív, než spustíte import
nebo hromadnou úpravu OSS.

Jak se sazba páruje na položku:

<!-- cols: 45 55 -->
| Situace | Co se stane |
|---|---|
| Sazba pro danou zemi a procento platná k datu plnění | Naváže se, nic se nehlásí |
| Táž země a procento, ale mimo uvedenou platnost | Naváže se **s varováním**. Do dokladu se otiskne procento, výkazy počítají z něj |
| Žádná shoda na zemi a procento | **Odmítne se celý doklad**, žádné „nejbližší procento" |

Sazby s příznakem reverse charge se pro OSS nepárují.

Tabulka DPH sazeb slouží jen k tomu, aby se sazba dala na položku vybrat.
**Autoritou o tom, kam plnění patří, není.** Tou je číselník sazeb členských států
(viz další oddíl). Důvod je prostý: DPH sazby si zakládá uživatel a může v nich mít
překlep, kdežto číselník je nezávislý.

### 45.10.6 Číselník sazeb členských států

Cesta: `Systém → Sazby a číselníky`, záložka **Sazby států OSS**
([§ 96.16.1](96_Nastaveni.md#96161-ciselniky-podrobnosti)).

Je to **kontrolní číselník** sazeb DPH platných v jednotlivých členských státech,
ne sazby pro doklad. Aplikace se ho ptá na jedinou věc: platí tahle sazba v téhle
zemi k tomuhle datu? Odpověď rozhoduje o tom, jestli je plnění tuzemské, nebo patří
do OSS.

Číselník je dodaný s aplikací, sdílený celou instalací a **běžně se needituje**.
Měnit ho smí jen správce instance a jen z webového rozhraní.

<!-- cols: 30 70 -->
| Sloupec | Význam |
|---|---|
| **Stát** | Dvoupísmenný kód členského státu |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Sazba** | Procento |
| **Platí od** / **Platí do** | Historie sazby. Prázdné **Platí do** = platí dosud |
| **Poznámka** | Volný text |
| **Původ** | `systémová` (dodaná s aplikací) nebo `vlastní` (přidal uživatel) |

Systémový řádek nelze přepsat ani smazat, jeho hodnoty používá aktualizační
migrace k rozpoznání, co je vlastní záznam. Povolené jsou u něj jen dvě akce:
**Zkrátit** platnost k datu a **Vyřadit** (a zase **Vrátit**). Ručně přidané sazby
aktualizační migrace nepřepisuje.

Když sazba na dokladu číselníku neodpovídá, aplikace **varuje, ale neblokuje**.
Číselník může být zastaralý a poslední slovo má člověk. Varování se objeví
v náhledu podání i v náhledu hromadné úpravy a rozlišuje čtyři situace:

- číselník v databázi vůbec není,
- stát v něm není,
- procento v té zemi k datu neplatí (s výčtem těch, které platí),
- procento sice platí, ale pod jiným typem sazby, než jaký doklad deklaruje.

Hláška **Číselník v databázi není - chybí migrace** není totéž jako „stát
v číselníku chybí". Znamená, že se po aktualizaci nespustily databázové migrace
(`php api/bin/migrate.php`).

Stránka číselníku zároveň kontroluje, zda má každý členský stát k dnešnímu dni
alespoň jednu platnou sazbu. Pokud zobrazí seznam zemí s chybějícím pokrytím,
nejde o chybu jednotlivého dokladu: nejprve spusťte všechny databázové migrace
a číselník znovu načtěte.

### 45.10.7 Nastavení na kartě odběratele

Karta klienta má sekci **Režim OSS** se dvěma poli:

<!-- cols: 30 30 40 -->
| Pole | Volby | K čemu |
|---|---|---|
| **Režim OSS** | Automaticky (doporučeno) / Neuplatňovat OSS | Umožní OSS u konkrétního odběratele **vyloučit** |
| **Výchozí typ plnění pro OSS** | Odvodit automaticky / Zboží / Služba | Použije se, když typ plnění nejde určit z měrné jednotky položky |

**Karta umí OSS jedině vyloučit, vynutit ne.** Vyloučení se hodí u odběratele,
o kterém víte, že je osobou povinnou k dani, jen zatím nedodal DIČ. Opačný směr
karta nenabízí schválně: o tom, že plnění do OSS patří, rozhoduje sazba, země
odběratele a číselník, ne uložený úmysl na kartě.

Vyloučení je bezpečné: pravidlo z [§ 45.10.8](#45108-jak-vznika-oss-radek) platí
dál, takže ani u vyloučeného odběratele se cizí sazba nestane tuzemskou. Řádek se
místo toho odmítne s hláškou.

Výchozí typ plnění je nejlevnější způsob, jak se zbavit opakované ruční práce
u e-shopu se zbožím (viz [§ 45.10.16](#451016-na-co-si-dat-pozor)).

**Výchozí země spotřeby na kartě záměrně není.** Země se bere z adresy odběratele
na konkrétním dokladu, protože ta je pravdivější než uložená karta.

### 45.10.8 Jak vzniká OSS řádek

Zařazení do OSS je **vlastnost jednotlivého řádku faktury**, ne celého dokladu.
Odvozuje se **automaticky ve všech vstupních kanálech**: při importu, u pravidelné
fakturace, při synchronizaci z iDokladu a Fakturoidu, při čtení PDF i přes veřejné
API. V editoru faktury zůstává ruční přepínač, ale i tam běží stejné kontroly.

#### Podmínky, které OSS vylučují

Nejdřív se vyhodnotí, jestli řádek vůbec může být OSS. Stačí jediná z těchto
podmínek a OSS je vyloučené:

<!-- cols: 45 55 -->
| Podmínka | Poznámka |
|---|---|
| Chybí nebo je nečitelné **datum plnění** | Bez data nejde ověřit ani platnost registrace, ani platnost sazby |
| Chybí **číselník sazeb členských států** | Nespuštěné migrace, viz [§ 45.10.6](#45106-ciselnik-sazeb-clenskych-statu) |
| Firma **nemá zapnutý OSS režim** | |
| Datum plnění leží **mimo platnost registrace** | |
| Doklad je v režimu **přenesené daňové povinnosti** | |
| Odběratel **nemá vyplněnou zemi** | |
| Odběratel je ze **země dodavatele** | „Tuzemsko" se bere ze země dodavatele, ne natvrdo z ČR |
| Odběratel je **mimo EU** | |
| Odběratel **má DIČ** | Tedy B2B, do OSS nepatří |
| Karta odběratele **OSS vylučuje** | Viz [§ 45.10.7](#45107-nastaveni-na-karte-odberatele) |
| Sazba řádku je **0 %** | Osvobození, reverse charge a vývoz se vykazují bez daně |

Každá podmínka má vlastní hlášku i konkrétní radu, co doplnit.

#### Rozhodovací pravidlo

Zbytek rozhodne **číselník sazeb členských států**, kterému se položí dvě otázky:
platí tahle sazba v zemi dodavatele k datu plnění? A platí ve státě spotřeby?
Každá má tři možné odpovědi: **platí / neplatí / nevím**.

> **Do tuzemského přiznání smí jen řádek, u kterého číselník POZITIVNĚ potvrdí, že
> sazba v zemi dodavatele k datu plnění opravdu platí.** Každá jiná odpověď
> (neplatí, nevím, nečitelné datum) znamená, že se řádek do tuzemska nepustí.

<!-- cols: 28 24 24 24 -->
| Odpověď za stát spotřeby / za zemi dodavatele | **platí** | **neplatí** | **nevím** |
|---|---|---|---|
| OSS je vyloučené (viz tabulka výše) | tuzemské plnění | **odmítnuto** | **odmítnuto** |
| **neplatí** | tuzemské plnění | OSS, typ sazby prázdný | OSS + k posouzení |
| **platí** | OSS + k posouzení | **OSS** (čistý případ) | OSS + k posouzení |
| **nevím** | OSS + k posouzení | OSS, typ sazby prázdný | OSS + k posouzení |

Řádek s nulovou sazbou je z pravidla vyňatý, číselník nulové sazby nevede.

#### Co systém odmítne a proč

**Odmítnutí** nastane, když je OSS z nějakého důvodu vyloučené, ale číselník
zároveň nepotvrdí, že sazba v zemi dodavatele platí. Typický případ: doklad se
sazbou 23 % pro odběratele, který má DIČ, nebo doklad se zahraniční sazbou z doby
před začátkem registrace.

Hláška má vždycky dvě věty: proč a co s tím. Například že sazba 23 % podle
číselníku v zemi dodavatele k datu plnění neplatí, takže řádek nemůže být tuzemské
plnění, ale do OSS ho zařadit nelze, protože firma nemá zapnutý režim OSS.

> **Sazba, kterou číselník v zemi dodavatele nezná, se nikdy nevykáže jako tuzemské
> plnění.** Kdyby ano, polská nebo maďarská daň by tiše skončila na ř. 1 českého
> přiznání k DPH jako česká daň na výstupu, kde ji mezi stovkami tuzemských řádků
> nikdo nenajde, až přijde výzva. Aplikace se raději zastaví a řekne, co opravit.

#### Typ sazby a typ plnění

**Typ sazby** (základní / snížená / druhá snížená / parkovací) se **nikdy
nedomýšlí**. Buď ho potvrdí číselník podle země a procenta, nebo zůstane prázdný
s varováním. Řádek bez typu sazby se do podání nedostane. Doplňte ho na položce nebo
hromadnou úpravou.

**Typ plnění** (zboží / služba) se hledá od nejkonkrétnějšího signálu:

1. **měrná jednotka položky**,
2. **výchozí typ plnění z karty odběratele**,
3. **převažující činnost dodavatele** (CZ-NACE),
4. výchozí **„služba"**, a to je hlášené varování, ne tichý dosazený údaj.

Poslední bod je v praxi nejdůležitější: jednotka `ks` je záměrně vedená jako
neutrální (je to výchozí hodnota, takže netvrdí nic), takže e-shop se zbožím
skončí u „služby", pokud nemá vyplněný CZ-NACE nebo výchozí typ na kartě
odběratele (viz [§ 45.10.16](#451016-na-co-si-dat-pozor)).

#### Rozdíly mezi kanály

Odvození je ve všech kanálech totožné. Liší se jen to, **co se stane s odmítnutým
řádkem**, a to podle toho, jestli je zdroj pravdy venku a dá se běh zopakovat:

<!-- cols: 30 70 -->
| Kanál | Chování |
|---|---|
| **Import souborů** (Pohoda XML, ISDOC), **iDoklad**, **Fakturoid**, **AI extrakce** | **Doklad se nevytvoří.** Chyba jmenuje konkrétní položku. Po opravě se běh zopakuje a doplní jen chybějící doklady |
| **Převod účetního roku z POHODY** ([§ 107.9.2](107_Prechod_z_POHODY.md#10792-co-prevod-prenese)) | Odvozuje se jen u dokladu, jehož **členění DPH stojí mimo přiznání a přesto nese daň**. Ostatních se převod nedotkne, protože o jejich zařazení už rozhodlo členění. Doklad bez státu MOSS POHODA v OSS nevede, převezme se jako koncept k ruční kontrole. Odmítnutý doklad se **nepřevezme** a protokol ho jmenuje. Příčinu společnou celému běhu (vypnutý režim, chybějící číselník) řekne jednou větou na začátku |
| **Pravidelná fakturace** (cron) | Doklad **vzniknout musí**, jinak by chybějící číselník zastavil fakturaci. Řádek zůstane mimo OSS a povinně dostane příznak **k ručnímu posouzení** |
| **Veřejné API** bez OSS údajů | Režim se odvodí. Do odpovědi jde poznámka a řádky, u kterých místo plnění určit nešlo, se označí k posouzení |
| **Editor faktury** | Rozhoduje uživatel přepínačem OSS na řádku. Kontrola soudržnosti dokladu běží stejně |

Na začátku každého importního běhu proběhne rychlá kontrola číselníku. Když
tabulka chybí nebo číselník nevede ani jednu sazbu pro zemi dodavatele, ohlásí se
to jednou nahlas, jinak by se odmítl každý doklad se sazbou nad 0 %, včetně ryze
české faktury.

**Šablony pravidelných faktur** si OSS pamatují jako **rozhodnutí člověka**, takže
mají přednost před odvozením a příznak k posouzení u nich nevzniká. Jediná výjimka:
pokud k datu plnění generovaného dokladu registrace do OSS neplatí, uložené
rozhodnutí se nepoužije, jede se odvozením a řádek příznak k posouzení dostane.

Nastavuje se **přímo na položce šablony** (`Faktury → Pravidelné`, editor šablony):
u řádku je zaškrtávátko **OSS** a pod ním stát spotřeby, typ sazby a typ plnění,
stejná pole jako na řádku faktury, jen bez kurzu, přepočtených částek a opravy
období (to jsou vlastnosti konkrétního dokladu, ne předpisu). Stát spotřeby je
povinný: bez něj se řádek uloží jako tuzemský, protože položku s OSS a bez země by
cron při každém běhu vyrobil neplatnou. Prázdný typ sazby doplní při generování
odvození, ale jen když mluví o **témže** státu spotřeby. Podrobně
[§ 17.8.4](17_Pravidelne_fakturace.md#1784-polozky).

Bez uloženého rozhodnutí šablona **mlčí** a rozhoduje odvození při každém generování.
Tak fungují všechny šablony založené před doplněním OSS polí. Pro e-shop
fakturující spotřebitelům v EU je to bezpečná výchozí cesta. Uložené rozhodnutí má
smysl tam, kde odvození samo nestačí (typ sazby, který číselník nepotvrdil, nebo typ
plnění, který z jednotky ani z CZ-NACE nevyplývá).

### 45.10.9 Plnění k ručnímu posouzení

Sporné řádky končí na **dvou různých místech** a každé se řeší jinou otázkou:

<!-- cols: 16 22 34 28 -->
| Stav | Kde daň leží | Jak vzniká | Na co se ptát |
|---|---|---|---|
| **Nejisté - v OSS podání** | V OSS podání | Sazba platí i v zemi dodavatele (21 % zná ČR, Nizozemsko, Belgie, Španělsko, Litva i Lotyšsko), číselník neuměl odpovědět, nebo si doklad protiřečí | Sedí země spotřeby a typ sazby? Nepatří plnění do tuzemska? |
| **Nejisté - v tuzemsku** | V přiznání k DPH na ř. 1 a 2 | Automatický kanál místo plnění neurčil a doklad zahodit nesměl, nebo řádek nese tuzemskou sazbu, přestože jde o přeshraniční B2C plnění a registrace k datu plnění platí | Patří plnění do tuzemského přiznání? Nemá jít do OSS? |

**Proč se import rozhoduje ve prospěch OSS.** Chybně zařazený OSS řádek uvidíte
v náhledu podání, který má pár řádků. Chybně zařazený tuzemský řádek zmizí mezi
stovkami řádků přiznání k DPH. Ze dvou možných omylů je ten první levnější.
U kanálů, které běží bez lidského zásahu, je to obráceně: do OSS podání nemá jít
nic, co nikdo nepotvrdil.

Zvláštní případ druhého stavu: řádek je zdaněný **tuzemskou** sazbou, přestože jde
o přeshraniční plnění spotřebiteli bez DIČ a firma má k datu plnění aktivní
registraci. Aplikace **sazbu ani zařazení nemění**, protože ji uvádí doklad
a registrace je dobrovolná, takže plnění tuzemské být může. Jen se rozpor označí.
U odběratele s vyloučeným OSS se tenhle rozpor nehlásí vůbec, byl by to šum na každé
jeho faktuře.

**Doklad rozpadlý mezi obojí.** Kontrola soudržnosti běží při **každém** uložení
dokladu. Když jedna faktura obsahuje zároveň OSS řádky a tuzemsky zdaněné řádky,
leží ve dvou různých přiznáních. Doklad se **nezamítá**, smíšená faktura umí
vzniknout legitimně, ale **označí se obě strany rozporu** a uživatel dostane výzvu
zkontrolovat sazby. Nulové sazby a slevové řádky se do posouzení nepočítají.

Rozhodnutí děláte v editoru faktury (přepínač OSS na položce) nebo hromadně
([§ 45.6](#456-krok-za-krokem-hromadna-uprava-oss)). Výběr **Jen řádky k ručnímu
posouzení** zabírá oba stavy najednou.

### 45.10.10 Hromadná úprava: dialog, přeskakování a vypnutí

Postup je v [§ 45.6](#456-krok-za-krokem-hromadna-uprava-oss). Pole dialogu:

<!-- cols: 30 70 -->
| Pole | Volby |
|---|---|
| **Které položky** | Jen řádky k ručnímu posouzení (výchozí) / Jen OSS řádky bez typu sazby / Všechny OSS řádky / Všechny položky dokladu |
| **Režim OSS** | Zapnout OSS / Vypnout OSS (plnění je tuzemské) / Ponechat beze změny |
| **Země spotřeby** | Členský stát, do kterého plnění patří |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Typ plnění** | Zboží / Služby |
| **Označit řádky jako posouzené** | Zhasne příznak „místo plnění k ručnímu posouzení" |

Náhled ukazuje, kolik dokladů a položek se změní, kolik se přeskočí a proč, a jaká
varování k sazbám vznikla. Celá akce se odmítne, když se pokusíte zapnout OSS bez
zapnutého režimu u firmy, zvolit jako zemi spotřeby zemi identifikace dodavatele
(takové plnění je tuzemské), nebo zemi, která není členským státem EU.

Volba **Označit řádky jako posouzené** existuje proto, že potvrzení místa plnění je
rozhodnutí člověka a systém ho sám neruší.

#### Co se přeskočí a proč

Akce nemá „provést i tak". Příznak OSS rozhoduje, jestli řádek jde do českého
přiznání, nebo do OSS podání, takže na dokladu, který už je odevzdaný nebo zamčený,
se nepřepisuje. Přeskočí se **celý doklad**, ne jen sporný řádek:

<!-- cols: 40 60 -->
| Důvod | Vysvětlení |
|---|---|
| Doklad neexistuje nebo patří jiné firmě | |
| Stornovaný doklad | Neupravuje se |
| Doklad je uzamčen | Zaúčtovaný, v uzavřeném účetním období, v uzávěrce nebo pod daňovým zámkem |
| Období už bylo podáno | Přiznání k DPH, kontrolní hlášení nebo OSS přiznání za to období. Řeší se opravným či dodatečným tvrzením, ne přepsáním dokladu |
| Záznamy roku jsou zadržené podle § 32 ZoÚ | Retenční hold |
| Datum plnění mimo platnost registrace | Zapnout OSS na dokladu z doby, kdy registrace neplatila, by ho odstranilo z českého přiznání, aniž by se objevil v OSS podání |
| Bez země spotřeby by OSS řádek nešel podat | Doplňte zemi spotřeby ve stejném dialogu |
| Sazba řádku v tuzemsku nepotvrzena | Viz další oddíl o vypnutí OSS |
| Doklad nemá položku ve výběru / položky už hodnoty mají | Není co měnit |

„Podáno" znamená **prokazatelně odevzdaný** snapshot. Samotné stažení XML podáním
není.

#### Vypnutí OSS je hlídané stejně jako zapnutí

Zhasnout příznak OSS znamená přesunout daň z OSS podání **na ř. 1 českého
přiznání**. Je to tedy stejně vážný krok jako zapnutí, jen opačným směrem, a proto
se ptáme téhož číselníku téže otázky:

- Řádek, který se **stěhuje** (byl OSS a přestává jím být), a číselník sazbu v zemi
  dodavatele **nepotvrdí** → **celý doklad se přeskočí**. Odpověď „nevím" (chybí
  číselník, stát k datu nezná, nečitelné datum plnění) se bere stejně jako
  „neplatí".
- Řádek, který **mimo OSS byl už předtím**, se nikam nestěhuje → změna projde, jen
  se vypíše varování, ať ověříte, jestli do tuzemského přiznání opravdu patří. Bez
  téhle výjimky by nešlo odklikat řádky označené „nevím" z automatických kanálů,
  což je hlavní důvod, proč výběr *Jen řádky k ručnímu posouzení* existuje.
- Sazba 0 % je z kontroly vyňatá.

Vypnutí OSS zároveň **vynuluje zemi spotřeby, typ sazby i typ plnění**. Peněžní
údaje (ručně zadaný kurz, ručně zadané částky v měně podání) se záměrně nemění,
vynulovat by zahodilo ruční práci.

Po zásahu se příznak „k ručnímu posouzení" **přepočítá**. Pokud doklad i po změně
leží zároveň v OSS podání a v tuzemském přiznání, příznak se vrátí. Volba
**Označit řádky jako posouzené** ho nedokáže odklikat pryč, dokud rozpor trvá,
a náhled to dopředu ohlásí.

Pokud dávka narazí na chybu, **zastaví se u prvního dokladu, který neprošel**,
a výsledek vypíše, které doklady jsou už změněné a které se ani nezkusily.
Změněným dokladům se zahodí PDF cache, protože doklad nese OSS doložku.

### 45.10.11 Doklad navenek: doložka a exporty

#### Doložka na faktuře

Jakmile je na dokladu **aspoň jeden** OSS řádek, nese doklad **OSS doložku**, a to
shodně v PDF i ve veřejném náhledu („web faktura"), česky nebo anglicky podle
jazyka dokladu.

- **Doklad celý v OSS:** „Daň je přiznána a odvedena ve státě spotřeby v režimu
  jednoho správního místa (One Stop Shop) podle § 110a a násl. zákona o DPH."
- **Smíšený doklad:** opatrnější formulace, která výslovně říká, že se týká jen
  položek v režimu OSS.

Za větou se jmenovitě vypíšou **státy spotřeby**. Výčet je buď úplný, nebo se
nevypíše vůbec, protože kdyby některý OSS řádek zemi neměl, neúplný výčet by na
dokladu lhal. Slevové řádky se nepočítají mezi řádky plnění, takže z dokladu celého
v OSS nedělají smíšený.

V editoru nese OSS řádek informační štítek **Jedno správní místo**.

#### Exporty

<!-- cols: 30 70 -->
| Export | Chování |
|---|---|
| **Pohoda XML** | Doklad s OSS řádkem se **neexportuje**. Export to řekne s vysvětlením |
| **Stereo XML** | Totéž, doklad se odmítne s vysvětlením |
| **ISDOC** | Projde, ale OSS nijak neoznačuje, přenáší se jen procento sazby |

Důvod odmítnutí u Pohoda XML: její formát vede sazbu DPH jako **výčet tuzemských
úrovní** (základní / snížená / nulová) a nemá kam zapsat zemi spotřeby. Polská
sazba 23 % by do Pohody dorazila jako česká základní. Export proto raději nic
neudělá, než aby cizí sazbu tiše vydával za českou
([§ 20.7.3.7](20_Exporty.md#20737-doklad-v-rezimu-oss-se-do-pohody-neexportuje)).

Řádky v režimu OSS vykažte přes `Daně → OSS přiznání` a doklady s nimi z exportu
do Pohody nebo Sterea vyřaďte.

### 45.10.12 Účtování OSS daně

Daň v režimu OSS **není česká daň na výstupu**. Patří jinému členskému státu, do
přiznání k DPH ani do kontrolního hlášení nevstupuje a odvádí se samostatně. Proto
se neúčtuje na 343, ale na vlastní účet:

> **345.100 - DPH v režimu OSS (jiný členský stát)**, obsazované předkontací
> `oss.output.vat`.

Na účtu 343 zůstává přesně to, co jde do přiznání k DPH, takže **zůstatek 343 jde
s přiznáním srovnat**. V rozvaze je 345.100 součástí téže položky **„Stát - daňové
závazky a dotace"** jako 343, takže se ve výkazech nic nemění, mění se jen možnost
kontroly.

Zápisy vydané faktury:

<!-- cols: 24 12 64 -->
| Účet | Strana | Co |
|---|---|---|
| 311 | MD | Celá pohledávka |
| Výnosový účet (602, ...) | D | **Základ tuzemský i OSS jde na týž výnosový účet**, výnos je výnos bez ohledu na to, kterému státu daň patří |
| 343 | D | Jen tuzemská daň |
| **345.100** | D | Jen OSS daň, a jen když je nenulová |

**Smíšená faktura** se zaúčtuje jedním dokladem, jen se daňová noha rozdělí mezi
343 a 345.100. **Dobropis i storno** obracejí obě daňové nohy.

**Úhrada OSS závazku z banky** se rozpozná zvlášť a zaúčtuje **MD 345.100 / D 221**.
Platba se pozná podle **čísla účtu finanční správy vyhrazeného pro OSS**, ne podle
variabilního symbolu. Referenční číslo OSS platby má tvar `CZ/CZ<DIČ>/Qn.RRRR`,
což není číselný variabilní symbol. Odvádí se v měně podání, tedy v eurech.

**Daňový doklad k přijaté platbě (záloha) se v režimu OSS nevydává.** Daň se
přiznává ke dni přijetí úplaty přímo v OSS přiznání. Doklad, který by nesl jen
OSS řádky, proto skončí hlasitou chybou „nelze zaúčtovat", nikdy tiše bez daňové
nohy.

> [!WARNING]
> Účet lze v předkontacích u pravidla `oss.output.vat` změnit, například na vlastní
> analytiku **pod 343**. Nedělejte to: součet syntetiky 343 (tedy 343 včetně
> [analytik vstupu, výstupu a zúčtování](66_Ucetni_osnova.md#6685-analytiky-dph-343100-343200-a-343900))
> se pak přestane shodovat s tuzemským přiznáním k DPH a OSS daň jiného státu by
> navíc vstoupila do [měsíčního zúčtování DPH](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph).
> Přesně kvůli tomu má OSS daň vlastní účet **345.100**.

### 45.10.13 Přiznání: náhled, kurz, opravy a XML

#### Kvartální náhled

Do přiznání vstupují jednotlivé OSS řádky vydaných faktur, jejichž datum plnění
patří do zvoleného čtvrtletí. Aplikace je seskupí podle **státu spotřeby, typu
plnění, typu sazby a procenta** a oddělí běžná plnění od oprav za dřívější období.
Výpočet vychází z řádkových základů a daně, ne z hlaviček dokladů.

Tabulka po státech ukazuje sazbu, typ sazby, základ, daň a počet řádků.
Rozbalovací **Detail řádků** vypíše jednotlivé doklady včetně měny a kurzu.

Náhled kontroluje zejména vyplněnou zemi spotřeby, existenci a shodu sazby proti
číselníku, přítomnost typu sazby, přepočet do měny podání a údaje potřebné pro
opravy minulých období. Daň z OSS řádků do českého přiznání k DPH nevstupuje
a řádky nejsou v kontrolním ani souhrnném hlášení. Jejich základ bez daně se ale
v přiznání k DPH i v [Knize DPH](42_Kniha_DPH.md) uvádí na ř. 24 „Vybraná plnění
(§ 110b odst. 2)", viz [§ 41.3.1](41_Vykazy_DPH.md#co-se-z-oss-promitne-do-priznani-k-dph).

#### Přepočet do měny podání

Částky v jiné měně se do měny podání přepočtou **kurzem Evropské centrální banky
zveřejněným pro poslední den zdaňovacího období** (čl. 91 směrnice 2006/112/ES),
**jedním kurzem pro celé čtvrtletí**, ne denním kurzem k datu plnění.

- Když ECB pro poslední den kurz nezveřejnila (víkend, svátek TARGET), použije se
  **nejbližší následující den**. Použité datum vidíte v souhrnu náhledu.
- **Kurz ČNB k datu plnění se tu nepoužívá.** Ten platí pro tuzemský základ daně,
  ne pro OSS podání.
- **Ruční kurz i ruční částky zadané na položce mají přednost vždy.**
- Dokud kurz pro dané čtvrtletí neexistuje (období ještě neskončilo, výpadek),
  zůstanou řádky nepřepočtené, náhled to jmenovitě oznámí a **XML nejde vytvořit**.

#### Opravy minulých období

Oprava plnění za dřívější čtvrtletí patří v OSS podání do **samostatného oddílu
s uvedením opravovaného období**. Zadává se na položce faktury v poli **Oprava
období** (postup v [§ 45.7](#457-krok-za-krokem-oprava-plneni-z-minuleho-ctvrtleti)).

Oprava se **nepřepočítává kurzem běžného čtvrtletí**, ale kurzem **opravovaného**
období. Hledá se ve dvou krocích: nejdřív v evidenci § 110f zapsané k podání toho
čtvrtletí, potom v kurzu ECB pro jeho poslední den. Zdroj kurzu je vidět v souhrnu
náhledu. Když neuspěje ani jeden, je oprava neplatná, řádek zůstane nepřepočtený
a export se zastaví s vysvětlením. Pomůže ruční kurz nebo ruční částky na položce.

**Dobropis nebo storno bez vyplněného původního období** podání nezablokuje, ale
náhled na něj upozorní: oprava se započte do běžného čtvrtletí, tedy do jiného, než
kam patří. Import původní období nedoplňuje, v souboru není z čeho ho poznat.

#### XML formuláře OSSEI1

Stažení vytvoří XML formuláře **`OSSEI1`** v měně nastavené pro OSS. Struktura:

<!-- cols: 20 80 -->
| Věta | Obsah |
|---|---|
| **VetaD** | Hlavička: rok a čtvrtletí, název firmy, DIČ, IBAN a BIC účtu v měně podání. Volitelně hranice registrace, když začala nebo skončila uvnitř čtvrtletí |
| **VetaP** | DIČ |
| **VetaR** | Běžná plnění agregovaná po státu spotřeby, typu plnění, typu sazby a procentu. Typ plnění `G` = zboží, `S` = služby. Typ sazby `Z` = základní, `S` = ostatní |
| **VetaO** | Opravy minulých období: opravovaný rok, čtvrtletí a stát spotřeby |

**Export se zastaví**, když jsou v období neplatná původní období oprav, chybí
přepočet do měny podání, nebo některý řádek nemá zemi spotřeby, platný typ plnění
či platný typ sazby.

**Export jen varuje** při chybějícím DIČ, měně podání jiné než EUR, chybějícím IBAN
nebo vynechané opravě.

Export vyžaduje oprávnění exportovat daňové výkazy, uloží neměnný snapshot
zdrojových dat do archivu a zapíše akci do activity logu.

### 45.10.14 Kde se OSS přiznání podává

XML má formát **`OSSEI1`**, ale **obecnou cestou EPO ho podat nelze**. Daňový portál
písemnost sice rozpozná (zobrazí *„DAP OSS - režim EU - Přiznání k DPH platné od
1. 7. 2021"*) a vzápětí ji odmítne hláškou:

> Pro práci s písemností „DAP OSS - režim EU - Přiznání k DPH platné od 1.7.2021"
> musíte být přihlášeni v aplikaci MOSS/OSS!

**MOSS/OSS je samostatná aplikace Daňového portálu**, v horní liště vedle EPO,
Registru DPH, Vracení DPH a DAC7. Přihlášení do EPO pro ni neplatí, je potřeba se
přihlásit přímo do ní. Postup je v [§ 45.8](#458-krok-za-krokem-ctvrtletni-oss-priznani).

Proto u OSS snapshotu v archivu podání **není tlačítko Otevřít a podat v EPO**
ani u něj nefunguje asistované předání přes API. Nezobrazí se ani panel
**Přímé podání se ZAREP**: přímé podání jde na týž endpoint portálu, takže se
láme o stejnou podmínku, jen by uživatel předtím zbytečně odemkl podpisový klíč.
Nabízet kteroukoli z těch cest by znamenalo posílat uživatele na chybu portálu.
Ostatní formuláře (DPH, kontrolní a souhrnné hlášení, daň z příjmů) obě cesty
mají, viz [kapitola 49](49_Archiv_podani_a_rekonciliace.md#493-krok-za-krokem-asistovane-podani).

### 45.10.15 Archiv podání, rekonciliace a evidence 110f

**Záložka Archiv podání.** Vypisuje všechny archivované OSS snapshoty s časem
vzniku, stavem, výsledkem validace, **SHA-256 otiskem** a odkazem na stažení
uloženého souboru. Tytéž snapshoty leží ve společném archivu v
`Daně → EPO podání a archív` ([kapitola 49](49_Archiv_podani_a_rekonciliace.md)),
kde se k nim připojují pokusy o podání, doručenky a označení „podáno".

Archivovaný soubor prokazuje, **co vzniklo, ne že bylo podáno**. Po odeslání
snapshot označte jako podaný, jinak archiv není důkazem podání.

**Záložka Rekonciliace.** Porovnává archivované podání s tím, co by se za totéž
období podalo dnes. Neimportuje cizí XML, srovnává uložený podklad s aktuálním
náhledem, takže odhalí doklad opravený zpětně po podání, doklad, který z období
zmizel (storno, přesun data plnění), i přesun daně do jiného státu.

Výsledkem je jeden ze čtyř závěrů:

- za období není nic archivováno,
- archivované podání nemá uložený podklad (porovnejte ručně proti staženému XML),
- dnešní náhled odpovídá,
- dnešní náhled se liší, pak zvažte opravné podání za původní období.

Rozdíly se vypisují v součtech, v řádcích podání i jako seznam dokladů změněných
po podání.

**Záložka Evidence § 110f.** Evidence vybraných plnění podle **§ 110f ZDPH**
(a čl. 63c prováděcího nařízení Rady (EU) č. 282/2011) se uchovává **10 let od
konce kalendářního roku, ve kterém bylo plnění uskutečněno**, a na žádost správce
daně se poskytne elektronicky.

Záznamy vznikají **při stažení OSS XML**, z téhož čtení dat jako podání, a jsou
**write-once**, nelze je změnit ani smazat. Hodnoty se kopírují, nedopočítávají se
z živých dokladů, protože evidence musí i za deset let ukazovat, co bylo podkladem
podání. Sloupec **Uchovat do** říká, kdy lhůta končí. Data jde stáhnout tlačítky
**Export CSV** a **Export JSON**.

Záložka zároveň vypisuje sekci **Body čl. 63c, které aplikace doložit neumí**:
zálohy přijaté před uskutečněním plnění (nemají vazbu na konkrétní OSS řádek),
místo zahájení a ukončení přepravy u zboží a doklad o vrácení zboží (vrácení je
zachyceno opravným dokladem, ne důkazem o vrácení věci). Tyhle body si v případě
kontroly doložte jinak.

### 45.10.16 Na co si dát pozor

Tenhle oddíl shrnuje věci, které **nejsou vadou aplikace**, ale rozejdou se
s očekáváním, a některé musí uživatel opravit ručně.

#### Typ plnění u položek v kusech je odhad

Jednotka `ks` je záměrně **neutrální**, je to výchozí hodnota, takže o zboží ani
službě netvrdí nic. Když soubor jednotku nenese vůbec, dosadí se výchozí **„služba"**
a do podání jde typ plnění `S`. **Pro e-shop se zbožím je to špatně, patří tam `G`.**

Hláška se u dokladu objeví **jen jednou**, u první položky, i když se týká všech.
Je to záměrná deduplikace, aby dvacetipoložková faktura nevyrobila dvacet stejných
vět.

Dvě cesty, jak to napravit:

1. **hromadná úprava OSS** nad výběrem dokladů ([§ 45.6](#456-krok-za-krokem-hromadna-uprava-oss)),
2. **výchozí typ plnění na kartě odběratele**: nové doklady ho pak dostanou samy
   a ruční práce se neopakuje. Případně doplňte dodavateli CZ-NACE.

#### Dobropisy a jejich původní období

Import ani jiný automatický kanál **původní období opravy nedoplní**, v žádném
zdrojovém souboru není z čeho ho poznat. Dokud ho na položce nevyplníte, vykáže se
oprava do **běžného** čtvrtletí místo do toho, kam patří. Kolik takových dokladů
je, říká souhrn importu i náhled podání.

#### Haléřové rozdíly u množství větší než jedna

Jednotková cena bez DPH se vede na **šest desetinných míst** (například 0,2683 EUR
za kus zůstane přesně), částka řádku se zaokrouhlí na haléře. Rozdíl proti zdrojovému
systému proto vznikne jen tehdy, když zdrojový systém zaokrouhluje řádek jinak, třeba
až za součtem dokladu. Na jednom dokladu jde o haléře.

#### Země spotřeby se bere z odběratele, ne z měny

Doklad v **eurech** pro **slovenského** odběratele jde do **SK** se slovenskou
sazbou, ne do nějaké „eurozóny". Rozhoduje země odběratele **na konkrétním
dokladu**, ne měna a ne uložená karta klienta.

To druhé má praktický důvod: odběratel bez IČO i DIČ (tedy každý spotřebitel) se
páruje podle shody jména, takže při tisících spotřebitelů může jeden Jan Novák
skončit na kartě jiného Jana Nováka. Daňově to neškodí, zařazení bere zemi
z dokladu, ale v adresáři to nepořádek udělá.

#### Nulová sazba pro odběratele s DIČ

Dodávka s **nulovou sazbou** odběrateli s platným DIČ do OSS nepatří, je to
osvobozené dodání do jiného členského státu. Zařadí se podle měrné jednotky buď
jako **dodání zboží** (ř. 20 přiznání, kód 0 v souhrnném hlášení), nebo jako
**poskytnutí služby** (ř. 21, kód 3). Protože jednotka `ks` nic netvrdí, u zboží
může vyjít služba. **Zkontrolujte to** a případně opravte,
[souhrnné hlášení](44_Souhrnne_hlaseni.md) se řídí toutéž klasifikací.

#### Historické doklady z doby před nastavením OSS

Doklady, které do systému natekly dřív, než byl OSS správně nastavený, mohou mít
příznak OSS prázdný a jejich zahraniční daň může být vykázaná v českém přiznání.
Než podáte přiznání za období, do kterého takový import spadl, projděte zahraniční
doklady v tom období a ověřte, že v přiznání k DPH nejsou na ř. 1 a 2 (patří jen
na ř. 24). Filtr **Místo plnění (OSS)** a hromadná úprava jsou na to ta správná
dvojice.

#### Náhled je poslední kontrolní bod

Náhled OSS podání je krátký (řádek na kombinaci **stát × sazba**) a je to
**poslední místo, kde se chyba dá chytit** dřív, než XML odejde na portál.
Než ho stáhnete, projděte varování, ověřte počet řádků k ručnímu posouzení
a porovnejte součty s tím, co čekáte.

## 45.11 Související kapitoly

- [Souhrnné hlášení](44_Souhrnne_hlaseni.md)
- [Výkazy DPH](41_Vykazy_DPH.md)
- [Kniha DPH](42_Kniha_DPH.md)
- [Faktury](14_Faktury.md)
- [Pravidelná fakturace](17_Pravidelne_fakturace.md)
- [Importy](21_Importy.md)
- [Exporty](20_Exporty.md)
- [Nastavení](96_Nastaveni.md)
- [Účetní osnova](66_Ucetni_osnova.md)
- [Archiv podání a rekonciliace](49_Archiv_podani_a_rekonciliace.md)
- [Fakturujeme](40_Fakturujeme.md)
