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
Používají firmu zvolenou v hlavní liště aplikace. U Pravidel účtování je
jméno firmy uvedeno také nad seznamem; pravidla druhé firmy se nepřimíchávají.
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
Každé pravidlo má režim **jen navrhovat** nebo **použít automaticky**. Pravidla
vytvořená asistentem začínají vždy v režimu návrhu a na automatické použití je
vhodné přepnout až po ověření na nových dokladech.

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

## 80.7 Asistent nastavení účtování

**Cesta: `Nástroje → Asistent nastavení účtování`**

Asistent je určený hlavně pro firmu s naimportovanou nebo historickou účetní
databází, která dosud nemá pravidla. Je širší než provozní Automat. Prochází
položky přijatých faktur, jejich dosavadní kontace a opakované bankovní pohyby.
Výsledkem jsou návrhy analytických účtů, pravidel nákladů, předkontací,
bankovních pravidel, kandidátů na dlouhodobý majetek a upozornění na neúplná
data.

Pokud je nákladový účet v osnově plochý, může asistent navrhnout analytiky pro
opakovaně rozpoznané skupiny, například pohonné hmoty, energie, drobný majetek,
opravy, pojištění nebo služby. Pravidlo nákladů míří na existující nebo současně
navrženou analytiku, nikoli přímo na syntetický účet. Účet se při analýze nezaloží. Vznikne až po výslovném
schválení společně se závislým pravidlem nákladů a případnou firemní
předkontací. Existující analytiky ani vlastní předkontace asistent nepřepisuje
bez schválení.

V editoru analytiky lze místo založení nového účtu zvolit kterýkoli aktivní
existující analytický účet. Asistent pak atomicky přesměruje všechna dosud
neschválená závislá pravidla nákladů a předkontace. Pouhé odškrtnutí návrhu
analytiky naopak odškrtne také návrhy, které by bez ní neměly platný cílový účet.

Analýzu lze bezpečně spouštět opakovaně. Každý běh zůstane uložený jako
samostatná auditní stopa, ale obrazovka pracuje vždy s výsledkem posledního
běhu. Aktivní ekvivalentní pravidlo nákladů, předkontace nebo bankovní pravidlo
se znovu nenavrhne. Kdyby se stav změnil mezi analýzou a schválením, druhá
kontrola při schválení duplicitní pravidlo stejně nevytvoří.

Interní katalog rozpoznává obecné výrazy v češtině, slovenštině, němčině a
angličtině. Slova pro pohonné hmoty, materiál, služby, opravy, pojištění a
majetek mají také záporné pojistky, aby například doprava nebo pronájem
nespadly mezi pořízení majetku. Česká a slovenská diakritika se při porovnání
normalizuje, například `Š` a `š` na `s`. Katalog neobsahuje data konkrétních
firem. Jednoznačné výrazy pro drobný majetek se sčítají napříč dodavateli,
takže například dva notebooky od různých prodejců vytvoří jeden obecný návrh
pravidla místo dvou slabých, osamocených vzorů.

Pokud je pro firmu nakonfigurovaná a potvrzená AI brána, lze při spuštění
výslovně zapnout doplnění nerozpoznaných položek. Po lokálním průchodu se odešle
uživatelem zvolených nejvýše 50, 100 nebo 200 opakujících se typických textů.
Výchozí a nejlevnější rozsah je 50. Vyšší rozsahy se rozdělí na samostatné dávky
po nejvýše 50 vzorcích, aby odpověď nepřekročila limit poskytovatele. Model se
kvůli velikosti vstupu automaticky nemění. Texty jsou zkrácené, bez diakritiky a
zbavené názvů protistran, čísel dokladů, identifikátorů, dat a částek. AI pouze
určí povahu nákladu, společné klíčové slovo a obecný název analytiky. Volbu účtu
a roční hranici majetku vždy provede a znovu ověří lokální účetní vrstva. Chyba
nebo nedostupnost AI nezastaví základní analýzu; úspěšné předchozí dávky se při
částečném výsledku zachovají.

U banky asistent hledá opakované pohyby a učí se také z jejich konzistentní
historické kontace. Již existující ekvivalentní bankovní pravidla odfiltruje.
Schválený návrh založí bankovní pravidlo v režimu **navrhovat** pro frontu
**Bankovní účty → K zaúčtování**. Saldokontní účty se tímto způsobem nenavrhují
a bankovní historie se hromadně nepřepisuje.

U majetku se nepoužívá pevná částka. Asistent načte daňový limit platný pro rok
pořízení z ročních daňových konstant. Cena se posuzuje za kus bez DPH a po
přepočtu do korun. Pouze hmotná věc s částkou vyšší než dobový limit vytvoří
kandidáta na účet 042 a založení karty odpisovaného majetku; služba kandidátem
není. Kandidáti jsou uvedeni jmenovitě a každý se potvrzuje samostatně. Samotný
návrh kartu dlouhodobého majetku nezaloží.

Postup má tři oddělené kroky:

1. **Analýza historie** je pouze pro čtení a běží jako úloha na pozadí.
   Po dokončení ukazuje procentní odhad položek pokrytých klasifikací. Přesný
   podíl dokladů, jejichž kontace se skutečně změní, ukáže až dry-run.
2. **Kontrola návrhů a vytvoření pravidel** rozděluje výsledky do záložek pro pravidla nákladů,
   analytiky, předkontace, bankovní pravidla, majetek a kvalitu dat. Druhý filtr
   odliší místní slovník, AI a vzory z historie. Před schválením lze podporovaný
   návrh upravit v samostatném okně, například změnit český název, klíčovou frázi
   nebo účty MD/D. Technické klíče předkontací se uživateli nezobrazují jako
   názvy. Z vybraných položek pak vznikne neměnný balíček a pravidla se uloží
   v režimu jen navrhovat. Balíček pro následný dry-run současně zmrazí všechna
   již aktivní pravidla nákladů firmy, takže opakovaná analýza nezapomene pravidla
   vytvořená předchozím během. Pokud opakovaná analýza nenajde žádné nové pravidlo,
   lze tlačítkem **Použít aktivní pravidla** vytvořit balíček pouze z těch stávajících.
   Tímto krokem se historické doklady ani deník nemění.
3. **Přeúčtování historie** je volitelné. Uživatel zvolí datum od a do. Rozsah
   se řídí DUZP, a pokud chybí, datem vystavení dokladu; prázdná mez znamená
   celou dostupnou historii. Bezpečný výchozí režim zahrne pouze doklady, jejichž
   položka odpovídá konkrétnímu pravidlu. Volba **Přeúčtovat všechny doklady v období**
   zahrne také faktury bez konkrétní shody a použije na ně výchozí předkontaci,
   například zbytkovou analytiku 518.100. Tato volba je vhodná pro úplný převod
   ze syntetik na analytiky, může však nahradit ručně zadané účtování. Dry-run vypíše
   každý dotčený doklad, český stav a účty před změnou i po ní. Ostrý běh vyžaduje
   dokončený dry-run stejného balíčku, se zcela shodným rozsahem dat i režimem výběru.
   Z čísla dokladu lze otevřít stejný náhled zdroje jako v účetním deníku.
   Ostrý běh čistě nahradí jen nákladové a majetkové řádky 5xx/04x existujícího
   účetního zápisu. Závazek 321, DPH 343 a ostatní nohy převezme beze změny.
   Nevytváří storno ani další kopii zápisu. Pokud se položka změní na drobný
   hmotný nebo nehmotný majetek, ve stejné transakci se synchronizuje také karta
   v **Nákup → Drobný majetek**. Dlouhodobý majetek zůstává kandidátem k ručnímu
   založení karty a odpisového plánu. Tento job mění pouze přijaté faktury;
   již zaúčtovanou bankovní historii nemění.

Uzavřené, uzavírané a měkce uzamčené období se vždy přeskočí. Stav období se
ověří při dry-runu a znovu atomicky bezprostředně před přepisem. Pokud se zápis
nebo vypočtený výsledek od dry-runu změnil, asistent jej také přeskočí a vyžádá
novou kontrolu. Po ostrém běhu lze původní účtování obnovit ze snapshotu; obnova
opět smí změnit jen otevřené a nezamčené období a odmítne doklad mezitím ručně
změněný. Jakmile už záloha není potřeba, lze snapshot samostatně a nevratně
smazat. Smazání snapshotu účetní deník ani klasifikaci položek nemění, pouze
odstraní možnost obnovy daného běhu.
