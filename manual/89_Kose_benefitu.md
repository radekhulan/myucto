# 89. Koše benefitů

> Návod, jak hlídat zákonné limity osvobození nepeněžních benefitů (zdravotní
> plnění, rekreace, spoření na stáří, stravování, přechodné ubytování) a jak
> číst přehled čerpání za firmu. Pro mzdové účetní.

## 89.1 Kdy to potřebujete

- Zavádíte nový benefit a potřebujete, aby se jeho osvobození počítalo do
  zákonného limitu.
- Zaměstnanec dostal benefit a chcete vědět, kolik mu z ročního limitu zbývá.
- Blíží se konec roku a chcete zkontrolovat, kdo je nad limitem a kolik se mu
  zdanilo.
- Kontrolujete měsíční příspěvek na stravování nebo přechodné ubytování.

## 89.2 Než začnete

1. **Mzdová složka benefitu** musí mít v katalogu vybraný zákonný koš
   (`Mzdy → Mzdové složky a vstupy`, viz
   [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md)).
2. **Legislativní pravidla** pro daný rok musí být schválená, odtud se bere
   výše limitu (viz [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md)).
3. **Oprávnění**: přehled stačí číst se základním oprávněním ke mzdám, stejným
   jako seznam mzdových vstupů.

Zákonné koše se nezakládají ručně. Plnění do nich zařadí klasifikace použité
mzdové složky.

## 89.3 Krok za krokem: nový benefit do koše

1. Otevřete `Mzdy → Mzdové složky a vstupy`, katalog složek, a u složky
   benefitu vyberte zákonný koš (např. zdravotní plnění). Volitelně vyplňte
   **Roční limit** jako vlastní firemní strop.
2. Zadejte benefit zaměstnanci jako mzdový vstup. Náhled vstupu ukáže, kolik
   z koše je po tomto plnění vyčerpáno a kolik zbývá.
3. Vstup schvalte. Nadlimitní část se při schválení oddělí jako zdanitelná.
4. Spočítejte mzdový běh.

**Jak poznáte, že je hotovo:** V `Mzdy → Koše benefitů` je u zaměstnance řádek
koše s vyčerpanou částkou a stavem **V limitu**, **Blíží se limitu** nebo
**Nad limitem**.

## 89.4 Krok za krokem: kontrola čerpání za firmu

1. Otevřete `Mzdy → Koše benefitů` (nadpis stránky je **Koše osvobození
   benefitů**).
2. Zvolte záložku **Roční koše** (vyberte **Zdaňovací období**), nebo
   **Měsíční koše** (vyberte **Měsíc**).
3. Případně zužte koš a zaměstnance (**Hledat podle jména**).
4. Projděte sloupce **Vyčerpáno**, **Limit**, **Zbývá** a **Zdaněno nad limit**
   a stav řádku.
5. Před mzdovým během porovnejte souhrn se zdrojovými doklady. Přehled sám nic
   nepřepočítává ani nezapisuje.

**Jak poznáte, že je hotovo:** Žádný řádek nemá stav **Neúplný podklad** bez
vysvětlení a nadlimitní čerpání odpovídá zdaněné částce.

## 89.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Schválený benefitní vstup nejde upravit | Čerpání je už zapsané v ročním koši | Proveďte opačný zápis a pak zadejte správnou částku. |
| Schválení vstupu neprojde | Vstup překračuje vlastní firemní strop (**Roční limit** u složky) | Snižte částku, nebo upravte strop složky. |
| Stav **Neúplný podklad** | Část vstupů je z doby, kdy se koše nezmrazovaly | Nic se nedopočítává; zkontrolujte vstupy ručně. |
| Stav **Limit není k dispozici** | Pro období chybí schválená legislativní pravidla, nebo jde o stravování s limitem za směnu | Schvalte pravidla pro rok; u stravování je to v pořádku. |
| Poznámka „Zmrazený rozpad neodpovídá dnešnímu limitu" | Limit se v pravidlech změnil po schválení vstupů | Čísla zůstávají zmrazená, sedí s vyplacenými mzdami. |
| „Za zvolené období nikdo koš nečerpal" | Ve filtru nejsou žádné vstupy | Zkuste jiné období, nebo klikněte na **Zrušit filtry**. |
| Benefit se v koši nezobrazuje | Složka nemá vybraný zákonný koš, nebo vstup není schválený | Upravte složku v katalogu a vstup schvalte. |

## 89.6 Podrobnosti a pravidla

### 89.6.1 Roční koš osvobození

Limit osvobození podle zákona o daních z příjmů se nevztahuje na jednu mzdovou
složku, ale na **úhrn všech plnění daného ustanovení za kalendářní rok**.
Aplikace ho drží jako **zákonný koš** vybraný u složky:

- **Zdravotní plnění** (§ 6 odst. 9 písm. d) bod 1): do výše průměrné mzdy,
- **Rekreace, sport a kultura** (§ 6 odst. 9 písm. d) bod 2): do poloviny
  průměrné mzdy,
- **Spoření na stáří a dlouhodobá péče** (§ 6 odst. 9 písm. m)): 50 000 Kč.

Koš se sčítá za osobu u zaměstnavatele, tedy i napříč souběžnými vztahy
a napříč složkami téhož koše, a limit bere z pravidel daně z příjmů účinných
pro daný rok. Náhled vstupu ukáže vyčerpání a zbytek hned, takže se překročení
nezjistí až v prosinci.

Plnění nad limit se **neblokuje**: zákon ho nezakazuje, jen ho zdaňuje.
Nadlimitní část se při schválení vstupu zmrazí zvlášť a vstupuje do výpočtu jako
samostatná zdanitelná složka, započtená do daně i do vyměřovacích základů
sociálního a zdravotního pojištění. Částka přesně na limitu je ještě celá
osvobozená.

**Schválený benefitní vstup nejde přepsat.** Po zápisu do koše se běžná úprava
odmítne, jinak by se totéž plnění mohlo započítat do limitu dvakrát. Opravu
proveďte opačným zápisem a pak zadejte správnou částku. Stornem uvolněná
čerpání do koše nevstupují a přehled je u řádku uvede poznámkou.

**Rok přechodu z jiného mzdového programu.** Příspěvky na spoření na stáří
z měsíců převzatých převodem mezd čerpají roční koš stejně jako vstupy
MyÚčta: náhled vstupu i přehled je započtou do **Vyčerpáno** a přehled u řádku
poznámkou uvede, kolik měsíců a jaká částka jsou převzaté. Rozpad na
osvobozenou a zdaněnou část za převzaté měsíce vedl předchozí program.

Pole **Roční limit** u složky je něco jiného: **vlastní strop zaměstnavatele**,
nad který schválení vstupu neprojde.

### 89.6.2 Měsíční koše

Záložka **Měsíční koše** ukazuje koše podle § 6 odst. 9 písm. b) a i):
**Příspěvek na stravování** a **Přechodné ubytování**. Sčítá se podle období
mzdového vstupu, takže zpětný vstup se započítá měsíci, kterého se týká.

U **přechodného ubytování** je limit měsíční (3 500 Kč), přehled proto ukáže
i zbytek. U **příspěvku na stravování** je limit za **jednu směnu**, kdežto
mzdový vstup je měsíční. Měsíční součet se proti limitu za směnu poměřit nedá,
takže přehled **žádný limit ani zbytek netvrdí** a řekne to poznámkou: údaj
znamená „tolik se za měsíc poskytlo", ne „limit je dodržený". Dodržení limitu za
směnu se hlídá při schválení vstupu proti doloženému počtu směn z docházky.

### 89.6.3 Jak přehled čte čísla

Řádek je jeden na zaměstnance a koš a sčítá se stejně jako náhled vstupu.
Stav řádku: **V limitu**, **Blíží se limitu** (od 80 % koše), **Nad limitem**,
**Neúplný podklad** a **Limit není k dispozici**. Sloupce a hustotu tabulky si
každý uživatel nastaví sám.

Přehled **nic nepřepočítává**: osvobozenou i nadlimitní část čte zmrazenou
z okamžiku schválení vstupu, takže sedí s výplatní páskou. Proto se místo čísla
někdy objeví přiznání, že podklad chybí:

- **Neúplný podklad**: část vstupů je z doby, kdy se koše nezmrazovaly.
  Chybějící rozpad se nedopočítá, přehled přizná počet takových vstupů.
- **Limit není k dispozici**: pro období není schválená sada legislativních
  pravidel, takže se netvrdí limit ani zbytek. Totéž mají řádky příspěvku na
  stravování s limitem za směnu.
- **Rozpor se zmrazeným rozpadem**: limit se v pravidlech po schválení vstupů
  změnil. Čísla zůstávají zmrazená; přepsat je dnešním limitem by přehled
  rozešlo s vyplacenými mzdami.

Přehled je čtecí a běží na základním oprávnění ke mzdám (`payroll`), stejném
jako seznam mzdových vstupů: je to jejich součet za osobu a období, ne nová
třída údajů. Změna filtru ani otevření přehledu nemění mzdový běh.

### 89.6.4 Účetní dopad u zaměstnavatele

Pozor na směr: daňově neuznatelná je **osvobozená** část, ne nadlimitní. § 25
odst. 1 písm. h) zákona o daních z příjmů ve znění od 1. 1. 2024 vylučuje
z nákladů nepeněžní plnění „v rozsahu, ve kterém je u zaměstnance osvobozeno od
daně". Nadlimitní část se zaměstnanci zdaní, a zaměstnavateli proto uznatelná
zůstává (§ 24 odst. 2 písm. j) bod 4).

Mzdový můstek proto osvobozenou část účtuje na samostatný účet nedaňových
nákladů (výchozí 528). Dělí se **jen** u košů **zdravotní plnění** a **rekreace,
sport a kultura** podle § 6 odst. 9 písm. d); příspěvek na stravování, spoření na
stáří a přechodné ubytování jsou uznatelné celé. Předkontaci nastavíte
v [Nastavení mezd](90_Nastaveni_mezd.md#90146-predkontace-pro-zvlastni-mzdove-situace).

Nepeněžní plnění bez vlastní dvojice účtů se do mzdového deníku nezaúčtuje
vůbec: náklad je v knihách už ze zdrojového dokladu a mzdový zápis by ho
zaúčtoval podruhé. Do daně a pojistného přitom vstupuje normálně (viz
[Shoda účtování mezd](81_Shoda_uctovani_mezd.md#8153-ucetne-neutralni-nepenezni-plneni)).

### 89.6.5 Kontroly a časté chyby

Kontrolujte období, osobu, limit, duplicity a zákonnou klasifikaci plnění.
Benefit nepoužívejte k obcházení zdanitelné mzdy. Zdravotní nebo rodinné údaje
související s benefitem evidujte jen v nezbytném rozsahu. Zařazení složky do
JMHZ, pravidelné předpisy a import vstupů popisuje
[Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md#91125-zarazeni-do-jmhz-predpisy-a-import).

Časté chyby:

- čerpání ve špatném kalendářním roce,
- duplicitní doklad ve více koších,
- překročený limit bez správného mzdového dopadu,
- benefit přiřazený ukončenému vztahu bez nároku.

## 89.7 Související kapitoly

- [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md): zařazení složky do koše
- [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md): měsíční hodnoty
- [Mzdové běhy](80_Mzdove_behy.md): výpočet
- [Nastavení mezd](90_Nastaveni_mezd.md): předkontace nedaňové části
