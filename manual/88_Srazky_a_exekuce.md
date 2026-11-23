# 88. Srážky a exekuce

> Návod, jak v MyÚčtu vést exekuce a jiné nucené srážky ze mzdy, oddlužení
> a insolvenci, jak sražené peníze odeslat příjemci a co udělat, když
> povinnému skončí pracovní poměr. Pro mzdové účetní. Dobrovolné srážky jsou
> v kapitole [Dohody o srážkách](87_Dohody_o_srazkach.md).

## 88.1 Kdy to potřebujete

<!-- cols: 34 40 26 -->
| Situace | Co udělat | Kde v aplikaci |
|---|---|---|
| Přišel exekuční příkaz nebo usnesení o srážkách ze mzdy | Založit případ, pohledávky, ověřit podklady, zahájit srážení | `Mzdy → Srážky a exekuce`, [§ 88.3](#883-krok-za-krokem-nova-exekuce) |
| Máte ověřený účet exekutora nebo oprávněného | Povolit odesílání sražených částek | detail případu, [§ 88.4](#884-krok-za-krokem-odesilani-srazenych-castek-prijemci) |
| Každý měsíc před schválením mzdy | Zkontrolovat měsíční podklady srážek | detail případu, [§ 88.5](#885-krok-za-krokem-mesicni-kontrola) |
| Zahájené insolvenční řízení, schválené oddlužení | Zapsat režim oddlužení za měsíc | `Mzdy → Oddlužení`, [§ 88.6](#886-krok-za-krokem-oddluzeni-a-insolvence) |
| Povinnému skončil pracovní poměr | Do týdne oznámit soudu nebo exekutorovi, ukončit případ u vás | detail případu, [§ 88.7](#887-krok-za-krokem-skonceni-pracovniho-pomeru-povinneho) |
| Schválené oddlužení nebo konkurs po deponování | Vydat depozit insolvenčnímu správci | detail případu, [§ 88.8](#888-krok-za-krokem-vydani-depozita-insolvencnimu-spravci) |
| Exekutor poslal dotaz XMLZAM do datové schránky | Připravit odpověď o příjmech a srážkách | `Mzdy → Součinnost exekutorům`, [§ 88.9](#889-krok-za-krokem-dotaz-exekutora-xmlzam) |

## 88.2 Než začnete

1. **Oprávnění.** Potřebujete oprávnění k exekucím (`payroll.enforcement`),
   k oddlužení navíc oprávnění k insolvenci (`payroll.insolvency`) a pro
   součinnost exekutorům vlastní oprávnění (`payroll.enforcement.cooperation`).
   K výběru rozhodnutí potřebujete i právo číst Dokumenty.
2. **Rozhodnutí v Dokumentech.** Exekuční příkaz, usnesení nebo rozhodnutí
   insolvenčního soudu nahrajte do firemních Dokumentů. Zahájení srážení,
   odklad, obnovení i zastavení se o vybraný dokument opírají.
3. **Účet příjemce.** Účet exekutora, oprávněného nebo insolvenčního správce
   založte jako ověřený účet typu ostatní příjemce v `Mzdy → Nastavení mezd`,
   záložce **Účty institucí** (viz [Nastavení mezd](90_Nastaveni_mezd.md#905-krok-za-krokem-ucty-instituci)).
4. **Podklady.** Připravte datum doručení prvnímu plátci, druh a výši
   pohledávek, příjemce a stav řízení. Nejasný případ posuďte s odborníkem.

## 88.3 Krok za krokem: nová exekuce

1. Otevřete `Mzdy → Srážky a exekuce` a klikněte na **Nová exekuce nebo
   dohoda**.
2. Vyberte zaměstnance a **Právní titul** (zákonná srážka nebo exekuce)
   a případ uložte. Vznikne ve stavu **Přijato** (čeká na ověření).
3. V detailu sledujte blok **Doporučený další krok**. Jeho tlačítko vede vždy
   na část, kterou je potřeba doplnit.
4. Klikněte na **Přidat pohledávku**. Vyplňte **Kategorie pohledávky**,
   **Původní výše pohledávky (Kč)**, **Doručeno prvnímu plátci** a **Rozhodnutí
   vydáno**. Patří-li pohledávka ke stejnému příkazu jako jiná, vyberte ji
   v **Stejný exekuční příkaz jako**.
5. V bloku **Strany případu a skladba pohledávky** uložte soud nebo exekutora,
   oprávněného a rozpad pohledávky na jistinu, úroky, náklady a výživné.
6. Zkontrolujte **Vyživované osoby**. U manžela nebo partnera odpovězte, zda je
   doložen důchod (viz [§ 88.12.3](#88123-manzel-a-nezabavitelna-castka-dolozeny-duchod)).
7. V **Ověření podkladů** potvrďte právní titul, doručení, existenci splatné
   pohledávky a pořadí.
8. Klikněte na **Zahájit srážení**, vyberte ověřené rozhodnutí v Dokumentech
   a potvrďte. Do ověření příjemce zůstávají částky v depozitu.
9. Pokračujte odesíláním příjemci ([§ 88.4](#884-krok-za-krokem-odesilani-srazenych-castek-prijemci)).

**Jak poznáte, že je hotovo:** Případ má stav **Srážet a deponovat** a po
výpočtu mzdy přibudou v **Pohyby srážek** sražené částky.

## 88.4 Krok za krokem: odesílání sražených částek příjemci

1. V detailu případu otevřete blok **Platební instrukce příjemce** a založte
   novou instrukci: aktuální exekutor nebo oprávněný, **Účinné od**, ověřený
   účet z katalogu, zdrojový dokument a důvod.
2. Klikněte na **Ověřit příjemce**, vyberte **Příjemce srážky (katalog
   platebních účtů)** a zaškrtněte **Příjemce platby je ověřený**.
3. Klikněte na **Povolit odesílání** a vyberte rozhodnutí, o které se
   povolení opírá.
4. Po schválení mzdy otevřete `Mzdy → Mzdové příkazy a úhrady` a klikněte na
   **Připravit závazky**.
5. Po odeslání platby ji spárujte na záložce **Spárování úhrad**.

**Jak poznáte, že je hotovo:** Případ má stav **Srážet a odesílat**. V bloku
**Sraženo, depozitum a odeslané platby** roste **Odesláno příjemci** a klesá
**Zbývá odeslat**.

## 88.5 Krok za krokem: měsíční kontrola

1. Před schválením mzdového běhu otevřete u každého běžícího případu blok
   **Měsíční podklady výpočtu** za daný měsíc.
2. V **Běžná měsíční kontrola** projděte rejstřík pohledávek a nároky
   uplatněné v měsíci.
3. Jen při změně klikněte na **Zkontrolovat výjimky** a vyplňte **Soudní
   a další výjimky** (více plátců, důchodová podmínka, soudem určená
   nezabavitelná částka, insolvence).
4. Uložte podklady.
5. Ve výsledku mzdového běhu projděte rozdělení srážek a zůstatky všech
   souběžných případů.

**Jak poznáte, že je hotovo:** Detail ukazuje „Případ je připravený pro
měsíční mzdu" a mzdový běh u zaměstnance nehlásí chybějící podklady srážek.

> [!TIP]
> Zaměstnanec bez aktivní pohledávky a bez oddlužení nic zadávat nemusí.
> Vyživované osoby se mění jen při vzniku, zániku nebo ověření nároku.

## 88.6 Krok za krokem: oddlužení a insolvence

Oddlužení se vede za osobu a měsíc. Exekuční případ kvůli němu nezakládejte.

1. Otevřete `Mzdy → Oddlužení`, vyberte zaměstnance a **Období**.
2. Zvolte **Režim oddlužení**:
   - zahájené insolvenční řízení (srážet a deponovat),
   - **Schválené standardní oddlužení**,
   - **Soudem určená jiná výše měsíčních splátek** s **Měsíční splátka určená
     soudem (Kč)**,
   - **Oddlužení se tento měsíc neuplatní**.
3. U schváleného oddlužení vyberte **Účinný pracovní vztah**, **Ověřený CZK účet
   insolvenčního správce** a **Firemní rozhodnutí v Dokumentech**.
4. Klikněte na **Uložit**.
5. Po schválení mzdy připravte závazek na správce tlačítkem **Připravit
   závazky** v `Mzdy → Mzdové příkazy a úhrady`.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Evidence oddlužení byla
uložena." a u schváleného oddlužení je evidence svázaná s neměnným platebním
pokynem.

> [!WARNING]
> Schválený pokyn nezrušíte změnou režimu. Použijte tlačítko **Zrušit schválené
> oddlužení**, a to jen u pokynu, který ještě nebyl použit.

## 88.7 Krok za krokem: skončení pracovního poměru povinného

Lhůta: do jednoho týdne od skončení (§ 295 odst. 2 o. s. ř.).

1. Ukončete pracovní vztah a schvalte poslední mzdu (viz
   [Zaměstnanci](86_Zamestnanci.md#869-krok-za-krokem-skonceni-vztahu)).
2. V detailu případu otevřete blok **Skončení pracovního poměru** (oznámení
   soudu nebo exekutorovi). Zkontrolujte sražené, vyplacené a zbývající částky
   po pohledávkách.
3. Víte-li, kam povinný nastoupil, vyplňte **Nový plátce mzdy (je-li znám)**
   a **IČO nebo adresa nového plátce**.
4. Klikněte na **Vystavit oznámení**. PDF stáhnete tlačítkem **Stáhnout PDF**.
5. Odešlete oznámení: **Připravit k odeslání** ho zařadí jako koncept do
   datové schránky (adresát musí být v číselníku příjemců), nebo ho odešlete
   jinak.
6. Klikněte na **Zaznamenat odeslání** a vyplňte **Datum odeslání** a způsob.
7. Klikněte na **Ukončit u nás (skončil poměr)**.
8. Pokračující srážky uveďte v zápočtovém listu (sekce **Dokumenty při skončení
   vztahu** na kartě vztahu).

**Jak poznáte, že je hotovo:** Oznámení je **Odesláno** s datem, případ má stav
ukončeno u nás (poměr skončil) a položka checklistu skončení vztahu se
odškrtla sama.

## 88.8 Krok za krokem: vydání depozita insolvenčnímu správci

1. V detailu případu otevřete **Další stavové kroky**.
2. Zvolte **Vydat depozit insolvenčnímu správci**.
3. Vyberte rozhodnutí insolvenčního soudu, **Účet insolvenčního správce**
   a napište důvod. Klikněte na **Provést změnu**.
4. Po schválení připravte závazek tlačítkem **Připravit závazky**.

**Jak poznáte, že je hotovo:** V bloku **Sraženo, depozitum a odeslané platby**
je částka v **Vydáno insolvenčnímu správci** a **Drženo v depozitu** je nula.

## 88.9 Krok za krokem: dotaz exekutora (XMLZAM)

1. Ručně načtěte datovou schránku (`Mzdy → Datová schránka`).
2. Otevřete `Mzdy → Součinnost exekutorům`, vyberte doručený požadavek
   a klikněte na **Ověřit a importovat**.
3. Zkontrolujte spárovaného zaměstnance a příjemce. Vyberte **Exekuční případ
   zaměstnance** a pomocí **Přidat období** období se schválenými mzdami.
4. Klikněte na **Připravit náhled odpovědi**. Náhled nic neukládá ani
   neodesílá.
5. Zkontrolujte zveřejňované údaje a klikněte na **Schválit a zmrazit XML**.
6. Klikněte na **Připravit do datové schránky** a odpověď odešlete v agendě
   Datová schránka.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Odpověď byla připravena do
fronty datové schránky." a zpráva odešla z datové schránky.

## 88.10 Krok za krokem: oprava omylem založeného případu

1. Otevřete detail případu ve stavu **Přijato** (čeká na ověření).
2. Chybnou pohledávku opravte v tabulce **Pohledávky** tlačítkem **Opravit**,
   nebo ji smažte (**Smazat**).
3. Celý prázdný případ smažete v nabídce akcí tlačítkem **Smazat omylem
   založený případ** a potvrzením.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Omylem založený případ byl
smazán.", nebo u pohledávky „Rozpracovaná pohledávka byla smazána."

## 88.11 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Mzdový běh hlásí varování k doručenému případu | Případ je ve stavu **Přijato** a ještě se nesráží | Ověřte podklady a zahajte srážení; varování vědomě potvrďte ([§ 88.12.6](#88126-doruceny-pripad-ktery-se-jeste-nesrazi)). |
| **Zahájit srážení** nejde | Chybí pohledávka, ověření podkladů, rozhodnutí nebo strana případu | Řiďte se blokem **Doporučený další krok**. |
| Peníze zůstávají v depozitu | Příjemce není ověřený, nebo odesílání není povolené | Ověřte příjemce a povolte odesílání ([§ 88.4](#884-krok-za-krokem-odesilani-srazenych-castek-prijemci)). |
| Měsíc spadne do ruční kontroly kvůli manželovi | Stav důchodu manžela je neověřený | Doložte důchod, nebo manžela z vyživovaných osob odeberte. |
| Měsíc s oddlužením a výživným skončí na ručním posouzení | Přednostní pohledávka se za oddlužení vykonává jen podle rozhodnutí soudu | Vyřešte měsíc podle konkrétních usnesení. |
| Výpočet srážek z odstupného se zastaví | Chybí počet násobků, nebo nepotvrzený jiný příjem povinného | Doplňte v sekci **Skončení vztahu** na kartě vztahu ([§ 88.12.8](#88128-srazky-z-odstupneho)). |
| **Ukončit u nás** nejde | Vztah trvá, poslední mzda není schválená, chybí oznámení, nebo je nevydané depozitum | Dokončete kroky v [§ 88.7](#887-krok-za-krokem-skonceni-pracovniho-pomeru-povinneho). |
| Případ nejde smazat | Případ už má pohledávku, rozhodnutí, pohyb nebo platbu | Zastavte ho nebo uzavřete stavovým krokem. |
| Oznámení nejde zařadit do datové schránky | Adresát není v číselníku příjemců | Doplňte ho v agendě Datová schránka, nebo odešlete jinak a zaznamenejte odeslání. |
| Odpověď XMLZAM nejde připravit | Požadavek chce jen část dat, chybí schválená revize nebo nejde spárovat příjemce | Řiďte se hláškou; aplikace údaje nedomýšlí. |
| „Případ mezitím změnil jiný uživatel." | Souběžná změna | Načetl se aktuální stav, akci zopakujte. |

## 88.12 Podrobnosti a pravidla

### 88.12.1 Stavy případu

<!-- cols: 34 66 -->
| Stav | Co znamená |
|---|---|
| **Přijato** (čeká na ověření) | případ je založený, nesráží se |
| **Srážet a deponovat** | sráží se, peníze drží depozitum |
| **Srážet a odesílat** | sráží se a odesílá ověřenému příjemci |
| **Odloženo bez srážení** | případ je odložený, nesráží se |
| Odloženo, deponovat | odložený případ, sražené částky drží depozitum |
| **Uhrazeno** | potvrzené úhrady pokryly celý zůstatek |
| **Zastaveno** | případ je zastavený rozhodnutím |
| Ukončeno u nás (poměr skončil) | povinnému skončil poměr, exekuce pokračuje u dalšího plátce |

Běžný postup vede od pohledávky přes ověření podkladů a zahájení srážení
k ověření příjemce a povolení odesílání. Méně časté změny (odklad, obnovení
deponování nebo odesílání, zastavení, uhrazení, vydání depozita, ukončení
u nás) jsou pod **Další stavové kroky**; jejich skrytí nemění právní kontroly.
Použijte je, jen když odpovídají doloženému rozhodnutí. Odklad, obnovení
a zastavení vyžadují vybraný dokument, odklad a zastavení i důvod. Ukončený
případ nelze zkratkou znovu otevřít. **Označit za uhrazené** projde, až
potvrzené úhrady pokryjí celý zůstatek; sražení ze mzdy nestačí. Samotné
vložení rozhodnutí nezaručuje srážku v uzavřeném běhu; rozhodují účinnost,
pořadí a disponibilní částka.

Aplikace při ověření firmy a oprávnění k dokumentu uloží jeho otisk a dokument
pak chrání jako právní důkaz. Číslo řízení, účet příjemce ani právní dokument se
do polí případu nepřepisují; patří do zabezpečených dokumentů.

### 88.12.2 Výpočet a pravidla roku

Výpočet používá celé haléře a uchovává neměnný měsíční vstup, použitou verzi
pravidel, mezikroky zaokrouhlení, přidělení částek pohledávkám a pohyby
**sraženo / deponováno**. Kontroluje nezabavitelnou částku, třetiny, plně
zabavitelný zbytek, pořadí přednostních pohledávek, běžné a dlužné výživné, víc
exekučních příkazů, víc plátců, oddlužení a paušální náhradu nákladů
zaměstnavatele. Chybějící měsíční podklady nezastoupí odhadem, výsledek označí
k ruční kontrole. Odeslané peníze eviduje samostatně platební vrstva, aby
výpočet nemohl předstírat skutečnou úhradu.

> [!WARNING]
> **Rok 2025 se počítá jinou formulí než rok 2026**, nejen jinými čísly.
> V roce 2025 se z rozhodné části srážely dvě třetiny a hranice plně
> zabavitelného zbytku byla jedenapůlnásobek základní částky; od roku 2026 je
> poměr 85/100, hranice 1,9násobek a k částce na bydlení přibyl paušál na
> energie. Při opravě měsíce roku 2025 aplikace použije tehdy účinná pravidla,
> takže se výsledek od dnešního zadání téhož případu správně liší (viz
> [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md#9281-ktere-roky-jsou-pokryte)).

### 88.12.3 Manžel a nezabavitelná částka: doložený důchod

Od 1. 1. 2025 (nařízení vlády č. 441/2024 Sb.) se čtvrtina nezabavitelné částky
na manžela nebo partnera započítá, jen je-li doloženo, že **pobírá důchod
z důchodového pojištění**. Samotné manželství nestačí. U osoby vedené jako
manžel nebo partner zvolte:

- **doloženo**: čtvrtina se započítá; doplňte **Komu byl důchod přiznán**,
  **Druh důchodu** a **Datum doložení** (vyžadují se jen tady),
- **nedoloženo**: čtvrtina se nezapočítá a výpočet proběhne; povinný neunesl
  důkazní břemeno,
- **neověřeno**: výchozí hodnota u starších záznamů, ručně ji vybrat nejde.
  Čtvrtina se nezapočítá a měsíc, ve kterém se skutečně sráží, spadne do ruční
  kontroly. Měsíc bez srážky běh neblokuje. Uzavřená období se nepřepočítávají;
  rozdíl vypořádejte podle vlastního posouzení.

Měsíční podklady se vyžadují jen tam, kde mají co doložit. Zaměstnanec bez
aktivní pohledávky a bez oddlužení nezadává nic. Na vyživované osoby a slevu na
manžela se aplikace ptá, jen když je nárok uplatněný. U schválené mzdy je
z výsledku vidět, jestli byl podklad doložený, nebo proč se nevyžadoval.
Uplatněný a nedoložený nárok běh neblokuje, ale do kapacity dobrovolných dohod
o srážkách nepustí, protože nezabavitelná částka, ze které se strop dohody
počítá, není doložená.

### 88.12.4 Den pořadí, pohledávky a strany případu

U zákonné pohledávky aplikace určí **Den pořadí** sama z data doručení prvnímu
plátci. Při přípravě každého běhu se pořadí posoudí znovu; příkaz doručený po
dni výplaty se do běhu nezařadí. Schválený běh ani připravená úhrada se pozdější
skutečností nepřepisují. U víc pohledávek jednoho příkazu zvolte referenční
pohledávku a společné datum i pořadí se převezmou. Datum doručení lze
u staršího neúplného záznamu jednou doplnit, pak je neměnné. Chybně zadanou
nepoužitou pohledávku smažte a založte znovu podle listiny.

Kategorie pohledávek: **Běžné výživné**, **Dlužné výživné**, **Úplata za
postoupené výživné**, **Postoupené výživné**, **Náhradní výživné**, **Jiná
přednostní pohledávka** a **Nepřednostní pohledávka**.

V bloku **Strany případu a skladba pohledávky** se ukládá aktuální soud nebo
exekutor, oprávněný a rozpad pohledávek na jistinu, úroky, náklady a výživné.
Rozpad musí přesně odpovídat evidované částce. Každá změna vytváří neměnnou
revizi s odkazem na dokument; původní podklad ani dřívější výpočet
nepřepisujte.

**Oprava.** Dokud je případ ve stavu **Přijato** a pohledávka nevstoupila do
mzdového výsledku, ledgeru ani závazku, lze ji opravit nebo smazat. Oprava
zachová identitu pohledávky, zvýší verzi a podklady znovu označí jako
neověřené; uložené datum doručení se nemění. Prázdný případ lze smazat jen
tehdy, když k němu není pohledávka, dokument, změna stavu, alokace, pohyb,
závazek ani platba; aplikace to před smazáním ověří znovu. Smazání je nevratné.
Případ s historií smazat nejde, zastavte ho nebo uzavřete.

### 88.12.5 Insolvenční režimy

V `Mzdy → Oddlužení` je u každého režimu popsán dopad na výpočet.

- **Schválené standardní oddlužení** vypočte celou zabavitelnou část a po
  schválení mzdy z ní **Připravit závazky** vytvoří závazek na ověřený účet
  insolvenčního správce.
- **Soudem určená jiná výše měsíčních splátek** funguje stejně, jen se srazí
  částka z výroku usnesení, nejvýš do zákonné zabavitelné části; v měsíci
  s nižším příjmem vyjde méně. Splátka je povinná a patří k ní pracovní vztah,
  ověřený účet správce a rozhodnutí.
- **Zahájené insolvenční řízení** (srážet a deponovat) platí od vyhlášky
  o zahájení řízení do rozhodnutí o úpadku a způsobu řešení. Exekuce se v té
  době nesmí provést (§ 109 odst. 1 písm. c) insolvenčního zákona), srážet se
  ale má. Aplikace srazí částku v rozsahu dosavadních exekucí (pořadí, třetiny
  i paušál beze změny) a celou ji ponechá v depozitu; oprávněným se nic
  neodešle. Dohody o srážkách se v tomto režimu nesrážejí. Stačí ověřit vyhlášku
  o zahájení řízení, příjemce se neověřuje.

Potvrzení příjemce v měsíčních podkladech nenahrazuje rozhodnutí ani ověřený
účet. Exekuce vedle schváleného oddlužení se po dobu oddlužení nevykonávají:
zůstanou v rejstříku, srážka jde celá správci a mzda se spočítá. Výjimkou jsou
**přednostní** pohledávky (typicky výživné), které se za insolvence vykonávat
mohou, ale jen podle rozhodnutí insolvenčního soudu; takový měsíc skončí na
ručním posouzení.

**Vydání depozita.** Po schválení oddlužení nebo prohlášení konkursu patří
částky sražené a deponované za zahájeného řízení správci. Akce **Vydat depozit
insolvenčnímu správci** zapíše vydání celého depozita, případ odloží bez dalšího
srážení a **Připravit závazky** vytvoří závazek na účet správce splatný dnem
vydání. Pohledávce oprávněného se vydaná částka nezapočítá; depozitum, uzávěrka
roku i zápočtový list s ní počítají jako s vrácením zaměstnanci.

### 88.12.6 Doručený případ, který se ještě nesráží

Srážet se má ode dne doručení exekučního příkazu nebo usnesení plátci. Případ
ve stavu **Přijato** se ale do výpočtu nedostane, dokud podklady neověříte
a srážení nezahájíte. Mzdový běh na každý takový případ upozorní varováním
u zaměstnance; varování je nutné vědomě potvrdit a jeho odkaz otevře detail
případu. Případ účinný až po dni výplaty běh nehlásí.

### 88.12.7 Platební instrukce a depozitum

Platební instrukce váže aktuálního exekutora nebo oprávněného na ověřený účet
typu *ostatní příjemce* (z `Mzdy → Nastavení mezd`, záložky **Účty institucí**)
a doložený dokument. Účet musí být k datu účinnosti účinný a ověřený; do
zmrazeného platebního podkladu se uloží konkrétní účet i variabilní, specifický
a konstantní symbol. Číslo účtu ani symboly se do případu neopisují.

Bez doložené strany a platební instrukce nelze zahájit srážení ani povolit
odesílání. Změna příjemce, účtu nebo symbolů vytvoří novou instrukci pro budoucí
úhrady; historickou úhradu nemění. **Připravit závazky** vytvoří závazek jen
z částek ve stavu **odesílání**. Deponované částky (nový případ, odklad,
zastavení) do odchozí dávky nejdou. Opakovaná příprava nevytvoří druhý závazek;
oprava mzdy promítne rozdíl a pokles vznikne jako samostatný opravný závazek.

Blok **Sraženo, depozitum a odeslané platby** ukazuje **Sraženo zaměstnanci**,
**Drženo v depozitu**, **Připraveno k úhradě**, **Odesláno příjemci**, **Zbývá
odeslat** a **Zbývá srazit**, i v rozpadu po pohledávkách. Odesláno a zbývající
částka se mění až po spárování skutečné bankovní nebo pokladní platby v `Mzdy →
Mzdové příkazy a úhrady`, záložce **Spárování úhrad**.

### 88.12.8 Srážky z odstupného

Odstupné je pro srážky jiný příjem než mzda. Podle § 299 odst. 4 o. s. ř. se dělí
na tolik částí, kolika násobkům průměrného měsíčního výdělku odpovídá, a každá
se posuzuje jako mzda za jeden měsíc doby poskytování odstupného. Z každého
násobku se odečte vlastní nezabavitelná částka a srážka se počítá zvlášť;
z celku jako z jedné mzdy by se srazilo víc, než zákon dovoluje.

Počet násobků zadáváte při zakládání odstupného v sekci **Skončení vztahu** na
kartě vztahu (**Počet násobků průměru pro srážky**, viz
[Zaměstnanci](86_Zamestnanci.md#861217-skonceni-vztahu)). Předvyplní se
z návrhu podle § 67 zákoníku práce; měňte ho jen u jinak sjednaného odstupného.
Běh za měsíc skončení ukazuje ve výsledku srážek mzdu a násobky odstupného
zvlášť. Paušální náhradu si plátce ponechá jen jednou, ze mzdy; z násobků mu
nepatří (§ 301 odst. 2 o. s. ř.). Čisté odstupné nese svůj díl zálohy na daň,
pojistné z něj neplyne.

Nastoupí-li povinný v době poskytování odstupného jinam nebo mu vznikne jiný
příjem (důchod, podpora), násobky za tyto měsíce se s tím příjmem sčítají
(§ 299 odst. 4 věta druhá o. s. ř.). Vyplňte **Jiný příjem povinného od**
a podle oznámení nového plátce nebo soudu případně zaškrtněte
**Nezabavitelnou částku za tyto měsíce započítává nový plátce**. Bez potvrzení
výpočet srážek z odstupného zastaví, protože druhý příjem aplikace nezná.
Chybí-li počet násobků, výpočet se zastaví k ručnímu posouzení a sekce
**Skončení vztahu** řekne, kde ho doplnit (množství u vstupu odstupného
v mzdových vstupech).

### 88.12.9 Skončení pracovního poměru povinného

Do jednoho týdne musíte oznámit soudu nebo exekutorovi, že povinný u vás
přestal pracovat, a zaslat vyúčtování provedených a vyplacených srážek
s pořadím pohledávek (§ 295 odst. 2 o. s. ř.). Lhůtu hlídá položka **Exekuce
a insolvence: oznámení soudu / exekutorovi do 7 dnů** v checklistu skončení
vztahu a přehled termínů. Blok oznámení ukazuje datum skončení (**Poměr
skončil**), lhůtu (**Oznámit do**) a po pohledávkách sraženo, vyplaceno,
depozitum a zbytek. Nový plátce je nepovinný; soud ho pak vyrozumí podle § 294
odst. 3 o. s. ř. **Vystavit oznámení** obsah zmrazí; změnu vyřešíte vystavením
nové verze. Jakmile má oznámení každý běžící případ zaměstnance, položka
checklistu se odškrtne sama.

Skončení poměru exekuci nezastavuje, pokračuje u dalšího plátce. **Ukončit
u nás (skončil poměr)** jde až po skončení všech vztahů zaměstnance, schválení
poslední mzdy, vystavení oznámení a jen bez nevydaného depozita. Oznámení
a vyúčtování zahrnují i srážky z odstupného. Pokračující exekuce, dohody
o srážkách a insolvenci uveďte v zápočtovém listu, aby v nich další plátce
pokračoval.

### 88.12.10 Paušální náhrada plátce mzdy

Paušální náhradu nákladů plátce mzdy (50 Kč za měsíc, nejvýše třetina sražené
částky) hradí povinný (§ 270 odst. 2 o. s. ř., § 87 odst. 3 exekučního řádu)
a uspokojuje se z první třetiny před ostatními pohledávkami (§ 279 odst. 1).
V běžném měsíci, kdy srážku omezuje třetina, ji plátce odečte ze sražené
částky a oprávněný dostane o ni méně; pohledávka se umořuje jen tím, co
oprávněnému došlo. Stačí-li třetina na víc (poslední měsíc exekuce, výživné
kryté celé z druhé třetiny), srazí se náhrada navíc: při zbývajícím dluhu
1 000 Kč se srazí 1 050 Kč, oprávněný dostane celý zbytek a exekuce skončí.
Náhrada nikdy nespotřebuje celou srážku.

**Běžné výživné** se sráží každý měsíc, evidence ale vede u pohledávky jednu
částku. Do pohledávky zapište jednu měsíční dávku; částka za víc měsíců naráz
by se srazila celá hned. Na další měsíc založte nový případ s pohledávkou
běžného výživného a aktivujte ho, vyčerpaný případ po úhradě označte za
uhrazený. Zapomenete-li, mzdový běh se před schválením zastaví varováním
s odkazem na exekuce osoby.

Plátce si ze sražené částky ponechá paušální náhradu nákladů (§ 270 odst. 2
o. s. ř.) a oprávněnému pošle zbytek. Mzdový předpis proto sraženou částku
zaúčtuje na závazek exekučních srážek (obvykle 379.200) a paušál z něj převede
na výnos podle předkontace **Paušální náhrada plátce mzdy** v `Mzdy → Nastavení
mezd`, záložce **Automatické účtování** (obvykle 648 Ostatní provozní výnosy).
Závazek pak po úhradě oprávněným vyjde na nulu.

Předkontace je nepovinná. Bez výnosového účtu v osnově ji nechte prázdnou
a paušál zůstane na závazku. Převod se uplatní u mezd uzamčených po nastavení
předkontace; zaúčtované měsíce se nemění. Zůstatek paušálů z dřívějších měsíců
na závazku přeúčtujte jednorázově ručně.

### 88.12.11 Součinnost exekutorům

Stránka `Mzdy → Součinnost exekutorům` importuje přesný požadavek XMLZAM
z datové schránky. Zobrazí se jen dosud nezpracované XML přílohy; aplikace jim
začne důvěřovat až po kontrole odesílatele, struktury a jednoznačném spárování
zaměstnance. Částky v odpovědi pocházejí jen ze schválených neměnných revizí
a pořadí z aktuální evidence exekucí; má-li osoba víc případů, vyberte ten, kterého
se dotaz týká. Žádá-li požadavek jen část dat, ale oficiální XSD vyžaduje všechny
tři bloky, odpověď se nevytvoří. Datová schránka exekutora musí mít právě jednoho
aktivního příjemce. Odpověď se neodesílá sama, potvrdíte ji v datové schránce.

### 88.12.12 Bezpečnost a časté chyby

Použijte nejvyšší míru omezení přístupu. Ověřte pravidla účinná v měsíci,
pořadí doručení, přednostní charakter, nezabavitelnou částku a souběh. Citlivé
listiny neukládejte do veřejných odkazů a historické rozhodné datum neměňte bez
auditní stopy. Každý případ má **Auditní časovou osu**.

Časté chyby:

- chybné pořadí více exekucí,
- záměna přednostní a nepřednostní pohledávky,
- neaktualizovaný zůstatek po externí platbě,
- ruční srážka navíc k automaticky vypočtené částce.

## 88.13 Související kapitoly

- [Dohody o srážkách](87_Dohody_o_srazkach.md): dobrovolné a zákonné srážky
- [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md): účinné parametry
- [Mzdové běhy](80_Mzdove_behy.md): výpočet srážek
- [Platby a úhrady](82_Platby_a_uhrady.md): úhrada příjemci
- [Zaměstnanci](86_Zamestnanci.md): skončení vztahu a zápočtový list
- [Datová schránka](97_Datova_schranka.md)
