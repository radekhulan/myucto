# 113. Přechod mezd v průběhu roku

> Návod pro firmu, která začne vést mzdy v MyÚčtu jindy než v lednu: jak
> převzít měsíce zpracované předchozím programem, aby seděly roční agendy,
> a jak převzetí zkontrolovat. Pro mzdové účetní a správce převodu.

## 113.1 Kdy to potřebujete

Kapitolu otevřete, když:

- začínáte vést mzdy v MyÚčtu uprostřed roku,
- jste převedli data z PAMICA, PREMIER nebo naimportovali hlášení JMHZ a
  chcete ověřit, že převzetí je úplné,
- nemáte z předchozího programu export a převzaté mzdy musíte zadat ručně,
- kontrola převzetí hlásí chybějící počáteční stavy, rozpory nebo hlášení
  JMHZ s jinými údaji, než má konečná mzda,
- se blíží roční zúčtování, vyúčtování daně nebo uzávěrka mzdového roku.

Roční agendy potřebují celý rok: roční zúčtování záloh, potvrzení
o zdanitelných příjmech ze závislé činnosti (§ 38j zákona o daních
z příjmů), vyúčtování zálohové a srážkové daně, mzdový list, evidenční list
důchodového pojištění, průměrný výdělek pro první čtvrtletí i roční limit
DPP. Měsíce před začátkem vedení mezd se proto do MyÚčta **převezmou**.
Nepřepočítávají se a nic se z nich znovu neúčtuje ani nepodává.

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| při zapnutí mezd | Nastavit první mzdové období | `Mzdy → Přehled mezd` |
| před první mzdou | Převzít mzdy a počáteční stavy | `Mzdy → Importy`, záložka **Převzaté mzdy** |
| před první mzdou | Prohlášení, děti a invalidita od ledna | karta osoby, zákonná evidence |
| před první mzdou | Identifikátory ČSSZ | karta vztahu; `Mzdy → Importy`, záložky **JMHZ** a **OIČ z POHODY** |
| po převzetí a před koncem roku | Zkontrolovat převzetí | `Mzdy → Importy`, záložka **Kontrola převzetí** |

## 113.2 Než začnete

1. **První mzdové období** je nastavené při zapnutí mezd na
   `Mzdy → Přehled mezd`. Za převzaté se považují měsíce roku zahájení před
   tímto obdobím. Když mzdy začínají lednem, převzatá část roku neexistuje
   a nic z této kapitoly se neukáže.
2. **Zaměstnanci a pracovní vztahy** jsou založené (ručně nebo převodem).
   Převod z [PAMICA](108_Prechod_z_PAMICA.md), [PREMIER](109_Prechod_z_PREMIER.md)
   nebo [import hlášení JMHZ](90_Nastaveni_mezd.md#9010-krok-za-krokem-import-zamestnancu-z-jmhz-a-registraci)
   převzetí vyplní sám; zbytek kapitoly popisuje ruční doplnění a kontrolu.
3. Průvodce prvním nastavením na `Mzdy → Přehled mezd` přidá u přechodu
   v průběhu roku skupinu kroků **Přechod v průběhu roku**, které vedou na
   všechna místa z této kapitoly.

## 113.3 Krok za krokem: ruční zadání převzatých mezd

Použijte pro měsíce, ze kterých nemáte export ani hlášení.

1. Otevřete `Mzdy → Importy`, záložku **Převzaté mzdy**, blok **Ruční
   zadání převzatých mezd**.
2. Vyberte zaměstnance. Formulář nabídne jeho vztahy, které v převzatých
   měsících trvaly, a měsíce, za které se převzetí čeká.
3. Za každý vztah a měsíc vyplňte úhrny z předchozího programu **v korunách**.
   Pole jsou seskupená na mzdu, pojištění, daň a doby.
4. Měsíc, ve kterém zaměstnanec opravdu neměl žádný příjem (například
   neplacené volno), zaškrtněte jako **Opravdu nula**.
5. Základ daně, slevy, zvýhodnění na děti a dopočet do minima jsou veličiny
   osoby. U souběžných vztahů je stačí vyplnit v jednom řádku měsíce.
6. Uložte.

**Jak poznáte, že je hotovo:** Uložení zapíše převzaté mzdy i počáteční
stavy najednou a kontrola převzetí u osoby nehlásí chybějící měsíce.

### Doplnění z přijatých hlášení JMHZ

Máte-li přijatá hlášení předchozího programu naimportovaná na záložce **JMHZ**,
nemusíte čísla opisovat. Hodí se to i k doplnění měsíců, které jste zadali jen
zčásti.

1. V bloku **Ruční zadání převzatých mezd** vyberte zaměstnance.
2. Klikněte na **Doplnit z přijatých hlášení**. Formulář doplní prázdná pole:
   sociální pojištění, zdravotní pojištění zaměstnance, zálohu, dny pojištění
   a odpracovanou dobu. Čísla, která už vyplněná jsou, nechá být.
3. Přečtěte si poznámku pod tabulkou. Co hlášení nenese, aplikace dopočítá
   stejně jako ve vlastním mzdovém běhu a vyjmenuje to: **ZP zaměstnavatel**,
   **Základ ZP**, **Dopočet ZP do minima** a **Čistá mzda**. Zaměstnanci
   se souběhem vztahů se tyto údaje nedopočítávají a vyplníte je sami.
4. Zkontrolujte čísla a klikněte na **Uložit převzaté měsíce**. Tlačítko samo
   nic neukládá.

**Jak poznáte, že je hotovo:** Na záložce **Kontrola převzetí** zmizí nález
o ručně převzatém měsíci bez dnů účasti nebo pojistného.

> [!TIP]
> Po podaném měsíčním hlášení už nejde změnit základ sociálního pojištění a daň
> převzatých měsíců, protože z nich podané hlášení vyšlo. Dny, odvody, zdravotní
> pojištění a čistou mzdu doplnit lze. Formulář to řekne v poznámce nad tabulkou.

Převzatý běh je neměnný snímek. Chcete-li, aby doložení plateb ukazovalo opravená
čísla, zrušte a znovu převezměte měsíc podle
[§ 80.9](80_Mzdove_behy.md#809-krok-za-krokem-prevzate-mesice-roku-prechodu).
Evidenční list ani daňové doklady z převzatého běhu nečerpají.

## 113.4 Krok za krokem: import převzatých mezd ze souboru

1. Na záložce **Převzaté mzdy** klikněte na **Stáhnout vzor**.
2. Vyplňte soubor: zaměstnance určuje sloupec se jménem spolu s označením
   vztahu, období je měsíc, částky v korunách s nejvýše dvěma desetinnými
   místy (například `12 345,50`). Starší soubory s částkami v haléřích
   import přijme dál.
3. Nahrajte CSV nebo XLSX.
4. Import plní **jen převzaté mzdy**. Počáteční stavy doplníte tak, že
   otevřete ruční zadání ([§ 113.3](#1133-krok-za-krokem-rucni-zadani-prevzatych-mezd)),
   zkontrolujete načtené hodnoty a uložíte; nebo je vyplníte na kartě vztahu
   ([§ 113.5](#1135-krok-za-krokem-pocatecni-stavy-na-karte-pracovniho-vztahu)).

**Jak poznáte, že je hotovo:** Kontrola převzetí neukazuje měsíce **Převzatá
mzda bez počátečního stavu**.

## 113.5 Krok za krokem: počáteční stavy na kartě pracovního vztahu

1. Otevřete `Mzdy → Zaměstnanci`, kartu hlavního vztahu zaměstnance, který
   nastoupil před prvním mzdovým obdobím.
2. V sekci **Počáteční stavy za rok** vyplňte úhrny každého převzatého
   měsíce, ve kterém vztah trval, nebo zaškrtněte **Opravdu nula**.
3. Uložte.

**Jak poznáte, že je hotovo:** Žádný převzatý měsíc vztahu nezůstal prázdný.

## 113.6 Krok za krokem: prohlášení, děti, invalidita a identifikátory

1. Na kartě osoby v zákonné evidenci zaevidujte prohlášení poplatníka,
   vyživované děti a invaliditu s platností **od začátku roku**, ne od
   přechodu.
2. Na kartě pracovního vztahu doplňte identifikátory JMHZ (OIČ a ID
   pojistného vztahu) z předchozího programu. Hromadně je převezmete
   v `Mzdy → Importy` na záložce **JMHZ** nebo **OIČ z POHODY**.
3. Zapište čerpání dovolené za část roku v předchozím programu ručním
   záznamem v knize dovolené (`Mzdy → Absence a dovolená`, záložka
   **Dovolená**), ať zůstatek sedí od prvního měsíce.
4. Pro první čtvrtletí po přechodu zkontrolujte a schvalte návrh průměrného
   výdělku u každého vztahu (`Mzdy → Absence a dovolená`, záložka
   **Průměrný výdělek**).

**Jak poznáte, že je hotovo:** Potvrzení o zdanitelných příjmech jde vystavit
a první měsíční hlášení z MyÚčta navazuje na převzaté identifikátory.

## 113.7 Krok za krokem: kontrola převzaté části roku

1. Otevřete `Mzdy → Importy`, záložku **Kontrola převzetí** (stejná
   kontrola je i pod záložkou **Převzaté mzdy**) a zvolte rok.
2. Projděte sekce **Kontrola převzaté části roku**: chybějící počáteční
   stavy, rozpory mezi vrstvami, měsíce jen s jednou vrstvou, odhadnutý
   nástup, slevu na pojistném bez záměru a hlášení JMHZ podané s jinými
   údaji.
3. U každého nálezu použijte odkaz (**Otevřít kartu**, **Převzatá mzda**,
   **Otevřít hlášení**) a údaj doplňte nebo opravte.
4. Nástup odhadnutý z hlášení opravte na kartě vztahu, nebo ho potvrďte
   tlačítkem **Nástup je správně**.
5. Splňte úkoly, které převzetí založilo na vztazích: **Zaevidovat srážky
   ze mzdy** a **Ověřit rozběhnutou nemoc, PPM nebo ošetřovné**.

**Jak poznáte, že je hotovo:** Kontrola nic nehlásí, nebo zbývají jen
vysvětlené rozdíly (například osvobozený příjem v hrubé mzdě). Uzávěrka roku
a vyúčtování daně se pak na převzetí nezastaví.

## 113.8 Krok za krokem: hlášení JMHZ podané před opravou mzdy

Kontrola převzetí ukáže nález **Hlášení JMHZ podané s jinými údaji, než má
konečná mzda**, když předchozí program podal měsíční hlášení dřív, než se
mzda opravila, a opravné hlášení už nepodal. Převzetí je správné, ČSSZ
a finanční správa ale mají jiné údaje.

1. U nálezu si přečtěte rozpad: veličina, hodnota **V hlášení**, **Převzatá
   mzda** a **Rozdíl**.
2. Klikněte na **Převzatá mzda** a ověřte, že převzatá konečná mzda je
   správná. Odkaz **Otevřít hlášení** ukáže importované hlášení.
3. Zvažte opravné hlášení za dotčené osoby a měsíce. Podává se z programu
   nebo portálu, odkud šlo řádné hlášení
   ([§ 113.10.5](#113105-hlaseni-jmhz-za-prevzate-mesice)).

**Jak poznáte, že je hotovo:** Po přijetí opravného hlášení a jeho importu
nález zmizí. Převod ani uzávěrku nález neblokuje.

### Ručně převzaté mzdě chybí údaje z hlášení

Nález **Ručně převzaté mzdě chybí údaje z přijatého hlášení JMHZ** znamená, že
ČSSZ má z hlášení správná čísla a nula je jen v ručně zadané převzaté mzdě.
Opravné hlášení kvůli tomu nepodávejte. Klikněte u nálezu na **Převzatá mzda**
a doplňte měsíce podle [§ 113.3](#1133-krok-za-krokem-rucni-zadani-prevzatych-mezd).
Podat opravné hlášení má smysl jen tehdy, když je nula v převzaté mzdě opravdu
správná.

## 113.9 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Měsíc … vztahu … nemá vyplněnou žádnou částku. Vyplňte úhrny z předchozího programu, nebo zaškrtněte „Opravdu nula“.“ | Prázdný měsíc není nula | Vyplňte úhrny, nebo zaškrtněte **Opravdu nula**. |
| Uložení ručního zadání odmítnuto | Měsíc mimo převzatou část roku, vztah v měsíci netrval, dvojí zadání téhož měsíce, nepotvrzená nula nebo vynechaný měsíc trvání vztahu | Opravte zadání podle hlášky. |
| Formulář ručního zadání je jen ke čtení | Počáteční stavy jsou zamčené: z úhrnů vyšlo podané hlášení, je vydaný roční doklad, nebo je mzdový rok uzavřený | Formulář řekne důvod; oprava jde jen přes navazující doklady. |
| Mzdový běh za převzatý měsíc nejde založit | MyÚčto ten měsíc nepočítalo | Měsíc převezměte ([Mzdové běhy](80_Mzdove_behy.md)). |
| Potvrzení o zdanitelných příjmech se nevystaví | Chybí prohlášení, děti nebo invalidita od začátku roku | Doplňte zákonnou evidenci osoby ([§ 113.6](#1136-krok-za-krokem-prohlaseni-deti-invalidita-a-identifikatory)). |
| Roční zúčtování se zastaví kvůli dětem | Počáteční stav nese slevu na děti nebo bonus, ale v evidenci osoby za měsíc žádné dítě není | Odkaz v zúčtování vede na kartu zaměstnance; doplňte dítě. |
| Návrh průměrného výdělku se zastaví | Převzatý měsíc nemá odpracované hodiny | Doplňte hodiny v převzatých mzdách. |
| Výpočet zdravotního pojištění skončí chybou | Hlášení zdravotní pojišťovnu nenese | Doplňte ji hromadně ([§ 80.7](80_Mzdove_behy.md#807-krok-za-krokem-doplneni-zakonnych-udaju-po-importu)). |
| Vyúčtování daně se nesestaví | Někomu chybí počáteční stav převzatého měsíce | Panel vypíše komu a za které měsíce; doplňte je. |
| Uzávěrka mzdového roku se zastaví | Stejná mezera v počátečních stavech | Doplňte vypsané zaměstnance. |
| Nález hlášení JMHZ s jinými údaji | Předchozí program podal hlášení před opravou mzdy | Postup v [§ 113.8](#1138-krok-za-krokem-hlaseni-jmhz-podane-pred-opravou-mzdy). |

## 113.10 Podrobnosti a pravidla

### 113.10.1 Dvě vrstvy převzaté části roku

| Vrstva | Co obsahuje | Kdo z ní čte |
|---|---|---|
| **Počáteční stavy ročních součtů** | po měsících a za osobu: základ sociálního pojištění, základ a záloha daně, srážková daň, uplatněné slevy, sleva na děti, daňový bonus | roční zúčtování, potvrzení o zdanitelných příjmech, mzdový list, vyúčtování daně, roční maximum vyměřovacího základu |
| **Převzaté mzdy** | po vztazích a měsících: hrubá a čistá mzda, pojistné, vyměřovací základy, zálohy, dny pojištění, odpracované hodiny, datum výplaty | evidenční list důchodového pojištění, převzatý běh, kontrolní sestava, návrh průměrného výdělku, limit DPP |

Obě vrstvy musí tvrdit totéž. Kde si odporují, ukáže kontrola převzetí.
Formulář ručního zadání načte i převzaté mzdy nahrané souborem, takže po
importu ho stačí otevřít, zkontrolovat a uložit a počáteční stavy se doplní.

### 113.10.2 Prázdný měsíc není nula

Každý převzatý měsíc, ve kterém vztah trval, musí mít vyplněné úhrny, nebo
zaškrtnuté **Opravdu nula**. Stejně se chová import počátečních stavů ze
souboru: řádek měsíce bez jediné vyplněné částky je chyba, výslovně zapsané
nuly znamenají potvrzenou nulu. Nulový stav by tiše podhodnotil roční
zúčtování, potvrzení o příjmech i roční maximum sociálního pojištění.
Sekce **Počáteční stavy za rok** se ukáže u hlavního vztahu zaměstnance,
který nastoupil před prvním mzdovým obdobím.

### 113.10.3 Prohlášení, děti a invalidita

Potvrzení o zdanitelných příjmech za převzaté měsíce uvádí, zda bylo
podepsané prohlášení, na které děti a v jakém stupni invalidity se slevy
uplatnily. Bez této evidence se potvrzení nevystaví a řekne, co chybí.

### 113.10.4 Průměrný výdělek, dovolená a limit DPP

Pro první čtvrtletí po přechodu se průměr pro náhrady počítá z převzatých mezd
předchozího čtvrtletí. Návrh měsíce z převzatých mezd označí. Převzatý měsíc
bez odpracovaných hodin návrh zastaví. Návrh z převzatých mezd se hromadně
nezakládá: zkontrolujte ho a schvalte u každého vztahu zvlášť.

Kontrola mzdového běhu sečte za osobu odpracované hodiny všech jejích DPP
v roce včetně převzatých hodin. Od 240 hodin upozorní, že se osoba blíží
limitu, a po překročení 300 hodin přidá varování
s počtem hodin, podílem převzatých a odkazem na podmínky vztahu. Výpočet
nezastaví, odpracovanou práci je potřeba zaplatit; smluvní vztah je ale nutné
upravit.

### 113.10.5 Hlášení JMHZ za převzaté měsíce

OIČ a ID pojistného vztahu převezměte z předchozího programu; první měsíční
hlášení z MyÚčta na ně navazuje.

Hlášení JMHZ za převzaté měsíce podal předchozí program. MyÚčto k nim
opravné hlášení nesestaví, protože převzatý měsíc nemá mzdový běh, ze kterého
by hlášení vzniklo. Opravu podejte tam, odkud šlo řádné hlášení (předchozí
program nebo portál ČSSZ). Opravené úhrny potom promítněte do převzatých mezd
i počátečních stavů, ať roční doklady odpovídají podanému.

### 113.10.6 Co kontrola převzetí hlídá

- **Chybějící počáteční stavy:** komu v převzatých měsících trval vztah, ale
  počáteční stav za ně nic neříká. U každého je odkaz na kartu zaměstnance.
- **Rozpory mezi vrstvami** po zaměstnancích, měsících a veličinách (základ
  daně proti hrubé mzdě, záloha na daň, srážková daň, daňový bonus, základ
  sociálního a zdravotního pojištění). U základu daně může být rozdíl
  oprávněný, protože osvobozený příjem je v hrubé mzdě, ne v základu daně.
- Měsíce, které mají **jen počáteční stav** (odkaz vede na ruční zadání
  převzatých mezd daného zaměstnance), nebo **jen převzatou mzdu** (odkaz
  vede na kartu zaměstnance, kde se doplní počáteční stav; roční zúčtování
  ani vyúčtování daně s takovým měsícem nepočítají).
- **Nástup odhadnutý z hlášení:** hlášení JMHZ bez data nástupu dá jako
  nástup začátek pojištění v nejstarším hlášeném měsíci. Začíná-li řada
  hlášení třeba v březnu, vztah mohl trvat už v lednu a únoru a převzaté mzdy
  za ně chybí. Import hlášení za chybějící měsíce posune nástup dřív sám.
  Mzdový běh ani měsíční hlášení to neblokuje; vyúčtování daně a uzávěrka
  roku na to upozorní.
- **Sleva na pojistném bez přijatého záměru:** převzatý pracovní poměr má
  vyplněný důvod slevy na pojistném (§ 7a zákona č. 589/1992 Sb.), ale
  v evidenci chybí záměr OZUSPOJ, který ČSSZ přijala. Bez něj se sleva
  neuplatní. Záměr, který oznámil předchozí program, se převezme z jeho
  datové věty OZUSPOJ23 spolu s dnem doručení z protokolu ČSSZ: vznikne
  rovnou jako přijatý, označený jako převzatý, a nevzniká k němu povinnost
  ani lhůta oznámení. Z měsíčního hlášení se záměr neodvozuje, protože
  hlášení neříká, kdy ho ČSSZ přijala.
- **Hlášení JMHZ podané s jinými údaji, než má konečná mzda** (viz níže).

#### Hlášení JMHZ podané před opravou mzdy

Převod přebírá konečný stav mzdového listu. Importované přijaté hlášení
předchozího programu ale může být snímek mzdy před opravou (hlášení odešlo
s plným tarifem, mzda se pak přepočítala a opravné hlášení nepřišlo).
Kontrola proto za osobu a převzatý měsíc porovná převzatou mzdu s účinným
formulářem hlášení: posledním platným řádným nebo opravným podáním; storno
formulář ho ruší.

Nález zakládá jen rozdíl ve **vyměřovacím základu sociálního pojištění**,
v **záloze na daň po slevách** nebo ve **zdravotním pojistném** (zaměstnanec
a zaměstnavatel; vyměřovací základ ZP hlášení nenese). Rozdíl jen
v **zúčtovaném příjmu / hrubé mzdě** nález nezakládá, ukáže se jen v rozpadu:
zúčtovaný příjem v hlášení zahrnuje i osvobozené příjmy, kdežto hrubá mzda
předchozího programu je jeho vlastní pojem a osvobozená plnění (například
stravenkový paušál) v ní být nemusí. Rozdíl pod 1 Kč, u zdravotního pojistného
do 2 Kč, je zaokrouhlení a nehlásí se. Převzatá mzda, která sama vznikla
z hlášení JMHZ, se se svým hlášením neporovnává.

Nález je jen upozornění s rozpadem rozdílů a prokliky na kartu osoby,
převzatou mzdu a importované hlášení. Převod ani uzávěrku neblokuje.

Ručně nebo tabulkou převzatý měsíc, kterému v rozdílných veličinách jen chybí
číslo, se ukáže zvlášť jako neúplná převzatá mzda s pokynem mzdu doplnit.

#### Úkoly založené převzetím

Hlášení JMHZ nese u srážek ze mzdy jen příznak, ne jejich druh ani výši.
Vykazuje-li je poslední převzaté hlášení, převzetí založí na vztahu úkol
**Zaevidovat srážky ze mzdy** s termínem prvního měsíce vedení mezd v MyÚčtu
a tlačítkem do `Mzdy → Srážky a exekuce`. Úkol se splní sám, jakmile je
u osoby zaevidovaná exekuce, insolvence nebo dohoda o srážce. Zdravotní
pojišťovnu hlášení nenese vůbec; doplňte ji hromadně.

Vykazuje-li poslední převzaté hlášení nemoc, peněžitou pomoc v mateřství
nebo ošetřovné, převzetí založí úkol **Ověřit rozběhnutou nemoc, PPM nebo
ošetřovné** s tlačítkem do `Mzdy → Absence a dovolená`. Hlášení nenese den
vzniku nepřítomnosti. Pokračuje-li neschopnost do prvního měsíce v MyÚčtu,
zadejte ji se skutečným dnem vzniku, nebo zadejte dny okna náhrady mzdy,
které už proplatil předchozí program. Neschopnost zadaná od prvního dne
v MyÚčtu bez nich by otevřela nové okno náhrady mzdy a náhrada by se
vyplatila podruhé. Úkol se splní sám, jakmile je u vztahu schválená taková
nepřítomnost se dnem vzniku před prvním měsícem v MyÚčtu nebo se
započtenými dny okna.

Převzatý měsíc z hlášení nese i vyloučené dny podle § 18 odst. 7 zákona
č. 187/2006 Sb. a příjem z nepojištěné činnosti. Obojí potřebuje rozhodné
období oznámení o nemocenském (NEMPRI, viz
[§ 85.14.21](85_Podani_a_hlaseni.md#851421-nemocenske-a-dalsi-zakonne-povinnosti)).

### 113.10.7 Vyúčtování daně a uzávěrka roku

Vyúčtování zálohové a srážkové daně naplní převzaté měsíce z počátečních
stavů a označí je jako převzaté. Odvedenou daň za ně odvodí jako sražené
zálohy snížené o vyplacený bonus; ověřte ji proti osobnímu daňovému účtu.
Přeplatky z ročního zúčtování za předchozí rok, které vyplatil předchozí
program, v převzatých datech nejsou; pokud nějaké byly, doplňte je po
stažení XML v EPO. Postup vyúčtování je v
[§ 85.14.23](85_Podani_a_hlaseni.md#851423-vyuctovani-zalohove-a-srazkove-dane).

Uzávěrka mzdového roku se zastaví na chybějících počátečních stavech
a vypíše dotčené zaměstnance. Rozpory mezi vrstvami uzávěrku nezastaví, ale
ukáže je jako varování s odkazem na kontrolu převzetí.

## 113.11 Související kapitoly

- [Mzdové běhy](80_Mzdove_behy.md): převzaté měsíce roku přechodu.
- [Nastavení mezd](90_Nastaveni_mezd.md): import zaměstnanců z JMHZ
  a registrací.
- [Přechod z PAMICA](108_Prechod_z_PAMICA.md),
  [Přechod z PREMIER](109_Prechod_z_PREMIER.md),
  [Přechod z POHODY](107_Prechod_z_POHODY.md).
- [Podání a hlášení](85_Podani_a_hlaseni.md): opravné hlášení a vyúčtování
  daně.
- [Roční zúčtování](84_Rocni_zuctovani.md).
