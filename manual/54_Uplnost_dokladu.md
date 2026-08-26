# 54. Úplnost dokladů

**Cesta: `Účetnictví → Úplnost dokladů`**

Úplnost dokladů je pouze čtecí kontrola ve dvou směrech:

1. hledá starší skutečné bankovní pohyby, ke kterým není doložený ani
   zaúčtovaný účetní případ,
2. hledá otevřené pohledávky a závazky po splatnosti.

Sestava nic automaticky nepáruje, nekontuje ani nemění stav dokladu. Je
dostupná jen pro firmu v podvojném účetnictví a pro uživatele s právem číst
účetnictví.

## 54.1 Bankovní pohyby bez dokladu

Výchozí práh je **30 dní**. Lze zadat 0 až 3 650 dní a omezit výsledek na
všechny, příchozí nebo odchozí pohyby. Do výsledku se dostane pohyb, který
splňuje všechny podmínky:

- pochází ze skutečného bankovního výpisu (`source = statement`),
- je stále nespárovaný a nemá `matched_invoice_id`,
- jeho datum je nejpozději v den odpovídající zvolenému prahu,
- není k němu evidována úhrada ve vazbě faktury ani obecné párování platby,
- nemá aktivní účetní zápis se zdrojem banka,
- bankovní účet výpisu bezpečně náleží zvolené firmě.

Ignorované a spárované pohyby, pohyby s evidovanou úhradou a pohyby už
zaúčtované do deníku se nezobrazí. Kontrola se neptá, zda existuje návrh
kontace v Automatu; rozhodující je skutečný doklad, párování a aktivní zápis.

Každý řádek ukazuje datum, stáří, protistranu, popis, částku a stav:

- **Doklad chybí** — není otevřená žádost o tento podklad,
- **Doklad vyžádán** — existuje žádost navázaná na bankovní pohyb ve stavu
  Vyžádáno.

Odkaz **Otevřít výpis** vede na detail bankovního výpisu. Samotný štítek
**Doklad vyžádán** položku neřeší; podklad je potřeba doručit, zkontrolovat a
správně zaúčtovat.

### 54.1.1 Aging a součty

Pohyby jsou rozdělené podle stáří:

- 0–30 dní,
- 31–60 dní,
- 61–90 dní,
- 91–180 dní,
- více než 180 dní.

Souhrn u každého pásma obsahuje počet a korunový součet. Do korunových součtů
se zahrnují pouze pohyby vedené v CZK; cizoměnový pohyb zůstane v seznamu, ale
bez spolehlivého přepočtu se nepřičte k `total_czk`. Součet zachovává znaménko
pohybu, takže odchozí částky mohou souhrn snižovat.

> 🛈 **Pohyb bez faktury nemusí být chyba.** Daně, pojistné, mzdy, bankovní poplatky,
> vlastní převody nebo vklady mohou mít jiný účetní podklad. Kontrola říká, že
> z dostupných vazeb nevidí doklad ani zaúčtování; neurčuje daňovou uznatelnost.

## 54.2 Doklady po splatnosti bez plné úhrady

Druhá část používá stejné historické saldokonto jako účetní sestava:

- účet **311** pro vydané faktury a pohledávky,
- účet **321** pro přijaté faktury a závazky.

Ke dni spuštění vypočte pro každou otevřenou položku:

1. účetně zachycenou částku orientovanou na normální stranu účtu,
2. poměr zaplacení z doložených úhrad a storen,
3. zbývající korunovou částku,
4. počet dní po splatnosti.

Plně vyrovnaná položka v haléřích se vynechá. Doklad se splatností dnes ještě
není po splatnosti. Výsledek je seřazený od nejstaršího prodlení a každý řádek
vede na detail vydané nebo přijaté faktury.

Částka **Zbývá uhradit** je účetní zůstatek v CZK, ne původní nominální částka
v měně dokladu. Záporná otevřená položka může znamenat přeplatek nebo dobropis
a musí se posoudit podle detailu saldokonta.

## 54.3 Doporučený postup

1. Nastavte práh podle interního rytmu předávání dokladů; pro měsíční práci je
   obvykle vhodných 30 dní, před uzávěrkou lze použít kratší interval.
2. Projděte zvlášť odchozí a příchozí bankovní pohyby.
3. U chybějícího podkladu ověřte, zda již není v Dokumentech nebo mezi
   přijatými fakturami; případně jej vyžádejte.
4. U oprávněné platby bez faktury doložte jiný průkazný podklad a zkontujte ji
   podle povahy operace.
5. U dokladů po splatnosti zkontrolujte skutečné úhrady, částečné platby,
   dobropisy, zápočty a kurzové rozdíly. Neměňte pouze štítek stavu dokladu,
   pokud by přestal odpovídat deníku.
6. Po opravách sestavu načtěte znovu a pokračujte
   [Měsíční kontrolou](55_Mesicni_kontrola.md).

## 54.4 Rozdíl proti K doúčtování

[K doúčtování](47_Rucni_fronta_doctovani.md) zahrnuje všechny aktuálně
nezaúčtované vydané a přijaté doklady a banku bez návrhu bez ohledu na stáří.
Úplnost dokladů naproti tomu:

- aplikuje na banku nastavitelný časový práh,
- vyžaduje současně absenci dokladu, párování i aktivního bankovního zápisu,
- přidává opačný pohled na doklady po splatnosti z účtů 311 a 321,
- nerozlišuje, zda pro bankovní pohyb existuje čekající návrh v Automatu.

Prázdný výsledek neprokazuje, že v účetnictví nechybí doklad, který do systému
vůbec nevstoupil. Je to technická kontrola úplnosti vazeb nad dostupnými daty,
nikoli úplná inventura účetních případů.

## 54.5 Úplnost číselných řad vydaných dokladů

Samostatná stránka **Daně → Úplnost číselných řad** hledá mezery v číslování
vydaných **faktur a dobropisů**. Zvol rok a u každé nalezené řady uvidíš období,
rozsah od prvního do nejvyššího použitého pořadového čísla, počet použitých čísel
a konkrétní chybějící čísla.

Kontrola respektuje nastavení číslování firmy i vlastní šablony jednotlivých
odběratelů:

- měsíční řady vyhodnotí samostatně po měsících, roční po roce,
- řadu bez resetu kontroluje přes celou historii bez ohledu na rok vybraný na
  stránce,
- pokud faktury a dobropisy skutečně používají shodnou číselnou kostru, posuzuje
  je jako jednu sdílenou řadu, aby číslo použité dobropisem nevypadalo jako mezera
  faktur,
- šablonu bez pořadového zástupného symbolu nelze tímto způsobem zkontrolovat a
  sestava ji neuvádí.

Sestava je pouze čtecí a čísla sama nedoplňuje ani nepřečísluje. Mezera je signál
k prověření: dohledávej například smazaný koncept, ručně změněné číslo nebo chybnou
šablonu. Stornovaný doklad, který v systému zůstal s přiděleným číslem, mezeru
nevytváří. Výsledek kontroly řad je nezávislý na bankovním párování a na dvou
kontrolách popsaných výše.
