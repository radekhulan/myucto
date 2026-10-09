# 28. Majetek

> Návod, jak vést dlouhodobý hmotný a nehmotný majetek: založit kartu, zařadit ji do užívání, zaúčtovat
> roční odpisy, přidat technické zhodnocení a majetek vyřadit nebo prodat. Pro firmy v podvojném
> účetnictví i v daňové evidenci.

## 28.1 Kdy to potřebujete

- Přišla faktura za pořízení dlouhodobého majetku (stroj, vozidlo, software) a chcete založit kartu s odpisy.
- Přebíráte majetek z jiného programu a potřebujete ho založit i s dosavadními odpisy.
- Je konec roku a je třeba zaúčtovat odpisy.
- Majetek jste technicky zhodnotili (dostavba, modernizace).
- Majetek se prodal, zlikvidoval, daroval, nebo vznikla škoda či manko.
- Chcete dočasně přerušit daňové odpisování, nebo opravit daňový odpis, který počítala účetní mimo program.
- Máte majetek vedený jen na účtu (například pozemky) a chcete ho dostat do evidence.

Modul vede evidenci **dlouhodobého hmotného a nehmotného majetku**: karty s inventárními čísly, výpočet **daňových odpisů**
(§ 26 až 33 zákona o daních z příjmů) i **účetních odpisů** (ČÚS 013), technická zhodnocení a vyřazení. Je dostupný v
režimu **podvojného účetnictví** i **daňové evidence**. V daňové evidenci se nic neúčtuje a vedou se jen daňové odpisy, rozdíly
popisuje [§ 28.12.14](#281214-majetek-v-danove-evidenci).

<!-- cols: 24 46 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| při pořízení | Založit kartu z faktury nebo ručně | `Nákup → Majetek`, [§ 28.3](#283-krok-za-krokem-zalozit-kartu-majetku) |
| při uvedení do provozu | Zařadit kartu do užívání | detail karty, [§ 28.4](#284-krok-za-krokem-zaradit-majetek-do-uzivani) |
| jednou ročně před uzávěrkou | Zaúčtovat odpisy roku | seznam majetku, [§ 28.6](#286-krok-za-krokem-zauctovat-odpisy-roku) |
| při technickém zhodnocení | Přidat technické zhodnocení | detail karty, [§ 28.7](#287-krok-za-krokem-pridat-technicke-zhodnoceni) |
| při vyřazení nebo prodeji | Vyřadit kartu | detail karty, [§ 28.8](#288-krok-za-krokem-vyradit-nebo-prodat-majetek) |

> [!WARNING]
> Sazby a koeficienty daňových odpisů (§ 30 až 32 ZDP) i podmínky mimořádných odpisů bezemisních vozidel (§ 30a) jsou v systému
> napevno zakódované podle aktuálního znění zákona. Modul sám nehlídá budoucí legislativní změny. Zkontrolujte vždy aktuální stav
> se svou účetní, zejména u hraničních částek (80 000 Kč, limit 2 000 000 Kč u vozidel M1).

## 28.2 Než začnete

- Firma musí být v režimu **podvojného účetnictví** nebo **daňové evidence**.
- Majetek pořízený přijatou fakturou musí mít na faktuře označený druh nákladu **Dlouhodobý majetek** (viz [Přijaté faktury](23_Prijate_faktury.md)).
- V podvojném účetnictví musí mít pro zaúčtování odpisů účetní období stav otevřené (viz [Uzávěrka](72_Uzaverka.md)). V daňové
  evidenci nesmí mít rok dokončenou roční uzávěrku (viz [Daňová evidence](74_Danova_evidence.md)).
- Pro čtení, filtrování a export stačí čtecí oprávnění, pro všechny akce karty (založení, úprava, zařazení, technické zhodnocení,
  vyřazení, zaúčtování) je potřeba zápis.
- Drobnější majetek, který se účtuje přímo do nákladů, patří do kapitoly [Drobný majetek](27_Drobny_majetek.md). Ten nemá daňový
  ani účetní odpisový plán.

## 28.3 Krok za krokem: založit kartu majetku

### 28.3.1 Z přijaté faktury

1. Otevřete `Nákup → Majetek` a klikněte na **Z přijaté faktury**.
2. V seznamu přijatých faktur s příznakem dlouhodobého majetku vyberte doklad. U faktur, které už kartu mají, je štítek
   **Už má kartu**. U každé vidíte doklad, dodavatele, DUZP a základ bez DPH.
3. Otevře se editor nové karty s předvyplněnou **vstupní cenou** (základ bez DPH, případně cena s DPH, pokud u dokladu není
   nárok na odpočet), **datem pořízení** (DUZP, případně datum vystavení) a **názvem** (popis položky nebo dodavatel a číslo dokladu).
4. Doplňte údaje podle [§ 28.12.2](#28122-pole-karty) a uložte.

**Jak poznáte, že je hotovo:** karta je v seznamu ve stavu **Koncept** a v editoru i detailu je odkaz zpět na přijatou fakturu.

### 28.3.2 Ručně

1. Otevřete `Nákup → Majetek` a klikněte na **Nový majetek**.
2. Vyplňte **Inventární číslo** (povinné, doporučený tvar například `M-000001`) a **Název** (povinný).
3. V sekci **Účty** vyberte **Majetkový účet**. Účet oprávek a účet pořízení se předvyplní podle mapy účtů (například 022, 082, 042).
4. Zadejte **Vstupní cenu** (povinná, kladná) a **Datum pořízení**.
5. V části **Daňové odpisy** vyberte metodu a u rovnoměrné či zrychlené metody **Odpisovou skupinu** 1 až 6.
6. U odpisovaného majetku vyplňte **Účetní životnost (měsíce)** a **Účetní zbytkovou hodnotu**.
7. Uložte.

**Jak poznáte, že je hotovo:** karta je v seznamu ve stavu **Koncept**.

### 28.3.3 Historický majetek (převzatý z jiného programu)

1. Při zakládání nové karty zaškrtněte **Historický majetek** (volba je jen při zakládání).
2. Zadejte **Datum zařazení**, kolik let bylo daňově odepsáno (**Daňově odepsáno let**) a kolik Kč (**Daňové odpisy dosud**),
   kolik měsíců bylo účetně odepsáno (**Účetně odepsáno měsíců**) a kolik Kč (**Účetní odpisy dosud**).
3. Uložte.

**Jak poznáte, že je hotovo:** karta je rovnou ve stavu **V užívání** s historií. Pole historie už nejde po uložení měnit.
Počáteční daňové oprávky nesmějí přesáhnout vstupní cenu a počáteční účetní oprávky spolu s účetní zbytkovou hodnotou ji také
nesmějí překročit. Stejná kontrola platí při importu karet.

## 28.4 Krok za krokem: zařadit majetek do užívání

1. Otevřete detail karty ve stavu **Koncept**.
2. Klikněte na **Zařadit do užívání**.
3. Zadejte **Datum zařazení** (nesmí předcházet datu pořízení).
4. Ponechte zaškrtnuté **Zaúčtovat zařazení (02x / 04x)**. Systém zaúčtuje MD majetkový účet / D účet pořízení ve výši vstupní
   ceny (navýšené o technická zhodnocení dokončená do data zařazení).
5. Potvrďte.

**Jak poznáte, že je hotovo:** hláška **Majetek byl zařazen do užívání.** a stav **V užívání**.

Zaškrtávátko vypněte pro **historický majetek**, kde zůstatky na účtech 02x přicházejí počátečními stavy. Pokud by přitom datum
spadalo do otevřeného období, aplikace upozorní varováním.

## 28.5 Krok za krokem: zkontrolovat plán odpisů

1. Otevřete detail karty.
2. V části **Plán odpisů** přepínejte záložky **Daňové** a **Účetní** (záložka se nabízí, jen pokud majetek daný druh odpisu má).
3. Zkontrolujte po řádcích rok, **ZC počátek**, **Odpis**, případně **Uplatněno**, **ZC konec** a **Stav**.

**Jak poznáte, že je hotovo:** plán odpovídá očekávání. Než poprvé spustíte hromadné zaúčtování odpisů, projděte si plán u pár karet,
snadno tak odhalíte špatně zadanou odpisovou skupinu nebo životnost dřív, než se promítne do zaúčtovaných částek.

U zaúčtovaného roku je dostupná akce **Smazat**. Smazání odstraní zápis z deníku, účetní odpis i potvrzený daňový odpis stejného
roku, aby šel rok opravit a zaúčtovat znovu. Mazat lze pouze poslední potvrzený rok, v otevřeném období, mimo uzamčenou část
účetnictví a dokud majetek není vyřazený. Existující přerušení daňového odpisu zůstane zachováno.

## 28.6 Krok za krokem: zaúčtovat odpisy roku

1. Otevřete `Nákup → Majetek` a klikněte na **Zaúčtovat odpisy**.
2. Vyberte **Zdaňovací období (rok)**. Probíhající ani budoucí období zaúčtovat nelze.
3. Klikněte na **Zaúčtovat**.
4. Přečtěte si výsledek: počet **Zaúčtováno**, **Přeskočeno** a případně **Chyby**.

**Jak poznáte, že je hotovo:** dialog ukáže hlášku ve tvaru „Zaúčtováno: X, přeskočeno: Y“. Opakované spuštění pro stejný rok
zápisy přepíše na místě, dokud je dané účetní období otevřené.

> [!TIP]
> Zaúčtování odpisů spouštějte typicky **jednou ročně** před uzávěrkou období (viz [Uzávěrka](72_Uzaverka.md)). Po uzavření
> období už zápis pro daný rok zaúčtovat nejde.

## 28.7 Krok za krokem: přidat technické zhodnocení

1. Otevřete detail karty ve stavu **V užívání** a klikněte na **Technické zhodnocení**.
2. Zadejte **datum dokončení** (nesmí předcházet datu zařazení do užívání), **částku** a volitelně **popis**.
3. Uložte.
4. Zaúčtujte pořízení zhodnocení na účty 02x a 042 **ručním zápisem v deníku**. Modul účetní zápis pořízení nevytváří.

**Jak poznáte, že je hotovo:** hláška **Technické zhodnocení bylo přidáno.**, zhodnocení je v tabulce **Technická zhodnocení (§33)**
a promítne se do vstupní ceny i do plánu odpisů obou druhů od příslušného roku a měsíce dál.

Pokud součet zhodnocení za daný rok nepřesáhne 80 000 Kč, aplikace upozorní, že nejde o povinné technické zhodnocení dle § 33
a lze zvážit jednorázový náklad. Technické zhodnocení nejde přidat u majetku odpisovaného mimořádnými odpisy § 30a, pro takové
zhodnocení založte samostatnou kartu odpisovanou ve skupině 2.

## 28.8 Krok za krokem: vyřadit nebo prodat majetek

### 28.8.1 Vyřazení z karty

1. Otevřete detail karty ve stavu **V užívání** a klikněte na **Vyřadit**.
2. Zvolte **Typ vyřazení**: **Prodej**, **Likvidace**, **Dar** nebo **Manko a škoda**.
3. Zadejte **Datum vyřazení** (nesmí předcházet datu zařazení). U prodeje můžete zadat **Prodejní cenu bez DPH** (jen evidenční údaj).
4. Ponechte zaškrtnuté **Zaúčtovat vyřazení**. Pokud už vyřazení zaúčtoval deník, volbu vypněte (viz [§ 28.12.8](#28128-vyrazeni-ktere-uz-je-v-deniku)).
5. Klikněte na **Vyřadit majetek**.

**Jak poznáte, že je hotovo:** hláška **Majetek byl vyřazen.** a stav **Vyřazeno**.

> [!WARNING]
> Karta majetku **neúčtuje tržbu z prodeje**. U typu Prodej aplikace jen připomene, že výnos (účet 641 a DPH) je potřeba zaúčtovat
> samostatnou **vydanou fakturou**. Vyřazovací zápis řeší jen odúčtování majetku a jeho oprávek, ne inkaso od kupujícího.

### 28.8.2 Prodej z vydané faktury

Rychlejší cesta, která šetří ruční vyřazování:

1. V editoru vydané faktury zaškrtněte **Prodej majetku**.
2. U položky vyberte kartu z našeptávače.
3. Fakturu vystavte.

**Jak poznáte, že je hotovo:** řádek zaúčtuje výnos na **641** (u drobného majetku na 642) a aplikace sama provede vyřazení typu
Prodej se všemi kroky popsanými v [§ 28.12.7](#28127-vyrazeni), včetně vazby karty na doklad. Faktura navíc dostane klasifikaci DPH
**1m/2m**, protože prodej dlouhodobého majetku se podle § 76 odst. 4 ZDPH nezapočítává do koeficientu. Storno faktury vyřazení
vrátí (jen dokud je období otevřené).

### 28.8.3 Vrátit vyřazení

1. Otevřete detail vyřazené karty a klikněte na **Vrátit vyřazení**.
2. Potvrďte dialog.

**Jak poznáte, že je hotovo:** hláška **Vyřazení bylo vráceno.** a stav **V užívání**. Funguje, jen dokud je účetní období data
vyřazení stále otevřené (§ 35 zákona o účetnictví).

## 28.9 Krok za krokem: přerušit nebo ručně opravit daňový odpis

### 28.9.1 Přerušit odpis roku (§ 26 odst. 8 ZDP)

1. V detailu karty s rovnoměrnou nebo zrychlenou daňovou metodou klikněte na **Přerušit odpis roku**.
2. Vyberte rok a potvrďte.
3. Přerušení odvoláte tlačítkem **Zrušit přerušení** u řádku roku (dostupné, dokud je karta v užívání).

**Jak poznáte, že je hotovo:** hláška „Daňový odpis roku X byl přerušen.“ a v plánu odpisů je u roku značka přerušení.

### 28.9.2 Ručně přepsat daňový odpis roku

Typicky když přebíráte čísla účetní, která odpisy počítala mimo program (jiná vstupní cena, zaokrouhlení).

1. V plánu odpisů na záložce **Daňové** klikněte u potvrzeného roku na ikonu tužky (**Ručně přepsat daňový odpis roku**).
2. Zadejte **Daňový odpis roku (Kč)** a povinný **Důvod**. Dialog ukazuje i spočtený nebo převzatý odpis.
3. Uložte. Přepis zrušíte ikonou šipky zpět (**Zrušit ruční přepis**).

**Jak poznáte, že je hotovo:** hláška „Daňový odpis roku X byl ručně přepsán.“ a řádek nese štítek **ručně** s důvodem v bublině.

## 28.10 Krok za krokem: hromadně přenést karty a založit souhrnnou kartu

### 28.10.1 Import a export Excelem

1. Na seznamu majetku klikněte na **Export**, soubor obsahuje aktuální stav všech karet.
2. Pro hromadné založení nebo aktualizaci klikněte na **Import**. Otevře se stejný importní dialog jako u ostatních číselníků
   (například [Klienti](18_Klienti.md)) se zobrazením řádků, případných chyb validace a shrnutím po dokončení.

**Jak poznáte, že je hotovo:** dialog ukáže shrnutí založených a aktualizovaných karet.

> [!TIP]
> Import je praktický při přechodu z jiného účetního systému. Historický majetek založíte rovnou ve stavu V užívání s vyplněnými
> poli o dosavadních odpisech ([§ 28.3.3](#2833-historicky-majetek-prevzaty-z-jineho-programu)).

### 28.10.2 Souhrnná karta z účtu

1. Klikněte na **Souhrnná karta z účtu**. Aplikace ukáže účty neodpisovaného majetku (031, 032 a jejich analytiky) se zůstatkem v deníku.
2. U účtu klikněte na **Založit kartu**.
3. Po dalších pohybech účtu klikněte na **Srovnat s deníkem**.

**Jak poznáte, že je hotovo:** hláška o založení souhrnné karty a karta nese štítek **Souhrnná karta účtu**.

## 28.11 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Pole vstupní ceny nebo daňové parametry jsou neaktivní | Po prvním potvrzeném daňovém odpisu se uzamknou parametry a cena, po zařazení do užívání pořizovací údaje | Změna je možná jen po smazání posledního potvrzeného odpisu (akce **Smazat** v plánu odpisů), jinak založte novou kartu |
| Přerušit odpis roku aplikace odmítne | Existuje potvrzený pozdější daňový odpis | Nejdřív vraťte potvrzení pozdějších let |
| Technické zhodnocení nelze přidat nebo smazat | Existuje potvrzený pozdější odpis, nebo jde o majetek s odpisem § 30a | Vraťte potvrzení pozdějších let, nebo založte samostatnou kartu |
| Vyřazení aplikace odmítne | Datum v roce, po kterém existuje potvrzený odpis, nebo je přeskočený předchozí nepotvrzený daňový rok | Pozdější roky vraťte, chybějící předchozí rok zaúčtujte nebo přerušte |
| Zaúčtování odpisů roku u jedné karty selhalo | Uzavřené období, nebo chybí potvrzený či přerušený předchozí daňový rok | Doplňte předchozí rok, ostatní karty se zaúčtují normálně |
| Vrátit vyřazení nejde | Účetní období data vyřazení je uzavřené | Vrácení po uzávěrce není možné |
| Metoda **Mimořádné §30a** se neuloží | Majetek není bezemisní vozidlo pořízené prvním odpisovatelem mezi 1. 1. 2024 a 31. 12. 2028 | Zvolte jinou metodu |
| Upozornění, že vozidlo M1 přesahuje 2 000 000 Kč | Uplatněný odpis nad 2 000 000 Kč se poměrně krátí (§ 30e) | Ověřte, zda platí výjimka z limitu |
| Upozornění, že nejde o hmotný majetek pro daňové odpisy | Movitý hmotný majetek do 80 000 Kč (§ 26 odst. 2) | Zvažte evidenci jako [Drobný majetek](27_Drobny_majetek.md) |
| Smazání karty v užívání skončí hláškou | Existují potvrzené nebo zaúčtované odpisy, nebo technická zhodnocení | Odpisy odstraňte odzadu, zrušte přerušení, případně zhodnocení smažte |
| Odpis nelze ručně přepsat | Odpis by převýšil daňovou zůstatkovou cenu, nebo je rok ve schválené závěrce | Zadejte nižší částku, v uzavřeném roce změna není možná |

## 28.12 Podrobnosti a pravidla

### 28.12.1 Seznam majetku

Stránka **Majetek** zobrazuje všechny karty firmy v tabulce se sloupci **Inv. číslo**, **Název**, **Účet**, **Zařazení**,
**Vstupní cena** (u karet navýšených o technická zhodnocení je pod částkou drobná poznámka „vč. TZ"), **Daňová ZC**,
**Účetní ZC**, **Stav** a volitelně (přes výběr sloupců) **Způsob odpisu**, **Odpisová skupina** a **Datum vyřazení**.
Sloupce zapnete a vypnete přes ikonu výběru sloupců, hustotu řádků přepínačem hustoty. Obojí se pamatuje jako u ostatních seznamů.

Filtrovat lze podle **Stavu** (Koncept, V užívání, Vyřazeno) a fulltextem podle **inventárního čísla nebo názvu**. Filtry si
můžete uložit jako výchozí pohled (stejný mechanismus uložených filtrů jako jinde v aplikaci). Kliknutí na řádek otevře detail
karty. Nad tabulkou jsou tlačítka **Export**, **Import**, **Zaúčtovat odpisy**, **Souhrnná karta z účtu**, **Z přijaté faktury**
a **Nový majetek**.

### 28.12.2 Pole karty

Editor karty (**Nový majetek**, úprava konceptu) je rozdělený do sekcí.

**Identifikace** - **inventární číslo** (povinné), **název** (povinné), volitelný **popis** a **druh majetku**
(Hmotný majetek, Nehmotný majetek).

**Účty** - **majetkový účet** (01x, 02x, 03x dle osnovy firmy), **účet oprávek** (07x, 08x, prázdná volba znamená neodpisovaný
majetek, například pozemky § 27) a **účet pořízení** (041, 042). Při výběru majetkového účtu se účet oprávek i účet pořízení
**předvyplní automaticky** podle zavedené mapy syntetických účtů (například 022 na 082 na 042, 013 na 073 na 041), ruční úprava
je možná.

**Pořízení** - **vstupní cena** (§ 29, povinná, kladná) a **datum pořízení**. Pokud je karta založená z faktury, zobrazí se i
odkaz na zdrojový doklad. U movitého hmotného majetku s daňovým odpisem a vstupní cenou do 80 000 Kč se zobrazí upozornění, že
nejde o hmotný majetek pro daňové odpisy dle § 26 odst. 2.

**Daňové odpisy** - metoda:

<!-- cols: 30 70 -->
| Druh majetku | Dostupné metody |
|---|---|
| Hmotný majetek | Rovnoměrné §31, Zrychlené §32, Mimořádné §30a (bezemisní vozidlo), Neodpisuje se |
| Nehmotný majetek | Daňový = účetní (DNM, § 24 odst. 2 písm. v), Neodpisuje se |

U rovnoměrné a zrychlené metody je povinná **odpisová skupina 1 až 6** (doba odpisování 3, 5, 10, 20, 30, 50 let) a volitelně
**zvýšení odpisu v 1. roce** (§ 31 odst. 1 písm. b až d: +10 %, +15 %, +20 %). Zvýšení je dostupné jen pro skupiny 1 až 3 a jen
pro **prvního odpisovatele**. U osobního automobilu kategorie M1 zvýšení podle § 31 odst. 5 použít nelze. U hmotného majetku se
dál zaškrtává **první odpisovatel** a pokud jde o vozidlo, i **vozidlo kategorie M1** (limit § 30e: uplatněný odpis nad
2 000 000 Kč vstupní ceny se poměrně krátí), **výjimka z limitu** (sanitní nebo pohřební vozidlo, koncese) a **bezemisní vozidlo**.
Pokud je vozidlo M1 bez výjimky a vstupní cena přesahuje 2 000 000 Kč, editor na to upozorní.

Metoda **Mimořádné §30a** je povolená jen pro **bezemisní vozidlo pořízené prvním odpisovatelem mezi 1. 1. 2024 a 31. 12. 2028**,
jinak editor uložení odmítne s vysvětlením podmínek.

**Účetní odpisy** - sekce se zobrazí jen u odpisovaného majetku (má vybraný účet oprávek): **účetní doba použitelnosti v
měsících** (povinná, alespoň 1) a **účetní zbytková hodnota** (musí být nezáporná a menší než vstupní cena). Metodu účetního
odpisu volíte jako **Rovnoměrně dle doby použitelnosti** nebo **Shodně s daňovým odpisem**. Účetní odpisový plán je čistě
**prospektivní**: datum zařazení do užívání zatím nemusí být známé, plán se dopočítá až podle skutečného zařazení.

**Historický majetek** - zaškrtávátko se nabízí jen při zakládání nové karty (viz [§ 28.3.3](#2833-historicky-majetek-prevzaty-z-jineho-programu)).

### 28.12.3 Zámky po zařazení a potvrzení

Karta se v čase postupně **uzamyká**, aby nešlo měnit už uplatněné odpisy zpětně:

- Po **prvním potvrzeném daňovém odpisu** (potvrzený rok přes zaúčtování nebo vyřazení) se uzamknou **daňové parametry** (metoda,
  skupina, zvýšení, vlastnosti vozidla) i **vstupní cena**.
- Po **zařazení do užívání** (karta opustí stav Koncept) se uzamknou **pořizovací údaje** (vstupní cena, datum pořízení), bez
  ohledu na to, zda už byl potvrzený odpis.

Zamčená pole jsou v editoru neaktivní s vysvětlující bublinou. Účetní parametry (životnost, zbytková hodnota) zamčené nejsou, jejich
změna se promítne prospektivně do dalších měsíců plánu.

### 28.12.4 Detail karty a plán odpisů

Detail karty ukazuje hlavičku (název, stav, inventární číslo, druh, metoda a skupina, trojice účtů), **souhrnné dlaždice**
(vstupní cena, **Zvýšená vstupní cena** po zhodnocení, **Oprávky**, daňová a účetní zůstatková cena), základní údaje (datum
pořízení, zařazení, účetní životnost, odkaz na fakturu) a, pokud existují, tabulku **technických zhodnocení** s celkovým součtem.
Kartu lze stáhnout jako inventární kartu (**Stáhnout inventární kartu**).

Při zapnutých dimenzích má detail panel **Dimenze** karty (v editoru karty sekce **Dimenze**). Středisko, projekt nebo jiná
dimenze karty, případně jejich rozpad, se zapíše na zařazení, účetní odpisy i vyřazení. Karta bez vlastních dimenzí přebírá
dimenze přijaté faktury (její položky), ze které vznikla. Podrobnosti v kapitole [Dimenze](114_Dimenze.md#114118-majetek).

Klíčová část detailu je **Plán odpisů** se záložkami **Daňové** a **Účetní**. Tabulka po řádcích (rok) ukazuje **ZC počátek**,
**Odpis** (u daňových, kde se liší uplatněná částka od stanovené kvůli § 30e, i sloupec **Uplatněno**), **ZC konec** a **Stav**
řádku:

- **Plán** - rok je jen dopočítaný „na papíře", zatím nic nezaúčtováno ani nepotvrzeno.
- **Potvrzeno** (daňová záložka) nebo **Zaúčtováno** (účetní záložka) - rok už má reálný záznam v databázi (u účetní záložky i zápis
  v deníku).

Řádky mohou nést značky **Půlodpis §26/7** (půlodpis v roce vyřazení) a **Přerušeno §26/8** (viz [§ 28.12.9](#28129-preruseni-danoveho-odpisu)).
U mimořádných odpisů (§ 30a) a u účetních odpisů se dá řádek roku **rozkliknout** (**Měsíční rozpis**). Pod tabulkou je poznámka
o znění zákona, ke kterému jsou sazby v systému vedené.

Plán se počítá průběžně: minulé roky vycházejí z potvrzených a zaúčtovaných záznamů, budoucí roky se dopočítávají podle aktuálních
parametrů karty. Změníte-li parametr, který ovlivňuje výpočet (například přidáte technické zhodnocení), plán pro nepotvrzené roky
se přepočítá okamžitě.

### 28.12.5 Zařazení do užívání a mazání karty

Kartu lze **smazat** nejen ve stavu Koncept, ale také po chybném zařazení do užívání. U karty v užívání se spolu s kartou atomicky
smaže i účetní zápis zařazení, takže ji lze založit znovu se správným datem. Smazání je možné jen bez potvrzených nebo zaúčtovaných
odpisů a bez technických zhodnocení. Případné odpisy je potřeba nejdřív odstranit odzadu a přerušení zrušit. Zápis zařazení musí
být v otevřeném období a mimo uzamčenou část účetnictví. Vyřazenou kartu je nutné nejdřív vrátit z vyřazení.

### 28.12.6 Technické zhodnocení (§ 33)

Přidané zhodnocení se objeví v tabulce technických zhodnocení v detailu a promítne se do vstupní ceny i do plánu odpisů obou
druhů od příslušného roku a měsíce dál. Samotné **zaúčtování** zhodnocení na účty 02x a 042 se dělá **ručním zápisem v deníku**:
karta pořizovací cenu a odpisy promítne automaticky, ale účetní zápis pořízení zhodnocení modul negeneruje. Zhodnocení nejde
přidat u majetku odpisovaného mimořádnými odpisy § 30a (zhodnocení u něj vstupní cenu nezvyšuje). Smazat lze jen zhodnocení
z roku, který ještě nemá potvrzený daňový odpis.

**Chronologie navazujících let.** Přidání i smazání technického zhodnocení kontroluje, jestli už neexistuje **potvrzený daňový
nebo účetní odpis pozdějšího roku**. Pokud ano, zásah do dřívějšího roku aplikace odmítne, protože by zpětně změnil odpisovou
základnu, se kterou už pozdější potvrzený rok počítal. V takovém případě je nejdřív potřeba vrátit potvrzení pozdějších let.

### 28.12.7 Vyřazení

Při potvrzení vyřazení aplikace v jedné transakci:

1. dopočítá a zaúčtuje **účetní odpis roku vyřazení** až do měsíce vyřazení včetně,
2. potvrdí **daňový odpis roku vyřazení** (typicky půlodpis § 26 odst. 7, u § 30a poslední měsíc před vyřazením),
3. zaúčtuje **vyřazovací zápis**: u odpisovaného majetku nejdřív doodepsání zůstatkové ceny (náklad podle typu vyřazení: 541
   prodej, 551 likvidace, 543 dar, 549 manko a škoda proti oprávkám), pak vyřazení v (zvýšené) pořizovací ceně z oprávek proti
   majetkovému účtu. U neodpisovaného majetku (§ 27) jde jen o jeden pár v plné pořizovací ceně,
4. nastaví kartu do stavu **Vyřazeno** s datem, typem a případnou prodejní cenou.

Vyřazení současně hlídá chronologii odpisů: odmítne datum v roce, po kterém už existuje potvrzený daňový nebo účetní odpis, a
nedovolí přeskočit bezprostředně předchozí nepotvrzený daňový rok. Nejdřív je potřeba pozdější roky vrátit, případně chybějící
předchozí rok zaúčtovat nebo přerušit.

U **darování** majetku, u kterého byl uplatněn odpočet DPH, aplikace upozorní na nutnost ověřit odvod DPH z ceny obvyklé (§ 13
odst. 4 písm. a a § 36 odst. 6 písm. a ZDPH). U **likvidace, manka nebo škody** připomene doložení způsobu vyřazení a případné
vyrovnání či úpravu odpočtu (§ 77 a § 78e ZDPH). Karta sama interní daňový doklad nevytváří.

### 28.12.8 Vyřazení, které už je v deníku

Pokud vyřazení už zaúčtoval deník (převod z jiného programu nebo ruční zápis zůstatkové ceny 54x proti oprávkám), vypněte v dialogu
vyřazení volbu **Zaúčtovat vyřazení**. Karta se vyřadí **bez zaúčtování**: zůstatková cena ani vyřazení z evidence se znovu
neúčtují, jinak by byly v deníku dvakrát. Daňový odpis roku vyřazení se potvrdí stejně jako při běžném vyřazení, účetní odpisy
roku musí být v deníku. Karta se naváže na zápis, který vyřazení zaúčtoval (jediný zápis ke dni vyřazení s MD 54x proti oprávkám
karty, u neodpisovaného majetku proti majetkovému účtu). V detailu je na něj odkaz **Zápis vyřazení v deníku** a přiznání
k dani z příjmů z něj bere účetní zůstatkovou cenu pro rozdíl účetní a daňové zůstatkové ceny. Nenajde-li se zápis, nebo je jich
víc, aplikace upozorní a účetní zůstatková cena se vezme z karty.

Převody z Money S3, POHODY a PREMIER vyřazení v převáděném období zaznamenají takto samy. Typ vyřazení berou ze zdroje, a když ho
zdroj neuvádí, rozhodne deník: tržba z prodeje majetku (účet 641) ke dni vyřazení znamená prodej, jinak likvidace. Kartu, kterou
převod vyřadit nedokáže, ponechá jako koncept s pokynem vyřadit ji bez zaúčtování.

**Vrácení vyřazení** stornuje vyřazovací zápis i účetní odpis roku vyřazení, smaže jím vytvořené řádky roku vyřazení a vrátí kartu
do stavu V užívání. Dříve zvolená pauza daňového odpisu podle § 26 odst. 8 zůstává zachována. U vyřazení bez zaúčtování vrácení
v deníku nic nestornuje, jen vrátí kartu do užívání a zruší daňový odpis roku, který k vyřazení dopočetl systém.

### 28.12.9 Přerušení daňového odpisu

U karty s **rovnoměrnou nebo zrychlenou** daňovou metodou se po potvrzení daný rok označí jako **přerušený** podle § 26 odst. 8 ZDP:
daňový odpis za rok je nulový, ale zůstatková cena se nemění a v dalších letech se pokračuje, jako by k přerušení nedošlo (roky
odpisování se jen posunou).

Přerušit lze jen rok, po kterém **není potvrzený žádný pozdější** daňový odpis. U zrychlené metody (§ 32) by přerušení zpětně
posunulo odpisové schéma a znehodnotilo výši odpisu už potvrzeného pozdějšího roku. Pokud takový pozdější rok existuje, aplikace
přerušení odmítne a je potřeba nejdřív vrátit potvrzení pozdějších let, teprve pak přerušit rok dřívější.

### 28.12.10 Ruční přepis daňového odpisu: důsledky

Po uložení přepisu:

- stanovený i uplatněný odpis roku je zadaná částka a daňová zůstatková cena roku se posune o rozdíl, stejně jako zůstatková cena
  potvrzených pozdějších let (jejich částky se nemění),
- řádek nese štítek **ručně** a v bublině důvod a původní odpis,
- odpis se promítne do přiznání k dani z příjmů (rozdíl účetních a daňových odpisů a tabulka odpisů podle skupin v příloze),
- změnu zapíše protokol činností (kdo, kdy, původní a nová částka, důvod).

Hromadné zaúčtování odpisů, vyřazení ani opakovaný převod ručně přepsaný odpis nemění. Odpis nesmí převýšit daňovou zůstatkovou
cenu na začátku roku a v roce se schválenou účetní závěrkou ho už měnit nejde.

### 28.12.11 Hromadné zaúčtování odpisů: podrobnosti

Po spuštění aplikace projde **všechny karty V užívání** (i vyřazené v daném roce) a pro každou:

- zaúčtuje **účetní odpis roku** (MD nákladový účet z kontace odpisů, typicky 551 / D účet oprávek z karty) a zapíše
  odpovídající řádek do [Účetního deníku](52_Ucetni_denik.md) se zdrojem „odpis", se stejnou idempotencí jako ostatní automatické
  zápisy modulu Účetnictví,
- potvrdí **daňový odpis roku** (bez zápisu do deníku, daňový odpis je čistě evidenční údaj pro přiznání k dani z příjmů).

Roky s aktivním přerušením § 26 odst. 8 a ručně přepsaným daňovým odpisem se u daňové části přeskočí (pauza i přepis zůstávají
zachované). Operace je **hromadná a idempotentní**: opakované spuštění pro stejný rok přepíše zápisy na místě, dokud je dané účetní
období otevřené. Pokud u některé karty zaúčtování selže (například kvůli uzavřenému období), operace pro ostatní karty pokračuje
dál a chyba se jen připočítá do souhrnu.

Kromě uzavřeného období může zaúčtování jednotlivé karty selhat i na **chronologii**: pokud majetek ještě má nenulovou daňovou
zůstatkovou cenu a bezprostředně předchozí zdaňovací rok u něj nemá potvrzený ani přerušený daňový odpis (typicky se nějaký rok
omylem přeskočil), aplikace odpis roku pro tuto kartu odmítne, dokud předchozí rok nedoplníte nebo nepotvrdíte. I tahle chyba se
jen připočítá do souhrnu, ostatní karty se zaúčtují normálně.

### 28.12.12 Souhrnná karta z účtu: podrobnosti

Některý majetek bývá veden jen na účtu, bez inventárních karet, typicky portfolio pozemků na účtu 031, převzaté z jiného
programu. Aby se objevil v evidenci i inventarizaci majetku, založte mu **souhrnnou kartu**. **Vstupní cena** karty je počáteční
stav účtu (první otevírací zápis v deníku), každý další zápis deníku na účtu je **zvýšení nebo snížení ceny** s datem a dokladem
zápisu (v detailu karty v tabulce zhodnocení). Karta tak sedí na zůstatek účtu. Je v užívání, nic neúčtuje a neodpisuje se. Pokud
otevírací stav některého roku nenavazuje na pohyby předchozích let, karta rozdíl nese jako samostatný pohyb a aplikace na to upozorní.

Po dalších pohybech účtu kartu srovnejte s deníkem tlačítkem **Srovnat s deníkem** (v přehledu účtů nebo v detailu karty). Karta
se přepočítá z aktuálního deníku, druhá karta nevznikne. Účet, na kterém už jsou vlastní karty majetku, souhrnnou kartu nedostane:
pohyby deníku nejde rozdělit mezi karty a majetek by se započetl dvakrát.

### 28.12.13 Omezení a tipy

- **Sazby a koeficienty** daňových odpisů jsou v systému pevně dané aktuálním zněním zákona (poznámka pod plánem odpisů uvádí,
  ke kterému datu). Při legislativní změně je potřeba prostudovat aktuální stav se svou účetní.
- **Vstupní cenu a daňové parametry** po prvním potvrzeném odpisu, resp. pořizovací údaje po zařazení do užívání, už nejde měnit.
  Před zaúčtováním prvního odpisu zkontrolujte kartu pečlivě.
- **Tržba z prodeje majetku se neúčtuje z karty**: vždy je potřeba vystavit vydanou fakturu s výnosem 641 (a DPH). API při
  vyřazení podporuje její evidenční vazbu na kartu, ale současný webový dialog výběr faktury nenabízí. Účetní výnos v každém
  případě vzniká z faktury, ne z karty.
- **Technické zhodnocení u § 30a (bezemisní vozidla)** vstupní cenu nezvyšuje, pro zhodnocení u takového vozidla založte novou kartu.
- **Vrácení vyřazení** funguje, jen dokud je účetní období data vyřazení otevřené. Po uzávěrce (viz [Uzávěrka](72_Uzaverka.md)) už
  vyřazení vrátit nejde.
- Uživatelé bez oprávnění k zápisu mají všechny akce karty (založení, editace, zařazení, zhodnocení, vyřazení, zaúčtování)
  nedostupné, mohou jen prohlížet seznam, detail a exportovat.

### 28.12.14 Majetek v daňové evidenci

Firma v daňové evidenci (§ 7b ZDP) nemá účtovou osnovu ani deník. Karta majetku je proto jen evidence pro daňové odpisy
(§ 26 až 33 ZDP) a liší se takto:

<!-- cols: 30 70 -->
| Oblast | Daňová evidence |
|---|---|
| Karta | Místo tří účtů se volí jen **druh majetku** (stavby, hmotné movité věci, software, pozemky…). Druh určuje, zda se majetek odpisuje. Pozemky a umělecká díla se neodpisují. |
| Účetní odpisy | Nevedou se. Plán odpisů ukazuje jen daňové odpisy. U nehmotného majetku se zadává doba odpisování v měsících a odpisuje se rovnoměrně po měsících. |
| Zařazení do užívání | Jen evidenční údaj, nic se nezaúčtuje. |
| Odpisy roku | Tlačítko **Potvrdit odpisy roku** potvrdí daňové odpisy u všech karet v užívání. Potvrzené odpisy vstupují do výdajů § 7 v přiznání k dani z příjmů fyzických osob, ne do peněžního deníku. |
| Vyřazení | Dopočte se daňový odpis roku vyřazení (u rovnoměrných a zrychlených odpisů polovina, § 26 odst. 7 ZDP). Daňová zůstatková cena prodaného nebo zlikvidovaného majetku je výdajem (§ 24 odst. 2 písm. b) ZDP) a přiznání ji do výdajů zahrne samo. Darovaný majetek výdaj nemá. U škody je zůstatková cena výdajem jen do výše náhrad nebo při živelní pohromě či neznámém pachateli, uznatelnou část zadejte ručně v roční uzávěrce daňové evidence. |
| Prodej | Příjem z prodeje se z karty nezapisuje. Vystavte fakturu, do peněžního deníku vstoupí jejím zaplacením. |
| Zámek roku | Rok s dokončenou roční uzávěrkou daňové evidence už nejde měnit: vyřazení, vrácení vyřazení ani ruční přepis odpisu. Nejdřív uzávěrku vraťte do rozpracovaného stavu. |
| Nedostupné | Souhrnná karta z účtu, import a export Excelem a drobný majetek patří k účetnictví, v daňové evidenci nejsou. |

Pořízení majetku peněžní deník do výdajů nezapočítá, pokud je přijatá faktura označená jako dlouhodobý majetek. Výdajem jsou
až odpisy, takže kartu založte z téže faktury (`Nákup → Majetek → Z přijaté faktury`).

## 28.13 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md) - odkud se majetek zakládá.
- [Drobný majetek](27_Drobny_majetek.md) - operativní evidence věcí účtovaných rovnou do nákladů, bez odpisového plánu. Nezaměňujte ji s kartami dlouhodobého majetku v této kapitole.
- [Účetní deník](52_Ucetni_denik.md) - kam se zařazení, odpisy a vyřazení zapisují.
- [Uzávěrka](72_Uzaverka.md) - uzavření období, po kterém už odpisy nezaúčtujete.
- [Dimenze](114_Dimenze.md#114118-majetek) - středisko a další dimenze karty.
