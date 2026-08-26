# 48. Hlavní kniha

**Cesta: `Účetnictví → Hlavní kniha`**

Hlavní kniha je úplný přehled zaúčtovaných pohybů seskupený podle účtů.
Zahrnuje rozvahové, výsledkové, uzávěrkové i podrozvahové účty včetně účtové
třídy 7. Je dostupná jen firmám s podvojným účetnictvím a navazuje na
[Účtový rozvrh](81_Ucetni_osnova.md).

## 48.1 Co je zdrojem sestavy

Sestava čte řádky účetního deníku, které mají vyplněný okamžik zaúčtování.
Koncepty do částek nevstupují; jejich počet v nastaveném rozsahu se zobrazí
ve varování nad tabulkou. Storna se neodstraňují přepsáním historie, ale
vyruší původní částku opačným zaúčtovaným zápisem.

Účetní zápisy mohou vzniknout z vydaných a přijatých dokladů, banky, pokladny,
majetku, mezd, uzávěrky nebo ručního zápisu. Hlavní kniha mezi těmito zdroji
částek nerozlišuje: rozhodující je účet, strana MD/Dal, částka a datum řádku.

## 48.2 Období, rozsah a filtry

- **Období** určuje fiskální rok. Předvyplní se nejnovější otevřené období,
  jinak první dostupné.
- **Od / Do** omezí sestavu uvnitř zvoleného období. Prázdné hodnoty znamenají
  první a poslední den období. Datum mimo období nebo `Od > Do` server odmítne.
- **Rozpad po analytikách** vypíše jednotlivé analytické účty. Bez něj se
  analytiky sečtou pod syntetický účet.
- **Po uzavření knih** započte i vlastní závěrkový převod období. Výchozí
  pohled jej vynechává, aby zůstatky k poslednímu dni uzavřeného roku nebyly
  závěrkovým zápisem vynulované. Otevírací zápis a skladové uzávěrkové pohyby
  zůstávají součástí účetních hodnot.
- **Dodavatel**, **Odběratel** a **Text položky** filtrují zápisy podle
  protistrany nebo položky zdrojové přijaté či vydané faktury. Filtr se
  uplatní na počáteční stav, obraty i měsíční rozpad vybraného výřezu.

Aktuální kombinaci období, rozsahu, rozpadu analytik a vyhledávacích filtrů
lze uložit jako vlastní sestavu a případně ji nastavit jako výchozí. Volba
**Po uzavření knih** se do uložené kombinace nepřenáší. Ovládání **Sloupce**
skrývá či zobrazuje sloupce tabulky a **Hustota** mění řádkování; obě volby
mění jen zobrazení.

## 48.3 Výpočet PS, obratů a KS

Každý řádek účtu obsahuje:

| Hodnota | Výpočet |
|---|---|
| **PS MD / PS Dal** | Netto zůstatek před datem `Od`, zobrazený jen na straně výsledného salda |
| **Obrat MD / Obrat Dal** | Hrubé součty stran za dny `Od` až `Do` včetně |
| **KS MD / KS Dal** | `PS MD − PS Dal + obrat MD − obrat Dal`, opět jen na straně výsledného salda |

Otevírací zápis datovaný prvním dnem období patří do PS, nikoli do obratu.
U rozvahových účtů se počáteční stav nese z historie; existuje-li otevírací
kotva po uzávěrce, počítá se od ní, aby se historie a otevření nezapočetly
dvakrát. U nákladových a výnosových účtů začíná okno PS nejdříve prvním dnem
daného fiskálního období.

Peníze se pro součty převádějí na haléře. Účet bez PS i bez obratu se
nezobrazuje. Řádek **Součty** sčítá všechny zobrazené účty.

## 48.4 Měsíční rozpad a řádky měsíce

Šipka na začátku řádku rozbalí obrat MD a Dal po kalendářních měsících
zasahujících do nastaveného rozsahu. Jde o rozpad obratu, nikoli zůstatku:
měsíc bez pohybu proto ukazuje nuly. Měsíční částky se seskupují stejně jako
hlavní řádek — podle syntetiky, nebo po analytikách. Součet měsíců se rovná
obratu za celý rozsah; otevírací zápis prvního dne období je i tady součástí
počátečního stavu, ne lednového obratu.

Kliknutí na měsíc s pohybem rozbalí **jednotlivé řádky deníku** za ten měsíc:
datum, doklad, analytiku, popis a částku na správné straně. Doklad vede na
prvotní doklad (viz [48.5](#485-opis-uctu)). Zobrazí se prvních 200 řádků;
u delších měsíců odkaz v patičce pokračuje v opisu účtu za stejný měsíc.

Kliknutí na **kód účtu** otevře [kartu účtu](81_Ucetni_osnova.md#817-karta-uctu)
se zachovaným rozsahem `Od / Do`. Z karty vede odkaz zpět do hlavní knihy;
kniha pak dotčený účet sama rozbalí a odroluje k němu.

## 48.5 Opis účtu

Opis účtu otevřeš z karty účtu nebo z rozbaleného měsíce; rozsah `Od / Do`
se přenáší. Opis syntetického účtu zahrnuje i jeho analytiky a nahoře ukazuje
PS, obrat MD, obrat Dal a KS. Je-li opis složený z víc analytik, přibude
sloupec **Analytika** s odkazem na kartu příslušného účtu.

Pohyby jsou řazené podle data, ID zápisu a čísla řádku. Sloupec **Zůstatek**
je běžící saldo `PS + MD − Dal` počítané nad celým rozsahem, takže navazuje
správně i na dalších stránkách. Výchozí stránka má 50 řádků. Kladný zůstatek
znamená MD, záporný Dal.

Doklad v opisu vede podle zdroje na vydanou fakturu, přijatou fakturu, bankovní
výpis, pokladní doklad, kartu majetku nebo na vyrovnaný doklad zápočtu; bez
rozpoznaného zdroje na konkrétní zápis účetního deníku. PDF/XLSX opisu obsahuje
celý zvolený rozsah, nejen aktuální stránku.

## 48.6 Export a návaznosti

**Export PDF** a **Export XLSX** používají stejné období, rozsah, rozpad
analytik, pohled před/po uzavření a filtry jako obrazovka. Název souboru
obsahuje fiskální rok. Stejná logika PS/obrat/KS se používá také v
[Obratové předvaze](49_Obratova_predvaha.md); výkazové mapování a zákonné
řádky popisuje [Rozvaha](50_Rozvaha.md).

> ⚠️ **Koncepty nejsou součástí částek.** Žluté upozornění znamená, že se po
> jejich zaúčtování hlavní kniha ještě změní.
