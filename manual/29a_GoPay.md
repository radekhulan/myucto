# GoPay

Stránka **Peníze > GoPay** zpracovává měsíční XML vyúčtování GoPay. Samostatný
GPC výpis GoPay ani PDF vyúčtování se nenačítají. XML obsahuje jednotlivé platby,
vratky, poplatky i souhrnnou částku odeslanou na bankovní účet.

Funkce je dostupná firmám s podvojným účetnictvím.

## První nastavení

Před prvním importem vyberte analytické účty:

- **GoPay účet (221)** eviduje peníze, které už zákazníci zaplatili, ale GoPay je
  ještě neposlal na běžný účet.
- **Cílový bankovní účet (221)** je analytika skutečného účtu, na který GoPay
  posílá vyúčtování.
- **Pohledávky (311)** je účet použitý na vystavených fakturách a dobropisech.
- **Nákladový účet poplatků** slouží pro poplatky GoPay, například analytika účtu
  568.
- **Peníze na cestě (261)** propojí souhrnný převod z GoPay s jednou příchozí
  platbou na bankovním výpisu.

GoPay účet a cílový bankovní účet musí být dvě různé analytiky. Dále nastavte účet
odesílatele GoPay, kód banky a povolený rozdíl data mezi XML a bankovním pohybem.

## Import vyúčtování

1. V administraci GoPay stáhněte Clearing XML za uzavřené období.
2. Na stránce **Peníze > GoPay** vyberte soubor a zvolte **Načíst a zaúčtovat**.
3. Aplikace ověří strukturu XML i všechny kontrolní součty.
4. Jednotlivé pohyby spáruje s doklady a vytvoří účetní zápisy.
5. Souhrnnou výplatu se pokusí spárovat s již naimportovaným bankovním výpisem.

Stejné XML lze nahrát opakovaně. Nevzniknou duplicitní účetní zápisy, existující
vyúčtování se pouze znovu zpracuje. To je užitečné, pokud byl bankovní výpis nebo
některý doklad načten až později.

Pokud příchozí převod nejprve dorazí jen jako bankovní avízo, otevřete u něj
**Ručně spárovat**. Aplikace nabídne odpovídající GoPay vyúčtování podle částky,
měny, variabilního symbolu, data a účtu odesílatele. Avízo se nezaúčtuje. Po importu
skutečného GPC výpisu se vazba automaticky převede na jeho bankovní pohyb a teprve
ten vytvoří zápis přijetí převodu `221 Banka / 261 Peníze na cestě`.

## Párování dokladů

Platba se páruje přednostně podle GoPay ID uloženého u úhrady faktury. Pokud tam
ID není, použije se přesné **Číslo objednávky dodavatele** uložené na dokladu spolu
s částkou a měnou. U historických dokladů zůstává fallback na řádek
`Objednávka: MYU...` v poznámce. Vratka se stejným způsobem páruje s dobropisem.

Doklad musí být před importem zaúčtovaný. Pokud chybí, částka nesouhlasí nebo je
výsledek nejednoznačný, pohyb zůstane ve stavu **Ke kontrole**. Po opravě dokladu
zvolte u vyúčtování **Zpracovat znovu**.

V účetním deníku je vazba obousměrná. U zaúčtování faktury nebo dobropisu se v
části **Souvisí** zobrazí konkrétní GoPay pohyb a jeho zápis. Z GoPay zápisu se lze
stejným způsobem vrátit na zaúčtování dokladu. Vazba se odvozuje z uloženého GoPay
pohybu, takže se zobrazuje i u dříve importovaných vyúčtování.

## Účetní zápisy

Automatické účtování používá následující schéma:

| Událost | Má dáti | Dal |
|---|---|---|
| Platba zákazníka | 221 GoPay | 311 Pohledávky |
| Vratka zákazníkovi | 311 Pohledávky | 221 GoPay |
| Poplatek GoPay | Nákladový účet | 221 GoPay |
| Dobropis poplatku | 221 GoPay | Nákladový účet |
| Odeslání měsíčního vyúčtování | 261 Peníze na cestě | 221 GoPay |
| Přijetí převodu na bankovní účet | 221 Banka | 261 Peníze na cestě |

Příchozí bankovní převod se páruje pouze při shodě částky, měny, clearingového
variabilního symbolu, časového okna a nastaveného účtu odesílatele. Samotné číslo
účtu GoPay nestačí, protože stejný účet používají i jiná vyúčtování.

## Kontrola výsledku

Seznam ukazuje počet všech a zaúčtovaných pohybů. Stav **Hotovo** znamená, že jsou
zaúčtované všechny pohyby a je spárovaný i bankovní převod. Stav **Vyžaduje
kontrolu** obsahuje v detailu konkrétní důvod u problematického pohybu nebo převodu.

Původní XML zůstává uložené u vyúčtování a lze je kdykoli znovu stáhnout.
