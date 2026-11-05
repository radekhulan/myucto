# 50. Průvodce účetního

> Mapa a doporučený postup pro účetní, která v MyÚčtu vede podvojné účetnictví jedné nebo více firem: co dělat každý den, měsíc a rok, kam v aplikaci kliknout a jak poznat, že je hotovo. Podrobnosti k jednotlivým stránkám najdete v navazujících kapitolách.

Kapitola platí pro režim **podvojné účetnictví**. Vedete-li **daňovou evidenci**, sekce menu **Účetnictví** se vám nezobrazí. Místo ní máte **Daňovou evidenci** ([Daňová evidence](74_Danova_evidence.md): peněžní deník, pohledávky a závazky).

## 50.1 Kdy to potřebujete

Kapitolu otevřete, když:

- začínáte pracovat s novou firmou a potřebujete vědět, v jakém pořadí věci dělat,
- chcete zjistit, proč nějaký doklad ještě není v deníku,
- se vám nelíbí zápis, který vytvořila automatika, a hledáte, co opravit,
- do MyÚčta přišly doklady z jiného systému a nejsou zaúčtované,
- se blíží konec měsíce nebo roku a potřebujete postup,
- spravujete víc firem najednou a potřebujete přehled.

### 50.1.1 Kalendář účetní

<!-- cols: 22 46 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| denně | Projít nová upozornění, nezaúčtované doklady, banku a návrhy automatiky | [§ 50.3](#503-krok-za-krokem-denni-prace) |
| jednou po importu z jiného systému | Zaúčtovat naimportované doklady | `Účetnictví → Doúčtovat doklady`, [§ 50.6](#506-krok-za-krokem-zauctovani-dokladu-z-jineho-systemu) |
| jednou při zavedení | Zvolit, kolik toho smí automatika účtovat sama | [§ 50.7](#507-krok-za-krokem-nastaveni-miry-automatiky) |
| když je zápis špatně | Opravit návrh, stornovat zápis, opravit pravidlo | [§ 50.8](#508-krok-za-krokem-oprava-spatneho-zapisu) |
| po skončení měsíce, před DPH | Měsíční kontrola, úplnost dokladů, saldokonto, DPH | [§ 50.4](#504-krok-za-krokem-mesicni-prace) |
| po skončení roku | Uzávěrka krok za krokem | `Nástroje → Uzávěrka`, [§ 50.5](#505-krok-za-krokem-rocni-uzaverka) |
| při práci pro více firem | Projít termíny a nezaúčtované doklady napříč firmami | `Systém → Přehled firem`, [Přehled firem](51_Prehled_firem.md) |

## 50.2 Než začnete

1. **Podvojné účetnictví zapnuté u firmy.** Sekce **Účetnictví** a **Nástroje** se zobrazují jen firmě v podvojném účetnictví. Zapnutí a aktivaci řeší `Nástroje → Aktivace a doúčtování`.
2. **Oprávnění k účetnictví.** Fronty **Automat**, **K doúčtování**, **Doúčtovat doklady** a **Úplnost dokladů** se zobrazí jen s oprávněním k účetnictví. Ruční zápisy v deníku vyžadují oprávnění k zápisu do deníku.
3. **Účtový rozvrh a předkontace.** Základ je hotový po zapnutí účetnictví. U firmy s importovanou historií sestaví první sadu pravidel `Nástroje → Asistent nastavení účtování` ([Asistent nastavení účtování](65_Sablony.md#658-krok-za-krokem-asistent-nastaveni-uctovani)).
4. **Pochopte jedno pravidlo.** Vystavení nebo přijetí dokladu a jeho zaúčtování jsou dva oddělené kroky. Dokud doklad není zaúčtovaný, nepromítne se do hlavní knihy, výsledovky, rozvahy ani do kontroly DPH. Podrobně v [§ 50.10.1](#50101-co-je-zauctovano-a-proc-na-tom-vsechno-stoji).

> [!TIP]
> Chcete-li vědět, kolik dokladů čeká na zaúčtování, otevřete dlaždici **Akce pro tebe** na [Přehledu](10_Prehled.md). Badge **Nezaúčtováno** vidíte i v seznamech faktur.

## 50.3 Krok za krokem: denní práce

1. Otevřete [Přehled](10_Prehled.md). Dlaždice **Akce pro tebe** ukáže nejdůležitější termíny a nehotové doklady.
2. Otevřete `Účetnictví → K doúčtování` ([K doúčtování](54_Rucni_fronta_doctovani.md)). Je to pracovní fronta napříč bankou, vydanými a přijatými fakturami a vyžádanými doklady. Začněte nejstaršími položkami a důvody označenými jako blokované nebo bez pravidla.
3. Otevřete `Nákup → Přijaté faktury` ([Přijaté faktury](23_Prijate_faktury.md)) a zkontrolujte výsledek AI extrakce a navržený druh výdaje. Návrh je jen pomůcka. Účet, daňovou uznatelnost, DPH a případné zařazení do majetku potvrzujete vy.
4. Otevřete `Peníze → Bankovní účty` ([Banka](29_Banka.md)). Na záložce **Bankovní výpisy** potvrďte jednoznačná párování, vyřešte rozúčtované a cizoměnové pohyby a položky, pro které nevznikl návrh. Automatika zaúčtuje jen operace povolené firemní politikou, ostatní ponechá ke schválení na záložce **K zaúčtování**.
5. Otevřete `Účetnictví → Automat` ([Automat](53_Automat.md)). Rozlišujte záložky **Zaúčtováno dnes**, **Ke schválení** a **Vyžaduje zásah**. U každého návrhu je vysvětlení zdroje pravidla a náhled kontace. Hromadně potvrzujte jen stejnorodé položky, jejichž dopad jste zkontrolovali.
6. Otevřete `Účetnictví → Účetní deník` ([Seznam zápisů](52_Ucetni_denik.md#52143-seznam-zapisu)). Filtr **Koncept** ukáže rozpracované ruční zápisy. Zaúčtovaný zápis neopravujete přepisem, ale auditovanou změnou povolených údajů, stornem nebo opravou zdrojového dokladu.

**Jak poznáte, že je hotovo:** Fronta **K doúčtování** je prázdná nebo obsahuje jen položky, které vědomě čekají na podklad. V Automatu nezůstává nic na záložkách **Ke schválení** a **Vyžaduje zásah**. Na Přehledu nezbývá doklad s badgem **Nezaúčtováno**.

> [!WARNING]
> Prázdná fronta neznamená, že je účetnictví věcně správné. Systém pozná chybějící zaúčtování a řadu technických nesouladů, ale bez podkladu nerozhodne například o daňové uznatelnosti, období nákladu, existenci závazku, tvorbě opravné položky ani o správnosti odhadu dohadné položky.

## 50.4 Krok za krokem: měsíční práce

1. Dokončete [K doúčtování](54_Rucni_fronta_doctovani.md) a projděte `Účetnictví → Úplnost dokladů` ([Úplnost dokladů](61_Uplnost_dokladu.md)). Pohyb bez dokladu není automaticky náklad. Vyžádejte podklad, nebo účetně doložte, proč je pohyb zaúčtován bez něj.
2. Zpracujte mzdy. Vede-li firma zjednodušenou **Mzdovou rekapitulaci** (`Účetnictví → Mzdová rekapitulace`, [Mzdy](64_Mzdy.md)), zkontrolujte zaměstnance, měsíční vstupy a náhled předpisu. Vede-li **úplné mzdy**, postupujte podle [zpracování mzdového měsíce](75_Uplne_mzdy.md#754-krok-za-krokem-zpracovani-mzdoveho-mesice) a výsledek ověřte na [Shodě účtování mezd](81_Shoda_uctovani_mezd.md). V obou případech odpovídáte za správnost vstupů, zvláštní režimy a shodu s podklady mzdové agendy.
3. Spusťte `Účetnictví → Měsíční kontrola` ([Měsíční kontrola](62_Mesicni_kontrola.md)) před DPH a po dokončení měsíce. Výsledek je kontrolní seznam, ne automatická oprava.
4. Otevřete `Účetnictví → Saldokonto` ([Saldokonto](60_Saldokonto.md)). Uvidíte otevřené pohledávky a závazky podle partnerů. Použijte je k inventarizaci účtů 311 a 321 a k rozhodnutí, co poslat na [upomínku](22_Upominky.md) nebo na [zápočet](67_Zapocty.md).
5. Otevřete **Hlavní knihu** a **Obratovou předvahu** ([Hlavní kniha](55_Hlavni_kniha.md), [Obratová předvaha](56_Obratova_predvaha.md)). Prověřte neobvyklé zůstatky, průběžné účty, pokladnu a vazbu banky na účetní analytiky.
6. Připravte DPH výkazy ([Výkazy DPH](41_Vykazy_DPH.md)): přiznání a kontrolní hlášení. Před podáním ověřte, že se čísla shodují s knihou DPH ([Kniha DPH](42_Kniha_DPH.md)) a s účtem 343 v deníku.
7. Po skutečném podání nahrajte XML a potvrzení do **EPO podání a archívu**. Samotné vytvoření ani stažení XML zámek neposouvá. Po kontrole doručenky klikněte u validního snapshotu DPH nebo KH na **Označit jako podané** ([doložení podání](49_Archiv_podani_a_rekonciliace.md#496-krok-za-krokem-dolozte-podani)). Tím se posune příslušný zámek ([Zámek účtování k datu](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu)).
8. Pracujete-li pro klienta jako externí účetní, sestavte `Účetnictví → Měsíční přehled` ([Měsíční přehled](63_Mesicni_report.md)). Jedno tlačítko vytvoří PDF report za měsíc.

**Jak poznáte, že je hotovo:** Měsíční kontrola nemá otevřený nález, který by bránil podání DPH. Zámek účtování k datu je posunutý za podaný měsíc. Klientský report je odeslaný.

## 50.5 Krok za krokem: roční uzávěrka

Celý postup vede uzávěrkový průvodce v `Nástroje → Uzávěrka` ([Uzávěrka](72_Uzaverka.md)). Pořadí kroků:

1. [Předběžné kontroly](72_Uzaverka.md#7241-krok-1-predbezne-kontroly). Jsou to stejné kontroly jako měsíční, ale za celý rok.
2. [Odpisy majetku](72_Uzaverka.md#7242-krok-2-odpisy-majetku): hromadné zaúčtování ročních odpisů, viz i [Zaúčtovat odpisy roku](28_Majetek.md#286-krok-za-krokem-zauctovat-odpisy-roku).
3. [Kurzové rozdíly](72_Uzaverka.md#7243-krok-3-kurzove-rozdily): přecenění cizoměnových zůstatků k rozvahovému dni.
4. [Dohadné položky a časové rozlišení](72_Uzaverka.md#7244-kroky-4-a-5-dohadne-polozky-a-casove-rozliseni), včetně návrhů předplacených nákladů a zvolené politiky drobného majetku. Návrhy vycházejí z pravidel nákladů označených jako **opakovaný předplacený náklad**. Jde o doporučení jen ke čtení, které uzávěrka zaúčtuje až po vašem potvrzení.
5. [Opravné položky k pohledávkám](72_Uzaverka.md#7245-krok-6-opravne-polozky-k-pohledavkam). Navazují na saldokonto z [§ 50.4](#504-krok-za-krokem-mesicni-prace).
6. [Daň z příjmů](72_Uzaverka.md#7246-krok-7-dan-z-prijmu): mezikrok na stránce [Daň z příjmů](43_Dan_z_prijmu.md).
7. [Zásoby](72_Uzaverka.md#7247-krok-8-zasoby). Je-li sklad vedený v podvojném účetnictví a firma účtuje zásoby způsobem B, doložte fyzickou inventuru a ocenění a klikněte na **Zaúčtovat / přepočítat zásoby**. Krok zaúčtuje konečný stav, manka a přebytky. Firmě bez skladu se krok přeskočí sám.
8. [Uzavření knih a otevření nového roku](72_Uzaverka.md#725-krok-za-krokem-uzavreni-knih-a-otevreni-noveho-roku).
9. **Uzávěrkový balíček.** Před schválením stáhněte doložitelný ZIP sestav, inventarizací a daňových podkladů.
10. [Schválení závěrky](72_Uzaverka.md#726-krok-za-krokem-schvaleni-zaverky-a-znovuotevreni-obdobi): rozdělení výsledku hospodaření (431 → 428/429/364).

Po celý rok si držte po ruce [**Rozvahu**](57_Rozvaha.md) a [**Výsledovku**](58_Vysledovka_druhova.md) jako průběžnou kontrolu, jestli výsledek hospodaření ve výsledovce sedí s řádkem A.V. rozvahy. U firem s bankovním úvěrem zkontrolujte i řádek nákladových úroků (562).

**Jak poznáte, že je hotovo:** Účetní období má stav **Uzavřené** (po schválení závěrky **Schválené**), nový rok je otevřený a výsledek hospodaření je rozdělený.

## 50.6 Krok za krokem: zaúčtování dokladů z jiného systému

Automatika účtování se spouští při vzniku dokladu, ne u dokladů, které už v systému leží. Doklady naimportované z jiného systému proto zaúčtujete takto:

1. Otevřete `Účetnictví → Doúčtovat doklady` ([Doúčtování nezaúčtovaných dokladů](52_Ucetni_denik.md#521413-douctovani-nezauctovanych-dokladu)).
2. V bloku **Čeká na zaúčtování** si prohlédněte počty dokladů rozdělené po typech.
3. Klikněte na **Zkusit nanečisto** a v **Protokolu** zkontrolujte, co by vzniklo. Nic se nezapíše.
4. Klikněte na **Doúčtovat**. Úloha běží na pozadí a lze ji kdykoli ukončit tlačítkem **Zastavit**. Doklady zaúčtované do té chvíle v deníku zůstanou.

**Jak poznáte, že je hotovo:** Běh má v seznamu **Poslední běhy** stav **Hotovo** a stránka hlásí **Všechno je zaúčtované.**, nebo zbyly jen doklady s uvedenou chybou. Každý doklad se účtuje samostatně, jeden vadný dávku nezastaví. Pokladna, banka a zápočty mají vlastní cesty.

## 50.7 Krok za krokem: nastavení míry automatiky

Firmě, která teprve zavádí účetnictví nebo importovala cizí historii, doporučujeme začít opatrně.

1. Otevřete `Firma → Nastavení`, záložku **Daně a účetnictví**. Tentýž box je i v `Účetnictví → Automat`, záložka **Pravidla**, takže kvůli změně režimu nemusíte odcházet z fronty.
2. Pro začátek nastavte **Celkový režim** na **jen návrhy**.
3. Několik dní kontrolujte výsledky ve frontě Automatu.
4. U ověřených typů operací zvyšujte úroveň. Klikněte na **Nastavit jednotlivé typy operací** a pro každý typ zvolte **vypnuto**, **jen návrhy** nebo **plná automatika**.
5. Chcete-li omezit objem, vyplňte **Denní limit automatiky (Kč)**.

**Jak poznáte, že je hotovo:** V Automatu se samy účtují jen typy operací, kterým důvěřujete, a ostatní čekají na záložce **Ke schválení**. Cesta zpět je vždy otevřená.

> [!WARNING]
> Výchozí stav po zapnutí podvojného účetnictví je plná automatika a obě zaškrtávátka automatického účtování faktur jsou zapnutá. Pomalejší začátek vám ušetří storna.

## 50.8 Krok za krokem: oprava špatného zápisu

1. **Návrh, který ještě není zaúčtovaný.** V Automatu klikněte na **Upravit kontaci** a schvalte opravenou variantu (rozdíl se uloží jako učicí signál). Nebo klikněte na **Zamítnout** a uveďte důvod. Opakovaná zamítnutí pravidlo vypnou.
2. **Hotový automatický zápis.** Na záložce **Zaúčtováno dnes** klikněte na **Stornovat**, případně na **Vrátit zpět** v oznámení hned po schválení. Vznikne storno ve stejném datu jako původní zápis, obojí zůstane kvůli auditu a kontace se vrátí do fronty.
3. **Zápis u dokladu.** Opravte zdrojový doklad a zaúčtujte znovu, nebo použijte storno. Účetní historie se nepřepisuje.
4. **Příčina.** Po storně opravte i vrstvu, která o chybném zápisu rozhodla ([§ 50.10.2.1](#501021-ctyri-vrstvy-ktere-urcuji-kontaci)). Jinak stejná chyba vznikne příště znovu.

**Jak poznáte, že je hotovo:** Chybný zápis je stornovaný nebo nahrazený, doklad má správnou kontaci a další podobný doklad už vznikne správně.

> [!WARNING]
> Do dnešního období systém storno potichu nepřesune. Je-li původní období už uzavřené, operaci odmítne beze změny dat.

## 50.9 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Doklad je vystavený, ale není v deníku | Vystavení a zaúčtování jsou dva kroky; automatické účtování je vypnuté nebo selhalo | Na detailu faktury klikněte na **Zaúčtovat**. Chyby zaúčtování popisuje [Zaúčtování selže](16_Faktura_PDF.md#1691-zauctovani-selze). |
| Naimportované doklady nikdo nezaúčtoval | Automatika neprojde doklady, které už v systému leží | `Účetnictví → Doúčtovat doklady` ([§ 50.6](#506-krok-za-krokem-zauctovani-dokladu-z-jineho-systemu)). |
| Bankovní pohyb nemá návrh a je ve frontě **K doúčtování** | Důvod „Pro nespárovanou transakci není nastavené pravidlo“ nebo „Cizoměnová transakce vyžaduje ruční zaúčtování“ | Zaúčtujte ručně na detailu výpisu, nebo založte bankovní pravidlo ([§ 50.10.2.7](#501027-bankovni-vypisy-od-vypisu-k-zapisu)). |
| Návrh zůstal ve frontě, i když má vysokou jistotu | Překročený strop, denní limit, chybějící předpis, anomálie nebo uzavřené období | Automat u návrhu vypíše konkrétní pojistku. Odstraňte ji, nebo návrh schvalte ručně. |
| Zápis je věcně špatně | Rozhodla jiná vrstva nastavení | Postupujte podle [§ 50.8](#508-krok-za-krokem-oprava-spatneho-zapisu). |
| Mzdová platba v bance se neúčtuje | Pohyb si nárokuje mzdový modul, aby se závazek neodúčtoval dvakrát | Vypořádání řešte v [Mzdových příkazech a úhradách](82_Platby_a_uhrady.md) a kontrolujte na [Shodě účtování mezd](81_Shoda_uctovani_mezd.md). |
| Storno je odmítnuto | Původní zápis je v uzavřeném nebo zamčeném období | Obraťte se na osobu, která smí zrušit zámek nebo znovuotevřít období ([Zámek účtování k datu](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu)). |

## 50.10 Podrobnosti a pravidla

### 50.10.1 Co je zaúčtováno a proč na tom všechno stojí

Vydaná i přijatá faktura, bankovní/pokladní pohyb i majetkový doklad mohou
existovat v aplikaci **bez zápisu v účetním deníku** - vystavení dokladu a
jeho zaúčtování jsou dva oddělené kroky. Dokud doklad není zaúčtovaný,
nepromítne se do hlavní knihy, výsledovky, rozvahy ani do předvahy pro DPH
kontrolu.

- Na detailu vydané faktury ([Faktury](14_Faktury.md)) i přijaté faktury
  ([Přijaté faktury](23_Prijate_faktury.md)) je tlačítko **Zaúčtovat** - vytvoří zápis
  podle [předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace)
  a doklad dostane účetní ikonu **Zaúčtováno** s tooltipem a odkazem na zápis v deníku.
- Badge **Nezaúčtováno** vidíte přímo v seznamech faktur (filtr **Zaúčtování**) i na dlaždici **Akce pro tebe** na [Přehledu](10_Prehled.md). To je váš denní vstupní bod, kolik dokladů ještě čeká na zaúčtování.
- Když zaúčtování selže, aplikace vrátí srozumitelnou chybu místo tichého
  selhání - přehled chybových hlášek a jak je opravit viz
  [Zaúčtování do deníku](16_Faktura_PDF.md#16103-zauctovani-do-deniku)
  a [ochrany účtu při zaúčtování](66_Ucetni_osnova.md#6688-ochrany-pri-uctovani).
- U banky ([Bankovní účty](30_Bankovni_ucty.md#301118-automaticke-zauctovani-bankovnich-transakci-jen-podvojne-ucetnictvi))
  a párovaných plateb ([Banka](29_Banka.md#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi))
  lze zaúčtování z velké části zautomatizovat pravidly - ušetří to ruční
  zaúčtování běžných plateb.

### 50.10.2 Jak systém účtuje

MyÚčto nemá jeden univerzální automat, který by „uměl účtovat". To, **jaký
zápis vznikne**, skládají čtyři nezávislé vrstvy nastavení. Teprve nad nimi
stojí **automatika**, která rozhoduje o něčem úplně jiném: jestli hotový
výsledek rovnou zapíše do deníku, nebo vám ho předloží ke schválení.

Ten rozdíl je praktický. Je-li zápis **věcně špatně**, opravte vrstvu, která o něm
rozhodla. Je-li zápis správně, ale **nemá vznikat sám**, změňte nastavení automatiky.
Nastavování automatiky nikdy neopraví špatný účet a naopak.

Jedno platí vždycky: ať zápis vznikne jakkoli, prochází stejnou vnitřní službou,
která hlídá podvojnost (Σ MD = Σ Dal), otevřenost účetního období, zámek k datu
a idempotenci. V deníku proto nikdy nevznikne nevyrovnaný zápis ani dvojí
zaúčtování téhož dokladu - viz [Účetní deník](52_Ucetni_denik.md).

#### 50.10.2.1 Čtyři vrstvy, které určují kontaci

| # | Vrstva | Co určuje | Na co se použije | Kde ji najdete |
|---|---|---|---|---|
| 1 | **Předkontace** | výchozí dvojici účtů **MD/Dal** pro systémový typ operace (stabilní klíč, například `invoice.services.issued` nebo `payment.receivable.bank`) | každý doklad, který systém účtuje sám | **Nástroje → Účetní nastavení**, záložka **Předkontace** |
| 2 | **Pravidla nákladů** a **výnosů** | druh řádku přijaté faktury a konkrétní nákladový účet; účet řádku vydané faktury podle kódu, textu nebo sazby DPH | řádky přijatých a vydaných faktur | **Nástroje → Šablony účtování**, záložky **Pravidla nákladů** a **Pravidla výnosů** |
| 3 | **Pravidla účtování** | kontaci opakovaného bankovního pohybu, ke kterému neexistuje doklad | bankovní pohyby bez faktury (poplatky, odvody, úroky, nájem, splátky) | **Nástroje → Šablony účtování**, záložka **Pravidla účtování**, nebo **Účetnictví → Automat**, záložka **Pravidla** |
| 4 | **Šablony zápisů** | předvyplněné řádky ručního zápisu | ruční zápis v deníku a náhled kontace dokladu | **Nástroje → Šablony účtování**, záložka **Šablony zápisů** |

Vrstvy se **nepřekrývají** a nedají se nahradit jedna druhou:

- **Předkontace není šablona.** Je to mapa „typ operace → dvojice účtů". Systém
  má zhruba padesát globálních klíčů (fakturace, úhrady, pokladna, zálohy, mzdy,
  DPH, kurzové rozdíly, opravné položky, rezervy, časové rozlišení, dohadné
  položky, odpisy, vyřazení majetku, inventarizační rozdíly, odvody). Firma
  u nich může přepsat jen účty MD/Dal - **nový druh operace nezaložíte**, a
  firemní override vždy přebije globální hodnotu. Prázdná strana může být záměr:
  protiúčet doplní podle dokladu služba, třeba u kurzového rozdílu. DPH na 343
  v mapě není vůbec; dopočítává ji daňová služba z položek přes knihu DPH.
- **Pravidlo nákladů nevybírá kontaci**, vybírá **druh řádku**. Kontaci pak
  určí předkontace odpovídající tomuto druhu.
- **Bankovní pravidlo není šablona zápisu s pevnou částkou.** Rozpoznává
  transakci; částku bere z pohybu.
- **Šablona zápisu neúčtuje.** Jen předvyplní řádky, které účetní doplní
  a potvrdí.

Když se vám výsledek nelíbí, postupujte po vrstvách odshora:

1. **špatně rozpoznaný druh nákladu** → opravte pravidlo nákladů nebo přímo řádek
   dokladu,
2. **správný druh, ale chybný základní účet** → opravte předkontaci,
3. **nestandardní vícerádkový zápis** → použijte nebo upravte šablonu zápisů,
4. **opakovaná platba bez dokladu účtovaná špatně** → opravte bankovní pravidlo.

Podrobně všechny čtyři popisuje kapitola [Šablony a pravidla](65_Sablony.md),
předkontace samotné [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace).

> [!TIP]
> Firmě s naimportovanou historií, která ještě žádná pravidla nemá, sestaví
> první sadu **Nástroje → Asistent nastavení účtování**
> ([Asistent nastavení účtování](65_Sablony.md#658-krok-za-krokem-asistent-nastaveni-uctovani)). Projde existující
> doklady a navrhne analytické účty, pravidla nákladů, předkontace i bankovní
> pravidla. Nic nezaloží bez vašeho výslovného schválení.

#### 50.10.2.2 Co který doklad spustí

| Událost | Co systém udělá | Podle čeho |
|---|---|---|
| **Vystavení vydané faktury** | zápis 311 / 6xx + DPH na výstupu 343.200 | předkontace podle klíče výnosu na hlavičce (výchozí `invoice.services.issued`, 602), kniha DPH |
| **Přijetí přijaté faktury** | zápis 5xx nebo 04x / 321 + DPH na vstupu 343.100 | druh řádku z pravidel nákladů → předkontace, kniha DPH |
| **Spárování bankovní platby s dokladem** | přímý zápis 221/311, 321/221, 221/324 (inkaso zálohy), 314/221 (poskytnutá záloha) | předkontace `payment.*` |
| **Bankovní pohyb bez dokladu** | zápis nebo návrh podle vestavěného rozpoznání, pravidla nebo naučené kontace | pravidla účtování, registr vlastních účtů, mapa odvodů, historie oprav |
| **Pokladní doklad** | zápis pokladny | předkontace |
| **Zařazení, vyřazení a odpis majetku** | zápis odpisu nebo pohybu karty | [Majetek](28_Majetek.md) |
| **Skladový pohyb, zápočet, měsíční zúčtování DPH, uzávěrkové operace** | vlastní zápisy s odpovídajícím zdrojem | příslušné kapitoly |
| **Schválení mzdového běhu** | rozdílový mzdový deník | předkontace **zmrazené při uzamknutí vstupů** - pozdější změna nastavení už zkontrolovanou revizi nepřepíše |
| **Ruční zápis v deníku** | to, co zadáte | případně šablona zápisů |

Mzdové platby se z banky **neúčtují**: pohyb si nárokuje mzdový modul, aby se
závazek neodúčtoval dvakrát. Vypořádání se řeší v
[Mzdových příkazech a úhradách](82_Platby_a_uhrady.md) a kontroluje na
[Shodě účtování mezd](81_Shoda_uctovani_mezd.md).

> [!WARNING]
> **Automatika účtování je háček na VZNIK dokladu, ne zametač existujících.**
> Spustí se při vystavení faktury, přijetí přijaté faktury nebo opakované
> fakturaci. Doklad, který už v systému leží - typicky **naimportovaný z jiného
> systému** - jí neprojde nikdy, ať je nastavená jakkoli. Takové doklady
> zaúčtuje **Účetnictví → Doúčtovat doklady**
> ([Doúčtování nezaúčtovaných dokladů](52_Ucetni_denik.md#521413-douctovani-nezauctovanych-dokladu)):
> ukáže, kolik dokladů čeká zvlášť po typech, umí **Zkusit nanečisto** i běh
> **Zastavit**, každý doklad účtuje samostatně (jeden vadný dávku nezastaví) a
> nemá strop 500 dokladů na dávku jako hromadné zaúčtování ze seznamu faktur.
> Účtuje vydané a přijaté faktury; pokladna, banka a zápočty mají vlastní cesty.

#### 50.10.2.3 Kde se automatika zapíná a co přesně smí

Všechno se nastavuje na jednom místě: **Firma → Nastavení → Daně a účetnictví**.
Tentýž box je i v **Účetnictví → Automat → Pravidla**, takže kvůli změně režimu
nemusíte odcházet z fronty.

**Dvě samostatná zaškrtávátka** řídí háček na vznik dokladu:

- **Automaticky účtovat vydané faktury** - po vystavení faktury ji rovnou
  zaúčtovat. Chyba zaúčtování vystavení nezablokuje; faktura zůstane vystavená
  a zaúčtujete ji ručně.
- **Automaticky účtovat přijaté faktury** - totéž pro přijaté doklady.

**Box Automatika účtování** řídí zbytek - hlavně banku:

| Volba | Význam |
|---|---|
| **Celkový režim: vypnuto** | Automat nevytváří ani neúčtuje návrhy. |
| **Celkový režim: jen návrhy** | Všechny rozpoznané operace čekají na potvrzení. |
| **Celkový režim: asistovaná** | Samy se účtují jen spárované platby, převody mezi vlastními účty, sociální a zdravotní pojištění OSVČ a obě vestavěná rozpoznávání. Zbytek zůstává jako návrh. |
| **Celkový režim: plná automatika** | Samy se účtují všechny deterministické operace **kromě** AI návrhů, naučených kontací, paušální daně a ostatních odvodů - ty zůstávají návrhem. |
| **Denní limit automatiky (Kč)** | Celofiremní strop na objem zaúčtovaný za den. Prázdná hodnota znamená bez limitu; po jeho vyčerpání se další položky degradují na návrh. |
| **Ranní přehled e-mailem** | Souhrn fronty; neprovádí žádnou účetní operaci. |
| **Nastavit jednotlivé typy operací** | Rozpad celkového režimu na konkrétní typy - každý zvlášť na úrovni **vypnuto / jen návrhy / plná automatika**. |

Podrobné nastavení má čtyři skupiny:

- **Platby a převody** - Vystavené faktury, Přijaté faktury, Spárované bankovní
  platby, Převody mezi vlastními účty.
- **Odvody** - Sociální a Zdravotní pojištění OSVČ, Sociální a Zdravotní
  pojištění zaměstnavatele, Platby DPH, Odvod daně v režimu OSS, Daň z příjmů,
  Srážková daň, Daň ze závislé činnosti, Daň z nemovitých věcí, Silniční daň,
  Paušální daň, Ostatní odvody.
- **Banka** - Bankovní úroky, Bankovní poplatky, Vlastní bankovní pravidla,
  Naučené kontace, Rozpoznávání odvodů, Rozpoznávání vlastních převodů.
- **AI** - AI návrhy bankovních plateb, AI návrhy dokladů.

Rozpoznávání je nadřazené: vypnete-li **Rozpoznávání odvodů** nebo
**Rozpoznávání vlastních převodů**, klesnou s ním i všechny operace, které se
o něj opírají - vyšší úroveň u jednotlivého odvodu se pak neuplatní.

> [!WARNING]
> **Výchozí stav po zapnutí podvojného účetnictví je plná automatika** a obě
> zaškrtávátka automatického účtování faktur zapnutá. Firmě, která teprve
> zavádí účetnictví nebo importovala cizí historii, doporučujeme hned na začátku
> přepnout celkový režim na **jen návrhy**, několik dní kontrolovat výsledky ve
> frontě a teprve ověřeným typům operací úroveň zvyšovat. Cesta zpět je vždy
> otevřená.

Úroveň je vždy jen **horní hranice**. Bez ohledu na nastavení se **nikdy
nezaúčtuje samo**:

- **AI návrh** - technicky vyloučený, nejde povolit ani omylem,
- **nejednoznačná shoda** - dvě stejně silná pravidla skončí jako návrh
  s důvodem „Platbě odpovídá více pravidel se stejnou prioritou“,
- **cizoměnový nespárovaný pohyb**,
- **pohyb v uzavřeném nebo zamčeném období**,
- **položka nad stropem pravidla nebo nad denním limitem**,
- **operace na saldokontním účtu** (311, 321, 314, 324, 325) mimo řádné
  spárování platby s dokladem,
- **úhrada závazku** (336, 342, 343, 345) z 221 **bez existujícího zaúčtovaného
  předpisu** v dostatečné výši - jinak by vznikl nepodložený zůstatek,
- **podezření na duplicitu** a **nízká jistota** - ty jdou rovnou do fronty
  **Vyžaduje zásah**.

Vlastní převod se účtuje sám jen mezi evidovanými vlastními účty ve stejné měně.
Uživatelské bankovní pravidlo smí účtovat samo teprve tehdy, když je povýšené na
automatický režim, má vyplněný rozsah částky a už nejméně třikrát uspělo.

#### 50.10.2.4 Návrhy: co znamená „Proč" a jistota

Každý návrh i automatický zápis nese štítek **Proč**, který říká, která vrstva
rozhodla:

| Štítek | Rozhodla | Účtuje samo? |
|---|---|---|
| **Faktura** | platba byla spárována s konkrétním dokladem | ano, podle politiky |
| **Pravidlo** | pojmenované bankovní pravidlo | ano, je-li povýšené a v limitu |
| **Systémové rozpoznání** | vestavěná detekce (vlastní převod, odvod) | ano, podle politiky |
| **Naučeno** | shoda s dříve potvrzenými zápisy téže firmy | jen jako návrh |
| **Předpis zálohy** | evidovaný předpis | ano, podle politiky |
| **AI návrh** | jazykový model nebo podobnost | **nikdy** - ani hromadně |

Jistota (slovně i procentem) vyjadřuje sílu shody podle dostupných dat, ne
účetní správnost. Prakticky platí, že položka pod velmi vysokou jistotou
se zaúčtovat sama nemůže a při opravdu nízké jistotě rovnou padá do
**Vyžaduje zásah**. U návrhu s vysokou jistotou, který přesto čeká, Automat
vypíše konkrétní pojistku - překročený strop, denní limit, chybějící předpis,
anomálii nebo uzavřené období.

#### 50.10.2.5 Kam výsledek doputuje - tři fronty, tři různé otázky

| Stránka | Odpovídá na otázku | Provádí akce? |
|---|---|---|
| [**Účetnictví → Automat**](53_Automat.md) | Co systém zaúčtoval, co navrhl a co v návrhu potřebuje rozhodnutí? | Ano - schválit, zamítnout, upravit kontaci, odložit, stornovat. |
| [**Účetnictví → K doúčtování**](54_Rucni_fronta_doctovani.md) | Který známý případ nemá hotový zápis a **nevznikl pro něj vůbec žádný návrh**? | Ne - jen odkazuje na zdroj. |
| [**Účetnictví → Úplnost dokladů**](61_Uplnost_dokladu.md) | Které bankovní pohyby nemají doklad a které otevřené doklady jsou po splatnosti? | Ne - kontrolní sestava s agingem. |

Bankovní pohyb, pro který **existuje návrh v jakémkoli stavu**, je vždy jen
v Automatu; do fronty K doúčtování se dostanou jen dva důvody: chybějící pravidlo
(„Pro nespárovanou transakci není nastavené pravidlo“) a cizí měna
(„Cizoměnová transakce vyžaduje ruční zaúčtování“). Fronty se tak
záměrně nepřekrývají. Nezaúčtovaný **doklad** naopak může být vidět v obou - je
to týž případ ve dvou pracovních pohledech.

Automat má sedm záložek: **Doporučení** (výchozí - hledá příležitosti
k automatizaci nad existující historií), **Zaúčtováno dnes**, **Ke schválení**,
**Vyžaduje zásah**, **Pravidla**, **Checklist** a **Historie**.

#### 50.10.2.6 Náklady: od PDF přijaté faktury k účtu

1. Doklad se do systému dostane e-mailem, uploadem, přes ISDOC nebo přes
   [AI extrakci](25_AI_extrakce.md) (**Nákup → AI import**), která vyplní
   hlavičku a řádky.
2. Na každém řádku se určí **druh nákladu**. Rozhodují tři vrstvy v tomto
   pořadí: **firemní pravidlo nákladů** → **katalog frází** → **vestavěná
   klíčová slova**. Neuspěje-li nic, řádek zůstane na výchozí hodnotě a
   rozhodne účetní.

   | Druh nákladu | Výchozí účet |
   |---|---|
   | služba | 518 |
   | materiál | 501 |
   | drobný majetek | 501 |
   | drobný nehmotný majetek | 518 |
   | dlouhodobý majetek | 042 |

   Místo výchozího účtu může pravidlo určit konkrétní aktivní nákladový účet.
   Saldokonto, DPH, banku ani pokladnu jako cíl nastavit nelze.
3. Kritéria pravidla - konkrétní dodavatel, fragment názvu dodavatele, fragment
   popisu položky - se vyhodnocují **současně (AND)** a aspoň jedno z nich musí
   být vyplněné. **Rozpětí částky** je jen zúžení podle ceny za kus bez DPH,
   ne samostatné kritérium; pravidlo postavené jen na ceně by zachytávalo
   nesouvisející nákupy. Priorita **0–999, výchozí 100, nižší jde první**;
   vyhraje první shoda.
4. Každé pravidlo má **Režim použití**: **Jen navrhovat** nebo **Použít
   automaticky**. Pravidla z asistenta i z Automatu vznikají vždy v režimu
   návrhu. I automatické použití je jen předvyplnění - ruční volba účtu na
   řádku je vždy silnější a nepřepíše se.
5. Systém sám hlídá dvě věci, které se pletou nejčastěji:
   - **práh § 26 odst. 2 ZDP** - řádek označený jako drobný majetek, jehož cena
     za kus překročí zákonný limit (výchozích 80 000 Kč), se překlopí na
     **dlouhodobý majetek** a účet 042 s odpisy;
   - **osobní a nedaňové výdaje podle § 25 ZDP** (typicky optika nebo chytré
     hodinky) dostanou jen nízkou jistotu, zůstanou jako služba a **účet
     nedostanou vůbec** - volba mezi 528 a 513 je rozhodnutí účetní jednotky.
6. **Účetní potvrdí řádek.** Do uložení jde pořád jen o návrh.
7. Zaúčtování použije **předkontaci odpovídající potvrzenému druhu**. Faktura se
   rozpadá **po položkách**, takže jeden doklad může mít víc nákladových noh
   s různými účty. DPH jde odděleně přes knihu DPH; neodpočitatelná část daně se
   přičte k nákladu.
8. **Daňovou uznatelnost nese účet**, ne pravidlo. Nedaňový náklad se účtuje na
   účet označený v účtovém rozvrhu jako daňově neuznatelný (528, 513) a odtud si
   ho sečte řádek 40 přiznání k dani z příjmů právnických osob.
9. U drobného majetku lze z potvrzených řádků založit evidenční karty - karta
   nevytváří druhý nákladový zápis ([Drobný majetek](27_Drobny_majetek.md)).

> [!WARNING]
> Cenový práh ani slovo v popisu nerozhoduje, jestli jde o drobný či dlouhodobý
> majetek, technické zhodnocení nebo soubor věcí. Návrh je pomůcka; zařazení,
> daňovou uznatelnost a období nákladu potvrzuje účetní podle vnitřní směrnice.
> AI návrh druhu nákladu má z principu tak nízkou jistotu, že se sám nikdy
> nepoužije.

#### 50.10.2.7 Bankovní výpisy: od výpisu k zápisu

1. **Import.** GPC výpis nebo PDF podporované banky nahrajete v
   **Peníze → Bankovní účty**, záložka **Bankovní výpisy**, nebo pohyby
   dorazí z e-mailových avíz ([Bankovní účty](30_Bankovni_ucty.md)). Avízo je provizorní, **nikdy se neúčtuje** a
   po příchodu oficiálního výpisu mu párování předá.
2. **Párování.** Platba se spáruje podle variabilního symbolu, ručně, nebo jako
   sloučená úhrada ([Párování plateb](29_Banka.md#294-krok-za-krokem-parovani-plateb-s-fakturami)).
3. **Spárovaná platba se účtuje přímo** podle předkontací `payment.*` - běžná
   vydaná faktura 221/311, běžná přijatá 321/221, proforma 221/324, přijatá
   zálohová 314/221. U cizoměnové úhrady se dopočítá kurzový rozdíl (563/663).
   Na spárované platby faktur bankovní pravidla nesahají.
4. **Nespárovaný pohyb** projde v tomto pořadí:
   1. **vestavěné rozpoznání** - odvody státním institucím podle mapy předčíslí
      a variabilních symbolů (DPH, DPPO, DPFO, zálohová a srážková daň ze mzdy,
      daň z nemovitých věcí, silniční daň, paušální daň, SP a ZP OSVČ) a převody
      mezi vlastními účty; rozpoznání ustoupí uživatelskému pravidlu s prioritou
      nižší než 50,
   2. **bankovní pravidlo**,
   3. **naučená kontace** z dříve potvrzených obdobných pohybů,
   4. **AI návrh**, je-li AI asistence zapnutá,
   5. **nic** - pohyb skončí v **K doúčtování** s důvodem
      „Pro nespárovanou transakci není nastavené pravidlo“.
5. **Cizoměnový nespárovaný pohyb** automatika nepodporuje. Dostane důvod
   „Cizoměnová transakce vyžaduje ruční zaúčtování“ a účtuje se ručně na
   detailu výpisu.

Bankovní pravidlo založíte na záložce **Pravidla účtování** tlačítkem
**Nové pravidlo**, rovnou z rozbalovacího menu pohybu volbou
**Vytvořit účtovací pravidlo** (formulář se předvyplní protistranou, zprávou,
směrem a měnou), nebo z hotové **šablony** v katalogu
**Nástroje → Šablony účtování → Šablony pravidel** (odvody, bankovní poplatky, přijaté úroky,
nájem, předplatné). Pravidlo obsahuje:

- **směr** (příchozí/odchozí),
- **alespoň jedno kritérium shody** - protiúčet (volitelně s kódem banky a
  předčíslím), variabilní symbol, nebo fragment zprávy; fragment se hledá
  v popisu platby **i ve jménu protistrany**,
- volitelný **rozsah částky** (prázdná mez neomezuje danou stranu intervalu),
- **kontaci MD/Dal** - bankovní strana musí být účet **221**, druhá strana nesmí
  být saldokontní účet (311/321/314/324/325),
- **prioritu** - nižší číslo se vyhodnocuje dřív; hodnota pod 50 přebije
  i vestavěné rozpoznání,
- **limit pro automatiku** - nad zadanou částkou vynutí pouhý návrh, i když je
  pravidlo povýšené.

Uložení pravidla samo nic nezaúčtuje. **Otestovat na historii** je dry-run;
**Použít na historii** vytvoří **jen návrhy** pro dosud nezaúčtované pohyby
v otevřených obdobích a nikdy nepřeúčtuje už zaúčtovanou transakci.

Nové pravidlo vždy začíná v režimu **navrhovat**. Po **pěti** potvrzeních beze
změny za sebou, bez jediného odmítnutí a s vyplněným rozsahem částky nabídne
Automat tlačítko **Povýšit na automatiku** - k povýšení nikdy nedojde samo.
Naopak **tři odmítnutí na různých transakcích** pravidlo deaktivují a storno
automatického zápisu ho vrátí zpět do režimu návrhů.

### 50.10.3 Víc firem - účetní kancelář

Vedete-li víc firem najednou, najdete v menu `Systém` položku
**[Přehled firem](51_Prehled_firem.md)** - cross-firemní pohled na termíny
DPH/KH, nezaúčtované doklady a stav uzávěrky, s proklikem a přepnutím firmy
bez návratu na dashboard.

Předkontace, pravidla, šablony i fronty jsou vždy **firemní**. Automat ani
Šablony účtování nemají vlastní výběr firmy - pracují s firmou zvolenou v hlavní
liště aplikace, takže data dvou účetních jednotek se nikdy nesmíchají. Naučené
kontace se rovněž nikdy neporovnávají přes firmy.

### 50.10.4 Ostatní pohledávky a závazky

V menu **Účetnictví → Ostatní pohledávky a závazky** založíte pohledávku nebo závazek,
který není vydanou ani přijatou fakturou. Zadejte titul, protistranu, částku
v CZK, datum vzniku a splatnosti. Cizoměnové položky tato agenda zatím
nepřijímá, protože by vyžadovaly kurzové přecenění při uzávěrce.
V podvojném účetnictví vyberte účet
pohledávky nebo závazku a protiúčet podle skutečného případu. Před potvrzením
je položka konceptem. Tlačítko **Zaúčtovat** vytvoří jediný zápis v deníku a
přidělí číslo řady OP nebo OZ. Storno vytvoří opravný zápis; položku s
přiřazenou úhradou je třeba nejprve od úhrady odpojit.
Pokud se má změnit kontace již zaúčtované položky, použijte **Přeúčtovat**.
Zadáte nový účet, protiúčet, datum a důvod. Systém v jedné operaci stornuje
původní zápis a vytvoří nový, doklad si zachová ID a číslo. Datum musí
spadat do otevřeného období.

Pole **Účet protistrany** je nepovinné. Zadejte český účet ve tvaru
`předčíslí-číslo/kód banky` (například `19-1000000005/0100`) nebo IBAN.
Český účet se ověří kontrolou modulo 11, IBAN kontrolními číslicemi. Český
IBAN se uloží jako národní číslo účtu s kódem banky. Účet se zobrazí na detailu
a opakování ho přenáší do každé nově vzniklé položky. Podle účtu aplikace
nabízí úhrady z banky.

Na detailu lze připojit originály ze [skladu dokumentů](34_Dokumenty.md) a
akcí **Připojit úhradu** přiřadit volnou bankovní či pokladní úhradu. Úhrady
se párují ručně, automaticky je aplikace nepřiřazuje. Výběr nabízí platby
seřazené podle síly shody a u každé ukáže důvod:

| Pořadí | Shoda |
|---|---|
| 1 | variabilní symbol, účet protistrany a částka splátky |
| 2 | variabilní symbol |
| 3 | účet protistrany a částka splátky |
| 4 | částka splátky, platba nejvýše 15 dní od splatnosti splátky |
| 5 | celý zbývající dluh |
| 6 | částka splátky dál od splatnosti |
| 7 | jen účet protistrany |

Částka splátky znamená neuhrazený zbytek splátky z kalendáře, tolerance je
±0,01 Kč. Bez hledání se pod shodami nabízejí i nejnovější volné platby.
Částečná úhrada sníží zbývající částku, přeplatek aplikace odmítne. U
zaúčtované položky musí být bankovní nebo pokladní zápis veden proti stejnému
účtu pohledávky či závazku. Úhrady v cizí měně tato agenda nepřiřazuje,
vyžadují samostatné kurzové vypořádání.
Storno účetního zápisu platby znovu otevře zůstatek. Pokud stejnou platbu
zaúčtujete znovu, přiřaďte ji k položce znovu ve stejné částce. Původní
úhrada tak zůstane správně započtená v historickém saldu před stornem.

Má-li položka **splátkový kalendář**, každá úhrada se váže ke splátce. Platba
ve výši neuhrazeného zbytku některé splátky uhradí tuto splátku, jiná platba
hradí první neuhrazenou splátku. Část platby nad zbytek splátky pokryje další
neuhrazené splátky podle pořadí; platba zůstává u položky jedním řádkem
a saldo se nemění. Stejně podle pořadí se započte i část uhrazená mimo banku a
pokladnu. Tabulka splátek ukazuje u každé splátky stav **Neuhrazeno**,
**Částečně** (se zbývající částkou), **Uhrazeno** nebo **Po splatnosti**.
Podle zbytků splátek počítá i předpověď cash-flow.

Seznam lze řadit kliknutím na názvy sloupců podle názvu, protistrany,
splatnosti, částky, zůstatku a stavu. Řazení zahrnuje celý filtrovaný seznam.
Další položky se načítají při posunu dolů. Na počítači zůstávají filtry a názvy
sloupců viditelné, seznam má vlastní posuvníky a tlačítko **Další** nezabírá místo.
Na dotykovém zařízení je dostupné i ruční načtení tlačítkem.

Na detailu můžete založit **opakování** z aktuální položky. Zvolte měsíční,
čtvrtletní nebo roční četnost a případné koncové datum. Denní plánovač
vytváří termíny na 90 dní dopředu jako samostatné koncepty, které účetní před zaúčtováním
zkontroluje. Volba **Automaticky účtovat vzniklé položky** je dostupná po
potvrzení nebo zaúčtování zdrojového dokladu.
V podvojném účetnictví zapnutí nebo obnovení automatického rozvrhu a jeho
ruční generování navíc vyžaduje oprávnění k účtování do deníku.
S touto volbou plánovač potvrdí položky v daňové evidenci nebo zaúčtuje položky v podvojném účetnictví nejdříve v den
jejich vzniku. Budoucí položky zůstávají koncepty, uzamčené datum nebo uzavřené
období automatika neobchází. Při chybě se změny daného běhu rozvrhu vrátí
a plánovač ji zaznamená. Bez této volby se účtuje ručně. Koncepty lze vytvořit i ručně do
zvoleného data. Opakované spuštění stejný termín nevytvoří znovu. Rozvrh lze
pozastavit a obnovit. Každý vygenerovaný doklad má vlastní přílohy a úhrady.
Přílohy zdrojového dokladu, například smlouva, se při vytvoření konceptu
automaticky propojí i s novým dokladem; soubor zůstane jediný ve skladu
dokumentů. Zdrojový doklad rozvrhu nelze smazat.
Vygenerovaný koncept lze zrušit; zůstane v rozvrhu jako zrušený termín,
takže jej další spuštění nevytvoří znovu.

**Splátkový kalendář** rozděluje splatnost jedné položky do 2 až 120 termínů.
Součet splátek musí být přesně roven částce dokladu. V předpovědi cash-flow
se každá splátka objeví ve svém termínu, přijaté úhrady se odečítají od
nejstarší splátky. V účetním deníku zůstává jediný zápis za celý doklad.
Úhrady splátek samy nevytvářejí znovu náklad nebo výnos. Pro pravidelné
samostatné doklady použijte opakování; pro splácení jednoho závazku kalendář.

Obě možnosti podporuje REST API. Rozvrh založíte přes
`POST /api/v1/accounting/other-items/{item_id}/schedule` s četností a volbou
`auto_post`. Volbu změníte přes `PUT /api/v1/accounting/other-items/schedules/{id}/status`.
`POST /api/v1/accounting/other-items/schedules/{id}/generate` s datem `through`
vytvoří chybějící termíny a při zapnuté automatice potvrdí již vzniklé položky;
odpověď obsahuje `created_ids` a `posted_ids`. Splátkový kalendář jednoho
dokladu nastavíte přes `PUT /api/v1/accounting/other-items/{item_id}/installments`
se seznamem `items` obsahujícím `due_on` a `amount`. Samotné založení rozvrhu
neúčtuje a nepřiřazuje žádnou bankovní úhradu.
Po přiřazení první úhrady už nelze kalendář změnit.

Mzdy a daňové zálohy se v přehledu zobrazují ze svých modulů. Jejich částku
ani úhradu zde neupravujte; použijte odkaz na zdroj. Mzdy, DPH a daň z příjmů
se zde ručně nezakládají. Přehled budoucích plateb vstupuje do předpovědi
cash-flow. Na stránkách Tržby, Náklady a Zisk je zvlášť uveden výsledkový
dopad ostatních položek podle výnosového či nákladového protiúčtu. Rozvahové
položky, například kauce a jistina úvěru, tento dopad nemají.

## 50.11 Související kapitoly

<!-- cols: 55 45 -->
| Co řešíte | Kapitola |
|---|---|
| Termíny a stav práce napříč firmami | [Přehled firem](51_Prehled_firem.md) |
| Automatické návrhy, schvalování, pravidla a historie | [Automat](53_Automat.md) |
| Zápisy, ruční zápis, storno, zámek k datu | [Účetní deník](52_Ucetni_denik.md) |
| Doklady a pohyby, které čekají na ruční rozhodnutí | [K doúčtování](54_Rucni_fronta_doctovani.md) |
| Bankovní pohyby bez podkladu a doklady po splatnosti | [Úplnost dokladů](61_Uplnost_dokladu.md) |
| Průběžná kontrolní brána za měsíc, kvartál či vlastní rozsah | [Měsíční kontrola](62_Mesicni_kontrola.md) |
| Sestavení, PDF a odeslání klientského reportu | [Měsíční přehled](63_Mesicni_report.md) |
| Účtový rozvrh a kontrola účtu | [Účtový rozvrh](66_Ucetni_osnova.md) |
| Předkontace, šablony zápisů, pravidla nákladů a bankovní pravidla | [Šablony a pravidla](65_Sablony.md), [Nástroje](73_Ucetni_nastroje.md) |
| Hlavní kniha | [Hlavní kniha](55_Hlavni_kniha.md) |
| Předvaha | [Obratová předvaha](56_Obratova_predvaha.md) |
| Rozvaha | [Rozvaha](57_Rozvaha.md) |
| Výsledovka | [Druhová](58_Vysledovka_druhova.md) a [účelová](59_Vysledovka_ucelova.md) |
| Otevřené pohledávky a závazky | [Saldokonto](60_Saldokonto.md) |
| Jiné pohledávky a závazky bez faktury | [Ostatní pohledávky a závazky](52_Ucetni_denik.md#521414-ostatni-pohledavky-a-zavazky) |
| Měsíční kontroly, úplnost dokladů, K1–K10 a inventarizace | [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md) |
| Mzdová rekapitulace, kontace a mzdový list | [Mzdy](64_Mzdy.md) |
| Úplný mzdový modul a měsíční mzdový běh | [Úplné mzdy](75_Uplne_mzdy.md) |
| Dlouhodobý i drobný majetek, odpisy, inventární karty | [Majetek a odpisy](28_Majetek.md) |
| Uzávěrkový průvodce, kontroly K1–K10, balíček, archiv | [Účetní období a uzávěrka](72_Uzaverka.md) |
| DPH přiznání, kontrolní hlášení, kniha DPH | [Výkazy DPH](41_Vykazy_DPH.md), [Kniha DPH](42_Kniha_DPH.md) |
| Bankovní a pokladní zaúčtování | [Banka](29_Banka.md), [Bankovní účty](30_Bankovni_ucty.md), [Pokladna](32_Pokladna.md) |
