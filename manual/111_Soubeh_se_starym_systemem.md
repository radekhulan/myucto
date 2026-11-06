# 111. Souběh se starým systémem

> Měsíční porovnání MyÚčta s výstupy starého účetního programu, ve kterém firma ještě účtuje. Pro účetní, která při přechodu z jiného programu několik měsíců účtuje v obou a potřebuje ověřit, že čísla sedí.

## 111.1 Kdy to potřebujete

Kapitolu otevřete, když:

- firma přechází do MyÚčta z jiného programu (Money S3, POHODA, PREMIER nebo jiného) a ještě několik měsíců účtuje v obou,
- skončil měsíc a chcete ověřit, že MyÚčto sedí na starý program,
- potřebujete rozdíly zařadit a měsíc uzavřít,
- hledáte protokol o tom, jak souběh dopadl.

### 111.1.1 Každý měsíc souběhu

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po uzavření měsíce ve starém programu | Vyexportovat výstupy a pustit kontrolu | `Účetnictví → Souběh se starým systémem`, [§ 111.3](#1113-krok-za-krokem-kontrola-mesice) |
| po kontrole | Zařadit rozdíly a uzavřít cyklus | [§ 111.4](#1114-krok-za-krokem-zarazeni-rozdilu-a-uzavreni-cyklu) |
| při předávání | Stáhnout protokol | tlačítko **Protokol CSV** |

## 111.2 Než začnete

1. **Podvojné účetnictví.** Stránka je dostupná firmám s podvojným účetnictvím.
2. **Oprávnění.** Kontrolu spouští, zařazuje rozdíly a uzavírá uživatel s právem zápisu do účetnictví. Prohlížet ji může každý, kdo vidí účetnictví. Kontrola do účetnictví nic nezapisuje, ukládá jen protokol do historie cyklů.
3. **Převedená data v MyÚčtu.** Měsíc, který kontrolujete, musí být v MyÚčtu zaúčtovaný.
4. **Výstupy starého programu.** Připravte aspoň jeden výstup (obratovou předvahu, počty dokladů, saldokonto a další, viz [§ 111.6.1](#11161-co-se-porovnava)). Kritérium, ke kterému výstup nenahrajete, se nekontroluje a v protokolu není. Formát souborů popisuje [§ 111.6.3](#11163-format-vystupu).
5. **Volitelně záloha agendy Money S3.** Pokud jste ji nahráli v průvodci přechodu z Money S3, můžete ji vybrat místo souborů ([Přechod z Money S3](106_Prechod_z_Money_S3.md)).

## 111.3 Krok za krokem: kontrola měsíce

1. Otevřete `Účetnictví → Souběh se starým systémem`.
2. V bloku **Nová kontrola měsíce** vyberte **Měsíc** a **Starý program** (**Money S3**, **POHODA**, **PREMIER** nebo **Jiný program**).
3. Nahrajte výstupy, které máte k dispozici. U každého pole je vidět, ke kterému kritériu patří.
4. Byly-li výkazy vyexportované v tisících Kč, přepněte volbu **Výkazy ve** na **tisících Kč**.
5. U Money S3 můžete místo souborů vybrat **Záloha agendy**. Záloha se čte bez zápisu do MyÚčta a doplní obratovou předvahu a počty dokladů, pokud je nenahrajete jako soubor. Sestava vyexportovaná z Money má přednost před zálohou.
6. Klikněte na **Spustit kontrolu**.

**Jak poznáte, že je hotovo:** Stránka ohlásí **Kontrola doběhla, měsíc sedí.**, **Kontrola doběhla, rozdíly je potřeba zařadit.**, případně **Kontrola doběhla, některé kritérium skončilo chybou.** Výsledek je po kritériích (stav **Sedí**, **Rozdíly**, **Neúplná** nebo **Chyba**) a kontrola přibyla do **Historie cyklů**.

> [!TIP]
> Je-li tlačítko **Spustit kontrolu** neaktivní, nahrajte aspoň jeden výstup starého programu nebo vyberte zálohu agendy.

## 111.4 Krok za krokem: zařazení rozdílů a uzavření cyklu

1. U každého rozdílu vyberte zařazení: **Už ve zdroji**, **Rozdíl převodu** nebo **Rozdíl výkladu**.
2. Podle potřeby připište poznámku (**Poznámka k rozdílu**).
3. U **Rozdíl převodu** opravte příčinu v MyÚčtu nebo v převodu a kontrolu pusťte znovu. Opakovaná kontrola téhož měsíce převezme zařazení rozdílů, které trvají, kromě rozdílů převodu.
4. Zbývá-li kritérium s chybou, opravte vstup a pusťte kontrolu znovu.
5. Jsou-li všechny rozdíly zařazené jako **Už ve zdroji** nebo **Rozdíl výkladu**, klikněte na **Uzavřít cyklus**.
6. Chcete-li výsledek archivovat, klikněte na **Protokol CSV**.

**Jak poznáte, že je hotovo:** Stránka hlásí **Cyklus je uzavřený.** a v historii je cyklus s označením **Uzavřený cyklus**.

Význam zařazení:

- **Už ve zdroji**: rozdíl je už ve starém programu a převod ho věrně převzal.
- **Rozdíl převodu**: převod data přenesl jinak. Opravte příčinu a kontrolu pusťte znovu.
- **Rozdíl výkladu**: MyÚčto vykazuje položku jinak a předpis připouští obojí.

> [!WARNING]
> Uzavřený cyklus nejde měnit ani smazat, dokud ho znovu neotevřete tlačítkem **Znovu otevřít cyklus** (v nabídce u cyklu).

## 111.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Nejdřív nahrajte aspoň jeden výstup starého programu nebo vyberte zálohu agendy.** | Nic není k porovnání | Nahrajte výstup nebo vyberte zálohu. |
| **Některé kritérium skončilo chybou. Opravte vstup a pusťte kontrolu znovu.** | Soubor se nepodařilo přečíst nebo chybí povinný sloupec | Zkontrolujte formát podle [§ 111.6.3](#11163-format-vystupu) a spusťte kontrolu znovu. |
| **Nejdřív zařaďte všechny rozdíly (zbývá N).** | Cyklus nejde uzavřít s nezařazenými rozdíly | Zařaďte zbývající rozdíly. |
| **Rozdíly převodu (N) je potřeba opravit a kontrolu pustit znovu.** | Rozdíl převodu cyklus uzavřít nedovolí | Opravte příčinu a kontrolu pusťte znovu. |
| Počty dokladů z Money mají rozdíl v knize | Záloha obsahuje i doklady, které převod záměrně nepřebírá (nezaúčtovaný koncept v uzavřeném roce, nulový pokladní doklad) | Zařaďte je jako **Rozdíl výkladu**. |
| Majetek má jiné oprávky | MyÚčto počítá oprávky ze zaúčtovaných odpisů; odpisy se účtují až k 31. 12. | Porovnávejte hlavně stav k rozvahovému dni. |
| DPH za čtvrtletního plátce nesedí | MyÚčto sestavuje přiznání podle periody firmy | Spouštějte kontrolu DPH za poslední měsíc čtvrtletí. |
| Dalších N rozdílů je jen v počtu | Seznam rozdílů je zkrácený | Opravte příčinu a pusťte kontrolu znovu. |

## 111.6 Podrobnosti a pravidla

### 111.6.1 Co se porovnává

Na straně MyÚčta se berou stejné sestavy, jaké vidí účetní v aplikaci, vždy
k poslednímu dni vybraného měsíce.

| Kritérium | Co se porovná | Výstup starého programu | Tolerance |
|---|---|---|---|
| K1 | Obratová předvaha po syntetických účtech: počáteční stav, obrat od začátku roku a konečný stav | Obratová předvaha (CSV nebo text) | 0,00 Kč |
| K5 | Počty dokladů měsíce po knihách: vydané a přijaté faktury, pokladna, banka, interní doklady | Počty dokladů po knihách | 0 ks |
| K6 | Otevřené položky saldokonta po účtech a partnerech | Saldokonto | 0,00 Kč |
| K7 | Úhrady po dokladech: doklad otevřený jen v jednom z programů | Saldokonto | seznam |
| K8 | Korunové bankovní účty: zůstatek účtu 221 a posledního výpisu | Zůstatky bankovních účtů | 0,00 Kč |
| K9 | Přiznání k DPH po řádcích a kontrolní hlášení po dokladech | Přiznání a kontrolní hlášení (XML z EPO) | 0 Kč na řádek |
| K10 | Účty v cizí měně: zůstatek v měně a v Kč | Zůstatky bankovních účtů | 0,01 v měně |
| K11 | Majetek po kartách: vstupní cena, oprávky, zůstatková cena | Inventurní soupis majetku | 0,00 Kč |
| K12 | Výnosy a náklady měsíce po střediscích | Obraty po střediscích | 0,00 Kč |
| K13 | Řádky rozvahy a výsledovky | Rozvaha, výsledovka | 0 Kč, u výkazu v tisících ±1 tis. |

Kritérium, ke kterému nenahrajete výstup, se nekontroluje a v protokolu není.
Kritéria K2 až K4 (vyrovnanost, úplnost mapování, doklady proti deníku) kontroluje
převod sám při každém běhu, viz [Přechod z Money S3](106_Prechod_z_Money_S3.md).

Přiznání k DPH a kontrolní hlášení MyÚčto sestaví stejně jako při podání
a porovná s XML starého programu po atributech. Pořadí řádků kontrolního hlášení
ani mezery v evidenčním čísle dokladu nehrají roli. Rozdíl u dokladu v kontrolním
hlášení a v saldokontu odkazuje na doklad v MyÚčtu.

### 111.6.2 Kontrola z příkazové řádky

Kontrolu lze pustit i z příkazové řádky, například pro dávku firem:

```
php api/bin/parallel-run-check.php --ico=<IČO> --month=RRRR-MM --source=money_s3 \
    --trial-balance=predvaha.csv --saldo=saldo.csv --vat-return=dphdp3.xml \
    [--money-backup=agenda.lz] [--save]
```

Bez `--save` se výsledek jen vypíše, s ním se uloží do historie cyklů.

### 111.6.3 Formát výstupů

Tabulkové výstupy jsou CSV nebo text se středníkem, tabulátorem nebo čárkou,
v UTF-8 nebo Windows-1250. Hlavička nemusí být na prvním řádku, řádky nad ní
(název firmy, období) se přeskočí. Součtové řádky (Celkem, Součet) se nepočítají.
Sloupce se hledají podle názvu bez ohledu na diakritiku a velikost písmen:

| Výstup | Povinné sloupce | Volitelné sloupce |
|---|---|---|
| Obratová předvaha | účet a 3 čísla (PS, obrat, KS netto) nebo 6 čísel (PS MD, PS D, obrat MD, obrat D, KS MD, KS D) | hlavička se nehledá |
| Počty dokladů | Kniha, Počet | |
| Saldokonto | Doklad (Číslo dokladu), Zbývá (Zůstatek, Saldo) | Účet, Firma, IČ |
| Zůstatky bankovních účtů | Účet (číslo účtu, IBAN nebo analytika 221), Zůstatek | Měna, Zůstatek Kč |
| Inventurní soupis majetku | Inventární číslo | Název, Vstupní cena, Oprávky, Zůstatková cena |
| Obraty po střediscích | Středisko a buď Výnosy a Náklady, nebo Účet a Obrat | |
| Rozvaha, výsledovka | Označení (Řádek), Běžné období (Částka) | Strana (A/P) |

- **Předvaha** se čte stejně jako sestava z Money v průvodci převodu: obsahuje-li
  syntetický účet i jeho analytiky, platí syntetický řádek.
- **Počty dokladů**: knihu kontrola pozná podle názvu (*Faktury vydané*, *Přijaté
  faktury*, *Pokladna*, *Bankovní výpisy*, *Interní doklady*). Neznámou knihu
  vynechá s upozorněním.
- **Saldokonto**: částka je zbývající částka kladně (pohledávka i závazek).
  Přijatou fakturu kontrola spáruje podle čísla dokladu dodavatele i podle vlastního
  čísla. Bez sloupce účtu se porovnají všechny saldokontní účty najednou.
- **Obraty po střediscích** jsou obraty měsíce, výnosy i náklady kladně. Řádek
  *Bez střediska* porovná i obraty bez střediska. Na straně MyÚčta se bere první
  aktivní typ dimenze *Středisko* (kapitola 114).
- **Rozvaha**: pasiva poznají sloupec *Strana* s hodnotou P nebo označení začínající
  `P.` (například `P.A.I.`), ostatní řádky jsou aktiva. Aktiva se porovnávají
  v netto hodnotě.

## 111.7 Související kapitoly

- [Přechod z Money S3](106_Prechod_z_Money_S3.md)
- [Přechod z POHODY](107_Prechod_z_POHODY.md)
- [Přechod z PREMIER](109_Prechod_z_PREMIER.md)
- [Obratová předvaha](56_Obratova_predvaha.md)
- [Saldokonto](60_Saldokonto.md)
