# 57. Mzdy

**Cesta: `Účetnictví → Mzdová rekapitulace`**

Modul je zjednodušený měsíční kalkulátor a
účetní můstek. Umí z jedné hrubé částky připravit standardní rozpad, zaúčtovat jej
do deníku a uložit měsíční podklad pro mzdový list. Není plnohodnotným mzdovým
systémem. V demo režimu je položka menu skrytá, protože sdílená ukázková data
nemají představovat konkrétního poplatníka.

Samostatná sekce **Mzdy** tuto agendu nenahrazuje ani nelicencuje. Používá stejné
karty zaměstnanců a postupně je rozšiřuje o více pracovních vztahů; aktuální stav
popisuje kapitola [Úplné mzdy — rozcestník](58_Uplne_mzdy.md).

## 57.1 Kdy modul použít

Použij jej pro jednoduchou mzdu jednoho zaměstnance nebo odměnu
jednatele-společníka, pokud všechny vstupy odpovídají podporovanému standardnímu
modelu. U více zaměstnanců, nemocenské, exekucí, benefitů, souběhů, dohod a dalších
výjimek použij výpočet specializovaného mzdového systému a do MyÚčto přenes pouze
schválenou rekapitulaci.

Pro externí rekapitulaci lze využít šablonu ručního zápisu a CSV import popsaný v
[Účetním deníku](45_Ucetni_denik.md#454-rucni-zapis).
Obě cesty jsou alternativní; tentýž měsíc nezaúčtovávej dvakrát.

## 57.2 Zaměstnanci a podklady

Ve spodní části stránky lze založit zaměstnance a uložit identifikační údaje, typ
poplatníka, pracovněprávní vztah, příznak základní slevy na poplatníka, počet dětí,
pravidelnou měsíční hrubou mzdu a aktivní stav. Tyto údaje slouží ročnímu mzdovému
listu; samy nedokládají podepsané prohlášení poplatníka ani nárok na slevu.

**Pracovněprávní vztah** nabízí pracovní poměr, dohodu o provedení práce, dohodu
o pracovní činnosti a smlouvu o výkonu funkce (§ 59 ZOK). Rozhoduje o režimu zdanění:
srážkovou daní ze samostatného základu (§ 6 odst. 4 ZDP) se daní **pouze** dohoda
o provedení práce do ročního limitu bez podepsaného prohlášení. Odměna člena
statutárního orgánu je příjem podle § 6 odst. 1 písm. c) ZDP a daní se vždy zálohou,
i když je nízká; pojistné se u ní řídí rozhodným příjmem stejně jako u zaměstnance.

U smlouvy o výkonu funkce formulář předvyplní typ poplatníka **jednatel/společník**
(kontace 522/366). Předvyplní jej, ale nevynutí — jinou kombinaci lze uložit, jen na
ni aplikace upozorní. Jeden člověk totiž může mít u téže firmy vedle výkonu funkce
i pracovní poměr.

Karta je zdroj pravdy: vyberete-li ve výpočtu zaměstnance, převezme se z ní **typ
poplatníka i slevy** a příslušná pole ve formuláři se zamknou. Zabraňuje to tomu, aby
náhled ukazoval kontaci 521/331 a zaúčtovalo se 522/366.

**Pravidelná hrubá mzda** je deklarovaná částka pro příští měsíce, ne historie —
už zaúčtované měsíce zůstávají v mzdovém listu tak, jak byly zaúčtovány, a pozdější
změna karty je nepřepíše. Teprve s vyplněnou částkou lze zapnout **Účtovat
automaticky** (viz § 57.4).

Zaměstnance s historií měsíčních snapshotů nelze smazat. Lze jej deaktivovat,
aby se nenabízel pro nové měsíce; historický mzdový list zůstane čitelný.
Backend při výběru zaměstnance ověří jeho aktivní stav i příslušnost k aktuální
firmě.

Před prvním výpočtem připrav:

- schválenou hrubou mzdu nebo odměnu za konkrétní měsíc,
- typ **zaměstnanec** nebo **jednatel-společník**,
- podklady k pojistnému a minimálnímu vyměřovacímu základu,
- podepsané prohlášení a doklady k případným slevám,
- informaci, zda se má měsíc uložit konkrétnímu zaměstnanci do mzdového listu.

## 57.3 Měsíční výpočet

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
| Sražená záloha | záloha snížená o měsíční slevy, nejvýše na nulu — tahle částka jde na 342 a na finanční úřad |
| Čistá částka | hrubá mzda − pojistné zaměstnance − doplatek ZP − sražená záloha |

Náhled také ukazuje celkový odvod zdravotní pojišťovně, sociální správě a finančnímu
úřadu. Porovnej jej s platebními předpisy a výstupem mzdové agendy.

> [!WARNING]
> U vysokých mezd modul nezná roční kontext: strop vyměřovacího základu sociálního
> pojištění (48× průměrné mzdy za rok) se neuplatňuje, protože rekapitulace počítá
> jeden měsíc samostatně. Jakmile se mzda ke stropu blíží, ověř sociální pojištění
> podle mzdové agendy a rozpad podle ní uprav.

> [!WARNING]
> Minimální zdravotní základ se neuplatní ve všech životních situacích stejně.
> Modul nezná všechny výjimky, část měsíce, státní pojištění ani souběhy. Pokud se
> zaměstnance minimum netýká, nepoužívej automatický výsledek bez odborné úpravy.

## 57.4 Zaúčtování rekapitulace

Potvrzením vznikne zápis k poslednímu dni měsíce:

| Význam | MD | Dal |
|---|---:|---:|
| Hrubá mzda zaměstnance | 521 | 331 |
| Odměna jednatele-společníka | 522 | 366 |
| Pojistné hrazené zaměstnavatelem | 524 | 336 |
| Pojistné sražené zaměstnanci | 331 nebo 366 | 336 |
| Sražená záloha na daň (po slevách) | 331 nebo 366 | 342 |

Po srážkách zůstane na účtu 331 (resp. 366) čistá mzda jako závazek. Pokud se odměna
reálně nevyplácí — typicky u jednatele-společníka, který si ji nechává na účtu
společníka — vyplň na kartě zaměstnance **Naložení s čistou mzdou** a vyber účet, na
který se má měsíčně přeúčtovat (obvykle analytika účtu **365**). Zápis pak dostane
ještě jeden pár:

| Význam | MD | Dal |
|---|---:|---:|
| Zápočet čisté mzdy | 331 nebo 366 | zvolený účet (např. 365.100) |

Pár je součástí **téhož** zápisu, takže saldo 331/366 se každý měsíc vynuluje a
přeúčtování i storno mzdy s ním zacházejí zároveň. Bez vyplněného účtu se nic
nepřidává a závazek zůstane viset — to je výchozí chování.

Peněžní účty (21x, 22x, 26x) v nabídce nejsou schválně: výplatu z pokladny musí zapsat
**výdajový pokladní doklad**, jinak se pokladní kniha rozejde s hlavní knihou, a výplatu
z účtu zaúčtuje **párování bankovního výpisu** — mzdový automat by ji zdvojil.

Za jednu firmu a měsíc existuje nejvýše jeden zápis tohoto typu. Opakované uložení
stávající zápis řízeně přepíše, nezaloží druhý. To zároveň znamená, že kalkulátor
není určen k samostatnému účtování více zaměstnanců v jednom měsíci.

Zaúčtování respektuje otevřenost období a zámek účtování k datu. Před přepsáním
již zkontrolovaného měsíce ověř dopad na mzdy, odvody a všechny navazující platby.
Náhled je čistý výpočet bez zápisu; ostrá akce vyžaduje
`accounting.journal.post`. Výsledný zápis i snapshot vznikají společně v
transakci, aby mzdový list nemohl tvrdit něco jiného než deník.

### Automatické měsíční zaúčtování

Má-li zaměstnanec na kartě vyplněnou pravidelnou hrubou mzdu a zapnuté **Účtovat
automaticky**, zaúčtuje jeho rekapitulaci úloha `cron-payroll-post` sama — běží 1. dne
v měsíci a účtuje měsíc předchozí, s datem k jeho poslednímu dni. Stav běhu je vidět
v **Systém → Plánované úlohy**.

Automat nikdy nepřepisuje cizí práci:

- měsíc, který už je zaevidovaný (ať cronem, nebo ručně s jinou částkou), přeskočí
  a ohlásí jako „už bylo",
- je-li za měsíc už zaúčtovaná rekapitulace patřící někomu jinému — typicky **druhý
  zaměstnanec s automatem**, protože za firmu a měsíc existuje jen jeden zápis —
  ohlásí konflikt a nechá mzdu na ruční zaúčtování,
- uzavřené období, zámek data nebo chyba u jednoho zaměstnance běh neshodí; skončí
  v reportu úlohy.

Ručně lze úlohu spustit i zpětně: `cmd/cron-payroll-post.sh --period=2026-06`
(`--dry-run` jen vypíše, co by udělala).

## 57.5 Slevy a měsíční snapshot zaměstnance

Rekapitulace předpokládá **podepsané prohlášení poplatníka** a uplatní měsíční slevu na
poplatníka; přepínač nad rozpadem to vypne u poplatníka, který prohlášení podepsané nemá
(typicky jednatel s hlavním zaměstnáním jinde). Vedle něj se zadává počet vyživovaných
dětí. Základní sleva a zvýhodnění na děti se odvozují z ročních konstant jako měsíční podíl.

Vybereš-li konkrétního zaměstnance, slevy se převezmou z jeho karty a přepínač se zamkne —
karta zaměstnance je zdroj pravdy, aby se zaúčtování nerozešlo s mzdovým listem. Modul
zároveň uloží snapshot rozpadu a slev pro mzdový list.

Sleva snižuje zálohu nejvýše na nulu. **Daňový bonus na děti modul nemodeluje**;
nevytvoří zápornou daň ani samostatnou pohledávku vůči správci daně. U případu, kde
bonus skutečně vzniká, použij odborný mzdový výpočet a do účetnictví přenes jeho
výsledek.

Výběr zaměstnance nemění kontaci zápisu (účty zůstávají stejné), ale jeho slevy ovlivní
částku sražené zálohy na 342 — a tím i čistou mzdu na 331/366.

## 57.6 Roční mzdový list

Mzdový list se stahuje jako PDF za jednoho zaměstnance a rok. Obsahuje dvanáct
měsíců, uložené hrubé částky, pojistné, daň, slevy, čistou částku a roční součty.
Měsíc bez snapshotu je označen jako chybějící; sestava si jeho hodnoty nevymýšlí ani
je sama nedopočítá z deníku. Historické měsíce zaúčtované bez výběru zaměstnance
doplní dávkově skript v § 57.7.

Doporučený postup před exportem:

1. Ověř, že je založen správný zaměstnanec a není zaměněn s jinou osobou.
2. Projdi všech 12 měsíců a doplň chybějící rekapitulace z průkazných podkladů.
3. Porovnej roční součty s účty 521/522, 524, 331/366, 336 a 342.
4. Porovnej odvody s bankovními platbami a předpisy institucí.
5. PDF archivuj společně s prohlášeními, výplatními podklady a potvrzeními.

Sestava se generuje na serveru z `payroll_monthly_records`, nikoli zpětným
odhadem ze zůstatků účtů. Export vyžaduje `reports.export` a vždy je omezen
aktuální firmou a vybraným zaměstnancem.

## 57.7 Zpětné doplnění snapshotů (backfill)

Mzdy zaúčtované dřív, než byl v evidenci založen zaměstnanec — a mzdy zaúčtované ručně
— nemají snapshot, takže mzdový list zůstane prázdný, přestože deník je v pořádku.
Snapshoty doplní zpětně dávkový skript:

```
php api/bin/backfill-payroll-records.php --supplier=<ID>            # DRY-RUN, nic nezapíše
php api/bin/backfill-payroll-records.php --supplier=<ID> --apply    # ostrý běh
```

Skript projde zápisy `source_type='manual'` se `source_id` ve tvaru RRRRMM, vezme
hrubou mzdu z MD 521/522, znovu spočítá rozpad a uloží snapshot. **Do deníku nesahá** —
zaúčtování ani kontace se nemění. Opakovaný běh nic neduplikuje; existující měsíce
přepíše jen s `--overwrite`.

Zaměstnance páruje podle nákladového účtu: MD 522 → společník, MD 521 → zaměstnanec.
Je-li takových zaměstnanců v firmě víc, měsíc přeskočí a je potřeba `--employee=<ID>`.

Před zápisem ověří, že přepočtený rozpad reprodukuje řádky zápisu na haléř. Když
nesedí, měsíc **nezapíše** (`ledger_mismatch`) — mzdový list by jinak tvrdil něco
jiného než deník. Typicky jde o měsíc s jinými složkami mzdy (nemocenská, srážky,
více zaměstnanců v jednom zápisu), který patří do ruky člověku.

> [!TIP]
> Doplatek do minimálního vyměřovacího základu vychází na půlkorunu (2024: 2011,50 |
> 2025: 2200,50 | 2026: 2416,50), takže se ručně účtované mzdy o 1 Kč rozcházejí podle
> směru zaokrouhlení. Přepínač `--reconcile` takový rozdíl dorovná **na hodnotu z
> deníku** (pojistné zůstane zákonné, rozdíl absorbuje doplatek) a po úpravě znovu
> ověří shodu s deníkem. Roční součty mzdového listu pak sedí na účty 336/342 na korunu.

## 57.8 Co modul neumí

- docházku, dovolenou, překážky v práci a náhrady mzdy,
- nemocenskou a dávky,
- dohody a všechny výjimky pojistného,
- souběhy pracovních vztahů nebo více zaměstnanců v jednom měsíčním zápisu,
- exekuce, insolvence, srážky, benefity a naturální mzdu,
- roční zúčtování záloh a všechny daňové bonusy,
- přihlášky, odhlášky a elektronická podání institucím,
- výplatní pásky, bankovní dávku mezd a personální agendu,
- automatické doložení nároku na slevy.

> [!IMPORTANT]
> Mzdová rekapitulace je účetní pomůcka. Odpovědnost za pracovněprávní, pojistné a
> daňové posouzení zůstává na zaměstnavateli a osobě, která mzdy zpracovává.

## 57.9 Oprávnění a řešení chyb

- Náhled a seznam zaměstnanců může číst role s oprávněním `accounting`.
- Změna zaměstnance vyžaduje účetní zápisové oprávnění.
- Zaúčtování vyžaduje `accounting.journal.post`.
- PDF mzdového listu vyžaduje `reports.export`.

Pokud server nezná konstanty zvoleného roku, výpočet odmítne; nesáhne po
nejbližším jiném roce. Další časté chyby jsou neaktivní zaměstnanec, uzavřené
období, zámek data, chybějící účet v osnově a nevyrovnaný výsledný zápis.
Opravuj zdroj nebo nastavení, ne výslednou částku pouze proto, aby kontrola
prošla.
