# 43. Průvodce účetního

Tahle kapitola není popis jedné obrazovky — je to **mapa a doporučený postup**
napříč celou aplikací z pohledu účetní/účetního, který v MyÚčto.cz vede
podvojné účetnictví jedné nebo víc firem. Ostatní kapitoly popisují jednotlivé
stránky do detailu; tahle kapitola říká, **kdy na kterou z nich sáhnout** a v
jakém pořadí věci na sebe navazují — od jednoho dokladu až po roční závěrku.

Pokud vedeš **daňovou evidenci** místo podvojného účetnictví, sekce menu
**Účetnictví** se ti nezobrazí — místo ní máš **Daňová evidence**
([§ 51](90_Danova_evidence.md): peněžní deník, pohledávky a závazky). Tahle
kapitola je pro režim **podvojné účetnictví** (`double_entry`).

## 43.1 Než začneš — co je „zaúčtováno" a proč na tom všechno stojí

Vydaná i přijatá faktura, bankovní/pokladní pohyb i majetkový doklad mohou
existovat v aplikaci **bez zápisu v účetním deníku** — vystavení dokladu a
jeho zaúčtování jsou dva oddělené kroky. Dokud doklad není zaúčtovaný,
nepromítne se do hlavní knihy, výsledovky, rozvahy ani do předvahy pro DPH
kontrolu.

- Na detailu vydané faktury ([§ 14](14_Faktury.md)) i přijaté faktury
  ([§ 23](23_Prijate_faktury.md)) je tlačítko **Zaúčtovat** — vytvoří zápis
  podle [předkontace](88_Ucetni_nastroje.md#883-predkontace)
  a doklad dostane účetní ikonu **Zaúčtováno** s tooltipem a odkazem na zápis v deníku.
- Badge **Nezaúčtováno** vidíš přímo v seznamech faktur (filtr **Zaúčtování**
  ve FilterBar) i na dlaždici **Akce k řešení** na [Přehledu](10_Prehled.md) —
  to je tvůj denní vstupní bod, kolik dokladů ještě čeká na zaúčtování.
- Když zaúčtování selže, aplikace vrátí srozumitelnou chybu místo tichého
  selhání — přehled chybových hlášek a jak je opravit viz
  [§ 16.1.3 Zaúčtování do deníku](16_Faktura_PDF.md#1613-zauctovani-do-deniku)
  a [ochrany účtu při zaúčtování](81_Ucetni_osnova.md#816-ochrany-pri-uctovani).
- U banky ([§ 28](29_Bankovni_ucty.md#298-automaticke-zauctovani-bankovnich-transakci-jen-podvojne-ucetnictvi))
  a párovaných plateb ([§ 28.7](28_Banka.md#287-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi))
  lze zaúčtování z velké části zautomatizovat pravidly — ušetří to ruční
  zaúčtování běžných plateb.

## 43.2 Denní cyklus

1. **[Přehled](10_Prehled.md)** → dlaždice **Akce k řešení** ukáže nejdůležitější
   termíny a nehotové doklady.
2. **K doúčtování** ([samostatná kapitola](47_Rucni_fronta_doctovani.md))
   je pracovní fronta napříč bankou, vydanými a přijatými fakturami a vyžádanými
   doklady. Začni nejstaršími položkami a důvody označenými jako blokované nebo
   bez pravidla.
3. **Přijaté faktury** ([§ 23](23_Prijate_faktury.md)) — zkontroluj výsledek AI
   extrakce a navržený druh výdaje. Návrh je pomůcka; účet, daňovou uznatelnost,
   DPH a případné zařazení do majetku potvrzuje účetní.
4. **Banka** ([§ 27](28_Banka.md)) — potvrď jednoznačná párování, vyřeš
   rozúčtované a cizoměnové pohyby a položky, pro které nevznikl návrh. Automatika
   zaúčtuje jen operace povolené firemní politikou; ostatní ponechá ke schválení.
5. **[Automat](46_Automat.md)** — v kokpitu rozlišuj **Zaúčtováno automaticky**,
   **Čeká na potvrzení** a **Potřebuje doplnit**. U každého návrhu je dostupné
   vysvětlení zdroje pravidla a náhled kontace. Hromadně potvrzuj jen stejnorodé
   položky, jejichž dopad jsi zkontroloval(a).
6. **Účetní deník** ([§ 45.2](45_Ucetni_denik.md#452-seznam-zapisu)) — filtr
   **Koncept** ukáže rozpracované ruční zápisy. Oprava zaúčtovaného zápisu se
   provádí auditovanou změnou povolených údajů, stornem nebo opravou zdrojového
   dokladu, ne přepisem účetní historie.

> [!IMPORTANT]
> Prázdná fronta neznamená, že je účetnictví věcně správné. Systém umí poznat
> chybějící zaúčtování a řadu technických nesouladů, ale neumí bez podkladu
> rozhodnout například o daňové uznatelnosti, období nákladu, existenci závazku,
> tvorbě opravné položky nebo správnosti odhadu dohadné položky.

## 43.3 Měsíční cyklus

1. Dokonči [**K doúčtování**](47_Rucni_fronta_doctovani.md) a projdi
   [**Úplnost dokladů**](54_Uplnost_dokladu.md). Pohyb bez
   dokladu není automaticky náklad; vyžádej podklad, nebo účetně dolož, proč je
   zaúčtován bez něj.
2. **Mzdová rekapitulace** — zkontroluj zaměstnance, měsíční vstupy a náhled
   předpisu. Systém vypočte rekapitulaci a připraví kontaci; účetní odpovídá za
   správnost vstupů, zvláštní režimy a shodu s podklady mzdové agendy.
3. [**Měsíční kontrolu**](55_Mesicni_kontrola.md) spusť
   před DPH a po dokončení měsíce. Výsledek je kontrolní seznam, nikoli automatická
   oprava.
4. **[Saldokonto](53_Saldokonto.md)** — otevřené
   pohledávky a závazky per partner; použij k inventarizaci účtů 311/321 a
   k rozhodnutí, co poslat na [upomínku](22_Upominky.md) nebo na
   [zápočet](82_Zapocty.md).
5. **Hlavní kniha a předvaha** — prověř neobvyklé zůstatky, průběžné účty,
   pokladnu a vazbu banky na účetní analytiky.
6. **DPH výkazy** ([§ 35](36_Vykazy_DPH.md)) — přiznání a kontrolní hlášení;
   před podáním zkontroluj, že se čísla shodují s knihou DPH
   ([§ 36](37_Kniha_DPH.md)) a s účtem 343 v deníku.
7. Po skutečném podání nahraj XML a potvrzení do **EPO podání a archívu**.
   Samotné vytvoření ani stažení XML zámek neposouvá. Po kontrole doručenky označ
   validní DPH/KH snapshot jako **odeslaný** ručně. Tím se příslušný zámek posune.
   ([§ 45.9 Zámek účtování k datu](45_Ucetni_denik.md#459-zamek-uctovani-k-datu)).
8. **[Měsíční přehled](56_Mesicni_report.md) klientovi**
   — pokud firmu vede externí účetní pro klienta, jedno tlačítko sestaví PDF
   report za měsíc.

## 43.4 Roční cyklus — účetní uzávěrka

Celý postup vede **uzávěrkový průvodce** v [§ 50](87_Uzaverka.md), krok za
krokem:

1. [Předběžné kontroly](87_Uzaverka.md#8721-krok-1-predbezne-kontroly) —
   totéž jako měsíční kontrola, ale za celý rok.
2. [Odpisy majetku](87_Uzaverka.md#8722-krok-2-odpisy-majetku) — hromadné
   zaúčtování ročních odpisů, viz i [§ 49](78_Majetek.md#786-hromadne-zauctovani-odpisu-roku).
3. [Kurzové rozdíly](87_Uzaverka.md#8723-krok-3-kurzove-rozdily) — přecenění
   cizoměnových zůstatků k rozvahovému dni.
4. [Dohadné položky a časové rozlišení](87_Uzaverka.md#8724-kroky-4-5-dohadne-polozky-a-casove-rozliseni),
   včetně návrhů předplacených nákladů a zvolené politiky drobného majetku.
5. [Opravné položky k pohledávkám](87_Uzaverka.md#8725-krok-opravne-polozky-k-pohledavkam) —
   navazuje na saldokonto ze [§ 43.3](#433-mesicni-cyklus).
6. [Daň z příjmů](87_Uzaverka.md#8726-krok-dan-z-prijmu) — mezikrok na
   [Daň z příjmů](38_Dan_z_prijmu.md).
7. **Sklad** — je-li aktivní a firma používá způsob B, zkontroluj inventuru a
   ocenění. Backend umí zaúčtovat konečný stav, manka a přebytky, ale současný
   webový průvodce skladový krok nezobrazuje; firma se skladem proto standardní UI
   cestou uzávěrku nedokončí. Bez skladu se krok přeskočí.
8. [Uzavření knih a otevření nového roku](87_Uzaverka.md#873-uzavreni-knih-a-otevreni-noveho-roku).
9. **Uzávěrkový balíček** — před schválením stáhni doložitelný ZIP sestav,
   inventarizací a daňových podkladů.
10. [Schválení závěrky](87_Uzaverka.md#874-interni-kontrola-schvaleni-zaverky-a-znovuotevreni) —
   rozdělení výsledku hospodaření (431 → 428/429/364).

Po celý rok si drž [**Rozvahu**](50_Rozvaha.md) a
[**Výsledovku**](51_Vysledovka_druhova.md)
po ruce jako průběžnou kontrolu, jestli VH ve výsledovce sedí s A.V. rozvahy —
u firem s bankovním úvěrem zkontroluj i řádek nákladových úroků (562).

## 43.5 Víc firem — účetní kancelář

Vedeš-li víc firem najednou, sekce Účetnictví začíná položkou
**[Přehled firem](44_Prehled_firem.md)** — cross-firemní pohled na termíny
DPH/KH, nezaúčtované doklady a stav uzávěrky, s prokликem a přepnutím firmy
bez návratu na dashboard.

## 43.6 Mapa kapitol Účetnictví

| Co řešíš | Kapitola |
|---|---|
| Termíny a stav práce napříč firmami | [Přehled firem](44_Prehled_firem.md) |
| Automatické návrhy, schvalování, pravidla a historie | [Automat](46_Automat.md) |
| Zápisy, ruční zápis, storno, zámek k datu | [§ 44 Účetní deník](45_Ucetni_denik.md) |
| Doklady a pohyby, které čekají na ruční rozhodnutí | [K doúčtování](47_Rucni_fronta_doctovani.md) |
| Bankovní pohyby bez podkladu a doklady po splatnosti | [Úplnost dokladů](54_Uplnost_dokladu.md) |
| Průběžná kontrolní brána za měsíc, kvartál či vlastní rozsah | [Měsíční kontrola](55_Mesicni_kontrola.md) |
| Sestavení, PDF a odeslání klientského reportu | [Měsíční přehled](56_Mesicni_report.md) |
| Účtový rozvrh a kontrola účtu | [Účtový rozvrh](81_Ucetni_osnova.md) |
| Předkontace a další servisní nástroje | [Nástroje](88_Ucetni_nastroje.md) |
| Hlavní kniha | [Hlavní kniha](48_Hlavni_kniha.md) |
| Předvaha | [Obratová předvaha](49_Obratova_predvaha.md) |
| Rozvaha | [Rozvaha](50_Rozvaha.md) |
| Výsledovka | [Druhová](51_Vysledovka_druhova.md) a [účelová](52_Vysledovka_ucelova.md) |
| Otevřené pohledávky a závazky | [Saldokonto](53_Saldokonto.md) |
| Měsíční kontroly, úplnost dokladů, K1–K10 a inventarizace | [Účetní kontroly a inventarizace](79_Ucetni_kontroly_a_inventarizace.md) |
| Mzdová rekapitulace, kontace a mzdový list | [Mzdy](57_Mzdy.md) |
| Dlouhodobý i drobný majetek, odpisy, inventární karty | [§ 49 Majetek a odpisy](78_Majetek.md) |
| Uzávěrkový průvodce, kontroly K1–K10, balíček, archiv | [§ 50 Účetní období a uzávěrka](87_Uzaverka.md) |
| DPH přiznání, kontrolní hlášení, kniha DPH | [§ 35](36_Vykazy_DPH.md), [§ 36](37_Kniha_DPH.md) |
| Bankovní a pokladní zaúčtování | [§ 27–29 Peníze](28_Banka.md) |
