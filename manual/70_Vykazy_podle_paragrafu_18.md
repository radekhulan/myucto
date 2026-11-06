# 70. Peněžní toky a kapitál

> Návod, jak sestavit přehled o peněžních tocích a přehled o změnách vlastního kapitálu podle § 18 odst. 2
> zákona o účetnictví a vyexportovat je jako přílohy závěrky. Pro účetní firmy v podvojném účetnictví.

## 70.1 Kdy to potřebujete

Tento kalkulátor používá strukturu vyhlášky 500/2002 Sb. Pro období podle vyhlášky 504/2002 Sb. se zobrazí informace o nedostupnosti; nevytvoří se výkaz s účty jiného rámce. Závěrka 504 zahrnuje rozvahu, výsledovku a přílohu. Dobrovolné přehledy peněžních toků a změn vlastního jmění současný kalkulátor pro tento rámec nepodporuje.

Kapitolu otevřete, když:

- připravujete účetní závěrku a potřebujete oba přehledy jako její přílohy,
- chcete vědět, zda jsou přehledy pro vaši účetní jednotku povinné,
- chcete rozpad peněžních toků na provozní, investiční a finanční činnost,
- potřebujete zjistit, proč kontrola výkazu hlásí nesoulad.

Jedna stránka sestavuje dva samostatné přehledy podle § 18 odst. 2 zákona o účetnictví: **přehled o peněžních
tocích** a **přehled o změnách vlastního kapitálu**.

## 70.2 Než začnete

1. **Podvojné účetnictví** a zaúčtované doklady za období. Přehledy čtou jen zaúčtované zápisy.
2. **Oprávnění** ke čtení účetních sestav.
3. **Účtový rozvrh.** Účty vlastního kapitálu musí být v osnově označené typem vlastní kapitál (viz [Účtový rozvrh](66_Ucetni_osnova.md)).
4. **Kategorie účetní jednotky.** Stránka ji vyhodnotí sama, stejně jako [Rozvaha](57_Rozvaha.md).

## 70.3 Krok za krokem: sestavit přehledy

1. Otevřete `Nástroje → Peněžní toky a kapitál`.
2. V poli **Období** zvolte účetní období. Oba přehledy se sestaví za celé období.
3. Přečtěte si pruh nad přehledy. Říká, zda jsou oba přehledy povinnou součástí účetní závěrky, nebo jde o dobrovolnou sestavu pro vlastní potřebu.
4. V části **Přehled o peněžních tocích** projděte počáteční stav, rozpad na provozní, investiční a finanční činnost a nezařazené pohyby, čistou změnu a konečný stav. Skupiny rozbalíte kliknutím a uvidíte účty.
5. V části **Přehled o změnách vlastního kapitálu** projděte u každého účtu počáteční stav, zvýšení, snížení a konečný stav.
6. Zkontrolujte, že se nezobrazuje červené varování o nesouladu.
7. U každého přehledu klikněte na **PDF** nebo **XLSX** pro export.

**Jak poznáte, že je hotovo:** Oba přehledy se zobrazí bez červeného varování a exporty se stáhnou. Nezařazené
pohyby jsou prázdné, nebo vysvětlené.

> [!WARNING]
> Oba výkazy jsou pouze pro čtení: nic nezaúčtují ani neopraví. Neshodu je nutné vyřešit ve zdrojových
> zápisech a sestavu znovu načíst.

## 70.4 Když něco nejde

<!-- cols: 36 30 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Součet toků nesedí na skutečnou změnu stavu peněžních prostředků | Vada v zápisech nebo v klasifikaci pohybů | Zkontrolujte zápisy na peněžních účtech a jejich protiúčty. Výkaz nelze bez prověření použít. |
| Konečné stavy neodpovídají počátečním stavům upraveným o pohyby | Chyba na účtech vlastního kapitálu | Zkontrolujte zápisy na účtech vlastního kapitálu. |
| V osnově nejsou označeny žádné účty vlastního kapitálu | Účty vlastního kapitálu nemají správný typ | Opravte typ účtů v osnově ([Účtový rozvrh](66_Ucetni_osnova.md)). |
| Pohyby jsou ve skupině **Nezařazeno** | Protiúčet nespadá do žádného pravidla | Rozbalte skupinu, ověřte protiúčet a opravte zápis. Pohyby se nepřesouvají tiše do provozu. |
| Přehledy jsou označené jako nepovinné | Menší účetní jednotka | Můžete je použít dobrovolně. |

## 70.5 Podrobnosti a pravidla

### 70.5.1 Povinnost a období

Oba přehledy se sestaví za celé vybrané účetní období. Stránka současně vyhodnotí kategorii účetní jednotky.
Pro střední a velkou jednotku a pro jednotku, jejíž nastavení vynutí plný rozsah bez ručního přepisu, je
označí jako povinnou součást závěrky; menší jednotka je může použít dobrovolně. Kategorie a auditní
nastavení vycházejí ze stejné služby jako automatický rozsah [Rozvahy](57_Rozvaha.md), aby stránka
a uzávěrka nepoužívaly jiné kritérium.

### 70.5.2 Přehled o peněžních tocích

Aplikace používá přímou metodu: nerozvíjí výsledek hospodaření o odhady změn pracovního kapitálu, ale
klasifikuje každý zaúčtovaný pohyb peněžních účtů podle nepeněžního protiúčtu ve stejném zápisu.

Za peněžní prostředky a ekvivalenty považuje účty s prefixy:

- 211 pokladna,
- 213 ceniny,
- 221 bankovní účty,
- 261 peníze na cestě.

U víceřádkového zápisu se částka nerozmnoží spojením s každým protiúčtem. Každý nepeněžní řádek přispěje
částkou `Dal − MD`; díky podvojnosti je jejich součet přesně změnou peněžních řádků.

#### Klasifikace toků

<!-- cols: 24 76 -->
| Skupina | Protiúčty |
|---|---|
| **Investiční** | 0xx dlouhodobý majetek a 25x krátkodobý finanční majetek |
| **Finanční** | 4xx, úvěry 231/232/461 a vlastní podíly 252 |
| **Provozní** | ostatní účty tříd 1, 2, 3, 5, 6, 7 a 8 |
| **Nezařazené** | kód, který nesplní žádné pravidlo |

Převod mezi peněžními účty se neukáže jako příjem ani výdaj, protože nemá nepeněžní protiřádek. Bankovní
poplatek ve stejném převodu se naopak vykáže. Otevírací a závěrkové zápisy nejsou peněžní tok a vylučují se.

Počáteční stav zahrne otevírací zápis z prvního dne období, ale jiný běžný pohyb z tohoto dne už patří do
toku, nikoli současně do počátečního stavu. Konečný stav vyloučí závěrkový převod reportovaného období,
aby uzavření knih peníze nevynulovalo.

Kontrola na haléře ověřuje:

`provozní + investiční + finanční + nezařazené = konečný stav − počáteční stav`

Nezařazené pohyby mají vlastní rozbalitelnou skupinu a nepřesouvají se tiše do provozu. Červená kontrola
znamená, že výkaz nelze bez prověření použít.

### 70.5.3 Přehled o změnách vlastního kapitálu

Výkaz není založen na pevném seznamu účtů. Vezme všechny účty osnovy typu **vlastní kapitál**, včetně
firemních analytik, a vypíše jen složky s nenulovým stavem nebo pohybem.

Protože vlastní kapitál má běžně kreditní zůstatek, částky se zobrazují kladně ve směru růstu:

- **Počáteční stav** = kredit − debet před běžnými pohyby období, včetně otevíracího zápisu prvního dne,
- **Zvýšení** = kreditní obrat období,
- **Snížení** = debetní obrat období,
- **Konečný stav** = kredit − debet k poslednímu dni.

Otevírací ani závěrkové zápisy se nepočítají do zvýšení a snížení a závěrkový převod reportovaného období se
vyloučí i ze stavů. Kontrola ověří každý účet i součet:

`počáteční stav + zvýšení − snížení = konečný stav`

Zvýšení a snížení se vykazují odděleně. Stejně velký vklad a výplata se tak neskryjí v nulové čisté změně.

### 70.5.4 Export

Každý přehled má vlastní **PDF** a **XLSX**. Neslučují se, protože jde o dvě samostatné přílohy závěrky
s odlišnou strukturou. Export obsahuje hlavičku firmy, celé období, rozpad a kontrolní stav příslušného
přehledu.

## 70.6 Související kapitoly

- [Rozvaha](57_Rozvaha.md)
- [Uzávěrka](72_Uzaverka.md)
- [Účtový rozvrh](66_Ucetni_osnova.md)
