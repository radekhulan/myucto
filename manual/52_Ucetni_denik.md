# 52. Účetní deník

> Chronologický seznam všech účetních zápisů firmy v podvojném účetnictví. Kapitola je návod, jak v deníku zápis najít, založit ruční zápis, opravit nebo stornovat chybný zápis, doúčtovat doklady z jiného systému, vést ostatní pohledávky a závazky a interní doklady.

## 52.1 Kdy to potřebujete

Kapitolu otevřete, když:

- hledáte zápis ke konkrétní faktuře, platbě nebo dokladu,
- potřebujete zaúčtovat něco, co nevzniklo z faktury, banky ani pokladny (ruční zápis),
- jste zjistili chybný zápis a potřebujete ho opravit nebo stornovat,
- do MyÚčta přišly doklady z jiného systému a nejsou v deníku,
- potřebujete deník vytisknout nebo předat auditorovi (PDF, XLSX),
- potřebujete zamknout účtování po podání DPH,
- evidujete nájemné, kauci, půjčku, pojistné nebo jinou pohledávku či závazek bez faktury.

Deník je dostupný jen firmám v podvojném účetnictví. Firmy na [daňové evidenci](74_Danova_evidence.md) vedou místo něj jednodušší peněžní deník.

### 52.1.1 Kdy co udělat

<!-- cols: 24 46 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| denně | Najít zápis ke konkrétnímu dokladu | `Účetnictví → Účetní deník`, [§ 52.3](#523-krok-za-krokem-najdete-zapis-a-jeho-zdroj) |
| když účetní případ nemá doklad | Založit ruční zápis | tlačítko **Ruční zápis**, [§ 52.4](#524-krok-za-krokem-rucni-zapis) |
| při přesunu peněz mezi účty | Převod přes 261 | **Převod banka ↔ pokladna**, [§ 52.5](#525-krok-za-krokem-prevod-mezi-ucty-pres-261) |
| u opakovaných zápisů | Použít šablonu, importovat CSV | **Nový ze šablony**, [§ 52.6](#526-krok-za-krokem-rucni-zapis-ze-sablony-a-import-z-csv) |
| když je zápis špatně | Opravit, stornovat, přeúčtovat | [§ 52.7](#527-krok-za-krokem-oprava-nebo-storno-zapisu) a [§ 52.8](#528-krok-za-krokem-preuctovani-z-dokladu) |
| po importu z jiného systému | Doúčtovat doklady | `Účetnictví → Doúčtovat doklady`, [§ 52.9](#529-krok-za-krokem-douctovani-dokladu-z-jineho-systemu) |
| po podání DPH | Zamknout účtování k datu | [§ 52.11](#5211-krok-za-krokem-zamek-uctovani-k-datu) |
| u nájemného, kauce, půjčky | Vést ostatní pohledávky a závazky | `Účetnictví → Ostatní pohledávky a závazky`, [§ 52.12](#5212-krok-za-krokem-ostatni-pohledavky-a-zavazky) |

## 52.2 Než začnete

1. **Podvojné účetnictví.** Deník je jen pro podvojné účetnictví. Firmy na daňové evidenci modul v menu nevidí.
2. **Právo zápisu.** Ruční zápis, storno, úprava popisu a přílohy vyžadují právo zápisu (role účetní nebo administrátor). Role jen pro čtení vidí vše, ale nic nemění. Znovuotevření období a zámek účtování k datu smí jen administrátor ([§ 52.14.12.1](#5214121-kdo-smi-co)).
3. **Otevřené účetní období.** Zaúčtovat, stornovat ani přepsat zápis lze jen do otevřeného období. Existenci a stav období ověříte v [Uzávěrce](72_Uzaverka.md).
4. **Předkontace.** Deník nepředkontovává. Které účty se doklad zaúčtuje, určují předkontace ([Účtový rozvrh](66_Ucetni_osnova.md), [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace)).

> [!TIP]
> Zápisy se většinou zakládají samy z dokladů. Deník je tedy hlavně místo, kde výsledek kontrolujete. Rychlá cesta z dokladu do deníku: na detailu zaúčtované faktury klikněte na **Zobrazit v deníku**. Deník se otevře s filtrem na doklad a zápis bude rozbalený.

Z bankovního výpisu použijte u pohybu **Otevřít v účetním deníku**. Otevře se konkrétní účetní zápis s rozbaleným detailem, zvýrazněním a odskrolováním na jeho řádek. Při více panelech dostane přednost panel vpravo od výpisu, pokud je dostupný.

## 52.3 Krok za krokem: najděte zápis a jeho zdroj

1. Otevřete `Účetnictví → Účetní deník`. Zápisy jsou seřazené od nejnovějších a načítají se po 50 (další při posunu dolů, nebo tlačítkem **Načíst další**).
2. Nad tabulkou zúžte výběr filtry: **Číslo dokladu**, **Hledat** (hledá v čísle dokladu, popisu a údajích zdroje, kód účtu jako `221.400` omezí výsledky na pohyby účtu), **Období**, **Datum od / Datum do**, **Zdroj**, **Původ**, **Účet od / Účet do**, **Částka od / Částka do**, **Stav**, **Storno** a **Nesrovnalosti doklad ↔ deník**. Filtry zrušíte odkazem **Zrušit filtry**.
3. Řadit lze kliknutím na záhlaví sloupce. První kliknutí řadí sestupně, druhé vzestupně a třetí vrátí výchozí pořadí.
4. Kliknutím na řádek zápis rozbalte. Uvidíte řádky MD a Dal, součet **Celkem**, popis, přílohy, poznámky, historii a u automatických zápisů panel **Proč se to stalo**.
5. Kliknutím na **Zdroj** otevřete postranní souhrn zdrojového dokladu. Z něj přejdete do plného detailu faktury, bankovního výpisu, pokladny, karty majetku nebo vypořádání.
6. V panelu **Souvisí** vidíte protějšek (faktura a její úhrada) a můžete přejít na **Náhled**, **Zápis #…** nebo **Otevřít doklad**.
7. Kliknutím na kód nebo název účtu v rozbaleném zápisu otevřete opis pohybů účtu.
8. Chcete-li deník předat, klikněte na **Export PDF** nebo **Export XLSX**. Export respektuje aktuální filtry a je omezený na 5 000 zápisů.

**Jak poznáte, že je hotovo:** Vidíte hledaný zápis se stavem **Zaúčtováno** a jeho zdroj. Koncept se označí badge **Koncept**.

> [!TIP]
> Sloupce, jejich pořadí, barvy a hustotu upravíte v nabídkách **Sloupce**, **Barvy položek** a **Hustota**. Kombinace filtrů uložíte přes **Uložené filtry**. Podrobnosti jsou v [§ 52.14.3](#52143-seznam-zapisu).

## 52.4 Krok za krokem: ruční zápis

1. Na hlavní stránce deníku klikněte na **Ruční zápis**. Otevře se formulář **Ruční účetní zápis**.
2. Vyplňte **Datum zápisu**. Musí spadat do existujícího a otevřeného účetního období.
3. Volitelně vyplňte **Číslo dokladu** (prázdné pole se doplní z číselné řady, je-li zapnutá volba **Automatická čísla dokladů ručních zápisů (řada ID)** na stránce `Nástroje → Uzávěrka`) a **Popis** (nejvýš 255 znaků).
4. V tabulce řádků vyplňte **Kód účtu** (našeptávač nabízí aktivní účty firemní osnovy), stranu **MD** nebo **Dal** a kladnou **Částku** v Kč. Volitelně zvolte **Středisko**.
5. Další řádek přidáte tlačítkem **+ Přidat řádek**, řádek odeberete křížkem.
6. Podívejte se pod tabulku. Badge **Vyrovnáno** znamená, že Σ MD = Σ Dal. Badge **Rozdíl X** znamená, že zápis není vyrovnaný.
7. Volitelně připojte sekci **Vazba na doklad**, pokud zápis souvisí s konkrétním dokladem ([§ 52.14.7.4](#521474-vazba-na-doklad)).
8. Volitelně v sekci **Poznámky a doklady** přidejte soubory (tlačítkem **Přidat přílohu** nebo přetažením) a napište poznámku. Po zaúčtování se soubory nahrají jako přílohy zápisu ([§ 52.14.7.2](#521472-prilohy-zapisu)) a poznámka se uloží k zápisu ([§ 52.14.7.3](#521473-poznamky-k-zapisu)).
9. Klikněte na **Zaúčtovat**. Zápis se rovnou zaúčtuje a vrátíte se do seznamu.
10. Zapisujete-li víc podobných zápisů za sebou, klikněte místo toho na **Uložit a nový**. Řádky se vyčistí, datum a popis zůstanou.

**Jak poznáte, že je hotovo:** Zápis je v seznamu deníku se stavem **Zaúčtováno**. Tlačítko **Zaúčtovat** je aktivní, až když je zápis vyrovnaný a žádný řádek nemá prázdný účet nebo nekladnou částku.

> [!TIP]
> Opakuje-li se zápis, klikněte v rozbaleném zápisu na **Kopírovat jako nový**. Otevře se formulář s týmiž řádky a dnešním datem. Příklad ručního zápisu je v [§ 52.14.5.5](#521455-priklad-rucni-zapis-se-dvema-radky).

> [!TIP]
> Zápočet, přeúčtování, odpis pohledávky nebo kurzový rozdíl s vlastním číslem, partnerem a přílohami založte jako interní doklad ([§ 52.14.15](#521415-interni-doklady)). Zaúčtuje se týmž ručním zápisem.

## 52.5 Krok za krokem: převod mezi účty přes 261

Použijte pro přesun peněz mezi dvěma účty firemní osnovy (bankovní účty, banka a pokladna), kdy odeslání a přijetí spadá do různých dat.

1. V ručním zápisu klikněte na **Převod banka ↔ pokladna**. Otevře se dialog **Převod mezi účty (261)**.
2. Vyplňte **Z účtu** a **Na účet** (kódy účtů z osnovy, musí být různé).
3. Vyplňte kladnou **Částku**, **Datum odeslání** a **Datum přijetí**. Volitelně **Popis**.
4. Potvrďte.

**Jak poznáte, že je hotovo:** Obě nohy se zaúčtují najednou přes účet 261 Peníze na cestě, sdílejí číslo dokladu z řady PP a dialog se zavře s potvrzením čísel dokladů.

## 52.6 Krok za krokem: ruční zápis ze šablony a import z CSV

1. Pokud ještě šablonu nemáte, zadejte opakující se zápis ve formuláři **Ruční účetní zápis** a klikněte na **Uložit jako šablonu**. Vyplňte **Název šablony**, volitelně **Popis** a **Pojmenování řádků**. Zaškrtněte **Uložit i aktuální částky jako výchozí** jen u zápisů s pevnou částkou.
2. Při dalším zápisu klikněte v hlavičce formuláře na **Nový ze šablony**, vyberte šablonu a klikněte na **Použít šablonu**. Řádky se předvyplní. Vše lze přepsat.
3. Importujete-li rekapitulaci z externí mzdovky, nahrajte v dialogu **Nový ze šablony** v sekci **Import rekapitulace z CSV** soubor se dvěma sloupci (název položky nebo kód účtu, částka) a klikněte na **Nahrát a napárovat**. Řádky se předvyplní do formuláře, nic se neukládá.
4. Zkontrolujte nenapárované položky, o kterých vás upozorní zpráva, a doplňte je ručně.
5. Zápis zaúčtujte jako běžný ruční zápis ([§ 52.4](#524-krok-za-krokem-rucni-zapis)).

**Jak poznáte, že je hotovo:** Zápis je v deníku zaúčtovaný a šablona je dostupná také v `Nástroje → Šablony účtování`, záložka **Šablony zápisů**.

> [!WARNING]
> Tentýž měsíc mezd nezaúčtovávejte dvakrát: jednou importem šablony a podruhé přes [Mzdovou rekapitulaci](64_Mzdy.md). Formát CSV a doporučená šablona **Mzdy** jsou v [§ 52.14.5.8](#521458-sablony-rucnich-zapisu-a-mzdovy-mustek).

## 52.7 Krok za krokem: oprava nebo storno zápisu

Postup závisí na tom, odkud zápis vznikl a v jakém je období.

1. Určete příčinu. Je-li špatně zdrojový doklad (faktura, platba), opravte doklad. U ještě nezastornovaného zápisu v otevřeném období se zápis při novém zaúčtování přepíše na místě a historii drží auditní stopa.
2. Je-li špatně jen kontace, použijte **Přeúčtovat** přímo na dokladu, nebo v rozbaleném zápisu v deníku ([§ 52.8](#528-krok-za-krokem-preuctovani-z-dokladu)).
3. Má-li vzniknout protizápis, rozbalte zápis v deníku a klikněte na **Stornovat**. Vznikne zrcadlový zápis s prohozenými stranami MD a Dal k aktuálnímu datu v otevřeném období.
4. Opravte chybný údaj a doklad zaúčtujte znovu. U vydané nebo přijaté faktury storno odemkne zdrojový doklad.
5. Zápis, který v účetnictví nikdy neměl vzniknout, a jeho storno lze v otevřeném období smazat najednou tlačítkem **Smazat zápis i storno** ([§ 52.14.9.4](#521494-smazani-cele-storno-dvojice)).
6. Chcete-li opravit jen popis ručního zápisu, klikněte u popisu na ikonu tužky.

U aktivního zápisu ostatní pohledávky nebo závazku lze v otevřeném období použít **Smazat**.
Zdrojová položka se vrátí do konceptu, její dokumenty a splátkový kalendář zůstanou připojené.
Případné opakování se pozastaví. Spárované úhrady je nutné před smazáním odpojit.
Akce vyžaduje oprávnění k zápisu do deníku, ostatním položkám a jejich účtování.

**Jak poznáte, že je hotovo:** Původní zápis má badge **Stornováno** a odkaz na stornující zápis, nebo je nahrazený opraveným zápisem. Doklad lze opět zaúčtovat.

> [!WARNING]
> Do uzavřeného účetního období nelze stornovat ani přepsat zápis. Opravu řeší znovuotevření knih v [Uzávěrce](72_Uzaverka.md) (do schválení závěrky), ne přímé storno. Zápis lze stornovat jen jednou.

## 52.8 Krok za krokem: přeúčtování z dokladu

Přeúčtování změní jen kontaci. DPH se jím nemění, daňový režim se opravuje editací dokladu.

1. Otevřete detail [vydané faktury](14_Faktury.md), [přijaté faktury](23_Prijate_faktury.md) nebo zaúčtovaného [bankovního pohybu](30_Bankovni_ucty.md).
2. V sekci **Zaúčtování** klikněte u živého zápisu na **Přeúčtovat**. Stejné tlačítko má i rozbalený zápis faktury, přijaté faktury nebo bankovního pohybu v účetním deníku.
3. V dialogu upravte, smažte nebo doplňte řádky. Zápis musí zůstat vyrovnaný.
4. Přečtěte si, co se po potvrzení stane (závisí na stavu období, viz tabulka v [§ 52.14.9.2](#521492-preuctovani-z-dokladu-sekce-zauctovani)). Vyžádá-li si dialog potvrzení posunu data, zkontrolujte ho.
5. Potvrďte. Chcete-li změnit jen poznámku, klikněte na **Uložit poznámku**.

**Jak poznáte, že je hotovo:** Zápis má novou kontaci. V otevřeném období se přepsal na místě, v uzavřeném nebo zamčeném vznikl storno a nový zápis. Oba zůstanou v deníku kvůli auditu.

Doklad převzatý z jiného programu má skupinu jednoho nebo více vlastních účetních zápisů, například zvlášť základ a DPH. Tato skupina je oddělená od informativních vazeb **Souvisí**. Opakované automatické zaúčtování převzatou skupinu nepřepisuje. Při **Přeúčtování** dialog vypíše všechny její členy a předvyplní řádky, které jsou právě v deníku. Po potvrzení vzniknou protizápisy ke všem původním členům a jeden nový předpis. Úhrady a pouze související zápisy zůstanou zachované. Změní-li se některý člen během otevřeného dialogu, obnovte náhled. Storno nebo smazání zdrojového dokladu rovněž zruší celou skupinu zaúčtování.

Po ručním stornu jednoho člena zůstává doklad zaúčtovaný, dokud má skupina alespoň jeden aktivní zaúčtovaný zápis. Kontroly integrity a doúčtování proto neoznačují takový doklad za nezaúčtovaný. Automatická reklasifikace nebo dorovnání zúčtování zálohy rozdělenou skupinu nepřepíše po jednom členu; opravu proveďte přes **Přeúčtovat** na detailu celého dokladu.

## 52.9 Krok za krokem: doúčtování dokladů z jiného systému

Automatika účtování se spouští při vzniku dokladu. Doklad, který už v systému leží (například naimportovaný z jiného systému), jí neprojde nikdy. Takové doklady zaúčtujete takto:

1. Otevřete `Účetnictví → Doúčtovat doklady`. Obrazovka ukáže, kolik dokladů čeká (vydané a přijaté faktury, pokladní doklady, bankovní pohyby, zápočty).
2. Klikněte na **Zkusit nanečisto**. Aplikace řekne, co by se stalo, a nic nezapíše.
3. Klikněte na **Doúčtovat**. Úloha běží na pozadí a stránku můžete zavřít.
4. Chcete-li běh zastavit, klikněte na **Zastavit**. Doběhne rozepsaný doklad. Doklady zaúčtované do té chvíle zůstanou v deníku.
5. Projděte protokol. Přeskočené a chybné doklady mají u sebe důvod.

**Jak poznáte, že je hotovo:** Počty čekajících dokladů klesly a na konci běhu se nehlásí nevyrovnaný stav deníku.

Podrobnosti jsou v [§ 52.14.13](#521413-douctovani-nezauctovanych-dokladu).

## 52.10 Krok za krokem: přílohy, poznámky a vazba na doklad

1. Rozbalte zápis. V sekci **Přílohy** klikněte na **Přidat přílohu**, nebo soubory přetáhněte do vyznačené zóny. Jeden soubor smí mít nejvýš 20 MiB, všechny přílohy zápisu dohromady 100 MiB.
2. Popisek přílohy upravíte ikonou tužky, soubor stáhnete ikonou stažení, smažete košem.
3. V sekci s poznámkami napište pracovní vysvětlení. Důležitou poznámku připněte.
4. Souvisí-li zápis s konkrétním dokladem, klikněte v sekci **Vazba na doklad** na **Navázat doklad**, vyhledejte doklad a volitelně připište poznámku. Vazbu zrušíte tlačítkem **Zrušit vazbu**.

**Jak poznáte, že je hotovo:** Příloha, poznámka nebo vazba je v rozbaleném zápisu vidět. Navázaný doklad se objeví v panelu **Souvisí** i naopak.

## 52.11 Krok za krokem: zámek účtování k datu

Po podání přiznání k DPH zamkněte minulost, aby se omylem nezaúčtoval doklad zpátky do podaného období.

1. Zámek smí nastavit jen administrátor. Otevřete `Účetnictví → Měsíční kontrola`.
2. Klikněte na **Uzamknout k datu**, zadejte datum a zdůvodnění (nejméně 5 znaků) a klikněte na **Uložit zámek**. Postup je v [§ 62.4](62_Mesicni_kontrola.md#624-krok-za-krokem-zamek-uctovani-k-datu).
3. Po skutečném podání můžete zámek posunout také v Archivu podání tlačítkem **Označit jako podané**.

**Jak poznáte, že je hotovo:** Pokus o zaúčtování, přeúčtování nebo storno dokladu s datem v zamčeném rozsahu skončí hláškou, že datum je zamčené.

> [!TIP]
> Vygenerování ani stažení přiznání k DPH zámek samo neposouvá. Pravidla zámku jsou v [§ 52.14.10](#521410-zamek-uctovani-k-datu).

## 52.12 Krok za krokem: ostatní pohledávky a závazky

Agenda slouží pro nároky a dluhy, které nepatří do faktur, mezd ani daní: nájemné, vratnou kauci, půjčku a její splátky, pojistné, poplatek nebo náhradu škody.

1. V podvojném účetnictví otevřete `Účetnictví → Ostatní pohledávky a závazky`. V daňové evidenci `Daňová evidence → Ostatní pohledávky a závazky`.
2. Klikněte na **Nová ostatní položka**. Vyplňte směr, druh, položku, protistranu, datum vzniku, splatnost a částku v Kč.
3. V podvojném účetnictví zvolte v části **Účtování** účet pohledávky nebo závazku a protiúčet. Chybí-li volba, použije se 315 nebo 325.
4. Klikněte na **Zaúčtovat**. Položka dostane číslo z řady OP (pohledávky) nebo OZ (závazky) a vznikne zápis. V daňové evidenci se položka potvrdí a žádný zápis nevzniká.
5. Po uhrazení klikněte na **Hledat volnou platbu**, vyberte bankovní pohyb nebo pokladní doklad a přiřaďte úhradu.
6. Opakuje-li se položka, nastavte v části **Opakování položky** četnost. Rozkládá-li se úhrada, nastavte splátkový kalendář.

**Jak poznáte, že je hotovo:** Položka je ve stavu uhrazená, nebo jí zbývá uhradit nula, a z filtru **Otevřené** zmizí.

> [!WARNING]
> Zdanitelné plnění se sem nezadává. Patří do faktur, aby jeho řádky vstoupily do knihy DPH a výkazů.

Podrobnosti jsou v [§ 52.14.14](#521414-ostatni-pohledavky-a-zavazky).

## 52.13 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Tlačítko **Zaúčtovat** je neaktivní | Zápis není vyrovnaný, nebo řádek nemá účet či kladnou částku | Doplňte řádek. Server nevyrovnaný zápis vždy odmítne. |
| **Účet není v osnově** | Kód účtu neexistuje v osnově firmy | Zkontrolujte kód, nebo účet založte v [Účtovém rozvrhu](66_Ucetni_osnova.md). |
| **Pro zadané datum neexistuje účetní období** | Není založené období | Založte je v `Nástroje → Uzávěrka`. |
| **Do uzavřeného období nelze účtovat** | Období je uzavřené nebo se uzavírá | Znovu otevřete období v [Uzávěrce](72_Uzaverka.md), nebo zvolte datum v otevřeném období. |
| Hláška, že datum je zamčené | Zámek účtování k datu | Požádejte administrátora o posun zámku ([§ 52.11](#5211-krok-za-krokem-zamek-uctovani-k-datu)). |
| **Zápis už byl stornován** | Zápis lze stornovat jen jednou | Opravte doklad a zaúčtujte ho jako nový zápis. |
| Faktura nejde smazat, protože má zaúčtovaný zápis, který nelze stornovat | Původní období zápisu je uzavřené | Znovu otevřete období, nebo opravte doklad přeúčtováním ([§ 52.8](#528-krok-za-krokem-preuctovani-z-dokladu)). |
| **Zápis mezitím změnil jiný uživatel - načetl jsem aktuální stav, zkuste to prosím znovu.** | Při úpravě popisu se zápis mezitím změnil | Porovnejte předvyplněný text s tím, co uložil kolega, a uložení zopakujte. |
| Popis nejde upravit, vidíte text **Popis se edituje na zdrojovém dokladu** | Popis patří k dokladu (faktura, banka, pokladna, majetek) | Upravte popis na zdrojovém dokladu. Inline upravit lze jen ruční zápisy a zápisy uzavření a otevření knih. |
| Příloha se nenahraje, **Tato příloha už je u zápisu evidována** | Stejný obsah nejde k témuž zápisu nahrát dvakrát | Příloha už u zápisu je. |
| Export hlásí překročení limitu | Export je omezený na 5 000 zápisů | Zúžte filtr (typicky **Datum od / Datum do**) a export zopakujte po částech. |
| Zaúčtování faktury se odmítne s rozpisem částek | Celková částka dokladu se liší od základu a DPH z položek o víc než 2 Kč | Opravte doklad (klasifikaci DPH, základ nebo DPH v rekapitulaci). Rozdíl do 2 Kč se doúčtuje na 648 nebo 548. |
| Pro cizoměnový doklad vidíte kurzový rozdíl na 663 nebo 563 | Přecenění k rozvahovému dni | Nejde o chybu ([§ 52.14.6.1](#521461-kurzove-preceneni-a-kurzove-rozdily)). |
| Karta **Zkontroluj integritu deníku** na přehledu | Noční kontrola našla nesrovnalost | Klikněte na kartu, nebo v deníku nastavte filtr **Nesrovnalosti doklad ↔ deník** na **Částka zápisu nesedí na doklad** ([§ 52.14.11](#521411-kontrola-integrity-deniku-nocni-job)). |
| Doklad po importu není v deníku | Automatika účtování neprojde doklady, které už v systému leží | Viz [§ 52.9](#529-krok-za-krokem-douctovani-dokladu-z-jineho-systemu). |

## 52.14 Podrobnosti a pravidla

### 52.14.1 Co je účetní deník

**Účetní deník** je jádrem podvojného účetnictví v MyÚčto - chronologický seznam všech
účetních zápisů firmy, tedy dvojic (nebo vícenásobných skupin) řádků **MD** (má dáti) a
**Dal**, u kterých musí vždy platit **Σ MD = Σ Dal**. Najdete ho v menu **Účetnictví →
Účetní deník**. Modul je dostupný jen pro firmy vedené v režimu **podvojné účetnictví**
- firmy na [daňové evidenci](74_Danova_evidence.md) vedou místo něj jednodušší
[Peněžní deník](74_Danova_evidence.md) bez podvojných zápisů.

V seznamu lze kliknutím na záhlaví sloupce řadit zápisy podle data, dokladu,
zdroje, stavu, částky a dalších zobrazených údajů. Řazení platí pro celý
filtrovaný výsledek před stránkováním. První kliknutí řadí sestupně, druhé
vzestupně a třetí vrátí výchozí pořadí.
Křížek na pravém okraji záhlaví tabulky vrátí výchozí pořadí.
Ve víceřádkovém zobrazení má Popis dvojnásobnou šířku oproti běžnému poli
na stejném řádku. Text zůstává na jednom řádku; celý popis ukáže najetí myší
nebo rozbalení zápisu.
Bez popisků hodnot má záhlaví stejné řádky a šířky jako obsah. Se zapnutými
popisky zůstává záhlaví kompaktní.

> [!TIP]
> Deník je jen **evidence toho, co se stalo** - nepředkontovává sám o sobě. Kterým
> účtům (MD/Dal) se má konkrétní doklad zaúčtovat, řeší **předkontace** - viz
> [Účtový rozvrh](66_Ucetni_osnova.md) a
> [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace). Tato kapitola popisuje
> jen samotný deník: jak se v něm zápisy zobrazují, jak založit ruční zápis a jak
> zápis opravit nebo stornovat.

Zaúčtování jakéhokoli dokladu - ať už automatické (faktura, banka, pokladna, majetek),
nebo ruční - vždy prochází stejnou vnitřní službou, která hlídá podvojnost, otevřenost
účetního období a idempotenci. Díky tomu se v deníku nikdy neobjeví nevyrovnaný zápis
ani duplicitní zaúčtování téhož dokladu, ani kdyby doklad někdo omylem zaúčtoval
dvakrát rychle po sobě (dvojklik, výpadek sítě a opakování požadavku apod.).

> [!WARNING]
> **Automatika účtování je háček na VZNIK dokladu**, ne zametač existujících. Spustí se
> při vystavení faktury, přijetí přijaté faktury nebo opakované fakturaci. Doklad, který
> už v systému leží - typicky **naimportovaný z jiného systému** - jí neprojde nikdy, ať
> je nastavená jakkoli. Takové doklady zaúčtuje **Účetnictví → Doúčtovat doklady**
> ([§ 52.14.13](#521413-douctovani-nezauctovanych-dokladu)).

### 52.14.2 Odkud se zápisy berou

Naprostou většinu zápisů do deníku **nezakládáte ručně** - vznikají automaticky jako
vedlejší produkt běžné práce s doklady. Systém k dokladu sestaví vyrovnané řádky MD/Dal
podle nastavených předkontací a zapíše je do deníku; v deníku už jen vidíte výsledek
a můžete se z něj prokliknout zpět na zdrojový doklad. Podle sloupce **Zdroj** rozeznáte:

| Zdroj v deníku | Kdy vzniká |
|---|---|
| **Vydaná faktura** | zaúčtování vydané faktury (311/6xx + DPH na výstupu **343.200** podle [Knihy DPH](42_Kniha_DPH.md)) |
| **Přijatá faktura** | zaúčtování přijaté faktury (321/5xx nebo 04x/02x u majetku + DPH na vstupu **343.100**) |
| **Ostatní pohledávka nebo závazek** | zaúčtování potvrzené položky z agendy Účetnictví → Ostatní pohledávky a závazky; při stornu vzniká opravný zápis |
| **Banka** | spárování položky bankovního výpisu s dokladem - viz [Banka](29_Banka.md) |
| **Pokladna** | zaúčtování pokladního dokladu - viz [Pokladna](32_Pokladna.md) |
| **Zápočet / vypořádání** | vzájemný zápočet nebo jiné vypořádání otevřených položek |
| **Sklad** | zaúčtování příjmu, výdeje nebo inventurního rozdílu zásob |
| **Odpis majetku** | měsíční/roční odpisový běh karty majetku |
| **Zařazení majetku** / **Vyřazení majetku** | uvedení majetku do užívání / jeho vyřazení |
| **Uzavření knih** / **Otevření knih** | roční uzávěrka a otevření nového účetního období |
| **Zúčtování DPH** | měsíční (u čtvrtletního plátce čtvrtletní) interní doklad, který převede vstupní a výstupní daň na **343.900** - viz [Měsíční zúčtování DPH](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph) |
| **Kurzové přecenění** | přecenění cizoměnových zůstatků k rozvahovému dni (viz [§ 52.14.6](#52146-multi-menove-radky-zapisu)) |
| **Ruční** | zápis, který jste založili přímo v deníku (viz [§ 52.14.5](#52145-rucni-zapis)) |

Každý takto vzniklý zápis nese v poli **Zdroj** vazbu na konkrétní doklad
(typ a identifikátor zdroje). Kliknutí otevře postranní souhrn zdroje; z něj lze
přejít do plného detailu podporovaného dokladu. Zdrojový panel funguje pro vydané
a přijaté faktury, banku, pokladnu, majetek, odpisy i vypořádání.

> [!TIP]
> Rychlá cesta z dokladu do deníku a zpět: v detailu vydané/přijaté faktury najdete
> u zaúčtovaného dokladu odkaz **„Zobrazit v deníku"** a badge **Zaúčtováno/Koncept**.
> V deníku pak filtr **Zdroj** + drill-down podle `source_id` zobrazí přesně zápis
> k danému dokladu.

#### 52.14.2.1 Idempotence - proč doklad nejde zaúčtovat dvakrát

Dvojice `(typ zdroje, ID zdrojového dokladu)` smí mít nejvýš **jeden aktivní
(nestornovaný) zápis** - v databázi to hlídá unikátní klíč přímo nad tabulkou zápisů.
Stornované zápisy se do něj nepočítají, aby po stornu bylo kam zapsat opravu; ochrana
proti dvojímu zaúčtování tím ale nijak neslábne. Opětovné zaúčtování téhož dokladu
(např. po opravě údajů na faktuře, nebo když stejný požadavek odejde omylem dvakrát)
proto **nikdy nevytvoří druhý zápis**:

- systém k dokladu nejdřív zkusí najít existující zápis; pokud ho najde, **smaže jeho
  původní řádky MD/Dal a nahradí je nově spočítanými** - zápis si zachová stejné ID,
  jen se mu zvýší interní číslo verze (číslo verze) a přepíšou se částky, popis
  i datum dokladu podle aktuálního stavu zdrojového dokladu,
- pokud dva požadavky na zaúčtování téhož dokladu odejdou **současně** (typicky
  dvojklik na tlačítko), databáze druhý souběžný pokus o vložení odmítne jako
  duplicitu - aplikace to detekuje a automaticky ho převede na stejný přepis popsaný
  výše, takže výsledek je stejný, ať se doklad zaúčtuje jednou, nebo omylem vícekrát
  „najednou",
- přepis se **neprovede** u zápisu, který je mezitím **stornovaný** (viz
  [§ 52.14.9](#52149-storno-a-oprava-zauctovaneho-zapisu)) - takový zápis se z principu už
  nesmí měnit, doklad je nutné zaúčtovat jako zcela nový zápis,
- přepis se **neprovede** ani tehdy, když se aktuální zápis nachází v mezitím
  **uzavřeném** účetním období - do uzavřeného období nejde zasáhnout, i kdyby šlo jen
  o přepočet stejného dokladu (§35 zákona o účetnictví).

Ruční zápisy (zdroj Ruční) žádné `source_id` nemají, takže se na ně
idempotence nevztahuje - každé uložení ručního zápisu je vždy nový samostatný zápis.

#### 52.14.2.2 Jak vzniká popis zápisu

Popis je to jediné, podle čeho účetní v seznamu pozná, o jaký účetní případ jde, proto
ho systém skládá **z údajů dokladu**, ne jen z jeho textu. Segmenty jsou oddělené
pomlčkou a jdou vždy v pořadí **doklad - protistrana - věcný obsah**:

| Zdroj | Tvar popisu | Příklad |
|---|---|---|
| Vydaná faktura | zkratka a číslo dokladu, odběratel, obsah prvního řádku | `FV 2099001234 - Odběratel s.r.o. - pronájem místa` |
| Přijatá faktura | zkratka a číslo řady, dodavatelské číslo, dodavatel, obsah | `PF 2099-0007 / dod. VF-2099-88 - Dodavatel a.s. - leasing vozidla` |
| Banka | číslo výpisu, směr platby, protistrana a variabilní symbol, zpráva pro příjemce | `Banka 2099/004 - příchozí platba - Odběratel s.r.o. (VS 2099001234) - Platba faktury` |
| Pokladna | číslo dokladu, pokladna, účastník (§ 11/1/b), obsah | `PPD-2099-0042 - Pokladna Hlavní - Jan Novák - nákup kancelářských potřeb` |
| Majetek a odpisy | druh operace, rok, karta majetku | `Účetní odpis 2099 - Užitkový vůz` |
| Uzávěrka, mzdy, zápočty | druh operace a období | `Uzavření účetních knih 2099` |

Pravidla, která přitom platí:

- **Chybí-li některý údaj** (nespárovaná platba bez názvu protistrany), segment se
  vynechá - místo názvu se použije alespoň protiúčet nebo zpráva pro příjemce.
- **Text zadaný ručně** (popis v dialogu zaúčtování, popis pravidla automatiky) se
  nezahazuje - zůstane jako poslední segment za identifikací dokladu.
- **Popis je deterministický**: opakované zaúčtování téhož dokladu vyrobí týž text,
  takže se v historii zápisu neobjeví změna, ke které věcně nedošlo.
- **Délka** je omezená na 255 znaků a zkracuje se na hranici slova (na konci je `…`).

> [!TIP]
> Máte-li deník **převzatý z jiného systému** (POHODA, Money S3), nesou starší zápisy
> popis z jediného pole původní agendy a bývají navlas stejné. Popisy jde kdykoli
> dogenerovat - viz [§ 52.14.13.1](#5214131-dogenerovani-popisu-u-prevzatych-zapisu).

### 52.14.3 Seznam zápisů

Stránka **Účetní deník** zobrazuje postupně načítaný seznam zápisů (50 na dávku,
další se načte při posunu dolů nebo tlačítkem **Načíst další**; zápisy jsou seřazené od nejnovějších - nejdřív podle data
zápisu, při shodném datu podle pořadí vzniku) se sloupci:

- **Datum** - datum účetního případu (datum účetního případu),
- **Doklad** - číslo dokladu/zápisu; které číslo nese přijatá faktura, popisuje [§ 23.11.5](23_Prijate_faktury.md#23115-povinna-pole),
- **Datum dokladu** *(skryto ve výchozím zobrazení)* - datum vyhotovení, pokud se liší od data zápisu,
- **Popis**,
- **Zdroj** - typ a číslo zdrojového dokladu; odznak **Automaticky** se u
  automatického zápisu zobrazuje přímo v tomto sloupci, stejně jako ikona
  řetězu u zápisů, které mají protějšek (doklad ↔ jeho úhrada - viz
  [Souvisí: doklad a jeho úhrada](#521432-souvisi-doklad-a-jeho-uhrada)),
- **Částka** - bez filtru **Účet od / Účet do** celková částka zápisu (Σ MD, u
  vyváženého zápisu shodná se Σ Dal); s aktivním filtrem na účet naopak částka
  PŘIPADAJÍCÍ na filtrovaný rozsah účtů v daném zápisu, se značkou **MD**/**Dal**
  za částkou - u zápisu s víc nohama na různých účtech (např. náklad + zúčtování
  zálohy) by jinak sloupec ukazoval součet celého zápisu, ne částku vybraného účtu,
- **Stav** - badge **Zaúčtováno** (zeleně) nebo **Koncept** (šedě),
- **Zaúčtováno dne**, **Zaúčtoval**, **ID zápisu**, **Vytvořeno** a **Změněno**
  *(skryto ve výchozím zobrazení)*.

Mezi volitelnými sloupci jsou také **Rozpad DPH** podle sazeb u faktur,
**Účty MD/Dal** a při zapnutých dimenzích firmy také **Dimenze** z řádků zápisu.
Tyto podrobnosti se načítají až po zapnutí
příslušného sloupce. Když se údaje nevejdou na jeden řádek podle šířky okna
nebo pracovního panelu, má každý zápis vlastní popisky hodnot. Hlavní údaje jsou
nahoře a doplňující údaje na jemném podkladu pod nimi. Sloupce v každém řádku
jsou stejně široké a využijí celou šířku. Záhlaví slouží k řazení a přetahování
  sloupců. Přepínač **Zobrazovat popisky: Ano / Ne** v nabídce **Sloupce**
  ukládá vlastní volbu pro přihlášeného uživatele a tento seznam ve všech jeho
  firmách. Bez vlastní volby jsou popisky vypnuté na jednom řádku a zapnuté
  při rozložení na více řádků. Při posunu seznamu zůstává záhlaví viditelné a tabulka má
posuvník u spodního okraje. Na mobilu se zvolené údaje zobrazují v kartách.

V nabídce **Sloupce** je sestava **Výchozí** se stručným seznamem a sestava
**Kompletní** se všemi dostupnými údaji. Potom lze sloupce jednotlivě upravit.
Přepínačem **Hustota** zvolíte kompaktnější nebo prostornější tabulku. Mění výšku
řádků i rozestupy víceřádkových bloků; kompaktní režim zobrazí více zápisů.
Volba se ukládá pro uživatele a tento seznam. Nastavené
kombinace filtrů lze uložit a znovu použít přes **Uložené filtry**.

Sloupce lze přetahovat myší za záhlaví s tečkovanou ikonou. Barevná čára ukáže,
kam se sloupec přesune. Pořadí záhlaví i buněk se změní společně, také ve
víceřádkovém zobrazení. Pořadí se automaticky ukládá do profilu přihlášeného
uživatele pro tento seznam a platí ve všech jeho firmách. V nabídce **Sloupce**
je tlačítko **Obnovit pořadí sloupců**, které jedním kliknutím vrátí původní
pořadí a zachová vybrané sloupce, barvy i filtry.

Nabídka **Barvy položek** s ikonou palety umožňuje nastavit vlastní podklad buněk
jednotlivých sloupců. Písmo se automaticky přepne na černé nebo bílé podle kontrastu,
takže zůstává čitelné ve světlém i tmavém režimu. Volby se ukládají automaticky
pro přihlášeného uživatele, zvlášť pro každý seznam, a platí ve všech jeho firmách.
Tlačítko **Obnovit výchozí barvy** vrátí všechny barvy jedním kliknutím; šipka
u sloupce obnoví jen jeho barvu. Výběr sloupců, filtry a hustota se přitom nemění.

#### 52.14.3.1 Drill-down na zdrojový doklad

Kliknutí na **Zdroj** otevře read-only postranní panel se souhrnem zdroje, aniž by
uživatel ztratil rozevřený deník a filtry. Podle typu nabízí odkaz do plného detailu:

- **vydaná/přijatá faktura** - detail faktury,
- **banka** - detail bankovního výpisu, ke kterému spárovaná transakce patří (viz
  [Banka](29_Banka.md)); zápis totiž vzniká z jednotlivé transakce, proklik vás ale vezme
  rovnou na výpis, který ji obsahuje,
- **pokladna** - seznam **Pokladna** předfiltrovaný na konkrétní pokladnu a číslo
  dokladu (samostatnou stránku detailu pokladní doklad nemá, filtr ale dokladu obratem
  najde - viz [Pokladna](32_Pokladna.md)).

- **majetek a odpis** - karta majetku nebo příslušný odpis,
- **vypořádání** - detail vazeb vypořádaných položek.

U technických zdrojů bez samostatného detailu, například u uzavření knih nebo
kurzového přecenění, zůstane zdroj textový.

#### 52.14.3.2 Souvisí: doklad a jeho úhrada

Deník vede fakturu a její úhradu jako **dva samostatné zápisy** - předpis (311/6xx,
resp. 5xx/321) a úhradu (221/311, resp. 321/221). Účetní je ale řeší jako jeden
případ, proto má každý takový zápis panel **Souvisí**: v rozbaleném řádku deníku
i v postranním náhledu zdrojového dokladu.

Panel u každého protějšku ukazuje typ (banka, pokladna, zápočet, faktura), číslo,
datum, částku a nabízí tři cesty:

- **Náhled** - přepne postranní panel na *zaúčtování protějšku*, aniž byste opustili
  deník; zpět se vrátíte šipkou v hlavičce panelu,
- **Zápis #…** - odskok na protějšek přímo v deníku (deep-link `?entry_id=`),
- **Otevřít doklad** - detail faktury, bankovní výpis s danou transakcí nebo
  předfiltrovaná pokladna.

Vazby se hledají přes evidenci plateb, párování bankovních transakcí (včetně
souhrnných plateb pokrývajících víc dokladů), pokladní doklady navázané na fakturu
a zápočty. Když transakce pokryla jen část dokladu nebo naopak víc dokladů najednou,
panel vedle celkové částky pohybu uvádí i **částku připadající na tento doklad**.

Protějšek, který ještě **není zaúčtovaný**, je označený štítkem *Nezaúčtováno* -
je to typický důvod, proč saldo nesedí s deníkem, takže se záměrně nezamlčuje.

Ikona řetězu ve sloupci **Zdroj** ukazuje, které zápisy protějšek mají, ještě než
řádek rozbalíte.

Kromě takto **odvozených** vazeb panel ukazuje i **ruční vazby na doklad** (viz
[Vazba na doklad](#521474-vazba-na-doklad)) - mají vlastní barvu štítku, protože nevznikly
z evidence plateb, ale zadal je uživatel. Vidíte je z obou stran: u ručního zápisu
jako *Navázaný doklad*, u zaúčtování dokladu jako *Navázaný zápis*.

#### 52.14.3.3 Filtry

Nad tabulkou je filtrační lišta:

- **Číslo dokladu** - hledá částečnou shodu v celém deníku, ne jen na aktuální stránce,
- **Hledat** - hledá v čísle dokladu, popisu a dostupných údajích zdroje; pokud
  zadáte existující kód účtu, například `221.400` nebo `221400`, omezí výsledky na
  pohyby tohoto účtu,
- **Období** - výběr účetního období (fiskální rok) ze seznamu založených období;
  změna období zároveň nastaví **Datum od / Datum do** na jeho hranice,
- **Datum od / Datum do** - rozsah data účetního případu,
- **Zdroj** - omezení na jeden typ zdroje, včetně **Banka** a **Pokladna** (drill-down
  z [Banky](29_Banka.md)/[Pokladny](32_Pokladna.md) i tento filtr vedou ke stejnému
  výsledku, jen jinou cestou - buď z konkrétního dokladu do deníku, nebo z deníku podle
  typu zdroje),
- **Původ** - jen zápisy vytvořené **automaticky**, po **ručním potvrzení**, nebo
  **ručně** bez automatického návrhu,
- **Účet od / Účet do** - omezí deník na rozsah kódů účtů,
- **Částka od / Částka do** - omezí celkovou částku zápisu,
- **Stav** - jen zaúčtované, jen koncepty, nebo vše,
- **Storno** - stav stornování: **Stornované zápisy** (zápis, ke kterému existuje
  protizápis), **Storna (protizápisy)** (samotný stornující zápis), **Stornované i storna**
  (obě strany dvojice pohromadě), nebo **Bez storna**. Bez tohohle filtru se stornované
  dvojice hledají očima - v deníku stojí u sebe jen tehdy, když se nefiltruje podle data,
- **Nesrovnalosti doklad ↔ deník** - volba **Částka zápisu nesedí na doklad** ukáže
  zápisy, u kterých se celková částka dokladu v zaúčtování vůbec neobjeví (typicky doklad
  změněný po zaúčtování). Seznam se počítá živě z aktuálních dat.

Odkaz **„Zrušit filtry"** vrátí výchozí (prázdný) stav. Když do stránky přijdete
prokliknutím z jiného místa aplikace (detail dokladu, uzávěrka, sestavy), filtry se
předvyplní automaticky podle parametrů v URL - typicky se rovnou rozbalí konkrétní zápis
a rozsah data se zúží přesně na den daného zápisu, abyste se v dlouhém deníku neztratili.

V rozbaleném zápisu lze kliknout na kód nebo název účtu. Otevře se jeho opis pohybů
v aktuálně zvoleném rozsahu; bez datového filtru se použije účetní období zápisu.

#### 52.14.3.4 Export PDF / XLSX

Tlačítka **Export PDF** a **Export XLSX** nad tabulkou stáhnou deník **přesně s aktuálně
nastavenými filtry** (fulltext, číslo dokladu, období, rozsah dat, zdroj, původ,
stav, rozsah účtů a částek) - deník je totiž jako jediná
zákonná kniha (§13 zákona o účetnictví), kterou je potřeba mít i mimo aplikaci (archivace,
předložení auditorovi nebo finančnímu úřadu). Export obsahuje zápisy **chronologicky**
(od nejstaršího), u každého hlavičkový řádek se sloupcem **Původ** a řádky **MD/Dal**
s kódem a názvem účtu za VŠECHNY účty zápisu. Částka v hlavičkovém řádku se řídí stejným
pravidlem jako sloupec Částka v seznamu: bez filtru na účet je to celková částka zápisu
(Σ MD, u vyváženého zápisu shodná se Σ Dal, proto je v obou sloupcích), s aktivním filtrem
na účet naopak jen částka připadající na filtrovaný rozsah účtů, ve sloupci MD nebo Dal
podle strany. PDF má **číslované strany**. Export je omezený na max. **5 000 zápisů** najednou
- při větším rozsahu zužte filtr (typicky Datum od/do) a export zopakujte po částech.

#### 52.14.3.5 Rozklik na detail zápisu

Klikem na řádek se zápis rozbalí a zobrazí:

- tabulku **řádků zápisu** - účet (kód + název z osnovy), středisko, částka na straně
  **MD** nebo **Dal**; u cizoměnových řádků i částka v původní měně pod částkou v CZK
  (viz [§ 52.14.6](#52146-multi-menove-radky-zapisu)),
- součtový řádek **Celkem**,
- **popis zápisu** s možností úpravy a **přílohy** dokladu (viz
  [§ 52.14.7](#52147-popis-prilohy-a-poznamky)),
- samostatné **poznámky**, které lze připnout, upravit a zachovat s autorem,
- panel **Související dokumenty** pro vazby na dokumentový archiv,
- datum **vytvoření** zápisu,
- panel **Proč se to stalo** u automatizovaných zápisů - ukáže zdroj rozhodnutí (například
  shodu platby nebo pravidlo), režim automatického zpracování a dostupné auditní údaje,
- tlačítko **Kopírovat jako nový** - otevře formulář **Ruční účetní zápis** s předvyplněnými
  stejnými řádky (účet, strana, částka) a **dnešním datem**; hodí se pro doklady, které se
  opakují bez šablony (jednorázová varianta oproti šablonám, viz [§ 52.14.5](#52145-rucni-zapis)),
- tlačítko **Stornovat** (u aktivního zaúčtovaného zápisu), nebo odkaz na **stornující
  zápis** (u již stornovaného),
- tlačítko **Smazat zápis i storno** (u stornovaného zápisu v otevřeném období) - viz
  [§ 52.14.9.4](#521494-smazani-cele-storno-dvojice).

Zápisy, které už byly stornovány, mají v seznamu badge **Stornováno** a jsou vizuálně
ztlumené (nižší kontrast řádku).

### 52.14.4 Koncept vs. zaúčtovaný zápis

Zápis může být ve dvou stavech:

- **Koncept** (datum zaúčtování prázdné) - zápis existuje, ale nebyl finálně zaúčtován,
- **Zaúčtováno** (datum zaúčtování vyplněné) - zápis je platný a započítává se do hlavní
  knihy, obratové předvahy i výkazů (tam na koncepty upozorňuje hláška „V rozsahu je
  N nezaúčtovaných konceptů - nejsou zahrnuty").

Rozlišení najdete v badge sloupce **Stav** i ve filtru **Stav**. Koncept se u
přepisovatelných dokladů (viz idempotence v [§ 52.14.2.1](#521421-idempotence-proc-doklad-nejde-zauctovat-dvakrat))
při opravě zdrojového dokladu jednoduše přepíše - storno dává smysl **jen u zaúčtovaného**
zápisu (koncept se opraví/nahradí přímo, ne protizápisem).

### 52.14.5 Ruční zápis

Tlačítkem **„Ruční zápis"** na hlavní stránce deníku (jen role s právem zápisu - účetní/
administrátor) otevřete formulář **Ruční účetní zápis** (`/accounting/journal/new`).

#### 52.14.5.1 Hlavička

- **Datum zápisu** - povinné, datum účetního případu; musí spadat do **existujícího a
  otevřeného** účetního období, jinak zaúčtování selže s chybou o chybějícím/uzavřeném období,
- **Číslo dokladu** - volitelné; necháte-li prázdné a firma má na stránce
  [Účetní období](72_Uzaverka.md) zapnutou volbu **„Automatická čísla dokladů ručních
  zápisů (řada ID)"**, systém číslo přidělí automaticky z číselné řady vedené pro ruční
  zápisy (spravuje se v `Nástroje → Účetní nastavení`, záložka **Číselné řady**),
- **Popis** - volitelný text zápisu (max. 255 znaků).

#### 52.14.5.2 Řádky zápisu

Tabulka řádků, kde ke každému přidáte:

- **Kód účtu** - textové pole s **našeptávačem** (datalist) nad aktivními účty firemní
  osnovy; při rozpoznaném kódu se pod polem zobrazí název účtu, při nerozpoznaném
  hláška „Účet není v osnově",
- **Stranu** - **MD** nebo **Dal**,
- **Částku** - kladné číslo, na 2 desetinná místa (vždy v účetní měně CZK - ruční zápis
  přes formulář **nepodporuje** zadání cizí měny/kurzu na řádku, na rozdíl od
  automatických zápisů z cizoměnových faktur, viz [§ 52.14.6](#52146-multi-menove-radky-zapisu)),
- **Červené storno** - částka zůstává v poli kladná, ale zápis ji odečte na
  zvolené původní straně MD nebo Dal. V deníku a výkazech se zobrazuje záporně.
  Běžné storno na opačnou stranu funguje samostatně; stejný zůstatek neznamená
  stejný obrat. Kopírování i přeúčtování zápisu zachová příznak červeného storna.
  Při rozúčtování čistě červeného zápisu nový řádek převezme stejné znaménko.
  Opis účtu, otevřené položky a jejich exporty zobrazují záporný účetní účinek
  na původní straně. Takový zápis zatím nelze uložit jako šablonu.
- **Středisko** - volitelné analytické členění. Pole našeptává aktivní položky z firemního
  číselníku **Nástroje → Účetní nastavení**, záložka **Střediska**, ale kvůli kompatibilitě historických zápisů lze
  ponechat i vlastní volný text.

Tlačítkem **„+ Přidat řádek"** přidáte další řádek, křížkem u řádku ho odeberete (musí
zůstat aspoň jeden). Do nabídky účtů se dostanou jen **aktivní** účty (syntetika i
analytika), neaktivní se v novém zápisu nenabízí.

#### 52.14.5.3 Kontrola vyrovnanosti

Pod tabulkou řádků systém průběžně počítá součty **MD** a **Dal** a zobrazuje badge:

- **„Vyrovnáno"** (zeleně) - Σ MD = Σ Dal a součet je kladný,
- **„Rozdíl X"** (žlutě) - zápis není vyrovnaný, X je rozdíl MD − Dal.

Tlačítko **„Zaúčtovat"** je aktivní až když je zápis vyrovnaný **a** žádný řádek nemá
prázdný účet nebo nekladnou částku. Stejnou podmínku (**Σ MD = Σ Dal**, kontrolovanou
na zaokrouhlených částkách **v haléřích**, tedy přesně na tom, co se skutečně uloží,
nikoli přes nepřesné porovnání desetinných čísel) vynucuje i backend - ruční obejití
kontroly na frontendu tedy nic nezmůže, server nevyrovnaný zápis vždy odmítne.

Po úspěšném uložení se zápis rovnou zaúčtuje (doplní se datum zaúčtování) a přesměruje vás zpět
do seznamu deníku.

#### 52.14.5.4 Nejčastější chybové hlášky při ukládání

Kromě nevyrovnanosti může uložení ručního zápisu odmítnout i z dalších důvodů -
všechny hlídá server bez ohledu na to, co propustí formulář:

| Situace | Co uvidíte |
|---|---|
| Chybí datum zápisu | „Vyplňte datum" (frontend) / `entry_date musí být datum` (backend) |
| Žádný řádek nemá vyplněný účet nebo částku | „Vyplňte všechny řádky" |
| Součet MD ≠ součet Dal | „Zápis není vyrovnaný" + konkrétní rozdíl |
| Zadaný kód účtu není v účtové osnově firmy | Účet ✱ není v účtové osnově - zkontrolujte kód |
| Pro datum zápisu neexistuje založené účetní období | Pro zadané datum neexistuje účetní období |
| Účetní období pro dané datum je uzavřené / uzavírá se | Do uzavřeného období nelze účtovat (§35 ZoÚ) |

#### 52.14.5.5 Příklad: ruční zápis se dvěma řádky

Účetní potřebuje zaúčtovat zálohu na pracovní cestu vyplacenou zaměstnanci z hotovosti
mimo běžný pokladní doklad. Založí ruční zápis takto:

| Datum zápisu | Číslo dokladu | Popis |
|---|---|---|
| 15. 3. 2026 | (necháno prázdné → přidělí se automaticky) | Záloha na pracovní cestu - Novák |

| Účet | Název | Středisko | MD | Dal |
|---|---|---|---:|---:|
| 335 | Pohledávky za zaměstnanci | - | 5 000,00 | |
| 211 | Pokladna | - | | 5 000,00 |
| | **Celkem** | | **5 000,00** | **5 000,00** |

Badge pod tabulkou ukáže **„Vyrovnáno"** (Σ MD = Σ Dal = 5 000 Kč), tlačítko
**„Zaúčtovat"** se odemkne a po odeslání zápis rovnou vznikne jako **Zaúčtováno**.

#### 52.14.5.6 Převod mezi účty (261 - Peníze na cestě)

Vedle tlačítka Zaúčtovat je i tlačítko **„Převod banka ↔ pokladna"**, které otevře
samostatný dialog **Převod mezi účty (261)**. Použijete ho pro přesun peněz mezi dvěma
účty firemní osnovy (typicky mezi bankovními účty, nebo banka/pokladna), kdy odeslání
a přijetí spadá do různých dat - systém vytvoří **dvě nohy** přes účet **261 - Peníze
na cestě** (MD 261 / D zdrojový účet při odeslání, MD cílový účet / D 261 při přijetí),
sdílející číslo dokladu z vlastní číselné řady **PP** (přebírá se ze stejné správy
číselných řad jako řada pro ruční zápisy - viz [Účetní období](72_Uzaverka.md)). Zadáte:

- **Z účtu** / **Na účet** - kódy účtů (musí existovat v osnově a být různé),
- **Částka** - kladná,
- **Datum odeslání** / **Datum přijetí**,
- **Popis** *(volitelné)*.

Po odeslání se obě nohy zaúčtují najednou a dialog se zavře s potvrzením čísel dokladů.

#### 52.14.5.7 Uložit a nový

Tlačítko **„Uložit a nový"** vedle **„Zaúčtovat"** uloží rozepsaný zápis stejně jako
běžné odeslání, ale místo přesměrování do deníku **vyčistí řádky** pro další zápis
(datum a popis zůstávají). Hodí se, když účetní zapisuje víc podobných interních
dokladů za sebou (např. zálohy víc zaměstnancům ve stejný den).

#### 52.14.5.8 Šablony ručních zápisů a mzdový můstek

Ruční zápisy, které se opakují (mzdy z externí mzdovky, splátky leasingu), nemusíte
každý měsíc vyklikávat znovu - uložte si je jako **šablonu**.

**Uložit jako šablonu** - tlačítko vedle **„Zaúčtovat"** (aktivní, jakmile má každý
rozepsaný řádek vyplněný kód účtu). V dialogu zadáte:

- **Název šablony** - povinný,
- **Popis** - volitelný,
- **Pojmenování řádků** - ke každému řádku (účet + strana pro orientaci) volitelný
  název pro čitelnost, např. „Hrubé mzdy", „Sociální pojištění zaměstnavatele" -
  usnadní pozdější napárování CSV importu (viz níže),
- **„Uložit i aktuální částky jako výchozí"** - nezaškrtnuto (výchozí): šablona si
  pamatuje jen kostru (účty, strany, pojmenování) a částky zůstanou při použití
  **prázdné k doplnění** - typické pro mzdy, kde se částka mění každý měsíc. Zaškrtnete
  ji u zápisů s pevnou částkou (např. fixní splátka leasingu).

**Nový ze šablony** - tlačítko v hlavičce formuláře ručního zápisu. Vyberete uloženou
šablonu ze seznamu (nebo ji smažete křížkem, pokud už není potřeba) a tlačítkem
**„Použít šablonu"** se řádky předvyplní do gridu. **Nic není uzamčené** - účet, strana
i částka na každém řádku se dají v gridu běžně přepsat, šablona je jen výchozí bod.
Prázdné částky (typicky mzdy) doplníte ručně, nebo je napárujete z CSV (viz dále).
Datum zápisu se nemění (zůstává dnešek, případně datum, které jste už vyplnili).

Všechny uložené šablony najdete také v **Nástroje → Šablony účtování**, záložka **Šablony zápisů**. Na této záložce
lze šablonu vytvořit, upravit její název, popis i jednotlivé řádky (účet, stranu,
výchozí částku, pojmenování a středisko), smazat ji nebo z ní rovnou založit nový
ruční zápis.

Záložka **Nástroje → Šablony účtování → Šablony pravidel** spravuje firemní katalog pravidel
nabízených přes **Nástroje → Šablony účtování → Pravidla účtování → Ze šablony**.
U šablony lze nastavit český a anglický název, směr, kritéria shody, typ operace,
kontaci, prioritu, pořadí a aktivní stav. Úprava se projeví jen při
budoucím vytvoření pravidla; již existující firemní pravidla zůstávají beze změny.
Použitou šablonu lze deaktivovat, nikoli smazat.

Firemní číselník středisek se spravuje v **Nástroje → Účetní nastavení**, záložka **Střediska**. Při založení se kód
automaticky vytvoří z názvu a před uložením jej lze ručně upravit. Uložený kód je pak
neměnný; upravovat lze název a stav aktivní/neaktivní. Aktivní střediska se nabízejí našeptávačem
v ručním zápisu i v editoru šablon. Středisko, které už je použité, se při odstranění
jen deaktivuje, aby zůstaly historické účetní zápisy čitelné.

Firma vedená v podvojném účetnictví má automaticky k dispozici doporučenou šablonu
**„Mzdy"** (badge „doporučená"), naseedovanou při prvním otevření dialogu **Nový ze
šablony**, s řádky:

| Účet | Strana | Pojmenování |
|---|---|---|
| 521 | MD | Hrubé mzdy |
| 524 | MD | Sociální a zdravotní pojištění za zaměstnavatele |
| 331 | Dal | Závazek vůči zaměstnancům (čistá mzda k výplatě) |
| 336 | Dal | Zúčtování se OSSZ a zdravotními pojišťovnami |
| 342 | Dal | Záloha na daň ze závislé činnosti |

Jde o **mzdový můstek pro externí mzdovku** - šablona drží typický předpis pro
rychlé zaúčtování importované rekapitulace. Vedle něj je k dispozici také obrazovka
**Účetnictví → Mzdová rekapitulace**, která umí z měsíčních vstupů vypočítat náhled
a zaúčtovat předpis. Obě cesty jsou alternativní: tentýž měsíc nezaúčtovávejte jednou
importem a podruhé mzdovou rekapitulací. Mzdový list a jeho podklady popisuje
[Mzdách](64_Mzdy.md).

##### Import rekapitulace z CSV

Po výběru šablony se v dialogu **Nový ze šablony** zobrazí sekce **„Import rekapitulace
z CSV"**. Nahrajete CSV soubor se **dvěma sloupci** (oddělovač `;` nebo `,`, volitelná
hlavička se přeskočí automaticky):

1. **název položky** (musí odpovídat pojmenování řádku šablony, diakritika a velikost
   písmen se ignorují) **nebo kód účtu** (např. `521`),
2. **částka** (podporuje český i anglický formát desetinné čárky/tečky).

Příklad CSV z externí mzdovky:

```csv
Položka;Částka
Hrubé mzdy;185000
524;62790
Závazek vůči zaměstnancům (čistá mzda k výplatě);134800
336;42200
342;27790
```

Tlačítkem **„Nahrát a napárovat"** systém napáruje řádky CSV na řádky šablony a rovnou
je předvyplní do gridu ručního zápisu (žádný zápis se přitom nezapisuje do databáze -
jde jen o náhled/předvyplnění). Položky, které se nepodařilo napárovat (překlep v názvu,
neznámý účet), se ohlásí toastem s počtem - zkontrolujte názvy nebo kódy účtů a doplňte je
ručně. Import je jednorázový - soubor se nikam neukládá, slouží jen k rychlému vyplnění
aktuálního zápisu.

### 52.14.6 Multi-měnové řádky zápisu

Zákon o účetnictví (§4 odst. 12) vyžaduje, aby se pohledávky, závazky, valuty, ceniny
a devizové účty vedly **současně v cizí měně i v korunách**. MyÚčto proto u řádků
deníku (kromě běžné částky v CZK) volitelně ukládá:

- **měnu** řádku (např. EUR, USD) - `NULL`, pokud je řádek v účetní měně (CZK),
- **kurz** k účetní měně, kterým byla částka přepočtena,
- **částku v cizí měně** - tj. původní částku dokladu v jeho měně.

Tyto tři údaje se vyplní **automaticky** jen na saldokontních řádcích (311 - odběratelé,
321 - dodavatelé) vznikajících ze zaúčtování **cizoměnové** vydané nebo přijaté faktury.
Faktura vystavená/přijatá v CZK má vždy kurz 1,0 a tyto sloupce zůstávají prázdné (není
co přeceňovat). **Ruční zápis** cizí měnu na řádku zadat neumožňuje - všechny jeho
částky jsou vždy v CZK.

V rozbaleném detailu zápisu se cizoměnová částka zobrazí jako malý řádek pod částkou
v CZK (viz [§ 52.14.3.5](#521435-rozklik-na-detail-zapisu)), takže u faktury v eurech vidíte
zaokrouhlenou korunovou částku i skutečnou částku v EUR, ze které vznikla.

#### 52.14.6.1 Kurzové přecenění a kurzové rozdíly

K rozvahovému dni (v rámci [uzávěrky](72_Uzaverka.md)) systém přecení otevřené
cizoměnové zůstatky aktuálním kurzem a rozdíl proti účetně vedené hodnotě zaúčtuje jako
samostatný zápis se zdrojem **Kurzové přecenění** - kurzový **zisk** na účet **663**,
kurzová **ztráta** na účet **563** (přesný účet určuje předkontace `fx.gain`/`fx.loss`,
s výchozí hodnotou 663/563, pokud si ji ve firemní osnově nepřenastavíte). Totéž pravidlo
(663 zisk / 563 ztráta) platí i pro přecenění cizoměnových zůstatků na bankovních účtech.

> [!TIP]
> Drobný haléřový rozdíl, který u automaticky zaúčtovaných faktur vznikne jen
> zaokrouhlením (ne kurzem), se nepočítá jako kurzový rozdíl - doúčtuje se na účet
> **648** (ostatní provozní výnos) nebo **548** (ostatní provozní náklad), aby seděla
> podvojnost do posledního haléře.
>
> Toto dorovnání má ale **strop 2 Kč** - když se celková částka dokladu neshoduje se
> základem a DPH spočtenými z položek/DPH evidence o víc než 2 Kč, zaúčtování se
> **odmítne** (s rozpisem obou částek a rozdílu) místo tichého zápisu na 648/548. Nad
> touto hranicí už nejde o zaokrouhlení, ale o skutečný nesoulad dokladu (špatná DPH
> klasifikace položky, ručně přepsaný základ/DPH v rekapitulaci DPH apod.) - je potřeba
> doklad opravit, ne rozdíl zamaskovat jako výnos/náklad. Jde o jinou toleranci než
> 1 Kč dorovnání u spárovaných bankovních plateb (viz [Banka: spárované platby faktur](29_Banka.md#2913131-sparovane-platby-faktur-primy-zapis)) -
> to řeší jen zaokrouhlení mezi součtem alokací a částkou platby, ne shodu hlavičky
> dokladu s DPH evidencí.

> [!TIP]
> Účty **701, 702** a **710**, na které se během roční uzávěrky účtují zápisy se
> zdrojem Uzavření/Otevření knih, mají v účtové osnově vlastní typ **„Závěrkový"**
> (odlišný od Kapitálu) - viz [Účtový rozvrh](66_Ucetni_osnova.md) a
> [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace).
> Díky tomu je výkazy (rozvaha/VZZ) do vlastního kapitálu firmy nezapočítávají.

### 52.14.7 Popis, přílohy a poznámky

Po rozbalení detailu zápisu jsou pod řádky MD/Dal dvě další sekce.

#### 52.14.7.1 Inline editace popisu

U zápisů se zdrojem **Ruční**, **Uzavření knih** nebo **Otevření knih** (jediné typy,
u kterých popis nespravuje jiný doklad) se u popisu zobrazí ikona **tužky** - kliknutím
otevřete textové pole (max. 255 znaků, Enter uloží, Esc zruší). U ostatních zdrojů
(faktura, banka, pokladna, majetek…) je popis **uzamčen** - text „Popis se edituje na
zdrojovém dokladu" vysvětluje, že popis patří k dokladu samotnému (pokus o editaci na
serveru vždy skončí stejnou chybou, i kdyby se ji frontend nepokusil zabránit). Popis
takového zápisu se skládá z údajů dokladu, viz [§ 52.14.2.2](#521422-jak-vznika-popis-zapisu).

Úprava popisu funguje i na **už zaúčtovaném** zápisu - nejde o obcházení neměnnosti
účetnictví, protože je **auditovaná**: u zaúčtovaného zápisu se navíc zobrazí varování
„Změna zaúčtovaného zápisu je auditována (§35)" a systém uloží before/after hodnotu do
activity logu v téže databázové transakci jako samotnou změnu - nemůže tedy nastat stav,
kdy by se popis změnil, ale záznam o tom v auditní stopě chyběl. Úprava respektuje
otevřenost období (do uzavřeného období nejde zasáhnout) a je zamítnutá i u zápisu,
který je mezitím **stornovaný**.

**Optimistická konkurence.** Formulář si drží interní číslo verze zápisu (číslo verze),
které pošle spolu s uloženým textem. Pokud zápis mezitím upravil (nebo zaúčtoval znovu)
jiný uživatel, server uložení odmítne a v aplikaci se ukáže hláška **„Zápis mezitím
změnil jiný uživatel - načetl jsem aktuální stav, zkuste to prosím znovu."** Aplikace
si zároveň sama načte aktuální stav zápisu (aktuální popis i nové číslo verze) a
předvyplní jím editační pole, takže rozepsaný text se **neztratí** - porovnáte si ho
s tím, co mezitím uložil kolega, a uložení jednoduše zopakujete.

#### 52.14.7.2 Přílohy zápisu

Sekce **Přílohy** umožňuje k ruční nebo jinak vzniklé položce deníku připojit skutečný
doklad (sken, PDF, fotku) - nezávisle na tom, jestli má zdrojový doklad (faktura) své
vlastní přílohy v [Dokumentech](34_Dokumenty.md). Přílohy zápisu se ukládají do
vlastního úložiště, odděleného od dokumentového systému faktur. Rozpoznávají se
formáty **PDF, obrázek (JPG/PNG…), XML, ISDOC, ZFO** - ostatní podporované, ale jinak
nekategorizované typy padnou pod obecné „ostatní". Ovládání:

- tlačítko **„Přidat přílohu"** nebo přetažení souborů do vyznačené zóny (drag & drop,
  **více souborů najednou**; při vícesouborovém nahrání se každý soubor vyhodnotí
  samostatně - jedna vadná příloha v dávce nezastaví nahrání ostatních, u chybné se jen
  zobrazí důvod),
- u každé přílohy vidíte **název souboru**, **popisek** (upravitelný inline ikonou tužky,
  max. 255 znaků, „bez popisku" když není vyplněný), **velikost** a **datum nahrání**,
- ikona **stažení** a (s právem zápisu) **koš** pro smazání.

Nahrávání hlídá dva limity: jeden soubor smí mít max. **20 MiB**, součet velikostí všech
příloh jednoho zápisu smí dosáhnout max. **100 MiB** - po jeho překročení další nahrání
odmítne s hláškou o překročení celkového limitu. Typ souboru se nepozná podle přípony
ani podle toho, co pošle prohlížeč, ale **z obsahu souboru** (magic-byte/finfo detekce);
nebezpečné typy (spustitelné soubory a další zakázané přípony/MIME typy) jsou
blokované bez ohledu na to, jak se soubor jmenuje. Stejný soubor (podle otisku obsahu
- sha256, ne podle názvu) nejde k témuž zápisu nahrát dvakrát - druhý pokus skončí
hláškou o duplicitě („Tato příloha už je u zápisu evidována"), ale bajty na disku se
při první shodě jen sdílí, žádná duplicita dat nevzniká. Smazáním přílohy zmizí záznam
v deníku; samotná data na disku se smažou, jen pokud už je nesdílí jiná (stejný obsah
nahraný vícekrát se ukládá jednou, dokud ho drží alespoň jeden záznam).

Stažení přílohy vždy vynutí uložení souboru na disk (nikdy se neotevře přímo v
prohlížeči) - bezpečnostní opatření proti spuštění škodlivého obsahu z prohlížeče.

> [!TIP]
> Role **jen pro čtení** vidí přílohy i popis zápisu, ale nemůže nic nahrávat, mazat
> ani editovat - tlačítka pro zápis se jí nezobrazí.

#### 52.14.7.3 Poznámky k zápisu

Poznámky jsou oddělené od účetního popisu. Jeden zápis jich může mít více a
každá nese autora a čas vytvoření či poslední úpravy. Text může mít až 5 000
znaků; v detailu se načítá nejvýše 200 živých poznámek. Důležitou poznámku lze
**připnout**, takže zůstane před ostatními. Uživatel s právem zápisu může
poznámku upravit nebo ji odstranit; odstranění je auditovatelné měkké smazání,
nikoli přepis historie účetního zápisu.

Tytéž poznámky jsou vidět a jdou psát i mimo deník: v řádku zaúčtovaného
[bankovního pohybu](29_Banka.md#291313-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi)
(volba **Poznámka**) a v sekci **Zaúčtování** na detailu vydané i přijaté faktury.
Poznámka má jediné úložiště, u zápisu v deníku.

> [!TIP]
> Poznámka slouží pro pracovní vysvětlení a předání případu kolegovi. Nenahrazuje
> účetní doklad ani přílohu, která tvrzení prokazuje. Role jen pro čtení poznámky
> vidí, ale nemůže je měnit.

#### 52.14.7.4 Vazba na doklad

Interní zápis často *souvisí* s konkrétním dokladem, aniž by byl jeho zaúčtováním -
dohadná položka k faktuře, kurzový rozdíl, přeúčtování, oprava. V rozbaleném detailu
zápisu je proto sekce **Vazba na doklad**: tlačítkem **Navázat doklad** otevřete
našeptávač, který hledá napříč vydanými i přijatými fakturami, pokladními doklady
a bankovními pohyby (podle čísla dokladu, variabilního symbolu nebo názvu partnera).
K vazbě lze připsat **poznámku**, proč spolu doklady souvisejí.

Vazbu jde založit i rovnou při zakládání [ručního zápisu](#52145-rucni-zapis) - sekce
Vazba na doklad je i ve formuláři nového zápisu a uloží se jedním krokem se zápisem.
Při akci **Kopírovat jako nový** se vazby zkopírují spolu s řádky.

Co vazba **není**: doklad se jí nezaúčtuje ani neoznačí za vyřízený. Zápis zůstává
ručním zápisem, doklad si dál vede vlastní zaúčtování a nic se nemění na kontrolách
salda ani na tom, které doklady systém považuje za nezaúčtované. Je to čistě
dohledávací informace - zato obousměrná: navázaný doklad se objeví v panelu
**Souvisí** u zápisu i naopak, včetně prokliku na doklad a na jeho zaúčtování.

Jeden zápis může být navázaný na víc dokladů a jeden doklad na víc zápisů. Vazbu
zrušíte tlačítkem **Zrušit vazbu**; zápisu ani dokladu se tím nic nestane. Pokud byl
doklad mezitím smazaný, vazba zůstane vypsaná se štítkem *Doklad neexistuje*, ať ji
máte jak uklidit.

### 52.14.8 Historie zápisu

Každý přepis existujícího zápisu (idempotentní re-post popsaný v
[§ 52.14.2.1](#521421-idempotence-proc-doklad-nejde-zauctovat-dvakrat)) i editace popisu
zanechává v databázi **neměnnou historickou verzi** předchozího stavu hlavičky i řádků
zápisu - jde o auditní mechanismus na úrovni databázového serveru (tzv. systémové
verzování), který běží automaticky na pozadí ke každé změně a nejde ho vypnout ani
obejít z aplikace. Slouží jako důkazní materiál pro §35 zákona o účetnictví (dohledatelnost
oprav).

V rozbaleném detailu zápisu, pod přílohami, je sekce **Historie** - kliknutím na ni se
načte a zobrazí **časová osa všech verzí** zápisu, od nejnovější:

- u každé verze vidíte **číslo verze**, badge **„aktuální"** u té poslední, **datum a čas**
  vzniku verze a (pokud se ho podařilo dohledat v auditním logu) i **kdo** ji vytvořil,
- u verze vzniklé přepisem/editací popisu je pod ní čitelný **rozdíl proti předchozí
  verzi**: která hlavičková pole se změnila (např. popis, datum dokladu) formou
  „původní hodnota → nová hodnota", a jednotlivé řádky MD/Dal označené jako **přidané**
  (zeleně), **odebrané** (červeně) nebo **změněné** (s původní i novou částkou/stranou),
- **nejstarší verze** (vznik zápisu) žádný rozdíl nemá - je to výchozí stav.

Zápis, který od svého vzniku nebyl nikdy přepsán ani nezměnil popis, má v historii jen
jednu verzi a sekce zobrazí „Zápis nebyl od vzniku upraven."

> [!TIP]
> Spárování verze s konkrétním uživatelem/akcí v auditním logu je **orientační**
> (odvozené z časové blízkosti obou záznamů v téže databázové transakci), ne přesná
> vazba cizím klíčem - u historických verzí vzniklých mimo standardní zaúčtování/editaci
> popisu (např. na starším datovém stavu) se „kdo" nemusí dohledat.

### 52.14.9 Storno a oprava zaúčtovaného zápisu

Zákon o účetnictví (§35) vyžaduje, aby se do **uzavřeného** účetního období nezasahovalo
a aby oprava zaúčtovaného dokladu byla vždy dohledatelná. MyÚčto to řeší dvěma
mechanismy podle toho, zda je období, kam zápis patří, ještě **otevřené**:

- **Otevřené období - oprava přepisem.** Když opravíte údaje na zdrojovém dokladu
  (u již zaúčtované vydané faktury i administrátorským force-editem), systém **existující zápis
  k dokladu přepíše na místě** (stejné ID zápisu, nové řádky) - historii této změny
  drží auditní stopa na úrovni databáze (viz [§ 52.14.8](#52148-historie-zapisu)),
  takže i po přepisu je dohledatelné, jak zápis vypadal předtím. Přepis odmítne
  zápis, který je **už stornovaný** - ten se opravuje jen novým zápisem.
- **Otevřené období - přímé smazání chybného zápisu.** V detailu podporovaného
  zápisu je vedle storna dostupné tlačítko **„Smazat“**. Bez protizápisu lze odstranit
  ruční zápis, zápis vydané či přijaté faktury, bankovního pohybu, poslední odpis a
  **Zúčtování DPH** (to se nestornuje, jen maže; při dalším podání přiznání nebo ručním
  spuštění v agendě DPH se založí znovu).
  Faktura se atomicky odúčtuje (u přijaté faktury ve stavu **Zaúčtovaná** se pracovní
  stav vrátí na **Přijatá**, platební stav zůstane zachovaný); bankovní pohyb se vrátí
  mezi nezaúčtované položky, takže jej lze zkontovat znovu. Tato možnost není dostupná
  v období, které se uzavírá, je uzavřené či schválené, ani u již stornovaného zápisu
  nebo jeho protizápisu. Zápis v **části účetnictví uzamčené k datu** (typicky po podání
  přiznání k DPH) smazat jde, ale až po potvrzení velkého varování: smazání změní údaje,
  které už mohly být vykázané finančnímu úřadu, a zásah je na odpovědnost účetního
  (zaškrtávací potvrzení). Přehlasovaný zámek se zapíše do auditního logu. Smazání se zaznamená
  do auditního logu a databázová systémová historie uchová předchozí podobu zápisu.
- **Zaúčtovaný zápis, který přepisem opravovat nechcete (nebo nejde) - storno.**
  Tlačítko **„Stornovat"** v detailu zápisu vytvoří **zrcadlový protizápis** - stejné
  účty a částky (včetně cizoměnové stopy, pokud na originále byla), ale s
  **prohozenými stranami MD/Dal** - zaúčtovaný k aktuálnímu datu (do otevřeného
  období). Originál dostane odkaz na stornující zápis (badge **„Stornováno"** a proklik
  na stornující zápis), stornující zápis referuje originál. Zápis lze stornovat jen
  **jednou** - opakované storno hlásí „Zápis už byl stornován" (i kdyby dva požadavky
  na storno odešly současně, druhý vždy prohraje a nevznikne druhý protizápis).
  Storno je možné **jen u zaúčtovaného** zápisu (koncept se řeší jinak, viz
  [§ 52.14.4](#52144-koncept-vs-zauctovany-zapis)) a jen do otevřeného období.
- U zápisu se zdrojem vydaná/přijatá faktura storno navíc **odemyká zdrojový doklad**
  (zruší příznak „Zaúčtováno" na faktuře), pokud k dokladu neexistuje jiný aktivní
  zaúčtovaný zápis - doklad tak můžete opravit a zaúčtovat znovu.
- **Otevřené období - smazání celé storno dvojice.** Když zápis v účetnictví nikdy
  neměl vzniknout, je i po stornu v deníku dvojice, která se jen vzájemně ruší. V otevřeném
  období ji jde odstranit celou - viz [§ 52.14.9.4](#521494-smazani-cele-storno-dvojice).
- **Přeúčtování z dokladu.** Všechny tři cesty výš (přepis, storno, odmítnutí) má pod
  jedním tlačítkem i doklad sám - viz [§ 52.14.9.2](#521492-preuctovani-z-dokladu-sekce-zauctovani).

#### 52.14.9.1 Automatické storno při smazání nebo interním stornu dokladu

Storno popsané výše spouštíte ručně tlačítkem v deníku. Když ale **smažete** nebo
**interně stornujete** zaúčtovanou vydanou či přijatou fakturu přímo v [Fakturách](14_Faktury.md)
/ [Přijatých fakturách](23_Prijate_faktury.md), stejný mechanismus (protizápis s
prohozenými stranami) se spustí **automaticky**, ve stejné transakci jako
smazání/storno dokladu - deník tak nikdy nezůstane se zápisem k dokladu, který
už neexistuje nebo je zrušený.

Při smazání rodičovské faktury se nejdřív stornují a odpojí také aktivní zápisy
všech navázaných dokladů, které databáze smaže společně s ní (dobropisy a daňové
doklady k přijaté platbě). Selhání storna jediného potomka zastaví celé smazání.

- Protizápis dostane popis **„Storno zápisu při smazání dokladu"** (u smazání)
  nebo **„Storno zápisu při stornu dokladu"** (u interního storna), číslo
  dokladu **„STORNO {původní číslo}"**, a zaúčtuje se **do stejného období jako
  originál** - na rozdíl od ručního storna v deníku (to jde vždy do aktuálního
  otevřeného data).
- Právě proto, že se protizápis snaží zaúčtovat do období originálu: pokud je
  to období **uzavřené**, celá operace (smazání i storno dokladu) skončí chybou
  a **nic se neuloží** - ani doklad, ani zápis se nezmění. Aplikace to nahlásí
  srozumitelně, např. *„Fakturu nelze smazat - má zaúčtovaný zápis, který nelze
  stornovat (Období storna je „closed" - storno nelze zaúčtovat.). Nejdřív
  vyřešte zaúčtování v deníku."* (analogicky pro storno a pro přijaté faktury).
  Řešení je stejné jako jinde v této kapitole - období napřed znovu otevřít
  přes [Uzávěrku](72_Uzaverka.md), nebo doklad neuzavíráte, ale opravíte
  přeúčtováním.
- Při **smazání** se navíc na zdrojovém dokladu zruší vazba na zápis
  (`source_id`), protože samotný doklad za okamžik zmizí - v deníku po něm
  zůstane jen dvojice originál + stornující zápis jako auditní stopa.

> [!WARNING]
> Storno do **uzavřeného** účetního období nejde provést - systém vrátí chybu, že
> období je uzavřené. Opravu položek z minulého (uzavřeného) roku řeší jiný postup přes
> [Uzávěrku](72_Uzaverka.md) (znovuotevření knih do schválení závěrky), ne přímé storno
> v deníku.

> [!TIP]
> **Force-edit zaúčtovaného dokladu v uzavřeném období (admin).** Administrátor smí
> výjimečně upravit i fakturu nebo přijatou fakturu, která je zaúčtovaná a spadá do už
> uzavřeného období (parametr `?force=1` v detailu dokladu - viz
> [Faktury](14_Faktury.md) / [Přijaté faktury](23_Prijate_faktury.md)). Taková úprava
> vždy vyžaduje **explicitní volbu**, co se má stát se zápisem v deníku - bez
> zvolení jedné z nich se úprava vůbec neuloží:
>
> - **Přeúčtovat** - původní zápis se stornuje (protizápis k dnešnímu, otevřenému datu)
>   a doklad se rovnou zaúčtuje znovu podle opravených údajů, takže deník zůstane
>   konzistentní s dokladem.
> - **Jen poznámky** - povolí uložit jen neúčetní pole (interní poznámku apod.).
>   Jakoukoli změnu částky, DPH nebo kurzu (u cizoměnového dokladu i směnného kurzu)
>   doklad v tomto režimu odmítne - rozhodila by už zaúčtovaný zápis, aniž by se
>   promítla do deníku.
>
> V otevřeném období force-edit účetních polí automaticky přepíše existující zápis
> podle opraveného dokladu; samostatná volba režimu se nevyžaduje.

#### 52.14.9.2 Přeúčtování z dokladu (sekce Zaúčtování)

Storno a přepis z předchozích odstavců se dají spustit i z dokladu samotného, aniž
byste se museli přepínat do deníku. Sekce **Zaúčtování** má u každého živého zápisu
vedle odkazu **Otevřít v deníku** tlačítko **Přeúčtovat** (admin/účetní). Najdete ji na:

- detailu [vydané faktury](14_Faktury.md),
- detailu [přijaté faktury](23_Prijate_faktury.md),
- u zaúčtovaného [bankovního pohybu](30_Bankovni_ucty.md) (tam je tlačítko v řádku
  pohybu vedle **Zrušit zaúčtování**),
- v účetním deníku v rozbaleném zápisu kteréhokoli z těchto dokladů, vedle **Stornovat**.

Dialog ukáže **řádky zápisu, který v deníku opravdu je**, a nechá je změnit, smazat
i doplnit. U převzaté skupiny zobrazí všechny její členy a předvyplní jejich
stávající řádky; potvrzení stornuje celou původní skupinu a zapíše nový předpis,
i když skupina obsahuje jen jediný zápis. Zápis musí zůstat vyrovnaný (Σ MD = Σ Dal).
U běžného zápisu rozhoduje o způsobu přeúčtování stav účetního období:

| Stav období | Co se stane |
|---|---|
| otevřené a nezamčené | původní zápis se **přepíše** - staré řádky se smažou a zapíšou se nové; číslo i datum zápisu zůstávají |
| otevřený rok, datum pod [zámkem k datu](#521410-zamek-uctovani-k-datu), oprava jen přesouvá částky mezi účty téže třídy | původní zápis se **přepíše na místě** k původnímu datu, bez storna (podmínky viz níž) |
| uzavřené, nebo datum spadá pod zámek a oprava mění víc než účty | původní zápis se **nemaže**: vznikne **storno** (protizápis) a oprava se zapíše jako nový zápis. Obojí zůstane v deníku kvůli auditu (§ 35 ZoÚ) |
| zápis už někdo stornoval | protizápis se nedělá znovu, jen se zapíše opravený zápis k témuž datu |
| do žádného otevřeného data se zapsat nedá | operace se **odmítne** s vysvětlením (zámek zasahuje i dnešek, nebo pro dnešek není otevřené období) |

Když do původního data zapsat nejde, storno i oprava padnou na nejbližší otevřené
datum - dialog to napíše a **vyžádá si potvrzení**. Datum se nikdy neposune samo.

Dole v dialogu jsou **Poznámky** zápisu, tytéž jako v deníku a u bankovního pohybu.
Přidání i úprava poznámky se ukládá nezávisle na přeúčtování, takže jde vždy, i v
uzavřeném roce, kde je přeúčtování zablokované. Když změníte jen poznámku (kontace
a popis zůstanou), hlavní tlačítko se přepne na **Uložit poznámku** a zápis
nepřeúčtuje. Když přeúčtování vytvoří storno a nový zápis, živé poznámky se
zkopírují na nový zápis; stornovaný si je nechá.

**Přeúčtování v zamčeném datu bez storna.** Zámek k datu se posouvá s podaným
přiznáním k DPH, chrání tedy DPH, ne kontaci nákladu. Dokud rok není v uzávěrce, zápis
v zamčeném datu se přepíše na místě, pokud oprava splní všechny podmínky:

- nemění se žádný účet daní (34x, tedy ani DPH),
- nemění se částka peněz, pohledávek a závazků (účty třídy 2 a skupin 31–33, 35–37);
  přesun mezi nimi, třeba 321 → 325, projde,
- je-li za rok už podané přiznání k dani z příjmů, navíc: všechny měněné účty patří
  do stejné účtové třídy (typicky náklad 511 → 518.100), takže se nemění výsledek,
  a nemění se daňová uznatelnost (přesun na nedaňovou analytiku .990 jde stornem).

Porovnává se čistý pohyb (MD − Dal) na každém účtu, ne součty stran. Když se tedy
sleva zaúčtovaná zvlášť na straně Dal 518 rozpustí do ceny zboží na 501, součet stran
zápisu se zmenší, ale DPH, závazek ani výsledek se nemění a zápis se přepíše na místě.

Dokud přiznání k dani z příjmů podané není, projde tedy i přesun mezi třídami, například
dodatečně doplněné časové rozlišení 518 → 381 při opravě přijaté faktury.

Dialog to rozhodne už při úpravě řádků (ptá se serveru stejnou kontrolou, jakou pak
projde uložení). Buď napíše, že se zápis přepíše na místě k původnímu datu, nebo řekne,
proč to nejde (mění se DPH, saldokontní účet, po podání DPPO výsledek nebo uznatelnost),
a teprve pak si vyžádá potvrzení posunu data.
Kontrolu dělá server ještě jednou těsně před zápisem. Když oprava podmínky nesplní,
postupuje se stornem a novým zápisem jako v tabulce výše. U přijaté faktury se nový
nákladový účet zapíše i na položku dokladu, pokud se celý náklad přesunul z jednoho
účtu na jiný. Jinak by ho další úprava dokladu vrátila zpět.

Přeúčtování se týká jen **kontace**. DPH se jím nemění: evidence DPH se počítá z řádků
dokladu, takže daňový režim se opravuje editací dokladu, ne kontace.

U **bankovního pohybu** platí navíc totéž, co u ručního zaúčtování z fronty: pohyb na
účtu 221 musí sedět na částku z výpisu a bankovní noha se sama doplní na analytiku
vlastního účtu výpisu. Přeúčtování se tím liší od **Zrušit zaúčtování** - to pohyb
vrátí nezaúčtovaný do fronty a v zamčeném ani uzavřeném období ho provést nelze,
kdežto přeúčtovat pohyb jde i tam (storno + nový zápis).

#### 52.14.9.3 Podle čeho se účtovalo

Sekce **Zaúčtování** kromě samotné kontace říká i to, **z jaké šablony vznikla** - a
dovolí ji rovnou opravit. Vrstvy popisuje
[§ 50.10.2.1](50_Pruvodce_ucetniho.md#501021-ctyri-vrstvy-ktere-urcuji-kontaci); tady je,
co se u kterého dokladu ukáže:

| Doklad | Co určilo kontaci | Kam vede tlačítko |
|---|---|---|
| vydaná faktura | **předkontace** podle klíče výnosu na hlavičce (výchozí `invoice.services.issued`) | Nástroje → Účetní nastavení → Předkontace, rovnou na ten jeden klíč |
| přijatá faktura | **nákladové pravidlo** vybralo druh nákladu, **předkontace** k tomu druhu určila účty | pravidlo: Šablony účtování → Pravidla nákladů (dialog přímo na detailu); předkontace: Nástroje → Účetní nastavení → Předkontace |
| bankovní pohyb | **pravidlo účtování**, vestavěné rozpoznání, naučená kontace, nebo u spárované platby předkontace `payment.*` | pravidlo: Nástroje → Šablony účtování → Pravidla účtování; předkontace: Účetní nastavení → Předkontace |

U předkontace je vidět i to, jestli platí **firemní nastavení**, nebo systémový
výchozí stav, a jaké účty MD/Dal drží.

Systém si nikde nepamatuje „tenhle zápis vyrobila předkontace X" - odvozuje ji z
**dnešního** nastavení dokladu. Proto k ní přidává i kontrolu, jestli se její účty
v zápisu opravdu objevily:

- **„v zápisu se nepoužila"** - kontace vznikla jinak (ruční zápis, jiné nastavení
  v době účtování). Oprava předkontace se pak projeví až u příštího zaúčtování.
- **„Ručně přeúčtováno {datum} ({uživatel})"** - za účty stojí účetní, ne šablona.
- **„Zdroj kontace nelze určit"** - ruční zápis nebo doklad z doby před evidencí
  původu. Nepředstírá se šablona, která se nepoužila.

#### 52.14.9.4 Smazání celé storno dvojice

Storno je správná cesta, jak zrušit účinek zápisu - v deníku po něm ale navždy zůstane
dvojice, která se vzájemně ruší. Když šlo o zápis, který v účetnictví **nikdy neměl
vzniknout** (duplicitní bankovní pohyb po přepojení konektoru, omylem zaúčtovaný doklad),
je ta dvojice jen šum: nic nedokládá a v deníku i v opisu účtu jen překáží.

V rozbaleném detailu stornovaného zápisu je proto v otevřeném období tlačítko
**Smazat zápis i storno**. Odstraní obě strany najednou - původní zápis i jeho protizápis.

**Na číslech se tím nic nemění.** Obě strany dvojice se ruší, takže žádný zůstatek,
obratová předvaha ani výkaz nevypadají po smazání jinak. Mizí jen dva řádky deníku, které
se vzájemně vynulovaly.

Smazání se odmítne, když:

- je některá strana dvojice v období, které **není otevřené** (uzavírá se, je uzavřené
  nebo schválené),
- na dvojici **navazuje další storno** (storno storna) - řetěz se rozplétá odzadu, od
  posledního protizápisu,
- se zdroj zápisu ruší **vlastním workflow** (mzdy, odpisy, reklasifikace).

Spadá-li datum některé strany do **uzamčené části účetnictví**
(viz [§ 52.14.10](#521410-zamek-uctovani-k-datu)), smazání se provede až po potvrzení varování
o zásahu do uzamčeného období, stejně jako u jednoho zápisu. Stejně jde smazat i dvojici
stornovaného **Zúčtování DPH**.

Bankovní pohyb, ze kterého zápis vznikl, se smazáním vrátí mezi nezaúčtované položky,
takže jej lze zkontovat znovu; u faktury se zruší příznak „Zaúčtováno". Smazání se
zaznamená do auditního logu včetně zrušených řádků obou zápisů.

> [!TIP]
> Tohle není cesta, jak z účetnictví odklidit nepohodlný doklad. Je to úklid po chybě,
> která se stala a hned se napravila stornem, a jde jen tam, kde se ještě nic nevykázalo.
> Jakmile je období uzavřené nebo datum uzamčené, dvojice v deníku zůstává.

### 52.14.10 Zámek účtování k datu

Kromě uzavření **celého** účetního období (viz [Uzávěrka](72_Uzaverka.md)) existuje i
jemnější, měkký **zámek účtování k datu** platný napříč všemi obdobími firmy -
zámek. Řídí, do kterého data (včetně) už nejde nově zaúčtovat, přeúčtovat ani
stornovat žádný doklad, i když samotné účetní období je pořád formálně **otevřené**.

**K čemu slouží.** Jakmile jednou podáte přiznání k DPH za leden, nechcete, aby šlo o pár
měsíců později omylem zaúčtovat další doklad zpátky do ledna a rozhodit tak už podané
přiznání. Zámek k datu je jemnější než uzavření celého roku - běžný provoz v aktuálních
měsících funguje beze změny, chráněná je jen minulost před zamčeným datem.

**Posun po podání.** Vygenerování ani stažení přiznání k DPH nebo kontrolního hlášení
(viz [Výkazy DPH](41_Vykazy_DPH.md)) samo zámek neposouvá. Backend jej posune až při
explicitním označení validního snapshotu za skončené období jako **odeslaného**, a to
jen **dopředu** (nikdy ho automaticky nezmenší). V Archivu podání tuto akci
spustíte tlačítkem **Označit jako podané**; zámek lze alternativně nastavit ručně
administrátorem.

**Co uvidíte.** Pokus o zaúčtování, přeúčtování nebo storno dokladu s datem v zamčeném
rozsahu skončí chybou, že datum je zamčené. Storno zaúčtovaného zápisu, jehož datum už
je zamčené, samo o sobě projde, ale protizápis se automaticky zaúčtuje k dnešnímu
(otevřenému) datu, ne k datu originálu.

**Ruční nastavení a posun.** Zámek může nastavit, posunout dopředu i vrátit zpátky jen
**administrátor** - na stránce [**Účetnictví → Měsíční kontrola**](62_Mesicni_kontrola.md)
tlačítkem „Uzamknout k datu", vždy s
povinným písemným zdůvodněním (min. 5 znaků); změna se zaznamená do auditní stopy
(hodnota před/po + důvod). Stejná akce je dostupná i přímo přes administrátorské API
(`PUT /api/accounting/period-lock`).

### 52.14.11 Kontrola integrity deníku (noční job)

Kromě zámků a auditní stopy má aplikace i **pasivní diagnostiku** - jednou denně
(**02:30**) proběhne CLI cron `cron-journal-integrity-check`, který u každé
firmy v režimu **podvojné účetnictví** (daňová evidence se přeskakuje - deník
tam neexistuje) porovná doklady s deníkem a hledá pět typů nesrovnalostí:

| Nález | Co znamená |
|---|---|
| **Sirotčí zápisy** | Aktivní zaúčtovaný zápis, jehož zdrojový doklad (faktura/přijatá faktura) už v systému neexistuje. |
| **Nevyvážené (MD≠D)** | Součet MD a součet D na řádcích zápisu se neshodují (nad toleranci půl haléře). |
| **Zaúčtováno bez zápisu** | Doklad má nastavený příznak „Zaúčtováno", ale žádný aktivní zápis k němu v deníku neexistuje. |
| **Zápis bez zaúčtování** | Opačně - aktivní zápis existuje, ale doklad příznak „Zaúčtováno" nemá. |
| **Doklad ≠ zápis částkou** | Celková částka běžného dokladu (přepočtená na CZK) nesedí na žádný řádek zápisu v rámci tolerance. Daňový doklad k přijaté platbě se tímto pravidlem nekontroluje, protože účtuje jen DPH 324/343. |

Job je **čistě diagnostický - nic sám neopravuje**, jen zapíše počty a ukázku
nálezů (přepis při každém běhu). Výsledek vidíte
na dashboardu ([Zkontroluj integritu deníku](10_Prehled.md#101062-zkontroluj-integritu-deniku)) -
karta **„Zkontroluj integritu deníku"** se zobrazí jen když job něco našel, se
závažností vždy **vysoká**. Aplikace nemá samostatnou stránku s výpisem
jednotlivých nálezů. Karta i každý štítek rozpadu vedou zpátky na tento
seznam zápisů. U jediného nálezu otevře karta přímo dotčený zápis, u více nálezů
nesoulad částky doklad ↔ zápis vyfiltruje filtr **Nesrovnalosti doklad ↔ deník**;
ostatní typy dohledáte ručně podle typu.

Ruční spuštění (např. hned po podezřelé opravě, bez čekání na noční běh):

```
php api/bin/cron-journal-integrity-check.php                  # spustí a uloží výsledek
php api/bin/cron-journal-integrity-check.php --dry-run         # jen vypíše, nic neuloží
php api/bin/cron-journal-integrity-check.php --supplier=12     # jen jedna firma
```

Při ručním zaúčtování přes API lze zadat jiné `entry_date` než DUZP. Pokud datum
leží v jiném kalendářním roce, zápis se vytvoří, ale odpověď vrátí varování
`entry_date_outside_document_year`; takový přesun je potřeba účetně ověřit.

### 52.14.12 Omezení a tipy

#### 52.14.12.1 Kdo smí co

| Akce | Jen pro čtení | Účetní / administrátor |
|---|:---:|:---:|
| Prohlížet seznam, filtrovat, rozbalovat detail | ✓ | ✓ |
| Stahovat přílohy zápisu | ✓ | ✓ |
| Založit ruční zápis, převod 261 | - | ✓ |
| Upravit popis zápisu | - | ✓ |
| Nahrát / smazat / popsat přílohu | - | ✓ |
| Stornovat zaúčtovaný zápis | - | ✓ |
| Znovuotevřít uzavřené období (pro opravu) | - | jen administrátor |
| Nastavit/posunout zámek účtování k datu | - | jen administrátor |

- Deník je **jen pro podvojné účetnictví** - firmy na daňové evidenci modul v menu
  vůbec nevidí.
- Ruční zápis (i storno, i editace popisu, i přílohy) vyžaduje **právo zápisu** (role
  účetní/administrátor); role jen pro čtení vidí vše, ale nic needituje.
- **Σ MD = Σ Dal** je tvrdá podmínka na backendu (počítaná v haléřích, ne přes
  desetinná čísla) - frontendová kontrola je jen pomůcka, server nevyrovnaný zápis
  vždy odmítne.
- Zaúčtovat (i stornovat, i přepsat idempotentním re-postem) lze jen do **otevřeného**
  účetního období - nejdřív si ověřte v [Uzávěrce](72_Uzaverka.md), že období pro dané
  datum existuje a je otevřené.
- Popis zápisu lze inline upravit jen u **ručních** zápisů a zápisů uzavření/otevření
  knih - u ostatních (faktura, banka, pokladna, majetek) se popis mění na zdrojovém
  dokladu.
- Přílohy zápisu mají per-soubor limit **20 MiB** a per-zápis limit **100 MiB**;
  duplicitní obsah se k témuž zápisu nedá nahrát dvakrát.
- Ruční zápis přes formulář vždy počítá jen v **CZK** - cizí měnu/kurz na řádku má
  jen automaticky vzniklý zápis z cizoměnové faktury.
- Filtr **Zdroj** i postranní detail ze zápisu pokrývají banku, pokladnu, faktury,
  majetek, odpisy a vypořádání. Plný detail se otevírá až odkazem ze zdrojového panelu.
- Export deníku (PDF/XLSX) respektuje aktuálně nastavené filtry a je omezený na
  max. **5 000 zápisů** v jednom exportu.
- Sekce **Historie** v detailu zápisu ukazuje časovou osu verzí s rozdílem oproti
  předchozí verzi - spárování s konkrétním uživatelem je orientační (časová blízkost
  v auditním logu), ne přesná vazba.
- Kromě otevřenosti období hlídá zaúčtování/přeúčtování/storno i **zámek k datu**
  ([§ 52.14.10](#521410-zamek-uctovani-k-datu)). Ručně jej nastaví administrátor; po
  doloženém podání jej posune i označení snapshotu DPH nebo KH tlačítkem **Označit jako
  podané** v `Daně → EPO podání a archív`.

> [!TIP]
> Hledáte zápis ke konkrétní faktuře? Otevřete fakturu, klikněte na **„Zobrazit v deníku"**
> - deník se rovnou otevře s filtrem na daný doklad a zápis bude předrozbalený.

### 52.14.13 Doúčtování nezaúčtovaných dokladů

**Účetnictví → Doúčtovat doklady.**

Kdy to potřebujete: po [importu historie](21_Importy.md) z jiného systému, po přechodu
z daňové evidence na podvojné účetnictví, nebo kdykoli, kdy v seznamech leží doklady bez
zaúčtování. Automatika účtování je háček na vznik dokladu a takové doklady jí neprojdou
(viz poznámka na začátku kapitoly).

Obrazovka ukazuje, **kolik dokladů čeká** - zvlášť vydané a přijaté faktury, pokladní
doklady, bankovní pohyby a zápočty. Účtuje vydané a přijaté faktury; pokladna, banka
a zápočty mají vlastní cesty.

| Akce | Co udělá |
|---|---|
| **Doúčtovat** | Projde všechny nezaúčtované faktury a zaúčtuje je. Běží na pozadí, stránku můžete zavřít. |
| **Zkusit nanečisto** | Projde totéž a řekne, co by se stalo, ale **nic nezapíše**. |
| **Zastavit** | Doběhne rozepsaný doklad a skončí. Doklady zaúčtované do té chvíle v deníku zůstávají. |

Každý doklad se účtuje **samostatně**, takže jeden vadný dávku nezastaví - skončí
v protokolu jako přeskočený nebo chybný s důvodem. Typický důvod přeskočení je uzavřené
období nebo doklad, který se zaúčtovat nedá (nulová částka, zálohová faktura). Na konci
běhu se kontroluje **podvojnost celého deníku** a nevyrovnaný stav se hlásí zvlášť.

Účtuje se týmž kódem jako v průvodci aktivací účetnictví, takže výsledek je stejný,
jako by doklady prošly aktivací. Oproti hromadnému zaúčtování z výběru v seznamu faktur
tu není strop 500 dokladů na dávku.

#### 52.14.13.1 Dogenerování popisů u převzatých zápisů

Deník převzatý z POHODY nebo Money S3 nese v popisu jen jedno pole původní agendy, takže
desítky zápisů za sebou mívají navlas stejný text (typicky „Fakturujeme Vám za …"). Převod
popisy dopočítá sám hned po navázání dokladů na zápisy - v protokolu převodu to uvidíte jako
**„U N převedených zápisů se popis doplnil o číslo dokladu a protistranu."**

Doplnit je jde i kdykoli později, příkazem na serveru:

```bash
php api/bin/rebuild-journal-descriptions.php                    # nanečisto, jen vypíše
php api/bin/rebuild-journal-descriptions.php --apply            # skutečně přepíše
php api/bin/rebuild-journal-descriptions.php --supplier=2 --apply
php api/bin/rebuild-journal-descriptions.php --source-type=bank --limit=200
```

Bez `--apply` skript **nic nezapisuje** - vypíše, kolika zápisů se změna týká, po typech
zdroje, a ukázku „před → po". Přepínač `--samples=N` řídí počet ukázek.

Co se nikdy nepřepíše:

- **ruční zápisy** a zápisy uzavření/otevření knih - jejich popis psala účetní,
- zápisy **bez vazby na doklad** (storna, zápisy bez `source_id`) - není z čeho skládat,
- zápisy **uzávěrky, mezd, majetku a zápočtů** - jejich popis vychází z údajů, které
  v deníku nejsou, a už číslo i obsah nese,
- popis, který **uživatel sám změnil** (auditovaná inline editace, § 35).

Mění se **jen text popisu**. Částky, účty, data, období ani čísla dokladů zůstávají, takže
se sestavy ani výkazy nezmění. Opakované spuštění už nic nepřepíše.

### 52.14.14 Ostatní pohledávky a závazky

Agenda **Ostatní pohledávky a závazky** slouží pro peněžní nároky a dluhy, které
nepatří do vydaných ani přijatých faktur ani do mzdového a daňového modulu:
nájemné podle smlouvy, vratná kauce, půjčka a její splátky, pojistné, poplatek
nebo nárok na náhradu škody. V podvojném účetnictví ji najdete v menu
**Účetnictví → Ostatní pohledávky a závazky**, v daňové evidenci v menu
**Daňová evidence → Ostatní pohledávky a závazky**. Zdanitelné plnění se sem
nezadává; patří do faktur, aby jeho řádky vstoupily do knihy DPH a výkazů.

#### 52.14.14.1 Přehled

Seznam ukazuje ruční položky a pod nimi položky z jiných modulů. U ruční položky
vidíte název se směrem a druhem, protistranu, splatnost, zdroj s počtem
připojených dokumentů, částku, **zbývá uhradit** a stav. Z řádku otevřete detail;
rozpracovaný koncept lze také upravit nebo smazat.

Filtry:

- **Směr**: vše, pohledávky, nebo závazky.
- **Stav**: výchozí **Otevřené** ukazuje koncepty, potvrzené a zaúčtované
  položky, u kterých ještě zbývá něco uhradit; **Vše** ukáže i uhrazené,
  stornované a zrušené.
- **Zdroj**: ručně zadané, mzdy, nebo daně.
- **Hledat**: název, protistrana, číslo dokladu a variabilní symbol.
- **Splatnost od / do**: bez zadaného rozmezí se ukáží položky se splatností
  rok zpět až rok dopředu.

Tlačítko **Nová ostatní položka** založí koncept se směrem podle aktuálního filtru.

#### 52.14.14.2 Založení položky

V konceptu vyplníte:

| Pole | Význam |
|---|---|
| Směr | pohledávka (vám někdo dluží) nebo závazek (dlužíte vy) |
| Druh | nájemné, kauce, půjčka nebo splátka, pojistné, poplatek, náhrada, jiné |
| Položka | stručný název, například „Nájemné kancelář říjen" |
| Protistrana | výběr z adresáře (u závazku dodavatelé, u pohledávky odběratelé), nebo ruční text |
| Datum vzniku | den, kdy nárok nebo dluh vznikl; výchozí dnešek |
| Datum zaúčtování | jen v podvojném účetnictví; výchozí datum vzniku |
| Splatnost | kdy se má platit; řídí přehled, filtr a výhled cash-flow |
| Částka | kladná částka v Kč; jiná měna zatím není podporovaná |
| Variabilní symbol | jen číslice; usnadní dohledání platby |
| Poznámka | volný text k položce |

Názvy druhů se mění podle směru, aby například kauce k vrácení nezněla jako
přijatá kauce. Druh je jen popisný: neurčuje účty ani daňovou povahu. Změnou směru
se druh a zadané účty vymažou. Dokumenty připojíte až po uložení položky v jejím
detailu. Upravovat lze jen koncept.

#### 52.14.14.3 Účtování v podvojném účetnictví

V části **Účtování** zvolíte:

- **Účet pohledávky nebo závazku**: u pohledávky se nabízejí aktivní účty,
  u závazku pasivní. Nevyberete-li žádný, při zaúčtování se použije 315
  (pohledávka) nebo 325 (závazek).
- **Protiúčet**: skutečný účetní případ. Účet 5xx znamená náklad, 6xx výnos;
  rozvahový protiúčet (například kauce nebo jistina půjčky) zisk nemění.

Tlačítkem **Rozdělit kontaci** rozdělíte protiúčet až na 50 řádků, například
nájemné na nájem a zálohy na služby. Součet řádků musí přesně odpovídat částce
položky. Účet pohledávky nebo závazku zůstává jeden v celé částce kvůli saldu
a párování úhrad; protiúčet nesmí být shodný s ním ani začínat 315 nebo 325.

**Zaúčtovat** přidělí položce číslo z řady OP (pohledávky) nebo OZ (závazky)
podle roku data vzniku a vytvoří zápis k datu zaúčtování:

| Směr | Zápis |
|---|---|
| Pohledávka | MD účet pohledávky (315) / D protiúčet |
| Závazek | MD protiúčet / D účet závazku (325) |

Zaúčtování vyžaduje oprávnění zaúčtovat v deníku. Zápis nejde stornovat přímo
v deníku; storno i opravy dělejte v detailu položky.

#### 52.14.14.4 Daňová evidence

V daňové evidenci se položka **potvrdí** (dostane číslo, stav Potvrzeno) a žádný
účetní zápis nevzniká. Potvrzení samo nezakládá daňový příjem ani výdaj. Daňovou
povahu skutečné bankovní nebo pokladní platby zařadíte v peněžním deníku; detail
na to upozorní a odkáže do něj. Potvrzené položky se ukážou v přehledu pohledávek
a závazků daňové evidence rozdělené podle stáří.

#### 52.14.14.5 Úhrady

K potvrzené nebo zaúčtované položce přiřadíte existující platbu tlačítkem
**Hledat volnou platbu** (hledá podle variabilního symbolu, protistrany a popisu):

- **bankovní pohyb** ve správném směru (příchozí u pohledávky, odchozí u závazku),
  v Kč, zatím nespárovaný s fakturou, mzdou ani daňovou zálohou,
- **pokladní doklad** zaúčtovaný s účelem Ostatní, ve správném směru.

V podvojném účetnictví musí být platba nejdřív zaúčtovaná proti stejnému účtu
pohledávky nebo závazku (u pohledávky na straně D, u závazku MD); jinak ji
aplikace odmítne s výzvou platbu nejdřív takto zaúčtovat. Přiřazení samo nic
znovu nezaúčtuje.

Nabídnutá částka je menší z volné části platby a zbývajícího dluhu. Jedna
položka může mít více dílčích úhrad a jedna platba se může rozdělit mezi více
položek. Jakmile zbývá uhradit nula, položka z filtru Otevřené zmizí.
Přiřazení zrušíte tlačítkem **Odpojit úhradu**. Stornovaný bankovní zápis
přiřazení označí jako stornované; pokladní doklad přiřazený k položce stornovat
nejde, nejdřív ho odpojte.

#### 52.14.14.6 Storno a přeúčtování

**Stornovat** (v daňové evidenci **Zrušit**) jde u zaúčtované nebo potvrzené
položky bez přiřazených úhrad; úhrady nejdřív odpojte. Zadáte důvod a v podvojném
účetnictví datum storna. Vznikne protizápis „Storno číslo: důvod" a položka
přejde do stavu Stornováno. Storno zároveň pozastaví opakování položky.

**Přeúčtovat** (jen podvojné účetnictví, zaúčtovaná položka bez úhrad) opraví
chybné účty. Dialog ukáže dosavadní kontaci; zadáte nový účet pohledávky nebo
závazku, protiúčet nebo rozdělenou kontaci, datum a důvod. Aspoň jeden účet se
musí změnit. Původní zápis se stornuje a vznikne nový se **stejným číslem
dokladu** k datu přeúčtování.

Koncept se maže tlačítkem **Smazat**. Koncept vytvořený opakováním se místo
smazání zruší, aby v rozvrhu zůstalo vidět, že termín byl vyřešen. Zdrojový
koncept opakování smazat nejde.

#### 52.14.14.7 Opakování a splátkový kalendář

V detailu v části **Opakování položky** nastavíte měsíční, čtvrtletní nebo roční
opakování a případně datum konce. Každá kopie dostane datum vzniku posunuté
o dané období (u kratšího měsíce na jeho poslední den) a stejný odstup splatnosti
jako zdrojová položka. Přebírá směr, druh, název, protistranu, částku,
variabilní symbol, účty včetně rozdělené kontace, poznámku i připojené dokumenty.

Denní úloha připravuje koncepty až 90 dní dopředu, aby se ukázaly v predikci.
Ručně je vytvoříte polem **Vytvořit do** a tlačítkem **Vytvořit položky** (nejvýš 120 najednou). Vzniklé koncepty se nikdy nezaúčtují samy. Pod rozvrhem
se vypisují vytvořené položky s datem a stavem. Opakování lze **Pozastavit**
a **Obnovit**; po stornu zdrojové položky se pozastaví natrvalo a už vytvořené
koncepty zůstávají samostatnými položkami k posouzení.

**Splátkový kalendář** rozloží jednu pohledávku nebo závazek na 2 až 120 splátek
s rostoucími daty (ne dřívějšími než datum vzniku). Součet splátek musí odpovídat
částce položky. Kalendář nevytváří žádné účetní zápisy, jen rozloží očekávané
platby v cash-flow; úhrady se započítávají od nejstarší splátky. Po přiřazení
první úhrady už kalendář měnit nejde. Koncept se splátkovým kalendářem lze dál
upravovat, dokud částka odpovídá součtu splátek a datum vzniku není pozdější než
první splátka; před změnou částky kalendář zrušte tlačítkem **Zrušit kalendář**.

#### 52.14.14.8 Dokumenty a vazba na deník

Panel **Dokumenty** v detailu připojí smlouvy, skeny a další podklady ze skladu
dokumentů. Nově nahraný sken se uloží do složky **Ostatní pohledávky a závazky /
rok / měsíc** podle data vzniku. Originál zůstává ve skladu a stejná smlouva
může být podkladem více položek či období.

U zaúčtované položky ukazuje část **Účtování** účty a rozdělenou kontaci
a tlačítkem **Otevřít účetní zápis** přejde na zápis v deníku, včetně jeho
vlastních poznámek a příloh. Obráceně v deníku otevřete náhled položky (směr,
druh, protistrana, data, částka, uhrazeno, zbývá, variabilní symbol, účty
a poznámka) i s jejími dokumenty a přejdete na detail.

#### 52.14.14.9 Mzdy, daně a predikce

Pod ručními položkami se ukazují **položky z jiných modulů**, jen ke čtení
a s odkazem do jejich agendy:

- **Daňové zálohy**: zálohy na daň z příjmů a na sociální a zdravotní pojištění,
- **Mzdy**: mzdové závazky a vratky za období (bez údajů o zaměstnancích),
- **Odhady** označené štítkem Odhad: odhad mzdové platby podle posledních mezd,
  odhad DPH k odvodu nebo vratky a odhad doplatku DPPO. Ukazují se na 90 dní
  dopředu a zmizí, jakmile existuje skutečný doklad (mzdy za období, zaúčtované
  zúčtování DPH).

Tyto řádky vidí jen uživatelé s přístupem k přehledům daní, respektive k mzdovým
platbám. Upravují se ve svém zdrojovém modulu.

Otevřené ostatní položky vstupují do **výhledu cash-flow** podle splatnosti,
u splátkového kalendáře podle termínů splátek. Karta **Ostatní položky ve
výsledku hospodaření** na přehledech ukazuje výnosy, náklady a dopad na zisk
podle protiúčtů třídy 5 a 6, zvlášť zaúčtované a koncepty; rozvahové protiúčty
se do výsledku nepočítají. Odhad není potvrzený dluh. Zaúčtované položky na
účtu pohledávky nebo závazku zkontrolujete v [Saldokontu](60_Saldokonto.md)
i zpětně k rozvahovému dni.

#### 52.14.14.10 Oprávnění

Agendu vidí uživatelé s oprávněním **Ostatní pohledávky a závazky** (čtení);
zakládání, úpravy a úhrady vyžadují zápis. Zaúčtování a storno v podvojném
účetnictví a každé přeúčtování potřebují navíc oprávnění zaúčtovat v deníku.
Přiřazení bankovní platby vyžaduje přístup k bance a párování, přiřazení
pokladního dokladu přístup k pokladně. Připojené dokumenty vidí uživatel
s přístupem k dokumentům.

### 52.14.15 Interní doklady

Agenda **Interní doklady** (menu **Účetnictví → Interní doklady**, hned pod
ostatními pohledávkami a závazky) je evidence interních dokladů s vlastní číselnou
řadou: zápočty pohledávek a závazků, přeúčtování, odpisy pohledávek, kurzové
rozdíly, opravy a dohadné položky. Interní doklad je hlavička nad ručním zápisem
deníku. Nese číslo, data, popis, partnera, přílohy a poznámku, zápis v deníku nese
kontaci.

#### 52.14.15.1 Krok za krokem: rychlé zadání

1. Otevřete `Účetnictví → Interní doklady` a klikněte na **Nový interní doklad**.
   Stejný formulář otevře i položka **Interní doklad** v rychlém přidání (tlačítko **+**).
2. Vyberte šablonu: **Zápočet pohledávky a závazku** (MD 321 / D 311),
   **Přeúčtování**, **Odpis pohledávky** (MD 546 / D 311), **Kurzová ztráta**
   (MD 563 / D 311), **Kurzový zisk** (MD 311 / D 663) nebo **Dohadná položka**
   (MD 518 / D 389). Šablona předvyplní druh, popis a účty. Účty můžete přepsat,
   například na analytiku.
3. Vyplňte **Datum dokladu**, případně **Datum účetního případu** (výchozí je datum
   dokladu) a partnera.
4. Doplňte částku do prvního řádku. Řádek bez částky se dopočítá, takže u dvouřádkového
   případu píšete částku jen jednou. Tlačítko **Dorovnat** přidá řádek, který rozdíl
   stran vyrovná.
5. U zápočtu v části **Navázané doklady** vyhledejte vydanou a přijatou fakturu partnera.
6. Klikněte na **Uložit a zaúčtovat**. Nevyrovnaný nebo rozpracovaný doklad uložte
   tlačítkem **Uložit koncept**.

Formulář jde ovládat klávesnicí: Enter v částce posledního řádku přidá řádek,
Alt+N přidá řádek, Ctrl+S uloží koncept a Ctrl+Enter doklad zaúčtuje.

**Jak poznáte, že je hotovo:** Doklad je ve stavu **Zaúčtováno**, má číslo z řady ID
a v detailu odkaz na zápis deníku.

#### 52.14.15.2 Zaúčtování a číselná řada

Zaúčtování vytvoří ruční zápis deníku stejnou cestou jako formulář **Ruční zápis**:
platí kontrola MD = Dal, účty z účtové osnovy, otevřené účetní období a zámek
účtování k datu. Zápis je běžný ruční zápis, takže ho hlavní kniha, výkazy,
saldokonto i kontroly berou jako každý jiný. Číslo zápisu je číslo interního
dokladu, popis zápisu je popis dokladu.

Číslo se vydává až při zaúčtování z řady **Interní doklady a ruční zápisy (ID)**,
kterou sdílí i ruční zápisy s automatickým číslováním. Prefix, tvar čísla i další
číslo nastavíte v `Nástroje → Uzávěrka` v části **Číselné řady**. Koncept číslo nemá.

Navázané doklady se při zaúčtování přenesou do vazeb zápisu
([§ 52.14.7.4](#521474-vazba-na-doklad)). U zápočtu tak saldokonto obě faktury vyrovná
podle zápisu.

DPH řádky interní doklad nemá, stejně jako ruční zápis. Zdanitelné plnění patří do faktur.

#### 52.14.15.3 Seznam, filtry a export

Seznam ukazuje číslo, datum dokladu, popis s druhem, partnera, částku (součet strany MD)
a stav. Filtrovat jde podle stavu, druhu, partnera, období (datum dokladu od a do)
a textu (číslo, popis, partner, poznámka). Sloupce se řadí kliknutím na záhlaví.
Tlačítko **Export CSV** stáhne doklady podle aktuálních filtrů.

#### 52.14.15.4 Storno, smazání a vazba na deník

- **Koncept** jde upravit i smazat.
- **Zaúčtovaný doklad** stornujete v jeho detailu tlačítkem **Stornovat**. Vznikne
  protizápis k zápisu dokladu a doklad přejde do stavu **Stornováno**. Do zamčeného nebo
  uzavřeného období storno nepůjde, zvolte datum v otevřeném období.
- Zápis interního dokladu nejde stornovat přímo v deníku, deník odkáže na detail dokladu.
  Smažete-li zápis v deníku, doklad se vrátí do konceptu a při novém zaúčtování dostane
  své původní číslo.
- V deníku má zápis interního dokladu u čísla odkaz **Interní doklad**, rozbalený zápis
  ukazuje odkaz na doklad.

Interní doklad jde vytvořit i nad existujícím ručním zápisem: v rozbaleném zápisu
deníku klikněte na **Vytvořit interní doklad**. Zápis se nezmění, jen dostane číslo
z řady ID, pokud žádné nemá (to jde jen u zápisu v otevřeném období). Zápis patřící
k zaúčtování faktury (převzatý předpis) interní doklad dostat nemůže. Zápis takového
dokladu nejde v deníku smazat, opravuje se stornem dokladu.

#### 52.14.15.5 Přílohy a oprávnění

Přílohy (dohodu o zápočtu, podklad k odpisu) připojíte v detailu dokladu v části
**Přílohy**. Ukládají se do Dokumentů ve složce *Interní doklady/rok/měsíc*.

Seznam a detail vidí uživatel s přístupem k účetnictví. Koncept zakládá a upravuje,
kdo smí zapisovat ruční zápisy. Zaúčtování, storno a vytvoření dokladu nad ručním
zápisem vyžadují oprávnění zaúčtovat v deníku.

#### 52.14.15.6 Návrh od AI asistenta

Interní doklad vám může připravit `Účetnictví → AI asistent`. Popište operaci vlastními
slovy, například „přeúčtuj 5 000 z 518 na 501". Asistent vrátí náhled dokladu s řádky MD
a D a součty, server ho zkontroluje stejnými pravidly jako zaúčtování ručního dokladu.
Po potvrzení vznikne koncept, nebo rovnou zaúčtovaný doklad, stejnou cestou jako z formuláře
**Nový interní doklad**. Podrobnosti v
[AI extrakci, § 25.3](25_AI_extrakce.md#253-krok-za-krokem-nastavit-poskytovatele-ai).

## 52.15 Související kapitoly

- [Průvodce účetního](50_Pruvodce_ucetniho.md) - jak se zápisy tvoří a kdy co dělat
- [Automat](53_Automat.md) a [K doúčtování](54_Rucni_fronta_doctovani.md) - automatické zápisy a nezaúčtované případy
- [Hlavní kniha](55_Hlavni_kniha.md) a [Obratová předvaha](56_Obratova_predvaha.md) - sestavy ze zápisů deníku
- [Měsíční kontrola](62_Mesicni_kontrola.md) - zámek účtování k datu
- [Uzávěrka](72_Uzaverka.md) - období a znovuotevření knih
- [Účtový rozvrh](66_Ucetni_osnova.md) a [Předkontace](73_Ucetni_nastroje.md#735-krok-za-krokem-predkontace)
- [Banka](29_Banka.md), [Pokladna](32_Pokladna.md), [Faktury](14_Faktury.md), [Přijaté faktury](23_Prijate_faktury.md) - zdroje zápisů
