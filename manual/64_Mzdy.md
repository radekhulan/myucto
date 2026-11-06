# 64. Mzdy

> Zjednodušená mzdová rekapitulace: z jedné hrubé částky připraví standardní rozpad, zaúčtuje ho do deníku a uloží měsíční podklad pro mzdový list. Pro účetní, která potřebuje rychle zaúčtovat mzdu jednoho zaměstnance nebo odměnu jednatele-společníka. Plné mzdy zpracovává samostatná sekce [Mzdy](75_Uplne_mzdy.md).

## 64.1 Kdy to potřebujete

Kapitolu otevřete, když:

- potřebujete zaúčtovat jednoduchou mzdu jednoho zaměstnance,
- vyplácíte odměnu jednatele-společníka (kontace 522/366),
- chcete každý měsíc účtovat stejnou mzdu automaticky,
- potřebujete roční mzdový list zaměstnance.

Modul je zjednodušený měsíční kalkulátor a účetní můstek. Není plnohodnotným mzdovým systémem. V demo režimu je položka menu skrytá, protože sdílená ukázková data nemají představovat konkrétního poplatníka.

Sjednanou hodinovou sazbu s účinností a historií evidujete v plném modulu
[Mzdy na kartě pracovního vztahu](86_Zamestnanci.md#866-krok-za-krokem-zmena-mzdy-uvazku-nebo-podminek-vztahu).
Tato rekapitulace z hodin a sazby mzdu nepočítá. Plný modul nabízí
[náhled hodinového výpočtu](79_Rychly_mesicni_vstup.md#79812-vypocet-hodinove-mzdy-a-dolozeni-naroku)
z přesných schválených hodin a účinných sazeb, včetně
[kontroly minimální mzdy a doplatku](79_Rychly_mesicni_vstup.md#79813-minimalni-mzda-a-doplatek).
Nevyřešené podklady výpočet označí konkrétní příčinou. Schválený běh
uchová rozpis hodinových nároků pro výplatní pásku i navazující výstupy.

Použijte jej pro jednoduchou mzdu jednoho zaměstnance nebo odměnu jednatele-společníka, pokud všechny vstupy odpovídají podporovanému standardnímu modelu. U více zaměstnanců, nemocenské, exekucí, benefitů, souběhů, dohod a dalších výjimek použijte výpočet specializovaného mzdového systému a do MyÚčta přeneste pouze schválenou rekapitulaci. Seznam toho, co modul neumí, je v [§ 64.8.7](#6487-co-modul-neumi).

### 64.1.1 Kdy co udělat

<!-- cols: 24 46 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavedení | Založit zaměstnance | `Účetnictví → Mzdová rekapitulace`, dolní část stránky, [§ 64.3](#643-krok-za-krokem-zalozeni-zamestnance) |
| každý měsíc | Zadat hrubou mzdu a zaúčtovat | [§ 64.4](#644-krok-za-krokem-zauctovani-mesicni-mzdy) |
| když mzda je pravidelná | Zapnout automatické měsíční zaúčtování | karta zaměstnance, [§ 64.5](#645-krok-za-krokem-automaticke-mesicni-zauctovani) |
| po skončení roku | Stáhnout mzdový list | [§ 64.6](#646-krok-za-krokem-rocni-mzdovy-list) |

## 64.2 Než začnete

1. **Podvojné účetnictví a oprávnění.** Náhled a seznam zaměstnanců může číst role s oprávněním k účetnictví. Změna zaměstnance a zaúčtování vyžadují účetní zápisová oprávnění. PDF mzdového listu vyžaduje oprávnění k exportu sestav.
2. **Zaměstnanec s kartou.** Před prvním výpočtem si připravte: schválenou hrubou mzdu nebo odměnu za konkrétní měsíc, typ **zaměstnanec** nebo **jednatel-společník**, podklady k pojistnému a minimálnímu vyměřovacímu základu, podepsané prohlášení a doklady k případným slevám a informaci, zda se má měsíc uložit konkrétnímu zaměstnanci do mzdového listu.
3. **Roční konstanty zvoleného roku.** Server načte sazby a minima pro daný rok. Pokud roční konstanty chybí, výpočet odmítne a nepoužije sazby jiného roku.
4. **Jedna cesta na měsíc.** Pro externí rekapitulaci lze použít šablonu ručního zápisu a CSV import popsaný v [Účetním deníku](52_Ucetni_denik.md#52145-rucni-zapis). Obě cesty jsou alternativní, tentýž měsíc nezaúčtovávejte dvakrát.
5. **Mzdy nejsou převedené do modulu Mzdy.** Od období, od kterého se mzdy počítají a účtují v modulu **Mzdy**, rekapitulace účtovat nejde (hláška **Mzdu za období zaúčtujete v modulu Mzdy.**). Zůstává kvůli starším obdobím.

## 64.3 Krok za krokem: založení zaměstnance

1. Otevřete `Účetnictví → Mzdová rekapitulace` a ve spodní části stránky v bloku **Zaměstnanci** klikněte na **Nový zaměstnanec**.
2. Vyplňte **Jméno a příjmení** a **Datum narození**.
3. V poli **Typ poplatníka** zvolte **Zaměstnanec** nebo **Jednatel-společník**.
4. V poli **Pracovněprávní vztah** zvolte **Pracovní poměr**, **Dohoda o provedení práce**, **Dohoda o pracovní činnosti** nebo **Smlouva o výkonu funkce (§ 59 ZOK)**.
   U smlouvy o výkonu funkce se typ poplatníka předvyplní na **Jednatel-společník**.
5. Zaškrtněte **Uplatňuje slevu na poplatníka** a **Podepsané prohlášení k dani (§ 38k)**, pokud je prohlášení podepsané. Vyplňte **Počet dětí**.
6. Vyplňte **Pravidelná hrubá mzda (Kč)** pro příští měsíce.
7. Chcete-li, aby se čistá mzda měsíčně přeúčtovávala na účet společníka, zvolte účet v poli **Naložení s čistou mzdou** (obvykle analytika účtu 365).
8. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Zaměstnanec je v seznamu a hlásí **Zaměstnanec byl založen.**

> [!WARNING]
> Rodné číslo ani adresa se zde nezadávají. Patří do chráněné evidence osoby v [Zaměstnancích](86_Zamestnanci.md), kde se ukládají šifrovaně.

## 64.4 Krok za krokem: zaúčtování měsíční mzdy

1. Otevřete `Účetnictví → Mzdová rekapitulace`.
2. Zvolte **Rok** a **Měsíc**.
3. Ve výběru **Zaměstnanec (mzdový list)** vyberte zaměstnance. Typ poplatníka i slevy se převezmou z jeho karty a příslušná pole se zamknou. Výběr je nepovinný, ale bez něj zaúčtování neuloží podklad pro mzdový list.
4. Zadejte **Hrubá mzda**. Rozpad se spočítá automaticky.
5. Zkontrolujte **Rozpad hrubé mzdy**: pojistné zaměstnance, doplatek do minimálního vyměřovacího základu, zálohu na daň po slevách, **Čistá mzda k výplatě**. V bloku **Odvody k úhradě** vidíte tři platby (zdravotní pojišťovna, OSSZ, finanční úřad).
6. Zkontrolujte **Účetní zápis** k poslednímu dni měsíce.
7. Klikněte na **Zaúčtovat**.

**Jak poznáte, že je hotovo:** Stránka hlásí **Mzdová rekapitulace zaúčtována (zápis #N).** a v mzdovém listu zaměstnance přibyl měsíc.

> [!WARNING]
> Za firmu a měsíc existuje nejvýše jeden zápis. Opakované zaúčtování téhož měsíce zápis řízeně přepíše, druhý nevznikne. Před přepsáním již zkontrolovaného měsíce ověřte dopad na mzdy, odvody a navazující platby.

## 64.5 Krok za krokem: automatické měsíční zaúčtování

1. V `Účetnictví → Mzdová rekapitulace` v bloku **Zaměstnanci** klikněte u zaměstnance na **Upravit** a vyplňte **Pravidelná hrubá mzda (Kč)**. Automatické účtování jde zapnout až s vyplněnou pravidelnou hrubou mzdou.
2. Zaškrtněte **Účtovat automaticky**.
3. Klikněte na **Uložit**.
4. Průběh kontrolujte v `Systém → Plánované úlohy`.

**Jak poznáte, že je hotovo:** V seznamu zaměstnanců je u zaměstnance štítek **Automaticky**. Od 1. dne následujícího měsíce se předchozí měsíc zaúčtuje sám s datem k jeho poslednímu dni.

## 64.6 Krok za krokem: roční mzdový list

1. Ověřte, že je založen správný zaměstnanec a není zaměněn s jinou osobou.
2. V sekci **Mzdový list** zvolte zaměstnance a rok.
3. Projděte všech 12 měsíců a doplňte chybějící rekapitulace z průkazných podkladů.
4. Porovnejte roční součty s účty 521/522, 524, 331/366, 336 a 342.
5. Porovnejte odvody s bankovními platbami a předpisy institucí.
6. Klikněte na **Stáhnout PDF**.
7. PDF archivujte společně s prohlášeními, výplatními podklady a potvrzeními.

**Jak poznáte, že je hotovo:** PDF obsahuje dvanáct měsíců, pojistné, daň, slevy, čistou částku a roční součty. Měsíc bez podkladu je označen jako chybějící, sestava si jeho hodnoty nevymýšlí.

> [!TIP]
> Mzdy zaúčtované dřív, než byl zaměstnanec založen, doplní do mzdového listu dávkově skript z [§ 64.8.6](#6486-zpetne-doplneni-snapshotu-backfill).

## 64.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Mzdu za období zaúčtujete v modulu Mzdy.** | Od tohoto období se mzdy účtují v modulu **Mzdy** | Zaúčtujte mzdu tam ([Úplné mzdy](75_Uplne_mzdy.md)). |
| Výpočet se odmítl | Server nezná roční konstanty zvoleného roku | Doplňte konstanty roku. Server nesáhne po nejbližším jiném roce. |
| Zaúčtování se odmítlo | Zaměstnanec je neaktivní, období je uzavřené, je nastavený zámek data, chybí účet v osnově, nebo je výsledný zápis nevyrovnaný | Opravte zdroj nebo nastavení, ne výslednou částku. |
| Zápis je 521/331, místo aby byl 522/366 | Není zvolený zaměstnanec s typem poplatníka **Jednatel-společník** | Vyberte zaměstnance. Typ poplatníka se převezme z karty. |
| Aplikace upozorňuje, že odměna člena statutárního orgánu se obvykle účtuje 522/366 | Karta má jiný typ poplatníka, než se obvykle používá | Karta se uloží, jen se upozorní. Jeden člověk může mít u téže firmy vedle výkonu funkce i pracovní poměr. |
| Automatické zaúčtování měsíc přeskočilo | Měsíc už je zaevidovaný, nebo je za něj zaúčtovaná rekapitulace jiného zaměstnance, nebo je období uzavřené či zamčené | Výsledek je v reportu úlohy. Mzdu zaúčtujte ručně. |
| Zaměstnance nejde smazat | Má historii měsíčních podkladů | Deaktivujte ho. Historický mzdový list zůstane čitelný. |
| Čistá mzda nesouhlasí s mzdovou agendou | Modul neuplatňuje strop vyměřovacího základu a nezná všechny výjimky | Ověřte výpočet podle mzdové agendy ([§ 64.8.2](#6482-mesicni-vypocet)). |
| Měsíc s hrubým příjmem pod rozhodnou částkou (4 500 Kč v roce 2026) se spočítal zálohou | Srážková daň se v rekapitulaci počítá jen u dohody o provedení práce pod rozhodnou částkou; jinde rekapitulace spočítá zálohu a upozorní | Správný výpočet udělá sekce [Mzdy](75_Uplne_mzdy.md). |

## 64.8 Podrobnosti a pravidla

### 64.8.1 Zaměstnanci a podklady

Ve spodní části stránky lze založit zaměstnance a uložit jeho jméno, typ
poplatníka, pracovněprávní vztah, příznak základní slevy na poplatníka, počet dětí,
pravidelnou měsíční hrubou mzdu a aktivní stav. **Rodné číslo ani adresa se zde
už nezadávají** - patří do chráněné evidence osoby v
[Zaměstnancích](86_Zamestnanci.md), kde se ukládají šifrovaně. Tyto údaje slouží ročnímu mzdovému
listu; samy nedokládají podepsané prohlášení poplatníka ani nárok na slevu.

**Pracovněprávní vztah** nabízí pracovní poměr, dohodu o provedení práce, dohodu
o pracovní činnosti a smlouvu o výkonu funkce (§ 59 ZOK). Rozhoduje o režimu zdanění:
srážkovou daň ze samostatného základu (§ 6 odst. 4 písm. a) ZDP) tahle rekapitulace
spočítá **jen** u dohody o provedení práce pod rozhodnou částkou bez podepsaného
prohlášení. Podle § 6 odst. 4 písm. b) ZDP se ale bez prohlášení sráží i u každého
jiného vztahu, včetně pracovního poměru a odměny člena statutárního orgánu, když
hrubý příjem za měsíc nedosáhne rozhodné částky pro účast na nemocenském pojištění
(pro rok 2026 je to 4 500 Kč). Takový měsíc rekapitulace spočítá zálohou a upozorní
na něj; správný výpočet udělá sekce [Mzdy](75_Uplne_mzdy.md). Pojistné se u odměny
člena statutárního orgánu řídí rozhodným příjmem stejně jako u zaměstnance.

U smlouvy o výkonu funkce formulář předvyplní typ poplatníka **jednatel/společník**
(kontace 522/366). Předvyplní jej, ale nevynutí - jinou kombinaci lze uložit, jen na
ni aplikace upozorní. Jeden člověk totiž může mít u téže firmy vedle výkonu funkce
i pracovní poměr.

Karta je zdroj pravdy: vyberete-li ve výpočtu zaměstnance, převezme se z ní **typ
poplatníka i slevy** a příslušná pole ve formuláři se zamknou. Zabraňuje to tomu, aby
náhled ukazoval kontaci 521/331 a zaúčtovalo se 522/366.

**Pravidelná hrubá mzda** je deklarovaná částka pro příští měsíce, ne historie -
už zaúčtované měsíce zůstávají v mzdovém listu tak, jak byly zaúčtovány, a pozdější
změna karty je nepřepíše. Teprve s vyplněnou částkou lze zapnout **Účtovat
automaticky** (viz [§ 64.5](#645-krok-za-krokem-automaticke-mesicni-zauctovani)).

Samostatná sekce **Mzdy** tuto agendu nelicencuje. Od období, od kterého mzdy počítá a účtuje modul **Mzdy**, už se ale přes rekapitulaci účtovat nedá; rekapitulace zůstává kvůli starším obdobím.

Zaměstnance s historií měsíčních snapshotů nelze smazat. Lze jej deaktivovat,
aby se nenabízel pro nové měsíce; historický mzdový list zůstane čitelný.
Při výběru zaměstnance aplikace ověří jeho aktivní stav i příslušnost k aktuální
firmě.

### 64.8.2 Měsíční výpočet

Po volbě roku, měsíce a hrubé částky server načte sazby a minima přesně pro daný
rok. Pokud roční konstanty chybí, výpočet se nesmí tiše provést sazbami jiného roku.

Zjednodušeně platí:

| Veličina | Výpočet v modulu |
|---|---|
| Sociální pojištění zaměstnance | hrubá mzda × roční sazba, zaokrouhleno nahoru na Kč |
| Zdravotní pojištění zaměstnance | hrubá mzda × roční sazba, zaokrouhleno nahoru na Kč |
| Zdravotní vyměřovací základ | vyšší z hrubé mzdy a zákonného minima daného roku |
| Doplatek zaměstnance do minima | kladný rozdíl do minima × celková sazba ZP, zaokrouhleno dolů |
| Zdravotní pojištění zaměstnavatele | doplněk, aby celkový odvod odpovídal sazbě z vyměřovacího základu |
| Sociální pojištění zaměstnavatele | hrubá mzda × roční sazba, zaokrouhleno nahoru |
| Základ pro zálohu na daň | hrubá mzda zaokrouhlená do 100 Kč na celé koruny nahoru, nad 100 Kč na celé stokoruny nahoru |
| Záloha na daň | základ × roční sazba, zaokrouhleno nahoru; nad měsíční hranicí se část základu nad ní daní vyšší sazbou |
| Sražená záloha | záloha snížená o měsíční slevy, nejvýše na nulu - tahle částka jde na 342 a na finanční úřad |
| Čistá částka | hrubá mzda − pojistné zaměstnance − doplatek ZP − sražená záloha |

Náhled také ukazuje celkový odvod zdravotní pojišťovně, sociální správě a finančnímu
úřadu. Porovnejte jej s platebními předpisy a výstupem mzdové agendy.

> [!WARNING]
> U vysokých mezd modul nezná roční kontext: strop vyměřovacího základu sociálního
> pojištění (48× průměrné mzdy za rok) se neuplatňuje, protože rekapitulace počítá
> jeden měsíc samostatně. Jakmile se mzda ke stropu blíží, ověřte sociální pojištění
> podle mzdové agendy a rozpad podle ní upravte.

> [!WARNING]
> Minimální zdravotní základ se neuplatní ve všech životních situacích stejně.
> Modul nezná všechny výjimky, část měsíce, státní pojištění ani souběhy. Pokud se
> zaměstnance minimum netýká, nepoužívejte automatický výsledek bez odborné úpravy.

### 64.8.3 Zaúčtování rekapitulace

Potvrzením vznikne zápis k poslednímu dni měsíce:

| Význam | MD | Dal |
|---|---:|---:|
| Hrubá mzda zaměstnance | 521 | 331 |
| Odměna jednatele-společníka | 522 | 366 |
| Pojistné hrazené zaměstnavatelem | 524 | 336 |
| Pojistné sražené zaměstnanci | 331 nebo 366 | 336 |
| Sražená záloha na daň (po slevách) | 331 nebo 366 | 342 |

Po srážkách zůstane na účtu 331 (resp. 366) čistá mzda jako závazek. Pokud se odměna
reálně nevyplácí - typicky u jednatele-společníka, který si ji nechává na účtu
společníka - vyplňte na kartě zaměstnance **Naložení s čistou mzdou** a vyberte účet, na
který se má měsíčně přeúčtovat (obvykle analytika účtu **365**). Zápis pak dostane
ještě jeden pár:

| Význam | MD | Dal |
|---|---:|---:|
| Zápočet čisté mzdy | 331 nebo 366 | zvolený účet (např. 365.100) |

Pár je součástí **téhož** zápisu, takže saldo 331/366 se každý měsíc vynuluje a
přeúčtování i storno mzdy s ním zacházejí zároveň. Bez vyplněného účtu se nic
nepřidává a závazek zůstane viset - to je výchozí chování.

Peněžní účty (21x, 22x, 26x) v nabídce nejsou schválně: výplatu z pokladny musí zapsat
**výdajový pokladní doklad**, jinak se pokladní kniha rozejde s hlavní knihou, a výplatu
z účtu zaúčtuje **párování bankovního výpisu** - mzdový automat by ji zdvojil.

Za jednu firmu a měsíc existuje nejvýše jeden zápis tohoto typu. Opakované uložení
stávající zápis řízeně přepíše, nezaloží druhý. To zároveň znamená, že kalkulátor
není určen k samostatnému účtování více zaměstnanců v jednom měsíci.

Zaúčtování respektuje otevřenost období a zámek účtování k datu. Před přepsáním
již zkontrolovaného měsíce ověřte dopad na mzdy, odvody a všechny navazující platby.
Náhled je čistý výpočet bez zápisu; ostrá akce vyžaduje
`accounting.journal.post`. Výsledný zápis i snapshot vznikají společně v
transakci, aby mzdový list nemohl tvrdit něco jiného než deník.

#### 64.8.3.1 Automatické měsíční zaúčtování

Má-li zaměstnanec na kartě vyplněnou pravidelnou hrubou mzdu a zapnuté **Účtovat
automaticky**, zaúčtuje jeho rekapitulaci úloha `cron-payroll-post` sama - běží 1. dne
v měsíci a účtuje měsíc předchozí, s datem k jeho poslednímu dni. Stav běhu je vidět
v **Systém → Plánované úlohy**.

Automat nikdy nepřepisuje cizí práci:

- měsíc, který už je zaevidovaný (ať cronem, nebo ručně s jinou částkou), přeskočí
  a ohlásí jako „už bylo",
- je-li za měsíc už zaúčtovaná rekapitulace patřící někomu jinému - typicky **druhý
  zaměstnanec s automatem**, protože za firmu a měsíc existuje jen jeden zápis -
  ohlásí konflikt a nechá mzdu na ruční zaúčtování,
- uzavřené období, zámek data nebo chyba u jednoho zaměstnance běh neshodí; skončí
  v reportu úlohy.

Ručně lze úlohu spustit i zpětně: `cmd/cron-payroll-post.sh --period=2026-06`
(`--dry-run` jen vypíše, co by udělala).

### 64.8.4 Slevy a měsíční snapshot zaměstnance

Rekapitulace předpokládá **podepsané prohlášení poplatníka** a uplatní měsíční slevu na
poplatníka; přepínač nad rozpadem to vypne u poplatníka, který prohlášení podepsané nemá
(typicky jednatel s hlavním zaměstnáním jinde). Vedle něj se zadává počet vyživovaných
dětí. Základní sleva a zvýhodnění na děti se odvozují z ročních konstant jako měsíční podíl.

Vyberete-li konkrétního zaměstnance, slevy se převezmou z jeho karty a přepínač se zamkne -
karta zaměstnance je zdroj pravdy, aby se zaúčtování nerozešlo s mzdovým listem. Modul
zároveň uloží snapshot rozpadu a slev pro mzdový list.

Sleva snižuje zálohu nejvýše na nulu. **Daňový bonus na děti modul nemodeluje**;
nevytvoří zápornou daň ani samostatnou pohledávku vůči správci daně. U případu, kde
bonus skutečně vzniká, použijte odborný mzdový výpočet a do účetnictví přenes jeho
výsledek.

Výběr zaměstnance nemění kontaci zápisu (účty zůstávají stejné), ale jeho slevy ovlivní
částku sražené zálohy na 342 - a tím i čistou mzdu na 331/366.

### 64.8.5 Roční mzdový list

Mzdový list se stahuje jako PDF za jednoho zaměstnance a rok. Obsahuje dvanáct
měsíců, uložené hrubé částky, pojistné, daň, slevy, čistou částku a roční součty.
Měsíc bez snapshotu je označen jako chybějící; sestava si jeho hodnoty nevymýšlí ani
je sama nedopočítá z deníku. Historické měsíce zaúčtované bez výběru zaměstnance
doplní dávkově skript z [§ 64.8.6](#6486-zpetne-doplneni-snapshotu-backfill).

Sestava se generuje na serveru z uložených měsíčních podkladů, nikoli zpětným
odhadem ze zůstatků účtů. Export vždy omezen
aktuální firmou a vybraným zaměstnancem (vyžaduje oprávnění `reports.export`).

### 64.8.6 Zpětné doplnění snapshotů (backfill)

Mzdy zaúčtované dřív, než byl v evidenci založen zaměstnanec - a mzdy zaúčtované ručně
- nemají snapshot, takže mzdový list zůstane prázdný, přestože deník je v pořádku.
Snapshoty doplní zpětně dávkový skript:

```
php api/bin/backfill-payroll-records.php --supplier=<ID>            # DRY-RUN, nic nezapíše
php api/bin/backfill-payroll-records.php --supplier=<ID> --apply    # ostrý běh
```

Skript projde ručně zaúčtované zápisy s identifikátorem ve tvaru RRRRMM, vezme
hrubou mzdu z MD 521/522, znovu spočítá rozpad a uloží snapshot. **Do deníku nesahá** -
zaúčtování ani kontace se nemění. Opakovaný běh nic neduplikuje; existující měsíce
přepíše jen s `--overwrite`.

Zaměstnance páruje podle nákladového účtu: MD 522 → společník, MD 521 → zaměstnanec.
Je-li takových zaměstnanců v firmě víc, měsíc přeskočí a je potřeba `--employee=<ID>`.

Před zápisem ověří, že přepočtený rozpad reprodukuje řádky zápisu na haléř. Když
nesedí, měsíc **nezapíše** (chyba nesouladu s deníkem) - mzdový list by jinak tvrdil něco
jiného než deník. Typicky jde o měsíc s jinými složkami mzdy (nemocenská, srážky,
více zaměstnanců v jednom zápisu), který patří do ruky člověku.

> [!TIP]
> Doplatek do minimálního vyměřovacího základu vychází na půlkorunu (2024: 2011,50 |
> 2025: 2200,50 | 2026: 2416,50), takže se ručně účtované mzdy o 1 Kč rozcházejí podle
> směru zaokrouhlení. Přepínač `--reconcile` takový rozdíl dorovná **na hodnotu z
> deníku** (pojistné zůstane zákonné, rozdíl absorbuje doplatek) a po úpravě znovu
> ověří shodu s deníkem. Roční součty mzdového listu pak sedí na účty 336/342 na korunu.

### 64.8.7 Co modul neumí

- docházku, dovolenou, překážky v práci a náhrady mzdy,
- nemocenskou a dávky,
- dohody a všechny výjimky pojistného,
- souběhy pracovních vztahů nebo více zaměstnanců v jednom měsíčním zápisu,
- exekuce, insolvence, srážky, benefity a naturální mzdu,
- roční zúčtování záloh a všechny daňové bonusy,
- přihlášky, odhlášky a elektronická podání institucím,
- výplatní pásky, bankovní dávku mezd a personální agendu,
- automatické doložení nároku na slevy.

> [!WARNING]
> Mzdová rekapitulace je účetní pomůcka. Odpovědnost za pracovněprávní, pojistné a
> daňové posouzení zůstává na zaměstnavateli a osobě, která mzdy zpracovává.

### 64.8.8 Oprávnění a řešení chyb

- Náhled a seznam zaměstnanců může číst role s oprávněním `accounting`.
- Změna zaměstnance vyžaduje účetní zápisové oprávnění.
- Zaúčtování vyžaduje `accounting.journal.post`.
- PDF mzdového listu vyžaduje `reports.export`.

Pokud server nezná konstanty zvoleného roku, výpočet odmítne; nesáhne po
nejbližším jiném roce. Další časté chyby jsou neaktivní zaměstnanec, uzavřené
období, zámek data, chybějící účet v osnově a nevyrovnaný výsledný zápis.
Opravujte zdroj nebo nastavení, ne výslednou částku pouze proto, aby kontrola
prošla.

## 64.9 Související kapitoly

- [Úplné mzdy](75_Uplne_mzdy.md) - plnohodnotný mzdový modul
- [Zaměstnanci](86_Zamestnanci.md) - chráněná evidence osob
- [Účetní deník](52_Ucetni_denik.md#52145-rucni-zapis) - ruční zápis a CSV import rekapitulace
- [Shoda účtování mezd](81_Shoda_uctovani_mezd.md)
- [Průvodce účetního](50_Pruvodce_ucetniho.md) - měsíční postup
