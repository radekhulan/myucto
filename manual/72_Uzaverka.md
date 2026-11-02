# 72. Uzávěrka

> Návod, jak spravovat účetní období a provést roční uzávěrku: od předběžných kontrol přes uzavření knih
> a otevření nového roku až po schválení závěrky, rozdělení výsledku hospodaření a uzávěrkový balíček.
> Pro účetní a administrátory firem v podvojném účetnictví. Archiv účetnictví je popsán v kapitole
> [Nástroje](73_Ucetni_nastroje.md#73115-obsah-kompletniho-exportu).

## 72.1 Kdy to potřebujete

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| Zakládáte nový účetní rok nebo hospodářský rok | Založit účetní období | `Nástroje → Uzávěrka`, **Nové období** ([§ 72.3](#723-krok-za-krokem-zalozit-ucetni-obdobi)) |
| Firma vznikla v průběhu roku a první období začíná 1. ledna | Posunout začátek prvního období na den vzniku | `Nástroje → Uzávěrka`, **Změnit začátek** ([§ 72.3](#723-krok-za-krokem-zalozit-ucetni-obdobi)) |
| Rok skončil a chcete uzavřít účetnictví | Projít kroky uzávěrkového průvodce | `Nástroje → Uzávěrka`, odkaz **Uzávěrka** u období ([§ 72.4](#724-krok-za-krokem-uzaverka-roku-pruvodce)) |
| Kroky jsou hotové | Uzavřít knihy a otevřít nový rok | Průvodce, kroky **Uzavření knih** a **Otevření nového roku** ([§ 72.5](#725-krok-za-krokem-uzavreni-knih-a-otevreni-noveho-roku)) |
| Závěrku schvaluje statutár nebo valná hromada | Schválit závěrku | `Nástroje → Uzávěrka`, **Schválit závěrku** ([§ 72.6](#726-krok-za-krokem-schvaleni-zaverky-a-znovuotevreni-obdobi)) |
| Valná hromada rozhodla o zisku nebo ztrátě | Rozdělit výsledek hospodaření | Stránka uzávěrky schváleného období ([§ 72.7](#727-krok-za-krokem-rozdeleni-vysledku-hospodareni)) |
| Potřebujete přeplánovat číslování nebo nastavit výkazy | Upravit číselné řady a nastavení uzávěrky | [§ 72.8](#728-krok-za-krokem-ciselne-rady-a-nastaveni-uzaverky) |
| Předáváte závěrku auditorovi nebo do archivu | Připravit uzávěrkový balíček | [§ 72.9](#729-krok-za-krokem-uzaverkovy-balicek) |
| Každý měsíc | Provést měsíční kontrolu a zamknout měsíc | `Účetnictví → Měsíční kontrola` ([§ 72.10](#7210-krok-za-krokem-mesicni-kontrola)) |

## 72.2 Než začnete

1. **Podvojné účetnictví.** Celý modul (období, průvodce i uzávěrkový balíček) je dostupný jen firmám v podvojném účetnictví. U daňové evidence se místo něj zobrazuje daňový deník příjmů a výdajů.
2. **Role.** Většinu kroků zvládne role **účetní**. Zahájení a přerušení uzávěrky, přípravné kroky a úpravu prefixů číselných řad dělá i účetní. Administrátor je navíc potřeba pro uzavření knih, otevření nového roku, revert kroků, schválení a znovuotevření období. Uzávěrkový balíček vyžaduje oprávnění k exportu sestav.
3. **Zaúčtované doklady.** Nezaúčtované koncepty a doklady bez účetního zápisu uzávěrku zablokují (kontroly v kroku 1).
4. **Odpisy majetku zaúčtujte předem.** Zaúčtovat odpisy lze na stránce Majetek jen v otevřeném období. Udělejte to před zahájením uzávěrky (viz [Majetek § 28.6](28_Majetek.md#286-krok-za-krokem-zauctovat-odpisy-roku)).
5. **Souběžné účtování.** Při zahájení uzávěrky organizačně zastavte souběžné běžné účtování (viz [§ 72.12.6](#72126-omezeni-a-tipy)).
6. **Souvislá řada období.** Období musí tvořit souvislou řadu let bez mezer i překryvů.

> [!TIP]
> Uzávěrku dělejte v pořadí shora dolů. Krok 1 (kontroly) vám dopředu ukáže, co je potřeba doladit ještě
> před tím, než administrátor uzavře knihy. Dokud závěrku neschválíte, dokončené kroky lze v povoleném
> pořadí vzít zpět a opravit.

## 72.3 Krok za krokem: založit účetní období

1. Otevřete `Nástroje → Uzávěrka`. Stránka **Účetní období** ukazuje tabulku všech roků firmy: **Účetní rok**, **Začátek**, **Konec**, **Stav** a **Akce** (na mobilu karty).
2. Klikněte na **Nové období** (vyžaduje právo zápisu).
3. Vyplňte **Začátek** a **Konec** období a **Účetní rok** (celé číslo 2000 až 2200). Nemusí jít o kalendářní rok, podporovaný je i hospodářský rok podle nastavení firmy **Účetní rok začíná** ([§ 95.5](95_Multi_supplier.md#955-krok-za-krokem-zakladni-udaje-a-rezim-uctovani)). Účetní rok se dopočítá ze začátku: je to rok, do kterého začátek patří. U zkráceného prvního období hospodářského roku (firma vzniklá 1. 3. 2026 s rokem od srpna, období 1. 3. až 31. 7. 2026) je to 2025.
4. Uložte.

**Jak poznáte, že je hotovo:** Období je v tabulce se stavem **Otevřené**.

Aplikace kontroluje, že začátek je dřív než konec, že pro daný rok ještě jiné období není a že se období nepřekrývá s žádným existujícím obdobím firmy.

**Začátek prvního období.** První účetní období začíná dnem vzniku firmy (§ 3 zákona o účetnictví).
Když aplikace založila období od 1. ledna a firma vznikla později, klikněte u prvního období na
**Změnit začátek**, zadejte den vzniku a uložte. Konec období a účetní rok zůstávají. Tlačítko je jen
u nejstaršího období ve stavu **Otevřené** a nový začátek musí patřit do téhož účetního roku.
Aplikace změnu odmítne, když je v období zápis v deníku s datem před novým začátkem. Takový zápis
nejprve stornujte nebo přeúčtujte. Firma s hospodářským rokem musí mít v nastavení firmy vyplněné
**Účetní rok začíná**, jinak by zkrácené první období změnilo, jak aplikace zakládá další roky.

> [!TIP]
> Chybějící období si aplikace většinou doplní sama (viz [§ 72.12.2](#72122-automaticky-doplnena-obdobi)).
> Automaticky se nezakládá první období firmy: jeho hranice a počáteční rozvahu určíte v průvodci aktivací
> účetnictví ([Aktivace účetnictví](68_Aktivace_ucetnictvi.md)). Na tento stav upozorní karta **Akce pro vás**
> na nástěnce.

## 72.4 Krok za krokem: uzávěrka roku (průvodce)

Odkaz **Uzávěrka** u období otevře stránku **Uzávěrka období**: vlevo kroky, vpravo detail vybraného kroku.
V záhlaví vidíte rok, rozsah dat, štítek stavu a po spočtení i výsledek hospodaření. Dokončený krok má
zelenou fajfku, přeskočený pomlčku a popisek „přeskočeno".

1. U období ve stavu **Otevřené** klikněte na **Zahájit uzávěrku**. Teprve pak se zpřístupní kroky, které vytvářejí nebo potvrzují uzávěrkové zápisy. Stav přejde na **Uzavírá se**.
2. Projděte kroky v pevném, závazném pořadí. Každý musí být před uzavřením knih buď dokončený, nebo vědomě přeskočený.
3. Chcete-li uzávěrku zrušit, klikněte (jen ve stavu Uzavírá se) na **Přerušit uzávěrku** a potvrďte. Období se vrátí do stavu Otevřené.

<!-- cols: 6 28 66 -->
| # | Krok | Podmíněnost |
|---|---|---|
| 1 | Předběžné kontroly | vždy |
| 2 | Odpisy majetku | potvrdit nebo přeskočit (firma bez majetku přeskočí) |
| 3 | Kurzové rozdíly | jen jsou-li cizoměnové položky |
| 4 | Dohadné položky | dle potřeby (potvrdit nebo přeskočit) |
| 5 | Časové rozlišení | dle potřeby (potvrdit nebo přeskočit) |
| 6 | Opravné položky | volitelný |
| 7 | Daň z příjmů | volitelný (u fyzické osoby se přeskočí) |
| 8 | Zásoby | podmíněný: jen firma se skladem účtovaným způsobem B, jinak se přeskočí sám |
| 9 | Uzavření knih | až po dokončení nebo přeskočení kroků 1 až 8 |
| 10 | Otevření nového roku | až po Uzavření knih |

Každý krok se dokončuje tlačítkem **Potvrdit krok** (volitelně s poznámkou), nebo **Přeskočit**. U dokončeného
kroku se zobrazí datum potvrzení. Administrátor může dokončený krok vrátit tlačítkem **Vzít krok zpět**
(zápisy kroku se smažou s auditní stopou).

### 72.4.1 Krok 1: Předběžné kontroly

1. Klikněte na **Spustit kontroly**.
2. Projděte tabulku se sloupci **Závažnost** (Chyba, Varování, Info), **Kontrola** a **Hodnota**. Řádky jsou proklikatelné (**Zobrazit v seznamu**).
3. Opravte chyby a kontroly spusťte znovu. Je-li výsledek zastaralý (změnila se data, typicky po uzavření předchozího období), aplikace to ohlásí a vyzve ke spuštění znovu.

Kontrolují se mimo jiné:

- **služby SVJ** v rámci 504: shoda záloh, dohadů, skutečných nákladů a vyúčtování s hlavní knihou; nedokončená návaznost nebo nedoložený zdroj blokují uzavření knih i při nulovém rozdílu (viz [Registr domů a jednotek](116_Registr_domu_a_jednotek.md#kontrola-shody-salda)),

- zda je **předchozí období uzavřené**,
- **nezaúčtované koncepty** v deníku období a nevyrovnaný deník (Σ MD ≠ Σ Dal),
- **vydané a přijaté faktury** období bez účetního zápisu,
- nenulové zůstatky technických účtů: **261** (Peníze na cestě), **395** (Vnitřní zúčtování), **041/042** (nedokončené pořízení majetku),
- **mezičlen plateb kartou** (378.x): zůstatek každé analytiky karty proti konkrétním nevypořádaným platbám kartou a zvlášť rozdíl, který platbami vysvětlit nejde (viz [Platební karty](31_Platebni_karty.md#31974-kontroly)); analytiky karet se proto nehlásí u 261/395 ani mezi průběžnými účty,
- **nerozdělený výsledek hospodaření na 431** z minulých let,
- **majetek v užívání bez zaúčtovaných odpisů** roku,
- **cizoměnové otevřené doklady** čekající na přecenění,
- zůstatky **dohadných účtů 388/389** a **časového rozlišení 381 až 385**,
- informativní upozornění na **splatnou daň z příjmů** (591/341), kterou je nutné zaúčtovat ručně.

**Jak poznáte, že je hotovo:** Kontroly neobsahují žádnou chybu. Při alespoň jedné chybě se u tlačítka zobrazí
červený štítek **Chyby brání uzavření knih** a krok Uzavření knih zůstane zablokovaný.

### 72.4.2 Krok 2: Odpisy majetku

1. Klikněte na **Přejít na Majetek - Zaúčtovat odpisy** a odpisy roku zaúčtujte v modulu Majetek.
2. Vraťte se do průvodce, vyplňte případnou poznámku a klikněte na **Potvrdit krok** (nebo na **Přeskočit**, pokud firma odpisy neeviduje).

Nebyl-li v období žádný odpisovaný majetek, krok se přeskočí sám.

> [!WARNING]
> Tlačítko **Zaúčtovat odpisy** na stránce Majetek funguje jen do stavu období Otevřené. Jakmile období přejde
> do Uzavírá se, stejné tlačítko odpisy dál nezaúčtuje (hlásí neotevřené období). V panelu kroku je ale
> tlačítko **Zaúčtovat odpisy roku**, které odpisy zaúčtuje přímo pro tento krok. V běžném provozu přesto
> odpisy zaúčtujte na Majetku ještě před zahájením uzávěrky.

### 72.4.3 Krok 3: Kurzové rozdíly

**Účetní rámec 504:** Náhled vychází z doložených účetních případů a jejich aktuálních zůstatků.
U nejednoznačných zápisů vyberte skutečný původ, doložte podklady a rozepište přesné částky v Kč i cizí měně.
Potom obnovte náhled. Potvrzená historická přiřazení jsou uzamčená a nelze je v dalším roce přepsat.
Chybějící výsledkové dimenze doplňte společnými buňkami s odůvodněním; částky musí přesně pokrýt kurzový rozdíl.
Systém automaticky nedoplňuje hlavní činnost ani daňový režim. Neúplný náhled sčítá pouze vyřešené položky.
Zaúčtování vyžaduje aktuální podklady, aktivní účet a úplné rozhodnutí. Přenesené historické dimenze mohou
zůstat uzavřené, nově doplněné hodnoty musí být aktivní. Kurzové zisky a ztráty se zachovávají po jednotlivých
případech i při uzavření a otevření knih. Cenné papíry vyžadují samostatné doložené ocenění.

V části **Cenné papíry** doložte profil a kategorii účetní jednotky, identitu a zařazení nástroje a přesný rozpis inventurních řádků v Kč i cizí měně. Editor nabízí pořizovací ocenění majetkových cenných papírů, tržní reálnou hodnotu a ekvivalenci u účastí; přípustnost ověřuje podle profilu, období a doloženého vlivu. Podle metody přiložte pořizovací cenu a posouzení návratnosti, aktuální tržní podklady nebo podíl a vlastní kapitál emitenta. Nenulová změna vyžaduje odpovídající existující účetní zápis se správnými účty a úplnými dimenzemi. Peněžní část na společném účtu doložte odděleně. Chybějící či změněné podklady brání dokončení.

Kupónové a diskontní dluhové cenné papíry spravujte v **Účetnictví > Dluhové cenné papíry**. Zvolte skutečný účetní řádek pořízení a doložte smluvní nominál, čistou pořizovací cenu, nakoupený naběhlý úrok, splatnost a účetní zařazení. U kupónů vyplňte navazující smluvní období, roční sazbu a způsob výpočtu dnů. Pro dluhopisy s pevným kupónem zvolte ACT/ACT (ICMA) a počet výplat za rok: kupón za období je vždy roční sazba krát nominál dělená počtem výplat, i když období obsahuje přestupný den, a naběhlý úrok se počítá poměrem uplynulých dní období. Konvence ACT/365F a ACT/360 zůstávají pro instrumenty, jejichž podmínky je výslovně uvádějí. U držby do splatnosti potvrďte metodu rovnoměrného časového rozlišení podle skutečných dnů (ČÚS 406 bod 3.4.2): rozdíl mezi pořizovací cenou bez kupónu a nominálem se rozpouští na 644 Úroky (diskont) nebo 544 Úroky (prémie) tak, aby účetní hodnota jistiny ke dni splatnosti přesně odpovídala nominálu. Rozpis v měně instrumentu i v Kč musí odpovídat celému vybranému řádku.

Příklad: dluhopis s nominálem 1 000 000 Kč koupený 1. 7. 2025 za 980 000 Kč, kupón 4 % ročně vyplácený vždy 1. 7., splatnost 1. 7. 2028. K 31. 12. 2025 se zaúčtuje naběhlý úrok 20 164,38 Kč (184 dní ze 365) a u držby do splatnosti rozpuštění diskontu 3 357,66 Kč (184 dní z 1 096), obojí na vrub analytického účtu cenného papíru a ve prospěch 644. Za celou dobu držby vynese úrok 120 000 Kč a rozpuštění diskontu 20 000 Kč.

Ke zvolenému účetnímu dni vytvořte náhled naběhlého úroku, diskontu nebo prémie HTM a po kontrole jej potvrďte. Při tržním přecenění obchodního instrumentu doložte přípustný profil účetní jednotky a čistou tržní kotaci bez naběhlého úroku. Potvrďte také doloženou kotaci, která účetní hodnotu nemění; uloží se její podklady bez nového účetního pohybu. Nakoupený a nově naběhlý úrok se vedou odděleně od jistiny a čisté tržní ceny.

Inkaso kupónu a splacení jistiny přiřazujte k již zaúčtovanému příjmu na bankovním výpisu nebo pokladním dokladu. Nový peněžní zápis tím nevzniká. Vyberte celý odpovídající účetní řádek ve stejném dni a při splacení zadejte vrácený smluvní nominál. Smluvní nominál se může lišit od účetní hodnoty řádku například po přecenění nebo při původní prémii. Konečné splacení vyžaduje vypořádané časové rozlišení a přesnou zbývající účetní hodnotu jistiny.

Prodej před splatností zaúčtujte nejprve jako příjem na bankovním výpisu nebo pokladním dokladu ve prospěch účtu 653 Tržby z prodeje cenných papírů a podílů, a to v plné přijaté částce včetně úroku, který zaplatil kupující. U cenného papíru v cizí měně veďte řádek tržby ve stejné měně. V evidenci pak ke dni prodeje doúčtujte naběhlý úrok a u držby do splatnosti i rozpuštění rozdílu, zvolte operaci prodeje, zadejte prodávaný smluvní nominál a vyberte řádek tržby. Prodej celého zbývajícího nominálu vyřadí celou účetní hodnotu cenného papíru včetně naběhlého úroku na vrub 553 Prodané cenné papíry a podíly. Při částečném prodeji se vyřadí poměrná část jistiny podle prodaného nominálu a alikvotní úrok běžného kupónového období připadající na prodaný nominál; splatné a dosud neuhrazené kupóny zůstávají u zbytku držby. Zbytek dál nabíhá úrok jen ze zbývajícího nominálu a u držby do splatnosti se zbývající rozdíl rozpouští rovnoměrně do splatnosti, takže prodaná část se znovu nerozpouští. Příklad: z dluhopisu s nominálem 1 000 000 Kč (viz výše) se k 31. 3. 2026 prodá nominál 400 000 Kč. Jistina 985 000 Kč se vyřadí ve výši 394 000 Kč, prodaný alikvotní úrok činí 12 010,96 Kč, na vrub 553 jde 406 010,96 Kč. Zbytek 600 000 Kč nese jistinu 591 000 Kč a úrok 18 016,44 Kč. U cizí měny se účetní hodnota (včetně kurzového rozdílu z poslední uzávěrky) ke dni prodeje nejprve přepočte kurzem ČNB, a to úrok vždy a jistina u držby do splatnosti; jistina obchodní pozice zůstává v reálné hodnotě z rozvahového dne. Kurzový rozdíl se zaúčtuje ve stejném zápisu na 645 nebo 545 a na 553 jde prodaná část v přepočtené hodnotě. Zbytek pokračuje s přepočtenou hodnotou a přecení ho další uzávěrka. Rozdíl mezi 653 a 553 je zisk nebo ztráta z prodeje. Po prodeji celé držby už pozice další úrok ani rozpuštění nenabíhá a uzávěrka ho po dni prodeje nevyžaduje.

Evidence navazuje přes uzavření a otevření období. U cizí měny přebírá ověřený kurzový rozdíl z uzávěrky včetně případného obrácení při otevření. Historické přecenění použité pozdější operací nelze znovu přepsat. Zdrojové účetní zápisy a přiřazené peněžní doklady jsou chráněny před změnou. Poslední operaci lze opravit řízeným stornem: vygenerovaný zápis (úrok, rozpuštění, přecenění, prodej) se stornuje protizápisem a prodej tím zároveň uvolní řádek tržby. Přiřazené inkaso kupónu nebo splacení jistiny se stornem jen odváže, a to ve stejném dni, kdy bylo přiřazeno; bankovní nebo pokladní doklad pak lze opravit a přiřadit znovu. Změna podkladů ruší platnost náhledu a vyžaduje nové ověření.

Evidence dluhových cenných papírů je určena účetním jednotkám, které účtují podle vyhlášky č. 504/2002 Sb. U firmy v rámci vyhlášky č. 500/2002 Sb. stránka zobrazí vysvětlení a zápis nenabídne.

U doložené korunové hotovostní úhrady cizoměnového dokladu systém načítá nominál z propojené evidence platby. Chybějící nebo nejednoznačná vazba se zobrazí jako nevyřešený podklad a zabrání zaúčtování. Ověřte zdrojový doklad, platbu a její účetní zápis a obnovte náhled.

Následující přehled polí Saldokonto a Devizové účty popisuje průvodce pro účetní rámec 500.

Krok se zpřístupní až po zahájení uzávěrky. Zobrazí přecenění cizoměnových položek kurzem ČNB k rozvahovému
dni (§ 24 odst. 6 a 7 zákona o účetnictví, ČÚS 006 pro rámec 500 a ČÚS 404 pro rámec 504).

1. V části **Saldokonto** zkontrolujte tabulku cizoměnových vydaných (FV) a přijatých (FP) faktur: zbývá v cizí měně, kurz dokladu a kurz ČNB. Změna ocenění od dokladu, přecenění přenesené z minulých let a skutečný zisk nebo ztráta tohoto roku mají samostatné sloupce. Zelená označuje zisk běžného roku, červená ztrátu; změna korunové hodnoty závazku sama o sobě není zisk. Nad tabulkou je pro každou měnu použitý kurz ČNB a datum; pokud se ke dni nenašel platný kurz a použil se náhradní, zobrazí se u měny varovná ikona.
2. V části **Devizové účty a valutové pokladny** doplňte řádky: účet z osnovy, měna, **Zůstatek v cizí měně**. Tlačítkem **Přidat řádek pokladny** přidáte řádek, křížkem ho odeberete. Aplikace nabízí i návrhy zůstatků z posledních bankovních výpisů, účet osnovy ale doplňte ručně (výpis nese jen číslo účtu banky).
3. Cizoměnové zůstatky účtů **378** a **379** (například eurová záloha od faktora) aplikace přecení sama z účetního deníku. Zobrazí je v samostatné tabulce jen pro čtení. Bere jen řádky v dané měně, korunové pohyby na týchž účtech se nepřeceňují. Tyto účty do devizových účtů nezadávejte, aplikace je tam odmítne.
4. Klikněte na **Přepočítat návrh** (náhled bez zaúčtování).
5. Klikněte na **Zaúčtovat kurzové rozdíly** (jen ve stavu Uzavírá se). Účty se řídí rámcem platným na konci daného období: pro vyhlášku 500 jde o **563** (kurzová ztráta) a **663** (kurzový zisk), pro vyhlášku 504 o **545** a **645**. V rámci 500 se zachovávají vlastní nastavené kontace. Součty jsou pod tabulkami.
6. Potvrďte krok.

Administrátor může přecenění vzít zpět tlačítkem **Zrušit přecenění** (smaže zaúčtovaný zápis).

Saldokontní kurzové zisky a ztráty se počítají pro jednotlivé doložené účetní případy. Zisk jedné faktury se při účtování nezapočítává proti ztrátě jiné faktury. Dobropis se posuzuje spolu s původním dokladem, pokud je doložena jejich vazba a stejná protistrana. Přecenění zachovává středisko, projekt i úplné dimenze zdrojového zápisu. Při pokračování z minulého období se odečte pouze prokazatelně přenesené přecenění. Program kontroluje uložený rozpis, účetní zápis, případné storno a návaznost uzavření a otevření knih. Pokud podklady nesouhlasí nebo chybí, přecenění zastaví s konkrétním důvodem; uzavřené knihy automaticky nemění.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Kurzové rozdíly byly zaúčtovány** a krok je potvrzený.

Pokud se v nastavení nevytváří storno přecenění na začátku nového roku, následující přecenění dopočítá jen
rozdíl proti již zaúčtované účetní hodnotě; předchozí kurzový rozdíl se proto neúčtuje podruhé.

U cizoměnových pohledávek a závazků se otevřená částka určuje v měně dokladu z doložených úhrad a zúčtovaných záloh. Bankovní párování a samostatná evidence téže platby se nezapočítávají dvakrát. Rozporné vazby, storno platby s ponechanou vazbou nebo nedoložitelné rozdělení zálohy vyžadují opravu evidence před přeceněním. Samotný historický údaj o zaplacení se použije jen bez konkrétní evidence úhrad; pokud evidence existuje, má přednost a rozdíl je uveden jako upozornění.

### 72.4.4 Kroky 4 a 5: Dohadné položky a časové rozlišení

Oba kroky fungují stejně. Jde o asistenta pro ruční zaúčtování rozvahových položek k rozvahovému dni.

1. Ve formuláři zvolte **Kontaci**:
   - u dohadných položek **Dohadná položka aktivní (388)** nebo **Dohadná položka pasivní (389)**,
   - u časového rozlišení **Náklady příštích období (381)**, **Výdaje příštích období (383)**, **Výnosy příštích období (384)**, **Příjmy příštích období (385)** nebo **Časové rozlišení drobného majetku (381/501)**.
2. Vyplňte kladnou **Částku**, volitelně **Protiúčet** (z účtové osnovy) a povinný **Popis**.
3. Klikněte na **Zaúčtovat zápis**. Vytvořené zápisy se zobrazují v seznamu **Vytvořené zápisy** (číslo dokladu, popis, částka).
4. Dokud je uzávěrka ve stavu Uzavírá se, můžete každý zápis vzít zpět tlačítkem **Stornovat** (vytvoří se zrcadlový protizápis).
5. Krok potvrďte tlačítkem **Potvrdit krok**, nebo **Přeskočit**.

**Automatické návrhy** jsou oddělené od zaúčtování, zápis vždy vytváří účetní po kontrole:

- u **dohadných položek** tlačítko **Navrhnout dohady** načte opakující se měsíční náklady (energie, nájem, telco, cloud), pro které do rozvahového dne nedorazila obvyklá faktura. Aplikace předvyplní dodavatele, poslední doklad, odhad částky a případný protiúčet. Tlačítko **Předvyplnit** je převezme do formuláře,
- u **nákladů příštích období** se nabídnou řádky přijatých faktur s vyplněným obdobím plnění přesahujícím rozvahový den. Aplikace vypočte část připadající na další období a po potvrzení ji zaúčtuje na 381 proti původnímu nákladovému účtu. Vychází z účetního nákladu položky: základ a daň, na kterou nemá firma nárok na odpočet. Neplátce tak odkládá i budoucí část DPH, při poměrném odpočtu se počítá jen neodpočitatelná část. Otevření dalšího roku odklad rozpustí ze stejné částky, ze které ho uzávěrka zaúčtovala,
- u **výnosů příštích období** se nabídnou řádky vydaných faktur s vyplněným obdobím výnosu přesahujícím rozvahový den, jejichž zápis leží v uzavíraném roce. Aplikace vypočte poměrně podle dnů část připadající na další období a po potvrzení ji zaúčtuje na vrub výnosového účtu, na který se faktura zaúčtovala, a ve prospěch 384. Dobropis se stejným obdobím odklad sníží. Otevření dalšího roku odloženou část rozpustí zpět do výnosů (víceleté plnění po ročních tranších),
- pro **drobný majetek** zvolíte politiku **Bez rozlišení**, **Poměrně dle data pořízení** (poměr podle budoucí, nespotřebované doby užitku), nebo **Paušální %** (pevné procento). Náhled porovná cenu karet s rozpisem nákladů 501 a po potvrzení vytvoří časové rozlišení na 381. Politika se ukládá per období (ne per firma).

Během roku, ještě před uzávěrkou, ukáže stejný odklad nákladů a výnosů příštích období výsledovka a rozvaha s volbou **Průběžné časové rozlišení** ([§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni)). Počítá se stejným pravidlem jako tento krok, ale jen ve výkazu, do deníku nic nezapisuje. Po zaúčtování kroku výkaz k rozvahovému dni doklady s už zaúčtovaným odkladem neupravuje.

**Jak poznáte, že je hotovo:** U kroku je datum potvrzení a zápisy jsou v seznamu vytvořených zápisů.

> [!WARNING]
> Režim **Poměrně dle data pořízení** odkládá na 381 **budoucí (dosud nespotřebovanou) část** ceny, tedy tu
> část doloženého intervalu užitku, která leží za rozvahovým dnem. Interval se zjednodušeně bere jako okno
> o délce účetního období počínající dnem pořízení; uplynulá část do rozvahového dne je náklad tohoto roku,
> zbytek se odloží. Pořízení na konci roku proto odloží téměř celou cenu, pořízení na začátku roku téměř nic
> (formule: cena × zbývající dny za rozvahovým dnem / počet dnů období). Jde o zjednodušený předpoklad
> rovnoměrného ročního užitku, ne o zákonný výpočet: na 381 patří jen prokazatelná budoucí část (§ 7 zákona
> o účetnictví, věrný a poctivý obraz). Použijte jej až po ověření podle doložené doby plnění. Rozpuštění se
> navíc provede celé v následujícím roce, takže víceleté plnění vyžaduje ruční harmonogram.

**Drobný majetek se neodpisuje.** V roce pořízení jde celý do nákladů (501, § 26 odst. 2 písm. a) zákona
o daních z příjmů). Jeho rozprostření na 381 je volitelná účetní politika (§ 7 zákona o účetnictví), ne zákonná
povinnost, proto nikdy natvrdo 50 %. Hranice 80 000 Kč je daňový limit hmotného majetku, ne účetní hranice
časového rozlišení. Pevné procento se počítá z čistého obratu účtu 501 „drobný majetek" (po odečtení dobropisů),
ne z evidence karet. Paušál vyžaduje zdokumentovaný **limit významnosti** (báze 501) a není nástrojem na volné
vyhlazení výsledku: musí odpovídat obhajitelné, konzistentně uplatňované vnitřní politice pro homogenní
nevýznamný soubor. Jinak odložte jen prokazatelnou budoucí část podle druhu výdaje a doložené doby.

Opakované spuštění řízeného časového rozlišení aktualizuje příslušný uzávěrkový zápis místo založení
duplicity. Náhled však neumí poznat, zda smlouva skutečně pokračuje, zda je plnění dodáno ani zda se na
případ vztahuje zásada nevýznamnosti. Účetní musí ověřit období plnění, částku, zvolenou metodu a uložit
smlouvu, fakturu nebo výpočet jako průkazný podklad.

> [!TIP]
> Evidenční podklad pro přiznání daně z příjmů právnických osob (rozdíl daňových a účetních odpisů,
> zůstatkové ceny vyřazeného majetku s klasifikací daňové uznatelnosti, zůstatky 388/389 a 563/663) najdete
> v samostatné sestavě úprav základu daně.

### 72.4.5 Krok 6: Opravné položky k pohledávkám

Volitelný krok pro tvorbu opravných položek k pohledávkám po splatnosti (zásada opatrnosti, § 25 odst. 3
zákona o účetnictví).

1. Klikněte na **Načíst pohledávky**. Z aging saldokonta účtu 311 k rozvahovému dni se sestaví tabulka otevřených pohledávek s dny a měsíci po splatnosti (**Měs. po spl.**) a se sloupcem **Návrh**.
2. U každé pohledávky zadejte skutečnou částku **Zákonná OP (558)** (daňově uznatelná) a/nebo **Účetní OP (559)** (nad rámec zákona, daňově neúčinná). Zadáváte **požadovaný konečný stav**, nikoli částku nové tvorby.
3. Ve sloupci **Paragraf ZoR** u každé zákonné OP vyberte ustanovení, podle kterého se uplatňuje: **§ 8** (pohledávky za dlužníky v insolvenčním řízení), **§ 8a** (nepromlčené pohledávky), **§ 8b** (ručení za celní dluh) nebo **§ 8c** (drobné pohledávky). Volba je předvyplněná návrhem aplikace, ale rozhoduje účetní.
4. Klikněte na **Zaúčtovat opravné položky**, u rozsáhlého seznamu na **Zaúčtovat položky stránky**.
5. Potvrďte krok, nebo ho přeskočte.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Opravné položky byly zaúčtovány**.

Návrh aplikace pouze **nabízí**:

- **§ 8c ZoR**: drobné pohledávky **do 30 000 Kč** nad **12 měsíců** po splatnosti, 100 %,
- **§ 8a ZoR**: nad **18 měsíců** 50 %, nad **30 měsíců** 100 %.

Aplikace přenese dosavadní OP a zaúčtuje pouze změnu: navýšení **MD 558/559 / D 391**, snížení opačně. Při nezměněném
stavu nový účetní zápis nevzniká. Opakovaný běh upravuje pouze pohyb v právě uzavíraném roce; zápisy uzavřených
let zachovává.

Pohledávky se zobrazují po 100 položkách. Součty v tabulce patří k zobrazené stránce; limit § 8c se přesto
vyhodnocuje ze všech otevřených pohledávek za daným dlužníkem. **Zaúčtovat položky stránky** mění pouze
zobrazené položky, OP z jiných stránek zachovává. Nulová částka rozpustí přenesenou OP v aktuálním roce,
případně odstraní její tvorbu v témže roce. Historické zápisy nemaže. Před přechodem na jinou stránku změny
zaúčtujte, nebo je zahoďte opětovným načtením. Vrácení celého kroku ruší pohyby aktuálního roku ze všech stránek.

Paragraf ZoR je jediný podklad pro rozpad **tabulky C přílohy č. 1 II. oddílu** přiznání DPPO (§ 8 řádky 3/4,
§ 8a řádky 6/7, § 8b řádky 8/9, § 8c řádky 10/11). Z hlavní knihy paragraf odvodit nejde, kontace 558/391 je
pro všechny stejná. Bez vyplněného paragrafu přiznání rozpad nevygeneruje a upozorní na to varováním.

Účet **559** je v účtové osnově označen jako daňově neuznatelný, takže se účetní OP automaticky promítne do úprav
základu daně (DPPO); zákonná OP na **558** zůstává daňově uznatelná. Pohledávka, která může být promlčená, je
označena varováním (ověřte uznání dluhu nebo stavění lhůty).

### 72.4.6 Krok 7: Daň z příjmů

Volitelný krok pro zaúčtování **předpisu splatné daně z příjmů** k rozvahovému dni (**MD 591 / D 341**).

1. V panelu zkontrolujte přednabídnutou částku **Návrh z DPPO přiznání** (z finalizovaného přiznání DPPO téhož roku, pokud existuje) a zůstatky účtů 341 (zaplacené zálohy) a 591. Odkaz **Report úprav základu daně (podklad)** vede na sestavu jako podklad.
2. Není-li finalizované přiznání, aplikace daň dopočte z účetnictví a upozorní, ať ji před zaúčtováním ověříte. Případně částku zadejte ručně.
3. Zadejte kladnou **Splatnou daň** a klikněte na **Zaúčtovat daň**. Zápis dostane číslo z řady UZ.
4. Potvrďte krok.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Splatná daň byla zaúčtována**.

Částku vždy potvrdí účetní. Zaúčtování je idempotentní (opakování přepíše týž zápis, nevznikají duplicity), krok lze
přeskočit a administrátor ho může vzít zpět. U fyzické osoby se daň z příjmů na 591/341 neúčtuje; průvodce krok
označí jako nepoužitelný a vyžaduje jeho vědomé přeskočení.

> [!TIP]
> Kroky Opravné položky a Daň z příjmů musí být před uzavřením knih dokončené nebo vědomě přeskočené, protože
> ovlivňují výsledek hospodaření.

### 72.4.7 Krok 8: Zásoby

Krok je aktivní jen pro firmu se zapnutým skladem vedeným v podvojném účetnictví; ostatním firmám se
přeskočí sám (jinak by prázdný krok blokoval uzavření knih). Rozhoduje způsob účtování zásob:

- **Způsob A**: pořízení zásob se účtuje průběžně na majetkové účty zásob (111/112, 131/132, 121/123…) a spotřeba nebo prodej se z nich odepisuje během roku. K rozvahovému dni už zůstatky zásob na účtech sedí a žádná uzávěrková reklasifikace není potřeba.
- **Způsob B**: pořízení jde rovnou do spotřeby (501/504), účty zásob jsou během roku nulové. Teprve k rozvahovému dni se podle skladové evidence zaúčtuje konečný stav zásob a v novém roce se zrcadlově rozpustí zpět do spotřeby.

Tento krok automatizuje **výhradně způsob B** (ČÚS 015). U firmy účtující způsobem A se stav zásob vede průběžně
a krok nemá co reklasifikovat.

1. Krok je dostupný až po zahájení uzávěrky. Zobrazí konečný stav zásob k rozvahovému dni po druzích (**Materiál (112 / 501)**, **Zboží (132 / 504)**, **Výrobky (123 / 583)**), inventurní manka (549) a přebytky (648), již zaúčtované zápisy a **Podklady k ověření**.
2. Klikněte na **Zaúčtovat / přepočítat zásoby**. Zápisy se vytvoří nebo přepočtou.
3. Potvrďte krok.
4. Potřebujete-li krok vrátit, klikněte na **Zrušit uzávěrku zásob** (s oprávněním k uzavírání období).

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Uzávěrka zásob byla zaúčtována**, nebo u firmy bez skladu
**Krok zásob byl přeskočen**.

Backend podle skladové evidence k rozvahovému dni připraví samostatné idempotentní zápisy pro konečný stav
materiálu **112/501**, zboží **132/504** a výrobků **123/583**, reklasifikaci inventurních mank na **549** a inventurní
přebytky na **648**. Při otevření dalšího roku se konečný stav zrcadlově rozpustí. Výpočet vychází ze skladových
dokladů a inventur, ale účetní musí před spuštěním doložit fyzickou inventuru, ocenění, neidentifikované doklady
a posouzení mank a přebytků.

## 72.5 Krok za krokem: uzavření knih a otevření nového roku

### 72.5.1 Krok 9: Uzavření knih

1. Ověřte, že je období ve stavu Uzavírá se, kontroly v kroku 1 nemají chybu a všechny předchozí kroky jsou potvrzené nebo přeskočené.
2. Jako administrátor klikněte na **Uzavřít knihy** a potvrďte dialog **Uzavření účetních knih**.
3. Jsou-li v období nezaúčtované aktivní doklady, aplikace uzavření zablokuje. Uzavřít přes ně lze jen s doloženým důvodem (**Důvod override**, tlačítko **Uzavřít přes nezaúčtované**). Výjimka se zapíše do auditní stopy.

Zaúčtuje se uzávěrkový zápis:

- **výsledkové účty (5xx/6xx)** se uzavřou přes účet **710 - Účet zisků a ztrát**,
- **rozvahové účty** se uzavřou přes **702 - Konečný účet rozvažný**,
- vypočte se **výsledek hospodaření** a období přejde do stavu **Uzavřené**.

Doklad dostane číslo z řady UZ (viz [§ 72.8](#728-krok-za-krokem-ciselne-rady-a-nastaveni-uzaverky)).

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Účetní knihy byly uzavřeny**. Panel zobrazí **Výsledek
hospodaření**, **Doklad** a odkaz **Zobrazit uzávěrkové zápisy v deníku**.

Dokud závěrka není schválená, administrátor může krok zrušit tlačítkem **Vzít zpět uzavření knih** (uzávěrkové zápisy
se smažou, opět s auditní stopou).

### 72.5.2 Krok 10: Otevření nového roku

1. Jako administrátor klikněte na **Otevřít nový rok** (aktivní až po dokončení kroku Uzavření knih) a potvrďte dialog **Otevření účetních knih**.
2. Zaúčtuje se otevírací zápis k 1. dni následujícího období (to se založí automaticky, pokud ještě neexistuje) přes účet **701 - Počáteční účet rozvažný** a výsledek hospodaření se převede na účet **431**. Doklad dostane číslo z řady OT.
3. Je-li v nastavení uzávěrky zapnutá volba **Storno přecenění saldokonta k 1. dni nového období** (viz [§ 72.8](#728-krok-za-krokem-ciselne-rady-a-nastaveni-uzaverky)), zaúčtuje se zároveň zrcadlové storno kurzových rozdílů z kroku 3 (řada KR). Panel pak hlásí, že bylo zaúčtováno storno přecenění saldokonta k 1. dni období.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Nový rok byl otevřen**.

Zůstatky rozvahových účtů se přenášejí včetně dimenzí, středisek a projektů. Zachovají se kombinace více
dimenzí i částky procentních rozpadů po haléřích. Přenáší se také nenulová salda jednotlivých hodnot,
i když je celý účet v součtu nulový. Následující uzávěrka vychází z počátečních stavů tohoto roku,
stejně jako účetní sestavy.

Administrátor může krok vrátit tlačítkem **Vzít zpět otevření roku**.

#### Převzaté počáteční stavy

Následující rok může mít otevírací zápis už dřív, než rok otevřete v průvodci, typicky po převodu dat z jiného
účetního systému nebo po ručním zadání otevírací rozvahy při zahájení účetnictví. Krok proto nejdřív zjistí,
jestli v následujícím období nějaký zaúčtovaný otevírací zápis leží, a panel **Počáteční stavy dalšího roku**
ukáže jeden ze stavů:

<!-- cols: 22 38 40 -->
| Stav | Význam | Co krok udělá |
|---|---|---|
| **K založení** | další rok otevírací zápis nemá | zaúčtuje vypočtený otevírací zápis |
| **Převzato** | převzatý zápis souhlasí s konečnými stavy | tlačítko **Převzít počáteční stavy** označí krok jako hotový a nic nezaúčtuje |
| **Rozdíl** | převzatý zápis nesouhlasí | otevření roku je zablokované, panel zobrazí tabulku rozdílů (**Vypočteno**, **Převzato**, **Rozdíl**) |
| **Nelze porovnat** | konečné stavy nejdou spočítat | spusťte kontroly v kroku 1 |

Porovnává se účet po účtu: konečné stavy rozvahových účtů a výsledek hospodaření na účtu 431 (tak, jak by je krok
zaúčtoval) proti součtu převzatých otevíracích zápisů. Rozvažné účty 70x se vynechávají a účet 431 se srovnává
za syntetiku, takže nevadí, když převzaté stavy vedou výsledek hospodaření na analytice. Kontroluje se také
členění po dimenzích, střediscích a projektech. Shodná celková částka účtu bez odpovídajícího členění
nestačí k automatickému převzetí. Dokud nejsou uzavřené knihy, je porovnání jen předběžné.

Při rozdílu máte dvě cesty. Buď rozdíl dohledáte a opravíte (v uzavíraném roce nebo v převzatém zápisu), nebo
použijete **Nahradit převzaté stavy vypočtenými**. Náhrada vyžaduje **povinný důvod** (**Důvod náhrady**), převzaté
otevírací zápisy smaže (jejich obsah se uloží do auditní události spolu s důvodem a tabulkou rozdílů) a zaúčtuje
vypočtený otevírací zápis z řady OT. Potvrdíte ji tlačítkem **Nahradit a otevřít rok**.

> [!WARNING]
> **Vzít zpět otevření roku** převzatý zápis nesmaže, protože patří k dalšímu roku, ne k uzávěrce. Smažou se jen
> zápisy, které krok sám zaúčtoval (storno přecenění, rozpuštění časového rozlišení, počáteční stav zásob).
> Převzatý zápis pak nebrání ani vzetí zpět uzavření knih, ani znovuotevření období.

## 72.6 Krok za krokem: schválení závěrky a znovuotevření období

Jakmile je krok Uzavření knih hotový, období je ve stavu **Uzavřené** a je možné kroky ještě revidovat (revert)
nebo období znovuotevřít.

**Schválení závěrky** (administrátor):

1. Otevřete `Nástroje → Uzávěrka` a u období ve stavu Uzavřené klikněte na **Schválit závěrku**.
2. V dialogu **Schválení účetní závěrky** potvrďte zaškrtnutím, že rozumíte nevratnosti. Volitelně doložte schvalující orgán nebo osobu, odkaz na rozhodnutí o schválení závěrky a hash dokumentu závěrky (údaje se uchovávají a už se nikdy nemažou).
3. Potvrďte.

**Jak poznáte, že je hotovo:** Období má štítek **Schválené** a ikonu zámku. U období ve stavu Schválené je odkaz na
stránku uzávěrky pojmenován **Uzávěrka / rozdělení**.

**Znovuotevření uzavřeného období** (administrátor):

1. U období ve stavu Uzavřené klikněte na **Znovuotevřít**.
2. Vyplňte **Důvod** (nejméně 10 znaků) a potvrďte.
3. Pokud období má zaúčtované uzávěrkové nebo otevírací zápisy, aplikace znovuotevření odmítne. Nejdřív v průvodci vezměte zpět kroky **Otevření nového roku** a **Uzavření knih**.

**Jak poznáte, že je hotovo:** Období je ve stavu **Otevřené**.

> [!WARNING]
> Zákonné schválení je podle § 17 odst. 7 zákona o účetnictví nevratné. Schválenou závěrku nelze znovuotevřít a
> schválení nelze zrušit přechodem stavu. Pokus o to aplikace odmítne. Zjistíte-li po schválení chybu, opravte ji
> v období, kdy jste ji zjistili (§ 35 zákona o účetnictví), nikoli zrušením schválení. Znovuotevření uzavřeného
> období je zásah do uzavřeného účetnictví a vždy se zaznamená do auditní stopy spolu s uvedeným důvodem.

## 72.7 Krok za krokem: rozdělení výsledku hospodaření

V období vedeném podle vyhlášky 504 otevřete **Rozhodnutí o výsledku hospodaření**. Zvolte datum uvnitř cílového období, načtěte podklady a vyplňte označení rozhodnutí a oprávněný orgán. Každou zobrazenou kombinaci dimenzí vypořádejte do nevypořádaného výsledku 932, fondů 911 nebo vlastního jmění 901. Zisky a ztráty se posuzují odděleně; krytí ztráty fondem nebo vlastním jměním vyžaduje dostatečný zůstatek stejné kombinace. Pro krytí ztráty použijte skutečný účet fondu nebo vlastního jmění. Je-li ve stejné kombinaci více analytických účtů, vyberte konkrétní zdroj; zůstatky jiných účtů se na jeho krytí nepoužijí. Příděly musí souhlasit na haléř. Při změně podkladů načtěte nový náhled. Zrušení je dostupné v otevřeném nebo uzavíraném cílovém období bez navazujících pohybů.

Následující kroky popisují rozdělení podle rámce 500.

Po schválení závěrky (a po převodu výsledku hospodaření na účet 431 otevíracím zápisem) je na stránce uzávěrky
dostupná karta **Rozdělení výsledku hospodaření**. Zápis se účtuje do otevřeného období (nikdy do uzavřeného,
431 se řeší až v novém roce), proto je karta dostupná nad schváleným obdobím. Při otevření z následujícího období
aplikace stejně ověří, že bezprostředně předchozí závěrka je schválená.

1. Otevřete stránku uzávěrky schváleného období a klikněte na **Rozdělit výsledek hospodaření**. Zobrazí se disponibilní zůstatek účtu 431 (**K rozdělení**, případně **Ztráta k úhradě**) a **Cílové období**.
2. Pro každý příděl klikněte na **Přidat příděl** a zadejte **Účet**, **Druh** a **Částku**. Druhy jsou **Nerozdělený zisk (428)**, **Příděl do fondu** (například 421/427), **Podíly společníků (364)** a **Úhrada ztráty (429/428)**. Každý řádek druhu Podíly společníků představuje jednoho společníka; **srážková daň** podle § 36 zákona o daních z příjmů (**Sazba srážkové daně (§36)**, výchozí 15 %) se počítá a zaokrouhluje dolů samostatně za každého a účtuje se **MD 364 / D 342**.
3. Zadejte **Datum rozhodnutí valné hromady** (musí ležet v otevřeném období).
4. Sledujte **Součet přídělů** a **Zbývá k rozdělení**. Musí vyjít na nulu.
5. Klikněte na **Zaúčtovat rozdělení VH**.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Rozdělení výsledku hospodaření bylo zaúčtováno** a stránka uvede,
že je rozdělení pro období již zaúčtované.

Zisk se účtuje **MD 431 / D {428, fond, 364}**, úhrada ztráty **MD {429, 428} / D 431**. Součet přídělů musí přesně
odpovídat zůstatku 431 (jinak zápis skončí chybou o nesouladu rozdělení). Podíly na zisku navíc nesmí překročit
**Limit rozdělitelných zdrojů** z účtů 431 a 428 po odečtení neuhrazené ztráty na 429. Doklad dostane číslo z řady
ID. Rozdělení je idempotentní a administrátor ho může vzít zpět.

## 72.8 Krok za krokem: číselné řady a nastavení uzávěrky

**Číselné řady:**

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Číselné řady** (jen podvojné účetnictví; v daňové evidenci je tato záložka také, pro pokladní doklady).
2. V tabulce řad per účetní rok upravte **Prefix** (1 až 10 znaků A až Z a 0 až 9, uloží se velkými písmeny), **Tvar čísla** a **Další číslo**. Sloupec **Náhled** rovnou ukazuje, jak bude příští číslo vypadat.
3. Uložte.

Tabulka drží řady dokladů per účetní rok:

<!-- cols: 56 20 24 -->
| Řada | Kód | Výchozí prefix |
|---|---|---|
| Uzávěrkové zápisy | `closing` | UZ |
| Otevírací zápisy | `opening` | OT |
| Kurzové rozdíly | `fx` | KR |
| Převody mezi účty | `transfer` | PP |
| Ruční zápisy | `manual` | ID |
| Příjmové / výdajové pokladní doklady | `cash_in` / `cash_out` | PPD / VPD |
| Skladové příjemky / výdejky / převodky | `stock_in` / `stock_out` / `stock_transfer` | PRI / VYD / PRE |
| Zápočty | `offset` | ZAP |
| Objednávky dodavatelům | `purchase_order` | OBJ |
| Ostatní pohledávky / závazky | `other_receivable` / `other_payable` | OP / OZ |

Příjmové a výdajové pokladní doklady vydávají čísla do jedné evidence, stejně jako ostatní pohledávky
a závazky (řady `other_receivable` / `other_payable`, prefixy OP / OZ). Řady téže skupiny proto nesmí mít
stejný prefix (v kterémkoli roce) ani výchozí prefix druhé řady, dokud ta pro daný rok nevydala číslo
a nebyla upravena, jinak by vydaly totéž číslo.

Pokladna a druh ostatní položky mohou mít vlastní řadu vedle společné řady firmy; v tabulce je označená
názvem pokladny nebo druhu ([§ 32.11.1](32_Pokladna.md#32111-ciselnik-pokladen),
[§ 52.14.14.11](52_Ucetni_denik.md#52141411-druhy-a-ciselne-rady)).

Řádek řady pro daný rok vzniká automaticky při prvním vydání čísla (dokud rok nemá žádný doklad dané řady,
v tabulce se nezobrazuje; zobrazí se **zatím nevydáno**). Čísla se vydávají vzestupně a mezery po smazaných nebo
stornovaných zápisech se nikdy nedorovnávají (§ 11 zákona o účetnictví, jedinečné označení dokladu).

**Tvar čísla** je nepovinná šablona; prázdné pole znamená vestavěné `PREFIX-YYYY-CCCC` (například `UZ-2026-0001`).
Placeholdery se píší do složených závorek: `PREFIX` prefix řady, `YYYY` rok čtyřmístně, `YY` rok dvoumístně a `C+`
čítač, kde počet písmen C určuje odsazení nulami.

**Další číslo** je číslo, které dostane příští vydaný doklad té řady. Slouží hlavně při přechodu z jiného systému:
firma, které v roce 2026 skončila vlastní pokladní řada na `26HP00010`, nastaví u řady `cash_in` prefix `26HP`,
tvar čísla `PREFIX` a `CCCCC` ve složených závorkách a další číslo `11`. První doklad vystavený v MyÚčtu pak bude
`26HP00011` a řada zůstane spojitá. Čítač lze i snížit; jedinečnost čísla ale hlídá databáze, takže kolize
s už existujícím dokladem skončí chybou uložení, ne tichým duplikátem.

**Nastavení uzávěrky a výkazů:**

Příloha k závěrce a uzávěrkový balíček respektují účetní rámec zvoleného období. Příloha podle vyhlášky 504/2002 Sb. obsahuje sekce podle § 29 a § 30, včetně dotací, fondů, darů, veřejných sbírek a použití daňových úlev. Zkrácený rozsah vynechává pouze údaje uvedené v § 30 odst. 7. Údaje, které se jednotky netýkají, označte výslovně v textu. Vyplnění sekce potvrzuje přítomnost textu, nikoli jeho věcnou správnost. Převzaté loňské texty před uložením zkontrolujte; vlastní text se nepřepisuje. Balíček 504 sestavuje výkazy k poslednímu dni účetního období, PDF v celých tisících Kč a pracovní XLSX v Kč. Nedostupné přehledy podle vyhlášky 500 nejsou v nabídce aktivní.

1. Na stránce Účetní období klikněte na **Nastavení uzávěrky** (okno **Nastavení uzávěrky a výkazů**).
2. Upravte firemní výchozí hodnoty a uložte.

- **Podléhá povinnému auditu (§ 20 zákona o účetnictví)**: auditovaná jednotka pak sestavuje výkazy vždy v plném rozsahu (§ 3a vyhlášky 500/2002 Sb.).
- **Automatická čísla dokladů ručních zápisů (řada ID)**: když ruční zápis v deníku nemá vyplněné číslo dokladu, přidělí se mu automaticky číslo z řady ID.
- **Storno přecenění saldokonta k 1. dni nového období**: řídí, zda krok Otevření nového roku zaúčtuje i zrcadlové storno kurzových rozdílů (viz [§ 72.5.2](#7252-krok-10-otevreni-noveho-roku)).
- **Časové rozlišení drobného majetku**: výchozí politika žádné, poměrně nebo paušál; nové účetní období ji při založení převezme. Konkrétní uzávěrka pak ukládá a používá snapshot politiky svého období, takže změna firemního defaultu nepřepíše starší roky.
- **Daně vůči finančnímu úřadu vykazovat v rozvaze souhrnně**: přeplatek jedné daně se v rozvaze započte s nedoplatkem jiné (§ 58 odst. 2 vyhlášky 500/2002 Sb.), volitelně **od účetního období**; viz [§ 57.7.2.1](57_Rozvaha.md#57721-souhrnne-vykazani-dani-vuci-financnimu-uradu).
- **Minulé období výkazů převzít z uzavřeného výkazu minulého roku**: sloupec minulého období se sestaví s výjimkami mapování a volbami platnými v minulém roce; bez volby podle pravidel běžného roku. Viz [§ 57.7.1](57_Rozvaha.md#5771-obdobi-den-a-verze).
- **Průměrný přepočtený počet zaměstnanců**: jde do přílohy K II. oddílu přiznání DPPO; aplikace úvazky nedopočítává, doplňte ručně.

> [!TIP]
> Řada PP (Převody mezi účty) se využívá i mimo uzávěrkový průvodce. Na obrazovce ručního zápisu
> (`Účetnictví → Účetní deník → Ruční zápis`) je tlačítko **Převod mezi účty (261)**, které jedním formulářem
> (částka, účet odeslání a přijetí, datum odeslání a přijetí) zaúčtuje dvě nohy převodu přes účet 261 (Peníze
> na cestě). Obě sdílejí společné číslo dokladu z řady PP.

## 72.9 Krok za krokem: uzávěrkový balíček

Uzávěrkový balíček je výběrový ZIP sestav za jedno období.

1. Otevřete `Nástroje → Uzávěrka` a u období klikněte na **Uzávěrkový balíček** (vyžaduje oprávnění k exportu sestav).
2. V části **Co zahrnout do balíčku** vyberte sestavy (**Vybrat vše**, **Zrušit výběr**). K dispozici jsou rozvaha, výsledovka, hlavní kniha, obratová předvaha, deník, kniha DPH, přiznání k dani z příjmů (XML), PDF sestava přiznání, přehled záloh na daň z příjmů právnických osob, inventura dlouhodobého majetku, saldo starší než jeden rok a soupis dohadných položek a časového rozlišení. Náhled nejprve ukáže, které části mají data.
3. Volitelně zaškrtněte **Přiložit i XLSX** (kromě Knihy DPH, přiznání k dani a záloh, ty jsou jen PDF/XML). Výstup je standardně PDF.
4. Případně doplňte přílohu k závěrce tlačítkem **Vyplnit přílohu** (vyplněné sekce se do balíčku promítnou při dalším vygenerování).
5. Klikněte na **Připravit balíček**.
6. Sledujte frontu, aktuální krok a počet hotových a neúspěšných částí. Běžící úlohu můžete zrušit (**Zrušit**). Po dokončení ZIP stáhněte.

**Jak poznáte, že je hotovo:** Stránka hlásí **Balíček je připravený ke stažení** a v části **Poslední balíčky**
je ZIP ke stažení. Hotovou či selhanou úlohu lze z historie smazat, čímž se odstraní i výsledný ZIP.

Aktivní může být jen jedna úloha firmy. Worker požadavek na zrušení kontroluje mezi sestavami. Náhled i worker
vždy znovu načítají období aktuální firmy; stažení kontroluje firmu a stav úlohy, znalost cizího ID úlohy nestačí.

**PDF sestava přiznání** je pracovní přehled přiznání k DPPO (u fyzické osoby k DPFO) za rok období pro kontrolu
s účetní a do archivu. Není podáním; částky čte ze stejného XML, jaké se stahuje pro EPO. Když v balíčku není
zvolené samotné přiznání (XML), přibalí se XML vedle sestavy. Pokud přiznání za rok sestavit nejde, balíček
sestavu vynechá, uvede důvod mezi upozorněními v README a ostatní sestavy vytvoří.

> [!WARNING]
> Balíček pouze zabalí sestavy, které aplikace umí z aktuálních dat vytvořit. Neříká, že byly schváleny, podány
> nebo doloženy, a neobsahuje automaticky externí smlouvy, inventurní zápisy, bankovní potvrzení ani jiné
> podklady. Přiznání k dani je jen vygenerovaná sestava (XML nese vlastní varování); skutečné podání dokládá
> až importovaný podaný soubor, ne balíček (viz kontrola K9 v [§ 72.12.5](#72125-kontrolni-mapa-k1-az-k10-a-jeji-interpretace)).
> Případné chybějící nebo přeskočené sestavy jsou vypsané jako upozornění v README balíčku a v logu úlohy.
> Na rozdíl od archivu účetnictví nejde o úplnou technickou zálohu ani prostředek obnovy firmy.

## 72.10 Krok za krokem: měsíční kontrola

Praktické pořadí kontrol, inventarizaci rozvahových účtů a společnou interpretaci K1 až K10 shrnuje kapitola
[Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md). Uzávěrkové kontroly z [kroku 1](#7241-krok-1-predbezne-kontroly)
nemusíte čekat až na konec roku. Stránka **Měsíční kontrola** je spustí kdykoli během roku, nad libovolným
rozsahem uvnitř otevřeného účetního období, a bez zahájení uzávěrky (stav období se nemění).

1. Otevřete `Účetnictví → Měsíční kontrola`.
2. Nahoře vyberte **účetní období** (jen otevřené nebo probíhající uzávěrka) a **rozsah**: konkrétní měsíc, kvartál nebo vlastní od-do. Rozsah musí ležet celý uvnitř vybraného období.
3. Klikněte na **Spustit kontrolu**. Po prvním načtení stránky se kontrola automaticky spustí za poslední dostupný měsíc.
4. Projděte řádky: **zelená fajfka** (v pořádku) nebo **červený křížek** s počtem nálezů. Řádek je proklikatelný: nezaúčtované doklady vedou do seznamu faktur, zůstatkové kontroly na výpis účtu za zvolený rozsah, majetek bez oprávek na jednotlivé karty a saldo 343 na konkrétní doklady s nesouladem.
5. Jako administrátor můžete měsíc zamknout tlačítkem **Uzamknout k datu**. Dialog nabídne datum (výchozí je konec právě kontrolovaného rozsahu) a vyžaduje povinné zdůvodnění.

**Jak poznáte, že je hotovo:** Všechny řádky jsou zelené (nebo nálezy máte vysvětlené) a měsíc je zamknutý k datu.

Typický postup: zkontrolovat měsíc, podat přiznání DPH a hned nato měsíc zamknout, aby se do něj náhodou
nezaúčtoval další doklad. Aktuální stav zámku vidíte nahoře na stránce (viz [zámek účtování k datu](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu)).

Kromě sady z kroku 1 (nezaúčtované doklady, koncepty, zůstatky 261/395/041/042/431, chybějící odpisy…) přibývají
kontroly zaměřené na **inventarizaci zůstatků**:

- **111/131**: nedočerpané zálohy na pořízení materiálu nebo zboží,
- **Platby kartou bez dokladu**: při zapnutém účtování karet přes mezičlen platby kartou bez dokladu starší než lhůta z nastavení; řádek vede do přehledu plateb bez dokladu na stránce Platební karty,
- **Účty na neobvyklé straně**: porovná zůstatek každého účtu s jeho obvyklou stranou dle typu (aktivum = MD, pasivum = D) a upozorní na výjimky, typicky přeplatek (311 v kreditu) nebo záporný závazek (321 v debetu),
- **Majetek bez oprávek**: karty majetku v užívání, jejichž majetkový účet (0xx) má nenulový zůstatek, ale odpovídající účet oprávek (07x/08x) je stále nulový,
- **Saldo 343 vs. přiznání DPH**: porovná zaúčtovaný obrat účtu 343 s daní vypočtenou z přiznání za shodné zdaňovací období (viz [Křížová kontrola § 41.13.5](41_Vykazy_DPH.md#41135-krizova-kontrola-s-kh-sh-a-uctem-343)); dává smysl jen když je zvolený rozsah přesně jeden kalendářní měsíc nebo kvartál, jinak se zobrazí jen informativní poznámka,
- **DUZP proti příloze** (varování): doklady, u kterých AI vyčetla z přílohy DUZP v jiném měsíci, než je DUZP dokladu, a doklad vstupuje do DPH. Doklad se ukáže v kontrole obou dotčených měsíců. **Rozdíly proti příloze** (informativně) hlásí rozdílnou částku, IČO, VS nebo den DUZP. Potvrzené rozdíly se nehlásí; podrobnosti v kapitole [Připojení skenů k dokladům](35_Pripojeni_skenu.md#355-krok-za-krokem-vyresit-rozpory-dokladu-s-prilohami).

> [!TIP]
> Měsíční kontrola je čistě informativní. Nic nezaúčtovává, nemění stav období ani neukládá žádný krok
> průvodce. Kontrola v kroku 1 uzávěrky zůstává samostatná a nezávislá (obsahuje navíc i kontroly vázané na celé
> účetní období: kontinuitu výsledku hospodaření, vyrovnanost deníku).

## 72.11 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Zaúčtování dokladu se odmítne | Datum dokladu spadá do období Uzavíraného, Uzavřeného nebo Schváleného (§ 35 zákona o účetnictví) | Zaúčtujte do otevřeného období, nebo období řízeně znovuotevřete ([§ 72.6](#726-krok-za-krokem-schvaleni-zaverky-a-znovuotevreni-obdobi)). |
| **Použijte průvodce uzávěrkou** | Stav Uzavírá se a Uzavřené se nemění přímo | Použijte tlačítka **Zahájit uzávěrku**, **Přerušit uzávěrku** a krok Uzavření knih. |
| **Chyby brání uzavření knih** | Kontroly v kroku 1 mají chybu | Opravte chybu a kontroly spusťte znovu. |
| Výsledky kontrol jsou zastaralé | Od uložení se změnila data (typicky uzavření předchozího období) | Spusťte kontroly znovu. |
| Uzavřít knihy nejde | Některý krok není potvrzený ani přeskočený, kontroly hlásí chybu, nebo období není ve stavu Uzavírá se | Dokončete nebo přeskočte předchozí kroky, nebo zahajte uzávěrku. |
| Nezaúčtované doklady blokují uzavření | V období jsou nezaúčtované aktivní doklady | Zaúčtujte je, nebo uzavřete přes ně s doloženým důvodem. |
| Krok je dostupný až po zahájení uzávěrky | Období je ještě Otevřené | Klikněte na **Zahájit uzávěrku**. |
| Znovuotevření odmítnuto, existují uzávěrkové zápisy | Zaúčtovaná uzavření a otevírací zápisy | Nejdřív vezměte zpět kroky **Otevření nového roku** a **Uzavření knih**. |
| Schválenou závěrku nelze zrušit ani změnit přechodem stavu | Zákonné schválení je nevratné (§ 17 odst. 7) | Opravte chybu v období zjištění (§ 35). |
| Otevření roku zablokované, panel ukazuje **Rozdíl** | Převzatý otevírací zápis nesouhlasí s konečnými stavy | Rozdíl dohledejte a opravte, nebo použijte **Nahradit převzaté stavy vypočtenými**. |
| Odpisy nejde zaúčtovat z Majetku, hlásí neotevřené období | Období je ve stavu Uzavírá se | Použijte **Zaúčtovat odpisy roku** v panelu kroku. |
| Změna nebyla provedena, aplikace načetla aktuální stav | Mezitím záznam upravil jiný uživatel | Zkontrolujte aktuální stav a akci zopakujte. |
| Zápis uzávěrkového kroku chybí v auditu | Auditní událost vzniká až po potvrzení transakce | Ověřte stav kroku a deník, změnu neopakujte naslepo. |

## 72.12 Podrobnosti a pravidla

### 72.12.1 Stavy období

Uzávěrka je vícekrokový proces s auditní stopou navázaný na § 17 odst. 7, § 35 a § 31 a 32 zákona o účetnictví
(neměnnost uzavřených knih, průkaznost, archivace). Každé období prochází stavovým automatem, zobrazeným jako
barevný štítek:

<!-- cols: 24 76 -->
| Stav | Význam |
|---|---|
| **Otevřené** | Běžný provoz, do období lze účtovat, uzávěrka ještě nezačala. |
| **Uzavírá se** | Probíhá uzávěrkový průvodce ([§ 72.4](#724-krok-za-krokem-uzaverka-roku-pruvodce)). |
| **Uzavřené** | Knihy jsou uzavřené (krok Uzavření knih proběhl), ale závěrka ještě není schválená, kroky lze vzít zpět. |
| **Zkontrolované** | Vratná interní kontrola závěrky před zákonným schválením, běžný pracovní stav (například odsouhlasení hlavní účetní). Lze ji s uvedením důvodu kdykoli zrušit a vrátit období na Uzavřené. Nejde o zákonné schválení. |
| **Schválené** | Nevratné zákonné schválení účetní závěrky (§ 17 odst. 7 zákona o účetnictví); řádek má ikonu zámku. Uchovává datum schválení, schvalující orgán nebo osobu, odkaz na rozhodnutí o schválení a hash dokumentu. Schválení už nelze zrušit ani přepsat přechodem stavu; případné opravy se řeší v období zjištění (§ 35). |

Přechody otevřené, uzavírá se a uzavřené provádí výhradně uzávěrkový průvodce (tlačítka Zahájit a Přerušit
uzávěrku a krok Uzavření knih). Přímá změna stavu na ně skončí chybou „Použijte průvodce uzávěrkou". Ve sloupci
**Akce** se proto u období, které ještě není schválené, zobrazuje odkaz **Uzávěrka** vedoucí do průvodce.

Interně zkontrolované období má uzavřené knihy. Umožňuje otevření dalšího roku a zahájení jeho uzávěrky; zámky
dokladů i daňové kontroly jej považují za uzavřené.

Stavový automat podporuje tyto samostatné administrátorské přechody:

- **Zahájit interní kontrolu** (Uzavřené na Zkontrolované): označí závěrku jako procházející interní kontrolou. Jde o vratný pracovní stav, žádná zákonná data nevznikají.
- **Zrušit interní kontrolu** (Zkontrolované na Uzavřené): vyžaduje **důvod** (nejméně 10 znaků). Ruší pouze interní kontrolu, žádná data zákonného schválení se přitom nemažou (protože ve stavu Zkontrolované ještě žádná neexistují).
- **Schválit závěrku** (Uzavřené nebo Zkontrolované na Schválené): potvrzovací dialog upozorní, že schválení je nevratné a po něm už knihy nepůjde znovu otevřít; vyžaduje potvrzení zaškrtnutím.
- **Znovuotevřít** (Uzavřené na Otevřené): vyžaduje důvod (nejméně 10 znaků). Má-li období zaúčtované uzávěrkové nebo otevírací zápisy, aplikace znovuotevření odmítne (viz [§ 72.5.2](#7252-krok-10-otevreni-noveho-roku)).

Stránka Účetní období nabízí u uzavřeného období tlačítka **Schválit závěrku** a **Znovuotevřít**. Přechody
interní kontroly (Zahájit a Zrušit interní kontrolu) a schválení ze stavu Zkontrolované podporuje stavový automat
na serveru, samostatná tlačítka pro ně ale stránka nemá.

Všechny tyto přechody nesou interní verzi záznamu (kontrola souběžné editace). Pokud období mezitím upravil jiný
uživatel, aplikace to ohlásí a znovu načte aktuální stav místo provedení akce.

> [!WARNING]
> Ze stavu Schválené nevede žádný přechod stavu zpět. Zákonné schválení je podle § 17 odst. 7 definitivní;
> chyby zjištěné po schválení se opravují v období zjištění (§ 35), ne zrušením schválení. Potřebujete-li jen
> vratné interní odsouhlasení, použijte stav Zkontrolované. V uživatelském rozhraní stránky Účetní období se
> přesto u schváleného období zobrazuje tlačítko **Zrušit schválení**; aplikace jeho provedení odmítne.

### 72.12.2 Automaticky doplněná období

Chybějící období si aplikace doplní sama, takže na ně nenarazíte uprostřed práce:

- při **zaúčtování dokladu**, jehož datum do žádného období nespadá (typicky zapomenutý přelom roku, nebo naimportovaná historie z jiného účetního programu),
- při **importu dokladů**, jednou za dávku pro celý rozsah jejích dat,
- při **založení firmy** v průvodci prvním spuštěním.

Takové období vzniká jako **Otevřené** a v tabulce má u účetního roku štítek **automaticky**; najetím myší uvidíte,
která cesta ho založila. Období, která založíte ručně, štítek nemají.

Hranice se dědí z existující řady, a když řada chybí, z nastavení firmy **Účetní rok začíná**, takže firmě s hospodářským rokem nevznikne kalendářní období. Aplikace nikdy
nemění existující období: spadá-li datum dokladu do období Uzavíraného, Uzavřeného nebo Schváleného,
zaúčtování se odmítne (§ 35 zákona o účetnictví). Automaticky se nezakládá první období firmy, která zatím žádné
nemá; jeho hranice a počáteční rozvahu je potřeba rozhodnout v průvodci aktivací účetnictví.

### 72.12.3 Zámek účtování k datu

Kromě uzavření celého účetního období existuje i jemnější **zámek účtování k datu**, nezávislý na stránce Účetní
období a platný napříč všemi otevřenými obdobími firmy. Posune se po označení validního DPH/KH snapshotu jako
odeslaného v EPO podání a archívu, které uživatel provede po kontrole nahrané doručenky. Zámek brání zaúčtování,
přeúčtování i stornu dokladů starších než zamčené datum, i když je období samo pořád Otevřené. Podrobně viz
[Účetní deník, zámek účtování k datu](52_Ucetni_denik.md).

### 72.12.4 Pořadí kroků uzávěrky

Uzávěrkový průvodce má v rámci 500 deset kroků v pevném, závazném pořadí. V rámci 504 navíc obsahuje krok Odložená daň podle výslovně zvolené vlastní metody (viz tabulka v [§ 72.4](#724-krok-za-krokem-uzaverka-roku-pruvodce)).
Každý krok musí být před uzavřením knih buď dokončený, nebo vědomě přeskočený. Krok Zásoby je podmíněný: jen firma
se skladem účtovaným způsobem B, jinak se automaticky přeskočí. Kroky Opravné položky a Daň z příjmů musí být před
uzavřením knih dokončené nebo vědomě přeskočené, protože ovlivňují výsledek hospodaření.

### 72.12.5 Kontrolní mapa K1 až K10 a její interpretace

K1 až K10 je společná mapa kontrol rozprostřených mezi import dokladu, bankovní párování, účetní sestavy,
měsíční kontrolu a uzávěrku. Ne každé K se proto na stránce Měsíční kontrola zobrazuje jako jediný řádek.
Výsledek **Chyba** může blokovat zaúčtování nebo uzavření knih; **Varování** a **Info** vyžadují vysvětlení, ale
samy o sobě nemusí znamenat nesprávné účetnictví.

<!-- cols: 24 34 42 -->
| Kontrola | Co aplikace porovnává | Jak nález interpretovat a řešit |
|---|---|---|
| **K1 - technické a clearingové účty** | Nenulové zůstatky 041/042, 111/131, 261, 314/324, 395 a souvisejících účtů. | Otevřete opis účtu a na záložce [Otevřené položky - párování](55_Hlavni_kniha.md#55851-otevrene-polozky-a-parovani) přiřaďte zůstatek ke konkrétnímu případu. Nenulový zůstatek může být oprávněný; účetní jej musí doložit nebo doúčtovat, ne mechanicky vynulovat. Analytika prodaných poukazů a kreditu (výchozí 324.900, viz [Účtování a DPH poukazů](121_EET.md)) se do zůstatku 324 nepočítá, kontrola ji jen ukáže; záporný zůstatek analytiky je chyba kontroly **Záporný zůstatek prodaných poukazů a kreditu**. |
| **K2 - neobvyklá strana** | Zůstatek účtu proti jeho běžné straně, včetně záporné pokladny. | Může jít o přeplatek, dobropis či jiné legitimní saldo, ale také o obrácenou kontaci. Rozhoduje věcný podklad a opis účtu. |
| **K3 - úhrady a saldokonto** | Stav „uhrazeno" proti otevřenému saldu, spárované zálohy a finální doklady s nulou k úhradě. | Ověřte vazbu plateb, zálohových a finálních dokladů. Neměňte stav jen proto, aby kontrola zezelenala; opravte zdroj párování nebo chybějící účetní zápis. |
| **K4 - kurz ČNB** | Kurz dokladu proti referenčnímu kurzu ČNB a povolené odchylce. | Pevný kurz či smluvně doložený postup může odchylku vysvětlit. Bez podkladu opravte kurz na zdrojovém dokladu a poté řízeně přeúčtujte. |
| **K5 - položky proti hlavičce** | Součet řádků, DPH a částku hlavičky při zaúčtování. | Drobné zaokrouhlení aplikace vyrovná; rozdíl nad ochrannou toleranci **2 Kč** zaúčtování zablokuje. Opravte import nebo položky dokladu, nevytvářejte ruční dorovnání bez vysvětlení. |
| **K6 - měnová stopa** | U cizoměnových řádků přítomnost měny i původní cizoměnové částky. | Chybějící stopa znemožňuje průkazné přecenění. Opravte zdrojový doklad nebo doložte ruční postup; samotná CZK částka nestačí. |
| **K7 - bilanční rovnost** | Vyrovnanost deníku a rovnost aktiv a pasiv v rozvaze. | Jde o blokující strukturální chybu. Dohledává se nevyrovnaný zápis, chybné mapování účtu nebo nekonzistentní počáteční stav; účetní závěrku nelze schválit s nevysvětleným rozdílem. |
| **K8 - kolize variabilních symbolů** | Více možných dokladů se stejným VS napříč řadami nebo partnery. | Automatické párování není bezpečné. Vyberte správný doklad podle partnera, částky, měny a data a upravte číselnou řadu či pravidlo, pokud se kolize opakuje. |
| **K9 - rekonciliace přiznání** | Aplikací vypočtené hodnoty proti importovanému skutečně podanému XML. | Vygenerované přiznání není důkaz podání. Rok a typ musí souhlasit; každý rozdíl vysvětlete opravou dat, identifikací ruční úpravy nebo doložením podaného souboru. |
| **K10 - uzávěrková úplnost** | Nezaúčtované doklady a koncepty, opakující se náklady bez faktury, chybějící odpisy, rozlišení, opravné položky a další předuzávěrkové signály. | Jde převážně o návrhy a checklist. Aplikace může spočítat částku nebo předvyplnit zápis, ale účetní posuzuje existenci závazku, významnost, období, daňový režim a průkazný podklad. |

Kontroly vždy spouštějte znovu po opravě. Uložení komentáře nebo přeskočení kroku je záznam rozhodnutí, nikoli
potvrzení správnosti; k významným varováním uchovejte podklad a zdůvodnění podle vnitřní směrnice.

### 72.12.6 Omezení a tipy

- Celý modul (období, průvodce i uzávěrkový balíček) je dostupný jen firmám vedeným v **podvojném účetnictví**, u daňové evidence se nezobrazuje.
- **Administrátorská role** je nutná pro: interní kontrolu a její zrušení, zákonné schválení a znovuotevření období, revert libovolného kroku uzávěrky, uzavření knih a otevření nového roku. Zrušit zákonné schválení nelze vůbec (§ 17 odst. 7). Zahájení a přerušení uzávěrky, běh přípravných kroků a úpravu prefixů číselných řad zvládne i role **účetní** (právo zápisu). Balíček vyžaduje oprávnění k exportu sestav.
- Všechny změny období a kroků nesou interní verzi záznamu. Pokud mezi načtením a uložením zasáhne jiný uživatel, aplikace to ohlásí a znovu načte aktuální data místo provedení zastaralé akce.
- Při zahájení uzávěrky organizačně zastavte souběžné běžné účtování. Kontrola otevřenosti období při běžném zápisu není ve stejné databázové zámkové sekci jako přechod otevřené na uzavírá se, takže těsný souběh může propustit zápis do právě uzavíraného období. Po zahájení vždy znovu spusťte předkontroly.
- Auditní událost některých uzávěrkových kroků vzniká až po potvrzení účetní transakce. Při technickém výpadku proto ověřte stav kroku a deník i tehdy, když auditní stopa chybí; změnu neopakujte naslepo.
- Do kurzového přecenění nevkládejte stejný bankovní analytický účet vícekrát. Aplikace duplicitu neodmítne a mohla by stejný zůstatek přecenit opakovaně.
- Znovuotevření uzavřeného období je zablokované, dokud existují zaúčtované uzávěrkové nebo otevírací zápisy. Nejprve je nutné vzít zpět kroky Otevření nového roku a Uzavření knih v průvodci.
- Číselné řady se nikdy nedorovnávají po smazaných dokladech. Jedinečnost a souvislost číslování je vyžadována zákonem, ne estetika bez mezer.

### 72.12.7 Přechod mezi účetními rámci

Při přechodu z vyhlášky 500 na 504 použijte samostatný řízený převod v Účetnictví. Běžné otevření
účetních knih nemění účetní rámec. Řízený převod vychází z ověřené nativní uzávěrky 500 a uchovává
původní výkazy.

Výsledek převzatý na 931 se vypořádává akcí Rozhodnutí o výsledku v detailu převodu. Zdrojové výkazy
musí být schválené. Vyberte datum, uveďte příslušný orgán a referenci rozhodnutí a výslovně potvrďte
částky jednotlivých buněk. Nabízený postup převádí výsledek na nerozdělený výsledek minulých let
932. Účelové převody vyžadují příslušný samostatný podklad evidence. Nevypořádané buňky 931 brání
zahájení další uzávěrky, i pokud se jejich zisky a ztráty v součtu rovnají nule. Cizoměnové bankovní
zůstatky si zachovávají doložený původ pro následné kurzové přecenění.

## 72.13 Daňové posouzení činností v rámci 504

V průvodci uzávěrkou otevřete **Daňové posouzení činností**. Podklady vycházejí ze zaúčtovaných výsledkových řádků, jejich doloženého členění a společných alokací. Chybějící členění nebo neprůkaznou alokaci nejprve opravte v účetnictví.

Vyberte a doložte postavení poplatníka. U jednotlivých příjmů určete právní titul a rozhodné skutečnosti, u nákladů uznatelnou část a právní podklad. Samostatně vymezte druhy činností a případné cenové kohorty. U darů a dotací doložte financované náklady; dřívější čerpání se zohledňuje podle potvrzených historických podkladů. U částečně uznatelných nákladů doložte souhrnný překryv financování s uznatelnou částí. Stejnou neuznatelnou část nelze přidělit více zdrojům; kontrola zohledňuje také opravy nákladu.

Roční test zohledňuje také financování z daru, který se podle výsledku činnosti stane příjmem mimo předmět daně. Navazující činnosti se vyhodnocují podle vazeb financování. Kruhová závislost je přípustná jen při prokázání jediného konzistentního výsledku; více možných výsledků, chybějící podklady nebo více než deset podmíněných zdrojových kohort vyžaduje samostatné posouzení.

Automatická metodika pokrývá účetní období zcela uvnitř let 2024 až 2026. Pro jiné období použijte **Doložené ruční posouzení**. Uveďte právní podklad a jeho účinnost pro celé období, doložte postavení poplatníka a konečný režim každého řádku. U příjmů určete také způsob zdanění. U nákladů zadejte uznatelnou část před zvláštním vyloučením financovaných nákladů; aplikace průkazný překryv odečte právě jednou. Roční test činností se v této variantě neprovádí. Jde o uživatelem doložený podklad bez automatického ověření daňového režimu, což výslovně uvádějí i exporty. Kontroly účetních zdrojů, financování, volby osvobození darů a aktuálnosti potvrzení zůstávají povinné.

Spusťte náhled, zkontrolujte návaznost na účetní výsledek a potvrďte posouzení s důvodem. Potvrzení ukládá neměnnou revizi s uživatelem a otiskem podkladů. Změna podkladů vyžaduje nové posouzení. Uzavření knih vyžaduje aktuální potvrzenou revizi; staré nebo odvolané potvrzení nestačí.

Historii a podklady konkrétní revize lze stáhnout jako PDF, XLSX nebo strukturovaná data. Export označuje, zda revize stále odpovídá aktuálním podkladům. Přehled tvoří klasifikační most před obecnými úpravami základu daně. Nepočítá konečné DPPO, odpočty ani daň k podání; doložené úpravy mimo tento most zůstávají samostatným podkladem pro daňové přiznání.

Potvrzenou volbu osvobození daru nelze zpětně změnit ani jeho rozdělením do nových alokačních řádků. Části stejného původního daru musejí mít jednotný právní titul a volbu osvobození. Pokud z dřívějšího zdroje čerpá další potvrzené období, nejprve doloženě přehodnoťte navazující posouzení. Ochranu zachovává i nahrazení starého posouzení, při němž zdroj dostane jiný právní titul.

### 72.13.1 Zákonné opravné položky a rezervy

V kroku **Opravné položky** uzávěrky rámce 504 jsou samostatné formuláře pro zákonnou opravnou položku, tvorbu rezervy na opravu majetku a její čerpání. Rozhodnutí potvrzuje uživatel s právem provádět uzávěrku. Uživatel s právem číst účetnictví může prohlížet podklady a ověřovat náhledy. Zápis vyžaduje probíhající uzávěrku. Práce s konkrétním zdrojem majetku navíc vyžaduje oprávnění číst majetek; běžné podklady zákonných opravných položek toto právo nepotřebují.

U pohledávky zadejte **konečný stav zákonné opravné položky** po společných buňkách. Každá buňka zachovává současně činnost, druh činnosti, daňový režim i další členění zdroje. Můžete potvrdit doložené řádky pohledávky s poměrným vypořádáním úhrad nebo výslovně zdůvodnit nové rozdělení. Doplňte označení rozhodnutí, právní základ a doklad splnění podmínek včetně vazby na zdanitelnou činnost. Účtuje se pouze změna proti dosavadnímu stavu na 559/391. Nulový konečný stav opravnou položku rozpouští. Účetní opravná položka se v tomto rámci nenabízí.

Pro **tvorbu rezervy na opravu** vyhledejte kartu majetku, doložte jeho trvající užívání a vyberte podklad pořízení. U zařazení z karty se dohledá původní zápis. U doloženého přiřazení vyberte konkrétní účetní řádky a částku přiřazenou z každého z nich; jejich součet musí odpovídat pořizovací hodnotě včetně doložených technických zhodnocení. Rozpočet opravy je samostatná částka a může být vyšší než pořizovací hodnota. Režim zařazení z karty pokrývá původní pořizovací zápis bez technických zhodnocení. Při evidovaném a zaúčtovaném technickém zhodnocení použijte doložené přiřazení obou druhů řádků. Například zařazení 100 000 Kč a technické zhodnocení 90 000 Kč vyžadují přiřazení 190 000 Kč; rozpočet rezervy může být samostatně 150 000 Kč. Neúplné přiřazení aplikace odmítne.

Doplňte rozhodnutí, právní titul, popis a důkazy splnění zákonných podmínek. Po volbě **Ověřit a zobrazit náhled** zkontrolujte celé společné rozdělení. **Potvrdit zápis** vytvoří 556/941. Změna údajů zruší předchozí náhled; podklady se při potvrzení znovu ověřují.

Pro čerpání nebo rozpuštění vyberte původní doloženou rezervu a částky v jejích společných buňkách. Zápis 941/556 nesmí překročit dostupný zůstatek žádné buňky. Seznam zápisů aktuálního období umožňuje doložené storno; při navazujícím čerpání je nutné nejprve stornovat čerpání a až poté tvorbu.

### 72.13.2 Oprava zdroje rezervy majetku

Opravu zahajte u stejné karty majetku v uzávěrce a uveďte důvod i správné pořizovací podklady. Formulář rozlišuje dvě situace:

- **Oprava zdroje ve stejném období:** nejprve stornujte navazující čerpání a tvorbu úplnými protizápisy ve stejném období. Samotný opačný součet na účtu nestačí. Náhled musí potvrdit celý původní společný zápis a průkaznost nového zdroje. Po potvrzení opravy lze rezervu znovu vytvořit ze správného zdroje.
- **Oprava zdroje při uzavřené historii:** původní tvorba ani čerpání se nestornují. Doložte původní dokument s datem, označením a důkazem, že šlo od začátku o stejný majetek i účel opravy. V aktuálním období se převede pouze zbývající rezerva mezi společnými buňkami zápisem 941/941. Historie a její původní zdroje zůstávají chráněné.

Například při tvorbě **150 000 Kč** a čerpání **30 000 Kč** se při opravě uzavřené historie převádí **120 000 Kč**. Náhled ukáže převáděný zůstatek a obě strany společného rozdělení. Změněný zdroj, nesouhlasný účel, překročený zůstatek nebo zastaralý stav období potvrzení zablokují.

Majetek navázaný na doloženou rezervu nelze zpětně vyřadit způsobem, který by popřel jeho užívání v doloženém období. Obdobně nelze zrušením již neplatného zpětného vyřazení obcházet ochranu zdroje. Pozdější vyřazení a jeho zrušení jsou možné, pokud zachovají průkazný historický stav.

## 72.14 Odložená daň podle vlastní metody v rámci 504

Krok **Odložená daň** je ve výchozím stavu vypnutý. Jeho výslovná volba vyžaduje odkaz na schválené rozhodnutí, důvod věrného a poctivého obrazu, rozsah odchylky a důvod odděleného vykázání. Jde o vlastní účetní metodu podle § 7 odst. 2 a § 36 zákona o účetnictví, nikoli automatické posouzení splatné daně. Daňové posouzení činností zůstává samostatným podkladem.

Zadejte doložené tituly a celý požadovaný konečný přechodný rozdíl, sazbu a její právní podklad. U pohledávky určete prokazatelně využitelnou část a doložte pravděpodobnost uplatnění. Každý titul zachovává společné přiřazení dimenzí. Odebrání titulu rozpouští jeho dosavadní zůstatek; nový přírůstek potřebuje aktivní přiřazení. Samostatná volba prvního zavedení minulých rozdílů vyžaduje další důvod a používá účet 932.

Spusťte **Ověřit rozhodnutí a zobrazit zápisy**, zkontrolujte hrubý závazek, uznanou pohledávku, neuznanou část a navržené zápisy. Potvrzení účtuje přesně ověřené rozhodnutí nad stejnými podklady. Úprava rozhodnutí, změna verze období nebo podkladů vyžaduje nový náhled. Každé další období vyžaduje nové posouzení i při nulové změně částky. Příprava chybějících účtů 349, 592 a 959.100 vyžaduje zvláštní souhlas; existující nekompatibilní účty se nepřepisují.

Vrácením kroku nelze vymazat historii již potvrzeného posouzení ani zaúčtované zápisy. Změnu konečných rozdílů proveďte novým doloženým posouzením; historie předchozích rozhodnutí zůstává zachovaná i při nulovém zůstatku.

Závazek a pohledávka se vzájemně nezapočítávají. Rozvaha, výsledovka, PDF i XLSX zachovávají zákonné řádky a uvnitř nich oddělují běžnou a odloženou část. Příloha automaticky uvádí doloženou metodu, hrubé počáteční a konečné zůstatky, výsledkový dopad, neuznanou část a důkazy využitelnosti. Tato automatická sekce je pouze ke čtení. Při zobrazení v tisících se podpoložky dorovnají na zaokrouhlený rodičovský řádek.

## 72.15 Související kapitoly

- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md)
- [Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md)
- [Aktivace účetnictví](68_Aktivace_ucetnictvi.md)
- [Nástroje: číselné řady, kurzy a kompletní export](73_Ucetni_nastroje.md)
- [Rozvaha](57_Rozvaha.md)
- [Účetní deník](52_Ucetni_denik.md)
