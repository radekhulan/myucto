# Docházka a směny

## Účel

Docházka a směny určují plánovaný a skutečně odpracovaný čas. Slouží jako podklad pro mzdu, příplatky, překážky a kontrolu fondu pracovní doby.

## Předpoklady a oprávnění

Zaměstnanec musí mít aktivní vztah a přiřazený pracovní režim. Uživatel potřebuje mzdové oprávnění a schválený podklad docházky; samotná přítomnost záznamu nepotvrzuje jeho správnost.

## Krokový postup

1. Otevřete **Mzdy → Docházka a směny** a zvolte měsíc.
2. Zkontrolujte pracovní kalendář, úvazek a naplánované směny.
3. Doplňte skutečně odpracovaný čas a údaje potřebné pro příplatky.
4. Porovnejte docházku s absencemi, svátky a změnami vztahu.
5. Vyřešte varování a teprve potom předejte období do mzdového běhu.

## Import docházky z CSV nebo XLSX

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
  `difficult_environment`;
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

### Bezpečnost a limity XLSX

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

## Stavy

Období může být rozpracované, úplné nebo blokované nesouladem. Po převzetí do otevřeného běhu se změna projeví až novým výpočtem. Uzavřené období neupravujte bez opravy běhu.

## Kontroly a bezpečnost

Kontrolujte fond, odpočinek, překryv směn, práci ve svátek, přesčas a návaznost na úvazek. Záznamy docházky jsou osobní údaje; exportujte je jen oprávněným osobám a v nezbytném rozsahu.

## Časté chyby

- Plánovaná směna považovaná za skutečně odpracovanou dobu.
- Dvojí započtení hodin při překryvu směny a absence.
- Zadání hodin k jinému souběžnému vztahu.
- Oprava docházky bez přepočtu otevřeného běhu.

## Návaznosti

Nepřítomnosti patří do [absencí](59_Absence_a_dovolena.md), jednorázové odměny do [rychlého vstupu](62_Rychly_mesicni_vstup.md) a výsledný čas do [mzdových běhů](63_Mzdove_behy.md).



## Podrobný pracovní postup a kontroly

### Limity práce přesčas

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

### Náhradní volno za přesčas

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
