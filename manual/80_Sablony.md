# 80. Šablony a pravidla

**Cesta: `Nástroje → Šablony`**

Stránka soustřeďuje tři rozdílné druhy pomůcek. Nejde o jeden univerzální
automat: každá záložka vstupuje do jiné části zpracování.

| Záložka | Co ovlivňuje |
|---|---|
| Šablony zápisů | Předvyplnění řádků ručního účetního zápisu |
| Pravidla nákladů | Návrh druhu a účtu řádku přijaté faktury |
| Pravidla účtování | Návrh nebo automatizace opakovaných bankovních plateb |

Všechny tři záložky jsou firemní a zobrazují se jen v podvojném účetnictví.
Globální katalog šablon bankovních pravidel je systémová agenda a má vlastní
stránku **Systém → Šablony bank. pravidel** (viz 59.4). Změna pravidla se
použije na nové návrhy; již zaúčtované zápisy sama zpětně nepřepočítává.

## 80.1 Šablony účetních zápisů

Šablona ukládá název, popis a libovolný počet řádků:

- aktivní účet z účtového rozvrhu,
- stranu **MD** nebo **Dal**,
- volitelnou výchozí částku,
- popis řádku,
- volitelné nákladové středisko.

Tlačítkem **Použít** se otevře ruční zápis s předvyplněnými řádky. Účetní před
zaúčtováním doplní datum, číslo dokladu, popis a chybějící částky. Šablona není
účetním dokladem a její výchozí částky nejsou důkazem správnosti; výsledný zápis
musí být vyrovnaný.

Stejný výběr šablon je dostupný také v náhledu kontace dokladu. Použití šablony
změní návrh řádků, nikoli zdrojový doklad. Backend při uložení znovu ověří
aktivitu účtů, otevřené období, datumový zámek a rovnost MD/Dal.

### 80.1.1 Systémové a vlastní šablony

Při prvním načtení seznamu repozitář idempotentně doplní doporučenou mzdovou
šablonu a předuzávěrkové šablony. Každá má stabilní `seed_key`, takže opakované
načtení nevytvoří kopie. Firemní šablony lze zakládat, upravovat a mazat.

API nad `journal_entry_templates` a `journal_entry_template_lines` vždy ukládá
hlavičku i řádky společně. Účet šablony patří stejné firmě a musí být aktivní.
Kód střediska se ověřuje proti firemnímu číselníku. Endpoint
`/journal-templates/{id}/import-csv` slouží jen k náhledu napárování externí
rekapitulace; samotný import bez potvrzení nic nezaúčtuje.

## 80.2 Pravidla klasifikace nákladů

Pravidlo předvyplní na řádku přijaté faktury jeden z druhů:

- **služba** — výchozí účet 518,
- **materiál** — výchozí účet 501,
- **drobný majetek** — výchozí účet 501,
- **dlouhodobý majetek** — výchozí účet 042.

Pravidlo může místo výchozího účtu určit konkrétní aktivní nákladový účet.
Saldokonto, DPH, banku a pokladnu nelze nastavit jako cílový nákladový účet.

### 80.2.1 Podmínky shody

Formulář dovoluje omezit pravidlo:

- konkrétním dodavatelem,
- fragmentem názvu dodavatele,
- fragmentem popisu položky,
- dolní a horní hranicí částky.

Všechna vyplněná kritéria se vyhodnocují současně (**AND**). Samotné cenové
pásmo nestačí; pravidlo musí mít dodavatele nebo textový fragment, jinak by
nebezpečně zachytávalo nesouvisející nákupy.

Aktivní pravidla se zkoušejí podle nejnižšího čísla priority. Při stejné
prioritě má přednost pravidlo s více úspěšnými použitími a poté nižší ID.
Vyhraje první shoda. Po potvrzeném použití se zvýší počet zásahů a uloží čas
posledního použití.

Volba **opakovaný předplacený náklad** může označit pravidelně hrazenou službu
pro návrh časového rozlišení. Nejde o automatické zaúčtování: uzávěrková logika
musí stále ověřit období, významnost a podklad.

### 80.2.2 Od návrhu k deníku

Klasifikátor může spojit firemní pravidlo, text položky a výsledek importu nebo
AI vytěžení. Do uložení řádku jde jen o návrh. Potvrzený `expense_kind` následně
určí předkontaci při zaúčtování dokladu; DPH jde odděleně přes
`VatLedgerService`.

U drobného majetku lze z potvrzených řádků vytvořit evidenční karty. Karta
nevytváří druhý nákladový zápis; podrobnosti jsou v kapitole
[Drobný majetek](27_Drobny_majetek.md).

> [!IMPORTANT]
> Cenový práh ani text „notebook“ sám nerozhoduje, zda jde o drobný či dlouhodobý
> majetek, technické zhodnocení nebo soubor věcí. Konečné posouzení patří účetnímu
> a vnitřní směrnici firmy.

## 80.3 Pravidla účtování banky

Tato záložka spravuje opakované bankovní pohyby bez spolehlivě párovatelného
dokladu, například bankovní poplatky, odvody, úroky nebo splátky úvěru.
Podrobný pracovní postup fronty **K zaúčtování** je v kapitole
[Bankovní účty](29_Bankovni_ucty.md).

Pravidlo obsahuje směr platby, účet MD/Dal a alespoň jeden rozpoznávací znak:
protiúčet, variabilní symbol nebo fragment zprávy. Lze přidat rozsah částky.
Bankovní strana musí odpovídat účtu 221; saldokontní účty 311, 321, 314, 324
a 325 patří párování dokladů, ne obecnému pravidlu.

Nové pravidlo začíná v režimu **navrhovat**. Na automatický režim se povýší až
po úspěšném použití a s bezpečným rozsahem částky. Dry-run na historii nic
nezapisuje. Volitelný backfill vytvoří návrhy i pro starší nezaúčtované pohyby;
nikdy nepřeúčtuje již zaúčtovanou transakci.

Schválení návrhu vytvoří idempotentní zápis, odmítnutí uloží důvod a auditní
stopu. Opakovaně odmítané pravidlo se může deaktivovat. Historický backfill
vždy degraduje automatický režim na návrh, aby stará data neúčtoval bez
kontroly.

## 80.4 Šablony bankovních pravidel (globální katalog)

**Cesta: `Systém → Šablony bank. pravidel`**

Administrátor instance může spravovat katalog typických pravidel. Šablona má
stabilní klíč, popis, výchozí kontaci, kritéria, režim a aktivní stav. Firemní
uživatel z ní vytvoří vlastní pravidlo; další změna globální šablony již
instalovanou firemní kopii potichu nepřepíše.

Instalace ověřuje existenci účtů v osnově konkrétní firmy. Neplatná nebo
neaktivní šablona se neinstaluje. Globální CRUD je oddělený od tenantových
bankovních pravidel a je dostupný pouze administrátorovi.

## 80.5 Předkontace nejsou šablony

Předkontace v **Nástrojích** je systémová mapa operace na základní dvojici účtů,
například `invoice.services.issued` nebo `offset.mutual`. Šablona naproti tomu
předvyplňuje celý ruční zápis. Pravidlo nákladů vybírá druh řádku dokladu a
bankovní pravidlo rozpoznává transakci.

Při řešení chyby proto postupuj podle vrstvy:

1. špatně rozpoznaný druh nákladu — oprav pravidlo nákladů nebo řádek dokladu,
2. správný druh, ale chybný základní účet — oprav předkontaci,
3. nestandardní vícerádkový zápis — použij nebo uprav šablonu,
4. opakovaná platba bez dokladu — oprav bankovní pravidlo.

## 80.6 Oprávnění, audit a chyby

Čtení šablon vyžaduje `accounting.templates`; jejich změna zápisovou variantu
téhož oprávnění. Pravidla nákladů používají účetní oprávnění, bankovní pravidla
`bank.rules`. Globální katalog je jen pro administrátora. Demo režim může
mutace blokovat i tehdy, když je tlačítko zobrazené.

Backend všechny objekty omezuje na aktuální firmu a zaznamenává vytvoření,
změnu, smazání, použití či instalaci do auditní stopy. Typické chyby jsou:

- neaktivní nebo neexistující účet,
- chybějící kritérium pravidla,
- obrácené cenové pásmo,
- priorita mimo povolený rozsah 0–999,
- dodavatel nebo šablona patří jiné firmě,
- účetní období je uzavřené nebo datum uzamčené.

> [!TIP]
> Automatizaci nasazuj postupně: nejdřív pravidlo otestuj, několik návrhů ručně
> schval a teprve podle skutečných zásahů zvaž automatický režim.
