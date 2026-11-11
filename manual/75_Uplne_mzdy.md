# 75. Úplné mzdy: jak začít a jak postupovat

> Rozcestník mzdového modulu: co nastavit před první mzdou, jak projít
> mzdový měsíc od vstupů po podání a co dělat jednou ročně. Pro mzdové
> účetní a každého, kdo mzdy v MyÚčtu zavádí nebo zpracovává.

Mzdový modul vede celý pracovní tok jedné účetní: od nastavení zaměstnavatele
a zaměstnanců přes měsíční vstupy, výpočet a kontrolu až po výplatní
dokumenty, platby, zaúčtování a zákonná podání. Kroky jsou oddělené záměrně.
Výpočet mzdy sám neodešle peníze, nezaúčtuje doklad a nepodá hlášení. Každý
další krok spouštíte vědomě.

Na [kartě pracovního vztahu](86_Zamestnanci.md#866-krok-za-krokem-zmena-mzdy-uvazku-nebo-podminek-vztahu)
lze evidovat také hodinovou mzdu nebo hodinovou odměnu s účinností a historií.
Hodinový výpočet používá přesně doložené a schválené hodiny, účinné sazby
a samostatné podklady příplatků. V rychlém měsíčním vstupu ukazuje rozpad
nároků, zaokrouhlené částky a konkrétní chybějící podklady. Podporuje více
sazeb i změnu způsobu odměňování během měsíce, pokud lze dobu jednoznačně
rozdělit. Součástí je posouzení minima a případný doplatek podle
[doloženého režimu a započitatelných složek](79_Rychly_mesicni_vstup.md#79813-minimalni-mzda-a-doplatek).
Neúplné podklady minima mají konkrétní důvod blokace. Po schválení
běhu se hodinové nároky přenášejí do výplatní pásky a navazujících
výstupů ze zmrazené revize, bez přepočtu podle pozdějších sazeb.
Původní vztahy zůstávají v neurčeném režimu a jejich zpracování se nemění.
Hodinový režim se volí výslovně pro konkrétní vztah; hotová importovaná
částka sama tento režim nezapíná.

## 75.1 Kdy to potřebujete

Kapitolu otevřete, když:

- zavádíte mzdy v nové firmě nebo přecházíte z jiného mzdového programu,
- začíná nový mzdový měsíc a chcete vědět, v jakém pořadí postupovat,
- nevíte, kde v menu najdete další krok,
- je konec roku a čeká vás roční uzávěrka mezd,
- chybí vám tlačítko a potřebujete zjistit, jaké oprávnění k němu patří.

### 75.1.1 Každý měsíc

<!-- cols: 6 54 40 -->
| Pořadí | Co udělat | Kde v aplikaci |
|---:|---|---|
| 1 | Otevřít správnou firmu a měsíc, zkontrolovat nástupy, výstupy a změny podmínek | `Mzdy → Zaměstnanci`, viz [Zaměstnanci](86_Zamestnanci.md) |
| 2 | Doplnit absence, dovolenou a pracovní cesty a **schválit vybraný podklad pracovní doby** (docházku nebo doložený ruční souhrn) | `Mzdy → Absence a dovolená`, `Mzdy → Docházka a směny`, `Mzdy → Cestovní náhrady` |
| 3 | Zadat mzdy, odměny, náhrady a srážky; pro víc lidí hromadně | `Mzdy → Rychlý měsíční vstup`, `Mzdy → Mzdové složky a vstupy` |
| 4 | Založit mzdový běh, uzamknout vstupy, spočítat mzdy a projít blokace i varování | `Mzdy → Mzdové běhy` |
| 5 | Zkontrolovat čisté mzdy, odvody, daně, rozpad po zaměstnancích, srážky a exekuce | `Mzdy → Mzdové běhy`, [Srážky a exekuce](88_Srazky_a_exekuce.md) |
| 6 | Schválit revizi. Při chybě opravit zdrojový údaj a vytvořit novou revizi | `Mzdy → Mzdové běhy` |
| 7 | Zaúčtovat mzdy a porovnat mzdovou revizi, deník a platby | karta běhu, `Mzdy → Shoda účtování mezd` |
| 8 | Připravit závazky, vytvořit mzdové příkazy a po výpisu spárovat úhrady | `Mzdy → Mzdové příkazy a úhrady` |
| 9 | Vygenerovat výplatní pásky a měsíční balíček | `Mzdy → Dokumenty a výstupy` |
| 10 | Připravit a odeslat JMHZ a přehledy zdravotním pojišťovnám, nakonec měsíc uzavřít | `Mzdy → Podání a hlášení`, viz [§ 85.3](85_Podani_a_hlaseni.md#853-krok-za-krokem-mesicni-hlaseni-jmhz) |

Podrobný postup je v [§ 75.4](#754-krok-za-krokem-zpracovani-mzdoveho-mesice).

### 75.1.2 Průběžně

<!-- cols: 40 60 -->
| Kdy | Co udělat |
|---|---|
| Nástup, změna nebo skončení vztahu | Zapsat k datu účinnosti a splnit registrační nebo oznamovací lhůtu ([§ 85.1.2](85_Podani_a_hlaseni.md#8512-kdyz-se-neco-stane-u-zamestnance)) |
| Nemoc, ošetřování, mateřství, rodičovství, jiná dlouhá absence | Zapisovat průběžně, aby se nepřehlédla navazující povinnost ([Absence a dovolená](76_Absence_a_dovolena.md)) |
| Doručená exekuce, insolvence nebo dohoda o srážkách | Evidovat ihned, včetně pořadí a ověřených podkladů ([Srážky a exekuce](88_Srazky_a_exekuce.md)) |
| Výzva ČSSZ nebo žádost zaměstnance o potvrzení v důchodovém pojištění | Zapsat na kartě osoby, lhůta běží od doručení ([§ 86.8](86_Zamestnanci.md#868-krok-za-krokem-vyzvy-a-zadosti)) |
| Blíží se konec platnosti certifikátu, mění se registrace, účet nebo datová schránka | Obnovit a nastavit znovu ([§ 85.2](85_Podani_a_hlaseni.md#852-nez-zacnete)) |
| Vyšla legislativní aktualizace | Před prvním výpočtem zkontrolovat účinnost nové sady ([Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md)) |
| Podle interního režimu firmy | Vědomě načíst inbox datové schránky ([§ 75.7.3](#7573-datova-schranka-firmy)) |
| Průběžně | Sledovat **Provozní přehled mezd** na `Mzdy → Přehled mezd` ([§ 75.7.6](#7576-provozni-prehled-mezd)) |

### 75.1.3 Jednou ročně a výjimečně

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Aktivovat mzdy a projít první nastavení | [§ 75.3](#753-krok-za-krokem-prvni-nastaveni-mezd) |
| první období neleží v lednu | Převzít část roku z předchozího programu | [Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md) |
| na přelomu roku | Roční uzávěrka mezd, roční zúčtování, vyúčtování daně | [§ 75.5](#755-krok-za-krokem-rocni-uzaverka-mezd) |
| na výzvu ČSSZ | Evidenční list důchodového pojištění | [§ 85.10](85_Podani_a_hlaseni.md#8510-krok-za-krokem-evidencni-list-duchodoveho-pojisteni) |

## 75.2 Než začnete

1. **Mzdový doplněk.** Přepínač **Vést mzdy** je dostupný jen s aktivovaným
   mzdovým doplňkem. Bez něj je v `Firma → Nastavení` tlačítko
   **Aktivovat mzdový doplněk**.
2. **Oprávnění k nastavení mezd.** Mzdy zapne jen uživatel s právem na
   nastavení mezd. Ostatní práva přidělte účetní podle toho, co bude dělat
   (přehled v [§ 75.7.9](#7579-opravneni)).
3. **Rozhodnutí o prvním měsíci.** Starší měsíce mohou zůstat v
   [Mzdové rekapitulaci](64_Mzdy.md). Jeden měsíc ale nejde zpracovat oběma
   cestami.
4. **Podklady od předchozího programu**, pokud první období neleží v lednu:
   mzdy za převzaté měsíce, počáteční stavy, zůstatky dovolené a
   identifikátory ČSSZ (viz [Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md)).

## 75.3 Krok za krokem: první nastavení mezd

Nastavení projděte v tomto pořadí. Údaje z předchozího kroku používají další
obrazovky; přeskočený krok obvykle skončí blokací při výpočtu nebo podání.

1. V `Firma → Nastavení` zaškrtněte **Vést mzdy** a uložte. V menu se objeví
   sekce **Mzdy**.
2. Otevřete `Mzdy → Přehled mezd`. V panelu **Aktivovat mzdovou agendu**
   vyberte **První mzdové období** a klikněte na **Zahájit nastavení**.
3. Na přehledu se objeví **Průvodce prvním nastavením mezd** (nadpis
   **Rozjezd mezd krok za krokem**). Postupujte jeho kroky shora dolů. Každý
   krok vede na obrazovku, kde se údaj vyplňuje.
4. **Zaměstnavatel a účtárny.** V `Mzdy → Nastavení mezd` vyplňte údaje
   zaměstnavatele, výplatní den, pracovní režimy, mzdový kalendář,
   registrace u ČSSZ, zdravotních pojišťoven a finančního úřadu, bankovní účty
   institucí a předkontace (podrobně v [Nastavení mezd](90_Nastaveni_mezd.md)).
   Registrační a sériová čísla opište přesně tak, jak je přidělila instituce.
5. **Datová schránka.** V `Mzdy → Datová schránka` na záložce **Přístup**
   nastavte schránku firmy (viz [§ 75.7.3](#7573-datova-schranka-firmy)
   a [Datová schránka](97_Datova_schranka.md)).
6. **Certifikát pro podání ČSSZ.** Nahrajte a vyberte certifikát podle
   [§ 85.2.2](85_Podani_a_hlaseni.md#8522-nastaveni-v-myuctu).
7. **Legislativní pravidla.** V `Mzdy → Legislativní pravidla mezd` ověřte,
   že je pro první měsíc dostupná účinná sada (viz
   [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md)).
8. **Zaměstnanci a vztahy.** V `Mzdy → Zaměstnanci` založte osoby s osobními,
   identifikačními, daňovými, pojistnými a platebními údaji a ke každé
   pracovní vztah s podmínkami (viz [Zaměstnanci](86_Zamestnanci.md)).
   Údaje, které se běžně nemění (druh činnosti, pojištění, prohlášení
   k dani, běžný profil právních skutečností JMHZ), nastavte jako výchozí
   stav na vztahu.
9. **Mzdové složky.** V `Mzdy → Mzdové složky a vstupy` zkontrolujte zařazení
   složek do daně, pojištění, JMHZ a zaúčtování. Pravidelnou mzdu, paušál
   nebo opakovanou srážku nastavte jako opakovaný vstup (viz
   [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md)).
10. Přecházíte-li v průběhu roku, projděte navíc skupinu **Přechod v průběhu
    roku** podle [Přechodu mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md).
11. Posledním krokem průvodce **Spusťte první mzdový měsíc** přejdete
    k běžnému měsíčnímu zpracování ([§ 75.4](#754-krok-za-krokem-zpracovani-mzdoveho-mesice)).

**Jak poznáte, že je hotovo:** Všechny kroky průvodce jsou odškrtnuté. Po
prvním vypořádaném mzdovém běhu průvodce sám zmizí.

> [!TIP]
> Aplikace nevyžaduje dva měsíce souběžného provozu, uměle založený opravný
> běh, zkoušku obnovy ani kvalifikační protokol. Po nastavení můžete rovnou
> zpracovat první skutečný měsíc.

## 75.4 Krok za krokem: zpracování mzdového měsíce

Mzdový měsíc má tři fáze: zapíšou se vstupy, z nich se spočítá mzdový běh
a z hotového běhu se platí, účtuje, tisknou doklady a podává. Položky menu
**Mzdy** jsou seřazené v pořadí kroků, takže stačí jít shora dolů. Stejný sled
ukazuje na `Mzdy → Přehled mezd` rozcestník **Jak to funguje**.

**Vstupy**

1. Otevřete `Mzdy → Zaměstnanci` a zkontrolujte nástupy, výstupy a změny
   podmínek za měsíc. Nového člověka založíte i tlačítkem **+** v hlavičce
   volbou **Nový zaměstnanec**.
2. V `Mzdy → Absence a dovolená` zapište dovolenou, nemoc a ostatní překážky.
3. V `Mzdy → Docházka a směny` doplňte odpracovanou dobu a přesčasy a klikněte
   na **Schválit měsíc**.
4. V `Mzdy → Cestovní náhrady` zapište pracovní cesty a vyúčtování.
5. V `Mzdy → Rychlý měsíční vstup` zadejte hrubé mzdy, odměny a jednorázové
   položky (viz [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md)).
   S právem schvalovat mzdové vstupy se řádky ukládají rovnou jako schválené.
6. Opakující se složky, benefity a výjimky zadejte v `Mzdy → Mzdové složky
   a vstupy`. Tam vznikají vždy jako koncept. Schválíte je hromadně tlačítkem
   **Schválit N odpovídajících filtru**, nebo později u blokace v kartě běhu
   tlačítkem **Schválit vše**.

> [!WARNING]
> Příplatky za noc, víkend, svátek a ztížené prostředí vznikají schválením
> docházky. Po uzamčení vstupů se do běhu už nedostanou. Docházku schvalte
> dřív, než spustíte výpočet.

**Mzdový běh** (`Mzdy → Mzdové běhy`)

7. Nahoře vyplňte **Mzdové období** a **Datum výplaty**. Obojí je povinné.
   Z data výplaty se odvozují splatnosti odvodů i termíny podání. Nesmí být později než poslední den měsíce následujícího po měsíci, za který mzda
   přísluší (§ 141 odst. 1 zákoníku práce).
8. Klikněte na **Nový mzdový běh**. Nejde-li to, důvod je napsaný pod
   tlačítkem.
9. Projděte panel **Příprava vstupů za {měsíc}** (odkazy na vstupy, docházku,
   nepřítomnosti, mzdové složky a lidi, přepínač **Zobrazit přehled
   odvodů**). Vstupy jde měnit jen do zahájení výpočtu.
10. Na kartě běhu klikněte na **Spočítat mzdy**. Objeví se **Kontrola před
    zahájením**. Pokud něco našla, vyberte v dialogu **Opravdu zahájit mzdový
    běh?** volbu **Zkontrolovat znovu** (po opravě), nebo **Přesto zahájit**.
11. Projděte sekci **Kontroly běhu** na kartě. Červená je blokace, oranžová
    varování, šedá informace. Odkaz **Otevřít místo k opravě** vás přenese
    tam, kde se údaj opravuje. Neschválené vstupy schválíte přímo u blokace
    tlačítkem **Schválit vše**. Varování, které nejde odstranit, převezmete
    tlačítkem **Schválit výjimku** s odůvodněním.
12. Zkontrolujte dlaždice **Peněžní příjem před srážkou**, **Exekuční srážka
    a paušál**, **K výplatě po srážce** a klikněte na **Zobrazit rozpad
    podle zaměstnanců**. Otevře se tabulka **Zaměstnanci v běhu** s hrubou
    mzdou, pojistným zaměstnance, daní (záloha nebo srážková daň), čistou
    mzdou a částkou k výplatě každé osoby a se součtem dole. Řadíte kliknutím
    na záhlaví sloupce, hledáte podle jména nebo osobního čísla a volba **Jen
    s nálezem** nechá v tabulce jen osoby, kterých se týká některá kontrola
    běhu. Tlačítko **Detail výpočtu** u osoby ukáže, jak vznikla její daň,
    pojistné a čistá mzda. Po schválení vede tlačítko **Výplatní páska** na
    měsíční dokumenty osoby.
13. Je-li něco špatně, opravte zdrojový údaj a klikněte na **Přepočítat**.
    Změněný podklad (nový nebo opravený vstup, nepřítomnost, zákonná evidence)
    převezme do běhu tlačítko **Obnovit podklady**.
14. Klikněte na **Schválit**.

**Po schválení**

15. Klikněte na **Zaúčtovat** a výsledek zkontrolujte v `Mzdy → Shoda účtování
    mezd` (viz [Shoda účtování mezd](81_Shoda_uctovani_mezd.md)).
16. Klikněte na **Připravit platby** a pokračujte v `Mzdy → Mzdové příkazy
    a úhrady`: na záložce **Co zaplatit** klikněte na **Připravit závazky**,
    vyberte platby, zkontrolujte **Účet plátce** a klikněte na **Vytvořit
    mzdový příkaz**. Hotový příkaz najdete na záložce **Mzdové příkazy**.
    Po načtení výpisu
    spárujte úhrady na záložce **Spárování úhrad** (viz
    [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md)).
17. V `Mzdy → Dokumenty a výstupy`, záložce **Měsíční výstupy**, spusťte
    **Dávka dokumentů ({účtárna})** a po dokončení klikněte na **Stáhnout
    měsíční ZIP**. Neúspěšnou položku zopakujete tlačítkem **Opakovat**.
18. V `Mzdy → Podání a hlášení` na záložce **Měsíc** připravte a odešlete JMHZ
    a přehledy zdravotním pojišťovnám (postup v
    [§ 85.3](85_Podani_a_hlaseni.md#853-krok-za-krokem-mesicni-hlaseni-jmhz)
    a [§ 85.4](85_Podani_a_hlaseni.md#854-krok-za-krokem-prehledy-o-platbe-pojistneho-zdravotnim-pojistovnam)).
    Blok **Měsíční přehled pro účetní** ukáže u každé povinnosti, co se
    generuje, kam a jakou cestou to jde, do kdy a v jakém je to stavu.
    Zkontrolujte XML i PDF, zvolte kanál a každé odeslání výslovně potvrďte.
19. Až jsou platby doložené a podání přijatá, klikněte na kartě běhu na
    **Uzavřít**.

**Jak poznáte, že je hotovo:** Běh má stav **Uzavřeno**, na kartě je věta
**Úhrady doložené výpisem: všech N závazků**, Shoda účtování mezd ukazuje
**Mzda, deník i platby si po kategoriích odpovídají** a v panelu **Zákonné
termíny** na přehledu mezd jsou podání za měsíc **Splněno**. Samotný zelený
výpočet ani doručenka datové zprávy ještě hotový měsíc neznamenají.

> [!TIP]
> Zaměstnanec, jehož vztah trvá, ale v měsíci nemá žádný příjem, patří do běhu
> taky. U základní složky mu zadejte 0 Kč. Za vztah se pak podá nulový
> formulář JMHZ.

## 75.5 Krok za krokem: roční uzávěrka mezd

1. Ověřte, že jsou všechny měsíce schválené a nezůstala neuzavřená opravná
   revize, neprovedená platba ani nevyřešené podání.
2. Porovnejte roční součty daně, sociálního a zdravotního pojištění
   s měsíčními podáními, účetnictvím a bankou.
3. Zkontrolujte roční akumulátory, maximální vyměřovací základy, převod
   dovolené a počáteční hodnoty nového roku.
4. Zpracujte [roční zúčtování daně](84_Rocni_zuctovani.md) jen zaměstnancům,
   kteří splňují podmínky a doložili podklady.
5. Připravte zákonná potvrzení a evidenční výstupy v rozsahu, který aplikace
   označuje jako podporovaný. U ruční kontroly výsledek před vydáním ověřte.
6. Podejte obě roční
   [vyúčtování daně](85_Podani_a_hlaseni.md#851423-vyuctovani-zalohove-a-srazkove-dane),
   zálohové i srážkové. Jsou to dvě samostatná podání s různou lhůtou.
7. Projděte [retenční lhůty](93_Retencni_lhuty.md), zákonná zadržení a žádosti
   o [výmaz osobních údajů](94_Vymaz_osobnich_udaju.md). Konec roku sám není
   důvod mazat mzdové podklady.

**Jak poznáte, že je hotovo:** Všechny běhy roku jsou uzavřené, součty
souhlasí s podáními, deníkem i bankou a obě vyúčtování daně jsou podaná.

Stejný kontrolní postup použijte při převodu mezd z jiného systému, změně
účetní, reorganizaci mzdových účtáren nebo opravě staršího období.

Evidenční list důchodového pojištění už není roční povinnost. Připravuje se
na výzvu ČSSZ a za období před rokem 2026; výzvy i žádosti zaměstnanců
o potvrzení zapisujte na kartě osoby (postup v
[§ 86.8](86_Zamestnanci.md#868-krok-za-krokem-vyzvy-a-zadosti) a
[§ 85.10](85_Podani_a_hlaseni.md#8510-krok-za-krokem-evidencni-list-duchodoveho-pojisteni)).

## 75.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| V menu chybí sekce **Mzdy** | Firma nemá zapnuté **Vést mzdy**, nebo chybí mzdový doplněk | `Firma → Nastavení`, zaškrtněte **Vést mzdy** ([§ 75.3](#753-krok-za-krokem-prvni-nastaveni-mezd)) |
| „Mzdy může zapnout jen uživatel s právem na nastavení mezd.“ | Nemáte právo k nastavení mezd | Požádejte správce firmy o oprávnění ([§ 75.7.9](#7579-opravneni)) |
| Tlačítko na kartě běhu chybí úplně | Bez oprávnění se tlačítko nezobrazí, není ani zašedlé | Zkontrolujte svá práva v [§ 75.7.9](#7579-opravneni) |
| **Nový mzdový běh** nejde, pod tlačítkem je důvod | Chybí období, datum výplaty, nebo za měsíc už běh existuje | Doplňte údaj, nebo otevřete existující běh níž v seznamu |
| Blokace „Chybí schválená mzdová složka“ | Vztah nemá v měsíci schválený vstup | Doplňte a schvalte vstup. Trvá-li vztah bez příjmu, zadejte u základní složky 0 Kč; podá se nulový formulář JMHZ |
| Blokace s neschválenými vstupy | Vstupy z `Mzdové složky a vstupy` vznikají jako koncept | U blokace klikněte na **Schválit vše** |
| Varování, které nejde odstranit | Situace je správná, ale neobvyklá | **Schválit výjimku** s odůvodněním celou větou |
| Běh nejde schválit, karta hlásí změněné podklady | Od uzamčení se změnil vstup, nepřítomnost nebo zákonná evidence | Klikněte na **Obnovit podklady** a přepočítejte |
| Příplatek za noc, víkend, svátek nebo ztížené prostředí ve mzdě chybí | Docházka nebyla schválená před uzamčením vstupů | Vyžádejte opravu běhu, schvalte docházku a přepočítejte ([§ 75.7.5](#7575-priplatky-a-dochazka)) |
| Měsíc docházky s prací ve svátek nebo ve ztíženém prostředí nejde schválit | U vztahu chybí sjednaná zásada pro svátek nebo počet ztěžujících vlivů | Doplňte je na kartě pracovního vztahu v části **Zásady zákonných příplatků (§ 114–118)** |
| Uzavřený měsíc potřebujete změnit | Schválený výsledek se nepřepisuje | **Vyžádat opravu**, pak **Otevřít opravu**; vznikne nová revize |
| Test JMHZ hlásí, že formulář vztahu aplikace nesestaví | Scénář vztahu je mimo podporovaný rozsah | Viz [§ 75.7.7](#7577-podporovany-rozsah-a-rucni-kontrola) a [§ 85.14.9](85_Podani_a_hlaseni.md#85149-test-mesicniho-hlaseni-nalezy-a-nepodporovane-scenare) |

Obecné diagnostické postupy jsou v kapitole [Řešení problémů](999_Reseni_problemu.md).

## 75.7 Podrobnosti a pravidla

### 75.7.1 Jak je mzdový modul uspořádaný

Základem je **osoba**, která může mít jeden nebo více **pracovních vztahů**.
Pracovní vztah nese smluvní a zákonné podmínky platné v čase. Každý měsíc se
k němu doplní docházka, absence, cestovní náhrady a mzdové složky. Z těchto
podkladů vznikne **revize mzdového běhu**.

Schválená revize je neměnný otisk toho, co bylo skutečně spočítáno. Pozdější
změna karty zaměstnance, účtu nebo firemního nastavení ji zpětně nepřepíše.
Oprava vytvoří novou navazující revizi a peněžní či účetní rozdíly se řeší
proti předchozímu schválenému stavu.

Úplné mzdy používají stejný seznam osob jako Mzdová rekapitulace; druhou kopii
zaměstnance nezakládají. Jeden měsíc ale nejde uzavřít oběma cestami.

Jedna účetní může celý běžný tok připravit, zkontrolovat, schválit i odeslat;
modul nevyžaduje druhého schvalovatele. Citlivé oblasti, například exekuce nebo
nevratný výmaz osobních údajů, mají samostatná práva.

Rozpracovanou aktivaci jde zrušit tlačítkem **Zrušit zahájení nastavení**,
dokud je jen ve stavu nastavení. Aktivní začátek už běžný přepínač nezruší,
aby nezmizely vazby na běhy, platby, dokumenty a podání. Vypnutí **Vést mzdy**
skryje mzdy z menu, mzdová data se ale nemažou a po opětovném zapnutí zůstanou
dostupná.

### 75.7.2 Průvodce prvním nastavením mezd

Průvodce **Rozjezd mezd krok za krokem** na `Mzdy → Přehled mezd` má jedenáct
kroků ve třech skupinách:

1. **Nastavení zaměstnavatele**: údaje, bez kterých nejde spočítat ani odvést
   mzdu. Kroky **Zaměstnavatel a mzdové účtárny**, **Registrace u ČSSZ
   a pojišťoven**, **Platební účty institucí**, **Předkontace mezd**,
   **Mzdová politika a připravenost** a **Odesílání podání datovou schránkou**.
2. **Lidé**: **Založte prvního zaměstnance**, **Pracovní vztah a odměna**,
   **Zákonná evidence zaměstnance** a **Mzdové složky**.
3. **První mzdový měsíc**: jediný krok **Spusťte první mzdový měsíc**, kterým
   průvodce předá štafetu běžnému měsíčnímu zpracování.

Když první mzdové období neleží v lednu, přibude před první mzdový měsíc
skupina **Přechod v průběhu roku** se sedmi kroky: **Převezměte mzdy za měsíce
před přechodem**, **Zkontrolujte počáteční stavy**, **Děti a prohlášení od
začátku roku**, **Identifikátory pro ČSSZ**, **Průměrný výdělek pro první
čtvrtletí**, **Zůstatek dovolené** a **Kontrola převzaté části roku**.
Podrobně je popisuje [Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md).

Každý krok jde odškrtnout. Kroky, na které nemáte oprávnění, se nenabízejí;
prázdná skupina se skryje. Odškrtnuté kroky a skrytí průvodce se ukládají
k vašemu uživatelskému účtu, takže vám zůstanou i na jiném počítači nebo
v jiném prohlížeči. Průvodce zmizí sám, jakmile má firma první vypořádaný
mzdový běh. Firma, která mzdy dávno zpracovává, ho tedy neuvidí vůbec.

Nezaměňujte ho s rozcestníkem **Jak to funguje**. Ten popisuje opakovaný
měsíční postup v devíti krocích a odkazuje rovnou na příslušné obrazovky.
Průvodce prvním nastavením řeší jednorázové rozjetí modulu a stojí nad ním.

Při převodu z jiného programu doplňte i počáteční roční součty, zůstatky
dovolené a další návazné hodnoty. Bez nich může být samostatný měsíční
výpočet správný, ale roční limit, maximální vyměřovací základ nebo roční
zúčtování ne.

Odkaz na zdroj u mzdové složky je dobrovolný. Nenahrazuje skutečný zákonný
údaj a jeho absence sama o sobě nebrání práci. Jednorázové odměny a výjimky
patří do konkrétního měsíce, ne do opakovaného vstupu.

Úvodní nuly registračních a sériových identifikátorů neopravujte ručně podle
toho, jak vypadají v certifikátu. Použijte přesně hodnotu přidělenou
institucí; aplikace při porovnání zohlední povolený zápis.

Podání do testovacího prostředí úřadu jsou dostupná jen ve vývojové
instalaci; jinak jde vše do ostrého provozu (viz
[§ 85.2.3](85_Podani_a_hlaseni.md#8523-ostry-provoz-a-testovaci-prostredi)).
Testovací a ostré prostředí mají oddělené podání, certifikáty i stav.

### 75.7.3 Datová schránka firmy

Datová schránka patří ke konkrétní **firmě**, ne obecně k instalaci.
V `Mzdy → Datová schránka` zvolíte schránku a prostředí pro firmu. Podle
konkrétní akce lze použít Mobilní klíč eGovernmentu, jméno a heslo, jméno,
heslo a SMS kód, nebo uložený firemní certifikát. Zapamatované jméno
a komunikační kód Mobilního klíče se vážou na kombinaci firma + přihlášený
uživatel + prostředí; nejde o společné firemní heslo.

Datovou schránkou z mezd chodí **přehledy a hlášení zdravotním pojišťovnám**,
**měsíční hlášení zaměstnavatele ČSSZ (JMHZ)** jako alternativa k přímému
kanálu VREP a **součinnost exekutorům**. Daňová podání (přiznání k DPH,
kontrolní a souhrnné hlášení, přiznání k dani z příjmů) datovou schránkou
z aplikace nechodí, jdou přes EPO. Poslat je datovkou lze, ale takové podání
nedostane potvrzení s podacím číslem, jen dodejku.

Inbox se nikdy nevybírá automaticky. Nové zprávy se načtou až po otevření
záložky **Příchozí zprávy**, volbě přihlášení, potvrzení právního významu
vyzvednutí a spuštění akce uživatelem; vyzvednutí totiž může založit doručení
a právní lhůtu. Odesílací brána zprávy číst neumí, dokáže jen vložit koncept,
který uživatel po přihlášení v ISDS odešle. Doručenku proto stáhněte v datové
schránce a nahrajte ji k podání ručně. Žádné podání se neodešle jen tím, že
vzniklo XML nebo že se vložilo do odchozí fronty. Podrobnosti jsou
v [Podáních a hlášeních](85_Podani_a_hlaseni.md).

### 75.7.4 Mzdový běh: stavy a zvýrazněné tlačítko

Primární tlačítko na kartě běhu se po každém kroku samo přepne na další.
Pořadí je dané a nedá se přeskočit.

<!-- cols: 34 22 44 -->
| Stav běhu | Zvýrazněné tlačítko | Co se stane |
|---|---|---|
| Koncept | **Spočítat mzdy** | zamkne vstupy a spočítá |
| Vstupy uzamčeny / Oprava otevřena | **Přepočítat** | přepočítá ze zmrazeného snímku |
| Rozpracovaný běh se změněnými podklady | **Obnovit podklady** | nový snímek z aktuálních podkladů, pak přepočet |
| Spočítáno / Zkontrolováno | **Schválit** | závazný výsledek a výplatní pásky |
| Schváleno | **Zaúčtovat** | zápis do deníku (u daňové evidence se přeskočí) |
| Zaúčtováno | **Připravit platby** | vznikne seznam platebních závazků |
| Platby připraveny / Uhrazeno | **Uzavřít** | uzavře měsíc |
| Čeká na opravu / Zrušeno | **Otevřít opravu** | nová revize nad opravenými vstupy |

Vedle jsou méně časté akce **Vyžádat opravu** a **Zrušit běh** (červeně úplně
vpravo). Důvod se píše u **Vyžádat opravu** schváleného běhu a u zrušení
opravné revize, protože ta stojí nad schváleným výsledkem. Zrušení
neschváleného běhu stačí potvrdit, docházka ani vstupy se nemažou a běh vrátí
**Začít znovu a spočítat**. Zrušení uvolní jen vstupy zaměstnanců tohoto běhu.
Vstupy, které drží běh jiné mzdové účtárny za stejný měsíc, zůstávají
zamčené.

**Kontrola před zahájením.** Každý nález má vyznačený dopad: **Bez tohohle se
nedá počítat**, **Spočítat půjde, schválit ne** (červeně, například neschválená
docházka, rozpracované vstupy nebo vztah bez mzdové složky), **Pozdější oprava
znamená opravnou revizi**, nebo **Doplní se kdykoli, běh to nezdrží**. U
červených nálezů jsou tlačítka, která je vyřeší rovnou: **Otevřít docházku
a schválit měsíc**, **Schválit všechny mzdové vstupy** a **Otevřít měsíční
vstupy**.

**Běh nejde schválit.** Drží-li spočítaný běh neschválená docházka nebo
rozpracované vstupy, karta běhu ukáže panel **Jak pokračovat** s očíslovanými
kroky a tlačítkem u každého z nich. Běh nerušte: po opravě podkladů stačí
kliknout na **Obnovit podklady a přepočítat**.

**Uzamčení vstupů** vytvoří neměnný snímek zaměstnanců, vztahů, složek, data
výplaty a podkladů srážek. Co zapíšete potom, se do výpočtu ani do hlášení
nedostane, dokud podklady neobnovíte nebo neotevřete novou revizi. Přepočet
pracuje pořád se stejným zmrazeným snímkem, takže ho jde opakovat. Změnily-li
se podklady od zamknutí, karta běhu ukáže varování s počtem změn a běh nepůjde
schválit, dokud je neobnovíte a nepřepočítáte. Vypočtený výsledek se nikdy
neupravuje ručně.

**Schválit vše** u blokace schválí najednou až 500 vstupů; už schválený
přeskočí, takže je bezpečné ho použít znovu. **Schválit výjimku** vyžaduje
odůvodnění celou větou (nejméně 20 znaků a tři slova) a zapíše se do auditní
stopy. Bez převzetí odpovědnosti běh schválit nejde.

**Výsledek.** Rozpad podle zaměstnanců obsahuje čísla po osobách, **Rozklad
čisté mzdy**, **Rozklad sociálního a zdravotního pojištění** a **Rozpad daně
ze závislé činnosti**. Historie je pod odkazem **Zobrazit historii a změny**.
U hodinově odměňovaných osob **Hodinové nároky na výplatní pásce** uvádějí
každý vyplácený nárok jednou; rozpis úseků a zaokrouhlení řádku se rozbalí
pod odkazem **Rozpis výpočtu**. Doložené nulové nároky (0 h, 0 Kč) jsou
schované pod odkazem **Zobrazit i nulové nároky**. Výplatní páska se tím
nemění.

**Schválení** uloží výsledek jako závazný, zařadí generování výplatních pásek
každé zpracované osoby na pozadí, v podvojném účetnictví připraví rozdílový mzdový deník
a zapíše kontrolu i schválení do historie běhu. Samostatná kontrola je
součástí schválení.

**Zaúčtování** používá předkontace zmrazené při uzamčení vstupů, takže
pozdější změna nastavení zkontrolovanou revizi nezmění. Je-li účetní období
uzamčené, datum deníku se posune na první otevřený den.

**Platby.** **Připravit závazky** vytvoří přesné závazky bez duplicit (čisté
mzdy, sociální a zdravotní pojištění, záloha na daň, srážková daň, standardní
i exekuční srážky); opakované spuštění nic nezduplikuje. Mzdový příkaz
vznikne ve formátu **ABO / KPC pro českou banku**, **SEPA XML pro EUR**, nebo
jako **ruční hotovostní výplata**.

**Úhrada** není rozhodnutí účetní, ale fakt. Tlačítko „Označit za uhrazené“
proto na kartě není. Do stavu **Uhrazeno** běh překlopí aplikace sama, jakmile
poslední závazek dosedne na spárovanou platbu. Karta ukazuje větu **Úhrady
doložené výpisem** s počtem závazků a odkazem **Zobrazit platby**.

**Dokumenty.** Dávka dokumentů běží na pozadí po osobách s ukazatelem
průběhu; ZIP vznikne po dokončení všech osob.

**Uzavřený běh** jde otevřít už jen přes **Vyžádat opravu** a následné
**Otevřít opravu**. Vznikne nová revize, původní zůstane dohledatelná a dřív
vydané dokumenty zůstávají platné.

### 75.7.5 Příplatky a docházka

Příplatky za práci v noci, o víkendu, ve svátek a ve ztíženém prostředí
vznikají ze schválené docházky, nebo je zadáte hodinami v rychlém měsíčním
vstupu (viz [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md#795-krok-za-krokem-zakonne-priplatky-a-dalsi-slozky)).
Docházku je potřeba schválit dřív, než se u běhu uzamknou vstupy. Příplatek
za svátek (§ 115) potřebuje sjednanou zásadu a příplatek za ztížené prostředí
(§ 117) počet ztěžujících vlivů. Obojí se nastavuje na kartě pracovního vztahu
v části **Zásady zákonných příplatků (§ 114–118)**; bez toho měsíc docházky
s takovou prací nejde schválit. Podrobnosti jsou v
[Docházce a směnách](77_Dochazka_a_smeny.md#7795-zakonne-priplatky-ke-mzde-114-az-118).

Zelený výpočet ještě neznamená dokončený měsíc. Měsíc je hotový, až souhlasí
schválená revize, dokumenty, skutečně provedené platby, zaúčtování a přijaté
protokoly podání. Vytvořený soubor ani doručenka ISDS sama neprokazuje, že
instituce podání věcně přijala.

### 75.7.6 Provozní přehled mezd

Panel **Provozní přehled mezd** na `Mzdy → Přehled mezd` ukazuje u fronty
dokumentů a archivních exportů počty čekajících, opakovaných a vadných úloh,
stáří nejstarší aktivní položky a čas posledního úspěšného dokončení. Dlouhé
stáří při nulovém pokroku je důvod zkontrolovat plánované úlohy. Údaj
**Zatím nikdy** po prvním očekávaném běhu znamená, že úspěšné dokončení zatím
není doložené.

Karta **Provozní shoda** souhrnně ukazuje otevřené rozdíly, blokátory
a období s chybějícím podkladem mezi schválenou mzdou, deníkem, platbami,
zdravotními přehledy a JMHZ. Nulový počet znamená, že poslední uložená
kontrola nemá otevřený nález. Věcnou kontrolu mzdové účetní nenahrazuje.

U větší firmy pracujte s filtry, hledáním a hromadnými měsíčními vstupy.
Neprocházejte stovky zaměstnanců jen proto, abyste znovu potvrzovali stav,
který se od minulého období nezměnil.

### 75.7.7 Podporovaný rozsah a ruční kontrola

Podporované jsou scénáře, pro které aplikace nabídne potřebné údaje, výpočet
a kontrolu. Neobvyklé souběhy a odvodové režimy, nepokryté registrace,
nepodporované roční odpočty nebo výstupní potvrzení závislé na chybějícím
ověřeném přepočtu zpracujte ručně nebo s mzdovým specialistou. Chybějící
právní skutečnost nenahrazujte podobným polem.

Tabulka **Dostupné funkce** na přehledu mezd (pod **Technické informace
a dostupné funkce**) obsahuje jen funkce, které jsou v modulu bezpečně
dostupné. Neznamená to, že aplikace automatizuje libovolný hypotetický
scénář. Funkce, pro které chybí oficiální formát, transport nebo úplné
kontroly, zůstávají zablokované a v seznamu se nezobrazí.
Pravidla pro konkrétní měsíc ukazují
[Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md) se stavy
**Podporováno**, **Ruční kontrola** a **Nepodporováno**. Chybějící pravidlo
aplikace nenahradí hodnotou z jiného roku ani odhadem.

**Měsíční hlášení JMHZ** aplikace sestaví pro tyto formuláře:

<!-- cols: 40 60 -->
| Formulář | Kdy |
|---|---|
| běžný formulář | druh činnosti 1 až 9 s bližším určením 1, dohody, druhy 15 a 16 |
| činnost K až S | jednatel, společník, prokurista, člen orgánu (druh K a N až S s bližším určením 1) |
| vězeň (scénář 4) | výkon trestu nebo zabezpečovací detence: druh činnosti 1 až 9 s bližším určením 2 u pracovního poměru nebo zaměstnání malého rozsahu |
| jiný příjem (scénář 5) | druh činnosti 11, 13 nebo 14 u pracovního poměru |
| mezinárodní pronájem pracovní síly (scénář 6) | druh činnosti 12 u pracovního poměru |
| odložený příjem | volí se ručně potvrzením odloženého příjmu |

U druhu činnosti 11 až 14 se sociální ani zdravotní pojištění nepočítá
a karta pracovního vztahu to vysvětlí. Formulář vězně nenese zdravotní
pojištění, pozici ani rozpad mzdy, nese ale ELDP a odpracované hodiny.
Formuláře vězně, jiného příjmu a pronájmu síly nevyžadují ověřené pracoviště
ani příznaky vykonávané pozice.

Nesestaví formulář pěstouna (scénář 2), specifické skupiny (scénář 3,
například druh činnosti 1 až 9 s bližším určením 3) ani druh činnosti 10
(scénář 7). Vztah v těchto scénářích jde evidovat a přihlásit, test hlášení
ale ohlásí nález s pokynem podat ho ručně přes ePortál ČSSZ, nebo vztah
odložit (viz [§ 85.14.9](85_Podani_a_hlaseni.md#85149-test-mesicniho-hlaseni-nalezy-a-nepodporovane-scenare)).

**Nulové hlášení.** Trvající vztah, za který se v měsíci nic nezúčtovalo, se
hlásí nulovým formulářem. Zařaďte ho do běhu se mzdou 0 Kč u základní složky;
průměrný výdělek se pro takový měsíc nevyžaduje. Za měsíc, ve kterém
netrvalo žádné zaměstnání, se JMHZ nepodává (§ 7 odst. 2 zákona
č. 323/2025 Sb.). Hlášení bez jediného formuláře osoby ČSSZ odmítne
(kontrola 232).

JMHZ podporuje řízené storno celého podání i obsahovou opravu vybraných
formulářů z nové úplné přípravy. Přijatý formulář se opravuje se zachovanou
identitou, odmítnutý nebo chybějící se doplní jako nový (viz
[§ 85.14.13](85_Podani_a_hlaseni.md#851413-storno-a-obsahova-oprava-jmhz)).

Zvláštní režimy zdravotního pojištění, například člena družstva nebo SVJ
pracujícího za odměnu nebo výjimku z minima při péči o dítě do 7 let,
nastavíte na kartě zaměstnance (viz [Zaměstnanci](86_Zamestnanci.md#864-krok-za-krokem-zakonna-evidence-osoby)).

### 75.7.8 Společná bezpečnostní pravidla

- Pracujte jen ve správné firmě, prostředí a mzdovém období.
- Oprávnění přidělujte podle skutečné role; mzdy obsahují citlivé osobní údaje.
- Doklad o odeslání není doklad o věcném přijetí. U podání kontrolujte
  i doručenku, inbox a stav u instituce.
- ISDS ani inbox aplikace neobsluhuje automaticky. Každé vytvoření konceptu,
  přihlášení, načtení zpráv a potvrzení doručení spouští uživatel.
- Přihlašovací údaje, certifikáty, privátní klíče a SMS kódy nevkládejte do
  poznámek, příloh ani evidence zdrojů.
- Před uzavřením období uchovejte kontrolní výstupy a porovnejte součty mezd,
  plateb, zaúčtování a podání.

### 75.7.9 Oprávnění

Bez potřebného práva se tlačítko vůbec nezobrazí (není zašedlé). Chybějící
akce je proto nejčastěji chybějící oprávnění, ne chyba.

<!-- cols: 55 45 -->
| Tlačítko nebo akce | Oprávnění |
|---|---|
| **Nový mzdový běh**, **Smazat prázdný běh** | mzdové vstupy (`payroll.inputs.write`) |
| **Spočítat mzdy**, **Přepočítat**, **Uzamknout vstupy** | výpočet mezd (`payroll.calculate`) |
| **Obnovit podklady** | výpočet mezd a mzdové vstupy |
| **Vyžádat opravu** | kontrola běhu (`payroll.review`) |
| **Schválit**, **Uzavřít**, **Schválit vše**, **Schválit výjimku**, **Schválit měsíc** v docházce | schvalování (`payroll.approve`) |
| **Otevřít opravu**, **Zrušit běh** | znovuotevření (`payroll.reopen`) |
| **Zaúčtovat** a stránka Shoda účtování mezd | zaúčtování (`payroll.post`) |
| **Připravit platby** a stránka Mzdové příkazy a úhrady | platby (`payroll.payments`) |
| **Dávka dokumentů**, mzdový list, potvrzení, archivní ZIP | dokumenty (`payroll.documents`) |
| Podání a hlášení | podání (`payroll.submissions`) |

Úplný seznam práv modulu. Základní čtení vyžaduje oprávnění `payroll`.

<!-- cols: 55 45 -->
| Oblast | Oprávnění |
|---|---|
| Nastavení zaměstnavatele | `payroll.settings` |
| Změna osoby a ověření výplatního účtu | `payroll.person.write` |
| Odhalení citlivých osobních údajů | `payroll.person.read_sensitive` |
| Vztahy, podmínky a životní cyklus | `payroll.employment.write` |
| Docházka a absence | `payroll.time.write` |
| Mzdové vstupy (založení běhu, smazání prázdného běhu) | `payroll.inputs.write` |
| Výpočet mezd | `payroll.calculate` |
| Kontrola běhu a vyžádání opravy | `payroll.review` |
| Schválení mzdových vstupů, běhu, výjimky a uzavření | `payroll.approve` |
| Znovuotevření schváleného nebo zrušeného běhu | `payroll.reopen` |
| Platby, dávky a párování | `payroll.payments` |
| Dokumenty a měsíční balíček | `payroll.documents` |
| Podání a hlášení | `payroll.submissions` |
| Důkazy zdravotního pojištění | `payroll.health_evidence` |
| Mzdové sestavy a exporty | `payroll.reports` |
| Zaúčtování | `payroll.post` |
| Exekuce a nucené srážky | `payroll.enforcement` |
| Součinnost exekutorům | `payroll.enforcement.cooperation` |
| Insolvenční režim | `payroll.insolvency` |
| Retence a zadržení výmazu | `payroll.retention` |
| Schválení a provedení výmazu | `payroll.erasure` |
| Správa legislativních sad | `payroll.rulesets` |

Samostatná práva nejsou jen organizační pomůcka. Výchozí účetní role nemá
právo provést nevratný výmaz a běžné mzdové oprávnění samo neotevírá
exekuční spisy. Přístup přidělujte konkrétním rolím, ne všem uživatelům firmy.

## 75.8 Související kapitoly

Menu **Mzdy** a kapitoly v pořadí měsíčního kroku:

1. [Absence a dovolená](76_Absence_a_dovolena.md)
2. [Docházka a směny](77_Dochazka_a_smeny.md)
3. [Cestovní náhrady](78_Cestovni_nahrady.md)
4. [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md)
5. [Mzdové běhy](80_Mzdove_behy.md)
6. [Shoda účtování mezd](81_Shoda_uctovani_mezd.md)
7. [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md)
8. [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md)
9. [Roční zúčtování](84_Rocni_zuctovani.md)
10. [Podání a hlášení](85_Podani_a_hlaseni.md)
11. [Datová schránka](97_Datova_schranka.md)

Kmenová evidence:

12. [Zaměstnanci](86_Zamestnanci.md)
13. [Dohody o srážkách](87_Dohody_o_srazkach.md)
14. [Srážky a exekuce](88_Srazky_a_exekuce.md)
15. [Koše benefitů](89_Kose_benefitu.md)

Nastavení:

16. [Nastavení mezd](90_Nastaveni_mezd.md)
17. [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md)
18. [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md)
19. [Retenční lhůty](93_Retencni_lhuty.md)
20. [Výmaz osobních údajů](94_Vymaz_osobnich_udaju.md)
21. [Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md)
22. [Mzdová rekapitulace](64_Mzdy.md)
23. [Řešení problémů](999_Reseni_problemu.md)
