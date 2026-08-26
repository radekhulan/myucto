# 87. Uzávěrka

**Cesta: `Nástroje → Uzávěrka`**

Kapitola popisuje modul **Účetní období** a **uzávěrkový průvodce** pro firmy vedené
v **podvojném účetnictví** (menu se zobrazuje jen firmám v tomto účetním režimu —
u daňové evidence se místo něj zobrazuje daňový deník příjmů a výdajů). Archiv
účetnictví je samostatně popsán v kapitole
[Nástroje](88_Ucetni_nastroje.md#886-obnovitelny-archiv-v-kompletnim-exportu).

> [!NOTE]
> Uzávěrka je vícekrokový proces s auditní stopou — vazba na §17 odst. 7, §35 a
> §31/§32 zákona o účetnictví (neměnnost uzavřených knih, průkaznost, archivace).
> Většinu kroků zvládne role **účetní**, administrátorská role je navíc potřeba pro
> schválení/znovuotevření období, uzavření knih, otevření nového roku, revert kroků
> a archivaci.

## 87.1 Účetní období — přehled a stavy

Stránka **Účetní období** zobrazuje tabulku všech hospodářských/kalendářních roků
firmy: **Účetní rok**, **Začátek**, **Konec**, **Stav** a sloupec **Akce**. Na mobilu
se stejné údaje zobrazí jako karty.

Tlačítkem **„Nové období"** (role s právem zápisu) otevřeš formulář se třemi poli:

- **Účetní rok** — celé číslo v rozumném rozsahu (2000–2200),
- **Začátek** a **Konec** — kalendářní data období (nemusí jít o kalendářní rok —
  podporované je i hospodářský rok).

Systém validuje, že:

- začátek je dřív než konec,
- pro daný rok ještě neexistuje jiné období firmy,
- období se **nepřekrývá** s žádným existujícím obdobím firmy (souvislá řada let bez
  mezer i překryvů je nutná pro navazující uzávěrky a výkazy).

### 87.1.1 Stavy období

Každé období prochází stavovým automatem s pěti stavy, zobrazenými jako barevný
štítek:

| Stav | Význam |
|---|---|
| **Otevřené** (`open`) | Běžný provoz — do období lze účtovat, uzávěrka ještě nezačala. |
| **Uzavírá se** (`closing`) | Probíhá uzávěrkový průvodce (viz [§ 87.2](#872-uzaverkovy-pruvodce-zahajeni-a-kroky)). |
| **Uzavřené** (`closed`) | Knihy jsou uzavřené (krok *Uzavření knih* proběhl), ale závěrka ještě není schválená — kroky lze vzít zpět. |
| **Zkontrolované** (`reviewed`) | **Vratná** interní kontrola / review závěrky před zákonným schválením — běžný pracovní stav (např. odsouhlasení hlavní účetní). Kontrolu lze s uvedením důvodu kdykoli zrušit a vrátit období na Uzavřené. **Nejde o zákonné schválení.** |
| **Schválené** (`approved`) | **Nevratné** zákonné schválení účetní závěrky (**§ 17 odst. 7 ZoÚ**); řádek má ikonu 🔒. Uchovává datum schválení, schvalující orgán/osobu, odkaz na rozhodnutí o schválení a hash dokumentu. Schválení už **nelze** zrušit ani přepsat přechodem stavu; případné opravy se řeší v období zjištění (§ 35 ZoÚ). |

Přechody **otevřené → uzavírá se** a **uzavírá se → uzavřené** provádí výhradně
uzávěrkový průvodce (tlačítka Zahájit/Přerušit uzávěrku a krok Uzavření knih) — přímá
změna stavu na ně skončí chybou „Použijte průvodce uzávěrkou". Ve sloupci Akce se
proto u období, které ještě není `approved`, zobrazuje odkaz **„Uzávěrka"** vedoucí do
průvodce.

Přímo ze seznamu (jen administrátor) lze provést tyto samostatné přechody:

- **Zahájit interní kontrolu** (`closed → reviewed`) — označí závěrku jako procházející
  interní kontrolou / review. Jde o **vratný** pracovní stav, žádná zákonná data
  nevznikají.
- **Zrušit interní kontrolu** (`reviewed → closed`) — vyžaduje **důvod** (min. 10 znaků);
  vrátí období zpět na Uzavřené. Ruší **pouze** interní kontrolu — žádná data zákonného
  schválení se přitom nemažou (protože ve stavu Zkontrolované ještě žádná neexistují).
- **Schválit závěrku** (`closed → approved` nebo `reviewed → approved`) — potvrzovací
  dialog s upozorněním, že schválení je **nevratné** a po něm už knihy nepůjde znovu
  otevřít; vyžaduje potvrzení zaškrtnutím. Volitelně lze doložit **schvalující orgán/osobu**,
  **odkaz na rozhodnutí** o schválení závěrky a **hash dokumentu** — tyto údaje se uchovávají
  a už se **nikdy nemažou**.
- **Znovuotevřít** (`closed → open`) — vyžaduje **důvod** (min. 10 znaků).
  Pokud období má zaúčtované uzávěrkové/otevírací zápisy, systém znovuotevření odmítne
  chybou „Existují uzávěrkové zápisy — nejprve vezměte zpět otevření roku a uzavření
  knih" — je nutné nejdřív v průvodci vzít zpět kroky *Otevření nového roku* a
  *Uzavření knih* (viz [§ 87.3](#873-uzavreni-knih-a-otevreni-noveho-roku)).

Všechny tyto přechody nesou interní verzi záznamu (kontrola souběžné editace) — pokud
období mezitím upravil jiný uživatel, systém to ohlásí a znovu načte aktuální stav
místo provedení akce.

> [!IMPORTANT]
> Ze stavu **Schválené** nevede žádný přechod stavu zpět — pokus o něj systém odmítne
> chybou „Schválenou účetní závěrku nelze zrušit ani změnit přechodem stavu"
> (`approval_is_final`). Zákonné schválení je podle **§ 17 odst. 7 ZoÚ** definitivní;
> chyby zjištěné po schválení se opravují v období zjištění (§ 35 ZoÚ), ne zrušením
> schválení. Potřebuješ-li jen vratné interní odsouhlasení, použij stav **Zkontrolované**.

> [!WARNING]
> Znovuotevření uzavřeného období je zásah do uzavřeného účetnictví a **vždy se
> zaznamená do auditní stopy** spolu s uvedeným důvodem. Zákonně **Schválenou** závěrku
> už znovuotevřít nelze — schválení je nevratné a nejde ani zrušit. Znovuotevřít lze jen
> období ve stavu Uzavřené (případně po zrušení pouhé interní kontroly). Opravy po
> zákonném schválení řeš podle § 35 ZoÚ v období zjištění.

> [!NOTE]
> Kromě uzavření **celého** účetního období existuje i jemnější **zámek účtování k
> datu** — nezávislý na této stránce, platí napříč všemi otevřenými obdobími firmy.
> Posune se po označení validního DPH/KH snapshotu jako odeslaného v **EPO podání
> a archívu**, které uživatel provede po kontrole nahrané doručenky. Zámek brání
> zaúčtování, přeúčtování i stornu
> dokladů starších než zamčené datum, i když je období samo pořád Otevřené.
> Podrobně viz [Účetní deník § 44.9 Zámek účtování k datu](45_Ucetni_denik.md).

## 87.2 Uzávěrkový průvodce — zahájení a kroky

Kliknutím na odkaz **„Uzávěrka"** u období otevřeš stránku **Uzávěrka období** —
levý sloupec s kroky, pravý panel s detailem aktuálně vybraného kroku. V
záhlaví je vidět rok, rozsah data období, štítek stavu a (jakmile je spočtený) i
výsledek hospodaření.

- **Zahájit uzávěrku** posune období ze stavu Otevřené do Uzavírá se — teprve pak se
  zpřístupní kroky, které vytvářejí nebo potvrzují uzávěrkové zápisy.
- **Přerušit uzávěrku** (jen ve stavu Uzavírá se) vrátí období zpět do Otevřené po
  potvrzení dialogu.

Uzávěrkový průvodce (backend `ClosingService`) má **deset kroků v pevném, závazném
pořadí** — každý krok musí být před uzavřením knih buď dokončený (`done`), nebo vědomě
přeskočený (`skipped`). Devět z nich má ovládání ve webovém průvodci, desátý
(**Zásoby**) je pouze backendový. V levém panelu se dokončený krok značí zelenou
fajfkou, přeskočený pomlčkou a popiskem „přeskočeno":

| # | Krok | Klíč | UI | Podmíněnost |
|---|---|---|---|---|
| 1 | Předběžné kontroly | `precheck` | ✅ | vždy |
| 2 | Odpisy majetku | `depreciation` | ✅ | potvrdit/přeskočit (firma bez majetku přeskočí) |
| 3 | Kurzové rozdíly | `fx_revaluation` | ✅ | jen jsou-li cizoměnové položky |
| 4 | Dohadné položky | `estimates` | ✅ | dle potřeby (potvrdit/přeskočit) |
| 5 | Časové rozlišení | `deferrals` | ✅ | dle potřeby (potvrdit/přeskočit) |
| 6 | Opravné položky | `provisions` | ✅ | volitelný |
| 7 | Daň z příjmů | `income_tax` | ✅ | volitelný (u fyzické osoby se přeskočí) |
| 8 | **Zásoby** | `stock` | ✗ | **podmíněný** — jen firma se skladem účtovaným způsobem B; jinak automaticky `skipped` (viz [§ 87.2.7](#8727-backendovy-krok-zasoby)) |
| 9 | Uzavření knih | `close_books` | ✅ | až po dokončení/přeskočení kroků 1–8 |
| 10 | Otevření nového roku | `open_next` | ✅ | až po Uzavření knih |

Ve webovém průvodci se zobrazuje **9 z 10** kroků — skladový krok (`stock`) je
jen backendový, UI jej **nezobrazuje ani neumí spustit**. Firma se zapnutým skladem
(způsob B) proto standardní cestou v UI uzávěrku nedokončí — je nutný správcovský
zásah přes podporované API, jinak uzávěrku neuzavírej. Firma
bez skladu je tímto omezením nedotčena (krok se sám označí `skipped`).

### 87.2.1 Krok 1 — Předběžné kontroly

Tlačítkem **„Spustit kontroly"** systém projede sadu kontrol nad účetnictvím období a
zobrazí je v tabulce se sloupci **Závažnost** (Chyba/Varování/Info), **Kontrola** a
**Hodnota**. Kontrolují se mimo jiné:

- zda je **předchozí období uzavřené**,
- **nezaúčtované koncepty** v deníku období a nevyrovnaný deník (Σ MD ≠ Σ Dal),
- **vydané a přijaté faktury** období bez účetního zápisu,
- nenulové zůstatky technických účtů: **261** (Peníze na cestě), **395** (Vnitřní
  zúčtování), **041/042** (nedokončené pořízení majetku),
- **nerozdělený výsledek hospodaření na 431** z minulých let,
- **majetek v užívání bez zaúčtovaných odpisů** roku,
- **cizoměnové otevřené doklady** čekající na přecenění,
- zůstatky **dohadných účtů 388/389** a **časového rozlišení 381–385**,
- informativní upozornění na **splatnou daň z příjmů** (591/341), kterou je nutné
  zaúčtovat ručně (viz i podklad DPPO zmíněný níže).

Pokud kontroly obsahují alespoň jednu **chybu**, zobrazí se u tlačítka červený štítek
„Chyby brání uzavření knih" a krok *Uzavření knih* zůstane zablokovaný, dokud
chyby neodstraníš a kontroly znovu nespustíš.

### 87.2.2 Krok 2 — Odpisy majetku

Panel jen odkazuje na modul **Majetek** (tlačítko „Přejít na Majetek — Zaúčtovat
odpisy"), kde se účetní odpisy roku skutečně zaúčtují. Samotný krok v uzávěrce se jen
**potvrzuje** — po zahájení uzávěrky vyplníš volitelnou poznámku a tlačítkem
**„Potvrdit krok"** (nebo „Přeskočit", pokud firma odpisy neeviduje) krok uzavřeš;
u dokončeného kroku se zobrazí datum potvrzení.

> [!NOTE]
> **Odpisy během uzávěrky.** Tlačítko „Zaúčtovat odpisy" na stránce Majetek funguje jen
> do stavu období Otevřené — jakmile období přejde do Uzavírá se, stejné tlačítko
> odpisy dál nezaúčtuje (hlásí neotevřené období). Backend umí zaúčtovat odpisy
> přímo pro tento krok i do období ve stavu Uzavírá se, ale vlastní tlačítko v panelu
> není. V běžném provozu proto odpisy zaúčtuj na Majetku ještě **před** zahájením
> uzávěrky, jak je popsáno v [Majetek § 59.6](78_Majetek.md).

### 87.2.3 Krok 3 — Kurzové rozdíly

Krok se zpřístupní až po zahájení uzávěrky. Zobrazí přecenění cizoměnových položek
kurzem ČNB k rozvahovému dni (§24 odst. 6+7 ZoÚ, ČÚS 006) ve dvou částech:

- **Saldokonto** — tabulka otevřených cizoměnových vydaných (FV) a přijatých (FP)
  faktur se zbývající částkou v cizí měně, kurzem dokladu, kurzem ČNB a vypočteným
  rozdílem (zeleně kladný, červeně záporný). Nad tabulkou je pro každou měnu vidět
  použitý kurz ČNB a jeho datum; pokud se ke dni nenašel platný kurz a použil se
  náhradní, zobrazí se u měny varovná ikona.
- **Devizové účty a valutové pokladny** — ručně doplňovaný seznam řádků (účet z
  osnovy, měna, zůstatek v cizí měně); tlačítkem **„+ Přidat řádek pokladny"** přidáš
  další řádek, křížkem řádek odebereš. Systém nabízí i **návrhy** zůstatků z
  posledních bankovních výpisů — účet osnovy je ale nutné doplnit ručně (výpis nese
  jen číslo účtu banky).

Tlačítko **„Přepočítat návrh"** přepočte náhled bez zaúčtování, **„Zaúčtovat
kurzové rozdíly"** (dostupné jen ve stavu Uzavírá se) vytvoří účetní zápis na účty
**563** (kurzová ztráta) a **663** (kurzový zisk) — jejich součty jsou vidět pod
tabulkami. Administrátor navíc může přecenění tlačítkem **„Zrušit přecenění"** vzít
zpět (smaže zaúčtovaný zápis).

Pokud se v nastavení nevytváří storno přecenění na začátku nového roku,
následující přecenění dopočítá jen rozdíl proti již zaúčtované účetní hodnotě;
předchozí kurzový rozdíl se proto neúčtuje podruhé.

### 87.2.4 Kroky 4–5 — Dohadné položky a časové rozlišení

Oba kroky fungují stejně — asistent pro ruční zaúčtování rozvahových položek k
rozvahovému dni:

- **Dohadné položky** — kontace **Dohadná položka aktivní (388)** nebo **Dohadná
  položka pasivní (389)**.
- **Časové rozlišení** — kontace **Náklady příštích období (381)**, **Výdaje
  příštích období (383)**, **Výnosy příštích období (384)** nebo **Příjmy příštích
  období (385)**.

Ve formuláři vybereš kontaci, vyplníš kladnou **částku**, volitelný **protiúčet**
(nabídka z účtové osnovy) a povinný **popis**, tlačítkem **„Zaúčtovat zápis"** se
vytvoří účetní zápis. Vytvořené zápisy se zobrazují v seznamu pod formulářem (číslo
dokladu, popis, částka) a dokud je uzávěrka ve stavu Uzavírá se, lze každý z nich
tlačítkem **„Stornovat"** vzít zpět (vytvoří se zrcadlový protizápis).

Jako u kroku odpisů, i tady krok samotný potvrdíš nebo přeskočíš tlačítky **„Potvrdit
krok"** / **„Přeskočit"** — dokončený krok zobrazí datum potvrzení.

V obou krocích jsou automatické návrhy oddělené od zaúčtování:

- u **dohadných položek** lze načíst návrhy opakujících se nákladů, pro které do
  rozvahového dne nedorazila obvyklá faktura; systém předvyplní dodavatele, poslední
  doklad, odhad částky a případný protiúčet, ale zápis vytvoří až účetní po kontrole,
- u **nákladů příštích období** se nabídnou řádky přijatých faktur s vyplněným
  obdobím plnění přesahujícím rozvahový den; systém vypočte část připadající na další
  období a po potvrzení ji zaúčtuje na 381 proti původnímu nákladovému účtu,
- pro **drobný majetek** lze zvolit politiku *bez rozlišení* (`none`), *poměr podle
  budoucí (nespotřebované) doby užitku* (`pro_rata`) nebo *pevné procento* (`flat_pct`);
  náhled porovná cenu karet s rozpisem nákladů 501 a po potvrzení vytvoří časové
  rozlišení na 381. Politika se ukládá **per období** (ne per firma).

> [!WARNING]
> Režim `pro_rata` odkládá na 381 **budoucí (dosud nespotřebovanou) část** ceny — tu
> část doloženého intervalu užitku, která leží **za rozvahovým dnem**. Interval se
> zjednodušeně bere jako okno o délce účetního období počínající dnem pořízení; uplynulá
> část do rozvahového dne je náklad tohoto roku, zbytek se odloží. Pořízení **na konci
> roku** proto odloží téměř celou cenu, pořízení **na začátku roku** téměř nic (formule:
> `cena × zbývající dny za rozvahovým dnem / počet dnů období`). Jde ale o
> **zjednodušený předpoklad** rovnoměrného ročního užitku, ne o zákonný výpočet: na 381
> patří jen **prokazatelná budoucí část** (§ 7 ZoÚ, věrný a poctivý obraz). Použij jej
> až po ověření podle doložené doby plnění. Rozpuštění se navíc provede celé v N+1, takže
> víceleté plnění vyžaduje ruční harmonogram.

> [!IMPORTANT]
> **Drobný majetek se neodpisuje** — v roce pořízení jde celý do nákladů (501, § 26
> odst. 2 písm. a) ZDP). Jeho rozprostření na 381 je **volitelná účetní politika**
> (§ 7 ZoÚ), ne zákonná povinnost — proto nikdy natvrdo 50 %. Hranice **80 000 Kč** je
> **daňový** limit hmotného majetku podle ZDP, ne účetní hranice časového rozlišení.
> Pevné procento (`flat_pct`) se počítá z **čistého obratu účtu 501** „drobný majetek"
> (net dobropisů), ne z evidence karet, a **není nástrojem na volné „vyhlazení"
> výsledku** — musí odpovídat obhajitelné, konzistentně uplatňované vnitřní politice pro
> homogenní nevýznamný soubor. Jinak odlož jen prokazatelnou budoucí část podle druhu
> výdaje a doložené doby.

Opakované spuštění řízeného časového rozlišení aktualizuje příslušný uzávěrkový zápis
místo založení duplicity. Náhled však neumí poznat, zda smlouva skutečně pokračuje,
zda je plnění dodáno ani zda se na případ vztahuje zásada nevýznamnosti. Účetní musí
ověřit období plnění, částku, zvolenou metodu a uložit smlouvu, fakturu nebo výpočet
jako průkazný podklad.

> [!TIP]
> Evidenční podklad pro přiznání daně z příjmů právnických osob (rozdíl daňových a
> účetních odpisů, zůstatkové ceny vyřazeného majetku s klasifikací daňové
> uznatelnosti, zůstatky 388/389 a 563/663) najdeš v samostatné sestavě API
> `/accounting/reports/tax-base-adjustments`.

### 87.2.5 Krok — Opravné položky k pohledávkám

Volitelný krok pro tvorbu opravných položek k pohledávkám po splatnosti (zásada
opatrnosti, § 25 odst. 3 zákona o účetnictví). Tlačítkem **„Načíst pohledávky"** se
z aging saldokonta účtu **311** k rozvahovému dni sestaví tabulka otevřených
pohledávek s dny/měsíci po splatnosti a s **návrhem zákonné opravné položky**:

- **§ 8c ZoR** — drobné pohledávky **do 30 000 Kč** nad **12 měsíců** po splatnosti → 100 %,
- **§ 8a ZoR** — nad **18 měsíců** → 50 %, nad **30 měsíců** → 100 %.

Návrh systém pouze **nabízí** — u každé pohledávky zadáš skutečnou částku **zákonné
OP (účet 558**, daňově uznatelná) a/nebo **účetní OP nad rámec zákona (účet 559**,
daňově neúčinná); nulová hodnota pohledávku z tvorby vynechá. Tlačítkem **„Zaúčtovat
opravné položky"** se per pohledávka vytvoří zápis **MD 558/559 / D 391**. Opakované
spuštění je idempotentní (přepíše zápis téže pohledávky), takže návrh můžeš postupně
ladit. Vazba zápisu na fakturu umožňuje pozdější rozpuštění OP (391/558, resp. 391/559)
při úhradě nebo odpisu pohledávky.

Účet **559** je v účtové osnově označen jako daňově neuznatelný, takže se účetní OP
automaticky promítne do úprav základu daně (DPPO); zákonná OP na **558** zůstává
daňově uznatelná. Krok lze **přeskočit** a administrátor ho může tlačítkem **„Vzít
krok zpět"** vrátit (zápisy OP se smažou s auditní stopou).

### 87.2.6 Krok — Daň z příjmů

Volitelný krok pro zaúčtování **předpisu splatné daně z příjmů** k rozvahovému dni
(**MD 591 / D 341**). Panel přednabídne částku z **finalizovaného přiznání DPPO**
téhož roku (pokud existuje) a zobrazí aktuální zůstatky účtů **341** (zaplacené
zálohy) a **591**; odkaz vede na sestavu úprav základu daně jako podklad. Částku vždy
**potvrdí účetní** — tlačítkem **„Zaúčtovat daň"** se zápis vytvoří s číslem z řady
**UZ**. Zaúčtování je idempotentní (opakování přepíše týž zápis, nevznikají
duplicity), krok lze přeskočit a administrátor ho může vzít zpět.

U fyzické osoby se daň z příjmů na 591/341 neúčtuje; průvodce krok označí jako
nepoužitelný a vyžaduje jeho vědomé přeskočení.

> [!NOTE]
> Kroky Opravné položky a Daň z příjmů musí být před uzavřením knih
> **dokončené nebo vědomě přeskočené**, protože ovlivňují výsledek hospodaření.

### 87.2.7 Backendový krok — Zásoby

**Podmíněnost.** Krok je aktivní jen pro firmu s **zapnutým skladem** vedeným
v podvojném účetnictví; ostatní firmy backend automaticky označí `skipped` (jinak by
prázdný krok blokoval uzavření knih). Rozhoduje **způsob účtování zásob**:

- **Způsob A** — pořízení zásob se účtuje průběžně na majetkové účty zásob (111/112,
  131/132, 121/123…) a spotřeba/prodej se z nich odepisuje během roku; k rozvahovému
  dni už zůstatky zásob na účtech sedí a **žádná uzávěrková reklasifikace není potřeba**.
- **Způsob B** — pořízení jde rovnou do spotřeby (501/504), účty zásob jsou během roku
  nulové; teprve **k rozvahovému dni** se podle skladové evidence zaúčtuje konečný stav
  zásob a v novém roce se zrcadlově rozpustí zpět do spotřeby.

Tento krok automatizuje **výhradně způsob B** (ČÚS 015). U firmy účtující způsobem A se
stav zásob vede průběžně a krok nemá co reklasifikovat.

Pro způsob B backend podle skladové evidence k rozvahovému dni připraví samostatné
idempotentní zápisy pro:

- konečný stav materiálu **112/501**, zboží **132/504** a výrobků **123/583**,
- reklasifikaci inventurních mank na **549**,
- inventurní přebytky na **648**.

Při otevření dalšího roku se konečný stav zrcadlově rozpustí. Výpočet vychází ze
skladových dokladů a inventur, ale účetní musí před spuštěním doložit fyzickou
inventuru, ocenění, neidentifikované doklady a posouzení mank a přebytků.

> [!WARNING]
> Tento krok nemá ovládání ve webovém průvodci. Je-li sklad zapnutý, backend jej
> vyžaduje před uzavřením knih, takže standardní UI cestou nelze uzávěrku dokončit.
> Firma bez skladu je tímto omezením nedotčena — krok se označí jako nepoužitelný.

## 87.3 Uzavření knih a otevření nového roku

### 87.3.1 Krok — Uzavření knih

Tlačítko **„Uzavřít knihy"** (jen administrátor) je aktivní teprve když je období ve
stavu Uzavírá se, kontroly z kroku 1 neobsahují chyby a backend potvrdí, že je
možné uzavřít (všechny požadované předchozí kroky jsou dokončené nebo vědomě
přeskočené). Po potvrzení v dialogu se zaúčtuje uzávěrkový zápis:

- **výsledkové účty (5xx/6xx)** se uzavřou přes účet **710 — Účet zisků a ztrát**,
- **rozvahové účty** se uzavřou přes **702 — Konečný účet rozvažný**,
- vypočte se **výsledek hospodaření** a období přejde do stavu **Uzavřené**.

Doklad dostane číslo z řady **UZ** (viz [§ 87.5](#875-ciselne-rady-dokladu-uzaverky)).
Po dokončení kroku panel zobrazí zjištěný **výsledek hospodaření**, **číslo dokladu**
a odkaz **„Zobrazit uzávěrkové zápisy v deníku"**. Dokud závěrka není schválená,
administrátor může krok tlačítkem **„Vzít zpět uzavření knih"** zrušit — uzávěrkové
zápisy se smažou (opět s auditní stopou).

### 87.3.2 Krok — Otevření nového roku

Tlačítko **„Otevřít nový rok"** (jen administrátor) je aktivní až po dokončení kroku
Uzavření knih. Zaúčtuje otevírací zápis k **1. dni následujícího období** (to se
založí automaticky, pokud ještě neexistuje) přes účet **701 — Počáteční účet
rozvažný** a převede výsledek hospodaření na účet **431**. Doklad dostane číslo z
řady **OT**.

Pokud je v nastavení uzávěrky zapnutá volba **„Storno přecenění saldokonta k 1. dni
nového období"** (viz [§ 87.5](#875-ciselne-rady-dokladu-uzaverky)), zaúčtuje se
zároveň zrcadlový storno zápis kurzových rozdílů z kroku 3 — doklad z řady **KR**; v
panelu se pak zobrazí hláška „Bylo zaúčtováno storno přecenění saldokonta k 1. dni
období." Administrátor může i tento krok tlačítkem **„Vzít zpět otevření roku"**
zrušit.

## 87.4 Interní kontrola, schválení závěrky a znovuotevření

Jakmile je krok *Uzavření knih* hotový, období je ve stavu **Uzavřené** a je možné
kroky ještě revidovat (revert) nebo období znovuotevřít (viz [§ 87.1](#871-ucetni-obdobi-prehled-a-stavy)).

Aplikace rozlišuje **dva různé** koncepty, které je nutné nezaměňovat:

- **Interní kontrola / review** (stav **Zkontrolované**, `reviewed`) — **vratný** pracovní
  stav před zákonným schválením. Slouží k internímu odsouhlasení závěrky (např. hlavní
  účetní před předložením ke schválení). Kontrolu lze tlačítkem **Zrušit interní kontrolu**
  s uvedením důvodu kdykoli vrátit zpět na Uzavřené. Je to běžná pracovní operace, ne
  právní událost.
- **Zákonné schválení** (stav **Schválené**, `approved`) — **nevratná** událost podle
  **§ 17 odst. 7 zákona o účetnictví**. Tlačítkem **Schválit závěrku** (potvrzení
  zaškrtnutím) se období přepne do stavu Schválené; volitelně lze doložit **orgán/osobu**,
  která závěrku schválila, **odkaz na rozhodnutí** o schválení a **hash dokumentu**
  závěrky. Tyto údaje se uchovávají a **už se nikdy nemažou**.

> [!IMPORTANT]
> Zákonné schválení **nelze zrušit ani přepsat** přechodem stavu — schválená závěrka je
> definitivní. Zjistíš-li po schválení chybu, oprav ji **v období, kdy jsi ji zjistil**
> (§ 35 ZoÚ), nikoli zrušením schválení. Pro pouhé interní odsouhlasení, které chceš mít
> možnost vzít zpět, použij vratný stav **Zkontrolované**, ne zákonné schválení.

### 87.4.1 Rozdělení výsledku hospodaření (431 → 428 / 429 / 364)

Po schválení závěrky (a po převodu VH na účet **431** otevíracím zápisem) je na
stránce uzávěrky dostupná karta **„Rozdělení výsledku hospodaření"**. Zápis se účtuje
do **otevřeného období** (nikdy do uzavřeného — 431 se řeší až v novém roce), proto je
karta dostupná nad schváleným obdobím; při otevření z následujícího období systém
stejně ověří, že bezprostředně předchozí závěrka je schválená.

Tlačítkem **„Rozdělit výsledek hospodaření"** se zobrazí disponibilní zůstatek účtu
**431** a formulář přídělů. Ke každému přídělu zadáš **cílový účet**, **druh** a
**částku**:

- **Nerozdělený zisk (428)**, resp. **úhrada ztráty (429)**,
- **příděl do fondu** (např. 421/427),
- **podíly společníků (364)** — každý řádek představuje jednoho společníka;
  **srážková daň** podle **§ 36 ZDP** (výchozí sazba **15 %**) se počítá a
  zaokrouhluje dolů samostatně za každého a účtuje se **MD 364 / D 342**.

Zisk se účtuje **MD 431 / D {428, fond, 364}**, úhrada ztráty **MD {429, 428} / D 431**.
Součet přídělů musí přesně odpovídat zůstatku 431 (jinak zápis skončí chybou
`distribution_mismatch`) — panel průběžně ukazuje součet a zbývající částku.
Podíly na zisku navíc nesmí překročit limit rozdělitelných zdrojů z účtů
431 a 428 po odečtení neuhrazené ztráty na 429. Zadáš **datum rozhodnutí valné
hromady** (musí ležet v otevřeném období) a tlačítkem
**„Zaúčtovat rozdělení VH"** se vytvoří doklad z řady **ID**. Rozdělení je idempotentní
a administrátor ho může vzít zpět.

## 87.5 Číselné řady dokladů uzávěrky

Číselné řady se spravují v **Nástrojích** na záložce **„Číselné řady"**
(`/utilities?section=document-series`, jen podvojné účetnictví). Tabulka drží řady
dokladů per účetní rok:

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

Řádek řady pro daný rok vzniká automaticky **při prvním vydání čísla** (dokud rok
nemá žádný doklad dané řady, v tabulce se nezobrazuje). Editovat lze **prefix**
(1–10 znaků A–Z/0–9, uloží se velkými písmeny), **tvar čísla** a **další číslo**;
čísla se vydávají vzestupně a mezery po smazaných/stornovaných zápisech se **nikdy
nedorovnávají** (§11 ZoÚ — jedinečné označení dokladu).

**Tvar čísla** je nepovinná šablona — prázdné pole znamená vestavěné
`PREFIX-YYYY-CCCC` (např. `UZ-2026-0001`). Placeholdery se píší do složených závorek:
`PREFIX` prefix řady, `YYYY` rok čtyřmístně, `YY` rok dvoumístně a `C+` čítač, kde
počet písmen C určuje odsazení nulami. Sloupec **Náhled** rovnou ukazuje, jak bude
příští číslo vypadat.

**Další číslo** je číslo, které dostane **příští** vydaný doklad té řady. Slouží
hlavně při přechodu z jiného systému: firma, které v roce 2026 skončila vlastní
pokladní řada na `26HP00010`, nastaví u řady `cash_in` prefix `26HP`, tvar čísla
`PREFIX` a `CCCCC` ve složených závorkách a další číslo `11` — první doklad
vystavený v MyÚčtu pak bude `26HP00011` a řada zůstane spojitá. Čítač lze i snížit;
jedinečnost čísla ale hlídá databáze, takže kolize s už existujícím dokladem skončí
chybou uložení, ne tichým duplikátem.

### 87.5.1 Nastavení uzávěrky a výkazů

Tlačítkem **„Nastavení uzávěrky"** na stránce Účetní období otevřeš firemní výchozí
hodnoty:

- **Podléhá povinnému auditu (§20 ZoÚ)** — auditovaná jednotka pak sestavuje výkazy
  vždy v plném rozsahu (§3a vyhl. 500/2002 Sb.).
- **Automatická čísla dokladů ručních zápisů (řada ID)** — když ruční zápis v deníku
  nemá vyplněné číslo dokladu, přidělí se mu automaticky číslo z řady ID.
- **Storno přecenění saldokonta k 1. dni nového období** — řídí, zda krok *Otevření
  nového roku* zaúčtuje i zrcadlové storno kurzových rozdílů (viz [§ 87.3](#873-uzavreni-knih-a-otevreni-noveho-roku)).
- **Časové rozlišení drobného majetku** — výchozí politika `none`, `pro_rata`
  nebo `flat_pct`; nové účetní období ji při založení převezme. Konkrétní
  uzávěrka pak ukládá a používá snapshot politiky svého období, takže změna
  firemního defaultu nepřepíše starší roky.

> [!NOTE]
> Řada **PP** (Převody mezi účty) se využívá i mimo uzávěrkový průvodce — na
> obrazovce ručního zápisu do deníku (menu **Účetnictví → Účetní deník → Ruční
> zápis**) je tlačítko **„Převod mezi účty (261)"**, které jedním formulářem (částka,
> účet odeslání/přijetí, datum odeslání/přijetí) zaúčtuje dvě nohy převodu přes
> účet **261 — Peníze na cestě** a obě sdílejí společné číslo dokladu z řady PP.

## 87.6 Uzávěrkový balíček

**Cesta: `Nástroje → Uzávěrka → Uzávěrkový balíček`**

Uzávěrkový balíček je výběrový ZIP sestav za jedno období. Náhled nejprve ukáže,
které části mají data; účetní může vybrat rozvahu, výsledovku, hlavní knihu, obratovou
předvahu, deník, knihu DPH, přiznání k dani z příjmů, přehled záloh na daň z příjmů
právnických osob, inventuru dlouhodobého majetku, saldo starší než jeden rok a soupis
dohadných položek a časového rozlišení. Výstup je standardně v PDF a volitelně
obsahuje i XLSX.

Vytvoření probíhá na pozadí. Stránka zobrazuje frontu, aktuální krok, počet
hotových a neúspěšných částí a po dokončení nabídne ZIP ke stažení. Aktivní může
být jen jedna úloha firmy. Běžící úlohu lze požádat o zrušení; worker požadavek
kontroluje mezi sestavami. Hotovou či selhanou úlohu lze z historie smazat,
čímž se odstraní i výsledný ZIP.

Náhled i worker vždy znovu načítají období aktuální firmy. Stažení kontroluje
tenantový scope a stav `completed`; znalost cizího ID úlohy nestačí. Vytvoření
vyžaduje `reports.export:write`.

> [!IMPORTANT]
> Balíček pouze zabalí sestavy, které systém umí z aktuálních dat vytvořit. Neříká,
> že byly schváleny, podány nebo doloženy, a neobsahuje automaticky externí smlouvy,
> inventurní zápisy, bankovní potvrzení ani jiné podklady. Přiznání k dani je jen
> **vygenerovaná** sestava (XML nese vlastní varování) — **skutečné podání dokládá až
> importovaný podaný soubor**, ne balíček (viz křížová kontrola **K9 — rekonciliace
> přiznání** v [§ 87.7.1](#8771-kontrolni-mapa-k1-k10-a-jeji-interpretace)). Případné
> chybějící/přeskočené sestavy jsou vypsané jako **upozornění** v README balíčku a
> v logu úlohy. Na rozdíl od Archivu účetnictví nejde o úplnou technickou zálohu ani
> prostředek obnovy firmy.

## 87.7 Měsíční kontrola

Praktické pořadí kontrol, inventarizaci rozvahových účtů a společnou interpretaci
K1–K10 shrnuje samostatná kapitola
[Účetní kontroly a inventarizace](79_Ucetni_kontroly_a_inventarizace.md).

Uzávěrkové kontroly z [kroku 1](#8721-krok-1-predbezne-kontroly) nemusíš čekat až na
konec roku — stránka **Účetnictví → Měsíční kontrola** spustí **stejné kontroly**
kdykoli během roku, nad libovolným rozsahem uvnitř otevřeného účetního období, a **bez
zahájení uzávěrky** (stav období se nemění). Hodí se jako běžná měsíční rutina: po
uzavření měsíce v hlavě si ověřit, že v účetnictví nic nechybí, dřív než totéž zjistíš
až za rok při skutečné uzávěrce.

**Filtr rozsahu.** Nahoře vybereš **účetní období** (jen otevřená/probíhající uzávěrka)
a **rozsah**: konkrétní **měsíc**, **kvartál** nebo **vlastní od-do** — rozsah musí ležet
celý uvnitř vybraného účetního období. Tlačítkem **„Spustit kontrolu"** se kontroly
přepočtou; po prvním načtení stránky se automaticky spustí za poslední dostupný měsíc.

**Kontroly.** Kromě sady z kroku 1 (nezaúčtované doklady, koncepty, zůstatky 261/395/
041/042/431, chybějící odpisy…) přibývají kontroly zaměřené na **inventarizaci
zůstatků**:

- **111/131** — nedočerpané zálohy na pořízení materiálu/zboží,
- **Účty na neobvyklé straně** — porovná zůstatek každého účtu s jeho **obvyklou
  stranou** dle typu (aktivum = MD, pasivum = D) a upozorní na výjimky — typicky
  **přeplatek** (311 v kreditu) nebo **záporný závazek** (321 v debetu),
- **Majetek bez oprávek** — karty majetku v užívání, jejichž majetkový účet (0xx) má
  nenulový zůstatek, ale odpovídající účet oprávek (07x/08x) je stále nulový,
- **Saldo 343 vs. přiznání DPH** — porovná zaúčtovaný obrat účtu **343** s daní
  vypočtenou z přiznání za **shodné zdaňovací období** (viz [Křížová kontrola §
  34](36_Vykazy_DPH.md)); dává smysl jen když je zvolený rozsah přesně jeden kalendářní
  měsíc nebo kvartál — jinak se zobrazí jen informativní poznámka.

Každý řádek má **zelenou fajfku** (v pořádku) nebo **červený křížek** s počtem nálezů a
je **proklikatelný**: nezaúčtované doklady vedou do seznamu faktur/PF, zůstatkové
kontroly na **výpis účtu** za zvolený rozsah, majetek bez oprávek na jednotlivé karty a
saldo 343 na konkrétní doklady s nesouladem.

**Zámek k datu.** Nahoře na stránce vidíš aktuální stav [zámku účtování k datu](45_Ucetni_denik.md#459-zamek-uctovani-k-datu)
(`locked_until`) a — jen jako **administrátor** — tlačítko **„Uzamknout k datu"**.
Dialog nabídne datum (výchozí = konec právě kontrolovaného rozsahu) a vyžaduje povinné
zdůvodnění; typický postup je zkontrolovat měsíc, podat přiznání DPH a hned nato měsíc
zamknout, aby se do něj náhodou nezaúčtoval další doklad.

> [!NOTE]
> Měsíční kontrola je **čistě informativní** — nic nezaúčtovává, nemění stav období ani
> neukládá žádný krok průvodce. Precheck v kroku 1 uzávěrky zůstává samostatný a
> nezávislý (obsahuje navíc i kontroly vázané na **celé** účetní období — kontinuitu
> výsledku hospodaření, vyrovnanost deníku).

### 87.7.1 Kontrolní mapa K1–K10 a její interpretace

K1–K10 je společná mapa kontrol rozprostřených mezi import dokladu, bankovní párování,
účetní sestavy, měsíční kontrolu a uzávěrku. Ne každé K se proto na stránce Měsíční
kontrola zobrazuje jako jediný řádek. Výsledek **Chyba** může blokovat zaúčtování nebo
uzavření knih; **Varování** a **Info** vyžadují vysvětlení, ale samy o sobě nemusí
znamenat nesprávné účetnictví.

| Kontrola | Co systém porovnává | Jak nález interpretovat a řešit |
|---|---|---|
| **K1 — technické a clearingové účty** | Nenulové zůstatky 041/042, 111/131, 261, 314/324, 395 a souvisejících účtů. | Otevři opis účtu a přiřaď zůstatek ke konkrétnímu případu. Nenulový zůstatek může být oprávněný; účetní jej musí doložit nebo doúčtovat, ne mechanicky vynulovat. |
| **K2 — neobvyklá strana** | Zůstatek účtu proti jeho běžné straně, včetně záporné pokladny. | Může jít o přeplatek, dobropis či jiné legitimní saldo, ale také o obrácenou kontaci. Rozhoduje věcný podklad a opis účtu. |
| **K3 — úhrady a saldokonto** | Stav „uhrazeno" proti otevřenému saldu, spárované zálohy a finální doklady s nulou k úhradě. | Ověř vazbu plateb, zálohových a finálních dokladů. Neměň stav jen proto, aby kontrola zezelenala; oprav zdroj párování nebo chybějící účetní zápis. |
| **K4 — kurz ČNB** | Kurz dokladu proti referenčnímu kurzu ČNB a povolené odchylce. | Pevný kurz či smluvně doložený postup může odchylku vysvětlit. Bez podkladu oprav kurz na zdrojovém dokladu a poté řízeně přeúčtuj. |
| **K5 — položky proti hlavičce** | Součet řádků, DPH a částku hlavičky při zaúčtování. | Drobné zaokrouhlení systém vyrovná; rozdíl nad ochrannou toleranci **2 Kč** zaúčtování zablokuje. Oprav import nebo položky dokladu, nevytvářej ruční dorovnání bez vysvětlení. |
| **K6 — měnová stopa** | U cizoměnových řádků přítomnost měny i původní cizoměnové částky. | Chybějící stopa znemožňuje průkazné přecenění. Oprav zdrojový doklad nebo dolož ruční postup; samotná CZK částka nestačí. |
| **K7 — bilanční rovnost** | Vyrovnanost deníku a rovnost aktiv a pasiv v rozvaze. | Jde o blokující strukturální chybu. Dohledává se nevyrovnaný zápis, chybné mapování účtu nebo nekonzistentní počáteční stav; účetní závěrku nelze schválit s nevysvětleným rozdílem. |
| **K8 — kolize variabilních symbolů** | Více možných dokladů se stejným VS napříč řadami nebo partnery. | Automatické párování není bezpečné. Vyber správný doklad podle partnera, částky, měny a data a uprav číselnou řadu či pravidlo, pokud se kolize opakuje. |
| **K9 — rekonciliace přiznání** | Systémem vypočtené hodnoty proti importovanému skutečně podanému XML. | Vygenerované přiznání není důkaz podání. Rok a typ musí souhlasit; každý rozdíl vysvětli opravou dat, identifikací ruční úpravy nebo doložením podaného souboru. |
| **K10 — uzávěrková úplnost** | Nezaúčtované doklady a koncepty, opakující se náklady bez faktury, chybějící odpisy, rozlišení, opravné položky a další předuzávěrkové signály. | Jde převážně o návrhy a checklist. Systém může spočítat částku nebo předvyplnit zápis, ale účetní posuzuje existenci závazku, významnost, období, daňový režim a průkazný podklad. |

Kontroly vždy spouštěj znovu po opravě. Uložení komentáře nebo přeskočení kroku je
záznam rozhodnutí, nikoli potvrzení správnosti; k významným varováním uchovej podklad
a zdůvodnění podle vnitřní směrnice.

## 87.8 Omezení a tipy

- Celý modul (období, průvodce i uzávěrkový balíček) je dostupný jen firmám vedeným v
  **podvojném účetnictví** — u daňové evidence se nezobrazuje.
- **Administrátorská role** je nutná pro: interní kontrolu a její zrušení, zákonné
  schválení a znovuotevření období, revert libovolného kroku uzávěrky, uzavření knih,
  a otevření nového roku. Zrušit zákonné schválení nelze vůbec (§ 17/7 ZoÚ).
  Zahájení/přerušení uzávěrky, běh přípravných kroků a úpravu prefixů číselných řad
  zvládne i role **účetní** (právo zápisu). Balíček vyžaduje `reports.export`.
- Všechny mutace období a kroků nesou interní verzi záznamu — pokud mezi načtením a
  uložením zasáhne jiný uživatel, systém to ohlásí a znovu načte aktuální data
  místo provedení zastaralé akce.
- Přesto při zahájení uzávěrky organizačně zastav souběžné běžné účtování. Kontrola
  otevřenosti období při běžném zápisu není ve stejné databázové zámkové sekci jako
  přechod `open → closing`, takže těsný souběh může propustit zápis do právě uzavíraného
  období. Po zahájení vždy znovu spusť předkontroly.
- Auditní událost některých uzávěrkových kroků vzniká až po potvrzení účetní transakce.
  Při technickém výpadku proto ověř stav kroku a deník i tehdy, když auditní stopa chybí;
  změnu neopakuj naslepo.
- Do kurzového přecenění nevkládej stejný bankovní analytický účet vícekrát. Backend
  duplicitu neodmítne a mohl by stejný zůstatek přecenit opakovaně.
- Znovuotevření uzavřeného období je zablokované, dokud existují zaúčtované
  uzávěrkové/otevírací zápisy — nejprve je nutné vzít zpět kroky *Otevření nového
  roku* a *Uzavření knih* v průvodci.
- Číselné řady se nikdy nedorovnávají po smazaných dokladech — jedinečnost a
  souvislost číslování je vyžadována zákonem, ne estetika bez mezer.

> [!TIP]
> Uzávěrku dělej v pořadí shora dolů — krok 1 (kontroly) ti dopředu
> ukáže, co je potřeba doladit ještě před tím, než administrátor uzavře knihy. Dokud
> závěrku neschválíš, dokončené kroky lze v povoleném pořadí vzít zpět a opravit. Zákonné
> schválení je ale **nevratné** — po něm řeš opravu v období, kdy ji zjistíš (§ 35 ZoÚ).
> Potřebuješ-li jen vratné interní odsouhlasení, které jde vzít zpět, použij stav
> **Zkontrolované**, ne zákonné schválení.
