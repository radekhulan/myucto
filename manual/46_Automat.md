# 46. Automat účtování

**Automat** je pracovní přehled, který účetní ukazuje na jednom místě vše, co
systém zaúčtoval sám, co připravil ke schválení a kde potřebuje lidské
rozhodnutí. Nejde o „černou skříňku“, která bez kontroly účtuje všechno. Každá
položka ukazuje zdroj rozhodnutí, navrženou kontaci, míru jistoty a důvod, proč
skončila právě v dané skupině.

Stránku otevřete přes **Účetnictví → Automat**. Je dostupná firmám s podvojným
účetnictvím a respektuje oprávnění uživatele pro každou firmu zvlášť.

> [!IMPORTANT]
> Automat nemění pravidla účetních období. Do uzavřeného nebo zamčeného období
> nic nezaúčtuje ani nestornuje. Nejasnou platbu raději odloží ke kontrole. AI
> návrh nikdy nezaúčtuje automaticky a nelze ho schválit hromadně.

## 46.1 Proč je práce s Automatem bezpečná

Při každém rozhodnutí se uplatní několik pojistek:

1. **Oprávnění k firmě** — uživatel vidí jen firmy, ke kterým je přiřazený.
   U společné fronty více firem se každá akce provede v kontextu firmy daného
   řádku; není nutné ručně přepínat firmu.
2. **Otevřené účetní období** — datum dokladu nebo bankovního pohybu musí patřit
   do otevřeného a nezamčeného období.
3. **Jednoznačná kontace** — automatické zaúčtování se použije jen tam, kde
   pravidlo nebo vestavěné rozpoznání dává jednoznačný výsledek.
4. **Omezení částky a denního objemu** — nastavený strop pravidla nebo denní
   limit převede položku do fronty ke schválení místo automatického zápisu.
5. **Ochrana saldokonta a duplicit** — nebezpečná nebo neúplná kontace se
   nezpracuje potichu. Automat upozorní na chybějící předpis, možnou duplicitu
   nebo konflikt pravidel.
6. **Dohledatelný původ** — u zápisu je vidět, zda rozhodla faktura, pravidlo,
   vestavěné rozpoznání, dříve naučený vzor nebo AI návrh.
7. **Storno místo mazání** — vrácení již provedeného zápisu zachová auditní
   stopu. Původní zápis se stornuje a kontace se vrátí do fronty ke kontrole.

Bez ohledu na zvolený preset se automaticky nikdy nezaúčtuje AI návrh,
nejednoznačná shoda, pohyb v uzavřeném období, položka nad platným limitem ani
operace, která by vytvořila nepodložený zůstatek saldokonta. Vlastní převod se
účtuje automaticky jen mezi evidovanými vlastními účty ve stejné měně. Odvod
státu nebo pojišťovně potřebuje existující zaúčtovaný předpis a dostatečný
kreditní zůstatek příslušného zúčtovacího účtu.

Výchozí bezpečný postup je jednoduchý: nejprve používejte režim **jen návrhy**,
několik dní kontrolujte výsledky a teprve ověřeným typům operací povolte vyšší
úroveň automatiky.

## 46.2 Orientace na stránce

V horní části jsou filtry firmy, zdroje rozhodnutí, typu operace, jistoty,
částky a období. Výsledky lze řadit podle data, jistoty, částky, typu operace
nebo zdroje. Výběr firmy je společný pro frontu, pravidla, checklist i
průvodce, takže editovaná pravidla vždy patří právě zobrazené firmě. Následují
pracovní záložky:

### Zaúčtováno dnes

Zobrazuje zápisy, které automat provedl bez ručního potvrzení. U každého řádku
vidíte firmu, datum, částku, důvod, jistotu a kontaci **MD/D**. Rozbalením řádku
se zobrazí náhled zápisu. Tlačítko **Zobrazit zápis** otevře účetní deník.

Tuto záložku stačí ráno rychle projít. Zaměřte se hlavně na neobvyklé částky,
nové protistrany a první použití nového pravidla. Pokud výsledek není správně,
použijte **Stornovat**; podrobnosti jsou v [§ 46.6](#466-vraceni-a-storno-zapisu).

Bez vlastního filtru data se zobrazují dnešní rozhodnutí a jsou seskupená podle
dne. Pokud nastavíte rozsah **Od/Do**, lze stejným způsobem projít i starší
historii. Delší seznamy jsou rozdělené na stránky; volba všech položek se vždy
týká jen právě zobrazené stránky.

### Ke schválení

Obsahuje návrhy, u kterých systém zná pravděpodobnou kontaci, ale podle
nastavení nebo bezpečnostní pojistky čeká na účetní. Tlačítko **Schválit**
vytvoří účetní zápis, **Zamítnout** návrh odmítne.

Pokud je návrh věcně správný, ale má chybné účty, použijte **Upravit kontaci**,
doplňte správné účty MD/D a schvalte opravenou variantu. Automat si rozdíl mezi
návrhem a výsledkem uloží jako učicí signál. Účty vždy zkontrolujte v kontextu
firmy uvedené na řádku.

Deterministické návrhy lze označit a schválit hromadně. Před potvrzením Automat
zobrazí počet položek a firem i souhrn obratů MD/D po účtech a měnách. Po
zaúčtování lze celou dávku jednou akcí vrátit. V rámci jedné firmy je vrácení
atomické: pokud by jediný zápis narazil na uzavřené nebo zamčené období,
nevrátí se z dávky této firmy nic. Do hromadného výběru se
nezahrnou:

- AI návrhy,
- položky v uzavřeném nebo zamčeném období,
- položky, ke kterým uživatel nemá právo zápisu,
- jiné typy řádků než bankovní návrhy.

Před hromadným schválením zkontrolujte zobrazený dopad a rozbalte alespoň první
položky každého pravidla. Hromadná akce je rozdělena po firmách, takže se data
různých účetních jednotek nesmísí. Vybrané návrhy lze také hromadně odmítnout;
zvolený důvod se uloží pro další zlepšování pravidel.

### Vyžaduje zásah

Sem patří položky, které bez lidského rozhodnutí nelze bezpečně dokončit.
Červené nebo varovné označení neznamená poškozená data — znamená, že systém
zastavil automatiku dříve, než by mohl vzniknout chybný zápis.

Nad frontou je souhrn nejčastějších důvodů zásahu za posledních 30 dní.
Anomálie jsou zvýrazněné a řazené před běžné položky. Nejasnou položku lze
**Odložit** do následujícího dne; zůstane ve frontě, ale přesune se za aktivní
položky. Akce **Zdroj** otevře postranní detail bankovní transakce, výpisu nebo
dokladu bez ztráty rozepracované fronty.

| Důvod | Co znamená | Doporučený postup |
|---|---|---|
| Doklad není zaúčtovaný | Faktura nebo přijatý doklad nemá předpis v deníku | Otevřete doklad, zkontrolujte jej a použijte **Zaúčtovat doklad** |
| Období je uzavřené | Datum spadá mimo otevřené období nebo pod měkký zámek | Ověřte správnost data; období otevírejte jen podle interního postupu |
| Konflikt pravidel | Stejné platbě odpovídá více stejně silných pravidel | Porovnejte pravidla, jedno zpřesněte nebo snižte jeho prioritu |
| Podezření na duplicitu | Podobný zápis už v deníku existuje | Otevřete porovnání a ověřte doklad, částku, datum a protistranu |
| Chybí doklad | K bankovní platbě není účetní podklad | Vyžádejte doklad od klienta a zaúčtování dokončete až po jeho kontrole |
| Nejasné vyúčtování zálohy | Nelze bezpečně určit návaznost zálohy nebo DPH | Otevřete související doklady a posuďte vyúčtování ručně |
| Neobvyklá částka | Částka se odchyluje od známého chování protistrany | Porovnejte ji s dokladem a předchozími platbami |
| Překročený limit | Platba je nad stropem pravidla nebo denním limitem | Zkontrolujte ji a schvalte jednotlivě, případně upravte limit |
| Chybějící předpis závazku | Platba by vytvořila nesprávný zůstatek na zúčtovacím účtu | Nejdříve zaúčtujte předpis a poté platbu |
| Vypnuté pravidlo | Pravidlo bylo opakovaně zamítnuto | Zkontrolujte podmínky a účty; pravidlo opravte nebo ponechte vypnuté |

Přijatá zálohová výzva se mezi nezaúčtovanými předpisy nezobrazuje. Sama není
nákladem ani závazkem na účtu 321; účtuje se až její skutečná úhrada z banky
nebo pokladny proti účtu 314. Náklad a DPH vzniknou až z navazujícího finálního
daňového dokladu.

U konfliktu pravidel Automat zobrazí všechny odpovídající varianty včetně
kontace. Vyberte správné pravidlo a potvrďte je, nebo návrh zamítněte. U
podezření na duplicitu se vedle sebe zobrazí navržený a existující zápis s
odkazem do deníku; teprve po porovnání zvolte **Přesto schválit** nebo
**Již zaúčtováno**.

> [!TIP]
> Samostatná stránka [**K doúčtování**](47_Rucni_fronta_doctovani.md) ukazuje
> doklady bez předpisu, otevřené žádosti o podklad a skutečné bankovní pohyby,
> pro které **nevznikl žádný návrh**. Bankovní návrhy v jakémkoli stavu zůstávají
> pouze v Automatu, takže se obě fronty záměrně nepřekrývají.

### Pravidla

Zobrazuje celkové nastavení automatiky a pravidla bankovních pohybů. Pravidlo
typicky určuje směr platby, protistranu nebo text, případný rozsah částky a
výslednou kontaci. Pravidla udržujte co nejkonkrétnější. Obecné pravidlo podle
krátkého textu má větší riziko falešné shody než pravidlo podle bankovního účtu
protistrany.

Prázdná dolní nebo horní mez částky pravidlo neomezuje na dané straně intervalu.
Nižší číselná **priorita** se vyhodnocuje dříve. Rozsah částky určuje, na jaké
pohyby pravidlo platí; samostatný **limit pro automatiku** pouze rozhoduje, zda
shoda ještě smí rovnou účtovat, nebo musí zůstat návrhem. Nad tím vším stojí
celofiremní denní limit z nastavení automatiky.
U aktivního pravidla lze tlačítkem **Použít na historii** vytvořit návrhy také pro
dosud nezaúčtované bankovní transakce v otevřených obdobích. Běh nic nezaúčtuje
sám a při opakování nevytvoří duplicitní návrhy.

Převody mezi vlastními účty jsou vestavěné rozpoznání, nikoli běžné pravidlo.
Jejich režim nastavte v horním boxu **Automatika účtování** presetem
**Asistovaná** nebo **Plná automatika**. V podrobném nastavení musí být na
úrovni **Automaticky** jak **Převody mezi vlastními účty**, tak
**Rozpoznávání vlastních převodů**. Starší pravidlo vytvořené pro konkrétní
vlastní účet lze smazat; rozhodující je registr vlastních bankovních účtů.

### Checklist

Checklist nabízí tři pohledy:

- **Denní** — co automat provedl, co čeká na potvrzení a co vyžaduje zásah.
- **Měsíční závěrka** — upozorní, zda před uzavřením období nezůstala
  nevyřízená fronta.
- **DPH** — pomáhá projít položky důležité před přípravou daňového výstupu.

Kliknutím na řádek checklistu přejdete přímo na odpovídající frontu. Zelená
fajfka znamená splněnou kontrolu, nikoli automatické potvrzení účetní správnosti
všech podkladů.

### Historie

Historie ukazuje automatická zaúčtování, schválení, zamítnutí a nahrazené
návrhy. Slouží k dohledání, co se s položkou stalo a kdo rozhodnutí provedl.
U každé události je vidět částka a měna, kontace, popis, protistrana, datum
bankovní transakce, variabilní symbol a číslo účetního dokladu. Odkazy vedou
přímo na zápis v deníku a na zdrojovou bankovní transakci. Samotné účetní zápisy
a storna zůstávají dohledatelné také v účetním deníku.

## 46.3 Jak číst „Proč“ a jistotu

Štítek **Proč** vysvětluje zdroj návrhu:

- **Faktura** — platba byla spárována s konkrétním dokladem.
- **Pravidlo** — kontaci určilo pojmenované bankovní pravidlo.
- **Systémové rozpoznání** — systém rozpoznal bezpečný typ operace, například
  vlastní převod nebo odvod.
- **Naučeno** — návrh odpovídá dříve potvrzeným obdobným transakcím.
- **Předpis zálohy** — platba odpovídá evidovanému předpisu.
- **AI návrh** — jde pouze o pomůcku pro ruční kontrolu; nikdy se sám ani
  hromadně nezaúčtuje.

Jistota je zobrazena slovně i procentem. Vysoká jistota neznamená, že lze
vynechat účetní úsudek — vyjadřuje pouze sílu shody podle dostupných dat.
Nízká jistota je záměrný signál, že položku máte otevřít a ověřit podrobněji.
U návrhu s vysokou jistotou, který přesto čeká na potvrzení, Automat vypíše také
konkrétní pojistku, například překročený strop, denní limit, chybějící předpis,
anomálii nebo uzavřené období.

## 46.4 Doporučená ranní rutina

Pro běžný den si vyhraďte několik minut:

1. Otevřete **Zaúčtováno dnes** a zkontrolujte neobvyklé částky a nové vzory.
2. Přejděte na **Vyžaduje zásah**. Vyřešte nejprve uzavřená období, duplicity a
   chybějící doklady, protože mohou blokovat další práci.
3. Na kartě **Ke schválení** rozbalte návrhy, ověřte MD/D a schvalte je.
4. Hromadně potvrďte až opakované deterministické položky, které jste už
   jednotlivě ověřili.
5. Nakonec otevřete **Denní checklist** a zkontrolujte, zda nezůstala důležitá
   nevyřízená položka.

Při správě více firem ponechte filtr **Všechny firmy**. Fronta seřadí položky po
firmách a akce odešle vždy do správné účetní jednotky. Pokud potřebujete souvisle
pracovat jen na jedné firmě, vyberte ji ve filtru.

## 46.5 Schválení, zamítnutí a hromadná práce

Před schválením ověřte:

- zda popis a protistrana odpovídají očekávané operaci,
- zda částka a měna souhlasí s podkladem,
- zda datum patří do správného období,
- zda navržené účty MD/D odpovídají povaze operace,
- zda už podobný zápis v deníku neexistuje.

Zamítnutí není chyba. Je to informace, že daný návrh nemá být použit. Při
zamítnutí vyberte stručný důvod — chybný účet, cizí doklad, duplicitu nebo jiný
důvod. Opakovaná zamítnutí mohou pravidlo automaticky vypnout, aby dál
nevytvářelo nevhodné návrhy. Vypnuté pravidlo se objeví ve **Vyžaduje zásah** a
čeká na kontrolu.

## 46.6 Vrácení a storno zápisu

Po schválení se na několik sekund zobrazí oznámení s akcí **Vrátit zpět**.
Stejnou operaci lze u automatického zápisu vyvolat tlačítkem **Stornovat** na
kartě **Zaúčtováno dnes**.

Po hromadném schválení nabídne oznámení akci **Vrátit celou dávku**. Dávka jedné
firmy se vrací atomicky: buď vzniknou storna všech dosud aktivních zápisů, nebo
při nesplnění některé pojistky nevznikne žádné. Při výběru více firem se dávky
zpracují postupně po firmách; úspěšné vrácení předchozí firmy se kvůli chybě
následující firmy automaticky neodvolá.

Vrácení probíhá účetně bezpečně:

1. systém vyhledá původní zápis a jeho datum,
2. ověří, že původní období je stále otevřené a není zamčené,
3. vytvoří storno ve stejném datu jako původní zápis,
4. zachová původní zápis i storno pro audit,
5. vrátí kontaci do fronty **Ke schválení**, kde ji lze opravit nebo zamítnout.

Systém nikdy neposune storno potichu do dnešního období. Pokud bylo období
mezitím uzavřeno, zobrazí srozumitelné upozornění a nic nezmění. Další postup v
takové situaci určí osoba odpovědná za uzávěrku.

## 46.7 Průvodce prvním nastavením

Tlačítko **Průvodce automatikou** je dostupné pro jednu vybranou firmu. Průvodce
projde čtyři bezpečné kroky:

1. **Analýza historie** — pouze přečte opakované nezaúčtované korunové bankovní
   pohyby. V této fázi nic nemění.
2. **Návrhy pravidel** — seskupí podobné platby podle protistrany, variabilního
   symbolu nebo textu. Vy určíte, které skupiny použít, a zkontrolujete účty
   MD/D.
3. **Doplnění historie** — vytvoří pravidla a návrhy pro otevřená období.
   Transakce v uzavřených obdobích viditelně přeskočí.
4. **Režim a e-mailový přehled** — zvolíte úroveň automatiky a případný ranní
   souhrn.

Všechna pravidla vytvořená průvodcem začínají v režimu **jen návrhy**, i kdyby
byla v odeslaných datech uvedena plná automatika. Nejprve tak uvidíte jejich
výsledky ve frontě a můžete je bezpečně ověřit.

## 46.8 Úrovně automatiky

- **Vypnuto** — systém nové operace automaticky nezaúčtuje.
- **Jen návrhy** — rozpoznané operace čekají na ruční schválení; doporučeno pro
  začátek a pro nové firmy.
- **Asistovaná** — jednoznačné bezpečné typy mohou proběhnout automaticky,
  ostatní zůstávají ke schválení.
- **Plná automatika** — deterministické operace mohou být zaúčtovány podle
  politiky a limitů. Nejasné a AI návrhy stále vyžadují člověka.

Úroveň je jen horní hranice. Konkrétní bezpečnostní pojistka, limit, zavřené
období nebo nejednoznačnost vždy může operaci přesunout do ruční fronty.

## 46.9 Ranní e-mailový přehled

V průvodci lze zapnout ranní souhrn a vybrat hodinu odeslání. Přehled se
agreguje pro uživatele napříč všemi jeho povolenými firmami a obsahuje odkazy
přímo na návrhy ke schválení a položky vyžadující zásah.

Pokud není co řešit ani co oznámit, e-mail se neposílá. Přehled neprovádí žádnou
účetní operaci — pouze připomíná stav fronty.

Server kontroluje naplánované souhrny každou hodinu mezi 6:00 a 8:00 a odešle
je v hodině nastavené u konkrétní firmy. Jeden příjemce dostane souhrn za všechny
firmy, k nimž má přidělený přístup.

## 46.10 Jak se Automat učí z oprav

Automat si uchovává auditní stopu každé účetní opravy: změnu navržených účtů
MD/D, odmítnutí návrhu, ruční zaúčtování i storno. U naučeného návrhu proto
uvidíte srozumitelnou větu, kdy a z jaké kontace jste přešli na novou. Poslední
jednoznačná oprava má přednost před starší historií deníku; rozporné opravy
nevytvoří další návrh bez vaší kontroly.

Pravidlo v režimu **jen návrhy** ukazuje počet potvrzení beze změny. Po pěti
takových potvrzeních za sebou, bez odmítnutí a s vyplněným rozsahem částky,
nabídne tlačítko **Povýšit na automatiku**. K povýšení nikdy nedojde samo —
rozhodnutí vždy potvrdí člověk. Tlačítko **Historie** zobrazí časovou osu
pravidla, zaznamenané změny kontace, autora a úspěšnost.

Pokud automatický zápis stornujete, pravidlo se bezpečně vrátí do režimu **jen
návrhy** a začne znovu sbírat potvrzení. Storno se nepočítá jako odmítnutí;
samostatná ochrana tří odmítnutí různých transakcí zůstává zachovaná.

Správce může pravidelně spouštět miner korekcí. Ten hledá nejméně tři
konzistentní ruční opravy stejného bankovního protějšku a vytvoří z nich pouze
nové návrhové pravidlo. Nikdy tím nezapne plnou automatiku a ignoruje
saldokontní účty i rozporné vzory. Standardní plánovač jej spouští denně ve
4:00; AI worker pro povolené firmy zpracovává čekající úlohy každých deset
minut.

## 46.11 AI návrhy účtování

AI asistence je ve výchozím stavu vypnutá. Správce ji může zapnout v části
**Nastavení → Integrace → AI → AI asistence účtování** zvlášť pro bankovní
transakce a přijaté faktury. Před zapnutím je nutné potvrdit zpracovatelskou
smlouvu (DPA) s právě zvoleným poskytovatelem. Po změně poskytovatele je
vyžadováno nové potvrzení; bez něj systém žádná data neodešle. Rozbalovací AI
dotaz se v dialogu **Zaúčtovat** zobrazí až tehdy, když je tato volba zapnutá,
rozsah obsahuje bankovní transakce, poskytovatel má vyplněné přihlašovací údaje,
vyhovuje rezidenční politice a DPA je potvrzená.

Před odesláním se údaje omezí a pseudonymizují. Poskytovatel dostane částku,
měnu, měsíc, směr pohybu, kód banky a nevratné otisky identifikátorů. Variabilní
symbol se odešle jen jako obecný tvar a volný text se redukuje na povolené
účetní pojmy. Jména, e-mailové adresy, telefonní čísla, čísla účtů, IBAN a
samotné variabilní symboly se neposílají. Pseudonymizační klíč zůstává pouze v
databázi dané firmy.

AI používá dva zdroje návrhů:

- podobnost s dříve potvrzenými zápisy dané firmy; tento režim se aktivuje po
  nejméně 20 naučených rozhodnutích a nikdy neporovnává data jiné firmy,
- jazykový model pro případy, kde známý vzor nestačí.

V dialogu **Zaúčtovat** lze rozbalit položku **Zeptat se AI na kontaci** a
doplnit účetní kontext, například „jde o výběr kartou do pokladny“. Dotaz se
před odesláním rovněž očistí od osobních a identifikačních údajů. Výsledek pouze
předvyplní účty MD/D; zápis vznikne až po jejich kontrole a potvrzení tlačítkem
pro zaúčtování. Dotaz lze upravit a odeslat znovu.

Každé použití **Zeptat se AI** se uloží do auditní stopy návrhu, včetně použitého
poskytovatele/modelu a výsledku. Pokud úsporný model vrátí prázdnou, neplatnou
nebo příliš nejistou odpověď pro bankovní kontaci, systém smí provést právě
jeden ohraničený pokus silnějším modelem. I tento krok je zaznamenaný. Když ani
druhý výsledek není použitelný nebo poskytovatel selže, nic se nepředvyplní a
položka zůstane k ručnímu zpracování; nevzniká žádná opakovací smyčka ani
náhradní automatické zaúčtování.

U rozpracované přijaté faktury může AI navrhnout nákladový nebo majetkový účet
a kategorii. Návrh lze jednotlivě použít nebo odmítnout. Doklady s ručním
rozdělením DPH, dlouhodobým majetkem nebo již uzamčené doklady zůstávají pod
výhradně ruční kontrolou.

Každý AI návrh má nízký strop jistoty, nikdy se nezaúčtuje automaticky a není
součástí hromadného schválení. Pokud účetní schválí méně než polovinu posledních
návrhů, systém daný AI zdroj pozastaví. Správce jej může po kontrole v nastavení
znovu povolit. Denní limit chrání firmu před nečekanou spotřebou placeného
poskytovatele.

## 46.12 Měsíční rutina před uzávěrkou

Před uzavřením období doporučujeme:

1. nastavit filtr **Od/Do** na uzavíraný měsíc,
2. vyřešit všechny položky **Vyžaduje zásah**,
3. schválit nebo zamítnout všechny návrhy **Ke schválení**,
4. projít automatické zápisy s vyššími nebo neobvyklými částkami,
5. otevřít [**Účetnictví → K doúčtování**](47_Rucni_fronta_doctovani.md)
   a dořešit bankovní pohyby bez návrhu,
   nezaúčtované doklady a žádosti o podklady,
6. projít [**Účetnictví → Úplnost dokladů**](54_Uplnost_dokladu.md) v obou směrech,
7. otevřít **Checklist → Měsíční závěrka** a ověřit prázdnou frontu,
8. teprve potom pokračovat v kontrolách účetního období a uzávěrce.

Automat je pomocník pro třídění a opakované účtování. Nenahrazuje kontrolu
úplnosti dokladů, bankovních zůstatků, saldokonta, DPH ani závěrkové operace.
Podrobný postup je v samostatné kapitole
[Úplnost dokladů](54_Uplnost_dokladu.md).

## 46.13 Klávesové zkratky

Na kartách fronty lze urychlit opakovanou práci:

| Klávesa | Akce |
|---|---|
| **J** / **K** | další / předchozí řádek |
| **Enter** | rozbalit nebo zavřít detail |
| **A** | schválit vybraný návrh |
| **X** | zamítnout vybraný návrh |
| **Shift+A** | hromadně schválit označené způsobilé návrhy |
| **1**, **2**, **3** | přepnout hlavní pracovní záložku |

Zkratky se nespouštějí při psaní do filtrů nebo jiných vstupních polí.

## 46.14 Časté otázky

**Může automat zaúčtovat něco do uzavřeného období?**

Ne. Uzavřené období i měkký zámek mají před automatikou přednost.

**Co když pravidlo navrhne špatný účet?**

Návrh zamítněte a pravidlo opravte. Při opakovaných zamítnutích se pravidlo
vypne a objeví se ve frontě k zásahu.

**Je vysoká jistota zárukou správnosti?**

Ne. Je to informace o kvalitě shody, nikoli náhrada účetního posouzení.

**Může AI návrh projít bez mé kontroly?**

Ne. AI návrhy jsou vždy jednotlivé, ručně kontrolované a vyloučené z hromadného
schválení i automatického zaúčtování.

**Co se stane při kliknutí na Vrátit zpět?**

Vznikne auditovatelné storno v původním otevřeném období a návrh se vrátí do
fronty. Pokud je období už uzavřené, systém operaci odmítne beze změny dat.

**Proč položku vidím, ale nemohu ji schválit?**

Nejčastěji je období zamčené nebo máte pro danou firmu pouze právo čtení. Stav
ověřte u správce rolí nebo osoby odpovědné za účetní období.

**Musím při společné frontě přepínat firmu?**

Ne. Řádek nese firmu s sebou a server při každé akci znovu ověří vaše oprávnění
k této firmě.

Další informace o importu pohybů a bankovních pravidlech jsou v kapitole
[Banka — výpisy a párování](28_Banka.md). Účetní období a uzávěrku popisuje
kapitola [Účetní období a uzávěrka](87_Uzaverka.md).
