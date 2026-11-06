# 110. Přechod z ABRA Flexi

> Návod, jak převést účetnictví z ABRA Flexi do MyÚčta: od přípravy přístupu
> ve starém programu přes připojení a převod vybraných let až po kontrolu
> převzatých dat. Pro účetní a správce, kteří přecházejí z ABRA Flexi.

**Cesta: `Systém → Přechod z jiných účetních systémů → ABRA Flexi`**

## 110.1 Kdy to potřebujete

- Přecházíte s firmou z ABRA Flexi do MyÚčta a chcete převzít faktury, banku,
  pokladnu, účetní osnovu a původní účetní deník.
- Po prvním převodu chcete načíst nová data z vybraných let.
- Chcete doplnit produkty, ceny a aktuální stav skladu.
- Převod skončil upozorněním nebo chybou a potřebujete vědět, co dál.

## 110.2 Než začnete

1. **Cílová firma.** V MyÚčtu zvolte firmu, do které se bude převádět. Musí
   vést podvojné účetnictví a IČO musí odpovídat zdrojové firmě v ABRA Flexi.
   Cílové účetní období nesmí být uzavřené a datum počátečního stavu nesmí
   spadat do uzamčeného období. Převod účetnictví je podporován jen pro celé
   vybrané období vedené podle vyhlášky 500. Pokud do něj zasahuje rámec 504,
   převod se odmítne před změnou dokladů, nastavení nebo mapování importu.
   Rozhoduje historický rámec vybraného období, nikoli dnešní nastavení firmy.
   Převod nebyl ověřen pro neziskové organizace a SVJ. Je-li cílová firma
   podle právní formy nezisková organizace nebo SVJ, nebo vedla-li někdy
   účetnictví podle vyhlášky 504, ukáže průvodce výrazné upozornění. Před
   převodem pak kontaktujte podporu.
2. **Oprávnění.** Stránka vyžaduje oprávnění pro zápis importů (`utilities.import`).
3. **Přístup do ABRA Flexi** (co připravíte ve starém programu):
   - HTTPS adresu konkrétní účetní firmy z webového rozhraní ABRA Flexi.
     Adresa končí `/c/nazev_firmy` nebo `/flexi/nazev_firmy`, bez názvu
     evidence a dalších parametrů.
   - Uživatele ABRA Flexi s přístupem k API a právem číst účetní data této
     firmy. Doporučujeme samostatného uživatele jen pro čtení.
4. **Rozhodnutí o rozsahu.** Nejdřív se převádí účetnictví. Produkty a stav
   skladu se načítají samostatně až po něm.

> [!TIP]
> Převod je určen pro menší množství dat. Export dat se mezi verzemi i
> instalacemi liší. Na stránce `Systém → Přechod z jiných účetních systémů`
> je odkaz na podporu, která převod za poplatek provede nebo upraví na míru.
> Převzatá data si ověřte vždy.

## 110.3 Krok za krokem: připojení k ABRA Flexi

1. Otevřete `Systém → Přechod z jiných účetních systémů` a u dlaždice
   **ABRA Flexi** klikněte na **Otevřít průvodce**. Nahoře zkontrolujte cílovou firmu.
2. V části **Připojení** vyplňte **HTTPS adresa firmy v ABRA Flexi**,
   **Uživatelské jméno** a **Heslo**.
3. Klikněte na **Ověřit a uložit připojení**. Aplikace ověří přístup, uloží
   připojení pro vybranou firmu a načte dostupné účetní roky.

**Jak poznáte, že je hotovo:** Stránka ukazuje **Připojení nastaveno** a
v části **Roky prvního převodu** je seznam účetních let. Přístupové údaje se
po odeslání vymažou z formuláře a uložené hodnoty se znovu nezobrazují.

Připojení změníte tlačítkem **Změnit připojení**. Tlačítkem **Smazat
připojení** ho odstraníte, ale jen dokud nebyla převedena jakákoli data.

## 110.4 Krok za krokem: první převod

1. V části **Roky prvního převodu** zvolte účetní roky. Předvolený je aktuální
   a předchozí rok, pokud jsou ve zdroji dostupné. Seznam obnovíte tlačítkem
   **Načíst dostupné roky**.
2. Zkontrolujte blok **Rozsah převodu**: **Účetní data** jsou zapnutá vždy.
3. Klikněte na **Spustit převod**.
4. Převod běží na pozadí. Stránku můžete zavřít a později se vrátit. Průběh
   a počty vytvořených, přeskočených a chybných záznamů vidíte na stránce.
   Při potížích s načítáním průběhu klikněte na **Obnovit stav**.
5. Chcete-li převod zastavit, klikněte na **Zastavit import**.
   Nedokončený převod se vrátí zpět.

**Jak poznáte, že je hotovo:** V části **Výsledek převodu** vidíte hlášku
**Převod dokončen.** nebo **Převod dokončen s upozorněními.** a počty
**Vytvořeno / přeskočeno / chyby**. Doklady se v cílové firmě zobrazí až po
úplném načtení a účetních kontrolách.

## 110.5 Krok za krokem: kontrola převzetí

1. V části **Historie a protokol převodu** zvolte v poli **Běh převodu**
   poslední převod.
2. Zkontrolujte, že protokol hlásí **Účetní kontrola souhlasí**. Při
   **Účetní kontrola nesouhlasí** a při blokujících problémech převod další
   načtení neumožní, dokud důvody nevyřešíte.
3. Projděte **Upozornění k převodu**. Každé upozornění říká, co ověřit
   (například doklad zůstal konceptem kvůli nejasnému členění DPH).
4. V `Účetnictví` porovnejte předvahu a obraty s tím, co vidíte v ABRA Flexi.
5. Ověřte doklady označené ke kontrole, zejména cizoměnové a opravné.
6. Před podáním DPH ověřte přiznání se zdrojem a uzavření období.

**Jak poznáte, že je hotovo:** Protokol neobsahuje nevyřešená upozornění,
účetní kontrola souhlasí a předvaha odpovídá zdroji. Po úspěšné kontrole
účetnictví se aktivace firmy označí jako dokončená.

## 110.6 Krok za krokem: načtení nových dat

Po prvním převodu se výběr let skryje.

1. Otevřete stránku ABRA Flexi ve stejné firmě.
2. Klikněte na **Načíst nová data**. Načtou se nová data z vybraných let a
   zachovají se vazby na již převedené záznamy.
3. Zkontrolujte protokol podle [§ 110.5](#1105-krok-za-krokem-kontrola-prevzeti).

**Jak poznáte, že je hotovo:** U **Poslední načtení** je nové datum a výsledek
je **Převod dokončen.** Již převzaté záznamy se neduplikují. Změny původních
záznamů vyžadují vaši kontrolu.

## 110.7 Krok za krokem: produkty a stav skladu

Použijte až po dokončení účetního převodu.

1. V bloku **Rozsah převodu** je volitelný doplněk **Ceník a produkty**.
2. Klikněte na **Převést produkty a stav skladu**. Při dalším použití se
   tlačítko jmenuje **Načíst nové produkty a stav skladu**.
3. Počkejte na dokončení. Již uložené karty další běh přeskočí.

**Jak poznáte, že je hotovo:** Pod blokem je **Ceník naposledy načten** s datem
a v protokolu nejsou upozornění vyžadující kontrolu.

## 110.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| IČO zdrojové a cílové firmy se liší | Připojení míří na jinou firmu | Opravte adresu připojení, nebo vyberte správnou cílovou firmu |
| Cílová firma musí vést podvojné účetnictví | Cíl vede jiný typ účetnictví | Zvolte firmu s podvojným účetnictvím |
| Cílové účetní období je uzavřené, nebo datum je v uzamčeném období | Převod nesmí zapisovat do uzavřeného období | Období otevřete, nebo odemkněte datum, a převod spusťte znovu |
| Nejsou k dispozici žádné roky | Zdroj roky nevrátil | Klikněte na **Načíst dostupné roky** |
| **Převod se nezdařil.** | Chyba při čtení nebo kontrolách | Zkontrolujte výsledek a protokol, příčinu odstraňte a převod zopakujte |
| **Převod byl zrušen.** | Zastavili jste ho | Již převedené záznamy zůstávají; spusťte převod znovu |
| Doklad zůstal konceptem | Rozporné nebo neznámé členění DPH | Ověřte doklad podle důvodu v protokolu a doklad dokončete ručně |
| Převod se zastavil kvůli pokladně | Zdrojové pohyby patří do více pokladen, nebo pokladní doklad obsahuje DPH | Řešení je ruční, viz [§ 110.9](#1109-podrobnosti-a-pravidla) |
| Převod se zastavil kvůli limitu požadavků | Server omezil počet požadavků nebo je vyčerpán denní rozpočet | Počkejte na další den, nebo správce navýší limit |
| Chyba při načítání průběhu | Dočasný problém spojení | Klikněte na **Obnovit stav** |
| Skladová příjemka se nevytvořila | Zdroj má víc skladů, nebo cílový sklad už obsahuje zásobu | Viz pravidla produktů a skladu v [§ 110.9](#1109-podrobnosti-a-pravidla) |

## 110.9 Podrobnosti a pravidla

### 110.9.1 OSS a více účetních období

Účetní import čte také časově platné nastavení OSS ve zdrojové firmě. Pokud je
pro převáděný rok aktivní režim EU, zapne jej u cílové firmy. Nastavení se znovu
ověří při každém načtení nových dat. Ostatní režimy OSS vyžadují ruční kontrolu.
Upozornění na více účetních období se zobrazuje jen pro skutečně převáděné roky.
U účetního období bez roku v kódu se pro výběr používá rok jeho počátku; to
platí i pro období, které končí v následujícím kalendářním roce.

### 110.9.2 Faktury a DPH

Faktury zaúčtované v ABRA Flexi se převedou jako vystavené nebo zaúčtované
doklady a propojí se s převzatým deníkem. Totéž platí pro prodejky a pro závazky
s DPH, které ABRA vede mimo evidence vydaných a přijatých faktur. Import jejich
účetní zápisy nevytváří podruhé.

Ostatní pohledávky a závazky bez DPH (evidence `pohledavka` a `zavazek`, třeba
splátky úvěru, závazky ke společníkům nebo zaměstnancům) se nepřevádějí jako
faktury, ale do `Účetnictví → Ostatní pohledávky a závazky`. Doklad s jedním
zápisem v deníku ho převezme i s kontací. Doklad o více řádcích (například
splátka rozdělená na jistinu a úrok) nebo se zápisem, který nemění zůstatky,
se uloží jako potvrzený bez vlastního zaúčtování, protože jeho zápisy už nese
převzatý deník. Úhrady z banky a pokladny se k nim spárují. Co ABRA vede jako
uhrazené jinak (zápočtem, interním dokladem nebo platbou v nepřeváděném roce),
převezme se jako úhrada mimo banku a pokladnu, takže doklad nezůstane otevřený.
Předpisy mezd, odvodů a DPH (účty 331, 336, 341, 342 a 343) zůstávají jen
v deníku, stejně jako pohledávky s DPH a doklady v cizí měně nebo stornované;
protokol na ně upozorní. Zahraniční DPH zůstává v částce faktury, ale nevstupuje
do české daně v přiznání. Doklad s rozporným nebo neznámým členěním DPH,
například s tuzemským nárokem na odpočet a nulovou daní, zůstane k ověření jako
koncept; důvod se zobrazí v protokolu převodu.

Číslo vydané faktury se přebírá z čísla dokladu v ABRA Flexi, včetně lomítek;
variabilní symbol platby se ukládá zvlášť. Pokud je zdrojové číslo příliš dlouhé
nebo koliduje s jiným dokladem, protokol vyžádá kontrolu před podáním
kontrolního hlášení.

Převod čte také aktuální podklady DPH ve vybraných obdobích. U jednoznačně
spárovaných položek z nich převezme vypočtenou daň v Kč odděleně od částky
faktury, včetně nulového samovyměření. U opravných dokladů zachová zdrojové
daňové znaménko a stornované doklady vyřadí z evidence DPH. Nejednoznačné
podklady nepřepisuje automaticky a uvede upozornění ke kontrole.

Převzaté faktury s jednoznačným tuzemským členěním DPH se uloží jako vystavené
nebo zaúčtované podle zdroje. Převod přitom nespouští automatické účtování ani
skladový výdej; původní zápisy se přenášejí samostatně v deníku a v detailu
faktury na ně vede odkaz. Doklady s nevyjasněným daňovým členěním zůstávají
koncepty ke kontrole a nevstupují do evidence DPH.

### 110.9.3 Deník a kontroly

Nulové řádky zdrojového deníku nemají účetní obrat a převod je vynechá; jejich
počet uvede v protokolu. Účetní pohyby se kontrolují proti původním součtům.
Změny již převzatých záznamů se automaticky nepřepisují a vyžadují kontrolu.

Když ABRA změní počáteční stavy vybraného roku, synchronizace ověří původní
zápisy a dorovná rozdíly novými účetními zápisy. Původní zápisy zůstanou
dohledatelné. Úprava počátečních stavů při synchronizaci se neprovede v uzavřeném
účetním období ani v období s uzamčeným datem. Bankovní pohyby bez odpovídajícího
zápisu ve zdrojovém deníku zůstanou označené k ruční kontrole a nevstoupí do
automatického účtování.

### 110.9.4 Úhrady

Úhrada dokladu z jiného účetního roku se převede až při načtení roku bankovního
nebo pokladního pohybu. Stornované pohyby se nepřebírají. Pokud ABRA stornuje
již převedený pohyb, synchronizace jej označí ke kontrole a nepřepíše původní
záznam bez zásahu účetní.

Pokud není zapnuté Changes API, nové vazby úhrad se načítají podle jejich ID.
Změny nebo smazání starších vazeb nelze tímto způsobem spolehlivě zjistit;
v takovém případě je nutná ruční kontrola úhrad.

Úhrada se propojí pouze při shodě měny vazby, dokladu a peněžního pohybu.
Pokladní pohyb rozdělený jen částečně na doklad zůstane ke kontrole, aby se
celá částka chybně nevydávala za úhradu. Stornovaný cílový doklad synchronizace
znovu neoznačí jako uhrazený.

Pokud je uhrazený doklad z předchozího roku převzat kvůli letošní platbě,
zůstane zachován jeho celkový uhrazený stav ze zdroje. Platby z vybraného roku
se připojí samostatně a nezapočítají se podruhé.

### 110.9.5 Pokladna a banka

Pokladní doklad se zdrojovou DPH převod zastaví, protože jeho daňový rozpad
zatím nelze bezpečně zachovat. Pokud zdrojové pohyby patří do více pokladen,
převod se zastaví před jejich sloučením do jedné cílové pokladny.

U cizoměnových kontací banky a pokladny se vedle korunové částky uchovává také
původní měna, částka a kurz pro pozdější kurzovou uzávěrku. Nejednoznačný převod
mezi dvěma peněžními účty vyžaduje ruční kontrolu. Pokud cizoměnový počáteční
stav nemá samostatný korunový protějšek, korunová předvaha zůstává zachovaná,
ale tento cizoměnový stav se do kurzové uzávěrky automaticky nedoplní.

Zdrojové bankovní účty se evidují také v `Peníze → Bankovní účty` na záložce **Kontace účtů**. Analytiky
221 se přebírají ze zdroje a původní zápisy deníku na nich zůstávají. Účty bez
použitelného bankovního čísla, například virtuální platební účty, mají vlastní
analytiku a vazbu na účet v měnách, ale nelze je vydávat za český bankovní účet.
Pokud zdroj používá jinou syntetiku než 221, průvodce upozorní na nutnost
kontroly historického zaúčtování. Úvěrové účty, které ABRA vede jako bankovní
účet na účtu 231, 232 nebo 461, se do banky nepřevádějí: jejich zůstatek je
nesplacená jistina, ne peníze firmy. Splátky i jistina zůstávají v převzatém
deníku. Rekonstruovaný soubor GPC lze stáhnout jen
u výpisu s ověřenými stavy a platným číslem účtu i kódem banky. Kód banky se
čte také ze zdrojového pole `smerKod`. U virtuálních účtů a neúplné bankovní
identifikace se GPC nenabízí. Hodnoty přesahující pevnou šířku polí GPC nelze
zkrátit bez ztráty informace; jejich export se odmítne.

### 110.9.6 Produkty a sklad

Po dokončení účetního převodu lze načíst produkty, jejich prodejní ceny v CZK,
EUR, GBP a USD a kladné skladové zůstatky včetně ocenění. Nulové zůstatky
nevytvářejí příjemky. Záporné množství nebo ocenění se nepřevede a vyžaduje
kontrolu. Opakované načtení již převzaté skladové karty neduplikuje; změny
zdrojového stavu se automaticky nepřepisují. Historické skladové pohyby se
zatím nepřevádějí.

Přepočet cizoměnových cen bez DPH se řídí typem ceny v ceníku ABRA Flexi,
nikoli měnou. Pokud typ ceny chybí, cizoměnová cena zůstane ke kontrole.
Před vytvořením skladových příjemek se ověří, že zdroj obsahuje jediný sklad;
více skladů nelze bezpečně sloučit do jednoho cílového skladu. Převod před
každou příjemkou porovná cílový stav s již převedenými kartami. Pokud sklad
obsahuje další zásobu, příjemku nevytvoří, aby stav nezdvojnásobil.

### 110.9.7 Průběh, limity a bezpečnost

Převod běží na pozadí a při zrušení nebo chybě nevytvoří částečně převedené
účetnictví. Změna cílové firmy vymaže rozpracovaný formulář a načte stav
připojení této firmy.

Převod čte data postupně po větších dávkách. Požadavky všech převodů na stejný
server se započítávají do místního rozpočtu 1 000 požadavků za den. Tento rozpočet
nezahrnuje ostatní aplikace připojené k Flexi a není údajem o zbývající licenční
kvótě. Správce může po ověření sjednaného limitu nastavit proměnnou prostředí
`MYINVOICE_ABRA_DAILY_REQUEST_LIMIT` pro rozsáhlejší převod (1 až 50 000
požadavků za den); výchozí hodnota zůstává 1 000. Limit se sdílí mezi převody
na stejném serveru. Při omezení požadavků serverem se úloha zastaví a další
spuštění musí počkat.

Dokončené bloky se ukládají odděleně pro firmu a verzi připojení. Při opakování
převodu aplikace znovu ověří jejich identity a časy změny a plné záznamy načte
jen tam, kde uložený blok chybí nebo se změnil. Zrušení nebo chyba nevytvoří
částečně převedené účetnictví; dokončené čtené bloky mohou posloužit dalšímu běhu.

Přístupové údaje se ukládají šifrovaně k vybrané firmě a zdrojové API se pouze
čte.

## 110.10 Související kapitoly

- [Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)
- [Řešení problémů](999_Reseni_problemu.md)
