# 61. Úplnost dokladů

> Kontrola ve dvou směrech: které starší bankovní pohyby nemají doložený doklad a které otevřené pohledávky a závazky jsou po splatnosti. Pro účetní, která chce mít před uzávěrkou jistotu, že k bance existují podklady. Součástí je i kontrola mezer v číslování vydaných faktur a dobropisů.

## 61.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se blíží konec měsíce a chcete mít k bankovním pohybům doklady,
- vám v zaúčtovaném účetnictví nesedí banka s doklady,
- potřebujete seznam dokladů po splatnosti k upomínkám,
- chcete ověřit, že vydané faktury mají souvislou číselnou řadu.

Sestava je pouze čtecí. Nic automaticky nepáruje, nezaúčtuje ani nemění stav dokladu.

### 61.1.1 Kdy co udělat

<!-- cols: 24 46 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| měsíčně před DPH | Projít bankovní pohyby bez dokladu | `Účetnictví → Úplnost dokladů`, [§ 61.3](#613-krok-za-krokem-bankovni-pohyby-bez-dokladu) |
| měsíčně a před upomínkami | Projít doklady po splatnosti | stejná stránka, [§ 61.4](#614-krok-za-krokem-doklady-po-splatnosti) |
| ročně a při podezření | Zkontrolovat mezery v číslování | `Daně → Úplnost číselné řady`, [§ 61.5](#615-krok-za-krokem-uplnost-ciselnych-rad-vydanych-dokladu) |

## 61.2 Než začnete

1. **Účetní režim a právo číst účetnictví.** Sestava je dostupná firmě v podvojném účetnictví i v daňové evidenci (tam v menu `Daňová evidence → Úplnost dokladů`) a uživateli s právem číst účetnictví. Rozdíly v daňové evidenci popisuje [§ 61.7.5](#6175-danova-evidence).
2. **Naimportované bankovní výpisy.** Kontrola pracuje jen se skutečnými pohyby z výpisu. Provizorní avíza se nepočítají. Výpisy nahrajte v `Peníze → Banka`.
3. **Zaúčtované úhrady a párování.** Čím víc plateb máte spárovaných, tím kratší seznam uvidíte.

## 61.3 Krok za krokem: bankovní pohyby bez dokladu

1. Otevřete `Účetnictví → Úplnost dokladů`.
2. V poli **Práh (dní)** nastavte stáří pohybů. Pro měsíční práci je obvykle vhodných 30 dní, před uzávěrkou lze použít kratší interval. Lze zadat 0 až 3 650 dní.
3. V nabídce zvolte **Příjmy i výdaje**, **Jen výdaje** nebo **Jen příjmy**. Projděte zvlášť odchozí a příchozí pohyby.
4. V bloku **Bankovní pohyby bez dokladu** projděte řádky. U každého vidíte datum, stáří, protistranu, popis, částku a stav.
5. U stavu **Chybí doklad** ověřte, zda podklad už není v Dokumentech nebo mezi přijatými fakturami. Jinak ho vyžádejte od klienta.
6. U stavu **Doklad vyžádán** podklad doručte, zkontrolujte a správně zaúčtujte. Štítek sám položku neřeší.
7. Klikněte na **Otevřít výpis**. Otevře se detail bankovního výpisu, kde pohyb spárujete s dokladem nebo zaúčtujete.
8. U oprávněné platby bez faktury (daně, pojistné, mzdy, poplatky, vlastní převody, vklady) doložte jiný průkazný podklad a zaúčtujte ji podle povahy operace.
9. Po opravách stránku načtěte znovu.

**Jak poznáte, že je hotovo:** Blok zobrazí **Žádné bankovní pohyby bez dokladu nad zvoleným prahem.** Pokračujte [Měsíční kontrolou](62_Mesicni_kontrola.md).

> [!TIP]
> Pohyb bez faktury nemusí být chyba. Kontrola říká, že z dostupných vazeb nevidí doklad ani zaúčtování. Neurčuje daňovou uznatelnost. Náklad bez dokladu však není daňově uznatelný (§ 24 odst. 1 ZDP).

## 61.4 Krok za krokem: doklady po splatnosti

1. Na téže stránce přejděte k bloku **Doklady bez úhrady po splatnosti**.
2. Projděte seznam, který je seřazený od nejstaršího prodlení. Sloupec **Zbývá (CZK)** ukazuje účetní zůstatek v korunách.
3. Klikněte na **Otevřít doklad**. Otevře se detail vydané nebo přijaté faktury.
4. Zkontrolujte skutečné úhrady, částečné platby, dobropisy, zápočty a kurzové rozdíly.
5. Chybějící úhradu zaúčtujte (spárujte s bankovním pohybem). Pohledávky předejte k upomínce, případně k zápočtu.
6. Po opravách stránku načtěte znovu.

**Jak poznáte, že je hotovo:** Blok zobrazí **Žádné doklady po splatnosti bez úhrady.**, nebo zůstávají jen doklady, u nichž je důvod prodlení znám.

> [!WARNING]
> Neměňte pouze štítek stavu dokladu, pokud by přestal odpovídat deníku. Záporná otevřená položka může znamenat přeplatek nebo dobropis a musí se posoudit podle detailu v [Saldokontu](60_Saldokonto.md).

## 61.5 Krok za krokem: úplnost číselných řad vydaných dokladů

Samostatná stránka hledá mezery v číslování vydaných faktur a dobropisů.

1. Otevřete `Daně → Úplnost číselné řady`.
2. Zvolte rok.
3. U každé nalezené řady zkontrolujte období, rozsah od prvního do nejvyššího použitého pořadového čísla, počet použitých čísel a konkrétní chybějící čísla.
4. Každou mezeru prověřte. Dohledávejte například smazaný koncept, ručně změněné číslo nebo chybnou šablonu číslování.

**Jak poznáte, že je hotovo:** Žádná řada nemá chybějící čísla, nebo je každá mezera vysvětlená.

> [!TIP]
> Stornovaný doklad, který v systému zůstal s přiděleným číslem, mezeru nevytváří. Sestava čísla sama nedoplňuje ani nepřečísluje.

## 61.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Pohyb je v seznamu, ale doklad máte | Pohyb není spárovaný s dokladem | Klikněte na **Otevřít výpis** a pohyb spárujte. |
| Pohyb ze seznamu zmizel, přestože jste nic nespárovali | Byl zaúčtovaný do deníku jako banka nebo ignorovaný | Nejde o chybu. Spárované, ignorované a zaúčtované pohyby se nezobrazují. |
| Cizoměnový pohyb se nepřičetl do součtu pásma | Součty zahrnují jen pohyby vedené v CZK | Posuďte ho podle seznamu, ne podle součtu. |
| Nad tabulkou je upozornění, že je položek příliš mnoho | Z každého z účtů 311 a 321 se načte nejvýše 5 000 otevřených položek | Úplný přehled dá [Saldokonto](60_Saldokonto.md) se zúženým výběrem. |
| Prázdný výsledek, ale doklad v účetnictví chybí | Kontrola vidí jen to, co je v systému | Prázdný výsledek neprokazuje, že nechybí doklad, který do systému vůbec nevstoupil. |
| Řada nemá šablonu s pořadovým číslem | Takovou šablonu nelze kontrolovat | Sestava ji neuvádí. |

## 61.7 Podrobnosti a pravidla

### 61.7.1 Bankovní pohyby bez dokladu

Výchozí práh je **30 dní**. Lze zadat 0 až 3 650 dní a omezit výsledek na
všechny, příchozí nebo odchozí pohyby. Do výsledku se dostane pohyb, který
splňuje všechny podmínky:

- pochází ze skutečného bankovního výpisu,
- je stále nespárovaný a není spárovaný s žádnou fakturou,
- jeho datum je nejpozději v den odpovídající zvolenému prahu,
- není k němu evidována úhrada ve vazbě faktury ani obecné párování platby,
- nemá aktivní účetní zápis se zdrojem banka,
- bankovní účet výpisu bezpečně náleží zvolené firmě.

Ignorované a spárované pohyby, pohyby s evidovanou úhradou a pohyby už
zaúčtované do deníku se nezobrazí. Kontrola se neptá, zda existuje návrh
kontace v Automatu; rozhodující je skutečný doklad, párování a aktivní zápis.

Každý řádek ukazuje datum, stáří, protistranu, popis, částku a stav:

- **Chybí doklad** - není otevřená žádost o tento podklad,
- **Doklad vyžádán** - existuje žádost navázaná na bankovní pohyb ve stavu
  Vyžádáno.

Odkaz **Otevřít výpis** vede na detail bankovního výpisu. Samotný štítek
**Doklad vyžádán** položku neřeší; podklad je potřeba doručit, zkontrolovat a
správně zaúčtovat.

#### 61.7.1.1 Aging a součty

Pohyby jsou rozdělené podle stáří:

- do 30 dní,
- 31-60 dní,
- 61-90 dní,
- 91-180 dní,
- nad 180 dní.

Souhrn u každého pásma obsahuje počet a korunový součet. Do korunových součtů
se zahrnují pouze pohyby vedené v CZK; cizoměnový pohyb zůstane v seznamu, ale
bez spolehlivého přepočtu se nepřičte k součtu v CZK. Součet zachovává znaménko
pohybu, takže odchozí částky mohou souhrn snižovat.

> [!TIP]
> **Pohyb bez faktury nemusí být chyba.** Daně, pojistné, mzdy, bankovní poplatky,
> vlastní převody nebo vklady mohou mít jiný účetní podklad. Kontrola říká, že
> z dostupných vazeb nevidí doklad ani zaúčtování; neurčuje daňovou uznatelnost.

### 61.7.2 Doklady po splatnosti bez plné úhrady

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

Částka ve sloupci **Zbývá (CZK)** je účetní zůstatek v CZK, ne původní nominální částka
v měně dokladu. Záporná otevřená položka může znamenat přeplatek nebo dobropis
a musí se posoudit podle detailu saldokonta.

Z každého z obou účtů se načte nejvýše 5 000 otevřených položek. Pokud jich je
víc, obrazovka to napíše nad tabulkou a seznam i jeho součet pak platí jen za
načtenou nejstarší část - úplný přehled dá [Saldokonto](60_Saldokonto.md) se
zúženým výběrem.

### 61.7.3 Rozdíl proti K doúčtování

[K doúčtování](54_Rucni_fronta_doctovani.md) zahrnuje všechny aktuálně
nezaúčtované vydané a přijaté doklady a banku bez návrhu bez ohledu na stáří.
Úplnost dokladů naproti tomu:

- aplikuje na banku nastavitelný časový práh,
- vyžaduje současně absenci dokladu, párování i aktivního bankovního zápisu,
- přidává opačný pohled na doklady po splatnosti z účtů 311 a 321,
- nerozlišuje, zda pro bankovní pohyb existuje čekající návrh v Automatu.

Prázdný výsledek neprokazuje, že v účetnictví nechybí doklad, který do systému
vůbec nevstoupil. Je to technická kontrola úplnosti vazeb nad dostupnými daty,
nikoli úplná inventura účetních případů.

### 61.7.5 Daňová evidence

Daňová evidence musí příjmy a výdaje doložit stejně (§ 7b odst. 1 ZDP), jen nemá
deník ani saldokonto. Kontrola proto bere vazby jinak:

- **Bankovní pohyb má doklad**, když je spárovaný s vydanou nebo přijatou fakturou,
  přiřazený k ostatní pohledávce nebo závazku, spárovaný se zálohou na daň či
  pojistné nebo se mzdou, případně ručně zařazený v peněžním deníku jako
  **Soukromé** nebo **Převod**. Ostatní ruční zařazení doklad nenahrazuje.
- **Doklady po splatnosti** jsou neuhrazené vydané a přijaté faktury a potvrzené
  ostatní pohledávky a závazky, u splátkového kalendáře po jednotlivých splátkách.
  Zdroj je stejný jako v přehledu `Daňová evidence → Pohledávky a závazky`.
  Sloupec účtu zůstává prázdný a cizoměnové doklady se přepočítají kurzem dokladu.

### 61.7.4 Úplnost číselných řad vydaných dokladů (pravidla)

Samostatná stránka **Daně → Úplnost číselné řady** hledá mezery v číslování
vydaných **faktur a dobropisů**. Zvolte rok a u každé nalezené řady uvidíte období,
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
k prověření: dohledávejte například smazaný koncept, ručně změněné číslo nebo chybnou
šablonu. Stornovaný doklad, který v systému zůstal s přiděleným číslem, mezeru
nevytváří. Výsledek kontroly řad je nezávislý na bankovním párování a na dvou
kontrolách popsaných výše.

## 61.8 Související kapitoly

- [Měsíční kontrola](62_Mesicni_kontrola.md) - navazující kontrolní brána
- [K doúčtování](54_Rucni_fronta_doctovani.md) - nezaúčtované doklady a banka bez návrhu
- [Saldokonto](60_Saldokonto.md) - úplný přehled otevřených položek
- [Automat](53_Automat.md) - bankovní návrhy
- [Průvodce účetního](50_Pruvodce_ucetniho.md)
