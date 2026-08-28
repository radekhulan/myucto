# Docházka a směny

## 60.1 Účel

Docházka a směny určují plánovaný a skutečně odpracovaný čas. Slouží jako podklad pro mzdu, příplatky, překážky a kontrolu fondu pracovní doby.

## 60.2 Předpoklady a oprávnění

Zaměstnanec musí mít aktivní vztah a přiřazený pracovní režim. Uživatel potřebuje mzdové oprávnění a schválený podklad docházky; samotná přítomnost záznamu nepotvrzuje jeho správnost.

## 60.3 Krokový postup

1. Otevřete **Mzdy → Docházka a směny** a zvolte měsíc.
2. Zkontrolujte pracovní kalendář, úvazek a naplánované směny.
3. Doplňte skutečně odpracovaný čas a údaje potřebné pro příplatky.
4. Porovnejte docházku s absencemi, svátky a změnami vztahu.
5. Vyřešte varování a schvalte měsíc; teprve schválením vzniknou zákonné
   příplatky a období je připravené pro mzdový běh.

## 60.4 Import docházky z CSV nebo XLSX

Tlačítko **Import** slouží pro docházkové systémy i vlastní tabulku. CSV a XLSX
používají stejnou datovou větu, stejnou kontrolu a stejný výsledek. Import je
dvoukrokový: **Zkontrolovat** nejdřív vytvoří náhled bez jediného zápisu a až
**Importovat platné řádky** uloží řádky označené v náhledu jako platné. Chybné
řádky se vypíšou česky s číslem řádku; neopravují se odhadem.

Povinné sloupce jsou:

- `employment_code` — označení pracovního vztahu z karty zaměstnance;
- `starts_at` a `ends_at` — začátek a konec včetně časového posunu, například
  `2026-10-05T08:00:00+02:00`;
- `timezone` — IANA časové pásmo, pro českou docházku obvykle `Europe/Prague`;
- `category` — `regular`, `overtime`, `night`, `weekend`, `holiday` nebo
  `difficult_environment`. **Pozor:** odpracovanou dobu tvoří jen `regular` a
  `overtime`; zbylé čtyři kategorie jsou **příznaky nad týmiž hodinami**, ne
  hodiny navíc. Noční směnu proto importujte jako řádek `regular` (nebo
  `overtime`) **a k němu** řádek `night` na stejné hodiny. Za jeden den nesmí
  být příznakových minut víc než odpracovaných;
- `external_id` — jedinečný identifikátor záznamu ve zdrojovém docházkovém
  systému.

Volitelně lze přidat `employment_id` pro přesné párování souběžných vztahů a
`break_minutes` pro délku přestávky v celých nezáporných minutách. Odkaz na
zdrojový dokument se nevyžaduje. Za správnost párovacího kódu a importovaných
hodnot odpovídá uživatel, který potvrdí náhled.

Stejné `external_id` u stejného pracovního vztahu se podruhé nezapíše. Opakované
odeslání totožného souboru vrátí původní výsledek importu. Lze tedy bezpečně
zopakovat požadavek po přerušení spojení, aniž vznikne dvojí docházka. Párování
vždy probíhá jen uvnitř právě zvolené firmy a import vyžaduje přihlášenou relaci
s oprávněním zapisovat docházku.

### 60.4.1 Bezpečnost a limity XLSX

Soubor smí mít nejvýše 5 MB, 10 000 datových řádků a 24 sloupců. U XLSX se
zpracuje pouze první list. Aplikace přijímá jen statické hodnoty: vzorec,
makro, vložený soubor, externí propojení nebo neobvykle rozbalený archiv odmítne
ještě před náhledem a nic nezapíše. Bezpečnostní kontrola omezuje také počet
částí archivu a jeho rozbalenou velikost, takže komprimovaný soubor nemůže
nekontrolovaně spotřebovat paměť serveru.

Import není závislý na tom, kolik zaměstnanců je právě načteno v přehledu.
Každý řádek se páruje přímo podle označení vztahu v dané firmě, takže stejný
postup lze použít pro deset i pět set zaměstnanců. Pro rozsáhlý soubor nejdřív
zkontrolujte souhrn platných, chybných a duplicitních řádků a teprve potom
potvrďte zápis.

## 60.5 Stavy

Období může být rozpracované, úplné nebo blokované nesouladem. Po převzetí do otevřeného běhu se změna projeví až novým výpočtem. Uzavřené období neupravujte bez opravy běhu.

## 60.6 Kontroly a bezpečnost

Kontrolujte fond, odpočinek, překryv směn, práci ve svátek, přesčas a návaznost na úvazek. Záznamy docházky jsou osobní údaje; exportujte je jen oprávněným osobám a v nezbytném rozsahu.

## 60.7 Časté chyby

- Plánovaná směna považovaná za skutečně odpracovanou dobu.
- Noční, víkendové nebo svátkové hodiny zapsané **místo** odpracované doby
  místo **k ní** — příznak pak chybí v odpracované době a příplatek nevznikne.
- Dvojí započtení hodin při překryvu směny a absence.
- Zadání hodin k jinému souběžnému vztahu.
- Oprava docházky bez přepočtu otevřeného běhu.

## 60.8 Návaznosti

Nepřítomnosti patří do [absencí](59_Absence_a_dovolena.md), jednorázové odměny do [rychlého vstupu](62_Rychly_mesicni_vstup.md) a výsledný čas do [mzdových běhů](63_Mzdove_behy.md).



## 60.9 Podrobný pracovní postup a kontroly

### 60.9.1 Limity práce přesčas

V **Mzdy → Docházka a směny** aplikace u každého pracovního vztahu hlídá limity
práce přesčas podle § 93 zákoníku práce a stav ukazuje přímo u zaměstnance:

- **8 hodin v jednotlivých týdnech** a **150 hodin v kalendářním roce** — meze
  přesčasu, který smí zaměstnavatel nařídit (§ 93 odst. 2). Týden se posuzuje
  jako pondělí až neděle bez ohledu na hranici měsíce.
- **Průměr 8 hodin týdně ve vyrovnávacím období** nejvýše 26 týdnů po sobě
  jdoucích (§ 93 odst. 4). Poměřuje se celkový přesčas, tedy i ten dohodnutý.
  Na začátku pracovního poměru je okno kratší a strop s ním klesá.

Podkladem je evidence odpracovaného přesčasu v docházce, ne vyplacená částka.
Kromě překročení se hlásí i blížící se vyčerpání ročního limitu, aby se dalo
zasáhnout včas.

Nad nařízený rozsah lze práci přesčas požadovat jen na základě dohody se
zaměstnancem (§ 93 odst. 3). Tu zaznamenáš tlačítkem **Souhlas s přesčasem**
včetně doby platnosti a označení dokumentu. Přesčas ve dnech krytých dohodou se
posuzuje jako dohodnutý a limity nařízeného přesčasu se na něj nevztahují; bez
evidované dohody se proti nim poměřuje všechen přesčas.

> [!NOTE]
> Překročení limitu je vada na straně zaměstnavatele, ne chyba výpočtu.
> Odpracovaný přesčas se podle § 114 platí i tehdy, když byl nařízen nad
> zákonný rozsah, proto se nález eviduje jako **upozornění** u revize mzdového
> běhu a schválení ani výplatu nezastaví.

### 60.9.2 Náhradní volno za přesčas

Náhradní volno se eviduje na dvou místech, protože každý zápis odpovídá na
jinou otázku a váže se k jinému dni:

- **Absence druhu „Náhradní volno za přesčas"** v agendě Absence a dovolená je
  záznam o **dni čerpání** — vstup do docházky a mzdy. Za dobu čerpání mzda
  nepřísluší (§ 114 odst. 3), protože přesčas se už proplatil a volnem se
  nahrazuje jen příplatek.
- **Tlačítko Náhradní volno** v Docházce a směnách zapisuje, **ke kterému dni
  přesčasu** se volno vztahuje. Podle toho se přesčas vyjímá z vyrovnávacího
  období (§ 93 odst. 5); z limitů nařízeného přesčasu podle odst. 2 se
  neodečítá, tam zákon výjimku nemá.

Odvodit jedno z druhého nelze: absence den přesčasu nenese a jeden den čerpání
může vyrovnávat přesčas z několika dnů. U vztahu proto přibude upozornění,
když je za měsíc zapsaná jen jedna strana — jednostranný zápis by jinak zůstal
tichý a projevil by se buď chybějícím vynětím z vyrovnávacího období, nebo
neodpracovaným dnem bez důvodu. Zápis bez data poskytnutí volna se do měsíce
nezařazuje a hlásí se zvlášť.

### 60.9.3 Zákonné příplatky ke mzdě (§ 114 až § 118)

Docházka je **jediný** zdroj zákonných příplatků. Kolik hodin bylo odpracováno
v noci, o víkendu, ve svátek nebo ve ztíženém prostředí, se nikde jinde
nezadává — mzdovým vstupem ani rychlým měsíčním vstupem tyto příplatky založit
nelze.

> [!IMPORTANT]
> Firma, která docházku nevede, nedostane z aplikace příplatky podle § 116,
> § 117 ani § 118 vůbec. Chcete-li je vyplácet, musíte odpracovanou dobu
> a její příznaky evidovat zde.

| Ustanovení | Příplatek | Mzdová složka |
|---|---|---|
| § 114 | Příplatek za práci přesčas | `PRIPLATEK_PRESCAS` |
| § 115 | Příplatek za práci ve svátek | `PRIPLATEK_SVATEK` |
| § 116 | Příplatek za noční práci | `PRIPLATEK_NOCNI` |
| § 117 | Příplatek za práci ve ztíženém pracovním prostředí | `PRIPLATEK_ZTIZENE_PROSTREDI` |
| § 118 | Příplatek za práci v sobotu a v neděli | `PRIPLATEK_VIKEND` |

#### Za jednu hodinu může náležet víc příplatků

Kategorie `night`, `weekend`, `holiday` a `difficult_environment` jsou
**příznaky nad týmiž hodinami**, ne hodiny navíc. Do odpracované doby se proto
nesčítají — tu tvoří jen `regular` a `overtime`. Zároveň z toho plyne, že se
příplatky **sčítají vedle sebe a nevylučují se**:

- přesčas odpracovaný v noci o víkendu nese **tři** příplatky současně;
- práce ve svátek, který padne na sobotu, nese **dva** (§ 115 i § 118).

Hodinu odpracovanou v noci proto zapište jako běžnou (nebo přesčasovou)
odpracovanou dobu **a k ní** příznak noční práce. Aplikace hlídá, že
příznakových minut za den není víc než minut odpracovaných.

#### Z čeho se počítají

| Ustanovení | Zákonná sazba | Základ |
|---|---|---|
| § 114 přesčas | nejméně 25 % | průměrný výdělek |
| § 115 svátek | nejméně 100 % | průměrný výdělek |
| § 116 noční práce | nejméně 10 % | průměrný výdělek |
| § 117 ztížené prostředí | nejméně 10 % za každý ztěžující vliv | **základní sazba minimální mzdy** |
| § 118 sobota a neděle | nejméně 10 % | průměrný výdělek |

Za noční práci se považuje doba mezi **22. a 6. hodinou**. Sazba § 117 se
nepřepočítává na kratší úvazek: příplatek je kompenzace vlivu prostředí, ne
odměna za odpracovaný čas.

Sazby, základy i noční okno bere aplikace z legislativní sady účinné pro dané
období, takže je nepřepisujete ručně. Sjednat lze **vyšší** sazbu vždy;
**nižší** jen u noční práce (§ 116) a u práce v sobotu a neděli (§ 118),
protože jen tato dvě ustanovení dovolují sjednat jinou minimální výši. U § 114,
§ 115 a § 117 je zákonné „nejméně" tvrdá podlaha a aplikace nižší sjednanou
sazbu vůbec neuloží.

#### Náhradní volno místo příplatku

Zákon ho zná jen u přesčasu (§ 114) a u svátku (§ 115); u ostatních tří
příplatků žádnou takovou alternativu nemá.

- **Přesčas** — náhradní volno zapsané tlačítkem **Náhradní volno** se odečítá
  přesně, podle **dne přesčasu**. Dokud lhůta běží, příplatek se nevyplácí.
  Není-li volno poskytnuto v dohodnuté době, nejpozději **do konce třetího
  kalendářního měsíce** po měsíci přesčasu, **nárok na příplatek podle § 114
  odst. 2 obživne** a aplikace ho dopočítá. Totéž platí, když je volno zapsané
  bez data poskytnutí — takový zápis lhůtu nestaví. Je-li mzda sjednána
  s přihlédnutím k práci přesčas (§ 114 odst. 3), nepřísluší příplatek ani
  náhradní volno.
- **Svátek** — zákon má ve **výchozím stavu náhradní volno**; příplatek 100 %
  jen tehdy, je-li tak dohodnuto. Evidenci „za tento konkrétní svátek bylo
  poskytnuto volno" ale aplikace nevede — záznam o náhradním volnu nese den
  čerpání, ne den svátku. Bez sjednané zásady se proto výpočet **bezpečně
  zastaví** a nevyplatí nic. Je to záměr: tiše vyplacený příplatek bez dohody
  by byl výdaj bez právního titulu a nevyčerpané volno by zůstalo viset.
  Na rozdíl od přesčasu nárok po marném uplynutí lhůty **neobživne** —
  obdobnou větu jako § 114 odst. 2 zákon u svátku nemá.

#### Kdy příplatky vzniknou a co je zastaví

Příplatky se do mzdy promítnou **ve chvíli, kdy schválíte měsíc docházky**, ve
stejné transakci. Není to samostatné tlačítko, na které by šlo zapomenout —
u zákonného nároku by zapomenutí znamenalo nedoplatek, který nikdo neuvidí.

**Chybějící podklad proto shodí i schválení měsíce** a aplikace uvede konkrétní
důvod. Nejčastější jsou:

- **není sjednaná zásada pro svátek** — nelze určit, zda náleží náhradní volno,
  nebo příplatek;
- **není doložený počet ztěžujících vlivů** pro § 117 — bez něj se příplatek
  nepočítá, protože počet vlivů plyne z nařízení vlády a konkrétního pracoviště;
- **chybí schválený průměrný výdělek** pro rozhodné čtvrtletí (u § 117 není
  potřeba, ten se počítá z minimální mzdy);
- **příznakových minut je za den víc než odpracovaných**;
- **noční nebo víkendový příznak leží mimo noční dobu, resp. mimo sobotu
  a neděli**, nebo svátkový příznak nepadá na svátek;
- **týž přesčas je zadaný i v rychlém měsíčním vstupu** — jeden přesčas nelze
  vykázat dvakrát; rozhodněte se pro jednu z obou cest.

Měsíc s prací ve svátek bez sjednané zásady nebo s prací ve ztíženém prostředí
bez počtu vlivů prostě není schválitelná evidence — nedá se z ní spočítat mzda.

Docházku schvalte **dřív, než u mzdového běhu uzamknete vstupy**. Po uzamčení
už se příplatky do běhu nedostanou a schválení měsíce skončí chybou.

Znovuotevření měsíce už zapsané příplatky **neruší**. Opětovné schválení
dopočítá jen **rozdíl** proti tomu, co už je zapsáno; snížení vznikne jako
opravný záporný vstup, ne přepsáním původního. Opakované schválení beze změny
nezapíše nic.

> [!WARNING]
> **Zásadu pro svátek podle § 115 ani počet ztěžujících vlivů podle § 117 zatím
> nelze v aplikaci zadat** — obrazovka pro ně chybí, stejně jako pro sjednání
> nižší sazby u § 116 a § 118. Dokud ji aplikace nedostane, měsíc s prací ve
> svátek nebo ve ztíženém prostředí schválit nepůjde a tyto dva příplatky je
> nutné vypořádat mimo aplikaci. O nastavení sjednané zásady požádejte
> provozovatele. Příplatky za přesčas, noční práci a víkend fungují bez tohoto
> nastavení, protože pro ně platí zákonná sazba a zákonný výchozí režim.
