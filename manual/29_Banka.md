# 29. Banka: import výpisů a párování plateb

> Návod, jak do MyÚčta nahrát bankovní výpis, spárovat platby s fakturami,
> opravit chybné párování a v podvojném účetnictví zaúčtovat pohyby, které
> s fakturou nesouvisí. Pro účetní a každého, kdo za firmu hlídá platby.
> Místo ručního označování faktur jako zaplacených naimportujte GPC/ABO nebo
> podporovaný PDF výpis z banky.

Systém pohyby deduplikuje, nabídne jejich párování s doklady a v podvojném účetnictví připraví bezpečné zaúčtování. Návrhy zaúčtování, položky vyžadující zásah a historii automatických rozhodnutí najdete souhrnně v kapitole [Automat účtování](53_Automat.md).

Výpisy najdete v `Peníze → Bankovní účty`, záložka **Bankovní výpisy**. Konfigurace bankovních účtů, IMAP schránek a parserů má vlastní kapitolu [Bankovní účty a e-mailová avíza (IMAP)](30_Bankovni_ucty.md) a leží na dalších záložkách téže stránky. Vedle výpisů umí systém zpracovávat i bankovní e-mailová avíza z IMAP schránky; hodí se, když banka posílá oznámení o příchozí platbě rychleji než pravidelný výpis.

## 29.1 Kdy to potřebujete

- Banka vám poslala nebo si stahujete výpis a chcete, aby se faktury samy označily jako zaplacené.
- Klient zaplatil dvě faktury jednou platbou, nebo naopak jednu fakturu po částech.
- Platba se nespárovala, protože chybí variabilní symbol.
- Chybně spárovaná platba musí zpět.
- V bance je platba, ke které nemáte přijatou fakturu.
- V podvojném účetnictví jsou v bance poplatky, úroky, odvody nebo převody mezi účty a je třeba je zaúčtovat.
- Po importu aplikace hlásí, že párování nebo zaúčtování pohybů selhalo.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| denně nebo týdně | Nahrát nový výpis (nebo nechat stáhnout přes API) | **Nahrát GPC/ABO nebo PDF**, [§ 29.3](#293-krok-za-krokem-nahrani-vypisu) |
| po každém importu | Projít nespárované platby | filtr **Nespárováno** v detailu výpisu nebo záložka **Všechny pohyby**, [§ 29.4](#294-krok-za-krokem-parovani-plateb-s-fakturami) |
| po importu (podvojné účetnictví) | Schválit návrhy zaúčtování | záložka **K zaúčtování**, [§ 29.8](#298-krok-za-krokem-zauctovani-pohybu-podvojne-ucetnictvi) |
| při opakované platbě bez faktury | Založit účtovací pravidlo | **…** u pohybu → **Vytvořit účtovací pravidlo**, [§ 29.9](#299-krok-za-krokem-pravidlo-pro-opakovane-platby) |
| před uzávěrkou | Zkontrolovat, že nezbývají nezaúčtované pohyby | filtr **Výpisy s nezaúčtovanými pohyby** |

## 29.2 Než začnete

1. **Bankovní účet.** Číslo účtu z výpisu musí patřit některé z měn firmy. Účty zadáte v `Peníze → Bankovní účty`, záložka **Měny a účty** (viz [Bankovní účty](30_Bankovni_ucty.md)).
2. **Zaúčtované doklady.** Aby se platba v podvojném účetnictví zaúčtovala přímo, musí mít faktura svůj zaúčtovaný předpis (viz [§ 29.13.13](#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)).
3. **Oprávnění.** Párování vyžaduje oprávnění ke čtení banky a k zápisu párování. Smazání výpisu smí jen administrátor. Poznámku u pohybu přidává administrátor nebo účetní.
4. **Podvojné účetnictví** pro záložky **K zaúčtování** a pravidla účtování. Daňová evidence žádný deník nemá, tyto záložky ani tlačítka se v ní nezobrazují a banka funguje jen jako párování plateb.

## 29.3 Krok za krokem: nahrání výpisu

GPC (ABO) je standardní český formát pro elektronickou výměnu výpisů. Umí ho exportovat KB, Fio Bank, ČSOB, Raiffeisenbank, Česká spořitelna, mBank a další. Postup stažení je v každé bance trochu jiný:

| Banka | Cesta v internet bankingu |
|---|---|
| **KB** | Účet → Historie pohybů → Export → formát „GPC ABO“ |
| **Fio** | Přehled účtu → Stažení dat → formát „GPC“ |
| **ČSOB** | Účet → Výpisy → Stáhnout → formát „ABO“ |
| **Raiffeisen** | Detail účtu → Pohyby → Export → ABO formát |
| **ČS** | Detail účtu → Výpisy → formát „ABO“ |

Soubor má typicky příponu `.gpc` nebo `.abo`, někdy `.txt`, a velikost řádově 10-100 KB na měsíc.

1. Stáhněte výpis z banky (GPC/ABO nebo PDF).
2. Otevřete `Peníze → Bankovní účty`, záložku **Bankovní výpisy**.
3. Klikněte na **Nahrát GPC/ABO nebo PDF**.
4. Přetáhněte soubor do okna, nebo klikněte a vyberte ho. Najednou můžete vybrat i více souborů, klidně GPC i PDF dohromady (rozhoduje přípona).
5. Po nahrání si přečtěte výsledek.

![Upload výpisu](img/11_banka_upload.webp)

**Jak poznáte, že je hotovo:** Aplikace ukáže výsledek ve tvaru „Importováno: 12 transakcí, automaticky spárováno 8.“ (u více souborů najednou „Hromadný upload: … importováno, … duplicit.“). Výpis je v seznamu a faktury, které se spárovaly, jsou označené jako zaplacené. Jestliže se některé pohyby shodují s již evidovanými, zobrazí se samostatné varování s počty **nalezeno / založeno / přeskočeno jako duplicita**. Zkontrolujte je, zvlášť pokud nejde o očekávaný překryv výpisů.

> [!TIP]
> Místo GPC můžete nahrát **PDF výpis**. Pro Creditas, ČSOB, KB, MONETA a Raiffeisenbank stačí nahrát rovnou PDF: systém ho deterministicky rozparsuje na transakce (bez AI) a ověří, že součet sedí na počáteční a konečný zůstatek z hlavičky (na haléř přesně). Dál to funguje úplně stejně jako GPC. Je to užitečné u bank, které GPC/ABO export nenabízejí (Banka CREDITAS), nebo u účtu, který ho nemá zapnutý.

Co aplikace při importu udělá, popisuje [§ 29.13.1](#29131-import-vypisu-kontroly-a-vysledek). Vypisuje-li aplikace po nahrání varování, že se párování nepodařilo dokončit, postupujte podle [§ 29.10](#2910-krok-za-krokem-vypis-s-nezpracovanymi-pohyby).

## 29.4 Krok za krokem: párování plateb s fakturami

Většina plateb se spáruje sama (podle variabilního symbolu, částky a čísla faktury). U zbytku postupujte takto.

**Zkontrolujte, co zbývá:**

1. V seznamu výpisů klikněte na řádek výpisu, otevře se detail.
2. V detailu nastavte filtr **Nespárováno**. Pod stavem u každé platby je vidět důvod, proč ji automat nevzal, například „Žádná vydaná faktura s tímto VS“, „Chybí variabilní symbol“ nebo „Částka nesedí s fakturou“.

**Potvrďte návrh aplikace:**

3. Pod transakcí uvidíte **skórovaný návrh** s kandidáty. U každého je slovní jistota a důvody (shodný VS, zbývající částka, číslo faktury ve zprávě, známý účet protistrany nebo blízké datum splatnosti).
4. U správného kandidáta klikněte na **Spárovat**. Chybný návrh zamítněte tlačítkem **Odmítnout**.

**Nesedí-li návrh, vyberte ručně:**

5. Klikněte na **Spárovat**, otevře se okno s vyhledávačem.
6. Do pole **Vyhledat jiný doklad** zadejte číslo dokladu, platební VS, dodavatele či odběratele nebo přesnou částku (můžete psát `1 234,50`).
7. Doklad vyberte a potvrďte.

Párování můžete zahájit i z detailu vydané či přijaté faktury akcí **Spárovat s bankou**: vyberte volný pohyb správného směru a potvrďte přiřazení. U ostatních pohledávek a závazků použijte **Připojit úhradu**.

**Jak poznáte, že je hotovo:** Transakce má stav **Ručně** (automaticky spárovaná **Auto OK**, částečná úhrada **Částečně**), ve sloupci **Faktura** je klikatelné číslo faktury a faktura je zaplacená, nebo částečně uhrazená (a zůstatek se sníží).

> [!TIP]
> Tlačítko **Otevřít** u spárované transakce přeskočí na navázanou fakturu (vydanou i přijatou). Tlačítko s ikonou oka otevře detail pohybu včetně protistrany, účtů, platebních symbolů, stavu, spárovaných faktur a nezkráceného popisu i poznámky. Najdete ho v detailu výpisu i na záložce **Všechny pohyby**, v tabulce i na mobilu.

Pravidla skóre a hledání jsou v [§ 29.13.8](#29138-rucni-parovani-pravidla-a-nesparovane-platby). Částečné platby a cizí měny viz [§ 29.13.7](#29137-castecne-platby-a-cizi-mena).

## 29.5 Krok za krokem: jedna platba na víc faktur

Zaplatil-li klient **víc vydaných faktur jednou platbou**, nebo vy zaplatili víc přijatých faktur jednou platbou, použijte sloučenou úhradu.

1. U platby klikněte na **Spárovat**. Pod vyhledávačem je sekce **Sloučená úhrada** s návrhy kombinací faktur (klient, jednotlivé faktury s částkami a datem, celkový součet).
2. Jsou-li faktury vystavené dál od sebe, klikněte nad návrhy na **Hledat v širším okně** (například ±14 dní), případně na **Hledat mezi všemi neuhrazenými fakturami**.
3. Víte-li o jedné faktuře, která do platby patří, vyberte ji v poli **Vyber fakturu a dohledej zbytek**. Návrhy se omezí na kombinace, které ji obsahují.
4. U správné kombinace klikněte na **Spárovat (N faktur)**.

**Jak poznáte, že je hotovo:** Každá faktura je uhrazená svým plným zbytkem a označená jako zaplacená. Všechny spárované faktury najdete také přímo u bankovního pohybu. Zrušení spárování smaže všechny platby té transakce a vrátí všechny faktury zpět mezi pohledávky.

Pravidla kombinací (okno ±7 dní, jeden klient, přijaté faktury od různých dodavatelů) viz [§ 29.13.9](#29139-sloucena-uhrada).

## 29.6 Krok za krokem: platba, která není platbou faktury

**Ignorování** (poplatky, převody mezi vlastními účty, refundace):

1. V detailu výpisu najděte transakci a klikněte na **Ignorovat**.
2. V potvrzovacím dialogu můžete doplnit vlastní poznámku (nejvýše 1000 znaků).
3. Potvrďte **Ignorovat**.

Stav a poznámka se aktualizují přímo v seznamu a nastavený filtr zůstane zachovaný. Při filtru **Nespárováno** transakce ze seznamu zmizí. Poznámka se zobrazuje u ignorovaného pohybu při příštím otevření výpisu, v tabulce i na mobilu. **Zrušit ignorování** vrátí pohyb mezi nespárované.

> [!TIP]
> **Daňová evidence:** ignorovaný pohyb v peněžním deníku zůstává, ignorování jen ruší párování. Zařaďte ho (poplatek, převod,
> soukromé) v peněžním deníku, nebo si na opakované pohyby založte pravidlo, které pohyb ignoruje i zařadí najednou. Viz
> [Daňová evidence - postupy](115_Danova_evidence_postupy.md#1156-krok-za-krokem-bankovni-poplatky-prevody-mezi-ucty-vklady-a-vybery).

**Vytvoření přijaté faktury z odchozí platby**, ke které ještě nemáte fakturu:

1. V detailu výpisu najděte odchozí transakci a klikněte na **Vytvořit fakturu**.
2. Vyberte existujícího dodavatele (nebo klikněte na **Nový dodavatel** a založte ho). Dodavatel se nezakládá automaticky, musíte ho potvrdit.
3. Potvrďte. Vznikne **koncept přijaté faktury** v hrubé částce platby (1 položka, 0 % DPH) a rovnou se otevře v editoru.
4. V editoru doplňte rozpad DPH, skutečné číslo dokladu a nahrajte PDF.

**Jak poznáte, že je hotovo:** Platba je spárovaná na nový koncept. Variabilní symbol z platby se předvyplní do pole VS. Číslo dokladu má dočasný placeholder ve tvaru `BANK-{id}`, který přepište na reálné číslo z faktury. Platba se spáruje jako vazba, jako zaplacenou ji potvrdíte až po dokončení faktury.

## 29.7 Krok za krokem: oprava chybného párování

1. V detailu výpisu najděte transakci a klikněte na **Zrušit spárování**. Otevře se potvrzovací dialog s datem, částkou a protistranou.
2. Potvrďte.

**Jak poznáte, že je hotovo:** Transakce je znovu ve stavu **Nespárováno**. Platba vzniklá párováním se z faktury odebrala a stav faktury se přepočítal ze zbývajících plateb. Faktura, kterou kryje jiná platba, zůstává zaplacená, jinak se vrátí na předchozí stav (vystaveno). Zaúčtovaný pohyb se stornoval.

> [!WARNING]
> V uzavřeném nebo zamčeném období storno nejde provést a párování zůstane beze změny. Nejdřív období otevřete, nebo počkejte na účetní.

Je-li přijatá faktura uhrazená nižší platbou, než zbývá, nabídne aplikace při ručním párování dvě možnosti: **Nechat částečně uhrazené**, nebo **Uhradit a vyrovnat rozdíl** (viz [§ 29.13.12](#291312-rucni-parovani-prijate-faktury-s-nizsi-platbou)).

## 29.8 Krok za krokem: zaúčtování pohybů (podvojné účetnictví)

Spárovaná platba faktury se zaúčtuje sama (viz [§ 29.13.13](#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)). Pohyby bez faktury (poplatky, úroky, odvody, převody) vyřídíte ve frontě.

1. Otevřete `Peníze → Bankovní účty`, záložku **K zaúčtování**. Odznáček na záložce ukazuje počet čekajících položek.
2. Výchozí podzáložka **Nezaúčtované pohyby** obsahuje všechny skutečné pohyby bez aktivního zápisu, tedy i ty, pro které automatika žádný návrh kontace nevytvořila.
3. U návrhu zkontrolujte kontaci (MD/D) a stručné **Proč** (název pravidla nebo shoda platby).
4. Klikněte na **Schválit** (vytvoří zápis v deníku) nebo na **Odmítnout** (návrh se zahodí a ke stejné transakci a pravidlu se už nenabídne). Víc řádků najednou vyřídíte přes **Schválit vybrané**.
5. Nesedí-li kontace, ikonou ozubeného kola u řádku přepište účty MD/D ještě před schválením.
6. Pohyb, pro který nemáte návrh, zaúčtujete přímo v detailu výpisu: u transakce klikněte na **Zaúčtovat…**.

**Jak poznáte, že je hotovo:** Pohyb má stav zaúčtování a odkaz na zápis v účetním deníku. Automaticky zaúčtovaný pohyb nese odznak **Automaticky**.

U už zaúčtovaného pohybu máte dvojici **Přeúčtovat** a **Zrušit zaúčtování**:

<!-- cols: 22 46 32 -->
| Akce | Co udělá | Kdy jde |
|---|---|---|
| **Přeúčtovat** | otevře řádky existujícího zápisu k opravě a zapíše opravenou kontaci; pohyb zůstane zaúčtovaný | i v zamčeném nebo uzavřeném období (tam vznikne storno a nový zápis) |
| **Zrušit zaúčtování** | zápis stornuje a pohyb vrátí nezaúčtovaný do fronty | jen když je období původního zápisu otevřené a nezamčené |

Nad transakcemi v detailu výpisu můžete filtrovat **Zaúčtování: vše / Nezaúčtované / Zaúčtované** pohyby, filtr se kombinuje s filtrem stavu párování. V detailu výpisu i na záložkách **Všechny pohyby** a **K zaúčtování** seřadíte pohyby klikem na hlavičku sloupce (datum, částka, náš účet, VS, protistrana, faktura, stav). První klik řadí vzestupně, druhý sestupně, třetí vrátí výchozí pořadí. Řadí se celý seznam, ne jen načtená stránka, a pohyby bez hodnoty (bez VS, bez faktury) jsou vždy na konci. Na mobilu je místo hlaviček výběr **Řadit podle**.

> [!TIP]
> Poznámku k zaúčtovanému pohybu přidáte v nabídce **…** u pohybu volbou **Poznámka**. Ukládá se k zápisu v účetním deníku (viz [§ 29.13.13](#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)).

## 29.9 Krok za krokem: pravidlo pro opakované platby

Opakují se platby bez faktury (odvody, bankovní poplatky, úroky, leasing)? Založte pro ně pravidlo, ať se zaúčtují samy.

> [!TIP]
> Tento postup platí pro podvojné účetnictví. V daňové evidenci se nic nezaúčtovává, pravidla tam pohyb ignorují a zařadí
> v peněžním deníku: `Daňová evidence → Pravidla bankovních pohybů`, viz [§ 74.9.16](74_Danova_evidence.md#74916-pravidla-bankovnich-pohybu).

**Z konkrétního pohybu (nejrychlejší):**

1. V nabídce **…** u pohybu klikněte na **Vytvořit účtovací pravidlo**. Otevře se plný editor pravidla předvyplněný protistranou, zprávou, směrem, měnou a kontací pohybu.
2. Upravte podmínky a účty. V polích MD a D hledáte účet podle čísla i názvu. Bankovní strana nabízí účty 221, nebankovní vynechává saldokonta.
3. Zaškrtněte **Použít i na další odpovídající nezaúčtované pohyby (N)**, chcete-li stejným pravidlem zaúčtovat i ostatní shodné pohyby v otevřených obdobích.
4. Uložte.

**Jak poznáte, že je hotovo:** Pohyb, ze kterého pravidlo vzniklo, je zaúčtovaný (u už zaúčtovaného se otevře přeúčtování s protiúčtem z pravidla ke kontrole). Pravidlo je v režimu **Automaticky** a další odpovídající platby účtuje bez potvrzení.

**Z nabídky pravidel:** Pravidla účtování se spravují v `Nástroje → Šablony účtování`, záložka **Pravidla účtování** (z fronty **K zaúčtování** na ně vede odkaz). Klikněte na **Nové pravidlo**, vyplňte název, směr a aspoň jedno kritérium shody a kontaci. Pomocí **Otestovat na historii** ověříte, kolika transakcím za posledních 12 měsíců pravidlo sedí. Pravidlo založené z nabídky pravidel vždy jen **navrhuje** (režim **Návrh**). Na **Automaticky** ho přepnete tlačítkem **Povýšit na automatiku**.

Pravidla jsou popsána v [§ 29.13.14](#291314-pravidla-uctovani-opakovanych-plateb). Rychlý start nabízí i tlačítko **Ze šablony** (odvody, bankovní poplatky, úroky, nájem, předplatné).

## 29.10 Krok za krokem: výpis s nezpracovanými pohyby

Načtení výpisu probíhá ve dvou krocích: uloží se výpis a pohyby, potom se pohyby zpracují (spárují a zaúčtují). Když druhý krok selže, pohyby zůstanou uložené, ale nezpracované.

1. Všimněte si upozornění: při ručním nahrání varování, v seznamu štítek **Nezpracováno**, v detailu žlutý rámeček s počtem pohybů a textem chyby a na nástěnce v **Akcích pro tebe**.
2. Otevřete výpis.
3. Klikněte na **Přepárovat výpis** (tlačítko je přímo v upozornění i v liště akcí).

**Jak poznáte, že je hotovo:** Upozornění zmizí. Pokud se chyba opakuje, text chyby v upozornění pomůže dohledat příčinu.

> [!WARNING]
> Opakované nahrání stejného souboru pohyby nezpracuje, protože už jsou v evidenci. Použijte **Přepárovat výpis**.

## 29.11 Krok za krokem: načítání pohybů přímo z banky

Pro vybrané banky umí MyÚčto stahovat pohyby přes bankovní API, aniž byste museli nahrávat soubory. Napojení se zakládá v `Peníze → Bankovní účty`, záložka **Měny a účty**, sekce **Přímé napojení na banku** (postup viz [§ 30.5](30_Bankovni_ucty.md#305-krok-za-krokem-prime-napojeni-banky)). Pak:

1. U účtu klikněte na **Načíst pohyby**.
2. Prázdné pole **Od data** naváže na poslední načítání. Pro ruční načtení vyplňte obě data, nejvýše 31 dní.

**Jak poznáte, že je hotovo:** Hláška „Načtení dokončeno. Nové pohyby: …“ a odkaz **Otevřít načtený výpis**. Dál se s pohyby pracuje stejně jako s nahraným GPC. Souhrn možností, limitů a zabezpečení je v [§ 29.13.20](#291320-prime-napojeni-na-banku-api).

## 29.12 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Po nahrání je výpis označený **Nezpracováno** | Párování nebo zaúčtování selhalo | Klikněte na **Přepárovat výpis** ([§ 29.10](#2910-krok-za-krokem-vypis-s-nezpracovanymi-pohyby)). |
| Import odmítl soubor jako duplicitu | Stejný soubor (SHA-256) už je nahraný | Nic neděláte. Překrývající se pohyby z jiných výpisů se přeskočí automaticky. |
| Číslo účtu z hlavičky výpisu není u firmy | Účet není mezi bankovními účty firmy | Přidejte účet v `Peníze → Bankovní účty`, záložka **Měny a účty**. |
| PDF výpis se nenaimportuje | Součet pohybů nesedí na rozdíl zůstatků, nebo banka není podporovaná | Použijte GPC/ABO, nebo PDF jiného formátu výpisu. |
| Platba zůstala **Nespárováno**, důvod „Chybí variabilní symbol“ | Platba nemá VS | Spárujte ručně ([§ 29.4](#294-krok-za-krokem-parovani-plateb-s-fakturami)). |
| Důvod „Žádná vydaná faktura s tímto VS“ | Platba přišla dřív, než faktura vznikla | Počkejte na fakturu a použijte **Přepárovat výpis**, nebo spárujte ručně. |
| Důvod „Částka nesedí s fakturou“ | Rozdílná částka, kurz nebo bankovní poplatek | Spárujte ručně, případně jako částečnou platbu ([§ 29.13.7](#29137-castecne-platby-a-cizi-mena)). |
| Platba v EUR a faktura v CZK se nespárovaly | Jiná měna | Spárujte ručně. |
| Platba kartou bez VS zůstala nespárovaná | Nejde o jediný volný doklad a jediný volný pohyb téže částky | Spárujte ručně nebo vytvořte doklad z výpisu ([§ 29.6](#296-krok-za-krokem-platba-ktera-neni-platbou-faktury)). |
| Návrh spárování se nepotvrzuje automaticky | Přeplatek, podezření na překlep ve VS, rozdílná měna, proforma nebo částka snížená o poplatek | Zkontrolujte a potvrďte ručně. Tyto případy se nikdy nepotvrzují automaticky. |
| Ruční párování faktury se odmítne | Faktura je už plně kryta evidovanými platbami | Zrušte jinou platbu, nebo fakturu nepárujte. |
| Úhrada faktury čeká ve frontě **K zaúčtování** na kontrolu dvojí úhrady | Platby faktury přesahují částku k úhradě | Zkontrolujte platby u faktury. |
| Korunová platba cizoměnové faktury čeká ve frontě **K zaúčtování** | Částka je mimo kurzovou toleranci | Ověřte ručně a zaúčtujte. |
| Zaúčtování pohybu se nevytvoří, vznikne jen návrh | Uzavřené období, chybí zaúčtovaný předpis faktury, faktura označená jako zaplacená bez evidované platby, křížová měna | Přečtěte poznámku u návrhu a podle ní postupujte ([§ 29.13.13](#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)). |
| Zrušení spárování se nedokončí, hláška o uzavřeném období | Zápis je v uzavřeném nebo zamčeném období | Otevřete období a zopakujte. |
| Smazání výpisu se odmítne | Pohyb je v uzavřeném období, k platbě je vystavený daňový doklad, výpis dokládá vyplacení mezd, nebo jde o měsíční výpis bankovního API | Odstraňte příčinu (viz [§ 29.13.2](#29132-seznam-vypisu-hledani-a-smazani)). |
| Tlačítko **GPC** u API výpisu není dostupné | Chybí výchozí zůstatek nebo výpočet nelze ověřit | Nahrajte originální GPC z banky ([§ 29.13.5](#29135-zustatky-z-api-a-export-gpc)). |
| Zaúčtování odmítne převod mezi účty v různých měnách | Chybí kurzový rozdíl | Zaúčtujte ručně. |

## 29.13 Podrobnosti a pravidla

### 29.13.1 Import výpisu: kontroly a výsledek

Přímé API načítání sdružuje pohyby do jednoho výpisu za měsíc, účet a měnu. V přehledu i detailu vidíte vypočtený nebo bankou potvrzený konečný zůstatek. Tlačítko **GPC** stáhne dostupný bankovní originál, případně vypočtený export. Pokud chybí výchozí zůstatek nebo výpočet nelze ověřit, vypočtený export není dostupný.

Při načtení nebo opakovaném párování výpisu se doplní chybějící evidence úhrady u přesně spárované příchozí platby, která pokrývá celou dosud neuhrazenou fakturu. Existující platby a ruční označení faktury jako zaplacené se zachovají.

Jednotlivé odpovědi banky se v seznamu nezobrazují jako další překrývající se výpisy. GPC pro účet s API evidencí se začlení do stejného měsíce. Pokud pokrývá všechny jeho pohyby, nahradí API přehled jako hlavní bankovní výpis pod stejným odkazem. Zachová se párování i zaúčtování a originální GPC lze stáhnout. Neúplný GPC nesmaže pohyby, které už byly načtené přes API. PDF příloha úplného podkladu zůstane dostupná u měsíčního výpisu. Další PDF původních podkladů najdete v nabídce akcí jeho detailu.

Oficiální GPC nebo PDF výpis banky můžete k účtu napojenému přes API nahrát i dodatečně. Pohyby, které už přišly z napojení, se znovu nezakládají ani nepřepárují; výpis se jen přiloží k měsíčnímu výpisu jako doklad banky. GPC s čísly účtů ve vnitřním formátu banky (KB, MONETA) systém převede sám.

Po nahrání proběhne tento sled kroků:

1. **Kontrola duplicit.** SHA-256 odmítne opakovaný stejný soubor; stabilní otisk jednotlivých pohybů navíc přeskočí transakce, které se opakují v překrývajícím se denním a měsíčním výpisu. Několik skutečných pohybů se shodným datem, částkou, VS a popisem v jednom souboru se přitom zachová jako samostatné transakce (rozhoduje i pořadí jejich výskytu v souboru).
2. **Validace bankovního účtu.** Server zkontroluje, že číslo účtu z hlavičky výpisu patří některé z měn aktuální firmy.
3. **Parsing transakcí.** Přečtou se všechny řádky.
4. **Auto-matching.** Nejprve se u všech pohybů vyhodnotí silné signály, zejména VS, zbývající částka, číslo dokladu ve zprávě a známý účet protistrany. Ve druhém průchodu se u dosud volných odchozích plateb bez VS zkusí přesná shoda částky, měny a data. Jednoznačná shoda se potvrdí, slabší se jen nabídne. Příchozí platba se k vydané faktuře páruje podle čísla faktury i podle **platebního variabilního symbolu**, pokud ho faktura má vyplněný ([§ 15.2.5](15_Faktura_editor.md)). Stejný platební VS může mít víc faktur, například u pravidelné fakturace. Rozhodne pak částka a měna mezi nezaplacenými fakturami. Když se ani tak nenajde právě jedna faktura, platba se automaticky nepřiřadí a zůstane k ručnímu spárování.
5. **Update faktur.** Plně uhrazený doklad dostane stav zaplaceno a datum úhrady podle bankovní transakce; nižší platba se zapíše jako částečná a sníží zbývající částku.

Výsledek rozlišuje počet pohybů nalezených v souboru, počet skutečně založených a počet automaticky spárovaných. U dávkového nahrání se přeskočené pohyby sečtou do společného varování.

Pokud měsíční GPC obsahuje platby již načtené z API, připojí se k existujícím pohybům. Rozdílné bankovní reference se propojí automaticky, pokud kromě účtu, měny, data a částky souhlasí protiúčet s variabilním symbolem nebo dostatečně podrobný popis platby. Rozdíly v mezerách, interpunkci a doplněném názvu před oddělovačem se zohledňují. Každá platba musí mít jediný protějšek v obou zdrojích. Samotné datum a částka bez dalších údajů mohou vyžadovat potvrzení nalezených dvojic. Před potvrzením porovnejte údaje obou zdrojů; původní párování a zaúčtování zůstane zachované. Podrobnosti jsou v kapitole [Bankovní účty](30_Bankovni_ucty.md).

#### 29.13.1.1 Filtry, řazení a export nespárovaných

V záložce **Všechny pohyby** lze kombinovat hledání, rok, vlastní účet a stav spárování, v podvojném účetnictví také stav zaúčtování. Částku lze zadat i jako `1 234,50`; hledá se přesná částka bez ohledu na znaménko. Po návratu z deníku se filtry a řazení obnoví, pro každou firmu zvlášť. U zaúčtovaných pohybů se zobrazuje i protiúčet.

Při posouvání tabulky zůstávají filtry a názvy sloupců viditelné. Další pohyby se načítají automaticky při přiblížení ke konci seznamu. Na počítači tlačítko **Další** nezabírá místo pod výpisem; na dotykovém zařízení je dostupné i ruční načtení.

**Stáhnout nespárované (XLSX)** v záložce **Všechny pohyby** exportuje všechny nespárované pohyby odpovídající zvolenému roku, hledání, účtu a zaúčtování, včetně dosud nenačtených stránek a poznámek z deníku. Každý řádek nese vlastní bankovní účet. Směr platby vyjadřuje znaménko a barva částky. Filtr nespárovaných i export zahrnují platby kartou, které čekají na doklad na mezičlenu, například `378.101`. Uzavřené platby bez dokladu, daně, poplatky, mzdy a vlastní převody se mezi pohyby čekající na spárování nepočítají.

### 29.13.2 Seznam výpisů, hledání a smazání

Záložka **Bankovní výpisy** ukáže historii. Nad seznamem lze hledat také uvnitř transakcí všech výpisů:

- podle libovolné části čísla protiúčtu nebo IBANu (mezery, pomlčky a lomítka se při porovnání ignorují),
- podle zákazníka nebo dodavatele, a to přes spárovanou vydanou či přijatou fakturu nebo evidovaný účet obchodního partnera,
- podle přesné částky bez ohledu na znaménko, takže například `1 500` najde příchozí `1 500` i odchozí `-1 500`.

Filtry prohledávají všechny příchozí i odchozí platby a kombinují se; výsledkem jsou výpisy obsahující alespoň jednu transakci, která splňuje všechna zadaná kritéria. V podvojném účetnictví lze navíc zvolit **Výpisy s nezaúčtovanými pohyby**. Seznam u každého výpisu ukáže jejich počet. Ignorované položky a provizorní e-mailová avíza se do tohoto počtu nezahrnují.

| Sloupec | Význam |
|---|---|
| Datum | Datum výpisu |
| Číslo | Číslo výpisu z banky |
| Účet | Číslo účtu / IBAN |
| Měna | CZK / EUR / … |
| Příchozí | Suma kreditních transakcí |
| Odchozí | Suma debetních transakcí |
| Spárováno | `12/14`: 12 ze 14 transakcí spárováno na faktury |
| Importováno | Datum + uživatel |

Protistranu lze vyhledat psaním do filtru. Nabídka ukazuje nejvýše 50 shod; další najdete upřesněním hledání. Vybraná protistrana zůstává součástí odkazu i uloženého filtru.

**Smazání výpisu** (jen administrátor) smaže výpis i s jeho transakcemi. Každý spárovaný pohyb se předtím uvolní stejně jako při **Zrušit spárování**: platba vzniklá párováním se z faktury odebere, faktura se vrátí do stavu podle zbývajících plateb a zaúčtovaný pohyb se v podvojném účetnictví stornuje. Opětovný import téhož výpisu pak pohyby spáruje a zaúčtuje znovu, bez dvojí úhrady. Smazání se odmítne, když by po něm v evidenci něco nesedělo:

- pohyb je zaúčtovaný v uzavřeném nebo zamčeném období, takže jeho zápis nejde stornovat,
- k platbě z pohybu je vystavený daňový doklad k přijaté platbě (nejdřív ho smažte nebo stornujte),
- výpis slouží jako doklad o vyplacení mezd,
- jde o měsíční výpis bankovního API (průběžně se doplňuje z banky).

Měsíční výpis API skládá pohyby z jednotlivých stažení. Jejich **zdrojové výpisy** najdete v detailu měsíčního výpisu v nabídce **…**. Zdrojový výpis, který přinesl duplicitu (například po opětovném připojení banky), smažete tlačítkem **Smazat zdrojový výpis**. Výpis se odpojí od měsíce, jeho vlastní pohyby se uvolní a smažou a měsíční výpis se přepočítá. Ostatní zdrojové výpisy zůstanou beze změny. Smazání se odmítne, když pohyb dokládá ještě jiný import.

### 29.13.3 Všechny pohyby

Záložka **Všechny pohyby** je společný přehled transakcí napříč výpisy, účty a roky. Má ji každá firma bez ohledu na způsob vedení účetnictví, stačí nahrané výpisy. Ukazuje i již spárované a ignorované pohyby. U každého řádku vidíte náš zdrojový účet, účet protistrany, výpis a párování. Firma s podvojným účetnictvím navíc vidí stav zaúčtování a protiúčet a na rozdíl od fronty **K zaúčtování** i zaúčtované pohyby. Filtr **Stav** nabízí stejné stavy párování jako detail výpisu. Pod **Nespárováno** jsou pouze pohyby čekající na fakturu; vlastní převody, mzdy a pohyby zaúčtované mimo saldokonto se vynechají. Filtr **Ignorováno** ukáže i dříve ignorované položky.

Akce jsou stejné jako v detailu konkrétního výpisu: otevření nebo zrušení párování, rozdělené párování, vytvoření dokladu, přiložení podkladu, vyžádání dokladu, ignorování a v podvojném účetnictví i ruční zaúčtování. Po provedené akci se aktualizuje tentýž řádek; není nutné dohledávat původní výpis. Filtry podle našeho účtu, data, částky, protistrany, párování a v podvojném účetnictví i zaúčtování lze kombinovat.

### 29.13.4 Výpis s nezpracovanými pohyby

Načtení výpisu probíhá ve dvou krocích. Nejdřív se uloží výpis a jeho pohyby, potom se pohyby zpracují: převezmou párování z e-mailových avíz, spárují se na faktury a zaúčtují. Když druhý krok selže (chyba při párování, zaúčtování nebo výpadek databáze), pohyby zůstanou v evidenci nezpracované. Platí to pro ruční nahrání, stažení z napojené banky, skenování adresáře i PDF výpis z e-mailu. Upozornění viz [§ 29.10](#2910-krok-za-krokem-vypis-s-nezpracovanymi-pohyby).

### 29.13.5 Zůstatky z API a export GPC

U pohybů z API tlačítko **GPC** vytváří export ve standardním formátu GPC (ABO) s kódováním CP1250 a řádky 074/075/078/079, který načte většina účetních programů bez ohledu na banku. Export obsahuje všechny evidované zaúčtované pohyby stejného účtu a měny od začátku měsíce do data zvoleného výpisu. Není omezený stránkováním ani filtrem tabulky. Avíza se nezahrnují a pohyb sdílený mezi API a GPC se započítá jednou. Původní JSON z API slouží interně, v uživatelském rozhraní se nenabízí ke stažení (u Raiffeisenbank lze původní odpověď stáhnout jako JSON).

Výpočet navazuje na poslední předchozí GPC nebo PDF se známým konečným zůstatkem. Přičte příjmy a odečte výdaje, včetně evidovaných pohybů mezi výchozím výpisem a začátkem měsíce. Pokud u API účtu není žádný známý počáteční ani konečný zůstatek, výpočet začíná od nuly a zahrne všechny evidované pohyby účtu v dané měně až do data výpisu. Pohyby před začátkem měsíce tvoří jeho počáteční zůstatek. Tento výchozí předpoklad odpovídá novému účtu s nulovým zůstatkem; u účtu se starší historií nahrajte předchozí bankovní výpis. Známý zůstatek se nulou nenahrazuje.

Zůstatek je označený jako **vypočtený** a předpokládá úplnou evidenci pohybů. Po nahrání originálního bankovního výpisu ke stejnému koncovému dni se porovná s bankovním zůstatkem. Shoda se zobrazí jako potvrzená, rozdíl zablokuje nový export a ukáže částku ke kontrole. Výpočet probíhá při čtení, takže není potřeba ruční přepočet. Původní zůstatky, párování ani účetní zápisy se nepřepisují a nevytváří se dorovnávací platba.

Export má označení MYUCTO EXPORT a není originálním výpisem banky. Zpětný import tohoto exportu do MyÚčta je odmítnutý, aby se vypočtené údaje nevydávaly za bankou potvrzené. Pro ověření nahrajte originální GPC z banky. Dlouhé bankovní reference se přesouvají do doplňujícího textu a základní řádek dostává lokální identifikátor. Formát není totožný s KB KM ani s rozšířeným ABO České spořitelny; v cílovém programu zvolte profil GPC kompatibilní s ČSOB. Zahraniční protiúčet, který se nevejde do domácího pole, se uvádí v popisu. Delší popisy se zkracují na kapacitu formátu.

Technické specifikace: [ČSOB GPC](https://www.csob.cz/portal/documents/10710/1927786/format-gpc.pdf) a [CREDITAS GPC](https://www.creditas.cz/files/popis-formatu-abo-gpc-pro-platebni-prikazy-172021.pdf).

Po nahrání originálního GPC se jeho pohyby propojí s již evidovanými pohyby z API. Jejich ID, párování i zaúčtování zůstávají zachované. Pokud GPC pokrývá pohyby zobrazeného období, detail API nabídne odkaz na bankovní výpis a tlačítko GPC stáhne originál místo dopočítaného exportu. Původní API podklad zůstává zachovaný pro dohledatelnost. Nejednoznačné shody import zastaví k prověření. Samotná PDF příloha u API výpisu bez zpracovaných zůstatků nepotvrzuje výpočet; v takovém případě je dopočítaný export zablokovaný, dokud jej nenahradí GPC.

### 29.13.6 Detail pohybu, zaúčtování a mzdy

Dialog **Zaúčtovat** předvyplňuje analytiku vlastního bankovního účtu a u spárované úhrady kontaci podle faktury. Používá stejný výpočet jako účetní zpracování, včetně víceřádkového rozúčtování. Pokud nelze kontaci bezpečně připravit, zobrazí důvod a odeslání zablokuje. Po ručním zrušení zaúčtování zůstává faktura spárovaná a kontaci lze znovu nabídnout; zápis se neobnoví samotným otevřením dialogu.

U cizoměnového pohybu lze rozúčtování na víc řádků zadat i v měně pohybu: zaškrtněte **Zadávat částky v EUR** (podle měny pohybu) a opište částky z podkladu, například z rozpisu věřitele. Na koruny je přepočte týž kurz ČNB, jakým se zaúčtuje banka. Bankovní řádek dostane přesně korunovou částku z výpisu a haléřový rozdíl po zaokrouhlení jednotlivých řádků se vyrovná na řádku s největší částkou; dialog u každého řádku ukáže výslednou korunovou částku. Saldokonta (311, 321, 314, 324, 325) takhle zadat nejde, protože pohledávka i závazek se odúčtovávají kurzem předpisu. Ty rozúčtujte v korunách včetně kurzového rozdílu 563/663.

Pohyb použitý k úhradě mzdového závazku má stav **Spárováno se mzdami**. Označení zaúčtování otevře existující zápis úhrady v účetním deníku, ať vznikl z banky, nebo ze mzdového modulu. Pokud zápis vytvořily mzdy, banka další zaúčtování nenabízí a starší návrh kontace se zneplatní. Párování s fakturou se u mzdové úhrady nenabízí. Pokud mzdové párování účetní zápis nevytvořilo, lze pohyb ručně zaúčtovat v bance. Částečné mzdové zaúčtování má vlastní označení a pohyb zůstává mezi nezaúčtovanými, dokud zápisy nepokryjí celou částku. Příprava mzdového příkazu, e-mailové avízo ani prohlášení **Zaplatil jsem** samy bankovní úhradu do deníku nezapisují. Skutečný pohyb z API nebo výpisu se propojí s mzdovým závazkem a zaúčtuje pouze jednou.

Sloupce tabulky transakcí:

| Sloupec | Význam |
|---|---|
| Datum | Datum zaúčtování v bance |
| Částka | + (kredit) / − (debet) v měně pohybu |
| VS / KS | Variabilní a konstantní symbol z transakce |
| Protistrana | Název + číslo účtu (pokud ho banka zaslala) a popis z banky |
| Faktura | Pokud spárováno, číslo faktury (klikatelné) |
| Stav | **Auto OK**, **Částečně**, **Ručně**, **Nespárováno**, **Ignorováno**, případně **Spárováno se mzdami**, **Převod mezi účty** nebo **Bez dokladu** (zaúčtováno mimo saldokontní účty) |
| Protiúčet | Jen v podvojném účetnictví: protiúčet zaúčtovaného pohybu |

Specifický symbol, bankovní referenci a nezkrácený popis najdete v detailu pohybu (ikona oka).

Odchozí platby se u přijatých faktur párují také podle **platebního variabilního symbolu**, vedle interního a dodavatelského čísla dokladu. Doslovná shoda má přednost před numerickou normalizací; nejednoznačné shody vyžadují kontrolu. Očekávaná částka zahrnuje **zaokrouhlení dokladu**, odečtené zálohy a již zaevidované úhrady. Zaokrouhlení se zohledňuje také při náhradním hledání podle protistrany nebo částky a data a při vrácení peněz z přijatého dobropisu.

Po ignorování nebo zrušení spárování se seznam obnoví na pozadí se zachováním filtrů; v detailu výpisu zůstávají načtené i další stránky pohybů. Přímo v detailu otevřeném ikonou oka lze podle stavu pohybu a oprávnění spustit párování, vytvořit přijatou fakturu z nespárované odchozí platby nebo vyžádat doklad. V nabídce dalších akcí je ignorování a zrušení párování či ignorování. U pohybů spárovaných se mzdami se tyto akce nenabízejí. Zrušení akce otevřené z detailu vrátí původní detail transakce; u párování a vytvoření faktury funguje i Escape. Po úspěšném dokončení se detail znovu neotevírá. Během ukládání nelze dialog zrušit.

### 29.13.7 Částečné platby a cizí měna

Příchozí platba se **shodným variabilním symbolem**, ale nižší částkou než zbývá uhradit, se zaeviduje jako **částečná úhrada** (záznam v boxu Platby detailu faktury). Faktura zůstává pohledávkou se sníženým zůstatkem a štítkem **Částečně uhrazeno**. Další převody se přičítají; jakmile platby pokryjí částku k úhradě, faktura se označí jako zaplacená (datum úhrady = datum poslední platby). U **zálohové faktury** se k částečné platbě plátci DPH rovnou připraví koncept **daňového dokladu k přijaté platbě** (viz § 11.1.2); doplatek zálohy, ke které už existuje finální doklad, se eviduje na finál. Stejně fungují platby z **e-mailových avíz** ([Bankovní účty](30_Bankovni_ucty.md)).

U cizoměnové faktury placené na **CZK účet** se přepočet kurzem dokladu použije jen k rozpoznání platby. Pokud se CZK pohyb vejde do devizové tolerance, zaeviduje se jako úhrada celého zbývajícího obnosu v měně faktury. Skutečná CZK částka zůstává beze změny na bankovní transakci; nemusí se rovnat částce faktury násobené kurzem dokladu. Výrazně nižší platba se nadále eviduje jako částečná úhrada přepočtená kurzem faktury. V podvojném účetnictví zůstává bankovní noha 221 ve skutečné CZK částce, pohledávka 311 se odúčtuje kurzem předpisu a rozdíl se zachytí na 563/663.

Ve stejné měně se zaeviduje platba ve výši transakce. Plné pokrytí označí fakturu jako zaplacenou (datum úhrady = datum transakce).

### 29.13.8 Ruční párování: pravidla a nespárované platby

Pro transakce, které se nespárovaly automaticky (typicky chybí VS, nebo částka nesedí kvůli devizovému kurzu či bankovnímu poplatku):

Filtr **Nespárováno** v detailu výpisu ukazuje pohyby, které skutečně čekají na spárování s dokladem. Nezahrnuje vlastní převody, mzdové platby ani pohyby zaúčtované mimo saldokontní účty, například bankovní poplatky, platby kartou a daně. Tyto pohyby zůstávají ve výpisu dostupné bez filtru. Akce **Nespárované XLSX** stáhne všechny takové pohyby z aktuálního výpisu, včetně těch na dalších stránkách. Soubor obsahuje bankovní účet, směr a datum platby, text, částku, měnu a poznámky účetní z účetního zápisu. Tlačítko **Odeslat klientovi** před odesláním ukáže počet pohybů, účet a adresy aktivních klientských uživatelů firmy a vyžádá potvrzení. Odesílá stejný XLSX soubor; bez nespárovaných pohybů nebo bez klientského příjemce se nic neodešle.

- Pod stavem **Nespárováno** je vidět důvod, proč transakci automat nevzal, například *Žádná vydaná faktura s tímto VS* (platba přišla dřív, než faktura vznikla), *Chybí variabilní symbol* nebo *Částka nesedí s fakturou*. Důvod se obnoví při každém automatickém párování. U e-mailových avíz ho najdete i v přehledu zpracovaných e-mailů ([Bankovní účty](30_Bankovni_ucty.md)).
- MyÚčto nejprve nabídne **skórovaný návrh** přímo pod transakcí. U každého kandidáta ukáže slovní jistotu a důvody, například shodný VS, zbývající částku, číslo faktury ve zprávě, známý účet protistrany nebo blízké datum splatnosti.
- Správného kandidáta lze potvrdit jedním kliknutím; chybný návrh zamítněte. Přeplatek, podezření na překlep ve VS, rozdílná měna, zálohová faktura a částka snížená o poplatek se **nikdy nepotvrdí automaticky**.
- Potvrzené účty zákazníků a dodavatelů se ukládají do jejich evidence. Teprve po třech bezchybných shodách může známý účet pomoci s automatickým párováním platby bez VS; zrušení chybného párování tuto důvěru okamžitě sníží.

Skóre je dohledatelné složení signálů, nikoli odhad AI. Automatické potvrzení vyžaduje skóre nejméně **85 %**, náskok alespoň **15 procentních bodů** před druhým kandidátem a deterministické jádro: přesný VS, známý ověřený účet nebo jednoznačný součet více dokladů. Návrh od **35 %** se může zobrazit k ručnímu posouzení. Překlep ve VS, přeplatek, rozdíl odpovídající poplatku, rozdílná měna nebo proforma automatické potvrzení vždy blokují bez ohledu na skóre.

Pole **Vyhledat jiný doklad** hledá podle čísla dokladu, platebního VS, dodavatele či odběratele nebo přesné částky dokladu či zbývající úhrady. Ruční hledání zahrnuje i starší doklady mimo časové okno automatických návrhů a respektuje směr platby včetně dobropisů.

Párování z detailu faktury (**Spárovat s bankou**): seznam lze hledat podle protistrany, VS, popisu nebo přesné částky. Pohyb přidělený jinému dokladu, mzdám, daním nebo vlastnímu převodu se nenabídne; dostupnost se znovu kontroluje při potvrzení. U ostatních pohledávek a závazků použijte **Připojit úhradu**.

U CZK platby cizoměnové faktury, která odpovídá celému zbytku v devizové toleranci, se faktura vyrovná celým zbytkem v její měně; přesný CZK pohyb zůstane na bankovní transakci. Výrazně nižší částka je částečná úhrada.

U odchozí platby bez VS může druhý průchod automaticky potvrdit jednu běžnou přijatou fakturu, pokud přesně sedí částka i měna, datum vystavení nebo splatnosti je nejvýše 14 dní od platby a na výpisu není jiný volný pohyb stejné částky. Doklad nesmí být uhrazen bankou, hotovostí ani zápočtem. U faktury už označené jako uhrazená musí navíc přesně sedět datum úhrady a název obchodníka musí mít společný významný token s názvem dodavatele. Jakákoli nejednoznačnost zůstane k ruční kontrole.

### 29.13.9 Sloučená úhrada

Když klient zaplatí **víc vystavených faktur jednou platbou** (součet sedí, ale variabilní symbol odpovídá jen jedné faktuře, nebo žádné), nabídne okno pod vyhledávačem sekci **Sloučená úhrada**. MyÚčto samo hledá **kombinace faktur téhož klienta**, jejichž **součet odpovídá částce platby**, ve výchozím okně **±7 dní** kolem data platby. Klient se jménem podobným protistraně se nabízí první.

Stejný dialog funguje i pro **odchozí platbu na více přijatých faktur**. Nabídne kombinace přijatých dokladů a dovolí vybrat jednu fakturu jako výchozí nebo rozšířit období hledání. Faktury mohou být od různých dodavatelů, například z jedné objednávky na internetovém tržišti. U každé faktury je vidět její dodavatel, částka a měna. Po spárování najdete všechny faktury také přímo u bankovního pohybu.

Zálohové faktury dostanou koncept finálního dokladu jako u běžné úhrady.

U vystavených faktur jdou kombinace jen v rámci **jednoho klienta** a součet musí **odpovídat částce platby** (sloučená úhrada = uhradit všechny vybrané faktury celé; není to rozpouštění jedné platby na částečné úhrady). Zrušení spárování ([§ 29.7](#297-krok-za-krokem-oprava-chybneho-parovani)) smaže **všechny** platby té transakce a vrátí všechny faktury zpět mezi pohledávky.

U přijatých faktur se vyrovnávají **zbývající částky**, takže předchozí částečné bankovní úhrady ve stejné měně se nezapočítají znovu. Pokud nelze bezpečně určit měnu předchozího vyrovnání, kombinace se nenabídne. Návrh ukazuje případný rozdíl proti částce pohybu. U platby CZK kartou za EUR faktury uvidíte i jejich CZK protihodnoty. Skutečně odepsaná částka se rozdělí mezi faktury poměrně; při zaúčtování se závazky vyrovnají v hodnotě jejich účetního předpisu a kurzový rozdíl se zachytí zvlášť. Korunová platba více cizoměnových faktur podporuje doklady bez předchozí částečné úhrady či zápočtu. Automatické zaúčtování vyžaduje zaúčtované předpisy a otevřené účetní období.

### 29.13.10 Ignorování a zrušení spárování

Ignorování slouží pro transakce, které nejsou platby faktur (poplatky, převody mezi vlastními účty, refundace). Poznámka je uložená u transakce a zobrazuje se u ignorovaného pohybu i při příštím otevření výpisu, v tabulce i na mobilu. Zrušení dialogu nic nemění.

Akce **Zrušit spárování**, u ignorovaného pohybu **Zrušit ignorování**, otevře potvrzovací dialog s datem, částkou a protistranou. Zrušení ignorování vrátí pohyb mezi pohyby bez shody. Pokud má pohyb poznámku k ignorování, dialog ji zobrazí a upozorní na její odstranění. Po potvrzení se poznámka smaže; zrušení dialogu ji zachová. Po potvrzení se řádek a počet spárovaných transakcí aktualizují bez reloadu; filtr zůstává zachovaný. Případná chyba se zobrazí přímo v dialogu.

Ruční párování na fakturu, kterou už evidované platby plně kryjí, se odmítne. Vyšší platba se na faktuře zaeviduje jen do výše zbývající částky, stejně jako u automatického párování. Pokud platby faktury přesto přesahují částku k úhradě, úhrada se nezaúčtuje automaticky a čeká ve frontě **K zaúčtování** na kontrolu dvojí úhrady.

### 29.13.11 Přijatá faktura vytvořená z výpisu

Funkce **Vytvořit fakturu** ([§ 29.6](#296-krok-za-krokem-platba-ktera-neni-platbou-faktury)) vytvoří z odchozí (záporné) platby koncept přijaté faktury. Variabilní symbol z platby se předvyplní do pole VS; číslo dokladu dostane dočasný placeholder `BANK-{id}` (přepište ho na reálné číslo z faktury). Platba se zároveň **spáruje** na nově vzniklý koncept (vazba, nikoli zaplaceno; to potvrdíte až po finalizaci faktury).

### 29.13.12 Ruční párování přijaté faktury s nižší platbou

Přijatou fakturu označí ruční párování jako uhrazenou jen tehdy, když platba pokryje zbývající částku. Rozdíl do 1,00 (v měně faktury) banka dorovná na 548/648, korunovou platbu cizoměnové faktury bere v kurzové toleranci jako úhradu celé faktury a rozdíl zaúčtuje jako kurzový.

Je-li platba nižší, faktura zůstane **částečně uhrazená** a aplikace nabídne dvě možnosti:

- **Nechat částečně uhrazené**: zbytek se doplatí později (další platbou, příkazem k úhradě nebo zápočtem).
- **Uhradit a vyrovnat rozdíl**: zbytek se hned zaúčtuje jako zápočet proti zvolenému účtu (321 MD / zvolený účet D). Předvolený je účet 648, u cizí měny 663. Faktura se tím uzavře.

Vyrovnání rozdílu je dostupné v podvojném účetnictví a s oprávněním k účetnictví. V daňové evidenci aplikace jen oznámí, kolik zbývá uhradit.

Korunová platba cizoměnové faktury mimo kurzovou toleranci (částečná úhrada nebo druhá platba už uhrazené faktury) se automaticky nezaúčtuje a čeká ve frontě **K zaúčtování** na ruční ověření. Závazek na 321 se tak neodúčtuje víc, než kolik z faktury zbývá.

Příchozí platba spárovaná s přijatou fakturou (vrácený dobropis, vrácený přeplatek) se do uhrazené částky počítá se záporným znaménkem. Vrácený dobropis proto nic nedluží a faktura, ke které dodavatel vrátil část peněz, ukáže zbytek.

### 29.13.13 Automatické zaúčtování spárovaných plateb (jen podvojné účetnictví)

Firmám vedoucím **podvojné účetnictví** MyÚčto po každém spárování nebo importu rovnou nabídne (a u opakovaných plateb i samo vytvoří) zápis do [Účetního deníku](52_Ucetni_denik.md). Daňová evidence žádný deník nemá: u ní se tato sekce, účetní záložky ani tlačítka vůbec nezobrazují a bankovní modul funguje jen jako párování plateb popsané výše.

Automatika žije na stránce `Peníze → Bankovní účty`, kde firmě s podvojným účetnictvím přibudou vedle **Bankovních výpisů** a **Všech pohybů** záložky **K zaúčtování** a **Kontace účtů**. Pravidla účtování se spravují v `Nástroje → Šablony účtování`, záložka **Pravidla účtování**.

- **K zaúčtování** je fronta návrhů čekajících na schválení, s odznáčkem počtu čekajících položek přímo na záložce. Výchozí podzáložka **Nezaúčtované pohyby** obsahuje všechny skutečné pohyby bez aktivního zápisu, tedy i ty, pro které automatika žádný návrh kontace nevytvořila. Historie návrhů je v samostatné podzáložce **Historie návrhů** a nabízí **Zaúčtováno automaticky**, **Potřebuje mě**, **Schválené** a **Odmítnuté**.
- **Pravidla účtování** jsou naučená pravidla pro opakované platby bez dokladu (odvody, poplatky, úroky).

Přímo v detailu výpisu uvidíte u každé transakce aktuální stav zaúčtování a tlačítko podle situace: **Zaúčtovat…**, **Schválit** / **Odmítnout**, nebo u zaúčtovaného pohybu dvojici **Přeúčtovat** a **Zrušit zaúčtování** (rozdíl viz [§ 29.8](#298-krok-za-krokem-zauctovani-pohybu-podvojne-ucetnictvi)).

**Poznámka k pohybu.** Poznámka se neukládá k pohybu, ale k jeho zápisu v [Účetním deníku](52_Ucetni_denik.md) (sekce **Poznámky** u rozbaleného zápisu). Zaúčtovaný pohyb ji ukazuje přímo v řádku pod protistranou, připnuté poznámky jsou zvýrazněné. Novou poznámku přidáte v nabídce **…** u pohybu volbou **Poznámka** (administrátor nebo účetní), která otevře tytéž poznámky jako deník: jde je přidat, upravit, připnout i smazat. Co napíšete u pohybu, uvidíte v deníku i v sekci **Zaúčtování** spárované faktury a naopak. Nezaúčtovaný pohyb zápis nemá, proto je volba **Poznámka** u něj neaktivní s vysvětlením. Poznámku ale můžete napsat rovnou při ručním zaúčtování pohybu (pole **Poznámka** pod popisem v dialogu **Zaúčtovat**) a v dialogu **Přeúčtovat**; uloží se k zápisu v deníku stejně.

Přeúčtování hlídá tytéž bankovní podmínky jako ruční zaúčtování: pohyb na účtu 221 musí sedět na částku z výpisu a bankovní noha se sama doplní na analytiku vlastního účtu výpisu. Celý postup i chování v zamčeném období popisuje [§ 48.8.2](52_Ucetni_denik.md#521492-preuctovani-z-dokladu-sekce-zauctovani).

Automaticky zaúčtovanou transakci od ručního zápisu odliší odznak **Automaticky**. U návrhů je v přehledu vidět také stručné **Proč**, například název pravidla nebo informace, že návrh vznikl ze shody platby. Podrobné auditní vysvětlení hotového zápisu (jestli za kontací stojí **pravidlo účtování**, vestavěné rozpoznání, naučená kontace, předkontace u spárované platby, nebo ruční přeúčtování) najdete po jeho rozbalení v [Účetním deníku](52_Ucetni_denik.md), viz [§ 48.8.3](52_Ucetni_denik.md#521493-podle-ceho-se-uctovalo).

#### 29.13.13.1 Spárované platby faktur: přímý zápis

Jakmile se transakce spáruje s fakturou (automaticky dle VS, ručně nebo jako sloučená úhrada), MyÚčto se ji hned pokusí zaúčtovat. Konkrétní zápis závisí na typu dokladu:

- **běžná vydaná faktura** → **MD 221 Bankovní účty / D 311 Odběratelé** (skutečné účty bere z [předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace) pro úhradu pohledávky z banky, pokud ji máte upravenou),
- **běžná přijatá faktura** → **MD 321 Dodavatelé / D 221** (předkontace úhrady závazku z banky),
- **zálohová (proforma) faktura** → **MD 221 / D 324 Přijaté zálohy** (inkaso zálohy): proforma není daňový doklad a nemá vlastní zaúčtovaný předpis, proto se účtuje rovnou jako záloha, ne jako saldokonto 311,
- **zálohová přijatá faktura** → **MD 314 Poskytnuté zálohy / D 221**, symetricky k předchozímu bodu,
- **vrácení vydaného dobropisu odběrateli** → **MD 311 / D 221**,
- **refundace přijatého dobropisu od dodavatele** → **MD 221 / D 321**.

> [!TIP]
> **Každý bankovní účet má svou analytiku.** Účet 221 se nikdy nepoužívá plochý: bankovní strana zápisu padá na analytiku toho účtu, ze kterého je výpis, tedy **221.100**, **221.200**, **221.300** … (o tečkovaném zápisu viz [§ 84.3.1](66_Ucetni_osnova.md#6684-teckovany-zapis-analytik)). Číslo se přiděluje automaticky (první volné, které v účtovém rozvrhu nekoliduje) a najdete i změníte ho na záložce **Kontace účtů** (viz [Bankovní účty](30_Bankovni_ucty.md)). Díky tomu sedí zůstatek každé analytiky přesně na výpis daného účtu, inventarizace k rozvahovému dni se dá doložit výpisem a cizoměnové účty se přeceňují automaticky, bez míchání měn. Tam, kde text níž mluví o účtu **221**, jde tedy o analytiku konkrétního účtu. Historické zápisy, které vznikly dřív, zůstávají na syntetice 221; přesun na analytiky je účetní reklasifikace k datu a dělá se ručně, v otevřeném období.

U **sloučené úhrady** nebo částečných plateb rozdělených na víc faktur vznikne zápis s tolika řádky na straně 311/321 (resp. 324/314 u záloh), kolik je alokací; rozdíl do **1 Kč** (zaokrouhlení, bankovní poplatek v alokaci) se dorovná automaticky na účet **648** (výnos) nebo **548** (náklad). Nad tuto toleranci se transakce nezaúčtuje sama a čeká na ruční zásah.

**Přijatá faktura se zaokrouhlením** (pole Zaokrouhlení, typicky 16 370,09 + 0,91 = k úhradě 16 371,00) má předpis na 321 v nominálu, zaokrouhlení do DPH nevstupuje. Když úhrada přesně odpovídá částce k úhradě, považuje se za plnou úhradu i při párování jen podle částky a data: závazek se uzavře nominálem a zaokrouhlení jde na 548 (zaplaceno víc) nebo 648 (zaplaceno méně). U dobropisu stejně s příchozí vratkou. Úhrady zaúčtované dřív bez tohoto dorovnání srovná `php api/bin/purchase-rounding-settlement-backfill.php` (spouští ho i migrace) přepisem zápisu úhrady na místě, jen v otevřeném účetním roce.

Než se zápis vytvoří, MyÚčto ověří:

- transakce je buď v **CZK**, nebo (u spárované platby) ve **stejné cizí měně jako faktura** (viz [Cizoměnové spárované platby](#2913132-cizomenove-sparovane-platby-kurzovy-rozdil)),
- **běžná** faktura nebo přijatá faktura má svůj **vlastní zaúčtovaný předpis** v [Účetním deníku](52_Ucetni_denik.md) (a ten není stornovaný). Bez zaúčtovaného předpisu se platba jen spáruje, ale nezaúčtuje. **Zálohová (proforma) faktura ani zálohová přijatá faktura** tuto podmínku nemají: nejsou daňový doklad, takže žádný „svůj“ předpis v deníku ani nemají, a účtují se rovnou podle výše,
- **účetní období** transakce je otevřené.

Když některá podmínka nesedí (uzavřené období, faktura označená jako uhrazená bez evidované platby k ověření apod.), zápis se nevytvoří rovnou, ale místo něj přibude **návrh** v záložce **K zaúčtování** s vysvětlením v poznámce (například „uzavřené období“, „faktura už je označena jako zaplacená, ověřte“). Platby bez zaúčtovaného předpisu **běžné** faktury (ne zálohy), stejně jako křížová měna nebo CZK doklad placený cizí měnou, se nezaúčtují automaticky. Nevyřešený skutečný pohyb zůstane dohledatelný ve **Všech pohybech** a v jednotné frontě `Účetnictví → K doúčtování`, i když pro něj nevznikla kontace.

> [!TIP]
> **Vyúčtovací faktura ze zálohy.** Když později zaúčtujete finální (vyúčtovací) fakturu navázanou na zálohu (viz [§ 23.3.1](23_Prijate_faktury.md#231116-propojeni-zalohy-s-vyuctovaci-fakturou-proti-dvojimu-zapocteni) nebo [§ 15.8](15_Faktura_editor.md#154-krok-za-krokem-zalohova-faktura-a-danovy-doklad-k-ni)), zápis automaticky doplní i **zúčtovací řádek zálohy** (324/311 resp. 321/314) ve výši skutečně přijaté nebo zaplacené zálohy, nejvýše však do celkové částky vyúčtovacího dokladu. Případný přeplatek zůstane na 324/314 do vrácení nebo dalšího vyúčtování; částka se neodvozuje z nominální hodnoty proformy. Kombinace proforma s daňovým dokladem k přijaté platbě **a** vyúčtováním zároveň, nebo proforma s víc než jednou vyúčtovací fakturou, je mimo automatiku: takový zápis zaúčtujte ručně.

> [!WARNING]
> Zaúčtování spárované platby se k transakci váže jen jednou: přepárování (změna alokace, sloučená úhrada místo jednoduché) přepíše týž zápis, nevznikne duplicita. Zrušení spárování ([§ 29.7](#297-krok-za-krokem-oprava-chybneho-parovani)) naopak zápis nejdřív **stornuje** (opravný zápis v deníku) a teprve pak fakturu vrátí mezi pohledávky a závazky. Pokud je účetní období zápisu už uzavřené, zrušení spárování se **nedokončí** (hláška o uzavřeném období) a musíte nejdřív období otevřít nebo počkat na účetní.

#### 29.13.13.2 Cizoměnové spárované platby (kurzový rozdíl)

Faktura v cizí měně (EUR, USD …) placená **stejnou cizí měnou** bankovní transakcí (na devizový účet) se zaúčtuje automaticky, včetně kurzového rozdílu. Stejně se zaúčtuje jednoznačně spárovaná **CZK karetní platba za cizoměnovou přijatou fakturu**; u ní nemusí být variabilní symbol a CZK částka se může lišit podle kurzu karetní asociace:

- saldokonto (**311**/**321**) se odúčtuje v **CZK hodnotě předpisu**, tedy cizí částka přepočtená kurzem, který byl zafixovaný při zaúčtování faktury,
- banka (**221**) se zaúčtuje v **CZK hodnotě skutečné úhrady**, tedy cizí částka přepočtená pevným měsíčním nebo ročním kurzem firmy, pokud je zvolený, jinak kurzem ČNB ke dni bankovní transakce,
- rozdíl mezi oběma jde na **563** (kurzová ztráta) nebo **663** (kurzový zisk), stejné účty jako u ročního [kurzového přecenění](52_Ucetni_denik.md).

U platby stejnou cizí měnou fungují i částečné a sloučené úhrady (poměrná část na alokaci). CZK karetní platba za cizoměnový doklad se automaticky účtuje jen při jednoznačné vazbě 1:1 na celý doklad. Drobný nealokovaný zbytek do jedné jednotky cizí měny se vede odděleně jako provozní vyrovnání na **548/648**, nikoli jako kurzový rozdíl. Mimo automatiku zůstává: **křížová cizí měna** (faktura v EUR placená v USD), **CZK doklad placený cizí měnou** a **zálohová (proforma) faktura / zálohová přijatá faktura v cizí měně**. Valutová pokladna umí samostatné hotovostní prodeje, nákupy a ostatní pohyby, ale úhradu cizoměnové faktury z pokladny záměrně blokuje; viz [Pokladna](32_Pokladna.md).

Historické spárované transakce zaúčtuje správce příkazem `php api/bin/backfill-bank-posting.php --supplier=<ID> --apply`. Bez `--apply` se vypíše pouze dry-run. Back-fill respektuje existující párování i bez VS, bezpečně naváže jedinou odpovídající starší platbu a plně kryté položky označené jako částečné uzavře jako plné. Nejednoznačné nebo skutečně částečné vazby nechá k ruční kontrole.

Nespárované transakce (odvody, poplatky, převody mezi vlastními účty) back-fill ve výchozím stavu vůbec nevyhodnocuje. S `--rules` je vyhodnotí, ale výsledek uloží vždy jen jako **návrh**. S `--auto` (implikuje `--rules`) se řídí nastavením **Automatika účtování** dané firmy: co má úroveň `auto`, dávka rovnou **zaúčtuje**, co má `suggest`, navrhne jako dosud. Firma bez nastavené automatiky má výchozí `suggest`, takže se pro ni ani s `--auto` nic nemění. Zavřená a schválená období se přeskočí bez ohledu na přepínače.

### 29.13.14 Pravidla účtování opakovaných plateb

Z rozbalovacího menu pohybu lze otevřít **Vytvořit účtovací pravidlo**. Otevře se stejný formulář jako na záložce **Pravidla účtování** (v `Nástroje → Šablony účtování`), předvyplněný protistranou, zprávou, směrem, měnou a dostupnou kontací pohybu. Částka pohybu není výchozí účtovanou částkou; volitelný rozsah od/do zadáte sami nebo pomocí procentního rozpětí (předvyplněno ±10 %). Výchozí priorita je 40, pravidlo tak přebije systémová rozpoznání i obecná pravidla ze šablon. Po uložení se pravidlo hned použije na pohyb, ze kterého vzniklo (nezaúčtovaný pohyb se zaúčtuje, u jinak zaúčtovaného se nabídne přeúčtování), a volitelně i na další odpovídající nespárované nezaúčtované pohyby v otevřených obdobích (max. 200). Uzavřené období skončí jako blokovaný návrh v Automatice. Takové pravidlo je rovnou v režimu **Automaticky** a další odpovídající platby účtuje bez potvrzení. Založení z pohybu je vaše výslovná volba automatiky, proto pravidlo nečeká na historii použití ani nepotřebuje rozsah částky. Dál ho hlídá **Strop pro automatiku**, uzavřené období, neobvyklé částky a denní limit firmy.

Platby bez faktury (odvody na OSSZ/ZP, bankovní poplatky, úroky, leasing …) se neúčtují samy od prvního výskytu. Na záložce **Pravidla účtování** si pro ně založíte pravidlo:

1. Tlačítko **Nové pravidlo** otevře formulář. Vyplňte:

   | Pole | Význam |
   |---|---|
   | Název | Popisek pravidla v seznamu |
   | Směr | **Příchozí** / **Odchozí** (platba na účet firmy, nebo z něj) |
   | Protiúčet + kód banky | Číslo protiúčtu, na které nebo z kterého platba chodí |
   | Variabilní symbol | Přesná shoda VS (číslice) |
   | Fragment zprávy | Podřetězec v popisu pohybu nebo ve jménu protistrany (bez ohledu na velikost písmen a diakritiku) |
   | Rozsah částky (Kč) | Interval absolutní částky (**od** - **do**), ve kterém se pravidlo použije. Nevyplněná mez znamená „bez omezení“ na dané straně intervalu, nikoli částku 0 Kč |
   | Měna pravidla | Měna pohybů, na které pravidlo sedí. U jiné měny než CZK lze pravidlem účtovat jen výsledkové účty 5xx/6xx |
   | MD / D | Kontace zápisu, účty musí existovat v [účtovém rozvrhu](66_Ucetni_osnova.md) |
   | Režim | **Návrh**, nebo **Automaticky** |
   | Priorita | Pořadí vyhodnocení (nižší číslo má přednost; pod 50 přebije systémová rozpoznání) |
   | Strop pro automatiku | Nad zadanou částkou vynutí pouze návrh, i když je pravidlo povýšené na automatické |

   Pravidlo musí mít **aspoň jedno kritérium** (protiúčet, VS nebo fragment zprávy), samotný rozsah částky nestačí.
2. Bankovní strana kontace musí být účet **221** (dle směru: příchozí = MD 221, odchozí = D 221). Pravidlo vždy účtuje proti bankovnímu účtu. Zadává se syntetika **221**; při zaúčtování ji MyÚčto samo nahradí **analytikou účtu, ze kterého je výpis** ([Bankovní účty](30_Bankovni_ucty.md)), takže jedno pravidlo funguje pro všechny bankovní účty firmy. Druhá strana nesmí být saldokontní účet (**311/321/314/324/325**): na spárované platby faktur pravidla nesahají, ty řeší [§ 29.13.13.1](#2913131-sparovane-platby-faktur-primy-zapis).
3. Tlačítko **Otestovat na historii** (dry-run) ukáže, kolika transakcím za posledních 12 měsíců by pravidlo sedělo a kolik z nich je už zaúčtovaných. Pomůže odladit kritéria dřív, než pravidlo uložíte.
4. Při uložení nabídne zaškrtávátko **Navrhnout zaúčtování N historických plateb** (jen když dry-run něco našel) a vytvoří rovnou návrhy pro dosud nezaúčtované historické transakce v otevřených obdobích (max. 200 položek).

U již uloženého aktivního pravidla lze stejný bezpečný běh spustit v seznamu tlačítkem **Použít na historii**. Zpracují se jen dosud nezaúčtované transakce v otevřených obdobích (max. 200) a vzniknou pouze návrhy ke schválení; opakované spuštění nevytváří duplicity.

Pravidlo založené na záložce **Pravidla účtování** (ne z pohybu) vždy jen **navrhuje** (režim **Návrh**): po importu vytvoří položku v **K zaúčtování**, kterou potvrdíte **Schválit** (případně přes ikonu ozubeného kolečka přepíšete kontaci) nebo **Odmítnout**. Po pěti potvrzeních za sebou beze změny, bez odmítnutí a s vyplněným rozsahem částky pravidlo označí jako připravené a tlačítko **Povýšit na automatiku** v seznamu zezelená. Tlačítko **Historie** u pravidla ukazuje potvrzení, korekce, povýšení i případný návrat pravidla na návrhy.

Na automatiku ale můžete pravidlo přepnout **kdykoli ručně**, i bez historie potvrzení: v seznamu tlačítkem **Povýšit na automatiku** (u aktivního pravidla v režimu Návrh je vždy k dispozici), nebo v úpravě pravidla výběrem **Režim: Automaticky** a uložením. U pravidla, které ještě nemá pět čistých potvrzení, se potvrzovací dialog výslovně zeptá na **vynucené povýšení bez historie** a upozorní i na chybějící rozsah částky. Takové povýšení se v **Historii** pravidla zapíše jako ručně vynucené. Ručně povýšené pravidlo účtuje samo hned od dalšího výskytu, bez ohledu na počet použití a rozsah částky; strop automatiky, uzavřené období, neobvyklé částky a denní limit platí dál. Zpět na návrhy ho vrátíte tlačítkem **Jen návrhy** nebo v úpravě volbou **Režim: Návrh**. Režim se nikdy nepřepne na automatiku sám. Od dalšího výskytu se zápis vytvoří bez čekání ve frontě (transakce zůstane vidět v historii se štítkem, kdo nebo co ji zaúčtovalo).

Když transakci odpovídá víc aktivních pravidel najednou, MyÚčto nikdy neúčtuje automaticky: vytvoří návrh podle pravidla s vyšší úspěšností a označí to jako konflikt, ať si kontaci zkontrolujete ručně. Opakované **odmítnutí** téhož pravidla (3× po sobě u různých transakcí) ho samo **deaktivuje**, na což vás upozorní hláška při odmítnutí; pravidlo pak najdete v seznamu vypnuté a můžete ho opravit nebo smazat.

Nesedí-li žádné aktivní pravidlo, ale MyÚčto najde v posledním roce **jedinou stejnou dvouřádkovou kontaci** už dřív zaúčtovanou pro stejný protiúčet (a sedí-li VS, je-li vyplněný), nabídne rovnou **naučený návrh** (označený „naučeno“), i bez založeného pravidla. Z libovolného ručního zaúčtování transakce navíc systém umí nabídnout **Podobná platba se opakuje…** → **Vytvořit pravidlo** s předvyplněnými kritérii (protiúčet, VS, fragment zprávy, rozsah částky ±10 % okolo částky) i kontací, kterou stačí zkontrolovat a uložit. Hláška se nabízí, když se v posledním roce objeví podobná platba víckrát a ještě pro ni neexistuje pravidlo. Ruční opravy mají přednost před starší historií; naučený návrh zobrazí datum a změnu kontace. Rozporné opravy nový návrh nevytvoří.

> [!WARNING]
> Kontaci volí uživatel, systém nikdy nedosadí účty sám bez potvrzení. Pravidlo, které nesedí, nastavte radši na **Návrh** a chvíli sledujte v záložce **K zaúčtování**, než ho přepnete na **Automaticky**.

> [!TIP]
> Pravidlo v režimu **Automaticky** zaúčtuje stejně, ať výpis nahrajete ručně nebo ho naimportuje cron ([§ 29.13.18](#291318-cron-automaticky-scan-adresare)); motor automatiky je pro obě cesty stejný. Výjimka je **zpětné doplnění historie** při zakládání nového pravidla (bod 4 výše): to i pro pravidlo v režimu Automaticky vždy jen **navrhne**, nikdy nezaúčtuje samo, ať máte na staré transakce jistotu, než je potvrdíte.

#### 29.13.14.1 Schvalování návrhů

U čekajícího návrhu vidíte datum, částku, protistranu, navrhované pravidlo a kontaci (MD/D):

- **Schválit** vytvoří zápis do deníku (viz [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace) pro logiku sestavení zápisu) a návrh přejde do stavu Schváleno.
- **Odmítnout** zahodí návrh; ke stejné transakci a pravidlu se už znovu nenabídne. **Tři odmítnutí stejného pravidla po sobě** ho automaticky deaktivují.
- Ikonou ozubeného kola u řádku **přepíšete MD/D účty** ještě před schválením, aniž byste museli upravovat pravidlo.
- Upravená kontace se uloží jako učicí signál. Další naučený návrh u stejného protějšku ukáže, kdy a z jakých účtů byla kontace změněna.
- Víc řádků najednou vyřídíte přes **Schválit vybrané**. Blokované a AI návrhy se nikdy neschvalují hromadně.

Transakce v **cizí měně** bez vazby na fakturu se automaticky ani návrhem neúčtují (chybí řešení kurzových rozdílů). Takové řádky nesou štítek **Cizí měna** a řeší se ručně (viz [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace)).

U už **zaúčtovaných** položek (podzáložka **Zaúčtováno automaticky**) vidíte odkaz na zápis v deníku a tlačítko **Stornovat**: vytvoří opravný (storno) zápis a transakci vrátí mezi nezaúčtované, aniž by se mazala historie. Pokud zápis vytvořilo automatické pravidlo, storno ho zároveň vrátí do režimu **Návrh**. Nezvyšuje tím počet odmítnutí.

Automatické zaúčtování se **nikdy neprovede do uzavřeného účetního období**: místo zápisu vznikne jen návrh s poznámkou o uzavřeném období, který doplníte ručně po otevření období.

### 29.13.15 Vlastní převody mezi bankovními účty

Pohyb mezi dvěma bankovními účty téže firmy MyÚčto rozpozná podle účtu výpisu a protiúčtu. Ve frontě **K zaúčtování** jej označí štítkem **Vlastní převod** a nabídne samostatné zaúčtování každé bankovní transakce přes účet **261 Peníze na cestě**:

- odchozí pohyb se účtuje **MD 261 / D 221**,
- příchozí pohyb se účtuje **MD 221 / D 261**.

Obě nohy se propojí, jakmile dorazí ve výpisech. V detailu výpisu lze přejít na druhou nohu; dokud ještě nedorazila, zůstává zůstatek 261 doloženým převodem na cestě. To je běžné například při odeslání 31. prosince a připsání 2. ledna.

Variabilní symbol pro spárování převodu potřeba není. Za jistou shodu MyÚčto považuje převod, jehož protiúčet je evidovaný vlastní bankovní účet firmy a na tomto účtu v okně sedmi dní leží právě jeden protipohyb s opačnou částkou ve stejné měně, jehož protiúčtem je zpětně účet prvního pohybu. Takový převod spáruje hned při načtení výpisu, dřív než by pohyb nabídl k úhradě faktury, a zaúčtuje ho podle nastavené úrovně automatiky. Pokud odpovídá víc protipohybů, protipohyb patří jiné firmě, je v jiné měně, ignorovaný nebo už spárovaný s dokladem, mzdami či ostatní položkou, převod se tímto způsobem nespáruje.

Převod mezi účty v různých měnách systém pouze označí k ručnímu zaúčtování, protože je potřeba zohlednit kurzový rozdíl. Pokud už existuje podobný ruční zápis přes 261, automatika upozorní na možné zdvojení a bez kontroly jej nezaúčtuje. Stejné varování se zobrazí při ručním zadání převodu, ke kterému už existuje bankovní transakce.

### 29.13.16 Odvody ČSSZ, zdravotním pojišťovnám a finančnímu úřadu

Odchozí korunové platby na účty vedené u ČNB (**kód banky 0710**) MyÚčto rozpoznává samostatně. Nejprve hledá odpovídající předpis zálohy na daň, sociální nebo zdravotní pojištění podle variabilního symbolu, data splatnosti a částky. Pokud předpis nenajde, určí druh odvodu z variabilního symbolu firmy a předčíslí účtu finančního úřadu. Platby mimo banku 0710 tento detektor nikdy nepřebírá.

Rozpoznaný odvod se zobrazí ve frontě s kontací **336/341/342/343/345 proti 221**, údajem **Jistota** a lidským vysvětlením. Platba i vratka **DPH** míří na zúčtovací analytiku **343.900**, tedy přesně na účet, na kterém po měsíčním zúčtování DPH ([§ 84.3.3](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph)) leží skutečný závazek vůči finančnímu úřadu; firma bez analytik účtuje jako dřív na holou 343. Nejasný odvod zůstane pouze návrhem. Pokud na zúčtovacím účtu chybí zaúčtovaný předpis nebo jeho kreditní zůstatek nestačí na platbu, automatika zápis sama neprovede, aby nevytvořila debetní zůstatek závazku.

Na stránce **Pravidla účtování** lze tlačítkem **Ze šablony** založit připravené pravidlo pro odvody, bankovní poplatky, úroky, nájem nebo předplatné. Šablona doplní identifikátory z nastavení firmy a vždy vznikne v režimu **Návrh**. Firemní katalog se spravuje v `Nástroje → Šablony bank. pravidel` podle oprávnění ke správě bankovních pravidel. Změna šablony ovlivní její budoucí použití v aktuální firmě; už dříve vytvořená pravidla se zpětně nemění. Použitou šablonu lze deaktivovat, ale ne smazat.

Fronta má vedle běžných návrhů také záložku **Potřebuje mě**. Sdružuje položky, které vyžadují rozhodnutí, i blokované položky z uzavřeného období. Jednotlivou položku lze po kontrole schválit; blokované a AI návrhy se nikdy neschvalují hromadně.

### 29.13.17 Přenos ignorování z e-mailových avíz

Při ručním importu GPC/ABO nebo PDF výpisu aplikace před párováním nabídne převzetí ignorování ze shodných ručně ignorovaných avíz. Pomocí **Vybrat vše** lze označit všechny nabídnuté shody nebo jejich výběr zrušit. Vyberte konkrétní pohyby a potvrďte **Přenést vybrané a importovat**. Přenese se i poznámka; částky a zůstatky výpisu se nemění. Avízo zůstane ignorované.

**Importovat bez přenosu** pokračuje běžným párováním. **Zrušit** nebo zavření dialogu soubor neimportuje. Pokud se avízo mezitím změní, výběr je potřeba znovu potvrdit. Souhrn oznámí počet převzatých ignorování samostatně od párování.

Nabízejí se jen jednoznačné dvojice stejné firmy, účtu, banky, měny a částky včetně znaménka, s datem nejvýše o pět dní odlišným. Identitu musí podpořit shodný VS nebo protiúčet. Karetní platby bez obou údajů, nejednoznačné dvojice, systémově ignorovaná avíza a avíza s vazbou na úhradu se nepřenášejí. U starších ignorování musí být ruční rozhodnutí doloženo auditním záznamem.

Jedno avízo se použije nejvýše jednou, i když později zrušíte ignorování nebo smažete importovaný výpis. Automatické skenování adresáře ignorování nepřenáší.

### 29.13.18 Cron: automatický scan adresáře

Místo ručního uploadu můžete nastavit **cron**, který bude pravidelně skenovat adresář (například `private/bank-incoming/`) a importovat nové výpisy:

```bash
cmd/cron-bank-scan.sh        # každých 30 minut
```

Postup:

1. Banka pravidelně exportuje výpis e-mailem nebo SFTP do `private/bank-incoming/`.
2. Cron každých 30 minut spustí `php api/bin/cron-bank-scan.php`.
3. Skript projde nové soubory, importuje je a přesune do `private/bank-archive/`.

### 29.13.19 Tipy

- Nahrávejte výpis **denně nebo týdně**; čím čerstvější, tím dříve se vám správně vyfiltrují faktury po splatnosti.
- **VS je nejsilnější signál**, ale není jediný. Bez něj MyÚčto vyhodnotí částku, zprávu, datum, název a dříve ověřený účet protistrany; nejednoznačnou shodu nechá vždy k potvrzení. Klienty přesto veďte k vyplňování VS.
- **Platby kartou** (bez VS) se po dokončení párování podle silných signálů zkusí spárovat na přijatou fakturu podle přesné částky, měny a data. Musí jít o jediný volný doklad i jediný volný pohyb této částky; u dokladu už označeného jako uhrazený se kontroluje také datum úhrady a podobnost názvu dodavatele. Jinak platba zůstane k ručnímu párování nebo založení dokladu (viz [§ 29.6](#296-krok-za-krokem-platba-ktera-neni-platbou-faktury)).
- **Částečné platby** (klient pošle míň, ale VS sedí) se u **vydaných** faktur evidují automaticky jako částečná úhrada (viz [§ 29.13.7](#29137-castecne-platby-a-cizi-mena)). U **přijatých** faktur se podplatba jen označí k ruční kontrole. Toleranci přesné shody lze ladit v `cfg.php` → `bank.matching.tolerance`; u bankovních e-mailových avíz ji nastavíte přímo v mapování účtu.
- **Devizový kurz:** pokud klient pošle EUR a faktura je v CZK, transakce nebude spárovaná (jiná měna), spárujte ji ručně. Pokud je ale faktura v EUR a klient zaplatí přímo eurem na váš EUR účet, taková spárovaná platba se zaúčtuje automaticky i s kurzovým rozdílem (viz [§ 29.13.13.2](#2913132-cizomenove-sparovane-platby-kurzovy-rozdil)).
- **Bankovní poplatek:** pokud u korunové dávky banka strhla z 10 000 Kč poplatek 200 Kč, na účet dorazí 9 800 Kč. MyÚčto může nabídnout sloučené párování celé dávky a po potvrzení připraví návrh vyváženého zápisu s poplatkem na účtu 568; bez kontroly jej samo nezaúčtuje.

### 29.13.20 Přímé napojení na banku (API)

Kromě ručního nahrání GPC/ABO nebo PDF ([§ 29.3](#293-krok-za-krokem-nahrani-vypisu)) umí MyÚčto pro vybrané banky stahovat pohyby přímo přes bankovní API a u většiny z nich i předávat platební příkazy. Napojení se zakládá na stránce `Peníze → Bankovní účty`, záložka **Měny a účty**, v sekci **Přímé napojení na banku** u konkrétního měnového účtu. Podrobný postup založení pro každou banku (přístupové údaje, certifikáty, OAuth souhlas) je v [§ 30.11.4](30_Bankovni_ucty.md#30114-prime-napojeni-spolecna-pravidla) a [§ 30.11.5](30_Bankovni_ucty.md#30115-podporovane-banky-a-jejich-pozadavky). Tato sekce shrnuje, co napojení jako celek umí, jaké má limity a jak je to bezpečnostně řešené.

#### 29.13.20.1 Podporované banky

| Banka | Kód | Technologie | Pohyby | Odeslání příkazu |
|---|---|---|---|---|
| **KB Business (KB+)** | 0100 | Extra služba API Business: OAuth2, ADAA (pohyby, Plus) nebo STATDA (výpisy KM, Basic) + BATCHDA (dávky) | ano | ano, se souhlasem `bpisp` ([§ 29.13.20.6](#2913206-kb-business-kb-rozsireni-o-odesilani-davek-batchda)) |
| **Fio banka** (ČR i SR) | 2010, 8330 | API token vázaný na konkrétní účet | ano | ano |
| **ČSOB** | 0300 | CEB Business Connector: číslo smlouvy + komunikační certifikát | ano | ano |
| **Raiffeisenbank** | 5500 | Premium API: Client ID + certifikát | ano | ano |
| **Česká spořitelna** | 0800 | Premium Accounts API v3: OAuth2 Authorization Code | ano | ne (zatím neimplementováno) |
| **Banka CREDITAS** | 2250 | Bearer token, volitelně mTLS certifikát | ano | ano |
| **MONETA Money Bank** | 0600 | MONETA API: token z Internet Banky | ano | ano |

Přehled bank se v sekci zobrazuje vždy; v seznamu účtů pod ním se nabízí jen účet, jehož kód banky konektor podporuje, ostatní účty tam nejsou vidět. Neaktivní účty bez uloženého napojení se nezobrazují. Neaktivní účet s existujícím napojením zůstává dostupný pro správu a je označený jako neaktivní. Každý měnový účet má vlastní přístupové údaje a vlastní stav napojení (aktivní / pozastavené / odpojené).

#### 29.13.20.2 Co napojení dělá s pohyby

Stažené pohyby se slučují do **jednoho měsíčního výpisu** pro danou firmu, účet, kód banky a měnu, ať banka posílá data jako API odpověď, nebo jako GPC (Fio, výpisy KB). Opakované načtení stejného období doplní tentýž výpis, překrývající se pohyby se nezapočítají podruhé a stažení bez nového pohybu nezaloží další řádek v přehledu. Každé stažení zůstává jako podklad měsíce v detailu výpisu. Dál se s nimi pracuje úplně stejně jako s nahraným GPC: párování na faktury ([§ 29.13.8](#29138-rucni-parovani-pravidla-a-nesparovane-platby)), automatické zaúčtování v podvojném účetnictví ([§ 29.13.13](#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)) i pravidla pro opakované platby. Pokud pro tentýž účet a měsíc později nahrajete úplný GPC, nahradí API přehled jako hlavní výpis a zachová párování i zaúčtování ([§ 30.11.4](30_Bankovni_ucty.md#30114-prime-napojeni-spolecna-pravidla)).

Načítání má bezpečnostní meze:

- **ruční načtení**: nejvýše **31 dní** včetně krajních dnů,
- **automatické pokračování** (prázdné pole *Od data*): naváže 3 dny před posledním úspěšně načteným datem (překryv kvůli pozdě zaúčtovaným pohybům); u zcela nového napojení stáhne posledních 14 dní,
- pokud by mezi posledním načtením a dneškem vznikla mezera delší než **89 dní**, automatické pokračování to odmítne a je potřeba mezeru dohnat ručním načtením po částech,
- konkrétní banka může mít vlastní dodatečné omezení (například Raiffeisenbank vrací pohyby jen za posledních 90 dní, KB+ tarif Plus/Pro omezuje frekvenci dotazů na jednou za 61, resp. 10 minut).

#### 29.13.20.3 Automatická synchronizace (cron)

U ČSOB tlačítko **Načíst výpisy a avíza** i automatická synchronizace načítají denní výpisy GPC a průběžná avíza BBF. V CEB musí být povolené vytváření a stahování obou formátů. Datum filtru se vztahuje k vytvoření souboru v bance. Avízo doplní pohyb a dostupný průběžný zůstatek; následný výpis pohyby potvrdí a doplní chybějící historii bez zdvojení již propojených plateb. Pokud se pohyb nedá jednoznačně ztotožnit, je potřeba zkontrolovat případnou duplicitu. Samotný známý zůstatek avíza nenahrazuje chybějící počáteční stav pro export GPC.

Ruční tlačítko **Načíst pohyby** u účtu není jediná cesta: cron `cmd/cron-bank-connections.{sh,cmd}` (spouští `php api/bin/cron-bank-connections.php`) projde všechny aktivní napojení se zadanými přístupovými údaji a zavolá pro každé automatické pokračování posledního načtení. Pokud žádné napojení není aktivní, běh se přeskočí. Cron neřeší nic navíc oproti manuálnímu tlačítku, jen ho spouští za vás pravidelně, typicky **každých 15 minut** (`*/15 * * * *`). Nastavení plánovače je v [playbooku automatizačních skriptů](../cmd/README.md).

Některé banky omezují, jak často lze pohyby stahovat. **KB+** povoluje stažení nezměněných dat nejvýš jednou za 61 minut, jinak dotaz odmítne. Cron proto napojení KB+ zavolá až po uplynutí 61 minut od posledního pokusu a mezitím ho přeskočí. Nové pohyby z KB+ se tak v aplikaci objeví nejpozději zhruba za hodinu. Když banka dotaz kvůli četnosti odmítne, napojení se neoznačí jako chybné a další běh to zkusí znovu. Ruční tlačítko **Načíst pohyby** tímto odstupem omezené není.

#### 29.13.20.4 Odeslání platebního příkazu

Napojení s podporou příkazů (viz tabulka výše) umí místo exportu KPC/PDF předat připravený příkaz z `Nákup → Platební příkazy` přímo bance k autorizaci (postup viz [§ 30.6](30_Bankovni_ucty.md#306-krok-za-krokem-odeslani-platebniho-prikazu-do-banky)). Platí pro to vždy:

- jen **tuzemské příkazy v CZK**; slovenské EUR příkazy (Fio SR) přímé odeslání zatím nepodporuje,
- odeslání **nepotvrzuje úhradu ani platbu neautorizuje**: příkaz je nutné zkontrolovat a potvrdit v internetovém bankovnictví; skutečnou úhradu prokáže až bankovní pohyb,
- výsledek odeslání (přijato / odmítnuto / nejasné) blokuje opětovné odeslání téhož příkazu; při nejasném výsledku nejdřív ověřte stav v bance, než vytvoříte další platbu,
- u KB+ jde o dávku max. **100 plateb** najednou (BATCHDA); u ostatních bank se limity řídí jejich vlastním API.

#### 29.13.20.5 Zabezpečení přístupových údajů

Tokeny, API klíče, hesla k certifikátům i OAuth tokeny se ukládají výhradně na serveru, **šifrované** (AES-256-GCM, envelope s kontextem konkrétního napojení: firma + účet), a do prohlížeče se po uložení už nevrací; ve formulářích se needitují, jen nahrazují. Šifrovací klíč (`app.secret_encryption_key`, resp. proměnná prostředí `MYINVOICE_SECRET_KEY`) musí nastavit správce instalace, bez něj napojení nejde vůbec dokončit. Klientské certifikáty (`.p12`/`.pfx`) mají limit **24 KiB** a jejich privátní klíč se při komunikaci s bankou používá jen v paměti procesu, nikdy se neukládá rozšifrovaný na disk. Certifikátová napojení (ČSOB, Raiffeisenbank, KB+, volitelně CREDITAS) vyžadují na serveru PHP cURL s podporou klientského certifikátu v paměti a rozšíření OpenSSL.

#### 29.13.20.6 KB Business (KB+): rozšíření o odesílání dávek (BATCHDA)

Napojení KB+ stojí na registraci aplikace u KB (Software Statement) a na OAuth2 tokenech. Stejný základ mají všechna API KB. MyÚčto nad ním u varianty Plus čte pohyby přes **ADAA** a volitelně odesílá platební dávky přes **BATCHDA**. U varianty Basic stahuje výpisy ve formátu KM přes **STATDA**. NOTDA (notifikace) konektor nevyužívá. Přístupový údaj pro ADAA je ve formuláři označen **Direct Access API JWT token**, stejně jako na portálu banky.

Hromadné platby **nepotřebují samostatný API klíč BATCHDA**. Dávku autorizuje access token, který banka vydá se scope **`bpisp`**. Tento scope musí mít registrace aplikace i souhlas udělený k účtu. Pole **klíč BATCHDA** ve formuláři je nepovinné a na developer portálu KB ho běžně nezískáte. Vyplňte ho jen tehdy, když vám ho KB výslovně vydala; pak se k dávce přiloží jako identifikátor volajícího. Bez něj MyÚčto posílá dávku jen s tokenem a klíč ADAA do služby dávek neposílá.

**Zapnutí u nového napojení.** Ve formuláři napojení u účtu zaškrtněte **Chci i hromadné platby (BATCHDA)**. Registrace aplikace se pak u KB žádá se scope `adaa` a `bpisp` a navazující souhlas v KB zahrnuje i oprávnění k odesílání dávek. Bez zaškrtnutí (a bez vyplněného klíče BATCHDA) se aplikace registruje jen se scope `adaa` a napojení umí výhradně čtení pohybů. Volbu zapněte jen tehdy, když vaše varianta Extra služby API Business dávky zahrnuje; jinak KB registraci se scope `bpisp` odmítne.

**Rozšíření existujícího napojení.** Sekce napojení u účtu ukáže, proč dávky nejdou, a podle toho vede na jednu ze dvou cest, kterými rozšíření řeší banka:

- **aplikace je u KB zaregistrovaná jen se scope `adaa`** → zvolte **Zadat klíče znovu**, vyplňte klíče Client Registration, OAuth a ADAA, vyberte certifikát a nechte zaškrtnuté **Chci i hromadné platby** (při opakovaném zadání je volba předvyplněná). MyÚčto vystaví nový Software Statement, pošle bance registrační požadavek se scope `adaa` a `bpisp` a hned po registraci vás provede novým souhlasem v KB,
- **aplikace má `bpisp` zaregistrovaný, ale udělený souhlas ho nezahrnuje** → stačí **Zahájit nové ověření v KB+**. Aplikace si vyžádá nový autorizační kód se scope `adaa bpisp`; klíče se znovu nezadávají.

Registraci i souhlas musí v KB potvrdit tentýž uživatel (klient KB), jinak banka tokeny nevydá.

**Platnost souhlasu.** Access token platí jen několik minut a MyÚčto ho průběžně obnovuje refresh tokenem. Refresh token platí 12 měsíců. Po jeho vypršení přestane fungovat čtení pohybů i odesílání dávek a je potřeba **Zahájit nové ověření v KB+**; novou registraci aplikace to nevyžaduje.

Když napojení dávky odeslat neumí, zobrazí se u účtu upozornění s důvodem a odeslání příkazu aplikace odmítne dřív, než cokoli předá bance. Příkaz pak nahrajete do KB ručně; čtení pohybů tím omezené není. Odeslaná dávka má nejvýše **100 plateb** a její příjem bankou **není autorizace ani úhrada**. Tu je vždy nutné dokončit v internetovém bankovnictví.

## 29.14 Související kapitoly

- [Bankovní účty a e-mailová avíza (IMAP)](30_Bankovni_ucty.md) - účty, přímé napojení, IMAP a parsery.
- [Automat účtování](53_Automat.md) - návrhy zaúčtování a historie automatických rozhodnutí.
- [Účetní deník](52_Ucetni_denik.md) - zápisy z banky, poznámky, přeúčtování.
- [Vydané faktury, editor](15_Faktura_editor.md) - platební variabilní symbol.
- [Přijaté faktury](23_Prijate_faktury.md) - přijaté doklady a zálohy.
- [Pokladna](32_Pokladna.md) - hotovost a valutová pokladna.
- [Platební karty](31_Platebni_karty.md) a [Kreditní karty](112_Kreditni_karty.md) - platby kartou.
