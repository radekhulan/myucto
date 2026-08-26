# 85. Peněžní toky a kapitál

**Cesta: `Nástroje → Peněžní toky a kapitál`**

Jedna stránka sestavuje dva samostatné přehledy podle § 18 odst. 2 zákona
o účetnictví: **přehled o peněžních tocích** a **přehled o změnách vlastního
kapitálu**.

## 85.1 Povinnost a období

Oba přehledy se sestaví za celé vybrané účetní období. Stránka současně
vyhodnotí kategorii účetní jednotky. Pro střední a velkou jednotku a pro
jednotku, jejíž nastavení vynutí plný rozsah bez ručního přepisu, je označí
jako povinnou součást závěrky; menší jednotka je může použít dobrovolně.

Kategorie a auditní nastavení vycházejí ze stejné služby jako automatický
rozsah [Rozvahy](50_Rozvaha.md), aby stránka a uzávěrka nepoužívaly jiné
kritérium.

## 85.2 Přehled o peněžních tocích

Systém používá přímou metodu: nerozvíjí výsledek hospodaření o odhady změn
pracovního kapitálu, ale klasifikuje každý zaúčtovaný pohyb peněžních účtů
podle nepeněžního protiúčtu ve stejném zápisu.

Za peněžní prostředky a ekvivalenty považuje prefixy:

- 211 pokladna,
- 213 ceniny,
- 221 bankovní účty,
- 261 peníze na cestě.

U víceřádkového zápisu se částka nerozmnoží spojením s každým protiúčtem.
Každý nepeněžní řádek přispěje částkou `Dal − MD`; díky podvojnosti je jejich
součet přesně změnou peněžních řádků.

### 85.2.1 Klasifikace toků

| Skupina | Protiúčty |
|---|---|
| **Investiční** | 0xx dlouhodobý majetek a 25x krátkodobý finanční majetek |
| **Finanční** | 4xx, úvěry 231/232/461 a vlastní podíly 252 |
| **Provozní** | ostatní účty tříd 1, 2, 3, 5, 6, 7 a 8 |
| **Nezařazené** | kód, který nesplní žádné pravidlo |

Převod mezi peněžními účty se neukáže jako příjem ani výdaj, protože nemá
nepeněžní protiřádek. Bankovní poplatek ve stejném převodu se naopak vykáže.
Otevírací a závěrkové zápisy nejsou peněžní tok a vylučují se.

Počáteční stav zahrne otevírací zápis z prvního dne období, ale jiný běžný
pohyb z tohoto dne už patří do toku, nikoli současně do PS. Konečný stav
vyloučí závěrkový převod reportovaného období, aby uzavření knih peníze
nevynulovalo.

Kontrola na haléře ověřuje:

`provozní + investiční + finanční + nezařazené = konečný stav − počáteční stav`

Nezařazené pohyby mají vlastní rozbalitelnou skupinu a nepřesouvají se tiše
do provozu. Červená kontrola znamená, že výkaz nelze bez prověření použít.

## 85.3 Přehled o změnách vlastního kapitálu

Výkaz není založen na pevném seznamu účtů. Vezme všechny účty osnovy typu
**vlastní kapitál**, včetně firemních analytik, a vypíše jen složky s
nenulovým stavem nebo pohybem.

Protože vlastní kapitál má běžně kreditní zůstatek, částky se zobrazují
kladně ve směru růstu:

- **Počáteční stav** = kredit − debet před běžnými pohyby období, včetně
  otevíracího zápisu prvního dne,
- **Zvýšení** = kreditní obrat období,
- **Snížení** = debetní obrat období,
- **Konečný stav** = kredit − debet k poslednímu dni.

Otevírací ani závěrkové zápisy se nepočítají do zvýšení/snížení a závěrkový
převod reportovaného období se vyloučí i ze stavů. Kontrola ověří každý účet
i součet:

`počáteční stav + zvýšení − snížení = konečný stav`

Zvýšení a snížení se vykazují odděleně. Stejně velký vklad a výplata se tak
neskryjí v nulové čisté změně.

## 85.4 Export

Každý přehled má vlastní **PDF** a **XLSX**. Neslučují se, protože jde o dvě
samostatné přílohy závěrky s odlišnou strukturou. Export obsahuje hlavičku
firmy, celé období, rozpad a kontrolní stav příslušného přehledu.

> 🛈 Oba výkazy jsou pouze pro čtení: nic nezaúčtují ani neopraví. Neshodu je
> nutné vyřešit ve zdrojových zápisech a sestavu znovu načíst.
