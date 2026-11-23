# 26. Platební příkazy

> Návod, jak zaplatit více přijatých faktur najednou: vybrané faktury
> vyexportujete do souboru pro internetové bankovnictví nebo je pošlete rovnou
> do banky. Platí i pro vratky peněz odběratelům, daně finančnímu úřadu a ostatní
> závazky (úvěry, nájemné, leasing). Pro každého, kdo v bance
> zadává hromadné platby.

## 26.1 Kdy to potřebujete

- Blíží se splatnost několika přijatých faktur a nechcete je v bance zadávat po jedné.
- Platíte zahraničním dodavatelům v EUR a potřebujete soubor SEPA.
- Potřebujete tištěný nebo tabulkový přehled plateb (PDF, CSV) pro schválení.
- Chcete jen evidovat, že jste fakturu poslali k úhradě, protože platíte jinak.
- Máte vystavenou fakturu nebo dobropis, u kterého dlužíte peníze odběrateli (vrácené obaly, přeplatek).

| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| před splatností faktur | Vybrat nezaplacené faktury a vytvořit příkaz | `Nákup → Platební příkazy`, postup v [§ 26.3](#263-krok-za-krokem-zaplatit-prijate-faktury-hromadnym-prikazem) |
| u nového dodavatele | Ověřit účet proti registru plátců DPH před první platbou | stejná stránka, tlačítko **Ověřit**, postup v [§ 26.4](#264-krok-za-krokem-doplnit-a-overit-ucet-prijemce) |
| po odeslání do banky | Zkontrolovat a autorizovat dávku v bankovnictví | bankovnictví, viz [§ 26.5](#265-krok-za-krokem-odeslat-prikaz-primo-do-banky) |
| po platbě | Potvrdit úhradu importem výpisu | `Banka`, viz [Banka](29_Banka.md) |

> [!WARNING]
> Zařazení faktury do příkazu **neznamená zaplaceno**. Jen označí, že jste platbu
> předali bance. Skutečnou úhradu potvrdí až spárování bankovního výpisu
> (viz [Banka](29_Banka.md)). Faktura proto zůstává ve stavu `Přijatá` nebo
> `Zaúčtovaná` a jen dostane příznak **Předáno k úhradě**.

## 26.2 Než začnete

- U faktur, které chcete platit, musíte mít **platební účet dodavatele**. Pokud
  chybí, doplníte ho podle [§ 26.4](#264-krok-za-krokem-doplnit-a-overit-ucet-prijemce).
- Potřebujete **bankovní účet plátce** v číselníku ([Bankovní účty](30_Bankovni_ucty.md)).
  Pro každou měnu můžete mít vlastní účet.
- Pro SEPA platbu musí mít účet plátce **IBAN** a měnu EUR.
- Pro přímé odeslání do banky musí být účet aktivně propojený s bankou a mít
  potřebná oprávnění (viz [§ 26.5](#265-krok-za-krokem-odeslat-prikaz-primo-do-banky)).
- Uživatel jen pro čtení si může soubory stáhnout, ale příkazy vytvářet a účty upravovat nemůže.

## 26.3 Krok za krokem: zaplatit přijaté faktury hromadným příkazem

1. Otevřete `Nákup → Platební příkazy`.
2. Nahoře zvolte **Účet plátce**. Předvyplní se výchozí účet v CZK. Přepnutím na účet
   v jiné měně (například EUR) se nabídnou faktury v této měně.
3. Vyplňte **Datum splatnosti**. Volitelně doplňte společný **Konstantní symbol**
   a **Poznámku** (interní popis dávky, uloží se do historie).
4. V tabulkách **Faktury v CZK** a **Ostatní měny** zaškrtněte faktury k úhradě.
   Zaškrtnout lze jen faktury, které mají platební účet a měnu shodnou s účtem plátce.
5. Zvolte akci:
   - **Jen označit** - faktury se označí jako předané k úhradě, soubor se nevytvoří.
   - **Export CSV** - přehled plateb ke stažení.
   - **Export PDF** - tištěný přehled příkazu.
   - **Export ABO (KPC)** - soubor pro banku, jen pro CZK.
   - **Export SEPA (pain.001)** - soubor pro banku, jen pro EUR.
6. Soubor se stáhne. Nahrajte ho v internetovém bankovnictví do importu hromadných
   příkazů (ABO) nebo SEPA příkazů a platbu tam autorizujte.

**Jak poznáte, že je hotovo:** u faktur se objeví štítek **Zařazeno k úhradě** (v seznamu
přijatých faktur **Předáno k úhradě**) a příkaz je v části **Historie příkazů** dole
na stránce. Platba je skutečně uhrazená až po spárování výpisu.

> [!TIP]
> Rychlejší cesta: v seznamu `Nákup → Přijaté faktury` zaškrtněte faktury a klikněte
> na **Do příkazu k úhradě**. Předvybrané doklady se otevřou přímo na stránce Platební příkazy.

Volba **Označit při exportu faktury rovnou jako zaplacené** navíc překlopí faktury
do stavu **Zaplaceno**. Použijte ji jen tehdy, když nepoužíváte automatické
párování výpisů, jinak hrozí dvojí evidence.

## 26.4 Krok za krokem: doplnit a ověřit účet příjemce

1. U faktury bez účtu klikněte na **Doplnit účet**, u faktury s účtem na ikonu tužky (**Upravit účet**).
2. Zadejte **číslo účtu** a **kód banky** (tuzemský formát `[předčíslí-]číslo`),
   nebo **IBAN** a **BIC** (zahraniční platby a SEPA). Doplňte **variabilní symbol**.
3. Uložte. Zobrazí se hláška **Platební účet uložen.**
4. U tuzemského plátce DPH klikněte u účtu na **Ověřit**. Aplikace porovná účet
   se zveřejněnými účty dodavatele v registru plátců DPH.

**Jak poznáte, že je hotovo:** u účtu je štítek s výsledkem ověření: **zveřejněný účet**
(bezpečné), **nezveřejněný** (zvažte ověření u dodavatele) nebo **nespolehlivý plátce**
(riziko ručení za DPH).

> [!TIP]
> Ověřování účtu je prevencí ručení za nezaplacenou DPH dodavatele (§ 109 zákona o DPH).
> U nových dodavatelů ho doporučujeme provést před první platbou.

## 26.5 Krok za krokem: odeslat příkaz přímo do banky

Funguje u aktivně propojeného účtu Fio ČR (2010), ČSOB (0300), Raiffeisenbank (5500),
Banky CREDITAS (2250), MONETA Money Bank (0600), České spořitelny (0800, se souhlasem
pro platby) nebo KB+ (0100, jen varianta Plus
se souhlasem pro hromadné platby), a to pro tuzemský příkaz v CZK. Napojení Fio SR
(8330) slouží jen k načítání pohybů, ne k přímému odesílání EUR příkazů.

1. Na stránce `Nákup → Platební příkazy` vyberte faktury a zvolte **Připravit příkaz pro banku**.
   Příkaz se uloží a faktury se neoznačí jako zaplacené.
2. V sekci pod přehledem faktur vyberte uložený příkaz.
3. Klikněte na **Odeslat do banky** a potvrďte.
4. V internetovém bankovnictví dávku zkontrolujte a autorizujte.
   - CREDITAS: `Transakce → Zadané → Hromadné`.
   - MONETA: sekce **Zprávy a oznámení** (dávka k podpisu).
   - Česká spořitelna: podpis v MyÚčtu nebo přechod do George podle nastavení
     propojení, viz [§ 30.11.5.2](30_Bankovni_ucty.md#301152-ceska-sporitelna-0800).

**Jak poznáte, že je hotovo:** banka dávku přijala a čeká na vaši autorizaci. Odeslání
samo úhradu nepotvrzuje a zahájený import dávky ještě nepotvrzuje přijetí jednotlivých plateb.

Nastavení propojení a úplný postup jsou v kapitole
[Bankovní účty](30_Bankovni_ucty.md#30116-odeslani-prikazu-do-banky-pravidla).

## 26.6 Krok za krokem: vrátit peníze odběrateli

Postup funguje, když máte zapnuté vyplácení přeplatků: v `Firma → Nastavení` na záložce
**Fakturace** volbu **Povolit vyúčtování s částkou k vyplacení**.
Bez zapnuté volby se vratky v příkazech neukazují, ani u běžných dobropisů.

1. Otevřete detail faktury nebo dobropisu, u kterého dlužíte peníze zákazníkovi.
2. Klikněte na hlavní akci **Vrátit peníze**.
3. Vyberte **Účet odběratele** z nabídky, nebo zvolte **Jiný účet (zadat ručně)**
   a zadejte číslo účtu a kód banky. Volbou **Uložit účet do karty klienta** si ho zapamatujete.
4. Zvolte účet, ze kterého platíte, a **Datum splatnosti**. Variabilní symbol je
   předvyplněný číslem dokladu a nejde změnit.
5. Klikněte na **Stáhnout ABO**, **CSV** nebo **PDF**. Je-li účet napojený na bankovní API,
   můžete příkaz rovnou **Odeslat do banky**.

Volba **Přidat do hromadného příkazu** příkaz nevytváří. Jen uloží ručně zadaný účet
ke klientovi a doklad pak najdete v tabulce **Vratky odběratelům** na stránce Platební příkazy.

**Jak poznáte, že je hotovo:** vytvořený příkaz je v **Historii příkazů**. Doklad zůstává
otevřený, dokud se nespáruje odchozí platba z výpisu, nebo ho v nabídce **…** v detailu
neoznačíte volbou **Označit jako vyplaceno**.

## 26.7 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Faktura nejde zaškrtnout | Chybí platební účet, nebo má jinou měnu než účet plátce | U řádku klikněte na **Doplnit účet**, nebo přepněte **Účet plátce** na správnou měnu |
| Hláška, že ABO (KPC) lze vytvořit jen pro příkaz v CZK | Účet plátce není v CZK | Zvolte účet v CZK, nebo použijte CSV či PDF |
| Hláška o vybraných fakturách bez tuzemského účtu | Faktura má jen IBAN nebo cizí měnu | Doplňte tuzemský účet, nebo použijte CSV či PDF |
| Hláška, že SEPA XML lze vytvořit jen pro příkaz v EUR | Účet plátce není v EUR | Zvolte účet plátce v EUR |
| Hláška, že vybraný účet plátce nemá IBAN | SEPA vyžaduje IBAN | Doplňte IBAN k účtu plátce v [Bankovních účtech](30_Bankovni_ucty.md) |
| Hláška o vybraných fakturách bez platného IBAN | Příjemce nemá IBAN | Doplňte IBAN u faktury (**Doplnit účet**), nebo použijte CSV či PDF |
| Hláška, že datum splatnosti bylo posunuto na dnešek | ABO příkaz nesmí mít datum v minulosti | Žádná akce, případně zvolte pozdější datum |
| Hláška, že X faktur bylo přeskočeno | U faktur chybí účet, je jiná měna, nebo není co platit | Přečtěte důvod v hlášce a fakturu opravte |
| Příkaz nejde smazat | Má evidovaný pokus o odeslání přes bankovní konektor | U předaného příkazu použijte archivaci (viz [§ 26.8.9](#2689-omezeni-a-tipy)) |
| Odeslání příkazu je blokované | Opakované odeslání stejného příkazu aplikace nepovolí | Stav ověřte v bance |
| Vratka nejde vybrat | U odběratele chybí účet | Účet doplňte v detailu dokladu |
| Vratky v příkazech nevidíte | Není zapnuté vyplácení přeplatků | V `Firma → Nastavení`, záložka **Fakturace**, zapněte **Povolit vyúčtování s částkou k vyplacení** |

## 26.8 Podrobnosti a pravidla

### 26.8.1 Účet plátce

Nabídka účtů vychází z [bankovních účtů dodavatele](30_Bankovni_ucty.md). Příkaz je
vždy **jednoměnový**: měna příkazu je měna zvoleného účtu plátce. Faktury v jiné měně
nelze do téhož příkazu zařadit (nejdou zaškrtnout).

Datum splatnosti: ABO příkaz nesmí mít datum v minulosti, proto se starší datum
automaticky posune na dnešek a aplikace na to upozorní.

### 26.8.2 Seznam faktur k úhradě

Nezaplacené přijaté faktury (stav `Přijatá` nebo `Zaúčtovaná` se zbývající částkou
k úhradě) jsou ve dvou tabulkách:

- **Faktury v CZK** - platba přes **ABO (KPC)** (i CSV a PDF),
- **Ostatní měny** - platba přes **CSV / PDF**, u účtu plátce v EUR navíc přes
  **SEPA XML** (viz [§ 26.8.6](#2686-format-sepa-pain001)). ABO je tuzemský CZK platební styk, SEPA slouží pro EUR platby v rámci Evropy.

U každého řádku vidíte:

- dodavatele a číslo jeho dokladu,
- datum splatnosti a případný štítek **Zařazeno k úhradě**, pokud už byla faktura zařazena,
- účet příjemce s názvem banky, **jak byl účet získán** (ISDOC, AI, QR nebo ručně)
  a stav ověření (viz [§ 26.8.3](#2683-overeni-uctu-prijemce)),
- variabilní symbol a částku k úhradě.

Přepínač **Skrýt už zařazené k úhradě** schová faktury, které jste do nějakého příkazu už zařadili.

Účet se nejčastěji doplní automaticky už při importu faktury, a to z přílohy ISDOC,
z [AI extrakce](25_AI_extrakce.md) nebo z QR kódu na PDF. Zdroj je vidět u řádku jako malý štítek.

### 26.8.3 Ověření účtu příjemce

U tuzemských plátců DPH umí systém ověřit účet proti **registru plátců DPH (CRPDPH)**.
Porovná zadaný účet se zveřejněnými účty dodavatele a upozorní na nespolehlivého plátce.
Výsledek:

- **zveřejněný účet** - účet je mezi zveřejněnými účty plátce,
- **nezveřejněný** - plátce nalezen, ale tento účet mezi zveřejněnými není (zvažte ověření),
- **nespolehlivý plátce** - dodavatel je veden jako nespolehlivý (riziko ručení za DPH),
- bez ověření - dodavatel není tuzemský plátce DPH, nebo je registr nedostupný.

Při neúspěchu shody aplikace v hlášce vypíše seznam zveřejněných účtů, ať je můžete porovnat ručně.

### 26.8.4 QR kód, náhled PDF a detail

U každé faktury s účtem jsou ve sloupci **Akce** rychlé nástroje:

- **QR kód** - rozbalí QR platbu (CZK SPAYD nebo SEPA) z uloženého účtu, částky a VS.
  Načtete ji mobilní bankou bez exportu příkazu.
- **PDF** - náhled originálního dokladu (pokud je přiložené PDF), stejně jako v detailu faktury.
- **Detail** - otevře [detail přijaté faktury](23_Prijate_faktury.md) v novém okně.

### 26.8.5 Formát ABO (KPC)

**ABO** (přípona `.kpc`) je standardní formát příkazu k úhradě pro české banky
(Česká spořitelna a kompatibilní). MyÚčto generuje **hromadný příkaz**: jeden účet
plátce v hlavičce, položky pro jednotlivé příjemce, jedno datum splatnosti.

- Funguje **jen pro CZK** a příjemce s **tuzemským účtem** (číslo a kód banky).
  Faktury jen s IBANem nebo v cizí měně do ABO nepatří, použijte CSV nebo PDF.
- Částky jsou v **haléřích**. Konstantní symbol se kóduje spolu se směrovým kódem banky
  příjemce (specifikum formátu, řeší to aplikace za vás).
- Soubor je v ASCII (diakritika ve zprávě se převede), zakódovaný pro přímý import.
- Pro účet plátce u Raiffeisenbank (5500) aplikace automaticky použije hlavičku podle
  požadavků RB, a to při stažení souboru i při předání přes API.

### 26.8.6 Formát SEPA (pain.001)

**SEPA Credit Transfer** (ISO 20022, formát `pain.001.001.03`) je standardní XML
formát pro platby v **EUR**. Přijímají ho banky napříč Evropou, včetně českých
(ČS, KB, ČSOB, Raiffeisenbank a další). Použijte ho pro dodavatele v zahraničí
(zálohy, faktury v EUR), kde ABO nefunguje.

- Vyberte **účet plátce v EUR**. Tlačítko **Export SEPA (pain.001)** se zpřístupní jen pro EUR příkaz.
- Plátce i **každý příjemce v dávce musí mít vyplněný IBAN** (doplníte ho stejně jako
  tuzemský účet, viz [§ 26.4](#264-krok-za-krokem-doplnit-a-overit-ucet-prijemce)). BIC je nepovinný
  (v rámci SEPA a EHP se od roku 2016 nevyžaduje), pokud ho znáte, doplňte ho pro jistotu.
  Faktury bez IBAN se do SEPA dávky nezařadí.
- XML obsahuje hlavičku dávky, plátce (IBAN, BIC) a pro každou platbu příjemce (IBAN, BIC),
  částku, variabilní symbol (jako referenci) a datum splatnosti.

> [!WARNING]
> Vytvoření XML **neodešle peníze** a nepotvrdí úhradu faktury. Banka po importu
> znovu ověří oprávnění, účet, datum a disponibilní zůstatek a platby musíte
> v bankovnictví autorizovat. Skutečnou úhradu potvrďte importem bankovního
> výpisu. Uložený snapshot dávky je doklad toho, co bylo předáno bance, nikoli
> důkaz, že banka platbu provedla.

### 26.8.7 Stav „Předáno k úhradě“ a filtrování

Předání k úhradě je **samostatná dimenze**, ne stav faktury. Faktura zůstává
`Přijatá` nebo `Zaúčtovaná` a navíc nese příznak **Předáno k úhradě**. V seznamu
[Přijatých faktur](23_Prijate_faktury.md) proto najdete filtr **Předání k úhradě**
(**Předané k úhradě** / **Nepředané k úhradě**) a u řádků štítek, takže snadno
odlišíte, co už čeká na zaplacení.

Skutečné **Zaplaceno** nastaví až spárování bankovního výpisu ([Banka](29_Banka.md)),
ruční označení úhrady, nebo volba **Označit při exportu faktury rovnou jako zaplacené**.

### 26.8.8 Historie příkazů

Dole na stránce je **Historie příkazů**: každá vytvořená dávka s datem, účtem plátce,
počtem položek, součtem a příznakem **Zaplaceno**. Příkaz můžete **stáhnout
znovu** (CSV, PDF, ABO, SEPA). Díky uloženému snapshotu je opětovné stažení
totožné s původním, nezávisle na pozdějších změnách faktur.

### 26.8.9 Omezení a tipy

V historii lze uložený příkaz odstranit tlačítkem **Smazat** po potvrzení.
Příkaz s evidovaným pokusem o odeslání přes bankovní konektor smazat nelze,
ani při odmítnutém nebo nejasném výsledku. Smazání nemění stav faktur ani
jejich označení k úhradě a nezruší platbu, kterou jste do banky nahráli ručně.

Akce **Připravit příkaz pro banku** příkaz uloží bez označení faktur jako
zaplacených. Přijatý příkaz je potřeba autorizovat v internetovém bankovnictví.
U nejasného výsledku ověřte stav v bance, opakované odeslání stejného příkazu je blokované.

Pokud jste celou dávku v bance zrušili, zvolte v historii příkazu **Smazat**.
U předaného příkazu aplikace nabídne místo trvalého smazání archivaci
s výslovným potvrzením, že žádná platba z dávky nebude provedena.
Příkaz zmizí z přehledu, ale jeho položky, historie odeslání a ochrana
proti opakovanému odeslání zůstanou zachované. Jde o vaše potvrzení,
nikoli o ověření bankovním API. Stav faktur a označení k úhradě se nemění.

- **ABO jen CZK** a tuzemský účet příjemce. **SEPA jen EUR** a IBAN plátce i příjemce.
  Ostatní cizí měny se platí přes CSV nebo PDF, nebo zahraničním příkazem ve vaší bance.
- **Jeden příkaz = jedna měna** (podle účtu plátce).
- MyÚčto v příkazu neprovádí směnu měn: faktura, účet plátce a zvolený export
  musí mít odpovídající měnu. Směnu nebo platbu v jiné měně zadejte přímo v bance.
- Datum splatnosti v minulosti se posune na dnešek (požadavek ABO).
- Účty si nechte **ověřit proti CRPDPH**, zejména u nových dodavatelů.
- Pro uživatele jen se čtením je tvorba příkazů a editace účtů zakázána (jen čtení a stažení).

### 26.8.10 Vratky odběratelům

Vratkou je vystavená **faktura** nebo **dobropis**, u kterých po odečtení (vrácené
obaly, přeplatek záloh) dlužíte zákazníkovi peníze. Vratky se zobrazí v samostatné
tabulce **Vratky odběratelům** pod fakturami k úhradě. Vybrat je jde do stejné dávky
jako přijaté faktury, pokud platíte z účtu v CZK:

- **příjemcem** je odběratel dokladu, účet se bere z karty klienta. Přednost má účet
  zadaný ručně, pak účet z registru plátců DPH a nakonec účet naučený z bankovních výpisů
  (štítky **zadáno ručně**, **registr DPH**, **z výpisu**),
- **částka** je částka k vrácení na dokladu,
- **variabilní symbol** je číslo dokladu a nejde změnit. Podle něj se odchozí platba
  po importu výpisu s dokladem spáruje sama,
- vratky se posílají jen v **CZK** a jen převodem. Doklad s formou úhrady hotově do
  příkazu nepatří, vyplácí se přes pokladnu.

Vratka bez známého účtu odběratele vybrat nejde, účet doplníte v detailu dokladu.
Ručně zadaný účet se kontroluje (modulo 11).

Zařazení do příkazu doklad jen označí jako předaný k vyplacení, doklad zůstává
otevřený. Vyplacený je až po **spárování odchozí platby** z bankovního výpisu, nebo
když ho v detailu dokladu v nabídce **…** označíte volbou **Označit jako vyplaceno**.
Volba **Označit při exportu faktury rovnou jako zaplacené** se na vratky nevztahuje.
Smazáním příkazu se u vratky zruší i označení „předáno k vyplacení“, pokud doklad
není v jiném příkazu.

### 26.8.11 Daně finančnímu úřadu

Tabulka **Daně finančnímu úřadu** nabízí daně firmy, které se platí finančnímu úřadu:

- **DPH z podaných přiznání** - za každé období poslední doložené řádné nebo opravné
  přiznání, dokud bankovní výpis daň nepokryje, viz [Platba DPH](49_Archiv_podani_a_rekonciliace.md#49913-platba-dph),
- **zálohy na daň z příjmů** z předpisů záloh (splatné do 120 dní nebo nezaplacené po
  splatnosti), viz [Daň z příjmů](43_Dan_z_prijmu.md).

Účet, variabilní symbol i částku určuje aplikace, ručně se nezadávají:

- **účet** je předčíslí podle druhu daně (705 DPH, 7704 daň z příjmů právnických osob,
  721 fyzických osob) a číslo finančního úřadu. U DPH se úřad bere z podaného přiznání,
  u záloh z **finančního úřadu** vybraného v nastavení firmy. Bez něj záloha vybrat nejde,
- **variabilní symbol** je DIČ bez předpony CZ,
- **částka** je daň k úhradě, u částečně uhrazené DPH zbývající část.

Daně se platí jen z účtu v **CZK** a jdou do stejné dávky jako faktury a vratky,
včetně ABO a odeslání do banky. Volba **Jen označit** se na daně nevztahuje, daň
zařadí jen skutečný příkaz. Zařazená daň nese štítek **Zařazeno k úhradě**, zaplacená
je až po spárování odchozí platby z výpisu. Daň ze závislé činnosti, srážková daň
a pojistné se platí mzdovými dávkami v **Mzdy → Platby**, ne tady.

### 26.8.12 Ostatní závazky

Tabulka **Ostatní závazky** nabízí závazky z [Ostatních pohledávek a závazků](50_Pruvodce_ucetniho.md#50104-ostatni-pohledavky-a-zavazky)
(úvěry, nájemné, leasing, vlastní druhy), které jsou **potvrzené nebo zaúčtované**,
vedené v **CZK** a mají zbytek k úhradě. Koncepty, pohledávky a závazky v cizí měně
se nenabízejí.

- Závazek se **splátkovým kalendářem** nabízí nejbližší otevřenou splátku, která ještě
  není v žádném příkazu. Řádek nese štítek **Splátka 2/12**, splatnost splátky a částku
  rovnou jejímu zbytku. Po zařazení splátky do příkazu se nabídne další splátka.
- Závazek **bez kalendáře** nabízí celý zbytek (štítek **Celý zbytek**) se splatností
  položky.
- **Účet příjemce** je účet protistrany z položky, **variabilní symbol** číslice z jejího
  VS a zpráva pro příjemce číslo dokladu, název a pořadí splátky. Ručně se nic nezadává,
  účet nebo VS opravíte v detailu položky.
- Závazek **bez účtu protistrany** je v tabulce, ale vybrat nejde (**Chybí účet
  protistrany**). Účet doplníte v detailu položky a stránku obnovíte.

Ostatní závazky se platí jen z účtu v **CZK** a jdou do stejné dávky jako faktury,
vratky a daně, včetně ABO, CSV, PDF a odeslání do banky. Zařazená splátka nebo zbytek
nese štítek **Zařazeno k úhradě** a podruhé vybrat nejde, filtr ho skryje (**Skrýt už zařazené k úhradě**). Smazáním příkazu nebo jeho archivací po zrušení v bance se splátka zase uvolní.

Zařazení do příkazu závazek **neoznačí jako uhrazený**. Volba **Jen označit** ani
**Označit při exportu faktury rovnou jako zaplacené** se na ostatní závazky
nevztahuje. Úhradu zapíše až spárování odchozí platby z bankovního výpisu, ručně
v dialogu **Spárovat** u pohybu nebo v detailu položky (VS a účet protistrany
z příkazu párování usnadní). Do banky přes konektor jde příkaz jen tehdy, když
splátka nebo zbytek pořád pokryje částku v příkazu. Jinak ho aplikace odmítne, aby
splátka uhrazená mezitím jinou platbou neodešla podruhé.

## 26.9 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md) - odtud se berou data a platební údaje.
- [Export přijatých](24_Export_prijatych.md) - předání dokladů účetní.
- [AI extrakce](25_AI_extrakce.md) - automatické rozpoznání platebního účtu z PDF.
- [Bankovní účty](30_Bankovni_ucty.md) - účty plátce a přímé odeslání příkazu do banky.
- [Banka](29_Banka.md) - spárování výpisu, které potvrdí úhradu.
