# Koše benefitů

## 72.1 Účel

Koše benefitů jsou čtecím zákonným přehledem, který seskupuje schválená plnění a ukazuje jejich čerpání a mzdové zacházení v určeném období. Zákonné koše se nezakládají ručně; plnění do nich zařadí klasifikace použité mzdové složky.

## 72.2 Předpoklady a oprávnění

Musí existovat zaměstnanec, vztah a schválený mzdový vstup se složkou zařazenou do příslušného zákonného koše. Zákonné limity načítá aplikace z účinného rulesetu; vlastní firemní strop lze nastavit u mzdové složky.

## 72.3 Krokový postup

1. V katalogu mzdových složek ověřte zákonné zařazení benefitu a případný vlastní firemní limit.
2. Benefit zadejte zaměstnanci jako mzdový vstup a schvalte jeho podklad.
3. Otevřete **Mzdy → Koše benefitů** a vyberte rok nebo měsíc, koš a zaměstnance.
4. Zkontrolujte vyčerpanou, osvobozenou a nadlimitní částku i případné upozornění na neúplný podklad.
5. Před mzdovým během porovnejte souhrn se zdrojovými doklady; přehled sám nic nepřepočítává ani nezapisuje.

## 72.4 Stavy

Přehled rozlišuje stav v limitu, blížící se limitu, nad limitem a neúplný podklad. Částky čte ze schválených zmrazených vstupů; změna filtru ani otevření přehledu nemění mzdový běh.

## 72.5 Kontroly a bezpečnost

Kontrolujte období, osobu, limit, duplicity a zákonnou klasifikaci plnění. Benefit nepoužívejte k obcházení zdanitelné mzdy. Zdravotní nebo rodinné údaje související s benefitem evidujte pouze v nezbytném rozsahu.

## 72.6 Časté chyby

- Čerpání ve špatném kalendářním roce.
- Duplicitní doklad ve více koších.
- Překročený limit bez správného mzdového dopadu.
- Benefit přiřazený ukončenému vztahu bez nároku.

## 72.7 Návaznosti

Význam mzdového dopadu nastavují [složky](74_Mzdove_slozky_a_vstupy.md), měsíční hodnotu lze zkontrolovat v [rychlém vstupu](62_Rychly_mesicni_vstup.md) a vypočtený výsledek v [mzdovém běhu](63_Mzdove_behy.md).



## 72.8 Podrobný pracovní postup a kontroly

### 72.8.1 Roční koš osvobození benefitů

U nepeněžních benefitů se limit osvobození podle zákona o daních z příjmů
nevztahuje na jednu mzdovou složku, ale na **úhrn všech plnění daného
ustanovení za kalendářní rok**. Aplikace to drží jako **zákonný koš**, který se
u složky vybírá v katalogu:

- **zdravotní plnění** (§ 6 odst. 9 písm. d) bod 1) — do výše průměrné mzdy;
- **rekreace, sport a kultura** (§ 6 odst. 9 písm. d) bod 2) — do poloviny
  průměrné mzdy;
- **spoření na stáří a dlouhodobá péče** (§ 6 odst. 9 písm. m)) — 50 000 Kč.

Koš se sčítá za osobu u zaměstnavatele, tedy i napříč souběžnými pracovními
vztahy, a částku limitu bere z rulesetu daně z příjmů účinného pro daný rok.
Náhled mzdového vstupu ukazuje, kolik z koše je po tomto plnění vyčerpáno a
kolik zbývá — překročení se tedy nezjistí až v prosinci.

Plnění nad limit se **neblokuje**: zákon ho nezakazuje, jen ho zdaňuje.
Nadlimitní část se při schválení vstupu zmrazí zvlášť a do výpočtu vstupuje jako
samostatná zdanitelná složka, která se započítá do daně i do vyměřovacích
základů sociálního a zdravotního pojištění. Částka přesně na limitu je ještě
celá osvobozená.

Pole **Roční limit** u složky je něco jiného — je to **vlastní strop
zaměstnavatele** a schválení vstupu nad něj neprojde.

#### Přehled čerpání košů za firmu

Náhled vstupu ukáže koš jen tomu, kdo ten vstup zrovna zadává. Souhrn za celou
firmu je v **Mzdy → Koše benefitů**: jeden řádek na zaměstnance a koš,
s vyčerpanou částkou, limitem, zbytkem a s tím, kolik se už zdanilo jako
nadlimitní. Filtruje se podle období, koše a jména; sloupce a hustotu
tabulky si každý uživatel nastaví sám.

Obrazovka má dvě záložky podle toho, za jaké období zákon limit dává:

- **Roční koše** — zdravotní plnění, rekreace a spoření na stáří (§ 6 odst. 9
  písm. d) a m) ZDP). Filtruje se zdaňovacím obdobím.
- **Měsíční koše** — příspěvek na stravování a přechodné ubytování (písm. b)
  a i)). Filtruje se konkrétním měsícem a sčítá se podle období mzdového vstupu,
  takže zpětný vstup se započítá tomu měsíci, kterého se týká.

U **přechodného ubytování** je limit měsíční (3 500 Kč), takže se proti
měsíčnímu součtu poměřit dá a přehled u něj ukáže i zbytek. U **příspěvku na
stravování** je ale limit podle zákona za **jednu směnu**, kdežto mzdový vstup je
měsíční. Měsíční součet se proti limitu za směnu porovnat nedá, takže u takového
řádku přehled **žádný limit ani zbytek netvrdí** a řekne to poznámkou: údaj
znamená „tolik se za měsíc poskytlo", ne „limit je dodržený". Dodržení limitu za
směnu se hlídá při schválení vstupu proti doloženému počtu směn z docházky.

Řádky se sčítají za osobu u zaměstnavatele, tedy i napříč souběžnými pracovními
vztahy a napříč mzdovými složkami téhož koše — stejně, jako to počítá náhled
vstupu. Stav řádku říká, jestli je osoba v limitu, blíží se mu (od 80 % koše),
nebo je nad ním.

Přehled **nic nepřepočítává**: osvobozenou i nadlimitní část čte zmrazenou
z okamžiku schválení vstupu, takže sedí s výplatní páskou. Proto se u některých
řádků objeví místo čísla přiznání, že podklad chybí:

- **Neúplný podklad** — část vstupů je z doby, kdy se koše ještě nezmrazovaly.
  Chybějící rozpad se nedopočítá, jen se přizná počet takových vstupů.
- **Limit není k dispozici** — pro zvolené období není schválená sada
  legislativních pravidel, takže se netvrdí ani limit, ani zbývající částka.
  Týž stav mají v měsíční záložce řádky příspěvku na stravování, u nichž je limit
  za směnu, a měsíční součet se proti němu poměřit nedá.
- Poznámka o **rozporu se zmrazeným rozpadem** znamená, že se limit v pravidlech
  po schválení vstupů změnil. Zobrazená čísla zůstávají zmrazená; přepsat je
  dnešním limitem by přehled rozešlo s už vyplacenými mzdami.

Přehled je čtecí a jede na oprávnění `payroll`, tedy stejné, jaké má seznam
mzdových vstupů — je to jejich součet za osobu a období, ne nová třída údajů.

U složky zahrnuté do JMHZ nastav také konkrétní cílový atribut měsíčního
hlášení. Stav **Chybí mapování** nebrání výpočtu mzdy, ale znamená, že složku
zatím nelze bezpečně převést do úplného JMHZ. Celkové cíle používej jen pro
částky, které nelze přesně zařadit do detailního rozpadu; aplikace je proto
viditelně odlišuje. Mapování lze auditovatelně deaktivovat a teprve potom lze
složku z JMHZ vyloučit nebo převést do ručního posouzení. Samotné mapování
nevytváří XML ani nic neodesílá na ČSSZ.

Pravidelný předpis má vlastní interval platnosti a lze jej zadat pevnou částkou
nebo procentem. Účty MD/D se vybírají našeptáváním z aktivního účtového rozvrhu;
formulář nezadává interní identifikátory. Procentní sazbu zadávej jako běžné
procento a množství v přirozené jednotce — převod na interní bazické body
a tisíciny provede aplikace. Jednorázový vstup se nejprve zkontroluje a potom samostatně
schválí. Import odmítá nebezpečné sešity, vzorce a duplicitní řádky a před
zápisem vždy ukáže výsledek náhledu. Soubor můžeš vybrat fialovým tlačítkem
**Vybrat soubor** nebo jej přetáhnout do zvýrazněné plochy; stejný ovládací
prvek používá také import docházky. Přijímá CSV a XLSX do 5 MB a chybu zobrazí
přímo u souboru.
