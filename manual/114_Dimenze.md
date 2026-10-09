# 114. Dimenze

> Návod, jak výnosy a náklady členit podle střediska, projektu, vozidla,
> lokality nebo vlastního typu a jak z toho dostat sestavy. Pro účetní
> a manažery firem, které chtějí vědět, kolik který projekt nebo středisko vydělává.

Dimenze jsou analytické členění dokladů a účetních zápisů: středisko, projekt,
vozidlo, lokalita, obchodní případ nebo vlastní typ. Každý doklad i každý řádek
deníku může nést hodnotu každého typu (nejvýš jednu za typ) a sestavy pak ukážou
výnosy a náklady po hodnotách.

Dimenze nemění účty, částky ani období. Jde jen o analytiku, proto je lze doplnit
nebo změnit i u zaúčtovaného dokladu a v uzavřeném období.

## 114.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete vědět, kolik vydělal nebo prodělal jednotlivý projekt či středisko,
- potřebujete u dokladů zadat, ke kterému středisku patří,
- sdílíte projekt nebo lokalitu mezi víc firmami skupiny,
- chcete, aby se středisko předvyplnilo samo podle klienta, zakázky nebo dodavatele,
- náklad patří dvěma střediskům a potřebujete ho rozdělit,
- manažer střediska má schvalovat přijaté faktury a účtenky před přijetím,
- chcete vynutit, aby každý náklad a výnos měl středisko,
- chcete, aby středisko určovalo analytický účet (518.100, 518.200).

<!-- cols: 28 42 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavedení | Zapnout dimenze a založit typy a hodnoty | `Firma → Nastavení`, box **Dimenze**; `Firma → Dimenze` |
| při práci s doklady | Vybrat dimenze na faktuře, pokladním dokladu, bankovním pohybu | detail a editor dokladu, panel **Dimenze** |
| při nové firmě nebo zakázce | Nastavit výchozí dimenze klienta a zakázky | formulář klienta a zakázky, sekce **Výchozí dimenze** |
| při schvalování nákupu | Rozhodnout o dokladu čekajícím na schválení | `Nákup → Ke schválení` |
| po skončení období | Zkontrolovat, kterým řádkům dimenze chybí | `Firma → Dimenze`, záložka **Pravidla**, **Zkontrolovat deník** |
| měsíčně nebo ročně | Zobrazit výsledky po dimenzi | `Grafy → Dimenze`, `Účetnictví → Výkazy po dimenzi` |

## 114.2 Než začnete

1. **Licence.** Dimenze jsou součástí licencovaného účetnictví. Lze je zapnout
   a používat během zkušební doby nebo s platnou licencí, která odemyká účetní
   modul. Po jejím skončení zůstane nastavení firmy uložené, ale dimenze se
   nezobrazí a nelze s nimi pracovat, dokud nebude licence znovu platná.
2. **Oprávnění.** Číselník dimenzí a výběry na dokladech vidí uživatel s právem
   číst účetnictví, měnit je smí uživatel s právem zápisu do účetnictví. Zapnutí
   dimenzí patří k nastavení firmy, skupinu firem spravuje správce firmy.
   Výchozí dimenze klienta a zakázky smí měnit ten, kdo smí upravovat klienta,
   resp. zakázku. Schvalování řídí oprávnění **Schvalovat přijaté doklady**.
3. **Podvojné účetnictví** pro sestavy v menu **Grafy → Dimenze** a pro většinu
   účetních funkcí (účtotvorná dimenze, pravidla, účty produktů).

## 114.3 Krok za krokem: zapnout dimenze a založit typy

1. Otevřete `Firma → Nastavení` a v boxu **Dimenze** dimenze zapněte
   (nebo na stránce Dimenze klikněte na **Zapnout dimenze**).
2. V menu přibude položka `Firma → Dimenze` a v Účetnictví sestava **Výkazy po dimenzi**.
3. Otevřete `Firma → Dimenze`. Výchozí typy (Středisko, Projekt, Vozidlo, Lokalita
   a Obchodní případ) založíte tlačítkem **Založit výchozí typy**. Zapnete-li dimenze
   tlačítkem **Zapnout dimenze** na této stránce, výchozí typy se založí rovnou.
4. U každého typu přidejte hodnoty. Hodnotu můžete zařadit pod nadřízenou, tím
   vznikne strom.
5. Potřebujete-li vlastní typ, klikněte na **Nový typ** a zadejte název, kód, druh
   a zda se nabízí na dokladech (**Nabízet na dokladech a v deníku**).

**Jak poznáte, že je hotovo:** v číselníku vidíte typy s hodnotami a výběr dimenzí
se objeví na dokladech.

> [!TIP]
> Dokud jsou dimenze vypnuté, nikde se nic nezobrazí a doklady ani deník se nemění.

## 114.4 Krok za krokem: zadat dimenze na dokladu

1. Otevřete doklad. Výběr dimenzí mají přijaté a vydané faktury (v hlavičce
   i u každé položky), pokladní doklady, bankovní pohyby, ruční zápisy (u každého
   řádku), šablony ručních zápisů, ostatní pohledávky a závazky a karty dlouhodobého majetku.
2. U zaúčtovaného dokladu (faktura, pokladní doklad, ostatní pohledávka nebo závazek, karta majetku) otevřete panel **Dimenze** na detailu.
3. Vyberte hodnotu u typu. Hledání funguje v kódu, názvu i v nadřízených
   hodnotách: hledání „Morava“ najde i hodnotu Brno, která pod Moravou leží.
4. Uložte. Změna se promítne i do již zaúčtovaných řádků deníku.

**Bankovní pohyb:**

1. V detailu bankovního výpisu otevřete u pohybu nabídku **…** a zvolte **Dimenze** (nebo klikněte na štítky dimenzí pod protistranou).
2. Pod pohybem se otevře výběr dimenzí. Vyberte hodnoty.
3. Klikněte na **Uložit dimenze**.

**Řádek zápisu v deníku:**

1. Rozbalte zápis v účetním deníku.
2. Klikněte na **Upravit dimenze** a uložte hodnoty.

**Jak poznáte, že je hotovo:** doklad nebo pohyb ukazuje dimenze jako štítky a sestavy po dimenzi je zahrnují.

> [!WARNING]
> U zápisu z dokladu platí doklad. Další změna dimenzí dokladu ruční úpravu řádků přepíše.

## 114.5 Krok za krokem: nastavit výchozí dimenze klienta a zakázky

1. Otevřete formulář klienta nebo zakázky.
2. V sekci **Výchozí dimenze** zvolte pro každý typ nejvýše jednu hodnotu (firemní i globální).
3. Uložte. Detail klienta a zakázky ukazuje výchozí dimenze jako štítky.

Výchozí dimenze se pak předvyplní na novém dokladu. Pravidla jsou v
[§ 114.11.5](#114115-vychozi-dimenze).

**Jak poznáte, že je hotovo:** nová faktura klienta nebo zakázky má dimenze předvyplněné.

## 114.6 Krok za krokem: rozdělit náklad mezi více hodnot

1. Otevřete detail dokladu a v panelu **Dimenze** klikněte na **Rozpad**
   (u ostatní pohledávky nebo závazku a karty majetku také tam). Rozpad jednoho
   řádku děláte v účetním deníku v úpravě dimenzí řádků.
2. Vyberte typ dimenze a hodnoty s procentem (u řádku deníku také s částkou).
3. Zkontrolujte, že součet dává 100 %, resp. částku řádku.
4. Uložte.

**Jak poznáte, že je hotovo:** typ s rozpadem ukazuje víc hodnot a sestavy rozdělují částku poměrem.

## 114.7 Krok za krokem: nastavit schvalování přijatých dokladů

1. Otevřete `Firma → Dimenze` a vyberte typ (typicky Středisko).
2. V hlavičce typu zapněte přepínač **Schvalování dokladů**.
3. U každé hodnoty vyberte **Schvalovatele**. Schvaluje se jen u hodnot, které schvalovatele mají.
4. Volitelně v **Upravit typ** vyplňte limit **Schvalovat od částky (Kč bez DPH)**. Doklady pod limitem se neschvalují. Prázdné pole nebo 0 znamená, že se schvaluje vždy.
5. Přijatý doklad pak v seznamu přijatých faktur nejde přijmout rovnou. Označíte-li
   jej jako přijatý, odešle se ke schválení.
6. Schvalovatel dostane e-mail s odkazem, nebo otevře `Nákup → Ke schválení`.
   Doklad **schválí**, nebo **zamítne** s povinným důvodem.

**Jak poznáte, že je hotovo:** po schválení všemi se doklad přijme sám a dostane interní číslo.
Zamítnutý doklad zůstává konceptem a účetní ho najde v akčních položkách.

## 114.8 Krok za krokem: vynutit dimenzi pravidlem

1. Otevřete `Firma → Dimenze` a záložku **Pravidla**.
2. Klikněte na **Nové pravidlo** a zadejte **Účty** (například `5, 6, !59, !69`), **Typ dimenze** a **Vynucení** (**Povinné**, **Varovat**, **Jen doplnit**).
3. Volitelně doplňte **Výchozí hodnotu** a **Platí od / do**.
4. Pravidlo uložte.
5. Zpětně zkontrolujte historii tlačítkem **Zkontrolovat deník** pod seznamem pravidel.

**Jak poznáte, že je hotovo:** zaúčtování dokladu bez povinné dimenze skončí chybou
(u **Varovat** upozorněním) a kontrola deníku nenajde chybějící hodnoty.

Podrobnosti v [§ 114.11.10](#1141110-pravidla-dimenzi-podle-uctu).

## 114.9 Krok za krokem: zobrazit výsledky po dimenzi

**Přehled s grafy:**

1. Otevřete `Grafy → Dimenze` (firmy se zapnutými dimenzemi v podvojném účetnictví i v daňové evidenci).
2. Zvolte rok, typ dimenze a případně firmu.
3. Kliknutím na hodnotu vyfiltrujete karty, měsíční vývoj a spodní tabulku.

**Účetní výkaz:**

1. Otevřete `Účetnictví → Výkazy po dimenzi`.
2. Vyberte typ dimenze a období, přepněte záložku (**Výsledovka**, **Peněžní tok**).
3. Tlačítkem **Export XLSX** stáhnete aktivní záložku, výsledovku také jako PDF.

**Filtr ve výkazech:** **Výsledovka**, **Rozvaha**, **Obratová předvaha** a **Hlavní kniha** mají filtr na dimenzi (typ, hodnota, volitelně včetně podřízených).

**Jak poznáte, že je hotovo:** vidíte výnosy, náklady a zisk po hodnotách a řádek **Celkem**.

## 114.10 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| V menu není Dimenze | Dimenze nejsou zapnuté, nebo chybí licence účetnictví | Zapněte je v `Firma → Nastavení`, v boxu **Dimenze**, a ověřte licenci |
| **Zatím nemáte zadané žádné dimenze** na stránce Grafy | Neexistuje žádná hodnota dimenze | Přidejte hodnotu v `Firma → Dimenze` a přiřaďte ji dokladům |
| Globální typ nejde založit | Firma nepatří do skupiny firem | Na záložce **Globální (skupina firem)** založte skupinu nebo firmu připojte |
| Zaúčtování skončilo chybou s účtem, stranou, částkou a chybějící dimenzí | Pravidlo s vynucením **Povinné** | Doplňte dimenzi na dokladu nebo řádku |
| Aplikace upozorní, že doklad je potřeba **přeúčtovat** | Změna dimenzí by vyžadovala rozdělení řádku nebo přesun na jinou analytiku | Doklad přeúčtujte |
| Doklad v cizí měně se vždy schvaluje | Doklad nemá kurz, nejde ocenit a limit se neuplatní | Doplňte kurz |
| Odkaz ke schválení nefunguje | Platí 14 dní a jen pro jedno rozhodnutí, nová připomínka vydá nový odkaz | Použijte nejnovější e-mail nebo `Nákup → Ke schválení` |
| Zaúčtování skončilo chybou s názvem účtu produktu | Účet produktu zmizel z rozvrhu nebo se deaktivoval | Opravte účet na kartě produktu nebo ho znovu aktivujte |
| Změna dimenze u dokladu v uzavřeném období je odmítnutá | Změna by přesunula řádek na jinou analytiku | Úprava přímo na řádku deníku, která by měnila účet, se také odmítne; doklad přeúčtujte |
| Rozvaha po dimenzi nesedí | Hodnotu nenesou obě strany zápisů, například úhrada v bance bez projektu | Doplňte dimenzi na bankovní pohyb |

## 114.11 Podrobnosti a pravidla

### 114.11.1 Firemní a globální dimenze

- **Firemní** typ patří jedné firmě. Jeho hodnoty vidí jen ona.
- **Globální** typ patří **skupině firem** a jeho hodnoty sdílí všechny firmy
  skupiny. Typicky projekt nebo lokalita, které vede mateřská firma i její SPV:
  stejný projekt se pak dá sečíst přes všechny firmy.

Skupinu firem spravuje správce firmy na záložce **Globální (skupina firem)**: založí
novou skupinu, nebo firmu připojí ke skupině, do které patří jiná firma, kterou
spravuje. Firma, která skupinu opustí, přestane globální dimenze skupiny vidět.

Výchozí typy Projekt a Lokalita vzniknou jako globální, pokud firma do skupiny
patří. Jinak jsou firemní. Středisko, Vozidlo a Obchodní případ jsou vždy firemní.

### 114.11.2 Typy a hodnoty

Typ má název, kód, druh a nastavení, zda se nabízí na dokladech. Typ, který se
nenabízí na dokladech, se zadává jen v deníku.

Hodnoty tvoří **strom**: každá hodnota může mít nadřízenou. Sestava za nadřízenou
hodnotu sečte celou její větev. Hodnotu lze:

- přidat jako podřízenou jiné hodnoty,
- přejmenovat nebo přesunout pod jinou nadřízenou (ne pod sebe ani pod svou podřízenou),
- **uzavřít**, když už se nepoužívá. Uzavřená hodnota se na dokladech nenabízí,
  na dokladech a zápisech, kde už je, zůstává,
- smazat. Hodnota, která je použitá nebo má podřízené, se místo smazání uzavře.

Kód hodnoty se po založení nemění.

K hodnotě lze uvést **odpovědnou osobu** (uživatele firmy nebo text). Je to údaj
o hodnotě: do účetního deníku se nekopíruje, zápisy nesou jen odkaz na hodnotu.

#### Vazby na evidence firmy

<!-- cols: 30 70 -->
| Druh typu | Vazba hodnoty |
|---|---|
| Středisko | středisko v číselníku středisek (`Nástroje → Střediska`) |
| Vozidlo | vůz z knihy jízd |
| Projekt | zakázka pro fakturaci (`Zakázky`) |

Nová hodnota typu Středisko si středisko se stejným kódem v číselníku najde, nebo
ho založí. Uzavření hodnoty středisko deaktivuje. Řádky deníku, které nesou jen
kód střediska (mzdy, starší ruční zápisy), se v sestavách po středisku započítají
k hodnotě navázané na to středisko.

Hodnota projektu navázaná na zakázku doplní zakázku i na řádky deníku, takže se
promítne do ziskovosti zakázky.

Globální hodnota na záznamy jedné firmy odkazovat nemůže.

### 114.11.3 Dimenze na dokladech

Při zapnutých dimenzích mají výběr dimenzí:

- přijaté a vydané faktury, v hlavičce i u každé položky,
- pokladní doklady,
- bankovní pohyby (v detailu výpisu i v seznamu všech pohybů, viz níže),
- ruční zápisy (interní doklady), u každého řádku,
- šablony ručních zápisů (dimenze se předvyplní do zápisu),
- ostatní pohledávky a závazky, v editoru i na detailu,
- karty dlouhodobého majetku, v editoru i na detailu (viz [Majetek](#114118-majetek)).

Výběr hledá v kódu, názvu i v nadřízených hodnotách: hledání „Morava“ najde
i hodnotu Brno, která pod Moravou leží.

Na detailu faktury, pokladního dokladu, ostatní pohledávky nebo závazku a karty
majetku lze dimenze změnit i u zaúčtovaného dokladu.

#### Ostatní pohledávky a závazky

Dimenze hlavičky ostatní pohledávky nebo závazku se při zaúčtování zapíšou na
všechny řádky zápisu, tedy na saldokontní účet i na protiúčty. Typ, který doklad
nemá, doplní výchozí dimenze protistrany z adresáře. Na detailu dokladu lze
v panelu **Dimenze** zadat i rozpad mezi více hodnot. Rozvrh opakování přenese
dimenze zdrojového dokladu na každý vygenerovaný výskyt. Bankovní pohyb nebo
pokladní doklad, který položku hradí, dostane její dimenze ve chvíli spárování
úhrady; odpojení úhrady je zase odebere. Hradí-li pohyb nebo pokladní doklad víc
položek s různými hodnotami, rozdělí se jejich dimenze po řádcích stejně jako
u úhrady více faktur. Změna dimenzí zdrojového dokladu rozvrhu se promítne i do
výskytů, které jsou zatím koncepty.

#### Zápočty

- **Vzájemný zápočet** vlastní dimenze nemá. Řádek pohledávky dostane dimenze
  započtených vydaných faktur, řádek závazku dimenze započtených přijatých faktur.
  Liší-li se faktury jedné strany v hodnotě typu, nese řádek rozpad v poměru
  započtených částek. Typ, který některé faktuře chybí, doplní výchozí dimenze
  partnera zápočtu.
- **Zápočet proti účtu** (úhrada faktury zápočtem proti zvolenému účtu) přebírá
  dimenze vyrovnávané faktury, stejně jako její bankovní úhrada.

Zápočty přebírají z faktury i rozpad hlavičky (například 60/40 mezi dvě
střediska), takže saldo pohledávek a závazků po dimenzi sedí.

Změna dimenzí faktury se promítne i do zápisů jejích zápočtů. Náhled uložení
dimenzí na detailu faktury ukáže i dotčené řádky úhrad a zápočtů.

#### Bankovní pohyby

V detailu bankovního výpisu ukazuje každý pohyb své dimenze jako štítky pod
protistranou. Dimenze se nastavují v nabídce **…** u pohybu položkou **Dimenze**
(nebo kliknutím na štítky), která pod pohybem otevře výběr dimenzí; tlačítko
**Uložit dimenze** je uloží. Zaúčtování pohybu je zapíše na všechny řádky jeho
zápisu v deníku. Změna dimenzí už zaúčtovaného pohybu se do řádků deníku promítne
hned, i v uzavřeném období, protože mění jen analytické členění.

#### Jak se dimenze dostanou do deníku

- Dimenze hlavičky dokladu se při zaúčtování zapíšou na všechny řádky zápisu.
- Dimenze položky má přednost před hlavičkou (typ po typu) na výsledkových řádcích,
  tedy na nákladu a výnosu. Položka, která hodnotu typu nemá, dostane výchozí
  dimenzi svého produktu, jinak jeho kategorie (viz [Produkt a kategorie](#114119-produkt-a-kategorie)).
  Mají-li položky jednoho nákladového řádku různé dimenze, rozdělí se řádek při
  zaúčtování v poměru základu položek. Účet, strana a součet se nemění a zápis
  zůstává vyvážený na haléř.
- Storno přebírá dimenze stornovaného řádku.
- Kopie faktury, dobropis a vyúčtovací faktura ze zálohové faktury přebírají
  dimenze hlavičky i položek původního dokladu.
- Šablona pravidelné fakturace má vlastní dimenze hlavičky i položek (v editoru
  šablony nad položkami a ikonou u položky). Každá vygenerovaná faktura je dostane.
- Sleva v procentech z hlavičky faktury nese dimenze položek, které zlevňuje
  (položky téže sazby DPH, v poměru jejich základu).
- Změna dimenzí už zaúčtovaného dokladu se promítne do jeho řádků deníku. Řádky
  se přitom nedělí: když by rozdělení bylo potřeba (položky nově nesou různé
  hodnoty), aplikace upozorní, že doklad je potřeba **přeúčtovat**.

U řádků zápisu lze dimenze upravit i ručně v rozbaleném zápisu deníku tlačítkem
**Upravit dimenze**. U zápisu z dokladu platí doklad: další změna dimenzí dokladu
ruční úpravu řádků přepíše.

### 114.11.4 Účetní deník

Účetní deník má ve filtrech výběr dimenze: typ, hodnotu a volbu **včetně
podřízených**. Deník pak ukáže celé zápisy, u kterých aspoň jeden řádek nese
vybranou hodnotu (nebo hodnotu pod ní). U Střediska se počítají i řádky, které
nesou jen kód navázaného střediska, stejně jako v sestavách. Filtr se ukládá do
adresy stránky i do uložených pohledů a platí i pro export deníku do PDF a XLSX.

### 114.11.5 Výchozí dimenze

Klient a zakázka mohou mít **výchozí dimenze**: pro každý typ nejvýš jednu hodnotu,
firemní i globální. Nastavují se ve formuláři klienta a zakázky v sekci **Výchozí
dimenze**, detail klienta a zakázky je ukazuje jako štítky. Karta klienta slouží
pro obě role, výchozí dimenze tedy platí pro vystavené faktury odběratele
i přijaté faktury dodavatele.

Kde se použijí:

- **Vystavená faktura** a **přijatá faktura**: po výběru klienta (dodavatele) nebo
  zakázky se předvyplní prázdné dimenze hlavičky. Stejně u nové faktury otevřené
  z karty klienta nebo zakázky a u přijaté faktury vytěžené z PDF nebo ISDOC.
- **Pokladní doklad**: u úhrady faktury se převezmou dimenze placené faktury,
  jinak výchozí dimenze partnera, jehož název přesně odpovídá klientovi
  v adresáři.
- **Bankovní pohyb**: u spárovaného pohybu nabídne panel dimenzí hodnoty
  spárované faktury (její dimenze, případně výchozí dimenze její zakázky
  a klienta). Uloží se tlačítkem **Uložit dimenze**.
- **Ostatní pohledávka a závazek**: po výběru protistrany z adresáře se
  předvyplní prázdné dimenze hlavičky.
- **Karta majetku**: předvyplní se dimenze přijaté faktury, ze které karta vznikla.

Pravidla přednosti:

- Výchozí dimenze zakázky mají přednost před výchozími dimenzemi klienta, typ
  po typu. Typ, který zakázka nenastavuje, doplní klient.
- Předvyplnění nikdy nepřepíše hodnotu, kterou už doklad má nebo kterou jste
  vybrali ručně. Když změníte klienta nebo zakázku, změní se jen hodnoty, které
  se předvyplnily automaticky a které jste neupravili.
- Uzavřená hodnota a neaktivní typ se nepředvyplňují.

Při zaúčtování platí totéž i pro doklady, které editorem neprošly (import,
vytěžení, opakované faktury, automatizace): typ, pro který doklad hodnotu nemá,
dostane výchozí hodnotu zakázky, jinak klienta. Dimenze uvedená na dokladu
vždy vyhrává. U pokladního dokladu platí výchozí dimenze jeho zakázky a pak
dimenze placené faktury, u bankovního pohybu dimenze spárované faktury.

Změna výchozích dimenzí už zaúčtované doklady nemění. Projeví se u dokladů
zaúčtovaných později a u dokladu, jehož dimenze na detailu znovu uložíte.
Smazáním klienta, zakázky nebo hodnoty dimenze se výchozí nastavení odstraní.

### 114.11.6 Dimenze při vytěžení a nahrání dokladu

Přijatý doklad může dostat dimenze hned při vstupu, ne až při zaúčtování.

**Kontrola vytěžených dokladů.** Okno po vytěžení
([§ 25.5](25_AI_extrakce.md#255-krok-za-krokem-zkontrolovat-vytezene-doklady)) má sekci
**Dimenze dokladu**. Prázdné typy hlavičky předvyplní:

1. výchozí dimenze zakázky, pak dodavatele (viz výše),
2. co zbude, **návrh z historie**: hodnoty z hlavičky posledního nestornovaného
   přijatého dokladu téhož dodavatele. Bere se jen dimenze uložená na dokladu,
   uzavřená hodnota se nenavrhuje. Návrh nic nestojí, AI se při něm nevolá.

Ruční volba má vždy přednost. Předvyplněná hodnota je v okně označená jako
**Návrh** se zdrojem (výchozí dodavatele, výchozí zakázky, z posledního dokladu
dodavatele). **Uložit a další** ji uloží přímo do dokladu jako jeho dimenzi.

Okno se otevře i u dokladu bez hlášení vytěžení, pokud mu chybí dimenze, kterou
[pravidlo](#1141110-pravidla-dimenzi-podle-uctu) s vynucením **Povinné** vyžaduje na účtu
některé položky. Počítá se přitom se vším, co by doklad dostal při zaúčtování
(položka, produkt, hlavička, zakázka, dodavatel, výchozí hodnota pravidla).
Detail takového dokladu ukáže červené upozornění s tlačítkem **Zkontrolovat**.

**Příchozí doklady.** Při nahrání do **Nákup → Příchozí doklady** jde zvolit
dimenze dokladu, typicky středisko, pro které účtenka je. Podání je ukazuje jako
štítky. Při zpracování (vytěžení i ruční přepis) se volba propíše do hlavičky
vzniklé přijaté faktury. Před výchozími dimenzemi dodavatele má přednost, dimenzi,
kterou už faktura má, nepřepíše.

**Portál.** Klient v **Portál → Doklady pro účetní** vybírá jen **středisko**,
a to z aktivních hodnot firmy, které se ukazují na dokladech. Ostatní typy
a interní údaje číselníku (odpovědná osoba, poznámky, vazby) portál nevidí.
Náhrada originálu převezme středisko nahrazovaného podání.

### 114.11.7 Schvalování přijatých dokladů

Přijaté doklady (faktury i účtenky) může před přijetím schválit manažer střediska.
Schvalovatelem je **odpovědná osoba** hodnoty dimenze.

**Nastavení.** Ve **Firma → Dimenze** vyberte typ (typicky Středisko) a v jeho
hlavičce zapněte přepínač **Schvalování dokladů**. U každé hodnoty pak v řádku
vyberte **Schvalovatele**. Schvaluje se jen u hodnot, které schvalovatele mají;
doklad s hodnotou bez schvalovatele se přijme rovnou. V **Upravit typ** lze
volitelně zadat **limit** v Kč bez DPH (pole **Schvalovat od částky**), doklad
s částkou pod limitem se neschvaluje. Schvalovatelem může být uživatel přiřazený
k firmě nebo superadmin. Potřebuje oprávnění **Schvalovat přijaté doklady**, které
lze přidělit i roli jen pro čtení. Deaktivovaný uživatel schvalovat nemůže
a hodnota se pak chová jako bez schvalovatele.

**Které středisko schvaluje.** Středisko se bere z položek i z hlavičky dokladu
stejně jako při zaúčtování: dimenze položky, pak produkt, hlavička dokladu,
zakázka a dodavatel. Částka střediska je součet základů položek s jeho hodnotou
(u rozpadu podíl), u dokladu v cizí měně přepočtená kurzem dokladu. Doklad v cizí
měně bez kurzu nejde ocenit, proto se u něj limit neuplatní a schvaluje se vždy.
Má-li doklad víc středisek, schvaluje každé středisko jeho odpovědná osoba a doklad
je schválený, až schválí všichni. Daňový doklad k přijaté platbě a dobropis se
neschvalují.

**Odeslání ke schválení.** Přijetí konceptu (tlačítko **Označit jako přijaté**,
potvrzení v okně kontroly vytěžení, API i MCP) u dokladu, který schválení vyžaduje,
doklad nepřijme, ale odešle ho ke schválení. Doklad zůstává **konceptem**, takže
není v platebních příkazech, v nákladech, v evidenci DPH ani v účetnictví. Seznam
přijatých faktur jde filtrovat podle stavu schválení.

**Rozhodnutí.** Schvalovatel dostane e-mail s odkazem, na kterém vidí doklad
i jeho PDF a může ho **schválit**, nebo **zamítnout** s povinným důvodem. Odkaz
platí 14 dní a jde použít pro jedno rozhodnutí. Totéž najde v aplikaci na stránce
**Ke schválení**, kde vidí jen doklady, které schvaluje on. Nevyřízená schválení
připomíná denně e-mail (cron `cron-purchase-approval-reminders`), každá
připomínka nese nový odkaz a ten předchozí přestává platit.

**Po schválení všemi** se doklad přijme sám, stejně jako kdyby ho přijala účetní:
přidělí se interní číslo a proběhne i automatické zaúčtování, je-li zapnuté.
**Zamítnutý** doklad zůstává konceptem a účetní ho najde v akčních položkách.
Může ho upravit a poslat znovu (nové kolo schvalování), nebo stornovat.

**Změna po schválení.** Schválení platí pro středisko a částku. Změní-li se po
schválení středisko nebo částka, další pokus o přijetí pošle doklad znovu ke
schválení jen dotčenému středisku. Ostatní schválení zůstávají v platnosti.

Bez typu dimenze se zapnutým schvalováním se doklady přijímají jako dřív. Dávkový
import ISDOC a Pohoda XML, který doklady rovnou přijímá, schvalování neuplatňuje.
Kdo chce importované doklady schvalovat, importuje je jako koncepty.

### 114.11.8 Majetek

Karta dlouhodobého majetku (hmotného i nehmotného) nese vlastní dimenze. Zadávají
se v editoru karty v sekci **Dimenze** a na detailu karty v panelu **Dimenze**,
kde lze nastavit i rozpad mezi více hodnot (například stroj sdílený dvěma
středisky v poměru 60/40).

Dimenze karty dostanou všechny zápisy majetku:

- zařazení do užívání,
- účetní odpisy, včetně odpisu roku vyřazení,
- vyřazení (doodepsání zůstatkové ceny a vyřazení z evidence).

Technické zhodnocení se do zápisů promítá přes odpisy a vyřazení, takže nese
dimenze karty také. Karta bez vlastních dimenzí přebírá dimenze přijaté faktury,
ze které vznikla: dimenze nebo rozpad její položky, jinak výchozí dimenze produktu
položky, jinak hlavičku faktury. Změna dimenzí faktury se pak promítne i do zápisů
takové karty. Karta s vlastními dimenzemi se fakturou nemění.

Změna dimenzí karty se promítne do všech už zaúčtovaných zápisů majetku, i v
uzavřeném období, protože mění jen analytické členění. Přepíší se jen typy, které
karta určuje: dimenze jiného typu zadaná ručně na řádku zápisu (například projekt
u odpisu) zůstane. Totéž platí pro ostatní pohledávky a závazky a pro zápočty.
Drobný majetek dimenze nese z přijaté faktury nákupu; časové rozlišení drobného
majetku v uzávěrce je souhrnný zápis za období a dimenze nemá.

### 114.11.9 Produkt a kategorie

Skladová karta (produkt) a kategorie produktů mohou nést výchozí **účet výnosů**,
**účet nákladů** a **výchozí dimenze**. Položka dokladu s kartou se pak zaúčtuje
na účet produktu a dostane jeho dimenze, aniž by je kdokoli vyplňoval ručně.
Účty se používají jen v podvojném účetnictví.

Kde se nastavují:

- **Karta produktu**: tab **Účtování**. Prázdný účet zdědí účet kategorie, pole
  to ukazuje nápovědou („Prázdné = zdědí 601 z kategorie“).
- **Kategorie produktů** (E-shop → Kategorie): v úpravě kategorie odkaz
  **Účtování produktů kategorie**. Platí pro produkty, které mají kategorii jako
  primární. Nemá-li účet nebo dimenzi kategorie, použije se nadřízená kategorie.

Účet musí být v účtovém rozvrhu firmy, aktivní a výsledkový: účet výnosů ze
třídy 6, účet nákladů ze třídy 5. Jiný účet aplikace při uložení odmítne.

#### Výnosový účet položky

Vydaná faktura i šablona pravidelné fakturace mají u položek volitelný
**výnosový účet**. Pole se v editoru zapne odkazem **Účty položek** nad položkami
(samo se zapne, když už některá položka účet má). Prázdné pole znamená účet
produktu, jeho kategorie, jinak předkontaci dokladu. Výběr skladové karty
v položce předvyplní prázdný účet i prázdné dimenze položky z produktu.
Předvyplněné hodnoty jde přepsat. Když na řádku vyberete jinou kartu, nahradí se
jen hodnoty předvyplněné z předchozí karty, ruční volba zůstává.

Kopie faktury, vyúčtovací faktura ze zálohové faktury i pravidelná fakturace
účet položky přenášejí. Dobropis zapíše na své položky účet, na který šla
původní položka, i když ho určil produkt nebo kategorie, takže pozdější změna
karty vratku jinam nepřesměruje.

Sleva v procentech z hlavičky faktury se při zaúčtování rozdělí mezi položky téže
sazby DPH v poměru jejich základu a sníží výnos na jejich účtech (zboží na 604,
služba na předkontaci).

V sekci **Zaúčtování** u faktury ukazuje řádek **Podle čeho se účtovalo** i účet
položky, produktu nebo kategorie, s odkazem na kartu produktu.

#### Pořadí při zaúčtování

<!-- cols: 24 76 -->
| Co | Pořadí přednosti |
|---|---|
| Účet výnosů vydané faktury | prodej majetku > účet položky > produkt > kategorie > předkontace dokladu |
| Účet nákladů přijaté faktury | účet položky > druh výdaje > produkt > kategorie > předkontace dokladu; u pořízení dlouhodobého majetku se účet produktu ani kategorie nepoužije |
| Dimenze | položka > produkt > kategorie > hlavička > zakázka > klient > pravidlo dimenzí |

Dimenze produktu a kategorie mají přednost před hlavičkou jen na výsledkových
řádcích (náklad, výnos), stejně jako dimenze položky. Řádky pohledávky,
závazku a DPH nesou dimenze hlavičky.

Položky s různými účty se na faktuře zaúčtují na samostatné výnosové
(nákladové) řádky, které dohromady dají přesně základ dokladu. DPH ani
pohledávka se tím nemění. Bez nastavení produktu, kategorie a položky se
účtuje stejně jako dosud.

Zmizí-li účet produktu z rozvrhu nebo se deaktivuje, zaúčtování dokladu skončí
chybou s názvem účtu. Účet je potřeba na kartě opravit nebo znovu aktivovat.
Změna účtu nebo dimenzí produktu už zaúčtované doklady nemění.

### 114.11.10 Pravidla dimenzí podle účtu

Na záložce **Pravidla** v sekci Firma → Dimenze se nastavuje, které účty musí
nést hodnotu kterého typu dimenze, například „náklady a výnosy musí mít
středisko“. Pravidlo má:

- **Účty** - předpony účtů oddělené čárkou, vyloučení vykřičníkem. `5, 6, !59, !69`
  znamená třídy 5 a 6 kromě daně z příjmů. Maska `518` platí pro 518 i všechny
  jeho analytiky.
- **Typ dimenze**, kterou řádek musí nést.
- **Vynucení**:
  - **Povinné** - doklad ani ruční zápis bez hodnoty nejde zaúčtovat. Chyba
    jmenuje účet, stranu, částku a chybějící dimenzi.
  - **Varovat** - zápis se zaúčtuje a aplikace zobrazí upozornění.
  - **Jen doplnit** - pravidlo nic nevynucuje, jen doplňuje výchozí hodnotu.
- **Výchozí hodnota** - doplní se řádku na účtu z masky, který hodnotu typu
  nemá. Hodí se jako výchozí hodnota firmy, třeba projekt, ke kterému firma patří.
- **Doplnit vozidlo podle platební karty** (jen u typu Vozidlo) - u platby kartou
  z bankovního výpisu a u přijatého dokladu zaplaceného kartou se podle koncovky
  karty najde karta, její držitel a jeho jediné aktivní vozidlo. Hodnota typu
  Vozidlo navázaná na tento vůz se doplní na řádky z masky. Když se vozidlo
  určit nedá, použije se výchozí hodnota pravidla.
- **Platí od / do** - rozhoduje datum účetního případu. Pravidlo zavedené od
  určitého data nezablokuje přeúčtování starších dokladů.

Pořadí přednosti hodnoty na řádku: ruční volba na řádku, dimenze položky
a hlavičky dokladu, výchozí dimenze zakázky a klienta, teprve potom výchozí
hodnota pravidla. Když na jeden účet míří víc pravidel téhož typu, výchozí
hodnotu určí pravidlo s delší předponou a vynucení to nejpřísnější.

Pravidla platí pro zaúčtování vystavených a přijatých faktur, pokladních dokladů,
bankovních pohybů a ručních zápisů. Automatické zápisy (uzávěrka, otevření účtů,
odpisy, mzdy, přeúčtování DPH, kurzové rozdíly) pravidla neblokují. Při ruční
úpravě dimenzí řádků v deníku nejde povinnou dimenzi z řádku odebrat.

Firma bez pravidel účtuje stejně jako dřív.

#### Kontrola deníku

Pod seznamem pravidel se za zvolené období spouští **Zkontrolovat deník**.
Výsledek ukáže zaúčtované řádky, kterým podle pravidel chybí hodnota. Souhrn je
po účtech, zdrojích zápisu a vynucení, seznam řádků vede do deníku. Typicky jde
o převzatou historii, automatické zápisy nebo zápisy z doby před zavedením
pravidla. Uzávěrka, otevření účtů a stornované zápisy se nepočítají.

**Pokrytí účtů** ukáže, jaká část řádků na každém syntetickém účtu nese hodnotu
typu dimenze. Účet, který historie členila téměř vždy (aspoň 90 % z deseti
a více řádků), nabídne tlačítko **Vytvořit pravidlo**. Hodí se po převodu
z jiného systému.

#### Pravidla v daňové evidenci

Firma v daňové evidenci účty nemá. Pravidlo se uplatní na pohyby peněžního deníku
a maska se porovná s třídou: výdaj jako **5**, příjem jako **6**. Výchozí hodnota
pravidla doplní pohyb, který hodnotu nemá ani z dokladu, a kontrola pravidel ukáže
pohyby s daňovým příjmem nebo výdajem, kterým povinná dimenze chybí. Podrobnosti
jsou v [kapitole Daňová evidence](74_Danova_evidence.md#74919-dimenze-v-danove-evidenci).

### 114.11.11 Rozpad mezi více hodnot

Náklad, který patří víc střediskům nebo projektům, se dá rozdělit tlačítkem
**Rozpad**:

- na detailu dokladu v panelu Dimenze (rozpad celého dokladu, také u ostatní
  pohledávky nebo závazku a u karty majetku),
- v účetním deníku v úpravě dimenzí řádků (rozpad jednoho řádku).

V rozpadu se vybere typ dimenze a hodnoty s procentem, u řádku deníku také
s částkou. Součet musí dát 100 %, resp. částku řádku. Typ s rozpadem pak nemá
jedinou hodnotu. Když typu později vyberete jedinou hodnotu, rozpad se zruší.

Řádek zápisu se rozpadem nedělí, zůstává jeden se svou částkou. Rozpad se ukládá
jako podíly a všechny sestavy ho počítají poměrem po haléřích: každý díl se
zaokrouhlí na haléře a zbytek dostane největší podíl (při shodě hodnota založená
dřív). Výkazy po dimenzi i výkazy s filtrem na dimenzi tak dávají pro hodnotu
stejné číslo a součet po hodnotách sedí na výsledek firmy. Rozpad dokladu se při
zaúčtování přenese na řádky zápisu. Storno zápisu přenese stejný rozpad, takže
odečte přesně to, co původní zápis přičetl. Rozpad splní i povinnou dimenzi
pravidla.

### 114.11.12 Účtotvorná dimenze

Dimenze může kromě analytického členění určovat i analytický účet. Firma, která
vede náklady střediska na vlastních analytikách (518.100 FVE, 518.200 Kancelář),
tak nemusí volit účet na každém dokladu: stačí vybrat středisko.

Nastavení:

1. V editaci typu zaškrtněte **Účtotvorná dimenze**. Účtotvorná může být jen
   jedna dimenze firmy (ani firemní typ vedle skupinového). Pole **Účty, na které
   se mapa uplatní** omezuje výsledkové účty, výchozí je `5, 6`.
2. V editaci hodnoty vyplňte tabulku **Analytické účty**: syntetika (např. 518)
   a cílová analytika (518.100), volitelně platnost od-do. Analytiku lze založit
   přímo z tabulky. Ukládá se společně s hodnotou.

Cílová analytika musí ležet pod zvolenou syntetikou, být aktivní a mít stejnou
daňovou uznatelnost jako syntetika. Dimenze tak nikdy nepřesune náklad mezi
daňový a nedaňový. U skupiny firem jsou hodnoty společné, ale mapa platí pro
firmu, ve které ji nastavíte (každá firma má svůj účtový rozvrh).

Při zaúčtování:

- Řádek na výsledkové syntetice z mapy, jehož hodnota účtotvorné dimenze má
  mapování, se zaúčtuje na analytiku. Rozhoduje výsledná dimenze řádku, ať ji
  dal doklad, položka, produkt, zakázka, klient, pravidlo, mzdy nebo ruční zápis.
- Řádek s rozpadem účtotvorné dimenze (60 % FVE, 40 % Kancelář) se rozdělí na
  řádky po analytikách, na haléř přesně. Každý díl nese jen svou hodnotu, ostatní
  dimenze zůstávají.
- Účet, který zvolil doklad nebo uživatel jako analytiku, se nemění. Mapa
  přepisuje jen syntetiku. Hodnota bez mapování zůstane na syntetice.
- Mapa platí podle data účetního případu. Rozvahové účty se nemapují nikdy, DPH
  ani kontrolní hlášení se nemění.
- Náhled kontace před zaúčtováním ukáže, kam se syntetika přesune.
- Časové rozlišení (381, 384) odkládá a rozpouští náklad i výnos z týchž analytik.

Změna dimenze u zaúčtovaného dokladu, která by přesunula řádek na jinou
analytiku, se do deníku tiše nepromítne: řádek si ponechá dosavadní dimenzi
a aplikace vyzve k přeúčtování. U dokladu v uzavřeném nebo zamčeném období se
taková změna odmítne. Úprava dimenze přímo na řádku deníku, která by měnila
účet, se také odmítne.

Kontrola uzávěrky (i měsíční kontrola) upozorní na výsledkové zápisy, které
zůstaly na syntetice s mapou, typicky doklad bez střediska.

### 114.11.13 Mzdy

Mzdy mají vlastní číselník středisek, zakázek a činností (Mzdy → Nastavení →
Dimenze). Mzdovou dimenzi lze navázat na hodnotu firemní dimenze, takže jedno
středisko platí pro faktury i mzdy. Vazba smí mířit jen na hodnotu firmy nebo
její skupiny firem.

Při zaúčtování schváleného mzdového běhu:

- Náklady pracovního vztahu (hrubá mzda, pojistné zaměstnavatele na 524,
  příspěvek na spoření u rizikové práce) nesou firemní dimenzi navázané mzdové
  dimenze. Řádky se seskupí podle účtu, strany a dimenzí, takže v deníku je
  například jeden řádek 521 za každé středisko.
- Vztah rozdělený podílem (např. 70 / 30) rozdělí každý svůj náklad podle podílů.
  Haléř, který po dělení zbude, dostane podíl s největším zbytkem, součet tedy
  vždy sedí na haléř.
- Závazky (331, 336, 342 a další) se na střediska nedělí. Dluží se jako celek.
- Do deníku se neúčtuje po zaměstnancích. Mzdový předpis zůstává jeden za firmu.
- Textový kód střediska se na řádky zapisuje dál, sestavy po středisku proto
  fungují i bez vazby.

Opravná revize, která změní jen středisko nebo podíly, odúčtuje původní části
a zaúčtuje nové, celkový náklad se nemění. Bez vazby a bez rozpadu se mzdy
účtují přesně jako dřív.

#### Náklady na zaměstnance po dimenzi

Na přehledu mezd je sestava **Náklady na zaměstnance po dimenzi**. Za zvolený
rok ukáže mzdové náklady každého zaměstnance rozdělené po střediscích nebo
firemních dimenzích (mzdy, pojistné zaměstnavatele, ostatní) a souhrn po
hodnotách. Sestava čte účetní můstek mezd, ne deník, a započítává jen zaúčtované
běhy (u opravených běhů poslední zaúčtovanou revizi). Součet sedí na nákladové
řádky mzdového předpisu v deníku. Sestava se zobrazí jen firmě, která má zapnuté
firemní dimenze nebo eviduje aspoň jednu mzdovou dimenzi.

Pojistné zaměstnavatele se počítá ze součtu vyměřovacích základů celé firmy, a
pokud vztahy nenesou dimenzi, zaúčtuje se jednou částkou. Sestava ho přesto
rozdělí na zaměstnance poměrem jejich vyměřovacích základů, stejně jako podíl
osoby v rozkladu pojištění mzdového běhu, a podíl vztahu promítne do jeho
dimenzí. Součet podílů sedí na firemní částku na haléř. V řádku **Nerozděleno na
zaměstnance** zůstane jen pojistné revize, která si základy jednotlivých vztahů
neuložila; vysvětlení ukáže tooltip u řádku.

### 114.11.14 Sestavy

#### Filtr na dimenzi ve výkazech

**Výsledovka**, **Rozvaha**, **Obratová předvaha** a **Hlavní kniha** mají filtr
na dimenzi: vybere se typ a hodnota, volitelně včetně podřízených hodnot. Sestava
pak obsahuje jen řádky deníku s touto hodnotou, ne protistranu zápisu. Řádek
s rozpadem mezi víc hodnot se započte jen dílem vybrané hodnoty (náklad 60 %
středisko A a 40 % B se do výsledovky střediska A započte šedesáti procenty).
Kontrolní vazby předvahy na celý deník proto s filtrem nemusí sedět. Export do
PDF i XLSX nese v hlavičce řádek **Dimenze** s vybranou hodnotou.

Rozvaha po dimenzi dává smysl u projektu nebo zakázky, jejíž doklady nesou
hodnotu na všech řádcích (dimenze z hlavičky dokladu). Vyrovnaná je jen tehdy,
když hodnotu nesou obě strany zápisů. Úhrada faktury projektu v bance bez
projektu nechá pohledávku projektu v rozvaze otevřenou.

#### Výkazy po dimenzi

V daňové evidenci se statistika i výsledovka po dimenzi počítají z peněžního
deníku: výnosy jsou daňové příjmy a náklady daňové výdaje, hodnotu nese doklad
pohybu. Postup je v [kapitole Daňová evidence](74_Danova_evidence.md#74919-dimenze-v-danove-evidenci).

V menu **Grafy → Dimenze** je roční statistika za zvolený typ dimenze. Ukazuje
zaúčtované výnosy, náklady, zisk nebo ztrátu, ziskovou marži, podíl částek
přiřazených k hodnotě a částky bez hodnoty. Graf sleduje měsíční vývoj a
srovnává celý typ s minulým rokem; další graf porovnává kumulovaný zisk.
Tabulka porovnává hodnoty dimenze mezi sebou. Kliknutím na hodnotu vyfiltrujete
karty, měsíční vývoj a spodní tabulku. Nulové hodnoty se v tabulkách nevypisují.
Každá tabulka končí řádkem **Celkem** a má vlastní export do XLSX a PDF.
Export porovnání hodnot zahrnuje všechny nenulové hodnoty, export po firmách a
měsících respektuje právě zvolenou hodnotu dimenze.
U globální dimenze se jako výchozí zobrazí součet všech firem, ke kterým má
uživatel účetní přístup; lze vybrat i konkrétní firmu skupiny. Součet za skupinu
obsahuje také tabulku po firmách, která se řídí zvolenou hodnotou dimenze.
Čísla vycházejí ze zaúčtovaného deníku a používají stejná pravidla jako
výsledovka po dimenzi, včetně poměrného rozdělení řádků. Pokud jsou evidované
nedaňové náklady, sestava je oddělí od daňově uznatelných podle pravidla
podkladů DPPO/DPFO: nedaňový účet nebo nedaňová přijatá faktura. Daň z příjmů
na účtech 59x se vykazuje zvlášť, aby se nemíchala s oběma skupinami nákladů.

Menu **Účetnictví → Výkazy po dimenzi** má dvě záložky. Tlačítko **Export XLSX**
stáhne aktivní záložku. Výsledovku lze stáhnout také jako PDF.
Změna dimenze, větve, období nebo dalších filtrů sestavu rovnou znovu načte.
Nulové hodnoty dimenzí, účty a sloupce bez pohybu se v zobrazení skrývají.
Zisk se zobrazuje zeleně a ztráta červeně.

**Výsledovka** ukáže pro hodnoty jednoho typu výnosy, náklady a výsledek.
Nadřízená hodnota sčítá celou větev, řádek **Bez hodnoty** doplní součet do
výsledku firmy za období. Nad tabulkou jsou souhrnné částky a porovnání
největších zisků a ztrát podle hodnoty. Sestavu lze omezit:

- **Větev**: jen vybraná hodnota a její podřízené (účelová výsledovka projektu
  a jeho etap),
- **Odpovědná osoba**: jen hodnoty, u kterých je osoba uvedená jako odpovědná,
  včetně jejich větví.

S omezením se řádek Bez hodnoty nevykazuje a součet není výsledek celé firmy.
Volba **Rozpad po účtech** přepne tabulku na syntetické účty: řádky jsou účty
výnosů a nákladů, sloupce hodnoty nejvyšší úrovně (nebo vybraná větev či hodnoty
odpovědné osoby) a Bez hodnoty, poslední řádek je výsledek sloupce. XLSX obsahuje
strom hodnot i list s rozpadem po účtech.

Pod sestavou jsou odkazy na **Rozvahu**, **Obratovou předvahu** a **Hlavní knihu**.
Stejné odkazy jsou u každé hodnoty v tabulce a předají její dimenzi i aktuální
období. Z těchto sestav se lze prokliknout zpět do výsledovky po dimenzi i mezi
ostatními sestavami. Volby se uchovávají v URL. Navazující sestavy se vztahují
k aktuální firmě, nikoli k součtu skupiny firem.
Pokud zvolené datumy zasahují do více účetních období, hlavní kniha otevře
režim všech období. Rozvaha a obratová předvaha pracují s jedním obdobím:
vyberou období koncového data, předvaha rozsah omezí a obě stránky na úpravu
upozorní.

U globálního typu lze zapnout **Sečíst všechny firmy skupiny** a potom volitelný
**Rozpad po firmách**. Tabulka ukáže výnosy, náklady a výsledek každé přístupné
firmy pro stejný výběr hodnot a období, nulové firmy vynechá a skončí součtem.
Při zapnutí rozpadu se tabulka přidá také do PDF a jako samostatný list do XLSX.

**Peněžní tok** počítá tok nepřímou metodou za celou firmu nebo za vybranou
hodnotu:

<!-- cols: 34 66 -->
| Řádek | Obsah |
|---|---|
| Výsledek hospodaření | výnosy mínus náklady |
| Úpravy o nepeněžní operace | změna oprávek a opravných položek (07x až 09x, 19x, 29x, 39x) a rezerv (45x) |
| Změna pracovního kapitálu | změna pohledávek, závazků, zásob a ostatních účtů tříd 1 až 3 |
| B. Investiční činnost | dlouhodobý majetek (0xx) a krátkodobý finanční majetek (25x) |
| C. Finanční činnost | třída 4 kromě rezerv, úvěry 231 a 232, vlastní podíly 252 |

Každá změna rozvahového účtu se počítá z řádků, které hodnotu nesou (u rozpadu
jejich díl). Pod výkazem je pohyb na peněžních účtech (211, 213, 221, 261)
ve stejném výběru řádků. Za celou firmu musí vyjít stejně jako čistý tok.
U hodnoty dimenze **Rozdíl** ukazuje peníze, které hodnotu nenesou, typicky
úhradu faktury projektu v bance bez projektu. Oficiální přehled o peněžních
tocích do závěrky je v sekci **Peněžní toky a kapitál**.

#### Součet za skupinu firem

U globálního typu (projekt sdílený skupinou firem, viz [Firemní a globální
dimenze](#114111-firemni-a-globalni-dimenze)) lze v obou záložkách zaškrtnout
**Sečíst všechny firmy skupiny**. Sečtou se firmy skupiny, ke kterým máte účetní
přístup. Firmy, ke kterým přístup nemáte, se nesečtou a sestava uvede jen jejich počet.

### 114.11.15 Oprávnění a licence

Dimenze jsou součástí licencovaného účetnictví. Lze je zapnout a používat během
zkušební doby nebo s platnou licencí, která odemyká účetní modul. Po jejím
skončení zůstane nastavení firmy uložené, ale dimenze se nezobrazí a nelze s nimi
pracovat, dokud nebude licence znovu platná.

Číselník dimenzí a výběry na dokladech vidí uživatel s právem číst účetnictví,
měnit je smí uživatel s právem zápisu do účetnictví. Zapnutí dimenzí patří
k nastavení firmy, skupinu firem spravuje správce firmy. Výchozí dimenze klienta
a zakázky smí měnit ten, kdo smí upravovat klienta, resp. zakázku.
Schvalování přijatých dokladů řídí oprávnění **Schvalovat přijaté doklady**;
odeslání ke schválení a jeho zrušení patří ke změně stavu přijaté faktury.

### 114.11.16 Převod z Money S3

Převod z Money S3 dimenze zapne a středisko i zakázku z deníku Money převede na
dimenze. Podrobnosti v kapitole [Přechod z Money S3](103_Prechod_z_Money_S3.md).

## 114.12 Související kapitoly

- [Zisk](11_Zisk.md) - souhrnný přehled firmy
- [Přijaté faktury](23_Prijate_faktury.md) - schvalování a dimenze na přijatých dokladech
- [AI extrakce](25_AI_extrakce.md) - dimenze při vytěžení dokladů
- [Majetek](28_Majetek.md) - dimenze karet majetku
- [Přechod z Money S3](103_Prechod_z_Money_S3.md) - převod středisek a zakázek
