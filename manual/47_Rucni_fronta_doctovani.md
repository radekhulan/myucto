# 47. K doúčtování

**Cesta: `Účetnictví → K doúčtování`**

K doúčtování je společný, pouze čtecí seznam známých případů, u kterých ještě
chybí dokončení účetní práce. Spojuje skutečné bankovní pohyby bez jakéhokoli
návrhu kontace, nezaúčtované vydané a přijaté doklady a otevřené žádosti o
chybějící podklad.

Stránka sama nic nezaúčtuje, nespáruje ani neopraví. Každý řádek vede do
agendy, ve které se případ skutečně vyřeší.

> ⚠️ **Bankovní návrhy patří do Automatu.** Čekající návrhy se zde záměrně
> nezobrazují, a to ani odložené
> návrhy nebo návrhy ve stavu **Potřebuje doplnit**. Jakmile pro pohyb existuje
> návrh v jakémkoli stavu, patří do [Automatu](46_Automat.md). K doúčtování
> odpovídá na otázku „pro co automatika nemá hotový účetní výsledek nebo
> nevytvořila vůbec žádný návrh?“.

## 47.1 Co se do fronty zařazuje

### 47.1.1 Bankovní pohyb bez návrhu

Jde o skutečný pohyb importovaný z výpisu, který:

- není ignorovaný,
- nemá aktivní účetní zápis typu banka,
- a nemá žádný návrh bankovní kontace.

Korunový pohyb dostane důvod **Nenalezeno pravidlo** a akci pro vytvoření
pravidla nebo ruční zaúčtování. Pohyb v jiné měně dostane důvod
**Cizoměnová operace není podporovaná automatikou** a vede k ručnímu
zaúčtování na detailu výpisu.

Provizorní e-mailová avíza se do fronty nezařazují. Nejsou skutečným pohybem
z výpisu a nikdy se neúčtují.

### 47.1.2 Nezaúčtovaný vydaný doklad

Zařazují se doklady bez `booked_at`, které nejsou koncept ani stornované a
patří mezi postovatelné typy:

- faktura,
- dobropis,
- daňový doklad,
- penále.

Řádek vede na detail vydaného dokladu. Tam zkontrolujte položky, DPH, datum
účetního případu a předkontaci a teprve potom použijte **Zaúčtovat**.

### 47.1.3 Nezaúčtovaný přijatý doklad

Zařazují se přijaté doklady bez `booked_at`, které nejsou koncept ani
stornované. Zálohová výzva (`document_kind = advance`) se nezobrazuje:
nevytváří předpis na 321, účetně se projeví až skutečnou úhradou na 314 a
následným vyúčtováním.

Částka přijatého dokladu je ve frontě zobrazena záporně, aby byl na první
pohled odlišen výdajový směr.

### 47.1.4 Otevřená žádost o dokument

Zařazuje se žádost ve stavu **Vyžádáno**. Řádek může nést datum případu,
částku, protistranu, vlastní popis a termín dodání. Odkaz vede do přehledu
žádostí o dokumenty. Vyřešená žádost z fronty zmizí.

## 47.2 Filtry, pořadí a stránkování

Frontu lze filtrovat podle:

- **typu** — banka bez návrhu, přijatý doklad, vydaný doklad nebo žádost o
  dokument,
- **důvodu** — například bez pravidla, nepodporovaná cizí měna, doklad není
  zaúčtovaný nebo dokument chybí.

Počty u typů a důvodů se počítají z celé aktuální fronty ještě před filtrováním.
Řádky jsou seřazené od nejnovějšího data, při shodě podle interního
identifikátoru. Na stránce je standardně 50 položek; server dovoluje nejvýše
200 na stránku.

| Typ řádku | Kam vede | Co udělat |
|---|---|---|
| Banka bez návrhu | Detail bankovního výpisu s filtrem nezaúčtovaných pohybů | Dohledat význam, připojit doklad, spárovat nebo ručně zkontovat; opakovanou operaci lze pokrýt pravidlem. |
| Vydaný doklad | Detail faktury | Ověřit předpis, období a DPH a doklad zaúčtovat. |
| Přijatý doklad | Detail přijaté faktury | Ověřit věcnou a daňovou klasifikaci, předkontaci, období a doklad zaúčtovat. |
| Žádost o dokument | Žádosti o dokumenty | Podklad získat, zkontrolovat a žádost vyřešit; samotné doručení ještě neprokazuje správnou kontaci. |

## 47.3 Rozdíl proti Automatu a Úplnosti dokladů

| Stránka | Hlavní otázka | Provádí akce? |
|---|---|---|
| **Automat** | Co systém zaúčtoval, co navrhl a co v návrhu potřebuje rozhodnutí? | Ano — schválení, zamítnutí, úprava kontace, odložení a storno. |
| **K doúčtování** | Který známý případ nemá hotový zápis a není už obsloužen návrhem? | Ne — pouze vede na zdroj. |
| **Úplnost dokladů** | Které starší bankovní pohyby nemají doklad a které otevřené doklady jsou po splatnosti? | Ne — jde o kontrolní sestavu s agingem a saldokontem. |

Stejný nezaúčtovaný doklad může být vidět v K doúčtování i na kartě
**Potřebuje doplnit** v Automatu. Není to dvojí účetní případ; jde o dva různé
pracovní pohledy nad stejným stavem. Bankovní pohyb s čekajícím návrhem je
naopak pouze v Automatu.

## 47.4 Doporučený pracovní postup

1. Nejprve vyřešte žádosti o chybějící dokumenty s blízkým nebo prošlým
   termínem.
2. U banky bez návrhu ověřte, zda nejde o poplatek, daň, pojistné, výplatu,
   vlastní převod nebo platbu k dokladu.
3. U faktur otevřete detail, zkontrolujte správné období, DPH a předkontaci a
   vytvořte zápis.
4. Vraťte se do fronty. Položka zmizí až podle skutečného živého stavu zdroje,
   nikoli ručním odškrtnutím v této stránce.
5. Nakonec projděte [Úplnost dokladů](54_Uplnost_dokladu.md), protože může
   najít starý bankovní pohyb bez podkladu i v situaci, kdy K doúčtování působí
   prázdně.

## 47.5 Oprávnění a hranice kontroly

Stránka je dostupná jen firmě v podvojném účetnictví a vyžaduje právo číst
účetnictví. Její API je tenantově omezené na právě zvolenou firmu. Možnost
provést navazující akci se řídí oprávněním cílové agendy; uživatel pouze pro
čtení může frontu a zdroje prohlížet, ale nemůže je zaúčtovat.

Prázdná fronta znamená jen to, že systém neeviduje žádný známý případ podle
výše uvedených predikátů. Neodhalí fakturu, smlouvu, závazek, majetek, dohad
ani časové rozlišení, které v aplikaci vůbec nemá zdrojová data. Nenahrazuje
inventarizaci, saldokonto ani odborné posouzení účetní.

Prázdný stav nabízí přímé pokračování do bankovní záložky **K zaúčtování** a do
**Automatu**. Použij je zejména tehdy, když víš o bankovním pohybu, který ve frontě
není: pravděpodobně už k němu existuje návrh kontace a patří do jedné z těchto
agend.
