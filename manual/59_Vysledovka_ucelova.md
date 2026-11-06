# 59. Výsledovka - účelová

> Sestavení výsledovky, která člení provozní náklady podle funkce (náklady prodeje, odbytové náklady, správní režie), a přiřazení nákladových účtů k těmto funkcím. Kapitola je pro účetní v podvojném účetnictví.

## 59.1 Kdy to potřebujete

Kapitolu otevřete, když:

- firma člení náklady podle funkce a potřebuje výkaz podle přílohy č. 2 části II vyhlášky č. 500/2002 Sb.,
- výkaz nelze sestavit, protože nějaký nákladový účet nemá přiřazenou funkci,
- jste zavedli novou nákladovou analytiku nebo změnili účtový rozvrh,
- potřebujete porovnat celkový výsledek s druhovou výsledovkou.

Cesta: `Účetnictví → Výsledovka - účelová`. Výkaz je dostupný jen pro podvojné účetnictví.

## 59.2 Než začnete

1. **Právo zápisu do účetnictví.** Funkce nákladovým účtům může přiřazovat jen uživatel s právem zápisu do účetnictví.
2. **Rozhodnutí o funkcích.** Číslo nákladového účtu funkci samo neurčuje. Účet 518 může obsahovat službu spojenou s výrobou, odbytem i správou. Rozhodnutí, který účet patří k jaké funkci, je na účetní jednotce, systém ho neodhadne.
3. **Analytiky pro rozdělení.** Chcete-li jeden druh nákladu rozdělit mezi funkce, založte pro funkce samostatné analytiky. Jediný účet nelze rozdělit procentem.

## 59.3 Krok za krokem: přiřazení nákladových účtů k funkcím

1. Otevřete `Účetnictví → Výsledovka - účelová`.
2. Vpravo nahoře vyberte fiskální rok.
3. Pokud se zobrazí žlutý blok **Výkaz zatím nelze sestavit**, podívejte se do bloku **Mapa funkcí** na seznam **Účty s obratem, kterým přiřazení chybí**.
4. U každého účtu v seznamu vyberte v rozbalovacím poli (výchozí volba **vyberte funkci**) jednu z funkcí **A. Náklady prodeje**, **B. Odbytové náklady** nebo **C. Správní režie**. Volba se uloží hned a výkaz se zkusí znovu sestavit.
5. Chcete-li jeden druh nákladu rozdělit mezi funkce, založte nejdřív v [Účtovém rozvrhu](66_Ucetni_osnova.md) analytiky (například 511.100 odbyt, 511.900 správa) a přiřaďte funkci každé zvlášť. Platí pravidlo nejdelšího prefixu.
6. Chybné přiřazení opravíte v tabulce **Mapa funkcí**: klikněte na **×** (**Zrušit přiřazení**) u řádku a účet se vrátí do seznamu nepřiřazených, kde mu vyberete správnou funkci.

**Jak poznáte, že je hotovo:** Seznam **Účty s obratem, kterým přiřazení chybí** zmizel, blok **Výkaz zatím nelze sestavit** se nezobrazuje a pod mapou je vidět výkaz.

> [!WARNING]
> Dokud zůstává nepřiřazený nákladový účet s obratem, výkaz ani export nevznikne. Tiché vynechání by nadhodnotilo hrubý zisk i výsledek hospodaření.

## 59.4 Krok za krokem: sestavení a export výkazu

1. Otevřete `Účetnictví → Výsledovka - účelová`.
2. Vpravo nahoře vyberte fiskální rok a klikněte na **Načíst**. Výkaz se sestaví k dřívějšímu z posledního dne období a dneška, rozsah se zvolí automaticky podle kategorie účetní jednotky.
3. Zkontrolujte sloupce **Běžné období** a **Minulé období**.
4. Pro průběžný výkaz klientovi můžete vedle výběru roku zaškrtnout **Průběžné časové rozlišení**; pravidla jsou stejná jako u druhové výsledovky ([§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni)).
5. Klikněte na **Export PDF** nebo **Export XLSX**. Export jde jen u sestaveného, úplně namapovaného výkazu.
6. Chcete-li výsledek ověřit, porovnejte ho s [druhovou výsledovkou](58_Vysledovka_druhova.md). Celkový výsledek hospodaření musí být shodný, liší se jen členění provozních nákladů.

**Jak poznáte, že je hotovo:** Výkaz je sestavený, PDF a XLSX se stáhly a výsledek za období odpovídá druhové výsledovce.

> [!TIP]
> Při změně účtového rozvrhu nebo zavedení nové nákladové analytiky znovu zkontrolujte seznam nepřiřazených účtů. Mapa je rozhodnutí účetní jednotky, ne automatický odhad systému.

## 59.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Výkaz zatím nelze sestavit** | Nákladový účet s obratem nemá přiřazenou funkci | Přiřaďte funkci všem účtům v seznamu ([§ 59.3](#593-krok-za-krokem-prirazeni-nakladovych-uctu-k-funkcim)). |
| Export PDF nebo XLSX nic nestáhne | Zpřístupní se až po úspěšném sestavení úplně namapovaného výkazu | Dokončete mapu funkcí. |
| Rozdíl proti druhové výsledovce v celkovém výsledku | Obě varianty mají mít shodný výsledek | Zkontrolujte [Obratovou předvahu](56_Obratova_predvaha.md) a mapování výkazů. |
| Nemohu změnit přiřazení | Chybí právo zápisu do účetnictví | Požádejte správce rolí. |
| Nový nákladový účet se po zavedení analytiky vrátil do seznamu | Mapa pokrývá jen existující prefixy | Přiřaďte funkci nové analytice. |

## 59.6 Podrobnosti a pravidla

### 59.6.1 Proč je nutná firemní mapa

Číslo nákladového účtu funkci samo neurčuje. Účet 518 může obsahovat službu
spojenou s výrobou, odbytem i správou. Proto systém spojuje:

- globální verzovanou mapu tržeb, ostatních provozních výnosů/nákladů,
  finanční části, daně a převodu výsledku,
- mapu konkrétní firmy pro řádky A. náklady prodeje, B. odbytové náklady a
  C. správní režie.

Firemní mapu lze zadat pro syntetiku, například `518`, nebo přesnější
analytiku, například `518.100`. Vždy vyhrává nejdelší prefix. Chcete-li jeden
druh nákladu rozdělit mezi funkce, založte pro funkce samostatné analytiky;
jediný účet nelze rozdělit procentem.

Seznam **Účty s obratem, kterým přiřazení chybí** obsahuje zaúčtované nákladové účty s nenulovým
obratem, které nepokrývá globální ani firemní mapa. Přiřazení může měnit
uživatel s právem zápisu do účetnictví.

> [!WARNING]
> **Neúplná mapa sestavení zablokuje.** Dokud zůstává nepřiřazený
> nákladový účet s obratem, výkaz ani export nevznikne. Tiché vynechání by
> nadhodnotilo hrubý zisk i výsledek hospodaření.

### 59.6.2 Zdroj, znaménka a společné mapování

Výkaz čte zaúčtované zůstatky od začátku období do data **Sestaveno k**.
Výnosy vstupují jako `Dal − MD`, náklady jako `MD − Dal`. Závěrkový převod
se vylučuje. Minulé období se počítá se stejnou verzí mapy; firemní funkční
mapa se uplatní i na srovnávací sloupec.

Globální část vychází z ověřené druhové mapy:

- 601, 602 a 604 se slučují do I. Tržby z prodeje výrobků, zboží a služeb,
- druhové řádky III. se slučují do II. Ostatní provozní výnosy,
- druhové řádky F. do D. Ostatní provozní náklady,
- finanční výnosy, náklady, daň a převod výsledku se překódují do řádků
  III.–K. účelového výkazu.

Řádky A./B./C. dostanou pouze účty z firemní mapy. Mapa funkcí nad
výkazem ukazuje aktivní prefixy a umožňuje jejich odstranění.

### 59.6.3 Přesné vzorce

```text
Hrubý zisk nebo ztráta = I. Tržby − A. Náklady prodeje

Provozní VH =
    Hrubý zisk
  − B. Odbytové náklady
  − C. Správní režie
  + II. Ostatní provozní výnosy
  − D. Ostatní provozní náklady

Finanční VH =
    III. − E.
  + IV.  − F.
  + V.   − G. − H.
  + VI.  − I. Ostatní finanční náklady

VH před zdaněním = Provozní VH + Finanční VH
VH po zdanění    = VH před zdaněním − J. Daň z příjmů
VH za období     = VH po zdanění − K. Převod podílu na VH společníkům

Čistý obrat (období od 1. 1. 2024)       = I. + řádky zvolené firmou
Čistý obrat (období započatá před 2024) = I. + II. + III. + IV. + V. + VI.
```

Volba dalších výnosů obchodního modelu je stejná jako u
[druhové výsledovky](58_Vysledovka_druhova.md), pro účelové členění se nastavuje
zvlášť.

Výsledek za období se kontroluje proti nezávislému součtu všech výnosových a
nákladových účtů. Testovaná vazba vyžaduje, aby účelová a
[druhová výsledovka](58_Vysledovka_druhova.md) daly stejný celkový výsledek;
liší se jen členěním provozních nákladů.

### 59.6.4 Rozsah, zobrazení a export

Aktuální stránka nabízí výběr období. Datum sestavení ponechává prázdné, takže
backend použije dřívější z posledního dne období a dneška; rozsah ponechává na
**Automaticky** podle kategorie účetní jednotky. API podporuje i výslovné
datum a plný/malý/mikro rozsah stejně jako druhový výkaz. Malý i mikro rozsah
zobrazí nejvyšší úroveň.

Obrazovka ukazuje částky v Kč a běžné/minulé období. PDF a XLSX se zpřístupní
až po úspěšném sestavení úplně namapovaného výkazu a přebírají stejné
backendové datum a automatický rozsah jako zobrazení.

> [!TIP]
> Při změně účtového rozvrhu nebo zavedení nové nákladové analytiky znovu
> zkontrolujte seznam nepřiřazených účtů. Mapa je rozhodnutí účetní jednotky,
> nikoli automatický odhad systému.

## 59.7 Související kapitoly

- [Výsledovka druhová](58_Vysledovka_druhova.md) - stejný celkový výsledek, jiné členění
- [Rozvaha](57_Rozvaha.md) - mapování účtů a rozvaha po účtech
- [Obratová předvaha](56_Obratova_predvaha.md)
- [Účtový rozvrh](66_Ucetni_osnova.md) - zavedení nových analytik
