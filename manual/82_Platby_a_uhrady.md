# 82. Mzdové příkazy a úhrady

> Návod, jak z uzavřené mzdy připravit platby čistých mezd, daní, pojistného
> a srážek, poslat je do banky a doložit jejich úhradu. Pro mzdové účetní
> a každého, kdo ve firmě platí výplaty a odvody.

## 82.1 Kdy to potřebujete

Kapitolu otevřete, když:

- máte schválený a zaúčtovaný mzdový běh a chcete vyplatit mzdy,
- se blíží 20. den měsíce a musíte zaplatit pojistné a zálohovou daň,
- chcete platby poslat do banky jedním příkazem,
- dorazil bankovní výpis a chcete ověřit, že je všechno zaplacené,
- instituce vrátila přeplatek a musíte vratku doložit,
- potřebujete změnit datum příkazu nebo příkaz zahodit.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po zaúčtování běhu | Připravit závazky | karta běhu, **Připravit platby** |
| k datu výplaty | Zaplatit čisté mzdy | `Mzdy → Mzdové příkazy a úhrady`, záložka **Co zaplatit** |
| do 20. dne následujícího měsíce | Zaplatit sociální a zdravotní pojištění a zálohovou daň | záložka **Co zaplatit** |
| do konce následujícího měsíce | Zaplatit srážkovou daň | záložka **Co zaplatit** |
| po importu výpisu | Zkontrolovat spárování úhrad | záložka **Spárování úhrad** |

## 82.2 Než začnete

1. **Schválený a zaúčtovaný běh** v `Mzdy → Mzdové běhy`
   (viz [Mzdové běhy](80_Mzdove_behy.md)).
2. **Oprávnění k mzdovým platbám.** Předání příkazu přímo do banky
   potřebuje i oprávnění k bankovním účtům.
3. **Účty institucí.** V `Mzdy → Nastavení mezd`, záložce **Účty
   institucí**, musí mít každá pojišťovna, ČSSZ a finanční úřad účet
   účinný ke splatnosti, úplný ověřovací podklad a platební symboly.
   Variabilní symbol sociálního pojistného je u mzdové účtárny (záložka
   **Zaměstnavatel a účtárny**).
4. **Ověřené výplatní účty zaměstnanců** na kartě osoby. Bankovní údaje
   musí být ověřené z důvěryhodného zdroje.
5. **Napojení banky** pro přímé předání příkazu, jinak stačí stáhnout
   soubor a nahrát ho do bankovnictví ručně.

## 82.3 Krok za krokem: zaplatit mzdy a odvody

1. Na kartě zaúčtovaného běhu klikněte na **Připravit platby**. Otevře se
   `Mzdy → Mzdové příkazy a úhrady` ve správném období se závazky běhu.
   (Na stránce lze totéž spustit tlačítkem **Připravit závazky**.)
2. Zkontrolujte u každého závazku příjemce, účet, částku, splatnost
   a platební symboly. Součet porovnejte se schváleným během a s účetními
   závazky.
3. Na záložce **Co zaplatit** vyberte platby se stejným datem, měnou
   a způsobem úhrady.
4. Zvolte **Datum úhrady**: **Podle splatnosti**, **Dnes** nebo **Vlastní
   datum**.
5. Zkontrolujte **Účet plátce** a formát a klikněte na **Vytvořit mzdový
   příkaz**. Pro CZK vznikne ABO (KPC), pro EUR SEPA, u hotovosti evidence
   hotovostní výplaty.
6. Příkaz předejte bance tlačítkem **Odeslat do banky**, nebo stáhněte
   soubor a nahrajte ho do bankovnictví.
7. Platby autorizujte v bankovnictví podle pravidel firmy.

**Jak poznáte, že je hotovo:** Příkaz je na záložce **Mzdové příkazy**.
Exportovaná dávka ale teprve čeká na autorizaci v bance; zaplacená je až po
spárování s výpisem ([§ 82.4](#824-krok-za-krokem-dolozit-uhrady)).

> [!WARNING]
> Odeslaná dávka nejde poslat znovu. Při nejasném výsledku přenosu ověřte
> stav v bance a nevytvářejte duplicitní platbu.

## 82.4 Krok za krokem: doložit úhrady

1. Naimportujte bankovní výpis (viz [Banka](29_Banka.md)). Odvody se
   rozpoznají samy podle variabilního symbolu a částky; ručně rozpoznání
   spustíte tlačítkem **Načíst platby z banky**.
2. Na záložce **Spárování úhrad** projděte závazky, které zůstaly otevřené.
3. Vyberte závazek a kompatibilní bankovní pohyb nebo zaúčtovaný pokladní
   doklad a spárujte. Zapsat jde i částečnou úhradu.
4. Nemáte-li zatím výpis a víte, že je zaplaceno, klikněte u závazku na
   **Zaplatil jsem**. Termín zhasne, ale saldo se nezmění.

**Jak poznáte, že je hotovo:** Závazky jsou uhrazené a mzdový běh přejde sám
do stavu **Uhrazeno**. Na kartě běhu je „Úhrady doložené výpisem: všech N
závazků.“

## 82.5 Krok za krokem: přijatá vratka od instituce

Použijte, když opravná revize snížila odvod a instituce přeplatek vrátila.

1. Na záložce **Spárování úhrad** klikněte na **Potvrdit skutečně přijatou
   vratku**.
2. Vyberte nevyrovnaný příchozí závazek, doklad ve stejné měně (bankovní
   pohyb nebo příjmový pokladní doklad) a přijatou částku.
3. Výslovně potvrďte, že peníze byly firmě skutečně připsány nebo přijaty
   do pokladny, a uložte.

**Jak poznáte, že je hotovo:** Příchozí závazek je vyrovnaný.

## 82.6 Krok za krokem: změna data nebo zahození příkazu

1. Na záložce **Mzdové příkazy** najděte příkaz.
2. Klikněte na **Změnit datum** a zvolte nové datum, nebo na **Zahodit**.
3. Pokud jste soubor už stáhli nebo ho aplikace předala bance, potvrďte, že
   příkaz v bankovnictví není autorizovaný, nebo že jste ho tam zrušili
   (například u CREDITAS v nabídce Transakce, Zadané, Hromadné).

**Jak poznáte, že je hotovo:** Původní příkaz má označení **Zahozeno**
s datem. Při změně data vznikl nový příkaz s novým souborem pro banku; po
zahození jsou závazky zpět na záložce **Co zaplatit**.

## 82.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Schválená revize se k přípravě plateb nenabídne | Nemá zmrazené výplatní účty | Vytvořte opravnou revizi z aktuálních ověřených podkladů. |
| Příprava zastavena kvůli chybějícímu variabilnímu symbolu | Prázdný symbol aplikace nenahradí nulou | U sociálního pojistného doplňte VS mzdové účtárny, u ostatních institucí platební účet v `Mzdy → Nastavení mezd`, záložce **Účty institucí**. |
| Příprava zastavena u vztahu bez mzdové účtárny | Bez účtárny nejde odvod sociálního pojištění vykázat pod symbolem | Přiřaďte vztahu mzdovou účtárnu. |
| Část běhů měsíce se nepřipravila | Selhala jedna účtárna; ostatní se připravily | Aplikace vypíše počet nezpracovaných běhů a důvod; opravte ho a akci zopakujte. |
| „Datum úhrady nemůže být v minulosti.“ | Banka by příkaz se zpětným datem odmítla | Zvolte dnešek nebo pozdější den. |
| Příkaz po splatnosti nejde vytvořit | Datum po splatnosti vyžaduje potvrzení | Zaškrtněte potvrzení pozdní platby (u pojistného hrozí penále, u daně a mzdy úrok z prodlení). |
| Předání do banky je blokované | Minulé datum splatnosti, závazek s evidovanou úhradou nebo nevyřešeným avízem | Upravte datum, nebo vyřešte úhradu či avízo. |
| Příkaz nejde zahodit ani přeplánovat | Je k němu doložená úhrada z výpisu | Nic nedělejte, platba proběhla. |
| Záporný rozdíl odvodu nejde dát do dávky | Je to příchozí opravný závazek | Doložte vratku ([§ 82.5](#825-krok-za-krokem-prijata-vratka-od-instituce)). |
| Bankovní pohyb mzdy se nenabízí k faktuře | Pohyb už spotřebovala mzda | Je to v pořádku. Ruční označení faktury jako uhrazené tuto kontrolu nemá, u pohybu patřícího ke mzdám ho nepoužívejte. |

Časté chyby: považovat export za odeslanou platbu, starý účet zaměstnance
nebo instituce, duplicitní export nebo ruční platba, spárování podobné částky
z jiného období.

## 82.8 Podrobnosti a pravidla

Agenda připravuje závazky ze schválené mzdy (čisté mzdy, daň, sociální
a zdravotní pojištění, srážky a další příjemce) a sleduje jejich úhradu.
Jedna účetní může připravit i dokončit celý tok. Při změně účtu aplikace
vyžaduje ověřený podklad. Bankovní export chraňte jako citlivý soubor a po
přenosu ho nenechávejte na sdíleném místě.

### 82.8.1 Stavy plateb

Připravená platba není odeslaná. Exportovaná čeká na autorizaci v bance.
Uhrazená má odpovídající bankovní pohyb. Částečně uhrazená nebo zamítnutá
vyžaduje další krok.

### 82.8.2 Jak vznikají závazky

Závazky vznikají z aktuálních schválených revizí zvoleného období. Čistá
mzda se vždy odvozuje z částky po exekučních srážkách. Rozdělení mezi ověřené
bankovní účty a hotovost se znovu vypočte nad pravidly a účty zmrazenými při
uzamčení vstupů; pozdější změna živé karty schválenou revizi nepřesměruje.
Bankovní cíl musí mít ve snímku úplné ověření, období platnosti a verzi účtu.

Zdravotní pojistné se připraví samostatně pro každou pojišťovnu z neměnného
výsledku schválené revize. Pojišťovna musí mít právě jeden účet účinný ke
splatnosti. Seznam ukáže název a kód pojišťovny, maskovaný účet a stav
ověření, celé číslo účtu ne.

Sociální pojistné se dělí podle **mzdové účtárny pracovního vztahu**,
protože zaměstnavatelský variabilní symbol je na účtárně. Běh zúžený na jednu
účtárnu dá jeden závazek, celofiremní běh tolik závazků, kolik různých
účtáren mají vztahy v běhu. Součet vždy odpovídá výsledku běhu, takže sedí
kontrolní součty i rekonciliace účetnictví s platbami. Každý závazek nese VS
své účtárny a účet ČSSZ účinný ke splatnosti; v dávce jsou to samostatné
platby, i když jdou na týž účet. Přehled o výši pojistného (PVPOJ) se naopak
podává za jednu registraci u OSSZ, takže vyžaduje běh zúžený na jednu
účtárnu; u celofiremního běhu přes víc účtáren aplikace přípravu podání
odmítne.

Zálohová a srážková daň se neslučují: každá má vlastní účet finančního úřadu
a platební symboly. Od mezd za rok 2027 srážková daň ze mzdy nevzniká; záloha
ze samostatného základu podle § 6 odst. 4 se přičte k závazku zálohové daně.

Opakované **Připravit závazky** je bezpečné a nevytvoří duplicity. Opravná
revize nezapisuje znovu celou mzdu, jen rozdíl proti předchozím závazkům.
Seznam ukazuje příjemce, druh závazku, způsob úhrady, splatnost, částku
a stav.

### 82.8.3 Splatnosti a datum příkazu

<!-- cols: 34 66 -->
| Závazek | Splatnost |
|---|---|
| Čistá mzda | datum výplaty ze mzdového běhu, neposouvá se |
| Sociální a zdravotní pojistné | od 1. do 20. dne následujícího měsíce |
| Zálohová daň | 20. den následujícího měsíce |
| Srážková daň | poslední den následujícího měsíce |

Připadne-li poslední den lhůty na sobotu, neděli nebo svátek, aplikace
u všech zákonných odvodů zapíše jako splatnost nejbližší následující pracovní
den. Například odvody za 05/2026 nevyjdou na sobotu 20. 6. 2026, ale na
pondělí 22. 6. 2026. Posunuté datum uvidíte v seznamu závazků a použije se
pro platební dávku. Splatnost se vždy odvodí z mzdového období, ne ze dne,
kdy byl historický běh vypočten nebo opraven.

Zákonná lhůta je splněná až **připsáním** částky na účet instituce (§ 9
odst. 2 zákona č. 589/1992 Sb.), ne odesláním příkazu. Dávka složená
výhradně ze zákonných odvodů proto dostane datum příkazu o **jeden pracovní
den dříve**, než je zákonný termín. Poskytovatel platebních služeb musí
částku připsat nejpozději do konce následujícího pracovního dne (§ 109
odst. 1 zákona č. 370/2017 Sb., o platebním styku). U závazku je vidět
zákonný termín i předsunuté datum příkazu s označením, že k předsunutí
došlo. Delší rezervu aplikace nedělá, aby zbytečně nevázala peníze.
Předsouvá se jen dávka složená výhradně z odvodů.

**Datum úhrady** při vytváření příkazu:

- **Podle splatnosti** je výchozí volba: čistá mzda k datu výplaty, dávka
  jen z odvodů o jeden pracovní den dřív než zákonný termín.
- **Dnes** pošle příkaz s dnešním datem, například když chcete zaplatit hned
  po uzávěrce.
- **Vlastní datum** dovolí jakýkoli den od dneška.

Zaplatit dřív můžete vždy. Datum po splatnosti aplikace zvýrazní a příkaz
vytvoří jen po zaškrtnutí potvrzení. Zvolená volba zůstane nastavená i pro
další příkazy (zvolíte-li **Dnes**, vytvoříte postupně příkaz na mzdy
i odvody se stejným datem). Potvrzení pozdní platby se zadává u každého
příkazu znovu.

Zvolené datum se uloží do příkazu a použije se v souboru pro banku (KPC,
SEPA) i v dokladu PDF. Zákonný termín zůstává u závazků, takže seznam
příkazů ukazuje, zda je příkaz před splatností, nebo po ní. Rozpoznání úhrad
z výpisu hledá platbu k datu příkazu, takže dřívější platba se přiřadí ke
svému měsíci, ne k předchozímu se stejnou částkou.

### 82.8.4 Variabilní symbol a formát dávky

Variabilní symbol u instituce je povinný. Bez něj by odvod nešlo spárovat
s předpisem, proto ho aplikace nenahradí nulou a zastaví přípravu i export
s názvem konkrétní instituce. Kontrola běží při sestavení závazku a znovu
před exportem, protože dávky připravené dříve mají symbol zmrazený
v platební instrukci. Nulu aplikace zapíše jen tehdy, když ji účetní
výslovně povolí. Závazek čisté mzdy zaměstnance variabilní symbol mít nemusí.

Aplikace podle výplatních cílů nabídne účet plátce a formát ABO nebo SEPA,
znovu ověří nezměněné účty příjemců a vytvoří dávku. U zdravotní
pojišťovny, ČSSZ i finančního úřadu použije přesné zmrazené VS, SS a KS.
SEPA nemá samostatná pole pro české symboly, proto jdou do zprávy pro
příjemce v ustáleném tvaru `/VS/…/SS/…/KS/…` na jejím začátku, aby přežily
zkrácení. Jak je konkrétní banka převede zpět, si ověřte u ní.

Export se ukládá šifrovaně přesně v bajtech, které se stáhnou do banky.
Opakování se stejným klíčem vrátí tentýž export a nevytvoří další závazek.
Stažení vyžaduje právo zápisu a používá krátkodobé jednorázové oprávnění.

### 82.8.5 Přímé předání do banky

Uložený příkaz v CZK (ABO) lze předat přímo přes ověřené napojení účtu
plátce. Přenos používá stejné bankovní konektory a evidenci odeslání jako
příkazy přijatých faktur; stažení KPC a PDF zůstává dostupné. Před odesláním
potvrďte dávku, částku, počet plateb a banku. Předání neoznačuje mzdy jako
uhrazené; příkazy zkontrolujte a autorizujte v bankovnictví.

### 82.8.6 Rozpoznání zaplacených odvodů

Odvod na zdravotní pojišťovnu, ČSSZ i zálohová daň má vlastní variabilní
symbol, takže odchozí platbu aplikace pozná sama. Rozpoznání běží po importu
bankovního výpisu i po skenu e-mailových avíz. Přiřadí se jen pohyb, u kterého
sedí variabilní symbol i částka a který je jednoznačný; dvě stejně vzdálené
platby aplikace nechá účetní.

- **Bankovní výpis** je doklad. Vznikne z něj skutečná úhrada: zápis
  v platební knize, snížení salda i účetní protizápis.
- **E-mailové avízo** dokladem není, týž pohyb dorazí ještě výpisem. Vznikne
  z něj jen poznámka **Zaplaceno dle avíza**: termín přestane upomínat, ale
  závazek zůstane v saldu otevřený, dokud nedorazí výpis. Ten poznámku
  automaticky vystřídá skutečnou úhradou.

**Zaplatil jsem** funguje stejně jako avízo: termín zhasne, saldo se nemění
a označení jde kdykoli zrušit. Účetně úhradu doloží až bankovní výpis.

### 82.8.7 Spárování a vratky

Historie párování je neměnná: vratka nebo storno nevynuluje původní záznam,
ale přidá samostatnou reverzní událost s vlastním důkazem. Jeden bankovní nebo
pokladní důkaz nesmí současně převzít fakturace ani jiné párování. Pohyb,
který už spotřebovala mzda, aplikace v bance nenabídne k automatickému
spárování s fakturou ani nedovolí přijmout návrh na jeho spárování.

Filtr období patří mzdové revizi, ne datu vytvoření dávky. V nabídce důkazů
proto zůstane i předčasná nebo opožděná platba k otevřenému závazku.

Skutečné datum úhrady vzniká výhradně z data zvoleného důkazu, nikdy
z plánovaného data výplaty ani z existence exportního souboru. Dokud nejsou
všechny částky průkazně spárovány a vratky vyřešeny, aplikace závazek ani
daňové potvrzení neoznačí za uhrazené.

Záporný rozdíl institucionálního odvodu se zobrazí jako příchozí opravný
závazek a do odchozí dávky ho vložit nejde. Vratku je nutné doložit skutečně
přijatým bankovním pohybem nebo zaúčtovaným příjmovým pokladním dokladem.
Aplikace dovolí i částečné přijetí, ale vždy vyžaduje výslovné potvrzení
účetní. Změna závazku, dokladu nebo částky potvrzení zruší. Příchozí vratka
nevytváří odchozí dávku; případná reverze přidá samostatnou neměnnou událost
s vlastním bankovním důkazem nebo naváže na stornovaný původní pokladní
doklad.

### 82.8.8 Zahozený příkaz

**Změnit datum** příkaz zahodí a ze stejných závazků vytvoří nový s novým
datem, včetně nového souboru pro banku. **Zahodit** vrátí závazky na záložku
**Co zaplatit**. Bez zrušení příkazu v bance hrozí dvojí platba. Příkaz
s doloženou úhradou (spárovaná platba z výpisu) zahodit ani přeplánovat
nejde.

Zahozený příkaz zůstává v seznamu s označením **Zahozeno** a datem. Jeho
soubory zůstávají v evidenci, ale nejde je stáhnout, předat bance ani k nim
spárovat úhradu. Zahození i změna data se zapisují do auditní stopy.

## 82.9 Související kapitoly

- [Mzdové běhy](80_Mzdove_behy.md): odkud částky pocházejí.
- [Shoda účtování mezd](81_Shoda_uctovani_mezd.md): kontrola účetních
  závazků.
- [Dohody o srážkách](87_Dohody_o_srazkach.md) a
  [Srážky a exekuce](88_Srazky_a_exekuce.md): příjemci srážek.
- [Nastavení mezd](90_Nastaveni_mezd.md): účty institucí a účtárny.
