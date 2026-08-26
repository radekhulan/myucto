# 26. Platební příkazy

Modul **Platební příkazy** (menu **Nákup → Platební příkazy**) slouží k hromadné
úhradě **nezaplacených přijatých faktur**. Vybrané faktury vyexportuje do formátu,
který nahraješ do internetového bankovnictví — **ABO (KPC)** pro tuzemské CZK platby,
**SEPA XML (pain.001)** pro EUR platby do zahraničí, nebo **CSV** / **PDF** jako
přehled. Příkaz si systém zapamatuje a umíš ho kdykoli stáhnout znovu.

Navazuje na [Přijaté faktury](23_Prijate_faktury.md) (odtud berou data a platební
údaje) a doplňuje [Export přijatých](24_Export_prijatych.md), který řeší předání
dokladů účetní.

> [!NOTE]
> **Předání k úhradě není „zaplaceno".** Zařazení faktury do příkazu jen označí,
> že jsi platbu odeslal(a) do banky — skutečnou úhradu potvrdí až spárování
> bankovního výpisu (viz [Banka](28_Banka.md)). Stav faktury proto zůstává
> `Přijatá` / `Zaúčtovaná`, jen dostane příznak **„Předáno k úhradě"**.

## 26.1 Účet plátce

Nahoře vyber **účet plátce** — bankovní účet, ze kterého se bude platit. Nabídka
vychází z [bankovních účtů dodavatele](29_Bankovni_ucty.md) (číselník měn): pro každou
měnu můžeš mít vlastní účet. Předvyplní se **výchozí CZK účet**; přepnutím na účet
v jiné měně (např. EUR) se nabídnou faktury v dané měně.

Příkaz je vždy **jednoměnový** — měna příkazu = měna zvoleného účtu plátce. Faktury
v jiné měně nelze do téhož příkazu zařadit (jsou nevybíratelné).

Dále zvolíš:

- **Datum splatnosti** — kdy se má platba provést. ABO příkaz nesmí mít datum
  v minulosti, proto se starší datum automaticky posune na dnešek (systém na to upozorní).
- **Konstantní symbol** *(nepovinné)* — společný KS pro celý příkaz.
- **Poznámka** *(nepovinné)* — interní popis dávky (uloží se do historie).

## 26.2 Seznam faktur k úhradě

Nezaplacené přijaté faktury (stav `Přijatá` nebo `Zaúčtovaná` se zbývající částkou
k úhradě) se zobrazí ve **dvou tabulkách**, aby bylo jasné, co čím zaplatíš:

- **Faktury v CZK** — platba přes **ABO (KPC)** (i CSV/PDF),
- **Ostatní měny** — platba přes **CSV / PDF**, u účtu plátce v **EUR** navíc přes
  **SEPA XML** (viz [§ 26.7a](#267a-format-sepa-pain001) — ABO je tuzemský CZK
  platební styk, SEPA slouží pro EUR platby v rámci Evropy).

Vybíratelné (zaškrtávací) jsou jen faktury, které **mají platební účet** a jsou ve
měně zvoleného účtu plátce. U každého řádku vidíš:

- **Dodavatele** a číslo jeho dokladu,
- **datum splatnosti** + případný badge **„Předáno k úhradě"** (pokud už byla zařazena),
- **účet příjemce** s názvem banky, **jak byl účet získán** (ISDOC / AI / QR / ručně)
  a stav **ověření** (viz [§ 26.4](#264-overeni-uctu-prijemce)),
- **variabilní symbol** a **částku** k úhradě.

Přepínač **„Skrýt už zařazené k úhradě"** schová faktury, které jsi do nějakého
příkazu už dříve zařadil(a).

## 26.3 Doplnění a úprava účtu příjemce

Pokud u faktury **chybí platební účet**, zobrazí se tlačítko **„Doplnit účet"**;
u faktur s účtem ikona **tužky** (Upravit). Otevře se inline formulář, kde zadáš:

- **číslo účtu** + **kód banky** (tuzemský formát `[předčíslí-]číslo`), nebo
- **IBAN** + **BIC** (zahraniční / SEPA),
- **variabilní symbol**.

Účet se nejčastěji doplní automaticky už při importu faktury — z **ISDOC** přílohy,
**AI extrakce** ([AI extrakce](25_AI_extrakce.md)) nebo z **QR kódu** na PDF. Zdroj
je vidět u řádku jako malý štítek.

## 26.4 Ověření účtu příjemce

U tuzemských plátců DPH umí systém ověřit účet proti **registru plátců DPH (CRPDPH)**.
Klikni na **„Ověřit"** u účtu — porovná zadaný účet se **zveřejněnými účty** dodavatele
a upozorní na **nespolehlivého plátce**. Výsledek:

- **zveřejněný účet** — účet je mezi zveřejněnými účty plátce (✓ bezpečné),
- **nezveřejněný** — plátce nalezen, ale tento účet mezi zveřejněnými není (zvaž ověření),
- **nespolehlivý plátce** — dodavatel je vedený jako nespolehlivý (riziko ručení za DPH),
- *(bez ověření)* — dodavatel není tuzemský plátce DPH nebo je registr nedostupný.

Při neúspěchu shody systém v hlášce vypíše seznam **zveřejněných účtů**, ať je můžeš
porovnat ručně.

> [!TIP]
> Ověřování účtu proti zveřejněným účtům plátce DPH je prevencí ručení za nezaplacenou
> DPH dodavatele (§ 109 zákona o DPH). U nových dodavatelů ho doporučujeme provést
> před první platbou.

## 26.5 QR kód, náhled PDF a detail

U každé faktury s účtem jsou v sloupci **Akce** rychlé nástroje:

- **QR kód** — rozbalí QR platbu (CZK SPAYD / SEPA) z uloženého účtu, částky a VS;
  načteš ji mobilní bankou bez exportu příkazu.
- **PDF** — inline náhled originálního dokladu (pokud je přiložené PDF), stejně jako
  v detailu faktury.
- **Detail** — otevře [detail přijaté faktury](23_Prijate_faktury.md) v novém okně.

## 26.6 Vytvoření příkazu a export

Vyber faktury a zvol akci:

- **Jen označit** — faktury se označí jako **předané k úhradě** (příznak), **bez**
  generování souboru. Hodí se, když platíš jinak (např. ručně) a chceš jen evidovat.
- **Export CSV** — přehled plateb s oddělovačem `;` (UTF-8), pro ruční zadání nebo archiv.
- **Export PDF** — tištěný přehled příkazu na šířku (příjemci, účty, symboly, částky, ověření).
- **Export ABO (KPC)** — datový soubor pro nahrání do banky (jen CZK, viz [§ 26.7](#267-format-abo-kpc)).
- **Export SEPA (pain.001)** — XML soubor pro EUR platby do zahraničí (jen účet plátce
  v EUR, viz [§ 26.7a](#267a-format-sepa-pain001)).

Při exportu se z vybraných faktur vytvoří **dávka** (snapshot údajů v daném okamžiku),
faktury dostanou příznak **„Předáno k úhradě"** a soubor se rovnou stáhne.

Volba **„Označit při exportu faktury rovnou jako zaplacené"** navíc překlopí faktury
do stavu **Zaplaceno** (použij jen pokud nepoužíváš automatické párování výpisů —
jinak hrozí dvojí evidence).

## 26.7 Formát ABO (KPC)

**ABO** (přípona `.kpc`) je standardní formát příkazu k úhradě pro české banky
(Česká spořitelna a kompatibilní). MyÚčto generuje **hromadný příkaz**: jeden účet
plátce v hlavičce, položky pro jednotlivé příjemce, jedno datum splatnosti.

- Funguje **jen pro CZK** a příjemce s **tuzemským účtem** (číslo + kód banky);
  faktury jen s IBANem nebo v cizí měně do ABO nepatří — použij CSV/PDF.
- Částky jsou v **haléřích**, konstantní symbol se kóduje spolu se směrovým kódem banky
  příjemce (specifikum formátu — řeší to systém za tebe).
- Soubor je v ASCII (diakritika ve zprávě se převede), zakódovaný pro přímý import.

Vygenerovaný `.kpc` soubor jednoduše nahraješ v internetovém bankovnictví do importu
hromadných příkazů.

## 26.7a Formát SEPA (pain.001)

**SEPA Credit Transfer** (ISO 20022, formát `pain.001.001.03`) je standardní XML
formát pro platby v **EUR** — přijímají ho banky napříč Evropou, včetně českých
(ČS, KB, ČSOB, Raiffeisenbank a další). Použij ho pro dodavatele v zahraničí
(zálohy, faktury v EUR), kde ABO (tuzemský CZK styk) nefunguje.

- Vyber **účet plátce v EUR** (viz [§ 26.1](#261-ucet-platce)) — tlačítko **Export
  SEPA** se zpřístupní jen pro EUR příkaz.
- Plátce i **každý příjemce v dávce musí mít vyplněný IBAN** (doplníš ho stejně jako
  tuzemský účet, viz [§ 26.3](#263-doplneni-a-uprava-uctu-prijemce)). BIC je nepovinný
  (v rámci SEPA/EHP se od roku 2016 nevyžaduje) — pokud ho znáš, doplň ho pro jistotu.
  Faktury bez IBAN se do SEPA dávky nezařadí.
- Vygenerované XML obsahuje hlavičku dávky, plátce (IBAN/BIC), a pro každou platbu
  příjemce (IBAN/BIC), částku, variabilní symbol (jako referenci) a datum splatnosti.
- Soubor jednoduše nahraješ v internetovém bankovnictví do importu SEPA/zahraničních
  příkazů.

> [!IMPORTANT]
> Vytvoření XML **neodešle peníze** a nepotvrdí úhradu faktury. Banka po importu
> znovu ověří oprávnění, účet, datum a disponibilní zůstatek a platby musíš v
> bankovnictví autorizovat. Skutečnou úhradu následně potvrď importem bankovního
> výpisu; uložený snapshot dávky je doklad toho, co bylo předáno bance, nikoli
> důkaz, že banka platbu provedla.

## 26.8 Stav „Předáno k úhradě" a filtrování

Předání k úhradě je **samostatná dimenze**, ne stav faktury — faktura zůstává
`Přijatá`/`Zaúčtovaná` a navíc nese příznak **„Předáno k úhradě"**. V seznamu
[Přijatých faktur](23_Prijate_faktury.md) proto najdeš filtr **„Předání k úhradě"**
(předané / nepředané) a u řádků štítek, takže snadno odlišíš, co už čeká na zaplacení.

Skutečné **Zaplaceno** nastaví až spárování bankovního výpisu ([Banka](28_Banka.md)),
ruční označení úhrady, nebo volba „označit jako zaplacené" při exportu.

## 26.9 Historie příkazů

Dole na stránce je **Historie příkazů** — každá vytvořená dávka s datem, účtem plátce,
počtem položek, součtem a příznakem „zaplaceno". U každé dávky můžeš příkaz **stáhnout
znovu** (CSV / PDF / ABO / SEPA) — díky uloženému snapshotu je opětovné stažení
totožné s původním, nezávisle na pozdějších změnách faktur.

## 26.10 Omezení a tipy

- **ABO jen CZK** + tuzemský účet příjemce. **SEPA jen EUR** + IBAN plátce i příjemce.
  Ostatní cizí měny se platí přes CSV/PDF, nebo zahraničním příkazem ve své bance.
- **Jeden příkaz = jedna měna** (dle účtu plátce).
- MyÚčto v příkazu neprovádí směnu měn: faktura, účet plátce a zvolený export
  musí mít odpovídající měnu. Směnu nebo platbu v jiné měně zadej přímo v bance.
- Datum splatnosti v minulosti se posune na dnešek (požadavek ABO).
- Účty si nech **ověřit proti CRPDPH** zejména u nových dodavatelů.
- Pro readonly uživatele je tvorba příkazů a editace účtů zakázána (jen čtení a stažení).

> [!TIP]
> Rychlý postup: v [Přijatých fakturách](23_Prijate_faktury.md) zaškrtni faktury
> a klikni **„Do příkazu k úhradě"** — předvybrané doklady se otevřou přímo zde.
